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

	function formatTitle(raw) {
		var siteName = cfg.siteName || '';
		var sep = cfg.titleSeparator || '';
		if (!siteName || !raw) return raw;
		return raw + sep + siteName;
	}

	function refreshSerp() {
		var wrap = d.querySelector('.seoneo-serp-wrap');
		if (!wrap) return;

		var titleEl = wrap.querySelector('.seoneo-serp-title');
		var descEl  = wrap.querySelector('.seoneo-serp-description');
		if (!titleEl || !descEl) return;

		var titleField = wrap.getAttribute('data-title-field') || cfg.roleTitle || 'seoneo_title';
		var descField  = wrap.getAttribute('data-desc-field') || cfg.roleDescription || 'seoneo_description';

		var rawTitle = getActiveValue(titleField);
		var title;
		if (rawTitle) {
			title = formatTitle(rawTitle);
		} else {
			title = wrap.getAttribute('data-resolved-title') || '';
		}

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

	// ── Fallback chain UI ─────────────────────────────────────────────

	var SVG_CHAIN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	var SVG_ARROW_UP = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';

	// Currently open popover button (for close-on-outside-click)
	var _openPopover = null;

	function closeOpenPopover() {
		if (_openPopover) {
			_openPopover.setAttribute('aria-expanded', 'false');
			var pop = _openPopover._seoneoPopover;
			if (pop) pop.hidden = true;
			_openPopover = null;
		}
	}

	d.addEventListener('click', function(e) {
		if (_openPopover && !_openPopover.contains(e.target) && !(_openPopover._seoneoPopover && _openPopover._seoneoPopover.contains(e.target))) {
			closeOpenPopover();
		}
	});

	d.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') closeOpenPopover();
	});

	function buildPopoverHtml(chain) {
		var html = '<ol class="seoneo-chain-list" role="list">';
		for (var i = 0; i < chain.length; i++) {
			var step = chain[i];
			var classes = 'seoneo-chain-step';
			if (step.winner) classes += ' seoneo-chain-step--winner';
			if (!step.value) classes += ' seoneo-chain-step--empty';

			var previewText = step.value ? escHtml(truncate(step.value, 80)) : '<em>empty</em>';
			var promoteBtn = '';
			if (step.winner && step.type !== 'primary') {
				promoteBtn = '<button type="button" class="seoneo-chain-promote" title="Copy this value into the SEO field">' + SVG_ARROW_UP + '<span>Use this</span></button>';
			}

			html += '<li class="' + classes + '" data-field="' + escHtml(step.fieldName) + '" data-value="' + escHtml(step.value) + '">';
			html += '<span class="seoneo-chain-indicator"></span>';
			html += '<span class="seoneo-chain-label">' + escHtml(step.label) + '</span>';
			html += '<span class="seoneo-chain-preview">' + previewText + '</span>';
			if (promoteBtn) html += promoteBtn;
			html += '</li>';
		}
		html += '</ol>';
		return html;
	}

	function escHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function attachPromoteHandlers(popover, primaryInput, chain) {
		var btns = popover.querySelectorAll('.seoneo-chain-promote');
		btns.forEach(function(btn) {
			btn.addEventListener('click', function() {
				var li = btn.closest('.seoneo-chain-step');
				var val = li ? li.getAttribute('data-value') : '';
				if (!val || !primaryInput) return;
				primaryInput.value = val;
				// Fire input + change so counters and SERP preview update
				primaryInput.dispatchEvent(new Event('input', { bubbles: true }));
				primaryInput.dispatchEvent(new Event('change', { bubbles: true }));
				primaryInput.focus();
				closeOpenPopover();
			});
		});
	}

	function injectChainIcon(fieldName, chain) {
		if (!chain || !chain.length) return;

		var wrap = d.getElementById('wrap_Inputfield_' + fieldName);
		if (!wrap) return;

		// Don't double-inject
		if (wrap.querySelector('.seoneo-chain-btn')) return;

		var label = wrap.querySelector('label');
		if (!label) return;

		// ── Icon button ──────────────────────────────────
		var btn = d.createElement('button');
		btn.type = 'button';
		btn.className = 'seoneo-chain-btn';
		btn.setAttribute('aria-label', 'Show fallback chain');
		btn.setAttribute('aria-expanded', 'false');
		btn.innerHTML = SVG_CHAIN;

		// ── Popover ──────────────────────────────────────
		var popover = d.createElement('div');
		popover.className = 'seoneo-chain-popover';
		popover.setAttribute('role', 'dialog');
		popover.setAttribute('aria-label', 'Fallback chain for ' + fieldName);
		popover.hidden = true;
		popover.innerHTML = '<div class="seoneo-chain-header">Fallback chain</div>' + buildPopoverHtml(chain);

		btn._seoneoPopover = popover;

		btn.addEventListener('click', function(e) {
			e.stopPropagation();
			var isOpen = btn.getAttribute('aria-expanded') === 'true';
			closeOpenPopover();
			if (!isOpen) {
				btn.setAttribute('aria-expanded', 'true');
				popover.hidden = false;
				_openPopover = btn;
			}
		});

		// Wire up promote buttons
		var primaryInputs = findInputForFieldLanguage(fieldName);
		var primaryInput = primaryInputs[0] || null;
		attachPromoteHandlers(popover, primaryInput, chain);

		// Insert button after the label text, popover after the label element
		label.appendChild(btn);
		label.style.position = 'relative';
		label.appendChild(popover);

		// ── Ghost text (below the input) ─────────────────
		injectGhostText(wrap, fieldName, chain, primaryInput);
	}

	function getWinner(chain) {
		for (var i = 0; i < chain.length; i++) {
			if (chain[i].winner) return chain[i];
		}
		return null;
	}

	function injectGhostText(wrap, fieldName, chain, primaryInput) {
		// Ghost text shows only when primary field is empty
		var winner = getWinner(chain);
		if (!winner || winner.type === 'primary') return; // primary is filled or no winner

		var ghost = d.createElement('div');
		ghost.className = 'seoneo-chain-ghost';
		ghost.setAttribute('data-field', fieldName);

		function updateGhost() {
			var currentVal = primaryInput ? primaryInput.value.trim() : '';
			if (currentVal) {
				ghost.hidden = true;
				return;
			}
			ghost.hidden = false;
			ghost.textContent = 'Using: ' + winner.label;
		}

		if (primaryInput) {
			primaryInput.addEventListener('input', updateGhost);
			primaryInput.addEventListener('change', updateGhost);
		}

		// Insert after the last input/textarea in the wrap
		var inputs = wrap.querySelectorAll('input[type="text"], textarea');
		var lastInput = inputs[inputs.length - 1];
		if (lastInput && lastInput.parentNode) {
			lastInput.parentNode.insertBefore(ghost, lastInput.nextSibling);
		} else {
			wrap.appendChild(ghost);
		}

		updateGhost();
	}

	function injectChains() {
		var titleField = cfg.roleTitle || 'seoneo_title';
		var descField  = cfg.roleDescription || 'seoneo_description';

		if (cfg.titleChain && cfg.titleChain.length) {
			injectChainIcon(titleField, cfg.titleChain);
		}
		if (cfg.descChain && cfg.descChain.length) {
			injectChainIcon(descField, cfg.descChain);
		}
	}

	// ── Init ──────────────────────────────────────────────────────────

	SeoNeo.init = function() {
		injectCounters();
		setCanonicalPlaceholder();
		injectChains();
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
