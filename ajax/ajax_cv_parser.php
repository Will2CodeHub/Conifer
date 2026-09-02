<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!hasPermission('file_transfers.manage') && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getDBConnection();
$action = $_POST['action'] ?? '';

if ($action === 'parse_cv') {
    $fileId = intval($_POST['file_id']);
    $forceReparse = isset($_POST['force_reparse']) && $_POST['force_reparse'] == '1';
    
    // Get file details
    $stmt = $conn->prepare("SELECT f.*, ff.folder_code FROM ten_file_transfer_files f 
                            JOIN ten_file_transfer_folders ff ON f.folder_id = ff.id 
                            WHERE f.id = ?");
    $stmt->bind_param("i", $fileId);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();
    $stmt->close();
    
    if (!$file) {
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit();
    }
    
    // Check if already parsed
    if (!$forceReparse) {
        $stmt = $conn->prepare("SELECT id FROM ten_cv_candidates WHERE file_id = ? AND ai_parsed = 1");
        $stmt->bind_param("i", $fileId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'CV already parsed. Enable force reparse to parse again.', 'already_parsed' => true]);
            exit();
        }
        $stmt->close();
    }
    
    // Build file path
    $uploadDir = '/home/tenuser/public_html/file_transfers/' . $file['folder_code'] . '/';
    $filePath = $uploadDir . $file['stored_filename'];
    
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'File not found on disk']);
        exit();
    }
    
    // Read file content
    $fileContent = file_get_contents($filePath);
    $base64Content = base64_encode($fileContent);
    
    // Determine media type
    $extension = strtolower(pathinfo($file['original_filename'], PATHINFO_EXTENSION));
    $mediaType = 'application/pdf';
    if ($extension === 'doc' || $extension === 'docx') {
        $mediaType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    
    // Call Claude API
    $apiKey = ANTHROPIC_API_KEY;
    if (!$apiKey) {
        echo json_encode(['success' => false, 'message' => 'API key not configured']);
        exit();
    }
    
    $apiUrl = 'https://api.anthropic.com/v1/messages';
    
    $systemPrompt = "You are a CV/resume parser. Extract all relevant information from the provided CV and return it as a JSON object. Be thorough and extract as much detail as possible.

Return JSON with this structure:
{
  \"personal_info\": {
    \"full_name\": \"\",
    \"email\": \"\",
    \"phone\": \"\",
    \"address\": \"\",
    \"city\": \"\",
    \"country\": \"\",
    \"postcode\": \"\",
    \"date_of_birth\": \"YYYY-MM-DD\",
    \"nationality\": \"\",
    \"linkedin_url\": \"\",
    \"website_url\": \"\",
    \"summary\": \"\"
  },
  \"work_experience\": [
    {
      \"job_title\": \"\",
      \"company_name\": \"\",
      \"location\": \"\",
      \"start_date\": \"YYYY-MM-DD\",
      \"end_date\": \"YYYY-MM-DD or null if current\",
      \"is_current\": true/false,
      \"description\": \"\",
      \"responsibilities\": \"\",
      \"achievements\": \"\"
    }
  ],
  \"education\": [
    {
      \"degree_type\": \"\",
      \"field_of_study\": \"\",
      \"institution_name\": \"\",
      \"location\": \"\",
      \"start_date\": \"YYYY-MM-DD\",
      \"end_date\": \"YYYY-MM-DD\",
      \"grade\": \"\",
      \"description\": \"\"
    }
  ],
  \"skills\": [
    {
      \"skill_name\": \"\",
      \"skill_category\": \"Technical/Soft/Language/Other\",
      \"proficiency_level\": \"Beginner/Intermediate/Advanced/Expert\",
      \"years_of_experience\": 0
    }
  ],
  \"certifications\": [
    {
      \"certification_name\": \"\",
      \"issuing_organization\": \"\",
      \"issue_date\": \"YYYY-MM-DD\",
      \"expiry_date\": \"YYYY-MM-DD or null\",
      \"credential_id\": \"\",
      \"credential_url\": \"\"
    }
  ],
  \"languages\": [
    {
      \"language_name\": \"\",
      \"proficiency_level\": \"Native/Fluent/Professional/Intermediate/Basic\"
    }
  ],
  \"projects\": [
    {
      \"project_name\": \"\",
      \"project_role\": \"\",
      \"start_date\": \"YYYY-MM-DD\",
      \"end_date\": \"YYYY-MM-DD or null\",
      \"description\": \"\",
      \"technologies_used\": \"\",
      \"project_url\": \"\"
    }
  ],
  \"references\": [
    {
      \"reference_name\": \"\",
      \"job_title\": \"\",
      \"company\": \"\",
      \"email\": \"\",
      \"phone\": \"\",
      \"relationship\": \"\"
    }
  ]
}

Return ONLY the JSON, no markdown formatting or explanations.";
    
    $requestData = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 4096,
        'system' => $systemPrompt,
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64Content
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Please extract all information from this CV/resume and return it as JSON.'
                    ]
                ]
            ]
        ]
    ];
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $errorMsg = 'API request failed with code ' . $httpCode;
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ': ' . $errorData['error']['message'];
            }
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit();
    }
    
    $apiResponse = json_decode($response, true);
    
    if (!isset($apiResponse['content'][0]['text'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid API response']);
        exit();
    }
    
    $cvDataText = $apiResponse['content'][0]['text'];
    $cvDataText = trim($cvDataText);
    $cvDataText = preg_replace('/^```json\s*/', '', $cvDataText);
    $cvDataText = preg_replace('/\s*```$/', '', $cvDataText);
    
    $cvData = json_decode($cvDataText, true);
    
    if (!$cvData) {
        echo json_encode(['success' => false, 'message' => 'Failed to parse CV data from AI response', 'raw_response' => $cvDataText]);
        exit();
    }
    
    // Copy CV to dedicated folder
    $cvFolder = '/home/tenuser/public_html/management/cvs/';
    if (!file_exists($cvFolder)) {
        mkdir($cvFolder, 0755, true);
    }
    
    $cvFilename = 'cv_' . $fileId . '_' . time() . '.' . $extension;
    $cvPath = $cvFolder . $cvFilename;
    copy($filePath, $cvPath);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete existing data if reparse
        if ($forceReparse) {
            $stmt = $conn->prepare("SELECT id FROM ten_cv_candidates WHERE file_id = ?");
            $stmt->bind_param("i", $fileId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($existing = $result->fetch_assoc()) {
                $candidateId = $existing['id'];
                $stmt->close();
                
                // Delete related records (cascade should handle this, but be explicit)
                $conn->query("DELETE FROM ten_cv_work_experience WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_education WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_skills WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_certifications WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_languages WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_projects WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_references WHERE candidate_id = $candidateId");
                $conn->query("DELETE FROM ten_cv_candidates WHERE id = $candidateId");
            } else {
                $stmt->close();
            }
        }
        
        // Insert candidate
        $pi = $cvData['personal_info'] ?? [];
        $stmt = $conn->prepare("INSERT INTO ten_cv_candidates (file_id, cv_file_path, full_name, email, phone, address, city, country, postcode, date_of_birth, nationality, linkedin_url, website_url, summary, ai_parsed, parse_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        
        $dob = !empty($pi['date_of_birth']) ? $pi['date_of_birth'] : null;
        
        $stmt->bind_param("isssssssssssss", 
            $fileId, 
            $cvPath,
            $pi['full_name'],
            $pi['email'],
            $pi['phone'],
            $pi['address'],
            $pi['city'],
            $pi['country'],
            $pi['postcode'],
            $dob,
            $pi['nationality'],
            $pi['linkedin_url'],
            $pi['website_url'],
            $pi['summary']
        );
        $stmt->execute();
        $candidateId = $stmt->insert_id;
        $stmt->close();
        
        // Insert work experience
        if (isset($cvData['work_experience']) && is_array($cvData['work_experience'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_work_experience (candidate_id, job_title, company_name, location, start_date, end_date, is_current, description, responsibilities, achievements, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['work_experience'] as $idx => $exp) {
                $startDate = !empty($exp['start_date']) ? $exp['start_date'] : null;
                $endDate = !empty($exp['end_date']) ? $exp['end_date'] : null;
                $isCurrent = isset($exp['is_current']) ? ($exp['is_current'] ? 1 : 0) : 0;
                
                $stmt->bind_param("isssssisssi",
                    $candidateId,
                    $exp['job_title'],
                    $exp['company_name'],
                    $exp['location'],
                    $startDate,
                    $endDate,
                    $isCurrent,
                    $exp['description'],
                    $exp['responsibilities'],
                    $exp['achievements'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert education
        if (isset($cvData['education']) && is_array($cvData['education'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_education (candidate_id, degree_type, field_of_study, institution_name, location, start_date, end_date, grade, description, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['education'] as $idx => $edu) {
                $startDate = !empty($edu['start_date']) ? $edu['start_date'] : null;
                $endDate = !empty($edu['end_date']) ? $edu['end_date'] : null;
                
                $stmt->bind_param("issssssssi",
                    $candidateId,
                    $edu['degree_type'],
                    $edu['field_of_study'],
                    $edu['institution_name'],
                    $edu['location'],
                    $startDate,
                    $endDate,
                    $edu['grade'],
                    $edu['description'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert skills
        if (isset($cvData['skills']) && is_array($cvData['skills'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_skills (candidate_id, skill_name, skill_category, proficiency_level, years_of_experience, display_order) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['skills'] as $idx => $skill) {
                $yearsExp = isset($skill['years_of_experience']) ? intval($skill['years_of_experience']) : null;
                
                $stmt->bind_param("issisi",
                    $candidateId,
                    $skill['skill_name'],
                    $skill['skill_category'],
                    $skill['proficiency_level'],
                    $yearsExp,
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert certifications
        if (isset($cvData['certifications']) && is_array($cvData['certifications'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_certifications (candidate_id, certification_name, issuing_organization, issue_date, expiry_date, credential_id, credential_url, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['certifications'] as $idx => $cert) {
                $issueDate = !empty($cert['issue_date']) ? $cert['issue_date'] : null;
                $expiryDate = !empty($cert['expiry_date']) ? $cert['expiry_date'] : null;
                
                $stmt->bind_param("issssssi",
                    $candidateId,
                    $cert['certification_name'],
                    $cert['issuing_organization'],
                    $issueDate,
                    $expiryDate,
                    $cert['credential_id'],
                    $cert['credential_url'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert languages
        if (isset($cvData['languages']) && is_array($cvData['languages'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_languages (candidate_id, language_name, proficiency_level, display_order) VALUES (?, ?, ?, ?)");
            
            foreach ($cvData['languages'] as $idx => $lang) {
                $stmt->bind_param("issi",
                    $candidateId,
                    $lang['language_name'],
                    $lang['proficiency_level'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert projects
        if (isset($cvData['projects']) && is_array($cvData['projects'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_projects (candidate_id, project_name, project_role, start_date, end_date, description, technologies_used, project_url, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['projects'] as $idx => $proj) {
                $startDate = !empty($proj['start_date']) ? $proj['start_date'] : null;
                $endDate = !empty($proj['end_date']) ? $proj['end_date'] : null;
                
                $stmt->bind_param("isssssssi",
                    $candidateId,
                    $proj['project_name'],
                    $proj['project_role'],
                    $startDate,
                    $endDate,
                    $proj['description'],
                    $proj['technologies_used'],
                    $proj['project_url'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Insert references
        if (isset($cvData['references']) && is_array($cvData['references'])) {
            $stmt = $conn->prepare("INSERT INTO ten_cv_references (candidate_id, reference_name, job_title, company, email, phone, relationship, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cvData['references'] as $idx => $ref) {
                $stmt->bind_param("issssssi",
                    $candidateId,
                    $ref['reference_name'],
                    $ref['job_title'],
                    $ref['company'],
                    $ref['email'],
                    $ref['phone'],
                    $ref['relationship'],
                    $idx
                );
                $stmt->execute();
            }
            $stmt->close();
        }
        
        $conn->commit();
        
        logActivity('parse_cv', 'file_transfer', $fileId, "Parsed CV: " . $file['original_filename']);
        
        echo json_encode([
            'success' => true, 
            'message' => 'CV parsed successfully',
            'candidate_id' => $candidateId,
            'candidate_name' => $pi['full_name'] ?? 'Unknown'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
    $conn->close();
    exit();
}

if ($action === 'get_cv_data') {
    $fileId = intval($_POST['file_id']);
    
    // Get candidate data
    $stmt = $conn->prepare("SELECT * FROM ten_cv_candidates WHERE file_id = ? AND ai_parsed = 1");
    $stmt->bind_param("i", $fileId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'CV data not found']);
        exit();
    }
    
    $candidateId = $candidate['id'];
    
    // Get work experience
    $stmt = $conn->prepare("SELECT * FROM ten_cv_work_experience WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $workExperience = [];
    while ($row = $result->fetch_assoc()) {
        $workExperience[] = $row;
    }
    $stmt->close();
    
    // Get education
    $stmt = $conn->prepare("SELECT * FROM ten_cv_education WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $education = [];
    while ($row = $result->fetch_assoc()) {
        $education[] = $row;
    }
    $stmt->close();
    
    // Get skills
    $stmt = $conn->prepare("SELECT * FROM ten_cv_skills WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $skills = [];
    while ($row = $result->fetch_assoc()) {
        $skills[] = $row;
    }
    $stmt->close();
    
    // Get certifications
    $stmt = $conn->prepare("SELECT * FROM ten_cv_certifications WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $certifications = [];
    while ($row = $result->fetch_assoc()) {
        $certifications[] = $row;
    }
    $stmt->close();
    
    // Get languages
    $stmt = $conn->prepare("SELECT * FROM ten_cv_languages WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $languages = [];
    while ($row = $result->fetch_assoc()) {
        $languages[] = $row;
    }
    $stmt->close();
    
    // Get projects
    $stmt = $conn->prepare("SELECT * FROM ten_cv_projects WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = [];
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
    $stmt->close();
    
    // Get references
    $stmt = $conn->prepare("SELECT * FROM ten_cv_references WHERE candidate_id = ? ORDER BY display_order");
    $stmt->bind_param("i", $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();
    $references = [];
    while ($row = $result->fetch_assoc()) {
        $references[] = $row;
    }
    $stmt->close();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'candidate' => $candidate,
        'work_experience' => $workExperience,
        'education' => $education,
        'skills' => $skills,
        'certifications' => $certifications,
        'languages' => $languages,
        'projects' => $projects,
        'references' => $references
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
$conn->close();
