<?php
/**
 * Credential vault — AES-256-GCM encryption at rest for retrievable secrets
 * (email / social / other account passwords), used by the Password Manager module.
 *
 * KEY HANDLING: the 256-bit key lives in a file OUTSIDE the web root and OUT of git
 * (/home/tenuser/private/ten_vault_key). It is generated on first use and never
 * leaves the server. If that file is lost, stored secrets can no longer be decrypted
 * — back it up. Login passwords are NOT stored here (those are one-way bcrypt-hashed).
 */

/** Return the raw 32-byte vault key, generating + persisting it on first use. */
function ten_vault_key(): string {
    static $key = null;
    if ($key !== null) { return $key; }
    $dir = '/home/tenuser/private';
    if (!@is_dir($dir)) {
        // Fallback for a non-standard host: a 'private' sibling of the management dir.
        $dir = dirname(__DIR__) . '/../private';
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/ten_vault_key';
    if (@is_file($path)) {
        $k = base64_decode(trim((string) @file_get_contents($path)), true);
        if ($k !== false && strlen($k) === 32) { return $key = $k; }
    }
    $k = random_bytes(32);
    @file_put_contents($path, base64_encode($k), LOCK_EX);
    @chmod($path, 0600);
    return $key = $k;
}

/** Encrypt a plaintext secret → base64(iv[12] + tag[16] + ciphertext). '' stays ''. */
function ten_vault_encrypt(string $plain): string {
    if ($plain === '') { return ''; }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', ten_vault_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) { throw new RuntimeException('vault encrypt failed'); }
    return base64_encode($iv . $tag . $ct);
}

/** Decrypt a value produced by ten_vault_encrypt(); null on any failure/tamper. */
function ten_vault_decrypt(string $enc): ?string {
    if ($enc === '') { return ''; }
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) { return null; }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', ten_vault_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

/** Allowed credential categories (value => label). */
function ten_credential_categories(): array {
    return ['email' => 'Email account', 'social' => 'Social / other'];
}

/** Create the credential-vault table on first use (idempotent). Lives in TEN_Management. */
function ten_credentials_ensure_schema($c): void {
    $c->query("CREATE TABLE IF NOT EXISTS ten_credentials (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        category    VARCHAR(24)  NOT NULL DEFAULT 'other',
        label       VARCHAR(160) NOT NULL,
        host        VARCHAR(190) NULL,
        username    VARCHAR(190) NULL,
        secret_enc  MEDIUMTEXT   NULL,
        url         VARCHAR(255) NULL,
        notes       TEXT         NULL,
        created_by  INT NULL,
        updated_by  INT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cat (category, label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
