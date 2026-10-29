<?php
/**
 * Integration checks that run inside a store: dev/wp.sh eg eval-file tests/integration/run.php
 * Exits non-zero on the first failure.
 */

$GLOBALS['ym_fail'] = 0;
$GLOBALS['ym_ok']   = 0;
function ym_check( $name, $cond ) {
	if ( $cond ) {
		$GLOBALS['ym_ok']++;
		return;
	}
	$GLOBALS['ym_fail']++;
	WP_CLI::warning( 'FAIL: ' . $name );
}

$cc = YM_Country::code();

// Phone rules, including Arabic-Indic digits and international prefixes.
if ( 'EG' === $cc ) {
	ym_check( 'EG phone plain', YM_Country::valid_phone( '01012345678' ) );
	ym_check( 'EG phone arabic digits', YM_Country::valid_phone( '٠١٠١٢٣٤٥٦٧٨' ) );
	ym_check( 'EG phone +20', YM_Country::valid_phone( '+20 101 234 5678' ) );
	ym_check( 'EG phone wrong prefix', ! YM_Country::valid_phone( '01312345678' ) );
	ym_check( 'EG phone short', ! YM_Country::valid_phone( '0101234567' ) );
	ym_check( 'EG fee Cairo 95', 95.0 === YM_Country::fee( 'EGC' ) );
	ym_check( 'EG fee Delta 100', 100.0 === YM_Country::fee( 'EGDK' ) );
	ym_check( 'EG fee Aswan 105', 105.0 === YM_Country::fee( 'EGASN' ) );
	ym_check( 'EG fee unknown', null === YM_Country::fee( 'BG' ) );
	ym_check( 'EG all 27 governorates priced', 27 === count( YM_Country::rules()['governorates'] ) );
	ym_check( 'EG currency', 'EGP' === get_woocommerce_currency() );
} elseif ( 'SA' === $cc ) {
	ym_check( 'SA phone plain', YM_Country::valid_phone( '0512345678' ) );
	ym_check( 'SA phone +966', YM_Country::valid_phone( '+966 51 234 5678' ) );
	ym_check( 'SA phone 966 without +', YM_Country::valid_phone( '966512345678' ) );
	ym_check( 'SA phone without trunk 0', '0512345678' === YM_Country::normalise_phone( '512345678' ) );
	ym_check( 'SA phone persian digits', YM_Country::valid_phone( '۰۵۱۲۳۴۵۶۷۸' ) );
	ym_check( 'SA landline refused', ! YM_Country::valid_phone( '0112345678' ) );
	ym_check( 'SA fee flat 28', 28.0 === YM_Country::fee( 'SA01' ) && 28.0 === YM_Country::fee( 'SA14' ) );
	ym_check( 'SA 13 regions', 13 === count( YM_Country::rules()['governorates'] ) );
	ym_check( 'SA currency', 'SAR' === get_woocommerce_currency() );
	ym_check( 'SA 2 decimals', 2 === YM_Country::decimals() && '28' === YM_Country::number( 28 ) && '27.5' !== YM_Country::number( 27.5 ) );
	ym_check( 'SA refusal fee unknown, not zero', null === YM_Country::rules()['refusal_fee'] );
	ym_check( 'SA VAT on uplift 15%', 0.15 === YM_Country::rules()['vat_on_uplift'] );
} elseif ( 'AE' === $cc ) {
	ym_check( 'AE phone plain', YM_Country::valid_phone( '0501234567' ) );
	ym_check( 'AE phone +971', YM_Country::valid_phone( '+971 50 123 4567' ) );
	ym_check( 'AE phone bad prefix', ! YM_Country::valid_phone( '0531234567' ) );
	ym_check( 'AE fee flat 19', 19.0 === YM_Country::fee( 'DU' ) && 19.0 === YM_Country::fee( 'FU' ) );
	ym_check( 'AE 7 emirates', 7 === count( YM_Country::rules()['governorates'] ) );
	ym_check( 'AE currency', 'AED' === get_woocommerce_currency() );
} elseif ( 'OM' === $cc ) {
	ym_check( 'OM phone 8 digits', YM_Country::valid_phone( '91234567' ) );
	ym_check( 'OM phone +968', YM_Country::valid_phone( '+968 9123 4567' ) );
	ym_check( 'OM phone 00968', YM_Country::valid_phone( '0096871234567' ) );
	ym_check( 'OM phone arabic digits', YM_Country::valid_phone( '٩١٢٣٤٥٦٧' ) );
	ym_check( 'OM phone 9 digits refused', ! YM_Country::valid_phone( '912345678' ) );
	ym_check( 'OM intl phone for ad matching', '96891234567' === YM_Country::intl_phone( '9123 4567' ) );
	ym_check( 'OM fee flat 3', 3.0 === YM_Country::fee( 'MA' ) );
	ym_check( 'OM 11 governorates', 11 === count( YM_Country::rules()['governorates'] ) );
	ym_check( 'OM currency', 'OMR' === get_woocommerce_currency() );
	ym_check( 'OM 3 decimals in WooCommerce', 3 === wc_get_price_decimals() );
	ym_check( 'OM rounding to 3 decimals', 2.346 === YM_Country::round_money( 2.3456 ) );
	ym_check( 'OM display trims only whole numbers', '3' === YM_Country::number( 3 ) && '2.500' === YM_Country::number( 2.5 ) );
	ym_check( 'OM price step 0.100', 2.5 === YM_Country::price_step( 2.43 ) );
} else {
	ym_check( 'IQ phone plain', YM_Country::valid_phone( '07701234567' ) );
	ym_check( 'IQ phone +964', YM_Country::valid_phone( '+964 770 123 4567' ) );
	ym_check( 'IQ phone egyptian', ! YM_Country::valid_phone( '01012345678' ) );
	ym_check( 'IQ fee flat 7000', 7000.0 === YM_Country::fee( 'BG' ) && 7000.0 === YM_Country::fee( 'DA' ) );
	ym_check( 'IQ all 18 governorates priced', 18 === count( YM_Country::rules()['governorates'] ) );
	ym_check( 'IQ currency', 'IQD' === get_woocommerce_currency() );
}

// Every customer-facing string exists in both dialects (an Egyptian fallback must never reach Iraq).
$eg_keys = array_keys( YM_Country::copy_all( 'EG' ) );
sort( $eg_keys );
foreach ( YM_Country::codes() as $c ) {
	$k = array_keys( YM_Country::copy_all( $c ) );
	sort( $k );
	ym_check( "same copy keys in EG and $c: " . implode( ',', array_merge( array_diff( $eg_keys, $k ), array_diff( $k, $eg_keys ) ) ), $eg_keys === $k );
	ym_check( "no empty copy in $c", ! array_filter( YM_Country::copy_all( $c ), fn( $v, $key ) => '' === $v && 'returns' !== $key, ARRAY_FILTER_USE_BOTH ) );
}
ym_check( 'every market in config/markets.json', array_keys( array_diff_key( array_flip( YM_Country::codes() ), YM_Markets::config() ) ) === array() );

// Customer-facing text: no harsh policy phrasing, and the supplier's name never appears.
$banned = array( 'مش مسموح', 'ما مسموح', 'ممنوع', 'ردّي عشان', 'ردي عشان', 'يطلع الطلب', 'الطلب يطلع', 'رقم غريب', 'تاجر', 'Taager', 'taager' );
$texts  = array();
foreach ( YM_Country::codes() as $c ) {
	foreach ( YM_Country::copy_all( $c ) as $k => $v ) {
		$texts[ "copy/$c/$k" ] = $v;
	}
	foreach ( array_merge( glob( YM_DIR . '/content/' . strtolower( $c ) . '/*.html' ), glob( YM_DIR . '/content/gulf/*.html' ) ) as $f ) {
		$texts[ 'content/' . basename( dirname( $f ) ) . '/' . basename( $f ) ] = file_get_contents( $f );
	}
	$seed = json_decode( file_get_contents( YM_DIR . '/seed/' . strtolower( $c ) . '.json' ), true );
	foreach ( $seed['products'] as $row ) {
		foreach ( array( 'name', 'benefit', 'diary', 'fit', 'label', 'note', 'saving' ) as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				$texts[ "seed/$c/{$row['sku']}/$field" ] = $row[ $field ];
			}
		}
	}
}
$hits = array();
foreach ( $texts as $where => $text ) {
	foreach ( $banned as $b ) {
		if ( false !== mb_strpos( (string) $text, $b ) ) {
			$hits[] = "$b in $where";
		}
	}
}
ym_check( 'customer text free of banned phrases and supplier name (' . count( $texts ) . ' texts): ' . implode( ', ', $hits ), ! $hits );

// A saving line states money: it must equal the parts bought separately minus the bundle (seed field saving_parts).
$bad = array();
foreach ( json_decode( file_get_contents( YM_DIR . '/seed/' . strtolower( $cc ) . '.json' ), true )['products'] as $row ) {
	if ( empty( $row['saving'] ) ) {
		continue;
	}
	$price = array_column( json_decode( file_get_contents( YM_DIR . '/seed/' . strtolower( $cc ) . '.json' ), true )['products'], 'price', 'sku' );
	$parts = array_sum( array_map( fn( $s ) => (float) ( $price[ $s ] ?? NAN ), (array) ( $row['saving_parts'] ?? array() ) ) );
	preg_match( '/[\d,]+/', $row['saving'], $m );
	if ( ! $m || (float) str_replace( ',', '', $m[0] ) !== $parts - (float) $row['price'] ) {
		$bad[] = $row['sku'];
	}
}
ym_check( 'saving lines match the price of their parts: ' . implode( ', ', $bad ), ! $bad );

// Catalog gate: seeded (unverified) products are blocked from sale once the gate is enforced.
$first = json_decode( file_get_contents( YM_DIR . '/seed/' . strtolower( $cc ) . '.json' ), true )['products'][0]['sku'];
$p     = wc_get_product( wc_get_product_id_by_sku( $first ) );
$bl    = YM_Catalog::blockers( $p );
ym_check( 'seeded product has stock and media blockers', isset( $bl['stock_unverified'] ) && (bool) array_filter( array_keys( $bl ), fn( $k ) => 0 === strpos( $k, 'media' ) ) );
ym_check( 'local store does not enforce the gate', $p->is_purchasable() );
add_filter( 'ym_environment_type', fn() => 'production' );
ym_check( 'production: unverified product not purchasable', ! wc_get_product( $p->get_id() )->is_purchasable() );
ym_check( 'production: unverified product not in stock', ! wc_get_product( $p->get_id() )->is_in_stock() );
$tmp = new WC_Product_Simple();
$tmp->set_name( 'gate test' );
$tmp->set_regular_price( '10' );
$tmp->set_status( 'publish' );
$tmp->save();
ym_check( 'production: publishing an unverified product saves a draft', 'draft' === get_post_status( $tmp->get_id() ) );
$tmp->delete( true );
remove_all_filters( 'ym_environment_type' );

// Only cash on delivery is offered.
$gateways = WC()->payment_gateways()->payment_gateways();
ym_check( 'COD only', array( 'cod' ) === array_keys( YM_Checkout::cod_only( $gateways ) ) );

// Every published offer has a Taager SKU, a price at or above Taager's cost, and a Taager product ID.
foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) ) as $p ) {
	ym_check( 'SKU set: ' . $p->get_id(), '' !== $p->get_sku() );
	ym_check( 'price above cost: ' . $p->get_sku(), (float) $p->get_price() > (float) $p->get_meta( '_ym_taager_cost' ) );
	ym_check( 'Taager ID: ' . $p->get_sku(), (int) $p->get_meta( '_ym_taager_product_id' ) > 0 );
}

// Manual supplier entry refuses an order that already carries a supplier reference.
$o = wc_create_order();
$o->update_meta_data( '_taager_order_id', 'T-1' );
$o->save();
$r = YM_Supplier::mark_manual( $o, 'T-2' );
ym_check( 'manual refused when supplier ref exists', is_wp_error( $r ) && 'ym_already_sent' === $r->get_error_code() );
$r = YM_Supplier::mark_not_found( $o );
ym_check( 'not-found refused when supplier ref exists', is_wp_error( $r ) );
$o->delete( true );

// Staging order tests: an order placed while the store runs as "development" is marked as a test order; never otherwise.
foreach ( array( 'development' => '1', 'staging' => '', 'production' => '', 'local' => '' ) as $env => $want ) {
	add_filter( 'ym_environment_type', fn() => $env );
	$o = new WC_Order();
	YM_Checkout::save_extra( $o, array() );
	ym_check( "test-order mark in $env", $want === (string) $o->get_meta( '_ym_test' ) );
	remove_all_filters( 'ym_environment_type' );
}

// launch-check --format=json: open items are data, not a failure (the deploy workflow reads it).
ob_start();
( new YM_CLI() )->launch_check( array(), array( 'format' => 'json' ) );
$lc = json_decode( trim( (string) ob_get_clean() ), true );
ym_check( 'launch-check json parses', is_array( $lc ) && isset( $lc['open'], $lc['rows'] ) && $lc['open'] > 0 && strtoupper( $cc ) === $lc['country'] );

require __DIR__ . '/reporting.php';
require __DIR__ . '/accounting.php';

if ( $GLOBALS['ym_fail'] ) {
	WP_CLI::error( $GLOBALS['ym_fail'] . ' failed, ' . $GLOBALS['ym_ok'] . " passed ($cc)" );
}
WP_CLI::success( $GLOBALS['ym_ok'] . " passed ($cc)" );
