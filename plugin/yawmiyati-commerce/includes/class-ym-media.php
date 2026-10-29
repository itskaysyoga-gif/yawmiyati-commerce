<?php
/**
 * Card derivatives and media rights (29 Oct brief §4).
 *
 * Equal CSS boxes do not make photography consistent: a product cut-out with a lot of white around it looks smaller
 * than one that fills its picture. Every image gets an approved CARD DERIVATIVE, made from the untouched original:
 *
 *   cutout  plain light background detected on all four edges: trimmed to the product, then padded to 4:5 with the
 *           same margin for every product (same optical scale across the grid).
 *   photo   a photograph (lifestyle, in use): never stretched. Cropped to 4:5 only when the crop keeps at least 90 %
 *           of the picture, or 75 % when a person recorded --fit=cover with a focal point; otherwise shown whole
 *           (contain) on the same white card. When the whole photo would fill less than 70 % of the card it is
 *           marked WEAK: the gate asks for another authorised source image instead of enlarging a poor crop.
 *
 * Files: WebP and JPEG at 400 and 800 px wide (never upscaled), served as <picture> with srcset, sizes and fixed
 * dimensions. The original stays the gallery image on the product page. Nothing is redrawn or generated.
 *
 * Rights (on _ym_media, `wp ym media`): channels (site, organic, paid), editing (none, crop, retouch), expires
 * (YYYY-MM-DD), approved (owner approval date). Supplier integration is not proof of rights.
 */

defined( 'ABSPATH' ) || exit;

final class YM_Media {

	const VERSION  = 2;
	const RATIO    = 0.8;   // 4:5
	const WIDTHS   = array( 400, 800 );
	const MARGIN   = 0.08;  // cut-outs: white margin as a share of the card's short side
	const WEAK     = 0.70;  // a contained photo filling less than this share of the card is weak

	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	/** Derivatives are not WordPress sizes: remove their files with the attachment. */
	public static function on_delete( $id ) {
		$c    = self::card( (int) $id );
		$file = get_attached_file( (int) $id );
		if ( $c && $file ) {
			foreach ( $c['files'] as $f ) {
				foreach ( array( $f['webp'], $f['jpg'] ) as $n ) {
					wp_delete_file( dirname( $file ) . '/' . basename( $n ) );
				}
			}
		}
	}

	public static function on_upload( $meta, $id ) {
		self::derive( (int) $id );
		return $meta;
	}

	public static function record( $id ) {
		$m = json_decode( (string) get_post_meta( $id, '_ym_media', true ), true );
		return is_array( $m ) ? $m : array();
	}

	/** Derivative record: mode, fill, weak, files. Null when none was made. */
	public static function card( $id ) {
		$c = json_decode( (string) get_post_meta( $id, '_ym_card', true ), true );
		return is_array( $c ) && ( $c['v'] ?? 0 ) === self::VERSION ? $c : null;
	}

	/** Make (or remake) the card derivatives of one attachment. Returns the record or null. */
	public static function derive( $id, $force = false ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$hash = md5_file( $file );
		$m    = self::record( $id );
		$fit  = 'none' === ( $m['editing'] ?? '' ) ? 'contain' : (string) ( $m['fit'] ?? '' ); // no crop or trim when the rights forbid editing
		$fx   = isset( $m['focal'][0] ) ? (float) $m['focal'][0] : 0.5;
		$fy   = isset( $m['focal'][1] ) ? (float) $m['focal'][1] : 0.5;
		$key  = md5( $hash . '|' . $fit . '|' . $fx . '|' . $fy );
		$old  = self::card( $id );
		if ( ! $force && $old && ( $old['key'] ?? '' ) === $key ) {
			return $old;
		}
		$src = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $src ) {
			return null;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );

		$cut = self::cutout_box( $src, $w, $h );
		if ( $cut && 'contain' !== $fit && 'cover' !== $fit ) {
			list( $x0, $y0, $bw, $bh ) = $cut;
			$mode   = 'cutout';
			$inner  = array( $x0, $y0, $bw, $bh );
			$cardw  = max( $bw / ( 1 - 2 * self::MARGIN ), $bh * self::RATIO / ( 1 - 2 * self::MARGIN ) );
			$cardh  = $cardw / self::RATIO;
			$fill   = 1.0;
		} else {
			$mode = 'photo';
			// Largest 4:5 window inside the photo, placed around the focal point.
			if ( $w / $h > self::RATIO ) {
				$cw = $h * self::RATIO;
				$ch = $h;
			} else {
				$cw = $w;
				$ch = $w / self::RATIO;
			}
			$keep = ( $cw * $ch ) / ( $w * $h );
			$min  = 'cover' === $fit ? 0.75 : 0.90;
			if ( 'contain' !== $fit && $keep >= $min ) {
				$x0    = (int) round( min( max( 0, $fx * $w - $cw / 2 ), $w - $cw ) );
				$y0    = (int) round( min( max( 0, $fy * $h - $ch / 2 ), $h - $ch ) );
				$inner = array( $x0, $y0, (int) round( $cw ), (int) round( $ch ) );
				$cardw = $cw;
				$cardh = $ch;
				$fill  = 1.0;
				$mode  = 'photo-crop';
			} else {
				$inner = array( 0, 0, $w, $h );
				$cardw = max( $w, $h * self::RATIO );
				$cardh = $cardw / self::RATIO;
				$fill  = ( $w * $h ) / ( $cardw * $cardh );
				$mode  = 'photo-whole';
			}
		}

		$files = array();
		$dir   = dirname( $file );
		$base  = pathinfo( $file, PATHINFO_FILENAME );
		$max   = (int) floor( $cardw );
		$sizes = array_values( array_unique( array_filter( array_map( fn( $x ) => min( $x, $max ), self::WIDTHS ) ) ) );
		foreach ( $sizes as $tw ) {
			$th  = (int) round( $tw / self::RATIO );
			$dst = imagecreatetruecolor( $tw, $th );
			imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
			$scale = $tw / $cardw;
			$dw    = (int) round( $inner[2] * $scale );
			$dh    = (int) round( $inner[3] * $scale );
			$dx    = (int) round( ( $tw - $dw ) / 2 );
			$dy    = (int) round( ( $th - $dh ) / 2 );
			imagecopyresampled( $dst, $src, $dx, $dy, $inner[0], $inner[1], $dw, $dh, $inner[2], $inner[3] );
			$name = $base . '-ym-card-' . $tw;
			imagewebp( $dst, $dir . '/' . $name . '.webp', 82 );
			imagejpeg( $dst, $dir . '/' . $name . '.jpg', 84 );
			imagedestroy( $dst );
			$files[] = array( 'w' => $tw, 'h' => $th, 'webp' => $name . '.webp', 'jpg' => $name . '.jpg' );
		}
		imagedestroy( $src );
		$rec = array(
			'v'     => self::VERSION,
			'key'   => $key,
			'mode'  => $mode,
			'fill'  => round( $fill, 3 ),
			'weak'  => $fill < self::WEAK,
			'files' => $files,
			'made'  => gmdate( 'c' ),
		);
		update_post_meta( $id, '_ym_card', wp_json_encode( $rec ) );
		return $rec;
	}

	/**
	 * Product bounding box when the picture is a cut-out on a plain light background (all four edges light and
	 * nearly uniform), else null.
	 */
	private static function cutout_box( $img, $w, $h ) {
		$step  = max( 1, (int) floor( min( $w, $h ) / 120 ) );
		$light = function ( $x, $y ) use ( $img ) {
			$c = imagecolorat( $img, $x, $y );
			$r = ( $c >> 16 ) & 255;
			$g = ( $c >> 8 ) & 255;
			$b = $c & 255;
			return $r > 236 && $g > 236 && $b > 236 && max( $r, $g, $b ) - min( $r, $g, $b ) < 14;
		};
		$edge = 0;
		$all  = 0;
		for ( $x = 0; $x < $w; $x += $step ) {
			$edge += (int) $light( $x, 0 ) + (int) $light( $x, $h - 1 );
			$all  += 2;
		}
		for ( $y = 0; $y < $h; $y += $step ) {
			$edge += (int) $light( 0, $y ) + (int) $light( $w - 1, $y );
			$all  += 2;
		}
		if ( $all && $edge / $all < 0.97 ) {
			return null;
		}
		$minx = $w;
		$miny = $h;
		$maxx = -1;
		$maxy = -1;
		for ( $y = 0; $y < $h; $y += $step ) {
			for ( $x = 0; $x < $w; $x += $step ) {
				if ( ! $light( $x, $y ) ) {
					$minx = min( $minx, $x );
					$maxx = max( $maxx, $x );
					$miny = min( $miny, $y );
					$maxy = max( $maxy, $y );
				}
			}
		}
		if ( $maxx < 0 ) {
			return null; // blank picture
		}
		$minx = max( 0, $minx - $step );
		$miny = max( 0, $miny - $step );
		$maxx = min( $w - 1, $maxx + $step );
		$maxy = min( $h - 1, $maxy + $step );
		return array( $minx, $miny, $maxx - $minx + 1, $maxy - $miny + 1 );
	}

	/**
	 * Card image markup: <picture> with WebP and JPEG srcsets from the derivative, fixed dimensions; falls back to
	 * WordPress's own image when no derivative exists.
	 */
	public static function card_html( $id, $alt, array $attrs = array() ) {
		$c = $id ? self::card( $id ) : null;
		if ( ! $c && $id ) {
			$c = self::derive( (int) $id );
		}
		if ( ! $c || ! $c['files'] ) {
			return $id ? wp_get_attachment_image( $id, 'woocommerce_single', false, array_merge( array( 'alt' => $alt ), $attrs ) ) : '<span class="ym-frame ym-frame--empty" aria-hidden="true"></span>';
		}
		$url   = trailingslashit( dirname( (string) wp_get_attachment_url( $id ) ) );
		$webp  = implode( ', ', array_map( fn( $f ) => esc_url( $url . $f['webp'] ) . ' ' . $f['w'] . 'w', $c['files'] ) );
		$jpg   = implode( ', ', array_map( fn( $f ) => esc_url( $url . $f['jpg'] ) . ' ' . $f['w'] . 'w', $c['files'] ) );
		$first = $c['files'][0];
		$sizes = $attrs['sizes'] ?? '(min-width: 960px) 260px, 46vw';
		unset( $attrs['sizes'] );
		$a = '';
		foreach ( array_merge( array( 'class' => 'ym-frame', 'loading' => 'lazy', 'decoding' => 'async' ), $attrs ) as $k => $v ) {
			$a .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		return '<picture class="ym-pic" data-ym-card="' . esc_attr( $c['mode'] ) . '"><source type="image/webp" srcset="' . esc_attr( $webp ) . '" sizes="' . esc_attr( $sizes ) . '">'
			. '<img src="' . esc_url( $url . $first['jpg'] ) . '" srcset="' . esc_attr( $jpg ) . '" sizes="' . esc_attr( $sizes ) . '" width="' . (int) $first['w'] . '" height="' . (int) $first['h'] . '" alt="' . esc_attr( $alt ) . '"' . $a . '></picture>';
	}

	/** Rights problems of one picture for a channel (site | organic | paid): empty array = usable. */
	public static function rights_problems( $id, $channel = 'site' ) {
		$m   = self::record( $id );
		$out = array();
		if ( isset( $m['channels'] ) && ! in_array( $channel, (array) $m['channels'], true ) ) {
			$out['media_channel'] = 'rights do not cover ' . $channel;
		}
		if ( ! empty( $m['expires'] ) && strtotime( $m['expires'] . ' 23:59:59 UTC' ) < time() ) {
			$out['media_expired'] = 'rights expired ' . $m['expires'];
		}
		if ( in_array( $channel, array( 'organic', 'paid' ), true ) && empty( $m['approved'] ) ) {
			$out['media_unapproved'] = 'not approved by the owner for ' . $channel;
		}
		return $out;
	}
}
