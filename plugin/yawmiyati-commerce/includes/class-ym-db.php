<?php
/**
 * Own tables and atomic counters.
 *
 *   {prefix}ym_rate   rate-limit counters: one row per (hashed key, minute), incremented in one SQL statement, so
 *                     simultaneous requests can never lose a count (a read-modify-write transient could).
 *
 * The raw key (session ID, IP) is never stored: rows hold a salted SHA-256 prefix. Rows older than two hours are
 * pruned by the hourly supplier sweep.
 */

defined( 'ABSPATH' ) || exit;

final class YM_DB {

	const VERSION = '1';
	const BUCKET  = 60;

	public static function init() {
		if ( get_option( 'ym_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function rate_table() {
		global $wpdb;
		return $wpdb->prefix . 'ym_rate';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::rate_table();
		dbDelta( "CREATE TABLE {$t} (\n rk char(40) NOT NULL,\n bucket int(10) unsigned NOT NULL,\n n int(10) unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY  (rk,bucket)\n) {$wpdb->get_charset_collate()};" );
		update_option( 'ym_db_version', self::VERSION, true );
	}

	/**
	 * Count one attempt for ($kind, $id) and return this attempt's position in the sliding $window (1 = first).
	 * The current minute's counter is incremented and read back in one statement (LAST_INSERT_ID(n + 1)), so N
	 * simultaneous attempts get positions 1..N, never the same number twice.
	 */
	public static function hit( $kind, $id, $window = 600 ) {
		global $wpdb;
		if ( '' === (string) $id ) {
			return 0;
		}
		$t   = self::rate_table();
		$rk  = substr( hash( 'sha256', $kind . '|' . $id . '|' . wp_salt( 'auth' ) ), 0, 40 );
		$now = intdiv( time(), self::BUCKET );
		$old = $now - intdiv( $window, self::BUCKET );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "INSERT INTO {$t} (rk, bucket, n) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(n + 1)", $rk, $now );
		for ( $try = 0; $try < 4; $try++ ) {
			$done = $wpdb->query( $sql );
			if ( false !== $done ) {
				break;
			}
			if ( false !== stripos( $wpdb->last_error, "doesn't exist" ) ) {
				self::install(); // first request after an update: create the table, then count this attempt
			} else {
				usleep( 20000 * ( $try + 1 ) ); // deadlock between simultaneous first inserts: InnoDB rolled one back; retry
			}
		}
		if ( false === $done ) {
			YM_Ops::log( 'rate_counter_failed', array( 'error' => $wpdb->last_error ), 'warning' );
			return 0; // never block a customer because the counter failed
		}
		$mine    = 1 === (int) $done ? 1 : (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		$earlier = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n), 0) FROM {$t} WHERE rk = %s AND bucket > %d AND bucket < %d", $rk, $old, $now ) );
		// phpcs:enable
		return $earlier + $mine;
	}

	/** Drop counter rows older than two hours (hourly sweep; never on a customer's request, so no lock waits there). */
	public static function prune() {
		global $wpdb;
		$t = self::rate_table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE bucket < %d", intdiv( time(), self::BUCKET ) - 120 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
