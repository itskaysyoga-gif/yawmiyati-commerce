<?php
/**
 * Supplier hand-off check.
 *
 * The Taager WooCommerce plugin sends orders to Taager. What it writes back on the order (meta key, note text)
 * is NOT verified yet, so the markers are settings (ym_supplier_ref_keys, ym_supplier_note_pattern) to fill in
 * after the first real test order. Until then this check errs on the side of flagging.
 *
 * States in _ym_supplier_status:
 *   pending  waiting for the supplier plugin
 *   sent     a supplier reference was found
 *   failed   no reference after the wait; order note + email to the admin
 *   manual   a person entered the order in Taager by hand and recorded the Taager order ID
 */

defined( 'ABSPATH' ) || exit;

final class YM_Supplier {

	const NOTE_PREFIX = 'يومياتي:';

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_processed' ), 50, 3 );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( __CLASS__, 'schedule' ) );
		add_action( 'ym_supplier_check', array( __CLASS__, 'check' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	public static function ref_keys() {
		$keys = get_option( 'ym_supplier_ref_keys', array( '_taager_order_id', 'taager_order_id', '_taager_id' ) );
		return array_filter( array_map( 'strval', (array) $keys ) );
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
		$minutes = max( 1, (int) get_option( 'ym_supplier_check_minutes', 15 ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $minutes * MINUTE_IN_SECONDS, 'ym_supplier_check', array( (int) $order_id ), 'yawmiyati' );
		}
	}

	/** Reference the supplier plugin left on the order, or ''. */
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

	public static function check( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || in_array( $order->get_meta( '_ym_supplier_status' ), array( 'sent', 'manual' ), true ) ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'on-hold' ), true ) ) {
			return;
		}
		$ref = self::find_ref( $order );
		if ( '' !== $ref ) {
			$order->update_meta_data( '_ym_supplier_status', 'sent' );
			$order->update_meta_data( '_ym_supplier_ref', $ref );
			$order->save();
			return;
		}
		if ( 'failed' === $order->get_meta( '_ym_supplier_status' ) ) {
			return;
		}
		$order->update_meta_data( '_ym_supplier_status', 'failed' );
		$order->add_order_note( self::NOTE_PREFIX . ' مفيش أي تأكيد إن الطلب وصل لتاجر. راجعيه في حساب تاجر، ولو مش موجود سجّليه يدوي وحطي رقم طلب تاجر هنا.' );
		$order->save();
		wp_mail(
			get_option( 'admin_email' ),
			'يومياتي: طلب ' . $order->get_order_number() . ' ما وصلش لتاجر',
			'الطلب ' . $order->get_order_number() . ' ما ظهرش له رقم في تاجر. افتحيه: ' . $order->get_edit_order_url()
		);
	}

	/**
	 * Record a manual Taager entry. Refuses when the order already has a supplier reference, so the manual
	 * fallback can never duplicate an order the plugin already sent.
	 */
	public static function mark_manual( WC_Order $order, $taager_id ) {
		$taager_id = sanitize_text_field( (string) $taager_id );
		if ( '' === $taager_id ) {
			return new WP_Error( 'ym_missing_id', 'اكتبي رقم طلب تاجر.' );
		}
		$existing = self::find_ref( $order );
		$status   = $order->get_meta( '_ym_supplier_status' );
		if ( '' !== $existing || in_array( $status, array( 'sent', 'manual' ), true ) ) {
			return new WP_Error( 'ym_already_sent', 'الطلب ده متسجل في تاجر خلاص (' . ( $existing ? $existing : $order->get_meta( '_ym_supplier_ref' ) ) . '). متسجليهوش تاني.' );
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

	public static function render_meta_box( $post_or_order ) {
		$order  = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		$status = $order->get_meta( '_ym_supplier_status' );
		$labels = array( 'pending' => 'في انتظار تاجر', 'sent' => 'وصل لتاجر', 'failed' => 'ما وصلش لتاجر', 'manual' => 'اتسجل يدوي' );
		echo '<p><strong>' . esc_html( $labels[ $status ] ?? '—' ) . '</strong>';
		if ( $order->get_meta( '_ym_supplier_ref' ) ) {
			echo '<br>رقم تاجر: ' . esc_html( $order->get_meta( '_ym_supplier_ref' ) );
		}
		echo '</p>';
		if ( ! in_array( $status, array( 'sent', 'manual' ), true ) ) {
			wp_nonce_field( 'ym_manual', 'ym_manual_nonce' );
			echo '<p><label for="ym_taager_id">سجّلتيه يدوي؟ رقم طلب تاجر:</label><input type="text" id="ym_taager_id" name="ym_taager_id" class="widefat"></p>';
		}
	}

	public static function save_meta_box( $order_id ) {
		if ( empty( $_POST['ym_taager_id'] ) || ! isset( $_POST['ym_manual_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ym_manual_nonce'] ) ), 'ym_manual' ) ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$result = $order ? self::mark_manual( $order, wp_unslash( $_POST['ym_taager_id'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in mark_manual.
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
			$s = $order->get_meta( '_ym_supplier_status' );
			echo esc_html( array( 'pending' => '…', 'sent' => '✓', 'failed' => 'مراجعة', 'manual' => 'يدوي' )[ $s ] ?? '' );
			if ( 'possible_duplicate' === $order->get_meta( '_ym_review' ) ) {
				echo ' · مكرر؟';
			}
		}
	}
}
