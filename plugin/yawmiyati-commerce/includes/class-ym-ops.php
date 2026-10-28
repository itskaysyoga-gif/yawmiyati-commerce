<?php
/**
 * Scheduled operations jobs, logging, alerts and the submitted-to-delivered funnel.
 *
 * Jobs run on Action Scheduler (WooCommerce's queue). On hosting, WP-Cron must be driven by a real server cron
 * every 5 minutes (see docs/OPERATIONS.md); /wp-json/ym/v1/health reports whether each job ran on time so an
 * external uptime monitor can alert when the queue stops.
 *
 *   ym_job_supplier_sweep  hourly   re-check orders whose Taager hand-off is pending or unconfirmed
 *   ym_job_stock_guard     daily    list offers whose stock check expired or expires within 12 h (they stop selling)
 *   ym_job_daily_digest    daily    yesterday's orders, holds, unconfirmed hand-offs, stuck orders
 *   ym_job_weekly_report   Monday   funnel and contribution per product for the last 7 days
 *
 * Alerts: email to the site admin and, when YM_ALERT_WEBHOOK is defined (from the environment), a JSON POST
 * {"text": "..."} to that URL. The same alert key is sent at most once an hour. Logs: WooCommerce > Status > Logs,
 * source "yawmiyati".
 *
 * Supplier-dependent stages (confirmed, shipped, delivered, refused) come from YM_Lifecycle: from Taager's status sync
 * once it is verified and mapped, or from a person recording the outcome with evidence (wp ym outcome). Until then
 * status-only evidence is reported as unverified.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Ops {

	const JOBS = array(
		'ym_job_supplier_sweep' => array( '7 * * * *', HOUR_IN_SECONDS ),
		'ym_job_stock_guard'    => array( '0 6 * * *', DAY_IN_SECONDS ),
		'ym_job_daily_digest'   => array( '30 8 * * *', DAY_IN_SECONDS ),
		'ym_job_weekly_report'  => array( '0 9 * * 1', WEEK_IN_SECONDS ),
	);

	public static function init() {
		foreach ( array_keys( self::JOBS ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'run_' . substr( $hook, 7 ) ) );
		}
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function ensure_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'ym_jobs_ok' ) ) {
			return;
		}
		foreach ( self::JOBS as $hook => $job ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'yawmiyati' ) ) {
				as_schedule_cron_action( time(), $job[0], $hook, array(), 'yawmiyati' );
			}
		}
		set_transient( 'ym_jobs_ok', 1, HOUR_IN_SECONDS );
	}

	/* ---------- logging and alerts ---------- */

	public static function log( $event, $context = array(), $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $event . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ), array( 'source' => 'yawmiyati' ) );
		}
	}

	public static function alert( $key, $text, $order = null ) {
		self::log( 'alert:' . $key, array( 'text' => $text, 'order' => $order instanceof WC_Order ? $order->get_id() : null ), 'warning' );
		$dedupe = 'ym_alert_' . md5( $key . ( $order instanceof WC_Order ? $order->get_id() : '' ) );
		if ( get_transient( $dedupe ) ) {
			return;
		}
		set_transient( $dedupe, 1, HOUR_IN_SECONDS );
		$subject = '[Yawmiyati ' . YM_Country::code() . '] ' . $key;
		$body    = $text . ( $order instanceof WC_Order ? "\n" . $order->get_edit_order_url() : '' );
		wp_mail( get_option( 'admin_email' ), $subject, $body );
		if ( defined( 'YM_ALERT_WEBHOOK' ) && YM_ALERT_WEBHOOK ) {
			wp_remote_post( YM_ALERT_WEBHOOK, array( 'timeout' => 5, 'blocking' => false, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'text' => $subject . ': ' . $text ) ) ) );
		}
	}

	private static function ran( $job, $summary ) {
		$runs         = (array) get_option( 'ym_ops_runs', array() );
		$runs[ $job ] = array( 'at' => time(), 'summary' => $summary );
		update_option( 'ym_ops_runs', $runs, false );
		self::log( 'job:' . $job, $summary );
	}

	/* ---------- jobs ---------- */

	public static function run_supplier_sweep() {
		// Orders whose hand-off is pending or unconfirmed. Looked up by meta directly: wc_get_orders() would ignore a
		// meta_query on the classic order storage and return every processing order.
		$checked = 0;
		foreach ( self::sweep_candidates() as $id ) {
			YM_Supplier::check( $id, count( YM_Supplier::RECHECKS ) ); // a sweep never schedules more re-checks
			++$checked;
		}
		$consent = YM_Consent::flush_pending();
		$pruned  = YM_DB::prune();
		self::ran( 'supplier_sweep', array( 'checked' => $checked, 'consent_withdrawals_flushed' => $consent, 'rate_rows_pruned' => $pruned ) );
	}

	/** Processing orders of the last 3 days, older than 20 min, whose hand-off is pending or unconfirmed (max 100). */
	public static function sweep_candidates() {
		$out = array();
		foreach ( array( 'pending', 'unconfirmed' ) as $st ) {
			foreach ( YM_Orders::ids_by_meta( '_ym_supplier_status', $st, time() - 3 * DAY_IN_SECONDS, 500 ) as $id ) {
				$o = wc_get_order( $id );
				if ( $o && 'processing' === $o->get_status() && time() - $o->get_date_created()->getTimestamp() > 20 * MINUTE_IN_SECONDS ) {
					$out[ $id ] = $id;
				}
			}
		}
		krsort( $out );
		return array_slice( array_values( $out ), 0, 100 );
	}

	public static function run_stock_guard() {
		$expired  = array();
		$expiring = array();
		foreach ( wc_get_products( array( 'status' => array( 'publish', 'draft' ), 'limit' => -1 ) ) as $p ) {
			$at = (int) $p->get_meta( '_ym_stock_checked_at' );
			if ( ! $at || time() - $at > YM_Catalog::stock_max_age() ) {
				$expired[] = $p->get_sku();
			} elseif ( time() - $at > YM_Catalog::stock_max_age() - 12 * HOUR_IN_SECONDS ) {
				$expiring[] = $p->get_sku();
			}
		}
		$summary = array( 'expired' => $expired, 'expiring' => $expiring, 'enforced' => YM_Env::enforced() );
		if ( YM_Env::enforced() && ( $expired || $expiring ) ) {
			self::alert( 'stock_check_due', 'Stock check expired (not selling): ' . ( implode( ', ', $expired ) ?: 'none' ) . '. Expiring within 12 h: ' . ( implode( ', ', $expiring ) ?: 'none' ) . '. Confirm in Taager, then: wp ym check <sku> --stock' );
		}
		self::ran( 'stock_guard', $summary );
	}

	public static function run_daily_digest() {
		$since = strtotime( 'yesterday', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$ids   = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'date_created' => $since . '...' . ( $since + DAY_IN_SECONDS ) ) );
		$by    = array();
		$sup   = array();
		$holds = 0;
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			$by[ $o->get_status() ] = ( $by[ $o->get_status() ] ?? 0 ) + 1;
			$s = YM_Supplier::status( $o );
			$sup[ $s ] = ( $sup[ $s ] ?? 0 ) + 1;
			$holds    += YM_Risk::is_held( $o ) && 'on-hold' === $o->get_status() ? 1 : 0;
		}
		$stuck = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array( 'processing' ), 'date_modified' => '<' . ( time() - 3 * DAY_IN_SECONDS ) ) );
		$summary = array( 'orders' => count( $ids ), 'by_status' => $by, 'supplier' => $sup, 'review_holds' => $holds, 'stuck_over_72h' => count( $stuck ) );
		$issues  = $holds + ( $sup['unconfirmed'] ?? 0 ) + ( $sup['not_found'] ?? 0 ) + ( $sup['conflict'] ?? 0 ) + count( $stuck );
		wp_mail( get_option( 'admin_email' ), '[Yawmiyati ' . YM_Country::code() . '] daily digest', wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		if ( $issues ) {
			self::alert( 'daily_attention', $issues . ' order(s) need attention: ' . wp_json_encode( $summary ) );
		}
		self::ran( 'daily_digest', $summary );
	}

	public static function run_weekly_report() {
		// Last full week of orders, plus the mature cohort of the four weeks before it (delivery takes days).
		$report = array(
			'last_7_days'      => YM_Report::build( time() - 7 * DAY_IN_SECONDS, time() + 1 ),
			'mature_cohort_28' => YM_Report::build( time() - 35 * DAY_IN_SECONDS, time() - 7 * DAY_IN_SECONDS ),
		);
		update_option( 'ym_weekly_report', $report, false );
		wp_mail( get_option( 'admin_email' ), '[Yawmiyati ' . YM_Country::code() . '] weekly product report', wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		self::ran( 'weekly_report', array( 'orders' => $report['last_7_days']['counts']['orders'] ) );
	}

	/* ---------- report ---------- */

	/** Kept for callers of the old API: the stage map now lives in YM_Lifecycle. */
	public static function stage_map() {
		return YM_Lifecycle::stage_map();
	}

	/** Report for the last $days days (YM_Report). Unique orders, stage history, mature cohorts, unknowns kept unknown. */
	public static function funnel( $days ) {
		return YM_Report::build( time() - (int) $days * DAY_IN_SECONDS, time() + 1 );
	}

	/* ---------- health ---------- */

	public static function routes() {
		register_rest_route(
			'ym/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'health' ),
			)
		);
	}

	public static function health() {
		$runs = (array) get_option( 'ym_ops_runs', array() );
		$jobs = array();
		$ok   = true;
		foreach ( self::JOBS as $hook => $job ) {
			$name  = substr( $hook, 7 );
			$at    = (int) ( $runs[ $name ]['at'] ?? 0 );
			$stale = ! $at || time() - $at > 2 * $job[1];
			$ok    = $ok && ! $stale;
			$jobs[ $name ] = array( 'last_run_minutes_ago' => $at ? (int) floor( ( time() - $at ) / 60 ) : null, 'stale' => $stale );
		}
		return new WP_REST_Response( array( 'ok' => $ok, 'country' => YM_Country::code(), 'version' => YM_VERSION, 'jobs' => $jobs ), $ok ? 200 : 503 );
	}
}
