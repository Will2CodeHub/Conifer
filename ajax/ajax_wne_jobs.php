<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$conn = getDBConnection();

try {
    switch ($action) {
        case 'get_stats':
            getStats($conn);
            break;
            
        case 'get_jobs':
            getJobs($conn);
            break;
            
        case 'get_job_details':
            getJobDetails($conn);
            break;
            
        case 'update_job':
            updateJob($conn);
            break;
            
        case 'delete_job':
            deleteJob($conn);
            break;
            
        case 'get_applications':
            getApplications($conn);
            break;
            
        case 'get_candidates':
            getCandidates($conn);
            break;
            
        case 'get_companies':
            getCompanies($conn);
            break;
            
        case 'get_categories':
            getCategories($conn);
            break;
            
        case 'import_jobs_json':
            importJobsFromJSON($conn);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getStats($conn) {
    $stats = [];
    
    // Total jobs
    $result = $conn->query("SELECT COUNT(*) as count FROM ai_jobs");
    $stats['total_jobs'] = $result->fetch_assoc()['count'];
    
    // Active jobs
    $result = $conn->query("SELECT COUNT(*) as count FROM ai_jobs WHERE status = 'active'");
    $stats['active_jobs'] = $result->fetch_assoc()['count'];
    
    // Total applications
    $result = $conn->query("SELECT COUNT(*) as count FROM wne_applications");
    $stats['total_applications'] = $result->fetch_assoc()['count'];
    
    // Total companies (wne_users who are employers or recruiters)
    $result = $conn->query("SELECT COUNT(*) as count FROM wne_users WHERE user_type IN ('employer', 'recruiter')");
    $stats['total_companies'] = $result->fetch_assoc()['count'];
    
    echo json_encode(['success' => true, 'stats' => $stats]);
}

function getJobs($conn) {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
    $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'date_insert';
    $orderDir = isset($_GET['order_dir']) ? $_GET['order_dir'] : 'DESC';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
    $categoryFilter = isset($_GET['category']) ? $_GET['category'] : '';
    
    // Validate order direction
    $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
    
    // Validate order by column
    $allowedColumns = ['id', 'jobTitle', 'location', 'category', 'salaryMin', 'status', 'expiry_date', 'date_insert'];
    if (!in_array($orderBy, $allowedColumns)) {
        $orderBy = 'date_insert';
    }
    
    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $whereConditions[] = "(j.jobTitle LIKE ? OR j.location LIKE ? OR j.category LIKE ? OR j.company LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'ssss';
    }
    
    if (!empty($statusFilter)) {
        $whereConditions[] = "j.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    
    if (!empty($categoryFilter)) {
        $whereConditions[] = "j.category = ?";
        $params[] = $categoryFilter;
        $types .= 's';
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(DISTINCT j.id) as total FROM ai_jobs j $whereClause";
    if (!empty($params)) {
        $stmt = $conn->prepare($countQuery);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $totalRows = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $totalRows = $conn->query($countQuery)->fetch_assoc()['total'];
    }
    
    $totalPages = ceil($totalRows / $perPage);
    $offset = ($page - 1) * $perPage;
    
    // Get jobs with pagination
    $query = "
        SELECT 
            j.*,
            COUNT(DISTINCT a.id) as application_count
        FROM ai_jobs j
        LEFT JOIN wne_applications a ON j.id = a.job_id
        $whereClause
        GROUP BY j.id
        ORDER BY j.$orderBy $orderDir
        LIMIT ? OFFSET ?
    ";
    
    if (!empty($params)) {
        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
    
    echo json_encode([
        'success' => true,
        'jobs' => $jobs,
        'page' => $page,
        'per_page' => $perPage,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages
    ]);
}

function getJobDetails($conn) {
    $jobId = intval($_GET['job_id']);
    
    // Get job details
    $stmt = $conn->prepare("SELECT * FROM ai_jobs WHERE id = ?");
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$job) {
        echo json_encode(['success' => false, 'message' => 'Job not found']);
        return;
    }
    
    // Get applications for this job
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            u.email as candidate_email,
            u.phone as candidate_phone,
            u.company as candidate_name
        FROM wne_applications a
        LEFT JOIN wne_users u ON a.wne_user_id = u.id
        WHERE a.job_id = ?
        ORDER BY a.date_applied DESC
    ");
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'job' => $job,
        'applications' => $applications
    ]);
}

function updateJob($conn) {
    $jobId = intval($_POST['job_id']);
    
    // Check if expiry_date column exists, if not, add it
    $result = $conn->query("SHOW COLUMNS FROM ai_jobs LIKE 'expiry_date'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE ai_jobs ADD COLUMN expiry_date DATE NULL AFTER status");
    }
    
    // Build dynamic update query based on provided fields
    $updateFields = [];
    $params = [];
    $types = '';
    
    // Always update status
    if (isset($_POST['status'])) {
        $updateFields[] = "status = ?";
        $params[] = $_POST['status'];
        $types .= 's';
    }
    
    // Update expiry_date (can be set to null)
    if (isset($_POST['expiry_date'])) {
        $updateFields[] = "expiry_date = ?";
        $params[] = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $types .= 's';
    }
    
    // Update jobTitle if provided
    if (isset($_POST['job_title']) && trim($_POST['job_title']) !== '') {
        $updateFields[] = "jobTitle = ?";
        $params[] = $_POST['job_title'];
        $types .= 's';
    }
    
    // Update location only if provided and not empty
    if (isset($_POST['location']) && trim($_POST['location']) !== '') {
        $updateFields[] = "location = ?";
        $params[] = $_POST['location'];
        $types .= 's';
    }
    
    // Update category only if provided and not empty
    if (isset($_POST['category']) && trim($_POST['category']) !== '') {
        $updateFields[] = "category = ?";
        $params[] = $_POST['category'];
        $types .= 's';
    }
    
    // Update salary fields only if provided
    if (isset($_POST['salary_min']) && trim($_POST['salary_min']) !== '') {
        $updateFields[] = "salaryMin = ?";
        $params[] = intval($_POST['salary_min']);
        $types .= 'i';
    }
    
    if (isset($_POST['salary_max']) && trim($_POST['salary_max']) !== '') {
        $updateFields[] = "salaryMax = ?";
        $params[] = intval($_POST['salary_max']);
        $types .= 'i';
    }
    
    if (isset($_POST['salary_currency']) && trim($_POST['salary_currency']) !== '') {
        $updateFields[] = "salaryCurrency = ?";
        $params[] = $_POST['salary_currency'];
        $types .= 's';
    }
    
    // Update job description only if provided
    if (isset($_POST['job_description']) && trim($_POST['job_description']) !== '') {
        $updateFields[] = "jobDescription = ?";
        $params[] = $_POST['job_description'];
        $types .= 's';
    }
    
    if (empty($updateFields)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }
    
    // Add job_id to params
    $params[] = $jobId;
    $types .= 'i';
    
    $sql = "UPDATE ai_jobs SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Job updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update job: ' . $stmt->error]);
    }
    
    $stmt->close();
}

function deleteJob($conn) {
    $jobId = intval($_POST['job_id']);
    
    // Delete applications first (foreign key constraint)
    $stmt = $conn->prepare("DELETE FROM wne_applications WHERE job_id = ?");
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $stmt->close();
    
    // Delete the job
    $stmt = $conn->prepare("DELETE FROM ai_jobs WHERE id = ?");
    $stmt->bind_param("i", $jobId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Job deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete job: ' . $stmt->error]);
    }
    
    $stmt->close();
}

function getApplications($conn) {
    $query = "
        SELECT 
            a.*,
            u.email as candidate_email,
            u.phone as candidate_phone,
            u.company as candidate_name,
            j.jobTitle as job_title
        FROM wne_applications a
        LEFT JOIN wne_users u ON a.wne_user_id = u.id
        LEFT JOIN ai_jobs j ON a.job_id = j.id
        ORDER BY a.date_applied DESC
    ";
    
    $result = $conn->query($query);
    $applications = [];
    
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    
    echo json_encode(['success' => true, 'applications' => $applications]);
}

function getCandidates($conn) {
    $query = "
        SELECT 
            u.id,
            u.email,
            u.phone,
            u.company as full_name,
            u.date_registered,
            u.last_login,
            u.active,
            p.title,
            p.bio,
            p.skills,
            p.years_experience,
            p.education_level,
            p.availability,
            p.expected_salary_min,
            p.expected_salary_max,
            p.salary_currency,
            p.work_authorization,
            p.willing_to_relocate,
            p.remote_only,
            p.profile_status,
            COUNT(DISTINCT a.id) as application_count
        FROM wne_users u
        LEFT JOIN wne_candidate_profiles p ON u.id = p.wne_user_id
        LEFT JOIN wne_applications a ON u.id = a.wne_user_id
        WHERE u.user_type = 'candidate'
        GROUP BY u.id
        ORDER BY u.date_registered DESC
    ";
    
    $result = $conn->query($query);
    $candidates = [];
    
    while ($row = $result->fetch_assoc()) {
        $candidates[] = $row;
    }
    
    echo json_encode(['success' => true, 'candidates' => $candidates]);
}

function getCompanies($conn) {
    $query = "
        SELECT *
        FROM wne_users
        WHERE user_type IN ('employer', 'recruiter')
        ORDER BY date_registered DESC
    ";
    
    $result = $conn->query($query);
    $companies = [];
    
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    echo json_encode(['success' => true, 'companies' => $companies]);
}

function getCategories($conn) {
    $query = "SELECT DISTINCT category FROM ai_jobs WHERE category IS NOT NULL AND category != '' ORDER BY category";
    $result = $conn->query($query);
    $categories = [];
    
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    
    echo json_encode(['success' => true, 'categories' => $categories]);
}

function importJobsFromJSON($conn) {
    // Path to the JSON file (same as in job_scraped_cron.php)
    $jsonPath = '/home/wneuser/public_html/job_scraping_json/jobposts.json';
    
    if (!file_exists($jsonPath)) {
        echo json_encode([
            'success' => false, 
            'message' => 'JSON file not found at: ' . $jsonPath
        ]);
        return;
    }
    
    $jsonData = file_get_contents($jsonPath);
    $jobs = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'message' => 'JSON decode error: ' . json_last_error_msg()
        ]);
        return;
    }
    
    $insertedCount = 0;
    $errors = [];
    
    // Ensure expiry_date column exists
    $result = $conn->query("SHOW COLUMNS FROM ai_jobs LIKE 'expiry_date'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE ai_jobs ADD COLUMN expiry_date DATE NULL AFTER status");
    }
    
    foreach ($jobs as $job) {
        try {
            // Convert arrays to strings for storage
            $qualifications = implode(';; ', isset($job['qualifications']) ? $job['qualifications'] : []);
            $languagesNeeded = implode(';; ', isset($job['languagesNeeded']) ? $job['languagesNeeded'] : []);
            $spokenLanguages = implode(';; ', isset($job['spokenLanguages']) ? $job['spokenLanguages'] : []);
            $tasks = implode(';; ', isset($job['jobResponsibilities']['tasks']) ? $job['jobResponsibilities']['tasks'] : []);
            $skillset = implode(';; ', isset($job['skillsetRequired']) ? $job['skillsetRequired'] : []);
            $softskills = implode(';; ', isset($job['softSkillsRequired']) ? $job['softSkillsRequired'] : []);
            $benefits = implode(';; ', isset($job['jobBenefits']) ? $job['jobBenefits'] : []);
            $opportunitiesForAdvancement = implode(', ', isset($job['opportunitiesForAdvancement']) ? $job['opportunitiesForAdvancement'] : []);
            
            if (is_array($job['projectTypes'])) {
                $projectTypes = implode(';; ', $job['projectTypes']);
            } else {
                $projectTypes = $job['projectTypes'];
            }
            
            $visaSponsorship = (isset($job['visaSponsorship']) && 
                               ($job['visaSponsorship'] === 1 || 
                                $job['visaSponsorship'] === '1' || 
                                $job['visaSponsorship'] === true)) ? 1 : 0;
            
            $stmt = $conn->prepare("
                INSERT INTO ai_jobs (
                    jobTitle, date_insert, company, jobDescription, 
                    salaryCurrency, salaryMin, salaryMax, qualifications, 
                    yearsOfExperienceRequired, taskDescription, tasks, 
                    languagesNeeded, spokenLanguages, location, category, 
                    skillsetRequired, softSkillsRequired, jobBenefits, 
                    workingConditions, workType, companySize, companyCulture, 
                    opportunitiesForAdvancement, visaSponsorship, projectTypes, 
                    recruiterName, recruiterEmail, recruiterPhone
                ) VALUES (
                    ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            
            $company = '';
            $companySize = 0;
            
            $stmt->bind_param(
                "ssssiisississsssssssssissss",
                $job['jobTitle'],
                $company,
                $job['jobDescription'],
                $job['salaryRange']['currency'],
                $job['salaryRange']['min'],
                $job['salaryRange']['max'],
                $qualifications,
                $job['yearsOfExperienceRequired'],
                $job['jobResponsibilities']['taskDescription'],
                $tasks,
                $languagesNeeded,
                $spokenLanguages,
                $job['location'],
                $job['category'],
                $skillset,
                $softskills,
                $benefits,
                $job['workingConditions'],
                $job['workType'],
                $companySize,
                $job['companyCulture'],
                $opportunitiesForAdvancement,
                $visaSponsorship,
                $projectTypes,
                $job['recruiterName'],
                $job['recruiterEmail'],
                $job['recruiterPhone']
            );
            
            if ($stmt->execute()) {
                $insertedCount++;
            } else {
                $errors[] = $stmt->error;
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully imported $insertedCount jobs",
        'count' => $insertedCount,
        'errors' => $errors
    ]);
}
?>
