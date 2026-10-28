<?php
/**
 * The visitor's IP address, for rate limits, Turnstile and the order record. Spec: docs/RISK.md §2.
 *
 * A forwarding header (CF-Connecting-IP, X-Forwarded-For) is believed ONLY when the connection itself comes from a
 * configured trusted proxy. Anyone can send those headers, so trusting them by default would let one client rotate
 * its apparent address and slip under every per-connection limit.
 *
 * Configuration (staging/production, from the environment, set after inspecting what the host really sends):
 *   YM_TRUSTED_PROXIES   comma-separated IPs or CIDR ranges of the proxies in front of PHP (e.g. Cloudflare's ranges,
 *                        or the host's own reverse proxy). Empty = no proxy trusted: REMOTE_ADDR is the client.
 *   YM_CLIENT_IP_HEADER  "cf-connecting-ip" or "x-forwarded-for" (default x-forwarded-for).
 */

defined( 'ABSPATH' ) || exit;

final class YM_Net {

	/** Trusted proxy ranges (constant from the environment; option only as a fallback for local tests). */
	public static function trusted() {
		$raw = defined( 'YM_TRUSTED_PROXIES' ) ? (string) YM_TRUSTED_PROXIES : (string) get_option( 'ym_trusted_proxies', '' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	public static function header_name() {
		$h = defined( 'YM_CLIENT_IP_HEADER' ) ? (string) YM_CLIENT_IP_HEADER : (string) get_option( 'ym_client_ip_header', 'x-forwarded-for' );
		return in_array( strtolower( $h ), array( 'cf-connecting-ip', 'x-forwarded-for' ), true ) ? strtolower( $h ) : 'x-forwarded-for';
	}

	/** The client address, or '' when nothing valid is known. $server is injectable for tests. */
	public static function client_ip( $server = null ) {
		$server = null === $server ? $_SERVER : $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );
		if ( ! $remote ) {
			return '';
		}
		$proxies = self::trusted();
		if ( ! $proxies || ! self::in_ranges( $remote, $proxies ) ) {
			return $remote; // a direct connection: forwarding headers are ignored
		}
		if ( 'cf-connecting-ip' === self::header_name() ) {
			$cf = self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
			return $cf ? $cf : $remote;
		}
		// X-Forwarded-For: the right-most address that is not one of our proxies is the client. Left-most entries
		// can be written by the client itself and are never used.
		$chain = array_reverse( array_map( 'trim', explode( ',', (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) );
		foreach ( $chain as $hop ) {
			$ip = self::valid( $hop );
			if ( ! $ip ) {
				return $remote; // malformed chain: fall back to the proxy's own address (stricter, never spoofable)
			}
			if ( ! self::in_ranges( $ip, $proxies ) ) {
				return $ip;
			}
		}
		return $remote;
	}

	private static function valid( $ip ) {
		$ip = trim( (string) $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function in_ranges( $ip, $ranges ) {
		foreach ( $ranges as $r ) {
			if ( self::in_range( $ip, $r ) ) {
				return true;
			}
		}
		return false;
	}

	/** IPv4 or IPv6 address inside an IP or CIDR range. */
	public static function in_range( $ip, $range ) {
		$parts = explode( '/', $range, 2 );
		$a     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b     = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $a ) * 8;
		$bits = max( 0, min( strlen( $a ) * 8, $bits ) );
		$full = intdiv( $bits, 8 );
		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $a[ $full ] & $mask ) === ( $b[ $full ] & $mask );
	}
}
