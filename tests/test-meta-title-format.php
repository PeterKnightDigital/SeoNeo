<?php namespace ProcessWire;

/**
 * Meta title override + Title format; no double branding when full title pasted.
 *
 * USAGE (from a PW site root with SeoNeo installed):
 *   php /path/to/SeoNeo/tests/test-meta-title-format.php [page-id]
 */

if(PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only.');
}

ini_set('memory_limit', '512M');

$cwd = getcwd();
if(!is_file($cwd . '/index.php')) {
	fwrite(STDERR, "FATAL: run from a ProcessWire site root.\n");
	exit(1);
}

require $cwd . '/index.php';

$superuser = wire('users')->get('roles=superuser, include=all');
if(!$superuser || !$superuser->id) {
	fwrite(STDERR, "FATAL: no superuser.\n");
	exit(1);
}
wire('users')->setCurrentUser($superuser);

$pageId = isset($argv[1]) ? (int) $argv[1] : 1;
$page = wire('pages')->get($pageId);
if(!$page->id) {
	fwrite(STDERR, "FATAL: page $pageId not found.\n");
	exit(1);
}

$seoneo = wire('modules')->get('SeoNeo');
if(!$seoneo) {
	fwrite(STDERR, "FATAL: SeoNeo not installed.\n");
	exit(1);
}

$titleField = $seoneo->get('role_title') ?: 'seoneo_title';
$saved = $page->getUnformatted($titleField);
$siteName = $seoneo->getSiteName();
$sep = (string) $seoneo->get('title_separator');
if($siteName === '') {
	fwrite(STDERR, "SKIP: site_name empty.\n");
	exit(0);
}

$stem = 'ZZZ Meta Override Stem';
$full = $stem . $sep . $siteName;

$page->of(false);
$page->set($titleField, $stem);
$page->save($titleField);
wire('pages')->uncacheAll();
$page = wire('pages')->get($pageId);
$gotStem = $seoneo->getTitle($page);
$expectStem = $seoneo->formatTitle($stem);
$okStem = $gotStem === $expectStem && str_contains($gotStem, $siteName);

$page->of(false);
$page->set($titleField, $full);
$page->save($titleField);
wire('pages')->uncacheAll();
$page = wire('pages')->get($pageId);
$gotFull = $seoneo->getTitle($page);
$okFull = $gotFull === $full;

$page->of(false);
$page->set($titleField, $saved);
$page->save($titleField);

$ok = $okStem && $okFull;
echo "stem=[$gotStem] full=[$gotFull] " . ($ok ? "PASS\n" : "FAIL\n");
exit($ok ? 0 : 1);
