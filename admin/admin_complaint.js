// admin_complaint.js
// Unified complaint management for all admin roles

// Global variables
let currentComplaintId = null;
let currentFilter = 'all';
let currentStatusFilter = '';
let currentRoleFilter = '';
let currentPage = 1;
let totalPages = 1;
let userRole = '';

// Initialize complaint module
async function initAdminComplaintModule() {
    // Get user role
    const response = await fetch('get_user_role.php');
    const data = await response.json();
    userRole = data.role;
    
    renderAdminComplaintView();
}

function renderAdminComplaintView() {
    const container = document.getElementById('dashboardBody');
    if (!container) return;
    
    // Different tabs based on role
    let tabsHtml = '';
    let defaultView = '';
    
    if (userRole === 'secretary') {
        tabsHtml = `
            <button class="complaint-tab active" data-tab="pending" onclick="loadPendingComplaints()">
                <i class="fas fa-clock"></i> Pending Review
            </button>
            <button class="complaint-tab" data-tab="all" onclick="loadAllComplaints()">
                <i class="fas fa-list"></i> All Complaints
            </button>
            <button class="complaint-tab" data-tab="assigned" onclick="loadAssignedComplaints()">
                <i class="fas fa-user-check"></i> Assigned Cases
            </button>
        `;
        defaultView = 'pending';
    } else if (userRole === 'lupon') {
        tabsHtml = `
            <button class="complaint-tab active" data-tab="assigned" onclick="loadMyAssignedComplaints()">
                <i class="fas fa-gavel"></i> My Assigned Cases
            </button>
            <button class="complaint-tab" data-tab="hearings" onclick="loadMyHearings()">
                <i class="fas fa-calendar-alt"></i> My Hearings
            </button>
        `;
        defaultView = 'assigned';
    } else if (userRole === 'kagawad') {
        tabsHtml = `
            <button class="complaint-tab active" data-tab="assigned" onclick="loadMyAssignedComplaints()">
                <i class="fas fa-bullhorn"></i> My Assigned Cases
            </button>
            <button class="complaint-tab" data-tab="support" onclick="loadSupportRequests()">
                <i class="fas fa-handshake"></i> Support Requests
            </button>
        `;
        defaultView = 'assigned';
    } else if (userRole === 'captain') {
        tabsHtml = `
            <button class="complaint-tab active" data-tab="escalated" onclick="loadEscalatedComplaints()">
                <i class="fas fa-arrow-up"></i> Escalated Cases
            </button>
            <button class="complaint-tab" data-tab="all" onclick="loadAllComplaints()">
                <i class="fas fa-list"></i> All Complaints
            </button>
        `;
        defaultView = 'escalated';
    }
    
    container.innerHTML = `
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-gavel"></i> Complaint Management
            </div>
            <div class="section-sub">
                ${getRoleDescription()}
            </div>
            
            <div class="complaint-tabs" style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e2efe8;">
                ${tabsHtml}
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar" id="complaintFilterBar" style="margin-bottom: 20px; display: none;">
                <select id="statusFilter" class="filter-select" onchange="applyFilters()">
                    <option value="">All Statuses</option>
                    <option value="pending_review">Pending Review</option>
                    <option value="for_mediation">For Mediation</option>
                    <option value="for_action">For Action</option>
                    <option value="mediation_scheduled">Hearing Scheduled</option>
                    <option value="in_mediation">In Mediation</option>
                    <option value="settled">Settled</option>
                    <option value="failed_mediation">Failed Mediation</option>
                    <option value="escalated">Escalated</option>
                </select>
                <input type="text" id="searchComplaint" placeholder="Search by reference, title, or complainant..." onkeyup="applyFilters()">
            </div>
            
            <div id="complaintContent">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
            </div>
        </div>
    `;
    
    // Load initial view
    if (defaultView === 'pending') loadPendingComplaints();
    else if (defaultView === 'assigned') loadMyAssignedComplaints();
    else if (defaultView === 'escalated') loadEscalatedComplaints();
    else loadAllComplaints();
}

function getRoleDescription() {
    const descriptions = {
        'secretary': 'Review, classify, and assign complaints to appropriate officials.',
        'lupon': 'Conduct mediation hearings and facilitate dispute resolution.',
        'kagawad': 'Assist in mediation and provide support for dispute resolution.',
        'captain': 'Review escalated cases and provide final resolution.'
    };
    return descriptions[userRole] || 'Manage complaints and facilitate resolutions.';
}

// ============ SECRETARY FUNCTIONS ============

async function loadPendingComplaints() {
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading pending complaints...</p></div>';
    
    try {
        const response = await fetch('complaint_ajax.php?action=get_pending_complaints');
        const data = await response.json();
        
        if (data.success) {
            renderSecretaryComplaintsList(data.complaints, 'pending_review');
        } else {
            container.innerHTML = '<div class="empty-state"><p>No pending complaints.</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Error loading complaints.</p></div>';
    }
}

async function loadAllComplaints(page = 1) {
    currentPage = page;
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading complaints...</p></div>';
    
    // Show filter bar
    document.getElementById('complaintFilterBar').style.display = 'flex';
    
    let url = `complaint_ajax.php?action=get_all_complaints&page=${page}`;
    if (currentStatusFilter) url += `&status=${currentStatusFilter}`;
    if (currentRoleFilter) url += `&role=${currentRoleFilter}`;
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            renderSecretaryComplaintsList(data.complaints, 'all', data.counts);
            totalPages = data.total_pages || 1;
        } else {
            container.innerHTML = '<div class="empty-state"><p>No complaints found.</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Error loading complaints.</p></div>';
    }
}

function renderSecretaryComplaintsList(complaints, source, counts = null) {
    const container = document.getElementById('complaintContent');
    
    // Show counts summary if available
    let countsHtml = '';
    if (counts) {
        countsHtml = `
            <div class="stats-row-compact" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                <div class="stat-compact"><span class="stat-label">Pending</span><span class="stat-value">${counts.pending || 0}</span></div>
                <div class="stat-compact"><span class="stat-label">For Mediation</span><span class="stat-value">${counts.for_mediation || 0}</span></div>
                <div class="stat-compact"><span class="stat-label">For Action</span><span class="stat-value">${counts.for_action || 0}</span></div>
                <div class="stat-compact"><span class="stat-label">In Mediation</span><span class="stat-value">${counts.in_mediation || 0}</span></div>
                <div class="stat-compact"><span class="stat-label">Settled</span><span class="stat-value">${counts.settled || 0}</span></div>
            </div>
        `;
    }
    
    if (complaints.length === 0) {
        container.innerHTML = countsHtml + `<div class="empty-state"><p>No complaints found.</p></div>`;
        return;
    }
    
    container.innerHTML = countsHtml + `
        <div class="complaints-list">
            ${complaints.map(complaint => `
                <div class="complaint-card admin-card" data-id="${complaint.id}">
                    <div class="complaint-card-header">
                        <div class="complaint-title">
                            <i class="fas fa-file-alt"></i>
                            <strong>${escapeHtml(complaint.title)}</strong>
                            <span class="complaint-ref">#${escapeHtml(complaint.reference_number)}</span>
                        </div>
                        ${getStatusBadge(complaint.status)}
                    </div>
                    <div class="complaint-card-body">
                        <div class="complaint-info-row">
                            <span><i class="fas fa-user"></i> Complainant: ${escapeHtml(complaint.first_name)} ${escapeHtml(complaint.last_name)}</span>
                            <span><i class="fas fa-user-friends"></i> Respondent: ${escapeHtml(complaint.respondent_name || 'N/A')}</span>
                        </div>
                        <div class="complaint-info-row">
                            <span><i class="fas fa-tag"></i> Type: ${escapeHtml(complaint.complaint_type)}</span>
                            <span><i class="fas fa-flag"></i> Priority: ${getPriorityBadge(complaint.priority)}</span>
                        </div>
                        <div class="complaint-description-preview">
                            ${escapeHtml(complaint.description.substring(0, 150))}${complaint.description.length > 150 ? '...' : ''}
                        </div>
                    </div>
                    <div class="complaint-card-footer">
                        ${getActionButtons(complaint)}
                    </div>
                </div>
            `).join('')}
        </div>
        ${renderPagination()}
    `;
}

function getActionButtons(complaint) {
    if (userRole === 'secretary') {
        if (complaint.status === 'pending_review') {
            return `
                <button class="action-btn assign" onclick="event.stopPropagation(); showAssignmentModal(${complaint.id})">
                    <i class="fas fa-user-plus"></i> Assign
                </button>
                <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                    <i class="fas fa-eye"></i> Review
                </button>
            `;
        } else {
            return `
                <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                    <i class="fas fa-eye"></i> View Details
                </button>
                <button class="action-btn update" onclick="event.stopPropagation(); showStatusUpdateModal(${complaint.id}, '${complaint.status}')">
                    <i class="fas fa-edit"></i> Update Status
                </button>
            `;
        }
    } else if (userRole === 'lupon' || userRole === 'kagawad') {
        if (complaint.status === 'for_mediation' || complaint.status === 'mediation_scheduled' || complaint.status === 'in_mediation') {
            return `
                <button class="action-btn schedule" onclick="event.stopPropagation(); showScheduleHearingModal(${complaint.id})">
                    <i class="fas fa-calendar-plus"></i> Schedule Hearing
                </button>
                <button class="action-btn mediate" onclick="event.stopPropagation(); showMediationOutcomeModal(${complaint.id})">
                    <i class="fas fa-gavel"></i> Record Outcome
                </button>
                <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                    <i class="fas fa-eye"></i> View Details
                </button>
            `;
        } else if (complaint.status === 'for_action') {
            return `
                <button class="action-btn assist" onclick="event.stopPropagation(); showAssistanceModal(${complaint.id})">
                    <i class="fas fa-handshake"></i> Provide Assistance
                </button>
                <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                    <i class="fas fa-eye"></i> View Details
                </button>
            `;
        }
        return `
            <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                <i class="fas fa-eye"></i> View Details
            </button>
        `;
    } else if (userRole === 'captain') {
        if (complaint.status === 'escalated' || complaint.status === 'failed_mediation') {
            return `
                <button class="action-btn resolve" onclick="event.stopPropagation(); showCaptainResolutionModal(${complaint.id})">
                    <i class="fas fa-check-double"></i> Resolve Case
                </button>
                <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                    <i class="fas fa-eye"></i> View Details
                </button>
            `;
        }
        return `
            <button class="action-btn view" onclick="event.stopPropagation(); viewComplaintDetails(${complaint.id})">
                <i class="fas fa-eye"></i> View Details
            </button>
        `;
    }
    return '';
}

// ============ ASSIGNMENT MODAL (SECRETARY) ============

async function showAssignmentModal(complaintId) {
    currentComplaintId = complaintId;
    
    // Load available officials
    const luponResponse = await fetch('complaint_ajax.php?action=get_available_officials&role=lupon');
    const luponData = await luponResponse.json();
    const kagawadResponse = await fetch('complaint_ajax.php?action=get_available_officials&role=kagawad');
    const kagawadData = await kagawadResponse.json();
    
    Swal.fire({
        title: 'Assign Complaint',
        html: `
            <div style="text-align: left;">
                <div class="form-group">
                    <label>Assign To:</label>
                    <select id="assignRole" class="swal2-select" style="width: 100%; padding: 8px; margin-bottom: 15px;">
                        <option value="lupon">Lupon Tagapamayapa (Mediation)</option>
                        <option value="kagawad">Kagawad (Action/Assistance)</option>
                    </select>
                </div>
                <div class="form-group" id="officialsContainer">
                    <label>Select Official:</label>
                    <select id="assignTo" class="swal2-select" style="width: 100%; padding: 8px;">
                        ${luponData.success ? luponData.officials.map(o => `<option value="${o.id}">${escapeHtml(o.full_name)}</option>`).join('') : '<option>No Lupon available</option>'}
                    </select>
                </div>
                <div class="form-group">
                    <label>Assignment Notes:</label>
                    <textarea id="assignNotes" class="swal2-textarea" rows="3" placeholder="Add any notes about this assignment..."></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Assign',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const role = document.getElementById('assignRole').value;
            const assignedTo = document.getElementById('assignTo').value;
            const notes = document.getElementById('assignNotes').value;
            if (!assignedTo) {
                Swal.showValidationMessage('Please select an official to assign');
                return false;
            }
            return { role, assignedTo, notes };
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'assign_complaint');
            formData.append('complaint_id', currentComplaintId);
            formData.append('assigned_to', result.value.assignedTo);
            formData.append('assigned_role', result.value.role);
            formData.append('notes', result.value.notes);
            
            try {
                const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Assigned!', data.message, 'success');
                    loadPendingComplaints();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to assign complaint', 'error');
            }
        }
    });
    
    // Handle role change
    setTimeout(() => {
        const roleSelect = document.getElementById('assignRole');
        if (roleSelect) {
            roleSelect.addEventListener('change', async (e) => {
                const role = e.target.value;
                const response = await fetch(`complaint_ajax.php?action=get_available_officials&role=${role}`);
                const data = await response.json();
                
                const container = document.getElementById('officialsContainer');
                if (container && data.success) {
                    container.innerHTML = `
                        <label>Select Official:</label>
                        <select id="assignTo" class="swal2-select" style="width: 100%; padding: 8px;">
                            ${data.officials.map(o => `<option value="${o.id}">${escapeHtml(o.full_name)}</option>`).join('')}
                        </select>
                    `;
                }
            });
        }
    }, 100);
}

// ============ LUPON FUNCTIONS ============

async function loadMyAssignedComplaints() {
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading assigned cases...</p></div>';
    
    try {
        const response = await fetch('complaint_ajax.php?action=get_my_assigned_complaints');
        const data = await response.json();
        
        if (data.success && data.complaints.length > 0) {
            renderAssignedComplaintsList(data.complaints);
        } else {
            container.innerHTML = '<div class="empty-state"><p>No cases assigned to you.</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Error loading assigned cases.</p></div>';
    }
}

function renderAssignedComplaintsList(complaints) {
    const container = document.getElementById('complaintContent');
    
    container.innerHTML = `
        <div class="complaints-list">
            ${complaints.map(complaint => `
                <div class="complaint-card admin-card" data-id="${complaint.id}">
                    <div class="complaint-card-header">
                        <div class="complaint-title">
                            <i class="fas fa-file-alt"></i>
                            <strong>${escapeHtml(complaint.title)}</strong>
                            <span class="complaint-ref">#${escapeHtml(complaint.reference_number)}</span>
                        </div>
                        ${getStatusBadge(complaint.status)}
                    </div>
                    <div class="complaint-card-body">
                        <div class="complaint-info-row">
                            <span><i class="fas fa-user"></i> Complainant: ${escapeHtml(complaint.first_name)} ${escapeHtml(complaint.last_name)}</span>
                            <span><i class="fas fa-user-friends"></i> Respondent: ${escapeHtml(complaint.respondent_name || 'N/A')}</span>
                        </div>
                        <div class="complaint-info-row">
                            <span><i class="fas fa-flag"></i> Priority: ${getPriorityBadge(complaint.priority)}</span>
                            <span><i class="fas fa-calendar"></i> Filed: ${new Date(complaint.created_at).toLocaleDateString()}</span>
                        </div>
                        <div class="complaint-description-preview">
                            ${escapeHtml(complaint.description.substring(0, 150))}${complaint.description.length > 150 ? '...' : ''}
                        </div>
                    </div>
                    <div class="complaint-card-footer">
                        ${getActionButtons(complaint)}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function showScheduleHearingModal(complaintId) {
    Swal.fire({
        title: 'Schedule Mediation Hearing',
        html: `
            <div style="text-align: left;">
                <div class="form-group">
                    <label>Hearing Date:</label>
                    <input type="date" id="hearingDate" class="swal2-input" style="width: 100%;" required>
                </div>
                <div class="form-group">
                    <label>Hearing Time:</label>
                    <input type="time" id="hearingTime" class="swal2-input" style="width: 100%;" required>
                </div>
                <div class="form-group">
                    <label>Location:</label>
                    <input type="text" id="hearingLocation" class="swal2-input" style="width: 100%;" value="Barangay Hall" required>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Schedule',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const date = document.getElementById('hearingDate').value;
            const time = document.getElementById('hearingTime').value;
            const location = document.getElementById('hearingLocation').value;
            if (!date || !time) {
                Swal.showValidationMessage('Please enter date and time');
                return false;
            }
            return { date, time, location };
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'schedule_hearing');
            formData.append('complaint_id', complaintId);
            formData.append('hearing_date', result.value.date);
            formData.append('hearing_time', result.value.time);
            formData.append('location', result.value.location);
            
            try {
                const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Scheduled!', data.message, 'success');
                    loadMyAssignedComplaints();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to schedule hearing', 'error');
            }
        }
    });
}

function showMediationOutcomeModal(complaintId) {
    Swal.fire({
        title: 'Mediation Outcome',
        html: `
            <div style="text-align: left;">
                <div class="form-group">
                    <label>Outcome:</label>
                    <select id="outcome" class="swal2-select" style="width: 100%; padding: 8px;">
                        <option value="settled">Settled - Both parties reached an agreement</option>
                        <option value="failed">Failed - Parties could not reach agreement</option>
                        <option value="rescheduled">Reschedule - Need another hearing</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Summary/Notes:</label>
                    <textarea id="summary" class="swal2-textarea" rows="4" placeholder="Enter mediation summary and details..."></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Submit Outcome',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const outcome = document.getElementById('outcome').value;
            const summary = document.getElementById('summary').value;
            if (!summary) {
                Swal.showValidationMessage('Please enter a summary of the mediation');
                return false;
            }
            return { outcome, summary };
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            let newStatus = '';
            if (result.value.outcome === 'settled') newStatus = 'settled';
            else if (result.value.outcome === 'failed') newStatus = 'failed_mediation';
            else newStatus = 'for_mediation';
            
            const formData = new FormData();
            formData.append('action', 'update_mediation_outcome');
            formData.append('complaint_id', complaintId);
            formData.append('outcome', result.value.outcome);
            formData.append('summary', result.value.summary);
            formData.append('new_status', newStatus);
            
            try {
                const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Recorded!', data.message, 'success');
                    loadMyAssignedComplaints();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to record outcome', 'error');
            }
        }
    });
}

// ============ CAPTAIN FUNCTIONS ============

async function loadEscalatedComplaints() {
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading escalated cases...</p></div>';
    
    try {
        const response = await fetch('complaint_ajax.php?action=get_escalated_complaints');
        const data = await response.json();
        
        if (data.success && data.complaints.length > 0) {
            renderEscalatedComplaintsList(data.complaints);
        } else {
            container.innerHTML = '<div class="empty-state"><p>No escalated cases found.</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Error loading escalated cases.</p></div>';
    }
}

function renderEscalatedComplaintsList(complaints) {
    const container = document.getElementById('complaintContent');
    
    container.innerHTML = `
        <div class="complaints-list">
            ${complaints.map(complaint => `
                <div class="complaint-card admin-card escalated" data-id="${complaint.id}">
                    <div class="complaint-card-header">
                        <div class="complaint-title">
                            <i class="fas fa-file-alt"></i>
                            <strong>${escapeHtml(complaint.title)}</strong>
                            <span class="complaint-ref">#${escapeHtml(complaint.reference_number)}</span>
                        </div>
                        ${getStatusBadge(complaint.status)}
                    </div>
                    <div class="complaint-card-body">
                        <div class="complaint-info-row">
                            <span><i class="fas fa-user"></i> Complainant: ${escapeHtml(complaint.first_name)} ${escapeHtml(complaint.last_name)}</span>
                            <span><i class="fas fa-user-friends"></i> Respondent: ${escapeHtml(complaint.respondent_name || 'N/A')}</span>
                        </div>
                        <div class="complaint-info-row">
                            <span><i class="fas fa-user-check"></i> Assigned To: ${escapeHtml(complaint.assigned_to_name || 'N/A')}</span>
                            <span><i class="fas fa-calendar"></i> Filed: ${new Date(complaint.created_at).toLocaleDateString()}</span>
                        </div>
                        <div class="complaint-description-preview">
                            ${escapeHtml(complaint.description.substring(0, 150))}${complaint.description.length > 150 ? '...' : ''}
                        </div>
                    </div>
                    <div class="complaint-card-footer">
                        ${getActionButtons(complaint)}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function showCaptainResolutionModal(complaintId) {
    Swal.fire({
        title: 'Captain\'s Resolution',
        html: `
            <div style="text-align: left;">
                <div class="form-group">
                    <label>Resolution:</label>
                    <select id="resolutionStatus" class="swal2-select" style="width: 100%; padding: 8px;">
                        <option value="settled">Case Settled - Issue resolved</option>
                        <option value="dismissed">Case Dismissed - No further action</option>
                        <option value="escalated">Escalate to Higher Authority</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Resolution Details:</label>
                    <textarea id="resolutionDetails" class="swal2-textarea" rows="4" placeholder="Enter your resolution and decision details..."></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Issue Resolution',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const status = document.getElementById('resolutionStatus').value;
            const details = document.getElementById('resolutionDetails').value;
            if (!details) {
                Swal.showValidationMessage('Please enter resolution details');
                return false;
            }
            return { status, details };
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'captain_resolution');
            formData.append('complaint_id', complaintId);
            formData.append('resolution', result.value.details);
            formData.append('new_status', result.value.status);
            
            try {
                const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Resolution Issued!', data.message, 'success');
                    loadEscalatedComplaints();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to issue resolution', 'error');
            }
        }
    });
}

// ============ COMPLAINT DETAILS VIEW ============

async function viewComplaintDetails(complaintId) {
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading complaint details...</p></div>';
    
    try {
        const response = await fetch(`complaint_ajax.php?action=get_complaint_details&id=${complaintId}`);
        const data = await response.json();
        
        if (data.success) {
            renderAdminComplaintDetails(data);
        } else {
            container.innerHTML = '<div class="empty-state"><p>Error loading complaint details.</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><p>Error loading details.</p></div>';
    }
}

function renderAdminComplaintDetails(data) {
    const complaint = data.complaint;
    const updates = data.updates || [];
    const hearings = data.hearings || [];
    const evidence = data.evidence || [];
    
    const container = document.getElementById('complaintContent');
    container.innerHTML = `
        <button class="btn-back" onclick="refreshCurrentView()" style="margin-bottom: 20px;">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        
        <div class="complaint-detail-card">
            <div class="detail-header">
                <h3><i class="fas fa-gavel"></i> ${escapeHtml(complaint.title)}</h3>
                ${getStatusBadge(complaint.status)}
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-info-circle"></i> Case Information</h4>
                <div class="detail-grid">
                    <div class="detail-item"><label>Reference Number:</label><span><strong>${escapeHtml(complaint.reference_number)}</strong></span></div>
                    <div class="detail-item"><label>Filed Date:</label><span>${new Date(complaint.created_at).toLocaleString()}</span></div>
                    <div class="detail-item"><label>Complaint Type:</label><span>${escapeHtml(complaint.complaint_type)}</span></div>
                    <div class="detail-item"><label>Priority:</label><span>${getPriorityBadge(complaint.priority)}</span></div>
                    <div class="detail-item"><label>Assigned To:</label><span>${escapeHtml(complaint.assigned_to_name || 'Not assigned')}</span></div>
                    <div class="detail-item"><label>Assigned Role:</label><span>${escapeHtml(complaint.assigned_role || 'N/A')}</span></div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-users"></i> Parties Involved</h4>
                <div class="detail-grid">
                    <div class="detail-item"><label>Complainant:</label><span>${escapeHtml(complaint.complainant_name)}</span></div>
                    <div class="detail-item"><label>Complainant Contact:</label><span>${escapeHtml(complaint.complainant_contact || 'N/A')}</span></div>
                    <div class="detail-item"><label>Respondent:</label><span>${escapeHtml(complaint.respondent_name || 'N/A')}</span></div>
                    <div class="detail-item"><label>Respondent Address:</label><span>${escapeHtml(complaint.respondent_address || 'N/A')}</span></div>
                </div>
                <div class="detail-item-full"><label>Description:</label><p>${escapeHtml(complaint.description)}</p></div>
            </div>
            
            ${hearings.length > 0 ? `
                <div class="detail-section">
                    <h4><i class="fas fa-calendar-alt"></i> Hearing Schedule</h4>
                    ${hearings.map(hearing => `
                        <div class="hearing-item">
                            <div class="hearing-date"><i class="fas fa-calendar"></i> ${new Date(hearing.hearing_date).toLocaleDateString()}</div>
                            <div class="hearing-time"><i class="fas fa-clock"></i> ${hearing.hearing_time}</div>
                            <div class="hearing-location"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(hearing.location)}</div>
                            ${hearing.conducted_by_name ? `<div class="hearing-conducted"><i class="fas fa-user-check"></i> Conducted by: ${escapeHtml(hearing.conducted_by_name)}</div>` : ''}
                            ${hearing.outcome ? `<div class="hearing-outcome"><strong>Outcome:</strong> ${escapeHtml(hearing.outcome)}</div>` : ''}
                            ${hearing.summary ? `<div class="hearing-summary">${escapeHtml(hearing.summary)}</div>` : ''}
                        </div>
                    `).join('')}
                </div>
            ` : ''}
            
            ${evidence.length > 0 ? `
                <div class="detail-section">
                    <h4><i class="fas fa-paperclip"></i> Evidence Attachments</h4>
                    <div class="evidence-list">
                        ${evidence.map(ev => `
                            <div class="evidence-item">
                                <i class="fas fa-file"></i>
                                <a href="../../${ev.file_path}" target="_blank">${escapeHtml(ev.file_name)}</a>
                                <span class="evidence-date">${new Date(ev.uploaded_at).toLocaleDateString()}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : ''}
            
            <div class="detail-section">
                <h4><i class="fas fa-history"></i> Case Timeline</h4>
                <div class="timeline">
                    ${updates.map(update => `
                        <div class="timeline-item">
                            <div class="timeline-time">${new Date(update.created_at).toLocaleString()}</div>
                            <div class="timeline-content">
                                <i class="fas ${update.update_type === 'status_change' ? 'fa-exchange-alt' : update.update_type === 'hearing_scheduled' ? 'fa-calendar-plus' : 'fa-comment'}"></i>
                                ${escapeHtml(update.notes)}
                                <div class="timeline-by">by ${escapeHtml(update.updated_by_role || 'System')}</div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-comment"></i> Add Internal Note</h4>
                <textarea id="complaintNote" class="form-control" rows="3" placeholder="Add a note to this case..."></textarea>
                <button class="btn-verify" onclick="addComplaintNote(${complaint.id})" style="margin-top: 10px;">
                    <i class="fas fa-paper-plane"></i> Add Note
                </button>
            </div>
        </div>
    `;
}

// ============ HELPER FUNCTIONS ============

function getStatusBadge(status) {
    const statusConfig = {
        'pending_review': { class: 'status-pending', text: 'Pending Review', icon: 'fa-clock' },
        'for_mediation': { class: 'status-approved', text: 'For Mediation', icon: 'fa-handshake' },
        'for_action': { class: 'status-info', text: 'For Action', icon: 'fa-bullhorn' },
        'mediation_scheduled': { class: 'status-info', text: 'Hearing Scheduled', icon: 'fa-calendar' },
        'in_mediation': { class: 'status-info', text: 'In Mediation', icon: 'fa-gavel' },
        'settled': { class: 'status-completed', text: 'Settled', icon: 'fa-check-circle' },
        'failed_mediation': { class: 'status-rejected', text: 'Failed Mediation', icon: 'fa-times-circle' },
        'escalated': { class: 'status-warning', text: 'Escalated', icon: 'fa-arrow-up' },
        'dismissed': { class: 'status-rejected', text: 'Dismissed', icon: 'fa-ban' }
    };
    const config = statusConfig[status] || statusConfig.pending_review;
    return `<span class="status-badge ${config.class}"><i class="fas ${config.icon}"></i> ${config.text}</span>`;
}

function getPriorityBadge(priority) {
    const priorityConfig = {
        'low': '🟢 Low',
        'medium': '🟡 Medium',
        'high': '🟠 High',
        'urgent': '🔴 Urgent'
    };
    return priorityConfig[priority] || priority;
}

async function addComplaintNote(complaintId) {
    const note = document.getElementById('complaintNote').value;
    if (!note.trim()) {
        Swal.fire('Warning', 'Please enter a note', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_complaint_note');
    formData.append('complaint_id', complaintId);
    formData.append('note', note);
    
    try {
        const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            Swal.fire('Success', 'Note added successfully', 'success');
            document.getElementById('complaintNote').value = '';
            viewComplaintDetails(complaintId);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    } catch (error) {
        Swal.fire('Error', 'Failed to add note', 'error');
    }
}

function refreshCurrentView() {
    if (userRole === 'secretary') {
        loadAllComplaints();
    } else if (userRole === 'lupon' || userRole === 'kagawad') {
        loadMyAssignedComplaints();
    } else if (userRole === 'captain') {
        loadEscalatedComplaints();
    }
}

function applyFilters() {
    currentStatusFilter = document.getElementById('statusFilter').value;
    loadAllComplaints(1);
}

function renderPagination() {
    if (totalPages <= 1) return '';
    return `
        <div class="pagination">
            ${currentPage > 1 ? `<button class="page-btn" onclick="loadAllComplaints(${currentPage - 1})">Previous</button>` : ''}
            <span class="page-info">Page ${currentPage} of ${totalPages}</span>
            ${currentPage < totalPages ? `<button class="page-btn" onclick="loadAllComplaints(${currentPage + 1})">Next</button>` : ''}
        </div>
    `;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show status update modal for secretary
function showStatusUpdateModal(complaintId, currentStatus) {
    Swal.fire({
        title: 'Update Complaint Status',
        html: `
            <div style="text-align: left;">
                <div class="form-group">
                    <label>New Status:</label>
                    <select id="newStatus" class="swal2-select" style="width: 100%; padding: 8px;">
                        <option value="pending_review" ${currentStatus === 'pending_review' ? 'selected' : ''}>Pending Review</option>
                        <option value="for_mediation">For Mediation</option>
                        <option value="for_action">For Action</option>
                        <option value="mediation_scheduled">Hearing Scheduled</option>
                        <option value="in_mediation">In Mediation</option>
                        <option value="settled">Settled</option>
                        <option value="failed_mediation">Failed Mediation</option>
                        <option value="escalated">Escalated</option>
                        <option value="dismissed">Dismissed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status Notes:</label>
                    <textarea id="statusNotes" class="swal2-textarea" rows="3" placeholder="Provide reason for status change..."></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const newStatus = document.getElementById('newStatus').value;
            const notes = document.getElementById('statusNotes').value;
            return { newStatus, notes };
        }
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'update_complaint_status');
            formData.append('complaint_id', complaintId);
            formData.append('status', result.value.newStatus);
            formData.append('notes', result.value.notes);
            
            try {
                const response = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Updated!', data.message, 'success');
                    loadAllComplaints();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to update status', 'error');
            }
        }
    });
}