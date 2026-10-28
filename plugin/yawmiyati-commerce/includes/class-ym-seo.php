<?php
/**
 * Search and social metadata, without an SEO plugin. Checked on rendered pages by tests/e2e/seo.test.js.
 *
 * Indexing: only a production store is indexable. Everywhere else every page is noindex and robots.txt disallows
 * everything. On production, cart, checkout, order pages, account pages, search and 404 are noindex.
 * Sitemaps: WordPress core sitemap per store (/eg/wp-sitemap.xml, /iq/wp-sitemap.xml) with product pages and support
 * pages only. Products that cannot be sold (catalog gate) and secondary offers are left out.
 * hreflang: the country home pages point at each other (ar-EG, ar-IQ) and at the country chooser (x-default).
 * Products are not cross-linked: no launch product is the same item in both countries. A product can opt in with
 * meta _ym_equivalent = {"IQ": "https://.../iq/product/..."} once the items are verified identical.
 * Structured data: Product with one Offer (price, currency, availability, delivery fee by region) and a
 * BreadcrumbList matching the breadcrumb shown on the page. A rating appears only from approved reviews of verified
 * deliveries, and only from 3 of them (YM_Reviews). No return policy until it is verified.
 * Unavailable products: temporarily out of stock stays indexable as OutOfStock (the page still answers "is it
 * coming back"); a retired product (meta _ym_retired) is 301-redirected to its replacement (_ym_replaced_by) or its
 * category and leaves the sitemap. `wp ym retire <sku>`.
 */

defined( 'ABSPATH' ) || exit;

final class YM_SEO {

	const SITE_NAME = 'يومياتي';

	public static function init() {
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
		add_filter( 'document_title_separator', fn() => '|' );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'post_types' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_query' ), 10, 2 );
		// Sitemaps on (also while noindex outside production, for checking), except a live store whose market is not approved.
		add_filter( 'wp_sitemaps_enabled', fn() => ! ( YM_Env::is_production() && ! YM_Markets::indexable() ), 20 );
		remove_action( 'wp_head', 'rel_canonical' );
		add_filter( 'woocommerce_structured_data_product', '__return_false' );
		add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_false' );
		add_action( 'template_redirect', array( __CLASS__, 'retired_redirect' ), 4 );
	}

	public static function indexable() {
		return YM_Env::is_production() && '1' === (string) get_option( 'blog_public', '1' ) && YM_Markets::indexable();
	}

	public static function robots( $robots ) {
		$private = is_cart() || is_checkout() || is_account_page() || is_search() || is_404() || is_wc_endpoint_url() || is_page( 'review' );
		if ( ! self::indexable() || $private ) {
			return array( 'noindex' => true, 'nofollow' => ! self::indexable() );
		}
		$robots['max-image-preview'] = 'large';
		return $robots;
	}

	public static function robots_txt( $out, $public ) {
		if ( ! self::indexable() ) {
			return "User-agent: *\nDisallow: /\n";
		}
		return $out;
	}

	public static function title( $parts ) {
		if ( is_front_page() ) {
			return array( 'title' => self::SITE_NAME, 'tagline' => YM_Country::copy( 'home_title' ) );
		}
		$parts['site'] = self::SITE_NAME;
		unset( $parts['tagline'] );
		return $parts;
	}

	/** Canonical URL of the current page, without query strings. */
	public static function canonical() {
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( is_product() ) {
				$p  = YM_Offers::primary( wc_get_product( $id ) );
				$id = $p->get_id();
			}
			return get_permalink( $id );
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$base  = is_product_category() ? get_term_link( get_queried_object() ) : ( is_shop() && ! is_search() ? wc_get_page_permalink( 'shop' ) : '' );
		if ( $base && ! is_wp_error( $base ) ) {
			return $paged > 1 ? trailingslashit( $base ) . user_trailingslashit( 'page/' . $paged, 'paged' ) : $base;
		}
		return '';
	}

	public static function description() {
		if ( is_front_page() ) {
			return YM_Country::copy( 'home_lede' );
		}
		if ( is_product() ) {
			$p = YM_Offers::primary( wc_get_product( get_queried_object_id() ) );
			return (string) ( $p->get_meta( '_ym_benefit' ) ? $p->get_meta( '_ym_benefit' ) : $p->get_short_description() );
		}
		if ( is_product_category() ) {
			$t = get_queried_object();
			return $t->description ? wp_strip_all_tags( $t->description ) : sprintf( '%s · %s', $t->name, YM_Country::copy( 'home_lede' ) );
		}
		if ( is_shop() && ! is_search() ) {
			return YM_Country::copy( 'all_products' ) . ' · ' . YM_Country::copy( 'home_lede' );
		}
		if ( is_page() ) {
			$text = wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', get_queried_object_id() ) ) );
			return wp_trim_words( trim( preg_replace( '/\s+/u', ' ', $text ) ), 26, '…' );
		}
		return '';
	}

	public static function head() {
		$canonical = self::canonical();
		$desc      = self::description();
		$cc        = YM_Country::code();
		if ( $canonical && ! is_wc_endpoint_url() ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . "\">\n";
		}
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";
		}
		self::hreflang();
		// Social previews.
		$og = array(
			'og:site_name'   => self::SITE_NAME,
			'og:locale'      => 'ar_' . $cc,
			'og:type'        => is_product() ? 'product' : 'website',
			'og:title'       => wp_get_document_title(),
			'og:description' => $desc,
			'og:url'         => $canonical,
		);
		$image = null;
		if ( is_product() ) {
			$p     = YM_Offers::primary( wc_get_product( get_queried_object_id() ) );
			$og['og:title'] = $p->get_name();
			$image = $p->get_image_id() ? wp_get_attachment_image_src( $p->get_image_id(), 'full' ) : null;
			$og['product:price:amount']   = (string) (float) $p->get_price();
			$og['product:price:currency'] = get_woocommerce_currency();
		}
		if ( ! $image ) {
			$image = array( get_theme_file_uri( 'assets/img/og-default.png' ), 1200, 630 );
		}
		$og['og:image']        = $image[0];
		$og['og:image:width']  = (string) $image[1];
		$og['og:image:height'] = (string) $image[2];
		foreach ( array_filter( $og ) as $k => $v ) {
			echo '<meta property="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . "\">\n";
		}
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		// Structured data.
		$ld = is_product() ? self::product_ld( YM_Offers::primary( wc_get_product( get_queried_object_id() ) ) ) : ( is_front_page() ? self::home_ld() : null );
		foreach ( array_filter( array( $ld, self::breadcrumb_ld() ) ) as $one ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $one, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
		}
	}

	public static function hreflang() {
		$urls = array();
		if ( is_front_page() ) {
			// Only markets that take orders: a closed market is never offered as an alternate.
			$urls = array_intersect_key( YM_Country::store_urls(), array_flip( YM_Markets::public_codes() ) );
		} elseif ( is_product() ) {
			$p   = YM_Offers::primary( wc_get_product( get_queried_object_id() ) );
			$eq  = json_decode( (string) $p->get_meta( '_ym_equivalent' ), true );
			if ( is_array( $eq ) && $eq ) {
				$urls = array_merge( array( YM_Country::code() => get_permalink( $p->get_id() ) ), $eq );
			}
		}
		if ( count( $urls ) < 2 ) {
			return;
		}
		foreach ( $urls as $cc => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( YM_Country::rules( $cc )['hreflang'] ) . '" href="' . esc_url( $url ) . "\">\n";
		}
		$x = (string) get_option( 'ym_xdefault_url', '' );
		if ( is_front_page() && $x ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x ) . "\">\n";
		}
	}

	public static function product_ld( WC_Product $p ) {
		$images = array();
		foreach ( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) as $id ) {
			$images[] = wp_get_attachment_image_url( $id, 'full' );
		}
		$rules    = YM_Country::rules();
		$shipping = array();
		$by_fee   = array();
		foreach ( $rules['governorates'] as $code => $g ) {
			$by_fee[ (string) $g[1] ][] = $code;
		}
		foreach ( $by_fee as $fee => $codes ) {
			$dest = array( '@type' => 'DefinedRegion', 'addressCountry' => YM_Country::code() );
			if ( count( $by_fee ) > 1 && 'EG' === YM_Country::code() ) {
				$dest['addressRegion'] = array_map( fn( $c ) => substr( $c, 2 ), $codes );
			}
			$shipping[] = array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array( '@type' => 'MonetaryAmount', 'value' => (float) $fee, 'currency' => get_woocommerce_currency() ),
				'shippingDestination' => $dest,
			);
		}
		$sellable = $p->is_purchasable() && $p->is_in_stock() && YM_Markets::accepts_orders();
		$rating   = class_exists( 'YM_Reviews' ) ? YM_Reviews::summary( $p ) : array( 'count' => 0 );
		return array_filter(
			array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Product',
				'name'        => $p->get_name(),
				'description' => (string) $p->get_meta( '_ym_benefit' ),
				'sku'         => $p->get_sku(),
				'image'       => $images,
				'offers'      => array(
					'@type'           => 'Offer',
					'url'             => get_permalink( $p->get_id() ),
					'price'           => (float) $p->get_price(),
					'priceCurrency'   => get_woocommerce_currency(),
					'availability'    => $sellable ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
					'itemCondition'   => 'https://schema.org/NewCondition',
					'seller'          => array( '@type' => 'Organization', 'name' => self::SITE_NAME ),
					'shippingDetails' => $shipping,
				),
				'aggregateRating' => $rating['count'] >= YM_Reviews::MIN_FOR_RATING ? array( '@type' => 'AggregateRating', 'ratingValue' => $rating['average'], 'reviewCount' => $rating['count'], 'bestRating' => 5, 'worstRating' => 1 ) : null,
			)
		);
	}

	/** Trail shown on product and category pages; the structured data repeats exactly what is shown. */
	public static function breadcrumbs() {
		$trail = array( array( YM_Country::copy( 'home_word' ), home_url( '/' ) ) );
		if ( is_product() ) {
			$p    = YM_Offers::primary( wc_get_product( get_queried_object_id() ) );
			$cats = $p->get_category_ids();
			$term = $cats ? get_term( $cats[0], 'product_cat' ) : null;
			if ( $term && ! is_wp_error( $term ) ) {
				$trail[] = array( $term->name, get_term_link( $term ) );
			}
			$trail[] = array( $p->get_name(), get_permalink( $p->get_id() ) );
		} elseif ( is_product_category() ) {
			$trail[] = array( YM_Country::copy( 'all_products' ), wc_get_page_permalink( 'shop' ) );
			$trail[] = array( get_queried_object()->name, get_term_link( get_queried_object() ) );
		} else {
			return array();
		}
		return $trail;
	}

	public static function breadcrumb_html() {
		$trail = self::breadcrumbs();
		if ( ! $trail ) {
			return '';
		}
		$out = '<nav class="ym-crumbs" aria-label="' . esc_attr( YM_Country::copy( 'breadcrumb' ) ) . '"><ol>';
		foreach ( $trail as $i => $c ) {
			$last = count( $trail ) - 1 === $i;
			$out .= '<li>' . ( $last ? '<span aria-current="page">' . esc_html( $c[0] ) . '</span>' : '<a href="' . esc_url( $c[1] ) . '">' . esc_html( $c[0] ) . '</a>' ) . '</li>';
		}
		return $out . '</ol></nav>';
	}

	public static function breadcrumb_ld() {
		$trail = self::breadcrumbs();
		if ( ! $trail ) {
			return null;
		}
		$items = array();
		foreach ( $trail as $i => $c ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] );
		}
		return array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items );
	}

	/** A retired product goes to its replacement or its category, permanently. */
	public static function retired_redirect() {
		if ( ! is_product() ) {
			return;
		}
		$p = wc_get_product( get_queried_object_id() );
		if ( ! $p || ! $p->get_meta( '_ym_retired' ) ) {
			return;
		}
		$to = (string) $p->get_meta( '_ym_replaced_by' );
		if ( ! $to ) {
			$cats = $p->get_category_ids();
			$to   = $cats ? get_term_link( (int) $cats[0], 'product_cat' ) : wc_get_page_permalink( 'shop' );
		}
		wp_safe_redirect( is_wp_error( $to ) ? home_url( '/' ) : $to, 301 );
		exit;
	}

	public static function home_ld() {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'Organization', '@id' => home_url( '/#org' ), 'name' => self::SITE_NAME, 'url' => home_url( '/' ), 'logo' => get_theme_file_uri( 'assets/img/favicon-512.png' ), 'sameAs' => YM_Social::urls() ),
				array( '@type' => 'WebSite', 'name' => self::SITE_NAME, 'url' => home_url( '/' ), 'inLanguage' => 'ar-' . YM_Country::code(), 'publisher' => array( '@id' => home_url( '/#org' ) ) ),
			),
		);
	}

	/* ---------- sitemaps ---------- */

	public static function providers( $provider, $name ) {
		return in_array( $name, array( 'users', 'taxonomies' ), true ) ? false : $provider;
	}

	public static function post_types( $types ) {
		return array_intersect_key( $types, array_flip( array( 'page', 'product' ) ) );
	}

	public static function sitemap_query( $args, $post_type ) {
		if ( 'page' === $post_type ) {
			$review = get_page_by_path( 'review' );
			$skip   = array_filter( array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ), (int) get_option( 'page_on_front' ), $review ? $review->ID : 0 ) );
			$args['post__not_in'] = array_values( array_filter( $skip, fn( $id ) => $id > 0 ) );
		}
		if ( 'product' === $post_type ) {
			$exclude = array();
			foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) ) as $p ) {
				$secondary = $p->get_meta( '_ym_group' ) && 'yes' !== $p->get_meta( '_ym_offer_primary' );
				if ( $secondary || $p->get_meta( '_ym_retired' ) || 'hidden' === $p->get_catalog_visibility() || ( YM_Env::enforced() && YM_Catalog::blockers( $p ) ) ) {
					$exclude[] = $p->get_id();
				}
			}
			$args['post__not_in'] = $exclude;
		}
		return $args;
	}
}
