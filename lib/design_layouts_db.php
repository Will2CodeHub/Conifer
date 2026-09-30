<?php
/**
 * TEN Design System — data access (management side).
 *
 * The design tables live in the admin_ten database (alongside `publications`),
 * reached with getDBConnection_TENAdmin() (mysqli), exactly like news_sites_db.php.
 * The site renderer reads the SAME tables via PDO Core (design/lib/config.php).
 *
 * Draft = editable working copy (ten_design_layouts).
 * Version = immutable published snapshot bundle (ten_design_versions); one is_live per pub.
 */

if (!function_exists('getDBConnection_TENAdmin')) {
    require_once __DIR__ . '/../config_ten_admin.php';
}
require_once __DIR__ . '/design_registry.php';

/** Decoded draft layout for one page+device, or null if none stored. */
function dl_load_draft(string $pub, string $page, string $device = 'desktop'): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return null; }
    $st = $conn->prepare("SELECT layout_json FROM ten_design_layouts WHERE publication=? AND page_type=? AND device=? LIMIT 1");
    $st->bind_param('sss', $pub, $page, $device);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();
    return $row ? json_decode($row['layout_json'], true) : null;
}

/**
 * Upsert a draft. Desktop layouts are validated + cleaned; mobile is stored as a
 * raw diff ({hidden,order,settings,theme}). Pass $conn to enlist in a caller's
 * transaction (it will not be closed); omit it to use a self-managed connection.
 */
function dl_save_draft(string $pub, string $page, string $device, array $layout, ?string $user, ?mysqli $conn = null): void {
    if ($page === '_blocks') {
        $store = $layout; // reusable block templates, stored as-is
    } elseif ($device === 'desktop') {
        $v = dl_validate_layout($layout, $page);
        $store = $v['layout'];
    } else {
        $store = $layout; // mobile diff, stored as-is
    }
    $json = json_encode($store);

    $ownConn = false;
    if ($conn === null) {
        $conn = getDBConnection_TENAdmin();
        if (!$conn) { throw new RuntimeException('admin_ten connection failed'); }
        $ownConn = true;
    }
    $st = $conn->prepare("INSERT INTO ten_design_layouts (publication, page_type, device, layout_json, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), updated_by = VALUES(updated_by)");
    $st->bind_param('sssss', $pub, $page, $device, $json, $user);
    $st->execute();
    $st->close();
    if ($ownConn) { $conn->close(); }
}

/** Seed helper: $pages = [ page => [ 'desktop'=>layout, 'mobile'=>diff ] ]. */
function dl_seed_defaults(string $pub, array $pages): void {
    foreach ($pages as $page => $devices) {
        foreach ($devices as $device => $layout) {
            dl_save_draft($pub, $page, (string)$device, $layout, 'seed');
        }
    }
}

/** All drafts for a publication as [page][device] => decoded layout. */
function dl_all_drafts(string $pub, ?mysqli $conn = null): array {
    $ownConn = false;
    if ($conn === null) {
        $conn = getDBConnection_TENAdmin();
        if (!$conn) { return []; }
        $ownConn = true;
    }
    $st = $conn->prepare("SELECT page_type, device, layout_json FROM ten_design_layouts WHERE publication=?");
    $st->bind_param('s', $pub);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[$r['page_type']][$r['device']] = json_decode($r['layout_json'], true);
    }
    $st->close();
    if ($ownConn) { $conn->close(); }
    return $out;
}

/** Snapshot every draft into a new live version. Returns the new version_no. */
function dl_publish(string $pub, ?string $label, ?string $user): int {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { throw new RuntimeException('admin_ten connection failed'); }

    $bundle = dl_all_drafts($pub, $conn);
    $snapshot = json_encode($bundle);

    $conn->begin_transaction();
    try {
        $res = $conn->query("SELECT COALESCE(MAX(version_no),0)+1 AS n FROM ten_design_versions WHERE publication='" . $conn->real_escape_string($pub) . "'");
        $next = (int)$res->fetch_assoc()['n'];

        $u = $conn->prepare("UPDATE ten_design_versions SET is_live=0 WHERE publication=?");
        $u->bind_param('s', $pub);
        $u->execute();
        $u->close();

        $ins = $conn->prepare("INSERT INTO ten_design_versions (publication, version_no, label, snapshot_json, is_live, created_by)
            VALUES (?, ?, ?, ?, 1, ?)");
        $ins->bind_param('sisss', $pub, $next, $label, $snapshot, $user);
        $ins->execute();
        $ins->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        throw $e;
    }
    $conn->close();
    return $next;
}

/** Version rows newest-first (id, version_no, label, is_live, created_at, created_by). */
function dl_list_versions(string $pub): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return []; }
    $st = $conn->prepare("SELECT id, version_no, label, is_live, created_at, created_by
        FROM ten_design_versions WHERE publication=? ORDER BY version_no DESC");
    $st->bind_param('s', $pub);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    $st->close();
    $conn->close();
    return $out;
}

/** The currently live snapshot bundle, or null. */
function dl_live_snapshot(string $pub): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return null; }
    $st = $conn->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND is_live=1 LIMIT 1");
    $st->bind_param('s', $pub);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();
    return $row ? json_decode($row['snapshot_json'], true) : null;
}

/** Make an older version live and restore its bundle into the drafts. */
function dl_rollback(string $pub, int $version_no, ?string $user): void {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { throw new RuntimeException('admin_ten connection failed'); }

    $st = $conn->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND version_no=? LIMIT 1");
    $st->bind_param('si', $pub, $version_no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) { $conn->close(); throw new RuntimeException("version $version_no not found for $pub"); }
    $bundle = json_decode($row['snapshot_json'], true) ?: [];

    $conn->begin_transaction();
    try {
        $conn->query("UPDATE ten_design_versions SET is_live=0 WHERE publication='" . $conn->real_escape_string($pub) . "'");
        $up = $conn->prepare("UPDATE ten_design_versions SET is_live=1 WHERE publication=? AND version_no=?");
        $up->bind_param('si', $pub, $version_no);
        $up->execute();
        $up->close();

        foreach ($bundle as $page => $devices) {
            foreach ($devices as $device => $layout) {
                if (is_array($layout)) {
                    dl_save_draft($pub, (string)$page, (string)$device, $layout, $user ?? 'rollback', $conn);
                }
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        throw $e;
    }
    $conn->close();
}
