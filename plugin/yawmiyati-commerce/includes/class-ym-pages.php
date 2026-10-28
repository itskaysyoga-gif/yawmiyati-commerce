<?php
/**
 * Support and policy pages, built from content/<cc>/<slug>.html by `wp ym setup`.
 *
 * Pages reuse the same text the shop shows (shortcodes below), so a fee or policy line is written once.
 *   [ym_copy key="payment"]   a line from copy/<cc>.json
 *   [ym_fee_table]            delivery fees and times from YM_Country
 *   [ym_pending]...[/ym_pending]  a fact not yet confirmed with the supplier. Shown highlighted; `wp ym launch-check`
 *                             fails while any published page still contains one.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Pages {

	const PAGES = array(
		'delivery' => 'nav_delivery',
		'returns'  => 'nav_returns',
		'faq'      => 'nav_faq',
		'contact'  => 'nav_contact',
		'privacy'  => 'nav_privacy',
	);

	public static function init() {
		add_shortcode( 'ym_copy', fn( $a ) => esc_html( YM_Country::copy( (string) ( $a['key'] ?? '' ) ) ) );
		add_shortcode( 'ym_fee_table', array( __CLASS__, 'fee_table' ) );
		add_shortcode( 'ym_pending', fn( $a, $c = '' ) => '<span class="ym-pending">' . esc_html( do_shortcode( $c ) ) . '</span>' );
		add_shortcode( 'ym_rule', fn( $a ) => esc_html( (string) ( YM_Country::rules()[ (string) ( $a['key'] ?? '' ) ] ?? '' ) ) );
		add_shortcode( 'ym_social', fn() => YM_Social::links( 'page' ) );
	}

	public static function fee_table() {
		ob_start();
		YM_Offers::render_fee_table();
		return ob_get_clean();
	}

	/** Create or update the pages for this country. Returns [slug => page id]. */
	public static function install( $cc ) {
		$ids = array();
		foreach ( self::PAGES as $slug => $title_key ) {
			// Country pages first; Saudi, UAE and Oman share content/gulf/ (country facts come from shortcodes).
			$file = YM_DIR . '/content/' . strtolower( $cc ) . '/' . $slug . '.html';
			if ( ! file_exists( $file ) ) {
				$file = YM_DIR . '/content/' . YM_Country::rules( $cc )['voice'] . '/' . $slug . '.html';
			}
			if ( ! file_exists( $file ) ) {
				continue;
			}
			$data = array(
				'post_title'   => YM_Country::copy( $title_key, $cc ),
				'post_name'    => $slug,
				'post_content' => '<!-- wp:html -->' . trim( (string) file_get_contents( $file ) ) . '<!-- /wp:html -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			);
			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				$data['ID'] = $existing->ID;
				$ids[ $slug ] = wp_update_post( $data );
			} else {
				$ids[ $slug ] = wp_insert_post( $data );
			}
		}
		if ( ! empty( $ids['privacy'] ) ) {
			update_option( 'wp_page_for_privacy_policy', $ids['privacy'] );
		}
		return $ids;
	}

	/** Published pages that still contain an unconfirmed fact. */
	public static function pending() {
		$out = array();
		foreach ( array_keys( self::PAGES ) as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p && 'publish' === $p->post_status && preg_match_all( '/\[ym_pending\](.*?)\[\/ym_pending\]/su', $p->post_content, $m ) ) {
				foreach ( $m[1] as $text ) {
					$out[] = array( 'page' => $slug, 'text' => $text );
				}
			}
		}
		return $out;
	}
}
