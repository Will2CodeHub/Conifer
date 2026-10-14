# Article edit history (versioning) — design

Date: 2026-10-10
Branch: feature/design-system
Status: approved (William: "do it")

## Problem

The article manager shows who is *assigned* as an article's author (`journalist_id` →
`ten_users.full_name`) and *when* it was last modified (`modified_date`), but never **who
actually edited it**. There is no record of past versions. William wants, for each article:
see who edited it, view past versions, compare a version against the current article, and
restore a version.

## Decisions (from brainstorming)

- **Full edit history**, true versioning via **full snapshots** (every editable field).
- **Snapshot storage = one JSON column** (Approach B): schema-stable as the article shape
  evolves (pub-flags, scheduling were added this month). We only ever read a whole version
  back (view / compare / restore), never filter by individual field, so JSON costs nothing.
- Capabilities: **view** any version, **compare** against current (field + body diff),
  **restore** a chosen version (confirm step; logged as a new revision).
- **Access: senior roles only** — Admin / Super Admin / Super User / Administrator / Manager /
  Editor-in-Chief — for both viewing and restoring. Journalists / Section Editors never see it.
- **Retention: keep last N non-published saves per article, but always keep published
  snapshots.** N = 30 (one named constant).
- On **restore**, if the restored snapshot's state is `published`, re-run the publish →
  front-page cache regeneration so the live site reflects the restore immediately.

## Data model

New table in `admin_ten` (same connection as `articles` / `article_pub_flags`), auto-created
on first use — no separate migration step:

```sql
CREATE TABLE IF NOT EXISTS article_revisions (
  id             INT NOT NULL AUTO_INCREMENT,
  article_id     INT NOT NULL,
  edited_by      INT NOT NULL DEFAULT 0,      -- ten_users.id (0 if session lost)
  edited_by_name VARCHAR(190) DEFAULT '',     -- name at edit time (survives rename/delete)
  edited_at      DATETIME NOT NULL,
  state          VARCHAR(20) DEFAULT '',      -- article state after this save
  action         VARCHAR(10) DEFAULT 'save',  -- 'save' | 'restore'
  snapshot       LONGTEXT,                    -- JSON of all editable fields + pub_flags
  PRIMARY KEY (id),
  KEY idx_article (article_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Snapshot JSON = the editable field set already marshalled in `save_article.php`
(title, article_text, alias, tags, section, section_subcat, journalist_id, publications,
canonical, meta_title, meta_description, meta_keywords, evergreen, featured, sponsored,
frontpage_temp, note, publish_now, publish_from, publish_to, state) plus `pub_flags` and
`created_by`.

## Components

### `lib/article_revisions.php` (new, shared)
Pure-ish helpers so the field set stays consistent between capture and restore:
- `ten_ensure_revisions_table($conn)` — idempotent CREATE (mirrors `ten_ensure_pub_flags_table`).
- `TEN_REVISION_SNAPSHOT_FIELDS` — the canonical ordered field list.
- `ten_build_snapshot(array $fields, array $pubFlags): string` — JSON encode.
- `ten_revisions_to_prune(array $ids_states): array` — **pure** function taking rows
  `[{id, state, action}]` newest-first and returning the ids to delete (drop `save` rows
  with non-published state beyond the newest N; never a `published` row). Unit-tested.
- `ten_write_revision($conn, int $articleId, int $editedBy, string $editedByName, string $state, string $action, string $snapshotJson)` — insert then prune via the pure function.
- `ten_apply_article_fields($conn, int $articleId, array $f)` — UPDATE the article from a
  snapshot field array + rebuild slug/url, image_url, imageless, pub flags. Used by **restore**.
- `ten_diff_snapshots(array $old, array $new): array` — **pure**; returns per-field
  `{changed, old, new}`. Unit-tested.

### `ajax/save_article.php` (modified)
After the existing INSERT and UPDATE succeed, build the snapshot from the same variables and
call `ten_write_revision(..., action='save')`. Minimal change to the hot path (just an include
+ snapshot build + one call per branch); the existing inline write is unchanged.

### `ajax/article_revisions.php` (new endpoint)
`requireLogin()` + senior-role gate (403 JSON otherwise). Actions:
- `list` (GET `article_id`) → `[{id, edited_by_name, edited_at, state, action}]` newest-first.
- `get` (GET `article_id`, `rev`) → `{snapshot, diff}` where diff = `ten_diff_snapshots(rev, current_article)`.
- `restore` (POST `article_id`, `rev`) → load snapshot, `ten_apply_article_fields`,
  `ten_write_revision(action='restore')`, and if restored state is `published`,
  `ten_regenerate_publication_caches` (reuse from save_article — move it into the lib or require it).

### `module-articles.php` (modified)
- PHP: `$canViewHistory = isAdmin() || in_array($_SESSION['ten_position'], [...senior...])`;
  emit `window.TEN_canViewHistory`.
- Row actions: a **History** button (clock icon) next to Edit/Preview when `TEN_canViewHistory`.
- A **history modal**: left = version list; selecting one shows, on the right, who/when/state,
  the snapshot (read-only), the diff vs current, and a **Restore this version** button
  (SweetAlert2 confirm → POST restore → toast → reload list / refresh article row).

## Testing

- `tests/test_article_revisions.php` (PHP CLI): unit-test the pure functions —
  `ten_build_snapshot` round-trips; `ten_revisions_to_prune` keeps newest-N + all published,
  drops the rest; `ten_diff_snapshots` flags only changed fields. No DB needed.
- Live smoke test in `theeyenewspapers.com/management`: edit an article twice, confirm two
  revisions logged, view + diff + restore round-trip, and that journalists don't see the button.

## Deployment

Entirely within the `management/` repo (`lib/`, `ajax/`, `module-articles.php`), so it deploys
to `theeyenewspapers.com/management` the normal way. The table auto-creates on first save. No
parent-template files touched.
