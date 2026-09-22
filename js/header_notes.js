/**
 * Header post-it + notifications panels (includes/header.php).
 *
 *  Post-it button: panel with this page's notes, my deadline notes, and "+ New note on this page".
 *  Bell: live "needs attention" feed from ajax/notifications.php.
 *  Both badges refresh whenever notes change ("ten-notes-changed") and every 2 minutes.
 *  Card rendering, the editor and the conversation view come from js/project_notes.js.
 */
(function () {
    'use strict';
    var PAGE = (window.TEN_HEADER && window.TEN_HEADER.pageKey) || '';
    var NOTIF_EP = '/management/ajax/notifications.php';

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(url, params) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params || {})
        }).then(function (r) { return r.json(); });
    }
    function pageLabel() {
        var t = (document.title || '').split(/\s[-—|]\s/)[0].trim();
        return t || PAGE;
    }
    function relTime(s) {
        var d = new Date(String(s || '').replace(' ', 'T'));
        if (isNaN(d)) return '';
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 0) return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
        if (diff < 60) return 'now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd';
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    /* SweetAlert2 is bundled locally; a few pages don't include it. */
    function ensureSwal() {
        if (window.Swal) return Promise.resolve();
        return new Promise(function (res) {
            var s = document.createElement('script');
            s.src = '/management/js/vendor/sweetalert2.all.min.js';
            s.onload = res; s.onerror = res;
            document.head.appendChild(s);
        });
    }

    /* ---- badges ---- */
    function applySummary(notes, bellCount) {
        var nb = el('hdrNotesBadge'), btn = el('hdrNotesBtn');
        if (nb && notes) {
            nb.textContent = notes.deadlines;
            nb.style.display = notes.deadlines > 0 ? '' : 'none';
            nb.classList.toggle('badge-red', notes.overdue > 0);
            nb.classList.toggle('badge-amber', !(notes.overdue > 0));
            btn.classList.toggle('has-page-notes', notes.page > 0);
            btn.title = notes.page > 0 ? notes.page + ' note(s) on this page' : 'Notes';
        }
        var bb = el('hdrBellBadge');
        if (bb && typeof bellCount === 'number') {
            bb.textContent = bellCount > 99 ? '99+' : bellCount;
            bb.style.display = bellCount > 0 ? '' : 'none';
        }
    }
    function refreshBadges() {
        return post(NOTIF_EP, { action: 'feed', page_key: PAGE }).then(function (d) {
            if (d && d.success) applySummary(d.notes, d.count);
            return d;
        }).catch(function () { return null; });
    }

    /* ---- open/close ---- */
    function closeAll(except) {
        ['hdrNotesPop', 'hdrBellPop'].forEach(function (id) { if (id !== except && el(id)) el(id).classList.remove('active'); });
    }
    function toggle(popId, render) {
        var p = el(popId);
        var open = !p.classList.contains('active');
        closeAll(popId);
        var lm = el('langMenu'), ud = el('userDropdown');
        if (lm) lm.classList.remove('active');
        if (ud) ud.classList.remove('active');
        p.classList.toggle('active', open);
        if (open) render();
    }

    /* ---- post-it panel ---- */
    var pageView = 'open'; // open | completed | archived | deleted (this page's notes)
    function renderNotesPanel() {
        var pop = el('hdrNotesPop');
        if (!window.ProjectNotes) { pop.innerHTML = '<div class="hdr-pop-sec hdr-pop-empty">Loading…</div>'; return; }
        ProjectNotes.injectCss();
        if (!pop.innerHTML) pop.innerHTML = '<div class="hdr-pop-sec hdr-pop-empty">Loading notes…</div>';
        ProjectNotes.api('page_panel', { page_key: PAGE, view: pageView }).then(function (d) {
            if (!d.success) { pop.innerHTML = '<div class="hdr-pop-sec hdr-pop-empty">' + esc(d.message || 'Could not load notes') + '</div>'; return; }
            applySummary(d.summary);
            var label = pageLabel();
            var pageHtml = d.page_notes.length
                ? '<div class="pn-board">' + d.page_notes.map(function (n) { return ProjectNotes.renderCard(n, { compact: true }); }).join('') + '</div>'
                : '<div class="hdr-pop-empty">' + (pageView === 'open' ? 'No open notes on this page.' : 'None here.') + '</div>';
            var now = Date.now();
            var dl = d.deadlines.map(function (n) {
                var t = new Date(String(n.deadline).replace(' ', 'T')).getTime();
                var ms = t - now;
                var tone = ms < 0 ? '#dc2626' : (ms < 86400000 ? '#b45309' : '#15803d');
                var when = ms < 0 ? 'Overdue · ' : 'Due ';
                var place = n.page_label || n.project_name || '';
                var colors = { yellow: '#fff59d', pink: '#f8bbd0', green: '#c5e1a5', blue: '#b3e5fc', orange: '#ffcc80', purple: '#d1c4e9' };
                return '<div class="hdr-dl-row" data-note="' + n.id + '">' +
                    '<span class="hdr-dl-dot" style="background:' + (colors[n.color] || colors.yellow) + ';"></span>' +
                    '<div class="hdr-dl-main"><div class="hdr-dl-title">' + esc(n.title || (n.body || '').slice(0, 60) || 'Note') + '</div>' +
                    '<div class="hdr-dl-sub"><span style="color:' + tone + ';font-weight:700;">' + when +
                        esc(new Date(t).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })) + '</span>' +
                        (place ? ' · ' + esc(place) : '') + '</div></div>' +
                    (n.page_key && n.page_key !== PAGE ? '<a href="/management/' + esc(n.page_key) + '" title="Go to page" onclick="event.stopPropagation()" style="color:#9ca3af;"><i class="fas fa-arrow-up-right-from-square"></i></a>' : '') +
                    '</div>';
            }).join('');

            pop.innerHTML =
                '<div class="hdr-pop-head"><h4><i class="fas fa-note-sticky" style="color:#eab308;"></i> Notes</h4></div>' +
                '<div class="hdr-pop-sec"><div class="hdr-pop-label">On this page · ' + esc(label) + '</div>' +
                    '<div class="pn-views" id="hdrPageViews" style="margin:0 0 6px;"></div>' + pageHtml +
                    '<button class="hdr-new-note" id="hdrNewNote"><i class="fas fa-plus"></i> New note on this page</button></div>' +
                '<div class="hdr-pop-sec"><div class="hdr-pop-label">Deadlines (' + d.deadlines.length + ')</div>' +
                    (dl || '<div class="hdr-pop-empty">No notes with a deadline.</div>') + '</div>';

            ProjectNotes.renderViews(el('hdrPageViews'), d.page_counts, pageView, function (v) { pageView = v; renderNotesPanel(); });
            ProjectNotes.bindCards(pop, renderNotesPanel);
            pop.querySelectorAll('.hdr-dl-row').forEach(function (r) {
                r.addEventListener('click', function (e) {
                    e.stopPropagation();
                    ProjectNotes.openThread(parseInt(r.getAttribute('data-note'), 10), renderNotesPanel);
                });
            });
            el('hdrNewNote').addEventListener('click', function (e) {
                e.stopPropagation();
                ensureSwal().then(function () {
                    ProjectNotes.openEditor({ pageKey: PAGE, pageLabel: label, onSaved: renderNotesPanel });
                });
            });
        });
    }

    /* ---- bell panel ---- */
    function renderBellPanel(action, extra) {
        var pop = el('hdrBellPop');
        if (!pop.innerHTML) pop.innerHTML = '<div class="hdr-pop-sec hdr-pop-empty">Loading…</div>';
        post(NOTIF_EP, Object.assign({ action: action || 'feed', page_key: PAGE }, extra || {})).then(function (d) {
            if (!d || !d.success) { pop.innerHTML = '<div class="hdr-pop-sec hdr-pop-empty">Could not load notifications.</div>'; return; }
            applySummary(d.notes, d.count);
            var rows = d.items.map(function (it, i) {
                return '<div class="hdr-nt-row t-' + esc(it.tone) + '" data-i="' + i + '">' +
                    '<input type="checkbox" class="hdr-nt-ck" data-key="' + esc(it.key) + '" title="Select">' +
                    '<div class="hdr-nt-ic"><i class="fas ' + esc(it.icon) + '"></i></div>' +
                    '<div class="hdr-nt-main"><div class="hdr-nt-title">' + esc(it.title) + '</div>' +
                        (it.detail ? '<div class="hdr-nt-detail">' + esc(it.detail) + '</div>' : '') + '</div>' +
                    '<div class="hdr-nt-side"><div class="hdr-nt-when">' + esc(relTime(it.when)) + '</div>' +
                        '<button class="hdr-nt-del" data-key="' + esc(it.key) + '" title="Delete"><i class="fas fa-trash-can"></i></button></div>' +
                    '</div>';
            }).join('');
            pop.innerHTML =
                '<div class="hdr-pop-head">' +
                    (d.items.length ? '<input type="checkbox" id="hdrBellSelAll" title="Select all">' : '') +
                    '<h4><i class="fas fa-bell" style="color:#4f46e5;"></i> Notifications</h4>' +
                    '<button id="hdrBellDelSel" class="hdr-danger" style="display:none;"><i class="fas fa-trash-can"></i> Delete</button>' +
                    (d.count > 0 ? '<button id="hdrBellAllRead">Mark all read</button>' : '') + '</div>' +
                (rows || '<div class="hdr-pop-sec hdr-pop-empty" style="text-align:center;padding:26px 16px;"><i class="fas fa-circle-check" style="font-size:26px;color:#22c55e;display:block;margin-bottom:8px;"></i>You\'re all caught up.</div>');

            var delSel = el('hdrBellDelSel'), selAll = el('hdrBellSelAll');
            function checked() { return Array.prototype.slice.call(pop.querySelectorAll('.hdr-nt-ck:checked')); }
            function syncSel() {
                var n = checked().length;
                delSel.style.display = n ? '' : 'none';
                delSel.innerHTML = '<i class="fas fa-trash-can"></i> Delete (' + n + ')';
                if (selAll) selAll.checked = n > 0 && n === pop.querySelectorAll('.hdr-nt-ck').length;
            }
            function del(keys) {
                if (!keys.length) return;
                renderBellPanel('delete', { keys: JSON.stringify(keys) });
            }
            pop.querySelectorAll('.hdr-nt-ck').forEach(function (cb) {
                cb.addEventListener('click', function (e) { e.stopPropagation(); });
                cb.addEventListener('change', syncSel);
            });
            if (selAll) selAll.addEventListener('change', function () {
                var on = selAll.checked;
                pop.querySelectorAll('.hdr-nt-ck').forEach(function (cb) { cb.checked = on; });
                syncSel();
            });
            delSel.addEventListener('click', function (e) {
                e.stopPropagation();
                del(checked().map(function (cb) { return cb.getAttribute('data-key'); }));
            });
            pop.querySelectorAll('.hdr-nt-del').forEach(function (b) {
                b.addEventListener('click', function (e) { e.stopPropagation(); del([b.getAttribute('data-key')]); });
            });

            pop.querySelectorAll('.hdr-nt-row').forEach(function (r) {
                r.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var it = d.items[parseInt(r.getAttribute('data-i'), 10)];
                    var markThen = function (fn) { post(NOTIF_EP, { action: 'mark_read', key: it.key }).then(fn, fn); };
                    if (it.note_id && window.ProjectNotes) {
                        pop.classList.remove('active');
                        markThen(function () { ProjectNotes.openThread(it.note_id, refreshBadges); });
                        return;
                    }
                    markThen(function () { if (it.link) window.location.href = '/management/' + it.link; });
                });
            });
            var all = el('hdrBellAllRead');
            if (all) all.addEventListener('click', function (e) { e.stopPropagation(); renderBellPanel('mark_all_read'); });
        });
    }

    function init() {
        var nb = el('hdrNotesBtn'), bb = el('hdrBellBtn');
        if (!nb || !bb) return;
        nb.addEventListener('click', function (e) { e.stopPropagation(); ensureSwal(); toggle('hdrNotesPop', renderNotesPanel); });
        bb.addEventListener('click', function (e) { e.stopPropagation(); toggle('hdrBellPop', renderBellPanel); });
        ['hdrNotesPop', 'hdrBellPop'].forEach(function (id) {
            el(id).addEventListener('click', function (e) { e.stopPropagation(); });
        });
        document.addEventListener('click', function (e) {
            // Clicks inside SweetAlert / the conversation overlay shouldn't collapse the panel.
            if (e.target.closest && e.target.closest('.swal2-container, #pn-thread-overlay, .pn-menu-list')) return;
            closeAll();
        });
        document.addEventListener('ten-notes-changed', function () {
            refreshBadges();
            if (el('hdrBellPop').classList.contains('active')) renderBellPanel();
        });
        setInterval(function () { if (!document.hidden) refreshBadges(); }, 120000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
