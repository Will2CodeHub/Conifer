<?php
/**
 * Email Campaign Manager — shared helpers.
 * DB accessors, tokens, slug, secret encrypt/decrypt, merge-field rendering,
 * tracking-link rewriting, suppression checks.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_ec.php';

/** TEN_Management connection (contacts/campaigns/etc all live here). */
function ec_db(): mysqli {
    $c = getDBConnection();
    @$c->set_charset('utf8mb4');
    return $c;
}

/** URL-safe random token. */
function ec_token(int $len = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '=');
}

/** Slug from arbitrary text. */
function ec_slug(string $s): string {
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($s));
    $s = trim(preg_replace('/-+/', '-', $s), '-');
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    return $s === '' ? ('item-' . time()) : $s;
}

/** AES-256-GCM encrypt a secret for storage. Returns base64(iv|tag|cipher). */
function ec_encrypt(string $plain): string {
    if ($plain === '') return '';
    $key = base64_decode(EC_SECRET_KEY);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return base64_encode($iv . $tag . $cipher);
}

/** Decrypt a value produced by ec_encrypt(). Returns '' on failure. */
function ec_decrypt(string $stored): string {
    if ($stored === '') return '';
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $key = base64_decode(EC_SECRET_KEY);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $out = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? '' : $out;
}

/** Merge {{fields}} into a body for one contact. */
function ec_render(string $body, array $contact, string $unsubUrl): string {
    $map = [
        '{{first_name}}' => $contact['first_name'] ?? '',
        '{{last_name}}'  => $contact['last_name'] ?? '',
        '{{company}}'    => $contact['company'] ?? '',
        '{{email}}'      => $contact['email'] ?? '',
        '{{city}}'       => $contact['city'] ?? '',
        '{{unsubscribe_url}}' => $unsubUrl,
    ];
    return strtr($body, $map);
}

/** Base URL for tracking/unsub endpoints (from settings, fallback constant). */
function ec_track_base(): string {
    $c = ec_db();
    $r = $c->query("SELECT track_base FROM ten_ec_settings WHERE id=1");
    $row = $r ? $r->fetch_assoc() : null;
    $b = $row && !empty($row['track_base']) ? $row['track_base'] : 'https://theeyenewspapers.com/management';
    return rtrim($b, '/');
}

/**
 * Rewrite <a href> links to the click tracker and append the open pixel.
 * On-site links (matching $siteHosts) also get ?ec=<token>+UTM for visit attribution.
 */
function ec_rewrite_links(string $html, string $token, string $trackBase, array $siteHosts = []): string {
    $trackBase = rtrim($trackBase, '/');
    $html = preg_replace_callback('/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i', function ($mm) use ($token, $trackBase, $siteHosts) {
        $url = $mm[2];
        // tag on-site links for visit attribution
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && $siteHosts) {
            foreach ($siteHosts as $sh) {
                if (stripos($host, $sh) !== false) {
                    $sep = (strpos($url, '?') !== false) ? '&' : '?';
                    $url .= $sep . 'ec=' . urlencode($token) . '&utm_source=ten_email&utm_medium=email';
                    break;
                }
            }
        }
        $click = $trackBase . '/t/c.php?r=' . urlencode($token) . '&u=' . urlencode($url);
        return 'href=' . $mm[1] . $click . $mm[1];
    }, $html);
    // open pixel
    $pixel = '<img src="' . $trackBase . '/t/o.php?r=' . rawurlencode($token) . '" width="1" height="1" alt="" style="display:none" />';
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    } else {
        $html .= $pixel;
    }
    return $html;
}

/** Build a WHERE clause for a contact filter (build-from-filter / refresh).
 *  Always excludes suppressed unless include_suppressed=1; optionally excludes
 *  anyone already emailed in any campaign. $p keys: q,type,country,
 *  exclude_contacted,include_suppressed. */
function ec_filter_where(mysqli $c, array $p): string {
    $conds = [];
    $q = trim($p['q'] ?? '');
    if ($q !== '') { $qe='%'.$c->real_escape_string($q).'%'; $conds[]="(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.city LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe')"; }
    if (($p['type'] ?? '') !== '') $conds[]="ct.contact_type='".$c->real_escape_string($p['type'])."'";
    if (($p['country'] ?? '') !== '') $conds[]="ct.country LIKE '%".$c->real_escape_string($p['country'])."%'";
    if (empty($p['include_suppressed'])) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
    if (!empty($p['exclude_contacted'])) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_recipients r WHERE r.contact_id=ct.id AND r.status IN('sent','bounced'))";
    return $conds ? ('WHERE '.implode(' AND ',$conds)) : '';
}

/** Is this email on the global suppression list? */
function ec_is_suppressed(string $email): bool {
    $c = ec_db();
    $st = $c->prepare("SELECT 1 FROM ten_ec_suppression WHERE email = ? LIMIT 1");
    $st->bind_param('s', $email);
    $st->execute();
    $res = $st->get_result()->fetch_row();
    $st->close();
    return (bool)$res;
}

/** Add an email to suppression (idempotent). */
function ec_suppress(string $email, string $reason, ?int $campaignId = null): void {
    $c = ec_db();
    $st = $c->prepare("INSERT INTO ten_ec_suppression (email, reason, source_campaign_id) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE reason=VALUES(reason)");
    $st->bind_param('ssi', $email, $reason, $campaignId);
    $st->execute();
    $st->close();
    $u = $c->prepare("UPDATE ten_ec_contacts SET status='suppressed' WHERE email=?");
    $u->bind_param('s', $email); $u->execute(); $u->close();
}

/** Require campaigns.manage (or admin). Call at the top of AJAX endpoints. */
function ec_require_manage(): void {
    if (!function_exists('isAdmin')) return;
    if (!isAdmin() && !hasPermission('campaigns.manage')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized — requires campaigns.manage']);
        exit();
    }
}
