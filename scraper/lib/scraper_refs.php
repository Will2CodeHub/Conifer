<?php
/**
 * Reference lists for scraper config dropdowns.
 * Publications + sections come from the admin_ten DB; journalists from ten_users.
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}
// Publications + sections live in the admin_ten DB, reached via this helper,
// which is defined in config_ten_admin.php (not config.php). Same pattern as
// ajax/get_article_data.php.
if (!function_exists('getDBConnection_TENAdmin')) {
    // config_ten_admin.php re-defines constants config.php already set (and calls
    // session_start again), which emits warnings. Load it quietly so nothing
    // leaks into JSON responses; the identical re-defines are harmless.
    $__er = error_reporting(0);
    require_once __DIR__ . '/../../config_ten_admin.php';
    error_reporting($__er);
}

/**
 * Live publications: [{value: publication_key, label: title}].
 */
function scraper_get_publications(): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) {
        return [];
    }
    $out = [];
    $res = $conn->query("SELECT publication, title FROM publications WHERE pub_live = 1 ORDER BY title ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = ['value' => $row['publication'], 'label' => $row['title'] ?: $row['publication']];
        }
    }
    $conn->close();
    return $out;
}

/**
 * Active users usable as journalists: [{value: id, label: "Full Name (username)"}].
 */
function scraper_get_journalists(): array {
    $conn = getDBConnection();
    $out = [];
    $res = $conn->query("SELECT id, full_name, username FROM ten_users WHERE status = 'active' ORDER BY full_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $label = $row['full_name'] . ' (' . $row['username'] . ')';
            $out[] = ['value' => (int)$row['id'], 'label' => $label];
        }
    }
    $conn->close();
    return $out;
}

/**
 * Top-level TEN sections from main_menu: [{value: name, label: name}].
 * Mirrors ajax/get_article_data.php (section_item='1', parent_item=0, id != 79).
 */
function scraper_get_ten_sections(): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) {
        return [];
    }
    $out = [];
    $res = $conn->query("SELECT DISTINCT name FROM main_menu WHERE section_item = '1' AND parent_item = 0 AND id != 79 ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = ['value' => $row['name'], 'label' => $row['name']];
        }
    }
    $conn->close();
    return $out;
}

/**
 * Selectable AI models grouped by provider.
 * Edit this one list to change the model choices offered everywhere.
 */
function scraper_get_ai_models(): array {
    return [
        'anthropic' => [
            'claude-opus-5',
            'claude-sonnet-5',
            'claude-haiku-4-5',
            'claude-fable-5-1',
        ],
        'openai' => [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4.1',
            'gpt-4.1-mini',
        ],
    ];
}
