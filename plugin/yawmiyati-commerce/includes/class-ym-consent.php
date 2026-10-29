<?php
/**
 * Consent withdrawal shared by the five stores. Spec: docs/MEASUREMENT.md §4.
 *
 * The stores share one domain (yawmiyati.shop/eg/, /iq/, …), so the consent cookie (ym_consent) and the consent
 * subject (ym_cs, a random ID) are the same in all of them. Each store has its own database, though, so a withdrawal
 * recorded only in the store where she clicked would leave her orders in the other four eligible for server events.
 *
 * Shared database (YM_SHARED_DB, same server, every store's DB user granted on it) holds two tables:
 *   ym_consent_ledger (subject, withdrawn_at, store)   every withdrawal, from any store
 *   ym_consent_cert   (store, clean_as_of)             "every withdrawal this store recorded before clean_as_of is in
 *                                                       the ledger" — written by that store only
 *
 * Invariant (29 Oct 2026): no server event is sent for an order whose consent subject withdrew in ANY store before
 * the send was decided, even when that store could not write to the shared ledger while the others could still read
 * it (read-only database, failed INSERT, missing grant).
 *
 * How it holds:
 *   1. A withdrawal is written to this store's own table {prefix}ym_consent_pending FIRST (one atomic upsert, so
 *      simultaneous withdrawals can never overwrite each other), then to the shared ledger; the local row is deleted
 *      only when the shared write succeeded, and only if no newer withdrawal arrived meanwhile.
 *   2. certify() flushes this store's pending rows to the ledger and, only when none remain, writes this store's
 *      certificate: clean_as_of = database time just before the pending rows were read under a locking read.
 *      A store whose shared writes fail can never certify, so its certificate goes stale.
 *   3. Sending is two-phase (YM_Tracking::send): the first attempt records the decision time d on the order and
 *      waits; a later attempt sends only when every participating store (YM_CONSENT_STORES, default all five) holds a
 *      certificate newer than d, this store just certified itself (its own shared write works now), and a fresh
 *      read of the ledger shows no withdrawal at or after the order. Decisions older than 10 minutes are renewed.
 *   4. Anything unreadable or uncertified means "unknown": nothing is sent, the event waits and an alert is raised.
 *
 * Restoring a backup merges withdrawals with GREATEST(), so an old backup can never undo a withdrawal.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Consent {

	const MARGIN       = 2;       // seconds subtracted from the certificate time (second-level clock granularity)
	const DECISION_TTL = 600;     // a send decision older than this is renewed (bounds decision-to-send time)
	const CERT_MAX_AGE = 1800;    // launch-check and board: a certificate older than this is "stale"

	/* ---------- tables ---------- */

	private static function shared_db() {
		$db = defined( 'YM_SHARED_DB' ) ? (string) YM_SHARED_DB : '';
		if ( YM_Env::test_hooks() && get_option( 'ym_test_ledger_down' ) ) {
			$db = 'ym_no_such_database'; // local test hook: the shared ledger is unreachable
		}
		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $db ) ? $db : '';
	}

	/** Fully qualified ledger table, or '' when no shared database is configured. */
	public static function table() {
		$db = self::shared_db();
		return $db ? '`' . $db . '`.`ym_consent_ledger`' : '';
	}

	private static function cert_table() {
		$db = self::shared_db();
		return $db ? '`' . $db . '`.`ym_consent_cert`' : '';
	}

	public static function pending_table() {
		global $wpdb;
		return $wpdb->prefix . 'ym_consent_pending';
	}

	public static function configured() {
		return '' !== self::table();
	}

	/** Stores that must certify before any store sends: YM_CONSENT_STORES ("eg,iq"), default every configured country. */
	public static function stores() {
		$list = defined( 'YM_CONSENT_STORES' ) ? (string) YM_CONSENT_STORES : '';
		$out  = $list ? array_map( 'strtoupper', array_filter( array_map( 'trim', explode( ',', $list ) ) ) ) : array_keys( YM_Country::all() );
		return array_values( array_filter( $out, fn( $c ) => (bool) preg_match( '/^[A-Z]{2}$/', $c ) ) );
	}

	public static function install() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p = self::pending_table();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p} (subject char(32) NOT NULL, withdrawn_at int(10) unsigned NOT NULL, PRIMARY KEY (subject)) DEFAULT CHARSET=ascii ENGINE=InnoDB" );
		// Withdrawals queued by the previous version (an option, read-modify-write) move into the table once.
		$old = get_option( 'ym_consent_pending', null );
		if ( is_array( $old ) ) {
			foreach ( $old as $s => $at ) {
				self::pending_add( (string) $s, (int) $at );
			}
			delete_option( 'ym_consent_pending' );
		}
		$t = self::table();
		if ( ! $t ) {
			return false;
		}
		$c = self::cert_table();
		$wpdb->suppress_errors( true );
		$ok  = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$t} (subject char(32) NOT NULL, withdrawn_at int(10) unsigned NOT NULL, store char(2) NOT NULL, PRIMARY KEY (subject)) DEFAULT CHARSET=ascii ENGINE=InnoDB" );
		$ok2 = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$c} (store char(2) NOT NULL, clean_as_of int(10) unsigned NOT NULL, PRIMARY KEY (store)) DEFAULT CHARSET=ascii ENGINE=InnoDB" );
		$wpdb->suppress_errors( false );
		// phpcs:enable
		return false !== $ok && false !== $ok2;
	}

	/* ---------- local queue (atomic; never read-modify-write) ---------- */

	/** Queue a withdrawal. $at null = the database's own clock at the moment of the insert (see certify()). */
	private static function pending_add( $subject, $at = null ) {
		global $wpdb;
		$p   = self::pending_table();
		$val = null === $at ? 'UNIX_TIMESTAMP()' : (string) (int) $at;
		$sql = $wpdb->prepare( "INSERT INTO {$p} (subject, withdrawn_at) VALUES (%s, {$val}) ON DUPLICATE KEY UPDATE withdrawn_at = GREATEST(withdrawn_at, VALUES(withdrawn_at))", $subject ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok  = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $ok && false !== stripos( $wpdb->last_error, "doesn't exist" ) ) {
			self::install();
			$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		}
		return false !== $ok;
	}

	/** Remove a queued withdrawal once the ledger holds it, unless a newer withdrawal for her arrived meanwhile. */
	private static function pending_done( $subject, $at ) {
		global $wpdb;
		$p = self::pending_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p} WHERE subject = %s AND withdrawn_at <= %d", $subject, $at ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Queued withdrawals in this store: subject => time. */
	public static function pending() {
		global $wpdb;
		$p = self::pending_table();
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT subject, withdrawn_at FROM {$p}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( false );
		return wp_list_pluck( (array) $rows, 'withdrawn_at', 'subject' );
	}

	/* ---------- withdrawals ---------- */

	/** Record a withdrawal for all stores. Returns true when the shared ledger holds it; otherwise it stays queued here. */
	public static function record( $subject, $at = null ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $subject ) ) {
			return false;
		}
		// First, and stamped by the database itself: from now on this store cannot certify until the ledger holds it.
		self::pending_add( $subject, null === $at ? null : (int) $at );
		$at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT withdrawn_at FROM ' . self::pending_table() . ' WHERE subject = %s', $subject ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $at ) {
			$at = time(); // the local table could not be written either: still try the shared ledger
		}
		if ( self::write( $subject, $at ) ) {
			self::pending_done( $subject, $at );
			return true;
		}
		if ( self::configured() ) {
			YM_Ops::alert( 'consent_ledger_write_failed', 'A consent withdrawal could not be written to the shared ledger. It is queued in this store, this store stops certifying, and every store holds its server events until the ledger accepts it.' );
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
		return self::shared_write( $sql );
	}

	/** One write to the shared database. Local test hook ym_test_ledger_readonly: the INSERT really fails (read-only transaction) while reads still work. */
	private static function shared_write( $sql ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$ro = YM_Env::test_hooks() && get_option( 'ym_test_ledger_readonly' );
		if ( $ro ) {
			$wpdb->query( 'START TRANSACTION READ ONLY' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( $ro ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( false === $ok && false !== stripos( $wpdb->last_error, "doesn't exist" ) && self::install() ) {
			$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->suppress_errors( false );
		return false !== $ok;
	}

	/**
	 * Flush this store's queued withdrawals and, only if none remain, certify: "every withdrawal recorded here before
	 * clean_as_of is in the ledger". Returns true when the certificate was written now.
	 */
	public static function certify() {
		global $wpdb;
		if ( ! self::configured() ) {
			return false;
		}
		$p = self::pending_table();
		// Database time BEFORE the locking read: a withdrawal still being inserted makes the read wait for it, and any
		// withdrawal stamped earlier than this time is either in the rows read below or already deleted after a
		// successful shared write.
		$now = (int) $wpdb->get_var( 'SELECT UNIX_TIMESTAMP()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT subject, withdrawn_at FROM {$p} LOCK IN SHARE MODE", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$err  = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== $err ) {
			if ( false !== stripos( $err, "doesn't exist" ) ) {
				self::install();
				return false; // next run certifies
			}
			return false;
		}
		$left = 0;
		foreach ( (array) $rows as $r ) {
			if ( self::write( $r['subject'], (int) $r['withdrawn_at'] ) ) {
				self::pending_done( $r['subject'], (int) $r['withdrawn_at'] );
			} else {
				++$left;
			}
		}
		if ( $left ) {
			YM_Ops::alert( 'consent_certify_blocked', $left . ' consent withdrawal(s) cannot reach the shared ledger. Server events in every store are held until they do.' );
			return false;
		}
		$c   = self::cert_table();
		$sql = $wpdb->prepare( "INSERT INTO {$c} (store, clean_as_of) VALUES (%s, %d) ON DUPLICATE KEY UPDATE clean_as_of = GREATEST(clean_as_of, VALUES(clean_as_of))", YM_Country::code(), $now - self::MARGIN ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::shared_write( $sql );
	}

	/** Back-compat for the hourly sweep: flush and certify. Returns how many queued withdrawals remain. */
	public static function flush_pending() {
		self::certify();
		return count( self::pending() );
	}

	/** Certificates of the participating stores: code => clean_as_of (0 = never). Null when unreadable. */
	public static function certificates() {
		global $wpdb;
		$c = self::cert_table();
		if ( ! $c ) {
			return null;
		}
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT store, clean_as_of FROM {$c}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$err  = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		if ( '' !== $err ) {
			if ( false !== stripos( $err, "doesn't exist" ) ) {
				self::install();
				$rows = array();
			} else {
				return null;
			}
		}
		$have = wp_list_pluck( (array) $rows, 'clean_as_of', 'store' );
		$out  = array();
		foreach ( self::stores() as $s ) {
			$out[ $s ] = (int) ( $have[ $s ] ?? 0 );
		}
		return $out;
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
		$local = (int) ( self::pending()[ $subject ] ?? 0 );
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

	/**
	 * May a server event for an order placed at $placed by $subject be sent now, given the send was decided at
	 * $decided? Returns 'withdrawn:<time>', 'clear', or 'wait:<reason>'.
	 */
	public static function clearance( $subject, $placed, $decided ) {
		if ( ! self::configured() ) {
			$w = self::withdrawn_at( $subject );
			return $w && $w >= $placed ? 'withdrawn:' . $w : 'clear'; // single store without a ledger (not production)
		}
		if ( ! self::certify() ) {
			return 'wait:own_store_cannot_certify';
		}
		$certs = self::certificates();
		if ( null === $certs ) {
			return 'wait:consent_ledger_unavailable';
		}
		$behind = array_keys( array_filter( $certs, fn( $at ) => $at < $decided ) );
		if ( $behind ) {
			return 'wait:certificate_pending_' . strtolower( implode( '_', $behind ) );
		}
		$w = self::withdrawn_at( $subject ); // read after the certificates: everything they cover is visible now
		if ( null === $w ) {
			return 'wait:consent_ledger_unavailable';
		}
		return $w && $w >= $placed ? 'withdrawn:' . $w : 'clear';
	}

	/* ---------- backup and restore of the shared tables ---------- */

	public static function export() {
		global $wpdb;
		$t = self::table();
		$c = self::cert_table();
		if ( ! $t ) {
			return null;
		}
		$ledger = $wpdb->get_results( "SELECT subject, withdrawn_at, store FROM {$t} ORDER BY subject", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$certs  = $wpdb->get_results( "SELECT store, clean_as_of FROM {$c} ORDER BY store", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $ledger || '' !== $wpdb->last_error ) {
			return null;
		}
		return array( 'format' => 'ym-consent-1', 'exported_at' => time(), 'ledger' => $ledger, 'certificates' => (array) $certs );
	}

	/**
	 * Merge a backup into the shared ledger. Withdrawals merge with GREATEST, so a restore never removes or shortens
	 * one. Certificates are NOT restored: a restored store must certify again from its own queue.
	 */
	public static function import( array $data ) {
		if ( 'ym-consent-1' !== ( $data['format'] ?? '' ) ) {
			return false;
		}
		$n = 0;
		foreach ( (array) ( $data['ledger'] ?? array() ) as $r ) {
			if ( preg_match( '/^[a-f0-9]{32}$/', (string) ( $r['subject'] ?? '' ) ) && self::write_as( $r['subject'], (int) $r['withdrawn_at'], (string) $r['store'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	private static function write_as( $subject, $at, $store ) {
		global $wpdb;
		$t = self::table();
		if ( ! $t || ! preg_match( '/^[A-Z]{2}$/', $store ) ) {
			return false;
		}
		$sql = $wpdb->prepare( "INSERT INTO {$t} (subject, withdrawn_at, store) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE store = IF(VALUES(withdrawn_at) >= withdrawn_at, VALUES(store), store), withdrawn_at = GREATEST(withdrawn_at, VALUES(withdrawn_at))", $subject, $at, $store ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::shared_write( $sql );
	}

	/** For `wp ym launch-check`: the ledger is reachable and every participating store certified recently. */
	public static function launch_check() {
		$ok    = self::configured() && null !== self::withdrawn_at( str_repeat( '0', 32 ) );
		$certs = $ok ? self::certificates() : null;
		$stale = array();
		foreach ( (array) $certs as $s => $at ) {
			if ( time() - $at > self::CERT_MAX_AGE ) {
				$stale[] = $s;
			}
		}
		return array( $ok && is_array( $certs ) && ! $stale, 'YM_SHARED_DB set on every store (same database, DB users granted); YM_CONSENT_STORES lists the installed stores; every listed store certified within 30 min' . ( $stale ? ' (stale: ' . implode( ', ', $stale ) . ')' : '' ) );
	}
}
