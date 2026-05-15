<?php namespace ProcessWire;

/**
 * SEO Neo render harness.
 *
 * Boots ProcessWire from the current working directory, fetches a page, and
 * prints the full <head> block produced by $page->seoneo->render() to stdout.
 *
 * Optionally prints output from individual partial render methods (renderOg,
 * renderTwitter, etc.) — selected with the second argument.
 *
 * USAGE:
 *
 *   Must be run from inside a PW site root (the directory containing
 *   index.php and the wire/, site/ folders). SeoNeo must be installed
 *   in that site.
 *
 *     cd /path/to/pw-site
 *     php /path/to/SeoNeo/tests/test-render.php [page] [section] [lang]
 *
 *   page     optional, defaults to 1 (homepage). Accepts:
 *              - a numeric page ID, e.g. 1
 *              - a page path, e.g. /walks/nine-standards-rigg/
 *   section  optional, one of: full (default), title, description, canonical,
 *            robots, og, twitter, hreflang, verification, author, schema
 *   lang     optional, defaults to the user's default language. Pass the
 *            language name as defined in PW (e.g. default, de, fi).
 *
 * REGRESSION DIFF PATTERN:
 *
 *   The output is deterministic for any given page state, so it can be
 *   diffed across SeoNeo code revisions to prove a refactor preserved
 *   behaviour:
 *
 *     php …/test-render.php 1 > after.txt
 *     git -C /path/to/SeoNeo stash push -- SeoNeo/SeoNeo.module.php
 *     php …/test-render.php 1 > before.txt
 *     diff before.txt after.txt    # empty = byte-identical
 *     git -C /path/to/SeoNeo stash pop
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
$section = isset($argv[2]) ? strtolower((string) $argv[2]) : 'full';
$langArg = isset($argv[3]) ? (string) $argv[3] : '';

// Page lookup: numeric → ID; otherwise → path (always resolved in default lang
// first so the test target stays consistent across language renders).
if (ctype_digit($pageArg)) {
	$page = wire('pages')->get((int) $pageArg);
} else {
	$page = wire('pages')->get($pageArg);
}
if (!$page->id) {
	fwrite(STDERR, "FATAL: page '$pageArg' does not exist.\n");
	exit(1);
}

// Optional language switch — render the page as if the visitor were viewing
// it under the named language. We swap the language on the current user
// because that's what SeoNeo (and PW itself) reads from when rendering.
if ($langArg !== '') {
	$langs = wire('languages');
	if (!$langs || !count($langs)) {
		fwrite(STDERR, "FATAL: language '$langArg' requested but LanguageSupport is not installed.\n");
		exit(1);
	}
	$lang = $langs->get($langArg);
	if (!$lang || !$lang->id) {
		$available = [];
		foreach ($langs as $l) $available[] = $l->name;
		fwrite(STDERR, "FATAL: unknown language '$langArg'. Available: " . implode(', ', $available) . "\n");
		exit(1);
	}
	wire('user')->language = $lang;
}

$seoneo = wire('modules')->get('SeoNeo');
if (!$seoneo) {
	fwrite(STDERR, "FATAL: SeoNeo module is not installed on this site.\n");
	exit(1);
}

$accessor = $page->seoneo;
$out = match ($section) {
	'full'         => $accessor->render(),
	'title'        => $accessor->renderTitle(),
	'description'  => $accessor->renderDescription(),
	'canonical'    => $accessor->renderCanonical(),
	'robots'       => $accessor->renderRobots(),
	'og'           => $accessor->renderOg(),
	'twitter'      => $accessor->renderTwitter(),
	'hreflang'     => $accessor->renderHreflang(),
	'verification' => $accessor->renderVerification(),
	'author'       => $accessor->renderAuthor(),
	'schema'       => $accessor->renderSchema(),
	default        => null,
};

if ($out === null) {
	fwrite(STDERR, "FATAL: unknown section '$section'. Allowed: full, title, description, canonical, robots, og, twitter, hreflang, verification, author, schema.\n");
	exit(1);
}

$lang = wire('user')->language;
$langName = ($lang && $lang->id) ? $lang->name : 'default';

$header = sprintf(
	'═══ SEO NEO TEST OUTPUT — START ═══  page=%d  "%s"  template=%s  lang=%s  section=%s',
	$page->id,
	str_replace(["\r", "\n"], ' ', $page->title),
	$page->template ? $page->template->name : '(none)',
	$langName,
	$section
);
$footer = '═══ SEO NEO TEST OUTPUT — END ═══';

echo $header . "\n";
echo $out . "\n";
echo $footer . "\n";
