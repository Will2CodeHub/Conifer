<?php
/**
 * Scraper config CRUD (Phase 2): sections, sources, feeds, VPN profiles, prompts.
 * All against the TEN_Management DB via getDBConnection(). Prepared statements throughout.
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}

/* ------------------------------------------------------------------ sections */

/** Enable/disable one section's scraping (is_active gates the worker + its AI). */
function scraper_set_section_active(int $id, int $active): bool {
    $conn = getDBConnection();
    $active = $active ? 1 : 0;
    $s = $conn->prepare("UPDATE ten_scraper_pub_sections SET is_active=? WHERE id=?");
    $s->bind_param('ii', $active, $id);
    $ok = $s->execute(); $s->close(); $conn->close();
    return $ok;
}

/** Enable/disable ALL sections of a publication within a project (one click). */
function scraper_set_publication_active(int $projectId, string $pubKey, int $active): int {
    $conn = getDBConnection();
    $active = $active ? 1 : 0;
    $s = $conn->prepare("UPDATE ten_scraper_pub_sections SET is_active=? WHERE project_id=? AND publication_key=?");
    $s->bind_param('iis', $active, $projectId, $pubKey);
    $s->execute(); $n = $s->affected_rows; $s->close(); $conn->close();
    return $n;
}

function scraper_list_sections(int $projectId): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM ten_scraper_sources src WHERE src.pub_section_id = s.id) AS source_count
         FROM ten_scraper_pub_sections s
         WHERE s.project_id = ?
         ORDER BY s.publication_key, s.ten_section"
    );
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function scraper_get_section(int $id): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM ten_scraper_pub_sections WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * Create a section. Copies the project's default_prompt into the section prompt
 * unless a prompt is supplied.
 */
function scraper_create_section(array $d): int {
    $conn = getDBConnection();

    $prompt = $d['prompt'] ?? null;
    if ($prompt === null) {
        $ps = $conn->prepare("SELECT default_prompt FROM ten_scraper_projects WHERE id = ?");
        $projectId = (int)$d['project_id'];
        $ps->bind_param('i', $projectId);
        $ps->execute();
        $row = $ps->get_result()->fetch_assoc();
        $ps->close();
        $prompt = $row['default_prompt'] ?? null;
    }

    $projectId     = (int)$d['project_id'];
    $publication   = (string)$d['publication_key'];
    $section       = (string)$d['ten_section'];
    $dailyCount    = (int)($d['daily_count'] ?? 10);
    $cron          = (string)($d['cron_schedule'] ?? '0 6 * * *');
    $vpnProfileId  = isset($d['vpn_profile_id']) && $d['vpn_profile_id'] !== '' ? (int)$d['vpn_profile_id'] : null;
    $journalistId  = isset($d['journalist_id']) && $d['journalist_id'] !== '' ? (int)$d['journalist_id'] : null;
    $aiProvider    = isset($d['ai_provider']) && $d['ai_provider'] !== '' ? (string)$d['ai_provider'] : null;
    $aiModel       = isset($d['ai_model']) && $d['ai_model'] !== '' ? (string)$d['ai_model'] : null;
    $autoPublish   = !empty($d['auto_publish']) ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO ten_scraper_pub_sections
         (project_id, publication_key, ten_section, daily_count, cron_schedule,
          vpn_profile_id, journalist_id, ai_provider, ai_model, prompt, auto_publish)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param(
        'issisiisssi',
        $projectId, $publication, $section, $dailyCount, $cron,
        $vpnProfileId, $journalistId, $aiProvider, $aiModel, $prompt, $autoPublish
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    return $id;
}

function scraper_update_section(int $id, array $d): bool {
    $conn = getDBConnection();
    $dailyCount    = (int)($d['daily_count'] ?? 10);
    $cron          = (string)($d['cron_schedule'] ?? '0 6 * * *');
    $vpnProfileId  = isset($d['vpn_profile_id']) && $d['vpn_profile_id'] !== '' ? (int)$d['vpn_profile_id'] : null;
    $journalistId  = isset($d['journalist_id']) && $d['journalist_id'] !== '' ? (int)$d['journalist_id'] : null;
    $aiProvider    = isset($d['ai_provider']) && $d['ai_provider'] !== '' ? (string)$d['ai_provider'] : null;
    $aiModel       = isset($d['ai_model']) && $d['ai_model'] !== '' ? (string)$d['ai_model'] : null;
    $prompt        = isset($d['prompt']) ? (string)$d['prompt'] : null;
    $autoPublish   = !empty($d['auto_publish']) ? 1 : 0;
    $isActive      = isset($d['is_active']) ? (int)!empty($d['is_active']) : 1;

    $stmt = $conn->prepare(
        "UPDATE ten_scraper_pub_sections
         SET daily_count=?, cron_schedule=?, vpn_profile_id=?, journalist_id=?,
             ai_provider=?, ai_model=?, prompt=?, auto_publish=?, is_active=?
         WHERE id=?"
    );
    $stmt->bind_param(
        'isiisssiii',
        $dailyCount, $cron, $vpnProfileId, $journalistId,
        $aiProvider, $aiModel, $prompt, $autoPublish, $isActive, $id
    );
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

function scraper_delete_section(int $id): bool {
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        // delete feeds under this section's sources
        $conn->query("DELETE f FROM ten_scraper_feeds f
                      JOIN ten_scraper_sources s ON s.id = f.source_id
                      WHERE s.pub_section_id = " . (int)$id);
        // delete sources
        $s = $conn->prepare("DELETE FROM ten_scraper_sources WHERE pub_section_id = ?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        // delete section
        $s = $conn->prepare("DELETE FROM ten_scraper_pub_sections WHERE id = ?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        $conn->commit();
        $ok = true;
    } catch (Throwable $e) {
        $conn->rollback();
        $ok = false;
    }
    $conn->close();
    return $ok;
}

/* ------------------------------------------------------------------- sources */

function scraper_list_sources(int $pubSectionId): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT src.*,
                (SELECT COUNT(*) FROM ten_scraper_feeds f WHERE f.source_id = src.id) AS feed_count
         FROM ten_scraper_sources src
         WHERE src.pub_section_id = ?
         ORDER BY src.name"
    );
    $stmt->bind_param('i', $pubSectionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function scraper_create_source(array $d): int {
    $conn = getDBConnection();
    $pubSectionId = (int)$d['pub_section_id'];
    $name         = (string)$d['name'];
    $homepage     = (string)$d['homepage_url'];
    $stmt = $conn->prepare(
        "INSERT INTO ten_scraper_sources (pub_section_id, name, homepage_url) VALUES (?,?,?)"
    );
    $stmt->bind_param('iss', $pubSectionId, $name, $homepage);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    return $id;
}

function scraper_update_source(int $id, array $d): bool {
    $conn = getDBConnection();
    $name     = (string)$d['name'];
    $homepage = (string)$d['homepage_url'];
    $isActive = isset($d['is_active']) ? (int)!empty($d['is_active']) : 1;
    $stmt = $conn->prepare(
        "UPDATE ten_scraper_sources SET name=?, homepage_url=?, is_active=? WHERE id=?"
    );
    $stmt->bind_param('ssii', $name, $homepage, $isActive, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

function scraper_delete_source(int $id): bool {
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        $s = $conn->prepare("DELETE FROM ten_scraper_feeds WHERE source_id = ?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        $s = $conn->prepare("DELETE FROM ten_scraper_sources WHERE id = ?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        $conn->commit();
        $ok = true;
    } catch (Throwable $e) {
        $conn->rollback();
        $ok = false;
    }
    $conn->close();
    return $ok;
}

/* --------------------------------------------------------------------- feeds */

function scraper_list_feeds(int $sourceId): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM ten_scraper_feeds WHERE source_id = ? ORDER BY id");
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function scraper_create_feed(array $d): int {
    $conn = getDBConnection();
    $sourceId   = (int)$d['source_id'];
    $feedUrl    = (string)$d['feed_url'];
    $feedType   = ($d['feed_type'] ?? 'rss') === 'html' ? 'html' : 'rss';
    $category   = isset($d['source_category_label']) && $d['source_category_label'] !== '' ? (string)$d['source_category_label'] : null;
    $respect    = isset($d['respect_robots']) ? (int)!empty($d['respect_robots']) : 1;
    $override   = ($respect === 0 && !empty($d['robots_override_reason'])) ? (string)$d['robots_override_reason'] : null;
    $rate       = isset($d['rate_limit_seconds']) && $d['rate_limit_seconds'] !== '' ? (int)$d['rate_limit_seconds'] : null;
    $maxItems   = isset($d['max_items']) && $d['max_items'] !== '' ? (int)$d['max_items'] : 20;

    $stmt = $conn->prepare(
        "INSERT INTO ten_scraper_feeds
         (source_id, feed_url, feed_type, source_category_label, respect_robots, robots_override_reason, rate_limit_seconds, max_items)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('isssisii', $sourceId, $feedUrl, $feedType, $category, $respect, $override, $rate, $maxItems);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    return $id;
}

function scraper_update_feed(int $id, array $d): bool {
    $conn = getDBConnection();
    $feedUrl    = (string)$d['feed_url'];
    $feedType   = ($d['feed_type'] ?? 'rss') === 'html' ? 'html' : 'rss';
    $category   = isset($d['source_category_label']) && $d['source_category_label'] !== '' ? (string)$d['source_category_label'] : null;
    $respect    = isset($d['respect_robots']) ? (int)!empty($d['respect_robots']) : 1;
    $override   = ($respect === 0 && !empty($d['robots_override_reason'])) ? (string)$d['robots_override_reason'] : null;
    $rate       = isset($d['rate_limit_seconds']) && $d['rate_limit_seconds'] !== '' ? (int)$d['rate_limit_seconds'] : null;
    $maxItems   = isset($d['max_items']) && $d['max_items'] !== '' ? (int)$d['max_items'] : 20;
    $isActive   = isset($d['is_active']) ? (int)!empty($d['is_active']) : 1;

    $stmt = $conn->prepare(
        "UPDATE ten_scraper_feeds
         SET feed_url=?, feed_type=?, source_category_label=?, respect_robots=?, robots_override_reason=?, rate_limit_seconds=?, max_items=?, is_active=?
         WHERE id=?"
    );
    $stmt->bind_param('sssisiiii', $feedUrl, $feedType, $category, $respect, $override, $rate, $maxItems, $isActive, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

function scraper_delete_feed(int $id): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM ten_scraper_feeds WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

/* -------------------------------------------------------------- vpn profiles */

function scraper_list_vpn_profiles(): array {
    $conn = getDBConnection();
    $res = $conn->query("SELECT * FROM ten_scraper_vpn_profiles ORDER BY name");
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $conn->close();
    return $rows;
}

function scraper_save_vpn_profile(array $d): int {
    $conn = getDBConnection();
    $id        = isset($d['id']) ? (int)$d['id'] : 0;
    $provider  = in_array(($d['provider'] ?? ''), ['protonvpn','wireguard','openvpn'], true) ? $d['provider'] : 'protonvpn';
    $name      = (string)$d['name'];
    $country   = isset($d['country']) ? (string)$d['country'] : null;
    $configRef = isset($d['config_ref']) ? (string)$d['config_ref'] : null;
    $isActive  = isset($d['is_active']) ? (int)!empty($d['is_active']) : 1;

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE ten_scraper_vpn_profiles SET provider=?, name=?, country=?, config_ref=?, is_active=? WHERE id=?");
        $stmt->bind_param('ssssii', $provider, $name, $country, $configRef, $isActive, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO ten_scraper_vpn_profiles (provider, name, country, config_ref, is_active) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssssi', $provider, $name, $country, $configRef, $isActive);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
    }
    $conn->close();
    return $id;
}

function scraper_delete_vpn_profile(int $id): bool {
    $conn = getDBConnection();
    // Null out references from sections first so we never point at a missing profile.
    $conn->query("UPDATE ten_scraper_pub_sections SET vpn_profile_id = NULL WHERE vpn_profile_id = " . (int)$id);
    $stmt = $conn->prepare("DELETE FROM ten_scraper_vpn_profiles WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

/* ------------------------------------------------------------------- prompts */

function scraper_get_project(int $id): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id, name, type, default_ai_provider, default_ai_model, default_prompt, translation_provider, translation_model FROM ten_scraper_projects WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

function scraper_update_project_settings(int $id, string $prompt, string $provider, string $model, string $translationProvider, string $translationModel): bool {
    $conn = getDBConnection();
    $provider = $provider === 'openai' ? 'openai' : 'anthropic';
    $translationProvider = $translationProvider === 'openai' ? 'openai' : 'anthropic';
    $stmt = $conn->prepare(
        "UPDATE ten_scraper_projects
         SET default_prompt=?, default_ai_provider=?, default_ai_model=?, translation_provider=?, translation_model=?
         WHERE id=?"
    );
    $stmt->bind_param('sssssi', $prompt, $provider, $model, $translationProvider, $translationModel, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}
