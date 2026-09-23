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
    // Audiences only ever contain sendable contacts — emailless rows are excluded.
    $conds = ["ct.email IS NOT NULL AND ct.email<>''"];
    $q = trim($p['q'] ?? '');
    if ($q !== '') { $qe='%'.$c->real_escape_string($q).'%'; $conds[]="(ct.email LIKE '$qe' OR ct.company LIKE '$qe' OR ct.city LIKE '$qe' OR ct.first_name LIKE '$qe' OR ct.last_name LIKE '$qe')"; }
    // Contacts are categorised by a single dimension: Category (+ Country). (Type/Industry retired.)
    if (($p['category'] ?? '') !== '') $conds[]="ct.category='".$c->real_escape_string($p['category'])."'";
    if (($p['country'] ?? '') !== '') $conds[]="ct.country LIKE '%".$c->real_escape_string($p['country'])."%'";
    if (empty($p['include_suppressed'])) $conds[]="NOT EXISTS (SELECT 1 FROM ten_ec_suppression s WHERE s.email=ct.email)";
    // Never-email-twice: exclude anyone previously emailed. Checks the live
    // recipients AND the permanent send log, so it still holds after a campaign
    // (and its recipients) has been deleted.
    if (!empty($p['exclude_contacted'])) $conds[]=ec_contacted_not_exists();
    return $conds ? ('WHERE '.implode(' AND ',$conds)) : '';
}

/** SQL fragment: true when contact `ct` has NOT been emailed before (live queue or
 *  permanent send log). Used by audience filters and the contacts "hide already-emailed". */
function ec_contacted_not_exists(): string {
    return "NOT EXISTS (SELECT 1 FROM ten_ec_recipients r WHERE r.contact_id=ct.id AND r.status IN('sent','bounced'))
            AND NOT EXISTS (SELECT 1 FROM ten_ec_sent_log sl WHERE sl.contact_id=ct.id)";
}

/**
 * Append a permanent record of one sent email. Snapshots the campaign name,
 * subject and rendered body so the history survives campaign deletion and later
 * template edits. Best-effort: never throws (a logging failure must not abort a send).
 */
function ec_record_sent(mysqli $c, array $d): void {
    try {
        $st = $c->prepare("INSERT INTO ten_ec_sent_log
            (contact_id,email,campaign_id,campaign_name,subject,body,is_plain,from_email)
            VALUES (?,?,?,?,?,?,?,?)");
        if (!$st) return;
        $cid   = (int)($d['contact_id'] ?? 0);
        $email = (string)($d['email'] ?? '');
        $camp  = (int)($d['campaign_id'] ?? 0);
        $cname = (string)($d['campaign_name'] ?? '');
        $subj  = (string)($d['subject'] ?? '');
        $body  = (string)($d['body'] ?? '');
        $plain = !empty($d['is_plain']) ? 1 : 0;
        $from  = (string)($d['from_email'] ?? '');
        // types match column order: contact_id,email,campaign_id,campaign_name,subject,body,is_plain,from_email
        $st->bind_param('isisssis', $cid, $email, $camp, $cname, $subj, $body, $plain, $from);
        $st->execute();
        $st->close();
    } catch (Throwable $e) { /* logging must never abort a send */ }
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
 * Normalise a scraped email. Strips scraper artifacts (leading/trailing quote
 * escapes like a literal "x22"/"x27", stray quotes/spaces), lowercases, and
 * returns '' when the result is not a valid address. A '' return means the row
 * is treated as EMAILLESS on import (so it can be found and filled in later).
 */
function ec_clean_email(string $raw): string {
    $e = trim($raw);
    if ($e === '') return '';
    $e = preg_replace('/^(?:x22|x27|["\'\s])+/i', '', $e);
    $e = preg_replace('/(?:x22|x27|["\'\s])+$/i', '', $e);
    $e = strtolower(trim($e));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/** Normalise a scraped phone: drop a leading "Phone:" label, collapse whitespace. */
function ec_clean_phone(string $raw): string {
    $p = preg_replace('/^\s*phone\s*:\s*/i', '', trim($raw));
    return trim(preg_replace('/\s+/', ' ', (string)$p));
}

/**
 * Split a scraped "Address: <street>, <postcode> <city>, <country>" string into
 * ['address','city','postcode']. Drops a leading "Address:" label and a trailing
 * country segment; pulls a "<4-5 digit postcode> <city>" segment out where present.
 * Anything it cannot confidently parse stays in `address`.
 */
function ec_parse_broker_address(string $raw, string $country = ''): array {
    $a = preg_replace('/^\s*address\s*:\s*/i', '', trim($raw));
    $a = trim((string)$a);
    if ($a === '') return ['address'=>'','city'=>'','postcode'=>''];
    $parts = array_map('trim', explode(',', $a));
    $parts = array_values(array_filter($parts, function($x){ return $x !== ''; }));
    if ($parts && $country !== '' && strcasecmp(end($parts), $country) === 0) array_pop($parts);
    $city = ''; $postcode = '';
    foreach ($parts as $i => $seg) {
        if (preg_match('/^(\d{4,5})\s+(.+)$/', $seg, $m)) {
            $postcode = $m[1]; $city = trim($m[2]); unset($parts[$i]); break;
        }
    }
    return ['address'=>implode(', ', $parts), 'city'=>$city, 'postcode'=>$postcode];
}

/**
 * Upsert a contact from a JSON scrape import. Unlike ec_upsert_contact (keyed
 * strictly on email), this also stores EMAILLESS rows: when no valid email is
 * present the row is matched to an existing emailless contact by normalised
 * company + phone (project decision) so re-imports don't duplicate, and the
 * Google place_id is kept in source_ref for reference. On an existing row a
 * non-empty new value overwrites; blanks preserve what's there.
 * $d holds cleaned column=>value. Returns [id, inserted, has_email].
 */
function ec_upsert_contact_json(mysqli $c, array $d): array {
    $email = ec_clean_email((string)($d['email'] ?? ''));
    $hasEmail = $email !== '';
    // Columns this importer sets (source_ref included; email handled separately).
    $cols = ['company','phone','website','address','city','postcode','region','country',
             'contact_type','industry','category','source','consent_basis','source_ref'];
    $vals = [];
    foreach ($cols as $k) $vals[$k] = trim((string)($d[$k] ?? ''));

    if ($hasEmail) {
        // Dedup on the unique email via INSERT ... ON DUPLICATE KEY UPDATE.
        $fieldList = 'email,'.implode(',', $cols);
        $ph = implode(',', array_fill(0, count($cols)+1, '?'));
        $upd = [];
        foreach ($cols as $k) $upd[] = "$k=IF(VALUES($k)<>'',VALUES($k),$k)";
        $upd[] = "updated_at=NOW()";
        $sql = "INSERT INTO ten_ec_contacts ($fieldList) VALUES ($ph) ON DUPLICATE KEY UPDATE ".implode(',', $upd);
        $args = array_merge([$email], array_values($vals));
        $st = $c->prepare($sql);
        $st->bind_param(str_repeat('s', count($args)), ...$args);
        $st->execute();
        $inserted = ($c->affected_rows === 1);
        $id = (int)$c->insert_id;
        $st->close();
        if (!$id) {
            $r = $c->query("SELECT id FROM ten_ec_contacts WHERE email='".$c->real_escape_string($email)."'");
            $id = $r ? (int)($r->fetch_assoc()['id'] ?? 0) : 0;
        }
        return ['id'=>$id, 'inserted'=>$inserted, 'has_email'=>true];
    }

    // Emailless: match an existing emailless contact by company (+ phone).
    $id = 0;
    if ($vals['company'] !== '') {
        $st = $c->prepare("SELECT id FROM ten_ec_contacts
                           WHERE (email IS NULL OR email='') AND company=? AND phone=? LIMIT 1");
        $st->bind_param('ss', $vals['company'], $vals['phone']);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        if ($row) $id = (int)$row[0];
    }
    if ($id > 0) {
        // Update only the non-empty new values (blanks preserve existing data).
        $set = []; $args = [];
        foreach ($vals as $k => $v) { if ($v === '') continue; $set[] = "$k=?"; $args[] = $v; }
        if ($set) {
            $set[] = "updated_at=NOW()";
            $args[] = $id;
            $st = $c->prepare("UPDATE ten_ec_contacts SET ".implode(',', $set)." WHERE id=?");
            $st->bind_param(str_repeat('s', count($args)-1).'i', ...$args);
            $st->execute();
            $st->close();
        }
        return ['id'=>$id, 'inserted'=>false, 'has_email'=>false];
    }
    // Insert a new emailless contact (email stored as NULL so many can coexist).
    $fieldList = 'email,'.implode(',', $cols);
    $ph = implode(',', array_fill(0, count($cols)+1, '?'));
    $st = $c->prepare("INSERT INTO ten_ec_contacts ($fieldList) VALUES ($ph)");
    $nullEmail = null;
    $args = array_merge([$nullEmail], array_values($vals));
    $st->bind_param(str_repeat('s', count($args)), ...$args);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();
    return ['id'=>$id, 'inserted'=>true, 'has_email'=>false];
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
        'source_ref'=> "VARCHAR(255) NULL", // scraper provenance (e.g. Google place_id)
    ];
    foreach ($newCols as $name => $def) {
        $chk = $c->query("SHOW COLUMNS FROM ten_ec_contacts LIKE '$name'");
        if (!$chk || $chk->num_rows === 0) @$c->query("ALTER TABLE ten_ec_contacts ADD COLUMN $name $def");
    }
    // Allow EMAILLESS contacts: make email nullable so scraped rows without an
    // address can be stored and filled in later. A UNIQUE index still permits many
    // NULLs, so email-keyed dedup is unaffected. Existing '' emails become NULL.
    $em = $c->query("SHOW COLUMNS FROM ten_ec_contacts LIKE 'email'");
    if ($em && ($erow = $em->fetch_assoc()) && strtoupper($erow['Null']) === 'NO') {
        $type = $erow['Type'] ?: 'varchar(255)';
        @$c->query("ALTER TABLE ten_ec_contacts MODIFY email $type NULL");
        @$c->query("UPDATE ten_ec_contacts SET email=NULL WHERE email=''");
    }
    $iSr = @$c->query("SHOW INDEX FROM ten_ec_contacts WHERE Key_name='idx_ec_source_ref'");
    if ($iSr && $iSr->num_rows === 0) @$c->query("ALTER TABLE ten_ec_contacts ADD INDEX idx_ec_source_ref (source_ref)");
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

    // Managed pick-lists for Contact type, Industry and Country (like categories):
    // one table keyed by `kind`. Seeded once from the values already in use so no
    // existing value ever disappears from a dropdown; new values are absorbed on
    // import/add. Editing/renaming/deleting is done in the UI (ec_vocab.php).
    $vocabIsNew = false;
    $vChk = $c->query("SHOW TABLES LIKE 'ten_ec_vocab'");
    if (!$vChk || $vChk->num_rows === 0) $vocabIsNew = true;
    @$c->query("CREATE TABLE IF NOT EXISTS ten_ec_vocab (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL,
        name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vocab (kind, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($vocabIsNew) {
        // First-time seed from distinct values currently on contacts (+ existing
        // managed categories) so nothing already in use disappears from a dropdown.
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'type', contact_type FROM ten_ec_contacts WHERE contact_type IS NOT NULL AND contact_type<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'industry', industry FROM ten_ec_contacts WHERE industry IS NOT NULL AND industry<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'country', country FROM ten_ec_contacts WHERE country IS NOT NULL AND country<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'category', category FROM ten_ec_contacts WHERE category IS NOT NULL AND category<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'category', name FROM ten_ec_categories");
    }
    // One-time: seed the Country list with every country in the world (marker-gated so
    // it runs exactly once and is not re-added if the user later prunes the list).
    $ckMark = $c->query("SELECT 1 FROM ten_ec_vocab WHERE kind='_seed' AND name='countries' LIMIT 1");
    if (!$ckMark || $ckMark->num_rows === 0) {
        $world = ['Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo (Brazzaville)','Congo (Kinshasa)','Costa Rica','Croatia','Cuba','Cyprus','Czechia','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Ivory Coast','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kiribati','Kosovo','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Korea','North Macedonia','Norway','Oman','Pakistan','Palau','Palestine','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Vatican City','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
        $st = $c->prepare("INSERT IGNORE INTO ten_ec_vocab (kind,name) VALUES ('country',?)");
        if ($st) { foreach ($world as $cy) { $st->bind_param('s', $cy); $st->execute(); } $st->close(); }
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) VALUES ('_seed','countries')");
    }
    // One-time: Type/Industry retired in favour of a single Category. Fold each
    // contact's old contact_type into an empty category so nothing becomes
    // untargetable, then (re)seed the Category pick-list. Marker-gated → runs once.
    $catMig = $c->query("SELECT 1 FROM ten_ec_vocab WHERE kind='_seed' AND name='cat_from_type' LIMIT 1");
    if (!$catMig || $catMig->num_rows === 0) {
        @$c->query("UPDATE ten_ec_contacts SET category=contact_type WHERE (category IS NULL OR category='') AND contact_type IS NOT NULL AND contact_type<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) SELECT 'category', category FROM ten_ec_contacts WHERE category IS NOT NULL AND category<>''");
        @$c->query("INSERT IGNORE INTO ten_ec_vocab (kind,name) VALUES ('_seed','cat_from_type')");
    }

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

    // Permanent per-send history. Written on every successful send and NEVER
    // deleted when a campaign is deleted, so we always keep: who was emailed, what
    // was sent (subject + rendered body snapshot), which campaign (name kept even
    // after the campaign row is gone), and when. This is the source of truth for
    // "already contacted" (never-email-twice) and the per-contact history view.
    @$c->query("CREATE TABLE IF NOT EXISTS ten_ec_sent_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contact_id INT NULL,
        email VARCHAR(255) NULL,
        campaign_id INT NULL,
        campaign_name VARCHAR(255) NULL,
        subject VARCHAR(500) NULL,
        body MEDIUMTEXT NULL,
        is_plain TINYINT(1) NOT NULL DEFAULT 0,
        from_email VARCHAR(255) NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        unsubscribed_at TIMESTAMP NULL,
        INDEX idx_sl_contact (contact_id),
        INDEX idx_sl_email (email),
        INDEX idx_sl_campaign (campaign_id)
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

/** Add a value to a managed pick-list if not already present (kind: type|industry|country|category). */
function ec_vocab_absorb(mysqli $c, string $kind, string $name): void {
    $name = trim($name);
    if ($name === '' || !in_array($kind, ['type','industry','country','category'], true)) return;
    $st = $c->prepare("INSERT IGNORE INTO ten_ec_vocab (kind,name) VALUES (?,?)");
    if (!$st) return;
    $st->bind_param('ss', $kind, $name);
    $st->execute(); $st->close();
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
