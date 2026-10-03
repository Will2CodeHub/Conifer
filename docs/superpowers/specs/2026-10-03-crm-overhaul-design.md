# CRM Overhaul — Design

**Date:** 2026-10-03
**Branch:** feature/design-system
**Author:** William (wfsmyth) + Claude

## Goal

Turn the cramped, AI-template-looking CRM into a full, professional CRM: a **contacts
manager**, a **leads manager**, and **pipelines**, tied into the Email Campaign Manager so
the CRM knows who was contacted, when, and in what campaign. Also make the global left menu
collapsible so the main pane gets more screen, and restyle the whole CRM to look clean,
professional and non-"AI generated".

Decisions were taken with the user (brainstorming), then the user asked to proceed and deploy
without further questions.

## Decisions

1. **Shared contact database.** CRM Contacts **is** `ten_ec_contacts` (the email module's
   existing universe of ~tens of thousands of real people). One source of truth. No
   duplication, no sync. Campaign history comes for free by joining the `ten_ec_*` tables.
2. **Three surfaces:** Overview, Contacts, Leads, Pipelines. Projects/campaigns hierarchy of
   the old CRM is dropped (it was essentially empty scaffolding).
3. **Email integration = timeline + push to audience.** Each contact shows a read-only email
   timeline (sent / opened / clicked / replied / bounced / unsubscribed / suppressed) pulled
   live from `ten_ec_sent_log`, `ten_ec_recipients`, `ten_ec_responses`, `ten_ec_suppression`.
   One action back into the email tool: push selected leads/contacts into an email audience.
   No campaign authoring inside the CRM.
4. **PKV leads surfaced live.** The PKV broker tool (`ten_pkv_enquiries`, 13-state workflow,
   `ten_pkv_brokers`, history, external-broker gating) stays the system of record. The CRM
   shows it as one **live** pipeline (states = stages), read-only on the board, with each
   enquiry matched to a shared contact so its email timeline appears. External-broker access
   rules are respected server-side. Nothing is migrated or broken.
5. **Collapsible global sidebar.** `includes/sidebar.php` gains a desktop collapse to a 72px
   icon rail (hover to peek the labels), state persisted in `localStorage`. Mobile off-canvas
   behaviour unchanged.
6. **Professional visual system** (CRM only for now; sidebar keeps its dark identity):
   - Typeface **Public Sans** (institutional, neutral) with a system fallback; ~13.5px base.
   - Palette: ink `#1a1d21`, muted `#5b6470`, hairline `#dfe3e8`, app bg `#f4f5f7`,
     panel white, single accent deep navy `#1f4e79`. Muted semantics only in small dots/badges.
   - 3px radius max, no pill shapes, **no gradients**, **no coloured borders**, flat panels
     with hairline borders (no drop shadows except overlays), dense tables.

## Data model (new tables, `ten_crm_` prefix, self-migrating like `ec_ensure_schema`)

- `ten_crm_pipelines` (id, name, slug, description, accent, is_active, display_order,
  created_by, created_at) — manual pipelines. The PKV pipeline is **virtual** (not a row).
- `ten_crm_stages` (id, pipeline_id, name, display_order, is_won, is_lost, created_at).
- `ten_crm_leads` (id, pipeline_id, contact_id → ten_ec_contacts.id, stage_id, owner_id,
  value DECIMAL, status enum open|won|lost|on_hold, note, last_activity_at, created_by,
  created_at, updated_at). UNIQUE (pipeline_id, contact_id).
- `ten_crm_lead_history` (id, lead_id, from_stage_id, to_stage_id, user_id, note, created_at).
- `ten_crm_activities` (id, contact_id, lead_id NULL, user_id, type enum
  note|call|email|meeting|task, subject, body, due_at NULL, done TINYINT, created_at).

Seed one default manual pipeline ("Sales pipeline") with stages New → Contacted → Qualified →
Proposal → Negotiation → Won → Lost on first run.

Old `crm_*` tables are left untouched (unused) to avoid destructive drops.

## Components

- **lib/crm_core.php** — `crm_ensure_schema()`, PKV pipeline definition + live board loader
  (with external-broker gating), per-contact email timeline builder, helpers.
- **ajax/crm.php** — rewritten: overview, contacts_list, contact_get (profile + leads +
  activities + email timeline), contact_save, leads_list, pipelines_list, pipeline_board,
  lead_create/move/update/delete, pipeline_save/delete, stage_save/delete/reorder,
  activity_add/delete, audiences_list, push_to_audience, pkv_enquiry_get, vocab.
- **css/crm.css** — the professional design system.
- **module-crm.php** — rewritten UI: top tabs Overview / Contacts / Leads / Pipelines,
  contact drawer with the email timeline, kanban boards, settings for pipelines/stages.
- **includes/sidebar.php** — collapsible rail.

## Permissions (unchanged)

`crm.view` to load, `crm.manage` to edit. PKV board additionally requires `pkv_view` (or
admin); external brokers (`pkv_external_broker`) see only their assigned enquiries.

## Out of scope

Campaign authoring from the CRM; migrating/retiring the PKV module; drag-to-advance of PKV
states (has side effects — done in the PKV tool); restyling modules other than the CRM.
