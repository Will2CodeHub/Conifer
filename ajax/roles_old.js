// TEN Management - Roles & Permissions JavaScript

// Tab switching
function switchTab(tabName) {
    // Hide all tab contents
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(content => content.classList.remove('active'));
    
    // Remove active class from all tabs
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Show selected tab content
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Add active class to clicked tab
    event.target.classList.add('active');
}

// Open create role modal
function openCreateRoleModal() {
    document.getElementById('roleModalTitle').textContent = 'Create Role';
    document.getElementById('roleForm').reset();
    document.querySelector('input[name="action"]').value = 'create_role';
    document.getElementById('roleId').value = '';
    document.getElementById('roleModal').classList.add('active');
}

// Close role modal
function closeRoleModal() {
    document.getElementById('roleModal').classList.remove('active');
}

// Edit role
function editRole(roleId) {
    fetch('/management/ajax/roles.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_role&role_id=' + roleId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const role = data.role;
            
            document.getElementById('roleModalTitle').textContent = 'Edit Role';
            document.querySelector('input[name="action"]').value = 'update_role';
            document.getElementById('roleId').value = role.id;
            document.getElementById('roleName').value = role.role_name;
            document.getElementById('roleKey').value = role.role_key;
            document.getElementById('roleDescription').value = role.role_description || '';
            document.getElementById('roleLevel').value = role.role_level;
            
            document.getElementById('roleModal').classList.add('active');
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load role data', 'error');
    });
}

// Delete role
function deleteRole(roleId) {
    Swal.fire({
        title: 'Delete Role?',
        text: 'Users with this role will lose their permissions!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/roles.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_role&role_id=' + roleId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to delete role', 'error');
            });
        }
    });
}

// Manage permissions for a role
function managePermissions(roleId) {
    // First, get current permissions for this role
    fetch('/management/ajax/roles.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_role_permissions&role_id=' + roleId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showPermissionsModal(roleId, data.permissions);
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load permissions', 'error');
    });
}

// Show permissions modal with checkboxes
function showPermissionsModal(roleId, selectedPermissions) {
    document.getElementById('permRoleId').value = roleId;
    
    // Build permissions checkboxes from the page data
    const permissionsContainer = document.getElementById('permissionsContainer');
    permissionsContainer.innerHTML = '';
    
    // Get all permissions from the permissions tab
    const permissionGroups = document.querySelectorAll('#permissions-tab .permission-group');
    
    permissionGroups.forEach(group => {
        const groupTitle = group.querySelector('.permission-group-title').textContent;
        const permissions = group.querySelectorAll('.permission-item');
        
        if (permissions.length > 0) {
            const groupDiv = document.createElement('div');
            groupDiv.className = 'permission-group';
            groupDiv.style.marginBottom = '20px';
            
            const title = document.createElement('div');
            title.className = 'permission-group-title';
            title.textContent = groupTitle;
            title.style.fontSize = '16px';
            title.style.fontWeight = '700';
            title.style.marginBottom = '12px';
            groupDiv.appendChild(title);
            
            const checkboxGrid = document.createElement('div');
            checkboxGrid.style.display = 'grid';
            checkboxGrid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(250px, 1fr))';
            checkboxGrid.style.gap = '8px';
            
            permissions.forEach(permItem => {
                const permName = permItem.querySelector('.permission-name').textContent;
                const permType = permItem.querySelector('.permission-type').textContent;
                
                // Get permission ID from data attribute or create one
                const permId = permItem.dataset.permissionId || Math.random().toString(36).substr(2, 9);
                
                const label = document.createElement('label');
                label.style.display = 'flex';
                label.style.alignItems = 'center';
                label.style.gap = '8px';
                label.style.padding = '8px';
                label.style.background = '#f9fafb';
                label.style.borderRadius = '6px';
                label.style.cursor = 'pointer';
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'permissions[]';
                checkbox.value = permId;
                checkbox.checked = selectedPermissions.includes(permId.toString());
                
                const text = document.createElement('span');
                text.style.fontSize = '13px';
                text.textContent = permName;
                
                const badge = document.createElement('span');
                badge.style.marginLeft = 'auto';
                badge.style.padding = '2px 6px';
                badge.style.background = '#e5e7eb';
                badge.style.borderRadius = '3px';
                badge.style.fontSize = '10px';
                badge.style.fontWeight = '600';
                badge.textContent = permType;
                
                label.appendChild(checkbox);
                label.appendChild(text);
                label.appendChild(badge);
                checkboxGrid.appendChild(label);
            });
            
            groupDiv.appendChild(checkboxGrid);
            permissionsContainer.appendChild(groupDiv);
        }
    });
    
    document.getElementById('permissionsModal').classList.add('active');
}

// Close permissions modal
function closePermissionsModal() {
    document.getElementById('permissionsModal').classList.remove('active');
}

// Handle role form submission
document.getElementById('roleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/management/ajax/roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            closeRoleModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'error');
    });
});

// Handle permissions form submission
document.getElementById('permissionsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/management/ajax/roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            closePermissionsModal();
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred', 'error');
    });
});

// Open create permission modal
function openCreatePermissionModal() {
    showAlert('Permission creation coming soon. Permissions are currently managed through database or code.', 'info');
}

// Open create subrole modal
function openCreateSubroleModal() {
    showAlert('Sub-role creation coming soon. This will allow granular permissions within modules.', 'info');
}

// Show alert
function showAlert(message, type) {
    const alertContainer = document.getElementById('alert-container');
    if (!alertContainer) return;
    
    const alertClass = type === 'success' ? 'alert-success' : type === 'info' ? 'alert-info' : 'alert-error';
    const iconClass = type === 'success' ? 'fa-check-circle' : type === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle';
    
    const alert = document.createElement('div');
    alert.className = `alert ${alertClass}`;
    alert.innerHTML = `
        <i class="fas ${iconClass}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; font-size: 20px; cursor: pointer; color: inherit;">&times;</button>
    `;
    
    alertContainer.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, 5000);
}

// Close modals when clicking outside
document.getElementById('roleModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRoleModal();
    }
});

document.getElementById('permissionsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePermissionsModal();
    }
});

// Add permission IDs to permission items for easier reference
document.addEventListener('DOMContentLoaded', function() {
    const permissionItems = document.querySelectorAll('.permission-item');
    permissionItems.forEach((item, index) => {
        // You should ideally get this from a data attribute set by PHP
        // For now, we'll use a generated ID
        item.dataset.permissionId = index + 1;
    });
});
