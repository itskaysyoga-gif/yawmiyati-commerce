<?php
/**
 * WP-CLI: wp ym setup | seed | supplier-check | supplier-manual
 */

defined( 'ABSPATH' ) || exit;

class YM_CLI {

	/**
	 * Configure this install for one country: currency, selling/shipping country, COD, delivery zone, pages.
	 *
	 * ## OPTIONS
	 * --country=<EG|IQ>
	 *
	 * @when after_wp_load
	 */
	public function setup( $args, $assoc ) {
		$cc = strtoupper( $assoc['country'] ?? '' );
		if ( ! isset( YM_Country::RULES[ $cc ] ) ) {
			WP_CLI::error( 'Unknown country. Use EG or IQ.' );
		}
		$r = YM_Country::RULES[ $cc ];
		update_option( 'ym_country', $cc );
		$opts = array(
			'woocommerce_currency'                    => $r['currency'],
			'woocommerce_price_num_decimals'          => $r['decimals'],
			'woocommerce_price_thousand_sep'          => ',',
			'woocommerce_price_decimal_sep'           => '.',
			'woocommerce_currency_pos'                => 'right_space',
			'woocommerce_default_country'             => $cc,
			'woocommerce_allowed_countries'           => 'specific',
			'woocommerce_specific_allowed_countries'  => array( $cc ),
			'woocommerce_ship_to_countries'           => 'specific',
			'woocommerce_specific_ship_to_countries'  => array( $cc ),
			'woocommerce_ship_to_destination'         => 'billing_only',
			'woocommerce_calc_taxes'                  => 'no',
			'woocommerce_enable_guest_checkout'       => 'yes',
			'woocommerce_enable_checkout_login_reminder' => 'no',
			'woocommerce_enable_signup_and_login_from_checkout' => 'no',
			'woocommerce_enable_myaccount_registration' => 'no',
			'woocommerce_enable_shipping_calc'        => 'no',
			'woocommerce_shipping_cost_requires_address' => 'no',
			'woocommerce_coming_soon'                 => 'no',
			'woocommerce_checkout_phone_field'        => 'required',
			'woocommerce_checkout_company_field'      => 'hidden',
			'woocommerce_checkout_address_2_field'    => 'optional',
			'woocommerce_enable_coupons'              => 'no',
			'woocommerce_onboarding_profile'          => array( 'skipped' => true ),
			'woocommerce_cod_settings'                => array(
				'enabled'            => 'yes',
				'title'              => 'الدفع عند الاستلام',
				'description'        => YM_Country::RULES[ $cc ]['copy']['payment'],
				'instructions'       => YM_Country::RULES[ $cc ]['copy']['payment'],
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'no',
			),
			'permalink_structure'                     => '/%postname%/',
		);
		foreach ( $opts as $k => $v ) {
			update_option( $k, $v );
		}
		foreach ( array( 'bacs', 'cheque', 'paypal' ) as $g ) {
			update_option( "woocommerce_{$g}_settings", array( 'enabled' => 'no' ) );
		}
		// One delivery zone for the whole country with the governorate fee method.
		$zones = WC_Shipping_Zones::get_zones();
		foreach ( $zones as $z ) {
			WC_Shipping_Zones::delete_zone( $z['id'] );
		}
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $cc );
		$zone->add_location( $cc, 'country' );
		$zone->save();
		$zone->add_shipping_method( 'ym_governorate' );
		// Classic checkout and cart pages (shortcodes), so every standard checkout hook fires.
		foreach ( array( 'checkout' => array( 'الطلب', '[woocommerce_checkout]' ), 'cart' => array( 'السلة', '[woocommerce_cart]' ) ) as $key => $p ) {
			$id = wc_get_page_id( $key );
			$data = array( 'post_title' => $p[0], 'post_content' => '<!-- wp:shortcode -->' . $p[1] . '<!-- /wp:shortcode -->', 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'checkout' === $key ? 'checkout' : 'cart' );
			if ( $id > 0 && get_post( $id ) ) {
				$data['ID'] = $id;
				wp_update_post( $data );
			} else {
				$id = wp_insert_post( $data );
			}
			update_option( "woocommerce_{$key}_page_id", $id );
		}
		flush_rewrite_rules();
		WP_CLI::success( "Configured for $cc." );
	}

	/**
	 * Create the launch offers for this country from seed/<cc>.json (development and staging only).
	 *
	 * @when after_wp_load
	 */
	public function seed( $args, $assoc ) {
		$cc   = YM_Country::code();
		$file = YM_DIR . '/seed/' . strtolower( $cc ) . '.json';
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! $data ) {
			WP_CLI::error( "No seed file for $cc" );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$media_dir = dirname( YM_DIR, 2 ) . '/media/interim/';
		foreach ( $data['products'] as $row ) {
			$id = wc_get_product_id_by_sku( $row['sku'] );
			$p  = $id ? wc_get_product( $id ) : new WC_Product_Simple();
			$p->set_name( $row['name'] );
			$p->set_slug( $row['slug'] );
			$p->set_sku( $row['sku'] );
			$p->set_regular_price( (string) $row['price'] );
			$p->set_status( 'publish' );
			$p->set_manage_stock( false );
			$p->set_stock_status( 'instock' );
			$p->set_catalog_visibility( empty( $row['primary'] ) && ! empty( $row['group'] ) ? 'hidden' : 'visible' );
			$p->set_short_description( $row['benefit'] ?? '' );
			$imgs = array();
			foreach ( $row['images'] ?? array() as $img ) {
				$imgs[] = self::import_image( $media_dir . $img['file'], $img['alt'] );
			}
			$imgs = array_values( array_filter( $imgs ) );
			if ( $imgs ) {
				$p->set_image_id( $imgs[0] );
				$p->set_gallery_image_ids( array_slice( $imgs, 1 ) );
			}
			$meta = array(
				'_ym_group'            => $row['group'] ?? '',
				'_ym_offer_primary'    => ! empty( $row['primary'] ) ? 'yes' : '',
				'_ym_offer_order'      => $row['order'] ?? 0,
				'_ym_offer_label'      => $row['label'] ?? '',
				'_ym_offer_note'       => $row['note'] ?? '',
				'_ym_offer_saving'     => $row['saving'] ?? '',
				'_ym_benefit'          => $row['benefit'] ?? '',
				'_ym_diary'            => $row['diary'] ?? '',
				'_ym_fit'              => $row['fit'] ?? '',
				'_ym_benefits'         => wp_json_encode( $row['benefits'] ?? array(), JSON_UNESCAPED_UNICODE ),
				'_ym_specs'            => wp_json_encode( $row['specs'] ?? array(), JSON_UNESCAPED_UNICODE ),
				'_ym_taager_product_id'=> $row['taager_product_id'],
				'_ym_taager_cost'      => $row['taager_cost'],
				'_ym_source'           => $data['source'],
			);
			foreach ( $meta as $k => $v ) {
				$p->update_meta_data( $k, $v );
			}
			$p->save();
			WP_CLI::log( sprintf( '%s %s %s', $row['sku'], $row['price'], $row['name'] ) );
		}
		update_option( 'show_on_front', 'posts' );
		WP_CLI::success( 'Seeded ' . count( $data['products'] ) . " offers for $cc." );
	}

	private static function import_image( $path, $alt ) {
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		$existing = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_ym_source_file', 'meta_value' => basename( $path ), 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			return $existing[0];
		}
		$tmp = wp_tempnam( basename( $path ) );
		copy( $path, $tmp );
		$id = media_handle_sideload( array( 'name' => basename( $path ), 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $id, '_ym_source_file', basename( $path ) );
		return $id;
	}

	/**
	 * Run the supplier hand-off check for an order now.
	 *
	 * <order_id>
	 *
	 * @subcommand supplier-check
	 */
	public function supplier_check( $args ) {
		YM_Supplier::check( (int) $args[0] );
		$o = wc_get_order( (int) $args[0] );
		WP_CLI::log( (string) $o->get_meta( '_ym_supplier_status' ) );
	}

	/**
	 * Record a manual Taager entry for an order.
	 *
	 * <order_id> <taager_order_id>
	 *
	 * @subcommand supplier-manual
	 */
	public function supplier_manual( $args ) {
		$r = YM_Supplier::mark_manual( wc_get_order( (int) $args[0] ), $args[1] );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_code() . ': ' . $r->get_error_message() );
		}
		WP_CLI::success( 'manual' );
	}
}
