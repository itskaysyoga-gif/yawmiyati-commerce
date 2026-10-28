<?php
/**
 * Cash-on-delivery abuse controls. Spec: docs/RISK.md.
 *
 * Principle: decisions come from recorded behaviour and the recorded reason for a failure, never from who a customer
 * is. One signal never blocks anyone permanently: shared family phones, recycled numbers and shared connections are
 * normal. Most controls HOLD an order for a person to check rather than refuse it.
 *
 *   Refused at submission (nothing created):
 *     honeypot          a hidden field a person cannot see was filled
 *     rate_limited      too many complete submissions from one browser session (6 / 10 min), or an extreme rate
 *                       from one connection (120 / 10 min). Typing mistakes never count.
 *     bot_check         Cloudflare Turnstile failed (only when keys are configured)
 *   Created and HELD (on-hold, never handed to the supplier until a person releases it), with reason codes:
 *     possible_duplicate  same phone ordered an overlapping product in the last 24 h (retry, replacement or a real
 *                         second order: a person decides)
 *     phone_velocity      this phone placed 3+ orders in 24 h
 *     fast_submit         the form was sent within 4 s of being shown
 *     many_units          more units than ym_review_units (default 6) in one order
 *     confirm_first       the phone has a risk record at level "confirm" (repeated customer-caused refusals)
 *     risk_hold           the phone has a risk record at level "hold" (proven abuse)
 *     ip_burst            30+ complete submissions from one connection in 10 min (shared carrier IPs are normal,
 *                         so this holds for review instead of refusing)
 *     bot_check_unavailable  Turnstile could not be reached; the order is kept and checked by a person
 *
 * Risk records (per phone, stored hashed): level watch | confirm | hold, with reasons, evidence (order IDs), who set
 * it, when, and an expiry. Built automatically from VERIFIED refusals whose reason is customer-caused; operational
 * causes (supplier delay, wrong or damaged item, late delivery, unsupported address) never count against her.
 * Staff can set, extend or clear a record; every change is kept in its history.
 *
 * The hold is decided and recorded on the order before it is first saved; WooCommerce then moves it from pending
 * straight to on-hold, never through processing (tested with a probe on the usual supplier-plugin hooks).
 * Whether Taager's plugin skips on-hold orders is a verification task (docs/RISK.md §5): until it is verified on a
 * real store, "held" means held by us, not proven held at the supplier.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Risk {

	const LEVELS        = array( 'watch', 'confirm', 'hold' );
	const HOLD_REASONS  = array( 'possible_duplicate', 'phone_velocity', 'fast_submit', 'many_units', 'confirm_first', 'risk_hold' );
	const MIN_FORM_SECS = 4;

	public static function init() {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'fields' ), 5 );
		// After field validation, before the order token is claimed: only well-formed submissions count.
		add_action( 'ym_checkout_before_claim', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'assess' ), 30, 2 );
		add_action( 'ym_lifecycle_event', array( __CLASS__, 'on_event' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'turnstile_script' ) );
	}

	/* ---------- settings ---------- */

	public static function setting( $key ) {
		$defaults = array(
			'session_burst'   => 6,    // complete submissions per browser session per 10 minutes, then refused
			'ip_hold'         => 30,   // per connection per 10 minutes, then held (mobile carriers share IPs)
			'ip_refuse'       => 120,  // per connection per 10 minutes, then refused (an attack, not customers)
			'phone_day'       => 3,    // orders per phone per 24 h before phone_velocity
			'review_units'    => 6,    // units per order before many_units
			'confirm_after'   => 2,    // verified customer-caused refusals before "confirm"
			'hold_after_fake' => 2,    // verified fake_order refusals before "hold"
			'confirm_days'    => 90,
			'hold_days'       => 30,
		);
		$custom = (array) get_option( 'ym_risk_settings', array() );
		return (int) ( $custom[ $key ] ?? $defaults[ $key ] );
	}

	/* ---------- form fields ---------- */

	public static function fields() {
		$ts = time();
		// Honeypot: off-screen, not focusable, no autofill. A person never fills it.
		echo '<div class="ym-hp" aria-hidden="true"><label for="ym_company_site">Company site</label><input type="text" id="ym_company_site" name="ym_company_site" value="" tabindex="-1" autocomplete="off"></div>';
		echo '<input type="hidden" name="ym_form_ts" value="' . esc_attr( $ts . '.' . self::sign( (string) $ts ) ) . '">';
		if ( self::turnstile_on() ) {
			echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( (string) get_option( 'ym_turnstile_sitekey' ) ) . '" data-action="checkout" data-size="flexible" data-language="ar"></div>';
		}
	}

	private static function sign( $v ) {
		return substr( hash_hmac( 'sha256', $v, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** Seconds since the form was shown, or null when the stamp is missing or forged. */
	private static function form_age() {
		$raw = isset( $_POST['ym_form_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['ym_form_ts'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$p   = explode( '.', $raw );
		if ( 2 !== count( $p ) || ! hash_equals( self::sign( $p[0] ), $p[1] ) ) {
			return null;
		}
		$now = time() + (int) YM_Env::test_header( 'clock' ); // local test hook: shift the clock
		return $now - (int) $p[0];
	}

	/* ---------- refusals at submission ---------- */

	/** The visitor's address: forwarding headers count only from a configured trusted proxy (YM_Net). */
	public static function ip() {
		$t = YM_Env::test_header( 'ip' ); // local test hook: pretend to come from another connection
		return (string) ( $t ? $t : YM_Net::client_ip() );
	}

	/** Count an attempt in a 10-minute window; atomic across simultaneous requests (YM_DB). Stored hashed. */
	private static function hit( $kind, $id, $window = 600 ) {
		return YM_DB::hit( $kind, $id, $window );
	}

	public static function validate( $data, WP_Error $errors ) {
		if ( ! empty( $_POST['ym_company_site'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			YM_Ops::log( 'risk_honeypot', array( 'ip' => substr( hash( 'sha256', self::ip() ), 0, 12 ) ), 'warning' );
			$errors->add( 'ym_blocked', YM_Country::copy( 'try_again' ) );
			return;
		}
		$session = WC()->session ? (string) WC()->session->get_customer_id() : '';
		$by_sess = self::hit( 'sess', $session );
		$by_ip   = self::hit( 'ip', self::ip() );
		if ( $by_sess > self::setting( 'session_burst' ) || $by_ip > self::setting( 'ip_refuse' ) ) {
			YM_Ops::log( 'risk_rate_limited', array( 'session' => $by_sess, 'ip' => $by_ip ), 'warning' );
			$errors->add( 'ym_rate_limited', YM_Country::copy( 'rate_limited' ) );
			return;
		}
		if ( $by_ip > self::setting( 'ip_hold' ) ) {
			self::$extra_hold[] = 'ip_burst'; // a busy shared connection: a person looks before hand-off
		}
		if ( self::turnstile_on() && ! $errors->has_errors() ) {
			$check = self::turnstile_verify();
			if ( true !== $check ) {
				$errors->add( 'ym_bot_check', YM_Country::copy( 'bot_check' ), array( 'reset_turnstile' => true ) );
			}
		}
	}

	/* ---------- Turnstile (off until keys are configured) ---------- */

	public static function turnstile_on() {
		$secret = ( defined( 'YM_TURNSTILE_SECRET' ) && YM_TURNSTILE_SECRET ) || ( YM_Env::test_hooks() && 'stub' === get_option( 'ym_turnstile_test' ) );
		return (bool) get_option( 'ym_turnstile_sitekey' ) && $secret;
	}

	public static function turnstile_script() {
		if ( self::turnstile_on() && function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() ) {
			wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			// A failed or spent token: get a fresh one so the next try can pass (tokens are single-use, 300 s).
			wp_add_inline_script( 'cf-turnstile', 'jQuery(document.body).on("checkout_error",function(){if(window.turnstile){try{turnstile.reset();}catch(e){}}});' );
		}
	}

	/**
	 * Verify the Turnstile token once per checkout. The checkout token (ym_token, a UUID) is the idempotency key, so a
	 * retried verification of the same submission is safe; once it passed, retries of that checkout skip it.
	 * Returns true or an error code.
	 */
	public static function turnstile_verify() {
		$ym  = isset( $_POST['ym_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ym_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tok = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ok  = 'ym_tsok_' . md5( $ym );
		if ( wp_is_uuid( $ym ) && get_transient( $ok ) ) {
			return true;
		}
		if ( '' === $tok ) {
			return 'missing-input-response';
		}
		$result = apply_filters( 'ym_turnstile_siteverify', null, $tok, $ym );
		if ( null === $result ) {
			$resp = null;
			for ( $try = 0; $try < 3; $try++ ) {
				$resp = wp_remote_post(
					'https://challenges.cloudflare.com/turnstile/v0/siteverify',
					array( 'timeout' => 5, 'body' => array( 'secret' => YM_TURNSTILE_SECRET, 'response' => $tok, 'remoteip' => self::ip(), 'idempotency_key' => wp_is_uuid( $ym ) ? $ym : wp_generate_uuid4() ) )
				);
				$body = is_wp_error( $resp ) ? null : json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( is_array( $body ) && ! in_array( 'internal-error', (array) ( $body['error-codes'] ?? array() ), true ) ) {
					break;
				}
				usleep( ( 2 ** $try ) * 250000 );
			}
			$result = is_array( $body ?? null ) ? $body : array( 'success' => false, 'error-codes' => array( 'unreachable' ) );
		}
		// A token is accepted only for this widget's action and one of our own hostnames: a token solved on another
		// site, or for another action, is refused even though Cloudflare says "success".
		if ( ! empty( $result['success'] ) && 'checkout' !== ( $result['action'] ?? '' ) ) {
			$result = array( 'success' => false, 'error-codes' => array( 'action-mismatch' ) );
		} elseif ( ! empty( $result['success'] ) && ! in_array( strtolower( (string) ( $result['hostname'] ?? '' ) ), self::turnstile_hosts(), true ) ) {
			$result = array( 'success' => false, 'error-codes' => array( 'hostname-mismatch' ) );
		}
		if ( ! empty( $result['success'] ) ) {
			if ( wp_is_uuid( $ym ) ) {
				set_transient( $ok, 1, 15 * MINUTE_IN_SECONDS );
			}
			return true;
		}
		$code = (string) ( ( (array) ( $result['error-codes'] ?? array() ) )[0] ?? 'failed' );
		YM_Ops::log( 'risk_turnstile_failed', array( 'code' => $code ) );
		// Cloudflare unreachable: do not lose a real customer's order over our own outage; hold it instead.
		if ( 'unreachable' === $code ) {
			self::$extra_hold[] = 'bot_check_unavailable';
			return true;
		}
		return $code;
	}

	/** Hostnames a Turnstile token may come from: this store's own, plus ym_turnstile_hostnames (comma-separated). */
	public static function turnstile_hosts() {
		$own   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$extra = array_filter( array_map( 'trim', explode( ',', strtolower( (string) get_option( 'ym_turnstile_hostnames', '' ) ) ) ) );
		return array_values( array_unique( array_merge( array( $own ), $extra ) ) );
	}

	private static $extra_hold = array();

	/* ---------- hold decisions (before the order is first saved) ---------- */

	public static function assess( WC_Order $order, $data = array() ) {
		// The order records the same address the limits used (WooCommerce's own lookup trusts forwarding headers).
		$order->set_customer_ip_address( self::ip() );
		$reasons = array();
		$phone   = YM_Country::normalise_phone( $order->get_billing_phone() );
		$dupes   = YM_Orders::find_duplicates( $order );
		if ( $dupes ) {
			$reasons[] = 'possible_duplicate';
			$order->update_meta_data( '_ym_duplicate_of', implode( ',', $dupes ) );
		}
		if ( self::orders_today( $phone ) >= self::setting( 'phone_day' ) ) {
			$reasons[] = 'phone_velocity';
		}
		$age = self::form_age();
		if ( null !== $age && $age < self::MIN_FORM_SECS ) {
			$reasons[] = 'fast_submit';
		}
		if ( (int) $order->get_item_count() > self::setting( 'review_units' ) ) {
			$reasons[] = 'many_units';
		}
		$rec = self::record( $phone );
		if ( $rec && 'confirm' === $rec['level'] ) {
			$reasons[] = 'confirm_first';
		} elseif ( $rec && 'hold' === $rec['level'] ) {
			$reasons[] = 'risk_hold';
		}
		$reasons = array_values( array_unique( array_merge( $reasons, self::$extra_hold ) ) );
		if ( ! $reasons ) {
			return;
		}
		$order->update_meta_data( '_ym_review', $reasons[0] );
		$order->update_meta_data( '_ym_review_reasons', wp_json_encode( $reasons ) );
		$order->update_meta_data( '_ym_review_at', time() );
		// Not set_status( 'on-hold' ) here: WooCommerce would then skip payment handling and mark the order
		// completed. The order is saved as pending, as usual, and the COD step puts it on-hold instead of
		// processing (YM_Orders::cod_status). A held order never passes through processing.
	}

	public static function reasons( WC_Order $order ) {
		$r = json_decode( (string) $order->get_meta( '_ym_review_reasons' ), true );
		return is_array( $r ) ? $r : ( $order->get_meta( '_ym_review' ) ? array( (string) $order->get_meta( '_ym_review' ) ) : array() );
	}

	public static function is_held( WC_Order $order ) {
		return (bool) self::reasons( $order ) && ! $order->get_meta( '_ym_review_released' );
	}

	/** Orders in the last 24 h whose main or second phone is this phone. */
	private static function orders_today( $phone ) {
		if ( ! $phone ) {
			return 0;
		}
		$args = array( 'date_created' => '>' . ( time() - DAY_IN_SECONDS ), 'status' => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed' ) ), 'limit' => 20, 'return' => 'ids' );
		$a    = wc_get_orders( array_merge( $args, array( 'billing_phone' => $phone ) ) );
		$b    = array_filter( YM_Orders::ids_by_meta( '_ym_phone2', $phone, time() - DAY_IN_SECONDS, 20 ), fn( $id ) => ( $o = wc_get_order( $id ) ) && ! in_array( $o->get_status(), array( 'cancelled', 'failed' ), true ) );
		return count( array_unique( array_merge( $a, $b ) ) );
	}

	/* ---------- review queue (staff) ---------- */

	public static function queue() {
		$ids = wc_get_orders( array( 'status' => array( 'on-hold' ), 'limit' => 100, 'return' => 'ids', 'orderby' => 'date', 'order' => 'ASC' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o && self::is_held( $o ) ) {
				$out[] = array( 'order' => $id, 'placed' => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '', 'reasons' => implode( ', ', self::reasons( $o ) ), 'duplicate_of' => (string) $o->get_meta( '_ym_duplicate_of' ), 'total' => $o->get_total() );
			}
		}
		return $out;
	}

	/** A person checked the order (e.g. called her): release it to processing; the supplier hand-off starts then. */
	public static function release( WC_Order $order, $by, $note ) {
		if ( ! self::is_held( $order ) ) {
			return new WP_Error( 'ym_not_held', 'Order is not in the review queue.' );
		}
		if ( '' === trim( (string) $note ) ) {
			return new WP_Error( 'ym_note', 'Say what you checked (e.g. "called, she confirmed a second set").' );
		}
		self::audit( $order, 'released', $by, $note );
		$order->update_meta_data( '_ym_review_released', wp_json_encode( array( 'at' => time(), 'by' => $by, 'note' => $note ) ) );
		$order->save_meta_data();
		$order->update_status( 'processing', 'يومياتي: اتراجع واتأكد (' . $note . ').' );
		return true;
	}

	public static function cancel( WC_Order $order, $by, $reason ) {
		if ( ! self::is_held( $order ) ) {
			return new WP_Error( 'ym_not_held', 'Order is not in the review queue.' );
		}
		self::audit( $order, 'cancelled', $by, $reason );
		$order->update_status( 'cancelled', 'يومياتي: اتلغى بعد المراجعة (' . $reason . ').' );
		return true;
	}

	private static function audit( WC_Order $order, $action, $by, $note ) {
		$log   = json_decode( (string) $order->get_meta( '_ym_review_audit' ), true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'action' => $action, 'by' => $by, 'note' => substr( (string) $note, 0, 300 ), 'at' => time() );
		$order->update_meta_data( '_ym_review_audit', wp_json_encode( $log ) );
		$order->save_meta_data();
		YM_Ops::log( 'review_' . $action, array( 'order' => $order->get_id(), 'by' => $by ) );
	}

	/* ---------- risk records ---------- */

	private static function key( $phone ) {
		return 'ym_risk_' . substr( hash( 'sha256', YM_Country::normalise_phone( $phone ) . '|' . YM_Country::code() ), 0, 40 );
	}

	/** Current record for a phone, or null when none or expired. */
	public static function record( $phone, $include_expired = false ) {
		if ( ! $phone ) {
			return null;
		}
		$r = get_option( self::key( $phone ) );
		if ( ! is_array( $r ) ) {
			return null;
		}
		if ( ! $include_expired && ( ! empty( $r['cleared'] ) || ( (int) $r['expires'] && (int) $r['expires'] < time() ) ) ) {
			return null;
		}
		return $r;
	}

	/** Create or change a record. Every change is appended to its history (who, when, why, previous level). */
	public static function set( $phone, $level, $reason, $by, $days, $evidence = array() ) {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			return new WP_Error( 'ym_level', 'Level must be watch, confirm or hold.' );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new WP_Error( 'ym_reason', 'A reason is required.' );
		}
		$days = max( 1, min( 365, (int) $days ) );
		$old  = self::record( $phone, true );
		$hist = is_array( $old['history'] ?? null ) ? $old['history'] : array();
		$hist[] = array( 'at' => time(), 'by' => $by, 'from' => $old['level'] ?? '', 'to' => $level, 'reason' => substr( (string) $reason, 0, 300 ), 'days' => $days );
		$rec  = array(
			'level'    => $level,
			'reason'   => substr( (string) $reason, 0, 300 ),
			'evidence' => array_values( array_unique( array_map( 'intval', array_merge( (array) ( $old['evidence'] ?? array() ), (array) $evidence ) ) ) ),
			'by'       => $by,
			'created'  => (int) ( $old['created'] ?? time() ),
			'updated'  => time(),
			'expires'  => time() + $days * DAY_IN_SECONDS,
			'history'  => array_slice( $hist, -30 ),
		);
		update_option( self::key( $phone ), $rec, false );
		YM_Ops::log( 'risk_set', array( 'level' => $level, 'by' => $by, 'days' => $days ) );
		return $rec;
	}

	public static function clear( $phone, $by, $reason ) {
		$old = self::record( $phone, true );
		if ( ! $old ) {
			return new WP_Error( 'ym_none', 'No record for this phone.' );
		}
		$old['history'][] = array( 'at' => time(), 'by' => $by, 'from' => $old['level'], 'to' => 'cleared', 'reason' => substr( (string) $reason, 0, 300 ) );
		$old['cleared']   = time();
		update_option( self::key( $phone ), $old, false );
		YM_Ops::log( 'risk_cleared', array( 'by' => $by ) );
		return $old;
	}

	/**
	 * A verified refusal updates the phone's record from the evidence: customer-caused refusals across her orders
	 * (last 180 days). Operational causes are recorded on the order but never counted against her.
	 */
	public static function on_event( WC_Order $order, $event ) {
		if ( 'refused' !== ( $event['e'] ?? '' ) || empty( $event['verified'] ) || ! empty( $event['conflict'] ) ) {
			return;
		}
		$phone = YM_Country::normalise_phone( $order->get_billing_phone() );
		if ( ! $phone ) {
			return;
		}
		$ids = wc_get_orders( array( 'billing_phone' => $phone, 'date_created' => '>' . ( time() - 180 * DAY_IN_SECONDS ), 'status' => array_keys( wc_get_order_statuses() ), 'limit' => 50, 'return' => 'ids' ) );
		$customer = array();
		$fake     = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			foreach ( $o ? YM_Lifecycle::active( YM_Lifecycle::events( $o ) ) : array() as $e ) { // voided or contested refusals never count
				if ( 'refused' === $e['e'] && 'customer' === ( YM_Lifecycle::REASONS[ $e['reason'] ?? 'other' ] ?? '' ) ) {
					$customer[] = $id;
					if ( 'fake_order' === ( $e['reason'] ?? '' ) ) {
						$fake[] = $id;
					}
				}
			}
		}
		$customer = array_values( array_unique( $customer ) );
		$fake     = array_values( array_unique( $fake ) );
		$current  = self::record( $phone );
		if ( count( $fake ) >= self::setting( 'hold_after_fake' ) ) {
			if ( ! $current || 'hold' !== $current['level'] ) {
				self::set( $phone, 'hold', count( $fake ) . ' verified fake-order refusals', 'system', self::setting( 'hold_days' ), $fake );
			}
		} elseif ( count( $customer ) >= self::setting( 'confirm_after' ) ) {
			if ( ! $current || 'watch' === $current['level'] ) {
				self::set( $phone, 'confirm', count( $customer ) . ' verified customer-caused refusals', 'system', self::setting( 'confirm_days' ), $customer );
			}
		} elseif ( 1 === count( $customer ) && ! $current ) {
			self::set( $phone, 'watch', 'one verified customer-caused refusal (no effect on ordering)', 'system', self::setting( 'confirm_days' ), $customer );
		}
	}
}
