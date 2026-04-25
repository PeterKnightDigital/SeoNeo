<?php namespace ProcessWire;

/**
 * SeoNeo Migration Script
 * 
 * Run in Tracy Console. Set $dryRun = true to preview, false to execute.
 *
 * 1. Adds seoneo_tab (+ all SEO fields) to public templates
 * 2. Copies summary → seoneo_description where summary has content
 * 3. Sets noindex + nofollow on account/members pages
 * 4. Skips eCommerce, licence, and internal templates entirely
 */

$dryRun = false;

// ── Templates to receive the SEO tab ──────────────────────────────────

$publicTemplates = [
    'pages_about',
    'pages_basic-page',
    'pages_legal',
    'portfolio_projects',
    'portfolio_project',
    'blog_home',
    'blog_posts',
    'blog_post',
    'blog_categories',
    'blog_category',
    'blog_tags',
    'blog_tag',
    'docs_docs',
    'docs_module',
    'docs_version',
    'docs_page',
    'products_product-index',
    'products_products-module',
];

$ecommerceTemplates = [
    'products_purchase-thank-you',
    'products_product-release',
    'products_products-downloads',
    'products_products-early-access',
    'downloads_downloads-index',
];

$accountTemplates = [
    'account_login',
    'account_register',
    'account_forgot-pw',
    'account_index',
    'account_profile',
    'account_downloads',
    'account_purchases',
    'account_product-dl',
];

$noindexTemplates = array_merge($ecommerceTemplates, $accountTemplates);

$allTemplates = array_merge($publicTemplates, $ecommerceTemplates, $accountTemplates);

// SeoNeo fields in tab order
$seoneoFields = [
    'seoneo_tab',
    'seoneo_preview',
    'seoneo_title',
    'seoneo_description',
    'seoneo_canonical',
    'seoneo_keywords',
    'seoneo_noindex',
    'seoneo_nofollow',
    'seoneo_tab_END',
];

$fields = wire('fields');
$templates = wire('templates');

// ── Verify all SeoNeo fields exist ────────────────────────────────────

$missingFields = [];
foreach ($seoneoFields as $fname) {
    if (!$fields->get($fname)) $missingFields[] = $fname;
}
if (count($missingFields)) {
    echo "ERROR: Missing SeoNeo fields: " . implode(', ', $missingFields) . "\n";
    echo "Install the SeoNeo module first.\n";
    return;
}

// ── STEP 1: Add fields to templates ───────────────────────────────────

echo "═══ STEP 1: Add SEO tab to templates ═══\n\n";

foreach ($allTemplates as $tplName) {
    $tpl = $templates->get($tplName);
    if (!$tpl) {
        echo "  SKIP  $tplName — template not found\n";
        continue;
    }

    $fg = $tpl->fieldgroup;
    if ($fg->hasField('seoneo_tab')) {
        echo "  OK    $tplName — already has SEO tab\n";
        continue;
    }

    if ($dryRun) {
        echo "  ADD   $tplName — would add SEO tab + fields\n";
    } else {
        $prev = null;
        foreach ($seoneoFields as $fname) {
            $f = $fields->get($fname);
            if ($fg->hasField($f)) continue;
            if ($prev) {
                $fg->insertAfter($f, $prev);
            } else {
                $fg->add($f);
            }
            $prev = $f;
        }
        $fg->save();
        echo "  DONE  $tplName — SEO tab + fields added\n";
    }
}

// ── STEP 2: Migrate summary → seoneo_description ─────────────────────

echo "\n═══ STEP 2: Migrate summary → seoneo_description ═══\n\n";

$migrateCount = 0;
$skipCount = 0;

foreach ($publicTemplates as $tplName) {
    $tpl = $templates->get($tplName);
    if (!$tpl) continue;

    if (!$tpl->fieldgroup->hasField('summary')) {
        echo "  ──  $tplName — no summary field, title falls back via smart-map\n";
        continue;
    }

    $pages = wire('pages')->find("template=$tplName, include=all");
    foreach ($pages as $p) {
        $summary = trim((string) $p->getUnformatted('summary'));
        $existing = trim((string) $p->getUnformatted('seoneo_description'));

        if ($summary === '') {
            $skipCount++;
            continue;
        }
        if ($existing !== '') {
            echo "  KEEP  [{$p->id}] {$p->title} — seoneo_description already set\n";
            $skipCount++;
            continue;
        }

        $preview = mb_strlen($summary) > 80
            ? mb_substr($summary, 0, 77) . '...'
            : $summary;

        if ($dryRun) {
            echo "  COPY  [{$p->id}] {$p->title} — \"{$preview}\"\n";
        } else {
            $p->setAndSave('seoneo_description', $summary);
            echo "  DONE  [{$p->id}] {$p->title} — description migrated\n";
        }
        $migrateCount++;
    }
}

echo "\n  Summary: {$migrateCount} pages to migrate, {$skipCount} skipped\n";

echo "\n  NOTE: seoneo_title is left empty intentionally.\n";
echo "  Smart-map automatically uses the page title field.\n";
echo "  Only fill seoneo_title when you want a custom SEO override.\n";

// ── STEP 3: Set noindex + nofollow on private pages ──────────────────

echo "\n═══ STEP 3: Set noindex/nofollow on account + gated pages ═══\n\n";

$noindexCount = 0;

foreach ($noindexTemplates as $tplName) {
    $tpl = $templates->get($tplName);
    if (!$tpl) continue;

    $pages = wire('pages')->find("template=$tplName, include=all");
    foreach ($pages as $p) {
        $ni = (int) $p->getUnformatted('seoneo_noindex');
        $nf = (int) $p->getUnformatted('seoneo_nofollow');

        if ($ni && $nf) {
            echo "  OK    [{$p->id}] {$p->title} — already noindex+nofollow\n";
            continue;
        }

        if ($dryRun) {
            echo "  SET   [{$p->id}] {$p->title} — would set noindex+nofollow\n";
        } else {
            $p->of(false);
            $p->set('seoneo_noindex', 1);
            $p->set('seoneo_nofollow', 1);
            $p->save();
            echo "  DONE  [{$p->id}] {$p->title} — noindex+nofollow set\n";
        }
        $noindexCount++;
    }
}

echo "\n  Account pages to update: {$noindexCount}\n";

// ── Summary ──────────────────────────────────────────────────────────

echo "\n═══════════════════════════════════════════\n";
if ($dryRun) {
    echo "DRY RUN complete. Set \$dryRun = false to execute.\n";
} else {
    echo "MIGRATION complete.\n";
}
echo "═══════════════════════════════════════════\n";
