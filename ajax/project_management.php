<?php
/**
 * Project Management AJAX Handler
 * Handles all project, task, milestone, and deliverable operations
 */

require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$conn = getDBConnection();
$userId = $_SESSION['ten_user_id'];
$isAdmin = isAdmin();

try {
    switch ($action) {
        
        // ==========================================
        // PROJECT OPERATIONS
        // ==========================================
        
        case 'create_project':
            $projectName = sanitize($_POST['project_name'] ?? '');
            $projectKey = strtoupper(sanitize($_POST['project_key'] ?? ''));
            $description = sanitize($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $priority = $_POST['priority'] ?? 'medium';
            $startDate = $_POST['start_date'] ?? null;
            $endDate = $_POST['end_date'] ?? null;
            $budget = $_POST['budget'] ?? null;
            $members = $_POST['members'] ?? [];
            
            // Validate project key
            if (!preg_match('/^[A-Z]{2,10}$/', $projectKey)) {
                echo json_encode(['success' => false, 'error' => 'Invalid project key format']);
                exit;
            }
            
            // Check if project key exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_projects WHERE project_key = ?");
            $checkStmt->bind_param("s", $projectKey);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'error' => 'Project key already exists']);
                exit;
            }
            $checkStmt->close();
            
            // Create project
            $stmt = $conn->prepare("INSERT INTO ten_projects 
                (project_name, project_key, description, status, priority, start_date, end_date, budget, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssdi", $projectName, $projectKey, $description, $status, $priority, 
                $startDate, $endDate, $budget, $userId);
            
            if ($stmt->execute()) {
                $projectId = $stmt->insert_id;
                $stmt->close();
                
                // Add creator as owner
                $ownerStmt = $conn->prepare("INSERT INTO ten_project_members (project_id, user_id, role, assigned_by) 
                    VALUES (?, ?, 'owner', ?)");
                $ownerStmt->bind_param("iii", $projectId, $userId, $userId);
                $ownerStmt->execute();
                $ownerStmt->close();
                
                // Add team members
                if (!empty($members)) {
                    $memberStmt = $conn->prepare("INSERT INTO ten_project_members (project_id, user_id, role, assigned_by) 
                        VALUES (?, ?, 'member', ?)");
                    
                    foreach ($members as $memberId) {
                        if ($memberId != $userId) { // Don't duplicate creator
                            $memberStmt->bind_param("iii", $projectId, $memberId, $userId);
                            $memberStmt->execute();
                            
                            // Send notification email
                            sendProjectAssignmentEmail($conn, $projectId, $memberId, $projectName);
                        }
                    }
                    $memberStmt->close();
                }
                
                // Log activity
                logProjectActivity($conn, $projectId, $userId, 'project_created', 'project', $projectId, 
                    "Created project: $projectName");
                logActivity('project_created', 'project', $projectId, "Created project: $projectName");
                
                echo json_encode(['success' => true, 'project_id' => $projectId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create project']);
            }
            break;
            
        case 'update_project':
            $projectId = intval($_POST['project_id'] ?? 0);
            $projectName = sanitize($_POST['project_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $priority = $_POST['priority'] ?? 'medium';
            $startDate = $_POST['start_date'] ?? null;
            $endDate = $_POST['end_date'] ?? null;
            $budget = $_POST['budget'] ?? null;
            $members = $_POST['members'] ?? [];
            
            // Check permission
            if (!canManageProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("UPDATE ten_projects 
                SET project_name = ?, description = ?, status = ?, priority = ?, 
                    start_date = ?, end_date = ?, budget = ? 
                WHERE id = ?");
            $stmt->bind_param("ssssssdi", $projectName, $description, $status, $priority, 
                $startDate, $endDate, $budget, $projectId);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // Update members
                if (!empty($members)) {
                    // Get current members
                    $currentMembers = [];
                    $memberQuery = $conn->query("SELECT user_id FROM ten_project_members WHERE project_id = $projectId AND role != 'owner'");
                    while ($row = $memberQuery->fetch_assoc()) {
                        $currentMembers[] = $row['user_id'];
                    }
                    
                    // Remove members not in new list
                    $toRemove = array_diff($currentMembers, $members);
                    if (!empty($toRemove)) {
                        $removeStmt = $conn->prepare("DELETE FROM ten_project_members 
                            WHERE project_id = ? AND user_id = ? AND role != 'owner'");
                        foreach ($toRemove as $memberId) {
                            $removeStmt->bind_param("ii", $projectId, $memberId);
                            $removeStmt->execute();
                        }
                        $removeStmt->close();
                    }
                    
                    // Add new members
                    $toAdd = array_diff($members, $currentMembers);
                    if (!empty($toAdd)) {
                        $addStmt = $conn->prepare("INSERT IGNORE INTO ten_project_members 
                            (project_id, user_id, role, assigned_by) VALUES (?, ?, 'member', ?)");
                        foreach ($toAdd as $memberId) {
                            $addStmt->bind_param("iii", $projectId, $memberId, $userId);
                            $addStmt->execute();
                            
                            sendProjectAssignmentEmail($conn, $projectId, $memberId, $projectName);
                        }
                        $addStmt->close();
                    }
                }
                
                logProjectActivity($conn, $projectId, $userId, 'project_updated', 'project', $projectId, 
                    "Updated project: $projectName");
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update project']);
            }
            break;
            
        case 'delete_project':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!canManageProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("DELETE FROM ten_projects WHERE id = ?");
            $stmt->bind_param("i", $projectId);
            
            if ($stmt->execute()) {
                logActivity('project_deleted', 'project', $projectId, "Deleted project ID: $projectId");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete project']);
            }
            $stmt->close();
            break;
            
        case 'get_project':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!canViewProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT p.*, 
                (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id) as task_count,
                (SELECT COUNT(*) FROM ten_project_tasks WHERE project_id = p.id AND status = 'completed') as completed_tasks,
                (SELECT COUNT(*) FROM ten_project_members WHERE project_id = p.id) as member_count,
                (SELECT GROUP_CONCAT(user_id) FROM ten_project_members WHERE project_id = p.id AND role != 'owner') as members
                FROM ten_projects p WHERE p.id = ?");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($project) {
                $project['members'] = $project['members'] ? explode(',', $project['members']) : [];
                echo json_encode(['success' => true, 'project' => $project]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Project not found']);
            }
            break;
            
        // ==========================================
        // TASK OPERATIONS
        // ==========================================
        
        case 'create_task':
            $projectId = intval($_POST['project_id'] ?? 0);
            $taskName = sanitize($_POST['task_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'todo';
            $priority = $_POST['priority'] ?? 'medium';
            $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
            $milestoneId = intval($_POST['milestone_id'] ?? 0) ?: null;
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $estimatedHours = $_POST['estimated_hours'] ?? null;
            $progress = intval($_POST['progress'] ?? 0);
            
            if (!canManageProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("INSERT INTO ten_project_tasks 
                (project_id, task_name, description, status, priority, assigned_to, milestone_id, 
                 start_date, due_date, estimated_hours, progress, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssiissdii", $projectId, $taskName, $description, $status, $priority, 
                $assignedTo, $milestoneId, $startDate, $dueDate, $estimatedHours, $progress, $userId);
            
            if ($stmt->execute()) {
                $taskId = $stmt->insert_id;
                $stmt->close();
                
                // Send notification to assigned user
                if ($assignedTo) {
                    sendTaskAssignmentEmail($conn, $taskId, $assignedTo, $taskName);
                    createNotification($conn, $assignedTo, $projectId, 'task-assigned', 'task', $taskId,
                        'New Task Assigned', "You have been assigned to: $taskName");
                }
                
                logProjectActivity($conn, $projectId, $userId, 'task_created', 'task', $taskId, 
                    "Created task: $taskName");
                
                echo json_encode(['success' => true, 'task_id' => $taskId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create task']);
            }
            break;
            
        case 'update_task':
            $taskId = intval($_POST['task_id'] ?? 0);
            $taskName = sanitize($_POST['task_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'todo';
            $priority = $_POST['priority'] ?? 'medium';
            $assignedTo = intval($_POST['assigned_to'] ?? 0) ?: null;
            $milestoneId = intval($_POST['milestone_id'] ?? 0) ?: null;
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $estimatedHours = $_POST['estimated_hours'] ?? null;
            $actualHours = $_POST['actual_hours'] ?? null;
            $progress = intval($_POST['progress'] ?? 0);
            
            // Get task info for permission check
            $taskQuery = $conn->query("SELECT project_id, assigned_to FROM ten_project_tasks WHERE id = $taskId");
            $taskInfo = $taskQuery->fetch_assoc();
            
            if (!$taskInfo || !canManageProject($conn, $userId, $taskInfo['project_id'], $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Mark as completed if status changed to completed
            $completedDate = ($status === 'completed') ? 'NOW()' : 'NULL';
            
            $stmt = $conn->prepare("UPDATE ten_project_tasks 
                SET task_name = ?, description = ?, status = ?, priority = ?, assigned_to = ?, 
                    milestone_id = ?, start_date = ?, due_date = ?, estimated_hours = ?, 
                    actual_hours = ?, progress = ?, completed_date = $completedDate 
                WHERE id = ?");
            $stmt->bind_param("sssssissddii", $taskName, $description, $status, $priority, $assignedTo, 
                $milestoneId, $startDate, $dueDate, $estimatedHours, $actualHours, $progress, $taskId);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // Notify if assignee changed
                if ($assignedTo && $assignedTo != $taskInfo['assigned_to']) {
                    sendTaskAssignmentEmail($conn, $taskId, $assignedTo, $taskName);
                    createNotification($conn, $assignedTo, $taskInfo['project_id'], 'task-assigned', 'task', $taskId,
                        'Task Reassigned', "You have been assigned to: $taskName");
                }
                
                logProjectActivity($conn, $taskInfo['project_id'], $userId, 'task_updated', 'task', $taskId, 
                    "Updated task: $taskName");
                
                // Update project progress
                updateProjectProgress($conn, $taskInfo['project_id']);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update task']);
            }
            break;
            
        case 'delete_task':
            $taskId = intval($_POST['task_id'] ?? 0);
            
            $taskQuery = $conn->query("SELECT project_id FROM ten_project_tasks WHERE id = $taskId");
            $taskInfo = $taskQuery->fetch_assoc();
            
            if (!$taskInfo || !canManageProject($conn, $userId, $taskInfo['project_id'], $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("DELETE FROM ten_project_tasks WHERE id = ?");
            $stmt->bind_param("i", $taskId);
            
            if ($stmt->execute()) {
                logProjectActivity($conn, $taskInfo['project_id'], $userId, 'task_deleted', 'task', $taskId, 
                    "Deleted task ID: $taskId");
                updateProjectProgress($conn, $taskInfo['project_id']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete task']);
            }
            $stmt->close();
            break;
                        
        case 'get_my_tasks':
            $statusFilter = $_POST['status'] ?? '';
            $projectFilter = $_POST['project_id'] ?? '';
            $priorityFilter = $_POST['priority'] ?? '';
            $dateType = $_POST['date_type'] ?? '';
            $dateFrom = $_POST['date_from'] ?? '';
            $dateTo = $_POST['date_to'] ?? '';
            
            $query = "SELECT t.*, 
                p.project_name, p.project_key,
                m.milestone_name,
                creator.full_name as created_by_name
                FROM ten_project_tasks t
                INNER JOIN ten_projects p ON t.project_id = p.id
                LEFT JOIN ten_project_milestones m ON t.milestone_id = m.id
                LEFT JOIN ten_users creator ON t.created_by = creator.id
                WHERE t.assigned_to = ?";
            
            if ($statusFilter && $statusFilter !== 'all') {
                $query .= " AND t.status = '" . $conn->real_escape_string($statusFilter) . "'";
            }
            
            if ($projectFilter && $projectFilter !== 'all') {
                $query .= " AND t.project_id = " . intval($projectFilter);
            }
            
            if ($priorityFilter && $priorityFilter !== 'all') {
                $query .= " AND t.priority = '" . $conn->real_escape_string($priorityFilter) . "'";
            }
            
            // Date range filtering
            if ($dateType && $dateFrom && $dateTo) {
                if ($dateType === 'created') {
                    $query .= " AND DATE(t.created_at) BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'";
                } elseif ($dateType === 'start') {
                    $query .= " AND t.start_date BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'";
                } elseif ($dateType === 'due') {
                    $query .= " AND t.due_date BETWEEN '" . $conn->real_escape_string($dateFrom) . "' AND '" . $conn->real_escape_string($dateTo) . "'";
                }
            } elseif ($dateType && $dateFrom) {
                // From date only
                if ($dateType === 'created') {
                    $query .= " AND DATE(t.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
                } elseif ($dateType === 'start') {
                    $query .= " AND t.start_date >= '" . $conn->real_escape_string($dateFrom) . "'";
                } elseif ($dateType === 'due') {
                    $query .= " AND t.due_date >= '" . $conn->real_escape_string($dateFrom) . "'";
                }
            } elseif ($dateType && $dateTo) {
                // To date only
                if ($dateType === 'created') {
                    $query .= " AND DATE(t.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
                } elseif ($dateType === 'start') {
                    $query .= " AND t.start_date <= '" . $conn->real_escape_string($dateTo) . "'";
                } elseif ($dateType === 'due') {
                    $query .= " AND t.due_date <= '" . $conn->real_escape_string($dateTo) . "'";
                }
            }
            
            $query .= " ORDER BY t.status ASC, t.priority DESC, t.due_date ASC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            echo json_encode(['success' => true, 'tasks' => $tasks]);
            break;
            
        case 'get_all_tasks':
            $statusFilter = $_POST['status'] ?? '';
            $priorityFilter = $_POST['priority'] ?? '';
            $projectIdFilter = $_POST['project_id'] ?? '';
            $assignedToFilter = $_POST['assigned_to'] ?? '';
            $search = $_POST['search'] ?? '';
            $page = intval($_POST['page'] ?? 1);
            $perPage = intval($_POST['per_page'] ?? 20);
            
            $query = "SELECT t.*, 
                p.project_name, p.project_key,
                m.milestone_name,
                u.full_name as assigned_to_name,
                creator.full_name as created_by_name
                FROM ten_project_tasks t
                INNER JOIN ten_projects p ON t.project_id = p.id
                LEFT JOIN ten_project_milestones m ON t.milestone_id = m.id
                LEFT JOIN ten_users u ON t.assigned_to = u.id
                LEFT JOIN ten_users creator ON t.created_by = creator.id";
            
            $where = [];
            
            if ($statusFilter && $statusFilter !== 'all') {
                $where[] = "t.status = '" . $conn->real_escape_string($statusFilter) . "'";
            }
            
            if ($priorityFilter && $priorityFilter !== 'all') {
                $where[] = "t.priority = '" . $conn->real_escape_string($priorityFilter) . "'";
            }
            
            if ($projectIdFilter && $projectIdFilter !== 'all') {
                $where[] = "t.project_id = " . intval($projectIdFilter);
            }
            
            if ($assignedToFilter && $assignedToFilter !== 'all') {
                $where[] = "t.assigned_to = " . intval($assignedToFilter);
            }
            
            if ($search) {
                $searchEscaped = $conn->real_escape_string($search);
                $where[] = "(t.task_name LIKE '%$searchEscaped%' OR t.description LIKE '%$searchEscaped%')";
            }
            
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            
            $countQuery = "SELECT COUNT(*) as total FROM ten_project_tasks t";
            if (!empty($where)) {
                $countQuery .= " WHERE " . implode(" AND ", $where);
            }
            $totalResult = $conn->query($countQuery);
            $total = $totalResult->fetch_assoc()['total'];
            
            $query .= " ORDER BY t.status ASC, t.priority DESC, t.due_date ASC";
            $offset = ($page - 1) * $perPage;
            $query .= " LIMIT $perPage OFFSET $offset";
            
            $result = $conn->query($query);
            $tasks = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'tasks' => $tasks,
                'total' => $total
            ]);
            break;
            
        case 'update_task_status':
            $taskId = intval($_POST['task_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';
            
            if (!$taskId || !$newStatus) {
                echo json_encode(['success' => false, 'error' => 'Task ID and status required']);
                exit;
            }
            
            $stmt = $conn->prepare("UPDATE ten_project_tasks SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $newStatus, $taskId);
            
            if ($stmt->execute()) {
                $stmt->close();
                logActivity('task_updated', 'task', $taskId, "Task status changed to: $newStatus");
                echo json_encode(['success' => true, 'message' => 'Task status updated']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update task status']);
            }
            break;
            
        case 'get_overdue_tasks_count':
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ten_project_tasks t
                INNER JOIN ten_project_members pm ON t.project_id = pm.project_id
                WHERE (pm.user_id = ? OR ? = 1) 
                AND t.due_date < CURDATE() 
                AND t.status NOT IN ('completed', 'cancelled')");
            $stmt->bind_param("ii", $userId, $isAdmin);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            echo json_encode(['success' => true, 'count' => $result['count']]);
            break;

        case 'get_project_tasks':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $statusFilter = $_POST['status'] ?? '';
            
            // IMMEDIATE DEBUG - Return this info in JSON so we can see it
            $debugInfo = [
                'received_status' => $statusFilter,
                'received_project_id' => $projectId,
                'post_data' => $_POST
            ];
            
            if (!$projectId) {
                echo json_encode(['success' => false, 'error' => 'Project ID required']);
                exit;
            }
            
            if (!canViewProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $query = "SELECT t.*, 
                       u.full_name as assigned_to_name,
                       m.milestone_name,
                       creator.full_name as created_by_name
                       FROM ten_project_tasks t
                       LEFT JOIN ten_users u ON t.assigned_to = u.id
                       LEFT JOIN ten_project_milestones m ON t.milestone_id = m.id
                       LEFT JOIN ten_users creator ON t.created_by = creator.id
                       WHERE t.project_id = ?";
            
            $params = [$projectId];
            $types = 'i';
            
            if ($statusFilter && $statusFilter !== 'all') {
                $query .= " AND t.status = ?";
                $params[] = $statusFilter;
                $types .= 's';
                error_log("DEBUG: Adding status filter: $statusFilter");
            } else {
                error_log("DEBUG: No status filter applied (empty or 'all')");
            }
            
            $query .= " ORDER BY 
                           CASE t.status
                               WHEN 'blocked' THEN 1
                               WHEN 'in-progress' THEN 2
                               WHEN 'review' THEN 3
                               WHEN 'todo' THEN 4
                               WHEN 'completed' THEN 5
                           END,
                           t.priority DESC,
                           t.due_date ASC";
            
            error_log("DEBUG: Final query: $query");
            error_log("DEBUG: Params: " . json_encode($params));
            error_log("DEBUG: Types: $types");
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            
            error_log("DEBUG: Returning " . count($tasks) . " tasks");
            
            // Return debug info in response
            echo json_encode([
                'success' => true, 
                'tasks' => $tasks,
                'debug' => array_merge($debugInfo, [
                    'status_filter_applied' => ($statusFilter && $statusFilter !== 'all'),
                    'query_has_status_filter' => strpos($query, 'AND t.status') !== false,
                    'param_count' => count($params),
                    'types' => $types,
                    'final_query' => $query
                ])
            ]);
            break;

        case 'get_project_members':
            $projectId = (int)($_POST['project_id'] ?? 0);
            
            if (!$projectId) {
                echo json_encode(['success' => false, 'error' => 'Project ID required']);
                exit;
            }
            
            // Check project access
            if (!canViewProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT pm.role, pm.assigned_at, u.id, u.full_name, u.email
                                   FROM ten_project_members pm
                                   INNER JOIN ten_users u ON pm.user_id = u.id
                                   WHERE pm.project_id = ?
                                   ORDER BY 
                                       CASE pm.role
                                           WHEN 'owner' THEN 1
                                           WHEN 'manager' THEN 2
                                           WHEN 'member' THEN 3
                                           WHEN 'viewer' THEN 4
                                       END,
                                       u.full_name");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $members = [];
            while ($row = $result->fetch_assoc()) {
                $members[] = $row;
            }
            
            echo json_encode(['success' => true, 'members' => $members]);
            break;

            
        case 'get_project_activity':
            $projectId = intval($_POST['project_id'] ?? 0);
            $activityType = $_POST['activity_type'] ?? '';
            $userId = intval($_POST['user_id'] ?? 0);
            $dateFrom = $_POST['date_from'] ?? '';
            $dateTo = $_POST['date_to'] ?? '';
            $limit = intval($_POST['limit'] ?? 50);
            
            if (!$projectId) {
                echo json_encode(['success' => false, 'error' => 'Project ID required']);
                exit;
            }
            
            $query = "SELECT a.*, u.full_name as user_name
                     FROM ten_project_activity a
                     LEFT JOIN ten_users u ON a.user_id = u.id
                     WHERE a.project_id = ?";
            
            $params = [$projectId];
            $types = 'i';
            
            if ($activityType && $activityType !== 'all') {
                $query .= " AND a.action_type = ?";
                $params[] = $activityType;
                $types .= 's';
            }
            
            if ($userId && $userId > 0) {
                $query .= " AND a.user_id = ?";
                $params[] = $userId;
                $types .= 'i';
            }
            
            if ($dateFrom) {
                $query .= " AND DATE(a.created_at) >= ?";
                $params[] = $dateFrom;
                $types .= 's';
            }
            
            if ($dateTo) {
                $query .= " AND DATE(a.created_at) <= ?";
                $params[] = $dateTo;
                $types .= 's';
            }
            
            $query .= " ORDER BY a.created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= 'i';
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $activities = [];
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }
            
            echo json_encode(['success' => true, 'activities' => $activities]);
            break;
        // ==========================================
        // MILESTONE OPERATIONS
        // ==========================================
        
        case 'create_milestone':
            $projectId = intval($_POST['project_id'] ?? 0);
            $milestoneName = sanitize($_POST['milestone_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $milestoneType = $_POST['milestone_type'] ?? 'milestone';
            $dueDate = $_POST['due_date'] ?? null;
            $status = $_POST['status'] ?? 'pending';
            
            if (!canManageProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("INSERT INTO ten_project_milestones 
                (project_id, milestone_name, description, milestone_type, due_date, status) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $projectId, $milestoneName, $description, $milestoneType, $dueDate, $status);
            
            if ($stmt->execute()) {
                $milestoneId = $stmt->insert_id;
                $stmt->close();
                
                logProjectActivity($conn, $projectId, $userId, 'milestone_created', 'milestone', $milestoneId, 
                    "Created $milestoneType: $milestoneName");
                
                echo json_encode(['success' => true, 'milestone_id' => $milestoneId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create milestone']);
            }
            break;
            
        case 'update_milestone':
            $milestoneId = intval($_POST['milestone_id'] ?? 0);
            $milestoneName = sanitize($_POST['milestone_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $milestoneType = $_POST['milestone_type'] ?? 'milestone';
            $dueDate = $_POST['due_date'] ?? null;
            $status = $_POST['status'] ?? 'pending';
            
            $milestoneQuery = $conn->query("SELECT project_id FROM ten_project_milestones WHERE id = $milestoneId");
            $milestoneInfo = $milestoneQuery->fetch_assoc();
            
            if (!$milestoneInfo || !canManageProject($conn, $userId, $milestoneInfo['project_id'], $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $completedDate = ($status === 'completed') ? 'NOW()' : 'NULL';
            
            $stmt = $conn->prepare("UPDATE ten_project_milestones 
                SET milestone_name = ?, description = ?, milestone_type = ?, due_date = ?, 
                    status = ?, completed_date = $completedDate 
                WHERE id = ?");
            $stmt->bind_param("sssssi", $milestoneName, $description, $milestoneType, $dueDate, $status, $milestoneId);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // Notify project members if milestone completed
                if ($status === 'completed') {
                    notifyProjectMembers($conn, $milestoneInfo['project_id'], 'milestone-completed', 'milestone', 
                        $milestoneId, "Milestone Completed", "$milestoneType '$milestoneName' has been completed");
                }
                
                logProjectActivity($conn, $milestoneInfo['project_id'], $userId, 'milestone_updated', 
                    'milestone', $milestoneId, "Updated $milestoneType: $milestoneName");
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update milestone']);
            }
            break;
            
        case 'delete_milestone':
            $milestoneId = intval($_POST['milestone_id'] ?? 0);
            
            $milestoneQuery = $conn->query("SELECT project_id FROM ten_project_milestones WHERE id = $milestoneId");
            $milestoneInfo = $milestoneQuery->fetch_assoc();
            
            if (!$milestoneInfo || !canManageProject($conn, $userId, $milestoneInfo['project_id'], $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("DELETE FROM ten_project_milestones WHERE id = ?");
            $stmt->bind_param("i", $milestoneId);
            
            if ($stmt->execute()) {
                logProjectActivity($conn, $milestoneInfo['project_id'], $userId, 'milestone_deleted', 
                    'milestone', $milestoneId, "Deleted milestone ID: $milestoneId");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete milestone']);
            }
            $stmt->close();
            break;
            
        case 'get_project_milestones':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!canViewProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT * FROM ten_project_milestones 
                WHERE project_id = ? ORDER BY due_date ASC");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $milestones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            echo json_encode(['success' => true, 'milestones' => $milestones]);
            break;
            
        // ==========================================
        // GANTT CHART DATA
        // ==========================================
        
        case 'get_gantt_data':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!canViewProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $tasks = [];
            
            // Get milestones
            $milestoneQuery = $conn->prepare("SELECT 
                id, 
                milestone_name as name,
                due_date as start,
                due_date as end,
                'milestone' as custom_class,
                status
                FROM ten_project_milestones 
                WHERE project_id = ? 
                ORDER BY due_date ASC");
            $milestoneQuery->bind_param("i", $projectId);
            $milestoneQuery->execute();
            $milestones = $milestoneQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            foreach ($milestones as $milestone) {
                $tasks[] = [
                    'id' => 'M' . $milestone['id'],
                    'name' => $milestone['name'],
                    'start' => $milestone['start'],
                    'end' => $milestone['end'],
                    'progress' => $milestone['status'] === 'completed' ? 100 : 0,
                    'custom_class' => 'milestone'
                ];
            }
            
            // Get tasks
            $taskQuery = $conn->prepare("SELECT 
                t.id,
                t.task_name as name,
                COALESCE(t.start_date, t.due_date) as start,
                t.due_date as end,
                t.progress,
                t.status,
                u.full_name as assigned_to_name
                FROM ten_project_tasks t
                LEFT JOIN ten_users u ON t.assigned_to = u.id
                WHERE t.project_id = ? 
                AND t.start_date IS NOT NULL 
                AND t.due_date IS NOT NULL
                ORDER BY t.due_date ASC");
            $taskQuery->bind_param("i", $projectId);
            $taskQuery->execute();
            $taskResults = $taskQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            foreach ($taskResults as $task) {
                $tasks[] = [
                    'id' => 'T' . $task['id'],
                    'name' => $task['name'] . ($task['assigned_to_name'] ? ' (' . $task['assigned_to_name'] . ')' : ''),
                    'start' => $task['start'],
                    'end' => $task['end'],
                    'progress' => intval($task['progress']),
                    'custom_class' => 'task-' . $task['status']
                ];
            }
            
            echo json_encode(['success' => true, 'tasks' => $tasks]);
            break;
            
        // ==========================================
        // STATISTICS
        // ==========================================
        
        case 'get_project_statistics':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!canViewProject($conn, $userId, $projectId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stats = [];
            
            // Overall project stats
            $projectQuery = $conn->query("SELECT * FROM ten_projects WHERE id = $projectId");
            $stats['project'] = $projectQuery->fetch_assoc();
            
            // Task statistics
            $taskStats = $conn->query("SELECT 
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_tasks,
                SUM(CASE WHEN due_date < CURDATE() AND status NOT IN ('completed') THEN 1 ELSE 0 END) as overdue_tasks,
                SUM(estimated_hours) as total_estimated_hours,
                SUM(actual_hours) as total_actual_hours
                FROM ten_project_tasks WHERE project_id = $projectId")->fetch_assoc();
            $stats['task_stats'] = $taskStats;
            
            // Task status distribution
            $statusDist = $conn->query("SELECT status, COUNT(*) as count 
                FROM ten_project_tasks 
                WHERE project_id = $projectId 
                GROUP BY status")->fetch_all(MYSQLI_ASSOC);
            $stats['status_distribution'] = $statusDist;
            
            // Task priority distribution
            $priorityDist = $conn->query("SELECT priority, COUNT(*) as count 
                FROM ten_project_tasks 
                WHERE project_id = $projectId 
                GROUP BY priority")->fetch_all(MYSQLI_ASSOC);
            $stats['priority_distribution'] = $priorityDist;
            
            // Milestone progress
            $milestoneStats = $conn->query("SELECT 
                COUNT(*) as total_milestones,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_milestones,
                SUM(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue_milestones
                FROM ten_project_milestones WHERE project_id = $projectId")->fetch_assoc();
            $stats['milestone_stats'] = $milestoneStats;
            
            // Team member activity
            $memberActivity = $conn->query("SELECT 
                u.full_name,
                COUNT(t.id) as assigned_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
                FROM ten_project_members pm
                JOIN ten_users u ON pm.user_id = u.id
                LEFT JOIN ten_project_tasks t ON t.assigned_to = u.id AND t.project_id = $projectId
                WHERE pm.project_id = $projectId
                GROUP BY u.id, u.full_name
                ORDER BY assigned_tasks DESC")->fetch_all(MYSQLI_ASSOC);
            $stats['member_activity'] = $memberActivity;
            
            // Recent activity
            $recentActivity = $conn->query("SELECT 
                pa.*,
                u.full_name as user_name
                FROM ten_project_activity pa
                LEFT JOIN ten_users u ON pa.user_id = u.id
                WHERE pa.project_id = $projectId
                ORDER BY pa.created_at DESC
                LIMIT 20")->fetch_all(MYSQLI_ASSOC);
            $stats['recent_activity'] = $recentActivity;
            
            // Task completion trend (last 30 days)
            $completionTrend = $conn->query("SELECT 
                DATE(completed_date) as date,
                COUNT(*) as count
                FROM ten_project_tasks
                WHERE project_id = $projectId 
                AND completed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(completed_date)
                ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);
            $stats['completion_trend'] = $completionTrend;
            
            echo json_encode(['success' => true, 'statistics' => $stats]);
            break;
            
        // ==========================================
        // NOTIFICATIONS
        // ==========================================
        
        case 'get_notifications':
            $stmt = $conn->prepare("SELECT n.*, p.project_name 
                FROM ten_project_notifications n
                JOIN ten_projects p ON n.project_id = p.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 50");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            break;
            
        case 'mark_notification_read':
            $notificationId = intval($_POST['notification_id'] ?? 0);
            
            $stmt = $conn->prepare("UPDATE ten_project_notifications SET is_read = 1 
                WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notificationId, $userId);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to mark as read']);
            }
            $stmt->close();
            break;
            
        // ==========================================
        // DEADLINE CHECKING (for cron job)
        // ==========================================
        
        case 'check_deadlines':
            // This should be called by a cron job
            // Check for tasks due in 3 days, 1 day, and overdue
            
            $upcomingTasks = $conn->query("SELECT t.*, p.project_name, u.email, u.full_name
                FROM ten_project_tasks t
                JOIN ten_projects p ON t.project_id = p.id
                LEFT JOIN ten_users u ON t.assigned_to = u.id
                WHERE t.status NOT IN ('completed', 'cancelled')
                AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                AND u.id IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
            
            foreach ($upcomingTasks as $task) {
                $daysUntil = round((strtotime($task['due_date']) - time()) / 86400);
                $message = "Task '{$task['task_name']}' in project '{$task['project_name']}' is due in $daysUntil day(s)";
                
                createNotification($conn, $task['assigned_to'], $task['project_id'], 'deadline-approaching', 
                    'task', $task['id'], 'Deadline Approaching', $message);
                
                // Send email if not already sent today
                $checkEmail = $conn->query("SELECT id FROM ten_project_notifications 
                    WHERE user_id = {$task['assigned_to']} 
                    AND entity_id = {$task['id']} 
                    AND notification_type = 'deadline-approaching'
                    AND DATE(created_at) = CURDATE()");
                
                if ($checkEmail->num_rows === 0) {
                    sendDeadlineEmail($task['email'], $task['full_name'], $task['task_name'], 
                        $task['project_name'], $task['due_date']);
                }
            }
            
            echo json_encode(['success' => true, 'checked' => count($upcomingTasks)]);
            break;

        // ==========================================
        // TASK OPERATIONS - ADD THESE CASES
        // ==========================================

        case 'get_task':
            $taskId = intval($_POST['task_id'] ?? 0);
            
            if (!$taskId) {
                echo json_encode(['success' => false, 'error' => 'Task ID required']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT t.*, p.id as project_id 
                                   FROM ten_project_tasks t
                                   INNER JOIN ten_projects p ON t.project_id = p.id
                                   WHERE t.id = ?");
            $stmt->bind_param("i", $taskId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $task = $result->fetch_assoc();
                
                // Check access
                if (!canViewProject($conn, $task['project_id'], $userId, $isAdmin)) {
                    echo json_encode(['success' => false, 'error' => 'Access denied']);
                    exit;
                }
                
                echo json_encode(['success' => true, 'task' => $task]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Task not found']);
            }
            $stmt->close();
            break;

        case 'update_task_dates':
            $taskId = intval($_POST['task_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? null;
            $dueDate = $_POST['due_date'] ?? null;
            
            if (!$taskId) {
                echo json_encode(['success' => false, 'error' => 'Task ID required']);
                exit;
            }
            
            // Check task access
            $checkStmt = $conn->prepare("SELECT t.project_id FROM ten_project_tasks t WHERE t.id = ?");
            $checkStmt->bind_param("i", $taskId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Task not found']);
                exit;
            }
            
            $projectId = $result->fetch_assoc()['project_id'];
            $checkStmt->close();
            
            if (!canManageProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $stmt = $conn->prepare("UPDATE ten_project_tasks SET start_date = ?, due_date = ? WHERE id = ?");
            $stmt->bind_param("ssi", $startDate, $dueDate, $taskId);
            
            if ($stmt->execute()) {
                logProjectActivity($conn, $projectId, $userId, 'task_updated', 'task', $taskId, 
                    "Updated task dates");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update dates']);
            }
            $stmt->close();
            break;

        case 'update_task_progress':
            $taskId = intval($_POST['task_id'] ?? 0);
            $progress = intval($_POST['progress'] ?? 0);
            
            if (!$taskId) {
                echo json_encode(['success' => false, 'error' => 'Task ID required']);
                exit;
            }
            
            $progress = max(0, min(100, $progress)); // Clamp between 0-100
            
            // Check task access
            $checkStmt = $conn->prepare("SELECT t.project_id FROM ten_project_tasks t WHERE t.id = ?");
            $checkStmt->bind_param("i", $taskId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Task not found']);
                exit;
            }
            
            $projectId = $result->fetch_assoc()['project_id'];
            $checkStmt->close();
            
            if (!canManageProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $stmt = $conn->prepare("UPDATE ten_project_tasks SET progress = ? WHERE id = ?");
            $stmt->bind_param("ii", $progress, $taskId);
            
            if ($stmt->execute()) {
                // Auto-complete if 100%
                if ($progress === 100) {
                    $updateStmt = $conn->prepare("UPDATE ten_project_tasks SET status = 'completed', completed_date = NOW() WHERE id = ?");
                    $updateStmt->bind_param("i", $taskId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                
                logProjectActivity($conn, $projectId, $userId, 'task_updated', 'task', $taskId, 
                    "Updated task progress to {$progress}%");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update progress']);
            }
            $stmt->close();
            break;

        // ==========================================
        // MILESTONE OPERATIONS - ADD THESE CASES
        // ==========================================

        case 'get_milestone':
            $milestoneId = intval($_POST['milestone_id'] ?? 0);
            
            if (!$milestoneId) {
                echo json_encode(['success' => false, 'error' => 'Milestone ID required']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT m.*, p.id as project_id 
                                   FROM ten_project_milestones m
                                   INNER JOIN ten_projects p ON m.project_id = p.id
                                   WHERE m.id = ?");
            $stmt->bind_param("i", $milestoneId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $milestone = $result->fetch_assoc();
                
                // Check access
                if (!canViewProject($conn, $milestone['project_id'], $userId, $isAdmin)) {
                    echo json_encode(['success' => false, 'error' => 'Access denied']);
                    exit;
                }
                
                echo json_encode(['success' => true, 'milestone' => $milestone]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Milestone not found']);
            }
            $stmt->close();
            break;

        case 'update_milestone':
            $milestoneId = intval($_POST['milestone_id'] ?? 0);
            $milestoneName = sanitize($_POST['milestone_name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $milestoneType = $_POST['milestone_type'] ?? 'milestone';
            $dueDate = $_POST['due_date'] ?? null;
            $status = $_POST['status'] ?? 'pending';
            
            if (!$milestoneId || !$milestoneName || !$dueDate) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit;
            }
            
            // Get project ID and check access
            $checkStmt = $conn->prepare("SELECT project_id FROM ten_project_milestones WHERE id = ?");
            $checkStmt->bind_param("i", $milestoneId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Milestone not found']);
                exit;
            }
            
            $projectId = $result->fetch_assoc()['project_id'];
            $checkStmt->close();
            
            if (!canManageProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $completedDate = ($status === 'completed') ? date('Y-m-d') : null;
            
            $stmt = $conn->prepare("UPDATE ten_project_milestones 
                                   SET milestone_name = ?, description = ?, milestone_type = ?, 
                                       due_date = ?, status = ?, completed_date = ?
                                   WHERE id = ?");
            $stmt->bind_param("ssssssi", $milestoneName, $description, $milestoneType, 
                              $dueDate, $status, $completedDate, $milestoneId);
            
            if ($stmt->execute()) {
                logProjectActivity($conn, $projectId, $userId, 'milestone_updated', 'milestone', $milestoneId, 
                    "Updated milestone: $milestoneName");
                
                if ($status === 'completed') {
                    sendMilestoneCompletionEmail($conn, $projectId, $milestoneName);
                }
                
                echo json_encode(['success' => true, 'milestone_id' => $milestoneId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update milestone']);
            }
            $stmt->close();
            break;

        case 'delete_milestone':
            $milestoneId = intval($_POST['milestone_id'] ?? 0);
            
            if (!$milestoneId) {
                echo json_encode(['success' => false, 'error' => 'Milestone ID required']);
                exit;
            }
            
            // Get project ID and check access
            $checkStmt = $conn->prepare("SELECT project_id, milestone_name FROM ten_project_milestones WHERE id = ?");
            $checkStmt->bind_param("i", $milestoneId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Milestone not found']);
                exit;
            }
            
            $row = $result->fetch_assoc();
            $projectId = $row['project_id'];
            $milestoneName = $row['milestone_name'];
            $checkStmt->close();
            
            if (!canManageProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $stmt = $conn->prepare("DELETE FROM ten_project_milestones WHERE id = ?");
            $stmt->bind_param("i", $milestoneId);
            
            if ($stmt->execute()) {
                logProjectActivity($conn, $projectId, $userId, 'milestone_deleted', 'milestone', $milestoneId, 
                    "Deleted milestone: $milestoneName");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete milestone']);
            }
            $stmt->close();
            break;

        // ==========================================
        // GANTT DATA - ADD THIS CASE
        // ==========================================

        case 'get_gantt_data':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!$projectId) {
                echo json_encode(['success' => false, 'error' => 'Project ID required']);
                exit;
            }
            
            if (!canViewProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            // Get tasks and milestones
            $stmt = $conn->prepare("SELECT t.id, t.task_name, t.start_date, t.due_date, 
                                   t.progress, t.status, 
                                   td.depends_on_task_id as depends_on
                                   FROM ten_project_tasks t
                                   LEFT JOIN ten_project_task_dependencies td ON t.id = td.task_id
                                   WHERE t.project_id = ? 
                                   AND (t.start_date IS NOT NULL OR t.due_date IS NOT NULL)
                                   ORDER BY t.due_date ASC, t.id ASC");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            $stmt->close();
            
            // Get milestones
            $milestoneStmt = $conn->prepare("SELECT id, milestone_name as task_name, 
                                             due_date as start_date, due_date, 
                                             CASE WHEN status = 'completed' THEN 100 ELSE 0 END as progress,
                                             status
                                             FROM ten_project_milestones
                                             WHERE project_id = ?
                                             ORDER BY due_date ASC");
            $milestoneStmt->bind_param("i", $projectId);
            $milestoneStmt->execute();
            $milestoneResult = $milestoneStmt->get_result();
            
            while ($row = $milestoneResult->fetch_assoc()) {
                $tasks[] = $row;
            }
            $milestoneStmt->close();
            
            echo json_encode(['success' => true, 'tasks' => $tasks]);
            break;

        // ==========================================
        // STATISTICS - ADD THIS CASE
        // ==========================================

        case 'get_project_statistics':
            $projectId = intval($_POST['project_id'] ?? 0);
            
            if (!$projectId) {
                echo json_encode(['success' => false, 'error' => 'Project ID required']);
                exit;
            }
            
            if (!canViewProject($conn, $projectId, $userId, $isAdmin)) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            // Status distribution
            $statusStmt = $conn->prepare("SELECT status, COUNT(*) as count 
                                          FROM ten_project_tasks 
                                          WHERE project_id = ? 
                                          GROUP BY status");
            $statusStmt->bind_param("i", $projectId);
            $statusStmt->execute();
            $statusResult = $statusStmt->get_result();
            
            $statusDistribution = [];
            while ($row = $statusResult->fetch_assoc()) {
                $statusDistribution[ucfirst(str_replace('-', ' ', $row['status']))] = intval($row['count']);
            }
            $statusStmt->close();
            
            // Priority distribution
            $priorityStmt = $conn->prepare("SELECT priority, COUNT(*) as count 
                                            FROM ten_project_tasks 
                                            WHERE project_id = ? 
                                            GROUP BY priority");
            $priorityStmt->bind_param("i", $projectId);
            $priorityStmt->execute();
            $priorityResult = $priorityStmt->get_result();
            
            $priorityDistribution = [];
            while ($row = $priorityResult->fetch_assoc()) {
                $priorityDistribution[ucfirst($row['priority'])] = intval($row['count']);
            }
            $priorityStmt->close();
            
            $stats = [
                'statusDistribution' => $statusDistribution,
                'priorityDistribution' => $priorityDistribution
            ];
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // ==========================================
        // OVERDUE COUNT - ADD THIS CASE
        // ==========================================

        case 'get_overdue_count':
            // Get count of overdue tasks for user's projects
            if ($isAdmin) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count 
                                       FROM ten_project_tasks 
                                       WHERE due_date < CURDATE() 
                                       AND status != 'completed'");
                $stmt->execute();
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) as count 
                                       FROM ten_project_tasks t
                                       INNER JOIN ten_project_members pm ON t.project_id = pm.project_id
                                       WHERE t.due_date < CURDATE() 
                                       AND t.status != 'completed'
                                       AND pm.user_id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
            }
            
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $stmt->close();
            
            echo json_encode(['success' => true, 'count' => intval($count)]);
            break;
            
        case 'get_overview_stats':
            // Get status statistics
            $statusQuery = "SELECT status, COUNT(*) as count FROM ten_project_tasks ";
            if (!$isAdmin) {
                $statusQuery .= "INNER JOIN ten_project_members pm ON ten_project_tasks.project_id = pm.project_id 
                                WHERE pm.user_id = $userId ";
            }
            $statusQuery .= "GROUP BY status";
            
            $statusResult = $conn->query($statusQuery);
            $statusStats = [];
            while ($row = $statusResult->fetch_assoc()) {
                $statusStats[$row['status']] = intval($row['count']);
            }
            
            // Get priority statistics
            $priorityQuery = "SELECT priority, COUNT(*) as count FROM ten_project_tasks ";
            if (!$isAdmin) {
                $priorityQuery .= "INNER JOIN ten_project_members pm ON ten_project_tasks.project_id = pm.project_id 
                                  WHERE pm.user_id = $userId ";
            }
            $priorityQuery .= "GROUP BY priority";
            
            $priorityResult = $conn->query($priorityQuery);
            $priorityStats = [];
            while ($row = $priorityResult->fetch_assoc()) {
                $priorityStats[$row['priority']] = intval($row['count']);
            }
            
            // Get member activity statistics
            $memberQuery = "SELECT u.full_name as name, COUNT(t.id) as count 
                           FROM ten_users u
                           LEFT JOIN ten_project_tasks t ON u.id = t.assigned_to ";
            if (!$isAdmin) {
                $memberQuery .= "LEFT JOIN ten_project_members pm ON t.project_id = pm.project_id 
                               WHERE (pm.user_id = $userId OR t.assigned_to IS NULL) ";
            }
            $memberQuery .= "GROUP BY u.id, u.full_name 
                           HAVING count > 0 
                           ORDER BY count DESC 
                           LIMIT 10";
            
            $memberResult = $conn->query($memberQuery);
            $memberStats = [];
            while ($row = $memberResult->fetch_assoc()) {
                $memberStats[] = [
                    'name' => $row['name'],
                    'count' => intval($row['count'])
                ];
            }
            
            // Get overdue count
            $overdueQuery = "SELECT COUNT(*) as count FROM ten_project_tasks t ";
            if (!$isAdmin) {
                $overdueQuery .= "INNER JOIN ten_project_members pm ON t.project_id = pm.project_id 
                                WHERE pm.user_id = $userId AND ";
            } else {
                $overdueQuery .= "WHERE ";
            }
            $overdueQuery .= "t.due_date < CURDATE() AND t.status != 'completed'";
            
            $overdueResult = $conn->query($overdueQuery);
            $overdueCount = $overdueResult->fetch_assoc()['count'];
            
            echo json_encode([
                'success' => true,
                'status_stats' => $statusStats,
                'priority_stats' => $priorityStats,
                'member_stats' => $memberStats,
                'overdue_count' => intval($overdueCount)
            ]);
            break;

            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function canViewProject($conn, $userId, $projectId, $isAdmin) {
    if ($isAdmin) return true;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ten_project_members 
        WHERE project_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $projectId, $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['count'] > 0;
}

function canManageProject($conn, $userId, $projectId, $isAdmin) {
    if ($isAdmin) return true;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ten_project_members 
        WHERE project_id = ? AND user_id = ? AND role IN ('owner', 'manager')");
    $stmt->bind_param("ii", $projectId, $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['count'] > 0;
}

function logProjectActivity($conn, $projectId, $userId, $activityType, $entityType, $entityId, $description) {
    $stmt = $conn->prepare("INSERT INTO ten_project_activity 
        (project_id, user_id, activity_type, entity_type, entity_id, description) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissis", $projectId, $userId, $activityType, $entityType, $entityId, $description);
    $stmt->execute();
    $stmt->close();
}

function createNotification($conn, $userId, $projectId, $notificationType, $entityType, $entityId, $title, $message) {
    $stmt = $conn->prepare("INSERT INTO ten_project_notifications 
        (user_id, project_id, notification_type, entity_type, entity_id, title, message) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iississ", $userId, $projectId, $notificationType, $entityType, $entityId, $title, $message);
    $stmt->execute();
    $stmt->close();
}

function notifyProjectMembers($conn, $projectId, $notificationType, $entityType, $entityId, $title, $message) {
    $members = $conn->query("SELECT user_id FROM ten_project_members WHERE project_id = $projectId");
    
    while ($member = $members->fetch_assoc()) {
        createNotification($conn, $member['user_id'], $projectId, $notificationType, 
            $entityType, $entityId, $title, $message);
    }
}

function updateProjectProgress($conn, $projectId) {
    $stats = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM ten_project_tasks WHERE project_id = $projectId")->fetch_assoc();
    
    $progress = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
    
    $conn->query("UPDATE ten_projects SET progress = $progress WHERE id = $projectId");
}

function sendProjectAssignmentEmail($conn, $projectId, $userId, $projectName) {
    $userQuery = $conn->query("SELECT email, full_name FROM ten_users WHERE id = $userId");
    $user = $userQuery->fetch_assoc();
    
    if ($user) {
        $subject = "Assigned to Project: $projectName";
        $body = "
            <h2>Project Assignment</h2>
            <p>Hi {$user['full_name']},</p>
            <p>You have been assigned to the project: <strong>$projectName</strong></p>
            <p>Please log in to the TEN Management System to view project details and your tasks.</p>
            <p><a href='" . SITE_URL . "/module-project-management.php'>View Project</a></p>
        ";
        sendEmail($user['email'], $subject, $body);
        
        // Mark notification as emailed
        $conn->query("UPDATE ten_project_notifications SET is_emailed = 1 
            WHERE user_id = $userId AND project_id = $projectId AND is_emailed = 0 
            ORDER BY created_at DESC LIMIT 1");
    }
}

function sendTaskAssignmentEmail($conn, $taskId, $userId, $taskName) {
    $taskQuery = $conn->query("SELECT t.*, p.project_name FROM ten_project_tasks t 
        JOIN ten_projects p ON t.project_id = p.id WHERE t.id = $taskId");
    $task = $taskQuery->fetch_assoc();
    
    $userQuery = $conn->query("SELECT email, full_name FROM ten_users WHERE id = $userId");
    $user = $userQuery->fetch_assoc();
    
    if ($user && $task) {
        $subject = "New Task Assigned: $taskName";
        $body = "
            <h2>Task Assignment</h2>
            <p>Hi {$user['full_name']},</p>
            <p>You have been assigned a new task in project <strong>{$task['project_name']}</strong>:</p>
            <p><strong>Task:</strong> $taskName</p>
            <p><strong>Priority:</strong> {$task['priority']}</p>
            <p><strong>Due Date:</strong> {$task['due_date']}</p>
            <p><a href='" . SITE_URL . "/module-project-management.php'>View Task</a></p>
        ";
        sendEmail($user['email'], $subject, $body);
    }
}

function sendDeadlineEmail($email, $fullName, $taskName, $projectName, $dueDate) {
    $subject = "Deadline Approaching: $taskName";
    $body = "
        <h2>Task Deadline Reminder</h2>
        <p>Hi $fullName,</p>
        <p>This is a reminder that the following task is approaching its deadline:</p>
        <p><strong>Task:</strong> $taskName</p>
        <p><strong>Project:</strong> $projectName</p>
        <p><strong>Due Date:</strong> $dueDate</p>
        <p><a href='" . SITE_URL . "/module-project-management.php'>View Task</a></p>
    ";
    sendEmail($email, $subject, $body);
}

function sendMilestoneCompletionEmail($conn, $projectId, $milestoneName) {
    // Get project members
    $stmt = $conn->prepare("SELECT u.email, u.full_name, p.project_name
                           FROM ten_project_members pm
                           INNER JOIN ten_users u ON pm.user_id = u.id
                           INNER JOIN ten_projects p ON pm.project_id = p.id
                           WHERE pm.project_id = ?");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $subject = "Milestone Completed: {$milestoneName}";
        $message = "
            <h2>ðŸŽ‰ Milestone Completed!</h2>
            <p>Hi {$row['full_name']},</p>
            <p>Great news! A milestone has been completed in <strong>{$row['project_name']}</strong>:</p>
            <p><strong>{$milestoneName}</strong></p>
            <p>Keep up the great work!</p>
        ";
        
        sendEmail($row['email'], $subject, $message);
    }
    $stmt->close();
}

