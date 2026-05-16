<?php namespace ProcessWire;

/**
 * InputfieldSeoNeoPreview
 *
 * Display-only Inputfield that renders a Google SERP preview inside the
 * SeoNeo SEO tab. Stores no data — it reads the mapped SEO fields via
 * the SeoNeo module and renders a preview that JS updates live as the
 * editor types.
 */
class InputfieldSeoNeoPreview extends InputfieldMarkup {

	public static function getModuleInfo() {
		return [
			'title'    => 'SeoNeo SERP Preview',
			'version'  => '1.0.1',
			'summary'  => 'Display-only SERP preview widget for the SeoNeo SEO tab.',
			'icon'     => 'eye',
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0', 'SeoNeo'],
		];
	}

	public function ___render() {
		$module = $this->wire('modules')->get('SeoNeo');
		$page = $this->getEditedPage();

		$pageUrl = '';
		$resolvedTitle = '';
		$resolvedDesc = '';
		$canonical = '';
		$robots = '';
		$ogType = '';
		$ogImage = '';
		$siteName = '';

		if($page && $page->id && $module instanceof SeoNeo) {
			$pageUrl = (string) $page->httpUrl;
			$resolvedTitle = $module->getTitle($page);
			$resolvedDesc = $module->getDescription($page);
			$canonical = $module->getCanonical($page);
			$robots = $module->getRobots($page);
			$ogType = $module->getOgType($page);
			$siteName = $module->getSiteName();
			$ogData = $module->getOgImageData($page);
			$ogImage = (string) ($ogData['url'] ?? '');
		}

		$titleField = $module ? ($module->get('role_title') ?: 'seoneo_title') : 'seoneo_title';
		$descField = $module ? ($module->get('role_description') ?: 'seoneo_description') : 'seoneo_description';

		// Pre-resolve per-language values so the language switcher can swap
		// the preview without a round-trip. We briefly swap $user->language
		// during resolution so the existing resolver chain (getTitle, etc.)
		// returns the value as it would be rendered under that language;
		// the user language is restored before the method returns so nothing
		// downstream sees a side effect.
		$langPayload = [];
		$currentLangId = 0;
		if($module instanceof SeoNeo && $page && $page->id) {
			$langs = $this->wire('languages');
			if($langs && count($langs) > 1) {
				$user = $this->wire('user');
				$savedLang = $user->language;
				$currentLangId = $savedLang && $savedLang->id ? (int) $savedLang->id : 0;
				$defaultLang = $langs->getDefault();
				foreach($langs as $lang) {
					if(!$lang->id) continue;
					$user->language = $lang;
					$payloadUrl = (string) $page->httpUrl;
					$payloadFavicon = '';
					if($payloadUrl) {
						$parsed = parse_url($payloadUrl);
						$scheme = $parsed['scheme'] ?? 'https';
						$host   = $parsed['host'] ?? '';
						$port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
						$payloadFavicon = $scheme . '://' . $host . $port . '/favicon.ico';
					}
					$payloadHost = $payloadUrl ? (parse_url($payloadUrl, PHP_URL_HOST) ?? '') : '';
					$payloadSite = $module->getSiteName();
					$langPayload[] = [
						'id'        => (int) $lang->id,
						'name'      => (string) $lang->name,
						'title'     => $module->getTitle($page),
						'desc'      => $module->getDescription($page),
						'url'       => $payloadUrl,
						'favicon'   => $payloadFavicon,
						'host'      => $payloadHost,
						'siteName'  => $payloadSite ?: $payloadHost,
						'isDefault' => ($defaultLang && $lang->id === $defaultLang->id),
					];
				}
				$user->language = $savedLang;
			}
		}

		// Build favicon URL from the page URL; show the URL as-is (CSS truncates)
		$faviconUrl = '';
		$breadcrumb = $pageUrl;
		$hostOnly = '';
		if($pageUrl) {
			$parsed = parse_url($pageUrl);
			$scheme  = $parsed['scheme'] ?? 'https';
			$host    = $parsed['host'] ?? '';
			$port    = isset($parsed['port']) ? ':' . $parsed['port'] : '';
			$faviconUrl = $scheme . '://' . $host . $port . '/favicon.ico';
			$hostOnly = $host . $port;
		}

		// Use og:site_name if set, otherwise fall back to hostname
		$displayName = $siteName ?: $hostOnly;

		$esc = function($s) {
			return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		};

		$langsAttr = $langPayload
			? htmlspecialchars(json_encode($langPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8')
			: '';

		$out  = "<div class='seoneo-serp-wrap' ";
		$out .= "data-title-field='" . $esc($titleField) . "' ";
		$out .= "data-desc-field='" . $esc($descField) . "' ";
		$out .= "data-page-url='" . $esc($pageUrl) . "' ";
		$out .= "data-resolved-title='" . $esc($resolvedTitle) . "' ";
		$out .= "data-resolved-desc='" . $esc($resolvedDesc) . "' ";
		$out .= "data-host='" . $esc($hostOnly) . "' ";
		$out .= "data-favicon='" . $esc($faviconUrl) . "' ";
		$out .= "data-site-name='" . $esc($displayName) . "' ";
		$out .= "data-surface='desktop' ";
		$out .= "data-current-lang='" . $currentLangId . "' ";
		if($langsAttr !== '') $out .= "data-langs='" . $langsAttr . "' ";
		$out .= ">";

		// Controls row: surface toggle (always) + language switcher (multilingual only).
		// The surface toggle is keyboard-friendly with arrow keys; the language
		// select is a native <select> so it inherits the admin's i18n behaviour.
		$out .= "<div class='seoneo-serp-controls'>";
		$out .= "  <div class='seoneo-serp-surface-toggle' role='tablist' aria-label='" . $esc($this->_('Preview surface')) . "'>";
		$out .= "    <button type='button' class='seoneo-serp-surface-btn' data-surface='desktop' role='tab' aria-selected='true' aria-controls='seoneo-serp-card'>";
		$out .= "      <svg viewBox='0 0 24 24' width='14' height='14' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><rect x='2' y='3' width='20' height='14' rx='2'/><line x1='8' y1='21' x2='16' y2='21'/><line x1='12' y1='17' x2='12' y2='21'/></svg>";
		$out .= "      <span>" . $esc($this->_('Desktop')) . "</span>";
		$out .= "    </button>";
		$out .= "    <button type='button' class='seoneo-serp-surface-btn' data-surface='mobile' role='tab' aria-selected='false' aria-controls='seoneo-serp-card'>";
		$out .= "      <svg viewBox='0 0 24 24' width='14' height='14' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><rect x='7' y='2' width='10' height='20' rx='2'/><line x1='11' y1='18' x2='13' y2='18'/></svg>";
		$out .= "      <span>" . $esc($this->_('Mobile')) . "</span>";
		$out .= "    </button>";
		$out .= "  </div>";

		if(!empty($langPayload)) {
			// Segmented button group — visual sibling of the surface toggle on
			// the same row. One button per active language, label = upper-cased
			// language name (DE / FI / DEFAULT). Active button highlighted in
			// Google-blue.
			$out .= "  <div class='seoneo-serp-lang-toggle' role='tablist' aria-label='" . $esc($this->_('Preview language')) . "'>";
			foreach($langPayload as $p) {
				$selected = $p['id'] === $currentLangId;
				$label = strtoupper($p['name']);
				$title = $p['isDefault'] ? $this->_('Default language') : sprintf($this->_('Preview language: %s'), $p['name']);
				$out .= "    <button type='button' class='seoneo-serp-lang-btn' "
					. "data-lang-id='" . $p['id'] . "' "
					. "role='tab' "
					. "aria-selected='" . ($selected ? 'true' : 'false') . "' "
					. "aria-controls='seoneo-serp-card' "
					. "title='" . $esc($title) . "'>"
					. $esc($label)
					. "</button>";
			}
			$out .= "  </div>";
		}

		$out .= "</div>";

		$out .= "<div class='seoneo-serp-preview' id='seoneo-serp-card'>";

		// Source row: favicon + [site name / breadcrumb] — matches Google desktop layout
		$out .= "<div class='seoneo-serp-source-row'>";
		$out .= "  <span class='seoneo-serp-favicon'>";
		if($faviconUrl) {
			$out .= "<img src='" . $esc($faviconUrl) . "' alt='' loading='lazy' onerror='this.style.display=\"none\"'>";
		}
		$out .= "  </span>";
		$out .= "  <div class='seoneo-serp-source-meta'>";
		$out .= "    <div class='seoneo-serp-site-name'>" . $esc($displayName) . "</div>";
		$out .= "    <div class='seoneo-serp-breadcrumb' data-full='" . $esc($breadcrumb) . "' data-host='" . $esc($hostOnly) . "'>" . $esc($breadcrumb) . "</div>";
		$out .= "  </div>";
		$out .= "</div>";

		$out .= "<div class='seoneo-serp-title'>" . $esc($this->truncate($resolvedTitle, 60)) . "</div>";
		$out .= "<div class='seoneo-serp-description'>" . $esc($this->truncate($resolvedDesc, 160)) . "</div>";
		$out .= "</div>";

		$out .= "</div>";

		$this->value = $out;
		return parent::___render();
	}

	/**
	 * This field stores nothing — skip all processing.
	 */
	public function ___processInput(WireInputData $input) {
		return $this;
	}

	protected function getEditedPage(): ?Page {
		$process = $this->wire('process');
		if($process && $process instanceof ProcessPageEdit) {
			return $process->getPage();
		}
		$id = (int) $this->wire('input')->get('id');
		if($id) return $this->wire('pages')->get($id);
		return null;
	}

	protected function truncate(string $s, int $n): string {
		if(mb_strlen($s) <= $n) return $s;
		return mb_substr($s, 0, $n - 1) . "\u{2026}";
	}
}
