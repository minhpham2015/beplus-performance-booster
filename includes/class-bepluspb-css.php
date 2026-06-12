<?php
/**
 * CSS optimization: non-render-blocking preload swap, inline style minification,
 * font preloading, inline-all-CSS, and per-handle CSS removal.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_CSS
 *
 * Uses a wp_head output buffer to transform stylesheet links and
 * minify inline <style> blocks without touching external .css files.
 */
class BEPLUSPB_CSS {

	/**
	 * The ob_get_level() value recorded immediately before we call ob_start().
	 *
	 * Stored so buffer_end() can verify it is consuming only the buffer it
	 * opened. We compare against self::$buffer_level + 1 (the exact level of
	 * our buffer) rather than just > self::$buffer_level to avoid accidentally
	 * cleaning a buffer opened by another plugin on top of ours.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Register wp_head output-buffer hooks when at least one CSS feature is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( ! empty( $opts['font_preload'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'output_font_preload' ), 2 );
		}

		if ( ! empty( $opts['css_remove_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_css_handles' ), 9999 );
		}

		if ( $opts['css_non_blocking'] || $opts['css_minify'] || $opts['css_inline_all'] ) {
			add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), 1 );
			add_action( 'wp_head', array( __CLASS__, 'buffer_end' ),   9999 );
		}
	}

	// -------------------------------------------------------------------------
	// Font preload
	// -------------------------------------------------------------------------

	/**
	 * Output <link rel="preload" as="font"> tags for each URL in font_preload.
	 */
	public static function output_font_preload() {
		$opts  = bepluspb_get_options();
		$fonts = bepluspb_parse_exclude_list( $opts['font_preload'] );

		foreach ( $fonts as $font_url ) {
			if ( empty( $font_url ) ) {
				continue;
			}
			$clean_url = strtok( $font_url, '?' );
			$ext       = strtolower( pathinfo( $clean_url, PATHINFO_EXTENSION ) );
			$type_map  = array(
				'woff2' => 'font/woff2',
				'woff'  => 'font/woff',
				'ttf'   => 'font/ttf',
				'otf'   => 'font/otf',
				'eot'   => 'application/vnd.ms-fontobject',
			);
			$mime_type = isset( $type_map[ $ext ] ) ? $type_map[ $ext ] : 'font/woff2';

			printf(
				'<link rel="preload" as="font" type="%s" href="%s" crossorigin="anonymous">' . "\n",
				esc_attr( $mime_type ),
				esc_url( $font_url )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Remove CSS handles
	// -------------------------------------------------------------------------

	/**
	 * Dequeue and deregister CSS handles listed in the css_remove_handles option.
	 */
	public static function remove_css_handles() {
		$opts    = bepluspb_get_options();
		$handles = bepluspb_parse_exclude_list( $opts['css_remove_handles'] );

		foreach ( $handles as $handle ) {
			if ( ! empty( $handle ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Output buffer
	// -------------------------------------------------------------------------

	/**
	 * Start output buffering at the very start of wp_head (priority 1).
	 *
	 * Records the current ob nesting level so buffer_end() can verify it is
	 * closing exactly the buffer it opened (level + 1), not one opened by
	 * another plugin on top of ours.
	 */
	public static function buffer_start() {
		self::$buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * End the buffer, apply transforms, and echo the result.
	 * Runs at priority 9999 — after all enqueued styles have been printed.
	 */
	public static function buffer_end() {
		// Safety: only clean the exact buffer level we opened (level + 1).
		// Using !== instead of <= prevents us from accidentally closing a buffer
		// opened by another plugin on top of ours.
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			return;
		}

		$html = ob_get_clean();

		if ( false === $html ) {
			return;
		}

		$opts = bepluspb_get_options();

		if ( $opts['css_inline_all'] ) {
			$exclude = bepluspb_parse_exclude_list( $opts['css_exclude'] );
			$html    = self::inline_all_css( $html, $exclude );
		}

		if ( $opts['css_non_blocking'] ) {
			$exclude = bepluspb_parse_exclude_list( $opts['css_exclude'] );
			$html    = self::make_non_blocking( $html, $exclude );
		}

		if ( $opts['css_minify'] ) {
			$html = self::minify_inline_styles( $html );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	// -------------------------------------------------------------------------
	// Inline all CSS
	// -------------------------------------------------------------------------

	/**
	 * Replace every local <link rel="stylesheet"> with a <style> block
	 * containing the file's (minified) content.
	 *
	 * @param  string   $html    Buffered wp_head output.
	 * @param  string[] $exclude URL keywords whose stylesheets must be kept as links.
	 * @return string Modified HTML.
	 */
	public static function inline_all_css( $html, $exclude = array() ) {
		return preg_replace_callback(
			'/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i',
			function ( $matches ) use ( $exclude ) {
				$tag = $matches[0];

				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				if ( ! preg_match( '/\bhref=[\'"]([^\'"]+)[\'"]/', $tag, $href_m ) ) {
					return $tag;
				}
				$href = $href_m[1];

				$path = BEPLUSPB_Minify::url_to_path( $href );
				if ( ! $path ) {
					return $tag;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = @file_get_contents( $path );
				if ( false === $content || '' === trim( $content ) ) {
					return $tag;
				}

				$content  = self::rewrite_relative_urls( $content, $href );
				$minified = BEPLUSPB_Minify::minify_css( $content );

				$media = '';
				if ( preg_match( '/\bmedia=[\'"]([^\'"]*)[\'"]/', $tag, $media_m ) ) {
					$media = ' media="' . esc_attr( $media_m[1] ) . '"';
				}

				return '<style' . $media . '>' . $minified . '</style>';
			},
			$html
		);
	}

	// -------------------------------------------------------------------------
	// URL rewriting helper
	// -------------------------------------------------------------------------

	/**
	 * Rewrite relative url() references inside a CSS string to absolute URLs.
	 *
	 * @param  string $css  Raw CSS content from the stylesheet file.
	 * @param  string $href The public URL of the stylesheet.
	 * @return string CSS with absolute url() references.
	 */
	private static function rewrite_relative_urls( $css, $href ) {
		$clean_href = strtok( $href, '?' );
		$base_url   = trailingslashit( dirname( $clean_href ) );

		return preg_replace_callback(
			'/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
			function ( $m ) use ( $base_url ) {
				$url = trim( $m[1] );

				if (
					0 === strpos( $url, 'http://' )
					|| 0 === strpos( $url, 'https://' )
					|| 0 === strpos( $url, '//' )
					|| 0 === strpos( $url, '/' )
					|| 0 === strpos( $url, 'data:' )
					|| 0 === strpos( $url, '#' )
				) {
					return $m[0];
				}

				return 'url("' . esc_url( $base_url . $url ) . '")';
			},
			$css
		);
	}

	// -------------------------------------------------------------------------
	// Non-blocking CSS
	// -------------------------------------------------------------------------

	/**
	 * Replace <link rel="stylesheet"> tags with the preload + onload swap pattern.
	 *
	 * @param  string $html    Buffered wp_head output.
	 * @param  array  $exclude URL keywords whose stylesheets must be left untouched.
	 * @return string Modified HTML.
	 */
	public static function make_non_blocking( $html, $exclude ) {
		$theme_stylesheet_url = get_bloginfo( 'stylesheet_url' );
		if ( ! empty( $theme_stylesheet_url ) ) {
			$parsed = wp_parse_url( $theme_stylesheet_url, PHP_URL_PATH );
			if ( $parsed ) {
				$exclude[] = $parsed;
			}
		}
		return preg_replace_callback(
			'/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i',
			function ( $matches ) use ( $exclude ) {
				$tag = $matches[0];

				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				if ( false !== strpos( $tag, 'rel="preload"' ) || false !== strpos( $tag, "rel='preload'" ) ) {
					return $tag;
				}

				$preload = preg_replace(
					'/rel=[\'"]stylesheet[\'"]/i',
					'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
					$tag
				);

				return $preload . "\n<noscript>" . $tag . '</noscript>';
			},
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Inline style minification
	// -------------------------------------------------------------------------

	/**
	 * Minify the content of every inline <style> block in the given HTML.
	 *
	 * @param  string $html Buffered HTML (wp_head output).
	 * @return string HTML with minified inline styles.
	 */
	public static function minify_inline_styles( $html ) {
		return preg_replace_callback(
			'/<style([^>]*)>(.*?)<\/style>/is',
			function ( $matches ) {
				$attrs = $matches[1];
				$css   = BEPLUSPB_Minify::minify_css( $matches[2] );
				return '<style' . $attrs . '>' . $css . '</style>';
			},
			$html
		);
	}
}
