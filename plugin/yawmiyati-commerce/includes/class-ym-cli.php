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
	 *
	 * --country=<country>
	 * : EG, IQ, SA, AE or OM.
	 *
	 * [--store-urls=<json>]
	 * : Home URL of every country store (country switcher, hreflang), e.g. {"EG":"https://yawmiyati.shop/eg/","IQ":"https://yawmiyati.shop/iq/"}
	 *
	 * @when after_wp_load
	 */
	public function setup( $args, $assoc ) {
		$cc = strtoupper( $assoc['country'] ?? '' );
		if ( ! in_array( $cc, YM_Country::codes(), true ) ) {
			WP_CLI::error( 'Unknown country. Use one of: ' . implode( ', ', YM_Country::codes() ) );
		}
		$r = YM_Country::rules( $cc );
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
				'description'        => YM_Country::copy( 'payment', $cc ),
				'instructions'       => YM_Country::copy( 'payment', $cc ),
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
		if ( ! empty( $assoc['store-urls'] ) ) {
			$urls = json_decode( $assoc['store-urls'], true );
			if ( ! is_array( $urls ) ) {
				WP_CLI::error( '--store-urls must be JSON, e.g. {"EG":"https://yawmiyati.shop/eg/"}' );
			}
			update_option( 'ym_store_urls', array_map( 'esc_url_raw', $urls ) );
		}
		$pages = YM_Pages::install( $cc );
		YM_Board::install_role();
		update_option( 'woocommerce_logs_retention_period_days', 90 ); // operational logs kept 90 days, then deleted
		// WooCommerce's own order attribution sets tracking cookies before consent and duplicates ours (YM_Tracking).
		update_option( 'woocommerce_feature_order_attribution_enabled', 'no' );
		update_option( 'blogname', 'يومياتي' );
		update_option( 'blogdescription', YM_Country::copy( 'home_lede', $cc ) );
		flush_rewrite_rules();
		WP_CLI::success( "Configured for $cc (" . count( $pages ) . ' support pages).' );
	}

	/**
	 * Create the launch offers for this country from seed/<cc>.json (development and staging only).
	 *
	 * @when after_wp_load
	 */
	public function seed( $args, $assoc ) {
		// Seed data is interim and unverified. It must never reach a live store, with or without a flag.
		if ( YM_Env::is_production() ) {
			WP_CLI::error( 'Refusing to seed: this is a production store (WP_ENVIRONMENT_TYPE=production). Seed data is for local and staging stores only.' );
		}
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
			if ( ! empty( $row['category'] ) ) {
				$p->set_category_ids( array( YM_Catalog::category_id( $row['category'] ) ) );
			}
			$p->set_featured( ! empty( $row['featured'] ) );
			$imgs = array();
			foreach ( $row['images'] ?? array() as $img ) {
				$imgs[] = self::import_image( $media_dir . $img['file'], $img['alt'], ! empty( $img['complete'] ) );
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
				'_ym_taager_min'       => $row['taager_min'] ?? '',
				'_ym_source'           => $data['source'],
				'_ym_dev_seed'         => 'yes',
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

	private static function import_image( $path, $alt, $complete = false ) {
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		// Interim media: Taager listing thumbnails cropped to remove ad text. Recorded as such so the gate blocks them.
		$media = wp_json_encode(
			array(
				'source'   => 'Taager listing thumbnail (600 px), captured 2026-10-28, cropped to remove ad text: media/interim/' . basename( $path ),
				'rights'   => 'Taager support chat 2026-10-28: supplier images may be used (not written terms)',
				'captured' => '2026-10-28',
				'final'    => false,
				'ad_crop'  => true,
				'complete' => (bool) $complete,
			)
		);
		$existing = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_ym_source_file', 'meta_value' => basename( $path ), 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			update_post_meta( $existing[0], '_ym_media', $media );
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
		update_post_meta( $id, '_ym_media', $media );
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
		WP_CLI::log( YM_Supplier::status( wc_get_order( (int) $args[0] ) ) );
	}

	/**
	 * Record the result of looking the order up in Taager.
	 *
	 * <order_id>
	 * [--found=<taager_order_id>]
	 * [--not-found]
	 * [--by=<name>]
	 *
	 * @subcommand supplier-reconcile
	 */
	public function supplier_reconcile( $args, $assoc ) {
		$o  = wc_get_order( (int) $args[0] );
		$by = $assoc['by'] ?? 'cli';
		if ( ! empty( $assoc['found'] ) ) {
			$r = YM_Supplier::mark_found( $o, $assoc['found'], $by );
		} elseif ( ! empty( $assoc['not-found'] ) ) {
			$r = YM_Supplier::mark_not_found( $o, $by );
		} else {
			WP_CLI::error( 'Use --found=<id> or --not-found.' );
		}
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_code() . ': ' . $r->get_error_message() );
		}
		WP_CLI::success( YM_Supplier::status( wc_get_order( (int) $args[0] ) ) );
	}

	/**
	 * Record a manual Taager entry for an order (only after a fresh --not-found reconciliation).
	 *
	 * <order_id> <taager_order_id>
	 *
	 * @subcommand supplier-manual
	 */
	public function supplier_manual( $args ) {
		$r = YM_Supplier::mark_manual( wc_get_order( (int) $args[0] ), $args[1], 'cli' );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_code() . ': ' . $r->get_error_message() );
		}
		WP_CLI::success( 'manual' );
	}

	/**
	 * Record a catalog check for a product.
	 *
	 * <sku>
	 * [--identity=<note>]
	 * [--specs=<note>]
	 * [--media=<note>]
	 * [--price=<note>]
	 * [--stock]
	 * [--by=<name>]
	 *
	 * @subcommand check
	 */
	public function check( $args, $assoc ) {
		$p = wc_get_product( wc_get_product_id_by_sku( $args[0] ) );
		if ( ! $p ) {
			WP_CLI::error( 'Unknown SKU ' . $args[0] );
		}
		$by = $assoc['by'] ?? 'Rashid';
		foreach ( array( 'identity', 'specs', 'media', 'price' ) as $c ) {
			if ( ! empty( $assoc[ $c ] ) ) {
				YM_Catalog::record( $p, $c, $by, (string) $assoc[ $c ] );
			}
		}
		if ( ! empty( $assoc['stock'] ) ) {
			YM_Catalog::record( $p, 'stock', $by );
		}
		$this->gate( array( $args[0] ), array() );
	}

	/**
	 * List what blocks each product from being sold (the gate is enforced on staging and production).
	 *
	 * [<sku>...]
	 * [--format=<format>]
	 *
	 * @subcommand gate
	 */
	public function gate( $args, $assoc ) {
		$rows = array();
		$ps   = $args ? array_map( fn( $s ) => wc_get_product( wc_get_product_id_by_sku( $s ) ), $args ) : wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft' ) ) );
		foreach ( array_filter( $ps ) as $p ) {
			$b      = YM_Catalog::blockers( $p );
			$rows[] = array( 'sku' => $p->get_sku(), 'status' => $p->get_status(), 'ready' => $b ? 'no' : 'yes', 'blockers' => implode( '; ', array_keys( $b ) ), 'advice' => implode( '; ', YM_Catalog::media_advice( $p ) ) );
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'sku', 'status', 'ready', 'blockers', 'advice' ) );
	}

	/**
	 * Report: unique orders, lines and units; stage history; outcomes; money kept apart; unknowns listed.
	 *
	 * [--days=<days>]
	 * : Orders created in the last N days. Default 7.
	 *
	 * [--from=<date>]
	 * : Start date (YYYY-MM-DD), instead of --days.
	 *
	 * [--to=<date>]
	 * : End date (YYYY-MM-DD, exclusive). Default now.
	 *
	 * @subcommand funnel
	 * @alias report
	 */
	public function funnel( $args, $assoc ) {
		$to   = isset( $assoc['to'] ) ? strtotime( $assoc['to'] . ' 00:00:00 UTC' ) : time() + 1;
		$from = isset( $assoc['from'] ) ? strtotime( $assoc['from'] . ' 00:00:00 UTC' ) : $to - (int) ( $assoc['days'] ?? 7 ) * DAY_IN_SECONDS;
		WP_CLI::log( wp_json_encode( YM_Report::build( $from, $to ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Everything that must be true before this store takes real orders. Exits non-zero while anything is open.
	 *
	 * @subcommand launch-check
	 */
	public function launch_check() {
		$rows = array();
		$add  = function ( $area, $ok, $detail ) use ( &$rows ) {
			$rows[] = array( 'area' => $area, 'ok' => $ok ? 'yes' : 'NO', 'detail' => $detail );
		};
		$add( 'environment', YM_Env::is_production(), 'WP_ENVIRONMENT_TYPE=' . YM_Env::type() );
		$sellable = 0;
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) ) as $p ) {
			$b = YM_Catalog::blockers( $p );
			$sellable += $b ? 0 : 1;
			$add( 'product ' . $p->get_sku(), ! $b, $b ? implode( '; ', array_keys( $b ) ) : 'ready' );
			$add( 'seed data ' . $p->get_sku(), 'yes' !== $p->get_meta( '_ym_dev_seed' ), 'yes' === $p->get_meta( '_ym_dev_seed' ) ? 'created by wp ym seed; re-create from verified data' : 'ok' );
		}
		$add( 'sellable products', $sellable > 0, (string) $sellable );
		$pending = YM_Pages::pending();
		$add( 'policy pages', ! $pending, $pending ? count( $pending ) . ' unconfirmed fact(s): ' . implode( ' | ', wp_list_pluck( $pending, 'page' ) ) : 'ok' );
		$copy = YM_Country::copy_all( YM_Country::code() );
		$add( 'native language review', (bool) get_option( 'ym_language_reviewed_' . YM_Country::code() ), 'docs/LANGUAGE-REVIEW.md; set option ym_language_reviewed_' . YM_Country::code() . ' when signed off' );
		foreach ( YM_Tracking::launch_checks() as $k => $v ) {
			$add( 'measurement ' . $k, $v[0], $v[1] );
		}
		$add( 'supplier reference key', (bool) get_option( 'ym_supplier_ref_keys_verified' ), 'confirm which meta key the Taager plugin writes, then set ym_supplier_ref_keys and ym_supplier_ref_keys_verified' );
		$add( 'status sync', (bool) get_option( 'ym_status_sync_verified' ), 'confirm Taager status sync and set ym_stage_map + ym_status_sync_verified' );
		$add( 'search indexing', '1' === (string) get_option( 'blog_public' ), 'Settings > Reading: allow search engines (production only)' );
		$runs = (array) get_option( 'ym_ops_runs', array() );
		$add( 'scheduled jobs', count( $runs ) >= 3, count( $runs ) . ' job(s) have run' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'area', 'ok', 'detail' ) );
		$open = count( array_filter( $rows, fn( $r ) => 'NO' === $r['ok'] ) );
		if ( $open ) {
			WP_CLI::error( "$open item(s) open.", false );
			exit( 1 );
		}
		WP_CLI::success( 'Ready.' );
	}

	/**
	 * Run an operations job now: supplier_sweep, stock_guard, daily_digest, weekly_report.
	 *
	 * <job>
	 *
	 * @subcommand ops-run
	 */
	public function ops_run( $args ) {
		$fn = 'run_' . $args[0];
		if ( ! method_exists( 'YM_Ops', $fn ) ) {
			WP_CLI::error( 'Unknown job' );
		}
		YM_Ops::$fn();
		WP_CLI::success( wp_json_encode( get_option( 'ym_ops_runs' )[ $args[0] ] ?? array() ) );
	}

	/**
	 * Record what happened to an order (staff, with evidence). Verified by default: a person saw the evidence.
	 *
	 * <order>
	 * : Order ID.
	 *
	 * <stage>
	 * : confirmed | shipped | delivered | refused | cancelled | returned
	 *
	 * [--reason=<reason>]
	 * : For refused/cancelled/returned: changed_mind, not_reachable, not_at_address, price_dispute, ordered_elsewhere,
	 *   fake_order, wrong_item, damaged, late_delivery, supplier_delay, not_as_described, address_unsupported,
	 *   duplicate_order, before_handoff, after_shipment, other.
	 *
	 * [--at=<datetime>]
	 * : When it happened (e.g. "2026-11-03 14:20"), store time zone. Default now.
	 *
	 * [--units=<units>]
	 * : For returned: how many units came back. Default all.
	 *
	 * --evidence=<evidence>
	 * : Where you saw it (e.g. "Taager order 123 status Delivered, 3 Nov").
	 */
	public function outcome( $args, $assoc ) {
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order ) {
			WP_CLI::error( 'No such order.' );
		}
		$stage = (string) $args[1];
		if ( ! in_array( $stage, array( 'confirmed', 'shipped', 'delivered', 'refused', 'cancelled', 'returned' ), true ) ) {
			WP_CLI::error( 'Stage must be confirmed, shipped, delivered, refused, cancelled or returned. Refunds are recorded from the refund itself.' );
		}
		if ( empty( $assoc['evidence'] ) ) {
			WP_CLI::error( '--evidence is required: where did you see this?' );
		}
		if ( in_array( $stage, array( 'refused', 'returned' ), true ) && empty( $assoc['reason'] ) ) {
			WP_CLI::error( '--reason is required for ' . $stage . ' (it decides whether it counts against the customer or the operation).' );
		}
		$at = isset( $assoc['at'] ) ? ( new DateTime( $assoc['at'], wp_timezone() ) )->getTimestamp() : time();
		$e  = YM_Lifecycle::record( $order, $stage, array( 'at' => $at, 'src' => 'staff', 'verified' => true, 'reason' => $assoc['reason'] ?? '', 'units' => isset( $assoc['units'] ) ? (int) $assoc['units'] : null, 'by' => 'cli', 'evidence' => $assoc['evidence'] ) );
		if ( is_wp_error( $e ) ) {
			WP_CLI::error( $e->get_error_message() );
		}
		WP_CLI::success( wp_json_encode( $e, JSON_UNESCAPED_UNICODE ) . ' outcome=' . YM_Lifecycle::outcome( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Record an actual order-level charge from Taager's wallet or invoice (replaces a policy estimate of that type).
	 *
	 * <order>
	 * : Order ID.
	 *
	 * <type>
	 * : refusal_fee | return_fee | shipping_subsidy | payout_fee | other
	 *
	 * <amount>
	 * : Amount in the store currency.
	 *
	 * --source=<source>
	 * : Where it comes from.
	 *
	 * [--replace]
	 * : Replace an actual charge of this type already recorded.
	 */
	public function charge( $args, $assoc ) {
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order || empty( $assoc['source'] ) ) {
			WP_CLI::error( 'Order and --source required.' );
		}
		$r = YM_Lifecycle::add_charge( $order, (string) $args[1], (float) $args[2], 'actual', (string) $assoc['source'], ! empty( $assoc['replace'] ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Record money outside WooCommerce: wallet credits, payouts received, payout fees, ad spend.
	 *
	 * <kind>
	 * : wallet | payout | payout_fee | spend
	 *
	 * <amount>
	 * : Amount in the store currency.
	 *
	 * <date>
	 * : YYYY-MM-DD the money moved (spend: the day it was spent).
	 *
	 * --source=<source>
	 * : e.g. "Taager wallet statement", "Meta Ads Manager export 2026-11-03".
	 *
	 * [--sku=<sku>]
	 * : Product the spend was for, if any.
	 *
	 * [--note=<note>]
	 * : Free text.
	 */
	public function money( $args, $assoc ) {
		$r = YM_Report::add_entry( (string) $args[0], (float) $args[1], (string) $args[2], (string) ( $assoc['source'] ?? '' ), (string) ( $assoc['note'] ?? '' ), (string) ( $assoc['sku'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		if ( 'spend' === $args[0] && ! YM_Markets::is_on( YM_Country::code(), 'ads' ) ) {
			WP_CLI::warning( 'Advertising is not enabled for ' . YM_Country::code() . ' in config/markets.json, yet spend was recorded.' );
		}
		WP_CLI::success( wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Market gates: show the five-country matrix, or switch a gate OFF for this store (emergency). Switching ON is
	 * done only in config/markets.json, through a commit.
	 *
	 * [<action>]
	 * : status (default) | off | clear
	 *
	 * [<gate>]
	 * : prepared | catalog | checkout | ads
	 */
	public function market( $args, $assoc ) {
		$action = $args[0] ?? 'status';
		if ( 'off' === $action ) {
			$gate = (string) ( $args[1] ?? '' );
			if ( ! in_array( $gate, YM_Markets::GATES, true ) ) {
				WP_CLI::error( 'Gate must be one of ' . implode( ', ', YM_Markets::GATES ) );
			}
			$off = array_unique( array_merge( (array) get_option( 'ym_market_off', array() ), array( $gate ) ) );
			update_option( 'ym_market_off', array_values( $off ) );
			YM_Ops::log( 'market_gate_off', array( 'gate' => $gate ) );
			WP_CLI::success( YM_Country::code() . ' ' . $gate . ' switched OFF on this store.' );
		} elseif ( 'clear' === $action ) {
			delete_option( 'ym_market_off' );
			YM_Ops::log( 'market_gate_clear', array() );
			WP_CLI::success( 'Local switches cleared; config/markets.json applies.' );
		}
		$rows = array();
		foreach ( YM_Markets::matrix() as $cc => $row ) {
			$rows[] = array_merge( array( 'country' => $cc ), array_map( fn( $v ) => is_bool( $v ) ? ( $v ? 'on' : 'off' ) : $v, $row ) );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'country', 'prepared', 'catalog', 'checkout', 'ads', 'note' ) );
		WP_CLI::log( 'This store: ' . YM_Country::code() . ' · enforced here: ' . ( YM_Env::enforced() ? 'yes' : 'no (' . YM_Env::type() . ')' ) . ' · local off: ' . implode( ', ', (array) get_option( 'ym_market_off', array() ) ) );
	}

	/**
	 * Review queue: orders held before the supplier hand-off.
	 *
	 * [<action>]
	 * : list (default) | release | cancel
	 *
	 * [<order>]
	 * : Order ID (release, cancel).
	 *
	 * [--note=<note>]
	 * : What you checked (release) or why (cancel). Required for both.
	 *
	 * [--format=<format>]
	 * : table or json.
	 */
	public function review( $args, $assoc ) {
		$action = $args[0] ?? 'list';
		if ( 'list' === $action ) {
			$rows = YM_Risk::queue();
			if ( ! $rows ) {
				WP_CLI::log( 'Review queue empty.' );
				return;
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'order', 'placed', 'reasons', 'duplicate_of', 'total' ) );
			return;
		}
		$order = wc_get_order( (int) ( $args[1] ?? 0 ) );
		if ( ! $order ) {
			WP_CLI::error( 'No such order.' );
		}
		$r = 'release' === $action ? YM_Risk::release( $order, 'cli', (string) ( $assoc['note'] ?? '' ) ) : ( 'cancel' === $action ? YM_Risk::cancel( $order, 'cli', (string) ( $assoc['note'] ?? '' ) ) : new WP_Error( 'x', 'Action must be list, release or cancel.' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'Order ' . $order->get_id() . ' ' . $action . 'd.' );
	}

	/**
	 * Customer risk records (by phone, stored hashed). Reversible; every change kept in the record's history.
	 *
	 * <action>
	 * : show | set | clear
	 *
	 * <phone>
	 * : Phone number in any format.
	 *
	 * [<level>]
	 * : watch | confirm | hold (set).
	 *
	 * [--reason=<reason>]
	 * : Required for set and clear.
	 *
	 * [--days=<days>]
	 * : Expiry in days (set). Default 30.
	 *
	 * [--orders=<ids>]
	 * : Evidence: comma-separated order IDs.
	 */
	public function risk( $args, $assoc ) {
		$action = $args[0] ?? 'show';
		$phone  = (string) ( $args[1] ?? '' );
		if ( ! YM_Country::valid_phone( $phone ) ) {
			WP_CLI::error( 'Not a valid ' . YM_Country::code() . ' phone number.' );
		}
		if ( 'set' === $action ) {
			$r = YM_Risk::set( $phone, (string) ( $args[2] ?? '' ), (string) ( $assoc['reason'] ?? '' ), 'cli', (int) ( $assoc['days'] ?? 30 ), array_filter( array_map( 'intval', explode( ',', (string) ( $assoc['orders'] ?? '' ) ) ) ) );
		} elseif ( 'clear' === $action ) {
			$r = '' === trim( (string) ( $assoc['reason'] ?? '' ) ) ? new WP_Error( 'x', '--reason required.' ) : YM_Risk::clear( $phone, 'cli', (string) $assoc['reason'] );
		} else {
			$r = YM_Risk::record( $phone, true );
			if ( ! $r ) {
				WP_CLI::log( 'No record.' );
				return;
			}
		}
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Review link for an order with a verified delivery (send it to her yourself, e.g. on WhatsApp).
	 *
	 * <order>
	 * : Order ID.
	 *
	 * @subcommand review-link
	 */
	public function review_link( $args ) {
		$order = wc_get_order( (int) $args[0] );
		$link  = $order ? YM_Reviews::link( $order ) : new WP_Error( 'x', 'No such order.' );
		if ( is_wp_error( $link ) ) {
			WP_CLI::error( $link->get_error_message() );
		}
		WP_CLI::log( $link );
	}

	/**
	 * Moderate reviews: list pending ones, approve, or reject with a reason.
	 *
	 * [<action>]
	 * : pending (default) | approve | reject
	 *
	 * [<id>]
	 * : Review (comment) ID.
	 *
	 * [--reason=<reason>]
	 * : Required to reject.
	 */
	public function reviews( $args, $assoc ) {
		$action = $args[0] ?? 'pending';
		if ( 'pending' === $action ) {
			$rows = array();
			foreach ( get_comments( array( 'status' => 'hold', 'type' => 'review', 'number' => 50, 'meta_key' => 'ym_verified', 'meta_value' => 1 ) ) as $c ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$rows[] = array( 'id' => $c->comment_ID, 'product' => get_the_title( $c->comment_post_ID ), 'rating' => get_comment_meta( $c->comment_ID, 'rating', true ), 'order' => get_comment_meta( $c->comment_ID, 'ym_order', true ), 'text' => mb_substr( $c->comment_content, 0, 80 ) );
			}
			$rows ? WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'product', 'rating', 'order', 'text' ) ) : WP_CLI::log( 'No reviews waiting.' );
			return;
		}
		$r = YM_Reviews::moderate( (int) ( $args[1] ?? 0 ), $action, 'cli', (string) ( $assoc['reason'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'Review ' . $args[1] . ' ' . $action . 'd.' );
	}

	/**
	 * Retire a product for good (not for being out of stock): its page 301-redirects to the replacement or its
	 * category, and it leaves the catalog and sitemap. Temporarily unavailable products should NOT be retired.
	 *
	 * <sku>
	 * : Product SKU.
	 *
	 * [--to=<url>]
	 * : Replacement product URL.
	 *
	 * --reason=<reason>
	 * : Why (kept on the product).
	 */
	public function retire( $args, $assoc ) {
		$p = wc_get_product( wc_get_product_id_by_sku( (string) $args[0] ) );
		if ( ! $p || empty( $assoc['reason'] ) ) {
			WP_CLI::error( 'Unknown SKU, or --reason missing.' );
		}
		$p->update_meta_data( '_ym_retired', wp_json_encode( array( 'at' => gmdate( 'c' ), 'reason' => $assoc['reason'] ), JSON_UNESCAPED_UNICODE ) );
		if ( ! empty( $assoc['to'] ) ) {
			$p->update_meta_data( '_ym_replaced_by', esc_url_raw( $assoc['to'] ) );
		}
		$p->set_catalog_visibility( 'hidden' );
		$p->save();
		WP_CLI::success( $p->get_sku() . ' retired.' );
	}

	/**
	 * Record what a product picture is and where it came from (the media gate reads this).
	 *
	 * <attachment>
	 * : Attachment ID.
	 *
	 * --role=<role>
	 * : clean | detail | scale | demo | contents | lifestyle
	 *
	 * --sku=<sku>
	 * : Exact supplier SKU shown.
	 *
	 * [--variant=<variant>]
	 * : Colour, size or print shown.
	 *
	 * --source=<source>
	 * : Who supplied it (e.g. "Taager media pack, email 3 Nov").
	 *
	 * --rights=<rights>
	 * : Permission in writing (where).
	 *
	 * --original=<original>
	 * : Where the untouched original is kept.
	 *
	 * [--exact]
	 * : You compared it with the item sold and it is that exact item.
	 *
	 * [--complete]
	 * : Shows the whole product.
	 *
	 * [--final]
	 * : Final quality (not interim).
	 */
	public function media( $args, $assoc ) {
		$id = (int) $args[0];
		if ( 'attachment' !== get_post_type( $id ) ) {
			WP_CLI::error( 'Not an attachment.' );
		}
		if ( ! in_array( $assoc['role'] ?? '', array( 'clean', 'detail', 'scale', 'demo', 'contents', 'lifestyle' ), true ) ) {
			WP_CLI::error( '--role must be clean, detail, scale, demo, contents or lifestyle.' );
		}
		foreach ( array( 'sku', 'source', 'rights', 'original' ) as $k ) {
			if ( empty( $assoc[ $k ] ) ) {
				WP_CLI::error( "--$k is required." );
			}
		}
		$old = json_decode( (string) get_post_meta( $id, '_ym_media', true ), true );
		$m   = array_merge( is_array( $old ) ? $old : array(), array(
			'role' => $assoc['role'], 'sku' => $assoc['sku'], 'variant' => $assoc['variant'] ?? '', 'source' => $assoc['source'], 'rights' => $assoc['rights'],
			'original' => $assoc['original'], 'exact_item' => ! empty( $assoc['exact'] ), 'complete' => ! empty( $assoc['complete'] ), 'final' => ! empty( $assoc['final'] ),
			'recorded' => gmdate( 'c' ),
		) );
		update_post_meta( $id, '_ym_media', wp_json_encode( $m, JSON_UNESCAPED_UNICODE ) );
		WP_CLI::success( wp_json_encode( $m, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Operations board as JSON (same data as WooCommerce > Yawmiyati ops).
	 */
	public function ops( $args, $assoc ) {
		WP_CLI::log( wp_json_encode( YM_Board::data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
}
