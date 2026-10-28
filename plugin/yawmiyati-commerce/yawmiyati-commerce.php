<?php
/**
 * Plugin Name: Yawmiyati Commerce
 * Description: Yawmiyati COD store logic: country rules, offer groups, one-screen COD checkout, delivery fees by governorate, order snapshots, duplicate review and supplier hand-off checks.
 * Version: 0.3.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 11.0
 * Text Domain: yawmiyati
 * License: Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'YM_VERSION', '0.3.0' );
define( 'YM_POLICY_VERSION', '2026-10-28' );
define( 'YM_DIR', __DIR__ );
define( 'YM_URL', plugin_dir_url( __FILE__ ) );

require_once YM_DIR . '/includes/class-ym-env.php';
require_once YM_DIR . '/includes/class-ym-country.php';
require_once YM_DIR . '/includes/class-ym-markets.php';
require_once YM_DIR . '/includes/class-ym-lifecycle.php';
require_once YM_DIR . '/includes/class-ym-report.php';
require_once YM_DIR . '/includes/class-ym-catalog.php';
require_once YM_DIR . '/includes/class-ym-ops.php';
require_once YM_DIR . '/includes/class-ym-pages.php';
require_once YM_DIR . '/includes/class-ym-tracking.php';
require_once YM_DIR . '/includes/class-ym-seo.php';
require_once YM_DIR . '/includes/class-ym-offers.php';
require_once YM_DIR . '/includes/class-ym-checkout.php';
require_once YM_DIR . '/includes/class-ym-orders.php';
require_once YM_DIR . '/includes/class-ym-supplier.php';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	YM_Country::init();
	YM_Markets::init();
	YM_Lifecycle::init();
	YM_Offers::init();
	YM_Checkout::init();
	YM_Orders::init();
	YM_Supplier::init();
	YM_Catalog::init();
	YM_Ops::init();
	YM_Pages::init();
	YM_Tracking::init();
	YM_SEO::init();
	add_action( 'woocommerce_shipping_init', function () {
		require_once YM_DIR . '/includes/class-ym-shipping.php';
	} );
	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['ym_governorate'] = 'YM_Shipping';
		return $methods;
	} );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once YM_DIR . '/includes/class-ym-cli.php';
		WP_CLI::add_command( 'ym', 'YM_CLI' );
	}
} );
