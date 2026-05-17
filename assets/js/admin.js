/**
 * BePlus Optimizer — Admin settings page: tab navigation + cache toggle AJAX.
 *
 * Extracted from the inline <script> block in class-sob-admin.php (CQ-3)
 * so it can be cached by the browser, linted, and tested independently.
 *
 * @package Site_Optimizer_BePlus
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Tab navigation
	// -------------------------------------------------------------------------

	var btns    = document.querySelectorAll('.sob-tab-btn');
	var panels  = document.querySelectorAll('.sob-tab-panel');
	var saveBar = document.getElementById('sob-save-bar');

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
			p.classList.toggle('active', p.id === 'sob-tab-' + id);
		});

		// Hide Save Settings bar on tabs that manage their own state.
		if (saveBar) {
			saveBar.style.display = noSaveBarTabs[id] ? 'none' : '';
		}

		try {
			sessionStorage.setItem('sob_active_tab', id);
		} catch (e) { /* storage may be unavailable (private browsing) */ }
	}

	// Bind click handlers.
	btns.forEach(function (btn) {
		btn.addEventListener('click', function () {
			activate(btn.dataset.tab);
		});
	});

	// Switch tabs when a Status-tab recommendation link is clicked (href="#sob-tab-foo").
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a.sob-rec-link');
		if (!a) { return; }
		var href = a.getAttribute('href') || '';
		var hashIdx = href.indexOf('#sob-tab-');
		if (hashIdx === -1) { return; }
		var tabId = href.substring(hashIdx + '#sob-tab-'.length);
		if (document.getElementById('sob-tab-' + tabId)) {
			e.preventDefault();
			activate(tabId);
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}
	});

	// Restore the last-active tab from sessionStorage, defaulting to the
	// first tab (Dashboard) when nothing is stored or the stored value no
	// longer refers to an existing panel.
	var saved;
	try { saved = sessionStorage.getItem('sob_active_tab'); } catch (e) {}

	if (saved && document.getElementById('sob-tab-' + saved)) {
		activate(saved);
	} else if (btns.length) {
		activate(btns[0].dataset.tab);
	}

	// -------------------------------------------------------------------------
	// Master cache toggle — AJAX save
	// -------------------------------------------------------------------------

	var toggle  = document.getElementById('sob-cache-enabled-toggle');
	var status  = document.getElementById('sob-toggle-status');
	var notice  = document.getElementById('sob-cache-disabled-notice');
	var clearBtn = document.getElementById('sob-clear-cache-btn');

	if (toggle && typeof sobAdmin !== 'undefined') {
		toggle.addEventListener('change', function () {
			var enabled = toggle.checked ? 1 : 0;

			// Optimistic UI update.
			updateToggleUI(enabled);

			// Send to server.
			var data = new FormData();
			data.append('action',  'sob_toggle_cache');
			data.append('nonce',   sobAdmin.toggleNonce);
			data.append('enabled', enabled);

			fetch(sobAdmin.ajaxUrl, { method: 'POST', body: data })
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
	 * @param {number} enabled 1 = on, 0 = off.
	 */
	function updateToggleUI(enabled) {
		if (status) {
			if (enabled) {
				status.textContent = 'Enabled';
				status.className = 'sob-toggle-status sob-toggle-status--on';
			} else {
				status.textContent = 'Disabled';
				status.className = 'sob-toggle-status sob-toggle-status--off';
			}
		}

		if (notice) {
			if (enabled) {
				notice.style.display = 'none';
				notice.innerHTML = '';
			} else {
				notice.style.display = '';
				notice.innerHTML = '<p>&#9888; All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.</p>';
			}
		}

		if (clearBtn) {
			if (enabled) {
				clearBtn.removeAttribute('aria-disabled');
				clearBtn.removeAttribute('tabindex');
				clearBtn.classList.remove('sob-clear-btn--empty');
			} else {
				clearBtn.setAttribute('aria-disabled', 'true');
				clearBtn.setAttribute('tabindex', '-1');
				clearBtn.classList.add('sob-clear-btn--empty');
			}
		}
	}

})();
