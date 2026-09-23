// TEN Management - User Management JavaScript

// Search users
function searchUsers() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('usersTable');
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent || row.innerText;
        
        if (text.toLowerCase().indexOf(filter) > -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}

// Open create user modal
function openCreateUserModal() {
    document.getElementById('modalTitle').textContent = 'Create User';
    document.getElementById('formAction').value = 'create_user';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('passwordGroup').style.display = 'block';
    document.getElementById('password').required = true;
    
    // Uncheck all role checkboxes
    const checkboxes = document.querySelectorAll('input[name="roles[]"]');
    checkboxes.forEach(cb => cb.checked = false);
    
    document.getElementById('userModal').classList.add('active');
}

// Close user modal
function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

// Generate random password
function generatePassword() {
    const length = 12;
    const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let password = '';
    
    for (let i = 0; i < length; i++) {
        password += charset.charAt(Math.floor(Math.random() * charset.length));
    }
    
    document.getElementById('password').value = password;
    document.getElementById('password').type = 'text';
    
    // Show password for 3 seconds then hide
    setTimeout(() => {
        document.getElementById('password').type = 'password';
    }, 3000);
    
    // Copy to clipboard
    navigator.clipboard.writeText(password).then(() => {
        showAlert('Password generated and copied to clipboard!', 'success');
    });
}

// Edit user
function editUser(userId) {
    // Fetch user data
    fetch('/management/ajax/user_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_user&user_id=' + userId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const user = data.user;
            
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('formAction').value = 'update_user';
            document.getElementById('userId').value = user.id;
            document.getElementById('fullName').value = user.full_name;
            document.getElementById('email').value = user.email;
            document.getElementById('username').value = user.username;
            document.getElementById('status').value = user.status;
            
            // Hide password field for editing
            document.getElementById('passwordGroup').style.display = 'none';
            document.getElementById('password').required = false;
            
            // First uncheck all
            document.querySelectorAll('input[name="roles[]"]').forEach(cb => cb.checked = false);

            // Then check the roles this user has
            if (data.roles && data.roles.length > 0) {
                data.roles.forEach(roleId => {
                    const checkbox = document.querySelector(`input[name="roles[]"][value="${roleId}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }
            
            document.getElementById('userModal').classList.add('active');
        } else {
            showAlert(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load user data', 'error');
    });
}

// Delete user
function deleteUser(userId) {
    Swal.fire({
        title: 'Delete User?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/user_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_user&user_id=' + userId
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
                showAlert('Failed to delete user', 'error');
            });
        }
    });
}

// Handle form submission
document.getElementById('userForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const action = formData.get('action');
    
    // Convert FormData to URLSearchParams
    const params = new URLSearchParams();
    for (const [key, value] of formData.entries()) {
        if (key === 'roles[]') {
            // Handle multiple role selections
            const roles = formData.getAll('roles[]');
            roles.forEach(role => params.append('roles[]', role));
        } else if (key !== 'roles[]') {
            params.append(key, value);
        }
    }
    
    fetch('/management/ajax/user_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: params.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            closeUserModal();
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

// Close modal when clicking outside
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUserModal();
    }
});

// Add CSS for alerts if not already present
if (!document.getElementById('alert-styles')) {
    const style = document.createElement('style');
    style.id = 'alert-styles';
    style.textContent = `
        #alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
        }
        .alert {
            padding: 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
}
