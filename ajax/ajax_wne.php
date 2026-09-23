<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$conn = getDBConnection();
$userId = $_SESSION['ten_user_id'];
$isSuperAdmin = isAdmin();
$action = $_POST['action'] ?? '';

// Helper function to check project access
function hasProjectAccess($conn, $userId, $projectId, $isSuperAdmin) {
    if ($isSuperAdmin) return true;
    
    $stmt = $conn->prepare("SELECT id FROM ten_wne_project_users WHERE user_id = ? AND project_id = ?");
    $stmt->bind_param("ii", $userId, $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasAccess = $result->num_rows > 0;
    $stmt->close();
    return $hasAccess;
}

// Helper function to log activity
function logWNEActivity($conn, $projectId, $userId, $activityType, $description, $entityType = null, $entityId = null) {
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO ten_wne_activity_log (project_id, user_id, activity_type, activity_description, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssis", $projectId, $userId, $activityType, $description, $entityType, $entityId, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

function sendWNENotification($conn, $projectId, $candidateName, $newStage, $notes, $changedByUserId) {
    $result = $conn->query("SELECT DISTINCT u.email 
                       FROM ten_users u
                       JOIN ten_user_roles ur ON u.id = ur.user_id
                       JOIN ten_roles r ON ur.role_id = r.id
                       WHERE r.role_name = 'Super User' 
                       AND u.status = 'active'");
    $adminEmails = [];
    while ($row = $result->fetch_assoc()) {
        $adminEmails[] = $row['email'];
    }
    $stmt = $conn->prepare("SELECT p.project_name, pc.submitted_by, u.email as submitter_email, u2.full_name as changed_by_name FROM ten_wne_projects p JOIN ten_wne_project_candidates pc ON p.id = pc.project_id LEFT JOIN ten_users u ON pc.submitted_by = u.id LEFT JOIN ten_users u2 ON u2.id = ? WHERE p.id = ? LIMIT 1");
    $stmt->bind_param("ii", $changedByUserId, $projectId);
    $stmt->execute();
    $projectInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$projectInfo) return;
    $subject = "WNE: Candidate Status Updated - " . $candidateName;
    $message = "Candidate: " . $candidateName . "\n";
    $message .= "Project: " . $projectInfo['project_name'] . "\n";
    $message .= "New Stage: " . $newStage . "\n";
    $message .= "Updated by: " . $projectInfo['changed_by_name'] . "\n";
    if ($notes) $message .= "Notes: " . $notes . "\n";
    $message .= "\nView: https://" . $_SERVER['HTTP_HOST'] . "/management/module-wne-project.php?id=" . $projectId;
    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'];
    foreach ($adminEmails as $email) {
        @mail($email, $subject, $message, $headers);
    }
    if ($projectInfo['submitter_email'] && $projectInfo['submitted_by'] != $changedByUserId) {
        @mail($projectInfo['submitter_email'], $subject, $message, $headers);
    }
}

if ($action === 'create_project') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectName = sanitize($_POST['project_name']);
    
    // Auto-generate project code if not provided or use provided one
    if (empty($_POST['project_code'])) {
        $projectCode = 'WNE-' . date('Y-m-d');
        // Make unique by adding sequence number if needed
        $baseCode = $projectCode;
        $sequence = 1;
        $stmt = $conn->prepare("SELECT id FROM ten_wne_projects WHERE project_code = ?");
        $stmt->bind_param("s", $projectCode);
        $stmt->execute();
        while ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            $sequence++;
            $projectCode = $baseCode . '-' . $sequence;
            $stmt = $conn->prepare("SELECT id FROM ten_wne_projects WHERE project_code = ?");
            $stmt->bind_param("s", $projectCode);
            $stmt->execute();
        }
        $stmt->close();
    } else {
        $projectCode = sanitize($_POST['project_code']);
    }
    
    $employerCompany = sanitize($_POST['employer_company'] ?? '');
    $positionTitle = sanitize($_POST['position_title'] ?? '');
    $positionDescription = $_POST['position_description'] ?? '';
    $location = sanitize($_POST['location'] ?? '');
    $salaryRange = sanitize($_POST['salary_range'] ?? '');
    $employmentType = $_POST['employment_type'] ?? 'Full-time';
    $requiredSkills = $_POST['required_skills'] ?? '';
    $numPositions = intval($_POST['num_positions'] ?? 1);
    $startDate = $_POST['start_date'] ?? null;
    $targetCloseDate = $_POST['target_close_date'] ?? null;
    
    if (empty($startDate)) $startDate = null;
    if (empty($targetCloseDate)) $targetCloseDate = null;
    
    $stmt = $conn->prepare("INSERT INTO ten_wne_projects (project_name, project_code, employer_company, position_title, position_description, location, salary_range, employment_type, required_skills, num_positions, start_date, target_close_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssissi", $projectName, $projectCode, $employerCompany, $positionTitle, $positionDescription, $location, $salaryRange, $employmentType, $requiredSkills, $numPositions, $startDate, $targetCloseDate, $userId);
    
    if ($stmt->execute()) {
        $projectId = $stmt->insert_id;
        $stmt->close();
        
        logWNEActivity($conn, $projectId, $userId, 'create_project', "Created project: $projectName", 'project', $projectId);
        
        echo json_encode(['success' => true, 'message' => 'Project created successfully', 'project_id' => $projectId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create project: ' . $conn->error]);
    }
    
    $conn->close();
    exit();
}

if ($action === 'assign_user_to_project') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectId = intval($_POST['project_id']);
    $assignUserId = intval($_POST['user_id']);
    $userRole = $_POST['user_role'];
    $canUpdateStatus = isset($_POST['can_update_status']) ? 1 : 0;
    $canAddCandidates = isset($_POST['can_add_candidates']) ? 1 : 0;
    $canViewAllCandidates = isset($_POST['can_view_all_candidates']) ? 1 : 0;
    $canDownloadCVs = isset($_POST['can_download_cvs']) ? 1 : 0;
    
    $stmt = $conn->prepare("INSERT INTO ten_wne_project_users (project_id, user_id, user_role, can_update_status, can_add_candidates, can_view_all_candidates, can_download_cvs, assigned_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisiiiii", $projectId, $assignUserId, $userRole, $canUpdateStatus, $canAddCandidates, $canViewAllCandidates, $canDownloadCVs, $userId);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        logWNEActivity($conn, $projectId, $userId, 'assign_user', "Assigned user ID $assignUserId with role: $userRole", 'project_user', $assignUserId);
        
        echo json_encode(['success' => true, 'message' => 'User assigned to project successfully']);
    } else {
        if ($conn->errno === 1062) {
            echo json_encode(['success' => false, 'message' => 'User is already assigned to this project']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to assign user']);
        }
    }
    
    $conn->close();
    exit();
}

if ($action === 'add_candidate_to_project') {
    $projectId = intval($_POST['project_id']);
    $candidateId = intval($_POST['candidate_id']);
    $submissionSource = $_POST['submission_source'] ?? 'WNE Internal';
    $expectedSalary = sanitize($_POST['expected_salary'] ?? '');
    $noticePeriod = sanitize($_POST['notice_period'] ?? '');
    $availabilityDate = $_POST['availability_date'] ?? null;
    $internalNotes = $_POST['internal_notes'] ?? '';
    $targetCompany = sanitize($_POST['target_company'] ?? '');
    $consentRequired = isset($_POST['consent_required']) ? 1 : 0;
    
    // Check access
    if (!hasProjectAccess($conn, $userId, $projectId, $isSuperAdmin)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    // Check if user has permission to add candidates
    if (!$isSuperAdmin) {
        $stmt = $conn->prepare("SELECT can_add_candidates FROM ten_wne_project_users WHERE user_id = ? AND project_id = ?");
        $stmt->bind_param("ii", $userId, $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $permissions = $result->fetch_assoc();
        $stmt->close();
        
        if (!$permissions || !$permissions['can_add_candidates']) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to add candidates']);
            exit();
        }
    }
    
    if (empty($availabilityDate)) $availabilityDate = null;
    if (empty($targetCompany)) $targetCompany = null;
    
    // Check if candidate already in project (and is active)
    $stmt = $conn->prepare("SELECT id, is_active FROM ten_wne_project_candidates WHERE project_id = ? AND candidate_id = ?");
    $stmt->bind_param("ii", $projectId, $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        if ($existing['is_active'] == 1) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'This candidate is already in this project']);
            exit();
        } else {
            // Reactivate instead of insert
            $stmt->close();
            $stmt = $conn->prepare("UPDATE ten_wne_project_candidates SET is_active = 1, submitted_by = ?, submission_source = ?, expected_salary = ?, notice_period = ?, availability_date = ?, internal_notes = ?, target_company = ?, consent_required = ?, submitted_at = NOW() WHERE id = ?");
            $existingId = $existing['id'];
            $stmt->bind_param("isssssssi", $userId, $submissionSource, $expectedSalary, $noticePeriod, $availabilityDate, $internalNotes, $targetCompany, $consentRequired, $existingId);
            if ($stmt->execute()) {
                $stmt->close();
                
                // Add to history
                $stmt = $conn->prepare("INSERT INTO ten_wne_candidate_history (project_candidate_id, to_stage, changed_by, change_notes) VALUES (?, 'Submitted', ?, 'Candidate re-added to project')");
                $stmt->bind_param("ii", $existingId, $userId);
                $stmt->execute();
                $stmt->close();
                
                logWNEActivity($conn, $projectId, $userId, 'add_candidate', "Re-added candidate to project", 'project_candidate', $existingId);
                
                echo json_encode(['success' => true, 'message' => 'Candidate re-added to project successfully']);
                exit();
            }
        }
    }
    $stmt->close();
    
    $stmt = $conn->prepare("INSERT INTO ten_wne_project_candidates (project_id, candidate_id, submitted_by, submission_source, expected_salary, notice_period, availability_date, internal_notes, target_company, consent_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiissssssi", $projectId, $candidateId, $userId, $submissionSource, $expectedSalary, $noticePeriod, $availabilityDate, $internalNotes, $targetCompany, $consentRequired);
    
    if ($stmt->execute()) {
        $projectCandidateId = $stmt->insert_id;
        $stmt->close();
        
        // Add to history
        $stmt = $conn->prepare("INSERT INTO ten_wne_candidate_history (project_candidate_id, to_stage, changed_by, change_notes) VALUES (?, 'Submitted', ?, 'Candidate added to project')");
        $stmt->bind_param("ii", $projectCandidateId, $userId);
        $stmt->execute();
        $stmt->close();
        
        logWNEActivity($conn, $projectId, $userId, 'add_candidate', "Added candidate to project", 'project_candidate', $projectCandidateId);
        
        echo json_encode(['success' => true, 'message' => 'Candidate added to project successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add candidate']);
    }
    
    $conn->close();
    exit();
}

if ($action === 'update_candidate_stage') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    $newStage = $_POST['new_stage'];
    $changeNotes = $_POST['change_notes'] ?? '';
    $interviewType = $_POST['interview_type'] ?? null;
    $interviewDate = $_POST['interview_date'] ?? null;
    $interviewerName = sanitize($_POST['interviewer_name'] ?? '');
    $interviewFeedback = $_POST['interview_feedback'] ?? '';
    $clientFeedback = $_POST['client_feedback'] ?? '';
    $rejectionReason = $_POST['rejection_reason'] ?? '';
    
    // Get project ID and check access
    $stmt = $conn->prepare("SELECT project_id, current_stage FROM ten_wne_project_candidates WHERE id = ?");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit();
    }
    
    $projectId = $candidate['project_id'];
    $oldStage = $candidate['current_stage'];
    
    if (!hasProjectAccess($conn, $userId, $projectId, $isSuperAdmin)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    // Check if user has permission to update status
    if (!$isSuperAdmin) {
        $stmt = $conn->prepare("SELECT can_update_status FROM ten_wne_project_users WHERE user_id = ? AND project_id = ?");
        $stmt->bind_param("ii", $userId, $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $permissions = $result->fetch_assoc();
        $stmt->close();
        
        if (!$permissions || !$permissions['can_update_status']) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to update candidate status']);
            exit();
        }
    }
    
    // Update candidate stage
    $stmt = $conn->prepare("UPDATE ten_wne_project_candidates SET current_stage = ?, client_feedback = ?, rejection_reason = ? WHERE id = ?");
    $stmt->bind_param("sssi", $newStage, $clientFeedback, $rejectionReason, $projectCandidateId);
    $stmt->execute();
    $stmt->close();
    
    // Add to history
    if (empty($interviewDate)) $interviewDate = null;
    $stmt = $conn->prepare("INSERT INTO ten_wne_candidate_history (project_candidate_id, from_stage, to_stage, changed_by, change_notes, interview_type, interview_date, interviewer_name, interview_feedback) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississsss", $projectCandidateId, $oldStage, $newStage, $userId, $changeNotes, $interviewType, $interviewDate, $interviewerName, $interviewFeedback);
    $stmt->execute();
    $stmt->close();
    
    logWNEActivity($conn, $projectId, $userId, 'update_stage', "Updated candidate stage from $oldStage to $newStage", 'project_candidate', $projectCandidateId);
    $stmt = $conn->prepare("SELECT c.full_name FROM ten_cv_candidates c JOIN ten_wne_project_candidates pc ON c.id = pc.candidate_id WHERE pc.id = ?");    $stmt->bind_param("i", $projectCandidateId);    $stmt->execute();    $candidateData = $stmt->get_result()->fetch_assoc();    $stmt->close();    if ($candidateData) sendWNENotification($conn, $projectId, $candidateData['full_name'], $newStage, $changeNotes, $userId);
    
    echo json_encode(['success' => true, 'message' => 'Candidate stage updated successfully']);
    
    $conn->close();
    exit();
}

if ($action === 'get_project_candidates') {
    $projectId = intval($_POST['project_id']);
    
    if (!hasProjectAccess($conn, $userId, $projectId, $isSuperAdmin)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT pc.*, c.full_name, c.email, c.phone, c.cv_file_path,
                           u.full_name as submitted_by_name
                           FROM ten_wne_project_candidates pc
                           JOIN ten_cv_candidates c ON pc.candidate_id = c.id
                           JOIN ten_users u ON pc.submitted_by = u.id
                           WHERE pc.project_id = ? AND pc.is_active = 1
                           ORDER BY pc.submitted_at DESC");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $candidates[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'candidates' => $candidates]);
    
    $conn->close();
    exit();
}

if ($action === 'get_candidate_history') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    
    // Get project ID for access check
    $stmt = $conn->prepare("SELECT project_id FROM ten_wne_project_candidates WHERE id = ?");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit();
    }
    
    if (!hasProjectAccess($conn, $userId, $candidate['project_id'], $isSuperAdmin)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT h.*, u.full_name as changed_by_name
                           FROM ten_wne_candidate_history h
                           JOIN ten_users u ON h.changed_by = u.id
                           WHERE h.project_candidate_id = ?
                           ORDER BY h.created_at DESC");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'history' => $history]);
    
    $conn->close();
    exit();
}

if ($action === 'create_contract') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectId = intval($_POST['project_id']);
    $contractNumber = sanitize($_POST['contract_number']);
    $contractType = $_POST['contract_type'];
    $clientCompany = sanitize($_POST['client_company']);
    $contractValue = floatval($_POST['contract_value'] ?? 0);
    $currency = sanitize($_POST['currency'] ?? 'EUR');
    $feePercentage = floatval($_POST['fee_percentage'] ?? 0);
    $paymentTerms = $_POST['payment_terms'] ?? '';
    $startDate = $_POST['start_date'] ?? null;
    $endDate = $_POST['end_date'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if (empty($startDate)) $startDate = null;
    if (empty($endDate)) $endDate = null;
    
    $stmt = $conn->prepare("INSERT INTO ten_wne_contracts (project_id, contract_number, contract_type, client_company, contract_value, currency, fee_percentage, payment_terms, start_date, end_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssdsdssssi", $projectId, $contractNumber, $contractType, $clientCompany, $contractValue, $currency, $feePercentage, $paymentTerms, $startDate, $endDate, $notes, $userId);
    
    if ($stmt->execute()) {
        $contractId = $stmt->insert_id;
        $stmt->close();
        
        logWNEActivity($conn, $projectId, $userId, 'create_contract', "Created contract: $contractNumber", 'contract', $contractId);
        
        echo json_encode(['success' => true, 'message' => 'Contract created successfully', 'contract_id' => $contractId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create contract']);
    }
    
    $conn->close();
    exit();
}

if ($action === 'get_available_candidates') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectId = intval($_POST['project_id']);
    
    // Get candidates not already ACTIVE in this project (allows re-adding removed candidates)
    $stmt = $conn->prepare("SELECT c.id, c.full_name, c.email, c.phone, c.summary
                           FROM ten_cv_candidates c
                           WHERE c.ai_parsed = 1
                           AND c.id NOT IN (SELECT candidate_id FROM ten_wne_project_candidates WHERE project_id = ? AND is_active = 1)
                           ORDER BY c.parse_date DESC");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $candidates[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'candidates' => $candidates]);
    
    $conn->close();
    exit();
}

if ($action === 'get_all_users') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $result = $conn->query("SELECT id, full_name as name, email, username FROM ten_users WHERE status = 'active' ORDER BY full_name");
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

if ($action === 'get_all_projects') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    try {
        $result = $conn->query("SELECT p.*, u.full_name as creator_name,
                             (SELECT COUNT(*) FROM ten_wne_project_candidates WHERE project_id = p.id) as candidate_count,
                             (SELECT COUNT(*) FROM ten_wne_project_candidates WHERE project_id = p.id AND current_stage = 'Hired') as hired_count
                             FROM ten_wne_projects p
                             LEFT JOIN ten_users u ON p.created_by = u.id
                             ORDER BY p.created_at DESC");
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database query failed: ' . $conn->error]);
            exit();
        }
        
        $projects = [];
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
        
        echo json_encode(['success' => true, 'projects' => $projects]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    exit();
}

if ($action === 'get_project_users') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $conn->query("SELECT pu.*, u.full_name as user_name, u.email as user_email, p.project_name
                         FROM ten_wne_project_users pu
                         JOIN ten_users u ON pu.user_id = u.id
                         JOIN ten_wne_projects p ON pu.project_id = p.id
                         ORDER BY p.project_name, u.full_name");
    $users = [];
    while ($row = $stmt->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
    
    $conn->close();
    exit();
}

if ($action === 'get_all_contracts') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $conn->query("SELECT c.*, p.project_name
                         FROM ten_wne_contracts c
                         JOIN ten_wne_projects p ON c.project_id = p.id
                         ORDER BY c.created_at DESC");
    $contracts = [];
    while ($row = $stmt->fetch_assoc()) {
        $contracts[] = $row;
    }
    
    echo json_encode(['success' => true, 'contracts' => $contracts]);
    
    $conn->close();
    exit();
}

if ($action === 'get_all_candidates_overview') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $conn->query("SELECT pc.*, c.full_name, c.email, p.project_name
                         FROM ten_wne_project_candidates pc
                         JOIN ten_cv_candidates c ON pc.candidate_id = c.id
                         JOIN ten_wne_projects p ON pc.project_id = p.id
                         WHERE pc.is_active = 1
                         ORDER BY pc.submitted_at DESC");
    $candidates = [];
    while ($row = $stmt->fetch_assoc()) {
        $candidates[] = $row;
    }
    
    echo json_encode(['success' => true, 'candidates' => $candidates]);
    
    $conn->close();
    exit();
}

if ($action === 'remove_user_from_project') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectUserId = intval($_POST['project_user_id']);
    
    $stmt = $conn->prepare("DELETE FROM ten_wne_project_users WHERE id = ?");
    $stmt->bind_param("i", $projectUserId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'User removed from project']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove user']);
    }
    
    $stmt->close();
    $conn->close();
    exit();
}

if ($action === 'remove_user_from_project') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $projectUserId = intval($_POST['project_user_id']);
    
    $stmt = $conn->prepare("DELETE FROM ten_wne_project_users WHERE id = ?");
    $stmt->bind_param("i", $projectUserId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'User removed from project']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove user']);
    }
    
    $stmt->close();
    $conn->close();
    exit();
}

if ($action === 'remove_candidate_from_project') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    
    // Get project ID for access check
    $stmt = $conn->prepare("SELECT project_id FROM ten_wne_project_candidates WHERE id = ?");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit();
    }
    
    $projectId = $candidate['project_id'];
    
    if (!hasProjectAccess($conn, $userId, $projectId, $isSuperAdmin)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    // Soft delete by setting is_active to 0
    $stmt = $conn->prepare("UPDATE ten_wne_project_candidates SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $projectCandidateId);
    
    if ($stmt->execute()) {
        logWNEActivity($conn, $projectId, $userId, 'remove_candidate', "Removed candidate from project", 'project_candidate', $projectCandidateId);
        echo json_encode(['success' => true, 'message' => 'Candidate removed from project']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove candidate']);
    }
    
    $stmt->close();
    exit();
}

if ($action === 'delete_project') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized - Only super admin can delete projects']);
        exit();
    }
    
    $projectId = intval($_POST['project_id']);
    
    // Get project details first
    $stmt = $conn->prepare("SELECT project_name FROM ten_wne_projects WHERE id = ?");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();
    
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }
    
    // Delete project (cascade will handle related records)
    $stmt = $conn->prepare("DELETE FROM ten_wne_projects WHERE id = ?");
    $stmt->bind_param("i", $projectId);
    
    if ($stmt->execute()) {
        logActivity('delete_project', 'wne_project', $projectId, "Deleted project: " . $project['project_name']);
        echo json_encode(['success' => true, 'message' => 'Project deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete project']);
    }
    
    $stmt->close();
    exit();
}


// CONSENT MANAGEMENT ENDPOINTS

// Request consent from candidate
if ($action === 'request_candidate_consent') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    $targetCompany = sanitize($_POST['target_company']);
    $requestMessage = sanitize($_POST['request_message']);
    
    // Get project and candidate info
    $stmt = $conn->prepare("SELECT pc.*, c.email, c.full_name, p.project_name 
                           FROM ten_wne_project_candidates pc
                           JOIN ten_cv_candidates c ON pc.candidate_id = c.id
                           JOIN ten_wne_projects p ON pc.project_id = p.id
                           WHERE pc.id = ?");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit();
    }
    
    // Update project_candidates table
    $stmt = $conn->prepare("UPDATE ten_wne_project_candidates 
                           SET target_company = ?, consent_required = 1, consent_status = 'pending', 
                               consent_requested_at = NOW()
                           WHERE id = ?");
    $stmt->bind_param("si", $targetCompany, $projectCandidateId);
    $stmt->execute();
    $stmt->close();
    
    // Create consent request record
    $stmt = $conn->prepare("INSERT INTO ten_wne_consent_requests 
                           (project_candidate_id, requested_by, target_company, request_message) 
                           VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $projectCandidateId, $userId, $targetCompany, $requestMessage);
    $stmt->execute();
    $stmt->close();
    
    // Send email to candidate
    $subject = "Consent Required: CV Submission to " . $targetCompany;
    $emailBody = "Dear " . $data['full_name'] . ",

";
    $emailBody .= "We would like to submit your CV to " . $targetCompany . " for the following opportunity:

";
    $emailBody .= "Project: " . $data['project_name'] . "

";
    $emailBody .= "Message from recruiter:
" . $requestMessage . "

";
    $emailBody .= "Please respond to this email or contact us at your earliest convenience.

";
    $emailBody .= "We need your consent within 48 hours.

";
    $emailBody .= "Best regards,
Recruitment Team";
    
    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'];
    @mail($data['email'], $subject, $emailBody, $headers);
    
    // Log activity
    logWNEActivity($conn, $data['project_id'], $userId, 'consent_request', 
                   "Requested consent from " . $data['full_name'] . " for " . $targetCompany, 
                   'candidate', $projectCandidateId);
    
    echo json_encode(['success' => true, 'message' => 'Consent request sent to candidate']);
    exit();
}

// Get consent requests for a project candidate
if ($action === 'get_consent_requests') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    
    $stmt = $conn->prepare("SELECT cr.*, u.full_name as requested_by_name
                           FROM ten_wne_consent_requests cr
                           LEFT JOIN ten_users u ON cr.requested_by = u.id
                           WHERE cr.project_candidate_id = ?
                           ORDER BY cr.requested_at DESC");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit();
}

// Update consent status (when recruiter gets response from candidate)
if ($action === 'update_consent_status') {
    $projectCandidateId = intval($_POST['project_candidate_id']);
    $consentStatus = $_POST['consent_status']; // 'granted' or 'denied'
    $notes = sanitize($_POST['notes'] ?? '');
    
    // Update project_candidates table
    $stmt = $conn->prepare("UPDATE ten_wne_project_candidates 
                           SET consent_status = ?, consent_responded_at = NOW(), consent_notes = ?
                           WHERE id = ?");
    $stmt->bind_param("ssi", $consentStatus, $notes, $projectCandidateId);
    $stmt->execute();
    $stmt->close();
    
    // Update latest consent request - get the most recent one first
    $stmt = $conn->prepare("SELECT id FROM ten_wne_consent_requests 
                           WHERE project_candidate_id = ? 
                           ORDER BY requested_at DESC LIMIT 1");
    $stmt->bind_param("i", $projectCandidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $latestRequest = $result->fetch_assoc();
    $stmt->close();
    
    if ($latestRequest) {
        $stmt = $conn->prepare("UPDATE ten_wne_consent_requests 
                               SET consent_status = ?, candidate_response = ?, responded_at = NOW()
                               WHERE id = ?");
        $stmt->bind_param("ssi", $consentStatus, $notes, $latestRequest['id']);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => true, 'message' => 'Consent status updated']);
    exit();
}

if ($action === 'get_cv_full_data') {
    $candidateId = intval($_POST['candidate_id']);
    
    // Query with correct column names matching actual database schema
    $stmt = $conn->prepare("SELECT c.*, 
                           (SELECT GROUP_CONCAT(CONCAT(company_name, '|', job_title, '|', start_date, '|', IFNULL(end_date, 'Present'), '|', IFNULL(description, '')) SEPARATOR '|||') 
                            FROM ten_cv_work_experience WHERE candidate_id = c.id ORDER BY start_date DESC) as work_history,
                           (SELECT GROUP_CONCAT(CONCAT(institution_name, '|', degree_type, '|', field_of_study, '|', IFNULL(YEAR(end_date), '')) SEPARATOR '|||') 
                            FROM ten_cv_education WHERE candidate_id = c.id ORDER BY end_date DESC) as education,
                           (SELECT GROUP_CONCAT(skill_name SEPARATOR ', ') 
                            FROM ten_cv_skills WHERE candidate_id = c.id) as skills_list
                           FROM ten_cv_candidates c WHERE c.id = ?");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();
    
    if ($candidate) {
        // Parse work history
        $work = [];
        if ($candidate['work_history']) {
            foreach (explode('|||', $candidate['work_history']) as $w) {
                $parts = explode('|', $w);
                if (count($parts) >= 4) {
                    $work[] = [
                        'company' => $parts[0],
                        'position' => $parts[1],
                        'start_date' => $parts[2],
                        'end_date' => $parts[3],
                        'description' => $parts[4] ?? ''
                    ];
                }
            }
        }
        $candidate['work_history_parsed'] = $work;
        
        // Parse education
        $edu = [];
        if ($candidate['education']) {
            foreach (explode('|||', $candidate['education']) as $e) {
                $parts = explode('|', $e);
                if (count($parts) >= 3) {
                    $edu[] = [
                        'institution' => $parts[0],
                        'degree' => $parts[1],
                        'field' => $parts[2],
                        'year' => $parts[3] ?? ''
                    ];
                }
            }
        }
        $candidate['education_parsed'] = $edu;
        
        echo json_encode(['success' => true, 'candidate' => $candidate]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
    }
    exit();
}

if ($action === 'get_reports_data') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    $reports = [];
    $result = $conn->query("SELECT u.full_name, u.email, COUNT(DISTINCT al.id) as total_actions, COUNT(DISTINCT CASE WHEN al.activity_type = 'add_candidate' THEN al.id END) as candidates_added, COUNT(DISTINCT CASE WHEN al.activity_type = 'update_status' THEN al.id END) as status_updates, COUNT(DISTINCT CASE WHEN al.activity_type = 'create_project' THEN al.id END) as projects_created FROM ten_users u LEFT JOIN ten_wne_activity_log al ON u.id = al.user_id WHERE u.active = 1 GROUP BY u.id ORDER BY total_actions DESC");
    $reports['user_actions'] = [];
    while ($row = $result->fetch_assoc()) $reports['user_actions'][] = $row;
    $result = $conn->query("SELECT p.project_name, p.project_code, COUNT(DISTINCT pc.id) as total_candidates, COUNT(DISTINCT CASE WHEN pc.current_stage = 'Hired' THEN pc.id END) as hired_count, COUNT(DISTINCT al.id) as total_activities, MAX(al.created_at) as last_activity FROM ten_wne_projects p LEFT JOIN ten_wne_project_candidates pc ON p.id = pc.project_id LEFT JOIN ten_wne_activity_log al ON p.id = al.project_id GROUP BY p.id ORDER BY total_activities DESC");
    $reports['project_activity'] = [];
    while ($row = $result->fetch_assoc()) $reports['project_activity'][] = $row;
    $result = $conn->query("SELECT current_stage, COUNT(*) as count FROM ten_wne_project_candidates WHERE is_active = 1 GROUP BY current_stage ORDER BY count DESC");
    $reports['stage_distribution'] = [];
    while ($row = $result->fetch_assoc()) $reports['stage_distribution'][] = $row;
    $result = $conn->query("SELECT al.*, u.full_name, p.project_name FROM ten_wne_activity_log al LEFT JOIN ten_users u ON al.user_id = u.id LEFT JOIN ten_wne_projects p ON al.project_id = p.id ORDER BY al.created_at DESC LIMIT 50");
    $reports['recent_activities'] = [];
    while ($row = $result->fetch_assoc()) $reports['recent_activities'][] = $row;
    echo json_encode(['success' => true, 'reports' => $reports]);
    exit();
}

// CANDIDATE MODULE ENDPOINTS

// Search candidates with filters
if ($action === 'search_candidates') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $page = intval($_POST['page'] ?? 1);
    $perPage = intval($_POST['per_page'] ?? 20);
    $offset = ($page - 1) * $perPage;
    
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if (!empty($_POST['name'])) {
        $where[] = "c.full_name LIKE ?";
        $params[] = "%" . $_POST['name'] . "%";
        $types .= "s";
    }
    
    if (!empty($_POST['email'])) {
        $where[] = "c.email LIKE ?";
        $params[] = "%" . $_POST['email'] . "%";
        $types .= "s";
    }
    
    if (!empty($_POST['skills'])) {
        $where[] = "EXISTS (SELECT 1 FROM ten_cv_skills s WHERE s.candidate_id = c.id AND s.skill_name LIKE ?)";
        $params[] = "%" . $_POST['skills'] . "%";
        $types .= "s";
    }
    
    if (!empty($_POST['location'])) {
        $where[] = "(c.city LIKE ? OR c.country LIKE ?)";
        $params[] = "%" . $_POST['location'] . "%";
        $params[] = "%" . $_POST['location'] . "%";
        $types .= "ss";
    }
    
    if (!empty($_POST['nationality'])) {
        $where[] = "c.nationality LIKE ?";
        $params[] = "%" . $_POST['nationality'] . "%";
        $types .= "s";
    }
    
    if (!empty($_POST['language'])) {
        $where[] = "EXISTS (SELECT 1 FROM ten_cv_languages l WHERE l.candidate_id = c.id AND l.language_name LIKE ?)";
        $params[] = "%" . $_POST['language'] . "%";
        $types .= "s";
    }
    
    if (!empty($_POST['experience'])) {
        $exp = $_POST['experience'];
        if ($exp === '0-2') {
            $where[] = "TIMESTAMPDIFF(YEAR, (SELECT MIN(start_date) FROM ten_cv_work_experience WHERE candidate_id = c.id), NOW()) <= 2";
        } elseif ($exp === '3-5') {
            $where[] = "TIMESTAMPDIFF(YEAR, (SELECT MIN(start_date) FROM ten_cv_work_experience WHERE candidate_id = c.id), NOW()) BETWEEN 3 AND 5";
        } elseif ($exp === '6-10') {
            $where[] = "TIMESTAMPDIFF(YEAR, (SELECT MIN(start_date) FROM ten_cv_work_experience WHERE candidate_id = c.id), NOW()) BETWEEN 6 AND 10";
        } elseif ($exp === '10+') {
            $where[] = "TIMESTAMPDIFF(YEAR, (SELECT MIN(start_date) FROM ten_cv_work_experience WHERE candidate_id = c.id), NOW()) > 10";
        }
    }
    
    if (!empty($_POST['project'])) {
        $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND pc.project_id = ? AND pc.is_active = 1)";
        $params[] = intval($_POST['project']);
        $types .= "i";
    }
    
    if (!empty($_POST['stage'])) {
        $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND pc.current_stage = ? AND pc.is_active = 1)";
        $params[] = $_POST['stage'];
        $types .= "s";
    }
    
    if (!empty($_POST['date_range'])) {
        $range = $_POST['date_range'];
        if ($range === 'today') {
            $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND DATE(pc.submitted_at) = CURDATE())";
        } elseif ($range === 'yesterday') {
            $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND DATE(pc.submitted_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY))";
        } elseif ($range === 'last7days') {
            $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND pc.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))";
        } elseif ($range === 'last30days') {
            $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND pc.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))";
        } elseif ($range === 'custom') {
            if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
                $where[] = "EXISTS (SELECT 1 FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND DATE(pc.submitted_at) BETWEEN ? AND ?)";
                $params[] = $_POST['date_from'];
                $params[] = $_POST['date_to'];
                $types .= "ss";
            }
        }
    }
    
    $whereClause = implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(DISTINCT c.id) as total FROM ten_cv_candidates c WHERE " . $whereClause;
    if (!empty($params)) {
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $total = $conn->query($countSql)->fetch_assoc()['total'];
    }
    
    $sql = "SELECT c.*, 
            (SELECT COUNT(*) FROM ten_wne_project_candidates pc WHERE pc.candidate_id = c.id AND pc.is_active = 1) as project_count
            FROM ten_cv_candidates c 
            WHERE " . $whereClause . "
            ORDER BY c.parse_date DESC 
            LIMIT ? OFFSET ?";
    
    $params[] = $perPage;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $candidates[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'candidates' => $candidates,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
    exit();
}

if ($action === 'get_candidate_stats') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $candidateId = intval($_POST['candidate_id']);
    
    $stmt = $conn->prepare("SELECT * FROM ten_cv_candidates WHERE id = ?");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $candidate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT 
        COUNT(*) as total_projects,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_projects,
        COUNT(CASE WHEN current_stage = 'Hired' THEN 1 END) as hired_count,
        COUNT(CASE WHEN current_stage = 'Rejected' THEN 1 END) as rejected_count
        FROM ten_wne_project_candidates 
        WHERE candidate_id = ?");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get all projects with their complete history
    $stmt = $conn->prepare("SELECT pc.*, p.project_name, p.project_code, u.full_name as submitted_by_name
        FROM ten_wne_project_candidates pc
        JOIN ten_wne_projects p ON pc.project_id = p.id
        LEFT JOIN ten_users u ON pc.submitted_by = u.id
        WHERE pc.candidate_id = ?
        ORDER BY pc.submitted_at DESC");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        // Get full history for this project assignment
        $historyStmt = $conn->prepare("SELECT h.*, u.full_name as changed_by_name
            FROM ten_wne_candidate_history h
            JOIN ten_users u ON h.changed_by = u.id
            WHERE h.project_candidate_id = ?
            ORDER BY h.created_at ASC");
        $historyStmt->bind_param("i", $row['id']);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        
        $history = [];
        while ($historyRow = $historyResult->fetch_assoc()) {
            $history[] = $historyRow;
        }
        $historyStmt->close();
        
        $row['history'] = $history;
        $projects[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'candidate' => $candidate,
        'stats' => $stats,
        'projects' => $projects
    ]);
    exit();
}

// Email candidate report
if ($action === 'email_candidate_report') {
    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $recipients = json_decode($_POST['recipients'], true);
    $reportHtml = $_POST['report_html'];
    
    if (empty($recipients)) {
        echo json_encode(['success' => false, 'message' => 'No recipients selected']);
        exit();
    }
    
    $placeholders = implode(',', array_fill(0, count($recipients), '?'));
    $stmt = $conn->prepare("SELECT email, full_name FROM ten_users WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($recipients));
    $stmt->bind_param($types, ...$recipients);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $emails = [];
    while ($row = $result->fetch_assoc()) {
        $emails[] = $row;
    }
    $stmt->close();
    
    $subject = "Candidate Report - " . date('Y-m-d');
    $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $body = "<html><head><style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #f9fafb; }
    </style></head><body>" . $reportHtml . "</body></html>";
    
    $success = true;
    foreach ($emails as $recipient) {
        if (!@mail($recipient['email'], $subject, $body, $headers)) {
            $success = false;
        }
    }
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Report emailed successfully' : 'Failed to send some emails'
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);