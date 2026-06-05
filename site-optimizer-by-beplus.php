<?php
/**
 * Plugin Name: Site Optimizer by BePlus
 * Plugin URI:  https://beplusthemes.com/plugins/site-optimizer-by-beplus/
 * Description: Lightweight WordPress optimizer: smart caching, JS/CSS minification, lazy loading, and cleanup. Front-end only — admin is never affected.
 * Version:     1.0.0
 * Author:      BePlus
 * Author URI:  https://beplusthemes.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-optimizer-by-beplus-master
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

define( 'SOB_VERSION',     '1.0.0' );
define( 'SOB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SOB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SOB_OPTIONS_KEY', 'sob_settings' );

/**
 * Filesystem path to the CSS/JS minification cache directory.
 *
 * Stored inside wp-content/cache/ rather than the WordPress root so that
 * managed hosting environments (WP Engine, Kinsta, Pantheon, etc.) which
 * make only wp-content writable can still use the cache. The matching public
 * URL is returned by SOB_Minify::cache_url() — computed lazily to guarantee
 * WP_CONTENT_URL is available when it is first called.
 *
 * IMP-1: Changed from ABSPATH . 'cache/sob-cache/' to WP_CONTENT_DIR so
 * the path and URL are always in sync regardless of installation layout.
 */
define( 'SOB_CACHE_DIR', WP_CONTENT_DIR . '/cache/sob-cache/' );

/**
 * Multisite compatibility note (IMP-7):
 *
 * This plugin is not designed for WordPress Multisite. The SOB_OPTIONS_KEY
 * option is stored in the sub-site options table, but the single shared
 * SOB_CACHE_DIR path and a single .htaccess block mean that settings from
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
function sob_default_options() {
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
		// Default: exclude WooCommerce-style transactional pages where minified
		// CSS/JS could break cart/checkout/account UX. Users can edit this list
		// in Settings → Cache Exclusions.
		'cache_exclude_pages'    => "/checkout/\n/my-account/\n/cart/",
		'cache_for_logged_in'    => 0,   // Serve cached CSS/JS to logged-in users (default: off)

		// --- Master Cache Switch ---
		// Default: OFF on first install. The user explicitly enables it from
		// the Dashboard toggle after they have reviewed the settings so we
		// never run optimisations on a site without explicit consent.
		'cache_enabled'          => 0,
	);
}

/**
 * Returns saved options merged with defaults.
 *
 * Uses a process-level cache (global variable) so get_option() is only
 * called once per request, no matter how many times this function is
 * invoked (e.g. once per <script> tag when the defer filter runs).
 *
 * Call sob_flush_options_cache() to reset the cache within the same request
 * (needed after options are programmatically updated outside the Settings API).
 *
 * @return array
 */
function sob_get_options() {
	global $sob_options_cache;
	if ( isset( $sob_options_cache ) && is_array( $sob_options_cache ) ) {
		return $sob_options_cache;
	}
	$saved             = get_option( SOB_OPTIONS_KEY, array() );
	$sob_options_cache = wp_parse_args( $saved, sob_default_options() );
	return $sob_options_cache;
}

/**
 * Invalidate the sob_get_options() cache so the next call re-reads from the DB.
 *
 * Hooked to 'update_option_' . SOB_OPTIONS_KEY to auto-flush whenever the
 * Settings API writes new values.
 */
function sob_flush_options_cache() {
	global $sob_options_cache;
	$sob_options_cache = null;
}
add_action( 'update_option_' . SOB_OPTIONS_KEY, 'sob_flush_options_cache' );

/**
 * Parse a newline-separated textarea value into a trimmed, filtered array.
 *
 * @param  string $textarea Raw textarea value from settings.
 * @return array
 */
function sob_parse_exclude_list( $textarea ) {
	if ( empty( $textarea ) ) {
		return array();
	}
	$lines = explode( "\n", $textarea );
	return array_values( array_filter( array_map( 'trim', $lines ) ) );
}


// ---------------------------------------------------------------------------
// LOAD CLASS FILES
// ---------------------------------------------------------------------------

require_once SOB_PLUGIN_DIR . 'includes/class-sob-utils.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-admin.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-htaccess.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-cleanup.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-css.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-js.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-images.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-html.php';
require_once SOB_PLUGIN_DIR . 'includes/class-sob-minify.php';

// ---------------------------------------------------------------------------
// ACTIVATION / DEACTIVATION HOOKS
// Must be registered in the main file because they use __FILE__.
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	function () {
		// Seed the option row with the safe defaults on first activation so the
		// master cache toggle is explicitly OFF and exclusion paths are present
		// in the DB (rather than only living in sob_default_options()).
		$existing = get_option( SOB_OPTIONS_KEY, null );
		if ( null === $existing ) {
			add_option( SOB_OPTIONS_KEY, sob_default_options() );
		}

		$opts = sob_get_options();
		if ( $opts['cache_headers'] ) {
			SOB_Htaccess::add_rules();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		SOB_Htaccess::remove_rules();
	}
);

// ---------------------------------------------------------------------------
// BOOT
// ---------------------------------------------------------------------------

/**
 * Initialise the admin module as soon as all plugins are loaded.
 */
add_action( 'plugins_loaded', array( 'SOB_Admin', 'init' ) );

/**
 * Auto-clear the CSS/JS cache whenever a plugin or theme update completes.
 *
 * IMP-2: After an update, minified copies of old plugin/theme files can
 * remain in the cache and be served to visitors even though the source files
 * have changed. Clearing on upgrader_process_complete ensures visitors
 * immediately receive the newly updated assets.
 *
 * @param WP_Upgrader $upgrader  Upgrader instance.
 * @param array       $hook_extra Information about the update.
 */
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $hook_extra ) {
		// Only clear for plugin or theme updates, not translation packs.
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			SOB_Minify::clear_cache();
		}
	},
	10,
	2
);

/**
 * Early cleanup: remove oEmbed REST route and discovery links.
 *
 * Must run before 'parse_request' (where rest_api_init fires) so that
 * remove_action( 'rest_api_init', 'wp_oembed_register_route' ) is effective.
 * plugins_loaded fires well before parse_request.
 *
 * Note: is_admin() / is_user_logged_in() are NOT available at plugins_loaded,
 * so we only run this cleanup on non-admin, non-REST-API requests by checking
 * the request URI.  The REST namespace guard means this has no effect on the
 * REST API itself anyway.
 */
add_action( 'plugins_loaded', 'sob_early_embed_cleanup', 20 );

function sob_early_embed_cleanup() {
	$opts = sob_get_options();
	if ( ! empty( $opts['remove_embed'] ) ) {
		SOB_Cleanup::remove_embed_early();
	}
}

/**
 * Initialise all front-end modules on the 'wp' action.
 *
 * 'wp' fires after the query is set up and the user is authenticated,
 * which lets us safely call is_user_logged_in() and current_user_can().
 * All front-end hooks are registered inside this callback.
 */
add_action( 'wp', 'sob_boot_frontend' );

/**
 * Safety-gate for front-end optimisations.
 *
 * File minification (CSS/JS → disk cache) runs for ALL visitors including
 * logged-in administrators — it is transparent (same visual output, smaller
 * files) and safe to run during admin testing sessions.
 *
 * All other behavioural transforms (JS delay/defer, CSS preload swaps, HTML
 * minification, lazy-loading, asset removal) are skipped for administrators
 * to prevent breaking their editing experience.
 */
function sob_boot_frontend() {
	// Never run on wp-admin page requests.
	if ( is_admin() ) {
		return;
	}

	$opts = sob_get_options();

	// Master switch — when disabled, skip ALL front-end optimisations.
	// Admin UI, admin bar panel, and .htaccess rules are unaffected
	// (they are registered outside this function).
	if ( empty( $opts['cache_enabled'] ) ) {
		return;
	}

	// File minification runs for everyone — it just writes smaller copies of
	// the same CSS/JS to disk and swaps the URL.  Admins can verify it works
	// by checking the Dashboard tab for cached file counts.
	SOB_Minify::init( $opts );

	// All other optimisations skip logged-in administrators.
	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		return;
	}

	SOB_Cleanup::init( $opts );
	SOB_CSS::init( $opts );
	SOB_JS::init( $opts );
	SOB_Images::init( $opts );
	SOB_HTML::init( $opts );
}
