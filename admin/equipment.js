// equipment.js - Complete Equipment Management System with global_id support and validation

// ============ GLOBAL VARIABLES ============
let currentEquipmentFilter = 'all';
let currentBookingFilter = 'pending';
let currentBookingPage = 1;
let currentBookingTotalPages = 1;
let pendingBookingActionId = null;
let pendingBookingAction = null;
let currentCalendarYear = new Date().getFullYear();
let currentCalendarMonth = new Date().getMonth();
let currentScheduleEquipmentId = null;
let currentScheduleEquipmentName = '';

let currentEquipmentTypeId = null;
let currentEquipmentTypeName = '';

let autoRefreshEnabled = true;

// Validation tracking
let lastValidatedName = '';
let currentValidationPromise = null;

// ============ DEBOUNCE UTILITY ============
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

// ============ AUTO-REFRESH FUNCTIONS ============

async function refreshCurrentView() {
    const activeSubOption = document.querySelector('#equipmentSubmenu .sub-option.active-sub');
    if (!activeSubOption) {
        const itemsModal = document.getElementById('equipmentItemsModal');
        if (itemsModal && itemsModal.style.display === 'block' && currentEquipmentTypeId) {
            await refreshEquipmentItemsList();
        }
        return;
    }
    
    const view = activeSubOption.getAttribute('data-subview');
    
    switch(view) {
        case 'equipment_list':
            await loadEquipmentList();
            break;
        case 'equipment_bookings':
            await loadBookings(currentBookingFilter, currentBookingPage);
            break;
        case 'equipment_dashboard':
            await loadEquipmentDashboard();
            break;
    }
}

async function refreshEquipmentItemsList() {
    if (!currentEquipmentTypeId) return;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment_items&type_id=${currentEquipmentTypeId}`);
        const data = await response.json();
        if (data.success) {
            const modalBody = document.getElementById('equipmentItemsModalBody');
            if (modalBody && modalBody.style.display !== 'none') {
                modalBody.innerHTML = renderEquipmentItemsList(data.data, currentEquipmentTypeName);
            }
        }
    } catch (error) {
        console.error('Error refreshing items:', error);
    }
}

function triggerRefresh() {
    if (!autoRefreshEnabled) return;
    
    setTimeout(async () => {
        await refreshCurrentView();
        
        const itemsModal = document.getElementById('equipmentItemsModal');
        if (itemsModal && itemsModal.style.display === 'block') {
            await refreshEquipmentItemsList();
        }
    }, 500);
}

// ============ VALIDATION FUNCTIONS ============

async function checkEquipmentName(name, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_equipment_exists&name=${encodeURIComponent(name)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking equipment:', error);
        return false;
    }
}

async function checkVehicleExists(name, plateNumber, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_vehicle_exists&name=${encodeURIComponent(name)}&plate_number=${encodeURIComponent(plateNumber)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking vehicle:', error);
        return false;
    }
}

async function checkFacilityExists(name, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_facility_exists&name=${encodeURIComponent(name)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return { exists: data.exists, warning: data.warning };
    } catch (error) {
        console.error('Error checking facility:', error);
        return { exists: false, warning: false };
    }
}

// ============ VALIDATION HELPER FUNCTIONS ============

function validateItemType() {
    const select = document.getElementById('eqItemType');
    const errorDiv = document.getElementById('itemTypeError');
    const group = document.getElementById('itemTypeGroup');
    
    if (!select || !select.value) {
        if (errorDiv) {
            errorDiv.innerHTML = 'Please select an item type';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        return false;
    } else {
        if (errorDiv) errorDiv.style.display = 'none';
        if (group) group.classList.remove('has-error');
        return true;
    }
}

function validateCustomName(category, value) {
    const errorDiv = document.getElementById('customNameError');
    const group = document.getElementById('customNameGroupInner');
    
    if (!value || value.trim() === '') {
        if (errorDiv) {
            errorDiv.innerHTML = 'Please enter a custom item name';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        hideValidationMessage();
        return false;
    } else {
        if (errorDiv) errorDiv.style.display = 'none';
        if (group) group.classList.remove('has-error');
        return true;
    }
}

function validateQuantity() {
    const quantityInput = document.getElementById('eqQuantity');
    if (!quantityInput) return true;
    
    const errorDiv = document.getElementById('quantityError');
    const group = document.getElementById('quantityGroup');
    const value = parseInt(quantityInput.value);
    
    if (isNaN(value) || value < 1) {
        if (errorDiv) {
            errorDiv.innerHTML = 'Quantity must be at least 1';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        return false;
    } else if (value > 100) {
        if (errorDiv) {
            errorDiv.innerHTML = 'Quantity cannot exceed 100';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        return false;
    } else {
        if (errorDiv) errorDiv.style.display = 'none';
        if (group) group.classList.remove('has-error');
        return true;
    }
}

function validatePlateNumber() {
    const plateInput = document.getElementById('eqPlateNumber');
    if (!plateInput) return true;
    
    const errorDiv = document.getElementById('plateError');
    const group = document.getElementById('plateGroup');
    const value = plateInput.value.trim();
    
    if (!value) {
        if (errorDiv) {
            errorDiv.innerHTML = 'Please enter a plate number';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        return false;
    } else if (value.length < 3) {
        if (errorDiv) {
            errorDiv.innerHTML = 'Plate number must be at least 3 characters';
            errorDiv.style.display = 'block';
        }
        if (group) group.classList.add('has-error');
        return false;
    } else {
        if (errorDiv) errorDiv.style.display = 'none';
        if (group) group.classList.remove('has-error');
        return true;
    }
}

function validateImage() {
    const imageInput = document.getElementById('eqImage');
    const errorDiv = document.getElementById('imageError');
    if (!imageInput) return true;
    
    const file = imageInput.files[0];
    
    if (file) {
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            if (errorDiv) {
                errorDiv.innerHTML = 'Invalid image format. Use JPG, PNG, GIF, or WEBP';
                errorDiv.style.display = 'block';
            }
            return false;
        }
        if (file.size > 5 * 1024 * 1024) {
            if (errorDiv) {
                errorDiv.innerHTML = 'Image too large. Max 5MB';
                errorDiv.style.display = 'block';
            }
            return false;
        }
    }
    if (errorDiv) errorDiv.style.display = 'none';
    return true;
}

function clearFieldError(errorId) {
    const errorDiv = document.getElementById(errorId);
    if (errorDiv) errorDiv.style.display = 'none';
}

function removeFieldError(groupId) {
    const group = document.getElementById(groupId);
    if (group) group.classList.remove('has-error');
}

function validateForm(category) {
    let isValid = true;
    
    // Validate Item Type
    if (!validateItemType()) isValid = false;
    
    // Check if custom name is visible and validate
    const customGroup = document.getElementById('customNameGroup');
    if (customGroup && customGroup.style.display === 'block') {
        const customName = document.getElementById('eqCustomName');
        if (customName && !validateCustomName(category, customName.value)) isValid = false;
    }
    
    // Validate Equipment quantity
    if (category === 'Equipment') {
        if (!validateQuantity()) isValid = false;
    }
    
    // Validate Vehicle plate number
    if (category === 'Vehicle') {
        if (!validatePlateNumber()) isValid = false;
    }
    
    // Enable/disable submit button
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = !isValid;
        if (!isValid) {
            submitBtn.style.opacity = '0.6';
            submitBtn.style.cursor = 'not-allowed';
        } else {
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
    }
    
    return isValid;
}

// ============ DEBOUNCED VALIDATION FUNCTIONS ============

async function validateItemName(category, itemName) {
    if (!itemName || itemName.trim() === '') {
        hideValidationMessage();
        validateForm(category);
        return;
    }
    
    itemName = itemName.trim();
    
    // Skip if validating the same name
    if (lastValidatedName === itemName) {
        return;
    }
    lastValidatedName = itemName;
    
    try {
        let exists = false;
        if (category === 'Equipment') {
            const response = await fetch(`equipment_ajax.php?action=check_equipment_exists&name=${encodeURIComponent(itemName)}`);
            const data = await response.json();
            exists = data.exists;
            
            if (exists) {
                showValidationMessage('error', `❌ "${itemName}" already exists! Please use a different name.`);
                enableSubmitButtonByValidation(false);
            } else {
                showValidationMessage('success', `✅ "${itemName}" is available.`);
                enableSubmitButtonByValidation(true);
            }
        } else if (category === 'Facility') {
            const response = await fetch(`equipment_ajax.php?action=check_facility_exists&name=${encodeURIComponent(itemName)}`);
            const data = await response.json();
            exists = data.exists;
            
            if (exists) {
                showValidationMessage('warning', `⚠️ "${itemName}" already exists. You can still add it, but consider using a different name.`);
                enableSubmitButtonByValidation(true);
            } else {
                showValidationMessage('success', `✅ "${itemName}" is available.`);
                enableSubmitButtonByValidation(true);
            }
        }
    } catch (error) {
        console.error('Validation error:', error);
        enableSubmitButtonByValidation(true);
    }
    
    validateForm(category);
}

// Create debounced version - waits 500ms after last keystroke
const debouncedValidateItemName = debounce(async (category, itemName) => {
    await validateItemName(category, itemName);
}, 500);

function showValidationMessage(type, message) {
    let msgDiv = document.getElementById('nameValidationMessage');
    if (!msgDiv) {
        const customGroup = document.getElementById('customNameGroup');
        if (customGroup) {
            msgDiv = document.createElement('div');
            msgDiv.id = 'nameValidationMessage';
            msgDiv.className = 'validation-message';
            msgDiv.style.cssText = 'font-size:12px; margin-top:5px; padding:5px; border-radius:4px;';
            customGroup.appendChild(msgDiv);
        } else {
            msgDiv = document.getElementById('predefinedValidationMessage');
        }
    }
    
    if (msgDiv) {
        msgDiv.style.display = 'block';
        if (type === 'error') {
            msgDiv.style.backgroundColor = '#f8d7da';
            msgDiv.style.color = '#721c24';
            msgDiv.style.border = '1px solid #f5c6cb';
        } else if (type === 'warning') {
            msgDiv.style.backgroundColor = '#fff3cd';
            msgDiv.style.color = '#856404';
            msgDiv.style.border = '1px solid #ffeeba';
        } else {
            msgDiv.style.backgroundColor = '#d4edda';
            msgDiv.style.color = '#155724';
            msgDiv.style.border = '1px solid #c3e6cb';
        }
        msgDiv.innerHTML = message;
    }
}

function hideValidationMessage() {
    const msgDiv = document.getElementById('nameValidationMessage');
    if (msgDiv) msgDiv.style.display = 'none';
    const predefinedMsg = document.getElementById('predefinedValidationMessage');
    if (predefinedMsg) predefinedMsg.style.display = 'none';
}

function enableSubmitButtonByValidation(enabled) {
    const category = getCurrentCategory();
    if (category) validateForm(category);
}

function getCurrentCategory() {
    const modalTitle = document.getElementById('equipmentModalTitle');
    if (!modalTitle) return null;
    const titleText = modalTitle.innerHTML;
    if (titleText.includes('Equipment')) return 'Equipment';
    if (titleText.includes('Facility')) return 'Facility';
    if (titleText.includes('Vehicle')) return 'Vehicle';
    return null;
}

async function validateVehicleRealtime() {
    const nameSelect = document.getElementById('eqItemType');
    const customNameInput = document.getElementById('eqCustomName');
    const plateNumber = document.getElementById('eqPlateNumber') ? document.getElementById('eqPlateNumber').value : '';
    const msgDiv = document.getElementById('vehicleValidationMessage');
    
    let name = '';
    if (nameSelect.value === '__other__' && customNameInput) {
        name = customNameInput.value;
    } else if (nameSelect.value && nameSelect.value !== '__other__') {
        name = nameSelect.value;
    }
    
    if (!name || name.trim() === '' || !plateNumber || plateNumber.trim() === '') {
        if (msgDiv) msgDiv.style.display = 'none';
        return;
    }
    
    try {
        const response = await fetch(`equipment_ajax.php?action=check_vehicle_exists&name=${encodeURIComponent(name)}&plate_number=${encodeURIComponent(plateNumber)}`);
        const data = await response.json();
        
        if (msgDiv) {
            msgDiv.style.display = 'block';
            if (data.exists) {
                msgDiv.style.backgroundColor = '#f8d7da';
                msgDiv.style.color = '#721c24';
                msgDiv.style.border = '1px solid #f5c6cb';
                msgDiv.innerHTML = `❌ Vehicle "${name}" with plate "${plateNumber}" already exists!`;
            } else {
                msgDiv.style.backgroundColor = '#d4edda';
                msgDiv.style.color = '#155724';
                msgDiv.style.border = '1px solid #c3e6cb';
                msgDiv.innerHTML = `✅ Vehicle name and plate number are available.`;
            }
        }
    } catch (error) {
        console.error('Validation error:', error);
    }
}

function onItemTypeChange(category) {
    const selected = document.getElementById('eqItemType');
    const customGroup = document.getElementById('customNameGroup');
    const predefinedGroup = document.getElementById('predefinedNameGroup');
    const customNameInput = document.getElementById('eqCustomName');
    const selectedDisplay = document.getElementById('selectedItemDisplay');
    const nameValidationMsg = document.getElementById('nameValidationMessage');
    const predefinedMsg = document.getElementById('predefinedValidationMessage');
    
    if (selected.value === '__other__') {
        if (customGroup) customGroup.style.display = 'block';
        if (predefinedGroup) predefinedGroup.style.display = 'none';
        if (customNameInput) {
            customNameInput.required = true;
            customNameInput.value = '';
        }
        if (nameValidationMsg) nameValidationMsg.style.display = 'none';
        if (predefinedMsg) predefinedMsg.style.display = 'none';
        // Reset last validated name for custom input
        lastValidatedName = '';
    } else if (selected.value) {
        if (customGroup) customGroup.style.display = 'none';
        if (predefinedGroup) predefinedGroup.style.display = 'block';
        if (selectedDisplay) selectedDisplay.innerHTML = `<strong>${escapeHtml(selected.value)}</strong>`;
        if (customNameInput) customNameInput.required = false;
        
        // Reset last validated name and validate predefined item with debounce
        lastValidatedName = '';
        debouncedValidateItemName(category, selected.value);
    } else {
        if (customGroup) customGroup.style.display = 'none';
        if (predefinedGroup) predefinedGroup.style.display = 'none';
        if (nameValidationMsg) nameValidationMsg.style.display = 'none';
        if (predefinedMsg) predefinedMsg.style.display = 'none';
    }
    
    // Re-validate the entire form
    validateForm(category);
}

// ============ INDIVIDUAL ITEM MANAGEMENT FUNCTIONS ============

function manageItem(itemId, itemName, currentStatus, currentNotes) {
    var modal = document.getElementById('manageItemModal');
    var modalTitle = document.getElementById('manageItemModalTitle');
    var modalBody = document.getElementById('manageItemModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = '<i class="fas fa-tools"></i> Manage Item: ' + escapeHtml(itemName);
    modalBody.innerHTML = `
        <form id="manageItemForm" onsubmit="updateItemStatus(event, ${itemId})">
            <div class="form-group">
                <label>Item Status</label>
                <select id="itemStatus" class="form-control">
                    <option value="available" ${currentStatus === 'available' ? 'selected' : ''}>
                        <i class="fas fa-check-circle"></i> Available
                    </option>
                    <option value="maintenance" ${currentStatus === 'maintenance' ? 'selected' : ''}>
                        <i class="fas fa-wrench"></i> Under Maintenance
                    </option>
                    <option value="lost" ${currentStatus === 'lost' ? 'selected' : ''}>
                        <i class="fas fa-question-circle"></i> Lost / Missing
                    </option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Condition Notes</label>
                <textarea id="itemConditionNotes" class="form-control" rows="3" placeholder="Enter condition notes, maintenance details, or reason for status change...">${escapeHtml(currentNotes || '')}</textarea>
                <small class="form-text text-muted">Optional. Record any damages, maintenance work, or special notes.</small>
            </div>
            
            <div class="form-buttons">
                <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update Item</button>
                <button type="button" class="btn-close" onclick="closeManageItemModal()">Cancel</button>
            </div>
        </form>
    `;
    
    modal.style.display = 'block';
}

function updateItemStatus(event, itemId) {
    event.preventDefault();
    
    var status = document.getElementById('itemStatus').value;
    var conditionNotes = document.getElementById('itemConditionNotes').value;
    
    var formData = new FormData();
    formData.append('action', 'update_item_status');
    formData.append('id', itemId);
    formData.append('status', status);
    formData.append('condition_notes', conditionNotes);
    
    fetch('equipment_ajax.php', { method: 'POST', body: formData })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                if (typeof showSuccessModal === 'function') {
                    showSuccessModal('Item status updated successfully!', 'Success');
                } else {
                    alert('Item status updated successfully!');
                }
                closeManageItemModal();
                triggerRefresh();
            } else {
                if (typeof showErrorModal === 'function') {
                    showErrorModal(data.message || 'Failed to update item', 'Error');
                } else {
                    alert(data.message || 'Failed to update item');
                }
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            if (typeof showErrorModal === 'function') {
                showErrorModal('An error occurred', 'Error');
            } else {
                alert('An error occurred');
            }
        });
}

function closeManageItemModal() {
    const modal = document.getElementById('manageItemModal');
    if (modal) modal.style.display = 'none';
}

// ============ EQUIPMENT FORM HANDLING ============

function showAddEquipmentModal() {
    const modal = document.getElementById('equipmentModal');
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = '<i class="fas fa-plus"></i> Add Equipment/Facility';
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <p style="margin-bottom: 25px; color: #666; font-size: 14px;">Select the category of item you want to add:</p>
            <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                <div class="category-card" onclick="showCategoryForm('Equipment')" style="cursor: pointer; text-align: center; padding: 25px; border-radius: 16px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); transition: transform 0.2s; width: 150px;">
                    <i class="fas fa-tools" style="font-size: 48px; color: #2e7d32;"></i>
                    <h3 style="margin-top: 15px; color: #2e7d32;">Equipment</h3>
                    <p style="font-size: 12px; color: #666; margin-top: 8px;">Tents, Tables, etc.</p>
                </div>
                <div class="category-card" onclick="showCategoryForm('Facility')" style="cursor: pointer; text-align: center; padding: 25px; border-radius: 16px; background: linear-gradient(135deg, #e3f2fd, #bbdef5); transition: transform 0.2s; width: 150px;">
                    <i class="fas fa-building" style="font-size: 48px; color: #1565c0;"></i>
                    <h3 style="margin-top: 15px; color: #1565c0;">Facility</h3>
                    <p style="font-size: 12px; color: #666; margin-top: 8px;">Covered Court</p>
                </div>
                <div class="category-card" onclick="showCategoryForm('Vehicle')" style="cursor: pointer; text-align: center; padding: 25px; border-radius: 16px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); transition: transform 0.2s; width: 150px;">
                    <i class="fas fa-truck" style="font-size: 48px; color: #e65100;"></i>
                    <h3 style="margin-top: 15px; color: #e65100;">Vehicle</h3>
                    <p style="font-size: 12px; color: #666; margin-top: 8px;">Truck, Van, Patrol</p>
                </div>
            </div>
            <div style="margin-top: 25px;">
             
<button type="button" class="btn-close" onclick="resetImageUploadContainer(); showAddEquipmentModal()">Back</button>
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

function showCategoryForm(category) {
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    let categoryIcon = '';
    let predefinedItems = [];
    
    if (category === 'Equipment') {
        categoryIcon = '<i class="fas fa-tools"></i>';
        predefinedItems = ['Tent', 'Table', 'Chair'];
    } else if (category === 'Facility') {
        categoryIcon = '<i class="fas fa-building"></i>';
        predefinedItems = ['Covered Court', 'Multi-purpose Hall', 'Barangay Hall'];
    } else if (category === 'Vehicle') {
        categoryIcon = '<i class="fas fa-truck"></i>';
        predefinedItems = ['Barangay Patrol', 'Truck', 'Van', 'Jeep', 'Ambulance'];
    }
    
    // Build options HTML for Item Type dropdown
    let optionsHtml = `<option value="">-- Select Item --</option>`;
    predefinedItems.forEach(item => {
        optionsHtml += `<option value="${item}">${item}</option>`;
    });
    optionsHtml += `<option value="__other__">-- Other (Type custom name) --</option>`;
    
    const showQuantity = (category === 'Equipment');
    const quantityHtml = showQuantity ? `
        <div class="form-group" id="quantityGroup">
            <label>Quantity <span class="required-star">*</span></label>
            <input type="number" id="eqQuantity" class="form-control" required min="1" value="1" max="100">
            <small class="form-text text-muted">If quantity > 1, each item will be created individually (e.g., Tent 1, Tent 2, etc.)</small>
            <div class="field-error" id="quantityError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
        </div>
    ` : '<input type="hidden" id="eqQuantity" value="1">';
    
    // Vehicle specific - plate number only
    const vehicleFields = (category === 'Vehicle') ? `
        <div class="form-group" id="plateGroup">
            <label>Plate Number <span class="required-star">*</span></label>
            <input type="text" id="eqPlateNumber" class="form-control" placeholder="ABC-1234" required>
            <small class="form-text text-muted">Unique plate number for vehicle identification</small>
            <div class="field-error" id="plateError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
        </div>
        <div class="form-group">
            <label>Capacity</label>
            <input type="number" id="eqCapacity" class="form-control" placeholder="Number of passengers">
        </div>
    ` : '';
    
    // Facility specific fields
    const facilityFields = (category === 'Facility') ? `
        <div class="form-group">
            <label>Location</label>
            <input type="text" id="eqLocation" class="form-control" placeholder="Building, Hall, etc.">
        </div>
        <div class="form-group">
            <label>Capacity</label>
            <input type="number" id="eqCapacity" class="form-control" placeholder="Max persons">
        </div>
    ` : '';
    
    modalTitle.innerHTML = `${categoryIcon} Add ${category}`;
    modalBody.innerHTML = `
        <form id="equipmentForm" enctype="multipart/form-data" onsubmit="saveEquipment(event, '${category}')">
            <div class="form-group" id="itemTypeGroup">
                <label>Item Type <span class="required-star">*</span></label>
                <select id="eqItemType" class="form-control" required onchange="onItemTypeChange('${category}')">
                    ${optionsHtml}
                </select>
                <div class="field-error" id="itemTypeError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
            </div>
            
            <div id="customNameGroup" style="display:none;">
                <div class="form-group" id="customNameGroupInner">
                    <label>Custom Item Name <span class="required-star">*</span></label>
                    <input type="text" id="eqCustomName" class="form-control" placeholder="e.g., Special Equipment Name">
                    <div class="field-error" id="customNameError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
                    <div id="nameValidationMessage" class="validation-message" style="display:none; font-size:12px; margin-top:5px;"></div>
                </div>
            </div>
            
            <div id="predefinedNameGroup" style="display:none;">
                <div class="form-group">
                    <label>Selected Item: <strong id="selectedItemDisplay"></strong></label>
                    <input type="hidden" id="eqSelectedItem" value="">
                    <div id="predefinedValidationMessage" class="validation-message" style="display:none; font-size:12px; margin-top:5px;"></div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea id="eqDescription" class="form-control" rows="3" placeholder="Description of the item..."></textarea>
            </div>
            
            ${vehicleFields}
            ${facilityFields}
            
        

<div class="form-group">
    <label>Image</label>
    <div id="imageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('eqImage').click()">
        <div id="imagePreviewContainer" style="display: none;">
            <img id="previewImgUpload" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
            <p style="font-size: 12px; color: #999; margin: 0;">
                <i class="fas fa-sync-alt"></i> Click to change image
            </p>
        </div>
        <div id="imageUploadPlaceholder">
            <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #43e97b;"></i>
            <p style="margin-top: 10px; color: #666;">Click to upload image</p>
            <p style="font-size: 12px; color: #999;">JPG, PNG, GIF, WEBP (Max 5MB)</p>
        </div>
    </div>
    <input type="file" id="eqImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;" onchange="previewImageInContainer(this)">
    <div class="field-error" id="imageError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
</div>
            
            ${quantityHtml}
            
            <div id="vehicleValidationMessage" class="validation-message" style="display:none; font-size:12px; margin-top:5px;"></div>
            
            <div class="form-buttons">
                <button type="submit" id="submitBtn" class="btn-verify" disabled><i class="fas fa-save"></i> Save</button>
                <button type="button" class="btn-close" onclick="showAddEquipmentModal()">Back</button>
                <button type="button" class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
            </div>
        </form>
    `;
    
    // Add event listeners for real-time validation
    const itemTypeSelect = document.getElementById('eqItemType');
    if (itemTypeSelect) {
        itemTypeSelect.addEventListener('change', function() {
            clearFieldError('itemTypeError');
            if (this.value) {
                removeFieldError('itemTypeGroup');
            }
            onItemTypeChange(category);
        });
        itemTypeSelect.addEventListener('blur', function() {
            validateItemType();
        });
    }
    
    // Add debounced validation for custom name input
    const customNameInput = document.getElementById('eqCustomName');
    if (customNameInput) {
        customNameInput.addEventListener('input', function() {
            const value = this.value;
            validateCustomName(category, value);
            if (value && value.trim() !== '') {
                lastValidatedName = '';
                debouncedValidateItemName(category, value.trim());
            } else {
                hideValidationMessage();
            }
            validateForm(category);
        });
    }
    
    // For Equipment, add quantity validation
    if (category === 'Equipment') {
        const quantityInput = document.getElementById('eqQuantity');
        if (quantityInput) {
            quantityInput.addEventListener('input', function() {
                validateQuantity();
                validateForm(category);
            });
            quantityInput.addEventListener('blur', function() {
                validateQuantity();
                validateForm(category);
            });
        }
    }
    
    // For Vehicle, add plate number validation
    if (category === 'Vehicle') {
        const plateInput = document.getElementById('eqPlateNumber');
        if (plateInput) {
            plateInput.addEventListener('keyup', function() {
                this.value = this.value.trim().toUpperCase();
                validatePlateNumber();
                validateVehicleRealtime();
                validateForm(category);
            });
            plateInput.addEventListener('blur', function() {
                validatePlateNumber();
                validateForm(category);
            });
        }
    }
    
    // Image validation
    const imageInput = document.getElementById('eqImage');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            validateImage();
            validateForm(category);
        });
    }
    
    // Image preview
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const preview = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    // Initial validation check
    validateForm(category);
}



// ============ IMAGE PREVIEW FUNCTIONS ============

// Preview image inside the upload container
function previewImageInContainer(input) {
    const container = document.getElementById('imageUploadContainer');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const placeholder = document.getElementById('imageUploadPlaceholder');
    const previewImg = document.getElementById('previewImgUpload');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            if (previewContainer) previewContainer.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
            if (container) container.style.borderColor = '#4caf50';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        // Reset to placeholder if no file
        if (previewContainer) previewContainer.style.display = 'none';
        if (placeholder) placeholder.style.display = 'block';
        if (container) container.style.borderColor = '#e2efe8';
    }
}

// Function to reset image upload container (called when form is reset)
function resetImageUploadContainer() {
    const container = document.getElementById('imageUploadContainer');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const placeholder = document.getElementById('imageUploadPlaceholder');
    const fileInput = document.getElementById('eqImage');
    
    if (previewContainer) previewContainer.style.display = 'none';
    if (placeholder) placeholder.style.display = 'block';
    if (container) container.style.borderColor = '#e2efe8';
    if (fileInput) fileInput.value = '';
}






function clearImage() {
    const imageInput = document.getElementById('eqImage');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const placeholder = document.getElementById('imageUploadPlaceholder');
    const container = document.getElementById('imageUploadContainer');
    const previewImg = document.getElementById('previewImgUpload');
    
    if (imageInput) {
        imageInput.value = '';
    }
    if (previewContainer) {
        previewContainer.style.display = 'none';
    }
    if (placeholder) {
        placeholder.style.display = 'block';
    }
    if (container) {
        container.style.borderColor = '#e2efe8';
    }
    if (previewImg) {
        previewImg.src = '#';
    }
}


// ============ SAVE EQUIPMENT FUNCTION ============

async function saveEquipment(event, category) {
    event.preventDefault();
    
    // Final validation before saving
    if (!validateForm(category)) {
        showErrorModal('Please fill in all required fields correctly.', 'Validation Error');
        return;
    }
    
    const itemTypeSelect = document.getElementById('eqItemType');
    const selectedItem = itemTypeSelect.value;
    let itemName = '';
    
    if (!selectedItem) {
        showErrorModal('Please select an item type', 'Validation Error');
        return;
    }
    
    if (selectedItem === '__other__') {
        itemName = document.getElementById('eqCustomName').value;
        if (!itemName || itemName.trim() === '') {
            showErrorModal('Please enter a custom item name', 'Validation Error');
            return;
        }
        itemName = itemName.trim();
    } else {
        itemName = selectedItem;
    }
    
    const description = document.getElementById('eqDescription').value;
    const totalQuantity = (category === 'Equipment') ? parseInt(document.getElementById('eqQuantity').value) : 1;
    
    // Equipment duplicate validation
    if (category === 'Equipment') {
        const exists = await checkEquipmentName(itemName);
        if (exists) {
            showConfirmationModal(
                `"${itemName}" already exists! Do you want to edit the existing item instead?`,
                'Item Already Exists',
                async () => {
                    closeEquipmentModal();
                    await editEquipmentByName(itemName, 'equipment');
                }
            );
            return;
        }
    } 
    // Vehicle duplicate validation
    else if (category === 'Vehicle') {
        const plateNumber = document.getElementById('eqPlateNumber').value;
        if (!plateNumber || plateNumber.trim() === '') {
            showErrorModal('Please enter a plate number for the vehicle.', 'Validation Error');
            return;
        }
        const exists = await checkVehicleExists(itemName, plateNumber.trim());
        if (exists) {
            showErrorModal(`Vehicle with name "${itemName}" and plate number "${plateNumber}" already exists!`, 'Duplicate Found');
            return;
        }
    }
    // Facility warning only
    else if (category === 'Facility') {
        const exists = await checkFacilityExists(itemName);
        if (exists) {
            const confirmed = await showConfirmModal(
                `A facility named "${itemName}" already exists. Are you sure you want to add another one?`,
                'Warning: Duplicate Facility'
            );
            if (!confirmed) return;
        }
    }
    
    // Validate image if provided
    const imageFile = document.getElementById('eqImage').files[0];
    if (imageFile) {
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(imageFile.type)) {
            showErrorModal('Invalid image format. Please upload JPG, PNG, GIF, or WEBP images only.', 'Validation Error');
            return;
        }
        if (imageFile.size > 5 * 1024 * 1024) {
            showErrorModal('Image file is too large. Maximum size is 5MB.', 'Validation Error');
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'add_equipment');
    formData.append('name', itemName);
    formData.append('description', description || '');
    formData.append('category', category);
    formData.append('total_quantity', totalQuantity);
    
    if (category === 'Facility') {
        const location = document.getElementById('eqLocation') ? document.getElementById('eqLocation').value : '';
        const capacity = document.getElementById('eqCapacity') ? document.getElementById('eqCapacity').value : '';
        formData.append('location', location);
        formData.append('capacity', capacity || '0');
    } else if (category === 'Vehicle') {
        const plateNumber = document.getElementById('eqPlateNumber').value.trim();
        const capacity = document.getElementById('eqCapacity') ? document.getElementById('eqCapacity').value : '';
        formData.append('plate_number', plateNumber);
        formData.append('capacity', capacity || '0');
    }
    
    if (imageFile) {
        formData.append('equipment_image', imageFile);
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text.substring(0, 500));
            showErrorModal('Server error. Please check error logs.', 'Error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            return;
        }
        
        if (data.success) {
            let message = `Successfully added "${itemName}"`;
            if (category === 'Equipment' && totalQuantity > 1) message += ` with ${totalQuantity} items!`;
            else message += '!';
            showSuccessModal(message, 'Success');
            closeEquipmentModal();
            triggerRefresh();
        } else {
            showErrorModal(data.message || 'Failed to add equipment', 'Error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred while adding equipment', 'Error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
}

// ============ CHECK EXISTING FUNCTIONS ============

async function checkEquipmentName(name, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_equipment_exists&name=${encodeURIComponent(name)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking equipment:', error);
        return false;
    }
}

async function checkVehicleExists(name, plateNumber, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_vehicle_exists&name=${encodeURIComponent(name)}&plate_number=${encodeURIComponent(plateNumber)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking vehicle:', error);
        return false;
    }
}

async function checkFacilityExists(name, excludeId = null) {
    try {
        let url = `equipment_ajax.php?action=check_facility_exists&name=${encodeURIComponent(name)}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        const response = await fetch(url);
        const data = await response.json();
        return data.exists;
    } catch (error) {
        console.error('Error checking facility:', error);
        return false;
    }
}

// ============ HELPER FUNCTIONS ============

function showConfirmModal(message, title) {
    return new Promise((resolve) => {
        if (typeof showConfirmationModal === 'function') {
            showConfirmationModal(message, title, () => resolve(true), () => resolve(false));
        } else {
            resolve(confirm(title + '\n' + message));
        }
    });
}

// ============ EDIT FUNCTIONS ============

async function editEquipmentByName(name, type) {
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment&category=${type === 'equipment' ? 'Equipment' : (type === 'facility' ? 'Facility' : 'Vehicle')}`);
        const data = await response.json();
        if (data.success && data.data) {
            const item = data.data.find(i => i.name.toLowerCase() === name.toLowerCase());
            if (item) {
                let itemType = 'equipment';
                if (item.category === 'Facility') itemType = 'facility';
                else if (item.category === 'Vehicle') itemType = 'vehicle';
                editEquipment(item.id, itemType);
            }
        }
    } catch (error) {
        console.error('Error finding item to edit:', error);
    }
}

// Update editEquipment function to show two options
async function editEquipment(id, type) {
    const modal = document.getElementById('equipmentModal');
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    // Generate global_id
    let globalId = '';
    if (type === 'equipment') globalId = 'EQ' + String(id).padStart(6, '0');
    else if (type === 'facility') globalId = 'FA' + String(id).padStart(6, '0');
    else if (type === 'vehicle') globalId = 'VE' + String(id).padStart(6, '0');
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_item_by_global_id&global_id=${globalId}`);
        const data = await response.json();
        
        if (data.success) {
            const item = data.data;
            
            // For Facilities and Vehicles - only show Edit Info
            if (type === 'facility' || type === 'vehicle') {
                showEditInfoModal(item, globalId, type);
                return;
            }
            
            // For Equipment - show two choices
            modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Equipment';
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <p style="margin-bottom: 25px; color: #666;">What would you like to edit for "${escapeHtml(item.name)}"?</p>
                    <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                        <div class="category-card" onclick="showEditInfoModal(${JSON.stringify(item).replace(/"/g, '&quot;')}, '${globalId}', '${type}')" 
                             style="cursor: pointer; text-align: center; padding: 25px; border-radius: 16px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); width: 200px;">
                            <i class="fas fa-info-circle" style="font-size: 48px; color: #2e7d32;"></i>
                            <h3 style="margin-top: 15px; color: #2e7d32;">Edit Info</h3>
                            <p style="font-size: 12px; color: #666;">Name, description, image, status</p>
                        </div>
                        <div class="category-card" onclick="showCombinedManagementModal(${JSON.stringify(item).replace(/"/g, '&quot;')}, '${globalId}', '${type}')" 
                             style="cursor: pointer; text-align: center; padding: 25px; border-radius: 16px; background: linear-gradient(135deg, #e3f2fd, #bbdef5); width: 200px;">
                            <i class="fas fa-chart-pie" style="font-size: 48px; color: #1565c0;"></i>
                            <h3 style="margin-top: 15px; color: #1565c0;">Status & Quantity</h3>
                            <p style="font-size: 12px; color: #666;">Manage status distribution and add items</p>
                        </div>
                    </div>
                    <div style="margin-top: 25px;">
                        <button class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
                    </div>
                </div>
            `;
            modal.style.display = 'block';
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>${escapeHtml(data.message)}</p></div>`;
            modal.style.display = 'block';
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading data</p></div>`;
        modal.style.display = 'block';
    }
}
// Show Edit Info Modal (for all types)
function showEditInfoModal(item, globalId, type) {
    const modal = document.getElementById('equipmentModal');
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    const imageHtml = item.image ? `<img src="../${item.image}" style="max-width:150px; border-radius:8px; margin-top:10px;"><br><label><input type="checkbox" id="removeImage"> Remove current image</label>` : '<p class="text-muted">No image uploaded</p>';
    
    // Determine category for display
    let categoryValue = '';
    let statusOptions = '';
    
    if (type === 'equipment') {
        categoryValue = 'Equipment';
        statusOptions = `
            <option value="active" ${item.status === 'active' ? 'selected' : ''}>Active</option>
            <option value="inactive" ${item.status === 'inactive' ? 'selected' : ''}>Inactive</option>
        `;
    } else if (type === 'facility') {
        categoryValue = 'Facility';
        statusOptions = `
            <option value="available" ${item.status === 'available' ? 'selected' : ''}>Available</option>
            <option value="maintenance" ${item.status === 'maintenance' ? 'selected' : ''}>Maintenance</option>
            <option value="closed" ${item.status === 'closed' ? 'selected' : ''}>Closed</option>
        `;
    } else {
        categoryValue = 'Vehicle';
        statusOptions = `
            <option value="available" ${item.status === 'available' ? 'selected' : ''}>Available</option>
            <option value="maintenance" ${item.status === 'maintenance' ? 'selected' : ''}>Maintenance</option>
            <option value="unavailable" ${item.status === 'unavailable' ? 'selected' : ''}>Unavailable</option>
        `;
    }
    
    modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Info: ' + escapeHtml(item.name);
    modalBody.innerHTML = `
        <form id="equipmentForm" enctype="multipart/form-data" onsubmit="updateEquipmentByGlobalId(event, '${globalId}', '${type}')">
            <div class="form-group">
                <label>Category</label>
                <input type="text" class="form-control" value="${categoryValue}" disabled>
                <input type="hidden" id="eqCategoryHidden" value="${categoryValue}">
            </div>
            <div class="form-group">
                <label>Name <span style="color:red;">*</span></label>
                <input type="text" id="eqName" class="form-control" required value="${escapeHtml(item.name)}">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="eqDescription" class="form-control" rows="3">${escapeHtml(item.description || '')}</textarea>
            </div>
            ${type === 'facility' ? `
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" id="eqLocation" class="form-control" value="${escapeHtml(item.location || '')}">
                </div>
                <div class="form-group">
                    <label>Capacity</label>
                    <input type="number" id="eqCapacity" class="form-control" value="${item.capacity || ''}">
                </div>
            ` : ''}
            ${type === 'vehicle' ? `
                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" id="eqPlateNumber" class="form-control" value="${escapeHtml(item.plate_number || '')}">
                </div>
                <div class="form-group">
                    <label>Capacity</label>
                    <input type="number" id="eqCapacity" class="form-control" value="${item.capacity || ''}">
                </div>
            ` : ''}
            ${type === 'equipment' ? `
                <div class="form-group">
                    <label>Total Quantity (Read Only)</label>
                    <input type="number" id="eqQuantityDisplay" class="form-control" value="${item.total_quantity}" disabled>
                    <small class="form-text text-muted">To change quantity, use the "Status & Quantity" option.</small>
                </div>
                <input type="hidden" id="eqQuantity" value="${item.total_quantity}">
            ` : ''}
          

<div class="form-group">
    <label>Current Image</label>
    <div id="existingImageContainer" style="margin-bottom: 15px;">
        ${item.image ? `
            <div id="currentImageWrapper" style="border: 2px solid #e2efe8; border-radius: 12px; padding: 15px; text-align: center; background: #fafafa;">
                <img src="../${item.image}" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                <br>
                <label style="cursor: pointer; color: #dc3545; font-size: 12px;">
                    <input type="checkbox" id="removeImage"> Remove current image
                </label>
            </div>
        ` : '<p class="text-muted">No image uploaded</p>'}
    </div>
    <div class="form-group">
        <label>Change Image (Optional)</label>
        <div id="imageUploadContainer" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #fafafa;" onclick="document.getElementById('eqImage').click()">
            <div id="imagePreviewContainer" style="display: none;">
                <img id="previewImgUpload" src="#" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px; margin-bottom: 10px;">
                <p style="font-size: 12px; color: #999; margin: 0;">
                    <i class="fas fa-sync-alt"></i> Click to change image
                </p>
            </div>
            <div id="imageUploadPlaceholder">
                <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #43e97b;"></i>
                <p style="margin-top: 10px; color: #666;">Click to upload new image</p>
                <p style="font-size: 12px; color: #999;">JPG, PNG, GIF, WEBP (Max 5MB)</p>
            </div>
        </div>
        <input type="file" id="eqImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;" onchange="previewImageInContainer(this)">
    </div>
    <div class="field-error" id="imageError" style="display:none; color:#dc3545; font-size:12px; margin-top:5px;"></div>
</div>
            <div class="form-group">
                <label>Status</label>
                <select id="eqStatus" class="form-control">
                    ${statusOptions}
                </select>
            </div>
            <div class="form-buttons">
                <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update Info</button>
                <button type="button" class="btn-close" onclick="editEquipment(${item.original_id || item.id}, '${type}')">Back</button>
                <button type="button" class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
            </div>
        </form>
    `;
    
    const imageInput = document.getElementById('eqImage');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const preview = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    modal.style.display = 'block';
}

// Global variable to store current management data
let currentManagementData = null;

// Show Combined Management Modal with Add/Deduct and History
function showCombinedManagementModal(item, globalId, type) {
    const modal = document.getElementById('equipmentModal');
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = '<i class="fas fa-chart-line"></i> Manage Status & Quantity: ' + escapeHtml(item.name);
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading equipment data...</p></div>`;
    modal.style.display = 'block';
    
    // Extract numeric ID from global_id
    const equipmentId = parseInt(globalId.replace(/[^0-9]/g, ''));
    
    // Store current management data globally
    currentManagementData = {
        item: item,
        globalId: globalId,
        type: type,
        equipmentId: equipmentId
    };
    
    // Fetch current status distribution and change logs
    // In showCombinedManagementModal function, fix the data loading:
Promise.all([
    fetch(`equipment_ajax.php?action=get_equipment_status&equipment_id=${equipmentId}`).then(r => r.json()),
    fetch(`equipment_ajax.php?action=get_change_logs&equipment_id=${equipmentId}`).then(r => r.json())
]).then(([statusData, logsData]) => {
    // IMPORTANT: Use the actual total from equipment table, not from status
    const totalQty = item.total_quantity || item.quantity || 0;
    
    // Get status quantities from equipment_status (these are the actual values)
    let currentAvailable = 0;
    let currentMaintenance = 0;
    let currentLost = 0;
    
    if (statusData.success && statusData.data) {
        currentAvailable = statusData.data.available || 0;
        currentMaintenance = statusData.data.maintenance || 0;
        currentLost = statusData.data.lost || 0;
    } else {
        // Fallback: if no status data, all items are available
        currentAvailable = totalQty;
    }
    
    const changeLogs = logsData.success ? logsData.data : [];
    window.currentChangeLogs = changeLogs;
    renderManagementUI(totalQty, currentAvailable, currentMaintenance, currentLost, changeLogs, equipmentId);
}).catch(error => {
    console.error('Error:', error);
    modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading data</p></div>`;
});
    
    function renderManagementUI(totalQty, available, maintenance, lost, changeLogs, equipmentId) {
    modalBody.innerHTML = `
        <style>
            .stat-card { 
                background: white; 
                border-radius: 16px; 
                padding: 15px; 
                text-align: center; 
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: transform 0.2s;
            }
            .stat-card:hover { transform: translateY(-2px); }
            .stat-number { font-size: 28px; font-weight: bold; }
            .log-item { transition: background 0.2s; }
            .log-item:hover { background: #f5f5f5 !important; }
            .undo-btn { 
                background: #ff9800; 
                color: white; 
                border: none; 
                padding: 4px 10px; 
                border-radius: 5px; 
                cursor: pointer;
                font-size: 12px;
                transition: opacity 0.2s;
            }
            .undo-btn:hover { opacity: 0.8; }
            .action-card {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 15px;
                margin-bottom: 20px;
            }
            .history-toggle {
                background: #e0e0e0;
                border: none;
                padding: 8px 15px;
                border-radius: 20px;
                cursor: pointer;
                font-size: 13px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.2s;
            }
            .history-toggle:hover {
                background: #d0d0d0;
            }
            .history-container {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }
            .history-container.show {
                max-height: 400px;
                overflow-y: auto;
            }
        </style>
        
        <div style="max-height: 65vh; overflow-y: auto; padding-right: 10px;">
            <!-- Header -->
            <div style="margin-bottom: 15px; padding: 12px; background: linear-gradient(135deg, #1a472a, #2e7d32); border-radius: 12px; color: white;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <strong style="font-size: 1rem;">${escapeHtml(item.name)}</strong>
                        <div style="font-size: 0.75rem; opacity: 0.9;">Total: <strong>${totalQty}</strong> items</div>
                    </div>
                    <div style="font-size: 0.7rem;">
                        <i class="fas fa-history"></i> Changes logged
                    </div>
                </div>
            </div>
            
            <!-- Status Cards (Inline row) -->
            <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; justify-content: center;">
                <!-- Available Card -->
                <div class="stat-card" style="flex: 1; min-width: 100px; border-top: 3px solid #4caf50;">
                    <i class="fas fa-check-circle" style="font-size: 18px; color: #4caf50;"></i>
                    <div style="font-size: 10px; color: #666;">Available</div>
                    <div class="stat-number" style="color: #2e7d32;">${available}</div>
                </div>
                
                <!-- Maintenance Card -->
                <div class="stat-card" style="flex: 1; min-width: 100px; border-top: 3px solid #ff9800;">
                    <i class="fas fa-wrench" style="font-size: 18px; color: #ff9800;"></i>
                    <div style="font-size: 10px; color: #666;">Maintenance</div>
                    <div class="stat-number" style="color: #e65100;">${maintenance}</div>
                </div>
                
                <!-- Lost Card -->
                <div class="stat-card" style="flex: 1; min-width: 100px; border-top: 3px solid #f44336;">
                    <i class="fas fa-question-circle" style="font-size: 18px; color: #f44336;"></i>
                    <div style="font-size: 10px; color: #666;">Lost/Damaged</div>
                    <div class="stat-number" style="color: #c62828;">${lost}</div>
                </div>
            </div>
            
            <!-- Add Items Section -->
            <div class="action-card">
                <h4 style="color: #4caf50; margin-bottom: 12px; font-size: 14px;"><i class="fas fa-plus-circle"></i> Add Items</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 100px;">
                        <label style="font-size: 12px;">Quantity</label>
                        <input type="number" id="addQuantity" class="form-control" min="1" max="100" value="1" style="padding: 6px;">
                    </div>
                    <div style="flex: 2; min-width: 180px;">
                        <label style="font-size: 12px;">Reason <span style="color:red;">*</span></label>
                        <input type="text" id="addReason" class="form-control" placeholder="New purchase, Donation" style="padding: 6px;">
                    </div>
                    <div>
                        <button type="button" class="btn-verify" onclick="addItems()" style="background: #4caf50; padding: 6px 15px; font-size: 12px;">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Deduct Items Section -->
            <div class="action-card">
                <h4 style="color: #f44336; margin-bottom: 12px; font-size: 14px;"><i class="fas fa-minus-circle"></i> Deduct Items</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 100px;">
                        <label style="font-size: 12px;">Quantity</label>
                        <input type="number" id="deductQuantity" class="form-control" min="1" value="1" style="padding: 6px;">
                    </div>
                    <div style="flex: 1; min-width: 130px;">
                        <label style="font-size: 12px;">Type</label>
                        <select id="deductType" class="form-control" style="padding: 6px;">
                            <option value="lost">Lost / Missing</option>
                            <option value="maintenance">Damage / Maintenance</option>
                        </select>
                    </div>
                    <div style="flex: 2; min-width: 180px;">
                        <label style="font-size: 12px;">Reason (Optional)</label>
                        <input type="text" id="deductReason" class="form-control" placeholder="e.g., Broken, Lost" style="padding: 6px;">
                    </div>
                    <div>
                        <button type="button" class="btn-close" onclick="deductItems()" style="background: #f44336; padding: 6px 15px; font-size: 12px;">
                            <i class="fas fa-minus"></i> Deduct
                        </button>
                    </div>
                </div>
                <div id="deductWarning" style="display:none; margin-top: 8px; padding: 6px; background: #ffebee; border-radius: 8px; color: #c62828; font-size: 12px;">
                    <i class="fas fa-exclamation-triangle"></i> <span id="deductWarningText"></span>
                </div>
            </div>
            
            <!-- Change History Section with Toggle -->
<div style="margin-bottom: 15px;">
    <button type="button" class="history-toggle" onclick="toggleHistory()">
        <i class="fas fa-history"></i> Change History <i class="fas fa-chevron-down" id="historyIcon"></i>
    </button>
    <div id="historyContainer" class="history-container" style="margin-top: 10px;">
        ${renderChangeLogs(changeLogs)}
    </div>
</div>
            </div>
        </div>
        
        <div class="form-buttons" style="margin-top: 10px;">
            <button type="button" class="btn-close" onclick="editEquipment(${item.original_id || item.id}, '${type}')">Back</button>
            <button type="button" class="btn-close" onclick="closeEquipmentModal()">Close</button>
        </div>
    `;
    
    // Store current values globally
    window.currentStatusValues = {
        equipmentId: equipmentId,
        totalQty: totalQty,
        available: available,
        maintenance: maintenance,
        lost: lost
    };
    
    // Add real-time validation for deduction
    const deductQuantityInput = document.getElementById('deductQuantity');
    const deductTypeSelect = document.getElementById('deductType');
    
    function validateDeduction() {
        const qty = parseInt(deductQuantityInput.value) || 0;
        const warningDiv = document.getElementById('deductWarning');
        const warningText = document.getElementById('deductWarningText');
        
        let availableQty = window.currentStatusValues.available;
        
        if (qty > availableQty) {
            warningDiv.style.display = 'block';
            warningText.innerHTML = `Not enough available items! Available: ${availableQty}, Requested: ${qty}`;
            return false;
        } else {
            warningDiv.style.display = 'none';
            return true;
        }
    }
    
    if (deductQuantityInput) {
        deductQuantityInput.addEventListener('input', validateDeduction);
        deductTypeSelect.addEventListener('change', validateDeduction);
    }
    
    // Store validate function globally
    window.validateDeduction = validateDeduction;
}

// Toggle history function
// Make sure it's globally accessible
// Toggle history function with filter reattachment
window.toggleHistory = function() {
    const container = document.getElementById('historyContainer');
    const icon = document.getElementById('historyIcon');
    
    if (!container) return;
    
    if (container.classList.contains('show')) {
        container.classList.remove('show');
        if (icon) {
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    } else {
        container.classList.add('show');
        if (icon) {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        }
        
        // Reattach filter listeners after container is shown
        setTimeout(() => {
            const filterType = document.getElementById('historyFilterType');
            const searchInput = document.getElementById('historySearchInput');
            const clearBtn = document.getElementById('clearHistoryFilters');
            
            // Store the filter function if not already attached
            if (filterType && !filterType.hasListener) {
                const filterAndRender = () => {
                    const logs = window.currentChangeLogs || [];
                    const filterTypeVal = filterType.value;
                    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                    
                    let filteredLogs = [...logs];
                    
                    if (filterTypeVal !== 'all') {
                        filteredLogs = filteredLogs.filter(log => log.action === filterTypeVal);
                    }
                    
                    if (searchTerm) {
                        filteredLogs = filteredLogs.filter(log => 
                            (log.reason && log.reason.toLowerCase().includes(searchTerm)) ||
                            (log.action && log.action.toLowerCase().includes(searchTerm))
                        );
                    }
                    
                    const resultCountSpan = document.getElementById('filterResultCount');
                    if (resultCountSpan) {
                        resultCountSpan.innerText = `${filteredLogs.length} of ${logs.length} records`;
                    }
                    
                    const tbody = document.getElementById('historyTableBody');
                    if (tbody && window.renderFilteredLogRows) {
                        tbody.innerHTML = window.renderFilteredLogRows(filteredLogs);
                    }
                };
                
                filterType.addEventListener('change', filterAndRender);
                if (searchInput) searchInput.addEventListener('input', filterAndRender);
                if (clearBtn) {
                    clearBtn.addEventListener('click', () => {
                        filterType.value = 'all';
                        if (searchInput) searchInput.value = '';
                        filterAndRender();
                    });
                }
                filterType.hasListener = true;
            }
        }, 100);
    }
};
   function renderChangeLogs(logs) {
    if (!logs || logs.length === 0) {
        return `<div style="text-align: center; padding: 20px; color: #999; font-size: 13px;">
                    <i class="fas fa-inbox"></i>
                    <p>No changes recorded yet</p>
                </div>`;
    }
    
    // Create filter options HTML
    const filterHtml = `
        <div style="padding: 10px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <i class="fas fa-filter" style="font-size: 12px; color: #666;"></i>
                <select id="historyFilterType" style="padding: 5px 8px; border-radius: 5px; border: 1px solid #ddd; font-size: 12px;">
                    <option value="all">All Actions</option>
                    <option value="add">Add</option>
                    <option value="deduct">Deduct</option>
                    <option value="undo">Undo</option>
                </select>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <i class="fas fa-search" style="font-size: 12px; color: #666;"></i>
                <input type="text" id="historySearchInput" placeholder="Search by reason..." style="padding: 5px 8px; border-radius: 5px; border: 1px solid #ddd; font-size: 12px; width: 180px;">
            </div>
            <button id="clearHistoryFilters" style="background: #e0e0e0; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 11px;">
                <i class="fas fa-times"></i> Clear
            </button>
            <span style="font-size: 11px; color: #666; margin-left: auto;" id="filterResultCount">${logs.length} records</span>
        </div>
    `;
    
    // Function to filter and render logs
    const filterAndRender = () => {
        const filterType = document.getElementById('historyFilterType') ? document.getElementById('historyFilterType').value : 'all';
        const searchTerm = document.getElementById('historySearchInput') ? document.getElementById('historySearchInput').value.toLowerCase() : '';
        
        let filteredLogs = [...logs];
        
        // Filter by action type
        if (filterType !== 'all') {
            filteredLogs = filteredLogs.filter(log => log.action === filterType);
        }
        
        // Filter by search term (reason)
        if (searchTerm) {
            filteredLogs = filteredLogs.filter(log => 
                (log.reason && log.reason.toLowerCase().includes(searchTerm)) ||
                (log.action && log.action.toLowerCase().includes(searchTerm)) ||
                (log.transfer_to && log.transfer_to.toLowerCase().includes(searchTerm))
            );
        }
        
        // Update result count
        const resultCountSpan = document.getElementById('filterResultCount');
        if (resultCountSpan) {
            resultCountSpan.innerText = `${filteredLogs.length} of ${logs.length} records`;
        }
        
        // Render filtered logs
        const tbody = document.getElementById('historyTableBody');
        if (tbody) {
            tbody.innerHTML = renderFilteredLogRows(filteredLogs);
        }
    };
    
    const getActionIcon = (log) => {
        if (log.action === 'add') return '<i class="fas fa-plus-circle" style="color:#4caf50;"></i>';
        if (log.action === 'deduct') return '<i class="fas fa-minus-circle" style="color:#f44336;"></i>';
        if (log.action === 'undo') return '<i class="fas fa-undo-alt" style="color:#ff9800;"></i>';
        return '<i class="fas fa-exchange-alt" style="color:#ff9800;"></i>';
    };
    
    const getActionText = (log) => {
        if (log.action === 'add') {
            return `Added <strong>${log.quantity_change}</strong> item(s)`;
        }
        if (log.action === 'deduct') {
            const toStatus = log.transfer_to === 'maintenance' ? 'Maintenance' : 'Lost/Damaged';
            return `Deducted <strong>${log.quantity_change}</strong> item(s) → ${toStatus}`;
        }
        if (log.action === 'undo') {
            return `Undid previous action`;
        }
        return `Status changed`;
    };
    
    const renderFilteredLogRows = (filteredLogs) => {
        if (filteredLogs.length === 0) {
            return `<tr><td colspan="4" style="text-align: center; padding: 30px; color: #999;">No matching records found</td></tr>`;
        }
        
        return filteredLogs.slice(0, 50).map(log => `
            <tr class="log-item" style="border-bottom: 1px solid #eee; ${log.is_undone ? 'opacity: 0.5; background: #f0f0f0;' : ''}">
                <td style="padding: 8px;">${getActionIcon(log)} ${log.action}</td>
                <td style="padding: 8px;">
                    ${getActionText(log)}
                    ${log.reason ? `<div style="font-size: 10px; color: #999; margin-top: 2px;">${escapeHtml(log.reason.substring(0, 60))}${log.reason.length > 60 ? '...' : ''}</div>` : ''}
                    ${log.is_undone ? `<span style="font-size: 10px; color: #f44336;">(Undone)</span>` : ''}
                </td>
                <td style="padding: 8px; font-size: 11px;">${new Date(log.created_at).toLocaleString()}</td>
                <td style="padding: 8px; text-align: center;">
                    ${!log.is_undone && log.action !== 'undo' ? `<button type="button" class="undo-btn" onclick="undoChange(${log.id})" style="padding: 3px 8px; font-size: 11px;">
                        <i class="fas fa-undo"></i> Undo
                    </button>` : ''}
                </td>
            </tr>
        `).join('') + (filteredLogs.length > 50 ? `<tr><td colspan="4" style="text-align: center; padding: 8px; font-size: 11px; color: #999;">Showing first 50 of ${filteredLogs.length} matching records</td></tr>` : '');
    };
    
    // Initial render
    const tableHtml = `
        <div style="width: 100%;">
            ${filterHtml}
            <div style="max-height: 300px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead style="background: #f5f5f5; position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 10px; text-align: left;">Action</th>
                            <th style="padding: 10px; text-align: left;">Details</th>
                            <th style="padding: 10px; text-align: left;">Date</th>
                            <th style="padding: 10px; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        ${renderFilteredLogRows(logs.slice(0, 50))}
                    </tbody>
                </table>
            </div>
        </div>
    `;
    
    // Use setTimeout to attach event listeners after DOM is updated
    setTimeout(() => {
        const filterType = document.getElementById('historyFilterType');
        const searchInput = document.getElementById('historySearchInput');
        const clearBtn = document.getElementById('clearHistoryFilters');
        
        if (filterType) filterType.addEventListener('change', filterAndRender);
        if (searchInput) searchInput.addEventListener('input', filterAndRender);
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (filterType) filterType.value = 'all';
                if (searchInput) searchInput.value = '';
                filterAndRender();
            });
        }
    }, 0);
    
    return tableHtml;
}
}

// Add Items Function with modal confirmation (reason required)
window.addItems = async function() {
    const qty = parseInt(document.getElementById('addQuantity').value);
    const reason = document.getElementById('addReason').value.trim();
    
    if (!qty || qty < 1) {
        showErrorModal('Please enter a valid quantity to add.', 'Invalid Quantity');
        return;
    }
    
    if (!reason) {
        showErrorModal('Please provide a reason for adding items.', 'Reason Required');
        return;
    }
    
    const confirmMessage = `Are you sure you want to add ${qty} new item(s)?\n\nReason: ${reason}`;
    const confirmed = await showConfirmModal(confirmMessage, 'Confirm Addition');
    
    if (!confirmed) return;
    
    const formData = new FormData();
    formData.append('action', 'add_equipment_items');
    formData.append('equipment_id', currentManagementData.equipmentId);
    formData.append('quantity', qty);
    formData.append('reason', reason);
    
    const addBtn = event.target;
    const originalText = addBtn.innerHTML;
    addBtn.disabled = true;
    addBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text.substring(0, 500));
            throw new Error('Server returned invalid response');
        }
        
        if (data.success) {
            showSuccessModal(data.message, 'Items Added');
            // Refresh the management modal
            showCombinedManagementModal(currentManagementData.item, currentManagementData.globalId, currentManagementData.type);
        } else {
            showErrorModal(data.message || 'Failed to add items', 'Error');
            addBtn.disabled = false;
            addBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal(error.message || 'An error occurred', 'Error');
        addBtn.disabled = false;
        addBtn.innerHTML = originalText;
    }
};
// Deduct Items Function with modal confirmation (reason optional)
window.deductItems = async function() {
    const qty = parseInt(document.getElementById('deductQuantity').value);
    const deductType = document.getElementById('deductType').value;
    const reason = document.getElementById('deductReason').value.trim();
    
    if (!qty || qty < 1) {
        showErrorModal('Please enter a valid quantity to deduct.', 'Invalid Quantity');
        return;
    }
    
    // Validate available quantity
    if (qty > window.currentStatusValues.available) {
        showErrorModal(`Not enough available items! Available: ${window.currentStatusValues.available}`, 'Invalid Quantity');
        return;
    }
    
    // Reason is now optional for deduction
    const statusDisplay = deductType === 'lost' ? 'Lost/Damaged' : 'Maintenance';
    let confirmMessage = `Are you sure you want to deduct ${qty} item(s) to ${statusDisplay}?`;
    if (reason) {
        confirmMessage += `\n\nReason: ${reason}`;
    }
    
    const confirmed = await showConfirmModal(confirmMessage, 'Confirm Deduction');
    
    if (!confirmed) return;
    
    const formData = new FormData();
    formData.append('action', 'deduct_items');
    formData.append('equipment_id', currentManagementData.equipmentId);
    formData.append('quantity', qty);
    formData.append('to_status', deductType);
    formData.append('reason', reason || 'No reason provided');
    
    const deductBtn = event.target;
    const originalText = deductBtn.innerHTML;
    deductBtn.disabled = true;
    deductBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON:', text.substring(0, 500));
            throw new Error('Server returned invalid response');
        }
        
        if (data.success) {
            showSuccessModal(`Successfully deducted ${qty} item(s) to ${statusDisplay}.`, 'Items Deducted');
            // Refresh the management modal
            showCombinedManagementModal(currentManagementData.item, currentManagementData.globalId, currentManagementData.type);
        } else {
            showErrorModal(data.message || 'Failed to deduct items', 'Error');
            deductBtn.disabled = false;
            deductBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal(error.message || 'An error occurred', 'Error');
        deductBtn.disabled = false;
        deductBtn.innerHTML = originalText;
    }
};

// Undo Change Function with modal confirmation
window.undoChange = async function(logId) {
    // Use modal confirmation instead of confirm()
    const confirmed = await showConfirmModal(
        'Are you sure you want to undo this change? This action cannot be undone again.',
        'Confirm Undo'
    );
    
    if (!confirmed) return;
    
    // Show loading indicator
    const undoBtn = event.target;
    const originalText = undoBtn.innerHTML;
    undoBtn.disabled = true;
    undoBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Undoing...';
    
    const formData = new FormData();
    formData.append('action', 'undo_change');
    formData.append('log_id', logId);
    
    try {
        const response = await fetch('equipment_ajax.php', { 
            method: 'POST', 
            body: formData 
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text.substring(0, 500));
            throw new Error('Server returned invalid response. Please check error logs.');
        }
        
        if (data.success) {
            showSuccessModal(data.message, 'Change Undone');
            // Refresh the management modal
            showCombinedManagementModal(currentManagementData.item, currentManagementData.globalId, currentManagementData.type);
        } else {
            showErrorModal(data.message || 'Failed to undo change', 'Error');
            undoBtn.disabled = false;
            undoBtn.innerHTML = originalText;
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal(error.message || 'An error occurred while undoing', 'Error');
        undoBtn.disabled = false;
        undoBtn.innerHTML = originalText;
    }
};

// Update Combined Management (Status + Quantity - ADD only)
async function updateCombinedManagement(event, globalId, currentTotalQty) {
    event.preventDefault();
    
    const equipmentId = parseInt(globalId.replace(/[^0-9]/g, ''));
    
    // Get status distribution values
    const maintenance = parseInt(document.getElementById('statusMaintenance').value) || 0;
    const lost = parseInt(document.getElementById('statusLost').value) || 0;
    const available = currentTotalQty - (maintenance + lost);
    
    // Validate distribution
    if (maintenance + lost > currentTotalQty) {
        showErrorModal(`Maintenance + Lost (${maintenance + lost}) cannot exceed total quantity (${currentTotalQty}).`, 'Validation Error');
        return;
    }
    
    // Get add quantity
    const addQuantity = parseInt(document.getElementById('addQuantity').value) || 0;
    const addReason = document.getElementById('addReason') ? document.getElementById('addReason').value.trim() : '';
    
    const submitBtn = document.getElementById('combinedSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    try {
        // First, update status distribution (Maintenance and Lost)
        let statusFormData = new FormData();
        statusFormData.append('action', 'update_equipment_status');
        statusFormData.append('equipment_id', equipmentId);
        statusFormData.append('available', available);
        statusFormData.append('maintenance', maintenance);
        statusFormData.append('lost', lost);
        
        const statusResponse = await fetch('equipment_ajax.php', { method: 'POST', body: statusFormData });
        const statusData = await statusResponse.json();
        
        if (!statusData.success) {
            showErrorModal(statusData.message || 'Failed to update status distribution', 'Error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            return;
        }
        
        // Second, add items if quantity > 0
        let addMessage = '';
        if (addQuantity > 0) {
            if (!addReason) {
                showErrorModal('Please provide a reason for adding new items.', 'Reason Required');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                return;
            }
            
            let addFormData = new FormData();
            addFormData.append('action', 'add_equipment_items');
            addFormData.append('equipment_id', equipmentId);
            addFormData.append('quantity', addQuantity);
            addFormData.append('reason', addReason);
            
            const addResponse = await fetch('equipment_ajax.php', { method: 'POST', body: addFormData });
            const addData = await addResponse.json();
            
            if (!addData.success) {
                showErrorModal(addData.message || 'Failed to add items', 'Error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                return;
            }
            addMessage = ` Added ${addQuantity} new item(s).`;
        }
        
        showSuccessModal(`Status distribution updated successfully!${addMessage}`, 'Success');
        closeEquipmentModal();
        triggerRefresh();
        
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred while saving changes', 'Error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

// Show Status Distribution Modal (Equipment only) - Kept for backward compatibility
function showStatusDistributionModal(item, globalId) {
    // Redirect to combined management
    showCombinedManagementModal(item, globalId, 'equipment');
}

// Show Edit Quantity Modal (Equipment only) - Kept for backward compatibility
function showEditQuantityModal(item, globalId) {
    // Redirect to combined management
    showCombinedManagementModal(item, globalId, 'equipment');
}

// ============ UPDATE BY GLOBAL ID ============

async function updateEquipmentByGlobalId(event, globalId, sourceTable) {
    event.preventDefault();
    
    // Get category from hidden field (since select is disabled)
    const category = document.getElementById('eqCategoryHidden') ? 
        document.getElementById('eqCategoryHidden').value : 
        document.getElementById('eqCategory').value;
    
    const formData = new FormData();
    formData.append('action', 'update_equipment');
    formData.append('id', globalId);
    formData.append('type', sourceTable);
    formData.append('name', document.getElementById('eqName').value);
    formData.append('description', document.getElementById('eqDescription').value);
    formData.append('category', category);
    formData.append('status', document.getElementById('eqStatus').value);
    
    if (document.getElementById('eqQuantity')) {
        formData.append('total_quantity', document.getElementById('eqQuantity').value);
    }
    if (document.getElementById('eqLocation')) {
        formData.append('location', document.getElementById('eqLocation').value);
    }
    if (document.getElementById('eqCapacity')) {
        formData.append('capacity', document.getElementById('eqCapacity').value);
    }
    if (document.getElementById('eqPlateNumber')) {
        formData.append('plate_number', document.getElementById('eqPlateNumber').value);
    }
    if (document.getElementById('eqVehicleType')) {
        formData.append('vehicle_type', document.getElementById('eqVehicleType').value);
    }
    
    const removeImageCheckbox = document.getElementById('removeImage');
    if (removeImageCheckbox && removeImageCheckbox.checked) {
        formData.append('remove_image', 'true');
    }
    
    const imageFile = document.getElementById('eqImage').files[0];
    if (imageFile) {
        formData.append('equipment_image', imageFile);
    }
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showSuccessModal(data.message || 'Equipment updated successfully!', 'Success');
            closeEquipmentModal();
            triggerRefresh();
        } else {
            showErrorModal(data.message || 'Failed to update equipment', 'Error');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred', 'Error');
    }
}

// ============ DELETE FUNCTIONS ============

function deleteEquipmentItem(id, name, type) {
    const confirmMessage = `Delete "${name}"? This action cannot be undone.`;
    showConfirmationModal(confirmMessage, 'Confirm Delete', async () => {
        const formData = new FormData();
        formData.append('action', 'delete_equipment');
        formData.append('id', id);
        formData.append('type', type);
        try {
            const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                showSuccessModal('Deleted successfully!', 'Deleted');
                triggerRefresh();
            } else {
                showErrorModal(data.message || 'Failed to delete', 'Error');
            }
        } catch (error) {
            showErrorModal('An error occurred', 'Error');
        }
    }, null, 'danger');
}

function deleteIndividualItem(id, itemName) {
    const confirmMessage = `Delete "${itemName}"? This action cannot be undone. All booking history for this item will be removed.`;
    
    showConfirmationModal(confirmMessage, 'Confirm Delete', function() {
        const formData = new FormData();
        formData.append('action', 'delete_equipment_item');
        formData.append('id', id);
        
        fetch('equipment_ajax.php', { method: 'POST', body: formData })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    if (typeof showSuccessModal === 'function') {
                        showSuccessModal('Item deleted successfully!', 'Deleted');
                    } else {
                        alert('Item deleted successfully!');
                    }
                    triggerRefresh();
                } else {
                    if (typeof showErrorModal === 'function') {
                        showErrorModal(data.message || 'Failed to delete item', 'Error');
                    } else {
                        alert(data.message || 'Failed to delete item');
                    }
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                if (typeof showErrorModal === 'function') {
                    showErrorModal('An error occurred', 'Error');
                } else {
                    alert('An error occurred');
                }
            });
    }, null, 'danger');
}

// ============ EQUIPMENT LIST FUNCTIONS ============

async function loadEquipmentList() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading equipment...</p></div></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment&category=${currentEquipmentFilter}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text.substring(0, 500));
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Server error: Invalid response. Please check error logs.</p></div></div>`;
            return;
        }
        
        if (data.success) {
            dashboardBody.innerHTML = renderEquipmentList(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${escapeHtml(data.message || 'Failed to load equipment')}</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data: ${escapeHtml(error.message)}</p></div></div>`;
    }
}

function renderEquipmentList(equipment) {
    if (!equipment || equipment.length === 0) {
        return `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-tools"></i> Equipment & Facilities</div>
                <div class="section-sub">Manage barangay equipment and facilities</div>
                <div style="margin-bottom:20px;">
                    <button class="btn-verify" onclick="showAddEquipmentModal()"><i class="fas fa-plus"></i> Add Equipment</button>
                </div>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <p>No equipment or facilities found.</p>
                    <button class="btn-verify" onclick="showAddEquipmentModal()" style="margin-top:15px;">Add Your First Equipment</button>
                </div>
            </div>
        `;
    }
    
    const categories = [...new Set(equipment.map(e => e.category))];
    const filterButtons = `
    <div class="filter-bar">
        <button class="filter-chip ${currentEquipmentFilter === 'all' ? 'active' : ''}" onclick="filterEquipment('all')">All</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Equipment' ? 'active' : ''}" onclick="filterEquipment('Equipment')">Equipment</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Facility' ? 'active' : ''}" onclick="filterEquipment('Facility')">Facility</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Vehicle' ? 'active' : ''}" onclick="filterEquipment('Vehicle')">Vehicle</button>
        <button class="btn-verify" onclick="showAddEquipmentModal()" style="margin-left:auto;"><i class="fas fa-plus"></i> Add Equipment</button>
    </div>
`;
    
    const cardsHtml = equipment.map(item => {
        const imagePath = item.image ? '../' + item.image : null;
        
        let defaultIcon = 'fa-tools';
        if (item.category === 'Facility') defaultIcon = 'fa-building';
        else if (item.category === 'Vehicle') defaultIcon = 'fa-truck';
        
        const availableCount = parseInt(item.available_count) || 0;
        const borrowedCount = parseInt(item.borrowed_count) || 0;
        const maintenanceCount = parseInt(item.maintenance_count) || 0;
        const lostCount = parseInt(item.lost_count) || 0;
        const totalItems = parseInt(item.total_items) || 0;
        
        let overallStatus = '';
        let overallStatusClass = '';
        if (availableCount > 0) {
            overallStatus = `${availableCount} available`;
            overallStatusClass = 'status-available';
        } else if (borrowedCount > 0) {
            overallStatus = 'All Borrowed';
            overallStatusClass = 'status-borrowed';
        } else if (maintenanceCount > 0) {
            overallStatus = 'Under Maintenance';
            overallStatusClass = 'status-maintenance';
        } else {
            overallStatus = 'Unavailable';
            overallStatusClass = 'status-unavailable';
        }
        
        let itemType = 'equipment';
        if (item.category === 'Facility') itemType = 'facility';
        else if (item.category === 'Vehicle') itemType = 'vehicle';
        
        return `
            <div class="data-card shopee-card" data-id="${item.id}" data-name="${escapeHtml(item.name)}">
                <div class="card-actions-menu shopee-menu">
                    <button class="kebab-menu" onclick="event.stopPropagation(); toggleKebabMenu(${item.id})">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="kebab-dropdown" id="kebabMenu-${item.id}">
                        <div class="kebab-item" onclick="event.stopPropagation(); editEquipment(${item.id}, '${itemType}')">
                            <i class="fas fa-edit"></i> Edit
                        </div>
                        <div class="kebab-item" onclick="event.stopPropagation(); deleteEquipmentItem(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}', '${itemType}')">
                            <i class="fas fa-trash"></i> Delete
                        </div>
                    </div>
                </div>
                <div class="shopee-image-container" onclick="showEquipmentItems(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    ${imagePath ? 
                        `<img src="${imagePath}" class="shopee-image" alt="${escapeHtml(item.name)}" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-fallback\'><i class=\'fas ${defaultIcon}\'></i></div>';">` : 
                        `<div class="image-fallback"><i class="fas ${defaultIcon}"></i></div>`
                    }
                </div>
                <div class="shopee-info" onclick="showEquipmentItems(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    <div class="shopee-title">${escapeHtml(item.name)}</div>
                    <div class="shopee-category">${escapeHtml(item.category)}</div>
                    <div class="shopee-status">
                        <span class="status-badge ${overallStatusClass}">
                            <i class="fas ${availableCount > 0 ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${overallStatus}
                        </span>
                    </div>
                    ${item.description ? `<div class="shopee-description">${escapeHtml(item.description.substring(0, 80))}${item.description.length > 80 ? '...' : ''}</div>` : ''}
                </div>
                <div class="shopee-footer" onclick="showEquipmentItems(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    <div class="stats-row" style="display:flex; gap:8px; flex-wrap:wrap; font-size:0.65rem;">
                        ${availableCount > 0 ? `<span style="color:#28a745;"><i class="fas fa-check-circle"></i> ${availableCount} Available</span>` : ''}
                        ${borrowedCount > 0 ? `<span style="color:#17a2b8;"><i class="fas fa-hand-holding"></i> ${borrowedCount} Borrowed</span>` : ''}
                        ${maintenanceCount > 0 ? `<span style="color:#f4b942;"><i class="fas fa-wrench"></i> ${maintenanceCount} Maintenance</span>` : ''}
                        ${lostCount > 0 ? `<span style="color:#dc3545;"><i class="fas fa-question-circle"></i> ${lostCount} Lost</span>` : ''}
                    </div>
                    <div class="shopee-action">
                        <i class="fas fa-list"></i> View Items (${totalItems})
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-tools"></i> Equipment & Facilities</div>
            <div class="section-sub">Manage barangay equipment, facilities, and resources</div>
            ${filterButtons}
            <div class="cards-grid shopee-grid">
                ${cardsHtml}
            </div>
        </div>
    `;
}

async function showEquipmentItems(typeId, typeName) {
    const modal = document.getElementById('equipmentItemsModal');
    const modalTitle = document.getElementById('equipmentItemsModalTitle');
    const modalBody = document.getElementById('equipmentItemsModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    currentEquipmentTypeId = typeId;
    currentEquipmentTypeName = typeName;
    
    modalTitle.innerHTML = `<i class="fas fa-boxes"></i> Items: ${escapeHtml(typeName)}`;
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading items...</p></div>`;
    modal.style.display = 'block';
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment_items&type_id=${typeId}`);
        const data = await response.json();
        if (data.success) {
            modalBody.innerHTML = renderEquipmentItemsList(data.data, typeName);
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>Failed to load items</p></div>`;
        }
    } catch (error) {
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading items</p></div>`;
    }
}

function renderEquipmentItemsList(items, typeName) {
    if (!items || items.length === 0) {
        return `<div class="empty-state-mini"><p>No items found for ${escapeHtml(typeName)}</p></div>`;
    }
    
    const getStatusBadge = (status) => {
        switch(status) {
            case 'available': return '<span class="badge-verified"><i class="fas fa-check-circle"></i> Available</span>';
            case 'borrowed': return '<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>';
            case 'maintenance': return '<span class="badge-unverified" style="background:#fff3cd;color:#856404;"><i class="fas fa-wrench"></i> Maintenance</span>';
            case 'lost': return '<span class="status-unverified" style="background:#f8d7da;color:#721c24;"><i class="fas fa-question-circle"></i> Lost</span>';
            default: return '<span class="badge-unverified">' + status + '</span>';
        }
    };
    
    return `
        <div class="items-grid" style="display:grid; gap:15px;">
            ${items.map(item => `
                <div class="item-card" style="background:#f8faf8; border-radius:12px; padding:15px; border:1px solid #e2efe8;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <strong style="font-size:1.1rem;">${escapeHtml(item.item_name)}</strong>
                            <div style="font-size:0.8rem; color:#666; margin-top:5px;">
                                <i class="fas fa-tag"></i> Item #${item.item_number}
                            </div>
                        </div>
                        <div>
                            ${getStatusBadge(item.status)}
                        </div>
                    </div>
                    ${item.condition_notes ? `
                        <div style="margin-top:10px; font-size:0.75rem; background:#eef5f0; padding:8px; border-radius:8px;">
                            <i class="fas fa-sticky-note"></i> <strong>Notes:</strong> ${escapeHtml(item.condition_notes)}
                        </div>
                    ` : ''}
                    <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn-verify" onclick="manageItem(${item.id}, '${escapeHtml(item.item_name)}', '${item.status}', '${escapeHtml(item.condition_notes || '')}')" style="background:#17a2b8; padding:6px 12px; font-size:12px;">
                            <i class="fas fa-edit"></i> Manage Status
                        </button>
                        <button class="btn-verify" onclick="showEquipmentSchedule(${item.id}, '${escapeHtml(item.item_name)}')" style="background:#28a745; padding:6px 12px; font-size:12px;">
                            <i class="fas fa-calendar-alt"></i> View Schedule
                        </button>
                        <button class="btn-verify" onclick="deleteIndividualItem(${item.id}, '${escapeHtml(item.item_name)}')" style="background:#dc3545; padding:6px 12px; font-size:12px;">
                            <i class="fas fa-trash"></i> Delete Item
                        </button>
                    </div>
                    <div style="margin-top:8px; font-size:0.7rem; color:#999;">
                        <i class="fas fa-calendar"></i> Added: ${new Date(item.created_at).toLocaleDateString()}
                        ${item.updated_at !== item.created_at ? `<br><i class="fas fa-history"></i> Last updated: ${new Date(item.updated_at).toLocaleDateString()}` : ''}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function toggleKebabMenu(id) {
    document.querySelectorAll('.kebab-dropdown.show').forEach(menu => {
        if (menu.id !== `kebabMenu-${id}`) {
            menu.classList.remove('show');
        }
    });
    
    const menu = document.getElementById(`kebabMenu-${id}`);
    if (menu) {
        menu.classList.toggle('show');
    }
}

document.addEventListener('click', function() {
    document.querySelectorAll('.kebab-dropdown.show').forEach(menu => {
        menu.classList.remove('show');
    });
});

function filterEquipment(category) {
    currentEquipmentFilter = category === 'all' ? 'all' : category;
    loadEquipmentList();
}

// ============ EQUIPMENT SCHEDULE CALENDAR FUNCTIONS ============

async function showEquipmentSchedule(equipmentId, equipmentName) {
    const modal = document.getElementById('scheduleModal');
    const modalTitle = document.getElementById('scheduleModalTitle');
    const modalBody = document.getElementById('scheduleModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    currentScheduleEquipmentId = equipmentId;
    currentScheduleEquipmentName = equipmentName;
    
    modalTitle.innerHTML = `<i class="fas fa-calendar-alt"></i> Schedule: ${escapeHtml(equipmentName)}`;
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading schedule...</p></div>`;
    modal.style.display = 'block';
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment_bookings&id=${equipmentId}`);
        const data = await response.json();
        
        if (data.success) {
            modalBody.innerHTML = renderScheduleCalendar(equipmentId, equipmentName, data.bookings);
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>Failed to load schedule</p></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading schedule</p></div>`;
    }
}

function renderScheduleCalendar(equipmentId, equipmentName, bookings) {
    const year = currentCalendarYear;
    const month = currentCalendarMonth;
    
    const calendarHtml = generateCalendarHtml(year, month, bookings);
    const bookingsListHtml = generateBookingsListHtml(bookings);
    
    return `
        <div class="schedule-container">
            <div class="schedule-header">
                <div class="schedule-info">
                    <p><strong>Equipment:</strong> ${escapeHtml(equipmentName)}</p>
                    <p><strong>Total Bookings:</strong> ${bookings.length}</p>
                </div>
                <div class="schedule-legend">
                    <span class="legend-badge pending"><i class="fas fa-clock"></i> Pending</span>
                    <span class="legend-badge approved"><i class="fas fa-check-circle"></i> Approved</span>
                    <span class="legend-badge borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>
                    <span class="legend-badge returned"><i class="fas fa-undo-alt"></i> Returned</span>
                    <span class="legend-badge rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                                    </div>
            </div>
            
            <div class="calendar-wrapper">
                ${calendarHtml}
            </div>
            
            <div class="bookings-list-wrapper">
                <h4><i class="fas fa-list"></i> All Bookings</h4>
                ${bookingsListHtml}
            </div>
        </div>
    `;
}

function generateCalendarHtml(year, month, bookings) {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const startingDayOfWeek = firstDayOfMonth.getDay();
    const daysInMonth = lastDayOfMonth.getDate();
    
    const bookingsByDate = {};
    bookings.forEach(booking => {
        const bookingDate = new Date(booking.booking_date);
        const dateKey = `${bookingDate.getFullYear()}-${bookingDate.getMonth() + 1}-${bookingDate.getDate()}`;
        if (!bookingsByDate[dateKey]) {
            bookingsByDate[dateKey] = [];
        }
        bookingsByDate[dateKey].push(booking);
    });
    
    let calendarHtml = `
        <div class="calendar-header">
            <button class="calendar-nav" onclick="changeCalendarMonth(${year}, ${month}, -1)"><i class="fas fa-chevron-left"></i></button>
            <h3>${monthNames[month]} ${year}</h3>
            <button class="calendar-nav" onclick="changeCalendarMonth(${year}, ${month}, 1)"><i class="fas fa-chevron-right"></i></button>
        </div>
        <div class="calendar-weekdays">
            <div class="weekday">Sun</div>
            <div class="weekday">Mon</div>
            <div class="weekday">Tue</div>
            <div class="weekday">Wed</div>
            <div class="weekday">Thu</div>
            <div class="weekday">Fri</div>
            <div class="weekday">Sat</div>
        </div>
        <div class="calendar-days">
    `;
    
    for (let i = 0; i < startingDayOfWeek; i++) {
        calendarHtml += `<div class="calendar-day empty"></div>`;
    }
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dateKey = `${year}-${month + 1}-${day}`;
        const dayBookings = bookingsByDate[dateKey] || [];
        const hasBookings = dayBookings.length > 0;
        
        let statusClass = '';
        let statusIcons = '';
        
        if (hasBookings) {
            const hasPending = dayBookings.some(b => b.status === 'pending');
            const hasApproved = dayBookings.some(b => b.status === 'approved');
            const hasBorrowed = dayBookings.some(b => b.status === 'borrowed');
            const hasReturned = dayBookings.some(b => b.status === 'returned');
            
            if (hasPending) statusClass = 'has-pending';
            else if (hasBorrowed) statusClass = 'has-borrowed';
            else if (hasApproved) statusClass = 'has-approved';
            else if (hasReturned) statusClass = 'has-returned';
            
            const tooltipContent = dayBookings.map(b => {
                const statusIcon = b.status === 'pending' ? '⏳' : (b.status === 'approved' ? '✓' : (b.status === 'borrowed' ? '📋' : (b.status === 'returned' ? '↺' : '✗')));
                return `${statusIcon} ${escapeHtml(b.first_name)} ${escapeHtml(b.last_name)} - ${b.status}`;
            }).join('<br>');
            
            statusIcons = `<div class="booking-indicators">${dayBookings.slice(0, 3).map(() => '<i class="fas fa-calendar-check"></i>').join('')}</div>`;
            calendarHtml += `
                <div class="calendar-day ${statusClass}" data-tooltip="${escapeHtml(tooltipContent)}" onclick="viewDayBookings(${year}, ${month}, ${day})">
                    <span class="day-number">${day}</span>
                    ${statusIcons}
                    ${dayBookings.length > 3 ? `<small class="more-indicator">+${dayBookings.length - 3}</small>` : ''}
                </div>
            `;
        } else {
            calendarHtml += `<div class="calendar-day"><span class="day-number">${day}</span></div>`;
        }
    }
    
    calendarHtml += `</div>`;
    return calendarHtml;
}

function generateBookingsListHtml(bookings) {
    if (!bookings || bookings.length === 0) {
        return `<div class="empty-state-mini"><p>No bookings found for this equipment.</p></div>`;
    }
    
    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    };
    
    const getStatusClass = (status) => {
        switch(status) {
            case 'pending': return 'badge-unverified';
            case 'approved': return 'badge-verified';
            case 'borrowed': return 'badge-borrowed';
            case 'returned': return 'badge-returned';
            case 'rejected': return 'status-unverified';
            default: return 'badge-unverified';
        }
    };
    
    const getStatusIcon = (status) => {
        switch(status) {
            case 'pending': return '<i class="fas fa-clock"></i>';
            case 'approved': return '<i class="fas fa-check-circle"></i>';
            case 'borrowed': return '<i class="fas fa-hand-holding"></i>';
            case 'returned': return '<i class="fas fa-undo-alt"></i>';
            case 'rejected': return '<i class="fas fa-times-circle"></i>';
            default: return '<i class="fas fa-question-circle"></i>';
        }
    };
    
    const sortedBookings = [...bookings].sort((a, b) => new Date(b.booking_date) - new Date(a.booking_date));
    
    return `
        <div class="bookings-list">
            ${sortedBookings.map(booking => `
                <div class="booking-item" onclick="viewBookingDetails(${booking.id})">
                    <div class="booking-item-header">
                        <div class="booking-resident">
                            <strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong>
                        </div>
                        <div class="booking-status ${getStatusClass(booking.status)}">
                            ${getStatusIcon(booking.status)} ${booking.status}
                        </div>
                    </div>
                    <div class="booking-item-details">
                        <div><i class="fas fa-calendar"></i> ${formatDate(booking.booking_date)}</div>
                        <div><i class="fas fa-hourglass-half"></i> ${booking.duration_days || 1} day(s)</div>
                        ${booking.purpose ? `<div><i class="fas fa-comment"></i> ${escapeHtml(booking.purpose.substring(0, 100))}</div>` : ''}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

async function changeCalendarMonth(year, month, delta) {
    const newMonth = month + delta;
    let newYear = year;
    let newMonthIndex = newMonth;
    
    if (newMonth < 0) {
        newYear = year - 1;
        newMonthIndex = 11;
    } else if (newMonth > 11) {
        newYear = year + 1;
        newMonthIndex = 0;
    }
    
    currentCalendarYear = newYear;
    currentCalendarMonth = newMonthIndex;
    
    if (currentScheduleEquipmentId) {
        const response = await fetch(`equipment_ajax.php?action=get_equipment_bookings&id=${currentScheduleEquipmentId}`);
        const data = await response.json();
        if (data.success) {
            const modalBody = document.getElementById('scheduleModalBody');
            if (modalBody) {
                modalBody.innerHTML = renderScheduleCalendar(currentScheduleEquipmentId, currentScheduleEquipmentName, data.bookings);
            }
        }
    }
}

async function viewDayBookings(year, month, day) {
    const selectedDate = new Date(year, month, day);
    const formattedDate = selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    
    const allBookings = await fetchEquipmentBookings(currentScheduleEquipmentId);
    const dayBookings = allBookings.filter(booking => {
        const bookingDate = new Date(booking.booking_date);
        return bookingDate.getFullYear() === year && 
               bookingDate.getMonth() === month && 
               bookingDate.getDate() === day;
    });
    
    if (dayBookings.length === 0) {
        if (typeof showInfoModal === 'function') {
            showInfoModal(`No bookings on ${formattedDate}`, 'No Bookings');
        } else {
            alert(`No bookings on ${formattedDate}`);
        }
        return;
    }
    
    showDayBookingsModal(formattedDate, dayBookings);
}

async function fetchEquipmentBookings(equipmentId) {
    const response = await fetch(`equipment_ajax.php?action=get_equipment_bookings&id=${equipmentId}`);
    const data = await response.json();
    return data.success ? data.bookings : [];
}

function showDayBookingsModal(date, bookings) {
    const modal = document.getElementById('dayBookingsModal');
    const modalTitle = document.getElementById('dayBookingsModalTitle');
    const modalBody = document.getElementById('dayBookingsModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = `<i class="fas fa-calendar-day"></i> Bookings for ${date}`;
    
    const formatDateTime = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    };
    
    const getStatusClass = (status) => {
        switch(status) {
            case 'pending': return 'badge-unverified';
            case 'approved': return 'badge-verified';
            case 'borrowed': return 'badge-borrowed';
            case 'returned': return 'badge-returned';
            case 'rejected': return 'status-unverified';
            default: return 'badge-unverified';
        }
    };
    
    modalBody.innerHTML = `
        <div class="day-bookings-list">
            ${bookings.map(booking => `
                <div class="day-booking-item" onclick="viewBookingDetails(${booking.id}); closeDayBookingsModal();">
                    <div class="day-booking-time">
                        <i class="fas fa-clock"></i> ${formatDateTime(booking.booking_date)}
                    </div>
                    <div class="day-booking-info">
                        <div class="day-booking-resident">
                            <strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong>
                        </div>
                        <div class="day-booking-status ${getStatusClass(booking.status)}">
                            ${booking.status}
                        </div>
                    </div>
                    <div class="day-booking-purpose">
                        <i class="fas fa-comment"></i> ${escapeHtml(booking.purpose || 'No purpose specified')}
                    </div>
                    <div class="day-booking-duration">
                        <i class="fas fa-hourglass-half"></i> Duration: ${booking.duration_days || 1} day(s)
                    </div>
                </div>
            `).join('')}
        </div>
    `;
    
    modal.style.display = 'block';
}

function closeDayBookingsModal() {
    const modal = document.getElementById('dayBookingsModal');
    if (modal) modal.style.display = 'none';
}

function closeScheduleModal() {
    const modal = document.getElementById('scheduleModal');
    if (modal) modal.style.display = 'none';
    currentScheduleEquipmentId = null;
    currentScheduleEquipmentName = '';
    currentCalendarYear = new Date().getFullYear();
    currentCalendarMonth = new Date().getMonth();
}

// ============ BOOKINGS FUNCTIONS ============

async function loadBookings(status = 'pending', page = 1) {
    currentBookingFilter = status;
    currentBookingPage = page;
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bookings...</p></div></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_bookings&status=${status}&page=${page}`);
        const data = await response.json();
        if (data.success) {
            dashboardBody.innerHTML = renderBookingsList(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}


// Add these variables near the top of equipment.js with other global variables
let currentBookingView = 'cards'; // 'cards' or 'calendar'
let currentBookingCalendarYear = new Date().getFullYear();
let currentBookingCalendarMonth = new Date().getMonth();

// Add these functions to equipment.js

// ============ BOOKINGS CALENDAR VIEW FUNCTIONS ============

function toggleBookingView() {
    if (currentBookingView === 'cards') {
        currentBookingView = 'calendar';
        loadBookingsCalendar();
    } else {
        currentBookingView = 'cards';
        loadBookings(currentBookingFilter, currentBookingPage);
    }
}

async function loadBookingsCalendar() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading calendar...</p></div></div>`;
    
    try {
        // Get all bookings for filtered status and type
        const typeFilter = document.getElementById('calendarTypeFilterSelect') ? 
            document.getElementById('calendarTypeFilterSelect').value : 
            (window.currentCalendarTypeFilter || 'all');
        
        const response = await fetch(`equipment_ajax.php?action=get_all_bookings&status=${currentBookingFilter}&type=${typeFilter}`);
        const data = await response.json();
        
        if (data.success) {
            dashboardBody.innerHTML = renderBookingsCalendar(data.data.bookings, data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load calendar</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading calendar</p></div></div>`;
    }
}

function renderBookingsCalendar(bookings, metadata) {
    const year = currentBookingCalendarYear;
    const month = currentBookingCalendarMonth;
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const daysInMonth = lastDayOfMonth.getDate();
    const startingDayOfWeek = firstDayOfMonth.getDay();
    
    // Create array of days in the month
    const daysInMonthArray = [];
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        daysInMonthArray.push({
            day: i,
            date: date,
            dayOfWeek: date.getDay(),
            isToday: date.toDateString() === new Date().toDateString()
        });
    }
    
    // Process all bookings and calculate their span in the month
    const processedBookings = [];
    
    bookings.forEach(booking => {
        const startDate = new Date(booking.start_datetime || booking.booking_date);
        const endDate = new Date(booking.end_datetime || booking.return_date);
        
        // Calculate the booking's start and end day numbers within the month
        let startDay = startDate.getDate();
        let endDay = endDate.getDate();
        let startMonth = startDate.getMonth();
        let endMonth = endDate.getMonth();
        let startYear = startDate.getFullYear();
        let endYear = endDate.getFullYear();
        
        // Adjust for bookings that start before this month
        if (startYear < year || (startYear === year && startMonth < month)) {
            startDay = 1;
        }
        
        // Adjust for bookings that end after this month
        if (endYear > year || (endYear === year && endMonth > month)) {
            endDay = daysInMonth;
        }
        
        // FLEXIBLE FIX: Only include if booking overlaps with current month
        // Check if booking actually has any days in this month
        const overlaps = (startYear === year && startMonth === month) || 
                        (endYear === year && endMonth === month) ||
                        (startYear < year && endYear > year) ||
                        (startYear === year && startMonth < month && endYear > year) ||
                        (startYear < year && endYear === year && endMonth > month) ||
                        (startMonth < month && endMonth > month);
        
        if (overlaps) {
            // Calculate exact span based on day difference
            let span;
            if (startDay === endDay) {
                span = 1; // Single day booking
            } else {
                span = endDay - startDay + 1;
            }
            
            // FLEXIBLE ADJUSTMENT: Cap the span to days in month
            if (startDay + span - 1 > daysInMonth) {
                span = daysInMonth - startDay + 1;
            }
            
            processedBookings.push({
                ...booking,
                startDay: startDay,
                endDay: endDay,
                span: span,
                startDate: startDate,
                endDate: endDate
            });
        }
    });
    
    // Sort bookings by span (longer first) then by start day
    processedBookings.sort((a, b) => {
        if (a.span !== b.span) return b.span - a.span;
        return a.startDay - b.startDay;
    });
    
    // Calculate vertical stacking positions (handle overlaps)
    const usedRows = [];
    const MAX_ROWS = 6;
    
    processedBookings.forEach(booking => {
        let rowIndex = 0;
        let placed = false;
        
        while (!placed && rowIndex < MAX_ROWS) {
            let hasConflict = false;
            
            // Check if this row has any booking that overlaps with this booking
            for (let existing of usedRows) {
                if (existing.row === rowIndex) {
                    // Check for overlap
                    if (!(existing.endDay < booking.startDay || existing.startDay > booking.endDay)) {
                        hasConflict = true;
                        break;
                    }
                }
            }
            
            if (!hasConflict) {
                booking.row = rowIndex;
                usedRows.push({
                    row: rowIndex,
                    startDay: booking.startDay,
                    endDay: booking.endDay,
                    id: booking.id
                });
                placed = true;
            }
            rowIndex++;
        }
        
        if (!placed) {
            booking.row = MAX_ROWS - 1;
        }
    });
    
    // Group bookings by row
    const maxRow = Math.max(...processedBookings.map(b => b.row), -1) + 1;
    const totalRows = Math.min(maxRow, MAX_ROWS);
    
    // Build the calendar HTML
    let calendarHtml = `
        <style>
            .booking-calendar {
                background: white;
                border-radius: 16px;
                overflow-x: auto;
                margin-top: 15px;
                border: 1px solid #e2efe8;
            }
            .calendar-table {
                min-width: 800px;
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }
            .calendar-table td {
                width: 14.28%;
                position: relative;
            }
            .calendar-table th {
                padding: 14px 8px;
                background: #f8f9fa;
                border: 1px solid #e2efe8;
                font-weight: 600;
                color: #1a472a;
                text-align: center;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            .calendar-table td {
                border: 1px solid #eef2ef;
                vertical-align: top;
                padding: 0;
                position: relative;
            }
            .day-cell {
                min-height: 80px;
                position: relative;
                cursor: pointer;
                background: white;
            }
            .day-cell.today {
                background: #fff8e1;
            }
            .day-cell:hover {
                background: #f8faf8;
            }
            .day-number {
                position: absolute;
                top: 5px;
                right: 8px;
                font-size: 12px;
                font-weight: 600;
                color: #2c5e3c;
                background: rgba(255,255,255,0.9);
                padding: 2px 6px;
                border-radius: 12px;
                z-index: 2;
            }
            .booking-row {
                position: relative;
                height: 28px;
                margin: 2px 0;
                width: 100%;
            }
            .booking-bar {
                position: absolute;
                top: 2px;
                left: 0;
                height: 24px;
                border-radius: 4px;
                padding: 4px 6px;
                font-size: 11px;
                cursor: pointer;
                transition: all 0.2s;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                color: white;
                font-weight: 500;
                z-index: 20;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                pointer-events: auto;
                line-height: 16px;
                box-sizing: border-box;
            }
            .booking-bar:hover {
                transform: scaleY(1.05);
                z-index: 30;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                white-space: normal;
                overflow: visible;
                height: auto;
                min-height: 24px;
            }
            .booking-bar.pending { background: #ff9800; }
            .booking-bar.approved { background: #4caf50; }
            .booking-bar.borrowed { background: #2196f3; }
            .booking-bar.returned { background: #9c27b0; }
            .booking-bar.rejected { background: #f44336; }
            
            .booking-bar i {
                margin-right: 4px;
                font-size: 9px;
            }
            .booking-name {
                font-weight: 600;
                font-size: 10px;
            }
            .booking-item {
                font-size: 9px;
                opacity: 0.9;
                margin-left: 4px;
            }
            .booking-duration {
                font-size: 8px;
                opacity: 0.8;
                margin-left: 5px;
            }
            
            @media (max-width: 1024px) {
                .calendar-table {
                    min-width: 700px;
                }
                .calendar-table th {
                    padding: 10px 6px;
                    font-size: 13px;
                }
                .day-cell {
                    min-height: 70px;
                }
                .booking-bar {
                    font-size: 10px;
                    padding: 4px 5px;
                }
                .booking-name, .booking-item {
                    font-size: 9px;
                }
            }
            
            @media (max-width: 768px) {
                .booking-calendar {
                    border-radius: 12px;
                }
                .calendar-table {
                    min-width: 600px;
                }
                .calendar-table th {
                    padding: 8px 4px;
                    font-size: 11px;
                }
                .day-cell {
                    min-height: 60px;
                }
                .day-number {
                    font-size: 10px;
                    top: 3px;
                    right: 4px;
                    padding: 1px 4px;
                }
                .booking-row {
                    height: 24px;
                }
                .booking-bar {
                    font-size: 9px;
                    padding: 3px 5px;
                    height: 20px;
                    top: 2px;
                    line-height: 14px;
                }
                .booking-bar i {
                    font-size: 8px;
                    margin-right: 3px;
                }
                .booking-name, .booking-item {
                    font-size: 8px;
                }
                .booking-duration {
                    font-size: 8px;
                    margin-left: 4px;
                }
                .filter-chip {
                    padding: 5px 12px;
                    font-size: 11px;
                }
                .filter-select {
                    font-size: 11px;
                    padding: 5px 10px;
                }
                .legend-badge {
                    font-size: 10px;
                    padding: 3px 10px;
                }
                .calendar-nav {
                    width: 32px;
                    height: 32px;
                    font-size: 12px;
                }
                .calendar-header h3 {
                    font-size: 16px;
                }
                .section-title button {
                    padding: 5px 12px;
                    font-size: 12px;
                }
            }
            
            @media (max-width: 640px) {
                .calendar-table {
                    min-width: 550px;
                }
                .calendar-table th {
                    font-size: 10px;
                    padding: 6px 3px;
                }
                .day-cell {
                    min-height: 55px;
                }
                .day-number {
                    font-size: 9px;
                    top: 2px;
                    right: 3px;
                }
                .booking-row {
                    height: 22px;
                }
                .booking-bar {
                    font-size: 8px;
                    padding: 2px 4px;
                    height: 18px;
                    top: 2px;
                    line-height: 14px;
                }
                .booking-bar i {
                    font-size: 7px;
                    margin-right: 2px;
                }
                .booking-name, .booking-item {
                    font-size: 7px;
                }
                .booking-duration {
                    font-size: 7px;
                    margin-left: 3px;
                }
                .filter-chip {
                    padding: 4px 10px;
                    font-size: 10px;
                }
                .legend-badge {
                    font-size: 9px;
                    padding: 2px 8px;
                }
                .calendar-summary div {
                    font-size: 11px;
                }
            }
            
            @media (max-width: 480px) {
                .calendar-table {
                    min-width: 500px;
                }
                .calendar-table th {
                    font-size: 9px;
                    padding: 5px 2px;
                }
                .day-cell {
                    min-height: 50px;
                }
                .day-number {
                    font-size: 8px;
                    top: 2px;
                    right: 2px;
                    padding: 1px 3px;
                }
                .booking-row {
                    height: 20px;
                    margin: 1px 0;
                }
                .booking-bar {
                    font-size: 7px;
                    padding: 2px 3px;
                    height: 16px;
                    top: 2px;
                    line-height: 12px;
                }
                .booking-bar i {
                    font-size: 6px;
                    margin-right: 2px;
                }
                .booking-name, .booking-item {
                    font-size: 6px;
                }
                .booking-duration {
                    font-size: 6px;
                    margin-left: 2px;
                }
                .filter-chip {
                    padding: 3px 8px;
                    font-size: 9px;
                }
                .filter-select {
                    font-size: 9px;
                    padding: 3px 6px;
                }
                .legend-badge {
                    font-size: 8px;
                    padding: 2px 6px;
                }
                .calendar-nav {
                    width: 28px;
                    height: 28px;
                    font-size: 10px;
                }
                .calendar-header h3 {
                    font-size: 14px;
                }
                .section-title {
                    font-size: 1rem;
                }
                .section-title button {
                    padding: 4px 10px;
                    font-size: 10px;
                }
                .calendar-summary div {
                    font-size: 10px;
                    gap: 10px;
                }
            }

            /* Prevent booking bars from covering date numbers */
.day-cell {
    position: relative;
    padding-top: 28px;
}
.day-number {
    position: absolute;
    top: 5px;
    right: 8px;
    z-index: 25;
    background: rgba(255,255,255,0.95);
    pointer-events: none;
}
.booking-row {
    position: relative;
    height: 28px;
    margin: 2px 0;
    width: 100%;
    z-index: 1;
}
.booking-bar {
    position: absolute;
    top: 2px;
    left: 0;
    height: 24px;
    border-radius: 4px;
    padding: 4px 6px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.2s;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    color: white;
    font-weight: 500;
    z-index: 20;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    pointer-events: auto;
    line-height: 16px;
    box-sizing: border-box;
}
/* Add top padding to day-cell to make room for date number */
.day-cell {
    padding-top: 28px;
    min-height: 80px;
}
        </style>
        
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-calendar-alt"></i> Booking Calendar
                
            </div>
            <div class="section-sub">Manage equipment, facility, and vehicle booking requests</div>
            
           <!-- Filters -->
<div class="filter-bar">
    <button class="filter-chip ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="loadCalendarWithFilters('all')">All</button>
    <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadCalendarWithFilters('pending')">Pending</button>
    <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadCalendarWithFilters('approved')">Approved</button>
    <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadCalendarWithFilters('borrowed')">Borrowed</button>
    <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadCalendarWithFilters('returned')">Returned</button>
    <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadCalendarWithFilters('rejected')">Rejected</button>
    <select id="calendarTypeFilterSelect" class="filter-select" onchange="loadCalendarWithFilters(currentBookingFilter)">
        <option value="all" ${window.currentCalendarTypeFilter === 'all' ? 'selected' : ''}>All Types</option>
        <option value="equipment" ${window.currentCalendarTypeFilter === 'equipment' ? 'selected' : ''}>Equipment Only</option>
        <option value="facility" ${window.currentCalendarTypeFilter === 'facility' ? 'selected' : ''}>Facility Only</option>
        <option value="vehicle" ${window.currentCalendarTypeFilter === 'vehicle' ? 'selected' : ''}>Vehicle Only</option>
    </select>
    <div style="margin-left: auto; display: flex; gap: 10px;">
        <button class="btn-verify" onclick="toggleBookingView()" style="background: #17a2b8;">
            <i class="fas fa-list"></i> Card View
        </button>
        <button class="btn-verify" onclick="refreshBookingsList()" style="background: #28a745;">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>
</div>
            
            <!-- Legend -->
            <div class="schedule-legend" style="margin: 15px 0; display: flex; gap: 15px; flex-wrap: wrap; justify-content: center;">
                <span class="legend-badge pending"><i class="fas fa-clock"></i> Pending</span>
                <span class="legend-badge approved"><i class="fas fa-check-circle"></i> Approved</span>
                <span class="legend-badge borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>
                <span class="legend-badge returned"><i class="fas fa-undo-alt"></i> Returned</span>
                <span class="legend-badge rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                <span class="legend-badge"><i class="fas fa-arrows-alt-h"></i> Duration bar</span>
            </div>
            
            <!-- Navigation -->
            <div class="calendar-header">
                <button class="calendar-nav" onclick="changeBookingCalendarMonth(${year}, ${month}, -1)"><i class="fas fa-chevron-left"></i></button>
                <h3>${monthNames[month]} ${year}</h3>
                <button class="calendar-nav" onclick="changeBookingCalendarMonth(${year}, ${month}, 1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            
            <div class="booking-calendar">
                <table class="calendar-table">
                    <thead>
                        <tr>
                            <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    // Build the calendar grid
    let currentDay = 1;
    let cellCount = 0;
    let rowCount = 0;
    
    // Empty cells for days before month starts
    for (let i = 0; i < startingDayOfWeek; i++) {
        calendarHtml += `<td style="background:#fafbfa;">` + (cellCount + 1 === 7 ? '</tr><tr>' : '') + `</td>`;
        cellCount++;
    }
    
    // Create rows for the month
    while (currentDay <= daysInMonth) {
        if (cellCount % 7 === 0) {
            if (rowCount > 0) calendarHtml += `</tr>`;
            calendarHtml += `<tr style="vertical-align: top;">`;
            rowCount++;
        }
        
        const dayData = daysInMonthArray[currentDay - 1];
        const todayClass = dayData.isToday ? 'today' : '';
        const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(currentDay).padStart(2, '0')}`;
        
        // Find bookings that cover this day - FLEXIBLE MATCHING
        const bookingsForDay = processedBookings.filter(b => 
            b.startDay <= currentDay && b.endDay >= currentDay
        );
        
        // Build booking bars for this day - FLEXIBLE ADJUSTMENT
        let barsHtml = '';
        
        for (let row = 0; row < totalRows; row++) {
            const bookingAtRow = bookingsForDay.find(b => b.row === row);
            
            if (bookingAtRow && bookingAtRow.startDay === currentDay) {
                const getTypeIcon = (type) => {
                    if (type === 'equipment') return '<i class="fas fa-tools"></i>';
                    if (type === 'facility') return '<i class="fas fa-building"></i>';
                    if (type === 'vehicle') return '<i class="fas fa-truck"></i>';
                    return '<i class="fas fa-question-circle"></i>';
                };
                
                const formatShortDate = (date) => {
                    return `${date.getMonth() + 1}/${date.getDate()}`;
                };
                
                const startDateStr = formatShortDate(bookingAtRow.startDate);
                const endDateStr = formatShortDate(bookingAtRow.endDate);
                const durationText = bookingAtRow.span > 1 ? ` (${startDateStr}-${endDateStr})` : '';
                
                // FLEXIBLE SPAN CALCULATION - Calculate exact percentage width
                let colSpan;
                let widthPercentage;
                
                if (bookingAtRow.startDay === bookingAtRow.endDay) {
                    // Single day booking
                    colSpan = 1;
                    widthPercentage = 100;
                } else {
                    // Multi-day booking - calculate exact number of days it spans
                    const exactSpan = bookingAtRow.endDay - bookingAtRow.startDay + 1;
                    
                    // Check if this is the last day of the booking in this month
                    const isLastDayOfBooking = currentDay === bookingAtRow.endDay || 
                                              (bookingAtRow.endDay > daysInMonth && currentDay === daysInMonth);
                    
                    if (isLastDayOfBooking) {
                        // For the last day, only show the remaining portion if needed
                        widthPercentage = 100;
                        colSpan = 1;
                    } else {
                        // For the start day, calculate how many days to span
                        const remainingDays = Math.min(bookingAtRow.endDay, daysInMonth) - bookingAtRow.startDay;
                        colSpan = remainingDays + 1;
                        widthPercentage = colSpan * 100;
                    }
                }
                
                barsHtml += `
                    <div class="booking-row">
                        <div class="booking-bar ${bookingAtRow.status}" 
                             style="width: ${widthPercentage}%; left: 0;"
                             onclick="event.stopPropagation(); viewBookingDetails(${bookingAtRow.id})">
                            ${getTypeIcon(bookingAtRow.request_type)}
                            <span class="booking-name">${escapeHtml(bookingAtRow.first_name)} ${escapeHtml(bookingAtRow.last_name)}</span>
                            <span class="booking-duration">${durationText}</span>
                        </div>
                    </div>
                `;
            } else if (bookingAtRow) {
          // This is a continuation of a booking that started earlier
// Calculate remaining width for multi-day bookings
const remainingDays = bookingAtRow.endDay - currentDay;
const widthPercentage = (remainingDays + 1) * 100;

// Check if this is the end date to show "expected returned" instead of "continued..."
const isEndDate = currentDay === bookingAtRow.endDay;
const displayText = isEndDate ? "(Expected Returned)" : "continued...";
const textStyle = isEndDate ? 'color: #ffff00; font-weight: bold; text-shadow: 0 0 2px rgba(0,0,0,0.5);' : '';

barsHtml += `
    <div class="booking-row">
        <div class="booking-bar ${bookingAtRow.status}" 
             style="width: ${Math.min(widthPercentage, 100)}%; left: 0; opacity: 0.7;"
             onclick="event.stopPropagation(); viewBookingDetails(${bookingAtRow.id})">
            <span style="font-size: 9px; ${textStyle}">${displayText}</span>
        </div>
    </div>
`;
            } else {
                barsHtml += `<div class="booking-row" style="height: 28px;"></div>`;
            }
        }
        
        calendarHtml += `
            <td>
                <div class="day-cell ${todayClass}" data-date="${dateKey}" onclick="showDayBookingsModalForBooking('${dateKey}', true)">
                    <span class="day-number">${currentDay}</span>
                    ${barsHtml}
                </div>
            </td>
        `;
        
        currentDay++;
        cellCount++;
    }
    
    // Fill remaining cells at the end of the month
    const remainingCells = 7 - (cellCount % 7);
    if (remainingCells < 7) {
        for (let i = 0; i < remainingCells; i++) {
            calendarHtml += `<td style="background:#fafbfa;"></td>`;
        }
    }
    
    calendarHtml += `
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Summary -->
        <div class="calendar-summary" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
            <h4 style="margin-bottom: 10px;"><i class="fas fa-chart-pie"></i> Summary</h4>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div><i class="fas fa-calendar-day"></i> Total Bookings: <strong>${bookings.length}</strong></div>
                <div><i class="fas fa-clock" style="color: #ffc107;"></i> Pending: <strong>${bookings.filter(b => b.status === 'pending').length}</strong></div>
                <div><i class="fas fa-check-circle" style="color: #28a745;"></i> Approved: <strong>${bookings.filter(b => b.status === 'approved').length}</strong></div>
                <div><i class="fas fa-hand-holding" style="color: #17a2b8;"></i> Borrowed: <strong>${bookings.filter(b => b.status === 'borrowed').length}</strong></div>
                <div><i class="fas fa-undo-alt" style="color: #6c757d;"></i> Returned: <strong>${bookings.filter(b => b.status === 'returned').length}</strong></div>
                <div><i class="fas fa-times-circle" style="color: #dc3545;"></i> Rejected: <strong>${bookings.filter(b => b.status === 'rejected').length}</strong></div>
            </div>
        </div>
    </div>
    `;
    
    return calendarHtml;
}
// Replace the existing loadCalendarWithFilters function
async function loadCalendarWithFilters(status) {
    currentBookingFilter = status;
    currentBookingView = 'calendar';
    
    // Also get the type filter from the select element
    const typeFilter = document.getElementById('calendarTypeFilterSelect');
    if (typeFilter) {
        // Store the type filter in a global variable or pass it via URL
        window.currentCalendarTypeFilter = typeFilter.value;
    }
    
    await loadBookingsCalendar();
}

async function changeBookingCalendarMonth(year, month, delta) {
    const newMonth = month + delta;
    let newYear = year;
    let newMonthIndex = newMonth;
    
    if (newMonth < 0) {
        newYear = year - 1;
        newMonthIndex = 11;
    } else if (newMonth > 11) {
        newYear = year + 1;
        newMonthIndex = 0;
    }
    
    currentBookingCalendarYear = newYear;
    currentBookingCalendarMonth = newMonthIndex;
    await loadBookingsCalendar();
}

async function changeBookingCalendarMonth(year, month, delta) {
    const newMonth = month + delta;
    let newYear = year;
    let newMonthIndex = newMonth;
    
    if (newMonth < 0) {
        newYear = year - 1;
        newMonthIndex = 11;
    } else if (newMonth > 11) {
        newYear = year + 1;
        newMonthIndex = 0;
    }
    
    currentBookingCalendarYear = newYear;
    currentBookingCalendarMonth = newMonthIndex;
    await loadBookingsCalendar();
}

async function showDayBookingsModalForBooking(dateKey, hasBookings) {
    if (!hasBookings) return;
    
    const selectedDate = new Date(dateKey);
    const formattedDate = selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    
    // Fetch bookings for this specific date with current filters
    const typeFilter = document.getElementById('calendarTypeFilterSelect') ? 
        document.getElementById('calendarTypeFilterSelect').value : 'all';
    const response = await fetch(`equipment_ajax.php?action=get_all_bookings&status=${currentBookingFilter}&type=${typeFilter}&date=${dateKey}`);
    const data = await response.json();
    
    if (data.success && data.data.bookings) {
        const dayBookings = data.data.bookings.filter(booking => {
            const bookingDate = new Date(booking.start_datetime || booking.booking_date);
            const bookingDateKey = `${bookingDate.getFullYear()}-${String(bookingDate.getMonth() + 1).padStart(2, '0')}-${String(bookingDate.getDate()).padStart(2, '0')}`;
            return bookingDateKey === dateKey;
        });
        
        showDayBookingsListModal(formattedDate, dayBookings);
    }
}

function showDayBookingsListModal(date, bookings) {
    const modal = document.getElementById('dayBookingsModal');
    const modalTitle = document.getElementById('dayBookingsModalTitle');
    const modalBody = document.getElementById('dayBookingsModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = `<i class="fas fa-calendar-day"></i> Bookings for ${date}`;
    
    const formatDateTime = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    };
    
    const formatFullDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };
    
    const getStatusClass = (status) => {
        switch(status) {
            case 'pending': return 'badge-unverified';
            case 'approved': return 'badge-verified';
            case 'borrowed': return 'badge-borrowed';
            case 'returned': return 'badge-returned';
            case 'rejected': return 'status-unverified';
            default: return 'badge-unverified';
        }
    };
    
    const getTypeIcon = (type) => {
        switch(type) {
            case 'equipment': return '<i class="fas fa-tools"></i>';
            case 'facility': return '<i class="fas fa-building"></i>';
            case 'vehicle': return '<i class="fas fa-truck"></i>';
            default: return '<i class="fas fa-question-circle"></i>';
        }
    };
    
    const getStatusIcon = (status) => {
        switch(status) {
            case 'pending': return '<i class="fas fa-clock"></i>';
            case 'approved': return '<i class="fas fa-check-circle"></i>';
            case 'borrowed': return '<i class="fas fa-hand-holding"></i>';
            case 'returned': return '<i class="fas fa-undo-alt"></i>';
            case 'rejected': return '<i class="fas fa-times-circle"></i>';
            default: return '<i class="fas fa-question-circle"></i>';
        }
    };
    
    modalBody.innerHTML = `
        <div class="day-bookings-list" style="max-height: 60vh; overflow-y: auto;">
            ${bookings.map(booking => {
                const startDate = new Date(booking.start_datetime || booking.booking_date);
                const endDate = new Date(booking.end_datetime || booking.return_date);
                const durationDays = booking.duration_days || 1;
                const dateKey = new Date(date);
                const isStartDate = startDate.toDateString() === dateKey.toDateString();
                const isEndDate = endDate.toDateString() === dateKey.toDateString();
                
                let positionLabel = '';
                if (isStartDate && isEndDate && durationDays === 1) {
                    positionLabel = '<span class="duration-badge" style="background:#28a745;">Single Day</span>';
                } else if (isStartDate) {
                    positionLabel = `<span class="duration-badge" style="background:#ff9800;">Start (${durationDays} days)</span>`;
                } else if (isEndDate) {
                    positionLabel = `<span class="duration-badge" style="background:#2196f3;">End Date</span>`;
                } else {
                    positionLabel = `<span class="duration-badge" style="background:#6c757d;">During Booking</span>`;
                }
                
                return `
                    <div class="day-booking-item" onclick="viewBookingDetails(${booking.id}); closeDayBookingsModal();" 
                         style="background: #f8faf8; border-radius: 12px; padding: 12px; margin-bottom: 10px;  cursor: pointer;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-size: 18px; margin-right: 8px;">${getTypeIcon(booking.request_type)}</span>
                                <strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong>
                                ${positionLabel}
                            </div>
                            <div class="${getStatusClass(booking.status)}" style="padding: 4px 12px; border-radius: 40px; font-size: 11px;">
                                ${getStatusIcon(booking.status)} ${booking.status}
                            </div>
                        </div>
                        <div style="margin-top: 8px; font-size: 12px; color: #666;">
                            <div><i class="fas fa-calendar"></i> ${formatFullDate(startDate)} → ${formatFullDate(endDate)}</div>
                            <div><i class="fas fa-hourglass-half"></i> Duration: ${durationDays} day${durationDays > 1 ? 's' : ''}</div>
                            <div><i class="fas fa-tag"></i> ${escapeHtml(booking.equipment_name)}</div>
                            ${booking.purpose ? `<div><i class="fas fa-comment"></i> ${escapeHtml(booking.purpose.substring(0, 80))}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
    
    modal.style.display = 'block';
}

// Update the loadFilteredBookings function to handle calendar view
async function loadFilteredBookings(status, page) {
    const typeFilter = document.getElementById('typeFilterSelect') ? document.getElementById('typeFilterSelect').value : 'all';
    currentBookingFilter = status;
    currentBookingPage = page;
    
    if (currentBookingView === 'calendar') {
        await loadBookingsCalendar();
        return;
    }
    
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bookings...</p></div></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_bookings&status=${status}&page=${page}&type=${typeFilter}`);
        const data = await response.json();
        if (data.success) {
            dashboardBody.innerHTML = renderBookingsList(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}





function renderBookingsList(bookingsData) {
    const bookings = bookingsData.bookings;
    const totalPages = bookingsData.totalPages;
    const currentPage = bookingsData.currentPage;
    
    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    };
    
    const getStatusBadge = (status) => {
        switch(status) {
            case 'pending': return '<span class="badge-unverified"><i class="fas fa-clock"></i> Pending</span>';
            case 'approved': return '<span class="badge-verified"><i class="fas fa-check-circle"></i> Approved</span>';
            case 'borrowed': return '<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>';
            case 'returned': return '<span class="badge-returned"><i class="fas fa-undo-alt"></i> Returned</span>';
            case 'rejected': return '<span class="status-unverified"><i class="fas fa-times-circle"></i> Rejected</span>';
            default: return '<span class="badge-unverified">' + status + '</span>';
        }
    };
    
    const getTypeBadge = (type) => {
        switch(type) {
            case 'equipment': return '<span class="type-badge equipment"><i class="fas fa-tools"></i> Equipment</span>';
            case 'facility': return '<span class="type-badge facility"><i class="fas fa-building"></i> Facility</span>';
            case 'vehicle': return '<span class="type-badge vehicle"><i class="fas fa-truck"></i> Vehicle</span>';
            default: return '<span class="type-badge">' + type + '</span>';
        }
    };
    
    if (!bookings || bookings.length === 0) {
        return `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
                <div class="section-sub">Manage equipment, facility, and vehicle booking requests from residents</div>
                <div class="filter-bar">
                    <button class="filter-chip ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="loadFilteredBookings('all', 1)">All</button>
                    <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadFilteredBookings('pending', 1)">Pending</button>
                    <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadFilteredBookings('approved', 1)">Approved</button>
                    <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadFilteredBookings('borrowed', 1)">Borrowed</button>
                    <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadFilteredBookings('returned', 1)">Returned</button>
                    <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadFilteredBookings('rejected', 1)">Rejected</button>
                    <select id="typeFilterSelect" class="filter-select" onchange="loadFilteredBookings(currentBookingFilter, 1)">
                        <option value="all">All Types</option>
                        <option value="equipment">Equipment Only</option>
                        <option value="facility">Facility Only</option>
                        <option value="vehicle">Vehicle Only</option>
                    </select>
                    <button class="btn-verify" onclick="toggleBookingView()" style="background: #17a2b8; margin-left: auto;">
                        <i class="fas fa-calendar-alt"></i> Calendar View
                    </button>
                    <button class="btn-verify" onclick="refreshBookingsList()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-3x"></i>
                    <p>No ${currentBookingFilter} booking requests found.</p>
                </div>
            </div>
        `;
    }
    
    const cardsHtml = bookings.map(booking => {
        let customData = {};
        if (booking.custom_data) {
            try {
                customData = JSON.parse(booking.custom_data);
            } catch(e) {}
        }
        
        let displayDate = formatDate(booking.start_datetime || booking.booking_date);
        if (booking.request_type === 'facility' && customData.start_time) {
            displayDate = `${formatDate(booking.start_datetime)} (${customData.start_time} - ${customData.end_time})`;
        }
        if (booking.request_type === 'vehicle' && customData.trip_date) {
            displayDate = formatDate(customData.trip_date);
        }
        
        let typeInfo = '';
        if (booking.request_type === 'equipment') {
            typeInfo = `<div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-cubes"></i></div>
                            <div class="info-label-card">Quantity:</div>
                            <div class="info-value-card">${customData.quantity || booking.quantity || 1} item(s)</div>
                        </div>`;
        } else if (booking.request_type === 'facility') {
            typeInfo = `<div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-clock"></i></div>
                            <div class="info-label-card">Time:</div>
                            <div class="info-value-card">${customData.start_time || '--:--'} - ${customData.end_time || '--:--'}</div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-users"></i></div>
                            <div class="info-label-card">Attendees:</div>
                            <div class="info-value-card">${customData.expected_attendees || '-'}</div>
                        </div>`;
        } else if (booking.request_type === 'vehicle') {
            typeInfo = `<div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div class="info-label-card">Pickup:</div>
                            <div class="info-value-card">${escapeHtml((customData.pickup_location || '-').substring(0, 50))}</div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-users"></i></div>
                            <div class="info-label-card">Passengers:</div>
                            <div class="info-value-card">${customData.passenger_count || 1}</div>
                        </div>`;
        }
        
        return `
            <div class="data-card" onclick="viewBookingDetails(${booking.id})" data-id="${booking.id}">
                <div class="card-header-gradient ${booking.status === 'pending' ? 'pending' : (booking.status === 'approved' ? 'approved' : 'completed')}">
                    <div class="card-title-large">
                        ${getTypeBadge(booking.request_type)}
                        <span>${escapeHtml(booking.equipment_name)}</span>
                    </div>
                    <div class="status-chip">${getStatusBadge(booking.status)}</div>
                </div>
                <div class="card-content">
                    <div class="info-section">
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-user"></i></div>
                            <div class="info-label-card">Resident:</div>
                            <div class="info-value-card"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                            <div class="info-label-card">Phone:</div>
                            <div class="info-value-card">${escapeHtml(booking.phone || '-')}</div>
                        </div>
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-calendar"></i></div>
                            <div class="info-label-card">${booking.request_type === 'vehicle' ? 'Trip Date:' : 'Booking Date:'}</div>
                            <div class="info-value-card">${displayDate}</div>
                        </div>
                        ${typeInfo}
                        <div class="info-row-card">
                            <div class="info-icon"><i class="fas fa-hourglass-half"></i></div>
                            <div class="info-label-card">Duration:</div>
                            <div class="info-value-card">${booking.duration_days || 1} day(s)</div>
                        </div>
                        ${booking.purpose ? `
                            <div class="info-row-card">
                                <div class="info-icon"><i class="fas fa-comment"></i></div>
                                <div class="info-label-card">Purpose:</div>
                                <div class="info-value-card">${escapeHtml(booking.purpose.substring(0, 100))}${booking.purpose.length > 100 ? '...' : ''}</div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="click-hint">
                    <i class="fas fa-mouse-pointer"></i> Click to view details and take action
                </div>
            </div>
        `;
    }).join('');
    
    let paginationHtml = '';
    if (totalPages > 1) {
        paginationHtml = '<div class="pagination-wrapper"><div class="pagination">';
        if (currentPage > 1) paginationHtml += `<button class="page-btn" onclick="loadFilteredBookings('${currentBookingFilter}', ${currentPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
        for (let i = 1; i <= totalPages; i++) {
            paginationHtml += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="loadFilteredBookings('${currentBookingFilter}', ${i})">${i}</button>`;
        }
        if (currentPage < totalPages) paginationHtml += `<button class="page-btn" onclick="loadFilteredBookings('${currentBookingFilter}', ${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
        paginationHtml += '</div></div>';
    }
    
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
            <div class="section-sub">Manage equipment, facility, and vehicle booking requests from residents</div>
            <div class="filter-bar">
                <button class="filter-chip ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="loadFilteredBookings('all', 1)">All</button>
                <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadFilteredBookings('pending', 1)">Pending</button>
                <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadFilteredBookings('approved', 1)">Approved</button>
                <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadFilteredBookings('borrowed', 1)">Borrowed</button>
                <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadFilteredBookings('returned', 1)">Returned</button>
                <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadFilteredBookings('rejected', 1)">Rejected</button>
                <select id="typeFilterSelect" class="filter-select" onchange="loadFilteredBookings(currentBookingFilter, 1)">
                    <option value="all">All Types</option>
                    <option value="equipment">Equipment Only</option>
                    <option value="facility">Facility Only</option>
                    <option value="vehicle">Vehicle Only</option>
                </select>
                <button class="btn-verify" onclick="toggleBookingView()" style="background: #17a2b8; margin-left: auto;">
                    <i class="fas fa-calendar-alt"></i> Calendar View
                </button>
                <button class="btn-verify" onclick="refreshBookingsList()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            <div class="cards-grid">
                ${cardsHtml}
            </div>
            ${paginationHtml}
        </div>
    `;
}

// Add refresh function
function refreshBookingsList() {
    loadFilteredBookings(currentBookingFilter, currentBookingPage);
}

// Add new function to load filtered bookings by type
async function loadFilteredBookings(status, page) {
    const typeFilter = document.getElementById('typeFilterSelect') ? document.getElementById('typeFilterSelect').value : 'all';
    currentBookingFilter = status;
    currentBookingPage = page;
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bookings...</p></div></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_bookings&status=${status}&page=${page}&type=${typeFilter}`);
        const data = await response.json();
        if (data.success) {
            dashboardBody.innerHTML = renderBookingsList(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}

async function viewBookingDetails(id) {
    const modal = document.getElementById('bookingModal');
    const modalBody = document.getElementById('bookingModalBody');
    if (!modal || !modalBody) return;
    
    modal.style.display = 'block';
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading booking details...</p></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_booking_by_id&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const booking = data.data;
            const formatDate = (dateString) => {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            };
            
            // Parse custom data if exists
            let customData = {};
            if (booking.custom_data) {
                try {
                    customData = JSON.parse(booking.custom_data);
                } catch(e) {}
            }
            
            // Generate type-specific details based on request_type
            let typeSpecificHtml = '';
            
            if (booking.request_type === 'equipment') {
                typeSpecificHtml = `
                    <div class="detail-section">
                        <h4><i class="fas fa-cubes"></i> Equipment Details</h4>
                        <div class="detail-row">
                            <div class="detail-label">Quantity:</div>
                            <div class="detail-value">${booking.quantity || 1} item(s)</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Expected Return:</div>
                            <div class="detail-value">${formatDate(booking.end_datetime)}</div>
                        </div>
                    </div>
                `;
            } else if (booking.request_type === 'facility') {
                typeSpecificHtml = `
                    <div class="detail-section">
                        <h4><i class="fas fa-building"></i> Facility Details</h4>
                        <div class="detail-row">
                            <div class="detail-label">Start Time:</div>
                            <div class="detail-value">${customData.start_time || formatDate(booking.start_datetime)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">End Time:</div>
                            <div class="detail-value">${customData.end_time || formatDate(booking.end_datetime)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Duration (Hours):</div>
                            <div class="detail-value">${customData.duration_hours || '-'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Event Type:</div>
                            <div class="detail-value">${escapeHtml(customData.event_type || '-')}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Expected Attendees:</div>
                            <div class="detail-value">${customData.expected_attendees || '-'}</div>
                        </div>
                    </div>
                `;
            } else if (booking.request_type === 'vehicle') {
                typeSpecificHtml = `
                    <div class="detail-section">
                        <h4><i class="fas fa-truck"></i> Vehicle Details</h4>
                        <div class="detail-row">
                            <div class="detail-label">Vehicle Capacity:</div>
                            <div class="detail-value">${customData.vehicle_capacity || '-'} persons</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Trip Date:</div>
                            <div class="detail-value">${formatDate(booking.start_datetime)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Pickup Time:</div>
                            <div class="detail-value">${customData.pickup_time || '--:--'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Pickup Location:</div>
                            <div class="detail-value">${escapeHtml(customData.pickup_location || '-')}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Dropoff Location:</div>
                            <div class="detail-value">${escapeHtml(customData.dropoff_location || '-')}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Passenger Count:</div>
                            <div class="detail-value">${customData.passenger_count || 1}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Estimated Hours:</div>
                            <div class="detail-value">${customData.estimated_hours || '-'}</div>
                        </div>
                    </div>
                `;
            }
            
            const getStatusActions = () => {
                if (booking.status === 'pending') {
                    return `
                        <div class="modal-action-buttons">
                            <button class="btn-verify" onclick="showBookingActionModal(${booking.id}, 'approved')"><i class="fas fa-check-circle"></i> Approve</button>
                            <button class="btn-close" onclick="showBookingActionModal(${booking.id}, 'rejected')" style="background:#dc3545;"><i class="fas fa-times-circle"></i> Reject</button>
                        </div>
                    `;
                } else if (booking.status === 'approved') {
                    return `
                        <div class="modal-action-buttons">
                            <button class="btn-verify" onclick="showBookingActionModal(${booking.id}, 'borrowed')" style="background:#17a2b8;"><i class="fas fa-hand-holding"></i> Mark as Borrowed</button>
                        </div>
                    `;
                } else if (booking.status === 'borrowed') {
                    return `
                        <div class="modal-action-buttons">
                            <button class="btn-verify" onclick="showReturnModal(${booking.id})" style="background:#28a745;"><i class="fas fa-undo-alt"></i> Mark as Returned</button>
                        </div>
                    `;
                }
                return '';
            };
            
            const getTypeIcon = () => {
                switch(booking.request_type) {
                    case 'equipment': return '<i class="fas fa-tools"></i>';
                    case 'facility': return '<i class="fas fa-building"></i>';
                    case 'vehicle': return '<i class="fas fa-truck"></i>';
                    default: return '<i class="fas fa-question-circle"></i>';
                }
            };
            
            modalBody.innerHTML = `
                <div class="detail-section">
                    <h4><i class="fas fa-user-circle"></i> Resident Information</h4>
                    <div class="detail-row">
                        <div class="detail-label">Full Name:</div>
                        <div class="detail-value"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value">${escapeHtml(booking.email)}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone:</div>
                        <div class="detail-value">${escapeHtml(booking.phone || '-')}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value">${escapeHtml(booking.address || '-')}</div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4>${getTypeIcon()} Booking Information</h4>
                    <div class="detail-row">
                        <div class="detail-label">Request Type:</div>
                        <div class="detail-value"><strong>${escapeHtml(booking.request_type).toUpperCase()}</strong></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Item/Facility Name:</div>
                        <div class="detail-value"><strong>${escapeHtml(booking.equipment_name)}</strong></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Global ID:</div>
                        <div class="detail-value">${escapeHtml(booking.item_global_id || '-')}</div>
                    </div>
                </div>
                
                ${typeSpecificHtml}
                
                <div class="detail-section">
                    <h4><i class="fas fa-info-circle"></i> Request Information</h4>
                    <div class="detail-row">
                        <div class="detail-label">Purpose:</div>
                        <div class="detail-value">${escapeHtml(booking.purpose || '-')}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Request Date:</div>
                        <div class="detail-value">${formatDate(booking.request_date)}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Start Date/Time:</div>
                        <div class="detail-value">${formatDate(booking.start_datetime)}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">End Date/Time:</div>
                        <div class="detail-value">${formatDate(booking.end_datetime)}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">${getStatusBadgeHtml(booking.status)}</div>
                    </div>
                    ${booking.admin_notes ? `<div class="detail-row"><div class="detail-label">Admin Notes:</div><div class="detail-value"><strong>${escapeHtml(booking.admin_notes)}</strong></div></div>` : ''}
                </div>
                ${getStatusActions()}
            `;
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>${escapeHtml(data.message)}</p></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading details</p></div>`;
    }
}

function getStatusBadgeHtml(status) {
    switch(status) {
        case 'pending': return '<span class="badge-unverified"><i class="fas fa-clock"></i> Pending</span>';
        case 'approved': return '<span class="badge-verified"><i class="fas fa-check-circle"></i> Approved</span>';
        case 'borrowed': return '<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>';
        case 'returned': return '<span class="badge-returned"><i class="fas fa-undo-alt"></i> Returned</span>';
        case 'rejected': return '<span class="status-unverified"><i class="fas fa-times-circle"></i> Rejected</span>';
        default: return '<span class="badge-unverified">' + status + '</span>';
    }
}

function showBookingActionModal(id, action) {
    pendingBookingActionId = id;
    pendingBookingAction = action;
    const modal = document.getElementById('bookingApprovalModal');
    const title = document.getElementById('bookingActionTitle');
    
    if (!modal || !title) return;
    
    if (action === 'approved') title.innerHTML = 'Approve Booking';
    else if (action === 'rejected') title.innerHTML = 'Reject Booking';
    else if (action === 'borrowed') title.innerHTML = 'Mark as Borrowed';
    
    const notesField = document.getElementById('bookingApprovalNotes');
    const statusDiv = document.getElementById('bookingApprovalStatus');
    if (notesField) notesField.value = '';
    if (statusDiv) statusDiv.style.display = 'none';
    modal.style.display = 'block';
}

async function submitBookingAction() {
    const notes = document.getElementById('bookingApprovalNotes') ? document.getElementById('bookingApprovalNotes').value : '';
    const statusDiv = document.getElementById('bookingApprovalStatus');
    const confirmBtn = document.getElementById('confirmBookingBtn');
    
    if (!confirmBtn) return;
    
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.className = 'email-status';
        statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    const formData = new FormData();
    formData.append('action', 'update_booking_status');
    formData.append('id', pendingBookingActionId);
    formData.append('status', pendingBookingAction);
    formData.append('admin_notes', notes);
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            if (statusDiv) {
                statusDiv.className = 'email-status success';
                statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            }
            setTimeout(() => {
                closeBookingApprovalModal();
                if (typeof showSuccessModal === 'function') showSuccessModal(data.message, 'Success');
                else alert(data.message);
                
                loadBookings(currentBookingFilter, currentBookingPage);
                closeBookingModal();
            }, 1500);
        } else {
            if (statusDiv) {
                statusDiv.className = 'email-status error';
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to process');
            }
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm';
        }
    } catch (error) {
        console.error('Error:', error);
        if (statusDiv) {
            statusDiv.className = 'email-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred';
        }
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm';
    }
}

function showReturnModal(id) {
    pendingBookingActionId = id;
    const modal = document.getElementById('returnModal');
    const notesField = document.getElementById('returnNotes');
    if (modal) {
        modal.style.display = 'block';
        if (notesField) notesField.value = '';
    }
}

async function confirmReturn() {
    const notes = document.getElementById('returnNotes') ? document.getElementById('returnNotes').value : '';
    const modal = document.getElementById('returnModal');
    
    const formData = new FormData();
    formData.append('action', 'update_booking_status');
    formData.append('id', pendingBookingActionId);
    formData.append('status', 'returned');
    formData.append('admin_notes', notes);
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            if (modal) modal.style.display = 'none';
            if (typeof showSuccessModal === 'function') showSuccessModal('Item marked as returned!', 'Success');
            else alert('Item marked as returned!');
            
            loadBookings(currentBookingFilter, currentBookingPage);
            closeBookingModal();
        } else {
            if (typeof showErrorModal === 'function') showErrorModal(data.message || 'Failed to process return', 'Error');
            else alert(data.message || 'Failed to process return');
        }
    } catch (error) {
        console.error('Error:', error);
        if (typeof showErrorModal === 'function') showErrorModal('An error occurred', 'Error');
        else alert('An error occurred');
    }
}

// ============ EQUIPMENT DASHBOARD FUNCTIONS ============

async function loadEquipmentDashboard() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading dashboard...</p></div></div>`;
    
    try {
        const response = await fetch('equipment_ajax.php?action=get_counts');
        const data = await response.json();
        if (data.success) {
            dashboardBody.innerHTML = renderEquipmentDashboard(data.counts, data.pending_bookings);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load dashboard</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading dashboard</p></div></div>`;
    }
}

function renderEquipmentDashboard(counts, pendingBookings) {
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-chart-pie"></i> Equipment & Facilities Dashboard</div>
            <div class="section-sub">Overview of barangay resources and bookings</div>
            
            <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px;">
                <div class="stat-card" style="text-align:center;">
                    <i class="fas fa-tools fa-2x" style="color:#1a472a;"></i>
                    <h3>${counts.total}</h3>
                    <p>Total Items</p>
                </div>
                <div class="stat-card" style="text-align:center;">
                    <i class="fas fa-check-circle fa-2x" style="color:#43e97b;"></i>
                    <h3>${counts.available}</h3>
                    <p>Available Items</p>
                </div>
                <div class="stat-card" style="text-align:center;">
                    <i class="fas fa-wrench fa-2x" style="color:#f4b942;"></i>
                    <h3>${counts.maintenance}</h3>
                    <p>Under Maintenance</p>
                </div>
                <div class="stat-card" style="text-align:center;">
                    <i class="fas fa-clock fa-2x" style="color:#17a2b8;"></i>
                    <h3>${pendingBookings}</h3>
                    <p>Pending Bookings</p>
                </div>
            </div>
            
            <div style="margin-top:20px;">
                <h4 style="color:#1a472a;margin-bottom:15px;">Categories</h4>
                <div style="display:flex;gap:15px;flex-wrap:wrap;">
                    ${counts.categories && counts.categories.length > 0 ? counts.categories.map(cat => `
                        <div style="background:#e9f9ef;padding:10px 20px;border-radius:20px;">
                            <strong>${escapeHtml(cat.category)}</strong>: ${cat.count} items
                        </div>
                    `).join('') : '<p>No categories found</p>'}
                </div>
            </div>
            
            <div class="action-buttons-group" style="margin-top:30px;display:flex;gap:15px;justify-content:center;flex-wrap:wrap;">
                <button class="btn-verify" onclick="loadEquipmentList()"><i class="fas fa-list"></i> View Equipment</button>
                <button class="btn-verify" onclick="loadBookings('pending',1)" style="background:#17a2b8;"><i class="fas fa-calendar-alt"></i> View Bookings</button>
                <button class="btn-verify" onclick="showAddEquipmentModal()"><i class="fas fa-plus"></i> Add Equipment</button>
            </div>
        </div>
    `;
}

// ============ MODAL CLOSE FUNCTIONS ============

function closeEquipmentModal() { 
    const modal = document.getElementById('equipmentModal');
    if (modal) modal.style.display = 'none'; 
}

function closeBookingModal() { 
    const modal = document.getElementById('bookingModal');
    if (modal) modal.style.display = 'none'; 
}

function closeBookingApprovalModal() { 
    const modal = document.getElementById('bookingApprovalModal');
    if (modal) modal.style.display = 'none'; 
}

function closeReturnModal() { 
    const modal = document.getElementById('returnModal');
    if (modal) modal.style.display = 'none'; 
}

function closeEquipmentItemsModal() {
    const modal = document.getElementById('equipmentItemsModal');
    if (modal) modal.style.display = 'none';
    currentEquipmentTypeId = null;
    currentEquipmentTypeName = '';
}

// ============ HELPER FUNCTIONS ============

function showConfirmModal(message, title) {
    return new Promise((resolve) => {
        if (typeof showConfirmationModal === 'function') {
            showConfirmationModal(message, title, () => resolve(true), () => resolve(false));
        } else {
            resolve(confirm(title + '\n' + message));
        }
    });
}


// ============ MODAL CONFIRMATION FUNCTIONS ============

// Show confirmation modal with custom styling
function showConfirmationModal(message, title, onConfirm, onCancel, type = 'warning') {
    // Remove any existing confirmation modals
    const existingModal = document.querySelector('.modal-confirm');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal element
    const modal = document.createElement('div');
    modal.className = 'modal-confirm';
    
    // Set icon based on type
    let icon = '<i class="fas fa-exclamation-triangle"></i>';
    let confirmBtnClass = 'btn-confirm';
    
    switch(type) {
        case 'danger':
            icon = '<i class="fas fa-exclamation-circle"></i>';
            confirmBtnClass = 'btn-confirm';
            break;
        case 'warning':
            icon = '<i class="fas fa-exclamation-triangle"></i>';
            confirmBtnClass = 'btn-confirm warning';
            break;
        case 'success':
            icon = '<i class="fas fa-check-circle"></i>';
            confirmBtnClass = 'btn-confirm success';
            break;
        case 'info':
            icon = '<i class="fas fa-info-circle"></i>';
            confirmBtnClass = 'btn-confirm info';
            break;
        default:
            icon = '<i class="fas fa-question-circle"></i>';
            confirmBtnClass = 'btn-confirm warning';
    }
    
    modal.innerHTML = `
        <div class="modal-confirm-content">
            <div class="modal-confirm-header ${type}">
                ${icon}
                <h3>${escapeHtml(title)}</h3>
            </div>
            <div class="modal-confirm-body">
                <p>${escapeHtml(message)}</p>
            </div>
            <div class="modal-confirm-footer">
                <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                <button class="${confirmBtnClass}" onclick="confirmAction()">Confirm</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Store callbacks
    window._confirmCallback = onConfirm || null;
    window._cancelCallback = onCancel || null;
    
    // Close on escape key
    const handleEsc = (e) => {
        if (e.key === 'Escape') {
            closeConfirmationModal();
            document.removeEventListener('keydown', handleEsc);
        }
    };
    document.addEventListener('keydown', handleEsc);
    
    // Close when clicking outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeConfirmationModal();
        }
    });
}

// Close confirmation modal
function closeConfirmationModal() {
    const modal = document.querySelector('.modal-confirm');
    if (modal) {
        modal.remove();
    }
    if (window._cancelCallback) {
        window._cancelCallback();
        window._cancelCallback = null;
    }
    window._confirmCallback = null;
}

// Confirm action
function confirmAction() {
    if (window._confirmCallback) {
        window._confirmCallback();
        window._confirmCallback = null;
    }
    closeConfirmationModal();
}

// Simplified confirmation function for undo/deduct/etc
async function showConfirmModal(message, title) {
    return new Promise((resolve) => {
        showConfirmationModal(message, title, 
            () => resolve(true),
            () => resolve(false)
        );
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}












// ============ SIDEBAR EVENT LISTENERS ============

function initEquipmentSidebar() {
    const equipmentParent = document.getElementById('equipmentParent');
    const equipmentSubmenu = document.getElementById('equipmentSubmenu');
    let equipmentExpanded = false;

    function toggleEquipmentSubmenu(expand) {
        if (expand === undefined) equipmentExpanded = !equipmentExpanded;
        else equipmentExpanded = expand;
        if (equipmentExpanded) {
            if (equipmentSubmenu) equipmentSubmenu.classList.add('open');
            if (equipmentParent) {
                const icon = equipmentParent.querySelector('.toggle-icon');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        } else {
            if (equipmentSubmenu) equipmentSubmenu.classList.remove('open');
            if (equipmentParent) {
                const icon = equipmentParent.querySelector('.toggle-icon');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }
    }
    
    if (equipmentParent) {
        toggleEquipmentSubmenu(false);
        equipmentParent.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            toggleEquipmentSubmenu(); 
        });
    }

    const equipmentSubOptions = document.querySelectorAll('#equipmentSubmenu .sub-option');
    equipmentSubOptions.forEach(opt => {
        opt.addEventListener('click', async (e) => {
            e.preventDefault();
            const view = opt.getAttribute('data-subview');
            equipmentSubOptions.forEach(sub => sub.classList.remove('active-sub'));
            opt.classList.add('active-sub');
            
            const allNavItems = document.querySelectorAll('.nav-item');
            allNavItems.forEach(item => item.classList.remove('active-parent', 'active'));
            if (equipmentParent) equipmentParent.classList.add('active-parent');
            
            if (view === 'equipment_list') {
                await loadEquipmentList();
            } else if (view === 'equipment_bookings') {
                await loadBookings('pending', 1);
            } else if (view === 'equipment_dashboard') {
                await loadEquipmentDashboard();
            }
            
            if (!equipmentExpanded) toggleEquipmentSubmenu(true);
            
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileDropdown) profileDropdown.classList.remove('show');
        });
    });
}








// ============ INITIALIZATION ============

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquipmentSidebar);
} else {
    initEquipmentSidebar();
}