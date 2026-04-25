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
			'version'  => '1.0.0',
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

		$this->sendJson($this->buildPageData($page, $module));
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
			'canonical' => [
				'value'    => $resolvedCanon,
				'isDefault' => $resolvedCanon === (string) $page->httpUrl,
			],
			'robots' => [
				'value'    => $resolvedRobots,
				'noindex'  => $noindex,
				'nofollow' => $nofollow,
			],
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

	protected function buildHreflang(Page $page): array {
		$langs = $this->wire('languages');
		if(!$langs || count($langs) < 2) return [];

		$out  = [];
		$user = $this->wire('user');
		$orig = $user->language;
		try {
			foreach($langs as $lang) {
				if(!$page->viewable($lang)) continue;
				$user->language = $lang;
				$out[] = [
					'code' => $this->wire('sanitizer')->name($lang->name),
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

		$css = '<link rel="stylesheet" href="' . $esc($url . 'assets/SeoNeoBar.css') . '?v=' . $esc($v) . '">';
		$js  = '<script src="' . $esc($url . 'assets/SeoNeoBar.js') . '?v=' . $esc($v) . '" defer></script>';

		$jsConfig = json_encode([
			'pageId'   => $page->id,
			'dataUrl'  => $dataUrl,
			'editUrl'  => $editUrl,
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
}
