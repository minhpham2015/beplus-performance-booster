<?php
/**
 * Remove Unused CSS: strips CSS rules whose selectors do not appear anywhere
 * in the rendered page HTML, cached per page URL and per stylesheet.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_UCSS
 *
 * Unlike LiteSpeed's UCSS (which renders the page in a real browser via
 * QUIC.cloud), this is a pure-PHP, static-text match: it scans the final
 * HTML for class/id/tag names and drops CSS rules whose selectors never
 * appear in that text. Selectors matched by the safelist, or that cannot be
 * parsed safely (e.g. `:root`, `*`), are always kept.
 *
 * Flow
 * ----
 * 1. style_loader_src: if a fresh cached "used-only" copy of a stylesheet
 *    already exists for this URL, serve it immediately.
 * 2. template_redirect -> shutdown: buffer the entire page, extract the set
 *    of classes/ids/tags actually used, and (re)generate the cache file for
 *    every local stylesheet seen on this request so the *next* visit to the
 *    same URL gets the trimmed CSS.
 */
class BEPLUSPB_UCSS {

	/**
	 * Local stylesheet handles seen on this request: handle => file path.
	 *
	 * @var array
	 */
	private static $seen_styles = array();

	/**
	 * Cache key fragment identifying the current page URL.
	 *
	 * @var string|null
	 */
	private static $url_tag = null;

	/**
	 * Whether buffer_start() successfully opened a buffer.
	 *
	 * @var bool
	 */
	private static $buffering = false;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register hooks when the feature is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( empty( $opts['css_remove_unused'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( self::is_page_excluded( $opts ) ) {
			return;
		}

		if ( ! BEPLUSPB_Minify::ensure_cache_dir() ) {
			return;
		}

		self::$url_tag = self::build_url_tag();

		// Serve an already-cached "used-only" stylesheet immediately.
		add_filter( 'style_loader_src', array( __CLASS__, 'maybe_serve_cached' ), 20, 2 );

		// Buffer the full page so we can see what markup actually rendered.
		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'buffer_end' ), 0 );
	}

	/**
	 * Whether the current request URI matches a css_unused_exclude keyword.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 * @return bool
	 */
	private static function is_page_excluded( $opts ) {
		$patterns = bepluspb_parse_exclude_list( $opts['css_unused_exclude'] );
		if ( empty( $patterns ) ) {
			return false;
		}

		$current_url = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $current_url, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a short, filename-safe tag identifying the current page path.
	 *
	 * @return string
	 */
	private static function build_url_tag() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$path = strtok( $uri, '?' );

		return substr( md5( $path ), 0, 12 );
	}

	// -------------------------------------------------------------------------
	// Serving cached "used-only" CSS
	// -------------------------------------------------------------------------

	/**
	 * Point a stylesheet at its cached used-only version if one exists and is
	 * still fresh (source file not modified since the cache was generated).
	 *
	 * @param  string $src    Stylesheet URL.
	 * @param  string $handle Registered style handle.
	 * @return string Possibly replaced URL.
	 */
	public static function maybe_serve_cached( $src, $handle ) {
		if ( BEPLUSPB_Minify::is_css_file_excluded( $src ) ) {
			return $src;
		}

		$path = BEPLUSPB_Minify::url_to_path( $src );
		if ( ! $path ) {
			return $src; // External / not a local file — nothing we can do.
		}

		self::$seen_styles[ $handle ] = $path;

		$cache_file = self::cache_path( $handle, $path );
		if ( file_exists( $cache_file ) && filemtime( $cache_file ) >= filemtime( $path ) ) {
			$cache_url = self::cache_url( $handle, $path );
			$query     = wp_parse_url( $src, PHP_URL_QUERY );
			return $query ? $cache_url . '?' . $query : $cache_url;
		}

		return $src;
	}

	/**
	 * Build the cache filename for one stylesheet on the current page.
	 *
	 * @param  string $handle Style handle.
	 * @param  string $path   Absolute filesystem path of the source file.
	 * @return string
	 */
	private static function cache_name( $handle, $path ) {
		$content_hash = substr( md5_file( $path ), 0, 10 );
		return 'ucss-' . self::$url_tag . '-' . sanitize_file_name( $handle ) . '-' . $content_hash . '.css';
	}

	/**
	 * Absolute filesystem path of the cache file for a stylesheet.
	 *
	 * @param  string $handle Style handle.
	 * @param  string $path   Absolute filesystem path of the source file.
	 * @return string
	 */
	private static function cache_path( $handle, $path ) {
		return BEPLUSPB_CACHE_DIR . self::cache_name( $handle, $path );
	}

	/**
	 * Public URL of the cache file for a stylesheet.
	 *
	 * @param  string $handle Style handle.
	 * @param  string $path   Absolute filesystem path of the source file.
	 * @return string
	 */
	private static function cache_url( $handle, $path ) {
		return BEPLUSPB_Minify::cache_url() . self::cache_name( $handle, $path );
	}

	// -------------------------------------------------------------------------
	// Buffering + generation
	// -------------------------------------------------------------------------

	/**
	 * Start buffering the full page output.
	 */
	public static function buffer_start() {
		if ( is_admin() ) {
			return;
		}
		self::$buffering = true;
		ob_start();
	}

	/**
	 * Flush the buffered page to the browser, then regenerate any stale
	 * used-only CSS caches for the stylesheets seen on this request.
	 */
	public static function buffer_end() {
		if ( ! self::$buffering || ob_get_level() < 1 ) {
			return;
		}

		$html = ob_get_clean();
		if ( false === $html ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;

		// Let the browser start rendering before we do the (heavier) CSS work.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		if ( empty( self::$seen_styles ) ) {
			return;
		}

		$used     = self::extract_used_tokens( $html );
		$opts     = bepluspb_get_options();
		$safelist = bepluspb_parse_exclude_list( $opts['css_unused_safelist'] );

		foreach ( self::$seen_styles as $handle => $path ) {
			$cache_file = self::cache_path( $handle, $path );
			if ( file_exists( $cache_file ) && filemtime( $cache_file ) >= filemtime( $path ) ) {
				continue; // Already fresh.
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$css = @file_get_contents( $path );
			if ( false === $css || '' === trim( $css ) ) {
				continue;
			}

			$filtered = self::filter_css( $css, $used, $safelist );
			$filtered = BEPLUSPB_Minify::minify_css( $filtered );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@file_put_contents( $cache_file, $filtered );
		}
	}

	// -------------------------------------------------------------------------
	// Used-token extraction
	// -------------------------------------------------------------------------

	/**
	 * Build a lookup set of every class (.foo), id (#bar), and tag name used
	 * anywhere in the given HTML.
	 *
	 * @param  string $html Full rendered page HTML.
	 * @return array Associative array used as a set (token => true).
	 */
	private static function extract_used_tokens( $html ) {
		$tokens = array();

		if ( preg_match_all( '/\bclass=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $classes ) {
				foreach ( preg_split( '/\s+/', trim( $classes ) ) as $c ) {
					if ( '' !== $c ) {
						$tokens[ '.' . $c ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '/\bid=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = trim( $id );
				if ( '' !== $id ) {
					$tokens[ '#' . $id ] = true;
				}
			}
		}

		if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b/', $html, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tokens[ strtolower( $tag ) ] = true;
			}
		}

		return $tokens;
	}

	// -------------------------------------------------------------------------
	// CSS parsing / filtering
	// -------------------------------------------------------------------------

	/**
	 * Filter unused rules out of a CSS string.
	 *
	 * @param  string $css      Raw CSS content.
	 * @param  array  $used     Used-token lookup set from extract_used_tokens().
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string Filtered CSS.
	 */
	public static function filter_css( $css, $used, $safelist ) {
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		return self::filter_block( $css, $used, $safelist );
	}

	/**
	 * Recursively filter a block of CSS (top-level or inside an @-rule).
	 * Brace-depth aware so nested @media/@keyframes blocks parse correctly.
	 *
	 * @param  string $css      CSS block to filter.
	 * @param  array  $used     Used-token lookup set.
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string Filtered CSS.
	 */
	private static function filter_block( $css, $used, $safelist ) {
		$out    = '';
		$len    = strlen( $css );
		$i      = 0;
		$buffer = '';

		while ( $i < $len ) {
			$char = $css[ $i ];

			if ( '{' === $char ) {
				$selector = trim( $buffer );
				$buffer   = '';
				$depth    = 1;
				$start    = $i + 1;
				$j        = $start;

				while ( $j < $len && $depth > 0 ) {
					if ( '{' === $css[ $j ] ) {
						++$depth;
					} elseif ( '}' === $css[ $j ] ) {
						--$depth;
					}
					++$j;
				}

				$inner = substr( $css, $start, $j - $start - 1 );

				if ( '' !== $selector && '@' === $selector[0] ) {
					$out .= self::handle_at_rule( $selector, $inner, $used, $safelist );
				} elseif ( self::selector_is_used( $selector, $used, $safelist ) ) {
					$out .= $selector . '{' . $inner . '}';
				}

				$i = $j;
				continue;
			}

			$buffer .= $char;
			++$i;
		}

		return $out;
	}

	/**
	 * Handle an @-rule: keep always-safe at-rules verbatim, recurse into
	 * conditional group rules (@media, @supports), and drop them if the
	 * filtered contents end up empty.
	 *
	 * @param  string $selector The @-rule prelude, e.g. "@media (min-width:600px)".
	 * @param  string $inner    Raw CSS inside the @-rule's braces.
	 * @param  array  $used     Used-token lookup set.
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string
	 */
	private static function handle_at_rule( $selector, $inner, $used, $safelist ) {
		$name = strtolower( substr( $selector, 1, strcspn( $selector, " (\t\n" ) ) );

		$always_keep = array( 'font-face', 'keyframes', '-webkit-keyframes', '-moz-keyframes', 'page', 'import', 'charset', 'namespace' );
		foreach ( $always_keep as $keep ) {
			if ( 0 === strpos( $name, $keep ) ) {
				return $selector . '{' . $inner . '}';
			}
		}

		// @media, @supports, @document, etc. — recurse, drop if it ends up empty.
		$filtered = self::filter_block( $inner, $used, $safelist );
		if ( '' === trim( $filtered ) ) {
			return '';
		}

		return $selector . '{' . $filtered . '}';
	}

	/**
	 * Whether at least one comma-separated selector in $selector_list matches
	 * a used class/id/tag token or a safelist entry ('*' wildcard supported).
	 * Selectors that can't be safely parsed (e.g. `:root`, `*`) are kept.
	 *
	 * @param  string $selector_list Raw selector list, e.g. ".a, .b:hover".
	 * @param  array  $used          Used-token lookup set.
	 * @param  array  $safelist      Selector keywords that must always be kept.
	 * @return bool
	 */
	private static function selector_is_used( $selector_list, $used, $safelist ) {
		foreach ( explode( ',', $selector_list ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}

			foreach ( $safelist as $safe ) {
				$safe = trim( $safe );
				if ( '' === $safe ) {
					continue;
				}
				if ( '*' === substr( $safe, -1 ) ) {
					if ( 0 === strpos( $selector, rtrim( $safe, '*' ) ) ) {
						return true;
					}
				} elseif ( $selector === $safe || false !== strpos( $selector, $safe ) ) {
					return true;
				}
			}

			// Strip pseudo-classes/elements and combinators, keep bare tokens.
			$clean = preg_replace( '/::?[a-zA-Z-]+(\([^)]*\))?/', '', $selector );
			$clean = preg_replace( '/[>+~]/', ' ', $clean );

			if ( preg_match_all( '/(\.[a-zA-Z0-9_-]+|#[a-zA-Z0-9_-]+|\b[a-zA-Z][a-zA-Z0-9]*\b)/', $clean, $m ) ) {
				foreach ( $m[1] as $token ) {
					if ( '' === $token ) {
						continue;
					}
					$lookup = ctype_alpha( $token[0] ) ? strtolower( $token ) : $token;
					if ( isset( $used[ $lookup ] ) ) {
						return true;
					}
				}
			} else {
				// Universal selector `*`, `:root`, or something unparseable — keep it safe.
				return true;
			}
		}

		return false;
	}
}
