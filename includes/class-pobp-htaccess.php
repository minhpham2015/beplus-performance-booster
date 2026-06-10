<?php
/**
 * .htaccess browser-cache and compression rules manager.
 *
 * @package Performance_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class POBP_Htaccess
 *
 * Safely inserts and removes browser-caching and Gzip/Brotli compression
 * directives from the root .htaccess file using WordPress's built-in
 * insert_with_markers() helper.
 */
class POBP_Htaccess {

	/**
	 * Unique marker string used by insert_with_markers() to identify our block.
	 *
	 * @var string
	 */
	const MARKER = 'Performance Optimizer by BePlus';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Insert browser-caching and compression rules into .htaccess.
	 *
	 * @return bool True on success, false if the file is not writable or missing.
	 */
	public static function add_rules() {
		self::load_wp_admin_helpers();
		$htaccess = self::htaccess_path();

		if ( ! file_exists( $htaccess ) ) {
			return false;
		}

		if ( ! wp_is_writable( $htaccess ) ) {
			return false;
		}

		return insert_with_markers( $htaccess, self::MARKER, self::get_rules() );
	}

	/**
	 * Remove the plugin's rules from .htaccess.
	 *
	 * @return bool True on success, false if the file cannot be written.
	 */
	public static function remove_rules() {
		self::load_wp_admin_helpers();
		$htaccess = self::htaccess_path();

		if ( ! file_exists( $htaccess ) || ! wp_is_writable( $htaccess ) ) {
			return false;
		}

		return insert_with_markers( $htaccess, self::MARKER, array() );
	}

	/**
	 * Check whether the root .htaccess file is writable.
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$htaccess = self::htaccess_path();
		return file_exists( $htaccess ) && wp_is_writable( $htaccess );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the absolute path to the root .htaccess file.
	 *
	 * @return string Absolute filesystem path.
	 */
	private static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return get_home_path() . '.htaccess';
	}

	/**
	 * Ensure the WordPress functions needed to manipulate .htaccess are loaded.
	 */
	private static function load_wp_admin_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/**
	 * Build and return the array of .htaccess directive lines to insert.
	 *
	 * @return string[] Lines of .htaccess directives.
	 */
	private static function get_rules() {
		return array(
			'# ---------------------------------------------------------',
			'# Gzip Compression',
			'# ---------------------------------------------------------',
			'<IfModule mod_deflate.c>',
			'  AddOutputFilterByType DEFLATE text/html text/plain text/xml',
			'  AddOutputFilterByType DEFLATE text/css',
			'  AddOutputFilterByType DEFLATE text/javascript application/javascript application/x-javascript',
			'  AddOutputFilterByType DEFLATE application/json application/xml application/xhtml+xml',
			'  AddOutputFilterByType DEFLATE image/svg+xml',
			'  AddOutputFilterByType DEFLATE font/ttf font/otf font/woff font/woff2',
			'  AddOutputFilterByType DEFLATE application/font-woff application/font-woff2',
			'</IfModule>',
			'',
			'# ---------------------------------------------------------',
			'# Brotli Compression (Apache 2.4.26+ with mod_brotli)',
			'# ---------------------------------------------------------',
			'<IfModule mod_brotli.c>',
			'  AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/xml',
			'  AddOutputFilterByType BROTLI_COMPRESS text/css',
			'  AddOutputFilterByType BROTLI_COMPRESS text/javascript application/javascript application/x-javascript',
			'  AddOutputFilterByType BROTLI_COMPRESS application/json image/svg+xml',
			'</IfModule>',
			'',
			'# ---------------------------------------------------------',
			'# Browser Cache — Expires Headers',
			'# ---------------------------------------------------------',
			'<IfModule mod_expires.c>',
			'  ExpiresActive On',
			'  ExpiresDefault                          "access plus 1 month"',
			'',
			'  # HTML / dynamic responses — never cache',
			'  ExpiresByType text/html                 "access plus 0 seconds"',
			'  ExpiresByType text/xml                  "access plus 0 seconds"',
			'  ExpiresByType application/json          "access plus 0 seconds"',
			'  ExpiresByType application/xml           "access plus 0 seconds"',
			'',
			'  # Stylesheets and scripts — 1 year (use cache-busting query strings)',
			'  ExpiresByType text/css                  "access plus 1 year"',
			'  ExpiresByType application/javascript    "access plus 1 year"',
			'  ExpiresByType text/javascript           "access plus 1 year"',
			'',
			'  # Images',
			'  ExpiresByType image/jpeg                "access plus 1 year"',
			'  ExpiresByType image/gif                 "access plus 1 year"',
			'  ExpiresByType image/png                 "access plus 1 year"',
			'  ExpiresByType image/webp                "access plus 1 year"',
			'  ExpiresByType image/avif                "access plus 1 year"',
			'  ExpiresByType image/svg+xml             "access plus 1 year"',
			'  ExpiresByType image/x-icon              "access plus 1 year"',
			'',
			'  # Fonts',
			'  ExpiresByType font/ttf                  "access plus 1 year"',
			'  ExpiresByType font/otf                  "access plus 1 year"',
			'  ExpiresByType font/woff                 "access plus 1 year"',
			'  ExpiresByType font/woff2                "access plus 1 year"',
			'  ExpiresByType application/font-woff     "access plus 1 year"',
			'  ExpiresByType application/font-woff2    "access plus 1 year"',
			'</IfModule>',
			'',
			'# ---------------------------------------------------------',
			'# Browser Cache — Cache-Control Headers',
			'# ---------------------------------------------------------',
			'<IfModule mod_headers.c>',
			'  # Static assets: 1-year immutable cache',
			'  <FilesMatch "\.(ico|jpg|jpeg|png|gif|webp|avif|svg|woff|woff2|ttf|otf|eot)$">',
			'    Header set Cache-Control "max-age=31536000, public, immutable"',
			'  </FilesMatch>',
			'',
			'  # CSS and JS: 1-year immutable cache (WordPress appends ?ver= for busting)',
			'  <FilesMatch "\.(css|js)$">',
			'    Header set Cache-Control "max-age=31536000, public, immutable"',
			'  </FilesMatch>',
			'',
			'  # HTML and PHP: always revalidate',
			'  <FilesMatch "\.(html|htm|php)$">',
			'    Header set Cache-Control "no-cache, must-revalidate"',
			'  </FilesMatch>',
			'</IfModule>',
		);
	}
}
