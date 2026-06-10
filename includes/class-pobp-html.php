<?php
/**
 * HTML output optimization: whitespace minification and comment removal.
 *
 * @package Performance_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class POBP_HTML
 *
 * Buffers the entire page output (template_redirect → shutdown) and applies
 * one or more of the following transformations before sending to the browser:
 *
 *  1. Strip inline JS comments  — removes // and block comments from <script>.
 *  2. Strip inline CSS comments — removes block comments from <style>.
 *  3. Strip HTML comments       — removes <!-- ... --> from the markup.
 *  4. Minify HTML whitespace    — collapses redundant spaces/newlines between tags.
 */
class POBP_HTML {

	/**
	 * Whether buffer_start() successfully called ob_start().
	 *
	 * Tracks whether we actually started a buffer so that buffer_end() does
	 * not accidentally clean a buffer opened by another plugin in cases where
	 * buffer_start() returned early (REST_REQUEST, JSON response, etc.).
	 *
	 * @var bool
	 */
	private static $buffer_started = false;

	/**
	 * Register a full-page output buffer when at least one HTML feature is active.
	 *
	 * @param array $opts Result of pobp_get_options().
	 */
	public static function init( $opts ) {
		$any_active = $opts['html_minify']
		           || $opts['html_remove_comments']
		           || $opts['html_remove_js_comments']
		           || $opts['html_remove_css_comments'];

		if ( ! $any_active ) {
			return;
		}

		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 0 );
		add_action( 'shutdown',          array( __CLASS__, 'buffer_end' ),   0 );
	}

	/**
	 * Open the output buffer.
	 *
	 * Bails early for REST API and JSON requests — wrapping their responses in
	 * an HTML output buffer would corrupt the JSON payload.
	 * Sets self::$buffer_started to true only when ob_start() is actually called
	 * so that buffer_end() knows whether it has a buffer to clean.
	 */
	public static function buffer_start() {
		self::$buffer_started = false;

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		ob_start();
		self::$buffer_started = true;
	}

	/**
	 * Close the buffer, apply transforms in order, and echo the final HTML.
	 */
	public static function buffer_end() {
		// Only close the buffer if we actually opened one in buffer_start().
		if ( ! self::$buffer_started ) {
			return;
		}

		if ( ob_get_level() === 0 ) {
			return;
		}

		$html = ob_get_clean();
		self::$buffer_started = false;

		if ( empty( $html ) ) {
			return;
		}

		$opts = pobp_get_options();

		if ( $opts['html_remove_js_comments'] ) {
			$html = self::strip_inline_js_comments( $html );
		}

		if ( $opts['html_remove_css_comments'] ) {
			$html = self::strip_inline_css_comments( $html );
		}

		if ( $opts['html_remove_comments'] ) {
			$html = self::strip_html_comments( $html );
		}

		if ( $opts['html_minify'] ) {
			$html = self::minify( $html );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	// -------------------------------------------------------------------------
	// Transform: strip HTML comments
	// -------------------------------------------------------------------------

	/**
	 * Remove HTML comments from the page markup.
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with comments removed.
	 */
	public static function strip_html_comments( $html ) {
		$placeholders = array();
		$index        = 0;

		$html = preg_replace_callback(
			'/<(script|style)[^>]*>[\s\S]*?<\/\1>/i',
			function ( $m ) use ( &$placeholders, &$index ) {
				$token                  = 'POBPHC' . $index . 'END';
				$placeholders[ $token ] = $m[0];
				$index++;
				return $token;
			},
			$html
		);

		$html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );

		foreach ( $placeholders as $token => $original ) {
			$html = str_replace( $token, $original, $html );
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Transform: strip inline JS comments
	// -------------------------------------------------------------------------

	/**
	 * Remove single-line and block comments from every inline <script> block.
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with inline script comments removed.
	 */
	public static function strip_inline_js_comments( $html ) {
		return preg_replace_callback(
			'/<script([^>]*)>([\s\S]*?)<\/script>/i',
			function ( $matches ) {
				$attrs = $matches[1];
				$js    = $matches[2];

				if ( preg_match( '/\bsrc\s*=/i', $attrs ) ) {
					return $matches[0];
				}

				return '<script' . $attrs . '>' . self::remove_js_comments( $js ) . '</script>';
			},
			$html
		);
	}

	/**
	 * Walk a JS string and strip comments without touching string/regex content.
	 *
	 * @param  string $js Raw JavaScript source.
	 * @return string JS with comments removed.
	 */
	private static function remove_js_comments( $js ) {
		return POBP_Utils::strip_js_comments( $js, true );
	}

	// -------------------------------------------------------------------------
	// Transform: strip inline CSS comments
	// -------------------------------------------------------------------------

	/**
	 * Remove block comments from every inline <style> block.
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with inline CSS comments removed.
	 */
	public static function strip_inline_css_comments( $html ) {
		return preg_replace_callback(
			'/<style([^>]*)>([\s\S]*?)<\/style>/i',
			function ( $matches ) {
				$attrs = $matches[1];
				$css   = preg_replace( '/\/\*[\s\S]*?\*\//', '', $matches[2] );
				return '<style' . $attrs . '>' . $css . '</style>';
			},
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Transform: minify HTML whitespace
	// -------------------------------------------------------------------------

	/**
	 * Collapse redundant whitespace in the HTML output.
	 *
	 * @param  string $html Full page HTML.
	 * @return string Minified HTML.
	 */
	public static function minify( $html ) {
		$protected    = array();
		$index        = 0;
		$protect_tags = 'pre|textarea|script|style';

		$html = preg_replace_callback(
			'/<(' . $protect_tags . ')([^>]*)>([\s\S]*?)<\/\1>/i',
			function ( $m ) use ( &$protected, &$index ) {
				$token               = 'POBPWS' . $index . 'END';
				$protected[ $token ] = $m[0];
				$index++;
				return $token;
			},
			$html
		);

		$html = preg_replace( '/>\s+</', '> <', $html );
		$html = preg_replace( '/^\s+/m', '', $html );
		$html = preg_replace( '/\s+$/m', '', $html );
		$html = preg_replace( '/\n{2,}/', "\n", $html );

		foreach ( $protected as $token => $original ) {
			$html = str_replace( $token, $original, $html );
		}

		return $html;
	}
}
