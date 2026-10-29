# Numbered Article Placeholders — Design

Date: 2026-10-29
Status: Approved (ready for implementation plan)
Branch: `feature/design-system`

## Problem

Today, the only way to put a *list* of articles into a Design Studio layout is a
`{{#each items}} … {{/each}}` loop inside a block's template. Loops render a
single repeated structure and are a concept most editors never reach for.

Editors want to place individual articles **where they like** in a bespoke
layout — e.g. article 1 as a large hero, article 2 in a sidebar, article 3 as a
small card elsewhere — and to show the headline (as text or as a link to the
article) and a teaser, with control over teaser length. They do not want to
author loop syntax.

## Solution overview

A new kind of placeholder, inserted individually from the HTML editor, that
resolves on the live site to the **Nth latest article in a chosen section**.
No loop required. Three fields:

- **Title** — the headline as plain text (no link).
- **Title link** — the headline wrapped in an `<a>` to the article.
- **Snippet** — the article's teaser, with a settable length in words.

Each field auto-numbers itself per section as it is inserted (`title link 1`,
`title link 2`, …). Because the number means "Nth latest in that section," the
same number across fields refers to the **same article** — so a headline and its
snippet always describe the same story, with no extra wiring.

## Token contract

Self-describing tokens, consistent with the existing copyable `{{token}}` style:

```
{{article:<section>:<n>:title}}
{{article:<section>:<n>:titlelink}}
{{article:<section>:<n>:snippet}}
{{article:<section>:<n>:snippet:<words>}}
```

- `<section>` — a **section slug** (lowercase, `[a-z0-9-]`), or the literal
  `latest` for "any section, most recent."
- `<n>` — 1-based index into that section's latest-first list.
- `title` | `titlelink` | `snippet` — the field.
- `<words>` — snippet length in words. Optional; default **40**.

Examples:

```
{{article:politics:2:titlelink}}     2nd latest Politics story, headline as a link
{{article:politics:2:snippet:30}}    its teaser, 30 words  (same article as above)
{{article:latest:1:title}}           most recent story site-wide, headline text only
```

### Why a slug and a colon delimiter

The existing engine var regex is `[\w.]+`, which excludes `:` and `-`. Rather
than widen that regex (and risk colliding with ordinary dotted tokens and with
section names containing spaces/hyphens), article-slot tokens get their **own
resolver pass** with its own regex. The section is referenced by a stable slug
so the token text stays clean and legible (important: these tokens are also
copyable into AI prompts and visible in source view).

## Engine (live `/design/` site)

New resolver, run as a pre-pass before the generic template engine so the
filled-in HTML flows through normally afterwards.

- **New function** (e.g. `dl_fill_article_slots(string $html): string` in a new
  `design/lib/article_slots.php`, included from `design/lib/bootstrap.php`).
- Regex: `/\{\{\s*article:([a-z0-9-]*):(\d+):(title|titlelink|snippet)(?::(\d+))?\s*\}\}/i`.
- For each match:
  - Resolve the section slug to a real section name by slugifying each known
    section and matching; `latest` (or empty) means "any section."
  - Fetch the latest list **once per section, memoised per request**:
    - specific section → `dl_section_articles($section, $maxNeeded)`
    - `latest` → `dl_carousel_articles('', $maxNeeded)` (already falls back to
      `dl_front_articles()` for the all-section case).
    - `$maxNeeded` = the highest `<n>` seen for that section in the HTML, so one
      query covers every slot.
  - Take item `n-1`. If it does not exist (fewer articles than `n`), the token
    renders **empty** (fail-soft, matching engine convention).
  - Output:
    - `title` → `htmlspecialchars($title)`.
    - `titlelink` → `<a href="{href}" class="dl-art-link">{escaped title}</a>`,
      href from `dl_article_href()`.
    - `snippet` → teaser truncated to `<words>` words (word-based, with an
      ellipsis when truncated), built from the same source `dl_teaser_text()`
      uses.
- **Where it runs:** inside `dl_render_template()` (or a thin wrapper called by
  `dl_render_block_template()`), so tokens inside any block template are filled.
  It must run on both the live render and the editor **preview** render, so the
  preview shows real content.

### Wiring / helpers

- A small `dl_section_slug(string $name): string` + reverse lookup over the
  publication's known sections (the same list the Studio's `link_targets`
  action already returns).
- A word-truncation helper (reuse/extend the logic behind `dl_teaser_text`;
  that function currently truncates by **characters**, so add word-based
  truncation rather than changing its signature).

## Editor (management Design Studio)

### Insert UX (`js/design_studio/html_editor.js`)

Add an **"Article"** insert control to the HTML editor rail that opens a small
popover:

- **Section** — dropdown from the publication's sections (via `link_targets`),
  plus "Latest (any section)" (= `latest`).
- **Field** — Title / Title link / Snippet (radio or segmented).
- **Length (words)** — number, shown only for Snippet, default 40.
- **Insert** button.

On insert:

1. Determine the next number: scan the current template for existing
   `article:<slug>:<k>:<field>` tokens of the **same section + same field**,
   take `max(k)`, assign `k + 1` (first is 1).
2. Build the token and insert it at the caret via the existing `insertToken()` /
   `makeTok()` path, so it renders as a labelled chip.

### Chip label

The inserted token shows as a chip reading, e.g., *"Politics — title link 2"*
or *"Latest — snippet 1 · 40 words"*, using the existing `dl-tok` chip styling
and a label derived from the token parts.

### Editing a placed snippet

Selecting a placed **snippet** chip shows a "Snippet length (words)" field in
the inspector; changing it rewrites the token's `<words>` segment in place.
(Title ⇄ Title link conversion is **out of scope for v1** — re-insert instead;
revisit if wanted.)

### Discoverability (`management/lib/design_registry.php`, manual)

Add a new shared group to `dl_placeholder_defs()` — **"Numbered article slots"**
— documenting the three fields and the `:n` / `:words` convention, so the tokens
appear (copyable) in the Placeholders manual (`DS.openPlaceholders`) and the
Placeholders design page. These are not `loop` tokens.

## Out of scope (v1)

- Image / date / section-name fields on a numbered slot (titles, links and
  snippets only for now; the token shape leaves room to add fields later).
- Title ⇄ Title link in-place toggle.
- De-duplication across slots beyond "Nth latest" ordering (if the same article
  is the Nth latest in two different sections, it can appear twice — acceptable).
- Any change to the existing `{{#each}}` loop blocks.

## Deploy footprint

Two repos:

1. **`management/`** — editor UI (`js/design_studio/html_editor.js`), manual
   defs (`lib/design_registry.php`), any chip-label CSS. FTP-deployable to TEN
   (theeyenewspapers.com docroot) by the usual WinSCP path.
2. **`theeyenewspapers_website_template/design/`** — the resolver
   (`design/lib/article_slots.php` + wiring in `template.php` / `bootstrap.php`,
   data helpers in `data.php`). This is the **per-publication live engine**.
   Deploy to **TPE first** for testing (matches the design-system test phase);
   other publications' engine copies are deployed by William.

Both halves must be live for the feature to work end-to-end: the editor can
insert tokens before the engine understands them, but they will render empty
until the engine resolver is deployed to that publication.

## Success criteria

- Inserting "Title link" three times for Politics yields
  `{{article:politics:1:titlelink}}`, `…:2:…`, `…:3:…`, shown as numbered chips.
- On the live site (TPE), those render as links to the 1st/2nd/3rd latest
  Politics articles.
- A `title`/`titlelink` and a `snippet` with the same section + number describe
  the same article.
- Snippet length is controllable per placeholder (words) and defaults to 40.
- "Title" (plain) renders text with no link; "Title link" renders a link.
- Fewer-than-N articles → the slot renders empty, no error.
