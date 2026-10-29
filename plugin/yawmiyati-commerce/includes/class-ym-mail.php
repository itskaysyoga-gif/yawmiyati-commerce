<?php
/**
 * Customer permissions and email (29 Oct brief §7). Spec: docs/EMAIL.md.
 *
 * WooCommerce stays the source of truth for orders. A small permission layer shared by the five stores lives in the
 * shared database (YM_SHARED_DB, as the consent ledger):
 *   ym_contacts    one row per email address: stable id, status (pending | subscribed | unsubscribed | bounced |
 *                  complained), consent time, wording version and source, confirmation time, country, language
 *   ym_mail_log    every email sent (kind, reference, contact, store, time): idempotency and cross-store caps
 *   ym_mail_events webhook events already applied (idempotent bounces, complaints, unsubscribes)
 * Phones are never used to merge people (households share phones). Orders link to a contact by meta only.
 *
 * Rules:
 *   - Checkout is guest-first and phone-first. Email is optional (receipt only); none is ever made up.
 *   - Marketing needs its own unticked consent and a confirmed address (double opt-in). Cookie choices, orders and
 *     social follows never imply it.
 *   - An unsubscribe, bounce or complaint in any store suppresses marketing in every store. Nothing re-subscribes a
 *     contact except her own new confirmed opt-in; imports and syncs never do.
 *   - Caps across all stores: at most 1 marketing email a day and 3 a week per contact.
 *   - Every flow is OFF until tested and switched on (option ym_email_flows): transactional, newsletter
 *     (signup, confirmation, welcome), post_delivery, cross_sell. Abandoned-checkout mail is not built (docs/EMAIL.md).
 *
 * Transport: Brevo's API when YM_BREVO_KEY is set (transactional and marketing templates kept apart by tag),
 * otherwise wp_mail. Local test hook ym_test_mail_capture records messages and Brevo calls instead of sending.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Mail {

	const WORDING   = '2026-10-29';
	const FLOWS     = array( 'transactional', 'newsletter', 'post_delivery', 'cross_sell' );
	const CAP_DAY   = 1;
	const CAP_WEEK  = 3;
	const BREVO_API = 'https://api.brevo.com/v3';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'links' ), 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 30, 3 );
		add_action( 'ym_lifecycle_event', array( __CLASS__, 'on_event' ), 20, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_signup' ), 15 );
		add_action( 'ym_mail_later', array( __CLASS__, 'later' ), 10, 3 );
		// WooCommerce's own customer emails say "processing", "completed" or "payment": wrong for a COD request.
		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_failed_order', 'customer_invoice' ) as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, '__return_false' );
		}
	}

	public static function enabled( $flow ) {
		return in_array( $flow, (array) get_option( 'ym_email_flows', array() ), true );
	}

	/* ---------- tables ---------- */

	/** Fully qualified table: in the shared database when configured (all stores), else this store's own. */
	private static function t( $name ) {
		global $wpdb;
		$db = defined( 'YM_SHARED_DB' ) ? (string) YM_SHARED_DB : '';
		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $db ) ? '`' . $db . '`.`' . $name . '`' : '`' . $wpdb->prefix . $name . '`';
	}

	public static function install() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::t( 'ym_contacts' ) . " (id char(32) NOT NULL, email varchar(190) NOT NULL, status varchar(12) NOT NULL, consent_at int unsigned NOT NULL DEFAULT 0, consent_wording varchar(20) NOT NULL DEFAULT '', source varchar(40) NOT NULL DEFAULT '', country char(2) NOT NULL DEFAULT '', lang varchar(8) NOT NULL DEFAULT 'ar', confirmed_at int unsigned NOT NULL DEFAULT 0, unsub_at int unsigned NOT NULL DEFAULT 0, updated_at int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY email (email)) DEFAULT CHARSET=utf8mb4 ENGINE=InnoDB" );
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::t( 'ym_mail_log' ) . " (id bigint unsigned NOT NULL AUTO_INCREMENT, contact char(32) NOT NULL DEFAULT '', email_hash char(64) NOT NULL, kind varchar(30) NOT NULL, ref varchar(40) NOT NULL, store char(2) NOT NULL, marketing tinyint(1) NOT NULL DEFAULT 0, at int unsigned NOT NULL, PRIMARY KEY (id), UNIQUE KEY once (email_hash, kind, ref), KEY caps (email_hash, marketing, at)) DEFAULT CHARSET=ascii ENGINE=InnoDB" );
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::t( 'ym_mail_events' ) . ' (id char(32) NOT NULL, at int unsigned NOT NULL, PRIMARY KEY (id)) DEFAULT CHARSET=ascii ENGINE=InnoDB' );
		// phpcs:enable
	}

	private static function q( $fn, ...$args ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$r = $wpdb->$fn( ...$args );
		if ( '' !== $wpdb->last_error && false !== stripos( $wpdb->last_error, "doesn't exist" ) ) {
			self::install();
			$r = $wpdb->$fn( ...$args );
		}
		$wpdb->suppress_errors( false );
		return $r;
	}

	public static function contact( $email ) {
		global $wpdb;
		$email = self::clean_email( $email );
		if ( ! $email ) {
			return null;
		}
		return self::q( 'get_row', $wpdb->prepare( 'SELECT * FROM ' . self::t( 'ym_contacts' ) . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function clean_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return is_email( $email ) ? $email : '';
	}

	private static function save_contact( array $c ) {
		global $wpdb;
		$c['updated_at'] = time();
		$cols = array_keys( $c );
		$sql  = 'INSERT INTO ' . self::t( 'ym_contacts' ) . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '%s' ) ) . ') ON DUPLICATE KEY UPDATE ' . implode( ',', array_map( fn( $k ) => "$k = VALUES($k)", array_diff( $cols, array( 'id', 'email' ) ) ) );
		return false !== self::q( 'query', $wpdb->prepare( $sql, array_values( $c ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------- consent: signup, confirm, unsubscribe ---------- */

	/** Newsletter signup: explicit consent required. Returns 'pending' | 'invalid' | 'no_consent' | 'disabled'. */
	public static function subscribe( $email, $consent, $source ) {
		if ( ! self::enabled( 'newsletter' ) ) {
			return 'disabled';
		}
		$email = self::clean_email( $email );
		if ( ! $email ) {
			return 'invalid';
		}
		if ( ! $consent ) {
			return 'no_consent';
		}
		$c = self::contact( $email );
		if ( $c && 'subscribed' === $c['status'] ) {
			return 'pending'; // same answer: never reveal whether an address is already on the list
		}
		$c = array_merge(
			$c ? $c : array( 'id' => bin2hex( random_bytes( 16 ) ), 'email' => $email, 'confirmed_at' => 0, 'unsub_at' => 0 ),
			array( 'status' => 'pending', 'consent_at' => time(), 'consent_wording' => self::WORDING, 'source' => substr( sanitize_key( $source ), 0, 40 ), 'country' => YM_Country::code(), 'lang' => 'ar' )
		);
		self::save_contact( $c );
		$link = self::link( 'confirm', $c );
		self::send( $email, YM_Country::copy( 'mail_confirm_subject' ), array( YM_Country::copy( 'mail_confirm_body' ) ), array( 'kind' => 'confirm', 'ref' => (string) time(), 'button' => array( YM_Country::copy( 'mail_confirm_button' ), $link ) ) );
		return 'pending';
	}

	public static function confirm( array $c ) {
		if ( ! in_array( $c['status'], array( 'pending', 'subscribed' ), true ) ) {
			return false; // an unsubscribe or complaint after the confirmation mail was sent wins
		}
		$first = 'pending' === $c['status'];
		$c['status']       = 'subscribed';
		$c['confirmed_at'] = time();
		self::save_contact( $c );
		self::brevo_contact( $c['email'], array( 'listIds' => self::brevo_list( $c['country'] ), 'emailBlacklisted' => false, 'attributes' => array( 'COUNTRY' => $c['country'], 'CONSENT_AT' => gmdate( 'c', (int) $c['consent_at'] ), 'CONSENT_WORDING' => $c['consent_wording'], 'SOURCE' => $c['source'] ) ) );
		if ( $first ) {
			self::send_marketing( $c, 'welcome', $c['id'], YM_Country::copy( 'mail_welcome_subject' ), array( YM_Country::copy( 'mail_welcome_body' ) ) );
		}
		return true;
	}

	/** Unsubscribe (also from a bounce or complaint): marketing stops in every store; never undone by a sync. */
	public static function suppress( $email, $status = 'unsubscribed' ) {
		$c = self::contact( $email );
		if ( ! $c ) {
			$email = self::clean_email( $email );
			if ( ! $email ) {
				return false;
			}
			$c = array( 'id' => bin2hex( random_bytes( 16 ) ), 'email' => $email, 'consent_at' => 0, 'confirmed_at' => 0, 'country' => YM_Country::code() );
		}
		// The strongest suppression wins: complained > bounced > unsubscribed > (pending, subscribed).
		$rank = array( 'pending' => 0, 'subscribed' => 0, 'unsubscribed' => 1, 'bounced' => 2, 'complained' => 3 );
		if ( $rank[ $status ] >= ( $rank[ $c['status'] ?? 'pending' ] ?? 0 ) ) {
			$c['status'] = $status;
		}
		$c['unsub_at'] = time();
		self::save_contact( $c );
		self::brevo_contact( $c['email'], array( 'emailBlacklisted' => true ) );
		return true;
	}

	/**
	 * Merge contacts from elsewhere (an export, a Brevo sync). Never upgrades a suppressed or pending contact to
	 * subscribed: only her own confirmed opt-in does that. Returns counts.
	 */
	public static function import( array $rows ) {
		$out = array( 'added_suppressed' => 0, 'kept' => 0 );
		foreach ( $rows as $r ) {
			$email = self::clean_email( $r['email'] ?? '' );
			if ( ! $email ) {
				continue;
			}
			$status = (string) ( $r['status'] ?? '' );
			if ( in_array( $status, array( 'unsubscribed', 'bounced', 'complained' ), true ) ) {
				self::suppress( $email, $status );
				++$out['added_suppressed'];
			} else {
				++$out['kept']; // a "subscribed" row from outside proves nothing about consent here
			}
		}
		return $out;
	}

	/* ---------- signed links ---------- */

	private static function secret() {
		return defined( 'YM_MAIL_SECRET' ) && YM_MAIL_SECRET ? (string) YM_MAIL_SECRET : wp_salt( 'auth' );
	}

	public static function link( $action, array $c, $days = 30 ) {
		$exp = time() + $days * DAY_IN_SECONDS;
		$sig = substr( hash_hmac( 'sha256', $action . '|' . $c['id'] . '|' . $c['email'] . '|' . $exp, self::secret() ), 0, 32 );
		return add_query_arg( array( 'ym_' . $action => $c['id'] . '.' . $exp . '.' . $sig ), home_url( '/' ) );
	}

	private static function verify( $action, $token ) {
		if ( ! preg_match( '/^([a-f0-9]{32})\.(\d+)\.([a-f0-9]{32})$/', (string) $token, $m ) || (int) $m[2] < time() ) {
			return null;
		}
		global $wpdb;
		$c = self::q( 'get_row', $wpdb->prepare( 'SELECT * FROM ' . self::t( 'ym_contacts' ) . ' WHERE id = %s', $m[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $c ) {
			return null;
		}
		$sig = substr( hash_hmac( 'sha256', $action . '|' . $c['id'] . '|' . $c['email'] . '|' . $m[2], self::secret() ), 0, 32 );
		return hash_equals( $sig, $m[3] ) ? $c : null;
	}

	/** ?ym_confirm= and ?ym_unsub= (GET shows a page; POST is the one-click unsubscribe from the mail client). */
	public static function links() {
		foreach ( array( 'confirm', 'unsub' ) as $a ) {
			if ( empty( $_GET[ 'ym_' . $a ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$c   = self::verify( $a, sanitize_text_field( wp_unslash( $_GET[ 'ym_' . $a ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg = 'news_invalid';
			if ( $c && 'confirm' === $a && self::confirm( $c ) ) {
				$msg = 'news_confirmed';
			} elseif ( $c && 'unsub' === $a ) {
				self::suppress( $c['email'] );
				$msg = 'news_unsubscribed';
			}
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				status_header( $c ? 200 : 400 );
				exit;
			}
			wp_die( '<p data-ym-mail-result="' . esc_attr( $msg ) . '">' . esc_html( YM_Country::copy( $msg ) ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( YM_Country::copy( 'home_word' ) ) . '</a></p>', esc_html( YM_Country::copy( 'news_title' ) ), array( 'response' => $c ? 200 : 400 ) );
		}
	}

	/* ---------- REST: signup and provider webhook ---------- */

	public static function routes() {
		register_rest_route( 'ym/v1', '/subscribe', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_subscribe' ) ) );
		register_rest_route( 'ym/v1', '/mail-events', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_events' ) ) );
	}

	public static function rest_subscribe( WP_REST_Request $req ) {
		if ( YM_DB::hit( 'subscribe', YM_Net::client_ip(), 600 ) > 10 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$r = self::subscribe( $req->get_param( 'email' ), '1' === (string) $req->get_param( 'consent' ), (string) $req->get_param( 'source' ) );
		$msg = array( 'pending' => 'news_pending', 'no_consent' => 'news_need_agree', 'invalid' => 'email_err', 'disabled' => 'news_invalid' )[ $r ];
		return new WP_REST_Response( array( 'ok' => 'pending' === $r, 'message' => YM_Country::copy( $msg ) ), 'pending' === $r ? 200 : 400 );
	}

	/**
	 * Brevo webhook (hard_bounce, spam, unsubscribed, blocked, invalid_email): authenticated by YM_BREVO_WEBHOOK_KEY
	 * in the X-YM-Key header. Each event is applied once.
	 */
	public static function rest_events( WP_REST_Request $req ) {
		$key = defined( 'YM_BREVO_WEBHOOK_KEY' ) ? (string) YM_BREVO_WEBHOOK_KEY : ( YM_Env::test_hooks() ? (string) get_option( 'ym_test_webhook_key' ) : '' );
		if ( '' === $key || ! hash_equals( $key, (string) $req->get_header( 'x_ym_key' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$list    = $req->get_json_params();
		$list    = isset( $list['event'] ) ? array( $list ) : (array) $list;
		$applied = 0;
		global $wpdb;
		foreach ( $list as $e ) {
			$email = self::clean_email( $e['email'] ?? '' );
			$map   = array( 'hard_bounce' => 'bounced', 'invalid_email' => 'bounced', 'blocked' => 'bounced', 'spam' => 'complained', 'unsubscribed' => 'unsubscribed' );
			if ( ! $email || ! isset( $map[ $e['event'] ?? '' ] ) ) {
				continue;
			}
			$id  = md5( ( $e['event'] ?? '' ) . '|' . $email . '|' . ( $e['message-id'] ?? '' ) . '|' . ( $e['ts_event'] ?? $e['ts'] ?? '' ) );
			$new = self::q( 'query', $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'ym_mail_events' ) . ' (id, at) VALUES (%s, %d)', $id, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 === (int) $new ) {
				self::suppress( $email, $map[ $e['event'] ] );
				++$applied;
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'applied' => $applied ), 200 );
	}

	/* ---------- flows ---------- */

	/** Order received: a request, not a confirmation and not a payment. */
	public static function on_order( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$c = self::contact( $order->get_billing_email() );
		if ( $c ) {
			$order->update_meta_data( '_ym_contact_id', $c['id'] ); // a link, never a merge
			$order->save_meta_data();
		}
		self::transactional( $order, 'received' );
	}

	public static function on_event( $order, $event ) {
		if ( ! $order instanceof WC_Order || empty( $event['verified'] ) || ! empty( $event['conflict'] ) ) {
			return; // only what is known to have happened
		}
		$map = array( 'confirmed' => 'confirmed', 'shipped' => 'shipped', 'cancelled' => 'cancelled', 'refund_partial' => 'refund', 'refund_full' => 'refund' );
		if ( isset( $map[ $event['e'] ] ) ) {
			self::transactional( $order, $map[ $event['e'] ], $event );
		}
		if ( 'delivered' === $event['e'] && self::enabled( 'post_delivery' ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 2 * DAY_IN_SECONDS, 'ym_mail_later', array( 'post_delivery', $order->get_id(), '' ), 'yawmiyati' );
		}
		if ( 'delivered' === $event['e'] && self::enabled( 'cross_sell' ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 10 * DAY_IN_SECONDS, 'ym_mail_later', array( 'cross_sell', $order->get_id(), '' ), 'yawmiyati' );
		}
	}

	public static function transactional( WC_Order $order, $kind, $event = array() ) {
		$email = self::clean_email( $order->get_billing_email() );
		if ( ! $email || ! self::enabled( 'transactional' ) ) {
			return false;
		}
		$c = self::contact( $email );
		if ( $c && 'bounced' === $c['status'] ) {
			return false;
		}
		$tx   = YM_Env::transaction_id( $order );
		$body = array( 'refund' === $kind ? sprintf( YM_Country::copy( 'mail_refund_body' ), YM_Country::money( (float) ( $event['amount'] ?? 0 ) ) ) : ( 'shipped' === $kind ? sprintf( YM_Country::copy( 'mail_shipped_body' ), YM_Country::money( $order->get_total() ) ) : YM_Country::copy( 'mail_' . $kind . '_body' ) ) );
		return self::send( $email, sprintf( YM_Country::copy( 'mail_' . $kind . '_subject' ), $tx ), $body, array( 'kind' => 'order_' . $kind, 'ref' => $tx . ( 'refund' === $kind ? '-' . ( $event['id'] ?? '' ) : '' ), 'order' => $order, 'contact' => $c['id'] ?? '' ) );
	}

	/** Delayed flows: re-checked when they run (she may have unsubscribed, complained or returned the item since). */
	public static function later( $flow, $order_id, $unused = '' ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! self::enabled( $flow ) ) {
			return;
		}
		$c = self::contact( $order->get_billing_email() );
		if ( ! $c || 'subscribed' !== $c['status'] || ! YM_Lifecycle::was_delivered( $order ) ) {
			return;
		}
		$tx = YM_Env::transaction_id( $order );
		if ( 'post_delivery' === $flow ) {
			// Neutral invitation: the same for everyone, sent after a verified delivery even if she later returned it.
			$link = class_exists( 'YM_Reviews' ) ? YM_Reviews::link( $order ) : '';
			self::send_marketing( $c, 'post_delivery', $tx, sprintf( YM_Country::copy( 'mail_delivered_subject' ), $tx ), array( YM_Country::copy( 'mail_delivered_body' ) ), $link ? array( YM_Country::copy( 'mail_review_button' ), $link ) : null );
		} elseif ( 'cross_sell' === $flow ) {
			if ( 'open' === $order->get_meta( '_ym_support' ) || 'delivered_kept' !== YM_Lifecycle::outcome( $order ) ) {
				return; // never during an unresolved complaint or after a return
			}
			$sources = array_filter( array_map( fn( $it ) => $it->get_product(), array_values( $order->get_items() ) ) );
			$skus    = array_map( fn( $p ) => $p->get_sku(), $sources );
			$recs    = YM_Reco::for_products( $sources, $skus );
			if ( ! $recs ) {
				return;
			}
			$r = $recs[0]['product'];
			self::send_marketing( $c, 'cross_sell', $tx, YM_Country::copy( 'reco_title' ), array( $r->get_name() . ' — ' . YM_Country::money( $r->get_price() ) ), array( YM_Country::copy( 'reco_add' ), YM_Offers::primary( $r )->get_permalink() ) );
		}
	}

	/** A marketing email: subscribed only, caps across all stores, unsubscribe link and header. */
	public static function send_marketing( array $c, $kind, $ref, $subject, array $paras, $button = null ) {
		if ( 'subscribed' !== $c['status'] ) {
			return false;
		}
		global $wpdb;
		$h    = hash( 'sha256', $c['email'] );
		$day  = (int) self::q( 'get_var', $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'ym_mail_log' ) . ' WHERE email_hash = %s AND marketing = 1 AND at > %d', $h, time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$week = (int) self::q( 'get_var', $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'ym_mail_log' ) . ' WHERE email_hash = %s AND marketing = 1 AND at > %d', $h, time() - WEEK_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $day >= self::CAP_DAY || $week >= self::CAP_WEEK ) {
			YM_Ops::log( 'mail_capped', array( 'kind' => $kind ) );
			return false;
		}
		return self::send( $c['email'], $subject, $paras, array( 'kind' => $kind, 'ref' => (string) $ref, 'marketing' => true, 'contact' => $c['id'], 'unsub' => self::link( 'unsub', $c, 365 ), 'button' => $button ) );
	}

	/* ---------- sending ---------- */

	/** Send once per (address, kind, ref) across all stores. Returns true when sent (or captured locally). */
	public static function send( $to, $subject, array $paras, array $o = array() ) {
		global $wpdb;
		$kind = (string) ( $o['kind'] ?? 'other' );
		$ref  = (string) ( $o['ref'] ?? '' );
		$mkt  = ! empty( $o['marketing'] );
		$once = self::q( 'query', $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'ym_mail_log' ) . ' (contact, email_hash, kind, ref, store, marketing, at) VALUES (%s, %s, %s, %s, %s, %d, %d)', (string) ( $o['contact'] ?? '' ), hash( 'sha256', $to ), $kind, $ref, YM_Country::code(), $mkt ? 1 : 0, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== (int) $once ) {
			return false; // already sent (a retry, or another store)
		}
		list( $html, $text ) = self::render( $subject, $paras, $o );
		$headers = array();
		if ( $mkt && ! empty( $o['unsub'] ) ) {
			$headers['List-Unsubscribe']      = '<' . $o['unsub'] . '>';
			$headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
		}
		$msg = array( 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'kind' => $kind, 'ref' => $ref, 'marketing' => $mkt, 'headers' => $headers, 'store' => YM_Country::code() );
		if ( YM_Env::test_hooks() && get_option( 'ym_test_mail_capture' ) ) {
			$box   = (array) get_option( 'ym_test_mail_outbox', array() );
			$box[] = $msg;
			update_option( 'ym_test_mail_outbox', $box, false );
			return true;
		}
		$key = defined( 'YM_BREVO_KEY' ) ? (string) YM_BREVO_KEY : '';
		if ( $key ) {
			$sender = (array) get_option( 'ym_mail_from', array( 'email' => get_option( 'admin_email' ), 'name' => 'Yawmiyati' ) );
			$body   = array( 'sender' => $sender, 'to' => array( array( 'email' => $to ) ), 'subject' => $subject, 'htmlContent' => $html, 'textContent' => $text, 'tags' => array( $mkt ? 'marketing' : 'transactional', $kind, YM_Country::code() ), 'headers' => (object) $headers );
			if ( get_option( 'ym_mail_reply_to' ) ) {
				$body['replyTo'] = array( 'email' => (string) get_option( 'ym_mail_reply_to' ) );
			}
			$r = wp_remote_post( self::BREVO_API . '/smtp/email', array( 'timeout' => 10, 'headers' => array( 'api-key' => $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
			$ok = ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
		} else {
			$hdr = array( 'Content-Type: text/html; charset=UTF-8' );
			foreach ( $headers as $k => $v ) {
				$hdr[] = $k . ': ' . $v;
			}
			if ( get_option( 'ym_mail_reply_to' ) ) {
				$hdr[] = 'Reply-To: ' . get_option( 'ym_mail_reply_to' );
			}
			$ok = wp_mail( $to, $subject, $html, $hdr );
		}
		if ( ! $ok ) {
			// Release the "sent once" row so a later retry can send it.
			self::q( 'query', $wpdb->prepare( 'DELETE FROM ' . self::t( 'ym_mail_log' ) . ' WHERE email_hash = %s AND kind = %s AND ref = %s', hash( 'sha256', $to ), $kind, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			YM_Ops::log( 'mail_failed', array( 'kind' => $kind ), 'warning' );
		}
		return $ok;
	}

	/** Arabic, right-to-left, table layout with inline styles (Gmail and Outlook), plus a plain-text version. */
	public static function render( $subject, array $paras, array $o ) {
		$p    = '';
		$text = $subject . "\n\n";
		foreach ( $paras as $para ) {
			$p    .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.8;color:#2A2228">' . esc_html( $para ) . '</p>';
			$text .= $para . "\n\n";
		}
		if ( ! empty( $o['order'] ) && $o['order'] instanceof WC_Order ) {
			$rows = '';
			foreach ( $o['order']->get_items() as $it ) {
				$rows .= '<tr><td style="padding:6px 0;font-size:15px">' . esc_html( $it->get_name() ) . ' × ' . (int) $it->get_quantity() . '</td><td style="padding:6px 0;font-size:15px;text-align:left;white-space:nowrap">' . esc_html( YM_Country::money( $it->get_total() ) ) . '</td></tr>';
				$text .= $it->get_name() . ' × ' . (int) $it->get_quantity() . ': ' . YM_Country::money( $it->get_total() ) . "\n";
			}
			$rows .= '<tr><td style="padding:8px 0;border-top:1px solid #EEE6E0;font-weight:bold">' . esc_html( YM_Country::copy( 'mail_order_total' ) ) . '</td><td style="padding:8px 0;border-top:1px solid #EEE6E0;font-weight:bold;text-align:left;white-space:nowrap">' . esc_html( YM_Country::money( $o['order']->get_total() ) ) . '</td></tr>';
			$text .= YM_Country::copy( 'mail_order_total' ) . ': ' . YM_Country::money( $o['order']->get_total() ) . "\n\n";
			$p    .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="rtl" style="margin:4px 0 16px">' . $rows . '</table>';
		}
		if ( ! empty( $o['button'] ) ) {
			$p    .= '<p style="margin:8px 0 18px"><a href="' . esc_url( $o['button'][1] ) . '" style="display:inline-block;padding:12px 22px;background:#7B2D58;color:#ffffff;border-radius:10px;text-decoration:none;font-weight:bold">' . esc_html( $o['button'][0] ) . '</a></p>';
			$text .= $o['button'][0] . ': ' . $o['button'][1] . "\n\n";
		}
		$foot  = esc_html( YM_Country::copy( 'mail_footer' ) );
		$text .= YM_Country::copy( 'mail_footer' ) . "\n";
		if ( ! empty( $o['unsub'] ) ) {
			$foot .= ' <a href="' . esc_url( $o['unsub'] ) . '" style="color:#6E6268">' . esc_html( YM_Country::copy( 'mail_unsub' ) ) . '</a>';
			$text .= YM_Country::copy( 'mail_unsub' ) . ': ' . $o['unsub'] . "\n";
		}
		$html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#FAF6F1"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF6F1"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="rtl" style="max-width:560px;background:#ffffff;border:1px solid #EEE6E0;border-radius:14px;font-family:Tahoma,Arial,sans-serif;text-align:right">'
			. '<tr><td style="padding:22px 22px 6px;font-size:20px;font-weight:bold;color:#7B2D58">يومياتي</td></tr>'
			. '<tr><td style="padding:10px 22px 4px">' . $p . '</td></tr>'
			. '<tr><td style="padding:12px 22px 20px;font-size:12.5px;line-height:1.7;color:#6E6268;border-top:1px solid #EEE6E0">' . $foot . '</td></tr>'
			. '</table></td></tr></table></body></html>';
		return array( $html, $text );
	}

	/* ---------- Brevo contacts (marketing lists only) ---------- */

	private static function brevo_list( $cc ) {
		$lists = (array) get_option( 'ym_brevo_lists', array() );
		return isset( $lists[ $cc ] ) ? array( (int) $lists[ $cc ] ) : array();
	}

	private static function brevo_contact( $email, array $fields ) {
		$key = defined( 'YM_BREVO_KEY' ) ? (string) YM_BREVO_KEY : '';
		if ( YM_Env::test_hooks() && get_option( 'ym_test_mail_capture' ) ) {
			$log   = (array) get_option( 'ym_test_brevo_calls', array() );
			$log[] = array( 'email' => $email, 'fields' => $fields );
			update_option( 'ym_test_brevo_calls', $log, false );
			return true;
		}
		if ( ! $key ) {
			return false;
		}
		$r = wp_remote_post( self::BREVO_API . '/contacts', array( 'timeout' => 10, 'headers' => array( 'api-key' => $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array_merge( array( 'email' => $email, 'updateEnabled' => true ), $fields ) ) ) );
		return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
	}

	/* ---------- forms ---------- */

	public static function signup_form( $source, $email = '' ) {
		if ( ! self::enabled( 'newsletter' ) ) {
			return '';
		}
		if ( 'footer' === $source && function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_checkout() ) ) {
			return ''; // one signup per page: after ordering it sits under the order; never inside the checkout
		}
		$id = 'ym-news-' . sanitize_key( $source );
		return '<form class="ym-news" data-ym-news data-src="' . esc_attr( $source ) . '" novalidate>'
			. '<p class="ym-news__title">' . esc_html( YM_Country::copy( 'news_title' ) ) . '</p><p class="ym-news__body">' . esc_html( YM_Country::copy( 'news_body' ) ) . '</p>'
			. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( YM_Country::copy( 'news_email' ) ) . '</label>'
			. '<input type="email" id="' . esc_attr( $id ) . '" name="email" dir="ltr" autocomplete="email" placeholder="' . esc_attr( YM_Country::copy( 'news_email' ) ) . '" value="' . esc_attr( $email ) . '">'
			. '<label class="ym-news__agree"><input type="checkbox" name="consent" value="1"> ' . esc_html( YM_Country::copy( 'news_agree' ) ) . '</label>'
			. '<button type="submit" class="ym-btn ym-btn--secondary">' . esc_html( YM_Country::copy( 'news_button' ) ) . '</button><p class="ym-news__msg" role="status" data-ym-news-msg></p>'
			. '</form><script>(function(f){f.addEventListener("submit",function(e){e.preventDefault();var m=f.querySelector("[data-ym-news-msg]");fetch(' . wp_json_encode( esc_url_raw( rest_url( 'ym/v1/subscribe' ) ) ) . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({email:f.email.value,consent:f.consent.checked?"1":"0",source:f.dataset.src})}).then(function(r){return r.json();}).then(function(j){m.textContent=j.message||"";if(j.ok){f.email.disabled=true;f.consent.disabled=true;f.querySelector("button").disabled=true;}});});})(document.currentScript.previousElementSibling);</script>';
	}

	public static function thankyou_signup( $order_id ) {
		$o = wc_get_order( $order_id );
		echo self::signup_form( 'after_order', $o ? (string) $o->get_billing_email() : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
	}

	/** Housekeeping (daily): unconfirmed signups are deleted after 30 days; suppressions are kept (they protect her). */
	public static function cleanup() {
		global $wpdb;
		return (int) self::q( 'query', $wpdb->prepare( 'DELETE FROM ' . self::t( 'ym_contacts' ) . " WHERE status = 'pending' AND consent_at < %d", time() - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
