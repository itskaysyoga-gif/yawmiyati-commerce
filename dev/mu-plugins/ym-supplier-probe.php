<?php
/**
 * LOCAL ONLY (installed by dev/setup.sh into each local store's mu-plugins; never deployed).
 *
 * Stands in for a supplier plugin we cannot run locally. It listens on every hook a WooCommerce supplier/fulfilment
 * plugin commonly uses to pick up a new order and records the order's status at that moment, in order meta
 * _ym_probe. Tests use it to prove that an order we hold is already on-hold whenever any of these hooks fires, and
 * that "processing" only happens after a person releases it.
 *
 * It does NOT prove what Taager's plugin does: which hook it uses and whether it skips on-hold orders must be read
 * from its code and confirmed with a held test order on staging (docs/RISK.md §5).
 */

defined( 'ABSPATH' ) || exit;

$ym_probe = function ( $hook ) {
	return function ( $order_id ) use ( $hook ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? $order_id->get_id() : $order_id );
		if ( ! $order ) {
			return;
		}
		$key    = 'ym_probe_' . $order->get_id();
		$seen   = (array) get_option( $key, array() );
		$seen[] = array( 'hook' => $hook, 'status' => $order->get_status() );
		update_option( $key, $seen, false );
	};
};
foreach ( array( 'woocommerce_new_order', 'woocommerce_checkout_order_created', 'woocommerce_order_status_processing', 'woocommerce_payment_complete', 'woocommerce_thankyou' ) as $h ) {
	add_action( $h, $ym_probe( $h ), 999 );
}
add_action( 'woocommerce_checkout_order_processed', $ym_probe( 'woocommerce_checkout_order_processed@1' ), 1 );
add_action( 'woocommerce_checkout_order_processed', $ym_probe( 'woocommerce_checkout_order_processed@999' ), 999 );

/*
 * Local Turnstile stub: with option ym_turnstile_test = "stub", Cloudflare's siteverify is answered from the request
 * header X-YM-Test-Turnstile: pass | fail | duplicate | unreachable | wronghost | wrongaction | noaction (no network
 * in local stores). "pass" answers like Cloudflare does for this store's hostname and the checkout action.
 */
add_filter( 'ym_turnstile_siteverify', function ( $result ) {
	if ( 'stub' !== get_option( 'ym_turnstile_test' ) ) {
		return $result;
	}
	$h = isset( $_SERVER['HTTP_X_YM_TEST_TURNSTILE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_YM_TEST_TURNSTILE'] ) ) : 'pass';
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$map  = array(
		'pass'        => array( 'success' => true, 'action' => 'checkout', 'hostname' => $host ),
		'wronghost'   => array( 'success' => true, 'action' => 'checkout', 'hostname' => 'other-site.example' ),
		'wrongaction' => array( 'success' => true, 'action' => 'login', 'hostname' => $host ),
		'noaction'    => array( 'success' => true, 'hostname' => $host ),
		'fail'        => array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ),
		'duplicate'   => array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) ),
		'unreachable' => array( 'success' => false, 'error-codes' => array( 'unreachable' ) ),
	);
	return $map[ $h ] ?? $map['pass'];
} );
