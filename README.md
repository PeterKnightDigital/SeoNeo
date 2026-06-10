# SeoNeo

Modern SEO coordinator for ProcessWire. Uses native PW fields for meta tags, Open Graph, robots directives, canonical URLs, and more.

## How it works

SeoNeo is a **coordinator module**, not a custom Fieldtype. It creates standard ProcessWire fields (Text, Textarea, URL, Checkbox) for SEO data, reads them via a configurable mapping, resolves fallbacks, and renders the full `<head>` output.

This means:

- Every SEO field is a real PW field with full multi-language, selector, and import/export support
- No custom database schema, no Fieldtype complexity
- The SEO tab appears alongside your existing Content, Children, and Settings tabs in the page editor

## What it outputs

Below is the head block SeoNeo emits for a real page on the demo site
*LakesAndTrails.go* — a multilingual (EN / DE / FI) editorial guide to
the Lake District and Yorkshire Dales — viewing the article
*Nine Standards Rigg* in the default (English) language:

```html
<!-- SeoNeo -->
<title>Nine Standards Rigg | LakesAndTrails.go</title>
<meta name="description" content="Nine drystone cairns on a Pennine watershed above Kirkby Stephen. Crossed by the Coast to Coast.">
<link rel="canonical" href="https://lakesandtrails.go/walks/nine-standards-rigg/">
<meta name="keywords" content="coast to coast, pennine, yorkshire dales, kirkby stephen">

<meta property="og:title" content="Nine Standards Rigg">
<meta property="og:description" content="Nine drystone cairns on a Pennine watershed above Kirkby Stephen. Crossed by the Coast to Coast.">
<meta property="og:url" content="https://lakesandtrails.go/walks/nine-standards-rigg/">
<meta property="og:type" content="article">
<meta property="og:site_name" content="LakesAndTrails.go">
<meta property="og:image" content="https://lakesandtrails.go/site/assets/files/1048/nine-standards-rigg-hero.jpg">
<meta property="og:image:width" content="1600">
<meta property="og:image:height" content="900">
<meta property="og:image:secure_url" content="https://lakesandtrails.go/site/assets/files/1048/nine-standards-rigg-hero.jpg">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:locale" content="en_GB">
<meta property="og:locale:alternate" content="de_DE">
<meta property="og:locale:alternate" content="fi_FI">
<meta property="article:author" content="Lakes &amp; Trails Editorial">
<meta property="article:published_time" content="2026-05-12T11:49:20+01:00">
<meta property="article:modified_time" content="2026-05-14T15:37:31+01:00">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@lakesandtrails">
<meta name="twitter:creator" content="@lakesandtrails">
<meta name="twitter:title" content="Nine Standards Rigg">
<meta name="twitter:description" content="Nine drystone cairns on a Pennine watershed above Kirkby Stephen. Crossed by the Coast to Coast.">
<meta name="twitter:image" content="https://lakesandtrails.go/site/assets/files/1048/nine-standards-rigg-hero.jpg">

<link rel="alternate" hreflang="en-GB" href="https://lakesandtrails.go/walks/nine-standards-rigg/">
<link rel="alternate" hreflang="de"    href="https://lakesandtrails.go/de/wanderungen/nine-standards-rigg/">
<link rel="alternate" hreflang="fi"    href="https://lakesandtrails.go/fi/vaellukset/nine-standards-rigg/">
<link rel="alternate" hreflang="x-default" href="https://lakesandtrails.go/walks/nine-standards-rigg/">

<meta name="google-site-verification" content="abcd1234…">
<meta name="author" content="Lakes &amp; Trails Editorial">

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@graph": [
        { "@type": "Organization", "@id": "https://lakesandtrails.go/#organization",
          "name": "Lakes & Trails",
          "url": "https://lakesandtrails.go/",
          "description": "An editorial guide to the Lake District, Yorkshire Dales, and Eden Valley." },
        { "@type": "WebSite", "@id": "https://lakesandtrails.go/#website",
          "publisher": { "@id": "https://lakesandtrails.go/#organization" },
          "inLanguage": "en" },
        { "@type": "WebPage", "@id": "https://lakesandtrails.go/walks/nine-standards-rigg/#webpage",
          "name": "Nine Standards Rigg | LakesAndTrails.go",
          "inLanguage": "en",
          "datePublished": "2026-05-12T11:49:20+01:00",
          "dateModified": "2026-05-14T15:37:31+01:00",
          "isPartOf": { "@id": "https://lakesandtrails.go/#website" } },
        { "@type": "BreadcrumbList", "@id": "https://lakesandtrails.go/walks/nine-standards-rigg/#breadcrumb",
          "itemListElement": [
            { "@type": "ListItem", "position": 1, "name": "Lakes & Trails",       "item": "https://lakesandtrails.go/" },
            { "@type": "ListItem", "position": 2, "name": "Walks",                "item": "https://lakesandtrails.go/walks/" },
            { "@type": "ListItem", "position": 3, "name": "Nine Standards Rigg",  "item": "https://lakesandtrails.go/walks/nine-standards-rigg/" }
          ] }
    ]
}
</script>
<!-- /SeoNeo -->
```

Things worth pointing out in that output:

- **`<meta name="robots">` is absent** — for a default-state page (`index,follow`, no AI directives, no Google granular directives) SeoNeo intentionally omits the tag rather than emitting a no-op. Bots assume `index,follow` when nothing is declared, so the silence is the correct signal.
- **`og:type=article` triggers `article:author` / `article:published_time` / `article:modified_time`** automatically (C14). The published / modified times come from the page's PW `created` / `modified` timestamps when no explicit override is set; the author defaults to the resolved `getAuthors()` chain.
- **`hreflang` includes `x-default`** pointing at the default-language URL — the BCP47 codes use the language map, so `default` becomes `en-GB` rather than the unhelpful `default`.
- **`twitter:card` switched to `summary_large_image`** automatically because an OG image is present. With no image it would be plain `summary`.
- **JSON-LD `@graph`** wires four typed nodes (`Organization`, `WebSite`, `WebPage`, `BreadcrumbList`) by canonical `@id` so the page, site, and trail are a single linked graph rather than four standalone blobs.
- **Empty fields are skipped** — every tag has a hookable resolver, and if a resolver returns `''` the tag never enters the output. The marker comments `<!-- SeoNeo -->` / `<!-- /SeoNeo -->` are only emitted by the full `render()` method, not the per-section partials.

## Installation

1. Copy the `SeoNeo` folder to `site/modules/`
2. In the PW admin, go to Modules > Refresh, then install **SeoNeo**
3. The module auto-creates these fields: `seoneo_tab`, `seoneo_preview`, `seoneo_title`, `seoneo_description`, `seoneo_canonical`, `seoneo_keywords`, `seoneo_noindex`, `seoneo_nofollow`
4. Add `seoneo_tab` to any template to enable the SEO tab on those pages — SeoNeo automatically inserts the remaining SEO fields (preview, title, description, canonical, etc.) in the correct order when you save the template

> **Already using MarkupSEO or Seo Maestro?** You can run both during migration — see [Installing alongside other SEO modules](#installing-alongside-other-seo-modules). The SeoNeo tab stays labeled **SEO** by default with a small **NEO** badge so it stays distinct from MarkupSEO's **SEO** tab.

## Configuration

Go to Modules > Configure > SeoNeo:

| Setting | What it does |
|---|---|
| **Site name** | Appended to titles (e.g. "Peter Knight Digital") |
| **Per-language site name** | Optional `langname=name` per line (e.g. `de=Mein Beispiel`); only shown when languages are active |
| **Title format** | How `<title>` is built: `{title}{separator}{site_name}` (also accepts `{pageNum}` and `{pageNumber}` for paginated lists) |
| **Title separator** | Character(s) between title and site name (default: ` \| `) |
| **Auto-inject** | Automatically insert the meta block in `<head>` |
| **Injection position** | Top (right after `<head>`) or Bottom (before `</head>`, default) |
| **Canonical URL policy** | Pagination behaviour and URL-segment behaviour for the auto-canonical (default: include both, matching modern Google guidance) |
| **Robots / indexing defaults** | Auto-noindex unpublished pages (on by default) and auto-noindex hidden pages (off by default) |
| **Smart field mapping** | Fallback to existing page fields (e.g. `summary` for description). Prefix with `*` for ancestor-walk |
| **Per-template defaults** | Default meta values per template with placeholder support |
| **Custom tag mappings** | Map any PW field to any meta tag |
| **Search engine verification** | Dedicated fields for Google / Bing / Yandex / Pinterest / Facebook / Baidu — paste either the bare token or the full `<meta>` snippet |
| **Default author** | Site-wide value for `<meta name="author">`. Per-page overrides via an optional `seoneo_author` field |
| **OG image fields** | Which image fields to scan for `og:image` (comma-separated). Supports dotted paths |
| **Default OG image** | Fallback image URL when the page has no images |
| **Default OG type** | Site-wide fallback for `og:type` (default `website`) |
| **Default OG locale** | Site-wide fallback for `og:locale` (default `en_US`). Used as-is on single-language sites and when no language-specific entry exists |
| **Locale map for languages** | Optional per-language overrides, one per line: `default=en_GB`, `de=de_AT`, etc. Powers both `og:locale` and `og:locale:alternate` |
| **Twitter / X site handle** | `@username` of the site itself (emitted as `twitter:site`) |
| **Default Twitter / X creator handle** | `@username` of the content author (emitted as `twitter:creator`; hookable per-page) |
| **Hard-cap title / description input length** | Optional `maxlength` enforcement on the page-edit form. Off by default (the soft amber/red counter is preferred) |
| **SEO tab label** | Wire tab text for the SeoNeo fieldset (default **SEO**). Synced to the `seoneo_tab` field on config save |
| **Show NEO badge on tab** | Small **NEO** pill beside the tab label in the page editor — on by default; helps distinguish from MarkupSEO's **SEO** tab during migration |

## Admin UI features

- **Google SERP Preview**: live Google-style preview that updates as you type, showing the formatted title with separator and site name. **Desktop / Mobile surface toggle** above the card flips between Google's desktop and mobile SERP layouts (narrower card, host-only breadcrumb, mobile-budget truncation); **language switcher** (multilingual sites only) lets editors flick between language versions of the SERP without leaving the page
- **Surface-aware character counters**: advisory counters on title and description with green/amber/red zones. Budgets switch automatically with the SERP preview surface — desktop defaults to 60/160 chars, mobile to 50/120 (Google's mobile SERP truncates earlier). All four desktop and four mobile thresholds are tunable in module config. An optional **hard-cap** module setting can additionally enforce browser-level `maxlength` for editorial teams that want a firm limit
- **Canonical URL placeholder**: shows the automatic page URL in the input placeholder
- **Noindex/nofollow checkboxes**: per-page control over search engine indexing

## Template API

```php
// Resolved single values (after fallback chain) — output the raw string, no markup.
// All values resolve in the current language automatically (PW's native lang-aware
// field handling); switch $user->language to read another language's values.
$page->seoneo->title              // "My Page | Site Name"
$page->seoneo->description        // "Resolved description"
$page->seoneo->canonical          // "https://example.com/my-page/"
$page->seoneo->robots             // "index,follow"
$page->seoneo->author             // "Jane Doe, John Smith"   (raw string)
$page->seoneo->keywords           // "hiking, lake district, cumbria"
$page->seoneo->ogTitle            // "My Page"
$page->seoneo->ogImage            // "https://example.com/site/files/123/hero.jpg"
$page->seoneo->ogType             // "article"
$page->seoneo->ogLocale           // "en_GB"
$page->seoneo->siteName           // "My Example Site"
$page->seoneo->twitterSite        // "@mysite"
$page->seoneo->twitterCreator     // "@janedoe"
$page->seoneo->hreflangCode       // "en-GB"

// Resolved multi-value arrays — for downstream consumers that need structured
// data (JSON-LD authors, sitemap loops, custom UI, etc.). All return [] when empty.
$page->seoneo->authors            // ['Jane Doe', 'John Smith']
$page->seoneo->keywordsList       // ['hiking', 'lake district', 'cumbria']
$page->seoneo->ogImageData        // ['url' => …, 'width' => …, 'height' => …, 'secure_url' => …, 'type' => …]
$page->seoneo->ogLocaleAlternates // ['de_DE', 'fi_FI']
$page->seoneo->verifications      // ['google' => 'abc…', 'bing' => 'def…']
$page->seoneo->aiDirectives       // ['noai', 'noimageai']
$page->seoneo->robotsDirectives   // ['max-snippet' => '50', 'max-image-preview' => 'large']
$page->seoneo->articleAuthors     // ['Jane Doe', 'John Smith']        (when og:type=article)
$page->seoneo->articlePublishedTime  // "2026-05-15T09:42:00+01:00"    (ISO 8601, when og:type=article)
$page->seoneo->articleModifiedTime   // "2026-05-15T11:08:23+01:00"    (ISO 8601, when og:type=article)
$page->seoneo->hreflangAlternates // ['en' => 'https://…/', 'de' => 'https://…/de/', 'x-default' => 'https://…/']
$page->seoneo->schemaGraph        // ['@context' => 'https://schema.org', '@graph' => [...]]  ⚠️ BETA — see note below

// Rendering — full block:
$page->seoneo->render()              // Full <!-- SeoNeo --> head block
echo $page->seoneo;                  // Same as render()

// Rendering — per-section partials (for composing your own <head>):
$page->seoneo->renderTitle()         // <title>...</title>
$page->seoneo->renderDescription()   // <meta name="description">
$page->seoneo->renderCanonical()     // <link rel="canonical">
$page->seoneo->renderRobots()        // <meta name="robots"> (only when not index,follow)
$page->seoneo->renderOg()            // All og:* tags (title, description, url, type,
                                     //   site_name, locale[:alternate], image*)
$page->seoneo->renderTwitter()       // All twitter:* tags (card, site, creator,
                                     //   title, description, image)
$page->seoneo->renderHreflang()      // <link rel="alternate" hreflang="…"> set
$page->seoneo->renderVerification()  // Search-engine verification tags
$page->seoneo->renderAuthor()        // <meta name="author">
$page->seoneo->renderSchema()        // JSON-LD <script> block  ⚠️ BETA — see note below
```

### Namespace API (SeoMaestro-style)

In addition to the flat `renderOg()` / `ogTitle` style above, every grouped
concern also exposes a **namespace proxy** — a small read-only object that
groups the render method and value getters for one slice of the head. This
mirrors the API shape used by [SeoMaestro](https://github.com/wanze/SeoMaestro),
so templates migrating from `$page->seo` rewrite to `$page->seoneo` with no
further change to the partial-render shape:

```php
// Full render — identical to SeoMaestro
echo $page->seoneo;                          // = $page->seoneo->render()
echo $page->seoneo->render();

// Per-group rendering — same shape as $page->seo->opengraph->render()
echo $page->seoneo->og->render();            // (alias: ->opengraph->render())
echo $page->seoneo->twitter->render();
echo $page->seoneo->hreflang->render();
echo $page->seoneo->verification->render();
echo $page->seoneo->schema->render();        // ⚠️ BETA — see note below

// Or just echo the proxy — __toString delegates to render()
echo $page->seoneo->og;                      // Same as ->og->render()
echo $page->seoneo->twitter;                 // Same as ->twitter->render()
```

Each namespace also exposes the value getters relevant to its domain — so
where the flat surface uses prefix-mangled keys like `ogTitle`, the
namespace surface uses clean dotted names:

```php
// Open Graph (alias: $page->seoneo->opengraph)
$page->seoneo->og->title              // = ogTitle
$page->seoneo->og->description        // = description (shared with <meta name="description">)
$page->seoneo->og->image              // = ogImage  (URL string)
$page->seoneo->og->imageData          // = ogImageData  (full data array)
$page->seoneo->og->type               // = ogType
$page->seoneo->og->locale             // = ogLocale
$page->seoneo->og->localeAlternates   // = ogLocaleAlternates
$page->seoneo->og->siteName           // = siteName
$page->seoneo->og->url                // = canonical
$page->seoneo->og->articleAuthors         // = articleAuthors        (article OG type only)
$page->seoneo->og->articlePublishedTime   // = articlePublishedTime
$page->seoneo->og->articleModifiedTime    // = articleModifiedTime

// Twitter — title / description / image mirror their OG counterparts
$page->seoneo->twitter->site          // = twitterSite
$page->seoneo->twitter->creator       // = twitterCreator
$page->seoneo->twitter->title         // = ogTitle (Twitter cards inherit OG by spec)
$page->seoneo->twitter->description   // = description
$page->seoneo->twitter->image         // = ogImage
$page->seoneo->twitter->url           // = canonical

// Hreflang
$page->seoneo->hreflang->code         // = hreflangCode  ("en-GB")
$page->seoneo->hreflang->alternates   // = hreflangAlternates  (['en' => '…', 'de' => '…', 'x-default' => '…'])

// Verification tokens
$page->seoneo->verification->tokens   // = verifications  (['google' => 'abc…', 'bing' => 'def…'])

// Schema / JSON-LD — proxy form with structured access  (BETA — see note below)
$page->seoneo->schema->context        // "https://schema.org"
$page->seoneo->schema->graph          // The @graph array (the items themselves)
$page->seoneo->schema->data           // Full {"@context": …, "@graph": […]} payload
                                      // (alias: $page->seoneo->schemaGraph)
json_encode($page->seoneo->schema)    // The full data payload (via JsonSerializable)
```

The namespace surface and the flat surface are **two views of the same data**
— `$page->seoneo->og->title === $page->seoneo->ogTitle`. Hooks on the
underlying resolver (`SeoNeo::getOgTitle`) apply to both views; hooks on the
render method (`SeoNeo::renderOg`) apply to both `$page->seoneo->og->render()`
and `$page->seoneo->renderOg()`. Use whichever style reads best in your
template — most projects standardise on one.

> ⚠️ **Schema / JSON-LD is BETA.** The auto `@graph` generator (Organization, WebSite, WebPage, Article, Person, BreadcrumbList) ships in core and `$page->seoneo->schema->render()` / `renderSchema()` will return it, but the API shape, default `@graph` composition, and hook surface may still change. For production sites that need stable structured data today, hand-rolling JSON-LD via the standard hooks (`addHookAfter('SeoNeo::renderHead', …)`) remains the safer path until the helper API stabilises.

### Composing your own `<head>` with partial methods

When you want SeoNeo to handle the resolver chain, fallbacks, language handling, etc. but
need to place individual sections in specific spots — for example, OG tags above the fold,
schema markup at the end of `<head>` — disable **Auto-inject meta tags into `<head>`** in
module config and compose the head yourself:

```php
<head>
    <meta charset="utf-8">
    <?= $page->seoneo->renderTitle() ?>
    <?= $page->seoneo->renderDescription() ?>
    <?= $page->seoneo->renderCanonical() ?>
    <?= $page->seoneo->renderRobots() ?>

    <?= $page->seoneo->renderOg() ?>
    <?= $page->seoneo->renderTwitter() ?>

    <link rel="stylesheet" href="/site/templates/styles/main.css">

    <?= $page->seoneo->renderAuthor() ?>
    <?= $page->seoneo->renderHreflang() ?>
    <?= $page->seoneo->renderVerification() ?>
    <?= $page->seoneo->renderSchema() ?>
</head>
```

Each partial method returns the section's HTML (or an empty string if there's nothing
to emit) and is **hookable** on the underlying module class — so you can extend or filter
any section without subclassing. For example:

```php
$this->addHookAfter('SeoNeo::renderOg', function($event) {
    $event->return .= "\n" . '<meta property="og:custom" content="…">';
});
```

The `<!-- SeoNeo -->` / `<!-- /SeoNeo -->` block-marker comments are only emitted by the
full `render()` method, never by the partials — keeping the partial output clean for
embedding inside your own markup.

## Resolver chain

For title and description, SeoNeo resolves values in order:

1. **Page SEO field**: the explicit `seoneo_title` / `seoneo_description` value
2. **Smart-map fallback**: tries configured fallback fields (e.g. `headline`, `summary`, `body`). Dotted paths reach into nested data: `banner.image.description`, `gallery.first.alt`, `matrix_blocks.first.body`, `pagetable_items.0.summary`. Missing references at any step are skipped silently. Prefix any field with `*` to fall back to ancestors when the current page leaves it blank — e.g. `*section_description` walks parents nearest-first and stops at the first non-empty value
3. **Template default**: from module config, with `{title}`, `{site_name}`, `{page.fieldname}`, `{pageNum}`, `{pageNumber}` placeholders. Pipe-separated fallbacks pick the first non-empty value (`{long_title|title}`)
4. **Site default**: page title as ultimate fallback for title; empty for description
5. **Empty**: tag is not output

Each step is hookable (`___getTitle`, `___getDescription`, etc.).

**Description truncation** — values resolved from the smart-map or a template default (steps 2 and 3) are stripped of HTML, collapsed to single spaces, and truncated at the nearest word boundary to the configured **Max description length** (default 180 chars), with an ellipsis appended. Values typed directly into `seoneo_description` (step 1) are returned verbatim, on the assumption that the editor knows what they want. Set the limit to `0` in module config to disable truncation entirely.

## Canonical URLs

The `seoneo_canonical` field accepts three formats:

- **Absolute URL** (`https://example.com/path/`) — used verbatim
- **Protocol-relative** (`//example.com/path/`) — scheme is added based on the current request
- **Root-relative path** (`/about-us/`) — scheme + host are taken from the page's own URL

Relative paths are particularly useful when the same content is shared across staging and production environments — type `/about-us/` once and the rendered tag adapts to whichever host the page is being served from.

### Pagination and URL segments

When the canonical field is empty, SeoNeo falls back to the current page URL. Two module-config policies tune that fallback for paginated lists and URL-segment-driven sub-pages:

- **Pagination behaviour** — *Include the page number* (default, recommended) keeps `/news/page2/` as its own canonical; *Always page 1* collapses every paginated variant to `/news/`. Language-aware page-number prefixes from `$config->pageNumUrlPrefixes` are honoured automatically
- **URL segment behaviour** — *Include the segment string* (default, recommended) keeps `/news/2024/article-slug/` as its own canonical; *Parent page only* collapses every segment-driven variant to `/news/`

Per-page overrides via the **Canonical URL** field always win — the policies apply only to the auto-generated fallback. Because `og:url` reuses the resolved canonical and `twitter:url` mirrors it, all three tags stay in sync no matter which combination you choose.

## Robots / indexing

In addition to the per-page **Noindex** and **Nofollow** checkboxes on the SEO tab, two site-wide defaults live in module config:

- **Auto-noindex unpublished pages** — *enabled by default*. ProcessWire still allows superusers and editors with view-permission to render an unpublished page on the frontend, so without this safety net a search engine following an internal preview link could index a draft
- **Auto-noindex hidden pages** — *off by default*. Hidden pages are publicly viewable; this toggle treats Hidden as a "not for search" signal as well

Per-page checkboxes always win — the defaults only flip the noindex bit if it isn't already explicitly set.

### Granular Google directives (site-wide)

Four optional directives in module config compose into the **same `<meta name="robots">` tag** (Google's documented preference — directives go inside the robots tag, not in parallel `googlebot` tags):

- **`max-snippet`** — character cap for SERP text snippets. `-1` for no limit, `0` to suppress, positive for the cap. Leave blank to emit nothing
- **`max-image-preview`** — image preview size: `none`, `standard`, or `large`
- **`max-video-preview`** — seconds of video preview. `-1` for no limit, `0` to suppress
- **`unavailable_after`** — RFC 850 or ISO 8601 datetime telling Google to drop the page from the index after the supplied moment. Useful for event listings, time-limited offers, embargoed news

All four default to empty (= emit nothing) so the output stays byte-identical to pre-config behaviour until an editor opts in. Hookable per-page via `___getRobotsDirectives($page)` if you want per-template overrides.

### AI / LLM opt-out directives (site-wide)

Two optional toggles append AI-specific directives to the robots tag:

- **`noai`** — asks AI crawlers not to use the site's content for generative-AI training
- **`noimageai`** — asks AI crawlers not to include the site's images in AI training datasets

Both honoured by *some* AI crawlers (DeviantArt-originated spec). **Not a substitute** for blocking GPTBot / ClaudeBot / PerplexityBot at the `robots.txt` or HTTP level — treat as a polite request, not enforcement. Hookable via `___getAiDirectives($page)`.

When any granular or AI directive is configured, the robots tag is emitted even if `index,follow` would otherwise omit it. Composed output looks like:

```html
<meta name="robots" content="index,follow,noai,max-snippet:50,max-image-preview:large">
```

## Open Graph

OG tags are generated automatically from the same data:

- `og:title` uses the raw page title (without separator or site name)
- `og:description` uses the same resolved description as the meta tag
- `og:url` uses the canonical URL
- `og:site_name` comes from module config
- `og:image` scans the page's image fields in the configured order, falling back to a default URL. Dotted paths reach into nested data: `banner.image` (page reference), `gallery.first` (Pageimages), `matrix_blocks.first.image` (RepeaterMatrix). The optional **Inherit OG image from closest ancestor** toggle adds a middle step that walks `$page->parents()` and uses the first ancestor that has its own image — handy for section landing pages whose hero should be reused on every child article
- `og:image:width` / `og:image:height` are emitted when the resolved image is a real `Pageimage` (Facebook silently rejects images on first share when these are missing)
- `og:image:secure_url` mirrors `og:image` whenever it's served over HTTPS
- `og:image:type` is the IANA media type (e.g. `image/jpeg`, `image/webp`) inferred from the file extension
- `og:type` resolves per-page via the optional **OG Type** field, then per-template via an `og_type=` line in the per-template defaults, then via the site-wide default in module config (`website` if nothing is configured). Hookable via `___getOgType($page)`
- **Article-specific OG tags** (`article:author`, `article:published_time`, `article:modified_time`) are emitted *only* when `og:type` resolves to `article`, so non-article pages stay clean. Authors come from the same multi-author splitting as `<meta name="author">` (one `article:author` tag per author). Published / modified times default to the page's `$page->created` / `$page->modified` formatted as ISO 8601, hookable via `___getArticlePublishedTime($page)` and `___getArticleModifiedTime($page)`. Multi-author override hookable via `___getArticleAuthors($page)`
- `og:locale` resolves to the current request language's locale. Order: explicit `og_locale_map` entry → derived from the language record (title if it already looks like a locale, otherwise `xx_XX` from the language name) → site-wide **Default OG locale**. Hookable via `___getOgLocale($page, $lang)`
- `og:locale:alternate` is emitted once per active language other than the current one, using the same lookup chain. Hookable via `___getOgLocaleAlternates($page)`
- `hreflang` alternates are emitted for every active language. URL segments (`$input->urlSegmentStr()`) and pagination (`$input->pageNum()`) are preserved, so `/news/page2/` correctly resolves to `/de/news/seite2/` when `$config->pageNumUrlPrefixes = ['de' => 'seite']` is set. A final `hreflang="x-default"` line points at the default-language URL for users whose locale isn't otherwise covered. Multi-domain language setups (via `LanguageSupportPageNames`) are honoured because resolution goes through `$page->localHttpUrl($lang)`
- `twitter:card` is set to `summary_large_image` when an image is found, `summary` otherwise
- `twitter:site` and `twitter:creator` come from module config (the leading `@` is added automatically). Hook `___getTwitterCreator($page)` to return a per-page or per-author value
- `twitter:title`, `twitter:description`, and `twitter:image` mirror the OG values for scrapers that don't fall back to `og:*`

## Structured data (JSON-LD)

SeoNeo emits a `<script type="application/ld+json">` block on every page containing a Schema.org `@graph` of inter-linked nodes. This is the structured-data format Google requires for rich results, sitelinks search box, and is increasingly important for AI search agents (Perplexity, Bing Copilot, ChatGPT search) that rely on Schema.org to understand site authorship and content.

### Default graph composition

- **Organization** (or `Person`, `LocalBusiness`, `NewsMediaOrganization`, `EducationalOrganization` per the **Publisher type** setting) — the site-wide publisher, with `name`, `url`, optional `logo`, `description`, and `sameAs` links.
- **WebSite** — the site itself, with a `publisher` reference back to the Organization and the default-language `inLanguage`.
- **WebPage** — the current page, with `name`, `description`, `url`, `inLanguage`, `isPartOf` (→ WebSite), `dateModified`, `datePublished`, and `primaryImageOfPage` (when an OG image resolves).
- **Article** — added on pages whose template appears in **Article templates** (e.g. `journal_post,blog_post`), with `headline`, `description`, `image`, `datePublished`, `dateModified`, resolved `author`, publisher reference, and `inLanguage`.
- **Person** — added on pages whose template appears in **Person templates** (defaults to `user` so PW User pages used as author bios are recognised), with `name`, `description`, `image`, `url`.
- **BreadcrumbList** — added when **Emit BreadcrumbList** is enabled (on by default) and the page has at least one parent in the tree. Built from `$page->parents()` plus the page itself.

Nodes are wired via canonical `@id` URIs, so a single graph represents the site, page, author, and breadcrumb trail without duplication.

### Multilingual

The per-page nodes (WebPage, Article, Person, BreadcrumbList) automatically translate with the page — they consume `$page->title`, `$page->httpUrl`, the smart-map fallback chain, and the new `getHreflangCode()` for `inLanguage`. The Organization and WebSite nodes get their `name` and `description` from two new per-language map fields (**Publisher name (per language)** and **Publisher description (per language)**), e.g.:

```
default=Lakes & Trails
de=Seen & Pfade
```

The `@id` URIs for Organization and WebSite intentionally stay language-invariant — schema.org best practice is to identify the same entity across locales and translate only the descriptive properties.

### Hooks

Two hookable entry points let you extend or override the graph per site, per template, or per page:

- `___getJsonLd(Page $page): array` — returns the `@graph` array. Hook this to add nodes (Event, Recipe, Product, Course, etc.), modify existing ones, or remove nodes you don't want.
- `___renderJsonLd(Page $page): string` — returns the rendered `<script>` tag (or empty when JSON-LD is disabled or the graph is empty).

### Disabling

The **Emit JSON-LD** checkbox in module config is the master switch. When off, no `<script type="application/ld+json">` block is emitted regardless of the per-node templates.

## Custom meta tags

### Per-page custom HTML (`seoneo_custom`)

Each page has a **Custom <head> HTML** field on its SEO tab — a textarea where editors can paste arbitrary markup that's injected into the `<head>`. Typical uses:

- Site-verification snippets (Google Search Console, Bing Webmaster, Yandex, Pinterest, ahrefs)
- One-off `<meta>`, `<link>`, or `<script type="application/ld+json">` tags for a specific page
- Anything else SeoNeo doesn't model directly

The textarea content is rendered **verbatim** — SeoNeo does not escape it, because the whole point is to let you paste raw `<meta>` and `<link>` tags. Restrict edit access to trusted roles (the field is created with `tags=SeoNeo` so you can target it in the field-permissions UI).

### Global field-to-tag mappings

Map any PW field to any meta tag via the module config:

```
seoneo_author=<meta name="author" content="%s">
seoneo_og_type=<meta property="og:type" content="%s">
```

The field value replaces `%s`. Empty fields are skipped, and the value **is** escaped before insertion (unlike `seoneo_custom`).

### Search engine verification

A dedicated **Search engine verification** fieldset in module config covers the six common providers as first-class settings rather than free-form tags:

| Provider | Renders as |
|---|---|
| Google Search Console | `<meta name="google-site-verification" content="…">` |
| Bing Webmaster Tools  | `<meta name="msvalidate.01" content="…">` |
| Yandex Webmaster      | `<meta name="yandex-verification" content="…">` |
| Pinterest             | `<meta name="p:domain_verify" content="…">` |
| Facebook Domain       | `<meta name="facebook-domain-verification" content="…">` |
| Baidu Webmaster       | `<meta name="baidu-site-verification" content="…">` |

Paste either the bare token or the full `<meta name="…" content="…">` snippet from the service's dashboard — SeoNeo extracts the `content` attribute either way. By default these tags are emitted on the homepage only (most services only check the root URL); a checkbox lets you enable them on every page when verifying subdomains or per-language country variants.

### Author meta tag

For the `<meta name="author">` tag specifically, two opt-in tiers are available:

1. **Site-wide default** — set the **Default author** value in module config
2. **Per-page override** — add a `FieldtypeText` field named `seoneo_author` to any template; if it has a non-empty value, it wins over the site default

The field isn't installed automatically (most sites only need the site-wide default). The `___getAuthor(Page $page)` resolver is hookable, so sites that derive the author from a Page reference (e.g. `createdUser`) can return whatever they like.

## ProCache compatibility

SeoNeo and ProCache are designed to coexist without configuration:

- **Cache miss (page is being rebuilt):** SeoNeo's `Page::render` hook runs *before* ProCache stores the result, so the meta block is baked into the cached HTML on the way to disk. Subsequent cache hits serve that pre-injected HTML directly with no PHP overhead.
- **Cache hit (static file served by web server):** PW request handling is bypassed entirely, but the cached file already contains the SeoNeo block from when it was built — no injection needed.
- **Editor changes a SEO field:** PW's standard cache-invalidation rules apply. Set ProCache's "Clear cache when page is saved" behaviour to include all pages, or call `$procache->clearAll()` from a `Pages::saved` hook for the SEO fields if you want zero-touch invalidation.

### HTML minifier

ProCache's HTML minify strips HTML comments by default. The `<!-- SeoNeo -->` / `<!-- /SeoNeo -->` markers may disappear in minified output — this is cosmetic only, the actual `<meta>` tags are real DOM elements and are preserved. The minifier does **not** rewrite or reorder real tags inside `<head>`.

### When to disable auto-inject

If you want absolute control over where the SEO block lands relative to ProCache's own injected markers (PWPC tags, ProCache versioning comments, etc.), disable **Auto-inject meta tags into <head>** in module config and call `echo $page->seoneo->render()` from your template, e.g.:

```php
<head>
    <meta charset="utf-8">
    <?= $page->seoneo->render() ?>
    <!-- the rest of your head -->
</head>
```

Manual rendering also makes it explicit which template files emit the SEO block, which can help when debugging cache-related issues.

## Rolling out to templates

From **1.1.0**, add `seoneo_tab` to any template and save — SeoNeo inserts the remaining SEO fields in the correct order automatically. Repeat per template; no bulk script required.

For one-off data tasks (copying a legacy `summary` field into `seoneo_description`, bulk noindex on login pages, etc.), use Tracy Console with your own site-specific script or the ProcessWire API directly.

## Installing alongside other SEO modules

Many sites run **MarkupSEO** or **Seo Maestro** while migrating page-by-page to SeoNeo. That is supported — you can keep legacy fields on a template and copy values into the SeoNeo fields at your own pace.

### Editor tabs

MarkupSEO's `seo_tab` and SeoNeo's `seoneo_tab` both default to the label **SEO**, which is why two identical-looking tabs appear if both fieldsets are on the same template. The field **names** already differ (`seo_tab` vs `seoneo_tab`) — there is no technical clash, only a visual one.

SeoNeo handles this with:

| Mechanism | Purpose |
|-----------|---------|
| **`data-seoneo-tab="1"`** on the Wire tab link | Stable identifier for admin CSS/JS (set automatically) |
| **NEO badge** (on by default) | Small pill beside the tab label → reads as **SEO** <small>NEO</small> |
| **Configurable tab label** | Modules → Configure → SeoNeo → **SEO tab label** (e.g. change to `SEO Neo` or turn the badge off) |

During migration you might see:

| Tab | Typical content |
|-----|-----------------|
| **SEO** | MarkupSEO fields (`seo_title`, `seo_description`, …) |
| **SEO** + NEO badge | SeoNeo fields (`seoneo_preview`, `seoneo_title`, …) |

Seo Maestro usually adds a single `FieldtypeSeoMaestro` field (often named `seo`) on its own tab or in Content — it does not collide on tab name.

### Frontend output

The thing to watch is **`<head>` output**, not the editor: if both the legacy module and SeoNeo auto-inject meta tags, the page can get doubled titles and OG tags. Common approach during migration:

- Keep both fieldsets in the editor for data entry
- Disable auto-inject on whichever module is **not** yet authoritative for frontend output (SeoNeo module config → **Auto-inject**, or the equivalent on the legacy module)
- Switch templates to `$page->seoneo->render()` when ready, then uninstall the legacy module

Full feature comparison and migration steps: [Migrating from MarkupSEO or Seo Maestro](#migrating-from-markupseo-or-seo-maestro) below.

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+

## Migrating from MarkupSEO or Seo Maestro

Both legacy modules have been gradually unmaintained, and both ship a handful of features that SEO NEO either implements differently or intentionally leaves to dedicated companion modules. This table is the quick reference for "will I lose anything if I move?". Pro tier markers are shown so it's clear what's free vs paid.

| Feature area | MarkupSEO | Seo Maestro | **SEO NEO** | Notes |
|---|---|---|---|---|
| `<title>` template with separator/site name | ✓ | ✓ | ✓ | NEO supports `{title}{separator}{site_name}{pageNum}` placeholders and pipe-separated fallbacks in template defaults |
| Meta description with character counter | ✓ | ✓ | ✓ | NEO has soft amber/red zones **and** optional hard `maxlength` |
| Canonical URL (per-page override + auto) | ✓ | ✓ | ✓ | |
| Canonical preserves URL segments | ✗ (bug) | ✗ (bug) | ✓ | Configurable include/strip policy. **The reason SEO NEO exists.** |
| Canonical preserves pagination | partial | ✗ (bug) | ✓ | Honours `pageNumUrlPrefixes` for per-language prefixes |
| `og:url`, `twitter:url` mirror canonical | ✗ | ✗ | ✓ | Same fix as canonical |
| `hreflang` preserves segments + pagination | ✗ | ✗ | ✓ | Plus configurable BCP47 code map (`default=en-GB`, `de=de-AT`) |
| Robots meta (noindex / nofollow per page) | ✓ | ✓ | ✓ | |
| Auto-noindex unpublished / hidden | ✗ | partial | ✓ | Split into two independent toggles |
| Site-wide noindex / nofollow | ✓ | ✓ | ✓ | |
| Open Graph (title, desc, image, type, locale) | ✓ | ✓ | ✓ | |
| OG image fallback chain (page → ancestor → site default) | ✓ | partial | ✓ | NEO supports dotted paths into nested fields (`banner.image`, `matrix.first.image`) |
| OG image dimensions + secure_url + media type | ✗ | ✗ | ✓ | Prevents Facebook's silent first-share failure |
| Twitter card (`summary` / `summary_large_image`) | ✓ | ✓ | ✓ | Auto-selected based on image presence |
| Search-engine verification (Google/Bing/Yandex/Pinterest/Facebook/Baidu) | ✓ | partial | ✓ | Accepts bare token or full `<meta>` snippet |
| `<meta name="author">` | partial | ✓ | ✓ | Site-wide default + optional per-page `seoneo_author` field |
| Multilingual (per-language fields) | ✓ | ✓ | ✓ | NEO uses native PW language-aware fields, not bespoke storage |
| JSON-LD structured data emitter | ✗ | partial | ~ **BETA** | Auto `@graph` (Organization, WebSite, WebPage, Article, Person, BreadcrumbList) ships in core; API shape and defaults may still change — use hooks for production-critical schema |
| Per-template SEO defaults with placeholders | ✗ | partial | ✓ | NEO: `{title}`, `{page.field}`, `{pageNum}`, pipe-separated fallbacks |
| Smart-map fallbacks with ancestor walk | ✗ | ✗ | ✓ | Prefix any field with `*` to walk parents |
| ProCache compatibility | partial | partial | ✓ | Documented and tested in both cache-miss and cache-hit paths |
| **Fallback-chain visualisation in editor** | ✗ | ✗ | ✓ **(PRO)** | Popover next to every SEO field showing the full resolution order in the current language |
| Template-API shape — `echo $page->{seo,seoneo}->render()` | n/a | ✓ | ✓ | Identical full-render syntax |
| Template-API shape — per-group partials | n/a | `->opengraph->render()` | `->og->render()` **+** `->renderOg()` | NEO supports both the SeoMaestro-style namespace surface (`->og->render()`, alias `->opengraph->render()`) **and** the flat `->renderOg()` form — migrators choose whichever matches their existing templates |

### Intentional gaps — use a dedicated module instead

These features ship with one or both of the legacy modules but are deliberately out of scope for SEO NEO. Each is well-served by a mature, focused module that does the job better than a bundled "Swiss Army knife" SEO module can:

| Feature | Recommended companion module |
|---|---|
| **Sitemap.xml generator** | [`MarkupSitemap`](https://github.com/Mike-Rockett/MarkupSitemap) by Mike Rockett — the de-facto PW sitemap module. Hreflang-aware, supports custom URLs, and integrates cleanly with SEO NEO's per-page noindex setting |
| **301 / 302 redirects manager** | [`Jumplinks2`](https://github.com/Mike-Rockett/Jumplinks2) by Mike Rockett, or [`ProcessRedirects`](https://github.com/somatonic/ProcessRedirects) — both ship with admin UIs and 404 capture |
| **Custom `robots.txt` editor** | [`MarkupRobotsTxt`](https://modules.processwire.com/modules/markup-robots-txt/) or a simple `site/templates/robots.php` override |
| **Google Analytics / GTM snippet injection** | [`MarkupGoogleTagManager`](https://modules.processwire.com/modules/markup-google-tag-manager/) or [`SimpleAnalytics`](https://modules.processwire.com/modules/simple-analytics/) — analytics is its own discipline, not really an SEO concern |

Site-wide AI crawler management features that some users associate with Seo Maestro are planned for a separate **SEO NEO PRO** companion bundle (URL Lifecycle Manager, AI Crawler Observability). PRO scope is not published in this repository yet.

### Migration steps

1. Install SEO NEO via Modules → Refresh → Install **SeoNeo** only (the SERP Preview Inputfield installs with it)
2. Add `seoneo_tab` to each template that needs SeoNeo — legacy SEO fields can stay on the same template while you copy data across (see [Installing alongside other SEO modules](#installing-alongside-other-seo-modules))
3. Copy legacy values into SeoNeo fields at your pace (`seo_description` → `seoneo_description`, etc.) via the editor or Tracy Console
4. **Rewrite template API calls.** Both legacy modules expose `$page->seo` as the template hook; SEO NEO uses `$page->seoneo`. The shape of the calls is preserved across the move, so most projects are a one-liner find/replace:

	**From SeoMaestro:**
	```php
	echo $page->seo;                       // → echo $page->seoneo;
	echo $page->seo->render();             // → echo $page->seoneo->render();
	echo $page->seo->opengraph->render();  // → echo $page->seoneo->opengraph->render();
	                                       //   (or the canonical NEO form: ->og->render())
	echo $page->seo->twitter->render();    // → echo $page->seoneo->twitter->render();
	```

	**From MarkupSEO:**
	```php
	echo $config->seo->render();      // → echo $page->seoneo->render();
	echo $config->seo->title;         // → echo $page->seoneo->title;
	echo $config->seo->description;   // → echo $page->seoneo->description;
	```
5. Uninstall MarkupSEO / Seo Maestro (the old fields stay on the page tree; remove them manually if you want a clean slate)
6. If your old module had a sitemap, redirects, or analytics features turned on, install the recommended companion modules above

## Changelog

### 1.1.3 — Fix NEO badge on Wire tab

- Badge injection now targets the tab link (`a#_Inputfield_seoneo_tab`) — ProcessWire puts the ID on the anchor, not the `<li>`, so the badge never appeared
- Re-runs after WireTabs initialises (`jQuery` ready + `wiretabclick`)
- Badge pill uses flexbox so **NEO** is centred horizontally

### 1.1.2 — Fix empty SeoNeo tab

- **Upgrade self-heal** inserts any missing SeoNeo fields into fieldgroups that already have `seoneo_tab` but only got the tab opener
- Repairs `seoneo_tab` if **Presentation → Tab** (`collapsedTab`) was set — that mode wraps only the opener and leaves child fields outside the Wire tab (empty tab UI). `FieldtypeFieldsetTabOpen` + `seoneo_tab_END` is the correct pattern (same as MarkupSEO's `seo_tab`)
- **Module config fixes:** NEO badge checkbox uses the standard ProcessWire checkbox pattern (was snapping unchecked on click); tab label syncs to the `seoneo_tab` field from the saved config immediately on save
- Auto-complete also runs when `seoneo_tab` is added via the template editor (`ProcessTemplate::fieldAdded`)

### 1.1.1 — Distinct SeoNeo tab during migration

- Tab label defaults to **SEO** again; small **NEO** badge on the Wire tab (on by default) distinguishes it from MarkupSEO's **SEO** tab
- Module config: **SEO tab label** and **Show NEO badge on tab**; label syncs to the `seoneo_tab` field on save
- Wire tab link gets `data-seoneo-tab="1"` for stable admin targeting
- README: **Installing alongside other SEO modules** — coexistence during migration

### 1.1.0 — Auto-complete SEO fieldset

When you add `seoneo_tab` to a template, SeoNeo now inserts any missing SEO fields (`seoneo_preview`, `seoneo_title`, `seoneo_description`, etc.) in the canonical order when the fieldgroup is saved. Idempotent — safe on every template save; shows an admin notice when fields are added.

### 1.0.0 — Initial public release

First stable release of SEO NEO.

**What's new since beta.1**

- **Google SERP Preview** field label and description updated; the section now shows a descriptive helper line beneath the heading
- **Defensive self-heal on upgrade** — `___upgrade()` now re-asserts `seoneo_preview->inputfieldClass = InputfieldSeoNeoPreview` and ensures the companion Inputfield module is installed, guarding against stale-symlink or pre-release-snapshot installs that left the field wired to the plain text fallback
- **CSS design tokens** — `SeoNeo.css` now uses a three-layer token system (`--sn-*` namespace: primary palette → secondary palette → semantic tokens) so a single `:root` change propagates across all controls
- **Accent colour inherits ProcessWire theme** — the selected/active state on the Desktop/Mobile and language buttons now reads `--pw-main-color` automatically, matching whatever admin theme colour is set
- **Consistent button-group active state** — both the surface toggle and language switcher now use the same treatment: white pill, accent-coloured text, subtle shadow (no more solid filled background on the language buttons)
- **Slightly taller buttons** — vertical padding on both control groups increased from 4 px to 6 px

### 1.0.0-beta.1 — Initial public beta

First public release of SEO NEO — a modern, native-PW-fields-first SEO coordinator.

**Core output**

- `<title>` with separator + site name, configurable per template and per page
- Meta description, canonical URL, robots meta
- Open Graph (`og:title`, `og:description`, `og:url`, `og:image`, `og:site_name`, `og:type`, `og:locale`, `og:locale:alternate`)
- Twitter / X cards (`twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:site`, `twitter:creator`)
- Hreflang `<link rel="alternate">` set per active language (+ `x-default`)
- Article-specific Open Graph tags (`article:author`, `article:published_time`, `article:modified_time`) emitted when `og:type=article`
- JSON-LD `@graph` (Organization, WebSite, WebPage, optional Article + Person + BreadcrumbList), wired with canonical `@id` URIs — **shipping as Beta**
- Custom per-page meta (`seoneo_custom`) and global field-to-tag mappings
- Search-engine verification meta blocks (Google, Bing, Yandex, Pinterest, Facebook)
- Author meta tag with multi-author resolution

**Multilingual**

- Per-language site name map, hreflang code map, Open Graph locale map
- Per-language Organization name / description map for JSON-LD
- Hookable `getHreflangCode(Language)`, `getHreflangAlternates(Page)`

**Robots / indexing**

- Per-page noindex / nofollow checkboxes; site-wide overrides
- Auto-noindex for hidden / unpublished pages
- Granular Google directives (`max-snippet`, `max-image-preview`, `max-video-preview`, `unavailable_after`)
- AI / LLM opt-out directives (`noai`, `noimageai`)
- Smart canonical for paginated and URL-segmented pages

**Smart-map fallback chain**

- Site-wide field-to-role mapping with pipe-separated fallbacks (`{long_title|title}`, `{summary|body|intro}`)
- Optional ancestor-walk for inheritable fields (e.g. Open Graph image)
- Dotted paths into nested fields (`banner.image`, `matrix.first.image`)

**Admin UI**

- Surface-aware SERP preview with Desktop / Mobile toggle and multilingual language switcher
- Character counters with desktop (60 / 160) and mobile (50 / 120) budgets, all tunable
- Canonical URL placeholder + per-page robots checkboxes

**Template API**

- Flat surface: `$page->seoneo->renderTitle()`, `->renderOg()`, `->title`, `->description`, `->ogImage`, `->hreflangAlternates`, …
- Namespace-grouped surface: `$page->seoneo->og->render()`, `->twitter->card`, `->hreflang->alternates`, `->schema->graph` — intentionally mirrors [SeoMaestro](https://github.com/wanze/SeoMaestro)'s shape for near-zero-friction migration

**Extensibility**

- Hookable resolver methods on every value: `getTitle`, `getDescription`, `getCanonical`, `getRobots`, `getOgImage`, `getJsonLd`, `getHreflangAlternates`, …
- Hookable render methods on every section: `renderTitle`, `renderDescription`, `renderOg`, `renderTwitter`, `renderHreflang`, `renderJsonLd`, …

## License

MIT
