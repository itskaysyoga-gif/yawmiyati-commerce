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
		add_filter( 'gettext', array( __CLASS__, 'strings' ), 10, 3 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thanks' ), 10, 2 );
		add_action( 'ym_cleanup_tokens', array( __CLASS__, 'cleanup_tokens' ) );
		if ( ! wp_next_scheduled( 'ym_cleanup_tokens' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ym_cleanup_tokens' );
		}
	}

	public static function fields( $fields ) {
		$rules   = YM_Country::rules();
		$billing = array(
			'billing_first_name' => array_merge( $fields['billing']['billing_first_name'], array( 'label' => 'الاسم بالكامل', 'placeholder' => 'الاسم الأول والأخير', 'class' => array( 'form-row-wide' ), 'priority' => 10, 'autocomplete' => 'name' ) ),
			'billing_phone'      => array_merge( $fields['billing']['billing_phone'], array( 'label' => 'رقم الموبايل', 'required' => true, 'placeholder' => $rules['phone_example'], 'class' => array( 'form-row-wide' ), 'priority' => 20, 'custom_attributes' => array( 'dir' => 'ltr', 'inputmode' => 'tel' ) ) ),
			'ym_phone2'          => array( 'type' => 'tel', 'label' => 'رقم تاني', 'description' => 'بيساعد إن الطلب يوصل لو الأول مقفول', 'required' => false, 'placeholder' => $rules['phone_example'], 'class' => array( 'form-row-wide' ), 'priority' => 30, 'custom_attributes' => array( 'dir' => 'ltr', 'inputmode' => 'tel' ) ),
			'billing_country'    => array_merge( $fields['billing']['billing_country'], array( 'priority' => 35, 'class' => array( 'form-row-wide', 'ym-hidden' ) ) ),
			'billing_state'      => array_merge( $fields['billing']['billing_state'], array( 'label' => 'المحافظة', 'placeholder' => 'اختاري المحافظة', 'required' => true, 'class' => array( 'form-row-wide', 'update_totals_on_change' ), 'priority' => 40 ) ),
			'billing_address_1'  => array_merge( $fields['billing']['billing_address_1'], array( 'label' => 'المنطقة والشارع', 'placeholder' => '', 'class' => array( 'form-row-wide' ), 'priority' => 50 ) ),
			'billing_address_2'  => array_merge( $fields['billing']['billing_address_2'], array( 'label' => 'العمارة والدور والشقة', 'label_class' => array(), 'placeholder' => '', 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 60 ) ),
			'ym_landmark'        => array( 'type' => 'text', 'label' => 'أقرب علامة مميزة', 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 70 ),
		);
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
			'first_name' => array( 'label' => 'الاسم بالكامل', 'required' => true, 'priority' => 10 ),
			'phone'      => array( 'label' => 'رقم الموبايل', 'required' => true, 'priority' => 20 ),
			'country'    => array( 'priority' => 35 ),
			'state'      => array( 'label' => 'المحافظة', 'placeholder' => 'اختاري المحافظة', 'required' => true, 'priority' => 40 ),
			'address_1'  => array( 'label' => 'المنطقة والشارع', 'placeholder' => '', 'required' => true, 'priority' => 50 ),
			'address_2'  => array( 'label' => 'العمارة والدور والشقة', 'label_class' => array(), 'placeholder' => '', 'required' => false, 'priority' => 60 ),
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
		foreach ( array_keys( YM_Country::RULES ) as $cc ) {
			$locale[ $cc ]['postcode']  = array( 'required' => false, 'hidden' => true );
			$locale[ $cc ]['city']      = array( 'required' => false, 'hidden' => true );
			$locale[ $cc ]['state']     = array( 'required' => true, 'label' => 'المحافظة' );
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
			$errors->add( 'ym_country', 'التوصيل متاح للدولة دي بس.', array( 'id' => 'billing_country' ) );
		}
		$name = trim( (string) ( $data['billing_first_name'] ?? '' ) );
		if ( count( preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) ) < 2 ) {
			$errors->add( 'ym_name', 'اكتبي الاسم الأول والأخير.', array( 'id' => 'billing_first_name' ) );
		}
		if ( ! YM_Country::valid_phone( $data['billing_phone'] ?? '' ) ) {
			$errors->add( 'ym_phone', YM_Country::rules()['phone_hint'], array( 'id' => 'billing_phone' ) );
		}
		if ( ! empty( $data['ym_phone2'] ) && ! YM_Country::valid_phone( $data['ym_phone2'] ) ) {
			$errors->add( 'ym_phone2', 'الرقم التاني: ' . YM_Country::rules()['phone_hint'], array( 'id' => 'ym_phone2' ) );
		}
		if ( null === YM_Country::fee( $data['billing_state'] ?? '' ) ) {
			$errors->add( 'ym_state', 'اختاري المحافظة.', array( 'id' => 'billing_state' ) );
		}
		if ( mb_strlen( trim( (string) ( $data['billing_address_1'] ?? '' ) ) ) < 3 ) {
			$errors->add( 'ym_address', 'اكتبي المنطقة واسم الشارع.', array( 'id' => 'billing_address_1' ) );
		}
		if ( $errors->has_errors() ) {
			return;
		}
		$token = isset( $_POST['ym_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ym_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce before this hook.
		if ( ! wp_is_uuid( $token ) ) {
			$errors->add( 'ym_token', 'حدّثي الصفحة وجرّبي تاني.' );
			return;
		}
		if ( ! add_option( self::TOKEN_PREFIX . md5( $token ), time(), '', false ) ) {
			$errors->add( 'ym_duplicate_submit', 'الطلب ده اتبعت خلاص. لو عايزة تطلبي تاني، حدّثي الصفحة.' );
		}
	}

	public static function save_extra( WC_Order $order, $data ) {
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
		return sprintf( '%s، رقمه %s. %s', YM_Country::copy( 'thanks' ), $order->get_order_number(), YM_Country::copy( 'confirm_call' ) );
	}

	/** A few WooCommerce strings that read wrong for a delivery-only shop. */
	public static function strings( $translation, $text, $domain ) {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}
		$map = array(
			'Billing address'  => 'عنوان التوصيل',
			'Order details'    => 'تفاصيل الطلب',
		);
		return $map[ $text ] ?? $translation;
	}

	public static function cleanup_tokens() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( self::TOKEN_PREFIX ) . '%', time() - 2 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
