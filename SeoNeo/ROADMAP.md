# SeoNeo — Editor Experience Roadmap

Ideas and planned improvements focused on making the page-editing experience clearer and more self-contained. All items are scoped to the admin editor and/or the frontend SeoNeo bar; none require changes to the core SEO output logic.

---

## In Progress

### Fallback chain visualization
**Files:** `SeoNeo.module.php`, `SeoNeo.js`, `SeoNeo.css`

Right now the SEO tab shows a blank title or description field with no explanation of where the resolved value is coming from. Editors have to visit the module config page to understand the smart-map chain.

**Proposed UI:**
- A small, muted breadcrumb-style strip rendered below the title and description field labels, showing the full resolution chain in order: `seoneo_title → headline → title → page title`.
- The field that is currently winning (non-empty) is highlighted/bolded.
- Hovering over any step in the chain shows a tooltip with that field's current value (or "empty").
- The active winning step has a small "↑ Use this value" button that copies the resolved value into the primary SEO field, promoting the implicit fallback to an explicit override. The field is marked dirty so the editor knows to save.

**Technical notes:**
- New `resolveSmartMapChain(Page, key)` PHP method returns each step with its value and a `winner` flag; data passed via `ProcessWire.config.SeoNeo`.
- The `*` ancestor-walk prefix and dotted-path fields in `smart_map_text` need to be represented gracefully (e.g. `*author` shown as `author (inherited)`).
- Template defaults with `{a|b}` placeholders are shown as a single "template default" step, consistent with existing `resolveSource()` behavior.

---

## Planned

### Noindex / nofollow prominence warning
**Files:** `SeoNeo.js`, `SeoNeo.css`

When a page is set to noindex, the only indication is the checkbox and a quiet note in the effective-values panel. A page being accidentally deindexed is a silent, high-severity mistake.

**Proposed UI:**
- When the noindex or nofollow checkbox is checked, display a prominent amber/red banner at the top of the SEO tab: "This page is set to noindex — search engines will be asked not to include it."
- The banner updates live as the checkbox is toggled, without a page reload.
- Optional: mirror the warning in the SeoNeoBar drawer.

---

### Social card (OG) preview in admin tab
**Files:** `InputfieldSeoNeoPreview.module.php`, `SeoNeo.js`, `SeoNeo.css`

The SERP preview already shows how the page appears in Google search results. There is currently no equivalent for social sharing — editors need to use a third-party tool (or visit the frontend SeoNeoBar) to check how the page will look when shared on Facebook, LinkedIn, or X.

**Proposed UI:**
- A second preview card, toggled via a tab or button alongside the existing SERP preview.
- Renders a representative OG card: image, site name, title (truncated to ~60 chars), description (truncated to ~120 chars), domain.
- Pulls OG image from the resolved `getOgImageData()` value (same chain the module uses for output).
- Updates live when the title or description fields change, consistent with how the SERP preview works.

**Note:** This is distinct from the existing SERP preview — the Google card and the OG social card have different aspect ratios, truncation limits, and field priorities.

---

### Accurate title character budget (accounts for format)
**Files:** `SeoNeo.js`

The title character counter shows the raw length of what the editor types. But the module appends a separator and site name (e.g. `My Title — Site Name`) before outputting the `<title>` tag. The effective length visible to search engines is longer than the counter suggests, so the green/amber/red thresholds are misleading.

**Proposed change:**
- The counter reads `title_separator` and `site_name` from `ProcessWire.config.SeoNeo` (already injected).
- The displayed count reflects the full rendered title length: `raw input + separator + site name`.
- Thresholds (green/amber/red) are applied against the full rendered length.
- The counter label makes clear what is being counted: e.g. `42 / 60 chars (incl. site name)`.

---

### Canonical field live update in SERP preview
**Files:** `SeoNeo.js`

The URL line in the SERP preview card is rendered server-side once on page load. If an editor sets or changes the canonical field, the preview URL does not update — it continues to show the original page URL, which is actively misleading.

**Proposed change:**
- `SeoNeo.js` observes the canonical input field.
- When the canonical field has a value, the SERP preview URL line switches to it.
- When the canonical field is cleared, it reverts to the page's default URL (`data-page-url`).

---

### Duplicate title / description detection
**Files:** `SeoNeo.module.php` (new API endpoint), `SeoNeo.js`, `SeoNeo.css`

Duplicate meta titles across pages are one of the most common SEO mistakes and one of the hardest to catch while editing a single page. There is currently no per-page indication.

**Proposed UI:**
- When the editor finishes typing in the title or description field (on blur), a lightweight async check fires against a small admin-only endpoint.
- If the same resolved title or description exists on other pages, a dismissable warning appears: "This title is used on 2 other pages — duplicate meta titles can hurt rankings."
- Clicking the warning expands a list of the conflicting pages with links.

**Technical notes:**
- Endpoint queries published pages where the resolved title equals the current value; needs to be fast (indexed field query only, no full resolution chain per page).
- Debounced to avoid firing on every keystroke.

---

### "Suggest from content" for description
**Files:** `SeoNeo.module.php` (or small endpoint), `SeoNeo.js`, `SeoNeo.css`

Editors frequently leave description blank not because they don't want one but because writing a separate 160-character summary feels like additional effort on top of the main content. A one-click suggestion reduces that friction.

**Proposed UI:**
- A small "Suggest" button appears next to the description field when it is empty.
- Clicking it fetches a suggestion server-side: takes the first non-empty smart-map candidate field (e.g. `summary`, `body`) for this page, strips HTML, collapses whitespace, truncates to `max_description_length` at a word boundary — exactly what the module would use as a fallback anyway.
- The suggested text is inserted into the description field in an editable state; the editor can refine it before saving.
- The button does not appear if the description is already filled.

---

### Focus keyword
**Files:** `SeoNeo.module.php`, `SeoNeo.js`, `SeoNeo.css`, optionally a new lightweight PW field

Editors arriving from tools like Yoast or RankMath expect a focus keyword input with basic checks. Done superficially this becomes noise; done with discipline it gives editors a concrete checklist.

**Proposed UI:**
- A single optional text input for "Focus keyword" in the SEO tab (below description).
- Three binary checks shown inline once a keyword is entered:
  - Does the keyword appear in the resolved title?
  - Does the keyword appear in the resolved description?
  - Does the keyword appear in the page URL slug?
- Each check is a simple green tick or amber cross — no score, no red, no percentage.
- The keyword is stored per-page (new lightweight PW field or `seoneo_custom` data).

**Scope boundary:** This is a writing aid, not a content analysis tool. It does not check keyword density, body copy, heading tags, or readability. Those belong in a separate content audit tool, not the SEO tab.

---

## Bugs / accuracy issues (not features, but worth tracking)

- **SeoNeoBar OG type hardcoded:** `buildPageData()` in `SeoNeoBar.module.php` sets `og.type` to the literal string `'website'` in the JSON, instead of calling `getOgType()`. The OG panel therefore always shows `website` regardless of the `seoneo_og_type` field or `default_og_type` config.
- **SeoNeoBar site name ignores multilanguage:** The bar's SERP block uses the global `site_name` string, not `getSiteName()`, so per-language site names are not reflected.
- **`resolveSource()` in SeoNeoBar.module.php is a simplified approximation:** It does not handle `*` ancestor-walk prefixes or dotted paths in smart-map entries, so the "via …" label in the bar can be wrong for advanced smart-map configurations.
- **Effective values panel is a server snapshot:** Canonical, robots, OG type, and site name in the admin "effective values" `<details>` block do not update when the editor changes the corresponding fields — only title and description are live-updated.
