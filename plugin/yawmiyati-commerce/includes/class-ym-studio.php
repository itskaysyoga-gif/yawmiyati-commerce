<?php
/**
 * Approved facts for the Content Studio (29 Oct brief §8). Spec: docs/CONTENT-STUDIO.md.
 *
 * The studio (studio/, a separate Node project that renders in the cloud, never on this host) builds every script,
 * video, caption and email for a product from ONE export: what the store itself shows and sells. Nothing else may be
 * claimed. The export carries an offer_version (a hash of price, delivery fees, offer set, stock state and the landing
 * address): the publisher re-reads it right before posting, and any change invalidates the scheduled asset.
 *
 *   wp ym studio-facts <sku>            JSON to stdout (local, CI fixtures)
 *   GET /wp-json/ym/v1/studio-facts?sku= header X-YM-Studio-Key: YM_STUDIO_KEY (the publisher's freshness check)
 *
 * Contents: identity, name, price and currency, every offer of the page with its price, delivery fee range and the
 * one-fee rule, the benefit/fit/diary lines and verified specs only (unknown specs are listed as unknown so a script
 * can never fill them), media with their rights (channels, approval, expiry), stock state and its age, gate
 * blockers, market gates and the landing URL. No customer data, no supplier cost.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Studio {

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'ym/v1', '/studio-facts', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'allowed' ), 'callback' => array( __CLASS__, 'rest' ) ) );
		} );
	}

	public static function allowed( WP_REST_Request $req ) {
		$key = defined( 'YM_STUDIO_KEY' ) ? (string) YM_STUDIO_KEY : '';
		return '' !== $key && hash_equals( $key, (string) $req->get_header( 'x_ym_studio_key' ) );
	}

	public static function rest( WP_REST_Request $req ) {
		$f = self::facts( (string) $req->get_param( 'sku' ) );
		return $f ? new WP_REST_Response( $f, 200 ) : new WP_REST_Response( array( 'error' => 'unknown_sku' ), 404 );
	}

	public static function facts( $sku ) {
		$id = wc_get_product_id_by_sku( $sku );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return null;
		}
		$page  = YM_Offers::primary( $p );
		$rules = YM_Country::rules();
		$fees  = array_values( array_unique( array_map( fn( $g ) => (float) $g[1], $rules['governorates'] ) ) );
		$json  = fn( $k ) => json_decode( (string) $page->get_meta( $k ), true ) ?: array();
		$offers = array();
		foreach ( YM_Offers::group( $page ) as $o ) {
			$offers[] = array(
				'sku'      => $o->get_sku(),
				'label'    => (string) $o->get_meta( '_ym_offer_label' ),
				'note'     => (string) $o->get_meta( '_ym_offer_note' ),
				'price'    => (float) $o->get_price(),
				'price_text' => YM_Country::money( $o->get_price() ),
				'in_stock' => $o->is_in_stock(),
				'default'  => 'yes' === $o->get_meta( '_ym_offer_primary' ) || $o->get_id() === $page->get_id(),
			);
		}
		$media = array();
		foreach ( array_values( array_filter( array_merge( array( $page->get_image_id() ), $page->get_gallery_image_ids() ) ) ) as $mid ) {
			$m       = YM_Media::record( $mid );
			$card    = YM_Media::card( $mid );
			$media[] = array(
				'id'       => (int) $mid,
				'url'      => (string) wp_get_attachment_url( $mid ),
				'file'     => basename( (string) get_attached_file( $mid ) ),
				'alt'      => (string) get_post_meta( $mid, '_wp_attachment_image_alt', true ),
				'role'     => (string) ( $m['role'] ?? '' ),
				'final'    => ! empty( $m['final'] ),
				'source'   => (string) ( $m['source'] ?? '' ),
				'rights'   => (string) ( $m['rights'] ?? '' ),
				'channels' => (array) ( $m['channels'] ?? array() ),
				'approved' => (string) ( $m['approved'] ?? '' ),
				'expires'  => (string) ( $m['expires'] ?? '' ),
				'organic'  => ! YM_Media::rights_problems( $mid, 'organic' ),
				'paid'     => ! YM_Media::rights_problems( $mid, 'paid' ),
				'card'     => $card ? array( 'mode' => $card['mode'], 'weak' => $card['weak'] ) : null,
			);
		}
		$specs = array();
		foreach ( $json( '_ym_specs' ) as $s ) {
			$specs[] = array( 'label' => (string) $s[0], 'value' => $s[1] ?? null ); // null = unknown: never stated
		}
		$stock_at = (int) $page->get_meta( '_ym_stock_checked_at' );
		$facts    = array(
			'country'        => YM_Country::code(),
			'country_name'   => YM_Country::copy( 'country_name' ),
			'voice'          => $rules['voice'] ?? '',
			'currency'       => get_woocommerce_currency(),
			'symbol'         => $rules['symbol'],
			'decimals'       => YM_Country::decimals(),
			'sku'            => $page->get_sku(),
			'name'           => $page->get_name(),
			'price'          => (float) $page->get_price(),
			'price_text'     => YM_Country::money( $page->get_price() ),
			'offers'         => $offers,
			'cod'            => explode( '|', YM_Country::copy( 'home_trust' ) )[0],
			'delivery'       => array( 'min' => min( $fees ), 'max' => max( $fees ), 'text' => count( $fees ) > 1 ? sprintf( YM_Country::copy( 'fee_range' ), YM_Country::number( min( $fees ) ), YM_Country::money( max( $fees ) ) ) : sprintf( YM_Country::copy( 'fee_flat' ), YM_Country::money( $fees[0] ) ), 'one_fee' => YM_Country::copy( 'one_fee' ), 'payment' => YM_Country::copy( 'payment' ) ),
			'benefit'        => (string) $page->get_meta( '_ym_benefit' ),
			'benefits'       => array_map( fn( $b ) => array( 'title' => (string) $b[0], 'text' => (string) ( $b[1] ?? '' ) ), $json( '_ym_benefits' ) ),
			'fit'            => (string) $page->get_meta( '_ym_fit' ),
			'diary'          => (string) $page->get_meta( '_ym_diary' ),
			'specs'          => $specs,
			'media'          => $media,
			'in_stock'       => $page->is_in_stock(),
			'stock_checked'  => $stock_at ? gmdate( 'c', $stock_at ) : null,
			'stock_fresh'    => $stock_at && time() - $stock_at <= YM_Catalog::stock_max_age(),
			'gate_blockers'  => array_keys( YM_Catalog::blockers( $page ) ),
			'market'         => array( 'catalog' => YM_Markets::is_on( YM_Country::code(), 'catalog' ), 'checkout' => YM_Markets::is_on( YM_Country::code(), 'checkout' ), 'ads' => YM_Markets::is_on( YM_Country::code(), 'ads' ) ),
			'landing_url'    => $page->get_permalink(),
			'environment'    => YM_Env::type(),
			'exported_at'    => gmdate( 'c' ),
		);
		$facts['offer_version'] = substr( sha1( wp_json_encode( array( $facts['price'], $facts['offers'], $facts['delivery']['min'], $facts['delivery']['max'], $facts['in_stock'], $facts['landing_url'], array_map( fn( $m ) => array( $m['id'], $m['organic'], $m['paid'] ), $media ) ) ) ), 0, 12 );
		return $facts;
	}
}
