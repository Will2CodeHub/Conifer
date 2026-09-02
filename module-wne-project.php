<?php
require_once 'config.php';
requireLogin();

$conn = getDBConnection();
$userId = $_SESSION['ten_user_id'];
$isSuperAdmin = isAdmin();
$projectId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$projectId) {
    header('Location: module-wne.php?error=invalid_project');
    exit();
}

// Check access
if (!$isSuperAdmin) {
    $stmt = $conn->prepare("SELECT id FROM ten_wne_project_users WHERE user_id = ? AND project_id = ?");
    $stmt->bind_param("ii", $userId, $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        header('Location: module-wne.php?error=unauthorized');
        exit();
    }
    $stmt->close();
}

// Get project details
$stmt = $conn->prepare("SELECT p.*, u.full_name as creator_name FROM ten_wne_projects p LEFT JOIN ten_users u ON p.created_by = u.id WHERE p.id = ?");
$stmt->bind_param("i", $projectId);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$project) {
    header('Location: module-wne.php?error=project_not_found');
    exit();
}

// Get user permissions
$permissions = ['can_update_status' => false, 'can_add_candidates' => false, 'can_download_cvs' => false];
if ($isSuperAdmin) {
    $permissions = ['can_update_status' => true, 'can_add_candidates' => true, 'can_download_cvs' => true];
} else {
    $stmt = $conn->prepare("SELECT can_update_status, can_add_candidates, can_download_cvs FROM ten_wne_project_users WHERE user_id = ? AND project_id = ?");
    $stmt->bind_param("ii", $userId, $projectId);
    $stmt->execute();
    $userPerms = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($userPerms) {
        $permissions = $userPerms;
    }
}

// Get candidates
$stmt = $conn->prepare("SELECT pc.*, c.full_name, c.email, c.phone, c.summary, u.full_name as submitted_by_name
                       FROM ten_wne_project_candidates pc
                       JOIN ten_cv_candidates c ON pc.candidate_id = c.id
                       LEFT JOIN ten_users u ON pc.submitted_by = u.id
                       WHERE pc.project_id = ? AND pc.is_active = 1
                       ORDER BY pc.submitted_at DESC");
$stmt->bind_param("i", $projectId);
$stmt->execute();
$candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['project_name']); ?> - WNE Recruitment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        body { padding-bottom: 80px; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        .page-header { margin-bottom: 32px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .page-header p { color: #6b7280; font-size: 15px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #2563eb; text-decoration: none; margin-bottom: 24px; font-weight: 600; }
        .back-link:hover { color: #1d4ed8; }
        .project-info-card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 16px; }
        .info-item { display: flex; align-items: start; gap: 12px; }
        .info-item i { color: #6b7280; margin-top: 2px; }
        .info-label { font-size: 13px; color: #6b7280; margin-bottom: 4px; }
        .info-value { font-weight: 600; color: #111827; }
        .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
        .btn-primary { padding: 12px 24px; background: #3b82f6; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .candidates-table { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th { padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; }
        td { padding: 16px; border-bottom: 1px solid #e5e7eb; }
        tr:hover { background: #f9fafb; }
        .candidate-name { font-weight: 600; color: #111827; margin-bottom: 2px; }
        .candidate-email { font-size: 13px; color: #6b7280; }
        .stage-badge { padding: 6px 12px; background: #eff6ff; color: #1e40af; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block; }
        .btn-icon { padding: 8px 12px; background: #f3f4f6; border: none; border-radius: 8px; color: #374151; cursor: pointer; font-size: 13px; transition: all 0.2s; margin-right: 4px; }
        .btn-icon:hover { background: #e5e7eb; }
        .btn-icon.primary { background: #2563eb; color: white; }
        .btn-icon.primary:hover { background: #1d4ed8; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 1000; overflow-y: auto; padding: 40px 20px; }
        .modal.active { display: flex; align-items: flex-start; justify-content: center; }
        .modal-content { background: white; border-radius: 12px; max-width: 700px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); margin: auto; }
        .modal-header { padding: 24px; border-bottom: 1px solid #e5e7eb; font-size: 20px; font-weight: 700; color: #111827; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 12px; }
        .close-modal { background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .empty-state { text-align: center; padding: 80px 20px; }
        .empty-state i { font-size: 64px; color: #d1d5db; margin-bottom: 16px; }
        .content-wrapper { margin-top: 30px; max-width: 1600px; }
        .status-badge { padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-onhold { background: #fef3c7; color: #92400e; }
        .status-closed { background: #e5e7eb; color: #374151; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-wrapper" style="margin-left:30px;">
            <a href="module-wne.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Projects
            </a>
            
            <div class="page-header">
                <h1><i class="fas fa-project-diagram"></i> <?php echo htmlspecialchars($project['project_name']); ?></h1>
                <p><?php echo htmlspecialchars($project['project_code']); ?></p>
            </div>

            <!-- Project Info Card -->
            <div class="project-info-card">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                    <h3 style="font-size: 18px; font-weight: 700;">Project Details</h3>
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $project['project_status'])); ?>">
                        <?php echo $project['project_status']; ?>
                    </span>
                </div>
                
                <div class="info-grid">
                    <?php if ($project['employer_company']): ?>
                    <div class="info-item">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="info-label">Employer</div>
                            <div class="info-value"><?php echo htmlspecialchars($project['employer_company']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($project['position_title']): ?>
                    <div class="info-item">
                        <i class="fas fa-briefcase"></i>
                        <div>
                            <div class="info-label">Position</div>
                            <div class="info-value"><?php echo htmlspecialchars($project['position_title']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($project['location']): ?>
                    <div class="info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <div class="info-label">Location</div>
                            <div class="info-value"><?php echo htmlspecialchars($project['location']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($project['salary_range']): ?>
                    <div class="info-item">
                        <i class="fas fa-euro-sign"></i>
                        <div>
                            <div class="info-label">Salary Range</div>
                            <div class="info-value"><?php echo htmlspecialchars($project['salary_range']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <div>
                            <div class="info-label">Positions</div>
                            <div class="info-value"><?php echo $project['num_positions']; ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <div>
                            <div class="info-label">Created By</div>
                            <div class="info-value"><?php echo htmlspecialchars($project['creator_name']); ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($project['position_description']): ?>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <div class="info-label">Position Description</div>
                    <p style="color: #4b5563; margin-top: 8px; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($project['position_description'])); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Candidates Section -->
            <div class="actions-bar">
                <h3 style="font-size: 20px; color: #111827;">Candidates (<?php echo count($candidates); ?>)</h3>
                <?php if ($permissions['can_add_candidates']): ?>
                <button class="btn-primary" onclick="openAddCandidateModal()">
                    <i class="fas fa-plus"></i> Add Candidate
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($candidates)): ?>
            <div class="empty-state" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                <i class="fas fa-users"></i>
                <h3>No candidates yet</h3>
                <p>Add candidates to start tracking their progress</p>
            </div>
            <?php else: ?>
            <div class="candidates-table">
                <table>
                    <thead>
                        <tr>
                            <th>Candidate</th>
                            <th>Current Stage</th>
                            <th>Target Company</th>
                            <th>Consent Status</th>
                            <th>Submitted By</th>
                            <th>Submitted</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td>
                                <div class="candidate-name"><?php echo htmlspecialchars($candidate['full_name']); ?></div>
                                <div class="candidate-email"><?php echo htmlspecialchars($candidate['email'] ?? 'No email'); ?></div>
                            </td>
                            <td>
                                <span class="stage-badge"><?php echo htmlspecialchars($candidate['current_stage']); ?></span>
                            </td>
                            <td><?php echo $candidate['target_company'] ? htmlspecialchars($candidate['target_company']) : '<span style="color: #9ca3af;">N/A</span>'; ?></td>
                            <td>
                                <?php if ($candidate['consent_required']): ?>
                                    <?php if ($candidate['consent_status'] == 'pending'): ?>
                                        <span style="padding: 4px 8px; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px;">⏳ Pending</span>
                                    <?php elseif ($candidate['consent_status'] == 'granted'): ?>
                                        <span style="padding: 4px 8px; background: #d1fae5; color: #065f46; border-radius: 6px; font-size: 13px;">✓ Granted</span>
                                    <?php elseif ($candidate['consent_status'] == 'denied'): ?>
                                        <span style="padding: 4px 8px; background: #fee2e2; color: #991b1b; border-radius: 6px; font-size: 13px;">✗ Denied</span>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">Not Requested</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">Not Required</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($candidate['submitted_by_name']); ?></td>
                            <td style="color: #6b7280; font-size: 14px;"><?php echo date('M j, Y', strtotime($candidate['submitted_at'])); ?></td>
                            <td style="text-align: center;">
                                <button onclick="viewCandidateCV(<?php echo $candidate['candidate_id']; ?>)" class="btn-icon primary" title="View CV">
                                    <i class="fas fa-file-alt"></i>
                                </button>
                                <?php if ($permissions['can_update_status']): ?>
                                <button onclick="updateCandidateStage(<?php echo $candidate['id']; ?>, '<?php echo htmlspecialchars($candidate['current_stage'], ENT_QUOTES); ?>')" class="btn-icon" title="Update Stage">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($candidate['consent_required'] && $candidate['consent_status'] != 'granted' && $candidate['consent_status'] != 'denied'): ?>
                                <button onclick="requestConsent(<?php echo $candidate['id']; ?>)" class="btn-icon" style="background: #fef3c7; color: #92400e;" title="Request Consent">
                                    <i class="fas fa-user-check"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($candidate['consent_status']): ?>
                                <button onclick="viewConsentHistory(<?php echo $candidate['id']; ?>)" class="btn-icon" title="View Consent History">
                                    <i class="fas fa-clipboard-list"></i>
                                </button>
                                <button onclick="updateConsentManually(<?php echo $candidate['id']; ?>)" class="btn-icon" title="Update Consent Status">
                                    <i class="fas fa-check-circle"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="viewCandidateHistory(<?php echo $candidate['id']; ?>)" class="btn-icon" title="View History">
                                    <i class="fas fa-history"></i>
                                </button>
                                <?php if ($permissions['can_add_candidates']): ?>
                                <button onclick="removeCandidate(<?php echo $candidate['id']; ?>)" class="btn-icon" style="background: #fee2e2; color: #991b1b;" title="Remove from Project">
                                    <i class="fas fa-user-times"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Candidate Modal -->
    <div class="modal" id="addCandidateModal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Add Candidate to Project</span>
                <button class="close-modal" onclick="closeAddCandidateModal()">&times;</button>
            </div>
            <form id="addCandidateForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_candidate_to_project">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                    
                    <div class="form-group">
                        <label>Select Candidate *</label>
                        <select id="candidateSelect" name="candidate_id" required>
                            <option value="">Loading candidates...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Submission Source</label>
                        <select name="submission_source">
                            <option value="WNE Internal">WNE Internal</option>
                            <option value="External Recruiter">External Recruiter</option>
                            <option value="Direct Application">Direct Application</option>
                            <option value="Referral">Referral</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Expected Salary</label>
                        <input type="text" name="expected_salary" placeholder="e.g., €60,000">
                    </div>
                    
                    <div class="form-group">
                        <label>Notice Period</label>
                        <input type="text" name="notice_period" placeholder="e.g., 1 month">
                    </div>
                    
                    <div class="form-group">
                        <label>Availability Date</label>
                        <input type="date" name="availability_date">
                    </div>
                    
                    <div class="form-group">
                        <label>Internal Notes</label>
                        <textarea name="internal_notes" placeholder="Add any relevant notes about this candidate..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Target Company (Optional)</label>
                        <input type="text" name="target_company" placeholder="Company name if submitting to specific client...">
                        <small style="color: #6b7280; font-size: 13px;">Leave blank if internal review only</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="consent_required" value="1" style="width: auto; cursor: pointer;">
                            <span>Candidate consent required before submission</span>
                        </label>
                        <small style="color: #6b7280; font-size: 13px; margin-left: 28px;">Check this if you need candidate's permission to submit their CV</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeAddCandidateModal()" style="padding: 12px 24px; background: #6b7280; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-primary">Add Candidate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Stage Modal -->
    <div class="modal" id="updateStageModal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Update Candidate Stage</span>
                <button class="close-modal" onclick="closeUpdateStageModal()">&times;</button>
            </div>
            <form id="updateStageForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_candidate_stage">
                    <input type="hidden" id="projectCandidateId" name="project_candidate_id">
                    
                    <div class="form-group">
                        <label>New Stage *</label>
                        <select name="new_stage" required>
                            <option value="Submitted">Submitted</option>
                            <option value="Internal Review">Internal Review</option>
                            <option value="Shortlisted">Shortlisted</option>
                            <option value="Submitted to Client">Submitted to Client</option>
                            <option value="Client Review">Client Review</option>
                            <option value="Phone Screen">Phone Screen</option>
                            <option value="First Interview">First Interview</option>
                            <option value="Second Interview">Second Interview</option>
                            <option value="Third Interview">Third Interview</option>
                            <option value="Final Interview">Final Interview</option>
                            <option value="Reference Check">Reference Check</option>
                            <option value="Offer Preparation">Offer Preparation</option>
                            <option value="Offer Extended">Offer Extended</option>
                            <option value="Offer Accepted">Offer Accepted</option>
                            <option value="Offer Declined">Offer Declined</option>
                            <option value="Hired">Hired</option>
                            <option value="Rejected">Rejected</option>
                            <option value="Withdrawn">Withdrawn</option>
                            <option value="On Hold">On Hold</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="change_notes" placeholder="Add notes about this status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeUpdateStageModal()" style="padding: 12px 24px; background: #6b7280; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-primary">Update Stage</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddCandidateModal() {
            document.getElementById('addCandidateModal').classList.add('active');
            loadAvailableCandidates();
        }
        
        function closeAddCandidateModal() {
            document.getElementById('addCandidateModal').classList.remove('active');
        }
        
        function loadAvailableCandidates() {
            const formData = new FormData();
            formData.append('action', 'get_available_candidates');
            formData.append('project_id', <?php echo $projectId; ?>);
            
            fetch('ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const select = document.getElementById('candidateSelect');
                if (data.success && data.candidates.length > 0) {
                    select.innerHTML = '<option value="">Select candidate...</option>';
                    data.candidates.forEach(candidate => {
                        select.innerHTML += `<option value="${candidate.id}">${candidate.full_name} (${candidate.email || 'No email'})</option>`;
                    });
                } else {
                    select.innerHTML = '<option value="">No candidates available</option>';
                }
            });
        }
        
        function updateCandidateStage(projectCandidateId, currentStage) {
            document.getElementById('projectCandidateId').value = projectCandidateId;
            
            // Set the current stage as selected
            const stageSelect = document.querySelector('#updateStageForm select[name="new_stage"]');
            if (currentStage && stageSelect) {
                stageSelect.value = currentStage;
            }
            
            // Clear notes
            const notesTextarea = document.querySelector('#updateStageForm textarea[name="change_notes"]');
            if (notesTextarea) notesTextarea.value = '';
            
            // Clear any existing canned notes
            const existingContainer = document.getElementById('cannedNotesContainer');
            if (existingContainer) existingContainer.remove();
            
            const cannedNotes = {
                'Submitted': [
                    'CV received and logged',
                    'Initial screening pending',
                    'Candidate profile created'
                ],
                'Internal Review': [
                    'CV reviewed - meets requirements',
                    'Experience aligns with role',
                    'Skills assessment pending',
                    'Strong technical background',
                    'Needs additional review'
                ],
                'Shortlisted': [
                    'Strong candidate for next stage',
                    'Excellent background and experience',
                    'Top 3 candidate',
                    'Profile matches job requirements perfectly',
                    'Moving forward to interview'
                ],
                'Submitted to Client': [
                    'CV submitted to client for review',
                    'Client reviewing profile',
                    'Awaiting client feedback',
                    'Profile sent with strong recommendation'
                ],
                'Client Review': [
                    'Client is reviewing candidate',
                    'Awaiting client decision',
                    'Client requested additional information',
                    'Under consideration by hiring manager'
                ],
                'Phone Screen': [
                    'Phone screen completed - positive outcome',
                    'Strong interest confirmed from candidate',
                    'Technical knowledge appears solid',
                    'Cultural fit looks good',
                    'Moving to next interview stage'
                ],
                'First Interview': [
                    'Interview scheduled for [date]',
                    'Interview completed - positive feedback',
                    'Strong performance in interview',
                    'Technical skills demonstrated well',
                    'Awaiting decision on next steps'
                ],
                'Second Interview': [
                    'Second round scheduled',
                    'Interview with senior management completed',
                    'Very positive feedback from panel',
                    'Final stage interview',
                    'Team fit assessment positive'
                ],
                'Technical Test': [
                    'Technical assessment sent',
                    'Test completed - reviewing results',
                    'Strong technical performance',
                    'Code review in progress',
                    'Assessment passed successfully'
                ],
                'Final Interview': [
                    'Final interview scheduled',
                    'Executive interview completed',
                    'Decision pending from leadership',
                    'Very strong final impression',
                    'Moving to offer stage'
                ],
                'Reference Check': [
                    'References requested from candidate',
                    'Reference checks in progress',
                    'All references checked - positive',
                    'Strong recommendations received',
                    'Background verification complete'
                ],
                'Offer Extended': [
                    'Offer extended to candidate',
                    'Offer letter sent - awaiting response',
                    'Salary negotiation in progress',
                    'Candidate reviewing offer terms',
                    'Offer accepted verbally'
                ],
                'Offer Accepted': [
                    'Offer formally accepted',
                    'Contract signed',
                    'Start date confirmed',
                    'Onboarding process initiated',
                    'Notice period being served'
                ],
                'Hired': [
                    'Successfully placed',
                    'First day completed successfully',
                    'Placement confirmed',
                    'Onboarding completed',
                    'Settled into role well'
                ],
                'Rejected': [
                    'Not proceeding - skills mismatch',
                    'Client decided on other candidate',
                    'Salary expectations too high',
                    'Not the right fit for team',
                    'Withdrew from process'
                ],
                'Withdrawn': [
                    'Candidate withdrew application',
                    'Accepted position elsewhere',
                    'No longer interested in role',
                    'Personal reasons for withdrawal',
                    'Counter-offer from current employer'
                ]
            };
            
            setTimeout(() => {
                stageSelect.addEventListener('change', function() {
                    const notes = cannedNotes[this.value];
                    let container = document.getElementById('cannedNotesContainer');
                    if (!container) {
                        container = document.createElement('div');
                        container.id = 'cannedNotesContainer';
                        container.style.cssText = 'margin-bottom: 12px; display: flex; flex-direction: column; gap: 6px;';
                        notesTextarea.parentNode.insertBefore(container, notesTextarea);
                    }
                    if (notes && notes.length > 0) {
                        container.innerHTML = '<div style="font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px;">Quick notes (click to insert, then edit as needed):</div>';
                        notes.forEach(note => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.style.cssText = 'padding: 8px 12px; font-size: 13px; text-align: left; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; transition: all 0.2s;';
                            btn.textContent = note;
                            btn.onmouseover = function() { this.style.background = '#e5e7eb'; this.style.borderColor = '#9ca3af'; };
                            btn.onmouseout = function() { this.style.background = '#f3f4f6'; this.style.borderColor = '#d1d5db'; };
                            btn.onclick = function() { 
                                notesTextarea.value = note;
                                notesTextarea.focus();
                                // Highlight the textarea briefly
                                notesTextarea.style.borderColor = '#3b82f6';
                                setTimeout(() => { notesTextarea.style.borderColor = '#d1d5db'; }, 1000);
                            };
                            container.appendChild(btn);
                        });
                    } else {
                        container.innerHTML = '';
                    }
                });
                
                // Trigger on initial load if stage is already set
                if (currentStage && cannedNotes[currentStage]) {
                    stageSelect.dispatchEvent(new Event('change'));
                }
            }, 100);
            
            document.getElementById('updateStageModal').classList.add('active');
        }
        
        function closeUpdateStageModal() {
            document.getElementById('updateStageModal').classList.remove('active');
        }
        
        function viewCandidateCV(candidateId) {
            const formData = new FormData();
            formData.append('action', 'get_cv_full_data');
            formData.append('candidate_id', candidateId);
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const c = data.candidate;
                    
                    // Fix the file path - extract just the filename from the full path
                    const cvFileName = c.cv_file_path.split('/').pop();
                    const cvUrl = '/management/cvs/' + cvFileName;
                    
                    let html = `<div style="text-align: left; max-height: 600px; overflow-y: auto; padding: 20px;">
                        <div style="margin-bottom: 20px; padding: 12px; background: #f9fafb; border-radius: 8px; display: flex; gap: 12px;">
                            <a href="${cvUrl}" download style="flex: 1; padding: 10px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; text-align: center;">
                                <i class="fas fa-download"></i> Download CV
                            </a>
                            <a href="${cvUrl}" target="_blank" style="flex: 1; padding: 10px; background: #059669; color: white; text-decoration: none; border-radius: 6px; text-align: center;">
                                <i class="fas fa-external-link-alt"></i> View Original
                            </a>
                        </div>
                        
                        <h2 style="margin-bottom: 20px; color: #111827; border-bottom: 2px solid #3b82f6; padding-bottom: 10px;">${c.full_name}</h2>
                        
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151;">Contact Information</h4>
                            ${c.email ? `<p style="margin: 8px 0;"><strong>Email:</strong> <a href="mailto:${c.email}" style="color: #3b82f6;">${c.email}</a></p>` : ''}
                            ${c.phone ? `<p style="margin: 8px 0;"><strong>Phone:</strong> ${c.phone}</p>` : ''}
                            ${c.address ? `<p style="margin: 8px 0;"><strong>Address:</strong> ${c.address}</p>` : ''}
                            ${c.city ? `<p style="margin: 8px 0;"><strong>City:</strong> ${c.city}</p>` : ''}
                            ${c.country ? `<p style="margin: 8px 0;"><strong>Country:</strong> ${c.country}</p>` : ''}
                            ${c.postcode ? `<p style="margin: 8px 0;"><strong>Postcode:</strong> ${c.postcode}</p>` : ''}
                        </div>
                        
                        ${c.date_of_birth || c.nationality ? `
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151;">Personal Details</h4>
                            ${c.date_of_birth ? `<p style="margin: 8px 0;"><strong>Date of Birth:</strong> ${c.date_of_birth}</p>` : ''}
                            ${c.nationality ? `<p style="margin: 8px 0;"><strong>Nationality:</strong> ${c.nationality}</p>` : ''}
                        </div>
                        ` : ''}
                        
                        ${c.linkedin_url || c.website_url ? `
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151;">Online Presence</h4>
                            ${c.linkedin_url ? `<p style="margin: 8px 0;"><strong>LinkedIn:</strong> <a href="${c.linkedin_url}" target="_blank" style="color: #3b82f6;">${c.linkedin_url}</a></p>` : ''}
                            ${c.website_url ? `<p style="margin: 8px 0;"><strong>Website:</strong> <a href="${c.website_url}" target="_blank" style="color: #3b82f6;">${c.website_url}</a></p>` : ''}
                        </div>
                        ` : ''}
                        
                        ${c.summary ? `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;">Professional Summary</h4>
                            <div style="padding: 16px; background: #f9fafb; border-left: 4px solid #3b82f6; border-radius: 4px; line-height: 1.6;">${c.summary}</div>
                        </div>
                        ` : ''}
                        
                        ${c.work_history_parsed && c.work_history_parsed.length > 0 ? `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;">Work Experience</h4>
                            ${c.work_history_parsed.map(w => `
                                <div style="margin-bottom: 16px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; background: white;">
                                    <div style="font-weight: 600; font-size: 16px; color: #111827; margin-bottom: 4px;">${w.position}</div>
                                    <div style="color: #3b82f6; font-weight: 500; margin-bottom: 4px;">${w.company}</div>
                                    <div style="color: #6b7280; font-size: 14px; margin-bottom: 8px;">
                                        <i class="far fa-calendar"></i> ${w.start_date} - ${w.end_date}
                                    </div>
                                    ${w.description ? `<p style="margin-top: 12px; color: #4b5563; line-height: 1.6;">${w.description}</p>` : ''}
                                </div>
                            `).join('')}
                        </div>
                        ` : ''}
                        
                        ${c.education_parsed && c.education_parsed.length > 0 ? `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;">Education</h4>
                            ${c.education_parsed.map(e => `
                                <div style="margin-bottom: 12px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; background: white;">
                                    <div style="font-weight: 600; font-size: 16px; color: #111827; margin-bottom: 4px;">${e.degree}${e.field ? ' in ' + e.field : ''}</div>
                                    <div style="color: #3b82f6; font-weight: 500; margin-bottom: 4px;">${e.institution}</div>
                                    ${e.year ? `<div style="color: #6b7280; font-size: 14px;"><i class="far fa-calendar"></i> ${e.year}</div>` : ''}
                                </div>
                            `).join('')}
                        </div>
                        ` : ''}
                        
                        ${c.skills_list ? `
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;">Skills</h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                ${c.skills_list.split(', ').map(skill => 
                                    `<span style="padding: 8px 16px; background: #eff6ff; color: #1e40af; border-radius: 20px; font-size: 14px; font-weight: 500;">${skill}</span>`
                                ).join('')}
                            </div>
                        </div>
                        ` : ''}
                        
                        <div style="margin-top: 30px; padding: 12px; background: #f3f4f6; border-radius: 6px; font-size: 12px; color: #6b7280;">
                            <strong>CV Uploaded:</strong> ${c.parse_date ? new Date(c.parse_date).toLocaleString() : 'N/A'}
                            ${c.ai_parsed ? ' • <span style="color: #059669;">AI Parsed</span>' : ''}
                        </div>
                    </div>`;
                    
                    Swal.fire({
                        title: 'Candidate Profile',
                        html: html,
                        width: '900px',
                        confirmButtonText: 'Close',
                        customClass: {
                            popup: 'cv-modal-popup'
                        }
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to load CV data', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to load candidate data', 'error');
            });
        }
        
        // Replace the entire viewCandidateHistory function with this:

        function viewCandidateHistory(projectCandidateId) {
            const formData = new FormData();
            formData.append('action', 'get_candidate_history');
            formData.append('project_candidate_id', projectCandidateId);
            
            fetch('ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '<div style="max-height: 500px; overflow-y: auto; text-align: left;">';
                    if (data.history.length > 0) {
                        data.history.forEach(h => {
                            // Handle notes - check multiple possible field names and values
                            const notes = h.change_notes || h.notes || h.change_note || '';
                            const hasNotes = notes && notes !== '0' && notes !== 0 && notes.trim() !== '';
                            
                            html += `
                                <div style="padding: 20px; margin-bottom: 16px; background: #f9fafb; border-radius: 8px;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                        <div style="flex: 1;">
                                            <div style="font-size: 16px; font-weight: 600; color: #111827; margin-bottom: 4px;">
                                                ${h.from_stage ? '<span style="color: #6b7280;">' + h.from_stage + '</span> <span style="color: #3b82f6;">→</span> ' : ''}
                                                <span style="color: #059669;">${h.to_stage}</span>
                                            </div>
                                            <div style="font-size: 13px; color: #6b7280;">
                                                <i class="fas fa-user" style="margin-right: 4px;"></i> ${h.changed_by_name}
                                            </div>
                                        </div>
                                        <div style="font-size: 13px; color: #6b7280; text-align: right; white-space: nowrap; margin-left: 16px;">
                                            <i class="fas fa-clock" style="margin-right: 4px;"></i>
                                            ${new Date(h.created_at).toLocaleDateString('en-US', {
                                                year: 'numeric',
                                                month: 'short',
                                                day: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit'
                                            })}
                                        </div>
                                    </div>
                                    ${hasNotes ? `
                                        <div style="margin-top: 12px; padding: 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                            <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <i class="fas fa-sticky-note" style="margin-right: 4px;"></i> Notes
                                            </div>
                                            <div style="font-size: 14px; color: #374151; line-height: 1.6;">
                                                ${notes}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            `;
                        });
                    } else {
                        html += `
                            <div style="text-align: center; padding: 60px 20px;">
                                <i class="fas fa-history" style="font-size: 48px; color: #d1d5db; margin-bottom: 16px;"></i>
                                <p style="color: #6b7280; font-size: 16px; margin: 0;">No history available for this candidate</p>
                            </div>
                        `;
                    }
                    html += '</div>';
                    
                    Swal.fire({
                        title: '<span style="color: #111827;">Candidate History</span>',
                        html: html,
                        width: '700px',
                        showConfirmButton: true,
                        confirmButtonText: 'Close',
                        confirmButtonColor: '#3b82f6',
                        customClass: {
                            popup: 'candidate-history-modal'
                        }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to load candidate history',
                        confirmButtonColor: '#dc2626'
                    });
                }
            })
            .catch(error => {
                console.error('Error loading history:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load candidate history',
                    confirmButtonColor: '#dc2626'
                });
            });
        }
        
        function removeCandidate(projectCandidateId) {
            Swal.fire({
                title: 'Remove Candidate?',
                text: 'This will remove the candidate from this project. You can add them back later if needed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Remove',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'remove_candidate_from_project');
                    formData.append('project_candidate_id', projectCandidateId);
                    
                    fetch('ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Find and remove the table row
                            const button = event.target.closest('button');
                            const row = button.closest('tr');
                            if (row) {
                                row.style.transition = 'opacity 0.3s';
                                row.style.opacity = '0';
                                setTimeout(() => {
                                    row.remove();
                                    // Check if table is now empty
                                    const tbody = document.querySelector('.candidates-table tbody');
                                    if (tbody && tbody.children.length === 0) {
                                        location.reload(); // Reload to show empty state
                                    }
                                }, 300);
                            }
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Removed!',
                                text: 'Candidate has been removed from the project',
                                confirmButtonColor: '#059669',
                                timer: 1500,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    });
                }
            });
        }
        
        // Form submissions
        document.getElementById('addCandidateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeAddCandidateModal(); // Close modal first
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        confirmButtonColor: '#059669',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#dc2626'
                    });
                }
            });
        });
        
        document.getElementById('updateStageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeUpdateStageModal(); // Close the modal first
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        confirmButtonColor: '#059669',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#dc2626'
                    });
                }
            });
        });
        
        // Consent Management Functions
        function requestConsent(projectCandidateId) {
            Swal.fire({
                title: 'Request Candidate Consent',
                html: `
                    <div style="text-align: left; margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Target Company Name *</label>
                        <input id="targetCompany" class="swal2-input" placeholder="Enter company name" style="width: 90%;">
                    </div>
                    <div style="text-align: left;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Message to Candidate</label>
                        <textarea id="requestMessage" class="swal2-textarea" placeholder="Explain the opportunity to the candidate..." style="width: 90%; height: 120px;"></textarea>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Send Consent Request',
                confirmButtonColor: '#3b82f6',
                width: '600px',
                preConfirm: () => {
                    const company = document.getElementById('targetCompany').value;
                    const message = document.getElementById('requestMessage').value;
                    
                    if (!company) {
                        Swal.showValidationMessage('Please enter the company name');
                        return false;
                    }
                    
                    return { company, message };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'request_candidate_consent');
                    formData.append('project_candidate_id', projectCandidateId);
                    formData.append('target_company', result.value.company);
                    formData.append('request_message', result.value.message);
                    
                    fetch('ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Consent Request Sent!',
                                text: 'The candidate has been notified via email',
                                confirmButtonColor: '#059669'
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    });
                }
            });
        }
        
        function viewConsentHistory(projectCandidateId) {
            const formData = new FormData();
            formData.append('action', 'get_consent_requests');
            formData.append('project_candidate_id', projectCandidateId);
            
            fetch('ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '<div style="text-align: left; max-height: 500px; overflow-y: auto;">';
                    
                    if (data.requests.length === 0) {
                        html += '<p style="text-align: center; color: #6b7280; padding: 40px;">No consent requests yet</p>';
                    } else {
                        data.requests.forEach(req => {
                            const statusColor = req.consent_status === 'granted' ? '#059669' : (req.consent_status === 'denied' ? '#dc2626' : '#f59e0b');
                            const statusText = req.consent_status.charAt(0).toUpperCase() + req.consent_status.slice(1);
                            
                            html += `
                                <div style="padding: 16px; margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                        <strong style="color: #111827;">${req.target_company}</strong>
                                        <span style="padding: 4px 12px; background: ${statusColor}; color: white; border-radius: 12px; font-size: 12px; font-weight: 600;">${statusText}</span>
                                    </div>
                                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 8px;">
                                        Requested by: ${req.requested_by_name} on ${new Date(req.requested_at).toLocaleString()}
                                    </div>
                                    ${req.request_message ? `<div style="margin-top: 12px; padding: 12px; background: white; border-radius: 6px;"><strong>Message:</strong><br>${req.request_message}</div>` : ''}
                                    ${req.candidate_response ? `<div style="margin-top: 12px; padding: 12px; background: white; border-radius: 6px;"><strong>Candidate Response:</strong><br>${req.candidate_response}<br><small style="color: #6b7280;">Responded: ${new Date(req.responded_at).toLocaleString()}</small></div>` : ''}
                                </div>
                            `;
                        });
                    }
                    
                    html += '</div>';
                    
                    Swal.fire({
                        title: 'Consent Request History',
                        html: html,
                        width: '700px',
                        confirmButtonText: 'Close'
                    });
                }
            });
        }
        
        function updateConsentManually(projectCandidateId) {
            Swal.fire({
                title: 'Update Consent Status',
                html: `
                    <div style="text-align: left; margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Consent Status *</label>
                        <select id="consentStatus" class="swal2-input" style="width: 90%;">
                            <option value="granted">Granted (Candidate approved)</option>
                            <option value="denied">Denied (Candidate declined)</option>
                        </select>
                    </div>
                    <div style="text-align: left;">
                        <label style="font-weight: 600; margin-bottom: 8px; display: block;">Notes</label>
                        <textarea id="consentNotes" class="swal2-textarea" placeholder="Record how you received the consent response..." style="width: 90%;"></textarea>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Update Status',
                confirmButtonColor: '#3b82f6',
                width: '600px',
                preConfirm: () => {
                    const status = document.getElementById('consentStatus').value;
                    const notes = document.getElementById('consentNotes').value;
                    return { status, notes };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'update_consent_status');
                    formData.append('project_candidate_id', projectCandidateId);
                    formData.append('consent_status', result.value.status);
                    formData.append('notes', result.value.notes);
                    
                    fetch('ajax/ajax_wne.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Status Updated!',
                                text: 'Consent status has been recorded',
                                confirmButtonColor: '#059669',
                                timer: 1500
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>
