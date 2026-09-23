<?php
require_once 'config.php';
requireLogin();

// Check if user has admin access
$isSuperAdmin = isAdmin();
if (!$isSuperAdmin) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$pageTitle = 'WNE Jobs Management';
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            color: #1a202c;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
        }
        .container {
            max-width: 100%;
            width: 100%;
            padding: 32px;
        }
        .page-header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .page-header p {
            color: #6b7280;
            font-size: 15px;
        }
        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            background: #111827;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover {
            background: #374151;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #6b7280;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .btn-success {
            background: #059669;
        }
        .btn-success:hover {
            background: #047857;
        }
        .btn-danger {
            background: #dc2626;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
        }
        .tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            color: #6b7280;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .tab.active {
            color: #111827;
            border-bottom-color: #111827;
        }
        .tab:hover {
            color: #111827;
            background: #f9fafb;
        }
        .tab-content {
            display: none;
            min-height: 500px;
        }
        .tab-content.active {
            display: block;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
        }
        .stat-card .stat-change {
            font-size: 13px;
            color: #059669;
            margin-top: 8px;
        }
        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            padding: 24px;
            margin-bottom: 24px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        .card-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
        }
        .search-filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 16px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .search-box i {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        select {
            padding: 10px 16px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        th {
            text-align: left;
            padding: 12px;
            background: #f9fafb;
            color: #374151;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        td {
            padding: 16px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-paused {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-closed {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-submitted {
            background: #dbeafe;
            color: #1e40af;
        }
        .badge-under_review {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-shortlisted {
            background: #e0e7ff;
            color: #3730a3;
        }
        .badge-offered {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            padding: 6px 10px;
            background: transparent;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            color: #6b7280;
        }
        .btn-icon:hover {
            background: #f9fafb;
            border-color: #111827;
            color: #111827;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-content {
            background: white;
            border-radius: 8px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            padding: 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 16px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #374151;
        }
        .job-details {
            display: grid;
            gap: 16px;
        }
        .detail-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 12px;
        }
        .detail-label {
            font-weight: 600;
            color: #374151;
        }
        .detail-value {
            color: #1a202c;
        }
        .applicant-card {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .applicant-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        .applicant-name {
            font-weight: 600;
            font-size: 16px;
            color: #111827;
        }
        .applicant-email {
            color: #6b7280;
            font-size: 14px;
        }
        .company-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .company-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.2s;
        }
        .company-card:hover {
            border-color: #111827;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .company-name {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }
        .company-type {
            display: inline-block;
            padding: 4px 12px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 12px;
        }
        .company-info {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }
        .page-btn {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .page-btn.active {
            background: #111827;
            color: white;
            border-color: #111827;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #111827;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .expired-notice {
            background: #fee2e2;
            color: #991b1b;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-top: 8px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="container">
            <div class="page-header">
                <div>
                    <h1><?php echo $pageTitle; ?></h1>
                    <p>Manage jobs, applications, candidates, and companies</p>
                </div>
            </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Jobs</h3>
                <div class="stat-value" id="totalJobs">-</div>
            </div>
            <div class="stat-card">
                <h3>Active Jobs</h3>
                <div class="stat-value" id="activeJobs">-</div>
            </div>
            <div class="stat-card">
                <h3>Total Applications</h3>
                <div class="stat-value" id="totalApplications">-</div>
            </div>
            <div class="stat-card">
                <h3>Registered Companies</h3>
                <div class="stat-value" id="totalCompanies">-</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('jobs')">
                <i class="fas fa-briefcase"></i> Jobs
            </button>
            <button class="tab" onclick="switchTab('applications')">
                <i class="fas fa-file-alt"></i> Applications
            </button>
            <button class="tab" onclick="switchTab('candidates')">
                <i class="fas fa-users"></i> Candidates
            </button>
            <button class="tab" onclick="switchTab('companies')">
                <i class="fas fa-building"></i> Companies
            </button>
            <button class="tab" onclick="switchTab('import')">
                <i class="fas fa-file-import"></i> Import Jobs
            </button>
        </div>

        <!-- Jobs Tab -->
        <div id="tab-jobs" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h2>All Jobs</h2>
                </div>
                
                <div class="search-filter-bar">
                    <div class="search-box">
                        <input type="text" id="jobSearch" placeholder="Search by title, company, location...">
                        <i class="fas fa-search"></i>
                    </div>
                    <select id="jobStatusFilter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="closed">Closed</option>
                    </select>
                    <select id="jobCategoryFilter">
                        <option value="">All Categories</option>
                    </select>
                </div>

                <div class="table-container">
                    <table id="jobsTable">
                        <thead>
                            <tr>
                                <th onclick="sortJobs('id')" style="cursor: pointer; width: 80px;">
                                    ID <i class="fas fa-sort" id="sort-id"></i>
                                </th>
                                <th onclick="sortJobs('jobTitle')" style="cursor: pointer;">
                                    Job Title <i class="fas fa-sort" id="sort-jobTitle"></i>
                                </th>
                                <th onclick="sortJobs('location')" style="cursor: pointer;">
                                    Location <i class="fas fa-sort" id="sort-location"></i>
                                </th>
                                <th onclick="sortJobs('category')" style="cursor: pointer;">
                                    Category <i class="fas fa-sort" id="sort-category"></i>
                                </th>
                                <th onclick="sortJobs('salaryMin')" style="cursor: pointer;">
                                    Salary <i class="fas fa-sort" id="sort-salaryMin"></i>
                                </th>
                                <th onclick="sortJobs('status')" style="cursor: pointer;">
                                    Status <i class="fas fa-sort" id="sort-status"></i>
                                </th>
                                <th>Applications</th>
                                <th onclick="sortJobs('expiry_date')" style="cursor: pointer;">
                                    Expiry Date <i class="fas fa-sort" id="sort-expiry_date"></i>
                                </th>
                                <th onclick="sortJobs('date_insert')" style="cursor: pointer;">
                                    Date Added <i class="fas fa-sort" id="sort-date_insert"></i>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="jobsTableBody">
                            <tr>
                                <td colspan="10" class="loading">
                                    <div class="spinner"></div>
                                    Loading jobs...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div id="jobsPagination" class="pagination"></div>
            </div>
        </div>

        <!-- Applications Tab -->
        <div id="tab-applications" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2>All Applications</h2>
                </div>
                
                <div class="search-filter-bar">
                    <div class="search-box">
                        <input type="text" id="applicationSearch" placeholder="Search by candidate name, job title...">
                        <i class="fas fa-search"></i>
                    </div>
                    <select id="applicationStatusFilter">
                        <option value="">All Status</option>
                        <option value="submitted">Submitted</option>
                        <option value="under_review">Under Review</option>
                        <option value="shortlisted">Shortlisted</option>
                        <option value="interview_scheduled">Interview Scheduled</option>
                        <option value="offered">Offered</option>
                        <option value="rejected">Rejected</option>
                        <option value="withdrawn">Withdrawn</option>
                    </select>
                </div>

                <div class="table-container">
                    <table id="applicationsTable">
                        <thead>
                            <tr>
                                <th>Candidate</th>
                                <th>Job Title</th>
                                <th>Applied Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="applicationsTableBody">
                            <tr>
                                <td colspan="5" class="loading">
                                    <div class="spinner"></div>
                                    Loading applications...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Candidates Tab -->
        <div id="tab-candidates" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2>All Candidates</h2>
                </div>
                
                <div class="search-filter-bar">
                    <div class="search-box">
                        <input type="text" id="candidateSearch" placeholder="Search by name, email, skills...">
                        <i class="fas fa-search"></i>
                    </div>
                </div>

                <div class="table-container">
                    <table id="candidatesTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Job Title</th>
                                <th>Applications</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="candidatesTableBody">
                            <tr>
                                <td colspan="6" class="loading">
                                    <div class="spinner"></div>
                                    Loading candidates...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Companies Tab -->
        <div id="tab-companies" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2>Registered Companies</h2>
                </div>
                
                <div class="search-filter-bar">
                    <div class="search-box">
                        <input type="text" id="companySearch" placeholder="Search by company name, email...">
                        <i class="fas fa-search"></i>
                    </div>
                    <select id="companyTypeFilter">
                        <option value="">All Types</option>
                        <option value="employer">Employer</option>
                        <option value="recruiter">Recruiter</option>
                    </select>
                </div>

                <div class="company-grid" id="companyGrid">
                    <div class="loading">
                        <div class="spinner"></div>
                        Loading companies...
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Jobs Tab -->
        <div id="tab-import" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2>Import Jobs from JSON</h2>
                </div>
                
                <div style="padding: 40px; text-align: center;">
                    <div style="margin-bottom: 32px;">
                        <i class="fas fa-file-import" style="font-size: 64px; color: #6b7280; margin-bottom: 24px;"></i>
                        <h3 style="font-size: 24px; font-weight: 600; margin-bottom: 16px; color: #111827;">Import Jobs from JSON File</h3>
                        <p style="color: #6b7280; font-size: 16px; margin-bottom: 8px;">This will import all jobs from the jobposts.json file into the database.</p>
                        <p style="color: #6b7280; font-size: 14px; margin-bottom: 32px;">
                            <strong>File Location:</strong><br>
                            <code style="background: #f3f4f6; padding: 8px 16px; border-radius: 6px; display: inline-block; margin-top: 8px; font-size: 13px;">/home/wneuser/public_html/job_scraping_json/jobposts.json</code>
                        </p>
                    </div>
                    
                    <button class="btn btn-success" onclick="importJobsFromJSON()" style="padding: 16px 32px; font-size: 16px;">
                        <i class="fas fa-file-import"></i> Import Jobs from JSON
                    </button>
                    
                    <div id="importStatus" style="margin-top: 32px; display: none;">
                        <div class="spinner"></div>
                        <p style="color: #6b7280; margin-top: 16px;">Importing jobs...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Job Details Modal -->
    <div id="jobDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Job Details</h2>
                <button class="modal-close" onclick="closeModal('jobDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="jobDetailsContent">
                Loading...
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('jobDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Job Modal -->
    <div id="editJobModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Job</h2>
                <button class="modal-close" onclick="closeModal('editJobModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editJobForm">
                    <input type="hidden" id="editJobId" name="job_id">
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editJobStatus" required>
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="editJobExpiry">
                    </div>

                    <div class="form-group">
                        <label>Job Title</label>
                        <input type="text" name="job_title" id="editJobTitle" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" id="editJobLocation">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" id="editJobCategory">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Salary Min</label>
                            <input type="number" name="salary_min" id="editJobSalaryMin">
                        </div>
                        <div class="form-group">
                            <label>Salary Max</label>
                            <input type="number" name="salary_max" id="editJobSalaryMax">
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <input type="text" name="salary_currency" id="editJobCurrency" maxlength="10">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Description</label>
                        <textarea name="job_description" id="editJobDescription"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('editJobModal')">Cancel</button>
                <button class="btn btn-success" onclick="saveJob()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Application Details Modal -->
    <div id="applicationDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Application Details</h2>
                <button class="modal-close" onclick="closeModal('applicationDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="applicationDetailsContent">
                Loading...
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('applicationDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Candidate Details Modal -->
    <div id="candidateDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Candidate Details</h2>
                <button class="modal-close" onclick="closeModal('candidateDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="candidateDetailsContent">
                Loading...
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('candidateDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'jobs';
        let jobsData = [];
        let applicationsData = [];
        let candidatesData = [];
        let companiesData = [];
        
        // Pagination state
        let currentJobsPage = 1;
        let totalJobsPages = 1;
        let currentOrderBy = 'date_insert';
        let currentOrderDir = 'DESC';

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadJobs();
            loadCategories();
            setupSearchFilters();
        });

        // Tab switching
        function switchTab(tabName) {
            currentTab = tabName;
            
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.closest('.tab').classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Load data for the active tab
            switch(tabName) {
                case 'jobs':
                    if (jobsData.length === 0) loadJobs();
                    break;
                case 'applications':
                    if (applicationsData.length === 0) loadApplications();
                    break;
                case 'candidates':
                    if (candidatesData.length === 0) loadCandidates();
                    break;
                case 'companies':
                    if (companiesData.length === 0) loadCompanies();
                    break;
            }
        }

        // Load statistics
        function loadStats() {
            fetch('/management/ajax/ajax_wne_jobs.php?action=get_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalJobs').textContent = data.stats.total_jobs || 0;
                        document.getElementById('activeJobs').textContent = data.stats.active_jobs || 0;
                        document.getElementById('totalApplications').textContent = data.stats.total_applications || 0;
                        document.getElementById('totalCompanies').textContent = data.stats.total_companies || 0;
                    }
                })
                .catch(error => console.error('Error loading stats:', error));
        }

        // Load jobs with pagination
        function loadJobs(page = 1, orderBy = 'date_insert', orderDir = 'DESC') {
            const searchTerm = document.getElementById('jobSearch').value;
            const statusFilter = document.getElementById('jobStatusFilter').value;
            const categoryFilter = document.getElementById('jobCategoryFilter').value;
            
            const params = new URLSearchParams({
                action: 'get_jobs',
                page: page,
                per_page: 20,
                order_by: orderBy,
                order_dir: orderDir,
                search: searchTerm,
                status: statusFilter,
                category: categoryFilter
            });
            
            fetch(`/management/ajax/ajax_wne_jobs.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        jobsData = data.jobs;
                        currentJobsPage = data.page;
                        totalJobsPages = data.total_pages;
                        renderJobs(jobsData);
                        renderJobsPagination();
                    } else {
                        showError('Failed to load jobs');
                    }
                })
                .catch(error => {
                    console.error('Error loading jobs:', error);
                    showError('Error loading jobs');
                });
        }

        // Render jobs table
        function renderJobs(jobs) {
            const tbody = document.getElementById('jobsTableBody');
            
            if (jobs.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="empty-state">
                            <i class="fas fa-briefcase"></i>
                            <h3>No jobs found</h3>
                            <p>Import jobs from JSON to get started</p>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = jobs.map(job => {
                const salary = job.salaryCurrency && job.salaryMin && job.salaryMax 
                    ? `${job.salaryCurrency} ${parseInt(job.salaryMin).toLocaleString()} - ${parseInt(job.salaryMax).toLocaleString()}`
                    : 'Not specified';
                
                const isExpired = job.expiry_date && new Date(job.expiry_date) < new Date();
                const expiryDisplay = job.expiry_date 
                    ? `<span style="${isExpired ? 'color: #dc2626;' : ''}">${formatDate(job.expiry_date)}</span>`
                    : '<span style="color: #6b7280;">No expiry</span>';

                return `
                    <tr>
                        <td><strong>#${job.id}</strong></td>
                        <td><strong>${escapeHtml(job.jobTitle || 'Untitled')}</strong></td>
                        <td>${escapeHtml(job.location || '-')}</td>
                        <td>${escapeHtml(job.category || '-')}</td>
                        <td>${salary}</td>
                        <td><span class="badge badge-${job.status}">${capitalizeFirst(job.status)}</span></td>
                        <td>${job.application_count || 0}</td>
                        <td>${expiryDisplay}</td>
                        <td>${formatDate(job.date_insert)}</td>
                        <td class="action-buttons">
                            <button class="btn-icon" onclick="viewJob(${job.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-icon" onclick="editJob(${job.id})" title="Edit Job">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-icon" onclick="viewJobApplications(${job.id})" title="View Applications">
                                <i class="fas fa-users"></i>
                            </button>
                            <button class="btn-icon" onclick="deleteJob(${job.id})" title="Delete Job">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Sort jobs by column
        function sortJobs(column) {
            // Toggle sort direction if same column, otherwise default to DESC
            if (currentOrderBy === column) {
                currentOrderDir = currentOrderDir === 'DESC' ? 'ASC' : 'DESC';
            } else {
                currentOrderBy = column;
                currentOrderDir = 'DESC';
            }
            
            // Update sort icons
            document.querySelectorAll('[id^="sort-"]').forEach(icon => {
                icon.className = 'fas fa-sort';
            });
            
            const icon = document.getElementById('sort-' + column);
            if (icon) {
                icon.className = currentOrderDir === 'DESC' ? 'fas fa-sort-down' : 'fas fa-sort-up';
            }
            
            // Reload jobs with new sort
            loadJobs(1, currentOrderBy, currentOrderDir);
        }

        // Render pagination controls
        function renderJobsPagination() {
            const container = document.getElementById('jobsPagination');
            
            if (totalJobsPages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            let html = '';
            
            // Previous button
            if (currentJobsPage > 1) {
                html += `<button class="page-btn" onclick="loadJobs(${currentJobsPage - 1}, '${currentOrderBy}', '${currentOrderDir}')">
                    <i class="fas fa-chevron-left"></i>
                </button>`;
            }
            
            // Page numbers
            const startPage = Math.max(1, currentJobsPage - 2);
            const endPage = Math.min(totalJobsPages, currentJobsPage + 2);
            
            if (startPage > 1) {
                html += `<button class="page-btn" onclick="loadJobs(1, '${currentOrderBy}', '${currentOrderDir}')">1</button>`;
                if (startPage > 2) {
                    html += `<span style="padding: 8px;">...</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="page-btn ${i === currentJobsPage ? 'active' : ''}" 
                         onclick="loadJobs(${i}, '${currentOrderBy}', '${currentOrderDir}')">${i}</button>`;
            }
            
            if (endPage < totalJobsPages) {
                if (endPage < totalJobsPages - 1) {
                    html += `<span style="padding: 8px;">...</span>`;
                }
                html += `<button class="page-btn" onclick="loadJobs(${totalJobsPages}, '${currentOrderBy}', '${currentOrderDir}')">${totalJobsPages}</button>`;
            }
            
            // Next button
            if (currentJobsPage < totalJobsPages) {
                html += `<button class="page-btn" onclick="loadJobs(${currentJobsPage + 1}, '${currentOrderBy}', '${currentOrderDir}')">
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            }
            
            container.innerHTML = html;
        }

        // Load applications
        function loadApplications() {
            fetch('/management/ajax/ajax_wne_jobs.php?action=get_applications')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        applicationsData = data.applications;
                        renderApplications(applicationsData);
                    }
                })
                .catch(error => console.error('Error loading applications:', error));
        }

        // Render applications table
        function renderApplications(applications) {
            const tbody = document.getElementById('applicationsTableBody');
            
            if (applications.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No applications found</h3>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = applications.map(app => `
                <tr>
                    <td>
                        <strong>${escapeHtml(app.candidate_name || 'Unknown')}</strong><br>
                        <small style="color: #6b7280;">${escapeHtml(app.candidate_email || '')}</small>
                    </td>
                    <td>${escapeHtml(app.job_title || 'Unknown Job')}</td>
                    <td>${formatDate(app.date_applied)}</td>
                    <td><span class="badge badge-${app.status}">${app.status.replace('_', ' ')}</span></td>
                    <td class="action-buttons">
                        <button class="btn-icon" onclick="viewApplication(${app.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-icon" onclick="updateApplicationStatus(${app.id})" title="Update Status">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Load candidates
        function loadCandidates() {
            fetch('/management/ajax/ajax_wne_jobs.php?action=get_candidates')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        candidatesData = data.candidates;
                        renderCandidates(candidatesData);
                    }
                })
                .catch(error => console.error('Error loading candidates:', error));
        }

        // Render candidates table
        function renderCandidates(candidates) {
            const tbody = document.getElementById('candidatesTableBody');
            
            if (candidates.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No candidates found</h3>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = candidates.map(candidate => `
                <tr>
                    <td><strong>${escapeHtml(candidate.full_name || candidate.email || 'Unknown')}</strong></td>
                    <td>${escapeHtml(candidate.email || '-')}</td>
                    <td>${escapeHtml(candidate.phone || '-')}</td>
                    <td>${escapeHtml(candidate.title || '-')}</td>
                    <td>${candidate.application_count || 0}</td>
                    <td class="action-buttons">
                        <button class="btn-icon" onclick="viewCandidate(${candidate.id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-icon" onclick="viewCandidateApplications(${candidate.id})" title="View Applications">
                            <i class="fas fa-file-alt"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Load companies
        function loadCompanies() {
            fetch('/management/ajax/ajax_wne_jobs.php?action=get_companies')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        companiesData = data.companies;
                        renderCompanies(companiesData);
                    }
                })
                .catch(error => console.error('Error loading companies:', error));
        }

        // Render companies
        function renderCompanies(companies) {
            const grid = document.getElementById('companyGrid');
            
            if (companies.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-building"></i>
                        <h3>No companies found</h3>
                    </div>
                `;
                return;
            }

            grid.innerHTML = companies.map(company => `
                <div class="company-card">
                    <div class="company-name">${escapeHtml(company.company || 'Unknown Company')}</div>
                    <div class="company-type">${capitalizeFirst(company.user_type)}</div>
                    <div class="company-info">
                        <i class="fas fa-envelope"></i> ${escapeHtml(company.email || '-')}
                    </div>
                    <div class="company-info">
                        <i class="fas fa-phone"></i> ${escapeHtml(company.phone || '-')}
                    </div>
                    <div class="company-info">
                        <i class="fas fa-calendar"></i> Registered: ${formatDate(company.date_registered)}
                    </div>
                    <div class="company-info">
                        <i class="fas fa-sign-in-alt"></i> Last login: ${company.last_login ? formatDate(company.last_login) : 'Never'}
                    </div>
                </div>
            `).join('');
        }

        // Load categories for filter
        function loadCategories() {
            fetch('/management/ajax/ajax_wne_jobs.php?action=get_categories')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.categories) {
                        const select = document.getElementById('jobCategoryFilter');
                        data.categories.forEach(cat => {
                            if (cat) {
                                const option = document.createElement('option');
                                option.value = cat;
                                option.textContent = cat;
                                select.appendChild(option);
                            }
                        });
                    }
                });
        }

        // Setup search and filter listeners
        function setupSearchFilters() {
            // Jobs search - reload with pagination
            document.getElementById('jobSearch').addEventListener('input', function() {
                loadJobs(1, currentOrderBy, currentOrderDir);
            });
            document.getElementById('jobStatusFilter').addEventListener('change', function() {
                loadJobs(1, currentOrderBy, currentOrderDir);
            });
            document.getElementById('jobCategoryFilter').addEventListener('change', function() {
                loadJobs(1, currentOrderBy, currentOrderDir);
            });

            // Applications search
            document.getElementById('applicationSearch').addEventListener('input', function() {
                filterApplications();
            });
            document.getElementById('applicationStatusFilter').addEventListener('change', function() {
                filterApplications();
            });

            // Candidates search
            document.getElementById('candidateSearch').addEventListener('input', function() {
                filterCandidates();
            });

            // Companies search
            document.getElementById('companySearch').addEventListener('input', function() {
                filterCompanies();
            });
            document.getElementById('companyTypeFilter').addEventListener('change', function() {
                filterCompanies();
            });
        }

        // Filter applications
        function filterApplications() {
            const searchTerm = document.getElementById('applicationSearch').value.toLowerCase();
            const statusFilter = document.getElementById('applicationStatusFilter').value;

            const filtered = applicationsData.filter(app => {
                const matchesSearch = !searchTerm ||
                    (app.candidate_name && app.candidate_name.toLowerCase().includes(searchTerm)) ||
                    (app.job_title && app.job_title.toLowerCase().includes(searchTerm));
                
                const matchesStatus = !statusFilter || app.status === statusFilter;

                return matchesSearch && matchesStatus;
            });

            renderApplications(filtered);
        }

        // Filter candidates
        function filterCandidates() {
            const searchTerm = document.getElementById('candidateSearch').value.toLowerCase();

            const filtered = candidatesData.filter(candidate => {
                return !searchTerm ||
                    (candidate.full_name && candidate.full_name.toLowerCase().includes(searchTerm)) ||
                    (candidate.email && candidate.email.toLowerCase().includes(searchTerm));
            });

            renderCandidates(filtered);
        }

        // Filter companies
        function filterCompanies() {
            const searchTerm = document.getElementById('companySearch').value.toLowerCase();
            const typeFilter = document.getElementById('companyTypeFilter').value;

            const filtered = companiesData.filter(company => {
                const matchesSearch = !searchTerm ||
                    (company.company && company.company.toLowerCase().includes(searchTerm)) ||
                    (company.email && company.email.toLowerCase().includes(searchTerm));
                
                const matchesType = !typeFilter || company.user_type === typeFilter;

                return matchesSearch && matchesType;
            });

            renderCompanies(filtered);
        }

        // View job details
        function viewJob(jobId) {
            fetch(`/management/ajax/ajax_wne_jobs.php?action=get_job_details&job_id=${jobId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const job = data.job;
                        const applications = data.applications || [];
                        
                        const salary = job.salaryCurrency && job.salaryMin && job.salaryMax 
                            ? `${job.salaryCurrency} ${parseInt(job.salaryMin).toLocaleString()} - ${parseInt(job.salaryMax).toLocaleString()}`
                            : 'Not specified';

                        let html = `
                            <div class="job-details">
                                <div class="detail-row">
                                    <div class="detail-label">Job Title:</div>
                                    <div class="detail-value">${escapeHtml(job.jobTitle || '-')}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Location:</div>
                                    <div class="detail-value">${escapeHtml(job.location || '-')}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Category:</div>
                                    <div class="detail-value">${escapeHtml(job.category || '-')}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Salary Range:</div>
                                    <div class="detail-value">${salary}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Work Type:</div>
                                    <div class="detail-value">${escapeHtml(job.workType || '-')}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Status:</div>
                                    <div class="detail-value"><span class="badge badge-${job.status}">${capitalizeFirst(job.status)}</span></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Expiry Date:</div>
                                    <div class="detail-value">${job.expiry_date ? formatDate(job.expiry_date) : 'No expiry set'}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Posted Date:</div>
                                    <div class="detail-value">${formatDate(job.date_insert)}</div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">Description:</div>
                                    <div class="detail-value">${escapeHtml(job.jobDescription || 'No description')}</div>
                                </div>
                                ${job.qualifications ? `
                                <div class="detail-row">
                                    <div class="detail-label">Qualifications:</div>
                                    <div class="detail-value">${escapeHtml(job.qualifications).replace(/;;/g, '<br>')}</div>
                                </div>
                                ` : ''}
                                ${job.skillsetRequired ? `
                                <div class="detail-row">
                                    <div class="detail-label">Skills Required:</div>
                                    <div class="detail-value">${escapeHtml(job.skillsetRequired).replace(/;;/g, '<br>')}</div>
                                </div>
                                ` : ''}
                            </div>
                            
                            <h3 style="margin-top: 24px; margin-bottom: 16px; font-size: 18px; font-weight: 600;">Applications (${applications.length})</h3>
                        `;

                        if (applications.length > 0) {
                            applications.forEach(app => {
                                html += `
                                    <div class="applicant-card">
                                        <div class="applicant-header">
                                            <div>
                                                <div class="applicant-name">${escapeHtml(app.candidate_name || 'Unknown')}</div>
                                                <div class="applicant-email">${escapeHtml(app.candidate_email || '')}</div>
                                            </div>
                                            <span class="badge badge-${app.status}">${app.status.replace('_', ' ')}</span>
                                        </div>
                                        <div style="color: #6b7280; font-size: 14px;">
                                            Applied: ${formatDate(app.date_applied)}
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html += `<div style="color: #6b7280; text-align: center; padding: 20px;">No applications yet</div>`;
                        }

                        document.getElementById('jobDetailsContent').innerHTML = html;
                        openModal('jobDetailsModal');
                    }
                })
                .catch(error => console.error('Error loading job details:', error));
        }

        // Edit job
        function editJob(jobId) {
            fetch(`/management/ajax/ajax_wne_jobs.php?action=get_job_details&job_id=${jobId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const job = data.job;
                        document.getElementById('editJobId').value = job.id;
                        document.getElementById('editJobStatus').value = job.status;
                        document.getElementById('editJobExpiry').value = job.expiry_date || '';
                        document.getElementById('editJobTitle').value = job.jobTitle || '';
                        document.getElementById('editJobLocation').value = job.location || '';
                        document.getElementById('editJobCategory').value = job.category || '';
                        document.getElementById('editJobSalaryMin').value = job.salaryMin || '';
                        document.getElementById('editJobSalaryMax').value = job.salaryMax || '';
                        document.getElementById('editJobCurrency').value = job.salaryCurrency || '';
                        document.getElementById('editJobDescription').value = job.jobDescription || '';
                        
                        openModal('editJobModal');
                    }
                })
                .catch(error => console.error('Error loading job:', error));
        }

        // Save job
        function saveJob() {
            const form = document.getElementById('editJobForm');
            const formData = new FormData(form);
            formData.append('action', 'update_job');

            fetch('/management/ajax/ajax_wne_jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Job updated successfully');
                    closeModal('editJobModal');
                    loadJobs();
                    loadStats();
                } else {
                    showError(data.message || 'Failed to update job');
                }
            })
            .catch(error => {
                console.error('Error saving job:', error);
                showError('Error saving job');
            });
        }

        // Delete job
        function deleteJob(jobId) {
            Swal.fire({
                title: 'Delete Job?',
                text: 'This will also delete all applications for this job. This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/management/ajax/ajax_wne_jobs.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=delete_job&job_id=${jobId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Job deleted successfully');
                            loadJobs(currentJobsPage, currentOrderBy, currentOrderDir);
                            loadStats();
                        } else {
                            showError(data.message || 'Failed to delete job');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting job:', error);
                        showError('Error deleting job');
                    });
                }
            });
        }

        // View job applications
        function viewJobApplications(jobId) {
            switchTab('applications');
            loadApplications();
            // TODO: Filter by job_id
        }

        // View candidate applications
        function viewCandidateApplications(candidateId) {
            switchTab('applications');
            loadApplications();
            // TODO: Filter by candidate_id
        }

        // Import jobs from JSON
        function importJobsFromJSON() {
            const jsonPath = '/home/wneuser/public_html/job_scraping_json/jobposts.json';
            
            Swal.fire({
                title: 'Import Jobs from JSON?',
                html: `
                    <p style="margin-bottom: 16px;">This will import all jobs from the JSON file.</p>
                    <div style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-bottom: 16px;">
                        <strong>File:</strong><br>
                        <code style="font-size: 13px; word-break: break-all;">${jsonPath}</code>
                    </div>
                    <p style="color: #6b7280; font-size: 14px;">New jobs will be added to the database.</p>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, import jobs'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Importing...',
                        text: 'Please wait while we import the jobs',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    fetch('/management/ajax/ajax_wne_jobs.php?action=import_jobs_json')
                        .then(response => response.json())
                        .then(data => {
                            Swal.close();
                            if (data.success) {
                                showSuccess(`Successfully imported ${data.count || 0} jobs`);
                                loadJobs(1, currentOrderBy, currentOrderDir);
                                loadStats();
                            } else {
                                showError(data.message || 'Failed to import jobs');
                            }
                        })
                        .catch(error => {
                            Swal.close();
                            console.error('Error importing jobs:', error);
                            showError('Error importing jobs');
                        });
                }
            });
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Utility functions
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function capitalizeFirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function showSuccess(message) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: message,
                timer: 3000,
                showConfirmButton: false
            });
        }

        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</div>
<!-- End of main-content -->
</body>
</html>
