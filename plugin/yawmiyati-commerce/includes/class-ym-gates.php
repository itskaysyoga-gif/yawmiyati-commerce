<?php
/**
 * Kill-gates on observed contribution (29 Oct brief §6; replaces "pause below 35 % kept"). Spec: docs/PRICING.md.
 *
 * A kept rate alone says nothing about money: 35 % kept can pay on a high-margin offer and 60 % can lose on a thin
 * one. The decision uses what the mature cohort actually earned per SUBMITTED order after its own ad spend, with an
 * 80 % band from the kept rate's Wilson interval (small cohorts are directional, not proof):
 *
 *   n < 15 mature submitted orders            too_early   (directional only; the spend gates in ads still apply)
 *   money unknown (cost, charge or spend)     unknown     unless even the optimistic bound is below zero -> pause
 *   whole band below zero                     pause
 *   whole band above zero                     keep        (scale only with Rashid's yes on a new budget)
 *   band crosses zero, n < 60                 test        (same budget, no increase)
 *   band crosses zero, n >= 60, middle < 0    pause
 *   band crosses zero, n >= 60, middle >= 0   test
 *
 * Break-even CPA from the research scenarios is a CEILING before omitted costs and profit, never a bid.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Gates {

	const Z = 1.2816; // 80 % two-sided

	/** Wilson score interval for k successes in n trials. */
	public static function wilson( $k, $n, $z = self::Z ) {
		if ( $n <= 0 ) {
			return array( 0.0, 1.0 );
		}
		$p   = $k / $n;
		$d   = 1 + $z * $z / $n;
		$c   = $p + $z * $z / ( 2 * $n );
		$m   = $z * sqrt( $p * ( 1 - $p ) / $n + $z * $z / ( 4 * $n * $n ) );
		return array( max( 0.0, ( $c - $m ) / $d ), min( 1.0, ( $c + $m ) / $d ) );
	}

	/**
	 * $n submitted mature orders, $kept delivered-and-kept, $before_ads contribution before ads of the cohort (null =
	 * unknown), $spend ad spend on this offer for the cohort (null = unknown), $margin per kept order and $refusal_fee
	 * per order not kept (for the band).
	 */
	public static function decide( $n, $kept, $before_ads, $spend, $margin, $refusal_fee ) {
		$out = array( 'n' => (int) $n, 'kept' => (int) $kept );
		if ( $n < 15 ) {
			return $out + array( 'decision' => 'too_early', 'why' => 'fewer than 15 mature orders: directional only' );
		}
		list( $lo, $hi ) = self::wilson( $kept, $n );
		$per = fn( $p ) => $p * $margin - ( 1 - $p ) * $refusal_fee; // contribution before ads per submitted order
		$ads = null === $spend ? null : $spend / $n;
		$out += array( 'kept_rate' => round( $kept / $n, 3 ), 'kept_band' => array( round( $lo, 3 ), round( $hi, 3 ) ) );
		if ( null === $before_ads || null === $ads ) {
			$best = $per( $hi ) - ( null === $ads ? 0 : $ads );
			if ( null !== $ads && $best < 0 ) {
				return $out + array( 'decision' => 'pause', 'why' => 'even the optimistic bound loses money', 'band' => array( null, round( $best, 2 ) ) );
			}
			return $out + array( 'decision' => 'unknown', 'why' => 'money not known yet (cost, charge or ad spend): record it before deciding' );
		}
		$mid  = $before_ads / $n - $ads;
		$band = array( $per( $lo ) - $ads, $per( $hi ) - $ads );
		$out += array( 'contribution_per_order' => round( $mid, 2 ), 'band' => array( round( $band[0], 2 ), round( $band[1], 2 ) ) );
		if ( $band[1] < 0 ) {
			return $out + array( 'decision' => 'pause', 'why' => 'the whole band is below zero' );
		}
		if ( $band[0] > 0 ) {
			return $out + array( 'decision' => 'keep', 'why' => 'the whole band is above zero; more budget only with Rashid\'s yes' );
		}
		if ( $n >= 60 && $mid < 0 ) {
			return $out + array( 'decision' => 'pause', 'why' => '60 or more orders and still losing in the middle of the band' );
		}
		return $out + array( 'decision' => 'test', 'why' => 'band crosses zero: same budget, no increase' );
	}

	/** Every product of the mature cohort (last 90 days, older than the maturity window), with its gate decision. */
	public static function all() {
		$now  = time();
		$from = $now - 90 * DAY_IN_SECONDS;
		$to   = $now - YM_Report::maturity_days() * DAY_IN_SECONDS;
		$rep  = YM_Report::build( $from, $to );
		$fee  = YM_Country::rules()['refusal_fee'];
		$out  = array();
		foreach ( (array) ( $rep['products'] ?? array() ) as $sku => $row ) {
			$id     = wc_get_product_id_by_sku( $sku );
			$p      = $id ? wc_get_product( $id ) : null;
			$cost   = $p ? (float) $p->get_meta( '_ym_taager_cost' ) : 0;
			$spend  = null;
			foreach ( (array) get_option( 'ym_ledger', array() ) as $e ) {
				if ( 'spend' === $e['kind'] && ( $e['sku'] ?? '' ) === $sku && $e['ts'] >= $from && $e['ts'] < $to ) {
					$spend = ( $spend ?? 0 ) + (float) $e['amount'];
				}
			}
			if ( null === $spend && ! YM_Markets::is_on( YM_Country::code(), 'ads' ) ) {
				$spend = 0.0; // no ads in this country: nothing was spent
			}
			$before      = $row['unknown'] || null === $fee ? null : (float) $row['contribution_before_ads'];
			$out[ $sku ] = array( 'name' => $row['name'] ) + self::decide( (int) $row['orders'], (int) $row['orders_delivered'], $before, $spend, $p && $cost > 0 ? (float) $p->get_price() - $cost : 0, (float) $fee );
		}
		return $out;
	}
}
