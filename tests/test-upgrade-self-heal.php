<?php namespace ProcessWire;

/**
 * SEO Neo upgrade self-heal smoke test (1.0.0).
 *
 * Runs from a PW site root. Verifies:
 *
 *   1. SeoNeo module loads and reports version >= 1.0.0.
 *   2. The companion InputfieldSeoNeoPreview module is installed.
 *   3. The `seoneo_preview` field has `inputfieldClass = InputfieldSeoNeoPreview`
 *      and `collapsed = Inputfield::collapsedNever`.
 *   4. As a soak: invoke the helpers directly via reflection and confirm they
 *      are idempotent (return 0 on a healthy install).
 *   5. Optionally render the SERP preview against a page and confirm
 *      `.seoneo-serp-wrap` appears in the output (i.e. the Inputfield is
 *      actually wired, not a plain InputfieldText).
 *
 * USAGE:
 *
 *   cd /path/to/pw-site
 *   php /path/to/SeoNeo/tests/test-upgrade-self-heal.php [page]
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only.');
}

ini_set('memory_limit', '512M');

$cwd = getcwd();
$indexPath = $cwd . '/index.php';
if (!is_file($indexPath)) {
	fwrite(STDERR, "FATAL: no index.php in current directory ($cwd).\n");
	fwrite(STDERR, "Run this script from a ProcessWire site root.\n");
	exit(1);
}

require $indexPath;

$superuser = wire('users')->get("roles=superuser, include=all");
if (!$superuser || !$superuser->id) {
	fwrite(STDERR, "FATAL: no superuser account in this install.\n");
	exit(1);
}
wire('users')->setCurrentUser($superuser);

$pageArg = isset($argv[1]) ? (string) $argv[1] : '1';
$page = ctype_digit($pageArg)
	? wire('pages')->get((int) $pageArg)
	: wire('pages')->get($pageArg);
if (!$page->id) {
	fwrite(STDERR, "FATAL: page '$pageArg' does not exist.\n");
	exit(1);
}

echo "═══ SEO NEO UPGRADE SELF-HEAL SMOKE TEST ═══\n";
echo "PHP=" . PHP_VERSION . "\n";
echo "site=$cwd\n";
echo "page=#{$page->id} \"{$page->title}\" (template={$page->template->name})\n\n";

$failures = 0;
$pass = function(string $label) { echo "  PASS  $label\n"; };
$fail = function(string $label, string $detail = '') use (&$failures) {
	echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
	$failures++;
};

// ── 1. SeoNeo loads, reports >= 1.0.0 ────────────────────────────────

$seoneo = wire('modules')->get('SeoNeo');
if (!$seoneo) {
	$fail('SeoNeo module loaded', 'module not installed');
	echo "\nABORT — cannot continue without SeoNeo.\n";
	exit(1);
}
$info = wire('modules')->getModuleInfo($seoneo->className());
$version = (string) ($info['version'] ?? '');
if ($version === '') {
	$fail('SeoNeo version reported', 'getModuleInfo returned no version');
} elseif (version_compare($version, '1.0.0', '>=')) {
	$pass("SeoNeo version $version >= 1.0.0");
} else {
	$fail("SeoNeo version $version >= 1.0.0", "got $version");
}

// ── 2. Companion Inputfield installed ────────────────────────────────

if (wire('modules')->isInstalled('InputfieldSeoNeoPreview')) {
	$pass('InputfieldSeoNeoPreview is installed');
} else {
	$fail('InputfieldSeoNeoPreview is installed');
}

// ── 3. seoneo_preview field wiring ───────────────────────────────────

$field = wire('fields')->get('seoneo_preview');
if (!$field) {
	$fail('seoneo_preview field exists');
} else {
	$pass('seoneo_preview field exists');

	$ifc = (string) $field->inputfieldClass;
	if ($ifc === 'InputfieldSeoNeoPreview') {
		$pass("seoneo_preview->inputfieldClass = InputfieldSeoNeoPreview");
	} else {
		$fail("seoneo_preview->inputfieldClass = InputfieldSeoNeoPreview", "got '$ifc'");
	}

	$col = (int) $field->collapsed;
	if ($col === Inputfield::collapsedNever) {
		$pass("seoneo_preview->collapsed = collapsedNever ({$col})");
	} else {
		$fail("seoneo_preview->collapsed = collapsedNever", "got {$col}");
	}
}

// ── 3b. seoneo_tab presentation (must not be collapsedTab) ───────────

$tabField = wire('fields')->get('seoneo_tab');
if (!$tabField) {
	$fail('seoneo_tab field exists');
} else {
	$pass('seoneo_tab field exists');
	$col = (int) $tabField->collapsed;
	$badTabCollapsed = [
		Inputfield::collapsedTab,
		Inputfield::collapsedTabAjax,
		Inputfield::collapsedTabLocked,
	];
	if (!in_array($col, $badTabCollapsed, true)) {
		$pass("seoneo_tab->collapsed is not a tab-only presentation mode ({$col})");
	} else {
		$fail('seoneo_tab->collapsed must not be collapsedTab', "got {$col}");
	}
}

// ── 4. Helpers are idempotent on a healthy install ───────────────────

$ref = new \ReflectionClass($seoneo);
foreach (['ensurePreviewFieldInputfield', 'ensurePreviewInputfieldInstalled', 'ensureSeoTabFieldConfig', 'repairSeoFieldgroups'] as $methodName) {
	if (!$ref->hasMethod($methodName)) {
		$fail("$methodName() exists");
		continue;
	}
	$method = $ref->getMethod($methodName);
	$method->setAccessible(true);
	$ret = (int) $method->invoke($seoneo);
	if ($ret === 0) {
		$pass("$methodName() is no-op on healthy install (returned 0)");
	} else {
		// A non-zero return on first run is fine and expected on a stale install;
		// running it again should now be a no-op.
		echo "  NOTE  $methodName() returned $ret (helper ran a repair); re-running…\n";
		$ret2 = (int) $method->invoke($seoneo);
		if ($ret2 === 0) {
			$pass("$methodName() second-run idempotent");
		} else {
			$fail("$methodName() second-run idempotent", "returned $ret2");
		}
	}
}

// ── 5. SERP Inputfield actually renders the card markup ──────────────

$preview = wire('modules')->get('InputfieldSeoNeoPreview');
if (!$preview) {
	$fail('InputfieldSeoNeoPreview instantiates via $modules->get()');
} else {
	$pass('InputfieldSeoNeoPreview instantiates via $modules->get()');

	// Mimic ProcessPageEdit's $process so getEditedPage() returns our page.
	wire()->set('input', wire('input'));
	wire('input')->get->id = $page->id;

	$html = (string) $preview->render();
	if (strpos($html, 'seoneo-serp-wrap') !== false
		&& strpos($html, 'seoneo-serp-title') !== false
		&& strpos($html, 'seoneo-serp-description') !== false) {
		$pass('render() emits Google-style SERP card markup');
	} else {
		$fail('render() emits Google-style SERP card markup',
			'missing one of: seoneo-serp-wrap / seoneo-serp-title / seoneo-serp-description');
	}
}

// ── Summary ──────────────────────────────────────────────────────────

echo "\n══════════════════════════════════════════════\n";
if ($failures === 0) {
	echo "ALL CHECKS PASSED.\n";
	exit(0);
}
echo "FAILED: $failures check(s).\n";
exit(1);
