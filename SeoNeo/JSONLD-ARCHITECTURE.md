# SeoNeo — JSON‑LD / Schema.org Architecture

Design rails for the JSON‑LD subsystem of SeoNeo. This document is the **single source of design truth** for how structured data is produced, configured, and extended. New first‑class types, new editor UI, and any refactor of the existing JSON‑LD code path must conform to the model described here, or this document must be updated first.

This is a planning document. No code changes are mandated by merging it; subsequent feature PRs implement it incrementally.

**Companion documents:**
- `SeoNeo/ROADMAP.md` — tactical editor‑UX work in the admin SEO tab and the SeoNeoBar.
- `SEO-NEO-FEATURE-BACKLOG.md` (workspace root) — strategic backlog. Sections **J1 / J2** point here for depth.

---

## 1. Why JSON‑LD needs its own architecture

Meta tags, OG tags, canonical, and hreflang are mostly **one value per page**. The current SeoNeo cascade (smart‑map → per‑template defaults → site‑wide defaults → hookable resolver) is a perfect fit for that shape.

JSON‑LD is structurally different:

- A page can carry **multiple schema nodes** (`Article` + `BreadcrumbList` + `Person` author + `Organization` publisher), all linked by `@id`.
- Each node has **many properties**, each potentially sourced from a different field, a related page, or a repeater.
- Many properties are themselves **structured sub‑nodes** (`Article.author` is a `Person`, `Product.brand` is an `Organization`, `Event.location` is a `Place`).
- Lists are first‑class: `FAQPage.mainEntity` is a list of `Question`/`Answer` pairs; `HowTo.step` is a list of steps; `Product.offers` is a list of `Offer`.
- Multilingual content needs to resolve at render time in the **active** language, not be hand‑mapped per language in config.
- Repeaters, RepeaterMatrix, PageTable, and PageReference fields are the natural input for the lists above and **must** be supported as first‑class sources, not after‑the‑fact escape hatches.

A "more config keys per type" approach quickly becomes unmaintainable. Each new type would re‑implement value extraction, language handling, and source selection. The result would be inconsistent behaviour between types and a high cost per new type.

The architecture below replaces "more config keys per type" with **one resolver, one cascade, one hook surface, a small registry of first‑class types, and a generic escape hatch for everything else.**

---

## 2. Design principles

1. **Modular but consistent.** Every type uses the same value resolver, the same cascade, the same hook points. Adding a type is a declaration, not a re‑implementation.
2. **Explicit over magical.** Auto‑detection exists but lives at the bottom of the cascade. Mapping is always overridable by explicit config.
3. **Editor surface and developer surface are both first‑class.** Developers configure mapping per template once. Editors override per page or add ad‑hoc nodes without writing code. Power users can call the API directly from a template.
4. **Multilingual is a property of the resolver, not of each type.** Multilang fields resolve in the active language at render time. Per‑language string maps exist only as a workaround for properties whose source is a plain config string.
5. **Structured content is first‑class input.** Repeater, RepeaterMatrix, PageTable, and PageReference fields are natural data sources for `FAQPage`, `HowTo`, `Product.offers`, `Event.performer`, etc. The architecture must handle them without bespoke per‑type code.
6. **Single `@graph`, cross‑referenced by `@id`.** One `<script type="application/ld+json">` per rendered page. Sub‑nodes are referenced by `@id` so the same `Organization` or `Person` is never duplicated within a page.
7. **Validation is part of the deliverable.** Producing invalid structured data silently is worse than producing none. Editors get a preview + validity panel; the API exposes the resolved graph for programmatic checks.
8. **No painted corners.** Every Schema.org type that we don't ship as first‑class must still be expressible via the generic Custom type using the same resolver and cascade. No "you'll have to fork the module" paths.

---

## 3. The four orthogonal layers

```
+----------------------------------------------------------+
|  Renderer  (single @graph, @id strategy, deduping, hooks)|
+----------------------------------------------------------+
|  Cascade   (page  >  template  >  module  >  auto)       |
+----------------------------------------------------------+
|  Resolver  (source-spec → typed value, language-aware)   |
+----------------------------------------------------------+
|  Type registry  (Article, FAQPage, … + Custom; pluggable)|
+----------------------------------------------------------+
```

Each layer is small; the power comes from composition.

### 3.1 Type registry

A schema **type definition** declares:

- The Schema.org `@type` (or list of `@type` for sub‑typed nodes, e.g. `["LocalBusiness", "Restaurant"]`).
- A list of **properties**. For each property: name, expected datatype (Text, URL, Date, ImageObject, Person, nested type, list‑of), required/optional, default source spec.
- A **default mapping** giving sensible source specs for the common ProcessWire field shapes (so a new install with no per‑template mapping still produces something sensible).
- A **policy block**: e.g. *"Do not inherit Organization defaults"* (see Person rule, §11).

First‑class types ship in the module. Sites can register additional types in two supported ways (§13):

1. File‑based: drop a definition file into `site/modules/SeoNeo/schemas/` (or a sister folder, name TBD during implementation).
2. Hook‑based: register via `SeoNeo::registerSchemaType` from `site/ready.php` or another module.

### 3.2 Resolver (source specs)

A single concept — a **source spec** — declares where any value comes from. The set of source kinds is small and closed:

| Kind | Shape | Returns |
|---|---|---|
| `literal` | `{ kind: literal, value: "...", lang_map?: {...} }` | scalar / per‑language scalar |
| `field` | `{ kind: field, name: "fieldname" }` | scalar / list (whatever the field returns) |
| `field_path` | `{ kind: field_path, path: "author.image" }` | nested traversal of Page → Page → field |
| `field_on` | `{ kind: field_on, page: <selector or id>, name: "..." }` | value from a specific named/selectable page |
| `ancestor_field` | `{ kind: ancestor_field, name: "..." }` | first non‑empty value walking up `$page->parents()` |
| `iterate` | `{ kind: iterate, source: <spec for collection>, item_type: "Question", item_map: { name: <spec>, acceptedAnswer.text: <spec> } }` | list of typed sub‑nodes |
| `hook` | `{ kind: hook, hook: "MyModule::someResolver" }` | whatever the hook returns |
| `auto` | `{ kind: auto }` | resolver's built‑in heuristic for the property (the "do something sensible" fallback) |

Source specs are composable: `iterate.item_map` values are themselves source specs, including further `iterate` for nested structures (e.g. `HowTo.step` where each `step` has its own list of `itemListElement`).

The resolver is responsible for:

- **Language resolution.** When the source resolves to a multilang field value, the active language is used; the language fallback chain (active → default → blank) is applied. When the source is a `literal` with a `lang_map`, the same chain runs against the map.
- **Datatype coercion.** A field that returns a `Pageimage` is expanded into an `ImageObject`; a date field is formatted ISO‑8601; a URL is absolutised; HTML is stripped from text properties.
- **Page reference expansion.** A `field` returning a `Page` is rendered either inline as a sub‑node (using the referenced page's own type definition) or as `{ "@id": "..." }` only — chosen per property in the type definition.
- **Iteration over containers.** Repeater, RepeaterMatrix, PageTable, PageArray (PageReference multi), and WireArray are all iterable via the `iterate` kind. RepeaterMatrix items can additionally be filtered by matrix item type so different matrix items map to different sub‑schemas.
- **Empty handling.** Empty / null / blank‑array values are dropped from the final node. A node whose required properties cannot be resolved is dropped from the graph entirely (with a developer‑mode warning).

### 3.3 Cascade

For every property of every node, sources are tried in this order, top wins:

1. **Per‑page override.** A small set of override fields on the SEO tab (e.g. `seoneo_jsonld_headline`), plus a structured **"Extra schema nodes"** field on the page allowing editors to add ad‑hoc nodes (e.g. *"this one page is also a FAQPage"*).
2. **Per‑template mapping.** Developer‑defined per template — *"this template emits Article + BreadcrumbList; map Article.headline ← `seoneo_title|title`, Article.image ← `hero_image`, Article.author ← `created_user`"*. Stored either in the existing `template_defaults_text`‑style format or in a dedicated mapping field; format TBD during implementation but conceptually identical to existing per‑template defaults.
3. **Module‑wide defaults.** The current `jsonld_org_*`, `jsonld_default_author`, `jsonld_breadcrumbs`, `jsonld_pretty`, etc. config keys. These supply Organization + WebSite + global toggles + default authors. They keep working unchanged.
4. **Auto‑detection / built‑in heuristics.** Type selection from `jsonld_article_templates` / `jsonld_person_templates` style lists, default property mappings declared by the type definition. This is the "no configuration at all" floor — sites that install SeoNeo and do nothing still get sensible Organization + WebSite + WebPage + BreadcrumbList output, plus Article on templates whose name suggests articles.

The cascade is identical for every property of every type. There is no per‑type ordering and no per‑property special case.

### 3.4 Renderer

Responsibilities, in order:

1. Determine the active types for the page: union of (auto‑detected) + (per‑template mapping) + (per‑page extras).
2. For each type, ask the type definition + cascade + resolver to produce a node.
3. Assign each node a stable `@id` (§10).
4. Cross‑reference: where a property is itself a node already in the graph (Article.publisher → Organization, Article.author → Person), use `{ "@id": "..." }` instead of inlining the full sub‑node a second time.
5. Drop empty / invalid nodes.
6. Run hooks (§13) at every stage: `getJsonLd` (whole graph), `buildJsonLd<Type>` (per type, already exists for current builders), `resolveJsonLdValue` (per property), `finalizeJsonLdGraph` (last‑chance mutate).
7. Emit a **single** `<script type="application/ld+json">` containing `{ "@context": "https://schema.org", "@graph": [ ... ] }`, in the active request language. Pretty‑print remains controlled by `jsonld_pretty`.

---

## 4. First‑class type set

The first‑class set is the list of types the module ships with full type definitions and sensible defaults. Everything else uses the generic Custom type (§5) with the same machinery.

### 4.1 P1 — must ship before JSON‑LD subsystem is considered "done"

Already implemented today (need to be moved onto the new resolver + cascade, not rewritten functionally):

- `Organization` (with sub‑type extension to `LocalBusiness`)
- `WebSite`
- `WebPage`
- `Article` (with sub‑type extension to `NewsArticle`, `BlogPosting`)
- `Person`
- `BreadcrumbList`

New first‑class additions in the P1 set, motivated by being among the most commonly requested Schema.org types:

- `FAQPage` — repeater of `{question, answer}` is the canonical input.
- `LocalBusiness` (as a typed extension of `Organization`, with address, geo, openingHours, telephone). Module config gains a "site is a LocalBusiness" toggle that swaps the auto‑emitted Organization node for a LocalBusiness node and exposes the extra config fields.
- `Product` — name, description, image, brand, offers (list of `Offer`), aggregateRating (optional). Repeater of offers is supported.
- `Event` — name, description, startDate, endDate, location (`Place` sub‑node), organizer (PageRef → Organization), performer (PageRef multi → Person/Organization).

### 4.2 P2 — first‑class but not blocking the subsystem release

- `VideoObject`
- `HowTo` (steps via Repeater / RepeaterMatrix)
- `Recipe`
- `Review` / `AggregateRating` (as embedded nodes on `Product` initially; standalone P3)
- `JobPosting`

P2 types must not require any new resolver capabilities — if they do, the resolver is missing a feature and that gap is fixed first.

### 4.3 P3 — addressed via the Custom type

Anything not in P1/P2. Sites get full Schema.org coverage via the Custom type plus the same source‑spec machinery, with no module changes required. Promotion to first‑class happens when a P3 type proves popular enough to deserve a default mapping.

---

## 5. Generic Custom type

The Custom type is a type definition with no fixed property list. Editors / developers declare:

- The Schema.org `@type` (or list of `@type`).
- A free‑form list of properties, each with a source spec.

Custom is what stops the architecture from painting itself into a corner. Any Schema.org type not shipped first‑class is reachable through Custom with the same multilingual + repeater + hook + cascade behaviour. When a Custom mapping becomes a common pattern, it is promoted to a first‑class type definition with no change for existing users (the existing Custom configuration keeps producing the same output).

**Editor‑supplied** Custom nodes are **strictly sanitised** (treated as text key/value pairs at the leaves; no raw HTML, no script execution). Hook‑supplied Custom nodes from site code are trusted. The boundary is enforced in the renderer, not in the resolver, so the same Custom definition can be more permissive when invoked from a hook than when invoked from the page editor.

---

## 6. Multilingual rules

1. The graph is rendered **per request, in the active language**. We do not put all languages into one graph.
2. Multilang fields (`FieldtypeTextLanguage`, `FieldtypeTextareaLanguage`, etc.) are resolved using the active language with the standard PW fallback to the default language. This is automatic, no per‑type config required.
3. For properties whose source is a plain config string (e.g. `jsonld_org_name` in module config), a `lang_map` may be attached to the source spec. The current `jsonld_org_name_map` / `jsonld_org_description_map` config fields are exactly this pattern and continue to work — they become a degenerate case of `literal + lang_map`, not a special mechanism.
4. Pages emitted in different languages produce different graphs. `hreflang` alternates and JSON‑LD are independent: each language version of the page declares its own JSON‑LD in its own language. We do not emit `inLanguage` arrays attempting to cover all languages in a single node.
5. URLs in the graph (`url`, `image.url`, `sameAs`, `@id`) use the language‑aware URL helpers already used by the canonical / hreflang code, so multi‑domain language setups work without special handling.

---

## 7. Repeater / RepeaterMatrix / PageTable / PageReference handling

This is the single most important piece of the architecture for content‑rich sites. It is handled entirely by the `iterate` source kind, with no per‑type code.

### 7.1 Repeater

```
FAQPage.mainEntity:
  iterate over field "faqs" on current page,
    item_type: "Question",
    item_map:
      name: field "question"
      acceptedAnswer:
        @type: "Answer"
        text: field "answer"
```

### 7.2 RepeaterMatrix

Same as Repeater, plus an optional **matrix‑type filter** so different matrix item types map to different sub‑schemas:

```
HowTo.step:
  iterate over field "steps" on current page,
    when matrix_type = "text_step":
      item_type: "HowToStep"
      item_map:
        name: field "step_title"
        text: field "step_body"
    when matrix_type = "image_step":
      item_type: "HowToStep"
      item_map:
        name: field "caption"
        image: field "step_image"
```

### 7.3 PageTable

Treated identically to Repeater. Each PageTable child is a Page with its own template; the `item_map` reads fields from that template.

### 7.4 PageReference (multi)

Treated as `iterate` over a PageArray. Each item can either be **expanded inline** as a typed sub‑node (using the referenced page's own type definition, if it has one) or **referenced by `@id`** (preferred when both pages would emit the same `@id` on their own renders). The choice is per‑property in the parent type definition.

```
Event.performer:
  iterate over field "speakers" (PageReference multi),
    expand_inline: false   # use @id reference instead
```

### 7.5 PageReference (single)

A single PageReference resolved in a property either expands inline as a sub‑node or becomes an `@id` reference, exactly as for the multi case.

### 7.6 Empty containers

An empty repeater / matrix / PageTable / PageArray collapses to an empty list, which is then dropped per the empty‑handling rule. A `FAQPage` with no `mainEntity` items is dropped from the graph (and a developer‑mode warning is emitted).

---

## 8. `@id` strategy

A consistent `@id` strategy is the difference between a clean cross‑referenced graph and a pile of duplicated nodes that confuses validators.

Rules:

- Every node gets an `@id`. No exceptions.
- The `@id` is a URL (this is the Schema.org convention) using the page's canonical (resolved by the existing canonical pipeline) plus a fragment identifier per node type:
  - `Organization` → `<site-root-url>#organization`
  - `WebSite` → `<site-root-url>#website`
  - `WebPage` → `<page-canonical>#webpage`
  - `Article` → `<page-canonical>#article`
  - `Person` (when from a PageRef to a person page) → `<that-page-canonical>#person`
  - `BreadcrumbList` → `<page-canonical>#breadcrumb`
  - First‑class types each declare their fragment.
  - Custom and iterated sub‑nodes get fragments derived from `@type` + position (e.g. `#question-1`).
- A node is rendered only once per graph. Subsequent uses are `{ "@id": "..." }` references.
- Across pages, the same `Organization` `@id` is used so search engines can connect the dots between pages.
- The `@id` strategy is hookable per type for sites that need a different scheme.

---

## 9. Person doesn't inherit business defaults

A specific policy worth calling out, because it's a common subtle bug:

When emitting a `Person` node, properties that look like business defaults — `telephone`, `email`, `address`, `sameAs`, `logo` — are **not** auto‑inherited from the module's Organization config. They must come from the Person's own page (via `field` / `field_path` source specs) or be explicitly mapped. Otherwise the company switchboard ends up on every employee page as their personal phone number.

This is enforced in the type definition's policy block. The same rule applies recursively when a `Person` is emitted as a sub‑node of another type (Article.author, Event.performer).

---

## 10. Validation, preview, and observability

Every type definition declares its required properties. The renderer maintains a **validity report** for the current page's graph:

- Per node: which required properties resolved, which didn't, which sources were tried in order.
- Per property: which step of the cascade won, what the resolved value was after coercion, what language it came from.
- Cross‑node: which `@id` references resolve to nodes actually in the graph.

This report is exposed in three places:

1. **Admin SEO tab** — a new "JSON‑LD" disclosure panel under the existing Effective Values panel. Shows the rendered graph (collapsible), the validity table, and a per‑node language switcher when multilang is active.
2. **SeoNeoBar** (frontend, logged‑in admins) — a JSON‑LD tab showing the same data in‑context on the rendered page.
3. **API** — `$page->seoneo->jsonLdReport()` returns the validity report as a structured array, for site code that wants to fail builds on invalid graphs.

The renderer never throws on invalid data in production. In developer mode (`$config->debug` or a dedicated `jsonld_strict` config flag) missing required properties surface as PW notices.

---

## 11. Extension surface

Two equivalent on‑ramps for extending the type set, both supported:

### 11.1 File‑based registration

Drop a type definition file into a conventional folder under the site's SeoNeo install. The module discovers and registers it on init. Format mirrors the in‑module type definitions so site‑local types and shipped types are interchangeable. Suitable for "add one type to one site" without writing a module.

### 11.2 Hook‑based registration

```php
$wire->addHookAfter('SeoNeo::registerSchemaTypes', function(HookEvent $e) {
    $e->return['MyCustomType'] = [
        '@type' => 'MyCustomType',
        'properties' => [ ... ],
        'default_mapping' => [ ... ],
    ];
});
```

Suitable for sub‑modules that ship type definitions as part of a larger feature (e.g. a `SeoNeoEcommerce` companion shipping `Product` extensions).

### 11.3 Per‑value hooks

Every layer is hookable:

- `SeoNeo::getJsonLd($page)` — whole graph, post‑finalize. (Already exists.)
- `SeoNeo::buildJsonLd<TypeName>($page, $node)` — per type, pre‑finalize. (Pattern already exists for current builders.)
- `SeoNeo::resolveJsonLdValue($page, $type, $property, $sourceSpec)` — per property resolution. (New.)
- `SeoNeo::finalizeJsonLdGraph($page, $graph)` — last‑chance mutate before serialisation. (New.)

Hooks are documented as the stable extension surface in the README, alongside the resolver call.

---

## 12. Template helper API

The "do it in code from a template" path stays a one‑liner, so power users aren't forced through the config UI. Surface:

```php
// Auto-detected nodes for the page, full graph, ready to echo:
echo $page->seoneo->renderJsonLd();      // emits <script type="application/ld+json">…</script>

// Just the graph as a PHP array:
$graph = $page->seoneo->jsonLd();

// Render a single type with optional overrides, using the same resolver + cascade:
echo $page->seoneo->schema('FAQPage', [
    'mainEntity' => $page->faqs,         // overrides the default mapping for this property
]);

echo $page->seoneo->schema('Article');   // uses the per-template mapping + module defaults
```

Overrides passed at the call site enter the cascade as the highest‑priority layer (above per‑page overrides). They go through the same resolver, so a `$page->faqs` repeater gets iterated correctly, multilang fields resolve in the active language, and so on.

The legacy `___getJsonLd()` and `___renderJsonLd()` resolvers stay as the underlying public API so existing site hooks keep working.

---

## 13. Backward compatibility

The architecture is additive. Existing module config keys keep their meaning:

- `jsonld_enabled`, `jsonld_pretty`, `jsonld_breadcrumbs`, `jsonld_default_author` — unchanged.
- `jsonld_org_*` and `jsonld_org_*_map` — unchanged. They become the "module‑wide defaults" layer of the cascade for the auto‑emitted Organization node, expressed internally as `literal` source specs (with `lang_map` for the `_map` variants).
- `jsonld_article_templates`, `jsonld_person_templates` — unchanged. They become the "auto‑detection" floor of the cascade for type selection.
- Existing builders (`buildJsonLdOrganization`, `buildJsonLdArticle`, etc.) and their hook prefix (`___build…`) — unchanged externally. Internally, they're re‑expressed in terms of type definitions + the resolver, but the public hook signatures stay stable.

A site that upgrades and changes nothing produces the same JSON‑LD output as before. New capabilities (per‑template mapping, per‑page overrides, FAQPage + Product + Event, repeater iteration, etc.) light up only when the site opts in by configuring them.

---

## 14. Worked examples

### 14.1 FAQPage from a Repeater

Per‑template mapping for the `support-page` template:

```
type: FAQPage
properties:
  mainEntity:
    iterate: field "faqs"
    item_type: Question
    item_map:
      name: field "question"
      acceptedAnswer:
        @type: Answer
        text: field "answer"
```

For a page with three FAQ items, the renderer emits a `FAQPage` node with three `Question` sub‑nodes, each with an `Answer`. If `question` and `answer` are multilang fields, the active language wins. If the editor adds a fourth FAQ, it appears automatically next render — no config change.

### 14.2 Product with multiple Offer rows

Per‑template mapping for the `product` template:

```
type: Product
properties:
  name: field "title"
  description: field "summary"
  image: field "hero_image"
  brand:
    field_on: page "/about/" field "company_name"
    @type: Organization
  offers:
    iterate: field "price_tiers"   # repeater
    item_type: Offer
    item_map:
      price: field "price"
      priceCurrency: field "currency"
      availability: literal "https://schema.org/InStock"
      url: literal (auto: page canonical)
```

Three repeater rows produce three `Offer` sub‑nodes inside a `Product` node, with `brand` referencing the Organization node already in the graph by `@id`.

### 14.3 Event with Person speakers via PageReference

```
type: Event
properties:
  name: field "title"
  startDate: field "starts"
  endDate: field "ends"
  location:
    field_on: page (field "venue") field "address"
    @type: Place
  performer:
    iterate: field "speakers"      # PageReference multi
    item_type: Person
    expand_inline: false           # emit @id only; the speaker pages emit their own Person nodes
```

The Event node lists each speaker as `{ "@id": "https://site/speakers/jane#person" }`. Visiting the speaker page emits the corresponding Person node with the same `@id`, so search engines can connect the two.

---

## 15. Phased delivery

No calendar dates. Phases are dependency layers; each is a discrete PR or small group of PRs.

1. **Foundations.** `@id` strategy. Refactor existing builders behind type definitions + the resolver. Bring JSON‑LD properties into the same per‑template / per‑page cascade SeoNeo already uses for meta. No new types yet, no UI changes. Externally invisible to existing sites.
2. **Source kinds.** Implement `literal`, `field`, `field_path`, `field_on`, `ancestor_field`, `iterate` (Repeater / RepeaterMatrix / PageTable / PageReference multi), `hook`, `auto`. Multilingual handled centrally. Documented.
3. **First‑class type expansion.** Add `FAQPage`, `LocalBusiness` (as Organization sub‑type), `Product`, `Event` on top of the resolver. Each is a type definition + default mapping, not bespoke logic.
4. **Generic Custom type** + documented hook surface for registering more types + file‑based registration on‑ramp.
5. **Editor surface.** Per‑template mapping UI (or text config in the existing SeoNeo style — TBD), per‑page override fields, "Extra schema nodes" structured field, live preview / validity panel under the Effective Values panel.
6. **SeoNeoBar integration.** JSON‑LD tab showing the resolved graph, per‑type validity, missing‑required warnings, language switcher.
7. **P2 first‑class types.** `VideoObject`, `HowTo`, `Recipe`, `Review` / `AggregateRating`, `JobPosting`. Each must require zero new resolver capabilities.

The current J1/J2 backlog entries map to phases 1–4 (delivering the in‑core JSON‑LD subsystem), with phases 5–7 as follow‑ups still inside the free / core module.

---

## 16. Open questions / explicit non‑goals

**Open:**

- **Per‑template mapping storage format.** Either extend the existing `template_defaults_text` textarea grammar, or add a dedicated mapping field. Decision deferred to phase 5 implementation.
- **Sitemap‑style "include this page in JSON‑LD?" toggle.** Probably not needed — the existing per‑page noindex + an empty graph already cover the "don't emit structured data" case. Confirm during phase 5.
- **`@id` cross‑page persistence.** Currently the `@id` is derived from the canonical URL, which is stable. If sites change canonical strategy, existing `@id`s change. We may want a stable per‑node UUID stored on the page; deferred until there's evidence sites need it.
- **Schema.org context version pinning.** Currently `https://schema.org`. Whether to expose the option to pin to a versioned context (`https://schema.org/version/15.0/`) — open.

**Non‑goals:**

- Shipping a hand‑written builder for every Schema.org type. The Custom type plus the hook surface is the answer.
- A drag‑and‑drop schema designer in the admin. The mapping is structured but text‑based; the validity panel + preview is the editor‑facing surface.
- Producing structured data in serialisations other than JSON‑LD (Microdata, RDFa). Out of scope.
- Replacing or competing with content‑level structured data (e.g. inline `<itemscope>` markup written by editors in CKEditor body content). SeoNeo emits the page‑level `@graph`; editor‑authored markup is the editor's own responsibility.

---

## 17. How this document is maintained

- Every JSON‑LD‑related PR references the section(s) of this doc it implements or revises.
- Changes to design rails (new layer, new source kind, change to `@id` strategy, change to multilingual rules) update this doc **first**, in the same PR or in a preceding one.
- New first‑class types are added to §4 with their property list and default mapping summary.
- The phased delivery list in §15 is updated as phases land — completed phases marked done, follow‑ups added beneath as needed.
