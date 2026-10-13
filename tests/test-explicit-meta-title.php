<?php namespace ProcessWire;

/**
 * Explicit seoneo_title is returned verbatim (no global Title format).
 *
 * USAGE (from a PW site root with SeoNeo installed):
 *   php /path/to/SeoNeo/tests/test-explicit-meta-title.php [page-id]
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
$explicit = 'Lakes & Trails — Custom Meta Title Only';
$saved = $page->getUnformatted($titleField);

$page->of(false);
$page->set($titleField, $explicit);
$page->save($titleField);
wire('pages')->uncacheAll();
$page = wire('pages')->get($pageId);

$got = $seoneo->getTitle($page);
$formatted = $seoneo->formatTitle((string) $page->title);
$ok = $got === $explicit && $got !== $formatted;

$page->of(false);
$page->set($titleField, $saved);
$page->save($titleField);

echo "explicit=[$explicit] getTitle=[$got] formatTitle(page.title)=[$formatted] " . ($ok ? "PASS\n" : "FAIL\n");
exit($ok ? 0 : 1);
