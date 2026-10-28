<?php
/**
 * Market readiness gates. Spec: docs/MARKETS.md.
 *
 * Four separate controls per country, kept in config/markets.json (versioned, so every change has an author, a
 * date and a reason in git):
 *   prepared  store configured (currency, phones, regions, copy, policies, tracking config)
 *   catalog   assortment approved; at least one offer passes the catalog gate
 *   checkout  orders accepted (verified supplier link and a test order in this country)
 *   ads       paid advertising allowed (live measurement checks passed in this country)
 *
 * A store can switch a gate OFF for itself in an emergency (option ym_market_off, `wp ym market off <gate>`), never
 * ON beyond the file. Gates are enforced on staging and production; local stores ignore them so the flow can be
 * tested, except when a test asks to see the store as staging or production.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Markets {

	const GATES = array( 'prepared', 'catalog', 'checkout', 'ads' );

	public static function init() {
		// Refuse at the first step of checkout, with our own message (marking products unpurchasable would make
		// WooCommerce silently empty carts with a generic notice).
		add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'before_checkout' ), 0 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 5, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_validation' ), 5 );
	}

	public static function config() {
		static $cfg = null;
		if ( null === $cfg ) {
			$cfg = (array) json_decode( (string) file_get_contents( YM_DIR . '/config/markets.json' ), true );
		}
		return $cfg;
	}

	/** Gate value from the versioned file, lowered by this store's local kill switch. */
	public static function is_on( $cc, $gate ) {
		$cc  = strtoupper( (string) $cc );
		$row = self::config()[ $cc ] ?? array();
		// Local test hook: X-YM-Test-Markets: catalog,checkout switches gates on for this store, for one request.
		$test = YM_Env::test_header( 'markets' );
		if ( $test && YM_Country::code() === $cc ) {
			foreach ( explode( ',', $test ) as $g ) {
				$row[ trim( $g ) ] = true;
			}
		}
		if ( empty( $row[ $gate ] ) ) {
			return false;
		}
		if ( YM_Country::code() === $cc && in_array( $gate, (array) get_option( 'ym_market_off', array() ), true ) ) {
			return false;
		}
		// A later gate never stands without the earlier ones.
		$i = array_search( $gate, self::GATES, true );
		return 0 === $i || ( 'ads' === $gate ? self::is_on( $cc, 'checkout' ) : self::is_on( $cc, self::GATES[ $i - 1 ] ) );
	}

	/** Whether this store takes orders right now. */
	public static function accepts_orders() {
		return ! YM_Env::enforced() || self::is_on( YM_Country::code(), 'checkout' );
	}

	/** Whether this store may be indexed (production, prepared, catalog approved). */
	public static function indexable() {
		return self::is_on( YM_Country::code(), 'catalog' );
	}

	/** Countries a customer may be sent to (switcher, domain-root chooser): taking orders, or this store itself. */
	public static function public_codes() {
		$out = array();
		foreach ( YM_Country::codes() as $cc ) {
			if ( $cc === YM_Country::code() || ! YM_Env::enforced() || self::is_on( $cc, 'checkout' ) ) {
				$out[] = $cc;
			}
		}
		return $out;
	}

	public static function before_checkout() {
		if ( ! self::accepts_orders() ) {
			throw new Exception( esc_html( YM_Country::copy( 'market_closed' ) ) );
		}
	}

	public static function add_validation( $passed ) {
		if ( $passed && ! self::accepts_orders() ) {
			wc_add_notice( YM_Country::copy( 'market_closed' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function validate( $data, WP_Error $errors ) {
		if ( ! self::accepts_orders() ) {
			$errors->add( 'ym_market_closed', YM_Country::copy( 'market_closed' ) );
		}
	}

	/** Matrix for reports and the readiness screen. */
	public static function matrix() {
		$out = array();
		foreach ( YM_Country::codes() as $cc ) {
			$row = self::config()[ $cc ] ?? array();
			foreach ( self::GATES as $g ) {
				$out[ $cc ][ $g ] = self::is_on( $cc, $g );
			}
			$out[ $cc ]['note']    = (string) ( $row['note'] ?? '' );
			$out[ $cc ]['changed'] = (string) ( $row['changed'] ?? '' );
		}
		return $out;
	}
}
