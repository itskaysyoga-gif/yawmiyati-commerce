<?php
/**
 * Line-level accounting (included by run.php): partial returns by line and quantity, line and amount-only refunds,
 * supplier credits, unknown costs kept null, product totals reconciling with order totals, and contradictory events
 * staying unresolved until a person reviews them. Each order gets its own two-hour report window on a past day.
 * Temporary products with round prices and costs make the expected figures exact.
 */

$acc_made = array();
$acc_prod = array();
$acc_new  = function ( $sku, $price, $cost ) use ( &$acc_prod ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'accounting test ' . $sku );
	$p->set_sku( $sku );
	$p->set_regular_price( (string) $price );
	$p->set_status( 'publish' );
	if ( null !== $cost ) {
		$p->update_meta_data( '_ym_taager_cost', $cost );
	}
	$p->save();
	$acc_prod[] = $p->get_id();
	return wc_get_product( $p->get_id() );
};
$A = $acc_new( 'YM-ACC-A', 100, 60 ); // margin 40 a unit
$B = $acc_new( 'YM-ACC-B', 50, 20 );  // margin 30 a unit
$C = $acc_new( 'YM-ACC-C', 30, null ); // cost never recorded

$acc_day = strtotime( '2025-07-15 00:00:00 UTC' );
$acc_n   = 0;
$acc_mk  = function ( $lines ) use ( &$acc_made, &$acc_n, $acc_day ) {
	$o = wc_create_order();
	foreach ( $lines as $l ) {
		$o->add_product( $l[0], $l[1] );
	}
	$o->set_billing_country( YM_Country::code() );
	$o->calculate_totals( false );
	$o->set_date_created( $acc_day + ( ++$acc_n ) * 2 * HOUR_IN_SECONDS );
	$o->save();
	YM_Orders::snapshot( $o );
	$o->save();
	YM_Lifecycle::on_submitted( $o->get_id(), array(), $o );
	$acc_made[] = $o->get_id();
	return wc_get_order( $o->get_id() );
};
$acc_rep = function ( WC_Order $o ) {
	$t = $o->get_date_created()->getTimestamp();
	return YM_Report::build( $t - 60, $t + HOUR_IN_SECONDS );
};
$acc_ok  = fn( $a, $b ) => null !== $a && null !== $b && abs( (float) $a - (float) $b ) < 0.001;
$acc_del = fn( WC_Order $o ) => YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 't' ) );
$acc_item = function ( WC_Order $o, WC_Product $p ) {
	foreach ( $o->get_items() as $it ) {
		if ( $it->get_product_id() === $p->get_id() ) {
			return $it;
		}
	}
	return null;
};

// 1) Snapshot: an unknown cost is null (never 0), and every line carries its order item ID.
$o    = $acc_mk( array( array( $A, 1 ), array( $C, 1 ) ) );
$snap = json_decode( $o->get_meta( '_ym_snapshot' ), true );
ym_check( 'snapshot: unknown cost stored as null', array_key_exists( 'taager_cost', $snap['items'][1] ) && null === $snap['items'][1]['taager_cost'] );
ym_check( 'snapshot: known cost kept', 60.0 === (float) $snap['items'][0]['taager_cost'] );
ym_check( 'snapshot: lines carry item IDs', (int) $snap['items'][0]['item_id'] === $acc_item( $o, $A )->get_id() );
$acc_del( $o );
$r = $acc_rep( $o );
ym_check( 'unknown cost: that product\'s margin is null, not zero', null === $r['products']['YM-ACC-C']['margin'] && null === $r['products']['YM-ACC-C']['contribution_before_ads'] );
ym_check( 'unknown cost: the other product keeps its known margin', $acc_ok( $r['products']['YM-ACC-A']['margin'], 40 ) );
ym_check( 'unknown cost: order wallet figure null with reason', null === $r['money']['wallet_expected'] && (bool) array_filter( $r['unknown'], fn( $u ) => false !== strpos( $u, 'YM-ACC-C' ) ) );
// A unit of the unknown-cost product that came back: nothing kept, so its cost no longer matters.
YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'changed_mind', 'lines' => array( 'YM-ACC-C' => 1 ), 'evidence' => 't' ) );
$r = $acc_rep( $o );
ym_check( 'unknown cost returned in full: margin known again (0 kept units)', $acc_ok( $r['products']['YM-ACC-C']['margin'], 0 ) && $acc_ok( $r['money']['wallet_expected'], 40 ) );

// 2) Partial return of one line on a two-line order: only that line loses margin, by its own units.
$o = $acc_mk( array( array( $A, 1 ), array( $B, 2 ) ) );
$acc_del( $o );
YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'damaged', 'lines' => array( 'YM-ACC-B' => 1 ), 'evidence' => 't' ) );
$r = $acc_rep( $o );
ym_check( 'partial return by line: outcome delivered_partial_refund', 'delivered_partial_refund' === YM_Lifecycle::outcome( wc_get_order( $o->get_id() ) ) );
ym_check( 'partial return by line: A keeps its full margin (40)', $acc_ok( $r['products']['YM-ACC-A']['margin'], 40 ) );
ym_check( 'partial return by line: B keeps 1 of 2 units (30)', $acc_ok( $r['products']['YM-ACC-B']['margin'], 30 ) && 1 === $r['products']['YM-ACC-B']['units_returned'] && 1 === $r['products']['YM-ACC-B']['units_kept'] );
ym_check( 'partial return by line: wallet = 70', $acc_ok( $r['money']['wallet_expected'], 70 ) );
ym_check( 'partial return by line: delivered sales = 100 + 50', $acc_ok( $r['money']['delivered_sales'], 150 ) );
ym_check( 'partial return by line: reconciliation ok', true === $r['reconciliation']['ok'] );

// 3) Refunds: a line refund that matches the returned unit costs no extra margin; an amount-only refund is split by
//    line value and comes off the margin (money given back on goods she kept).
$item = $acc_item( wc_get_order( $o->get_id() ), $B );
wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 50, 'reason' => 'returned unit', 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 50 ) ) ) );
$r = $acc_rep( $o );
ym_check( 'line refund = returned unit value: wallet unchanged (70)', $acc_ok( $r['money']['wallet_expected'], 70 ) );
ym_check( 'line refund: sits on product B', $acc_ok( $r['products']['YM-ACC-B']['refunds'], 50 ) && $acc_ok( $r['products']['YM-ACC-A']['refunds'], 0 ) );
wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 20, 'reason' => 'goodwill' ) );
$r = $acc_rep( $o );
// Split by line value 100 : 100 → 10 and 10; both come off margins and delivered sales.
ym_check( 'amount-only refund split by value: A 10, B 10 + 50', $acc_ok( $r['products']['YM-ACC-A']['refunds'], 10 ) && $acc_ok( $r['products']['YM-ACC-B']['refunds'], 60 ) );
ym_check( 'amount-only refund comes off the margin: wallet 50', $acc_ok( $r['money']['wallet_expected'], 50 ) );
ym_check( 'amount-only refund reported as split by value', $acc_ok( $r['money']['refunds_split_by_value'], 20 ) && $acc_ok( $r['money']['refunds'], 70 ) );
ym_check( 'refunds: products add up to the order (reconciliation ok)', true === $r['reconciliation']['ok'] && $acc_ok( $r['reconciliation']['lines_minus_orders']['refunds'], 0 ) );

// 4) Supplier credit on one line, and one for the whole order split by value.
YM_Lifecycle::add_credit( wc_get_order( $o->get_id() ), 5, 'test wallet', 'YM-ACC-A' );
YM_Lifecycle::add_credit( wc_get_order( $o->get_id() ), 4, 'test wallet' );
$r = $acc_rep( $o );
ym_check( 'credits: A gets 5 + 2, B gets 2', $acc_ok( $r['products']['YM-ACC-A']['credits'], 7 ) && $acc_ok( $r['products']['YM-ACC-B']['credits'], 2 ) );
ym_check( 'credits: wallet 50 + 9', $acc_ok( $r['money']['wallet_expected'], 59 ) && $acc_ok( $r['money']['supplier_credits'], 9 ) );
ym_check( 'credits: products add up to the order', true === $r['reconciliation']['ok'] );
ym_check( 'credit without a source refused', is_wp_error( YM_Lifecycle::add_credit( wc_get_order( $o->get_id() ), 3, '' ) ) );

// 5) Order charge split across lines, shares add up exactly to the one charge.
YM_Lifecycle::add_charge( wc_get_order( $o->get_id() ), 'return_fee', 7, 'actual', 'test' );
$r = $acc_rep( $o );
ym_check( 'charge: allocated shares add up to the charge', $acc_ok( $r['products']['YM-ACC-A']['allocated_charges'] + $r['products']['YM-ACC-B']['allocated_charges'], 7 ) );
ym_check( 'charge: product contributions add up to the wallet figure', $acc_ok( $r['products']['YM-ACC-A']['contribution_before_ads'] + $r['products']['YM-ACC-B']['contribution_before_ads'], $r['money']['wallet_expected'] ) && $acc_ok( $r['money']['wallet_expected'], 52 ) );

// 6) A units-only return on a multi-line order cannot be placed: money unknown, never guessed.
$o = $acc_mk( array( array( $A, 1 ), array( $B, 1 ) ) );
$acc_del( $o );
YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'damaged', 'units' => 1, 'evidence' => 't' ) );
$r = $acc_rep( $o );
ym_check( 'units-only return on 2 lines: wallet null with reason', null === $r['money']['wallet_expected'] && (bool) array_filter( $r['unknown'], fn( $u ) => false !== strpos( $u, 'which lines' ) ) );
ym_check( 'units-only return on 2 lines: product margins null', null === $r['products']['YM-ACC-A']['margin'] && null === $r['products']['YM-ACC-B']['margin'] );

// 7) Returning more units than the line holds is a contradiction, kept open.
$o = $acc_mk( array( array( $B, 1 ) ) );
$acc_del( $o );
$x = YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'damaged', 'lines' => array( 'YM-ACC-B' => 1 ), 'evidence' => 't', 'at' => time() - 100 ) );
$y = YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'damaged', 'lines' => array( 'YM-ACC-B' => 1 ), 'evidence' => 't2', 'at' => time() - 50 ) );
ym_check( 'over-return: flagged return_exceeds_quantity', 'return_exceeds_quantity' === ( $y['conflict'] ?? '' ) && 'conflict' === YM_Lifecycle::outcome( wc_get_order( $o->get_id() ) ) );
ym_check( 'over-return: unknown line reference refused', is_wp_error( YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'damaged', 'lines' => array( 'NOT-ON-ORDER' => 1 ), 'evidence' => 't' ) ) ) );
YM_Lifecycle::resolve( wc_get_order( $o->get_id() ), $y['id'], 'reject', 'test', 'second report was a duplicate of the first' );
ym_check( 'over-return rejected: one return stands, returned_full', 'returned_full' === YM_Lifecycle::outcome( wc_get_order( $o->get_id() ) ) );

// 8) Delivered after a refusal: open conflict, report money unknown, then resolved both ways.
$fee   = YM_Country::rules()['refusal_fee'] ?? null;
$fired = 0;
$cnt   = function () use ( &$fired ) { $fired++; };
add_action( 'ym_order_delivered', $cnt );
$o   = $acc_mk( array( array( $A, 1 ) ) );
$ref = YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'refused', array( 'src' => 'staff', 'verified' => true, 'reason' => 'not_reachable', 'evidence' => 't', 'at' => time() - 7200 ) );
$del = YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 't', 'at' => time() - 3600 ) );
$r   = $acc_rep( wc_get_order( $o->get_id() ) );
ym_check( 'conflict: outcome stays "conflict", no delivered event', 'conflict' === YM_Lifecycle::outcome( wc_get_order( $o->get_id() ) ) && 0 === $fired );
ym_check( 'conflict: report money unknown with the order listed', null === $r['money']['wallet_expected'] && (bool) array_filter( $r['unknown'], fn( $u ) => false !== strpos( $u, 'contradictory' ) && false !== strpos( $u, (string) $o->get_id() ) ) );
ym_check( 'conflict: not counted as delivered in the funnel', 0 === $r['counts']['reached']['delivered'] );
ym_check( 'conflict: listed for review', in_array( $o->get_id(), YM_Orders::ids_by_meta( '_ym_conflict_open', '1', 0, 200 ), true ) );
ym_check( 'conflict: resolution needs a note', is_wp_error( YM_Lifecycle::resolve( wc_get_order( $o->get_id() ), $del['id'], 'accept', 'test', '' ) ) );
YM_Lifecycle::resolve( wc_get_order( $o->get_id() ), $del['id'], 'accept', 'test', 'supplier confirmed delivery; refusal was a courier error' );
$oo = wc_get_order( $o->get_id() );
ym_check( 'conflict accepted: delivered_kept, refusal voided, delivered event fired once', 'delivered_kept' === YM_Lifecycle::outcome( $oo ) && 1 === $fired && ! empty( array_values( array_filter( YM_Lifecycle::events( $oo ), fn( $e ) => 'refused' === $e['e'] ) )[0]['void'] ) );
ym_check( 'conflict accepted: refusal-fee estimate removed with the refusal', ! array_filter( YM_Lifecycle::charges( $oo ), fn( $c ) => 'refusal_fee' === $c['type'] ) );
ym_check( 'conflict accepted: no longer listed', ! in_array( $o->get_id(), YM_Orders::ids_by_meta( '_ym_conflict_open', '1', 0, 200 ), true ) );
$r = $acc_rep( $oo );
ym_check( 'conflict accepted: money known again', $acc_ok( $r['money']['wallet_expected'], 40 ) );
$o2 = $acc_mk( array( array( $A, 1 ) ) );
YM_Lifecycle::record( wc_get_order( $o2->get_id() ), 'refused', array( 'src' => 'staff', 'verified' => true, 'reason' => 'not_reachable', 'evidence' => 't', 'at' => time() - 7200 ) );
$d2 = YM_Lifecycle::record( wc_get_order( $o2->get_id() ), 'delivered', array( 'src' => 'staff', 'verified' => true, 'evidence' => 't', 'at' => time() - 3600 ) );
YM_Lifecycle::resolve( wc_get_order( $o2->get_id() ), $d2['id'], 'reject', 'test', 'supplier says still refused' );
ym_check( 'conflict rejected: refused stands, still no delivered event', 'refused' === YM_Lifecycle::outcome( wc_get_order( $o2->get_id() ) ) && 1 === $fired );
if ( null !== $fee ) {
	ym_check( 'conflict rejected: refusal fee kept', 1 === count( array_filter( YM_Lifecycle::charges( wc_get_order( $o2->get_id() ) ), fn( $c ) => 'refusal_fee' === $c['type'] ) ) );
}
remove_action( 'ym_order_delivered', $cnt );

// 9) Reviews: she received it, then returned it: she may still review. A refusal never qualifies.
$o = $acc_mk( array( array( $B, 1 ) ) );
$acc_del( $o );
wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => wc_get_order( $o->get_id() )->get_total(), 'reason' => 'full return' ) );
ym_check( 'review after full refund of a delivered order: outcome returned_full', 'returned_full' === YM_Lifecycle::outcome( wc_get_order( $o->get_id() ) ) );
ym_check( 'review after full refund of a delivered order: link allowed', ! is_wp_error( YM_Reviews::link( wc_get_order( $o->get_id() ) ) ) );
$o = $acc_mk( array( array( $B, 1 ) ) );
$acc_del( $o );
YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'returned', array( 'src' => 'staff', 'verified' => true, 'reason' => 'not_as_described', 'evidence' => 't' ) );
ym_check( 'review after a full return: link allowed', ! is_wp_error( YM_Reviews::link( wc_get_order( $o->get_id() ) ) ) );
$o = $acc_mk( array( array( $B, 1 ) ) );
YM_Lifecycle::record( wc_get_order( $o->get_id() ), 'refused', array( 'src' => 'staff', 'verified' => true, 'reason' => 'changed_mind', 'evidence' => 't' ) );
ym_check( 'review after a refusal: refused', is_wp_error( YM_Reviews::link( wc_get_order( $o->get_id() ) ) ) );

foreach ( $acc_made as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		foreach ( $o->get_refunds() as $rf ) {
			$rf->delete( true );
		}
		$o->delete( true );
	}
}
foreach ( $acc_prod as $id ) {
	wp_delete_post( $id, true );
}

// Client IP: forwarding headers count only from a configured trusted proxy.
$srv = array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 198.51.100.3' );
delete_option( 'ym_trusted_proxies' );
ym_check( 'client IP: no trusted proxy → headers ignored', '203.0.113.9' === YM_Net::client_ip( $srv ) );
update_option( 'ym_trusted_proxies', '10.0.0.0/8, 2001:db8::/32' );
ym_check( 'client IP: untrusted sender → headers ignored', '203.0.113.9' === YM_Net::client_ip( $srv ) );
$srv['REMOTE_ADDR'] = '10.1.2.3';
ym_check( 'client IP: trusted proxy → right-most untrusted X-Forwarded-For hop', '198.51.100.3' === YM_Net::client_ip( $srv ) );
ym_check( 'client IP: proxy chain skips our own proxies', '198.51.100.2' === YM_Net::client_ip( array_merge( $srv, array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 10.9.9.9' ) ) ) );
ym_check( 'client IP: spoofed left-most entry never used', '198.51.100.3' === YM_Net::client_ip( array_merge( $srv, array( 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.3' ) ) ) );
ym_check( 'client IP: malformed chain → proxy address', '10.1.2.3' === YM_Net::client_ip( array_merge( $srv, array( 'HTTP_X_FORWARDED_FOR' => 'nonsense' ) ) ) );
update_option( 'ym_client_ip_header', 'cf-connecting-ip' );
ym_check( 'client IP: CF-Connecting-IP from a trusted proxy', '198.51.100.1' === YM_Net::client_ip( $srv ) );
ym_check( 'client IP: IPv6 proxy range', '198.51.100.1' === YM_Net::client_ip( array_merge( $srv, array( 'REMOTE_ADDR' => '2001:db8::7' ) ) ) );
ym_check( 'client IP: CIDR edge', YM_Net::in_range( '10.255.255.255', '10.0.0.0/8' ) && ! YM_Net::in_range( '11.0.0.0', '10.0.0.0/8' ) && YM_Net::in_range( '192.168.1.130', '192.168.1.128/25' ) && ! YM_Net::in_range( '192.168.1.127', '192.168.1.128/25' ) );
delete_option( 'ym_trusted_proxies' );
delete_option( 'ym_client_ip_header' );

// Atomic counters: positions 1, 2, 3 in order; another key starts at 1.
$k = 'it-' . wp_generate_password( 8, false );
ym_check( 'rate counter: consecutive positions', array( 1, 2, 3 ) === array( YM_DB::hit( 'test', $k ), YM_DB::hit( 'test', $k ), YM_DB::hit( 'test', $k ) ) && 1 === YM_DB::hit( 'test', $k . 'x' ) );

// Shared consent ledger: configured locally, a withdrawal is readable, and later withdrawals move the time forward.
$subj = bin2hex( random_bytes( 16 ) );
ym_check( 'consent ledger configured', YM_Consent::configured() );
ym_check( 'consent ledger: never withdrawn = 0', 0 === YM_Consent::withdrawn_at( $subj ) );
YM_Consent::record( $subj, 1000 );
YM_Consent::record( $subj, 900 );
ym_check( 'consent ledger: keeps the latest withdrawal', 1000 === YM_Consent::withdrawn_at( $subj ) );
ym_check( 'consent: a written withdrawal leaves nothing queued here', ! isset( YM_Consent::pending()[ $subj ] ) );

// Write fails while reads work (29 Oct brief §3): the withdrawal stays queued here, this store stops certifying,
// and every other store waits for its certificate. Flushing restores both.
update_option( 'ym_test_ledger_readonly', 1 );
$s2 = bin2hex( random_bytes( 16 ) );
ym_check( 'consent read-only: record reports failure', false === YM_Consent::record( $s2 ) );
ym_check( 'consent read-only: still readable from the ledger', 0 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . YM_Consent::table() . ' WHERE subject = %s', $s2 ) ) && null !== YM_Consent::withdrawn_at( $subj ) );
ym_check( 'consent read-only: queued here, in force here', isset( YM_Consent::pending()[ $s2 ] ) && YM_Consent::withdrawn_at( $s2 ) > 0 );
$before = YM_Consent::certificates()[ $cc ] ?? 0;
ym_check( 'consent read-only: this store cannot certify', false === YM_Consent::certify() && ( YM_Consent::certificates()[ $cc ] ?? 0 ) === $before );
ym_check( 'consent read-only: an event decided now must wait', 0 === strpos( YM_Consent::clearance( str_repeat( 'a', 32 ), time() - 60, time() ), 'wait:' ) );
delete_option( 'ym_test_ledger_readonly' );
ym_check( 'consent: certify flushes the queue, then certifies', YM_Consent::certify() && ! isset( YM_Consent::pending()[ $s2 ] ) && ( YM_Consent::certificates()[ $cc ] ?? 0 ) >= $before );
ym_check( 'consent: the flushed withdrawal is in the ledger', YM_Consent::withdrawn_at( $s2 ) > 0 );
// Queue updates are atomic upserts: the later time wins whatever order they arrive in.
update_option( 'ym_test_ledger_readonly', 1 );
$s3 = bin2hex( random_bytes( 16 ) );
YM_Consent::record( $s3, 5000 );
YM_Consent::record( $s3, 4000 );
ym_check( 'consent queue keeps the latest time', 5000 === (int) ( YM_Consent::pending()[ $s3 ] ?? 0 ) );
delete_option( 'ym_test_ledger_readonly' );
YM_Consent::certify();
// Backup and restore: an export merges back; an older backup never shortens or removes a withdrawal.
$exp = YM_Consent::export();
ym_check( 'consent export holds the ledger', is_array( $exp ) && in_array( $s3, wp_list_pluck( $exp['ledger'], 'subject' ), true ) );
YM_Consent::record( $s3, 6000 );
ym_check( 'consent import of an older backup keeps the newer withdrawal', false !== YM_Consent::import( $exp ) && 6000 === YM_Consent::withdrawn_at( $s3 ) );
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . YM_Consent::table() . ' WHERE subject = %s', $s2 ) );
ym_check( 'consent import restores a lost withdrawal', YM_Consent::import( $exp ) >= 1 && YM_Consent::withdrawn_at( $s2 ) > 0 );
ym_check( 'consent import refuses other data', false === YM_Consent::import( array( 'ledger' => array() ) ) );
// Migration: withdrawals queued by the previous version (an option) move into the table.
$s4 = bin2hex( random_bytes( 16 ) );
update_option( 'ym_consent_pending', array( $s4 => 1234 ), false );
YM_Consent::install();
ym_check( 'consent: old queued option migrated', 1234 === (int) ( YM_Consent::pending()[ $s4 ] ?? 0 ) && false === get_option( 'ym_consent_pending' ) );
YM_Consent::certify();
ym_check( 'consent: migrated withdrawal flushed to the ledger', YM_Consent::withdrawn_at( $s4 ) >= 1234 && ! isset( YM_Consent::pending()[ $s4 ] ) );

// Supplier sweep looks only at hand-offs that are pending or unconfirmed (not every processing order).
$sw = wc_create_order();
$sw->set_status( 'processing' );
$sw->set_date_created( time() - HOUR_IN_SECONDS );
$sw->update_meta_data( '_ym_supplier_status', 'sent' );
$sw->save();
$cands = YM_Ops::sweep_candidates();
$bad   = array_filter( $cands, fn( $id ) => ! in_array( wc_get_order( $id )->get_meta( '_ym_supplier_status' ), array( 'pending', 'unconfirmed' ), true ) || 'processing' !== wc_get_order( $id )->get_status() );
ym_check( 'supplier sweep: candidates are only processing orders with a pending/unconfirmed hand-off', ! $bad && ! in_array( $sw->get_id(), $cands, true ) );
YM_Ops::run_supplier_sweep();
ym_check( 'supplier sweep: re-checks exactly the candidates', count( $cands ) === (int) ( get_option( 'ym_ops_runs' )['supplier_sweep']['summary']['checked'] ?? -1 ) );
$sw->delete( true );
