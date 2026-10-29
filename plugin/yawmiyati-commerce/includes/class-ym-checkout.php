<?php
/**
 * One-screen COD checkout on WooCommerce's standard (classic) checkout.
 * Orders are created by WooCommerce itself, so every standard checkout hook fires for the Taager plugin.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Checkout {

	const TOKEN_PREFIX = 'ym_tok_';

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 20 );
		add_filter( 'woocommerce_default_address_fields', array( __CLASS__, 'address_fields' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'locale' ) );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );
		add_filter( 'woocommerce_coupons_enabled', '__return_false' );
		add_filter( 'woocommerce_shipping_may_be_available_html', array( __CLASS__, 'shipping_prompt' ) );
		add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'shipping_prompt' ) );
		add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'shipping_prompt' ) );
		add_filter( 'woocommerce_endpoint_order-received_title', fn() => YM_Country::copy( 'thanks' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'cod_only' ) );
		add_filter( 'woocommerce_order_button_text', fn() => YM_Country::copy( 'place' ) );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
		add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
		add_filter( 'woocommerce_process_checkout_field_billing_phone', array( __CLASS__, 'normalise_phone' ) );
		add_filter( 'woocommerce_process_checkout_field_ym_phone2', array( __CLASS__, 'normalise_phone' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'token_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_extra' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'test_fail' ), 99 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'link_order' ), 1 );
		add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'early_replay' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'guard_received' ) );
		add_filter( 'gettext', array( __CLASS__, 'strings' ), 10, 3 );
		// Before a governorate is chosen there is no delivery fee yet: show the products' subtotal and say delivery
		// comes next, never an amount labelled as what she pays on delivery (review of 29 Oct).
		add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'order_total_html' ), 20 );
		add_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_empty_string' );
		add_filter( 'woocommerce_get_privacy_policy_text', fn( $text, $type ) => 'checkout' === $type ? YM_Country::copy( 'privacy_note' ) : $text, 10, 2 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thanks' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'next_steps' ), 5 );
		// Cart: say what delivery costs here instead of "calculated during checkout".
		add_filter( 'woocommerce_shipping_not_enabled_on_cart_html', array( __CLASS__, 'cart_delivery_line' ), 20 );
		add_filter( 'woocommerce_shipping_may_be_available_html', array( __CLASS__, 'cart_delivery_line' ), 20 );
		add_action( 'ym_cleanup_tokens', array( __CLASS__, 'cleanup_tokens' ) );
		if ( ! wp_next_scheduled( 'ym_cleanup_tokens' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ym_cleanup_tokens' );
		}
	}

	public static function fields( $fields ) {
		$rules   = YM_Country::rules();
		$billing = array(
			'billing_first_name' => array_merge( $fields['billing']['billing_first_name'], array( 'label' => YM_Country::copy( 'name_label' ), 'placeholder' => YM_Country::copy( 'name_ph' ), 'class' => array( 'form-row-wide' ), 'priority' => 10, 'autocomplete' => 'name' ) ),
			'billing_phone'      => array_merge( $fields['billing']['billing_phone'], array( 'label' => YM_Country::copy( 'phone_label' ), 'required' => true, 'placeholder' => $rules['phone_example'], 'class' => array( 'form-row-wide' ), 'priority' => 20, 'custom_attributes' => array( 'dir' => 'ltr', 'inputmode' => 'tel' ) ) ),
			'ym_phone2'          => array( 'type' => 'tel', 'label' => YM_Country::copy( 'phone2' ), 'description' => YM_Country::copy( 'phone2_help' ), 'required' => false, 'placeholder' => $rules['phone_example'], 'class' => array( 'form-row-wide' ), 'priority' => 30, 'custom_attributes' => array( 'dir' => 'ltr', 'inputmode' => 'tel' ) ),
			'billing_country'    => array_merge( $fields['billing']['billing_country'], array( 'priority' => 35, 'class' => array( 'form-row-wide', 'ym-hidden' ) ) ),
			'billing_state'      => array_merge( $fields['billing']['billing_state'], array( 'label' => YM_Country::copy( 'gov_label' ), 'placeholder' => YM_Country::copy( 'gov_ph' ), 'required' => true, 'class' => array( 'form-row-wide', 'update_totals_on_change' ), 'priority' => 40 ) ),
			'billing_address_1'  => array_merge( $fields['billing']['billing_address_1'], array( 'label' => YM_Country::copy( 'area_label' ), 'placeholder' => '', 'class' => array( 'form-row-wide' ), 'priority' => 50 ) ),
			'billing_address_2'  => array_merge( $fields['billing']['billing_address_2'], array( 'label' => YM_Country::copy( 'building_label' ), 'label_class' => array(), 'placeholder' => '', 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 60 ) ),
			'ym_landmark'        => array( 'type' => 'text', 'label' => YM_Country::copy( 'landmark_label' ), 'description' => YM_Country::copy( 'landmark_help' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 70 ),
		);
		// Email: optional and only offered when receipts are actually sent (transactional mail switched on). It is a
		// receipt address, not a marketing consent; none is ever made up for a phone-only order.
		if ( class_exists( 'YM_Mail' ) && YM_Mail::enabled( 'transactional' ) ) {
			$billing['billing_email'] = array_merge( $fields['billing']['billing_email'] ?? array( 'type' => 'email', 'validate' => array( 'email' ) ), array( 'label' => YM_Country::copy( 'email_label' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 80, 'autocomplete' => 'email', 'custom_attributes' => array( 'dir' => 'ltr' ) ) );
		}
		$fields['billing'] = $billing;
		$fields['shipping'] = array();
		unset( $fields['account'], $fields['order']['order_comments'] );
		return $fields;
	}

	/**
	 * WooCommerce's address script re-applies labels, required flags and order from the locale on every
	 * country change, and the locale starts from these defaults. Setting them here keeps the browser and
	 * the server showing the same form.
	 */
	public static function address_fields( $fields ) {
		$set = array(
			'first_name' => array( 'label' => YM_Country::copy( 'name_label' ), 'required' => true, 'priority' => 10 ),
			'phone'      => array( 'label' => YM_Country::copy( 'phone_label' ), 'required' => true, 'priority' => 20 ),
			'country'    => array( 'priority' => 35 ),
			'state'      => array( 'label' => YM_Country::copy( 'gov_label' ), 'placeholder' => YM_Country::copy( 'gov_ph' ), 'required' => true, 'priority' => 40 ),
			'address_1'  => array( 'label' => YM_Country::copy( 'area_label' ), 'placeholder' => '', 'required' => true, 'priority' => 50 ),
			'address_2'  => array( 'label' => YM_Country::copy( 'building_label' ), 'label_class' => array(), 'placeholder' => '', 'required' => false, 'priority' => 60 ),
		);
		foreach ( $set as $key => $values ) {
			$fields[ $key ] = array_merge( $fields[ $key ] ?? array(), $values );
		}
		return $fields;
	}

	public static function shipping_prompt() {
		return esc_html( YM_Country::copy( 'pick_gov' ) );
	}

	public static function locale( $locale ) {
		foreach ( YM_Country::codes() as $cc ) {
			$locale[ $cc ]['postcode']  = array( 'required' => false, 'hidden' => true );
			$locale[ $cc ]['city']      = array( 'required' => false, 'hidden' => true );
			$locale[ $cc ]['state']     = array( 'required' => true, 'label' => YM_Country::copy( 'gov_label' ) );
			$locale[ $cc ]['last_name'] = array( 'required' => false, 'hidden' => true );
			$locale[ $cc ]['company']   = array( 'required' => false, 'hidden' => true );
		}
		return $locale;
	}

	public static function cod_only( $gateways ) {
		return isset( $gateways['cod'] ) ? array( 'cod' => $gateways['cod'] ) : array();
	}

	public static function normalise_phone( $value ) {
		return YM_Country::normalise_phone( $value );
	}

	public static function token_field() {
		echo '<input type="hidden" name="ym_token" value="' . esc_attr( wp_generate_uuid4() ) . '">';
	}

	/**
	 * Server-side rules on top of WooCommerce's own validation. The token is claimed last and atomically
	 * (add_option fails if the row exists), so a double click or a retried request cannot create a second order.
	 */
	public static function validate( $data, WP_Error $errors ) {
		// WooCommerce's own messages for these fields are generic ("Billing … is a required field") and repeat ours.
		foreach ( $errors->get_error_codes() as $code ) {
			if ( 'shipping' === $code || preg_match( '/^billing_(first_name|phone|state|address_1)_(required|validation)$/', $code ) ) {
				$errors->remove( $code );
			}
		}
		$cc = YM_Country::code();
		if ( ( $data['billing_country'] ?? '' ) !== $cc ) {
			$errors->add( 'ym_country', YM_Country::copy( 'wrong_country' ), array( 'id' => 'billing_country' ) );
		}
		$name = trim( (string) ( $data['billing_first_name'] ?? '' ) );
		if ( count( preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) ) < 2 ) {
			$errors->add( 'ym_name', YM_Country::copy( 'name_err' ), array( 'id' => 'billing_first_name' ) );
		}
		if ( ! YM_Country::valid_phone( $data['billing_phone'] ?? '' ) ) {
			$errors->add( 'ym_phone', YM_Country::copy( 'phone_err' ), array( 'id' => 'billing_phone' ) );
		}
		if ( ! empty( $data['ym_phone2'] ) && ! YM_Country::valid_phone( $data['ym_phone2'] ) ) {
			$errors->add( 'ym_phone2', YM_Country::copy( 'phone2_err' ) . YM_Country::copy( 'phone_err' ), array( 'id' => 'ym_phone2' ) );
		}
		if ( null === YM_Country::fee( $data['billing_state'] ?? '' ) ) {
			$errors->add( 'ym_state', YM_Country::copy( 'gov_err' ), array( 'id' => 'billing_state' ) );
		}
		if ( mb_strlen( trim( (string) ( $data['billing_address_1'] ?? '' ) ) ) < 3 ) {
			$errors->add( 'ym_address', YM_Country::copy( 'area_err' ), array( 'id' => 'billing_address_1' ) );
		}
		if ( $errors->has_errors() ) {
			return;
		}
		/** Anti-abuse checks (YM_Risk): only submissions that passed field validation reach them. */
		do_action( 'ym_checkout_before_claim', $data, $errors );
		if ( $errors->has_errors() ) {
			return;
		}
		self::claim_token( $errors );
	}

	/*
	 * Idempotent submission. Each checkout form carries a one-time token (UUID).
	 *
	 * Claim: a plain conditional INSERT on the options table's unique key. (WordPress's add_option() is NOT usable
	 * here: it runs INSERT … ON DUPLICATE KEY UPDATE, so two simultaneous callers both "succeed" and both went on to
	 * create an order — CI run 40748106500.) The claiming request also takes a database lock named after the token
	 * and holds it until the request ends. The database frees that lock by itself when the request's connection
	 * closes, including when PHP crashes or is killed, so "is the first request still alive?" is answered by the
	 * lock, never by a clock.
	 *   - Same token again after the order exists (double click, retry after a lost response): no new order; the
	 *     customer is sent to the existing order's confirmation page.
	 *   - Same token while the first request is still running, however long it takes: "still sending, wait".
	 *   - The first request failed before an order existed: the claim is released at the end of that request, so
	 *     a retry goes through at once.
	 *   - The first request died: the lock is free. If an order carrying the token exists (death after the order was
	 *     saved), the retry is sent to it; otherwise the retry takes the lock and the claim over and continues.
	 */
	private static $claimed = '';

	private static function lock_name( $name ) {
		global $wpdb;
		return 'ym' . substr( md5( $wpdb->dbname . $wpdb->prefix . $name ), 0, 30 );
	}

	/** Take the token's lock without waiting. True only for the one connection that holds it. */
	private static function take_lock( $name ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function drop_lock( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Insert the claim row only if it does not exist. One row inserted = this request owns the token. */
	private static function insert_claim( $name, $value ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $done;
	}

	/** An order already saved with this token (the request that created it may have died before linking it). */
	private static function order_for_token( $name ) {
		$ids = YM_Orders::ids_by_meta( '_ym_token', substr( $name, strlen( self::TOKEN_PREFIX ) ), time() - 2 * DAY_IN_SECONDS, 1 );
		return $ids ? wc_get_order( (int) $ids[0] ) : null;
	}

	private static function token_from_post() {
		$t = isset( $_POST['ym_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ym_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce before this hook.
		return wp_is_uuid( $t ) ? $t : '';
	}

	private static function session_hash() {
		$id = WC()->session ? (string) WC()->session->get_customer_id() : '';
		return substr( hash( 'sha256', $id . wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function read_claim( $name ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $raw ? null : array( 'raw' => $raw, 'v' => (array) json_decode( $raw, true ) );
	}

	private static function claim_token( WP_Error $errors ) {
		global $wpdb;
		$token = self::token_from_post();
		if ( ! $token ) {
			$errors->add( 'ym_token', YM_Country::copy( 'refresh' ) );
			return;
		}
		$delay = (int) YM_Env::test_header( 'delay' );
		if ( $delay > 0 ) {
			usleep( min( $delay, 5000 ) * 1000 );
		}
		// Local test hook: release parallel requests at the same instant (epoch ms) so they race on the claim.
		$at = (int) YM_Env::test_header( 'claim-at' );
		if ( $at > 0 ) {
			$wait = $at - (int) floor( microtime( true ) * 1000 );
			if ( $wait > 0 && $wait < 10000 ) {
				usleep( $wait * 1000 );
			}
		}
		$name = self::TOKEN_PREFIX . md5( $token );
		$mine = wp_json_encode( array( 't' => time(), 's' => self::session_hash(), 'o' => 0, 'r' => wp_generate_password( 8, false ) ) );
		// Lock first, then insert: whoever holds the lock is the only request that may create the order.
		$locked = self::take_lock( $name );
		if ( $locked && self::insert_claim( $name, $mine ) ) {
			self::own( $name, $mine );
			return;
		}
		if ( ! $locked && ! self::read_claim( $name ) ) {
			// Lock held elsewhere and its row not written yet: wait a moment for it before answering.
			usleep( 200000 );
		}
		$claim = self::read_claim( $name );
		$v     = $claim ? $claim['v'] : array();
		if ( ! $claim ) {
			// No row and no lock: another request holds the lock and is about to insert. Treat as in flight.
			if ( $locked ) {
				self::drop_lock( $name );
			}
			$errors->add( 'ym_in_flight', YM_Country::copy( 'in_flight' ) );
			return;
		}
		if ( ( $v['s'] ?? '' ) !== self::session_hash() ) {
			if ( $locked ) {
				self::drop_lock( $name );
			}
			$errors->add( 'ym_duplicate_submit', YM_Country::copy( 'dup' ) );
			return;
		}
		$order = ! empty( $v['o'] ) ? wc_get_order( (int) $v['o'] ) : null;
		if ( ! $order && $locked ) {
			// The lock is ours, so the request that claimed this token is no longer running. If it got as far as
			// saving the order, that order carries the token.
			$order = self::order_for_token( $name );
		}
		if ( $order ) {
			if ( $locked ) {
				self::drop_lock( $name );
			}
			self::send_to_order( $order );
			$errors->add( 'ym_duplicate_submit', YM_Country::copy( 'dup' ) );
			return;
		}
		if ( ! $locked ) {
			// The first request is still running (slow supplier hook, slow database): never a second order.
			$errors->add( 'ym_in_flight', YM_Country::copy( 'in_flight' ) );
			return;
		}
		// The first request died before saving an order. We hold the lock, so nobody else can be doing this.
		$wpdb->update( $wpdb->options, array( 'option_value' => $mine ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::own( $name, $mine );
	}

	private static function own( $name, $mine ) {
		self::$claimed = $name . '|' . $mine;
		register_shutdown_function( array( __CLASS__, 'release_unlinked' ) );
	}

	/**
	 * A retry after the order was already created arrives with an empty cart, which WooCommerce rejects before
	 * validation runs. Catch it first and send the customer to her order.
	 */
	public static function early_replay() {
		$token = self::token_from_post();
		if ( ! $token ) {
			return;
		}
		$claim = self::read_claim( self::TOKEN_PREFIX . md5( $token ) );
		$v     = $claim ? $claim['v'] : array();
		if ( ! empty( $v['o'] ) && ( $v['s'] ?? '' ) === self::session_hash() ) {
			$order = wc_get_order( (int) $v['o'] );
			if ( $order ) {
				self::send_to_order( $order );
			}
		}
	}

	/** Attach the new order to this request's claim (woocommerce_checkout_order_created). */
	public static function link_order( $order ) {
		global $wpdb;
		if ( ! self::$claimed || ! $order instanceof WC_Order ) {
			return;
		}
		// Local test hook: the process dies after the order is saved, before the claim is linked and before any
		// response is sent (X-YM-Test-Fail: after-create). Shutdown handlers still run, as after a fatal error.
		if ( 'after-create' === YM_Env::test_header( 'fail' ) ) {
			exit;
		}
		list( $name, $mine ) = explode( '|', self::$claimed, 2 );
		self::link_claim( $name, $mine, $order->get_id() );
		self::drop_lock( $name );
		self::$claimed = '';
	}

	private static function link_claim( $name, $mine, $order_id ) {
		global $wpdb;
		$v      = json_decode( $mine, true );
		$v['o'] = (int) $order_id;
		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $v ) ), array( 'option_name' => $name, 'option_value' => $mine ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** End of request: a claim that never got an order is released so the customer can try again at once. */
	public static function release_unlinked() {
		global $wpdb;
		if ( ! self::$claimed ) {
			return;
		}
		list( $name, $mine ) = explode( '|', self::$claimed, 2 );
		// If the order was saved before the request failed, keep the claim and point it at that order.
		$order = self::order_for_token( $name );
		if ( $order ) {
			self::link_claim( $name, $mine, $order->get_id() );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $mine ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::drop_lock( $name );
		self::$claimed = '';
	}

	/** The token travels inside the order itself, written in the same save that creates the order. */
	private static function claimed_token() {
		return self::$claimed ? substr( strtok( self::$claimed, '|' ), strlen( self::TOKEN_PREFIX ) ) : '';
	}

	private static function send_to_order( WC_Order $order ) {
		$url = $order->get_checkout_order_received_url();
		if ( wp_doing_ajax() || ! empty( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json( array( 'result' => 'success', 'redirect' => $url, 'ym_replay' => 1 ) );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/** Local test hook: make order creation fail once (X-YM-Test-Fail: create). */
	public static function test_fail( $order ) {
		// Local test hook: order creation is slow (X-YM-Test-Hold: seconds, up to 60), e.g. a stuck supplier hook.
		$hold = (int) YM_Env::test_header( 'hold' );
		if ( $hold > 0 ) {
			sleep( min( $hold, 60 ) );
		}
		if ( 'create' === YM_Env::test_header( 'fail' ) ) {
			throw new Exception( 'ym test: order creation failed' );
		}
	}

	/** Order details (address, phone) are shown only to the browser session that placed the order. */
	public static function guard_received() {
		if ( ! is_wc_endpoint_url( 'order-received' ) || current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		global $wp;
		$order = wc_get_order( absint( $wp->query_vars['order-received'] ?? 0 ) );
		if ( $order && $order->get_meta( '_ym_session' ) !== self::session_hash() ) {
			remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
		}
	}

	public static function save_extra( WC_Order $order, $data ) {
		$order->update_meta_data( '_ym_session', self::session_hash() );
		if ( self::claimed_token() ) {
			$order->update_meta_data( '_ym_token', self::claimed_token() );
		}
		foreach ( array( 'ym_phone2', 'ym_landmark' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$order->update_meta_data( '_' . $k, sanitize_text_field( $data[ $k ] ) );
			}
		}
	}

	public static function thanks( $text, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $text;
		}
		return sprintf( YM_Country::copy( 'thanks_line' ), YM_Country::copy( 'thanks' ), $order->get_order_number() ) . ' ' . YM_Country::copy( 'confirm_call' );
	}

	/**
	 * What happens next, in order. A COD order request is not confirmed and not paid on this page: the steps say so
	 * (no "payment received", no "confirmed by the supplier").
	 */
	public static function next_steps( $order_id ) {
		$steps = array_filter( explode( '|', YM_Country::copy( 'thanks_steps' ) ) );
		if ( ! $order_id || ! $steps ) {
			return;
		}
		echo '<section class="ym-next" data-ym-next><h2>' . esc_html( YM_Country::copy( 'thanks_next' ) ) . '</h2><ol>';
		foreach ( $steps as $st ) {
			echo '<li>' . esc_html( $st ) . '</li>';
		}
		echo '</ol></section>';
	}

	public static function cart_delivery_line( $html = '' ) {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return $html; // checkout keeps its own prompt: pick the governorate to see the fee
		}
		$fees = array_unique( array_map( fn( $g ) => (float) $g[1], YM_Country::rules()['governorates'] ) );
		$txt  = count( $fees ) > 1 ? sprintf( YM_Country::copy( 'fee_range' ), YM_Country::number( min( $fees ) ), YM_Country::money( max( $fees ) ) ) : sprintf( YM_Country::copy( 'fee_flat' ), YM_Country::money( reset( $fees ) ) );
		return esc_html( $txt . '. ' . YM_Country::copy( 'one_fee' ) );
	}

	/** True on checkout while the delivery fee is not known yet (no governorate, so no shipping rate). */
	public static function delivery_unresolved() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! is_checkout() || ! WC()->cart->needs_shipping() ) {
			return false;
		}
		$packages = WC()->shipping()->get_packages();
		if ( ! $packages ) {
			return true;
		}
		foreach ( $packages as $pkg ) {
			if ( empty( $pkg['rates'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function order_total_html( $html ) {
		if ( ! self::delivery_unresolved() ) {
			return $html;
		}
		return '<span data-ym-total-pending>' . wc_price( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() ) . '</span> <small class="ym-total-note">' . esc_html( YM_Country::copy( 'delivery_after_gov' ) ) . '</small>';
	}

	/** A few WooCommerce strings that read wrong for a delivery-only shop. */
	public static function strings( $translation, $text, $domain ) {
		if ( 'default' === $domain && 'Skip to content' === $text ) {
			return YM_Country::copy( 'skip_link' ); // core's wording addresses a man; a neutral noun instead
		}
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}
		// Customer-facing WooCommerce strings found by the rendered-page inventory (docs/language/rendered-<cc>.csv).
		$map = array(
			'Cart totals'                        => YM_Country::copy( 'cart_totals' ),
			'Proceed to checkout'                => YM_Country::copy( 'to_checkout' ),
			'The following problems were found:' => YM_Country::copy( 'fix_these' ),
			'Select an option&hellip;'           => YM_Country::copy( 'gov_ph' ),
			'Billing address'  => YM_Country::copy( 'delivery_address' ),
			'Shipping'         => YM_Country::copy( 'delivery_word' ),
			'Shipping:'        => YM_Country::copy( 'delivery_word' ) . ':',
			'Order details'    => YM_Country::copy( 'order_details' ),
			'Total'            => YM_Country::copy( 'pay_total' ),
		);
		if ( 'Total' === $text && self::delivery_unresolved() ) {
			return YM_Country::copy( 'subtotal_label' );
		}
		return $map[ $text ] ?? $translation;
	}

	public static function cleanup_tokens() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::TOKEN_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$v = json_decode( $r->option_value, true );
			if ( (int) ( $v['t'] ?? 0 ) < time() - 2 * DAY_IN_SECONDS ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $r->option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}
}
