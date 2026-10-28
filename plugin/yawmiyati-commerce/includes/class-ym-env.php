<?php
/**
 * Environment helpers and local-only test hooks.
 *
 * Environment type comes from WordPress (WP_ENVIRONMENT_TYPE): local, development, staging or production.
 * The catalog gate (YM_Catalog) is enforced on staging and production; local and development stores may sell
 * unverified test products so the order flow can be tested.
 *
 * Test hooks exist only when the store is "local" AND wp-config defines YM_TEST_HOOKS as true (dev/setup.sh
 * does this). They let the end-to-end tests delay a checkout request or make order creation fail once.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Env {

	public static function type() {
		$type = wp_get_environment_type();
		// Local test hook: render one request as if it were another environment (SEO and gate tests).
		$as = self::test_header( 'env' );
		if ( in_array( $as, array( 'staging', 'production' ), true ) ) {
			$type = $as;
		}
		return (string) apply_filters( 'ym_environment_type', $type );
	}

	public static function is_production() {
		return 'production' === self::type();
	}

	/** Catalog gate and publishing rules apply here. */
	public static function enforced() {
		return in_array( self::type(), array( 'staging', 'production' ), true );
	}

	public static function test_hooks() {
		return 'local' === wp_get_environment_type() && defined( 'YM_TEST_HOOKS' ) && YM_TEST_HOOKS;
	}

	/** Value of a test header (X-YM-Test-<name>) when test hooks are on, else ''. */
	public static function test_header( $name ) {
		if ( ! self::test_hooks() ) {
			return '';
		}
		$key = 'HTTP_X_YM_TEST_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/** Country-prefixed ID used for analytics and ad platforms, e.g. EG-123. */
	public static function transaction_id( WC_Order $order ) {
		return YM_Country::code() . '-' . $order->get_id();
	}
}
