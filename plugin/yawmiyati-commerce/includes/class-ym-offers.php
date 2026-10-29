<?php
/**
 * Offer groups and the product page.
 *
 * An offer group is a set of WooCommerce products that share one product page, e.g. "1 set" and "2 sets",
 * or "rack alone" and "rack + mats". Each offer is its own product with its own Taager SKU and price, so the
 * price always comes from WooCommerce on the server and the Taager plugin sees a normal product line.
 *
 * Product meta used:
 *   _ym_group          group slug shared by the offers
 *   _ym_offer_primary  "yes" on the product whose page shows the group (the default offer)
 *   _ym_offer_order    sort order of the offer cards
 *   _ym_offer_label    card title, e.g. "طقم واحد"
 *   _ym_offer_note     card line, e.g. "قطعتين: طويلة + صغيرة"
 *   _ym_offer_saving   optional saving line
 *   _ym_benefit        one-line benefit under the title
 *   _ym_diary          "ملاحظة من يومياتي" text
 *   _ym_fit            fit/measuring guidance
 *   _ym_benefits       JSON [[title, text], ...]
 *   _ym_specs          JSON [[label, value-or-null], ...]; null = not verified with Taager yet
 */

defined( 'ABSPATH' ) || exit;

final class YM_Offers {

	const MAX_QTY = 3; // default; per store: option ym_max_qty

	public static function max_qty() {
		return max( 1, (int) get_option( 'ym_max_qty', self::MAX_QTY ) );
	}

	public static function init() {
		add_shortcode( 'ym_product', array( __CLASS__, 'shortcode_product' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_post' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_secondary' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'limit_add' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'limit_update' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore' ), 5 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'restore_stash' ), 99 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'stash_notice' ), 5 );
	}

	/*
	 * Buy now checks out one offer without losing the rest of the cart: the other items are set aside in the
	 * session and come back when she leaves checkout or after the order is placed.
	 */
	const STASH = 'ym_stash';
	const BUY   = 'ym_buy_now';

	public static function stash_cart() {
		$cart    = WC()->cart;
		$session = WC()->session;
		$stash   = (array) $session->get( self::STASH, array() );
		if ( ! $session->get( self::BUY ) ) {
			foreach ( $cart->get_cart() as $item ) {
				$stash[] = array( 'product_id' => (int) $item['product_id'], 'variation_id' => (int) $item['variation_id'], 'quantity' => (int) $item['quantity'] );
			}
		}
		$cart->empty_cart();
		$session->set( self::STASH, $stash );
		$session->set( self::BUY, 1 );
	}

	public static function restore_stash() {
		$session = WC()->session;
		if ( ! $session ) {
			return;
		}
		$stash = (array) $session->get( self::STASH, array() );
		$session->set( self::STASH, array() );
		$session->set( self::BUY, 0 );
		foreach ( $stash as $s ) {
			$p = wc_get_product( $s['product_id'] );
			if ( $p && $p->is_purchasable() && $p->is_in_stock() ) {
				WC()->cart->add_to_cart( $s['product_id'], max( 1, (int) $s['quantity'] ), (int) $s['variation_id'] );
			}
		}
	}

	/**
	 * Restore on any page outside checkout. The order-received page restores later (woocommerce_thankyou), because
	 * WooCommerce empties the cart again while rendering it.
	 */
	public static function maybe_restore() {
		if ( ! WC()->session || ! WC()->session->get( self::STASH ) || is_checkout() ) {
			return;
		}
		self::restore_stash();
	}

	public static function stash_notice() {
		if ( WC()->session && WC()->session->get( self::STASH ) ) {
			echo '<p class="ym-note">' . esc_html( YM_Country::copy( 'cart_saved' ) ) . '</p>';
		}
	}

	public static function assets() {
		wp_enqueue_script( 'ym', YM_URL . 'assets/ym.js', array(), YM_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/** Offers in the same group as $product, primary first by order. */
	public static function group( WC_Product $product ) {
		$slug = $product->get_meta( '_ym_group' );
		if ( ! $slug ) {
			return array( $product );
		}
		$ids = wc_get_products(
			array(
				'status'     => 'publish',
				'limit'      => 10,
				'return'     => 'ids',
				'meta_key'   => '_ym_group', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$offers = array_filter( array_map( 'wc_get_product', $ids ) );
		usort( $offers, fn( $a, $b ) => (int) $a->get_meta( '_ym_offer_order' ) <=> (int) $b->get_meta( '_ym_offer_order' ) );
		return $offers;
	}

	public static function primary( WC_Product $product ) {
		foreach ( self::group( $product ) as $p ) {
			if ( 'yes' === $p->get_meta( '_ym_offer_primary' ) ) {
				return $p;
			}
		}
		return $product;
	}

	/** Secondary offers have no page of their own: send visitors to the group page. */
	public static function redirect_secondary() {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( $product && $product->get_meta( '_ym_group' ) && 'yes' !== $product->get_meta( '_ym_offer_primary' ) ) {
			wp_safe_redirect( get_permalink( self::primary( $product )->get_id() ), 301 );
			exit;
		}
	}

	/** Offer form: buy now (to checkout) or add to cart. The client sends only the offer's product ID. */
	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ym_offer'] ) ) {
			return;
		}
		$page_id = absint( $_POST['ym_page'] ?? 0 );
		$back    = $page_id && get_permalink( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
		// Guests have no session until something is in the cart; start one so notices survive the redirect.
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		if ( ! isset( $_POST['ym_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ym_offer_nonce'] ) ), 'ym_offer' ) ) {
			wc_add_notice( YM_Country::copy( 'expired' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$offer = wc_get_product( absint( $_POST['ym_offer'] ) );
		$page  = wc_get_product( absint( $_POST['ym_page'] ?? 0 ) );
		$valid = $offer && $page && $offer->is_purchasable() && $offer->is_in_stock()
			&& in_array( $offer->get_id(), array_map( fn( $p ) => $p->get_id(), self::group( $page ) ), true );
		if ( ! $valid ) {
			wc_add_notice( YM_Country::copy( 'offer_na' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$intent = sanitize_key( wp_unslash( $_POST['ym_intent'] ?? 'buy' ) );
		if ( 'buy' === $intent ) {
			self::stash_cart();
		} elseif ( WC()->session->get( self::STASH ) ) {
			self::restore_stash();
		}
		$qty   = min( self::max_qty(), max( 1, absint( $_POST['ym_qty'] ?? 1 ) ) );
		$added = WC()->cart->add_to_cart( $offer->get_id(), $qty );
		if ( ! $added ) {
			wp_safe_redirect( $back );
			exit;
		}
		do_action(
			'ym_dl_event',
			array(
				'event'       => 'add_to_cart',
				'ym_event_id' => wp_generate_uuid4(),
				'ym_intent'   => $intent,
				'ecommerce'   => array( 'currency' => get_woocommerce_currency(), 'value' => YM_Country::round_money( (float) $offer->get_price() * $qty ), 'items' => array( YM_Tracking::item( $offer, $qty ) ) ),
			)
		);
		if ( 'buy' === $intent ) {
			wp_safe_redirect( wc_get_checkout_url() );
		} else {
			wc_add_notice( YM_Country::copy( 'added' ), 'success' );
			// Added from a recommendation: back to where she was (the cart, or the product page she was reading).
			$from = sanitize_key( wp_unslash( $_POST['ym_back'] ?? '' ) );
			$src  = ctype_digit( $from ) ? wc_get_product( (int) $from ) : null;
			wp_safe_redirect( 'cart' === $from ? wc_get_cart_url() : ( $src ? $src->get_permalink() : get_permalink( $page->get_id() ) ) );
		}
		exit;
	}

	public static function limit_add( $passed, $product_id, $qty ) {
		$in_cart = 0;
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( (int) $item['product_id'] === (int) $product_id ) {
				$in_cart += (int) $item['quantity'];
			}
		}
		if ( $in_cart + (int) $qty > self::max_qty() ) {
			wc_add_notice( sprintf( YM_Country::copy( 'max_qty' ), self::max_qty() ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function limit_update( $passed, $key, $values, $qty ) {
		if ( (int) $qty > self::max_qty() ) {
			wc_add_notice( sprintf( YM_Country::copy( 'max_qty' ), self::max_qty() ), 'error' );
			return false;
		}
		return $passed;
	}

	private static function json_meta( WC_Product $p, $key ) {
		$v = json_decode( (string) $p->get_meta( $key ), true );
		return is_array( $v ) ? $v : array();
	}

	private static function show_unverified() {
		return ! YM_Env::is_production();
	}

	public static function shortcode_product() {
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return '';
		}
		ob_start();
		self::render( self::primary( $product ) );
		// The shortcode block runs wpautop over its output; without line breaks there is nothing for it to wrap.
		return preg_replace( '/\s*\n\s*/', ' ', ob_get_clean() );
	}

	public static function render( WC_Product $page ) {
		$offers  = self::group( $page );
		$rules   = YM_Country::rules();
		$images  = array_filter( array_merge( array( $page->get_image_id() ), $page->get_gallery_image_ids() ) );
		$default = $page;
		$fees    = array_unique( array_map( fn( $g ) => $g[1], $rules['governorates'] ) );
		sort( $fees );
		$fee_txt = count( $fees ) > 1 ? sprintf( YM_Country::copy( 'fee_range' ), YM_Country::number( min( $fees ) ), YM_Country::money( max( $fees ) ) ) : sprintf( YM_Country::copy( 'fee_flat' ), YM_Country::money( $fees[0] ) );
		?>
		<article class="ym-pdp" data-ym-pdp data-ym-money="<?php echo esc_attr( wp_json_encode( array( 'd' => YM_Country::decimals(), 'sym' => $rules['symbol'], 'min' => min( $fees ), 'max' => max( $fees ), 'one' => YM_Country::copy( 'total_line' ), 'range' => YM_Country::copy( 'total_range' ) ), JSON_UNESCAPED_UNICODE ) ); ?>">
			<?php wc_print_notices(); ?>
			<?php echo YM_SEO::breadcrumb_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<section class="ym-gallery" aria-label="<?php echo esc_attr( $page->get_name() ); ?>">
				<?php if ( $images ) : ?>
					<?php
					// The main image is the page's largest element: loaded first, at the size the screen needs. Everything
					// below it (thumbnails, video poster) waits until it is near the screen.
					echo wp_get_attachment_image( $images[0], 'large', false, array( 'class' => 'ym-gallery__main', 'data-ym-main' => '1', 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'sizes' => '(min-width: 960px) 520px, 100vw', 'alt' => get_post_meta( $images[0], '_wp_attachment_image_alt', true ) ? get_post_meta( $images[0], '_wp_attachment_image_alt', true ) : $page->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
					<?php if ( count( $images ) > 1 ) : ?>
						<div class="ym-gallery__thumbs">
							<?php foreach ( $images as $i => $img ) : ?>
								<button type="button" class="ym-thumb" data-ym-thumb="<?php echo esc_url( wp_get_attachment_image_url( $img, 'large' ) ); ?>" data-ym-srcset="<?php echo esc_attr( (string) wp_get_attachment_image_srcset( $img, 'large' ) ); ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( YM_Country::copy( 'gallery_n' ), $i + 1, count( $images ) ) ); ?>">
									<?php echo wp_get_attachment_image( $img, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<?php echo self::render_video( $page ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>

			<section class="ym-buy">
				<h1 class="ym-title"><?php echo esc_html( $page->get_name() ); ?></h1>
				<?php if ( $page->get_meta( '_ym_benefit' ) ) : ?>
					<p class="ym-lede"><?php echo esc_html( $page->get_meta( '_ym_benefit' ) ); ?></p>
				<?php endif; ?>
				<p class="ym-price"><span data-ym-price><?php echo esc_html( YM_Country::money( $default->get_price() ) ); ?></span> <span class="ym-price__fee">+ <?php echo esc_html( $fee_txt ); ?></span></p>

				<form method="post" class="ym-offers" data-ym-offers>
					<?php wp_nonce_field( 'ym_offer', 'ym_offer_nonce' ); ?>
					<input type="hidden" name="ym_page" value="<?php echo esc_attr( $page->get_id() ); ?>">
					<?php if ( count( $offers ) > 1 ) : ?>
						<fieldset class="ym-offers__set">
							<legend class="ym-offers__legend"><?php echo esc_html( YM_Country::copy( 'choose' ) ); ?></legend>
							<?php foreach ( $offers as $o ) : ?>
								<?php $thumb = $o->get_image_id() ? $o->get_image_id() : $page->get_image_id(); ?>
								<label class="ym-offer">
									<input type="radio" name="ym_offer" value="<?php echo esc_attr( $o->get_id() ); ?>" data-ym-price-num="<?php echo esc_attr( (float) $o->get_price() ); ?>" data-ym-price-text="<?php echo esc_attr( YM_Country::money( $o->get_price() ) ); ?>" <?php checked( $o->get_id(), $default->get_id() ); ?> <?php disabled( ! $o->is_in_stock() ); ?>>
									<?php echo wp_get_attachment_image( $thumb, 'thumbnail', false, array( 'class' => 'ym-offer__img', 'alt' => '' ) ); ?>
									<span class="ym-offer__text">
										<span class="ym-offer__label"><?php echo esc_html( $o->get_meta( '_ym_offer_label' ) ? $o->get_meta( '_ym_offer_label' ) : $o->get_name() ); ?></span>
										<?php if ( $o->get_meta( '_ym_offer_note' ) ) : ?><span class="ym-offer__note"><?php echo esc_html( $o->get_meta( '_ym_offer_note' ) ); ?></span><?php endif; ?>
										<?php if ( $o->get_meta( '_ym_offer_saving' ) ) : ?><span class="ym-offer__saving"><?php echo esc_html( $o->get_meta( '_ym_offer_saving' ) ); ?></span><?php endif; ?>
										<?php if ( ! $o->is_in_stock() ) : ?><span class="ym-offer__note"><?php echo esc_html( YM_Country::copy( 'sold_out_tag' ) ); ?></span><?php endif; ?>
									</span>
									<span class="ym-offer__price"><?php echo esc_html( YM_Country::money( $o->get_price() ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php else : ?>
						<input type="hidden" name="ym_offer" value="<?php echo esc_attr( $page->get_id() ); ?>" data-ym-price-num="<?php echo esc_attr( (float) $page->get_price() ); ?>">
					<?php endif; ?>
					<?php if ( YM_Markets::accepts_orders() && $page->is_in_stock() ) : ?>
						<div class="ym-qty">
							<label for="ym-qty"><?php echo esc_html( YM_Country::copy( 'qty_label' ) ); ?></label>
							<div class="ym-qty__ctl">
								<button type="button" data-ym-qty="-1" aria-label="<?php echo esc_attr( YM_Country::copy( 'qty_less' ) ); ?>">−</button>
								<input id="ym-qty" name="ym_qty" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( self::max_qty() ); ?>" value="1" data-ym-qty-input>
								<button type="button" data-ym-qty="1" aria-label="<?php echo esc_attr( YM_Country::copy( 'qty_more' ) ); ?>">+</button>
							</div>
						</div>
						<p class="ym-total" data-ym-total aria-live="polite"><?php echo esc_html( self::total_text( (float) $default->get_price(), 1 ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! YM_Markets::accepts_orders() ) : ?>
						<p class="ym-out" data-ym-closed><?php echo esc_html( YM_Country::copy( 'market_closed' ) ); ?></p>
					<?php elseif ( $page->is_in_stock() ) : ?>
						<button type="submit" name="ym_intent" value="buy" class="ym-btn ym-btn--primary"><?php echo esc_html( YM_Country::copy( 'buy' ) ); ?></button>
						<button type="submit" name="ym_intent" value="cart" class="ym-btn ym-btn--secondary"><?php echo esc_html( YM_Country::copy( 'add' ) ); ?></button>
					<?php else : ?>
						<p class="ym-out"><?php echo esc_html( YM_Country::copy( 'sold_out' ) ); ?></p>
					<?php endif; ?>
				</form>
			</section>

			<?php if ( $page->get_meta( '_ym_diary' ) ) : ?>
				<aside class="ym-diary" aria-label="<?php echo esc_attr( YM_Country::copy( 'diary_title' ) ); ?>">
					<p class="ym-diary__head"><span class="ym-diary__title"><?php echo esc_html( YM_Country::copy( 'diary_title' ) ); ?></span></p>
					<p class="ym-diary__body"><?php echo esc_html( $page->get_meta( '_ym_diary' ) ); ?></p>
				</aside>
			<?php endif; ?>

			<?php if ( $page->get_meta( '_ym_fit' ) ) : ?>
				<section class="ym-section">
					<h2><?php echo esc_html( YM_Country::copy( 'fit_q' ) ); ?></h2>
					<p><?php echo esc_html( $page->get_meta( '_ym_fit' ) ); ?></p>
				</section>
			<?php endif; ?>

			<?php $benefits = self::json_meta( $page, '_ym_benefits' ); ?>
			<?php if ( $benefits ) : ?>
				<section class="ym-section">
					<h2><?php echo esc_html( YM_Country::copy( 'why' ) ); ?></h2>
					<?php foreach ( $benefits as $b ) : ?>
						<p><strong><?php echo esc_html( $b[0] ); ?></strong> <?php echo esc_html( $b[1] ?? '' ); ?></p>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>

			<?php
			$specs = array_filter( self::json_meta( $page, '_ym_specs' ), fn( $s ) => null !== ( $s[1] ?? null ) || self::show_unverified() );
			?>
			<?php if ( $specs ) : ?>
				<section class="ym-section">
					<h2><?php echo esc_html( YM_Country::copy( 'specs_title' ) ); ?></h2>
					<dl class="ym-specs">
						<?php foreach ( $specs as $s ) : ?>
							<div><dt><?php echo esc_html( $s[0] ); ?></dt><dd><?php echo null === ( $s[1] ?? null ) ? '<span class="ym-unverified">[' . esc_html( YM_Country::copy( 'unverified' ) ) . ']</span>' : esc_html( $s[1] ); ?></dd></div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endif; ?>

			<?php echo class_exists( 'YM_Reviews' ) ? YM_Reviews::render( $page ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<section class="ym-section ym-delivery">
				<h2><?php echo esc_html( YM_Country::copy( 'delivery_title' ) ); ?></h2>
				<?php self::render_fee_table(); ?>
				<p><?php echo esc_html( YM_Country::copy( 'one_fee' ) . ' ' . YM_Country::copy( 'confirm_call' ) ); ?></p>
				<p><?php echo esc_html( trim( YM_Country::copy( 'payment' ) . ' ' . YM_Country::copy( 'returns' ) ) ); ?></p>
			</section>

			<?php echo YM_Markets::accepts_orders() ? YM_Reco::render_page( $page ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>

			<?php if ( YM_Markets::accepts_orders() && $page->is_in_stock() ) : ?>
			<div class="ym-sticky" data-ym-sticky>
				<span class="ym-sticky__price" data-ym-price><?php echo esc_html( YM_Country::money( $default->get_price() ) ); ?></span>
				<button type="button" class="ym-btn ym-btn--primary" data-ym-sticky-buy><?php echo esc_html( YM_Country::copy( 'buy_short' ) ); ?></button>
			</div>
			<?php endif; ?>
		</article>
		<?php
	}

	/** "Total with delivery" for a quantity: exact where the fee is flat, a range where it depends on the governorate. */
	public static function total_text( $price, $qty ) {
		$fees = array_unique( array_map( fn( $g ) => (float) $g[1], YM_Country::rules()['governorates'] ) );
		$sub  = (float) $price * max( 1, (int) $qty );
		if ( count( $fees ) > 1 ) {
			return sprintf( YM_Country::copy( 'total_range' ), YM_Country::number( $sub + min( $fees ) ), YM_Country::money( $sub + max( $fees ) ) );
		}
		return sprintf( YM_Country::copy( 'total_line' ), YM_Country::money( $sub + reset( $fees ) ) );
	}

	/**
	 * Product video: the poster image only; the video file is fetched when she presses play. Meta _ym_video JSON:
	 * {src, poster (attachment ID), demo_verified (a real, unedited demonstration of this exact item), generated}.
	 * A generated video is never shown as a demonstration of what the product does.
	 */
	public static function render_video( WC_Product $page ) {
		$v = json_decode( (string) $page->get_meta( '_ym_video' ), true );
		if ( ! is_array( $v ) || empty( $v['src'] ) || empty( $v['poster'] ) ) {
			return '';
		}
		$poster = wp_get_attachment_image( (int) $v['poster'], 'large', false, array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'class' => 'ym-video__poster' ) );
		return '<div class="ym-video" data-ym-video data-src="' . esc_url( $v['src'] ) . '"><button type="button" class="ym-video__play" aria-label="' . esc_attr( YM_Country::copy( 'video_play' ) ) . '">' . $poster . '<span class="ym-video__icon" aria-hidden="true"></span></button></div>';
	}

	public static function render_fee_table() {
		$zones = YM_Country::rules()['zones'];
		if ( 1 === count( $zones ) ) {
			echo '<p><strong>' . esc_html( YM_Country::money( $zones[0][0] ) ) . '</strong> ' . esc_html( $zones[0][1] ) . '.' . ( $zones[0][2] ? ' ' . esc_html( $zones[0][2] ) : '' ) . '</p>';
			return;
		}
		$head = explode( '|', YM_Country::copy( 'zones_head' ) );
		// Delivery times come from the supplier's publication ("Taager said"); they are shown only once observed on
		// real orders in this country (option ym_delivery_days_verified, set at checklist P0-8/9).
		$days = (bool) get_option( 'ym_delivery_days_verified' ) && array_filter( array_column( $zones, 2 ) );
		echo '<table class="ym-fees"><thead><tr><th scope="col">' . esc_html( $head[0] ) . '</th><th scope="col">' . esc_html( $head[1] ) . '</th>' . ( $days ? '<th scope="col">' . esc_html( $head[2] ?? '' ) . '</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $zones as $z ) {
			echo '<tr><td>' . esc_html( $z[1] ) . '</td><td>' . esc_html( YM_Country::money( $z[0] ) ) . '</td>' . ( $days ? '<td>' . esc_html( $z[2] ) . '</td>' : '' ) . '</tr>';
		}
		echo '</tbody></table>';
	}

}
