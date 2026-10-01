# Per-publication article flags — design

**Date:** 2026-10-01
**Status:** Draft for review (William)
**Branch:** feature/design-system

## Problem

An article is published to one or more publications (`articles.publications` is a CSV
like `ten,tme,tge`). Today the editorial flags are **single columns on the `articles`
row**, so they apply to the article on *every* publication it appears on:

| Column | Meaning | Editor label |
|---|---|---|
| `frontpage_temp` | Front-page headline | "Frontpage Headline" |
| `featured` | Section headline | "Section Headline" |
| `evergreen` | Never expires | "Evergreen" |
| `sponsored` | Paid/partner content | "Sponsored" |

William wants these to be **per publication**: e.g. an article can be the front-page
headline on The Munich Eye, a plain article on The Germany Eye, and a section headline
on The Berlin Eye — chosen *inside the Publications tab* next to each publication, not
as one global switch.

Scope decision (confirm): **frontpage headline, section headline, and evergreen become
per-publication**; **sponsored stays global** (paid status is the same wherever it runs).

## Constraints / what makes this cross-system

- Articles live in the **`admin_ten`** database (`getDBConnection_TENAdmin()`), not
  `TEN_Management`. Any per-pub flags store must live in `admin_ten` so each publication's
  site can read it.
- The **live sites consume these flags** when building front pages and section pages
  (`generate_index_page.php` per site, section/sidebar builders). Those files live on
  each publication's own server, **outside this repo**, and are deployed per publication
  by William. So this feature is only *fully* live once each site reads the new store.
- Back-compat is mandatory: a site that has **not** been updated must keep working off
  the existing columns until its generator is updated.

## Approach (recommended)

A dedicated join table, plus keep the legacy columns in sync for un-migrated sites.

### 1. Data model — new table in `admin_ten`

```sql
CREATE TABLE IF NOT EXISTS article_pub_flags (
    article_id   INT NOT NULL,
    publication  VARCHAR(32) NOT NULL,   -- matches publications.publication / the CSV tokens
    frontpage    TINYINT(1) NOT NULL DEFAULT 0,   -- front-page headline on THIS pub
    section_head TINYINT(1) NOT NULL DEFAULT 0,   -- section headline on THIS pub
    evergreen    TINYINT(1) NOT NULL DEFAULT 0,   -- evergreen on THIS pub
    PRIMARY KEY (article_id, publication),
    KEY idx_pub_flags (publication, frontpage, section_head)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

A row exists only for publications the article is in. Absent row = all flags 0.

### 2. Back-compat with the legacy columns

On every save, after writing `article_pub_flags`, also set the old columns on `articles`
to the **OR across all the article's publications**:
- `frontpage_temp = 1` if any pub has `frontpage = 1`
- `featured = 1` if any pub has `section_head = 1`
- `evergreen = 1` if any pub has `evergreen = 1`

So an un-updated site keeps showing the article as a headline *somewhere* (its current
behaviour), while an updated site reads `article_pub_flags` for precise per-pub control.
`sponsored` stays a single column, unchanged.

### 3. Editor UI — merge flags into the Publications tab

In both the Add-article form (`#add_article_publications`) and the edit modal
(`#modal_publications_container`), each publication row already has a **checkbox**
(include) + a **canonical radio**. Add three small flag toggles to each row, enabled only
when that publication is ticked:

```
[x] The Munich Eye      (o) canonical   [🔥 Front]  [★ Section]  [🌱 Evergreen]
[x] The Germany Eye     ( ) canonical   [ ] Front   [ ] Section  [ ] Evergreen
```

The standalone "Flags" tab keeps **Sponsored** (global) and loses Frontpage/Section/
Evergreen (now per-row). Tooltips carry over.

### 4. Save path (`ajax/save_article.php`)

- Accept a new POST field `pub_flags` = JSON: `{ "tme": {"frontpage":1,"section_head":0,"evergreen":1}, ... }`.
- After the article insert/update: `DELETE FROM article_pub_flags WHERE article_id = ?`
  then insert a row per selected publication from `pub_flags` (ignoring any pub not in the
  article's `publications` CSV).
- Recompute and write the legacy `frontpage_temp` / `featured` / `evergreen` columns as the
  OR described above. Keep `sponsored` as today.

### 5. Read path (`ajax/get_article_data.php`)

Return `pub_flags` (the `article_pub_flags` rows keyed by publication) so the editor can
pre-tick each row. Keep returning the legacy flag fields for anything still using them.

### 6. Live-site generators (external, per-pub — handed to William)

Each site's front-page/section builder changes from reading the global column to:

```sql
... JOIN article_pub_flags f
      ON f.article_id = a.id AND f.publication = '<thisPub>'
   WHERE f.frontpage = 1   -- front-page headline for THIS publication
```

with a fallback to the legacy column while a site is un-migrated. Delivered as a diff per
publication; William deploys to each site's chroot.

### 7. Migration / backfill

One-shot script (token-protected, deleted after — the established pattern): create the
table, then backfill one row per (article, publication) from the article's current global
flags, so nothing changes visually until per-pub edits begin.

```sql
INSERT INTO article_pub_flags (article_id, publication, frontpage, section_head, evergreen)
SELECT a.id, TRIM(j.pub), a.frontpage_temp, a.featured, a.evergreen
FROM articles a
JOIN JSON_TABLE(...) -- or a split on a.publications CSV
WHERE a.publications <> '';
```

(Exact CSV-split done in PHP in the migration script for portability.)

## Alternatives considered

- **Per-pub flag columns on `articles`** (e.g. `frontpage_tme`, `frontpage_tge`…): rejected —
  schema churn, unbounded as publications grow, ugly reads.
- **JSON column on `articles`** (`pub_flags JSON`): simpler to write, but every site's SQL
  would need `JSON_EXTRACT` and can't index per-pub cheaply; the join table is cleaner for
  the generators. Rejected in favour of the table.

## Rollout order

1. Migration (create table + backfill) — safe, additive, sites unaffected.
2. Management side (editor UI + save/read) — editors can set per-pub flags; legacy columns
   stay in sync so **all** sites keep working immediately.
3. Per-pub generator updates — each site starts honouring per-pub flags as William deploys.

## Open questions for review

1. **Sponsored** — keep global (recommended) or make it per-pub too?
2. Is **evergreen** genuinely per-pub, or is "never expires" really a global property? (If
   global, drop it from the per-row set and keep it on the Flags tab.)
3. Any other per-pub flags wanted now (e.g. a per-pub "breaking" marker), or just these?
