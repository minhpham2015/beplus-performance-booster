<?php
/**
 * JavaScript optimization: defer attribute and interaction-based delay.
 *
 * @package Performance_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class POBP_JS
 *
 * Handles two JS performance strategies:
 *  1. defer  — adds the `defer` attribute to non-critical script tags.
 *  2. delay  — delays all script execution until the first user interaction
 *              with a 5-second fallback.
 */
class POBP_JS {

	/**
	 * Register front-end hooks based on current settings.
	 *
	 * @param array $opts Result of pobp_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['js_defer'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'defer_scripts' ), 10, 3 );
		}

		if ( $opts['js_delay'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'delay_scripts' ), 20, 3 );
			add_action( 'wp_footer', array( __CLASS__, 'output_delay_script' ), 1 );
		}

		if ( ! empty( $opts['remove_js_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_js_handles' ), 100 );
		}
	}

	// -------------------------------------------------------------------------
	// Remove JS handles
	// -------------------------------------------------------------------------

	/**
	 * Dequeue and deregister JS handles listed in the remove_js_handles option.
	 */
	public static function dequeue_js_handles() {
		$opts    = pobp_get_options();
		$handles = pobp_parse_exclude_list( $opts['remove_js_handles'] );

		foreach ( $handles as $handle ) {
			if ( ! empty( $handle ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Defer
	// -------------------------------------------------------------------------

	/**
	 * Add the `defer` attribute to script tags.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function defer_scripts( $tag, $handle, $src ) {
		$never_defer = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments' );
		if ( in_array( $handle, $never_defer, true ) ) {
			return $tag;
		}

		$opts    = pobp_get_options();
		$exclude = pobp_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	// -------------------------------------------------------------------------
	// Delay
	// -------------------------------------------------------------------------

	/**
	 * Convert an enqueued <script src="…"> tag to a "delayed" placeholder.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function delay_scripts( $tag, $handle, $src ) {
		$never_delay = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments' );
		if ( in_array( $handle, $never_delay, true ) ) {
			return $tag;
		}

		if ( empty( $src ) || false === strpos( $tag, ' src=' ) ) {
			return $tag;
		}

		if ( false !== strpos( $tag, 'data-pobp-delay' ) ) {
			return $tag;
		}

		$opts    = pobp_get_options();
		$exclude = pobp_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		$tag = preg_replace_callback(
			'/<script([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>/i',
			function ( $m ) use ( $handle ) {
				$before = $m[1];
				$quote  = $m[2];
				$src    = $m[3];
				$after  = $m[4];

				$before = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $before );
				$after  = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $after );

				return '<script' . $before . $after
					. ' type="text/plain"'
					. ' data-pobp-delay="1"'
					. ' data-pobp-handle="' . esc_attr( $handle ) . '"'
					. ' data-pobp-src="' . esc_url( $src ) . '"'
					. '>';
			},
			$tag
		);

		return $tag;
	}

	// -------------------------------------------------------------------------
	// Footer loader snippet
	// -------------------------------------------------------------------------

	/**
	 * Output the inline JS delay snippet into wp_footer (priority 1).
	 */
	public static function output_delay_script() {
		$opts         = pobp_get_options();
		$exclude      = pobp_parse_exclude_list( $opts['js_exclude'] );
		$exclude_json = wp_json_encode( array_values( $exclude ) );

		if ( false === $exclude_json ) {
			$exclude_json = '[]';
		}
		?>
<script id="pobp-delay-js">
(function () {
	'use strict';

	var _pobpExclude = <?php echo $exclude_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is JSON-encoded via wp_json_encode() ?>;
	var _pobpLoaded  = false;

	function _pobpIsExcluded(src) {
		for (var i = 0; i < _pobpExclude.length; i++) {
			if (_pobpExclude[i] && src.indexOf(_pobpExclude[i]) !== -1) return true;
		}
		return false;
	}

	function _pobpLoadAll() {
		if (_pobpLoaded) return;
		_pobpLoaded = true;

		var delayed = document.querySelectorAll('script[data-pobp-delay="1"]');
		delayed.forEach(function (placeholder) {
			var src = placeholder.getAttribute('data-pobp-src') || '';
			if (src && !_pobpIsExcluded(src)) {
				var s   = document.createElement('script');
				s.src   = src;
				s.async = false;
				document.body.appendChild(s);
			}
			if (placeholder.parentNode) {
				placeholder.parentNode.removeChild(placeholder);
			}
		});
	}

	var _pobpEvents = [
		'mousemove', 'mousedown',
		'keydown',
		'scroll', 'wheel',
		'touchstart', 'touchmove',
		'click'
	];

	function _pobpOnInteraction() {
		_pobpLoadAll();
		_pobpEvents.forEach(function (e) {
			document.removeEventListener(e, _pobpOnInteraction, {passive: true});
		});
	}

	_pobpEvents.forEach(function (e) {
		document.addEventListener(e, _pobpOnInteraction, {once: true, passive: true});
	});

	setTimeout(_pobpLoadAll, 5000);
})();
</script>
		<?php
	}
}
