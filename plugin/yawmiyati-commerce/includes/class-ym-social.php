<?php
/**
 * Yawmiyati's social accounts: one list used by the footer, the contact pages and the Organization structured data.
 * Icons are inline SVG (no icon font, no external request). Each link has an accessible name.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Social {

	const ACCOUNTS = array(
		'facebook'  => array( 'https://www.facebook.com/yawmiyatimag', 'فيسبوك يومياتي', '<path d="M14 8.5V7c0-.7.3-1 1-1h1.5V3H14c-2.5 0-3.5 1.6-3.5 3.8v1.7H8.5v3H10.5V21h3.5v-9.5h2.2l.4-3z" fill="currentColor"/>' ),
		'instagram' => array( 'https://www.instagram.com/yawmiyatimag', 'إنستغرام يومياتي', '<rect x="3.5" y="3.5" width="17" height="17" rx="5" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3.9" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor"/>' ),
	);

	public static function urls() {
		return array_values( array_map( fn( $a ) => $a[0], self::ACCOUNTS ) );
	}

	/** Icon links. $context: footer | page. */
	public static function links( $context = 'footer' ) {
		$out = '<ul class="ym-social ym-social--' . esc_attr( $context ) . '">';
		foreach ( self::ACCOUNTS as $key => $a ) {
			$label = 'EG' === YM_Country::code() ? str_replace( 'إنستغرام', 'إنستجرام', $a[1] ) : $a[1]; // Egyptian spelling (docs/voice/EG.md)
			$out  .= '<li><a href="' . esc_url( $a[0] ) . '" rel="noopener me" target="_blank" aria-label="' . esc_attr( $label ) . '" data-ym-social="' . esc_attr( $key ) . '">'
				. '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $a[2] . '</svg>'
				. ( 'page' === $context ? '<span>' . esc_html( $label ) . '</span>' : '' ) . '</a></li>';
		}
		return $out . '</ul>';
	}
}
