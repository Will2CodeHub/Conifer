<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$action = $_POST['action'] ?? '';
$conn = getDBConnection();

$canManage = hasModulePermission('crm.manage') || isAdmin();
$canView   = hasModulePermission('crm.view')   || isAdmin();

if (!$canView) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    $conn->close();
    exit();
}

$userId = (int)$_SESSION['ten_user_id'];

// Helper: require manage permission
function requireManage($canManage, $conn) {
    if (!$canManage) {
        echo json_encode(['success' => false, 'message' => 'Manage permission required']);
        $conn->close();
        exit();
    }
}

// Helper: generate a slug key from a string
function makeKey($str) {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9]+/', '_', $str);
    $str = trim($str, '_');
    return $str ?: 'item_' . time();
}

switch ($action) {

    // ─── DASHBOARD ────────────────────────────────────────────────────────────
    case 'get_dashboard': {
        $totalProjects = $conn->query("SELECT COUNT(*) c FROM crm_projects WHERE is_active=1")->fetch_assoc()['c'];
        $totalSubprojects = $conn->query("SELECT COUNT(*) c FROM crm_subprojects")->fetch_assoc()['c'];
        $totalLeads = $conn->query("SELECT COUNT(*) c FROM crm_leads")->fetch_assoc()['c'];

        $wonLeads = $conn->query("
            SELECT COUNT(*) c FROM crm_leads l
            JOIN crm_pipeline_stages s ON l.current_stage_id = s.id
            WHERE s.is_win_stage = 1
        ")->fetch_assoc()['c'];

        $convRate = $totalLeads > 0 ? round($wonLeads / $totalLeads * 100, 1) : 0;

        // Leads by stage
        $byStage = [];
        $r = $conn->query("
            SELECT s.stage_name, s.color, COUNT(l.id) cnt
            FROM crm_pipeline_stages s
            LEFT JOIN crm_leads l ON l.current_stage_id = s.id
            GROUP BY s.id
            ORDER BY s.display_order
        ");
        while ($row = $r->fetch_assoc()) $byStage[] = $row;

        // Recent activities
        $recentAct = [];
        $r2 = $conn->query("
            SELECT a.*, CONCAT(l.first_name,' ',l.last_name) lead_name, l.id lead_id
            FROM crm_lead_activities a
            JOIN crm_leads l ON a.lead_id = l.id
            ORDER BY a.created_at DESC LIMIT 5
        ");
        while ($row = $r2->fetch_assoc()) $recentAct[] = $row;

        // Won this month
        $wonMonth = $conn->query("
            SELECT COUNT(*) c FROM crm_leads l
            JOIN crm_pipeline_stages s ON l.current_stage_id = s.id
            WHERE s.is_win_stage = 1
            AND MONTH(l.updated_at) = MONTH(CURDATE())
            AND YEAR(l.updated_at) = YEAR(CURDATE())
        ")->fetch_assoc()['c'];

        echo json_encode([
            'success' => true,
            'data' => [
                'total_projects'    => $totalProjects,
                'total_subprojects' => $totalSubprojects,
                'total_leads'       => $totalLeads,
                'won_leads'         => $wonLeads,
                'won_this_month'    => $wonMonth,
                'conversion_rate'   => $convRate,
                'leads_by_stage'    => $byStage,
                'recent_activities' => $recentAct,
            ]
        ]);
        break;
    }

    // ─── PROJECTS ─────────────────────────────────────────────────────────────
    case 'get_projects': {
        $rows = [];
        $r = $conn->query("
            SELECT p.*,
                   (SELECT COUNT(*) FROM crm_subprojects sp WHERE sp.project_id = p.id) sub_count,
                   (SELECT COUNT(*) FROM crm_leads l JOIN crm_subprojects sp ON l.subproject_id = sp.id WHERE sp.project_id = p.id) lead_count
            FROM crm_projects p
            ORDER BY p.display_order, p.project_name
        ");
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['success' => true, 'data' => $rows]);
        break;
    }

    case 'create_project': {
        requireManage($canManage, $conn);
        $name = trim($_POST['project_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#667eea');
        $icon = trim($_POST['icon'] ?? 'fa-folder');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Project name required']); break; }
        $key = makeKey($name);
        // Ensure unique key
        $existing = $conn->prepare("SELECT id FROM crm_projects WHERE project_key=?");
        $existing->bind_param("s", $key); $existing->execute();
        if ($existing->get_result()->num_rows > 0) $key .= '_' . time();
        $existing->close();
        $stmt = $conn->prepare("INSERT INTO crm_projects (project_name,project_key,description,color,icon,is_active,display_order,created_by,created_at,updated_at) VALUES(?,?,?,?,?,1,(SELECT IFNULL(MAX(display_order),0)+1 FROM crm_projects p2),?,NOW(),NOW())");
        $stmt->bind_param("ssssssi", $name, $key, $desc, $color, $icon, $userId);
        $stmt->execute();
        $id = $conn->insert_id;
        $stmt->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Project created']);
        break;
    }

    case 'update_project': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['project_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#667eea');
        $icon = trim($_POST['icon'] ?? 'fa-folder');
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Invalid input']); break; }
        $stmt = $conn->prepare("UPDATE crm_projects SET project_name=?,description=?,color=?,icon=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssssi", $name, $desc, $color, $icon, $id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Project updated']);
        break;
    }

    case 'delete_project': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $check = $conn->prepare("SELECT COUNT(*) c FROM crm_subprojects WHERE project_id=?");
        $check->bind_param("i", $id); $check->execute();
        if ($check->get_result()->fetch_assoc()['c'] > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: project has subprojects']); $check->close(); break;
        }
        $check->close();
        $stmt = $conn->prepare("DELETE FROM crm_projects WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Project deleted']);
        break;
    }

    case 'toggle_project': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        $stmt = $conn->prepare("UPDATE crm_projects SET is_active=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ii", $val, $id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Project toggled']);
        break;
    }

    // ─── SUBPROJECTS ──────────────────────────────────────────────────────────
    case 'get_subprojects': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $rows = [];
        $r = $conn->prepare("
            SELECT sp.*, t.type_name, t.color type_color, t.icon type_icon,
                   (SELECT COUNT(*) FROM crm_leads l WHERE l.subproject_id = sp.id) lead_count,
                   (SELECT COUNT(*) FROM crm_leads l JOIN crm_pipeline_stages s ON l.current_stage_id=s.id WHERE l.subproject_id=sp.id AND s.is_win_stage=1) won_count
            FROM crm_subprojects sp
            LEFT JOIN crm_subproject_types t ON sp.type_id = t.id
            WHERE sp.project_id=?
            ORDER BY sp.created_at DESC
        ");
        $r->bind_param("i", $pid); $r->execute();
        $res = $r->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $r->close();
        echo json_encode(['success'=>true,'data'=>$rows]);
        break;
    }

    case 'create_subproject': {
        requireManage($canManage, $conn);
        $pid  = (int)($_POST['project_id'] ?? 0);
        $tid  = (int)($_POST['type_id'] ?? 0);
        $name = trim($_POST['subproject_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $goal = trim($_POST['goal'] ?? '');
        $tgt  = (int)($_POST['target_leads'] ?? 0);
        $start= trim($_POST['start_date'] ?? '') ?: null;
        $end  = trim($_POST['end_date'] ?? '') ?: null;
        if (!$pid || !$tid || !$name) { echo json_encode(['success'=>false,'message'=>'Required fields missing']); break; }
        $stmt = $conn->prepare("INSERT INTO crm_subprojects (project_id,type_id,subproject_name,description,goal,target_leads,status,start_date,end_date,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,'active',?,?,?,NOW(),NOW())");
        $stmt->bind_param("iisssissi", $pid, $tid, $name, $desc, $goal, $tgt, $start, $end, $userId);
        $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Subproject created']);
        break;
    }

    case 'update_subproject': {
        requireManage($canManage, $conn);
        $id   = (int)($_POST['id'] ?? 0);
        $tid  = (int)($_POST['type_id'] ?? 0);
        $name = trim($_POST['subproject_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $goal = trim($_POST['goal'] ?? '');
        $tgt  = (int)($_POST['target_leads'] ?? 0);
        $status = trim($_POST['status'] ?? 'active');
        $start= trim($_POST['start_date'] ?? '') ?: null;
        $end  = trim($_POST['end_date'] ?? '') ?: null;
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Invalid input']); break; }
        $stmt = $conn->prepare("UPDATE crm_subprojects SET type_id=?,subproject_name=?,description=?,goal=?,target_leads=?,status=?,start_date=?,end_date=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param("issssissi", $tid, $name, $desc, $goal, $tgt, $status, $start, $end, $id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Subproject updated']);
        break;
    }

    case 'delete_subproject': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $check = $conn->prepare("SELECT COUNT(*) c FROM crm_leads WHERE subproject_id=?");
        $check->bind_param("i", $id); $check->execute();
        if ($check->get_result()->fetch_assoc()['c'] > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: subproject has leads']); $check->close(); break;
        }
        $check->close();
        $stmt = $conn->prepare("DELETE FROM crm_subprojects WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Subproject deleted']);
        break;
    }

    // ─── LEADS ────────────────────────────────────────────────────────────────
    case 'get_leads': {
        $spid = (int)($_POST['subproject_id'] ?? 0);
        // Get type_id for subproject to find stages
        $spInfo = $conn->prepare("SELECT type_id FROM crm_subprojects WHERE id=?");
        $spInfo->bind_param("i", $spid); $spInfo->execute();
        $sp = $spInfo->get_result()->fetch_assoc(); $spInfo->close();
        $typeId = $sp ? (int)$sp['type_id'] : 0;

        // Get stages for this type (or global)
        $stages = [];
        $r = $conn->prepare("SELECT * FROM crm_pipeline_stages WHERE (type_id=? OR type_id IS NULL) AND is_active=1 ORDER BY display_order");
        $r->bind_param("i", $typeId); $r->execute();
        $res = $r->get_result();
        while ($row = $res->fetch_assoc()) $stages[] = $row;
        $r->close();

        // Get leads
        $leads = [];
        $r2 = $conn->prepare("
            SELECT l.*, u.full_name assigned_name
            FROM crm_leads l
            LEFT JOIN ten_users u ON l.assigned_to = u.id
            WHERE l.subproject_id=?
            ORDER BY l.created_at DESC
        ");
        $r2->bind_param("i", $spid); $r2->execute();
        $res2 = $r2->get_result();
        while ($row = $res2->fetch_assoc()) $leads[] = $row;
        $r2->close();

        echo json_encode(['success'=>true,'data'=>['stages'=>$stages,'leads'=>$leads]]);
        break;
    }

    case 'create_lead': {
        requireManage($canManage, $conn);
        $spid     = (int)($_POST['subproject_id'] ?? 0);
        $fname    = trim($_POST['first_name'] ?? '');
        $lname    = trim($_POST['last_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $company  = trim($_POST['company'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $website  = trim($_POST['website'] ?? '');
        $linkedin = trim($_POST['linkedin'] ?? '');
        $country  = trim($_POST['country'] ?? '');
        $city     = trim($_POST['city'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $source   = trim($_POST['source'] ?? '');
        if (!$spid || !$fname) { echo json_encode(['success'=>false,'message'=>'Required fields missing']); break; }

        // Get first stage for subproject type
        $spInfo = $conn->prepare("SELECT type_id FROM crm_subprojects WHERE id=?");
        $spInfo->bind_param("i", $spid); $spInfo->execute();
        $sp = $spInfo->get_result()->fetch_assoc(); $spInfo->close();
        $typeId = $sp ? (int)$sp['type_id'] : 0;

        $stageRow = $conn->prepare("SELECT id FROM crm_pipeline_stages WHERE (type_id=? OR type_id IS NULL) AND is_active=1 ORDER BY display_order LIMIT 1");
        $stageRow->bind_param("i", $typeId); $stageRow->execute();
        $st = $stageRow->get_result()->fetch_assoc(); $stageRow->close();
        $stageId = $st ? (int)$st['id'] : null;

        $stmt = $conn->prepare("INSERT INTO crm_leads (subproject_id,first_name,last_name,email,phone,company,position,website,linkedin,country,city,notes,source,current_stage_id,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,NOW(),NOW())");
        $stmt->bind_param("issssssssssssii", $spid,$fname,$lname,$email,$phone,$company,$position,$website,$linkedin,$country,$city,$notes,$source,$stageId,$userId);
        $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Lead created']);
        break;
    }

    case 'update_lead': {
        requireManage($canManage, $conn);
        $id       = (int)($_POST['id'] ?? 0);
        $fname    = trim($_POST['first_name'] ?? '');
        $lname    = trim($_POST['last_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $company  = trim($_POST['company'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $website  = trim($_POST['website'] ?? '');
        $linkedin = trim($_POST['linkedin'] ?? '');
        $country  = trim($_POST['country'] ?? '');
        $city     = trim($_POST['city'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $source   = trim($_POST['source'] ?? '');
        $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
        if (!$id || !$fname) { echo json_encode(['success'=>false,'message'=>'Invalid input']); break; }
        $stmt = $conn->prepare("UPDATE crm_leads SET first_name=?,last_name=?,email=?,phone=?,company=?,position=?,website=?,linkedin=?,country=?,city=?,notes=?,source=?,assigned_to=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssssssssssssii", $fname,$lname,$email,$phone,$company,$position,$website,$linkedin,$country,$city,$notes,$source,$assignedTo,$id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Lead updated']);
        break;
    }

    case 'delete_lead': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $s1 = $conn->prepare("DELETE FROM crm_lead_activities WHERE lead_id=?"); $s1->bind_param("i",$id); $s1->execute(); $s1->close();
        $s2 = $conn->prepare("DELETE FROM crm_lead_history WHERE lead_id=?"); $s2->bind_param("i",$id); $s2->execute(); $s2->close();
        $s3 = $conn->prepare("DELETE FROM crm_leads WHERE id=?"); $s3->bind_param("i",$id); $s3->execute(); $s3->close();
        echo json_encode(['success'=>true,'message'=>'Lead deleted']);
        break;
    }

    case 'move_lead': {
        requireManage($canManage, $conn);
        $leadId  = (int)($_POST['lead_id'] ?? 0);
        $toStage = (int)($_POST['to_stage_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');
        if (!$leadId || !$toStage) { echo json_encode(['success'=>false,'message'=>'Invalid parameters']); break; }
        // Get current stage
        $cur = $conn->prepare("SELECT current_stage_id FROM crm_leads WHERE id=?");
        $cur->bind_param("i",$leadId); $cur->execute();
        $curRow = $cur->get_result()->fetch_assoc(); $cur->close();
        $fromStage = $curRow ? (int)$curRow['current_stage_id'] : null;
        // Insert history
        $h = $conn->prepare("INSERT INTO crm_lead_history (lead_id,from_stage_id,to_stage_id,notes,changed_by,created_at) VALUES(?,?,?,?,?,NOW())");
        $h->bind_param("iiisi",$leadId,$fromStage,$toStage,$notes,$userId); $h->execute(); $h->close();
        // Update lead
        // Check if win/loss stage → update status
        $stageInfo = $conn->prepare("SELECT is_win_stage,is_loss_stage FROM crm_pipeline_stages WHERE id=?");
        $stageInfo->bind_param("i",$toStage); $stageInfo->execute();
        $si = $stageInfo->get_result()->fetch_assoc(); $stageInfo->close();
        $status = 'active';
        if ($si) {
            if ($si['is_win_stage'])  $status = 'converted';
            if ($si['is_loss_stage']) $status = 'lost';
        }
        $u = $conn->prepare("UPDATE crm_leads SET current_stage_id=?,status=?,updated_at=NOW() WHERE id=?");
        $u->bind_param("isi",$toStage,$status,$leadId); $u->execute(); $u->close();
        echo json_encode(['success'=>true,'message'=>'Lead moved']);
        break;
    }

    case 'get_lead_detail': {
        $lid = (int)($_POST['lead_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT l.*, s.stage_name, s.color stage_color, s.is_win_stage, s.is_loss_stage,
                   u.full_name assigned_name
            FROM crm_leads l
            LEFT JOIN crm_pipeline_stages s ON l.current_stage_id = s.id
            LEFT JOIN ten_users u ON l.assigned_to = u.id
            WHERE l.id=?
        ");
        $stmt->bind_param("i",$lid); $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$lead) { echo json_encode(['success'=>false,'message'=>'Lead not found']); break; }

        $activities = [];
        $r = $conn->prepare("SELECT * FROM crm_lead_activities WHERE lead_id=? ORDER BY activity_date DESC, created_at DESC");
        $r->bind_param("i",$lid); $r->execute();
        $res = $r->get_result(); while ($row = $res->fetch_assoc()) $activities[] = $row; $r->close();

        $history = [];
        $r2 = $conn->prepare("
            SELECT h.*, fs.stage_name from_stage, ts.stage_name to_stage, ts.color to_color
            FROM crm_lead_history h
            LEFT JOIN crm_pipeline_stages fs ON h.from_stage_id = fs.id
            LEFT JOIN crm_pipeline_stages ts ON h.to_stage_id   = ts.id
            WHERE h.lead_id=? ORDER BY h.created_at DESC
        ");
        $r2->bind_param("i",$lid); $r2->execute();
        $res2 = $r2->get_result(); while ($row = $res2->fetch_assoc()) $history[] = $row; $r2->close();

        echo json_encode(['success'=>true,'data'=>['lead'=>$lead,'activities'=>$activities,'history'=>$history]]);
        break;
    }

    case 'add_activity': {
        requireManage($canManage, $conn);
        $lid  = (int)($_POST['lead_id'] ?? 0);
        $type = trim($_POST['activity_type'] ?? 'note');
        $subj = trim($_POST['subject'] ?? '');
        $cont = trim($_POST['content'] ?? '');
        $date = trim($_POST['activity_date'] ?? date('Y-m-d H:i:s'));
        if (!$lid || !$subj) { echo json_encode(['success'=>false,'message'=>'Required fields missing']); break; }
        $stmt = $conn->prepare("INSERT INTO crm_lead_activities (lead_id,activity_type,subject,content,activity_date,created_by,created_at) VALUES(?,?,?,?,?,?,NOW())");
        $stmt->bind_param("issssi",$lid,$type,$subj,$cont,$date,$userId); $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        // Update last_contacted
        $u = $conn->prepare("UPDATE crm_leads SET last_contacted=NOW(),updated_at=NOW() WHERE id=?");
        $u->bind_param("i",$lid); $u->execute(); $u->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Activity added']);
        break;
    }

    case 'delete_activity': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM crm_lead_activities WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Activity deleted']);
        break;
    }

    // ─── SUBPROJECT TYPES ─────────────────────────────────────────────────────
    case 'get_types': {
        $rows = [];
        $r = $conn->query("
            SELECT t.*,
                   (SELECT COUNT(*) FROM crm_subprojects sp WHERE sp.type_id = t.id) sub_count,
                   (SELECT COUNT(*) FROM crm_pipeline_stages ps WHERE ps.type_id = t.id) stage_count
            FROM crm_subproject_types t
            ORDER BY t.type_name
        ");
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode(['success'=>true,'data'=>$rows]);
        break;
    }

    case 'create_type': {
        requireManage($canManage, $conn);
        $name  = trim($_POST['type_name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#667eea');
        $icon  = trim($_POST['icon'] ?? 'fa-tag');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Type name required']); break; }
        $key = makeKey($name);
        $existing = $conn->prepare("SELECT id FROM crm_subproject_types WHERE type_key=?");
        $existing->bind_param("s",$key); $existing->execute();
        if ($existing->get_result()->num_rows > 0) $key .= '_' . time();
        $existing->close();
        $stmt = $conn->prepare("INSERT INTO crm_subproject_types (type_name,type_key,description,color,icon,is_active,created_at) VALUES(?,?,?,?,?,1,NOW())");
        $stmt->bind_param("sssss",$name,$key,$desc,$color,$icon); $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Type created']);
        break;
    }

    case 'update_type': {
        requireManage($canManage, $conn);
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['type_name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#667eea');
        $icon  = trim($_POST['icon'] ?? 'fa-tag');
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Invalid input']); break; }
        $stmt = $conn->prepare("UPDATE crm_subproject_types SET type_name=?,description=?,color=?,icon=? WHERE id=?");
        $stmt->bind_param("ssssi",$name,$desc,$color,$icon,$id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Type updated']);
        break;
    }

    case 'delete_type': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $check = $conn->prepare("SELECT COUNT(*) c FROM crm_subprojects WHERE type_id=?");
        $check->bind_param("i",$id); $check->execute();
        if ($check->get_result()->fetch_assoc()['c'] > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: type is used by subprojects']); $check->close(); break;
        }
        $check->close();
        // Delete associated stages
        $ds = $conn->prepare("DELETE FROM crm_pipeline_stages WHERE type_id=?"); $ds->bind_param("i",$id); $ds->execute(); $ds->close();
        $stmt = $conn->prepare("DELETE FROM crm_subproject_types WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Type deleted']);
        break;
    }

    // ─── PIPELINE STAGES ──────────────────────────────────────────────────────
    case 'get_stages': {
        $typeId = isset($_POST['type_id']) && $_POST['type_id'] !== '' ? (int)$_POST['type_id'] : null;
        $rows = [];
        if ($typeId !== null) {
            $r = $conn->prepare("SELECT * FROM crm_pipeline_stages WHERE type_id=? ORDER BY display_order");
            $r->bind_param("i",$typeId);
        } else {
            $r = $conn->prepare("SELECT * FROM crm_pipeline_stages WHERE type_id IS NULL ORDER BY display_order");
        }
        $r->execute(); $res = $r->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $r->close();
        echo json_encode(['success'=>true,'data'=>$rows]);
        break;
    }

    case 'create_stage': {
        requireManage($canManage, $conn);
        $typeId = isset($_POST['type_id']) && $_POST['type_id'] !== '' ? (int)$_POST['type_id'] : null;
        $name   = trim($_POST['stage_name'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $color  = trim($_POST['color'] ?? '#667eea');
        $order  = (int)($_POST['display_order'] ?? 0);
        $isWin  = (int)($_POST['is_win_stage'] ?? 0);
        $isLoss = (int)($_POST['is_loss_stage'] ?? 0);
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Stage name required']); break; }
        $key = makeKey($name);
        $stmt = $conn->prepare("INSERT INTO crm_pipeline_stages (type_id,stage_name,stage_key,description,color,display_order,is_win_stage,is_loss_stage,is_active,created_at) VALUES(?,?,?,?,?,?,?,?,1,NOW())");
        $stmt->bind_param("issssiis", $typeId,$name,$key,$desc,$color,$order,$isWin,$isLoss);
        // Fix: handle null type_id
        if ($typeId === null) {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO crm_pipeline_stages (type_id,stage_name,stage_key,description,color,display_order,is_win_stage,is_loss_stage,is_active,created_at) VALUES(NULL,?,?,?,?,?,?,?,1,NOW())");
            $stmt->bind_param("ssssiis",$name,$key,$desc,$color,$order,$isWin,$isLoss);
        }
        $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'Stage created']);
        break;
    }

    case 'update_stage': {
        requireManage($canManage, $conn);
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['stage_name'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $color  = trim($_POST['color'] ?? '#667eea');
        $order  = (int)($_POST['display_order'] ?? 0);
        $isWin  = (int)($_POST['is_win_stage'] ?? 0);
        $isLoss = (int)($_POST['is_loss_stage'] ?? 0);
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Invalid input']); break; }
        $stmt = $conn->prepare("UPDATE crm_pipeline_stages SET stage_name=?,description=?,color=?,display_order=?,is_win_stage=?,is_loss_stage=? WHERE id=?");
        $stmt->bind_param("sssiiii",$name,$desc,$color,$order,$isWin,$isLoss,$id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Stage updated']);
        break;
    }

    case 'delete_stage': {
        requireManage($canManage, $conn);
        $id = (int)($_POST['id'] ?? 0);
        $check = $conn->prepare("SELECT COUNT(*) c FROM crm_leads WHERE current_stage_id=?");
        $check->bind_param("i",$id); $check->execute();
        if ($check->get_result()->fetch_assoc()['c'] > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: leads are in this stage']); $check->close(); break;
        }
        $check->close();
        $stmt = $conn->prepare("DELETE FROM crm_pipeline_stages WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Stage deleted']);
        break;
    }

    case 'reorder_stages': {
        requireManage($canManage, $conn);
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items)) { echo json_encode(['success'=>false,'message'=>'Invalid data']); break; }
        $stmt = $conn->prepare("UPDATE crm_pipeline_stages SET display_order=? WHERE id=?");
        foreach ($items as $item) {
            $ord = (int)($item['display_order'] ?? 0);
            $sid = (int)($item['id'] ?? 0);
            if ($sid) { $stmt->bind_param("ii",$ord,$sid); $stmt->execute(); }
        }
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Stages reordered']);
        break;
    }

    // ─── SUBPROJECT STATS ─────────────────────────────────────────────────────
    case 'get_subproject_stats': {
        $spid = (int)($_POST['subproject_id'] ?? 0);
        $spInfo = $conn->prepare("SELECT * FROM crm_subprojects WHERE id=?");
        $spInfo->bind_param("i",$spid); $spInfo->execute();
        $sp = $spInfo->get_result()->fetch_assoc(); $spInfo->close();
        if (!$sp) { echo json_encode(['success'=>false,'message'=>'Subproject not found']); break; }

        $typeId = (int)$sp['type_id'];
        // Leads per stage
        $byStage = [];
        $r = $conn->prepare("
            SELECT s.stage_name, s.color, s.is_win_stage, s.is_loss_stage, COUNT(l.id) cnt
            FROM crm_pipeline_stages s
            LEFT JOIN crm_leads l ON l.current_stage_id = s.id AND l.subproject_id=?
            WHERE (s.type_id=? OR s.type_id IS NULL) AND s.is_active=1
            GROUP BY s.id ORDER BY s.display_order
        ");
        $r->bind_param("ii",$spid,$typeId); $r->execute();
        $res = $r->get_result(); while ($row = $res->fetch_assoc()) $byStage[] = $row; $r->close();

        $totalLeads = $conn->prepare("SELECT COUNT(*) c FROM crm_leads WHERE subproject_id=?");
        $totalLeads->bind_param("i",$spid); $totalLeads->execute();
        $tl = $totalLeads->get_result()->fetch_assoc()['c']; $totalLeads->close();

        $wonLeads = $conn->prepare("SELECT COUNT(*) c FROM crm_leads l JOIN crm_pipeline_stages s ON l.current_stage_id=s.id WHERE l.subproject_id=? AND s.is_win_stage=1");
        $wonLeads->bind_param("i",$spid); $wonLeads->execute();
        $wl = $wonLeads->get_result()->fetch_assoc()['c']; $wonLeads->close();

        $actCount = $conn->prepare("SELECT COUNT(*) c FROM crm_lead_activities a JOIN crm_leads l ON a.lead_id=l.id WHERE l.subproject_id=?");
        $actCount->bind_param("i",$spid); $actCount->execute();
        $ac = $actCount->get_result()->fetch_assoc()['c']; $actCount->close();

        // Timeline: leads created per day last 30 days
        $timeline = [];
        $r3 = $conn->prepare("
            SELECT DATE(created_at) d, COUNT(*) cnt FROM crm_leads
            WHERE subproject_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at) ORDER BY d
        ");
        $r3->bind_param("i",$spid); $r3->execute();
        $res3 = $r3->get_result(); while ($row = $res3->fetch_assoc()) $timeline[] = $row; $r3->close();

        $convRate = $tl > 0 ? round($wl/$tl*100,1) : 0;
        echo json_encode(['success'=>true,'data'=>[
            'subproject'      => $sp,
            'leads_by_stage'  => $byStage,
            'total_leads'     => $tl,
            'won_leads'       => $wl,
            'activity_count'  => $ac,
            'conversion_rate' => $convRate,
            'timeline'        => $timeline,
        ]]);
        break;
    }

    // ─── IMPORT LEADS ─────────────────────────────────────────────────────────
    case 'import_leads': {
        requireManage($canManage, $conn);
        $spid    = (int)($_POST['subproject_id'] ?? 0);
        $csvData = trim($_POST['csv_data'] ?? '');
        if (!$spid || !$csvData) { echo json_encode(['success'=>false,'message'=>'Missing parameters']); break; }

        $spInfo = $conn->prepare("SELECT type_id FROM crm_subprojects WHERE id=?");
        $spInfo->bind_param("i",$spid); $spInfo->execute();
        $sp = $spInfo->get_result()->fetch_assoc(); $spInfo->close();
        $typeId = $sp ? (int)$sp['type_id'] : 0;

        $stageRow = $conn->prepare("SELECT id FROM crm_pipeline_stages WHERE (type_id=? OR type_id IS NULL) AND is_active=1 ORDER BY display_order LIMIT 1");
        $stageRow->bind_param("i",$typeId); $stageRow->execute();
        $st = $stageRow->get_result()->fetch_assoc(); $stageRow->close();
        $stageId = $st ? (int)$st['id'] : null;

        $lines = explode("\n", str_replace("\r\n","\n",$csvData));
        $header = null;
        $inserted = 0; $skipped = 0;

        $stmt = $conn->prepare("INSERT INTO crm_leads (subproject_id,first_name,last_name,email,phone,company,position,country,current_stage_id,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'active',?,NOW(),NOW())");

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $cols = str_getcsv($line);
            if ($header === null) {
                $header = array_map('trim', $cols);
                continue;
            }
            $row = array_combine($header, array_pad($cols, count($header), ''));
            $fname = trim($row['first_name'] ?? '');
            $lname = trim($row['last_name'] ?? '');
            if (!$fname) { $skipped++; continue; }
            $email    = trim($row['email'] ?? '');
            $phone    = trim($row['phone'] ?? '');
            $company  = trim($row['company'] ?? '');
            $position = trim($row['position'] ?? '');
            $country  = trim($row['country'] ?? '');
            $stmt->bind_param("isssssssii",$spid,$fname,$lname,$email,$phone,$company,$position,$country,$stageId,$userId);
            if ($stmt->execute()) $inserted++;
            else $skipped++;
        }
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>"Imported $inserted leads, skipped $skipped",'inserted'=>$inserted,'skipped'=>$skipped]);
        break;
    }

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action]);
        break;
}

$conn->close();
