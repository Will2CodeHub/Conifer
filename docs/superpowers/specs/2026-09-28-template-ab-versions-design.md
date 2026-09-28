# Email Campaigns — A/B versions on the template (design)

Date: 2026-09-28 · Branch: feature/journalist-role

## Problem
A/B testing didn't alternate. Root cause: variants live on the **campaign** (`ten_ec_campaign_variants`) and are DELETE+re-INSERTed with new ids on every save; existing recipients were then re-pointed to the first variant, collapsing everyone to Version A. The UI (pick-a-template-per-variant) was also confusing, the A/B labels weren't visible, and the per-template unsubscribe toggle wasn't taking effect on live (older code deployed).

## Decision (William)
Move A/B to the **template**: one template holds Version A and Version B, edited via tabs. A campaign that uses an A/B template automatically alternates A/B across recipients.

## Data model
- `ten_ec_templates` (idempotent ALTERs in `ec_ensure_schema`):
  - `ab_enabled TINYINT(1) NOT NULL DEFAULT 0`
  - `subject_b VARCHAR(500) NULL`, `html_body_b MEDIUMTEXT NULL`, `text_body_b MEDIUMTEXT NULL`
  - A/B differ by **subject + body only**. Shared across versions: name, format (`is_plain`), `require_unsub`, from/reply (profile-driven).
- `ten_ec_recipients.ab CHAR(1) NOT NULL DEFAULT 'A'` — the version tag. Assigned once at materialise; never changes on template re-save (this is what fixes the alternation bug).
- `ten_ec_campaigns.ab_enabled` (already exists) = "A/B test this send" opt-in; only meaningful when the chosen template is `ab_enabled`.

## Behaviour
- **Template editor:** an "A/B test this template" checkbox; when on, a **Version A / Version B** tab bar swaps the single shared editor (subject + body) between two JS buffers. `tPayload` flushes the active tab then sends both versions + `ab_enabled`. Weights dropped (50/50). Preview and Send-test get an A/B selector (client sends the chosen version's fields under the existing keys — no change to the preview/test_send endpoints).
- **Unsubscribe:** the existing per-template `require_unsub` toggle governs it; when on, **both active versions** must contain `{{unsubscribe_url}}`. (Redeploy so live matches repo — that's the "still demands it" bug.)
- **Campaign editor:** the multi-row "+ Add A/B variant" picker is removed. A campaign picks **one template** (still stored as a single `ten_ec_campaign_variants` row so sender/report joins barely change). When that template is `ab_enabled`, an "A/B test this send" checkbox appears (default on).
- **Materialise:** assign `recipients.ab` round-robin A,B,A,B when the (single) template is `ab_enabled` and the campaign opted in; otherwise all `'A'`.
- **Sender (`ec_send.php`):** after loading the template via the recipient's variant, if `ab_enabled` and `recipient.ab='B'`, use `subject_b/html_body_b/text_body_b` (falling back to A where a B field is empty); else Version A.
- **Report:** the A/B table groups by `recipients.ab` → "Version A" / "Version B" with recipients / sent / opened / clicked / replied.

## Back-compat
Existing older multi-variant campaigns keep their variant rows and send unchanged (their recipients are `ab='A'`); no destructive migration. Their report collapses to a single "Version A" row — acceptable, they're historical.

## Out of scope (YAGNI)
More than two versions, per-version weights, per-version format, auto-winner selection.

## Files
`lib/ec_core.php` (schema), `ajax/ec_templates.php` (save/list), `cron/ec_send.php` (version pick), `ajax/ec_campaigns.php` (materialise/report), `module-email-campaigns.php` (template A/B tabs, campaign single-template + A/B checkbox, preview/test selector, report labels). Deploy via plain FTP; schema self-migrates on first authed load.
