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
$iq_keys = array_keys( YM_Country::copy_all( 'IQ' ) );
sort( $eg_keys );
sort( $iq_keys );
ym_check( 'same copy keys in EG and IQ', $eg_keys === $iq_keys );

// Customer-facing text: no harsh policy phrasing, and the supplier's name never appears.
$banned = array( 'مش مسموح', 'ما مسموح', 'ممنوع', 'ردّي عشان', 'ردي عشان', 'يطلع الطلب', 'الطلب يطلع', 'رقم غريب', 'تاجر', 'Taager', 'taager' );
$texts  = array();
foreach ( array( 'EG', 'IQ' ) as $c ) {
	foreach ( YM_Country::copy_all( $c ) as $k => $v ) {
		$texts[ "copy/$c/$k" ] = $v;
	}
	foreach ( glob( YM_DIR . '/content/' . strtolower( $c ) . '/*.html' ) as $f ) {
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

// Catalog gate: seeded (unverified) products are blocked from sale once the gate is enforced.
$p = wc_get_product( wc_get_product_id_by_sku( 'EG' === $cc ? 'EG030106MKKT99' : 'IQ030403SENK99' ) );
ym_check( 'seeded product has blockers', isset( YM_Catalog::blockers( $p )['stock_unverified'], YM_Catalog::blockers( $p )['media_resolution'] ) );
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

require __DIR__ . '/reporting.php';

if ( $GLOBALS['ym_fail'] ) {
	WP_CLI::error( $GLOBALS['ym_fail'] . ' failed, ' . $GLOBALS['ym_ok'] . " passed ($cc)" );
}
WP_CLI::success( $GLOBALS['ym_ok'] . " passed ($cc)" );
