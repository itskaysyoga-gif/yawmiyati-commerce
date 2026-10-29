<?php
/**
 * Operations board: one screen (WooCommerce > Yawmiyati ops) and one command (wp ym ops) with everything that
 * needs a person. Spec: docs/OPERATIONS.md.
 *
 * Access: the "Yawmiyati operator" role (created by wp ym setup) can see the board, release or cancel held orders,
 * record outcomes and moderate reviews; it cannot change settings, plugins, themes, users or payment methods.
 * Secrets never appear here.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Board {

	const CAP = 'ym_ops';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		// WooCommerce sends users without edit_posts to My Account; operators need the admin screens.
		add_filter( 'woocommerce_prevent_admin_access', fn( $prevent ) => $prevent && ! current_user_can( self::CAP ) );
	}

	/** Operator role: what running the shop day to day needs, nothing that changes the system. */
	public static function install_role() {
		$caps = array( 'read' => true, self::CAP => true, 'edit_shop_orders' => true, 'read_shop_order' => true, 'edit_others_shop_orders' => true, 'edit_published_shop_orders' => true, 'moderate_comments' => true, 'edit_products' => false, 'manage_woocommerce' => false, 'view_woocommerce_reports' => true );
		remove_role( 'ym_operator' );
		add_role( 'ym_operator', 'Yawmiyati operator', $caps );
		foreach ( array( 'administrator', 'shop_manager' ) as $r ) {
			$role = get_role( $r );
			if ( $role ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Yawmiyati ops', 'Yawmiyati ops', self::CAP, 'ym-ops', array( __CLASS__, 'page' ) );
		if ( ! current_user_can( 'manage_woocommerce' ) && current_user_can( self::CAP ) ) {
			add_menu_page( 'Yawmiyati ops', 'Yawmiyati ops', self::CAP, 'ym-ops', array( __CLASS__, 'page' ), 'dashicons-clipboard', 3 );
		}
	}

	/** Everything that needs attention, as data. */
	public static function data() {
		$now = time();
		$d   = array( 'country' => YM_Country::code(), 'generated' => gmdate( 'c' ) );

		$d['review_queue'] = YM_Risk::queue();

		// Supplier acknowledgement: pending past 20 min, unconfirmed, not found, possible double.
		$sup = array();
		foreach ( wc_get_orders( array( 'limit' => 100, 'return' => 'ids', 'status' => array( 'processing' ), 'date_created' => '>' . ( $now - 14 * DAY_IN_SECONDS ) ) ) as $id ) {
			$o = wc_get_order( $id );
			$s = YM_Supplier::status( $o );
			$age = $now - $o->get_date_created()->getTimestamp();
			if ( in_array( $s, array( 'unconfirmed', 'not_found', 'conflict' ), true ) || ( 'pending' === $s && $age > 20 * MINUTE_IN_SECONDS ) ) {
				$sup[] = array( 'order' => $id, 'state' => $s, 'age_hours' => round( $age / HOUR_IN_SECONDS, 1 ) );
			}
		}
		$d['supplier_unacknowledged'] = $sup;

		// Products: stock checks due, and what blocks each product from sale.
		$stock = array();
		$gate  = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft' ) ) ) as $p ) {
			if ( $p->get_meta( '_ym_retired' ) ) {
				continue;
			}
			$at = (int) $p->get_meta( '_ym_stock_checked_at' );
			if ( ! $at || $now - $at > YM_Catalog::stock_max_age() - 12 * HOUR_IN_SECONDS ) {
				$stock[] = array( 'sku' => $p->get_sku(), 'state' => ! $at ? 'never checked' : ( $now - $at > YM_Catalog::stock_max_age() ? 'expired' : 'due within 12 h' ) );
			}
			$b = YM_Catalog::blockers( $p );
			unset( $b['stock_unverified'] );
			if ( $b ) {
				$gate[] = array( 'sku' => $p->get_sku(), 'missing' => implode( ', ', array_keys( $b ) ) );
			}
		}
		$d['stock_checks'] = $stock;
		$d['product_approvals'] = $gate;

		// Shipments without an outcome after 7 days; returns without a refund after 5 days.
		$stuck   = array();
		$returns = array();
		foreach ( wc_get_orders( array( 'limit' => 200, 'return' => 'ids', 'date_created' => '>' . ( $now - 60 * DAY_IN_SECONDS ) ) ) as $id ) {
			$o  = wc_get_order( $id );
			$ev = YM_Lifecycle::events( $o );
			$st = array_column( $ev, 'at', 'e' );
			if ( isset( $st['shipped'] ) && ! array_intersect( array_keys( $st ), array( 'delivered', 'refused', 'cancelled', 'returned' ) ) && $now - $st['shipped'] > 7 * DAY_IN_SECONDS ) {
				$stuck[] = array( 'order' => $id, 'shipped_days_ago' => (int) floor( ( $now - $st['shipped'] ) / DAY_IN_SECONDS ) );
			}
			if ( isset( $st['returned'] ) && ! array_intersect( array_keys( $st ), array( 'refund_partial', 'refund_full' ) ) && $now - $st['returned'] > 5 * DAY_IN_SECONDS ) {
				$returns[] = array( 'order' => $id, 'returned_days_ago' => (int) floor( ( $now - $st['returned'] ) / DAY_IN_SECONDS ) );
			}
		}
		$d['stuck_shipments']    = $stuck;
		$d['unresolved_returns'] = $returns;

		// Measurement failures (last 7 days) and overdue scheduled jobs.
		$fails = array();
		foreach ( wc_get_orders( array( 'limit' => 200, 'return' => 'ids', 'date_created' => '>' . ( $now - 7 * DAY_IN_SECONDS ) ) ) as $id ) {
			$o = wc_get_order( $id );
			foreach ( array( 'meta_purchase', 'snap_purchase', 'meta_delivered' ) as $k ) {
				$r = json_decode( (string) $o->get_meta( '_ym_capi_' . $k ), true );
				if ( is_array( $r ) && 'error' === ( $r['status'] ?? '' ) ) {
					$fails[] = array( 'order' => $id, 'event' => $k, 'code' => $r['code'] ?? 0 );
				}
			}
		}
		$d['tracking_failures'] = $fails;
		$health                 = YM_Ops::health()->get_data();
		$d['overdue_jobs']      = array_keys( array_filter( $health['jobs'], fn( $j ) => $j['stale'] ) );
		$cl                     = YM_Consent::launch_check();
		$d['consent_ledger']    = array( 'ok' => $cl[0], 'certificates' => YM_Consent::certificates(), 'queued_here' => count( YM_Consent::pending() ) );

		// Contribution by product: mature orders (older than the maturity window) of the last 60 days.
		$rep = YM_Report::build( $now - 60 * DAY_IN_SECONDS, $now - YM_Report::maturity_days() * DAY_IN_SECONDS );
		$d['contribution'] = array( 'basis' => $rep['rates_mature']['basis'], 'money' => $rep['money'], 'unknown' => $rep['unknown'], 'products' => array_map( fn( $p ) => array( 'name' => $p['name'], 'orders' => $p['orders'], 'delivered' => $p['orders_delivered'], 'contribution_before_ads' => $p['contribution_before_ads'] ), $rep['products'] ?? array() ) );
		$d['markets'] = YM_Markets::matrix();
		$d['gates']   = YM_Gates::all();

		// The short list for Rashid: things only he can do, or that cost money if left.
		$act = array();
		if ( $d['review_queue'] ) {
			$act[] = count( $d['review_queue'] ) . ' held order(s) to check and release or cancel (wp ym review list).';
		}
		if ( array_filter( $sup, fn( $s ) => 'conflict' === $s['state'] ) ) {
			$act[] = 'Possible double order in Taager: cancel one copy today.';
		}
		if ( array_filter( $sup, fn( $s ) => in_array( $s['state'], array( 'unconfirmed', 'not_found' ), true ) ) ) {
			$act[] = 'Look up unconfirmed orders in Taager and record found / not found.';
		}
		$expired = array_filter( $stock, fn( $s ) => 'due within 12 h' !== $s['state'] );
		if ( $expired && YM_Env::enforced() ) {
			$act[] = count( $expired ) . ' offer(s) not selling until stock is confirmed in Taager (wp ym check <sku> --stock).';
		}
		if ( $stuck ) {
			$act[] = count( $stuck ) . ' shipment(s) with no outcome after 7 days: ask Taager.';
		}
		if ( $returns ) {
			$act[] = count( $returns ) . ' return(s) without a refund after 5 days.';
		}
		if ( $fails ) {
			$act[] = 'Server events failing: check tokens and Meta/Snap event tools before spending more.';
		}
		if ( YM_Consent::configured() && ! $cl[0] ) {
			$act[] = 'Server events are held in every store: a store has not certified its consent withdrawals (wp ym consent status).';
		}
		$pause = array_keys( array_filter( $d['gates'], fn( $g ) => 'pause' === $g['decision'] ) );
		if ( $pause ) {
			$act[] = 'Kill-gate: pause ads for ' . implode( ', ', $pause ) . ' (wp ym gates).';
		}
		if ( $d['overdue_jobs'] ) {
			$act[] = 'Scheduled jobs overdue (' . implode( ', ', $d['overdue_jobs'] ) . '): check the server cron.';
		}
		if ( YM_Markets::is_on( YM_Country::code(), 'ads' ) && null === $rep['money']['ad_spend'] ) {
			$act[] = 'Record ad spend (wp ym money spend …) so contribution can be computed.';
		}
		$d['actions_for_rashid'] = $act;
		return $d;
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		$d = self::data();
		echo '<div class="wrap"><h1>Yawmiyati ops · ' . esc_html( $d['country'] ) . '</h1>';
		echo '<h2>Actions for Rashid</h2>' . ( $d['actions_for_rashid'] ? '<ol><li>' . implode( '</li><li>', array_map( 'esc_html', $d['actions_for_rashid'] ) ) . '</li></ol>' : '<p>Nothing needs you right now.</p>' );
		$sections = array(
			'review_queue'            => 'Orders held for review (not sent to the supplier)',
			'supplier_unacknowledged' => 'Orders awaiting supplier acknowledgement',
			'stock_checks'            => 'Stock checks due or expired',
			'product_approvals'       => 'Products not ready to sell (missing checks or media)',
			'stuck_shipments'         => 'Shipments with no outcome after 7 days',
			'unresolved_returns'      => 'Returns without a refund',
			'tracking_failures'       => 'Server measurement failures (7 days)',
		);
		foreach ( $sections as $key => $title ) {
			echo '<h2>' . esc_html( $title ) . ' (' . count( $d[ $key ] ) . ')</h2>';
			if ( ! $d[ $key ] ) {
				echo '<p>None.</p>';
				continue;
			}
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array_keys( $d[ $key ][0] ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $d[ $key ] as $row ) {
				echo '<tr>';
				foreach ( $row as $k => $v ) {
					$cell = 'order' === $k && wc_get_order( (int) $v ) ? '<a href="' . esc_url( wc_get_order( (int) $v )->get_edit_order_url() ) . '">' . esc_html( $v ) . '</a>' : esc_html( (string) $v );
					echo '<td>' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>Contribution by product (' . esc_html( $d['contribution']['basis'] ) . ')</h2><pre>' . esc_html( wp_json_encode( $d['contribution'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		echo '<h2>Markets</h2><pre>' . esc_html( wp_json_encode( $d['markets'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		echo '<p>Overdue jobs: ' . esc_html( $d['overdue_jobs'] ? implode( ', ', $d['overdue_jobs'] ) : 'none' ) . '</p></div>';
	}
}
