// admin_management.js
// Admin/Official Accounts Management Module

// ============ REAL-TIME VALIDATION SYSTEM ============

const VALIDATION_RULES = {
    fullName: {
        required: true,
        minLength: 2,
        maxLength: 100,
        pattern: /^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s,.\-']+$/,
        messages: {
            required: 'Full name is required',
            minLength: 'Full name must be at least 2 characters',
            maxLength: 'Full name must not exceed 100 characters',
            pattern: 'Full name can only contain letters, spaces, commas, periods, and hyphens'
        }
    },
    email: {
        required: true,
        pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        maxLength: 100,
        messages: {
            required: 'Email address is required',
            pattern: 'Please enter a valid email address (e.g., name@example.com)',
            maxLength: 'Email must not exceed 100 characters'
        }
    },
    phone: {
        required: false,
        pattern: /^[0-9]{10,11}$/,
        messages: {
            pattern: 'Phone number must be 10-11 digits (e.g., 09171234567)'
        }
    },
    suffix: {
        required: false,
        maxLength: 10,
        pattern: /^[a-zA-Z.]*$/,
        messages: {
            maxLength: 'Suffix must not exceed 10 characters',
            pattern: 'Suffix can only contain letters and periods'
        }
    },
    committee: {
        required: false,
        maxLength: 100,
        messages: { maxLength: 'Committee must not exceed 100 characters' }
    },
    birthPlace: {
        required: false,
        maxLength: 100,
        messages: { maxLength: 'Birth place must not exceed 100 characters' }
    },
    religion: {
        required: false,
        maxLength: 50,
        messages: { maxLength: 'Religion must not exceed 50 characters' }
    },
    occupation: {
        required: false,
        maxLength: 100,
        messages: { maxLength: 'Occupation must not exceed 100 characters' }
    }
};

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function getOrCreateValidationMessage(input) {
    let messageEl = input.parentElement.querySelector('.validation-message');
    if (!messageEl) {
        messageEl = document.createElement('div');
        messageEl.className = 'validation-message';
        input.parentElement.appendChild(messageEl);
    }
    return messageEl;
}

function showValidationError(input, message) {
    input.classList.add('invalid-field');
    input.classList.remove('valid-field');
    const messageEl = getOrCreateValidationMessage(input);
    messageEl.className = 'validation-message error';
    messageEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    messageEl.style.display = 'block';
}

function showValidationSuccess(input, message = '') {
    input.classList.remove('invalid-field');
    input.classList.add('valid-field');
    const messageEl = getOrCreateValidationMessage(input);
    if (message) {
        messageEl.className = 'validation-message success';
        messageEl.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
        messageEl.style.display = 'block';
    } else {
        messageEl.style.display = 'none';
    }
}

function clearValidation(input) {
    input.classList.remove('invalid-field', 'valid-field');
    const messageEl = input.parentElement.querySelector('.validation-message');
    if (messageEl) {
        messageEl.style.display = 'none';
        messageEl.innerHTML = '';
    }
}

function validateField(input, ruleName) {
    const rule = VALIDATION_RULES[ruleName];
    if (!rule) return true;
    
    const value = input.value.trim();
    
    if (rule.required && !value) {
        showValidationError(input, rule.messages.required);
        return false;
    }
    
    if (!value && !rule.required) {
        showValidationSuccess(input);
        return true;
    }
    
    if (rule.minLength && value.length < rule.minLength) {
        showValidationError(input, rule.messages.minLength);
        return false;
    }
    
    if (rule.maxLength && value.length > rule.maxLength) {
        showValidationError(input, rule.messages.maxLength);
        return false;
    }
    
    if (rule.pattern && !rule.pattern.test(value)) {
        showValidationError(input, rule.messages.pattern);
        return false;
    }
    
    showValidationSuccess(input);
    return true;
}

const checkEmailAvailabilityDebounced = debounce(async function(input, excludeId = 0) {
    const email = input.value.trim();
    if (!email) return;
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) return;
    
    input.classList.add('validating');
    
    try {
        const response = await fetch(`admin_ajax.php?action=check_availability&email=${encodeURIComponent(email)}&exclude_id=${excludeId}`);
        const data = await response.json();
        
        input.classList.remove('validating');
        
        if (data.available) {
            showValidationSuccess(input, 'Email is available');
        } else {
            showValidationError(input, 'This email is already registered');
        }
    } catch (error) {
        console.error('Error checking email:', error);
        input.classList.remove('validating');
        clearValidation(input);
    }
}, 500);

function formatPhoneInput(input) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length > 11) {
        input.value = input.value.substring(0, 11);
    }
}

function attachRealTimeValidation(input, ruleName, options = {}) {
    if (!input) return;
    
    const actualInput = input;
    
    actualInput.addEventListener('input', function() {
        if (ruleName === 'phone') {
            formatPhoneInput(this);
        }
        
        validateField(this, ruleName);
        
        if (ruleName === 'email' && options.checkAvailability) {
            checkEmailAvailabilityDebounced(this, options.excludeId || 0);
        }
    });
    
    actualInput.addEventListener('blur', function() {
        if (this.value.trim()) {
            validateField(this, ruleName);
        }
    });
    
    return actualInput;
}

function validateFormFields(fieldMappings) {
    let isValid = true;
    
    for (const [fieldId, ruleName] of Object.entries(fieldMappings)) {
        const input = document.getElementById(fieldId);
        if (input) {
            const fieldValid = validateField(input, ruleName);
            if (!fieldValid) isValid = false;
        }
    }
    
    return isValid;
}

function focusFirstInvalidField() {
    const firstInvalid = document.querySelector('.invalid-field');
    if (firstInvalid) {
        firstInvalid.focus();
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
let currentAdminData = [];
let currentAdminPage = 1;
let totalAdminPages = 1;
let totalAdminsCount = 0;
let selectedRole = null;

// Role limits for Barangay Officials (only Captain and Secretary can have accounts)
const ROLE_LIMITS = {
    'captain': { max: 1, current: 0, name: 'Barangay Captain', icon: 'fas fa-user-tie', color: '#8B0000' },
    'secretary': { max: 1, current: 0, name: 'Secretary', icon: 'fas fa-file-alt', color: '#2E7D32' }
};

// Kagawad positions (display only, no account creation)
const KAGAWAD_POSITIONS = {
    max: 7,
    current: 0,
    name: 'Kagawad',
    icon: 'fas fa-users',
    color: '#1565C0'
};

// Lupon role limits (separate management) - 10 to 20 members
const LUPON_ROLE_LIMITS = {
    'lupon': { min: 10, max: 20, current: 0, name: 'Lupon Member', icon: 'fas fa-gavel', color: '#E65100' }
};

// Roles to display in Barangay Officials chart
const DISPLAY_ROLES = ['captain', 'secretary'];
// Kagawad members data (loaded from database)
let kagawadMembersData = [];

// ============ RENDER ADMIN/OFFICIAL MANAGEMENT INTERFACE ============

function renderAdminManagement() {
    return `
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-users-cog"></i> Barangay Officials
            </div>
            <div class="section-sub">
                Click on any <strong>vacant slot</strong> to add a new official
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
    KAGAWAD_POSITIONS.current = kagawadMembersData.length;
    
    // Count current admins (captain and secretary only)
    currentAdminData.forEach(admin => {
        if (ROLE_LIMITS[admin.admin_role]) {
            ROLE_LIMITS[admin.admin_role].current++;
        }
    });
    
    // Update lupon counts
    for (const key in LUPON_ROLE_LIMITS) {
        LUPON_ROLE_LIMITS[key].current = 0;
    }
    currentAdminData.forEach(admin => {
        if (LUPON_ROLE_LIMITS[admin.admin_role]) {
            LUPON_ROLE_LIMITS[admin.admin_role].current++;
        }
    });
    
    // Update the lupon member count display
    const countEl = document.getElementById('luponMemberCount');
    if (countEl) {
        countEl.textContent = LUPON_ROLE_LIMITS['lupon']?.current || 0;
    }
}

function selectRole(role) {
    selectedRole = role;
    closeRoleModal();
    showAddAdminForm(role);
}


// ============ KAGAWAD MANAGEMENT (Display Only - No Account) ============
function showAddKagawadModal() {
    createKagawadModal();
    
    const modal = document.getElementById('kagawadModal');
    const modalBody = document.getElementById('kagawadModalBody');
    const modalTitle = document.getElementById('kagawadModalTitle');
    
    modalTitle.innerHTML = '<i class="fas fa-user-plus"></i> Add Kagawad';
    
    modalBody.innerHTML = `
        <form id="kagawadForm" enctype="multipart/form-data" onsubmit="saveKagawad(event)">
            <div class="form-group">
                <label>Profile Picture / ID</label>
                <div id="kagawadImageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('kagawadImage').click()">
                    <div id="kagawadImagePreviewContainer" style="display: none;">
                        <img id="kagawadPreviewImg" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                        <p style="font-size: 12px; color: #999; margin: 0;"><i class="fas fa-sync-alt"></i> Click to change</p>
                    </div>
                    <div id="kagawadImagePlaceholder">
                        <i class="fas fa-camera" style="font-size: 48px; color: #1565C0;"></i>
                        <p style="margin-top: 10px; color: #666;">Click to upload picture/ID</p>
                        <p style="font-size: 12px; color: #999;">JPG, PNG (Max 5MB)</p>
                    </div>
                </div>
                <input type="file" id="kagawadImage" accept="image/jpeg,image/png,image/jpg" style="display: none;" onchange="previewKagawadImage(this)">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name <span style="color:red;">*</span></label>
                    <input type="text" id="kagawadFullName" class="form-control" required placeholder="e.g., DELA CRUZ, JUAN M.">
                </div>
                <div class="form-group">
                    <label>Suffix</label>
                    <input type="text" id="kagawadSuffix" class="form-control" placeholder="Jr., Sr., III">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Committee/Position</label>
                    <input type="text" id="kagawadCommittee" class="form-control" placeholder="e.g., Committee on Health">
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="tel" id="kagawadContact" class="form-control" placeholder="09XXXXXXXXX">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="kagawadEmail" class="form-control" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>Birth Date</label>
                    <input type="date" id="kagawadBirthDate" class="form-control">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Birth Place</label>
                    <input type="text" id="kagawadBirthPlace" class="form-control" placeholder="City/Municipality">
                </div>
                <div class="form-group">
                    <label>Sex / Gender</label>
                    <select id="kagawadGender" class="form-control">
                        <option value="">Select</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Civil Status</label>
                    <select id="kagawadCivilStatus" class="form-control">
                        <option value="">Select</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Divorced">Divorced</option>
                        <option value="Separated">Separated</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Religion</label>
                    <input type="text" id="kagawadReligion" class="form-control" placeholder="e.g., Roman Catholic">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Occupation</label>
                    <input type="text" id="kagawadOccupation" class="form-control" placeholder="e.g., Farmer, Teacher">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="kagawadIsActive" class="form-control">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="submit" class="btn-verify" style="background: #1565C0;"><i class="fas fa-save"></i> Add Kagawad</button>
                <button type="button" class="btn-close" onclick="closeKagawadModal()">Cancel</button>
            </div>
        </form>
    `;
    
        modal.style.display = 'block';
    
    setTimeout(() => {
        attachRealTimeValidation(document.getElementById('kagawadFullName'), 'fullName');
        attachRealTimeValidation(document.getElementById('kagawadSuffix'), 'suffix');
        attachRealTimeValidation(document.getElementById('kagawadCommittee'), 'committee');
        attachRealTimeValidation(document.getElementById('kagawadContact'), 'phone');
        attachRealTimeValidation(document.getElementById('kagawadEmail'), 'email');
        attachRealTimeValidation(document.getElementById('kagawadBirthPlace'), 'birthPlace');
        attachRealTimeValidation(document.getElementById('kagawadReligion'), 'religion');
        attachRealTimeValidation(document.getElementById('kagawadOccupation'), 'occupation');
    }, 100);
}

function previewKagawadImage(input) {
    const previewContainer = document.getElementById('kagawadImagePreviewContainer');
    const placeholder = document.getElementById('kagawadImagePlaceholder');
    const previewImg = document.getElementById('kagawadPreviewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'block';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
async function saveKagawad(event) {
    event.preventDefault();
    
    const fullName = document.getElementById('kagawadFullName').value.trim();
    
    if (!fullName) {
        showWarningModal('Please enter full name.', 'Validation Error');
        return;
    }
    
    if (kagawadMembersData.length >= 7) {
        showWarningModal('Maximum of 7 Kagawad positions already filled.', 'Limit Reached');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_kagawad');
    formData.append('full_name', fullName);
    formData.append('suffix', document.getElementById('kagawadSuffix')?.value || '');
    formData.append('committee', document.getElementById('kagawadCommittee').value.trim());
    formData.append('contact_number', document.getElementById('kagawadContact').value.trim());
    formData.append('email', document.getElementById('kagawadEmail')?.value.trim() || '');
    formData.append('birth_date', document.getElementById('kagawadBirthDate')?.value || '');
    formData.append('birth_place', document.getElementById('kagawadBirthPlace')?.value.trim() || '');
    formData.append('gender', document.getElementById('kagawadGender')?.value || '');
    formData.append('civil_status', document.getElementById('kagawadCivilStatus')?.value || '');
    formData.append('religion', document.getElementById('kagawadReligion')?.value.trim() || '');
    formData.append('occupation', document.getElementById('kagawadOccupation')?.value.trim() || '');
    formData.append('is_active', document.getElementById('kagawadIsActive')?.value || '1');
    
    const imageFile = document.getElementById('kagawadImage').files[0];
    if (imageFile) {
        formData.append('kagawad_image', imageFile);
    }
    
    try {
        const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            showGlobalNotificationModal('success', 'Kagawad Added', `Kagawad "${fullName}" has been added successfully!`);
            closeKagawadModal();
            await loadKagawadMembers();
            renderOrganizationChart();
        } else {
            showGlobalNotificationModal('error', 'Error', data.message || 'Failed to add Kagawad');
        }
    } catch (error) {
        console.error('Error:', error);
        showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
    }
}

async function editKagawad(id) {
    createKagawadModal();
    
    const modal = document.getElementById('kagawadModal');
    const modalBody = document.getElementById('kagawadModalBody');
    const modalTitle = document.getElementById('kagawadModalTitle');
    
    modalTitle.innerHTML = '<i class="fas fa-user-edit"></i> Edit Kagawad';
    modalBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>';
    modal.style.display = 'block';
    
    try {
        const response = await fetch(`admin_ajax.php?action=get_kagawad&id=${id}`);
        const data = await response.json();
        
        if (!data.success) {
            modalBody.innerHTML = `<div class="empty-state"><p>${data.message || 'Kagawad not found'}</p></div>`;
            return;
        }
        
        const kagawad = data.kagawad;
        const hasImage = kagawad.profile_image;
        
        const formatDateForInput = (dateStr) => {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return '';
            return date.toISOString().split('T')[0];
        };
        
        modalBody.innerHTML = `
            <form id="kagawadForm" enctype="multipart/form-data" onsubmit="updateKagawad(event, ${id})">
                <div class="form-group">
                    <label>Profile Picture / ID</label>
                    ${hasImage ? `
                        <div style="margin-bottom: 15px; text-align: center;">
                            <img src="../${kagawad.profile_image}" style="max-width: 120px; max-height: 120px; border-radius: 50%; border: 3px solid #1565C0; object-fit: cover;">
                            <br>
                            <label style="font-size: 12px; color: #dc3545; cursor: pointer;">
                                <input type="checkbox" id="removeKagawadImage"> Remove current image
                            </label>
                        </div>
                    ` : ''}
                    <div id="kagawadImageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('kagawadImage').click()">
                        <div id="kagawadImagePreviewContainer" style="display: none;">
                            <img id="kagawadPreviewImg" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                        </div>
                        <div id="kagawadImagePlaceholder">
                            <i class="fas fa-camera" style="font-size: 36px; color: #1565C0;"></i>
                            <p style="margin-top: 10px; color: #666;">Click to ${hasImage ? 'change' : 'upload'} picture/ID</p>
                            <p style="font-size: 12px; color: #999;">JPG, PNG (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="kagawadImage" accept="image/jpeg,image/png,image/jpg" style="display: none;" onchange="previewKagawadImage(this)">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" id="kagawadFullName" class="form-control" required value="${escapeHtml(kagawad.full_name || '')}">
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" id="kagawadSuffix" class="form-control" value="${escapeHtml(kagawad.suffix || '')}" placeholder="Jr., Sr., III">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Committee/Position</label>
                        <input type="text" id="kagawadCommittee" class="form-control" value="${escapeHtml(kagawad.committee || '')}" placeholder="e.g., Committee on Health">
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" id="kagawadContact" class="form-control" value="${escapeHtml(kagawad.contact_number || '')}" placeholder="09XXXXXXXXX">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="kagawadEmail" class="form-control" value="${escapeHtml(kagawad.email || '')}" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" id="kagawadBirthDate" class="form-control" value="${formatDateForInput(kagawad.birth_date)}">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Birth Place</label>
                        <input type="text" id="kagawadBirthPlace" class="form-control" value="${escapeHtml(kagawad.birth_place || '')}" placeholder="City/Municipality">
                    </div>
                    <div class="form-group">
                        <label>Sex / Gender</label>
                        <select id="kagawadGender" class="form-control">
                            <option value="">Select</option>
                            <option value="Male" ${kagawad.gender === 'Male' || kagawad.gender === 'male' ? 'selected' : ''}>Male</option>
                            <option value="Female" ${kagawad.gender === 'Female' || kagawad.gender === 'female' ? 'selected' : ''}>Female</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Civil Status</label>
                        <select id="kagawadCivilStatus" class="form-control">
                            <option value="">Select</option>
                            <option value="Single" ${kagawad.civil_status === 'Single' || kagawad.civil_status === 'single' ? 'selected' : ''}>Single</option>
                            <option value="Married" ${kagawad.civil_status === 'Married' || kagawad.civil_status === 'married' ? 'selected' : ''}>Married</option>
                            <option value="Widowed" ${kagawad.civil_status === 'Widowed' || kagawad.civil_status === 'widowed' ? 'selected' : ''}>Widowed</option>
                            <option value="Divorced" ${kagawad.civil_status === 'Divorced' || kagawad.civil_status === 'divorced' ? 'selected' : ''}>Divorced</option>
                            <option value="Separated" ${kagawad.civil_status === 'Separated' || kagawad.civil_status === 'separated' ? 'selected' : ''}>Separated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Religion</label>
                        <input type="text" id="kagawadReligion" class="form-control" value="${escapeHtml(kagawad.religion || '')}" placeholder="e.g., Roman Catholic">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Occupation</label>
                        <input type="text" id="kagawadOccupation" class="form-control" value="${escapeHtml(kagawad.occupation || '')}" placeholder="e.g., Farmer, Teacher">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="kagawadIsActive" class="form-control">
                            <option value="1" ${kagawad.is_active == 1 ? 'selected' : ''}>Active</option>
                            <option value="0" ${kagawad.is_active == 0 ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" class="btn-verify" style="background: #1565C0;"><i class="fas fa-save"></i> Update Kagawad</button>
                    <button type="button" class="btn-close" onclick="closeKagawadModal()">Cancel</button>
                </div>
            </form>
        `;
                // At the end of the try block, after setting innerHTML:
        setTimeout(() => {
            attachRealTimeValidation(document.getElementById('kagawadFullName'), 'fullName');
            attachRealTimeValidation(document.getElementById('kagawadSuffix'), 'suffix');
            attachRealTimeValidation(document.getElementById('kagawadCommittee'), 'committee');
            attachRealTimeValidation(document.getElementById('kagawadContact'), 'phone');
            attachRealTimeValidation(document.getElementById('kagawadEmail'), 'email');
            attachRealTimeValidation(document.getElementById('kagawadBirthPlace'), 'birthPlace');
            attachRealTimeValidation(document.getElementById('kagawadReligion'), 'religion');
            attachRealTimeValidation(document.getElementById('kagawadOccupation'), 'occupation');
        }, 100);
    } catch (error) {
        console.error('Edit Kagawad error:', error);
        modalBody.innerHTML = `<div class="empty-state"><p>Error loading Kagawad data.</p></div>`;
    }
}

async function updateKagawad(event, id) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'update_kagawad');
    formData.append('id', id);
    formData.append('full_name', document.getElementById('kagawadFullName').value.trim());
    formData.append('suffix', document.getElementById('kagawadSuffix')?.value || '');
    formData.append('committee', document.getElementById('kagawadCommittee').value.trim());
    formData.append('contact_number', document.getElementById('kagawadContact').value.trim());
    formData.append('email', document.getElementById('kagawadEmail')?.value.trim() || '');
    formData.append('birth_date', document.getElementById('kagawadBirthDate')?.value || '');
    formData.append('birth_place', document.getElementById('kagawadBirthPlace')?.value.trim() || '');
    formData.append('gender', document.getElementById('kagawadGender')?.value || '');
    formData.append('civil_status', document.getElementById('kagawadCivilStatus')?.value || '');
    formData.append('religion', document.getElementById('kagawadReligion')?.value.trim() || '');
    formData.append('occupation', document.getElementById('kagawadOccupation')?.value.trim() || '');
    formData.append('is_active', document.getElementById('kagawadIsActive')?.value || '1');
    
    const removeImage = document.getElementById('removeKagawadImage');
    if (removeImage && removeImage.checked) {
        formData.append('remove_image', 'true');
    }
    
    const imageFile = document.getElementById('kagawadImage').files[0];
    if (imageFile) {
        formData.append('kagawad_image', imageFile);
    }
    
    try {
        const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            showGlobalNotificationModal('success', 'Kagawad Updated', 'Kagawad information has been updated successfully!');
            closeKagawadModal();
            await loadKagawadMembers();
            renderOrganizationChart();
        } else {
            showGlobalNotificationModal('error', 'Error', data.message || 'Failed to update Kagawad');
        }
    } catch (error) {
        console.error('Error:', error);
        showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
    }
}
function deleteAdmin(id, name) {
    showGlobalConfirmModal(
        'Delete Account',
        `Are you sure you want to DELETE "${name}"?\n\nThis action CANNOT be undone.\nAll data associated with this account will be permanently removed.`,
        async () => {
            const formData = new FormData();
            formData.append('action', 'delete_admin');
            formData.append('id', id);

            try {
                const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showGlobalNotificationModal(
                        'success',
                        'Account Deleted',
                        `"${name}" has been deleted successfully.`
                    );
                    await loadAdmins(currentAdminPage);
                    if (document.getElementById('luponOrganizationChart')) {
                        await loadLuponMembers(1);
                    }
                } else {
                    showGlobalNotificationModal('error', 'Error', data.message || 'Failed to delete account');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
            }
        },
        null,
        'danger'
    );
}

function createKagawadModal() {
    if (document.getElementById('kagawadModal')) return;
    
    const modalHtml = `
        <div id="kagawadModal" class="modal">
            <div class="modal-content" style="max-width: 550px; width: 95%;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1565C0, #42A5F5);">
                    <h2 id="kagawadModalTitle"><i class="fas fa-user-plus"></i> Add Kagawad</h2>
                    <button class="close-modal" onclick="closeKagawadModal()">&times;</button>
                </div>
                <div class="modal-body" id="kagawadModalBody" style="max-height: 70vh; overflow-y: auto;">
                    <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeKagawadModal() {
    const modal = document.getElementById('kagawadModal');
    if (modal) modal.style.display = 'none';
}

function renderLuponManagement() {
    return `
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-gavel"></i> Lupon Tagapamayapa
            </div>
            <div class="section-sub">
                Click on any <strong>vacant slot</strong> to add a new Lupon member
            </div>
            
            <div style="margin-bottom: 20px;">
                <span id="luponCountBadge" style="background: #fff3e0; color: #e65100; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                    <i class="fas fa-users"></i> <span id="luponMemberCount">0</span> / 20 Members
                </span>
            </div>
            
            <!-- Organization Chart ONLY -->
            <div id="luponOrganizationChart">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading Lupon organization chart...</p></div>
            </div>
        </div>
    `;
}
async function loadLuponMembers(page = 1) {
    try {
        const response = await fetch(`admin_ajax.php?action=get_admins&page=${page}`);
        const data = await response.json();
        
        if (data.success) {
            // Filter lupon members
            const luponMembers = data.admins.filter(admin => admin.admin_role === 'lupon');
            
            // Get captain and secretary for the org chart
            const captain = data.admins.find(admin => admin.admin_role === 'captain');
            const secretary = data.admins.find(admin => admin.admin_role === 'secretary');
            
            // Update member count
            const countEl = document.getElementById('luponMemberCount');
            if (countEl) countEl.textContent = luponMembers.length;
            
            // Render organization chart ONLY (no cards)
            renderLuponOrganizationChart(captain, secretary, luponMembers);
        } else {
            const container = document.getElementById('luponOrganizationChart');
            if (container) {
                container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle fa-3x"></i><p>${data.message}</p></div>`;
            }
        }
    } catch (error) {
        console.error('Error loading Lupon members:', error);
        const container = document.getElementById('luponOrganizationChart');
        if (container) {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle fa-3x"></i><p>Error loading Lupon members. Please try again.</p></div>`;
        }
    }
}

function renderLuponOrganizationChart(captain, secretary, luponMembers) {
    const container = document.getElementById('luponOrganizationChart');
    if (!container) return;
    
    const roleColors = {
        chairman: '#8B0000',
        secretary: '#2E7D32',
        member: '#E65100'
    };
    
    // Helper to render a person node - NO ACTION BUTTONS
    const renderPersonNode = (person, role, roleLabel, color) => {
        const hasImage = person && person.profile_image;
        const imageUrl = hasImage ? `../${person.profile_image}` : null;
        const initials = person ? (person.full_name || person.username || '?').charAt(0).toUpperCase() : '?';
        
        if (!person) {
            return `
                <div class="org-node lupon-vacant" style="border: 2px dashed #ccc; background: #fafafa;">
                    <div class="org-node-avatar">
                        <div style="width: 70px; height: 70px; border-radius: 50%; background: #f0f0f0; color: #999; display: flex; align-items: center; justify-content: center; font-size: 28px;">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <div class="org-node-info">
                        <div class="org-node-name" style="color: #999;">Vacant</div>
                        <div class="org-node-title">${roleLabel}</div>
                        <div class="org-node-status vacant">Not Assigned</div>
                    </div>
                </div>
            `;
        }
        
        return `
            <div class="org-node lupon-${role}" onclick="showOfficialDetails(${person.id})" style="border: 2px solid ${color}; min-width: 220px; cursor: pointer;">
                <div class="org-node-avatar">
                    ${hasImage 
                        ? `<img src="${imageUrl}" alt="${escapeHtml(person.full_name)}" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid ${color};">` 
                        : `<div style="width: 70px; height: 70px; border-radius: 50%; background: ${color}20; color: ${color}; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: bold; border: 3px solid ${color};">${initials}</div>`
                    }
                </div>
                <div class="org-node-info">
                    <div class="org-node-name" style="font-size: 13px;">${escapeHtml(person.full_name || person.username || 'Vacant')}</div>
                    <div class="org-node-title" style="color: ${color}; font-weight: 600;">${roleLabel}</div>
                    <div class="org-node-status ${person.is_active ? 'active' : 'inactive'}">${person.is_active ? 'Active' : 'Inactive'}</div>
                </div>
                <div class="click-hint" style="font-size: 10px; color: #999; margin-top: 8px;">
                    <i class="fas fa-mouse-pointer"></i> Click for details
                </div>
            </div>
        `;
    };
    
    // Helper to render lupon member node - NO ACTION BUTTONS
    const renderLuponMemberNode = (member, index) => {
        const hasImage = member && member.profile_image;
        const imageUrl = hasImage ? `../${member.profile_image}` : null;
        const initials = member ? (member.full_name || '').charAt(0).toUpperCase() : '?';
        const color = roleColors.member;
        
        if (!member) {
            return `
                <div class="org-node lupon-member empty" onclick="showAddLuponForm()" style="cursor: pointer; opacity: 0.6; min-width: 160px;">
                    <div class="org-node-avatar">
                        <div style="width: 50px; height: 50px; border-radius: 50%; background: #f0f0f0; color: #999; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            <i class="fas fa-plus"></i>
                        </div>
                    </div>
                    <div class="org-node-info">
                        <div class="org-node-name" style="color: #999; font-size: 11px;">Slot ${index + 1}</div>
                        <div class="org-node-title" style="font-size: 10px;">Vacant</div>
                    </div>
                </div>
            `;
        }
        
        return `
            <div class="org-node lupon-member" onclick="showOfficialDetails(${member.id})" style="border: 1px solid ${color}; padding: 10px; min-width: 180px; cursor: pointer;">
                <div class="org-node-avatar">
                    ${hasImage 
                        ? `<img src="${imageUrl}" alt="${escapeHtml(member.full_name)}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid ${color};">` 
                        : `<div style="width: 50px; height: 50px; border-radius: 50%; background: ${color}20; color: ${color}; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; border: 2px solid ${color};">${initials}</div>`
                    }
                </div>
                <div class="org-node-info">
                    <div class="org-node-name" style="font-size: 12px; font-weight: 600;">${escapeHtml(member.full_name)}</div>
                    <div class="org-node-title" style="font-size: 10px; color: ${color};">Lupon Member</div>
                </div>
                <div class="click-hint" style="font-size: 10px; color: #999; margin-top: 8px;">
                    <i class="fas fa-mouse-pointer"></i> Click for details
                </div>
            </div>
        `;
    };
    
    const totalSlots = Math.max(10, Math.min(20, luponMembers.length + 2));
    
    let html = `
        <style>
            .lupon-org-chart { background: linear-gradient(135deg, #fff8f0, #fff3e0); border-radius: 20px; padding: 25px; border: 2px solid #E65100; margin-bottom: 20px; overflow-x: auto; }
            .lupon-org-title { text-align: center; color: #E65100; font-size: 1.3rem; font-weight: 700; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px dashed #E65100; }
            .lupon-org-title i { margin-right: 10px; }
            .lupon-org-level { display: flex; justify-content: center; flex-wrap: wrap; gap: 20px; margin: 15px 0; }
            .lupon-org-connector { width: 2px; height: 25px; background: linear-gradient(to bottom, #E65100, #FF9800); margin: 5px auto; }
            .lupon-members-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; justify-items: center; margin-top: 15px; }
            .lupon-members-label { text-align: center; font-weight: 600; color: #E65100; margin-bottom: 10px; font-size: 0.95rem; }
            .lupon-member { min-width: 180px; max-width: 200px; padding: 10px !important; text-align: center; }
            @media (max-width: 1200px) { .lupon-members-grid { grid-template-columns: repeat(3, 1fr); } }
            @media (max-width: 768px) { .lupon-members-grid { grid-template-columns: repeat(2, 1fr); } }
            @media (max-width: 480px) { .lupon-members-grid { grid-template-columns: 1fr; } }
        </style>
        
        <div class="lupon-org-chart">
            <div class="lupon-org-title"><i class="fas fa-gavel"></i> LUPONG TAGAPAMAYAPA ORGANIZATIONAL CHART</div>
            
            <div class="lupon-org-level">
                ${renderPersonNode(captain, 'chairman', 'Lupon Chairman', roleColors.chairman)}
            </div>
            
            <div class="lupon-org-connector"></div>
            
            <div class="lupon-org-level">
                ${renderPersonNode(secretary, 'secretary', 'Lupon Secretary', roleColors.secretary)}
            </div>
            
            <div class="lupon-org-connector"></div>
            
            <div class="lupon-members-label"><i class="fas fa-users"></i> Lupon Members (${luponMembers.length} / ${totalSlots})</div>
            <div class="lupon-members-grid">
                ${Array.from({ length: totalSlots }, (_, i) => renderLuponMemberNode(luponMembers[i] || null, i)).join('')}
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

// ============ SHOW OFFICIAL DETAILS MODAL (For Captain, Secretary, Lupon) ============

async function showOfficialDetails(id) {
    createAdminDetailsModal();
    
    const modal = document.getElementById('adminDetailsModal');
    const modalBody = document.getElementById('adminDetailsModalBody');
    const modalFooter = document.getElementById('adminDetailsModalFooter');
    
    modal.style.display = 'block';
    modalBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>';
    modalFooter.innerHTML = '';
    
    try {
        const response = await fetch(`admin_ajax.php?action=get_admin&id=${id}`);
        const data = await response.json();
        
        if (!data.success) {
            modalBody.innerHTML = `<div class="empty-state"><p>${data.message || 'Official not found'}</p></div>`;
            modalFooter.innerHTML = `<button class="btn-close" onclick="closeAdminDetailsModal()">Close</button>`;
            return;
        }
        
        const admin = data.admin;
        const roleInfo = ROLE_LIMITS[admin.admin_role] || LUPON_ROLE_LIMITS[admin.admin_role] || { name: 'Staff', icon: 'fas fa-user', color: '#43e97b' };
        
        const formatDate = (dateString) => {
            if (!dateString) return 'Not provided';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        };
        
        const hasImage = admin.profile_image;
        
        modalBody.innerHTML = `
            <div style="text-align: center; margin-bottom: 25px;">
                ${hasImage 
                    ? `<img src="../${admin.profile_image}" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid ${roleInfo.color || '#43e97b'}; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">` 
                    : `<div style="width: 120px; height: 120px; border-radius: 50%; background: ${roleInfo.color || '#43e97b'}20; color: ${roleInfo.color || '#43e97b'}; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; border: 4px solid ${roleInfo.color || '#43e97b'}; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">${(admin.full_name || admin.username || '?').charAt(0).toUpperCase()}</div>`
                }
                <h2 style="margin: 15px 0 5px; color: #1a472a; font-size: 1.4rem;">${escapeHtml(admin.full_name || admin.username)}</h2>
                <div style="display: inline-flex; align-items: center; gap: 6px; background: ${roleInfo.color || '#43e97b'}20; color: ${roleInfo.color || '#43e97b'}; padding: 6px 16px; border-radius: 20px; font-weight: 600; font-size: 0.85rem;">
                    <i class="${roleInfo.icon || 'fas fa-user'}"></i>
                    ${roleInfo.name || 'Staff'}
                </div>
                <div style="margin-top: 10px;">
                    <span style="padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; background: ${admin.is_active ? '#d4edda' : '#f8d7da'}; color: ${admin.is_active ? '#155724' : '#721c24'};">
                        <i class="fas ${admin.is_active ? 'fa-check-circle' : 'fa-ban'}"></i>
                        ${admin.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
            </div>
            
            <div class="detail-section" style="margin-bottom: 20px;">
                <h4 style="color: #1a472a; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #e2efe8; font-size: 0.95rem;">
                    <i class="fas fa-user" style="color: #43e97b;"></i> Personal Information
                </h4>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Full Name:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;"><strong>${escapeHtml(admin.full_name || 'Not provided')}</strong></div>
                </div>
                ${admin.suffix ? `<div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Suffix:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.suffix)}</div>
                </div>` : ''}
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Email:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.email || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Phone:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.phone_number || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Birth Date:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${formatDate(admin.birth_date)}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Birth Place:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.birth_place || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Gender:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.gender || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Civil Status:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.civil_status || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Religion:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.religion || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Occupation:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(admin.occupation || 'Not provided')}</div>
                </div>
            </div>
        `;
        
        // Action buttons at bottom of modal
        modalFooter.innerHTML = `
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
                <button class="btn-verify" onclick="editAdmin(${admin.id}); closeAdminDetailsModal();" style="background: #2196F3;">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-verify" onclick="resetAdminPassword(${admin.id}, '${escapeHtml(admin.username)}', '${escapeHtml(admin.email)}', '${escapeHtml(admin.full_name)}'); closeAdminDetailsModal();" style="background: #FF9800;">
                    <i class="fas fa-key"></i> Reset Password
                </button>
                <button class="btn-verify" onclick="toggleAdminStatus(${admin.id}, ${admin.is_active}); closeAdminDetailsModal();" style="background: ${admin.is_active ? '#dc3545' : '#28a745'};">
                    <i class="fas fa-${admin.is_active ? 'ban' : 'check'}"></i> ${admin.is_active ? 'Deactivate' : 'Activate'}
                </button>
                <button class="btn-verify" onclick="deleteAdmin(${admin.id}, '${escapeHtml(admin.full_name || admin.username)}'); closeAdminDetailsModal();" style="background: #6c757d;">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button class="btn-close" onclick="closeAdminDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        `;
    } catch (error) {
        console.error('Error loading official details:', error);
        modalBody.innerHTML = `<div class="empty-state"><p>Error loading details.</p></div>`;
        modalFooter.innerHTML = `<button class="btn-close" onclick="closeAdminDetailsModal()">Close</button>`;
    }
}

// ============ SHOW KAGAWAD DETAILS MODAL ============

async function showKagawadDetails(id) {
    createKagawadDetailsModal();
    
    const modal = document.getElementById('kagawadDetailsModal');
    const modalBody = document.getElementById('kagawadDetailsModalBody');
    const modalFooter = document.getElementById('kagawadDetailsModalFooter');
    
    modal.style.display = 'block';
    modalBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>';
    modalFooter.innerHTML = '';
    
    try {
        const response = await fetch(`admin_ajax.php?action=get_kagawad&id=${id}`);
        const data = await response.json();
        
        if (!data.success) {
            modalBody.innerHTML = `<div class="empty-state"><p>${data.message || 'Kagawad not found'}</p></div>`;
            modalFooter.innerHTML = `<button class="btn-close" onclick="closeKagawadDetailsModal()">Close</button>`;
            return;
        }
        
        const kagawad = data.kagawad;
        const hasImage = kagawad.profile_image;
        
        const formatDate = (dateString) => {
            if (!dateString) return 'Not provided';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        };
        
        modalBody.innerHTML = `
            <div style="text-align: center; margin-bottom: 25px;">
                ${hasImage 
                    ? `<img src="../${kagawad.profile_image}" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #1565C0; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">` 
                    : `<div style="width: 120px; height: 120px; border-radius: 50%; background: #1565C020; color: #1565C0; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; border: 4px solid #1565C0; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">${(kagawad.full_name || '?').charAt(0).toUpperCase()}</div>`
                }
                <h2 style="margin: 15px 0 5px; color: #1a472a; font-size: 1.4rem;">${escapeHtml(kagawad.full_name)}</h2>
                <div style="display: inline-flex; align-items: center; gap: 6px; background: #1565C020; color: #1565C0; padding: 6px 16px; border-radius: 20px; font-weight: 600; font-size: 0.85rem;">
                    <i class="fas fa-users"></i> Kagawad
                </div>
                ${kagawad.committee ? `<div style="margin-top: 8px; font-size: 0.85rem; color: #666; font-style: italic;">${escapeHtml(kagawad.committee)}</div>` : ''}
                <div style="margin-top: 10px;">
                    <span style="padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; background: ${kagawad.is_active ? '#d4edda' : '#f8d7da'}; color: ${kagawad.is_active ? '#155724' : '#721c24'};">
                        <i class="fas ${kagawad.is_active ? 'fa-check-circle' : 'fa-ban'}"></i>
                        ${kagawad.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
            </div>
            
            <div class="detail-section" style="margin-bottom: 20px;">
                <h4 style="color: #1a472a; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #e2efe8; font-size: 0.95rem;">
                    <i class="fas fa-user" style="color: #1565C0;"></i> Personal Information
                </h4>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Full Name:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;"><strong>${escapeHtml(kagawad.full_name || 'Not provided')}</strong></div>
                </div>
                ${kagawad.suffix ? `<div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Suffix:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.suffix)}</div>
                </div>` : ''}
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Email:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.email || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Contact:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.contact_number || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Birth Date:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${formatDate(kagawad.birth_date)}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Birth Place:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.birth_place || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Gender:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.gender || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Civil Status:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.civil_status || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0; border-bottom: 1px solid #f0f4f0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Religion:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.religion || 'Not provided')}</div>
                </div>
                <div style="display: flex; padding: 8px 0;">
                    <div style="width: 140px; font-weight: 600; color: #5f7f6e; font-size: 0.85rem;">Occupation:</div>
                    <div style="flex: 1; color: #2c3e50; font-size: 0.9rem;">${escapeHtml(kagawad.occupation || 'Not provided')}</div>
                </div>
            </div>
        `;
        
        // Action buttons at bottom
        modalFooter.innerHTML = `
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
                <button class="btn-verify" onclick="editKagawad(${kagawad.id}); closeKagawadDetailsModal();" style="background: #2196F3;">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-verify" onclick="deleteKagawad(${kagawad.id}, '${escapeHtml(kagawad.full_name)}'); closeKagawadDetailsModal();" style="background: #dc3545;">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button class="btn-close" onclick="closeKagawadDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        `;
    } catch (error) {
        console.error('Error loading kagawad details:', error);
        modalBody.innerHTML = `<div class="empty-state"><p>Error loading details.</p></div>`;
        modalFooter.innerHTML = `<button class="btn-close" onclick="closeKagawadDetailsModal()">Close</button>`;
    }
}

// ============ CREATE DETAIL MODALS ============

function createAdminDetailsModal() {
    if (document.getElementById('adminDetailsModal')) return;
    
    const modalHtml = `
        <div id="adminDetailsModal" class="modal">
            <div class="modal-content" style="max-width: 600px; width: 95%;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1a472a, #43e97b);">
                    <h2><i class="fas fa-user-tie"></i> Official Details</h2>
                    <button class="close-modal" onclick="closeAdminDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="adminDetailsModalBody" style="max-height: 60vh; overflow-y: auto;"></div>
                <div class="modal-footer" id="adminDetailsModalFooter" style="padding: 16px 24px; border-top: 1px solid #eef2ef;"></div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeAdminDetailsModal() {
    const modal = document.getElementById('adminDetailsModal');
    if (modal) modal.style.display = 'none';
}

function createKagawadDetailsModal() {
    if (document.getElementById('kagawadDetailsModal')) return;
    
    const modalHtml = `
        <div id="kagawadDetailsModal" class="modal">
            <div class="modal-content" style="max-width: 600px; width: 95%;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1565C0, #42A5F5);">
                    <h2><i class="fas fa-users"></i> Kagawad Details</h2>
                    <button class="close-modal" onclick="closeKagawadDetailsModal()">&times;</button>
                </div>
                <div class="modal-body" id="kagawadDetailsModalBody" style="max-height: 60vh; overflow-y: auto;"></div>
                <div class="modal-footer" id="kagawadDetailsModalFooter" style="padding: 16px 24px; border-top: 1px solid #eef2ef;"></div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeKagawadDetailsModal() {
    const modal = document.getElementById('kagawadDetailsModal');
    if (modal) modal.style.display = 'none';
}

function renderLuponMembersList(luponMembers) {
    const container = document.getElementById('luponMembersList');
    if (!container) return;
    
    if (luponMembers.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-gavel fa-3x"></i>
                <p>No Lupon members found.</p>
                <button class="btn-verify" onclick="showAddLuponForm()" style="margin-top: 15px; background: #E65100;">
                    <i class="fas fa-plus-circle"></i> Add First Lupon Member
                </button>
            </div>
        `;
        return;
    }
    
    const cardsHtml = luponMembers.map(member => {
        const hasImage = member.profile_image;
        const initials = (member.full_name || member.username || '?').charAt(0).toUpperCase();
        
        return `
            <div class="data-card" style="margin-bottom: 15px;">
                <div class="card-header-gradient" style="background: linear-gradient(135deg, #E65100, #FF9800); display: flex; align-items: center; gap: 15px;">
                    ${hasImage 
                        ? `<img src="../${member.profile_image}" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 3px solid white;">` 
                        : `<div style="width: 60px; height: 60px; border-radius: 50%; background: white; color: #E65100; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold;">${initials}</div>`
                    }
                    <div style="flex: 1;">
                        <div class="card-title-large">
                            <span>${escapeHtml(member.full_name || member.username)}</span>
                        </div>
                        <div class="status-chip" style="margin-top: 5px;">${member.is_active ? 'Active' : 'Inactive'}</div>
                    </div>
                </div>
                <div class="card-content">
                    <div class="info-section">
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-envelope"></i></div>
                            <div class="info-label-card">Email:</div>
                            <div class="info-value-card">${escapeHtml(member.email)}</div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                            <div class="info-label-card">Phone:</div>
                            <div class="info-value-card">${escapeHtml(member.phone_number || 'Not provided')}</div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-calendar"></i></div>
                            <div class="info-label-card">Created:</div>
                            <div class="info-value-card">${formatDate(member.created_at)}</div>
                        </div>
                    </div>
                </div>
                <div class="card-actions" style="padding: 15px; display: flex; gap: 10px; flex-wrap: wrap; border-top: 1px solid #eef2ef;">
                    <button class="action-btn edit" onclick="editAdmin(${member.id})" style="background: #2196F3; color: white; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="action-btn reset" onclick="resetAdminPassword(${member.id}, '${escapeHtml(member.username)}', '${escapeHtml(member.email)}', '${escapeHtml(member.full_name)}')" style="background: #FF9800; color: white; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;">
                        <i class="fas fa-key"></i> Reset Password
                    </button>
                    <button class="action-btn ${member.is_active ? 'deactivate' : 'activate'}" onclick="toggleAdminStatus(${member.id}, ${member.is_active})" style="background: ${member.is_active ? '#dc3545' : '#28a745'}; color: white; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;">
                        <i class="fas fa-${member.is_active ? 'ban' : 'check'}"></i> ${member.is_active ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="action-btn delete" onclick="deleteAdmin(${member.id}, '${escapeHtml(member.full_name || member.username)}')" style="background: #6c757d; color: white; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = cardsHtml;
}

function showAddLuponForm() {
    createAdminModal();
    
    const modal = document.getElementById('adminModal');
    const modalBody = document.getElementById('adminModalBody');
    const modalTitle = document.getElementById('adminModalTitle');
    
    modalTitle.innerHTML = '<i class="fas fa-gavel"></i> Add New Lupon Member';
    
    modalBody.innerHTML = `
        <form id="luponForm" enctype="multipart/form-data" onsubmit="saveLuponMember(event)">
            <div class="form-group">
                <label>Full Name <span style="color:red;">*</span></label>
                <input type="text" id="luponFullName" class="form-control" required placeholder="e.g., DELA CRUZ, JUAN M.">
            </div>
            
            <div class="form-group">
                <label>Suffix</label>
                <input type="text" id="luponSuffix" class="form-control" placeholder="Jr., Sr., III">
            </div>
            
            <div class="form-group">
                <label>Email <span style="color:red;">*</span></label>
                <input type="email" id="luponEmail" class="form-control" required onblur="validateLuponEmailField()">
                <small id="luponEmailStatus" class="form-hint"></small>
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" id="luponPhone" class="form-control" placeholder="09XXXXXXXXX" onkeyup="validateLuponPhoneInput(this)">
                <small id="luponPhoneStatus" class="form-hint">Only numbers allowed (10-11 digits)</small>
            </div>
            
            <div class="form-group">
                <label>Profile Picture / ID</label>
                <div id="luponImageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('luponImage').click()">
                    <div id="luponImagePreviewContainer" style="display: none;">
                        <img id="luponPreviewImg" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                    </div>
                    <div id="luponImagePlaceholder">
                        <i class="fas fa-camera" style="font-size: 48px; color: #E65100;"></i>
                        <p style="margin-top: 10px; color: #666;">Click to upload picture/ID</p>
                        <p style="font-size: 12px; color: #999;">JPG, PNG (Max 5MB)</p>
                    </div>
                </div>
                <input type="file" id="luponImage" accept="image/jpeg,image/png,image/jpg" style="display: none;" onchange="previewLuponImage(this)">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Birth Date</label>
                    <input type="date" id="luponBirthDate" class="form-control">
                </div>
                <div class="form-group">
                    <label>Birth Place</label>
                    <input type="text" id="luponBirthPlace" class="form-control">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Gender</label>
                    <select id="luponGender" class="form-control">
                        <option value="">Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Civil Status</label>
                    <select id="luponCivilStatus" class="form-control">
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
                    <input type="text" id="luponReligion" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label>Occupation</label>
                <input type="text" id="luponOccupation" class="form-control">
            </div>
            
            <div class="form-group">
                <label>Status</label>
                <select id="luponIsActive" class="form-control">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            
            <div class="form-buttons">
                <button type="submit" class="btn-verify" style="background: #E65100;"><i class="fas fa-save"></i> Create Lupon Account</button>
                <button type="button" class="btn-close" onclick="closeAdminModal()">Cancel</button>
            </div>
        </form>
    `;
    
      modal.style.display = 'block';
    
    setTimeout(() => {
        attachRealTimeValidation(document.getElementById('luponFullName'), 'fullName');
        attachRealTimeValidation(document.getElementById('luponSuffix'), 'suffix');
        attachRealTimeValidation(document.getElementById('luponEmail'), 'email', { checkAvailability: true });
        attachRealTimeValidation(document.getElementById('luponPhone'), 'phone');
        attachRealTimeValidation(document.getElementById('luponBirthPlace'), 'birthPlace');
        attachRealTimeValidation(document.getElementById('luponReligion'), 'religion');
        attachRealTimeValidation(document.getElementById('luponOccupation'), 'occupation');
    }, 100);
}

function previewLuponImage(input) {
    const previewContainer = document.getElementById('luponImagePreviewContainer');
    const placeholder = document.getElementById('luponImagePlaceholder');
    const previewImg = document.getElementById('luponPreviewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'block';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

async function validateLuponEmailField() {
    const email = document.getElementById('luponEmail').value;
    const statusSpan = document.getElementById('luponEmailStatus');
    
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

function validateLuponPhoneInput(input) {
    input.value = input.value.replace(/\D/g, '');
    const statusSpan = document.getElementById('luponPhoneStatus');
    
    if (!input.value) {
        statusSpan.innerHTML = 'Optional - Only numbers allowed (10-11 digits)';
        statusSpan.style.color = '#6c757d';
        return;
    }
    
    if (input.value.length === 10 || input.value.length === 11) {
        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Valid phone number';
        statusSpan.style.color = 'green';
    } else {
        statusSpan.innerHTML = '<i class="fas fa-times-circle"></i> Phone number must be 10-11 digits';
        statusSpan.style.color = 'red';
    }
}

async function saveLuponMember(event) {
    event.preventDefault();
    
    const full_name = document.getElementById('luponFullName').value.trim();
    const email = document.getElementById('luponEmail').value.trim();
    const phone = document.getElementById('luponPhone').value;
    
    if (!full_name) {
        showWarningModal('Please enter full name.', 'Validation Error');
        return;
    }
    if (!email) {
        showWarningModal('Please enter email address.', 'Validation Error');
        return;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showWarningModal('Please enter a valid email address.', 'Validation Error');
        return;
    }
    
    const isEmailAvailable = await checkEmailAvailability(email);
    if (!isEmailAvailable) {
        showWarningModal('Email already exists. Please use a different email address.', 'Validation Error');
        return;
    }
    
    if (phone && !validatePhoneNumber(phone)) {
        showWarningModal('Phone number must contain 10-11 digits only.', 'Validation Error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_admin');
    formData.append('full_name', full_name);
    formData.append('email', email);
    formData.append('suffix', document.getElementById('luponSuffix').value);
    formData.append('admin_role', 'lupon');
    formData.append('phone_number', phone);
    formData.append('birth_date', document.getElementById('luponBirthDate').value);
    formData.append('birth_place', document.getElementById('luponBirthPlace').value);
    formData.append('gender', document.getElementById('luponGender').value);
    formData.append('civil_status', document.getElementById('luponCivilStatus').value);
    formData.append('religion', document.getElementById('luponReligion').value);
    formData.append('occupation', document.getElementById('luponOccupation').value);
    formData.append('is_active', document.getElementById('luponIsActive').value);
    
    const imageFile = document.getElementById('luponImage').files[0];
    if (imageFile) {
        formData.append('profile_image', imageFile);
    }
    
    try {
        const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Lupon member account created successfully!', 'Success');
            closeAdminModal();
            await loadLuponMembers(1);
        } else {
            showErrorModal(data.message || 'Failed to create Lupon account', 'Error');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred. Please try again.', 'Error');
    }
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
    
    // Attach real-time validation
    setTimeout(() => {
        attachRealTimeValidation(document.getElementById('adminFullName'), 'fullName');
        attachRealTimeValidation(document.getElementById('adminSuffix'), 'suffix');
        attachRealTimeValidation(document.getElementById('adminEmail'), 'email', { checkAvailability: true });
        attachRealTimeValidation(document.getElementById('adminPhone'), 'phone');
        attachRealTimeValidation(document.getElementById('adminBirthPlace'), 'birthPlace');
        attachRealTimeValidation(document.getElementById('adminReligion'), 'religion');
        attachRealTimeValidation(document.getElementById('adminOccupation'), 'occupation');
    }, 100);

}

function renderOrganizationChart() {
    const container = document.getElementById('organizationChart');
    if (!container) return;
    
    const displayAdmins = currentAdminData.filter(admin => DISPLAY_ROLES.includes(admin.admin_role));
    
    const grouped = {
        captain: [],
        secretary: []
    };
    
    displayAdmins.forEach(admin => {
        if (grouped[admin.admin_role]) {
            grouped[admin.admin_role].push(admin);
        }
    });
    
    const roleColors = {
        captain: '#8B0000',
        secretary: '#2E7D32',
        kagawad: '#1565C0'
    };
    
    const roleNames = {
        captain: 'Barangay Captain',
        secretary: 'Secretary',
        kagawad: 'Kagawad'
    };
    
    // Helper function to render official node - NO ACTION BUTTONS
    const renderNode = (person, role, index = 0) => {
        const hasImage = person && person.profile_image;
        const imageUrl = hasImage ? `../${person.profile_image}` : null;
        const initials = person ? (person.full_name || person.username || '?').charAt(0).toUpperCase() : '?';
        
        return `
            <div class="org-node ${role}" onclick="${person ? `showOfficialDetails(${person.id})` : 'showRoleSelection()'}" style="cursor: pointer;">
                <div class="org-node-avatar">
                    ${hasImage 
                        ? `<img src="${imageUrl}" alt="${escapeHtml(person.full_name)}" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid ${roleColors[role]};">` 
                        : `<div class="org-avatar-initials" style="width: 70px; height: 70px; border-radius: 50%; background: ${roleColors[role]}20; color: ${roleColors[role]}; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: bold; border: 3px solid ${roleColors[role]};">${initials}</div>`
                    }
                </div>
                <div class="org-node-info">
                    <div class="org-node-name">${escapeHtml(person ? (person.full_name || person.username) : 'Vacant')}</div>
                    <div class="org-node-title">${roleNames[role]}</div>
                    ${person ? `<div class="org-node-status ${person.is_active ? 'active' : 'inactive'}">${person.is_active ? 'Active' : 'Inactive'}</div>` : '<div class="org-node-status vacant">Vacant</div>'}
                </div>
                <div class="click-hint" style="font-size: 10px; color: #999; margin-top: 8px;">
                    <i class="fas fa-mouse-pointer"></i> Click for details
                </div>
            </div>
        `;
    };
    
    // Helper for Kagawad node - NO ACTION BUTTONS
    const renderKagawadNode = (kagawad, index) => {
        const hasImage = kagawad && kagawad.profile_image;
        const imageUrl = hasImage ? `../${kagawad.profile_image}` : null;
        const initials = kagawad ? (kagawad.full_name || '').charAt(0).toUpperCase() : '?';
        
        if (kagawad) {
            return `
                <div class="org-node kagawad" onclick="showKagawadDetails(${kagawad.id})" style="cursor: pointer;">
                    <div class="org-node-avatar">
                        ${hasImage 
                            ? `<img src="${imageUrl}" alt="${escapeHtml(kagawad.full_name)}" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 3px solid ${roleColors.kagawad};">` 
                            : `<div class="org-avatar-initials" style="width: 60px; height: 60px; border-radius: 50%; background: ${roleColors.kagawad}20; color: ${roleColors.kagawad}; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; border: 3px solid ${roleColors.kagawad};">${initials}</div>`
                        }
                    </div>
                    <div class="org-node-info">
                        <div class="org-node-name">${escapeHtml(kagawad.full_name)}</div>
                        <div class="org-node-title">Kagawad</div>
                        ${kagawad.committee ? `<div class="org-node-committee">${escapeHtml(kagawad.committee)}</div>` : ''}
                    </div>
                    <div class="click-hint" style="font-size: 10px; color: #999; margin-top: 8px;">
                        <i class="fas fa-mouse-pointer"></i> Click for details
                    </div>
                </div>
            `;
        } else {
            return `
                <div class="org-node empty kagawad-empty" onclick="showAddKagawadModal()" style="cursor: pointer;">
                    <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="org-node-info">
                        <div class="org-node-name">Vacant Slot ${index + 1}</div>
                        <div class="org-node-title">Kagawad</div>
                    </div>
                </div>
            `;
        }
    };
    
    let html = `
        <style>
            .org-node-avatar { margin-bottom: 10px; }
            .org-node-committee { font-size: 10px; color: #666; font-style: italic; margin-top: 2px; }
            .org-node-status.vacant { background: #f0f0f0; color: #999; }
            .org-kagawad-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; justify-items: center; }
            @media (max-width: 768px) { .org-kagawad-grid { grid-template-columns: repeat(2, 1fr); } }
            @media (max-width: 480px) { .org-kagawad-grid { grid-template-columns: 1fr; } }
        </style>
        <div class="org-chart-container">
            <h3 class="org-chart-title"><i class="fas fa-sitemap"></i> Barangay Officials</h3>
            <div class="org-chart">
    `;
    
    html += `<div class="org-level org-level-top">`;
    html += grouped.captain.length > 0 ? renderNode(grouped.captain[0], 'captain') : `
        <div class="org-node empty" onclick="showRoleSelection()" style="cursor: pointer;">
            <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
            <div class="org-node-info">
                <div class="org-node-name">Vacant Position</div>
                <div class="org-node-title">Barangay Captain</div>
            </div>
        </div>
    `;
    html += `</div>`;
    
    html += `<div class="org-connector"></div>`;
    html += `<div class="org-level org-level-second">`;
    html += grouped.secretary.length > 0 ? renderNode(grouped.secretary[0], 'secretary') : `
        <div class="org-node empty" onclick="showRoleSelection()" style="cursor: pointer;">
            <div class="org-node-icon"><i class="fas fa-plus-circle"></i></div>
            <div class="org-node-info">
                <div class="org-node-name">Vacant Position</div>
                <div class="org-node-title">Secretary</div>
            </div>
        </div>
    `;
    html += `</div>`;
    
    html += `<div class="org-connector"></div>`;
    html += `<div class="org-level org-level-third">`;
    html += `<div class="org-level-label"><i class="fas fa-users"></i> Sangguniang Barangay Members (Kagawad)</div>`;
    html += `<div class="org-kagawad-grid">`;
    
    for (let i = 0; i < 7; i++) {
        html += renderKagawadNode(kagawadMembersData[i] || null, i);
    }
    
    html += `</div></div>`;
    html += `</div></div>`;
    
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
        // Load admins (captain, secretary, lupon)
        const response = await fetch(`admin_ajax.php?action=get_admins&page=${page}`);
        const data = await response.json();
        
        if (data.success) {
            currentAdminData = data.admins;
            totalAdminPages = data.totalPages;
            totalAdminsCount = data.totalCount;
            
            // Load Kagawad members separately
            await loadKagawadMembers();
            
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

async function loadKagawadMembers() {
    try {
        const response = await fetch('admin_ajax.php?action=get_kagawad_members');
        const data = await response.json();
        
        if (data.success) {
            kagawadMembersData = data.kagawad_members || [];
        } else {
            kagawadMembersData = [];
        }
    } catch (error) {
        console.error('Error loading Kagawad members:', error);
        kagawadMembersData = [];
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
    showGlobalConfirmModal(
        'Create Account',
        `Create new account for:\n\nName:  ${full_name}\nEmail: ${email}\nRole:  ${roleInfo.name}\n\nCredentials will be sent to the email address.`,
        async () => {
            await performSaveAdmin();
        },
        null,
        'success'
    );
    
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
            const roleInfo = ROLE_LIMITS[admin.admin_role] || LUPON_ROLE_LIMITS[admin.admin_role] || { name: 'Staff', icon: 'fas fa-user' };
            
            modalTitle.innerHTML = `<i class="${roleInfo.icon}"></i> Edit ${roleInfo.name}`;
            
            // Format date for input
            const formatDateForInput = (dateStr) => {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                if (isNaN(date.getTime())) return '';
                return date.toISOString().split('T')[0];
            };
            
            modalBody.innerHTML = `
                <form id="adminForm" enctype="multipart/form-data" onsubmit="updateAdmin(event, ${admin.id})">
                    <!-- Profile Image Section -->
                    <div class="form-group">
                        <label>Profile Picture / ID</label>
                        ${admin.profile_image ? `
                            <div style="margin-bottom: 15px; text-align: center;">
                                <img src="../${admin.profile_image}" style="max-width: 120px; max-height: 120px; border-radius: 50%; border: 3px solid #43e97b; object-fit: cover;">
                                <br>
                                <label style="font-size: 12px; color: #dc3545; cursor: pointer;">
                                    <input type="checkbox" id="removeAdminImage"> Remove current image
                                </label>
                            </div>
                        ` : ''}
                        <div id="adminImageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('adminImage').click()">
                            <div id="adminImagePreviewContainer" style="display: none;">
                                <img id="adminPreviewImg" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                            </div>
                            <div id="adminImagePlaceholder">
                                <i class="fas fa-camera" style="font-size: 36px; color: #43e97b;"></i>
                                <p style="margin-top: 10px; color: #666;">Click to ${admin.profile_image ? 'change' : 'upload'} picture/ID</p>
                                <p style="font-size: 12px; color: #999;">JPG, PNG (Max 5MB)</p>
                            </div>
                        </div>
                        <input type="file" id="adminImage" accept="image/jpeg,image/png,image/jpg" style="display: none;" onchange="previewAdminImage(this)">
                    </div>
                    
                    <!-- Personal Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span style="color:red;">*</span></label>
                            <input type="text" id="adminFullName" class="form-control" value="${escapeHtml(admin.full_name || '')}" required>
                        </div>
                        <div class="form-group">
                            <label>Suffix</label>
                            <input type="text" id="adminSuffix" class="form-control" value="${escapeHtml(admin.suffix || '')}" placeholder="Jr., Sr., III">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email <span style="color:red;">*</span></label>
                            <input type="email" id="adminEmail" class="form-control" value="${escapeHtml(admin.email || '')}" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="tel" id="adminPhone" class="form-control" value="${escapeHtml(admin.phone_number || '')}" placeholder="09XXXXXXXXX">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Birth Date</label>
                            <input type="date" id="adminBirthDate" class="form-control" value="${formatDateForInput(admin.birth_date)}">
                        </div>
                        <div class="form-group">
                            <label>Birth Place</label>
                            <input type="text" id="adminBirthPlace" class="form-control" value="${escapeHtml(admin.birth_place || '')}" placeholder="City/Municipality">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Sex/Gender</label>
                            <select id="adminGender" class="form-control">
                                <option value="">Select</option>
                                <option value="male" ${admin.gender === 'male' || admin.gender === 'Male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${admin.gender === 'female' || admin.gender === 'Female' ? 'selected' : ''}>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Civil Status</label>
                            <select id="adminCivilStatus" class="form-control">
                                <option value="">Select</option>
                                <option value="Single" ${admin.civil_status === 'Single' || admin.civil_status === 'single' ? 'selected' : ''}>Single</option>
                                <option value="Married" ${admin.civil_status === 'Married' || admin.civil_status === 'married' ? 'selected' : ''}>Married</option>
                                <option value="Widowed" ${admin.civil_status === 'Widowed' || admin.civil_status === 'widowed' ? 'selected' : ''}>Widowed</option>
                                <option value="Divorced" ${admin.civil_status === 'Divorced' || admin.civil_status === 'divorced' ? 'selected' : ''}>Divorced</option>
                                <option value="Separated" ${admin.civil_status === 'Separated' || admin.civil_status === 'separated' ? 'selected' : ''}>Separated</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Religion</label>
                            <input type="text" id="adminReligion" class="form-control" value="${escapeHtml(admin.religion || '')}" placeholder="e.g., Roman Catholic">
                        </div>
                        <div class="form-group">
                            <label>Occupation</label>
                            <input type="text" id="adminOccupation" class="form-control" value="${escapeHtml(admin.occupation || '')}" placeholder="e.g., Farmer, Teacher">
                        </div>
                    </div>
                    
                    <div class="form-row">
    <div class="form-group">
        <label>Status</label>
        <select id="adminIsActive" class="form-control">
            <option value="1" ${admin.is_active == 1 ? 'selected' : ''}>Active</option>
            <option value="0" ${admin.is_active == 0 ? 'selected' : ''}>Inactive</option>
        </select>
    </div>
</div>
                    
                    <div class="form-buttons">
                        <button type="submit" class="btn-verify" style="background: ${admin.admin_role === 'lupon' ? '#E65100' : '#43e97b'};"><i class="fas fa-save"></i> Update Account</button>
                        <button type="button" class="btn-close" onclick="closeAdminModal()">Cancel</button>
                    </div>
                </form>
            `;
                    // At the end of the try block, after setting innerHTML:
        setTimeout(() => {
            attachRealTimeValidation(document.getElementById('adminFullName'), 'fullName');
            attachRealTimeValidation(document.getElementById('adminSuffix'), 'suffix');
            attachRealTimeValidation(document.getElementById('adminEmail'), 'email', { checkAvailability: true, excludeId: id });
            attachRealTimeValidation(document.getElementById('adminPhone'), 'phone');
            attachRealTimeValidation(document.getElementById('adminBirthPlace'), 'birthPlace');
            attachRealTimeValidation(document.getElementById('adminReligion'), 'religion');
            attachRealTimeValidation(document.getElementById('adminOccupation'), 'occupation');
        }, 100);
        } else {
            modalBody.innerHTML = `<div class="empty-state"><p>${data.message}</p></div>`;
        }
    } catch (error) {
        console.error('Edit error:', error);
        modalBody.innerHTML = `<div class="empty-state"><p>Error loading admin data.</p></div>`;
    }
}

function previewAdminImage(input) {
    const previewContainer = document.getElementById('adminImagePreviewContainer');
    const placeholder = document.getElementById('adminImagePlaceholder');
    const previewImg = document.getElementById('adminPreviewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'block';
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
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
    
    const email = document.getElementById('adminEmail').value.trim();
    const fullName = document.getElementById('adminFullName').value.trim();
    
    // Validate email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showWarningModal('Please enter a valid email address.', 'Validation Error');
        return;
    }
    
    // Check email availability
    const isEmailAvailable = await checkEmailAvailability(email, id);
    if (!isEmailAvailable) {
        showWarningModal('Email already exists. Please use a different email address.', 'Validation Error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_admin');
    formData.append('id', id);
    formData.append('full_name', fullName);
    formData.append('email', email);
    formData.append('suffix', document.getElementById('adminSuffix').value);
    formData.append('phone_number', document.getElementById('adminPhone').value);
    formData.append('birth_date', document.getElementById('adminBirthDate').value);
    formData.append('birth_place', document.getElementById('adminBirthPlace').value);
    formData.append('gender', document.getElementById('adminGender').value);
    formData.append('civil_status', document.getElementById('adminCivilStatus').value);
    formData.append('religion', document.getElementById('adminReligion').value);
    formData.append('occupation', document.getElementById('adminOccupation').value);
    formData.append('is_active', document.getElementById('adminIsActive').value);
    
    // REMOVED: Password handling
    
    // Handle image removal
    const removeImage = document.getElementById('removeAdminImage');
    if (removeImage && removeImage.checked) {
        formData.append('remove_image', 'true');
    }
    
    // Handle new image upload
    const imageFile = document.getElementById('adminImage').files[0];
    if (imageFile) {
        formData.append('profile_image', imageFile);
    }
    
    try {
        const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Account updated successfully!', 'Account Updated');
            closeAdminModal();
            await loadAdmins(currentAdminPage);
            if (document.getElementById('luponOrganizationChart')) {
                await loadLuponMembers(1);
            }
        } else {
            showErrorModal(data.message || 'Failed to update account', 'Error');
        }
    } catch (error) {
        console.error('Update error:', error);
        showErrorModal('An error occurred. Please try again.', 'Error');
    }
}
async function resetAdminPassword(id, username, email, full_name) {
    showGlobalConfirmModal(
        'Reset Password',
        `Reset password for "${username}"?\n\nA new strong password will be generated and sent to:\n${email}`,
        async () => {
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
                    showGlobalNotificationModal('success', 'Password Reset', message);
                } else {
                    showGlobalNotificationModal('error', 'Error', data.message || 'Failed to reset password');
                }
            } catch (error) {
                console.error('Reset error:', error);
                showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
            }
        },
        null,
        'warning'
    );
}

function toggleAdminStatus(id, currentStatus) {
    const isDeactivating = currentStatus == 1;
    const action = isDeactivating ? 'Deactivate' : 'Activate';
    const message = isDeactivating
        ? 'Are you sure you want to DEACTIVATE this account?\n\nThe user will NOT be able to login until reactivated.'
        : 'Are you sure you want to ACTIVATE this account?\n\nThe user will be able to login again.';

    showGlobalConfirmModal(
        `${action} Account`,
        message,
        async () => {
            const formData = new FormData();
            formData.append('action', 'toggle_admin_status');
            formData.append('id', id);
            formData.append('status', currentStatus ? 0 : 1);

            try {
                const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showGlobalNotificationModal(
                        'success',
                        'Status Updated',
                        `Account has been ${isDeactivating ? 'deactivated' : 'activated'} successfully!`
                    );
                    await loadAdmins(currentAdminPage);
                    if (document.getElementById('luponOrganizationChart')) {
                        await loadLuponMembers(1);
                    }
                } else {
                    showGlobalNotificationModal('error', 'Error', data.message || 'Failed to update status');
                }
            } catch (error) {
                console.error('Toggle error:', error);
                showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
            }
        },
        null,
        isDeactivating ? 'danger' : 'success'
    );
}

function deleteAdmin(id, name) {
    showGlobalConfirmModal(
        'Delete Account',
        `Are you sure you want to DELETE "${name}"?\n\nThis action CANNOT be undone.\nAll data associated with this account will be permanently removed.`,
        async () => {
            const formData = new FormData();
            formData.append('action', 'delete_admin');
            formData.append('id', id);

            try {
                const response = await fetch('admin_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showGlobalNotificationModal(
                        'success',
                        'Account Deleted',
                        `"${name}" has been deleted successfully.`
                    );
                    await loadAdmins(currentAdminPage);
                    if (document.getElementById('luponOrganizationChart')) {
                        await loadLuponMembers(1);
                    }
                } else {
                    showGlobalNotificationModal('error', 'Error', data.message || 'Failed to delete account');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showGlobalNotificationModal('error', 'Error', 'An error occurred. Please try again.');
            }
        },
        null,
        'danger'
    );
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