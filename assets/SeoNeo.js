/**
 * SeoNeo — SERP preview + SEO character counter injection.
 *
 * Reads configuration from ProcessWire.config.SeoNeo (set by the module).
 * Watches the mapped title and description Inputfields on the page editor
 * and updates the SERP preview live. Injects advisory character counters
 * with green/amber/red zones.
 */
(function(w, d) {
	'use strict';

	var SeoNeo = w.SeoNeo = w.SeoNeo || {};
	var cfg = (w.ProcessWire && w.ProcessWire.config && w.ProcessWire.config.SeoNeo) || {};

	// Truncation budgets per surface. Mobile values are roughly Google's
	// mobile SERP cut-off; they're set via cfg so editors can tune them
	// in module config without touching JS.
	var TRUNCATE = {
		desktop: { title: 60,  desc: 160 },
		mobile:  { title: cfg.counterTitleMobileGreen || 50,
		           desc:  cfg.counterDescMobileGreen  || 120 }
	};

	function truncate(s, n) {
		s = s || '';
		if (s.length <= n) return s;
		return s.slice(0, n - 1).replace(/\s+\S*$/, '') + '\u2026';
	}

	function getCurrentSurface() {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		return (wrap && wrap.getAttribute('data-surface')) || 'desktop';
	}

	function findInputForField(fieldName) {
		var wrap = d.getElementById('wrap_Inputfield_' + fieldName);
		if (!wrap) return null;
		return wrap.querySelector('input, textarea');
	}

	function findInputForFieldLanguage(fieldName) {
		var inputs = [];
		var wrap = d.getElementById('wrap_Inputfield_' + fieldName);
		if (!wrap) {
			var input = d.querySelector('[name="' + fieldName + '"]');
			if (input) return [input];
			return [];
		}
		var els = wrap.querySelectorAll('input[type="text"], textarea');
		for (var i = 0; i < els.length; i++) inputs.push(els[i]);
		return inputs;
	}

	function getActiveValue(fieldName) {
		var inputs = findInputForFieldLanguage(fieldName);
		if (!inputs.length) return '';
		for (var i = 0; i < inputs.length; i++) {
			var tab = inputs[i].closest('.langTab, .LanguageSupport');
			if (tab && tab.style.display === 'none') continue;
			if (inputs[i].value && inputs[i].value.trim() !== '') return inputs[i].value;
		}
		return inputs[0] ? inputs[0].value : '';
	}

	// ── SERP preview ──────────────────────────────────────────────────

	function formatTitle(raw) {
		var siteName = cfg.siteName || '';
		var sep = cfg.titleSeparator || '';
		if (!siteName || !raw) return raw;
		return raw + sep + siteName;
	}

	// Find the per-language input value for a given fieldname + language id.
	// PW multilang fields are rendered as `<name>` for the default language
	// and `<name>__<langId>` for other languages. We look up the input by
	// name attribute so the language switcher reads what the editor has
	// typed even when the corresponding language tab isn't currently visible.
	function getValueForLang(fieldName, langId) {
		if (!fieldName) return '';
		var defaultLangId = cfg.defaultLanguageId || 0;
		var name = (langId && langId !== defaultLangId) ? fieldName + '__' + langId : fieldName;
		var input = d.querySelector('[name="' + name + '"]');
		return input ? (input.value || '') : '';
	}

	function getSelectedLangPayload(wrap) {
		var raw = wrap.getAttribute('data-langs');
		if (!raw) return null;
		var langs;
		try { langs = JSON.parse(raw); } catch (e) { return null; }
		if (!Array.isArray(langs) || !langs.length) return null;
		// Active language = the lang-toggle button with aria-selected=true.
		// Falls back to data-current-lang on the wrap, then to the first payload.
		var activeBtn = wrap.querySelector('.seoneo-serp-lang-btn[aria-selected="true"]');
		var selectedId = activeBtn
			? parseInt(activeBtn.getAttribute('data-lang-id'), 10)
			: parseInt(wrap.getAttribute('data-current-lang'), 10);
		for (var i = 0; i < langs.length; i++) {
			if (langs[i].id === selectedId) return langs[i];
		}
		return langs[0];
	}

	function refreshSerp() {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		if (!wrap) return;

		var titleEl = wrap.querySelector('.seoneo-serp-title');
		var descEl  = wrap.querySelector('.seoneo-serp-description');
		var siteNameEl = wrap.querySelector('.seoneo-serp-site-name');
		var breadcrumbEl = wrap.querySelector('.seoneo-serp-breadcrumb');
		var faviconImg = wrap.querySelector('.seoneo-serp-favicon img');
		if (!titleEl || !descEl) return;

		var surface = getCurrentSurface();
		var truncBudget = TRUNCATE[surface] || TRUNCATE.desktop;

		var titleField = wrap.getAttribute('data-title-field') || cfg.roleTitle || 'seoneo_title';
		var descField  = wrap.getAttribute('data-desc-field') || cfg.roleDescription || 'seoneo_description';

		// Pick the active language payload (multilingual sites) or fall back
		// to the wrap's default-language attributes (single-language sites).
		var lp = getSelectedLangPayload(wrap);
		var langId = lp ? lp.id : (cfg.defaultLanguageId || 0);
		var fallbackTitle = lp ? lp.title : (wrap.getAttribute('data-resolved-title') || '');
		var fallbackDesc  = lp ? lp.desc  : (wrap.getAttribute('data-resolved-desc') || '');
		var displayName   = lp ? lp.siteName : (wrap.getAttribute('data-site-name') || '');
		var breadcrumbFull = lp ? lp.url : (wrap.getAttribute('data-page-url') || '');
		var hostOnly      = lp ? lp.host : (wrap.getAttribute('data-host') || '');
		var faviconUrl    = lp ? lp.favicon : (wrap.getAttribute('data-favicon') || '');

		// Title: editor's typed value for the selected language wins; else
		// server-resolved fallback from the per-language payload.
		var rawTitle = lp ? getValueForLang(titleField, langId) : getActiveValue(titleField);
		var title = rawTitle ? formatTitle(rawTitle) : fallbackTitle;

		var desc = lp ? getValueForLang(descField, langId) : getActiveValue(descField);
		if (!desc) desc = fallbackDesc;

		titleEl.textContent = truncate(title, truncBudget.title);
		descEl.textContent  = truncate(desc, truncBudget.desc);

		if (siteNameEl) siteNameEl.textContent = displayName;
		if (breadcrumbEl) {
			// Mobile SERP collapses the URL to host-only; desktop shows the full URL.
			breadcrumbEl.textContent = (surface === 'mobile' && hostOnly) ? hostOnly : breadcrumbFull;
		}
		if (faviconImg && faviconUrl && faviconImg.src !== faviconUrl) {
			faviconImg.src = faviconUrl;
			faviconImg.style.display = '';
		}
	}

	// ── Character counters ────────────────────────────────────────────

	function setCounter(el, len, green, amber) {
		var state = 'green';
		if (amber > 0 && len > amber) state = 'red';
		else if (green > 0 && len > green) state = 'amber';
		el.textContent = len + ' / ' + green + ' chars';
		el.setAttribute('data-state', state);
	}

	// Per-surface counter budgets for the current page. Title and desc each
	// have green / amber thresholds for desktop and mobile separately, so
	// switching the SERP preview surface re-renders the counters with the
	// new budgets without re-attaching any listeners.
	function getCounterBudgets(kind) {
		var surface = getCurrentSurface();
		if (kind === 'title') {
			if (surface === 'mobile') {
				return {
					green: cfg.counterTitleMobileGreen || 50,
					amber: cfg.counterTitleMobileAmber || 60
				};
			}
			return {
				green: cfg.counterTitleGreen || 60,
				amber: cfg.counterTitleAmber || 70
			};
		}
		// desc
		if (surface === 'mobile') {
			return {
				green: cfg.counterDescMobileGreen || 120,
				amber: cfg.counterDescMobileAmber || 140
			};
		}
		return {
			green: cfg.counterDescGreen || 160,
			amber: cfg.counterDescAmber || 180
		};
	}

	function injectCounter(input, kind) {
		if (input._seoneoCounter) return;
		var counter = d.createElement('div');
		counter.className = 'seoneo-counter';
		input.parentNode.insertBefore(counter, input.nextSibling);
		input._seoneoCounter = counter;
		input._seoneoCounterKind = kind;

		function update() {
			var b = getCounterBudgets(kind);
			var len = (input.value || '').length;
			setCounter(counter, len, b.green, b.amber);
			refreshSerp();
		}
		input.addEventListener('input', update);
		input.addEventListener('change', update);
		input._seoneoCounterUpdate = update;
		update();
	}

	// Called after a surface change — re-runs each counter's update so the
	// "X / N chars" label and green/amber/red state reflect the new budget.
	function refreshAllCounters() {
		var inputs = d.querySelectorAll('.seoneo-counter');
		inputs.forEach(function(counterEl) {
			// Find the input we previously injected from
			var sibling = counterEl.previousElementSibling;
			while (sibling && !sibling._seoneoCounter) sibling = sibling.previousElementSibling;
			if (sibling && typeof sibling._seoneoCounterUpdate === 'function') {
				sibling._seoneoCounterUpdate();
			}
		});
	}

	function injectCounters() {
		var titleField = cfg.roleTitle || 'seoneo_title';
		var descField  = cfg.roleDescription || 'seoneo_description';

		findInputForFieldLanguage(titleField).forEach(function(input) {
			injectCounter(input, 'title');
		});

		findInputForFieldLanguage(descField).forEach(function(input) {
			injectCounter(input, 'desc');
		});
	}

	// ── Canonical placeholder ─────────────────────────────────────────

	function setCanonicalPlaceholder() {
		var canonField = cfg.roleCanonical || 'seoneo_canonical';
		var pageUrl = cfg.pageUrl || '';
		if (!pageUrl) return;
		var inputs = findInputForFieldLanguage(canonField);
		inputs.forEach(function(input) {
			if (!input.getAttribute('placeholder')) {
				input.setAttribute('placeholder', pageUrl);
			}
		});
	}

	// ── SERP preview controls (L9) ────────────────────────────────────

	function setSurface(surface) {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		if (!wrap) return;
		surface = (surface === 'mobile') ? 'mobile' : 'desktop';
		wrap.setAttribute('data-surface', surface);
		var btns = wrap.querySelectorAll('.seoneo-serp-surface-btn');
		btns.forEach(function(btn) {
			btn.setAttribute('aria-selected', btn.getAttribute('data-surface') === surface ? 'true' : 'false');
		});
		refreshSerp();
		refreshAllCounters();
	}

	function wirePreviewControls() {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		if (!wrap || wrap._seoneoControlsWired) return;
		wrap._seoneoControlsWired = true;

		// Surface buttons — click + keyboard nav (arrow keys).
		var btns = wrap.querySelectorAll('.seoneo-serp-surface-btn');
		btns.forEach(function(btn) {
			btn.addEventListener('click', function() {
				setSurface(btn.getAttribute('data-surface'));
			});
			btn.addEventListener('keydown', function(e) {
				if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
					e.preventDefault();
					var arr = Array.prototype.slice.call(btns);
					var i = arr.indexOf(btn);
					var next = e.key === 'ArrowRight' ? (i + 1) % arr.length : (i - 1 + arr.length) % arr.length;
					arr[next].focus();
					setSurface(arr[next].getAttribute('data-surface'));
				}
			});
		});

		// Language switcher buttons — click + keyboard arrow-key cycling.
		// One button per active language; mirroring the surface toggle's
		// keyboard pattern keeps the two control groups feeling identical.
		var langBtns = wrap.querySelectorAll('.seoneo-serp-lang-btn');
		function setLang(btn) {
			langBtns.forEach(function(b) {
				b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
			});
			wrap.setAttribute('data-current-lang', btn.getAttribute('data-lang-id'));
			refreshSerp();
		}
		langBtns.forEach(function(btn) {
			btn.addEventListener('click', function() { setLang(btn); });
			btn.addEventListener('keydown', function(e) {
				if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
					e.preventDefault();
					var arr = Array.prototype.slice.call(langBtns);
					var i = arr.indexOf(btn);
					var next = e.key === 'ArrowRight' ? (i + 1) % arr.length : (i - 1 + arr.length) % arr.length;
					arr[next].focus();
					setLang(arr[next]);
				}
			});
		});

		// When the editor types into any title/desc input — including hidden
		// language tabs — the live preview should refresh if that input's
		// language matches the switcher. The counters are already wired per
		// input by injectCounter(); here we add a lightweight refresh-only
		// listener so cross-tab typing reflects in the preview immediately.
		var titleField = cfg.roleTitle || 'seoneo_title';
		var descField  = cfg.roleDescription || 'seoneo_description';
		[titleField, descField].forEach(function(name) {
			var inputs = d.querySelectorAll('[name="' + name + '"], [name^="' + name + '__"]');
			inputs.forEach(function(input) {
				if (input._seoneoPreviewWired) return;
				input._seoneoPreviewWired = true;
				input.addEventListener('input', refreshSerp);
				input.addEventListener('change', refreshSerp);
			});
		});
	}

	// ── Wire tab badge (page editor) ──────────────────────────────────

	function injectTabBadge() {
		if (!cfg.showTabBadge) return;
		var tabLi = d.querySelector('ul.WireTabs li[id*="_seoneo_tab"]');
		if (!tabLi) return;
		var tabLink = tabLi.querySelector('a');
		if (!tabLink) return;
		tabLink.setAttribute('data-seoneo-tab', '1');
		if (tabLink.querySelector('.seoneo-tab-badge')) return;
		var badge = d.createElement('span');
		badge.className = 'seoneo-tab-badge';
		badge.textContent = 'NEO';
		tabLink.appendChild(badge);
	}

	// ── Init ──────────────────────────────────────────────────────────

	SeoNeo.init = function() {
		injectCounters();
		setCanonicalPlaceholder();
		wirePreviewControls();
		injectTabBadge();
		refreshSerp();
	};

	if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', function() { SeoNeo.init(); });
	} else {
		SeoNeo.init();
	}

	if (w.jQuery) {
		w.jQuery(d).on('reloaded', '.Inputfield, .InputfieldContent', function() {
			SeoNeo.init();
		});
	}

})(window, document);
