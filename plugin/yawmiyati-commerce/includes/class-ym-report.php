<?php
/**
 * Country report from order history (YM_Lifecycle). Spec: docs/REPORTING.md.
 *
 * Counting: an order is counted once however many products it holds. Products are counted per order that contains
 * them, plus lines and units separately.
 *
 * Maturity: delivery takes days, so rates (delivered, refused, returned) are computed only on orders older than
 * ym_cohort_maturity_days (default 14). Younger orders are reported as "not yet mature", never as failures.
 *
 * Money, kept apart:
 *   submitted_value       product value of every submitted order (delivery fees shown separately)
 *   delivered_sales       product value of delivered orders, less refunds on them
 *   refunds               money given back (partial + full)
 *   wallet_expected       our margin on delivered-and-kept units (price − Taager cost), less order charges
 *   wallet_actual         wallet credits recorded from Taager (wp ym money wallet …)
 *   paid_out              money that actually reached the payout account (wp ym money payout …)
 *   ad_spend              recorded spend (wp ym money spend …)
 *   contribution          wallet_expected − payout fees − ad spend
 * Any input that is not known (a missing cost, a refusal fee nobody published, no spend recorded while ads are
 * enabled) makes the dependent figure null, with the reason listed under "unknown". A partial figure is given as a
 * labelled bound, never as the answer.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Report {

	public static function maturity_days() {
		return max( 1, (int) get_option( 'ym_cohort_maturity_days', 14 ) );
	}

	/**
	 * @param int  $from      Unix time, orders created at or after
	 * @param int  $to        Unix time, orders created before
	 * @param bool $by_product include the per-product table
	 */
	public static function build( $from, $to, $by_product = true ) {
		$cc      = YM_Country::code();
		$mature  = time() - self::maturity_days() * DAY_IN_SECONDS;
		$ids     = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'date_created' => $from . '...' . ( $to - 1 ) ) );
		$unknown = array();
		$t       = array(
			'orders' => 0, 'lines' => 0, 'units' => 0, 'test_orders_excluded' => 0,
			'mature_orders' => 0, 'immature_orders' => 0,
			'reached' => array_fill_keys( YM_Lifecycle::CHAIN, 0 ),
			'reached_mature' => array_fill_keys( YM_Lifecycle::CHAIN, 0 ),
			'outcomes' => array(), 'outcomes_mature' => array(),
			'unverified_status_events' => 0,
		);
		$money = array( 'submitted_value' => 0.0, 'delivery_fees_charged' => 0.0, 'delivered_sales' => 0.0, 'refunds' => 0.0, 'margin_delivered' => 0.0, 'charges_known' => 0.0 );
		$cost_missing    = array();
		$charges_unknown = 0;
		$products        = array();

		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			if ( $o->get_meta( '_ym_test' ) || $o->get_meta( '_ym_dev_seed' ) ) {
				++$t['test_orders_excluded'];
				continue;
			}
			$snap = json_decode( (string) $o->get_meta( '_ym_snapshot' ), true );
			if ( ! $snap ) {
				continue;
			}
			$ev       = YM_Lifecycle::events( $o );
			$outcome  = YM_Lifecycle::outcome( $o );
			$reached  = YM_Lifecycle::reached( $ev );
			$is_old   = $o->get_date_created() && $o->get_date_created()->getTimestamp() < $mature;
			$refunded = (float) $o->get_total_refunded();
			++$t['orders'];
			$t[ $is_old ? 'mature_orders' : 'immature_orders' ]++;
			$t['outcomes'][ $outcome ] = ( $t['outcomes'][ $outcome ] ?? 0 ) + 1;
			if ( $is_old ) {
				$t['outcomes_mature'][ $outcome ] = ( $t['outcomes_mature'][ $outcome ] ?? 0 ) + 1;
			}
			foreach ( $reached as $s => $yes ) {
				$t['reached'][ $s ] += $yes ? 1 : 0;
				if ( $is_old ) {
					$t['reached_mature'][ $s ] += $yes ? 1 : 0;
				}
			}
			$t['unverified_status_events'] += count( array_filter( $ev, fn( $e ) => empty( $e['verified'] ) ) );

			$subtotal = 0.0;
			foreach ( $snap['items'] as $it ) {
				$subtotal += (float) $it['line_total'];
			}
			$money['submitted_value']       += $subtotal;
			$money['delivery_fees_charged'] += (float) ( $snap['delivery_fee'] ?? 0 );
			$money['refunds']               += $refunded;
			$delivered = in_array( $outcome, array( 'delivered_kept', 'delivered_partial_refund' ), true );
			if ( $delivered ) {
				$money['delivered_sales'] += $subtotal - $refunded;
			}

			// Order-level charges: once per order, never per line.
			$order_charges = 0.0;
			foreach ( YM_Lifecycle::charges( $o ) as $c ) {
				if ( null === $c['amount'] ) {
					++$charges_unknown;
				} else {
					$order_charges += (float) $c['amount'];
				}
			}
			$money['charges_known'] += $order_charges;

			// Margin on delivered-and-kept units. Returned units carry no margin; a partial money refund is taken off.
			$order_margin = 0.0;
			$kept_ratio   = self::kept_ratio( $o, $ev );
			foreach ( $snap['items'] as $it ) {
				$sku  = $it['sku'] ? $it['sku'] : (string) $it['product_id'];
				$cost = $it['taager_cost'];
				if ( $delivered ) {
					if ( null === $cost || '' === $cost ) {
						$cost_missing[ $sku ] = true;
					} else {
						$order_margin += ( (float) $it['line_total'] - (float) $cost * (int) $it['qty'] ) * $kept_ratio;
					}
				}
				if ( ! $by_product ) {
					continue;
				}
				$row = $products[ $sku ] ?? array( 'name' => $it['name'], 'orders' => 0, 'lines' => 0, 'units' => 0, 'orders_delivered' => 0, 'units_delivered' => 0, 'submitted_value' => 0.0, 'delivered_value' => 0.0, 'margin' => 0.0, 'allocated_charges' => 0.0, '_seen' => array() );
				if ( empty( $row['_seen'][ $id ] ) ) {
					++$row['orders'];
					$row['_seen'][ $id ] = true;
					if ( $delivered ) {
						++$row['orders_delivered'];
					}
				}
				++$row['lines'];
				$row['units']           += (int) $it['qty'];
				$row['submitted_value'] += (float) $it['line_total'];
				if ( $delivered ) {
					$row['units_delivered'] += (int) $it['qty'];
					$row['delivered_value'] += (float) $it['line_total'] * $kept_ratio;
					if ( null !== $cost && '' !== $cost ) {
						$row['margin'] += ( (float) $it['line_total'] - (float) $cost * (int) $it['qty'] ) * $kept_ratio;
					}
				}
				// Allocation of this order's charges: share of the order's product value (line_value_share).
				if ( $order_charges && $subtotal > 0 ) {
					$row['allocated_charges'] += $order_charges * (float) $it['line_total'] / $subtotal;
				}
				$products[ $sku ] = $row;
			}
			$partial_money = 'delivered_partial_refund' === $outcome && 1.0 === $kept_ratio ? $refunded : 0.0;
			$money['margin_delivered'] += $order_margin - $partial_money;
			$t['lines'] += count( $snap['items'] );
			$t['units'] += array_sum( array_map( fn( $i ) => (int) $i['qty'], $snap['items'] ) );
		}

		foreach ( $products as &$row ) {
			unset( $row['_seen'] );
			$row['contribution_before_ads'] = round( $row['margin'] - $row['allocated_charges'], 3 );
		}
		unset( $row );

		// Money outside WooCommerce: recorded entries for the period.
		$ledger = self::ledger( $cc, $from, $to );

		if ( $cost_missing ) {
			$unknown[] = 'Taager cost missing for: ' . implode( ', ', array_keys( $cost_missing ) );
		}
		if ( $charges_unknown ) {
			$unknown[] = $charges_unknown . ' order charge(s) with no known amount (e.g. a refusal fee the supplier has not published for ' . $cc . ')';
		}
		$payout_fee = $ledger['payout_fees'];
		if ( null === $payout_fee ) {
			$unknown[] = 'payout fees: none recorded for this period and no fee rate set (ym_payout_fee_rate)';
		}
		$spend = $ledger['spend'];
		if ( null === $spend ) {
			$unknown[] = 'ad spend: advertising is enabled for ' . $cc . ' but no spend is recorded for this period';
		}

		$wallet_expected = ( $cost_missing || $charges_unknown ) ? null : $money['margin_delivered'] - $money['charges_known'];
		$contribution    = ( null === $wallet_expected || null === $payout_fee || null === $spend ) ? null : $wallet_expected - $payout_fee - $spend;

		$rate = function ( $n, $d ) {
			return $d ? round( $n / $d, 3 ) : null;
		};
		$m = $t['mature_orders'];
		$out = array(
			'country'           => $cc,
			'currency'          => get_woocommerce_currency(),
			'period'            => array( 'from' => gmdate( 'c', $from ), 'to' => gmdate( 'c', $to ) ),
			'generated'         => gmdate( 'c' ),
			'maturity_days'     => self::maturity_days(),
			'status_sync_verified' => YM_Lifecycle::status_sync_verified(),
			'counts'            => $t,
			'rates_mature'      => array(
				'basis'          => $m . ' mature orders (older than ' . self::maturity_days() . ' days)',
				'confirmed'      => $rate( $t['reached_mature']['confirmed'], $m ),
				'shipped'        => $rate( $t['reached_mature']['shipped'], $m ),
				'delivered'      => $rate( $t['reached_mature']['delivered'], $m ),
				'delivered_kept' => $rate( $t['outcomes_mature']['delivered_kept'] ?? 0, $m ),
				'refused'        => $rate( $t['outcomes_mature']['refused'] ?? 0, $m ),
				'returned'       => $rate( ( $t['outcomes_mature']['returned_full'] ?? 0 ) + ( $t['outcomes_mature']['delivered_partial_refund'] ?? 0 ), $m ),
				'cancelled'      => $rate( ( $t['outcomes_mature']['cancelled_before_handoff'] ?? 0 ) + ( $t['outcomes_mature']['cancelled_after_shipment'] ?? 0 ), $m ),
			),
			'money'             => array(
				'submitted_value'       => self::r( $money['submitted_value'] ),
				'delivery_fees_charged' => self::r( $money['delivery_fees_charged'] ),
				'delivered_sales'       => self::r( $money['delivered_sales'] ),
				'refunds'               => self::r( $money['refunds'] ),
				'order_charges_known'   => self::r( $money['charges_known'] ),
				'wallet_expected'       => null === $wallet_expected ? null : self::r( $wallet_expected ),
				'wallet_expected_bound' => null === $wallet_expected ? array( 'upper_bound_excluding_unknowns' => self::r( $money['margin_delivered'] - $money['charges_known'] ) ) : null,
				'wallet_actual'         => $ledger['wallet'],
				'paid_out'              => $ledger['payout'],
				'payout_fees'           => $payout_fee,
				'ad_spend'              => $spend,
				'contribution'          => null === $contribution ? null : self::r( $contribution ),
			),
			'allocation'        => 'line_value_share: an order-level charge is split across its products in proportion to each line\'s product value',
			'unknown'           => $unknown,
		);
		if ( $by_product ) {
			ksort( $products );
			$out['products'] = $products;
		}
		return $out;
	}

	/** Share of the order's units still with the customer (1 unless a verified return names units). */
	private static function kept_ratio( WC_Order $o, $events ) {
		$total = max( 1, (int) $o->get_item_count() );
		$back  = 0;
		foreach ( $events as $e ) {
			if ( 'returned' === $e['e'] && ! empty( $e['verified'] ) ) {
				$back += (int) ( $e['units'] ?? $total );
			}
			if ( 'refund_full' === $e['e'] && ! empty( $e['verified'] ) ) {
				return 0.0;
			}
		}
		return max( 0.0, ( $total - min( $back, $total ) ) / $total );
	}

	private static function r( $v ) {
		return round( (float) $v, YM_Country::decimals() );
	}

	/* ---------- money recorded outside WooCommerce ---------- */

	const LEDGER_KINDS = array( 'wallet', 'payout', 'payout_fee', 'spend' );

	/** Record a ledger entry (wp ym money …). Each needs a source; nothing is estimated. */
	public static function add_entry( $kind, $amount, $date, $source, $note = '', $sku = '' ) {
		if ( ! in_array( $kind, self::LEDGER_KINDS, true ) ) {
			return new WP_Error( 'ym_ledger', 'kind must be one of ' . implode( ', ', self::LEDGER_KINDS ) );
		}
		if ( '' === trim( (string) $source ) ) {
			return new WP_Error( 'ym_ledger_source', 'Every money entry needs its source (e.g. "Taager wallet statement 2026-11-03", "Meta Ads Manager export").' );
		}
		$ts = strtotime( (string) $date . ' 12:00:00 UTC' );
		if ( ! $ts ) {
			return new WP_Error( 'ym_ledger_date', 'Date must be YYYY-MM-DD.' );
		}
		$list   = (array) get_option( 'ym_ledger', array() );
		$list[] = array( 'kind' => $kind, 'amount' => (float) $amount, 'date' => gmdate( 'Y-m-d', $ts ), 'ts' => $ts, 'source' => substr( (string) $source, 0, 200 ), 'note' => substr( (string) $note, 0, 200 ), 'sku' => (string) $sku, 'rec' => time() );
		update_option( 'ym_ledger', $list, false );
		return end( $list );
	}

	public static function ledger( $cc, $from, $to ) {
		$sum = array( 'wallet' => null, 'payout' => null, 'payout_fee' => null, 'spend' => null );
		foreach ( (array) get_option( 'ym_ledger', array() ) as $e ) {
			if ( $e['ts'] >= $from && $e['ts'] < $to ) {
				$sum[ $e['kind'] ] = ( $sum[ $e['kind'] ] ?? 0 ) + (float) $e['amount'];
			}
		}
		// No spend recorded is zero only when advertising is switched off for this country.
		if ( null === $sum['spend'] && ! YM_Markets::is_on( $cc, 'ads' ) ) {
			$sum['spend'] = 0.0;
		}
		// Payout fees: recorded, or derived from a configured rate on money paid out.
		$rate = get_option( 'ym_payout_fee_rate', '' );
		if ( null === $sum['payout_fee'] && '' !== $rate && null !== $sum['payout'] ) {
			$sum['payout_fee'] = round( (float) $sum['payout'] * (float) $rate, 3 );
		}
		if ( null === $sum['payout_fee'] && null === $sum['payout'] ) {
			$sum['payout_fee'] = 0.0; // nothing paid out yet in the period, so no payout fee
		}
		return array( 'wallet' => $sum['wallet'], 'payout' => $sum['payout'], 'payout_fees' => $sum['payout_fee'], 'spend' => $sum['spend'] );
	}
}
