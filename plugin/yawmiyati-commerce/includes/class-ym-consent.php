<?php
/**
 * Consent withdrawal shared by the five stores. Spec: docs/MEASUREMENT.md §4.
 *
 * The stores share one domain (yawmiyati.shop/eg/, /iq/, …), so the consent cookie (ym_consent) and the consent
 * subject (ym_cs, a random ID) are the same in all of them. Each store has its own database, though, so a withdrawal
 * recorded only in the store where she clicked would leave her orders in the other four eligible for server events.
 *
 * Shared ledger: one table in a database every store can read and write (YM_SHARED_DB, same server and DB user):
 *   ym_consent_ledger (subject, withdrawn_at, store)
 * A withdrawal in any store writes it there. Every store checks it immediately before sending any server event: an
 * order whose consent subject withdrew at or after the order was placed is never sent, whichever store it belongs
 * to. A later order placed after she accepted again is unaffected.
 *
 * Failure handling: if the ledger cannot be written, the withdrawal is queued in that store and retried by the
 * supplier sweep; if it cannot be read, server events wait (fail closed) and retry later. Nothing is sent on an
 * unknown answer.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Consent {

	/** Fully qualified ledger table, or '' when no shared database is configured. */
	public static function table() {
		$db = defined( 'YM_SHARED_DB' ) ? (string) YM_SHARED_DB : '';
		if ( YM_Env::test_hooks() && get_option( 'ym_test_ledger_down' ) ) {
			$db = 'ym_no_such_database'; // local test hook: the shared ledger is unreachable
		}
		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $db ) ? '`' . $db . '`.`ym_consent_ledger`' : '';
	}

	public static function configured() {
		return '' !== self::table();
	}

	public static function install() {
		global $wpdb;
		$t = self::table();
		if ( ! $t ) {
			return false;
		}
		$wpdb->suppress_errors( true );
		$ok = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$t} (subject char(32) NOT NULL, withdrawn_at int(10) unsigned NOT NULL, store char(2) NOT NULL, PRIMARY KEY (subject)) DEFAULT CHARSET=ascii" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( false );
		return false !== $ok;
	}

	/** Record a withdrawal for all stores. Returns true when the shared ledger holds it; otherwise it is queued. */
	public static function record( $subject, $at = null ) {
		$at = (int) ( $at ?? time() );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $subject ) ) {
			return false;
		}
		if ( self::write( $subject, $at ) ) {
			return true;
		}
		$pending             = (array) get_option( 'ym_consent_pending', array() );
		$pending[ $subject ] = max( $at, (int) ( $pending[ $subject ] ?? 0 ) );
		update_option( 'ym_consent_pending', $pending, false );
		if ( self::configured() ) {
			YM_Ops::alert( 'consent_ledger_write_failed', 'A consent withdrawal could not be written to the shared ledger. It is queued here and retried hourly; until then the other stores cannot see it.' );
		}
		return false;
	}

	private static function write( $subject, $at ) {
		global $wpdb;
		$t = self::table();
		if ( ! $t ) {
			return false;
		}
		$sql = $wpdb->prepare( "INSERT INTO {$t} (subject, withdrawn_at, store) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE store = IF(VALUES(withdrawn_at) >= withdrawn_at, VALUES(store), store), withdrawn_at = GREATEST(withdrawn_at, VALUES(withdrawn_at))", $subject, $at, YM_Country::code() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( true );
		$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $ok && self::install() ) {
			$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->suppress_errors( false );
		return false !== $ok;
	}

	/** Retry withdrawals that could not reach the shared ledger (hourly sweep). They stay in force locally meanwhile. */
	public static function flush_pending() {
		$pending = (array) get_option( 'ym_consent_pending', array() );
		if ( ! $pending || ! self::configured() ) {
			return 0;
		}
		$done = 0;
		foreach ( $pending as $subject => $at ) {
			if ( self::write( $subject, (int) $at ) ) {
				unset( $pending[ $subject ] );
				++$done;
			}
		}
		update_option( 'ym_consent_pending', $pending, false );
		return $done;
	}

	/**
	 * When this subject last withdrew, across all stores: Unix time, 0 when never, null when the answer is unknown
	 * (ledger configured but unreadable). Without a shared ledger only this store's own record is known.
	 */
	public static function withdrawn_at( $subject ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $subject ) ) {
			return 0;
		}
		$local = (int) ( ( (array) get_option( 'ym_consent_pending', array() ) )[ $subject ] ?? 0 );
		$t     = self::table();
		if ( ! $t ) {
			return $local;
		}
		$wpdb->suppress_errors( true );
		$v   = $wpdb->get_var( $wpdb->prepare( "SELECT withdrawn_at FROM {$t} WHERE subject = %s", $subject ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$err = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		if ( '' !== $err ) {
			if ( false !== stripos( $err, "doesn't exist" ) && self::install() ) {
				return $local; // first use: the ledger was empty
			}
			return null;
		}
		return max( $local, (int) $v );
	}

	/** For `wp ym launch-check`: the ledger is configured and reachable from this store. */
	public static function launch_check() {
		return array( self::configured() && null !== self::withdrawn_at( str_repeat( '0', 32 ) ), 'YM_SHARED_DB set in wp-config (from the environment) on every store, same database, DB user granted on it' );
	}
}
