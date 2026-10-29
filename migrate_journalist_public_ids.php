<?php
/**
 * One-time backfill: give every ten_users row a public_id (used by the article
 * byline link /backend/journalist.php?id=<public_id>) and a firstname/surname
 * (the byline resolver needs firstname to render a *linked* author byline).
 *
 * Safe + idempotent: only fills values that are missing, never overwrites
 * existing data. Run once in the browser while logged in as an admin.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
requireLogin();

header('Content-Type: text/plain; charset=utf-8');

if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    echo "Admin only.\n";
    exit;
}

$conn = getDBConnection();
if (!$conn) { echo "DB connection failed.\n"; exit; }

$dry = isset($_GET['dry']); // ?dry=1 to preview counts without writing

function dl_unique_public_id(mysqli $conn): int {
    for ($t = 0; $t < 20; $t++) {
        $cand = rand(10000000, 999999999);
        $pc = $conn->prepare("SELECT id FROM ten_users WHERE public_id = ? LIMIT 1");
        $pc->bind_param("i", $cand);
        $pc->execute();
        $taken = $pc->get_result()->num_rows > 0;
        $pc->close();
        if (!$taken) { return $cand; }
    }
    return rand(10000000, 999999999);
}

// Rows missing a public_id OR a firstname (but which have a name to derive from).
$res = $conn->query("SELECT id, full_name, firstname, surname, public_id FROM ten_users");
if (!$res) { echo "Query failed: " . $conn->error . "\n"; exit; }

$scanned = 0; $setPid = 0; $setName = 0; $changed = 0;
$rows = [];
while ($row = $res->fetch_assoc()) { $rows[] = $row; }
$res->free();

foreach ($rows as $row) {
    $scanned++;
    $id = (int)$row['id'];
    $needPid  = empty($row['public_id']); // NULL, 0 or ''
    $needName = (trim((string)$row['firstname']) === '') && (trim((string)$row['full_name']) !== '');
    if (!$needPid && !$needName) { continue; }

    $newPid   = $needPid ? dl_unique_public_id($conn) : (int)$row['public_id'];
    $firstName = $row['firstname'];
    $surName   = $row['surname'];
    if ($needName) {
        $parts = preg_split('/\s+/', trim((string)$row['full_name']), 2);
        $firstName = isset($parts[0]) ? $parts[0] : '';
        $surName   = (trim((string)$surName) === '' && isset($parts[1])) ? $parts[1] : $surName;
    }

    if ($dry) {
        if ($needPid) { $setPid++; }
        if ($needName) { $setName++; }
        $changed++;
        continue;
    }

    $u = $conn->prepare("UPDATE ten_users SET public_id = ?, firstname = ?, surname = ? WHERE id = ?");
    $u->bind_param("issi", $newPid, $firstName, $surName, $id);
    if ($u->execute()) {
        $changed++;
        if ($needPid) { $setPid++; }
        if ($needName) { $setName++; }
    } else {
        echo "  ! id $id update failed: " . $u->error . "\n";
    }
    $u->close();
}

echo ($dry ? "DRY RUN (no writes)\n" : "DONE\n");
echo "Scanned:            $scanned\n";
echo "public_id filled:   $setPid\n";
echo "first/surname set:  $setName\n";
echo "rows changed:       $changed\n";
echo "\nDelete this file from the server after running.\n";
