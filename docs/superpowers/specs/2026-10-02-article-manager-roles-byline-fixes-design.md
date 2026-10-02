# Article Manager — roles, permissions, byline & column fixes

**Date:** 2026-10-02
**Branch:** feature/design-system
**Status:** Design approved, pending spec review

## Problem

Several editorial access rules are not enforced, and the published byline breaks
when an editor reassigns an article's author:

1. A journalist can change an article's author to another journalist.
2. A journalist can see articles that are not their own.
3. A journalist can edit an article after it is published or submitted for review
   (they should only be able to edit their own **drafts**).
4. The article-manager Date column shows a date only — no time, and no indication
   that an article is scheduled to publish at a future time.
5. When a Section Editor or above changes an article's author, the published
   byline shows the house line ("All content brought to you by The Munich Eye
   News.") instead of the new author's name. Reproduced with the "Hannah Mones
   test" account.
6. The orderable columns don't signal that they're orderable until after a click.

Context: the `feature/design-system` branch already enforces #1 and #2
server-side, but the live site runs older code, so the rules appear broken. The
correct code must be in place **and deployed**. #3, #4, #5, #6 are genuine gaps.

## Role model (reference)

`$_SESSION['ten_position']` = the user's highest role (`login.php`, by
`role_level`). `ten_users.publication` and `ten_users.section` are
comma-separated assignment lists. Capability tiers already used across
`get_articles.php` / `get_article_data.php` / `save_article.php`:

- **See-all:** Admin, Super Admin, Super User, Administrator, Manager.
- **Pub-scoped editors:** Editor, Managing Editor, General Editor,
  Editor-in-Chief, Edition Editor-in-Chief — all sections within their pubs.
- **Section Editor:** their section(s) **within** their publication(s); non-draft only.
- **Journalist:** their own articles only; may submit but not publish.

## Changes

### 1. Journalist cannot change author (verify + deploy only)

Already enforced:
- `ajax/save_article.php` forces `$author = $userId` when position is Journalist.
- `ajax/get_article_data.php` returns only the journalist's own row for the author dropdown.

No code change. Covered by deployment at the end.

### 2. Section-Editor author scoping (new)

A Section Editor may reassign an article's author only to a user who shares at
least one of the editor's **publications** AND at least one of the editor's
**sections** (Section Editors are pub+section scoped at creation).

- `ajax/get_article_data.php`: when `position === 'Section Editor'`, build the
  author dropdown from active author-eligible users (same role whitelist as
  today) whose `publication` intersects the editor's `publication` list **and**
  whose `section` intersects the editor's `section` list. Intersection is done in
  PHP over the comma lists (the stored fields are CSV, so no SQL equality).
  The article's current author is still force-appended so it shows as selected
  (existing behaviour at `get_article_data.php` ~line 348).
- `ajax/save_article.php`: defence in depth. When the saver is a Section Editor
  and the posted `author` differs from the article's existing `journalist_id`,
  verify the new author is within the editor's pub∩section set; if not, reject
  with an error and do not write. See-all roles and pub-scoped editors are
  unaffected. Journalists are already locked to self.

### 3. Journalist draft-only editing (new)

- `ajax/get_articles.php`: for a Journalist, set `editable = 1` only when
  `state === 'draft'`; otherwise `0`. Non-draft rows remain listed (the journalist
  still sees their own submitted/published work) but render no Edit button;
  Preview/Live links are unaffected.
- `ajax/save_article.php`: after loading the existing article, if the saver is a
  Journalist and the **stored** state is not `draft`, reject the save
  ("You can only edit your own drafts."). This guards direct POSTs regardless of
  UI. Creating a new article and saving/submitting a draft are unaffected.
- `ajax/get_article_data.php`: unchanged (a journalist may still open their
  non-draft article to read it in the modal; the save is what's blocked). This
  keeps "see" working while "edit" is blocked, matching the requirement.

### 4. Date column: last-modified date+time + scheduled marker (new)

- `ajax/save_article.php`: the UPDATE path must set `modified_date = NOW()` so
  "last modified" actually advances on every edit (currently only the INSERT sets
  it). Add `modified_date = NOW()` to the UPDATE statement and its bind list.
- `ajax/get_articles.php`: add `modified_date`, `publish_from`, `publish_now`
  to each returned row. Add `modified_date` to `$allowedSort`.
- `module-articles.php`:
  - The Date column shows the **last-modified** datetime as `dd Mon yyyy, HH:MM`
    (24h). Its header `data-col` becomes `modified_date` so sorting orders by last
    modified. Header label stays "Date" (tooltip "Last modified").
  - When `publish_now == 0` and `publish_from` is set and in the future, append a
    small amber badge: `⏱ Scheduled <dd Mon, HH:MM>` under the date.

**Decision (ambiguity resolved):** the Date column reflects *last modified*, not
submission date, because the requirement explicitly asks for the last-modified
time. Submission date is no longer shown in this column.

### 5. Byline no longer falls to the house line for a real author (parent template + data)

Two parts (William chose "Both"):

**5a. Resolver fallback (code).** In `get_author_byline($journalist_id,
$published_name, $is_alias)`:
- House accounts 112 / 186 keep their in-house line (unchanged).
- If `firstname` is non-empty → full linked byline (unchanged).
- **New:** else if `$published_name` is non-empty → render a byline with
  `Author: <published_name>` and the "Please use the Contact Form" email line, but
  **no** bio link and no Title/Section row (we have a name but no profile). This
  covers any author whose `ten_users` row lacks byline fields (e.g. the Hannah
  test account), for all current and future authors.
- Else (no name at all) → the house line (unchanged).

`$published_name` is the article's `created_by` (or alias), which
`save_article.php` already keeps in sync with `journalist_id`.

Apply the identical change to both copies that the live sites use:
`backend/article_functions.php` and `content/article_functions.php`.

**Deployment caveat:** these files live in the template root, **outside** the
`management/` git repo, and are chrooted per publication. I can FTP-deploy the
theeyenewspapers.com copies; the other publications' copies are handed to William
to upload to each `/backend/` and `/content/`.

**5b. Byline backfill (data).** For active, author-eligible `ten_users` rows with
an empty `firstname`, backfill non-fabricated identity so their bios/links work
too: `firstname`/`surname` split from `full_name`, `pen_name = full_name`,
`position` from their highest role, and a generated numeric `public_id` (no unique
index). Leave `byline`/`bio` prose **empty** (do not fabricate — William supplies
real copy, per the Savannah precedent). Done via a token-protected script run on
the live server against the localhost DB, then deleted. The resolver fallback (5a)
already makes the name show even before any backfill.

### 6. Persistent orderable arrows (UI)

- `module-articles.php`: render a neutral `⇅` (dimmed) in every `.sort-arrow`
  span at rest so sortable headers advertise themselves. The active column shows
  `▲`/`▼`; on sort, reset the others back to the neutral `⇅` rather than blank.
  Initialise the active column's arrow on load to match the default sort
  (`modified_date` DESC → `▼`). Only the four already-sortable columns (ID, Title,
  Status, Date) carry an arrow; Author/Publication/Section remain unsorted.

## Out of scope

- No new sortable columns (Author/Publication/Section stay as they are).
- No redirect table for changed URLs (unchanged from prior decision).
- `byline`/`bio` prose authoring (William supplies).
- Legacy site generators that read the single flag columns (unchanged).

## Testing / verification

- **Journalist:** cannot see others' rows (`get_articles` own-only); author
  dropdown = self; no Edit button on non-draft rows; a direct POST save to a
  non-draft own article is rejected; can still save/submit a draft.
- **Section Editor:** author dropdown lists only pub∩section peers; a POST
  reassigning to an out-of-scope author is rejected; in-scope reassign succeeds.
- **Editor/above:** author reassignment unrestricted; reassigning to an author
  with no byline fields shows `Author: <name>` on the live article (not the house
  line).
- **Date column:** editing an article advances its displayed last-modified time;
  a future-scheduled article shows the Scheduled badge.
- **Arrows:** all four sortable headers show `⇅` at rest; clicking cycles
  ▲/▼ and resets the others to `⇅`.
- Verify live endpoints with the token-protected impersonation harness pattern
  (sets `$_SESSION` like `login.php`, cURLs the real endpoints), then delete it.

## Deployment

Management repo files (FTP to `/public_html/management/`): `ajax/get_articles.php`,
`ajax/get_article_data.php`, `ajax/save_article.php`, `module-articles.php`.
Parent template (FTP to theeyenewspapers.com `/public_html/backend/` and
`/content/`): `article_functions.php`. Other publications' copies handed to
William. Byline backfill: token-protected script, run then deleted.
