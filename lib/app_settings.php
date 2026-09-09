<?php
/**
 * Tiny global key/value settings store (TEN_Management DB).
 * Used for app-wide preferences that don't warrant their own table, e.g. the
 * default publication + timeframe for the General Statistics page.
 *
 * The table is created on first use so no separate migration step is needed.
 */

function ten_settings_conn() {
    static $c = null;
    if ($c === null) { $c = getDBConnection(); }
    return $c;
}

function ten_settings_ensure_table(): void {
    static $done = false;
    if ($done) return;
    $c = ten_settings_conn();
    @$c->query("CREATE TABLE IF NOT EXISTS ten_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Read a setting, returning $default when missing/empty. */
function ten_get_setting(string $key, ?string $default = null): ?string {
    ten_settings_ensure_table();
    $c = ten_settings_conn();
    $st = $c->prepare("SELECT setting_value FROM ten_settings WHERE setting_key=?");
    if (!$st) return $default;
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') return $default;
    return $row['setting_value'];
}

/** Insert/update a setting. */
function ten_set_setting(string $key, string $value): bool {
    ten_settings_ensure_table();
    $c = ten_settings_conn();
    $st = $c->prepare("INSERT INTO ten_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    if (!$st) return false;
    $st->bind_param('ss', $key, $value);
    $ok = $st->execute();
    $st->close();
    return $ok;
}
