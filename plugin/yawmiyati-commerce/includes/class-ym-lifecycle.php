<?php
/**
 * Order lifecycle: a time-stamped history of what happened to each order, the money attached to it, and the report
 * built from that history. Spec: docs/REPORTING.md.
 *
 * Events (order meta _ym_events, a JSON list, append-only):
 *   submitted       the order was accepted at checkout
 *   confirmed       the confirmation call succeeded (supplier side)
 *   shipped         handed to the courier
 *   delivered       the customer received and paid
 *   refused         refused at the door, before delivery (reason code)
 *   cancelled       cancelled; "before_handoff" or "after_shipment" in the reason
 *   returned        returned after delivery (full or partial, by units)
 *   refund_partial  money given back, part of the order
 *   refund_full     money given back, the whole order
 *
 * Each event: e (stage), at (when it happened, Unix time), rec (when we recorded it), src (checkout, status, staff,
 * refund), verified (bool), plus optional reason, amount, units, by, evidence.
 *
 * A WooCommerce status alone is NOT evidence of delivery. Status-derived events are verified only after the
 * supplier's status sync has been confirmed on a real order (option ym_status_sync_verified) with the status
 * mapped in ym_stage_map. Before that, they are kept as unverified and the report shows them apart.
 *
 * Money attached to an order (meta _ym_charges, JSON list): order-level charges such as the refusal fee, a return
 * charge, a shipping subsidy or a payout fee. Each has src "actual" (from Taager's wallet or invoice) or "rule"
 * (published policy). A charge whose amount is not known is stored with amount null and reported as unknown.
 * Charges are applied once per order. Splitting them across products uses one explicit method: line_value_share.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Lifecycle {

	const STAGES  = array( 'submitted', 'confirmed', 'shipped', 'delivered', 'refused', 'cancelled', 'returned', 'refund_partial', 'refund_full' );
	const CHAIN   = array( 'submitted', 'confirmed', 'shipped', 'delivered' );
	const CHARGES = array( 'refusal_fee', 'return_fee', 'shipping_subsidy', 'payout_fee', 'other' );

	/** Refusal / return reasons. "operational" ones are recorded against the operation, never the customer. */
	const REASONS = array(
		'changed_mind'        => 'customer',
		'not_reachable'       => 'customer',
		'not_at_address'      => 'customer',
		'price_dispute'       => 'customer',
		'ordered_elsewhere'   => 'customer',
		'fake_order'          => 'customer',
		'wrong_item'          => 'operational',
		'damaged'             => 'operational',
		'late_delivery'       => 'operational',
		'supplier_delay'      => 'operational',
		'not_as_described'    => 'operational',
		'address_unsupported' => 'operational',
		'duplicate_order'     => 'operational',
		'before_handoff'      => 'neutral',
		'after_shipment'      => 'neutral',
		'other'               => 'unknown',
	);

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_submitted' ), 4, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 20, 2 );
	}

	/* ---------- recording ---------- */

	public static function events( WC_Order $order ) {
		$list = json_decode( (string) $order->get_meta( '_ym_events' ), true );
		$list = is_array( $list ) ? $list : array();
		// Orders placed before the event log existed still count as submitted at their creation time.
		if ( ! $list && $order->get_date_created() && $order->get_meta( '_ym_snapshot' ) ) {
			$list[] = array( 'e' => 'submitted', 'at' => $order->get_date_created()->getTimestamp(), 'rec' => 0, 'src' => 'backfill', 'verified' => true );
		}
		return $list;
	}

	/**
	 * Append one event. Duplicate deliveries of the same update (same stage, same time, same source) are ignored, so
	 * a repeated or replayed status update cannot double-count. Returns the event or WP_Error.
	 */
	public static function record( WC_Order $order, $stage, $args = array() ) {
		if ( ! in_array( $stage, self::STAGES, true ) ) {
			return new WP_Error( 'ym_stage', 'Unknown stage ' . $stage );
		}
		$reason = isset( $args['reason'] ) ? sanitize_key( $args['reason'] ) : '';
		if ( $reason && ! isset( self::REASONS[ $reason ] ) ) {
			return new WP_Error( 'ym_reason', 'Unknown reason ' . $reason . '. Use one of: ' . implode( ', ', array_keys( self::REASONS ) ) );
		}
		$event = array_filter(
			array(
				'e'        => $stage,
				'at'       => (int) ( $args['at'] ?? time() ),
				'rec'      => time(),
				'src'      => (string) ( $args['src'] ?? 'staff' ),
				'verified' => (bool) ( $args['verified'] ?? false ),
				'reason'   => $reason,
				'amount'   => isset( $args['amount'] ) ? (float) $args['amount'] : null,
				'units'    => isset( $args['units'] ) ? (int) $args['units'] : null,
				'by'       => (string) ( $args['by'] ?? '' ),
				'evidence' => isset( $args['evidence'] ) ? substr( sanitize_text_field( (string) $args['evidence'] ), 0, 300 ) : '',
				'status'   => (string) ( $args['status'] ?? '' ),
			),
			fn( $v ) => null !== $v && '' !== $v
		);
		$event['verified'] = (bool) ( $args['verified'] ?? false );
		$list = self::events( $order );
		foreach ( $list as $old ) {
			if ( $old['e'] === $stage && (int) $old['at'] === $event['at'] && ( $old['src'] ?? '' ) === $event['src'] ) {
				return $old; // same update delivered twice
			}
		}
		if ( 'delivered' === $stage && self::has( $list, 'refused', true ) ) {
			// A delivery reported after a refusal is out of order or contradictory: keep it, flag it for a person.
			$event['conflict'] = 'refused_earlier';
			YM_Ops::alert( 'lifecycle_conflict', 'Order ' . $order->get_order_number() . ': delivered reported after a refusal. Check with the supplier.', $order );
		}
		$list[] = $event;
		usort( $list, fn( $a, $b ) => $a['at'] <=> $b['at'] );
		$order->update_meta_data( '_ym_events', wp_json_encode( $list ) );
		if ( 'refused' === $stage ) {
			self::rule_charge( $order, 'refusal_fee' );
		}
		$order->save_meta_data();
		if ( 'delivered' === $stage && $event['verified'] && empty( $event['conflict'] ) ) {
			/** A verified delivery: measurement sends OrderDelivered with this event's time. */
			do_action( 'ym_order_delivered', $order, $event );
		}
		do_action( 'ym_lifecycle_event', $order, $event );
		return $event;
	}

	private static function has( $list, $stage, $verified_only = false ) {
		foreach ( $list as $e ) {
			if ( $e['e'] === $stage && ( ! $verified_only || ! empty( $e['verified'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function on_submitted( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && ! json_decode( (string) $order->get_meta( '_ym_events' ), true ) ) {
			$at = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
			self::record( $order, 'submitted', array( 'at' => $at, 'src' => 'checkout', 'verified' => true ) );
		}
	}

	/** WooCommerce status for each stage. Statuses not listed here record nothing. */
	public static function stage_map() {
		$map = (array) get_option(
			'ym_stage_map',
			array(
				'confirmed' => array( 'ym-confirmed' ),
				'shipped'   => array( 'ym-shipped' ),
				'delivered' => array( 'ym-delivered' ),
				'refused'   => array( 'ym-refused' ),
				'returned'  => array( 'ym-returned' ),
				'cancelled' => array( 'cancelled' ),
			)
		);
		// Refunds are recorded from the refund itself (amount known), never from the "refunded" status.
		foreach ( $map as $stage => $statuses ) {
			$map[ $stage ] = array_values( array_diff( (array) $statuses, array( 'refunded' ) ) );
		}
		return $map;
	}

	public static function status_sync_verified() {
		return (bool) get_option( 'ym_status_sync_verified', false );
	}

	public static function on_status( $order_id, $from, $to, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( self::stage_map() as $stage => $statuses ) {
			if ( ! in_array( $to, $statuses, true ) ) {
				continue;
			}
			$args = array( 'src' => 'status', 'status' => $to, 'verified' => self::status_sync_verified(), 'by' => self::actor() );
			if ( 'cancelled' === $stage ) {
				// Cancelled is our own action (or the customer's); whether the parcel had left decides the reason.
				$args['verified'] = true;
				$args['reason']   = self::has( self::events( $order ), 'shipped' ) ? 'after_shipment' : 'before_handoff';
			}
			self::record( $order, $stage, $args );
		}
	}

	private static function actor() {
		$u = wp_get_current_user();
		return $u && $u->exists() ? $u->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'system' );
	}

	/** Refunds: partial or full by money, recorded once per refund object. */
	public static function on_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		$amount = (float) $refund->get_amount();
		$full   = (float) $order->get_total_refunded() >= (float) $order->get_total() - 0.0005;
		$at     = $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : time();
		self::record( $order, $full ? 'refund_full' : 'refund_partial', array( 'at' => $at, 'src' => 'refund', 'verified' => true, 'amount' => $amount, 'by' => self::actor(), 'evidence' => 'refund #' . $refund_id ) );
	}

	/* ---------- charges ---------- */

	public static function charges( WC_Order $order ) {
		$c = json_decode( (string) $order->get_meta( '_ym_charges' ), true );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Record an order-level charge once. An "actual" charge replaces a "rule" estimate of the same type; a second
	 * actual charge of the same type is refused unless $replace is set (no double deduction).
	 */
	public static function add_charge( WC_Order $order, $type, $amount, $src = 'actual', $note = '', $replace = false ) {
		if ( ! in_array( $type, self::CHARGES, true ) ) {
			return new WP_Error( 'ym_charge', 'Unknown charge type ' . $type );
		}
		$list = self::charges( $order );
		foreach ( $list as $i => $c ) {
			if ( $c['type'] !== $type ) {
				continue;
			}
			if ( 'actual' === $c['src'] && ! $replace ) {
				return new WP_Error( 'ym_charge_exists', 'An actual ' . $type . ' is already recorded on this order.' );
			}
			if ( 'rule' === $src && 'actual' === $c['src'] ) {
				return $c;
			}
			unset( $list[ $i ] );
		}
		$list[] = array( 'type' => $type, 'amount' => null === $amount ? null : (float) $amount, 'src' => $src, 'note' => substr( (string) $note, 0, 200 ), 'at' => time() );
		$order->update_meta_data( '_ym_charges', wp_json_encode( array_values( $list ) ) );
		$order->save_meta_data();
		return end( $list );
	}

	/** Charge from published policy. Unknown policy figures are stored as unknown (null), not zero. */
	private static function rule_charge( WC_Order $order, $type ) {
		$fee = YM_Country::rules()['refusal_fee'] ?? null;
		self::add_charge( $order, $type, $fee, 'rule', null === $fee ? 'policy figure unknown' : 'published policy' );
	}

	/* ---------- classification ---------- */

	/** Furthest point each order reached on the main chain. A delivered order counts in every earlier stage. */
	public static function reached( $events, $verified_only = true ) {
		$out = array_fill_keys( self::CHAIN, false );
		foreach ( $events as $e ) {
			if ( $verified_only && empty( $e['verified'] ) ) {
				continue;
			}
			$i = array_search( $e['e'], self::CHAIN, true );
			if ( false !== $i ) {
				for ( $j = 0; $j <= $i; $j++ ) {
					$out[ self::CHAIN[ $j ] ] = true;
				}
			}
			if ( in_array( $e['e'], array( 'refused', 'returned' ), true ) ) {
				// A refusal at the door means it was confirmed and shipped; a return means it was delivered.
				foreach ( array( 'submitted', 'confirmed', 'shipped' ) as $s ) {
					$out[ $s ] = true;
				}
				if ( 'returned' === $e['e'] ) {
					$out['delivered'] = true;
				}
			}
		}
		$out['submitted'] = true;
		return $out;
	}

	/**
	 * One final outcome per order:
	 *   delivered_kept, delivered_partial_refund, returned_full, refused, cancelled_before_handoff,
	 *   cancelled_after_shipment, in_progress, unverified (only status-derived evidence; sync not confirmed)
	 */
	public static function outcome( WC_Order $order ) {
		$ev       = self::events( $order );
		$verified = array_values( array_filter( $ev, fn( $e ) => ! empty( $e['verified'] ) ) );
		$stages   = array_column( $verified, 'e' );
		if ( in_array( 'refund_full', $stages, true ) || self::returned_all( $order, $verified ) ) {
			return in_array( 'delivered', $stages, true ) || in_array( 'returned', $stages, true ) ? 'returned_full' : ( in_array( 'refused', $stages, true ) ? 'refused' : 'refunded_before_delivery' );
		}
		if ( in_array( 'refused', $stages, true ) && ! in_array( 'delivered', $stages, true ) ) {
			return 'refused';
		}
		if ( in_array( 'cancelled', $stages, true ) && ! in_array( 'delivered', $stages, true ) ) {
			$c = array_values( array_filter( $verified, fn( $e ) => 'cancelled' === $e['e'] ) );
			return 'after_shipment' === ( end( $c )['reason'] ?? '' ) ? 'cancelled_after_shipment' : 'cancelled_before_handoff';
		}
		if ( in_array( 'delivered', $stages, true ) ) {
			return ( in_array( 'refund_partial', $stages, true ) || in_array( 'returned', $stages, true ) ) ? 'delivered_partial_refund' : 'delivered_kept';
		}
		$unverified = array_diff( array_column( $ev, 'e' ), $stages );
		return $unverified ? 'unverified' : 'in_progress';
	}

	private static function returned_all( WC_Order $order, $verified ) {
		$units = 0;
		foreach ( $verified as $e ) {
			if ( 'returned' === $e['e'] ) {
				$units += (int) ( $e['units'] ?? 0 );
			}
		}
		return $units > 0 && $units >= (int) $order->get_item_count();
	}

	/** Customer-attributable refusals in an order's history (used by the risk rules). */
	public static function customer_refusals( WC_Order $order ) {
		$n = 0;
		foreach ( self::events( $order ) as $e ) {
			if ( 'refused' === $e['e'] && ! empty( $e['verified'] ) && 'customer' === ( self::REASONS[ $e['reason'] ?? 'other' ] ?? 'unknown' ) ) {
				++$n;
			}
		}
		return $n;
	}
}
