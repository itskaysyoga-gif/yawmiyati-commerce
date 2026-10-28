<?php
/**
 * Shared country configuration for all Yawmiyati stores (one WordPress install per country, same plugin and theme).
 * Change docs/COUNTRY-RULES.md first, then this file. Every supplier figure names its source.
 *
 *   currency, decimals   ISO currency and its minor units: amounts are rounded to this many decimals everywhere
 *                        (orders, reports, analytics). Display trims trailing zeros (3.000 OMR shows as 3).
 *   price_step           our selling prices are whole multiples of this (1 EGP, 250 IQD, 1 SAR, 1 AED, 0.100 OMR)
 *   dial, trunk          international dialling code and national trunk prefix ('0' or none)
 *   phone_regex          valid MOBILE number in national format after normalisation
 *   refusal_fee          charged to us when a customer refuses at the door; null = not published (unknown, not 0)
 *   return_days          customer's return window per the supplier's published policy; null = unclear
 *   vat_on_uplift        VAT on the part of our price above the supplier minimum (Saudi only)
 *   governorates         code => [Arabic name, delivery fee charged to the customer, delivery window or '']
 *   voice                copy file family: eg, iq or gulf (Saudi, UAE, Oman share a restrained Gulf voice)
 *
 * Sources: Taager published shipping policy (captured Sept 2026, project doc "Taager_shipping_policy_TEXT.md");
 * Taager support chat with Rashid, 28 Oct 2026 ("Taager said", not verified).
 */

defined( 'ABSPATH' ) || exit;

$flat = function ( $fee, $window, $names ) {
	$out = array();
	foreach ( $names as $code => $name ) {
		$out[ $code ] = array( $name, $fee, $window );
	}
	return $out;
};

return array(
	'EG' => array(
		'name'          => 'مصر',
		'hreflang'      => 'ar-EG',
		'currency'      => 'EGP',
		'decimals'      => 0,
		'price_step'    => 1,
		'symbol'        => 'ج.م',
		'dial'          => '20',
		'trunk'         => '0',
		'phone_regex'   => '/^01[0125][0-9]{8}$/',
		'phone_example' => '01xxxxxxxxx',
		'refusal_fee'   => 5,
		'refusal_fee_source' => 'published policy: 5 EGP on a shipped order not received; Taager support said no deduction (28 Oct): conflict, modelled at 5',
		'return_fee'    => 5,
		'return_days'   => 14,
		'vat_on_uplift' => 0,
		'voice'         => 'eg',
		'region_word'   => 'المحافظة',
		'zones'         => array(
			array( 95, 'القاهرة والجيزة والإسكندرية', 'يومين شغل' ),
			array( 100, 'الدلتا والقناة', 'لحد 3 أيام' ),
			array( 105, 'الصعيد وسيناء والبحر الأحمر ومطروح', 'من 2 لـ 5 أيام' ),
		),
		'governorates'  => array(
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
		'name'          => 'العراق',
		'hreflang'      => 'ar-IQ',
		'currency'      => 'IQD',
		'decimals'      => 0,
		'price_step'    => 250,
		'symbol'        => 'د.ع',
		'dial'          => '964',
		'trunk'         => '0',
		'phone_regex'   => '/^07[0-9]{9}$/',
		'phone_example' => '07xxxxxxxxx',
		'refusal_fee'   => 0,
		'refusal_fee_source' => 'not in the published policy; Taager support said no deduction on refusal (28 Oct 2026)',
		'return_fee'    => null,
		'return_days'   => null,
		'vat_on_uplift' => 0,
		'voice'         => 'iq',
		'region_word'   => 'المحافظة',
		'zones'         => array( array( 7000, 'كل المحافظات', '' ) ),
		'governorates'  => $flat( 7000, '', array( 'BG' => 'بغداد', 'BA' => 'البصرة', 'NI' => 'نينوى', 'AR' => 'أربيل', 'SU' => 'السليمانية', 'DA' => 'دهوك', 'KI' => 'كركوك', 'AN' => 'الأنبار', 'BB' => 'بابل', 'KA' => 'كربلاء', 'NA' => 'النجف', 'WA' => 'واسط', 'MA' => 'ميسان', 'DQ' => 'ذي قار', 'MU' => 'المثنى', 'QA' => 'الديوانية', 'SD' => 'صلاح الدين', 'DI' => 'ديالى' ) ),
	),
	'SA' => array(
		'name'          => 'السعودية',
		'hreflang'      => 'ar-SA',
		'currency'      => 'SAR',
		'decimals'      => 2,
		'price_step'    => 1,
		'symbol'        => 'ر.س',
		'dial'          => '966',
		'trunk'         => '0',
		'phone_regex'   => '/^05[0-9]{8}$/',
		'phone_example' => '05xxxxxxxx',
		'refusal_fee'   => null,
		'refusal_fee_source' => 'not published',
		'return_fee'    => null,
		'return_days'   => 7,
		'vat_on_uplift' => 0.15,
		'voice'         => 'gulf',
		'region_word'   => 'المنطقة',
		'zones'         => array( array( 28, 'كل مناطق المملكة', '' ) ),
		// Delivery time is published by city (Riyadh, Jeddah, Dammam 2-3 days; elsewhere 4-5), not by region.
		'governorates'  => $flat( 28, '', array( 'SA01' => 'الرياض', 'SA02' => 'مكة المكرمة', 'SA03' => 'المدينة المنورة', 'SA04' => 'المنطقة الشرقية', 'SA05' => 'القصيم', 'SA06' => 'حائل', 'SA07' => 'تبوك', 'SA08' => 'الحدود الشمالية', 'SA09' => 'جازان', 'SA10' => 'نجران', 'SA11' => 'الباحة', 'SA12' => 'الجوف', 'SA14' => 'عسير' ) ),
	),
	'AE' => array(
		'name'          => 'الإمارات',
		'hreflang'      => 'ar-AE',
		'currency'      => 'AED',
		'decimals'      => 2,
		'price_step'    => 1,
		'symbol'        => 'د.إ',
		'dial'          => '971',
		'trunk'         => '0',
		'phone_regex'   => '/^05[024568][0-9]{7}$/',
		'phone_example' => '05xxxxxxxx',
		'refusal_fee'   => null,
		'refusal_fee_source' => 'not published',
		'return_fee'    => null,
		'return_days'   => 7,
		'vat_on_uplift' => 0,
		'voice'         => 'gulf',
		'region_word'   => 'الإمارة',
		'zones'         => array( array( 19, 'كل الإمارات', 'خلال 24 إلى 48 ساعة' ) ),
		'governorates'  => $flat( 19, 'خلال 24 إلى 48 ساعة', array( 'AZ' => 'أبوظبي', 'DU' => 'دبي', 'SH' => 'الشارقة', 'AJ' => 'عجمان', 'UQ' => 'أم القيوين', 'RK' => 'رأس الخيمة', 'FU' => 'الفجيرة' ) ),
	),
	'OM' => array(
		'name'          => 'عُمان',
		'hreflang'      => 'ar-OM',
		'currency'      => 'OMR',
		'decimals'      => 3,
		'price_step'    => 0.1,
		'symbol'        => 'ر.ع',
		'dial'          => '968',
		'trunk'         => '',
		'phone_regex'   => '/^[79][0-9]{7}$/',
		'phone_example' => '9xxxxxxx',
		'refusal_fee'   => null,
		'refusal_fee_source' => 'not published',
		'return_fee'    => null,
		'return_days'   => 15,
		'vat_on_uplift' => 0,
		'voice'         => 'gulf',
		'region_word'   => 'المحافظة',
		'zones'         => array( array( 3, 'كل محافظات السلطنة', '' ) ),
		'governorates'  => $flat( 3, '', array( 'MA' => 'مسقط', 'ZU' => 'ظفار', 'MU' => 'مسندم', 'BU' => 'البريمي', 'DA' => 'الداخلية', 'BS' => 'شمال الباطنة', 'BJ' => 'جنوب الباطنة', 'SJ' => 'جنوب الشرقية', 'SS' => 'شمال الشرقية', 'ZA' => 'الظاهرة', 'WU' => 'الوسطى' ) ),
	),
);
