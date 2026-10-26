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
    if ($page === '_blocks' || $page === '_pages' || $page === '_templates' || $page === '_assign') {
        $store = $layout; // meta rows (block registry / page registry / templates / assignments), stored as-is
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

/* ---- per-item Save history (ten_design_history) ---------------------------
 * Each explicit Save appends one snapshot for that page/block/template, kept in
 * its own table so it persists across sessions and is revertable. Capped per item. */

function dl_add_history(string $pub, string $kind, string $id, string $snapshot, ?string $user): void {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return; }
    $st = $conn->prepare("INSERT INTO ten_design_history (publication, item_kind, item_id, snapshot_json, created_by) VALUES (?, ?, ?, ?, ?)");
    $st->bind_param('sssss', $pub, $kind, $id, $snapshot, $user);
    $st->execute();
    $st->close();
    // Keep only the newest 60 for this item.
    $cap = $conn->prepare("DELETE FROM ten_design_history WHERE publication=? AND item_kind=? AND item_id=? AND id NOT IN (
        SELECT id FROM (SELECT id FROM ten_design_history WHERE publication=? AND item_kind=? AND item_id=? ORDER BY id DESC LIMIT 60) t)");
    $cap->bind_param('ssssss', $pub, $kind, $id, $pub, $kind, $id);
    $cap->execute();
    $cap->close();
    $conn->close();
}

/** History rows (id, created_at, created_by) for one item, newest first. */
function dl_list_history(string $pub, string $kind, string $id): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return []; }
    $st = $conn->prepare("SELECT id, created_at, created_by FROM ten_design_history WHERE publication=? AND item_kind=? AND item_id=? ORDER BY id DESC");
    $st->bind_param('sss', $pub, $kind, $id);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    $st->close();
    $conn->close();
    return $out;
}

/** One history snapshot (decoded), or null. Scoped to the pub for safety. */
function dl_get_history(string $pub, int $histId): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return null; }
    $st = $conn->prepare("SELECT snapshot_json FROM ten_design_history WHERE id=? AND publication=? LIMIT 1");
    $st->bind_param('is', $histId, $pub);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();
    return $row ? json_decode($row['snapshot_json'], true) : null;
}

/** A specific version's snapshot bundle, or null. */
function dl_get_version(string $pub, int $version_no): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { return null; }
    $st = $conn->prepare("SELECT snapshot_json FROM ten_design_versions WHERE publication=? AND version_no=? LIMIT 1");
    $st->bind_param('si', $pub, $version_no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();
    return $row ? json_decode($row['snapshot_json'], true) : null;
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
    // Normalise: every template has a 'blocks' array (derive from legacy header/footer).
    foreach ($tpls as &$t) {
        if (empty($t['blocks']) || !is_array($t['blocks'])) {
            $t['blocks'] = [];
            if (!empty($t['header'])) { $t['blocks'][] = $t['header']; }
            if (!empty($t['footer'])) { $t['blocks'][] = $t['footer']; }
        }
    }
    unset($t);
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
        'labels'    => is_array($raw['labels'] ?? null)    ? $raw['labels']    : [],  // built-in block display-label overrides {type:label}
    ];
}

/** Create a new named template, cloned from a base template (default tpl_default). */
function dl_create_template(string $pub, string $name, string $base, string $user): array {
    $name = trim($name);
    if ($name === '') { throw new RuntimeException('Template name required'); }
    $meta = dl_load_templates($pub);
    $baseTpl = null;
    foreach ($meta['templates'] as $t) { if (($t['id'] ?? '') === $base) { $baseTpl = $t; break; } }
    if (!$baseTpl) { $baseTpl = dl_builtin_template(); }
    $row = $baseTpl;   // copy the base template's blocks + page_style
    $row['id']         = 'tpl_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $row['name']       = $name;
    $row['created_by'] = $user;
    if (empty($row['blocks']) || !is_array($row['blocks'])) {
        $row['blocks'] = [];
        if (!empty($baseTpl['header'])) { $row['blocks'][] = $baseTpl['header']; }
        if (!empty($baseTpl['footer'])) { $row['blocks'][] = $baseTpl['footer']; }
    }
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
    // Seed the page from its template's blocks so it starts as a real layout.
    $tplId = $template !== '' ? $template : 'tpl_default';
    $tplBlocks = [];
    foreach (dl_load_templates($pub)['templates'] as $t) {
        if (($t['id'] ?? '') === $tplId) { $tplBlocks = is_array($t['blocks'] ?? null) ? $t['blocks'] : []; break; }
    }
    dl_save_draft($pub, $slug, 'desktop', ['theme' => [], 'blocks' => $tplBlocks], $user);
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

/** Delete a user-created template. Built-in tpl_default cannot be deleted.
 *  Also clears any page→template assignments that pointed at it. */
function dl_delete_template(string $pub, string $id, string $user): void {
    if ($id === 'tpl_default') { throw new RuntimeException('The default template cannot be deleted'); }
    $meta = dl_load_templates($pub);
    $found = false;
    $kept = [];
    foreach ($meta['templates'] as $t) {
        if (($t['id'] ?? '') === $id) { $found = true; continue; }
        $kept[] = $t;
    }
    if (!$found) { throw new RuntimeException('Template not found'); }
    dl_save_draft($pub, '_templates', 'desktop', ['templates' => $kept], $user);
    // Drop assignments that referenced the deleted template.
    $assign = dl_load_assign($pub);
    $changed = false;
    foreach ($assign as $slug => $tpl) { if ($tpl === $id) { unset($assign[$slug]); $changed = true; } }
    if ($changed) { dl_save_draft($pub, '_assign', 'desktop', ['assign' => $assign], $user); }
}

/** Page → template assignments as [slug => template_id]. '' means "no frame". */
function dl_load_assign(string $pub): array {
    $raw = dl_load_draft($pub, '_assign', 'desktop');
    return (is_array($raw) && isset($raw['assign']) && is_array($raw['assign'])) ? $raw['assign'] : [];
}

/** Assign ($tplId) or unassign ('') a template to a page slug. */
function dl_set_assign(string $pub, string $slug, string $tplId, string $user): void {
    $slug = trim($slug);
    if ($slug === '') { throw new RuntimeException('Page required'); }
    $assign = dl_load_assign($pub);
    $assign[$slug] = $tplId;   // '' = explicitly no frame (overrides a custom page default)
    dl_save_draft($pub, '_assign', 'desktop', ['assign' => $assign], $user);
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

    // block — either a custom (blk_*) block's name, or a built-in block's studio
    // display label (an override stored in the _blocks 'labels' map; does not
    // affect the rendered site).
    $meta = dl_load_blocks_meta($pub);
    if (isset($meta['names'][$id])) {
        if (($meta['names'][$id]['created_by'] ?? null) !== $user) {
            throw new RuntimeException('You can only rename blocks you created');
        }
        $meta['names'][$id]['name'] = $name;
    } elseif (array_key_exists($id, dl_registry())) {
        $meta['labels'][$id] = $name;   // built-in block: rename its studio label only
    } else {
        throw new RuntimeException('Unknown block');
    }
    dl_save_draft($pub, '_blocks', 'desktop', $meta, $user);
}

/** Remove every instance of a block $type from all page drafts and templates.
 *  Used when a custom block is deleted. Runs inside the caller's transaction. */
function dl_strip_block_type(string $pub, string $type, ?string $user, mysqli $conn): void {
    $drafts = dl_all_drafts($pub, $conn);
    foreach ($drafts as $page => $devices) {
        if ($page === '' || $page[0] === '_') { continue; }   // skip meta rows
        foreach ($devices as $device => $layout) {
            if (!is_array($layout) || empty($layout['blocks']) || !is_array($layout['blocks'])) { continue; }
            $before = count($layout['blocks']);
            $layout['blocks'] = array_values(array_filter($layout['blocks'], function ($b) use ($type) {
                return ($b['type'] ?? '') !== $type;
            }));
            if (count($layout['blocks']) !== $before) {
                dl_save_draft($pub, (string)$page, (string)$device, $layout, $user, $conn);
            }
        }
    }
    $tplRaw = dl_load_draft($pub, '_templates', 'desktop');
    if ($tplRaw && isset($tplRaw['templates']) && is_array($tplRaw['templates'])) {
        $changed = false;
        foreach ($tplRaw['templates'] as &$t) {
            if (empty($t['blocks']) || !is_array($t['blocks'])) { continue; }
            $before = count($t['blocks']);
            $t['blocks'] = array_values(array_filter($t['blocks'], function ($b) use ($type) {
                return ($b['type'] ?? '') !== $type;
            }));
            if (count($t['blocks']) !== $before) { $changed = true; }
        }
        unset($t);
        if ($changed) { dl_save_draft($pub, '_templates', 'desktop', $tplRaw, $user, $conn); }
    }
}

/** Delete a user-created (blk_*) block: remove its meta and strip every instance
 *  from pages + templates, atomically. Built-in blocks cannot be deleted. */
function dl_delete_block(string $pub, string $type, string $user): void {
    if (strpos($type, 'blk_') !== 0) { throw new RuntimeException('Built-in blocks cannot be deleted'); }
    $meta = dl_load_blocks_meta($pub);
    if (!isset($meta['names'][$type]) && !isset($meta['templates'][$type])) {
        throw new RuntimeException('Block not found');
    }
    if (isset($meta['names'][$type]) && ($meta['names'][$type]['created_by'] ?? null) !== $user) {
        throw new RuntimeException('You can only delete blocks you created');
    }
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { throw new RuntimeException('admin_ten connection failed'); }
    $conn->begin_transaction();
    try {
        unset($meta['names'][$type], $meta['templates'][$type], $meta['settings'][$type], $meta['labels'][$type]);
        dl_save_draft($pub, '_blocks', 'desktop', $meta, $user, $conn);
        dl_strip_block_type($pub, $type, $user, $conn);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback(); $conn->close(); throw $e;
    }
    $conn->close();
}

/** Delete a custom page: its meta row, its layout rows, and its assignment.
 *  Built-in page types cannot be deleted. Creator-only. */
function dl_delete_page(string $pub, string $slug, string $user): void {
    $slug = strtolower(trim($slug));
    foreach (dl_builtin_pages() as $b) {
        if ($b['slug'] === $slug) { throw new RuntimeException('Built-in pages cannot be deleted'); }
    }
    $pages = dl_load_pages($pub);
    $found = false; $kept = [];
    foreach ($pages['pages'] as $p) {
        if (($p['slug'] ?? '') === $slug) {
            $found = true;
            if (($p['created_by'] ?? null) !== $user) { throw new RuntimeException('You can only delete pages you created'); }
            continue;
        }
        $kept[] = $p;
    }
    if (!$found) { throw new RuntimeException('Page not found'); }
    $conn = getDBConnection_TENAdmin();
    if (!$conn) { throw new RuntimeException('admin_ten connection failed'); }
    $conn->begin_transaction();
    try {
        dl_save_draft($pub, '_pages', 'desktop', ['pages' => $kept], $user, $conn);
        $del = $conn->prepare("DELETE FROM ten_design_layouts WHERE publication=? AND page_type=?");
        $del->bind_param('ss', $pub, $slug);
        $del->execute();
        $del->close();
        $assign = dl_load_assign($pub);
        if (array_key_exists($slug, $assign)) {
            unset($assign[$slug]);
            dl_save_draft($pub, '_assign', 'desktop', ['assign' => $assign], $user, $conn);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback(); $conn->close(); throw $e;
    }
    $conn->close();
}
