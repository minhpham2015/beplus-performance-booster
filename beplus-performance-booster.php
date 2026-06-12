<?php
/**
 * Plugin Name: Beplus Performance Booster
 * Description: Smart caching, JS/CSS minification, lazy loading, and site cleanup in one lightweight plugin — frontend performance without touching the admin.
 * Version: 1.0.0 
 * Author:      Minh BePlus
 * Author URI:  https://beplusthemes.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beplus-performance-booster
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// CONSTANTS
// ---------------------------------------------------------------------------

define( 'BEPLUSPB_VERSION',     '1.0.0' );
define( 'BEPLUSPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BEPLUSPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );

/**
 * Filesystem path to the CSS/JS minification cache directory.
 *
 * Stored inside wp-content/uploads/ so that managed hosting environments
 * (WP Engine, Kinsta, Pantheon, etc.) which only guarantee the uploads
 * directory is writable can still use the cache. The matching public URL is
 * returned by BEPLUSPB_Minify::cache_url() — computed lazily to guarantee
 * wp_upload_dir() is called after WordPress has fully initialised.
 */
define( 'BEPLUSPB_CACHE_DIR', wp_upload_dir()['basedir'] . '/bepluspb-cache/' );

/**
 * Multisite compatibility note:
 *
 * This plugin is not designed for WordPress Multisite. The BEPLUSPB_OPTIONS_KEY
 * option is stored in the sub-site options table, but the single shared
 * BEPLUSPB_CACHE_DIR path and a single .htaccess block mean that settings from
 * one sub-site affect all others. Network activation is therefore NOT
 * recommended. Install this plugin on individual sub-sites only.
 */

// ---------------------------------------------------------------------------
// SHARED HELPERS
// Global functions available to all classes.
// ---------------------------------------------------------------------------

/**
 * Returns the full set of default option values.
 * All features are OFF by default so the plugin is safe on first activation.
 *
 * @return array
 */
function bepluspb_default_options() {
	return array(
		// --- JavaScript Optimization ---
		'js_delay'               => 0,
		'js_defer'               => 0,
		'js_exclude'             => '',

		// --- CSS Optimization ---
		'css_minify'             => 0,
		'css_non_blocking'       => 0,
		'css_exclude'            => '',

		// --- Lazy Load Images ---
		'lazy_load'              => 0,

		// --- Remove Unused Assets ---
		'remove_emoji'           => 0,
		'remove_embed'           => 0,
		'remove_block_css'       => 0,
		'remove_woo_scripts'     => 0,

		// --- HTML Optimization ---
		'html_minify'            => 0,
		'html_remove_comments'   => 0,
		'html_remove_js_comments'  => 0,
		'html_remove_css_comments' => 0,

		// --- Browser Cache (.htaccess) ---
		'cache_headers'          => 0,

		// --- Lazy Load — Advanced options ---
		'lazy_skip_first_n'      => 1,   // Skip the first N images (LCP / hero)
		'lazy_exclude_class'     => '',  // CSS class names to exclude (comma-separated)
		'lazy_exclude_id'        => '',  // Element IDs to exclude (comma-separated)
		'lazy_exclude_filename'  => '',  // Partial filename strings to exclude (comma-sep)

		// --- File Minification ---
		'minify_css_files'       => 0,   // Minify enqueued CSS files → cache
		'minify_js_files'        => 0,   // Minify enqueued JS files  → cache

		// --- Advanced CSS ---
		'css_inline_all'         => 0,   // Inline all local CSS files into <head>
		'css_remove_handles'     => '',  // CSS handles to dequeue (newline-separated)

		// --- Advanced JS ---
		'remove_js_handles'      => '',  // JS handles to dequeue (newline-separated)

		// --- Font Preload ---
		'font_preload'           => '',  // Font URLs to preload (newline-separated)

		// --- Cache Exclusions ---
		'cache_exclude_pages'    => "/checkout/\n/my-account/\n/cart/",
		'cache_for_logged_in'    => 0,   // Serve cached CSS/JS to logged-in users (default: off)

		// --- Master Cache Switch ---
		'cache_enabled'          => 0,
	);
}

/**
 * Returns saved options merged with defaults.
 *
 * Uses a process-level cache (global variable) so get_option() is only
 * called once per request.
 *
 * @return array
 */
function bepluspb_get_options() {
	global $bepluspb_options_cache;
	if ( isset( $bepluspb_options_cache ) && is_array( $bepluspb_options_cache ) ) {
		return $bepluspb_options_cache;
	}
	$saved              = get_option( BEPLUSPB_OPTIONS_KEY, array() );
	$bepluspb_options_cache = wp_parse_args( $saved, bepluspb_default_options() );
	return $bepluspb_options_cache;
}

/**
 * Invalidate the bepluspb_get_options() cache so the next call re-reads from the DB.
 */
function bepluspb_flush_options_cache() {
	global $bepluspb_options_cache;
	$bepluspb_options_cache = null;
}
add_action( 'update_option_' . BEPLUSPB_OPTIONS_KEY, 'bepluspb_flush_options_cache' );

/**
 * Parse a newline-separated textarea value into a trimmed, filtered array.
 *
 * @param  string $textarea Raw textarea value from settings.
 * @return array
 */
function bepluspb_parse_exclude_list( $textarea ) {
	if ( empty( $textarea ) ) {
		return array();
	}
	$lines = explode( "\n", $textarea );
	return array_values( array_filter( array_map( 'trim', $lines ) ) );
}


// ---------------------------------------------------------------------------
// LOAD CLASS FILES
// ---------------------------------------------------------------------------

require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-utils.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-admin.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-htaccess.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-cleanup.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-css.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-js.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-images.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-html.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-minify.php';

// ---------------------------------------------------------------------------
// ACTIVATION / DEACTIVATION HOOKS
// Must be registered in the main file because they use __FILE__.
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	function () {
		$existing = get_option( BEPLUSPB_OPTIONS_KEY, null );
		if ( null === $existing ) {
			add_option( BEPLUSPB_OPTIONS_KEY, bepluspb_default_options() );
		}

		$opts = bepluspb_get_options();
		if ( $opts['cache_headers'] ) {
			BEPLUSPB_Htaccess::add_rules();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		BEPLUSPB_Htaccess::remove_rules();
	}
);

// ---------------------------------------------------------------------------
// BOOT
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', array( 'BEPLUSPB_Admin', 'init' ) );

/**
 * Auto-clear the CSS/JS cache whenever a plugin or theme update completes.
 *
 * @param WP_Upgrader $upgrader  Upgrader instance.
 * @param array       $hook_extra Information about the update.
 */
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $hook_extra ) {
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			BEPLUSPB_Minify::clear_cache();
		}
	},
	10,
	2
);

/**
 * Early cleanup: remove oEmbed REST route and discovery links.
 */
add_action( 'plugins_loaded', 'bepluspb_early_embed_cleanup', 20 );

function bepluspb_early_embed_cleanup() {
	$opts = bepluspb_get_options();
	if ( ! empty( $opts['remove_embed'] ) ) {
		BEPLUSPB_Cleanup::remove_embed_early();
	}
}

/**
 * Initialise all front-end modules on the 'wp' action.
 */
add_action( 'wp', 'bepluspb_boot_frontend' );

/**
 * Safety-gate for front-end optimisations.
 */
function bepluspb_boot_frontend() {
	if ( is_admin() ) {
		return;
	}

	$opts = bepluspb_get_options();

	if ( empty( $opts['cache_enabled'] ) ) {
		return;
	}

	BEPLUSPB_Minify::init( $opts );

	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		return;
	}

	BEPLUSPB_Cleanup::init( $opts );
	BEPLUSPB_CSS::init( $opts );
	BEPLUSPB_JS::init( $opts );
	BEPLUSPB_Images::init( $opts );
	BEPLUSPB_HTML::init( $opts );
}
