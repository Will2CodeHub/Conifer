<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

if (!hasPermission('menus.manage') && !isAdmin()) {
    header('Location: ' . getLandingUrl());
    exit();
}

// Publications that can own a menu (same source article manager / user setup use).
$allPublications = [];
try {
    $connAdmin = getDBConnection_TENAdmin();
    if ($connAdmin) {
        $pubRes = $connAdmin->query("SELECT publication, title, url FROM publications WHERE pub_live = '1' ORDER BY title");
        while ($pubRes && ($r = $pubRes->fetch_assoc())) { $allPublications[] = $r; }
        $connAdmin->close();
    }
} catch (Throwable $e) {
    error_log('module-site-menus: failed loading publications: ' . $e->getMessage());
}

$canCreate = isAdmin() || hasPermission('menus.manage');
$currentPage = 'site_menus';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEN Site Menus - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 30px; font-weight: 700; color: #111827; margin-bottom: 6px; }
        .page-header p { color: #6b7280; font-size: 14px; max-width: 780px; }
        .sm-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; margin-bottom: 20px; }
        .sm-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; margin-bottom: 6px; }
        .sm-field select, .sm-field input { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; min-width: 260px; background: #fff; }
        .sm-spacer { flex: 1; }
        .btn-primary { background: #4f46e5; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background: #4338ca; }
        .btn-light { background: #fff; color: #374151; border: 1px solid #d1d5db; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; }
        .btn-light:hover { background: #f9fafb; }
        .sm-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .sm-legend { display: flex; gap: 20px; flex-wrap: wrap; padding: 12px 18px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12.5px; color: #6b7280; }
        .sm-legend b { color: #374151; }
        table.sm-table { width: 100%; border-collapse: collapse; }
        table.sm-table th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; padding: 10px 14px; border-bottom: 1px solid #e5e7eb; background: #fafafa; }
        table.sm-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        table.sm-table tr:last-child td { border-bottom: none; }
        .sm-handle { cursor: grab; color: #9ca3af; font-size: 15px; }
        .sm-row.child td:first-child { padding-left: 34px; }
        .sm-row.child .sm-name::before { content: "↳ "; color: #cbd5e1; }
        .sm-name { font-weight: 600; color: #111827; }
        .sm-url { color: #6b7280; font-size: 12.5px; word-break: break-all; }
        .sm-sub { display: inline-block; margin-left: 8px; font-size: 10.5px; font-weight: 600; color: #92400e; background: #fef3c7; padding: 1px 7px; border-radius: 999px; }
        /* toggle switch */
        .sw { position: relative; display: inline-block; width: 40px; height: 22px; }
        .sw input { opacity: 0; width: 0; height: 0; }
        .sw span { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: .2s; cursor: pointer; }
        .sw span::before { content: ""; position: absolute; height: 16px; width: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .sw input:checked + span { background: #4f46e5; }
        .sw input:checked + span::before { transform: translateX(18px); }
        .sm-actions { display: flex; gap: 6px; justify-content: flex-end; }
        .sm-icon-btn { border: 1px solid #e5e7eb; background: #fff; border-radius: 7px; width: 32px; height: 32px; cursor: pointer; color: #6b7280; }
        .sm-icon-btn:hover { background: #f9fafb; color: #111827; }
        .sm-icon-btn.danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .sm-empty { padding: 40px; text-align: center; color: #9ca3af; }
        .sortable-ghost { opacity: .45; background: #eef2ff; }
        /* modal */
        .sm-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: none; align-items: flex-start; justify-content: center; z-index: 2000; overflow-y: auto; padding: 40px 16px; }
        .sm-modal-overlay.open { display: flex; }
        .sm-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 560px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .sm-modal-head { padding: 18px 22px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .sm-modal-head h2 { font-size: 18px; font-weight: 700; color: #111827; }
        .sm-modal-body { padding: 20px 22px; }
        .sm-modal-foot { padding: 16px 22px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .sm-form-group { margin-bottom: 16px; }
        .sm-form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .sm-form-group input[type=text] { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .sm-hint { font-size: 12px; color: #9ca3af; font-weight: 400; }
        .sm-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; max-height: 220px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
        .sm-checks label { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 13px; color: #374151; }
        .sm-flags { display: flex; gap: 22px; margin-top: 4px; }
        .sm-flags label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: #374151; }
        .close-x { background: none; border: none; font-size: 22px; color: #9ca3af; cursor: pointer; line-height: 1; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-bars"></i> TEN Site Menus</h1>
                <p>Manage each publication's navigation menu and article sections. Items live in the shared <code>main_menu</code> table that every live site reads &mdash; changes here take effect on the public sites and drive the section lists in the Articles Manager and in user setup.</p>
            </div>

            <div id="alert-container"></div>

            <div class="sm-toolbar">
                <div class="sm-field">
                    <label for="sm_pub">Publication</label>
                    <select id="sm_pub">
                        <option value="">Choose a publication…</option>
                        <?php foreach ($allPublications as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['publication']); ?>"><?php echo htmlspecialchars($p['title']); ?> (<?php echo htmlspecialchars($p['publication']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm-spacer"></div>
                <?php if ($canCreate): ?>
                <div class="sm-field">
                    <button class="btn-primary" onclick="openCreateMenuModal()"><i class="fas fa-plus"></i> Add menu item</button>
                </div>
                <?php endif; ?>
            </div>

            <div class="sm-card">
                <div class="sm-legend">
                    <span><b>In Menu</b> — shows in the site's navigation bar</span>
                    <span><b>Is Section</b> — usable as an article section (in Articles &amp; user setup)</span>
                    <span><i class="fas fa-grip-vertical"></i> drag to reorder</span>
                </div>
                <div id="sm_table_wrap">
                    <div class="sm-empty">Select a publication to manage its menu.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create / Edit modal -->
    <div class="sm-modal-overlay" id="sm_modal">
        <div class="sm-modal">
            <div class="sm-modal-head">
                <h2 id="sm_modal_title">Add menu item</h2>
                <button class="close-x" onclick="closeMenuModal()">&times;</button>
            </div>
            <div class="sm-modal-body">
                <input type="hidden" id="sm_edit_id" value="">
                <div class="sm-form-group">
                    <label>Name <span style="color:#dc2626">*</span></label>
                    <input type="text" id="sm_name" placeholder="e.g. Sport">
                </div>
                <div class="sm-form-group">
                    <label>Link title <span class="sm-hint">(tooltip / SEO title — defaults to the name)</span></label>
                    <input type="text" id="sm_title" placeholder="e.g. Berlin Sport News">
                </div>
                <div class="sm-form-group">
                    <label>URL <span class="sm-hint">(leave blank to auto-build from the publication + name)</span></label>
                    <input type="text" id="sm_url" placeholder="https://…/sport">
                </div>
                <div class="sm-form-group" id="sm_flags_group">
                    <label>Flags</label>
                    <div class="sm-flags">
                        <label><input type="checkbox" id="sm_menu_item" checked> In Menu</label>
                        <label><input type="checkbox" id="sm_section_item" checked> Is Section</label>
                    </div>
                </div>
                <div class="sm-form-group" id="sm_pubs_group">
                    <label>Assign to publication(s) <span style="color:#dc2626">*</span></label>
                    <div class="sm-checks" id="sm_pub_checks">
                        <?php foreach ($allPublications as $p): ?>
                            <label><input type="checkbox" class="sm-pub-cb" value="<?php echo htmlspecialchars($p['publication']); ?>"> <?php echo htmlspecialchars($p['title']); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="sm-modal-foot">
                <button class="btn-light" onclick="closeMenuModal()">Cancel</button>
                <button class="btn-primary" id="sm_save_btn" onclick="saveMenuItem()"><i class="fas fa-check"></i> Save</button>
            </div>
        </div>
    </div>

<script>
const SM_AJAX = 'ajax/site_menus.php';
let smSortable = null;

function smAlert(msg, type) {
    Swal.fire({ toast: true, position: 'top-end', timer: 2600, showConfirmButton: false,
        icon: type || 'success', title: msg });
}

$('#sm_pub').on('change', function () { loadMenu(this.value); });

function loadMenu(edition) {
    const wrap = document.getElementById('sm_table_wrap');
    if (!edition) { wrap.innerHTML = '<div class="sm-empty">Select a publication to manage its menu.</div>'; return; }
    wrap.innerHTML = '<div class="sm-empty">Loading…</div>';
    $.post(SM_AJAX, { action: 'list', edition: edition }, function (res) {
        if (!res.success) { wrap.innerHTML = '<div class="sm-empty">' + (res.message || 'Failed to load') + '</div>'; return; }
        renderMenu(res.rows, edition);
    }, 'json').fail(function () { wrap.innerHTML = '<div class="sm-empty">Request failed.</div>'; });
}

function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

function renderMenu(rows, edition) {
    const wrap = document.getElementById('sm_table_wrap');
    if (!rows.length) { wrap.innerHTML = '<div class="sm-empty">No menu items yet for this publication. Use “Add menu item”.</div>'; return; }
    let html = '<table class="sm-table"><thead><tr>' +
        '<th style="width:34px"></th><th>Name</th><th style="width:90px">In Menu</th>' +
        '<th style="width:90px">Is Section</th><th style="width:90px;text-align:right">Actions</th>' +
        '</tr></thead><tbody id="sm_tbody">';
    rows.forEach(function (r) {
        const child = r.parent_item && r.parent_item > 0;
        html += '<tr class="sm-row' + (child ? ' child' : '') + '" data-id="' + r.id + '" data-parent="' + r.parent_item + '">' +
            '<td>' + (child ? '' : '<i class="fas fa-grip-vertical sm-handle"></i>') + '</td>' +
            '<td><div class="sm-name">' + esc(r.name) + (child ? '<span class="sm-sub">sub</span>' : '') + '</div>' +
                '<div class="sm-url">' + esc(r.url) + '</div></td>' +
            '<td><label class="sw"><input type="checkbox" ' + (r.menu_item ? 'checked' : '') +
                ' onchange="toggleFlag(' + r.id + ',\'menu_item\',this)"><span></span></label></td>' +
            '<td><label class="sw"><input type="checkbox" ' + (r.section_item ? 'checked' : '') +
                ' onchange="toggleFlag(' + r.id + ',\'section_item\',this)"><span></span></label></td>' +
            '<td><div class="sm-actions">' +
                '<button class="sm-icon-btn" title="Edit" onclick="editMenuItem(' + r.id + ')"><i class="fas fa-pen"></i></button>' +
                '<button class="sm-icon-btn danger" title="Delete" onclick="deleteMenuItem(' + r.id + ')"><i class="fas fa-trash"></i></button>' +
            '</div></td></tr>';
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
    // keep the rows in a JS cache for edit
    wrap._rows = {};
    rows.forEach(function (r) { wrap._rows[r.id] = r; });

    const tbody = document.getElementById('sm_tbody');
    if (smSortable) { try { smSortable.destroy(); } catch (e) {} }
    smSortable = Sortable.create(tbody, {
        handle: '.sm-handle', animation: 150, ghostClass: 'sortable-ghost',
        // only top-level rows are draggable (children have no handle)
        filter: '.child',
        onMove: function (evt) { return !$(evt.related).hasClass('child'); },
        onEnd: function () { persistOrder(edition); }
    });
}

function persistOrder(edition) {
    const ids = [];
    $('#sm_tbody tr.sm-row').each(function () {
        if (!$(this).hasClass('child')) ids.push($(this).data('id'));
    });
    $.post(SM_AJAX, { action: 'reorder', edition: edition, order: JSON.stringify(ids) }, function (res) {
        if (res.success) smAlert('Order saved'); else smAlert(res.message || 'Reorder failed', 'error');
    }, 'json');
}

function toggleFlag(id, field, el) {
    const val = el.checked ? 1 : 0;
    $.post(SM_AJAX, { action: 'toggle', id: id, field: field, value: val }, function (res) {
        if (res.success) smAlert('Updated'); else { el.checked = !el.checked; smAlert(res.message || 'Update failed', 'error'); }
    }, 'json').fail(function () { el.checked = !el.checked; smAlert('Request failed', 'error'); });
}

/* ---- create / edit modal ---- */
function openCreateMenuModal() {
    document.getElementById('sm_modal_title').textContent = 'Add menu item';
    document.getElementById('sm_edit_id').value = '';
    document.getElementById('sm_name').value = '';
    document.getElementById('sm_title').value = '';
    document.getElementById('sm_url').value = '';
    document.getElementById('sm_menu_item').checked = true;
    document.getElementById('sm_section_item').checked = true;
    $('.sm-pub-cb').prop('checked', false);
    // preselect the currently chosen publication
    const cur = document.getElementById('sm_pub').value;
    if (cur) $('.sm-pub-cb[value="' + cur + '"]').prop('checked', true);
    document.getElementById('sm_pubs_group').style.display = '';
    document.getElementById('sm_flags_group').style.display = '';
    document.getElementById('sm_modal').classList.add('open');
}

function editMenuItem(id) {
    const r = (document.getElementById('sm_table_wrap')._rows || {})[id];
    if (!r) return;
    document.getElementById('sm_modal_title').textContent = 'Edit menu item';
    document.getElementById('sm_edit_id').value = id;
    document.getElementById('sm_name').value = r.name || '';
    document.getElementById('sm_title').value = r.title || '';
    document.getElementById('sm_url').value = r.url || '';
    // flags + publications are managed inline in the table; hide them when editing
    document.getElementById('sm_pubs_group').style.display = 'none';
    document.getElementById('sm_flags_group').style.display = 'none';
    document.getElementById('sm_modal').classList.add('open');
}

function closeMenuModal() { document.getElementById('sm_modal').classList.remove('open'); }

function saveMenuItem() {
    const id = document.getElementById('sm_edit_id').value;
    const name = document.getElementById('sm_name').value.trim();
    const title = document.getElementById('sm_title').value.trim();
    const url = document.getElementById('sm_url').value.trim();
    if (!name) { smAlert('Name is required', 'error'); return; }

    if (id) {
        $.post(SM_AJAX, { action: 'update', id: id, name: name, title: title, url: url }, function (res) {
            if (res.success) { closeMenuModal(); loadMenu(document.getElementById('sm_pub').value); smAlert('Saved'); }
            else smAlert(res.message || 'Save failed', 'error');
        }, 'json');
    } else {
        const editions = [];
        $('.sm-pub-cb:checked').each(function () { editions.push(this.value); });
        if (!editions.length) { smAlert('Choose at least one publication', 'error'); return; }
        const data = {
            action: 'create', name: name, title: title, url: url,
            menu_item: document.getElementById('sm_menu_item').checked ? 1 : 0,
            section_item: document.getElementById('sm_section_item').checked ? 1 : 0,
            'editions[]': editions
        };
        $.post(SM_AJAX, data, function (res) {
            if (res.success) {
                closeMenuModal();
                loadMenu(document.getElementById('sm_pub').value);
                smAlert('Added to ' + res.created.length + ' publication(s)');
            } else smAlert(res.message || 'Create failed', 'error');
        }, 'json');
    }
}

function deleteMenuItem(id) {
    Swal.fire({ title: 'Delete this menu item?', text: 'It will be removed from this publication only.',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.post(SM_AJAX, { action: 'delete', id: id }, function (res) {
            if (res.success) { loadMenu(document.getElementById('sm_pub').value); smAlert('Deleted'); }
            else smAlert(res.message || 'Delete failed', 'error');
        }, 'json');
    });
}
</script>
</body>
</html>
