# SeoNeo — JSON‑LD / Schema.org Architecture

Design rails for the JSON‑LD subsystem of SeoNeo. This document is the **single source of design truth** for how structured data is produced, configured, and extended. New first‑class types, new editor UI, and any refactor of the existing JSON‑LD code path must conform to the model described here, or this document must be updated first.

This is a planning document. No code changes are mandated by merging it; subsequent feature PRs implement it incrementally.

**Stress‑test note (May 2026):** the architecture has been walked end‑to‑end against the structurally‑distinct value shapes that appear in real Schema.org JSON‑LD across the major type families (creative works, organizations + LocalBusiness subtypes, places, events with `eventAttendanceMode` / `eventStatus` enums, products + offers, reviews, actions, jobs, real estate, education, FAQs / how‑tos, site‑structure, identifiers + `PropertyValue`, speakable, time‑aware structures, controlled vocabularies, intersection types, polymorphic property values). The shape‑coverage matrix in **§4.4** records what was tested and how each shape is handled. Future contributors adding first‑class types must check the type's value shapes against §4.4; if a shape isn't there, the resolver / type‑definition contract is missing a feature and that gap is fixed *first*, not absorbed as per‑type bespoke code.

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

- A `@type` value, which may be:
  - a string literal (e.g. `"Article"`),
  - a list literal for intersection types (e.g. `["LocalBusiness", "Restaurant"]`), or
  - a **source spec** — for the case where a single PW template emits one of several Schema.org subtypes based on a field value (e.g. a `publication` template where the editor picks `Article` / `NewsArticle` / `BlogPosting` / `Report` via a select). `@type` is a property like any other; it just happens to control the shape of the node it's on.
- An optional `extends` reference to another type definition — the parent's property list, default mapping, and policy block are inherited; the child may add new properties or override existing ones. Used by `LocalBusiness extends Organization`, `Restaurant extends LocalBusiness`, `NewsArticle extends Article`, `Episode extends CreativeWork`, and so on. Inheritance is what lets the long tail of Schema.org subtypes be added cheaply without duplicating 20–30 properties per subtype.
- A list of **properties**. For each property: name, expected datatype, required / optional / conditional, default source spec, and per‑property policy flags (e.g. `auto_wire_to: WebSite` — see §3.4 and §10).
- A **default mapping** that may include both per‑property source specs *and* **constant sub‑node templates** — fixed JSON‑LD structures with a small number of source‑spec'd holes. Used for things like `WebSite.potentialAction → SearchAction`, where the SearchAction's structure is almost entirely fixed and only the URL template / search-results page URL come from config.
- A **policy block** declaring per‑type rules: e.g. *"Do not inherit Organization defaults"* (Person — §11), *"Auto‑wire `mainEntity` to the primary page-type node in the graph"* (WebPage — §10), *"Drop this node when `eventAttendanceMode` is `OnlineEventAttendanceMode` and `location` is missing"* (Event), and so on.

**Property datatypes** (closed set):

| Datatype | Notes |
|---|---|
| `Text` | HTML‑stripped, whitespace‑collapsed at resolve time. |
| `URL` | Absolutised at resolve time using the page's URL host (multi‑domain language setups respected). |
| `Date` / `DateTime` / `Time` | Coerced to ISO‑8601 at resolve time. |
| `Number` / `Integer` / `Boolean` | Direct. |
| `Enum<Vocabulary>` | A URL literal from a controlled Schema.org vocabulary (e.g. `ItemAvailability`, `EventStatus`, `EventAttendanceMode`, `DayOfWeek`, `OfferItemCondition`, `Gender`, `BookFormatType`, `MerchantReturnEnumeration`). Editor UI offers a dropdown sourced from the vocabulary; validity panel flags invalid values. The vocabulary is part of the property declaration — `availability: Enum<ItemAvailability>`. |
| `Image` | Resolves to an `ImageObject` sub‑node. Pageimage is auto‑expanded; URL/Pageimages are also accepted. |
| `<TypeName>` | Reference to another type definition in the registry. Resolves either inline as a sub‑node or as `{ "@id": "..." }`, per the property's `expand_inline` flag. |
| `oneOf<[T1, T2, ...]>` | Polymorphic property: the value can be any of the listed datatypes. The resolver picks based on what the source returns. Examples: `Article.author = oneOf<[Person, Organization]>`, `Event.performer = oneOf<[Person, Organization, PerformingGroup]>`, `Recipe.recipeInstructions = oneOf<[Text, List<HowToStep>]>`. The type definition may declare a *resolution rule* (e.g. *"if source page's template is in `jsonld_person_templates`, treat as Person; otherwise Organization"*); without an explicit rule, the resolver inspects the source and matches the first compatible datatype in declaration order. |
| `List<T>` | Ordered list. Combine with any of the above (`List<Image>`, `List<Enum<DayOfWeek>>`, `List<oneOf<[Person, Organization]>>`). |

**Required / optional / conditional**:

- `required` — the node is dropped from the graph if this property cannot be resolved (developer‑mode warning emitted).
- `optional` — empty values are simply omitted from the node.
- `conditional` — required only when another property has a given value. Declarable for the well‑known cases (e.g. `Event.location` required when `eventAttendanceMode` is not `OnlineEventAttendanceMode`). Full conditional logic is **deferred** (§16); v1 ships a small built‑in policy library covering the cases Google flags in Search Console.

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

1. **Determine active types** for the page: union of (auto‑detected from template) + (per‑template mapping) + (per‑page extras). Resolve any source‑spec'd `@type` properties so the final type list is concrete before graph assembly.
2. **Build nodes.** For each type, ask the type definition + cascade + resolver to produce a node. Type definitions with `extends` inherit their parent's property list and policies.
3. **Assign `@id`.** Each node gets a stable `@id` per §10.
4. **Auto‑wire cross‑references.** A small set of well‑known properties are filled in by the renderer when both endpoints are in the graph, regardless of per‑site mapping:
   - `WebPage.mainEntity` → primary page‑type node (Article / Product / Event / FAQPage / etc.) on the current page.
   - `WebPage.isPartOf` → `WebSite`.
   - `WebPage.breadcrumb` → `BreadcrumbList`.
   - `Article.publisher` → `Organization`.
   - `Article.mainEntityOfPage` → `WebPage`.
   - `Product.brand`, `Event.organizer`, etc. → `Organization` *only when* the per‑template mapping doesn't already supply a more specific value.
   The full auto‑wire table lives in each type definition's policy block, not as scattered renderer special cases.
5. **Cross‑reference by `@id`.** Where a property's value is a node already present in the graph, emit `{ "@id": "..." }` instead of inlining the full sub‑node a second time. PageReference targets that point at pages emitting their own JSON‑LD also reference by `@id`.
6. **Drop invalid / empty nodes.** A node missing a required property is dropped (with a developer‑mode warning); a node whose properties all resolved empty is dropped.
7. **Sanitise editor‑supplied content.** Nodes added via the per‑page "Extra schema nodes" field are walked recursively: scalars / lists / maps only at the leaves; HTML‑stripped from text values; keys starting with `@reverse`, `@graph`, `@context`, or `@base` rejected; total node depth and node count capped (defaults TBD; conservative). Hook‑supplied nodes are trusted and skip this step.
8. **Order nodes.** Default order: primary page‑type node first (Article / Product / Event / FAQPage / etc.), then `BreadcrumbList`, then `WebPage`, then `WebSite`, then `Organization`, then anything else in declaration order. Some structured‑data consumers prefer the most‑specific node first; this ordering matches that expectation. Hookable per site.
9. **Run hooks** at every stage: `getJsonLd` (whole graph), `buildJsonLd<Type>` (per type — already exists for current builders), `resolveJsonLdValue` (per property), `finalizeJsonLdGraph` (last‑chance mutate before serialisation).
10. **Emit** a **single** `<script type="application/ld+json">` containing `{ "@context": "https://schema.org", "@graph": [ ... ] }`, in the active request language. Pretty‑print remains controlled by `jsonld_pretty`.

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

### 4.4 Schema.org value‑shape coverage

The architecture has been stress‑tested against the structurally‑distinct value shapes that appear in real Schema.org JSON‑LD. Every shape here must be expressible without bespoke per‑type code; if a future Schema.org pattern doesn't fit, **the resolver is missing a feature and is fixed before the type is added** (same gating rule as §4.2).

| Schema.org value shape | Real example | How the architecture handles it |
|---|---|---|
| Plain text | `Article.headline`, `Person.name` | `Text` datatype with `field` / `literal` source. HTML‑stripped at resolve time. |
| URL | `url`, `sameAs`, `image.url` | `URL` datatype with absolutisation. |
| Date / DateTime | `Article.datePublished`, `Event.startDate` | `Date` / `DateTime` datatype with ISO‑8601 coercion. |
| Image | `Product.image`, `Person.image`, `Organization.logo` | `Image` datatype; Pageimage auto‑expanded to `ImageObject` (URL, width, height). |
| Sub‑node by reference | `Article.publisher → Organization`, `WebPage.isPartOf → WebSite` | Auto‑wired by the renderer when both endpoints exist (§3.4). Per‑site `field` / `field_on` source spec where override needed. |
| Sub‑node from a related page | `Article.author → Person` (PageRef to staff page) | `field` returning a Page; `expand_inline: false` in property declaration → `@id` reference; staff page emits its own Person node with the same `@id`. |
| List of homogeneous sub‑nodes | `FAQPage.mainEntity → List<Question>`, `Product.offers → List<Offer>` | `iterate` source kind over Repeater / RepeaterMatrix / PageTable / PageArray. |
| List with per‑item type variation | `HowTo.step → List<HowToStep | HowToSection>`, RepeaterMatrix items mapping to different sub‑schemas | `iterate` with `when matrix_type = "..."` filter (§7.2). |
| Polymorphic property | `Article.author = Person | Organization`, `Event.performer = Person | Organization | PerformingGroup` | `oneOf` datatype; resolver picks based on what the source returns + an optional resolution rule on the property. |
| Polymorphic `@type` | One PW template emits Article / NewsArticle / BlogPosting based on a select field | `@type` itself can be a source‑spec'd property in the type definition (§3.1). |
| Enum (controlled vocabulary URL) | `availability: "https://schema.org/InStock"`, `eventStatus: "https://schema.org/EventScheduled"`, `dayOfWeek: "https://schema.org/Monday"` | `Enum<Vocabulary>` datatype; editor UI offers a dropdown; validity panel flags invalid values. |
| List of enum values | `OpeningHoursSpecification.dayOfWeek: ["Monday", "Tuesday", ...]` | `List<Enum<DayOfWeek>>`. |
| Repeated structured items with internal lists | `LocalBusiness.openingHoursSpecification → List<OpeningHoursSpecification>`, each with `dayOfWeek` array, `opens`, `closes`, optional `validFrom` / `validThrough` | `iterate` of sub‑nodes whose properties include `List<Enum<>>` and Date types. |
| Arbitrary key/value pairs | `Product.additionalProperty: List<PropertyValue>`, `identifier: PropertyValue` (ISBN, GTIN, SKU) | `iterate` with `item_type: PropertyValue` and `item_map: { name, value, propertyID? }`. |
| Constant sub‑node template with holes | `WebSite.potentialAction → SearchAction` (mostly fixed structure, only URL template configurable) | Type definition embeds a constant sub‑node template with source‑spec'd holes (§3.1). |
| Type intersection | `["LocalBusiness", "Restaurant"]`, `["CreativeWork", "Product"]` | `@type` may be a list literal in the type definition. |
| Type subtype inheritance | `Restaurant extends FoodEstablishment extends LocalBusiness extends Organization` (~30 inherited properties) | `extends` field on the type definition (§3.1). |
| Auto‑wired graph cross‑references | `WebPage.mainEntity`, `WebPage.isPartOf`, `Article.publisher`, `Article.mainEntityOfPage` | Renderer policy (§3.4 step 4); declared in type definition's policy block, not per‑site mapping. |
| Selector / template‑level constants | `Article.speakable → SpeakableSpecification` containing CSS selectors that target the rendered page's HTML | `literal` source with a list of selectors, configured at template level (no field source). |
| Conditional emission | `Event.location` required for offline events, forbidden for `OnlineEventAttendanceMode`. `Product.aggregateRating` only when reviews exist. | Built‑in policy library covering well‑known cases; full conditional logic deferred (§16). Hook escape valve covers the long tail. |
| Recursive / self‑referential nesting | `BreadcrumbList.itemListElement[i].item`, `HowToStep.itemListElement` for sub‑steps, `ImageObject.thumbnail → ImageObject` | Source specs are composable; `iterate.item_map` may contain further `iterate`. |
| Identifiers on top‑level properties | `Product.gtin13`, `Product.sku`, `Book.isbn` | Plain `field` source on the property — no special handling needed. |
| Currency codes | `priceCurrency: "USD"` (ISO 4217) | `Text` datatype with optional vocabulary‑validation hint; validity panel flags non‑ISO‑4217 values. |
| Quantitative values with units | `weight: { @type: QuantitativeValue, value: 1.5, unitCode: "KGM" }` | Constant sub‑node template with source‑spec'd holes for `value` and `unitCode`. |
| Speakable / accessibility metadata | `accessibilityFeature`, `accessibilityHazard` | Plain `field` (or `literal` list) — no architectural complication. |

If you're adding a first‑class type (P1, P2, or promoting from P3), check it against this table. If the type uses a value shape that isn't here, that's a signal to revisit §3.1 *first*, not to special‑case it inside the type definition.

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
  eventAttendanceMode: literal "https://schema.org/OfflineEventAttendanceMode"
  eventStatus: literal "https://schema.org/EventScheduled"
  location:
    field_on: page (field "venue") field "address"
    @type: Place
  performer:
    iterate: field "speakers"      # PageReference multi
    item_type: Person
    expand_inline: false           # emit @id only; the speaker pages emit their own Person nodes
```

The Event node lists each speaker as `{ "@id": "https://site/speakers/jane#person" }`. Visiting the speaker page emits the corresponding Person node with the same `@id`, so search engines can connect the two. `eventAttendanceMode` and `eventStatus` are typed as `Enum<EventAttendanceMode>` and `Enum<EventStatus>` in the Event type definition, so the editor UI offers a dropdown rather than a free text box.

### 14.4 Polymorphic `@type` and `oneOf` author on a unified `publication` template

A single PW template `publication` carries blog posts, news items, and op‑eds. The editor picks the subtype via a select field `pub_subtype` whose options are `Article`, `NewsArticle`, `BlogPosting`, `OpinionNewsArticle`. The author can be either a staff member (PageRef to a `staff-member` page → `Person`) or an institutional byline (PageRef to an `organization` page → `Organization`).

Per‑template mapping for `publication`:

```
type:
  @type: field "pub_subtype"          # source-spec'd @type — picks the subtype per page
  extends: Article                    # whatever subtype is picked, inherits Article's properties
properties:
  headline: field "title"
  datePublished: field "published"
  dateModified: field "modified"
  image: field "hero_image"
  author:
    field: "byline"                   # PageRef single
    type: oneOf<[Person, Organization]>
    resolution_rule:
      - when source_template in jsonld_person_templates: Person
      - otherwise: Organization
    expand_inline: false              # @id reference; the byline page emits its own node
```

For a page whose `pub_subtype = NewsArticle` and `byline → /staff/jane/`, the renderer emits a `NewsArticle` node with `author: { "@id": "https://site/staff/jane#person" }`. For a page whose `pub_subtype = OpinionNewsArticle` and `byline → /partners/some-think-tank/`, it emits an `OpinionNewsArticle` node with `author: { "@id": "https://site/partners/some-think-tank#organization" }`. Same template, same mapping; the polymorphism is declarative.

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

**Deferred (known gap, not pretending it's covered):**

- **Full conditional emission logic.** Some Schema.org properties are required only when another property has a given value (`Event.location` required when `eventAttendanceMode != OnlineEventAttendanceMode`; `Offer.price` required when `priceSpecification` absent; `Product.aggregateRating` only when reviews exist). v1 ships a built‑in policy library covering the well‑known cases that Google flags in Search Console; sites with bespoke conditional rules use the `finalizeJsonLdGraph` hook. A full declarative condition language (e.g. *"required if `eventAttendanceMode in [Offline, Mixed]`"*) is deferred until there's evidence the hook escape valve isn't enough.
- **Editor‑facing `Enum<Vocabulary>` UI for Custom‑type properties.** Built‑in types ship with declared enum vocabularies, so the editor gets dropdowns. For Custom‑type properties, the v1 editor surface is a free text box with vocabulary URLs validated by the validity panel; a generic dropdown UI keyed off a registered vocabulary list is a follow‑up.
- **Cross‑page graph stitching.** The auto‑wire rules in §3.4 operate within a single page's graph. *Across* pages, cross‑references work via stable `@id`s pointing at canonical URLs; we don't currently introspect other pages' graphs at render time. If a downstream consumer needs e.g. an Article node embedded in a CollectionPage's `hasPart` array, that's expressed via per‑template mapping (`iterate` over the collection's PageReference) rather than automatic graph stitching.

**Open:**

- **Per‑template mapping storage format.** Either extend the existing `template_defaults_text` textarea grammar, or add a dedicated mapping field. Decision deferred to phase 5 implementation.
- **Sitemap‑style "include this page in JSON‑LD?" toggle.** Probably not needed — the existing per‑page noindex + an empty graph already cover the "don't emit structured data" case. Confirm during phase 5.
- **`@id` cross‑page persistence.** Currently the `@id` is derived from the canonical URL, which is stable. If sites change canonical strategy, existing `@id`s change. We may want a stable per‑node UUID stored on the page; deferred until there's evidence sites need it.
- **Schema.org context version pinning.** Currently `https://schema.org`. Whether to expose the option to pin to a versioned context (`https://schema.org/version/15.0/`) — open.
- **`oneOf` resolution rules — declarative vs hook.** v1 ships a small set of built‑in resolution rules (template‑name based: a Page using a template in `jsonld_person_templates` resolves Person, otherwise Organization). A more general declarative rule language is open; the hook escape valve covers it for now.
- **Built‑in vocabulary registry size.** v1 ships the vocabularies needed by P1/P2 types (`ItemAvailability`, `EventStatus`, `EventAttendanceMode`, `DayOfWeek`, `OfferItemCondition`, `Gender`, `BookFormatType`, `MerchantReturnEnumeration`). Sites needing additional vocabularies register them via the same hook surface as types (`SeoNeo::registerSchemaVocabularies`).

**Non‑goals:**

- Shipping a hand‑written builder for every Schema.org type. The Custom type plus the hook surface is the answer.
- A drag‑and‑drop schema designer in the admin. The mapping is structured but text‑based; the validity panel + preview is the editor‑facing surface.
- Producing structured data in serialisations other than JSON‑LD (Microdata, RDFa). Out of scope.
- Replacing or competing with content‑level structured data (e.g. inline `<itemscope>` markup written by editors in CKEditor body content). SeoNeo emits the page‑level `@graph`; editor‑authored markup is the editor's own responsibility.
- A full declarative replacement for ProcessWire hooks. Hooks are the documented stable extension surface for everything that doesn't fit the source‑spec grammar.

---

## 17. How this document is maintained

- Every JSON‑LD‑related PR references the section(s) of this doc it implements or revises.
- Changes to design rails (new layer, new source kind, change to `@id` strategy, change to multilingual rules) update this doc **first**, in the same PR or in a preceding one.
- New first‑class types are added to §4 with their property list and default mapping summary.
- The phased delivery list in §15 is updated as phases land — completed phases marked done, follow‑ups added beneath as needed.
