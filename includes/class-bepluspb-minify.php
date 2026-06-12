<?php
/**
 * CSS and JS file minification with disk caching.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Minify
 *
 * Intercepts every enqueued CSS and JS file via WordPress's
 * `style_loader_src` and `script_loader_src` filters, minifies the content
 * in PHP, writes the result to the uploads-based cache directory, and returns
 * the cached file's public URL so the browser loads the smaller version.
 *
 * Cache strategy
 * --------------
 * - Cache directory : wp-content/uploads/bepluspb-cache/
 * - Cache filename  : {handle}-{md5_12}.{ext}
 * - Already-minified files (*.min.css, *.min.js) are skipped.
 * - External URLs (CDN, Google Fonts, etc.) are skipped.
 * - If the cache directory is not writable the original URL is returned
 *   unchanged — the feature degrades gracefully.
 */
class BEPLUSPB_Minify {

	/**
	 * Per-request cache for the "is page cache disabled?" lookup.
	 *
	 * @var bool|null  null = not yet checked.
	 */
	private static $page_cache_disabled = null;

	/**
	 * Per-request cache for the page-exclusion-only check.
	 *
	 * @var bool|null  null = not yet checked.
	 */
	private static $page_excluded = null;

	/**
	 * Cached public URL for the cache directory.
	 *
	 * @var string|null
	 */
	private static $cache_url = null;

	// -------------------------------------------------------------------------
	// Cache URL helper (lazy so wp_upload_dir() is always available)
	// -------------------------------------------------------------------------

	/**
	 * Return the public URL of the cache directory, with trailing slash.
	 *
	 * Uses wp_upload_dir() to resolve the URL so it is always in sync with
	 * BEPLUSPB_CACHE_DIR regardless of WordPress installation layout.
	 *
	 * @return string e.g. http://example.com/wp-content/uploads/bepluspb-cache/
	 */
	public static function cache_url() {
		if ( null === self::$cache_url ) {
			$upload_dir      = wp_upload_dir();
			self::$cache_url = trailingslashit( $upload_dir['baseurl'] . '/bepluspb-cache' );
		}
		return self::$cache_url;
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register style/script src filters when minification is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}

		self::$page_cache_disabled = null;
		self::$page_excluded       = null;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$css_on = $opts['minify_css_files'] ? 'ON' : 'OFF';
			$js_on  = $opts['minify_js_files']  ? 'ON' : 'OFF';
			error_log( "[BEPLUSPB] Minify::init() -- CSS:{$css_on} JS:{$js_on} CACHE_DIR:" . BEPLUSPB_CACHE_DIR ); // phpcs:ignore
		}

		if ( $opts['minify_css_files'] ) {
			add_filter( 'style_loader_src',  array( __CLASS__, 'maybe_minify_css' ), 10, 2 );
		}

		if ( $opts['minify_js_files'] ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'maybe_minify_js' ), 10, 2 );
		}
	}

	// -------------------------------------------------------------------------
	// Filter callbacks
	// -------------------------------------------------------------------------

	/**
	 * Minify one enqueued CSS file.
	 *
	 * @param  string $src    Stylesheet URL.
	 * @param  string $handle Registered style handle.
	 * @return string Possibly replaced URL pointing to the cached/minified file.
	 */
	public static function maybe_minify_css( $src, $handle ) {
		if ( self::is_page_excluded() ) {
			return $src;
		}

		if ( false !== strpos( $src, '.min.css' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] CSS skip (pre-minified): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		$file_path = self::url_to_path( $src );
		if ( ! $file_path ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] CSS skip (url_to_path failed): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $file_path );
		if ( false === $content || '' === trim( $content ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] CSS skip (unreadable): {$handle} -> {$file_path}" ); // phpcs:ignore
			}
			return $src;
		}

		$hash       = substr( md5( $content ), 0, 12 );
		$cache_name = sanitize_file_name( $handle ) . '-' . $hash . '.css';
		$cache_file = BEPLUSPB_CACHE_DIR . $cache_name;
		$cache_url  = self::cache_url() . $cache_name;

		if ( ! file_exists( $cache_file ) ) {
			if ( ! self::ensure_cache_dir() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[BEPLUSPB] CSS skip (cache dir not writable): " . BEPLUSPB_CACHE_DIR ); // phpcs:ignore
				}
				return $src;
			}
			$minified = self::minify_css( $content );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$written = @file_put_contents( $cache_file, $minified );
			if ( false === $written ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[BEPLUSPB] CSS WRITE FAILED: {$cache_file}" ); // phpcs:ignore
				}
				return $src;
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] CSS cached: {$handle} -> {$cache_file} ({$written} bytes)" ); // phpcs:ignore
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] CSS cache hit: {$handle} -> {$cache_name}" ); // phpcs:ignore
			}
		}

		$opts = bepluspb_get_options();
		if ( empty( $opts['cache_for_logged_in'] ) && is_user_logged_in() ) {
			return $src;
		}

		$query = wp_parse_url( $src, PHP_URL_QUERY );
		return $query ? $cache_url . '?' . $query : $cache_url;
	}

	/**
	 * Minify one enqueued JS file.
	 *
	 * @param  string $src    Script URL.
	 * @param  string $handle Registered script handle.
	 * @return string Possibly replaced URL pointing to the cached/minified file.
	 */
	public static function maybe_minify_js( $src, $handle ) {
		if ( self::is_page_excluded() ) {
			return $src;
		}

		if ( false !== strpos( $src, '.min.js' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] JS skip (pre-minified): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		if ( in_array( $handle, array( 'bepluspb-lazy-fallback', 'bepluspb-delay-js' ), true ) ) {
			return $src;
		}

		$file_path = self::url_to_path( $src );
		if ( ! $file_path ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] JS skip (url_to_path failed): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $file_path );
		if ( false === $content || '' === trim( $content ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] JS skip (unreadable): {$handle} -> {$file_path}" ); // phpcs:ignore
			}
			return $src;
		}

		$hash       = substr( md5( $content ), 0, 12 );
		$cache_name = sanitize_file_name( $handle ) . '-' . $hash . '.js';
		$cache_file = BEPLUSPB_CACHE_DIR . $cache_name;
		$cache_url  = self::cache_url() . $cache_name;

		if ( ! file_exists( $cache_file ) ) {
			if ( ! self::ensure_cache_dir() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[BEPLUSPB] JS skip (cache dir not writable): " . BEPLUSPB_CACHE_DIR ); // phpcs:ignore
				}
				return $src;
			}
			$minified = self::minify_js( $content );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$written = @file_put_contents( $cache_file, $minified );
			if ( false === $written ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[BEPLUSPB] JS WRITE FAILED: {$cache_file}" ); // phpcs:ignore
				}
				return $src;
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] JS cached: {$handle} -> {$cache_file} ({$written} bytes)" ); // phpcs:ignore
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[BEPLUSPB] JS cache hit: {$handle} -> {$cache_name}" ); // phpcs:ignore
			}
		}

		$opts = bepluspb_get_options();
		if ( empty( $opts['cache_for_logged_in'] ) && is_user_logged_in() ) {
			return $src;
		}

		$query = wp_parse_url( $src, PHP_URL_QUERY );
		return $query ? $cache_url . '?' . $query : $cache_url;
	}

	// -------------------------------------------------------------------------
	// Per-page cache-disable check
	// -------------------------------------------------------------------------

	/**
	 * Return true if caching should be skipped for the current page.
	 *
	 * @return bool
	 */
	public static function is_cache_disabled_for_page() {
		if ( null !== self::$page_cache_disabled ) {
			return self::$page_cache_disabled;
		}

		$opts = bepluspb_get_options();

		if ( empty( $opts['cache_for_logged_in'] ) && is_user_logged_in() ) {
			self::$page_cache_disabled = true;
			return true;
		}

		$post_id = get_queried_object_id();
		if ( $post_id ) {
			$meta = get_post_meta( $post_id, '_bepluspb_disable_cache', true );
			if ( ! empty( $meta ) ) {
				self::$page_cache_disabled = true;
				return true;
			}
		}

		$patterns = bepluspb_parse_exclude_list( $opts['cache_exclude_pages'] );
		if ( ! empty( $patterns ) ) {
			$current_url = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$full_url = home_url( $current_url );

			foreach ( $patterns as $pattern ) {
				if ( empty( $pattern ) ) {
					continue;
				}
				if (
					false !== strpos( $current_url, $pattern ) ||
					false !== strpos( $full_url, $pattern )
				) {
					self::$page_cache_disabled = true;
					return true;
				}
			}
		}

		self::$page_cache_disabled = false;
		return false;
	}

	/**
	 * Return true if the current page is excluded from caching via per-page meta
	 * or URL pattern — WITHOUT checking login status.
	 *
	 * @return bool
	 */
	private static function is_page_excluded() {
		if ( null !== self::$page_excluded ) {
			return self::$page_excluded;
		}

		$opts = bepluspb_get_options();

		$post_id = get_queried_object_id();
		if ( $post_id ) {
			$meta = get_post_meta( $post_id, '_bepluspb_disable_cache', true );
			if ( ! empty( $meta ) ) {
				self::$page_excluded = true;
				return true;
			}
		}

		$patterns = bepluspb_parse_exclude_list( $opts['cache_exclude_pages'] );
		if ( ! empty( $patterns ) ) {
			$current_url = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$full_url = home_url( $current_url );

			foreach ( $patterns as $pattern ) {
				if ( empty( $pattern ) ) {
					continue;
				}
				if (
					false !== strpos( $current_url, $pattern ) ||
					false !== strpos( $full_url, $pattern )
				) {
					self::$page_excluded = true;
					return true;
				}
			}
		}

		self::$page_excluded = false;
		return false;
	}

	// -------------------------------------------------------------------------
	// URL -> filesystem path
	// -------------------------------------------------------------------------

	/**
	 * Convert a local asset URL to an absolute filesystem path.
	 *
	 * @param  string $src Full URL (may include query string).
	 * @return string|false Absolute path or false.
	 */
	public static function url_to_path( $src ) {
		$url = strtok( $src, '?' );

		$url_nr = preg_replace( '#^https?://#i', '//', $url );

		// Attempt 1: WP_CONTENT_URL -> WP_CONTENT_DIR.
		$content_url_nr = preg_replace( '#^https?://#i', '//', untrailingslashit( WP_CONTENT_URL ) );
		if ( '' !== $content_url_nr && 0 === strpos( $url_nr, $content_url_nr ) ) {
			$path = wp_normalize_path( WP_CONTENT_DIR . substr( $url_nr, strlen( $content_url_nr ) ) );
			if ( file_exists( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		// Attempt 2: site_url() -> ABSPATH.
		$site_url_nr = preg_replace( '#^https?://#i', '//', trailingslashit( site_url() ) );
		if ( '' !== $site_url_nr && 0 === strpos( $url_nr, $site_url_nr ) ) {
			$path = wp_normalize_path( trailingslashit( ABSPATH ) . substr( $url_nr, strlen( $site_url_nr ) ) );
			if ( file_exists( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		// Attempt 3: home_url() -> ABSPATH.
		$home_url_nr = preg_replace( '#^https?://#i', '//', trailingslashit( home_url() ) );
		if ( '' !== $home_url_nr && 0 === strpos( $url_nr, $home_url_nr ) ) {
			$path = wp_normalize_path( trailingslashit( ABSPATH ) . substr( $url_nr, strlen( $home_url_nr ) ) );
			if ( file_exists( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// CSS minification
	// -------------------------------------------------------------------------

	/**
	 * Minify a CSS string.
	 *
	 * @param  string $css Raw CSS content.
	 * @return string Minified CSS.
	 */
	public static function minify_css( $css ) {
		$license_tokens = array();
		$lic_idx        = 0;
		$css = preg_replace_callback(
			'/\/\*![\s\S]*?\*\//',
			function ( $m ) use ( &$license_tokens, &$lic_idx ) {
				$token                    = 'BEPLUSPBLIC' . $lic_idx . 'END';
				$license_tokens[ $token ] = $m[0];
				$lic_idx++;
				return $token;
			},
			$css
		);

		$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};:,>~])\s*/', '$1', $css );
		$css = str_replace( ';}', '}', $css );

		foreach ( $license_tokens as $token => $comment ) {
			$css = str_replace( $token, "\n" . $comment . "\n", $css );
		}

		return trim( $css );
	}

	// -------------------------------------------------------------------------
	// JS minification
	// -------------------------------------------------------------------------

	/**
	 * Minify a JavaScript string.
	 *
	 * @param  string $js Raw JavaScript content.
	 * @return string Minified JavaScript.
	 */
	public static function minify_js( $js ) {
		$js = self::strip_js_comments( $js );
		$js = preg_replace( '/^[ \t]+/m', '', $js );
		$js = preg_replace( '/[ \t]+$/m', '', $js );
		$js = preg_replace( '/\n{3,}/', "\n\n", $js );

		return trim( $js );
	}

	/**
	 * Strip // and block comments from a JavaScript string.
	 *
	 * @param  string $js Raw JavaScript.
	 * @return string JS with comments stripped.
	 */
	private static function strip_js_comments( $js ) {
		return BEPLUSPB_Utils::strip_js_comments( $js, true );
	}

	// -------------------------------------------------------------------------
	// Cache directory management
	// -------------------------------------------------------------------------

	/**
	 * Create the cache directory if it does not already exist.
	 *
	 * @return bool True if the directory exists and is writable after the call.
	 */
	public static function ensure_cache_dir() {
		$dir = BEPLUSPB_CACHE_DIR;

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@file_put_contents(
				$dir . '.htaccess',
				"Options -Indexes\n<FilesMatch \"\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|cgi|sh)$\">\n  Deny from all\n</FilesMatch>\n"
			);
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Delete all cached CSS and JS files from the cache directory.
	 *
	 * @return int Number of files deleted.
	 */
	public static function clear_cache() {
		$dir = BEPLUSPB_CACHE_DIR;

		if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$count = 0;
		$files = glob( $dir . '*.{css,js}', GLOB_BRACE );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
					if ( ! file_exists( $file ) ) {
						$count++;
					}
				}
			}
		}

		delete_transient( 'bepluspb_cache_stats' );

		return $count;
	}

	/**
	 * Return detailed statistics about the cache directory.
	 *
	 * @return array
	 */
	public static function get_cache_stats() {
		$cached = get_transient( 'bepluspb_cache_stats' );
		if ( is_array( $cached ) ) {
			$dir             = BEPLUSPB_CACHE_DIR;
			$cached['exists']   = file_exists( $dir ) && is_dir( $dir );
			$cached['writable'] = ( $cached['exists'] && wp_is_writable( $dir ) );
			return $cached;
		}

		$dir = BEPLUSPB_CACHE_DIR;

		$stats = array(
			'count'    => 0,
			'size'     => 0,
			'oldest'   => false,
			'newest'   => false,
			'exists'   => file_exists( $dir ) && is_dir( $dir ),
			'writable' => ( file_exists( $dir ) && wp_is_writable( $dir ) ),
			'dir'      => $dir,
		);

		if ( ! $stats['exists'] ) {
			return $stats;
		}

		$files = glob( $dir . '*.{css,js}', GLOB_BRACE );
		if ( ! is_array( $files ) || empty( $files ) ) {
			set_transient( 'bepluspb_cache_stats', $stats, 60 );
			return $stats;
		}

		$stats['count'] = count( $files );

		$oldest = PHP_INT_MAX;
		$newest = 0;
		$total  = 0;

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$mtime  = filemtime( $file );
			$total += filesize( $file );
			if ( $mtime < $oldest ) {
				$oldest = $mtime;
			}
			if ( $mtime > $newest ) {
				$newest = $mtime;
			}
		}

		$stats['size']   = $total;
		$stats['oldest'] = ( PHP_INT_MAX !== $oldest ) ? $oldest : false;
		$stats['newest'] = ( 0 !== $newest ) ? $newest : false;

		set_transient( 'bepluspb_cache_stats', $stats, 60 );

		return $stats;
	}

	/**
	 * Format a byte count as a human-readable string.
	 *
	 * @param  int $bytes Number of bytes.
	 * @return string
	 */
	public static function human_filesize( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}
}
