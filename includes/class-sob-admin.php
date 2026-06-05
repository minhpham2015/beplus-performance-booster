<?php
/**
 * Admin settings page, Settings API registration, options sanitization,
 * meta box, admin bar menu, and cache management UI.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_Admin
 *
 * Registers the Settings > BePlus Optimizer page and handles all
 * option storage via the WordPress Settings API.
 *
 * Also provides:
 *  - Meta box on post/page edit screens ("Disable cache for this page").
 *  - Admin bar "Clear Cache" menu with a "Clear CSS/JS Cache" link.
 *  - A "Clear Cache" button on the settings page itself.
 *  - Admin notice confirming successful cache clears.
 */
class SOB_Admin {

	// =========================================================================
	// Bootstrap
	// =========================================================================

	/**
	 * Register all admin hooks.
	 * Called on 'plugins_loaded'.
	 */
	public static function init() {
		// Settings page.
		add_action( 'admin_menu',            array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init',            array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Per-page cache-disable meta box.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'save_post',      array( __CLASS__, 'save_meta_box' ), 10, 2 );

		// Admin bar "BePlus Optimizer" menu (visible on frontend + backend for admins).
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_menu' ), 100 );

		// Enqueue admin bar stylesheet on front-end pages where the bar is showing.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_adminbar_styles' ) );

		// POST handler for the "Clear Cache" action (admin-post.php).
		add_action( 'admin_post_sob_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );

		// POST handler for quick-enable buttons on the Dashboard tab.
		add_action( 'admin_post_sob_quick_enable', array( __CLASS__, 'handle_quick_enable' ) );

		// AJAX handler for the master cache on/off toggle on the Dashboard tab.
		add_action( 'wp_ajax_sob_toggle_cache', array( __CLASS__, 'handle_ajax_toggle_cache' ) );

		// Admin notice shown after a successful cache clear.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_cleared_notice' ) );
	}

	// =========================================================================
	// Assets
	// =========================================================================

	/**
	 * Enqueue admin assets.
	 *
	 * CSS is loaded on all admin pages because the admin bar panel is visible
	 * on every admin screen — not just the plugin settings page.
	 * JS (tab switcher) is only needed on the settings page itself.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Load CSS on every admin page — needed for the admin bar cache panel.
		wp_enqueue_style(
			'sob-admin',
			SOB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SOB_VERSION
		);

		// CQ-3: Tab-switching logic lives in an external file so it can be
		// cached by the browser and linted/tested independently.
		if ( 'settings_page_site-optimizer-by-beplus-master' === $hook_suffix ) {
			wp_enqueue_script(
				'sob-admin-js',
				SOB_PLUGIN_URL . 'assets/js/admin.js',
				array(),
				SOB_VERSION,
				true // load in footer
			);
			// Pass AJAX URL, nonce, and translated strings for the master cache toggle.
			wp_localize_script(
				'sob-admin-js',
				'sobAdmin',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'toggleNonce'    => wp_create_nonce( 'sob_toggle_cache' ),
					'labelEnabled'   => __( 'Enabled', 'site-optimizer-by-beplus-master' ),
					'labelDisabled'  => __( 'Disabled', 'site-optimizer-by-beplus-master' ),
					'noticeDisabled' => __( 'All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.', 'site-optimizer-by-beplus-master' ),
				)
			);
		}
	}

	/**
	 * Enqueue admin bar stylesheet on the front end.
	 *
	 * Fires on wp_enqueue_scripts so the admin bar cache panel looks correct
	 * for logged-in admins viewing the public-facing site.
	 */
	public static function enqueue_adminbar_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_enqueue_style(
			'sob-admin',
			SOB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SOB_VERSION
		);
	}

	// =========================================================================
	// Settings page
	// =========================================================================

	/**
	 * Register the options page under Settings > BePlus Optimizer.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ),
			__( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ),
			'manage_options',
			'site-optimizer-by-beplus-master',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the single option key with the WordPress Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'sob_settings_group',
			SOB_OPTIONS_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
			)
		);
	}

	/**
	 * Sanitize and save submitted settings.
	 *
	 * Also handles toggling .htaccess rules when the cache option changes.
	 *
	 * @param  array $input Raw POST values.
	 * @return array Sanitized option values.
	 */
	public static function sanitize_options( $input ) {
		$sanitized = array();

		// ---- Boolean toggles (checkbox = 1 when present, 0 when absent). ----
		$booleans = array(
			// JS
			'js_delay', 'js_defer',
			// CSS
			'css_minify', 'css_non_blocking', 'css_inline_all',
			// Lazy load
			'lazy_load',
			// Cleanup
			'remove_emoji', 'remove_embed', 'remove_block_css', 'remove_woo_scripts',
			// HTML
			'html_minify', 'html_remove_comments', 'html_remove_js_comments', 'html_remove_css_comments',
			// Cache
			'cache_headers',
			// File minification
			'minify_css_files', 'minify_js_files',
			// Cache exclusions
			'cache_for_logged_in',
		);
		foreach ( $booleans as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		// ---- Textarea / text fields. ----
		$sanitized['js_exclude']  = isset( $input['js_exclude'] )
			? sanitize_textarea_field( $input['js_exclude'] )
			: '';

		$sanitized['css_exclude'] = isset( $input['css_exclude'] )
			? sanitize_textarea_field( $input['css_exclude'] )
			: '';

		$sanitized['css_remove_handles'] = isset( $input['css_remove_handles'] )
			? sanitize_textarea_field( $input['css_remove_handles'] )
			: '';

		$sanitized['remove_js_handles'] = isset( $input['remove_js_handles'] )
			? sanitize_textarea_field( $input['remove_js_handles'] )
			: '';

		$sanitized['font_preload'] = isset( $input['font_preload'] )
			? sanitize_textarea_field( $input['font_preload'] )
			: '';

		$sanitized['cache_exclude_pages'] = isset( $input['cache_exclude_pages'] )
			? sanitize_textarea_field( $input['cache_exclude_pages'] )
			: '';

		// ---- Lazy load advanced options. ----
		$sanitized['lazy_skip_first_n'] = isset( $input['lazy_skip_first_n'] )
			? absint( $input['lazy_skip_first_n'] )
			: 1;

		$sanitized['lazy_exclude_class'] = isset( $input['lazy_exclude_class'] )
			? sanitize_text_field( $input['lazy_exclude_class'] )
			: '';

		$sanitized['lazy_exclude_id'] = isset( $input['lazy_exclude_id'] )
			? sanitize_text_field( $input['lazy_exclude_id'] )
			: '';

		$sanitized['lazy_exclude_filename'] = isset( $input['lazy_exclude_filename'] )
			? sanitize_text_field( $input['lazy_exclude_filename'] )
			: '';

		// ---- Master cache switch — preserved from DB when not submitted. ----
		// cache_enabled lives on the Dashboard tab outside the main <form>, so it
		// is never present in the Settings API POST. Read the existing DB value
		// rather than defaulting to 0 (which would undo every AJAX toggle save).
		// Fallback uses 0 to match sob_default_options() — the master toggle
		// must stay OFF on a fresh install until the user explicitly enables it.
		if ( isset( $input['cache_enabled'] ) ) {
			$sanitized['cache_enabled'] = (int) (bool) $input['cache_enabled'];
		} else {
			$existing                   = get_option( SOB_OPTIONS_KEY, array() );
			$sanitized['cache_enabled'] = isset( $existing['cache_enabled'] ) ? (int) $existing['cache_enabled'] : 0;
		}

		// ---- Sync .htaccess rules when the cache_headers toggle changes. ----
		$old = sob_get_options();
		if ( $sanitized['cache_headers'] && ! $old['cache_headers'] ) {
			SOB_Htaccess::add_rules();
		} elseif ( ! $sanitized['cache_headers'] && $old['cache_headers'] ) {
			SOB_Htaccess::remove_rules();
		}

		return $sanitized;
	}

	/**
	 * Render the full admin settings page with a tabbed interface.
	 *
	 * Five tabs:
	 *  1. Dashboard   — outside the <form> (has its own action forms)
	 *  2. Cache Files — JS + CSS + Media merged
	 *  3. Fonts       — font preload
	 *  4. Cleanup     — Remove Assets + HTML
	 *  5. Cache Exclusions — page exclusions + user exclusions + browser cache
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts = sob_get_options();

		$clear_cache_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=sob_clear_cache' ),
			'sob_clear_cache'
		);
		$cache_dir_writable = SOB_Minify::ensure_cache_dir();

		// Tab definitions: id => label.
		$tabs = array(
			'dashboard'    => '📊 ' . __( 'Dashboard', 'site-optimizer-by-beplus-master' ),
			'cache_files'  => '⚡ ' . __( 'Cache Files', 'site-optimizer-by-beplus-master' ),
			'fonts'        => '🔤 ' . __( 'Fonts', 'site-optimizer-by-beplus-master' ),
			'cleanup'      => '🧹 ' . __( 'Cleanup', 'site-optimizer-by-beplus-master' ),
			'exclusions'   => '🚫 ' . __( 'Cache Exclusions', 'site-optimizer-by-beplus-master' ),
			'status'       => '🔍 ' . __( 'Status', 'site-optimizer-by-beplus-master' ),
			'ai_optimizer' => '🤖 ' . __( 'AI Optimizer', 'site-optimizer-by-beplus-master' ),
		);
		?>
		<div class="wrap sob-settings-wrap">
			<h1><?php esc_html_e( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ); ?></h1>
			<p class="sob-tagline"><?php esc_html_e( 'BePlus Optimizer is a lightweight performance plugin that accelerates your WordPress site through smart caching, asset minification, lazy loading, and cleanup of unnecessary bloat. Optimizations apply exclusively to front-end visitors — your admin experience is never impacted.', 'site-optimizer-by-beplus-master' ); ?></p>

			<!-- Tab navigation -->
			<div class="sob-tabs-nav" role="tablist">
				<?php foreach ( $tabs as $id => $label ) : ?>
				<button type="button"
					class="sob-tab-btn"
					data-tab="<?php echo esc_attr( $id ); ?>"
					role="tab"
					aria-controls="sob-tab-<?php echo esc_attr( $id ); ?>"
					aria-selected="false">
					<?php echo esc_html( $label ); ?>
				</button>
				<?php endforeach; ?>
			</div>

			<!-- Dashboard tab: rendered outside the settings form so it can have its own forms -->
			<div id="sob-tab-dashboard" class="sob-tab-panel" role="tabpanel">
				<?php self::render_section_dashboard( $opts, $clear_cache_url, $cache_dir_writable ); ?>
			</div>

			<!-- Settings tabs: all inside a single <form> for the Settings API -->
			<form method="post" action="options.php" class="sob-settings-form">
				<?php settings_fields( 'sob_settings_group' ); ?>

				<div id="sob-tab-cache_files" class="sob-tab-panel" role="tabpanel">
					<?php self::render_section_cache_files( $opts, $cache_dir_writable ); ?>
				</div>

				<div id="sob-tab-fonts" class="sob-tab-panel" role="tabpanel">
					<?php self::render_section_fonts( $opts ); ?>
				</div>

				<div id="sob-tab-cleanup" class="sob-tab-panel" role="tabpanel">
					<?php self::render_section_cleanup_all( $opts ); ?>
				</div>

				<div id="sob-tab-exclusions" class="sob-tab-panel" role="tabpanel">
					<?php self::render_section_exclusions( $opts ); ?>
				</div>

				<div class="sob-save-bar" id="sob-save-bar">
					<?php submit_button( __( 'Save Settings', 'site-optimizer-by-beplus-master' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<!-- Status tab: outside the settings form — read-only system report -->
			<div id="sob-tab-status" class="sob-tab-panel" role="tabpanel">
				<?php self::render_section_status(); ?>
			</div>

			<!-- AI Optimizer tab: outside the settings form — no settings to save -->
			<div id="sob-tab-ai_optimizer" class="sob-tab-panel" role="tabpanel">
				<?php self::render_section_ai_optimizer(); ?>
			</div>

		</div>
		<?php
		// Tab-switching JavaScript is loaded via wp_enqueue_script() in
		// enqueue_admin_assets() — see assets/js/admin.js.
	}

	// =========================================================================
	// Section renderers
	// =========================================================================

	/**
	 * Render the Dashboard tab: cache overview + recommended settings.
	 *
	 * @param array  $opts               Current option values.
	 * @param string $clear_cache_url    Nonce-signed URL for the clear-cache action.
	 * @param bool   $cache_dir_writable Whether the cache directory is writable.
	 */
	private static function render_section_dashboard( $opts, $clear_cache_url, $cache_dir_writable = true ) {
		$stats       = SOB_Minify::get_cache_stats();
		$settings_url = admin_url( 'options-general.php?page=site-optimizer-by-beplus-master' );
		$htaccess_writable = SOB_Htaccess::is_writable();

		// Recommended features for the quick-enable table.
		$recommended = array(
			'lazy_load'        => __( 'Lazy Load Images',          'site-optimizer-by-beplus-master' ),
			'minify_css_files' => __( 'Minify CSS Files',          'site-optimizer-by-beplus-master' ),
			'minify_js_files'  => __( 'Minify JS Files',           'site-optimizer-by-beplus-master' ),
			'js_defer'         => __( 'Defer Non-Critical JS',     'site-optimizer-by-beplus-master' ),
			'remove_emoji'     => __( 'Remove Emoji Scripts',      'site-optimizer-by-beplus-master' ),
			'cache_headers'    => __( 'Browser Cache (.htaccess)', 'site-optimizer-by-beplus-master' ),
		);
		?>

		<!-- ── Row 1: Stat cards ──────────────────────────────────────────── -->
		<div class="sob-stat-grid">

			<div class="sob-stat-card sob-stat-card--blue">
				<span class="sob-stat-icon dashicons dashicons-media-default"></span>
				<div class="sob-stat-body">
					<span class="sob-stat-value"><?php echo esc_html( $stats['count'] ); ?></span>
					<span class="sob-stat-label"><?php esc_html_e( 'Cached Files', 'site-optimizer-by-beplus-master' ); ?></span>
				</div>
			</div>

			<div class="sob-stat-card sob-stat-card--green">
				<span class="sob-stat-icon dashicons dashicons-database"></span>
				<div class="sob-stat-body">
					<span class="sob-stat-value">
						<?php echo $stats['size'] > 0 ? esc_html( SOB_Minify::human_filesize( $stats['size'] ) ) : '—'; ?>
					</span>
					<span class="sob-stat-label"><?php esc_html_e( 'Total Size', 'site-optimizer-by-beplus-master' ); ?></span>
				</div>
			</div>

			<div class="sob-stat-card sob-stat-card--grey">
				<span class="sob-stat-icon dashicons dashicons-clock"></span>
				<div class="sob-stat-body">
					<span class="sob-stat-value sob-stat-value--sm">
						<?php echo $stats['newest'] ? esc_html( wp_date( get_option( 'date_format' ), $stats['newest'] ) ) : '—'; ?>
					</span>
					<span class="sob-stat-label"><?php esc_html_e( 'Last Cached', 'site-optimizer-by-beplus-master' ); ?></span>
				</div>
			</div>

			<div class="sob-stat-card <?php echo esc_attr( $stats['writable'] ? 'sob-stat-card--teal' : 'sob-stat-card--orange' ); ?>">
				<span class="sob-stat-icon dashicons <?php echo esc_attr( $stats['writable'] ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span>
				<div class="sob-stat-body">
					<span class="sob-stat-value sob-stat-value--sm">
						<?php echo $stats['writable'] ? esc_html__( 'Writable', 'site-optimizer-by-beplus-master' ) : esc_html__( 'Not writable', 'site-optimizer-by-beplus-master' ); ?>
					</span>
					<span class="sob-stat-label"><?php esc_html_e( 'Cache Directory', 'site-optimizer-by-beplus-master' ); ?></span>
				</div>
			</div>

		</div>

		<?php if ( ! $stats['writable'] ) : ?>
		<div class="notice notice-warning sob-notice-warning inline" style="margin-top:12px;">
			<p>
				<?php esc_html_e( 'Cache directory is not writable:', 'site-optimizer-by-beplus-master' ); ?>
				<code><?php echo esc_html( $stats['dir'] ); ?></code><br>
				<?php esc_html_e( 'Please check directory permissions (755 recommended).', 'site-optimizer-by-beplus-master' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- ── Row 2: Actions | Recommended ──────────────────────────────── -->
		<div class="sob-two-col">

			<!-- Cache Actions card -->
			<div class="sob-card sob-actions-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'Cache Actions', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body">

					<!-- Master cache toggle -->
					<?php $cache_on = ! empty( $opts['cache_enabled'] ); ?>
					<div class="sob-toggle-section" id="sob-toggle-section">
						<div class="sob-toggle-wrap">
							<label class="sob-toggle" for="sob-cache-enabled-toggle" aria-label="<?php esc_attr_e( 'Cache Optimizations', 'site-optimizer-by-beplus-master' ); ?>">
								<input type="checkbox"
									id="sob-cache-enabled-toggle"
									<?php checked( $cache_on ); ?>>
								<span class="sob-toggle-slider"></span>
							</label>
							<div class="sob-toggle-labels">
								<span class="sob-toggle-title"><?php esc_html_e( 'Cache Optimizations', 'site-optimizer-by-beplus-master' ); ?></span>
								<span class="sob-toggle-status <?php echo $cache_on ? 'sob-toggle-status--on' : 'sob-toggle-status--off'; ?>" id="sob-toggle-status">
									<?php echo $cache_on ? esc_html__( 'Enabled', 'site-optimizer-by-beplus-master' ) : esc_html__( 'Disabled', 'site-optimizer-by-beplus-master' ); ?>
								</span>
							</div>
						</div>
						<p class="sob-toggle-desc"><?php esc_html_e( 'Enable or disable all CSS/JS minification and caching globally.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>

					<?php if ( ! $cache_on ) : ?>
					<div class="notice notice-warning inline sob-cache-disabled-notice" id="sob-cache-disabled-notice">
						<p>&#9888; <?php esc_html_e( 'All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<?php else : ?>
					<div class="notice notice-warning inline sob-cache-disabled-notice" id="sob-cache-disabled-notice" style="display:none;"></div>
					<?php endif; ?>

					<div class="sob-cache-actions-row">
						<a href="<?php echo $cache_on ? esc_url( $clear_cache_url ) : '#'; ?>"
						   id="sob-clear-cache-btn"
						   class="button <?php echo esc_attr( ! $cache_on || 0 === $stats['count'] ? 'sob-clear-btn sob-clear-btn--empty' : 'sob-clear-btn' ); ?>"
						   <?php echo ! $cache_on ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
							<?php esc_html_e( 'Clear CSS/JS Cache', 'site-optimizer-by-beplus-master' ); ?>
						</a>
					</div>

					<p class="sob-cache-summary">
						<?php if ( $stats['count'] > 0 ) : ?>
							<?php
							printf(
								/* translators: 1: count, 2: singular/plural, 3: size */
								esc_html__( '%1$s %2$s · %3$s', 'site-optimizer-by-beplus-master' ),
								esc_html( $stats['count'] ),
								esc_html( _n( 'file', 'files', $stats['count'], 'site-optimizer-by-beplus-master' ) ),
								esc_html( SOB_Minify::human_filesize( $stats['size'] ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Cache is empty', 'site-optimizer-by-beplus-master' ); ?>
						<?php endif; ?>
					</p>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="sob-refresh-link">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Stats', 'site-optimizer-by-beplus-master' ); ?>
					</a>
				</div>
			</div>

			<!-- Recommended Settings card -->
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'Recommended Settings', 'site-optimizer-by-beplus-master' ); ?></h2>
					<p><?php esc_html_e( 'Quick-enable the most impactful performance features.', 'site-optimizer-by-beplus-master' ); ?></p>
				</div>
				<div class="sob-card-body sob-card-body--flush">
					<table class="sob-rec-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Feature', 'site-optimizer-by-beplus-master' ); ?></th>
								<th><?php esc_html_e( 'Status', 'site-optimizer-by-beplus-master' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recommended as $key => $label ) :
								$is_on = ! empty( $opts[ $key ] );
								$locked = ( 'cache_headers' === $key && ! $htaccess_writable );
							?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td>
									<?php if ( $is_on ) : ?>
										<span class="sob-status-badge active"><?php esc_html_e( 'Active', 'site-optimizer-by-beplus-master' ); ?></span>
									<?php else : ?>
										<span class="sob-status-badge inactive"><?php esc_html_e( 'Inactive', 'site-optimizer-by-beplus-master' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_on ) : ?>
										<span class="sob-status-check dashicons dashicons-yes"></span>
									<?php elseif ( $locked ) : ?>
										<span class="description" style="font-size:11px;"><?php esc_html_e( 'N/A', 'site-optimizer-by-beplus-master' ); ?></span>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="sob_quick_enable">
											<input type="hidden" name="sob_option" value="<?php echo esc_attr( $key ); ?>">
											<?php wp_nonce_field( 'sob_quick_enable_' . $key, 'sob_quick_enable_nonce' ); ?>
											<button type="submit" class="button button-secondary sob-quick-enable-btn">
												<?php esc_html_e( 'Enable', 'site-optimizer-by-beplus-master' ); ?>
											</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>

		<!-- ── Row 3: Plugin Info strip ───────────────────────────────────── -->
		<div class="sob-info-strip">
			<div class="sob-info-strip-left">
				<strong><?php esc_html_e( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ); ?></strong>
				<span class="sob-info-version">v<?php echo esc_html( SOB_VERSION ); ?></span>
				<span class="sob-info-sep">·</span>
				<span><?php esc_html_e( 'A complete performance toolkit for WordPress — cache, minify, lazy load, and clean up your site with ease.', 'site-optimizer-by-beplus-master' ); ?></span>
			</div>
			<div class="sob-info-strip-links">
				<a href="https://beplusthemes.com/plugins/site-optimizer-by-beplus/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Documentation', 'site-optimizer-by-beplus-master' ); ?>
				</a>
				<span class="sob-info-sep">·</span>
				<a href="https://beplusthemes.com/support/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Support', 'site-optimizer-by-beplus-master' ); ?>
				</a>
				<span class="sob-info-sep">·</span>
				<a href="https://wordpress.org/plugins/site-optimizer-by-beplus/#reviews" target="_blank" rel="noopener noreferrer">
					★★★★★ <?php esc_html_e( 'Rate this plugin', 'site-optimizer-by-beplus-master' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cache Files" tab — JavaScript, CSS, and Media merged.
	 *
	 * @param array $opts               Current option values.
	 * @param bool  $cache_dir_writable Whether the cache directory is writable.
	 */
	private static function render_section_cache_files( $opts, $cache_dir_writable = true ) {

		// ---- JavaScript card ------------------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'JavaScript', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Control how JavaScript files are loaded and processed to reduce render-blocking and improve page speed.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<!-- Minify JS Files -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_minify_js_files"><?php esc_html_e( 'Minify JS Files', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_minify_js_files"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[minify_js_files]" value="1"
								<?php checked( $opts['minify_js_files'], 1 ); ?>
								<?php disabled( ! $cache_dir_writable, true ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Minify all enqueued JS files (strip comments, trim whitespace) and serve cached versions. Already-minified *.min.js and external CDN scripts are skipped automatically.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<?php if ( ! $cache_dir_writable ) : ?>
						<p class="sob-warn"><?php esc_html_e( 'Cache directory is not writable — minification disabled.', 'site-optimizer-by-beplus-master' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Defer Non-Critical JS -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_js_defer"><?php esc_html_e( 'Defer Non-Critical JS', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_js_defer"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[js_defer]" value="1"
								<?php checked( $opts['js_defer'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Add the defer attribute to non-excluded script tags so they are fetched in parallel and executed after HTML parsing. jQuery and jquery-migrate are always protected.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Delay JS Execution -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_js_delay"><?php esc_html_e( 'Delay JS Execution', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_js_delay"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[js_delay]" value="1"
								<?php checked( $opts['js_delay'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Delay all non-excluded scripts until the first user interaction (mousemove, click, scroll, keydown, touch). Falls back automatically after 5 seconds.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Exclude JS Files -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_js_exclude"><?php esc_html_e( 'Exclude JS Files', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_js_exclude"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[js_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['js_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Scripts whose src contains any of these strings are excluded from delay, defer, and minify.', 'site-optimizer-by-beplus-master' ); ?><br>
							<?php esc_html_e( 'Example: jquery, woocommerce, my-critical-script', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

				<!-- Remove JS Handles -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_remove_js_handles"><?php esc_html_e( 'Remove JS Handles', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One WordPress script handle per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_remove_js_handles"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[remove_js_handles]"
							rows="5" class="large-text code"
							placeholder="jquery-migrate"><?php echo esc_textarea( $opts['remove_js_handles'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Completely dequeue specific JavaScript files by handle. Use this to remove scripts that are loaded by WordPress or plugins but are not needed on your site.', 'site-optimizer-by-beplus-master' ); ?><br>
							<?php esc_html_e( 'Example: jquery-migrate, wp-embed, my-plugin-script', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- CSS card -------------------------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'CSS', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Minify and optimize your stylesheets to reduce file size, eliminate render-blocking requests, and improve load times.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<!-- Minify CSS Files -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_minify_css_files"><?php esc_html_e( 'Minify CSS Files', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_minify_css_files"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[minify_css_files]" value="1"
								<?php checked( $opts['minify_css_files'], 1 ); ?>
								<?php disabled( ! $cache_dir_writable, true ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Minify all enqueued CSS files (strip comments, collapse whitespace) and serve cached versions. Already-minified *.min.css and external CDN stylesheets are skipped.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<?php if ( ! $cache_dir_writable ) : ?>
						<p class="sob-warn"><?php esc_html_e( 'Cache directory is not writable — minification disabled.', 'site-optimizer-by-beplus-master' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Minify Inline CSS -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_css_minify"><?php esc_html_e( 'Minify Inline CSS', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_css_minify"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[css_minify]" value="1"
								<?php checked( $opts['css_minify'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Strip whitespace and comments from inline &lt;style&gt; blocks in &lt;head&gt;. License comments (/*! … */) are preserved.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Non-Render-Blocking CSS -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_css_non_blocking"><?php esc_html_e( 'Non-Render-Blocking CSS', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_css_non_blocking"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[css_non_blocking]" value="1"
								<?php checked( $opts['css_non_blocking'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Convert stylesheet links to preload + onload swap so they do not block rendering. A &lt;noscript&gt; fallback is included.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Tip: Exclude your theme\'s main stylesheet if you see a flash of unstyled content (FOUC).', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

				<!-- Inline All CSS -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_css_inline_all"><?php esc_html_e( 'Inline All CSS', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_css_inline_all"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[css_inline_all]" value="1"
								<?php checked( $opts['css_inline_all'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Read every local enqueued stylesheet and output its (minified) content as an inline &lt;style&gt; block. Eliminates render-blocking HTTP requests. External CDN stylesheets are kept as links.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Tip: Use "Exclude CSS Files" to keep large stylesheets as external links.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

				<!-- Exclude CSS Files -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_css_exclude"><?php esc_html_e( 'Exclude CSS Files', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_css_exclude"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[css_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Stylesheets whose href contains any of these strings are excluded from Non-Blocking, Inline All, and Minify CSS Files.', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

				<!-- Remove CSS Handles -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_css_remove_handles"><?php esc_html_e( 'Remove CSS Handles', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One WordPress style handle per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_css_remove_handles"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[css_remove_handles]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_remove_handles'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'These stylesheets will be completely dequeued on every front-end page.', 'site-optimizer-by-beplus-master' ); ?><br>
							<?php esc_html_e( 'Example: wp-block-library, dashicons, woocommerce-layout', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- Media card -----------------------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'Media', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Defer off-screen images and control lazy loading behavior to speed up initial page rendering and protect your Core Web Vitals score.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<!-- Enable Lazy Loading -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_lazy_load"><?php esc_html_e( 'Enable Lazy Loading', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_lazy_load"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[lazy_load]" value="1"
								<?php checked( $opts['lazy_load'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Add loading="lazy" to &lt;img&gt; tags in post content, thumbnails, widgets, Gutenberg blocks, and &lt;picture&gt; elements. Includes an IntersectionObserver JS fallback for older browsers.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Skip First N Images -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_lazy_skip_first_n"><?php esc_html_e( 'Skip First N Images', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<input type="number" id="sob_lazy_skip_first_n"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[lazy_skip_first_n]"
							value="<?php echo esc_attr( $opts['lazy_skip_first_n'] ); ?>"
							min="0" max="20" step="1" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Images 1 through N are loaded eagerly. Default: 1 — protects the hero/LCP image from being lazy-loaded and hurting Core Web Vitals.', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

				<!-- Exclude by CSS Class -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_lazy_exclude_class"><?php esc_html_e( 'Exclude by CSS Class', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<input type="text" id="sob_lazy_exclude_class"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[lazy_exclude_class]"
							value="<?php echo esc_attr( $opts['lazy_exclude_class'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. hero-image, no-lazy, skip-lazy', 'site-optimizer-by-beplus-master' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated CSS class names. Images with any of these classes are loaded normally.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

				<!-- Exclude by Element ID -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_lazy_exclude_id"><?php esc_html_e( 'Exclude by Element ID', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<input type="text" id="sob_lazy_exclude_id"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[lazy_exclude_id]"
							value="<?php echo esc_attr( $opts['lazy_exclude_id'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. hero-banner, site-logo', 'site-optimizer-by-beplus-master' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated element IDs. Images with these IDs are loaded normally.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

				<!-- Exclude by Filename -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_lazy_exclude_filename"><?php esc_html_e( 'Exclude by Filename', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<input type="text" id="sob_lazy_exclude_filename"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[lazy_exclude_filename]"
							value="<?php echo esc_attr( $opts['lazy_exclude_filename'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. logo, hero, banner', 'site-optimizer-by-beplus-master' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated partial filename strings. Images whose src URL contains any of these strings are loaded normally.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Fonts" tab — font preload.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_fonts( $opts ) {
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'Font Preload', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Manage how web fonts are loaded to eliminate render-blocking requests, reduce layout shift, and prevent a flash of invisible text (FOIT).', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_font_preload"><?php esc_html_e( 'Font URLs to Preload', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One font URL per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_font_preload"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[font_preload]"
							rows="6" class="large-text code"><?php echo esc_textarea( $opts['font_preload'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Each URL will be output as a &lt;link rel="preload" as="font" crossorigin="anonymous"&gt; tag near the top of &lt;head&gt;.', 'site-optimizer-by-beplus-master' ); ?><br>
							<?php esc_html_e( 'Supports woff2, woff, ttf, otf, eot. Example:', 'site-optimizer-by-beplus-master' ); ?><br>
							<code>/wp-content/themes/my-theme/fonts/myfont.woff2</code>
						</p>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cleanup" tab — Remove Unused Assets + HTML Optimization merged.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_cleanup_all( $opts ) {

		// ---- Remove Unused Assets card --------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'Remove Unused Assets', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Remove unnecessary WordPress features and unused assets that add overhead without benefit — fewer requests, faster pages.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<!-- Remove Emoji Scripts -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_remove_emoji"><?php esc_html_e( 'Remove Emoji Scripts', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_remove_emoji"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[remove_emoji]" value="1"
								<?php checked( $opts['remove_emoji'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Remove the WordPress emoji detection script, inline style, and DNS prefetch. Safe if you use real Unicode emoji in your content.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove oEmbed -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_remove_embed"><?php esc_html_e( 'Remove oEmbed / wp-embed', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_remove_embed"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[remove_embed]" value="1"
								<?php checked( $opts['remove_embed'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Remove the wp-embed script and oEmbed discovery links. Disable only if you embed WordPress posts inside other sites.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Block / Gutenberg CSS -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_remove_block_css"><?php esc_html_e( 'Remove Block / Gutenberg CSS', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_remove_block_css"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[remove_block_css]" value="1"
								<?php checked( $opts['remove_block_css'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Dequeue wp-block-library, wp-block-library-theme, and global-styles on the front-end. Disable if your theme or content relies on Gutenberg block styles.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Disable WooCommerce Assets -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_remove_woo_scripts"><?php esc_html_e( 'Disable WooCommerce Assets on Non-Shop Pages', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_remove_woo_scripts"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[remove_woo_scripts]" value="1"
								<?php checked( $opts['remove_woo_scripts'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Dequeue WooCommerce scripts and styles on pages unrelated to the shop, cart, checkout, or account. Requires WooCommerce to be active.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'Note: wc-cart-fragments is always preserved regardless of this setting. It maintains live cart counts across all pages via AJAX — removing it breaks the mini-cart widget in most themes.', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- HTML Optimization card -----------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'HTML Optimization', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Compress your HTML output and strip developer comments to reduce the page size delivered to every visitor.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<!-- Minify HTML Output -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_html_minify"><?php esc_html_e( 'Minify HTML Output', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_html_minify"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[html_minify]" value="1"
								<?php checked( $opts['html_minify'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Collapse redundant whitespace between HTML tags in the full page output. Reduces page size without altering visible content.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Content inside &lt;pre&gt;, &lt;textarea&gt;, &lt;script&gt;, and &lt;style&gt; tags is always preserved exactly as-is.', 'site-optimizer-by-beplus-master' ); ?></p>
						<div class="notice notice-warning sob-notice-warning inline">
							<p><strong><?php esc_html_e( 'Compatibility note:', 'site-optimizer-by-beplus-master' ); ?></strong> <?php esc_html_e( 'Collapsing whitespace between tags can affect inline elements — a space between adjacent &lt;a&gt;, &lt;span&gt;, or &lt;img&gt; tags may disappear, potentially shifting layout. Test thoroughly on your theme before enabling in production.', 'site-optimizer-by-beplus-master' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Remove HTML Comments -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_html_remove_comments"><?php esc_html_e( 'Remove HTML Comments', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_html_remove_comments"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[html_remove_comments]" value="1"
								<?php checked( $opts['html_remove_comments'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Strip HTML comments (e.g. theme generator tags, plugin banners, conditional IE comments) from page output.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Inline JS Comments -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_html_remove_js_comments"><?php esc_html_e( 'Remove Inline JS Comments', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_html_remove_js_comments"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[html_remove_js_comments]" value="1"
								<?php checked( $opts['html_remove_js_comments'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Remove // single-line and /* block */ comments from inline &lt;script&gt; blocks in the HTML output.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Inline CSS Comments -->
				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_html_remove_css_comments"><?php esc_html_e( 'Remove Inline CSS Comments', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_html_remove_css_comments"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[html_remove_css_comments]" value="1"
								<?php checked( $opts['html_remove_css_comments'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Remove block comments from inline &lt;style&gt; blocks in the HTML output.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Status" tab — live server-side system checks, read-only.
	 *
	 * Layout:
	 *  - Top: Recommendations panel (warnings + suggestions for fixes/improvements).
	 *  - Below: Six panels in a two-column CSS grid for raw environment data.
	 */
	private static function render_section_status() {

		// ---- Helpers -----------------------------------------------------------

		/**
		 * Convert a PHP ini size string (e.g. "128M", "1G") to bytes.
		 * Returns -1 when the value is unlimited ("-1").
		 */
		$parse_bytes = static function ( $val ) {
			$val = trim( (string) $val );
			if ( '-1' === $val ) {
				return -1;
			}
			$last = strtolower( substr( $val, -1 ) );
			$num  = (float) $val;
			switch ( $last ) {
				case 'g':
					$num *= 1024;
					// fall through
				case 'm':
					$num *= 1024;
					// fall through
				case 'k':
					$num *= 1024;
			}
			return (int) $num;
		};

		/**
		 * Return a coloured status badge span.
		 *
		 * @param string $type  ok | warn | error | info
		 * @param string $label Text to display inside the badge.
		 * @return string Safe HTML.
		 */
		$badge = static function ( $type, $label ) {
			return '<span class="sob-status-badge sob-status-' . esc_attr( $type ) . '">'
				. esc_html( $label )
				. '</span>';
		};

		/**
		 * Echo one status-table row.
		 *
		 * @param string $label      Left-side label.
		 * @param string $value      Right-side value text (will be esc_html'd).
		 * @param string $badge_html Optional badge HTML (already escaped — echoed raw).
		 */
		$row = static function ( $label, $value, $badge_html = '' ) {
			echo '<div class="sob-status-row">';
			echo '<span class="sob-status-label">' . esc_html( $label ) . '</span>';
			echo '<span class="sob-status-value">' . esc_html( $value );
			if ( $badge_html ) {
				echo ' ' . $badge_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by $badge() which escapes internally.
			}
			echo '</span>';
			echo '</div>';
		};

		// =========================================================================
		// Gather all data up-front so we can render both check rows and the
		// recommendations panel from a single source of truth.
		// =========================================================================

		// ---- PHP ----
		$php_ver    = phpversion();
		$mem        = ini_get( 'memory_limit' );
		$max_ex     = ini_get( 'max_execution_time' );
		$post_max   = ini_get( 'post_max_size' );
		$upload_max = ini_get( 'upload_max_filesize' );
		$has_zlib   = extension_loaded( 'zlib' );
		$has_mb     = extension_loaded( 'mbstring' );

		$mem_bytes = $parse_bytes( $mem );

		if ( version_compare( $php_ver, '7.4', '>=' ) ) {
			$php_ver_badge = $badge( 'ok', 'OK' );
		} elseif ( version_compare( $php_ver, '7.0', '>=' ) ) {
			$php_ver_badge = $badge( 'warn', 'Warning' );
		} else {
			$php_ver_badge = $badge( 'error', 'Error' );
		}

		if ( -1 === $mem_bytes ) {
			$mem_badge = $badge( 'ok', 'Unlimited' );
		} elseif ( $mem_bytes >= 128 * 1024 * 1024 ) {
			$mem_badge = $badge( 'ok', 'OK' );
		} elseif ( $mem_bytes >= 64 * 1024 * 1024 ) {
			$mem_badge = $badge( 'warn', 'Warning' );
		} else {
			$mem_badge = $badge( 'error', 'Low' );
		}

		$max_ex_val   = (int) $max_ex;
		$max_ex_badge = ( 0 === $max_ex_val || $max_ex_val >= 30 )
			? $badge( 'ok', 'OK' )
			: $badge( 'warn', 'Warning' );


		// ---- WordPress ----
		$wp_ver     = get_bloginfo( 'version' );
		$theme      = wp_get_theme();
		$theme_name = $theme->get( 'Name' ) . ' v' . $theme->get( 'Version' );
		$parent     = $theme->parent();
		$is_multi   = is_multisite();
		$wp_debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$sc_debug   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$wp_mem     = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '64M';
		$wp_max_mem = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '256M';
		$cron_dis   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$wp_mem_bytes = $parse_bytes( $wp_mem );
		$wp_mem_badge = ( -1 === $wp_mem_bytes || $wp_mem_bytes >= 128 * 1024 * 1024 )
			? $badge( 'ok', 'OK' )
			: $badge( 'warn', 'Low' );

		// ---- Cache directory ----
		$cache_dir    = SOB_CACHE_DIR;
		$dir_exists   = is_dir( $cache_dir );
		$dir_writable = $dir_exists ? wp_is_writable( $cache_dir ) : wp_is_writable( dirname( $cache_dir ) );
		$ht_path      = get_home_path() . '.htaccess';
		$ht_exists    = file_exists( $ht_path );
		$ht_writable  = $ht_exists ? wp_is_writable( $ht_path ) : wp_is_writable( dirname( $ht_path ) );
		$wc_writable  = wp_is_writable( WP_CONTENT_DIR );
		$cache_stats  = SOB_Minify::get_cache_stats();
		$cache_size   = $cache_stats['size'] > 0 ? SOB_Minify::human_filesize( $cache_stats['size'] ) : '0 B';
		$cache_count  = (int) $cache_stats['count'];


		// ---- Active plugins ----
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins   = get_plugins();
		$active_slugs  = get_option( 'active_plugins', array() );
		$active_plugins = array();
		foreach ( $active_slugs as $slug ) {
			if ( isset( $all_plugins[ $slug ] ) ) {
				$active_plugins[ $slug ] = $all_plugins[ $slug ];
			}
		}

		// Known potential conflicts keyed by plugin file (folder/file.php).
		$known_conflicts = array(
			// Caching plugins.
			'w3-total-cache/w3-total-cache.php'          => array( 'type' => 'warn', 'note' => 'May conflict — caching' ),
			'wp-super-cache/wp-cache.php'                => array( 'type' => 'warn', 'note' => 'May conflict — caching' ),
			'wp-rocket/wp-rocket.php'                    => array( 'type' => 'warn', 'note' => 'May conflict — caching & minification' ),
			'litespeed-cache/litespeed-cache.php'        => array( 'type' => 'warn', 'note' => 'May conflict — caching' ),
			'autoptimize/autoptimize.php'                => array( 'type' => 'warn', 'note' => 'May conflict — minification' ),
			'hummingbird-performance/wp-hummingbird.php' => array( 'type' => 'warn', 'note' => 'May conflict — caching' ),
			// Minification plugins.
			'fast-velocity-minify/fvm.php'               => array( 'type' => 'warn', 'note' => 'May conflict — minification' ),
			'asset-cleanup/asset-cleanup.php'            => array( 'type' => 'warn', 'note' => 'May conflict — asset management' ),
			// Page builders.
			'elementor/elementor.php'                    => array( 'type' => 'info', 'note' => 'Test output buffering with this plugin' ),
			'divi-builder/divi-builder.php'              => array( 'type' => 'info', 'note' => 'Test output buffering with this plugin' ),
			'bb-plugin/fl-builder.php'                   => array( 'type' => 'info', 'note' => 'Test output buffering with this plugin' ),
			'beaver-builder-lite-version/fl-builder.php' => array( 'type' => 'info', 'note' => 'Test output buffering with this plugin' ),
		);

		$flagged_plugins = array();
		$other_plugins   = array();
		foreach ( $active_plugins as $slug => $data ) {
			if ( isset( $known_conflicts[ $slug ] ) ) {
				$flagged_plugins[ $slug ] = array_merge( $data, $known_conflicts[ $slug ] );
			} else {
				$other_plugins[ $slug ] = $data;
			}
		}

		// ---- Server environment ----
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$server_soft    = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'N/A';
		$doc_root       = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : 'N/A';
		// phpcs:enable
		$server_os      = PHP_OS;
		$max_input_vars = ini_get( 'max_input_vars' );
		$has_curl       = function_exists( 'curl_version' );
		$openssl_ver    = defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'N/A';


		// ---- Plugin info ----
		$opts      = sob_get_options();
		$cache_url = SOB_Minify::cache_url();




		// =========================================================================
		// Output HTML
		// =========================================================================

		// Shorthand for On/Off badge.
		$on_off = static function ( $val ) use ( $badge ) {
			return $badge( $val ? 'ok' : 'error', $val ? 'On' : 'Off' );
		};
		?>

		<div class="sob-status-tab-header">
			<h2><?php esc_html_e( 'System Status', 'site-optimizer-by-beplus-master' ); ?></h2>
		</div>

		<?php
		// ---- Recommendations panel (warnings + suggestions) ----------------------
		self::render_status_recommendations( $opts, $dir_writable, $dir_exists, $ht_writable, $ht_exists, $wc_writable, $php_ver, $wp_ver, $wp_debug, $is_multi, $has_zlib, $mem_bytes, $flagged_plugins );
		?>

		<div class="sob-status-grid">

			<?php // ---- Panel 1: PHP Environment -------------------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'PHP Environment', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body sob-status-table">
					<?php
					$row( 'PHP Version', $php_ver, $php_ver_badge );
					$row( 'Memory Limit', $mem, $mem_badge );
					$row( 'Max Execution Time', $max_ex . 's', $max_ex_badge );
					$row( 'Post Max Size', $post_max );
					$row( 'Upload Max Filesize', $upload_max );
					$row( 'Zlib (gzip)', $has_zlib ? 'Enabled' : 'Disabled', $badge( $has_zlib ? 'ok' : 'warn', $has_zlib ? 'OK' : 'Missing' ) );
					$row( 'Mbstring', $has_mb ? 'Enabled' : 'Disabled', $badge( $has_mb ? 'ok' : 'warn', $has_mb ? 'OK' : 'Missing' ) );
					?>
				</div>
			</div>

			<?php // ---- Panel 2: WordPress Environment -------------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'WordPress Environment', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body sob-status-table">
					<?php
					$row( 'WordPress Version', $wp_ver, $badge( 'info', 'Info' ) );
					$row( 'Active Theme', $theme_name );
					if ( $parent ) {
						$row( 'Parent Theme', $parent->get( 'Name' ) . ' v' . $parent->get( 'Version' ) );
					}
					$row( 'Multisite', $is_multi ? 'Yes (not recommended)' : 'No', $badge( $is_multi ? 'warn' : 'ok', $is_multi ? 'Warning' : 'OK' ) );
					$row( 'WP_DEBUG', $wp_debug ? 'On (development)' : 'Off (production)', $badge( $wp_debug ? 'warn' : 'ok', $wp_debug ? 'Warning' : 'OK' ) );
					$row( 'SCRIPT_DEBUG', $sc_debug ? 'On' : 'Off', $badge( $sc_debug ? 'warn' : 'ok', $sc_debug ? 'Warning' : 'OK' ) );
					$row( 'WP Memory Limit', $wp_mem, $wp_mem_badge );
					$row( 'WP Max Memory Limit', $wp_max_mem );
					$row( 'WP Cron', $cron_dis ? 'Disabled (external cron)' : 'Enabled', $badge( 'info', 'Info' ) );
					?>
				</div>
			</div>

			<?php // ---- Panel 3: Cache Directory -------------------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'Cache Directory & Permissions', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body sob-status-table">
					<?php
					$row( 'Cache Directory', $cache_dir );
					$row( 'Directory Exists', $dir_exists ? 'Yes' : 'No — will be created on first use', $badge( $dir_exists ? 'ok' : 'warn', $dir_exists ? 'OK' : 'Warning' ) );
					$row( 'Directory Writable', $dir_writable ? 'Yes' : 'No', $badge( $dir_writable ? 'ok' : 'error', $dir_writable ? 'OK' : 'Error' ) );
					$row( '.htaccess Path', $ht_path );
					$row( '.htaccess Exists', $ht_exists ? 'Yes' : 'No', $badge( $ht_exists ? 'ok' : 'warn', $ht_exists ? 'OK' : 'Warning' ) );
					$row( '.htaccess Writable', $ht_writable ? 'Yes' : 'No', $badge( $ht_writable ? 'ok' : 'error', $ht_writable ? 'OK' : 'Error' ) );
					$row( 'wp-content Writable', $wc_writable ? 'Yes' : 'No', $badge( $wc_writable ? 'ok' : 'error', $wc_writable ? 'OK' : 'Error' ) );
					$row( 'Cached Files', (string) $cache_count );
					$row( 'Cache Size', $cache_size );
					?>
				</div>
			</div>

			<?php // ---- Panel 4: Active Plugins --------------------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'Active Plugins', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body">
					<?php if ( empty( $active_plugins ) ) : ?>
						<p class="description"><?php esc_html_e( 'No active plugins found.', 'site-optimizer-by-beplus-master' ); ?></p>
					<?php else : ?>

						<?php if ( ! empty( $flagged_plugins ) ) : ?>
						<div class="sob-plugins-flagged">
							<?php foreach ( $flagged_plugins as $slug => $data ) :
								$c_type = $data['type'];
								$c_icon = 'warn' === $c_type ? '⚠️' : 'ℹ️';
							?>
							<div class="sob-plugin-row sob-plugin-row--flagged">
								<span class="sob-plugin-info">
									<strong><?php echo esc_html( $data['Name'] ); ?></strong>
									<span class="sob-plugin-ver">v<?php echo esc_html( $data['Version'] ); ?></span>
								</span>
								<span class="sob-status-badge sob-status-<?php echo esc_attr( $c_type ); ?>">
									<?php echo esc_html( $c_icon . ' ' . $data['note'] ); ?>
								</span>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<?php if ( ! empty( $other_plugins ) ) : ?>
						<details class="sob-all-plugins">
							<summary>
								<?php
								/* translators: %d: number of non-flagged active plugins. */
								printf( esc_html__( 'Show all %d plugins', 'site-optimizer-by-beplus-master' ), count( $other_plugins ) );
								?>
							</summary>
							<div class="sob-plugins-all">
								<?php foreach ( $other_plugins as $slug => $data ) : ?>
								<div class="sob-plugin-row">
									<span class="sob-plugin-info">
										<strong><?php echo esc_html( $data['Name'] ); ?></strong>
										<span class="sob-plugin-ver">v<?php echo esc_html( $data['Version'] ); ?></span>
									</span>
								</div>
								<?php endforeach; ?>
							</div>
						</details>
						<?php endif; ?>

					<?php endif; ?>
				</div>
			</div>

			<?php // ---- Panel 5: Server Environment ----------------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'Server Environment', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body sob-status-table">
					<?php
					$row( 'Server Software', $server_soft );
					$row( 'Server OS', $server_os );
					$row( 'Document Root', $doc_root );
					$row( 'HTTPS', is_ssl() ? 'Yes' : 'No', $badge( is_ssl() ? 'ok' : 'warn', is_ssl() ? 'OK' : 'Warning' ) );
					$row( 'Home URL', home_url() );
					$row( 'Site URL', site_url() );
					$row( 'WordPress Root', ABSPATH );
					$row( 'wp-content Directory', WP_CONTENT_DIR );
					$row( 'Max Input Vars', (string) $max_input_vars );
					$row( 'cURL', $has_curl ? 'Enabled' : 'Disabled', $badge( $has_curl ? 'ok' : 'warn', $has_curl ? 'OK' : 'Missing' ) );
					$row( 'OpenSSL', $openssl_ver );
					?>
				</div>
			</div>

			<?php // ---- Panel 6: BePlus Optimizer Settings --------------------- ?>
			<div class="sob-card">
				<div class="sob-card-header">
					<h2><?php esc_html_e( 'BePlus Optimizer Settings', 'site-optimizer-by-beplus-master' ); ?></h2>
				</div>
				<div class="sob-card-body sob-status-table">
					<?php
					$row( 'Plugin Version', SOB_VERSION, $badge( 'info', 'Info' ) );
					$row( 'Plugin Directory', SOB_PLUGIN_DIR );
					$row( 'Cache Directory', SOB_CACHE_DIR );
					$row( 'Cache URL', $cache_url );
					$row( 'Options Key', SOB_OPTIONS_KEY );
					$row( 'Master Cache Toggle', ! empty( $opts['cache_enabled'] ) ? 'On' : 'Off', $on_off( ! empty( $opts['cache_enabled'] ) ) );
					$row( 'CSS Minification', ! empty( $opts['minify_css_files'] ) ? 'On' : 'Off', $on_off( ! empty( $opts['minify_css_files'] ) ) );
					$row( 'JS Minification', ! empty( $opts['minify_js_files'] ) ? 'On' : 'Off', $on_off( ! empty( $opts['minify_js_files'] ) ) );
					$row( 'Lazy Load', ! empty( $opts['lazy_load'] ) ? 'On' : 'Off', $on_off( ! empty( $opts['lazy_load'] ) ) );
					$row( 'JS Defer', ! empty( $opts['js_defer'] ) ? 'On' : 'Off', $on_off( ! empty( $opts['js_defer'] ) ) );
					?>
				</div>
			</div>

		</div><!-- .sob-status-grid -->
		<?php
	}

	/**
	 * Render the Recommendations panel shown at the top of the Status tab.
	 *
	 * Builds a list of:
	 *  - ❌ Errors   — block correct operation (e.g. cache dir not writable).
	 *  - ⚠️ Warnings — sub-optimal config (e.g. WP_DEBUG on, old PHP, multisite).
	 *  - 💡 Suggestions — recommended features not yet enabled (lazy load, defer JS…).
	 *  - ✅ Good     — shown only when everything looks healthy.
	 *
	 * Each item has a one-line "what" + "why/how" message and, when relevant,
	 * a link that jumps to the right tab so the user can act immediately.
	 *
	 * @param array $opts            Current option values.
	 * @param bool  $dir_writable    Whether the cache directory (or its parent) is writable.
	 * @param bool  $dir_exists      Whether the cache directory exists.
	 * @param bool  $ht_writable     Whether the .htaccess file (or its parent) is writable.
	 * @param bool  $ht_exists       Whether the .htaccess file exists.
	 * @param bool  $wc_writable     Whether wp-content is writable.
	 * @param string $php_ver        Current PHP version string.
	 * @param string $wp_ver         Current WordPress version string.
	 * @param bool  $wp_debug        Whether WP_DEBUG is on.
	 * @param bool  $is_multi        Whether this is a multisite install.
	 * @param bool  $has_zlib        Whether the Zlib PHP extension is loaded.
	 * @param int   $mem_bytes       PHP memory limit in bytes (-1 = unlimited).
	 * @param array $flagged_plugins Detected potentially-conflicting active plugins.
	 */
	private static function render_status_recommendations(
		$opts,
		$dir_writable,
		$dir_exists,
		$ht_writable,
		$ht_exists,
		$wc_writable,
		$php_ver,
		$wp_ver,
		$wp_debug,
		$is_multi,
		$has_zlib,
		$mem_bytes,
		$flagged_plugins
	) {
		$settings_url = admin_url( 'options-general.php?page=site-optimizer-by-beplus-master' );
		$tab_link = static function ( $tab, $label ) use ( $settings_url ) {
			return '<a href="' . esc_url( $settings_url . '#sob-tab-' . $tab ) . '" class="sob-rec-link">' . esc_html( $label ) . ' →</a>';
		};

		$errors      = array();
		$warnings    = array();
		$suggestions = array();

		// =====================================================================
		// Errors — things that break or block the plugin
		// =====================================================================

		if ( ! $wc_writable ) {
			$errors[] = array(
				'title'  => __( 'wp-content is not writable', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'The plugin cannot create the cache directory inside wp-content. Set wp-content permissions to 755 (or contact your host).', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( ! $dir_writable ) {
			$errors[] = array(
				'title'  => __( 'Cache directory is not writable', 'site-optimizer-by-beplus-master' ),
				'detail' => sprintf(
					/* translators: %s: cache directory path */
					__( 'Minified CSS/JS files cannot be written to %s. The plugin will silently fall back to the original (un-minified) files. Set the directory to 755.', 'site-optimizer-by-beplus-master' ),
					'<code>' . esc_html( SOB_CACHE_DIR ) . '</code>'
				),
			);
		}

		if ( ! empty( $opts['cache_headers'] ) && ! $ht_writable ) {
			$errors[] = array(
				'title'  => __( 'Browser caching is on but .htaccess is not writable', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'The browser cache rules cannot be injected into .htaccess. Make the .htaccess file writable (644) and re-save the Cache Exclusions tab.', 'site-optimizer-by-beplus-master' ),
				'action' => $tab_link( 'exclusions', __( 'Open Cache Exclusions', 'site-optimizer-by-beplus-master' ) ),
			);
		}

		// =====================================================================
		// Warnings — sub-optimal but not breaking
		// =====================================================================

		if ( empty( $opts['cache_enabled'] ) ) {
			$warnings[] = array(
				'title'  => __( 'Master cache is OFF', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'All CSS/JS minification, caching, lazy loading, and cleanup features are currently disabled. Turn on the master toggle on the Dashboard to start optimising.', 'site-optimizer-by-beplus-master' ),
				'action' => $tab_link( 'dashboard', __( 'Open Dashboard', 'site-optimizer-by-beplus-master' ) ),
			);
		}

		if ( version_compare( $php_ver, '7.4', '<' ) ) {
			$warnings[] = array(
				'title'  => sprintf(
					/* translators: %s: detected PHP version */
					__( 'PHP %s is outdated', 'site-optimizer-by-beplus-master' ),
					esc_html( $php_ver )
				),
				'detail' => __( 'WordPress recommends PHP 7.4 or newer (8.1+ for best performance). Ask your host to upgrade.', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( -1 !== $mem_bytes && $mem_bytes < 128 * 1024 * 1024 ) {
			$warnings[] = array(
				'title'  => __( 'PHP memory_limit is low', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'Minifying many large CSS/JS files can hit the PHP memory limit. Set memory_limit to at least 128M in php.ini or wp-config.php.', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( $wp_debug ) {
			$warnings[] = array(
				'title'  => __( 'WP_DEBUG is on', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'WP_DEBUG should be OFF on production sites. Set WP_DEBUG to false in wp-config.php to avoid surfacing PHP notices to visitors.', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( $is_multi ) {
			$warnings[] = array(
				'title'  => __( 'WordPress Multisite detected', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'This plugin is not designed for Multisite. The cache directory and .htaccess block are shared across all sub-sites. Install it on individual sub-sites instead of network-activating.', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( ! $has_zlib ) {
			$warnings[] = array(
				'title'  => __( 'Zlib extension missing', 'site-optimizer-by-beplus-master' ),
				'detail' => __( 'PHP Zlib is not loaded, so gzip compression cannot be applied at the PHP level. Ask your host to enable the zlib extension.', 'site-optimizer-by-beplus-master' ),
			);
		}

		if ( ! empty( $flagged_plugins ) ) {
			$names = array();
			foreach ( $flagged_plugins as $data ) {
				$names[] = $data['Name'];
			}
			$warnings[] = array(
				'title'  => __( 'Another caching/minification plugin is active', 'site-optimizer-by-beplus-master' ),
				'detail' => sprintf(
					/* translators: %s: comma-separated list of plugin names */
					__( 'Running two optimisation plugins can produce double-minified or broken assets. Detected: %s. Deactivate one of them to be safe.', 'site-optimizer-by-beplus-master' ),
					'<em>' . esc_html( implode( ', ', $names ) ) . '</em>'
				),
			);
		}

		// =====================================================================
		// Suggestions — recommended features not yet enabled
		// =====================================================================

		// Only suggest enabling features when the master cache is ON; otherwise the
		// "Master cache is OFF" warning above is the priority action.
		if ( ! empty( $opts['cache_enabled'] ) ) {

			if ( empty( $opts['lazy_load'] ) ) {
				$suggestions[] = array(
					'title'  => __( 'Enable lazy loading', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Add loading="lazy" to off-screen images so they only load when scrolled into view — large Core Web Vitals win on image-heavy pages.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cache_files', __( 'Open Cache Files tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['minify_css_files'] ) && $dir_writable ) {
				$suggestions[] = array(
					'title'  => __( 'Minify CSS files', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Strip comments and whitespace from every enqueued CSS file and serve the cached version. Typically cuts CSS payload 10–30%.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cache_files', __( 'Open Cache Files tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['minify_js_files'] ) && $dir_writable ) {
				$suggestions[] = array(
					'title'  => __( 'Minify JS files', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Strip comments and whitespace from every enqueued JS file. Saves bytes and improves time-to-interactive.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cache_files', __( 'Open Cache Files tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['js_defer'] ) ) {
				$suggestions[] = array(
					'title'  => __( 'Defer non-critical JS', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Add the defer attribute to scripts so they fetch in parallel and execute after HTML parsing — major boost to First Contentful Paint.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cache_files', __( 'Open Cache Files tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['remove_emoji'] ) ) {
				$suggestions[] = array(
					'title'  => __( 'Remove emoji scripts', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'WordPress loads a JS+CSS pair to polyfill emoji on old browsers that no longer exist. Removing it shaves ~10KB and one HTTP request from every page.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cleanup', __( 'Open Cleanup tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['cache_headers'] ) && $ht_writable ) {
				$suggestions[] = array(
					'title'  => __( 'Enable browser cache headers', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Inject 1-year cache headers and gzip/brotli rules into .htaccess so returning visitors load static assets from their local cache.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'exclusions', __( 'Open Cache Exclusions tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}

			if ( empty( $opts['html_minify'] ) ) {
				$suggestions[] = array(
					'title'  => __( 'Minify HTML output', 'site-optimizer-by-beplus-master' ),
					'detail' => __( 'Collapse whitespace between HTML tags in the rendered page. Small per-request win that adds up across the whole site.', 'site-optimizer-by-beplus-master' ),
					'action' => $tab_link( 'cleanup', __( 'Open Cleanup tab', 'site-optimizer-by-beplus-master' ) ),
				);
			}
		}

		$total = count( $errors ) + count( $warnings ) + count( $suggestions );

		// ---- Render -------------------------------------------------------------
		?>
		<div class="sob-card sob-recommend-card">
			<div class="sob-card-header">
				<h2>
					<?php esc_html_e( 'Recommendations', 'site-optimizer-by-beplus-master' ); ?>
					<?php if ( $total > 0 ) : ?>
						<span class="sob-rec-count"><?php echo esc_html( (string) $total ); ?></span>
					<?php endif; ?>
				</h2>
				<p>
					<?php esc_html_e( 'Targeted fixes and improvements to keep caching healthy and your site fast.', 'site-optimizer-by-beplus-master' ); ?>
				</p>
			</div>
			<div class="sob-card-body">

				<?php if ( 0 === $total ) : ?>
					<div class="sob-rec-item sob-rec-item--ok">
						<span class="sob-rec-icon" aria-hidden="true">✅</span>
						<div class="sob-rec-text">
							<strong><?php esc_html_e( 'Everything looks good', 'site-optimizer-by-beplus-master' ); ?></strong>
							<p><?php esc_html_e( 'No errors, warnings, or pending recommendations were detected on this site.', 'site-optimizer-by-beplus-master' ); ?></p>
						</div>
					</div>
				<?php else : ?>

					<?php foreach ( $errors as $item ) : ?>
					<div class="sob-rec-item sob-rec-item--error">
						<span class="sob-rec-icon" aria-hidden="true">⛔</span>
						<div class="sob-rec-text">
							<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<p>
								<?php
								// $detail may contain pre-escaped HTML (e.g. <code>, <em>) built above.
								echo wp_kses(
									$item['detail'],
									array(
										'code' => array(),
										'em'   => array(),
										'strong' => array(),
									)
								);
								?>
							</p>
							<?php if ( ! empty( $item['action'] ) ) : ?>
								<?php echo wp_kses( $item['action'], array( 'a' => array( 'href' => array(), 'class' => array() ) ) ); ?>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>

					<?php foreach ( $warnings as $item ) : ?>
					<div class="sob-rec-item sob-rec-item--warn">
						<span class="sob-rec-icon" aria-hidden="true">⚠️</span>
						<div class="sob-rec-text">
							<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<p>
								<?php
								echo wp_kses(
									$item['detail'],
									array(
										'code'   => array(),
										'em'     => array(),
										'strong' => array(),
									)
								);
								?>
							</p>
							<?php if ( ! empty( $item['action'] ) ) : ?>
								<?php echo wp_kses( $item['action'], array( 'a' => array( 'href' => array(), 'class' => array() ) ) ); ?>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>

					<?php foreach ( $suggestions as $item ) : ?>
					<div class="sob-rec-item sob-rec-item--tip">
						<span class="sob-rec-icon" aria-hidden="true">💡</span>
						<div class="sob-rec-text">
							<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<p><?php echo esc_html( $item['detail'] ); ?></p>
							<?php if ( ! empty( $item['action'] ) ) : ?>
								<?php echo wp_kses( $item['action'], array( 'a' => array( 'href' => array(), 'class' => array() ) ) ); ?>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>

				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "AI Optimizer" tab — coming-soon placeholder.
	 *
	 * Displayed outside the settings <form> (no fields to save).
	 * Describes planned v2.0 AI-powered features that are not yet available.
	 */
	private static function render_section_ai_optimizer() {
		?>
		<div class="sob-card sob-ai-card">
			<div class="sob-card-body sob-ai-body">

				<div class="sob-ai-icon" aria-hidden="true">🤖</div>

				<h2 class="sob-ai-title"><?php esc_html_e( 'AI Optimizer', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p class="sob-ai-subtitle"><?php esc_html_e( 'Intelligent, automatic performance optimization — powered by AI.', 'site-optimizer-by-beplus-master' ); ?></p>

				<hr class="sob-ai-divider">

				<ul class="sob-ai-features" aria-label="<?php esc_attr_e( 'Upcoming features', 'site-optimizer-by-beplus-master' ); ?>">
					<li>
						<span class="sob-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'site-optimizer-by-beplus-master' ); ?>">🔒</span>
						<span><?php esc_html_e( 'Smart image compression &amp; next-gen format conversion', 'site-optimizer-by-beplus-master' ); ?></span>
					</li>
					<li>
						<span class="sob-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'site-optimizer-by-beplus-master' ); ?>">🔒</span>
						<span><?php esc_html_e( 'AI-powered critical CSS extraction', 'site-optimizer-by-beplus-master' ); ?></span>
					</li>
					<li>
						<span class="sob-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'site-optimizer-by-beplus-master' ); ?>">🔒</span>
						<span><?php esc_html_e( 'Automated performance scoring &amp; fix suggestions', 'site-optimizer-by-beplus-master' ); ?></span>
					</li>
				</ul>

				<hr class="sob-ai-divider">

				<div class="notice notice-info inline sob-ai-notice">
					<p><?php esc_html_e( 'This feature is currently in development and will be available in a future release of BePlus Optimizer. Stay tuned for updates!', 'site-optimizer-by-beplus-master' ); ?></p>
				</div>

				<div class="sob-ai-version-badge">
					<?php esc_html_e( 'Coming in v2.0', 'site-optimizer-by-beplus-master' ); ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cache Exclusions" tab — page exclusions, user exclusions, browser cache.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_exclusions( $opts ) {
		$htaccess_writable = SOB_Htaccess::is_writable();

		// ---- Page Exclusions card -------------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'Page Exclusions', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Fine-tune which pages bypass caching and optimization. Use URL patterns here for global rules, or the per-post meta box for individual pages.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_cache_exclude_pages"><?php esc_html_e( 'Exclude Pages from Cache', 'site-optimizer-by-beplus-master' ); ?></label>
						<p class="sob-row-desc"><?php esc_html_e( 'One URL or path per line.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
					<div class="sob-form-row-field">
						<textarea id="sob_cache_exclude_pages"
							name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[cache_exclude_pages]"
							rows="6" class="large-text code"><?php echo esc_textarea( $opts['cache_exclude_pages'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Pages whose URL contains any of these strings will be served original (un-cached) CSS/JS files.', 'site-optimizer-by-beplus-master' ); ?><br>
							<?php esc_html_e( 'Examples:', 'site-optimizer-by-beplus-master' ); ?>
							<code>/checkout/</code> &nbsp; <code>/my-account/</code> &nbsp; <code>/cart/</code>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- User-Based Exclusions card -------------------------------------
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'User-Based Exclusions', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p><?php esc_html_e( 'Control whether optimized CSS and JS files are served to logged-in users, or bypassed to ensure user-specific pages render correctly.', 'site-optimizer-by-beplus-master' ); ?></p>
			</div>
			<div class="sob-card-body">

				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_cache_for_logged_in"><?php esc_html_e( 'Enable Cache for Logged-In Users', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_cache_for_logged_in"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[cache_for_logged_in]" value="1"
								<?php checked( $opts['cache_for_logged_in'], 1 ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Serve cached/minified CSS and JS to logged-in users.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'By default, cached CSS/JS is skipped for logged-in users because pages may contain user-specific content. Enable this only if your pages look the same regardless of login state (e.g. a blog with no member-only content).', 'site-optimizer-by-beplus-master' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- Browser Cache card ---------------------------------------------
		// IMP-3: Detect Nginx and show copy-paste config instead of the
		// .htaccess toggle, which has no effect on Nginx servers.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
		$is_nginx = ( false !== stripos( $server_software, 'nginx' ) );
		?>
		<div class="sob-card">
			<div class="sob-card-header">
				<h2><?php esc_html_e( 'Browser Cache', 'site-optimizer-by-beplus-master' ); ?></h2>
				<p>
					<?php if ( $is_nginx ) : ?>
						<?php esc_html_e( 'Nginx detected. Copy the configuration block below into your server\'s nginx.conf or site virtual host to add long-lived browser cache headers.', 'site-optimizer-by-beplus-master' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Add long-lived browser cache headers and gzip/brotli compression to your .htaccess file, so returning visitors load your site faster from their local cache.', 'site-optimizer-by-beplus-master' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="sob-card-body">

				<?php if ( $is_nginx ) : ?>
				<div class="notice notice-info sob-notice-warning inline">
					<p><strong><?php esc_html_e( 'Nginx server detected.', 'site-optimizer-by-beplus-master' ); ?></strong> <?php esc_html_e( 'The .htaccess option below has no effect on Nginx. Ask your hosting provider or server administrator to add the following block to your nginx.conf or site configuration:', 'site-optimizer-by-beplus-master' ); ?></p>
				</div>
				<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 16px;overflow-x:auto;font-size:12px;margin:12px 0;border-radius:4px;"><?php echo esc_html(
'# Browser cache — static assets (1 year)
location ~* \.(ico|jpg|jpeg|png|gif|webp|avif|svg|woff|woff2|ttf|otf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

location ~* \.(css|js)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

# HTML — always revalidate
location ~* \.(html|htm)$ {
    add_header Cache-Control "no-cache, must-revalidate";
}

# Gzip compression
gzip on;
gzip_types text/plain text/css text/javascript application/javascript
           application/json image/svg+xml font/woff2;
gzip_min_length 1024;'
				); ?></pre>
				<?php else : ?>

				<?php if ( ! $htaccess_writable ) : ?>
				<div class="notice notice-warning sob-notice-warning inline">
					<p><?php esc_html_e( 'Your .htaccess file is not writable. Browser caching rules cannot be injected automatically. Set the file to 644 permissions (or ask your host) and try again, or add the rules manually.', 'site-optimizer-by-beplus-master' ); ?></p>
				</div>
				<?php endif; ?>

				<div class="sob-form-row">
					<div class="sob-form-row-label">
						<label for="sob_cache_headers"><?php esc_html_e( 'Enable Browser Caching', 'site-optimizer-by-beplus-master' ); ?></label>
					</div>
					<div class="sob-form-row-field">
						<label class="sob-check-label">
							<input type="checkbox" id="sob_cache_headers"
								name="<?php echo esc_attr( SOB_OPTIONS_KEY ); ?>[cache_headers]" value="1"
								<?php checked( $opts['cache_headers'], 1 ); ?>
								<?php disabled( ! $htaccess_writable, true ); ?>>
							<span class="sob-check-text"><?php esc_html_e( 'Inject browser caching rules and gzip/brotli compression directives into .htaccess. Rules are wrapped in a clearly labelled block and are never duplicated.', 'site-optimizer-by-beplus-master' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Requires Apache with mod_expires, mod_headers, mod_deflate. Disabling this option removes the injected rules automatically.', 'site-optimizer-by-beplus-master' ); ?></p>
					</div>
				</div>

				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Admin bar
	// =========================================================================

	/**
	 * Add the "BePlus Optimizer" menu to the WordPress admin bar.
	 *
	 * Renders a parent node with a green dot indicator and a single child
	 * panel containing an SVG donut ring, cache size/file stats, and a
	 * "Clear CSS / JS Cache" button.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public static function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts            = sob_get_options();
		$dot_color       = ! empty( $opts['cache_enabled'] ) ? '#00a32a' : '#dc3232';
		$stats           = SOB_Minify::get_cache_stats();
		$clear_cache_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sob_clear_cache' ),
			'sob_clear_cache'
		);

		// Parent node — colour-coded dot (green = on, red = off) + "BePlus Optimizer".
		$wp_admin_bar->add_node(
			array(
				'id'    => 'sob-cache',
				'title' => '<span class="sob-ab-dot" aria-hidden="true" style="background:' . esc_attr( $dot_color ) . '"></span>'
					. esc_html__( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ),
				'href'  => admin_url( 'options-general.php?page=site-optimizer-by-beplus-master' ),
				'meta'  => array( 'class' => 'sob-adminbar-root' ),
			)
		);

		// Single child panel node with full cache info + clear button.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'sob-cache-panel',
				'parent' => 'sob-cache',
				'title'  => self::build_adminbar_panel( $stats, $clear_cache_url, $dot_color ),
				'href'   => false,
				'meta'   => array( 'class' => 'sob-adminbar-panel-node' ),
			)
		);
	}

	/**
	 * Build the HTML for the admin bar cache-info panel.
	 *
	 * Renders a CSS-only SVG donut ring showing cache usage as a percentage
	 * of a 10 MB reference maximum, plus size/file stats and a clear button.
	 *
	 * Ring colours:
	 *  - Grey   : 0 % (empty cache)
	 *  - Green  : 1 – 49 %
	 *  - Orange : 50 – 74 %
	 *  - Red    : 75 – 100 %
	 *
	 * The ring uses r = 15.9155 so the circumference ≈ 100, making the
	 * stroke-dasharray value equal to the percentage directly.
	 * stroke-dashoffset = 25 rotates the arc start to 12 o'clock.
	 *
	 * @param  array  $stats           Result of SOB_Minify::get_cache_stats().
	 * @param  string $clear_cache_url Nonce-signed URL for the clear-cache action.
	 * @return string HTML markup (output raw as WP_Admin_Bar node title).
	 */
	private static function build_adminbar_panel( $stats, $clear_cache_url, $dot_color = '#00a32a' ) {
		$max_bytes = 10 * 1024 * 1024; // 10 MB reference maximum.
		$pct       = $stats['size'] > 0
			? min( 100, (int) round( ( $stats['size'] / $max_bytes ) * 100 ) )
			: 0;

		// Progress arc colour: green ≤50%, orange ≤80%, red >80%.
		// When cache is empty the arc is omitted; only the dark track shows.
		if ( $pct <= 50 ) {
			$ring_color = '#00a32a'; // Green.
		} elseif ( $pct <= 80 ) {
			$ring_color = '#dba617'; // Orange.
		} else {
			$ring_color = '#d63638'; // Red.
		}

		$size_label = $stats['size'] > 0
			? esc_html( SOB_Minify::human_filesize( $stats['size'] ) )
			: '0 B';
		$file_count = (int) $stats['count'];

		// SVG donut ring — 52 × 52px display, viewBox 36 × 36 (scale ≈ 1.44×).
		// stroke-width 3 SVG units ≈ 4.3px rendered.
		// font-size   7 SVG units ≈ 10px rendered.
		// dominant-baseline="central" + y="18" vertically centres the label.
		$svg  = '<svg viewBox="0 0 36 36" class="sob-ring-svg" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		$svg .= '<circle cx="18" cy="18" r="15.9155" fill="none" stroke="#3c434a" stroke-width="3"/>';
		if ( $pct > 0 ) {
			$svg .= '<circle cx="18" cy="18" r="15.9155" fill="none"'
				. ' stroke="' . esc_attr( $ring_color ) . '"'
				. ' stroke-width="3"'
				. ' stroke-dasharray="' . esc_attr( $pct . ' ' . ( 100 - $pct ) ) . '"'
				. ' stroke-dashoffset="25"'
				. ' stroke-linecap="round"/>';
		}
		$svg .= '<text x="18" y="18" text-anchor="middle" dominant-baseline="central" class="sob-ring-pct">'
			. esc_html( $pct . '%' )
			. '</text>';
		$svg .= '</svg>';

		// ── Panel HTML ───────────────────────────────────────────────────────
		// Structure:
		//   .sob-ab-panel
		//     .sob-ab-info          ← padded area (header + ring/stats row)
		//       .sob-ab-header      ← green dot + "CSS / JS Cache Info"
		//       .sob-ab-content     ← flex row: ring left, stats right
		//     .sob-ab-sep           ← 1px separator
		//     a.sob-ab-clear-btn    ← full-width flush dark button

		$html  = '<div class="sob-ab-panel">';

		// Padded info area.
		$html .= '<div class="sob-ab-info">';

		// Header: small colour-coded dot + uppercase label.
		$html .= '<div class="sob-ab-header">'
			. '<span class="sob-ab-header-dot" aria-hidden="true" style="background:' . esc_attr( $dot_color ) . '"></span>'
			. '<span class="sob-ab-header-text">'
			. esc_html__( 'CSS / JS Cache Info', 'site-optimizer-by-beplus-master' )
			. '</span>'
			. '</div>';

		// Content: ring (left) + stats (right), vertically centred.
		$html .= '<div class="sob-ab-content">';
		$html .= '<div class="sob-ab-ring-wrap">' . $svg . '</div>';
		$html .= '<div class="sob-ab-stats">';
		$html .= '<p class="sob-ab-stat-row">'
			. '<span class="sob-ab-stat-label">' . esc_html__( 'Size:', 'site-optimizer-by-beplus-master' ) . '</span>'
			. '<span class="sob-ab-stat-size">' . $size_label . '</span>'
			. '</p>';
		$html .= '<p class="sob-ab-stat-row">'
			. '<span class="sob-ab-stat-label">' . esc_html__( 'Files:', 'site-optimizer-by-beplus-master' ) . '</span>'
			. '<span class="sob-ab-stat-files">' . esc_html( $file_count ) . '</span>'
			. '</p>';
		$html .= '</div>'; // .sob-ab-stats
		$html .= '</div>'; // .sob-ab-content

		$html .= '</div>'; // .sob-ab-info

		// 1px separator.
		$html .= '<div class="sob-ab-sep" aria-hidden="true"></div>';

		// Full-width flush clear button.
		$html .= '<a href="' . esc_url( $clear_cache_url ) . '" class="sob-ab-clear-btn">'
			. esc_html__( 'Clear CSS / JS Cache', 'site-optimizer-by-beplus-master' )
			. '</a>';

		$html .= '</div>'; // .sob-ab-panel

		return $html;
	}

	// =========================================================================
	// Quick-enable action handler
	// =========================================================================

	/**
	 * Handle POST to admin-post.php?action=sob_quick_enable.
	 *
	 * Enables exactly one boolean option from the Dashboard recommended-settings
	 * panel — every other key in the saved option array is preserved verbatim.
	 *
	 * BUG-FIX (toggle-leak):
	 *   The previous implementation called sob_get_options(), which merges the
	 *   raw DB row with sob_default_options() *before* writing it back. That
	 *   merge would (1) introduce every default key into the DB row, and
	 *   (2) when defaults later changed, replay those new defaults as if the
	 *   user had explicitly opted-in. Reading the raw row via get_option() and
	 *   modifying only the targeted key guarantees no other toggle is affected.
	 */
	public static function handle_quick_enable() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'site-optimizer-by-beplus-master' ) );
		}

		$option = isset( $_POST['sob_option'] ) ? sanitize_key( wp_unslash( $_POST['sob_option'] ) ) : '';

		// Validate that this is an allowed option key.
		$allowed = array( 'lazy_load', 'minify_css_files', 'minify_js_files', 'js_defer', 'remove_emoji', 'css_minify', 'cache_headers' );
		if ( ! in_array( $option, $allowed, true ) ) {
			wp_die( esc_html__( 'Invalid option key.', 'site-optimizer-by-beplus-master' ) );
		}

		check_admin_referer( 'sob_quick_enable_' . $option, 'sob_quick_enable_nonce' );

		// Read RAW from DB (no defaults merged in) so we modify exactly one key
		// and leave every other previously-saved option untouched.
		$saved = get_option( SOB_OPTIONS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved[ $option ] = 1;
		update_option( SOB_OPTIONS_KEY, $saved );
		sob_flush_options_cache();

		// FC-1: When the browser-cache option is quick-enabled via the Dashboard,
		// the Settings API sanitize_options() callback is not invoked, so we must
		// trigger .htaccess rule injection manually here.
		if ( 'cache_headers' === $option ) {
			SOB_Htaccess::add_rules();
		}

		$redirect = add_query_arg(
			array(
				'page'              => 'site-optimizer-by-beplus-master',
				'sob_quick_enabled' => $option,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	// =========================================================================
	// Master cache toggle AJAX handler
	// =========================================================================

	/**
	 * Handle wp_ajax_sob_toggle_cache.
	 *
	 * Reads the raw saved option directly (not through sob_get_options()) so
	 * that defaults are never merged back into the DB row. Only the
	 * cache_enabled key is modified; every other key remains exactly as it was
	 * last saved — toggling the master cache must never cascade-enable other
	 * features (lazy load, minify, defer, etc.).
	 *
	 * Expects POST fields: nonce, enabled (1 or 0).
	 * Returns JSON: { success: true, cache_enabled: 0|1 }.
	 */
	public static function handle_ajax_toggle_cache() {
		// Nonce check first (also verifies the user is logged in).
		check_ajax_referer( 'sob_toggle_cache', 'nonce' );

		// Capability check after nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// Cast to 0 or 1: any truthy POST value → 1, anything else → 0.
		$enabled = isset( $_POST['enabled'] ) ? (int) (bool) $_POST['enabled'] : 0;

		// Read RAW saved row from DB — no defaults merged. Then mutate only the
		// single cache_enabled key. This guarantees no other toggle is touched.
		$saved = get_option( SOB_OPTIONS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved['cache_enabled'] = $enabled;
		update_option( SOB_OPTIONS_KEY, $saved );

		// Invalidate in-memory cache so any code in this same request that calls
		// sob_get_options() afterwards gets the freshly saved value.
		sob_flush_options_cache();

		wp_send_json_success( array( 'cache_enabled' => $enabled ) );
	}

	// =========================================================================
	// Clear-cache action handler
	// =========================================================================

	/**
	 * Handle POST to admin-post.php?action=sob_clear_cache.
	 */
	public static function handle_clear_cache() {
		// Nonce first — also verifies the user is logged in before any capability check.
		check_admin_referer( 'sob_clear_cache' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'site-optimizer-by-beplus-master' ) );
		}

		$count = SOB_Minify::clear_cache();

		// SEC-1: Store the result in a short-lived per-user transient instead of
		// appending it to the redirect URL. This avoids the notice re-firing if
		// the user bookmarks or shares the URL with the query string attached.
		set_transient( 'sob_cache_cleared_' . get_current_user_id(), $count, 30 );

		$referrer = wp_get_referer();
		if ( ! $referrer ) {
			$referrer = admin_url( 'options-general.php?page=site-optimizer-by-beplus-master' );
		}

		wp_safe_redirect( remove_query_arg( 'sob_cache_cleared', $referrer ) );
		exit;
	}

	/**
	 * Display a success notice after the cache has been cleared.
	 *
	 * Reads the result from a short-lived per-user transient set by
	 * handle_clear_cache() so the notice is shown exactly once regardless
	 * of browser history or URL sharing.
	 */
	public static function maybe_show_cleared_notice() {
		$transient_key = 'sob_cache_cleared_' . get_current_user_id();
		$count         = get_transient( $transient_key );

		if ( false === $count ) {
			return;
		}

		// Consume the transient so the notice only appears once.
		delete_transient( $transient_key );

		$count = absint( $count );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d = number of deleted files */
					esc_html( _n(
						'BePlus Optimizer: %d cached file cleared successfully.',
						'BePlus Optimizer: %d cached files cleared successfully.',
						$count,
						'site-optimizer-by-beplus-master'
					) ),
					absint( $count )
				);
				?>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Per-page cache-disable meta box
	// =========================================================================

	/**
	 * Register the "BePlus Optimizer" meta box on posts and pages only.
	 *
	 * IMP-6: Restricting to core post types avoids cluttering CPT edit screens
	 * (products, events, etc.) where the cache-disable option is rarely useful
	 * and the meta key would create noise in third-party post type queries.
	 */
	public static function register_meta_box() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'sob-page-settings',
				__( 'BePlus Optimizer', 'site-optimizer-by-beplus-master' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'sob_meta_box_save', 'sob_meta_box_nonce' );

		$is_disabled = (bool) get_post_meta( $post->ID, '_sob_disable_cache', true );
		?>
		<p>
			<label>
				<input type="checkbox" name="sob_disable_cache" value="1"
					<?php checked( $is_disabled ); ?>>
				<?php esc_html_e( 'Disable CSS/JS cache optimizations for this page/post.', 'site-optimizer-by-beplus-master' ); ?>
			</label>
		</p>
		<p class="description" style="font-size:11px;margin-top:4px;">
			<?php esc_html_e( 'When checked, minified files are not served for this page. Useful for debugging or if a specific page has conflicts.', 'site-optimizer-by-beplus-master' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the meta box value when a post is saved.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_box( $post_id, $post ) {
		// Skip autosaves, revisions, and posts without our nonce field.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['sob_meta_box_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['sob_meta_box_nonce'] ) ),
			'sob_meta_box_save'
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['sob_disable_cache'] ) ) {
			update_post_meta( $post_id, '_sob_disable_cache', 1 );
		} else {
			delete_post_meta( $post_id, '_sob_disable_cache' );
		}
	}
}


