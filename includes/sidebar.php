<?php
// Get user's accessible modules grouped by category
$conn = getDBConnection();

$userId = $_SESSION['ten_user_id'];
$isUserAdmin = isAdmin();

// Get module groups with their modules
$groupsQuery = "SELECT * FROM ten_module_groups ORDER BY display_order";
$groupsResult = $conn->query($groupsQuery);
$moduleGroups = [];

while ($group = $groupsResult->fetch_assoc()) {
    $groupKey = $group['group_key'];
    $moduleGroups[$groupKey] = $group;
    $moduleGroups[$groupKey]['modules'] = [];
    
    // Get modules for this group
    // required_permission is ALWAYS enforced (even for admins) so PKV/Health Insurance
    // can be restricted to specific roles regardless of admin status.
    // Modules with required_permission = NULL are visible to everyone.
    $modulesQuery = "SELECT m.* FROM ten_modules m
                    WHERE m.module_group = ? AND m.is_enabled = 1
                    AND (m.required_permission IS NULL OR m.required_permission IN (
                        SELECT p.permission_key FROM ten_user_roles ur
                        JOIN ten_role_permissions rp ON ur.role_id = rp.role_id
                        JOIN ten_permissions p ON rp.permission_id = p.id
                        WHERE ur.user_id = ?
                    ))
                    ORDER BY m.display_order";
    $modulesStmt = $conn->prepare($modulesQuery);
    $modulesStmt->bind_param("si", $groupKey, $userId);
    
    $modulesStmt->execute();
    $modulesResult = $modulesStmt->get_result();
    
    while ($module = $modulesResult->fetch_assoc()) {
        $moduleGroups[$groupKey]['modules'][] = $module;
    }
    $modulesStmt->close();
}

$conn->close();
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-newspaper"></i>
            <span>TEN Management</span>
        </div>
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="sidebar-menu">
        <?php foreach ($moduleGroups as $group): ?>
            <?php if (!empty($group['modules'])): ?>
                <div class="menu-group">
                    <div class="menu-group-header" onclick="toggleGroup(this)">
                        <div>
                            <i class="fa <?php echo htmlspecialchars($group['group_icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($group['group_name']); ?></span>
                        </div>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                    <div class="menu-group-items <?php echo $group['is_collapsed'] ? 'collapsed' : ''; ?>">
                        <?php foreach ($group['modules'] as $module): ?>
                            <?php 
                            $isActive = ($currentPage ?? '') === $module['module_key'];
                            $moduleUrl = $module['module_url'] ?: '#';
                            ?>
                            <a href="<?php echo htmlspecialchars($moduleUrl); ?>" 
                               class="menu-item <?php echo $isActive ? 'active' : ''; ?>">
                                <i class="fa <?php echo htmlspecialchars($module['module_icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($module['module_name']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php if ($currentUser['profile_image']): ?>
                    <img src="<?php echo htmlspecialchars($currentUser['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($currentUser['email']); ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</div>

<style>
.sidebar {
    width: 280px;
    height: 100vh;
    background: #2e2e2e;
    position: fixed;
    left: 0;
    top: 0;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    transition: transform 0.3s ease;
}

.sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid #334155;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
    font-size: 20px;
    font-weight: 700;
}

.logo i {
    font-size: 28px;
}

.sidebar-toggle {
    display: none;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    padding: 8px;
}

.sidebar-menu {
    flex: 1;
    overflow-y: auto;
    padding: 16px 0;
}

.sidebar-menu::-webkit-scrollbar {
    width: 6px;
}

.sidebar-menu::-webkit-scrollbar-track {
    background: #1e293b;
}

.sidebar-menu::-webkit-scrollbar-thumb {
    background: #475569;
    border-radius: 3px;
}

.menu-group {
    margin-bottom: 8px;
}

.menu-group-header {
    padding: 12px 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
}

.menu-group-header:hover {
    color: #cbd5e1;
}

.menu-group-header > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.toggle-icon {
    font-size: 10px;
    transition: transform 0.3s ease;
}

.menu-group-header.collapsed .toggle-icon {
    transform: rotate(-90deg);
}

.menu-group-items {
    max-height: 1000px;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.menu-group-items.collapsed {
    max-height: 0;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px 12px 40px;
    color: #cbd5e1;
    text-decoration: none;
    transition: all 0.2s;
    font-size: 14px;
    font-weight: 500;
}

.menu-item:hover {
    background: #334155;
    color: white;
}

.menu-item.active {
    background: linear-gradient(90deg, rgba(102, 126, 234, 0.1) 0%, transparent 100%);
    color: #667eea;
    border-left: 3px solid #667eea;
    padding-left: 37px;
}

.menu-item i {
    font-size: 16px;
    width: 20px;
    text-align: center;
}

.sidebar-footer {
    padding: 16px;
    border-top: 1px solid #334155;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-info {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
    overflow: hidden;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-details {
    min-width: 0;
    flex: 1;
}

.user-name {
    color: white;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-email {
    color: #94a3b8;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.logout-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-decoration: none;
    transition: all 0.2s;
    flex-shrink: 0;
}

.logout-btn:hover {
    background: #ef4444;
    color: white;
}

.main-content {
    margin-left: 280px;
    min-height: 100vh;
    background: #f8fafc;
}

@media (max-width: 1024px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.active {
        transform: translateX(0) !important;
    }
    
    .sidebar-toggle {
        display: block;
    }
    
    .main-content {
        margin-left: 0;
    }
}
</style>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
}

function toggleGroup(header) {
    header.classList.toggle('collapsed');
    const items = header.nextElementSibling;
    items.classList.toggle('collapsed');
}
</script>
