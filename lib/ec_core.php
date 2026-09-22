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

/**
 * Merge fields available to templates/signatures, ordered most-likely first. Each entry:
 * token (the {{placeholder}}), label (human name for the picker), col (ten_ec_contacts
 * column it resolves from). ec_render() and the sender build their maps from this, and the
 * editor's "insert field" dropdown is populated from it — one source of truth.
 */
function ec_merge_fields(): array {
    return [
        ['token'=>'{{first_name}}','label'=>'First name','col'=>'first_name'],
        ['token'=>'{{last_name}}', 'label'=>'Last name', 'col'=>'last_name'],
        ['token'=>'{{company}}',   'label'=>'Company',   'col'=>'company'],
        ['token'=>'{{city}}',      'label'=>'City',      'col'=>'city'],
        ['token'=>'{{country}}',   'label'=>'Country',   'col'=>'country'],
        ['token'=>'{{email}}',     'label'=>'Email',     'col'=>'email'],
        ['token'=>'{{job_title}}', 'label'=>'Job title', 'col'=>'job_title'],
        ['token'=>'{{phone}}',     'label'=>'Phone',     'col'=>'phone'],
        ['token'=>'{{website}}',   'label'=>'Website',   'col'=>'website'],
        ['token'=>'{{industry}}',  'label'=>'Industry',  'col'=>'industry'],
        ['token'=>'{{address}}',   'label'=>'Address',   'col'=>'address'],
        ['token'=>'{{postcode}}',  'label'=>'Postcode',  'col'=>'postcode'],
        ['token'=>'{{region}}',    'label'=>'Region',    'col'=>'region'],
        ['token'=>'{{category}}',  'label'=>'Category',  'col'=>'category'],
    ];
}

/**
 * Merge {{fields}} into any string (subject OR body) for one contact.
 * Works identically on the subject line and the HTML/text body.
 * After substituting the known fields, any leftover {{...}} placeholder (an
 * unknown or misspelt field, e.g. {{company name}}) is stripped to empty so raw
 * braces never reach a recipient.
 */
function ec_render(string $body, array $contact, string $unsubUrl): string {
    $map = ['{{unsubscribe_url}}' => $unsubUrl];
    foreach (ec_merge_fields() as $f) {
        $map[$f['token']] = (string)($contact[$f['col']] ?? '');
    }
    $out = strtr($body, $map);
    // Remove any remaining unresolved placeholders like {{ something }}.
    $out = preg_replace('/\{\{\s*[\w .-]+\s*\}\}/', '', $out);
    return $out;
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
 * Rewrite <a href> links to the click tracker (click tracking).
 * On-site links (matching $siteHosts) also get ?ec=<token>+UTM for visit attribution.
 * NOTE: this does the LINK rewriting only. The open pixel is added separately by
 * ec_add_open_pixel() so opens and clicks can be toggled independently per campaign.
 */
function ec_rewrite_links(string $html, string $token, string $trackBase, array $siteHosts = []): string {
    $trackBase = rtrim($trackBase, '/');
    return preg_replace_callback('/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i', function ($mm) use ($token, $trackBase, $siteHosts) {
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
}

/**
 * Append the 1x1 invisible open-tracking pixel (open tracking). Inserted before
 * </body> when present, else at the end. Gated per campaign by track_opens.
 */
function ec_add_open_pixel(string $html, string $token, string $trackBase): string {
    $trackBase = rtrim($trackBase, '/');
    $pixel = '<img src="' . $trackBase . '/t/o.php?r=' . rawurlencode($token) . '" width="1" height="1" alt="" style="display:none" />';
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    }
    return $html . $pixel;
}

/** Build a WHERE clause for a contact filter (build-from-filter / refresh).
 *  Always excludes suppressed unless include_suppressed=1; optionally excludes
 *  anyone already emailed in any campaign. $p keys: q,type,country,industry,
 *  category,exclude_contacted,include_suppressed. */
function ec_filter_where(mysqli $c, array $p): string {
    $conds = [];
    $q = trim($p['q'] ?? '');
    if ($q !== '') { $qe='%'.$c->real_escape_string($q).'%'; $conds[]="(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.city LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe')"; }
    if (($p['type'] ?? '') !== '') $conds[]="ct.contact_type='".$c->real_escape_string($p['type'])."'";
    if (($p['country'] ?? '') !== '') $conds[]="ct.country LIKE '%".$c->real_escape_string($p['country'])."%'";
    if (($p['industry'] ?? '') !== '') $conds[]="ct.industry='".$c->real_escape_string($p['industry'])."'";
    if (($p['category'] ?? '') !== '') $conds[]="ct.category='".$c->real_escape_string($p['category'])."'";
    if (empty($p['include_suppressed'])) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
    if (!empty($p['exclude_contacted'])) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_recipients r WHERE r.contact_id=ct.id AND r.status IN('sent','bounced'))";
    return $conds ? ('WHERE '.implode(' AND ',$conds)) : '';
}

/** Extended contact columns editable/importable beyond the original core set. */
function ec_contact_columns(): array {
    return ['first_name','last_name','company','contact_type','phone','city','country',
            'job_title','website','address','postcode','region','industry','category','source','consent_basis'];
}

/**
 * Insert or update a contact keyed by email. $d holds column=>value.
 * On an existing email, a non-empty new value overwrites; blanks preserve what's
 * there (so a light re-import never wipes richer data). Returns [id, inserted].
 */
function ec_upsert_contact(mysqli $c, array $d): array {
    $email = strtolower(trim($d['email'] ?? ''));
    $cols  = ec_contact_columns();
    $vals  = [$email];
    foreach ($cols as $k) $vals[] = trim((string)($d[$k] ?? ''));
    $fieldList = 'email,'.implode(',', $cols);
    $ph  = implode(',', array_fill(0, count($cols)+1, '?'));
    $upd = [];
    foreach ($cols as $k) $upd[] = "$k=IF(VALUES($k)<>'',VALUES($k),$k)";
    $upd[] = "updated_at=NOW()";
    $sql = "INSERT INTO ten_ec_contacts ($fieldList) VALUES ($ph) ON DUPLICATE KEY UPDATE ".implode(',', $upd);
    $st  = $c->prepare($sql);
    $st->bind_param(str_repeat('s', count($vals)), ...$vals);
    $st->execute();
    $inserted = ($c->affected_rows === 1);
    $id = (int)$c->insert_id;
    $st->close();
    if (!$id) {
        $r = $c->query("SELECT id FROM ten_ec_contacts WHERE email='".$c->real_escape_string($email)."'");
        $id = $r ? (int)($r->fetch_assoc()['id'] ?? 0) : 0;
    }
    return ['id'=>$id, 'inserted'=>$inserted];
}

/**
 * Idempotent, cheap schema top-up for the newer contact fields, the managed
 * categories vocabulary, and the campaign archived flag. Safe to call on every
 * page/AJAX hit — it only ALTERs when something is actually missing.
 */
function ec_ensure_schema(mysqli $c): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $newCols = [
        'job_title' => "VARCHAR(150) NULL",
        'website'   => "VARCHAR(255) NULL",
        'address'   => "VARCHAR(255) NULL",
        'postcode'  => "VARCHAR(40) NULL",
        'region'    => "VARCHAR(120) NULL",
        'industry'  => "VARCHAR(150) NULL",
        'category'  => "VARCHAR(150) NULL",
    ];
    foreach ($newCols as $name => $def) {
        $chk = $c->query("SHOW COLUMNS FROM ten_ec_contacts LIKE '$name'");
        if (!$chk || $chk->num_rows === 0) @$c->query("ALTER TABLE ten_ec_contacts ADD COLUMN $name $def");
    }
    // helpful indexes for the new audience filters (ignore errors if they exist)
    $iInd = @$c->query("SHOW INDEX FROM ten_ec_contacts WHERE Key_name='idx_ec_industry'");
    if ($iInd && $iInd->num_rows === 0) @$c->query("ALTER TABLE ten_ec_contacts ADD INDEX idx_ec_industry (industry)");
    $iCat = @$c->query("SHOW INDEX FROM ten_ec_contacts WHERE Key_name='idx_ec_category'");
    if ($iCat && $iCat->num_rows === 0) @$c->query("ALTER TABLE ten_ec_contacts ADD INDEX idx_ec_category (category)");

    @$c->query("CREATE TABLE IF NOT EXISTS ten_ec_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ca = $c->query("SHOW COLUMNS FROM ten_ec_campaigns LIKE 'archived'");
    if (!$ca || $ca->num_rows === 0) @$c->query("ALTER TABLE ten_ec_campaigns ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");

    // Per-campaign tracking + format controls. All default OFF: no open pixel, no link
    // rewriting, HTML body. Toggled in the campaign form; enforced in cron/ec_send.php.
    foreach (['track_opens','track_clicks','plain_text'] as $col) {
        $chk = $c->query("SHOW COLUMNS FROM ten_ec_campaigns LIKE '$col'");
        if (!$chk || $chk->num_rows === 0) @$c->query("ALTER TABLE ten_ec_campaigns ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 0");
    }

    $sa = $c->query("SHOW COLUMNS FROM ten_ec_recipients LIKE 'send_attempts'");
    if (!$sa || $sa->num_rows === 0) @$c->query("ALTER TABLE ten_ec_recipients ADD COLUMN send_attempts INT NOT NULL DEFAULT 0");

    // Template format flag: 0 = HTML (rich editor), 1 = plain text only. This is where
    // the HTML-vs-plain decision now lives (campaigns inherit it); the sender reads it
    // per template and sets the MIME headers accordingly.
    $ip = $c->query("SHOW COLUMNS FROM ten_ec_templates LIKE 'is_plain'");
    if (!$ip || $ip->num_rows === 0) @$c->query("ALTER TABLE ten_ec_templates ADD COLUMN is_plain TINYINT(1) NOT NULL DEFAULT 0");

    // Reusable e-mail signatures (HTML or plain, same as templates). A plain-text template
    // inserts the signature's plain-text version; an HTML template inserts its HTML.
    @$c->query("CREATE TABLE IF NOT EXISTS ten_ec_signatures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        is_plain TINYINT(1) NOT NULL DEFAULT 0,
        html_body MEDIUMTEXT NULL,
        text_body MEDIUMTEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Captured replies + bounces (content), populated by the IMAP poller.
    @$c->query("CREATE TABLE IF NOT EXISTS ten_ec_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_id INT NULL,
        campaign_id INT NULL,
        contact_email VARCHAR(255) NULL,
        type VARCHAR(10) NOT NULL,
        subject VARCHAR(500) NULL,
        body MEDIUMTEXT NULL,
        bounce_type VARCHAR(10) NULL,
        message_uid VARCHAR(255) NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_resp_type (type),
        INDEX idx_resp_campaign (campaign_id),
        UNIQUE KEY uq_resp_msg (message_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
