<?php
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$userId = $_SESSION['ten_user_id'];

if ($action === 'upload_photo') {
    try {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error');
        }
        
        // Get user email to find admin_ten user
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT email FROM ten_users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $userEmail = $result['email'];
        $stmt->close();
        
        // Get admin_ten user ID (or use ten_users ID if admin user doesn't exist)
        $connAdmin = getDBConnection_TENAdmin();
        $stmtAdmin = $connAdmin->prepare("SELECT id FROM users WHERE username = ?");
        $stmtAdmin->bind_param("s", $userEmail);
        $stmtAdmin->execute();
        $adminResult = $stmtAdmin->get_result()->fetch_assoc();
        $stmtAdmin->close();
        
        // Use admin_ten user ID if exists, otherwise use TEN Management user ID
        $photoUserId = $adminResult ? $adminResult['id'] : $userId;
        
        // Create directory if it doesn't exist
        $uploadDir = '../../journalist-photo/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate filename
        $filename = 'bio_photo_' . $photoUserId . '.png';
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
            throw new Exception('Failed to save file');
        }
        
        // Update admin_ten database if user exists
        if ($adminResult) {
            $updateAdmin = $connAdmin->prepare("UPDATE users SET photo = ? WHERE id = ?");
            $updateAdmin->bind_param("si", $filename, $adminResult['id']);
            $updateAdmin->execute();
            $updateAdmin->close();
        }
        
        // Update TEN Management database with relative path
        $relativePath = '/journalist-photo/' . $filename;
        $updateMain = $conn->prepare("UPDATE ten_users SET profile_image = ? WHERE id = ?");
        $updateMain->bind_param("si", $relativePath, $userId);
        $updateMain->execute();
        $updateMain->close();
        
        $conn->close();
        $connAdmin->close();
        
        logActivity('profile_photo_updated', 'user', $userId, 'Updated profile photo');
        
        echo json_encode([
            'success' => true,
            'message' => 'Photo uploaded successfully',
            'filename' => $filename
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

if ($action === 'save_management_profile') {
    try {
        $conn = getDBConnection();
        
        // Get current user data
        $stmt = $conn->prepare("SELECT * FROM ten_users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $languagePreference = $_POST['language_preference'] ?? 'en';
        
        // Validate required fields
        if (empty($fullName) || empty($email)) {
            throw new Exception('Full name and email are required');
        }
        
        // Check if email is already taken by another user
        $emailCheck = $conn->prepare("SELECT id FROM ten_users WHERE email = ? AND id != ?");
        $emailCheck->bind_param("si", $email, $userId);
        $emailCheck->execute();
        if ($emailCheck->get_result()->num_rows > 0) {
            throw new Exception('Email already in use by another user');
        }
        $emailCheck->close();
        
        // Update basic info
        $update = $conn->prepare("UPDATE ten_users SET full_name = ?, email = ?, phone = ?, language_preference = ? WHERE id = ?");
        $update->bind_param("ssssi", $fullName, $email, $phone, $languagePreference, $userId);
        $update->execute();
        $update->close();
        
        // Handle password change
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (!empty($newPassword)) {
            // Validate new password
            if ($newPassword !== $confirmPassword) {
                throw new Exception('New passwords do not match');
            }
            
            if (strlen($newPassword) < 8) {
                throw new Exception('Password must be at least 8 characters');
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePwd = $conn->prepare("UPDATE ten_users SET password = ? WHERE id = ?");
            $updatePwd->bind_param("si", $hashedPassword, $userId);
            $updatePwd->execute();
            $updatePwd->close();
            
            logActivity('password_changed', 'user', $userId, 'Changed password');
        }
        
        // Update session if email changed
        if ($email !== $currentUser['email']) {
            $_SESSION['ten_email'] = $email;
        }
        
        // Update session language
        $_SESSION['ten_language'] = $languagePreference;
        
        $conn->close();
        
        logActivity('profile_updated', 'user', $userId, 'Updated management profile');
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

if ($action === 'save_newsportal_profile') {
    try {
        // Get user data from TEN Management
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT email, profile_image FROM ten_users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $userEmail = $result['email'];
        $currentProfileImage = $result['profile_image'];
        $stmt->close();
        $conn->close();
        
        // Get fields from POST
        $firstname = trim($_POST['firstname'] ?? '');
        $middlename = trim($_POST['middlename'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $penName = trim($_POST['pen_name'] ?? '');
        $alias = trim($_POST['alias'] ?? '');
        $position = $_POST['position'] ?? 'Journalist';
        $role = $_POST['role'] ?? 'journalist';
        $section = $_POST['section'] ?? '';
        $byline = trim($_POST['byline'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if (isAdmin()) {
            $position = $_POST['position'] ?? 'Journalist';
            $role = $_POST['role'] ?? 'journalist';
        } else {
            // Get current values from database for non-admins
            $position = null; // Will be set from existing record
            $role = null; // Will be set from existing record
        }
        
        // Update admin_ten database
        $connAdmin = getDBConnection_TENAdmin();
        
        // Check if user exists in admin_ten
        $checkStmt = $connAdmin->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $userEmail);
        $checkStmt->execute();
        $adminUser = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($adminUser) {
            // Update existing user
            $adminUserId = $adminUser['id'];
            if (isAdmin()) {
                $update = $connAdmin->prepare("UPDATE users SET firstname = ?, middlename = ?, surname = ?, pen_name = ?, alias = ?, position = ?, role = ?, section = ?, byline = ?, bio = ? WHERE id = ?");
                $update->bind_param("ssssssssssi", $firstname, $middlename, $surname, $penName, $alias, $position, $role, $section, $byline, $bio, $adminUserId);
            } else {
                $update = $connAdmin->prepare("UPDATE users SET firstname = ?, middlename = ?, surname = ?, pen_name = ?, alias = ?, section = ?, byline = ?, bio = ? WHERE id = ?");
                $update->bind_param("ssssssssi", $firstname, $middlename, $surname, $penName, $alias, $section, $byline, $bio, $adminUserId);
            }
            $update->execute();
            $update->close();
        } else {
            // Create new user in admin_ten
            // Use md5 hash for portal accounts — fits short password columns; account is locked until user resets
            $password = md5(bin2hex(random_bytes(16)));
            // public_id must fit in a standard INT column (max ~2.1 billion)
            $publicId = rand(10000000, 999999999);

            if (!isAdmin()) {
                $position = 'Journalist';
                $role = 'journalist';
            }
            
            $insert = $connAdmin->prepare("INSERT INTO users (public_id, username, password, role, firstname, middlename, surname, pen_name, alias, position, section, byline, bio, created, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)");
            $insert->bind_param("issssssssssss", $publicId, $userEmail, $password, $role, $firstname, $middlename, $surname, $penName, $alias, $position, $section, $byline, $bio);
            $insert->execute();
            $newAdminUserId = $insert->insert_id;
            $insert->close();
            
            // If there's an existing profile photo, rename it to match new admin_ten user ID
            if (!empty($currentProfileImage) && file_exists('../' . $currentProfileImage)) {
                $oldPhotoPath = '../' . $currentProfileImage;
                $newFilename = 'bio_photo_' . $newAdminUserId . '.png';
                $newPhotoPath = '../journalist-photo/' . $newFilename;
                
                // Rename the file
                if (rename($oldPhotoPath, $newPhotoPath)) {
                    // Update photo in admin_ten
                    $updatePhoto = $connAdmin->prepare("UPDATE users SET photo = ? WHERE id = ?");
                    $updatePhoto->bind_param("si", $newFilename, $newAdminUserId);
                    $updatePhoto->execute();
                    $updatePhoto->close();
                    
                    // Update photo path in ten_users
                    $conn = getDBConnection();
                    $newRelativePath = 'journalist-photo/' . $newFilename;
                    $updateMainPhoto = $conn->prepare("UPDATE ten_users SET profile_image = ? WHERE id = ?");
                    $updateMainPhoto->bind_param("si", $newRelativePath, $userId);
                    $updateMainPhoto->execute();
                    $updateMainPhoto->close();
                    $conn->close();
                }
            }
        }
        
        $connAdmin->close();
        
        logActivity('newsportal_profile_updated', 'user', $userId, 'Updated news portal profile');
        
        echo json_encode([
            'success' => true,
            'message' => 'News portal profile updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit();
}

// Invalid action
echo json_encode([
    'success' => false,
    'message' => 'Invalid action'
]);