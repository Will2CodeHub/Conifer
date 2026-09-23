<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

$conn = getDBConnection();
$connAdmin = getDBConnection_TENAdmin();

// Get current user from TEN Management
$userId = $_SESSION['ten_user_id'];
$userQuery = "SELECT * FROM ten_users WHERE id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get user from admin_ten database using email
$adminUser = null;
if ($user['email']) {
    $adminQuery = "SELECT * FROM users WHERE username = ?";
    $stmtAdmin = $connAdmin->prepare($adminQuery);
    $stmtAdmin->bind_param("s", $user['email']);
    $stmtAdmin->execute();
    $adminUser = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

// Sections from main_menu, scoped to the user's own publication(s) so their
// section list matches the sites they write for (falls back to all sections if
// they have no publication assigned).
$sections = [];
$userPubList = array_values(array_filter(array_map('trim', explode(',', (string)($user['publication'] ?? '')))));
if ($userPubList) {
    $seenSec = [];
    $secStmt = $connAdmin->prepare("SELECT name FROM main_menu WHERE edition = ? AND name != '' AND section_item = '1' AND parent_item = 0 ORDER BY position ASC, name ASC");
    if ($secStmt) {
        foreach ($userPubList as $ed) {
            $secStmt->bind_param('s', $ed);
            $secStmt->execute();
            $sr = $secStmt->get_result();
            while ($row = $sr->fetch_assoc()) {
                if (!isset($seenSec[$row['name']])) { $seenSec[$row['name']] = true; $sections[] = $row['name']; }
            }
            $sr->free();
        }
        $secStmt->close();
    }
    sort($sections);
}
if (!$sections) {
    $sectionsResult = $connAdmin->query("SELECT DISTINCT name FROM main_menu WHERE name != '' AND section_item = '1' AND parent_item = 0 AND id != 79 ORDER BY name");
    while ($sectionsResult && $row = $sectionsResult->fetch_assoc()) { $sections[] = $row['name']; }
}

$conn->close();
$connAdmin->close();

$currentPage = 'profile';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .page-header {
            margin-bottom: 32px;
            margin-left: 30px;
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
        .profile-container {
            background: white;
            margin-left: 30px;
            overflow: hidden;
        }
        .profile-header {
            padding: 32px;
            text-align: center;
            position: relative;
            background: linear-gradient(135deg, #4b5c87 0%, #2d3a5c 100%);
            border-radius: 0;
        }
        .profile-avatar-container {
            position: relative;
            display: inline-block;
        }
        .profile-avatar {
            width: 160px;
            height: 160px;
            border-radius: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-avatar i {
            font-size: 64px;
            color: #9ca3af;
        }
        .edit-avatar-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            width: 40px;
            height: 40px;
            background: white;
            border: 2px solid #4b5c87;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .edit-avatar-btn:hover {
            background: #4b5c87;
            color: white;
        }
        .profile-name {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-top: 16px;
        }
        .profile-email {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            margin-top: 4px;
        }
        .tabs-container {
            border-bottom: 2px solid #e5e7eb;
            background: #f9fafb;
        }
        .tabs {
            display: flex;
            padding: 0 32px;
            gap: 8px;
        }
        .tab {
            padding: 16px 24px;
            background: transparent;
            border: none;
            color: #6b7280;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        .tab:hover {
            color: #4b5c87;
        }
        .tab.active {
            color: #4b5c87;
        }
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #4b5c87;
        }
        .tab-content {
            display: none;
            padding: 32px;
        }
        .tab-content.active {
            display: block;
        }
        .form-section {
            margin-bottom: 32px;
        }
        .form-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4b5c87;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .btn-save {
            padding: 12px 32px;
            background: #4b5c87;
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
        .btn-save:hover {
            background: #3d4a6b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(75, 92, 135, 0.3);
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
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 24px;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
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
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-body {
            padding: 24px;
        }
        .image-crop-container {
            max-width: 100%;
            margin: 20px 0;
        }
        .crop-preview {
            width: 200px;
            height: 200px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px auto;
        }
        .btn-secondary {
            padding: 12px 24px;
            background: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 8px;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        .upload-area {
            border: 2px dashed #e5e7eb;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .upload-area:hover {
            border-color: #4b5c87;
            background: #f9fafb;
        }
        .upload-area i {
            font-size: 48px;
            color: #9ca3af;
            margin-bottom: 12px;
        }
        .upload-area p {
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="page-header">
            <h1>My Profile</h1>
            <p>Manage your personal information and preferences</p>
        </div>

        <?php
        $editorialPositions = ['Journalist', 'Section Editor', 'Editor', 'Managing Editor', 'General Editor'];
        if (in_array($_SESSION['ten_position'] ?? '', $editorialPositions, true)):
        ?>
        <div style="margin:0 30px 20px;">
            <a href="tutorial.php" style="display:inline-flex;align-items:center;gap:8px;background:#3c4f6d;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;">
                <i class="fas fa-graduation-cap"></i> Guide for your role
            </a>
        </div>
        <?php endif; ?>

        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-avatar-container">
                    <div class="profile-avatar">
                        <?php if (!empty($user['profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile">
                        <?php elseif (!empty($adminUser['photo']) && file_exists('journalist-photo/' . $adminUser['photo'])): ?>
                            <img src="journalist-photo/<?php echo htmlspecialchars($adminUser['photo']); ?>" alt="Profile">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <button class="edit-avatar-btn" onclick="openPhotoModal()">
                        <i class="fas fa-camera"></i>
                    </button>
                </div>
                <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
            </div>
            
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab active" onclick="switchTab(this, 'management')">
                        <i class="fas fa-cog"></i> TEN Management
                    </button>
                    <button class="tab" onclick="switchTab(this, 'newsportal')">
                        <i class="fas fa-newspaper"></i> TEN News Portal
                    </button>
                </div>
            </div>
            
            <!-- TEN Management Tab -->
            <div id="tab-management" class="tab-content active">
                <form id="managementForm" onsubmit="saveManagementProfile(event)">
                    <div class="form-section">
                        <h3>Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Language</label>
                                <select name="language_preference">
                                    <option value="en" <?php echo ($user['language_preference'] ?? 'en') == 'en' ? 'selected' : ''; ?>>English</option>
                                    <option value="de" <?php echo ($user['language_preference'] ?? 'en') == 'de' ? 'selected' : ''; ?>>Deutsch</option>
                                    <option value="es" <?php echo ($user['language_preference'] ?? 'en') == 'es' ? 'selected' : ''; ?>>Español</option>
                                    <option value="pt" <?php echo ($user['language_preference'] ?? 'en') == 'pt' ? 'selected' : ''; ?>>Português</option>
                                    <option value="ru" <?php echo ($user['language_preference'] ?? 'en') == 'ru' ? 'selected' : ''; ?>>Русский</option>
                                    <option value="ja" <?php echo ($user['language_preference'] ?? 'en') == 'ja' ? 'selected' : ''; ?>>日本語</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Change Password</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>New Password</label>
                                <div style="position: relative;">
                                    <input type="password" name="new_password" id="new_password" style="padding-right: 45px;">
                                    <i class="fas fa-eye" id="toggleNewPassword" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af;"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <div style="position: relative;">
                                    <input type="password" name="confirm_password" id="confirm_password" style="padding-right: 45px;">
                                    <i class="fas fa-eye" id="toggleConfirmPassword" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
            
            <!-- TEN News Portal Tab -->
            <div id="tab-newsportal" class="tab-content">
                <form id="newsportalForm" onsubmit="saveNewsPortalProfile(event)">
                    <div class="form-section">
                        <h3>Professional Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="firstname" value="<?php echo htmlspecialchars($adminUser['firstname'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middlename" value="<?php echo htmlspecialchars($adminUser['middlename'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Surname</label>
                                <input type="text" name="surname" value="<?php echo htmlspecialchars($adminUser['surname'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Pen Name</label>
                                <input type="text" name="pen_name" value="<?php echo htmlspecialchars($adminUser['pen_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Alias</label>
                                <input type="text" name="alias" value="<?php echo htmlspecialchars($adminUser['alias'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Role & Assignment</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Position</label>
                                <select name="position" <?php echo !isAdmin() ? 'disabled' : ''; ?>>
                                    <option value="Admin" <?php echo ($adminUser['position'] ?? '') == 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="Publisher" <?php echo ($adminUser['position'] ?? '') == 'Publisher' ? 'selected' : ''; ?>>Publisher</option>
                                    <option value="Editor-in-Chief" <?php echo ($adminUser['position'] ?? '') == 'Editor-in-Chief' ? 'selected' : ''; ?>>Editor-in-Chief</option>
                                    <option value="General Editor" <?php echo ($adminUser['position'] ?? '') == 'General Editor' ? 'selected' : ''; ?>>General Editor</option>
                                    <option value="Managing Editor" <?php echo ($adminUser['position'] ?? '') == 'Managing Editor' ? 'selected' : ''; ?>>Managing Editor</option>
                                    <option value="Photo Editor" <?php echo ($adminUser['position'] ?? '') == 'Photo Editor' ? 'selected' : ''; ?>>Photo Editor</option>
                                    <option value="Section Editor" <?php echo ($adminUser['position'] ?? '') == 'Section Editor' ? 'selected' : ''; ?>>Section Editor</option>
                                    <option value="Assignment Editor" <?php echo ($adminUser['position'] ?? '') == 'Assignment Editor' ? 'selected' : ''; ?>>Assignment Editor</option>
                                    <option value="Boxing Correspondent" <?php echo ($adminUser['position'] ?? '') == 'Boxing Correspondent' ? 'selected' : ''; ?>>Boxing Correspondent</option>
                                    <option value="Journalist" <?php echo ($adminUser['position'] ?? '') == 'Journalist' ? 'selected' : ''; ?>>Journalist</option>
                                    <option value="Citizen Journalist" <?php echo ($adminUser['position'] ?? '') == 'Citizen Journalist' ? 'selected' : ''; ?>>Citizen Journalist</option>
                                    <option value="Guest" <?php echo ($adminUser['position'] ?? '') == 'Guest' ? 'selected' : ''; ?>>Guest</option>
                                    <option value="PHI Broker" <?php echo ($adminUser['position'] ?? '') == 'PHI Broker' ? 'selected' : ''; ?>>PHI Broker</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role" <?php echo !isAdmin() ? 'disabled' : ''; ?>>
                                    <option value="admin" <?php echo ($adminUser['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="journalist" <?php echo ($adminUser['role'] ?? '') == 'journalist' ? 'selected' : ''; ?>>Journalist</option>
                                    <option value="candidate" <?php echo ($adminUser['role'] ?? '') == 'candidate' ? 'selected' : ''; ?>>Candidate</option>
                                    <option value="broker" <?php echo ($adminUser['role'] ?? '') == 'broker' ? 'selected' : ''; ?>>Broker</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Section</label>
                                <select name="section">
                                    <option value="">-- Select Section --</option>
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?php echo htmlspecialchars($section); ?>" <?php echo ($adminUser['section'] ?? '') == $section ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($section); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Byline & Biography</h3>
                        <div class="form-group">
                            <label>Byline</label>
                            <input type="text" name="byline" value="<?php echo htmlspecialchars($adminUser['byline'] ?? ''); ?>" placeholder="How your name appears in articles">
                        </div>
                        <div class="form-group">
                            <label>Biography</label>
                            <textarea name="bio" placeholder="Tell us about yourself"><?php echo htmlspecialchars($adminUser['bio'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Photo Upload Modal -->
    <div class="modal" id="photoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Update Profile Photo</h2>
                <button class="modal-close" onclick="closePhotoModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="uploadArea" class="upload-area" onclick="document.getElementById('photoInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p><strong>Click to upload</strong> or drag and drop</p>
                    <p style="font-size: 12px; color: #9ca3af;">PNG, JPG up to 10MB</p>
                </div>
                <input type="file" id="photoInput" accept="image/*" style="display: none;" onchange="handlePhotoSelect(event)">
                
                <div id="cropArea" style="display: none;">
                    <div class="image-crop-container">
                        <img id="cropImage" style="max-width: 100%;">
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <button type="button" class="btn-secondary" onclick="cancelCrop()">Cancel</button>
                        <button type="button" class="btn-save" onclick="saveCroppedPhoto()">
                            <i class="fas fa-check"></i> Save Photo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <script>
    let cropper = null;
    
    function switchTab(el, tabName) {
        // Update tab buttons
        document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
        (el.closest ? el.closest('.tab') : el).classList.add('active');

        // Update tab content
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
    }
    
    function openPhotoModal() {
        document.getElementById('photoModal').classList.add('active');
    }
    
    function closePhotoModal() {
        document.getElementById('photoModal').classList.remove('active');
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        document.getElementById('uploadArea').style.display = 'block';
        document.getElementById('cropArea').style.display = 'none';
        document.getElementById('photoInput').value = '';
    }
    
    function handlePhotoSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        if (!file.type.match('image.*')) {
            Swal.fire('Error', 'Please select an image file', 'error');
            return;
        }
        
        if (file.size > 10 * 1024 * 1024) {
            Swal.fire('Error', 'File size must be less than 10MB', 'error');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('uploadArea').style.display = 'none';
            document.getElementById('cropArea').style.display = 'block';
            document.getElementById('cropImage').src = e.target.result;
            
            if (cropper) {
                cropper.destroy();
            }
            
            cropper = new Cropper(document.getElementById('cropImage'), {
                aspectRatio: 1,
                viewMode: 2,
                autoCropArea: 1,
                responsive: true,
                background: false,
                zoomable: true,
                movable: true,
                rotatable: true
            });
        };
        reader.readAsDataURL(file);
    }
    
    function cancelCrop() {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        document.getElementById('uploadArea').style.display = 'block';
        document.getElementById('cropArea').style.display = 'none';
        document.getElementById('photoInput').value = '';
    }
    
    function saveCroppedPhoto() {
        if (!cropper) return;
        
        cropper.getCroppedCanvas({
            width: 200,
            height: 200
        }).toBlob(function(blob) {
            const formData = new FormData();
            formData.append('action', 'upload_photo');
            formData.append('photo', blob, 'profile.png');
            
            fetch('ajax/save_profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Profile photo updated successfully', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to upload photo', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to upload photo', 'error');
            });
        });
    }
    
    function saveManagementProfile(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        formData.append('action', 'save_management_profile');
        
        // Validate password if provided
        const newPassword = formData.get('new_password');
        const confirmPassword = formData.get('confirm_password');
        
        if (newPassword || confirmPassword) {
            if (newPassword !== confirmPassword) {
                Swal.fire('Error', 'New passwords do not match', 'error');
                return;
            }
            if (newPassword.length < 8) {
                Swal.fire('Error', 'Password must be at least 8 characters', 'error');
                return;
            }
        }
        
        fetch('ajax/save_profile.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success', 'Profile updated successfully', 'success').then(() => {
                    if (newPassword) {
                        location.reload();
                    }
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to update profile', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to update profile', 'error');
        });
    }
    
    function saveNewsPortalProfile(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        formData.append('action', 'save_newsportal_profile');
        
        fetch('ajax/save_profile.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success', 'Profile updated successfully', 'success');
            } else {
                Swal.fire('Error', data.message || 'Failed to update profile', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to update profile', 'error');
        });
    }
    
    // Drag and drop for photo upload
    const uploadArea = document.getElementById('uploadArea');
    
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#4b5c87';
        uploadArea.style.background = '#f9fafb';
    });
    
    uploadArea.addEventListener('dragleave', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#e5e7eb';
        uploadArea.style.background = 'white';
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#e5e7eb';
        uploadArea.style.background = 'white';

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            // Use DataTransfer to assign files to input (read-only FileList workaround)
            try {
                const dt = new DataTransfer();
                for (let i = 0; i < files.length; i++) dt.items.add(files[i]);
                document.getElementById('photoInput').files = dt.files;
            } catch(ex) {}
            handlePhotoSelect({ target: { files: files } });
        }
    });
    
    // Password toggle functionality
    document.getElementById('toggleNewPassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('new_password');
        const icon = this;
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
    
    document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('confirm_password');
        const icon = this;
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
    </script>
</body>
</html>