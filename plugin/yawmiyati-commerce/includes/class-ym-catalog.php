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
 * Media meta on each attachment, _ym_media JSON (docs/MEDIA-STANDARDS.md):
 *   role        clean | detail | scale | demo | contents | lifestyle
 *   sku, variant  the exact supplier SKU and variant the picture shows
 *   exact_item  a person compared it with the item being sold
 *   source, rights, captured, original (where the untouched original file is kept)
 *   final       not interim; complete (main image shows the whole product)
 *   ad_crop, screenshot, enhanced (changes appearance, parts or size): never allowed
 * Gate = COVERAGE, not a count: at least 3 accepted images that together show the clean whole product (first),
 * a detail, and scale or dimensions. A demonstration (photo or a real, unedited video) is recommended and reported,
 * not required. A generated video never counts as a demonstration.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Catalog {

	const CHECKS        = array( 'identity', 'specs', 'media', 'price' );
	const MIN_IMAGE_PX  = 1000;
	const MIN_IMAGES    = 3;
	const NEED_ROLES    = array( 'clean', 'detail', 'scale' );

	public static function init() {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/** Shop categories: Latin slugs, names in this store's voice (config/categories.json). Created on first use. */
	public static function category_id( $slug ) {
		$names = (array) json_decode( (string) file_get_contents( YM_DIR . '/config/categories.json' ), true );
		$voice = YM_Country::rules()['voice'];
		$name  = $names[ $voice ][ $slug ] ?? $slug;
		$term  = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term ) {
			if ( $term->name !== $name ) {
				wp_update_term( (int) $term->term_id, 'product_cat', array( 'name' => $name ) ); // config/categories.json is the source
			}
			return (int) $term->term_id;
		}
		$made  = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
		return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
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

	/** Gallery problems, keyed by code. Coverage of the required views, then each picture's provenance and quality. */
	public static function media_problems( WC_Product $p ) {
		$ids = array_values( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) );
		$sku = $p->get_sku();
		if ( ! $ids && $p->get_meta( '_ym_group' ) ) {
			$primary = YM_Offers::primary( $p );
			$ids     = array_values( array_filter( array_merge( array( $primary->get_image_id() ), $primary->get_gallery_image_ids() ) ) );
		}
		$out   = array();
		$roles = array();
		foreach ( $ids as $i => $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$m    = json_decode( (string) get_post_meta( $id, '_ym_media', true ), true );
			$m    = is_array( $m ) ? $m : array();
			if ( min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) ) < self::MIN_IMAGE_PX ) {
				$out['media_resolution'] = 'image below ' . self::MIN_IMAGE_PX . ' px on the short side';
			}
			if ( empty( $m['source'] ) || empty( $m['rights'] ) ) {
				$out['media_source'] = 'image without recorded source or rights';
			}
			if ( empty( $m['original'] ) ) {
				$out['media_original'] = 'original file not recorded';
			}
			if ( ! empty( $m['ad_crop'] ) || ! empty( $m['screenshot'] ) || ! empty( $m['enhanced'] ) ) {
				$out['media_rejected'] = 'screenshot, advertising crop or appearance-changing edit';
			}
			if ( empty( $m['final'] ) ) {
				$out['media_interim'] = 'interim image';
			}
			// The exact item: checked by a person, and recorded against this SKU (offers of one page share a gallery).
			$group_skus = array_map( fn( $o ) => $o->get_sku(), YM_Offers::group( $p ) );
			if ( empty( $m['exact_item'] ) || ( ! empty( $m['sku'] ) && ! in_array( $m['sku'], array_merge( array( $sku ), $group_skus ), true ) ) ) {
				$out['media_unmatched'] = 'not confirmed to show the exact item and variant sold';
			}
			$ok = empty( $out['media_rejected'] ) && ! empty( $m['final'] );
			if ( $ok && ! empty( $m['role'] ) ) {
				$roles[ $m['role'] ] = true;
			}
			if ( 0 === $i && ( empty( $m['complete'] ) || 'clean' !== ( $m['role'] ?? 'clean' ) ) ) {
				$out['media_partial'] = 'main image must show the complete product cleanly';
			}
			$card = 0 === $i ? ( YM_Media::card( $id ) ?? YM_Media::derive( $id ) ) : null;
			if ( is_array( $card ) && ! empty( $card['weak'] ) ) {
				$out['media_card_weak'] = 'main image is too wide or tall for a strong 4:5 card: use another authorised source image (never an enlarged crop)';
			}
			$out = array_merge( $out, YM_Media::rights_problems( $id, 'site' ) );
		}
		if ( count( $ids ) < self::MIN_IMAGES ) {
			$out['media_count'] = sprintf( '%d of at least %d images', count( $ids ), self::MIN_IMAGES );
		}
		$missing = array_diff( self::NEED_ROLES, array_keys( $roles ) );
		if ( $missing ) {
			$out['media_coverage'] = 'missing views: ' . implode( ', ', $missing );
		}
		$video = json_decode( (string) $p->get_meta( '_ym_video' ), true );
		if ( is_array( $video ) && ! empty( $video['generated'] ) && ! empty( $video['demo_verified'] ) ) {
			$out['video_generated_demo'] = 'a generated video cannot be a demonstration';
		}
		if ( is_array( $video ) && ! empty( $video['src'] ) && empty( $video['poster'] ) ) {
			$out['video_poster'] = 'video without a poster image';
		}
		return $out;
	}

	/** Advice that does not block publication (shown by wp ym gate). */
	public static function media_advice( WC_Product $p ) {
		$ids   = array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) );
		$roles = array_map( fn( $id ) => ( json_decode( (string) get_post_meta( $id, '_ym_media', true ), true ) ?: array() )['role'] ?? '', $ids );
		$video = json_decode( (string) $p->get_meta( '_ym_video' ), true );
		$demo  = in_array( 'demo', $roles, true ) || ( is_array( $video ) && ! empty( $video['demo_verified'] ) && empty( $video['generated'] ) );
		$out   = array();
		if ( ! $demo ) {
			$out[] = 'no genuine demonstration (photo or real video): recommended for ads and the product page';
		}
		if ( mb_strlen( (string) $p->get_meta( '_ym_diary' ) ) > 220 ) {
			$out[] = 'diary note longer than 220 characters: keep it short';
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
