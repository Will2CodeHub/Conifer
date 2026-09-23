<?php
// CRM Module Database Setup — DELETE AFTER RUNNING
require_once 'config.php';
requireLogin();
if (!isAdmin()) { die('Unauthorized'); }

$conn = getDBConnection();
$errors = [];
$success = [];

function run($conn, $sql, $label, &$errors, &$success) {
    if ($conn->query($sql)) { $success[] = "✓ $label"; }
    else { $errors[] = "✗ $label: " . $conn->error; }
}

// ── Tables ─────────────────────────────────────────────────────────────────

run($conn, "CREATE TABLE IF NOT EXISTS `crm_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(200) NOT NULL,
  `project_key` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#667eea',
  `icon` varchar(50) DEFAULT 'fa-briefcase',
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_key` (`project_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_projects", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_subproject_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) NOT NULL,
  `type_key` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#667eea',
  `icon` varchar(50) DEFAULT 'fa-tag',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_subproject_types", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_subprojects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `type_id` int(11) DEFAULT NULL,
  `subproject_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `goal` text DEFAULT NULL,
  `target_leads` int(11) DEFAULT 0,
  `status` enum('active','paused','completed','archived') DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `fk_subproj_project` FOREIGN KEY (`project_id`) REFERENCES `crm_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_subprojects", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_id` int(11) DEFAULT NULL COMMENT 'NULL = global default stages',
  `stage_name` varchar(150) NOT NULL,
  `stage_key` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#667eea',
  `display_order` int(11) DEFAULT 0,
  `is_win_stage` tinyint(1) DEFAULT 0,
  `is_loss_stage` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `type_id` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_pipeline_stages", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subproject_id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company` varchar(200) DEFAULT NULL,
  `position` varchar(150) DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `linkedin` varchar(300) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `current_stage_id` int(11) DEFAULT NULL,
  `status` enum('active','converted','lost','unsubscribed') DEFAULT 'active',
  `last_contacted` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subproject_id` (`subproject_id`),
  KEY `current_stage_id` (`current_stage_id`),
  CONSTRAINT `fk_lead_subproject` FOREIGN KEY (`subproject_id`) REFERENCES `crm_subprojects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_leads", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_lead_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `from_stage_id` int(11) DEFAULT NULL,
  `to_stage_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  CONSTRAINT `fk_history_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_lead_history", $errors, $success);

run($conn, "CREATE TABLE IF NOT EXISTS `crm_lead_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `activity_type` enum('email','call','meeting','note','task','other') DEFAULT 'note',
  `subject` varchar(300) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `activity_date` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  CONSTRAINT `fk_activity_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create crm_lead_activities", $errors, $success);

// ── Default Projects ────────────────────────────────────────────────────────

$defaultProjects = [
    ['The Eye Newspapers (TEN)',  'ten',     'Core TEN business operations and contacts',          '#667eea', 'fa-newspaper',   1],
    ['WNE Recruitment',           'wne',     'WNE recruitment pipeline and client contacts',        '#10b981', 'fa-handshake',   2],
    ['Venue Management Tool',     'venues',  'Venue & event management tool sales and support',     '#f59e0b', 'fa-ticket',      3],
    ['Hosting Management',        'hosting', 'TEN hosting services sales and client management',    '#3b82f6', 'fa-server',      4],
    ['PKV Health Insurance',      'pkv',     'PKV health insurance leads and client management',    '#ef4444', 'fa-heartbeat',   5],
];
foreach ($defaultProjects as $p) {
    $exists = $conn->query("SELECT id FROM crm_projects WHERE project_key='{$p[1]}'")->num_rows;
    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO crm_projects (project_name,project_key,description,color,icon,display_order,created_by) VALUES (?,?,?,?,?,?,1)");
        $stmt->bind_param("sssssi", $p[0],$p[1],$p[2],$p[3],$p[4],$p[5]);
        $stmt->execute();
        $success[] = "✓ Created project: {$p[0]}";
        $stmt->close();
    } else { $success[] = "→ Project {$p[1]} exists, skipped"; }
}

// ── Default Subproject Types ────────────────────────────────────────────────

$defaultTypes = [
    ['Sales',          'sales',       'Sales pipeline and lead generation',           '#10b981', 'fa-dollar-sign'],
    ['Marketing',      'marketing',   'Marketing campaigns and audience building',     '#667eea', 'fa-bullhorn'],
    ['Partnerships',   'partnerships','Business partnerships and alliances',            '#f59e0b', 'fa-handshake'],
    ['Client Success', 'client',      'Existing client management and retention',      '#3b82f6', 'fa-star'],
    ['Recruitment',    'recruitment', 'Talent acquisition pipelines',                  '#8b5cf6', 'fa-user-tie'],
    ['General',        'general',     'General purpose CRM pipeline',                  '#64748b', 'fa-folder'],
];
foreach ($defaultTypes as $t) {
    $exists = $conn->query("SELECT id FROM crm_subproject_types WHERE type_key='{$t[1]}'")->num_rows;
    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO crm_subproject_types (type_name,type_key,description,color,icon) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $t[0],$t[1],$t[2],$t[3],$t[4]);
        $stmt->execute();
        $success[] = "✓ Created type: {$t[0]}";
        $stmt->close();
    }
}

// ── Default Pipeline Stages per type ───────────────────────────────────────

$typeMap = [];
$r = $conn->query("SELECT id, type_key FROM crm_subproject_types");
while ($row = $r->fetch_assoc()) $typeMap[$row['type_key']] = $row['id'];

$stageSets = [
    'sales' => [
        ['Prospect',       'prospect',       '#94a3b8', 1, 0, 0],
        ['Contacted',      'contacted',      '#3b82f6', 2, 0, 0],
        ['Qualified',      'qualified',      '#8b5cf6', 3, 0, 0],
        ['Proposal Sent',  'proposal',       '#f59e0b', 4, 0, 0],
        ['Negotiation',    'negotiation',    '#f97316', 5, 0, 0],
        ['Won',            'won',            '#10b981', 6, 1, 0],
        ['Lost',           'lost',           '#ef4444', 7, 0, 1],
    ],
    'marketing' => [
        ['Awareness',      'awareness',      '#94a3b8', 1, 0, 0],
        ['Engaged',        'engaged',        '#3b82f6', 2, 0, 0],
        ['Interested',     'interested',     '#8b5cf6', 3, 0, 0],
        ['Nurtured',       'nurtured',       '#f59e0b', 4, 0, 0],
        ['Converted',      'converted',      '#10b981', 5, 1, 0],
        ['Inactive',       'inactive',       '#ef4444', 6, 0, 1],
    ],
    'partnerships' => [
        ['Initial Contact','initial',        '#94a3b8', 1, 0, 0],
        ['Exploratory',    'exploratory',    '#3b82f6', 2, 0, 0],
        ['Proposal',       'proposal',       '#8b5cf6', 3, 0, 0],
        ['Negotiation',    'negotiation',    '#f59e0b', 4, 0, 0],
        ['Agreement',      'agreement',      '#10b981', 5, 1, 0],
        ['Declined',       'declined',       '#ef4444', 6, 0, 1],
    ],
    'client' => [
        ['Onboarding',     'onboarding',     '#3b82f6', 1, 0, 0],
        ['Active',         'active',         '#10b981', 2, 0, 0],
        ['At Risk',        'at_risk',        '#f59e0b', 3, 0, 0],
        ['Renewal',        'renewal',        '#8b5cf6', 4, 0, 0],
        ['Churned',        'churned',        '#ef4444', 5, 0, 1],
    ],
    'recruitment' => [
        ['Applied',        'applied',        '#94a3b8', 1, 0, 0],
        ['Screened',       'screened',       '#3b82f6', 2, 0, 0],
        ['Interview',      'interview',      '#8b5cf6', 3, 0, 0],
        ['Assessment',     'assessment',     '#f59e0b', 4, 0, 0],
        ['Offer',          'offer',          '#f97316', 5, 0, 0],
        ['Hired',          'hired',          '#10b981', 6, 1, 0],
        ['Rejected',       'rejected',       '#ef4444', 7, 0, 1],
    ],
    'general' => [
        ['New',            'new',            '#94a3b8', 1, 0, 0],
        ['In Progress',    'in_progress',    '#3b82f6', 2, 0, 0],
        ['Review',         'review',         '#f59e0b', 3, 0, 0],
        ['Complete',       'complete',       '#10b981', 4, 1, 0],
        ['Closed',         'closed',         '#ef4444', 5, 0, 1],
    ],
];

foreach ($stageSets as $typeKey => $stages) {
    if (!isset($typeMap[$typeKey])) continue;
    $tid = $typeMap[$typeKey];
    foreach ($stages as $s) {
        $exists = $conn->query("SELECT id FROM crm_pipeline_stages WHERE type_id=$tid AND stage_key='{$s[1]}'")->num_rows;
        if (!$exists) {
            $stmt = $conn->prepare("INSERT INTO crm_pipeline_stages (type_id,stage_name,stage_key,color,display_order,is_win_stage,is_loss_stage) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("isssiis", $tid,$s[0],$s[1],$s[2],$s[3],$s[4],$s[5]);
            // Fix: is_win_stage and is_loss_stage are ints
            $tid2=$tid; $sn=$s[0]; $sk=$s[1]; $sc=$s[2]; $so=(int)$s[3]; $sw=(int)$s[4]; $sl=(int)$s[5];
            $stmt->close();
            $stmt2 = $conn->prepare("INSERT INTO crm_pipeline_stages (type_id,stage_name,stage_key,color,display_order,is_win_stage,is_loss_stage) VALUES (?,?,?,?,?,?,?)");
            $stmt2->bind_param("isssiii", $tid2,$sn,$sk,$sc,$so,$sw,$sl);
            $stmt2->execute();
            $stmt2->close();
            $success[] = "✓ Stage: [$typeKey] {$s[0]}";
        }
    }
}

// ── Permissions ─────────────────────────────────────────────────────────────

$newPerms = [
    ['crm.view',            'View CRM',             'Access the CRM module',                       'crm', 'view'],
    ['crm.manage',          'Manage CRM',           'Create and edit projects, subprojects, leads', 'crm', 'manage'],
    ['crm.admin',           'Admin CRM',            'Full CRM administration including settings',   'crm', 'manage'],
    ['crm.delete',          'Delete CRM Records',   'Delete leads, subprojects, projects',          'crm', 'delete'],
];
foreach ($newPerms as $p) {
    $exists = $conn->query("SELECT id FROM ten_permissions WHERE permission_key='{$p[0]}'")->num_rows;
    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO ten_permissions (permission_key,permission_name,permission_description,module,permission_type,is_system) VALUES (?,?,?,?,?,0)");
        $stmt->bind_param("sssss", $p[0],$p[1],$p[2],$p[3],$p[4]);
        $stmt->execute();
        $stmt->close();
        $success[] = "✓ Permission: {$p[0]}";
    } else { $success[] = "→ Permission {$p[0]} exists"; }
}

// ── Register Module ──────────────────────────────────────────────────────────

$modExists = $conn->query("SELECT id FROM ten_modules WHERE module_key='crm'")->num_rows;
if (!$modExists) {
    $conn->query("INSERT INTO ten_modules (module_key,module_name,module_description,module_icon,module_url,module_group,is_enabled,is_system,display_order,required_permission,created_at)
                  VALUES ('crm','CRM','Customer Relationship Management — projects, leads, pipeline','fa-chart-line','/management/module-crm.php','bus_tools',1,0,10,'crm.view',NOW())");
    $success[] = "✓ Module registered: CRM";
} else {
    $conn->query("UPDATE ten_modules SET required_permission='crm.view', module_icon='fa-people-arrows', module_url='/management/module-crm.php' WHERE module_key='crm'");
    $success[] = "→ Module already exists, updated";
}

// ── Assign CRM permissions to roles ─────────────────────────────────────────

// Super User (1) and Admin (2) get all CRM permissions
// Also create a CRM role for the four specific users
$crmPermKeys = ['crm.view', 'crm.manage', 'crm.admin', 'crm.delete'];
foreach ([1, 2] as $roleId) {
    foreach ($crmPermKeys as $pk) {
        $pRow = $conn->query("SELECT id FROM ten_permissions WHERE permission_key='$pk'")->fetch_assoc();
        if (!$pRow) continue;
        $pid = $pRow['id'];
        $already = $conn->query("SELECT id FROM ten_role_permissions WHERE role_id=$roleId AND permission_id=$pid")->num_rows;
        if (!$already) {
            $conn->query("INSERT INTO ten_role_permissions (role_id,permission_id) VALUES ($roleId,$pid)");
            $success[] = "✓ Role #$roleId ← $pk";
        }
    }
}

// Grace Gomez (user 3) has super_user so she's covered.
// Ensure all four users have crm.view at minimum via their roles.
// Prince (admin=2), Hannah (admin=2), William (super_user=1), Grace (super_user=1) — all covered.

$conn->close();
?>
<!DOCTYPE html>
<html><head><title>CRM Setup</title>
<style>
body{font-family:monospace;padding:20px;background:#111;color:#eee}
.ok{color:#4ade80}.err{color:#f87171}.info{color:#93c5fd}h2{color:#fff}
</style></head><body>
<h2>CRM Module Setup</h2>
<?php foreach ($success as $s): ?><div class="ok"><?=htmlspecialchars($s)?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="err"><?=htmlspecialchars($e)?></div><?php endforeach; ?>
<br><div class="info">Done — <?=count($success)?> succeeded, <?=count($errors)?> errors.</div>
<br><div class="err" style="font-size:1.3em">⚠️ DELETE THIS FILE: crm_setup.php</div>
</body></html>
