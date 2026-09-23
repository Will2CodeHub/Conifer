<?php
require_once 'config.php';
requireLogin();

if (!hasPermission('file_transfers.manage') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    if ($action === 'create_folder') {
        $folderName = sanitize($_POST['folder_name']);
        $uploadPublic = isset($_POST['upload_public']) ? 1 : 0;
        $downloadPublic = isset($_POST['download_public']) ? 1 : 0;
        $uploadPassword = !empty($_POST['upload_password']) ? password_hash($_POST['upload_password'], PASSWORD_DEFAULT) : null;
        $downloadPassword = !empty($_POST['download_password']) ? password_hash($_POST['download_password'], PASSWORD_DEFAULT) : null;
        
        // Generate unique folder code
        $folderCode = substr(md5(uniqid(rand(), true)), 0, 16);
        
        // Handle expiry date
        $expiryDate = null;
        if (!empty($_POST['expiry_type'])) {
            $expiryType = $_POST['expiry_type'];
            if ($expiryType === 'custom' && !empty($_POST['expiry_date'])) {
                $expiryDate = $_POST['expiry_date'];
            } elseif ($expiryType !== 'never') {
                $date = new DateTime();
                switch ($expiryType) {
                    case '1_day': $date->modify('+1 day'); break;
                    case '2_days': $date->modify('+2 days'); break;
                    case '3_days': $date->modify('+3 days'); break;
                    case '4_days': $date->modify('+4 days'); break;
                    case '5_days': $date->modify('+5 days'); break;
                    case '6_days': $date->modify('+6 days'); break;
                    case '7_days': $date->modify('+7 days'); break;
                    case '1_week': $date->modify('+1 week'); break;
                    case '2_weeks': $date->modify('+2 weeks'); break;
                    case '3_weeks': $date->modify('+3 weeks'); break;
                    case '4_weeks': $date->modify('+4 weeks'); break;
                    case '1_month': $date->modify('+1 month'); break;
                    case '2_months': $date->modify('+2 months'); break;
                    case '3_months': $date->modify('+3 months'); break;
                    case '6_months': $date->modify('+6 months'); break;
                    case '12_months': $date->modify('+12 months'); break;
                }
                $expiryDate = $date->format('Y-m-d H:i:s');
            }
        }
        
        // Collect allowed extensions
        $allowedExtensions = [];
        if (isset($_POST['preset_extensions'])) {
            $allowedExtensions = array_merge($allowedExtensions, $_POST['preset_extensions']);
        }
        if (!empty($_POST['custom_extensions'])) {
            $customExts = array_map('trim', explode(',', $_POST['custom_extensions']));
            $customExts = array_map('strtolower', $customExts);
            $customExts = array_map(function($ext) { return ltrim($ext, ". \t"); }, $customExts);
            $customExts = array_filter($customExts);
            $allowedExtensions = array_merge($allowedExtensions, $customExts);
        }
        
        if (empty($allowedExtensions)) {
            echo json_encode(['success' => false, 'message' => 'At least one file type must be allowed']);
            exit;
        }
        
        $allowedExtensionsStr = implode(',', array_unique($allowedExtensions));
        
        $stmt = $conn->prepare("INSERT INTO ten_file_transfer_folders (folder_name, folder_code, upload_password, download_password, upload_public, download_public, allowed_extensions, expiry_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $userId = $_SESSION['ten_user_id'];
        $stmt->bind_param("ssssiissi", $folderName, $folderCode, $uploadPassword, $downloadPassword, $uploadPublic, $downloadPublic, $allowedExtensionsStr, $expiryDate, $userId);
        
        if ($stmt->execute()) {
            $folderId = $stmt->insert_id;
            logActivity('create_folder', 'file_transfer_folder', $folderId, "Created folder: $folderName");
            
            $uploadUrl = 'https://theeyenewspapers.com/file_transfers/upload.php?folder=' . $folderCode;
            $downloadUrl = 'https://theeyenewspapers.com/file_transfers/download.php?folder=' . $folderCode;
            
            echo json_encode([
                'success' => true, 
                'folder_id' => $folderId,
                'folder_code' => $folderCode,
                'upload_url' => $uploadUrl,
                'download_url' => $downloadUrl
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create folder']);
        }
        $stmt->close();
        $conn->close();
        exit;
    }
    
    if ($action === 'update_folder') {
        $folderId = intval($_POST['folder_id']);
        $folderName = sanitize($_POST['folder_name']);
        $uploadPublic = isset($_POST['upload_public']) ? 1 : 0;
        $downloadPublic = isset($_POST['download_public']) ? 1 : 0;
        
        // Only update passwords if new ones are provided
        $uploadPassword = null;
        $downloadPassword = null;
        $updatePasswords = false;
        
        if (!empty($_POST['upload_password'])) {
            $uploadPassword = password_hash($_POST['upload_password'], PASSWORD_DEFAULT);
            $updatePasswords = true;
        }
        if (!empty($_POST['download_password'])) {
            $downloadPassword = password_hash($_POST['download_password'], PASSWORD_DEFAULT);
            $updatePasswords = true;
        }
        
        // Handle expiry date
        $expiryDate = null;
        if (!empty($_POST['expiry_type'])) {
            $expiryType = $_POST['expiry_type'];
            if ($expiryType === 'custom' && !empty($_POST['expiry_date'])) {
                $expiryDate = $_POST['expiry_date'];
            } elseif ($expiryType !== 'never') {
                $date = new DateTime();
                switch ($expiryType) {
                    case '1_day': $date->modify('+1 day'); break;
                    case '2_days': $date->modify('+2 days'); break;
                    case '3_days': $date->modify('+3 days'); break;
                    case '4_days': $date->modify('+4 days'); break;
                    case '5_days': $date->modify('+5 days'); break;
                    case '6_days': $date->modify('+6 days'); break;
                    case '7_days': $date->modify('+7 days'); break;
                    case '1_week': $date->modify('+1 week'); break;
                    case '2_weeks': $date->modify('+2 weeks'); break;
                    case '3_weeks': $date->modify('+3 weeks'); break;
                    case '4_weeks': $date->modify('+4 weeks'); break;
                    case '1_month': $date->modify('+1 month'); break;
                    case '2_months': $date->modify('+2 months'); break;
                    case '3_months': $date->modify('+3 months'); break;
                    case '6_months': $date->modify('+6 months'); break;
                    case '12_months': $date->modify('+12 months'); break;
                }
                $expiryDate = $date->format('Y-m-d H:i:s');
            }
        }
        
        // Collect allowed extensions
        $allowedExtensions = [];
        if (isset($_POST['preset_extensions'])) {
            $allowedExtensions = array_merge($allowedExtensions, $_POST['preset_extensions']);
        }
        if (!empty($_POST['custom_extensions'])) {
            $customExts = array_map('trim', explode(',', $_POST['custom_extensions']));
            $customExts = array_map('strtolower', $customExts);
            $customExts = array_map(function($ext) { return ltrim($ext, ". \t"); }, $customExts);
            $customExts = array_filter($customExts);
            $allowedExtensions = array_merge($allowedExtensions, $customExts);
        }
        
        if (empty($allowedExtensions)) {
            echo json_encode(['success' => false, 'message' => 'At least one file type must be allowed']);
            exit;
        }
        
        $allowedExtensionsStr = implode(',', array_unique($allowedExtensions));
        
        if ($updatePasswords) {
            $stmt = $conn->prepare("UPDATE ten_file_transfer_folders SET folder_name = ?, upload_password = ?, download_password = ?, upload_public = ?, download_public = ?, allowed_extensions = ?, expiry_date = ? WHERE id = ?");
            $stmt->bind_param("sssiissi", $folderName, $uploadPassword, $downloadPassword, $uploadPublic, $downloadPublic, $allowedExtensionsStr, $expiryDate, $folderId);
        } else {
            $stmt = $conn->prepare("UPDATE ten_file_transfer_folders SET folder_name = ?, upload_public = ?, download_public = ?, allowed_extensions = ?, expiry_date = ? WHERE id = ?");
            $stmt->bind_param("siissi", $folderName, $uploadPublic, $downloadPublic, $allowedExtensionsStr, $expiryDate, $folderId);
        }
        
        if ($stmt->execute()) {
            logActivity('update_folder', 'file_transfer_folder', $folderId, "Updated folder: $folderName");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update folder']);
        }
        $stmt->close();
        $conn->close();
        exit;
    }
    
    if ($action === 'delete_folder') {
        $folderId = intval($_POST['folder_id']);
        
        // Get folder info
        $stmt = $conn->prepare("SELECT folder_name, folder_code FROM ten_file_transfer_folders WHERE id = ?");
        $stmt->bind_param("i", $folderId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($folder) {
            // Delete physical files
            $stmt = $conn->prepare("SELECT stored_filename FROM ten_file_transfer_files WHERE folder_id = ?");
            $stmt->bind_param("i", $folderId);
            $stmt->execute();
            $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $folderPath = '/home/tenuser/public_html/file_transfers/' . $folder['folder_code'];
            foreach ($files as $file) {
                $filePath = $folderPath . '/' . $file['stored_filename'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            
            // Remove directory if exists
            if (is_dir($folderPath)) {
                rmdir($folderPath);
            }
            
            // Delete from database (cascade will handle files and access records)
            $stmt = $conn->prepare("DELETE FROM ten_file_transfer_folders WHERE id = ?");
            $stmt->bind_param("i", $folderId);
            
            if ($stmt->execute()) {
                logActivity('delete_folder', 'file_transfer_folder', $folderId, "Deleted folder: " . $folder['folder_name']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete folder']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Folder not found']);
        }
        $conn->close();
        exit;
    }
    
    if ($action === 'get_folder_stats') {
        $folderId = intval($_POST['folder_id']);
        
        $stmt = $conn->prepare("SELECT COUNT(*) as file_count, COALESCE(SUM(file_size), 0) as total_size FROM ten_file_transfer_files WHERE folder_id = ?");
        $stmt->bind_param("i", $folderId);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'file_count' => $stats['file_count'],
            'total_size' => $stats['total_size']
        ]);
        $conn->close();
        exit;
    }
    
    if ($action === 'get_folder_files') {
        $folderId = intval($_POST['folder_id']);
        
        // Get folder details
        $stmt = $conn->prepare("SELECT * FROM ten_file_transfer_folders WHERE id = ?");
        $stmt->bind_param("i", $folderId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$folder) {
            echo json_encode(['success' => false, 'message' => 'Folder not found']);
            exit;
        }
        
        // Get all files with access stats and CV parsing status
        $filesQuery = "SELECT f.*, 
                       (SELECT COUNT(DISTINCT ip_address) FROM ten_file_transfer_access a 
                        WHERE a.folder_id = f.folder_id AND a.access_type = 'upload') as total_uploaders,
                       (SELECT COUNT(DISTINCT ip_address) FROM ten_file_transfer_access a 
                        WHERE a.folder_id = f.folder_id AND a.access_type = 'download') as total_downloaders,
                       COALESCE(c.ai_parsed, 0) as ai_parsed
                       FROM ten_file_transfer_files f
                       LEFT JOIN ten_cv_candidates c ON f.id = c.file_id
                       WHERE f.folder_id = ?
                       ORDER BY f.uploaded_at DESC";
        $stmt = $conn->prepare($filesQuery);
        $stmt->bind_param("i", $folderId);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get access logs for password-protected folders
        $uploadAccess = [];
        $downloadAccess = [];
        
        if (!$folder['upload_public']) {
            $stmt = $conn->prepare("SELECT ip_address, authenticated_at, last_activity FROM ten_file_transfer_access WHERE folder_id = ? AND access_type = 'upload' ORDER BY authenticated_at DESC");
            $stmt->bind_param("i", $folderId);
            $stmt->execute();
            $uploadAccess = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        
        if (!$folder['download_public']) {
            $stmt = $conn->prepare("SELECT ip_address, authenticated_at, last_activity FROM ten_file_transfer_access WHERE folder_id = ? AND access_type = 'download' ORDER BY authenticated_at DESC");
            $stmt->bind_param("i", $folderId);
            $stmt->execute();
            $downloadAccess = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        
        echo json_encode([
            'success' => true,
            'folder' => $folder,
            'files' => $files,
            'upload_access' => $uploadAccess,
            'download_access' => $downloadAccess
        ]);
        $conn->close();
        exit;
    }
}

// Get all folders
$foldersQuery = "SELECT f.*, u.full_name as creator_name,
                 (SELECT COUNT(*) FROM ten_file_transfer_files WHERE folder_id = f.id) as file_count,
                 (SELECT COALESCE(SUM(file_size), 0) FROM ten_file_transfer_files WHERE folder_id = f.id) as total_size
                 FROM ten_file_transfer_folders f
                 LEFT JOIN ten_users u ON f.created_by = u.id
                 ORDER BY f.created_at DESC";
$folders = $conn->query($foldersQuery)->fetch_all(MYSQLI_ASSOC);

$conn->close();
$currentPage = 'file_transfers';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Transfers - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
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
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }
        .page-header {
            margin-bottom: 32px;
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
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }
        .search-box {
            flex: 1;
            min-width: 250px;
            max-width: 400px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
        }
        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .btn-primary {
            padding: 12px 24px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .folders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
        }
        .folder-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.2s;
        }
        .folder-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        .folder-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .folder-icon {
            width: 48px;
            height: 48px;
            background: #eff6ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
            font-size: 24px;
        }
        .folder-name {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
        }
        .folder-code {
            font-size: 12px;
            color: #6b7280;
            font-family: 'Monaco', monospace;
        }
        .folder-stats {
            display: flex;
            gap: 20px;
            margin: 16px 0;
            padding: 12px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
        }
        .stat-item i {
            color: #9ca3af;
        }
        .folder-meta {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        .folder-meta div {
            margin-bottom: 6px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }
        .folder-actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            color: #6b7280;
            transition: all 0.2s;
        }
        .btn-icon:hover {
            border-color: #3b82f6;
            color: #3b82f6;
            background: #eff6ff;
        }
        .btn-icon.danger:hover {
            border-color: #dc2626;
            color: #dc2626;
            background: #fee2e2;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 32px;
        }
        .modal-header {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="datetime-local"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
            font-family: 'Monaco', monospace;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #374151;
            cursor: pointer;
        }
        .checkbox-label input {
            cursor: pointer;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }
        .btn-secondary {
            padding: 12px 24px;
            background: #e5e7eb;
            color: #374151;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .help-text {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        .url-display {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            font-family: 'Monaco', monospace;
            font-size: 13px;
            word-break: break-all;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .copy-btn {
            padding: 6px 12px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            white-space: nowrap;
            margin-left: 8px;
        }
        .copy-btn:hover {
            background: #2563eb;
        }
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            color: #374151;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6b7280;
            margin-bottom: 24px;
        }

        
        .content-wrapper {
            margin-top: 30px;
            max-width: 1400px;
        }
        
        /* CV Data Modal Styles */
        .cv-data-modal {
            z-index: 9998 !important;
        }
        
        .swal2-container.swal2-center.swal2-backdrop-show {
            z-index: 9999 !important;
        }
        
    </style>
</head>
<body>    
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
    <div class="content-wrapper" style="margin-left:30px;">
        <div class="page-header">
            <h1><i class="fas fa-folder-open"></i> File Transfers</h1>
            <p>Create secure file transfer folders with custom expiration and access controls</p>
        </div>

        <div class="actions-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search folders...">
            </div>
            <button class="btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Create Folder
            </button>
        </div>

        <?php if (empty($folders)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>No file transfer folders yet</h3>
            <p>Create your first folder to start sharing files securely</p>
            <button class="btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Create First Folder
            </button>
        </div>
        <?php else: ?>
        <div class="folders-grid" id="foldersGrid">
            <?php foreach ($folders as $folder): 
                $isExpired = $folder['expiry_date'] && strtotime($folder['expiry_date']) < time();
                $uploadUrl = 'https://theeyenewspapers.com/file_transfers/upload.php?folder=' . $folder['folder_code'];
                $downloadUrl = 'https://theeyenewspapers.com/file_transfers/download.php?folder=' . $folder['folder_code'];
            ?>
            <div class="folder-card" data-folder-name="<?php echo htmlspecialchars($folder['folder_name']); ?>">
                <div class="folder-header">
                    <div>
                        <div class="folder-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                    </div>
                    <div>
                        <?php if ($isExpired): ?>
                        <span class="status-badge status-expired">Expired</span>
                        <?php else: ?>
                        <span class="status-badge status-active">Active</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="folder-name"><?php echo htmlspecialchars($folder['folder_name']); ?></div>
                <div class="folder-code">Code: <?php echo htmlspecialchars($folder['folder_code']); ?></div>
                
                <div class="folder-stats">
                    <div class="stat-item">
                        <i class="fas fa-file"></i>
                        <span><?php echo $folder['file_count']; ?> files</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-database"></i>
                        <span><?php echo number_format($folder['total_size'] / 1024 / 1024, 2); ?> MB</span>
                    </div>
                </div>
                
                <div class="folder-meta">
                    <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($folder['creator_name']); ?></div>
                    <div><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($folder['created_at'])); ?></div>
                    <?php if ($folder['expiry_date']): ?>
                    <div><i class="fas fa-clock"></i> Expires: <?php echo date('M j, Y g:i A', strtotime($folder['expiry_date'])); ?></div>
                    <?php else: ?>
                    <div><i class="fas fa-infinity"></i> No expiration</div>
                    <?php endif; ?>
                    <div>
                        <i class="fas fa-upload"></i> Upload: <?php echo $folder['upload_public'] ? 'Public' : 'Password'; ?>
                        <i class="fas fa-download" style="margin-left:12px;"></i> Download: <?php echo $folder['download_public'] ? 'Public' : 'Password'; ?>
                    </div>
                </div>
                
                <div class="folder-actions">
                    <button class="btn-icon" onclick='showUrls(<?php echo json_encode($uploadUrl); ?>, <?php echo json_encode($downloadUrl); ?>)' title="Show URLs">
                        <i class="fas fa-link"></i>
                    </button>
                    <button class="btn-icon" onclick='viewFiles(<?php echo $folder['id']; ?>)' title="View Files">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-icon" onclick='editFolder(<?php echo json_encode($folder); ?>)' title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-icon danger" onclick="deleteFolder(<?php echo $folder['id']; ?>, '<?php echo htmlspecialchars($folder['folder_name'], ENT_QUOTES); ?>')" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Create/Edit Folder Modal -->
    <div class="modal" id="folderModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Create File Transfer Folder</div>
            <form id="folderForm">
                <input type="hidden" id="folderId" name="folder_id">
                <input type="hidden" id="formAction" name="action" value="create_folder">
                
                <div class="form-group">
                    <label>Folder Name</label>
                    <input type="text" id="folderName" name="folder_name" required>
                </div>
                
                <div class="form-group">
                    <label>Expiration</label>
                    <select id="expiryType" name="expiry_type">
                        <option value="never">Never expires</option>
                        <option value="1_day">1 Day</option>
                        <option value="2_days">2 Days</option>
                        <option value="3_days">3 Days</option>
                        <option value="4_days">4 Days</option>
                        <option value="5_days">5 Days</option>
                        <option value="6_days">6 Days</option>
                        <option value="7_days">7 Days</option>
                        <option value="1_week">1 Week</option>
                        <option value="2_weeks">2 Weeks</option>
                        <option value="3_weeks">3 Weeks</option>
                        <option value="4_weeks">4 Weeks</option>
                        <option value="1_month">1 Month</option>
                        <option value="2_months">2 Months</option>
                        <option value="3_months">3 Months</option>
                        <option value="6_months">6 Months</option>
                        <option value="12_months">12 Months</option>
                        <option value="custom">Custom Date</option>
                    </select>
                </div>
                
                <div class="form-group" id="customDateGroup" style="display:none;">
                    <label>Custom Expiration Date</label>
                    <input type="datetime-local" id="expiryDate" name="expiry_date">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Upload Access</label>
                        <div class="checkbox-label">
                            <input type="checkbox" id="uploadPublic" name="upload_public" checked>
                            <span>Public (no password)</span>
                        </div>
                        <input type="password" id="uploadPassword" name="upload_password" placeholder="Password (if not public)" style="margin-top:8px;">
                    </div>
                    
                    <div class="form-group">
                        <label>Download Access</label>
                        <div class="checkbox-label">
                            <input type="checkbox" id="downloadPublic" name="download_public" checked>
                            <span>Public (no password)</span>
                        </div>
                        <input type="password" id="downloadPassword" name="download_password" placeholder="Password (if not public)" style="margin-top:8px;">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Allowed File Types</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="pdf" checked>
                            <span>PDF</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="doc" checked>
                            <span>DOC</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="docx" checked>
                            <span>DOCX</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="xls">
                            <span>XLS</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="xlsx">
                            <span>XLSX</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="jpg" checked>
                            <span>JPG</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="jpeg" checked>
                            <span>JPEG</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="png" checked>
                            <span>PNG</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="gif">
                            <span>GIF</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="zip" checked>
                            <span>ZIP</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="rar">
                            <span>RAR</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="preset_extensions[]" value="txt">
                            <span>TXT</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Additional File Types (comma-separated)</label>
                    <textarea id="customExtensions" name="custom_extensions" placeholder="e.g., psd, ai, eps"></textarea>
                    <div class="help-text">Enter file extensions without dots, separated by commas</div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="submitBtn">Create Folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- File Viewer Modal -->
    <div class="modal" id="filesModal">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header" id="filesModalTitle">Folder Files</div>
            <div id="filesModalContent"></div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeFilesModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.folder-card');
            
            cards.forEach(card => {
                const name = card.dataset.folderName.toLowerCase();
                card.style.display = name.includes(searchTerm) ? 'block' : 'none';
            });
        });

        // Expiry type change
        document.getElementById('expiryType').addEventListener('change', function() {
            const customGroup = document.getElementById('customDateGroup');
            customGroup.style.display = this.value === 'custom' ? 'block' : 'none';
        });

        // Modal functions
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create File Transfer Folder';
            document.getElementById('folderForm').reset();
            document.getElementById('formAction').value = 'create_folder';
            document.getElementById('folderId').value = '';
            document.getElementById('submitBtn').textContent = 'Create Folder';
            document.getElementById('folderModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('folderModal').classList.remove('active');
        }

        function editFolder(folder) {
            document.getElementById('modalTitle').textContent = 'Edit File Transfer Folder';
            document.getElementById('formAction').value = 'update_folder';
            document.getElementById('folderId').value = folder.id;
            document.getElementById('folderName').value = folder.folder_name;
            document.getElementById('uploadPublic').checked = folder.upload_public == 1;
            document.getElementById('downloadPublic').checked = folder.download_public == 1;
            document.getElementById('submitBtn').textContent = 'Update Folder';
            
            // Set allowed extensions
            const allowedExts = folder.allowed_extensions.split(',');
            document.querySelectorAll('input[name="preset_extensions[]"]').forEach(cb => {
                cb.checked = allowedExts.includes(cb.value);
            });
            
            // Separate preset from custom
            const presetValues = Array.from(document.querySelectorAll('input[name="preset_extensions[]"]')).map(cb => cb.value);
            const customExts = allowedExts.filter(ext => !presetValues.includes(ext));
            document.getElementById('customExtensions').value = customExts.join(', ');
            
            document.getElementById('folderModal').classList.add('active');
        }

        function showUrls(uploadUrl, downloadUrl) {
            Swal.fire({
                title: 'Folder URLs',
                html: `
                    <div style="text-align:left; margin-bottom:16px;">
                        <strong>Upload URL:</strong>
                        <div class="url-display">
                            <span style="flex:1; word-break:break-all;">${uploadUrl}</span>
                            <button class="copy-btn" onclick="copyToClipboard('${uploadUrl}')">Copy</button>
                        </div>
                    </div>
                    <div style="text-align:left;">
                        <strong>Download URL:</strong>
                        <div class="url-display">
                            <span style="flex:1; word-break:break-all;">${downloadUrl}</span>
                            <button class="copy-btn" onclick="copyToClipboard('${downloadUrl}')">Copy</button>
                        </div>
                    </div>
                `,
                showCloseButton: true,
                showConfirmButton: false,
                width: 600
            });
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Copied!',
                    text: 'URL copied to clipboard',
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        }

        function deleteFolder(id, name) {
            Swal.fire({
                title: 'Delete Folder?',
                text: `This will permanently delete "${name}" and all its files. This cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete_folder');
                    formData.append('folder_id', id);
                    
                    fetch('module-file-transfers.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', 'Folder has been deleted.', 'success')
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', data.message || 'Failed to delete folder', 'error');
                        }
                    });
                }
            });
        }

        // Form submission
        document.getElementById('folderForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const action = formData.get('action');
            
            fetch('module-file-transfers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (action === 'create_folder') {
                        Swal.fire({
                            title: 'Folder Created!',
                            html: `
                                <div style="text-align:left; margin-top:16px;">
                                    <p style="margin-bottom:12px;">Your folder has been created successfully.</p>
                                    <strong>Upload URL:</strong>
                                    <div class="url-display">
                                        <span style="flex:1; word-break:break-all;">${data.upload_url}</span>
                                        <button class="copy-btn" onclick="copyToClipboard('${data.upload_url}')">Copy</button>
                                    </div>
                                    <strong style="display:block; margin-top:12px;">Download URL:</strong>
                                    <div class="url-display">
                                        <span style="flex:1; word-break:break-all;">${data.download_url}</span>
                                        <button class="copy-btn" onclick="copyToClipboard('${data.download_url}')">Copy</button>
                                    </div>
                                </div>
                            `,
                            icon: 'success',
                            confirmButtonText: 'Done'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Updated!', 'Folder has been updated.', 'success')
                            .then(() => location.reload());
                    }
                    closeModal();
                } else {
                    Swal.fire('Error', data.message || 'Operation failed', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error occurred', 'error');
            });
        });

        // Close modal on outside click
        document.getElementById('folderModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // View files function
        function viewFiles(folderId) {
            const formData = new FormData();
            formData.append('action', 'get_folder_files');
            formData.append('folder_id', folderId);
            
            fetch('module-file-transfers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayFilesModal(data);
                } else {
                    Swal.fire('Error', data.message || 'Failed to load files', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error occurred', 'error');
            });
        }
        
        function displayFilesModal(data) {
            const folder = data.folder;
            const files = data.files;
            const uploadAccess = data.upload_access;
            const downloadAccess = data.download_access;
            
            const filesModal = document.getElementById('filesModal');
            filesModal.dataset.folderId = folder.id;
            
            document.getElementById('filesModalTitle').textContent = folder.folder_name + ' - Files & Access';
            
            let html = '';
            
            // Files section
            html += '<div style="margin-bottom: 32px;">';
            html += '<h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px; color: #111827;"><i class="fas fa-file"></i> Files (' + files.length + ')</h3>';
            
            if (files.length === 0) {
                html += '<div style="text-align: center; padding: 40px; color: #9ca3af;">No files uploaded yet</div>';
            } else {
                html += '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr style="background: #f9fafb;">';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">File Name</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Size</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Uploader IP</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Uploaded</th>';
                html += '<th style="padding: 12px; text-align: center; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">CV Actions</th>';
                html += '</tr></thead><tbody>';
                
                files.forEach(file => {
                    const sizeKB = (file.file_size / 1024).toFixed(2);
                    const uploadedDate = new Date(file.uploaded_at).toLocaleString();
                    const displayIP = folder.upload_public == 0 ? file.uploader_ip : '***.' + file.uploader_ip.split('.').pop();
                    const fileExt = file.original_filename.split('.').pop().toLowerCase();
                    const isCVFormat = ['pdf', 'doc', 'docx'].includes(fileExt);
                    
                    html += '<tr style="border-bottom: 1px solid #e5e7eb;">';
                    html += '<td style="padding: 12px; font-size: 14px;">' + file.original_filename + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + sizeKB + ' KB</td>';
                    html += '<td style="padding: 12px; font-size: 14px; font-family: Monaco, monospace;">' + displayIP + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + uploadedDate + '</td>';
                    html += '<td style="padding: 12px; text-align: center;">';
                    
                    if (isCVFormat) {
                        if (file.ai_parsed == 1) {
                            html += '<button onclick="viewCVData(' + file.id + ')" style="padding: 6px 12px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; margin-right: 4px;" title="View CV Data"><i class="fas fa-eye"></i></button>';
                            html += '<button onclick="parseCV(' + file.id + ', true)" style="padding: 6px 12px; background: #d97706; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;" title="Reparse CV"><i class="fas fa-sync-alt"></i></button>';
                        } else {
                            html += '<button onclick="parseCV(' + file.id + ', false)" style="padding: 6px 12px; background: #2563eb; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;" title="Parse CV with AI"><i class="fas fa-robot"></i> Parse</button>';
                        }
                    } else {
                        html += '<span style="color: #9ca3af; font-size: 12px;">Not a CV</span>';
                    }
                    
                    html += '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
            }
            html += '</div>';
            
            // Upload access section (only for password-protected)
            if (!folder.upload_public && uploadAccess.length > 0) {
                html += '<div style="margin-bottom: 32px;">';
                html += '<h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px; color: #111827;"><i class="fas fa-upload"></i> Upload Access Log (' + uploadAccess.length + ' IPs)</h3>';
                html += '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr style="background: #f9fafb;">';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">IP Address</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">First Access</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Last Activity</th>';
                html += '</tr></thead><tbody>';
                
                uploadAccess.forEach(access => {
                    const firstAccess = new Date(access.authenticated_at).toLocaleString();
                    const lastActivity = new Date(access.last_activity).toLocaleString();
                    
                    html += '<tr style="border-bottom: 1px solid #e5e7eb;">';
                    html += '<td style="padding: 12px; font-size: 14px; font-family: Monaco, monospace;">' + access.ip_address + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + firstAccess + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + lastActivity + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                html += '</div>';
            }
            
            // Download access section (only for password-protected)
            if (!folder.download_public && downloadAccess.length > 0) {
                html += '<div>';
                html += '<h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px; color: #111827;"><i class="fas fa-download"></i> Download Access Log (' + downloadAccess.length + ' IPs)</h3>';
                html += '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr style="background: #f9fafb;">';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">IP Address</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">First Access</th>';
                html += '<th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Last Activity</th>';
                html += '</tr></thead><tbody>';
                
                downloadAccess.forEach(access => {
                    const firstAccess = new Date(access.authenticated_at).toLocaleString();
                    const lastActivity = new Date(access.last_activity).toLocaleString();
                    
                    html += '<tr style="border-bottom: 1px solid #e5e7eb;">';
                    html += '<td style="padding: 12px; font-size: 14px; font-family: Monaco, monospace;">' + access.ip_address + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + firstAccess + '</td>';
                    html += '<td style="padding: 12px; font-size: 14px;">' + lastActivity + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                html += '</div>';
            }
            
            document.getElementById('filesModalContent').innerHTML = html;
            document.getElementById('filesModal').classList.add('active');
        }
        
        function closeFilesModal() {
            document.getElementById('filesModal').classList.remove('active');
        }
        
        // Close files modal on outside click
        document.getElementById('filesModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFilesModal();
            }
        });
        
        // CV Parsing Functions
        function parseCV(fileId, forceReparse) {
            const action = forceReparse ? 'Reparse' : 'Parse';
            
            Swal.fire({
                title: action + ' CV with AI?',
                text: forceReparse ? 'This will overwrite existing CV data.' : 'This will extract all data from the CV using Claude AI.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, ' + action.toLowerCase()
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show processing alert
                    Swal.fire({
                        title: 'Processing CV...',
                        html: 'Extracting data with Claude AI. This may take a moment.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    const formData = new FormData();
                    formData.append('action', 'parse_cv');
                    formData.append('file_id', fileId);
                    formData.append('force_reparse', forceReparse ? '1' : '0');
                    
                    fetch('ajax/ajax_cv_parser.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                html: 'CV parsed successfully for <strong>' + data.candidate_name + '</strong>',
                                confirmButtonColor: '#059669'
                            }).then(() => {
                                // Refresh the files view
                                const currentFolderId = document.querySelector('#filesModal.active') ? 
                                    parseInt(document.getElementById('filesModal').dataset.folderId) : null;
                                if (currentFolderId) {
                                    viewFiles(currentFolderId);
                                }
                            });
                        } else {
                            let errorMsg = data.message || 'Failed to parse CV';
                            if (data.already_parsed) {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Already Parsed',
                                    text: errorMsg,
                                    confirmButtonColor: '#2563eb'
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Parsing Failed',
                                    html: errorMsg + (data.raw_response ? '<br><br><small>Raw response available in console</small>' : ''),
                                    confirmButtonColor: '#dc2626'
                                });
                                if (data.raw_response) {
                                    console.error('AI Response:', data.raw_response);
                                }
                            }
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Network error occurred',
                            confirmButtonColor: '#dc2626'
                        });
                    });
                }
            });
        }
        
        function viewCVData(fileId) {
            // Show loading
            Swal.fire({
                title: 'Loading CV Data...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('action', 'get_cv_data');
            formData.append('file_id', fileId);
            
            fetch('ajax/ajax_cv_parser.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    displayCVDataModal(data);
                } else {
                    Swal.fire('Error', data.message || 'Failed to load CV data', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'Network error occurred', 'error');
            });
        }
        
        function displayCVDataModal(data) {
            const candidate = data.candidate;
            const workExp = data.work_experience;
            const education = data.education;
            const skills = data.skills;
            const certifications = data.certifications;
            const languages = data.languages;
            const projects = data.projects;
            const references = data.references;
            
            let html = '<div style="max-height: 70vh; overflow-y: auto; padding: 0 4px;">';
            
            // Personal Information Section
            html += '<div style="background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 24px;">';
            html += '<h3 style="font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 16px;"><i class="fas fa-user"></i> ' + (candidate.full_name || 'Unknown') + '</h3>';
            
            if (candidate.summary) {
                html += '<p style="color: #4b5563; margin-bottom: 12px; line-height: 1.5;">' + candidate.summary + '</p>';
            }
            
            html += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; font-size: 14px;">';
            if (candidate.email) html += '<div><strong style="color: #6b7280;">Email:</strong> <a href="mailto:' + candidate.email + '" style="color: #2563eb;">' + candidate.email + '</a></div>';
            if (candidate.phone) html += '<div><strong style="color: #6b7280;">Phone:</strong> ' + candidate.phone + '</div>';
            if (candidate.address) html += '<div><strong style="color: #6b7280;">Address:</strong> ' + candidate.address + '</div>';
            if (candidate.city) html += '<div><strong style="color: #6b7280;">City:</strong> ' + candidate.city + '</div>';
            if (candidate.country) html += '<div><strong style="color: #6b7280;">Country:</strong> ' + candidate.country + '</div>';
            if (candidate.postcode) html += '<div><strong style="color: #6b7280;">Postcode:</strong> ' + candidate.postcode + '</div>';
            if (candidate.date_of_birth) html += '<div><strong style="color: #6b7280;">DOB:</strong> ' + candidate.date_of_birth + '</div>';
            if (candidate.nationality) html += '<div><strong style="color: #6b7280;">Nationality:</strong> ' + candidate.nationality + '</div>';
            if (candidate.linkedin_url) html += '<div style="grid-column: 1 / -1;"><strong style="color: #6b7280;">LinkedIn:</strong> <a href="' + candidate.linkedin_url + '" target="_blank" style="color: #2563eb;">' + candidate.linkedin_url + '</a></div>';
            if (candidate.website_url) html += '<div style="grid-column: 1 / -1;"><strong style="color: #6b7280;">Website:</strong> <a href="' + candidate.website_url + '" target="_blank" style="color: #2563eb;">' + candidate.website_url + '</a></div>';
            html += '</div>';
            html += '</div>';
            
            // Work Experience Section
            if (workExp.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-briefcase"></i> Work Experience</h4>';
                workExp.forEach(exp => {
                    html += '<div style="background: white; border-left: 4px solid #2563eb; padding: 16px; margin-bottom: 12px; border-radius: 4px;">';
                    html += '<div style="font-weight: 700; font-size: 16px; color: #111827;">' + (exp.job_title || 'Unknown Position') + '</div>';
                    html += '<div style="color: #6b7280; font-size: 14px; margin: 4px 0;">' + (exp.company_name || '') + (exp.location ? ' - ' + exp.location : '') + '</div>';
                    
                    let dateRange = '';
                    if (exp.start_date) dateRange += exp.start_date;
                    if (exp.is_current == 1) {
                        dateRange += ' - Present';
                    } else if (exp.end_date) {
                        dateRange += ' - ' + exp.end_date;
                    }
                    if (dateRange) html += '<div style="color: #9ca3af; font-size: 13px; margin-bottom: 8px;">' + dateRange + '</div>';
                    
                    if (exp.description) html += '<p style="color: #4b5563; font-size: 14px; margin: 8px 0; line-height: 1.5;">' + exp.description + '</p>';
                    if (exp.responsibilities) html += '<div style="margin-top: 8px;"><strong style="color: #6b7280; font-size: 13px;">Responsibilities:</strong><p style="color: #4b5563; font-size: 14px; margin: 4px 0; line-height: 1.5;">' + exp.responsibilities + '</p></div>';
                    if (exp.achievements) html += '<div style="margin-top: 8px;"><strong style="color: #6b7280; font-size: 13px;">Achievements:</strong><p style="color: #4b5563; font-size: 14px; margin: 4px 0; line-height: 1.5;">' + exp.achievements + '</p></div>';
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // Education Section
            if (education.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-graduation-cap"></i> Education</h4>';
                education.forEach(edu => {
                    html += '<div style="background: white; border-left: 4px solid #059669; padding: 16px; margin-bottom: 12px; border-radius: 4px;">';
                    html += '<div style="font-weight: 700; font-size: 16px; color: #111827;">' + (edu.degree_type || '') + (edu.field_of_study ? ' in ' + edu.field_of_study : '') + '</div>';
                    html += '<div style="color: #6b7280; font-size: 14px; margin: 4px 0;">' + (edu.institution_name || '') + (edu.location ? ' - ' + edu.location : '') + '</div>';
                    
                    let dateRange = '';
                    if (edu.start_date) dateRange += edu.start_date;
                    if (edu.end_date) dateRange += ' - ' + edu.end_date;
                    if (dateRange) html += '<div style="color: #9ca3af; font-size: 13px; margin-bottom: 8px;">' + dateRange + '</div>';
                    
                    if (edu.grade) html += '<div style="color: #4b5563; font-size: 14px; margin-top: 4px;"><strong>Grade:</strong> ' + edu.grade + '</div>';
                    if (edu.description) html += '<p style="color: #4b5563; font-size: 14px; margin-top: 8px; line-height: 1.5;">' + edu.description + '</p>';
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // Skills Section
            if (skills.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-cogs"></i> Skills</h4>';
                
                // Group skills by category
                const skillsByCategory = {};
                skills.forEach(skill => {
                    const category = skill.skill_category || 'Other';
                    if (!skillsByCategory[category]) skillsByCategory[category] = [];
                    skillsByCategory[category].push(skill);
                });
                
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">';
                Object.keys(skillsByCategory).forEach(category => {
                    html += '<div style="background: white; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">';
                    html += '<div style="font-weight: 700; color: #111827; margin-bottom: 8px; font-size: 14px;">' + category + '</div>';
                    html += '<div style="display: flex; flex-wrap: wrap; gap: 6px;">';
                    skillsByCategory[category].forEach(skill => {
                        let badge = '<span style="display: inline-block; background: #eff6ff; color: #1e40af; padding: 4px 10px; border-radius: 6px; font-size: 13px;">' + skill.skill_name;
                        if (skill.proficiency_level) badge += ' <span style="font-size: 11px; color: #60a5fa;">(' + skill.proficiency_level + ')</span>';
                        badge += '</span>';
                        html += badge;
                    });
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
                html += '</div>';
            }
            
            // Certifications Section
            if (certifications.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-certificate"></i> Certifications</h4>';
                certifications.forEach(cert => {
                    html += '<div style="background: white; border-left: 4px solid #d97706; padding: 16px; margin-bottom: 12px; border-radius: 4px;">';
                    html += '<div style="font-weight: 700; font-size: 15px; color: #111827;">' + (cert.certification_name || 'Unknown Certification') + '</div>';
                    if (cert.issuing_organization) html += '<div style="color: #6b7280; font-size: 14px; margin-top: 4px;">' + cert.issuing_organization + '</div>';
                    
                    let certDates = '';
                    if (cert.issue_date) certDates += 'Issued: ' + cert.issue_date;
                    if (cert.expiry_date) certDates += (certDates ? ' | ' : '') + 'Expires: ' + cert.expiry_date;
                    if (certDates) html += '<div style="color: #9ca3af; font-size: 13px; margin-top: 4px;">' + certDates + '</div>';
                    
                    if (cert.credential_id) html += '<div style="color: #4b5563; font-size: 13px; margin-top: 6px;"><strong>ID:</strong> ' + cert.credential_id + '</div>';
                    if (cert.credential_url) html += '<div style="margin-top: 6px;"><a href="' + cert.credential_url + '" target="_blank" style="color: #2563eb; font-size: 13px;">View Credential</a></div>';
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // Languages Section
            if (languages.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-language"></i> Languages</h4>';
                html += '<div style="display: flex; flex-wrap: wrap; gap: 12px;">';
                languages.forEach(lang => {
                    html += '<div style="background: white; border: 1px solid #e5e7eb; padding: 12px 16px; border-radius: 8px;">';
                    html += '<div style="font-weight: 600; color: #111827;">' + lang.language_name + '</div>';
                    if (lang.proficiency_level) html += '<div style="color: #6b7280; font-size: 13px; margin-top: 2px;">' + lang.proficiency_level + '</div>';
                    html += '</div>';
                });
                html += '</div>';
                html += '</div>';
            }
            
            // Projects Section
            if (projects.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-project-diagram"></i> Projects</h4>';
                projects.forEach(proj => {
                    html += '<div style="background: white; border-left: 4px solid #7c3aed; padding: 16px; margin-bottom: 12px; border-radius: 4px;">';
                    html += '<div style="font-weight: 700; font-size: 16px; color: #111827;">' + (proj.project_name || 'Unknown Project') + '</div>';
                    if (proj.project_role) html += '<div style="color: #6b7280; font-size: 14px; margin-top: 4px;">' + proj.project_role + '</div>';
                    
                    let projDates = '';
                    if (proj.start_date) projDates += proj.start_date;
                    if (proj.end_date) projDates += ' - ' + proj.end_date;
                    if (projDates) html += '<div style="color: #9ca3af; font-size: 13px; margin-top: 4px;">' + projDates + '</div>';
                    
                    if (proj.description) html += '<p style="color: #4b5563; font-size: 14px; margin-top: 8px; line-height: 1.5;">' + proj.description + '</p>';
                    if (proj.technologies_used) html += '<div style="margin-top: 8px;"><strong style="color: #6b7280; font-size: 13px;">Technologies:</strong> <span style="color: #4b5563; font-size: 14px;">' + proj.technologies_used + '</span></div>';
                    if (proj.project_url) html += '<div style="margin-top: 6px;"><a href="' + proj.project_url + '" target="_blank" style="color: #2563eb; font-size: 13px;">View Project</a></div>';
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // References Section
            if (references.length > 0) {
                html += '<div style="margin-bottom: 24px;">';
                html += '<h4 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 12px; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;"><i class="fas fa-user-friends"></i> References</h4>';
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">';
                references.forEach(ref => {
                    html += '<div style="background: white; border: 1px solid #e5e7eb; padding: 16px; border-radius: 8px;">';
                    html += '<div style="font-weight: 700; color: #111827;">' + (ref.reference_name || 'Unknown') + '</div>';
                    if (ref.job_title) html += '<div style="color: #6b7280; font-size: 14px; margin-top: 2px;">' + ref.job_title + '</div>';
                    if (ref.company) html += '<div style="color: #6b7280; font-size: 14px;">' + ref.company + '</div>';
                    if (ref.relationship) html += '<div style="color: #9ca3af; font-size: 13px; margin-top: 6px;">Relationship: ' + ref.relationship + '</div>';
                    if (ref.email) html += '<div style="margin-top: 6px;"><a href="mailto:' + ref.email + '" style="color: #2563eb; font-size: 13px;">' + ref.email + '</a></div>';
                    if (ref.phone) html += '<div style="color: #4b5563; font-size: 13px; margin-top: 2px;">' + ref.phone + '</div>';
                    html += '</div>';
                });
                html += '</div>';
                html += '</div>';
            }
            
            html += '</div>';
            
            // Show in SweetAlert modal
            Swal.fire({
                title: '<div style="font-size: 24px; font-weight: 700; color: #111827;">CV Data: ' + (candidate.full_name || 'Unknown') + '</div>',
                html: html,
                width: '90%',
                showConfirmButton: true,
                confirmButtonText: 'Close',
                confirmButtonColor: '#6b7280',
                customClass: {
                    popup: 'cv-data-modal'
                }
            });
        }
        
        function closeCVDataModal() {
            document.getElementById('cvDataModal').classList.remove('active');
        }
        
        document.getElementById('cvDataModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCVDataModal();
            }
        });
    </script>
</body>
</html>
