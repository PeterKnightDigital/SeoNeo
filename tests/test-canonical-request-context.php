<?php namespace ProcessWire;

/**
 * Prove getCanonical() does not copy this request's URL segments onto
 * other pages.
 *
 *   cd /path/to/pw-site
 *   php /path/to/SeoNeo/tests/test-canonical-request-context.php
 *
 * Exit 0 on pass, 1 on fail.
 */

if(PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only.');
}

$cwd = getcwd();
$indexPath = $cwd . '/index.php';
if(!is_file($indexPath)) {
	fwrite(STDERR, "FATAL: run from a ProcessWire site root.\n");
	exit(1);
}

require $indexPath;

$seoneo = wire('modules')->get('SeoNeo');
if(!$seoneo) {
	fwrite(STDERR, "FATAL: SeoNeo is not installed.\n");
	exit(1);
}

$pages = wire('pages');
$home = $pages->get(1);
$viewedId = (int) wire('page')->id;
$other = $pages->findOne('id>1, id!=' . $viewedId . ', template!=admin, template!=http404, status<' . Page::statusTrash);
if(!$home->id || !$other || !$other->id) {
	fwrite(STDERR, "FATAL: need homepage plus one other page (not the current request page).\n");
	exit(1);
}

$input = wire('input');
if(method_exists($input, 'setUrlSegment')) {
$input->setUrlSegment(1, 'extra');
} else {
	fwrite(STDERR, "FATAL: WireInput::setUrlSegment() missing.\n");
	exit(1);
}

$fail = 0;
$assert = function(string $label, bool $ok) use (&$fail): void {
	echo ($ok ? 'PASS' : 'FAIL') . "  $label\n";
	if(!$ok) $fail++;
};

$otherCanon = $seoneo->getCanonical($other);
$assert(
	'other page canonical does not contain /extra/ (viewed page is not $other)',
	$otherCanon !== '' && !str_contains($otherCanon, '/extra')
);

$origPage = wire('page');
wire()->set('page', $other);
$otherAsViewed = $seoneo->getCanonical($other);
wire()->set('page', $origPage);

$assert(
	'viewed page canonical keeps /extra/ when segment policy is include',
	str_contains($otherAsViewed, '/extra')
);

$homeCanon = $seoneo->getCanonical($home);
$assert(
	'homepage canonical does not inherit /extra/ while viewing a Null/admin page',
	$homeCanon !== '' && !str_contains($homeCanon, '/extra')
);

if($fail) {
	echo "otherCanon=$otherCanon\nviewedCanon=$otherAsViewed\nhomeCanon=$homeCanon\n";
	exit(1);
}

echo "OK\n";
exit(0);
