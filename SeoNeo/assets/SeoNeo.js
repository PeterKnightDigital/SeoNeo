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
	var SVG_ARROW_DOWN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>';

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

	// Close on viewport changes — the popover is positioned from a viewport-rect
	// snapshot of the icon button; rather than re-position on every scroll tick
	// we just close (matches native <select> semantics, much cheaper).
	window.addEventListener('scroll', function() { closeOpenPopover(); }, true);
	window.addEventListener('resize', function() { closeOpenPopover(); });

	// Anchor a body-portalled popover to its trigger button using the button's
	// viewport rect. Clamps inside the viewport and flips above the button when
	// there isn't room below. Requires `popover.hidden = false` before calling
	// so we can measure offsetWidth / offsetHeight.
	function positionPopoverFor(btn, popover) {
		var rect = btn.getBoundingClientRect();
		var vw = window.innerWidth || d.documentElement.clientWidth;
		var vh = window.innerHeight || d.documentElement.clientHeight;
		var gap = 6;
		var margin = 8;

		var ph = popover.offsetHeight;
		var pw = popover.offsetWidth;

		var top = rect.bottom + gap;
		var left = rect.left;

		// Flip above the button if there isn't room below
		if (top + ph > vh - margin && (rect.top - ph - gap) > margin) {
			top = rect.top - ph - gap;
		}
		// Clamp horizontally inside viewport
		if (left + pw > vw - margin) left = Math.max(margin, vw - pw - margin);
		if (left < margin) left = margin;

		popover.style.top = top + 'px';
		popover.style.left = left + 'px';
	}

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
			html += '<span class="seoneo-chain-label">' + buildLabelHtml(step) + '</span>';
			html += '<span class="seoneo-chain-preview">' + previewText + '</span>';
			if (promoteBtn) html += promoteBtn;
			html += '</li>';

			if (i < chain.length - 1) {
				html += '<li class="seoneo-chain-sep" aria-hidden="true">' + SVG_ARROW_DOWN + '</li>';
			}
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

	function buildLabelHtml(step) {
		var labelText = step.labelText || step.label || step.fieldName || '';
		var displayName = step.displayName || '';
		var html = '<span class="seoneo-chain-label-text">' + escHtml(labelText) + '</span>';
		// Hide the field-name brackets when there is no real field (synthetic
		// steps like template_default) or when the label and field name match.
		if (displayName && displayName !== labelText && step.type !== 'template_default') {
			html += ' <span class="seoneo-chain-fieldname">(' + escHtml(displayName) + ')</span>';
		}
		if (step.inheritedSuffix) {
			html += ' <span class="seoneo-chain-inherited">' + escHtml(step.inheritedSuffix) + '</span>';
		}
		return html;
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

	// Extract the PW language suffix from an Inputfield input's `name`
	// attribute. PW renders multilang fields as `<name>` for the default
	// language and `<name>__<langId>` for non-default languages, e.g.
	// `seoneo_description` (default) and `seoneo_description__1011` (DE).
	// Returns the suffix WITH the leading double-underscore (or '' for
	// default) so callers can build matching selectors directly.
	function getLangSuffix(input) {
		if (!input || !input.name) return '';
		var m = input.name.match(/(__\d+)$/);
		return m ? m[1] : '';
	}

	function getLangIdForInput(input) {
		if (!input || !input.name) return cfg.defaultLanguageId || 0;
		var m = input.name.match(/__(\d+)$/);
		return m ? parseInt(m[1], 10) : (cfg.defaultLanguageId || 0);
	}

	// Read a chain step's value at a given language context. Prefers the
	// live DOM input for the step's source field (so the popover reflects
	// what the editor has just typed but not yet saved) and falls back to
	// the server-pre-computed valuesByLang map for steps that aren't in
	// the editor DOM (template defaults, ancestor-walked smart-map values).
	function readStepValueForLanguage(step, langSuffix, langId) {
		var live = '';
		if (step.type === 'primary' || step.type === 'page_title' ||
			(step.type === 'smart_map' && !step.fromAncestor)) {
			var raw = step.fieldName || '';
			// Strip the leading `*` ancestor-walk marker from smart-map names
			var cleanName = raw.replace(/^\*/, '');
			var input = d.querySelector('[name="' + cleanName + langSuffix + '"]');
			if (input) live = (input.value || '').trim();
		}
		if (live !== '') return live;
		if (step.valuesByLang && step.valuesByLang[langId] !== undefined) {
			return step.valuesByLang[langId];
		}
		return step.value || '';
	}

	// Build a per-language live snapshot of the chain: same shape as the
	// server-sent chain, with `value` replaced by this language's value and
	// `winner` recomputed (first non-empty step wins).
	function buildLiveChain(chain, langSuffix, langId) {
		var live = chain.map(function(step) {
			var s = Object.assign({}, step);
			s.value = readStepValueForLanguage(step, langSuffix, langId);
			s.winner = false;
			return s;
		});
		for (var i = 0; i < live.length; i++) {
			if (live[i].value !== '') { live[i].winner = true; break; }
		}
		return live;
	}

	function injectChainForField(fieldName, chain) {
		if (!chain || !chain.length) return;

		var wrap = d.getElementById('wrap_Inputfield_' + fieldName);
		if (!wrap) return;

		// Don't double-inject — guard on the row, not the button, because the
		// label no longer carries the chain icon.
		if (wrap.querySelector('.seoneo-chain-row')) return;

		var primaryInputs = findInputForFieldLanguage(fieldName);
		if (!primaryInputs.length) return;

		primaryInputs.forEach(function(primaryInput) {
			injectChainRow(wrap, fieldName, chain, primaryInput);
		});
	}

	// Inject one "Using: … (chain icon)" row directly after a primary input.
	// Each language tab has its own primary input, so each language gets its
	// own row, its own icon, and its own popover scoped to that language's
	// values. The smart-map shape is global (same fields in same order); only
	// the values differ. See LNT-006 in lakesandtrails.go/SEO-NEO-FINDINGS.md.
	function injectChainRow(wrap, fieldName, chain, primaryInput) {
		var langSuffix = getLangSuffix(primaryInput);
		var langId = getLangIdForInput(primaryInput);

		var row = d.createElement('div');
		row.className = 'seoneo-chain-row';
		row.setAttribute('data-field', fieldName);
		row.setAttribute('data-lang', langId);

		var ghost = d.createElement('span');
		ghost.className = 'seoneo-chain-ghost';
		ghost.hidden = true;

		var btn = d.createElement('button');
		btn.type = 'button';
		btn.className = 'seoneo-chain-btn';
		btn.setAttribute('aria-label', 'Show fallback chain');
		btn.setAttribute('aria-expanded', 'false');
		btn.innerHTML = SVG_CHAIN;

		var popover = d.createElement('div');
		popover.className = 'seoneo-chain-popover';
		popover.setAttribute('role', 'dialog');
		popover.setAttribute('aria-label', 'Fallback chain for ' + fieldName);
		popover.setAttribute('data-fieldname', fieldName);
		popover.setAttribute('data-lang', langId);
		popover.hidden = true;
		btn._seoneoPopover = popover;

		// Update the "Using: …" ghost text based on the live winner. The icon
		// itself stays visible at all times (so editors can always inspect
		// the chain); only the explanatory ghost text hides when the primary
		// field is filled, because there's no fallback to explain.
		function updateGhost() {
			var live = buildLiveChain(chain, langSuffix, langId);
			var winner = null;
			for (var i = 0; i < live.length; i++) { if (live[i].winner) { winner = live[i]; break; } }
			if (winner && winner.type !== 'primary') {
				ghost.hidden = false;
				ghost.textContent = 'Using: ' + winner.label;
			} else {
				ghost.hidden = true;
			}
		}

		btn.addEventListener('click', function(e) {
			e.stopPropagation();
			var isOpen = btn.getAttribute('aria-expanded') === 'true';
			closeOpenPopover();
			if (!isOpen) {
				var live = buildLiveChain(chain, langSuffix, langId);
				popover.innerHTML = '<div class="seoneo-chain-header">Fallback chain</div>' + buildPopoverHtml(live);
				attachPromoteHandlers(popover, primaryInput, live);
				btn.setAttribute('aria-expanded', 'true');
				popover.hidden = false;
				positionPopoverFor(btn, popover);
				_openPopover = btn;
			}
		});

		// Wire ghost updates: most importantly the primary input itself, plus
		// the smart-map source inputs in this language (so changing Summary
		// while editing the German tab refreshes the "Using: …" hint).
		if (primaryInput) {
			primaryInput.addEventListener('input', updateGhost);
			primaryInput.addEventListener('change', updateGhost);
		}
		chain.forEach(function(step) {
			if (step.type === 'smart_map' && !step.fromAncestor) {
				var cleanName = (step.fieldName || '').replace(/^\*/, '');
				var src = d.querySelector('[name="' + cleanName + langSuffix + '"]');
				if (src && src !== primaryInput) {
					src.addEventListener('input', updateGhost);
					src.addEventListener('change', updateGhost);
				}
			}
		});

		row.appendChild(ghost);
		row.appendChild(btn);

		// Insert the row as the next sibling of the primary input. For
		// multilang fields that means the row lives inside the same langTab_*
		// container as the input it describes, so showing / hiding tabs
		// shows / hides the matching row for free.
		if (primaryInput.parentNode) {
			primaryInput.parentNode.insertBefore(row, primaryInput.nextSibling);
		} else {
			wrap.appendChild(row);
		}

		// Clean up any stale popovers for this field + language (defensive:
		// AJAX-reloaded inputfields would otherwise leave orphans in <body>).
		var staleSel = '.seoneo-chain-popover[data-fieldname="' + fieldName + '"][data-lang="' + langId + '"]';
		var stale = d.body.querySelectorAll(staleSel);
		for (var s = 0; s < stale.length; s++) stale[s].parentNode.removeChild(stale[s]);
		d.body.appendChild(popover);

		updateGhost();
	}

	function injectChains() {
		var titleField = cfg.roleTitle || 'seoneo_title';
		var descField  = cfg.roleDescription || 'seoneo_description';

		if (cfg.titleChain && cfg.titleChain.length) {
			injectChainForField(titleField, cfg.titleChain);
		}
		if (cfg.descChain && cfg.descChain.length) {
			injectChainForField(descField, cfg.descChain);
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
