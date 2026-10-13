<?php namespace ProcessWire;

/**
 * Per-template title default: literal output (no global Title format).
 *
 * USAGE (from a PW site root with SeoNeo installed):
 *   php /path/to/SeoNeo/tests/test-template-default-title.php [page-id]
 *
 * Exit 0 when checks pass, 1 otherwise.
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

$tpl = $page->template ? $page->template->name : 'page';
$modules = wire('modules');
$savedConfig = $modules->getModuleConfigData($seoneo);
$titleField = $seoneo->get('role_title') ?: 'seoneo_title';
$savedSeoTitle = $page->getUnformatted($titleField);

$siteName = $seoneo->getSiteName();
if($siteName === '') {
	fwrite(STDERR, "SKIP: site_name is empty in module config.\n");
	exit(0);
}
$block = "[$tpl]\ntitle={site_name}\n";
$mutated = $savedConfig;
$mutated['template_defaults_text'] = $block;
$mutated['smart_map_text'] = '';
$modules->saveModuleConfigData($seoneo, $mutated);
$seoneo = $modules->get('SeoNeo');

$page->of(false);
$page->set($titleField, '');
$page->save($titleField);
wire('pages')->uncacheAll();
$page = wire('pages')->get($pageId);

$got = $seoneo->getTitle($page);
$ok = $got === $siteName;

$page->of(false);
$page->set($titleField, $savedSeoTitle);
$page->save($titleField);
$modules->saveModuleConfigData($seoneo, $savedConfig);
$seoneo = $modules->get('SeoNeo');
wire('pages')->uncacheAll();

echo "template=$tpl site_name=[$siteName] getTitle=[$got] " . ($ok ? "PASS\n" : "FAIL\n");
exit($ok ? 0 : 1);
