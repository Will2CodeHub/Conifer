/**
 * Project Management JavaScript - COMPLETE FIXED VERSION
 * Version: 2.0 - All errors fixed, View button added
 * Features: Projects, Tasks, Milestones, All Tasks Tab, Kanban Board, Project Detail Modal, Gantt Chart
 */

let ganttChart = null;
let currentGanttView = 'Week';
let statusChart = null;
let priorityChart = null;
let memberActivityChart = null;
let currentProjectId = null;
let currentPage = 1;
const tasksPerPage = 20;

// ==========================================
// PROJECT FUNCTIONS
// ==========================================

function openCreateProjectModal() {
    document.getElementById('projectModalTitle').textContent = 'Create Project';
    document.getElementById('projectForm').reset();
    document.getElementById('projectId').value = '';
    document.getElementById('projectForm').querySelector('[name="action"]').value = 'create_project';
    document.getElementById('projectModal').classList.add('active');
}

function closeProjectModal() {
    document.getElementById('projectModal').classList.remove('active');
}

function editProject(projectId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const project = data.project;
            document.getElementById('projectModalTitle').textContent = 'Edit Project';
            document.getElementById('projectId').value = project.id;
            document.getElementById('projectName').value = project.project_name;
            document.getElementById('projectKey').value = project.project_key;
            document.getElementById('projectDescription').value = project.description || '';
            document.getElementById('projectStatus').value = project.status;
            document.getElementById('projectPriority').value = project.priority;
            document.getElementById('projectStartDate').value = project.start_date || '';
            document.getElementById('projectEndDate').value = project.end_date || '';
            document.getElementById('projectBudget').value = project.budget || '';
            
            document.querySelectorAll('[name="members[]"]').forEach(checkbox => {
                checkbox.checked = project.members && project.members.includes(checkbox.value);
            });
            
            document.getElementById('projectForm').querySelector('[name="action"]').value = 'update_project';
            document.getElementById('projectModal').classList.add('active');
        } else {
            showAlert('error', data.error || 'Failed to load project');
        }
    })
    .catch(error => showAlert('error', 'Error loading project'));
}

function deleteProject(projectId) {
    Swal.fire({
        title: 'Delete Project?',
        text: 'This will delete all tasks, milestones, and related data. This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/project_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_project', project_id: projectId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Project deleted successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.error || 'Failed to delete project');
                }
            })
            .catch(error => showAlert('error', 'Error deleting project'));
        }
    });
}

function openProjectDetailModal(projectId) {
    currentProjectId = projectId;
    window.currentProjectId = projectId; // Also set on window for global access
    
    // Load project data
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const project = data.project;
            
            // Update modal header
            document.getElementById('projectDetailTitle').textContent = project.project_name;
            document.getElementById('projectDetailKey').textContent = project.project_key;
            
            // Open modal and load Overview tab
            document.getElementById('projectDetailModal').classList.add('active');
            switchProjectTab('overview');
        } else {
            showAlert('error', data.error || 'Failed to load project');
        }
    })
    .catch(error => showAlert('error', 'Error loading project'));
}

function closeProjectDetailModal() {
    document.getElementById('projectDetailModal').classList.remove('active');
    currentProjectId = null;
    window.currentProjectId = null;
}

function switchProjectTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.project-detail-tab').forEach(tab => tab.classList.remove('active'));
    const clickedTab = document.querySelector(`[onclick="switchProjectTab('${tabName}')"]`);
    if (clickedTab) clickedTab.classList.add('active');
    
    // Update tab contents
    document.querySelectorAll('.project-tab-content').forEach(content => content.classList.remove('active'));
    const targetContent = document.getElementById(`project-${tabName}-tab`);
    if (targetContent) targetContent.classList.add('active');
    
    // Load tab content
    if (tabName === 'overview') {
        loadProjectOverview(currentProjectId);
    } else if (tabName === 'tasks') {
        loadProjectTasks(currentProjectId);
    } else if (tabName === 'gantt') {
    } else if (tabName === 'kanban') {
        loadProjectKanban(currentProjectId);
        loadProjectGantt(currentProjectId);
        loadProjectActivity(currentProjectId);
        loadProjectActivity(currentProjectId);
    }
}

function loadProjectOverview(projectId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const project = data.project;
            
            // Update stats if elements exist
            const totalTasksEl = document.getElementById('detail-total-tasks');
            const completedTasksEl = document.getElementById('detail-completed-tasks');
            const totalMembersEl = document.getElementById('detail-total-members');
            
            if (totalTasksEl) totalTasksEl.textContent = project.task_count || 0;
            if (completedTasksEl) completedTasksEl.textContent = project.completed_tasks || 0;
            if (totalMembersEl) totalMembersEl.textContent = project.member_count || 0;
            
            // Update info
            const infoContainer = document.getElementById('project-overview-info');
            if (infoContainer) {
                const infoHtml = `
                    <div style="display: grid; gap: 16px;">
                        <div style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px;">
                            <span style="color: #6b7280; font-weight: 500;">Status</span>
                            <span class="status-badge status-${project.status}">${formatStatus(project.status)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px;">
                            <span style="color: #6b7280; font-weight: 500;">Priority</span>
                            <span class="priority-badge priority-${project.priority}">${formatPriority(project.priority)}</span>
                        </div>
                        ${project.start_date ? `
                            <div style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <span style="color: #6b7280; font-weight: 500;">Start Date</span>
                                <span style="color: #111827;">${formatDate(project.start_date)}</span>
                            </div>
                        ` : ''}
                        ${project.end_date ? `
                            <div style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <span style="color: #6b7280; font-weight: 500;">End Date</span>
                                <span style="color: #111827;">${formatDate(project.end_date)}</span>
                            </div>
                        ` : ''}
                        ${project.budget ? `
                            <div style="display: flex; justify-content: space-between; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <span style="color: #6b7280; font-weight: 500;">Budget</span>
                                <span style="color: #111827;">$${parseFloat(project.budget).toLocaleString()}</span>
                            </div>
                        ` : ''}
                        ${project.description ? `
                            <div style="padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <div style="color: #6b7280; font-weight: 500; margin-bottom: 8px;">Description</div>
                                <div style="color: #111827; line-height: 1.6;">${escapeHtml(project.description)}</div>
                            </div>
                        ` : ''}
                    </div>
                `;
                infoContainer.innerHTML = infoHtml;
            }
            
            // Load milestones
            loadProjectMilestones(projectId);
        }
    });
}

function loadProjectMilestones(projectId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project_milestones', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayMilestones(data.milestones, 'project-milestones-list');
        }
    });
}


// Function to load project tasks filtered by status

// Enhanced loadProjectTasksByStatus with detailed logging
function loadProjectTasksByStatus(projectId, status) {
    console.log('=== loadProjectTasksByStatus called ===');
    console.log('Project ID:', projectId);
    console.log('Status:', status);
    
    const container = document.getElementById('project-tasks-list');
    console.log('Container element found:', !!container);
    
    const params = new URLSearchParams({ 
        action: 'get_project_tasks', 
        project_id: projectId
    });
    
    if (status !== 'all') {
        params.append('status', status);
    }
    
    console.log('Fetching with params:', params.toString());
    console.log('Fetching with params:'  , params.toString());
    console.log('IMPORTANT: Status parameter being sent:'  , status !== 'all' ? status : 'ALL STATUSES');
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text(); // Get as text first to see raw response
    })
    .then(text => {
        console.log('RAW RESPONSE:', text);
        const data = JSON.parse(text);
        console.log('Data received:', data);
        if (data.debug) {
            console.log('🔍 BACKEND DEBUG INFO:');
            console.log('  - Project ID received:'  , data.debug.project_id);
            console.log('  - Status filter received:'  , data.debug.status_filter_received);
            console.log('  - Status filter applied:'  , data.debug.status_filter_applied);
            console.log('  - Query has status filter:'  , data.debug.query_has_status_filter);
            console.log('  - Param count:'  , data.debug.param_count);
            console.log('  - Types:'  , data.debug.types);
        }
        console.log('Tasks count:', data.tasks ? data.tasks.length : 0);
        
        if (data.success && data.tasks) {
            // Log task statuses to verify filtering
            const statuses = data.tasks.map(t => t.status);
            console.log('Task statuses in response:', statuses);
            console.log('Unique statuses:', [...new Set(statuses)]);
            
            console.log('Calling displayTasksList with container: project-tasks-list');
            displayTasksList(data.tasks, 'project-tasks-list');
            console.log('displayTasksList completed');
        } else {
            console.error('Failed:', data);
        }
    })
    .catch(error => console.error('Error loading project tasks:', error));
}

function loadProjectTasks(projectId) {
    // Load todo tasks by default when Tasks tab is opened
    loadProjectTasksByStatus(projectId, 'todo');
}



function loadProjectGantt(projectId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project_tasks', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderGanttChart(data.tasks, 'project-gantt-chart');
        }
    });
}

function loadProjectActivity(projectId) {
    const activityType = document.getElementById('activity-type-filter')?.value || 'all';
    const userId = document.getElementById('activity-user-filter')?.value || 'all';
    const dateFrom = document.getElementById('activity-date-from')?.value || '';
    const dateTo = document.getElementById('activity-date-to')?.value || '';
    
    const params = new URLSearchParams({ 
        action: 'get_project_activity', 
        project_id: projectId, 
        limit: 100
    });
    
    if (activityType !== 'all') {
        params.append('activity_type', activityType);
    }
    if (userId !== 'all') {
        params.append('user_id', userId);
    }
    if (dateFrom) {
        params.append('date_from', dateFrom);
    }
    if (dateTo) {
        params.append('date_to', dateTo);
    }
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayActivity(data.activities, 'project-activity-list');
        } else {
            console.error('Failed to load activity:', data);
        }
    })
    .catch(error => console.error('Error loading activity:', error));
}

document.getElementById('projectForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = new URLSearchParams(formData);
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Project saved successfully');
            closeProjectModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert('error', data.error || 'Failed to save project');
        }
    })
    .catch(error => showAlert('error', 'Error saving project'));
});

const projectNameInput = document.getElementById('projectName');
if (projectNameInput) {
    projectNameInput.addEventListener('input', function() {
        if (!document.getElementById('projectId').value) {
            const key = this.value
                .toUpperCase()
                .replace(/[^A-Z\s]/g, '')
                .split(/\s+/)
                .map(word => word.charAt(0))
                .join('')
                .substring(0, 5);
            document.getElementById('projectKey').value = key;
        }
    });
}

// ==========================================
// TASK FUNCTIONS
// ==========================================

function openCreateTaskModal(projectId) {
    document.getElementById('taskModalTitle').textContent = 'Create Task';
    document.getElementById('taskForm').reset();
    document.getElementById('taskId').value = '';
    document.getElementById('taskProjectId').value = projectId;
    document.getElementById('taskForm').querySelector('[name="action"]').value = 'create_task';
    
    loadMilestonesForSelect(projectId);
    
    document.getElementById('taskModal').classList.add('active');
}

function closeTaskModal() {
    document.getElementById('taskModal').classList.remove('active');
}

function editTask(taskId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_task', task_id: taskId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const task = data.task;
            document.getElementById('taskModalTitle').textContent = 'Edit Task';
            document.getElementById('taskId').value = task.id;
            document.getElementById('taskProjectId').value = task.project_id;
            document.getElementById('taskName').value = task.task_name;
            document.getElementById('taskDescription').value = task.description || '';
            document.getElementById('taskStatus').value = task.status;
            document.getElementById('taskPriority').value = task.priority;
            document.getElementById('taskAssignedTo').value = task.assigned_to || '';
            document.getElementById('taskStartDate').value = task.start_date || '';
            document.getElementById('taskDueDate').value = task.due_date || '';
            document.getElementById('taskEstimatedHours').value = task.estimated_hours || '';
            document.getElementById('taskActualHours').value = task.actual_hours || '';
            document.getElementById('taskProgress').value = task.progress || 0;
            
            loadMilestonesForSelect(task.project_id, task.milestone_id);
            
            document.getElementById('taskForm').querySelector('[name="action"]').value = 'update_task';
            document.getElementById('taskModal').classList.add('active');
        } else {
            showAlert('error', data.error || 'Failed to load task');
        }
    })
    .catch(error => showAlert('error', 'Error loading task'));
}

function deleteTask(taskId) {
    Swal.fire({
        title: 'Delete Task?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/project_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_task', task_id: taskId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Task deleted successfully');
                    loadAllTasks();
                    loadMyTasks();
                    if (currentProjectId) {
                        loadProjectTasks(currentProjectId);
                    }
                } else {
                    showAlert('error', data.error || 'Failed to delete task');
                }
            })
            .catch(error => showAlert('error', 'Error deleting task'));
        }
    });
}

function loadMilestonesForSelect(projectId, selectedId = null) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_project_milestones', project_id: projectId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('taskMilestone');
            if (select) {
                select.innerHTML = '<option value="">No Milestone</option>';
                
                data.milestones.forEach(milestone => {
                    const option = document.createElement('option');
                    option.value = milestone.id;
                    option.textContent = milestone.milestone_name;
                    if (selectedId && milestone.id == selectedId) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            }
        }
    });
}

function loadMyTasks() {
    console.log('=== loadMyTasks called ===');
    
    // Show loading indicator
    const container = document.getElementById('tasks-list');
    if (container) {
        container.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #667eea;"></i><p style="margin-top: 12px; color: #6b7280;">Loading tasks...</p></div>';
    }
    
    const statusFilter = document.getElementById('task-filter-status')?.value || 'all';
    const projectFilter = document.getElementById('task-filter-project')?.value || 'all';
    const priorityFilter = document.getElementById('task-filter-priority')?.value || 'all';
    const dateType = document.getElementById('task-filter-date-type')?.value || '';
    const dateFrom = document.getElementById('task-filter-date-from')?.value || '';
    const dateTo = document.getElementById('task-filter-date-to')?.value || '';
    
    console.log('Filters:', { statusFilter, projectFilter, priorityFilter, dateType, dateFrom, dateTo });
    
    const params = new URLSearchParams({ 
        action: 'get_my_tasks',
        status: statusFilter
    });
    
    if (projectFilter !== 'all') {
        params.append('project_id', projectFilter);
    }
    
    if (priorityFilter !== 'all') {
        params.append('priority', priorityFilter);
    }
    
    if (dateType) {
        params.append('date_type', dateType);
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);
    }
    
    console.log('Sending params:', params.toString());
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        console.log('Data received:', data);
        console.log('Task count:', data.tasks ? data.tasks.length : 0);
        if (data.success) {
            console.log('Calling displayTasksList...');
            displayTasksList(data.tasks, 'tasks-list');
            console.log('Tasks displayed successfully');
        } else {
            console.error('Failed to load tasks:', data);
            if (container) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i><p style="margin-top: 12px;">Failed to load tasks: ' + (data.error || 'Unknown error') + '</p></div>';
            }
        }
    })
    .catch(error => {
        console.error('Error loading tasks:', error);
        if (container) {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i><p style="margin-top: 12px;">Error loading tasks. Check console for details.</p></div>';
        }
    });
}

function clearMyTasksFilters() {
    document.getElementById('task-filter-status').value = 'all';
    document.getElementById('task-filter-project').value = 'all';
    document.getElementById('task-filter-priority').value = 'all';
    document.getElementById('task-filter-date-type').value = '';
    document.getElementById('task-filter-date-from').value = '';
    document.getElementById('task-filter-date-to').value = '';
    loadMyTasks();
}

function loadAllTasks(page = 1) {
    currentPage = page;
    const statusFilter = document.getElementById('all-tasks-status-filter')?.value || 'all';
    const priorityFilter = document.getElementById('all-tasks-priority-filter')?.value || 'all';
    const searchQuery = document.getElementById('all-tasks-search')?.value || '';
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 
            action: 'get_all_tasks',
            status: statusFilter,
            priority: priorityFilter,
            search: searchQuery,
            page: page,
            per_page: tasksPerPage
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayAllTasksTable(data.tasks, data.total);
            updatePagination(data.total, page);
        }
    })
    .catch(error => console.error('Error loading all tasks:', error));
}

function displayAllTasksTable(tasks, total) {
    const tbody = document.getElementById('all-tasks-tbody');
    
    if (!tbody) return;
    
    if (!tasks || tasks.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-tasks" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                    <p style="color: #9ca3af; font-size: 16px;">No tasks found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const html = tasks.map(task => {
        const isDueToday = task.due_date && new Date(task.due_date).toDateString() === new Date().toDateString();
        const isOverdue = task.due_date && new Date(task.due_date) < new Date() && task.status !== 'completed';
        
        return `
            <tr>
                <td>
                    <div style="font-weight: 600; color: #111827; margin-bottom: 4px;">${escapeHtml(task.task_name)}</div>
                    <div style="font-size: 12px; color: #6b7280;">
                        <span class="badge badge-key">${escapeHtml(task.project_key)}</span>
                        ${escapeHtml(task.project_name)}
                    </div>
                </td>
                <td><span class="status-badge status-${task.status}">${formatStatus(task.status)}</span></td>
                <td><span class="priority-badge priority-${task.priority}">${formatPriority(task.priority)}</span></td>
                <td style="color: #6b7280;">${task.assigned_to_name ? escapeHtml(task.assigned_to_name) : '<span style="color: #9ca3af;">Unassigned</span>'}</td>
                <td style="color: ${isDueToday ? '#f59e0b' : isOverdue ? '#ef4444' : '#6b7280'}; font-weight: ${isDueToday || isOverdue ? '600' : '400'};">
                    ${task.due_date ? formatDate(task.due_date) : '-'}
                    ${isDueToday ? ' <i class="fas fa-clock" style="color: #f59e0b;"></i>' : ''}
                    ${isOverdue ? ' <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>' : ''}
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                            <div style="height: 100%; background: ${task.progress >= 100 ? '#10b981' : '#667eea'}; width: ${task.progress || 0}%;"></div>
                        </div>
                        <span style="font-size: 12px; color: #6b7280; font-weight: 600;">${task.progress || 0}%</span>
                    </div>
                </td>
                <td style="font-size: 12px; color: #9ca3af;">${formatDateTime(task.created_at)}</td>
                <td>
                    <div style="display: flex; gap: 4px;">
                        <button onclick="editTask(${task.id})" class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteTask(${task.id})" class="btn-danger" style="padding: 6px 12px; font-size: 12px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = html;
}

function updatePagination(total, currentPage) {
    const totalPages = Math.ceil(total / tasksPerPage);
    const pagination = document.getElementById('all-tasks-pagination');
    
    if (!pagination) return;
    
    if (totalPages <= 1) {
        pagination.innerHTML = '';
        return;
    }
    
    let html = '<div style="display: flex; gap: 8px; align-items: center;">';
    
    html += `<button onclick="loadAllTasks(${currentPage - 1})" class="btn-secondary" style="padding: 8px 12px;" ${currentPage === 1 ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i>
    </button>`;
    
    const maxVisible = 7;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }
    
    if (startPage > 1) {
        html += `<button onclick="loadAllTasks(1)" class="btn-secondary" style="padding: 8px 12px;">1</button>`;
        if (startPage > 2) {
            html += `<span style="color: #9ca3af; padding: 0 4px;">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button onclick="loadAllTasks(${i})" class="${i === currentPage ? 'btn-primary' : 'btn-secondary'}" style="padding: 8px 12px;">${i}</button>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<span style="color: #9ca3af; padding: 0 4px;">...</span>`;
        }
        html += `<button onclick="loadAllTasks(${totalPages})" class="btn-secondary" style="padding: 8px 12px;">${totalPages}</button>`;
    }
    
    html += `<button onclick="loadAllTasks(${currentPage + 1})" class="btn-secondary" style="padding: 8px 12px;" ${currentPage === totalPages ? 'disabled' : ''}>
        <i class="fas fa-chevron-right"></i>
    </button>`;
    
    html += `<span style="color: #6b7280; margin-left: 16px;">Page ${currentPage} of ${totalPages}</span>`;
    html += '</div>';
    
    pagination.innerHTML = html;
}

function displayTasksList(tasks, containerId = 'tasks-list') {
    const container = document.getElementById(containerId);
    console.log('=== displayTasksList called ===');
    console.log('Container ID:'  , containerId);
    console.log('Number of tasks:'  , tasks ? tasks.length : 0);
    
    if (!container) {
        console.error('Container not found:', containerId);
        return;
    }
    
    if (!tasks || tasks.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-tasks" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No tasks found</p>
            </div>
        `;
        return;
    }
    
    const html = tasks.map(task => {
        const isDueToday = task.due_date && new Date(task.due_date).toDateString() === new Date().toDateString();
        const isOverdue = task.due_date && new Date(task.due_date) < new Date() && task.status !== 'completed';
        
        return `
            <div class="task-card">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 16px; color: #111827; margin-bottom: 8px;">
                            ${escapeHtml(task.task_name)}
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <span class="badge badge-key">${escapeHtml(task.project_key)}</span>
                            <span style="color: #6b7280; font-size: 13px;">${escapeHtml(task.project_name)}</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <button onclick="editTask(${task.id})" class="btn-secondary" style="padding: 6px 12px;">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteTask(${task.id})" class="btn-danger" style="padding: 6px 12px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                ${task.description ? `<div style="color: #6b7280; font-size: 14px; line-height: 1.6; margin-bottom: 12px;">${escapeHtml(task.description)}</div>` : ''}
                
                <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;">
                    <span class="status-badge status-${task.status}">${formatStatus(task.status)}</span>
                    <span class="priority-badge priority-${task.priority}">${formatPriority(task.priority)}</span>
                    ${task.milestone_name ? `<span class="badge" style="background: #f3f4f6; color: #667eea;"><i class="fas fa-flag"></i> ${escapeHtml(task.milestone_name)}</span>` : ''}
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 12px;">
                    ${task.created_by_name ? `
                        <div>
                            <div style="font-size: 12px; color: #9ca3af; margin-bottom: 4px;">Created By</div>
                            <div style="font-size: 14px; color: #111827; font-weight: 500;">${escapeHtml(task.created_by_name)}</div>
                        </div>
                    ` : ''}
                    ${task.assigned_to_name ? `
                        <div>
                            <div style="font-size: 12px; color: #9ca3af; margin-bottom: 4px;">Assigned To</div>
                            <div style="font-size: 14px; color: #111827; font-weight: 500;">${escapeHtml(task.assigned_to_name)}</div>
                        </div>
                    ` : ''}
                    ${task.start_date ? `
                        <div>
                            <div style="font-size: 12px; color: #9ca3af; margin-bottom: 4px;">Start Date</div>
                            <div style="font-size: 14px; color: #111827; font-weight: 500;">${formatDate(task.start_date)}</div>
                        </div>
                    ` : ''}
                    ${task.due_date ? `
                        <div>
                            <div style="font-size: 12px; color: #9ca3af; margin-bottom: 4px;">Due Date</div>
                            <div style="font-size: 14px; color: ${isDueToday ? '#f59e0b' : isOverdue ? '#ef4444' : '#111827'}; font-weight: ${isDueToday || isOverdue ? '600' : '500'};">
                                ${formatDate(task.due_date)}
                                ${isDueToday ? ' <i class="fas fa-clock" style="color: #f59e0b;"></i>' : ''}
                                ${isOverdue ? ' <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>' : ''}
                            </div>
                        </div>
                    ` : ''}
                </div>
                
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 12px; color: #9ca3af;">Progress</span>
                        <span style="font-size: 12px; color: #6b7280; font-weight: 600;">${task.progress || 0}%</span>
                    </div>
                    <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; background: ${task.progress >= 100 ? '#10b981' : '#667eea'}; width: ${task.progress || 0}%; transition: width 0.3s;"></div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
    console.log('Tasks HTML updated in container:'  , containerId);
    console.log('Container element:'  , container);
    console.log('Container has content:'  , container.innerHTML.length > 0);
}

const taskForm = document.getElementById('taskForm');
if (taskForm) {
    taskForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = new URLSearchParams(formData);
        
        fetch('/management/ajax/project_management.php', {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', 'Task saved successfully');
                closeTaskModal();
                loadMyTasks();
                loadAllTasks();
                if (currentProjectId) {
                    loadProjectTasks(currentProjectId);
                }
            } else {
                showAlert('error', data.error || 'Failed to save task');
            }
        })
        .catch(error => showAlert('error', 'Error saving task'));
    });
}

// ==========================================
// KANBAN BOARD FUNCTIONS (FIXED)
// ==========================================

function loadKanbanBoard() {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_all_tasks', status: 'all' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayKanbanBoard(data.tasks);
        }
    })
    .catch(error => console.error('Error loading kanban:', error));
}

function displayKanbanBoard(tasks, containerId = 'kanban-board') {
    const columns = {
        'todo': { title: 'To Do', tasks: [], color: '#6b7280' },
        'in-progress': { title: 'In Progress', tasks: [], color: '#3b82f6' },
        'review': { title: 'Review', tasks: [], color: '#f59e0b' },
        'completed': { title: 'Completed', tasks: [], color: '#10b981' },
        'blocked': { title: 'Blocked', tasks: [], color: '#ef4444' }
    };
    
    tasks.forEach(task => {
        if (columns[task.status]) {
            columns[task.status].tasks.push(task);
        }
    });
    
    const kanbanBoard = document.getElementById(containerId);
    if (!kanbanBoard) { console.error('Kanban board container not found:', containerId); return; }
    
    let html = '';
    for (const [status, column] of Object.entries(columns)) {
        html += `
            <div class="kanban-column">
                <div class="kanban-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 12px; height: 12px; border-radius: 3px; background: ${column.color};"></div>
                        <span>${column.title}</span>
                    </div>
                    <span class="badge" style="background: ${column.color}; color: white;">${column.tasks.length}</span>
                </div>
                <div class="kanban-cards" data-status="${status}" ondrop="dropTask(event)" ondragover="allowDrop(event)">
                    ${column.tasks.map(task => createKanbanCard(task)).join('')}
                </div>
            </div>
        `;
    }
    
    kanbanBoard.innerHTML = html;
}

function createKanbanCard(task) {
    const isOverdue = task.due_date && new Date(task.due_date) < new Date() && task.status !== 'completed';
    
    return `
        <div class="kanban-card" draggable="true" ondragstart="dragTask(event, ${task.id})" data-task-id="${task.id}">
            <div style="margin-bottom: 8px;">
                <div style="font-weight: 600; color: #111827; margin-bottom: 4px;">${escapeHtml(task.task_name)}</div>
                <div style="font-size: 12px; color: #6b7280;">
                    <span class="badge badge-key">${escapeHtml(task.project_key)}</span>
                    ${escapeHtml(task.project_name)}
                </div>
            </div>
            
            ${task.description ? `<div style="font-size: 13px; color: #6b7280; line-height: 1.5; margin-bottom: 8px;">${escapeHtml(task.description.substring(0, 80))}${task.description.length > 80 ? '...' : ''}</div>` : ''}
            
            <div style="display: flex; gap: 4px; margin-bottom: 8px; flex-wrap: wrap;">
                <span class="priority-badge priority-${task.priority}">${formatPriority(task.priority)}</span>
                ${task.milestone_name ? `<span class="badge" style="background: #f3f4f6; color: #667eea; font-size: 11px;"><i class="fas fa-flag"></i> ${escapeHtml(task.milestone_name)}</span>` : ''}
            </div>
            
            ${task.assigned_to_name || task.due_date ? `
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #6b7280; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                    ${task.assigned_to_name ? `<span><i class="fas fa-user"></i> ${escapeHtml(task.assigned_to_name)}</span>` : '<span></span>'}
                    ${task.due_date ? `<span style="color: ${isOverdue ? '#ef4444' : '#6b7280'}; font-weight: ${isOverdue ? '600' : '400'};"><i class="fas fa-calendar"></i> ${formatDate(task.due_date)}</span>` : ''}
                </div>
            ` : ''}
        </div>
    `;
}

function dragTask(event, taskId) {
    event.dataTransfer.setData('taskId', taskId);
}

function allowDrop(event) {
    event.preventDefault();
}

function dropTask(event) {
    event.preventDefault();
    const taskId = event.dataTransfer.getData('taskId');
    const newStatus = event.currentTarget.dataset.status;
    
    updateTaskStatus(taskId, newStatus);
}

function updateTaskStatus(taskId, newStatus) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 
            action: 'update_task_status', 
            task_id: taskId, 
            status: newStatus 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Task status updated');
            loadKanbanBoard();
        } else {
            showAlert('error', data.error || 'Failed to update task status');
        }
    })
    .catch(error => showAlert('error', 'Error updating task status'));
}

// ==========================================
// MILESTONE FUNCTIONS
// ==========================================

function openCreateMilestoneModal(projectId) {
    document.getElementById('milestoneModalTitle').textContent = 'Create Milestone';
    document.getElementById('milestoneForm').reset();
    document.getElementById('milestoneId').value = '';
    document.getElementById('milestoneProjectId').value = projectId;
    document.getElementById('milestoneForm').querySelector('[name="action"]').value = 'create_milestone';
    document.getElementById('milestoneModal').classList.add('active');
}

function closeMilestoneModal() {
    document.getElementById('milestoneModal').classList.remove('active');
}

function editMilestone(milestoneId) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_milestone', milestone_id: milestoneId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const milestone = data.milestone;
            document.getElementById('milestoneModalTitle').textContent = 'Edit Milestone';
            document.getElementById('milestoneId').value = milestone.id;
            document.getElementById('milestoneProjectId').value = milestone.project_id;
            document.getElementById('milestoneName').value = milestone.milestone_name;
            document.getElementById('milestoneDescription').value = milestone.description || '';
            document.getElementById('milestoneType').value = milestone.milestone_type;
            document.getElementById('milestoneDueDate').value = milestone.due_date;
            document.getElementById('milestoneStatus').value = milestone.status;
            
            document.getElementById('milestoneForm').querySelector('[name="action"]').value = 'update_milestone';
            document.getElementById('milestoneModal').classList.add('active');
        } else {
            showAlert('error', data.error || 'Failed to load milestone');
        }
    })
    .catch(error => showAlert('error', 'Error loading milestone'));
}

function deleteMilestone(milestoneId) {
    Swal.fire({
        title: 'Delete Milestone?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/management/ajax/project_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_milestone', milestone_id: milestoneId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Milestone deleted successfully');
                    if (currentProjectId) {
                        loadProjectMilestones(currentProjectId);
                    }
                } else {
                    showAlert('error', data.error || 'Failed to delete milestone');
                }
            })
            .catch(error => showAlert('error', 'Error deleting milestone'));
        }
    });
}

function displayMilestones(milestones, containerId) {
    const container = document.getElementById(containerId);
    
    if (!container) {
        console.error('Container not found:', containerId);
        return;
    }
    
    if (!milestones || milestones.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas fa-flag" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No milestones yet</p>
            </div>
        `;
        return;
    }
    
    const html = milestones.map(milestone => {
        const isOverdue = new Date(milestone.due_date) < new Date() && milestone.status !== 'completed';
        
        return `
            <div style="padding: 16px; border: 2px solid ${milestone.status === 'completed' ? '#10b981' : '#e5e7eb'}; border-radius: 12px; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 16px; color: #111827; margin-bottom: 8px;">
                            <i class="fas fa-flag" style="color: #667eea;"></i>
                            ${escapeHtml(milestone.milestone_name)}
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <span class="badge" style="background: ${
                                milestone.status === 'completed' ? '#10b981' : 
                                milestone.status === 'in-progress' ? '#3b82f6' : 
                                milestone.status === 'missed' ? '#ef4444' : '#6b7280'
                            }; color: white;">${formatStatus(milestone.status)}</span>
                            <span class="badge" style="background: #f3f4f6; color: #667eea;">${milestone.milestone_type}</span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <button onclick="editMilestone(${milestone.id})" class="btn-secondary" style="padding: 6px 12px;">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteMilestone(${milestone.id})" class="btn-danger" style="padding: 6px 12px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                ${milestone.description ? `<div style="color: #6b7280; font-size: 14px; line-height: 1.6; margin-bottom: 12px;">${escapeHtml(milestone.description)}</div>` : ''}
                
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 13px;">
                        <span style="color: #9ca3af;">Due: </span>
                        <span style="color: ${isOverdue ? '#ef4444' : '#111827'}; font-weight: 600;">
                            ${formatDate(milestone.due_date)}
                            ${isOverdue ? ' <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>' : ''}
                        </span>
                    </div>
                    ${milestone.task_count ? `
                        <div style="font-size: 13px; color: #6b7280;">
                            <i class="fas fa-tasks"></i> ${milestone.completed_tasks || 0}/${milestone.task_count} tasks
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

const milestoneForm = document.getElementById('milestoneForm');
if (milestoneForm) {
    milestoneForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = new URLSearchParams(formData);
        
        fetch('/management/ajax/project_management.php', {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', 'Milestone saved successfully');
                closeMilestoneModal();
                if (currentProjectId) {
                    loadProjectMilestones(currentProjectId);
                }
            } else {
                showAlert('error', data.error || 'Failed to save milestone');
            }
        })
        .catch(error => showAlert('error', 'Error saving milestone'));
    });
}

// ==========================================
// GANTT CHART FUNCTIONS (FIXED)
// ==========================================

function loadGanttChart() {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_all_tasks', status: 'all' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderGanttChart(data.tasks, 'gantt-container');
        }
    })
    .catch(error => console.error('Error loading gantt:', error));
}

function renderGanttChart(tasks, containerId = 'gantt-container') {
    const container = document.getElementById(containerId);
    
    if (!container) {
        console.error('Gantt container not found:', containerId);
        return;
    }
    
    if (!tasks || tasks.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-chart-gantt" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No tasks with dates to display</p>
            </div>
        `;
        return;
    }
    
    const ganttTasks = tasks.filter(task => task.start_date && task.due_date).map(task => {
        return {
            id: task.id.toString(),
            name: task.task_name,
            start: task.start_date,
            end: task.due_date,
            progress: parseInt(task.progress) || 0,
            dependencies: '',
            custom_class: `priority-${task.priority} status-${task.status}`
        };
    });
    
    if (ganttTasks.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-chart-gantt" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No tasks with start and due dates to display</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = '';
    
    try {
        ganttChart = new Gantt(container, ganttTasks, {
            view_mode: currentGanttView,
            bar_height: 30,
            bar_corner_radius: 3,
            arrow_curve: 5,
            padding: 18,
            date_format: 'YYYY-MM-DD',
            language: 'en',
            custom_popup_html: function(task) {
                const taskData = tasks.find(t => t.id.toString() === task.id);
                return `
                    <div style="padding: 12px; min-width: 250px;">
                        <h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600;">${task.name}</h4>
                        ${taskData && taskData.description ? `<p style="font-size: 12px; color: #6b7280; margin: 0 0 8px 0;">${taskData.description}</p>` : ''}
                        <div style="font-size: 12px;">
                            ${taskData ? `
                                <div style="margin-bottom: 4px;"><strong>Status:</strong> ${formatStatus(taskData.status)}</div>
                                <div style="margin-bottom: 4px;"><strong>Priority:</strong> ${formatPriority(taskData.priority)}</div>
                            ` : ''}
                            <div style="margin-bottom: 4px;"><strong>Progress:</strong> ${task.progress}%</div>
                            <div style="margin-bottom: 4px;"><strong>Start:</strong> ${formatDate(task.start)}</div>
                            <div><strong>End:</strong> ${formatDate(task.end)}</div>
                        </div>
                    </div>
                `;
            },
            on_click: function(task) {
                const taskData = tasks.find(t => t.id.toString() === task.id);
                if (taskData) {
                    editTask(taskData.id);
                }
            },
            on_date_change: function(task, start, end) {
                updateTaskDates(task.id, start, end);
            },
            on_progress_change: function(task, progress) {
                updateTaskProgress(task.id, progress);
            }
        });
    } catch (error) {
        console.error('Error rendering Gantt chart:', error);
        container.innerHTML = `
            <div style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #ef4444; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">Error rendering Gantt chart</p>
            </div>
        `;
    }
}

function changeGanttView(view) {
    currentGanttView = view;
    if (ganttChart) {
        ganttChart.change_view_mode(view);
    }
    
    document.querySelectorAll('.gantt-view-btn').forEach(btn => btn.classList.remove('active'));
    if (event && event.target) {
        event.target.classList.add('active');
    }
}

function updateTaskDates(taskId, startDate, endDate) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 
            action: 'update_task_dates', 
            task_id: taskId,
            start_date: startDate.toISOString().split('T')[0],
            due_date: endDate.toISOString().split('T')[0]
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Task dates updated');
        } else {
            showAlert('error', data.error || 'Failed to update dates');
        }
    });
}

function updateTaskProgress(taskId, progress) {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 
            action: 'update_task_progress', 
            task_id: taskId,
            progress: progress
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Task progress updated');
        } else {
            showAlert('error', data.error || 'Failed to update progress');
        }
    });
}

// ==========================================
// ACTIVITY FUNCTIONS
// ==========================================

function displayActivity(activities, containerId) {
    const container = document.getElementById(containerId);
    
    if (!container) {
        console.error('Container not found:', containerId);
        return;
    }
    
    if (!activities || activities.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas fa-clock" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No activity yet</p>
            </div>
        `;
        return;
    }
    
    const html = activities.map(activity => `
        <div style="padding: 12px; border-bottom: 1px solid #e5e7eb; display: flex; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fas fa-circle" style="font-size: 8px; color: #667eea;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 14px; color: #111827;">
                    <strong>${escapeHtml(activity.user_name || 'System')}</strong>
                    ${escapeHtml(activity.description)}
                </div>
                <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                    ${formatDateTime(activity.created_at)}
                </div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
}

// ==========================================
// OVERVIEW & PROJECT LISTING
// ==========================================

function loadOverview() {
    console.log('Loading overview...');
    
    // Update project stats
    let totalProjects = projectsData.length;
    let totalTasks = projectsData.reduce((sum, p) => sum + parseInt(p.task_count || 0), 0);
    let completedTasks = projectsData.reduce((sum, p) => sum + parseInt(p.completed_tasks || 0), 0);
    
    const totalProjectsEl = document.getElementById('total-projects');
    const totalTasksEl = document.getElementById('total-tasks');
    const completedTasksEl = document.getElementById('completed-tasks');
    
    if (totalProjectsEl) totalProjectsEl.textContent = totalProjects;
    if (totalTasksEl) totalTasksEl.textContent = totalTasks;
    if (completedTasksEl) completedTasksEl.textContent = completedTasks;
    
    // Fetch additional stats
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_overview_stats' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update overdue count
            const overdueTasksEl = document.getElementById('overdue-tasks');
            if (overdueTasksEl && data.overdue_count !== undefined) {
                overdueTasksEl.textContent = data.overdue_count;
            }
            
            // Initialize charts with fetched data
            initializeOverviewCharts(data);
        }
    })
    .catch(error => {
        console.error('Error loading overview stats:', error);
        // Initialize charts with empty data if fetch fails
        initializeOverviewCharts({});
    });
}

function initializeOverviewCharts(data) {
    // Status Chart with muted colors
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        if (statusChart) statusChart.destroy();
        
        const statusData = data.status_stats || {
            'todo': 0,
            'in-progress': 0,
            'review': 0,
            'completed': 0,
            'blocked': 0
        };
        
        statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['To Do', 'In Progress', 'Review', 'Completed', 'Blocked'],
                datasets: [{
                    data: [
                        statusData['todo'] || 0,
                        statusData['in-progress'] || 0,
                        statusData['review'] || 0,
                        statusData['completed'] || 0,
                        statusData['blocked'] || 0
                    ],
                    backgroundColor: [
                        '#9ca3af',
                        '#60a5fa',
                        '#fbbf24',
                        '#34d399',
                        '#f87171'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12, family: 'Inter' }
                        }
                    }
                }
            }
        });
    }
    
    // Priority Chart with muted colors
    const priorityCtx = document.getElementById('priorityChart');
    if (priorityCtx) {
        if (priorityChart) priorityChart.destroy();
        
        const priorityData = data.priority_stats || {
            'low': 0,
            'medium': 0,
            'high': 0,
            'critical': 0
        };
        
        priorityChart = new Chart(priorityCtx, {
            type: 'doughnut',
            data: {
                labels: ['Low', 'Medium', 'High', 'Critical'],
                datasets: [{
                    data: [
                        priorityData['low'] || 0,
                        priorityData['medium'] || 0,
                        priorityData['high'] || 0,
                        priorityData['critical'] || 0
                    ],
                    backgroundColor: [
                        '#9ca3af',
                        '#60a5fa',
                        '#fb923c',
                        '#f87171'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12, family: 'Inter' }
                        }
                    }
                }
            }
        });
    }
    
    // Member Activity Chart with muted colors
    const memberCtx = document.getElementById('memberActivityChart');
    if (memberCtx) {
        if (memberActivityChart) memberActivityChart.destroy();
        
        const memberData = data.member_stats || [];
        const memberNames = memberData.map(m => m.name || 'Unknown');
        const memberCounts = memberData.map(m => m.count || 0);
        
        memberActivityChart = new Chart(memberCtx, {
            type: 'bar',
            data: {
                labels: memberNames.length > 0 ? memberNames : ['No Data'],
                datasets: [{
                    label: 'Tasks Assigned',
                    data: memberCounts.length > 0 ? memberCounts : [0],
                    backgroundColor: '#4b5c87',
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: { size: 11, family: 'Inter' }
                        },
                        grid: {
                            color: '#f3f4f6'
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11, family: 'Inter' }
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
}

function loadProjects() {
    displayProjects('projects-list', projectsData);
}

function displayProjects(containerId, projects) {
    const container = document.getElementById(containerId);
    
    if (!container) return;
    
    if (!projects || projects.length === 0) {
        container.innerHTML = `
            <div style="grid-column: 1/-1; text-align: center; padding: 60px 20px;">
                <i class="fas fa-folder-open" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px;"></i>
                <p style="color: #9ca3af; font-size: 16px;">No projects found</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    projects.forEach(project => {
        const progress = project.task_count > 0 ? Math.round((project.completed_tasks / project.task_count) * 100) : 0;
        
        html += `
            <div class="project-card">
                <div class="project-card-header">
                    <div style="flex: 1;">
                        <div class="project-title">${escapeHtml(project.project_name)}</div>
                        <div style="display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                            <span class="badge badge-key">${escapeHtml(project.project_key)}</span>
                            <span class="status-badge status-${project.status}">${formatStatus(project.status)}</span>
                            <span class="priority-badge priority-${project.priority}">${formatPriority(project.priority)}</span>
                        </div>
                    </div>
                </div>
                
                ${project.description ? `<div class="project-description">${escapeHtml(project.description.substring(0, 120))}${project.description.length > 120 ? '...' : ''}</div>` : ''}
                
                <div class="project-stats">
                    <div class="project-stat">
                        <div class="project-stat-value">${project.task_count || 0}</div>
                        <div class="project-stat-label">Tasks</div>
                    </div>
                    <div class="project-stat">
                        <div class="project-stat-value">${project.completed_tasks || 0}</div>
                        <div class="project-stat-label">Completed</div>
                    </div>
                    <div class="project-stat">
                        <div class="project-stat-value">${project.member_count || 0}</div>
                        <div class="project-stat-label">Members</div>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill ${progress >= 100 ? 'completed' : ''}" style="width: ${progress}%"></div>
                </div>
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 16px; font-weight: 600;">${progress}% Complete</div>
                
                <div class="project-footer">
                    <div class="project-actions">
                        <button onclick="openProjectDetailModal(${project.id})" class="btn-primary">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="editProject(${project.id})" class="btn-secondary">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteProject(${project.id})" class="btn-danger">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function searchProjects() {
    const searchInput = document.getElementById('project-search');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    const filtered = projectsData.filter(project => 
        project.project_name.toLowerCase().includes(searchTerm) ||
        project.project_key.toLowerCase().includes(searchTerm) ||
        (project.description && project.description.toLowerCase().includes(searchTerm))
    );
    displayProjects('projects-list', filtered);
}

// ==========================================
// UTILITY FUNCTIONS
// ==========================================

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

function formatStatus(status) {
    if (!status) return '';
    return status.replace('-', ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function formatPriority(priority) {
    if (!priority) return '';
    return priority.charAt(0).toUpperCase() + priority.slice(1);
}

function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '';
    const date = new Date(dateTimeString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    
    return date.toLocaleDateString();
}

function showAlert(type, message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: type,
            title: type === 'success' ? 'Success!' : 'Error!',
            text: message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            customClass: {
                container: 'swal-high-z'
            }
        });
    } else {
        // Fallback custom alert
        const alertDiv = document.createElement('div');
        alertDiv.className = `custom-alert ${type}`;
        alertDiv.textContent = message;
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 3000);
    }
}

// Add CSS for high z-index SweetAlert
if (!document.getElementById('swal-z-index-style')) {
    const style = document.createElement('style');
    style.id = 'swal-z-index-style';
    style.textContent = '.swal-high-z { z-index: 10000 !important; }';
    document.head.appendChild(style);
}

// Close modals when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
});

// Export task data to CSV
function exportTasksToCSV() {
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_all_tasks', status: 'all' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const tasks = data.tasks;
            
            let csv = 'Project,Task,Status,Priority,Assigned To,Due Date,Progress,Created\n';
            
            tasks.forEach(task => {
                csv += `"${task.project_name}","${task.task_name}","${task.status}","${task.priority}",`;
                csv += `"${task.assigned_to_name || 'Unassigned'}","${task.due_date || ''}","${task.progress || 0}%","${formatDate(task.created_at)}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `tasks-export-${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
            
            showAlert('success', 'Tasks exported successfully');
        }
    });
}


// Auto-set progress to 100% when status is changed to completed
document.addEventListener('DOMContentLoaded', function() {
    const taskStatusSelect = document.getElementById('taskStatus');
    const taskProgressInput = document.getElementById('taskProgress');
    
    if (taskStatusSelect && taskProgressInput) {
        taskStatusSelect.addEventListener('change', function() {
            if (this.value === 'completed') {
                taskProgressInput.value = 100;
                console.log('Auto-set progress to 100% for completed task');
            }
        });
    }
});


// Fix: Make sure task status tabs actually filter tasks properly
// Enhanced switchTaskStatusTab with better logging and event handling
function switchTaskStatusTab(status) {
    console.log('=== switchTaskStatusTab called ===');
    console.log('Status:', status);
    console.log('Current project ID:', window.currentProjectId);
    
    // Update tab active state within the project-tasks-tab
    const taskTabsContainer = document.querySelector('#project-tasks-tab .tabs');
    console.log('Task tabs container found:', !!taskTabsContainer);
    
    if (taskTabsContainer) {
        const tabs = taskTabsContainer.querySelectorAll('.tab');
        console.log('Number of tabs found:', tabs.length);
        
        tabs.forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Find and activate the clicked tab
        const clickedTab = event?.target?.closest('.tab');
        if (clickedTab) {
            clickedTab.classList.add('active');
            console.log('Activated tab:', clickedTab.textContent.trim());
        }
    }
    
    // Store the status and reload tasks
    window.currentTaskStatusFilter = status;
    console.log('Set currentTaskStatusFilter to:', status);
    
    if (window.currentProjectId) {
        console.log('Calling loadProjectTasksByStatus...');
        loadProjectTasksByStatus(window.currentProjectId, status);
    } else {
        console.error('No currentProjectId set!');
    }
}

// Load project Kanban board
function loadProjectKanban(projectId) {
    console.log('Loading Kanban for project:', projectId);
    
    fetch('/management/ajax/project_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 
            action: 'get_project_tasks', 
            project_id: projectId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayKanbanBoard(data.tasks, 'project-kanban-board');
        }
    })
    .catch(error => console.error('Error loading project kanban:', error));
}

// Update displayKanbanBoard to accept container ID parameter
window.originalDisplayKanbanBoard = window.displayKanbanBoard || displayKanbanBoard;



// Toggle Start Date field visibility
function toggleStartDate() {
    const checkbox = document.getElementById('hasStartDate');
    const row = document.getElementById('startDateRow');
    const input = document.getElementById('taskStartDate');
    
    if (checkbox.checked) {
        row.style.display = 'block';
    } else {
        row.style.display = 'none';
        input.value = ''; // Clear the value when hiding
    }
}

// Toggle Due Date field visibility
function toggleDueDate() {
    const checkbox = document.getElementById('hasDueDate');
    const row = document.getElementById('dueDateRow');
    const input = document.getElementById('taskDueDate');
    
    if (checkbox.checked) {
        row.style.display = 'block';
    } else {
        row.style.display = 'none';
        input.value = ''; // Clear the value when hiding
    }
}

// Reset date checkboxes when opening task modal
const originalOpenCreateTaskModal = window.openCreateTaskModal;
if (originalOpenCreateTaskModal) {
    window.openCreateTaskModal = function(projectId) {
        originalOpenCreateTaskModal(projectId);
        
        // Reset checkboxes and hide date fields
        document.getElementById('hasStartDate').checked = false;
        document.getElementById('hasDueDate').checked = false;
        document.getElementById('startDateRow').style.display = 'none';
        document.getElementById('dueDateRow').style.display = 'none';
        document.getElementById('taskStartDate').value = '';
        document.getElementById('taskDueDate').value = '';
    };
}

// Update editTask to show date fields if dates exist
const originalEditTask = window.editTask;
if (originalEditTask) {
    window.editTask = function(taskId) {
        originalEditTask(taskId);
        
        // Wait a bit for the modal to populate, then check dates
        setTimeout(() => {
            const startDate = document.getElementById('taskStartDate').value;
            const dueDate = document.getElementById('taskDueDate').value;
            
            if (startDate) {
                document.getElementById('hasStartDate').checked = true;
                document.getElementById('startDateRow').style.display = 'block';
            }
            
            if (dueDate) {
                document.getElementById('hasDueDate').checked = true;
                document.getElementById('dueDateRow').style.display = 'block';
            }
        }, 100);
    };
}

