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

/** All owned publications: id, publication (acronym), title, url, pub_live, target_language. */
function ns_list_publications(): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return [];
    $out = [];
    $res = $conn->query("SELECT id, publication, title, url, pub_live, target_language, max_daily_translations, breaking_news_enabled, design_enabled FROM publications ORDER BY title ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
    }
    $conn->close();
    return $out;
}

/** Max translations/day for a publication key (0 = unlimited). */
function ns_publication_daily_cap(string $publicationKey): int {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return 0;
    $stmt = $conn->prepare("SELECT max_daily_translations FROM publications WHERE publication = ? LIMIT 1");
    $stmt->bind_param('s', $publicationKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)($row['max_daily_translations'] ?? 0);
}

function ns_get_publication(int $id): ?array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, publication, title, url, pub_live, target_language, max_daily_translations, breaking_news_enabled, design_enabled FROM publications WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/** Target language for a publication key (defaults to English). */
function ns_publication_language(string $publicationKey): string {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return 'English';
    $stmt = $conn->prepare("SELECT target_language FROM publications WHERE publication = ? LIMIT 1");
    $stmt->bind_param('s', $publicationKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    $lang = $row['target_language'] ?? '';
    return ($lang !== null && $lang !== '') ? $lang : 'English';
}

/** Insert or update a publication (owned site). Returns the id. */
function ns_save_publication(array $d): int {
    $conn = getDBConnection_TENAdmin();
    $id       = isset($d['id']) ? (int)$d['id'] : 0;
    $acronym  = trim((string)($d['publication'] ?? ''));
    $title    = trim((string)($d['title'] ?? ''));
    $url      = trim((string)($d['url'] ?? ''));
    $live     = !empty($d['pub_live']) ? 1 : 0;
    $lang     = trim((string)($d['target_language'] ?? '')) ?: 'English';
    $cap      = isset($d['max_daily_translations']) && $d['max_daily_translations'] !== '' ? (int)$d['max_daily_translations'] : 0;
    $breaking = !empty($d['breaking_news_enabled']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE publications SET publication=?, title=?, url=?, pub_live=?, target_language=?, max_daily_translations=?, breaking_news_enabled=? WHERE id=?");
        $stmt->bind_param('sssisiii', $acronym, $title, $url, $live, $lang, $cap, $breaking, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO publications (publication, title, url, pub_live, target_language, max_daily_translations, breaking_news_enabled) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssisii', $acronym, $title, $url, $live, $lang, $cap, $breaking);
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

/**
 * Publications with the breaking-news desk switched on, as [publication_key => title].
 * Drives the breaking auto-publish cron and the front-page breaking section — set the
 * flag per publication in the TEN News Sites module.
 */
function ns_breaking_enabled_publications(): array {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return [];
    $out = [];
    $res = $conn->query("SELECT publication, title FROM publications WHERE breaking_news_enabled=1 ORDER BY title ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = trim((string)$row['publication']);
            if ($key !== '') $out[$key] = ($row['title'] !== '' ? $row['title'] : strtoupper($key));
        }
    }
    $conn->close();
    return $out;
}

/** True if a publication key has the breaking-news desk switched on. */
function ns_publication_breaking_enabled(string $publicationKey): bool {
    $conn = getDBConnection_TENAdmin();
    if (!$conn) return false;
    $stmt = $conn->prepare("SELECT breaking_news_enabled FROM publications WHERE publication = ? LIMIT 1");
    $stmt->bind_param('s', $publicationKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)($row['breaking_news_enabled'] ?? 0) === 1;
}
