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

/** [ym_header]: logo, country, cart. */
add_shortcode( 'ym_header', function () {
	$country = class_exists( 'YM_Country' ) ? YM_Country::code() : '';
	$names   = array( 'EG' => 'مصر', 'IQ' => 'العراق' );
	$count   = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	ob_start();
	?>
	<header class="ym-header">
		<a class="ym-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="يومياتي، الرئيسية"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.svg' ) ); ?>" alt="يومياتي" width="52" height="45"></a>
		<div class="ym-header__tools">
			<span class="ym-chip"><?php echo esc_html( $names[ $country ] ?? '' ); ?></span>
			<a class="ym-cart" href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#' ); ?>" aria-label="<?php echo esc_attr( 'السلة، ' . $count . ' منتج' ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
				<?php if ( $count ) : ?><span class="ym-cart__count"><?php echo esc_html( $count ); ?></span><?php endif; ?>
			</a>
		</div>
	</header>
	<?php
	return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
} );

/** [ym_footer] */
add_shortcode( 'ym_footer', function () {
	$country = class_exists( 'YM_Country' ) ? YM_Country::code() : 'EG';
	$ask     = 'IQ' === $country ? 'عدج سؤال؟ دزيلنا على إنستغرام ونرد عليج.' : 'عندك سؤال؟ ابعتيلنا على إنستجرام وهنرد عليكي.';
	return '<footer class="ym-footer"><img src="' . esc_url( get_theme_file_uri( 'assets/img/logo-white.svg' ) ) . '" alt="يومياتي" width="52" height="45"><p>' . esc_html( $ask ) . '</p></footer>';
} );
