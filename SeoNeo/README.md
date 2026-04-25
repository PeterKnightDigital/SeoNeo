# SeoNeo

Modern SEO coordinator for ProcessWire. Uses native PW fields for meta tags, robots directives, canonical URLs, and more.

## How it works

SeoNeo is a **coordinator module**, not a custom Fieldtype. It creates standard ProcessWire fields (Text, Textarea, URL, Checkbox) for SEO data, reads them via a configurable mapping, resolves fallbacks, and renders the full `<head>` output.

This means:

- Every SEO field is a real PW field with full multi-language, selector, and import/export support
- Adding a new meta tag (e.g., keywords, Open Graph) is just creating a PW field and adding one line of config
- No custom database schema, no Fieldtype complexity

## Installation

1. Copy the `SeoNeo` folder to `site/modules/`
2. In the PW admin, go to Modules > Refresh, then install **SeoNeo**
3. The module auto-creates SEO fields: `seoneo_title`, `seoneo_description`, `seoneo_canonical`, `seoneo_noindex`, `seoneo_nofollow`, plus a tab and SERP preview
4. Add `seoneo_tab` to any template to enable the SEO tab on those pages

## Configuration

Go to Modules > Configure > SeoNeo:

- **Site name** and **title format** — control how `<title>` tags are built
- **Smart field mapping** — fallback to existing page fields (e.g., `summary` for description)
- **Per-template defaults** — default meta values per template with placeholder support
- **Custom tag mappings** — map any PW field to any meta tag
- **Auto-inject** — automatically insert meta tags before `</head>`

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

## Adding custom fields

1. Create a PW field (e.g., `seoneo_keywords`, type Text)
2. Add it to your template, inside the `seoneo_tab`
3. In SeoNeo module config, add to Custom tag mappings:
   ```
   seoneo_keywords=<meta name="keywords" content="%s">
   ```
4. Done. Populated = tag output. Empty = ignored.

## Resolver chain

For title and description, SeoNeo resolves values in order:

1. **Page SEO field** — the explicit `seoneo_title` / `seoneo_description` value
2. **Smart-map fallback** — tries configured fallback fields (e.g., `headline`, `summary`)
3. **Template default** — from module config, with `{title}`, `{site_name}`, `{page.fieldname}` placeholders
4. **Site default** — page title as ultimate fallback for title; empty for description
5. **Empty** — tag is not output

Each step is hookable (`___getTitle`, `___getDescription`, etc.).

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+

## License

MIT
