# Post-it notes, page notes and a real notifications bell — design

Date: 2026-09-22. Approved by William in chat.

## Goals
1. Notes look and behave like post-its: title, colour, optional deadline, optional email reminder.
2. A note can be pinned to any module page (project optional). A post-it icon in the top header,
   left of the bell, shows the count of the user's open deadline notes and glows when the current
   page has notes the user can see; clicking it opens a panel for this page's notes + deadlines.
3. The hardcoded notifications bell becomes a live "needs attention" feed.

## Decisions (from William)
- Project is optional on a note (page-only notes allowed).
- Post-it badge = open notes with a deadline that I wrote or that are shared with me, overdue included
  (badge red when any overdue).
- Deadline reminder email goes to the note's author only.
- A note attaches to a module page = the script basename (e.g. `module-scraper.php`), not a sub-tab.

## Data (auto-migrated in `notes_ensure_schema`, add-only)
`ten_project_notes`: `project_id` becomes NULL-able; add `title VARCHAR(120)`, `color VARCHAR(16)`
(yellow|pink|green|blue|orange|purple, default yellow), `deadline DATETIME NULL`,
`remind_before_mins INT NULL`, `reminder_sent_at DATETIME NULL`, `page_key VARCHAR(100) NULL`,
`page_label VARCHAR(120) NULL` + indexes on (page_key,status) and (deadline).
`ten_note_shares`: add `seen_at DATETIME NULL` (drives "shared with you" in the bell).
New `ten_note_reads (note_id, user_id, last_read_at)` (drives "new replies" in the bell).

Rules: title/colour/deadline/reminder/page are editable any time by the author (or admin);
the note body keeps the 30-minute edit window. Changing deadline or reminder clears
`reminder_sent_at`. Sharing a note with no project may target any active user; a project note
keeps the members-only rule.

## Header
- Post-it button (`fa-note-sticky`) left of the bell. Count computed server-side in PHP at page render
  (no flicker). `.has-page-notes` glow when the current page has visible notes.
- Panel: "On this page" post-its, "Deadlines" (overdue first, then soonest), "+ New note on this page".
  Clicking a post-it opens its conversation thread (existing thread modal); owners get Edit.
- Bell: live feed from `lib/notifications_core.php`:
  my overdue tasks, my tasks due today, unread `ten_project_notifications`, notes newly shared with me,
  new replies on notes I'm in, and (scraper users) curated stories waiting today.
  Badge = actionable count (everything except the scraper info line). "Mark all read" clears
  project notifications + note share/reply markers; task items stay until the task is done.

## Reminders
`cron/notes_reminders.php` (token or CLI), every 5 minutes: active notes with deadline + reminder
where `NOW() >= deadline - remind_before_mins` and `reminder_sent_at IS NULL` → `sendEmail()` to the
author, set `reminder_sent_at`. William adds the crontab line.

## Files
- `lib/notes_core.php` (schema + helpers), `ajax/project_notes.php` (new fields, `meta`, `users`,
  `page_notes`, `deadlines`, read tracking), `js/project_notes.js` (post-it cards + editor; exposes
  `openThread`, `openEditor` for the header), `lib/notifications_core.php` + `ajax/notifications.php`,
  `includes/header.php` (buttons, panels, `js/header_notes.js`), `cron/notes_reminders.php`.

## Out of scope
Voice notes (phase 2, unchanged). Pages that don't include `includes/header.php`
(e.g. `module-candidates.php`) get no header icons.
