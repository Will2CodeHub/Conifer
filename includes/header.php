<?php
$currentUser = getCurrentUser();
$availableLanguages = [
    'en' => ['name' => 'English', 'flag' => '🇬🇧'],
    'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪'],
    'es' => ['name' => 'Español', 'flag' => '🇪🇸'],
    'it' => ['name' => 'Italiano', 'flag' => '🇮🇹'],
    'fr' => ['name' => 'Français', 'flag' => '🇫🇷']
];
$currentLang = getUserLanguage();
?>
<div class="top-header">
    <div class="header-left">
        <button class="mobile-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="breadcrumb">
            <i class="fas fa-home"></i>
            <span><?php echo t('header.home', 'Home'); ?></span>
        </div>
    </div>
    
    <div class="header-right">
        <!-- Language Switcher -->
        <div class="language-switcher">
            <button class="lang-btn" onclick="toggleLanguageMenu()">
                <span class="flag"><?php echo $availableLanguages[$currentLang]['flag']; ?></span>
                <span class="lang-name"><?php echo $availableLanguages[$currentLang]['name']; ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="lang-menu" id="langMenu">
                <?php foreach ($availableLanguages as $code => $lang): ?>
                    <a href="?change_language=<?php echo $code; ?>" class="lang-option <?php echo $code === $currentLang ? 'active' : ''; ?>">
                        <span class="flag"><?php echo $lang['flag']; ?></span>
                        <span><?php echo $lang['name']; ?></span>
                        <?php if ($code === $currentLang): ?>
                            <i class="fas fa-check"></i>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Notifications -->
        <button class="icon-btn" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="badge">3</span>
        </button>
        
        <!-- User Menu -->
        <div class="user-menu">
            <button class="user-btn" onclick="toggleUserMenu()">
                <div class="user-avatar-small">
                    <?php if ($currentUser['profile_image']): ?>
                        <img src="<?php echo htmlspecialchars($currentUser['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <span class="user-name-header"><?php echo htmlspecialchars($currentUser['full_name']); ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="user-dropdown" id="userDropdown">
                <div class="dropdown-header">
                    <div class="user-info-dropdown">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
                        <div class="user-email"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="profile.php" class="dropdown-item">
                    <i class="fas fa-user"></i>
                    <span><?php echo t('header.profile', 'My Profile'); ?></span>
                </a>
                <a href="settings.php" class="dropdown-item">
                    <i class="fas fa-cog"></i>
                    <span><?php echo t('header.settings', 'Settings'); ?></span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item text-danger">
                    <i class="fas fa-sign-out-alt"></i>
                    <span><?php echo t('header.logout', 'Logout'); ?></span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// Handle language change
if (isset($_GET['change_language'])) {
    $newLang = $_GET['change_language'];
    if (array_key_exists($newLang, $availableLanguages)) {
        $_SESSION['ten_language'] = $newLang;
        
        // Update user preference in database
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE ten_users SET language_preference = ? WHERE id = ?");
        $stmt->bind_param("si", $newLang, $_SESSION['ten_user_id']);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Redirect to remove query parameter
        $redirect = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: $redirect");
        exit();
    }
}
?>

<style>
.top-header {
    background: white;
    border-bottom: 1px solid #e5e7eb;
    padding: 16px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.mobile-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 20px;
    color: #64748b;
    cursor: pointer;
    padding: 8px;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #64748b;
    font-size: 14px;
}

.breadcrumb i {
    color: #667eea;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.language-switcher {
    position: relative;
}

.lang-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
}

.lang-btn:hover {
    border-color: #667eea;
}

.lang-btn .flag {
    font-size: 18px;
}

.lang-btn .lang-name {
    display: none;
}

.lang-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    min-width: 180px;
    display: none;
    z-index: 1000;
}

.lang-menu.active {
    display: block;
}

.lang-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.2s;
}

.lang-option:hover {
    background: #f9fafb;
}

.lang-option.active {
    background: #eff6ff;
    color: #1e40af;
}

.lang-option .flag {
    font-size: 20px;
}

.lang-option i {
    margin-left: auto;
    color: #667eea;
}

.icon-btn {
    position: relative;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: white;
    border: 2px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
}

.icon-btn:hover {
    border-color: #667eea;
    color: #667eea;
}

.icon-btn .badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

.user-menu {
    position: relative;
}

.user-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 12px 6px 6px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.user-btn:hover {
    border-color: #667eea;
}

.user-avatar-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    overflow: hidden;
}

.user-avatar-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-name-header {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
}

.user-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    min-width: 220px;
    display: none;
    z-index: 1000;
}

.user-dropdown.active {
    display: block;
}

.dropdown-header {
    padding: 16px;
}

.user-info-dropdown .user-name {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 2px;
}

.user-info-dropdown .user-email {
    font-size: 12px;
    color: #6b7280;
}

.dropdown-divider {
    height: 1px;
    background: #e5e7eb;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.2s;
}

.dropdown-item:hover {
    background: #f9fafb;
}

.dropdown-item.text-danger {
    color: #ef4444;
}

.dropdown-item.text-danger:hover {
    background: #fef2f2;
}

.dropdown-item i {
    width: 20px;
    text-align: center;
}

@media (min-width: 640px) {
    .lang-btn .lang-name {
        display: block;
    }
}

@media (max-width: 1024px) {
    .mobile-toggle {
        display: block;
    }
    
    .user-name-header {
        display: none;
    }
}

@media (max-width: 640px) {
    .top-header {
        padding: 12px 16px;
    }
    
    .breadcrumb {
        display: none;
    }
}
</style>

<script>
function toggleLanguageMenu() {
    const menu = document.getElementById('langMenu');
    menu.classList.toggle('active');
    
    // Close user menu if open
    document.getElementById('userDropdown').classList.remove('active');
}

function toggleUserMenu() {
    const menu = document.getElementById('userDropdown');
    menu.classList.toggle('active');
    
    // Close language menu if open
    document.getElementById('langMenu').classList.remove('active');
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.language-switcher')) {
        document.getElementById('langMenu').classList.remove('active');
    }
    if (!event.target.closest('.user-menu')) {
        document.getElementById('userDropdown').classList.remove('active');
    }
});

// Mobile menu toggle - FIXED VERSION
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-toggle');
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    
    // Open sidebar when clicking mobile toggle (hamburger icon)
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });
    }
    
    // Close sidebar when clicking the X button inside sidebar
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.remove('active');
        });
    }
    
    // Close sidebar when clicking outside on mobile/tablet
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 1024) {
            if (sidebar && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && !e.target.closest('.mobile-toggle')) {
                    sidebar.classList.remove('active');
                }
            }
        }
    });
});
</script>