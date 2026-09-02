<?php
require_once 'config.php';
requireAdmin(); // Only admins can access settings

$conn = getDBConnection();

// Get all module groups
$groupsQuery = "SELECT * FROM ten_module_groups ORDER BY display_order";
$groups = $conn->query($groupsQuery);

// Get all modules
$modulesQuery = "SELECT * FROM ten_modules ORDER BY display_order";
$modules = $conn->query($modulesQuery);

$conn->close();

$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('settings.title', 'System Settings'); ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
            border-bottom: 2px solid #e5e7eb;
        }
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: -2px;
        }
        .tab:hover {
            color: #667eea;
        }
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .card-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }
        .btn-primary {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .items-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .item-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .item-row:hover {
            border-color: #e5e7eb;
        }
        .drag-handle {
            cursor: grab;
            color: #9ca3af;
            font-size: 18px;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        .item-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 18px;
        }
        .item-info {
            flex: 1;
            min-width: 0;
        }
        .item-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 2px;
        }
        .item-description {
            font-size: 13px;
            color: #6b7280;
        }
        .item-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-enabled {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-disabled {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-system {
            background: #dbeafe;
            color: #1e40af;
        }
        .item-actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }
        .btn-edit {
            background: #eff6ff;
            color: #1e40af;
        }
        .btn-edit:hover {
            background: #dbeafe;
        }
        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-delete:hover {
            background: #fecaca;
        }
        .btn-toggle {
            background: #f3f4f6;
            color: #374151;
        }
        .btn-toggle:hover {
            background: #e5e7eb;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
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
            max-width: 600px;
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
            font-weight: 700;
            color: #111827;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #9ca3af;
            cursor: pointer;
        }
        .modal-body {
            padding: 24px;
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
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input {
            width: auto;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header" style="margin-bottom: 32px;">
                <h1><i class="fas fa-cog"></i> <?php echo t('settings.title', 'System Settings'); ?></h1>
                <p><?php echo t('settings.subtitle', 'Configure system modules, groups, and preferences'); ?></p>
            </div>
            
            <div id="alert-container"></div>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('modules')"><?php echo t('settings.tab.modules', 'Modules'); ?></button>
                <button class="tab" onclick="switchTab('groups')"><?php echo t('settings.tab.groups', 'Groups'); ?></button>
                <button class="tab" onclick="switchTab('general')"><?php echo t('settings.tab.general', 'General'); ?></button>
            </div>
            
            <!-- Modules Tab -->
            <div id="modules-tab" class="tab-content active">
                <div class="settings-card">
                    <div class="card-header">
                        <h2><?php echo t('settings.modules.title', 'System Modules'); ?></h2>
                        <button class="btn-primary" onclick="openCreateModuleModal()">
                            <i class="fas fa-plus"></i>
                            <?php echo t('settings.add_module', 'Add Module'); ?>
                        </button>
                    </div>
                    
                    <div class="items-list" id="modulesList">
                        <?php while ($module = $modules->fetch_assoc()): ?>
                            <div class="item-row" data-id="<?php echo $module['id']; ?>">
                                <i class="fas fa-grip-vertical drag-handle"></i>
                                <div class="item-icon">
                                    <i class="fa <?php echo htmlspecialchars($module['module_icon']); ?>"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-name"><?php echo htmlspecialchars($module['module_name']); ?></div>
                                    <div class="item-description"><?php echo htmlspecialchars($module['module_key']); ?></div>
                                </div>
                                <span class="item-badge badge-<?php echo $module['is_enabled'] ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $module['is_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                </span>
                                <?php if ($module['is_system']): ?>
                                    <span class="item-badge badge-system">System</span>
                                <?php endif; ?>
                                <div class="item-actions">
                                    <button class="btn-icon btn-toggle" onclick="toggleModule(<?php echo $module['id']; ?>, <?php echo $module['is_enabled']; ?>)">
                                        <i class="fas fa-toggle-<?php echo $module['is_enabled'] ? 'on' : 'off'; ?>"></i>
                                    </button>
                                    <button class="btn-icon btn-edit" onclick="editModule(<?php echo $module['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if (!$module['is_system']): ?>
                                        <button class="btn-icon btn-delete" onclick="deleteModule(<?php echo $module['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <!-- Groups Tab -->
            <div id="groups-tab" class="tab-content">
                <div class="settings-card">
                    <div class="card-header">
                        <h2><?php echo t('settings.groups.title', 'Module Groups'); ?></h2>
                        <button class="btn-primary" onclick="openCreateGroupModal()">
                            <i class="fas fa-plus"></i>
                            <?php echo t('settings.add_group', 'Add Group'); ?>
                        </button>
                    </div>
                    
                    <div class="items-list" id="groupsList">
                        <?php while ($group = $groups->fetch_assoc()): ?>
                            <div class="item-row" data-id="<?php echo $group['id']; ?>">
                                <i class="fas fa-grip-vertical drag-handle"></i>
                                <div class="item-icon">
                                    <i class="fa <?php echo htmlspecialchars($group['group_icon']); ?>"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-name"><?php echo htmlspecialchars($group['group_name']); ?></div>
                                    <div class="item-description"><?php echo htmlspecialchars($group['group_key']); ?></div>
                                </div>
                                <div class="item-actions">
                                    <button class="btn-icon btn-edit" onclick="editGroup(<?php echo $group['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="deleteGroup(<?php echo $group['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <!-- General Tab -->
            <div id="general-tab" class="tab-content">
                <div class="settings-card">
                    <h2 style="margin-bottom: 20px;"><?php echo t('settings.general.title', 'General Settings'); ?></h2>
                    <p style="color: #6b7280;"><?php echo t('settings.general.coming_soon', 'Additional system settings coming soon...'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Module Modal -->
    <div class="modal" id="moduleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="moduleModalTitle"><?php echo t('settings.create_module', 'Create Module'); ?></h2>
                <button class="modal-close" onclick="closeModuleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="moduleForm">
                    <input type="hidden" name="action" value="create_module">
                    <input type="hidden" name="module_id" id="moduleId">
                    
                    <div class="form-group">
                        <label><?php echo t('settings.module_name', 'Module Name'); ?> *</label>
                        <input type="text" name="module_name" id="moduleName" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.module_key', 'Module Key'); ?> *</label>
                        <input type="text" name="module_key" id="moduleKey" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.description', 'Description'); ?></label>
                        <textarea name="module_description" id="moduleDescription"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.icon', 'Icon'); ?></label>
                        <input type="text" name="module_icon" id="moduleIcon" placeholder="fa-cube">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.url', 'URL'); ?></label>
                        <input type="text" name="module_url" id="moduleUrl" placeholder="/management/module-name.php">
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.group', 'Group'); ?></label>
                        <select name="module_group" id="moduleGroup">
                            <?php
                            $conn = getDBConnection();
                            $groupsSelect = $conn->query("SELECT * FROM ten_module_groups ORDER BY group_name");
                            while ($g = $groupsSelect->fetch_assoc()):
                            ?>
                                <option value="<?php echo htmlspecialchars($g['group_key']); ?>">
                                    <?php echo htmlspecialchars($g['group_name']); ?>
                                </option>
                            <?php endwhile; 
                            $conn->close();
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_enabled" id="moduleEnabled" checked>
                            <label for="moduleEnabled" style="margin: 0;"><?php echo t('settings.enabled', 'Enabled'); ?></label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i>
                        <?php echo t('settings.save', 'Save Module'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Group Modal -->
    <div class="modal" id="groupModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="groupModalTitle"><?php echo t('settings.create_group', 'Create Group'); ?></h2>
                <button class="modal-close" onclick="closeGroupModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="groupForm">
                    <input type="hidden" name="action" value="create_group">
                    <input type="hidden" name="group_id" id="groupId">
                    
                    <div class="form-group">
                        <label><?php echo t('settings.group_name', 'Group Name'); ?> *</label>
                        <input type="text" name="group_name" id="groupName" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.group_key', 'Group Key'); ?> *</label>
                        <input type="text" name="group_key" id="groupKey" required>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.description', 'Description'); ?></label>
                        <textarea name="group_description" id="groupDescription"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo t('settings.icon', 'Icon'); ?></label>
                        <input type="text" name="group_icon" id="groupIcon" placeholder="fa-folder">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i>
                        <?php echo t('settings.save', 'Save Group'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="ajax/settings.js"></script>
</body>
</html>
