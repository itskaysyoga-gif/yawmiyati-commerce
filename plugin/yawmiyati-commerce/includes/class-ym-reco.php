<?php
/**
 * Recommendations v1 (29 Oct brief §6): deterministic, curated, no live model and no behavioural profile.
 *
 * Rules (config/recommendations.json, per country): SKU -> [{sku, type complement|substitute}], in priority order.
 * A candidate is shown only when it is, in THIS store:
 *   published, purchasable and in stock (on staging/production that includes the catalog gate: verified record,
 *   media, price and a stock check within 48 h), not an offer of the page she is on, not already in her cart (a
 *   bundle counts as its component SKUs, both ways), and not in an exclude_with pair with anything in the cart.
 * Ranking: curated priority, then contribution per unit (price - recorded supplier cost) less any recorded
 *   return/defect penalty (_ym_reco_penalty, set by the weekly review). At most three; nothing when none qualify.
 * Labels say what the item is to her ("goes with it" / "an alternative"). Nothing is preselected or added for her;
 *   adding one goes through the normal cart, so delivery and the total are recalculated by WooCommerce. Placed on
 *   the product page below the decision content and on the cart page, never inside the checkout form, never after
 *   the order (an order handed to the supplier is never changed).
 * Measurement: impressions and clicks as GA4 view_item_list / select_item (consent applies as for every tag);
 *   items added carry _ym_reco_from on the order line, so `wp ym reco-report` joins them to verified outcomes.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Reco {

	const MAX = 3;

	public static function init() {
		add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'render_cart' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_meta' ), 10, 3 );
	}

	public static function config() {
		static $all = null;
		if ( null === $all ) {
			$all = json_decode( (string) file_get_contents( YM_DIR . '/config/recommendations.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$cc = strtolower( YM_Country::code() );
		$c  = is_array( $all[ $cc ] ?? null ) ? $all[ $cc ] : array();
		return array( 'rules' => (array) ( $c['rules'] ?? array() ), 'bundles' => (array) ( $c['bundles'] ?? array() ), 'exclude_with' => (array) ( $c['exclude_with'] ?? array() ) );
	}

	/** A SKU as the set of SKUs it physically contains. */
	private static function parts( $sku, $bundles ) {
		return array_values( array_unique( array_merge( array( $sku ), (array) ( $bundles[ $sku ] ?? array() ) ) ) );
	}

	/** SKUs in the cart, bundles expanded. */
	public static function cart_skus() {
		$cfg = self::config();
		$out = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$p = wc_get_product( $item['product_id'] );
			if ( $p ) {
				$out = array_merge( $out, self::parts( $p->get_sku(), $cfg['bundles'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Recommendations for one or more source products (a page, or the cart). Returns [ [product, type, source sku] ].
	 * $sources: WC_Product list. $exclude_skus: SKUs already chosen (cart).
	 */
	public static function for_products( array $sources, array $exclude_skus = array() ) {
		$cfg      = self::config();
		$blocked  = array();
		foreach ( $exclude_skus as $s ) {
			$blocked = array_merge( $blocked, self::parts( $s, $cfg['bundles'] ) );
		}
		$own = array();
		foreach ( $sources as $src ) {
			foreach ( YM_Offers::group( $src ) as $o ) {
				$own = array_merge( $own, self::parts( $o->get_sku(), $cfg['bundles'] ) );
			}
		}
		$cands = array();
		$prio  = 0;
		foreach ( $sources as $src ) {
			foreach ( (array) ( $cfg['rules'][ $src->get_sku() ] ?? array() ) as $r ) {
				++$prio;
				$sku = (string) ( $r['sku'] ?? '' );
				if ( isset( $cands[ $sku ] ) ) {
					continue;
				}
				$p = self::eligible( $sku, array_merge( $own, $blocked ), $cfg );
				if ( ! $p ) {
					continue;
				}
				$cost  = (float) $p->get_meta( '_ym_taager_cost' );
				$score = 1000 * ( 100 - $prio ) + ( $cost > 0 ? (float) $p->get_price() - $cost : 0 ) - (float) $p->get_meta( '_ym_reco_penalty' );
				$cands[ $sku ] = array( 'product' => $p, 'type' => 'substitute' === ( $r['type'] ?? '' ) ? 'substitute' : 'complement', 'from' => $src->get_sku(), 'score' => $score );
			}
		}
		uasort( $cands, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( array_values( $cands ), 0, self::MAX );
	}

	/** The product for $sku if it may be recommended here, else null. */
	public static function eligible( $sku, array $taken, array $cfg ) {
		$id = wc_get_product_id_by_sku( $sku );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_purchasable() || ! $p->is_in_stock() || 'hidden' === $p->get_catalog_visibility() ) {
			return null;
		}
		if ( YM_Env::enforced() && YM_Catalog::blockers( $p ) ) {
			return null;
		}
		if ( '' !== (string) $p->get_meta( '_ym_conditional' ) ) {
			return null; // never suggested while its open questions stand, in previews too
		}
		if ( array_intersect( self::parts( $sku, $cfg['bundles'] ), $taken ) || in_array( $sku, $taken, true ) ) {
			return null;
		}
		foreach ( $cfg['exclude_with'] as $pair ) {
			if ( is_array( $pair ) && in_array( $sku, $pair, true ) && array_intersect( $pair, $taken ) ) {
				return null;
			}
		}
		return $p;
	}

	/* ---------- cart line attribution ---------- */

	public static function cart_item_data( $data, $product_id ) {
		if ( ! empty( $_POST['ym_reco_from'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the offer form's nonce is checked in YM_Offers::handle_post before add_to_cart
			$data['ym_reco_from'] = substr( sanitize_text_field( wp_unslash( $_POST['ym_reco_from'] ) ), 0, 40 ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return $data;
	}

	public static function line_meta( $item, $key, $values ) {
		if ( ! empty( $values['ym_reco_from'] ) ) {
			$item->add_meta_data( '_ym_reco_from', $values['ym_reco_from'], true );
		}
	}

	/* ---------- markup ---------- */

	public static function render_page( WC_Product $page ) {
		return self::render( self::for_products( array( $page ), self::cart_skus() ), 'reco_pdp', $page->get_id() );
	}

	public static function render_cart() {
		$sources = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$p = wc_get_product( $item['product_id'] );
			if ( $p ) {
				$sources[] = $p;
			}
		}
		echo self::render( self::for_products( $sources, self::cart_skus() ), 'reco_cart', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render()
	}

	private static function render( array $recs, $list, $back_page ) {
		if ( ! $recs ) {
			return '';
		}
		$items = array_map( fn( $r ) => YM_Tracking::item( $r['product'], 1 ), $recs );
		$out   = '<section class="ym-section ym-reco" data-ym-reco="' . esc_attr( $list ) . '" data-ym-reco-items="' . esc_attr( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ) . '"><h2>' . esc_html( YM_Country::copy( 'reco_title' ) ) . '</h2><ul class="ym-reco__list">';
		foreach ( $recs as $i => $r ) {
			$p    = $r['product'];
			$page = YM_Offers::primary( $p );
			$out .= '<li class="ym-reco__item" data-ym-reco-type="' . esc_attr( $r['type'] ) . '">'
				. '<a class="ym-reco__img" href="' . esc_url( $page->get_permalink() ) . '" data-ym-reco-click="' . (int) $i . '">' . YM_Media::card_html( (int) $p->get_image_id(), $p->get_name(), array( 'sizes' => '96px' ) ) . '</a>'
				. '<div class="ym-reco__body"><span class="ym-reco__label">' . esc_html( YM_Country::copy( 'complement' === $r['type'] ? 'reco_complement' : 'reco_substitute' ) ) . '</span>'
				. '<a class="ym-reco__name" href="' . esc_url( $page->get_permalink() ) . '" data-ym-reco-click="' . (int) $i . '">' . esc_html( $p->get_name() ) . '</a>'
				. '<span class="ym-reco__price">' . esc_html( YM_Country::money( $p->get_price() ) ) . '</span>'
				. '<form method="post" class="ym-reco__add">'
				. wp_nonce_field( 'ym_offer', 'ym_offer_nonce', false, false )
				. '<input type="hidden" name="ym_offer" value="' . (int) $p->get_id() . '"><input type="hidden" name="ym_page" value="' . (int) $page->get_id() . '">'
				. '<input type="hidden" name="ym_qty" value="1"><input type="hidden" name="ym_intent" value="cart">'
				. '<input type="hidden" name="ym_reco_from" value="' . esc_attr( $r['from'] ) . '"><input type="hidden" name="ym_back" value="' . ( $back_page ? (int) $back_page : 'cart' ) . '">'
				. '<button type="submit" class="ym-btn ym-btn--secondary" data-ym-reco-add="' . (int) $i . '">' . esc_html( YM_Country::copy( 'reco_add' ) ) . '</button></form>'
				. '</div></li>';
		}
		// On the cart the delivery line in the summary already states the one-fee rule; say it once.
		$out .= '</ul>' . ( 'reco_cart' === $list ? '' : '<p class="ym-reco__note">' . esc_html( YM_Country::copy( 'one_fee' ) ) . '</p>' ) . '</section>';
		$out .= '<script>(function(){var s=document.currentScript.previousElementSibling,items=JSON.parse(s.getAttribute("data-ym-reco-items")),l=s.getAttribute("data-ym-reco"),seen=false;function push(e,x){window.dataLayer=window.dataLayer||[];dataLayer.push({ecommerce:null});dataLayer.push({event:e,ecommerce:{item_list_id:l,items:x}});}if("IntersectionObserver" in window){new IntersectionObserver(function(en,o){if(en[0].isIntersecting&&!seen){seen=true;push("view_item_list",items);o.disconnect();}}).observe(s);}s.querySelectorAll("[data-ym-reco-click],[data-ym-reco-add]").forEach(function(a){a.addEventListener("click",function(){var i=+(a.getAttribute("data-ym-reco-click")||a.getAttribute("data-ym-reco-add"));push("select_item",[items[i]]);});});})();</script>';
		return $out;
	}

	/* ---------- report ---------- */

	/** Lines added from a recommendation in orders placed since $from: by source -> item, with verified outcomes. */
	public static function report( $from ) {
		$rows = array();
		foreach ( wc_get_orders( array( 'limit' => -1, 'date_created' => '>' . (int) $from, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
			foreach ( $o->get_items() as $it ) {
				$src = (string) $it->get_meta( '_ym_reco_from' );
				if ( ! $src ) {
					continue;
				}
				$p   = $it->get_product();
				$key = $src . ' -> ' . ( $p ? $p->get_sku() : $it->get_product_id() );
				$out = YM_Lifecycle::outcome( $o );
				$rows[ $key ] = $rows[ $key ] ?? array( 'lines' => 0, 'units' => 0, 'value' => 0.0, 'delivered_kept' => 0, 'unknown_or_open' => 0, 'not_kept' => 0 );
				$rows[ $key ]['lines']++;
				$rows[ $key ]['units'] += (int) $it->get_quantity();
				$rows[ $key ]['value'] += (float) $it->get_total();
				if ( 'delivered_kept' === $out ) {
					$rows[ $key ]['delivered_kept']++;
				} elseif ( in_array( $out, array( 'in_progress', 'unverified', 'conflict' ), true ) ) {
					$rows[ $key ]['unknown_or_open']++;
				} else {
					$rows[ $key ]['not_kept']++;
				}
			}
		}
		return $rows;
	}
}
