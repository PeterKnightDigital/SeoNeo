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

		if($page && $page->id && $module instanceof SeoNeo) {
			$pageUrl = (string) $page->httpUrl;
			$resolvedTitle = $module->getTitle($page);
			$resolvedDesc = $module->getDescription($page);
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
