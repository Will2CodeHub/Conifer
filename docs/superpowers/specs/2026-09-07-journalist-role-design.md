# Journalist Role — End-to-End Design

Date: 2026-09-07
Status: Approved (design), implementing

## Goal

Make a **Journalist** role in the TEN Management tool behave exactly as specified:

1. A `Journalist` role exists and can be assigned to a user.
2. When creating/editing a journalist they **can be assigned a Section and a Publication, or not**.
3. If assigned a section, the journalist **does not choose** a section when writing an article (it is fixed to their section). Same for publication.
4. If **not** assigned a section, the journalist **chooses** the section for any article they submit (and likewise publication).
5. Journalists see **only the Article Management page** — no dashboard, no other tools.
6. The Article Management page shows **only their own articles**.
7. Journalists **do not see the Sponsored Articles tab** (they DO keep View All Articles, Add Article, and Add Image).
8. The only other thing available to a journalist is their **journalistic bio and byline** via their profile/settings page.
9. Journalists **submit for review** only — they cannot publish directly.

## Root cause / architecture

The tool has two authorization layers that must agree:

- **Layer A — sidebar & page access:** `ten_roles` → `ten_role_permissions` → `ten_permissions`; each `ten_modules` row has a `required_permission`. A module is visible/reachable when its `required_permission` is NULL (everyone) or the user holds it. `includes/sidebar.php` enforces this for all users incl. admins.
- **Layer B — article behaviour:** `$_SESSION['ten_position']` (set at login to the user's **highest `role_name`**) plus `ten_users.section` and `ten_users.publication`. Drives `ajax/get_articles.php` (list filtering) and `ajax/get_article_data.php` (section/publication/author dropdowns).

Today Layer B has explicit `'Journalist'` handling for *list filtering* only. The section/publication auto-fill, the section-assignment UI, the Sponsored tab, the dashboard landing, and several ungated module pages are missing or wrong. This design closes all gaps.

## Decisions (confirmed with user)

- Dashboard: journalists are **redirected to `module-articles.php`** and blocked from the dashboard.
- Publication: journalists are **assignable to a publication** too (mirrors section).
- Publish rights: **submit for review only** (unchanged; already excluded from publish list).
- Byline/bio: **write to `ten_users`** (matches migrated live site, STAGE C).
- Add Image tab: **kept** for journalists. Only the **Sponsored** tab is hidden.

## Changes

### C1 — Assign Section & Publication at user create/edit (Layer B data)
- `module-users.php`: add optional **Section** and **Publication** selects to the Create/Edit User modal. Sections from `admin_ten.main_menu` (top-level section items); publications from `admin_ten.publications` where `pub_live=1`. Pre-select existing values on edit (`ten_users.section`, `ten_users.publication`).
- `ajax/users.php`: `create_user` and `update_user` persist `section` and `publication` on `ten_users`. Empty string stored as NULL/'' (means "not assigned").

### C2 — Section/publication auto-fill for journalists
- `ajax/get_article_data.php`:
  - Sections: add a branch so that when `position === 'Journalist'` **and** `ten_section` is non-empty, return only that one section (collapses the dropdown to one option → auto-selected). When empty, return all sections. (Section Editor already does this; Journalist currently falls into the "all sections" branch — fix that.)
  - Publications: the existing branch already returns only the assigned publication for `Journalist` when `publication` is set; when empty they fall through to all publications (correct). Verify and keep.
- `module-articles.php`: the Add Article JS already auto-selects when exactly one section/one publication is returned — no change needed beyond C2 server behaviour.

### C3 — Article page UI restrictions (`module-articles.php`)
- Determine journalist context in PHP: `$isJournalist = ($_SESSION['ten_position'] ?? '') === 'Journalist'`.
- Hide the **Sponsored Articles** tab button and its tab-content pane when `$isJournalist`.
- Keep View All Articles, Add Article, Add Image.
- Add a page-level guard: allow admins and any recognised editorial position incl. `Journalist`; otherwise redirect to dashboard/login. (The page currently has only `requireLogin()`.)

### C4 — Journalists can edit their own articles
- `ajax/get_articles.php`: the `editable` flag (line ~327) currently excludes `Journalist`, so the list hides the edit button on their own rows even though `ajax/save_article.php` permits owner edits. Set `editable=1` for a journalist's own rows (they only ever see their own rows anyway) so the edit button appears.

### C5 — Login/dashboard routing
- `login.php`: after setting session, if `ten_position === 'Journalist'` redirect to `module-articles.php` instead of `dashboard.php`.
- `dashboard.php`: near the top, if `ten_position === 'Journalist'` redirect to `module-articles.php` (covers direct navigation / bookmarks).

### C6 — Harden URL-reachable modules (Layer A)
- Add a `hasPermission(...)/isAdmin()` guard (same shape as existing gated modules) to the pages that currently only call `requireLogin()`:
  `module-candidates.php`, `module-news.php`, `module-project-management.php`, `module-venues-tool.php`, `module-wne.php`, `module-wne-jobs.php`, `module-wne-project.php`.
  Each uses the permission key that already gates its sidebar module (verified against `ten_modules.required_permission` during the DB step). This prevents a journalist reaching a tool by typing its URL.

### C7 — Byline/bio → `ten_users` (`ajax/save_profile.php`)
- In `save_newsportal_profile`, in addition to the existing `admin_ten.users` write, update `ten_users` `byline`, `bio`, and `section` for the current user, so byline/bio edits reach the migrated live site. `profile.php` remains journalist-accessible (already `requireLogin()`).

### C8 — DB: role + module gating (Layer A data)
Executed server-side via a temporary token-protected PHP script deployed over FTPS, then deleted.
- Ensure `ten_roles` has `role_name='Journalist'`, `role_key='journalist'`, `role_level` below Section Editor, `is_system=0`.
- Grant the Journalist role only the permission(s) needed to see the **Articles** module in the sidebar (the `required_permission` of the articles module) plus nothing else.
- Verify every other enabled `ten_modules` row has a non-NULL `required_permission` (NULL leaks the module to everyone, including journalists). Set gates on any that are NULL.

### C9 — Test journalist account
- Create a test journalist user (Layer A role = Journalist; Layer B `section`/`publication` set for one variant) plus a second unassigned variant, to validate both branches. Created via the (fixed) user UI or the DB script.

## Testing / acceptance

Drive the live site in the browser (admin already logged in; log in as the test journalist in a separate context):
- Sidebar shows **only** Articles for the journalist; dashboard URL redirects to Articles.
- Article list shows **only** the journalist's own articles; edit button present on own rows.
- Add Article: with an assigned section+publication → those fields are fixed (single option, auto-selected); with none assigned → journalist can choose from all.
- No **Sponsored** tab; Add Image and Add Article present.
- Journalist can Save (draft) and Submit (under review) but not Publish.
- Profile page saves byline/bio; values land in `ten_users`.
- Typing another module's URL (e.g. `module-candidates.php`) as the journalist is blocked.

## Operational notes

- Management tool + shared DB → deploy only to theeyenewspapers.com (`/public_html/management/`). No per-pub deploy.
- Deploys go straight to LIVE (no staging for the management tool). Deploy file-by-file via WinSCP FTPS. The DB step runs via a temporary script that is deleted immediately after.
- Related memory: [[project_roles_permissions]], [[ten-users-vs-old-users-byline]], [[server-access-deploy]].

---

## Addendum (2026-09-07): expanded to suite-wide RBAC

The journalist work was extended (user-approved) to a full role matrix across every tool.

### Role → tool matrix (implemented)
- **Super User** — everything. **Administrator** — everything except PKV. **Manager** — Dashboard, all Statistics, Articles, News Sites, Data Scraper, Marketing, Emails, CRM, File Transfers, Project Management, Restaurant Builder, Venues (no Users/Roles/Settings, no PKV/WNE).
- **Editorial (Articles only, no dashboard):** Managing Editor, General Editor, Editor, Section Editor, Journalist. Section Editor and above also get Data Scraper. Capabilities on the Articles page by position: Journalist = own + submit-only; Section Editor = non-draft in their section(s)∩publication(s); Editor/Managing/General = all sections within their publication(s); can publish.
- **Vertical specialists (their tool only, no dashboard):** Health Insurance→PKV, WNE Recruiter→WNE, Marketing Manager→Marketing, Restaurant Manager→Restaurant Builder, Venues Manager→Venues, CRM Agent→CRM.

### Assignment model
- `ten_users.publication` and `ten_users.section` are **comma-separated lists**; assigned via multi-select checkboxes in User Management (admin/super only). Editorial roles must have ≥1 publication (client + server validation).
- Article list/dropdowns and the Data Scraper (curate publications + sections) respect the user's assigned publications/sections. Scoping is enforced in `get_articles.php`, `get_article_data.php`, `save_article.php`, and `scraper/lib/scraper_review.php`.

### Landing
- `getLandingUrl()` (config.php): users with `dashboard.view`/admin land on the dashboard; everyone else lands on their first permitted tool. Used by login.php + dashboard.php (replaces the journalist-only redirect).

### Admin control
- Roles & Permissions editor (module-roles.php) lets Admin/Super grant any of the 69 permissions (17 tool groups) to any role — verified.

### Open items for review
- Legacy roles **PKV Broker** and **Viewer** were left as-is (they predate this and Broker is an external user type). Both still hold `dashboard.view`/`system.access`; Viewer also holds `pkv.view`. Decide whether to fold them into the new model.
- Data Scraper enforcement is at the **visible-list** level (publications/sections shown). Deeper per-item AJAX id checks (e.g. curate items by arbitrary pub_section_id) are a possible follow-up hardening.
- Test accounts (password `Journo!Test2026`): test_journalist (News/tme), test_journalist_free (none), test_section_editor (News/tme), test_editor (tme). Remove when done. (Removed 2026-09-07.)

### Addendum 2 (2026-09-07): user invite email + role tutorials
- **Create User** has an optional "Email this user a welcome message with a link to set their password" checkbox (all roles). When ticked, password is optional; `ajax/users.php` creates a 7-day `ten_password_resets` token and emails a welcome via `sendEmail()` with a `reset-password.php?token=` link (reuses the existing reset flow). Editorial users also get a link to `tutorial.php`.
- **tutorial.php** — login-required, role-specific guides (Journalist, Section Editor, Editor = Managing/General Editor); non-editorial roles see a generic message. Linked from Settings/Profile (a "Guide for your role" button, editorial roles only) and from the invite email.
- Verified via impersonation harness: invite checkbox present; each role's tutorial renders without error. Email delivery itself depends on the server's `mail()` (same path as the existing forgot-password flow) — not exercised in testing to avoid sending to placeholder addresses.
