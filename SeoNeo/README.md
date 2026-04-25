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
<meta name="twitter:card" content="summary_large_image">
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
| **Title format** | How `<title>` is built: `{title}{separator}{site_name}` |
| **Title separator** | Character(s) between title and site name (default: ` \| `) |
| **Auto-inject** | Automatically insert the meta block before `</head>` |
| **Smart field mapping** | Fallback to existing page fields (e.g. `summary` for description) |
| **Per-template defaults** | Default meta values per template with placeholder support |
| **Custom tag mappings** | Map any PW field to any meta tag |
| **OG image fields** | Which image fields to scan for `og:image` (comma-separated) |
| **Default OG image** | Fallback image URL when the page has no images |

## Admin UI features

- **SERP Preview**: live Google-style preview that updates as you type, showing the formatted title with separator and site name
- **Character counters**: advisory counters on title (60 chars) and description (160 chars) with green/amber/red zones
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
2. **Smart-map fallback**: tries configured fallback fields (e.g. `headline`, `summary`, `body`)
3. **Template default**: from module config, with `{title}`, `{site_name}`, `{page.fieldname}` placeholders
4. **Site default**: page title as ultimate fallback for title; empty for description
5. **Empty**: tag is not output

Each step is hookable (`___getTitle`, `___getDescription`, etc.).

## Open Graph

OG tags are generated automatically from the same data:

- `og:title` uses the raw page title (without separator or site name)
- `og:description` uses the same resolved description as the meta tag
- `og:url` uses the canonical URL
- `og:site_name` comes from module config
- `og:image` scans the page's image fields in the configured order, falling back to a default URL
- `twitter:card` is set to `summary_large_image` when an image is found, `summary` otherwise

## Custom meta tags

Map any PW field to any meta tag via the module config:

```
seoneo_author=<meta name="author" content="%s">
seoneo_og_type=<meta property="og:type" content="%s">
```

The field value replaces `%s`. Empty fields are skipped.

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

## License

MIT
