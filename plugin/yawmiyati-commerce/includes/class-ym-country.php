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
			'phone_hint'   => 'رقم مصري من 11 رقم يبدأ بـ 010 أو 011 أو 012 أو 015.',
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
			'copy'         => array(
				'buy'          => 'اطلبي دلوقتي، والدفع عند الاستلام',
				'buy_short'    => 'اطلبي دلوقتي',
				'add'          => 'أضيفي للسلة',
				'place'        => 'تأكيد الطلب',
				'pay_on'       => 'هتدفعي عند الاستلام',
				'confirm_call' => 'فريق التأكيد هيكلمك خلال 24 ساعة من رقم غريب، ردّي عشان الطلب يطلع. لو طلبتي قبل 7 بالليل بيتأكد نفس اليوم.',
				'payment'      => 'الدفع كاش للمندوب. مش مسموح فتح الشحنة قبل الدفع، والفاتورة جوّه الشحنة.',
				'returns'      => 'لو فيه عيب: استبدال أو استرجاع خلال 14 يوم.',
				'one_fee'      => 'التوصيل مرة واحدة على الطلب كله.',
				'thanks'       => 'طلبك وصل',
				'pick_gov'     => 'اختاري المحافظة وهيظهر سعر التوصيل.',
				'fit_q'        => 'هتيجي مظبوطة عندك؟',
				'why'          => 'ليه هتحبيها',
				'expired'      => 'انتهت صلاحية الصفحة. حدّثيها وجرّبي تاني.',
				'offer_na'     => 'العرض ده مش متاح دلوقتي.',
				'added'        => 'اتضاف للسلة.',
				'max_qty'      => 'أقصى كمية من المنتج ده %d في الطلب الواحد.',
				'sold_out_tag' => 'خلص من المخزن',
				'sold_out'     => 'المنتج ده خلص من المخزن دلوقتي.',
				'phone2'       => 'رقم تاني',
				'phone2_help'  => 'بيساعد إن الطلب يوصل لو الأول مقفول',
				'phone2_err'   => 'الرقم التاني: ',
				'wrong_country' => 'التوصيل متاح جوّه مصر بس.',
				'refresh'      => 'حدّثي الصفحة وجرّبي تاني.',
				'dup'          => 'الطلب ده اتبعت خلاص. لو عايزة تطلبي تاني، حدّثي الصفحة.',
			),
		),
		'IQ' => array(
			'currency'     => 'IQD',
			'decimals'     => 0,
			'symbol'       => 'د.ع',
			'phone_regex'  => '/^07[0-9]{9}$/',
			'phone_hint'   => 'رقم عراقي من 11 رقم يبدأ بـ 07.',
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
			'copy'         => array(
				'buy'          => 'اطلبي هسّه والدفع عند الاستلام',
				'buy_short'    => 'اطلبي هسّه',
				'add'          => 'ضيفي للسلة',
				'place'        => 'ثبّتي الطلب',
				'pay_on'       => 'تدفعين عند الاستلام',
				'confirm_call' => 'فريق التأكيد يتصل بيج خلال 24 ساعة، من 9 الصبح لـ 5 العصر. جاوبي حتى يطلع الطلب.',
				'payment'      => 'الدفع كاش للمندوب. ما مسموح تفتحين الكرتونة قبل الدفع، والفاتورة داخلها.',
				'returns'      => '',
				'one_fee'      => 'التوصيل مرة وحدة على الطلب كله.',
				'thanks'       => 'وصلنا طلبج',
				'pick_gov'     => 'اختاري المحافظة حتى يطلع سعر التوصيل.',
				'fit_q'        => 'راح تجي مضبوطة عدج؟',
				'why'          => 'ليش راح تحبينها',
				'expired'      => 'انتهت صلاحية الصفحة. حدّثيها وجرّبي مرة ثانية.',
				'offer_na'     => 'هذا العرض مو متوفر هسّه.',
				'added'        => 'انضاف للسلة.',
				'max_qty'      => 'أكثر كمية من هذا المنتج %d بالطلب الواحد.',
				'sold_out_tag' => 'خلصت الكمية',
				'sold_out'     => 'هذا المنتج خلص هسّه.',
				'phone2'       => 'رقم ثاني',
				'phone2_help'  => 'يفيد حتى يوصل الطلب إذا الرقم الأول مسدود',
				'phone2_err'   => 'الرقم الثاني: ',
				'wrong_country' => 'التوصيل داخل العراق بس.',
				'refresh'      => 'حدّثي الصفحة وجرّبي مرة ثانية.',
				'dup'          => 'هذا الطلب انبعث. إذا تريدين تطلبين مرة ثانية، حدّثي الصفحة.',
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

	public static function copy( $key ) {
		$r = self::rules();
		return $r['copy'][ $key ] ?? '';
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

	public static function money( $amount ) {
		return self::number( $amount ) . ' ' . self::rules()['symbol'];
	}
}
