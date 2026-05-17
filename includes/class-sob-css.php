<?php
/**
 * CSS optimization: non-render-blocking preload swap, inline style minification,
 * font preloading, inline-all-CSS, and per-handle CSS removal.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_CSS
 *
 * Uses a wp_head output buffer to transform stylesheet links and
 * minify inline <style> blocks without touching external .css files.
 *
 * Non-blocking strategy:
 *   <link rel="stylesheet"> is replaced with
 *   <link rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'">
 *   plus a <noscript> fallback for JS-disabled browsers.
 *
 * Inline-all-CSS strategy:
 *   Every local <link rel="stylesheet"> in wp_head is replaced by the file's
 *   minified content inside a <style> block. External CDN stylesheets are kept
 *   as-is. Reduces render-blocking HTTP requests to zero at the cost of a
 *   slightly larger initial HTML payload.
 *
 * Font preload:
 *   Outputs <link rel="preload" as="font" crossorigin="anonymous"> for each
 *   URL listed in the font_preload option (earliest possible — priority 2).
 *
 * Remove CSS handles:
 *   Dequeues and deregisters specific style handles listed in css_remove_handles.
 */
class SOB_CSS {

	/**
	 * The ob_get_level() value recorded immediately before we call ob_start().
	 *
	 * Stored as a class property so buffer_end() can verify it is consuming
	 * only the buffer it opened and not one opened by another plugin.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Register wp_head output-buffer hooks when at least one CSS feature is enabled.
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		// Font preload runs earliest so preload hints appear near the top of <head>.
		if ( ! empty( $opts['font_preload'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'output_font_preload' ), 2 );
		}

		// Remove specific CSS handles before styles are printed.
		if ( ! empty( $opts['css_remove_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_css_handles' ), 9999 );
		}

		// Output-buffer features: non-blocking, inline-minify, inline-all.
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
	 *
	 * The crossorigin="anonymous" attribute is required for CORS font requests,
	 * even for same-origin fonts, to match how browsers fetch @font-face sources.
	 */
	public static function output_font_preload() {
		$opts  = sob_get_options();
		$fonts = sob_parse_exclude_list( $opts['font_preload'] );

		foreach ( $fonts as $font_url ) {
			if ( empty( $font_url ) ) {
				continue;
			}
			// Determine MIME type from extension.
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
	 *
	 * Runs at wp_enqueue_scripts priority 9999 so it fires after all plugins
	 * and themes have registered their stylesheets.
	 */
	public static function remove_css_handles() {
		$opts    = sob_get_options();
		$handles = sob_parse_exclude_list( $opts['css_remove_handles'] );

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
	 * Records the current ob nesting level so buffer_end() only consumes the
	 * buffer it opened, even when another plugin has opened additional buffers
	 * in between.
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
		// Safety: only clean our own buffer level.
		if ( null === self::$buffer_level || ob_get_level() <= self::$buffer_level ) {
			return;
		}

		$html = ob_get_clean();

		// ob_get_clean() returns false when there is no active buffer.
		if ( false === $html ) {
			return;
		}

		$opts = sob_get_options();

		// Inline-all-CSS must run before non-blocking conversion (the link tags
		// it produces should not be converted back to preload).
		if ( $opts['css_inline_all'] ) {
			$exclude = sob_parse_exclude_list( $opts['css_exclude'] );
			$html    = self::inline_all_css( $html, $exclude );
		}

		if ( $opts['css_non_blocking'] ) {
			$exclude = sob_parse_exclude_list( $opts['css_exclude'] );
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
	 * External URLs (CDN, Google Fonts, etc.) are left as-is because their
	 * content is not accessible from the server filesystem.
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

				// Keep excluded stylesheets as external links.
				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				// Extract href.
				if ( ! preg_match( '/\bhref=[\'"]([^\'"]+)[\'"]/', $tag, $href_m ) ) {
					return $tag;
				}
				$href = $href_m[1];

				// Resolve to filesystem path.
				$path = SOB_Minify::url_to_path( $href );
				if ( ! $path ) {
					// External URL — keep as a link.
					return $tag;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = @file_get_contents( $path );
				if ( false === $content || '' === trim( $content ) ) {
					return $tag; // Unreadable — fall back to link.
				}

				// Rewrite relative url() references to absolute before inlining
				// so that images, fonts, and other assets resolve correctly from
				// the page URL rather than from the original stylesheet directory.
				$content = self::rewrite_relative_urls( $content, $href );

				$minified = SOB_Minify::minify_css( $content );

				// Extract media attribute if present.
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
	// URL rewriting helper (BUG-4)
	// -------------------------------------------------------------------------

	/**
	 * Rewrite relative url() references inside a CSS string to absolute URLs.
	 *
	 * Relative paths in an inlined <style> block resolve against the page URL
	 * rather than the original stylesheet directory, causing 404s for images,
	 * fonts, and other assets referenced in the CSS.  This helper fixes that by
	 * prepending the stylesheet's directory URL to every relative url() value.
	 *
	 * Paths that are already absolute (starting with `/`, `http://`, `https://`,
	 * `data:`, or `//`) are left untouched.
	 *
	 * @param  string $css  Raw CSS content from the stylesheet file.
	 * @param  string $href The public URL of the stylesheet (e.g. as it appears
	 *                      in the href attribute of the original <link> tag).
	 * @return string CSS with absolute url() references.
	 */
	private static function rewrite_relative_urls( $css, $href ) {
		// Derive the directory URL: strip query string, then remove the filename.
		$clean_href  = strtok( $href, '?' );
		$base_url    = trailingslashit( dirname( $clean_href ) );

		return preg_replace_callback(
			'/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
			function ( $m ) use ( $base_url ) {
				$url = trim( $m[1] );

				// Already absolute — nothing to change.
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
	 * The active theme's main stylesheet (style.css) is always excluded from
	 * non-blocking conversion because many themes rely on its load order for
	 * critical above-the-fold styles, and converting it causes a flash of
	 * unstyled content (FOUC).
	 *
	 * @param  string $html    Buffered wp_head output.
	 * @param  array  $exclude URL keywords whose stylesheets must be left untouched.
	 * @return string Modified HTML.
	 */
	public static function make_non_blocking( $html, $exclude ) {
		// COMPAT-5: Always protect the active theme's stylesheet from conversion.
		$theme_stylesheet_url = get_bloginfo( 'stylesheet_url' );
		if ( ! empty( $theme_stylesheet_url ) ) {
			// Use just the path portion so it matches regardless of scheme/host.
			$parsed = wp_parse_url( $theme_stylesheet_url, PHP_URL_PATH );
			if ( $parsed ) {
				$exclude[] = $parsed;
			}
		}
		return preg_replace_callback(
			'/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i',
			function ( $matches ) use ( $exclude ) {
				$tag = $matches[0];

				// Leave excluded stylesheets untouched.
				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				// Already converted — nothing to do.
				if ( false !== strpos( $tag, 'rel="preload"' ) || false !== strpos( $tag, "rel='preload'" ) ) {
					return $tag;
				}

				// Swap rel="stylesheet" for rel="preload" with onload restoration.
				$preload = preg_replace(
					'/rel=[\'"]stylesheet[\'"]/i',
					'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
					$tag
				);

				// noscript fallback ensures CSS loads even without JavaScript.
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
	 * Removes:
	 *  - Block comments (license comments starting with "/*!" are preserved)
	 *  - Redundant whitespace
	 *  - Trailing semicolons before closing braces
	 *
	 * @param  string $html Buffered HTML (wp_head output).
	 * @return string HTML with minified inline styles.
	 */
	public static function minify_inline_styles( $html ) {
		return preg_replace_callback(
			'/<style([^>]*)>(.*?)<\/style>/is',
			function ( $matches ) {
				$attrs = $matches[1];
				$css   = $matches[2];

				// Step 1: protect /*! license / copyright comments with tokens.
				$license_tokens = array();
				$lic_idx        = 0;
				$css = preg_replace_callback(
					'/\/\*![\s\S]*?\*\//',
					function ( $m ) use ( &$license_tokens, &$lic_idx ) {
						$token                    = 'SOBCSSLIC' . $lic_idx . 'END';
						$license_tokens[ $token ] = $m[0];
						$lic_idx++;
						return $token;
					},
					$css
				);

				// Step 2: strip all remaining block comments.
				$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );

				// Step 3: collapse runs of whitespace to a single space.
				$css = preg_replace( '/\s+/', ' ', $css );

				// Step 4: remove whitespace around structural characters.
				// NOTE: `+` is intentionally omitted — removing spaces around `+`
				// breaks calc() expressions such as calc(100% + 20px).
				$css = preg_replace( '/\s*([{};:,>~])\s*/', '$1', $css );

				// Step 5: remove trailing semicolons before closing braces.
				$css = str_replace( ';}', '}', $css );

				$css = trim( $css );

				// Step 6: restore license comments.
				if ( ! empty( $license_tokens ) ) {
					foreach ( $license_tokens as $token => $comment ) {
						$css = str_replace( $token, $comment, $css );
					}
				}

				return '<style' . $attrs . '>' . $css . '</style>';
			},
			$html
		);
	}
}
