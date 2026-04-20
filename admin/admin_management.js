// admin_management.js
// Admin/Official Accounts Management Module

let currentAdminData = [];
let currentAdminPage = 1;
let totalAdminPages = 1;
let totalAdminsCount = 0;
let selectedRole = null;

// Role limits (Super Admin excluded from selection)
const ROLE_LIMITS = {
    'captain': { max: 1, current: 0, name: 'Barangay Captain', icon: 'fas fa-user-tie', color: '#8B0000', order: 1 },
    'secretary': { max: 1, current: 0, name: 'Secretary', icon: 'fas fa-file-alt', color: '#2E7D32', order: 2 },
    'kagawad': { max: 7, current: 0, name: 'Kagawad', icon: 'fas fa-users', color: '#1565C0', order: 3 },
    'lupon': { max: 1, current: 0, name: 'Lupon Member', icon: 'fas fa-gavel', color: '#E65100', order: 4 }
};

// Roles to display in organization chart (excluding super_admin)
const DISPLAY_ROLES = ['captain', 'secretary', 'kagawad', 'lupon'];

// ============ RENDER ADMIN/OFFICIAL MANAGEMENT INTERFACE ============

function renderAdminManagement() {
    return `
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-users-cog"></i> Barangay Officials & Staff Management
            </div>
            <div class="section-sub">
                Manage barangay captains, secretaries, kagawads, and lupons
            </div>
            
            <div style="margin-bottom: 25px;">
                <button class="btn-verify" onclick="showRoleSelection()">
                    <i class="fas fa-plus-circle"></i> Add New Official/Staff
                </button>
            </div>
            
            <!-- Organization Chart -->
            <div id="organizationChart">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading organization chart...</p></div>
            </div>
        </div>
    `;
}

// ============ ROLE SELECTION CARD VIEW ============

function showRoleSelection() {
    createRoleModal();
    
    const modal = document.getElementById('roleModal');
    const modalBody = document.getElementById('roleModalBody');
    
    // Update role counts based on current data
    updateRoleCounts();
    
    let rolesHtml = '<div class="role-selection-grid">';
    
    for (const [key, role] of Object.entries(ROLE_LIMITS)) {
        const isFull = role.current >= role.max;
        const remaining = role.max - role.current;
        
        rolesHtml += `
            <div class="role-card ${isFull ? 'disabled' : ''}" onclick="${!isFull ? `selectRole('${key}')` : ''}">
                <div class="role-card-icon" style="background: ${role.color}20; color: ${role.color};">
                    <i class="${role.icon}"></i>
                </div>
                <div class="role-card-title">${role.name}</div>
                <div class="role-card-limit">
                    ${role.current} / ${role.max} positions filled
                </div>
                ${isFull ? '<div class="role-card-full-badge"><i class="fas fa-ban"></i> Full</div>' : `<div class="role-card-available">${remaining} slot${remaining > 1 ? 's' : ''} available</div>`}
            </div>
        `;
    }
    
    rolesHtml += '</div>';
    
    modalBody.innerHTML = `
        <div class="role-selection-container">
            <h3 style="text-align: center; margin-bottom: 20px; color: #1a472a;">
                <i class="fas fa-user-plus"></i> Select Position to Add
            </h3>
            ${rolesHtml}
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn-close" onclick="closeRoleModal()">Cancel</button>
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

function updateRoleCounts() {
    // Reset counts
    for (const key in ROLE_LIMITS) {
        ROLE_LIMITS[key].current = 0;
    }
    
    // Count current admins (excluding super_admin)
    currentAdminData.forEach(admin => {
        if (ROLE_LIMITS[admin.admin_role]) {
            ROLE_LIMITS[admin.admin_role].current++;
        }
    });
}

function selectRole(role) {
    selectedRole = role;
    closeRoleModal();
    showAddAdminForm(role);
}

// ============ ROLE MODAL ============

function createRoleModal() {
    if (document.getElementById('roleModal')) return;
    
    const modalHtml = `
        <div id="roleModal" class="modal">
            <div class="modal-content" style="max-width: 700px; width: 95%;">
                <div class="modal-header">
                    <h2><i class="fas fa-user-tie"></i> Select Position</h2>
                    <button class="close-modal" onclick="closeRoleModal()">&times;</button>
                </div>
                <div class="modal-body" id="roleModalBody" style="max-height: 70vh; overflow-y: auto;">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
                </div>
                <div class="modal-footer">
                    <button class="btn-close" onclick="closeRoleModal()">Close</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeRoleModal() {
    const modal = document.getElementById('roleModal');
    if (modal) modal.style.display = 'none';
    selectedRole = null;
}

// ============ SHOW ADD ADMIN FORM ============

function showAddAdminForm(role) {
    createAdminModal();
    
    const modal = document.getElementById('adminModal');
    const modalBody = document.getElementById('adminModalBody');
    const modalTitle = document.getElementById('adminModalTitle');
    
    const roleInfo = ROLE_LIMITS[role];
    modalTitle.innerHTML = `<i class="${roleInfo.icon}"></i> Add New ${roleInfo.name}`;
    
    modalBody.innerHTML = `
        <form id="adminForm" onsubmit="saveAdmin(event)">
            <div class="form-group">
                <label>Full Name <span style="color:red;">*</span></label>
                <input type="text" id="adminFullName" class="form-control" required placeholder="e.g., DELA CRUZ, JUAN M. or JUAN DELA CRUZ">
                <small class="form-hint">Format: LAST NAME, FIRST NAME MI. (e.g., DELA CRUZ, JUAN M.)</small>
            </div>
            
            <div class="form-group">
                <label>Suffix</label>
                <input type="text" id="adminSuffix" class="form-control" placeholder="Jr., Sr., III">
                <small class="form-hint">Optional - e.g., Jr., Sr., III</small>
            </div>
            
            <div class="form-group">
                <label>Email <span style="color:red;">*</span></label>
                <input type="email" id="adminEmail" class="form-control" required onblur="validateEmailField()">
                <small id="emailStatus" class="form-hint"></small>
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" id="adminPhone" class="form-control" placeholder="09XXXXXXXXX" onkeyup="validatePhoneInput(this)" onblur="validatePhoneNumberField()">
                <small id="phoneStatus" class="form-hint">Only numbers allowed (10-11 digits)</small>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Birth Date</label>
                    <input type="date" id="adminBirthDate" class="form-control">
                </div>
                <div class="form-group">
                    <label>Birth Place</label>
                    <input type="text" id="adminBirthPlace" class="form-control">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Gender</label>
                    <select id="adminGender" class="form-control">
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Civil Status</label>
                    <select id="adminCivilStatus" class="form-control">
                        <option value="">Select</option>
                        <option value="single">Single</option>
                        <option value="married">Married</option>
                        <option value="widowed">Widowed</option>
                        <option value="divorced">Divorced</option>
                        <option value="separated">Separated</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Religion</label>
                    <input type="text" id="adminReligion" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label>Occupation</label>
                <input type="text" id="adminOccupation" class="form-control">
            </div>
            
            <div class="form-group">
                <label>Status</label>
                <select id="adminIsActive" class="form-control">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            
            <div class="form-buttons">
                <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Create Account</button>
                <button type="button" class="btn-close" onclick="closeAdminModal()">Cancel</button>
            </div>
        </form>
    `;
    
    modal.style.display = 'block';
}

// ============ RENDER ORGANIZATION CHART (Excluding Super Admin) ============

function renderOrganizationChart() {
    const container = document.getElementById('organizationChart');
    if (!container) return;
    
    // Filter out super_admin from display
    const displayAdmins = currentAdminData.filter(admin => DISPLAY_ROLES.includes(admin.admin_role));
    
    if (displayAdmins.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-users fa-3x"></i>
                <p>No officials/staff found.</p>
                <button class="btn-verify" onclick="showRoleSelection()" style="margin-top: 15px;">
                    <i class="fas fa-plus-circle"></i> Add First Official
                </button>
            </div>
        `;
        return;
    }
    
    // Group admins by role (only display roles)
    const grouped = {
        captain: [],
        secretary: [],
        kagawad: [],
        lupon: []
    };
    
    displayAdmins.forEach(admin => {
        if (grouped[admin.admin_role]) {
            grouped[admin.admin_role].push(admin);
        }
    });
    
    const roleColors = {
        captain: '#8B0000',
        secretary: '#2E7D32',
        kagawad: '#1565C0',
        lupon: '#E65100'
    };
    
    const roleIcons = {
        captain: 'fas fa-user-tie',
        secretary: 'fas fa-file-alt',
        kagawad: 'fas fa-users',
        lupon: 'fas fa-gavel'
    };
    
    const roleNames = {
        captain: 'Barangay Captain',
        secretary: 'Secretary',
        kagawad: 'Kagawad',
        lupon: 'Lupon Member'
    };
    
    let html = `
        <div class="org-chart-container">
            <h3 class="org-chart-title">
                <i class="fas fa-sitemap"></i> Barangay Officials Organization Chart
            </h3>
            <div class="org-chart">
    `;
    
    // Captain (Top)
    if (grouped.captain.length > 0) {
        html += `
            <div class="org-level org-level-top">
                <div class="org-node captain">
                    <div class="org-node-icon" style="background: ${roleColors.captain}20; color: ${roleColors.captain};">
                        <i class="${roleIcons.captain}"></i>
                    </div>
                    <div class="org-node-info">
                        <div class="org-node-name">${escapeHtml(grouped.captain[0].full_name || grouped.captain[0].username)}</div>
                        <div class="org-node-title">${roleNames.captain}</div>
                        <div class="org-node-status ${grouped.captain[0].is_active ? 'active' : 'inactive'}">
                            ${grouped.captain[0].is_active ? 'Active' : 'Inactive'}
                        </div>
                    </div>
                    <div class="org-node-actions">
                        <button class="org-action-btn" onclick="editAdmin(${grouped.captain[0].id})"><i class="fas fa-edit"></i></button>
                        <button class="org-action-btn" onclick="resetAdminPassword(${grouped.captain[0].id}, '${escapeHtml(grouped.captain[0].username)}', '${escapeHtml(grouped.captain[0].email)}', '${escapeHtml(grouped.captain[0].full_name)}')"><i class="fas fa-key"></i></button>
                    </div>
                </div>
            </div>
        `;
    } else {
        html += `
            <div class="org-level org-level-top">
                <div class="org-node empty" onclick="showRoleSelection()">
                    <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="org-node-info">
                        <div class="org-node-name">Vacant Position</div>
                        <div class="org-node-title">${roleNames.captain}</div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Secretary (Below Captain)
    html += `<div class="org-connector"></div>`;
    html += `<div class="org-level org-level-second">`;
    
    if (grouped.secretary.length > 0) {
        html += `
            <div class="org-node secretary">
                <div class="org-node-icon" style="background: ${roleColors.secretary}20; color: ${roleColors.secretary};">
                    <i class="${roleIcons.secretary}"></i>
                </div>
                <div class="org-node-info">
                    <div class="org-node-name">${escapeHtml(grouped.secretary[0].full_name || grouped.secretary[0].username)}</div>
                    <div class="org-node-title">${roleNames.secretary}</div>
                    <div class="org-node-status ${grouped.secretary[0].is_active ? 'active' : 'inactive'}">
                        ${grouped.secretary[0].is_active ? 'Active' : 'Inactive'}
                    </div>
                </div>
                <div class="org-node-actions">
                    <button class="org-action-btn" onclick="editAdmin(${grouped.secretary[0].id})"><i class="fas fa-edit"></i></button>
                    <button class="org-action-btn" onclick="resetAdminPassword(${grouped.secretary[0].id}, '${escapeHtml(grouped.secretary[0].username)}', '${escapeHtml(grouped.secretary[0].email)}', '${escapeHtml(grouped.secretary[0].full_name)}')"><i class="fas fa-key"></i></button>
                </div>
            </div>
        `;
    } else {
        html += `
            <div class="org-node empty" onclick="showRoleSelection()">
                <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="org-node-info">
                    <div class="org-node-name">Vacant Position</div>
                    <div class="org-node-title">${roleNames.secretary}</div>
                </div>
            </div>
        `;
    }
    
    html += `</div>`;
    
    // Kagawad (7 members)
    html += `<div class="org-connector"></div>`;
    html += `<div class="org-level org-level-third">`;
    html += `<div class="org-level-label"><i class="fas fa-users"></i> Sangguniang Barangay Members (Kagawad)</div>`;
    html += `<div class="org-kagawad-grid">`;
    
    for (let i = 0; i < 7; i++) {
        const kagawad = grouped.kagawad[i];
        if (kagawad) {
            html += `
                <div class="org-node kagawad">
                    <div class="org-node-icon" style="background: ${roleColors.kagawad}20; color: ${roleColors.kagawad};">
                        <i class="${roleIcons.kagawad}"></i>
                    </div>
                    <div class="org-node-info">
                        <div class="org-node-name">${escapeHtml(kagawad.full_name || kagawad.username)}</div>
                        <div class="org-node-title">Kagawad</div>
                        <div class="org-node-status ${kagawad.is_active ? 'active' : 'inactive'}">
                            ${kagawad.is_active ? 'Active' : 'Inactive'}
                        </div>
                    </div>
                    <div class="org-node-actions">
                        <button class="org-action-btn" onclick="editAdmin(${kagawad.id})"><i class="fas fa-edit"></i></button>
                        <button class="org-action-btn" onclick="resetAdminPassword(${kagawad.id}, '${escapeHtml(kagawad.username)}', '${escapeHtml(kagawad.email)}', '${escapeHtml(kagawad.full_name)}')"><i class="fas fa-key"></i></button>
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="org-node empty" onclick="showRoleSelection()">
                    <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="org-node-info">
                        <div class="org-node-name">Vacant Slot ${i + 1}</div>
                        <div class="org-node-title">Kagawad</div>
                    </div>
                </div>
            `;
        }
    }
    
    html += `</div></div>`;
    
    // Lupon (Below Kagawad)
    html += `<div class="org-connector"></div>`;
    html += `<div class="org-level org-level-fourth">`;
    
    if (grouped.lupon.length > 0) {
        html += `
            <div class="org-node lupon">
                <div class="org-node-icon" style="background: ${roleColors.lupon}20; color: ${roleColors.lupon};">
                    <i class="${roleIcons.lupon}"></i>
                </div>
                <div class="org-node-info">
                    <div class="org-node-name">${escapeHtml(grouped.lupon[0].full_name || grouped.lupon[0].username)}</div>
                    <div class="org-node-title">${roleNames.lupon}</div>
                    <div class="org-node-status ${grouped.lupon[0].is_active ? 'active' : 'inactive'}">
                        ${grouped.lupon[0].is_active ? 'Active' : 'Inactive'}
                    </div>
                </div>
                <div class="org-node-actions">
                    <button class="org-action-btn" onclick="editAdmin(${grouped.lupon[0].id})"><i class="fas fa-edit"></i></button>
                    <button class="org-action-btn" onclick="resetAdminPassword(${grouped.lupon[0].id}, '${escapeHtml(grouped.lupon[0].username)}', '${escapeHtml(grouped.lupon[0].email)}', '${escapeHtml(grouped.lupon[0].full_name)}')"><i class="fas fa-key"></i></button>
                </div>
            </div>
        `;
    } else {
        html += `
            <div class="org-node empty" onclick="showRoleSelection()">
                <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="org-node-info">
                    <div class="org-node-name">Vacant Position</div>
                    <div class="org-node-title">${roleNames.lupon}</div>
                </div>
            </div>
        `;
    }
    
    html += `</div>`;
    
    html += `
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

// ============ CREATE ADMIN MODAL ============

function createAdminModal() {
    if (document.getElementById('adminModal')) return;
    
    const modalHtml = `
        <div id="adminModal" class="modal">
            <div class="modal-content" style="max-width: 700px; width: 95%;">
                <div class="modal-header">
                    <h2 id="adminModalTitle"><i class="fas fa-users-cog"></i> Manage Official/Staff</h2>
                    <button class="close-modal" onclick="closeAdminModal()">&times;</button>
                </div>
                <div class="modal-body" id="adminModalBody" style="max-height: 70vh; overflow-y: auto;">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
                </div>
                <div class="modal-footer">
                    <button class="btn-close" onclick="closeAdminModal()">Close</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// ============ LOAD ADMINS FROM SERVER ============

async function loadAdmins(page = 1) {
    currentAdminPage = page;
    
    try {
        const response = await fetch(`admin_ajax.php?action=get_admins&page=${page}`);
        const data = await response.json();
        
        if (data.success) {
            currentAdminData = data.admins;
            totalAdminPages = data.totalPages;
            totalAdminsCount = data.totalCount;
            updateRoleCounts();
            renderOrganizationChart();
            renderAdminPagination();
        } else {
            const container = document.getElementById('organizationChart');
            if (container) {
                container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle fa-3x"></i><p>${data.message}</p></div>`;
            }
        }
    } catch (error) {
        console.error('Error loading admins:', error);
        const container = document.getElementById('organizationChart');
        if (container) {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle fa-3x"></i><p>Error loading officials. Please try again.</p></div>`;
        }
    }
}

function renderAdminPagination() {
    const container = document.getElementById('adminPagination');
    if (!container) return;
    
    if (totalAdminPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination-wrapper"><div class="pagination">';
    
    if (currentAdminPage > 1) {
        html += `<button class="page-btn" onclick="loadAdmins(${currentAdminPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
    } else {
        html += `<button class="page-btn disabled" disabled><i class="fas fa-chevron-left"></i> Prev</button>`;
    }
    
    const maxVisible = 5;
    let startPage = Math.max(1, currentAdminPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalAdminPages, startPage + maxVisible - 1);
    
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }
    
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="loadAdmins(1)">1</button>`;
        if (startPage > 2) html += `<span class="page-dots">...</span>`;
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === currentAdminPage ? 'active' : ''}" onclick="loadAdmins(${i})">${i}</button>`;
    }
    
    if (endPage < totalAdminPages) {
        if (endPage < totalAdminPages - 1) html += `<span class="page-dots">...</span>`;
        html += `<button class="page-btn" onclick="loadAdmins(${totalAdminPages})">${totalAdminPages}</button>`;
    }
    
    if (currentAdminPage < totalAdminPages) {
        html += `<button class="page-btn" onclick="loadAdmins(${currentAdminPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
    } else {
        html += `<button class="page-btn disabled" disabled>Next <i class="fas fa-chevron-right"></i></button>`;
    }
    
    html += '</div></div>';
    container.innerHTML = html;
}

// ============ VALIDATION FUNCTIONS ============

function validatePhoneNumber(phone) {
    const phoneRegex = /^[0-9]{10,11}$/;
    return phoneRegex.test(phone);
}

async function checkEmailAvailability(email, excludeId = 0) {
    if (!email) return true;
    
    try {
        const response = await fetch(`admin_ajax.php?action=check_availability&email=${encodeURIComponent(email)}&exclude_id=${excludeId}`);
        const data = await response.json();
        return data.available;
    } catch (error) {
        console.error('Error checking email:', error);
        return true;
    }
}

async function validateEmailField() {
    const email = document.getElementById('adminEmail').value;
    const statusSpan = document.getElementById('emailStatus');
    
    if (!email) {
        statusSpan.innerHTML = '';
        return false;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Invalid email format';
        statusSpan.style.color = 'red';
        return false;
    }
    
    const isAvailable = await checkEmailAvailability(email);
    if (isAvailable) {
        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Email available';
        statusSpan.style.color = 'green';
        return true;
    } else {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Email already exists';
        statusSpan.style.color = 'red';
        return false;
    }
}

function validatePhoneInput(input) {
    input.value = input.value.replace(/\D/g, '');
    validatePhoneNumberField();
}

function validatePhoneNumberField() {
    const phone = document.getElementById('adminPhone').value;
    const statusSpan = document.getElementById('phoneStatus');
    
    if (!phone) {
        statusSpan.innerHTML = 'Optional - Only numbers allowed (10-11 digits)';
        statusSpan.style.color = '#6c757d';
        return true;
    }
    
    if (phone.length === 10 || phone.length === 11) {
        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Valid phone number';
        statusSpan.style.color = 'green';
        return true;
    } else {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Phone number must be 10-11 digits';
        statusSpan.style.color = 'red';
        return false;
    }
}

// ============ SAVE ADMIN ============

async function saveAdmin(event) {
    event.preventDefault();
    
    const full_name = document.getElementById('adminFullName').value.trim();
    const email = document.getElementById('adminEmail').value.trim();
    const phone = document.getElementById('adminPhone').value;
    const suffix = document.getElementById('adminSuffix').value.trim();
    
    // Validate required fields
    if (!full_name) {
        showWarningModal('Please enter full name.', 'Validation Error');
        return;
    }
    if (!email) {
        showWarningModal('Please enter email address.', 'Validation Error');
        return;
    }
    
    // Validate email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showWarningModal('Please enter a valid email address.', 'Validation Error');
        return;
    }
    
    // Validate email availability
    const isEmailAvailable = await checkEmailAvailability(email);
    if (!isEmailAvailable) {
        showWarningModal('Email already exists. Please use a different email address.', 'Validation Error');
        return;
    }
    
    // Validate phone number
    if (phone && !validatePhoneNumber(phone)) {
        showWarningModal('Phone number must contain 10-11 digits only.', 'Validation Error');
        return;
    }
    
    // Check role limit again before saving
    if (ROLE_LIMITS[selectedRole] && ROLE_LIMITS[selectedRole].current >= ROLE_LIMITS[selectedRole].max) {
        showWarningModal(`${ROLE_LIMITS[selectedRole].name} position is already full. Maximum ${ROLE_LIMITS[selectedRole].max} ${ROLE_LIMITS[selectedRole].max > 1 ? 'slots' : 'slot'} available.`, 'Limit Reached');
        closeAdminModal();
        showRoleSelection();
        return;
    }
    
    const roleInfo = ROLE_LIMITS[selectedRole];
    
    // Show confirmation
    if (typeof showConfirmationModal !== 'undefined') {
        showConfirmationModal(
            `Create new account for ${full_name} as ${roleInfo.name}?\n\nCredentials will be sent to ${email}`,
            'Confirm Create Account',
            async () => {
                await performSaveAdmin();
            }
        );
    } else {
        if (confirm(`Create new account for ${full_name} as ${roleInfo.name}?`)) {
            await performSaveAdmin();
        }
    }
    
    async function performSaveAdmin() {
        const formData = new FormData();
        formData.append('action', 'add_admin');
        formData.append('full_name', full_name);
        formData.append('email', email);
        formData.append('suffix', suffix);
        formData.append('admin_role', selectedRole);
        formData.append('phone_number', phone);
        formData.append('birth_date', document.getElementById('adminBirthDate').value);
        formData.append('birth_place', document.getElementById('adminBirthPlace').value);
        formData.append('gender', document.getElementById('adminGender').value);
        formData.append('civil_status', document.getElementById('adminCivilStatus').value);
        formData.append('religion', document.getElementById('adminReligion').value);
        formData.append('occupation', document.getElementById('adminOccupation').value);
        formData.append('is_active', document.getElementById('adminIsActive').value);
        
        try {
            const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                let message = 'Account created successfully!';
                if (data.email_sent) {
                    message += `\n\nCredentials have been sent to ${email}`;
                } else {
                    message += `\n\nWarning: Email could not be sent. Please contact the user directly.`;
                }
                
                if (typeof showSuccessModal !== 'undefined') {
                    showSuccessModal(message, 'Account Created');
                } else {
                    alert(message);
                }
                
                closeAdminModal();
                await loadAdmins(currentAdminPage);
            } else {
                if (typeof showErrorModal !== 'undefined') {
                    showErrorModal(data.message || 'Failed to create account', 'Error');
                } else {
                    alert('Error: ' + (data.message || 'Failed to create account'));
                }
            }
        } catch (error) {
            console.error('Save error:', error);
            if (typeof showErrorModal !== 'undefined') {
                showErrorModal('An error occurred. Please try again.', 'Error');
            } else {
                alert('An error occurred. Please try again.');
            }
        }
    }
}

// ============ EDIT, UPDATE, DELETE FUNCTIONS ============

async function editAdmin(id) {
    createAdminModal();
    
    const modal = document.getElementById('adminModal');
    const modalBody = document.getElementById('adminModalBody');
    const modalTitle = document.getElementById('adminModalTitle');
    
    modalTitle.innerHTML = '<i class="fas fa-user-edit"></i> Edit Official/Staff';
    modalBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>';
    modal.style.display = 'block';
    
    try {
        const response = await fetch(`admin_ajax.php?action=get_admin&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const admin = data.admin;
            const roleInfo = ROLE_LIMITS[admin.admin_role] || { name: 'Staff', icon: 'fas fa-user' };
            
            modalTitle.innerHTML = `<i class="${roleInfo.icon}"></i> Edit ${roleInfo.name}`;
            
            modalBody.innerHTML = `
                <form id="adminForm" onsubmit="updateAdmin(event, ${admin.id})">
                    <div class="form-group">
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" id="adminFullName" class="form-control" value="${escapeHtml(admin.full_name || '')}" required>
                        <small class="form-hint">Format: LAST NAME, FIRST NAME MI. (e.g., DELA CRUZ, JUAN M.)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" id="adminSuffix" class="form-control" value="${escapeHtml(admin.suffix || '')}" placeholder="Jr., Sr., III">
                    </div>
                    
                    <div class="form-group">
                        <label>Email <span style="color:red;">*</span></label>
                        <input type="email" id="adminEmail" class="form-control" value="${escapeHtml(admin.email)}" required onblur="validateEmailFieldEdit(${admin.id})">
                        <small id="emailStatus" class="form-hint"></small>
                    </div>
                    
                    <div class="form-group">
                        <label>New Password (leave blank to keep current)</label>
                        <input type="password" id="adminPassword" class="form-control" placeholder="Enter new password only if you want to change it">
                        <small class="form-hint">Leave blank to keep current password. If changed, new password will be sent via email.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" id="adminPhone" class="form-control" value="${escapeHtml(admin.phone_number || '')}" onkeyup="validatePhoneInput(this)" onblur="validatePhoneNumberField()">
                        <small id="phoneStatus" class="form-hint">Only numbers allowed (10-11 digits)</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Birth Date</label>
                            <input type="date" id="adminBirthDate" class="form-control" value="${admin.birth_date || ''}">
                        </div>
                        <div class="form-group">
                            <label>Birth Place</label>
                            <input type="text" id="adminBirthPlace" class="form-control" value="${escapeHtml(admin.birth_place || '')}">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Gender</label>
                            <select id="adminGender" class="form-control">
                                <option value="">Select</option>
                                <option value="male" ${admin.gender === 'male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${admin.gender === 'female' ? 'selected' : ''}>Female</option>
                                <option value="other" ${admin.gender === 'other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Civil Status</label>
                            <select id="adminCivilStatus" class="form-control">
                                <option value="">Select</option>
                                <option value="single" ${admin.civil_status === 'single' ? 'selected' : ''}>Single</option>
                                <option value="married" ${admin.civil_status === 'married' ? 'selected' : ''}>Married</option>
                                <option value="widowed" ${admin.civil_status === 'widowed' ? 'selected' : ''}>Widowed</option>
                                <option value="divorced" ${admin.civil_status === 'divorced' ? 'selected' : ''}>Divorced</option>
                                <option value="separated" ${admin.civil_status === 'separated' ? 'selected' : ''}>Separated</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Religion</label>
                            <input type="text" id="adminReligion" class="form-control" value="${escapeHtml(admin.religion || '')}">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Occupation</label>
                        <input type="text" id="adminOccupation" class="form-control" value="${escapeHtml(admin.occupation || '')}">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select id="adminIsActive" class="form-control">
                            <option value="1" ${admin.is_active == 1 ? 'selected' : ''}>Active</option>
                            <option value="0" ${admin.is_active == 0 ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update Account</button>
                        <button type="button" class="btn-close" onclick="closeAdminModal()">Cancel</button>
                    </div>
                </form>
            `;
        } else {
            modalBody.innerHTML = `<div class="empty-state"><p>${data.message}</p></div>`;
        }
    } catch (error) {
        console.error('Edit error:', error);
        modalBody.innerHTML = `<div class="empty-state"><p>Error loading admin data.</p></div>`;
    }
}

async function validateEmailFieldEdit(excludeId) {
    const email = document.getElementById('adminEmail').value;
    const statusSpan = document.getElementById('emailStatus');
    
    if (!email) {
        statusSpan.innerHTML = '';
        return false;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Invalid email format';
        statusSpan.style.color = 'red';
        return false;
    }
    
    const isAvailable = await checkEmailAvailability(email, excludeId);
    if (isAvailable) {
        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Email available';
        statusSpan.style.color = 'green';
        return true;
    } else {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Email already exists';
        statusSpan.style.color = 'red';
        return false;
    }
}

async function updateAdmin(event, id) {
    event.preventDefault();
    
    const password = document.getElementById('adminPassword').value;
    const email = document.getElementById('adminEmail').value;
    
    // Validate email if changed
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showWarningModal('Please enter a valid email address.', 'Validation Error');
        return;
    }
    
    const isEmailAvailable = await checkEmailAvailability(email, id);
    if (!isEmailAvailable) {
        showWarningModal('Email already exists. Please use a different email address.', 'Validation Error');
        return;
    }
    
    // Validate phone number
    const phone = document.getElementById('adminPhone').value;
    if (phone && !validatePhoneNumber(phone)) {
        showWarningModal('Phone number must contain 10-11 digits only.', 'Validation Error');
        return;
    }
    
    const full_name = document.getElementById('adminFullName').value;
    
    if (typeof showConfirmationModal !== 'undefined') {
        showConfirmationModal(
            `Update account for ${full_name}?`,
            'Confirm Update',
            async () => {
                await performUpdateAdmin(id, password);
            }
        );
    } else {
        if (confirm(`Update account for ${full_name}?`)) {
            await performUpdateAdmin(id, password);
        }
    }
    
    async function performUpdateAdmin(id, password) {
        const formData = new FormData();
        formData.append('action', 'update_admin');
        formData.append('id', id);
        formData.append('full_name', document.getElementById('adminFullName').value);
        formData.append('email', document.getElementById('adminEmail').value);
        formData.append('suffix', document.getElementById('adminSuffix').value);
        formData.append('admin_role', document.getElementById('adminRole')?.value || '');
        formData.append('phone_number', document.getElementById('adminPhone').value);
        formData.append('birth_date', document.getElementById('adminBirthDate').value);
        formData.append('birth_place', document.getElementById('adminBirthPlace').value);
        formData.append('gender', document.getElementById('adminGender').value);
        formData.append('civil_status', document.getElementById('adminCivilStatus').value);
        formData.append('religion', document.getElementById('adminReligion').value);
        formData.append('occupation', document.getElementById('adminOccupation').value);
        formData.append('is_active', document.getElementById('adminIsActive').value);
        
        if (password) {
            formData.append('password', password);
        }
        
        try {
            const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                let message = 'Account updated successfully!';
                if (password) {
                    message += '\n\nNew password has been sent to the user\'s email.';
                }
                
                if (typeof showSuccessModal !== 'undefined') {
                    showSuccessModal(message, 'Account Updated');
                } else {
                    alert(message);
                }
                closeAdminModal();
                await loadAdmins(currentAdminPage);
            } else {
                if (typeof showErrorModal !== 'undefined') {
                    showErrorModal(data.message || 'Failed to update account', 'Error');
                } else {
                    alert('Error: ' + (data.message || 'Failed to update account'));
                }
            }
        } catch (error) {
            console.error('Update error:', error);
            if (typeof showErrorModal !== 'undefined') {
                showErrorModal('An error occurred. Please try again.', 'Error');
            } else {
                alert('An error occurred. Please try again.');
            }
        }
    }
}

async function resetAdminPassword(id, username, email, full_name) {
    if (confirm(`Reset password for "${username}"?\n\nA new strong password will be generated and sent to ${email}`)) {
        const formData = new FormData();
        formData.append('action', 'reset_admin_password');
        formData.append('id', id);
        
        try {
            const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                let message = `Password for "${username}" has been reset successfully!`;
                if (data.email_sent) {
                    message += `\n\nNew password has been sent to ${email}`;
                } else {
                    message += `\n\nWarning: Email could not be sent. Please contact the user directly.`;
                }
                
                if (typeof showSuccessModal !== 'undefined') {
                    showSuccessModal(message, 'Password Reset');
                } else {
                    alert(message);
                }
            } else {
                if (typeof showErrorModal !== 'undefined') {
                    showErrorModal(data.message || 'Failed to reset password', 'Error');
                } else {
                    alert('Error: ' + (data.message || 'Failed to reset password'));
                }
            }
        } catch (error) {
            console.error('Reset error:', error);
            if (typeof showErrorModal !== 'undefined') {
                showErrorModal('An error occurred. Please try again.', 'Error');
            } else {
                alert('An error occurred. Please try again.');
            }
        }
    }
}

function toggleAdminStatus(id, currentStatus) {
    const action = currentStatus ? 'deactivate' : 'activate';
    const message = currentStatus ? 
        'Deactivate this account? The user will not be able to login.' : 
        'Activate this account? The user will be able to login.';
    
    if (confirm(message)) {
        performToggleStatus(id, currentStatus);
    }
    
    async function performToggleStatus(id, currentStatus) {
        const formData = new FormData();
        formData.append('action', 'toggle_admin_status');
        formData.append('id', id);
        formData.append('status', currentStatus ? 0 : 1);
        
        try {
            const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                alert(`Account ${currentStatus ? 'deactivated' : 'activated'} successfully!`);
                await loadAdmins(currentAdminPage);
            } else {
                alert('Error: ' + (data.message || 'Failed to update status'));
            }
        } catch (error) {
            console.error('Toggle error:', error);
            alert('An error occurred. Please try again.');
        }
    }
}

function deleteAdmin(id, name) {
    if (confirm(`Delete "${name}"? This action cannot be undone.`)) {
        performDeleteAdmin(id);
    }
    
    async function performDeleteAdmin(id) {
        const formData = new FormData();
        formData.append('action', 'delete_admin');
        formData.append('id', id);
        
        try {
            const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                alert('Account deleted successfully!');
                await loadAdmins(currentAdminPage);
            } else {
                alert('Error: ' + (data.message || 'Failed to delete account'));
            }
        } catch (error) {
            console.error('Delete error:', error);
            alert('An error occurred. Please try again.');
        }
    }
}

function closeAdminModal() {
    const modal = document.getElementById('adminModal');
    if (modal) modal.style.display = 'none';
    selectedRole = null;
}

function getRoleDisplayName(role) {
    return ROLE_LIMITS[role]?.name || role || 'Staff';
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}