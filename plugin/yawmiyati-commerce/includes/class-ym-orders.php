<?php
/**
 * Order snapshot and duplicate review.
 *
 * Snapshot: every order stores what the customer saw (country, currency, policy version, SKUs, unit prices,
 * delivery fee, total) so later price or policy changes never rewrite history.
 *
 * Duplicate review: the same phone ordering a product that the same phone already ordered in the last 24 hours
 * is NOT blocked. The order is created normally but placed on hold for a person to check, because a real
 * second purchase is possible.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Orders {

	const DUPLICATE_WINDOW = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_processed' ), 5, 3 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( __CLASS__, 'cod_status' ), 10, 2 );
	}

	public static function on_processed( $order_id, $posted, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::snapshot( $order );
		$dupes = self::find_duplicates( $order );
		if ( $dupes ) {
			$order->update_meta_data( '_ym_review', 'possible_duplicate' );
			$order->update_meta_data( '_ym_duplicate_of', implode( ',', $dupes ) );
			$order->add_order_note( 'يومياتي: نفس رقم الموبايل طلب نفس المنتج خلال 24 ساعة (طلب ' . implode( '، ', $dupes ) . '). الطلب متوقف للمراجعة قبل إرساله لتاجر.' );
		}
		$order->save();
	}

	/** Flagged orders go on hold instead of processing, so they are reviewed before any supplier hand-off. */
	public static function cod_status( $status, $order ) {
		return ( $order instanceof WC_Order && 'possible_duplicate' === $order->get_meta( '_ym_review' ) ) ? 'on-hold' : $status;
	}

	public static function snapshot( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$p       = $item->get_product();
			$items[] = array(
				'product_id' => $item->get_product_id(),
				'sku'        => $p ? $p->get_sku() : '',
				'name'       => $item->get_name(),
				'qty'        => $item->get_quantity(),
				'unit_price' => (float) $order->get_item_subtotal( $item, false, false ),
				'line_total' => (float) $item->get_total(),
				'taager_cost'=> $p ? (float) $p->get_meta( '_ym_taager_cost' ) : null,
			);
		}
		$order->update_meta_data(
			'_ym_snapshot',
			wp_json_encode(
				array(
					'country'        => YM_Country::code(),
					'currency'       => $order->get_currency(),
					'policy_version' => YM_POLICY_VERSION,
					'plugin_version' => YM_VERSION,
					'governorate'    => $order->get_billing_state(),
					'delivery_fee'   => (float) $order->get_shipping_total(),
					'items'          => $items,
					'total'          => (float) $order->get_total(),
					'created_gmt'    => gmdate( 'c' ),
				),
				JSON_UNESCAPED_UNICODE
			)
		);
	}

	/** IDs of earlier orders (last 24 h) with the same phone and at least one same product. */
	public static function find_duplicates( WC_Order $order ) {
		$phone = YM_Country::normalise_phone( $order->get_billing_phone() );
		if ( ! $phone ) {
			return array();
		}
		$mine = array_map( fn( $i ) => $i->get_product_id(), array_values( $order->get_items() ) );
		$ids  = wc_get_orders(
			array(
				'billing_phone' => $phone,
				'date_created'  => '>' . ( time() - self::DUPLICATE_WINDOW ),
				'exclude'       => array( $order->get_id() ),
				'status'        => array_keys( wc_get_order_statuses() ),
				'limit'         => 20,
				'return'        => 'ids',
			)
		);
		$dupes = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o || in_array( $o->get_status(), array( 'cancelled', 'failed' ), true ) ) {
				continue;
			}
			$theirs = array_map( fn( $i ) => $i->get_product_id(), array_values( $o->get_items() ) );
			if ( array_intersect( $mine, $theirs ) ) {
				$dupes[] = $o->get_id();
			}
		}
		return $dupes;
	}
}
