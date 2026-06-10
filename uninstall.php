<?php
/**
 * Fired when the plugin is deleted (not just deactivated).
 *
 * Cleans up ALL data created by Performance Optimizer by BePlus:
 *  - The `pobp_settings` option from the database.
 *  - Browser-caching rules from .htaccess.
 *  - All minified/cached CSS and JS files from the cache directory.
 *  - The `_pobp_disable_cache` post meta from every post/page.
 *
 * @package Performance_Optimizer_BePlus
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// WordPress sets WP_UNINSTALL_PLUGIN before including this file.
// Bail immediately if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Remove plugin options
// ---------------------------------------------------------------------------

delete_option( 'pobp_settings' );

// Remove the cache-stats transient (60 s TTL, but clean up explicitly on uninstall).
delete_transient( 'pobp_cache_stats' );

// ---------------------------------------------------------------------------
// 2. Remove .htaccess rules
// ---------------------------------------------------------------------------

if ( ! defined( 'POBP_PLUGIN_DIR' ) ) {
	define( 'POBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

$pobp_htaccess_class = POBP_PLUGIN_DIR . 'includes/class-pobp-htaccess.php';
if ( file_exists( $pobp_htaccess_class ) ) {
	require_once $pobp_htaccess_class;
	POBP_Htaccess::remove_rules();
}

// ---------------------------------------------------------------------------
// 3. Clear the CSS/JS minification cache directory
// ---------------------------------------------------------------------------

if ( ! defined( 'POBP_CACHE_DIR' ) ) {
	// Must match the define() in performance-optimizer-by-beplus.php exactly.
	define( 'POBP_CACHE_DIR', wp_upload_dir()['basedir'] . '/pobp-cache/' );
}

$pobp_cache_dir = POBP_CACHE_DIR;

if ( file_exists( $pobp_cache_dir ) && is_dir( $pobp_cache_dir ) ) {
	// Delete all cached CSS and JS files.
	$pobp_cached_files = glob( $pobp_cache_dir . '*.{css,js}', GLOB_BRACE );
	if ( is_array( $pobp_cached_files ) ) {
		foreach ( $pobp_cached_files as $pobp_file ) {
			if ( is_file( $pobp_file ) ) {
				wp_delete_file( $pobp_file );
			}
		}
	}

	// Remove plugin-created support files.
	foreach ( array( 'index.php', '.htaccess' ) as $pobp_support_file ) {
		$pobp_path = $pobp_cache_dir . $pobp_support_file;
		if ( file_exists( $pobp_path ) ) {
			wp_delete_file( $pobp_path );
		}
	}

	// Remove the directory itself if it is now empty.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->rmdir( $pobp_cache_dir );
}

// ---------------------------------------------------------------------------
// 4. Delete _pobp_disable_cache post meta from every post
// ---------------------------------------------------------------------------

// Use a direct DB query for efficiency — avoids loading every post into memory.
global $wpdb;

$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->postmeta,
	array( 'meta_key' => '_pobp_disable_cache' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup, no alternative
	array( '%s' )
);
