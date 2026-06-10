<?php namespace ProcessWire;

/**
 * SeoNeo — Modern SEO coordinator for ProcessWire.
 *
 * Works WITH ProcessWire's field system: creates native PW fields for SEO
 * data, reads them via a configurable mapping, resolves fallbacks, and
 * renders the full <head> output. No custom Fieldtype — SeoNeo reads PW
 * fields and renders.
 *
 * Template API:
 *
 *   Resolved-value accessors (return strings, no markup):
 *     $page->seoneo->title
 *     $page->seoneo->description
 *     $page->seoneo->canonical
 *     $page->seoneo->robots
 *
 *   Full <head> block (auto-injected when enabled):
 *     $page->seoneo->render()
 *
 *   Per-section partial render methods (for developers composing
 *   their own <head> with auto-inject turned off):
 *     $page->seoneo->renderTitle()
 *     $page->seoneo->renderDescription()
 *     $page->seoneo->renderCanonical()
 *     $page->seoneo->renderRobots()
 *     $page->seoneo->renderOg()           // og:title, og:description, og:url, og:type,
 *                                         // og:site_name, og:locale[:alternate], og:image*
 *     $page->seoneo->renderTwitter()      // twitter:card, twitter:site, twitter:creator,
 *                                         // twitter:title, twitter:description, twitter:image
 *     $page->seoneo->renderHreflang()
 *     $page->seoneo->renderVerification() // search-engine verification tags (homepage by default)
 *     $page->seoneo->renderAuthor()
 *     $page->seoneo->renderSchema()       // JSON-LD <script> block
 */
class SeoNeo extends WireData implements Module, ConfigurableModule {

	const FIELD_PREFIX = 'seoneo_';

	/** @var bool Guard against recursive Fieldgroups::save during auto field insertion */
	protected static bool $ensuringSeoFields = false;

	const DEFAULT_FIELDS = [
		'seoneo_tab'         => 'FieldtypeFieldsetTabOpen',
		'seoneo_preview'     => 'InputfieldSeoNeoPreview',
		'seoneo_title'       => 'FieldtypeText',
		'seoneo_description' => 'FieldtypeTextarea',
		'seoneo_canonical'   => 'FieldtypeURL',
		'seoneo_keywords'    => 'FieldtypeText',
		'seoneo_og_image'    => 'FieldtypeImage',
		'seoneo_og_type'     => 'FieldtypeText',
		'seoneo_noindex'     => 'FieldtypeCheckbox',
		'seoneo_nofollow'    => 'FieldtypeCheckbox',
		'seoneo_custom'      => 'FieldtypeTextarea',
		'seoneo_tab_END'     => 'FieldtypeFieldsetClose',
	];

	const FIELD_LABELS = [
		'seoneo_tab'         => 'SEO',
		'seoneo_preview'     => 'Google SERP Preview',
		'seoneo_title'       => 'Meta Title',
		'seoneo_description' => 'Meta Description',
		'seoneo_canonical'   => 'Canonical URL',
		'seoneo_keywords'    => 'Meta Keywords',
		'seoneo_og_image'    => 'OG Image',
		'seoneo_og_type'     => 'OG Type',
		'seoneo_noindex'     => 'Noindex',
		'seoneo_nofollow'    => 'Nofollow',
		'seoneo_custom'      => 'Custom <head> HTML',
		'seoneo_tab_END'     => '',
	];

	const FIELD_DESCRIPTIONS = [
		'seoneo_preview'     => 'Live preview of how this page will appear in Google search results. Updates as you type — switch between desktop and mobile to check both layouts.',
		'seoneo_title'       => 'Override the page title used in search results. Leave empty to use smart-map fallbacks.',
		'seoneo_description' => 'A short summary for search engine results. Leave empty to use smart-map fallbacks.',
		'seoneo_canonical'   => 'Leave empty to use the page URL automatically. Accepts an absolute URL (https://example.com/path/) or a root-relative path (/path/) — relative paths are expanded against the current site\'s scheme and host.',
		'seoneo_keywords'    => 'Comma-separated keywords. Most search engines no longer use this, but some sites still want it.',
		'seoneo_og_image'    => 'Upload one image to use as the og:image for this page. If empty, SeoNeo falls back to other image fields, then the homepage OG image, then the module default URL.',
		'seoneo_og_type'     => 'Override the og:type for this page. Common values: website (default), article, profile, product, book, video.movie, music.song. Leave empty to use the per-template default or the site-wide default from module config.',
		'seoneo_noindex'     => 'Tell search engines not to index this page.',
		'seoneo_nofollow'    => 'Tell search engines not to follow links on this page.',
		'seoneo_custom'      => 'Raw HTML inserted in the <head> for this page only. Useful for site-verification snippets (Google, Bing, Yandex, Pinterest, ahrefs), structured-data scripts, or any meta tag not handled by SeoNeo. Output as-is — anything you paste here is rendered verbatim, so paste only HTML you trust.',
	];

	/**
	 * Module info.
	 *
	 * NOTE: keep `version` here in sync with `SeoNeo.info.json`. Anything
	 * inside this module that needs the live version for an asset URL or
	 * similar should call `$this->wire('modules')->getModuleInfo($this->className())`
	 * rather than `$this->getModuleInfo()` — only the modules-manager
	 * lookup stays in sync with info.json automatically.
	 */
	public static function getModuleInfo() {
		return [
			'title'    => 'SeoNeo',
			'version'  => '1.1.1',
			'summary'  => 'Modern SEO coordinator for ProcessWire — uses native PW fields for meta, robots, canonical, and more.',
			'icon'     => 'search-plus',
			'autoload' => true,
			'singular' => true,
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0'],
			'installs' => ['InputfieldSeoNeoPreview'],
		];
	}

	// ────────────────────────────────────────────────────────────────────
	//  Default configuration
	// ────────────────────────────────────────────────────────────────────

	public static function getDefaultConfig(): array {
		return [
			'site_name'        => '',
			'site_name_map'    => '',
			'title_format'     => '{title}{separator}{site_name}',
			'title_separator'  => ' | ',
			'auto_inject'      => 1,
			'inject_position'  => 'bottom',
			'editor_tab_label' => 'SEO',
			'editor_tab_show_badge' => 1,
			'role_title'       => 'seoneo_title',
			'role_description' => 'seoneo_description',
			'role_canonical'   => 'seoneo_canonical',
			'role_noindex'     => 'seoneo_noindex',
			'role_nofollow'    => 'seoneo_nofollow',
			'noindex_unpublished' => 1,
			'noindex_hidden'   => 0,
			'noindex_sitewide' => 0,
			'nofollow_sitewide' => 0,
			'emit_noai'        => 0,
			'emit_noimageai'   => 0,
			'robots_max_snippet'       => '',
			'robots_max_image_preview' => '',
			'robots_max_video_preview' => '',
			'robots_unavailable_after' => '',
			'jsonld_enabled'   => 1,
			'jsonld_org_type'  => 'Organization',
			'jsonld_org_name'  => '',
			'jsonld_org_name_map' => '',
			'jsonld_org_url'   => '',
			'jsonld_org_logo'  => '',
			'jsonld_org_description' => '',
			'jsonld_org_description_map' => '',
			'jsonld_org_sameas' => '',
			'jsonld_default_author' => 0,
			'jsonld_article_templates' => '',
			'jsonld_person_templates' => 'user',
			'jsonld_breadcrumbs' => 1,
			'jsonld_pretty'    => 1,
			'smart_map_text'   => "title=headline,title\ndescription=summary,body",
			'template_defaults_text' => '',
			'custom_tags_text' => '',
			'og_image_fields'  => 'og_image,screenshot,images,image,blog_images',
			'og_image_default' => '',
			'og_image_inherit_ancestors' => 0,
			'default_og_type'  => 'website',
			'og_default_locale' => 'en_US',
			'og_locale_map'    => '',
			'hreflang_default' => 'en',
			'hreflang_map'     => '',
			'twitter_site'     => '',
			'twitter_creator'  => '',
			'verify_google'    => '',
			'verify_bing'      => '',
			'verify_yandex'    => '',
			'verify_pinterest' => '',
			'verify_facebook'  => '',
			'verify_baidu'     => '',
			'verify_homepage_only' => 1,
			'meta_author'      => '',
			'counter_title_green'  => 60,
			'counter_title_amber'  => 70,
			'counter_desc_green'   => 160,
			'counter_desc_amber'   => 180,
			'counter_title_mobile_green' => 50,
			'counter_title_mobile_amber' => 60,
			'counter_desc_mobile_green'  => 120,
			'counter_desc_mobile_amber'  => 140,
			'max_description_length' => 180,
			'hard_cap_title'       => 0,
			'hard_cap_description' => 0,
			'canonical_pagination_policy' => 'include',
			'canonical_segment_policy'    => 'include',
		];
	}

	public function __construct() {
		parent::__construct();
		foreach(self::getDefaultConfig() as $k => $v) $this->set($k, $v);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Init / Ready
	// ────────────────────────────────────────────────────────────────────

	public function init() {
		$this->addHookProperty('Page::seoneo', $this, 'hookPageSeoNeo');
		$this->addHookAfter('Modules::saveModuleConfigData', $this, 'hookAfterSaveModuleConfigData');
	}

	public function ready() {
		$this->addHookBefore('Fieldgroups::save', $this, 'hookFieldgroupSaveEnsureSeoFields');
		if($this->shouldAutoInject()) {
			$this->addHookAfter('Page::render', $this, 'hookPageRenderInject');
		}
		if($this->wire('page') && $this->wire('page')->process == 'ProcessPageEdit') {
			$this->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'hookInjectAssets');
			if((int) $this->get('hard_cap_title') > 0 || (int) $this->get('hard_cap_description') > 0) {
				$this->addHookBefore('Inputfield::render', $this, 'hookEnforceHardCap');
			}
		}
	}

	/**
	 * Apply the configured hard-cap maxlength attribute to the title and
	 * description inputs, but only inside the page edit form (so config
	 * screens elsewhere are unaffected). Off by default — most editors
	 * prefer the soft amber/red counter, which still runs underneath.
	 */
	public function hookEnforceHardCap(HookEvent $event) {
		$input = $event->object;
		if(!$input instanceof Inputfield) return;
		$name = (string) $input->attr('name');
		if($name === '' && method_exists($input, 'getAttribute')) {
			$name = (string) $input->getAttribute('name');
		}
		$titleField = (string) ($this->get('role_title') ?: 'seoneo_title');
		$descField  = (string) ($this->get('role_description') ?: 'seoneo_description');
		if($name === $titleField) {
			$cap = (int) $this->get('hard_cap_title');
			if($cap > 0) $input->attr('maxlength', $cap);
		} elseif($name === $descField) {
			$cap = (int) $this->get('hard_cap_description');
			if($cap > 0) $input->attr('maxlength', $cap);
		}
	}

	// ────────────────────────────────────────────────────────────────────
	//  Auto-complete SEO fieldset on templates
	// ────────────────────────────────────────────────────────────────────

	/**
	 * When a fieldgroup gains seoneo_tab, insert any missing SeoNeo fields in
	 * the canonical order (preview → title → … → tab_END) before the save
	 * completes. Idempotent — safe on every fieldgroup save.
	 */
	public function hookFieldgroupSaveEnsureSeoFields(HookEvent $event): void {
		if(self::$ensuringSeoFields) return;
		$fg = $event->object;
		if(!$fg instanceof Fieldgroup) return;

		self::$ensuringSeoFields = true;
		$added = $this->ensureSeoFieldsOnFieldgroup($fg);
		self::$ensuringSeoFields = false;

		if($added > 0 && $this->wire('page') && $this->wire('page')->process === 'ProcessTemplate') {
			$this->message(sprintf(
				$this->_('SeoNeo: added %d SEO field(s) to fieldgroup "%s".'),
				$added,
				$fg->name
			));
		}
	}

	/**
	 * Insert missing DEFAULT_FIELDS entries into a fieldgroup that already has
	 * seoneo_tab. Returns the number of fields added (in memory; caller saves).
	 */
	public function ensureSeoFieldsOnFieldgroup(Fieldgroup $fg): int {
		if(!$fg->hasField('seoneo_tab')) return 0;

		$fields = $this->wire('fields');
		$tabField = $fields->get('seoneo_tab');
		if(!$tabField) return 0;

		$endField = $fg->hasField('seoneo_tab_END') ? $fields->get('seoneo_tab_END') : null;
		$prev = $tabField;
		$added = 0;

		foreach(array_keys(self::DEFAULT_FIELDS) as $name) {
			if($name === 'seoneo_tab') continue;
			if($fg->hasField($name)) {
				$existing = $fields->get($name);
				if($existing) $prev = $existing;
				continue;
			}

			$field = $fields->get($name);
			if(!$field) continue;

			if($endField && $name !== 'seoneo_tab_END') {
				$fg->insertBefore($field, $endField);
			} elseif($prev) {
				$fg->insertAfter($field, $prev);
			} else {
				$fg->add($field);
			}

			if($name !== 'seoneo_tab_END' || !$endField) $prev = $field;
			$added++;
		}

		return $added;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Install / Uninstall
	// ────────────────────────────────────────────────────────────────────

	public function ___install() {
		$created = $this->createMissingFields();
		$this->ensurePreviewFieldInputfield();
		$this->ensureSeoTabLabel();
		$this->message(sprintf(
			$this->_('SeoNeo: %d field(s) created. Add seoneo_tab to your templates to enable SEO editing.'),
			$created
		));
	}

	/**
	 * Called automatically by PW when the installed version differs from the
	 * version in getModuleInfo(). Creates any DEFAULT_FIELDS entries that are
	 * missing (typically because they were added in a later release), and
	 * re-asserts the SERP-preview Inputfield wiring on any pre-existing
	 * `seoneo_preview` field — defensive against installs that pre-date the
	 * companion Inputfield being broken out into its own module.
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$created = $this->createMissingFields();
		if($created > 0) {
			$this->message(sprintf(
				$this->_('SeoNeo: %1$d new field(s) created during upgrade to %2$s. Add them to the templates that need them.'),
				$created,
				$toVersion
			));
		}

		if($this->ensurePreviewInputfieldInstalled() > 0) {
			$this->message($this->_('SeoNeo: companion module InputfieldSeoNeoPreview installed.'));
		}

		if($this->ensurePreviewFieldInputfield() > 0) {
			$this->message($this->_('SeoNeo: SERP Preview field repaired (Inputfield class re-applied).'));
		}

		if($this->ensureSeoTabLabel() > 0) {
			$this->message($this->_('SeoNeo: editor tab label synced from module config.'));
		}
	}

	/**
	 * Idempotent: walks DEFAULT_FIELDS and creates anything missing.
	 * Returns the number of fields actually created.
	 */
	protected function createMissingFields(): int {
		$fields = $this->wire('fields');
		$modules = $this->wire('modules');
		$hasLanguage = $this->wire('languages') && count($this->wire('languages')) > 0;
		$created = 0;

		foreach(self::DEFAULT_FIELDS as $name => $type) {
			if($fields->get($name)) continue;

			$f = new Field();
			$f->name = $name;

			if($name === 'seoneo_title' && $hasLanguage) {
				$type = 'FieldtypeTextLanguage';
			} elseif($name === 'seoneo_description' && $hasLanguage) {
				$type = 'FieldtypeTextareaLanguage';
			}

			if($name === 'seoneo_preview') {
				$f->type = $modules->get('FieldtypeText');
				$f->inputfieldClass = 'InputfieldSeoNeoPreview';
				$f->collapsed = Inputfield::collapsedNever;
			} elseif($name === 'seoneo_tab_END') {
				$f->type = $modules->get('FieldtypeFieldsetClose');
			} else {
				$f->type = $modules->get($type);
			}

			$f->label = ($name === 'seoneo_tab') ? $this->getEditorTabLabel() : (self::FIELD_LABELS[$name] ?? $name);
			if(isset(self::FIELD_DESCRIPTIONS[$name])) {
				$f->description = self::FIELD_DESCRIPTIONS[$name];
			}

			if($name === 'seoneo_description') {
				$f->rows = 3;
			}

			if($name === 'seoneo_og_image') {
				$f->maxFiles = 1;
				$f->extensions = 'jpg jpeg png gif webp svg';
				$f->collapsed = Inputfield::collapsedBlank;
			}

			if($name === 'seoneo_custom') {
				$f->rows = 4;
				$f->collapsed = Inputfield::collapsedBlank;
				$f->contentType = 0;
			}

			if($name === 'seoneo_tab_END') {
				$tabField = $fields->get('seoneo_tab');
				if($tabField) $f->set('field_seoneo_tab', $tabField->id);
			}

			$f->tags = 'SeoNeo';
			$f->save();
			$created++;
		}

		return $created;
	}

	/**
	 * Ensure the seoneo_tab field label matches FIELD_LABELS (e.g. after rename
	 * from "SEO" to "SeoNeo" so it does not collide with MarkupSEO's seo_tab).
	 */
	protected function ensureSeoTabLabel(): int {
		$field = $this->wire('fields')->get('seoneo_tab');
		if(!$field) return 0;

		$expected = $this->getEditorTabLabel();
		if((string) $field->label === $expected) return 0;

		$field->label = $expected;
		$field->save();
		return 1;
	}

	/**
	 * Defensive repair: re-asserts that the existing `seoneo_preview` field
	 * is wired to render via `InputfieldSeoNeoPreview` (the Google-style SERP
	 * card) rather than the default `InputfieldText` fallback.
	 *
	 * This guards against installs where `seoneo_preview` was created before
	 * the companion Inputfield existed, or where a `Modules → Refresh` left
	 * the field's `inputfieldClass` empty. Idempotent: returns 1 if a repair
	 * actually happened, 0 otherwise.
	 */
	protected function ensurePreviewFieldInputfield(): int {
		$fields = $this->wire('fields');
		$field = $fields->get('seoneo_preview');
		if(!$field) return 0;

		$needsRepair = false;
		if((string) $field->inputfieldClass !== 'InputfieldSeoNeoPreview') {
			$field->inputfieldClass = 'InputfieldSeoNeoPreview';
			$needsRepair = true;
		}
		if((int) $field->collapsed !== Inputfield::collapsedNever) {
			$field->collapsed = Inputfield::collapsedNever;
			$needsRepair = true;
		}
		$expectedLabel = self::FIELD_LABELS['seoneo_preview'];
		if((string) $field->label !== $expectedLabel) {
			$field->label = $expectedLabel;
			$needsRepair = true;
		}
		$expectedDesc = self::FIELD_DESCRIPTIONS['seoneo_preview'];
		if((string) $field->description !== $expectedDesc) {
			$field->description = $expectedDesc;
			$needsRepair = true;
		}

		if($needsRepair) {
			$field->save();
			return 1;
		}
		return 0;
	}

	/**
	 * Belt-and-braces: PW auto-installs the companion Inputfield on a fresh
	 * install of SeoNeo, but not on upgrade. If for any reason it isn't
	 * registered when we run an upgrade, install it now so the SERP card
	 * has somewhere to render from. Returns 1 if we installed it just now.
	 */
	protected function ensurePreviewInputfieldInstalled(): int {
		$modules = $this->wire('modules');
		if($modules->isInstalled('InputfieldSeoNeoPreview')) return 0;
		$modules->install('InputfieldSeoNeoPreview');
		return 1;
	}

	public function ___uninstall() {
		$fields = $this->wire('fields');
		$fieldNames = array_reverse(array_keys(self::DEFAULT_FIELDS));

		foreach($fieldNames as $name) {
			$f = $fields->get($name);
			if(!$f) continue;

			$fieldgroups = $f->getFieldgroups();
			if($fieldgroups && $fieldgroups->count()) {
				foreach($fieldgroups as $fg) {
					$fg->remove($f);
					$fg->save();
				}
			}

			$fields->delete($f);
		}

		$this->message($this->_('SeoNeo fields removed.'));
	}

	// ────────────────────────────────────────────────────────────────────
	//  Page::seoneo hook property
	// ────────────────────────────────────────────────────────────────────

	public function hookPageSeoNeo(HookEvent $event) {
		$page = $event->object;
		$event->return = new SeoNeoAccessor($page, $this);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Auto-inject
	// ────────────────────────────────────────────────────────────────────

	protected function shouldAutoInject(): bool {
		if((int) $this->auto_inject !== 1) return false;
		$page = $this->wire('page');
		if(!$page || $page->template->name === 'admin') return false;
		return true;
	}

	public function hookPageRenderInject(HookEvent $event) {
		$page = $event->object;
		if(!$page || !$page->id || $page->template->name === 'admin') return;

		$titleField = $this->get('role_title') ?: 'seoneo_title';
		if(!$page->template->hasField($titleField) && !$page->template->hasField('seoneo_tab')) return;

		$html = (string) $event->return;
		if($html === '' || stripos($html, '</head>') === false) return;
		if(strpos($html, '<!-- SeoNeo -->') !== false) return;

		$block = $this->renderHead($page);
		if($block === '') return;

		$position = strtolower((string) $this->get('inject_position'));
		if($position === 'top' && preg_match('~<head[^>]*>~i', $html)) {
			$event->return = preg_replace(
				'~(<head[^>]*>)~i',
				"$1\n" . $block,
				$html,
				1
			);
			return;
		}

		$event->return = preg_replace(
			'~</head>~i',
			$block . "\n</head>",
			$html,
			1
		);
	}

	/**
	 * Sync seoneo_tab field label when module config is saved.
	 */
	public function hookAfterSaveModuleConfigData(HookEvent $event): void {
		if($event->arguments(0) !== 'SeoNeo') return;
		$this->ensureSeoTabLabel();
	}

	/**
	 * Label for the seoneo_tab fieldset tab in the page editor.
	 */
	protected function getEditorTabLabel(): string {
		$label = trim((string) $this->get('editor_tab_label'));
		return $label !== '' ? $label : 'SEO';
	}

	/**
	 * Whether to append a small "NEO" badge on the Wire tab (page editor only).
	 */
	protected function getEditorTabShowBadge(): bool {
		return (int) $this->get('editor_tab_show_badge') > 0;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Asset injection for page editor
	// ────────────────────────────────────────────────────────────────────

	public function hookInjectAssets(HookEvent $event) {
		$config = $this->wire('config');
		$url = $config->urls($this->className()) ?: $config->urls->siteModules . 'SeoNeo/';
		// Read the version from the modules manager rather than the static
		// class method so the asset cache-buster stays in sync with
		// SeoNeo.info.json after a release (the static method's hardcoded
		// version can silently drift if a release forgets to update both).
		$info = $this->wire('modules')->getModuleInfo($this->className());
		$v = $info['version'] ?? '1.0.0';
		$config->styles->add($url . "assets/SeoNeo.css?v=$v");
		$config->scripts->add($url . "assets/SeoNeo.js?v=$v");

		$pageUrl = '';
		$process = $this->wire('process');
		if($process && $process instanceof ProcessPageEdit) {
			$editPage = $process->getPage();
			if($editPage && $editPage->id) {
				$pageUrl = (string) $editPage->httpUrl;
			}
		}

		$canonicalField = $this->get('role_canonical') ?: 'seoneo_canonical';

		$languages = $this->wire('languages');
		$multilang = $languages && count($languages) > 1;
		$defaultLanguageId = 0;
		if($multilang) {
			$defaultLang = $languages->getDefault();
			if($defaultLang) $defaultLanguageId = (int) $defaultLang->id;
		}

		$jsConfig = [
			'roleTitle'       => $this->get('role_title') ?: 'seoneo_title',
			'roleDescription' => $this->get('role_description') ?: 'seoneo_description',
			'roleCanonical'   => $canonicalField,
			'pageUrl'         => $pageUrl,
			'siteName'        => (string) $this->get('site_name'),
			'titleSeparator'  => (string) $this->get('title_separator'),
			'counterTitleGreen'  => (int) $this->counter_title_green,
			'counterTitleAmber'  => (int) $this->counter_title_amber,
			'counterDescGreen'   => (int) $this->counter_desc_green,
			'counterDescAmber'   => (int) $this->counter_desc_amber,
			'counterTitleMobileGreen' => (int) $this->counter_title_mobile_green,
			'counterTitleMobileAmber' => (int) $this->counter_title_mobile_amber,
			'counterDescMobileGreen'  => (int) $this->counter_desc_mobile_green,
			'counterDescMobileAmber'  => (int) $this->counter_desc_mobile_amber,
			'multilang'       => $multilang,
			'defaultLanguageId' => $defaultLanguageId,
			'showTabBadge'    => $this->getEditorTabShowBadge(),
		];
		$config->js('SeoNeo', $jsConfig);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Hookable resolvers
	// ────────────────────────────────────────────────────────────────────

	public function getMeta(?Page $page, string $key): string {
		if(!$page || !$page->id) return '';
		return match($key) {
			'title'       => $this->getTitle($page),
			'description' => $this->getDescription($page),
			'canonical'   => $this->getCanonical($page),
			'robots'      => $this->getRobots($page),
			default       => '',
		};
	}

	public function ___getTitle(Page $page): string {
		$field = $this->get('role_title') ?: 'seoneo_title';
		$raw = $this->readField($page, $field);

		if($raw === '') $raw = $this->resolveSmartMap($page, 'title');
		if($raw === '') $raw = $this->renderTemplateDefault($page, 'title');
		if($raw === '') $raw = (string) $page->title;
		if($raw === '') return '';

		return $this->formatTitle($raw);
	}

	public function ___getDescription(Page $page): string {
		$field = $this->get('role_description') ?: 'seoneo_description';
		$raw = $this->readField($page, $field);
		if($raw !== '') return $raw;

		$raw = $this->resolveSmartMap($page, 'description');
		if($raw === '') $raw = $this->renderTemplateDefault($page, 'description');
		return $raw === '' ? '' : $this->truncateDescription($raw);
	}

	/**
	 * Trim an auto-resolved description down to a sensible meta-tag length.
	 * Strips HTML, collapses whitespace, then cuts at the last word boundary
	 * before max_description_length and appends an ellipsis.
	 *
	 * Only called on smart-mapped and template-default values — values typed
	 * explicitly into seoneo_description are returned verbatim.
	 */
	protected function truncateDescription(string $text): string {
		$text = preg_replace('/\s+/u', ' ', trim(strip_tags($text)));
		$limit = (int) $this->get('max_description_length');
		if($limit <= 0) return $text;
		if(mb_strlen($text, 'UTF-8') <= $limit) return $text;

		$truncated = mb_substr($text, 0, $limit, 'UTF-8');
		$lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
		if($lastSpace !== false && $lastSpace > $limit / 2) {
			$truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
		}
		return rtrim($truncated, " \t\n\r\0\x0B,;:.") . '…';
	}

	public function ___getCanonical(Page $page): string {
		$field = $this->get('role_canonical') ?: 'seoneo_canonical';
		$raw = $this->readField($page, $field);
		if($raw !== '') return $this->absolutiseCanonical($raw, $page);
		return $this->applyCanonicalPolicies((string) $page->httpUrl);
	}

	/**
	 * Apply the configured pagination + URL-segment policies to a fallback
	 * canonical (i.e. one that came from $page->httpUrl, not from the editor).
	 *
	 * - canonical_pagination_policy = 'include' → keep `/page2/`
	 * - canonical_pagination_policy = 'collapse' → strip pagination, point at page 1
	 *
	 * - canonical_segment_policy    = 'include' → keep `/2024/article-slug/`
	 * - canonical_segment_policy    = 'collapse' → strip segments, point at parent
	 *
	 * Defaults are 'include' for both, which matches Google's modern
	 * "each indexable URL is its own canonical" guidance.
	 */
	protected function applyCanonicalPolicies(string $base): string {
		$base = rtrim($base, '/');
		if($base === '') return '/';

		$input = $this->wire('input');
		if(!$input) return $base . '/';

		$pagePolicy = (string) $this->get('canonical_pagination_policy') ?: 'include';
		$segmentPolicy = (string) $this->get('canonical_segment_policy') ?: 'include';

		$pageNum = max(1, (int) $input->pageNum());
		$segmentStr = method_exists($input, 'urlSegmentStr')
			? trim((string) $input->urlSegmentStr(), '/')
			: '';

		if($segmentPolicy === 'include' && $segmentStr !== '') {
			$base .= '/' . $segmentStr;
		}

		if($pagePolicy === 'include' && $pageNum > 1) {
			$prefix = $this->pageNumUrlPrefix($this->wire('user')->language ?? null);
			$base .= '/' . $prefix . $pageNum;
		}

		return $base . '/';
	}

	/**
	 * Promote a user-entered canonical to an absolute URL.
	 *
	 * Accepts:
	 *   - "https://example.com/foo/" (absolute, any scheme) → returned as-is
	 *   - "//example.com/foo/"       (protocol-relative)    → scheme prepended
	 *   - "/about-us/"               (root-relative)        → scheme + host prepended
	 *   - "about-us/"                (bare path)            → scheme + host + "/" prepended
	 *
	 * The host is derived from $page->httpUrl rather than $config->httpHost
	 * so multi-domain language setups and per-template HTTPS settings are
	 * respected automatically.
	 */
	protected function absolutiseCanonical(string $raw, Page $page): string {
		if(preg_match('#^[a-z][a-z0-9+\-.]*:#i', $raw)) return $raw;
		if(strncmp($raw, '//', 2) === 0) {
			$scheme = parse_url((string) $page->httpUrl, PHP_URL_SCHEME) ?: 'https';
			return $scheme . ':' . $raw;
		}

		$base = $this->siteBaseUrl($page);
		if($raw === '') return $base . '/';
		if($raw[0] === '/') return $base . $raw;
		return $base . '/' . $raw;
	}

	/**
	 * Scheme + host (+ non-default port) derived from the page's own URL.
	 */
	protected function siteBaseUrl(Page $page): string {
		$parts = parse_url((string) $page->httpUrl);
		if(empty($parts['scheme']) || empty($parts['host'])) {
			return rtrim((string) $this->wire('config')->urls->httpRoot, '/');
		}
		$base = $parts['scheme'] . '://' . $parts['host'];
		if(!empty($parts['port'])) {
			$port = (int) $parts['port'];
			$default = ($parts['scheme'] === 'https') ? 443 : 80;
			if($port !== $default) $base .= ':' . $port;
		}
		return $base;
	}

	public function ___getRobots(Page $page): string {
		$noindexField = $this->get('role_noindex') ?: 'seoneo_noindex';
		$nofollowField = $this->get('role_nofollow') ?: 'seoneo_nofollow';

		$noindex = $page->template->hasField($noindexField) ? (int) $page->get($noindexField) : 0;
		$nofollow = $page->template->hasField($nofollowField) ? (int) $page->get($nofollowField) : 0;

		if(!$noindex && (int) $this->get('noindex_unpublished') && method_exists($page, 'isUnpublished') && $page->isUnpublished()) {
			$noindex = 1;
		}
		if(!$noindex && (int) $this->get('noindex_hidden') && method_exists($page, 'isHidden') && $page->isHidden()) {
			$noindex = 1;
		}

		if(!$noindex && (int) $this->get('noindex_sitewide')) {
			$noindex = 1;
		}
		if(!$nofollow && (int) $this->get('nofollow_sitewide')) {
			$nofollow = 1;
		}

		return ($noindex ? 'noindex' : 'index') . ',' . ($nofollow ? 'nofollow' : 'follow');
	}

	/**
	 * Resolved AI / LLM-specific directives for the supplied page.
	 *
	 * Returns an array of directive strings that should be appended to the
	 * `<meta name="robots">` tag content alongside the standard index /
	 * follow values. Currently supports:
	 *
	 *   - `noai`      — opt out of generative-AI training (DeviantArt-style spec
	 *                   honoured by some AI crawlers; not a substitute for
	 *                   blocking GPTBot / ClaudeBot etc. at the robots.txt
	 *                   or HTTP level).
	 *   - `noimageai` — opt images out of AI training datasets.
	 *
	 * Site-wide for v1 (per-page override may follow if anyone asks). Both
	 * default to off so existing render output is unchanged unless an
	 * editor explicitly enables them. Hookable: sites that compute AI
	 * directives per-page or per-template can override return values.
	 */
	public function ___getAiDirectives(Page $page): array {
		$out = [];
		if((int) $this->get('emit_noai')) $out[] = 'noai';
		if((int) $this->get('emit_noimageai')) $out[] = 'noimageai';
		return $out;
	}

	/**
	 * Resolved granular Google robots directives for the supplied page.
	 *
	 * Returns an associative array of directive => value pairs that should
	 * be appended to the `<meta name="robots">` tag content as `key:value`
	 * fragments. Supports:
	 *
	 *   - `max-snippet`        — int. -1 = no limit, 0 = no snippet,
	 *                            positive = character cap. Google.
	 *   - `max-image-preview`  — enum: `none`, `standard`, `large`. Google.
	 *   - `max-video-preview`  — int. -1 = no limit, 0 = no video,
	 *                            positive = seconds. Google.
	 *   - `unavailable_after`  — string. RFC 850 or ISO 8601 datetime
	 *                            ("25-Aug-2026 15:00:00 PST" or
	 *                            "2026-08-25T15:00:00Z"). Tells Google
	 *                            to drop the page from the index after
	 *                            the supplied moment. Google.
	 *
	 * All four are site-wide config for v1. Per-page overrides may follow
	 * if there's demand — most sites set these once and forget them.
	 * Hookable, so sites that compute per-template or per-page values can
	 * override.
	 *
	 * Returns an empty array when nothing is configured, so the omit-
	 * when-default branch in getRobotsLines() still works as expected.
	 */
	public function ___getRobotsDirectives(Page $page): array {
		$out = [];

		$maxSnippet = trim((string) $this->get('robots_max_snippet'));
		if($maxSnippet !== '' && is_numeric($maxSnippet)) {
			$out['max-snippet'] = (string) (int) $maxSnippet;
		}

		$maxImagePreview = trim((string) $this->get('robots_max_image_preview'));
		if($maxImagePreview !== '' && in_array($maxImagePreview, ['none', 'standard', 'large'], true)) {
			$out['max-image-preview'] = $maxImagePreview;
		}

		$maxVideoPreview = trim((string) $this->get('robots_max_video_preview'));
		if($maxVideoPreview !== '' && is_numeric($maxVideoPreview)) {
			$out['max-video-preview'] = (string) (int) $maxVideoPreview;
		}

		$unavailableAfter = trim((string) $this->get('robots_unavailable_after'));
		if($unavailableAfter !== '') {
			$out['unavailable_after'] = $unavailableAfter;
		}

		return $out;
	}

	/**
	 * Resolved keywords string for the supplied page.
	 *
	 * Returns the trimmed value of the page's `seoneo_keywords` field, or
	 * an empty string if the template does not declare the field or the
	 * value is blank. Hookable so sites can compute keywords from other
	 * sources (tags, categories, etc.).
	 */
	public function ___getKeywords(Page $page): string {
		if(!$page->template->hasField('seoneo_keywords')) return '';
		return trim((string) $page->get('seoneo_keywords'));
	}

	/**
	 * Resolved keywords as an array of individual terms.
	 *
	 * Splits the comma-separated `seoneo_keywords` string into trimmed
	 * non-empty terms, e.g. "hiking, lake district, cumbria" →
	 * ['hiking', 'lake district', 'cumbria']. Empty array when nothing
	 * is configured. Hookable for sites that want to source keywords
	 * from page tags, categories, or any other field.
	 */
	public function ___getKeywordsList(Page $page): array {
		$raw = $this->getKeywords($page);
		if($raw === '') return [];
		$parts = preg_split('/\s*,\s*/', $raw);
		return array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));
	}

	// ────────────────────────────────────────────────────────────────────
	//  Open Graph
	// ────────────────────────────────────────────────────────────────────

	public function ___getOgTitle(Page $page): string {
		$field = $this->get('role_title') ?: 'seoneo_title';
		$raw = $this->readField($page, $field);
		if($raw === '') $raw = $this->resolveSmartMap($page, 'title');
		if($raw === '') $raw = $this->renderTemplateDefault($page, 'title');
		if($raw === '') $raw = (string) $page->title;
		return $raw;
	}

	public function ___getOgImage(Page $page): string {
		$img = $this->resolveOgImagePageimage($page);
		if($img) return (string) $img->httpUrl;
		return (string) $this->get('og_image_default');
	}

	/**
	 * Open Graph object type (og:type).
	 *
	 * Resolution order:
	 *   1. Per-page seoneo_og_type field (if present and non-empty)
	 *   2. Per-template default from template_defaults_text (key: og_type)
	 *   3. Site-wide default from module config (default_og_type)
	 *   4. Hard-coded fallback: "website"
	 */
	public function ___getOgType(Page $page): string {
		if($page->template->hasField('seoneo_og_type')) {
			$val = trim((string) $page->get('seoneo_og_type'));
			if($val !== '') return $val;
		}
		$tplDefault = $this->renderTemplateDefault($page, 'og_type');
		if($tplDefault !== '') return $tplDefault;
		$siteDefault = trim((string) $this->get('default_og_type'));
		return $siteDefault !== '' ? $siteDefault : 'website';
	}

	/**
	 * Resolved article authors for the supplied page.
	 *
	 * Used to emit `<meta property="article:author">` tags when og:type
	 * is `article`. Defaults to the per-author array form of getAuthors()
	 * — same multi-author splitting rules — but is independently hookable
	 * so sites can compute article authors from a different source
	 * (Page-reference field, byline relationship, contributor table, etc.)
	 * without affecting the plain `<meta name="author">` resolution.
	 *
	 * The emission itself is gated by og:type === "article" in getOgLines()
	 * so non-article pages don't get spurious tags; the accessor will
	 * always return the resolved values regardless of og:type.
	 */
	public function ___getArticleAuthors(Page $page): array {
		return $this->getAuthors($page);
	}

	/**
	 * Resolved article published time (ISO 8601) for the supplied page.
	 *
	 * Used to emit `<meta property="article:published_time">` when
	 * og:type is `article`. Defaults to `$page->created` formatted as
	 * ISO 8601 (`2026-05-15T09:42:00+01:00`). Hookable, so sites with a
	 * dedicated publishing date field can override.
	 *
	 * Returns an empty string for unsaved / virtual pages so the tag
	 * is skipped rather than emitting a nonsensical 1970-01-01 date.
	 */
	public function ___getArticlePublishedTime(Page $page): string {
		$ts = (int) $page->created;
		if($ts <= 0) return '';
		return date('c', $ts);
	}

	/**
	 * Resolved article modified time (ISO 8601) for the supplied page.
	 *
	 * Used to emit `<meta property="article:modified_time">` when
	 * og:type is `article`. Defaults to `$page->modified` formatted as
	 * ISO 8601. Hookable. Returns '' for pages that have never been
	 * modified (modified == 0).
	 */
	public function ___getArticleModifiedTime(Page $page): string {
		$ts = (int) $page->modified;
		if($ts <= 0) return '';
		return date('c', $ts);
	}

	/**
	 * The site name for the current (or supplied) language.
	 *
	 * Order:
	 *   1. Per-language entry in `site_name_map` (e.g. `de=Mein Beispiel`).
	 *   2. Site-wide `site_name` config.
	 *
	 * Hookable so sites that store their site name on a Settings page can
	 * return it from there instead.
	 */
	public function ___getSiteName($lang = null): string {
		$map = $this->getSiteNameMap();
		if($map) {
			$langName = $this->resolveLanguageName($lang);
			if($langName !== '' && isset($map[$langName])) return (string) $map[$langName];
		}
		return (string) $this->get('site_name');
	}

	protected function getSiteNameMap(): array {
		$text = (string) $this->get('site_name_map');
		if($text === '') return [];
		$map = [];
		foreach(preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
			[$k, $v] = explode('=', $line, 2);
			$k = trim($k);
			$v = trim($v);
			if($k !== '' && $v !== '') $map[$k] = $v;
		}
		return $map;
	}

	/**
	 * Open Graph locale (og:locale) for the given language.
	 * Looks up the language in the configured og_locale_map first; falls back
	 * to deriving a value from the language's own properties (locale string,
	 * language name); finally falls back to the site-wide default.
	 *
	 * @param Page $page Current page (unused today, but provided so a hook
	 *                   can return a per-page override).
	 * @param Language|null $lang If null, uses the current request language.
	 */
	public function ___getOgLocale(Page $page, $lang = null): string {
		$map = $this->getOgLocaleMap();
		$langName = $this->resolveLanguageName($lang);

		if($langName !== '' && isset($map[$langName])) return $map[$langName];

		$derived = $this->deriveLocaleFromLanguage($lang);
		if($derived !== '') return $derived;

		return (string) ($this->get('og_default_locale') ?: 'en_US');
	}

	/**
	 * Returns og:locale:alternate values for every active language *other*
	 * than the one currently being rendered. Empty array on single-language sites.
	 */
	public function ___getOgLocaleAlternates(Page $page): array {
		$languages = $this->wire('languages');
		if(!$languages || count($languages) < 2) return [];

		$current = $this->wire('user')->language;
		$out = [];
		foreach($languages as $lang) {
			if($current && $lang->id === $current->id) continue;
			$loc = $this->getOgLocale($page, $lang);
			if($loc !== '' && !in_array($loc, $out, true)) $out[] = $loc;
		}
		return $out;
	}

	protected function getOgLocaleMap(): array {
		return $this->parseLanguageMap((string) $this->get('og_locale_map'));
	}

	/**
	 * BCP47 hreflang code (e.g. `en`, `de`, `en-GB`) for the given language.
	 *
	 * ProcessWire stores the default language under the system-locked name
	 * `default`, which is *not* a valid BCP47 tag. This method maps PW's
	 * internal language names to the codes search engines actually expect.
	 *
	 * Resolution order:
	 *
	 * 1. `hreflang_map` config entry for the language's name (highest precedence).
	 * 2. If the name is `default`, the `hreflang_default` config value (defaults to `en`).
	 * 3. Otherwise the language's own name, sanitised.
	 *
	 * The method is hookable so downstream sites can override per-language
	 * without forking the module — e.g. `$wire->addHookAfter('SeoNeo::getHreflangCode', ...)`.
	 *
	 * @param Language|null $lang If null, uses the current request language.
	 */
	public function ___getHreflangCode($lang = null): string {
		$name = $this->resolveLanguageName($lang);
		$map = $this->getHreflangMap();
		if($name !== '' && isset($map[$name])) return $map[$name];
		if($name === 'default' || $name === '') {
			return (string) ($this->get('hreflang_default') ?: 'en');
		}
		return (string) $this->wire('sanitizer')->name($name);
	}

	protected function getHreflangMap(): array {
		return $this->parseLanguageMap((string) $this->get('hreflang_map'));
	}

	/**
	 * Parse a textarea-style `key=value` map into a PHP array. Lines that are
	 * blank, comment-only (`#`), or missing the `=` separator are skipped.
	 * Shared by `getOgLocaleMap()` and `getHreflangMap()`.
	 */
	protected function parseLanguageMap(string $text): array {
		if($text === '') return [];
		$map = [];
		foreach(preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
			[$k, $v] = explode('=', $line, 2);
			$k = trim($k);
			$v = trim($v);
			if($k !== '' && $v !== '') $map[$k] = $v;
		}
		return $map;
	}

	protected function resolveLanguageName($lang): string {
		if($lang && property_exists($lang, 'name')) return (string) $lang->name;
		if($lang && method_exists($lang, 'get')) return (string) $lang->get('name');
		$user = $this->wire('user');
		if($user && $user->language) return (string) $user->language->name;
		return '';
	}

	/**
	 * Best-effort locale derivation when no explicit mapping is configured.
	 * Reads the language's title field if it looks like a locale code (de_AT),
	 * otherwise generates `xx_XX` from the language name slug.
	 */
	protected function deriveLocaleFromLanguage($lang): string {
		if(!$lang) return '';
		$title = '';
		if(method_exists($lang, 'get')) $title = (string) $lang->get('title');
		if(preg_match('/^([a-z]{2,3})[_-]([A-Z]{2})$/', trim($title), $m)) {
			return $m[1] . '_' . $m[2];
		}
		$name = $this->resolveLanguageName($lang);
		if($name === '' || $name === 'default') return '';
		$base = strtolower(preg_replace('/[^a-z]/i', '', $name));
		if(strlen($base) < 2 || strlen($base) > 3) return '';
		return $base . '_' . strtoupper($base);
	}

	/**
	 * Full Open Graph image metadata: url, width, height, secure_url, type.
	 *
	 * Returns an associative array suitable for emitting the og:image tag set:
	 *   ['url' => ..., 'width' => 1200, 'height' => 630,
	 *    'secure_url' => 'https://...', 'type' => 'image/jpeg']
	 *
	 * Width and height are only present when the resolved image is a real
	 * Pageimage on disk (cases 1-3). For the module-config URL fallback the
	 * dimensions are not knowable without fetching the file, so only url,
	 * secure_url (if HTTPS), and type (if extension is recognised) are filled.
	 *
	 * Returns an empty array if no image is configured.
	 */
	public function ___getOgImageData(Page $page): array {
		$img = $this->resolveOgImagePageimage($page);
		if($img) {
			$url    = (string) $img->httpUrl;
			$width  = (int) $img->width;
			$height = (int) $img->height;
			$type   = $this->ogImageMimeType((string) $img->ext);
			$data = ['url' => $url];
			if($width > 0)  $data['width']  = $width;
			if($height > 0) $data['height'] = $height;
			if(strncmp($url, 'https://', 8) === 0) $data['secure_url'] = $url;
			if($type !== '') $data['type'] = $type;
			return $data;
		}

		$url = (string) $this->get('og_image_default');
		if($url === '') return [];

		$data = ['url' => $url];
		if(strncmp($url, 'https://', 8) === 0) $data['secure_url'] = $url;
		$type = $this->ogImageMimeType($url);
		if($type !== '') $data['type'] = $type;
		return $data;
	}

	/**
	 * Walk the OG-image fallback chain and return the first matching Pageimage.
	 * Shared by getOgImage() and getOgImageData() so the resolution rules
	 * stay in one place.
	 */
	protected function resolveOgImagePageimage(Page $page): ?Pageimage {
		$fieldNames = array_map('trim', explode(',', (string) $this->get('og_image_fields')));

		$img = $this->ogImageFromPage($page, $fieldNames);
		if($img) return $img;

		if((int) $this->get('og_image_inherit_ancestors')) {
			foreach($page->parents() as $ancestor) {
				if(!$ancestor->id || $ancestor->id === 1) continue;
				$img = $this->ogImageFromPage($ancestor, $fieldNames);
				if($img) return $img;
			}
		}

		$homepage = $this->wire('pages')->get(1);
		if($homepage && $homepage->id && $homepage->id !== $page->id && $homepage->template->hasField('seoneo_og_image')) {
			$img = $this->firstPageimage($homepage->get('seoneo_og_image'));
			if($img) return $img;
		}

		return null;
	}

	/**
	 * Look for an OG image on a single page: the explicit seoneo_og_image
	 * field first, then each entry in the configured smart-map field list.
	 */
	protected function ogImageFromPage(Page $page, array $fieldNames): ?Pageimage {
		if($page->template->hasField('seoneo_og_image')) {
			$img = $this->firstPageimage($page->get('seoneo_og_image'));
			if($img) return $img;
		}
		foreach($fieldNames as $name) {
			if($name === '') continue;

			$val = str_contains($name, '.')
				? $this->getDeep($page, $name)
				: ($page->template->hasField($name) ? $page->get($name) : null);

			$img = $this->firstPageimage($val);
			if($img) return $img;
		}
		return null;
	}

	protected function firstPageimage($val): ?Pageimage {
		if($val instanceof Pageimage) return $val;
		if($val instanceof Pageimages && $val->count()) return $val->first();
		return null;
	}

	/**
	 * Map a file extension or URL to its IANA media type.
	 * Returns an empty string for unknown extensions so the caller can skip
	 * emitting og:image:type rather than guessing.
	 */
	protected function ogImageMimeType(string $extOrUrl): string {
		static $map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
		];
		$ext = $extOrUrl;
		if(str_contains($extOrUrl, '/') || str_contains($extOrUrl, '.')) {
			$path = parse_url($extOrUrl, PHP_URL_PATH) ?: $extOrUrl;
			$ext = pathinfo($path, PATHINFO_EXTENSION);
		}
		return $map[strtolower($ext)] ?? '';
	}

	/**
	 * Twitter site handle (the @username for the site as a whole).
	 * Read from the module config and normalised to start with @.
	 */
	public function ___getTwitterSite(Page $page): string {
		return $this->normaliseTwitterHandle((string) $this->get('twitter_site'));
	}

	/**
	 * Twitter creator handle (the @username for the content author).
	 * Defaults to the module config; hookable so editors can return a
	 * per-page or per-author value (e.g. from a User profile field).
	 */
	public function ___getTwitterCreator(Page $page): string {
		return $this->normaliseTwitterHandle((string) $this->get('twitter_creator'));
	}

	protected function normaliseTwitterHandle(string $handle): string {
		$handle = trim($handle);
		if($handle === '') return '';
		return $handle[0] === '@' ? $handle : '@' . $handle;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Rendering
	// ────────────────────────────────────────────────────────────────────

	public function ___renderTitle(Page $page): string {
		$title = $this->getTitle($page);
		if($title === '') return '';
		return '<title>' . $this->esc($title) . '</title>';
	}

	// ────────────────────────────────────────────────────────────────────
	//  JSON-LD (structured data)
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Build the JSON-LD `@graph` for the given page. The graph contains a
	 * site-wide Organization (or Person) node, a WebSite node, a WebPage
	 * node, and any per-page additions (Article, Person, BreadcrumbList).
	 *
	 * Hookable so downstream sites can add nodes, modify properties, or
	 * remove nodes by manipulating the returned array.
	 *
	 * Returns an empty array when JSON-LD is disabled in config — the
	 * `renderJsonLd()` caller then skips the script tag entirely.
	 *
	 * @return array `['@context' => 'https://schema.org', '@graph' => [...]]`
	 */
	public function ___getJsonLd(Page $page): array {
		if(!(int) $this->get('jsonld_enabled')) return [];

		$nodes = [];

		$org = $this->buildJsonLdOrganization();
		if($org) $nodes[] = $org;

		$website = $this->buildJsonLdWebSite();
		if($website) $nodes[] = $website;

		$webpage = $this->buildJsonLdWebPage($page);
		if($webpage) $nodes[] = $webpage;

		$type = $this->detectJsonLdPageType($page);
		if($type === 'Article') {
			$article = $this->buildJsonLdArticle($page);
			if($article) $nodes[] = $article;
		} elseif($type === 'Person') {
			$person = $this->buildJsonLdPerson($page);
			if($person) $nodes[] = $person;
		}

		if((int) $this->get('jsonld_breadcrumbs')) {
			$breadcrumb = $this->buildJsonLdBreadcrumbs($page);
			if($breadcrumb) $nodes[] = $breadcrumb;
		}

		return ['@context' => 'https://schema.org', '@graph' => $nodes];
	}

	/**
	 * Render the JSON-LD script tag for the given page. Returns empty
	 * string when JSON-LD is disabled or the graph is empty.
	 */
	public function ___renderJsonLd(Page $page): string {
		$data = $this->getJsonLd($page);
		if(!is_array($data) || empty($data['@graph'])) return '';
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if((int) $this->get('jsonld_pretty')) $flags |= JSON_PRETTY_PRINT;
		$json = json_encode($data, $flags);
		if($json === false) return '';
		return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
	}

	protected function buildJsonLdOrganization(): ?array {
		$lang = $this->wire('user') ? $this->wire('user')->language : null;

		// Name: per-language map > explicit single-text override > site_name (itself
		// language-aware via site_name_map) > empty.
		$name = $this->resolveLocalizedJsonLdValue(
			'jsonld_org_name_map',
			(string) $this->get('jsonld_org_name'),
			$lang
		);
		if($name === '') $name = (string) $this->getSiteName($lang);

		$url  = (string) ($this->get('jsonld_org_url')  ?: $this->wire('config')->urls->httpRoot);
		if($name === '' || $url === '') return null;

		$type = (string) ($this->get('jsonld_org_type') ?: 'Organization');
		$type = preg_match('/^[A-Za-z]+$/', $type) ? $type : 'Organization';

		$node = [
			'@type' => $type,
			'@id'   => $this->jsonLdOrgId(),
			'name'  => $name,
			'url'   => rtrim($url, '/') . '/',
		];

		// Description: same resolution order (per-language map first).
		$desc = $this->resolveLocalizedJsonLdValue(
			'jsonld_org_description_map',
			(string) $this->get('jsonld_org_description'),
			$lang
		);
		if($desc !== '') $node['description'] = $desc;

		$logo = trim((string) $this->get('jsonld_org_logo'));
		if($logo !== '') {
			$node['logo'] = [
				'@type'      => 'ImageObject',
				'url'        => $logo,
				'contentUrl' => $logo,
			];
		}

		$sameAs = $this->parseJsonLdLines((string) $this->get('jsonld_org_sameas'));
		if($sameAs) $node['sameAs'] = $sameAs;

		return $node;
	}

	protected function buildJsonLdWebSite(): ?array {
		$lang = $this->wire('user') ? $this->wire('user')->language : null;
		$name = $this->resolveLocalizedJsonLdValue(
			'jsonld_org_name_map',
			(string) $this->get('jsonld_org_name'),
			$lang
		);
		if($name === '') $name = (string) $this->getSiteName($lang);

		$url  = (string) ($this->get('jsonld_org_url')  ?: $this->wire('config')->urls->httpRoot);
		if($url === '') return null;

		$node = [
			'@type' => 'WebSite',
			'@id'   => $this->jsonLdWebSiteId(),
			'url'   => rtrim($url, '/') . '/',
		];
		if($name !== '') $node['name'] = $name;

		$orgId = $this->jsonLdOrgId();
		if($orgId !== '') $node['publisher'] = ['@id' => $orgId];

		// Language: use the default language code, sites often serve more
		// than one language but @id-deduplication keeps the WebSite singular
		$default = $this->wire('languages') && method_exists($this->wire('languages'), 'getDefault')
			? $this->wire('languages')->getDefault()
			: null;
		$inLang = $this->getHreflangCode($default);
		if($inLang !== '') $node['inLanguage'] = $inLang;

		return $node;
	}

	protected function buildJsonLdWebPage(Page $page): ?array {
		$url = $this->getCanonical($page);
		if($url === '') return null;

		$node = [
			'@type' => 'WebPage',
			'@id'   => $this->jsonLdWebPageId($page),
			'url'   => $url,
			'name'  => $this->getTitle($page),
		];

		$desc = $this->getDescription($page);
		if($desc !== '') $node['description'] = $desc;

		$lang = $this->wire('user')->language;
		$inLang = $this->getHreflangCode($lang);
		if($inLang !== '') $node['inLanguage'] = $inLang;

		$websiteId = $this->jsonLdWebSiteId();
		if($websiteId !== '') $node['isPartOf'] = ['@id' => $websiteId];

		$ogImage = $this->getOgImage($page);
		if($ogImage !== '') {
			$node['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $ogImage];
		}

		if(method_exists($page, 'getModified') || isset($page->modified)) {
			$mod = (int) $page->modified;
			if($mod > 0) $node['dateModified'] = date('c', $mod);
		}
		if(isset($page->created)) {
			$created = (int) $page->created;
			if($created > 0) $node['datePublished'] = date('c', $created);
		}

		return $node;
	}

	protected function buildJsonLdArticle(Page $page): ?array {
		$url = $this->getCanonical($page);
		if($url === '') return null;

		$node = [
			'@type' => 'Article',
			'@id'   => $url . '#article',
			'headline'  => $this->getTitle($page),
			'mainEntityOfPage' => ['@id' => $this->jsonLdWebPageId($page)],
		];

		$desc = $this->getDescription($page);
		if($desc !== '') $node['description'] = $desc;

		$ogImage = $this->getOgImage($page);
		if($ogImage !== '') $node['image'] = $ogImage;

		if(isset($page->created) && (int) $page->created > 0) {
			$node['datePublished'] = date('c', (int) $page->created);
		}
		if(isset($page->modified) && (int) $page->modified > 0) {
			$node['dateModified'] = date('c', (int) $page->modified);
		}

		$author = $this->resolveArticleAuthor($page);
		if($author) $node['author'] = $author;

		$orgId = $this->jsonLdOrgId();
		if($orgId !== '') $node['publisher'] = ['@id' => $orgId];

		$lang = $this->wire('user')->language;
		$inLang = $this->getHreflangCode($lang);
		if($inLang !== '') $node['inLanguage'] = $inLang;

		return $node;
	}

	protected function buildJsonLdPerson(Page $page): ?array {
		$name = trim((string) $page->title);
		if($name === '') $name = trim((string) $page->name);
		if($name === '') return null;

		$node = [
			'@type' => 'Person',
			'@id'   => $this->jsonLdPersonId($page),
			'name'  => $name,
			'url'   => $this->getCanonical($page),
		];

		// Image: try a handful of common avatar field names
		$avatar = $this->resolvePersonImage($page);
		if($avatar !== '') $node['image'] = $avatar;

		$desc = $this->getDescription($page);
		if($desc !== '') $node['description'] = $desc;

		return $node;
	}

	protected function buildJsonLdBreadcrumbs(Page $page): ?array {
		$home = $this->wire('pages')->get('/');
		if(!$home || !$home->id) return null;

		$crumbs = [];
		$parents = $page->parents();
		$pos = 1;
		foreach($parents as $p) {
			if(!$p || !$p->id) continue;
			$crumbs[] = [
				'@type' => 'ListItem',
				'position' => $pos++,
				'name' => (string) $p->title,
				'item' => (string) $p->httpUrl,
			];
		}
		// Add the page itself as the final crumb
		$crumbs[] = [
			'@type' => 'ListItem',
			'position' => $pos,
			'name' => (string) $page->title,
			'item' => $this->getCanonical($page),
		];

		// Skip if only the page itself (no real breadcrumb trail)
		if(count($crumbs) < 2) return null;

		return [
			'@type' => 'BreadcrumbList',
			'@id'   => $this->getCanonical($page) . '#breadcrumb',
			'itemListElement' => $crumbs,
		];
	}

	protected function detectJsonLdPageType(Page $page): string {
		$tpl = $page->template->name;

		$personTemplates = $this->parseJsonLdLines((string) $this->get('jsonld_person_templates'), ',');
		if(in_array($tpl, $personTemplates, true)) return 'Person';

		$articleTemplates = $this->parseJsonLdLines((string) $this->get('jsonld_article_templates'), ',');
		if(in_array($tpl, $articleTemplates, true)) return 'Article';

		return 'WebPage';
	}

	protected function resolveArticleAuthor(Page $page): ?array {
		// Try `author` field on the page (Page reference)
		if($page->template->hasField('author')) {
			$author = $page->get('author');
			if($author && is_object($author) && $author->id) {
				$node = $this->buildJsonLdPerson($author);
				if($node) return $node;
			}
		}
		// Fall back to default author config (Page ID)
		$defaultId = (int) $this->get('jsonld_default_author');
		if($defaultId > 0) {
			$author = $this->wire('pages')->get($defaultId);
			if($author && $author->id) {
				$node = $this->buildJsonLdPerson($author);
				if($node) return $node;
			}
		}
		return null;
	}

	protected function resolvePersonImage(Page $page): string {
		$candidates = ['avatar', 'photo', 'image', 'portrait', 'headshot'];
		foreach($candidates as $name) {
			if(!$page->template->hasField($name)) continue;
			$val = $page->get($name);
			if(is_object($val)) {
				$img = method_exists($val, 'first') ? $val->first() : $val;
				if($img && method_exists($img, 'httpUrl')) return (string) $img->httpUrl;
				if($img && property_exists($img, 'httpUrl')) return (string) $img->httpUrl;
			} elseif(is_string($val) && $val !== '') {
				return $val;
			}
		}
		return '';
	}

	protected function jsonLdOrgId(): string {
		$url = (string) ($this->get('jsonld_org_url') ?: $this->wire('config')->urls->httpRoot);
		if($url === '') return '';
		return rtrim($url, '/') . '/#organization';
	}

	protected function jsonLdWebSiteId(): string {
		$url = (string) ($this->get('jsonld_org_url') ?: $this->wire('config')->urls->httpRoot);
		if($url === '') return '';
		return rtrim($url, '/') . '/#website';
	}

	protected function jsonLdWebPageId(Page $page): string {
		$canon = $this->getCanonical($page);
		return $canon === '' ? '' : $canon . '#webpage';
	}

	protected function jsonLdPersonId(Page $page): string {
		$url = (string) $page->httpUrl;
		return $url === '' ? '' : rtrim($url, '/') . '/#person';
	}

	/**
	 * Resolve a JSON-LD config value for the current request language.
	 *
	 * Resolution order:
	 *   1. Per-language map entry (e.g. `de=Mein Beispiel`) for the requested language.
	 *   2. Per-language map entry for `default` (so a site can localise *just* away
	 *      from the bare config field while still using the map machinery).
	 *   3. The single-text `$fallback` value.
	 *
	 * @param string $mapConfigKey Module config key holding the `key=value` map textarea.
	 * @param string $fallback     The single-text value used when no map entry matches.
	 * @param Language|null $lang  Target language (defaults to current request language).
	 */
	protected function resolveLocalizedJsonLdValue(string $mapConfigKey, string $fallback, $lang = null): string {
		$map = $this->parseLanguageMap((string) $this->get($mapConfigKey));
		$name = $this->resolveLanguageName($lang);

		if($name !== '' && isset($map[$name]) && trim($map[$name]) !== '') {
			return trim($map[$name]);
		}
		if(isset($map['default']) && trim($map['default']) !== '') {
			return trim($map['default']);
		}
		return trim($fallback);
	}

	/**
	 * Parse a textarea (one entry per line) or comma-separated list into a
	 * trimmed, deduplicated PHP array. Empty entries and comment lines are
	 * skipped.
	 */
	protected function parseJsonLdLines(string $text, string $delim = "\n"): array {
		if($text === '') return [];
		$parts = $delim === "\n" ? preg_split('/\r?\n/', $text) : explode($delim, $text);
		$out = [];
		foreach($parts as $part) {
			$part = trim($part);
			if($part === '' || str_starts_with($part, '#')) continue;
			if(!in_array($part, $out, true)) $out[] = $part;
		}
		return $out;
	}

	public function ___renderHead(Page $page): string {
		$lines = array_merge(
			['<!-- SeoNeo -->'],
			$this->getTitleLines($page),
			$this->getDescriptionLines($page),
			$this->getCanonicalLines($page),
			$this->getRobotsLines($page),
			$this->getKeywordsLines($page),
			$this->getOgLines($page),
			$this->getTwitterLines($page),
			$this->getVerificationLines($page),
			$this->getAuthorLines($page),
			$this->getCustomMappingLines($page),
			$this->getHreflangLines($page),
			$this->getSchemaLines($page),
			$this->getCustomBlockLines($page),
			['<!-- /SeoNeo -->']
		);
		return implode("\n", array_filter($lines));
	}

	// ────────────────────────────────────────────────────────────────────
	//  Section builders — each returns an array of zero or more HTML
	//  lines for one logical group of <head> tags. Used by ___renderHead()
	//  to compose the full block and by the public ___render*() partial
	//  methods (renderOg, renderTwitter, etc.) to expose each group on
	//  its own for developers composing their own <head>.
	// ────────────────────────────────────────────────────────────────────

	protected function getTitleLines(Page $page): array {
		$title = $this->getTitle($page);
		if($title === '') return [];
		return ['<title>' . $this->esc($title) . '</title>'];
	}

	protected function getDescriptionLines(Page $page): array {
		$desc = $this->getDescription($page);
		if($desc === '') return [];
		return ['<meta name="description" content="' . $this->esc($desc) . '">'];
	}

	protected function getCanonicalLines(Page $page): array {
		$canonical = $this->getCanonical($page);
		if($canonical === '') return [];
		return ['<link rel="canonical" href="' . $this->esc($canonical) . '">'];
	}

	protected function getRobotsLines(Page $page): array {
		$robots = $this->getRobots($page);
		$aiDirectives = $this->getAiDirectives($page);
		$granular = $this->getRobotsDirectives($page);
		// Omit the tag entirely only when it would be a no-op: default
		// index,follow AND no AI directives AND no granular directives.
		// Otherwise emit a single composed tag (per Google's preference —
		// max-snippet / max-image-preview etc. live inside the robots tag,
		// not in parallel googlebot tags).
		if($robots === 'index,follow' && empty($aiDirectives) && empty($granular)) return [];
		$parts = [$robots];
		foreach($aiDirectives as $d) $parts[] = $d;
		foreach($granular as $key => $val) $parts[] = $key . ':' . $val;
		return ['<meta name="robots" content="' . $this->esc(implode(',', $parts)) . '">'];
	}

	protected function getKeywordsLines(Page $page): array {
		$kw = $this->getKeywords($page);
		if($kw === '') return [];
		return ['<meta name="keywords" content="' . $this->esc($kw) . '">'];
	}

	protected function getOgLines(Page $page): array {
		$out = [];

		$ogTitle = $this->getOgTitle($page);
		if($ogTitle !== '') {
			$out[] = '<meta property="og:title" content="' . $this->esc($ogTitle) . '">';
		}

		$desc = $this->getDescription($page);
		if($desc !== '') {
			$out[] = '<meta property="og:description" content="' . $this->esc($desc) . '">';
		}

		$canonical = $this->getCanonical($page);
		$out[] = '<meta property="og:url" content="' . $this->esc($canonical) . '">';
		$ogType = $this->getOgType($page);
		$out[] = '<meta property="og:type" content="' . $this->esc($ogType) . '">';

		// Article-specific OG tags (C14) — only emit when og:type === 'article',
		// so non-article pages stay clean and Facebook / LinkedIn aren't
		// confused by article timestamps on a generic web page.
		if($ogType === 'article') {
			foreach($this->getArticleAuthors($page) as $author) {
				$out[] = '<meta property="article:author" content="' . $this->esc($author) . '">';
			}
			$publishedTime = $this->getArticlePublishedTime($page);
			if($publishedTime !== '') {
				$out[] = '<meta property="article:published_time" content="' . $this->esc($publishedTime) . '">';
			}
			$modifiedTime = $this->getArticleModifiedTime($page);
			if($modifiedTime !== '') {
				$out[] = '<meta property="article:modified_time" content="' . $this->esc($modifiedTime) . '">';
			}
		}

		$siteName = $this->getSiteName();
		if($siteName !== '') {
			$out[] = '<meta property="og:site_name" content="' . $this->esc($siteName) . '">';
		}

		$ogLocale = trim($this->getOgLocale($page));
		if($ogLocale !== '') {
			$out[] = '<meta property="og:locale" content="' . $this->esc($ogLocale) . '">';
		}

		foreach($this->getOgLocaleAlternates($page) as $altLocale) {
			$out[] = '<meta property="og:locale:alternate" content="' . $this->esc($altLocale) . '">';
		}

		$ogData = $this->getOgImageData($page);
		$ogImage = $ogData['url'] ?? '';
		if($ogImage !== '') {
			$out[] = '<meta property="og:image" content="' . $this->esc($ogImage) . '">';
			if(!empty($ogData['width'])) {
				$out[] = '<meta property="og:image:width" content="' . (int) $ogData['width'] . '">';
			}
			if(!empty($ogData['height'])) {
				$out[] = '<meta property="og:image:height" content="' . (int) $ogData['height'] . '">';
			}
			if(!empty($ogData['secure_url'])) {
				$out[] = '<meta property="og:image:secure_url" content="' . $this->esc($ogData['secure_url']) . '">';
			}
			if(!empty($ogData['type'])) {
				$out[] = '<meta property="og:image:type" content="' . $this->esc($ogData['type']) . '">';
			}
		}

		return $out;
	}

	protected function getTwitterLines(Page $page): array {
		$out = [];

		$ogData = $this->getOgImageData($page);
		$ogImage = $ogData['url'] ?? '';
		$out[] = '<meta name="twitter:card" content="' . ($ogImage ? 'summary_large_image' : 'summary') . '">';

		$twSite = $this->getTwitterSite($page);
		if($twSite !== '') {
			$out[] = '<meta name="twitter:site" content="' . $this->esc($twSite) . '">';
		}

		$twCreator = $this->getTwitterCreator($page);
		if($twCreator !== '') {
			$out[] = '<meta name="twitter:creator" content="' . $this->esc($twCreator) . '">';
		}

		$ogTitle = $this->getOgTitle($page);
		if($ogTitle !== '') {
			$out[] = '<meta name="twitter:title" content="' . $this->esc($ogTitle) . '">';
		}

		$desc = $this->getDescription($page);
		if($desc !== '') {
			$out[] = '<meta name="twitter:description" content="' . $this->esc($desc) . '">';
		}

		if($ogImage !== '') {
			$out[] = '<meta name="twitter:image" content="' . $this->esc($ogImage) . '">';
		}

		return $out;
	}

	protected function getVerificationLines(Page $page): array {
		return $this->getVerificationMetaLines($page);
	}

	protected function getAuthorLines(Page $page): array {
		$author = $this->getAuthor($page);
		if($author === '') return [];
		return ['<meta name="author" content="' . $this->esc($author) . '">'];
	}

	protected function getCustomMappingLines(Page $page): array {
		$out = [];
		foreach($this->getCustomTagMappings() as $fieldName => $tagTemplate) {
			if(!$page->template->hasField($fieldName)) continue;
			$val = trim((string) $page->get($fieldName));
			if($val === '') continue;
			$out[] = sprintf($tagTemplate, $this->esc($val));
		}
		return $out;
	}

	protected function getHreflangLines(Page $page): array {
		$alts = $this->renderHreflangAlternates($page);
		if($alts === '') return [];
		return explode("\n", $alts);
	}

	protected function getSchemaLines(Page $page): array {
		$jsonLd = $this->renderJsonLd($page);
		if($jsonLd === '') return [];
		return [$jsonLd];
	}

	protected function getCustomBlockLines(Page $page): array {
		if(!$page->template->hasField('seoneo_custom')) return [];
		$custom = trim((string) $page->getUnformatted('seoneo_custom'));
		if($custom === '') return [];
		return [$custom];
	}

	// ────────────────────────────────────────────────────────────────────
	//  Partial render methods — public, hookable wrappers around each
	//  section builder. Return one section's HTML for developers composing
	//  their own <head>. None of these emit the `<!-- SeoNeo -->` block
	//  markers — those are only added by the full ___renderHead().
	// ────────────────────────────────────────────────────────────────────

	public function ___renderDescription(Page $page): string {
		return implode("\n", $this->getDescriptionLines($page));
	}

	public function ___renderCanonical(Page $page): string {
		return implode("\n", $this->getCanonicalLines($page));
	}

	public function ___renderRobots(Page $page): string {
		return implode("\n", $this->getRobotsLines($page));
	}

	public function ___renderOg(Page $page): string {
		return implode("\n", $this->getOgLines($page));
	}

	public function ___renderTwitter(Page $page): string {
		return implode("\n", $this->getTwitterLines($page));
	}

	public function ___renderHreflang(Page $page): string {
		return implode("\n", $this->getHreflangLines($page));
	}

	public function ___renderVerification(Page $page): string {
		return implode("\n", $this->getVerificationLines($page));
	}

	public function ___renderAuthor(Page $page): string {
		return implode("\n", $this->getAuthorLines($page));
	}

	public function ___renderSchema(Page $page): string {
		return implode("\n", $this->getSchemaLines($page));
	}

	/**
	 * Return any <meta> verification tags configured for Google / Bing /
	 * Yandex / Pinterest / Facebook / Baidu. Emitted on the homepage by
	 * default; can be enabled on every page via the "Emit on all pages"
	 * setting for editors who self-verify subdomains or country variants.
	 */
	/**
	 * Per-service verification tokens as an associative array.
	 *
	 * Returns the normalised, trimmed verification tokens for every
	 * search-engine / social platform that has a configured value,
	 * keyed by short service name:
	 *
	 *   ['google' => 'abc123…', 'bing' => 'def456…', …]
	 *
	 * Editors sometimes paste the entire `<meta>` snippet from the
	 * service dashboard rather than just the token — this method
	 * normalises that into the bare token string in either case.
	 *
	 * Empty array when no verification is configured. Hookable for
	 * sites that want to inject verification tokens from another store
	 * (e.g. a Settings page).
	 */
	public function ___getVerifications(): array {
		$specs = [
			'google'    => 'verify_google',
			'bing'      => 'verify_bing',
			'yandex'    => 'verify_yandex',
			'pinterest' => 'verify_pinterest',
			'facebook'  => 'verify_facebook',
			'baidu'     => 'verify_baidu',
		];
		$out = [];
		foreach($specs as $service => $configKey) {
			$val = trim((string) $this->get($configKey));
			if($val === '') continue;
			if(preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $val, $m)) {
				$val = $m[1];
			}
			$out[$service] = $val;
		}
		return $out;
	}

	protected function getVerificationMetaLines(Page $page): array {
		$homepageOnly = (int) $this->get('verify_homepage_only');
		if($homepageOnly && (int) $page->id !== 1) return [];

		$metaNames = [
			'google'    => 'google-site-verification',
			'bing'      => 'msvalidate.01',
			'yandex'    => 'yandex-verification',
			'pinterest' => 'p:domain_verify',
			'facebook'  => 'facebook-domain-verification',
			'baidu'     => 'baidu-site-verification',
		];

		$out = [];
		foreach($this->getVerifications() as $service => $token) {
			if(!isset($metaNames[$service])) continue;
			$out[] = '<meta name="' . $this->esc($metaNames[$service]) . '" content="' . $this->esc($token) . '">';
		}
		return $out;
	}

	/**
	 * Author meta tag value. Resolution order:
	 *   1. Page-level `seoneo_author` field, if defined on the template.
	 *   2. Module-level `meta_author` default.
	 *   3. '' — no tag rendered.
	 */
	public function ___getAuthor(Page $page): string {
		if($page->template && $page->template->hasField('seoneo_author')) {
			$v = trim((string) (method_exists($page, 'getUnformatted')
				? $page->getUnformatted('seoneo_author')
				: $page->get('seoneo_author')));
			if($v !== '') return $v;
		}
		return trim((string) $this->get('meta_author'));
	}

	/**
	 * Author list as an array of individual names.
	 *
	 * Splits the single-string `seoneo_author` value on commas, semicolons,
	 * " and " or " & " for pages with multiple credited authors.
	 *   "Jane Doe, John Smith"   → ['Jane Doe', 'John Smith']
	 *   "Jane Doe & John Smith"  → ['Jane Doe', 'John Smith']
	 *   "Jane Doe and John Smith" → ['Jane Doe', 'John Smith']
	 *
	 * The single-string `<meta name="author">` rendered tag stays unchanged
	 * for backwards compatibility — sites already using a comma-separated
	 * value see no difference. The array form is for downstream consumers
	 * (JSON-LD Article@author, multi-byline UIs, etc.).
	 *
	 * Hookable, so sites that have moved to a Page-reference author field
	 * can override this to return the resolved names directly.
	 */
	public function ___getAuthors(Page $page): array {
		$raw = $this->getAuthor($page);
		if($raw === '') return [];
		// Normalise the Oxford-comma form first so ", and " collapses to ", "
		// and we get a clean separator pass.
		$raw = preg_replace('/,\s+and\s+/i', ', ', $raw);
		$parts = preg_split('/\s*[,;]\s*|\s+and\s+|\s*&\s*/i', $raw);
		return array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));
	}

	public function ___renderHreflangAlternates(Page $page): string {
		$alts = $this->getHreflangAlternates($page);
		if(empty($alts)) return '';
		$out = [];
		foreach($alts as $code => $href) {
			$out[] = '<link rel="alternate" hreflang="' . $this->esc($code) . '" href="' . $this->esc($href) . '">';
		}
		return implode("\n", $out);
	}

	/**
	 * Resolve hreflang alternates for `$page` as a `[code => url]` map,
	 * including the `x-default` entry when there is a default language. This
	 * is the data form behind `___renderHreflangAlternates()` and the
	 * `$page->seoneo->hreflang->alternates` accessor — useful for callers
	 * that want to build their own markup (sitemaps, JSON-LD `inLanguage`
	 * arrays, custom UI, etc.) without re-implementing the URL builder.
	 *
	 * @hookable
	 */
	public function ___getHreflangAlternates(Page $page): array {
		$langs = $this->wire('languages');
		if(!$langs || count($langs) < 2) return [];

		$input = $this->wire('input');
		$pageNum = $input ? max(1, (int) $input->pageNum()) : 1;
		$segmentStr = ($input && method_exists($input, 'urlSegmentStr'))
			? trim((string) $input->urlSegmentStr(), '/')
			: '';

		$defaultLang = method_exists($langs, 'getDefault') ? $langs->getDefault() : null;
		$out = [];
		$defaultHref = '';

		foreach($langs as $lang) {
			if(!$page->viewable($lang)) continue;
			$href = $this->buildLanguageUrl($page, $lang, $pageNum, $segmentStr);
			if($href === '') continue;
			$code = $this->getHreflangCode($lang);
			if($code === '') continue;
			$out[$code] = $href;
			if($defaultLang && $lang->id === $defaultLang->id) $defaultHref = $href;
		}

		if($defaultHref !== '') $out['x-default'] = $defaultHref;
		return $out;
	}

	/**
	 * Build the absolute URL for the page in a specific language, adding any
	 * URL segment string and pagination prefix from the *current* request so
	 * that `/news/page2/` correctly maps to `/de/news/seite2/`.
	 */
	protected function buildLanguageUrl(Page $page, $lang, int $pageNum, string $segmentStr): string {
		$url = '';

		if(method_exists($page, 'localHttpUrl')) {
			try {
				$url = (string) $page->localHttpUrl($lang);
			} catch(\Throwable $e) {
				$url = '';
			}
		}

		if($url === '') {
			// Fall back to user-language switching for installs that do not
			// have LanguageSupportPageNames active (vanishingly rare on a
			// multi-language site, but defensive).
			$user = $this->wire('user');
			$orig = $user->language;
			try {
				$user->language = $lang;
				$url = (string) $page->httpUrl;
			} finally {
				$user->language = $orig;
			}
		}

		if($url === '') return '';
		if(substr($url, -1) !== '/') $url .= '/';

		if($segmentStr !== '') $url .= $segmentStr . '/';

		if($pageNum > 1) {
			$prefix = $this->pageNumUrlPrefix($lang);
			if($prefix !== '') $url .= $prefix . $pageNum . '/';
		}

		return $url;
	}

	/**
	 * Resolve the per-language pagination URL prefix (e.g. `page`, `seite`,
	 * `pagina`). Reads `$config->pageNumUrlPrefixes` first; falls back to the
	 * core default `page`.
	 */
	protected function pageNumUrlPrefix($lang): string {
		$config = $this->wire('config');
		$prefixes = $config ? $config->pageNumUrlPrefixes : null;
		if(is_array($prefixes) && !empty($prefixes)) {
			$langName = $this->resolveLanguageName($lang);
			if($langName !== '' && isset($prefixes[$langName]) && $prefixes[$langName] !== '') {
				return (string) $prefixes[$langName];
			}
		}
		return 'page';
	}

	// ────────────────────────────────────────────────────────────────────
	//  Title formatting
	// ────────────────────────────────────────────────────────────────────

	public function ___formatTitle(string $rawTitle): string {
		$format = (string) $this->title_format;
		if($format === '') $format = '{title}';

		$siteName = $this->getSiteName();
		$separator = $siteName === '' ? '' : (string) $this->title_separator;

		$out = strtr($format, [
			'{title}'     => $rawTitle,
			'{site_name}' => $siteName,
			'{separator}' => $separator,
			'{pageNum}'   => $this->resolvePageNumLabel(),
			'{pageNumber}' => $this->resolvePageNumber(),
		]);

		$out = trim($out);
		$sep = trim((string) $this->title_separator);
		if($sep !== '') {
			$pattern = '/^' . preg_quote($sep, '/') . '+|' . preg_quote($sep, '/') . '+$/u';
			$out = trim(preg_replace($pattern, '', $out));
		}
		return $out;
	}

	/**
	 * "Page N" (localised) when the current request is on pageNum > 1, else ''.
	 * Used for the {pageNum} placeholder in title_format and template defaults.
	 */
	protected function resolvePageNumLabel(): string {
		$n = $this->resolveCurrentPageNum();
		if($n <= 1) return '';
		return sprintf($this->_('Page %d'), $n);
	}

	/**
	 * Bare integer when on pageNum > 1, else empty string. Used for
	 * `{pageNumber}` so editors can wrap it themselves: `Page {pageNumber}`.
	 */
	protected function resolvePageNumber(): string {
		$n = $this->resolveCurrentPageNum();
		return $n > 1 ? (string) $n : '';
	}

	protected function resolveCurrentPageNum(): int {
		$input = $this->wire('input');
		return $input ? max(1, (int) $input->pageNum()) : 1;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Smart-map
	// ────────────────────────────────────────────────────────────────────

	public function ___resolveSmartMap(Page $page, string $key): string {
		$map = $this->getSmartMap();
		if(!isset($map[$key]) || !is_array($map[$key])) return '';

		foreach($map[$key] as $fieldName) {
			$fieldName = trim($fieldName);
			if($fieldName === '') continue;

			$inheritable = false;
			if(strncmp($fieldName, '*', 1) === 0) {
				$inheritable = true;
				$fieldName = ltrim(substr($fieldName, 1));
				if($fieldName === '') continue;
			}

			$val = $this->readSmartMapValue($page, $fieldName);

			// Ancestor-walk fallback. Activated by the `*` prefix.
			// Walks parents nearest-first; root is excluded automatically because
			// $page->parents() never includes the page itself, and we stop before
			// the home page only for non-home requests (a homepage referencing
			// `*field` has nothing to walk anyway).
			if($val === '' && $inheritable) {
				foreach($page->parents()->reverse() as $ancestor) {
					if(!$ancestor || !$ancestor->id) continue;
					$val = $this->readSmartMapValue($ancestor, $fieldName);
					if($val !== '') break;
				}
			}

			if($val !== '') return $val;
		}
		return '';
	}

	/**
	 * Read a single smart-map field value from a given page, applying the
	 * same dotted-path / unformatted / strip-tags / collapse-whitespace
	 * pipeline used by the main resolveSmartMap loop. Returns '' on miss.
	 */
	protected function readSmartMapValue(Page $page, string $fieldName): string {
		if(str_contains($fieldName, '.')) {
			$val = $this->getDeep($page, $fieldName);
		} elseif(!$page->template || !$page->template->hasField($fieldName)) {
			return '';
		} else {
			$val = method_exists($page, 'getUnformatted')
				? $page->getUnformatted($fieldName)
				: $page->get($fieldName);
		}

		if($val === null || $val === '') return '';
		if(is_object($val)) {
			$val = method_exists($val, '__toString') ? (string) $val : '';
		} else {
			$val = (string) $val;
		}
		$val = preg_replace('/\s+/u', ' ', strip_tags($val));
		return trim((string) $val);
	}

	/**
	 * Walk a dot-separated field path and return the leaf value.
	 *
	 * Supports common ProcessWire shapes safely — any missing reference,
	 * empty repeater, or unset image returns null instead of throwing.
	 *
	 * Examples:
	 *   `banner.image`            — Page reference → image field
	 *   `gallery.first.image`     — Pageimages → first → image
	 *   `matrix.0.body`           — RepeaterMatrix → first item → body
	 *   `pagetable.first.summary` — PageTable → first → text
	 *
	 * @return mixed Pageimage, Pageimages, scalar, or null
	 */
	protected function getDeep($node, string $path) {
		$parts = explode('.', $path);
		foreach($parts as $part) {
			$part = trim($part);
			if($part === '' || $node === null || $node === '') return null;

			if($part === 'first') {
				if(is_object($node) && method_exists($node, 'first')) {
					$node = $node->first();
					continue;
				}
				if(is_object($node) && method_exists($node, 'eq')) {
					$node = $node->eq(0);
					continue;
				}
				return null;
			}

			if(ctype_digit($part)) {
				$idx = (int) $part;
				if(is_object($node) && method_exists($node, 'eq')) {
					$node = $node->eq($idx);
					continue;
				}
				if(is_array($node)) {
					$node = $node[$idx] ?? null;
					continue;
				}
				return null;
			}

			if(is_object($node) && method_exists($node, 'get')) {
				$node = $node->get($part);
				continue;
			}

			if(is_array($node)) {
				$node = $node[$part] ?? null;
				continue;
			}

			return null;
		}
		return $node;
	}

	public function getSmartMap(): array {
		$text = (string) $this->get('smart_map_text');
		if($text === '') return [];
		return $this->parseKeyListText($text);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Template defaults
	// ────────────────────────────────────────────────────────────────────

	public function ___renderTemplateDefault(Page $page, string $key): string {
		$defaults = $this->getTemplateDefaults();
		$tpl = $page->template ? $page->template->name : '';
		$block = $defaults[$tpl] ?? [];
		$raw = (string) ($block[$key] ?? '');
		if($raw === '') return '';
		return $this->expandTemplateString($raw, $page);
	}

	public function getTemplateDefaults(): array {
		$text = (string) $this->get('template_defaults_text');
		if($text === '') return [];
		return $this->parseTemplateDefaultsText($text);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Custom tag mappings
	// ────────────────────────────────────────────────────────────────────

	public function getCustomTagMappings(): array {
		$text = (string) $this->get('custom_tags_text');
		if($text === '') return [];
		$out = [];
		foreach(preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if($line === '' || str_starts_with($line, '#')) continue;
			if(!str_contains($line, '=')) continue;
			[$field, $tag] = explode('=', $line, 2);
			$field = trim($field);
			$tag = trim($tag);
			if($field !== '' && $tag !== '') $out[$field] = $tag;
		}
		return $out;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Module configuration UI
	// ────────────────────────────────────────────────────────────────────

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire('modules');

		// -- Admin styling ------------------------------------------------
		// Adds a little vertical breathing room between each fieldset on the
		// SEO NEO module-config screen. Scoped via a SEO NEO-specific class
		// on a hidden marker so it can't leak to other module configs.
		$styleHack = '<style>'
			. '.Inputfield_seoneo_admin_styles ~ li.Inputfield.InputfieldFieldset { margin-bottom: 1.25rem; }'
			. '.Inputfield_seoneo_admin_styles ~ li.Inputfield.InputfieldFieldset:last-child { margin-bottom: 0; }'
			. '.Inputfield_seoneo_admin_styles { display: none; }'
			. '</style>';
		$f = $modules->get('InputfieldMarkup');
		$f->name = 'seoneo_admin_styles';
		$f->markupText = $styleHack;
		$f->skipLabel = Inputfield::skipLabelBlank;
		$inputfields->add($f);

		// -- Page editor tab ----------------------------------------------

		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->label = $this->_('Page editor tab');
		$fieldset->icon = 'folder-open';
		$fieldset->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldText');
		$f->name = 'editor_tab_label';
		$f->label = $this->_('SEO tab label');
		$f->description = $this->_('Label for the SeoNeo fieldset tab on the page editor. Default is "SEO". Change to "SEO Neo" or anything you prefer — synced to the seoneo_tab field on save.');
		$f->value = $this->getEditorTabLabel();
		$f->columnWidth = 50;
		$fieldset->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'editor_tab_show_badge';
		$f->label = $this->_('Show NEO badge on tab');
		$f->description = $this->_('When enabled, a small "NEO" badge appears beside the tab label in the page editor. Recommended when MarkupSEO\'s "SEO" tab is also on the template — the field name (seoneo_tab) already differs; the badge makes the tab visually distinct.');
		$f->value = $this->getEditorTabShowBadge() ? 1 : 0;
		$f->columnWidth = 50;
		$fieldset->add($f);

		$inputfields->add($fieldset);

		// -- Site identity ------------------------------------------------

		$f = $modules->get('InputfieldText');
		$f->name = 'site_name';
		$f->label = $this->_('Site name');
		$f->description = $this->_('Used by the title format placeholder {site_name}.');
		$f->value = $this->site_name;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'title_separator';
		$f->noTrim = true;
		$f->label = $this->_('Title separator');
		$f->notes = $this->_('Common values: " | ", " – ", " · ". Leading/trailing spaces are preserved.');
		$f->value = $this->title_separator;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'title_format';
		$f->label = $this->_('Title format');
		$f->description = $this->_('Placeholders: `{title}`, `{separator}`, `{site_name}`, `{pageNum}` (renders "Page N" on paginated lists, blank on page 1), `{pageNumber}` (bare integer for custom wrapping).');
		$f->notes = $this->_('Tip: `{title}{separator}{pageNum}{separator}{site_name}` produces "Articles | Page 2 | My Site" automatically and falls back to "Articles | My Site" on the first page.');
		$f->value = $this->title_format;
		$inputfields->add($f);

		// Per-language site name map — only show on multi-language installs.
		$languages = $this->wire('languages');
		if($languages && count($languages) > 1) {
			$f = $modules->get('InputfieldTextarea');
			$f->name = 'site_name_map';
			$f->label = $this->_('Per-language site name');
			$f->description = $this->_('Override the **Site name** above for individual languages. One `langname=name` per line. Languages not listed fall back to the global site name. Used everywhere `{site_name}` is expanded — title format, template defaults, and the `og:site_name` meta tag.');
			$exampleNames = [];
			foreach($languages as $lang) {
				$ln = (string) $lang->name;
				if($ln === '' || $ln === 'default') continue;
				$exampleNames[] = $ln . '=Mein Beispiel';
				if(count($exampleNames) >= 2) break;
			}
			$f->notes = $this->_('Active language names: ') . '`' . implode('`, `', array_map(fn($l) => (string) $l->name, iterator_to_array($languages))) . '`' .
				($exampleNames ? "\n" . $this->_('Example:') . "\n```\n" . implode("\n", $exampleNames) . "\n```" : '');
			$f->rows = 3;
			$f->value = (string) $this->get('site_name_map');
			$inputfields->add($f);
		}

		// -- Behaviour ----------------------------------------------------

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'auto_inject';
		$f->label = $this->_('Auto-inject meta tags into <head>');
		$f->label2 = $this->_('Enabled');
		$f->description = $this->_('When enabled, SeoNeo injects the meta block on front-end pages. Disable to call $page->seoneo->render() manually.');
		if((int) $this->auto_inject === 1) $f->attr('checked', 'checked');
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldRadios');
		$f->name = 'inject_position';
		$f->label = $this->_('Injection position');
		$f->description = $this->_('Where the meta block lands inside `<head>`. Choose **Top** if you want SEO tags to render before any other tags injected by templates or third-party modules; **Bottom** keeps the historical behaviour and lets template-level overrides win.');
		$f->addOption('top', $this->_('Top — right after `<head>`'));
		$f->addOption('bottom', $this->_('Bottom — right before `</head>` (default)'));
		$f->value = $this->get('inject_position') ?: 'bottom';
		$f->columnWidth = 50;
		$inputfields->add($f);

		if($this->wire('modules')->isInstalled('ProCache')) {
			$f = $modules->get('InputfieldMarkup');
			$f->label = $this->_('ProCache detected');
			$f->value = '<p style="margin:0;font-size:13px">' .
				$this->_('SeoNeo is ProCache-compatible out of the box — the meta block is baked into the cached HTML when each page is built, and served from disk on subsequent hits with no PHP overhead. ' .
				'If you want the SEO block to appear in a specific spot relative to ProCache\'s own markers, disable auto-inject above and call `$page->seoneo->render()` from your template. ' .
				'See the README section "ProCache compatibility" for details.') .
				'</p>';
			$f->collapsed = Inputfield::collapsedYes;
			$inputfields->add($f);
		}

		// -- Canonical policy ---------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Canonical URL policy');
		$fs->description = $this->_('How the auto-generated canonical URL handles paginated lists and URL-segment-driven sub-pages. Editors can always override per-page via the **Canonical URL** field on the SEO tab — these settings only apply when that field is empty.');
		$fs->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldRadios');
		$f->name = 'canonical_pagination_policy';
		$f->label = $this->_('Pagination behaviour');
		$f->description = $this->_('On a page like `/news/page2/`, what should the canonical point at?');
		$f->addOption('include',  $this->_('Include the page number — `/news/page2/` is its own canonical (default, recommended)'));
		$f->addOption('collapse', $this->_('Always page 1 — `/news/` is the canonical for every paginated variant'));
		$f->value = $this->get('canonical_pagination_policy') ?: 'include';
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldRadios');
		$f->name = 'canonical_segment_policy';
		$f->label = $this->_('URL segment behaviour');
		$f->description = $this->_('On a URL-segment-driven page like `/news/2024/article-slug/`, what should the canonical point at?');
		$f->addOption('include',  $this->_('Include the segment string — `/news/2024/article-slug/` is its own canonical (default, recommended)'));
		$f->addOption('collapse', $this->_('Parent page only — `/news/` becomes the canonical for every segment-driven variant'));
		$f->value = $this->get('canonical_segment_policy') ?: 'include';
		$f->columnWidth = 50;
		$fs->add($f);

		$inputfields->add($fs);

		// -- Robots / indexing --------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Robots / indexing defaults');
		$fs->description = $this->_('Site-wide defaults that apply *before* the per-page Noindex / Nofollow checkboxes. Useful for staging environments and draft-heavy editorial workflows.');
		$fs->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'noindex_unpublished';
		$f->label = $this->_('Auto-noindex unpublished pages');
		$f->label2 = $this->_('Add `noindex` to any page that is currently unpublished');
		$f->description = $this->_('When a page is unpublished, ProcessWire still allows superusers and editors with view-permission to render it on the frontend. Without this toggle a search engine following an internal preview link could index the draft. **Enabled by default** — disable only if you have a deliberate workflow where unpublished pages must be indexable.');
		$f->columnWidth = 50;
		if((int) $this->get('noindex_unpublished')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'noindex_hidden';
		$f->label = $this->_('Auto-noindex hidden pages');
		$f->label2 = $this->_('Add `noindex` to any page flagged Hidden in the page tree');
		$f->description = $this->_('Hidden pages are still publicly viewable — they are simply omitted from `$page->children()` listings. Enabling this toggle treats Hidden as a stronger "not for search" signal. Off by default.');
		$f->columnWidth = 50;
		if((int) $this->get('noindex_hidden')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'noindex_sitewide';
		$f->label = $this->_('Site-wide noindex');
		$f->label2 = $this->_('Force `noindex` on every page, regardless of per-page settings');
		$f->description = $this->_('Belt-and-braces switch for staging, screenshot labs, and pre-launch sites. When enabled, `<meta name="robots">` will always include `noindex` — overriding the per-page Noindex checkbox and the Hidden/Unpublished auto-noindex toggles above. Off by default. Pair with the *Site-wide nofollow* checkbox if you also want to stop crawlers following links.');
		$f->columnWidth = 50;
		if((int) $this->get('noindex_sitewide')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'nofollow_sitewide';
		$f->label = $this->_('Site-wide nofollow');
		$f->label2 = $this->_('Force `nofollow` on every page, regardless of per-page settings');
		$f->description = $this->_('Companion to *Site-wide noindex*. When enabled, `<meta name="robots">` will always include `nofollow`. Off by default. Useful for staging environments where you do not want search engines following links into the unfinished site.');
		$f->columnWidth = 50;
		if((int) $this->get('nofollow_sitewide')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'emit_noai';
		$f->label = $this->_('AI training opt-out — `noai`');
		$f->label2 = $this->_('Append `noai` to the robots meta tag site-wide');
		$f->description = $this->_('Tells AI crawlers not to use this site\'s content for training generative-AI models. Honoured by some AI crawlers (DeviantArt-originated spec). **Not a substitute** for blocking AI bots at the robots.txt or HTTP level — treat as a polite request, not enforcement. Off by default. Pair with the *AI image opt-out* checkbox below for the strongest signal.');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		if((int) $this->get('emit_noai')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'emit_noimageai';
		$f->label = $this->_('AI image opt-out — `noimageai`');
		$f->label2 = $this->_('Append `noimageai` to the robots meta tag site-wide');
		$f->description = $this->_('Tells AI crawlers not to include this site\'s images in AI training datasets. Same enforcement caveat as `noai` — polite request, not blocking.');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		if((int) $this->get('emit_noimageai')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'robots_max_snippet';
		$f->label = $this->_('`max-snippet` — character cap for SERP text snippets');
		$f->description = $this->_('Google-recognised directive limiting the number of characters shown in the search snippet for this site. Set `-1` for no limit (Google\'s default), `0` to suppress the snippet entirely, or any positive integer for the cap. Leave blank to emit nothing. Site-wide.');
		$f->placeholder = '-1';
		$f->value = (string) $this->get('robots_max_snippet');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		$fs->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'robots_max_image_preview';
		$f->label = $this->_('`max-image-preview` — image preview size');
		$f->description = $this->_('Google-recognised directive controlling the maximum image size shown in search results. Leave blank to emit nothing. Site-wide.');
		$f->addOption('', $this->_('(not set — emit nothing)'));
		$f->addOption('none',     $this->_('none — no image preview'));
		$f->addOption('standard', $this->_('standard — default thumbnail size'));
		$f->addOption('large',    $this->_('large — full-size preview'));
		$f->value = (string) $this->get('robots_max_image_preview');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'robots_max_video_preview';
		$f->label = $this->_('`max-video-preview` — seconds of video preview');
		$f->description = $this->_('Google-recognised directive limiting the duration of video previews in seconds. `-1` for no limit, `0` to suppress, positive for seconds. Leave blank to emit nothing. Site-wide.');
		$f->placeholder = '-1';
		$f->value = (string) $this->get('robots_max_video_preview');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'robots_unavailable_after';
		$f->label = $this->_('`unavailable_after` — drop from index after a date');
		$f->description = $this->_('Google-recognised directive telling Search to drop the page from the index after the supplied moment. Accepts RFC 850 (`25-Aug-2026 15:00:00 PST`) or ISO 8601 (`2026-08-25T15:00:00Z`). Useful for event listings, time-limited offers, embargoed news. Leave blank to emit nothing. Site-wide.');
		$f->placeholder = '2026-12-31T23:59:59Z';
		$f->value = (string) $this->get('robots_unavailable_after');
		$f->columnWidth = 50;
		$f->collapsed = Inputfield::collapsedYes;
		$fs->add($f);

		$inputfields->add($fs);

		// -- JSON-LD (structured data) ------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Structured data (JSON-LD)');
		$fs->description = $this->_('SEO NEO can emit a `<script type="application/ld+json">` block on every page containing an Organization (or Person), WebSite, WebPage, optional Article / Person, and BreadcrumbList. This is what feeds Google Search rich results and helps AI search agents (Perplexity, Bing Copilot, etc.) understand site authorship and structure.');
		$fs->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'jsonld_enabled';
		$f->label = $this->_('Emit JSON-LD');
		$f->label2 = $this->_('Render the structured data script on every page');
		$f->description = $this->_('Master switch. When enabled, SEO NEO renders a `<script type="application/ld+json">` block in the page `<head>` containing the nodes described below. **On by default** — but no nodes are emitted unless you fill in at least an Organization name and URL.');
		$f->columnWidth = 100;
		if((int) $this->get('jsonld_enabled')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'jsonld_org_type';
		$f->label = $this->_('Publisher type');
		$f->description = $this->_('Schema.org type for the site-wide publisher node. `Organization` and its more specific subtypes (`LocalBusiness`, `NewsMediaOrganization`, `EducationalOrganization`) are appropriate for businesses and institutions. Pick `Person` for solo sites where you yourself are the publisher.');
		$f->addOption('Organization', 'Organization');
		$f->addOption('LocalBusiness', 'LocalBusiness');
		$f->addOption('NewsMediaOrganization', 'NewsMediaOrganization');
		$f->addOption('EducationalOrganization', 'EducationalOrganization');
		$f->addOption('Person', 'Person');
		$f->value = $this->get('jsonld_org_type') ?: 'Organization';
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'jsonld_org_name';
		$f->label = $this->_('Publisher name');
		$f->description = $this->_('Default Organization `name`. Falls back to the site name above when empty. For multi-language sites, the language-specific overrides below take precedence — most editors will leave this field empty and use the map.');
		$f->placeholder = (string) $this->getSiteName();
		$f->value = $this->get('jsonld_org_name');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'jsonld_org_name_map';
		$f->label = $this->_('Publisher name (per language)');
		$f->description = $this->_(
			'Optional. Per-language overrides for the Organization `name`, one per line. Resolved on every page render based on the visitor\'s language. Example:' . "\n" .
			'`default=Lakes & Trails`' . "\n" .
			'`de=Seen & Pfade`'
		);
		$f->rows = 3;
		$f->value = $this->get('jsonld_org_name_map');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldURL');
		$f->name = 'jsonld_org_url';
		$f->label = $this->_('Publisher URL');
		$f->description = $this->_('The canonical site URL. Falls back to the ProcessWire `$config->urls->httpRoot` when empty. Used in the `@id` URIs that wire the graph together — leave empty for almost all cases.');
		$f->placeholder = (string) $this->wire('config')->urls->httpRoot;
		$f->value = $this->get('jsonld_org_url');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldURL');
		$f->name = 'jsonld_org_logo';
		$f->label = $this->_('Publisher logo URL');
		$f->description = $this->_('Absolute URL to a square logo image (PNG or SVG). Google requires logos of at least 112×112 px for the [Logo structured data spec](https://developers.google.com/search/docs/appearance/structured-data/logo). Omitted from the graph when empty.');
		$f->placeholder = 'https://example.com/logo.png';
		$f->value = $this->get('jsonld_org_logo');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'jsonld_org_description';
		$f->label = $this->_('Publisher description');
		$f->description = $this->_('Default one- or two-sentence description of the publisher. Used as the Organization `description`. For multi-language sites, the per-language overrides below take precedence.');
		$f->rows = 3;
		$f->value = $this->get('jsonld_org_description');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'jsonld_org_description_map';
		$f->label = $this->_('Publisher description (per language)');
		$f->description = $this->_(
			'Optional. Per-language overrides for the Organization `description`, one per line. Resolved on every page render based on the visitor\'s language. Example:' . "\n" .
			'`default=An editorial guide to the Lake District, Yorkshire Dales, and Eden Valley.`' . "\n" .
			'`de=Ein redaktioneller Leitfaden zum Lake District, den Yorkshire Dales und dem Eden Valley.`'
		);
		$f->rows = 4;
		$f->value = $this->get('jsonld_org_description_map');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'jsonld_org_sameas';
		$f->label = $this->_('Publisher sameAs URLs');
		$f->description = $this->_('Optional. One URL per line — links to social profiles, Wikipedia, or other canonical references that identify the same publisher elsewhere on the web. Emitted as the `sameAs` array. Example:' . "\n" . '`https://twitter.com/yourhandle`' . "\n" . '`https://www.linkedin.com/company/yourcompany`');
		$f->rows = 4;
		$f->value = $this->get('jsonld_org_sameas');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'jsonld_article_templates';
		$f->label = $this->_('Article templates');
		$f->description = $this->_('Comma-separated list of template names whose pages should be emitted with an additional `Article` node alongside the `WebPage` (e.g. `journal_post,blog_post,news_article`). Empty by default.');
		$f->placeholder = 'journal_post,blog_post';
		$f->value = $this->get('jsonld_article_templates');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'jsonld_person_templates';
		$f->label = $this->_('Person templates');
		$f->description = $this->_('Comma-separated list of template names whose pages should be emitted with an additional `Person` node alongside the `WebPage` (e.g. `user,contributor,author`). Defaults to `user` so PW User pages used as author bios are recognised.');
		$f->placeholder = 'user';
		$f->value = $this->get('jsonld_person_templates') ?: 'user';
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldPageListSelect');
		$f->name = 'jsonld_default_author';
		$f->label = $this->_('Default Article author');
		$f->description = $this->_('Optional fallback for the Article `author` property when no per-page `author` field is set. Choose a User page that represents the typical / default author for the site.');
		$f->value = (int) $this->get('jsonld_default_author');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'jsonld_breadcrumbs';
		$f->label = $this->_('Emit BreadcrumbList');
		$f->label2 = $this->_('Include a BreadcrumbList node built from the page hierarchy');
		$f->description = $this->_('Adds a `BreadcrumbList` to the graph on every page that has a non-empty parent chain. Lets Google show breadcrumbs in search results instead of the bare URL. On by default.');
		$f->columnWidth = 50;
		if((int) $this->get('jsonld_breadcrumbs')) $f->attr('checked', 'checked');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'jsonld_pretty';
		$f->label = $this->_('Pretty-print JSON-LD');
		$f->label2 = $this->_('Emit indented, human-readable JSON in the rendered `<head>`');
		$f->description = $this->_('Helps when inspecting view-source or screenshotting the structured data. Output is identical to crawlers either way — pretty-printing just adds whitespace. On by default for developer experience; turn off if you prefer minimal markup.');
		$f->columnWidth = 50;
		if((int) $this->get('jsonld_pretty')) $f->attr('checked', 'checked');
		$fs->add($f);

		$inputfields->add($fs);

		// -- Field mapping ------------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Field mapping');
		$fs->description = $this->_('Map PW field names to SEO roles. Change these if you use different field names.');
		$fs->collapsed = Inputfield::collapsedYes;

		foreach([
			'role_title'       => 'Title field',
			'role_description' => 'Description field',
			'role_canonical'   => 'Canonical URL field',
			'role_noindex'     => 'Noindex field',
			'role_nofollow'    => 'Nofollow field',
		] as $key => $label) {
			$f = $modules->get('InputfieldText');
			$f->name = $key;
			$f->label = $this->_($label);
			$f->value = $this->get($key);
			$f->columnWidth = 33;
			$fs->add($f);
		}

		$inputfields->add($fs);

		// -- Smart map ----------------------------------------------------

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'smart_map_text';
		$f->label = $this->_('Smart field mapping');
		$f->description = $this->_(
			'Fallback fields when the SEO field is empty. Format: key=field1,field2. ' .
			'SeoNeo tries each field in order and uses the first non-empty value. ' .
			'Supported keys: title, description. ' .
			'Dotted paths reach into nested data: `banner.image`, `gallery.first.alt`, ' .
			'`matrix_blocks.first.body`, `pagetable_items.0.summary`. ' .
			'Missing references at any step are skipped silently. ' .
			'**Ancestor walk** — prefix any field with `*` to fall back to ancestors when the current page leaves it blank: `*section_description` checks parents nearest-first and stops at the first non-empty value. Useful for letting a section landing page supply a default description for every article inside it.'
		);
		$f->notes = $this->_("Example:\ntitle=headline,title\ndescription=summary,body,*section_description,banner.image.description");
		$f->value = $this->get('smart_map_text');
		$f->rows = 4;
		$inputfields->add($f);

		// -- Template defaults --------------------------------------------

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'template_defaults_text';
		$f->label = $this->_('Per-template defaults');
		$f->description = $this->_(
			'Default meta values per template. Format: [template-name] then key=value lines. ' .
			'Recognised keys: title, description, og_type. ' .
			'Placeholders inside values: `{title}`, `{site_name}`, `{page.fieldname}`, `{fieldname}` (shorthand), `{pageNum}`, `{pageNumber}`. ' .
			'Pipe-separated fallbacks pick the first non-empty value: `{long_title|title}`, `{summary|body|intro}`. ' .
			'Dotted paths reach into nested data: `{banner.image.description}`.'
		);
		$f->notes = $this->_("Example:\n[home]\ndescription=Welcome to {site_name}.\n\n[blog-post]\nog_type=article\ndescription={summary|body}\ntitle={long_title|title}");
		$f->value = $this->get('template_defaults_text');
		$f->rows = 8;
		$inputfields->add($f);

		// -- Search engine verification -----------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Search engine verification');
		$fs->description = $this->_('Paste the verification token (or the entire `<meta>` snippet) that each service gives you. SeoNeo emits a single, normalised meta tag — you can paste either the bare token (`abc123…`) or the full `<meta name="…" content="…">` snippet from the service\'s dashboard.');
		$fs->collapsed = Inputfield::collapsedYes;

		$verifyFields = [
			'verify_google'    => [$this->_('Google Search Console'), 'google-site-verification'],
			'verify_bing'      => [$this->_('Bing Webmaster Tools'),  'msvalidate.01'],
			'verify_yandex'    => [$this->_('Yandex Webmaster'),       'yandex-verification'],
			'verify_pinterest' => [$this->_('Pinterest'),              'p:domain_verify'],
			'verify_facebook'  => [$this->_('Facebook Domain'),        'facebook-domain-verification'],
			'verify_baidu'     => [$this->_('Baidu Webmaster'),        'baidu-site-verification'],
		];
		foreach($verifyFields as $key => [$label, $metaName]) {
			$f = $modules->get('InputfieldText');
			$f->name = $key;
			$f->label = $label;
			$f->notes = sprintf($this->_('Renders as `<meta name="%s" content="…">`'), $metaName);
			$f->value = (string) $this->get($key);
			$f->columnWidth = 50;
			$fs->add($f);
		}

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'verify_homepage_only';
		$f->label = $this->_('Emit verification tags on the homepage only');
		$f->label2 = $this->_('Recommended — most services only check the root URL');
		$f->description = $this->_('Disable to render the verification tags on every page (occasionally needed when verifying subdomains or per-language country variants).');
		if((int) $this->get('verify_homepage_only')) $f->attr('checked', 'checked');
		$fs->add($f);

		$inputfields->add($fs);

		// -- Author -------------------------------------------------------

		$f = $modules->get('InputfieldText');
		$f->name = 'meta_author';
		$f->label = $this->_('Default author');
		$f->description = $this->_('Site-wide default for the `<meta name="author">` tag. Leave blank to skip the tag entirely. Per-page overrides are picked up from a `seoneo_author` field if present on a template.');
		$f->collapsed = Inputfield::collapsedBlank;
		$f->value = (string) $this->get('meta_author');
		$inputfields->add($f);

		// -- Custom tags --------------------------------------------------

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'custom_tags_text';
		$f->label = $this->_('Custom tag mappings');
		$f->description = $this->_(
			'Map any PW field to a custom meta tag. Format: fieldname=<tag template with %s>. ' .
			'The field value replaces %s. Empty fields are skipped.'
		);
		$f->notes = $this->_("Example:\nseoneo_keywords=<meta name=\"keywords\" content=\"%s\">\nseoneo_og_title=<meta property=\"og:title\" content=\"%s\">");
		$f->value = $this->get('custom_tags_text');
		$f->rows = 5;
		$inputfields->add($f);

		// -- Open Graph ---------------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Open Graph');
		$fs->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldMarkup');
		$f->label = $this->_('OG image resolution order');
		$f->value = '<ol style="margin:0;padding-left:1.4em;font-size:13px;line-height:1.8">' .
			'<li><strong>seoneo_og_image</strong> — dedicated per-page image field in the SEO tab</li>' .
			'<li><strong>Image field scan</strong> — checks the field names listed below in order</li>' .
			'<li><strong>Closest ancestor</strong> — only when "Inherit OG image from closest ancestor" is on; walks the page\'s parents and reuses steps 1–2 on each one</li>' .
			'<li><strong>Homepage seoneo_og_image</strong> — used as the site-wide default if set on the home page</li>' .
			'<li><strong>Default OG image URL</strong> — the URL below, last resort</li>' .
			'</ol>' .
			'<p style="margin:.6em 0 0;font-size:12px;color:#666">' .
			'When a real Pageimage is resolved (steps 1–3), SeoNeo also emits ' .
			'<code>og:image:width</code>, <code>og:image:height</code>, <code>og:image:secure_url</code> (HTTPS) and <code>og:image:type</code> alongside it.' .
			'</p>';
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'og_image_fields';
		$f->label = $this->_('Image field scan order');
		$f->description = $this->_(
			'Comma-separated list of PW image field names to scan (step 2). The first field on the page that contains an image wins. ' .
			'Dotted paths reach into nested data: `banner.image` (page reference), `gallery.first` (Pageimages), `matrix_blocks.first.image` (RepeaterMatrix). Missing references at any step are skipped silently.'
		);
		$f->value = $this->get('og_image_fields');
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'og_image_inherit_ancestors';
		$f->label = $this->_('Inherit OG image from closest ancestor');
		$f->label2 = $this->_('When the page has no image of its own, walk up the page tree and use the first ancestor that does');
		$f->description = $this->_('When enabled, after checking the page\'s own seoneo_og_image and configured image fields, SeoNeo walks `$page->parents()` from the closest ancestor upward looking for a populated value. The homepage default still applies as the final fallback. Off by default.');
		$f->value = 1;
		$f->attr('checked', (int) $this->get('og_image_inherit_ancestors') ? 'checked' : '');
		$fs->add($f);

		$f = $modules->get('InputfieldURL');
		$f->name = 'og_image_default';
		$f->label = $this->_('Default OG image URL');
		$f->description = $this->_('Absolute URL used as a last resort if no image is found anywhere. Recommended size: 1200×630px.');
		$f->value = $this->get('og_image_default');
		$fs->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->name = 'default_og_type';
		$f->label = $this->_('Default OG type');
		$f->description = $this->_('Site-wide fallback for `<meta property="og:type">`. Override per template with an `og_type=` line in the per-template defaults, or per page via the OG Type field on the SEO tab.');
		foreach([
			'website'      => 'website (default)',
			'article'      => 'article',
			'profile'      => 'profile',
			'book'         => 'book',
			'product'      => 'product',
			'video.movie'  => 'video.movie',
			'video.episode' => 'video.episode',
			'video.tv_show' => 'video.tv_show',
			'video.other'  => 'video.other',
			'music.song'   => 'music.song',
			'music.album'  => 'music.album',
			'music.playlist' => 'music.playlist',
			'music.radio_station' => 'music.radio_station',
		] as $val => $label) {
			$f->addOption($val, $label);
		}
		$f->value = $this->get('default_og_type') ?: 'website';
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'og_default_locale';
		$f->label = $this->_('Default OG locale');
		$f->description = $this->_('Site-wide fallback for `<meta property="og:locale">` (e.g. `en_US`, `en_GB`, `de_DE`). Used as-is on single-language sites and as the fallback whenever a language has no entry in the locale map below.');
		$f->placeholder = 'en_US';
		$f->value = $this->get('og_default_locale') ?: 'en_US';
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'og_locale_map';
		$f->label = $this->_('Locale map for languages');
		$f->description = $this->_(
			'Optional. Map ProcessWire language names to OG locale codes, one per line, e.g.:' . "\n" .
			'`default=en_GB`' . "\n" .
			'`de=de_AT`' . "\n" .
			'`fr=fr_CA`' . "\n" .
			'Languages not listed here fall back to a derived value (e.g. `de` → `de_DE`) or to the default locale above. Used for both `og:locale` and the `og:locale:alternate` tags emitted alongside hreflang.'
		);
		$f->rows = 4;
		$f->value = $this->get('og_locale_map');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'hreflang_default';
		$f->label = $this->_('Default hreflang code');
		$f->description = $this->_('ProcessWire stores the default language under the system-locked name `default`, which is not a valid BCP47 hreflang tag — Google ignores `<link rel="alternate" hreflang="default">` entirely. SEO NEO maps the default language to this code. Use a [BCP47](https://en.wikipedia.org/wiki/IETF_language_tag) language tag (`en`, `en-GB`, `de-AT`, `fr-CA`).');
		$f->placeholder = 'en';
		$f->value = $this->get('hreflang_default') ?: 'en';
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'hreflang_map';
		$f->label = $this->_('Hreflang map for languages');
		$f->description = $this->_(
			'Optional. Map ProcessWire language names to hreflang codes, one per line. Overrides the default above for the listed language; non-listed languages use their own name. Example:' . "\n" .
			'`default=en-GB`' . "\n" .
			'`de=de-AT`' . "\n" .
			'`fr=fr-CA`' . "\n" .
			'Used in `<link rel="alternate" hreflang="…">` tags emitted on every page.'
		);
		$f->rows = 4;
		$f->value = $this->get('hreflang_map');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'twitter_site';
		$f->label = $this->_('Twitter / X site handle');
		$f->description = $this->_('The @username for the site itself. Used for `<meta name="twitter:site">`. Leading @ is added automatically if you forget it.');
		$f->placeholder = '@yourhandle';
		$f->value = $this->get('twitter_site');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'twitter_creator';
		$f->label = $this->_('Default Twitter / X creator handle');
		$f->description = $this->_('The @username for the content author. Used for `<meta name="twitter:creator">`. Hook `SeoNeo::getTwitterCreator` to return a per-page or per-author value.');
		$f->placeholder = '@yourhandle';
		$f->value = $this->get('twitter_creator');
		$f->columnWidth = 50;
		$fs->add($f);

		$inputfields->add($fs);

		// -- Counter thresholds -------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Character counter thresholds');
		$fs->collapsed = Inputfield::collapsedYes;

		foreach([
			'counter_title_green'  => ['Title green limit (desktop)', 60],
			'counter_title_amber'  => ['Title amber limit (desktop)', 70],
			'counter_desc_green'   => ['Description green limit (desktop)', 160],
			'counter_desc_amber'   => ['Description amber limit (desktop)', 180],
		] as $key => [$label, $default]) {
			$f = $modules->get('InputfieldInteger');
			$f->name = $key;
			$f->label = $this->_($label);
			$f->value = $this->get($key) ?: $default;
			$f->columnWidth = 25;
			$fs->add($f);
		}

		// Mobile-surface thresholds — paired with the L9 SERP preview's mobile
		// toggle. Google's mobile SERP card is structurally similar to desktop
		// but truncates earlier; ~50 chars for the title and ~120 chars for
		// the description are the most commonly cited rough cut-offs.
		foreach([
			'counter_title_mobile_green' => ['Title green limit (mobile)', 50],
			'counter_title_mobile_amber' => ['Title amber limit (mobile)', 60],
			'counter_desc_mobile_green'  => ['Description green limit (mobile)', 120],
			'counter_desc_mobile_amber'  => ['Description amber limit (mobile)', 140],
		] as $key => [$label, $default]) {
			$f = $modules->get('InputfieldInteger');
			$f->name = $key;
			$f->label = $this->_($label);
			$f->value = $this->get($key) ?: $default;
			$f->columnWidth = 25;
			$fs->add($f);
		}

		$f = $modules->get('InputfieldInteger');
		$f->name = 'max_description_length';
		$f->label = $this->_('Max description length (auto-resolved values)');
		$f->description = $this->_('Smart-mapped and template-default descriptions are truncated to this length at the nearest word boundary, with an ellipsis appended. Values typed directly into the SEO Description field are never truncated. Set to 0 to disable truncation entirely.');
		$f->value = (int) $this->get('max_description_length') ?: 180;
		$f->columnWidth = 100;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'hard_cap_title';
		$f->label = $this->_('Hard-cap title input length');
		$f->description = $this->_('Optional. When set above 0, the SEO Title input gets a real `maxlength` attribute and the browser physically prevents editors from typing past the limit. Off by default — most editors prefer the soft amber/red counter, which still runs underneath.');
		$f->notes = $this->_('Set to 0 to disable. Common hard caps: 60 (display target), 70 (Google\'s rough cut-off).');
		$f->value = (int) $this->get('hard_cap_title');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'hard_cap_description';
		$f->label = $this->_('Hard-cap description input length');
		$f->description = $this->_('Optional. When set above 0, the SEO Description input gets a real `maxlength` attribute. Off by default.');
		$f->notes = $this->_('Set to 0 to disable. Common hard caps: 160, 180.');
		$f->value = (int) $this->get('hard_cap_description');
		$f->columnWidth = 50;
		$fs->add($f);

		$inputfields->add($fs);

		return $inputfields;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Read a single page field for use as a meta-tag value.
	 *
	 * Always uses `getUnformatted()` so that textformatters which pre-encode
	 * the value (HTML Entity Encoder, Markdown, Smartypants, Hanna Code, etc.)
	 * cannot cause double-encoding when our own `esc()` runs on output. HTML
	 * tags are stripped and internal whitespace collapsed so an editor pasting
	 * "<p>Lorem ipsum</p>" into a SEO field still produces "Lorem ipsum" in
	 * the meta tag.
	 *
	 * The seoneo_custom textarea deliberately bypasses this method — that
	 * field is rendered verbatim by design.
	 */
	protected function readField(Page $page, string $fieldName): string {
		if($fieldName === '' || !$page->template->hasField($fieldName)) return '';

		$val = method_exists($page, 'getUnformatted')
			? $page->getUnformatted($fieldName)
			: $page->get($fieldName);

		if($val === null) return '';
		if(is_object($val)) {
			$val = method_exists($val, '__toString') ? (string) $val : '';
		} else {
			$val = (string) $val;
		}

		$val = strip_tags($val);
		$val = preg_replace('/\s+/u', ' ', $val);
		return trim((string) $val);
	}

	protected function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Expand `{token}` placeholders in title-format and template-default
	 * strings. Each token may contain pipe-separated fallbacks, and each
	 * fallback may be a special keyword (`title`, `site_name`) or any page
	 * field — including dotted paths like `banner.image.description`.
	 *
	 * Examples:
	 *   `{title}`                                 — page title
	 *   `{site_name}`                             — module-config site name
	 *   `{page.summary}`                          — page field, current syntax
	 *   `{long_title|title}`                      — long_title if set, else title
	 *   `{seoneo_title|headline|title}`           — first non-empty
	 *   `{banner.image.description|summary}`      — dotted path with fallback
	 */
	protected function expandTemplateString(string $tpl, Page $page): string {
		$out = preg_replace_callback('/\{([^{}]+)\}/', function($m) use ($page) {
			$expr = trim($m[1]);
			if($expr === '') return $m[0];

			// Only process tokens we recognise. Leave anything else as a literal
			// to preserve backwards-compatibility (and to avoid eating
			// {separator}-style markers from sibling features).
			$looksLikeToken = $expr === 'title'
				|| $expr === 'site_name'
				|| $expr === 'pageNum'
				|| $expr === 'pageNumber'
				|| str_starts_with($expr, 'page.')
				|| str_contains($expr, '|')
				|| str_contains($expr, '.');
			if(!$looksLikeToken) return $m[0];

			foreach(explode('|', $expr) as $token) {
				$val = $this->resolvePlaceholderToken($page, trim($token));
				if($val !== '') return $val;
			}
			return '';
		}, $tpl);
		return trim((string) $out);
	}

	/**
	 * Resolve a single placeholder token to a string value (or '' if unset).
	 *
	 * - `title`             → page title
	 * - `site_name`         → module-config site name
	 * - `page.fieldname`    → page field (current syntax, kept for back-compat)
	 * - `fieldname`         → page field (bare token)
	 * - `field.subfield...` → walks the dotted path via getDeep()
	 */
	protected function resolvePlaceholderToken(Page $page, string $token): string {
		if($token === '') return '';
		if($token === 'title') return (string) $page->title;
		if($token === 'site_name') return $this->getSiteName();
		if($token === 'pageNum') return $this->resolvePageNumLabel();
		if($token === 'pageNumber') return $this->resolvePageNumber();

		if(str_starts_with($token, 'page.')) $token = substr($token, 5);

		if($token === '') return '';

		if(str_contains($token, '.')) {
			$val = $this->getDeep($page, $token);
		} elseif($page->template->hasField($token)) {
			$val = method_exists($page, 'getUnformatted')
				? $page->getUnformatted($token)
				: $page->get($token);
		} else {
			$val = null;
		}

		if($val === null || $val === '') return '';
		if(is_object($val)) {
			$val = method_exists($val, '__toString') ? (string) $val : '';
		} else {
			$val = (string) $val;
		}
		return trim((string) preg_replace('/\s+/u', ' ', strip_tags($val)));
	}

	protected function parseKeyListText(string $text): array {
		$out = [];
		foreach(preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if($line === '' || str_starts_with($line, '#')) continue;
			if(!str_contains($line, '=')) continue;
			[$k, $v] = explode('=', $line, 2);
			$k = trim($k);
			if($k === '') continue;
			$fields = array_values(array_filter(array_map('trim', explode(',', $v)), fn($s) => $s !== ''));
			if($fields) $out[$k] = $fields;
		}
		return $out;
	}

	protected function parseTemplateDefaultsText(string $text): array {
		$out = [];
		$current = null;
		foreach(preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if($line === '' || str_starts_with($line, '#')) continue;
			if(preg_match('/^\[([a-z0-9_\-]+)\]$/i', $line, $m)) {
				$current = $m[1];
				if(!isset($out[$current])) $out[$current] = [];
				continue;
			}
			if($current === null || !str_contains($line, '=')) continue;
			[$k, $v] = explode('=', $line, 2);
			$out[$current][trim($k)] = trim($v);
		}
		return $out;
	}
}


// ────────────────────────────────────────────────────────────────────────
//  Lightweight accessor for $page->seoneo
// ────────────────────────────────────────────────────────────────────────

class SeoNeoAccessor extends Wire {

	protected Page $page;
	protected SeoNeo $module;

	/**
	 * Cached namespace proxies (og, twitter, hreflang, verification, schema).
	 * Lazily instantiated so a template that only touches `->og->title` does
	 * not pay for the others, and so each proxy is the same instance across
	 * repeat access on the same accessor.
	 */
	protected array $proxies = [];

	public function __construct(Page $page, SeoNeo $module) {
		$this->page = $page;
		$this->module = $module;
	}

	public function __get($key) {
		// Namespace proxies — `$page->seoneo->og`, `->twitter`, etc.
		// `opengraph` is a deliberate alias for `og` to give SeoMaestro users
		// a near-zero-friction migration (their templates use `->opengraph`).
		switch($key) {
			case 'og':
			case 'opengraph':
				return $this->proxies['og']
					??= new SeoNeoOgAccessor($this->page, $this->module);
			case 'twitter':
				return $this->proxies['twitter']
					??= new SeoNeoTwitterAccessor($this->page, $this->module);
			case 'hreflang':
				return $this->proxies['hreflang']
					??= new SeoNeoHreflangAccessor($this->page, $this->module);
			case 'verification':
				return $this->proxies['verification']
					??= new SeoNeoVerificationAccessor($this->page, $this->module);
			case 'schema':
				return $this->proxies['schema']
					??= new SeoNeoSchemaAccessor($this->page, $this->module);
		}

		return match($key) {
			// Single-value (string) getters.
			'title'              => $this->module->getTitle($this->page),
			'description'        => $this->module->getDescription($this->page),
			'canonical'          => $this->module->getCanonical($this->page),
			'robots'             => $this->module->getRobots($this->page),
			'author'             => $this->module->getAuthor($this->page),
			'keywords'           => $this->module->getKeywords($this->page),
			'ogTitle'            => $this->module->getOgTitle($this->page),
			'ogImage'            => $this->module->getOgImage($this->page),
			'ogType'             => $this->module->getOgType($this->page),
			'ogLocale'           => $this->module->getOgLocale($this->page),
			'siteName'           => $this->module->getSiteName(),
			'twitterSite'        => $this->module->getTwitterSite($this->page),
			'twitterCreator'     => $this->module->getTwitterCreator($this->page),
			'hreflangCode'       => $this->module->getHreflangCode(),
			// Multi-value (array) getters — for sites that need to iterate
			// or pass structured data to JSON-LD, sitemaps, custom UI, etc.
			'authors'            => $this->module->getAuthors($this->page),
			'keywordsList'       => $this->module->getKeywordsList($this->page),
			'aiDirectives'         => $this->module->getAiDirectives($this->page),
			'robotsDirectives'     => $this->module->getRobotsDirectives($this->page),
			'articleAuthors'       => $this->module->getArticleAuthors($this->page),
			'articlePublishedTime' => $this->module->getArticlePublishedTime($this->page),
			'articleModifiedTime'  => $this->module->getArticleModifiedTime($this->page),
			'ogImageData'        => $this->module->getOgImageData($this->page),
			'ogLocaleAlternates' => $this->module->getOgLocaleAlternates($this->page),
			'verifications'      => $this->module->getVerifications(),
			'hreflangAlternates' => $this->module->getHreflangAlternates($this->page),
			// `schemaGraph` is the flat-array form of the JSON-LD @graph —
			// kept alongside the `schema` namespace so callers can grab the
			// raw array without going through `->schema->graph` if they
			// prefer the original flat-accessor style.
			'schemaGraph'        => $this->module->getJsonLd($this->page),
			default              => parent::__get($key),
		};
	}

	public function render(): string {
		return $this->module->renderHead($this->page);
	}

	public function renderTitle(): string {
		return $this->module->renderTitle($this->page);
	}

	public function renderDescription(): string {
		return $this->module->renderDescription($this->page);
	}

	public function renderCanonical(): string {
		return $this->module->renderCanonical($this->page);
	}

	public function renderRobots(): string {
		return $this->module->renderRobots($this->page);
	}

	public function renderOg(): string {
		return $this->module->renderOg($this->page);
	}

	public function renderTwitter(): string {
		return $this->module->renderTwitter($this->page);
	}

	public function renderHreflang(): string {
		return $this->module->renderHreflang($this->page);
	}

	public function renderVerification(): string {
		return $this->module->renderVerification($this->page);
	}

	public function renderAuthor(): string {
		return $this->module->renderAuthor($this->page);
	}

	public function renderSchema(): string {
		return $this->module->renderSchema($this->page);
	}

	public function __toString(): string {
		return $this->render();
	}
}


// ────────────────────────────────────────────────────────────────────────
//  Namespace proxies — $page->seoneo->og / ->twitter / ->hreflang / etc.
// ────────────────────────────────────────────────────────────────────────
//
// Each proxy is a lightweight read-only object that:
//   - has its own `render()` method delegating to the matching `___render*`
//     hookable on the SeoNeo module, so `addHookAfter('SeoNeo::renderOg', …)`
//     continues to apply transparently;
//   - exposes a small set of value getters via `__get`, sourcing data from
//     the same `___get*` hookable methods as the flat accessor — so any
//     hook on the resolver applies to both surfaces;
//   - implements `__toString` so `echo $page->seoneo->og;` works, mirroring
//     SeoMaestro's `echo $page->seo->opengraph;` ergonomic.
//
// The flat accessor surface (`->renderOg()`, `->ogTitle`, …) is unchanged —
// these namespaces are purely additive and exist primarily so that
// templates ported from SeoMaestro work after a `$page->seo` →
// `$page->seoneo` find/replace, without rewriting the partial-render
// shape (`->opengraph->render()` etc.).
// ────────────────────────────────────────────────────────────────────────

abstract class SeoNeoNamespaceAccessor extends Wire {
	protected Page $page;
	protected SeoNeo $module;

	public function __construct(Page $page, SeoNeo $module) {
		$this->page = $page;
		$this->module = $module;
	}

	abstract public function render(): string;

	public function __toString(): string {
		try {
			return $this->render();
		} catch(\Throwable $e) {
			return '';
		}
	}
}

class SeoNeoOgAccessor extends SeoNeoNamespaceAccessor {

	public function render(): string {
		return $this->module->renderOg($this->page);
	}

	public function __get($key) {
		return match($key) {
			'title'                => $this->module->getOgTitle($this->page),
			'description'          => $this->module->getDescription($this->page),
			'image'                => $this->module->getOgImage($this->page),
			'imageData'            => $this->module->getOgImageData($this->page),
			'type'                 => $this->module->getOgType($this->page),
			'locale'               => $this->module->getOgLocale($this->page),
			'localeAlternates'     => $this->module->getOgLocaleAlternates($this->page),
			'siteName'             => $this->module->getSiteName(),
			'url'                  => $this->module->getCanonical($this->page),
			'articleAuthors'       => $this->module->getArticleAuthors($this->page),
			'articlePublishedTime' => $this->module->getArticlePublishedTime($this->page),
			'articleModifiedTime'  => $this->module->getArticleModifiedTime($this->page),
			default                => parent::__get($key),
		};
	}
}

class SeoNeoTwitterAccessor extends SeoNeoNamespaceAccessor {

	public function render(): string {
		return $this->module->renderTwitter($this->page);
	}

	public function __get($key) {
		// `title` / `description` / `image` mirror their OG counterparts —
		// Twitter cards inherit those by spec, so we surface them here too
		// to spare callers from juggling `$seoneo->og->title` when the
		// intent is `$seoneo->twitter->title`.
		return match($key) {
			'site'        => $this->module->getTwitterSite($this->page),
			'creator'     => $this->module->getTwitterCreator($this->page),
			'title'       => $this->module->getOgTitle($this->page),
			'description' => $this->module->getDescription($this->page),
			'image'       => $this->module->getOgImage($this->page),
			'url'         => $this->module->getCanonical($this->page),
			default       => parent::__get($key),
		};
	}
}

class SeoNeoHreflangAccessor extends SeoNeoNamespaceAccessor {

	public function render(): string {
		return $this->module->renderHreflang($this->page);
	}

	public function __get($key) {
		return match($key) {
			'code'       => $this->module->getHreflangCode(),
			'alternates' => $this->module->getHreflangAlternates($this->page),
			default      => parent::__get($key),
		};
	}
}

class SeoNeoVerificationAccessor extends SeoNeoNamespaceAccessor {

	public function render(): string {
		return $this->module->renderVerification($this->page);
	}

	public function __get($key) {
		return match($key) {
			'tokens' => $this->module->getVerifications(),
			default  => parent::__get($key),
		};
	}
}

class SeoNeoSchemaAccessor extends SeoNeoNamespaceAccessor implements \JsonSerializable {

	public function render(): string {
		return $this->module->renderSchema($this->page);
	}

	public function __get($key) {
		$data = $this->module->getJsonLd($this->page);
		return match($key) {
			// `graph` is the @graph array (the items themselves). `context`
			// is the @context URL. `data` is the whole JSON-LD payload —
			// equivalent to the original flat `$page->seoneo->schema`
			// array form (kept available for callers that need it).
			'graph'   => isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [],
			'context' => $data['@context'] ?? '',
			'data'    => $data,
			default   => parent::__get($key),
		};
	}

	/**
	 * `json_encode($page->seoneo->schema)` serialises to the full JSON-LD
	 * payload (`{"@context": …, "@graph": […]}`), matching the original
	 * flat-array behaviour for callers that pipe the data into their own
	 * `<script type="application/ld+json">` block.
	 */
	public function jsonSerialize(): mixed {
		return $this->module->getJsonLd($this->page);
	}
}
