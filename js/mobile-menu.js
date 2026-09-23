// Mobile menu functionality
document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.toggle('mobile-active');
        }
    };
    
    // Toggle user dropdown
    window.toggleUserMenu = function() {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
        }
    };
    
    // Close sidebar when clicking outside
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.querySelector('.mobile-menu-btn');
        const closeBtn = document.querySelector('.mobile-close');
        
        // If clicking outside sidebar and not on menu button
        if (sidebar && 
            !sidebar.contains(event.target) && 
            event.target !== menuBtn && 
            !menuBtn?.contains(event.target)) {
            sidebar.classList.remove('mobile-active');
        }
        
        // Close user dropdown when clicking outside
        const userMenu = document.querySelector('.user-menu');
        const userDropdown = document.getElementById('userDropdown');
        
        if (userDropdown && 
            !userMenu?.contains(event.target)) {
            userDropdown.classList.remove('show');
        }
    });
    
    // Close sidebar when clicking a nav link (mobile only)
    const navLinks = document.querySelectorAll('.sidebar .nav-item');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.classList.remove('mobile-active');
                }
            }
        });
    });
});