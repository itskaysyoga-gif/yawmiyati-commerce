<?php
/**
 * Country rules for this install. Each WordPress install serves one country (option ym_country).
 * Figures come from Taager's published shipping policy (captured Sept 2026) and Taager support (28 Oct 2026);
 * see docs/COUNTRY-RULES.md. Change them there first.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Country {

	/** All five countries, from config/countries.php (shared by every store). */
	public static function all() {
		static $all = null;
		if ( null === $all ) {
			$all = require YM_DIR . '/config/countries.php';
		}
		return $all;
	}

	public static function codes() {
		return array_keys( self::all() );
	}

	public static function init() {
		add_filter( 'woocommerce_states', array( __CLASS__, 'states' ) );
		add_filter( 'woocommerce_currency_symbol', array( __CLASS__, 'symbol' ), 10, 2 );
		// 3.000 OMR shows as 3 and 28.00 SAR as 28; 2.500 OMR keeps its decimals.
		add_filter( 'woocommerce_price_trim_zeros', '__return_true' );
	}

	public static function symbol( $symbol, $currency ) {
		foreach ( self::all() as $r ) {
			if ( $r['currency'] === $currency ) {
				return $r['symbol'];
			}
		}
		return $symbol;
	}

	public static function code() {
		$c = strtoupper( (string) get_option( 'ym_country', 'EG' ) );
		return isset( self::all()[ $c ] ) ? $c : 'EG';
	}

	public static function rules( $country = null ) {
		$all = self::all();
		return $all[ $country ? strtoupper( $country ) : self::code() ];
	}

	/**
	 * Customer-facing text in this country's dialect, from copy/<cc>.json (Egyptian Arabic for EG, Iraqi Arabic
	 * for IQ). Voice rules: docs/voice/. Review status: docs/LANGUAGE-REVIEW.md.
	 */
	public static function copy( $key, $country = null ) {
		$all = self::copy_all( $country );
		return (string) ( $all[ $key ] ?? '' );
	}

	public static function copy_all( $country = null ) {
		static $cache = array();
		$cc = $country ? $country : self::code();
		if ( ! isset( $cache[ $cc ] ) ) {
			// Saudi, UAE and Oman share copy/gulf.json; copy/<cc>.json overrides what differs by country.
			$voice = strtolower( (string) ( self::rules( $cc )['voice'] ?? strtolower( $cc ) ) );
			$base  = array();
			if ( strtolower( $cc ) !== $voice && is_readable( YM_DIR . '/copy/' . $voice . '.json' ) ) {
				$base = (array) json_decode( (string) file_get_contents( YM_DIR . '/copy/' . $voice . '.json' ), true );
			}
			$own = is_readable( YM_DIR . '/copy/' . strtolower( $cc ) . '.json' ) ? (array) json_decode( (string) file_get_contents( YM_DIR . '/copy/' . strtolower( $cc ) . '.json' ), true ) : array();
			$cache[ $cc ] = array_merge( $base, $own );
			unset( $cache[ $cc ]['_status'] );
		}
		return $cache[ $cc ];
	}

	/** Arabic governorate names as WooCommerce states for this country. */
	public static function states( $states ) {
		foreach ( self::all() as $cc => $rules ) {
			$states[ $cc ] = array();
			foreach ( $rules['governorates'] as $code => $g ) {
				$states[ $cc ][ $code ] = $g[0];
			}
		}
		return $states;
	}

	/** Delivery fee for a governorate code, or null when unknown. */
	public static function fee( $state, $country = null ) {
		$g = self::rules( $country )['governorates'][ $state ] ?? null;
		return $g ? (float) $g[1] : null;
	}

	/**
	 * Normalise a phone number to national format for the given country, before validation and duplicate checks:
	 * Arabic-Indic (٠-٩) and Persian (۰-۹) digits become 0-9; spaces, dashes, dots and brackets go; +CC / 00CC / CC
	 * prefixes become the national trunk prefix; a mobile number typed without its trunk 0 gets it back.
	 */
	public static function normalise_phone( $raw, $country = null ) {
		$r = self::rules( $country );
		$p = strtr( (string) $raw, array( '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		$p = preg_replace( '/[^0-9+]/', '', $p );
		$dial = $r['dial'];
		if ( preg_match( '/^(?:\+|00)' . $dial . '(\d+)$/', $p, $m ) ) {
			$p = $r['trunk'] . ltrim( $m[1], '0' );
		} elseif ( 0 === strpos( $p, $dial ) && ! preg_match( $r['phone_regex'], $p ) && preg_match( $r['phone_regex'], $r['trunk'] . substr( $p, strlen( $dial ) ) ) ) {
			$p = $r['trunk'] . substr( $p, strlen( $dial ) ); // international digits typed without + (e.g. 9647701234567)
		}
		if ( '' !== $r['trunk'] && ! preg_match( $r['phone_regex'], $p ) && preg_match( $r['phone_regex'], $r['trunk'] . $p ) ) {
			$p = $r['trunk'] . $p; // trunk 0 left off (e.g. 1012345678)
		}
		return $p;
	}

	public static function valid_phone( $raw, $country = null ) {
		return (bool) preg_match( self::rules( $country )['phone_regex'], self::normalise_phone( $raw, $country ) );
	}

	/** International digits for ad-platform matching: 01012345678 → 201012345678; 91234567 (OM) → 96891234567. */
	public static function intl_phone( $raw, $country = null ) {
		$r = self::rules( $country );
		$p = self::normalise_phone( $raw, $country );
		if ( '' !== $r['trunk'] && 0 === strpos( $p, $r['trunk'] ) ) {
			$p = substr( $p, strlen( $r['trunk'] ) );
		}
		return $r['dial'] . $p;
	}

	/** Minor units of this country's currency (0 EGP/IQD, 2 SAR/AED, 3 OMR). */
	public static function decimals( $country = null ) {
		return (int) self::rules( $country )['decimals'];
	}

	/** Round a money amount the way this country's currency is held. */
	public static function round_money( $amount, $country = null ) {
		return round( (float) $amount, self::decimals( $country ) );
	}

	/** Round a selling price to the country's price step (e.g. 0.100 OMR, 250 IQD), never below the amount. */
	public static function price_step( $amount, $country = null ) {
		$step = (float) self::rules( $country )['price_step'];
		return self::round_money( ceil( round( (float) $amount / $step, 6 ) ) * $step, $country );
	}

	/** Same digits and separators as WooCommerce's own prices; trailing zeros trimmed (3.000 → 3, 2.500 stays). */
	public static function number( $amount, $country = null ) {
		$sep = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
		$d   = self::decimals( $country );
		$out = number_format( self::round_money( $amount, $country ), $d, '.', $sep );
		if ( $d > 0 && preg_match( '/\.0+$/', $out ) ) {
			$out = preg_replace( '/\.0+$/', '', $out );
		}
		return $out;
	}

	/** Home URL of each country store, from option ym_store_urls (set by `wp ym setup --store-urls=...`). */
	public static function store_urls() {
		$urls = (array) get_option( 'ym_store_urls', array() );
		$out  = array();
		foreach ( self::codes() as $cc ) {
			$out[ $cc ] = $urls[ $cc ] ?? ( self::code() === $cc ? home_url( '/' ) : '' );
		}
		return array_filter( $out );
	}

	public static function money( $amount, $country = null ) {
		return self::number( $amount, $country ) . ' ' . self::rules( $country )['symbol'];
	}
}
