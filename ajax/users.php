<?php
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => ''];

// Check permissions
$requiresManage = in_array($action, ['delete_user']);
$requiresEdit = in_array($action, ['update_user', 'admin_upload_photo', 'send_reset', 'reactivate_user', 'deactivate_user']);
$requiresCreate = in_array($action, ['create_user']);

if (!isAdmin()) {
    if ($requiresManage && !hasPermission('users.manage') && !hasPermission('users.delete')) {
        $response['message'] = 'Unauthorized access - requires delete permission';
        echo json_encode($response);
        exit();
    }
    if ($requiresEdit && !hasPermission('users.edit') && !hasPermission('users.manage')) {
        $response['message'] = 'Unauthorized access - requires edit permission';
        echo json_encode($response);
        exit();
    }
    if ($requiresCreate && !hasPermission('users.create') && !hasPermission('users.manage')) {
        $response['message'] = 'Unauthorized access - requires create permission';
        echo json_encode($response);
        exit();
    }
}

$conn = getDBConnection();

// Build a clean comma-separated string from a checkbox array (publication[] /
// section[]) or a legacy comma string. De-dupes and drops blanks.
function csvFromInput($v) {
    if (is_array($v)) {
        $parts = array_map('sanitize', $v);
    } else {
        $parts = array_map('sanitize', explode(',', (string)$v));
    }
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts), fn($x) => $x !== '')));
    return implode(',', $parts);
}

// True if any of the given role ids is a TEN-news editorial role that requires
// a publication assignment.
function selectionHasEditorialRole($conn, $roleIds) {
    $roleIds = array_values(array_filter(array_map('intval', (array)$roleIds)));
    if (!$roleIds) return false;
    $in = implode(',', $roleIds);
    $editorial = "'Journalist','Section Editor','Editor','Managing Editor','General Editor'";
    $res = $conn->query("SELECT COUNT(*) AS c FROM ten_roles WHERE id IN ($in) AND role_name IN ($editorial)");
    $row = $res ? $res->fetch_assoc() : ['c' => 0];
    return ((int)$row['c']) > 0;
}

try {
    switch ($action) {
        case 'create_user':
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $status = sanitize($_POST['status'] ?? 'pending');
            $section = csvFromInput($_POST['section'] ?? '');
            $publication = csvFromInput($_POST['publication'] ?? '');
            $roles = $_POST['roles'] ?? [];
            $sendInvite = !empty($_POST['send_invite']);

            // Validate required fields. Password is optional when inviting — the
            // user sets their own via the emailed link.
            if (empty($fullName) || empty($email) || empty($username) || (empty($password) && !$sendInvite)) {
                throw new Exception('All required fields must be filled');
            }
            if (empty($password)) {
                // Placeholder hash so the account exists; the invite link replaces it.
                $password = generatePassword(16);
            }

            // Editorial (TEN news) roles require at least one publication.
            if (selectionHasEditorialRole($conn, $roles) && $publication === '') {
                throw new Exception('Editorial roles must be assigned at least one publication');
            }
            
            // Check if email already exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            $checkStmt->close();
            
            // Check if username already exists
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Username already exists');
            }
            $checkStmt->close();
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $conn->prepare("INSERT INTO ten_users (username, email, password, full_name, status, section, publication, email_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("sssssss", $username, $email, $hashedPassword, $fullName, $status, $section, $publication);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create user: ' . $stmt->error);
            }
            
            $userId = $stmt->insert_id;
            $stmt->close();
            
            // Assign roles
            if (!empty($roles) && is_array($roles)) {
                $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                $assignedBy = $_SESSION['ten_user_id'];
                
                foreach ($roles as $roleId) {
                    $roleId = intval($roleId);
                    $roleStmt->bind_param("iii", $userId, $roleId, $assignedBy);
                    $roleStmt->execute();
                }
                $roleStmt->close();
            }
            
            // Optionally email a welcome + set-password link (reuses the reset flow).
            if ($sendInvite) {
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 3600); // 7 days for invites
                $tk = $conn->prepare("INSERT INTO ten_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                $tk->bind_param('iss', $userId, $token, $expiresAt);
                $tk->execute();
                $tk->close();

                $setLink = SITE_URL . "/reset-password.php?token=" . $token;
                $tutorialBlock = '';
                if (selectionHasEditorialRole($conn, $roles)) {
                    $tutLink = SITE_URL . "/tutorial.php";
                    $tutorialBlock = "<p>Once you're logged in, here is a guide to your role and how to use the tools available to you:</p>"
                        . "<p><a href='$tutLink'>View your role tutorial</a></p>";
                }
                $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
                $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
                $body = "<h2>Welcome to TEN Management</h2>"
                    . "<p>Hi $safeName,</p>"
                    . "<p>An account has been created for you on the TEN Management System.</p>"
                    . "<p>Click below to set your password and log in:</p>"
                    . "<p><a href='$setLink' style='display:inline-block;padding:10px 18px;background:#3c4f6d;color:#fff;text-decoration:none;border-radius:6px;'>Set your password &amp; log in</a></p>"
                    . "<p>Or paste this link into your browser:<br><a href='$setLink'>$setLink</a></p>"
                    . "<p>This link is valid for 7 days. Your login email is <strong>$safeEmail</strong>.</p>"
                    . $tutorialBlock
                    . "<p>— The Eye Newspapers</p>";
                $sent = sendEmail($email, 'Your TEN Management account', $body);
                $response['invite_sent'] = (bool)$sent;
            }

            // Log activity
            logActivity('user_created', 'user', $userId, "Created user: $username");

            $response['success'] = true;
            $response['message'] = $sendInvite
                ? ('User created. Invitation email ' . (!empty($response['invite_sent']) ? 'sent to ' . $email : 'could NOT be sent (check mail config)') . '.')
                : 'User created successfully';
            $response['user_id'] = $userId;
            break;
            
        case 'update_user':
            $userId = intval($_POST['user_id'] ?? 0);
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $status = sanitize($_POST['status'] ?? 'active');
            $section = csvFromInput($_POST['section'] ?? '');
            $publication = csvFromInput($_POST['publication'] ?? '');
            $byline = trim($_POST['byline'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $roles = $_POST['roles'] ?? [];

            if ($userId <= 0) {
                throw new Exception('Invalid user ID');
            }

            // Editorial (TEN news) roles require at least one publication.
            if (selectionHasEditorialRole($conn, $roles) && $publication === '') {
                throw new Exception('Editorial roles must be assigned at least one publication');
            }
            
            // Validate required fields
            if (empty($fullName) || empty($email) || empty($username)) {
                throw new Exception('All required fields must be filled');
            }
            
            // Check if email already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE email = ? AND id != ?");
            $checkStmt->bind_param("si", $email, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            $checkStmt->close();
            
            // Check if username already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM ten_users WHERE username = ? AND id != ?");
            $checkStmt->bind_param("si", $username, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Username already exists');
            }
            $checkStmt->close();
            
            // Update user
            $stmt = $conn->prepare("UPDATE ten_users SET username = ?, email = ?, full_name = ?, status = ?, section = ?, publication = ?, byline = ?, bio = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssssssi", $username, $email, $fullName, $status, $section, $publication, $byline, $bio, $userId);

            if (!$stmt->execute()) {
                throw new Exception('Failed to update user: ' . $stmt->error);
            }
            $stmt->close();

            // Mirror byline/bio to the old admin_ten.users row (matched by email as
            // username) for any un-migrated publications that still read it.
            try {
                $ca = getDBConnection_TENAdmin();
                $ma = $ca->prepare("UPDATE users SET byline = ?, bio = ? WHERE username = ?");
                $ma->bind_param('sss', $byline, $bio, $email);
                $ma->execute(); $ma->close(); $ca->close();
            } catch (Throwable $e) { error_log('users.php admin_ten byline mirror failed: ' . $e->getMessage()); }
            
            // Update roles - delete existing and add new ones
            $deleteStmt = $conn->prepare("DELETE FROM ten_user_roles WHERE user_id = ?");
            $deleteStmt->bind_param("i", $userId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Assign new roles
            if (!empty($roles) && is_array($roles)) {
                $roleStmt = $conn->prepare("INSERT INTO ten_user_roles (user_id, role_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                $assignedBy = $_SESSION['ten_user_id'];
                
                foreach ($roles as $roleId) {
                    $roleId = intval($roleId);
                    $roleStmt->bind_param("iii", $userId, $roleId, $assignedBy);
                    $roleStmt->execute();
                }
                $roleStmt->close();
            }
            
            // Log activity
            logActivity('user_updated', 'user', $userId, "Updated user: $username");
            
            $response['success'] = true;
            $response['message'] = 'User updated successfully';
            break;
            
        case 'reactivate_user':
        case 'deactivate_user':
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new Exception('Invalid user ID');
            $newStatus = ($action === 'reactivate_user') ? 'active' : 'inactive';
            if ($action === 'deactivate_user' && $userId == $_SESSION['ten_user_id']) {
                throw new Exception('You cannot deactivate your own account');
            }
            $stmt = $conn->prepare("UPDATE ten_users SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $newStatus, $userId);
            if (!$stmt->execute()) throw new Exception('Failed to update user status: ' . $stmt->error);
            $stmt->close();
            logActivity($action, 'user', $userId, ($action === 'reactivate_user' ? 'Reactivated' : 'Deactivated') . " user #$userId");
            $response['success'] = true;
            $response['message'] = ($action === 'reactivate_user') ? 'User reactivated' : 'User moved to old accounts';
            break;

        case 'delete_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if ($userId <= 0) {
                throw new Exception('Invalid user ID');
            }
            
            // Prevent deleting yourself
            if ($userId == $_SESSION['ten_user_id']) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Get username before deletion for logging
            $userStmt = $conn->prepare("SELECT username FROM ten_users WHERE id = ?");
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $username = $userStmt->get_result()->fetch_assoc()['username'] ?? 'Unknown';
            $userStmt->close();
            
            // Delete user (cascade will handle related records)
            $stmt = $conn->prepare("DELETE FROM ten_users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete user: ' . $stmt->error);
            }
            $stmt->close();
            
            // Log activity
            logActivity('user_deleted', 'user', $userId, "Deleted user: $username");
            
            $response['success'] = true;
            $response['message'] = 'User deleted successfully';
            break;

        case 'admin_upload_photo':
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) { throw new Exception('Invalid user ID'); }
            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error');
            }
            // Resolve the target's email → old admin_ten user id (photos are named by it).
            $es = $conn->prepare("SELECT email FROM ten_users WHERE id = ?");
            $es->bind_param('i', $userId); $es->execute();
            $targetEmail = $es->get_result()->fetch_assoc()['email'] ?? '';
            $es->close();

            $adminUserId = null;
            try {
                $ca = getDBConnection_TENAdmin();
                $as = $ca->prepare("SELECT id FROM users WHERE username = ?");
                $as->bind_param('s', $targetEmail); $as->execute();
                $ar = $as->get_result()->fetch_assoc(); $as->close();
                $adminUserId = $ar ? (int)$ar['id'] : null;
                $photoUserId = $adminUserId ?: $userId;
                $uploadDir = __DIR__ . '/../../journalist-photo/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                $filename = 'bio_photo_' . $photoUserId . '.png';
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                    $ca->close();
                    throw new Exception('Failed to save file');
                }
                if ($adminUserId) {
                    $up = $ca->prepare("UPDATE users SET photo = ? WHERE id = ?");
                    $up->bind_param('si', $filename, $adminUserId); $up->execute(); $up->close();
                }
                $ca->close();
                $relative = '/journalist-photo/' . $filename;
                // profile_image (path) drives the management UI avatar; photo (filename)
                // is what the public bio page (get_bio_page.php) reads.
                $um = $conn->prepare("UPDATE ten_users SET profile_image = ?, photo = ? WHERE id = ?");
                $um->bind_param('ssi', $relative, $filename, $userId); $um->execute(); $um->close();
                logActivity('admin_user_photo', 'user', $userId, 'Admin updated user photo');
                $response['success'] = true;
                $response['message'] = 'Photo updated';
                $response['path'] = $relative;
            } catch (Throwable $e) {
                throw new Exception('Photo upload failed: ' . $e->getMessage());
            }
            break;

        case 'send_reset':
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) { throw new Exception('Invalid user ID'); }
            $us = $conn->prepare("SELECT email, full_name FROM ten_users WHERE id = ?");
            $us->bind_param('i', $userId); $us->execute();
            $u = $us->get_result()->fetch_assoc(); $us->close();
            if (!$u || empty($u['email'])) { throw new Exception('User has no email address'); }
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 24 * 3600);
            $tk = $conn->prepare("INSERT INTO ten_password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $tk->bind_param('iss', $userId, $token, $expiresAt); $tk->execute(); $tk->close();
            $link = SITE_URL . '/reset-password.php?token=' . $token;
            $safeName = htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8');
            $emailBody = "<h2>Password reset</h2><p>Hi $safeName,</p>"
                . "<p>A password reset was requested for your TEN Management account. Click below to set a new password:</p>"
                . "<p><a href='$link' style='display:inline-block;padding:10px 18px;background:#3c4f6d;color:#fff;text-decoration:none;border-radius:6px;'>Reset your password</a></p>"
                . "<p>Or paste this link into your browser:<br><a href='$link'>$link</a></p>"
                . "<p>This link is valid for 24 hours. If you didn't expect this, you can ignore this email.</p>";
            $sent = sendEmail($u['email'], 'Reset your TEN Management password', $emailBody);
            logActivity('admin_send_reset', 'user', $userId, 'Admin sent password reset email');
            $response['success'] = (bool)$sent;
            $response['message'] = $sent ? ('Reset email sent to ' . $u['email']) : 'Could not send email (check mail config)';
            break;

        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

$conn->close();
echo json_encode($response);
