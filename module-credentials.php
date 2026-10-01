<?php
require_once 'config.php';
requireLogin();

// Admins only — this is a retrievable credential store.
if (!isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$currentPage = 'credentials';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Manager - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .cred-cat { margin-bottom: 28px; }
        .cred-cat h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; margin: 0 0 12px; }
        .cred-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
        .cred-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; }
        .cred-card h3 { margin: 0 0 4px; font-size: 16px; color: #0f172a; }
        .cred-meta { font-size: 13px; color: #475569; margin: 2px 0; word-break: break-all; }
        .cred-meta i { width: 16px; color: #94a3b8; }
        .cred-secret { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
        .cred-secret code { flex: 1; background: #f1f5f9; border-radius: 6px; padding: 6px 8px; font-size: 13px; letter-spacing: 1px; color: #0f172a; min-height: 30px; word-break: break-all; }
        .cred-actions { display: flex; gap: 8px; margin-top: 12px; }
        .cred-actions button { font-size: 12.5px; }
        .icon-btn-sm { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:6px 9px; cursor:pointer; color:#475569; }
        .icon-btn-sm:hover { background:#e2e8f0; }
        .cred-empty { color:#94a3b8; font-size:14px; padding: 8px 0; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.45); display:none; align-items:center; justify-content:center; z-index: 1300; }
        .modal-overlay.active { display:flex; }
        .modal-box { background:#fff; border-radius:14px; width: 480px; max-width: calc(100vw - 32px); max-height: 90vh; overflow-y:auto; padding: 22px; }
        .modal-box h2 { margin:0 0 16px; font-size: 18px; }
        .fld { margin-bottom: 12px; }
        .fld label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px; }
        .fld input, .fld select, .fld textarea { width:100%; padding:9px 11px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; box-sizing:border-box; }
        .modal-foot { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-area">
            <div class="page-header" style="margin-bottom: 24px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div>
                    <h1><i class="fas fa-key"></i> Password Manager</h1>
                    <p>Securely store email and other account passwords. Secrets are encrypted at rest; only administrators can view them.</p>
                </div>
                <button class="btn btn-primary" onclick="credOpen()"><i class="fas fa-plus"></i> Add credential</button>
            </div>

            <div id="credList"><div class="cred-empty">Loading…</div></div>
        </div>
    </div>

    <!-- Add / edit modal -->
    <div class="modal-overlay" id="credModal">
        <div class="modal-box">
            <h2 id="credModalTitle">Add credential</h2>
            <input type="hidden" id="credId">
            <div class="fld">
                <label>Category</label>
                <select id="credCategory">
                    <option value="email">Email account</option>
                    <option value="social">Social / other</option>
                </select>
            </div>
            <div class="fld"><label>Label *</label><input type="text" id="credLabel" placeholder="e.g. info@themunicheye.com"></div>
            <div class="fld"><label>Username / login</label><input type="text" id="credUsername" autocomplete="off"></div>
            <div class="fld"><label>Host / server <span style="font-weight:400;color:#94a3b8;">(e.g. mail.server.com, optional)</span></label><input type="text" id="credHost" autocomplete="off"></div>
            <div class="fld"><label>URL <span style="font-weight:400;color:#94a3b8;">(optional)</span></label><input type="text" id="credUrl" autocomplete="off"></div>
            <div class="fld">
                <label>Password / secret <span id="credSecretHint" style="font-weight:400;color:#94a3b8;"></span></label>
                <input type="password" id="credSecret" autocomplete="new-password">
            </div>
            <div class="fld"><label>Notes</label><textarea id="credNotes" rows="2"></textarea></div>
            <div class="modal-foot">
                <button class="btn" onclick="credClose()">Cancel</button>
                <button class="btn btn-primary" onclick="credSave()">Save</button>
            </div>
        </div>
    </div>

    <script src="js/credentials.js?v=<?php echo @filemtime(__DIR__ . '/js/credentials.js'); ?>"></script>
</body>
</html>
