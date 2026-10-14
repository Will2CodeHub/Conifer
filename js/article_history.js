/* =========================================================================
   Article edit history (versioning) UI.
   Senior roles only (the History button is only rendered when
   window.TEN_canViewHistory; the endpoint ajax/article_revisions.php also
   gates server-side).

   Pick any TWO versions (or "Current (live)") from the left list — one for the
   LEFT column, one for the RIGHT — and see them side by side with differences
   highlighted (changed fields tinted; the body shown as a word-level diff).
   Each version column has its own "Restore to this version" button.
   ========================================================================= */
(function () {
    if (!window.TEN_canViewHistory) { return; }

    var ENDPOINT = '/management/ajax/article_revisions.php';

    // The history overlay sits at z-index 100000; SweetAlert2's container defaults
    // to 1060, so its dialogs would render BEHIND the overlay. Lift it above.
    (function () {
        if (document.getElementById('ten_hist_swal_z')) { return; }
        var st = document.createElement('style');
        st.id = 'ten_hist_swal_z';
        st.textContent = '.swal2-container{z-index:200000 !important;}';
        document.head.appendChild(st);
    })();

    var FIELD_LABELS = {
        title: 'Title', alias: 'Alias / pen name', tags: 'Tags', section: 'Section',
        section_subcat: 'Sub-category', journalist_id: 'Author (id)', publications: 'Publications',
        canonical: 'Canonical', meta_title: 'Meta title', meta_description: 'Meta description',
        meta_keywords: 'Meta keywords', evergreen: 'Evergreen', featured: 'Section headline',
        sponsored: 'Sponsored', frontpage_temp: 'Front-page headline', note: 'Internal note',
        created_by: 'Byline name', publish_now: 'Publish now', publish_from: 'Publish from',
        publish_to: 'Publish to', state: 'State', pub_flags: 'Per-publication flags'
    };
    // Fields shown in the compact field-comparison table (body handled separately).
    var TABLE_FIELDS = ['title', 'state', 'journalist_id', 'created_by', 'section', 'section_subcat',
        'alias', 'tags', 'publications', 'canonical', 'frontpage_temp', 'featured', 'sponsored',
        'evergreen', 'publish_now', 'publish_from', 'publish_to', 'meta_title', 'meta_description',
        'meta_keywords', 'note', 'pub_flags'];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function stateBadge(state) {
        var bg = '#64748b', col = '#fff', label = state || '—';
        if (state === 'draft') { bg = '#fee2e2'; col = '#991b1b'; label = 'Draft'; }
        else if (state === 'under review') { bg = '#fef3c7'; col = '#92400e'; label = 'In Review'; }
        else if (state === 'published') { bg = '#dcfce7'; col = '#166534'; label = 'Published'; }
        return '<span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;">' + esc(label) + '</span>';
    }
    function fmt(dt) {
        if (!dt) return '';
        try {
            var d = new Date(String(dt).replace(' ', 'T'));
            if (isNaN(d.getTime())) return esc(dt);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
                ', ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
        } catch (e) { return esc(dt); }
    }
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = String(html == null ? '' : html);
        return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
    }
    function fieldValueText(key, val) {
        if (key === 'pub_flags') {
            if (!val || typeof val !== 'object') return '(none)';
            var parts = Object.keys(val).map(function (p) {
                var f = val[p] || {}, on = [];
                if (f.frontpage) on.push('front'); if (f.section_head) on.push('section'); if (f.sponsored) on.push('sponsored');
                return p + ': ' + (on.length ? on.join('+') : 'none');
            });
            return parts.join('; ') || '(none)';
        }
        return String(val == null ? '' : val);
    }

    // ---- word-level diff (LCS) --------------------------------------------
    var DIFF_TOKEN_CAP = 2200; // guard the O(n*m) matrix on very long bodies
    function tokenize(text) {
        var t = String(text == null ? '' : text).trim();
        if (t === '') return [];
        return t.split(/(\s+)/).filter(function (x) { return x.length > 0; });
    }
    function lcsOps(a, b) {
        var n = a.length, m = b.length;
        var dp = [];
        for (var i = 0; i <= n; i++) { dp.push(new Int32Array(m + 1)); }
        for (var i2 = n - 1; i2 >= 0; i2--) {
            for (var j = m - 1; j >= 0; j--) {
                dp[i2][j] = (a[i2] === b[j]) ? dp[i2 + 1][j + 1] + 1 : Math.max(dp[i2 + 1][j], dp[i2][j + 1]);
            }
        }
        var ops = [], i3 = 0, j2 = 0;
        while (i3 < n && j2 < m) {
            if (a[i3] === b[j2]) { ops.push({ t: 'eq', v: a[i3] }); i3++; j2++; }
            else if (dp[i3 + 1][j2] >= dp[i3][j2 + 1]) { ops.push({ t: 'del', v: a[i3] }); i3++; }
            else { ops.push({ t: 'ins', v: b[j2] }); j2++; }
        }
        while (i3 < n) { ops.push({ t: 'del', v: a[i3++] }); }
        while (j2 < m) { ops.push({ t: 'ins', v: b[j2++] }); }
        return ops;
    }
    function bodyDiffHtml(leftText, rightText) {
        var la = tokenize(leftText), lb = tokenize(rightText);
        if (la.length > DIFF_TOKEN_CAP || lb.length > DIFF_TOKEN_CAP) {
            var changed = (leftText !== rightText);
            var wrap = function (s) { return '<div style="white-space:pre-wrap;">' + (esc(s) || '<em>(empty)</em>') + '</div>'; };
            return {
                left: (changed ? '<div style="font-size:11px;color:#92400e;margin-bottom:6px;">Too long for word-level highlighting — shown in full.</div>' : '') + wrap(leftText),
                right: wrap(rightText)
            };
        }
        var ops = lcsOps(la, lb);
        var L = '', R = '';
        var delBg = 'background:#fecaca;color:#7f1d1d;', insBg = 'background:#bbf7d0;color:#14532d;';
        ops.forEach(function (op) {
            if (op.t === 'eq') { L += esc(op.v); R += esc(op.v); }
            else if (op.t === 'del') { L += '<mark style="' + delBg + 'border-radius:2px;">' + esc(op.v) + '</mark>'; }
            else { R += '<mark style="' + insBg + 'border-radius:2px;">' + esc(op.v) + '</mark>'; }
        });
        return {
            left: '<div style="white-space:pre-wrap;">' + (L || '<em>(empty)</em>') + '</div>',
            right: '<div style="white-space:pre-wrap;">' + (R || '<em>(empty)</em>') + '</div>'
        };
    }

    // ---- state ------------------------------------------------------------
    var overlay = null, articleId = 0, revisions = [], snapCache = {}, currentSnap = null;
    // Each side is {kind:'current'} or {kind:'rev', id:<n>}.
    var sides = { left: null, right: null };

    function closeOverlay() { if (overlay) { overlay.remove(); overlay = null; } }
    function selKey(sel) { return sel ? (sel.kind === 'current' ? 'current' : 'rev:' + sel.id) : ''; }
    function selSnap(sel) {
        if (!sel) return null;
        return sel.kind === 'current' ? currentSnap : (snapCache[sel.id] || null);
    }
    function revMeta(id) { for (var i = 0; i < revisions.length; i++) { if (revisions[i].id === id) return revisions[i]; } return null; }
    function sideLabel(sel) {
        if (!sel) return '—';
        if (sel.kind === 'current') return 'Current (live)';
        var m = revMeta(sel.id);
        return m ? (esc(m.edited_by_name || 'Unknown') + ' · ' + fmt(m.edited_at)) : ('Version ' + sel.id);
    }

    window.openHistory = function (id) {
        articleId = id;
        revisions = []; snapCache = {}; currentSnap = null; sides = { left: null, right: null };
        closeOverlay();
        overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:18px;';
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(); });
        overlay.innerHTML =
            '<div style="background:#fff;border-radius:12px;width:100%;max-width:1200px;height:86vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.3);overflow:hidden;">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e5e7eb;">' +
                    '<div style="font-size:16px;font-weight:700;color:#1e293b;"><i class="fas fa-clock-rotate-left" style="color:#8b5cf6;margin-right:8px;"></i>Edit history — Article #' + esc(id) + '</div>' +
                    '<button type="button" id="ten_hist_close" style="border:none;background:#f1f5f9;color:#334155;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:16px;">&times;</button>' +
                '</div>' +
                '<div style="flex:1;display:flex;min-height:0;">' +
                    '<div style="width:330px;border-right:1px solid #e5e7eb;overflow:auto;background:#f8fafc;display:flex;flex-direction:column;">' +
                        '<div style="padding:10px 14px;font-size:11px;color:#64748b;border-bottom:1px solid #eef2f7;background:#fff;">Choose a version for the <strong style="color:#2563eb;">Left</strong> and <strong style="color:#16a34a;">Right</strong> column to compare them.</div>' +
                        '<div id="ten_hist_list" style="flex:1;overflow:auto;"><div style="padding:16px;color:#64748b;font-size:13px;">Loading…</div></div>' +
                    '</div>' +
                    '<div id="ten_hist_compare" style="flex:1;overflow:auto;padding:0;background:#fff;">' +
                        '<div style="padding:20px;color:#94a3b8;font-size:13px;">Loading…</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.getElementById('ten_hist_close').addEventListener('click', closeOverlay);

        // Load the version list and the current snapshot in parallel.
        Promise.all([
            fetch(ENDPOINT + '?action=list&article_id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
            fetch(ENDPOINT + '?action=current&article_id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        ]).then(function (res) {
            var listJson = res[0], curJson = res[1];
            if (!listJson || listJson.status !== 'success') {
                document.getElementById('ten_hist_list').innerHTML = '<div style="padding:16px;color:#dc2626;font-size:13px;">' + esc((listJson && listJson.message) || 'Failed to load history.') + '</div>';
                return;
            }
            currentSnap = (curJson && curJson.status === 'success') ? curJson.snapshot : null;
            revisions = listJson.revisions || [];
            if (!revisions.length) {
                document.getElementById('ten_hist_list').innerHTML = '<div style="padding:16px;color:#64748b;font-size:13px;">No history yet. Versions are recorded from the next save onward.</div>';
                document.getElementById('ten_hist_compare').innerHTML = '<div style="padding:20px;color:#94a3b8;font-size:13px;">Nothing to compare yet.</div>';
                return;
            }
            // Default: newest version on the left, Current on the right.
            sides.left = { kind: 'rev', id: revisions[0].id };
            sides.right = { kind: 'current' };
            ensureSnap(sides.left).then(function () { renderList(); renderCompare(); });
        }).catch(function () {
            document.getElementById('ten_hist_list').innerHTML = '<div style="padding:16px;color:#dc2626;font-size:13px;">Network error loading history.</div>';
        });
    };

    function ensureSnap(sel) {
        if (!sel || sel.kind === 'current') return Promise.resolve();
        if (snapCache[sel.id]) return Promise.resolve();
        return fetch(ENDPOINT + '?action=get&article_id=' + encodeURIComponent(articleId) + '&rev=' + encodeURIComponent(sel.id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.status === 'success') {
                    snapCache[sel.id] = json.snapshot || {};
                    if (!currentSnap && json.current) currentSnap = json.current;
                }
            });
    }

    function assign(side, sel) {
        // Don't let both sides be the exact same version.
        var other = side === 'left' ? 'right' : 'left';
        if (selKey(sides[other]) === selKey(sel)) { sides[other] = sides[side]; }
        sides[side] = sel;
        ensureSnap(sel).then(function () { renderList(); renderCompare(); });
    }

    function sideButtons(sel) {
        var isLeft = selKey(sides.left) === selKey(sel);
        var isRight = selKey(sides.right) === selKey(sel);
        var btn = function (side, label, active, activeBg) {
            return '<button type="button" data-side="' + side + '" data-sel="' + esc(selKey(sel)) + '" ' +
                'style="border:1px solid ' + (active ? activeBg : '#cbd5e1') + ';background:' + (active ? activeBg : '#fff') + ';color:' + (active ? '#fff' : '#475569') + ';padding:3px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:700;">' + label + '</button>';
        };
        return '<div style="display:flex;gap:6px;margin-top:6px;">' +
            btn('left', 'Left', isLeft, '#2563eb') + btn('right', 'Right', isRight, '#16a34a') + '</div>';
    }

    function rowShell(inner, sel) {
        var isLeft = selKey(sides.left) === selKey(sel);
        var isRight = selKey(sides.right) === selKey(sel);
        var border = isLeft ? '#2563eb' : (isRight ? '#16a34a' : 'transparent');
        var bg = isLeft ? '#eff6ff' : (isRight ? '#f0fdf4' : '#fff');
        return '<div style="padding:10px 14px;border-bottom:1px solid #eef2f7;border-left:4px solid ' + border + ';background:' + bg + ';">' + inner + '</div>';
    }

    function renderList() {
        var list = document.getElementById('ten_hist_list');
        if (!list) return;
        var html = '';
        // Pinned "Current (live)" option.
        var curSel = { kind: 'current' };
        html += rowShell(
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
                '<span style="font-size:12px;font-weight:700;color:#0f172a;"><i class="fas fa-circle" style="color:#0ea5e9;font-size:8px;margin-right:6px;"></i>Current (live article)</span>' +
            '</div>' + sideButtons(curSel), curSel);
        // Revisions newest-first.
        revisions.forEach(function (rev, i) {
            var sel = { kind: 'rev', id: rev.id };
            var restoreTag = rev.action === 'restore'
                ? '<span style="background:#ede9fe;color:#6d28d9;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:700;margin-left:6px;">RESTORED</span>' : '';
            html += rowShell(
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
                    '<span style="font-size:12px;font-weight:600;color:#1e293b;">' + esc(rev.edited_by_name || 'Unknown') + restoreTag + '</span>' +
                    stateBadge(rev.state) +
                '</div>' +
                '<div style="font-size:11px;color:#64748b;margin-top:3px;">' + fmt(rev.edited_at) + (i === 0 ? ' · latest' : '') + '</div>' +
                sideButtons(sel), sel);
        });
        list.innerHTML = html;
        // Wire the Left/Right buttons.
        Array.prototype.forEach.call(list.querySelectorAll('button[data-side]'), function (b) {
            b.addEventListener('click', function () {
                var side = b.getAttribute('data-side');
                var key = b.getAttribute('data-sel');
                var sel = key === 'current' ? { kind: 'current' } : { kind: 'rev', id: parseInt(key.split(':')[1], 10) };
                assign(side, sel);
            });
        });
    }

    function columnHeader(sel, accent) {
        var isRev = sel && sel.kind === 'rev';
        var meta = isRev ? revMeta(sel.id) : null;
        var restoreBtn = isRev
            ? '<button type="button" class="ten_hist_restore" data-rev="' + sel.id + '" data-state="' + esc(meta ? meta.state : '') + '" style="border:none;background:#8b5cf6;color:#fff;padding:7px 12px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;"><i class="fas fa-rotate-left" style="margin-right:5px;"></i>Restore to this version</button>'
            : '<span style="font-size:11px;color:#64748b;font-style:italic;">current article</span>';
        return '<div style="border-top:3px solid ' + accent + ';background:#f8fafc;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;position:sticky;top:0;">' +
            '<div style="min-width:0;">' +
                '<div style="font-size:12px;font-weight:700;color:' + accent + ';text-transform:uppercase;letter-spacing:.04em;">' + (accent === '#2563eb' ? 'Left' : 'Right') + '</div>' +
                '<div style="font-size:13px;font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(sideLabel(sel)) + '</div>' +
            '</div>' + restoreBtn + '</div>';
    }

    function renderCompare() {
        var pane = document.getElementById('ten_hist_compare');
        if (!pane) return;
        var sa = selSnap(sides.left), sb = selSnap(sides.right);
        if (!sides.left || !sides.right) { pane.innerHTML = '<div style="padding:20px;color:#94a3b8;font-size:13px;">Pick a version for each column.</div>'; return; }
        if (!sa || !sb) { pane.innerHTML = '<div style="padding:20px;color:#64748b;font-size:13px;">Loading versions…</div>'; return; }

        // Field table (only rows that exist; changed rows tinted).
        var tableRows = '';
        TABLE_FIELDS.forEach(function (key) {
            var lv = fieldValueText(key, sa[key]);
            var rv = fieldValueText(key, sb[key]);
            if (lv === '' && rv === '') return;
            var changed = (lv !== rv);
            var cellBaseL = 'padding:6px 10px;font-size:12px;vertical-align:top;border-bottom:1px solid #f1f5f9;word-break:break-word;';
            var tintL = changed ? 'background:#fef2f2;color:#7f1d1d;' : 'color:#334155;';
            var tintR = changed ? 'background:#f0fdf4;color:#14532d;' : 'color:#334155;';
            tableRows +=
                '<tr>' +
                    '<td style="padding:6px 10px;font-size:11px;font-weight:700;color:#64748b;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:top;">' + esc(FIELD_LABELS[key] || key) + (changed ? ' <span style="color:#d97706;">●</span>' : '') + '</td>' +
                    '<td style="' + cellBaseL + tintL + '">' + (esc(lv) || '<em style="color:#94a3b8;">(empty)</em>') + '</td>' +
                    '<td style="' + cellBaseL + tintR + '">' + (esc(rv) || '<em style="color:#94a3b8;">(empty)</em>') + '</td>' +
                '</tr>';
        });

        // Body word-level diff.
        var bd = bodyDiffHtml(stripHtml(sa.article_text), stripHtml(sb.article_text));
        var bodyChanged = stripHtml(sa.article_text) !== stripHtml(sb.article_text);

        pane.innerHTML =
            '<div style="display:flex;min-height:0;">' +
                '<div style="flex:1;border-right:1px solid #e5e7eb;">' + columnHeader(sides.left, '#2563eb') + '</div>' +
                '<div style="flex:1;">' + columnHeader(sides.right, '#16a34a') + '</div>' +
            '</div>' +
            '<div style="padding:14px 16px;">' +
                '<div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">Fields</div>' +
                '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">' +
                    '<colgroup><col style="width:130px;"><col><col></colgroup>' +
                    '<thead><tr>' +
                        '<th style="text-align:left;padding:4px 10px;font-size:10px;color:#94a3b8;text-transform:uppercase;"></th>' +
                        '<th style="text-align:left;padding:4px 10px;font-size:10px;color:#2563eb;text-transform:uppercase;">Left</th>' +
                        '<th style="text-align:left;padding:4px 10px;font-size:10px;color:#16a34a;text-transform:uppercase;">Right</th>' +
                    '</tr></thead><tbody>' + tableRows + '</tbody>' +
                '</table>' +
                '<div style="font-size:12px;font-weight:700;color:#334155;margin:18px 0 6px;">Body ' +
                    (bodyChanged ? '<span style="font-weight:500;color:#64748b;">— <mark style="background:#fecaca;">removed</mark> / <mark style="background:#bbf7d0;">added</mark></span>' : '<span style="font-weight:500;color:#16a34a;">— identical</span>') +
                '</div>' +
                '<div style="display:flex;gap:12px;align-items:flex-start;">' +
                    '<div style="flex:1;font-size:13px;line-height:1.6;color:#334155;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:12px;">' + bd.left + '</div>' +
                    '<div style="flex:1;font-size:13px;line-height:1.6;color:#334155;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:12px;">' + bd.right + '</div>' +
                '</div>' +
            '</div>';

        Array.prototype.forEach.call(pane.querySelectorAll('.ten_hist_restore'), function (b) {
            b.addEventListener('click', function () {
                confirmRestore(articleId, parseInt(b.getAttribute('data-rev'), 10), b.getAttribute('data-state'));
            });
        });
    }

    function confirmRestore(aid, revId, state) {
        var warnPublished = (state === 'published')
            ? ' The restored version is <strong>published</strong>, so the live site will be rebuilt to match.'
            : '';
        Swal.fire({
            title: 'Restore this version?',
            html: 'This overwrites the current article with the selected version.' + warnPublished +
                  '<br><br>The current article is itself saved in history first, so you can undo this.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#8b5cf6',
            confirmButtonText: 'Restore', cancelButtonText: 'Cancel'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            var fd = new FormData();
            fd.append('action', 'restore');
            fd.append('article_id', aid);
            fd.append('rev', revId);
            fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.status === 'success') {
                        Swal.fire({ title: 'Restored', text: 'The article was restored to the selected version.', icon: 'success', timer: 1800, showConfirmButton: false });
                        closeOverlay();
                        if (typeof window.loadArticles === 'function') { window.loadArticles(); }
                    } else {
                        Swal.fire('Restore failed', (json && json.message) || 'Unknown error', 'error');
                    }
                })
                .catch(function () { Swal.fire('Restore failed', 'Network error', 'error'); });
        });
    }

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOverlay(); });
})();
