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
 * Withdrawal (choosing "essentials only" after accepting) tells Google tags consent is denied, deletes measurement
 * cookies, reloads the page so no loaded tag keeps running, and tells the server, which marks that visitor's
 * orders as withdrawn and cancels their queued server events. Every server event re-checks consent when it is sent.
 * Click IDs seen before a choice are held only in this browser tab (sessionStorage), never sent anywhere, and are
 * kept only if she accepts during that visit; declining or closing the tab discards them.
 *
 * Purchase in the browser: pushed on the confirmation page until the browser confirms that GTM had loaded when the
 * event was pushed (POST /ym/v1/dl-ack). Rendering the page is not taken as evidence. Receipt by GA4, Meta or Snap
 * is checked in their own test tools (docs/MEASUREMENT.md §9), not here.
 *
 * Addresses: the order key is removed from the address bar on the confirmation page before any tag loads (the
 * session remembers it), the page sends only its origin as referrer, and the dataLayer carries ym_page_location
 * without query strings. GTM's GA4 tag must use {{ym_page_location}} as page_location.
 *
 * Delivered: OrderDelivered is sent only for a verified delivery (YM_Lifecycle), with that delivery's own time.
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
		add_action( 'ym_order_delivered', array( __CLASS__, 'queue_delivered' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'woocommerce_thankyou_order_key', array( __CLASS__, 'session_order_key' ) );
		add_action( 'wp_head', array( __CLASS__, 'referrer_policy' ), 0 );
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
			'ym_page_location' => self::clean_location(),
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
  s.onload = function () { if (window.ymOnGTMReady) window.ymOnGTMReady(); else window.ymGTMReady = true; };
  document.head.appendChild(s);
};
if (document.cookie.indexOf('ym_consent=granted') !== -1) { window.ymLoadGTM(); }
<?php if ( YM_Env::test_hooks() ) : ?>
// Local test hook: no network here, so the GTM container is replaced by a stub that "loads" at once.
if (window.ymGTM === 'GTM-TESTSTUB') { window.ymLoadGTM = function () { if (window.ymGTMLoaded) return; window.ymGTMLoaded = true; gtag('consent', 'update', {ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted'}); dataLayer.push({event: 'gtm.js'}); setTimeout(function () { if (window.ymOnGTMReady) window.ymOnGTMReady(); else window.ymGTMReady = true; }, 50); }; if (document.cookie.indexOf('ym_consent=granted') !== -1) { window.ymGTMLoaded = false; window.ymLoadGTM(); } }
<?php endif; ?>
</script>
		<?php
	}

	/** This page's address without query string or fragment (the order key and campaign tags never reach GA4 here). */
	public static function clean_location() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', PHP_URL_PATH );
		return home_url( $path ? $path : '/' );
	}

	/** Checkout and confirmation pages send only the site origin as referrer. */
	public static function referrer_policy() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			echo '<meta name="referrer" content="origin">' . "\n";
		}
	}

	/**
	 * The confirmation page's address loses ?key= in the browser (see footer script); on reload WooCommerce gets the
	 * key from this session if the order was placed here.
	 */
	public static function session_order_key( $key ) {
		if ( $key || ! WC()->session ) {
			return $key;
		}
		global $wp;
		$id   = absint( $wp->query_vars['order-received'] ?? 0 );
		$keys = (array) WC()->session->get( 'ym_order_keys', array() );
		return $id && isset( $keys[ $id ] ) ? (string) $keys[ $id ] : $key;
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
			if ( $order && WC()->session ) {
				$keys                       = (array) WC()->session->get( 'ym_order_keys', array() );
				$keys[ $order->get_id() ]   = $order->get_order_key();
				WC()->session->set( 'ym_order_keys', array_slice( $keys, -5, null, true ) );
			}
			// Pushed until the browser acknowledges it with GTM loaded; GA4/Meta/Snap deduplicate on transaction_id.
			if ( $order && ! $order->get_meta( '_ym_dl_purchase' ) && 'denied' !== self::consent() ) {
				$e           = self::purchase_event( $order );
				$e['ym_ack'] = self::ack_token( $order );
				$events[]    = $e;
			}
		}
		return $events;
	}

	private static function received_order() {
		global $wp;
		$id  = absint( $wp->query_vars['order-received'] ?? 0 );
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$key = self::session_order_key( $key );
		$o   = $id ? wc_get_order( $id ) : null;
		return $o && $key && hash_equals( $o->get_order_key(), (string) $key ) ? $o : null;
	}

	/** Proof the browser saw this order's page, without exposing the order key (HMAC of ID + key). */
	public static function ack_token( WC_Order $order ) {
		return substr( hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) ), 0, 32 );
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
			'ecommerce'   => array( 'currency' => get_woocommerce_currency(), 'value' => YM_Country::round_money( $value ), 'items' => $items ),
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

  var ACK = <?php echo wp_json_encode( esc_url_raw( rest_url( 'ym/v1/dl-ack' ) ) ); ?>, CONSENT = <?php echo wp_json_encode( esc_url_raw( rest_url( 'ym/v1/consent' ) ) ); ?>;
  var IDS = <?php echo wp_json_encode( self::CLICK_IDS ); ?>, TAGS = <?php echo wp_json_encode( self::ATTR_KEYS ); ?>;
  function has(v) { return document.cookie.indexOf('ym_consent=' + v) !== -1; }

  // The confirmation page's key leaves the address bar before any tag can read it (the session remembers it).
  if (/[?&]key=wc_order_/.test(location.search)) {
    var u = new URL(location.href); u.searchParams.delete('key'); history.replaceState(history.state, '', u.pathname + (u.search || '') + u.hash);
  }

  // Purchase acknowledgement: only once GTM has actually loaded, never on render alone.
  function ackPurchases() {
    if (!window.ymGTMLoaded || !window.ymGTMReady) return;
    ev.forEach(function (e) {
      if (e.event !== 'purchase' || !e.ym_ack || e.ym_acked) return;
      e.ym_acked = true;
      var body = JSON.stringify({tx: e.ecommerce.transaction_id, ack: e.ym_ack});
      if (navigator.sendBeacon) navigator.sendBeacon(ACK, new Blob([body], {type: 'application/json'}));
      else fetch(ACK, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: body, keepalive: true});
    });
  }
  window.ymOnGTMReady = function () { window.ymGTMReady = true; ackPurchases(); };
  ackPurchases();

  // Attribution: campaign tags always; ad click IDs only with consent. First and last touch, 90 days.
  function read() { var m = document.cookie.match(/(?:^|; )ym_attr=([^;]*)/); try { return m ? JSON.parse(decodeURIComponent(m[1])) : {}; } catch (e) { return {}; } }
  function write(a) { document.cookie = 'ym_attr=' + encodeURIComponent(JSON.stringify(a)) + '; max-age=7776000; path=/; SameSite=Lax'; }
  function held(v) { try { if (v === undefined) return JSON.parse(sessionStorage.getItem('ym_held_ids') || '{}'); if (v === null) sessionStorage.removeItem('ym_held_ids'); else sessionStorage.setItem('ym_held_ids', JSON.stringify(v)); } catch (e) { return {}; } }
  function remember(touch) { var a = read(); if (!a.first) a.first = touch; a.last = touch; write(a); }
  var q = new URLSearchParams(location.search), touch = {}, ids = {};
  TAGS.forEach(function (k) { if (q.get(k)) touch[k] = q.get(k).slice(0, 100); });
  IDS.forEach(function (k) { if (q.get(k)) ids[k] = q.get(k).slice(0, 200); });
  if (Object.keys(touch).length || Object.keys(ids).length) {
    touch.landing = location.pathname.slice(0, 120); touch.ref = document.referrer ? new URL(document.referrer).hostname : ''; touch.at = Math.floor(Date.now() / 1000);
    if (has('granted')) { Object.assign(touch, ids); }
    else if (!has('denied') && Object.keys(ids).length) { held({ids: ids, at: touch.at}); }  // tab-only until she chooses
    remember(touch);
  }

  // Consent banner.
  var box = document.querySelector('[data-ym-consent]');
  var MEASURE = /^(_ga|_gid|_gat|_gcl_|_fbp|_fbc|_scid|_sctr|_ttp|_uet)/;
  function wipe() {
    document.cookie.split('; ').forEach(function (c) {
      var n = c.split('=')[0];
      if (!MEASURE.test(n)) return;
      var host = location.hostname.split('.');
      for (var i = 0; i < host.length - 1; i++) {
        var d = host.slice(i).join('.');
        document.cookie = n + '=; max-age=0; path=/; domain=' + d;
        document.cookie = n + '=; max-age=0; path=/; domain=.' + d;
      }
      document.cookie = n + '=; max-age=0; path=/';
    });
  }
  function choose(v) {
    var was = has('granted');
    document.cookie = 'ym_consent=' + v + '; max-age=15552000; path=/; SameSite=Lax';
    dataLayer.push({event: 'ym_consent', ym_consent: v});
    if (v === 'granted') {
      if (!/(?:^|; )ym_cs=/.test(document.cookie)) {
        var r = new Uint8Array(16); crypto.getRandomValues(r);
        document.cookie = 'ym_cs=' + Array.prototype.map.call(r, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('') + '; max-age=15552000; path=/; SameSite=Lax';
      }
      var h = held(); held(null);
      if (h && h.ids) { var a = read(); ['first', 'last'].forEach(function (t) { if (a[t] && a[t].at === h.at) Object.assign(a[t], h.ids); }); if (!a.last) { a.first = a.last = Object.assign({at: h.at, landing: location.pathname}, h.ids); } write(a); }
      window.ymLoadGTM();
      ackPurchases();
    } else {
      held(null);
      var b = read(); IDS.forEach(function (k) { ['first', 'last'].forEach(function (t) { if (b[t]) delete b[t][k]; }); }); write(b);
      if (was) {
        // Withdrawal: deny consent to loaded Google tags, delete measurement cookies, tell the server, and reload so
        // nothing that already loaded keeps running.
        gtag('consent', 'update', {ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});
        window.ymGTM = '';
        wipe();
        var done = function () { location.reload(); };
        fetch(CONSENT, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({consent: 'denied'}), keepalive: true}).then(done, done);
      }
    }
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
		$subject = self::subject();
		if ( $subject ) {
			$order->update_meta_data( '_ym_consent_subject', $subject );
		}
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

	/** A verified delivery (YM_Lifecycle) — never a bare "completed" status. */
	public static function queue_delivered( $order, $event = array() ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( $order && 'granted' === $order->get_meta( '_ym_consent' ) && in_array( 'meta', self::platforms(), true ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'ym_capi_send', array( $order->get_id(), 'meta', 'delivered' ), 'yawmiyati' );
		}
	}

	/** When the verified delivery happened (the event's own time, stable across retries), or 0. */
	public static function delivered_at( WC_Order $order ) {
		foreach ( array_reverse( YM_Lifecycle::events( $order ) ) as $e ) {
			if ( 'delivered' === $e['e'] && ! empty( $e['verified'] ) && empty( $e['conflict'] ) ) {
				return (int) $e['at'];
			}
		}
		return 0;
	}

	/* ---------- consent subject and endpoints ---------- */

	/** Random first-party ID tying a browser's consent to its orders, so a later withdrawal reaches them. */
	public static function subject() {
		$s = isset( $_COOKIE['ym_cs'] ) ? sanitize_key( wp_unslash( $_COOKIE['ym_cs'] ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $s ) ? $s : '';
	}

	public static function routes() {
		register_rest_route( 'ym/v1', '/consent', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_consent' ) ) );
		register_rest_route( 'ym/v1', '/dl-ack', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_ack' ) ) );
	}

	/** Withdrawal: this browser's orders stop being sent to ad platforms; queued sends are cancelled. */
	public static function rest_consent( WP_REST_Request $req ) {
		if ( 'denied' !== $req->get_param( 'consent' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$n = self::withdraw( self::subject() );
		return new WP_REST_Response( array( 'ok' => true, 'orders' => $n ), 200 );
	}

	public static function withdraw( $subject ) {
		if ( ! $subject ) {
			return 0;
		}
		$ids = wc_get_orders( array( 'limit' => 200, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ), 'meta_key' => '_ym_consent_subject', 'meta_value' => $subject ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o || 'withdrawn' === $o->get_meta( '_ym_consent' ) ) {
				continue;
			}
			$o->update_meta_data( '_ym_consent', 'withdrawn' );
			$o->update_meta_data( '_ym_consent_withdrawn_at', time() );
			$o->save_meta_data();
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				foreach ( array( 'meta', 'snap' ) as $pf ) {
					foreach ( array( 'purchase', 'delivered' ) as $kind ) {
						as_unschedule_all_actions( 'ym_capi_send', array( (int) $id, $pf, $kind ), 'yawmiyati' );
					}
				}
			}
		}
		YM_Ops::log( 'consent_withdrawn', array( 'orders' => count( $ids ) ) );
		return count( $ids );
	}

	public static function rest_ack( WP_REST_Request $req ) {
		$tx  = (string) $req->get_param( 'tx' );
		$ack = (string) $req->get_param( 'ack' );
		if ( ! preg_match( '/^' . YM_Country::code() . '-(\d+)$/', $tx, $m ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order || ! hash_equals( self::ack_token( $order ), $ack ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( ! $order->get_meta( '_ym_dl_purchase' ) ) {
			$order->update_meta_data( '_ym_dl_purchase', time() );
			$order->save_meta_data();
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
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
		return YM_Country::intl_phone( $phone );
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
				'event_time'       => 'purchase' === $kind ? $time : self::delivered_at( $order ),
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
		// Consent is checked when the event is sent, not only when it was queued (she may have withdrawn since).
		$skip = '';
		if ( 'granted' !== $order->get_meta( '_ym_consent' ) ) {
			$skip = 'no_consent';
		} elseif ( 'delivered' === $kind && ! self::delivered_at( $order ) ) {
			$skip = 'delivery_not_verified';
		} elseif ( 'meta' === $platform && time() - ( 'delivered' === $kind ? self::delivered_at( $order ) : ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ) ) > 7 * DAY_IN_SECONDS ) {
			$skip = 'older_than_7_days'; // Meta refuses events older than 7 days
		}
		if ( $skip ) {
			$order->update_meta_data( $meta_key, wp_json_encode( array( 'at' => time(), 'status' => 'skipped', 'reason' => $skip ) ) );
			$order->save_meta_data();
			YM_Ops::log( 'capi_skipped', array( 'order' => $order_id, 'platform' => $platform, 'kind' => $kind, 'reason' => $skip ) );
			return;
		}
		$body  = is_array( $done ) && ! empty( $done['payload'] ) ? $done['payload'] : self::payload( $order, $platform, $kind ); // a retry resends the same event: same ID, same time
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
