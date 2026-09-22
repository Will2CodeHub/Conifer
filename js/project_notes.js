/**
 * Notes — post-it UI (text notes; voice is a planned follow-up).
 *
 * Notes look like post-its: title, colour, optional deadline + email reminder, optionally pinned
 * to a module page and/or a project. Each note has a WhatsApp-style conversation thread.
 * Talks to ajax/project_notes.php.
 *
 * window.ProjectNotes:
 *   load(projectId)        — render the Notes tab of a project's detail modal
 *   openThread(noteId)     — open a note's conversation (used by the header panel + bell)
 *   openEditor(opts)       — create/edit dialog; opts {projectId, pageKey, pageLabel, note}
 *   renderCard(note, opts) — post-it card HTML;  bindCards(container, onChange)
 * Any change fires a "ten-notes-changed" event on document so the header badges refresh.
 */
(function () {
    'use strict';
    if (window.ProjectNotes) return; // header + project page may both include this file

    var ENDPOINT = '/management/ajax/project_notes.php';
    var COLORS = {
        yellow: { bg: '#fff59d', edge: '#f9e04b', ink: '#5b4a00' },
        pink:   { bg: '#f8bbd0', edge: '#f48fb1', ink: '#6d1b3b' },
        green:  { bg: '#c5e1a5', edge: '#9ccc65', ink: '#2e4a12' },
        blue:   { bg: '#b3e5fc', edge: '#4fc3f7', ink: '#0b4561' },
        orange: { bg: '#ffcc80', edge: '#ffa726', ink: '#6a3a00' },
        purple: { bg: '#d1c4e9', edge: '#9575cd', ink: '#3a2466' }
    };
    // Reminder presets (minutes before the deadline; 0 = at the deadline) and repeat presets.
    var REMIND = [[0, 'At the deadline'], [15, '15 minutes before'], [60, '1 hour before'], [180, '3 hours before'],
                  [1440, '1 day before'], [2880, '2 days before'], [10080, '1 week before']];
    var REPEAT = [[60, 'Every hour'], [180, 'Every 3 hours'], [1440, 'Every day'], [10080, 'Every week']];
    var UNITS = [[1, 'minutes'], [60, 'hours'], [1440, 'days']];

    /** Split minutes into the largest whole unit: 2880 -> {n:2, unit:1440}. */
    function splitMins(m) {
        if (m > 0 && m % 1440 === 0) return { n: m / 1440, unit: 1440 };
        if (m > 0 && m % 60 === 0) return { n: m / 60, unit: 60 };
        return { n: m, unit: 1 };
    }
    function fmtMins(m) {
        var s = splitMins(m), u = { 1: 'minute', 60: 'hour', 1440: 'day' }[s.unit];
        return s.n + ' ' + u + (s.n === 1 ? '' : 's');
    }
    function reminderSummary(n) {
        if (n.remind_before_mins === null || n.remind_before_mins === undefined) return '';
        var t = n.remind_before_mins === 0 ? 'Reminder at the deadline' : 'Reminder ' + fmtMins(n.remind_before_mins) + ' before';
        if (n.remind_repeat_mins) t += ', then every ' + fmtMins(n.remind_repeat_mins) + ' until Done';
        t += ' (' + ({ both: 'email + bell', email: 'email', bell: 'bell' }[n.remind_via || 'both']) + ')';
        return t;
    }
    var state = { projectId: null, members: [], openNoteId: null, view: 'open' };

    // Status lifecycle (mirrors lib/notes_core.php). Live = reminders fire + counted in the badge.
    var STATUS = {
        active:      { label: 'Open',        icon: 'fa-circle' },
        in_progress: { label: 'In progress', icon: 'fa-spinner' },
        on_hold:     { label: 'On hold',     icon: 'fa-pause' },
        completed:   { label: 'Completed',   icon: 'fa-circle-check' },
        archived:    { label: 'Archived',    icon: 'fa-box-archive' },
        deleted:     { label: 'Deleted',     icon: 'fa-trash-can' }
    };
    function isLive(st) { return st === 'active' || st === 'in_progress'; }
    function canTo(n, to) { return (n.can_status || []).indexOf(to) !== -1; }
    /** Menu wording for a status move, which depends on where the note is coming from. */
    function moveLabel(from, to) {
        if (to === 'active') {
            if (from === 'deleted') return '<i class="fas fa-rotate-left"></i> Restore';
            if (from === 'completed' || from === 'archived') return '<i class="fas fa-rotate-left"></i> Reopen';
            if (from === 'on_hold') return '<i class="fas fa-play"></i> Resume (reminders back on)';
            return '<i class="far fa-circle"></i> Mark as open';
        }
        return {
            in_progress: '<i class="fas fa-spinner"></i> Mark in progress',
            on_hold: '<i class="fas fa-pause"></i> Put on hold (pause reminders)',
            completed: '<i class="fas fa-circle-check"></i> Mark completed',
            archived: '<i class="fas fa-box-archive"></i> Archive',
            deleted: '<i class="fas fa-trash-can"></i> Delete'
        }[to];
    }

    function api(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body, credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }
    function changed() { document.dispatchEvent(new CustomEvent('ten-notes-changed')); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }
    function parseDt(s) { if (!s) return null; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? null : d; }

    function fmtTime(iso) {
        var d = parseDt(iso);
        if (!d) return esc(iso || '');
        var now = new Date();
        var opts = { hour: '2-digit', minute: '2-digit' };
        if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], opts);
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString([], opts);
    }

    /** Deadline chip: red overdue, amber within 24h, green later. */
    function deadlineChip(n) {
        if (n.status === 'completed') {
            return '<span class="pn-due done" title="' + esc(n.completed_by_name ? 'Completed by ' + n.completed_by_name : 'Completed') + '">' +
                '<i class="fas fa-check"></i> Completed ' + esc(n.completed_at ? fmtTime(n.completed_at) : '') + '</span>';
        }
        var d = parseDt(n.deadline);
        if (!d) return '';
        if (!isLive(n.status)) {
            // Paused/filed notes: show the date without the urgency colours or reminder icon.
            return '<span class="pn-due muted"><i class="fas fa-clock"></i> Due ' + esc(fmtTime(n.deadline)) + '</span>';
        }
        var ms = d - new Date();
        var cls = ms < 0 ? 'late' : (ms < 86400000 ? 'soon' : 'ok');
        var label;
        if (ms < 0) {
            var h = Math.round(-ms / 3600000);
            label = 'Overdue ' + (h < 24 ? h + 'h' : Math.round(h / 24) + 'd');
        } else {
            label = 'Due ' + fmtTime(n.deadline);
        }
        return '<span class="pn-due ' + cls + '" title="' + esc(d.toLocaleString()) + '"><i class="fas fa-clock"></i> ' + esc(label) +
            (n.remind_before_mins !== null && n.remind_before_mins !== undefined
                ? ' <i class="fas ' + (n.remind_repeat_mins ? 'fa-repeat' : 'fa-bell') + '" title="' + esc(reminderSummary(n)) + '"></i>' : '') + '</span>';
    }

    /** "2026-09-25 14:30:00" → value for <input type=datetime-local>. */
    function toLocalInput(s) { return s ? String(s).replace(' ', 'T').slice(0, 16) : ''; }

    function avatarHtml(url, name, size) {
        size = size || 34;
        var st = 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex:0 0 auto;object-fit:cover;';
        if (url) return '<img src="' + esc(url) + '" alt="' + esc(name) + '" style="' + st + '">';
        var initials = (name || '?').trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
        return '<div style="' + st + 'background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:' + (size * 0.4) + 'px;font-weight:600;">' + esc(initials) + '</div>';
    }

    function injectCss() {
        if (document.getElementById('ten-notes-css')) return;
        if (!document.getElementById('ten-notes-font')) {
            var f = document.createElement('link');
            f.id = 'ten-notes-font'; f.rel = 'stylesheet';
            f.href = 'https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&display=swap';
            document.head.appendChild(f);
        }
        var css = document.createElement('style');
        css.id = 'ten-notes-css';
        css.textContent = [
            '.pn-wrap{max-width:980px;margin:0 auto;}',
            '.pn-toolbar{display:flex;align-items:center;gap:12px;margin:0 4px 18px;flex-wrap:wrap;}',
            '.pn-toolbar h4{margin:0;font-size:16px;color:#111827;flex:1;}',
            '.pn-btn{background:#4f46e5;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}',
            '.pn-btn:disabled{opacity:.6;cursor:not-allowed;}',
            '.pn-btn-light{background:#fff;color:#374151;border:1px solid #d1d5db;padding:7px 12px;border-radius:8px;font-size:13px;cursor:pointer;}',
            /* post-it grid + card */
            '.pn-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:22px;padding:6px 4px 10px;}',
            '.pn-postit{position:relative;background:var(--pn-bg);color:var(--pn-ink);min-height:170px;padding:16px 16px 12px;border-radius:2px 2px 4px 22px;',
            '  box-shadow:0 1px 1px rgba(0,0,0,.08),0 8px 18px -6px rgba(0,0,0,.28);display:flex;flex-direction:column;transform:rotate(var(--pn-tilt,0deg));',
            '  transition:transform .15s ease,box-shadow .15s ease;cursor:pointer;}',
            '.pn-postit:hover{transform:rotate(0deg) translateY(-3px);box-shadow:0 2px 2px rgba(0,0,0,.08),0 14px 26px -8px rgba(0,0,0,.35);z-index:2;}',
            '.pn-postit::before{content:"";position:absolute;top:-9px;left:50%;width:74px;height:20px;margin-left:-37px;background:rgba(255,255,255,.55);',
            '  box-shadow:0 1px 2px rgba(0,0,0,.12);transform:rotate(-2deg);}',
            '.pn-postit::after{content:"";position:absolute;left:0;bottom:0;width:22px;height:22px;background:linear-gradient(45deg,rgba(0,0,0,.12) 50%,var(--pn-edge) 50%);border-radius:0 0 0 22px;}',
            '.pn-postit.archived{opacity:.6;filter:saturate(.6);}',
            '.pn-postit-title{font-family:"Caveat",cursive;font-size:25px;line-height:1.05;font-weight:700;margin:0 26px 6px 0;word-break:break-word;}',
            '.pn-postit-body{font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;flex:1;max-height:150px;overflow:hidden;',
            '  -webkit-mask-image:linear-gradient(#000 80%,transparent);mask-image:linear-gradient(#000 80%,transparent);}',
            '.pn-postit-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;}',
            '.pn-tag{font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:999px;background:rgba(255,255,255,.55);}',
            '.pn-due{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;display:inline-flex;align-items:center;gap:4px;}',
            '.pn-due.ok{background:#dcfce7;color:#166534;}.pn-due.soon{background:#fef3c7;color:#92400e;}.pn-due.late{background:#dc2626;color:#fff;}',
            '.pn-due.done{background:#16a34a;color:#fff;}.pn-due.muted{background:rgba(255,255,255,.55);color:inherit;opacity:.8;}',
            '.pn-tag.st{background:rgba(0,0,0,.72);color:#fff;}',
            '.pn-check{position:absolute;top:10px;left:10px;width:22px;height:22px;border-radius:50%;border:2px solid currentColor;background:rgba(255,255,255,.45);',
            '  color:inherit;opacity:.55;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;padding:0;z-index:3;}',
            '.pn-check i{opacity:0;}.pn-check:hover{opacity:1;}.pn-check:hover i{opacity:.6;}',
            '.pn-check.on{background:#16a34a;border-color:#16a34a;color:#fff;opacity:1;}.pn-check.on i{opacity:1;}',
            '.pn-postit.has-check .pn-postit-title{margin-left:28px;}',
            '.pn-postit.st-completed .pn-postit-title{text-decoration:line-through;text-decoration-thickness:2px;opacity:.7;}',
            '.pn-postit.st-completed .pn-postit-body{opacity:.6;}',
            '.pn-postit.st-on_hold{filter:saturate(.45);}',
            '.pn-postit.st-archived,.pn-postit.st-deleted{opacity:.62;filter:grayscale(.5);}',
            '.pn-menu-list.pn-float{position:fixed;right:auto;z-index:7100;}',
            '.pn-menu-list hr{border:none;border-top:1px solid #f1f5f9;margin:4px 0;}',
            '.pn-menu-list button i{width:18px;color:#9ca3af;}',
            '.pn-menu-list button.danger i{color:#dc2626;}',
            '.pn-views{display:flex;gap:6px;flex-wrap:wrap;margin:0 4px 16px;}',
            '.pn-view{border:1px solid #e5e7eb;background:#fff;color:#374151;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:600;cursor:pointer;}',
            '.pn-view span{color:#9ca3af;font-weight:700;margin-left:2px;}',
            '.pn-view.on{background:#111827;border-color:#111827;color:#fff;}.pn-view.on span{color:#d1d5db;}',
            '.pn-event{align-self:center;background:rgba(255,255,255,.7);color:#4b5563;font-size:12px;padding:4px 12px;border-radius:999px;box-shadow:0 1px 1px rgba(0,0,0,.05);}',
            '.pn-thread-status{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.75;}',
            '.pn-postit-foot{display:flex;align-items:center;gap:7px;margin-top:10px;padding-left:14px;font-size:11.5px;opacity:.85;}',
            '.pn-postit-foot .pn-replies{margin-left:auto;font-weight:700;}',
            '.pn-menu{position:absolute;top:8px;right:6px;}',
            '.pn-menu-btn{background:none;border:none;color:inherit;opacity:.55;font-size:18px;cursor:pointer;padding:0 6px;border-radius:6px;line-height:1.2;}',
            '.pn-menu-btn:hover{opacity:1;background:rgba(0,0,0,.08);}',
            '.pn-menu-list{position:absolute;right:0;top:100%;background:#fff;color:#374151;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:220px;z-index:30;padding:5px 0;display:none;}',
            '.pn-menu-list.open{display:block;}',
            '.pn-menu-list button{display:block;width:100%;text-align:left;background:none;border:none;padding:9px 14px;font-size:13px;color:#374151;cursor:pointer;}',
            '.pn-menu-list button:hover{background:#f9fafb;}',
            '.pn-menu-list button.danger{color:#dc2626;}',
            '.pn-empty{text-align:center;color:#9ca3af;padding:36px;}',
            /* editor */
            '.pn-ed{text-align:left;}',
            '.pn-ed-paper{background:var(--pn-bg);border-radius:4px 4px 4px 20px;padding:14px 16px;box-shadow:0 6px 16px -6px rgba(0,0,0,.3);transition:background .15s;}',
            '.pn-ed-title{width:100%;border:none;background:transparent;font-family:"Caveat",cursive;font-size:28px;font-weight:700;color:#1f2937;outline:none;padding:0 0 4px;box-sizing:border-box;}',
            '.pn-ed-body{width:100%;min-height:110px;border:none;background:rgba(255,255,255,.35);border-radius:6px;padding:8px 10px;font:inherit;font-size:14px;resize:vertical;box-sizing:border-box;outline:none;}',
            '.pn-ed-body:disabled{opacity:.75;}',
            '.pn-swatches{display:flex;gap:8px;margin:12px 0 4px;}',
            '.pn-sw{width:26px;height:26px;border-radius:50%;border:2px solid rgba(0,0,0,.12);cursor:pointer;padding:0;}',
            '.pn-sw.on{outline:3px solid #4f46e5;outline-offset:2px;}',
            '.pn-ed-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-top:14px;}',
            '.pn-ed-grid label{display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:4px;}',
            '.pn-ed-grid input,.pn-ed-grid select{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;box-sizing:border-box;}',
            '.pn-ed-row{margin-top:12px;font-size:13px;color:#374151;}',
            '.pn-ed-row label{cursor:pointer;margin-right:12px;}',
            '.pn-share-box{margin-top:8px;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;max-height:170px;overflow:auto;}',
            '.pn-share-row{display:flex;align-items:center;gap:10px;padding:4px 0;font-size:13px;}',
            '.pn-share-row select{margin-left:auto;padding:3px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;}',
            '.pn-remind{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-top:12px;padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;}',
            '.pn-remind.off{opacity:.55;}',
            '.pn-remind label{display:block;font-size:12px;font-weight:600;color:#6b7280;margin-bottom:4px;}',
            '.pn-remind select,.pn-remind input{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;box-sizing:border-box;}',
            '.pn-custom{display:none;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;color:#6b7280;}',
            '.pn-custom input{width:70px !important;flex:0 0 auto;}',
            '.pn-custom select{flex:1;}',
            '.pn-remind-hint{grid-column:1/-1;font-size:11.5px;color:#6b7280;}',
            '.pn-remind-hint:empty{display:none;}',
            '@media (max-width:560px){.pn-ed-grid,.pn-remind{grid-template-columns:1fr;}}',
            /* conversation modal */
            '.pn-thread-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:6500;display:none;align-items:center;justify-content:center;padding:20px;}',
            '.pn-thread-overlay.open{display:flex;}',
            '.pn-thread{background:#efeae2;width:100%;max-width:560px;height:80vh;max-height:760px;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);}',
            '.pn-thread-head{background:#4f46e5;color:#fff;padding:14px 18px;display:flex;align-items:center;gap:10px;}',
            '.pn-thread-head h3{margin:0;font-size:15px;font-weight:600;flex:1;}',
            '.pn-thread-close{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;}',
            '.pn-thread-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;}',
            '.pn-msg{max-width:78%;padding:8px 11px;border-radius:10px;font-size:14px;line-height:1.45;box-shadow:0 1px 1px rgba(0,0,0,.08);position:relative;word-break:break-word;}',
            '.pn-msg.them{align-self:flex-start;background:#fff;}',
            '.pn-msg.me{align-self:flex-end;background:#d9fdd3;}',
            '.pn-msg-author{font-size:12px;font-weight:700;color:#4f46e5;margin-bottom:2px;}',
            '.pn-msg-meta{font-size:10.5px;color:#6b7280;margin-top:3px;text-align:right;}',
            '.pn-msg-actions{margin-top:3px;text-align:right;}',
            '.pn-msg-actions button{background:none;border:none;color:#6b7280;font-size:11px;cursor:pointer;padding:1px 4px;}',
            '.pn-msg-actions button:hover{color:#111827;text-decoration:underline;}',
            '.pn-thread-foot{padding:10px 12px;background:#f0f2f5;display:flex;gap:8px;align-items:flex-end;}',
            '.pn-thread-foot textarea{flex:1;border:1px solid #d1d5db;border-radius:20px;padding:9px 14px;font:inherit;font-size:14px;resize:none;max-height:120px;box-sizing:border-box;}',
            '.pn-send{background:#4f46e5;color:#fff;border:none;width:42px;height:42px;border-radius:50%;font-size:16px;cursor:pointer;flex:0 0 auto;}',
            '.pn-thread-note{background:var(--pn-bg,#fff);border-radius:4px 4px 4px 18px;padding:12px 14px;align-self:stretch;max-width:100%;box-shadow:0 4px 10px -4px rgba(0,0,0,.25);}',
            '.pn-thread-note .pn-postit-title{font-size:24px;margin-right:0;}',
            '.pn-readonly{padding:10px;text-align:center;color:#6b7280;font-size:12.5px;}',
            '.swal2-container.pn-swal{z-index:7000;}'
        ].join('\n');
        document.head.appendChild(css);
    }

    function colorVars(c) {
        var k = COLORS[c] || COLORS.yellow;
        return '--pn-bg:' + k.bg + ';--pn-edge:' + k.edge + ';--pn-ink:' + k.ink + ';';
    }
    // Stable, gentle tilt per note so the board looks hand-placed.
    function tilt(id) { return ((id * 37) % 7 - 3) * 0.6; }

    // ── Card ─────────────────────────────────────────────────────────────────
    /** opts.showPlace: show the project/page tag (header panel), opts.compact: smaller body */
    function renderCard(n, opts) {
        opts = opts || {};
        var st = n.status || 'active';
        var items = [];
        if (n.can_manage && st !== 'deleted') items.push('<button data-act="edit" data-id="' + n.id + '"><i class="fas fa-pen"></i> Edit post-it…</button>');
        ['completed', 'in_progress', 'active', 'on_hold'].forEach(function (to) {
            if (canTo(n, to)) items.push('<button data-act="status" data-to="' + to + '" data-id="' + n.id + '">' + moveLabel(st, to) + '</button>');
        });
        if (n.can_manage && isLive(st) && n.remind_before_mins !== null && n.remind_before_mins !== undefined) {
            items.push('<button data-act="snooze" data-id="' + n.id + '"><i class="fas fa-bell-slash"></i> Snooze reminder…</button>');
        }
        if (n.can_manage && st !== 'deleted') {
            items.push('<hr>');
            items.push('<button data-act="share" data-id="' + n.id + '"><i class="fas fa-user-group"></i> Share…</button>');
            if (n.project_id) items.push('<button data-act="attach" data-id="' + n.id + '"><i class="fas fa-link"></i> Attach to task…</button>');
            items.push('<button data-act="move" data-id="' + n.id + '"><i class="fas fa-diagram-project"></i> ' + (n.project_id ? 'Move to project…' : 'Add to a project…') + '</button>');
        }
        if (canTo(n, 'archived') || canTo(n, 'deleted')) items.push('<hr>');
        if (canTo(n, 'archived')) items.push('<button data-act="status" data-to="archived" data-id="' + n.id + '">' + moveLabel(st, 'archived') + '</button>');
        if (canTo(n, 'deleted')) items.push('<button class="danger" data-act="status" data-to="deleted" data-id="' + n.id + '">' + moveLabel(st, 'deleted') + '</button>');
        var menu = items.length ? (
            '<div class="pn-menu"><button class="pn-menu-btn" data-menu="' + n.id + '" title="More">&#8942;</button>' +
            '<div class="pn-menu-list" data-menu-list="' + n.id + '">' + items.join('') + '</div></div>'
        ) : '';
        // Round tick in the corner: complete / reopen in one click.
        var check = '';
        if (st === 'completed' && canTo(n, 'active')) {
            check = '<button class="pn-check on" data-act="status" data-to="active" data-id="' + n.id + '" title="Completed. Click to reopen"><i class="fas fa-check"></i></button>';
        } else if (st !== 'completed' && canTo(n, 'completed')) {
            check = '<button class="pn-check" data-act="status" data-to="completed" data-id="' + n.id + '" title="Mark completed"><i class="fas fa-check"></i></button>';
        }

        var tags = [];
        if (st === 'in_progress') tags.push('<span class="pn-tag st"><i class="fas fa-spinner"></i> In progress</span>');
        if (st === 'on_hold') tags.push('<span class="pn-tag st" title="Reminders are paused"><i class="fas fa-pause"></i> On hold</span>');
        if (st === 'archived') tags.push('<span class="pn-tag st"><i class="fas fa-box-archive"></i> Archived</span>');
        if (st === 'deleted') tags.push('<span class="pn-tag st"><i class="fas fa-trash-can"></i> Deleted</span>');
        if (n.snoozed_until && isLive(st)) tags.push('<span class="pn-tag" title="Reminder snoozed"><i class="fas fa-bell-slash"></i> Snoozed to ' + esc(fmtTime(n.snoozed_until)) + '</span>');
        if (n.visibility === 'shared') tags.push('<span class="pn-tag"><i class="fas fa-user-group"></i> Shared</span>');
        if (opts.showPlace && n.project_name) tags.push('<span class="pn-tag"><i class="fas fa-diagram-project"></i> ' + esc(n.project_name) + '</span>');
        if (n.page_label) tags.push('<span class="pn-tag" title="Pinned to this page"><i class="fas fa-thumbtack"></i> ' + esc(n.page_label) + '</span>');
        var due = deadlineChip(n);
        if (due) tags.unshift(due);

        return '<div class="pn-postit st-' + esc(st) + (check ? ' has-check' : '') + '" data-note="' + n.id + '" data-act="open" data-id="' + n.id + '"' +
                ' style="' + colorVars(n.color) + '--pn-tilt:' + tilt(n.id) + 'deg;">' +
            check + menu +
            (n.title ? '<div class="pn-postit-title">' + esc(n.title) + '</div>' : '') +
            (n.body ? '<div class="pn-postit-body"' + (opts.compact ? ' style="max-height:80px;"' : '') + '>' + nl2br(n.body) + '</div>' : '<div style="flex:1"></div>') +
            (tags.length ? '<div class="pn-postit-tags">' + tags.join('') + '</div>' : '') +
            '<div class="pn-postit-foot">' + avatarHtml(n.author_avatar, n.author_name, 20) +
              '<span>' + esc(n.is_mine ? 'You' : n.author_name) + ' · ' + fmtTime(n.created_at) + '</span>' +
              '<span class="pn-replies"><i class="fas fa-comments"></i> ' + (n.reply_count || 0) + '</span>' +
            '</div>' +
        '</div>';
    }

    /** Wire menus + actions inside a container of cards; onChange() re-renders it. */
    function bindCards(container, onChange) {
        // Drop menus floated to <body> by an earlier render whose cards are gone.
        document.querySelectorAll('body > .pn-menu-list').forEach(function (x) { if (!x._pnBtn || !document.contains(x._pnBtn)) x.remove(); });
        container.querySelectorAll('.pn-menu-btn').forEach(function (b) {
            var id = b.getAttribute('data-menu');
            var m = container.querySelector('.pn-menu-list[data-menu-list="' + id + '"]');
            if (!m) return;
            m._pnBtn = b;
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var wasOpen = m.classList.contains('open');
                document.querySelectorAll('.pn-menu-list.open').forEach(function (x) { x.classList.remove('open'); });
                if (wasOpen) return;
                // Float the menu on <body> beside the button: the post-it is rotated and sits in
                // scrolling panels, both of which would otherwise clip or skew the menu.
                document.body.appendChild(m);
                m.classList.add('open', 'pn-float');
                var r = b.getBoundingClientRect();
                var w = m.offsetWidth, h = m.offsetHeight;
                var left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
                var top = r.bottom + 4;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
                m.style.left = left + 'px';
                m.style.top = top + 'px';
            });
        });
        container.querySelectorAll('[data-act]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.pn-menu-list.open').forEach(function (x) { x.classList.remove('open'); });
                var act = this.getAttribute('data-act');
                var id = parseInt(this.getAttribute('data-id'), 10);
                var done = function () { changed(); if (onChange) onChange(); };
                if (act === 'open') openThread(id, done);
                else if (act === 'edit') editById(id, done);
                else if (act === 'share') shareDialog(id, done);
                else if (act === 'attach') attachTaskDialog(id, done);
                else if (act === 'move') moveDialog(id, done);
                else if (act === 'status') setStatus(id, this.getAttribute('data-to'), done);
                else if (act === 'snooze') snoozeDialog(id, done);
            });
        });
    }
    document.addEventListener('click', function () {
        document.querySelectorAll('.pn-menu-list.open').forEach(function (x) { x.classList.remove('open'); });
    });

    function swal(o) { o.customClass = Object.assign({ container: 'pn-swal' }, o.customClass || {}); return Swal.fire(o); }
    function fail(d, fallback) { swal({ icon: 'error', title: 'Error', text: (d && d.message) || fallback }); }

    // ── Project tab ──────────────────────────────────────────────────────────
    function load(projectId) {
        injectCss();
        state.projectId = projectId;
        var host = document.getElementById('project-notes-tab');
        if (!host) return;
        host.innerHTML =
            '<div class="pn-wrap">' +
              '<div class="pn-toolbar"><h4><i class="fas fa-note-sticky" style="color:#eab308;"></i> Notes</h4>' +
                '<button class="pn-btn" id="pn-new"><i class="fas fa-plus"></i> New post-it</button></div>' +
              '<div class="pn-views" id="pn-views"></div>' +
              '<div id="pn-list"><div class="pn-empty">Loading notes…</div></div>' +
            '</div>';
        host.querySelector('#pn-new').addEventListener('click', function () {
            openEditor({ projectId: projectId, onSaved: refreshList });
        });
        refreshList();
    }

    function refreshList() {
        var list = document.getElementById('pn-list');
        if (!list || !state.projectId) return;
        api('list', { project_id: state.projectId, view: state.view }).then(function (d) {
            if (!d.success) { list.innerHTML = '<div class="pn-empty">' + esc(d.message || 'Could not load notes') + '</div>'; return; }
            renderViews(document.getElementById('pn-views'), d.counts, state.view, function (v) { state.view = v; refreshList(); });
            if (!d.notes.length) {
                list.innerHTML = '<div class="pn-empty">' + ({ open: 'No open notes. Stick the first post-it on this project.',
                    completed: 'Nothing completed yet.', archived: 'No archived notes.', deleted: 'Nothing in the bin.' }[state.view] || '') + '</div>';
                return;
            }
            list.innerHTML = '<div class="pn-board">' + d.notes.map(function (n) { return renderCard(n); }).join('') + '</div>';
            bindCards(list, refreshList);
        });
    }

    // ── Editor (create + edit) ───────────────────────────────────────────────
    function membersFor(projectId) {
        return projectId ? api('members', { project_id: projectId }) : api('users', {});
    }

    function editById(id, onSaved) {
        api('get', { note_id: id }).then(function (d) {
            if (!d.success) { fail(d, 'Could not load note'); return; }
            openEditor({ note: d.note, onSaved: onSaved });
        });
    }

    /**
     * opts: { projectId, pageKey, pageLabel } for a new note, or { note } to edit; onSaved callback.
     */
    function openEditor(opts) {
        injectCss();
        opts = opts || {};
        var n = opts.note || null;
        var isNew = !n;
        var color = n ? (n.color || 'yellow') : 'yellow';
        var pageKey = n ? (n.page_key || opts.pageKey || '') : (opts.pageKey || '');
        var pageLabel = n ? (n.page_label || opts.pageLabel || '') : (opts.pageLabel || '');
        var pinned = n ? !!n.page_key : !!opts.pageKey;
        var projectId = n ? n.project_id : (opts.projectId || null);
        var bodyLocked = n && !n.can_edit;

        var swatches = Object.keys(COLORS).map(function (c) {
            return '<button type="button" class="pn-sw' + (c === color ? ' on' : '') + '" data-color="' + c + '" title="' + c + '" style="background:' + COLORS[c].bg + ';"></button>';
        }).join('');
        // Reminder: preset or custom (number + unit); repeat: none, preset or custom; channel.
        var curBefore = n && n.remind_before_mins !== null && n.remind_before_mins !== undefined ? n.remind_before_mins : null;
        var curRepeat = n && n.remind_repeat_mins ? n.remind_repeat_mins : 0;
        var beforeIsPreset = curBefore === null || REMIND.some(function (r) { return r[0] === curBefore; });
        var repeatIsPreset = !curRepeat || REPEAT.some(function (r) { return r[0] === curRepeat; });
        var remindOpts = '<option value=""' + (curBefore === null ? ' selected' : '') + '>No reminder</option>' + REMIND.map(function (r) {
            return '<option value="' + r[0] + '"' + (curBefore === r[0] ? ' selected' : '') + '>' + r[1] + '</option>';
        }).join('') + '<option value="custom"' + (!beforeIsPreset ? ' selected' : '') + '>Custom…</option>';
        var repeatOpts = '<option value="0"' + (!curRepeat ? ' selected' : '') + '>Don&#39;t repeat</option>' + REPEAT.map(function (r) {
            return '<option value="' + r[0] + '"' + (curRepeat === r[0] ? ' selected' : '') + '>' + r[1] + '</option>';
        }).join('') + '<option value="custom"' + (!repeatIsPreset ? ' selected' : '') + '>Custom…</option>';
        function unitSel(id, units, cur) {
            return '<select id="' + id + '">' + units.map(function (u) {
                return '<option value="' + u[0] + '"' + (cur === u[0] ? ' selected' : '') + '>' + u[1] + '</option>';
            }).join('') + '</select>';
        }
        var cb = splitMins(!beforeIsPreset ? curBefore : 60), cr = splitMins(!repeatIsPreset ? curRepeat : 120);
        var via = n && n.remind_via ? n.remind_via : 'both';
        var remindBlock =
            '<div class="pn-remind" id="pe-remind-box">' +
              '<div><label>Remind me</label><select id="pe-remind">' + remindOpts + '</select>' +
                '<div class="pn-custom" id="pe-remind-custom"><input type="number" min="0" id="pe-remind-n" value="' + cb.n + '">' +
                  unitSel('pe-remind-u', UNITS, cb.unit) + '<span>before</span></div></div>' +
              '<div><label>Repeat</label><select id="pe-repeat">' + repeatOpts + '</select>' +
                '<div class="pn-custom" id="pe-repeat-custom"><span>every</span><input type="number" min="1" id="pe-repeat-n" value="' + cr.n + '">' +
                  unitSel('pe-repeat-u', UNITS.slice(1), cr.unit === 1 ? 60 : cr.unit) + '</div></div>' +
              '<div><label>Send via</label><select id="pe-via">' +
                '<option value="both"' + (via === 'both' ? ' selected' : '') + '>Email + notification bell</option>' +
                '<option value="bell"' + (via === 'bell' ? ' selected' : '') + '>Notification bell only</option>' +
                '<option value="email"' + (via === 'email' ? ' selected' : '') + '>Email only</option></select></div>' +
              '<div class="pn-remind-hint" id="pe-remind-hint"></div>' +
            '</div>';
        var pinRow = pageKey
            ? '<div class="pn-ed-row"><label><input type="checkbox" id="pe-pin"' + (pinned ? ' checked' : '') + '> <i class="fas fa-thumbtack"></i> Pin to page: <strong>' + esc(pageLabel || pageKey) + '</strong></label></div>'
            : '';
        var projectRow = isNew && !opts.projectId
            ? '<div><label>Project (optional)</label><select id="pe-project"><option value="">— No project —</option></select></div>'
            : '';
        var shareRow = isNew
            ? '<div class="pn-ed-row"><span style="font-weight:600;color:#6b7280;margin-right:8px;">Visibility:</span>' +
                '<label><input type="radio" name="pe-vis" value="private" checked> Private</label>' +
                '<label><input type="radio" name="pe-vis" value="shared"> Shared</label>' +
                '<div class="pn-share-box" id="pe-share" style="display:none;"><div style="color:#9ca3af;font-size:12px;">Loading people…</div></div></div>'
            : '';

        var html =
            '<div class="pn-ed">' +
              '<div class="pn-ed-paper" id="pe-paper" style="' + colorVars(color) + '">' +
                '<input class="pn-ed-title" id="pe-title" maxlength="120" placeholder="Title" value="' + esc(n ? n.title || '' : '') + '">' +
                '<textarea class="pn-ed-body" id="pe-body" placeholder="Write your note…"' + (bodyLocked ? ' disabled title="Text can only be edited for 30 minutes after posting"' : '') + '>' + esc(n ? n.body || '' : '') + '</textarea>' +
                (bodyLocked ? '<div style="font-size:11px;color:#6b7280;margin-top:4px;"><i class="fas fa-lock"></i> Text is locked after 30 minutes. Title, colour and deadline can still change.</div>' : '') +
              '</div>' +
              '<div class="pn-swatches">' + swatches + '</div>' +
              '<div class="pn-ed-grid">' +
                '<div><label>Deadline (optional)</label><input type="datetime-local" id="pe-deadline" value="' + esc(toLocalInput(n && n.deadline)) + '"></div>' +
                projectRow +
              '</div>' + remindBlock +
              pinRow + shareRow +
            '</div>';

        swal({
            title: isNew ? 'New post-it' : 'Edit post-it',
            html: html, width: 560, showCancelButton: true,
            confirmButtonText: isNew ? 'Stick it' : 'Save', confirmButtonColor: '#4f46e5', focusConfirm: false,
            didOpen: function () {
                var pop = Swal.getPopup();
                pop.querySelectorAll('.pn-sw').forEach(function (b) {
                    b.addEventListener('click', function () {
                        color = this.getAttribute('data-color');
                        pop.querySelectorAll('.pn-sw').forEach(function (x) { x.classList.toggle('on', x === b); });
                        pop.querySelector('#pe-paper').setAttribute('style', colorVars(color));
                    });
                });
                var dl = pop.querySelector('#pe-deadline'), rm = pop.querySelector('#pe-remind'), rp = pop.querySelector('#pe-repeat');
                function syncRemind() {
                    var hasDl = !!dl.value, on = hasDl && rm.value !== '';
                    rm.disabled = !hasDl;
                    pop.querySelector('#pe-remind-box').classList.toggle('off', !hasDl);
                    pop.querySelector('#pe-remind-custom').style.display = hasDl && rm.value === 'custom' ? 'flex' : 'none';
                    pop.querySelector('#pe-repeat-custom').style.display = on && rp.value === 'custom' ? 'flex' : 'none';
                    rp.disabled = !on; pop.querySelector('#pe-via').disabled = !on;
                    pop.querySelector('#pe-remind-hint').textContent = !hasDl ? 'Set a deadline to add a reminder.'
                        : (on && rp.value !== '0' ? 'Repeats until you mark the note Done (or delete it).' : '');
                }
                [dl, rm, rp].forEach(function (x) { x.addEventListener('input', syncRemind); x.addEventListener('change', syncRemind); });
                syncRemind();

                var projSel = pop.querySelector('#pe-project');
                var shareBox = pop.querySelector('#pe-share');
                function loadPeople() {
                    if (!shareBox) return;
                    var pid = projSel ? (parseInt(projSel.value, 10) || null) : projectId;
                    shareBox.innerHTML = '<div style="color:#9ca3af;font-size:12px;">Loading people…</div>';
                    membersFor(pid).then(function (d) {
                        var ms = (d && d.success ? d.members : []).filter(function (m) { return !m.is_me; });
                        shareBox.innerHTML = ms.length ? ms.map(function (m) {
                            return '<label class="pn-share-row"><input type="checkbox" class="pe-user" value="' + m.id + '"> ' +
                                avatarHtml(m.avatar, m.name, 22) + ' <span>' + esc(m.name) + '</span>' +
                                '<select class="pe-perm" data-user="' + m.id + '"><option value="view">Can view</option><option value="reply">Can reply</option></select></label>';
                        }).join('') : '<div style="color:#9ca3af;font-size:12px;">Nobody else to share with.</div>';
                    });
                }
                pop.querySelectorAll('input[name="pe-vis"]').forEach(function (r) {
                    r.addEventListener('change', function () {
                        shareBox.style.display = this.value === 'shared' ? 'block' : 'none';
                        if (this.value === 'shared') loadPeople();
                    });
                });
                if (projSel) {
                    api('projects', {}).then(function (d) {
                        (d && d.success ? d.projects : []).forEach(function (p) {
                            var o = document.createElement('option'); o.value = p.id; o.textContent = p.name; projSel.appendChild(o);
                        });
                    });
                    projSel.addEventListener('change', function () { if (shareBox.style.display === 'block') loadPeople(); });
                }
                setTimeout(function () { pop.querySelector(isNew ? '#pe-title' : '#pe-title').focus(); }, 50);
            },
            preConfirm: function () {
                var pop = Swal.getPopup();
                var v = {
                    title: pop.querySelector('#pe-title').value.trim(),
                    body: pop.querySelector('#pe-body').value.trim(),
                    color: color,
                    deadline: pop.querySelector('#pe-deadline').value,
                    remind_before_mins: '', remind_repeat_mins: 0, remind_via: pop.querySelector('#pe-via').value
                };
                var rmv = pop.querySelector('#pe-remind').value;
                if (v.deadline && rmv !== '') {
                    if (rmv === 'custom') {
                        var bn = parseInt(pop.querySelector('#pe-remind-n').value, 10);
                        if (!(bn >= 0)) { Swal.showValidationMessage('Enter how long before the deadline to remind you'); return false; }
                        v.remind_before_mins = bn * parseInt(pop.querySelector('#pe-remind-u').value, 10);
                    } else { v.remind_before_mins = parseInt(rmv, 10); }
                    var rpv = pop.querySelector('#pe-repeat').value;
                    if (rpv === 'custom') {
                        var rn = parseInt(pop.querySelector('#pe-repeat-n').value, 10);
                        if (!(rn >= 1)) { Swal.showValidationMessage('Enter how often to repeat the reminder'); return false; }
                        v.remind_repeat_mins = rn * parseInt(pop.querySelector('#pe-repeat-u').value, 10);
                    } else { v.remind_repeat_mins = parseInt(rpv, 10) || 0; }
                }
                if (!v.title && !v.body) { Swal.showValidationMessage('Give the note a title or some text'); return false; }
                var pin = pop.querySelector('#pe-pin');
                v.page_key = pin && pin.checked ? pageKey : '';
                v.page_label = pin && pin.checked ? pageLabel : '';
                if (isNew) {
                    var projSel = pop.querySelector('#pe-project');
                    v.project_id = projSel ? (projSel.value || '') : (projectId || '');
                    v.visibility = pop.querySelector('input[name="pe-vis"]:checked').value;
                    var shares = [];
                    pop.querySelectorAll('.pe-user:checked').forEach(function (cb) {
                        var perm = pop.querySelector('.pe-perm[data-user="' + cb.value + '"]');
                        shares.push({ user_id: parseInt(cb.value, 10), permission: perm ? perm.value : 'view' });
                    });
                    v.shares = JSON.stringify(shares);
                    if (!v.project_id && !v.page_key && !v.deadline) {
                        // A note with no project, page or deadline would be unreachable.
                        Swal.showValidationMessage('Pin it to this page, pick a project, or set a deadline so you can find it again');
                        return false;
                    }
                }
                return v;
            }
        }).then(function (res) {
            if (!res.isConfirmed) return;
            var v = res.value;
            var done = function () { changed(); if (opts.onSaved) opts.onSaved(); };
            if (isNew) {
                api('create', v).then(function (d) { if (!d.success) { fail(d, 'Could not save note'); return; } done(); });
                return;
            }
            api('meta', { note_id: n.id, title: v.title, color: v.color, deadline: v.deadline, remind_before_mins: v.remind_before_mins,
                          remind_repeat_mins: v.remind_repeat_mins, remind_via: v.remind_via,
                          page_key: v.page_key, page_label: v.page_label }).then(function (d) {
                if (!d.success) { fail(d, 'Could not save note'); return; }
                if (!bodyLocked && v.body !== (n.body || '') && v.body !== '') {
                    api('update', { note_id: n.id, body: v.body }).then(function (d2) {
                        if (!d2.success) fail(d2, 'Could not save the note text');
                        done();
                    });
                } else { done(); }
            });
        });
    }

    // ── Menu actions ─────────────────────────────────────────────────────────
    /** Open / Completed / Archived / Deleted filter tabs with counts. */
    function renderViews(host, counts, current, onPick) {
        if (!host) return;
        counts = counts || {};
        var views = [['open', 'Open'], ['completed', 'Completed'], ['archived', 'Archived'], ['deleted', 'Deleted']];
        host.innerHTML = views.filter(function (v) { return v[0] === 'open' || v[0] === current || (counts[v[0]] || 0) > 0; }).map(function (v) {
            return '<button class="pn-view' + (v[0] === current ? ' on' : '') + '" data-view="' + v[0] + '">' + v[1] +
                ' <span>' + (counts[v[0]] || 0) + '</span></button>';
        }).join('');
        host.querySelectorAll('[data-view]').forEach(function (b) {
            b.addEventListener('click', function (e) { e.stopPropagation(); onPick(b.getAttribute('data-view')); });
        });
    }

    /** Change a note's status; the server handles reminders, bells and the thread history line. */
    function setStatus(id, to, onDone) {
        var go = function () {
            api('set_status', { note_id: id, status: to }).then(function (d) {
                if (!d.success) { fail(d, 'Could not update the note'); return; }
                var msg = { completed: 'Completed. Reminders stopped', on_hold: 'On hold. Reminders paused',
                            archived: 'Archived. Reminders stopped', deleted: 'Moved to Deleted. You can restore it from the Deleted tab',
                            in_progress: 'Marked in progress', active: 'Note is open again. Reminders rescheduled' }[to];
                if (msg && window.Swal) swal({ toast: true, position: 'bottom-end', icon: 'success', title: msg, timer: 2200, showConfirmButton: false });
                if (onDone) onDone();
            });
        };
        if (to !== 'deleted') { go(); return; }
        swal({
            title: 'Delete this note?', text: 'Reminders stop and it moves to the Deleted tab, where you can restore it.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete'
        }).then(function (r) { if (r.isConfirmed) go(); });
    }

    /** Push the next reminder to a later time without changing the deadline. */
    function snoozeDialog(id, onDone) {
        var tomorrow9 = new Date(); tomorrow9.setDate(tomorrow9.getDate() + 1); tomorrow9.setHours(9, 0, 0, 0);
        var pad = function (x) { return String(x).padStart(2, '0'); };
        var local = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
        swal({
            title: 'Snooze reminder',
            html: '<div style="text-align:left;font-size:14px;">' +
                [['60', '1 hour'], ['180', '3 hours'], ['t9', 'Tomorrow at 09:00'], ['10080', '1 week'], ['custom', 'Pick a date and time']].map(function (o, i) {
                    return '<label style="display:block;margin:6px 0;cursor:pointer;"><input type="radio" name="sz" value="' + o[0] + '"' + (i === 0 ? ' checked' : '') + '> ' + o[1] + '</label>';
                }).join('') +
                '<input type="datetime-local" id="sz-at" class="swal2-input" style="margin:6px 0 0;width:100%;box-sizing:border-box;" value="' + local(tomorrow9) + '">' +
                '<p style="font-size:12px;color:#6b7280;margin-top:10px;">The deadline stays the same; only the next reminder moves.</p></div>',
            showCancelButton: true, confirmButtonText: 'Snooze', confirmButtonColor: '#4f46e5',
            preConfirm: function () {
                var pop = Swal.getPopup();
                var v = pop.querySelector('input[name="sz"]:checked').value;
                if (v === 't9') return { until: local(tomorrow9) };
                if (v === 'custom') return { until: pop.querySelector('#sz-at').value };
                return { minutes: v };
            }
        }).then(function (res) {
            if (!res.isConfirmed) return;
            api('snooze', Object.assign({ note_id: id }, res.value)).then(function (d) {
                if (!d.success) { fail(d, 'Could not snooze'); return; }
                swal({ toast: true, position: 'bottom-end', icon: 'success', title: 'Reminder snoozed to ' + fmtTime(d.note.snoozed_until), timer: 2200, showConfirmButton: false });
                if (onDone) onDone();
            });
        });
    }

    function shareDialog(id, onDone) {
        api('get', { note_id: id }).then(function (d) {
            if (!d.success) { fail(d, 'Could not load note'); return; }
            var note = d.note;
            membersFor(note.project_id).then(function (md) {
                var members = (md && md.success ? md.members : []).filter(function (m) { return !m.is_me; });
                var sharedMap = {};
                (note.shares || []).forEach(function (s) { sharedMap[s.user_id] = s.permission; });
                var rows = members.map(function (m) {
                    var perm = sharedMap[m.id] || 'view';
                    return '<label class="pn-share-row" style="justify-content:flex-start;">' +
                        '<input type="checkbox" class="sw-user" value="' + m.id + '" ' + (sharedMap[m.id] ? 'checked' : '') + '> ' + esc(m.name) +
                        '<select class="sw-perm" data-user="' + m.id + '"><option value="view"' + (perm === 'view' ? ' selected' : '') + '>Can view</option><option value="reply"' + (perm === 'reply' ? ' selected' : '') + '>Can reply</option></select>' +
                        '</label>';
                }).join('') || '<div style="color:#9ca3af;">Nobody else to share with.</div>';

                swal({
                    title: 'Share note',
                    html: '<div style="text-align:left;">' +
                            '<label style="display:block;margin-bottom:10px;font-size:14px;"><input type="radio" name="sw-vis" value="private" ' + (note.visibility === 'private' ? 'checked' : '') + '> Private (only me)</label>' +
                            '<label style="display:block;margin-bottom:10px;font-size:14px;"><input type="radio" name="sw-vis" value="shared" ' + (note.visibility === 'shared' ? 'checked' : '') + '> Shared with:</label>' +
                            '<div id="sw-box" style="max-height:240px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">' + rows + '</div>' +
                          '</div>',
                    width: 480, showCancelButton: true, confirmButtonText: 'Save sharing', confirmButtonColor: '#4f46e5',
                    didOpen: function () {
                        var pop = Swal.getPopup();
                        function sync() {
                            var v = pop.querySelector('input[name="sw-vis"]:checked').value;
                            pop.querySelector('#sw-box').style.opacity = v === 'shared' ? '1' : '.45';
                            pop.querySelectorAll('#sw-box input,#sw-box select').forEach(function (el) { el.disabled = v !== 'shared'; });
                        }
                        pop.querySelectorAll('input[name="sw-vis"]').forEach(function (r) { r.addEventListener('change', sync); });
                        sync();
                    },
                    preConfirm: function () {
                        var pop = Swal.getPopup();
                        var shares = [];
                        pop.querySelectorAll('.sw-user:checked').forEach(function (cb) {
                            var perm = pop.querySelector('.sw-perm[data-user="' + cb.value + '"]');
                            shares.push({ user_id: parseInt(cb.value, 10), permission: perm ? perm.value : 'view' });
                        });
                        return { vis: pop.querySelector('input[name="sw-vis"]:checked').value, shares: shares };
                    }
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    api('share', { note_id: id, visibility: res.value.vis, shares: JSON.stringify(res.value.shares) }).then(function (d2) {
                        if (!d2.success) { fail(d2, 'Could not update sharing'); return; }
                        if (onDone) onDone();
                    });
                });
            });
        });
    }

    function attachTaskDialog(id, onDone) {
        api('get', { note_id: id }).then(function (nd) {
            if (!nd.success || !nd.note.project_id) { fail(nd, 'This note has no project'); return; }
            api('tasks', { project_id: nd.note.project_id }).then(function (d) {
                if (!d.success) { fail(d, 'Could not load tasks'); return; }
                var opts = '<option value="">— none (detach) —</option>' + d.tasks.map(function (t) {
                    return '<option value="' + t.id + '"' + (nd.note.task_id === t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>';
                }).join('');
                swal({
                    title: 'Attach to task',
                    html: '<select id="pn-task-sel" class="swal2-select" style="width:100%;">' + opts + '</select>',
                    showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#4f46e5',
                    preConfirm: function () { return document.getElementById('pn-task-sel').value; }
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    api('attach_task', { note_id: id, task_id: res.value || 0 }).then(function (d2) {
                        if (!d2.success) { fail(d2, 'Could not attach'); return; }
                        swal({ icon: 'success', title: res.value ? 'Attached to task' : 'Detached', timer: 1400, showConfirmButton: false });
                        if (onDone) onDone();
                    });
                });
            });
        });
    }

    function moveDialog(id, onDone) {
        api('get', { note_id: id }).then(function (nd) {
            var current = nd.success ? nd.note.project_id : null;
            api('projects', {}).then(function (d) {
                if (!d.success) { fail(d, 'Could not load projects'); return; }
                var opts = d.projects.filter(function (p) { return p.id !== current; }).map(function (p) {
                    return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
                }).join('');
                if (!opts) { swal({ icon: 'info', title: 'No other projects', text: 'You are not a member of another project to move this to.' }); return; }
                swal({
                    title: current ? 'Move note to project' : 'Add note to a project',
                    html: '<select id="pn-proj-sel" class="swal2-select" style="width:100%;">' + opts + '</select>' +
                          '<p style="font-size:12px;color:#6b7280;margin-top:10px;">The note is detached from any task and unshared from people who aren\'t in the project.</p>',
                    showCancelButton: true, confirmButtonText: 'Move', confirmButtonColor: '#4f46e5',
                    preConfirm: function () { return document.getElementById('pn-proj-sel').value; }
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    api('reassign', { note_id: id, target_project_id: res.value }).then(function (d2) {
                        if (!d2.success) { fail(d2, 'Could not move note'); return; }
                        swal({ icon: 'success', title: 'Note moved', timer: 1400, showConfirmButton: false });
                        if (onDone) onDone();
                    });
                });
            });
        });
    }

    // ── Conversation (WhatsApp-style) ─────────────────────────────────────────
    var threadOnClose = null;
    function ensureThreadModal() {
        injectCss();
        var ov = document.getElementById('pn-thread-overlay');
        if (ov) return ov;
        ov = document.createElement('div');
        ov.id = 'pn-thread-overlay';
        ov.className = 'pn-thread-overlay';
        ov.innerHTML =
            '<div class="pn-thread">' +
              '<div class="pn-thread-head" id="pn-thread-head"><i class="fas fa-note-sticky"></i><h3 id="pn-thread-title">Conversation</h3>' +
                '<span class="pn-thread-status" id="pn-thread-status"></span>' +
                '<button class="pn-btn-light" id="pn-thread-done" style="display:none;padding:4px 10px;"></button>' +
                '<button class="pn-btn-light" id="pn-thread-edit" style="display:none;padding:4px 10px;">Edit</button>' +
                '<button class="pn-thread-close" id="pn-thread-close">&times;</button></div>' +
              '<div class="pn-thread-body" id="pn-thread-body"></div>' +
              '<div id="pn-thread-foot"></div>' +
            '</div>';
        document.body.appendChild(ov);
        ov.addEventListener('click', function (e) { if (e.target === ov) closeThread(); });
        ov.querySelector('#pn-thread-close').addEventListener('click', closeThread);
        return ov;
    }
    function closeThread() {
        var ov = document.getElementById('pn-thread-overlay');
        if (ov) ov.classList.remove('open');
        state.openNoteId = null;
        var cb = threadOnClose; threadOnClose = null;
        changed(); // reading a thread clears it from the bell
        if (cb) cb();
    }

    function openThread(noteId, onClose) {
        var ov = ensureThreadModal();
        ov.classList.add('open');
        state.openNoteId = noteId;
        threadOnClose = onClose || null;
        renderThread();
    }

    function renderThread() {
        var noteId = state.openNoteId;
        api('get', { note_id: noteId }).then(function (d) {
            if (!d.success) { closeThread(); fail(d, 'Could not open conversation'); return; }
            if (state.openNoteId !== noteId) return;
            var note = d.note;
            var k = COLORS[note.color] || COLORS.yellow;
            var head = document.getElementById('pn-thread-head');
            head.style.background = k.edge; head.style.color = k.ink;
            document.getElementById('pn-thread-close').style.color = k.ink;
            document.getElementById('pn-thread-title').textContent = note.title || ('Note by ' + note.author_name);
            var stEl = document.getElementById('pn-thread-status');
            stEl.textContent = note.status !== 'active' ? (STATUS[note.status] || {}).label || '' : '';
            var doneBtn = document.getElementById('pn-thread-done');
            var doneTo = note.status === 'completed' ? (canTo(note, 'active') ? 'active' : null) : (canTo(note, 'completed') ? 'completed' : null);
            doneBtn.style.display = doneTo ? '' : 'none';
            doneBtn.innerHTML = doneTo === 'active' ? '<i class="fas fa-rotate-left"></i> Reopen' : '<i class="fas fa-check"></i> Complete';
            doneBtn.onclick = function () { setStatus(note.id, doneTo, function () { changed(); renderThread(); }); };
            var editBtn = document.getElementById('pn-thread-edit');
            editBtn.style.display = note.can_manage ? '' : 'none';
            editBtn.onclick = function () {
                var cb = threadOnClose; threadOnClose = null;
                document.getElementById('pn-thread-overlay').classList.remove('open');
                state.openNoteId = null;
                openEditor({ note: note, onSaved: cb });
            };
            var body = document.getElementById('pn-thread-body');

            var html = '<div class="pn-thread-note" style="' + colorVars(note.color) + 'color:' + k.ink + ';">' +
                (note.title ? '<div class="pn-postit-title">' + esc(note.title) + '</div>' : '<div class="pn-msg-author">' + esc(note.author_name) + '</div>') +
                '<div style="white-space:pre-wrap;word-break:break-word;font-size:14px;">' + nl2br(note.body) + '</div>' +
                '<div class="pn-postit-tags">' + deadlineChip(note) +
                  (note.project_name ? '<span class="pn-tag"><i class="fas fa-diagram-project"></i> ' + esc(note.project_name) + '</span>' : '') +
                  (note.page_label ? '<span class="pn-tag"><i class="fas fa-thumbtack"></i> ' + esc(note.page_label) + '</span>' : '') + '</div>' +
                '<div class="pn-msg-meta" style="text-align:left;">' + esc(note.author_name) + ' · ' + fmtTime(note.created_at) + (note.edited_at ? ' · edited' : '') + '</div></div>';

            html += note.replies.map(function (r) {
                if (r.type === 'event') {
                    return '<div class="pn-event"><strong>' + esc(r.is_mine ? 'You' : r.author_name) + '</strong> ' + esc(r.body) +
                        ' · ' + fmtTime(r.created_at) + '</div>';
                }
                var cls = r.is_mine ? 'me' : 'them';
                var actions = r.can_edit
                    ? '<div class="pn-msg-actions"><button data-redit="' + r.id + '">Edit</button><button data-rdel="' + r.id + '">Delete</button></div>'
                    : '';
                return '<div class="pn-msg ' + cls + '" data-reply="' + r.id + '">' +
                    (r.is_mine ? '' : '<div class="pn-msg-author">' + esc(r.author_name) + '</div>') +
                    '<div class="pn-msg-text" style="white-space:pre-wrap;">' + nl2br(r.body) + '</div>' +
                    '<div class="pn-msg-meta">' + fmtTime(r.created_at) + (r.edited_at ? ' · edited' : '') + '</div>' +
                    actions + '</div>';
            }).join('');
            body.innerHTML = html;
            body.scrollTop = body.scrollHeight;

            body.querySelectorAll('[data-redit]').forEach(function (b) {
                b.addEventListener('click', function () { editReply(parseInt(this.getAttribute('data-redit'), 10)); });
            });
            body.querySelectorAll('[data-rdel]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var rid = parseInt(this.getAttribute('data-rdel'), 10);
                    api('reply_delete', { reply_id: rid }).then(function (x) { if (x.success) renderThread(); });
                });
            });

            var foot = document.getElementById('pn-thread-foot');
            if (note.can_reply) {
                foot.className = 'pn-thread-foot';
                foot.innerHTML = '<textarea id="pn-reply-box" rows="1" placeholder="Type a reply…"></textarea>' +
                    '<button class="pn-send" id="pn-reply-send"><i class="fas fa-paper-plane"></i></button>';
                var ta = foot.querySelector('#pn-reply-box');
                ta.addEventListener('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 120) + 'px'; });
                ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); } });
                foot.querySelector('#pn-reply-send').addEventListener('click', sendReply);
            } else {
                foot.className = '';
                foot.innerHTML = '<div class="pn-readonly">You have view-only access to this note.</div>';
            }
        });
    }

    function sendReply() {
        var ta = document.getElementById('pn-reply-box');
        if (!ta) return;
        var body = ta.value.trim();
        if (!body) return;
        var noteId = state.openNoteId;
        ta.disabled = true;
        api('reply_create', { note_id: noteId, body: body }).then(function (d) {
            ta.disabled = false;
            if (!d.success) { fail(d, 'Could not send'); return; }
            ta.value = ''; ta.style.height = 'auto';
            renderThread();
        });
    }

    function editReply(replyId) {
        var msg = document.querySelector('.pn-msg[data-reply="' + replyId + '"]');
        if (!msg) return;
        var textEl = msg.querySelector('.pn-msg-text');
        var current = textEl.innerText;
        var actions = msg.querySelector('.pn-msg-actions');
        if (actions) actions.style.display = 'none';
        textEl.innerHTML = '<textarea style="width:100%;min-width:200px;border:1px solid #cbd5e1;border-radius:6px;padding:6px;font:inherit;font-size:14px;box-sizing:border-box;">' + esc(current) + '</textarea>' +
            '<div style="margin-top:5px;text-align:right;"><button class="pn-msg-save" style="background:#4f46e5;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;">Save</button> ' +
            '<button class="pn-msg-cancel" style="background:none;border:none;color:#6b7280;font-size:12px;cursor:pointer;">Cancel</button></div>';
        textEl.querySelector('.pn-msg-save').addEventListener('click', function () {
            var val = textEl.querySelector('textarea').value.trim();
            api('reply_update', { reply_id: replyId, body: val }).then(function (d) {
                if (!d.success) { fail(d, 'Could not save'); return; }
                renderThread();
            });
        });
        textEl.querySelector('.pn-msg-cancel').addEventListener('click', renderThread);
    }

    window.ProjectNotes = {
        load: load, openThread: openThread, openEditor: openEditor,
        renderCard: renderCard, bindCards: bindCards, injectCss: injectCss, api: api, renderViews: renderViews
    };
})();
