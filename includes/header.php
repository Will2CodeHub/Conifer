<?php
$currentUser = getCurrentUser();
$availableLanguages = [
    'en' => ['name' => 'English',  'cc' => 'gb'],
    'de' => ['name' => 'Deutsch',  'cc' => 'de'],
    'es' => ['name' => 'Español',  'cc' => 'es'],
    'it' => ['name' => 'Italiano', 'cc' => 'it'],
    'fr' => ['name' => 'Français', 'cc' => 'fr'],
];
$currentLang = getUserLanguage();
if (!isset($availableLanguages[$currentLang])) $currentLang = 'en';

// Header badges, computed at render so they never flicker: post-it summary + bell count.
$hdrNotes = ['deadlines' => 0, 'overdue' => 0, 'page' => 0];
$hdrBellCount = 0;
try {
    require_once __DIR__ . '/../lib/notifications_core.php';
    $hdrConn = getDBConnection();
    notes_ensure_schema($hdrConn);
    $hdrUid = (int) ($_SESSION['ten_user_id'] ?? 0);
    $hdrNotes = notes_header_summary($hdrConn, $hdrUid, notes_current_page_key());
    $hdrBellCount = ten_notifications_count($hdrConn, $hdrUid);
    $hdrConn->close();
} catch (Throwable $e) {
    error_log('header badges: ' . $e->getMessage());
}
?>
<!-- Real flag images (Windows/Chrome don't render flag emoji) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icons/7.2.3/css/flag-icons.min.css">
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
                <span class="fi fi-<?php echo $availableLanguages[$currentLang]['cc']; ?> flag"></span>
                <span class="lang-name"><?php echo $availableLanguages[$currentLang]['name']; ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="lang-menu" id="langMenu">
                <?php foreach ($availableLanguages as $code => $lang): ?>
                    <a href="?change_language=<?php echo $code; ?>" class="lang-option <?php echo $code === $currentLang ? 'active' : ''; ?>">
                        <span class="fi fi-<?php echo $lang['cc']; ?> flag"></span>
                        <span><?php echo $lang['name']; ?></span>
                        <?php if ($code === $currentLang): ?>
                            <i class="fas fa-check"></i>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Post-it notes: badge = my open notes with a deadline; glows when this page has notes -->
        <div class="hdr-pop-wrap" id="hdrNotesWrap">
            <button class="icon-btn hdr-notes-btn<?php echo $hdrNotes['page'] > 0 ? ' has-page-notes' : ''; ?>" id="hdrNotesBtn"
                    title="<?php echo $hdrNotes['page'] > 0 ? $hdrNotes['page'] . ' note(s) on this page' : 'Notes'; ?>">
                <i class="fas fa-note-sticky"></i>
                <span class="badge<?php echo $hdrNotes['overdue'] > 0 ? ' badge-red' : ' badge-amber'; ?>" id="hdrNotesBadge"<?php echo $hdrNotes['deadlines'] > 0 ? '' : ' style="display:none"'; ?>><?php echo (int) $hdrNotes['deadlines']; ?></span>
            </button>
            <div class="hdr-pop" id="hdrNotesPop"></div>
        </div>

        <!-- Notifications: live "needs attention" feed (lib/notifications_core.php) -->
        <div class="hdr-pop-wrap" id="hdrBellWrap">
            <button class="icon-btn" id="hdrBellBtn" title="Notifications">
                <i class="fas fa-bell"></i>
                <span class="badge" id="hdrBellBadge"<?php echo $hdrBellCount > 0 ? '' : ' style="display:none"'; ?>><?php echo $hdrBellCount > 99 ? '99+' : (int) $hdrBellCount; ?></span>
            </button>
            <div class="hdr-pop" id="hdrBellPop"></div>
        </div>
        
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
    font-size: 20px;
}
/* flag-icons render as background images; round the corners and add a hairline */
.language-switcher .fi.flag {
    border-radius: 3px;
    box-shadow: 0 0 0 1px rgba(0,0,0,.08);
    line-height: 1;
}
.lang-option .fi.flag {
    font-size: 20px;
    margin-right: 4px;
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
.icon-btn .badge.badge-amber { background: #d97706; }
.icon-btn .badge.badge-red { background: #dc2626; }

/* Post-it button: yellow glow when the current page has notes */
.hdr-notes-btn.has-page-notes {
    background: #fff59d;
    border-color: #eab308;
    color: #854d0e;
    box-shadow: 0 0 0 3px rgba(234, 179, 8, .25);
    animation: hdrNotesPulse 2.4s ease-in-out 2;
}
@keyframes hdrNotesPulse {
    50% { box-shadow: 0 0 0 7px rgba(234, 179, 8, .12); }
}

/* Header dropdown panels (notes + bell) */
.hdr-pop-wrap { position: relative; }
.hdr-pop {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 10px);
    width: 400px;
    max-width: calc(100vw - 32px);
    max-height: 75vh;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, .18);
    z-index: 1200;
}
.hdr-pop.active { display: block; }
.hdr-pop-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f1f5f9;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
}
.hdr-pop-head h4 { margin: 0; font-size: 15px; flex: 1; color: #111827; }
.hdr-pop-head button {
    background: none; border: none; color: #4f46e5; font-size: 12.5px; font-weight: 600; cursor: pointer;
    padding: 4px 8px; border-radius: 6px;
}
.hdr-pop-head button:hover { background: #eef2ff; }
.hdr-pop-sec { padding: 12px 16px; }
.hdr-pop-sec + .hdr-pop-sec { border-top: 1px solid #f1f5f9; }
.hdr-pop-label {
    font-size: 11px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #9ca3af; margin-bottom: 10px;
}
.hdr-pop-empty { color: #9ca3af; font-size: 13px; padding: 4px 0 6px; }
.hdr-pop .pn-board { grid-template-columns: 1fr 1fr; gap: 16px; padding: 8px 2px 4px; }
.hdr-pop .pn-postit { min-height: 120px; padding: 12px 12px 10px; }
.hdr-pop .pn-postit-title { font-size: 21px; }
.hdr-pop .pn-postit-body { font-size: 12.5px; }
.hdr-new-note {
    width: 100%; margin-top: 12px; padding: 10px; border: 2px dashed #eab308; border-radius: 10px;
    background: #fffbeb; color: #854d0e; font-weight: 700; font-size: 13px; cursor: pointer;
}
.hdr-new-note:hover { background: #fef3c7; }
.hdr-dl-row {
    display: flex; align-items: center; gap: 10px; padding: 8px 6px; border-radius: 8px; cursor: pointer;
}
.hdr-dl-row:hover { background: #f9fafb; }
.hdr-dl-dot { width: 14px; height: 14px; border-radius: 3px; flex: 0 0 auto; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.hdr-dl-main { flex: 1; min-width: 0; }
.hdr-dl-title { font-size: 13.5px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hdr-dl-sub { font-size: 11.5px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* bell rows */
.hdr-nt-row {
    display: flex; gap: 12px; padding: 10px 16px; cursor: pointer; border-left: 3px solid transparent;
    text-decoration: none; color: inherit;
}
.hdr-nt-row:hover { background: #f9fafb; }
.hdr-nt-ic {
    width: 32px; height: 32px; border-radius: 50%; flex: 0 0 auto; display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.hdr-nt-row.t-red .hdr-nt-ic { background: #fee2e2; color: #dc2626; }
.hdr-nt-row.t-red { border-left-color: #dc2626; }
.hdr-nt-row.t-amber .hdr-nt-ic { background: #fef3c7; color: #b45309; }
.hdr-nt-row.t-blue .hdr-nt-ic { background: #dbeafe; color: #1d4ed8; }
.hdr-nt-row.t-yellow .hdr-nt-ic { background: #fff59d; color: #854d0e; }
.hdr-nt-row.t-green .hdr-nt-ic { background: #dcfce7; color: #15803d; }
.hdr-nt-row.t-gray .hdr-nt-ic { background: #f3f4f6; color: #4b5563; }
.hdr-nt-main { flex: 1; min-width: 0; }
.hdr-nt-title { font-size: 13.5px; font-weight: 600; color: #111827; }
.hdr-nt-detail { font-size: 12px; color: #6b7280; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.hdr-nt-when { font-size: 11px; color: #9ca3af; white-space: nowrap; }
.hdr-nt-ck, #hdrBellSelAll { width: 15px; height: 15px; margin: 8px 0 0; flex: 0 0 auto; cursor: pointer; accent-color: #4f46e5; }
#hdrBellSelAll { margin: 0; }
.hdr-nt-side { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.hdr-nt-del {
    background: none; border: none; color: #cbd5e1; cursor: pointer; padding: 2px 4px; border-radius: 5px; font-size: 12.5px;
    opacity: 0; transition: opacity .12s;
}
.hdr-nt-row:hover .hdr-nt-del { opacity: 1; }
.hdr-nt-del:hover { color: #dc2626; background: #fef2f2; }
.hdr-pop-head button.hdr-danger { color: #dc2626; }
.hdr-pop-head button.hdr-danger:hover { background: #fef2f2; }
@media (hover: none) { .hdr-nt-del { opacity: 1; } }

@media (max-width: 640px) {
    .hdr-pop { position: fixed; top: 64px; right: 16px; left: 16px; width: auto; max-width: none; }
    .hdr-pop .pn-board { grid-template-columns: 1fr; }
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
window.TEN_HEADER = { pageKey: <?php echo json_encode(notes_current_page_key()); ?> };
</script>
<script src="/management/js/project_notes.js?v=<?php echo @filemtime(__DIR__ . '/../js/project_notes.js'); ?>" defer></script>
<script src="/management/js/header_notes.js?v=<?php echo @filemtime(__DIR__ . '/../js/header_notes.js'); ?>" defer></script>
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