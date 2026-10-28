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
$o->delete( true );

if ( $GLOBALS['ym_fail'] ) {
	WP_CLI::error( $GLOBALS['ym_fail'] . ' failed, ' . $GLOBALS['ym_ok'] . " passed ($cc)" );
}
WP_CLI::success( $GLOBALS['ym_ok'] . " passed ($cc)" );
