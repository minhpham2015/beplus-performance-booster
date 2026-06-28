=== Beplus Performance Booster ===
Contributors: bearsthemes, minhphamit
Tags: performance, lazy load, cache, minify, optimization
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress optimizer with JS/CSS minification, lazy loading, asset cleanup, and browser cache headers. Zero dependencies.

== Description ==

Beplus Performance Booster is a no-bloat performance plugin that gives you fine-grained
control over your site's front-end assets. All features can be toggled independently
from a single settings page (Settings > Beplus Performance Booster).

All front-end optimisations are bypassed for logged-in administrators so you never
accidentally break the admin panel or your own editing experience.

**Feature summary:**

* JavaScript delay (Simple / Advanced mode) and defer with per-script exclude list
* JS Release Delay: image-load fallback timer for releasing delayed scripts
* CSS file and inline minification with cache
* Non-render-blocking stylesheet loading
* Lazy load images with IntersectionObserver fallback
* Remove emoji, wp-embed, Gutenberg CSS, WooCommerce assets on non-shop pages
* HTML minification and comment stripping
* Browser cache and gzip/brotli rules via .htaccess
* Per-page option to disable the cache for specific posts/pages

== Settings Reference ==

Navigate to **Settings > Beplus Performance Booster** to configure the plugin.

---

= ⚡ JavaScript Optimization =

**Delay JS Execution**
Default: off

Delays all non-excluded front-end scripts until the first user interaction (click,
scroll, keydown, or touchstart), or until the JS Release Delay timer fires —
whichever comes first.

*Delay Mode* — choose how scripts are intercepted:

* **Simple** — converts WordPress-enqueued external scripts to `text/plain`
  placeholders and replays them on first interaction. Low risk, no event-queue replay.
* **Advanced** — output-buffer approach that intercepts every `<script>` tag on the
  page (including hardcoded theme scripts and dynamically injected ones) via
  MutationObserver. Replays `DOMContentLoaded` and `window.load` event queues in
  correct order. Test thoroughly before enabling on production.

*JS Release Delay (ms)* — fallback timer (ms) that releases delayed scripts after
above-fold images and fonts finish loading, without requiring user interaction.
`0` = disabled — only user interaction releases delayed scripts.

**Defer Non-Critical JS**
Default: off

Adds the `defer` attribute to every non-excluded `<script>` tag so scripts are
fetched in parallel and executed after the HTML is parsed. jQuery and
jquery-migrate are always protected from deferral.

**Exclude JS Files**
Default: empty

One URL keyword per line. Any script whose `src` attribute contains a listed
keyword is excluded from both Delay and Defer. Use this to protect scripts that
must run immediately (e.g. analytics that need to capture the first page view).

Examples: `jquery`, `woocommerce`, `my-critical-script`

---

= 🎨 CSS Optimization =

**Minify Inline CSS**
Default: off

Strips block comments (`/* … */`), collapses redundant whitespace, and removes
trailing semicolons from every inline `<style>` block in `<head>`. License
comments (`/*! … */`) are preserved. Spaces around `+` are intentionally kept
so `calc(100% + 20px)` expressions are never broken.

**Minify CSS Files**
Default: off
Requires: wp-content/uploads/bepluspb-cache/ directory to be writable

Minifies every enqueued external CSS file and serves the cached version from
`wp-content/uploads/bepluspb-cache/`. Already-minified `*.min.css` files and
external CDN stylesheets are skipped automatically. Cache files are named using a
12-character content MD5 hash so they self-invalidate whenever the source changes.

**Non-Render-Blocking CSS**
Default: off

Converts `<link rel="stylesheet">` tags to the preload + onload swap pattern:

    <link rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" ...></noscript>

This prevents stylesheets from blocking the initial render. A `<noscript>` fallback
ensures CSS loads correctly even when JavaScript is disabled.

Tip: if you see a flash of unstyled content (FOUC), add your theme's main stylesheet
URL keyword to the Exclude CSS Files list.

**Exclude CSS Files**
Default: empty

One URL keyword per line. Stylesheets whose `href` contains a listed keyword are
left completely untouched by both the Non-Blocking and Minify CSS Files features.

---

= 🖼️ Lazy Load Images =

**Enable Lazy Loading**
Default: off

Adds `loading="lazy"` to qualifying `<img>` tags found in post content, featured
images, widget text, and `<picture>` elements. For browsers that do not support the
native `loading` attribute, a small IntersectionObserver polyfill is injected into
`wp_footer` (activates only when native support is absent).

**Skip First N Images**
Default: 1

Images 1 through N are marked `loading="eager"` instead of `loading="lazy"`.
Set this to at least 1 to protect the hero/LCP image from being lazy-loaded, which
would hurt Core Web Vitals. Accepts 0–20.

**Exclude by CSS Class**
Default: empty

Comma-separated CSS class names. Any `<img>` that has one of these classes loads
eagerly (not lazily).

Example: `hero-image, no-lazy, skip-lazy`

**Exclude by Element ID**
Default: empty

Comma-separated element IDs. Any `<img>` with a matching `id` attribute loads
eagerly.

Example: `hero-banner, site-logo`

**Exclude by Filename**
Default: empty

Comma-separated partial filename strings. Any `<img>` whose `src` URL contains one
of these strings loads eagerly.

Example: `logo, hero, banner`

---

= 🗑️ Remove Unused Assets =

**Remove Emoji Scripts**
Default: off

Removes the WordPress emoji detection script, its inline stylesheet, and the
`s.w.org` DNS prefetch hint. Safe to enable if you use real Unicode emoji in your
content — browsers display them natively without the WordPress helper.

**Remove oEmbed / wp-embed**
Default: off

Removes the `wp-embed.js` script and the oEmbed discovery links added to `<head>`.
Also unregisters the oEmbed REST API endpoint. Disable only if you rely on
embedding your WordPress posts inside other websites.

**Remove Block / Gutenberg CSS**
Default: off

Dequeues `wp-block-library`, `wp-block-library-theme`, and `global-styles`
stylesheets on the front-end. Disable if your theme or content depends on
Gutenberg block styles.

**Disable WooCommerce Assets on Non-Shop Pages**
Default: off
Requires: WooCommerce active

Dequeues WooCommerce scripts and styles on pages that are unrelated to the shop,
cart, checkout, or account. WooCommerce assets are always kept on those pages.

---

= 📄 HTML Optimization =

**Minify HTML Output**
Default: off

Collapses redundant whitespace between HTML tags in the full page output. Content
inside `<pre>`, `<textarea>`, `<script>`, and `<style>` tags is always preserved
exactly as-is.

**Remove HTML Comments**
Default: off

Strips HTML comments (e.g. theme generator tags, plugin banners) from the page
output. Conditional IE comments are also removed.

**Remove Inline JS Comments**
Default: off

Removes `//` single-line and `/* … */` block comments from inline `<script>`
blocks in the HTML output. String literals and template literals are never
modified. License comments (`/*! … */`) are preserved.

**Remove Inline CSS Comments**
Default: off

Removes `/* … */` block comments from inline `<style>` blocks in the HTML output.

---

= 🚀 Browser Cache Headers (.htaccess) =

**Enable Browser Caching**
Default: off
Requires: Apache with mod_expires, mod_headers, mod_deflate. .htaccess writable.

Injects the following directives into the root `.htaccess` file inside a clearly
labelled block that is never duplicated:

* **Gzip compression** (mod_deflate) for HTML, CSS, JS, JSON, SVG, and fonts.
* **Brotli compression** (mod_brotli, Apache 2.4.26+) for the same types.
* **Expires headers** (mod_expires): 1-year cache for static assets; no-cache
  for HTML and API responses.
* **Cache-Control headers** (mod_headers): `max-age=31536000, public, immutable`
  for CSS, JS, images, and fonts.

Disabling this option or deactivating the plugin removes the injected block cleanly.

---

= 📦 JS File Minification & Cache =

**Minify JS Files**
Default: off
Requires: wp-content/uploads/bepluspb-cache/ directory to be writable

Minifies every enqueued JavaScript file using a safe character-by-character comment
stripper that correctly handles string literals, template literals, and URL protocol
slashes (`https://`). Already-minified `*.min.js` files and external CDN scripts are
skipped. Cache files use a 12-character content MD5 hash for automatic cache busting.

Lines are never joined to avoid breaking JavaScript's Automatic Semicolon Insertion
(ASI) rules.

**Cache Status**

Shows how many CSS and JS files are currently stored in the cache directory
(`wp-content/uploads/bepluspb-cache/`). Use the "Clear CSS/JS Cache" button to delete
all cached files; they are regenerated on the next page load.

The "Beplus Performance Booster" item in the WordPress admin bar (visible to administrators on
both the front-end and back-end) shows cache size and file count in a dropdown panel
and provides a one-click button to clear the cache.

---

= Per-Page Cache Disable (Meta Box) =

Every post and page edit screen includes a "Beplus Performance Booster" meta box in the sidebar.
Checking **Disable CSS/JS cache optimizations for this page/post** stores the
`_bepluspb_disable_cache` flag in post meta. When set, the Minify CSS Files and Minify
JS Files features are bypassed for that specific URL and the original (unminified)
files are served instead. Useful for debugging or resolving conflicts on a specific
page without disabling minification site-wide.

== Installation ==

1. Upload the `beplus-performance-booster` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings > Beplus Performance Booster** to configure each feature.

== Frequently Asked Questions ==

= Will this plugin slow down my admin panel? =
No. Every optimisation hook is guarded by `is_admin()` and an additional check that
skips users with the `manage_options` capability on the front-end.

= I see a flash of unstyled content (FOUC) after enabling Non-Render-Blocking CSS. =
Add a URL keyword for your theme's main stylesheet to the **Exclude CSS Files**
textarea. That file will be loaded normally while the rest are deferred.

= The Browser Cache option says my .htaccess is not writable. =
Your server's file permissions do not allow PHP to write to `.htaccess`. Set the
file to 644 (or ask your host) and try again, or add the rules manually.

= Where are the minified CSS/JS cache files stored? =
In `wp-content/uploads/bepluspb-cache/` — inside your `wp-content` directory. The directory
is created automatically with an `index.php` stub (to prevent directory listing) and
a `.htaccess` stub (to block direct PHP execution).

= Is WooCommerce required? =
No. The WooCommerce asset-removal option is only applied when WooCommerce is active.
All other features work independently.

= Can I use this plugin alongside a caching plugin? =
Yes. The .htaccess browser-caching rules complement full-page caches (LiteSpeed
Cache, W3 Total Cache, WP Super Cache, etc.). The JS/CSS optimisations operate at
the PHP output level and work with most caching setups.

= What happens to my settings when I delete the plugin? =
The `uninstall.php` script removes all plugin data: the `bepluspb_settings` option, the
injected `.htaccess` rules, every file in `uploads/bepluspb-cache/`, and the
`_bepluspb_disable_cache` post meta from every post.

== Screenshots ==

1. **Dashboard tab** — Master cache toggle, one-click recommended-settings panel, and cache statistics.
2. **Cache Files tab** — CSS and JS file minification options with cache-exclusion controls.
3. **Status tab** — Live system status report covering PHP, WordPress, server environment, and plugin settings.
4. **Admin bar panel** — Cache size and file count, colour-coded status dot, one-click Clear Cache button.

== Changelog ==

= 1.0.1 =
* Added Delay Mode option (Simple / Advanced) for JS delay.
* Added JS Release Delay (ms): fallback timer that releases delayed scripts after above-fold images and fonts load; 0 = user interaction only.
* JS delay now applies to all users including logged-in administrators.
* Renamed "Image-Load Wait" setting to "JS Release Delay (ms)" for accuracy.

= 1.0.0 =
* Initial release.
* JavaScript delay (Simple / Advanced mode) and defer with per-script exclude list.
* JS Release Delay: fallback timer that releases delayed scripts after above-fold images and fonts load; `0` = user interaction only.
* CSS inline minification and non-render-blocking preload swap.
* CSS file minification with content-hash disk cache.
* JS file minification with safe comment stripper (respects string literals, ASI).
* Native lazy loading for `<img>` and `<picture>` elements.
* IntersectionObserver polyfill for lazy load in older browsers.
* Skip first N images (LCP protection), exclude by class / ID / filename.
* Remove emoji, wp-embed, Gutenberg block CSS, WooCommerce assets on non-shop pages.
* HTML minification and comment/JS-comment/CSS-comment stripping.
* Browser cache and gzip/brotli rules injected into .htaccess via insert_with_markers().
* Admin bar "Beplus Performance Booster" menu with cache size/file count panel and one-click clear.
* Per-page cache-disable meta box on all public post type edit screens.
* Uninstall script cleans up all options, rules, cache files, and post meta.

== Upgrade Notice ==

= 1.0.1 =
New JS delay options: Delay Mode (Simple/Advanced) and JS Release Delay (ms). No upgrade steps required.

= 1.0.0 =
Initial release — no upgrade steps required.
