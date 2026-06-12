<?php
/**
 * Fired when the plugin is deleted (not just deactivated).
 *
 * Cleans up ALL data created by Beplus Performance Booster:
 *  - The `bepluspb_settings` option from the database.
 *  - Browser-caching rules from .htaccess.
 *  - All minified/cached CSS and JS files from the cache directory.
 *  - The `_bepluspb_disable_cache` post meta from every post/page.
 *
 * @package Beplus_Performance_Booster
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

delete_option( 'bepluspb_settings' );

// Remove the cache-stats transient (60 s TTL, but clean up explicitly on uninstall).
delete_transient( 'bepluspb_cache_stats' );

// ---------------------------------------------------------------------------
// 2. Remove .htaccess rules
// ---------------------------------------------------------------------------

if ( ! defined( 'BEPLUSPB_PLUGIN_DIR' ) ) {
	define( 'BEPLUSPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

$bepluspb_htaccess_class = BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-htaccess.php';
if ( file_exists( $bepluspb_htaccess_class ) ) {
	require_once $bepluspb_htaccess_class;
	BEPLUSPB_Htaccess::remove_rules();
}

// ---------------------------------------------------------------------------
// 3. Clear the CSS/JS minification cache directory
// ---------------------------------------------------------------------------

if ( ! defined( 'BEPLUSPB_CACHE_DIR' ) ) {
	// Must match the define() in beplus-performance-booster.php exactly.
	define( 'BEPLUSPB_CACHE_DIR', wp_upload_dir()['basedir'] . '/bepluspb-cache/' );
}

$bepluspb_cache_dir = BEPLUSPB_CACHE_DIR;

if ( file_exists( $bepluspb_cache_dir ) && is_dir( $bepluspb_cache_dir ) ) {
	// Delete all cached CSS and JS files.
	$bepluspb_cached_files = glob( $bepluspb_cache_dir . '*.{css,js}', GLOB_BRACE );
	if ( is_array( $bepluspb_cached_files ) ) {
		foreach ( $bepluspb_cached_files as $bepluspb_file ) {
			if ( is_file( $bepluspb_file ) ) {
				wp_delete_file( $bepluspb_file );
			}
		}
	}

	// Remove plugin-created support files.
	foreach ( array( 'index.php', '.htaccess' ) as $bepluspb_support_file ) {
		$bepluspb_path = $bepluspb_cache_dir . $bepluspb_support_file;
		if ( file_exists( $bepluspb_path ) ) {
			wp_delete_file( $bepluspb_path );
		}
	}

	// Remove the directory itself if it is now empty.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->rmdir( $bepluspb_cache_dir );
}

// ---------------------------------------------------------------------------
// 4. Delete _bepluspb_disable_cache post meta from every post
// ---------------------------------------------------------------------------

// Use a direct DB query for efficiency — avoids loading every post into memory.
global $wpdb;

$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->postmeta,
	array( 'meta_key' => '_bepluspb_disable_cache' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup, no alternative
	array( '%s' )
);
