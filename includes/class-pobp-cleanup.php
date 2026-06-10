<?php
/**
 * Asset cleanup: emoji scripts, wp-embed, Gutenberg block CSS, WooCommerce on non-shop pages.
 *
 * @package Performance_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class POBP_Cleanup
 *
 * Removes unnecessary WordPress default assets from the front-end.
 */
class POBP_Cleanup {

	/**
	 * Register cleanup hooks based on current settings.
	 *
	 * @param array $opts Result of pobp_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['remove_emoji'] ) {
			self::remove_emoji();
		}

		if ( $opts['remove_embed'] ) {
			self::remove_embed_late();
		}

		if ( $opts['remove_block_css'] ) {
			self::remove_block_css();
		}

		if ( $opts['remove_woo_scripts'] ) {
			self::remove_woo_non_shop();
		}
	}

	// -------------------------------------------------------------------------
	// Emoji
	// -------------------------------------------------------------------------

	/**
	 * Unhook all WordPress emoji-related output on the front-end.
	 */
	private static function remove_emoji() {
		remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles',     'print_emoji_styles' );
		remove_action( 'admin_print_styles',  'print_emoji_styles' );
		remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
		remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins',  array( __CLASS__, 'remove_emoji_tinymce' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove the wpemoji plugin from TinyMCE.
	 *
	 * @param  array $plugins List of TinyMCE plugins.
	 * @return array
	 */
	public static function remove_emoji_tinymce( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Remove the emoji SVG origin from DNS prefetch resource hints.
	 *
	 * @param  array  $urls          Array of hint URLs.
	 * @param  string $relation_type Relation type.
	 * @return array
	 */
	public static function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$emoji_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP filter, not a custom hook
			$urls      = array_diff( $urls, array( $emoji_url ) );
		}
		return $urls;
	}

	// -------------------------------------------------------------------------
	// oEmbed / wp-embed
	// -------------------------------------------------------------------------

	/**
	 * Early embed removal: unregister the oEmbed REST endpoint.
	 *
	 * Must be called at plugins_loaded (priority >= 20).
	 */
	public static function remove_embed_early() {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * Late embed removal: dequeue wp-embed script and remove head links.
	 */
	private static function remove_embed_late() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_wp_embed' ) );
	}

	/**
	 * Dequeue the wp-embed script.
	 */
	public static function dequeue_wp_embed() {
		wp_dequeue_script( 'wp-embed' );
	}

	// -------------------------------------------------------------------------
	// Block / Gutenberg CSS
	// -------------------------------------------------------------------------

	/**
	 * Register the dequeue callback for Gutenberg block-library stylesheets.
	 */
	private static function remove_block_css() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_block_styles' ), 100 );
	}

	/**
	 * Dequeue Gutenberg block-library stylesheets on the front-end.
	 */
	public static function dequeue_block_styles() {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'wc-block-style' );
		wp_dequeue_style( 'global-styles' );
	}

	// -------------------------------------------------------------------------
	// WooCommerce on non-shop pages
	// -------------------------------------------------------------------------

	/**
	 * Register the WooCommerce dequeue callback on pages unrelated to the shop.
	 */
	private static function remove_woo_non_shop() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_woo_assets' ), 99 );
	}

	/**
	 * Dequeue WooCommerce scripts and styles on non-shop pages.
	 */
	public static function dequeue_woo_assets() {
		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'woocommerce_frontend_styles' );
		wp_dequeue_style( 'wc-block-style' );
		wp_dequeue_style( 'woocommerce-inline' );

		wp_dequeue_script( 'woocommerce' );
		wp_dequeue_script( 'wc-add-to-cart' );
		wp_dequeue_script( 'wc-single-product' );
	}
}
