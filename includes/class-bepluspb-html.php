<?php
/**
 * HTML output optimization: whitespace minification and comment removal.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_HTML
 *
 * Buffers the entire page output (template_redirect → shutdown) and applies
 * one or more of the following transformations before sending to the browser:
 *
 *  1. Strip inline JS comments  — removes // and block comments from <script>.
 *  2. Strip inline CSS comments — removes block comments from <style>.
 *  3. Strip HTML comments       — removes <!-- ... --> from the markup.
 *  4. Minify HTML whitespace    — collapses redundant spaces/newlines between tags.
 */
class BEPLUSPB_HTML {

	/**
	 * The ob_get_level() value recorded immediately before we call ob_start().
	 *
	 * Stored so buffer_end() can verify it is consuming only the buffer it
	 * opened. We compare against self::$buffer_level + 1 (the exact level of
	 * our buffer) rather than just > self::$buffer_level to avoid accidentally
	 * cleaning a buffer opened by another plugin on top of ours.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Register a full-page output buffer when at least one HTML feature is active.
	 *
	 * @param array $opts Result of bepluspb_get_options().
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
	 * Records ob_get_level() before calling ob_start() so buffer_end() can
	 * verify it is closing exactly the buffer it opened.
	 */
	public static function buffer_start() {
		self::$buffer_level = null;

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		self::$buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * Close the buffer, apply transforms in order, and echo the final HTML.
	 */
	public static function buffer_end() {
		// Only close the exact buffer level we opened (level + 1).
		// Using !== instead of <= prevents us from accidentally closing a buffer
		// opened by another plugin on top of ours.
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			return;
		}

		$html = ob_get_clean();
		self::$buffer_level = null;

		if ( empty( $html ) ) {
			return;
		}

		$opts = bepluspb_get_options();

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
				$token                  = 'BEPLUSPBHC' . $index . 'END';
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
		return BEPLUSPB_Utils::strip_js_comments( $js, true );
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
				$token               = 'BEPLUSPBWS' . $index . 'END';
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
