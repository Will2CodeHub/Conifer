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
    if ($page === '_blocks' || $page === '_pages' || $page === '_templates') {
        $store = $layout; // meta rows (block registry / page registry / templates), stored as-is
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

/* ---------------------------------------------------------------------------
 * Design Studio: templates, custom pages, named blocks.
 * All stored as meta page_type rows (_templates / _pages / _blocks) so the
 * existing publish snapshot covers them with no new tables.
 * ------------------------------------------------------------------------- */

/** Decoded _templates meta; always includes the built-in tpl_default. */
function dl_load_templates(string $pub): array {
    $raw  = dl_load_draft($pub, '_templates', 'desktop');
    $tpls = ($raw && isset($raw['templates']) && is_array($raw['templates'])) ? $raw['templates'] : [];
    $hasDefault = false;
    foreach ($tpls as $t) { if (($t['id'] ?? '') === 'tpl_default') { $hasDefault = true; break; } }
    if (!$hasDefault) { array_unshift($tpls, dl_builtin_template()); }
    return ['templates' => $tpls];
}

/** Decoded _pages meta ({pages:[...]}), empty list if none stored. */
function dl_load_pages(string $pub): array {
    $raw = dl_load_draft($pub, '_pages', 'desktop');
    return ['pages' => ($raw && isset($raw['pages']) && is_array($raw['pages'])) ? $raw['pages'] : []];
}

/** Decoded _blocks meta, guaranteeing templates/settings/names keys exist. */
function dl_load_blocks_meta(string $pub): array {
    $raw = dl_load_draft($pub, '_blocks', 'desktop') ?: [];
    return [
        'templates' => is_array($raw['templates'] ?? null) ? $raw['templates'] : [],
        'settings'  => is_array($raw['settings'] ?? null)  ? $raw['settings']  : [],
        'names'     => is_array($raw['names'] ?? null)     ? $raw['names']     : [],
    ];
}

/** Create a new named template, cloned from tpl_default. Returns the new row. */
function dl_create_template(string $pub, string $name, string $user): array {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Template name required'); }
    $meta = dl_load_templates($pub);
    $row = dl_builtin_template();
    $row['id']         = 'tpl_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $row['name']       = $name;
    $row['created_by'] = $user;
    $meta['templates'][] = $row;
    dl_save_draft($pub, '_templates', 'desktop', $meta, $user);
    return $row;
}

/** Create a new static content page based on a template. Returns the new row. */
function dl_create_page(string $pub, string $slug, string $title, string $template, string $user): array {
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $slug)) {
        throw new RuntimeException('Slug must be lowercase letters, numbers and hyphens');
    }
    foreach (dl_builtin_pages() as $b) {
        if ($b['slug'] === $slug) { throw new RuntimeException('That slug is a built-in page'); }
    }
    $pages = dl_load_pages($pub);
    foreach ($pages['pages'] as $p) {
        if (($p['slug'] ?? '') === $slug) { throw new RuntimeException('A page with that slug already exists'); }
    }
    // Seed an empty body layout; header/footer come from the template at render time.
    dl_save_draft($pub, $slug, 'desktop', ['theme' => [], 'blocks' => []], $user);
    $row = [
        'slug'       => $slug,
        'title'      => trim($title) !== '' ? trim($title) : $slug,
        'template'   => $template !== '' ? $template : 'tpl_default',
        'order'      => count($pages['pages']) + 1,
        'created_by' => $user,
    ];
    $pages['pages'][] = $row;
    dl_save_draft($pub, '_pages', 'desktop', $pages, $user);
    return $row;
}

/** Create a new global, reusable named block. Returns its generated type key. */
function dl_create_named_block(string $pub, string $name, string $user): string {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Block name required'); }
    $type = 'blk_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $meta = dl_load_blocks_meta($pub);
    $meta['templates'][$type] = '<div class="dl-block-custom"><p>New block — edit me</p></div>';
    $meta['names'][$type]     = ['name' => $name, 'created_by' => $user];
    dl_save_draft($pub, '_blocks', 'desktop', $meta, $user);
    return $type;
}

/**
 * Rename a page / template / block. Rejects built-ins and items the caller does
 * not own ($kind: page|template|block).
 */
function dl_rename_item(string $pub, string $kind, string $id, string $name, string $user): void {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Name required'); }

    if ($kind === 'template') {
        if ($id === 'tpl_default') { throw new RuntimeException('Built-in templates cannot be renamed'); }
        $meta = dl_load_templates($pub); $found = false;
        foreach ($meta['templates'] as &$t) {
            if (($t['id'] ?? '') === $id) {
                if (($t['created_by'] ?? null) === null || $t['created_by'] !== $user) {
                    throw new RuntimeException('You can only rename items you created');
                }
                $t['name'] = $name; $found = true; break;
            }
        }
        unset($t);
        if (!$found) { throw new RuntimeException('Template not found'); }
        dl_save_draft($pub, '_templates', 'desktop', $meta, $user);
        return;
    }

    if ($kind === 'page') {
        $meta = dl_load_pages($pub); $found = false;
        foreach ($meta['pages'] as &$p) {
            if (($p['slug'] ?? '') === $id) {
                if (($p['created_by'] ?? null) !== $user) {
                    throw new RuntimeException('You can only rename pages you created');
                }
                $p['title'] = $name; $found = true; break;
            }
        }
        unset($p);
        if (!$found) { throw new RuntimeException('Page not found (built-in pages cannot be renamed)'); }
        dl_save_draft($pub, '_pages', 'desktop', $meta, $user);
        return;
    }

    // block
    $meta = dl_load_blocks_meta($pub);
    if (!isset($meta['names'][$id])) { throw new RuntimeException('Only blocks you created can be renamed'); }
    if (($meta['names'][$id]['created_by'] ?? null) !== $user) {
        throw new RuntimeException('You can only rename blocks you created');
    }
    $meta['names'][$id]['name'] = $name;
    dl_save_draft($pub, '_blocks', 'desktop', $meta, $user);
}
