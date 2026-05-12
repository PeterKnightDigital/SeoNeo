# SeoNeo

Modern SEO coordinator for ProcessWire. Uses native PW fields for meta tags, Open Graph, robots directives, canonical URLs, and more.

## How it works

SeoNeo is a **coordinator module**, not a custom Fieldtype. It creates standard ProcessWire fields (Text, Textarea, URL, Checkbox) for SEO data, reads them via a configurable mapping, resolves fallbacks, and renders the full `<head>` output.

This means:

- Every SEO field is a real PW field with full multi-language, selector, and import/export support
- No custom database schema, no Fieldtype complexity
- The SEO tab appears alongside your existing Content, Children, and Settings tabs in the page editor

## What it outputs

```html
<!-- SeoNeo -->
<title>Freelance ProcessWire Developer | Peter Knight Digital</title>
<meta name="description" content="Your page description here.">
<link rel="canonical" href="https://example.com/your-page/">
<meta name="keywords" content="processwire, web development">
<meta property="og:title" content="Freelance ProcessWire Developer">
<meta property="og:description" content="Your page description here.">
<meta property="og:url" content="https://example.com/your-page/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Peter Knight Digital">
<meta property="og:image" content="https://example.com/site/assets/files/1234/hero.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:secure_url" content="https://example.com/site/assets/files/1234/hero.jpg">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:locale" content="en_US">
<meta property="og:locale:alternate" content="de_DE">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@yourhandle">
<meta name="twitter:creator" content="@yourhandle">
<meta name="twitter:title" content="Freelance ProcessWire Developer">
<meta name="twitter:description" content="Your page description here.">
<meta name="twitter:image" content="https://example.com/site/assets/files/1234/hero.jpg">
<meta name="google-site-verification" content="abcd1234…">
<meta name="author" content="Peter Knight">
<!-- /SeoNeo -->
```

Empty fields are skipped. Pages with noindex/nofollow get the robots directive automatically.

## Installation

1. Copy the `SeoNeo` folder to `site/modules/`
2. In the PW admin, go to Modules > Refresh, then install **SeoNeo**
3. The module auto-creates these fields: `seoneo_tab`, `seoneo_preview`, `seoneo_title`, `seoneo_description`, `seoneo_canonical`, `seoneo_keywords`, `seoneo_noindex`, `seoneo_nofollow`
4. Add `seoneo_tab` to any template to enable the SEO tab on those pages

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

## Admin UI features

- **SERP Preview**: live Google-style preview that updates as you type, showing the formatted title with separator and site name. Responsive — readable down to ~360px wide
- **Effective values panel**: a collapsed disclosure under the SERP card showing exactly what SeoNeo will emit for title, description, canonical, robots, OG type, site name, and OG image (with thumbnail). Especially useful when smart-map fallbacks or template defaults are doing the heavy lifting and the per-page SEO fields are blank
- **Character counters**: advisory counters on title (60 chars) and description (160 chars) with green/amber/red zones. An optional **hard-cap** module setting can additionally enforce browser-level `maxlength` for editorial teams that want a firm limit
- **Canonical URL placeholder**: shows the automatic page URL in the input placeholder
- **Noindex/nofollow checkboxes**: per-page control over search engine indexing

## Template API

```php
// Resolved values (after fallback chain):
$page->seoneo->title          // "My Page | Site Name"
$page->seoneo->description    // "Resolved description"
$page->seoneo->canonical      // "https://example.com/my-page/"
$page->seoneo->robots         // "index,follow"

// Rendering:
$page->seoneo->render()       // Full <!-- SeoNeo --> head block
$page->seoneo->renderTitle()  // Just the <title> tag

// Or use as a string:
echo $page->seoneo;           // Same as render()
```

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
- `og:locale` resolves to the current request language's locale. Order: explicit `og_locale_map` entry → derived from the language record (title if it already looks like a locale, otherwise `xx_XX` from the language name) → site-wide **Default OG locale**. Hookable via `___getOgLocale($page, $lang)`
- `og:locale:alternate` is emitted once per active language other than the current one, using the same lookup chain. Hookable via `___getOgLocaleAlternates($page)`
- `hreflang` alternates are emitted for every active language. URL segments (`$input->urlSegmentStr()`) and pagination (`$input->pageNum()`) are preserved, so `/news/page2/` correctly resolves to `/de/news/seite2/` when `$config->pageNumUrlPrefixes = ['de' => 'seite']` is set. A final `hreflang="x-default"` line points at the default-language URL for users whose locale isn't otherwise covered. Multi-domain language setups (via `LanguageSupportPageNames`) are honoured because resolution goes through `$page->localHttpUrl($lang)`
- `twitter:card` is set to `summary_large_image` when an image is found, `summary` otherwise
- `twitter:site` and `twitter:creator` come from module config (the leading `@` is added automatically). Hook `___getTwitterCreator($page)` to return a per-page or per-author value
- `twitter:title`, `twitter:description`, and `twitter:image` mirror the OG values for scrapers that don't fall back to `og:*`

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

## Migration script

A migration script is included at `migrate-seoneo.php` for rolling out the SEO tab to multiple templates at once. It:

- Adds the SEO tab and all fields to specified templates
- Copies `summary` field values to `seoneo_description`
- Sets noindex/nofollow on gated or private pages
- Supports dry-run mode (default) for safe preview

Run via Tracy Console:

```php
include $config->paths->siteModules . 'SeoNeo/migrate-seoneo.php';
```

## Frontend Admin Bar (SeoNeoBar)

The optional **SeoNeoBar** module injects a discreet fixed bar at the bottom of every front-end page for logged-in editors. It gives instant access to fully-resolved SEO data without opening the page editor or inspecting source code.

### What it shows

The bar has three panel buttons:

| Panel | Contents |
|---|---|
| **SEO & Meta** | Google SERP preview, resolved title + description with character counters, canonical URL, robots status badges (index/noindex, follow/nofollow), keywords, hreflang alternates |
| **Headings** | Full H1–H6 tree extracted from the live page, indented and sized by level |
| **Open Graph** | Live OG card preview (image, title, description, site name), individual tag values, Twitter/X card type |

Each value shows **where it came from** — whether it came directly from a SeoNeo field, from a smart-map fallback field, or from a template default. This makes it easy to verify the fallback chain without guessing.

### Installation

1. Install **SeoNeo** first (required dependency)
2. Install **SeoNeoBar** — it auto-loads alongside SeoNeo
3. Visit any front-end page while logged in as an admin or editor with page-edit permission

No template changes required. The bar is injected automatically before `</body>` on front-end pages. It is never shown to non-logged-in users.

### Permissions

The bar is only shown when:
- The current user is logged in
- The user is a superuser, or has `page-edit` permission

The AJAX data endpoint performs the same permission check server-side on every request.

### Design

The bar uses a dark charcoal strip that works against any site design, with a clean light drawer for data. All CSS is namespaced under `pkd-seoneo-` and uses CSS custom properties (design tokens) that can be overridden if needed.

The bar adds `margin-bottom` to `<body>` so page content is never hidden behind it.

---

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+

## Changelog

### SeoNeo 1.5.0 — fallback-chain label visualisation

- The fallback-chain popover now pairs each step's human field label with
  its machine name in brackets — e.g. *Meta Description
  (seoneo_description) → Summary (summary) → Body (body)* — joined by a
  downward arrow glyph so the resolution order reads as a flow.
- The full chain is always shown (not truncated at the winning step), so
  editors can see *why* a value won.
- Duplicate fields are silently deduplicated and only shown at their
  earliest position in the chain.
- New per-step keys `labelText`, `displayName`, and `inheritedSuffix`
  feed a new client-side label renderer and CSS layout.

### SeoNeo 1.4.2 — per-language fallback chain

- The fallback-chain icon and "Using: …" ghost text now appear next to
  *each* language input on multilingual sites, not just the default
  language. The icon stays visible at all times; the "Using: …" text
  only appears when a fallback is actually winning for that language.
- The chain is computed per language: the popover for the German input
  shows German values, the English input shows English values, etc.
- The "Use this" button now writes to the correct language input rather
  than always targeting the default language.
- Server-side: each chain step carries a `valuesByLang` map; client-side:
  steps are re-evaluated against live DOM values for the active language.

### SeoNeo 1.4.1 — fallback-chain popover portal

- Fixes a bug where the field-mapping popover was clipped by ancestor
  `overflow` rules inside the Inputfield wrap, producing an in-field
  scrollbar instead of a free-floating popover.
- The popover now uses a portal pattern: it's appended to `document.body`
  with `position: fixed`, and JS positions it relative to the trigger
  button's viewport rectangle, with flip and clamp logic so it stays on
  screen near viewport edges.
- The popover closes on viewport scroll and resize, and stale popovers
  are defensively cleaned up on AJAX reloads of the Inputfield.

### SeoNeo 1.4.0

- Fallback-chain visualisation (link-icon popover next to each SEO field
  that resolves through the smart map). Pro candidate feature.

### SeoNeo 1.3.x and earlier

- 1.3.0 — OG image resolver with 4-step fallback chain
  (`seoneo_og_image` → smart map → page images → site default).
- 1.2.x — Smart map for inheritable fields, OG tags, cache busting,
  refreshed admin UI.
- 1.1.x — Open Graph tags, SERP preview with site name + separator,
  canonical URL placeholders, meta keywords field.
- 1.0.0 — Initial release: coordinator SEO module emitting meta, robots,
  and canonical tags from native PW fields.

### SeoNeoBar 1.1.1 — canonical-context spoof

- Fixes a bug where the bar's canonical row reflected its own AJAX
  endpoint URL (e.g. `/walks/seoneo-bar-data/`) instead of the page the
  bar was opened on, and the "Edit page" button linked to a broken
  JSON endpoint. SeoNeoBar now snapshots the live page's URL segments
  and page number when injecting the bar, ships them with the AJAX
  request, and temporarily spoofs `$input` in the data handler so
  SeoNeo's canonical resolver sees the *page's* URL context, not the
  AJAX endpoint's.

### SeoNeoBar 1.1.0

- Added Links and Images panels.
- Refreshed SERP preview behaviour to keep parity with the SEO tab.

## License

MIT
