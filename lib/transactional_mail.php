<?php
/**
 * Transactional email — account invites and password resets.
 *
 * These are sent through the authenticated SMTP relay the Email Campaign Manager
 * uses (a row in ten_ec_sending_profiles), NOT PHP mail(). Bare mail() from this
 * host has no authenticated envelope / SPF / DKIM alignment, so external providers
 * (Gmail, corporate filters, etc.) silently drop or spam-folder it — which is why
 * invite/reset emails were never arriving. We fall back to mail() only when no
 * usable SMTP profile exists, so behaviour degrades rather than breaks.
 *
 * Safe to include anywhere config.php is already loaded (ec_mailer pulls config.php;
 * both entry points — ajax/users.php and forgot-password.php — load it first).
 */
require_once __DIR__ . '/ec_mailer.php';

/**
 * A usable, active sending profile (decrypted via ec_profile), or null. Prefers a
 * profile whose From address is on a TEN domain so the sender reads as first-party.
 */
function ten_transactional_profile(): ?array {
    $c = ec_db();
    $rs = $c->query(
        "SELECT id FROM ten_ec_sending_profiles
         WHERE active = 1 AND smtp_host <> '' AND smtp_user <> '' AND smtp_pass <> ''
         ORDER BY (from_email LIKE '%themunicheye.com' OR from_email LIKE '%theeyenewspapers.com') DESC, id ASC
         LIMIT 1"
    );
    $row = $rs ? $rs->fetch_assoc() : null;
    return $row ? ec_profile((int)$row['id']) : null;
}

/**
 * Send one transactional message. The From address stays the profile's authenticated
 * mailbox (so SPF/DKIM align and the relay accepts it); $fromName is the friendly
 * display name shown to the recipient. Returns true if accepted for delivery.
 */
function ten_send_transactional_mail(string $toEmail, string $toName, string $subject, string $html, string $fromName = 'The Eye Newspapers'): bool {
    $p = ten_transactional_profile();
    if ($p) {
        $smtp = [
            'host'     => $p['smtp_host'],
            'port'     => $p['smtp_port'] ?: 587,
            'security' => $p['smtp_security'] ?: 'tls',
            'user'     => $p['smtp_user'],
            'pass'     => $p['smtp_pass_plain'] ?? '',
        ];
        $res = ec_send_message(
            ['email' => $toEmail, 'name' => $toName],
            $subject, $html, '',
            [
                'from_email' => $p['from_email'],
                'from_name'  => $fromName ?: ($p['from_name'] ?: 'The Eye Newspapers'),
                'reply_to'   => $p['reply_to'] ?: $p['from_email'],
                'smtp'       => $smtp,
            ]
        );
        if (!empty($res['ok'])) return true;
        error_log('ten_send_transactional_mail: SMTP send failed (' . ($res['error'] ?? '') . '); falling back to mail()');
    }
    // Last resort: PHP mail() (unreliable to external domains, but better than silence).
    return (bool) sendEmail($toEmail, $subject, $html);
}
