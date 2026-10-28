<?php
/**
 * Measurement: consent, dataLayer for Google Tag Manager, attribution capture, and server-side conversions
 * (Meta Conversions API, Snap Conversions API). Specification: docs/MEASUREMENT.md.
 *
 * Ownership (one integration per event and destination):
 *   Browser events (page_view, view_item, add_to_cart, begin_checkout, purchase) are pushed to the dataLayer by this
 *   plugin and sent to GA4, Meta Pixel and Snap Pixel by GTM tags only. No other plugin may load these pixels.
 *   Server events (Meta Purchase, Snap PURCHASE, Meta OrderDelivered) are sent by this plugin only.
 *   Purchase is deduplicated: browser and server use the same ID, the country-prefixed transaction ID (EG-123).
 *
 * Consent: nothing that measures or advertises loads before the visitor accepts. "Essentials only" means GTM never
 * loads, click IDs are not stored and no server event is sent for that order. Choice cookie: ym_consent.
 *
 * Personal data: the dataLayer never carries name, phone, address or email. Server events carry the phone only as a
 * SHA-256 hash, only for orders placed with consent.
 *
 * Configuration (per store):
 *   options  ym_gtm_id, ym_ga4_id, ym_meta_pixel_id, ym_snap_pixel_id, ym_meta_test_event_code, ym_snap_disabled
 *   secrets  YM_META_CAPI_TOKEN, YM_SNAP_CAPI_TOKEN as constants in wp-config.php, read from the environment
 */

defined( 'ABSPATH' ) || exit;

final class YM_Tracking {

	const META_API = 'https://graph.facebook.com/v26.0/%s/events';
	const SNAP_API = 'https://tr.snapchat.com/v3/%s/events';
	const ATTR_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' );
	const CLICK_IDS = array( 'fbclid', 'ScCid', 'gclid', 'ttclid' );

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 50 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'capture' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'queue_purchase' ), 60, 3 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue_delivered' ) );
		add_action( 'ym_capi_send', array( __CLASS__, 'send' ), 10, 3 );
		add_action( 'ym_dl_event', array( __CLASS__, 'queue_dl' ) );
	}

	public static function consent() {
		$c = isset( $_COOKIE['ym_consent'] ) ? sanitize_key( wp_unslash( $_COOKIE['ym_consent'] ) ) : '';
		return in_array( $c, array( 'granted', 'denied' ), true ) ? $c : '';
	}

	/* ---------- browser ---------- */

	public static function head() {
		$gtm = (string) get_option( 'ym_gtm_id', '' );
		$base = array(
			'ym_country'  => YM_Country::code(),
			'ym_currency' => get_woocommerce_currency(),
			'ym_consent'  => self::consent() ? self::consent() : 'unset',
			'ym_env'      => YM_Env::type(),
		);
		?>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});
dataLayer.push(<?php echo wp_json_encode( $base ); ?>);
window.ymGTM = <?php echo wp_json_encode( preg_match( '/^GTM-[A-Z0-9]+$/', $gtm ) ? $gtm : '' ); ?>;
window.ymLoadGTM = function () {
  if (!window.ymGTM || window.ymGTMLoaded) return;
  window.ymGTMLoaded = true;
  gtag('consent', 'update', {ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted'});
  dataLayer.push({'gtm.start': Date.now(), event: 'gtm.js'});
  var s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtm.js?id=' + window.ymGTM;
  document.head.appendChild(s);
};
if (document.cookie.indexOf('ym_consent=granted') !== -1) { window.ymLoadGTM(); }
</script>
		<?php
	}

	/** Events to push on this page view. */
	public static function events() {
		$events = array();
		if ( WC()->session ) {
			foreach ( (array) WC()->session->get( 'ym_dl_queue', array() ) as $e ) {
				$events[] = $e;
			}
			WC()->session->set( 'ym_dl_queue', array() );
		}
		if ( is_product() ) {
			$p = YM_Offers::primary( wc_get_product( get_queried_object_id() ) );
			$events[] = self::ecommerce( 'view_item', array( self::item( $p, 1 ) ), (float) $p->get_price() );
		} elseif ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$items = array();
			foreach ( WC()->cart->get_cart() as $ci ) {
				$items[] = self::item( $ci['data'], (int) $ci['quantity'] );
			}
			$events[] = self::ecommerce( 'begin_checkout', $items, (float) WC()->cart->get_subtotal() );
		} elseif ( is_wc_endpoint_url( 'order-received' ) ) {
			$order = self::received_order();
			if ( $order && ! $order->get_meta( '_ym_dl_purchase' ) ) {
				$events[] = self::purchase_event( $order );
				$order->update_meta_data( '_ym_dl_purchase', time() );
				$order->save_meta_data();
			}
		}
		return $events;
	}

	private static function received_order() {
		global $wp;
		$id  = absint( $wp->query_vars['order-received'] ?? 0 );
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$o   = $id ? wc_get_order( $id ) : null;
		return $o && hash_equals( $o->get_order_key(), $key ) ? $o : null;
	}

	public static function item( WC_Product $p, $qty ) {
		return array(
			'item_id'   => $p->get_sku(),
			'item_name' => $p->get_name(),
			'price'     => (float) $p->get_price(),
			'quantity'  => (int) $qty,
		);
	}

	private static function ecommerce( $name, $items, $value ) {
		return array(
			'event'       => $name,
			'ym_event_id' => wp_generate_uuid4(),
			'ecommerce'   => array( 'currency' => get_woocommerce_currency(), 'value' => round( $value, 2 ), 'items' => $items ),
		);
	}

	/** GA4 purchase. transaction_id doubles as Meta eventID and Snap transaction_id for deduplication. */
	public static function purchase_event( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $it ) {
			$p       = $it->get_product();
			$items[] = array( 'item_id' => $p ? $p->get_sku() : '', 'item_name' => $it->get_name(), 'price' => (float) $order->get_item_subtotal( $it, false, false ), 'quantity' => (int) $it->get_quantity() );
		}
		$tx = YM_Env::transaction_id( $order );
		return array(
			'event'       => 'purchase',
			'ym_event_id' => $tx,
			'ecommerce'   => array(
				'transaction_id' => $tx,
				'currency'       => $order->get_currency(),
				'value'          => (float) $order->get_subtotal(),
				'shipping'       => (float) $order->get_shipping_total(),
				'items'          => $items,
			),
		);
	}

	/** Called by the offer form handler: the add_to_cart event is pushed on the next page. */
	public static function queue_dl( $event ) {
		if ( WC()->session ) {
			$q   = (array) WC()->session->get( 'ym_dl_queue', array() );
			$q[] = $event;
			WC()->session->set( 'ym_dl_queue', $q );
		}
	}

	public static function footer() {
		$events = self::events();
		?>
<div class="ym-consent" data-ym-consent hidden role="region" aria-label="<?php echo esc_attr( YM_Country::copy( 'consent_change' ) ); ?>">
	<p><?php echo esc_html( YM_Country::copy( 'consent_text' ) ); ?> <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php echo esc_html( YM_Country::copy( 'nav_privacy' ) ); ?></a></p>
	<div class="ym-consent__actions">
		<button type="button" class="ym-btn ym-btn--primary" data-ym-consent-yes><?php echo esc_html( YM_Country::copy( 'consent_yes' ) ); ?></button>
		<button type="button" class="ym-btn ym-btn--secondary" data-ym-consent-no><?php echo esc_html( YM_Country::copy( 'consent_no' ) ); ?></button>
	</div>
</div>
<script>
(function () {
  var ev = <?php echo wp_json_encode( $events, JSON_UNESCAPED_UNICODE ); ?>;
  ev.forEach(function (e) { if (e.ecommerce) dataLayer.push({ecommerce: null}); dataLayer.push(e); });

  // Attribution: campaign tags always; ad click IDs only with consent. First and last touch, 90 days.
  function read() { var m = document.cookie.match(/(?:^|; )ym_attr=([^;]*)/); try { return m ? JSON.parse(decodeURIComponent(m[1])) : {}; } catch (e) { return {}; } }
  function write(a) { document.cookie = 'ym_attr=' + encodeURIComponent(JSON.stringify(a)) + '; max-age=7776000; path=/; SameSite=Lax'; }
  var q = new URLSearchParams(location.search), touch = {}, granted = document.cookie.indexOf('ym_consent=granted') !== -1;
  <?php echo wp_json_encode( self::ATTR_KEYS ); ?>.forEach(function (k) { if (q.get(k)) touch[k] = q.get(k).slice(0, 100); });
  if (granted) <?php echo wp_json_encode( self::CLICK_IDS ); ?>.forEach(function (k) { if (q.get(k)) touch[k] = q.get(k).slice(0, 200); });
  if (Object.keys(touch).length) {
    touch.landing = location.pathname.slice(0, 120); touch.ref = document.referrer ? new URL(document.referrer).hostname : ''; touch.at = Math.floor(Date.now() / 1000);
    var a = read(); if (!a.first) a.first = touch; a.last = touch; write(a);
  }

  // Consent banner.
  var box = document.querySelector('[data-ym-consent]');
  function choose(v) {
    document.cookie = 'ym_consent=' + v + '; max-age=15552000; path=/; SameSite=Lax';
    dataLayer.push({event: 'ym_consent', ym_consent: v});
    if (v === 'granted') { window.ymLoadGTM(); }
    else { var a = read(); <?php echo wp_json_encode( self::CLICK_IDS ); ?>.forEach(function (k) { ['first', 'last'].forEach(function (t) { if (a[t]) delete a[t][k]; }); }); write(a); }
    if (box) box.hidden = true;
  }
  if (box) {
    if (document.cookie.indexOf('ym_consent=') === -1) box.hidden = false;
    box.querySelector('[data-ym-consent-yes]').addEventListener('click', function () { choose('granted'); });
    box.querySelector('[data-ym-consent-no]').addEventListener('click', function () { choose('denied'); });
  }
  document.querySelectorAll('[data-ym-consent-open]').forEach(function (b) { b.addEventListener('click', function () { if (box) box.hidden = false; }); });
})();
</script>
		<?php
	}

	/* ---------- order capture ---------- */

	/** Store consent and attribution on the order (internal reporting; never sent to analytics). */
	public static function capture( WC_Order $order ) {
		$consent = self::consent();
		$order->update_meta_data( '_ym_consent', $consent ? $consent : 'unset' );
		$attr = isset( $_COOKIE['ym_attr'] ) ? json_decode( wp_unslash( $_COOKIE['ym_attr'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and filtered below.
		if ( is_array( $attr ) ) {
			$clean = array();
			$keys  = array_merge( self::ATTR_KEYS, 'granted' === $consent ? self::CLICK_IDS : array(), array( 'landing', 'ref', 'at' ) );
			foreach ( array( 'first', 'last' ) as $t ) {
				foreach ( $keys as $k ) {
					if ( isset( $attr[ $t ][ $k ] ) ) {
						$clean[ $t ][ $k ] = sanitize_text_field( (string) $attr[ $t ][ $k ] );
					}
				}
			}
			$order->update_meta_data( '_ym_attr', wp_json_encode( $clean ) );
		}
		if ( 'granted' === $consent ) {
			foreach ( array( '_fbp' => '_ym_fbp', '_fbc' => '_ym_fbc', '_scid' => '_ym_scid' ) as $cookie => $meta ) {
				if ( ! empty( $_COOKIE[ $cookie ] ) ) {
					$order->update_meta_data( $meta, sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) );
				}
			}
			$order->update_meta_data( '_ym_ua', substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 400 ) );
		}
	}

	/* ---------- server events ---------- */

	public static function queue_purchase( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || 'granted' !== $order->get_meta( '_ym_consent' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		foreach ( self::platforms() as $platform ) {
			as_enqueue_async_action( 'ym_capi_send', array( $order->get_id(), $platform, 'purchase' ), 'yawmiyati' );
		}
	}

	public static function queue_delivered( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'granted' === $order->get_meta( '_ym_consent' ) && in_array( 'meta', self::platforms(), true ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'ym_capi_send', array( $order->get_id(), 'meta', 'delivered' ), 'yawmiyati' );
		}
	}

	public static function platforms() {
		$out = array();
		if ( get_option( 'ym_meta_pixel_id' ) ) {
			$out[] = 'meta';
		}
		if ( get_option( 'ym_snap_pixel_id' ) && ! get_option( 'ym_snap_disabled' ) ) {
			$out[] = 'snap';
		}
		return $out;
	}

	public static function hash( $v ) {
		return hash( 'sha256', strtolower( trim( (string) $v ) ) );
	}

	/** Phone in international digits for matching: 01012345678 → 201012345678; 07701234567 → 9647701234567. */
	public static function intl_phone( $phone ) {
		$p = YM_Country::normalise_phone( $phone );
		$cc = 'EG' === YM_Country::code() ? '20' : '964';
		return $cc . ltrim( $p, '0' );
	}

	public static function payload( WC_Order $order, $platform, $kind ) {
		$tx    = YM_Env::transaction_id( $order );
		$time  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$skus  = array();
		$items = array();
		foreach ( $order->get_items() as $it ) {
			$p       = $it->get_product();
			$sku     = $p ? $p->get_sku() : (string) $it->get_product_id();
			$skus[]  = $sku;
			$items[] = array( 'id' => $sku, 'quantity' => (int) $it->get_quantity(), 'item_price' => (float) $order->get_item_subtotal( $it, false, false ) );
		}
		$ph  = self::hash( self::intl_phone( $order->get_billing_phone() ) );
		$url = wc_get_checkout_url();
		if ( 'meta' === $platform ) {
			$user = array_filter(
				array(
					'ph'                => array( $ph ),
					'country'           => array( self::hash( strtolower( YM_Country::code() ) ) ),
					'external_id'       => array( self::hash( $order->get_billing_phone() ) ),
					'client_ip_address' => $order->get_customer_ip_address(),
					'client_user_agent' => $order->get_meta( '_ym_ua' ),
					'fbp'               => $order->get_meta( '_ym_fbp' ),
					'fbc'               => $order->get_meta( '_ym_fbc' ),
				)
			);
			$event = array(
				'event_name'       => 'purchase' === $kind ? 'Purchase' : 'OrderDelivered',
				'event_time'       => 'purchase' === $kind ? $time : time(),
				'event_id'         => 'purchase' === $kind ? $tx : $tx . '-delivered',
				'action_source'    => 'purchase' === $kind ? 'website' : 'system_generated',
				'event_source_url' => $url,
				'user_data'        => $user,
				'custom_data'      => array( 'currency' => $order->get_currency(), 'value' => (float) $order->get_subtotal(), 'content_ids' => $skus, 'content_type' => 'product', 'contents' => $items, 'order_id' => $tx, 'num_items' => array_sum( wp_list_pluck( $items, 'quantity' ) ) ),
			);
			$body = array( 'data' => array( $event ) );
			if ( get_option( 'ym_meta_test_event_code' ) ) {
				$body['test_event_code'] = (string) get_option( 'ym_meta_test_event_code' );
			}
			return $body;
		}
		$user = array_filter(
			array(
				'ph'                => array( $ph ),
				'client_ip_address' => $order->get_customer_ip_address(),
				'client_user_agent' => $order->get_meta( '_ym_ua' ),
				'sc_cookie1'        => $order->get_meta( '_ym_scid' ),
				'sc_click_id'       => self::click_id( $order, 'ScCid' ),
			)
		);
		return array(
			'data' => array(
				array(
					'event_name'       => 'PURCHASE',
					'event_time'       => $time,
					'event_id'         => $tx,
					'action_source'    => 'WEB',
					'event_source_url' => $url,
					'user_data'        => $user,
					'custom_data'      => array( 'currency' => $order->get_currency(), 'value' => (float) $order->get_subtotal(), 'order_id' => $tx, 'content_ids' => $skus, 'num_items' => array_sum( wp_list_pluck( $items, 'quantity' ) ) ),
				),
			),
		);
	}

	private static function click_id( WC_Order $order, $key ) {
		$a = json_decode( (string) $order->get_meta( '_ym_attr' ), true );
		return (string) ( $a['last'][ $key ] ?? '' );
	}

	/** Send one server event. Without a token, or when outbound HTTP is blocked (local), the payload is stored as a dry run. */
	public static function send( $order_id, $platform, $kind ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$meta_key = '_ym_capi_' . $platform . '_' . $kind;
		$done     = json_decode( (string) $order->get_meta( $meta_key ), true );
		if ( is_array( $done ) && 'sent' === ( $done['status'] ?? '' ) ) {
			return;
		}
		$body  = self::payload( $order, $platform, $kind );
		$token = 'meta' === $platform ? ( defined( 'YM_META_CAPI_TOKEN' ) ? YM_META_CAPI_TOKEN : '' ) : ( defined( 'YM_SNAP_CAPI_TOKEN' ) ? YM_SNAP_CAPI_TOKEN : '' );
		$pixel = (string) get_option( 'meta' === $platform ? 'ym_meta_pixel_id' : 'ym_snap_pixel_id' );
		$rec   = array( 'at' => time(), 'event_id' => $body['data'][0]['event_id'], 'payload' => $body );
		if ( ! $token || ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) ) {
			$rec['status'] = 'dry_run';
		} else {
			$url  = sprintf( 'meta' === $platform ? self::META_API : self::SNAP_API, rawurlencode( $pixel ) ) . '?access_token=' . rawurlencode( $token );
			$resp = wp_remote_post( $url, array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
			$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			$rec['status'] = $code >= 200 && $code < 300 ? 'sent' : 'error';
			$rec['code']   = $code;
			$rec['reply']  = is_wp_error( $resp ) ? $resp->get_error_message() : substr( (string) wp_remote_retrieve_body( $resp ), 0, 500 );
			$tries         = (int) ( $done['tries'] ?? 0 ) + 1;
			$rec['tries']  = $tries;
			if ( 'error' === $rec['status'] && $tries < 3 && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS * $tries, 'ym_capi_send', array( $order_id, $platform, $kind ), 'yawmiyati' );
			} elseif ( 'error' === $rec['status'] ) {
				YM_Ops::alert( 'capi_failed', $platform . ' ' . $kind . ' failed 3 times for order ' . $order->get_order_number() . ': ' . $rec['reply'], $order );
			}
		}
		$order->update_meta_data( $meta_key, wp_json_encode( $rec ) );
		$order->save_meta_data();
		YM_Ops::log( 'capi', array( 'order' => $order_id, 'platform' => $platform, 'kind' => $kind, 'status' => $rec['status'], 'code' => $rec['code'] ?? null ) );
	}

	/** For `wp ym launch-check`. */
	public static function launch_checks() {
		return array(
			'GTM container' => array( (bool) preg_match( '/^GTM-[A-Z0-9]+$/', (string) get_option( 'ym_gtm_id' ) ), 'option ym_gtm_id' ),
			'GA4 property'  => array( (bool) preg_match( '/^G-[A-Z0-9]+$/', (string) get_option( 'ym_ga4_id' ) ), 'option ym_ga4_id (tag configured in GTM)' ),
			'Meta pixel'    => array( (bool) get_option( 'ym_meta_pixel_id' ) && defined( 'YM_META_CAPI_TOKEN' ) && YM_META_CAPI_TOKEN, 'option ym_meta_pixel_id + YM_META_CAPI_TOKEN in the environment' ),
			'Snap pixel'    => array( (bool) get_option( 'ym_snap_disabled' ) || ( get_option( 'ym_snap_pixel_id' ) && defined( 'YM_SNAP_CAPI_TOKEN' ) && YM_SNAP_CAPI_TOKEN ), 'option ym_snap_pixel_id + YM_SNAP_CAPI_TOKEN, or ym_snap_disabled' ),
			'test report'   => array( (bool) get_option( 'ym_measurement_tested' ), 'live test report signed off (docs/MEASUREMENT.md, section 9); set ym_measurement_tested' ),
		);
	}
}
