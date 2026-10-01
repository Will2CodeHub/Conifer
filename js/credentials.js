/* Password Manager (credential vault) — admin-only UI. Secrets are fetched (decrypted)
   only on demand via action=reveal; the list never carries them. */
(function () {
    'use strict';
    var EP = 'ajax/credentials.php';
    var CATS = { email: 'Email accounts', social: 'Social / other' };
    var items = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(params) {
        return fetch(EP, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params) }).then(function (r) { return r.json(); });
    }

    function load() {
        post({ action: 'list' }).then(function (d) {
            if (!d.success) { document.getElementById('credList').innerHTML = '<div class="cred-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
            items = d.items || [];
            render();
        });
    }

    function render() {
        var host = document.getElementById('credList');
        if (!items.length) { host.innerHTML = '<div class="cred-empty">No credentials saved yet. Click “Add credential”.</div>'; return; }
        var byCat = {};
        items.forEach(function (it) { (byCat[it.category] = byCat[it.category] || []).push(it); });
        var html = '';
        Object.keys(CATS).forEach(function (cat) {
            var list = byCat[cat]; if (!list || !list.length) return;
            html += '<div class="cred-cat"><h2>' + esc(CATS[cat]) + ' (' + list.length + ')</h2><div class="cred-grid">';
            list.forEach(function (it) { html += card(it); });
            html += '</div></div>';
        });
        // Any unknown category falls into a generic group.
        Object.keys(byCat).forEach(function (cat) {
            if (CATS[cat]) return;
            html += '<div class="cred-cat"><h2>' + esc(cat) + '</h2><div class="cred-grid">';
            byCat[cat].forEach(function (it) { html += card(it); });
            html += '</div></div>';
        });
        host.innerHTML = html;
    }

    function card(it) {
        return '<div class="cred-card" data-id="' + it.id + '">' +
            '<h3>' + esc(it.label) + '</h3>' +
            (it.username ? '<div class="cred-meta"><i class="fas fa-user"></i> ' + esc(it.username) + '</div>' : '') +
            (it.host ? '<div class="cred-meta"><i class="fas fa-server"></i> ' + esc(it.host) + '</div>' : '') +
            (it.url ? '<div class="cred-meta"><i class="fas fa-link"></i> <a href="' + esc(it.url) + '" target="_blank" rel="noopener">' + esc(it.url) + '</a></div>' : '') +
            (it.notes ? '<div class="cred-meta"><i class="fas fa-note-sticky"></i> ' + esc(it.notes) + '</div>' : '') +
            '<div class="cred-secret"><code id="sec-' + it.id + '">' + (it.has_secret ? '••••••••' : '<span style="color:#94a3b8;letter-spacing:0">no secret</span>') + '</code>' +
                (it.has_secret ? '<button class="icon-btn-sm" title="Reveal" onclick="credReveal(' + it.id + ')"><i class="fas fa-eye"></i></button>' +
                 '<button class="icon-btn-sm" title="Copy" onclick="credCopy(' + it.id + ')"><i class="fas fa-copy"></i></button>' : '') +
            '</div>' +
            '<div class="cred-actions">' +
                '<button class="icon-btn-sm" onclick="credEdit(' + it.id + ')"><i class="fas fa-pen"></i> Edit</button>' +
                '<button class="icon-btn-sm" onclick="credDelete(' + it.id + ')" style="color:#dc2626;"><i class="fas fa-trash-can"></i> Delete</button>' +
            '</div></div>';
    }

    function revealInto(id) {
        return post({ action: 'reveal', id: id }).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Could not reveal', 'error'); return null; }
            return d.secret;
        });
    }
    window.credReveal = function (id) {
        var code = document.getElementById('sec-' + id);
        if (code && code.dataset.shown === '1') { // toggle back to masked
            code.textContent = '••••••••'; code.dataset.shown = '0'; return;
        }
        revealInto(id).then(function (s) { if (s !== null && code) { code.textContent = s; code.dataset.shown = '1'; } });
    };
    window.credCopy = function (id) {
        revealInto(id).then(function (s) {
            if (s === null) return;
            navigator.clipboard.writeText(s).then(
                function () { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Copied', showConfirmButton: false, timer: 1200 }); },
                function () { Swal.fire('Copy failed', 'Copy manually: ' + s, 'info'); }
            );
        });
    };

    function fld(id) { return document.getElementById(id); }
    window.credOpen = function () {
        fld('credModalTitle').textContent = 'Add credential';
        fld('credId').value = ''; fld('credCategory').value = 'email';
        fld('credLabel').value = ''; fld('credUsername').value = ''; fld('credHost').value = '';
        fld('credUrl').value = ''; fld('credSecret').value = ''; fld('credNotes').value = '';
        fld('credSecretHint').textContent = '';
        document.getElementById('credModal').classList.add('active');
    };
    window.credEdit = function (id) {
        var it = items.filter(function (x) { return x.id === id; })[0]; if (!it) return;
        fld('credModalTitle').textContent = 'Edit credential';
        fld('credId').value = it.id; fld('credCategory').value = CATS[it.category] ? it.category : 'social';
        fld('credLabel').value = it.label || ''; fld('credUsername').value = it.username || '';
        fld('credHost').value = it.host || ''; fld('credUrl').value = it.url || '';
        fld('credSecret').value = ''; fld('credNotes').value = it.notes || '';
        fld('credSecretHint').textContent = it.has_secret ? '(leave blank to keep current)' : '';
        document.getElementById('credModal').classList.add('active');
    };
    window.credClose = function () { document.getElementById('credModal').classList.remove('active'); };

    window.credSave = function () {
        var label = fld('credLabel').value.trim();
        if (!label) { Swal.fire('Missing label', 'Please enter a label.', 'warning'); return; }
        var p = {
            action: 'save', id: fld('credId').value, category: fld('credCategory').value,
            label: label, username: fld('credUsername').value, host: fld('credHost').value,
            url: fld('credUrl').value, notes: fld('credNotes').value
        };
        // Only send the secret when one was typed (blank keeps the existing one on edit).
        var sec = fld('credSecret').value;
        if (sec !== '') p.secret = sec;
        else if (!fld('credId').value) p.secret = ''; // new row with no secret
        post(p).then(function (d) {
            if (!d.success) { Swal.fire('Error', d.message || 'Could not save', 'error'); return; }
            credClose(); load();
        });
    };

    window.credDelete = function (id) {
        var it = items.filter(function (x) { return x.id === id; })[0];
        Swal.fire({ title: 'Delete credential?', text: (it ? '“' + it.label + '”' : '') + ' will be permanently removed.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                post({ action: 'delete', id: id }).then(function (d) {
                    if (!d.success) { Swal.fire('Error', d.message || 'Could not delete', 'error'); return; }
                    load();
                });
            });
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
