<?php
/**
 * Scraper data-access helpers (Phase 1: reads only).
 * Reuses the TEN_Management connection from config.php.
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}

/**
 * Return all active scraper projects, ordered by id.
 * @return array<int,array<string,mixed>>
 */
function scraper_get_projects(): array {
    $conn = getDBConnection();
    $sql = "SELECT id, name, type, default_ai_provider, default_ai_model, is_active
            FROM ten_scraper_projects
            WHERE is_active = 1
            ORDER BY id ASC";
    $result = $conn->query($sql);
    $projects = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    $conn->close();
    return $projects;
}

/**
 * Return a single active project by its type, or null.
 */
function scraper_get_project_by_type(string $type): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, name, type, default_ai_provider, default_ai_model, is_active
         FROM ten_scraper_projects
         WHERE type = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}
