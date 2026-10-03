<?php
/**
 * CRM — shared helpers.
 *
 * The CRM is a rich front-end over the SHARED contact universe in ten_ec_contacts
 * (the Email Campaign Manager's contacts). It adds leads (a contact placed on a
 * pipeline at a stage), pipelines/stages, activities, and a per-contact email
 * timeline joined live from the ec_* tables. The PKV broker tool is surfaced as a
 * live, read-only pipeline (its own ten_pkv_* tables stay the system of record).
 *
 * All CRM tables use the ten_crm_ prefix. Schema self-migrates on page / ajax load
 * (same pattern as ec_ensure_schema), so there is no separate setup script to run.
 */
require_once __DIR__ . '/../config.php';

/** DB connection (everything lives in TEN_Management). */
function crm_db(): mysqli {
    $c = getDBConnection();
    @$c->set_charset('utf8mb4');
    return $c;
}

/** Create tables + seed a default pipeline once. Idempotent, cheap to re-run. */
function crm_ensure_schema(mysqli $c): void {
    static $done = false;
    if ($done) return;
    $done = true;

    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_pipelines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(160) NULL,
        description TEXT NULL,
        accent VARCHAR(20) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pipeline_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_won TINYINT(1) NOT NULL DEFAULT 0,
        is_lost TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crm_stage_pipeline (pipeline_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pipeline_id INT NOT NULL,
        contact_id INT NOT NULL,
        stage_id INT NULL,
        owner_id INT NULL,
        value DECIMAL(12,2) NULL,
        status ENUM('open','won','lost','on_hold') NOT NULL DEFAULT 'open',
        note TEXT NULL,
        last_activity_at TIMESTAMP NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_crm_lead (pipeline_id, contact_id),
        INDEX idx_crm_lead_contact (contact_id),
        INDEX idx_crm_lead_stage (stage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_lead_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        from_stage_id INT NULL,
        to_stage_id INT NULL,
        user_id INT NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crm_hist_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contact_id INT NOT NULL,
        lead_id INT NULL,
        user_id INT NULL,
        type ENUM('note','call','email','meeting','task') NOT NULL DEFAULT 'note',
        subject VARCHAR(300) NULL,
        body TEXT NULL,
        due_at DATETIME NULL,
        done TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crm_act_contact (contact_id),
        INDEX idx_crm_act_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Projects can nest (sub-projects) via parent_id; a project can have a type
    // (category) and, for per-publication work, a publication.
    $pc = $c->query("SHOW COLUMNS FROM ten_crm_pipelines LIKE 'parent_id'");
    if (!$pc || $pc->num_rows === 0) @$c->query("ALTER TABLE ten_crm_pipelines ADD COLUMN parent_id INT NULL");
    $cc = $c->query("SHOW COLUMNS FROM ten_crm_pipelines LIKE 'category'");
    if (!$cc || $cc->num_rows === 0) @$c->query("ALTER TABLE ten_crm_pipelines ADD COLUMN category VARCHAR(60) NULL");
    $pbc = $c->query("SHOW COLUMNS FROM ten_crm_pipelines LIKE 'publication'");
    if (!$pbc || $pbc->num_rows === 0) @$c->query("ALTER TABLE ten_crm_pipelines ADD COLUMN publication VARCHAR(120) NULL");

    // Per-user show/hide preference for any project (by its key, e.g. 'pkv', 'wne:1', 'native:3').
    @$c->query("CREATE TABLE IF NOT EXISTS ten_crm_project_prefs (
        user_id INT NOT NULL,
        project_key VARCHAR(64) NOT NULL,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_pref (user_id, project_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    crm_seed_projects($c);
    crm_migrate_projects_v2($c);
}

/** v2: drop the placeholder "Sales pipeline" and the per-publication promotion
 *  boxes (promotions are now created on demand with a publication dropdown).
 *  Only deletes projects that have no contacts. Marker-gated. */
function crm_migrate_projects_v2(mysqli $c): void {
    $mk = $c->query("SELECT 1 FROM ten_crm_project_prefs WHERE user_id=0 AND project_key='_proj_v2' LIMIT 1");
    if ($mk && $mk->num_rows) return;
    $ids = [];
    $r = $c->query("SELECT p.id FROM ten_crm_pipelines p
                    WHERE (p.name='Sales pipeline' OR p.name='Café & Venue Promotions' OR p.name LIKE 'Promotions – %')
                      AND NOT EXISTS (SELECT 1 FROM ten_crm_leads l WHERE l.pipeline_id=p.id)");
    if ($r) while ($row = $r->fetch_row()) $ids[] = (int)$row[0];
    foreach ($ids as $id) { @$c->query("DELETE FROM ten_crm_stages WHERE pipeline_id=$id"); @$c->query("DELETE FROM ten_crm_pipelines WHERE id=$id"); }
    // tidy any now-orphaned sub-project links, tag the outreach set
    @$c->query("UPDATE ten_crm_pipelines SET parent_id=NULL WHERE parent_id IS NOT NULL AND parent_id NOT IN (SELECT id FROM (SELECT id FROM ten_crm_pipelines) t)");
    @$c->query("UPDATE ten_crm_pipelines SET category='Outreach' WHERE (category IS NULL OR category='') AND name LIKE '%Outreach%'");
    @$c->query("INSERT IGNORE INTO ten_crm_project_prefs (user_id,project_key,hidden) VALUES (0,'_proj_v2',0)");
}

/** Seed the standard outreach projects once (marker-gated). */
function crm_seed_projects(mysqli $c): void {
    $mk = $c->query("SELECT 1 FROM ten_crm_project_prefs WHERE user_id=0 AND project_key='_seed_outreach_v1' LIMIT 1");
    if ($mk && $mk->num_rows) return;

    $stages = [['Prospect',0,0],['Contacted',0,0],['In discussion',0,0],['Proposal sent',0,0],['Agreed',1,0],['Declined',0,1]];
    $make = function(string $name, ?int $parent = null) use ($c, $stages): int {
        $ex = $c->query("SELECT id FROM ten_crm_pipelines WHERE name='".$c->real_escape_string($name)."' LIMIT 1");
        if ($ex && $ex->num_rows) return (int)$ex->fetch_assoc()['id'];
        $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/i', '-', strtolower($name))), '-'); $desc = ''; $acc = '#1f4e79';
        $ord = (int)$c->query("SELECT COALESCE(MAX(display_order),0)+1 n FROM ten_crm_pipelines")->fetch_assoc()['n'];
        $st = $c->prepare("INSERT INTO ten_crm_pipelines (name,slug,description,accent,display_order,parent_id,created_by) VALUES (?,?,?,?,?,?,0)");
        $st->bind_param('ssssii', $name, $slug, $desc, $acc, $ord, $parent); $st->execute();
        $id = (int)$c->insert_id; $st->close();
        $ss = $c->prepare("INSERT INTO ten_crm_stages (pipeline_id,name,display_order,is_won,is_lost) VALUES (?,?,?,?,?)");
        foreach ($stages as $i => $s) { $ss->bind_param('isiii', $id, $s[0], $i, $s[1], $s[2]); $ss->execute(); }
        $ss->close();
        return $id;
    };

    foreach (['Venues Outreach','Events Outreach','Broker Outreach','Advertiser Outreach – TEN Sites','Restaurant Outreach'] as $n) $make($n);
    $promo = $make('Café & Venue Promotions');
    // One promotions sub-project per live publication.
    try {
        $a = crm_admin_db();
        if ($a) {
            $r = $a->query("SELECT title, publication FROM publications WHERE pub_live='1' ORDER BY title");
            if ($r) while ($row = $r->fetch_assoc()) {
                $t = trim(($row['title'] ?: '') ?: ($row['publication'] ?: ''));
                if ($t !== '') $make('Promotions – '.$t, $promo);
            }
            @$a->close();
        }
    } catch (Throwable $e) { /* admin_ten unavailable */ }

    @$c->query("INSERT IGNORE INTO ten_crm_project_prefs (user_id,project_key,hidden) VALUES (0,'_seed_outreach_v1',0)");
}

/** Keys the given user has hidden. */
function crm_hidden_keys(mysqli $c, int $uid): array {
    $out = [];
    $r = $c->query("SELECT project_key FROM ten_crm_project_prefs WHERE user_id=$uid AND hidden=1");
    if ($r) while ($row = $r->fetch_row()) $out[$row[0]] = 1;
    return $out;
}

/** True when the current user may see the PKV pipeline. */
function crm_pkv_visible(): bool {
    if (!function_exists('hasPermission')) return false;
    return isAdmin() || hasPermission('pkv_view');
}

/** True when the current user is an external PKV broker (restricted to own enquiries). */
function crm_pkv_external_broker(): bool {
    return function_exists('hasPermission') && hasPermission('pkv_external_broker') && !isAdmin();
}

/**
 * Ordered PKV states = the stages of the virtual PKV pipeline. Mirrors module-pkv.php.
 * Each: [key, label, is_won, is_lost].
 */
function crm_pkv_states(): array {
    return [
        ['new',                        'New',               0, 0],
        ['contacted',                  'Contacted',         0, 0],
        ['awaiting_client_response',   'Awaiting Client',   0, 0],
        ['awaiting_insurer_response',  'Awaiting Insurer',  0, 0],
        ['documents_pending',          'Documents Pending', 0, 0],
        ['quote_provided',             'Quote Provided',    0, 0],
        ['negotiating',                'Negotiating',       0, 0],
        ['contract_preparation',       'Contract Prep',     0, 0],
        ['contract_sent',             'Contract Sent',     0, 0],
        ['contract_signed',            'Signed',            0, 0],
        ['on_hold',                    'On Hold',           0, 0],
        ['closed_success',             'Success',           1, 0],
        ['closed_failed',              'Failed',            0, 1],
    ];
}

/**
 * Live board for the virtual PKV pipeline: stages (from crm_pkv_states) + enquiry
 * cards read straight from ten_pkv_enquiries. Each card is matched to a shared
 * ten_ec_contacts row by email where one exists, so the email timeline can be shown.
 * External brokers only see their own assigned enquiries.
 */
function crm_pkv_board(mysqli $c): array {
    $states = crm_pkv_states();
    $stages = [];
    foreach ($states as $i => $s) {
        $stages[] = ['key' => $s[0], 'name' => $s[1], 'order' => $i, 'is_won' => $s[2], 'is_lost' => $s[3]];
    }

    $where = '1=1';
    if (crm_pkv_external_broker()) {
        $uid = (int)($_SESSION['ten_user_id'] ?? 0);
        $br = $c->query("SELECT id FROM ten_pkv_brokers WHERE user_id=$uid LIMIT 1");
        $brow = $br ? $br->fetch_assoc() : null;
        if (!$brow) return ['stages' => $stages, 'leads' => []];
        $where = 'e.assigned_broker_id=' . (int)$brow['id'];
    }

    $leads = [];
    $sql = "SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.state,
                   e.source, e.created_at, e.updated_at, e.assigned_broker_id,
                   b.company_name AS broker_name, ct.id AS contact_id, ct.category AS contact_category
            FROM ten_pkv_enquiries e
            LEFT JOIN ten_pkv_brokers b ON b.id = e.assigned_broker_id
            LEFT JOIN ten_ec_contacts ct ON ct.email = e.email COLLATE utf8mb4_unicode_ci
            WHERE $where
            ORDER BY e.updated_at DESC, e.id DESC";
    $r = $c->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) {
        $leads[] = [
            'enquiry_id'  => (int)$row['id'],
            'stage_key'   => $row['state'],
            'first_name'  => $row['first_name'],
            'last_name'   => $row['last_name'],
            'email'       => $row['email'],
            'phone'       => $row['phone'],
            'broker_name' => $row['broker_name'],
            'source'      => $row['source'],
            'contact_id'  => $row['contact_id'] ? (int)$row['contact_id'] : null,
            'updated_at'  => $row['updated_at'],
            'created_at'  => $row['created_at'],
        ];
    }
    return ['stages' => $stages, 'leads' => $leads];
}

/**
 * Per-contact email timeline, merged + sorted newest-first from the ec_* tables.
 * Each event: ['kind','at','title','detail','campaign']. kind ∈
 * sent|opened|clicked|replied|bounced|unsubscribed|suppressed.
 */
function crm_email_timeline(mysqli $c, int $contactId, string $email): array {
    $events = [];
    $em = trim($email);
    $emEsc = $c->real_escape_string($em);

    // Permanent send log (survives campaign deletion).
    $r = $c->query("SELECT campaign_name, subject, sent_at, unsubscribed_at
                    FROM ten_ec_sent_log
                    WHERE contact_id=$contactId OR (email IS NOT NULL AND email='$emEsc')
                    ORDER BY sent_at DESC LIMIT 200");
    if ($r) while ($row = $r->fetch_assoc()) {
        $events[] = ['kind'=>'sent','at'=>$row['sent_at'],'title'=>$row['subject'] ?: '(no subject)',
                     'detail'=>'','campaign'=>$row['campaign_name']];
        if (!empty($row['unsubscribed_at'])) {
            $events[] = ['kind'=>'unsubscribed','at'=>$row['unsubscribed_at'],'title'=>'Unsubscribed',
                         'detail'=>'','campaign'=>$row['campaign_name']];
        }
    }

    // Live recipient engagement (opens / clicks / replies / bounces).
    $r = $c->query("SELECT r.opened_at, r.first_click_at, r.replied_at, r.bounce_type, r.status,
                           ca.name AS campaign_name
                    FROM ten_ec_recipients r
                    LEFT JOIN ten_ec_campaigns ca ON ca.id = r.campaign_id
                    WHERE r.contact_id=$contactId
                    ORDER BY r.sent_at DESC LIMIT 200");
    if ($r) while ($row = $r->fetch_assoc()) {
        $cn = $row['campaign_name'];
        if (!empty($row['opened_at']))     $events[] = ['kind'=>'opened','at'=>$row['opened_at'],'title'=>'Opened','detail'=>'','campaign'=>$cn];
        if (!empty($row['first_click_at'])) $events[] = ['kind'=>'clicked','at'=>$row['first_click_at'],'title'=>'Clicked a link','detail'=>'','campaign'=>$cn];
        if (!empty($row['replied_at']))    $events[] = ['kind'=>'replied','at'=>$row['replied_at'],'title'=>'Replied','detail'=>'','campaign'=>$cn];
        if (!empty($row['bounce_type']))   $events[] = ['kind'=>'bounced','at'=>$row['replied_at'] ?: $row['opened_at'] ?: null,'title'=>'Bounced ('.$row['bounce_type'].')','detail'=>'','campaign'=>$cn];
    }

    // Captured reply / bounce content.
    $r = $c->query("SELECT type, subject, body, received_at FROM ten_ec_responses
                    WHERE contact_email='$emEsc' ORDER BY received_at DESC LIMIT 100");
    if ($r) while ($row = $r->fetch_assoc()) {
        $body = trim((string)$row['body']);
        if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 500) . '…';
        $events[] = ['kind'=>$row['type']==='bounce'?'bounced':'replied','at'=>$row['received_at'],
                     'title'=>$row['type']==='bounce'?'Bounce message':'Reply received',
                     'detail'=>$row['subject'] ? ($row['subject']."\n".$body) : $body, 'campaign'=>null];
    }

    // Suppression (opt-out / DNC / hard bounce).
    if ($em !== '') {
        $r = $c->query("SELECT reason, created_at FROM ten_ec_suppression WHERE email='$emEsc' LIMIT 5");
        if ($r) while ($row = $r->fetch_assoc()) {
            $events[] = ['kind'=>'suppressed','at'=>$row['created_at'],'title'=>'Suppressed — '.$row['reason'],
                         'detail'=>'','campaign'=>null];
        }
    }

    // Sort newest first; nulls last.
    usort($events, function ($a, $b) {
        $ta = $a['at'] ? strtotime($a['at']) : 0;
        $tb = $b['at'] ? strtotime($b['at']) : 0;
        return $tb <=> $ta;
    });
    return $events;
}

/** Scalar COUNT tolerant of a missing table/column → null (so a tile just hides). */
function crm_safe_scalar(?mysqli $c, string $sql): ?int {
    if (!$c) return null;
    try { $r = @$c->query($sql); if (!$r) return null; $row = $r->fetch_row(); return $row ? (int)$row[0] : 0; }
    catch (Throwable $e) { return null; }
}

/** admin_ten connection (articles/publications/venues) or null. */
function crm_admin_db(): ?mysqli {
    try {
        require_once __DIR__ . '/../config_ten_admin.php';
        if (!function_exists('getDBConnection_TENAdmin')) return null;
        $a = getDBConnection_TENAdmin();
        @$a->set_charset('utf8mb4');
        return $a;
    } catch (Throwable $e) { return null; }
}

/** PKV enquiry WHERE fragment honouring external-broker scoping (empty string if full access). */
function crm_pkv_scope_sql(mysqli $c): string {
    if (!crm_pkv_external_broker()) return '';
    $uid = (int)($_SESSION['ten_user_id'] ?? 0);
    $b = $c->query("SELECT id FROM ten_pkv_brokers WHERE user_id=$uid LIMIT 1");
    $brow = $b ? $b->fetch_assoc() : null;
    return $brow ? (' AND e.assigned_broker_id=' . (int)$brow['id']) : ' AND 1=0';
}

/**
 * Cross-module "business areas" the CRM oversees, each with live counts pulled
 * tolerantly from that module's own tables. Any missing table simply yields a
 * null stat (rendered as "—"). This is what makes the CRM manage them all.
 * Returns an ordered list of cards.
 */
function crm_business_areas(mysqli $c): array {
    $a = crm_admin_db();
    $areas = [];

    // PKV · PhiCRM / GPHI (private health insurance enquiries)
    if (crm_pkv_visible()) {
        $scope = crm_pkv_scope_sql($c);
        // crm_pkv_scope_sql uses alias e.; wrap so a bare count works
        $base = "FROM ten_pkv_enquiries e WHERE 1=1$scope";
        $areas[] = [
            'key' => 'pkv', 'name' => 'PKV · PhiCRM / GPHI', 'icon' => 'fa-heart-pulse',
            'url' => 'module-pkv.php', 'pipeline' => 'pkv',
            'stats' => [
                ['Open',   crm_safe_scalar($c, "SELECT COUNT(*) $base AND e.state NOT IN('closed_success','closed_failed')")],
                ['New',    crm_safe_scalar($c, "SELECT COUNT(*) $base AND e.state='new'")],
                ['Signed', crm_safe_scalar($c, "SELECT COUNT(*) $base AND e.state='closed_success'")],
            ],
        ];
    }

    // WNE Recruitment
    $areas[] = [
        'key' => 'wne', 'name' => 'WNE Recruitment', 'icon' => 'fa-user-tie',
        'url' => 'module-wne.php', 'pipeline' => null,
        'stats' => [
            ['Candidates', crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_cv_candidates")],
            ['Projects',   crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_wne_projects")],
            ['Contracts',  crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_wne_contracts")],
        ],
    ];

    // Project management
    $areas[] = [
        'key' => 'pm', 'name' => 'Projects', 'icon' => 'fa-diagram-project',
        'url' => 'module-project-management.php', 'pipeline' => null,
        'stats' => [
            ['Active',     crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_projects WHERE status='active'")],
            ['Open tasks', crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_project_tasks WHERE status<>'done' AND status<>'completed'")],
            ['Milestones', crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_project_milestones")],
        ],
    ];

    // Email campaigns
    $areas[] = [
        'key' => 'email', 'name' => 'Email Campaigns', 'icon' => 'fa-paper-plane',
        'url' => 'module-email-campaigns.php', 'pipeline' => null,
        'stats' => [
            ['Contacts',   crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_ec_contacts")],
            ['Sent 30d',   crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_ec_recipients WHERE status='sent' AND sent_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")],
            ['Campaigns',  crm_safe_scalar($c, "SELECT COUNT(*) FROM ten_ec_campaigns")],
        ],
    ];

    // Venues (admin_ten)
    $venueCount = crm_safe_scalar($a, "SELECT COUNT(*) FROM venues");
    if ($venueCount !== null) {
        $areas[] = [
            'key' => 'venues', 'name' => 'Venues', 'icon' => 'fa-location-dot',
            'url' => 'module-venues-tool.php', 'pipeline' => null,
            'stats' => [
                ['Venues',   $venueCount],
                ['Contacts', crm_safe_scalar($a, "SELECT COUNT(*) FROM venue_contacts")],
            ],
        ];
    }

    // News / editorial (admin_ten)
    $pub = crm_safe_scalar($a, "SELECT COUNT(*) FROM publications WHERE pub_live=1");
    if ($pub !== null) {
        $areas[] = [
            'key' => 'news', 'name' => 'News & Editorial', 'icon' => 'fa-newspaper',
            'url' => 'module-news.php', 'pipeline' => null,
            'stats' => [
                ['Publications', $pub],
                ['Published',    crm_safe_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='published'")],
                ['In review',    crm_safe_scalar($a, "SELECT COUNT(*) FROM articles WHERE state='under review'")],
            ],
        ];
    }

    if ($a) @$a->close();
    return $areas;
}

/** Whether a contact (by id) is currently suppressed, and why. */
function crm_contact_suppression(mysqli $c, string $email): ?string {
    $email = trim($email);
    if ($email === '') return null;
    $emEsc = $c->real_escape_string($email);
    $r = $c->query("SELECT reason FROM ten_ec_suppression WHERE email='$emEsc' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? $row['reason'] : null;
}
