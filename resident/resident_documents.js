// resident_documents.js - Document Request Module with 2-Step Wizard Form

// ============ DOCUMENT MODULE STATE ============
const DocumentModule = {
    currentBaseFee: 0,
    currentDocName: '',
    currentDocId: 0,
    currentEditRequestId: null,
    pendingCancelRequestId: null,
    pendingCallback: null,
    currentUnreadNotes: {},
    notificationCheckEnabled: true,
    lastCheckedStats: {
        approved: 0,
        pending: 0,
        rejected: 0
    },
    notificationTimeout: null,
    currentPaymentRequest: null,
    currentPaymentMethod: null,
    currentQRRequestId: null,
    currentQRImageUrl: null,
    currentBookingFilter: 'all',
    currentEquipmentFilter: 'all',
    currentEquipmentTypes: [],
    currentBookingsList: [],
    currentSelectedEquipment: null,
    allRequests: [],
    equipmentBookingsData: [],
    expandedBookingId: null,
    modernCurrentSlide: 0,
    modernSlideInterval: null,
    currentCalendarDate: new Date(),
    returnDates: [],
    bookingDates: [],
    // Wizard state
    wizardStep: 1,
    wizardData: {
        residentInfo: null,
        residentType: 'regular',
        selectedDoc: null,
        selectedFields: [],
        feeType: 'regular',
        quantity: 1,
        paymentMethod: 'online',
        paymentAmount: 0,
        isFree: false,
        autoApproved: false,
        documents: [],
        customData: {},
        residentListData: null,
        allCustomFields: {},
        isSubmitting: false
    }
};

// ============ HELPER FUNCTIONS ============

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getDocumentIcon(docName) {
    const icons = {
        'Barangay Clearance': 'fa-id-card',
        'Certificate of Residency': 'fa-home',
        'Indigency Certificate': 'fa-hand-holding-heart',
        'Business Clearance': 'fa-store',
        'Certificate of Good Moral': 'fa-star',
        'First Time Job Seeker': 'fa-briefcase',
        'Certificate of Cohabitation': 'fa-heart'
    };
    return icons[docName] || 'fa-file-alt';
}

// ============ LOADING FUNCTIONS ============

function showLoading(message) {
    let loadingDiv = document.getElementById('loadingOverlay');
    if (!loadingDiv) {
        loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingOverlay';
        loadingDiv.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
        `;
        loadingDiv.innerHTML = `
            <div style="background: white; padding: 35px 45px; border-radius: 20px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3); min-width: 200px;">
                <div style="width: 50px; height: 50px; margin: 0 auto 20px;">
                    <div class="loading-spinner" style="width: 50px; height: 50px; border-width: 4px;"></div>
                </div>
                <p style="margin: 0; font-size: 15px; color: #1a472a; font-weight: 500; letter-spacing: 0.3px;">
                    ${message || 'Loading...'}
                    <span class="loading-dots"></span>
                </p>
                <div style="margin-top: 8px; font-size: 12px; color: #999;">Please wait</div>
            </div>
        `;
        document.body.appendChild(loadingDiv);
    } else {
        loadingDiv.style.display = 'flex';
        const msgPara = loadingDiv.querySelector('p');
        if (msgPara) {
            msgPara.innerHTML = (message || 'Loading...') + ' <span class="loading-dots"></span>';
        }
    }
}

function hideLoading() {
    const loadingDiv = document.getElementById('loadingOverlay');
    if (loadingDiv) {
        loadingDiv.style.opacity = '0';
        setTimeout(() => {
            loadingDiv.style.display = 'none';
            loadingDiv.style.opacity = '1';
        }, 300);
    }
}

function showSuccessModal(message) {
    const modal = document.getElementById('successModal');
    const messageEl = document.getElementById('successMessage');
    if (modal && messageEl) {
        messageEl.innerHTML = message;
        modal.classList.add('success-animation');
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('success-animation');
        }, 500);
        setTimeout(() => {
            modal.style.display = 'none';
        }, 3000);
    } else {
        alert(message);
    }
}

function showErrorModal(message) {
    const modal = document.getElementById('errorModal');
    const messageEl = document.getElementById('errorMessage');
    if (modal && messageEl) {
        messageEl.innerHTML = message;
        modal.classList.add('error-animation');
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('error-animation');
        }, 500);
        setTimeout(() => {
            modal.style.display = 'none';
        }, 3000);
    } else {
        alert(message);
    }
}

function closeErrorModal() {
    const modal = document.getElementById('errorModal');
    if (modal) modal.style.display = 'none';
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    if (modal) modal.style.display = 'none';
}

function closeInfoModal() {
    const modal = document.getElementById('infoModal');
    if (modal) modal.style.display = 'none';
}

function showConfirmModal(message, warning, onConfirm) {
    const modal = document.getElementById('confirmModal');
    const messageEl = document.getElementById('confirmMessage');
    const warningEl = document.getElementById('confirmWarning');
    const confirmBtn = document.getElementById('confirmYesBtn');
    
    if (messageEl) messageEl.innerHTML = message;
    if (warningEl) warningEl.style.display = warning ? 'block' : 'none';
    
    DocumentModule.pendingCallback = onConfirm;
    
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    newConfirmBtn.addEventListener('click', function() {
        if (DocumentModule.pendingCallback) {
            DocumentModule.pendingCallback();
            DocumentModule.pendingCallback = null;
        }
        closeConfirmModal();
    });
    
    if (modal) {
        modal.classList.add('warning-animation');
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('warning-animation');
        }, 500);
    }
}

function closeConfirmModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
        modal.style.display = 'none';
    }
    DocumentModule.pendingCallback = null;
    DocumentModule.pendingCancelRequestId = null;
}

function showInfoModal(message) {
    const modal = document.getElementById('infoModal');
    if (modal) {
        document.getElementById('infoMessage').innerHTML = message;
        modal.classList.add('info-animation');
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.remove('info-animation');
        }, 400);
        setTimeout(() => {
            modal.style.display = 'none';
        }, 3000);
    } else {
        alert(message);
    }
}

// ============ DATE FORMAT HELPERS ============

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateStr)) return dateStr;
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
        const parts = dateStr.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }
    try {
        const d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear();
            return day + '/' + month + '/' + year;
        }
    } catch (e) {}
    return dateStr;
}

function formatDateForDatabase(dateStr) {
    if (!dateStr) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateStr)) {
        const parts = dateStr.split('/');
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    try {
        const d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
            return d.toISOString().split('T')[0];
        }
    } catch (e) {}
    return dateStr;
}

function formatGenderDisplay(gender) {
    if (!gender) return '';
    if (gender === 'M') return 'Male';
    if (gender === 'F') return 'Female';
    return gender;
}

function formatGenderForDatabase(gender) {
    if (!gender) return '';
    if (gender === 'Male' || gender === 'M') return 'M';
    if (gender === 'Female' || gender === 'F') return 'F';
    return gender;
}

// ============ PROFILE FUNCTIONS ============

async function getResidentProfile() {
    try {
        console.log('📡 Fetching resident profile...');
        const response = await fetch('ajax_handler.php?action=get_resident_profile');
        const data = await response.json();
        if (data.success && data.data) {
            console.log('✅ Profile data loaded successfully');
            return data.data;
        }
        return null;
    } catch (error) {
        console.error('❌ Error fetching profile:', error);
        return null;
    }
}

// ============ BARANGAY RESIDENT LIST SEARCH ============

async function searchResidentList(fullName) {
    try {
        if (!fullName || fullName.trim() === '') return null;
        
        const nameParts = fullName.trim().split(/\s+/);
        let firstName = '';
        let lastName = '';
        let middleName = '';
        
        if (nameParts.length === 1) {
            firstName = nameParts[0];
            lastName = nameParts[0];
        } else if (nameParts.length === 2) {
            firstName = nameParts[0];
            lastName = nameParts[1];
        } else if (nameParts.length >= 3) {
            firstName = nameParts[0];
            lastName = nameParts[nameParts.length - 1];
            middleName = nameParts.slice(1, nameParts.length - 1).join(' ');
        }
        
        console.log('🔍 Searching barangay resident list for:', { firstName, middleName, lastName });
        
        const url = `ajax_handler.php?action=search_masterlist&first_name=${encodeURIComponent(firstName)}&last_name=${encodeURIComponent(lastName)}&middle_name=${encodeURIComponent(middleName)}`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('📡 Resident list search response:', data);
        
        if (data.success && data.data) {
            return data.data;
        }
        return null;
    } catch (error) {
        console.error('❌ Error searching resident list:', error);
        return null;
    }
}

// ============ AGE CALCULATION FUNCTIONS ============

function calculateAgeFromBirthdate(birthdate) {
    if (!birthdate) return null;
    let dateObj;
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(birthdate)) {
        const parts = birthdate.split('/');
        dateObj = new Date(parts[2], parts[1] - 1, parts[0]);
    } else {
        dateObj = new Date(birthdate);
    }
    if (isNaN(dateObj.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - dateObj.getFullYear();
    const monthDiff = today.getMonth() - dateObj.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dateObj.getDate())) {
        age--;
    }
    return age;
}

function validateDateOfBirth(dateString, fieldName = 'Birthdate') {
    if (!dateString) return { valid: false, message: `${fieldName} is required` };
    let selectedDate;
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateString)) {
        const parts = dateString.split('/');
        selectedDate = new Date(parts[2], parts[1] - 1, parts[0]);
    } else {
        selectedDate = new Date(dateString);
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (selectedDate > today) {
        return { valid: false, message: `${fieldName} cannot be in the future` };
    }
    const minDate = new Date();
    minDate.setFullYear(today.getFullYear() - 120);
    if (selectedDate < minDate) {
        return { valid: false, message: `Please enter a valid ${fieldName.toLowerCase()}` };
    }
    const age = calculateAgeFromBirthdate(dateString);
    return { valid: true, message: '', age: age };
}

function setupAutoAgeCalculation() {
    const birthdateFields = document.querySelectorAll('input[type="date"][name*="custom_fields"], input[type="date"][id*="birthdate"], input[type="text"][name*="birthdate"], input[type="text"][name*="date_of_birth"]');
    birthdateFields.forEach(birthdateInput => {
        birthdateInput.removeEventListener('change', handleBirthdateChange);
        birthdateInput.addEventListener('change', handleBirthdateChange);
        birthdateInput.addEventListener('blur', handleBirthdateChange);
    });
}

function handleBirthdateChange(event) {
    const birthdateInput = event.target;
    const birthdate = birthdateInput.value;
    let parentContainer = birthdateInput.closest('.form-group');
    if (!parentContainer) parentContainer = birthdateInput.closest('.child-entry');
    if (!parentContainer) return;
    let ageField = parentContainer.querySelector('input[type="number"][name*="age"], input[name*="age"]');
    if (!ageField) {
        ageField = parentContainer.querySelector('[name*="age"], [id*="age"]');
    }
    if (ageField && ageField.tagName === 'INPUT') {
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate);
            if (validation.valid && validation.age !== null) {
                ageField.value = validation.age;
            } else if (!validation.valid) {
                showFieldError(birthdateInput, validation.message);
                ageField.value = '';
            } else {
                ageField.value = '';
                clearFieldError(birthdateInput);
            }
        } else {
            ageField.value = '';
        }
    }
    if (birthdate) {
        const validation = validateDateOfBirth(birthdate);
        if (!validation.valid) {
            showFieldError(birthdateInput, validation.message);
        } else {
            clearFieldError(birthdateInput);
        }
    }
}

function showFieldError(field, message) {
    clearFieldError(field);
    field.style.borderColor = '#dc3545';
    field.style.backgroundColor = '#fff8f8';
    const errorSpan = document.createElement('span');
    errorSpan.className = 'field-error-message';
    errorSpan.style.cssText = 'color: #dc3545; font-size: 11px; display: block; margin-top: 5px;';
    errorSpan.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    field.parentNode.appendChild(errorSpan);
}

function clearFieldError(field) {
    field.style.borderColor = '';
    field.style.backgroundColor = '';
    const parent = field.parentNode;
    const existingError = parent.querySelector('.field-error-message');
    if (existingError) {
        existingError.remove();
    }
}

// ============ CHILDREN LIST HANDLERS ============

function initChildrenFieldWithData(fieldName, existingChildren) {
    let childrenArray = existingChildren || [];
    const container = document.getElementById(`children-list-${fieldName}`);
    const hiddenInput = document.getElementById(`children-data-${fieldName}`);
    if (!container) return;
    
    function renderChildrenList() {
        container.innerHTML = '';
        childrenArray.forEach((child, index) => {
            const age = child.birthdate ? calculateAgeFromBirthdate(child.birthdate) : (child.age || '');
            const childDiv = document.createElement('div');
            childDiv.className = 'child-entry';
            childDiv.setAttribute('data-child-index', index);
            childDiv.style.cssText = 'border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:8px; background:#f9f9f9;';
            childDiv.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; color:#1a472a;">Child ${index + 1}</h5>
                    <button type="button" class="remove-child-btn" data-index="${index}" style="background:#ff4444; color:white; border:none; border-radius:5px; padding:5px 10px; cursor:pointer;">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div class="row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="child-full-name" data-index="${index}" value="${escapeHtml(child.full_name || '')}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Birthdate <span style="color:red;">*</span></label>
                        <input type="date" class="child-birthdate" data-index="${index}" value="${child.birthdate || ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Age</label>
                        <input type="number" class="child-age" data-index="${index}" value="${age !== null ? age : ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px; background:#f0f0f0;" readonly>
                    </div>
                </div>
            `;
            container.appendChild(childDiv);
        });
        attachChildEventListeners(fieldName);
    }
    
    function attachChildEventListeners(fieldName) {
        document.querySelectorAll(`#children-list-${fieldName} .child-birthdate`).forEach(input => {
            input.removeEventListener('change', handleChildBirthdateChange);
            input.addEventListener('change', handleChildBirthdateChange);
        });
        document.querySelectorAll(`#children-list-${fieldName} .remove-child-btn`).forEach(btn => {
            btn.removeEventListener('click', handleRemoveChild);
            btn.addEventListener('click', handleRemoveChild);
        });
        document.querySelectorAll(`#children-list-${fieldName} .child-full-name`).forEach(input => {
            input.removeEventListener('input', handleChildNameInput);
            input.addEventListener('input', handleChildNameInput);
        });
    }
    
    function handleChildBirthdateChange(event) {
        const input = event.target;
        const index = parseInt(input.getAttribute('data-index'));
        const birthdate = input.value;
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate, 'Child birthdate');
            if (!validation.valid) {
                showFieldError(input, validation.message);
                const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                if (ageField) ageField.value = '';
                childrenArray[index].age = '';
                childrenArray[index].birthdate = birthdate;
            } else {
                clearFieldError(input);
                if (validation.age !== null) {
                    const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                    if (ageField) ageField.value = validation.age;
                    childrenArray[index].age = validation.age;
                    childrenArray[index].birthdate = birthdate;
                }
            }
        } else {
            clearFieldError(input);
            const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
            if (ageField) ageField.value = '';
            childrenArray[index].age = '';
            childrenArray[index].birthdate = '';
        }
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleRemoveChild(event) {
        const btn = event.target.closest('.remove-child-btn');
        if (!btn) return;
        const index = parseInt(btn.getAttribute('data-index'));
        childrenArray.splice(index, 1);
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleChildNameInput(event) {
        const index = parseInt(event.target.getAttribute('data-index'));
        childrenArray[index].full_name = event.target.value;
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    renderChildrenList();
    const addBtn = document.querySelector(`.add-child-btn[data-field="${fieldName}"]`);
    if (addBtn) {
        addBtn.removeEventListener('click', handleAddChild);
        addBtn.addEventListener('click', handleAddChild);
    }
    
    function handleAddChild() {
        childrenArray.push({
            full_name: '',
            age: '',
            birthdate: ''
        });
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
}

function initChildrenFieldDynamic(fieldName) {
    let childrenArray = [];
    const container = document.getElementById(`children-list-${fieldName}`);
    const hiddenInput = document.getElementById(`children-data-${fieldName}`);
    if (!container) return;
    
    function renderChildrenList() {
        container.innerHTML = '';
        childrenArray.forEach((child, index) => {
            const age = child.birthdate ? calculateAgeFromBirthdate(child.birthdate) : (child.age || '');
            const childDiv = document.createElement('div');
            childDiv.className = 'child-entry';
            childDiv.setAttribute('data-child-index', index);
            childDiv.style.cssText = 'border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:8px; background:#f9f9f9;';
            childDiv.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; color:#1a472a;">Child ${index + 1}</h5>
                    <button type="button" class="remove-child-btn" data-index="${index}" style="background:#ff4444; color:white; border:none; border-radius:5px; padding:5px 10px; cursor:pointer;">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div class="row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="child-full-name" data-index="${index}" value="${escapeHtml(child.full_name || '')}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Birthdate <span style="color:red;">*</span></label>
                        <input type="date" class="child-birthdate" data-index="${index}" value="${child.birthdate || ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Age</label>
                        <input type="number" class="child-age" data-index="${index}" value="${age !== null ? age : ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px; background:#f0f0f0;" readonly>
                    </div>
                </div>
            `;
            container.appendChild(childDiv);
        });
        attachChildEventListeners();
    }
    
    function attachChildEventListeners() {
        document.querySelectorAll(`#children-list-${fieldName} .child-birthdate`).forEach(input => {
            input.removeEventListener('change', handleChildBirthdateChange);
            input.addEventListener('change', handleChildBirthdateChange);
        });
        document.querySelectorAll(`#children-list-${fieldName} .remove-child-btn`).forEach(btn => {
            btn.removeEventListener('click', handleRemoveChild);
            btn.addEventListener('click', handleRemoveChild);
        });
        document.querySelectorAll(`#children-list-${fieldName} .child-full-name`).forEach(input => {
            input.removeEventListener('input', handleChildNameInput);
            input.addEventListener('input', handleChildNameInput);
        });
    }
    
    function handleChildBirthdateChange(event) {
        const input = event.target;
        const index = parseInt(input.getAttribute('data-index'));
        const birthdate = input.value;
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate, 'Child birthdate');
            if (!validation.valid) {
                showFieldError(input, validation.message);
                const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                if (ageField) ageField.value = '';
                childrenArray[index].age = '';
                childrenArray[index].birthdate = birthdate;
            } else {
                clearFieldError(input);
                if (validation.age !== null) {
                    const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                    if (ageField) ageField.value = validation.age;
                    childrenArray[index].age = validation.age;
                    childrenArray[index].birthdate = birthdate;
                }
            }
        } else {
            clearFieldError(input);
            const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
            if (ageField) ageField.value = '';
            childrenArray[index].age = '';
            childrenArray[index].birthdate = '';
        }
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleRemoveChild(event) {
        const btn = event.target.closest('.remove-child-btn');
        if (!btn) return;
        const index = parseInt(btn.getAttribute('data-index'));
        childrenArray.splice(index, 1);
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleChildNameInput(event) {
        const index = parseInt(event.target.getAttribute('data-index'));
        childrenArray[index].full_name = event.target.value;
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    renderChildrenList();
    const addBtn = document.querySelector(`.add-child-btn[data-field="${fieldName}"]`);
    if (addBtn) {
        addBtn.removeEventListener('click', handleAddChild);
        addBtn.addEventListener('click', handleAddChild);
    }
    
    function handleAddChild() {
        childrenArray.push({
            full_name: '',
            age: '',
            birthdate: ''
        });
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
}

// ============ WIZARD STYLES ============

function addWizardStyles() {
    if (document.getElementById('wizardStyles')) return;
    
    const style = document.createElement('style');
    style.id = 'wizardStyles';
    style.textContent = `
        .wizard-container { padding: 10px 0; }
        .wizard-progress {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            padding: 0 10px;
        }
        .wizard-progress::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 30px;
            right: 30px;
            height: 3px;
            background: #e0e0e0;
            z-index: 0;
        }
        .wizard-progress .step-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }
        .wizard-progress .step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.4s ease;
            border: 3px solid transparent;
        }
        .wizard-progress .step-circle.active {
            background: #2e7d32;
            color: white;
            border-color: #43e97b;
            box-shadow: 0 0 20px rgba(46,125,50,0.3);
        }
        .wizard-progress .step-circle.completed {
            background: #43e97b;
            color: white;
            border-color: #2e7d32;
        }
        .wizard-progress .step-label {
            font-size: 11px;
            color: #999;
            margin-top: 6px;
            font-weight: 500;
            text-align: center;
        }
        .wizard-progress .step-label.active { color: #2e7d32; font-weight: 600; }
        .wizard-progress .step-label.completed { color: #43e97b; }
        
        .wizard-step { display: none; animation: fadeInUp 0.4s ease; }
        .wizard-step.active { display: block; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .wizard-step-card {
            background: #f8faf9;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid #e8f0ea;
        }
        .wizard-step-card h3 {
            color: #1a472a;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wizard-step-card h3 i { color: #2e7d32; }
        
        .document-banner {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #a5d6a7;
        }
        .document-banner .doc-icon { font-size: 28px; color: #2e7d32; }
        .document-banner .doc-name { font-weight: 600; color: #1a472a; font-size: 15px; }
        .document-banner .doc-fee { font-size: 13px; color: #2e7d32; margin-left: auto; }
        
        .resident-info-display {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .resident-info-display .info-item {
            background: white;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid #eef2ef;
        }
        .resident-info-display .info-item label {
            font-size: 11px;
            color: #999;
            display: block;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .resident-info-display .info-item input,
        .resident-info-display .info-item select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            margin-top: 3px;
            background: white;
        }
        .resident-info-display .info-item input:focus,
        .resident-info-display .info-item select:focus {
            border-color: #2e7d32;
            outline: none;
            box-shadow: 0 0 0 2px rgba(46,125,50,0.2);
        }
        
        .resident-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 14px;
        }
        .resident-type-badge.regular { background: #e3f2fd; color: #0d47a1; }
        .resident-type-badge.student { background: #e8f5e9; color: #1b5e20; }
        .resident-type-badge.senior { background: #fff3e0; color: #e65100; }
        
        .wizard-nav {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eef2ef;
        }
        .wizard-nav .btn-back {
            background: #f5f5f5;
            color: #666;
            border: none;
            padding: 10px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        .wizard-nav .btn-back:hover { background: #e8e8e8; }
        .wizard-nav .btn-next {
            background: linear-gradient(135deg, #2e7d32, #43e97b);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wizard-nav .btn-next:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(46,125,50,0.3);
        }
        .wizard-nav .btn-next:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .wizard-nav .btn-submit {
            background: linear-gradient(135deg, #1a472a, #2e7d32);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wizard-nav .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(26,71,42,0.3);
        }
        .wizard-nav .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .payment-method-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 10px 0;
        }
        .payment-method-option {
            background: white;
            border: 2px solid #eef2ef;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        .payment-method-option:hover { border-color: #2e7d32; }
        .payment-method-option.selected {
            border-color: #2e7d32;
            background: #e8f5e9;
        }
        .payment-method-option .pm-icon { font-size: 28px; margin-bottom: 5px; display: block; }
        .payment-method-option .pm-label { font-weight: 600; font-size: 13px; color: #333; display: block; }
        .payment-method-option .pm-desc { font-size: 10px; color: #999; display: block; margin-top: 2px; }
        
        .auto-approval-notice {
            background: #e8f5e9;
            border-radius: 12px;
            padding: 15px 20px;
            border-left: 4px solid #2e7d32;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 15px 0;
        }
        .auto-approval-notice i { color: #2e7d32; font-size: 22px; margin-top: 2px; }
        .auto-approval-notice .notice-content h4 { color: #1a472a; margin: 0 0 5px 0; font-size: 14px; }
        .auto-approval-notice .notice-content p { margin: 0; font-size: 13px; color: #2e7d32; }
        
        .wizard-success {
            text-align: center;
            padding: 30px 20px;
        }
        .wizard-success .success-icon {
            font-size: 70px;
            color: #2e7d32;
            animation: successPulse 1s ease;
        }
        @keyframes successPulse {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }
        .wizard-success h3 { color: #1a472a; margin: 20px 0 10px; }
        .wizard-success p { color: #666; max-width: 400px; margin: 0 auto 20px; }
        
        .custom-fields-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 5px;
        }
        .custom-fields-container::-webkit-scrollbar { width: 5px; }
        .custom-fields-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-fields-container::-webkit-scrollbar-thumb { background: #2e7d32; border-radius: 10px; }
        .custom-fields-container .form-group {
            margin-bottom: 15px;
        }
        .custom-fields-container .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }
        .custom-fields-container .form-group label.required::after {
            content: ' *';
            color: red;
        }
        .custom-fields-container .form-group input,
        .custom-fields-container .form-group select,
        .custom-fields-container .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .custom-fields-container .form-group input:focus,
        .custom-fields-container .form-group select:focus,
        .custom-fields-container .form-group textarea:focus {
            border-color: #2e7d32;
            outline: none;
            box-shadow: 0 0 0 2px rgba(46,125,50,0.2);
        }
        .custom-fields-container .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .child-entry {
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            background: #f9f9f9;
        }
        .child-entry .row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        .child-entry .row label {
            font-size: 12px;
            display: block;
            margin-bottom: 5px;
        }
        .child-entry .row input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .child-entry .remove-child-btn {
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 5px 10px;
            cursor: pointer;
        }
        .child-entry .remove-child-btn:hover { background: #cc0000; }
        
        .resident-list-match-banner {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #1a472a;
        }
        .resident-list-match-banner .match-icon { color: #2e7d32; font-size: 18px; }
        .resident-list-match-banner .match-text { flex: 1; }
        .resident-list-match-banner .match-name { font-weight: 600; }
        
        .resident-list-error-banner {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #721c24;
        }
        .resident-list-error-banner .error-icon { color: #dc3545; font-size: 18px; }
        .resident-list-error-banner .error-text { flex: 1; }
        
        /* Review Grid */
        .review-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .review-grid .review-item {
            background: #f8faf9;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #eef2ef;
        }
        .review-grid .review-item label {
            font-size: 11px;
            color: #999;
            display: block;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .review-grid .review-item strong {
            color: #1a472a;
            font-size: 14px;
        }
        .review-grid .review-item.full-width {
            grid-column: 1 / -1;
        }
        
        /* Payment Modal */
        .payment-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .payment-modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: fadeInUp 0.3s ease;
        }
        .payment-modal-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .payment-modal-header h3 {
            color: #1a472a;
            margin: 10px 0 5px;
        }
        .payment-modal-header .amount {
            font-size: 2rem;
            font-weight: bold;
            color: #ff9800;
        }
        .payment-modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .payment-modal-actions button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-modal-actions .btn-pay {
            background: linear-gradient(135deg, #2e7d32, #43e97b);
            color: white;
        }
        .payment-modal-actions .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(46,125,50,0.3);
        }
        .payment-modal-actions .btn-cancel-payment {
            background: #f5f5f5;
            color: #666;
        }
        .payment-modal-actions .btn-cancel-payment:hover {
            background: #e8e8e8;
        }
        
        @media (max-width: 768px) {
            .wizard-progress .step-label { font-size: 9px; }
            .wizard-progress .step-circle { width: 30px; height: 30px; font-size: 12px; }
            .resident-info-display { grid-template-columns: 1fr; }
            .payment-method-grid { grid-template-columns: 1fr; }
            .child-entry .row { grid-template-columns: 1fr; }
            .review-grid { grid-template-columns: 1fr; }
            .payment-modal-content { padding: 20px; }
        }
        @media (max-width: 480px) {
            .payment-method-grid { grid-template-columns: 1fr; }
            .wizard-nav { flex-direction: column; gap: 8px; }
            .wizard-nav button { width: 100%; justify-content: center; }
            .payment-modal-actions { flex-direction: column; }
        }
    `;
    document.head.appendChild(style);
}

// ============ PICKUP DATE/TIME FUNCTIONS ============

function setDefaultPickupDateTime() {
    const dateInput = document.getElementById('wizardPickupDate');
    const timeInput = document.getElementById('wizardPickupTime');
    
    if (!dateInput || !timeInput) return;
    
    // Set default date to today
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    dateInput.value = `${year}-${month}-${day}`;
    
    // Set default time to current time + 1 hour (rounded up)
    let hours = today.getHours() + 1;
    let minutes = today.getMinutes();
    
    // Round up to nearest 30 minutes
    if (minutes > 0 && minutes <= 30) {
        minutes = 30;
    } else if (minutes > 30) {
        minutes = 0;
        hours++;
    }
    
    if (hours >= 24) {
        hours = 23;
        minutes = 59;
    }
    
    timeInput.value = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function validatePickupDateTime() {
    const dateInput = document.getElementById('wizardPickupDate');
    const timeInput = document.getElementById('wizardPickupTime');
    
    if (!dateInput || !timeInput) return true;
    
    const dateValue = dateInput.value;
    const timeValue = timeInput.value;
    
    if (!dateValue) {
        showWizardError('Please select a pickup date.');
        dateInput.style.borderColor = '#dc3545';
        return false;
    }
    dateInput.style.borderColor = '';
    
    if (!timeValue) {
        showWizardError('Please select a pickup time.');
        timeInput.style.borderColor = '#dc3545';
        return false;
    }
    timeInput.style.borderColor = '';
    
    // Check if date is valid
    const selectedDate = new Date(dateValue + ' ' + timeValue);
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    
    const todayDate = new Date();
    todayDate.setHours(0, 0, 0, 0);
    
    // Check if date is not in the past
    if (selectedDate < now) {
        showWizardError('Pickup date and time cannot be in the past.');
        dateInput.style.borderColor = '#dc3545';
        timeInput.style.borderColor = '#dc3545';
        return false;
    }
    
    // Check if same day but time is in the past
    if (dateValue === todayDate.toISOString().split('T')[0]) {
        const currentTime = new Date();
        const selectedTime = new Date(dateValue + ' ' + timeValue);
        if (selectedTime < currentTime) {
            showWizardError('Pickup time must be in the future.');
            timeInput.style.borderColor = '#dc3545';
            return false;
        }
    }
    
    dateInput.style.borderColor = '';
    timeInput.style.borderColor = '';
    return true;
}

// ============ WIZARD CONTAINER ============

function renderWizardContainer() {
    const container = document.querySelector('.request-modal-body');
    if (!container) return;
    
    container.innerHTML = `
        <div class="wizard-container">
            <div class="wizard-progress" id="wizardProgress">
                <div class="step-indicator" data-step="1">
                    <div class="step-circle active" id="stepCircle1">1</div>
                    <div class="step-label active" id="stepLabel1">Document Details</div>
                </div>
                <div class="step-indicator" data-step="2">
                    <div class="step-circle" id="stepCircle2">2</div>
                    <div class="step-label" id="stepLabel2">Review & Submit</div>
                </div>
            </div>
            
            <div id="wizardStepContent">
                <!-- Step 1: Document Details -->
                <div class="wizard-step active" data-step="1" id="wizardStep1">
                    <div class="document-banner">
                        <div class="doc-icon"><i class="fas ${getDocumentIcon(DocumentModule.wizardData.selectedDoc?.name || 'file-alt')}"></i></div>
                        <div class="doc-name">${escapeHtml(DocumentModule.wizardData.selectedDoc?.name || 'Loading...')}</div>
                        <div class="doc-fee">Fee: ₱${(DocumentModule.wizardData.selectedDoc?.fee || 0).toFixed(2)} per copy</div>
                    </div>
                    
                    <div id="residentListMatchStatus"></div>
                    
                    <div class="wizard-step-card">
                        <h3><i class="fas fa-clipboard-list"></i> Document Information</h3>
                        <div id="dynamicFieldsContainer" class="custom-fields-container">
                            <div style="text-align: center; padding: 20px;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #2e7d32;"></i>
                                <p style="margin-top: 10px; color: #666;">Loading document fields...</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wizard-step-card" style="background: #fff8e1; border-color: #ff9800;">
                        <h3><i class="fas fa-id-card" style="color: #ff9800;"></i> Resident Type <span style="font-size:12px; color:#999; font-weight:400; margin-left:10px;">(Auto-detected)</span></h3>
                        <div id="residentTypeDisplay">
                            <div style="text-align: center; padding: 10px;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #ff9800;"></i>
                                <p style="margin-top: 8px; color: #666; font-size: 13px;">Detecting your resident type...</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wizard-step-card" style="background: #f8faf9;">
                        <h3><i class="fas fa-file-signature"></i> Additional Information</h3>
                        <div id="additionalInfoDisplay">
                            <div class="form-group">
                                <label>Quantity (Number of Copies)</label>
                                <input type="number" id="wizardQuantity" value="1" min="1" max="10" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:14px;">
                                <small>Maximum of 10 copies per request</small>
                            </div>
                            <div class="form-group" style="margin-top:15px;">
                                <label class="required">Pickup Date & Time <span style="color:red;">*</span></label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <input type="date" id="wizardPickupDate" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:14px;">
                                    <input type="time" id="wizardPickupTime" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:14px;">
                                </div>
                                <small id="pickupDateTimeHelp">Select when you want to claim your document. Pick a valid date and time.</small>
                            </div>
                            <div class="form-group" style="margin-top:15px;">
                                <label>Additional Notes</label>
                                <textarea id="wizardNotes" rows="2" placeholder="Any additional information..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:14px; resize:vertical;"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wizard-nav">
                        <button class="btn-back" disabled style="opacity:0.5;">Back</button>
                        <button class="btn-next" id="wizardStep1Next" onclick="goToWizardStep(2)">Review & Submit <i class="fas fa-check"></i></button>
                    </div>
                </div>
                
                <!-- Step 2: Review & Submit -->
                <div class="wizard-step" data-step="2" id="wizardStep2">
                    <div id="confirmationDisplay">
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 30px; color: #2e7d32;"></i>
                            <p style="margin-top: 10px; color: #666;">Preparing confirmation...</p>
                        </div>
                    </div>
                    <div class="wizard-nav">
                        <button class="btn-back" onclick="goToWizardStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                        <button class="btn-submit" id="wizardSubmitBtn" onclick="submitWizardRequest()"><i class="fas fa-paper-plane"></i> Submit Request</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add quantity change listener to update total
    const quantityInput = document.getElementById('wizardQuantity');
    if (quantityInput) {
        quantityInput.addEventListener('change', function() {
            updateTotalAmount();
        });
        quantityInput.addEventListener('input', function() {
            updateTotalAmount();
        });
    }
    
    // Set default pickup date and time
    setDefaultPickupDateTime();
}

function updateTotalAmount() {
    const quantity = parseInt(document.getElementById('wizardQuantity')?.value || 1);
    const baseFee = DocumentModule.wizardData.selectedDoc?.fee || 0;
    const isFree = DocumentModule.wizardData.isFree || false;
    const totalAmount = isFree ? 0 : baseFee * quantity;
    DocumentModule.wizardData.paymentAmount = totalAmount;
    DocumentModule.wizardData.quantity = quantity;
    
    // Update banner fee display
    const feeDisplay = document.querySelector('.document-banner .doc-fee');
    if (feeDisplay) {
        if (isFree || totalAmount === 0) {
            feeDisplay.innerHTML = 'Fee: <strong style="color:#2e7d32;">FREE</strong>';
        } else {
            feeDisplay.innerHTML = `Fee: <strong style="color:#ff9800;">₱${totalAmount.toFixed(2)}</strong> (${quantity} copy${quantity > 1 ? 's' : ''})`;
        }
    }
}

// ============ WIZARD CORE FUNCTIONS ============

function showWizardModal(docId, docName, fee) {
    const modal = document.getElementById('requestModal');
    if (!modal) return;
    
    DocumentModule.wizardData.selectedDoc = {
        id: docId,
        name: docName,
        fee: fee
    };
    DocumentModule.currentBaseFee = fee;
    DocumentModule.currentDocName = docName;
    DocumentModule.currentDocId = docId;
    
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-magic"></i> Request Document';
    modal.style.display = 'flex';
    renderWizardContainer();
    addWizardStyles();
    
    loadWizardStep1();
}

async function goToWizardStep(step) {
    if (step > DocumentModule.wizardStep) {
        const canProceed = await validateCurrentStep(DocumentModule.wizardStep);
        if (!canProceed) return;
    }

    // If user goes back to step 1 while a payment poll is running, stop it
    if (step === 1 && __paymentPollActive) {
        stopPaymentPolling();
    }

    DocumentModule.wizardStep = step;
    updateWizardProgress(step);
    
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    const targetStep = document.getElementById(`wizardStep${step}`);
    if (targetStep) targetStep.classList.add('active');
    
    switch(step) {
        case 1: await loadWizardStep1(); break;
        case 2: await loadWizardStep2(); break;
    }
}

function updateWizardProgress(currentStep) {
    for (let i = 1; i <= 2; i++) {
        const circle = document.getElementById(`stepCircle${i}`);
        const label = document.getElementById(`stepLabel${i}`);
        if (!circle || !label) continue;
        
        circle.classList.remove('active', 'completed');
        label.classList.remove('active', 'completed');
        
        if (i < currentStep) {
            circle.classList.add('completed');
            label.classList.add('completed');
            circle.innerHTML = '<i class="fas fa-check"></i>';
        } else if (i === currentStep) {
            circle.classList.add('active');
            label.classList.add('active');
            circle.innerHTML = i;
        } else {
            circle.innerHTML = i;
        }
    }
}

async function validateCurrentStep(step) {
    switch(step) {
        case 1:
            // Check if the name in the form exists in barangay resident list
            const nameField = document.querySelector('#dynamicFieldsContainer input[name*="full_name"], #dynamicFieldsContainer input[name*="fullname"]');
            const isCohabitation = DocumentModule.wizardData.selectedDoc?.name === 'Certificate of Cohabitation';
            
            if (nameField && !isCohabitation) {
                const name = nameField.value.trim();
                if (name) {
                    const matchResult = await searchResidentList(name);
                    if (!matchResult) {
                        showWizardError('⚠️ The name you entered does not match any record in the barangay resident list. Please check the spelling.');
                        return false;
                    }
                }
            }
            
            const requiredFields = document.querySelectorAll('#dynamicFieldsContainer .form-group [required]');
            let allValid = true;
            requiredFields.forEach(field => {
                if (!field.value || field.value.trim() === '') {
                    field.style.borderColor = '#dc3545';
                    allValid = false;
                } else {
                    field.style.borderColor = '';
                }
            });
            if (!allValid) {
                showWizardError('Please fill in all required fields.');
                return false;
            }
            
            // Validate pickup date and time
            if (!validatePickupDateTime()) {
                return false;
            }
            
            const quantity = parseInt(document.getElementById('wizardQuantity')?.value || 1);
            if (isNaN(quantity) || quantity < 1 || quantity > 10) {
                showWizardError('Please enter a valid quantity (1-10).');
                return false;
            }
            DocumentModule.wizardData.quantity = quantity;
            return true;
        default:
            return true;
    }
}

function showWizardError(message) {
    const errorModal = document.getElementById('errorModal');
    if (errorModal) {
        document.getElementById('errorMessage').innerHTML = message;
        errorModal.style.display = 'flex';
        setTimeout(() => errorModal.style.display = 'none', 4000);
    } else {
        alert(message);
    }
}

// ============ RESIDENT LIST MATCH CHECK ============

async function checkResidentListMatch(field, value) {
    if (!value || value.trim() === '') {
        document.getElementById('residentListMatchStatus').innerHTML = '';
        return;
    }
    
    // Skip validation for Certificate of Cohabitation partner name fields
    const isCohabitation = DocumentModule.wizardData.selectedDoc?.name === 'Certificate of Cohabitation';
    if (isCohabitation && field && field.id && field.id.includes('partner')) {
        return;
    }
    
    try {
        const result = await searchResidentList(value.trim());
        const statusContainer = document.getElementById('residentListMatchStatus');
        
        if (result) {
            DocumentModule.wizardData.residentListData = result;
            
            statusContainer.innerHTML = `
                <div class="resident-list-match-banner">
                    <span class="match-icon"><i class="fas fa-check-circle"></i></span>
                    <span class="match-text">
                        ✅ Verified in barangay resident list
                    </span>
                    <span style="font-size:12px; color:#2e7d32; background:#c8e6c9; padding:2px 12px; border-radius:20px;">Verified</span>
                </div>
            `;
            
            autoFillFieldsFromResidentList(result);
            await detectResidentTypeFromResidentList(result);
            
        } else {
            DocumentModule.wizardData.residentListData = null;
            
            statusContainer.innerHTML = `
                <div class="resident-list-error-banner">
                    <span class="error-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <span class="error-text">
                        ⚠️ Information does not match any record in the barangay resident list.
                    </span>
                </div>
            `;
            
            DocumentModule.wizardData.residentType = 'regular';
            DocumentModule.wizardData.isFree = false;
            updateResidentTypeDisplay('regular', false);
        }
    } catch (error) {
        console.error('Error checking resident list:', error);
    }
}

function autoFillFieldsFromResidentList(data) {
    const allFields = document.querySelectorAll('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea');
    
    const fieldMappings = {
        'full_name': data.full_name || '',
        'first_name': data.first_name || '',
        'last_name': data.last_name || '',
        'middle_name': data.middle_name || '',
        'ext': data.ext || '',
        'place_of_birth': data.place_of_birth || '',
        'date_of_birth': data.date_of_birth || '',
        'age': data.age || '',
        'sex': data.sex || '',
        'civil_status': data.civil_status || '',
        'citizenship': data.citizenship || '',
        'occupation': data.occupation || '',
        'employment_status': data.employment_status || '',
        'address': data.address || '',
        'purok': data.purok || ''
    };
    
    allFields.forEach(field => {
        const fieldName = field.name || '';
        const fieldId = field.id || '';
        
        for (const [key, value] of Object.entries(fieldMappings)) {
            if (fieldName.includes(key) || fieldId.includes(key) || fieldName === `custom_fields[${key}]`) {
                if (value && value !== '') {
                    if (field.tagName === 'SELECT') {
                        const options = field.options;
                        for (let i = 0; i < options.length; i++) {
                            if (options[i].value === value || options[i].text === value) {
                                field.value = options[i].value;
                                break;
                            }
                        }
                    } else if (field.type === 'date') {
                        let dateStr = value;
                        if (dateStr && /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
                            const parts = dateStr.split('-');
                            field.value = parts[2] + '/' + parts[1] + '/' + parts[0];
                        } else if (dateStr) {
                            field.value = formatDateDisplay(dateStr);
                        }
                    } else if (field.type === 'text' && (fieldName.includes('sex') || fieldName.includes('gender'))) {
                        field.value = formatGenderDisplay(value);
                    } else {
                        field.value = value;
                    }
                    field.dispatchEvent(new Event('change'));
                    field.dispatchEvent(new Event('input'));
                }
            }
        }
    });
    
    // Store all custom field values for review
    DocumentModule.wizardData.allCustomFields = {};
    allFields.forEach(field => {
        const fieldName = field.name || '';
        const fieldId = field.id || '';
        const value = field.value || '';
        if (fieldName && value) {
            DocumentModule.wizardData.allCustomFields[fieldName] = value;
        }
        if (fieldId && value) {
            DocumentModule.wizardData.allCustomFields[fieldId] = value;
        }
    });
    
    const dobField = document.querySelector('#dynamicFieldsContainer input[type="date"][name*="birthdate"], #dynamicFieldsContainer input[type="text"][name*="birthdate"]');
    if (dobField && data.date_of_birth) {
        setTimeout(() => {
            dobField.dispatchEvent(new Event('change'));
        }, 300);
    }
}

function updateResidentTypeDisplay(type, isFree) {
    const container = document.getElementById('residentTypeDisplay');
    if (!container) return;
    
    const typeConfig = {
        'regular': { label: 'Regular Resident', icon: 'fa-user', color: '#0d47a1', bg: '#e3f2fd' },
        'student': { label: 'Student', icon: 'fa-graduation-cap', color: '#1b5e20', bg: '#e8f5e9' },
        'senior': { label: 'Senior Citizen', icon: 'fa-user-plus', color: '#e65100', bg: '#fff3e0' }
    };
    
    const config = typeConfig[type] || typeConfig.regular;
    const freeBadge = isFree ? '<span style="background:#ff9800; color:white; padding:2px 12px; border-radius:20px; font-size:11px; margin-left:8px;">FREE</span>' : '';
    const explanation = isFree ? 'You qualify for FREE document processing.' : 'Standard fees apply.';
    
    container.innerHTML = `
        <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
            <div class="resident-type-badge ${type}" style="background:${config.bg}; color:${config.color};">
                <i class="fas ${config.icon}"></i> ${config.label}
                ${freeBadge}
            </div>
            <div style="flex:1; font-size:13px; color:#555;"><i class="fas fa-info-circle" style="color:${config.color};"></i> ${explanation}</div>
            <div style="font-size:12px; color:#999; background:white; padding:5px 15px; border-radius:20px; border:1px solid #eef2ef;">
                ${isFree ? '✅ Auto-approval eligible' : 'ℹ️ Payment required'}
            </div>
        </div>
    `;
}

async function detectResidentTypeFromResidentList(data) {
    let type = 'regular';
    let isFree = false;
    
    try {
        const age = parseInt(data.age) || 0;
        const employmentStatus = (data.employment_status || '').toLowerCase();
        const occupation = (data.occupation || '').toLowerCase();
        
        if (age >= 60) {
            type = 'senior';
            isFree = true;
        } else if (
            employmentStatus.includes('student') || occupation.includes('student') ||
            occupation.includes('studying') || occupation.includes('college') ||
            occupation.includes('university') || occupation.includes('school')
        ) {
            type = 'student';
            isFree = true;
        } else {
            type = 'regular';
            isFree = false;
        }
        
        DocumentModule.wizardData.residentType = type;
        DocumentModule.wizardData.isFree = isFree;
        updateResidentTypeDisplay(type, isFree);
        updateTotalAmount();
        
    } catch (error) {
        DocumentModule.wizardData.residentType = 'regular';
        DocumentModule.wizardData.isFree = false;
        updateResidentTypeDisplay('regular', false);
        updateTotalAmount();
    }
}

// ============ WIZARD STEP FUNCTIONS ============

async function loadWizardStep1() {
    await loadDocumentFieldsForWizard();
    await detectResidentType();
}

async function loadDocumentFieldsForWizard() {
    const docId = DocumentModule.wizardData.selectedDoc?.id;
    if (!docId) return;
    
    try {
        const response = await fetch(`ajax_handler.php?action=get_document_fields&document_id=${docId}`);
        const data = await response.json();
        if (data.success) {
            DocumentModule.wizardData.selectedFields = data.fields;
            renderWizardDynamicFields(data.fields);
            setTimeout(autoFillWizardFields, 500);
        }
    } catch (error) {
        console.error('Error loading document fields:', error);
        document.getElementById('dynamicFieldsContainer').innerHTML = `
            <div style="background:#fff3cd; padding:15px; border-radius:10px; color:#856404;">
                <i class="fas fa-exclamation-triangle"></i> Could not load document fields.
            </div>
        `;
    }
}

function renderWizardDynamicFields(fields) {
    const container = document.getElementById('dynamicFieldsContainer');
    if (!container) return;
    
    if (!fields || fields.length === 0) {
        container.innerHTML = '<p style="color:#999; font-size:13px;">No additional information required for this document.</p>';
        return;
    }
    
    const isCohabitation = DocumentModule.wizardData.selectedDoc?.name === 'Certificate of Cohabitation';
    
    let html = '';
    fields.forEach(field => {
        const required = field.field_required ? 'required' : '';
        const requiredStar = field.field_required ? '<span style="color:red;">*</span>' : '';
        
        let fieldName = field.field_name || '';
        let isPartnerField = isCohabitation && (fieldName.includes('partner') || fieldName.includes('spouse'));
        
        if (field.field_type === 'children_list') {
            html += `<div class="form-group" data-field-type="children">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            html += `<div id="children-field-${escapeHtml(field.field_name)}" class="children-dynamic-field"></div>`;
            html += `</div>`;
        } else {
            html += `<div class="form-group">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            
            switch(field.field_type) {
                case 'textarea':
                    html += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" ${required} rows="3" id="field_${escapeHtml(field.field_name)}" placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}"></textarea>`;
                    break;
                case 'select':
                    html += `<select name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    html += `<option value="">Select ${escapeHtml(field.field_label)}</option>`;
                    if (field.field_options) {
                        const options = typeof field.field_options === 'string' ? JSON.parse(field.field_options) : field.field_options;
                        options.forEach(option => {
                            html += `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`;
                        });
                    }
                    html += `</select>`;
                    break;
                case 'date':
                    html += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    break;
                case 'email':
                    html += `<input type="email" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" placeholder="example@email.com">`;
                    break;
                case 'number':
                    html += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" placeholder="Enter number">`;
                    break;
                default:
                    html += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}">`;
            }
            
            html += `</div>`;
        }
    });
    
    container.innerHTML = html;
    
    fields.forEach(field => {
        if (field.field_type === 'children_list') {
            const childContainer = document.getElementById(`children-field-${escapeHtml(field.field_name)}`);
            if (childContainer) {
                childContainer.innerHTML = `
                    <div class="children-field-container" data-field-name="${escapeHtml(field.field_name)}">
                        <div id="children-list-${escapeHtml(field.field_name)}" class="children-list"></div>
                        <button type="button" class="add-child-btn" data-field="${escapeHtml(field.field_name)}" style="margin-top:10px; padding:8px 15px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Child
                        </button>
                        <input type="hidden" name="custom_fields[${escapeHtml(field.field_name)}]" id="children-data-${escapeHtml(field.field_name)}" value="[]">
                    </div>
                `;
                initChildrenFieldDynamic(escapeHtml(field.field_name));
            }
        }
    });
    
    setupAutoAgeCalculation();
    
    // Add real-time validation for name field (skip for Certificate of Cohabitation partner fields)
    const nameFields = document.querySelectorAll('#dynamicFieldsContainer input[name*="full_name"], #dynamicFieldsContainer input[name*="fullname"]');
    nameFields.forEach(field => {
        if (isCohabitation && field.id && field.id.includes('partner')) {
            return;
        }
        field.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value) {
                checkResidentListMatch(this, value);
            }
        });
    });
    
    // Store all field values on change for review
    const allFields = document.querySelectorAll('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea');
    allFields.forEach(field => {
        field.addEventListener('change', function() {
            const fieldName = this.name || this.id || '';
            const value = this.value || '';
            if (fieldName && value) {
                DocumentModule.wizardData.allCustomFields[fieldName] = value;
            }
        });
        field.addEventListener('input', function() {
            const fieldName = this.name || this.id || '';
            const value = this.value || '';
            if (fieldName && value) {
                DocumentModule.wizardData.allCustomFields[fieldName] = value;
            }
        });
    });
}

async function autoFillWizardFields() {
    const profile = await getResidentProfile();
    if (!profile) return;
    
    const isCohabitation = DocumentModule.wizardData.selectedDoc?.name === 'Certificate of Cohabitation';
    
    const fieldMappings = {
        'full_name': profile.full_name || '',
        'first_name': profile.first_name || '',
        'last_name': profile.last_name || '',
        'middle_name': profile.middle_name || '',
        'ext': profile.ext || '',
        'place_of_birth': profile.place_of_birth || '',
        'date_of_birth': profile.date_of_birth || '',
        'age': profile.age || '',
        'sex': profile.sex || '',
        'civil_status': profile.civil_status || '',
        'citizenship': profile.citizenship || '',
        'occupation': profile.occupation || '',
        'employment_status': profile.employment_status || '',
        'address': profile.address || '',
        'purok': profile.purok || ''
    };
    
    const allFields = document.querySelectorAll('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea');
    
    allFields.forEach(field => {
        const fieldName = field.name || '';
        const fieldId = field.id || '';
        
        // Skip auto-filling partner fields for cohabitation
        if (isCohabitation && (fieldId && fieldId.includes('partner'))) {
            return;
        }
        
        for (const [key, value] of Object.entries(fieldMappings)) {
            if (fieldName.includes(key) || fieldId.includes(key) || fieldName === `custom_fields[${key}]`) {
                if (value && value !== '') {
                    if (field.tagName === 'SELECT') {
                        const options = field.options;
                        for (let i = 0; i < options.length; i++) {
                            if (options[i].value === value || options[i].text === value) {
                                field.value = options[i].value;
                                break;
                            }
                        }
                    } else if (field.type === 'date') {
                        let dateStr = value;
                        if (dateStr && /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
                            const parts = dateStr.split('-');
                            field.value = parts[2] + '/' + parts[1] + '/' + parts[0];
                        } else if (dateStr) {
                            field.value = formatDateDisplay(dateStr);
                        }
                    } else if (field.type === 'text' && (fieldName.includes('sex') || fieldName.includes('gender'))) {
                        field.value = formatGenderDisplay(value);
                    } else {
                        field.value = value;
                    }
                    field.dispatchEvent(new Event('change'));
                    field.dispatchEvent(new Event('input'));
                    
                    // Store in custom fields
                    const name = field.name || field.id || '';
                    if (name && value) {
                        DocumentModule.wizardData.allCustomFields[name] = value;
                    }
                }
            }
        }
    });
    
    const nameField = document.querySelector('#dynamicFieldsContainer input[name*="full_name"], #dynamicFieldsContainer input[name*="fullname"]');
    if (nameField && nameField.value.trim() && !isCohabitation) {
        setTimeout(() => {
            checkResidentListMatch(nameField, nameField.value.trim());
        }, 800);
    }
}

async function detectResidentType() {
    const container = document.getElementById('residentTypeDisplay');
    if (!container) return;
    
    const profile = await getResidentProfile();
    if (!profile) {
        container.innerHTML = `<div style="background:#fff3cd; padding:15px; border-radius:10px; text-align:center; color:#856404;">
            Could not detect resident type. Defaulting to Regular.
        </div>`;
        DocumentModule.wizardData.residentType = 'regular';
        DocumentModule.wizardData.isFree = false;
        updateResidentTypeDisplay('regular', false);
        updateTotalAmount();
        return;
    }
    
    let type = 'regular';
    let isFree = false;
    
    try {
        const age = parseInt(profile.age) || 0;
        const employmentStatus = (profile.employment_status || '').toLowerCase();
        const occupation = (profile.occupation || '').toLowerCase();
        
        if (age >= 60) {
            type = 'senior';
            isFree = true;
        } else if (
            employmentStatus.includes('student') || occupation.includes('student') ||
            occupation.includes('studying') || occupation.includes('college') ||
            occupation.includes('university') || occupation.includes('school')
        ) {
            type = 'student';
            isFree = true;
        } else {
            type = 'regular';
            isFree = false;
        }
        
        DocumentModule.wizardData.residentType = type;
        DocumentModule.wizardData.isFree = isFree;
        updateResidentTypeDisplay(type, isFree);
        updateTotalAmount();
        
    } catch (error) {
        container.innerHTML = `<div style="background:#fff3cd; padding:15px; border-radius:10px; text-align:center; color:#856404;">
            Could not detect resident type. Defaulting to Regular.
        </div>`;
        DocumentModule.wizardData.residentType = 'regular';
        DocumentModule.wizardData.isFree = false;
        updateResidentTypeDisplay('regular', false);
        updateTotalAmount();
    }
}

// ============ STEP 2: REVIEW & SUBMIT ============

async function loadWizardStep2() {
    const container = document.getElementById('confirmationDisplay');
    if (!container) return;
    
    const selectedDoc = DocumentModule.wizardData.selectedDoc;
    const quantity = parseInt(document.getElementById('wizardQuantity')?.value || 1);
    const notes = document.getElementById('wizardNotes')?.value || '';
    const isFree = DocumentModule.wizardData.isFree;
    const residentType = DocumentModule.wizardData.residentType || 'regular';
    const residentListData = DocumentModule.wizardData.residentListData;
    const pickupDate = document.getElementById('wizardPickupDate')?.value || 'Not set';
    const pickupTime = document.getElementById('wizardPickupTime')?.value || 'Not set';
    
    let baseFee = selectedDoc?.fee || 0;
    const totalAmount = isFree ? 0 : baseFee * quantity;
    DocumentModule.wizardData.paymentAmount = totalAmount;
    
    // Update status label - removed "Auto-Approved" reference
    const statusLabel = isFree || totalAmount === 0 ? '✅ Free Document' : '⏳ Payment Required';
    const statusColor = isFree || totalAmount === 0 ? '#2e7d32' : '#ff9800';
    
    // Build custom fields HTML for review - get ALL fields from the form
    let customFieldsHtml = '';
    const allFields = document.querySelectorAll('#dynamicFieldsContainer .form-group');
    
    allFields.forEach(group => {
        const label = group.querySelector('label');
        const input = group.querySelector('input, select, textarea');
        const fieldType = group.querySelector('[data-field-type]');
        
        if (label && input) {
            let labelText = label.textContent.replace('*', '').trim();
            let value = input.value || 'Not provided';
            
            // Handle children list specially
            if (fieldType && fieldType.dataset.fieldType === 'children') {
                const hiddenInput = group.querySelector('input[type="hidden"]');
                if (hiddenInput && hiddenInput.value) {
                    try {
                        const children = JSON.parse(hiddenInput.value);
                        if (children && children.length > 0) {
                            let childrenHtml = '<div style="margin-top:5px;">';
                            children.forEach((child, idx) => {
                                childrenHtml += `
                                    <div style="background:white; padding:8px 12px; border-radius:6px; margin-bottom:5px; border:1px solid #eef2ef;">
                                        <strong>Child ${idx + 1}:</strong> 
                                        ${escapeHtml(child.full_name || 'N/A')} 
                                        ${child.age ? `(Age: ${escapeHtml(child.age)})` : ''}
                                        ${child.birthdate ? `Birthdate: ${escapeHtml(child.birthdate)}` : ''}
                                    </div>
                                `;
                            });
                            childrenHtml += '</div>';
                            value = childrenHtml;
                        } else {
                            value = 'No children added';
                        }
                    } catch (e) {
                        value = 'Unable to parse children data';
                    }
                } else {
                    value = 'No children added';
                }
            }
            
            // Format date display
            if (input.type === 'date' && value && value !== 'Not provided') {
                value = formatDateDisplay(value);
            }
            
            // Format gender display
            if (labelText.toLowerCase().includes('sex') || labelText.toLowerCase().includes('gender')) {
                value = formatGenderDisplay(value);
            }
            
            customFieldsHtml += `
                <div class="review-item">
                    <label>${escapeHtml(labelText)}</label>
                    <strong>${typeof value === 'string' ? escapeHtml(value) : value}</strong>
                </div>
            `;
        }
    });
    
    container.innerHTML = `
        <div style="text-align:center; margin-bottom:20px;">
            <i class="fas fa-clipboard-check" style="font-size:50px; color:#2e7d32;"></i>
            <h3 style="margin:10px 0 5px; color:#1a472a;">Review Your Request</h3>
            <p style="color:#666; font-size:13px;">Please review all details before submitting.</p>
        </div>
        
        <div class="review-grid">
            <div class="review-item full-width" style="border-left:4px solid #2e7d32;">
                <label>Document</label>
                <strong style="font-size:16px;">${escapeHtml(selectedDoc?.name || 'N/A')}</strong>
                <span style="color:#666; font-size:13px; margin-left:10px;">${isFree || totalAmount === 0 ? 'FREE' : '₱' + totalAmount.toFixed(2)}</span>
            </div>
            
            ${customFieldsHtml}
            
            <div class="review-item">
                <label>Resident Type</label>
                <strong>${residentType === 'senior' ? 'Senior Citizen' : residentType === 'student' ? 'Student' : 'Regular'} ${isFree ? '✅ FREE' : ''}</strong>
            </div>
            <div class="review-item">
                <label>Quantity</label>
                <strong>${quantity} copy/copies</strong>
            </div>
            <div class="review-item">
                <label>Pickup Date & Time</label>
                <strong>${pickupDate} at ${pickupTime}</strong>
            </div>
            ${residentListData ? `
            <div class="review-item full-width" style="border-left:4px solid #2e7d32;">
                <label>Verification Status</label>
                <strong style="color:#2e7d32;"><i class="fas fa-check-circle"></i> Verified in barangay resident list</strong>
            </div>
            ` : `
            <div class="review-item full-width" style="border-left:4px solid #dc3545; background:#f8d7da;">
                <label>Verification Status</label>
                <strong style="color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> Not verified</strong>
            </div>
            `}
            ${notes ? `
            <div class="review-item full-width">
                <label>Additional Notes</label>
                <p style="margin:5px 0 0; font-size:13px; color:#555;">${escapeHtml(notes)}</p>
            </div>
            ` : ''}
            <div class="review-item full-width" style="border-left:4px solid ${statusColor};">
                <label>Request Status</label>
                <strong style="color:${statusColor};">${statusLabel}</strong>
                ${isFree || totalAmount === 0 ? `
                    <p style="margin:5px 0 0; font-size:12px; color:#2e7d32;">
                        <i class="fas fa-check-circle"></i> No payment required for this document.
                    </p>
                ` : `
                    <p style="margin:5px 0 0; font-size:12px; color:#ff9800;">
                        <i class="fas fa-info-circle"></i> Payment is required to complete this request.
                    </p>
                `}
            </div>
        </div>
        ${isFree || totalAmount === 0 ? `
            <div style="background:#e8f5e9; padding:15px; border-radius:12px; margin-top:15px; border-left:4px solid #2e7d32;">
                <p style="margin:0; font-size:13px; color:#2e7d32;">
                    <i class="fas fa-check-circle"></i> This document is <strong>FREE</strong> based on your resident type (${residentType === 'senior' ? 'Senior Citizen' : residentType === 'student' ? 'Student' : 'Regular'}). Your request will be processed upon submission.
                </p>
            </div>
        ` : `
          
        `}
    `;
}

// ============ SUBMIT WIZARD REQUEST ============

let pendingPaymentRequest = null;

async function submitWizardRequest() {
    console.log('=== submitWizardRequest ===');
    console.log('isFree:', DocumentModule.wizardData.isFree);
    console.log('paymentAmount:', DocumentModule.wizardData.paymentAmount);
    console.log('residentType:', DocumentModule.wizardData.residentType);
    console.log('selectedDoc:', DocumentModule.wizardData.selectedDoc);
    
    const submitBtn = document.getElementById('wizardSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    const isFree = DocumentModule.wizardData.isFree || DocumentModule.wizardData.paymentAmount === 0;
    
    if (!isFree && DocumentModule.wizardData.paymentAmount > 0) {
        // Show payment modal
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        }
        showPaymentModal();
        return;
    }
    
    // Free doc — submit directly
    await executeSubmitRequest();
}

// ============ PAYMENT MODAL — Channel picker ============



function showPaymentModal() {
    const totalAmount = DocumentModule.wizardData.paymentAmount || 0;
    const docName = DocumentModule.wizardData.selectedDoc?.name || 'Document';

    const overlay = document.createElement('div');
    overlay.className = 'payment-modal-overlay';
    overlay.id = 'paymentModalOverlay';
    overlay.innerHTML = `
        <div class="payment-modal-content" style="max-width: 540px;">
            <div class="payment-modal-header">
                <i class="fas fa-credit-card" style="font-size: 42px; color: #2e7d32;"></i>
                <h3 style="margin: 12px 0 4px;">Choose Payment Method</h3>
                <p style="color: #666; font-size: 14px; margin: 0;">How would you like to pay?</p>
                <div class="amount" style="margin-top: 12px;">₱${totalAmount.toFixed(2)}</div>
                <p style="color: #999; font-size: 13px; margin-top: 5px;">${escapeHtml(docName)}</p>
            </div>

            <div style="padding: 10px 0;">
                <div class="pm-card" id="pmCardOnline" style="border: 2px solid #e2efe8; border-radius: 14px; margin-bottom: 12px; overflow: hidden; transition: all .2s;">
                    <div onclick="toggleOnlineMethods()" style="padding: 16px 18px; cursor: pointer; display: flex; align-items: center; gap: 14px; background: #fff;">
                        <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg,#0066B3,#00B4D8); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-mobile-alt" style="color: #fff; font-size: 22px;"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: #1a2b22; font-size: 15px;">Online Payment</div>
                            <div style="font-size: 12px; color: #888; margin-top: 2px;">GCash, Maya, QR Ph, Bank Transfer</div>
                        </div>
                        <i class="fas fa-chevron-down" id="pmOnlineChevron" style="color: #999; transition: transform .2s;"></i>
                    </div>

                    <div id="pmOnlineSub" style="display: none; padding: 12px 18px 16px; border-top: 1px solid #eef2ef; background: #fafbfa;">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #888; font-weight: 700; margin-bottom: 10px;">Select your wallet</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="pm-sub-option" onclick="selectSubMethod('GCASH')" data-method="GCASH" style="border: 2px solid #e2efe8; border-radius: 12px; padding: 12px; cursor: pointer; text-align: center; background: #fff; transition: all .15s;">
                                <div style="font-size: 22px; color: #0070E0; margin-bottom: 4px;"><i class="fas fa-wallet"></i></div>
                                <div style="font-weight: 700; font-size: 13px; color: #1a2b22;">GCash</div>
                            </div>
                            <div class="pm-sub-option" onclick="selectSubMethod('PAYMAYA')" data-method="PAYMAYA" style="border: 2px solid #e2efe8; border-radius: 12px; padding: 12px; cursor: pointer; text-align: center; background: #fff; transition: all .15s;">
                                <div style="font-size: 22px; color: #00A86B; margin-bottom: 4px;"><i class="fas fa-wallet"></i></div>
                                <div style="font-weight: 700; font-size: 13px; color: #1a2b22;">Maya</div>
                            </div>
                            <div class="pm-sub-option" onclick="selectSubMethod('QRPH')" data-method="QRPH" style="border: 2px solid #e2efe8; border-radius: 12px; padding: 12px; cursor: pointer; text-align: center; background: #fff; transition: all .15s;">
                                <div style="font-size: 22px; color: #1565c0; margin-bottom: 4px;"><i class="fas fa-qrcode"></i></div>
                                <div style="font-weight: 700; font-size: 13px; color: #1a2b22;">QR Ph</div>
                            </div>
                            <div class="pm-sub-option" onclick="selectSubMethod('BANK_TRANSFER')" data-method="BANK_TRANSFER" style="border: 2px solid #e2efe8; border-radius: 12px; padding: 12px; cursor: pointer; text-align: center; background: #fff; transition: all .15s;">
                                <div style="font-size: 22px; color: #f57c00; margin-bottom: 4px;"><i class="fas fa-university"></i></div>
                                <div style="font-weight: 700; font-size: 13px; color: #1a2b22;">Bank Transfer</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pm-card" id="pmCardClaim" onclick="selectPayAtClaim()" style="border: 2px solid #e2efe8; border-radius: 14px; padding: 16px 18px; cursor: pointer; display: flex; align-items: center; gap: 14px; background: #fff; transition: all .2s;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg,#ff9800,#f57c00); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="fas fa-building" style="color: #fff; font-size: 22px;"></i>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: #1a2b22; font-size: 15px;">Pay at Claim</div>
                        <div style="font-size: 12px; color: #888; margin-top: 2px;">Pay in cash at the Barangay Hall when claiming</div>
                    </div>
                    <i class="fas fa-chevron-right" style="color: #ccc;"></i>
                </div>
            </div>

            <div class="payment-modal-actions">
                <button class="btn-cancel-payment" onclick="closePaymentModal()">
                    <i class="fas fa-arrow-left"></i> Go Back
                </button>
                <button class="btn-pay" id="paymentModalPayBtn" onclick="processPaymentModal()" disabled style="opacity:.5; cursor:not-allowed;">
                    <i class="fas fa-check-circle"></i> Continue
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    DocumentModule.wizardData.paymentMethod = null;
    DocumentModule.wizardData.paymentChannel = null;
}

function toggleOnlineMethods() {
    const sub = document.getElementById('pmOnlineSub');
    const chev = document.getElementById('pmOnlineChevron');
    const isOpen = sub.style.display !== 'none';
    sub.style.display = isOpen ? 'none' : 'block';
    chev.style.transform = isOpen ? 'rotate(0)' : 'rotate(180deg)';
    document.getElementById('pmCardOnline').style.borderColor = isOpen ? '#e2efe8' : '#0066B3';
    if (!isOpen) document.getElementById('pmCardClaim').style.borderColor = '#e2efe8';
}

function selectSubMethod(method) {
    document.querySelectorAll('.pm-sub-option').forEach(el => {
        el.style.borderColor = '#e2efe8';
        el.style.background = '#fff';
    });
    const selected = document.querySelector(`.pm-sub-option[data-method="${method}"]`);
    if (selected) {
        selected.style.borderColor = '#0066B3';
        selected.style.background = '#e3f2fd';
    }

    DocumentModule.wizardData.paymentMethod = 'online';
    DocumentModule.wizardData.paymentChannel = method;

    const btn = document.getElementById('paymentModalPayBtn');
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    document.getElementById('pmCardClaim').style.borderColor = '#e2efe8';
}

function selectPayAtClaim() {
    DocumentModule.wizardData.paymentMethod = 'pay_at_claim';
    DocumentModule.wizardData.paymentChannel = null;

    document.querySelectorAll('.pm-sub-option').forEach(el => {
        el.style.borderColor = '#e2efe8';
        el.style.background = '#fff';
    });
    document.getElementById('pmCardOnline').style.borderColor = '#e2efe8';
    document.getElementById('pmCardClaim').style.borderColor = '#ff9800';

    const btn = document.getElementById('paymentModalPayBtn');
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';

    setTimeout(processPaymentModal, 250);
}

function closePaymentModal() {
    stopPaymentPolling();
    const overlay = document.getElementById('paymentModalOverlay');
    if (overlay) overlay.remove();
    goToWizardStep(1);
}   

function processPaymentModal() {
    const payBtn = document.getElementById('paymentModalPayBtn');
    if (payBtn) {
        payBtn.disabled = true;
        payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }

    const overlay = document.getElementById('paymentModalOverlay');
    if (overlay) overlay.remove();

    if (DocumentModule.wizardData.paymentMethod === 'pay_at_claim') {
        showLoading('Submitting your request');
        executeSubmitRequest();
    } else if (DocumentModule.wizardData.paymentMethod === 'online') {
        // No tab is opened here. The waiting page is shown first and the
        // resident opens the payment page themselves with the button.
        closePaymentTab();
        executeSubmitRequestWithPayment();
    }
}

async function executeSubmitRequestWithPayment() {
    try {
        showLoading('Preparing payment...');
        // STEP 1: Save draft to session (NOT to the database)
        const draftFormData = new FormData();
        draftFormData.append('action', 'save_draft_request');
        draftFormData.append('document_type', DocumentModule.wizardData.selectedDoc?.name || '');
        draftFormData.append('document_id', DocumentModule.wizardData.selectedDoc?.id || 0);
        draftFormData.append('fee_type', DocumentModule.wizardData.residentType || 'regular');
        draftFormData.append('fee', DocumentModule.wizardData.paymentAmount);
        draftFormData.append('quantity', parseInt(document.getElementById('wizardQuantity')?.value || 1));
        draftFormData.append('notes', document.getElementById('wizardNotes')?.value || '');
        draftFormData.append('payment_method', 'online');
        draftFormData.append('payment_channel', DocumentModule.wizardData.paymentChannel);
        draftFormData.append('purpose', `Request for ${DocumentModule.wizardData.selectedDoc?.name || 'Document'}`);
        draftFormData.append('pickup_date', document.getElementById('wizardPickupDate')?.value || '');
        draftFormData.append('pickup_time', document.getElementById('wizardPickupTime')?.value || '');

        document.querySelectorAll('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea').forEach(field => {
            if (field.name && field.type !== 'hidden') draftFormData.append(field.name, field.value);
        });
        document.querySelectorAll('#dynamicFieldsContainer input[type="hidden"][id*="children-data-"]').forEach(field => {
            if (field.name) draftFormData.append(field.name, field.value);
        });

        const draftResp = await fetch('ajax_handler.php', { method: 'POST', body: draftFormData });
        const draftData = await draftResp.json();

        if (!draftData.success) {
            hideLoading();
            closePaymentTab();
            showWizardError(draftData.message || 'Failed to prepare request.');
            return;
        }

        // STEP 2: Create the Xendit payment (server reads from session draft)
        showLoading('Preparing payment...');

        const payFormData = new FormData();
        payFormData.append('action', 'create_xendit_checkout');
        payFormData.append('channel', DocumentModule.wizardData.paymentChannel);

        const payResp = await fetch('ajax_handler.php', { method: 'POST', body: payFormData });
        const payData = await payResp.json();

        hideLoading();

        if (!payData.success) {
            closePaymentTab();
            showWizardError(payData.message || 'Failed to create payment.');
            return;
        }

        // GCash / Maya: keep the redirect URL; the resident opens it with the
        // button on the waiting page (see openPaymentPage).
        DocumentModule.paymentRedirectUrl = payData.redirect_url || null;

        showPaymentQRPage(payData.draft_token, payData);

    } catch (error) {
        hideLoading();
        closePaymentTab();
        console.error('Error:', error);
        showWizardError('Error: ' + error.message);
    }
}


async function saveDraftRequest() {
    // Helper used by executeSubmitRequestWithPayment — already inlined there.
    // Kept for external callers.
}

// ============ QR PAGE ============

let __paymentPollInterval = null;
let __qrCountdownInterval = null;

function showPaymentQRPage(draftToken, paymentData) {
    const channelLabels = {
        'GCASH': { name: 'GCash', color: '#0070E0', icon: 'fa-wallet' },
        'PAYMAYA': { name: 'Maya', color: '#00A86B', icon: 'fa-wallet' },
        'QRPH': { name: 'QR Ph', color: '#1565c0', icon: 'fa-qrcode' },
        'BANK_TRANSFER': { name: 'Bank Transfer', color: '#f57c00', icon: 'fa-university' }
    };
    const ch = channelLabels[paymentData.channel] || channelLabels['QRPH'];
    const requestId = draftToken; // keep local var name for template below

    const container = createPaymentWaitModal();
    container.innerHTML = `
        <div style="max-width: 440px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 30px rgba(0,0,0,.08);">
            <div style="background: linear-gradient(135deg, ${ch.color}, ${ch.color}dd); padding: 16px 20px; text-align: center; color: #fff;">
                <div style="font-size: 11px; opacity: .85; letter-spacing: .5px;">PAYMENT VIA</div>
                <div style="font-size: 20px; font-weight: 800; margin-top: 4px;">
                    <i class="fas ${ch.icon}"></i> ${ch.name}
                </div>
            </div>

            <div style="padding: 18px 20px 8px; text-align: center; border-bottom: 1px solid #eef2ef;">
                <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .5px;">Amount to Pay</div>
                <div style="font-size: 32px; font-weight: 800; color: #1a2b22; margin-top: 4px;">₱${parseFloat(paymentData.amount).toFixed(2)}</div>
                <div style="font-size: 11px; color: #999; margin-top: 4px;">Ref: #${requestId}</div>
            </div>

            <div style="padding: 24px 20px; text-align: center;">
                ${paymentData.qr_code_url && !paymentData.redirect_url ? `
                    <div style="display: inline-block; padding: 16px; background: #fff; border: 2px solid #eef2ef; border-radius: 14px;">
                        <img src="${paymentData.qr_code_url}" alt="Payment QR" style="width: 240px; height: 240px; display: block;">
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 14px; line-height: 1.5;">
                        <i class="fas fa-mobile-alt"></i> Open your <strong>${ch.name}</strong> app and scan this QR
                    </div>
                ` : `
                    <div style="padding: 30px 10px;">
                        <i class="fas fa-external-link-alt" style="font-size: 40px; color: ${ch.color}; margin-bottom: 14px; display: block;"></i>
                        <div style="font-size: 13px; color: #555; margin-bottom: 14px; line-height: 1.5;">
                            Click the button below to open the ${ch.name} payment page and complete your payment there.<br>
                            This page will update automatically.
                        </div>
                        <button type="button" onclick="openPaymentPage()" style="display: inline-block; padding: 10px 20px; background: ${ch.color}; color: #fff; border: none; cursor: pointer; border-radius: 10px; font-weight: 700; font-size: 13px;">
                            Open ${ch.name} payment page
                        </button>
                    </div>
                `}
            </div>

            <div style="padding: 0 20px 16px;">
                <div style="background: #f8faf9; border-radius: 12px; padding: 14px 16px; font-size: 12px; color: #555; line-height: 1.7;">
                    <div style="font-weight: 700; color: #1a2b22; margin-bottom: 6px;">
                        <i class="fas fa-info-circle"></i> How to pay
                    </div>
                    ${paymentData.qr_code_url && !paymentData.redirect_url ? `
                    <div>1. Open your <strong>${ch.name}</strong> app</div>
                    <div>2. Tap "Scan QR" or "Pay via QR"</div>
                    <div>3. Scan the QR code above</div>
                    <div>4. Confirm the amount and pay</div>
                    ` : `
                    <div>1. Click <strong>Open ${ch.name} payment page</strong></div>
                    <div>2. Log in, confirm the amount and proceed to pay</div>
                    <div>3. Come back here. This page updates by itself</div>
                    `}
                    <div style="margin-top: 8px; color: #2e7d32; font-weight: 600;">
                        <i class="fas fa-sync-alt fa-spin"></i> Waiting for payment...
                    </div>
                </div>
            </div>

            <div style="padding: 14px 20px 20px; border-top: 1px solid #eef2ef; display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 11px; color: #999;">
                    <i class="fas fa-clock"></i> Expires in <span id="qrExpiryCountdown" style="font-weight: 700; color: #c62828;">15:00</span>
                </div>
                <button onclick="checkPaymentNow('${requestId}')" style="background: #f0f4f9; border: none; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; color: #555; cursor: pointer;">
                    <i class="fas fa-redo"></i> Check Status
                </button>
            </div>

            <div style="padding: 0 20px 18px;">
                <div id="paymentPollWarning" style="display: none; background: #fff3e0; color: #e65100; border-radius: 10px; padding: 10px 12px; font-size: 12px; line-height: 1.5; margin-bottom: 10px;"></div>
                <button onclick="cancelPaymentWait()" style="width: 100%; background: transparent; border: 1px solid #ddd; color: #666; padding: 9px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Cancel payment
                </button>
            </div>
        </div>
    `;

    startPaymentPolling(draftToken);
    startQRExpiryCountdown(paymentData.expires_at);
}

// Polling is driven by a state flag, NOT by DOM presence.
// This survives any re-render of the wizard step, and only stops when:
//   1. Payment is confirmed (paid)
//   2. The wizard modal is closed
//   3. The user navigates away from step 2
let __paymentPollActive = false;
let __paymentPollToken = null;
let __paymentPollBusy = false;

function startPaymentPolling(draftToken) {
    // Kill any previous run
    __paymentPollActive = false;
    if (__paymentPollInterval) {
        clearInterval(__paymentPollInterval);
        __paymentPollInterval = null;
    }

    __paymentPollActive = true;
    __paymentPollToken = draftToken;

    __paymentPollInterval = setInterval(async () => {
        // Self-terminate if state says stop
        if (!__paymentPollActive || __paymentPollToken !== draftToken) {
            clearInterval(__paymentPollInterval);
            __paymentPollInterval = null;
            return;
        }

        if (__paymentPollBusy) return;   // previous check still running
        __paymentPollBusy = true;

        try {
            const resp = await fetch(
                `ajax_handler.php?action=check_payment_status&draft_token=${encodeURIComponent(draftToken)}`
            );
            const data = await resp.json();

            if (data.success && data.payment_status === 'paid') {
                __paymentPollActive = false;
                clearInterval(__paymentPollInterval);
                __paymentPollInterval = null;
                if (__qrCountdownInterval) {
                    clearInterval(__qrCountdownInterval);
                    __qrCountdownInterval = null;
                }
                showPaymentSuccess(data.request_id, data);
            } else if (data.success && ['failed', 'cancelled', 'expired'].includes(data.payment_status)) {
                handlePaymentFailed(data.payment_status);
            } else if (data.success === false) {
                console.warn('Payment status check:', data.message);
                showPaymentProblem(data.message);
            }
        } catch (e) {
            console.warn('Poll error:', e);
        } finally {
            __paymentPollBusy = false;
        }
    }, 3000);
}

function stopPaymentPolling() {
    __paymentPollActive = false;
    __paymentPollToken = null;

    if (__paymentPollInterval) {
        clearInterval(__paymentPollInterval);
        __paymentPollInterval = null;
    }
    if (__qrCountdownInterval) {
        clearInterval(__qrCountdownInterval);
        __qrCountdownInterval = null;
    }
}

async function checkPaymentNow(draftToken) {
    try {
        const resp = await fetch(
            `ajax_handler.php?action=check_payment_status&draft_token=${encodeURIComponent(draftToken)}`
        );
        const data = await resp.json();
        if (data.success && data.payment_status === 'paid') {
            stopPaymentPolling();
            showPaymentSuccess(data.request_id, data);
        } else {
            Swal.fire({
                icon: 'info',
                title: 'Not Yet Paid',
                text: 'We have not received your payment yet. Please complete it and try again.',
                confirmButtonColor: '#2e7d32',
                timer: 3000
            });
        }
    } catch (e) {
        showErrorModal('Could not check payment status.');
    }
}
function showPaymentSuccess(requestId, data) {
    __paymentPollActive = false;
    if (__qrCountdownInterval) clearInterval(__qrCountdownInterval);
    if (__paymentPollInterval) clearInterval(__paymentPollInterval);

    closePaymentTab();
    closePaymentWaitModal();

    const container = createReceiptModal();

    const docName = data.document_type || DocumentModule.wizardData.selectedDoc?.name || 'Document';
    const quantity = document.getElementById('wizardQuantity')?.value || 1;
    const residentType = DocumentModule.wizardData.residentType || 'regular';
    const residentTypeDisplay = residentType === 'senior' ? 'Senior Citizen'
                              : residentType === 'student' ? 'Student'
                              : 'Regular';
    const amount = '₱' + parseFloat(data.amount || DocumentModule.wizardData.paymentAmount || 0).toFixed(2);
    const dateToday = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    const timeNow = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    // Build QR image HTML (same as showWizardSuccess)
    let qrImageHtml = '';
    if (data.qr_code_path) {
        const fullQrPath = '../' + data.qr_code_path;
        qrImageHtml = `
            <div style="text-align: center; padding: 20px 0 15px 0; border-bottom: 1px solid #eef2ef;">
                <img src="${fullQrPath}" alt="QR Code"
                     style="width: 160px; height: 160px; border-radius: 12px; border: 2px solid #e8f5e9; padding: 8px; background: white;">
                <div style="font-size: 10px; color: #999; margin-top: 6px;">
                    <i class="fas fa-qrcode"></i> Scan to verify
                </div>
            </div>
        `;
    }

    container.innerHTML = `
        <div id="receiptContainer" style="max-width: 400px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 30px rgba(0,0,0,0.08);">

            <!-- Receipt Header -->
            <div style="background: linear-gradient(135deg, #1a472a, #2e7d32); padding: 14px 20px; text-align: center; color: white;">
                <div style="font-size: 18px; font-weight: 700; letter-spacing: 1px;">🏛️ BRITE</div>
                <div style="font-size: 10px; opacity: 0.85;">Barangay San Bartolome • Sto Tomas, Pampanga</div>
                <div style="font-size: 11px; margin-top: 4px; background: rgba(255,255,255,0.15); padding: 3px 14px; border-radius: 12px; display: inline-block; font-weight: 600;">
                    ✅ PAID & APPROVED
                </div>
            </div>

            <!-- QR Code Section -->
            ${qrImageHtml}

            <!-- Info Section -->
            <div style="padding: 16px 20px 12px;">
                <div style="background: #f8faf9; border-radius: 10px; padding: 12px 16px;">
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Document</span>
                        <span style="color: #1a472a; font-weight: 600; font-size: 13px;">${escapeHtml(docName)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Quantity</span>
                        <span style="color: #333; font-weight: 600; font-size: 13px;">${quantity} copy(s)</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Type</span>
                        <span style="color: #333; font-weight: 600; font-size: 13px;">${residentTypeDisplay}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0;">
                        <span style="color: #888; font-size: 12px;">Amount Paid</span>
                        <span style="color: #2e7d32; font-weight: 700; font-size: 15px;">${amount}</span>
                    </div>
                </div>

                <!-- Footer Info -->
                <div style="display: flex; justify-content: space-between; font-size: 9px; color: #bbb; padding: 8px 4px 0;">
                    <span>Ref: #${requestId}</span>
                    <span>${dateToday} ${timeNow}</span>
                </div>
            </div>

            <!-- Buttons -->
            <div style="padding: 0 20px 20px; display: flex; gap: 10px; flex-direction: column;">
                <button onclick="downloadReceipt()" style="background: linear-gradient(135deg, #1976d2, #1565c0); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; width: 100%;">
                    <i class="fas fa-download"></i> Download Receipt
                </button>
                <button onclick="closeWizardSuccess()" style="background: linear-gradient(135deg, #1a472a, #2e7d32); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; width: 100%;">
                    <i class="fas fa-check-circle"></i> Done
                </button>
            </div>
        </div>
    `;

    hideWizardBehindReceipt();

    const nav = document.querySelector('.wizard-nav');
    if (nav) nav.style.display = 'none';

    setTimeout(() => {
        if (typeof loadDashboard === 'function') loadDashboard();
    }, 2000);
}

function startQRExpiryCountdown(expiresAtIso) {
    if (__qrCountdownInterval) clearInterval(__qrCountdownInterval);

    const expiresAt = new Date(expiresAtIso).getTime();

    const update = () => {
        const el = document.getElementById('qrExpiryCountdown');
        if (!el) {
            // Countdown element gone — stop the countdown only.
            // Do NOT stop the payment poll — the poll is independent.
            clearInterval(__qrCountdownInterval);
            __qrCountdownInterval = null;
            return;
        }

        const diff = expiresAt - Date.now();
        if (diff <= 0) {
            el.textContent = 'Expired';
            el.style.color = '#999';
            clearInterval(__qrCountdownInterval);
            __qrCountdownInterval = null;
            // Note: we still let the poll run — the payment may still be
            // processing, and the server will reject the late success anyway.
            return;
        }
        const m = Math.floor(diff / 60000);
        const s = Math.floor((diff % 60000) / 1000);
        el.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    };
    update();
    __qrCountdownInterval = setInterval(update, 1000);
}
// Export new functions
window.toggleOnlineMethods = toggleOnlineMethods;
window.selectSubMethod = selectSubMethod;
window.selectPayAtClaim = selectPayAtClaim;
window.executeSubmitRequestWithPayment = executeSubmitRequestWithPayment;
window.showPaymentQRPage = showPaymentQRPage;
window.saveDraftRequest = saveDraftRequest;
window.startPaymentPolling = startPaymentPolling;
window.checkPaymentNow = checkPaymentNow;
window.showPaymentSuccess = showPaymentSuccess;
window.startQRExpiryCountdown = startQRExpiryCountdown;
window.stopPaymentPolling = stopPaymentPolling;

async function executeSubmitRequest() {
    const submitBtn = document.getElementById('wizardSubmitBtn');
    
    try {
        const formData = new FormData();
        formData.append('action', 'submit_request');
        formData.append('document_type', DocumentModule.wizardData.selectedDoc?.name || '');
        formData.append('document_id', DocumentModule.wizardData.selectedDoc?.id || 0);
        formData.append('fee_type', DocumentModule.wizardData.residentType || 'regular');
        formData.append('fee', DocumentModule.wizardData.isFree ? 0 : DocumentModule.wizardData.paymentAmount);
        formData.append('quantity', parseInt(document.getElementById('wizardQuantity')?.value || 1));
        formData.append('notes', document.getElementById('wizardNotes')?.value || '');
        formData.append('payment_method', DocumentModule.wizardData.paymentMethod || 'online');
        formData.append('purpose', `Request for ${DocumentModule.wizardData.selectedDoc?.name || 'Document'}`);
        formData.append('pickup_date', document.getElementById('wizardPickupDate')?.value || '');
        formData.append('pickup_time', document.getElementById('wizardPickupTime')?.value || '');
        
        // Get all custom fields from the form
        const customFields = document.querySelectorAll('#dynamicFieldsContainer input, #dynamicFieldsContainer select, #dynamicFieldsContainer textarea');
        customFields.forEach(field => {
            if (field.name) {
                let value = field.value;
                if (field.type === 'hidden' && field.id && field.id.startsWith('children-data-')) {
                    formData.append(field.name, value);
                } else if (field.type !== 'hidden') {
                    formData.append(field.name, value);
                }
            }
        });
        
        // Also get children data from hidden inputs
        const childrenHiddenInputs = document.querySelectorAll('#dynamicFieldsContainer input[type="hidden"][id*="children-data-"]');
        childrenHiddenInputs.forEach(field => {
            if (field.name) {
                formData.append(field.name, field.value);
            }
        });
        
        formData.append('custom_fields[detected_type]', DocumentModule.wizardData.residentType || 'regular');
        
        const response = await fetch('ajax_handler.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            hideLoading();
            // Show success with QR code
            showWizardSuccess(data);
            
            // ====== REMOVED THE AUTO-CLOSE TIMEOUT ======
            // The user will close the modal manually with the "Done" button
            // No automatic redirect/close
            
        } else {
            hideLoading();
            showWizardError(data.message || 'Failed to submit request.');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
            }
        }
    } catch (error) {
        hideLoading();
        console.error('Error submitting request:', error);
        showWizardError('Error submitting request. Please try again.');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        }
    }
}

function showWizardSuccess(data) {
    const container = createReceiptModal();
    
    const isFree = DocumentModule.wizardData.isFree || DocumentModule.wizardData.paymentAmount === 0;
    const autoApproved = data.auto_approved || isFree;
    const status = data.status || (autoApproved ? 'approved' : 'pending');
    const qrGenerated = data.qr_generated || false;
    const qrPath = data.qr_path || null;
    const documentGenerated = data.document_generated || false;
    const documentPath = data.document_path || null;
    
    const docName = DocumentModule.wizardData.selectedDoc?.name || '';
    const quantity = document.getElementById('wizardQuantity')?.value || 1;
    const residentType = DocumentModule.wizardData.residentType || 'Regular';
    const amount = isFree ? 'FREE' : '₱' + DocumentModule.wizardData.paymentAmount.toFixed(2);
    const residentTypeDisplay = residentType === 'senior' ? 'Senior Citizen' : residentType === 'student' ? 'Student' : 'Regular';
    const requestId = data.request_id || 'N/A';
    const dateToday = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    const timeNow = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    
    // Build QR Code image HTML if generated
    let qrImageHtml = '';
    let qrImageSrc = '';
    if (autoApproved && status === 'approved' && qrGenerated && qrPath) {
        const fullQrPath = '../' + qrPath;
        qrImageSrc = fullQrPath;
        qrImageHtml = `
            <div style="text-align: center; padding: 20px 0 15px 0; border-bottom: 1px solid #eef2ef;">
                <img src="${fullQrPath}" alt="QR Code" style="width: 160px; height: 160px; border-radius: 12px; border: 2px solid #e8f5e9; padding: 8px; background: white;">
                <div style="font-size: 10px; color: #999; margin-top: 6px;">
                    <i class="fas fa-qrcode"></i> Scan to verify
                </div>
            </div>
        `;
    }
    
    container.innerHTML = `
        <div id="receiptContainer" style="max-width: 400px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 30px rgba(0,0,0,0.08);">
            
            <!-- Receipt Header -->
            <div style="background: linear-gradient(135deg, #1a472a, #2e7d32); padding: 14px 20px; text-align: center; color: white;">
                <div style="font-size: 18px; font-weight: 700; letter-spacing: 1px;">🏛️ BRITE</div>
                <div style="font-size: 10px; opacity: 0.85;">Barangay San Bartolome • Sto Tomas, Pampanga</div>
                <div style="font-size: 11px; margin-top: 4px; background: rgba(255,255,255,0.15); padding: 3px 14px; border-radius: 12px; display: inline-block; font-weight: 600;">
                    ${autoApproved && status === 'approved' ? '✅ APPROVED' : '⏳ PENDING'}
                </div>
            </div>
            
            <!-- QR Code Section -->
            ${qrImageHtml}
            
            <!-- Info Section -->
            <div style="padding: 16px 20px 12px;">
                <div style="background: #f8faf9; border-radius: 10px; padding: 12px 16px;">
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Document</span>
                        <span style="color: #1a472a; font-weight: 600; font-size: 13px;">${escapeHtml(docName)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Quantity</span>
                        <span style="color: #333; font-weight: 600; font-size: 13px;">${quantity} copy(s)</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eef2ef;">
                        <span style="color: #888; font-size: 12px;">Type</span>
                        <span style="color: #333; font-weight: 600; font-size: 13px;">${residentTypeDisplay}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 5px 0;">
                        <span style="color: #888; font-size: 12px;">Amount</span>
                        <span style="color: ${isFree ? '#2e7d32' : '#ff9800'}; font-weight: 700; font-size: 15px;">${amount}</span>
                    </div>
                </div>
                
                <!-- Footer Info -->
                <div style="display: flex; justify-content: space-between; font-size: 9px; color: #bbb; padding: 8px 4px 0;">
                    <span>Ref: #${requestId}</span>
                    <span>${dateToday} ${timeNow}</span>
                </div>
            </div>
            
            <!-- Buttons -->
            <div style="padding: 0 20px 20px; display: flex; gap: 10px; flex-direction: column;">
                <button onclick="downloadReceipt()" style="background: linear-gradient(135deg, #1976d2, #1565c0); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; width: 100%;">
                    <i class="fas fa-download"></i> Download Receipt
                </button>
                <button onclick="closeWizardSuccess()" style="background: linear-gradient(135deg, #1a472a, #2e7d32); color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; width: 100%;">
                    <i class="fas fa-check-circle"></i> Done
                </button>
            </div>
        </div>
    `;
    
    hideWizardBehindReceipt();

    // Hide navigation buttons
    const submitBtn = document.getElementById('wizardSubmitBtn');
    if (submitBtn) submitBtn.style.display = 'none';
    const backBtn = document.querySelector('.wizard-nav .btn-back');
    if (backBtn) backBtn.style.display = 'none';
}

// ============ CLOSE WIZARD SUCCESS ============
function closeWizardSuccess() {
    closeReceiptModal();

    // Close the wizard (resets the form)
    closeRequestModal();
    
    // Reset wizard state for next request
    DocumentModule.wizardStep = 1;
    DocumentModule.wizardData.isSubmitting = false;
    
    // Refresh the current view after a small delay to allow modal to close
    setTimeout(() => {
        if (document.querySelector('[data-subview="history"]')?.classList.contains('active-sub')) {
            loadHistory();
        } else {
            loadDashboard();
        }
    }, 300);
}

// ============ DOWNLOAD QR CODE FROM DATA ============
function downloadReceipt() {
    const receiptElement = document.getElementById('receiptContainer');
    if (!receiptElement) {
        showErrorModal('Receipt not found.');
        return;
    }
    
    showLoading('Generating receipt image...');
    
    // Load html2canvas if not available
    if (typeof html2canvas === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = function() {
            captureReceipt(receiptElement);
        };
        script.onerror = function() {
            hideLoading();
            showErrorModal('Failed to load image generator. Please try again.');
        };
        document.head.appendChild(script);
    } else {
        captureReceipt(receiptElement);
    }
}

function captureReceipt(element) {
    html2canvas(element, {
        scale: 2,
        backgroundColor: '#ffffff',
        logging: false,
        useCORS: true,
        allowTaint: true,
        width: 420,
        height: element.scrollHeight    }).then(function(canvas) {
        const link = document.createElement('a');
        link.download = 'receipt_' + Date.now() + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
        hideLoading();
        showSuccessModal('Receipt downloaded successfully!');
    }).catch(function(error) {
        console.error('Error capturing receipt:', error);
        hideLoading();
        showErrorModal('Failed to download receipt. Please try again.');
    });
}

// ============ OPEN WIZARD FROM DOCUMENT CARD ============

function openDocumentWizard(docId, docName, fee) {
    console.log('📋 Opening wizard for:', docName, 'Fee:', fee);
    DocumentModule.wizardStep = 1;
    DocumentModule.wizardData = {
        residentInfo: null,
        residentType: 'regular',
        selectedDoc: { id: docId, name: docName, fee: fee },
        selectedFields: [],
        feeType: 'regular',
        quantity: 1,
        paymentMethod: 'online',
        paymentAmount: 0,
        isFree: false,
        autoApproved: false,
        documents: [],
        customData: {},
        residentListData: null,
        allCustomFields: {},
        isSubmitting: false
    };
    DocumentModule.currentBaseFee = fee;
    DocumentModule.currentDocName = docName;
    DocumentModule.currentDocId = docId;
    
    showWizardModal(docId, docName, fee);
}

// ============ ORIGINAL FUNCTIONS ============

function updateFeeDisplay() {
    const feeType = document.querySelector('input[name="fee_type"]:checked')?.value || 'regular';
    const quantity = parseInt(document.getElementById('quantity')?.value) || 1;
    
    let totalFee = 0;
    let displayText = '';
    
    if (feeType === 'regular') {
        totalFee = DocumentModule.currentBaseFee * quantity;
        displayText = `₱${totalFee.toFixed(2)}`;
    } else {
        totalFee = 0;
        displayText = '<span style="color:#ff9800; font-weight:bold;">FREE</span>';
    }
    
    const feeDisplay = document.getElementById('selectedDocFeeDisplay');
    const feeInput = document.getElementById('selectedDocFee');
    const priceEl = document.getElementById('regularPrice');
    
    if (feeDisplay) feeDisplay.innerHTML = displayText;
    if (feeInput) feeInput.value = totalFee;
    if (priceEl) priceEl.innerHTML = `₱${DocumentModule.currentBaseFee.toFixed(2)}`;
}

function renderDynamicFields(fields) {
    console.log('🔍 [DEBUG] renderDynamicFields called with', fields.length, 'fields');
    
    let html = '<div style="background:#f0f8ff; padding:15px; border-radius:12px; margin-bottom:20px;">';
    html += '<h4 style="color:#1a472a; margin-bottom:15px;"><i class="fas fa-clipboard-list"></i> Required Information</h4>';
    
    fields.forEach(field => {
        const required = field.field_required ? 'required' : '';
        const requiredStar = field.field_required ? '<span style="color:red;">*</span>' : '';
        
        if (field.field_type === 'children_list') {
            html += `<div class="form-group" data-field-type="children">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            html += `<div id="children-field-${escapeHtml(field.field_name)}" class="children-dynamic-field"></div>`;
            html += `</div>`;
        } else {
            html += `<div class="form-group">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            
            switch(field.field_type) {
                case 'textarea':
                    html += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" ${required} rows="3" id="field_${escapeHtml(field.field_name)}"></textarea>`;
                    break;
                case 'select':
                    html += `<select name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    html += `<option value="">Select ${escapeHtml(field.field_label)}</option>`;
                    if (field.field_options) {
                        const options = typeof field.field_options === 'string' ? JSON.parse(field.field_options) : field.field_options;
                        options.forEach(option => {
                            html += `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`;
                        });
                    }
                    html += `</select>`;
                    break;
                case 'date':
                    html += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    break;
                case 'email':
                    html += `<input type="email" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" placeholder="example@email.com">`;
                    break;
                case 'number':
                    html += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    break;
                default:
                    html += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}">`;
            }
            
            html += `</div>`;
        }
    });
    
    html += '</div>';
    document.getElementById('dynamicFieldsContainer').innerHTML = html;
    
    setupAutoAgeCalculation();
    
    fields.forEach(field => {
        if (field.field_type === 'children_list') {
            const container = document.getElementById(`children-field-${escapeHtml(field.field_name)}`);
            if (container) {
                container.innerHTML = `
                    <div class="children-field-container" data-field-name="${escapeHtml(field.field_name)}">
                        <div id="children-list-${escapeHtml(field.field_name)}" class="children-list"></div>
                        <button type="button" class="add-child-btn" data-field="${escapeHtml(field.field_name)}" style="margin-top:10px; padding:8px 15px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Child
                        </button>
                        <input type="hidden" name="custom_fields[${escapeHtml(field.field_name)}]" id="children-data-${escapeHtml(field.field_name)}" value="[]">
                    </div>
                `;
                initChildrenFieldDynamic(escapeHtml(field.field_name));
            }
        }
    });
    
    // Auto-fill after fields are rendered
    console.log('🔍 [DEBUG] Scheduling auto-fill in 600ms');
    setTimeout(autoFillProfileData, 600);
}

function renderDynamicFieldsForEdit(fields, customData) {
    console.log('🔍 [DEBUG] renderDynamicFieldsForEdit called with', fields.length, 'fields');
    
    let html = '<div style="background:#f0f8ff; padding:15px; border-radius:12px; margin-bottom:20px;">';
    html += '<h4 style="color:#1a472a; margin-bottom:15px;"><i class="fas fa-clipboard-list"></i> Required Information</h4>';
    
    fields.forEach(field => {
        const required = field.field_required ? 'required' : '';
        const requiredStar = field.field_required ? '<span style="color:red;">*</span>' : '';
        const existingValue = (customData && customData[field.field_name]) ? escapeHtml(customData[field.field_name]) : '';
        
        if (field.field_type === 'children_list') {
            html += `<div class="form-group" data-field-type="children">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            html += `<div id="children-field-${escapeHtml(field.field_name)}" class="children-dynamic-field"></div>`;
            html += `</div>`;
        } else {
            html += `<div class="form-group">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            
            switch(field.field_type) {
                case 'textarea':
                    html += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" ${required} rows="3" id="field_${escapeHtml(field.field_name)}">${existingValue}</textarea>`;
                    break;
                case 'select':
                    html += `<select name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}">`;
                    html += `<option value="">Select ${escapeHtml(field.field_label)}</option>`;
                    if (field.field_options) {
                        const options = typeof field.field_options === 'string' ? JSON.parse(field.field_options) : field.field_options;
                        options.forEach(option => {
                            const selected = (option === existingValue) ? 'selected' : '';
                            html += `<option value="${escapeHtml(option)}" ${selected}>${escapeHtml(option)}</option>`;
                        });
                    }
                    html += `</select>`;
                    break;
                case 'date':
                    html += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" value="${existingValue}">`;
                    break;
                case 'email':
                    html += `<input type="email" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" value="${existingValue}" placeholder="example@email.com">`;
                    break;
                case 'number':
                    html += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" value="${existingValue}">`;
                    break;
                default:
                    html += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} id="field_${escapeHtml(field.field_name)}" value="${existingValue}" placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}">`;
            }
            
            html += `</div>`;
        }
    });
    
    html += '</div>';
    document.getElementById('dynamicFieldsContainer').innerHTML = html;
    
    setupAutoAgeCalculation();
    
    fields.forEach(field => {
        if (field.field_type === 'children_list') {
            const container = document.getElementById(`children-field-${escapeHtml(field.field_name)}`);
            if (container) {
                let existingChildren = [];
                if (customData && customData.children) {
                    try {
                        existingChildren = typeof customData.children === 'string' ? JSON.parse(customData.children) : customData.children;
                    } catch(e) {
                        existingChildren = [];
                    }
                }
                
                container.innerHTML = `
                    <div class="children-field-container" data-field-name="${escapeHtml(field.field_name)}">
                        <div id="children-list-${escapeHtml(field.field_name)}" class="children-list"></div>
                        <button type="button" class="add-child-btn" data-field="${escapeHtml(field.field_name)}" style="margin-top:10px; padding:8px 15px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Child
                        </button>
                        <input type="hidden" name="custom_fields[${escapeHtml(field.field_name)}]" id="children-data-${escapeHtml(field.field_name)}" value='${JSON.stringify(existingChildren)}'>
                    </div>
                `;
                initChildrenFieldWithData(escapeHtml(field.field_name), existingChildren);
            }
        }
    });
}

async function autoFillProfileData() {
    console.log('🔍 [DEBUG] autoFillProfileData called...');
    
    const profileData = await getResidentProfile();
    console.log('🔍 [DEBUG] Profile Data received:', profileData);
    
    const notice = document.getElementById('profileNotice');
    const statusText = document.getElementById('profileStatusText');
    const verifiedBadge = document.querySelector('.profile-verified');
    
    if (!profileData) {
        console.log('❌ [DEBUG] No profile data found - hiding notice');
        if (notice) {
            notice.style.display = 'none';
        }
        return;
    }
    
    console.log('✅ [DEBUG] Profile data found, showing notice');
    
    if (notice) {
        notice.style.display = 'flex';
        notice.style.borderLeftColor = '#2e7d32';
        statusText.innerHTML = 'Your information has been pre-filled. Please review before submitting.';
        if (verifiedBadge) {
            verifiedBadge.innerHTML = '<i class="fas fa-check-circle"></i> Verified';
            verifiedBadge.style.background = '#d4edda';
            verifiedBadge.style.color = '#155724';
        }
    }
    
    function setFieldValue(field, value) {
        if (!field || value === undefined || value === null || value === '') return false;
        
        const tagName = field.tagName;
        const fieldType = field.type || '';
        const valueStr = String(value);
        
        if (tagName === 'SELECT') {
            const options = field.options;
            let matched = false;
            
            for (let i = 0; i < options.length; i++) {
                if (String(options[i].value) === valueStr) {
                    field.value = options[i].value;
                    matched = true;
                    break;
                }
            }
            
            if (!matched) {
                const lowerValue = valueStr.toLowerCase();
                for (let i = 0; i < options.length; i++) {
                    if (String(options[i].value).toLowerCase() === lowerValue) {
                        field.value = options[i].value;
                        matched = true;
                        break;
                    }
                    if (options[i].text.toLowerCase() === lowerValue) {
                        field.value = options[i].value;
                        matched = true;
                        break;
                    }
                }
            }
            
            if (matched) {
                field.dispatchEvent(new Event('change'));
                return true;
            }
            return false;
        } else if (fieldType === 'date') {
            try {
                let dateStr = valueStr.trim();
                if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
                    field.value = dateStr;
                } else if (/^(\d{2})\/(\d{2})\/(\d{4})$/.test(dateStr)) {
                    const m = dateStr.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                    field.value = m[3] + '-' + m[2] + '-' + m[1];
                } else {
                    const dateObj = new Date(dateStr);
                    if (!isNaN(dateObj.getTime())) {
                        field.value = dateObj.toISOString().split('T')[0];
                    } else {
                        field.value = dateStr;
                    }
                }
                field.dispatchEvent(new Event('change'));
                return true;
            } catch (e) {
                field.value = valueStr;
                return true;
            }
        } else if (fieldType === 'number') {
            const numValue = parseFloat(valueStr);
            if (!isNaN(numValue)) {
                field.value = numValue;
                field.dispatchEvent(new Event('input'));
                return true;
            }
            return false;
        } else {
            field.value = valueStr;
            field.dispatchEvent(new Event('input'));
            return true;
        }
    }
    
    const allFields = document.querySelectorAll('input:not([type="hidden"]), select, textarea');
    const fieldMap = {};
    
    allFields.forEach(field => {
        if (field.name) fieldMap[field.name] = field;
        if (field.id) fieldMap[field.id] = field;
    });
    
    const fieldMappings = {
        'full_name': ['full_name', 'custom_fields[full_name]', 'name', 'fullname'],
        'first_name': ['first_name', 'custom_fields[first_name]'],
        'last_name': ['last_name', 'custom_fields[last_name]'],
        'middle_name': ['middle_name', 'custom_fields[middle_name]'],
        'ext': ['ext', 'custom_fields[ext]', 'extension'],
        'place_of_birth': ['place_of_birth', 'custom_fields[place_of_birth]'],
        'date_of_birth': ['date_of_birth', 'custom_fields[date_of_birth]', 'birthdate', 'custom_fields[birthdate]', 'dob'],
        'age': ['age', 'custom_fields[age]'],
        'sex': ['sex', 'custom_fields[sex]', 'gender', 'custom_fields[gender]'],
        'civil_status': ['civil_status', 'custom_fields[civil_status]'],
        'citizenship': ['citizenship', 'custom_fields[citizenship]'],
        'occupation': ['occupation', 'custom_fields[occupation]'],
        'employment_status': ['employment_status', 'custom_fields[employment_status]'],
        'address': ['address', 'custom_fields[address]', 'household_address', 'house_number', 'custom_fields[house_number]'],
        'purok': ['purok', 'custom_fields[purok]']
    };
    
    Object.keys(fieldMappings).forEach(profileKey => {
        const value = profileData[profileKey];
        if (value === undefined || value === null || value === '') return;
        
        const fieldNames = fieldMappings[profileKey];
        for (const fieldName of fieldNames) {
            const field = fieldMap[fieldName];
            if (field) {
                const set = setFieldValue(field, value);
                if (set) break;
            }
        }
    });
}

function updatePaymentMethodInfo() {
    const method = document.getElementById('paymentMethodSelect')?.value;
    const infoDiv = document.getElementById('paymentMethodInfo');
    const infoText = document.getElementById('paymentMethodInfoText');
    
    if (!infoDiv || !infoText) return;
    
    if (method === 'online') {
        infoDiv.className = 'payment-method-info online';
        infoText.innerHTML = 'You will be redirected to complete payment online via GCash or Maya. Your request will be processed after payment confirmation.';
    } else if (method === 'pay_at_claim') {
        infoDiv.className = 'payment-method-info pay-at-claim';
        infoText.innerHTML = 'You will pay the document fee when you claim the document at Barangay Hall. Please bring exact amount and your valid ID.';
    } else {
        infoDiv.className = 'payment-method-info';
    }
}

// ============ DOCUMENT FUNCTIONS ============

async function loadDocuments() {
    DocumentModule.notificationCheckEnabled = true;
    
    // Show skeleton loading
    const body = document.getElementById('dashboardBody');
    if (body) {
        body.innerHTML = `
            <div style="display: grid; gap: 20px; padding: 10px 0;">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    ${Array(6).fill(0).map(() => `
                        <div class="skeleton" style="height: 180px; border-radius: 16px;"></div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    try {
        const response = await fetch('ajax_handler.php?action=get_documents');
        const data = await response.json();
        if (data.success) {
            renderDocuments(data.documents);
        }
    } catch (error) {
        showErrorModal('Error loading documents');
    }
}

function renderDocuments(documents) {
    let cardsHtml = '';
    documents.forEach(doc => {
        cardsHtml += `
            <div class="doc-card" onclick="openDocumentWizard(${doc.id}, '${escapeHtml(doc.name)}', ${doc.fee})">
                <div class="doc-card-icon"><i class="fas ${getDocumentIcon(doc.name)}"></i></div>
                <div class="doc-card-content">
                    <div class="doc-card-title">${escapeHtml(doc.name)}</div>
                    <div class="doc-card-description">${escapeHtml(doc.description)}</div>
                    <div class="doc-card-fee">
                        <span class="fee-amount">₱${doc.fee.toFixed(2)}</span>
                        <span class="select-badge">Select <i class="fas fa-arrow-right"></i></span>
                    </div>
                </div>
            </div>
        `;
    });
    
    const html = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-file-alt"></i> Select Document Type</div>
            <div class="section-sub">Choose the document you want to request</div>
            <div class="info-card" style="margin-bottom:25px;">
                <h3><i class="fas fa-tag"></i> Fee & ID Requirements</h3>
                <ul>
                    <li><i class="fas fa-user"></i> <strong>Regular:</strong> Standard fee + Valid Government ID</li>
                    <li><i class="fas fa-graduation-cap"></i> <strong>Student:</strong> FREE + Valid Student ID required</li>
                    <li><i class="fas fa-user-plus"></i> <strong>Senior:</strong> FREE + Valid Senior ID required</li>
                </ul>
            </div>
            <div class="documents-grid">${cardsHtml}</div>
        </div>
    `;
    document.getElementById('dashboardBody').innerHTML = html;
}

function closeRequestModal() {
    // Kill any in-flight payment poll
    stopPaymentPolling();

    const modal = document.getElementById('requestModal');
    if (modal) modal.style.display = 'none';

    const form = document.getElementById('documentRequestForm');
    if (form) form.reset();

    const dyn = document.getElementById('dynamicFieldsContainer');
    if (dyn) dyn.innerHTML = '';

    DocumentModule.currentEditRequestId = null;

    const editInput = document.querySelector('input[name="edit_request_id"]');
    if (editInput) editInput.remove();

    const actionInput = document.querySelector('input[name="action"]');
    if (actionInput) actionInput.value = 'submit_request';

    const idDoc = document.getElementById('id_document');
    if (idDoc) idDoc.required = true;

    const submitBtn = document.querySelector('#documentRequestForm button[type="submit"]');
    if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
}

// ============ HISTORY FUNCTIONS ============

async function loadHistory() {
    DocumentModule.notificationCheckEnabled = false;
    
    // Show skeleton loading
    const body = document.getElementById('dashboardBody');
    if (body) {
        body.innerHTML = `
            <div style="display: grid; gap: 15px; padding: 10px 0;">
                ${Array(3).fill(0).map(() => `
                    <div class="skeleton" style="height: 80px; border-radius: 12px;"></div>
                `).join('')}
            </div>
        `;
    }
    
    try {
        const response = await fetch('ajax_handler.php?action=get_requests');
        const data = await response.json();
        if (data.success) {
            renderHistory(data.requests);
        }
    } catch (error) {
        showErrorModal('Error loading history');
    }
}

function renderHistory(requests) {
    if (requests.length === 0) {
        document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-history"></i> Request History</div>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No document requests yet.</p></div>
            </div>`;
        return;
    }

    DocumentModule.allRequests = requests;
    
    const documentTypes = [...new Set(requests.map(r => r.document_type))];
    
    let documentTypeOptions = '<option value="">All Document Types</option>';
    documentTypes.forEach(type => {
        documentTypeOptions += `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`;
    });
    
    // Show ALL requests by default
    let filteredRequests = [...requests];
    
    let requestsHtml = '';
    filteredRequests.forEach(request => {
        requestsHtml += generateRequestCardHtml(request);
    });
    
    const noRequestsMessage = filteredRequests.length === 0 ? 
        '<div class="empty-state"><i class="fas fa-inbox"></i><p>No requests found.</p></div>' : '';
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-history"></i> Request History</div>
            <div class="section-sub">View and manage your document requests</div>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div style="flex: 2; min-width: 200px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-search"></i> Search Requests
                        </label>
                        <input type="text" id="searchRequestsInput" placeholder="Search by document type, requestor name, or status..." 
                            style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div style="flex: 1; min-width: 180px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-filter"></i> Filter by Status
                        </label>
                       <select id="statusFilterSelect" style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
    <option value="all" selected>All Requests</option>
    <option value="approved">Approved</option>
    <option value="unclaimed">Unclaimed</option>
    <option value="claimed">Claimed</option>
    <option value="rejected">Rejected</option>
</select>
                    </div>
                    <div style="flex: 1; min-width: 180px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-file-alt"></i> Filter by Document Type
                        </label>
                        <select id="docTypeFilterSelect" style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                            ${documentTypeOptions}
                        </select>
                    </div>
                    <div>
                        <button id="clearFiltersBtn" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
                            <i class="fas fa-eraser"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="filter-buttons" style="margin-bottom: 20px;">
    <button class="filter-btn active" onclick="filterRequests('all')">All Requests</button>
    <button class="filter-btn" onclick="filterRequests('approved')">Approved</button>
    <button class="filter-btn" onclick="filterRequests('unclaimed')">Unclaimed</button>
    <button class="filter-btn" onclick="filterRequests('claimed')">Claimed</button>
    <button class="filter-btn" onclick="filterRequests('rejected')">Rejected</button>
</div>
            
            <div id="requestsContainer">
                ${noRequestsMessage}
                ${requestsHtml}
            </div>
        </div>
    `;
    
    const searchInput = document.getElementById('searchRequestsInput');
    const statusFilter = document.getElementById('statusFilterSelect');
    const docTypeFilter = document.getElementById('docTypeFilterSelect');
    const clearBtn = document.getElementById('clearFiltersBtn');
    
    if (searchInput) {
        searchInput.removeEventListener('input', applyFiltersAndSearch);
        searchInput.addEventListener('input', applyFiltersAndSearch);
    }
    
    if (statusFilter) {
        statusFilter.removeEventListener('change', function() {});
        statusFilter.addEventListener('change', function() {
            applyFiltersAndSearch();
            const selectedStatus = this.value;
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.textContent.toLowerCase().includes(selectedStatus) || 
                    (selectedStatus === 'all' && btn.textContent === 'All Requests')) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        });
    }
    
    if (docTypeFilter) {
        docTypeFilter.removeEventListener('change', applyFiltersAndSearch);
        docTypeFilter.addEventListener('change', applyFiltersAndSearch);
    }
    
    if (clearBtn) {
        clearBtn.removeEventListener('click', function() {});
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = 'all';
            if (docTypeFilter) docTypeFilter.value = '';
            applyFiltersAndSearch();
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.textContent === 'All Requests') {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        });
    }
}

function applyFiltersAndSearch() {
    if (!DocumentModule.allRequests) return;
    
    const searchTerm = document.getElementById('searchRequestsInput')?.value.toLowerCase() || '';
    const statusFilter = document.getElementById('statusFilterSelect')?.value || 'all';
    const docTypeFilter = document.getElementById('docTypeFilterSelect')?.value || '';
    
    let filteredRequests = [...DocumentModule.allRequests];
    
        if (statusFilter !== 'all') {
        // All statuses are now stored directly in DB (pending, approved, unclaimed, claimed, rejected)
        filteredRequests = filteredRequests.filter(r => r.status === statusFilter);
    }
    
    if (docTypeFilter) {
        filteredRequests = filteredRequests.filter(r => r.document_type === docTypeFilter);
    }
    
    if (searchTerm) {
        const residentName = window.resident?.name || '';
        filteredRequests = filteredRequests.filter(r => {
            let requestorName = residentName;
            if (r.custom_data && r.custom_data.full_name) {
                requestorName = r.custom_data.full_name;
            } else if (r.custom_data && r.custom_data.fullname) {
                requestorName = r.custom_data.fullname;
            } else if (r.custom_data && r.custom_data['full name']) {
                requestorName = r.custom_data['full name'];
            }
            
            return r.document_type.toLowerCase().includes(searchTerm) ||
                requestorName.toLowerCase().includes(searchTerm) ||
                r.status.toLowerCase().includes(searchTerm);
        });
    }
    
    const container = document.getElementById('requestsContainer');
    if (!container) return;
    
    if (filteredRequests.length === 0) {
        container.innerHTML = `<div class="empty-state"><i class="fas fa-search"></i><p>No requests match your search criteria.</p></div>`;
    } else {
        let requestsHtml = '';
        filteredRequests.forEach(request => {
            requestsHtml += generateRequestCardHtml(request);
        });
        container.innerHTML = requestsHtml;
    }
}

function filterRequests(status) {
    const statusFilter = document.getElementById('statusFilterSelect');
    if (statusFilter) {
        statusFilter.value = status;
    }
    
    applyFiltersAndSearch();
    
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if ((status === 'all' && btn.textContent === 'All Requests') ||
            (status !== 'all' && btn.textContent.toLowerCase().includes(status))) {
            btn.classList.add('active');
        }
    });
}

// ============ REQUEST CARD FUNCTIONS ============

function generateRequestCardHtml(request) {
    // Status now comes directly from DB (pending, approved, unclaimed, claimed, rejected)
    const displayStatus = request.status;
    
    let statusClass = '', statusIcon = '';
    switch(displayStatus) {
        case 'approved':  statusClass = 'status-approved';  statusIcon = '<i class="fas fa-check-circle"></i> ';   break;
        case 'rejected':  statusClass = 'status-rejected';  statusIcon = '<i class="fas fa-times-circle"></i> ';   break;
        case 'unclaimed': statusClass = 'status-unclaimed'; statusIcon = '<i class="fas fa-inbox"></i> ';          break;
        case 'claimed':   statusClass = 'status-claimed';   statusIcon = '<i class="fas fa-check-double"></i> ';   break;
        default:          statusClass = 'status-approved';  statusIcon = '<i class="fas fa-check-circle"></i> ';   break;
    }
    
    const feeDisplay = request.fee == 0 ? '<span style="color:#ff9800; font-weight:bold;">FREE</span>' : `₱${request.fee.toFixed(2)}`;
    const totalAmount = (request.fee || 0) * (request.quantity || 1);
    
    let paymentStatusHtml = '';
    let paymentActionHtml = '';
    let qrCodeHtml = '';
    const isFree = (request.fee_type === 'student' || request.fee_type === 'senior' || request.fee == 0);

        // QR code available for approved or unclaimed documents (still claimable)
    if (request.status === 'approved' || request.status === 'unclaimed') {
        let paymentStatus = request.payment_status || 'not_created';
        
        if (request.payment_method === 'pay_at_claim') {
            paymentStatus = 'pay_at_claim';
        }
        
        if (paymentStatus === 'paid' || paymentStatus === 'pay_at_claim' || isFree || request.fee == 0) {
            qrCodeHtml = `<button class="qr-code-btn" onclick="event.stopPropagation(); showQRCodeModal(${request.id}, '${escapeHtml(request.document_type)}')">
                                <i class="fas fa-qrcode"></i> View QR Code
                            </button>`;
        }
    }

       if (isFree) {
        paymentStatusHtml = '<span class="status-badge" style="background: #e8f5e9; color: #2e7d32;"><i class="fas fa-gift"></i> FREE Document</span>';
    } else if (request.status === 'approved' || request.status === 'unclaimed') {
        let paymentStatus = request.payment_status || 'not_created';
        
        if (request.payment_method === 'pay_at_claim') {
            paymentStatus = 'pay_at_claim';
        }
        
        if (paymentStatus === 'paid') {
            paymentStatusHtml = '<span class="status-badge" style="background: #d4edda; color: #155724;"><i class="fas fa-check-circle"></i> Payment Completed</span>';
        } else if (paymentStatus === 'pay_at_claim') {
            paymentStatusHtml = '<span class="status-badge" style="background: #fff3cd; color: #856404;"><i class="fas fa-hand-holding-usd"></i> Payment Method: Pay at Claim</span>';
        } else {
            paymentStatusHtml = '<span class="status-badge status-pending"><i class="fas fa-credit-card"></i> Payment Required</span>';
            paymentActionHtml = `<button class="pay-now-btn" onclick="event.stopPropagation(); showPaymentOptions(${request.id}, '${escapeHtml(request.document_type)}', ${totalAmount})">
                                        <i class="fas fa-credit-card"></i> Pay Now
                                    </button>`;
        }
    } else if (request.status === 'claimed') {
        paymentStatusHtml = '<span class="status-badge" style="background: #d1ecf1; color: #0c5460;"><i class="fas fa-check-double"></i> Document Claimed</span>';
    }
    
    const residentName = window.resident?.name || '';
    let requestorName = residentName;
    if (request.custom_data && request.custom_data.full_name) {
        requestorName = request.custom_data.full_name;
    } else if (request.custom_data && request.custom_data.fullname) {
        requestorName = request.custom_data.fullname;
    } else if (request.custom_data && request.custom_data['full name']) {
        requestorName = request.custom_data['full name'];
    }
    
    const canEdit = false;
    const cancelButton = '';
    const editButton = '';   
    // Claim button removed - no manual claim
    
    const hasUnreadNotes = DocumentModule.currentUnreadNotes[request.id] === true;
    const notesIndicator = (request.admin_notes && request.admin_notes !== '' && hasUnreadNotes) ? 
        '<span class="admin-notes-indicator"><i class="fas fa-comment"></i> New response!</span>' : '';
    
    const notesStatus = (request.admin_notes && request.admin_notes !== '') ?
        `<div class="notes-read-status ${hasUnreadNotes ? 'unread' : 'read'}" data-id="${request.id}">
                <i class="fas ${hasUnreadNotes ? 'fa-envelope' : 'fa-check-circle'}"></i>
                ${hasUnreadNotes ? 'New response available' : 'Notes read'}
            </div>` : '';
    
    let customDataHtml = '';
    if (request.custom_data && Object.keys(request.custom_data).length > 0) {
        customDataHtml = `
            <div class="details-section">
                <h4><i class="fas fa-clipboard-list"></i> Additional Information</h4>
                <div class="details-grid">
                    ${Object.entries(request.custom_data).map(([key, value]) => {
                        if (key === 'full_name' || key === 'fullname' || key === 'full name') {
                            return '';
                        }
                        if (key === 'children') {
                            let childrenHtml = '<div class="detail-item-full"><label><i class="fas fa-child"></i> Children Information:</label><div class="children-list-details">';
                            
                            try {
                                let childrenArray = [];
                                
                                if (typeof value === 'string') {
                                    let jsonString = value;
                                    if (jsonString.startsWith('"') && jsonString.endsWith('"')) {
                                        jsonString = jsonString.slice(1, -1);
                                    }
                                    jsonString = jsonString.replace(/\\"/g, '"');
                                    childrenArray = JSON.parse(jsonString);
                                } else if (Array.isArray(value)) {
                                    childrenArray = value;
                                }
                                
                                if (Array.isArray(childrenArray) && childrenArray.length > 0) {
                                    childrenArray.forEach((child, idx) => {
                                        childrenHtml += `
                                            <div class="child-detail-card">
                                                <div class="child-number">Child ${idx + 1}</div>
                                                <div class="child-name"><strong>Name:</strong> ${escapeHtml(child.full_name || 'N/A')}</div>
                                                ${child.age ? `<div class="child-age"><strong>Age:</strong> ${escapeHtml(child.age)}</div>` : ''}
                                                ${child.birthdate ? `<div class="child-birthdate"><strong>Birthdate:</strong> ${escapeHtml(child.birthdate)}</div>` : ''}
                                            </div>
                                        `;
                                    });
                                } else {
                                    childrenHtml += '<div class="no-data">No children information available</div>';
                                }
                            } catch(e) {
                                console.error('Error parsing children:', e);
                                childrenHtml += '<div class="error-data">Unable to display children information</div>';
                            }
                            childrenHtml += '</div></div>';
                            return childrenHtml;
                        } else {
                            return `
                            <div class="detail-item">
                                <label>${escapeHtml(key.replace(/_/g, ' ').toUpperCase())}:</label>
                                <span>${escapeHtml(String(value))}</span>
                            </div>
                            `;
                        }
                    }).join('')}
                </div>
            </div>
            `;
    }
    
    return `
            <div class="request-card ${hasUnreadNotes ? 'has-new-notes' : ''}" data-id="${request.id}" data-status="${displayStatus}">
                <div class="request-card-header" onclick="toggleRequestDetails(${request.id})">
                    <div class="request-card-info">
                        <div class="request-type-badge">
                            <i class="fas ${getDocumentIcon(request.document_type)}"></i>
                            <span class="request-type-name">${escapeHtml(request.document_type)}</span>
                        </div>
                        <div class="status-badge ${statusClass}">${statusIcon} ${displayStatus.charAt(0).toUpperCase() + displayStatus.slice(1)}</div>
                        ${paymentStatusHtml}
                        ${notesIndicator}
                    </div>
                    <div class="request-card-meta">
                        <div class="requestor-name">
                            <i class="fas fa-user"></i> ${escapeHtml(requestorName)}
                        </div>
                        <div class="request-date-simple">
                            <i class="fas fa-calendar-alt"></i> ${new Date(request.request_date).toLocaleDateString()}
                        </div>
                        <div class="request-fee-simple">
                            <i class="fas fa-tag"></i> ${feeDisplay}
                        </div>
                        <i class="fas fa-chevron-down expand-icon" id="expandIcon-${request.id}"></i>
                    </div>
                </div>
                <div class="request-card-details" id="details-${request.id}" style="display: none;">
                    <div class="details-content">
                        <div class="details-section">
                            <h4><i class="fas fa-info-circle"></i> Request Information</h4>
                            <div class="details-grid">
                                <div class="detail-item">
                                    <label>Document Type:</label>
                                    <span>${escapeHtml(request.document_type)}</span>
                                </div>
                                <div class="detail-item">
                                    <label>Request Date:</label>
                                    <span>${new Date(request.request_date).toLocaleString()}</span>
                                </div>
                                <div class="detail-item">
                                    <label>Status:</label>
                                    <span class="status-badge ${statusClass}">${statusIcon} ${displayStatus.charAt(0).toUpperCase() + displayStatus.slice(1)}</span>
                                </div>
                                <div class="detail-item">
                                    <label>Quantity:</label>
                                    <span>${request.quantity} copy/copies</span>
                                </div>
                                <div class="detail-item">
                                    <label>Fee:</label>
                                    <span>${feeDisplay}</span>
                                </div>
                                ${request.fee_type ? `<div class="detail-item">
                                    <label>Fee Type:</label>
                                    <span>${escapeHtml(request.fee_type).charAt(0).toUpperCase() + escapeHtml(request.fee_type).slice(1)}</span>
                                </div>` : ''}
                                ${!isFree && request.fee > 0 ? `<div class="detail-item">
                                    <label>Total Amount:</label>
                                    <span style="font-weight: bold; color: #ff9800;">₱${totalAmount.toFixed(2)}</span>
                                </div>` : ''}
                            </div>
                        </div>
                        
                        ${customDataHtml}
                        
                        ${request.purpose && request.purpose !== 'Document Request' ? `
                        <div class="details-section">
                            <h4><i class="fas fa-bullhorn"></i> Purpose</h4>
                            <p>${escapeHtml(request.purpose)}</p>
                        </div>
                        ` : ''}
                        
                        ${request.notes ? `
                        <div class="details-section">
                            <h4><i class="fas fa-comment"></i> Additional Notes</h4>
                            <p>${escapeHtml(request.notes)}</p>
                        </div>
                        ` : ''}
                        
                        ${request.admin_notes ? `
                        <div class="details-section">
                            <h4><i class="fas fa-user-shield"></i> Admin Response</h4>
                            <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; border-left: 4px solid #2196f3;">
                                <p style="margin: 0; color: #1565c0;">${escapeHtml(request.admin_notes)}</p>
                                ${notesStatus}
                            </div>
                        </div>
                        ` : ''}
                        
                        ${request.processed_date ? `
                        <div class="details-section">
                            <h4><i class="fas fa-check-circle"></i> Processed Date</h4>
                            <p>${new Date(request.processed_date).toLocaleString()}</p>
                        </div>
                        ` : ''}
                        
                        <div class="details-actions">
                            ${editButton}
                            ${cancelButton}
                            ${paymentActionHtml}
                            ${qrCodeHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;
}

function toggleRequestDetails(requestId) {
    const detailsDiv = document.getElementById(`details-${requestId}`);
    const expandIcon = document.getElementById(`expandIcon-${requestId}`);
    
    if (detailsDiv.style.display === 'none') {
        detailsDiv.style.display = 'block';
        if (expandIcon) expandIcon.style.transform = 'rotate(180deg)';
        autoMarkAsRead(requestId);
    } else {
        detailsDiv.style.display = 'none';
        if (expandIcon) expandIcon.style.transform = 'rotate(0deg)';
    }
}

function showCancelConfirm(requestId, event) {
    event.stopPropagation();
    DocumentModule.pendingCancelRequestId = requestId;
    showConfirmModal(
        'Are you sure you want to cancel this request?',
        'This action will permanently delete the request and cannot be undone.',
        function() {
            executeCancelRequest(DocumentModule.pendingCancelRequestId);
        }
    );
}

async function executeCancelRequest(requestId) {
    const cancelBtn = document.querySelector(`.cancel-request-btn[data-id="${requestId}"]`);
    let originalText = '';
    if (cancelBtn) {
        originalText = cancelBtn.innerHTML;
        cancelBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
        cancelBtn.disabled = true;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel_request');
        formData.append('request_id', requestId);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Request cancelled and deleted successfully');
            loadHistory();
        } else {
            showErrorModal(data.message || 'Failed to cancel request');
            if (cancelBtn) {
                cancelBtn.innerHTML = originalText;
                cancelBtn.disabled = false;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error cancelling request. Please try again.');
        if (cancelBtn) {
            cancelBtn.innerHTML = originalText;
            cancelBtn.disabled = false;
        }
    }
    DocumentModule.pendingCancelRequestId = null;
}

async function editRequest(requestId, event) {
    event.stopPropagation();
    
    try {
        const response = await fetch(`ajax_handler.php?action=get_request_details&request_id=${requestId}`);
        const data = await response.json();
        
        if (data.success) {
            DocumentModule.currentEditRequestId = requestId;
            DocumentModule.currentDocId = data.request.document_type_id;
            DocumentModule.currentDocName = data.request.document_type;
            DocumentModule.currentBaseFee = data.request.fee / (data.request.quantity || 1);
            
            await loadDocumentFieldsForEdit(data.request);
        } else {
            showErrorModal(data.message || 'Failed to load request details');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error loading request for editing');
    }
}

async function loadDocumentFieldsForEdit(request) {
    try {
        const response = await fetch(`ajax_handler.php?action=get_document_fields&document_id=${request.document_type_id}`);
        const data = await response.json();
        if (data.success) {
            showEditRequestModal(request, data.fields);
        }
    } catch (error) {
        showErrorModal('Error loading form fields');
    }
}

function showEditRequestModal(request, fields) {
    document.getElementById('modalTitle').innerHTML = `Edit ${request.document_type} Request`;
    document.getElementById('selectedDocType').value = request.document_type;
    document.getElementById('selectedDocId').value = request.document_type_id;
    document.getElementById('selectedDocName').innerHTML = `<i class="fas ${getDocumentIcon(request.document_type)}"></i> ${request.document_type}`;
    
    const feePerCopy = request.fee / request.quantity;
    document.getElementById('selectedDocFeeDisplay').innerHTML = `₱${feePerCopy.toFixed(2)}`;
    document.getElementById('selectedDocFee').value = request.fee;
    document.getElementById('regularPrice').innerHTML = `₱${feePerCopy.toFixed(2)}`;
    
    document.getElementById('quantity').value = request.quantity;
    
    if (request.fee_type) {
        document.querySelector(`input[name="fee_type"][value="${request.fee_type}"]`).checked = true;
    }
    
    document.getElementById('notesField').value = request.notes || '';
    
    const feeType = request.fee_type || 'regular';
    const idLabel = document.getElementById('idLabel');
    const requirementText = document.getElementById('requirementText');
    const idUploadLabel = document.getElementById('idUploadLabel');
    
    if (feeType === 'regular') {
        idLabel.innerHTML = 'Valid Government ID';
        requirementText.innerHTML = 'Please upload a valid government-issued ID (e.g., Driver\'s License, Passport, Postal ID)';
        idUploadLabel.innerHTML = 'Upload New ID Document (Optional - leave empty to keep current)';
    } else if (feeType === 'student') {
        idLabel.innerHTML = 'Valid Student ID / School ID';
        requirementText.innerHTML = 'Please upload a valid Student ID or School ID for FREE processing';
        idUploadLabel.innerHTML = 'Upload New Student ID (Optional - leave empty to keep current)';
    } else if (feeType === 'senior') {
        idLabel.innerHTML = 'Valid Senior Citizen ID';
        requirementText.innerHTML = 'Please upload a valid Senior Citizen ID for FREE processing';
        idUploadLabel.innerHTML = 'Upload New Senior ID (Optional - leave empty to keep current)';
    }
    
    document.getElementById('id_document').required = false;
    
    renderDynamicFieldsForEdit(fields, request.custom_data);
    
    const submitBtn = document.querySelector('#documentRequestForm button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Request';
    
    let editInput = document.querySelector('input[name="edit_request_id"]');
    if (!editInput) {
        editInput = document.createElement('input');
        editInput.type = 'hidden';
        editInput.name = 'edit_request_id';
        document.getElementById('documentRequestForm').appendChild(editInput);
    }
    editInput.value = DocumentModule.currentEditRequestId;
    
    let actionInput = document.querySelector('input[name="action"]');
    if (actionInput) {
        actionInput.value = 'update_request';
    }
    
    document.getElementById('requestModal').style.display = 'flex';
}

// ============ QR CODE FUNCTIONS ============

async function showQRCodeModal(requestId, docType) {
    DocumentModule.currentQRRequestId = requestId;
    
    document.getElementById('qrCodeLoading').style.display = 'block';
    document.getElementById('qrCodeContent').style.display = 'none';
    document.getElementById('qrCodeError').style.display = 'none';
    
    const modal = document.getElementById('qrCodeModal');
    modal.style.display = 'flex';
    
    try {
        const response = await fetch(`ajax_handler.php?action=get_qr_code_info&request_id=${requestId}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('qrDocType').innerHTML = escapeHtml(data.request.document_type);
            document.getElementById('qrResidentName').innerHTML = escapeHtml(data.request.resident_name);
            document.getElementById('qrRequestDate').innerHTML = new Date(data.request.request_date).toLocaleDateString();
            
            if (data.qr_code_url) {
                DocumentModule.currentQRImageUrl = data.qr_code_url;
                document.getElementById('qrCodeDisplay').src = data.qr_code_url;
                document.getElementById('qrCodeLoading').style.display = 'none';
                document.getElementById('qrCodeContent').style.display = 'block';
            } else {
                document.getElementById('qrCodeLoading').style.display = 'none';
                document.getElementById('qrCodeError').style.display = 'block';
            }
        } else {
            document.getElementById('qrCodeLoading').style.display = 'none';
            document.getElementById('qrCodeError').style.display = 'block';
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('qrCodeLoading').style.display = 'none';
        document.getElementById('qrCodeError').style.display = 'block';
    }
}

function closeQRCodeModal() {
    const modal = document.getElementById('qrCodeModal');
    modal.style.display = 'none';
    DocumentModule.currentQRRequestId = null;
    DocumentModule.currentQRImageUrl = null;
}

async function downloadQRCodeCapture() {
    const captureArea = document.getElementById('qrCodeCaptureArea');
    if (!captureArea) {
        showErrorModal('Content not available');
        return;
    }
    
    showLoading('Generating image...');
    
    try {
        if (typeof html2canvas === 'undefined') {
            await loadHtml2Canvas();
        }
        
        const canvas = await html2canvas(captureArea, {
            scale: 2,
            backgroundColor: '#ffffff',
            logging: false,
            useCORS: true,
            allowTaint: false
        });
        
        const link = document.createElement('a');
        link.download = `document_qrcode_${DocumentModule.currentQRRequestId}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        
        hideLoading();
        showSuccessModal('QR Slip downloaded successfully!');
        
    } catch (error) {
        console.error('Error capturing image:', error);
        hideLoading();
        showErrorModal('Failed to generate image. Please try again.');
    }
}

function loadHtml2Canvas() {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = resolve;
        script.onerror = () => reject(new Error('Failed to load html2canvas'));
        document.head.appendChild(script);
    });
}

// ============ PAYMENT FUNCTIONS ============

async function showPaymentOptions(requestId, docName, totalAmount) {
    console.log('showPaymentOptions called:', {requestId, docName, totalAmount});
    
    DocumentModule.currentPaymentRequest = {
        id: requestId,
        docName: docName,
        totalAmount: totalAmount
    };
    
    const docNameSpan = document.getElementById('paymentDocName');
    const amountSpan = document.getElementById('paymentTotalAmount');
    
    if (docNameSpan) docNameSpan.innerHTML = docName;
    if (amountSpan) amountSpan.innerHTML = `₱${totalAmount.toFixed(2)}`;
    
    const modal = document.getElementById('paymentOptionsModal');
    if (modal) {
        modal.style.display = 'flex';
    } else {
        showErrorModal('Payment options modal not found');
    }
}

function closePaymentOptionsModal() {
    const modal = document.getElementById('paymentOptionsModal');
    if (modal) modal.style.display = 'none';
}

async function processPayment(method) {
    if (!DocumentModule.currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    DocumentModule.currentPaymentMethod = method;
    
    const confirmDocName = document.getElementById('confirmDocName');
    const confirmAmount = document.getElementById('confirmAmount');
    const confirmMethod = document.getElementById('confirmMethod');
    
    if (confirmDocName) confirmDocName.innerHTML = DocumentModule.currentPaymentRequest.docName;
    if (confirmAmount) confirmAmount.innerHTML = `₱${DocumentModule.currentPaymentRequest.totalAmount.toFixed(2)}`;
    if (confirmMethod) confirmMethod.innerHTML = method.toUpperCase();
    
    closePaymentOptionsModal();
    
    const modal = document.getElementById('paymentConfirmModal');
    if (modal) modal.style.display = 'flex';
}

function closePaymentConfirmModal() {
    const modal = document.getElementById('paymentConfirmModal');
    if (modal) modal.style.display = 'none';
}

async function confirmPayment() {
    if (!DocumentModule.currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    closePaymentConfirmModal();
    showLoading('Processing payment...');
    
    try {
        const formData = new FormData();
        formData.append('action', 'simulate_payment');
        formData.append('request_id', DocumentModule.currentPaymentRequest.id);
        formData.append('payment_method', DocumentModule.currentPaymentMethod);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('Payment response:', data);
        
        if (data.success) {
            showSuccessModal('Payment successful! Your document will be processed.');
            loadHistory();
        } else {
            showErrorModal(data.message || 'Failed to process payment');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error processing payment: ' + error.message);
    } finally {
        hideLoading();
        DocumentModule.currentPaymentRequest = null;
    }
}

function processPayAtClaim() {
    if (!DocumentModule.currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    const claimDocName = document.getElementById('claimDocName');
    const claimAmount = document.getElementById('claimAmount');
    
    if (claimDocName) claimDocName.innerHTML = DocumentModule.currentPaymentRequest.docName;
    if (claimAmount) claimAmount.innerHTML = `₱${DocumentModule.currentPaymentRequest.totalAmount.toFixed(2)}`;
    
    closePaymentOptionsModal();
    
    const modal = document.getElementById('payAtClaimModal');
    if (modal) modal.style.display = 'flex';
}

function closePayAtClaimModal() {
    const modal = document.getElementById('payAtClaimModal');
    if (modal) modal.style.display = 'none';
}

async function confirmPayAtClaim() {
    if (!DocumentModule.currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    closePayAtClaimModal();
    showLoading('Processing...');
    
    try {
        const formData = new FormData();
        formData.append('action', 'pay_at_claim');
        formData.append('request_id', DocumentModule.currentPaymentRequest.id);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('Pay at claim response:', data);
        
        if (data.success) {
            showSuccessModal(data.message);
            loadHistory();
        } else {
            showErrorModal(data.message || 'Failed to process pay at claim');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error processing payment: ' + error.message);
    } finally {
        hideLoading();
        DocumentModule.currentPaymentRequest = null;
    }
}

// ============ NOTIFICATION FUNCTIONS ============

async function checkForNewApprovals() {
    try {
        const response = await fetch('ajax_handler.php?action=get_stats');
        const data = await response.json();
        
        if (data.success) {
            const currentStats = data.stats;
            
            if (currentStats.approved > DocumentModule.lastCheckedStats.approved && DocumentModule.lastCheckedStats.approved !== 0) {
                const newApprovals = currentStats.approved - DocumentModule.lastCheckedStats.approved;
                showApprovalNotification(newApprovals);
            }
            
            DocumentModule.lastCheckedStats = currentStats;
        }
    } catch (error) {
        console.error('Error checking for new approvals:', error);
    }
}

function showApprovalNotification(count) {
    const existingBar = document.getElementById('approvalNotificationBar');
    if (existingBar) {
        existingBar.remove();
    }
    
    if (DocumentModule.notificationTimeout) {
        clearTimeout(DocumentModule.notificationTimeout);
    }
    
    const notificationBar = document.createElement('div');
    notificationBar.id = 'approvalNotificationBar';
    notificationBar.className = 'notification-bar success notification-pulse';
    notificationBar.innerHTML = `
            <div style="display: flex; align-items: center;">
                <i class="fas fa-check-circle notification-icon"></i>
                <div class="notification-content">
                    <div class="notification-title">🎉 New Request Approved!</div>
                    <div class="notification-message">${count} of your document request(s) have been approved. Click to view.</div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="notification-count">+${count}</span>
                <button class="notification-close" onclick="closeNotificationBar(event)">×</button>
            </div>
        `;
    
    const dashboardBody = document.getElementById('dashboardBody');
    const firstChild = dashboardBody.firstChild;
    dashboardBody.insertBefore(notificationBar, firstChild);
    
    DocumentModule.notificationTimeout = setTimeout(() => {
        const bar = document.getElementById('approvalNotificationBar');
        if (bar) {
            bar.style.animation = 'slideUp 0.3s ease';
            setTimeout(() => {
                if (bar && bar.parentNode) bar.remove();
            }, 300);
        }
    }, 10000);
    
    notificationBar.addEventListener('click', (e) => {
        if (!e.target.classList.contains('notification-close')) {
            document.querySelector('[data-subview="history"]').click();
            setTimeout(() => {
                filterRequests('approved');
                const approvedCards = document.querySelectorAll('.request-card[data-status="approved"]');
                if (approvedCards.length > 0) {
                    approvedCards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    approvedCards[0].style.boxShadow = '0 0 0 3px #28a745, 0 4px 20px rgba(0,0,0,0.15)';
                    setTimeout(() => {
                        approvedCards[0].style.boxShadow = '';
                    }, 3000);
                }
            }, 100);
        }
    });
}

function closeNotificationBar(event) {
    event.stopPropagation();
    const bar = document.getElementById('approvalNotificationBar');
    if (bar) {
        bar.style.animation = 'slideUp 0.3s ease';
        setTimeout(() => {
            if (bar && bar.parentNode) bar.remove();
        }, 300);
    }
    if (DocumentModule.notificationTimeout) {
        clearTimeout(DocumentModule.notificationTimeout);
    }
}

async function checkForNewNotes() {
    if (!DocumentModule.notificationCheckEnabled) return;
    
    try {
        const response = await fetch('ajax_handler.php?action=check_new_notes');
        const data = await response.json();
        
        if (data.success && data.unread_count > 0) {
            const unreadRequests = data.unread_requests;
            let hasNewUnread = false;
            
            unreadRequests.forEach(req => {
                if (!DocumentModule.currentUnreadNotes[req.id]) {
                    hasNewUnread = true;
                }
                DocumentModule.currentUnreadNotes[req.id] = true;
            });
            
            const activeSub = document.querySelector('[data-subview].active-sub');
            if (activeSub && activeSub.dataset.subview === 'history') {
                if (hasNewUnread) {
                    loadHistory();
                }
            } else if (hasNewUnread) {
                updateUnreadNotesIndicator(data.unread_count, unreadRequests);
            }
        }
    } catch (error) {
        console.error('Error checking for new notes:', error);
    }
}

function updateUnreadNotesIndicator(count, unreadRequests) {
    let notificationBadge = document.getElementById('unreadNotesBadge');
    window.unreadRequestsData = unreadRequests;
    
    if (!notificationBadge) {
        notificationBadge = document.createElement('div');
        notificationBadge.id = 'unreadNotesBadge';
        notificationBadge.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: #ff9800;
                color: white;
                border-radius: 30px;
                padding: 10px 15px;
                font-size: 12px;
                font-weight: bold;
                z-index: 1000;
                cursor: pointer;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideInRight 0.3s ease;
            `;
        notificationBadge.innerHTML = `<i class="fas fa-comment-dots"></i> ${count} new admin message(s)`;
        notificationBadge.onclick = () => {
            window.pendingUnreadRequests = unreadRequests;
            notificationBadge.style.display = 'none';
            document.querySelector('[data-subview="history"]').click();
        };
        document.body.appendChild(notificationBadge);
    } else {
        notificationBadge.innerHTML = `<i class="fas fa-comment-dots"></i> ${count} new admin message(s)`;
        notificationBadge.style.display = 'flex';
        notificationBadge.onclick = () => {
            window.pendingUnreadRequests = unreadRequests;
            notificationBadge.style.display = 'none';
            document.querySelector('[data-subview="history"]').click();
        };
    }
    
    setTimeout(() => {
        if (notificationBadge) {
            notificationBadge.style.opacity = '0';
            setTimeout(() => {
                if (notificationBadge && notificationBadge.style.display !== 'none') {
                    notificationBadge.style.display = 'none';
                }
            }, 300);
        }
    }, 10000);
}

async function autoMarkAsRead(requestId) {
    if (!DocumentModule.currentUnreadNotes[requestId]) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'mark_notes_read');
        formData.append('request_id', requestId);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            delete DocumentModule.currentUnreadNotes[requestId];
            
            const card = document.querySelector(`.request-card[data-id="${requestId}"]`);
            if (card) {
                card.classList.remove('has-new-notes');
                const notesIndicator = card.querySelector('.admin-notes-indicator');
                if (notesIndicator) {
                    notesIndicator.remove();
                }
            }
            
            const notesStatus = document.querySelector(`.notes-read-status[data-id="${requestId}"]`);
            if (notesStatus) {
                notesStatus.className = 'notes-read-status read';
                notesStatus.innerHTML = '<i class="fas fa-check-circle"></i> Notes read';
            }
        }
    } catch (error) {
        console.error('Error auto-marking notes as read:', error);
    }
}

function highlightRequestCard(requestId) {
    const requestCard = document.querySelector(`.request-card[data-id="${requestId}"]`);
    if (!requestCard) return false;
    
    document.querySelectorAll('.request-card').forEach(card => {
        card.style.transition = 'all 0.3s ease';
        card.style.boxShadow = '';
    });
    
    requestCard.style.boxShadow = '0 0 0 3px #ff9800, 0 4px 20px rgba(0,0,0,0.15)';
    requestCard.style.transition = 'all 0.3s ease';
    requestCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    let flashCount = 0;
    const flashInterval = setInterval(() => {
        if (flashCount >= 6) {
            clearInterval(flashInterval);
            requestCard.style.boxShadow = '';
            requestCard.style.backgroundColor = '';
            return;
        }
        requestCard.style.backgroundColor = flashCount % 2 === 0 ? '#fff8e1' : '';
        flashCount++;
    }, 300);
    
    return true;
}

// Add this function for skeleton loading
function showSkeletonLoading() {
    const body = document.getElementById('dashboardBody');
    if (!body) return;
    
    body.innerHTML = `
        <div style="display: grid; gap: 20px; padding: 10px 0;">
            <!-- Welcome skeleton -->
            <div class="skeleton" style="height: 120px; border-radius: 16px;"></div>
            
            <!-- Stats row skeleton -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                ${Array(4).fill(0).map(() => `
                    <div class="skeleton" style="height: 100px; border-radius: 16px;"></div>
                `).join('')}
            </div>
            
            <!-- Middle row skeleton -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                <div class="skeleton" style="height: 250px; border-radius: 16px;"></div>
                <div class="skeleton" style="height: 250px; border-radius: 16px;"></div>
            </div>
            
            <!-- Bottom row skeleton -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                ${Array(3).fill(0).map(() => `
                    <div class="skeleton" style="height: 200px; border-radius: 16px;"></div>
                `).join('')}
            </div>
        </div>
    `;
}

async function loadDashboard() {
    DocumentModule.notificationCheckEnabled = true;
    
    // Show skeleton loading immediately
    showSkeletonLoading();
    
    try {
        const response = await fetch('ajax_handler.php?action=get_stats');
        const data = await response.json();
        if (data.success) {
            if (data.stats.approved > DocumentModule.lastCheckedStats.approved && DocumentModule.lastCheckedStats.approved !== 0) {
                const newApprovals = data.stats.approved - DocumentModule.lastCheckedStats.approved;
                renderDashboard(data.stats);
                showApprovalNotification(newApprovals);
            } else {
                renderDashboard(data.stats);
            }
            DocumentModule.lastCheckedStats = data.stats;
        }
    } catch (error) {
        console.error('Error loading dashboard:', error);
        // Show error state
        document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card" style="padding: 40px; text-align: center;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #dc3545;"></i>
                <h3 style="color: #dc3545; margin-top: 15px;">Failed to load dashboard</h3>
                <p style="color: #666;">Please refresh the page or try again later.</p>
                <button onclick="loadDashboard()" style="margin-top: 15px; padding: 10px 30px; background: #2e7d32; color: white; border: none; border-radius: 8px; cursor: pointer;">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        `;
    }
}

function renderDashboard(stats) {
    const totalRequests = stats.total || 0;
    const approvedRequests = stats.approved || 0;   // only currently approved (not claimed)
    const rejectedRequests = stats.rejected || 0;
    const unclaimedRequests = stats.unclaimed || 0;
    const claimedRequests = stats.claimed || 0;
    
    const totalDocuments = totalRequests;
    // Success rate now includes approved + unclaimed + claimed (all non-pending, non-rejected)
    const successCount = approvedRequests + unclaimedRequests + claimedRequests;
    const approvalRate = totalDocuments > 0 ? Math.round((successCount / totalDocuments) * 100) : 0;

    let equipmentTotal = 0;
    let equipmentPending = 0;
    let equipmentApproved = 0;
    let equipmentRejected = 0;
    
    if (DocumentModule.equipmentBookingsData) {
        equipmentTotal = DocumentModule.equipmentBookingsData.length;
        equipmentPending = DocumentModule.equipmentBookingsData.filter(b => b.status === 'pending').length;
        equipmentApproved = DocumentModule.equipmentBookingsData.filter(b => b.status === 'approved' || b.status === 'borrowed').length;
        equipmentRejected = DocumentModule.equipmentBookingsData.filter(b => b.status === 'rejected').length;
    }

    const html = `
            <div class="content-card" style="padding: 20px; overflow: hidden;">
                
                <div class="welcome-card-modern">
                    <div class="welcome-content">
                        <h2>Welcome back, ${escapeHtml(window.resident?.name?.split(' ')[0] || '')}!</h2>
                        <p>Here's what's happening with your barangay requests today.</p>
                        <div class="welcome-stats-modern">
                            <div class="welcome-stat-item">
                                <span class="stat-value">${totalDocuments + equipmentTotal}</span>
                                <span class="stat-label">Total Requests</span>
                            </div>
                            <div class="welcome-stat-item">
                                <span class="stat-value">${equipmentApproved}</span>
                                <span class="stat-label">Active Bookings</span>
                            </div>
                            <div class="welcome-stat-item">
                                <span class="stat-value">${approvalRate}%</span>
                                <span class="stat-label">Success Rate</span>
                            </div>
                        </div>
                    </div>
                    <div class="welcome-icon">
                        <i class="fas fa-hand-peace"></i>
                    </div>
                </div>

                <div class="top-stats-row">
                    <div class="stat-card-modern">
                        <div class="stat-header">
                            <span class="stat-label">Total Documents</span>
                            <div class="stat-icon-box stat-icon-blue"><i class="fas fa-file-alt"></i></div>
                        </div>
                        <div class="stat-number">${totalDocuments}</div>
                        <div class="stat-change positive"><i class="fas fa-arrow-up"></i> From all requests</div>
                    </div>
                    
                    <div class="stat-card-modern">
                        <div class="stat-header">
                            <span class="stat-label">Awaiting Pickup</span>
                            <div class="stat-icon-box stat-icon-orange"><i class="fas fa-inbox"></i></div>
                        </div>
                        <div class="stat-number">${unclaimedRequests}</div>
                        <div class="stat-change negative"><i class="fas fa-clock"></i> Not yet claimed</div>
                    </div>
                    
                    <div class="stat-card-modern">
                        <div class="stat-header">
                            <span class="stat-label">Equipment Bookings</span>
                            <div class="stat-icon-box stat-icon-green"><i class="fas fa-tools"></i></div>
                        </div>
                        <div class="stat-number">${equipmentTotal}</div>
                        <div class="stat-change positive"><i class="fas fa-calendar-check"></i> Total reservations</div>
                    </div>
                    
                    <div class="stat-card-modern">
                        <div class="stat-header">
                            <span class="stat-label">Pending Bookings</span>
                            <div class="stat-icon-box stat-icon-purple"><i class="fas fa-hourglass-half"></i></div>
                        </div>
                        <div class="stat-number">${equipmentPending}</div>
                        <div class="stat-change negative"><i class="fas fa-spinner"></i> Pending approval</div>
                    </div>
                </div>

                <div class="middle-row">
                    <div class="announcement-card-modern">
                        <div class="announcement-header-modern">
                            <h4><i class="fas fa-bullhorn"></i> Announcements</h4>
                            <span class="badge">Latest</span>
                        </div>
                        <div class="carousel-container-modern">
                            <button class="carousel-nav-modern carousel-prev-modern" id="carouselPrevModern"><i class="fas fa-chevron-left"></i></button>
                            <div class="carousel-slides-modern" id="carouselSlidesModern">
                                <div class="carousel-slide-modern">
                                    <div class="carousel-content">
                                        <i class="fas fa-file-alt"></i>
                                        <h4>Document Requests</h4>
                                        <p>Submit document requests online. Processing takes 2-3 business days.</p>
                                    </div>
                                </div>
                                <div class="carousel-slide-modern">
                                    <div class="carousel-content">
                                        <i class="fas fa-tools"></i>
                                        <h4>Equipment Booking</h4>
                                        <p>Book barangay equipment and facilities for your events.</p>
                                    </div>
                                </div>
                                <div class="carousel-slide-modern">
                                    <div class="carousel-content">
                                        <i class="fas fa-credit-card"></i>
                                        <h4>Online Payments</h4>
                                        <p>Pay using GCash or Maya for faster processing.</p>
                                    </div>
                                </div>
                                <div class="carousel-slide-modern">
                                    <div class="carousel-content">
                                        <i class="fas fa-clock"></i>
                                        <h4>Office Hours</h4>
                                        <p>Mon-Fri: 8:00 AM - 5:00 PM</p>
                                    </div>
                                </div>
                            </div>
                            <button class="carousel-nav-modern carousel-next-modern" id="carouselNextModern"><i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div class="carousel-dots-modern" id="carouselDotsModern"></div>
                    </div>

                    <div class="donut-widget-modern">
                        <div class="donut-header">
                            <h4><i class="fas fa-chart-pie"></i> Equipment Distribution</h4>
                            <span class="badge-secondary">By status</span>
                        </div>
                        <div class="donut-container">
                            <div class="donut-ring">
                                <svg viewBox="0 0 100 100">
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#e8f5e9" stroke-width="12"/>
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#2e7d32" stroke-width="12"
                                            stroke-dasharray="${equipmentTotal > 0 ? (equipmentApproved / equipmentTotal) * 251.2 : 0} 251.2"
                                            stroke-dashoffset="0" class="donut-segment"/>
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#ff9800" stroke-width="12"
                                            stroke-dasharray="${equipmentTotal > 0 ? (equipmentPending / equipmentTotal) * 251.2 : 0} 251.2"
                                            stroke-dashoffset="${equipmentTotal > 0 ? -((equipmentApproved / equipmentTotal) * 251.2) : 0}" class="donut-segment"/>
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#d32f2f" stroke-width="12"
                                            stroke-dasharray="${equipmentTotal > 0 ? (equipmentRejected / equipmentTotal) * 251.2 : 0} 251.2"
                                            stroke-dashoffset="${equipmentTotal > 0 ? -(((equipmentApproved + equipmentPending) / equipmentTotal) * 251.2) : 0}" class="donut-segment"/>
                                </svg>
                            </div>
                            <div class="donut-legend">
                                <div class="donut-legend-item">
                                    <span><span class="color-dot" style="background:#2e7d32;"></span> Approved/Active</span>
                                    <span class="percent">${equipmentApproved} (${equipmentTotal > 0 ? Math.round((equipmentApproved / equipmentTotal) * 100) : 0}%)</span>
                                </div>
                                <div class="donut-legend-item">
                                    <span><span class="color-dot" style="background:#ff9800;"></span> Pending</span>
                                    <span class="percent">${equipmentPending} (${equipmentTotal > 0 ? Math.round((equipmentPending / equipmentTotal) * 100) : 0}%)</span>
                                </div>
                                <div class="donut-legend-item">
                                    <span><span class="color-dot" style="background:#d32f2f;"></span> Rejected/Cancelled</span>
                                    <span class="percent">${equipmentRejected} (${equipmentTotal > 0 ? Math.round((equipmentRejected / equipmentTotal) * 100) : 0}%)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bottom-row">
                    <div class="calendar-widget-modern">
                        <div class="cal-header">
                            <h4><i class="fas fa-calendar-alt"></i> Event Calendar</h4>
                            <div>
                                <button class="cal-nav-btn" id="calendarPrevBtn"><i class="fas fa-chevron-left"></i></button>
                                <span id="calendarMonthYear" style="font-size:0.75rem; font-weight:600; margin:0 8px;"></span>
                                <button class="cal-nav-btn" id="calendarNextBtn"><i class="fas fa-chevron-right"></i></button>
                            </div>
                        </div>
                        <div class="cal-weekdays">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="cal-days-grid" id="miniCalendarDays"></div>
                        <div class="calendar-legend" style="margin-top: 12px; font-size: 0.6rem; display: flex; gap: 12px; justify-content: center;">
                            <span><i class="fas fa-circle" style="color: #ff9800; font-size: 6px;"></i> Return date</span>
                            <span><i class="fas fa-circle" style="color: #28a745; font-size: 6px;"></i> Booking date</span>
                        </div>
                    </div>

                    <div class="donut-widget-modern">
                        <div class="donut-header">
                            <h4><i class="fas fa-chart-pie"></i> Document Distribution</h4>
                            <span class="badge-secondary">By status</span>
                        </div>
                        <div class="donut-container">
                            <div class="donut-ring">
                               <svg viewBox="0 0 100 100">
    <circle cx="50" cy="50" r="40" fill="none" stroke="#e8f5e9" stroke-width="12"/>
    <circle cx="50" cy="50" r="40" fill="none" stroke="#2e7d32" stroke-width="12"
            stroke-dasharray="${totalDocuments > 0 ? (approvedRequests / totalDocuments) * 251.2 : 0} 251.2"
            stroke-dashoffset="0" class="donut-segment"/>
    <circle cx="50" cy="50" r="40" fill="none" stroke="#ff9800" stroke-width="12"
            stroke-dasharray="${totalDocuments > 0 ? (unclaimedRequests / totalDocuments) * 251.2 : 0} 251.2"
            stroke-dashoffset="${totalDocuments > 0 ? -((approvedRequests / totalDocuments) * 251.2) : 0}" class="donut-segment"/>
    <circle cx="50" cy="50" r="40" fill="none" stroke="#6c757d" stroke-width="12"
            stroke-dasharray="${totalDocuments > 0 ? (claimedRequests / totalDocuments) * 251.2 : 0} 251.2"
            stroke-dashoffset="${totalDocuments > 0 ? -(((approvedRequests + unclaimedRequests) / totalDocuments) * 251.2) : 0}" class="donut-segment"/>

    <circle cx="50" cy="50" r="40" fill="none" stroke="#d32f2f" stroke-width="12"
            stroke-dasharray="${totalDocuments > 0 ? (rejectedRequests / totalDocuments) * 251.2 : 0} 251.2"
    stroke-dashoffset="${totalDocuments > 0 ? -(((approvedRequests + unclaimedRequests + claimedRequests) / totalDocuments) * 251.2) : 0}" class="donut-segment"/>
</svg>
                            </div>
                            <div class="donut-legend">
    <div class="donut-legend-item">
        <span><span class="color-dot" style="background:#2e7d32;"></span> Approved</span>
        <span class="percent">${approvedRequests} (${totalDocuments > 0 ? Math.round((approvedRequests / totalDocuments) * 100) : 0}%)</span>
    </div>
    <div class="donut-legend-item">
        <span><span class="color-dot" style="background:#ff9800;"></span> Unclaimed</span>
        <span class="percent">${unclaimedRequests} (${totalDocuments > 0 ? Math.round((unclaimedRequests / totalDocuments) * 100) : 0}%)</span>
    </div>
    <div class="donut-legend-item">
        <span><span class="color-dot" style="background:#6c757d;"></span> Claimed</span>
        <span class="percent">${claimedRequests} (${totalDocuments > 0 ? Math.round((claimedRequests / totalDocuments) * 100) : 0}%)</span>
    </div>

    <div class="donut-legend-item">
        <span><span class="color-dot" style="background:#d32f2f;"></span> Rejected</span>
        <span class="percent">${rejectedRequests} (${totalDocuments > 0 ? Math.round((rejectedRequests / totalDocuments) * 100) : 0}%)</span>
    </div>
</div>
                        </div>
                    </div>

                    <div class="quick-actions-modern">
                        <div class="quick-actions-header">
                            <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                            <i class="fas fa-ellipsis-h"></i>
                        </div>
                        <div class="quick-actions-grid">
                            <div class="quick-action-btn" onclick="document.querySelector('[data-subview=\\"request\\"]').click()">
                                <i class="fas fa-file-alt"></i>
                                <span>Request Document</span>
                            </div>
                            <div class="quick-action-btn" onclick="document.querySelector('[data-subview=\\"equipment_list\\"]').click()">
                                <i class="fas fa-tools"></i>
                                <span>Book Equipment</span>
                            </div>
                            <div class="quick-action-btn" onclick="document.querySelector('[data-subview=\\"history\\"]').click()">
                                <i class="fas fa-history"></i>
                                <span>View History</span>
                            </div>
                            <div class="quick-action-btn" onclick="document.querySelector('[data-subview=\\"equipment_bookings\\"]').click()">
                                <i class="fas fa-calendar-alt"></i>
                                <span>My Bookings</span>
                            </div>
                        </div>
                        <div class="quick-stats">
                            <div class="quick-stat">
                                <i class="fas fa-check-circle"></i>
                                <span>${approvedRequests} Approved</span>
                            </div>
                            <div class="quick-stat">
                                <i class="fas fa-clock"></i>
                                <span>${equipmentPending} Pending</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

    document.getElementById('dashboardBody').innerHTML = html;
    initMiniCalendar();
    initModernCarousel();
}

// ============ CAROUSEL FUNCTION ============

function initModernCarousel() {
    const slides = document.querySelectorAll('.carousel-slide-modern');
    const dotsContainer = document.getElementById('carouselDotsModern');
    const prevBtn = document.getElementById('carouselPrevModern');
    const nextBtn = document.getElementById('carouselNextModern');
    const slidesContainer = document.getElementById('carouselSlidesModern');
    
    if (!slides.length || !dotsContainer) return;
    
    dotsContainer.innerHTML = '';
    
    slides.forEach((_, index) => {
        const dot = document.createElement('div');
        dot.className = 'carousel-dot-modern';
        if (index === 0) dot.classList.add('active');
        dot.addEventListener('click', () => goToSlide(index));
        dotsContainer.appendChild(dot);
    });
    
    function goToSlide(index) {
        DocumentModule.modernCurrentSlide = index;
        if (slidesContainer) {
            slidesContainer.style.transform = `translateX(-${DocumentModule.modernCurrentSlide * 100}%)`;
        }
        document.querySelectorAll('.carousel-dot-modern').forEach((dot, i) => {
            dot.classList.toggle('active', i === DocumentModule.modernCurrentSlide);
        });
    }
    
    function nextSlide() {
        DocumentModule.modernCurrentSlide = (DocumentModule.modernCurrentSlide + 1) % slides.length;
        goToSlide(DocumentModule.modernCurrentSlide);
    }
    
    function prevSlide() {
        DocumentModule.modernCurrentSlide = (DocumentModule.modernCurrentSlide - 1 + slides.length) % slides.length;
        goToSlide(DocumentModule.modernCurrentSlide);
    }
    
    if (prevBtn) {
        prevBtn.removeEventListener('click', prevSlide);
        prevBtn.addEventListener('click', prevSlide);
    }
    if (nextBtn) {
        nextBtn.removeEventListener('click', nextSlide);
        nextBtn.addEventListener('click', nextSlide);
    }
    
    if (DocumentModule.modernSlideInterval) clearInterval(DocumentModule.modernSlideInterval);
    DocumentModule.modernSlideInterval = setInterval(nextSlide, 5000);
    
    const carouselContainer = document.querySelector('.carousel-container-modern');
    if (carouselContainer) {
        carouselContainer.removeEventListener('mouseenter', () => {});
        carouselContainer.removeEventListener('mouseleave', () => {});
        carouselContainer.addEventListener('mouseenter', () => clearInterval(DocumentModule.modernSlideInterval));
        carouselContainer.addEventListener('mouseleave', () => {
            DocumentModule.modernSlideInterval = setInterval(nextSlide, 5000);
        });
    }
    
    goToSlide(0);
}

// ============ MINI CALENDAR ============

async function loadEquipmentReturnDates() {
    try {
        const response = await fetch('resident_equipment_ajax.php?action=get_my_bookings');
        const data = await response.json();
        if (data.success && data.bookings) {
            DocumentModule.returnDates = [];
            DocumentModule.bookingDates = [];
            data.bookings.forEach(booking => {
                if (booking.status === 'approved' || booking.status === 'borrowed') {
                    if (booking.end_datetime) {
                        const returnDate = new Date(booking.end_datetime);
                        DocumentModule.returnDates.push(returnDate.toDateString());
                    }
                    if (booking.start_datetime) {
                        const startDate = new Date(booking.start_datetime);
                        DocumentModule.bookingDates.push(startDate.toDateString());
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error loading return dates:', error);
    }
}

function initMiniCalendar() {
    const calendarDays = document.getElementById('miniCalendarDays');
    if (!calendarDays) return;
    
    function renderCalendar() {
        const year = DocumentModule.currentCalendarDate.getFullYear();
        const month = DocumentModule.currentCalendarDate.getMonth();
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startingDay = firstDay.getDay();
        const daysInMonth = lastDay.getDate();
        
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        document.getElementById('calendarMonthYear').innerHTML = `${monthNames[month]} ${year}`;
        
        let html = '';
        
        for (let i = 0; i < startingDay; i++) {
            html += `<div class="mini-calendar-day other-month"></div>`;
        }
        
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateString = date.toDateString();
            const isToday = date.toDateString() === today.toDateString();
            const hasReturn = DocumentModule.returnDates.includes(dateString);
            const hasBooking = DocumentModule.bookingDates.includes(dateString);
            
            let classes = 'mini-calendar-day';
            if (isToday) classes += ' today';
            if (hasReturn) classes += ' has-return';
            if (hasBooking) classes += ' has-booking';
            
            let tooltipText = '';
            if (hasReturn && hasBooking) tooltipText = 'Return & Booking date';
            else if (hasReturn) tooltipText = 'Equipment return date';
            else if (hasBooking) tooltipText = 'Equipment booking date';
            
            html += `<div class="${classes}" data-date="${date.toISOString()}" data-tooltip="${tooltipText}">${day}</div>`;
        }
        
        calendarDays.innerHTML = html;
        
        const days = calendarDays.querySelectorAll('.mini-calendar-day');
        const tooltip = document.createElement('div');
        tooltip.className = 'calendar-tooltip';
        document.body.appendChild(tooltip);
        
        days.forEach(day => {
            const tooltipText = day.dataset.tooltip;
            if (tooltipText) {
                day.addEventListener('mouseenter', (e) => {
                    tooltip.textContent = tooltipText;
                    tooltip.style.display = 'block';
                    const rect = e.target.getBoundingClientRect();
                    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                    tooltip.style.top = rect.top - 30 + 'px';
                });
                day.addEventListener('mouseleave', () => {
                    tooltip.style.display = 'none';
                });
            }
        });
    }
    
    renderCalendar();
    
    document.getElementById('calendarPrevBtn').addEventListener('click', () => {
        DocumentModule.currentCalendarDate.setMonth(DocumentModule.currentCalendarDate.getMonth() - 1);
        renderCalendar();
    });
    
    document.getElementById('calendarNextBtn').addEventListener('click', () => {
        DocumentModule.currentCalendarDate.setMonth(DocumentModule.currentCalendarDate.getMonth() + 1);
        renderCalendar();
    });
}

async function loadDashboardWithCalendar() {
    await loadEquipmentReturnDates();
    try {
        const response = await fetch('ajax_handler.php?action=get_requests');
        const data = await response.json();
        if (data.success) {
            DocumentModule.allRequests = data.requests;
        }
    } catch (error) {
        console.error('Error loading requests:', error);
    }
    try {
        const equipResponse = await fetch('resident_equipment_ajax.php?action=get_my_bookings');
        const equipData = await equipResponse.json();
        if (equipData.success) {
            DocumentModule.equipmentBookingsData = equipData.bookings;
        }
    } catch (error) {
        console.error('Error loading equipment bookings:', error);
    }
    loadDashboard();
}

// ============ PROFILE FUNCTIONS ============

function renderProfile() {
    const residentData = window.resident || {};
    const verificationStatus = residentData.isVerified ? 
        '<span class="status-badge status-approved"><i class="fas fa-check-circle"></i> Verified</span>' : 
        '<span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending Verification</span>';
    
    document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-user-circle"></i> My Profile</div>
                <div class="info-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3>Account Information</h3> ${verificationStatus}
                    </div>
                    <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                        <strong>Full Name:</strong> ${escapeHtml(residentData.name || '')}
                    </div>
                    <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                        <strong>Email:</strong> ${escapeHtml(residentData.email || '')}
                    </div>
                    <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                        <strong>Phone:</strong> ${escapeHtml(residentData.phone || 'Not provided')}
                    </div>
                    <div style="padding:12px 0;">
                        <strong>Address:</strong> ${escapeHtml(residentData.address || 'Not specified')}
                    </div>
                </div>
            </div>
        `;
}

// ============ FORM SUBMISSION ============

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('documentRequestForm');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            let hasError = false;
            const allDateFields = document.querySelectorAll('#documentRequestForm input[type="date"]');
            
            allDateFields.forEach(dateField => {
                if (dateField.value) {
                    const validation = validateDateOfBirth(dateField.value, 'Date field');
                    if (!validation.valid) {
                        showFieldError(dateField, validation.message);
                        hasError = true;
                    } else {
                        clearFieldError(dateField);
                    }
                }
            });
            
            if (hasError) {
                showErrorModal('Please fix the date validation errors before submitting');
                return;
            }
            
            const isEdit = document.querySelector('input[name="edit_request_id"]') && document.querySelector('input[name="edit_request_id"]').value;
            const idFile = document.querySelector('[name="id_document"]').files[0];
            
            if (!isEdit && !idFile) {
                showErrorModal('Please upload a valid ID document');
                return;
            }
            
            if (idFile && idFile.size > 5 * 1024 * 1024) {
                showErrorModal('File size must be less than 5MB');
                return;
            }
            
            const formData = new FormData(e.target);
            if (!isEdit) {
                formData.append('action', 'submit_request');
                const quantity = document.querySelector('[name="quantity"]').value;
                const feeType = document.querySelector('input[name="fee_type"]:checked').value;
                formData.append('purpose', `Request for ${DocumentModule.currentDocName} - ${quantity} copy/copies (${feeType})`);
            } else {
                formData.append('action', 'update_request');
            }
            
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('ajax_handler.php', { 
                    method: 'POST', 
                    body: formData 
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showSuccessModal(data.message);
                    closeRequestModal();
                    DocumentModule.currentEditRequestId = null;
                    const editInput = document.querySelector('input[name="edit_request_id"]');
                    if (editInput) editInput.remove();
                    const actionInput = document.querySelector('input[name="action"]');
                    if (actionInput) actionInput.value = 'submit_request';
                    document.getElementById('id_document').required = true;
                    const submitBtnReset = document.querySelector('#documentRequestForm button[type="submit"]');
                    submitBtnReset.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                    
                    if (document.querySelector('[data-subview="history"]') && 
                        document.querySelector('[data-subview="history"]').classList.contains('active-sub')) {
                        loadHistory();
                    } else {
                        loadDashboard();
                    }
                } else {
                    showErrorModal(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                showErrorModal('Error processing request. Please try again.');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    }
});

// ============ INITIALIZATION ============

document.addEventListener('DOMContentLoaded', function() {
    const paymentSelect = document.getElementById('paymentMethodSelect');
    if (paymentSelect) {
        paymentSelect.addEventListener('change', updatePaymentMethodInfo);
    }
    
    const modal = document.getElementById('requestModal');
    if (modal) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'style' || mutation.type === 'attributes') {
                    if (modal.style.display === 'flex') {
                        console.log('Modal opened - triggering auto-fill');
                        setTimeout(autoFillProfileData, 600);
                    }
                }
            });
        });
        observer.observe(modal, { attributes: true });
    }
});

const originalSelectDocument = window.selectDocument;
window.selectDocument = function(docId, docName, baseFee) {
    console.log('selectDocument called:', docId, docName, baseFee);
    openDocumentWizard(docId, docName, baseFee);
};

document.addEventListener('click', function(e) {
    if (e.target.closest && e.target.closest('[data-subview="request"]')) {
        console.log('Request subview clicked - loading documents');
        setTimeout(loadDocuments, 300);
    }
});

// ============ RECEIPT MODAL (separate from the wizard) ============

// Creates a standalone modal on top of the page and returns the element the
// receipt HTML should be written into. Used for every successful request:
// online payment, pay at claim, and free documents.
function createReceiptModal() {
    closeReceiptModal();

    const overlay = document.createElement('div');
    overlay.id = 'receiptModalOverlay';
    overlay.style.cssText =
        'position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);' +
        'z-index:10001;display:flex;align-items:center;justify-content:center;' +
        'padding:16px;overflow-y:auto;';

    const body = document.createElement('div');
    body.id = 'receiptModalBody';
    body.style.cssText = 'width:100%;max-width:440px;max-height:96vh;overflow-y:auto;border-radius:16px;';

    overlay.appendChild(body);
    document.body.appendChild(overlay);
    return body;
}

function closeReceiptModal() {
    const overlay = document.getElementById('receiptModalOverlay');
    if (overlay) overlay.remove();
}

// Hide (not reset) the wizard so the receipt shows alone.
// The form is reset later, when the person clicks Done.
function hideWizardBehindReceipt() {
    const wizard = document.getElementById('requestModal');
    if (wizard) wizard.style.display = 'none';
}

window.createReceiptModal = createReceiptModal;
window.closeReceiptModal = closeReceiptModal;

// ============ PAYMENT TAB + FAILURE HANDLING ============

// Close the payment tab we opened. close() is allowed even after the tab
// navigated to GCash/Maya, so this works from the original tab.
function closePaymentTab() {
    try {
        if (DocumentModule.paymentTab && !DocumentModule.paymentTab.closed) {
            DocumentModule.paymentTab.close();
        }
    } catch (e) { /* ignore */ }
    DocumentModule.paymentTab = null;
}

// Opens the GCash / Maya payment page. Called from a button click, so the
// popup blocker allows it, and because it is opened by script the return page
// can close it again afterwards.
function openPaymentPage() {
    const url = DocumentModule.paymentRedirectUrl;
    if (!url) return;
    try {
        if (DocumentModule.paymentTab && !DocumentModule.paymentTab.closed) {
            DocumentModule.paymentTab.location.href = url;
            DocumentModule.paymentTab.focus();
            return;
        }
    } catch (e) { /* fall through and open a new tab */ }

    DocumentModule.paymentTab = window.open(url, '_blank');
    if (!DocumentModule.paymentTab) {
        showPaymentProblem('Your browser blocked the payment page. Please allow pop-ups for this site and click the button again.');
    }
}
window.openPaymentPage = openPaymentPage;

// Standalone modal for the "waiting for payment" screen and the failure screen.
function createPaymentWaitModal() {
    closePaymentWaitModal();

    const overlay = document.createElement('div');
    overlay.id = 'paymentWaitOverlay';
    overlay.style.cssText =
        'position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);' +
        'z-index:10001;display:flex;align-items:center;justify-content:center;' +
        'padding:16px;overflow-y:auto;';

    const body = document.createElement('div');
    body.id = 'paymentWaitBody';
    body.style.cssText = 'width:100%;max-width:440px;max-height:96vh;overflow-y:auto;border-radius:16px;';

    overlay.appendChild(body);
    document.body.appendChild(overlay);
    return body;
}

function closePaymentWaitModal() {
    const overlay = document.getElementById('paymentWaitOverlay');
    if (overlay) overlay.remove();
}

function cancelPaymentWait() {
    stopPaymentPolling();
    closePaymentTab();
    closePaymentWaitModal();
}

function retryPayment() {
    closePaymentWaitModal();
    showPaymentModal();
}

// Shows a problem on the waiting screen (e.g. the server could not save the request).
function showPaymentProblem(message) {
    const el = document.getElementById('paymentPollWarning');
    if (!el || !message) return;
    el.textContent = message;
    el.style.display = 'block';
}

function handlePaymentFailed(reason) {
    stopPaymentPolling();
    closePaymentTab();

    const messages = {
        failed: 'Payment failed. Please try again.',
        cancelled: 'Payment was cancelled. You can try again.',
        expired: 'The payment expired. Please start a new payment.'
    };
    const msg = messages[reason] || messages.failed;

    const body = createPaymentWaitModal();
    body.innerHTML = `
        <div style="background: #fff; border-radius: 16px; padding: 32px 24px; text-align: center; box-shadow: 0 4px 30px rgba(0,0,0,.08);">
            <div style="font-size: 52px; color: #c62828;"><i class="fas fa-times-circle"></i></div>
            <h3 style="margin: 12px 0 6px; color: #1a2b22;">Payment Not Completed</h3>
            <p style="color: #666; font-size: 14px; margin: 0 0 20px;">${msg}</p>
            <button onclick="retryPayment()" style="width: 100%; background: linear-gradient(135deg, #1a472a, #2e7d32); color: #fff; border: none; padding: 11px; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; margin-bottom: 10px;">
                <i class="fas fa-redo"></i> Try Again
            </button>
            <button onclick="closePaymentWaitModal()" style="width: 100%; background: transparent; border: 1px solid #ddd; color: #666; padding: 9px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                Close
            </button>
        </div>
    `;
}

// One immediate status check (used when the person comes back to this tab
// and when the return page pings us), so we don't wait for the next 3s poll.
async function checkPaymentSilently() {
    if (!__paymentPollActive || !__paymentPollToken) return;
    const token = __paymentPollToken;
    try {
        const resp = await fetch(
            `ajax_handler.php?action=check_payment_status&draft_token=${encodeURIComponent(token)}`
        );
        const data = await resp.json();
        if (!__paymentPollActive || __paymentPollToken !== token) return;

        if (data.success && data.payment_status === 'paid') {
            showPaymentSuccess(data.request_id, data);
        } else if (data.success && ['failed', 'cancelled', 'expired'].includes(data.payment_status)) {
            handlePaymentFailed(data.payment_status);
        } else if (data.success === false) {
            showPaymentProblem(data.message);
        }
    } catch (err) { /* the poll will retry */ }
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') checkPaymentSilently();
});
window.addEventListener('focus', checkPaymentSilently);

// The return page (payment_return.php) pings this tab so it reacts instantly.
// Same-origin only; the 3-second poll is the fallback.
try {
    const paymentChannel = new BroadcastChannel('brite-payment');
    paymentChannel.onmessage = (e) => {
        if (!__paymentPollActive || !__paymentPollToken) return;
        const status = e.data && e.data.status;
        if (status === 'failed' || status === 'cancelled') {
            handlePaymentFailed(status);
        } else if (status === 'success') {
            checkPaymentSilently();
        }
    };
} catch (e) { /* BroadcastChannel not supported */ }

window.createPaymentWaitModal = createPaymentWaitModal;
window.closePaymentWaitModal = closePaymentWaitModal;
window.cancelPaymentWait = cancelPaymentWait;
window.retryPayment = retryPayment;

// ============ EXPORT FUNCTIONS ============
window.openDocumentWizard = openDocumentWizard;
window.goToWizardStep = goToWizardStep;

window.submitWizardRequest = submitWizardRequest;
window.loadWizardStep1 = loadWizardStep1;
window.loadWizardStep2 = loadWizardStep2;
window.validateCurrentStep = validateCurrentStep;
window.loadDocuments = loadDocuments;
window.loadHistory = loadHistory;
window.loadDashboard = loadDashboard;
window.loadDashboardWithCalendar = loadDashboardWithCalendar;
window.renderProfile = renderProfile;
window.closeRequestModal = closeRequestModal;
window.showQRCodeModal = showQRCodeModal;
window.closeQRCodeModal = closeQRCodeModal;
window.downloadQRCodeCapture = downloadQRCodeCapture;
window.showPaymentOptions = showPaymentOptions;
window.processPayment = processPayment;
window.processPayAtClaim = processPayAtClaim;
window.confirmPayAtClaim = confirmPayAtClaim;
window.confirmPayment = confirmPayment;
window.closePaymentOptionsModal = closePaymentOptionsModal;
window.closePaymentConfirmModal = closePaymentConfirmModal;
window.closePayAtClaimModal = closePayAtClaimModal;
window.filterRequests = filterRequests;
window.toggleRequestDetails = toggleRequestDetails;
window.showCancelConfirm = showCancelConfirm;
window.editRequest = editRequest;
window.autoMarkAsRead = autoMarkAsRead;
window.highlightRequestCard = highlightRequestCard;
window.showSuccessModal = showSuccessModal;
window.showErrorModal = showErrorModal;
window.showConfirmModal = showConfirmModal;
window.showInfoModal = showInfoModal;
window.closeErrorModal = closeErrorModal;
window.closeSuccessModal = closeSuccessModal;
window.closeInfoModal = closeInfoModal;
window.closeConfirmModal = closeConfirmModal;
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.updateFeeDisplay = updateFeeDisplay;
window.renderDynamicFields = renderDynamicFields;
window.renderDynamicFieldsForEdit = renderDynamicFieldsForEdit;
window.autoFillProfileData = autoFillProfileData;
window.updatePaymentMethodInfo = updatePaymentMethodInfo;
window.escapeHtml = escapeHtml;
window.getDocumentIcon = getDocumentIcon;
window.getResidentProfile = getResidentProfile;
window.searchResidentList = searchResidentList;
window.checkResidentListMatch = checkResidentListMatch;
window.formatDateDisplay = formatDateDisplay;
window.formatDateForDatabase = formatDateForDatabase;
window.formatGenderDisplay = formatGenderDisplay;
window.formatGenderForDatabase = formatGenderForDatabase;
window.updateTotalAmount = updateTotalAmount;
window.closePaymentModal = closePaymentModal;

window.processPaymentModal = processPaymentModal;
// ====== ADD THESE TWO NEW FUNCTIONS ======
window.closeWizardSuccess = closeWizardSuccess;


console.log('✅ Document Module with 2-Step Wizard loaded successfully!');