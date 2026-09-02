// TEN Management - Settings JavaScript

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

// Initialize drag and drop for modules
let moduleSortable, groupSortable;

document.addEventListener('DOMContentLoaded', function() {
    const modulesList = document.getElementById('modulesList');
    if (modulesList && typeof Sortable !== 'undefined') {
        moduleSortable = new Sortable(modulesList, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                updateModuleOrder();
            }
        });
    }
    
    const groupsList = document.getElementById('groupsList');
    if (groupsList && typeof Sortable !== 'undefined') {
        groupSortable = new Sortable(groupsList, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            onEnd: function(evt) {
                updateGroupOrder();
            }
        });
    }
});

// Update module display order
function updateModuleOrder() {
    const items = document.querySelectorAll('#modulesList .item-row');
    const order = [];
    
    items.forEach((item, index) => {
        order.push({
            id: item.dataset.id,
            order: index
        });
    });
    
    // You can send this to server to save the order
    console.log('New module order:', order);
}

// Update group display order
function updateGroupOrder() {
    const items = document.querySelectorAll('#groupsList .item-row');
    const order = [];
    
    items.forEach((item, index) => {
        order.push({
            id: item.dataset.id,
            order: index
        });
    });
    
    console.log('New group order:', order);
}

// MODULE FUNCTIONS

// Open create module modal
function openCreateModuleModal() {
    document.getElementById('moduleModalTitle').textContent = 'Create Module';
    document.getElementById('moduleForm').reset();
    document.querySelector('#moduleForm input[name="action"]').value = 'create_module';
    document.getElementById('moduleId').value = '';
    document.getElementById('moduleEnabled').checked = true;
    document.getElementById('moduleModal').classList.add('active');
}

// Close module modal
function closeModuleModal() {
    document.getElementById('moduleModal').classList.remove('active');
}

// Edit module
function editModule(moduleId) {
    fetch('/management/ajax/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_module&module_id=' + moduleId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const module = data.module;
            
            document.getElementById('moduleModalTitle').textContent = 'Edit Module';
            document.querySelector('#moduleForm input[name="action"]').value = 'update_module';
            document.getElementById('moduleId').value = module.id;
            document.getElementById('moduleName').value = module.module_name;
            document.getElementById('moduleKey').value = module.module_key;
            document.getElementById('moduleDescription').value = module.module_description || '';
            document.getElementById('moduleIcon').value = module.module_icon;
            document.getElementById('moduleUrl').value = module.module_url || '';
            document.getElementById('moduleGroup').value = module.module_group;
            document.getElementById('moduleEnabled').checked = module.is_enabled == 1;
            
            document.getElementById('moduleModal').classList.add('active');
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load module data', 'error');
    });
}

// Toggle module enabled/disabled
function toggleModule(moduleId, currentState) {
    fetch('/management/ajax/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=toggle_module&module_id=' + moduleId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to toggle module', 'error');
    });
}

// Delete module
function deleteModule(moduleId) {
    Swal.fire({
        title: 'Delete Module?',
        text: 'This will remove the module from the system!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_module&module_id=' + moduleId
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
                showAlert('Failed to delete module', 'error');
            });
        }
    });
}

// Handle module form submission
document.getElementById('moduleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/management/ajax/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            closeModuleModal();
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

// GROUP FUNCTIONS

// Open create group modal
function openCreateGroupModal() {
    document.getElementById('groupModalTitle').textContent = 'Create Group';
    document.getElementById('groupForm').reset();
    document.querySelector('#groupForm input[name="action"]').value = 'create_group';
    document.getElementById('groupId').value = '';
    document.getElementById('groupModal').classList.add('active');
}

// Close group modal
function closeGroupModal() {
    document.getElementById('groupModal').classList.remove('active');
}

// Edit group
function editGroup(groupId) {
    fetch('/management/ajax/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_group&group_id=' + groupId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const group = data.group;
            
            document.getElementById('groupModalTitle').textContent = 'Edit Group';
            document.querySelector('#groupForm input[name="action"]').value = 'update_group';
            document.getElementById('groupId').value = group.id;
            document.getElementById('groupName').value = group.group_name;
            document.getElementById('groupKey').value = group.group_key;
            document.getElementById('groupDescription').value = group.group_description || '';
            document.getElementById('groupIcon').value = group.group_icon;
            
            document.getElementById('groupModal').classList.add('active');
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load group data', 'error');
    });
}

// Delete group
function deleteGroup(groupId) {
    Swal.fire({
        title: 'Delete Group?',
        text: 'Modules in this group will need to be reassigned!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_group&group_id=' + groupId
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
                showAlert('Failed to delete group', 'error');
            });
        }
    });
}

// Handle group form submission
document.getElementById('groupForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/management/ajax/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            closeGroupModal();
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

// Show alert
function showAlert(message, type) {
    const alertContainer = document.getElementById('alert-container');
    if (!alertContainer) return;
    
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
    const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    
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
document.getElementById('moduleModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModuleModal();
    }
});

document.getElementById('groupModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGroupModal();
    }
});

// Add CSS for sortable ghost
if (!document.getElementById('sortable-styles')) {
    const style = document.createElement('style');
    style.id = 'sortable-styles';
    style.textContent = `
        .sortable-ghost {
            opacity: 0.4;
            background: #f3f4f6;
        }
    `;
    document.head.appendChild(style);
}
