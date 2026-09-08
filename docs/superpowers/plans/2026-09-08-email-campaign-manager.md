# Email Campaign Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a self-hosted email-campaign manager in TEN Management (Business Tools → Email Campaigns) that imports contacts, sends throttled/batched campaigns via authenticated SMTP with full deliverability hygiene, and tracks opens/clicks/site-visits/replies/bounces with global suppression and never-email-twice dedup.

**Architecture:** New `ten_ec_*` tables in `TEN_Management`. A PHP UI page (`module-email-campaigns.php`) + AJAX endpoints for CRUD. Sending is a per-minute cron worker (`cron/ec_send.php`) that pulls batches from a recipients queue and sends via PHPMailer over authenticated SMTP. Public tracking/unsubscribe endpoints record events. Two IMAP poller crons ingest replies and bounces. Verification is by deploy-to-ten + token-protected harness / SQL probe / browser (no unit-test framework exists).

**Tech Stack:** PHP 8.3, MySQLi, TEN_Management DB, PHPMailer (vendored), PHP `imap` ext, existing module/permission system (`ten_modules`/`ten_permissions`), WinSCP FTPS deploy to theeyenewspapers.com, cron via `/usr/local/bin/php`.

## Global Constraints
- Deploy target for app code: **theeyenewspapers.com only** (`/home/tenuser/public_html/management/`); other pubs are chrooted — not needed here (this is management-only).
- DB: all new tables in **`TEN_Management`**, prefix **`ten_ec_`**.
- Sending: **authenticated SMTP via PHPMailer** (never PHP `mail()`, never a consumer VPN). SMTP host/creds come from `ten_ec_settings` so an external static-IP relay can be swapped in without code change.
- Deliverability headers on every message: multipart HTML+text, `From`, `Reply-To`, `Message-ID`, `Date`, `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, VERP `Return-Path` `bounce+<token>@<domain>`. One recipient per message.
- Suppression + dedup are enforced **at materialise time and again at send time**; an address is never emailed twice.
- Access: module `email_campaigns` (group `bus_tools`), permission **`campaigns.manage`** (Super User/Administrator/Manager); Settings tab additionally admin/super only.
- Temp DB/harness scripts are token-protected and deleted after use. Secrets (SMTP/IMAP passwords) stored encrypted at rest in `ten_ec_settings` (AES via a key in `config.php`).
- Verification pattern per task: deploy changed files via WinSCP, then confirm via (a) a token-protected `_ec_verify.php` impersonation/SQL harness, (b) the browser for UI/endpoints, or (c) direct SQL probe — then delete temp scripts.

---

## File Structure

**New (management/):**
- `module-email-campaigns.php` — the tabbed UI (Dashboard, Contacts, Audiences, Templates, Campaigns, Reports, Settings).
- `ajax/ec_contacts.php` — contacts CRUD + CSV/paste import + suppression add.
- `ajax/ec_audiences.php` — audiences CRUD + membership.
- `ajax/ec_templates.php` — templates CRUD + preview + test-send.
- `ajax/ec_campaigns.php` — campaign CRUD, materialise, launch/pause/resume/cancel, progress JSON, reports JSON.
- `ajax/ec_settings.php` — SMTP/IMAP/warm-up settings (admin/super).
- `lib/ec_core.php` — shared helpers: DB accessors, `ec_slug`, token gen, merge-field render, tracking-link rewrite, suppression checks, secret encrypt/decrypt.
- `lib/ec_mailer.php` — PHPMailer wrapper: `ec_send_message($recipientRow, $campaignRow, $variantRow): array`.
- `lib/PHPMailer/` — vendored PHPMailer (PHPMailer.php, SMTP.php, Exception.php).
- `cron/ec_send.php` — per-minute batched sender worker.
- `cron/ec_imap_poll.php` — reply + bounce IMAP poller.
- `t/o.php`, `t/c.php`, `u.php` — public tracking (open pixel), click redirect, unsubscribe endpoints. (`v.php` visit logger.)
- `_ec_schema.php` — one-shot idempotent schema/seed installer (token-protected; deleted after run).

**Modify:** none of the existing app files except DB seed rows (module/permission) done via `_ec_schema.php`.

---

## Task 1: Schema, module & permission installer

**Files:**
- Create: `management/_ec_schema.php` (temp, token-protected, deleted after run)

**Interfaces:**
- Produces: all `ten_ec_*` tables (see spec §3); `ten_permissions` row `campaigns.manage`; grants to Super User/Administrator/Manager; `ten_modules` row `email_campaigns` (group `bus_tools`, url `/management/module-email-campaigns.php`, icon `fa-paper-plane`, required_permission `campaigns.manage`); one `ten_ec_settings` row (id=1, empty).

- [ ] **Step 1: Write `_ec_schema.php`** — token guard `?t=ec_9x2k7Q`; `CREATE TABLE IF NOT EXISTS` for every table in spec §3 with the exact columns/indexes; `INSERT ... ON DUPLICATE KEY UPDATE`/existence-guarded seeds for permission, grants, module, and the settings singleton. Mirror the column-existence-guarded insert style used in `_ten_ticker_setup.php`.
- [ ] **Step 2: Deploy** `_ec_schema.php` via WinSCP to `/public_html/management/`.
- [ ] **Step 3: Run** `https://theeyenewspapers.com/management/_ec_schema.php?t=ec_9x2k7Q`; expected JSON: every table `created` (or `exists`), permission `created`, grants `granted`×3, module `created`.
- [ ] **Step 4: Verify** the module appears for a Super User via an impersonation probe of the sidebar `content`/`bus_tools` module query (reuse the `_ten_ticker_verify.php` pattern) — `email_campaigns` present.
- [ ] **Step 5:** Delete `_ec_schema.php` from the server.
- [ ] **Step 6: Commit** the (local copy of the) installer under `management/` scaffolding note; commit message `feat(email-campaigns): schema + module/permission installer`.

---

## Task 2: `lib/ec_core.php` shared helpers

**Files:**
- Create: `management/lib/ec_core.php`

**Interfaces:**
- Produces:
  - `ec_db(): mysqli` (TEN_Management).
  - `ec_token(int $len=32): string` (url-safe).
  - `ec_slug(string): string`.
  - `ec_encrypt(string): string` / `ec_decrypt(string): string` (AES-256-GCM using `EC_SECRET_KEY` constant added to config).
  - `ec_render(string $body, array $contact, string $unsubUrl): string` — merge `{{first_name}}`,`{{last_name}}`,`{{company}}`,`{{email}}`,`{{unsubscribe_url}}`.
  - `ec_rewrite_links(string $html, string $token, string $trackBase): string` — rewrite `<a href>` to `t/c.php?r=<token>&u=<enc>` and append open pixel `t/o.php?r=<token>`.
  - `ec_is_suppressed(string $email): bool`.
  - `ec_track_base(): string` (from settings; default `https://theeyenewspapers.com/management`).

- [ ] **Step 1:** Add `EC_SECRET_KEY` (32-byte base64) to `config.php` (or a new `config_ec.php` required by ec_core).
- [ ] **Step 2:** Implement `ec_core.php` with the functions above; each pure/DB-guarded.
- [ ] **Step 3: Verify** via a token-protected `_ec_verify.php` that calls `ec_render`/`ec_rewrite_links`/`ec_encrypt`+`ec_decrypt` round-trip and prints results; confirm merge + link rewrite + encryption round-trip correct.
- [ ] **Step 4:** Delete `_ec_verify.php`.
- [ ] **Step 5: Commit** `feat(email-campaigns): core helpers (render, link-rewrite, crypto, suppression)`.

---

## Task 3: Contacts — import, list, suppression (`ajax/ec_contacts.php` + Contacts tab)

**Files:**
- Create: `management/ajax/ec_contacts.php`
- Modify: `management/module-email-campaigns.php` (Contacts tab; page shell created here first)

**Interfaces:**
- Consumes: `ec_core.php`.
- Produces AJAX actions: `list` (paged/search), `import` (CSV text or pasted rows → upsert into `ten_ec_contacts` by unique email, dedup, count new/updated/skipped-suppressed), `add` (single), `suppress` (email+reason → `ten_ec_suppression` + mark contact), `history` (per-contact events).

- [ ] **Step 1:** Create the page shell `module-email-campaigns.php` (login + `campaigns.manage`/isAdmin guard, sidebar/header include, tab nav, empty tab panes) — mirror `module-site-ticker.php` structure.
- [ ] **Step 2:** Implement `ajax/ec_contacts.php` actions (gated by `campaigns.manage`/isAdmin), parsing CSV with a header row (email required; first_name/last_name/company/phone/city/country/source optional), upserting with `INSERT ... ON DUPLICATE KEY UPDATE`, skipping suppressed, returning counts.
- [ ] **Step 3:** Build the Contacts tab UI: import box (paste + file), search, paged table, per-row suppress, add-contact modal.
- [ ] **Step 4: Deploy** page + ajax + lib.
- [ ] **Step 5: Verify** via impersonation harness: POST `import` with 3 rows (one duplicate, one already-suppressed) → assert new=1/updated=1/skipped=1; `list` returns them; `suppress` moves one to suppression and `import` of it again is skipped. Browser-load the tab as the logged-in admin is a manual check.
- [ ] **Step 6:** Delete harness. **Commit** `feat(email-campaigns): contacts import/list/suppression + page shell`.

---

## Task 4: Audiences (`ajax/ec_audiences.php` + Audiences tab)

**Files:**
- Create: `management/ajax/ec_audiences.php`
- Modify: `management/module-email-campaigns.php` (Audiences tab)

**Interfaces:**
- Consumes: `ec_core.php`, contacts tables.
- Produces actions: `list`, `create` (name/desc), `add_members` (by contact ids or by filter/tags), `remove_member`, `members` (paged), `size` (active minus suppressed).

- [ ] **Step 1:** Implement `ajax/ec_audiences.php` actions.
- [ ] **Step 2:** Audiences tab UI: create list, add contacts (from a filtered contacts picker), show effective size (excluding suppressed).
- [ ] **Step 3: Deploy + verify** via harness: create audience, add 3 members (1 suppressed), `size` returns 2. **Commit** `feat(email-campaigns): audiences`.

---

## Task 5: Vendored PHPMailer + `lib/ec_mailer.php` + Settings tab

**Files:**
- Create: `management/lib/PHPMailer/{PHPMailer.php,SMTP.php,Exception.php}` (v6.x)
- Create: `management/lib/ec_mailer.php`
- Create: `management/ajax/ec_settings.php`
- Modify: `management/module-email-campaigns.php` (Settings tab)

**Interfaces:**
- Consumes: `ec_core.php`, `ten_ec_settings`.
- Produces:
  - `ec_settings(): array` (decrypted).
  - `ec_send_message(array $to /*email,name*/, string $subject, string $html, string $text, array $opts /*from_name,from_email,reply_to,message_id,return_path,list_unsub_url,headers*/): array{ok:bool,message_id:string,error:string}` — configures PHPMailer SMTP from settings, sets all deliverability headers, sends one message.
- Settings actions: `get`, `save` (SMTP host/port/security/user/pass, from/reply defaults, IMAP host/user/pass, warm-up JSON, daily cap, track base) — passwords encrypted; admin/super only.

- [ ] **Step 1:** Vendor PHPMailer 6.x (three files).
- [ ] **Step 2:** Implement `ec_settings.php` (get/save, encrypt secrets) + Settings tab UI (admin/super gated).
- [ ] **Step 3:** Implement `ec_mailer.php::ec_send_message` with full headers (List-Unsubscribe, Message-ID, Return-Path via `Sender`, multipart).
- [ ] **Step 4:** Add a **Send test to me** action (in `ec_templates.php` later; here expose a minimal test in Settings) that calls `ec_send_message` to the logged-in user's email.
- [ ] **Step 5: Deploy.** Enter real SMTP creds in Settings (William provides a sending mailbox). **Verify:** send a test to William's address; confirm receipt + inspect headers (SPF/DKIM pass once DNS done, List-Unsubscribe present). **Commit** `feat(email-campaigns): PHPMailer SMTP sender + settings`.

---

## Task 6: Templates (`ajax/ec_templates.php` + Templates tab)

**Files:**
- Create: `management/ajax/ec_templates.php`
- Modify: `management/module-email-campaigns.php` (Templates tab)

**Interfaces:**
- Consumes: `ec_core.php`, `ec_mailer.php`.
- Produces actions: `list`, `save` (name/subject/from/reply/html/text; validate presence of `{{unsubscribe_url}}`), `delete`, `preview` (render with a sample contact), `test_send` (render + `ec_send_message` to current user).

- [ ] **Step 1:** Implement `ajax/ec_templates.php`; reject save if `{{unsubscribe_url}}` missing from html.
- [ ] **Step 2:** Templates tab UI: HTML + text editors, merge-field helper, live preview, test-send.
- [ ] **Step 3: Deploy + verify:** save a template, preview renders merge fields, test-send arrives. **Commit** `feat(email-campaigns): templates + preview + test-send`.

---

## Task 7: Campaigns — build, materialise, dedup (`ajax/ec_campaigns.php` + Campaigns tab)

**Files:**
- Create: `management/ajax/ec_campaigns.php`
- Modify: `management/module-email-campaigns.php` (Campaigns tab)

**Interfaces:**
- Consumes: audiences, templates, `ec_core.php`.
- Produces actions: `list`, `save` (name/audience/variants/batch_size/batch_interval_min/per_domain_limit/daily_cap/warmup/scheduled_at/ab_enabled), `materialise` (expand audience → `ten_ec_recipients` skipping suppressed + already-recipient; assign variant by weight; unique token; staggered `send_after`), `preview_count` (how many will actually send), `launch` (status→scheduled/sending), `pause`, `resume`, `cancel`.

- [ ] **Step 1:** Implement `ajax/ec_campaigns.php` incl. `materialise` with suppression/dedup and A/B weighting; `preview_count`.
- [ ] **Step 2:** Campaigns wizard UI: audience → variant(s)/AB → throttle/warm-up/schedule → review (shows send count after suppression) → launch/pause/resume/cancel.
- [ ] **Step 3: Deploy + verify** via harness: create campaign over a 3-contact audience (1 suppressed) → materialise → recipients=2, tokens unique, variants assigned; `preview_count`=2. **Commit** `feat(email-campaigns): campaigns + materialise + dedup`.

---

## Task 8: Sender worker cron (`cron/ec_send.php`) + Dashboard progress

**Files:**
- Create: `management/cron/ec_send.php`
- Modify: `management/ajax/ec_campaigns.php` (progress JSON), `management/module-email-campaigns.php` (Dashboard tab)

**Interfaces:**
- Consumes: `ec_mailer.php`, `ec_core.php`, recipients queue.
- Produces: sends due recipients respecting `batch_size`, `batch_interval_min`, `per_domain_limit`, `daily_cap`, warm-up ramp; re-checks suppression at send; renders template + rewrites tracking links + builds unsubscribe url; writes `sent` event + message_id; sets recipient `sent`/`failed`. `progress` action returns live counters.

- [ ] **Step 1:** Implement `cron/ec_send.php` (CLI + token guard for manual run; `ignore_user_abort(true)`, `set_time_limit(0)`; advisory lock so overlapping cron ticks don't double-send — `GET_LOCK`).
- [ ] **Step 2:** Implement `progress` action + Dashboard tab (live counters, progress bars, pause/resume).
- [ ] **Step 3: Deploy.** Manually run `ec_send.php?t=…` once against the Task-7 test campaign with the sender pointed at a safe test inbox. **Verify:** the 2 recipients get `sent`, emails arrive with tracking links + unsubscribe, dashboard shows 2 sent / 0 remaining, and re-running does NOT resend. **Commit** `feat(email-campaigns): sender worker + live dashboard`.
- [ ] **Step 4:** Provide William the crontab line: `* * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_send.php >/dev/null 2>&1`.

---

## Task 9: Tracking endpoints — open, click, unsubscribe (`t/o.php`, `t/c.php`, `u.php`)

**Files:**
- Create: `management/t/o.php`, `management/t/c.php`, `management/u.php`

**Interfaces:**
- Consumes: `ec_core.php`, recipients/events/suppression.
- Produces: `o.php?r=<token>` → log `open` (once), return 1×1 gif; `c.php?r=<token>&u=<enc>` → log `click`, 302 to decoded url (validate it's http/https); `u.php?r=<token>` → add to suppression (`unsubscribe`), mark recipient/contact, show confirmation page; also handle `List-Unsubscribe-Post` one-click POST.

- [ ] **Step 1:** Implement the three endpoints (no login; token-scoped; abuse-safe: only act on known tokens).
- [ ] **Step 2: Deploy + verify** with the Task-8 test recipients: hit the open pixel (opened_at set), click link (click logged + redirects), unsubscribe (suppression + recipient marked); re-send to that contact is now skipped. **Commit** `feat(email-campaigns): open/click/unsubscribe tracking`.

---

## Task 10: Reports tab + per-recipient timeline

**Files:**
- Modify: `management/ajax/ec_campaigns.php` (reports JSON), `management/module-email-campaigns.php` (Reports tab)

**Interfaces:**
- Produces: `report` action → per-campaign funnel (sent/opened/clicked/replied/bounced/unsubscribed + rates), A/B variant comparison; `recipient_timeline` → per-recipient event list.

- [ ] **Step 1:** Implement `report` + `recipient_timeline`.
- [ ] **Step 2:** Reports tab UI: funnel, A/B table, recipient drill-down, CSV export.
- [ ] **Step 3: Deploy + verify** against the test campaign's real events. **Commit** `feat(email-campaigns): reports + A/B comparison`.

---

## Task 11: IMAP pollers — replies + bounces (`cron/ec_imap_poll.php`)

**Files:**
- Create: `management/cron/ec_imap_poll.php`

**Interfaces:**
- Consumes: `ec_settings` (IMAP), recipients/events/suppression.
- Produces: connect to reply + bounce mailboxes; match reply to recipient via VERP `bounce+<token>@`/`In-Reply-To`/original `Message-ID`; mark `replied_at` + log `reply` + suppress that contact (reply = stop); classify bounces hard/soft, hard → suppression `hard_bounce` + recipient `bounced`.

- [ ] **Step 1:** Confirm PHP `imap` ext enabled (probe `function_exists('imap_open')`); if not, William enables it.
- [ ] **Step 2:** Implement `cron/ec_imap_poll.php` (CLI + token; idempotent via a processed-UID marker).
- [ ] **Step 3: Deploy + verify:** reply to a test send from another mailbox → poller marks replied + suppresses; send a message to an invalid address → bounce classified hard + suppressed. **Commit** `feat(email-campaigns): reply + bounce IMAP polling`.
- [ ] **Step 4:** Provide crontab: `*/5 * * * * /usr/local/bin/php -q /home/tenuser/public_html/management/cron/ec_imap_poll.php >/dev/null 2>&1`.

---

## Task 12: Site-visit attribution + warm-up + A/B finalisation

**Files:**
- Create: `management/v.php` (visit logger)
- Modify: `cron/ec_send.php` (warm-up ramp already stubbed → finalise), `ec_core.php` (append `?ec=<token>` + UTM to outbound links), reports.

**Interfaces:**
- Produces: `v.php?ec=<token>` logs a `visit` event; warm-up daily ramp enforced by the sender; A/B split verified end-to-end in reports.

- [ ] **Step 1:** Implement `v.php` + link tagging in `ec_rewrite_links` (add `ec` + UTM params to on-site links).
- [ ] **Step 2:** Finalise warm-up ramp in the sender (configurable schedule; cap daily sends).
- [ ] **Step 3: Deploy + verify:** a tagged link hit logs a visit tied to the recipient; warm-up caps a large campaign's daily sends; A/B report shows per-variant metrics. **Commit** `feat(email-campaigns): site-visit attribution + warm-up + A/B`.

---

## Root / DNS handoff (William — outside code, tracked here)
- SPF (include server), DKIM (generate key + publish TXT + sign in MTA), DMARC (`p=none` → monitor); ideally dedicated subdomain `mail.theeyenewspapers.com`.
- Sending mailbox + reply mailbox + VERP/bounce mailbox with IMAP; put creds in Settings.
- Crons: `ec_send.php` (`* * * * *`), `ec_imap_poll.php` (`*/5 * * * *`) via `/usr/local/bin/php`.
- Enable PHP `imap` extension.

## Self-Review notes
- Spec coverage: contacts/audiences/templates/campaigns/sending/suppression+dedup/unsubscribe/dashboard/open+click/reply+bounce/site-visit/A-B/warm-up/settings/access-control all mapped to Tasks 1–12. ✓
- Adaptation: no unit-test framework → each task verifies via deploy + token harness/browser/SQL (project-standard). ✓
- Type consistency: `ec_send_message` signature used by Tasks 5/6/8 identical; token field `ten_ec_recipients.token` used by Tasks 8/9/12. ✓
