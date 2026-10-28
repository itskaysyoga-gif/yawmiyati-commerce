<?php
/**
 * Supplier hand-off tracking.
 *
 * The Taager WooCommerce plugin sends orders to Taager. What it writes back on the order (meta key, note text)
 * is NOT verified yet, so the markers are settings (ym_supplier_ref_keys, ym_supplier_note_pattern) to fill in
 * after the first real test order. Missing evidence is never treated as proof of failure: an order without a
 * reference is "unconfirmed" and keeps being re-checked.
 *
 * States in _ym_supplier_status:
 *   pending      waiting for the supplier plugin
 *   sent         a supplier reference was found on the order
 *   unconfirmed  no reference yet after the wait; re-checked, and a person is asked to look in Taager
 *   not_found    a person looked in Taager and the order is not there (reconciliation, time-stamped)
 *   manual       entered in Taager by hand after a fresh not_found reconciliation; Taager order ID recorded
 *   conflict     a manual entry exists AND the plugin later reported its own reference: possible double order
 *
 * Manual entry is allowed only when: no reference is on the order, the status is not_found, and that
 * reconciliation is less than RECONCILE_TTL old. This is the guard against sending the same order twice.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Supplier {

	const NOTE_PREFIX   = 'يومياتي:';
	const RECHECKS      = array( 15, 60, 180 ); // minutes after the order
	const RECONCILE_TTL = 2 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_processed' ), 50, 3 );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( __CLASS__, 'schedule' ) );
		add_action( 'ym_supplier_check', array( __CLASS__, 'check' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	public static function ref_keys() {
		$keys = get_option( 'ym_supplier_ref_keys', array( '_taager_order_id', 'taager_order_id', '_taager_id' ) );
		return array_filter( array_map( 'strval', (array) $keys ) );
	}

	public static function status( WC_Order $order ) {
		return (string) $order->get_meta( '_ym_supplier_status' );
	}

	public static function on_processed( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_ym_supplier_status', 'pending' );
		$order->save();
		if ( 'possible_duplicate' !== $order->get_meta( '_ym_review' ) ) {
			self::schedule( $order->get_id() );
		}
	}

	public static function schedule( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$first = max( 1, (int) get_option( 'ym_supplier_check_minutes', self::RECHECKS[0] ) );
		as_schedule_single_action( time() + $first * MINUTE_IN_SECONDS, 'ym_supplier_check', array( (int) $order_id, 0 ), 'yawmiyati' );
	}

	/** Reference the supplier plugin left on the order, or ''. Our own manual reference is not counted. */
	public static function find_ref( WC_Order $order ) {
		foreach ( self::ref_keys() as $key ) {
			$v = $order->get_meta( $key );
			if ( '' !== (string) $v ) {
				return (string) $v;
			}
		}
		$pattern = (string) get_option( 'ym_supplier_note_pattern', '' );
		if ( $pattern ) {
			foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
				if ( 0 !== strpos( $note->content, self::NOTE_PREFIX ) && @preg_match( $pattern, $note->content, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return $m[1] ?? 'note';
				}
			}
		}
		return '';
	}

	/** Look for the supplier reference. $attempt counts re-checks (0 = first check). */
	public static function check( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || in_array( $order->get_status(), array( 'cancelled', 'failed', 'on-hold' ), true ) ) {
			return;
		}
		$status = self::status( $order );
		$ref    = self::find_ref( $order );
		if ( '' !== $ref ) {
			if ( 'manual' === $status && $ref !== $order->get_meta( '_ym_supplier_ref' ) ) {
				$order->update_meta_data( '_ym_supplier_status', 'conflict' );
				$order->update_meta_data( '_ym_supplier_plugin_ref', $ref );
				$order->add_order_note( self::NOTE_PREFIX . ' الطلب اتسجل يدوي، وبعدها ظهر له رقم تاني من الإضافة (' . $ref . '). ممكن يكون متسجل مرتين في تاجر: راجعيه وألغي واحد.' );
				$order->save();
				YM_Ops::alert( 'supplier_conflict', 'Order ' . $order->get_order_number() . ' may exist twice in Taager (manual ' . $order->get_meta( '_ym_supplier_ref' ) . ', plugin ' . $ref . ').', $order );
				return;
			}
			if ( in_array( $status, array( 'sent', 'conflict' ), true ) ) {
				return;
			}
			$order->update_meta_data( '_ym_supplier_status', 'manual' === $status ? 'manual' : 'sent' );
			if ( 'manual' !== $status ) {
				$order->update_meta_data( '_ym_supplier_ref', $ref );
			}
			$order->save();
			YM_Ops::log( 'supplier_sent', array( 'order' => $order->get_id(), 'ref' => $ref, 'attempt' => (int) $attempt ) );
			return;
		}
		if ( in_array( $status, array( 'sent', 'manual', 'conflict', 'not_found' ), true ) ) {
			return;
		}
		if ( 'unconfirmed' !== $status ) {
			$order->update_meta_data( '_ym_supplier_status', 'unconfirmed' );
			$order->add_order_note( self::NOTE_PREFIX . ' لسه مفيش رقم من تاجر على الطلب. ده مش معناه إنه ما وصلش: هنكشف تاني تلقائي. لو استعجلتي، دوري عليه في حساب تاجر وسجّلي النتيجة هنا.' );
			$order->save();
			YM_Ops::alert( 'supplier_unconfirmed', 'Order ' . $order->get_order_number() . ' has no Taager reference yet. Check Taager before any manual entry.', $order );
		}
		$next = (int) $attempt + 1;
		if ( isset( self::RECHECKS[ $next ] ) && function_exists( 'as_schedule_single_action' ) ) {
			$wait = self::RECHECKS[ $next ] - self::RECHECKS[ $next - 1 ];
			as_schedule_single_action( time() + $wait * MINUTE_IN_SECONDS, 'ym_supplier_check', array( (int) $order->get_id(), $next ), 'yawmiyati' );
		}
	}

	/** Reconciliation: a person found the order in Taager. Records its Taager ID; no manual entry needed. */
	public static function mark_found( WC_Order $order, $taager_id, $by = '' ) {
		$taager_id = sanitize_text_field( (string) $taager_id );
		if ( '' === $taager_id ) {
			return new WP_Error( 'ym_missing_id', 'Taager order ID required.' );
		}
		if ( in_array( self::status( $order ), array( 'sent', 'manual', 'conflict' ), true ) ) {
			return new WP_Error( 'ym_already_sent', 'Order already has a Taager reference (' . $order->get_meta( '_ym_supplier_ref' ) . ').' );
		}
		$order->update_meta_data( '_ym_supplier_status', 'sent' );
		$order->update_meta_data( '_ym_supplier_ref', $taager_id );
		$order->update_meta_data( '_ym_reconciled', wp_json_encode( array( 'result' => 'found', 'at' => time(), 'by' => $by ) ) );
		$order->add_order_note( self::NOTE_PREFIX . ' اتراجع في تاجر: الطلب موجود برقم ' . $taager_id . '.' );
		$order->save();
		return true;
	}

	/** Reconciliation: a person searched Taager (phone, name, date) and the order is not there. */
	public static function mark_not_found( WC_Order $order, $by = '' ) {
		if ( '' !== self::find_ref( $order ) || in_array( self::status( $order ), array( 'sent', 'manual', 'conflict' ), true ) ) {
			return new WP_Error( 'ym_already_sent', 'Order already has a Taager reference.' );
		}
		$order->update_meta_data( '_ym_supplier_status', 'not_found' );
		$order->update_meta_data( '_ym_reconciled', wp_json_encode( array( 'result' => 'not_found', 'at' => time(), 'by' => $by ) ) );
		$order->add_order_note( self::NOTE_PREFIX . ' اتراجع في تاجر: الطلب مش موجود. ممكن يتسجل يدوي خلال ساعتين.' );
		$order->save();
		return true;
	}

	/** Record a manual Taager entry. Requires a fresh "not found" reconciliation and no reference on the order. */
	public static function mark_manual( WC_Order $order, $taager_id, $by = '' ) {
		$taager_id = sanitize_text_field( (string) $taager_id );
		if ( '' === $taager_id ) {
			return new WP_Error( 'ym_missing_id', 'Taager order ID required.' );
		}
		$existing = self::find_ref( $order );
		$status   = self::status( $order );
		if ( '' !== $existing || in_array( $status, array( 'sent', 'manual', 'conflict' ), true ) ) {
			return new WP_Error( 'ym_already_sent', 'Order already has a Taager reference (' . ( $existing ? $existing : $order->get_meta( '_ym_supplier_ref' ) ) . '). Do not enter it again.' );
		}
		$rec = json_decode( (string) $order->get_meta( '_ym_reconciled' ), true );
		if ( 'not_found' !== $status || ! is_array( $rec ) || 'not_found' !== ( $rec['result'] ?? '' ) || time() - (int) $rec['at'] > self::RECONCILE_TTL ) {
			return new WP_Error( 'ym_reconcile_first', 'Search Taager for this order first and record "not found" (valid 2 h).' );
		}
		$order->update_meta_data( '_ym_supplier_status', 'manual' );
		$order->update_meta_data( '_ym_supplier_ref', $taager_id );
		$order->add_order_note( self::NOTE_PREFIX . ' اتسجل يدوي في تاجر برقم ' . $taager_id . '.' );
		$order->save();
		return true;
	}

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'ym-supplier', 'تاجر', array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	const LABELS = array(
		'pending'     => 'في انتظار الإضافة',
		'sent'        => 'وصل لتاجر',
		'unconfirmed' => 'لسه مفيش تأكيد: دوري عليه في تاجر',
		'not_found'   => 'مش موجود في تاجر: ممكن يتسجل يدوي',
		'manual'      => 'اتسجل يدوي',
		'conflict'    => 'ممكن يكون متسجل مرتين',
	);

	public static function render_meta_box( $post_or_order ) {
		$order  = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		$status = self::status( $order );
		echo '<p><strong>' . esc_html( self::LABELS[ $status ] ?? '—' ) . '</strong>';
		if ( $order->get_meta( '_ym_supplier_ref' ) ) {
			echo '<br>رقم تاجر: ' . esc_html( $order->get_meta( '_ym_supplier_ref' ) );
		}
		echo '</p>';
		if ( in_array( $status, array( 'pending', 'unconfirmed' ), true ) ) {
			wp_nonce_field( 'ym_supplier', 'ym_supplier_nonce' );
			echo '<p><label for="ym_found_id">لقيتيه في تاجر؟ رقمه:</label><input type="text" id="ym_found_id" name="ym_found_id" class="widefat"></p>';
			echo '<p><label><input type="checkbox" name="ym_not_found" value="1"> دورت في تاجر بالموبايل والاسم والتاريخ ومش موجود</label></p>';
		} elseif ( 'not_found' === $status ) {
			wp_nonce_field( 'ym_supplier', 'ym_supplier_nonce' );
			echo '<p><label for="ym_taager_id">سجّلتيه يدوي؟ رقم طلب تاجر:</label><input type="text" id="ym_taager_id" name="ym_taager_id" class="widefat"></p>';
		}
	}

	public static function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['ym_supplier_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ym_supplier_nonce'] ) ), 'ym_supplier' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$by     = wp_get_current_user()->user_login;
		$result = null;
		if ( ! empty( $_POST['ym_found_id'] ) ) {
			$result = self::mark_found( $order, sanitize_text_field( wp_unslash( $_POST['ym_found_id'] ) ), $by );
		} elseif ( ! empty( $_POST['ym_not_found'] ) ) {
			$result = self::mark_not_found( $order, $by );
		} elseif ( ! empty( $_POST['ym_taager_id'] ) ) {
			$result = self::mark_manual( $order, sanitize_text_field( wp_unslash( $_POST['ym_taager_id'] ) ), $by );
		}
		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	public static function column( $cols ) {
		$cols['ym_supplier'] = 'تاجر';
		return $cols;
	}

	public static function column_value( $col, $order ) {
		if ( 'ym_supplier' === $col ) {
			$s = self::status( $order );
			echo esc_html( array( 'pending' => '…', 'sent' => '✓', 'unconfirmed' => 'تأكيد؟', 'not_found' => 'يدوي؟', 'manual' => 'يدوي', 'conflict' => 'مرتين؟' )[ $s ] ?? '' );
			if ( 'possible_duplicate' === $order->get_meta( '_ym_review' ) ) {
				echo ' · مكرر؟';
			}
		}
	}
}
