<?php
/**
 * CSS and JS file minification with disk caching.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_Minify
 *
 * Intercepts every enqueued CSS and JS file via WordPress's
 * `style_loader_src` and `script_loader_src` filters, minifies the content
 * in PHP, writes the result to a cache directory, and returns the cached
 * file's public URL so the browser loads the smaller version.
 *
 * Cache strategy
 * --------------
 * - Cache directory : WP_CONTENT_DIR/cache/sob-cache/
 * - Cache filename  : {handle}-{md5_12}.{ext}
 *   The 12-character MD5 prefix is computed from the FILE CONTENT, so the
 *   cache busts itself automatically whenever the source file changes.
 * - Already-minified files (*.min.css, *.min.js) are skipped.
 * - External URLs (CDN, Google Fonts, etc.) are skipped.
 * - If the cache directory is not writable the original URL is returned
 *   unchanged -- the feature degrades gracefully.
 *
 * Per-page disable
 * ----------------
 * If the current post/page has the `_sob_disable_cache` meta flag set the
 * minification is skipped for that specific request.
 *
 * CSS minification
 * ----------------
 *  - Block comments removed (license comments of the form: slash-bang ... star-slash are preserved).
 *  - Whitespace collapsed.
 *  - Spaces around structural characters ({};:,>~+[]) removed.
 *  - Trailing semicolons before } removed.
 *
 * JS minification
 * ---------------
 * JS is complex enough that naive regex replacement can break code.  A
 * character-by-character comment stripper (adapted from SOB_HTML) is used
 * to safely remove single-line and block comments while respecting string literals
 * and template literals.  After comment removal, leading/trailing whitespace
 * is trimmed from each line and consecutive blank lines are collapsed.
 * Lines are NOT joined (prevents ASI breakage).
 */
class SOB_Minify {

	/**
	 * Per-request cache for the "is page cache disabled?" lookup.
	 * Avoids a repeated get_post_meta() call per enqueued asset.
	 *
	 * @var bool|null  null = not yet checked.
	 */
	private static $page_cache_disabled = null;

	/**
	 * Per-request cache for the page-exclusion-only check (no user login check).
	 * Used by file minification so cache files are always written to disk even
	 * when the current user is logged in — the cached URL is withheld from
	 * logged-in users separately, not the file creation itself.
	 *
	 * @var bool|null  null = not yet checked.
	 */
	private static $page_excluded = null;

	/**
	 * Cached public URL for the cache directory.
	 * Populated on first call to cache_url().
	 *
	 * @var string|null
	 */
	private static $cache_url = null;

	// -------------------------------------------------------------------------
	// Cache URL helper (lazy so home_url() is always available)
	// -------------------------------------------------------------------------

	/**
	 * Return the public URL of the cache directory, with trailing slash.
	 *
	 * Computed once and memoised. Using a method instead of a define() constant
	 * guarantees home_url() is called after WordPress has fully loaded its
	 * options -- avoiding any risk of an undefined or incorrect value at
	 * plugin-file-load time.
	 *
	 * @return string e.g. http://example.com/cache/sob-cache/
	 */
	public static function cache_url() {
		if ( null === self::$cache_url ) {
			// IMP-1: Use WP_CONTENT_URL so the URL matches WP_CONTENT_DIR/cache/sob-cache/
			// regardless of whether WordPress is installed in a subdirectory.
			self::$cache_url = trailingslashit( WP_CONTENT_URL . '/cache/sob-cache' );
		}
		return self::$cache_url;
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register style/script src filters when minification is enabled.
	 * Called from sob_boot_frontend() on the 'wp' action.
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		// Master cache switch — bail immediately if globally disabled.
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}

		// Reset per-request flags.
		self::$page_cache_disabled = null;
		self::$page_excluded       = null;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$css_on = $opts['minify_css_files'] ? 'ON' : 'OFF';
			$js_on  = $opts['minify_js_files']  ? 'ON' : 'OFF';
			error_log( "[SOB] Minify::init() -- CSS:{$css_on} JS:{$js_on} CACHE_DIR:" . SOB_CACHE_DIR ); // phpcs:ignore
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
		// Hard bail: page is excluded via per-page meta or URL pattern.
		// Does NOT check login status — we always write the cache file to disk
		// so guest visitors benefit even when admins browse the site first.
		if ( self::is_page_excluded() ) {
			return $src;
		}

		// Skip already-minified files.
		if ( false !== strpos( $src, '.min.css' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] CSS skip (pre-minified): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		$file_path = self::url_to_path( $src );
		if ( ! $file_path ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] CSS skip (url_to_path failed): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src; // External or unresolvable URL.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $file_path );
		if ( false === $content || '' === trim( $content ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] CSS skip (unreadable): {$handle} -> {$file_path}" ); // phpcs:ignore
			}
			return $src;
		}

		$hash       = substr( md5( $content ), 0, 12 );
		// BUG-5: sanitize_file_name() prevents path traversal if a handle contains
		// slashes, dots, or other filesystem-special characters.
		$cache_name = sanitize_file_name( $handle ) . '-' . $hash . '.css';
		$cache_file = SOB_CACHE_DIR . $cache_name;
		$cache_url  = self::cache_url() . $cache_name;

		if ( ! file_exists( $cache_file ) ) {
			if ( ! self::ensure_cache_dir() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[SOB] CSS skip (cache dir not writable): " . SOB_CACHE_DIR ); // phpcs:ignore
				}
				return $src; // Can't write -- fall back to original.
			}
			$minified = self::minify_css( $content );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$written = @file_put_contents( $cache_file, $minified );
			if ( false === $written ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[SOB] CSS WRITE FAILED: {$cache_file}" ); // phpcs:ignore
				}
				return $src;
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] CSS cached: {$handle} -> {$cache_file} ({$written} bytes)" ); // phpcs:ignore
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] CSS cache hit: {$handle} -> {$cache_name}" ); // phpcs:ignore
			}
		}

		// Soft bail: serve the original URL to logged-in users unless the
		// "Cache for Logged-In Users" option is explicitly enabled.
		// The cache file was still written above so guest visitors benefit.
		$opts = sob_get_options();
		if ( empty( $opts['cache_for_logged_in'] ) && is_user_logged_in() ) {
			return $src;
		}

		// Preserve the original ?ver= query string for WordPress's own cache-busting.
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
		// Hard bail: page is excluded via per-page meta or URL pattern.
		if ( self::is_page_excluded() ) {
			return $src;
		}

		// Skip already-minified files.
		if ( false !== strpos( $src, '.min.js' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] JS skip (pre-minified): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		// SOB's own inline scripts are never enqueued via wp_enqueue_script,
		// but guard against it just in case.
		if ( in_array( $handle, array( 'sob-lazy-fallback', 'sob-delay-js' ), true ) ) {
			return $src;
		}

		$file_path = self::url_to_path( $src );
		if ( ! $file_path ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] JS skip (url_to_path failed): {$handle} -> {$src}" ); // phpcs:ignore
			}
			return $src;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $file_path );
		if ( false === $content || '' === trim( $content ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] JS skip (unreadable): {$handle} -> {$file_path}" ); // phpcs:ignore
			}
			return $src;
		}

		$hash       = substr( md5( $content ), 0, 12 );
		$cache_name = sanitize_file_name( $handle ) . '-' . $hash . '.js';
		$cache_file = SOB_CACHE_DIR . $cache_name;
		$cache_url  = self::cache_url() . $cache_name;

		if ( ! file_exists( $cache_file ) ) {
			if ( ! self::ensure_cache_dir() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[SOB] JS skip (cache dir not writable): " . SOB_CACHE_DIR ); // phpcs:ignore
				}
				return $src;
			}
			$minified = self::minify_js( $content );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$written = @file_put_contents( $cache_file, $minified );
			if ( false === $written ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "[SOB] JS WRITE FAILED: {$cache_file}" ); // phpcs:ignore
				}
				return $src;
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] JS cached: {$handle} -> {$cache_file} ({$written} bytes)" ); // phpcs:ignore
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( "[SOB] JS cache hit: {$handle} -> {$cache_name}" ); // phpcs:ignore
			}
		}

		// Soft bail: serve original URL to logged-in users unless explicitly enabled.
		$opts = sob_get_options();
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
	 * Two conditions trigger a skip:
	 *  1. The _sob_disable_cache post meta flag is set on the current post/page.
	 *  2. The current request URL matches one of the patterns in the
	 *     `cache_exclude_pages` global setting (partial path match).
	 *
	 * Uses a static cache so get_queried_object_id(), get_post_meta(), and the
	 * URL pattern check run at most once per request.
	 *
	 * @return bool
	 */
	public static function is_cache_disabled_for_page() {
		if ( null !== self::$page_cache_disabled ) {
			return self::$page_cache_disabled;
		}

		$opts = sob_get_options();

		// Check 1: logged-in user bypass.
		// When cache_for_logged_in is OFF (default), skip caching for any
		// logged-in user -- pages may contain personalised content.
		if ( empty( $opts['cache_for_logged_in'] ) && is_user_logged_in() ) {
			self::$page_cache_disabled = true;
			return true;
		}

		// Check 2: per-post meta flag (_sob_disable_cache).
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			$meta = get_post_meta( $post_id, '_sob_disable_cache', true );
			if ( ! empty( $meta ) ) {
				self::$page_cache_disabled = true;
				return true;
			}
		}

		// Check 3: global URL pattern list (cache_exclude_pages).
		$patterns = sob_parse_exclude_list( $opts['cache_exclude_pages'] );
		if ( ! empty( $patterns ) ) {
			// Current request URI (path only -- no scheme/host).
			$current_url = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';

			// Also match against full URL for patterns that include the domain.
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
	 * Used by file minification (maybe_minify_css / maybe_minify_js) so that
	 * cache files are always written to disk even when an admin is browsing.
	 * The decision of whether to SERVE the cached URL to the current user is
	 * handled separately at the end of each filter callback.
	 *
	 * @return bool
	 */
	private static function is_page_excluded() {
		if ( null !== self::$page_excluded ) {
			return self::$page_excluded;
		}

		$opts = sob_get_options();

		// Check 1: per-post meta flag (_sob_disable_cache).
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			$meta = get_post_meta( $post_id, '_sob_disable_cache', true );
			if ( ! empty( $meta ) ) {
				self::$page_excluded = true;
				return true;
			}
		}

		// Check 2: global URL pattern list (cache_exclude_pages).
		$patterns = sob_parse_exclude_list( $opts['cache_exclude_pages'] );
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
	 * Handles common WordPress configurations:
	 *  - Standard installation (WordPress in root).
	 *  - WordPress in a subdirectory with wp-content elsewhere.
	 *  - Custom WP_CONTENT_DIR / WP_CONTENT_URL constants.
	 *
	 * Returns false for external URLs (CDN, Google Fonts, etc.) and for
	 * URLs that cannot be resolved to an existing file.
	 *
	 * @param  string $src Full URL (may include query string).
	 * @return string|false Absolute path or false.
	 */
	public static function url_to_path( $src ) {
		// Strip query string (?ver=...).
		$url = strtok( $src, '?' );

		// Normalize to scheme-relative (//) so http vs https mismatches don't
		// prevent resolution. This is the most common failure on dev environments
		// (Local WP, DDEV, etc.) where site_url() might return http:// but
		// the asset URL was registered with https://.
		$url_nr = preg_replace( '#^https?://#i', '//', $url );

		// Attempt 1: WP_CONTENT_URL -> WP_CONTENT_DIR (most common case).
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

		// Attempt 3: home_url() -> ABSPATH (handles WordPress in a subdirectory).
		$home_url_nr = preg_replace( '#^https?://#i', '//', trailingslashit( home_url() ) );
		if ( '' !== $home_url_nr && 0 === strpos( $url_nr, $home_url_nr ) ) {
			$path = wp_normalize_path( trailingslashit( ABSPATH ) . substr( $url_nr, strlen( $home_url_nr ) ) );
			if ( file_exists( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		return false; // External URL or unresolvable.
	}

	// -------------------------------------------------------------------------
	// CSS minification
	// -------------------------------------------------------------------------

	/**
	 * Minify a CSS string.
	 *
	 * - Preserves `/*!` license / copyright comments.
	 * - Removes all other block comments.
	 * - Collapses whitespace.
	 * - Removes whitespace around structural characters.
	 * - Removes trailing semicolons before closing braces.
	 *
	 * @param  string $css Raw CSS content.
	 * @return string Minified CSS.
	 */
	public static function minify_css( $css ) {
		// Step 1: protect /*! license comments with tokens.
		$license_tokens = array();
		$lic_idx        = 0;
		$css = preg_replace_callback(
			'/\/\*![\s\S]*?\*\//',
			function ( $m ) use ( &$license_tokens, &$lic_idx ) {
				$token                    = 'SOBLIC' . $lic_idx . 'END';
				$license_tokens[ $token ] = $m[0];
				$lic_idx++;
				return $token;
			},
			$css
		);

		// Step 2: strip all other block comments.
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );

		// Step 3: collapse whitespace (tabs, newlines -> single space).
		$css = preg_replace( '/\s+/', ' ', $css );

		// Step 4: remove whitespace around structural characters.
		// `+`  is excluded -- spaces around + are required inside calc() expressions.
		// `[]` is excluded -- removing space before [ would collapse descendant
		//       attribute selectors: `div [class]` -> `div[class]` (different meaning).
		// `()` is excluded -- function-call parens are already tight in practice.
		$css = preg_replace( '/\s*([{};:,>~])\s*/', '$1', $css );

		// Step 5: remove trailing semicolons before closing braces.
		$css = str_replace( ';}', '}', $css );

		// Step 6: restore license comments.
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
	 * Uses a safe character-by-character approach to strip comments
	 * (single-line and block comments) without touching string literals,
	 * template literals, or URL protocol slashes (https://).
	 *
	 * After comment removal:
	 *  - Leading and trailing whitespace is stripped from each line.
	 *  - Consecutive blank lines are collapsed to at most one blank line.
	 *
	 * Lines are NOT joined to avoid breaking JavaScript's Automatic Semicolon
	 * Insertion (ASI) rules.
	 *
	 * @param  string $js Raw JavaScript content.
	 * @return string Minified JavaScript.
	 */
	public static function minify_js( $js ) {
		// Step 1: strip comments using a safe character-by-character walker.
		$js = self::strip_js_comments( $js );

		// Step 2: per-line whitespace cleanup.
		$js = preg_replace( '/^[ \t]+/m', '', $js );  // Leading whitespace.
		$js = preg_replace( '/[ \t]+$/m', '', $js );  // Trailing whitespace.

		// Step 3: collapse consecutive blank lines (more than 2 -> 1).
		$js = preg_replace( '/\n{3,}/', "\n\n", $js );

		return trim( $js );
	}

	/**
	 * Strip // and block comments from a JavaScript string.
	 *
	 * Delegates to SOB_Utils::strip_js_comments() so both the file minifier
	 * and the HTML transformer share identical, single-source-of-truth logic.
	 *
	 * @param  string $js Raw JavaScript.
	 * @return string JS with comments stripped.
	 */
	private static function strip_js_comments( $js ) {
		return SOB_Utils::strip_js_comments( $js, true );
	}

	// -------------------------------------------------------------------------
	// Cache directory management
	// -------------------------------------------------------------------------

	/**
	 * Create the cache directory if it does not already exist.
	 *
	 * Also places an index.php stub to prevent directory listing.
	 *
	 * @return bool True if the directory exists and is writable after the call.
	 */
	public static function ensure_cache_dir() {
		$dir = SOB_CACHE_DIR;

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			// Prevent directory listing.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );

			// .htaccess: deny direct access to the cache files (optional safety layer).
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
		$dir = SOB_CACHE_DIR;

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

		// PERF-2: Invalidate the cached stats transient so the next request
		// reflects the now-empty directory without waiting for the TTL to expire.
		delete_transient( 'sob_cache_stats' );

		return $count;
	}

	/**
	 * Return detailed statistics about the cache directory.
	 *
	 * @return array {
	 *     @type int         $count   Number of cached CSS/JS files.
	 *     @type int         $size    Total size in bytes.
	 *     @type int|false   $oldest  Unix timestamp of oldest file, or false.
	 *     @type int|false   $newest  Unix timestamp of newest file, or false.
	 *     @type bool        $exists  Whether the cache directory exists.
	 *     @type bool        $writable Whether the cache directory is writable.
	 *     @type string      $dir     Filesystem path to the cache directory.
	 * }
	 */
	public static function get_cache_stats() {
		// PERF-2: Cache the glob results for 60 seconds. glob() on a large cache
		// directory can be slow; the dashboard calls this on every page load.
		$cached = get_transient( 'sob_cache_stats' );
		if ( is_array( $cached ) ) {
			// Re-evaluate dynamic flags on every call (directory could be deleted).
			$dir             = SOB_CACHE_DIR;
			$cached['exists']   = file_exists( $dir ) && is_dir( $dir );
			$cached['writable'] = ( $cached['exists'] && wp_is_writable( $dir ) );
			return $cached;
		}

		$dir = SOB_CACHE_DIR;

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
			set_transient( 'sob_cache_stats', $stats, 60 );
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

		set_transient( 'sob_cache_stats', $stats, 60 );

		return $stats;
	}

	/**
	 * Format a byte count as a human-readable string.
	 *
	 * @param  int $bytes Number of bytes.
	 * @return string     e.g. "1.23 MB", "456 KB", "12 B".
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
