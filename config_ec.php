<?php
/**
 * Email Campaign Manager config. Kept separate from the main config so the
 * secret key lives in its own file. Required by lib/ec_core.php.
 */
if (!defined('EC_SECRET_KEY')) {
    // 32-byte key (base64) for AES-256-GCM encryption of stored SMTP/IMAP passwords.
    define('EC_SECRET_KEY', 'e/ZGRre9PcrctjlMwMo1Yc4hmL0ogzrY1xJ3xrDVtXo=');
}
