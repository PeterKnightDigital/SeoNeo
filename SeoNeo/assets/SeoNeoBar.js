/**
 * SeoNeo Bar — Frontend admin bar controller.
 *
 * Manages the bar button states, drawer open/close animation,
 * lazy data fetching from the SeoNeo AJAX endpoint, and rendering
 * of all drawer panels (SEO & Meta, Headings, Open Graph).
 *
 * No dependencies. Vanilla JS, ES5-compatible syntax avoided in favour
 * of clean modern ES2017 since ProcessWire 3.x targets modern browsers.
 */

(function () {
  'use strict';

  const cfg = window.SeoNeoBarConfig || {};
  const PAGE_ID = cfg.pageId || 0;
  const DATA_URL = cfg.dataUrl || '/seoneo-bar-data/';

  // Cached fetch result — only one network request per page load
  let _dataCache = null;
  let _dataPromise = null;

  // Currently active panel id
  let _activePanel = null;

  // ─────────────────────────────────────────────────────────────────────
  //  DOM refs (resolved once on init)
  // ─────────────────────────────────────────────────────────────────────

  let bar, drawer, drawerBody, drawerTitle, drawerClose;

  // ─────────────────────────────────────────────────────────────────────
  //  Data fetching
  // ─────────────────────────────────────────────────────────────────────

  function fetchData() {
    if (_dataCache) return Promise.resolve(_dataCache);
    if (_dataPromise) return _dataPromise;

    _dataPromise = fetch(DATA_URL + '?id=' + PAGE_ID, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        _dataCache = data;
        _dataPromise = null;
        return data;
      })
      .catch(function (err) {
        _dataPromise = null;
        throw err;
      });

    return _dataPromise;
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Drawer open / close
  // ─────────────────────────────────────────────────────────────────────

  function openDrawer(panelId) {
    if (!drawer) return;

    _activePanel = panelId;

    // Update bar buttons
    bar.querySelectorAll('.pkd-seoneo-bar__btn').forEach(function (btn) {
      const active = btn.dataset.panel === panelId;
      btn.classList.toggle('pkd-seoneo-bar__btn--active', active);
      btn.setAttribute('aria-expanded', active ? 'true' : 'false');
    });

    // Show panel title
    const panelTitles = { seo: 'SEO & Meta', headings: 'Headings', og: 'Open Graph' };
    drawerTitle.textContent = panelTitles[panelId] || 'SeoNeo';

    // Open
    drawer.classList.add('pkd-seoneo-drawer--open');
    drawer.setAttribute('aria-hidden', 'false');

    // Focus management
    drawerClose.focus();

    // Load content
    renderPanel(panelId);
  }

  function closeDrawer() {
    if (!drawer) return;

    _activePanel = null;

    drawer.classList.remove('pkd-seoneo-drawer--open');
    drawer.setAttribute('aria-hidden', 'true');

    bar.querySelectorAll('.pkd-seoneo-bar__btn').forEach(function (btn) {
      btn.classList.remove('pkd-seoneo-bar__btn--active');
      btn.setAttribute('aria-expanded', 'false');
    });
  }

  function toggleDrawer(panelId) {
    if (_activePanel === panelId && drawer.classList.contains('pkd-seoneo-drawer--open')) {
      closeDrawer();
    } else {
      openDrawer(panelId);
    }
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel rendering (dispatches to specific renderers)
  // ─────────────────────────────────────────────────────────────────────

  function renderPanel(panelId) {
    showLoading();

    fetchData()
      .then(function (data) {
        switch (panelId) {
          case 'seo':      drawerBody.innerHTML = renderSeoPanel(data);      break;
          case 'headings': drawerBody.innerHTML = renderHeadingsPanel(data); break;
          case 'og':       drawerBody.innerHTML = renderOgPanel(data);       break;
          default:         drawerBody.innerHTML = '';
        }
        drawerBody.scrollTop = 0;
      })
      .catch(function (err) {
        drawerBody.innerHTML = '<div class="pkd-seoneo-drawer__error">Could not load SEO data. ' + esc(err.message) + '</div>';
      });
  }

  function showLoading() {
    drawerBody.innerHTML = '<div class="pkd-seoneo-drawer__loading"><div class="pkd-seoneo-spinner" role="status" aria-label="Loading\u2026"></div><span>Loading\u2026</span></div>';
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel: SEO & Meta
  // ─────────────────────────────────────────────────────────────────────

  function renderSeoPanel(data) {
    const t = data.thresholds || {};
    let html = '';

    // ── SERP Preview section ──────────────────────────────────────────
    html += section('SERP Preview', renderSerp(data));

    // ── Meta fields section ───────────────────────────────────────────
    let metaFields = '';

    // Title
    metaFields += renderField({
      label: 'Title',
      value: data.title.value,
      length: data.title.length,
      source: data.title.source,
      isDirect: data.title.raw !== '',
      greenAt: t.titleGreen || 60,
      amberAt: t.titleAmber || 70,
      showCounter: true,
    });

    // Description
    metaFields += renderField({
      label: 'Description',
      value: data.description.value,
      length: data.description.length,
      source: data.description.source,
      isDirect: data.description.raw !== '',
      greenAt: t.descGreen || 160,
      amberAt: t.descAmber || 180,
      showCounter: true,
    });

    // Canonical
    metaFields += renderUrlField('Canonical', data.canonical.value, data.canonical.isDefault ? 'auto (page URL)' : 'explicit');

    html += section('Meta', metaFields);

    // ── Robots section ────────────────────────────────────────────────
    html += section('Robots', renderRobots(data.robots));

    // ── Keywords ─────────────────────────────────────────────────────
    if (data.keywords) {
      const kws = data.keywords.split(',').map(function (k) { return k.trim(); }).filter(Boolean);
      let kwHtml = '<ul class="pkd-seoneo-tag-list">';
      kws.forEach(function (k) { kwHtml += '<li class="pkd-seoneo-tag-list__item">' + esc(k) + '</li>'; });
      kwHtml += '</ul>';
      html += section('Keywords', kwHtml);
    }

    // ── Hreflang ──────────────────────────────────────────────────────
    if (data.hreflang && data.hreflang.length > 1) {
      html += section('Hreflang', renderHreflang(data.hreflang));
    }

    return html;
  }

  function renderSerp(data) {
    const title = data.title.value || '';
    const desc  = data.description.value || '';
    const url   = data.canonical.value || '';
    const siteName = (data.og && data.og.siteName) ? data.og.siteName : '';

    const faviconUrl = url ? (new URL(url)).origin + '/favicon.ico' : '';

    const titleHtml = title
      ? '<div class="pkd-seoneo-serp__title">' + esc(truncate(title, 60)) + '</div>'
      : '<div class="pkd-seoneo-serp__title pkd-seoneo-serp__title--missing">No title</div>';

    const descHtml = desc
      ? '<div class="pkd-seoneo-serp__description">' + esc(truncate(desc, 160)) + '</div>'
      : '<div class="pkd-seoneo-serp__description pkd-seoneo-serp__description--missing">No description</div>';

    return '<div class="pkd-seoneo-serp">' +
      '<div class="pkd-seoneo-serp__favicon-row">' +
        '<span class="pkd-seoneo-serp__favicon">' +
          (faviconUrl ? '<img src="' + esc(faviconUrl) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
        '</span>' +
        '<span class="pkd-seoneo-serp__site-name">' + esc(siteName || domainFrom(url)) + '</span>' +
      '</div>' +
      '<div class="pkd-seoneo-serp__url">' + esc(url) + '</div>' +
      titleHtml +
      descHtml +
    '</div>';
  }

  function renderRobots(robots) {
    const noindex  = robots.noindex;
    const nofollow = robots.nofollow;

    let html = '<div class="pkd-seoneo-badge-row">';

    html += badge(
      noindex  ? 'warn' : 'good',
      noindex  ? svgWarn() + ' noindex' : svgCheck() + ' index'
    );
    html += badge(
      nofollow ? 'warn' : 'good',
      nofollow ? svgWarn() + ' nofollow' : svgCheck() + ' follow'
    );

    html += '</div>';

    if (noindex || nofollow) {
      html += '<p style="margin:0;font-size:12px;color:var(--pkd-seoneo-status-warn);font-family:var(--pkd-seoneo-font);line-height:1.5;">' +
        'This page is restricted from search engines.' +
      '</p>';
    }

    return html;
  }

  function renderHreflang(alts) {
    let html = '<ul class="pkd-seoneo-tag-list">';
    alts.forEach(function (alt) {
      html += '<li class="pkd-seoneo-tag-list__item"><a href="' + esc(alt.url) + '" target="_blank" rel="noopener">' + esc(alt.code) + ' — ' + esc(alt.name) + '</a></li>';
    });
    html += '</ul>';
    return html;
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel: Headings
  // ─────────────────────────────────────────────────────────────────────

  function renderHeadingsPanel(data) {
    // Extract headings from the live page DOM (we're running on the page itself)
    const headings = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6')).filter(function (el) {
      // Exclude any headings inside the SeoNeo bar / drawer
      return !el.closest('.pkd-seoneo-bar') && !el.closest('.pkd-seoneo-drawer');
    });

    if (!headings.length) {
      return emptyState('No headings found', 'No H1–H6 elements were found on this page.');
    }

    let html = '<ul class="pkd-seoneo-headings">';
    headings.forEach(function (el) {
      const level = parseInt(el.tagName.substring(1), 10);
      const text  = el.textContent.trim();
      html += '<li class="pkd-seoneo-headings__item" data-level="' + level + '">' +
        '<span class="pkd-seoneo-headings__tag">H' + level + '</span>' +
        '<span class="pkd-seoneo-headings__text">' + esc(text) + '</span>' +
      '</li>';
    });
    html += '</ul>';

    return section('Page headings (' + headings.length + ')', html);
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel: Open Graph
  // ─────────────────────────────────────────────────────────────────────

  function renderOgPanel(data) {
    const og = data.og || {};
    let html = '';

    // OG card preview
    let cardHtml = '<div class="pkd-seoneo-og">';

    // Image
    cardHtml += '<div class="pkd-seoneo-og__image' + (og.image ? '' : ' pkd-seoneo-og__image--empty') + '">';
    if (og.image) {
      cardHtml += '<img src="' + esc(og.image) + '" alt="OG image" loading="lazy">';
    } else {
      cardHtml += '<div class="pkd-seoneo-og__image-placeholder">' + svgImage() + '<span>No OG image</span></div>';
    }
    cardHtml += '</div>';

    // Meta
    cardHtml += '<div class="pkd-seoneo-og__meta">';
    if (og.siteName) cardHtml += '<div class="pkd-seoneo-og__site-name">' + esc(og.siteName) + '</div>';
    cardHtml += '<div class="pkd-seoneo-og__title">' + esc(og.title || '—') + '</div>';
    if (og.description) cardHtml += '<div class="pkd-seoneo-og__description">' + esc(truncate(og.description, 120)) + '</div>';
    cardHtml += '</div></div>';

    html += section('Preview', cardHtml);

    // OG field values
    let fields = '';
    fields += renderSimpleField('og:title', og.title);
    fields += renderSimpleField('og:description', og.description);
    fields += renderSimpleField('og:type', og.type || 'website');
    fields += renderSimpleField('og:url', og.url);
    if (og.siteName) fields += renderSimpleField('og:site_name', og.siteName);
    if (og.image)    fields += renderSimpleField('og:image', og.image);

    html += section('Tags', fields);

    // Twitter
    const tw = data.twitter || {};
    const cardType = tw.card || 'summary';
    html += section('Twitter / X', '<div class="pkd-seoneo-twitter">' + svgTwitter() + '<span>twitter:card = <strong>' + esc(cardType) + '</strong></span></div>');

    return html;
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Field renderers
  // ─────────────────────────────────────────────────────────────────────

  function renderField(opts) {
    const { label, value, length, source, isDirect, greenAt, amberAt, showCounter } = opts;

    let counterClass = 'pkd-seoneo-field__counter--empty';
    let counterText  = '';

    if (showCounter && value) {
      if (amberAt > 0 && length > amberAt) {
        counterClass = 'pkd-seoneo-field__counter--bad';
        counterText  = length + ' / ' + greenAt + ' chars';
      } else if (greenAt > 0 && length > greenAt) {
        counterClass = 'pkd-seoneo-field__counter--warn';
        counterText  = length + ' / ' + greenAt + ' chars';
      } else {
        counterClass = 'pkd-seoneo-field__counter--good';
        counterText  = length + ' / ' + greenAt + ' chars';
      }
    }

    const isMissing  = !value;
    const isFallback = value && !isDirect;

    let valueClass = 'pkd-seoneo-field__value';
    if (isMissing)  valueClass += ' pkd-seoneo-field__value--missing';
    if (isFallback) valueClass += ' pkd-seoneo-field__value--fallback';

    let html = '<div class="pkd-seoneo-field">';

    html += '<div class="pkd-seoneo-field__header">';
    html += '<span class="pkd-seoneo-field__label">' + esc(label) + '</span>';
    if (showCounter && counterText) {
      html += '<span class="pkd-seoneo-field__counter ' + counterClass + '">' + esc(counterText) + '</span>';
    }
    html += '</div>';

    html += '<div class="' + valueClass + '">' + esc(value || 'Not set') + '</div>';

    if (source && source !== label.toLowerCase()) {
      html += '<div class="pkd-seoneo-field__source">' + svgArrow() + '<span class="pkd-seoneo-field__source-text">via ' + esc(source) + '</span></div>';
    }

    html += '</div>';
    return html;
  }

  function renderUrlField(label, value, note) {
    let html = '<div class="pkd-seoneo-field">';
    html += '<div class="pkd-seoneo-field__header"><span class="pkd-seoneo-field__label">' + esc(label) + '</span></div>';
    if (value) {
      html += '<a class="pkd-seoneo-field__url" href="' + esc(value) + '" target="_blank" rel="noopener">' + esc(value) + '</a>';
    } else {
      html += '<div class="pkd-seoneo-field__value pkd-seoneo-field__value--missing">Not set</div>';
    }
    if (note) {
      html += '<div class="pkd-seoneo-field__source">' + svgArrow() + '<span class="pkd-seoneo-field__source-text">' + esc(note) + '</span></div>';
    }
    html += '</div>';
    return html;
  }

  function renderSimpleField(label, value) {
    const cls = value ? 'pkd-seoneo-field__value' : 'pkd-seoneo-field__value pkd-seoneo-field__value--missing';
    return '<div class="pkd-seoneo-field">' +
      '<div class="pkd-seoneo-field__header"><span class="pkd-seoneo-field__label">' + esc(label) + '</span></div>' +
      '<div class="' + cls + '">' + esc(value || 'Not set') + '</div>' +
    '</div>';
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Layout helpers
  // ─────────────────────────────────────────────────────────────────────

  function section(title, content) {
    return '<div class="pkd-seoneo-drawer__section">' +
      '<div class="pkd-seoneo-drawer__section-title">' + esc(title) + '</div>' +
      content +
    '</div>';
  }

  function badge(type, html) {
    return '<span class="pkd-seoneo-badge pkd-seoneo-badge--' + type + '">' + html + '</span>';
  }

  function emptyState(title, text) {
    return '<div class="pkd-seoneo-drawer__empty">' +
      '<div class="pkd-seoneo-drawer__empty-icon">' + svgSearch() + '</div>' +
      '<p class="pkd-seoneo-drawer__empty-title">' + esc(title) + '</p>' +
      '<p class="pkd-seoneo-drawer__empty-text">' + esc(text) + '</p>' +
    '</div>';
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Utility
  // ─────────────────────────────────────────────────────────────────────

  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function truncate(s, n) {
    s = s || '';
    if (s.length <= n) return s;
    return s.slice(0, n - 1).replace(/\s+\S*$/, '') + '\u2026';
  }

  function domainFrom(url) {
    try { return new URL(url).hostname; } catch (e) { return url; }
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Inline SVG icons (stroke-based, no external deps)
  // ─────────────────────────────────────────────────────────────────────

  function svgCheck() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
  }

  function svgWarn() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  }

  function svgArrow() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
  }

  function svgSearch() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="22" y2="22"/></svg>';
  }

  function svgImage() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
  }

  function svgTwitter() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>';
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Keyboard handling
  // ─────────────────────────────────────────────────────────────────────

  function onKeyDown(e) {
    if (e.key === 'Escape' && _activePanel) {
      closeDrawer();
      // Return focus to the button that opened it
      const activeBtn = bar.querySelector('.pkd-seoneo-bar__btn[data-panel="' + _activePanel + '"]');
      if (activeBtn) activeBtn.focus();
    }
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Body offset so content isn't hidden behind the fixed bar
  // ─────────────────────────────────────────────────────────────────────

  function applyBodyOffset() {
    document.body.classList.add('pkd-seoneo-body-offset');
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Init
  // ─────────────────────────────────────────────────────────────────────

  function init() {
    bar    = document.querySelector('.pkd-seoneo-bar');
    drawer = document.getElementById('pkd-seoneo-drawer');

    if (!bar || !drawer) return;

    drawerBody  = drawer.querySelector('.pkd-seoneo-drawer__body');
    drawerTitle = drawer.querySelector('.pkd-seoneo-drawer__header-title');
    drawerClose = drawer.querySelector('.pkd-seoneo-drawer__close');

    if (!drawerBody || !drawerClose) return;

    // Bar panel buttons
    bar.querySelectorAll('.pkd-seoneo-bar__btn[data-panel]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        toggleDrawer(btn.dataset.panel);
      });
    });

    // Close button
    drawerClose.addEventListener('click', closeDrawer);

    // Keyboard: Escape
    document.addEventListener('keydown', onKeyDown);

    // Prefetch data after a short idle delay so it's ready when drawer opens
    if ('requestIdleCallback' in window) {
      requestIdleCallback(function () { fetchData().catch(function () {}); }, { timeout: 3000 });
    } else {
      setTimeout(function () { fetchData().catch(function () {}); }, 1500);
    }

    applyBodyOffset();
  }

  // Run after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
