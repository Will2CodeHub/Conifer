<?php
require_once 'config.php';
requireLogin();

$isSuperAdmin = isAdmin();
$conn = getDBConnection();
$userId = $_SESSION['ten_user_id'];

if (!$isSuperAdmin) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$pageTitle = 'Candidate Database Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TEN Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            color: #1a202c;
            margin: 0;
            padding: 0;
            padding-bottom: 80px;
            margin-left: 260px; /* Push content away from sidebar */
        }
        
        .content-wrapper {
            max-width: 1600px;
            margin: 0px 0px 0px 30px;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        .header p {
            margin: 0;
            color: #6b7280;
        }
        
        .filters-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-success {
            background: #059669;
            color: white;
        }
        
        .btn-success:hover {
            background: #047857;
        }
        
        .results-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .results-count {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .candidate-name {
            font-weight: 600;
            color: #111827;
        }
        
        .candidate-email {
            font-size: 13px;
            color: #6b7280;
        }
        
        .btn-icon {
            padding: 8px 12px;
            background: #f3f4f6;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-right: 4px;
            transition: all 0.2s;
        }
        
        .btn-icon:hover {
            background: #e5e7eb;
        }
        
        .btn-icon.primary {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .btn-icon.primary:hover {
            background: #dbeafe;
        }
        
        .btn-icon.success {
            background: #d1fae5;
            color: #059669;
        }
        
        .btn-icon.success:hover {
            background: #a7f3d0;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }
        
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .pagination button:hover:not(:disabled) {
            background: #f9fafb;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination button.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        
        .project-list {
            margin-top: 20px;
        }
        
        .project-item {
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .project-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .project-details {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: #6b7280;
        }
        
        .stage-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #eff6ff;
            color: #3b82f6;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        
        .empty-state h3 {
            color: #6b7280;
            font-weight: 600;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            #exportModal, #exportModal * {
                visibility: visible;
            }
            #exportModal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .modal-header button,
            .btn {
                display: none !important;
            }
        }
        
        @media (max-width: 768px) {
            body {
                margin-left: 0;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: scroll;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="content-wrapper">
        <div class="header">
            <h1><i class="fas fa-users"></i> Candidate Database</h1>
            <p>Search, filter, and manage all candidates in the recruitment system</p>
        </div>
        
        <div class="filters-section">
            <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 18px; color: #111827;">Search & Filter</h3>
            
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Candidate Name</label>
                    <input type="text" id="filterName" placeholder="Search by name...">
                </div>
                
                <div class="filter-group">
                    <label>Email</label>
                    <input type="text" id="filterEmail" placeholder="Search by email...">
                </div>
                
                <div class="filter-group">
                    <label>Skills</label>
                    <input type="text" id="filterSkills" placeholder="e.g., JavaScript, Python...">
                </div>
                
                <div class="filter-group">
                    <label>Location/City</label>
                    <input type="text" id="filterLocation" placeholder="City or country...">
                </div>
                
                <div class="filter-group">
                    <label>Project Assigned</label>
                    <select id="filterProject">
                        <option value="">All Projects</option>
                        <!-- Populated dynamically -->
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Current Stage</label>
                    <select id="filterStage">
                        <option value="">Any Stage</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Internal Review">Internal Review</option>
                        <option value="Shortlisted">Shortlisted</option>
                        <option value="Submitted to Client">Submitted to Client</option>
                        <option value="Phone Screen">Phone Screen</option>
                        <option value="First Interview">First Interview</option>
                        <option value="Second Interview">Second Interview</option>
                        <option value="Final Interview">Final Interview</option>
                        <option value="Reference Check">Reference Check</option>
                        <option value="Offer Preparation">Offer Preparation</option>
                        <option value="Offer Extended">Offer Extended</option>
                        <option value="Offer Accepted">Offer Accepted</option>
                        <option value="Hired">Hired</option>
                        <option value="Rejected">Rejected</option>
                        <option value="Withdrawn">Withdrawn</option>
                        <option value="On Hold">On Hold</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Assignment Date</label>
                    <select id="filterDateRange" onchange="toggleCustomDateRange()">
                        <option value="">Any Time</option>
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="last7days">Last 7 Days</option>
                        <option value="last30days">Last 30 Days</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                
                <div class="filter-group" id="customDateFrom" style="display: none;">
                    <label>Date From</label>
                    <input type="date" id="filterDateFrom">
                </div>
                
                <div class="filter-group" id="customDateTo" style="display: none;">
                    <label>Date To</label>
                    <input type="date" id="filterDateTo">
                </div>
                
                <div class="filter-group">
                    <label>Language</label>
                    <input type="text" id="filterLanguage" placeholder="e.g., English, Spanish...">
                </div>
                
                <div class="filter-group">
                    <label>Nationality</label>
                    <input type="text" id="filterNationality" placeholder="Search by nationality...">
                </div>
                
                <div class="filter-group">
                    <label>Years of Experience</label>
                    <select id="filterExperience">
                        <option value="">Any</option>
                        <option value="0-2">0-2 years</option>
                        <option value="3-5">3-5 years</option>
                        <option value="6-10">6-10 years</option>
                        <option value="10+">10+ years</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button class="btn btn-primary" onclick="applyCandidateFilters()">
                    <i class="fas fa-search"></i> Search
                </button>
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
                <button class="btn btn-success" onclick="exportResults()" id="exportBtn" style="display: none;">
                    <i class="fas fa-file-export"></i> Export Results
                </button>
            </div>
        </div>
        
        <div class="results-section">
            <div class="results-header">
                <div class="results-count" id="resultsCount">Loading candidates...</div>
            </div>
            
            <div class="table-container">
                <table id="candidatesTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Candidate</th>
                            <th>Location</th>
                            <th>Projects</th>
                            <th>Parse Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="candidatesTableBody">
                        <tr>
                            <td colspan="6" class="loading">
                                <i class="fas fa-spinner fa-spin"></i> Loading candidates...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="pagination" id="pagination"></div>
        </div>
    </div>
    
    <!-- Candidate Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Candidate Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailsContent">
                <div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- Stats Modal -->
    <div class="modal" id="statsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Candidate Statistics & Projects</h3>
                <button class="modal-close" onclick="closeModal('statsModal')">&times;</button>
            </div>
            <div class="modal-body" id="statsContent">
                <div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
    </div>
    
    <!-- Export Modal -->
    <div class="modal" id="exportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Export Candidate Report</h3>
                <button class="modal-close" onclick="closeModal('exportModal')">&times;</button>
            </div>
            <div class="modal-body" id="exportContent">
                <div style="margin-bottom: 24px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Select Recipients:</label>
                    <select id="exportRecipients" multiple style="width: 100%; height: 150px; padding: 8px; border: 1px solid #d1d5db; border-radius: 8px;">
                        <option disabled>Loading users...</option>
                    </select>
                    <p style="font-size: 13px; color: #6b7280; margin-top: 8px;">Hold Ctrl/Cmd to select multiple users</p>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="printReport()" style="margin-right: 8px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button class="btn btn-success" onclick="emailReport()">
                        <i class="fas fa-envelope"></i> Email to Selected Users
                    </button>
                </div>
                
                <div id="reportContent" style="border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;">
                    <!-- Report will be generated here -->
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentPage = 1;
        let totalPages = 1;
        let currentFilters = {};
        let allProjects = [];
        let allUsers = [];
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadProjects();
            loadUsers();
            loadCandidates();
        });
        
        function loadProjects() {
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_projects'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allProjects = data.projects;
                    const select = document.getElementById('filterProject');
                    data.projects.forEach(p => {
                        const option = document.createElement('option');
                        option.value = p.id;
                        option.textContent = `${p.project_name} (${p.project_code})`;
                        select.appendChild(option);
                    });
                }
            });
        }
        
        function loadUsers() {
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_all_users'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Users loaded:', data);
                if (data.success) {
                    allUsers = data.users;
                    const select = document.getElementById('exportRecipients');
                    if (!select) {
                        console.error('exportRecipients select not found!');
                        return;
                    }
                    // Clear existing options first
                    select.innerHTML = '';
                    data.users.forEach(u => {
                        const option = document.createElement('option');
                        option.value = u.id;
                        option.textContent = `${u.full_name} (${u.email})`;
                        select.appendChild(option);
                    });
                    console.log(`Loaded ${data.users.length} users into select`);
                } else {
                    console.error('Failed to load users:', data.message);
                }
            })
            .catch(error => {
                console.error('Error loading users:', error);
            });
        }
        
        function loadCandidates(page = 1) {
            currentPage = page;
            
            const formData = new FormData();
            formData.append('action', 'search_candidates');
            formData.append('page', page);
            formData.append('per_page', 20);
            
            // Add all filters
            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key]) {
                    formData.append(key, currentFilters[key]);
                }
            });
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderCandidates(data.candidates);
                    renderPagination(data.total, data.page, data.per_page);
                    document.getElementById('resultsCount').textContent = `${data.total} candidate(s) found`;
                    document.getElementById('exportBtn').style.display = data.total > 0 ? 'inline-block' : 'none';
                } else {
                    document.getElementById('candidatesTableBody').innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading candidates</h3></td></tr>';
                }
            });
        }
        
        function renderCandidates(candidates) {
            const tbody = document.getElementById('candidatesTableBody');
            
            if (candidates.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fas fa-user-slash"></i><h3>No candidates found</h3><p>Try adjusting your filters</p></td></tr>';
                return;
            }
            
            tbody.innerHTML = candidates.map(c => `
                <tr>
                    <td>${c.id}</td>
                    <td>
                        <div class="candidate-name">${c.full_name}</div>
                        <div class="candidate-email">${c.email || 'No email'}</div>
                    </td>
                    <td>${c.city ? c.city + (c.country ? ', ' + c.country : '') : 'Not specified'}</td>
                    <td>${c.project_count || 0} project(s)</td>
                    <td>${c.parse_date ? new Date(c.parse_date).toLocaleDateString() : 'N/A'}</td>
                    <td>
                        <button class="btn-icon primary" onclick="viewDetails(${c.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-icon" onclick="viewCV(${c.id})" title="View CV">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                        <button class="btn-icon success" onclick="viewStats(${c.id})" title="Stats & Projects">
                            <i class="fas fa-chart-line"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        function renderPagination(total, page, perPage) {
            totalPages = Math.ceil(total / perPage);
            const pagination = document.getElementById('pagination');
            
            if (totalPages <= 1) {
                pagination.innerHTML = '';
                return;
            }
            
            let html = '<button onclick="loadCandidates(1)" ' + (page === 1 ? 'disabled' : '') + '><i class="fas fa-angle-double-left"></i></button>';
            html += '<button onclick="loadCandidates(' + (page - 1) + ')" ' + (page === 1 ? 'disabled' : '') + '><i class="fas fa-angle-left"></i></button>';
            
            let startPage = Math.max(1, page - 2);
            let endPage = Math.min(totalPages, page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                html += '<button onclick="loadCandidates(' + i + ')" class="' + (i === page ? 'active' : '') + '">' + i + '</button>';
            }
            
            html += '<button onclick="loadCandidates(' + (page + 1) + ')" ' + (page === totalPages ? 'disabled' : '') + '><i class="fas fa-angle-right"></i></button>';
            html += '<button onclick="loadCandidates(' + totalPages + ')" ' + (page === totalPages ? 'disabled' : '') + '><i class="fas fa-angle-double-right"></i></button>';
            
            pagination.innerHTML = html;
        }
        
        function applyCandidateFilters() {
            currentFilters = {
                name: document.getElementById('filterName').value,
                email: document.getElementById('filterEmail').value,
                skills: document.getElementById('filterSkills').value,
                location: document.getElementById('filterLocation').value,
                project: document.getElementById('filterProject').value,
                stage: document.getElementById('filterStage').value,
                date_range: document.getElementById('filterDateRange').value,
                date_from: document.getElementById('filterDateFrom').value,
                date_to: document.getElementById('filterDateTo').value,
                language: document.getElementById('filterLanguage').value,
                nationality: document.getElementById('filterNationality').value,
                experience: document.getElementById('filterExperience').value
            };
            
            loadCandidates(1);
        }
        
        function clearFilters() {
            document.getElementById('filterName').value = '';
            document.getElementById('filterEmail').value = '';
            document.getElementById('filterSkills').value = '';
            document.getElementById('filterLocation').value = '';
            document.getElementById('filterProject').value = '';
            document.getElementById('filterStage').value = '';
            document.getElementById('filterDateRange').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterLanguage').value = '';
            document.getElementById('filterNationality').value = '';
            document.getElementById('filterExperience').value = '';
            document.getElementById('customDateFrom').style.display = 'none';
            document.getElementById('customDateTo').style.display = 'none';
            
            currentFilters = {};
            loadCandidates(1);
        }
        
        function toggleCustomDateRange() {
            const value = document.getElementById('filterDateRange').value;
            const customFrom = document.getElementById('customDateFrom');
            const customTo = document.getElementById('customDateTo');
            
            if (value === 'custom') {
                customFrom.style.display = 'block';
                customTo.style.display = 'block';
            } else {
                customFrom.style.display = 'none';
                customTo.style.display = 'none';
            }
        }
        
        function viewDetails(candidateId) {
            document.getElementById('detailsModal').classList.add('active');
            document.getElementById('detailsContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
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
                    const cvFileName = c.cv_file_path.split('/').pop();
                    const cvUrl = '/management/cvs/' + cvFileName;
                    
                    let html = `
                        <div style="margin-bottom: 20px; display: flex; gap: 12px;">
                            <a href="${cvUrl}" download class="btn btn-primary" style="text-decoration: none;">
                                <i class="fas fa-download"></i> Download CV
                            </a>
                            <a href="${cvUrl}" target="_blank" class="btn btn-success" style="text-decoration: none;">
                                <i class="fas fa-external-link-alt"></i> View Original CV
                            </a>
                        </div>
                        
                        <h2 style="margin-bottom: 20px; color: #111827;">${c.full_name}</h2>
                        
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px;">Contact Information</h4>
                            ${c.email ? `<p><strong>Email:</strong> ${c.email}</p>` : ''}
                            ${c.phone ? `<p><strong>Phone:</strong> ${c.phone}</p>` : ''}
                            ${c.address ? `<p><strong>Address:</strong> ${c.address}</p>` : ''}
                            ${c.city ? `<p><strong>City:</strong> ${c.city}</p>` : ''}
                            ${c.country ? `<p><strong>Country:</strong> ${c.country}</p>` : ''}
                            ${c.postcode ? `<p><strong>Postcode:</strong> ${c.postcode}</p>` : ''}
                        </div>
                        
                        ${c.date_of_birth || c.nationality ? `
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px;">Personal Details</h4>
                            ${c.date_of_birth ? `<p><strong>Date of Birth:</strong> ${c.date_of_birth}</p>` : ''}
                            ${c.nationality ? `<p><strong>Nationality:</strong> ${c.nationality}</p>` : ''}
                        </div>
                        ` : ''}
                        
                        ${c.linkedin_url || c.website_url ? `
                        <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                            <h4 style="margin-bottom: 12px;">Online Presence</h4>
                            ${c.linkedin_url ? `<p><strong>LinkedIn:</strong> <a href="${c.linkedin_url}" target="_blank">${c.linkedin_url}</a></p>` : ''}
                            ${c.website_url ? `<p><strong>Website:</strong> <a href="${c.website_url}" target="_blank">${c.website_url}</a></p>` : ''}
                        </div>
                        ` : ''}
                        
                        ${c.summary ? `<div style="margin-bottom: 20px;"><h4>Professional Summary</h4><p>${c.summary}</p></div>` : ''}
                        
                        ${c.work_history_parsed && c.work_history_parsed.length > 0 ? `
                        <div style="margin-bottom: 20px;">
                            <h4>Work Experience</h4>
                            ${c.work_history_parsed.map(w => `
                                <div style="margin-bottom: 16px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                    <div style="font-weight: 600;">${w.position}</div>
                                    <div style="color: #3b82f6;">${w.company}</div>
                                    <div style="color: #6b7280; font-size: 14px;">${w.start_date} - ${w.end_date}</div>
                                    ${w.description ? `<p style="margin-top: 8px;">${w.description}</p>` : ''}
                                </div>
                            `).join('')}
                        </div>
                        ` : ''}
                        
                        ${c.education_parsed && c.education_parsed.length > 0 ? `
                        <div style="margin-bottom: 20px;">
                            <h4>Education</h4>
                            ${c.education_parsed.map(e => `
                                <div style="margin-bottom: 12px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                    <div style="font-weight: 600;">${e.degree}${e.field ? ' in ' + e.field : ''}</div>
                                    <div style="color: #3b82f6;">${e.institution}</div>
                                    ${e.year ? `<div style="color: #6b7280;">${e.year}</div>` : ''}
                                </div>
                            `).join('')}
                        </div>
                        ` : ''}
                        
                        ${c.skills_list ? `
                        <div style="margin-bottom: 20px;">
                            <h4>Skills</h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                ${c.skills_list.split(', ').map(skill => 
                                    `<span style="padding: 8px 16px; background: #eff6ff; color: #1e40af; border-radius: 20px;">${skill}</span>`
                                ).join('')}
                            </div>
                        </div>
                        ` : ''}
                    `;
                    
                    document.getElementById('detailsContent').innerHTML = html;
                }
            });
        }
        
        function viewCV(candidateId) {
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
                    const cvFileName = data.candidate.cv_file_path.split('/').pop();
                    const cvUrl = '/management/cvs/' + cvFileName;
                    window.open(cvUrl, '_blank');
                }
            });
        }
        
        function viewStats(candidateId) {
            document.getElementById('statsModal').classList.add('active');
            document.getElementById('statsContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
            const formData = new FormData();
            formData.append('action', 'get_candidate_stats');
            formData.append('candidate_id', candidateId);
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderStatsModal(data);
                } else {
                    document.getElementById('statsContent').innerHTML = '<p style="color: #dc2626;">Error loading stats</p>';
                }
            });
        }

        function renderStatsModal(data) {
            const c = data.candidate;
            const stats = data.stats;
            
            let html = `
                <h3 style="margin-bottom: 16px;">${c.full_name}</h3>
                
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Projects</div>
                        <div class="stat-value">${stats.total_projects}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Active Projects</div>
                        <div class="stat-value">${stats.active_projects}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Times Hired</div>
                        <div class="stat-value">${stats.hired_count}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Times Rejected</div>
                        <div class="stat-value">${stats.rejected_count}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="openAssignModal(${c.id})">
                        <i class="fas fa-plus"></i> Assign to Project
                    </button>
                </div>
                
                <h4 style="margin-bottom: 16px;">Project History & Timeline</h4>
            `;
            
            if (data.projects.length === 0) {
                html += '<p style="text-align: center; color: #6b7280; padding: 20px;">Not assigned to any projects yet</p>';
            } else {
                data.projects.forEach(p => {
                    html += `
                        <div class="project-history-section" style="margin-bottom: 32px; padding: 20px; background: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                                <div>
                                    <h5 style="margin: 0 0 4px 0; font-size: 18px; color: #111827;">${p.project_name}</h5>
                                    <div style="font-size: 13px; color: #6b7280;">${p.project_code}</div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="stage-badge" style="padding: 6px 12px; background: #dbeafe; color: #1e40af; border-radius: 6px; font-size: 13px; font-weight: 600;">
                                        ${p.current_stage}
                                    </div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">Current Stage</div>
                                </div>
                            </div>
                            
                            ${p.internal_notes ? `
                                <div style="padding: 12px; background: white; border-radius: 6px; margin-bottom: 16px; border-left: 3px solid #3b82f6;">
                                    <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">INITIAL NOTES</div>
                                    <div style="font-size: 14px; color: #374151;">${p.internal_notes}</div>
                                </div>
                            ` : ''}
                            
                            <div style="margin-top: 16px;">
                                <h6 style="font-size: 14px; color: #374151; margin-bottom: 12px; font-weight: 600;">
                                    <i class="fas fa-history" style="margin-right: 6px; color: #3b82f6;"></i>
                                    Stage History
                                </h6>
                    `;
                    
                    if (p.history && p.history.length > 0) {
                        p.history.forEach(h => {
                            const notes = h.change_notes || '';
                            const hasNotes = notes && notes !== '0' && notes !== 0 && notes.trim() !== '';
                            
                            html += `
                                <div style="padding: 16px; background: white; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid #3b82f6;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                        <div style="flex: 1;">
                                            <div style="font-size: 15px; font-weight: 600; color: #111827;">
                                                ${h.from_stage ? '<span style="color: #6b7280;">' + h.from_stage + '</span> <span style="color: #3b82f6;">→</span> ' : ''}
                                                <span style="color: #059669;">${h.to_stage}</span>
                                            </div>
                                            <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                                <i class="fas fa-user" style="margin-right: 4px;"></i> ${h.changed_by_name}
                                            </div>
                                        </div>
                                        <div style="font-size: 13px; color: #6b7280; text-align: right; white-space: nowrap; margin-left: 16px;">
                                            <i class="fas fa-clock" style="margin-right: 4px;"></i>
                                            ${new Date(h.created_at).toLocaleDateString('en-US', {
                                                month: 'short',
                                                day: 'numeric',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit'
                                            })}
                                        </div>
                                    </div>
                                    ${hasNotes ? `
                                        <div style="margin-top: 12px; padding: 10px 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                                            <div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <i class="fas fa-sticky-note" style="margin-right: 4px;"></i> Notes
                                            </div>
                                            <div style="font-size: 14px; color: #374151; line-height: 1.5;">
                                                ${notes}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            `;
                        });
                    } else {
                        html += '<p style="text-align: center; color: #9ca3af; padding: 20px; font-size: 13px;">No stage changes recorded yet</p>';
                    }
                    
                    html += `
                            </div>
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
                                <i class="fas fa-calendar-alt" style="margin-right: 4px;"></i> 
                                Submitted: ${new Date(p.submitted_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                                <span style="margin: 0 8px;">•</span>
                                By: ${p.submitted_by_name}
                            </div>
                        </div>
                    `;
                });
            }
            
            document.getElementById('statsContent').innerHTML = html;
        }
        
        function openAssignModal(candidateId) {
            Swal.fire({
                title: 'Assign to Project',
                html: `
                    <select id="assignProject" class="swal2-input" style="width: 80%; display: block; margin: 10px auto;">
                        <option value="">Select Project...</option>
                        ${allProjects.map(p => `<option value="${p.id}">${p.project_name} (${p.project_code})</option>`).join('')}
                    </select>
                    <textarea id="assignNotes" class="swal2-textarea" placeholder="Internal notes..." style="width: 80%;"></textarea>
                `,
                showCancelButton: true,
                confirmButtonText: 'Assign',
                confirmButtonColor: '#3b82f6',
                preConfirm: () => {
                    const projectId = document.getElementById('assignProject').value;
                    const notes = document.getElementById('assignNotes').value;
                    
                    if (!projectId) {
                        Swal.showValidationMessage('Please select a project');
                        return false;
                    }
                    
                    return { projectId, notes, candidateId };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    assignToProject(result.value);
                }
            });
        }
        
        function assignToProject(data) {
            const formData = new FormData();
            formData.append('action', 'add_candidate_to_project');
            formData.append('project_id', data.projectId);
            formData.append('candidate_id', data.candidateId);
            formData.append('submission_source', 'Direct Assignment');
            formData.append('internal_notes', data.notes);
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    Swal.fire('Success!', result.message, 'success');
                    viewStats(data.candidateId); // Refresh stats
                } else {
                    Swal.fire('Error', result.message, 'error');
                }
            });
        }
        
        function exportResults() {
            document.getElementById('exportModal').classList.add('active');
            
            // Get current results for export
            const formData = new FormData();
            formData.append('action', 'search_candidates');
            formData.append('page', 1);
            formData.append('per_page', 1000); // Get all results
            
            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key]) {
                    formData.append(key, currentFilters[key]);
                }
            });
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    generateReport(data.candidates);
                }
            });
        }
        
        function generateReport(candidates) {
            const today = new Date().toLocaleDateString();
            
            let html = `
                <h2 style="text-align: center; margin-bottom: 24px;">Candidate Report</h2>
                <p style="text-align: center; color: #6b7280; margin-bottom: 24px;">Generated on ${today}</p>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th style="padding: 12px; border: 1px solid #e5e7eb; text-align: left;">ID</th>
                            <th style="padding: 12px; border: 1px solid #e5e7eb; text-align: left;">Name</th>
                            <th style="padding: 12px; border: 1px solid #e5e7eb; text-align: left;">Email</th>
                            <th style="padding: 12px; border: 1px solid #e5e7eb; text-align: left;">Location</th>
                            <th style="padding: 12px; border: 1px solid #e5e7eb; text-align: left;">Projects</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            candidates.forEach(c => {
                html += `
                    <tr>
                        <td style="padding: 12px; border: 1px solid #e5e7eb;">${c.id}</td>
                        <td style="padding: 12px; border: 1px solid #e5e7eb;">${c.full_name}</td>
                        <td style="padding: 12px; border: 1px solid #e5e7eb;">${c.email || 'N/A'}</td>
                        <td style="padding: 12px; border: 1px solid #e5e7eb;">${c.city ? c.city + (c.country ? ', ' + c.country : '') : 'N/A'}</td>
                        <td style="padding: 12px; border: 1px solid #e5e7eb;">${c.project_count || 0}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            html += `<p style="margin-top: 24px; text-align: center; color: #6b7280;">Total Candidates: ${candidates.length}</p>`;
            
            document.getElementById('reportContent').innerHTML = html;
        }
        
        function printReport() {
            window.print();
        }
        
        function emailReport() {
            const recipients = Array.from(document.getElementById('exportRecipients').selectedOptions).map(o => o.value);
            
            if (recipients.length === 0) {
                Swal.fire('Error', 'Please select at least one recipient', 'error');
                return;
            }
            
            const reportHtml = document.getElementById('reportContent').innerHTML;
            
            const formData = new FormData();
            formData.append('action', 'email_candidate_report');
            formData.append('recipients', JSON.stringify(recipients));
            formData.append('report_html', reportHtml);
            
            fetch('/management/ajax/ajax_wne.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', 'Report emailed successfully', 'success');
                    closeModal('exportModal');
                } else {
                    Swal.fire('Error', data.message || 'Failed to send email', 'error');
                }
            });
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
    </script>
</body>
</html>
