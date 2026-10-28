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
 *   delivered_sales       product value of the units delivered and kept, less money refunded beyond returned goods
 *   refunds               product money given back (delivery-fee refunds apart); amount-only refunds split by value
 *   supplier_credits      money Taager credited back (wp ym credit)
 *   wallet_expected       margin on kept units (net price − Taager cost), less excess refunds, plus credits, less
 *                         order charges, all computed per order line
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
		$money = array( 'submitted_value' => 0.0, 'delivery_fees_charged' => 0.0, 'delivered_sales' => 0.0, 'refunds' => 0.0, 'delivery_refunds' => 0.0, 'refunds_allocated' => 0.0, 'credits' => 0.0, 'margin_delivered' => 0.0, 'charges_known' => 0.0 );
		$cost_missing    = array();
		$charges_unknown = 0;
		$conflicts       = array();
		$lines_unknown   = array();
		$products        = array();
		// Reconciliation: the same money summed from the order's own records and from its lines must agree.
		$rec = array( 'order' => array( 'refunds' => 0.0, 'credits' => 0.0, 'charges' => 0.0, 'value' => 0.0 ), 'lines' => array( 'refunds' => 0.0, 'credits' => 0.0, 'charges' => 0.0, 'value' => 0.0 ) );

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
			$ev      = YM_Lifecycle::events( $o );
			$outcome = YM_Lifecycle::outcome( $o );
			$reached = YM_Lifecycle::reached( $ev );
			$is_old  = $o->get_date_created() && $o->get_date_created()->getTimestamp() < $mature;
			$m       = YM_Lifecycle::money_lines( $o );
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
			if ( $m['conflict'] ) {
				$conflicts[] = $id;
			}
			if ( in_array( 'a return did not say which lines came back', $m['unknown'], true ) ) {
				$lines_unknown[] = $id;
			}

			$money['submitted_value']       += $m['order']['value'];
			$money['delivery_fees_charged'] += (float) ( $snap['delivery_fee'] ?? 0 );
			$money['refunds']               += $m['order']['product_refunds'];
			$money['delivery_refunds']      += $m['order']['delivery_refunds'];
			$money['refunds_allocated']     += $m['order']['refunds_allocated'];
			$money['credits']               += $m['order']['credits'];
			if ( null === $m['order']['charges'] ) {
				++$charges_unknown;
			} else {
				$money['charges_known'] += $m['order']['charges'];
			}
			$rec['order']['refunds'] += $m['order']['product_refunds'];
			$rec['order']['credits'] += $m['order']['credits'];
			$rec['order']['charges'] += (float) $m['order']['charges'];
			$rec['order']['value']   += (float) $o->get_subtotal() - (float) $o->get_discount_total();

			$seen = array();
			foreach ( $m['lines'] as $l ) {
				$money['delivered_sales'] += $l['delivered_sales'];
				if ( $l['cost_unknown'] ) {
					$cost_missing[ $l['sku'] ] = true;
				}
				if ( null !== $l['margin'] ) {
					$money['margin_delivered'] += $l['margin'];
				}
				$rec['lines']['refunds'] += $l['refunds'];
				$rec['lines']['credits'] += $l['credits'];
				$rec['lines']['charges'] += (float) $l['charges'];
				$rec['lines']['value']   += $l['value'];
				if ( ! $by_product ) {
					continue;
				}
				$row = $products[ $l['sku'] ] ?? array( 'name' => $l['name'], 'orders' => 0, 'lines' => 0, 'units' => 0, 'orders_delivered' => 0, 'units_delivered' => 0, 'units_returned' => 0, 'units_kept' => 0, 'submitted_value' => 0.0, 'delivered_value' => 0.0, 'refunds' => 0.0, 'credits' => 0.0, 'margin' => 0.0, 'allocated_charges' => 0.0, 'contribution_before_ads' => 0.0, 'unknown' => array() );
				if ( empty( $seen[ $l['sku'] ] ) ) {
					++$row['orders'];
					$row['orders_delivered'] += $m['delivered'] ? 1 : 0;
					$seen[ $l['sku'] ] = true;
				}
				++$row['lines'];
				$row['units']           += $l['qty'];
				$row['units_delivered'] += $l['delivered_units'];
				$row['units_returned']  += $l['returned_units'];
				$row['units_kept']      += $l['kept_units'];
				$row['submitted_value'] += $l['value'];
				$row['delivered_value'] += $l['delivered_sales'];
				$row['refunds']         += $l['refunds'];
				$row['credits']         += $l['credits'];
				// Unknown stays unknown: one unknown input makes the product's figure null, with the reason.
				if ( null === $l['margin'] ) {
					$row['margin']                  = null;
					$row['contribution_before_ads'] = null;
					$row['unknown'][]               = $m['conflict'] ? 'order ' . $id . ': contradictory events awaiting review' : ( $l['cost_unknown'] ? 'Taager cost missing' : 'order ' . $id . ': return lines unknown' );
				} elseif ( null !== $row['margin'] ) {
					$row['margin'] += $l['margin'];
				}
				if ( null === $l['charges'] ) {
					$row['allocated_charges']       = null;
					$row['contribution_before_ads'] = null;
					$row['unknown'][]               = 'order ' . $id . ': a charge with no known amount';
				} elseif ( null !== $row['allocated_charges'] ) {
					$row['allocated_charges'] += $l['charges'];
				}
				if ( null !== $row['contribution_before_ads'] && null !== $l['contribution'] ) {
					$row['contribution_before_ads'] += $l['contribution'];
				}
				$row['unknown']     = array_values( array_unique( $row['unknown'] ) );
				$products[ $l['sku'] ] = $row;
			}
			$t['lines'] += count( $m['lines'] );
			$t['units'] += array_sum( array_column( $m['lines'], 'qty' ) );
		}

		foreach ( $products as &$row ) {
			foreach ( array( 'submitted_value', 'delivered_value', 'refunds', 'credits', 'margin', 'allocated_charges', 'contribution_before_ads' ) as $k ) {
				$row[ $k ] = null === $row[ $k ] ? null : round( $row[ $k ], 6 ); // fine enough that shares still add up
			}
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
		if ( $conflicts ) {
			$unknown[] = count( $conflicts ) . ' order(s) with contradictory events awaiting review (wp ym conflict list): ' . implode( ', ', $conflicts );
		}
		if ( $lines_unknown ) {
			$unknown[] = count( $lines_unknown ) . ' return(s) that did not say which lines came back: ' . implode( ', ', $lines_unknown );
		}
		$payout_fee = $ledger['payout_fees'];
		if ( null === $payout_fee ) {
			$unknown[] = 'payout fees: none recorded for this period and no fee rate set (ym_payout_fee_rate)';
		}
		$spend = $ledger['spend'];
		if ( null === $spend ) {
			$unknown[] = 'ad spend: advertising is enabled for ' . $cc . ' but no spend is recorded for this period';
		}

		$wallet_expected = ( $cost_missing || $charges_unknown || $conflicts || $lines_unknown ) ? null : $money['margin_delivered'] - $money['charges_known'];
		$contribution    = ( null === $wallet_expected || null === $payout_fee || null === $spend ) ? null : $wallet_expected - $payout_fee - $spend;

		// Products against orders: every figure split across lines must add back to the order's own figure.
		$diff = array();
		foreach ( array( 'value', 'refunds', 'credits', 'charges' ) as $k ) {
			$diff[ $k ] = round( $rec['lines'][ $k ] - $rec['order'][ $k ], 6 );
		}
		$sum_products = array( 'delivered_value' => 0.0, 'contribution_before_ads' => 0.0 );
		foreach ( $products as $row ) {
			$sum_products['delivered_value'] += (float) $row['delivered_value'];
			$sum_products['contribution_before_ads'] += (float) $row['contribution_before_ads'];
		}
		$reconciliation = array(
			'lines_minus_orders' => $diff,
			'products_delivered_value_minus_report' => $by_product ? round( $sum_products['delivered_value'] - $money['delivered_sales'], 3 ) : null,
			'products_contribution_minus_wallet_expected' => ( $by_product && null !== $wallet_expected ) ? round( $sum_products['contribution_before_ads'] - $wallet_expected, 3 ) : null,
		);
		$reconciliation['ok'] = ! array_filter( $diff, fn( $d ) => abs( $d ) > 0.001 )
			&& ( null === $reconciliation['products_delivered_value_minus_report'] || abs( $reconciliation['products_delivered_value_minus_report'] ) <= 0.01 )
			&& ( null === $reconciliation['products_contribution_minus_wallet_expected'] || abs( $reconciliation['products_contribution_minus_wallet_expected'] ) <= 0.01 );
		if ( ! $reconciliation['ok'] ) {
			$unknown[] = 'reconciliation failed: product lines do not add up to the orders (see reconciliation); figures are not reliable';
			$wallet_expected = null;
			$contribution    = null;
		}

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
				'refunds_split_by_value'=> self::r( $money['refunds_allocated'] ),
				'delivery_fee_refunds'  => self::r( $money['delivery_refunds'] ),
				'supplier_credits'      => self::r( $money['credits'] ),
				'order_charges_known'   => self::r( $money['charges_known'] ),
				'wallet_expected'       => null === $wallet_expected ? null : self::r( $wallet_expected ),
				'wallet_expected_bound' => null === $wallet_expected ? array( 'known_part_only' => self::r( $money['margin_delivered'] - $money['charges_known'] ), 'note' => 'sum of the figures that are known; not an estimate of the total' ) : null,
				'wallet_actual'         => $ledger['wallet'],
				'paid_out'              => $ledger['payout'],
				'payout_fees'           => $payout_fee,
				'ad_spend'              => $spend,
				'contribution'          => null === $contribution ? null : self::r( $contribution ),
			),
			'allocation'        => 'line_value_share: order-level charges, credits and amount-only refunds are split across the order\'s lines in proportion to each line\'s product value; returns, line refunds and line credits stay on their own line',
			'reconciliation'    => $reconciliation,
			'unknown'           => $unknown,
		);
		if ( $by_product ) {
			ksort( $products );
			$out['products'] = $products;
		}
		return $out;
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
