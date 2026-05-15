<?php namespace ProcessWire;

/**
 * SEO Neo hook smoke test.
 *
 * Proves that the public ___renderXxx() partial render methods on SeoNeo
 * are hookable in the standard ProcessWire way, both when called directly
 * on the module and when reached via the $page->seoneo accessor.
 *
 * Three independent checks:
 *
 *   A. addHookAfter on a NEW partial (renderOg) — appends a marker,
 *      verifies the marker appears in both the direct module call AND
 *      the accessor path, then removes the hook and verifies output
 *      reverts to the pre-hook baseline.
 *
 *   B. addHookBefore on a NEW partial (renderCanonical) with $event->replace
 *      — short-circuits the original implementation entirely, verifies the
 *      replacement value is what's returned, then removes the hook and
 *      verifies output reverts.
 *
 *   C. Pre-existing ___renderTitle is still hookable (no regression from
 *      the K7 refactor). addHookAfter rewrites the inner <title>, then
 *      removes the hook and verifies reversion.
 *
 * USAGE:
 *
 *   Must be run from inside a PW site root (the directory containing
 *   index.php and the wire/, site/ folders). SeoNeo must be installed.
 *
 *     cd /path/to/pw-site
 *     php /path/to/SeoNeo/tests/test-hooks.php [page]
 *
 *   page  optional, defaults to 1 (homepage). Numeric ID or page path.
 *
 * Exit code is 0 if all three checks pass, 1 otherwise (CI-friendly).
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
	fwrite(STDERR, "Run this script from a ProcessWire site root, not from the SeoNeo repo.\n");
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
if (ctype_digit($pageArg)) {
	$page = wire('pages')->get((int) $pageArg);
} else {
	$page = wire('pages')->get($pageArg);
}
if (!$page->id) {
	fwrite(STDERR, "FATAL: page '$pageArg' does not exist.\n");
	exit(1);
}

$seoneo = wire('modules')->get('SeoNeo');
if (!$seoneo) {
	fwrite(STDERR, "FATAL: SeoNeo module is not installed on this site.\n");
	exit(1);
}

$wire = wire();

echo "═══ SEO NEO HOOK SMOKE TEST — START ═══\n";
echo "page    = #{$page->id} \"{$page->title}\"  (template={$page->template->name})\n";
echo "\n";

$failures = 0;

// ────────────────────────────────────────────────────────────────────
// Check A: addHookAfter on ___renderOg appends a marker, accessor too.
// ────────────────────────────────────────────────────────────────────

echo "── A. addHookAfter on SeoNeo::renderOg (new partial) ──\n";

$markerA = '<!-- HOOK-A-AFTER-OG -->';
$baselineA = $seoneo->renderOg($page);

$hookIdA = $wire->addHookAfter('SeoNeo::renderOg', function (HookEvent $event) use ($markerA) {
	$event->return = $event->return . "\n" . $markerA;
});

$directA = $seoneo->renderOg($page);
$accessorA = $page->seoneo->renderOg();

$wire->removeHook($hookIdA);
$revertedA = $seoneo->renderOg($page);

$a1 = (strpos($directA, $markerA) !== false);
$a2 = (strpos($accessorA, $markerA) !== false);
$a3 = ($revertedA === $baselineA);

echo "  baseline len            : " . strlen($baselineA) . " chars\n";
echo "  direct call (hooked)    : " . ($a1 ? 'marker present' : 'MARKER MISSING') . "\n";
echo "  accessor path (hooked)  : " . ($a2 ? 'marker present' : 'MARKER MISSING') . "\n";
echo "  after removeHook        : " . ($a3 ? 'reverted to baseline' : 'NOT REVERTED') . "\n";

if ($a1 && $a2 && $a3) {
	echo "  RESULT: PASS\n\n";
} else {
	echo "  RESULT: FAIL\n\n";
	$failures++;
}

// ────────────────────────────────────────────────────────────────────
// Check B: addHookBefore with $event->replace on ___renderCanonical
// short-circuits and substitutes its own return value.
// ────────────────────────────────────────────────────────────────────

echo "── B. addHookBefore on SeoNeo::renderCanonical (replace mode) ──\n";

$replacementB = '<!-- HOOK-B-CANONICAL-REPLACED -->';
$baselineB = $seoneo->renderCanonical($page);

$hookIdB = $wire->addHookBefore('SeoNeo::renderCanonical', function (HookEvent $event) use ($replacementB) {
	$event->replace = true;
	$event->return = $replacementB;
});

$replacedB = $seoneo->renderCanonical($page);

$wire->removeHook($hookIdB);
$revertedB = $seoneo->renderCanonical($page);

$b1 = ($replacedB === $replacementB);
$b2 = ($revertedB === $baselineB);

echo "  baseline                : " . trim($baselineB) . "\n";
echo "  replaced output         : " . trim($replacedB) . "\n";
echo "  replacement matched     : " . ($b1 ? 'yes' : 'NO') . "\n";
echo "  after removeHook        : " . ($b2 ? 'reverted to baseline' : 'NOT REVERTED') . "\n";

if ($b1 && $b2) {
	echo "  RESULT: PASS\n\n";
} else {
	echo "  RESULT: FAIL\n\n";
	$failures++;
}

// ────────────────────────────────────────────────────────────────────
// Check C: pre-existing ___renderTitle is still hookable (regression
// check — proves the K7 refactor did not damage existing hook surface).
// ────────────────────────────────────────────────────────────────────

echo "── C. addHookAfter on SeoNeo::renderTitle (pre-existing partial) ──\n";

$markerC = ' [HOOKED]';
$baselineC = $seoneo->renderTitle($page);

$hookIdC = $wire->addHookAfter('SeoNeo::renderTitle', function (HookEvent $event) use ($markerC) {
	$event->return = str_replace('</title>', $markerC . '</title>', $event->return);
});

$hookedC = $seoneo->renderTitle($page);

$wire->removeHook($hookIdC);
$revertedC = $seoneo->renderTitle($page);

$c1 = (strpos($hookedC, $markerC) !== false);
$c2 = ($revertedC === $baselineC);

echo "  baseline                : " . trim($baselineC) . "\n";
echo "  hooked output           : " . trim($hookedC) . "\n";
echo "  marker present          : " . ($c1 ? 'yes' : 'NO') . "\n";
echo "  after removeHook        : " . ($c2 ? 'reverted to baseline' : 'NOT REVERTED') . "\n";

if ($c1 && $c2) {
	echo "  RESULT: PASS\n\n";
} else {
	echo "  RESULT: FAIL\n\n";
	$failures++;
}

// ────────────────────────────────────────────────────────────────────
// Summary
// ────────────────────────────────────────────────────────────────────

echo "═══ SEO NEO HOOK SMOKE TEST — END ═══\n";
if ($failures === 0) {
	echo "OVERALL: PASS (3/3 checks)\n";
	exit(0);
} else {
	echo "OVERALL: FAIL ({$failures} of 3 checks failed)\n";
	exit(1);
}
