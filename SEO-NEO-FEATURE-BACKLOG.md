## SEO NEO — Feature & Gap Backlog

A living, plain-English checklist of issues, gaps, and enhancement ideas for the SEO NEO module. Sourced from a full review of the **MarkupSEO** support thread on the ProcessWire forums (pages 1‑14, 2014‑2024) and the **Seo Maestro** module page, GitHub issue tracker, and forum thread (pages 1‑12, Feb 2019 ‑ Apr 2026). All findings cross-checked against SEO NEO's current implementation.

**Note on the wider context:** Wanze (the Seo Maestro author) confirmed in May 2020 that he has stopped using ProcessWire as his daily tool and only accepts community contributions via pull request. Several of the open issues referenced in this document have been outstanding for 4+ years. SEO NEO can credibly position itself as the actively-maintained successor to both modules.

This document is **not** part of the module itself — it lives at the workspace root for reference only and is updated as features land or new issues come in.

**Product split (added May 2026, after user feedback round)**

SEO NEO will ship in two layers. The full picture lives in section **R. SEO NEO PRO scope** below — keeping a summary here at the top so the rest of the document reads cleanly:

- **SEO NEO (free, in-core)** — meta / OG / Twitter / canonical / hreflang / robots / schema helpers / SEO health audit. Sections A‑P below. Has to stand on its own without the PRO offering.
- **SEO NEO PRO (paid)** — operational tooling and editor-UX polish that go beyond meta-tag rendering. Section Q covers the operational side (URL Lifecycle Manager, AI Crawler Observability + Management); the editor-UX items live in `SeoNeo/ROADMAP.md` and are summarised in section R. Whether PRO ships as a single bundle or splits into separate companion modules is a deferred decision, captured at the bottom of section R.

**Companion document:** `SeoNeo/ROADMAP.md` is the tactical roadmap for editor-UX work in the admin SEO tab and the frontend SeoNeoBar. Items there tagged `[Pro candidate]` are reflected in section R's Paid list.

**Legend**

- ✅ **Done** — already handled by SEO NEO
- 🟡 **Partial** — works indirectly, could be made first-class
- 🔴 **Gap** — not yet handled, candidate for the roadmap
- ⚫ **Out of scope** — intentionally not in SEO NEO (deferred to a companion module or another tool)
- 🔵 **PRO planned** — out of scope for core SEO NEO; planned for the SEO NEO PRO companion bundle

**Priority for the gaps**

- **P1** — should land before the 1.0 release
- **P2** — nice-to-have, can ship after 1.0
- **P3** — long-term / discussion only

---

### Quick scoreboard

**Core (free) module:**

| Area | Done | Partial | Gap | Out |
|---|---|---|---|---|
| Architecture & data storage | 8 | 0 | 0 | 0 |
| Hook conflicts | 5 | 0 | 0 | 0 |
| Open Graph / Twitter / Social | 13 | 0 | 0 | 0 |
| Robots / indexing | 5 | 0 | 0 | 0 |
| Canonical URL | 5 | 0 | 0 | 0 |
| Multi-language | 9 | 0 | 0 | 0 |
| Title format & smart defaults | 10 | 0 | 0 | 0 |
| Custom / free-form meta | 6 | 0 | 0 | 0 |
| Analytics & tracking | 0 | 0 | 0 | 4 |
| Schema / structured data | 0 | 0 | 2 | 0 |
| Auto-injection / rendering | 6 | 0 | 0 | 0 |
| Editor UX (admin tab) | 7 | 0 | 1 | 0 |
| PHP / PW compatibility | 5 | 0 | 0 | 0 |
| Sitemap | 0 | 0 | 0 | 1 |
| Companion-module wishes — out of scope | 0 | 0 | 0 | 4 |
| Audit / Lister view | 0 | 0 | 1 | 0 |

**SEO NEO PRO companion bundle (planned, separate deliverable — not 1.0 blockers):**

| Area | Done | Partial | PRO planned |
|---|---|---|---|
| URL Lifecycle Manager | 0 | 0 | 4 |
| AI Crawler Observability & Management | 0 | 0 | 4 |

---

## A. Architecture & Data Storage

#### A1. Don't store SEO data in the module config ✅ Done
Original MarkupSEO kept every page's SEO values inside its own settings file. On sites with a few hundred pages this got slow and impossible to translate.
**SEO NEO:** uses real ProcessWire fields per page, the same way any other content is stored. No size limit, fully translatable.

#### A2. Provide a clean upgrade path from older versions ✅ Done
MarkupSEO 0.3 → 0.6 silently renamed fields and left orphaned data behind, requiring people to manually clean the database.
**SEO NEO:** ships a `migrate-seoneo.php` script that sets up fields, adds them to chosen templates, and lets people opt in to smart fallbacks.

#### A3. Install / uninstall must be safe to run repeatedly ✅ Done
MarkupSEO threw fatal errors if you tried to uninstall after a partial install.
**SEO NEO:** install checks for existing fields before creating them; uninstall checks each field exists before deleting and skips ones it can't find.

#### A4. Don't depend on pre-rendered strings ✅ Done
After a ProcessWire 3.0.45 change, MarkupSEO's `$page->seo->render` returned `null`, breaking many live sites overnight.
**SEO NEO:** `$page->seoneo` is a small helper object built per request; `render()` runs each time.

#### A5. Use the proper API for fieldset tabs ✅ Done
A community fix pointed out the original module wasn't instantiating `FieldsetTabOpen`/`FieldsetClose` correctly.
**SEO NEO:** uses the supported approach, so the SEO tab appears reliably.

#### A6. No risky in-place schema migrations on module update ✅ Done — **Strength**
Seo Maestro hit a recurring issue (`neophron`, Nov 2019) where upgrading the module added a new sub-property to its single composite Fieldtype (`structuredData_inherit`), but the column-add migration didn't always fire — leaving editors with `Unknown column …` SQL errors when opening any page that used the SEO field. This is a structural risk of single-fieldtype designs.
**SEO NEO:** stores each value in a normal ProcessWire field, so adding a new SEO field in a future release is a routine field-creation (handled the same way as any other module install hook). No bespoke ALTER TABLE logic, and existing data is never touched.

#### A7. "Empty means inherit" comes free with real fields ✅ Done — **Strength**
Seo Maestro encoded inheritance as a separate `*_inherit` boolean stored next to each value. To programmatically reset a page back to its template default, editors had to set both the value *and* the inherit flag together — `iNoize` (June 2020) lost a working day trying to figure out the API for this and finally gave up.
**SEO NEO:** any field left empty automatically falls back through the resolver chain (per-page → template default → smart-map → site default). Setting `$page->seoneo_title = ''` is the inheritance trigger — there is no second flag and no special API to learn.

#### A8. SEO data is searchable with the standard ProcessWire selector engine ✅ Done — **Strength**
`Ben Sayers` (March 2020) hit `Exception: Operator '~=' is not implemented in FieldtypeSeoMaestro` on a search results page. Wanze confirmed this is a hard limitation of Maestro's composite Fieldtype: meta data simply cannot be queried with `~=`, `*=`, `%=`, etc., so a site search can never include the SEO title or description.
**SEO NEO:** every value lives in a native field (`FieldtypeText`, `FieldtypeTextLanguage`, `FieldtypeTextarea`, `FieldtypeURL`, …), so all standard selector operators work out of the box. `$pages->find("seoneo_title~=widget|seoneo_description~=widget")` works exactly as you'd expect, and the values are also picked up by `SearchEngine`, `SearchPro` and any other module that walks the field index.

---

## B. Hook Conflicts (the single biggest pain point in MarkupSEO)

#### B1. Don't break FormBuilder, Lister or other admin pages with `?id=` in the URL ✅ Done
MarkupSEO inspected every admin URL and, if it saw an `id=` parameter, tried to load that as a page — which broke FormBuilder entry pages, ProcessLister, ProcessDatabaseBackups and others.
**SEO NEO:** only runs its admin hooks when the current process is the page editor.

#### B2. Don't run on admin pages themselves ✅ Done
MarkupSEO sometimes ran its frontend logic on admin templates, double-saving fields.
**SEO NEO:** explicitly skips the admin template.

#### B3. Avoid "Unknown Selector operator: ''" errors ✅ Done
A symptom of B1 — fixed by the same guard.

#### B4. Set up hooks at the right point in the request lifecycle ✅ Done
A community contributor pointed out hooks shouldn't be registered in `init()` because not everything is ready yet.
**SEO NEO:** registers hooks in `ready()`.

#### B5. Don't load the SEO tab on pages where it isn't wanted ✅ Done
Same family as the above — `seoneo_tab` is only added to templates the user opts in.

---

## C. Open Graph, Twitter & Social

#### C1. Use `property=` for Open Graph tags, not `name=` ✅ Done
Open Graph tags have always required `<meta property="og:..."/>` per the spec. MarkupSEO used `name=` for years, causing W3C validation errors and intermittent failure on Facebook.
**SEO NEO:** uses `property=` for `og:` tags and `name=` for everything else.

#### C2. Output `og:image:width` and `og:image:height` ✅ Done
Facebook silently refuses to use an `og:image` on first share if width and height aren't provided. teppo and Jason Huck both confirmed this in 2016.
**SEO NEO:** `___renderHead()` now resolves the OG image via the new hookable `___getOgImageData()` helper, which returns full metadata when the source is a real `Pageimage`. Width and height are emitted alongside `og:image` whenever `$pageimage->width` and `$pageimage->height` are non-zero.

#### C3. Output `og:image:secure_url` and `og:image:type` for HTTPS sites ✅ Done
On full-HTTPS sites Facebook's debugger asks for these even when the main `og:image` URL is already HTTPS.
**SEO NEO:** `___getOgImageData()` mirrors `og:image` to `og:image:secure_url` whenever the URL starts with `https://` (works for both real Pageimages and the module-config fallback URL), and emits `og:image:type` from a small extension-to-MIME map covering jpg/jpeg, png, gif, webp, and svg. Unknown extensions skip the tag rather than guessing.

#### C4. Pick the right OG image even when the field type is unusual ✅ Done
MarkupSEO crashed on image fields limited to one image, missing on the page, wrapped in PageTable, or using CroppableImages3.
**SEO NEO:** the OG-image resolver walks a configurable list of fields, handles single-image and multi-image fields safely, and falls back to the homepage's image and finally a module-default URL.

#### C5. Let editors choose the `og:type` per page ✅ Done
MarkupSEO hard-coded `og:type=website`. Articles, products and profiles want different values.
**SEO NEO:** new hookable resolver `___getOgType($page)` walks three layers — (1) per-page override via the new `seoneo_og_type` field on the SEO tab, (2) per-template default via an `og_type=` line in the existing per-template defaults textarea, (3) site-wide default via the new **Default OG type** select in module config (defaults to `website`). The module-config select offers the standard OG vocabulary (website, article, profile, book, product, video.*, music.*) but the per-page text field accepts any value for niche cases like `article:newsletter`. The migrate script and `___upgrade()` hook both pick up the new field automatically.

#### C6. Output `twitter:site` (the @username tag) ✅ Done
MarkupSEO never output `twitter:site`, so cards never showed an attribution to the account.
**SEO NEO:** new module-config setting **Twitter / X site handle** drives `<meta name="twitter:site">`. The leading `@` is added automatically if you forget it. A second config setting (**Default Twitter / X creator handle**) drives `<meta name="twitter:creator">`. Both come through hookable resolvers (`___getTwitterSite`, `___getTwitterCreator`) so multi-author sites can return a per-page value (e.g. by reading a `twitter_handle` field on the page's `createdUser`).

#### C7. Full Twitter tag set parallel to Open Graph ✅ Done
Twitter prefers its own `twitter:title`, `twitter:description`, `twitter:image` if present, otherwise it reads `og:*` — but some third-party scrapers don't fall back gracefully.
**SEO NEO:** `___renderHead()` now emits `twitter:title`, `twitter:description`, and `twitter:image` mirroring the resolved OG values, alongside `twitter:card`, `twitter:site`, and `twitter:creator`. All tags are skipped when their source value is empty, so there's no clutter on minimally-configured sites.

#### C8. Pass W3C HTML5 validation ✅ Done
A consequence of C1.

#### C9. Walk the page tree when resolving the OG image ✅ Done
Adrian (Nov 2019) asked for this on the Seo Maestro thread: site editors set the OG image once on a section landing page, and every descendant article should fall back to it unless explicitly overridden. Wanze rejected it for Maestro to keep the inheritance model simple, and `sz-ligatur` posted a community hook walking ancestors.

**SEO NEO:** new module-config toggle **Inherit OG image from closest ancestor** (off by default). When enabled, after checking the page's own `seoneo_og_image` and configured image fields, the resolver walks `$page->parents()` from the closest ancestor upward and reuses the same per-page lookup. Homepage default still applies as the final fallback. Implementation: `og_image_inherit_ancestors` config, `ogImageFromPage()` helper extracted from `resolveOgImagePageimage()` so the same rules apply to the page and to each ancestor.

#### C10. `og:url` follows the canonical override ✅ Done — **Strength**
`csaggo.com` (May 2020) hit a wall in Seo Maestro: the `og:url` was hardcoded to `$page->httpUrl` and could not be overridden, even when the canonical *had* been. He had a URL-segment-driven page that needed both `<link rel="canonical">` and `<meta property="og:url">` to point to the same custom URL, and Maestro forced them apart. Wanze acknowledged this was a bug and asked for a GitHub issue.
**SEO NEO:** `renderHead()` reuses the resolved canonical for `og:url` (line 424). Set `$page->seoneo_canonical = '/foo/'` and both tags follow.

#### C11. Multiple OG image source fields out of the box ✅ Done — **Strength**
`iNoize` (June 2020) ran into Maestro's hard one-field-per-placeholder limit when his sites used different image field names per template (`{images}` on some, `{immo_images}` on others). Wanze said the only fix was a bespoke hook on every site.
**SEO NEO:** the module config takes a comma-separated `og_image_fields` list (default `og_image,screenshot,images,image,blog_images`). The resolver walks the list in order and uses the first populated field, so iNoize's mixed-field case "just works" with no hooks.

#### C12. OG image is referenced, never duplicated on disk ✅ Done — **Strength**
`DV-JF` (Dec 2021, Maestro GitHub #40) reported a severe disk-bloat bug: every frontend request that resolved Maestro's OG image cloned the image into the *current* page's assets folder. After a few days of normal traffic the assets directories were full of duplicate copies of the same image. The issue is still listed as open on the Maestro tracker.
**SEO NEO:** `___getOgImage()` simply returns `$pageimage->httpUrl`. No resizing, no reformatting, no copying — the original `Pageimage` is referenced as-is. There is no code path that can write to disk during meta-tag rendering.

#### C13. Walk into nested fields when resolving the OG image and smart-map ✅ Done
Recurring Maestro request:
- `MarkE` (Sep 2020) wanted an image inside a PageTable.
- `cst989` (Jan 2021) used `{banner.image}` to reach an image on a referenced page — worked when the reference was set, fatal error when it wasn't.
- `Roych` (Feb 2021) wanted to use `body` from a repeater-matrix item as the description.

**SEO NEO:** both `og_image_fields` and `smart_map_text` now accept dotted-path syntax. A new `getDeep($node, $path)` helper walks the path one segment at a time, gracefully handling `Page`, `Pageimage`, `Pageimages`, `PageArray`, `RepeaterPage`, and `RepeaterMatrixPage` shapes. Numeric segments (`0`, `1`) and the literal `first` are recognised as collection accessors. Any missing reference at any step short-circuits to `null` rather than throwing, so an unset Page reference or empty repeater simply moves on to the next fallback. Closes the three Maestro tickets in one move.

Examples that now work:
- `og_image_fields = banner.image, gallery.first, matrix_blocks.first.image`
- `smart_map_text = description=summary,body,banner.image.description,matrix_blocks.first.body`

---

## D. Robots / Indexing

#### D1. Per-page noindex / nofollow ✅ Done
Probably the single most-requested MarkupSEO feature (Peter Knight asked twice across two years).
**SEO NEO:** dedicated `seoneo_noindex` and `seoneo_nofollow` checkboxes, with sensible global defaults.

#### D2. Avoid duplicate `<meta robots>` tags ✅ Done
In MarkupSEO, putting a robots tag in the custom field caused both the auto-injected one and the custom one to render.
**SEO NEO:** the robots tag has a single resolver; the per-page checkboxes override the defaults.

#### D3. "Noindex unpublished pages by default" toggle ✅ Done
A small quality-of-life option for staging environments and draft-heavy workflows.
**SEO NEO:** the new **Robots / indexing defaults** fieldset in module config exposes two checkboxes:
- **Auto-noindex unpublished pages** — *enabled by default* in 1.4.0. ProcessWire still allows superusers and editors with view-permission to render an unpublished page on the frontend, so without this safety net a search engine following an internal preview link could index a draft.
- **Auto-noindex hidden pages** — *off by default*. Hidden pages are publicly viewable; this toggle treats Hidden as a "not for search" signal as well.

Both are evaluated inside `___getRobots()` and only flip the noindex bit if it isn't already set by the per-page `seoneo_noindex` checkbox, so explicit editor choices always win.

#### D4. Allow `index,follow` overrides via custom tags ✅ Done
SEO NEO's per-page checkboxes are the modern equivalent.

#### D5. Don't emit a redundant `<meta robots="index,follow">` tag ✅ Done — **Strength**
`neophron` raised this on the Seo Maestro thread (Nov 2019): Maestro was outputting `<meta name="robots" content="index, follow">` on every page even though that's already the search engine default. `dragan` confirmed the tag is redundant. Maestro never fixed this — users had to suppress it via `unset()` in a `renderMetatags` hook.
**SEO NEO:** `renderHead()` only emits the robots meta tag when its resolved value differs from `index,follow`. Pages keep clean source until something is actually customised.

---

## E. Canonical URL

#### E1. Don't produce `httpss://` URLs ✅ Done
MarkupSEO did a naive find-and-replace that doubled the "s" on already-HTTPS URLs.
**SEO NEO:** uses ProcessWire's own `httpUrl`, which is environment-aware.

#### E2. Respect each template's "URL ends with slash?" setting ✅ Done
MarkupSEO always appended a trailing slash regardless of the template setting.
**SEO NEO:** `httpUrl` honours that setting natively.

#### E3. Never inherit the canonical from the parent page ✅ Done
A subtle but very damaging MarkupSEO bug: a blank canonical inherited the parent's URL, so Google effectively saw two pages as one. Guy Incognito patched this in 2022.
**SEO NEO:** an empty canonical falls back to the current page's URL, never the parent's.

#### E4. Accept relative URLs in the canonical field ✅ Done
A widely-loved Guy Incognito patch from the MarkupSEO thread: editors type `/about-us/` in the canonical field, the module renders the full domain. Particularly useful when the same content is shared across staging and production environments.
**SEO NEO:** new `absolutiseCanonical()` helper inside `___getCanonical()` accepts any of: an absolute URL with any scheme (used verbatim), a protocol-relative URL (scheme prepended), a root-relative path (scheme + host prepended), or a bare path (scheme + host + `/` prepended). The host is derived from `$page->httpUrl` rather than `$config->httpHost`, so multi-domain language hosts and per-template HTTPS settings are respected automatically. The canonical field's help text now explains the accepted formats.

#### E5. Sensible canonical on paginated and URL-segment pages ✅ Done
A long-running theme across both modules:
- MarkupSEO complaints about paginated lists (`?page=2`) producing duplicate-content canonicals.
- `psy` (Apr 2026, latest post on the Maestro thread) had to write a `renderMetatags` hook because Maestro's canonical, `og:url`, and `twitter:url` all stripped `$input->urlSegmentStr()` from the URL — so `/news/2024/article-slug/` declared its canonical as `/news/`, telling Google that every URL-segment-driven sub-page was a duplicate of its parent.

**SEO NEO:** `___getCanonical()` now routes its fallback through a new `applyCanonicalPolicies()` helper that reads two module-config radios:
- **Pagination behaviour** — *Include page number* (default, recommended) or *Always page 1*. When include is on and the request is on `pageNum > 1`, the language-aware `pageNumUrlPrefix()` (already used for hreflang) is appended.
- **URL segment behaviour** — *Include the segment string* (default, recommended) or *Parent page only*. When include is on and `$input->urlSegmentStr()` is present, it is appended.

Per-page overrides via the **Canonical URL** field always win — the policies apply *only* to the auto-generated fallback. Because `og:url` reuses the resolved canonical and `twitter:url` mirrors `og:url`, a single fix closes all three of psy's hook targets at once.

**May 2026 user feedback note:** the urlSegments scenario re-surfaced via the old PW Blog module's author-bio template (where the public URL is a urlSegment that maps to a User page, since User pages aren't FE-viewable). Without segment-aware canonicals every author bio shares the parent's canonical and Google merges them. SEO NEO already handles this natively, with a config dial for sites that want the opposite behaviour. Worth keeping prominent in marketing copy — it's a case where SeoMaestro still requires a `renderMetatags` hook in 2026.

---

## F. Multi-language

#### F1. Multi-language fields out of the box ✅ Done
MarkupSEO required users to manually convert each SEO field to a multi-language equivalent.
**SEO NEO:** when ProcessWire's languages module is active, the install routine creates multi-language field types automatically.

#### F2. Render `hreflang` alternate links ✅ Done
A long-standing wish from `ceberlin` in 2014.
**SEO NEO:** has `renderHreflangAlternates()` and emits one alternate per active language.

#### F3. Output `og:locale` and `og:locale:alternate` ✅ Done
Facebook (and other OG consumers) use these to pick the right language version of a page. SEO NEO now emits `og:locale` for the current request language and an `og:locale:alternate` tag for every other active language, alongside the existing `hreflang` block.

Resolution order for each language:
1. Manual mapping in module config (`og_locale_map`, e.g. `default=en_GB`, `de=de_AT`).
2. Auto-derive from the language record — title is used if it already looks like a locale (`fr_CA`), otherwise `xx_XX` is generated from the language name (`fi` → `fi_FI`).
3. Site-wide default (`og_default_locale`, defaults to `en_US`).

Single-language sites just emit `og:locale` from the default. The resolvers `___getOgLocale()` / `___getOgLocaleAlternates()` are hookable for sites that need fully custom logic.

#### F4. Per-language site name and title format ✅ Done
Today the global "site name" is a single string.
**SEO NEO:** introduces a new `site_name_map` config (textarea, one `langname=name` line per language) plus a hookable `___getSiteName($lang = null)` resolver. Order is:
1. Per-language entry in `site_name_map` (e.g. `de=Mein Beispiel`).
2. Site-wide `site_name` config.

Every place the global site name was previously read directly (`formatTitle()`, the `og:site_name` tag, the `{site_name}` placeholder in template defaults and title formats) now goes through `getSiteName()`, so a `de=Mein Beispiel` line flips the rendered tags — and the browser-tab title — for German visitors automatically. The textarea only appears in module config when ProcessWire's languages module is active and at least two languages exist; single-language sites see no extra UI.

The resolver is hookable, so sites that store the site name on a Settings page (the L8 plan) can return it from there with a single `$wire->addHookAfter('SeoNeo::getSiteName', …)` call.

#### F5. Preserve UTF-8 characters in meta tags ✅ Done — **Strength**
A long, painful saga in Seo Maestro:
- `tires` (Oct 2019) reported German umlauts rendered as `&auml;` in meta description.
- `Lutz` argued (correctly) that on a UTF-8 site, `htmlentities()` over-encodes — `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')` is the right tool.
- Wanze attempted a fix that broke it for Nordic alphabets — `VeiJari` (Nov 2019) reported `Ä Ö Å` were broken in 1.0.0.
- `dragan` (Jan 2020) confirmed yet another follow-up patch.

Maestro went around the loop several times because its escaping function tried to second-guess the encoding.
**SEO NEO:** every meta tag value is escaped with `htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')` from day one. Umlauts, Scandic letters, Cyrillic, Greek and CJK characters all pass through cleanly with no module-specific encoding logic.

#### F6. Honour per-language domains in `hreflang` ✅ Done
`uiui` (May 2020) had a multi-domain multi-language ProcessWire site (`fr.example.com`, `de.example.com`, etc., configured via core's `LanguageSupportPageNames`). Maestro emitted `hreflang` links with the same base domain for every language, forcing them to write a regex hook to swap domains afterwards.
**SEO NEO:** as part of the F8 refactor, `___renderHreflangAlternates()` now uses `$page->localHttpUrl($lang)` — the API designed for "give me the URL of this page in another language" — instead of temporarily reassigning `$user->language`. Per-language domains configured via `LanguageSupportPageNames` are picked up automatically, with no user-state mutation. The previous fallback (assigning `$user->language` and reading `$page->httpUrl`) is retained only for installs without `LanguageSupportPageNames`.

#### F7. Empty per-language values fall back to default language ✅ Done — **Strength**
`markus_blue_tomato` (Oct 2020) raised this on the Maestro thread: if an editor filled in the German title but left the French one blank, Maestro emitted an empty `<title>` on the French version of the page. Standard ProcessWire `FieldtypeTextLanguage` fields fall back to the default language automatically, but Maestro had reimplemented language storage from scratch and the fallback was missing. He filed a PR (#27) but at the time of writing it has not shipped.
**SEO NEO:** when ProcessWire's languages module is active, `___install()` creates `seoneo_title` as `FieldtypeTextLanguage` and `seoneo_description` as `FieldtypeTextareaLanguage` (lines 137‑139). Default-language fallback is therefore automatic — the same behaviour every other multi-language ProcessWire field has had since 2014.

#### F8. Include pagination segments and URL segments in `hreflang` URLs ✅ Done
`pmichaelis` (Apr 2025) opened a Maestro issue with a code patch: on a paginated list (`/news/page2/`), the `hreflang` alternates were pointing to `/news/`, so Google was being told the German "page 2" of news was the alternate of the English "page 1".

**SEO NEO:** `___renderHreflangAlternates()` now detects `$input->pageNum()` and `$input->urlSegmentStr()` from the current request and appends both to every alternate URL. The pagination prefix is per-language — `$config->pageNumUrlPrefixes` (e.g. `'de' => 'seite'`) is honoured, falling back to the core default `page`. Building blocks: `buildLanguageUrl()` and `pageNumUrlPrefix()` helpers. The same call now uses `$page->localHttpUrl($lang)` instead of mutating `$user->language`, which also closes the F6 multi-domain partial.

#### F9. Emit `hreflang="x-default"` for the default language ✅ Done
Same Maestro patch from `pmichaelis`. Google explicitly recommends emitting `<link rel="alternate" hreflang="x-default" href="…">` to signal which language version to show users whose locale isn't covered.

**SEO NEO:** when iterating over languages, the resolved URL for the default language is captured and emitted a second time as `hreflang="x-default"` after the per-language alternates. No extra config required.

---

## G. Title Format & Smart Defaults

#### G1. Per-template title format ✅ Done
A photo-blog example from `Frank Vèssia` in 2015: each template wants its own title pattern. `Tyssen` (Apr 2022) asked the same of Maestro — wanted the homepage to use `Site Name – tagline` while interior pages used `Page Name – Site Name`. Maestro had no first-class answer; `fliwire` posted a `renderSeoDataValue` hook workaround.
**SEO NEO:** `template_defaults_text` lets per-template defaults be defined (title, description, etc.). The `home` template gets its own line, so Tyssen's case is a one-line config change with no hooks.

#### G2. `{pageNum}` placeholder for paginated lists ✅ Done
On a list page that uses MarkupPagerNav, editors want `Articles | Page 2 | My Site` rather than just `Articles | My Site`.
**SEO NEO:** two new placeholders are recognised everywhere SeoNeo expands tokens — both `formatTitle()` (the global title format) and `expandTemplateString()` / `resolvePlaceholderToken()` (template defaults):

- `{pageNum}` — renders the localised string `Page N` when the current request is on `$input->pageNum() > 1`, and an empty string on page 1.
- `{pageNumber}` — renders the bare integer (`2`) when on page > 1, empty otherwise. Useful for editors who want to wrap it themselves: `Page {pageNumber}`.

The recommended idiom is `{title}{separator}{pageNum}{separator}{site_name}` — this collapses cleanly to `Articles | My Site` on page 1 and expands to `Articles | Page 2 | My Site` on page 2 (the `{separator}` adjacent to a blank `{pageNum}` is stripped by the existing separator-collapse logic from G4). Wired into `expandTemplateString()` so the same tokens work inside per-template description defaults too.

#### G3. Pipe-fallback inside placeholders ✅ Done
e.g. `{seoneo_title|long_title|title}` to use the first non-empty.
**SEO NEO:** `expandTemplateString()` was rewritten around a single placeholder regex that hands each token to a new `resolvePlaceholderToken()` helper. Tokens may now contain pipe-separated fallbacks — every alternative is resolved in order, the first non-empty value wins. Each alternative can be:

- a special keyword (`title`, `site_name`)
- the existing `page.fieldname` syntax
- a bare field name shorthand
- a dotted path that reaches into nested data (`banner.image.description`)

Unknown tokens with no pipes/dots are still rendered literally so existing template-default strings keep working unchanged.

#### G4. Drop the separator when the site name is empty ✅ Done
A small but very visible bug: homepage rendering as `Home | ` with a trailing pipe.
**SEO NEO:** `formatTitle()` collapses empty parts gracefully.

#### G5. Show editors what the resolved values will be ✅ Done
Many MarkupSEO users wrote save-time hooks to copy `title` into `seo_title` so the editor could see what was being used.
**SEO NEO:** the SERP-preview Inputfield (`InputfieldSeoNeoPreview`) now renders an **Effective values** disclosure panel directly under the Google-style SERP card. The panel is server-rendered (so it always reflects what `___renderHead()` will actually emit), and shows seven rows in a compact table:

- Title — the resolved `<title>` after smart-map / template default / format application.
- Description — same, after auto-truncate / strip-tags pipeline.
- Canonical — including the new pagination + URL-segment policies (E5).
- Robots — `index,follow` etc., reflecting D3 auto-noindex rules.
- OG type — global default, per-template, or per-page override.
- Site name — per-language if F4's `site_name_map` is in use.
- OG image — thumbnail + full URL, linked to open in a new tab; falls back to "(no image — twitter:card falls back to summary)" when nothing is resolved.

Empty values are shown as `(empty — tag will be skipped)` so editors immediately see why a tag isn't rendering. The panel uses a native `<details>` element, so it's collapsed by default and adds no JS weight. The frontend `SeoNeoBar` (L7) keeps its role as the deeper inspection tool — the new panel just gives editors a quick "what will this page look like to Google?" reference without leaving the page-edit form.

#### G6. Page title can differ from the `<title>` tag ✅ Done
A core MarkupSEO design decision that's preserved: the page's everyday title (used in nav etc.) and the SEO/browser-tab title are independent.

#### G7. Strip HTML when smart-mapping from `body` ✅ Done
MarkupSEO's smart description used the raw HTML, which broke layouts and cut off mid-tag.
**SEO NEO:** runs `strip_tags()` on smart-mapped values.

#### G8. Truncate over-long smart-mapped descriptions ✅ Done
If `body` is several thousand characters long, the resulting `<meta description>` is huge.
**SEO NEO:** new `truncateDescription()` helper, called only on auto-resolved values (smart-map and template defaults), strips HTML, collapses whitespace, and cuts at the last word boundary before the configurable **Max description length** (default 180 chars) with `…` appended. Trailing punctuation is stripped before the ellipsis (no `,…` or `..…`). UTF-8-safe (`mb_*` throughout) so the F5 strength is preserved. Values typed directly into `seoneo_description` are returned verbatim — if an editor wants a 220-char description on one page, they get exactly that. Setting the limit to 0 disables truncation entirely.

#### G9. Optional ancestor-walk for smart-mapped values ✅ Done
Generalisation of C9. Today `resolveSmartMap()` only inspects the current page's fields. Useful real-world cases — a section landing page's "section description" used as a description fallback for every article inside it — are unlocked by an opt-in ancestor walk.

**SEO NEO:** `___resolveSmartMap()` now recognises a leading `*` on any field name as the *inherit-from-ancestors* signal. The same field is checked first on the current page (using the existing dotted-path / unformatted / strip-tags pipeline factored into a new `readSmartMapValue()` helper); if blank, the resolver walks `$page->parents()->reverse()` (parent first, then grandparent, etc.) and returns the first non-empty value. Mixing inheritable and non-inheritable entries in the same fallback chain works as you'd expect:

```
description=summary,body,*section_description,banner.image.description
```

Off by default — entries without `*` retain the original page-only behaviour, so existing installs see no change on upgrade. Combined with the C9 OG-image ancestor walk, this gives editors a consistent "section landing page supplies the defaults for everything underneath" pattern without writing a single hook.

#### G10. Sanitise meta-tag values: strip tags and bypass output formatters ✅ Done
Two related Seo Maestro reports point at the same root cause:

- `palacios000` (April 2020) — "the field I set up to display the default `og:description` is a textarea with HTML formatting", so the output meta tag was `<meta property="og:description" content="&lt;p&gt;La linea di Pomate Freita…">` — encoded HTML inside the attribute.
- `gebeer` (Jan 2020) — a Page Title field with the **HTML Entity Encoder** textformatter caused **double encoding** in Maestro's preview and rendered meta tags ("Über uns" → "&amp;Uuml;ber uns"). Wanze had to ship a 1.0.1 patch.

**SEO NEO:** `readField()` now always uses `$page->getUnformatted($fieldName)` so the textformatter chain (HTML Entity Encoder, Markdown, Smartypants, Hanna Code, etc.) cannot pre-encode the value — our own `esc()` then runs exactly once at output. Stripping HTML tags and collapsing whitespace happens at read time, so a CKEditor field plumbed into `role_description` no longer leaks `<p>` and `&nbsp;` into the meta tag. The `___resolveSmartMap()` fallback path was hardened the same way. The `seoneo_custom` field is the deliberate exception — it's rendered verbatim by design and bypasses this helper.

---

## H. Custom / Free-form Meta

#### H1. Per-page custom meta textarea ✅ Done
MarkupSEO had a per-page `seo_custom` textarea where editors could paste site-verification snippets, Yandex, Bing, ahrefs, Pinterest verification, etc.
**SEO NEO:** added `seoneo_custom` to `DEFAULT_FIELDS` (a `FieldtypeTextarea`, 4 rows, collapsed-when-blank). `___renderHead()` emits the field's contents verbatim — no escaping, by design — right before the closing `<!-- /SeoNeo -->` marker, so per-page snippets always come *after* SeoNeo's own tags. The field's help text warns editors that anything pasted is rendered as-is, and the field carries the `SeoNeo` tag so it can be locked down via the standard PW field-permissions UI. The companion `migrate-seoneo.php` script was updated to include `seoneo_custom` (and the previously-omitted `seoneo_og_image`) in its tab-order list. A new `___upgrade()` hook plus the extracted `createMissingFields()` helper means existing 1.1.x installs will get the field added automatically when they upgrade to 1.2.0.

#### H2. Global custom tags via `key := value` lines ✅ Done
**SEO NEO:** the module config has `custom_tags_text` parsed by `parseKeyListText()`.

#### H3. Yandex / Bing / Google site-verification snippets ✅ Done
Doable today through global custom tags, but not a first-class editor experience.
**SEO NEO:** a new **Search engine verification** fieldset in module config exposes six dedicated text inputs, each labelled with the service name and noted with the exact `<meta>` tag it produces:

- Google Search Console → `<meta name="google-site-verification" content="…">`
- Bing Webmaster Tools  → `<meta name="msvalidate.01" content="…">`
- Yandex Webmaster      → `<meta name="yandex-verification" content="…">`
- Pinterest             → `<meta name="p:domain_verify" content="…">`
- Facebook Domain       → `<meta name="facebook-domain-verification" content="…">`
- Baidu Webmaster       → `<meta name="baidu-site-verification" content="…">`

Editors can paste either the bare token (`abc123…`) or the full `<meta name="…" content="…">` snippet — `getVerificationMetaLines()` extracts the `content` attribute either way and emits a single, normalised tag. An additional **Emit verification tags on the homepage only** checkbox (on by default, since most services only check the root URL) keeps the rendered HTML clean on inner pages.

#### H4. Author meta tag ✅ Done
Could be added by the user via custom tags but no dedicated field.
**SEO NEO:** new hookable `___getAuthor(Page $page)` resolver with a two-tier fallback:
1. Per-page `seoneo_author` field if it exists on the template (opt-in — editors can add a `FieldtypeText` field with that name to any template that needs per-page authors; it isn't installed by default).
2. Site-wide `meta_author` config setting.

When the resolver returns a non-empty value, `<meta name="author" content="…">` is emitted in the SeoNeo block. Both tiers go through the same `esc()` pipeline as every other tag, so umlauts, Cyrillic etc. round-trip cleanly. Sites that prefer to derive the author from a Page reference (e.g. `seoneo_author = createdUser`) can hook the resolver and return whatever they like.

#### H5. Dublin Core and other namespaced meta ✅ Done
Anything keyed `dc.title := …` works through `custom_tags_text`.

#### H6. No fight with ProcessWire over the generator meta tag ✅ Done — **Strength**
A long-running Maestro friction point:
- `tires` (Oct 2019) wanted to suppress `<meta name="generator" content="ProcessWire">` on a German site.
- `Krlos` (Aug 2022) had used a `renderMetatags` hook to `unset($tags['meta_generator'])` for years, then a Maestro update broke the hook.
- Open Maestro GitHub issue #51 has been asking for "Respect `$config->poweredBy`" since 2022 with no fix in sight.

The root problem is that Maestro hard-codes a `<meta name="generator">` line and ignores ProcessWire core's `$config->poweredBy` toggle.
**SEO NEO:** doesn't emit a generator meta tag at all (`renderHead()` has no `meta_generator` line). ProcessWire core's own X-Powered-By/`<meta name="generator">` behaviour is left untouched. Editors who *want* the tag can add `meta:generator := ProcessWire` to their `custom_tags_text`. Editors who don't want it have nothing to suppress in the first place.

---

## I. Analytics & Tracking — intentionally out of scope ⚫

A large chunk of historical MarkupSEO bug reports came from analytics integrations:

- I1. GA snippet vs. ProCache HTML minify conflicts.
- I2. Piwik undefined index errors.
- I3. Google Tag Manager support.
- I4. Yandex.Metrika integration.

**SEO NEO position:** keep analytics out of the module. The SEO concerns and the analytics concerns are independent, and bundling them was the source of dozens of MarkupSEO support tickets. Recommend dedicated modules (e.g. *Native Analytics*, GTM via tag-manager modules) in the README.

---

## J. Schema / Structured Data

#### J1. JSON-LD Schema.org generator with hookable type helpers 🔴 Gap (P1 — in-core)
Joss raised this back in 2014 and it's only become more important since. Independent confirmation came via user feedback in May 2026: schema helpers are one of two genuinely-missing meta-handling features people actually ask for (the other being native urlSegments support, which is already done — see E5).

**Revised plan (May 2026):** bring this into the core module rather than deferring to a companion. Reasoning:
- It's meta-tag rendering, which is squarely SEO NEO's wheelhouse.
- Keeping it in core means one install, one config screen, one place to look.
- The companion-module split was originally proposed in 2024 to keep core lean; a few well-chosen helpers don't justify a separate distribution.

**Architecture (May 2026, expanded):** the full design rails for the JSON-LD subsystem live in **`SeoNeo/JSONLD-ARCHITECTURE.md`**. That document is the single source of truth for the resolver, cascade, source-spec grammar, multilingual rules, repeater / RepeaterMatrix / PageReference handling, `@id` strategy, validation/preview tooling, and the file-based + hook-based extension surface. The backlog entries below are the index — depth lives in the architecture doc.

Headline architectural commitments (read the doc for the full design):

- **One resolver, one cascade, one hook surface** across every schema type. Adding a type is a declaration, not a re-implementation. No per-type re-invention of value extraction, language handling, or source selection.
- **Source-spec grammar** (a closed set of source kinds: `literal`, `field`, `field_path`, `field_on` (named/selectable page), `ancestor_field`, `iterate` (over Repeater / RepeaterMatrix / PageTable / PageReference multi), `hook`, `auto`). Source specs are composable so iterated sub-nodes use the same grammar recursively.
- **Cascade order**: per-page override (incl. an "Extra schema nodes" structured field for ad-hoc nodes) → per-template mapping → module-wide defaults (the existing `jsonld_*` config) → built-in auto-detection floor.
- **Multilingual is a property of the resolver, not of each type.** Multilang fields resolve in the active language at render time. The existing `jsonld_org_name_map` / `jsonld_org_description_map` config keys become a degenerate case of `literal + lang_map`, so they continue to work unchanged.
- **Repeater / RepeaterMatrix / PageTable / PageReference as first-class inputs** via the `iterate` source kind. RepeaterMatrix items can be filtered by matrix item type so different matrix items map to different sub-schemas. PageReference targets can either expand inline as sub-nodes or be referenced by `@id`.
- **Single `<script type="application/ld+json">` per page, single `@graph`, cross-referenced by `@id`.** Defined `@id` strategy per node (URL + fragment derived from `@type`). Same `Organization` `@id` across the whole site so search engines connect the dots.
- **Person doesn't inherit business defaults** (telephone, email, address, sameAs, logo) from the module's Organization config. Enforced as a type-definition policy.
- **Validation + preview tooling** in three places: a JSON-LD disclosure panel under the existing Effective Values panel in the admin SEO tab, a JSON-LD tab in SeoNeoBar (frontend, logged-in admins), and a programmatic `$page->seoneo->jsonLdReport()` API.
- **Generic Custom type** with the same source-spec machinery for any Schema.org type the module doesn't ship first-class — so we never paint into the "you have to fork the module to add a type" corner.
- **Two equivalent extension on-ramps**: file-based registration (drop a definition file into a conventional folder) and hook-based registration (`SeoNeo::registerSchemaTypes`). Sub-modules can ship type definitions as part of larger features.
- **Full backward compatibility.** Existing module config keys (`jsonld_enabled`, `jsonld_pretty`, `jsonld_breadcrumbs`, `jsonld_default_author`, `jsonld_org_*`, `jsonld_*_templates`) keep their meaning — they map onto layers of the new cascade. Existing `___build…` and `___getJsonLd` / `___renderJsonLd` hook signatures stay stable. A site that upgrades and changes nothing produces the same JSON-LD output as before.

**API shape:** developers call generators from templates rather than relying on auto-detection (auto-detection without explicit mapping produces wrong structured data — every attempt has shown this). Each generator has a hookable resolver so per-site customisation stays clean. The template helper one-liner stays available for the "do it in code" audience:

```php
echo $page->seoneo->renderJsonLd();             // emits the auto-detected graph for the page
echo $page->seoneo->schema('Article');          // single type, uses cascade + resolver
echo $page->seoneo->schema('FAQPage', [
    'mainEntity' => $page->faqs,                // overrides enter the cascade at top priority
]);
$report = $page->seoneo->jsonLdReport();        // structured validity report (per-node, per-property)
```

**First-class types:**

P1 (must ship before the JSON-LD subsystem is considered "done"):

- Already present today (move onto the new resolver + cascade): `Organization`, `WebSite`, `WebPage`, `Article`, `Person`, `BreadcrumbList`.
- New first-class additions in P1, motivated by being among the most commonly requested Schema.org types in real content sites:
  - `FAQPage` — repeater of `{question, answer}` is the canonical input.
  - `LocalBusiness` (typed extension of `Organization`, with address, geo, openingHours, telephone). Module config gains a "site is a LocalBusiness" toggle that swaps the auto-emitted Organization node for a LocalBusiness node and exposes the extra fields.
  - `Product` — name, description, image, brand, offers (list of `Offer` from a repeater), aggregateRating (optional).
  - `Event` — name, description, startDate, endDate, location (`Place`), organizer (PageRef → Organization), performer (PageRef multi → Person/Organization).
- Article subtypes (`NewsArticle`, `BlogPosting`) supported via the `@type` array on the existing Article definition — no separate type definitions needed.

P2 (first-class but not blocking the subsystem release; must require zero new resolver capabilities — if they do, the resolver is missing a feature and that gap is fixed first):

- `VideoObject`
- `HowTo` (steps via Repeater / RepeaterMatrix)
- `Recipe`
- `Review` / `AggregateRating` (embedded on `Product` initially; standalone P3)
- `JobPosting`

P3: anything else, addressed via the generic Custom type with no module changes required. Promotion to first-class happens when a P3 type proves popular enough to deserve a default mapping.

**Lessons to inherit:**
- `BreadcrumbList` implementations have historically walked all parent pages, including the admin root and any PageRepeater wrapper pages, leaking internal IDs into Google's structured-data tester. SEO NEO's implementation must filter `template=admin`, `class=RepeaterPage`, and similar non-public ancestors before serialising. (The current builder already does this; calling it out so the requirement survives the resolver refactor.)

#### J2. Type-specific helpers and per-type extension 🔴 Gap (P1 — in-core)
Same subsystem as J1. Per-type and per-property hooks are the stable extension surface, documented in `SeoNeo/JSONLD-ARCHITECTURE.md` §11:

```php
$wire->addHookAfter('SeoNeo::buildJsonLdArticle', function(HookEvent $e) {
    $data = $e->return;
    $data['articleSection'] = $e->arguments(0)->parent->title;
    $e->return = $data;
});

// Per-property resolution (e.g. force a specific source for one site):
$wire->addHookAfter('SeoNeo::resolveJsonLdValue', function(HookEvent $e) { … });

// Whole-graph last-chance mutate:
$wire->addHookAfter('SeoNeo::finalizeJsonLdGraph', function(HookEvent $e) { … });

// Register an additional first-class type from a sub-module:
$wire->addHookAfter('SeoNeo::registerSchemaTypes', function(HookEvent $e) {
    $e->return['Course'] = [ '@type' => 'Course', 'properties' => [ … ], 'default_mapping' => [ … ] ];
});
```

Sites that need types not in the P1/P2 first-class set (Schema.org's long tail) reach them through the **generic Custom type** with the same source-spec grammar — no need to hook anything. The Custom path is the architecture's escape valve, and is what stops the type list from becoming a never-ending feature backlog.

---

## K. Auto-injection / Rendering

#### K1. Configurable injection position ✅ Done
MarkupSEO injected immediately before `</head>`, which some users felt was too low.
**SEO NEO:** new module-config radio **Injection position** with two values:
- **Top** — meta block is inserted right after the opening `<head>` tag, so SEO tags render before any other tags emitted by templates or third-party modules. Useful when those tags themselves rely on canonical/meta information being present.
- **Bottom** — historical default, inserts before `</head>` so template-level meta tags win.

The change is a small addition to `hookPageRenderInject()` — when set to `top`, a `<head[^>]*>` regex match is used (capturing any attributes); the bottom path is unchanged. Existing installs default to `bottom` so behaviour is unchanged on upgrade.

#### K2. Survive ProcessWire core changes ✅ Done
The PW 3.0.45 incident broke `$page->seo->render`. SEO NEO renders on demand each request, so similar core changes shouldn't affect it.

#### K3. Mutate values before render ✅ Done
A long-running MarkupSEO frustration — wanting to override `og:image` from a template just before output.
**SEO NEO:** every value comes from a hookable resolver method (`___getOgImage()`, `___getTitle()` etc.), and every backing field can be set directly with `$page->seoneo_og_image = $img`.

#### K4. Don't emit empty meta tags ✅ Done
MarkupSEO sometimes wrote `<meta name="description" content="" />` when nothing was set.
**SEO NEO:** empty values are skipped before `implode()`.

#### K5. Play nicely with ProCache HTML minify ✅ Done
Reports from Pete and Peter Knight in 2016 — ProCache's minifier could strip or mangle injected blocks.

**SEO NEO:** the architecture happens to be ProCache-friendly because the `Page::render` hook runs *before* ProCache stores the cached output. The meta block is baked into the cached HTML on first build and served directly from disk on subsequent hits. The minifier may strip the cosmetic `<!-- SeoNeo -->` comments, but real `<meta>` elements are untouched.

The README now has a dedicated **ProCache compatibility** section that:
- Explains the cache-miss / cache-hit lifecycle and why no special configuration is needed;
- Documents the recommended pattern for editors who want absolute control over placement (disable auto-inject and call `echo $page->seoneo->render()` from the template);
- Notes the minifier behaviour around HTML comments;
- Reminds users that SEO-field changes need ProCache cache invalidation.

When the module-config screen is opened on a site where ProCache is installed, a collapsed **ProCache detected** notice points at the README section. No auto-disable behaviour was added — testing showed it's unnecessary, and it would be a footgun for sites that *want* the cached block.

#### K6. Compatible with Autojoin and other field-level performance options ✅ Done — **Strength**
`markus_blue_tomato` (Mar 2021) discovered that enabling Autojoin on a Seo Maestro field broke saving entirely — the page editor silently failed to persist any SEO data. He filed PR #31 to *remove* the Autojoin option from the field's settings page so editors couldn't shoot themselves in the foot.
**SEO NEO:** every value lives in a normal core ProcessWire field. Autojoin works exactly as it does for any other text field — and on a single-page render that uses the SEO data, it can even be a small performance win.

---

## L. Editor UX (admin tab)

#### L1. SERP preview shows the public URL, not the admin URL ✅ Done
A common MarkupSEO bug — fixed there in 2014.
**SEO NEO:** uses `httpUrl` from the start.

#### L2. Title and description character counters ✅ Done
**SEO NEO:** counters with configurable amber and red thresholds.

#### L3. Hard-cap input length at 60/160 chars ✅ Done
Opinion is split — most users prefer a soft warning (the current behaviour); some editorial teams want the browser to physically refuse keystrokes past the limit.
**SEO NEO:** two new module-config integers — **Hard-cap title input length** and **Hard-cap description input length** — both default to `0` (disabled). When set above 0, a `Inputfield::render` hook only runs in `ProcessPageEdit` and adds the `maxlength` HTML attribute to the title / description inputs. The soft amber/red counter (L2) keeps running underneath, so editors still see the warning thresholds. The hook respects whatever field names are mapped to `role_title` and `role_description`, so renamed installs work too.

#### L4. Verify the SERP preview layout on narrow screens ✅ Done
A 2015 report showed the URL line wrapping into the description column.
**SEO NEO:** the existing card was already block-stacked (so column-wrapping was structurally impossible) but the rules were tuned for desktop. `assets/SeoNeo.css` now adds:
- `box-sizing: border-box` and `overflow-wrap: anywhere` on the preview card so long URLs and unbreakable strings can't push the card past its column.
- A `@media (max-width: 600px)` block that drops padding (14px → 12px), tightens the title to 17px / 1.25 line-height, the description to 13px, and the URL to 11px — matching the proportions of an actual mobile Google SERP card.
- A second `@media (max-width: 380px)` step that further reduces padding and title size for very narrow ProcessWire admin panes (the page-edit form column can drop below this on tablets in landscape with the sidebar open).
- A separate responsive treatment for the new G5 *Effective values* table — at <480px it switches from a two-column layout to stacked label-then-value blocks so the OG image thumbnail and long URLs stay readable.

#### L5. Field values persist between admin visits ✅ Done
A famous MarkupSEO bug where the Twitter username field went blank on revisit.
**SEO NEO:** real fields can't go blank like that.

#### L6. Bulk-add the SEO tab to many templates ✅ Done
**SEO NEO:** the migration script handles this.

#### L7. Show resolved SEO data on the public page for logged-in admins ✅ Done — **Strength**
No equivalent in MarkupSEO. SEO NEO ships a companion `SeoNeoBar` that shows the rendered values inline on the frontend for logged-in admins. This alone is a strong selling point.

#### L8. Editor-friendly admin UI for template defaults 🔴 Gap (P3 — companion module)
`StanLindsey` (Dec 2020) raised this on the Maestro thread: site editors couldn't manage their own SEO defaults because Maestro's defaults lived inside the field configuration screen, which is gated to superusers. Several other Maestro users mentioned similar setups using a "Settings" page.
**SEO NEO:** today template defaults are configured via `template_defaults_text` in the module config — same problem, superuser-only. The 1.4.0 release made the `___getSiteName()` resolver hookable specifically so this companion can plug into it.
**Roadmap:** intentionally deferred to a separate companion module (`SeoNeoSettings` or similar). The companion will:
- Expose template defaults as editable fields on a designated Settings page so non-superuser editors with edit access to that page can manage them.
- Keep `template_defaults_text` as the canonical source — the Settings page just reads and writes it via a save hook.
- Ship as an *optional* install (not bundled into core SeoNeo) so sites that prefer the textarea config screen aren't forced to switch.

Tracking this separately from the core module so the 1.4.0 release can ship without waiting on the companion's own design and review cycle.

---

## M. PHP / ProcessWire Compatibility

#### M1. PHP 7+ "illegal string offset" issues ✅ Done
A 2016 MarkupSEO bug was caused by initialising a variable as a string and then using it as an array.
**SEO NEO:** strict PHP 8.1 typed code, no implicit coercion.

#### M2. PHP 8.1 deprecation noise ✅ Done
Reports from `tires` in 2023 — `trim(null)`, undefined array keys etc.
**SEO NEO:** uses null-coalescing operators and `match()`/typed signatures throughout.

#### M3. Survive ProcessWire core upgrades ✅ Done
The 3.0.45 hook-property regression broke MarkupSEO. SEO NEO uses a different mechanism that doesn't depend on the same code path.

#### M4. Safe uninstall on partially-installed sites ✅ Done
The "Argument 1 passed to Fields::___delete() must implement Saveable, null given" error from MarkupSEO is impossible in SEO NEO — the uninstall routine checks each field exists first.

#### M5. Single source file (no `.module` + `.module.php` collision) ✅ Done

---

## N. Companion-module wishes — out of scope ⚫

These came up in the MarkupSEO thread (and the May 2026 user-feedback round) and are intentionally not on the SEO NEO roadmap. Rationale captured here so the answer is the same the next time someone asks:

- **N1. Yoast-style traffic-light content scoring.** Mentioned by Pete on the MarkupSEO thread (2022) and explicitly raised — and rejected — by user feedback in May 2026: *"tends to produce text optimised for the algorithm rather than the reader."* Visible-green-dot UX nudges editors toward keyword-stuffed prose that ranks worse with modern semantic search than well-written copy. A well-built `SeoNeoScore` companion may be possible one day, but the default position is no.
- **N2. Auto keyword extraction.** Low value — `meta keywords` is ignored by every major search engine in 2026 and has been since ~2009.
- **N3. XML sitemap generator.** See section O for the full reasoning. Defer to MarkupSitemap.
- **N4. `llms.txt` generator.** Independent log-file analysis (May 2026) confirms GPTBot, ClaudeBot, and PerplexityBot don't actually fetch `/llms.txt`. The spec is unofficial and no LLM lab has committed to honouring it. Worth revisiting if that changes — until then, generating a file nobody reads is misleading.

**Moved out of this section:** the previous N4 entry (URL redirects manager — *"defer to ProcessRedirects / Jumplinks"*) has been promoted to the SEO NEO PRO companion bundle (see section Q1). User feedback in May 2026 made the case that an in-house, integrated answer is more valuable than the third-party-dependency route.

---

## O. Sitemap generation — intentionally out of scope ⚫

The Seo Maestro thread is a cautionary tale on bundling sitemap generation into an SEO module. Across pages 5‑7 alone the thread surfaced:

- **O1. Silent failures from non-writable paths** (`markus_blue_tomato`) — sitemap was attempted on every request, slowing the admin panel ~100×, with no error visible to the editor.
- **O2. Misleading "not writable" error** (`psy`, `teppo`, `neophron`) — the same exception was thrown when there were simply no items to include, sending people on long permission-debugging detours.
- **O3. Path resolution issues under `open_basedir` / chrooted PHP-FPM** (`titanium`) — surfaced as a `__toString()` fatal because the underlying exception was thrown from inside Maestro's stringification.
- **O4. Couples sitemap policy to SEO field design** — Maestro's per-template "include in sitemap?" toggle and per-page "exclude" override forced editors to think about indexing, sitemap inclusion, robots, and canonical at the same time, which is more cognitive load than necessary.

**SEO NEO position:** keep sitemap generation **out of the module entirely**. Recommend `MarkupSitemap` (or a successor) in the README. The robots/noindex fields SEO NEO already provides are independent and can be read by any sitemap module. If a tightly-integrated companion is ever wanted, build it as `SeoNeoSitemap` rather than rolling it into core.

---

## P. Audit / Lister View

#### P1. SEO health audit as a Lister-based admin view 🔴 Gap (P1 — in-core)
Raised in user feedback (May 2026) as the single highest-leverage missing feature in the PW SEO ecosystem: nobody currently surfaces "where are the missing descriptions, duplicate titles, accidental noindex flags, missing OG images" in one view. SeoMaestro can't do this — its single composite Fieldtype doesn't support the selector operators (`~=`, `*=`, `%=`) needed to query SEO data, as Wanze confirmed in March 2020 (see strength A8).

**SEO NEO is uniquely well-positioned to ship this** because every value lives in a normal core ProcessWire field, so `ProcessPageLister` can already query them. The feature is mostly UI work over an architectural strength that's already in place.

**Scope:**
- A dedicated **SEO Audit** entry under the Admin menu, opening a `ProcessPageLister`-derived view.
- Pre-baked filter chips for the common questions:
  - *Missing description* — `seoneo_description=''` (with smart-map fallbacks taken into account where possible)
  - *Missing title override* — `seoneo_title=''` on templates where the page title isn't a sufficient SEO title
  - *Duplicate titles* — pages whose resolved `<title>` matches another page's (across templates)
  - *Duplicate descriptions* — same idea for description
  - *Noindex flagged* — `seoneo_noindex=1` (catches accidental flags from staging copies)
  - *Missing OG image* — pages whose resolver chain returns the site-wide default image only
  - *Over-length title / description* — exceeds the configured hard or soft thresholds
- Inline edit links straight to the page editor's SEO tab.
- Bulk actions where they make sense (e.g. "clear noindex" across selected results).
- CSV export of the current filter result for off-platform audits.

**Implementation note:** lean on `ProcessPageLister` and standard PW admin theming rather than building a custom dashboard. The duplicate-detection queries are the only piece that needs custom SQL — everything else is selector-engine work.

**Why this lands in core, not PRO:** it operates entirely on SEO NEO's own data (no third-party module dependencies, no operational tooling), and it's the most visible expression of the architectural choices in section A. Putting it behind a paywall would undersell the free product.

---

## Q. SEO NEO PRO — Companion Bundle 🔵 PRO planned

A separately-distributed paid bundle, modelled on Ryan's `Pro*` modules. Core SEO NEO stays free and focused on meta-tag rendering plus the audit view. PRO adds operational tooling that goes beyond meta tags — work that has real maintenance cost and clear time-saving value, which makes a sustainable funding model.

**Bundle positioning:** one licence, one download, modules light up additively when their dependencies are present. Sites that only need the meta layer never see the PRO surface and never pay.

#### Q1. URL Lifecycle Manager — design overview 🔵 PRO planned
**Reframe (May 2026):** rather than building "a 404 logger plus a redirect manager bolted together", model it as a single URL Lifecycle Manager. Any URL on the site is in one of four states:
1. **Live** — resolves to a real page
2. **Redirected** — actively forwards somewhere
3. **404 hotspot** — getting hits but going nowhere
4. **Retired** — was a 404 but has been acknowledged/dismissed

Single admin view, four filters, one *"promote to redirect"* action that moves a URL from state 3 to state 2. Neither Process404Logger nor ProcessRedirects can claim this view because they were built separately and connected only by convention.

#### Q2. URL Lifecycle Manager — redirect engine 🔵 PRO planned
The features that ProcessRedirects has spent years getting right and that we can't ship without:

- **Wildcard / regex sources** — `/old-blog/*` → `/new-blog/$1` with proper capture groups, plus an admin pattern-tester before saving. Skipping this loses every site migrating off ProcessRedirects.
- **HTTP status codes** — 301 / 302 / 307 / 308 properly distinguished, with sensible defaults and inline help explaining when to use which.
- **Page-reference targets** — destination is a `Page` reference where possible, so renaming the destination page updates the redirect automatically. Falls back to a literal URL for external destinations.
- **Hit counters and last-hit timestamps** — written via deferred tally + cron flush, never on every request (writes to the redirect row from a hot loop are a ProcessRedirects pain point).
- **CSV import / export** — covers most "I'm migrating from ProcessRedirects" cases. Native ProcessRedirects table importer is a stretch but high-value.

#### Q3. URL Lifecycle Manager — 404 logger with safe storage policy 🔵 PRO planned
Logging that doesn't blow up the database. Layered defence, all configurable:

- **Pre-aggregation** — same URL hitting twice updates a counter on the existing row (`UPDATE … SET hits = hits + 1, last_seen = NOW()`), it does *not* insert a new row. This single decision kills ~90% of the volume problem before the other defences engage. Most "my 404 log table is 2 GB" disasters are because the third-party module logged every request as a separate row.
- **Hard cap** — max rows OR max megabytes, whichever comes first. Default ~10,000 rows / ~10 MB. Configurable.
- **LRU eviction** — when the cap is hit, drop oldest entries first. Logging never stops.
- **Time-based pruning** — optional cron job: *"delete entries older than N days"*. Default 90.
- **Ignore-list** — sensible defaults (`/wp-admin/`, `/.env`, `/.git/`, common scanner patterns, `*.php` requests on a non-PHP-extension site) plus a textarea for editors to add their own.
- **Per-IP throttle** — same IP hammering 1,000 random URLs in a minute logs as a single "burst from x.x.x.x" row, not 1,000 separate rows.
- **Storage warning** — at 80% of cap, show a yellow flag in the module config screen and the 404 admin page; optionally email the superuser.
- **Privacy** — store hashed IP or truncated `/24`, not raw. Configurable retention window.

#### Q4. URL Lifecycle Manager — broken-link checker 🔵 PRO planned
Raised in May 2026 user feedback. Three flavours, in order of value:

1. **Orphaned page references** — `FieldtypePage` / `PageReference` fields whose target page got trashed, unpublished, or deleted. Cheapest to detect (just query for IDs that no longer resolve to a viewable page). Highest signal — these break navigation silently.
2. **Hardcoded internal hrefs in rich text** — `<a href="/old-path/">` baked into CKEditor / textarea content where the path no longer resolves. Needs a scanner that walks rich-text fields, extracts hrefs, and checks each against the page tree.
3. **External links** (optional, lower priority) — links to other domains. Background HEAD-request checker with a long retry interval and a clear "external links can break for reasons unrelated to your site" disclaimer.

**The action button is what makes it valuable:** each broken row in the audit gets a *"Create redirect"* button that pre-fills the URL Lifecycle Manager redirect-add form with the broken URL as the source. This pairing is what user feedback specifically asked for ("a 404 logged by Process404Logger doesn't surface in ProcessRedirects as a redirect suggestion, even though that's exactly the kind of pairing that would save real time").

#### Q5. AI Crawler Observability — recognised-bot registry 🔵 PRO planned
Site owners have no idea who's crawling them and what for. The current AI bot landscape splits three ways:

- **Training crawlers** — GPTBot (OpenAI), ClaudeBot (Anthropic), Google-Extended, Applebot-Extended, CCBot (Common Crawl), Bytespider (ByteDance), Meta-ExternalAgent
- **Answer-engine crawlers** — ChatGPT-User, PerplexityBot, Perplexity-User, ClaudeBot in user mode
- **Traditional search** — Googlebot, Bingbot, DuckDuckBot

A maintained, categorised registry inside SEO NEO PRO is half the feature. It's also the hardest part to get right and to keep current — bot user-agents change, new bots launch monthly, and some (notably Perplexity in 2024) get caught ignoring `robots.txt`. Updating this registry through PRO module updates is one of the things people are paying for.

#### Q6. AI Crawler Observability — first-party request logging 🔵 PRO planned
SEO NEO does *observability* (passive logging); modules like Wire Request Blocker do *blocking* (active intervention). Different jobs, no overlap, no third-party dependency.

Hook the request lifecycle, recognise bot user-agents from the Q5 registry, log to a small aggregated table (date + bot + path, pre-grouped — same storage discipline as Q3). The 30-day "AI bot activity" panel becomes a single SQL query.

#### Q7. AI Crawler Management — robots.txt UI + per-section opt-out 🔵 PRO planned
The action layer on top of Q5/Q6. Currently, controlling AI bots means hand-editing `robots.txt`. SEO NEO PRO surfaces it as a first-class admin screen:

- Show current `robots.txt` directives in a readable, categorised table.
- Toggle each bot category on/off (training / answer-engine / search) via checkbox; the corresponding `User-agent: ... / Disallow: /` lines are generated.
- Warn against bots known to ignore `robots.txt` (with a link to the source — credibility matters here).
- **Per-section opt-out** — *"block training crawlers from `/pricing/` and `/customers/`, allow them on `/blog/`"*. Implementation-wise this is a natural extension of the per-page noindex/nofollow fields already in core: same UX, new field, same resolver chain. Probably warrants a `seoneo_ai_robots` field added to core (free) so the *enforcement* lives in PRO but the *editor surface* doesn't paywall a basic per-page choice. Decision deferred until implementation.

#### Q8. AI Visibility Report 🔵 PRO planned (stretch goal)
The flip-side question — instead of *"who is crawling us"*, ask *"are we appearing in answers when people ask ChatGPT/Claude/Perplexity about our domain or our key terms?"*

Cron-driven check against major answer engines using a configured set of seed queries; record whether the domain is cited. There are existing services that do this (Profound, Otterly, Peec) at €100+/month — a serviceable open implementation inside SEO NEO PRO would be a real differentiator.

This is the hardest item to build, the most ambitious to instrument, and probably v3 of the AI feature line. But it's the SEO question of 2026/2027, and the module that has a credible answer to it is the module people install.

---

## R. SEO NEO PRO — scope & positioning summary

A reference for marketing copy and "is this paid?" questions, and the single source of truth for "what's free vs paid in the SEO NEO project". The companion document **`SeoNeo/ROADMAP.md`** covers tactical editor-UX work and tags items with `[Pro candidate]`; everything tagged that way is reflected in the **Paid** list below.

**Free (core SEO NEO):**
- Meta / OG / Twitter / canonical / hreflang / robots tags
- Schema.org helpers with hookable type generators (J)
- SERP preview, character counters, hard-cap inputs, effective-values panel
- Per-template defaults, smart-map, ancestor walking
- Multi-language with per-language site name and full F-section coverage
- SEO health audit (Lister-based) — section P
- Optional `seoneo_ai_robots` per-page field (enforcement is PRO; editor surface is core)

**Free companion modules (separately distributed, MIT-licensed):**
- `SeoNeoSettings` — editor-friendly admin UI for template defaults so non-superusers can manage them (deferred from L8). Reads/writes `template_defaults_text` via the hookable `___getSiteName()` resolver and friends.

**Paid (SEO NEO PRO):**

*Operational tooling (sections Q1‑Q8):*
- URL Lifecycle Manager: 404 logging + redirect engine + broken-link checker (Q1‑Q4)
- AI Crawler Observability + Management: bot registry, first-party logging, robots.txt UI, per-section opt-out (Q5‑Q7)
- AI Visibility Report — stretch goal, probes answer engines for citations (Q8)
- Maintained registries (recognised AI bots; default ignore-lists; common scanner patterns) refreshed via PRO module updates

*Editor UX enhancements (from `SeoNeo/ROADMAP.md`):*
- Fallback chain visualisation under title / description fields with "use this value" promote button
- Noindex / nofollow prominence warning banner on the SEO tab
- Social-card (OG) preview in the admin tab, alongside the existing SERP preview
- Accurate title character budget that accounts for separator + site name in the rendered `<title>`
- Canonical field live-update in the SERP preview (currently server-rendered, not reactive)
- Duplicate title / description detection (fires on field blur, surfaces conflicting pages)
- "Suggest from content" button for the description field
- Focus keyword input with three binary checks (title / description / URL slug) — writing aid only, no scoring

**Out of scope (any layer):**
- Yoast-style traffic-light page scoring (N1)
- Auto keyword extraction (N2)
- XML sitemap generation (O — defer to MarkupSitemap)
- `llms.txt` generator (N4 — bots don't fetch it)
- Bundled analytics integrations (I — separate concern, well-served by dedicated modules)

**Why a PRO offering at all:** SEO NEO's predecessors (MarkupSEO, SeoMaestro) both stalled when their authors stepped back from active PW work. A funded maintenance path is what keeps the module current with bot landscape changes, PW core releases, and Google/Bing policy changes. Core stays free so the meta-tag layer is never gated; PRO funds the operational tooling, the editor UX polish, and the registries that need ongoing care.

### Deferred architectural decisions

The following questions are **not yet decided** and don't need to be answered before 1.0 ships. Listed here so we can revisit once 1.0 is in the wild and we have real adoption + user-feedback signal:

- **Single PRO bundle vs split into separate companion modules.** Three plausible shapes — one (`SeoNeoPro`), two (`SeoNeoEditorPro` + `SeoNeoOpsPro`), or three (`SeoNeoEditorPro` + `SeoNeoUrlPro` + `SeoNeoBotsPro`). Each has trade-offs around release cadence, install simplicity, and per-feature pricing flexibility. The three-companion shape mirrors Ryan's `ProFields` pattern; the single-bundle shape mirrors `ProDrafts`. Decide once we know which features generate paid demand.
- **License model.** Single license unlocking all PRO modules vs per-module purchase vs subscription for the maintained registries (AI bot list, ignore-list defaults). Likely "single license, soft-fail without it" by analogy with `ProFields`, but not committed.
- **Naming.** `SeoNeoPro`, `SeoNeoEditorPro`, etc. are placeholder names. Will firm up once the split decision lands.
- **Trial / freemium tier.** Possible to offer e.g. AI Crawler Observability in core (free) and gate AI Crawler *Management* behind PRO, since the data is interesting on its own. Open question.

Trigger for revisiting: when 1.0 has been in the wild for ~6 months and we have meaningful adoption + feature-request signal, **or** when a sufficiently strong demand spike for one of the PRO areas (most likely URL Lifecycle Manager) makes the case to ship that companion early.

---

## Suggested order of work for a 1.0 release

In priority order, picking up the highest-impact gaps first:

1. ~~**C2 / C3** — `og:image` width / height / secure_url / type.~~ ✅ Done (Apr 2026).
2. ~~**C6 / C7** — `twitter:site`, `twitter:creator`, and the full Twitter Card tag set.~~ ✅ Done (Apr 2026).
3. ~~**H1** — per-page custom meta textarea (`seoneo_custom`).~~ ✅ Done (Apr 2026).
4. ~~**E4** — relative-to-absolute resolution for the canonical field.~~ ✅ Done (Apr 2026).
5. ~~**C5** — selectable `og:type`.~~ ✅ Done (Apr 2026).
6. ~~**G8** — auto-truncate over-long smart-mapped descriptions.~~ ✅ Done (Apr 2026).
7. ~~**F3** — `og:locale` + `og:locale:alternate`.~~ ✅ Done (Apr 2026).
8. ~~**F8 / F9** — pagination-aware `hreflang` URLs and `hreflang="x-default"` (closes pmichaelis's open Maestro patch). Also closes F6 (per-language domains).~~ ✅ Done (Apr 2026).
9. ~~**C9** — opt-in ancestor-walk for `og:image` (covers Adrian's long-standing Maestro request).~~ ✅ Done (Apr 2026).
10. ~~**C13** — dotted-path field access for OG image and smart-map (closes MarkE / cst989 / Roych Maestro tickets in one move).~~ ✅ Done (Apr 2026).
11. ~~**G10** — strip HTML and bypass output formatters when reading SEO field values (closes palacios000's HTML-in-`og:description` and gebeer's double-encoding cases).~~ ✅ Done (Apr 2026).
12. ~~**K1** — configurable injection position.~~ ✅ Done (Apr 2026).
13. ~~**G3** — pipe fallbacks in title-format placeholders.~~ ✅ Done (Apr 2026).
14. ~~**K5** — ProCache compatibility test and documentation.~~ ✅ Done (Apr 2026).

### Post-1.0 follow-up (1.4.0, Apr 2026)

After the 1.0 release the remaining in-scope P2 / P3 partials and gaps were tackled in a single pass:

15. ~~**D3** — auto-noindex unpublished / hidden pages.~~ ✅ Done.
16. ~~**E5** — pagination + URL-segment policy for the auto-canonical (closes psy's hook chain).~~ ✅ Done.
17. ~~**G2** — `{pageNum}` and `{pageNumber}` placeholders for paginated lists.~~ ✅ Done.
18. ~~**F4** — per-language site name via `site_name_map` + hookable `___getSiteName()`.~~ ✅ Done.
19. ~~**G9** — opt-in ancestor-walk for smart-map values via the `*field` prefix.~~ ✅ Done.
20. ~~**H3** — dedicated Search-engine verification fields for Google / Bing / Yandex / Pinterest / Facebook / Baidu.~~ ✅ Done.
21. ~~**H4** — author meta tag with `___getAuthor()` resolver, optional per-page `seoneo_author` field, and `meta_author` site default.~~ ✅ Done.
22. ~~**G5** — Effective values panel under the SERP preview.~~ ✅ Done.
23. ~~**L3** — optional hard-cap on title / description input length.~~ ✅ Done.
24. ~~**L4** — narrow-screen CSS audit on `seoneo-preview` (responsive breakpoints at 600px and 380px).~~ ✅ Done.

The only remaining in-scope item from the original backlog is **L8** (editor-friendly admin UI for template defaults), which is intentionally deferred to a separate `SeoNeoSettings` companion module rather than bundled into the core.

### Post-1.4.0 follow-up (1.5.0+, May 2026 onwards)

After a user-feedback round (May 2026) the priority of two previously-deferred items changed, and two new sections were added to the backlog:

25. **J1 / J2** — Schema.org structured data with hookable type generators. **Promoted from P3 / companion-module to P1 / in-core.** Justification in section J above; full design rails in `SeoNeo/JSONLD-ARCHITECTURE.md`. P1 first-class type set expanded (May 2026) to add `FAQPage`, `LocalBusiness`, `Product`, and `Event` alongside the existing `Organization` / `WebSite` / `WebPage` / `Article` / `Person` / `BreadcrumbList`. P2 follow-ups (`VideoObject`, `HowTo`, `Recipe`, `Review` / `AggregateRating`, `JobPosting`) and a generic `Custom` type for the long tail.
26. **P1** — SEO health audit as a Lister-based admin view. New section P. The architectural strength noted in A8 (selector-engine support) makes this uniquely cheap to build in SEO NEO compared to any other PW SEO module.

These two are the remaining 1.0-blockers under the revised plan.

### Post-1.0 — SEO NEO PRO companion bundle

A separate paid companion bundle is being scoped (sections Q and R). It is **not on the path to the 1.0 release** and won't gate it. Roughly:

- **PRO v1** — URL Lifecycle Manager (Q1‑Q4): redirects, 404 logging with safe storage policy, broken-link checker.
- **PRO v2** — AI Crawler Observability (Q5‑Q6): bot registry, first-party logging, 30-day activity panel.
- **PRO v3** — AI Crawler Management (Q7): `robots.txt` UI + per-section opt-out.
- **PRO v3+ (stretch)** — AI Visibility Report (Q8).

Sequence and naming may change as the design firms up.

---

## Working notes

- This file is **not** part of the module's installed footprint. It lives at `/Users/peterknight/Sites/SeoNeo/SEO-NEO-FEATURE-BACKLOG.md`.
- Update it as gaps close (`🔴 Gap` → `✅ Done`) and as new feedback comes in.

**Sources reviewed:**

- MarkupSEO support thread: <https://processwire.com/talk/topic/8007-markupseo-the-all-in-one-seo-solution-for-processwire/> (pages 1‑14, reviewed Apr 2026).
- Seo Maestro module page: <https://processwire.com/modules/seo-maestro/>
- Seo Maestro GitHub issue tracker: <https://github.com/wanze/SeoMaestro/issues>
- Seo Maestro support thread: <https://processwire.com/talk/topic/20817-seomaestro/> (pages 1‑12, reviewed Apr 2026 — full thread coverage).
- Direct user-feedback round, May 2026 (private email): an SEO specialist's wishlist plus internal stress-test, raising the audit-view, schema-helpers, urlSegments, and "umbrella module" framings reflected in sections E5, J, N, P, Q, and R above.
