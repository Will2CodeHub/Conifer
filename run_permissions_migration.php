<?php
// One-time permissions migration - DELETE THIS FILE AFTER RUNNING
require_once 'config.php';
requireLogin();
if (!isAdmin()) { die('Unauthorized'); }

$conn = getDBConnection();
$errors = [];
$success = [];

function runSQL($conn, $sql, $desc, &$errors, &$success) {
    if ($conn->query($sql)) {
        $success[] = "✓ $desc";
    } else {
        $errors[] = "✗ $desc: " . $conn->error;
    }
}

// ============================================================
// STEP 1: Add new permissions
// ============================================================
$newPerms = [
    ['articles.view',   'View Articles',          'Access articles manager module',      18, 'articles'],
    ['news.view',       'View News Sites',         'Access TEN news sites module',         6, 'news'],
    ['wne.view',        'View WNE Recruitment',    'Access WNE recruitment modules',       8, 'wne'],
    ['emails.view',     'View Emails',             'Access emails module',                13, 'emails'],
    ['restaurants.view','View Restaurant Builder', 'Access restaurant builder module',    14, 'restaurants'],
    ['venues.view',     'View Venues',             'Access venues and events module',     15, 'venues'],
];
foreach ($newPerms as $p) {
    $exists = $conn->query("SELECT id FROM ten_permissions WHERE permission_key = '{$p[0]}'")->num_rows;
    if (!$exists) {
        runSQL($conn,
            "INSERT INTO ten_permissions (permission_key, permission_name, permission_description, module_id, module, permission_type, is_system)
             VALUES ('{$p[0]}', '{$p[1]}', '{$p[2]}', {$p[3]}, '{$p[4]}', 'view', 0)",
            "Add permission: {$p[0]}", $errors, $success);
    } else {
        $success[] = "→ Permission {$p[0]} already exists, skipped";
    }
}

// ============================================================
// STEP 2: Create Health Insurance role (id=8)
// ============================================================
$hroleExists = $conn->query("SELECT id FROM ten_roles WHERE role_key = 'health_insurance'")->num_rows;
if (!$hroleExists) {
    runSQL($conn,
        "INSERT INTO ten_roles (role_key, role_name, role_description, role_level, is_system)
         VALUES ('health_insurance', 'Health Insurance', 'Access to Health Insurance (PKV) section', 45, 0)",
        "Create Health Insurance role", $errors, $success);
}
$hrole = $conn->query("SELECT id FROM ten_roles WHERE role_key = 'health_insurance'")->fetch_assoc();
$healthRoleId = $hrole['id'];

// ============================================================
// STEP 3: Add all PKV permissions to health_insurance role
// ============================================================
$pkvPerms = $conn->query("SELECT id FROM ten_permissions WHERE permission_key LIKE 'pkv.%'");
while ($row = $pkvPerms->fetch_assoc()) {
    $pid = $row['id'];
    $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id = $healthRoleId AND permission_id = $pid")->num_rows;
    if (!$already) {
        runSQL($conn,
            "INSERT INTO ten_role_permissions (role_id, permission_id) VALUES ($healthRoleId, $pid)",
            "Assign PKV perm #$pid to Health Insurance role", $errors, $success);
    }
}

// ============================================================
// STEP 4: Remove PKV permissions from admin role (2)
// ============================================================
runSQL($conn,
    "DELETE FROM ten_role_permissions WHERE role_id = 2
     AND permission_id IN (SELECT id FROM ten_permissions WHERE permission_key LIKE 'pkv.%')",
    "Remove PKV permissions from Administrator role", $errors, $success);

// ============================================================
// STEP 5: Add new permissions + file_transfers to super_user (1) and admin (2)
// ============================================================
$addToRoles = [1, 2];
$newPermKeys = ['articles.view','news.view','wne.view','emails.view','restaurants.view','venues.view',
                'file_transfers.view','file_transfers.manage'];
foreach ($addToRoles as $rid) {
    foreach ($newPermKeys as $pk) {
        $pRow = $conn->query("SELECT id FROM ten_permissions WHERE permission_key = '$pk'")->fetch_assoc();
        if (!$pRow) continue;
        $pid = $pRow['id'];
        $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id=$rid AND permission_id=$pid")->num_rows;
        if (!$already) {
            runSQL($conn,
                "INSERT INTO ten_role_permissions (role_id, permission_id) VALUES ($rid, $pid)",
                "Add $pk to role #$rid", $errors, $success);
        }
    }
}

// Also ensure super_user (1) has dashboard.view and system.settings
foreach ([134, 135, 82] as $pid) {
    $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id=1 AND permission_id=$pid")->num_rows;
    if (!$already) {
        runSQL($conn,
            "INSERT INTO ten_role_permissions (role_id, permission_id) VALUES (1, $pid)",
            "Ensure super_user has perm #$pid", $errors, $success);
    }
}

// ============================================================
// STEP 6: Add articles.view to sectioneditor role (7)
// ============================================================
$avRow = $conn->query("SELECT id FROM ten_permissions WHERE permission_key = 'articles.view'")->fetch_assoc();
if ($avRow) {
    $avId = $avRow['id'];
    $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id=7 AND permission_id=$avId")->num_rows;
    if (!$already) {
        runSQL($conn,
            "INSERT INTO ten_role_permissions (role_id, permission_id) VALUES (7, $avId)",
            "Add articles.view to Section Editor role", $errors, $success);
    }
}
// Also add dashboard.view to sectioneditor
$dvRow = $conn->query("SELECT id FROM ten_permissions WHERE permission_key = 'dashboard.view'")->fetch_assoc();
if ($dvRow) {
    $dvId = $dvRow['id'];
    $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id=7 AND permission_id=$dvId")->num_rows;
    if (!$already) {
        runSQL($conn,
            "INSERT INTO ten_role_permissions (role_id, permission_id) VALUES (7, $dvId)",
            "Add dashboard.view to Section Editor role", $errors, $success);
    }
}

// ============================================================
// STEP 7: Set required_permission on ALL modules
// ============================================================
$modulePerms = [
    [2,  'system.settings'],
    [3,  'traffic.view'],
    [4,  'users.view'],
    [5,  'roles.view'],
    [6,  'news.view'],
    [7,  'pkv.view'],
    [8,  'wne.view'],
    [9,  'projects.view'],
    [10, 'marketing.view'],
    [11, 'traffic.view'],
    [12, 'traffic.view'],
    [13, 'emails.view'],
    [14, 'restaurants.view'],
    [15, 'venues.view'],
    [18, 'articles.view'],
    [19, 'file_transfers.view'],
    [21, 'wne.view'],
    [22, 'wne.view'],
];
foreach ($modulePerms as $mp) {
    runSQL($conn,
        "UPDATE ten_modules SET required_permission = '{$mp[1]}' WHERE id = {$mp[0]}",
        "Set required_permission '{$mp[1]}' on module #{$mp[0]}", $errors, $success);
}

// ============================================================
// STEP 8: Clean up Hannah's roles (user 2)
// Remove super_user(1), manager(3), editor(4), viewer(5), broker(6), keep admin(2)
// ============================================================
runSQL($conn,
    "DELETE FROM ten_user_roles WHERE user_id = 2 AND role_id IN (1, 3, 4, 5, 6)",
    "Clean up Hannah's excess roles", $errors, $success);

// Add health_insurance role to Hannah (user 2)
$already = $conn->query("SELECT id FROM ten_user_roles WHERE user_id=2 AND role_id=$healthRoleId")->num_rows;
if (!$already) {
    runSQL($conn,
        "INSERT INTO ten_user_roles (user_id, role_id, assigned_by) VALUES (2, $healthRoleId, 1)",
        "Assign Health Insurance role to Hannah", $errors, $success);
}

// ============================================================
// STEP 9: Ensure William (user 1) has super_user role (already should, verify)
// ============================================================
$wAlready = $conn->query("SELECT id FROM ten_user_roles WHERE user_id=1 AND role_id=1")->num_rows;
if (!$wAlready) {
    runSQL($conn,
        "INSERT INTO ten_user_roles (user_id, role_id, assigned_by) VALUES (1, 1, 1)",
        "Assign super_user role to William", $errors, $success);
} else {
    $success[] = "→ William already has super_user role";
}

// ============================================================
// STEP 10: Ensure Prince Kumar has admin role only (already set, verify & clean)
// Remove any excess roles from Prince (keep only admin=2)
// ============================================================
// First find Prince's user id
$princeRow = $conn->query("SELECT id FROM ten_users WHERE email = 'lok9910@gmail.com'")->fetch_assoc();
if ($princeRow) {
    $princeId = $princeRow['id'];
    runSQL($conn,
        "DELETE FROM ten_user_roles WHERE user_id = $princeId AND role_id != 2",
        "Clean Prince's roles (keep admin only)", $errors, $success);
    $already = $conn->query("SELECT id FROM ten_user_roles WHERE user_id=$princeId AND role_id=2")->num_rows;
    if (!$already) {
        runSQL($conn,
            "INSERT INTO ten_user_roles (user_id, role_id, assigned_by) VALUES ($princeId, 2, 1)",
            "Assign admin role to Prince", $errors, $success);
    } else {
        $success[] = "→ Prince already has admin role";
    }
}

// ============================================================
// STEP 11: Update Matt's publication to include Germany Eye
// ============================================================
runSQL($conn,
    "UPDATE ten_users SET publication = 'tme,tge' WHERE id = 6",
    "Update Matt's publication to tme,tge", $errors, $success);

$conn->close();
?>
<!DOCTYPE html><html><head><title>Migration</title>
<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#eee;}
.ok{color:#4ade80;}.err{color:#f87171;}.info{color:#93c5fd;}
h2{color:#fff;}
</style></head><body>
<h2>TEN Permissions Migration</h2>
<?php foreach ($success as $s): ?><div class="ok"><?= htmlspecialchars($s) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
<br><div class="info">Done. Total: <?= count($success) ?> succeeded, <?= count($errors) ?> errors.</div>
<br><div class="err" style="font-size:1.2em;">⚠️ DELETE THIS FILE: run_permissions_migration.php</div>
</body></html>
