/**
 * Beplus Performance Booster — Admin settings page: tab navigation + cache toggle AJAX.
 *
 * Extracted from the inline <script> block in class-bepluspb-admin.php so it can be
 * cached by the browser, linted, and tested independently.
 *
 * @package Beplus_Performance_Booster
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Tab navigation
	// -------------------------------------------------------------------------

	var btns    = document.querySelectorAll('.bepluspb-tab-btn');
	var panels  = document.querySelectorAll('.bepluspb-tab-panel');
	var saveBar = document.getElementById('bepluspb-save-bar');

	// Tabs that have their own forms and must NOT show the main Save bar.
	var noSaveBarTabs = { dashboard: true, status: true, ai_optimizer: true };

	/**
	 * Activate the tab with the given id, hide all others.
	 *
	 * @param {string} id Tab identifier (matches data-tab attribute).
	 */
	function activate(id) {
		btns.forEach(function (b) {
			var active = b.dataset.tab === id;
			b.classList.toggle('active', active);
			b.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		panels.forEach(function (p) {
			p.classList.toggle('active', p.id === 'bepluspb-tab-' + id);
		});

		// Hide Save Settings bar on tabs that manage their own state.
		if (saveBar) {
			saveBar.style.display = noSaveBarTabs[id] ? 'none' : '';
		}

		try {
			sessionStorage.setItem('bepluspb_active_tab', id);
		} catch (e) { /* storage may be unavailable (private browsing) */ }
	}

	// Bind click handlers.
	btns.forEach(function (btn) {
		btn.addEventListener('click', function () {
			activate(btn.dataset.tab);
		});
	});

	// Switch tabs when a Status-tab recommendation link is clicked (href="#bepluspb-tab-foo").
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a.bepluspb-rec-link');
		if (!a) { return; }
		var href = a.getAttribute('href') || '';
		var hashIdx = href.indexOf('#bepluspb-tab-');
		if (hashIdx === -1) { return; }
		var tabId = href.substring(hashIdx + '#bepluspb-tab-'.length);
		if (document.getElementById('bepluspb-tab-' + tabId)) {
			e.preventDefault();
			activate(tabId);
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}
	});

	// Restore the last-active tab from sessionStorage, defaulting to the
	// first tab (Dashboard) when nothing is stored or the stored value no
	// longer refers to an existing panel.
	var saved;
	try { saved = sessionStorage.getItem('bepluspb_active_tab'); } catch (e) {}

	if (saved && document.getElementById('bepluspb-tab-' + saved)) {
		activate(saved);
	} else if (btns.length) {
		activate(btns[0].dataset.tab);
	}

	// -------------------------------------------------------------------------
	// Delay JS sub-options visibility
	// -------------------------------------------------------------------------

	var jsDelayCheckbox  = document.getElementById('bepluspb_js_delay');
	var delayModeRow     = document.getElementById('bepluspb-delay-mode-row');
	var rdelayRow        = document.getElementById('bepluspb-rdelay-row');

	function toggleDelaySubRows() {
		var show = jsDelayCheckbox && jsDelayCheckbox.checked;
		if (delayModeRow) { delayModeRow.style.display = show ? '' : 'none'; }
		if (rdelayRow)    { rdelayRow.style.display    = show ? '' : 'none'; }
	}

	if (jsDelayCheckbox) {
		jsDelayCheckbox.addEventListener('change', toggleDelaySubRows);
	}

	// -------------------------------------------------------------------------
	// Master cache toggle — AJAX save
	// -------------------------------------------------------------------------

	var toggle  = document.getElementById('bepluspb-cache-enabled-toggle');
	var status  = document.getElementById('bepluspb-toggle-status');
	var notice  = document.getElementById('bepluspb-cache-disabled-notice');
	var clearBtn = document.getElementById('bepluspb-clear-cache-btn');

	if (toggle && typeof bepluspbAdmin !== 'undefined') {
		toggle.addEventListener('change', function () {
			var enabled = toggle.checked ? 1 : 0;

			// Optimistic UI update.
			updateToggleUI(enabled);

			// Send to server.
			var data = new FormData();
			data.append('action',  'bepluspb_toggle_cache');
			data.append('nonce',   bepluspbAdmin.toggleNonce);
			data.append('enabled', enabled);

			fetch(bepluspbAdmin.ajaxUrl, { method: 'POST', body: data })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (!json.success) {
						// Revert on failure.
						updateToggleUI(enabled ? 0 : 1);
						toggle.checked = !toggle.checked;
					}
				})
				.catch(function () {
					// Revert on network error.
					updateToggleUI(enabled ? 0 : 1);
					toggle.checked = !toggle.checked;
				});
		});
	}

	/**
	 * Update the toggle label, warning notice, and clear button to reflect state.
	 *
	 * Uses server-side translated strings from bepluspbAdmin (wp_localize_script)
	 * so the labels appear in the site's configured language.
	 *
	 * @param {number} enabled 1 = on, 0 = off.
	 */
	function updateToggleUI(enabled) {
		var i18n = (typeof bepluspbAdmin !== 'undefined') ? bepluspbAdmin : {};

		if (status) {
			if (enabled) {
				status.textContent = i18n.labelEnabled  || 'Enabled';
				status.className = 'bepluspb-toggle-status bepluspb-toggle-status--on';
			} else {
				status.textContent = i18n.labelDisabled || 'Disabled';
				status.className = 'bepluspb-toggle-status bepluspb-toggle-status--off';
			}
		}

		if (notice) {
			if (enabled) {
				notice.style.display = 'none';
				notice.innerHTML = '';
			} else {
				var msg = i18n.noticeDisabled || 'All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.';
				notice.style.display = '';
				while ( notice.firstChild ) { notice.removeChild( notice.firstChild ); }
				var p = document.createElement( 'p' );
				p.textContent = '⚠ ' + msg;
				notice.appendChild( p );
			}
		}

		if (clearBtn) {
			if (enabled) {
				clearBtn.removeAttribute('aria-disabled');
				clearBtn.removeAttribute('tabindex');
				clearBtn.classList.remove('bepluspb-clear-btn--empty');
			} else {
				clearBtn.setAttribute('aria-disabled', 'true');
				clearBtn.setAttribute('tabindex', '-1');
				clearBtn.classList.add('bepluspb-clear-btn--empty');
			}
		}
	}

})();
