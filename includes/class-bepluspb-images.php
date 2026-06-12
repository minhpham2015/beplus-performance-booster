<?php
/**
 * Lazy load images: native loading="lazy" attribute + IntersectionObserver fallback.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Images
 *
 * Applies native lazy loading to images across the page with fine-grained
 * control over which images are affected.
 */
class BEPLUSPB_Images {

	/**
	 * Running count of <img> tags processed in this HTTP request.
	 *
	 * @var int
	 */
	private static $image_count = 0;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register all lazy-load hooks when the feature is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( ! $opts['lazy_load'] ) {
			return;
		}

		self::$image_count = 0;

		add_filter( 'render_block', array( __CLASS__, 'process_html' ), 8 );

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_blocks( $post->post_content ) ) {
			add_filter( 'the_content', array( __CLASS__, 'process_html' ), 20 );
		}

		add_filter( 'post_thumbnail_html', array( __CLASS__, 'process_html' ), 20 );
		add_filter( 'widget_text',         array( __CLASS__, 'process_html' ), 20 );

		add_action( 'wp_footer', array( __CLASS__, 'output_fallback_script' ), 20 );
	}

	// -------------------------------------------------------------------------
	// HTML processing
	// -------------------------------------------------------------------------

	/**
	 * Main entry point for content transformation.
	 *
	 * @param  string $content HTML markup.
	 * @return string Transformed HTML.
	 */
	public static function process_html( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		$opts          = bepluspb_get_options();
		$skip_n        = absint( $opts['lazy_skip_first_n'] );
		$exc_classes   = self::parse_comma_list( $opts['lazy_exclude_class'] );
		$exc_ids       = self::parse_comma_list( $opts['lazy_exclude_id'] );
		$exc_filenames = self::parse_comma_list( $opts['lazy_exclude_filename'] );

		$picture_blocks = array();
		$pic_idx        = 0;
		$content = preg_replace_callback(
			'/<picture[^>]*>[\s\S]*?<\/picture>/i',
			function ( $m ) use ( &$picture_blocks, &$pic_idx ) {
				$token                    = 'BEPLUSPBPIC' . $pic_idx . 'END';
				$picture_blocks[ $token ] = $m[0];
				$pic_idx++;
				return $token;
			},
			$content
		);

		$content = preg_replace_callback(
			'/<img([^>]*)>/i',
			function ( $m ) use ( $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
				return self::maybe_lazy( $m[0], $m[1], $skip_n, $exc_classes, $exc_ids, $exc_filenames );
			},
			$content
		);

		foreach ( $picture_blocks as $token => $picture_html ) {
			$picture_html = preg_replace_callback(
				'/<img([^>]*)>/i',
				function ( $m ) use ( $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
					return self::maybe_lazy( $m[0], $m[1], $skip_n, $exc_classes, $exc_ids, $exc_filenames );
				},
				$picture_html
			);
			$content = str_replace( $token, $picture_html, $content );
		}

		return $content;
	}

	// -------------------------------------------------------------------------
	// Per-image decision logic
	// -------------------------------------------------------------------------

	/**
	 * Decide whether to add loading="lazy" to one <img> tag.
	 *
	 * @param  string   $full_tag      The full <img ...> string.
	 * @param  string   $attrs         Everything between <img and >.
	 * @param  int      $skip_n        Number of leading images to skip.
	 * @param  string[] $exc_classes   CSS class names that trigger exclusion.
	 * @param  string[] $exc_ids       Element IDs that trigger exclusion.
	 * @param  string[] $exc_filenames Partial filename strings that trigger exclusion.
	 * @return string   Possibly modified <img> tag.
	 */
	private static function maybe_lazy( $full_tag, $attrs, $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
		if ( false !== strpos( $attrs, 'loading=' ) ) {
			return $full_tag;
		}

		self::$image_count++;
		if ( self::$image_count <= $skip_n ) {
			return '<img' . $attrs . ' loading="eager">';
		}

		if ( ! empty( $exc_classes ) ) {
			if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $attrs, $cls_m ) ) {
				$img_classes = array_filter( explode( ' ', $cls_m[1] ) );
				foreach ( $exc_classes as $cls ) {
					if ( '' !== $cls && in_array( $cls, $img_classes, true ) ) {
						return $full_tag;
					}
				}
			}
		}

		if ( ! empty( $exc_ids ) ) {
			if ( preg_match( '/\bid=["\']([^"\']*)["\']/', $attrs, $id_m ) ) {
				foreach ( $exc_ids as $exc_id ) {
					if ( '' !== $exc_id && $exc_id === $id_m[1] ) {
						return $full_tag;
					}
				}
			}
		}

		if ( ! empty( $exc_filenames ) ) {
			if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $attrs, $src_m ) ) {
				foreach ( $exc_filenames as $keyword ) {
					if ( '' !== $keyword && false !== strpos( $src_m[1], $keyword ) ) {
						return $full_tag;
					}
				}
			}
		}

		return '<img' . $attrs . ' loading="lazy">';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Split a comma-separated string into a trimmed, filtered array.
	 *
	 * @param  string $value Raw option value.
	 * @return string[]
	 */
	private static function parse_comma_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	// -------------------------------------------------------------------------
	// IntersectionObserver polyfill (wp_footer)
	// -------------------------------------------------------------------------

	/**
	 * Output a minimal IntersectionObserver polyfill into wp_footer.
	 */
	public static function output_fallback_script() {
		?>
<script id="bepluspb-lazy-fallback">
(function () {
	'use strict';

	if ( 'loading' in HTMLImageElement.prototype ) {
		return;
	}

	var lazyImgs = [].slice.call( document.querySelectorAll( 'img[loading="lazy"]' ) );
	if ( ! lazyImgs.length ) {
		return;
	}

	function loadImage( img ) {
		if ( img.dataset && img.dataset.src ) {
			img.src = img.dataset.src;
		}
		if ( img.dataset && img.dataset.srcset ) {
			img.srcset = img.dataset.srcset;
		}
		img.removeAttribute( 'loading' );
	}

	if ( 'IntersectionObserver' in window ) {
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}
					loadImage( entry.target );
					observer.unobserve( entry.target );
				} );
			},
			{ rootMargin: '200px 0px', threshold: 0.01 }
		);
		lazyImgs.forEach( function ( img ) {
			observer.observe( img );
		} );
	} else {
		lazyImgs.forEach( loadImage );
	}
})();
</script>
		<?php
	}
}
