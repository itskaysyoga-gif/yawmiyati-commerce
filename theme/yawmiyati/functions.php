<?php
/**
 * Yawmiyati theme: block theme shell; the product page and checkout are rendered by the yawmiyati-commerce plugin
 * and WooCommerce, styled here.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	remove_theme_support( 'core-block-patterns' );
} );

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

/** [ym_header]: logo, country switcher, cart. */
add_shortcode( 'ym_header', function () {
	$country = class_exists( 'YM_Country' ) ? YM_Country::code() : '';
	$count   = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$stores  = class_exists( 'YM_Country' ) ? YM_Country::store_urls() : array();
	ob_start();
	?>
	<header class="ym-header">
		<a class="ym-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.svg' ) ); ?>" alt="يومياتي" width="52" height="45"></a>
		<div class="ym-header__tools">
			<details class="ym-country">
				<summary aria-label="<?php echo esc_attr( ym_t( 'switch_country' ) ); ?>"><?php echo esc_html( ym_t( 'country_name' ) ); ?> <svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></summary>
				<ul class="ym-country__menu">
					<?php foreach ( $stores as $cc => $url ) : ?>
						<li><?php if ( $cc === $country ) : ?><span aria-current="true"><?php echo esc_html( YM_Country::copy( 'country_name', $cc ) ); ?> <small>✓</small></span><?php else : ?><a href="<?php echo esc_url( $url ); ?>" hreflang="<?php echo esc_attr( 'ar-' . $cc ); ?>" lang="ar"><?php echo esc_html( YM_Country::copy( 'country_name', $cc ) ); ?> <small><?php echo esc_html( YM_Country::rules( $cc )['currency'] ); ?></small></a><?php endif; ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
			<a class="ym-cart" href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#' ); ?>" aria-label="<?php echo esc_attr( 'السلة: ' . $count ); ?>">
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

/** [ym_home]: hero with whole-product frames, then every product page as a card. */
add_shortcode( 'ym_home', function () {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return '';
	}
	$products = wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 24, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
	$products = array_values( array_filter( $products, fn( $p ) => ! $p->get_meta( '_ym_group' ) || 'yes' === $p->get_meta( '_ym_offer_primary' ) ) );
	// Hero shows products whose main image shows the whole item; others still appear in the grid.
	$whole = array_values( array_filter( $products, function ( $p ) {
		$m = json_decode( (string) get_post_meta( $p->get_image_id(), '_ym_media', true ), true );
		return ! empty( $m['complete'] );
	} ) );
	$hero = array_slice( count( $whole ) >= 3 ? $whole : $products, 0, 3 );
	$frame = function ( $p, $size = 'woocommerce_single' ) {
		return wp_get_attachment_image( $p->get_image_id(), $size, false, array( 'class' => 'ym-frame', 'alt' => $p->get_name(), 'loading' => 'eager' ) );
	};
	ob_start();
	?>
	<div class="ym-home">
		<section class="ym-hero">
			<div class="ym-hero__text">
				<h1><?php echo esc_html( ym_t( 'home_title' ) ); ?></h1>
				<p><?php echo esc_html( ym_t( 'home_lede' ) ); ?></p>
				<ul class="ym-trust"><?php foreach ( explode( '|', ym_t( 'home_trust' ) ) as $t ) : ?><li><?php echo esc_html( $t ); ?></li><?php endforeach; ?></ul>
			</div>
			<div class="ym-hero__shelf">
				<?php foreach ( $hero as $p ) : ?><a href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo $frame( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a><?php endforeach; ?>
			</div>
		</section>
		<section class="ym-shop">
			<ul class="ym-grid">
				<?php foreach ( $products as $p ) : ?>
					<li class="ym-card">
						<a class="ym-card__img" href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo $frame( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<a class="ym-card__name" href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo esc_html( $p->get_name() ); ?></a>
						<?php if ( $p->get_meta( '_ym_benefit' ) ) : ?><span class="ym-card__benefit"><?php echo esc_html( $p->get_meta( '_ym_benefit' ) ); ?></span><?php endif; ?>
						<span class="ym-card__price"><?php echo esc_html( YM_Country::money( $p->get_price() ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );
