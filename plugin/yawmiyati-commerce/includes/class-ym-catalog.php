<?php
/**
 * Catalog gate: a product may be published and sold only when its identity, specs, media, price and stock have
 * been checked and recorded. Enforced on staging and production (YM_Env::enforced()); local stores report the
 * same blockers without enforcing them so the order flow can be tested with interim data.
 *
 * Recorded checks (product meta, JSON {date, by, note}):
 *   _ym_check_identity  product matches the Taager SKU: same item, contents, colour, print
 *   _ym_check_specs     every critical spec row filled from a supplier source
 *   _ym_check_media     gallery reviewed: complete product shown, final quality, rights recorded
 *   _ym_check_price     cost and Taager minimum price confirmed in Taager, price set at or above it
 * Stock: _ym_stock_checked_at (unix time), set by `wp ym check <sku> --stock`; expires after ym_stock_max_age_hours.
 *
 * Spec rows in _ym_specs: [label, value|null, "optional"?]. A row without "optional" is critical.
 * Media meta on each attachment, _ym_media JSON: {source, rights, captured, final: bool, ad_crop: bool, complete: bool}.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Catalog {

	const CHECKS        = array( 'identity', 'specs', 'media', 'price' );
	const MIN_IMAGE_PX  = 1000;
	const MIN_IMAGES    = 3;

	public static function init() {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function stock_max_age() {
		return max( 1, (int) get_option( 'ym_stock_max_age_hours', 48 ) ) * HOUR_IN_SECONDS;
	}

	/** Reasons this product may not be sold, keyed by code. Empty array = ready. */
	public static function blockers( WC_Product $p ) {
		$out = array();
		foreach ( self::CHECKS as $c ) {
			if ( ! self::check( $p, $c ) ) {
				$out[ "check_$c" ] = "$c not checked";
			}
		}
		foreach ( self::spec_rows( $p ) as $row ) {
			if ( null === ( $row[1] ?? null ) && 'optional' !== ( $row[2] ?? '' ) ) {
				$out['spec_missing'] = 'critical spec missing: ' . $row[0];
				break;
			}
		}
		foreach ( self::media_problems( $p ) as $code => $msg ) {
			$out[ $code ] = $msg;
		}
		$cost = (float) $p->get_meta( '_ym_taager_cost' );
		$min  = (float) $p->get_meta( '_ym_taager_min' );
		if ( (float) $p->get_price() <= $cost ) {
			$out['price_below_cost'] = 'price at or below supplier cost';
		}
		if ( $min > 0 && (float) $p->get_price() < $min ) {
			$out['price_below_min'] = 'price below Taager minimum';
		}
		$checked = (int) $p->get_meta( '_ym_stock_checked_at' );
		if ( ! $checked || time() - $checked > self::stock_max_age() ) {
			$out['stock_unverified'] = 'stock not confirmed in the last ' . ( self::stock_max_age() / HOUR_IN_SECONDS ) . ' h';
		}
		return $out;
	}

	public static function check( WC_Product $p, $name ) {
		$v = json_decode( (string) $p->get_meta( '_ym_check_' . $name ), true );
		return is_array( $v ) && ! empty( $v['date'] );
	}

	public static function spec_rows( WC_Product $p ) {
		$v = json_decode( (string) $p->get_meta( '_ym_specs' ), true );
		if ( ! $v && $p->get_meta( '_ym_group' ) && 'yes' !== $p->get_meta( '_ym_offer_primary' ) ) {
			$v = json_decode( (string) YM_Offers::primary( $p )->get_meta( '_ym_specs' ), true );
		}
		return is_array( $v ) ? $v : array();
	}

	/** Gallery problems: too few images, low resolution, advertising crops, unknown source or rights, partial product. */
	public static function media_problems( WC_Product $p ) {
		$ids = array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) );
		if ( ! $ids && $p->get_meta( '_ym_group' ) ) {
			$primary = YM_Offers::primary( $p );
			$ids     = array_filter( array_merge( array( $primary->get_image_id() ), $primary->get_gallery_image_ids() ) );
		}
		$out = array();
		if ( count( $ids ) < self::MIN_IMAGES ) {
			$out['media_count'] = sprintf( '%d of %d images', count( $ids ), self::MIN_IMAGES );
		}
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$m    = json_decode( (string) get_post_meta( $id, '_ym_media', true ), true );
			$m    = is_array( $m ) ? $m : array();
			if ( min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) ) < self::MIN_IMAGE_PX ) {
				$out['media_resolution'] = 'image below ' . self::MIN_IMAGE_PX . ' px on the short side';
			}
			if ( empty( $m['source'] ) || empty( $m['rights'] ) ) {
				$out['media_source'] = 'image without recorded source or rights';
			}
			if ( ! empty( $m['ad_crop'] ) ) {
				$out['media_ad_crop'] = 'advertising crop used as product image';
			}
			if ( empty( $m['final'] ) ) {
				$out['media_interim'] = 'interim image';
			}
		}
		if ( $ids ) {
			$main = json_decode( (string) get_post_meta( $ids[0], '_ym_media', true ), true );
			if ( empty( $main['complete'] ) ) {
				$out['media_partial'] = 'main image does not show the complete product';
			}
		}
		return $out;
	}

	public static function purchasable( $purchasable, $product ) {
		if ( $purchasable && YM_Env::enforced() && $product instanceof WC_Product && self::blockers( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	public static function in_stock( $in_stock, $product ) {
		if ( $in_stock && YM_Env::enforced() && $product instanceof WC_Product && self::blockers( $product ) ) {
			return false;
		}
		return $in_stock;
	}

	/** Publishing an unready product on staging or production saves it as a draft instead. */
	public static function before_save( $product ) {
		if ( ! YM_Env::enforced() || 'publish' !== $product->get_status() ) {
			return;
		}
		$b = self::blockers( $product );
		if ( $b ) {
			$product->set_status( 'draft' );
			$product->update_meta_data( '_ym_gate_blocked', wp_json_encode( array_values( $b ) ) );
			set_transient( 'ym_gate_notice_' . get_current_user_id(), $product->get_name() . ': ' . implode( '; ', $b ), 60 );
		} else {
			$product->delete_meta_data( '_ym_gate_blocked' );
		}
	}

	public static function admin_notice() {
		$msg = get_transient( 'ym_gate_notice_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'ym_gate_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( 'Saved as draft, not ready to sell. ' . $msg ) . '</p></div>';
		}
	}

	/** Record a check, e.g. record( $p, 'identity', 'Rashid', 'Matched Taager page photos and box contents' ). */
	public static function record( WC_Product $p, $name, $by, $note = '' ) {
		if ( 'stock' === $name ) {
			$p->update_meta_data( '_ym_stock_checked_at', time() );
			$p->update_meta_data( '_ym_stock_checked_by', sanitize_text_field( $by ) );
		} else {
			$p->update_meta_data( '_ym_check_' . $name, wp_json_encode( array( 'date' => gmdate( 'c' ), 'by' => sanitize_text_field( $by ), 'note' => sanitize_text_field( $note ) ), JSON_UNESCAPED_UNICODE ) );
		}
		$p->save();
	}
}
