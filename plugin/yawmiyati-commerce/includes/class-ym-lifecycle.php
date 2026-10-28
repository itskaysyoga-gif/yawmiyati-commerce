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
 *   returned        returned after delivery: which order lines and how many units of each (lines: item_id => qty)
 *   refund_partial  money given back, part of the order
 *   refund_full     money given back, the whole order
 *
 * Each event: id, e (stage), at (when it happened, Unix time), rec (when we recorded it), src (checkout, status,
 * staff, refund), verified (bool), plus optional reason, amount, lines, units, by, evidence.
 *
 * Contradictions (a delivery after a refusal or a cancellation before hand-off, a refusal after a delivery, a return
 * of a refused order, more units returned than the line holds) are kept, flagged with "conflict" and left OPEN: the
 * order's outcome is "conflict" and its money is unknown in the report until a person resolves it
 * (wp ym conflict resolve), accepting the new event (the contradicted ones are voided) or rejecting it (it is
 * voided). Voided events stay in the log with who voided them and why, and count for nothing.
 *
 * A WooCommerce status alone is NOT evidence of delivery. Status-derived events are verified only after the
 * supplier's status sync has been confirmed on a real order (option ym_status_sync_verified) with the status
 * mapped in ym_stage_map. Before that, they are kept as unverified and the report shows them apart.
 *
 * Money attached to an order (meta _ym_charges, JSON list): order-level charges such as the refusal fee, a return
 * charge, a shipping subsidy or a payout fee. Each has src "actual" (from Taager's wallet or invoice) or "rule"
 * (published policy). A charge whose amount is not known is stored with amount null and reported as unknown.
 * Charges are applied once per order. Splitting them across products uses one explicit method: line_value_share.
 *
 * Supplier credits (meta _ym_credits): money Taager credits back (e.g. compensation for a damaged item), for one
 * order line or the whole order, each with its source. Refunds are read from WooCommerce refunds, line by line when
 * the refund names lines; an amount-only refund is split by line_value_share and reported as allocated.
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
		$lines = null;
		if ( 'returned' === $stage ) {
			$lines = self::return_lines( $order, $args );
			if ( is_wp_error( $lines ) ) {
				return $lines;
			}
		}
		$event = array_filter(
			array(
				'e'        => $stage,
				'at'       => (int) ( $args['at'] ?? time() ),
				'rec'      => time(),
				'src'      => (string) ( $args['src'] ?? 'staff' ),
				'reason'   => $reason,
				'amount'   => isset( $args['amount'] ) ? (float) $args['amount'] : null,
				'lines'    => $lines ? $lines : null,
				'units'    => isset( $args['units'] ) ? (int) $args['units'] : ( $lines ? (int) array_sum( $lines ) : null ),
				'by'       => (string) ( $args['by'] ?? '' ),
				'evidence' => isset( $args['evidence'] ) ? substr( sanitize_text_field( (string) $args['evidence'] ), 0, 300 ) : '',
				'status'   => (string) ( $args['status'] ?? '' ),
			),
			fn( $v ) => null !== $v && '' !== $v
		);
		$event['verified'] = (bool) ( $args['verified'] ?? false );
		$event['id']       = self::eid( $event );
		if ( 'returned' === $stage && null === $lines ) {
			$event['lines_unknown'] = true; // units on a multi-line order without saying which lines: money unknown
		}
		$list = self::events( $order );
		foreach ( $list as $old ) {
			if ( $old['e'] === $stage && (int) $old['at'] === $event['at'] && ( $old['src'] ?? '' ) === $event['src'] ) {
				return $old; // same update delivered twice
			}
		}
		if ( $event['verified'] ) {
			$clash = self::contradictions( $order, $list, $event );
			if ( $clash ) {
				$event['conflict']      = $clash['code'];
				$event['conflict_with'] = $clash['with'];
				$order->update_meta_data( '_ym_conflict_open', 1 );
				YM_Ops::alert( 'lifecycle_conflict', 'Order ' . $order->get_order_number() . ': ' . $stage . ' contradicts its history (' . $clash['code'] . '). Its outcome and money stay unresolved until a person checks with the supplier: wp ym conflict resolve ' . $order->get_id() . ' ' . $event['id'], $order );
			}
		}
		$list[] = $event;
		usort( $list, fn( $a, $b ) => $a['at'] <=> $b['at'] );
		$order->update_meta_data( '_ym_events', wp_json_encode( $list ) );
		if ( 'refused' === $stage && $event['verified'] && empty( $event['conflict'] ) ) {
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

	/** Stable event ID (stage, time, source: the same triple is the same update). */
	public static function eid( $e ) {
		return substr( md5( $e['e'] . '|' . (int) $e['at'] . '|' . ( $e['src'] ?? '' ) ), 0, 10 );
	}

	/** Events that count: verified, not voided, not an open conflict. */
	public static function active( $events ) {
		return array_values( array_filter( $events, fn( $e ) => ! empty( $e['verified'] ) && empty( $e['void'] ) && ! self::is_open( $e ) ) );
	}

	public static function is_open( $e ) {
		return ! empty( $e['conflict'] ) && empty( $e['resolved'] ) && empty( $e['void'] );
	}

	public static function open_conflicts( WC_Order $order ) {
		return array_values( array_filter( self::events( $order ), array( __CLASS__, 'is_open' ) ) );
	}

	/** What a new verified event contradicts in the active history, or null. */
	private static function contradictions( WC_Order $order, $list, $event ) {
		$act  = self::active( $list );
		$ids  = fn( $stage, $test = null ) => array_values( array_map( fn( $e ) => $e['id'] ?? self::eid( $e ), array_filter( $act, fn( $e ) => $e['e'] === $stage && ( ! $test || $test( $e ) ) ) ) );
		$with = array();
		$code = '';
		switch ( $event['e'] ) {
			case 'delivered':
				$with = $ids( 'refused' );
				$code = $with ? 'refused_earlier' : '';
				if ( ! $with ) {
					$with = $ids( 'cancelled', fn( $e ) => 'before_handoff' === ( $e['reason'] ?? '' ) );
					$code = $with ? 'cancelled_earlier' : '';
				}
				break;
			case 'refused':
				$with = array_merge( $ids( 'delivered' ), $ids( 'returned' ) );
				$code = $with ? 'delivered_earlier' : '';
				break;
			case 'returned':
				$with = $ids( 'delivered' ) ? array() : $ids( 'refused' );
				$code = $with ? 'refused_earlier' : '';
				if ( ! $code && ! empty( $event['lines'] ) ) {
					$back = self::returned_by_line( $act );
					foreach ( self::snapshot_lines( $order ) as $l ) {
						if ( ( $back[ $l['item_id'] ] ?? 0 ) + (int) ( $event['lines'][ $l['item_id'] ] ?? 0 ) > (int) $l['qty'] ) {
							$code = 'return_exceeds_quantity';
							$with = $ids( 'returned' );
							break;
						}
					}
				}
				break;
		}
		return $code ? array( 'code' => $code, 'with' => $with ) : null;
	}

	/**
	 * Resolve an open conflict after checking with the supplier. accept: the new event stands and the events it
	 * contradicted are voided; reject: the new event is voided. Both keep who decided, when and why.
	 */
	public static function resolve( WC_Order $order, $event_id, $decision, $by, $note ) {
		if ( ! in_array( $decision, array( 'accept', 'reject' ), true ) ) {
			return new WP_Error( 'ym_decision', 'Decision must be accept or reject.' );
		}
		if ( '' === trim( (string) $note ) ) {
			return new WP_Error( 'ym_note', 'Say what you checked (e.g. "Taager support ticket 4411: delivered on 3 Nov, refusal was a courier error").' );
		}
		$list  = self::events( $order );
		$found = null;
		foreach ( $list as $i => $e ) {
			if ( ( $e['id'] ?? self::eid( $e ) ) === $event_id && self::is_open( $e ) ) {
				$found = $i;
			}
		}
		if ( null === $found ) {
			return new WP_Error( 'ym_no_conflict', 'No open conflict with that event ID on this order (wp ym conflict list).' );
		}
		$mark = array( 'by' => $by, 'at' => time(), 'note' => substr( (string) $note, 0, 300 ), 'decision' => $decision );
		$list[ $found ]['resolved'] = $mark;
		if ( 'reject' === $decision ) {
			$list[ $found ]['void'] = $mark;
		} else {
			foreach ( $list as $i => $e ) {
				if ( in_array( $e['id'] ?? self::eid( $e ), (array) $list[ $found ]['conflict_with'], true ) ) {
					$list[ $i ]['void'] = $mark + array( 'because' => $event_id );
				}
			}
		}
		$order->update_meta_data( '_ym_events', wp_json_encode( $list ) );
		if ( ! array_filter( $list, array( __CLASS__, 'is_open' ) ) ) {
			$order->delete_meta_data( '_ym_conflict_open' );
		}
		// The refusal fee estimate follows the refusals that still stand.
		$refused = (bool) array_filter( self::active( $list ), fn( $e ) => 'refused' === $e['e'] );
		$rule    = array_filter( self::charges( $order ), fn( $c ) => 'refusal_fee' === $c['type'] && 'rule' === $c['src'] );
		if ( $refused && ! array_filter( self::charges( $order ), fn( $c ) => 'refusal_fee' === $c['type'] ) ) {
			self::rule_charge( $order, 'refusal_fee' );
		} elseif ( ! $refused && $rule ) {
			$order->update_meta_data( '_ym_charges', wp_json_encode( array_values( array_filter( self::charges( $order ), fn( $c ) => ! ( 'refusal_fee' === $c['type'] && 'rule' === $c['src'] ) ) ) ) );
		}
		$order->add_order_note( 'يومياتي: تعارض في حالة الطلب اتحسم (' . $decision . ' ' . $event_id . '): ' . $note );
		$order->save();
		$ev = $list[ $found ];
		if ( 'accept' === $decision && 'delivered' === $ev['e'] ) {
			do_action( 'ym_order_delivered', $order, $ev );
		}
		YM_Ops::log( 'lifecycle_conflict_resolved', array( 'order' => $order->get_id(), 'event' => $event_id, 'decision' => $decision, 'by' => $by ) );
		return $ev;
	}

	/**
	 * Which lines a return covers: --lines (item_id or SKU => units), or units on a single-line order. Returns the
	 * map, null when units were given for a multi-line order without lines (kept, money unknown), or WP_Error.
	 */
	private static function return_lines( WC_Order $order, $args ) {
		$snap = self::snapshot_lines( $order );
		if ( ! empty( $args['lines'] ) && is_array( $args['lines'] ) ) {
			$out = array();
			foreach ( $args['lines'] as $ref => $qty ) {
				$hit = null;
				foreach ( $snap as $l ) {
					if ( (string) $l['item_id'] === (string) $ref || ( '' !== $l['sku'] && $l['sku'] === (string) $ref ) ) {
						$hit = $l;
					}
				}
				if ( ! $hit || (int) $qty < 1 ) {
					return new WP_Error( 'ym_return_line', 'Return line ' . $ref . ' is not on this order (use the SKU or order item ID, with at least 1 unit).' );
				}
				$out[ $hit['item_id'] ] = ( $out[ $hit['item_id'] ] ?? 0 ) + (int) $qty;
			}
			return $out;
		}
		$units = isset( $args['units'] ) ? (int) $args['units'] : null;
		if ( 1 === count( $snap ) ) {
			return array( $snap[0]['item_id'] => null === $units ? (int) $snap[0]['qty'] : max( 1, $units ) );
		}
		if ( null === $units ) {
			// "Returned" with nothing more on a multi-line order: everything came back.
			$out = array();
			foreach ( $snap as $l ) {
				$out[ $l['item_id'] ] = (int) $l['qty'];
			}
			return $out;
		}
		return null;
	}

	/** Units returned per order line, from active returns that name their lines. */
	public static function returned_by_line( $active ) {
		$back = array();
		foreach ( $active as $e ) {
			if ( 'returned' === $e['e'] && ! empty( $e['lines'] ) ) {
				foreach ( $e['lines'] as $item => $qty ) {
					$back[ (int) $item ] = ( $back[ (int) $item ] ?? 0 ) + (int) $qty;
				}
			}
		}
		return $back;
	}

	/**
	 * The order's lines as the customer saw them (snapshot), each with its WooCommerce item ID. Snapshots written
	 * before item IDs were stored are matched to the order's items by position. A cost that was not recorded is null.
	 */
	public static function snapshot_lines( WC_Order $order ) {
		$snap  = json_decode( (string) $order->get_meta( '_ym_snapshot' ), true );
		$items = array_values( $order->get_items() );
		$out   = array();
		foreach ( (array) ( $snap['items'] ?? array() ) as $i => $l ) {
			$cost  = $l['taager_cost'] ?? null;
			$out[] = array(
				'item_id'    => (int) ( $l['item_id'] ?? ( isset( $items[ $i ] ) ? $items[ $i ]->get_id() : 0 ) ),
				'sku'        => (string) ( $l['sku'] ?? '' ),
				'product_id' => (int) ( $l['product_id'] ?? 0 ),
				'name'       => (string) ( $l['name'] ?? '' ),
				'qty'        => max( 1, (int) ( $l['qty'] ?? 1 ) ),
				'line_total' => (float) ( $l['line_total'] ?? 0 ),
				'cost'       => ( null === $cost || '' === $cost ) ? null : (float) $cost,
			);
		}
		return $out;
	}

	private static function has( $list, $stage, $verified_only = false ) {
		foreach ( $list as $e ) {
			if ( $e['e'] === $stage && empty( $e['void'] ) && ( ! $verified_only || ! empty( $e['verified'] ) ) ) {
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

	/* ---------- supplier credits ---------- */

	public static function credits( WC_Order $order ) {
		$c = json_decode( (string) $order->get_meta( '_ym_credits' ), true );
		return is_array( $c ) ? $c : array();
	}

	/** Record a credit from Taager (positive amount), for one line (SKU or item ID) or the whole order. */
	public static function add_credit( WC_Order $order, $amount, $source, $line = null, $by = '' ) {
		if ( (float) $amount <= 0 ) {
			return new WP_Error( 'ym_credit', 'A credit is a positive amount the supplier gave back.' );
		}
		if ( '' === trim( (string) $source ) ) {
			return new WP_Error( 'ym_credit_source', 'Every credit needs its source (e.g. "Taager wallet 2026-11-05, compensation damaged item").' );
		}
		$item = null;
		if ( null !== $line && '' !== (string) $line ) {
			foreach ( self::snapshot_lines( $order ) as $l ) {
				if ( (string) $l['item_id'] === (string) $line || ( '' !== $l['sku'] && $l['sku'] === (string) $line ) ) {
					$item = $l['item_id'];
				}
			}
			if ( null === $item ) {
				return new WP_Error( 'ym_credit_line', 'Line ' . $line . ' is not on this order.' );
			}
		}
		$list   = self::credits( $order );
		$list[] = array( 'amount' => (float) $amount, 'item_id' => $item, 'source' => substr( (string) $source, 0, 200 ), 'by' => $by, 'at' => time() );
		$order->update_meta_data( '_ym_credits', wp_json_encode( $list ) );
		$order->save_meta_data();
		return end( $list );
	}

	/* ---------- classification ---------- */

	/** Furthest point each order reached on the main chain. A delivered order counts in every earlier stage. */
	public static function reached( $events, $verified_only = true ) {
		$out = array_fill_keys( self::CHAIN, false );
		foreach ( $events as $e ) {
			if ( ! empty( $e['void'] ) || self::is_open( $e ) || ( $verified_only && empty( $e['verified'] ) ) ) {
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
	 *   delivered_kept, delivered_partial_refund (part returned or part refunded), returned_full, refused,
	 *   cancelled_before_handoff, cancelled_after_shipment, refunded_before_delivery, in_progress,
	 *   unverified (only status-derived evidence; sync not confirmed), conflict (contradictory events, open)
	 */
	public static function outcome( WC_Order $order ) {
		$ev = self::events( $order );
		if ( array_filter( $ev, array( __CLASS__, 'is_open' ) ) ) {
			return 'conflict';
		}
		$verified = self::active( $ev );
		$stages   = array_column( $verified, 'e' );
		$got      = in_array( 'delivered', $stages, true ) || in_array( 'returned', $stages, true );
		if ( in_array( 'refund_full', $stages, true ) || self::returned_all( $order, $verified ) ) {
			return $got ? 'returned_full' : ( in_array( 'refused', $stages, true ) ? 'refused' : 'refunded_before_delivery' );
		}
		if ( in_array( 'refused', $stages, true ) && ! $got ) {
			return 'refused';
		}
		if ( in_array( 'cancelled', $stages, true ) && ! $got ) {
			$c = array_values( array_filter( $verified, fn( $e ) => 'cancelled' === $e['e'] ) );
			return 'after_shipment' === ( end( $c )['reason'] ?? '' ) ? 'cancelled_after_shipment' : 'cancelled_before_handoff';
		}
		if ( $got ) {
			return ( in_array( 'refund_partial', $stages, true ) || in_array( 'returned', $stages, true ) ) ? 'delivered_partial_refund' : 'delivered_kept';
		}
		$unverified = array_filter( $ev, fn( $e ) => empty( $e['verified'] ) && empty( $e['void'] ) );
		return $unverified ? 'unverified' : 'in_progress';
	}

	/** She received the goods (a verified delivery or a return stands), whatever happened afterwards. */
	public static function was_delivered( WC_Order $order ) {
		$ev = self::events( $order );
		if ( array_filter( $ev, array( __CLASS__, 'is_open' ) ) ) {
			return false;
		}
		return (bool) array_filter( self::active( $ev ), fn( $e ) => in_array( $e['e'], array( 'delivered', 'returned' ), true ) );
	}

	private static function returned_all( WC_Order $order, $verified ) {
		$lines = self::snapshot_lines( $order );
		if ( ! $lines ) {
			return false;
		}
		$back = self::returned_by_line( $verified );
		$all  = true;
		foreach ( $lines as $l ) {
			$all = $all && ( $back[ $l['item_id'] ] ?? 0 ) >= $l['qty'];
		}
		if ( $all ) {
			return true;
		}
		// Returns that did not name lines: complete only if their units cover every unit of the order.
		$units = array_sum( array_map( fn( $e ) => 'returned' === $e['e'] && empty( $e['lines'] ) ? (int) ( $e['units'] ?? 0 ) : 0, $verified ) );
		return $units > 0 && $units + array_sum( $back ) >= array_sum( array_column( $lines, 'qty' ) );
	}

	/** Customer-attributable refusals in an order's history (used by the risk rules). */
	public static function customer_refusals( WC_Order $order ) {
		$n = 0;
		foreach ( self::active( self::events( $order ) ) as $e ) {
			if ( 'refused' === $e['e'] && 'customer' === ( self::REASONS[ $e['reason'] ?? 'other' ] ?? 'unknown' ) ) {
				++$n;
			}
		}
		return $n;
	}

	/* ---------- line-level money ---------- */

	/**
	 * Money per order line, from the snapshot, the lifecycle, WooCommerce refunds, supplier credits and charges.
	 *
	 * Per line: kept units = delivered units − returned units; goods margin = (net unit price − Taager cost) × kept
	 * units (null when the cost is unknown and units were kept); refunds = refunds naming the line + the line's share
	 * of amount-only refunds; money refunded beyond the returned goods' value reduces the margin; credits and charges
	 * are the line's own plus its share of order-level ones (line_value_share; the last line takes the rounding rest,
	 * so shares always add up to the order figure).
	 *
	 * Returns array( lines => [...], order => totals computed from the order's own records (not by summing lines),
	 * unknown => reasons, conflict => bool ).
	 */
	public static function money_lines( WC_Order $order ) {
		$lines   = self::snapshot_lines( $order );
		$ev      = self::events( $order );
		$open    = (bool) array_filter( $ev, array( __CLASS__, 'is_open' ) );
		$act     = self::active( $ev );
		$got     = ! $open && (bool) array_filter( $act, fn( $e ) => in_array( $e['e'], array( 'delivered', 'returned' ), true ) );
		$back    = self::returned_by_line( $act );
		$unknown = array();
		if ( $open ) {
			$unknown[] = 'contradictory events awaiting review';
		}
		if ( $got && array_filter( $act, fn( $e ) => 'returned' === $e['e'] && ! empty( $e['lines_unknown'] ) ) ) {
			$unknown[] = 'a return did not say which lines came back';
		}
		$money_unknown = (bool) $unknown; // conflict or return lines unknown: the margin cannot be known
		$weights = array_map( fn( $l ) => max( 0.0, $l['line_total'] ), $lines );

		// Refunds from WooCommerce: line items name their order line; shipping refunds are delivery money.
		$by_line = array();
		$product_refunds = 0.0;
		$delivery_refunds = 0.0;
		$unallocated = 0.0;
		foreach ( $order->get_refunds() as $rf ) {
			$named = 0.0;
			foreach ( $rf->get_items( 'line_item' ) as $ri ) {
				$orig = (int) $ri->get_meta( '_refunded_item_id' );
				$amt  = abs( (float) $ri->get_total() ) + abs( (float) $ri->get_total_tax() );
				$by_line[ $orig ] = ( $by_line[ $orig ] ?? 0 ) + $amt;
				$named += $amt;
			}
			$ship = 0.0;
			foreach ( $rf->get_items( 'shipping' ) as $si ) {
				$ship += abs( (float) $si->get_total() ) + abs( (float) $si->get_total_tax() );
			}
			$delivery_refunds += $ship;
			$rest              = max( 0.0, (float) $rf->get_amount() - $named - $ship );
			$unallocated      += $rest;
			$product_refunds  += (float) $rf->get_amount() - $ship;
		}
		$refund_share = self::split( $unallocated, $weights );

		$credits     = self::credits( $order );
		$credit_line = array();
		$credit_all  = 0.0;
		foreach ( $credits as $c ) {
			if ( null !== ( $c['item_id'] ?? null ) ) {
				$credit_line[ (int) $c['item_id'] ] = ( $credit_line[ (int) $c['item_id'] ] ?? 0 ) + (float) $c['amount'];
			} else {
				$credit_all += (float) $c['amount'];
			}
		}
		$credit_share = self::split( $credit_all, $weights );

		$charges_known   = 0.0;
		$charges_unknown = false;
		foreach ( self::charges( $order ) as $c ) {
			if ( null === $c['amount'] ) {
				$charges_unknown = true;
			} else {
				$charges_known += (float) $c['amount'];
			}
		}
		if ( $charges_unknown ) {
			$unknown[] = 'an order charge with no known amount';
		}
		$charge_share = self::split( $charges_known, $weights );

		$out = array();
		foreach ( $lines as $i => $l ) {
			$qty      = $l['qty'];
			$unit     = $qty ? $l['line_total'] / $qty : 0.0;
			$returned = $got ? min( $qty, (int) ( $back[ $l['item_id'] ] ?? 0 ) ) : 0;
			$kept     = $got ? $qty - $returned : 0;
			$refund   = (float) ( $by_line[ $l['item_id'] ] ?? 0 ) + $refund_share[ $i ];
			$excess   = $got ? max( 0.0, $refund - $unit * $returned ) : 0.0;
			$goods    = 0 === $kept ? 0.0 : ( null === $l['cost'] ? null : ( $unit - $l['cost'] ) * $kept );
			$credit   = (float) ( $credit_line[ $l['item_id'] ] ?? 0 ) + $credit_share[ $i ];
			$margin   = ( null === $goods || $money_unknown ) ? null : $goods - $excess + $credit;
			$out[]    = array(
				'item_id'         => $l['item_id'],
				'sku'             => '' !== $l['sku'] ? $l['sku'] : (string) $l['product_id'],
				'name'            => $l['name'],
				'qty'             => $qty,
				'value'           => $l['line_total'],
				'delivered_units' => $got ? $qty : 0,
				'returned_units'  => $returned,
				'kept_units'      => $kept,
				'delivered_sales' => $got ? $unit * $kept - $excess : 0.0,
				'refunds'         => $refund,
				'refund_allocated'=> $refund_share[ $i ],
				'credits'         => $credit,
				'cost_unknown'    => null === $l['cost'] && $kept > 0,
				'margin'          => $margin,
				'charges'         => $charges_unknown ? null : $charge_share[ $i ],
				'contribution'    => ( null === $margin || $charges_unknown ) ? null : $margin - $charge_share[ $i ],
			);
		}
		$order_totals = array(
			'value'            => array_sum( array_column( $lines, 'line_total' ) ),
			'product_refunds'  => $product_refunds,
			'delivery_refunds' => $delivery_refunds,
			'refunds_allocated'=> $unallocated,
			'credits'          => array_sum( array_map( fn( $c ) => (float) $c['amount'], $credits ) ),
			'charges'          => $charges_unknown ? null : $charges_known,
		);
		return array( 'lines' => $out, 'order' => $order_totals, 'unknown' => $unknown, 'conflict' => $open, 'delivered' => $got );
	}

	/** Split $amount by weights; the last share takes the rest so the shares add up exactly. */
	public static function split( $amount, $weights ) {
		$n   = count( $weights );
		$sum = array_sum( $weights );
		$out = array_fill( 0, $n, 0.0 );
		if ( ! $n || 0.0 === (float) $amount ) {
			return $out;
		}
		$given = 0.0;
		foreach ( array_values( $weights ) as $i => $w ) {
			$out[ $i ] = $i === $n - 1 ? $amount - $given : ( $sum > 0 ? $amount * $w / $sum : $amount / $n );
			$given    += $out[ $i ];
		}
		return $out;
	}
}
