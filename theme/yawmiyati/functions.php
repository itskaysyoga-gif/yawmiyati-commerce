<?php
/**
 * Yawmiyati theme: block theme shell; the product page and checkout are rendered by the yawmiyati-commerce plugin
 * and WooCommerce, styled here.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
	remove_theme_support( 'core-block-patterns' );
} );

// Only what a page uses. WooCommerce's own CSS and jQuery-based scripts are needed on cart, checkout and account
// pages only; the shop, product and content pages are styled by ym.css and run ym.js (no jQuery). No emoji script.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_checkout' ) || is_cart() || is_checkout() || is_account_page() ) {
		return;
	}
	foreach ( array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-blocktheme', 'wc-blocks-style' ) as $h ) {
		wp_dequeue_style( $h );
	}
	foreach ( array( 'wc-add-to-cart', 'woocommerce', 'jquery-blockui', 'js-cookie', 'wc-single-product', 'zoom', 'flexslider', 'photoswipe', 'photoswipe-ui-default', 'sourcebuster-js', 'wc-order-attribution' ) as $h ) {
		wp_dequeue_script( $h );
	}
}, 99 );

add_action( 'wp_enqueue_scripts', function () {
	$ver = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'yawmiyati', get_theme_file_uri( 'assets/css/ym.css' ), array(), $ver );
} );

// Keep WooCommerce's layout styles off our pages; ym.css styles the forms and tables we use.
add_filter( 'woocommerce_enqueue_styles', function ( $styles ) {
	unset( $styles['woocommerce-smallscreen'] );
	return $styles;
} );

add_action( 'wp_head', function () {
	echo '<link rel="icon" href="' . esc_url( get_theme_file_uri( 'assets/img/favicon-512.png' ) ) . '">';
}, 1 );

/** Copy helper: the plugin owns customer-facing text (copy/<cc>.json). */
function ym_t( $key ) {
	return class_exists( 'YM_Country' ) ? YM_Country::copy( $key ) : '';
}

/** Catalog page URL (WooCommerce shop page). */
function ym_shop_url() {
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * One product card, the same everywhere (home, catalog, search): whole-product frame, name, price, delivery hint.
 * $eager: only the first visible row on a page is loaded eagerly; everything else waits until it is near the screen.
 */
function ym_card( WC_Product $p, $eager = false, $priority = false ) {
	$attrs = array( 'class' => 'ym-frame', 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async', 'sizes' => '(min-width: 960px) 260px, 46vw' );
	if ( $priority ) {
		$attrs['fetchpriority'] = 'high';
	}
	// One 4:5 card derivative per product (YM_Media): same white card and optical scale for cut-outs, whole photos never stretched.
	$img = class_exists( 'YM_Media' ) ? YM_Media::card_html( (int) $p->get_image_id(), $p->get_name(), $attrs ) : wp_get_attachment_image( $p->get_image_id(), 'woocommerce_single', false, array_merge( $attrs, array( 'alt' => $p->get_name() ) ) );
	$out = '<li class="ym-card' . ( $p->is_in_stock() ? '' : ' is-out' ) . '">'
		. '<a class="ym-card__img" href="' . esc_url( $p->get_permalink() ) . '" tabindex="-1" aria-hidden="true">' . $img . '</a>'
		. '<a class="ym-card__name" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( $p->get_name() ) . '</a>'
		. '<span class="ym-card__price">' . esc_html( YM_Country::money( $p->get_price() ) ) . ' <small>' . esc_html( ym_t( 'plus_delivery' ) ) . '</small></span>';
	if ( ! $p->is_in_stock() ) {
		$out .= '<span class="ym-card__out">' . esc_html( ym_t( 'sold_out_tag' ) ) . '</span>';
	}
	return $out . '</li>';
}

/** Category chips (only categories with products), plus "all". */
function ym_category_chips( $current = '' ) {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return '';
	}
	$out = '<nav class="ym-chips" aria-label="' . esc_attr( ym_t( 'categories' ) ) . '"><a href="' . esc_url( ym_shop_url() ) . '"' . ( '' === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( ym_t( 'all_products' ) ) . '</a>';
	foreach ( $terms as $t ) {
		$out .= '<a href="' . esc_url( get_term_link( $t ) ) . '"' . ( $current === $t->slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $t->name ) . '</a>';
	}
	return $out . '</nav>';
}

function ym_search_form( $value = '' ) {
	return '<form class="ym-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">'
		. '<label class="screen-reader-text" for="ym-s">' . esc_html( ym_t( 'search_label' ) ) . '</label>'
		. '<input type="search" id="ym-s" name="s" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( ym_t( 'search_ph' ) ) . '" enterkeyhint="search">'
		. '<input type="hidden" name="post_type" value="product">'
		. '<button type="submit" aria-label="' . esc_attr( ym_t( 'search_label' ) ) . '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg></button>'
		. '</form>';
}

/** [ym_header]: logo, search, explicit country switcher (open markets only), cart. */
add_shortcode( 'ym_header', function () {
	$country = class_exists( 'YM_Country' ) ? YM_Country::code() : '';
	$count   = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$stores  = class_exists( 'YM_Country' ) ? YM_Country::store_urls() : array();
	$open    = class_exists( 'YM_Markets' ) ? YM_Markets::public_codes() : array_keys( $stores );
	ob_start();
	?>
	<header class="ym-header">
		<a class="ym-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.svg' ) ); ?>" alt="يومياتي" width="52" height="45"></a>
		<div class="ym-header__tools">
			<a class="ym-iconbtn" href="<?php echo esc_url( add_query_arg( 'ym_find', '1', ym_shop_url() ) ); ?>" aria-label="<?php echo esc_attr( ym_t( 'search_label' ) ); ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg></a>
			<details class="ym-country">
				<summary aria-label="<?php echo esc_attr( ym_t( 'switch_country' ) . ': ' . ym_t( 'country_name' ) ); ?>"><?php echo esc_html( ym_t( 'country_name' ) ); ?> <svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></summary>
				<div class="ym-country__menu">
					<p class="ym-country__head"><?php echo esc_html( ym_t( 'switch_country' ) ); ?></p>
					<ul>
					<?php foreach ( $stores as $cc => $url ) : ?>
						<?php if ( ! in_array( $cc, $open, true ) ) { continue; } ?>
						<li><?php if ( $cc === $country ) : ?><span aria-current="true"><?php echo esc_html( YM_Country::copy( 'country_name', $cc ) ); ?> <small>✓</small></span><?php else : ?><a href="<?php echo esc_url( $url ); ?>" hreflang="<?php echo esc_attr( YM_Country::rules( $cc )['hreflang'] ); ?>" lang="ar" data-ym-switch><?php echo esc_html( YM_Country::copy( 'country_name', $cc ) ); ?> <small><?php echo esc_html( YM_Country::rules( $cc )['currency'] ); ?></small></a><?php endif; ?></li>
					<?php endforeach; ?>
					</ul>
					<?php if ( $count ) : ?><p class="ym-country__note" data-ym-cart-stays><?php echo esc_html( ym_t( 'cart_stays' ) ); ?></p><?php endif; ?>
				</div>
			</details>
			<a class="ym-cart" href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#' ); ?>" aria-label="<?php echo esc_attr( ym_t( 'cart_label' ) . ': ' . $count ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
				<?php if ( $count ) : ?><span class="ym-cart__count"><?php echo esc_html( $count ); ?></span><?php endif; ?>
			</a>
		</div>
	</header>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );
/** [ym_footer]: help line, support and policy pages, cookie settings. */
add_shortcode( 'ym_footer', function () {
	$links = array( 'delivery' => 'nav_delivery', 'returns' => 'nav_returns', 'faq' => 'nav_faq', 'contact' => 'nav_contact', 'privacy' => 'nav_privacy' );
	ob_start();
	?>
	<footer class="ym-footer">
		<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.svg' ) ); ?>" alt="يومياتي" width="46" height="40">
		<p><?php echo esc_html( ym_t( 'ask' ) ); ?></p>
		<?php echo class_exists( 'YM_Social' ) ? YM_Social::links( 'footer' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
		<?php echo class_exists( 'YM_Mail' ) ? YM_Mail::signup_form( 'footer' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside; shown only once the newsletter flow is switched on ?>
		<nav aria-label="<?php echo esc_attr( ym_t( 'nav_contact' ) ); ?>"><ul>
			<?php foreach ( $links as $slug => $key ) : ?>
				<?php $page = get_page_by_path( $slug ); ?>
				<?php if ( $page && 'publish' === $page->post_status ) : ?><li><a href="<?php echo esc_url( get_permalink( $page ) ); ?>"><?php echo esc_html( ym_t( $key ) ); ?></a></li><?php endif; ?>
			<?php endforeach; ?>
		</ul></nav>
		<div class="ym-footer__meta"><span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> يومياتي</span><button type="button" class="ym-linkbtn" data-ym-consent-open><?php echo esc_html( ym_t( 'consent_change' ) ); ?></button></div>
	</footer>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );

/**
 * The home page's focal image. A licensed lifestyle photograph (option ym_hero_image: attachment ID whose media
 * record says rights are cleared for the site) when one exists; otherwise the first featured product with complete
 * media, shown whole and named. Never three unrelated thumbnails, never a generated "lifestyle" scene.
 */
function ym_hero_media() {
	$id = (int) get_option( 'ym_hero_image' );
	if ( $id ) {
		$m = json_decode( (string) get_post_meta( $id, '_ym_media', true ), true );
		if ( ! empty( $m['rights'] ) && in_array( 'site', (array) ( $m['channels'] ?? array( 'site' ) ), true ) ) {
			return array( 'kind' => 'photo', 'html' => wp_get_attachment_image( $id, 'large', false, array( 'class' => 'ym-hero__photo', 'alt' => (string) ( $m['alt'] ?? '' ), 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'sizes' => '(min-width: 960px) 560px, 100vw' ) ) );
		}
	}
	return null;
}

/**
 * Why the curated hero product (option ym_hero_sku, set by a person: `wp ym hero <sku>`) cannot be shown, or ''.
 * Never chosen automatically (review of 29 Oct): an item with open safety or specification questions (meta
 * _ym_conditional), out of stock, unpublished or without a picture is not the commercial lead.
 */
function ym_hero_product_problem( $sku ) {
	if ( '' === (string) $sku ) {
		return 'no hero chosen';
	}
	$p = wc_get_product( wc_get_product_id_by_sku( (string) $sku ) );
	if ( ! $p || 'publish' !== $p->get_status() ) {
		return 'not published';
	}
	if ( '' !== (string) $p->get_meta( '_ym_conditional' ) ) {
		return 'conditional: ' . $p->get_meta( '_ym_conditional' );
	}
	if ( ! $p->is_in_stock() ) {
		return 'out of stock';
	}
	return $p->get_image_id() ? '' : 'no picture';
}

/**
 * [ym_home]: one focal image with a short editorial message and one clear action, then categories, a curated
 * selection (at most 6), one practical home tip and how ordering works. The home page never lists the whole catalog.
 */
add_shortcode( 'ym_home', function () {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return '';
	}
	// The curated selection shows only what she can order now, with a picture: no filler, no sold-out tiles.
	$ready = fn( $list ) => array_values( array_filter( $list, fn( $p ) => $p->is_in_stock() && $p->get_image_id() ) );
	$pick  = $ready( wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'featured' => true, 'limit' => 12, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) );
	if ( count( $pick ) < 4 ) {
		$more = $ready( wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 12, 'orderby' => 'menu_order', 'order' => 'ASC', 'exclude' => array_map( fn( $p ) => $p->get_id(), $pick ) ) ) );
		$pick = array_merge( $pick, $more );
	}
	$pick  = array_slice( $pick, 0, 6 );
	$photo = ym_hero_media();
	$sku   = (string) get_option( 'ym_hero_sku' );
	$focus = $photo || '' !== ym_hero_product_problem( $sku ) ? null : YM_Offers::primary( wc_get_product( wc_get_product_id_by_sku( $sku ) ) );
	$cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
	$steps = array_filter( explode( '|', ym_t( 'home_steps' ) ) );
	ob_start();
	?>
	<div class="ym-home">
		<section class="ym-hero<?php echo $photo ? ' ym-hero--photo' : ( $focus ? ' ym-hero--product' : ' ym-hero--text' ); ?>">
			<div class="ym-hero__text">
				<h1><?php echo esc_html( ym_t( 'home_title' ) ); ?></h1>
				<p><?php echo esc_html( ym_t( 'home_lede' ) ); ?></p>
				<a class="ym-btn ym-btn--primary ym-hero__cta" href="<?php echo esc_url( ym_shop_url() ); ?>" data-ym-hero-cta><?php echo esc_html( ym_t( 'home_cta' ) ); ?></a>
				<ul class="ym-trust"><?php foreach ( explode( '|', ym_t( 'home_trust' ) ) as $t ) : ?><li><?php echo esc_html( $t ); ?></li><?php endforeach; ?></ul>
			</div>
			<?php if ( $photo ) : ?>
				<div class="ym-hero__media"><?php echo $photo['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php elseif ( $focus ) : ?>
				<a class="ym-hero__media ym-hero__product" href="<?php echo esc_url( $focus->get_permalink() ); ?>">
					<span class="ym-hero__frame"><?php echo wp_get_attachment_image( (int) $focus->get_image_id(), 'large', false, array( 'class' => 'ym-hero__img', 'alt' => $focus->get_name(), 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'sizes' => '(min-width: 960px) 460px, 86vw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span class="ym-hero__caption"><small><?php echo esc_html( ym_t( 'home_featured' ) ); ?></small> <?php echo esc_html( $focus->get_name() ); ?></span>
				</a>
			<?php endif; ?>
		</section>
		<?php if ( ! is_wp_error( $cats ) && $cats ) : ?>
		<section class="ym-band ym-cats" aria-labelledby="ym-cats-h">
			<h2 id="ym-cats-h"><?php echo esc_html( ym_t( 'home_cats' ) ); ?></h2>
			<ul class="ym-cats__list">
				<?php foreach ( $cats as $t ) : ?><li><a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><span><?php echo esc_html( $t->name ); ?></span> <small><?php echo esc_html( YM_Country::number( $t->count ) ); ?></small></a></li><?php endforeach; ?>
			</ul>
		</section>
		<?php endif; ?>
		<section class="ym-shop">
			<div class="ym-shop__head"><h2><?php echo esc_html( ym_t( 'home_pick' ) ); ?></h2><a class="ym-more" href="<?php echo esc_url( ym_shop_url() ); ?>"><?php echo esc_html( ym_t( 'see_all' ) ); ?></a></div>
			<ul class="ym-grid">
				<?php foreach ( $pick as $p ) { echo ym_card( $p ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</ul>
			<a class="ym-btn ym-btn--secondary ym-shop__all" href="<?php echo esc_url( ym_shop_url() ); ?>"><?php echo esc_html( ym_t( 'see_all' ) ); ?></a>
		</section>
		<div class="ym-band ym-home__pair">
			<section class="ym-tip" aria-labelledby="ym-tip-h">
				<h2 id="ym-tip-h"><?php echo esc_html( ym_t( 'home_tip_title' ) ); ?></h2>
				<p><?php echo esc_html( ym_t( 'home_tip_body' ) ); ?></p>
			</section>
			<section class="ym-steps" aria-labelledby="ym-steps-h">
				<h2 id="ym-steps-h"><?php echo esc_html( ym_t( 'home_steps_title' ) ); ?></h2>
				<ol><?php foreach ( $steps as $st ) : ?><li><?php echo esc_html( $st ); ?></li><?php endforeach; ?></ol>
			</section>
		</div>
	</div>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );

/**
 * [ym_catalog]: the full catalog, a category, or search results, 12 per page from WooCommerce's own query.
 * Search pages are noindex (YM_SEO); category pages are indexable.
 */
add_filter( 'loop_shop_per_page', fn() => 12, 20 );
add_shortcode( 'ym_catalog', function () {
	global $wp_query;
	$term    = is_product_category() ? get_queried_object() : null;
	$query   = get_search_query();
	$title   = $query ? sprintf( ym_t( 'search_title' ), $query ) : ( $term ? $term->name : ym_t( 'all_products' ) );
	$focus   = ! empty( $_GET['ym_find'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$posts   = $wp_query->posts;
	ob_start();
	?>
	<div class="ym-catalog">
		<div class="ym-catalog__head">
			<?php echo class_exists( 'YM_SEO' ) ? YM_SEO::breadcrumb_html() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php echo ym_search_form( $query ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo ym_category_chips( $term ? $term->slug : ( $query ? '-' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php if ( $posts ) : ?>
			<p class="ym-catalog__count" aria-live="polite"><?php echo esc_html( sprintf( ym_t( 'results' ), (int) $wp_query->found_posts ) ); ?></p>
			<ul class="ym-grid">
				<?php foreach ( $posts as $i => $post ) {
					$p = wc_get_product( $post );
					if ( $p ) {
						echo ym_card( $p, $i < 2 && 1 === max( 1, (int) get_query_var( 'paged' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				} ?>
			</ul>
			<?php
			$links = paginate_links( array( 'total' => (int) $wp_query->max_num_pages, 'current' => max( 1, (int) get_query_var( 'paged' ) ), 'type' => 'list', 'prev_text' => ym_t( 'prev' ), 'next_text' => ym_t( 'next' ) ) );
			if ( $links ) {
				echo '<nav class="ym-pages" aria-label="' . esc_attr( ym_t( 'pages' ) ) . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		<?php else : ?>
			<div class="ym-empty" data-ym-empty>
				<p><?php echo esc_html( $query ? sprintf( ym_t( 'no_results' ), $query ) : ym_t( 'no_products' ) ); ?></p>
				<p><?php echo esc_html( ym_t( 'no_results_help' ) ); ?></p>
				<a class="ym-btn ym-btn--secondary" href="<?php echo esc_url( ym_shop_url() ); ?>"><?php echo esc_html( ym_t( 'see_all' ) ); ?></a>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( $focus ) : ?><script>document.getElementById('ym-s').focus();</script><?php endif; ?>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );
