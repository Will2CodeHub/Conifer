<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

if (!hasPermission('ticker.manage') && !isAdmin()) {
    header('Location: ' . getLandingUrl());
    exit();
}

$allPublications = [];
try {
    $connAdmin = getDBConnection_TENAdmin();
    if ($connAdmin) {
        $pubRes = $connAdmin->query("SELECT publication, title FROM publications WHERE pub_live = '1' ORDER BY title");
        while ($pubRes && ($r = $pubRes->fetch_assoc())) { $allPublications[] = $r; }
        $connAdmin->close();
    }
} catch (Throwable $e) {
    error_log('module-site-ticker: failed loading publications: ' . $e->getMessage());
}

$currentPage = 'site_ticker';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEN Site Ticker - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 30px; font-weight: 700; color: #111827; margin-bottom: 6px; }
        .page-header p { color: #6b7280; font-size: 14px; max-width: 820px; }
        .tk-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; margin-bottom: 20px; }
        .tk-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; margin-bottom: 6px; }
        .tk-field select { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; min-width: 260px; background: #fff; }
        .tk-spacer { flex: 1; }
        .btn-primary { background: #4f46e5; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background: #4338ca; }
        .btn-light { background: #fff; color: #374151; border: 1px solid #d1d5db; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; }
        .btn-light:hover { background: #f9fafb; }
        .tk-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .tk-legend { display: flex; gap: 20px; flex-wrap: wrap; padding: 12px 18px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12.5px; color: #6b7280; }
        .tk-legend b { color: #374151; }
        table.tk-table { width: 100%; border-collapse: collapse; }
        table.tk-table th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; padding: 10px 14px; border-bottom: 1px solid #e5e7eb; background: #fafafa; }
        table.tk-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: top; }
        table.tk-table tr:last-child td { border-bottom: none; }
        .tk-text { color: #111827; }
        .tk-text a { color: #4f46e5; }
        .tk-date { color: #6b7280; font-size: 12.5px; white-space: nowrap; }
        .tk-live { display: inline-block; font-size: 10.5px; font-weight: 700; color: #065f46; background: #d1fae5; padding: 1px 8px; border-radius: 999px; margin-top: 6px; }
        .tk-off { display: inline-block; font-size: 10.5px; font-weight: 600; color: #6b7280; background: #f3f4f6; padding: 1px 8px; border-radius: 999px; margin-top: 6px; }
        .tk-actions { display: flex; gap: 6px; justify-content: flex-end; }
        .tk-icon-btn { border: 1px solid #e5e7eb; background: #fff; border-radius: 7px; width: 32px; height: 32px; cursor: pointer; color: #6b7280; }
        .tk-icon-btn:hover { background: #f9fafb; color: #111827; }
        .tk-icon-btn.danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .tk-empty { padding: 40px; text-align: center; color: #9ca3af; }
        /* modal */
        .tk-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: none; align-items: flex-start; justify-content: center; z-index: 2000; overflow-y: auto; padding: 40px 16px; }
        .tk-modal-overlay.open { display: flex; }
        .tk-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 600px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .tk-modal-head { padding: 18px 22px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .tk-modal-head h2 { font-size: 18px; font-weight: 700; color: #111827; }
        .tk-modal-body { padding: 20px 22px; }
        .tk-modal-foot { padding: 16px 22px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .tk-form-group { margin-bottom: 16px; }
        .tk-form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .tk-form-group textarea, .tk-form-group input[type=datetime-local] { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: inherit; }
        .tk-form-group textarea { min-height: 90px; resize: vertical; }
        .tk-hint { font-size: 12px; color: #9ca3af; font-weight: 400; }
        .tk-counter { font-size: 12px; color: #9ca3af; text-align: right; margin-top: 4px; }
        .close-x { background: none; border: none; font-size: 22px; color: #9ca3af; cursor: pointer; line-height: 1; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-bullhorn"></i> TEN Site Ticker</h1>
                <p>Manage the scrolling <strong>Latest</strong> news ticker under each publication's title. The live site shows the <strong>6 most recent</strong> items (by date) for that publication &mdash; older items stay here but drop off the site. You can include a link using HTML, e.g. <code>&lt;a href="https://…"&gt;Read more&lt;/a&gt;</code>.</p>
            </div>

            <div id="alert-container"></div>

            <div class="tk-toolbar">
                <div class="tk-field">
                    <label for="tk_pub">Publication</label>
                    <select id="tk_pub">
                        <option value="">Choose a publication…</option>
                        <?php foreach ($allPublications as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['publication']); ?>"><?php echo htmlspecialchars($p['title']); ?> (<?php echo htmlspecialchars($p['publication']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tk-spacer"></div>
                <div class="tk-field">
                    <button class="btn-primary" onclick="openCreateTicker()"><i class="fas fa-plus"></i> Add ticker item</button>
                </div>
            </div>

            <div class="tk-card">
                <div class="tk-legend">
                    <span><span class="tk-live">LIVE</span> shows on the site now (newest 6)</span>
                    <span><span class="tk-off">off</span> older — kept but not shown</span>
                    <span>Ordering is by date &mdash; set a future/most-recent date to push an item to the top.</span>
                </div>
                <div id="tk_table_wrap">
                    <div class="tk-empty">Select a publication to manage its ticker.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create / Edit modal -->
    <div class="tk-modal-overlay" id="tk_modal">
        <div class="tk-modal">
            <div class="tk-modal-head">
                <h2 id="tk_modal_title">Add ticker item</h2>
                <button class="close-x" onclick="closeTicker()">&times;</button>
            </div>
            <div class="tk-modal-body">
                <input type="hidden" id="tk_edit_id" value="">
                <div class="tk-form-group">
                    <label>Ticker text <span style="color:#dc2626">*</span> <span class="tk-hint">(plain text, or include an &lt;a href&gt; link)</span></label>
                    <textarea id="tk_text" maxlength="500" oninput="tkCount()" placeholder="e.g. The Berlin Eye launches — read our first stories now."></textarea>
                    <div class="tk-counter"><span id="tk_count">0</span>/500</div>
                </div>
                <div class="tk-form-group">
                    <label>Date / time <span class="tk-hint">(controls ordering; defaults to now)</span></label>
                    <input type="datetime-local" id="tk_date">
                </div>
            </div>
            <div class="tk-modal-foot">
                <button class="btn-light" onclick="closeTicker()">Cancel</button>
                <button class="btn-primary" onclick="saveTicker()"><i class="fas fa-check"></i> Save</button>
            </div>
        </div>
    </div>

<script>
const TK_AJAX = 'ajax/site_ticker.php';

function tkAlert(msg, type) {
    Swal.fire({ toast: true, position: 'top-end', timer: 2600, showConfirmButton: false, icon: type || 'success', title: msg });
}
function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
function tkCount() { document.getElementById('tk_count').textContent = (document.getElementById('tk_text').value || '').length; }

$('#tk_pub').on('change', function () { loadTicker(this.value); });

function loadTicker(edition) {
    const wrap = document.getElementById('tk_table_wrap');
    if (!edition) { wrap.innerHTML = '<div class="tk-empty">Select a publication to manage its ticker.</div>'; return; }
    wrap.innerHTML = '<div class="tk-empty">Loading…</div>';
    $.post(TK_AJAX, { action: 'list', edition: edition }, function (res) {
        if (!res.success) { wrap.innerHTML = '<div class="tk-empty">' + (res.message || 'Failed to load') + '</div>'; return; }
        renderTicker(res.rows);
    }, 'json').fail(function () { wrap.innerHTML = '<div class="tk-empty">Request failed.</div>'; });
}

function fmtDate(d) {
    if (!d) return '';
    const t = d.replace(' ', 'T');
    const dt = new Date(t);
    if (isNaN(dt)) return d;
    return dt.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function renderTicker(rows) {
    const wrap = document.getElementById('tk_table_wrap');
    if (!rows.length) { wrap.innerHTML = '<div class="tk-empty">No ticker items yet for this publication. Use “Add ticker item”.</div>'; return; }
    let html = '<table class="tk-table"><thead><tr><th>Ticker text</th><th style="width:160px">Date</th><th style="width:90px;text-align:right">Actions</th></tr></thead><tbody>';
    rows.forEach(function (r) {
        html += '<tr data-id="' + r.id + '">' +
            '<td><div class="tk-text">' + r.breaking_news + '</div>' +
                (r.live ? '<span class="tk-live">LIVE</span>' : '<span class="tk-off">off</span>') + '</td>' +
            '<td><div class="tk-date">' + esc(fmtDate(r.date)) + '</div></td>' +
            '<td><div class="tk-actions">' +
                '<button class="tk-icon-btn" title="Edit" onclick="editTicker(' + r.id + ')"><i class="fas fa-pen"></i></button>' +
                '<button class="tk-icon-btn danger" title="Delete" onclick="deleteTicker(' + r.id + ')"><i class="fas fa-trash"></i></button>' +
            '</div></td></tr>';
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
    wrap._rows = {};
    rows.forEach(function (r) { wrap._rows[r.id] = r; });
}

function nowLocal() {
    const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}
function toLocalInput(mysql) {
    if (!mysql) return nowLocal();
    const dt = new Date(mysql.replace(' ', 'T'));
    if (isNaN(dt)) return nowLocal();
    dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
    return dt.toISOString().slice(0, 16);
}

function openCreateTicker() {
    if (!document.getElementById('tk_pub').value) { tkAlert('Choose a publication first', 'info'); return; }
    document.getElementById('tk_modal_title').textContent = 'Add ticker item';
    document.getElementById('tk_edit_id').value = '';
    document.getElementById('tk_text').value = '';
    document.getElementById('tk_date').value = nowLocal();
    tkCount();
    document.getElementById('tk_modal').classList.add('open');
}

function editTicker(id) {
    const r = (document.getElementById('tk_table_wrap')._rows || {})[id];
    if (!r) return;
    document.getElementById('tk_modal_title').textContent = 'Edit ticker item';
    document.getElementById('tk_edit_id').value = id;
    document.getElementById('tk_text').value = r.breaking_news || '';
    document.getElementById('tk_date').value = toLocalInput(r.date);
    tkCount();
    document.getElementById('tk_modal').classList.add('open');
}

function closeTicker() { document.getElementById('tk_modal').classList.remove('open'); }

function saveTicker() {
    const id = document.getElementById('tk_edit_id').value;
    const text = document.getElementById('tk_text').value.trim();
    const date = document.getElementById('tk_date').value;
    const edition = document.getElementById('tk_pub').value;
    if (!text) { tkAlert('Ticker text is required', 'error'); return; }
    const data = id
        ? { action: 'update', id: id, breaking_news: text, date: date }
        : { action: 'create', edition: edition, breaking_news: text, date: date };
    $.post(TK_AJAX, data, function (res) {
        if (res.success) { closeTicker(); loadTicker(edition); tkAlert('Saved'); }
        else tkAlert(res.message || 'Save failed', 'error');
    }, 'json').fail(function () { tkAlert('Request failed', 'error'); });
}

function deleteTicker(id) {
    Swal.fire({ title: 'Delete this ticker item?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post(TK_AJAX, { action: 'delete', id: id }, function (res) {
            if (res.success) { loadTicker(document.getElementById('tk_pub').value); tkAlert('Deleted'); }
            else tkAlert(res.message || 'Delete failed', 'error');
        }, 'json');
    });
}
</script>
</body>
</html>
