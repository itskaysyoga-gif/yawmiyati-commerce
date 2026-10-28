<?php
/**
 * Genuine reviews after delivery. Spec: docs/REVIEWS.md.
 *
 * - Only a customer whose order has a VERIFIED delivery (YM_Lifecycle) can review, through a signed link for that
 *   order (wp ym review-link <order>). The link carries an HMAC of the order ID and key, never the key itself.
 * - One review per order and product. Every review is stored unapproved and shown only after a person approves it
 *   (wp ym reviews approve <id>, or WooCommerce > Reviews).
 * - Shown with "bought and received". No review is ever created, edited for meaning, or imported by us; there is
 *   no admin path to add one. Rejected reviews stay in the log with the reason.
 * - Structured data gets a rating only from approved verified reviews, and only from 3 of them.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Reviews {

	const MIN_FOR_RATING = 3;

	public static function init() {
		add_shortcode( 'ym_review_form', array( __CLASS__, 'form' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
	}

	public static function token( WC_Order $order ) {
		return substr( hash_hmac( 'sha256', 'review|' . $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) ), 0, 32 );
	}

	/** Review link for an order with a verified delivery, or WP_Error. */
	public static function link( WC_Order $order ) {
		if ( ! in_array( YM_Lifecycle::outcome( $order ), array( 'delivered_kept', 'delivered_partial_refund' ), true ) ) {
			return new WP_Error( 'ym_not_delivered', 'Only orders with a verified delivery can be reviewed.' );
		}
		$page = get_page_by_path( 'review' );
		$base = $page ? get_permalink( $page ) : home_url( '/review/' );
		return add_query_arg( array( 'o' => $order->get_id(), 't' => self::token( $order ) ), $base );
	}

	private static function order_from_request( $src ) {
		$id    = absint( $src['o'] ?? 0 );
		$t     = sanitize_text_field( wp_unslash( $src['t'] ?? '' ) );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order || ! hash_equals( self::token( $order ), (string) $t ) || is_wp_error( self::link( $order ) ) ) {
			return null;
		}
		return $order;
	}

	/** Products of an order (group pages, so offers of one product share their reviews). */
	private static function products( WC_Order $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			if ( $p ) {
				$page              = YM_Offers::primary( $p );
				$out[ $page->get_id() ] = $page;
			}
		}
		return $out;
	}

	private static function existing( WC_Order $order, $product_id ) {
		return get_comments( array( 'post_id' => $product_id, 'status' => 'all', 'meta_key' => 'ym_order', 'meta_value' => $order->get_id(), 'count' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function form() {
		$order = self::order_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['ym_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<p class="ym-note">' . esc_html( YM_Country::copy( 'review_thanks' ) ) . '</p>';
		}
		if ( ! $order ) {
			return '<p class="ym-out">' . esc_html( YM_Country::copy( 'review_invalid' ) ) . '</p>';
		}
		$out = '';
		foreach ( self::products( $order ) as $pid => $p ) {
			if ( self::existing( $order, $pid ) ) {
				$out .= '<p class="ym-note">' . esc_html( $p->get_name() . ': ' . YM_Country::copy( 'review_done' ) ) . '</p>';
				continue;
			}
			$stars = '';
			for ( $i = 5; $i >= 1; $i-- ) {
				$stars .= '<input type="radio" id="r' . $pid . '-' . $i . '" name="ym_rating" value="' . $i . '" required><label for="r' . $pid . '-' . $i . '">' . esc_html( sprintf( YM_Country::copy( 'review_stars' ), $i ) ) . '</label>';
			}
			$out .= '<form method="post" class="ym-review-form" data-ym-review-form>'
				. '<h2>' . esc_html( YM_Country::copy( 'review_form_title' ) ) . ': ' . esc_html( $p->get_name() ) . '</h2>'
				. wp_nonce_field( 'ym_review', 'ym_review_nonce', true, false )
				. '<input type="hidden" name="o" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="t" value="' . esc_attr( self::token( $order ) ) . '"><input type="hidden" name="ym_product" value="' . esc_attr( $pid ) . '">'
				. '<fieldset class="ym-stars"><legend>' . esc_html( YM_Country::copy( 'review_rating' ) ) . '</legend>' . $stars . '</fieldset>'
				. '<label for="ym-rt-' . $pid . '">' . esc_html( YM_Country::copy( 'review_text' ) ) . '</label><textarea id="ym-rt-' . $pid . '" name="ym_text" rows="4" maxlength="1000" required></textarea>'
				. '<label for="ym-rn-' . $pid . '">' . esc_html( YM_Country::copy( 'review_name' ) ) . '</label><input type="text" id="ym-rn-' . $pid . '" name="ym_name" maxlength="40">'
				. '<button type="submit" class="ym-btn ym-btn--primary">' . esc_html( YM_Country::copy( 'review_submit' ) ) . '</button></form>';
		}
		return $out;
	}

	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['ym_review_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ym_review_nonce'] ) ), 'ym_review' ) ) {
			return;
		}
		$order = self::order_from_request( $_POST );
		$pid   = absint( $_POST['ym_product'] ?? 0 );
		$rate  = absint( $_POST['ym_rating'] ?? 0 );
		$text  = trim( sanitize_textarea_field( wp_unslash( $_POST['ym_text'] ?? '' ) ) );
		if ( ! $order || ! isset( self::products( $order )[ $pid ] ) || $rate < 1 || $rate > 5 || '' === $text || self::existing( $order, $pid ) ) {
			return; // the form shows the reason (invalid link, already reviewed)
		}
		$name = sanitize_text_field( wp_unslash( $_POST['ym_name'] ?? '' ) );
		$id   = wp_insert_comment(
			array(
				'comment_post_ID'  => $pid,
				'comment_author'   => '' !== $name ? mb_substr( $name, 0, 40 ) : '',
				'comment_content'  => mb_substr( $text, 0, 1000 ),
				'comment_type'     => 'review',
				'comment_approved' => 0,
				'comment_meta'     => array( 'rating' => $rate, 'ym_order' => $order->get_id(), 'ym_verified' => 1, 'verified' => 1 ),
			)
		);
		YM_Ops::log( 'review_received', array( 'order' => $order->get_id(), 'product' => $pid, 'comment' => $id ) );
		wp_safe_redirect( add_query_arg( 'ym_sent', 1, get_permalink() ) );
		exit;
	}

	/** Approved, verified reviews of a product page. */
	public static function approved( WC_Product $page, $limit = 10 ) {
		return get_comments( array( 'post_id' => $page->get_id(), 'status' => 'approve', 'type' => 'review', 'number' => $limit, 'meta_key' => 'ym_verified', 'meta_value' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function summary( WC_Product $page ) {
		$all = self::approved( $page, 500 );
		$sum = array_sum( array_map( fn( $c ) => (int) get_comment_meta( $c->comment_ID, 'rating', true ), $all ) );
		return array( 'count' => count( $all ), 'average' => count( $all ) ? round( $sum / count( $all ), 1 ) : null );
	}

	public static function render( WC_Product $page ) {
		$list = self::approved( $page );
		if ( ! $list ) {
			return '';
		}
		$out = '<section class="ym-section ym-reviews" id="reviews"><h2>' . esc_html( YM_Country::copy( 'reviews_title' ) ) . '</h2>';
		foreach ( $list as $c ) {
			$r    = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			$out .= '<article class="ym-review"><div class="ym-review__head"><span class="ym-review__stars" aria-label="' . esc_attr( sprintf( YM_Country::copy( 'review_stars' ), $r ) ) . '">' . str_repeat( '★', $r ) . str_repeat( '☆', 5 - $r ) . '</span>'
				. ( $c->comment_author ? '<strong>' . esc_html( $c->comment_author ) . '</strong>' : '' )
				. '<span class="ym-review__badge">' . esc_html( YM_Country::copy( 'review_verified' ) ) . '</span></div>'
				. '<p>' . esc_html( $c->comment_content ) . '</p></article>';
		}
		return $out . '</section>';
	}

	/** Moderation: approve or reject (with a reason kept on the review). */
	public static function moderate( $comment_id, $action, $by, $reason = '' ) {
		$c = get_comment( $comment_id );
		if ( ! $c || 'review' !== $c->comment_type || ! get_comment_meta( $comment_id, 'ym_verified', true ) ) {
			return new WP_Error( 'ym_review', 'Not a verified review.' );
		}
		if ( 'reject' === $action && '' === trim( (string) $reason ) ) {
			return new WP_Error( 'ym_reason', 'Give the reason (e.g. personal data, abuse, not about the product).' );
		}
		update_comment_meta( $comment_id, 'ym_moderation', wp_json_encode( array( 'action' => $action, 'by' => $by, 'reason' => $reason, 'at' => time() ) ) );
		wp_set_comment_status( $comment_id, 'approve' === $action ? 'approve' : 'hold' );
		if ( 'reject' === $action ) {
			wp_set_comment_status( $comment_id, 'trash' );
		}
		YM_Ops::log( 'review_' . $action, array( 'comment' => $comment_id, 'by' => $by ) );
		return true;
	}
}
