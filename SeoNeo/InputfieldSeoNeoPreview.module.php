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
			'version'  => '1.0.0',
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

		$esc = function($s) {
			return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		};

		$out  = "<div class='seoneo-serp-wrap' ";
		$out .= "data-title-field='" . $esc($titleField) . "' ";
		$out .= "data-desc-field='" . $esc($descField) . "' ";
		$out .= "data-page-url='" . $esc($pageUrl) . "' ";
		$out .= "data-resolved-title='" . $esc($resolvedTitle) . "' ";
		$out .= "data-resolved-desc='" . $esc($resolvedDesc) . "'>";
		$out .= "  <div class='seoneo-serp-preview'>";
		$out .= "    <div class='seoneo-serp-url'>" . $esc($pageUrl) . "</div>";
		$out .= "    <div class='seoneo-serp-title'>" . $esc($this->truncate($resolvedTitle, 60)) . "</div>";
		$out .= "    <div class='seoneo-serp-description'>" . $esc($this->truncate($resolvedDesc, 160)) . "</div>";
		$out .= "  </div>";

		$out .= $this->renderEffectiveValuesPanel([
			'title'      => $resolvedTitle,
			'desc'       => $resolvedDesc,
			'canonical'  => $canonical,
			'robots'     => $robots,
			'og_type'    => $ogType,
			'og_image'   => $ogImage,
			'site_name'  => $siteName,
		]);

		$out .= "</div>";

		$this->value = $out;
		return parent::___render();
	}

	/**
	 * Renders a small "Effective values" disclosure panel beneath the SERP
	 * preview, showing the values SeoNeo will actually output. Especially
	 * useful when smart-map fallbacks or template defaults are doing the
	 * heavy lifting and the per-page SEO fields are blank.
	 */
	protected function renderEffectiveValuesPanel(array $r): string {
		$esc = function($s) {
			return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		};
		$row = function(string $label, string $value, string $cls = '') use ($esc) {
			$shown = $value === '' ? '<em style="color:#999">' . $this->_('(empty — tag will be skipped)') . '</em>' : $esc($value);
			return '<tr><th style="text-align:left;font-weight:500;color:#666;width:8.5em;padding:.25em 0">'
				. $esc($label) . '</th><td style="padding:.25em 0">'
				. ($cls ? '<span class="' . $cls . '">' . $shown . '</span>' : $shown)
				. '</td></tr>';
		};

		$ogImg = $r['og_image'] !== ''
			? '<a href="' . $esc($r['og_image']) . '" target="_blank" rel="noopener" style="text-decoration:none">'
				. '<img src="' . $esc($r['og_image']) . '" alt="" style="max-width:120px;max-height:80px;border:1px solid #ddd;border-radius:3px;vertical-align:middle;margin-right:.6em">'
				. '<span style="color:#666;font-size:11px;word-break:break-all">' . $esc($r['og_image']) . '</span>'
				. '</a>'
			: '<em style="color:#999">' . $this->_('(no image — twitter:card falls back to "summary")') . '</em>';

		$out  = '<details class="seoneo-effective" style="margin-top:.75em;font-size:13px;color:#444">';
		$out .= '<summary style="cursor:pointer;color:#666;user-select:none">' . $this->_('Effective values') . ' <span style="color:#999;font-size:11px">— ' . $this->_('what SeoNeo will actually emit') . '</span></summary>';
		$out .= '<div style="padding:.5em .25em 0">';
		$out .= '<table style="border-collapse:collapse;width:100%">';
		$out .= $row($this->_('Title'),       $r['title']);
		$out .= $row($this->_('Description'), $r['desc']);
		$out .= $row($this->_('Canonical'),   $r['canonical']);
		$out .= $row($this->_('Robots'),      $r['robots']);
		$out .= $row($this->_('OG type'),     $r['og_type']);
		$out .= $row($this->_('Site name'),   $r['site_name']);
		$out .= '<tr><th style="text-align:left;font-weight:500;color:#666;width:8.5em;padding:.25em 0;vertical-align:top">'
			. $esc($this->_('OG image')) . '</th><td style="padding:.25em 0">' . $ogImg . '</td></tr>';
		$out .= '</table>';
		$out .= '</div></details>';
		return $out;
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
