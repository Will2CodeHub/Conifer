/**
 * Project Notes — UI module (text notes; voice is a planned follow-up).
 *
 * Renders a Notes panel inside a project's detail modal and a WhatsApp-style
 * conversation view for each note. Talks to ajax/project_notes.php.
 *
 * Exposes window.ProjectNotes.load(projectId), called when the project's "Notes" tab opens.
 */
(function () {
    'use strict';

    var ENDPOINT = '/management/ajax/project_notes.php';
    var state = { projectId: null, members: [], openNoteId: null, showArchived: false };

    function api(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }

    function fmtTime(iso) {
        if (!iso) return '';
        var d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d)) return esc(iso);
        var now = new Date();
        var opts = { hour: '2-digit', minute: '2-digit' };
        if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], opts);
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString([], opts);
    }

    function avatarHtml(url, name, size) {
        size = size || 34;
        var st = 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex:0 0 auto;object-fit:cover;';
        if (url) return '<img src="' + esc(url) + '" alt="' + esc(name) + '" style="' + st + '">';
        var initials = (name || '?').trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
        return '<div style="' + st + 'background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:' + (size * 0.4) + 'px;font-weight:600;">' + esc(initials) + '</div>';
    }

    function injectCss() {
        if (document.getElementById('ten-notes-css')) return;
        var css = document.createElement('style');
        css.id = 'ten-notes-css';
        css.textContent = [
            '.pn-wrap{max-width:820px;margin:0 auto;}',
            '.pn-composer{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:20px;}',
            '.pn-composer textarea{width:100%;min-height:70px;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font:inherit;font-size:14px;resize:vertical;box-sizing:border-box;}',
            '.pn-row{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:12px;}',
            '.pn-vis label{font-size:13px;color:#374151;margin-right:10px;cursor:pointer;}',
            '.pn-share-box{margin-top:12px;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;max-height:200px;overflow:auto;display:none;}',
            '.pn-share-box.open{display:block;}',
            '.pn-share-row{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:13px;}',
            '.pn-share-row select{margin-left:auto;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;}',
            '.pn-btn{background:#4f46e5;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}',
            '.pn-btn:disabled{opacity:.6;cursor:not-allowed;}',
            '.pn-btn-light{background:#fff;color:#374151;border:1px solid #d1d5db;padding:7px 12px;border-radius:8px;font-size:13px;cursor:pointer;}',
            '.pn-note{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px;}',
            '.pn-note.archived{opacity:.7;}',
            '.pn-note-head{display:flex;align-items:center;gap:10px;margin-bottom:8px;}',
            '.pn-note-meta{min-width:0;}',
            '.pn-note-author{font-weight:600;color:#111827;font-size:14px;}',
            '.pn-note-time{color:#9ca3af;font-size:12px;}',
            '.pn-badge{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.3px;}',
            '.pn-badge.private{background:#fef3c7;color:#92400e;}',
            '.pn-badge.shared{background:#dbeafe;color:#1e40af;}',
            '.pn-badge.arch{background:#f3f4f6;color:#6b7280;}',
            '.pn-note-body{color:#374151;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;}',
            '.pn-note-foot{display:flex;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap;}',
            '.pn-link{background:none;border:none;color:#4f46e5;font-size:13px;font-weight:600;cursor:pointer;padding:4px 6px;border-radius:6px;}',
            '.pn-link:hover{background:#eef2ff;}',
            '.pn-link.danger{color:#dc2626;}.pn-link.danger:hover{background:#fef2f2;}',
            '.pn-menu{margin-left:auto;position:relative;}',
            '.pn-menu-btn{background:none;border:none;color:#9ca3af;font-size:18px;cursor:pointer;padding:2px 8px;border-radius:6px;}',
            '.pn-menu-btn:hover{background:#f3f4f6;color:#111827;}',
            '.pn-menu-list{position:absolute;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:170px;z-index:30;padding:5px 0;display:none;}',
            '.pn-menu-list.open{display:block;}',
            '.pn-menu-list button{display:block;width:100%;text-align:left;background:none;border:none;padding:9px 14px;font-size:13px;color:#374151;cursor:pointer;}',
            '.pn-menu-list button:hover{background:#f9fafb;}',
            '.pn-menu-list button.danger{color:#dc2626;}',
            '.pn-empty{text-align:center;color:#9ca3af;padding:36px;}',
            /* conversation modal */
            '.pn-thread-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:4000;display:none;align-items:center;justify-content:center;padding:20px;}',
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
            '.pn-thread-note{background:#fff;border-radius:10px;padding:12px 14px;align-self:stretch;max-width:100%;border-left:4px solid #4f46e5;}',
            '.pn-readonly{padding:10px;text-align:center;color:#6b7280;font-size:12.5px;}'
        ].join('\n');
        document.head.appendChild(css);
    }

    // ── Panel ────────────────────────────────────────────────────────────────
    function load(projectId) {
        injectCss();
        state.projectId = projectId;
        var host = document.getElementById('project-notes-tab');
        if (!host) return;
        host.innerHTML = '<div class="pn-wrap"><div class="pn-empty">Loading notes…</div></div>';

        api('members', { project_id: projectId }).then(function (d) {
            state.members = (d && d.success) ? d.members : [];
            renderPanel(host);
            refreshList();
        });
    }

    function renderPanel(host) {
        var shareRows = state.members.filter(function (m) { return !m.is_me; }).map(function (m) {
            return '<label class="pn-share-row">' +
                '<input type="checkbox" class="pn-share-user" value="' + m.id + '"> ' +
                avatarHtml(m.avatar, m.name, 24) + ' <span>' + esc(m.name) + '</span>' +
                '<select class="pn-share-perm" data-user="' + m.id + '"><option value="view">Can view</option><option value="reply">Can reply</option></select>' +
                '</label>';
        }).join('');

        host.innerHTML =
            '<div class="pn-wrap">' +
              '<div class="pn-composer">' +
                '<textarea id="pn-body" placeholder="Write a note for this project…"></textarea>' +
                '<div class="pn-row pn-vis">' +
                  '<span style="font-size:13px;color:#6b7280;font-weight:600;">Visibility:</span>' +
                  '<label><input type="radio" name="pn-vis" value="private" checked> Private</label>' +
                  '<label><input type="radio" name="pn-vis" value="shared"> Shared</label>' +
                  '<button class="pn-btn" id="pn-post" style="margin-left:auto;"><i class="fas fa-paper-plane"></i> Post note</button>' +
                '</div>' +
                '<div class="pn-share-box" id="pn-share-box">' +
                  '<div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Share with project members:</div>' +
                  (shareRows || '<div style="font-size:13px;color:#9ca3af;">No other members in this project.</div>') +
                '</div>' +
              '</div>' +
              '<div class="pn-row" style="justify-content:space-between;margin:0 4px 12px;">' +
                '<div style="font-weight:700;color:#111827;">Notes</div>' +
                '<label style="font-size:13px;color:#6b7280;"><input type="checkbox" id="pn-show-archived"> Show archived</label>' +
              '</div>' +
              '<div id="pn-list"></div>' +
            '</div>';

        host.querySelectorAll('input[name="pn-vis"]').forEach(function (r) {
            r.addEventListener('change', function () {
                host.querySelector('#pn-share-box').classList.toggle('open', this.value === 'shared');
            });
        });
        host.querySelector('#pn-post').addEventListener('click', postNote);
        host.querySelector('#pn-show-archived').addEventListener('change', function () {
            state.showArchived = this.checked; refreshList();
        });
    }

    function collectShares(scope) {
        var shares = [];
        scope.querySelectorAll('.pn-share-user:checked').forEach(function (cb) {
            var perm = scope.querySelector('.pn-share-perm[data-user="' + cb.value + '"]');
            shares.push({ user_id: parseInt(cb.value, 10), permission: perm ? perm.value : 'view' });
        });
        return shares;
    }

    function postNote() {
        var host = document.getElementById('project-notes-tab');
        var body = host.querySelector('#pn-body').value.trim();
        if (!body) { host.querySelector('#pn-body').focus(); return; }
        var vis = host.querySelector('input[name="pn-vis"]:checked').value;
        var btn = host.querySelector('#pn-post');
        btn.disabled = true;
        var params = { project_id: state.projectId, body: body, visibility: vis };
        if (vis === 'shared') params.shares = JSON.stringify(collectShares(host));
        api('create', params).then(function (d) {
            btn.disabled = false;
            if (!d.success) { Swal.fire('Error', d.message || 'Could not post note', 'error'); return; }
            host.querySelector('#pn-body').value = '';
            host.querySelectorAll('.pn-share-user:checked').forEach(function (cb) { cb.checked = false; });
            refreshList();
        });
    }

    function refreshList() {
        var list = document.getElementById('pn-list');
        if (!list) return;
        api('list', { project_id: state.projectId, include_archived: state.showArchived ? 1 : '' }).then(function (d) {
            if (!d.success) { list.innerHTML = '<div class="pn-empty">' + esc(d.message || 'Could not load notes') + '</div>'; return; }
            if (!d.notes.length) { list.innerHTML = '<div class="pn-empty">No notes yet. Write the first one above.</div>'; return; }
            list.innerHTML = d.notes.map(renderNoteCard).join('');
            bindNoteCards(list);
        });
    }

    function renderNoteCard(n) {
        var badge = n.status === 'archived'
            ? '<span class="pn-badge arch">Archived</span>'
            : '<span class="pn-badge ' + n.visibility + '">' + n.visibility + '</span>';
        var menu = n.can_manage ? (
            '<div class="pn-menu"><button class="pn-menu-btn" data-menu="' + n.id + '">&#8942;</button>' +
            '<div class="pn-menu-list" data-menu-list="' + n.id + '">' +
              (n.can_edit ? '<button data-act="edit" data-id="' + n.id + '">Edit</button>' : '') +
              '<button data-act="share" data-id="' + n.id + '">Share…</button>' +
              '<button data-act="attach" data-id="' + n.id + '">Attach to task…</button>' +
              '<button data-act="move" data-id="' + n.id + '">Move to project…</button>' +
              (n.status === 'archived'
                ? '<button data-act="unarchive" data-id="' + n.id + '">Unarchive</button>'
                : '<button data-act="archive" data-id="' + n.id + '">Archive</button>') +
              '<button class="danger" data-act="delete" data-id="' + n.id + '">Delete</button>' +
            '</div></div>'
        ) : '';

        return '<div class="pn-note' + (n.status === 'archived' ? ' archived' : '') + '" data-note="' + n.id + '">' +
            '<div class="pn-note-head">' +
              avatarHtml(n.author_avatar, n.author_name, 34) +
              '<div class="pn-note-meta"><div class="pn-note-author">' + esc(n.author_name) + (n.is_mine ? ' <span style="color:#9ca3af;font-weight:400;">(you)</span>' : '') + '</div>' +
              '<div class="pn-note-time">' + fmtTime(n.created_at) + (n.edited_at ? ' · edited' : '') + '</div></div>' +
              badge + menu +
            '</div>' +
            '<div class="pn-note-body" data-body="' + n.id + '">' + nl2br(n.body) + '</div>' +
            '<div class="pn-note-foot">' +
              '<button class="pn-link" data-act="open" data-id="' + n.id + '"><i class="fas fa-comments"></i> ' +
                (n.reply_count > 0 ? (n.reply_count + (n.reply_count === 1 ? ' reply' : ' replies')) : 'Open conversation') + '</button>' +
            '</div>' +
        '</div>';
    }

    function bindNoteCards(list) {
        list.querySelectorAll('.pn-menu-btn').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = this.getAttribute('data-menu');
                var m = list.querySelector('.pn-menu-list[data-menu-list="' + id + '"]');
                var wasOpen = m.classList.contains('open');
                list.querySelectorAll('.pn-menu-list').forEach(function (x) { x.classList.remove('open'); });
                if (!wasOpen) m.classList.add('open');
            });
        });
        list.querySelectorAll('[data-act]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var act = this.getAttribute('data-act');
                var id = parseInt(this.getAttribute('data-id'), 10);
                if (act === 'open') openThread(id);
                else if (act === 'edit') editNoteInline(id);
                else if (act === 'share') shareDialog(id);
                else if (act === 'attach') attachTaskDialog(id);
                else if (act === 'move') moveDialog(id);
                else if (act === 'archive') simpleAction('archive', id);
                else if (act === 'unarchive') simpleAction('unarchive', id);
                else if (act === 'delete') deleteNote(id);
            });
        });
    }
    document.addEventListener('click', function () {
        document.querySelectorAll('.pn-menu-list.open').forEach(function (x) { x.classList.remove('open'); });
    });

    function simpleAction(action, id) {
        api(action, { note_id: id }).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Action failed', 'error'); return; }
            refreshList();
        });
    }

    function deleteNote(id) {
        Swal.fire({
            title: 'Delete this note?', text: 'This removes the note and its conversation.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete'
        }).then(function (r) { if (r.isConfirmed) simpleAction('delete', id); });
    }

    function editNoteInline(id) {
        var card = document.querySelector('.pn-note[data-note="' + id + '"]');
        if (!card) return;
        var bodyEl = card.querySelector('[data-body="' + id + '"]');
        var current = bodyEl.innerText;
        bodyEl.innerHTML = '<textarea style="width:100%;min-height:70px;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:14px;box-sizing:border-box;">' + esc(current) + '</textarea>' +
            '<div style="margin-top:8px;display:flex;gap:8px;"><button class="pn-btn" data-save="' + id + '">Save</button><button class="pn-btn-light" data-cancel="' + id + '">Cancel</button></div>';
        bodyEl.querySelector('[data-save]').addEventListener('click', function () {
            var val = bodyEl.querySelector('textarea').value.trim();
            api('update', { note_id: id, body: val }).then(function (d) {
                if (!d.success) { Swal.fire('Error', d.message || 'Could not save', 'error'); return; }
                refreshList();
            });
        });
        bodyEl.querySelector('[data-cancel]').addEventListener('click', refreshList);
    }

    function shareDialog(id) {
        api('get', { note_id: id }).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Could not load note', 'error'); return; }
            var note = d.note;
            var sharedMap = {};
            (note.shares || []).forEach(function (s) { sharedMap[s.user_id] = s.permission; });
            var rows = state.members.filter(function (m) { return !m.is_me; }).map(function (m) {
                var checked = sharedMap[m.id] ? 'checked' : '';
                var perm = sharedMap[m.id] || 'view';
                return '<label class="pn-share-row" style="justify-content:flex-start;">' +
                    '<input type="checkbox" class="sw-user" value="' + m.id + '" ' + checked + '> ' + esc(m.name) +
                    '<select class="sw-perm" data-user="' + m.id + '"><option value="view"' + (perm === 'view' ? ' selected' : '') + '>Can view</option><option value="reply"' + (perm === 'reply' ? ' selected' : '') + '>Can reply</option></select>' +
                    '</label>';
            }).join('') || '<div style="color:#9ca3af;">No other members in this project.</div>';

            Swal.fire({
                title: 'Share note',
                html: '<div style="text-align:left;">' +
                        '<label style="display:block;margin-bottom:10px;font-size:14px;"><input type="radio" name="sw-vis" value="private" ' + (note.visibility === 'private' ? 'checked' : '') + '> Private (only me)</label>' +
                        '<label style="display:block;margin-bottom:10px;font-size:14px;"><input type="radio" name="sw-vis" value="shared" ' + (note.visibility === 'shared' ? 'checked' : '') + '> Shared with:</label>' +
                        '<div id="sw-box" style="max-height:220px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">' + rows + '</div>' +
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
                    var vis = pop.querySelector('input[name="sw-vis"]:checked').value;
                    var shares = [];
                    pop.querySelectorAll('.sw-user:checked').forEach(function (cb) {
                        var perm = pop.querySelector('.sw-perm[data-user="' + cb.value + '"]');
                        shares.push({ user_id: parseInt(cb.value, 10), permission: perm ? perm.value : 'view' });
                    });
                    return { vis: vis, shares: shares };
                }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                api('share', { note_id: id, visibility: res.value.vis, shares: JSON.stringify(res.value.shares) }).then(function (d) {
                    if (!d.success) { Swal.fire('Error', d.message || 'Could not update sharing', 'error'); return; }
                    refreshList();
                });
            });
        });
    }

    function attachTaskDialog(id) {
        api('tasks', { project_id: state.projectId }).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Could not load tasks', 'error'); return; }
            var opts = '<option value="">— none (detach) —</option>' + d.tasks.map(function (t) {
                return '<option value="' + t.id + '">' + esc(t.name) + '</option>';
            }).join('');
            Swal.fire({
                title: 'Attach to task',
                html: '<select id="pn-task-sel" class="swal2-select" style="width:100%;">' + opts + '</select>',
                showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#4f46e5',
                preConfirm: function () { return document.getElementById('pn-task-sel').value; }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                api('attach_task', { note_id: id, task_id: res.value || 0 }).then(function (d2) {
                    if (!d2.success) { Swal.fire('Error', d2.message || 'Could not attach', 'error'); return; }
                    Swal.fire({ icon: 'success', title: res.value ? 'Attached to task' : 'Detached', timer: 1400, showConfirmButton: false });
                });
            });
        });
    }

    function moveDialog(id) {
        api('projects', {}).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Could not load projects', 'error'); return; }
            var opts = d.projects.filter(function (p) { return p.id !== state.projectId; }).map(function (p) {
                return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
            }).join('');
            if (!opts) { Swal.fire('No other projects', 'You are not a member of another project to move this to.', 'info'); return; }
            Swal.fire({
                title: 'Move note to project',
                html: '<select id="pn-proj-sel" class="swal2-select" style="width:100%;">' + opts + '</select>' +
                      '<p style="font-size:12px;color:#6b7280;margin-top:10px;">The note is detached from any task and unshared from members who aren\'t in the new project.</p>',
                showCancelButton: true, confirmButtonText: 'Move', confirmButtonColor: '#4f46e5',
                preConfirm: function () { return document.getElementById('pn-proj-sel').value; }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                api('reassign', { note_id: id, target_project_id: res.value }).then(function (d2) {
                    if (!d2.success) { Swal.fire('Error', d2.message || 'Could not move note', 'error'); return; }
                    Swal.fire({ icon: 'success', title: 'Note moved', timer: 1400, showConfirmButton: false });
                    refreshList();
                });
            });
        });
    }

    // ── Conversation (WhatsApp-style) ─────────────────────────────────────────
    function ensureThreadModal() {
        var ov = document.getElementById('pn-thread-overlay');
        if (ov) return ov;
        ov = document.createElement('div');
        ov.id = 'pn-thread-overlay';
        ov.className = 'pn-thread-overlay';
        ov.innerHTML =
            '<div class="pn-thread">' +
              '<div class="pn-thread-head"><i class="fas fa-comments"></i><h3 id="pn-thread-title">Conversation</h3>' +
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
    }

    function openThread(noteId) {
        var ov = ensureThreadModal();
        ov.classList.add('open');
        state.openNoteId = noteId;
        renderThread();
    }

    function renderThread() {
        var noteId = state.openNoteId;
        api('get', { note_id: noteId }).then(function (d) {
            if (!d.success) { closeThread(); Swal.fire('Error', d.message || 'Could not open conversation', 'error'); return; }
            if (state.openNoteId !== noteId) return;
            var note = d.note;
            document.getElementById('pn-thread-title').textContent = 'Note by ' + note.author_name;
            var body = document.getElementById('pn-thread-body');

            var html = '<div class="pn-thread-note"><div class="pn-msg-author">' + esc(note.author_name) + '</div>' +
                '<div style="white-space:pre-wrap;word-break:break-word;font-size:14px;color:#111827;">' + nl2br(note.body) + '</div>' +
                '<div class="pn-msg-meta" style="text-align:left;">' + fmtTime(note.created_at) + (note.edited_at ? ' · edited' : '') + '</div></div>';

            html += note.replies.map(function (r) {
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
            if (!d.success) { Swal.fire('Error', d.message || 'Could not send', 'error'); return; }
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
                if (!d.success) { Swal.fire('Error', d.message || 'Could not save', 'error'); return; }
                renderThread();
            });
        });
        textEl.querySelector('.pn-msg-cancel').addEventListener('click', renderThread);
    }

    window.ProjectNotes = { load: load };
})();
