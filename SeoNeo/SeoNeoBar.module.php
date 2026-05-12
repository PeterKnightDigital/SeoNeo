<?php namespace ProcessWire;

/**
 * SeoNeoBar — Frontend admin bar for SeoNeo.
 *
 * Injects a fixed bar at the bottom of the page for logged-in editors,
 * with a slide-in drawer showing fully-resolved SEO and meta data for
 * the current page. Read-only, Phase 1.
 *
 * The bar is only injected when:
 *   - The current user is logged in
 *   - The user has page-edit permission
 *   - The page is a front-end page (not the admin)
 *   - The rendered HTML contains a </body> tag
 *
 * Data is fetched lazily via a lightweight AJAX endpoint when the drawer
 * is first opened, so there is no overhead on pages where it stays closed.
 */
class SeoNeoBar extends WireData implements Module {

	public static function getModuleInfo() {
		return [
			'title'    => 'SeoNeo Bar',
			'version'  => '1.3.0',
			'summary'  => 'Frontend admin bar showing resolved SEO data for the current page.',
			'icon'     => 'bar-chart',
			'autoload' => true,
			'singular' => true,
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0', 'SeoNeo'],
		];
	}

	// ────────────────────────────────────────────────────────────────────
	//  Module lifecycle
	// ────────────────────────────────────────────────────────────────────

	public function init() {
		// Register the AJAX data endpoint via a URL hook.
		// Accessible at: /seoneo-bar-data/?id=PAGE_ID
		$this->addHook('/seoneo-bar-data/', $this, 'handleDataRequest');
	}

	public function ready() {
		$page = $this->wire('page');
		if(!$page || $page->template->name === 'admin') return;
		if(!$this->currentUserCanSeeBar()) return;

		$this->addHookAfter('Page::render', $this, 'hookInjectBar');
	}

	// ────────────────────────────────────────────────────────────────────
	//  Bar injection
	// ────────────────────────────────────────────────────────────────────

	public function hookInjectBar(HookEvent $event) {
		$page = $event->object;
		if(!$page || !$page->id || $page->template->name === 'admin') return;
		if(!$this->currentUserCanSeeBar()) return;

		$html = (string) $event->return;
		if($html === '' || stripos($html, '</body>') === false) return;

		// Avoid double-injection
		if(strpos($html, 'pkd-seoneo-bar') !== false) return;

		$barHtml = $this->renderBar($page);
		if($barHtml === '') return;

		// Replace only the last </body> occurrence to be safe with inline SVG etc.
		$event->return = preg_replace('~</body>~i', $barHtml . "\n</body>", $html, 1);
	}

	// ────────────────────────────────────────────────────────────────────
	//  AJAX data endpoint
	// ────────────────────────────────────────────────────────────────────

	public function handleDataRequest(HookEvent $event) {
		// Auth check — must be logged in with page-edit permission
		$user = $this->wire('user');
		if(!$user->isLoggedIn() || (!$user->isSuperuser() && !$user->hasPermission('page-edit'))) {
			$this->sendJson(['error' => 'Unauthorized'], 403);
		}

		$input = $this->wire('input');
		$pages = $this->wire('pages');
		$id    = (int) $input->get->pageId ?: (int) $input->get('id');

		if($id < 1) {
			$this->sendJson(['error' => 'Missing page id'], 400);
		}

		/** @var Page|NullPage $page */
		$page = $pages->get("id=$id, include=all");
		if(!$page || !$page->id) {
			$this->sendJson(['error' => 'Page not found'], 404);
		}

		// Verify the user can actually view this page
		if(!$page->viewable()) {
			$this->sendJson(['error' => 'Forbidden'], 403);
		}

		/** @var SeoNeo $module */
		$module = $this->wire('modules')->get('SeoNeo');
		if(!$module) {
			$this->sendJson(['error' => 'SeoNeo module not available'], 500);
		}

		// Spoof the live page's URL context onto $input for the duration of
		// the data build. The bar's AJAX endpoint is itself a URL hook at
		// /seoneo-bar-data/, so left as-is $input->urlSegmentStr() reports
		// "seoneo-bar-data" — which the canonical / og:url / twitter:url /
		// hreflang resolvers in SeoNeo would then dutifully append to every
		// URL they produce. The bar passes the page's actual URL context
		// (captured at bar-injection time in renderBar()) so the resolvers
		// see what they would see during the page's own render pass.
		$frontendSegmentStr = trim((string) $input->get('urlSegmentStr'), '/');
		$frontendPageNum    = max(1, (int) $input->get('pageNum'));

		$savedSegments = $input->urlSegments();
		$savedPageNum  = $input->pageNum();

		$input->setUrlSegments($frontendSegmentStr === '' ? [] : explode('/', $frontendSegmentStr));
		$input->setPageNum($frontendPageNum);

		try {
			$data = $this->buildPageData($page, $module);
		} finally {
			$input->setUrlSegments(is_array($savedSegments) ? array_values($savedSegments) : []);
			$input->setPageNum($savedPageNum);
		}

		$this->sendJson($data);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Data builder
	// ────────────────────────────────────────────────────────────────────

	protected function buildPageData(Page $page, SeoNeo $module): array {
		$titleField = $module->get('role_title') ?: 'seoneo_title';
		$descField  = $module->get('role_description') ?: 'seoneo_description';
		$canonField = $module->get('role_canonical') ?: 'seoneo_canonical';
		$noindexField  = $module->get('role_noindex') ?: 'seoneo_noindex';
		$nofollowField = $module->get('role_nofollow') ?: 'seoneo_nofollow';

		// Resolved values
		$resolvedTitle   = $module->getTitle($page);
		$resolvedDesc    = $module->getDescription($page);
		$resolvedCanon   = $module->getCanonical($page);
		$resolvedRobots  = $module->getRobots($page);
		$resolvedOgTitle = $module->getOgTitle($page);
		$resolvedOgImage = $module->getOgImage($page);

		// Determine value sources for the "via X field" labels
		$titleSource = $this->resolveSource($page, $titleField, $module, 'title');
		$descSource  = $this->resolveSource($page, $descField, $module, 'description');

		// Robots breakdown
		$robots = explode(',', $resolvedRobots);
		$noindex  = in_array('noindex', $robots, true);
		$nofollow = in_array('nofollow', $robots, true);

		// Keywords
		$keywords = '';
		if($page->template->hasField('seoneo_keywords')) {
			$keywords = trim((string) $page->get('seoneo_keywords'));
		}

		// Hreflang alternates
		$hreflang = $this->buildHreflang($page);

		// Current viewing URL: the address the visitor is actually on,
		// preserving URL segments and pagination. Distinct from canonical,
		// which may strip either depending on SEO NEO's policy config.
		$resolvedUrl = $this->buildCurrentUrl($page);

		// BCP47 language code for the page's current language
		$lang = $this->wire('user')->language;
		$langCode = method_exists($module, '___getHreflangCode') ? $module->getHreflangCode($lang) : 'en';

		// Word count: count whitespace-separated tokens in the body field
		// after stripping HTML. Returns null when the template has no body.
		$wordCount = $this->computeWordCount($page);

		// Full JSON-LD graph for the Schema panel and the Publisher row.
		// Use method_exists on the underscored hookable name — PW dispatches
		// `getJsonLd(...)` via `__call` so `method_exists($module, 'getJsonLd')`
		// returns false even when the method is callable.
		$jsonLd = method_exists($module, '___getJsonLd') ? $module->getJsonLd($page) : [];

		// Publisher (read from the JSON-LD Organization / Person node)
		$publisher = $this->extractPublisher($jsonLd);

		// X-Robots-Tag: try a HEAD request to the page itself so we report
		// what the server actually emits (frameworks, security plugins, or
		// CDN config can set this header outside of SEO NEO's control).
		$xRobotsTag = $this->probeXRobotsTag($resolvedUrl);

		// Robots.txt and Sitemap shortcuts
		$rootUrl = (string) $this->wire('config')->urls->httpRoot;
		$server = [
			'robotsTxtUrl' => rtrim($rootUrl, '/') . '/robots.txt',
			'sitemapUrl'   => rtrim($rootUrl, '/') . '/sitemap.xml',
		];

		// Twitter card type
		$twitterCard = $resolvedOgImage ? 'summary_large_image' : 'summary';

		// Site name and canonical details
		$siteName = (string) $module->get('site_name');

		// Character thresholds
		$thresholds = [
			'titleGreen' => (int) $module->get('counter_title_green') ?: 60,
			'titleAmber' => (int) $module->get('counter_title_amber') ?: 70,
			'descGreen'  => (int) $module->get('counter_desc_green') ?: 160,
			'descAmber'  => (int) $module->get('counter_desc_amber') ?: 180,
		];

		return [
			'pageId'    => $page->id,
			'pageTitle' => (string) $page->title,
			'template'  => $page->template->name,
			'hasSeoTab' => (bool) $page->template->hasField('seoneo_tab'),
			'editUrl'   => $this->wire('config')->urls->admin . 'page/edit/?id=' . $page->id,

			'title' => [
				'value'    => $resolvedTitle,
				'raw'      => $this->readField($page, $titleField),
				'source'   => $titleSource,
				'length'   => mb_strlen($resolvedTitle),
			],
			'description' => [
				'value'    => $resolvedDesc,
				'raw'      => $this->readField($page, $descField),
				'source'   => $descSource,
				'length'   => mb_strlen($resolvedDesc),
			],
			'url' => [
				'value' => $resolvedUrl,
			],
			'canonical' => [
				'value'    => $resolvedCanon,
				'isDefault' => $resolvedCanon === (string) $page->httpUrl,
				'isSelfReferencing' => $resolvedCanon === $resolvedUrl,
			],
			'robots' => [
				'value'    => $resolvedRobots,
				'noindex'  => $noindex,
				'nofollow' => $nofollow,
				'xRobotsTag' => $xRobotsTag,
			],
			'lang' => $langCode,
			'wordCount' => $wordCount,
			'publisher' => $publisher,
			'server'    => $server,
			'jsonLd'    => $jsonLd,
			'og' => [
				'title'       => $resolvedOgTitle,
				'description' => $resolvedDesc,
				'image'       => $resolvedOgImage,
				'url'         => $resolvedCanon,
				'siteName'    => $siteName,
				'type'        => 'website',
			],
			'twitter' => [
				'card' => $twitterCard,
			],
			'keywords'   => $keywords,
			'hreflang'   => $hreflang,
			'thresholds' => $thresholds,
		];
	}

	protected function resolveSource(Page $page, string $fieldName, SeoNeo $module, string $key): string {
		// Value came directly from the SEO field
		if($fieldName !== '' && $page->template->hasField($fieldName)) {
			$val = $page->get($fieldName);
			if(is_object($val)) $val = method_exists($val, '__toString') ? (string) $val : '';
			if(trim((string) $val) !== '') return $fieldName;
		}

		// Value came from smart-map
		$map = $module->getSmartMap();
		if(isset($map[$key])) {
			foreach($map[$key] as $f) {
				if(!$page->template->hasField($f)) continue;
				$val = $page->get($f);
				if(is_object($val)) $val = method_exists($val, '__toString') ? (string) $val : '';
				if(trim(strip_tags((string) $val)) !== '') return $f;
			}
		}

		// Value came from template defaults or page title fallback
		$defaults = $module->getTemplateDefaults();
		$tpl = $page->template->name;
		if(isset($defaults[$tpl][$key]) && $defaults[$tpl][$key] !== '') {
			return 'template default';
		}

		if($key === 'title') return 'page title';

		return '';
	}

	/**
	 * Build the absolute URL the visitor is actually on, preserving any
	 * URL segments and pagination prefix snapshotted from the frontend
	 * request (see handleDataRequest). Always falls back to $page->httpUrl
	 * so the resulting value is never empty.
	 */
	protected function buildCurrentUrl(Page $page): string {
		$input = $this->wire('input');
		$base  = (string) $page->httpUrl;
		if($base === '') return '';

		$segmentStr = method_exists($input, 'urlSegmentStr')
			? trim((string) $input->urlSegmentStr(), '/')
			: '';
		$pageNum = max(1, (int) $input->pageNum());

		$out = rtrim($base, '/');
		if($segmentStr !== '') $out .= '/' . $segmentStr;

		if($pageNum > 1) {
			$config = $this->wire('config');
			$prefix = (string) ($config->pageNumUrlPrefix ?? 'page');
			$user   = $this->wire('user');
			if($user && $user->language && is_array($config->pageNumUrlPrefixes ?? null)) {
				$langName = (string) $user->language->name;
				if(isset($config->pageNumUrlPrefixes[$langName])) {
					$prefix = (string) $config->pageNumUrlPrefixes[$langName];
				}
			}
			$out .= '/' . $prefix . $pageNum;
		}

		return $out . '/';
	}

	/**
	 * Extract a `{name, url, type}` publisher summary from the JSON-LD
	 * `@graph`. Used to render the Publisher row in the Overview panel.
	 * Returns null when the graph has no Organization / Person node.
	 */
	protected function extractPublisher(array $jsonLd): ?array {
		if(empty($jsonLd['@graph']) || !is_array($jsonLd['@graph'])) return null;
		$publisherTypes = ['Organization', 'LocalBusiness', 'NewsMediaOrganization', 'EducationalOrganization', 'Person'];
		foreach($jsonLd['@graph'] as $node) {
			if(!is_array($node)) continue;
			$type = $node['@type'] ?? '';
			if(!in_array($type, $publisherTypes, true)) continue;
			$name = (string) ($node['name'] ?? '');
			if($name === '') continue;
			return [
				'name' => $name,
				'url'  => (string) ($node['url'] ?? ''),
				'type' => $type,
			];
		}
		return null;
	}

	/**
	 * HEAD-probe the page URL to read its `X-Robots-Tag` response header,
	 * if any. Returns an empty string when the header isn't present or the
	 * probe fails. Times out aggressively (1.5s) so an unreachable backend
	 * never holds up the bar drawer.
	 */
	protected function probeXRobotsTag(string $url): string {
		if($url === '') return '';
		$ctx = stream_context_create([
			'http'  => ['method' => 'HEAD', 'timeout' => 1.5, 'ignore_errors' => true],
			'https' => ['method' => 'HEAD', 'timeout' => 1.5, 'ignore_errors' => true],
			'ssl'   => ['verify_peer' => false, 'verify_peer_name' => false],
		]);
		$prevTrack = ini_get('user_agent');
		$headers = @get_headers($url, true, $ctx);
		if(!is_array($headers)) return '';
		foreach($headers as $name => $value) {
			if(!is_string($name)) continue;
			if(strcasecmp($name, 'X-Robots-Tag') !== 0) continue;
			if(is_array($value)) return implode(', ', $value);
			return (string) $value;
		}
		return '';
	}

	/**
	 * Count words in the page body, after stripping HTML. Returns null if
	 * the template has no body-like field, so the bar can render "n/a".
	 */
	protected function computeWordCount(Page $page): ?int {
		$candidates = ['body', 'content', 'text'];
		$text = '';
		foreach($candidates as $name) {
			if(!$page->template->hasField($name)) continue;
			$val = $page->get($name);
			if(is_object($val) && method_exists($val, '__toString')) $val = (string) $val;
			$text = trim((string) $val);
			if($text !== '') break;
		}
		if($text === '') return null;

		$plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
		if($plain === '') return 0;
		return count(preg_split('/\s+/u', $plain));
	}

	protected function buildHreflang(Page $page): array {
		$langs = $this->wire('languages');
		if(!$langs || count($langs) < 2) return [];

		/** @var SeoNeo $module */
		$module = $this->wire('modules')->get('SeoNeo');

		$out  = [];
		$user = $this->wire('user');
		$orig = $user->language;
		try {
			foreach($langs as $lang) {
				if(!$page->viewable($lang)) continue;
				$user->language = $lang;
				$code = $module && method_exists($module, '___getHreflangCode')
					? $module->getHreflangCode($lang)
					: $this->wire('sanitizer')->name($lang->name);
				if($code === '') continue;
				$out[] = [
					'code' => $code,
					'url'  => (string) $page->httpUrl,
					'name' => (string) $lang->title ?: $lang->name,
				];
			}
		} finally {
			$user->language = $orig;
		}
		return $out;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Bar + drawer HTML
	// ────────────────────────────────────────────────────────────────────

	protected function renderBar(Page $page): string {
		$config  = $this->wire('config');
		$url     = $config->urls($this->className()) ?: $config->urls->siteModules . 'SeoNeoBar/';
		$v       = $this->getModuleInfo()['version'] ?? '1.0.0';
		$editUrl = $config->urls->admin . 'page/edit/?id=' . $page->id;
		$dataUrl = $config->urls->root . 'seoneo-bar-data/';
		$esc     = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Snapshot the live request's URL context for the AJAX endpoint to
		// echo back when it computes URL-segment-aware values (canonical,
		// og:url, twitter:url, hreflang). At this moment $input reflects the
		// page the editor is actually viewing; by the time the drawer fetch
		// fires, $input will instead reflect /seoneo-bar-data/, which is the
		// wrong context for those resolvers.
		$input = $this->wire('input');
		$urlSegmentStr = method_exists($input, 'urlSegmentStr')
			? trim((string) $input->urlSegmentStr(), '/')
			: '';
		$pageNum = max(1, (int) $input->pageNum());

		$css = '<link rel="stylesheet" href="' . $esc($url . 'assets/SeoNeoBar.css') . '?v=' . $esc($v) . '">';
		$js  = '<script src="' . $esc($url . 'assets/SeoNeoBar.js') . '?v=' . $esc($v) . '" defer></script>';

		$jsConfig = json_encode([
			'pageId'   => $page->id,
			'dataUrl'  => $dataUrl,
			'editUrl'  => $editUrl,
			'urlSegmentStr' => $urlSegmentStr,
			'pageNum'       => $pageNum,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$configScript = '<script>window.SeoNeoBarConfig = ' . $jsConfig . ';</script>';

		$bar    = $this->renderBarHtml($page, $editUrl, $esc);
		$drawer = $this->renderDrawerHtml($esc);

		return "\n" . $css . "\n" . $configScript . "\n" . $bar . "\n" . $drawer . "\n" . $js;
	}

	protected function renderBarHtml(Page $page, string $editUrl, callable $esc): string {
		$tmpl = $page->template->name;
		return <<<HTML
<!-- SeoNeoBar -->
<div class="pkd-seoneo-bar" role="toolbar" aria-label="SeoNeo Admin Bar">

  <span class="pkd-seoneo-bar__logo" aria-hidden="true">
    <span class="pkd-seoneo-bar__logo-mark">
      {$this->svgLogoMark()}
    </span>
    <span class="pkd-seoneo-bar__logo-label">SeoNeo</span>
  </span>

  <span class="pkd-seoneo-bar__divider"></span>

  <nav class="pkd-seoneo-bar__actions" aria-label="SeoNeo panels">

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="seo"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="SEO &amp; Meta"
    >
      {$this->svgSearch()}
      <span class="pkd-seoneo-bar__btn-label">SEO &amp; Meta</span>
    </button>

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="headings"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="Headings"
    >
      {$this->svgHeadings()}
      <span class="pkd-seoneo-bar__btn-label">Headings</span>
    </button>

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="og"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="Open Graph"
    >
      {$this->svgShare()}
      <span class="pkd-seoneo-bar__btn-label">Open Graph</span>
    </button>

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="schema"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="Schema (JSON-LD)"
    >
      {$this->svgSchema()}
      <span class="pkd-seoneo-bar__btn-label">Schema</span>
    </button>

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="links"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="Links"
    >
      {$this->svgLinks()}
      <span class="pkd-seoneo-bar__btn-label">Links</span>
    </button>

    <button
      class="pkd-seoneo-bar__btn"
      type="button"
      data-panel="images"
      aria-expanded="false"
      aria-controls="pkd-seoneo-drawer"
      title="Images"
    >
      {$this->svgImages()}
      <span class="pkd-seoneo-bar__btn-label">Images</span>
    </button>

  </nav>

  <span class="pkd-seoneo-bar__spacer"></span>

  <span class="pkd-seoneo-bar__page-id" aria-label="Template: {$esc($tmpl)}">{$esc($tmpl)}</span>

  <a
    class="pkd-seoneo-bar__edit-link"
    href="{$esc($editUrl)}"
    title="Edit this page in ProcessWire"
  >
    {$this->svgEdit()}
    Edit page
  </a>

</div>
<!-- /SeoNeoBar -->
HTML;
	}

	protected function renderDrawerHtml(callable $esc): string {
		return <<<HTML
<div
  id="pkd-seoneo-drawer"
  class="pkd-seoneo-drawer"
  role="dialog"
  aria-label="SeoNeo panel"
  aria-hidden="true"
>
  <div class="pkd-seoneo-drawer__header">
    <span class="pkd-seoneo-drawer__header-icon" aria-hidden="true">
      {$this->svgLogoMark()}
    </span>
    <h2 class="pkd-seoneo-drawer__header-title" id="pkd-seoneo-drawer-title">SEO &amp; Meta</h2>
    <button
      class="pkd-seoneo-drawer__close"
      type="button"
      aria-label="Close panel"
    >
      {$this->svgClose()}
    </button>
  </div>
  <div class="pkd-seoneo-drawer__body" role="region" aria-labelledby="pkd-seoneo-drawer-title">
    <div class="pkd-seoneo-drawer__loading">
      <div class="pkd-seoneo-spinner" role="status" aria-label="Loading…"></div>
      <span>Loading…</span>
    </div>
  </div>
</div>
HTML;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Permission check
	// ────────────────────────────────────────────────────────────────────

	protected function currentUserCanSeeBar(): bool {
		$user = $this->wire('user');
		if(!$user || !$user->isLoggedIn()) return false;
		if($user->isSuperuser()) return true;
		return $user->hasPermission('page-edit');
	}

	// ────────────────────────────────────────────────────────────────────
	//  Helpers
	// ────────────────────────────────────────────────────────────────────

	protected function readField(Page $page, string $fieldName): string {
		if($fieldName === '' || !$page->template->hasField($fieldName)) return '';
		$val = $page->get($fieldName);
		if($val === null) return '';
		if(is_object($val)) {
			return method_exists($val, '__toString') ? trim((string) $val) : '';
		}
		return trim((string) $val);
	}

	protected function sendJson(array $data, int $status = 200): void {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($status);
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	// ────────────────────────────────────────────────────────────────────
	//  SVG icons (inline, scoped, no external deps)
	// ────────────────────────────────────────────────────────────────────

	protected function svgLogoMark(): string {
		return '<svg viewBox="0 0 14 14" aria-hidden="true"><path fill="white" d="M6.5 1a5.5 5.5 0 1 0 3.47 9.79l2.12 2.12a.75.75 0 1 0 1.06-1.06l-2.12-2.12A5.5 5.5 0 0 0 6.5 1Zm0 1.5a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z"/></svg>';
	}

	protected function svgSearch(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="22" y2="22"/></svg>';
	}

	protected function svgHeadings(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="17" y2="18"/></svg>';
	}

	protected function svgShare(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
	}

	protected function svgEdit(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
	}

	protected function svgClose(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
	}

	protected function svgLinks(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
	}

	protected function svgImages(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
	}

	protected function svgSchema(): string {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><line x1="12" y1="7" x2="6" y2="17"/><line x1="12" y1="7" x2="18" y2="17"/></svg>';
	}
}
