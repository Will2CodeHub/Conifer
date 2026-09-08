# TEN Email Campaign Manager — Design Spec

**Date:** 2026-09-08
**Location:** TEN Management → *TEN Business Tools* → **Email Campaigns** (`module-email-campaigns.php`)
**Status:** Draft for review

## 1. Purpose & scope

A complete, self-hosted email‑campaign manager for outbound outreach (initially German insurance brokers, later restaurants/cafés and others). It ingests contact lists (CSV/paste now; the future **Broker** and **Restaurants/Cafés** scrapers write into the same contacts store), builds templates, and sends **throttled, batched** campaigns with full deliverability hygiene, suppression/dedup, and tracking (opens, clicks, site‑visits, replies, bounces, unsubscribes).

**Decisions locked (2026-09-08):**
- **Sending engine:** the server's own SMTP, authenticated, with **SPF + DKIM + DMARC** (root setup). No third‑party ESP.
- **Scope:** full build in one project — batched sending, suppression/dedup, unsubscribe, live progress, **A/B testing**, **warm‑up**, and tracking of opens/clicks/**site‑visits**/**replies**/bounces.
- **Reply tracking:** poll a dedicated mailbox over **IMAP**.

Out of scope (separate later projects): the Broker scraper and the Restaurants/Cafés scraper. This tool exposes a clean `ten_ec_contacts` table they will write into.

## 2. Compliance guardrails (built in)
- Every send requires a template with a working **one‑click unsubscribe** (tokenised) + `List-Unsubscribe` / `List-Unsubscribe-Post` headers.
- **Global suppression list** (unsubscribed, do‑not‑contact, hard‑bounced, complained) is checked before every send; suppressed = never sent.
- **Global contact ledger**: an address is recorded the moment it's queued/sent; the sender refuses to queue an address that is suppressed or already contacted by the same campaign — so **no address is emailed twice**.
- Contacts store **source** + **consent basis** + first‑seen timestamp (for GDPR/UWG record‑keeping). Operator confirms lawful basis for cold outreach; the tool enforces the mechanics.

## 3. Data model (new tables in `TEN_Management`, prefix `ten_ec_`)
- **ten_ec_contacts** — `id, email (UNIQUE), first_name, last_name, company, phone, city, country, source ('csv'|'broker_scraper'|'cafe_scraper'|'manual'), source_ref, consent_basis, tags, status ('active'|'suppressed'|'bounced'), created_at, updated_at`.
- **ten_ec_audiences** — a named list (`id, name, description, created_by, created_at`).
- **ten_ec_audience_members** — `audience_id, contact_id` (M:N; UNIQUE pair).
- **ten_ec_templates** — `id, name, subject, from_name, from_email, reply_to, html_body, text_body, created_by, updated_at`. Merge fields `{{first_name}}`, `{{company}}`, `{{unsubscribe_url}}`, etc.
- **ten_ec_campaigns** — `id, name, audience_id, status ('draft'|'scheduled'|'sending'|'paused'|'completed'|'cancelled'), from_name, from_email, reply_to, batch_size, batch_interval_min, per_domain_limit, daily_cap, warmup_enabled, scheduled_at, ab_enabled, created_by, created_at, started_at, completed_at`.
- **ten_ec_campaign_variants** — A/B variants: `id, campaign_id, label ('A'|'B'|…), template_id, subject_override, weight`. Non‑A/B campaigns have one variant.
- **ten_ec_recipients** — the per‑recipient queue + state: `id, campaign_id, contact_id, variant_id, token (UNIQUE, for tracking/unsub), status ('queued'|'sending'|'sent'|'failed'|'bounced'|'skipped'), send_after, sent_at, message_id, error, opened_at, first_click_at, replied_at, visited_at, unsubscribed_at, bounce_type`. UNIQUE(campaign_id, contact_id).
- **ten_ec_events** — append‑only event log: `id, recipient_id, type ('sent'|'open'|'click'|'visit'|'reply'|'bounce'|'unsubscribe'|'complaint'), url, ip, user_agent, meta, created_at`.
- **ten_ec_suppression** — `email (UNIQUE), reason ('unsubscribe'|'do_not_contact'|'hard_bounce'|'complaint'), source_campaign_id, created_at`.
- **ten_ec_settings** — singleton config: SMTP host/port/user/pass(encrypted)/security, default from/reply, bounce/reply IMAP host/user/pass, warm‑up schedule JSON, global daily cap, tracking base URL.

## 4. Sending pipeline
1. **Build** — operator creates a campaign (audience + variant(s) + batch size + interval + per‑domain limit + daily cap + warm‑up + schedule).
2. **Materialise** — on launch, expand the audience into `ten_ec_recipients`, **skipping** anyone in suppression or already a recipient of this campaign; assign A/B variants by weight; assign a unique `token`; set `send_after` staggered by the throttle plan.
3. **Worker cron** (`cron/ec_send.php`, every minute, self‑limiting, `ignore_user_abort`) — pulls up to `batch_size` recipients whose `send_after <= now`, respecting `per_domain_limit`, `daily_cap`, and the warm‑up ramp; re‑checks suppression at send time; renders template (merge fields + tracking rewrites); sends via **PHPMailer over authenticated SMTP**; writes `sent` event, `message_id`, and updates progress. Failures → `failed` with error; retriable vs permanent distinguished.
4. **Headers/deliverability** — multipart HTML+text; `From`/`Reply-To`/`Message-ID`/`Date`; `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; VERP `Return-Path` (`bounce+<token>@<domain>`) so bounces are attributable; one recipient per message (no bulk To/CC).
5. **Warm‑up** — a configurable ramp (e.g. day1 50, day2 100, … capped) enforced by the worker.

## 5. Tracking (public endpoints, served on theeyenewspapers.com)
- **Open** — `t/o/<token>.gif` → log `open`, return 1×1 pixel.
- **Click** — `t/c/<token>?u=<encoded_url>` → log `click`, 302 to the real URL. All links in the HTML are rewritten to this at send time.
- **Site‑visit** — outbound links carry `?ec=<token>` (+ UTM); a tiny snippet on the TEN sites logs the visit against the recipient (attribution beyond the initial click).
- **Unsubscribe** — `u/<token>` → add to suppression, mark recipient, show a confirmation page. Honoured by `List-Unsubscribe` too.
- **Reply** — `cron/ec_imap_poll.php` polls the reply mailbox over IMAP; matches messages to recipients via VERP/`In-Reply-To`/`Message-ID`; marks `replied_at`, logs `reply`, and (a reply = stop) suppresses further sends to that contact.
- **Bounce** — the same/parallel IMAP poll reads the VERP bounce mailbox, classifies hard vs soft; hard → suppression (`hard_bounce`).

## 6. UI (`module-email-campaigns.php`, tabbed)
- **Dashboard** — active campaigns with live counters (queued / sent / remaining / opened / clicked / replied / bounced / unsubscribed), progress bars, pause/resume.
- **Contacts** — browse/search/import (CSV + paste), tag, view per‑contact history, manual add to suppression.
- **Audiences** — build lists from contacts (filters/tags), see size (minus suppressed).
- **Templates** — editor (HTML + text), merge fields, live preview, mandatory unsubscribe token, send‑test‑to‑me.
- **Campaigns** — wizard: audience → variant(s)/A‑B → throttle/warm‑up/schedule → review (shows how many will actually send after suppression/dedup) → launch.
- **Reports** — per‑campaign funnel + A/B comparison; per‑recipient timeline; export.
- **Settings** — SMTP + IMAP config, from/reply defaults, warm‑up schedule, global daily cap (admin only).

## 7. Access control
- New module `email_campaigns` in group `bus_tools`; new permission **`campaigns.manage`** granted to Super User / Administrator / Manager (+ a Marketing role if desired). Settings tab additionally gated to admin/super.

## 8. Infra / deployment
- App + AJAX + cron under `/management/` (deployable to theeyenewspapers.com by us). Public tracking/unsub endpoints under `/management/t/…`, `/management/u/…` (or a short public path).
- **Root tasks (William):** (a) DNS **SPF** (include the server), **DKIM** (generate key, publish TXT, sign in the MTA), **DMARC** (`p=none` to start, monitor); ideally a **dedicated sending subdomain** (e.g. `mail.theeyenewspapers.com`) to isolate reputation; (b) a sending mailbox + a reply mailbox + a VERP/bounce mailbox with IMAP access; (c) cron entries for `ec_send.php` (*/1) and `ec_imap_poll.php` (*/5) via `/usr/local/bin/php`.
- PHPMailer (vendored) for SMTP; PHP `imap` extension for polling (confirm it's enabled).

## 8a. Sending IP / VPN policy (decided 2026-09-08)
**Mail delivery must NOT go through a consumer VPN (NordVPN etc.).** Doing so wrecks deliverability: SPF authorises the *connecting* IP, so a VPN exit IP not in the domain's SPF → SPF fail → spam; VPN exit IPs are shared and frequently blocklisted; no rDNS/PTR can be set to match the domain; VPN/residential ranges are distrusted for port‑25 sending. DKIM (domain‑signed) survives but SPF/alignment fails.

Correct options for the delivery hop:
- **Default:** the server's own static IP with SPF + DKIM + DMARC + rDNS + warm‑up.
- **Optional dedicated relay:** point the Settings SMTP host/port/user/pass at an **external static‑IP SMTP relay** (e.g. a small German VPS the operator controls) to send from a separate/German IP and isolate the news sites' reputation. This is a relay, not a VPN. No code change needed — the SMTP config already accepts any host.

**VPN/proxy egress belongs to the *scraper* layer** (Broker / Restaurants‑Cafés scrapers hitting Google Maps), matching the existing news scraper's VPN egress — not the mail sender. Recorded here so the scraper projects factor it in.

## 9. Build order (phases within this one project)
1. DB schema + module/permission + Contacts (CSV/paste import) + suppression.
2. Templates + test‑send + SMTP settings/PHPMailer.
3. Campaigns + materialise + worker cron (batched/throttled) + progress dashboard + dedup.
4. Tracking endpoints (open/click/unsubscribe) + reports.
5. IMAP pollers (reply + bounce) + site‑visit attribution.
6. A/B + warm‑up.

## 10. Open questions / risks
- **Volume vs shared IP:** the server's IP reputation is shared with the news sites; heavy cold volume could hurt all mail. The dedicated subdomain + DKIM + conservative warm‑up mitigate this; a separate IP/relay may be needed at scale.
- **PHP `imap` extension** must be enabled (verify).
- **Legal basis** for cold German B2B outreach is the operator's responsibility; the tool provides the compliance mechanics.
