<?php
/**
 * Reporting and lifecycle checks (included by run.php). Orders are created on a fixed past day so the report window
 * contains only them, and they are deleted at the end.
 */

$day  = strtotime( '2025-06-10 12:00:00 UTC' );
$made = array();
$prods = array_values( array_filter( wc_get_products( array( 'limit' => 6, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC' ) ), fn( $p ) => (float) $p->get_meta( '_ym_taager_cost' ) > 0 ) );
$temp  = array();
for ( $i = count( $prods ); $i < 3; $i++ ) { // stores with one seeded item get temporary products for these checks
	$t = new WC_Product_Simple();
	$t->set_name( 'report test ' . $i );
	$t->set_sku( 'YM-REPORT-TEST-' . $i );
	$t->set_regular_price( (string) ( 10 + $i ) );
	$t->set_status( 'publish' );
	$t->update_meta_data( '_ym_taager_cost', 5 );
	$t->save();
	$temp[]  = $t->get_id();
	$prods[] = wc_get_product( $t->get_id() );
}
$mk = function ( $lines, $offset = 0 ) use ( &$made, $day ) {
	$o = wc_create_order();
	foreach ( $lines as $l ) {
		$o->add_product( $l[0], $l[1] );
	}
	$o->set_billing_country( YM_Country::code() );
	$o->calculate_totals( false );
	$o->set_date_created( $day + $offset );
	$o->save();
	YM_Orders::snapshot( $o );
	$o->save();
	YM_Lifecycle::on_submitted( $o->get_id(), array(), $o );
	$made[] = $o->get_id();
	return wc_get_order( $o->get_id() );
};
$report = fn() => YM_Report::build( $day - HOUR_IN_SECONDS, $day + DAY_IN_SECONDS );

if ( count( $prods ) >= 3 ) {
	// 1) One order with three different products is one order, three lines, five units.
	$a = $mk( array( array( $prods[0], 2 ), array( $prods[1], 1 ), array( $prods[2], 2 ) ) );
	$r = $report();
	ym_check( 'report: 3-product order counts as 1 order', 1 === $r['counts']['orders'] );
	ym_check( 'report: 3 lines', 3 === $r['counts']['lines'] );
	ym_check( 'report: 5 units', 5 === $r['counts']['units'] );
	ym_check( 'report: each product counts that order once', 1 === $r['products'][ $prods[0]->get_sku() ]['orders'] && 2 === $r['products'][ $prods[0]->get_sku() ]['units'] );

	// 2) Refusal on that order: the fee is charged once for the order, never once per product line.
	YM_Lifecycle::record( $a, 'refused', array( 'src' => 'staff', 'verified' => true, 'reason' => 'changed_mind', 'evidence' => 'test' ) );
	$charges = YM_Lifecycle::charges( wc_get_order( $a->get_id() ) );
	ym_check( 'refusal: exactly one refusal_fee charge on a 3-line order', 1 === count( array_filter( $charges, fn( $c ) => 'refusal_fee' === $c['type'] ) ) );
	$fee = YM_Country::rules()['refusal_fee'];
	$r   = $report();
	if ( null === $fee ) {
		ym_check( 'refusal: unknown fee reported as unknown, not zero', null === $r['money']['wallet_expected'] && $r['unknown'] );
	} else {
		ym_check( 'refusal: order charges = one fee', abs( $r['money']['order_charges_known'] - (float) $fee ) < 0.001 );
		$alloc = array_sum( array_map( fn( $p ) => $p['allocated_charges'], $r['products'] ) );
		ym_check( 'refusal: product allocation sums to the one order charge', abs( $alloc - (float) $fee ) < 0.001 );
	}
	ym_check( 'refusal: outcome refused', 'refused' === YM_Lifecycle::outcome( wc_get_order( $a->get_id() ) ) );
	ym_check( 'refusal: refused order still counted as confirmed and shipped', 1 === $r['counts']['reached']['shipped'] && 0 === $r['counts']['reached']['delivered'] );
	// The same update delivered twice is recorded once.
	$before = count( YM_Lifecycle::events( wc_get_order( $a->get_id() ) ) );
	$ev     = YM_Lifecycle::events( wc_get_order( $a->get_id() ) );
	$last   = end( $ev );
	YM_Lifecycle::record( wc_get_order( $a->get_id() ), 'refused', array( 'at' => $last['at'], 'src' => 'staff', 'verified' => true, 'reason' => 'changed_mind' ) );
	ym_check( 'lifecycle: duplicate update ignored', $before === count( YM_Lifecycle::events( wc_get_order( $a->get_id() ) ) ) );
	// An actual charge replaces the policy estimate; a second actual one is refused (no double deduction).
	$x = YM_Lifecycle::add_charge( wc_get_order( $a->get_id() ), 'refusal_fee', 4, 'actual', 'wallet' );
	$y = YM_Lifecycle::add_charge( wc_get_order( $a->get_id() ), 'refusal_fee', 4, 'actual', 'wallet' );
	ym_check( 'charge: actual replaces rule, second actual refused', ! is_wp_error( $x ) && is_wp_error( $y ) && 1 === count( YM_Lifecycle::charges( wc_get_order( $a->get_id() ) ) ) );

	// 3) Delivered order: present in every earlier stage even if only "delivered" was recorded.
	$b = $mk( array( array( $prods[0], 1 ) ), 60 );
	YM_Lifecycle::record( $b, 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 'test' ) );
	$r = $report();
	ym_check( 'funnel: delivered counts in confirmed and shipped', 1 === $r['counts']['reached']['delivered'] && 2 === $r['counts']['reached']['shipped'] && 2 === $r['counts']['reached']['confirmed'] );
	ym_check( 'outcome: delivered_kept', 'delivered_kept' === YM_Lifecycle::outcome( wc_get_order( $b->get_id() ) ) );

	// 4) Refund is not refusal: a full refund after delivery is a return; a partial refund keeps it delivered.
	$c = $mk( array( array( $prods[1], 2 ) ), 120 );
	YM_Lifecycle::record( $c, 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 'test' ) );
	wc_create_refund( array( 'order_id' => $c->get_id(), 'amount' => round( (float) $c->get_total() / 4, YM_Country::decimals() ), 'reason' => 'test partial' ) );
	ym_check( 'refund partial: delivered_partial_refund', 'delivered_partial_refund' === YM_Lifecycle::outcome( wc_get_order( $c->get_id() ) ) );
	$d = $mk( array( array( $prods[1], 1 ) ), 180 );
	YM_Lifecycle::record( $d, 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 'test' ) );
	wc_create_refund( array( 'order_id' => $d->get_id(), 'amount' => $d->get_total(), 'reason' => 'test full' ) );
	ym_check( 'refund full after delivery: returned_full, not refused', 'returned_full' === YM_Lifecycle::outcome( wc_get_order( $d->get_id() ) ) );
	$r = $report();
	ym_check( 'report: refunds are not refusals', 1 === ( $r['counts']['outcomes']['refused'] ?? 0 ) && 1 === ( $r['counts']['outcomes']['returned_full'] ?? 0 ) && 1 === ( $r['counts']['outcomes']['delivered_partial_refund'] ?? 0 ) );
	ym_check( 'report: refunds money tracked apart', $r['money']['refunds'] > 0 );

	// 5) Cancellation before hand-off vs after shipment.
	$e = $mk( array( array( $prods[2], 1 ) ), 240 );
	$e->update_status( 'cancelled' );
	ym_check( 'cancel before hand-off', 'cancelled_before_handoff' === YM_Lifecycle::outcome( wc_get_order( $e->get_id() ) ) );
	$f = $mk( array( array( $prods[2], 1 ) ), 300 );
	YM_Lifecycle::record( $f, 'shipped', array( 'src' => 'staff', 'verified' => true, 'evidence' => 'test' ) );
	wc_get_order( $f->get_id() )->update_status( 'cancelled' );
	ym_check( 'cancel after shipment', 'cancelled_after_shipment' === YM_Lifecycle::outcome( wc_get_order( $f->get_id() ) ) );

	// 6) A WooCommerce status alone is not a verified delivery while status sync is unverified.
	$g = $mk( array( array( $prods[0], 1 ) ), 360 );
	update_option( 'ym_stage_map', array( 'delivered' => array( 'completed' ) ) );
	delete_option( 'ym_status_sync_verified' );
	$fired = 0;
	$count = function () use ( &$fired ) { $fired++; };
	add_action( 'ym_order_delivered', $count );
	wc_get_order( $g->get_id() )->update_status( 'completed' );
	ym_check( 'completed status (unverified sync): not delivered, no delivered event fired', 'unverified' === YM_Lifecycle::outcome( wc_get_order( $g->get_id() ) ) && 0 === $fired );
	update_option( 'ym_status_sync_verified', 1 );
	$h = $mk( array( array( $prods[0], 1 ) ), 420 );
	wc_get_order( $h->get_id() )->update_status( 'completed' );
	ym_check( 'completed status (verified mapping): delivered, event fired once', 'delivered_kept' === YM_Lifecycle::outcome( wc_get_order( $h->get_id() ) ) && 1 === $fired );
	remove_action( 'ym_order_delivered', $count );
	delete_option( 'ym_stage_map' );
	delete_option( 'ym_status_sync_verified' );

	// 7) Unknown cost: wallet figure is null with the reason, never a silent zero.
	$k = $mk( array( array( $prods[0], 1 ) ), 480 );
	$snap = json_decode( $k->get_meta( '_ym_snapshot' ), true );
	$snap['items'][0]['taager_cost'] = null;
	$k->update_meta_data( '_ym_snapshot', wp_json_encode( $snap ) );
	$k->save();
	YM_Lifecycle::record( wc_get_order( $k->get_id() ), 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 'test' ) );
	$r = $report();
	ym_check( 'unknown cost: wallet_expected null + reason listed', null === $r['money']['wallet_expected'] && (bool) array_filter( $r['unknown'], fn( $u ) => false !== strpos( $u, 'Taager cost missing' ) ) );
	ym_check( 'unknown cost: contribution null', null === $r['money']['contribution'] );

	// 8) Maturity: these orders are a year old, so all mature; a new order is immature and gives no rate.
	ym_check( 'maturity: old orders are mature', $r['counts']['mature_orders'] === $r['counts']['orders'] );
	$fresh = YM_Report::build( time() - HOUR_IN_SECONDS, time() + 1 );
	ym_check( 'maturity: fresh orders are not mature', 0 === $fresh['counts']['mature_orders'] || $fresh['counts']['immature_orders'] > 0 );

	// 9) Ad spend unknown vs zero: zero only while ads are off for the country.
	ym_check( 'ad spend: zero while ads are off', 0.0 === $r['money']['ad_spend'] );
} else {
	ym_check( 'reporting: needs 3 seeded products with a cost', false );
}

foreach ( $made as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		foreach ( $o->get_refunds() as $rf ) {
			$rf->delete( true );
		}
		$o->delete( true );
	}
}
foreach ( $temp as $id ) {
	wp_delete_post( $id, true );
}
