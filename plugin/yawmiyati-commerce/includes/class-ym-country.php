<?php
/**
 * Country rules for this install. Each WordPress install serves one country (option ym_country).
 * Figures come from Taager's published shipping policy (captured Sept 2026) and Taager support (28 Oct 2026);
 * see docs/COUNTRY-RULES.md. Change them there first.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Country {

	const RULES = array(
		'EG' => array(
			'currency'     => 'EGP',
			'decimals'     => 0,
			'symbol'       => 'ج.م',
			'phone_regex'  => '/^01[0125][0-9]{8}$/',
			'phone_example'=> '01xxxxxxxxx',
			'refusal_fee'  => 5,
			'zones'        => array(
				array( 95, 'القاهرة والجيزة والإسكندرية', 'يومين شغل' ),
				array( 100, 'الدلتا والقناة', 'لحد 3 أيام' ),
				array( 105, 'الصعيد وسيناء والبحر الأحمر ومطروح', 'من 2 لـ 5 أيام' ),
			),
			// Governorate code => [Arabic name, delivery fee charged to the customer, delivery window].
			'governorates' => array(
				'EGC'   => array( 'القاهرة', 95, 'يومين شغل' ),
				'EGGZ'  => array( 'الجيزة', 95, 'يومين شغل' ),
				'EGALX' => array( 'الإسكندرية', 95, 'يومين شغل' ),
				'EGBH'  => array( 'البحيرة', 100, 'لحد 3 أيام' ),
				'EGDK'  => array( 'الدقهلية', 100, 'لحد 3 أيام' ),
				'EGSHR' => array( 'الشرقية', 100, 'لحد 3 أيام' ),
				'EGGH'  => array( 'الغربية', 100, 'لحد 3 أيام' ),
				'EGKB'  => array( 'القليوبية', 100, 'لحد 3 أيام' ),
				'EGMNF' => array( 'المنوفية', 100, 'لحد 3 أيام' ),
				'EGIS'  => array( 'الإسماعيلية', 100, 'لحد 3 أيام' ),
				'EGSUZ' => array( 'السويس', 100, 'لحد 3 أيام' ),
				'EGPTS' => array( 'بورسعيد', 100, 'لحد 3 أيام' ),
				'EGKFS' => array( 'كفر الشيخ', 100, 'لحد 3 أيام' ),
				'EGDT'  => array( 'دمياط', 100, 'لحد 3 أيام' ),
				'EGBNS' => array( 'بني سويف', 100, 'لحد 3 أيام' ),
				'EGFYM' => array( 'الفيوم', 105, 'من 2 لـ 5 أيام' ),
				'EGMN'  => array( 'المنيا', 105, 'من 2 لـ 5 أيام' ),
				'EGAST' => array( 'أسيوط', 105, 'من 2 لـ 5 أيام' ),
				'EGSHG' => array( 'سوهاج', 105, 'من 2 لـ 5 أيام' ),
				'EGKN'  => array( 'قنا', 105, 'من 2 لـ 5 أيام' ),
				'EGLX'  => array( 'الأقصر', 105, 'من 2 لـ 5 أيام' ),
				'EGASN' => array( 'أسوان', 105, 'من 2 لـ 5 أيام' ),
				'EGWAD' => array( 'الوادي الجديد', 105, 'من 2 لـ 5 أيام' ),
				'EGMT'  => array( 'مطروح', 105, 'من 2 لـ 5 أيام' ),
				'EGBA'  => array( 'البحر الأحمر', 105, 'من 2 لـ 5 أيام' ),
				'EGSIN' => array( 'شمال سيناء', 105, 'من 2 لـ 5 أيام' ),
				'EGJS'  => array( 'جنوب سيناء', 105, 'من 2 لـ 5 أيام' ),
			),
		),
		'IQ' => array(
			'currency'     => 'IQD',
			'decimals'     => 0,
			'symbol'       => 'د.ع',
			'phone_regex'  => '/^07[0-9]{9}$/',
			'phone_example'=> '07xxxxxxxxx',
			'refusal_fee'  => 0,
			'zones'        => array(
				array( 7000, 'كل المحافظات', '' ),
			),
			'governorates' => array(
				'BG' => array( 'بغداد', 7000, '' ),
				'BA' => array( 'البصرة', 7000, '' ),
				'NI' => array( 'نينوى', 7000, '' ),
				'AR' => array( 'أربيل', 7000, '' ),
				'SU' => array( 'السليمانية', 7000, '' ),
				'DA' => array( 'دهوك', 7000, '' ),
				'KI' => array( 'كركوك', 7000, '' ),
				'AN' => array( 'الأنبار', 7000, '' ),
				'BB' => array( 'بابل', 7000, '' ),
				'KA' => array( 'كربلاء', 7000, '' ),
				'NA' => array( 'النجف', 7000, '' ),
				'WA' => array( 'واسط', 7000, '' ),
				'MA' => array( 'ميسان', 7000, '' ),
				'DQ' => array( 'ذي قار', 7000, '' ),
				'MU' => array( 'المثنى', 7000, '' ),
				'QA' => array( 'الديوانية', 7000, '' ),
				'SD' => array( 'صلاح الدين', 7000, '' ),
				'DI' => array( 'ديالى', 7000, '' ),
			),
		),
	);

	public static function init() {
		add_filter( 'woocommerce_states', array( __CLASS__, 'states' ) );
		add_filter( 'woocommerce_currency_symbol', array( __CLASS__, 'symbol' ), 10, 2 );
	}

	public static function symbol( $symbol, $currency ) {
		foreach ( self::RULES as $r ) {
			if ( $r['currency'] === $currency ) {
				return $r['symbol'];
			}
		}
		return $symbol;
	}

	public static function code() {
		$c = strtoupper( (string) get_option( 'ym_country', 'EG' ) );
		return isset( self::RULES[ $c ] ) ? $c : 'EG';
	}

	public static function rules( $country = null ) {
		return self::RULES[ $country ? $country : self::code() ];
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
			$cache[ $cc ] = (array) json_decode( (string) file_get_contents( YM_DIR . '/copy/' . strtolower( $cc ) . '.json' ), true );
			unset( $cache[ $cc ]['_status'] );
		}
		return $cache[ $cc ];
	}

	/** Arabic governorate names as WooCommerce states for this country. */
	public static function states( $states ) {
		foreach ( self::RULES as $cc => $rules ) {
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

	/** Normalise a phone number: strip spaces, dashes, Arabic-Indic digits, +20 / +964 prefixes. */
	public static function normalise_phone( $raw, $country = null ) {
		$p = strtr( (string) $raw, array( '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
		$p = preg_replace( '/[^0-9+]/', '', $p );
		$cc = $country ? $country : self::code();
		if ( 'EG' === $cc ) {
			$p = preg_replace( '/^(\+|00)20/', '0', $p );
		} else {
			$p = preg_replace( '/^(\+|00)964/', '0', $p );
		}
		return $p;
	}

	public static function valid_phone( $raw, $country = null ) {
		return (bool) preg_match( self::rules( $country )['phone_regex'], self::normalise_phone( $raw, $country ) );
	}

	/** Same digits and separators as WooCommerce's own prices (cart, checkout, emails). */
	public static function number( $amount ) {
		$sep = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
		return number_format( (float) $amount, 0, '.', $sep );
	}

	/** Home URL of each country store, from option ym_store_urls (set by `wp ym setup --store-urls=...`). */
	public static function store_urls() {
		$urls = (array) get_option( 'ym_store_urls', array() );
		$out  = array();
		foreach ( array_keys( self::RULES ) as $cc ) {
			$out[ $cc ] = $urls[ $cc ] ?? ( self::code() === $cc ? home_url( '/' ) : '' );
		}
		return array_filter( $out );
	}

	public static function money( $amount ) {
		return self::number( $amount ) . ' ' . self::rules()['symbol'];
	}
}
