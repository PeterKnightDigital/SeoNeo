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
 *   $page->seoneo->title
 *   $page->seoneo->description
 *   $page->seoneo->canonical
 *   $page->seoneo->robots
 *   $page->seoneo->render()
 *   $page->seoneo->renderTitle()
 */
class SeoNeo extends WireData implements Module, ConfigurableModule {

	const FIELD_PREFIX = 'seoneo_';

	const DEFAULT_FIELDS = [
		'seoneo_tab'         => 'FieldtypeFieldsetTabOpen',
		'seoneo_preview'     => 'InputfieldSeoNeoPreview',
		'seoneo_title'       => 'FieldtypeText',
		'seoneo_description' => 'FieldtypeTextarea',
		'seoneo_canonical'   => 'FieldtypeURL',
		'seoneo_keywords'    => 'FieldtypeText',
		'seoneo_og_image'    => 'FieldtypeImage',
		'seoneo_noindex'     => 'FieldtypeCheckbox',
		'seoneo_nofollow'    => 'FieldtypeCheckbox',
		'seoneo_tab_END'     => 'FieldtypeFieldsetClose',
	];

	const FIELD_LABELS = [
		'seoneo_tab'         => 'SEO',
		'seoneo_preview'     => 'SERP Preview',
		'seoneo_title'       => 'Meta Title',
		'seoneo_description' => 'Meta Description',
		'seoneo_canonical'   => 'Canonical URL',
		'seoneo_keywords'    => 'Meta Keywords',
		'seoneo_og_image'    => 'OG Image',
		'seoneo_noindex'     => 'Noindex',
		'seoneo_nofollow'    => 'Nofollow',
		'seoneo_tab_END'     => '',
	];

	const FIELD_DESCRIPTIONS = [
		'seoneo_title'       => 'Override the page title used in search results. Leave empty to use smart-map fallbacks.',
		'seoneo_description' => 'A short summary for search engine results. Leave empty to use smart-map fallbacks.',
		'seoneo_canonical'   => 'Leave empty to use the page URL automatically.',
		'seoneo_keywords'    => 'Comma-separated keywords. Most search engines no longer use this, but some sites still want it.',
		'seoneo_og_image'    => 'Upload one image to use as the og:image for this page. If empty, SeoNeo falls back to other image fields, then the homepage OG image, then the module default URL.',
		'seoneo_noindex'     => 'Tell search engines not to index this page.',
		'seoneo_nofollow'    => 'Tell search engines not to follow links on this page.',
	];

	public static function getModuleInfo() {
		return [
			'title'    => 'SeoNeo',
			'version'  => '1.1.0',
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
			'title_format'     => '{title}{separator}{site_name}',
			'title_separator'  => ' | ',
			'auto_inject'      => 1,
			'role_title'       => 'seoneo_title',
			'role_description' => 'seoneo_description',
			'role_canonical'   => 'seoneo_canonical',
			'role_noindex'     => 'seoneo_noindex',
			'role_nofollow'    => 'seoneo_nofollow',
			'smart_map_text'   => "title=headline,title\ndescription=summary,body",
			'template_defaults_text' => '',
			'custom_tags_text' => '',
			'og_image_fields'  => 'og_image,screenshot,images,image,blog_images',
			'og_image_default' => '',
			'counter_title_green'  => 60,
			'counter_title_amber'  => 70,
			'counter_desc_green'   => 160,
			'counter_desc_amber'   => 180,
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
	}

	public function ready() {
		if($this->shouldAutoInject()) {
			$this->addHookAfter('Page::render', $this, 'hookPageRenderInject');
		}
		if($this->wire('page') && $this->wire('page')->process == 'ProcessPageEdit') {
			$this->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'hookInjectAssets');
		}
	}

	// ────────────────────────────────────────────────────────────────────
	//  Install / Uninstall
	// ────────────────────────────────────────────────────────────────────

	public function ___install() {
		$fields = $this->wire('fields');
		$modules = $this->wire('modules');
		$hasLanguage = $this->wire('languages') && count($this->wire('languages')) > 0;

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

			$f->label = self::FIELD_LABELS[$name] ?? $name;
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

			if($name === 'seoneo_tab_END') {
				$tabField = $fields->get('seoneo_tab');
				if($tabField) $f->set('field_seoneo_tab', $tabField->id);
			}

			$f->tags = 'SeoNeo';
			$f->save();
		}

		$this->message($this->_('SeoNeo fields created. Add seoneo_tab to your templates to enable SEO editing.'));
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

		$event->return = preg_replace(
			'~</head>~i',
			$block . "\n</head>",
			$html,
			1
		);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Asset injection for page editor
	// ────────────────────────────────────────────────────────────────────

	public function hookInjectAssets(HookEvent $event) {
		$config = $this->wire('config');
		$url = $config->urls($this->className()) ?: $config->urls->siteModules . 'SeoNeo/';
		$v = $this->getModuleInfo()['version'] ?? '1.0.0';
		$config->styles->add($url . "assets/SeoNeo.css?v=$v");
		$config->scripts->add($url . "assets/SeoNeo.js?v=$v");

		$pageUrl = '';
		$process = $this->wire('process');
		if($process && $process instanceof ProcessPageEdit) {
			$editPage = $process->getPage();
			if($editPage && $editPage->id) $pageUrl = (string) $editPage->httpUrl;
		}

		$canonicalField = $this->get('role_canonical') ?: 'seoneo_canonical';

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

		if($raw === '') $raw = $this->resolveSmartMap($page, 'description');
		if($raw === '') $raw = $this->renderTemplateDefault($page, 'description');
		return $raw;
	}

	public function ___getCanonical(Page $page): string {
		$field = $this->get('role_canonical') ?: 'seoneo_canonical';
		$raw = $this->readField($page, $field);
		if($raw !== '') return $raw;
		return (string) $page->httpUrl;
	}

	public function ___getRobots(Page $page): string {
		$noindexField = $this->get('role_noindex') ?: 'seoneo_noindex';
		$nofollowField = $this->get('role_nofollow') ?: 'seoneo_nofollow';

		$noindex = $page->template->hasField($noindexField) ? (int) $page->get($noindexField) : 0;
		$nofollow = $page->template->hasField($nofollowField) ? (int) $page->get($nofollowField) : 0;

		return ($noindex ? 'noindex' : 'index') . ',' . ($nofollow ? 'nofollow' : 'follow');
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
		// 1. Dedicated per-page OG image field (highest priority)
		if($page->template->hasField('seoneo_og_image')) {
			$val = $page->get('seoneo_og_image');
			if($val instanceof Pageimage) return (string) $val->httpUrl;
			if($val instanceof Pageimages && $val->count()) return (string) $val->first()->httpUrl;
		}

		// 2. Scan configured image fields (MediaHub, per-template images, etc.)
		$fieldNames = array_map('trim', explode(',', (string) $this->get('og_image_fields')));
		foreach($fieldNames as $name) {
			if($name === '' || !$page->template->hasField($name)) continue;
			$val = $page->get($name);
			if($val instanceof Pageimages && $val->count()) {
				return (string) $val->first()->httpUrl;
			} elseif($val instanceof Pageimage) {
				return (string) $val->httpUrl;
			}
		}

		// 3. Homepage seoneo_og_image as site-wide default (skip if we're already on the homepage)
		$homepage = $this->wire('pages')->get(1);
		if($homepage && $homepage->id && $homepage->id !== $page->id && $homepage->template->hasField('seoneo_og_image')) {
			$val = $homepage->get('seoneo_og_image');
			if($val instanceof Pageimage) return (string) $val->httpUrl;
			if($val instanceof Pageimages && $val->count()) return (string) $val->first()->httpUrl;
		}

		// 4. Last resort: configured URL in module settings
		return (string) $this->get('og_image_default');
	}

	// ────────────────────────────────────────────────────────────────────
	//  Rendering
	// ────────────────────────────────────────────────────────────────────

	public function ___renderTitle(Page $page): string {
		$title = $this->getTitle($page);
		if($title === '') return '';
		return '<title>' . $this->esc($title) . '</title>';
	}

	public function ___renderHead(Page $page): string {
		$lines = ['<!-- SeoNeo -->'];

		$title = $this->getTitle($page);
		if($title !== '') {
			$lines[] = '<title>' . $this->esc($title) . '</title>';
		}

		$desc = $this->getDescription($page);
		if($desc !== '') {
			$lines[] = '<meta name="description" content="' . $this->esc($desc) . '">';
		}

		$canonical = $this->getCanonical($page);
		if($canonical !== '') {
			$lines[] = '<link rel="canonical" href="' . $this->esc($canonical) . '">';
		}

		$robots = $this->getRobots($page);
		if($robots !== 'index,follow') {
			$lines[] = '<meta name="robots" content="' . $this->esc($robots) . '">';
		}

		if($page->template->hasField('seoneo_keywords')) {
			$kw = trim((string) $page->get('seoneo_keywords'));
			if($kw !== '') {
				$lines[] = '<meta name="keywords" content="' . $this->esc($kw) . '">';
			}
		}

		// Open Graph
		$ogTitle = $this->getOgTitle($page);
		if($ogTitle !== '') {
			$lines[] = '<meta property="og:title" content="' . $this->esc($ogTitle) . '">';
		}
		if($desc !== '') {
			$lines[] = '<meta property="og:description" content="' . $this->esc($desc) . '">';
		}
		$lines[] = '<meta property="og:url" content="' . $this->esc($canonical) . '">';
		$lines[] = '<meta property="og:type" content="website">';
		$siteName = (string) $this->get('site_name');
		if($siteName !== '') {
			$lines[] = '<meta property="og:site_name" content="' . $this->esc($siteName) . '">';
		}
		$ogImage = $this->getOgImage($page);
		if($ogImage !== '') {
			$lines[] = '<meta property="og:image" content="' . $this->esc($ogImage) . '">';
		}
		$lines[] = '<meta name="twitter:card" content="' . ($ogImage ? 'summary_large_image' : 'summary') . '">';

		foreach($this->getCustomTagMappings() as $fieldName => $tagTemplate) {
			if(!$page->template->hasField($fieldName)) continue;
			$val = trim((string) $page->get($fieldName));
			if($val === '') continue;
			$lines[] = sprintf($tagTemplate, $this->esc($val));
		}

		$alts = $this->renderHreflangAlternates($page);
		if($alts !== '') $lines[] = $alts;

		$lines[] = '<!-- /SeoNeo -->';
		return implode("\n", array_filter($lines));
	}

	public function ___renderHreflangAlternates(Page $page): string {
		$langs = $this->wire('languages');
		if(!$langs || count($langs) < 2) return '';

		$out = [];
		$user = $this->wire('user');
		$origLang = $user->language;
		try {
			foreach($langs as $lang) {
				if(!$page->viewable($lang)) continue;
				$user->language = $lang;
				$href = $page->httpUrl;
				$code = $this->wire('sanitizer')->name($lang->name);
				$out[] = '<link rel="alternate" hreflang="' . $this->esc($code) . '" href="' . $this->esc($href) . '">';
			}
		} finally {
			$user->language = $origLang;
		}
		return implode("\n", $out);
	}

	// ────────────────────────────────────────────────────────────────────
	//  Title formatting
	// ────────────────────────────────────────────────────────────────────

	public function ___formatTitle(string $rawTitle): string {
		$format = (string) $this->title_format;
		if($format === '') $format = '{title}';

		$siteName = (string) $this->site_name;
		$separator = $siteName === '' ? '' : (string) $this->title_separator;

		$out = strtr($format, [
			'{title}'     => $rawTitle,
			'{site_name}' => $siteName,
			'{separator}' => $separator,
		]);

		$out = trim($out);
		$sep = trim((string) $this->title_separator);
		if($sep !== '') {
			$pattern = '/^' . preg_quote($sep, '/') . '+|' . preg_quote($sep, '/') . '+$/u';
			$out = trim(preg_replace($pattern, '', $out));
		}
		return $out;
	}

	// ────────────────────────────────────────────────────────────────────
	//  Smart-map
	// ────────────────────────────────────────────────────────────────────

	public function ___resolveSmartMap(Page $page, string $key): string {
		$map = $this->getSmartMap();
		if(!isset($map[$key]) || !is_array($map[$key])) return '';

		foreach($map[$key] as $fieldName) {
			$fieldName = trim($fieldName);
			if($fieldName === '' || !$page->template->hasField($fieldName)) continue;
			$val = $page->get($fieldName);
			if($val === null || $val === '') continue;
			if(is_object($val)) {
				$val = method_exists($val, '__toString') ? (string) $val : '';
			} else {
				$val = (string) $val;
			}
			$val = trim(strip_tags($val));
			if($val !== '') return $val;
		}
		return '';
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
		$f->description = $this->_('Placeholders: {title}, {separator}, {site_name}');
		$f->value = $this->title_format;
		$inputfields->add($f);

		// -- Behaviour ----------------------------------------------------

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'auto_inject';
		$f->label = $this->_('Auto-inject meta tags into <head>');
		$f->label2 = $this->_('Enabled');
		$f->description = $this->_('When enabled, SeoNeo injects the meta block before </head> on front-end pages. Disable to call $page->seoneo->render() manually.');
		if((int) $this->auto_inject === 1) $f->attr('checked', 'checked');
		$inputfields->add($f);

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
			'Supported keys: title, description.'
		);
		$f->notes = $this->_("Example:\ntitle=headline,title\ndescription=summary,body");
		$f->value = $this->get('smart_map_text');
		$f->rows = 4;
		$inputfields->add($f);

		// -- Template defaults --------------------------------------------

		$f = $modules->get('InputfieldTextarea');
		$f->name = 'template_defaults_text';
		$f->label = $this->_('Per-template defaults');
		$f->description = $this->_(
			'Default meta values per template. Format: [template-name] then key=value lines. ' .
			'Placeholders: {title}, {site_name}, {page.fieldname}.'
		);
		$f->notes = $this->_("Example:\n[home]\ndescription=Welcome to {site_name}.\n\n[basic-page]\ndescription={page.summary}");
		$f->value = $this->get('template_defaults_text');
		$f->rows = 8;
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
			'<li><strong>Homepage seoneo_og_image</strong> — used as the site-wide default if set on the home page</li>' .
			'<li><strong>Default OG image URL</strong> — the URL below, last resort</li>' .
			'</ol>';
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->name = 'og_image_fields';
		$f->label = $this->_('Image field scan order');
		$f->description = $this->_('Comma-separated list of PW image field names to scan (step 2). The first field on the page that contains an image wins.');
		$f->value = $this->get('og_image_fields');
		$fs->add($f);

		$f = $modules->get('InputfieldURL');
		$f->name = 'og_image_default';
		$f->label = $this->_('Default OG image URL');
		$f->description = $this->_('Absolute URL used as a last resort if no image is found anywhere. Recommended size: 1200×630px.');
		$f->value = $this->get('og_image_default');
		$fs->add($f);

		$inputfields->add($fs);

		// -- Counter thresholds -------------------------------------------

		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Character counter thresholds');
		$fs->collapsed = Inputfield::collapsedYes;

		foreach([
			'counter_title_green'  => ['Title green limit', 60],
			'counter_title_amber'  => ['Title amber limit', 70],
			'counter_desc_green'   => ['Description green limit', 160],
			'counter_desc_amber'   => ['Description amber limit', 180],
		] as $key => [$label, $default]) {
			$f = $modules->get('InputfieldInteger');
			$f->name = $key;
			$f->label = $this->_($label);
			$f->value = $this->get($key) ?: $default;
			$f->columnWidth = 25;
			$fs->add($f);
		}

		$inputfields->add($fs);

		return $inputfields;
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

	protected function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	protected function expandTemplateString(string $tpl, Page $page): string {
		$out = strtr($tpl, [
			'{title}'     => (string) $page->title,
			'{site_name}' => (string) $this->site_name,
		]);
		$out = preg_replace_callback('/\{page\.([a-zA-Z0-9_]+)\}/', function($m) use ($page) {
			$v = $page->get($m[1]);
			if(is_object($v) && !method_exists($v, '__toString')) return '';
			return is_scalar($v) || (is_object($v) && method_exists($v, '__toString')) ? (string) $v : '';
		}, $out);
		return trim($out);
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

	public function __construct(Page $page, SeoNeo $module) {
		$this->page = $page;
		$this->module = $module;
	}

	public function __get($key) {
		return match($key) {
			'title'       => $this->module->getTitle($this->page),
			'description' => $this->module->getDescription($this->page),
			'canonical'   => $this->module->getCanonical($this->page),
			'robots'      => $this->module->getRobots($this->page),
			default       => parent::__get($key),
		};
	}

	public function render(): string {
		return $this->module->renderHead($this->page);
	}

	public function renderTitle(): string {
		return $this->module->renderTitle($this->page);
	}

	public function __toString(): string {
		return $this->render();
	}
}
