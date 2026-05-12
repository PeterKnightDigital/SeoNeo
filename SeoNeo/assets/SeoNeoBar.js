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

    // Forward the live page's URL context so the AJAX endpoint can compute
    // canonical / og:url / hreflang against the right URL — the endpoint
    // itself lives at /seoneo-bar-data/, so without these params its
    // ambient $input->urlSegmentStr() would leak into every resolved URL.
    var qs = '?id=' + encodeURIComponent(PAGE_ID);
    if (cfg.urlSegmentStr) qs += '&urlSegmentStr=' + encodeURIComponent(cfg.urlSegmentStr);
    if (cfg.pageNum && cfg.pageNum > 1) qs += '&pageNum=' + encodeURIComponent(cfg.pageNum);

    _dataPromise = fetch(DATA_URL + qs, {
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
    const panelTitles = { seo: 'SEO & Meta', headings: 'Headings', og: 'Open Graph', links: 'Links', images: 'Images' };
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
          case 'schema':   drawerBody.innerHTML = renderSchemaPanel(data);   break;
          case 'headings': drawerBody.innerHTML = renderHeadingsPanel(data); break;
          case 'og':       drawerBody.innerHTML = renderOgPanel(data);       break;
          case 'links':    drawerBody.innerHTML = renderLinksPanel();        break;
          case 'images':   drawerBody.innerHTML = renderImagesPanel();       break;
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

    // URL (the address the visitor is actually on, preserving segments
    // and pagination). Rendered above Canonical so editors can spot
    // mismatches at a glance.
    if (data.url && data.url.value) {
      metaFields += renderUrlField('URL', data.url.value, 'current page');
    }

    // Canonical (what the page tells crawlers is its preferred URL).
    var canonNote = data.canonical.isSelfReferencing
      ? 'self-referencing'
      : (data.canonical.isDefault ? 'auto (page URL)' : 'explicit');
    metaFields += renderUrlField('Canonical', data.canonical.value, canonNote);

    html += section('Meta', metaFields);

    // ── Page / language signals ───────────────────────────────────────
    var signalsHtml = '';
    if (data.lang) {
      signalsHtml += kvRow('Lang', esc(data.lang));
    }
    if (data.wordCount !== null && data.wordCount !== undefined) {
      signalsHtml += kvRow('Word count', esc(String(data.wordCount)));
    } else {
      signalsHtml += kvRow('Word count', '<span class="pkd-seoneo-kv__value--missing">n/a</span>');
    }
    if (data.publisher && data.publisher.name) {
      var pubVal = esc(data.publisher.name) +
        ' <span class="pkd-seoneo-kv__sub">(' + esc(data.publisher.type || 'Organization') + ')</span>';
      signalsHtml += kvRow('Publisher', pubVal);
    } else {
      signalsHtml += kvRow('Publisher', '<span class="pkd-seoneo-kv__value--missing">Missing</span>');
    }
    if (signalsHtml) html += section('Signals', signalsHtml);

    // ── Robots section ────────────────────────────────────────────────
    var robotsHtml = renderRobots(data.robots);
    var xrt = data.robots && data.robots.xRobotsTag;
    if (xrt) {
      robotsHtml += kvRow('X-Robots-Tag', esc(xrt));
    } else {
      robotsHtml += kvRow('X-Robots-Tag', '<span class="pkd-seoneo-kv__value--missing">Not set</span>');
    }
    html += section('Robots', robotsHtml);

    // ── Server / Tools ────────────────────────────────────────────────
    if (data.server && (data.server.robotsTxtUrl || data.server.sitemapUrl)) {
      var toolsHtml = '<div class="pkd-seoneo-tools">';
      if (data.server.robotsTxtUrl) {
        toolsHtml += '<a class="pkd-seoneo-tools__link" target="_blank" rel="noopener" href="' +
          esc(data.server.robotsTxtUrl) + '">robots.txt</a>';
      }
      if (data.server.sitemapUrl) {
        toolsHtml += '<a class="pkd-seoneo-tools__link" target="_blank" rel="noopener" href="' +
          esc(data.server.sitemapUrl) + '">sitemap.xml</a>';
      }
      toolsHtml += '</div>';
      html += section('Tools', toolsHtml);
    }

    // ── Keywords (always rendered; placeholder when empty) ────────────
    var kwHtml;
    if (data.keywords) {
      var kws = data.keywords.split(',').map(function (k) { return k.trim(); }).filter(Boolean);
      kwHtml = '<ul class="pkd-seoneo-tag-list">';
      kws.forEach(function (k) { kwHtml += '<li class="pkd-seoneo-tag-list__item">' + esc(k) + '</li>'; });
      kwHtml += '</ul>';
    } else {
      kwHtml = '<div class="pkd-seoneo-kv__value pkd-seoneo-kv__value--missing">Missing</div>';
    }
    html += section('Keywords', kwHtml);

    // ── Hreflang (always rendered; placeholder when single-language) ──
    if (data.hreflang && data.hreflang.length > 0) {
      html += section('Hreflang', renderHreflang(data.hreflang));
    } else {
      html += section('Hreflang',
        '<div class="pkd-seoneo-kv__value pkd-seoneo-kv__value--missing">Single-language site</div>');
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
  //  Schema (JSON-LD) panel
  // ─────────────────────────────────────────────────────────────────────

  function renderSchemaPanel(data) {
    var graph = data && data.jsonLd && Array.isArray(data.jsonLd['@graph'])
      ? data.jsonLd['@graph']
      : [];

    if (!graph.length) {
      return emptyState(
        'No JSON-LD on this page',
        'Enable structured data in SEO NEO module config under "Structured data (JSON-LD)" to start emitting Schema.org graphs.'
      );
    }

    // Summary badge row
    var typeCounts = {};
    graph.forEach(function (node) {
      var t = node['@type'] || 'Unknown';
      typeCounts[t] = (typeCounts[t] || 0) + 1;
    });
    var summary = '<div class="pkd-seoneo-badge-row">' +
      badge('good', svgCheck() + ' ' + graph.length + ' nodes') +
      Object.keys(typeCounts).map(function (t) {
        var n = typeCounts[t];
        return badge('neutral', esc(t) + (n > 1 ? ' × ' + n : ''));
      }).join('') +
    '</div>';

    var html = section('Summary', summary);

    // Per-node disclosure (expanded by default for the first node)
    var nodesHtml = '';
    graph.forEach(function (node, idx) {
      var type = node['@type'] || 'Unknown';
      var nodeName = node.name || node.headline || (node['@id'] || '');
      var open = idx === 0 ? ' open' : '';
      var pretty = JSON.stringify(node, null, 2);
      var schemaUrl = 'https://schema.org/' + encodeURIComponent(String(type));

      nodesHtml +=
        '<details class="pkd-seoneo-schema__node"' + open + '>' +
          '<summary class="pkd-seoneo-schema__summary">' +
            '<span class="pkd-seoneo-schema__type">' + esc(String(type)) + '</span>' +
            (nodeName
              ? '<span class="pkd-seoneo-schema__name">' + esc(String(nodeName)) + '</span>'
              : '') +
            '<a class="pkd-seoneo-schema__doc" href="' + esc(schemaUrl) + '" target="_blank" rel="noopener" ' +
              'title="View ' + esc(String(type)) + ' on schema.org" onclick="event.stopPropagation();">↗</a>' +
          '</summary>' +
          '<pre class="pkd-seoneo-schema__code">' + esc(pretty) + '</pre>' +
        '</details>';
    });

    html += section('Graph', nodesHtml);

    // Validator links
    var pageUrl = data.url && data.url.value ? data.url.value : '';
    if (pageUrl) {
      var rich  = 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(pageUrl);
      var sval  = 'https://validator.schema.org/#url=' + encodeURIComponent(pageUrl);
      html += section('Validate',
        '<div class="pkd-seoneo-tools">' +
          '<a class="pkd-seoneo-tools__link" target="_blank" rel="noopener" href="' + esc(rich) + '">Google Rich Results Test</a>' +
          '<a class="pkd-seoneo-tools__link" target="_blank" rel="noopener" href="' + esc(sval) + '">Schema.org Validator</a>' +
        '</div>'
      );
    }

    return html;
  }

  // ─────────────────────────────────────────────────────────────────────
  //  DOM element filter — excludes admin toolbars from page scans
  // ─────────────────────────────────────────────────────────────────────

  function isPageContent(el) {
    return !el.closest('.pkd-seoneo-bar') &&
           !el.closest('.pkd-seoneo-drawer') &&
           !el.closest('#tracy-debug') &&
           !el.closest('#tracy-debug-bar');
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel: Headings
  // ─────────────────────────────────────────────────────────────────────

  function renderHeadingsPanel(data) {
    const headings = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6')).filter(isPageContent);

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
  //  Panel: Links
  // ─────────────────────────────────────────────────────────────────────

  function renderLinksPanel() {
    // Collect all anchors on the page, excluding SeoNeoBar's own elements
    var allLinks = Array.from(document.querySelectorAll('a')).filter(isPageContent);

    if (!allLinks.length) {
      return emptyState('No links found', 'No anchor elements were found on this page.');
    }

    var broken   = [];
    var internal = [];
    var external = [];
    var hashOnly = [];
    var uniqueHrefs = new Set();

    allLinks.forEach(function (el) {
      var href = (el.getAttribute('href') || '').trim();
      var text = el.textContent.trim() || el.getAttribute('aria-label') || '';
      var entry = { href: href, text: text };
      var lower = href.toLowerCase();

      // Truly broken: no href at all, or javascript: pseudo-protocol
      if (!href || lower === 'javascript:;' || lower.indexOf('javascript:void') === 0) {
        broken.push(entry);
        return;
      }

      // Hash-only links (href="#") — common for JS-driven UI (tabs, toggles, dropdowns)
      if (href === '#') {
        hashOnly.push(entry);
        return;
      }

      uniqueHrefs.add(href);

      // Classify internal vs external by hostname
      var isInternal = false;
      try {
        if (!/^[a-z][a-z\d+\-.]*:/i.test(href)) {
          isInternal = true;
        } else {
          isInternal = new URL(href).hostname === window.location.hostname;
        }
      } catch (e) {
        isInternal = true;
      }

      if (isInternal) {
        internal.push(entry);
      } else {
        external.push(entry);
      }
    });

    var total   = allLinks.length;
    var unique  = uniqueHrefs.size;
    var numInt  = internal.length;
    var numExt  = external.length;

    var html = '';

    // Summary
    var summaryHtml = '<div class="pkd-seoneo-badge-row">' +
      badge('neutral', 'Total: ' + total) +
      badge('neutral', 'Unique: ' + unique) +
      badge('good', svgCheck() + ' Internal: ' + numInt) +
      badge('neutral', 'External: ' + numExt) +
      (hashOnly.length ? badge('neutral', '# Hash: ' + hashOnly.length) : '') +
      (broken.length ? badge('bad', svgWarn() + ' Broken: ' + broken.length) : '') +
    '</div>';
    html += section('Summary', summaryHtml);

    // Broken anchors (truly problematic)
    if (broken.length) {
      var brokenHtml = '<ul class="pkd-seoneo-tag-list">';
      broken.forEach(function (link) {
        var display = link.text || link.href || '(no text, no href)';
        brokenHtml += '<li class="pkd-seoneo-tag-list__item pkd-seoneo-tag-list__item--error">' + esc(truncate(display, 60)) + '</li>';
      });
      brokenHtml += '</ul>';
      html += section('Broken (' + broken.length + ')', brokenHtml);
    }

    // Hash-only links (informational, not alarming)
    if (hashOnly.length) {
      var hashHtml = '<ul class="pkd-seoneo-tag-list">';
      hashOnly.forEach(function (link) {
        var display = link.text || '(no text)';
        hashHtml += '<li class="pkd-seoneo-tag-list__item">' + esc(truncate(display, 60)) + '</li>';
      });
      hashHtml += '</ul>';
      html += section('Hash-only (#) links (' + hashOnly.length + ')', hashHtml);
    }

    // Render a grouped link list
    function renderLinkList(links) {
      if (!links.length) {
        return '<p class="pkd-seoneo-links__none">None</p>';
      }
      var out = '<ul class="pkd-seoneo-links">';
      links.forEach(function (link) {
        out += '<li class="pkd-seoneo-links__item">' +
          '<a class="pkd-seoneo-links__href" href="' + esc(link.href) + '" target="_blank" rel="noopener">' + esc(truncate(link.href, 55)) + '</a>' +
          (link.text
            ? '<span class="pkd-seoneo-links__text">' + esc(truncate(link.text, 65)) + '</span>'
            : '<span class="pkd-seoneo-links__text pkd-seoneo-links__text--empty">(no anchor text)</span>'
          ) +
        '</li>';
      });
      out += '</ul>';
      return out;
    }

    html += section('Internal (' + numInt + ')', renderLinkList(internal));
    html += section('External (' + numExt + ')', renderLinkList(external));

    return html;
  }

  // ─────────────────────────────────────────────────────────────────────
  //  Panel: Images
  // ─────────────────────────────────────────────────────────────────────

  function renderImagesPanel() {
    // Collect all images on the page, excluding SeoNeoBar's own elements
    var allImages = Array.from(document.querySelectorAll('img')).filter(isPageContent);

    if (!allImages.length) {
      return emptyState('No images found', 'No img elements were found on this page.');
    }

    var total        = allImages.length;
    var missingAlt   = allImages.filter(function (el) { return !el.hasAttribute('alt'); });
    var missingTitle = allImages.filter(function (el) { return !el.title; });

    // Tally file types
    var typeCounts = {};
    allImages.forEach(function (el) {
      var src = el.getAttribute('src') || '';
      var filename = src.split('/').pop().split('?')[0];
      var extMatch = filename.match(/\.([a-zA-Z0-9]+)$/);
      var ext = extMatch ? extMatch[1].toUpperCase() : 'OTHER';
      typeCounts[ext] = (typeCounts[ext] || 0) + 1;
    });

    var html = '';

    // Summary — counts row
    var summaryHtml = '<div class="pkd-seoneo-badge-row">' +
      badge('neutral', 'Total: ' + total) +
      badge(
        missingAlt.length === 0 ? 'good' : 'bad',
        (missingAlt.length === 0 ? svgCheck() : svgWarn()) + ' Without Alt: ' + missingAlt.length
      ) +
      badge(
        missingTitle.length === 0 ? 'good' : 'warn',
        'Without Title: ' + missingTitle.length
      ) +
    '</div>';

    // Type breakdown row
    var typeBadges = Object.keys(typeCounts).sort().map(function (ext) {
      return badge('neutral', ext + ': ' + typeCounts[ext]);
    }).join('');
    summaryHtml += '<div class="pkd-seoneo-badge-row" style="margin-top:var(--pkd-seoneo-sp-2)">' + typeBadges + '</div>';

    html += section('Summary', summaryHtml);

    // Image list
    var listHtml = '<ul class="pkd-seoneo-images">';
    allImages.forEach(function (el) {
      var src       = el.getAttribute('src') || '';
      var hasAlt    = el.hasAttribute('alt');
      var altVal    = el.getAttribute('alt'); // null if missing, '' if empty, string if set
      var titleVal  = el.title || '';
      var filename  = src ? src.split('/').pop().split('?')[0] : '(no src)';
      var naturalW  = el.naturalWidth;
      var naturalH  = el.naturalHeight;
      var dims      = (naturalW && naturalH) ? naturalW + '\u00d7' + naturalH : '';
      var extMatch  = filename.match(/\.([a-zA-Z0-9]+)$/);
      var fileType  = extMatch ? extMatch[1].toUpperCase() : '';

      // Alt status badge
      var altStatus;
      if (!hasAlt) {
        altStatus = badge('bad', svgWarn() + ' missing');
      } else if (altVal === '') {
        altStatus = badge('neutral', svgCheck() + ' empty');
      } else {
        altStatus = badge('good', svgCheck() + ' ' + esc(truncate(altVal, 30)));
      }

      // Title status — muted dash when absent (less important)
      var titleStatus = titleVal
        ? badge('good', svgCheck() + ' ' + esc(truncate(titleVal, 30)))
        : '<span class="pkd-seoneo-images__dash">\u2014</span>';

      listHtml += '<li class="pkd-seoneo-images__item' + (!hasAlt ? ' pkd-seoneo-images__item--warn' : '') + '">' +
        '<div class="pkd-seoneo-images__thumb">' +
          (src ? '<img src="' + esc(src) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
        '</div>' +
        '<div class="pkd-seoneo-images__meta">' +
          '<div class="pkd-seoneo-images__src">' + esc(filename) + '</div>' +
          (dims || fileType
            ? '<div class="pkd-seoneo-images__tech-row">' +
                (dims
                  ? '<div class="pkd-seoneo-images__attr-row">' +
                      '<span class="pkd-seoneo-images__attr-label">W\u00d7H</span>' +
                      '<span class="pkd-seoneo-images__attr-value pkd-seoneo-images__dims">' + esc(dims) + '</span>' +
                    '</div>'
                  : '') +
                (fileType
                  ? '<div class="pkd-seoneo-images__attr-row">' +
                      '<span class="pkd-seoneo-images__attr-label">Type</span>' +
                      '<span class="pkd-seoneo-images__attr-value pkd-seoneo-images__dims">' + esc(fileType) + '</span>' +
                    '</div>'
                  : '') +
              '</div>'
            : '') +
          '<div class="pkd-seoneo-images__attr-row">' +
            '<span class="pkd-seoneo-images__attr-label">Alt</span>' +
            '<span class="pkd-seoneo-images__attr-value">' + altStatus + '</span>' +
          '</div>' +
          '<div class="pkd-seoneo-images__attr-row">' +
            '<span class="pkd-seoneo-images__attr-label">Title</span>' +
            '<span class="pkd-seoneo-images__attr-value">' + titleStatus + '</span>' +
          '</div>' +
        '</div>' +
      '</li>';
    });
    listHtml += '</ul>';

    html += section('Images (' + total + ')', listHtml);

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

  function kvRow(key, valueHtml) {
    return '<div class="pkd-seoneo-kv">' +
      '<span class="pkd-seoneo-kv__key">' + esc(key) + '</span>' +
      '<span class="pkd-seoneo-kv__value">' + valueHtml + '</span>' +
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

  function svgLink() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
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
