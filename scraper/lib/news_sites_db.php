<?php
/**
 * TEN News Sites data access.
 * Publications live in admin_ten (getDBConnection_TENAdmin); their scraper feed
 * URLs are rolled up from the TEN_Management scraper tables (read-only here —
 * they are edited in the Data Scraper module).
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}
if (!function_exists('getDBConnection_TENAdmin')) {
    $__er = error_reporting(0);
    require_once __DIR__ . '/../../config_ten_admin.php';
    error_reporting($__er);
}

/** All owned publications: id, publication (acronym), title, url, pub_live. */
function ns_list_publications(): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return [];
    $out = [];
    $res = $conn->query("SELECT id, publication, title, url, pub_live FROM publications ORDER BY title ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
    }
    $conn->close();
    return $out;
}

function ns_get_publication(int $id): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, publication, title, url, pub_live FROM publications WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/** Insert or update a publication (owned site). Returns the id. */
function ns_save_publication(array $d): int {
    $conn = getDBConnection_TENAdmin();
    $id       = isset($d['id']) ? (int)$d['id'] : 0;
    $acronym  = trim((string)($d['publication'] ?? ''));
    $title    = trim((string)($d['title'] ?? ''));
    $url      = trim((string)($d['url'] ?? ''));
    $live     = !empty($d['pub_live']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE publications SET publication=?, title=?, url=?, pub_live=? WHERE id=?");
        $stmt->bind_param('sssii', $acronym, $title, $url, $live, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO publications (publication, title, url, pub_live) VALUES (?,?,?,?)");
        $stmt->bind_param('sssi', $acronym, $title, $url, $live);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
    }
    $conn->close();
    return $id;
}

/**
 * Scraper feed URLs configured to feed a given publication (by acronym/key),
 * rolled up across its sections and sources. Read-only view.
 * Returns rows: ten_section, source_name, feed_url, feed_type, source_category_label.
 */
function ns_feeds_for_publication(string $publicationKey): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT ps.ten_section, src.name AS source_name, f.feed_url, f.feed_type, f.source_category_label
         FROM ten_scraper_feeds f
         JOIN ten_scraper_sources src ON src.id = f.source_id
         JOIN ten_scraper_pub_sections ps ON ps.id = src.pub_section_id
         WHERE ps.publication_key = ?
         ORDER BY ps.ten_section, src.name, f.id"
    );
    $stmt->bind_param('s', $publicationKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}
