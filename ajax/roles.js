/**
 * TEN Management - Roles JavaScript Handler
 * Handles role management, permission assignment, and UI interactions
 */

// Tab switching
function switchTab(tabName) {
    // Remove active class from all tabs and content
    document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    // Add active class to selected tab and content
    event.target.classList.add('active');
    document.getElementById(tabName + '-tab').classList.add('active');
}

// Create Role Modal
function openCreateRoleModal() {
    document.getElementById('roleModalTitle').textContent = 'Create Role';
    document.getElementById('roleForm').reset();
    document.getElementById('roleId').value = '';
    document.getElementById('roleForm').querySelector('[name="action"]').value = 'create_role';
    document.getElementById('roleModal').classList.add('active');
}

function closeRoleModal() {
    document.getElementById('roleModal').classList.remove('active');
}

// Edit Role
function editRole(roleId) {
    fetch('/management/ajax/role_handler.php?action=get_role&role_id=' + roleId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('roleModalTitle').textContent = 'Edit Role';
                document.getElementById('roleId').value = data.role.id;
                document.getElementById('roleName').value = data.role.role_name;
                document.getElementById('roleKey').value = data.role.role_key;
                document.getElementById('roleDescription').value = data.role.role_description || '';
                document.getElementById('roleLevel').value = data.role.role_level;
                document.getElementById('roleForm').querySelector('[name="action"]').value = 'update_role';
                document.getElementById('roleModal').classList.add('active');
            } else {
                showAlert('error', data.message || 'Failed to load role');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'Failed to load role');
        });
}

// Delete Role
function deleteRole(roleId) {
    Swal.fire({
        title: 'Delete Role?',
        text: "This will remove all user assignments for this role. This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_role');
            formData.append('role_id', roleId);
            
            fetch('/management/ajax/role_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'Failed to delete role', 'error');
            });
        }
    });
}

// Manage Permissions Modal
function managePermissions(roleId) {
    document.getElementById('permRoleId').value = roleId;
    
    // Load permissions with current role assignments
    fetch('/management/ajax/role_handler.php?action=get_role_permissions&role_id=' + roleId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderPermissionsForm(data.permissions, data.role_permissions);
                document.getElementById('permissionsModal').classList.add('active');
            } else {
                showAlert('error', data.message || 'Failed to load permissions');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'Failed to load permissions');
        });
}

function closePermissionsModal() {
    document.getElementById('permissionsModal').classList.remove('active');
}

// Render permissions form grouped by module
function renderPermissionsForm(allPermissions, rolePermissions) {
    const container = document.getElementById('permissionsContainer');
    const rolePermIds = rolePermissions.map(p => parseInt(p.permission_id));
    
    // Group permissions by module - use the 'module' field directly
    const grouped = {};
    allPermissions.forEach(perm => {
        // Use module field from ten_permissions table, fallback to module_name, then 'System'
        const module = perm.module ? perm.module.charAt(0).toUpperCase() + perm.module.slice(1) : 
                      (perm.module_name || 'System');
        if (!grouped[module]) {
            grouped[module] = [];
        }
        grouped[module].push(perm);
    });
    
    // Sort modules alphabetically
    const sortedModules = Object.keys(grouped).sort();
    
    // Build HTML
    let html = '';
    sortedModules.forEach(module => {
        const perms = grouped[module];
        html += `
            <div class="permission-group">
                <div class="permission-group-title">
                    ${escapeHtml(module)}
                    <button type="button" class="btn-link" onclick="toggleModulePermissions('${module}', true)" 
                            style="font-size: 12px; margin-left: 10px;">Select All</button>
                    <button type="button" class="btn-link" onclick="toggleModulePermissions('${module}', false)" 
                            style="font-size: 12px; margin-left: 5px;">Deselect All</button>
                </div>
                <div class="permission-list">
        `;
        
        perms.forEach(perm => {
            // CRITICAL: Check if this permission ID is in the role's permissions
            const isChecked = rolePermIds.includes(parseInt(perm.id));
            const checked = isChecked ? 'checked' : '';
            
            html += `
                <div class="checkbox-item">
                    <input type="checkbox" 
                           name="permissions[]" 
                           value="${perm.id}" 
                           id="perm_${perm.id}"
                           data-module="${escapeHtml(module)}"
                           ${checked}>
                    <label for="perm_${perm.id}" style="margin: 0; font-weight: 500;">
                        ${escapeHtml(perm.permission_name)}
                        <span style="color: #9ca3af; font-size: 11px; margin-left: 8px;">
                            (${perm.permission_type.toUpperCase()})
                        </span>
                    </label>
                </div>
            `;
        });
        
        html += `
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Toggle all permissions in a module
function toggleModulePermissions(module, checked) {
    document.querySelectorAll(`input[data-module="${module}"]`).forEach(checkbox => {
        checkbox.checked = checked;
    });
}

// Create Permission Modal (placeholder - you may want to implement this fully later)
function openCreatePermissionModal() {
    Swal.fire({
        title: 'Create Permission',
        html: `
            <div style="text-align: left;">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Permission Key *</label>
                    <input type="text" id="perm_key" class="swal2-input" placeholder="e.g., module.action" style="margin: 0; width: 100%;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Permission Name *</label>
                    <input type="text" id="perm_name" class="swal2-input" placeholder="e.g., View Module" style="margin: 0; width: 100%;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Module *</label>
                    <input type="text" id="perm_module" class="swal2-input" placeholder="e.g., users, pkv, projects" style="margin: 0; width: 100%;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Permission Type *</label>
                    <select id="perm_type" class="swal2-input" style="margin: 0; width: 100%;">
                        <option value="view">View</option>
                        <option value="create">Create</option>
                        <option value="edit">Edit</option>
                        <option value="delete">Delete</option>
                        <option value="manage">Manage</option>
                        <option value="execute">Execute</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Description</label>
                    <textarea id="perm_description" class="swal2-textarea" placeholder="Permission description" style="margin: 0; width: 100%;"></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Create Permission',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#667eea',
        preConfirm: () => {
            const key = document.getElementById('perm_key').value;
            const name = document.getElementById('perm_name').value;
            const module = document.getElementById('perm_module').value;
            const type = document.getElementById('perm_type').value;
            const description = document.getElementById('perm_description').value;
            
            if (!key || !name || !module || !type) {
                Swal.showValidationMessage('Please fill in all required fields');
                return false;
            }
            
            return { key, name, module, type, description };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'create_permission');
            formData.append('permission_key', result.value.key);
            formData.append('permission_name', result.value.name);
            formData.append('module', result.value.module);
            formData.append('permission_type', result.value.type);
            formData.append('permission_description', result.value.description);
            
            fetch('/management/ajax/role_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'Failed to create permission', 'error');
            });
        }
    });
}

// Create Sub-Role Modal (placeholder)
function openCreateSubroleModal() {
    Swal.fire({
        title: 'Create Sub-Role',
        text: 'Sub-role functionality will be implemented based on your specific requirements.',
        icon: 'info',
        confirmButtonText: 'OK',
        confirmButtonColor: '#667eea'
    });
}

// Handle role form submission
document.addEventListener('DOMContentLoaded', function() {
    const roleForm = document.getElementById('roleForm');
    if (roleForm) {
        roleForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/management/ajax/role_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'An error occurred', 'error');
            });
        });
    }
    
    const permissionsForm = document.getElementById('permissionsForm');
    if (permissionsForm) {
        permissionsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/management/ajax/role_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'An error occurred', 'error');
            });
        });
    }
});

// Utility function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Alert function
function showAlert(type, message) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
    const alertHtml = `
        <div class="alert ${alertClass}" style="margin-bottom: 20px; padding: 12px 16px; border-radius: 8px;">
            ${message}
        </div>
    `;
    const container = document.getElementById('alert-container');
    if (container) {
        container.innerHTML = alertHtml;
        setTimeout(() => {
            container.innerHTML = '';
        }, 5000);
    }
}
