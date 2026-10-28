<?php
/**
 * Order snapshot and duplicate review.
 *
 * Snapshot: every order stores what the customer saw (country, currency, policy version, SKUs, unit prices,
 * delivery fee, total) so later price or policy changes never rewrite history.
 *
 * Duplicate review: the same phone (main or second number) ordering a product that phone already ordered in the
 * last 24 hours is NOT blocked. The order is created on hold for a person to check (YM_Risk), because a retry, a
 * replacement or a real second purchase are all possible.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Orders {

	const DUPLICATE_WINDOW = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_processed' ), 5, 3 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( __CLASS__, 'cod_status' ), 10, 2 );
	}

	/**
	 * Order IDs with meta $key = $value (newest first), on either order storage. wc_get_orders() ignores a top-level
	 * meta_key and rejects meta_query on the classic (posts) storage, so a filter there would silently match
	 * every order.
	 */
	public static function ids_by_meta( $key, $value, $since = 0, $limit = 50 ) {
		global $wpdb;
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$sql    = $wpdb->prepare( "SELECT o.id FROM {$orders} o JOIN {$meta} m ON m.order_id = o.id WHERE o.type = 'shop_order' AND m.meta_key = %s AND m.meta_value = %s AND o.date_created_gmt >= %s ORDER BY o.id DESC LIMIT %d", $key, (string) $value, gmdate( 'Y-m-d H:i:s', (int) $since ), (int) $limit );
		} else {
			$sql = $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'shop_order' AND m.meta_key = %s AND m.meta_value = %s AND p.post_date_gmt >= %s ORDER BY p.ID DESC LIMIT %d", $key, (string) $value, gmdate( 'Y-m-d H:i:s', (int) $since ), (int) $limit );
		}
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function on_processed( $order_id, $posted, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::snapshot( $order );
		// The hold itself was decided before the first save (YM_Risk::assess); this only explains it to staff.
		$reasons = YM_Risk::reasons( $order );
		if ( $reasons ) {
			$dupes = (string) $order->get_meta( '_ym_duplicate_of' );
			$order->add_order_note( 'يومياتي: الطلب متوقف للمراجعة قبل ما يتبعت للمورد. الأسباب: ' . implode( '، ', $reasons ) . ( $dupes ? ' (طلبات سابقة: ' . $dupes . ')' : '' ) . '. راجعيه ثم: wp ym review release ' . $order->get_id() . ' --note="..."' );
		}
		$order->save();
	}

	/** Flagged orders go on hold instead of processing, so they are reviewed before any supplier hand-off. */
	public static function cod_status( $status, $order ) {
		return ( $order instanceof WC_Order && YM_Risk::is_held( $order ) ) ? 'on-hold' : $status;
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

	/** IDs of earlier orders (last 24 h) sharing a phone (main or second, either way) and at least one product. */
	public static function find_duplicates( WC_Order $order ) {
		$phones = array_values( array_unique( array_filter( array(
			YM_Country::normalise_phone( $order->get_billing_phone() ),
			YM_Country::normalise_phone( (string) $order->get_meta( '_ym_phone2' ) ),
		) ) ) );
		if ( ! $phones ) {
			return array();
		}
		$mine  = array_map( fn( $i ) => $i->get_product_id(), array_values( $order->get_items() ) );
		$since = time() - self::DUPLICATE_WINDOW;
		$ids   = array();
		foreach ( $phones as $phone ) {
			$ids = array_merge(
				$ids,
				wc_get_orders( array( 'billing_phone' => $phone, 'date_created' => '>' . $since, 'status' => array_keys( wc_get_order_statuses() ), 'limit' => 20, 'return' => 'ids' ) ),
				self::ids_by_meta( '_ym_phone2', $phone, $since, 20 )
			);
		}
		$dupes = array();
		foreach ( array_diff( array_unique( $ids ), array( $order->get_id() ) ) as $id ) {
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
