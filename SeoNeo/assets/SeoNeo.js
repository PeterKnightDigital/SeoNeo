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

	var TITLE_TRUNCATE = 60;
	var DESC_TRUNCATE  = 160;

	function truncate(s, n) {
		s = s || '';
		if (s.length <= n) return s;
		return s.slice(0, n - 1).replace(/\s+\S*$/, '') + '\u2026';
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

	function refreshSerp() {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		if (!wrap) return;

		var titleEl = wrap.querySelector('.seoneo-serp-title');
		var descEl  = wrap.querySelector('.seoneo-serp-description');
		if (!titleEl || !descEl) return;

		var titleField = wrap.getAttribute('data-title-field') || cfg.roleTitle || 'seoneo_title';
		var descField  = wrap.getAttribute('data-desc-field') || cfg.roleDescription || 'seoneo_description';

		var title = getActiveValue(titleField);
		if (!title) title = wrap.getAttribute('data-resolved-title') || '';

		var desc = getActiveValue(descField);
		if (!desc) desc = wrap.getAttribute('data-resolved-desc') || '';

		titleEl.textContent = truncate(title, TITLE_TRUNCATE);
		descEl.textContent  = truncate(desc, DESC_TRUNCATE);
	}

	// ── Character counters ────────────────────────────────────────────

	function setCounter(el, len, green, amber) {
		var state = 'green';
		if (amber > 0 && len > amber) state = 'red';
		else if (green > 0 && len > green) state = 'amber';
		el.textContent = len + ' / ' + green + ' chars';
		el.setAttribute('data-state', state);
	}

	function injectCounter(input, green, amber) {
		if (input._seoneoCounter) return;
		var counter = d.createElement('div');
		counter.className = 'seoneo-counter';
		input.parentNode.insertBefore(counter, input.nextSibling);
		input._seoneoCounter = counter;

		function update() {
			var len = (input.value || '').length;
			setCounter(counter, len, green, amber);
			refreshSerp();
		}
		input.addEventListener('input', update);
		input.addEventListener('change', update);
		update();
	}

	function injectCounters() {
		var titleField = cfg.roleTitle || 'seoneo_title';
		var descField  = cfg.roleDescription || 'seoneo_description';

		var titleGreen = cfg.counterTitleGreen || 60;
		var titleAmber = cfg.counterTitleAmber || 70;
		var descGreen  = cfg.counterDescGreen || 160;
		var descAmber  = cfg.counterDescAmber || 180;

		findInputForFieldLanguage(titleField).forEach(function(input) {
			injectCounter(input, titleGreen, titleAmber);
		});

		findInputForFieldLanguage(descField).forEach(function(input) {
			injectCounter(input, descGreen, descAmber);
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

	// ── Init ──────────────────────────────────────────────────────────

	SeoNeo.init = function() {
		injectCounters();
		setCanonicalPlaceholder();
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
