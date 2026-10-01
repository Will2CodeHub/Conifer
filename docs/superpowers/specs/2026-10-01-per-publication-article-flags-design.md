# Per-publication article flags — design

**Date:** 2026-10-01
**Status:** Approved (William, 2026-10-01) — building
**Branch:** feature/design-system

## Problem

An article is published to one or more publications (`articles.publications` is a CSV
like `ten,tme,tge`). Today the editorial flags are **single columns on the `articles`
row**, so they apply to the article on *every* publication it appears on:

| Column | Meaning | Editor label |
|---|---|---|
| `frontpage_temp` | Front-page headline | "Frontpage Headline" |
| `featured` | Section headline | "Section Headline" |
| `sponsored` | Paid/partner content | "Sponsored" |
| `evergreen` | Never expires | "Evergreen" — **being removed** |

William wants these **per publication**: e.g. an article can be the front-page headline on
The Munich Eye, a plain article on The Germany Eye, and a section headline on The Berlin Eye
— chosen *inside the Publications tab* next to each publication, not as one global switch.

### Decisions (William, 2026-10-01)
1. **Sponsored is per-publication** too.
2. **Evergreen is removed** from the editor UI — never used. The `evergreen` column stays in
   the DB (untouched/ignored) to avoid a destructive migration; it just disappears from the
   forms.
3. Per-pub flags are exactly: **frontpage headline, section headline, sponsored**. No others.

## Constraints / what makes this cross-system

- Articles live in **`admin_ten`** (`getDBConnection_TENAdmin()`), not `TEN_Management`. The
  per-pub flags store must live in `admin_ten` so each publication's site can read it.
- The **live sites consume these flags** when building front/section pages
  (`generate_index_page.php` per site, section/sidebar builders), which live on each
  publication's own server, **outside this repo**, deployed per-pub by William. So the
  feature is only *fully* live once each site reads the new store.
- Back-compat is mandatory: a site not yet updated must keep working off the existing columns.

## Approach

A dedicated join table, plus keep the legacy columns in sync for un-migrated sites.

### 1. Data model — new table in `admin_ten`

```sql
CREATE TABLE IF NOT EXISTS article_pub_flags (
    article_id   INT NOT NULL,
    publication  VARCHAR(32) NOT NULL,   -- matches publications.publication / the CSV tokens
    frontpage    TINYINT(1) NOT NULL DEFAULT 0,   -- front-page headline on THIS pub
    section_head TINYINT(1) NOT NULL DEFAULT 0,   -- section headline on THIS pub
    sponsored    TINYINT(1) NOT NULL DEFAULT 0,   -- sponsored on THIS pub
    PRIMARY KEY (article_id, publication),
    KEY idx_pub_front (publication, frontpage),
    KEY idx_pub_section (publication, section_head)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

A row exists only for publications the article is in. Absent row = all flags 0.

### 2. Back-compat with the legacy columns

On every save, after writing `article_pub_flags`, set the old columns on `articles` to the
**OR across the article's publications**:
- `frontpage_temp = 1` if any pub has `frontpage = 1`
- `featured = 1`      if any pub has `section_head = 1`
- `sponsored = 1`     if any pub has `sponsored = 1`

So an un-updated site keeps showing the article as a headline/sponsored *somewhere* (its
current behaviour) while an updated site reads `article_pub_flags` for precise per-pub
control. `evergreen` is left as-is (no longer edited).

### 3. Editor UI — flags move into each publication row

Both the Add-article form (`#add_article_publications`) and the edit modal
(`#modal_publications_container`) build publication rows in JS with a checkbox (include) +
canonical radio. Add three small per-row toggles, enabled only when the row is ticked:

```
[x] The Munich Eye     (o) canonical   [🔥 Front] [★ Section] [💲 Sponsored]
[x] The Germany Eye    ( ) canonical   [ ] Front  [ ] Section [ ] Sponsored
```

The standalone global flag checkboxes (Frontpage/Section/Evergreen/Sponsored) are **removed**
from both forms. The edit modal's now-empty "Flags & Scheduling" tab is removed (scheduling
sits above the tabs already); the add form's "Flags & Scheduling" tab keeps only the
scheduling block and is renamed **"Scheduling"**.

### 4. Save path (`ajax/save_article.php`)

- New POST field `pub_flags` = JSON: `{ "tme": {"frontpage":1,"section_head":0,"sponsored":1}, ... }`.
- After the article insert/update: `DELETE FROM article_pub_flags WHERE article_id = ?` then
  insert one row per selected publication (intersected with the `publications` CSV).
- Recompute legacy `frontpage_temp` / `featured` / `sponsored` as the OR above. `evergreen`
  defaults to 0 (UI gone); existing values are left untouched on rows not re-saved.

### 5. Read path (`ajax/get_article_data.php`)

Return `pub_flags` (the rows keyed by publication) so the editor pre-ticks each row. Keep
returning the legacy fields for anything still using them.

### 6. Live-site generators (external, per-pub — handed to William)

Each site's front-page/section builder changes from the global column to:

```sql
LEFT JOIN article_pub_flags f ON f.article_id = a.id AND f.publication = '<thisPub>'
WHERE COALESCE(f.frontpage, a.frontpage_temp) = 1   -- per-pub, legacy fallback
```

Delivered as a diff per publication; William deploys to each site's chroot.

### 7. Migration / backfill

One-shot token-protected script (deleted after — the established pattern): create the table,
then backfill one row per (article, publication) from the article's current global flags, so
nothing changes visually until per-pub edits begin.

```sql
-- per article, split publications CSV in PHP; for each pub:
INSERT INTO article_pub_flags (article_id, publication, frontpage, section_head, sponsored)
VALUES (?, ?, a.frontpage_temp, a.featured, a.sponsored)
ON DUPLICATE KEY UPDATE frontpage=VALUES(frontpage), section_head=VALUES(section_head), sponsored=VALUES(sponsored);
```

## Alternatives considered

- **Per-pub flag columns on `articles`**: rejected — schema churn, unbounded as pubs grow.
- **JSON column on `articles`**: every site's SQL would need `JSON_EXTRACT` and can't index
  per-pub cheaply; the join table is cleaner for the generators. Rejected.

## Rollout order

1. Migration (create table + backfill) — additive, sites unaffected.
2. Management side (editor UI + save/read) — editors set per-pub flags; legacy columns stay
   in sync so **all** sites keep working immediately.
3. Per-pub generator updates — each site honours per-pub flags as William deploys.
