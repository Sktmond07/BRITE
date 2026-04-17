// equipment.js - Complete Equipment Management System with Auto-Refresh

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

// Store current equipment type info for refreshing
let currentEquipmentTypeId = null;
let currentEquipmentTypeName = '';

// Auto-refresh flag
let autoRefreshEnabled = true;

// ============ AUTO-REFRESH FUNCTIONS ============

// Refresh the current view based on active tab
async function refreshCurrentView() {
    // Get the active subview from sidebar
    const activeSubOption = document.querySelector('#equipmentSubmenu .sub-option.active-sub');
    if (!activeSubOption) {
        // If no active subview, check if we're in equipment items modal
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

// Refresh equipment items list (when modal is open)
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

// Trigger refresh after any change
function triggerRefresh() {
    if (!autoRefreshEnabled) return;
    
    setTimeout(async () => {
        await refreshCurrentView();
        
        // Also refresh items modal if open
        const itemsModal = document.getElementById('equipmentItemsModal');
        if (itemsModal && itemsModal.style.display === 'block') {
            await refreshEquipmentItemsList();
        }
    }, 500);
}

// ============ INDIVIDUAL ITEM MANAGEMENT FUNCTIONS ============

// Show item management modal
// Show item management modal - ONLY Available, Maintenance, Lost
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

// Update individual item status
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
                
                // Auto-refresh the display
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

// Close manage item modal
function closeManageItemModal() {
    const modal = document.getElementById('manageItemModal');
    if (modal) modal.style.display = 'none';
}

// ============ EQUIPMENT FORM HANDLING ============

// Show category selection cards first
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
                <button class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

// Show form based on selected category
function showCategoryForm(category) {
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    let categoryIcon = '';
    let predefinedItems = [];
    
    if (category === 'Equipment') {
        categoryIcon = '<i class="fas fa-tools"></i>';
        predefinedItems = ['Tent', 'Table'];
    } else if (category === 'Facility') {
        categoryIcon = '<i class="fas fa-building"></i>';
        predefinedItems = ['Covered Court'];
    } else if (category === 'Vehicle') {
        categoryIcon = '<i class="fas fa-truck"></i>';
        predefinedItems = ['Truck', 'Van', 'Barangay Patrol'];
    }
    
    // Build options HTML
    let optionsHtml = `<option value="">-- Select Item --</option>`;
    predefinedItems.forEach(item => {
        optionsHtml += `<option value="${item}">${item}</option>`;
    });
    optionsHtml += `<option value="__other__">-- Other (Type custom name) --</option>`;
    
    // Check if quantity should be shown (only for Equipment)
    const showQuantity = (category === 'Equipment');
    const quantityHtml = showQuantity ? `
        <div class="form-group" id="quantityGroup">
            <label>Quantity <span style="color:red;">*</span></label>
            <input type="number" id="eqQuantity" class="form-control" required min="1" value="1" max="100">
            <small class="form-text text-muted">If quantity > 1, each item will be created individually (e.g., Tent 1, Tent 2, etc.)</small>
        </div>
    ` : '<input type="hidden" id="eqQuantity" value="1">';
    
    modalTitle.innerHTML = `${categoryIcon} Add ${category}`;
    modalBody.innerHTML = `
        <form id="equipmentForm" enctype="multipart/form-data" onsubmit="saveEquipment(event, '${category}')">
            <div class="form-group">
                <label>Item Type <span style="color:red;">*</span></label>
                <select id="eqItemType" class="form-control" required onchange="onItemTypeChange()">
                    ${optionsHtml}
                </select>
            </div>
            
            <div id="customNameGroup" style="display:none;">
                <div class="form-group">
                    <label>Custom Item Name <span style="color:red;">*</span></label>
                    <input type="text" id="eqCustomName" class="form-control" placeholder="e.g., Special Equipment Name">
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea id="eqDescription" class="form-control" rows="3" placeholder="Description of the item..."></textarea>
            </div>
            
            <div class="form-group">
                <label>Equipment Image</label>
                <div class="custom-file-upload" style="border: 2px dashed #e2efe8; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.2s;" onclick="document.getElementById('eqImage').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #43e97b;"></i>
                    <p style="margin-top: 10px; color: #666;">Click to upload image</p>
                    <p style="font-size: 12px; color: #999;">JPG, PNG, GIF, WEBP (Max 5MB)</p>
                    <input type="file" id="eqImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;" onchange="previewImage(this)">
                </div>
                <div id="imagePreview" style="margin-top:10px; display:none; text-align:center;">
                    <img id="previewImg" src="#" alt="Preview" style="max-width:150px; border-radius:8px; border:1px solid #e2efe8;">
                    <button type="button" class="btn-close" style="display:block; margin:5px auto 0; padding:2px 10px;" onclick="clearImage()">Remove</button>
                </div>
            </div>
            
            ${quantityHtml}
            
            <div class="form-buttons">
                <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Save</button>
                <button type="button" class="btn-close" onclick="showAddEquipmentModal()">Back</button>
                <button type="button" class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
            </div>
        </form>
    `;
}

// Handle item type change
function onItemTypeChange() {
    const selected = document.getElementById('eqItemType');
    const customGroup = document.getElementById('customNameGroup');
    const customNameInput = document.getElementById('eqCustomName');
    
    if (selected.value === '__other__') {
        customGroup.style.display = 'block';
        customNameInput.required = true;
    } else {
        customGroup.style.display = 'none';
        customNameInput.required = false;
    }
}

// Preview image before upload
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Clear selected image
function clearImage() {
    const imageInput = document.getElementById('eqImage');
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    
    imageInput.value = '';
    previewImg.src = '#';
    preview.style.display = 'none';
}

// Save equipment based on category
async function saveEquipment(event, category) {
    event.preventDefault();
    
    const itemTypeSelect = document.getElementById('eqItemType');
    const selectedItem = itemTypeSelect.value;
    let itemName = '';
    
    if (!selectedItem) {
        if (typeof showErrorModal === 'function') {
            showErrorModal('Please select an item type', 'Validation Error');
        } else {
            alert('Please select an item type');
        }
        return;
    }
    
    if (selectedItem === '__other__') {
        itemName = document.getElementById('eqCustomName').value;
        if (!itemName) {
            if (typeof showErrorModal === 'function') {
                showErrorModal('Please enter a custom item name', 'Validation Error');
            } else {
                alert('Please enter a custom item name');
            }
            return;
        }
    } else {
        itemName = selectedItem;
    }
    
    const description = document.getElementById('eqDescription').value;
    const totalQuantity = (category === 'Equipment') ? parseInt(document.getElementById('eqQuantity').value) : 1;
    
    const formData = new FormData();
    formData.append('action', 'add_equipment');
    formData.append('name', itemName);
    formData.append('description', description);
    formData.append('category', category);
    formData.append('total_quantity', totalQuantity);
    formData.append('status', 'active');
    formData.append('is_predefined', 1);
    
    const imageFile = document.getElementById('eqImage').files[0];
    if (imageFile) {
        formData.append('equipment_image', imageFile);
    }
    
    try {
        const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            let message = `Successfully added "${itemName}"`;
            if (category === 'Equipment' && totalQuantity > 1) {
                message += ` with ${totalQuantity} items!`;
            } else {
                message += '!';
            }
            
            if (typeof showSuccessModal === 'function') {
                showSuccessModal(message, 'Success');
            } else {
                alert(message);
            }
            
            closeEquipmentModal();
            
            // Auto-refresh the display
            triggerRefresh();
        } else {
            if (typeof showErrorModal === 'function') {
                showErrorModal(data.message || 'Failed to add equipment', 'Error');
            } else {
                alert(data.message || 'Failed to add equipment');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        if (typeof showErrorModal === 'function') {
            showErrorModal('An error occurred while adding equipment', 'Error');
        } else {
            alert('An error occurred while adding equipment');
        }
    }
}

// Edit equipment type
async function editEquipment(id) {
    const modal = document.getElementById('equipmentModal');
    const modalTitle = document.getElementById('equipmentModalTitle');
    const modalBody = document.getElementById('equipmentModalBody');
    
    if (!modal || !modalTitle || !modalBody) return;
    
    modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Equipment Type';
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>`;
    modal.style.display = 'block';
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment_by_id&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const item = data.data;
            const imageHtml = item.image ? `<img src="../${item.image}" style="max-width:150px; border-radius:8px; margin-top:10px;"><br><label><input type="checkbox" id="removeImage"> Remove current image</label>` : '<p class="text-muted">No image uploaded</p>';
            
            const quantityDisabled = (item.category === 'Facility' || item.category === 'Vehicle') ? 'disabled' : '';
            const quantityWarning = (item.category === 'Facility' || item.category === 'Vehicle') ? 'block' : 'none';
            
            modalBody.innerHTML = `
                <form id="equipmentForm" enctype="multipart/form-data" onsubmit="updateEquipment(event, ${item.id})">
                    <div class="form-group">
                        <label>Category <span style="color:red;">*</span></label>
                        <select id="eqCategory" class="form-control" required>
                            <option value="Equipment" ${item.category === 'Equipment' ? 'selected' : ''}>Equipment</option>
                            <option value="Facility" ${item.category === 'Facility' ? 'selected' : ''}>Facility</option>
                            <option value="Vehicle" ${item.category === 'Vehicle' ? 'selected' : ''}>Vehicle</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Item Name <span style="color:red;">*</span></label>
                        <input type="text" id="eqName" class="form-control" required value="${escapeHtml(item.name)}">
                        <small class="form-text text-muted">This is the equipment type name (e.g., Tent, Table, Covered Court)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="eqDescription" class="form-control" rows="3">${escapeHtml(item.description || '')}</textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Image</label>
                        <div id="currentImageContainer">${imageHtml}</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Change Image (Optional)</label>
                        <input type="file" id="eqImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="form-text text-muted">Max 5MB. Leave empty to keep current image.</small>
                        <div id="imagePreview" style="margin-top:10px; display:none;">
                            <img id="previewImg" src="#" alt="Preview" style="max-width:150px; border-radius:8px;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Total Quantity <span style="color:red;">*</span></label>
                        <input type="number" id="eqQuantity" class="form-control" required min="1" value="${item.total_quantity}" ${quantityDisabled}>
                        <small class="form-text text-muted">Number of individual items (e.g., 5 tents = Tent 1, Tent 2, etc.)</small>
                        <div id="quantityWarning" style="display:${quantityWarning}; color:#dc3545; margin-top:5px;">
                            <i class="fas fa-info-circle"></i> Facilities and Vehicles can only have 1 quantity.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select id="eqStatus" class="form-control">
                            <option value="active" ${item.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="inactive" ${item.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update</button>
                        <button type="button" class="btn-close" onclick="closeEquipmentModal()">Cancel</button>
                    </div>
                </form>
            `;
            
            const categorySelect = document.getElementById('eqCategory');
            if (categorySelect) {
                categorySelect.addEventListener('change', function() {
                    const quantityInput = document.getElementById('eqQuantity');
                    const warningDiv = document.getElementById('quantityWarning');
                    const selectedCategory = this.value;
                    
                    if (selectedCategory === 'Facility' || selectedCategory === 'Vehicle') {
                        quantityInput.value = 1;
                        quantityInput.disabled = true;
                        if (warningDiv) warningDiv.style.display = 'block';
                    } else {
                        quantityInput.disabled = false;
                        if (warningDiv) warningDiv.style.display = 'none';
                    }
                });
            }
            
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
                    } else {
                        preview.style.display = 'none';
                    }
                });
            }
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>${escapeHtml(data.message)}</p></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading data</p></div>`;
    }
}

// Update equipment type
async function updateEquipment(event, id) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'update_equipment');
    formData.append('id', id);
    formData.append('name', document.getElementById('eqName').value);
    formData.append('description', document.getElementById('eqDescription').value);
    formData.append('category', document.getElementById('eqCategory').value);
    formData.append('total_quantity', document.getElementById('eqQuantity').value);
    formData.append('status', document.getElementById('eqStatus').value);
    
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
            if (typeof showSuccessModal === 'function') {
                showSuccessModal('Equipment updated successfully!', 'Success');
            } else {
                alert('Equipment updated successfully!');
            }
            closeEquipmentModal();
            
            // Auto-refresh the display
            triggerRefresh();
        } else {
            if (typeof showErrorModal === 'function') {
                showErrorModal(data.message || 'Failed to update equipment', 'Error');
            } else {
                alert(data.message || 'Failed to update equipment');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        if (typeof showErrorModal === 'function') {
            showErrorModal('An error occurred', 'Error');
        } else {
            alert('An error occurred');
        }
    }
}

// Delete equipment type
function deleteEquipmentItem(id, name) {
    const confirmMessage = `Delete "${name}"? This will also remove all individual items and associated bookings. This action cannot be undone.`;
    
    if (typeof showConfirmationModal === 'function') {
        showConfirmationModal(confirmMessage, 'Confirm Delete', async () => {
            const formData = new FormData();
            formData.append('action', 'delete_equipment');
            formData.append('id', id);
            const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                if (typeof showSuccessModal === 'function') {
                    showSuccessModal('Equipment deleted successfully!', 'Deleted');
                } else {
                    alert('Equipment deleted successfully!');
                }
                
                // Auto-refresh the display
                triggerRefresh();
            } else {
                if (typeof showErrorModal === 'function') {
                    showErrorModal(data.message || 'Failed to delete equipment', 'Error');
                } else {
                    alert(data.message || 'Failed to delete equipment');
                }
            }
        });
    } else {
        if (confirm(confirmMessage)) {
            (async () => {
                const formData = new FormData();
                formData.append('action', 'delete_equipment');
                formData.append('id', id);
                const response = await fetch('equipment_ajax.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    alert('Equipment deleted successfully!');
                    triggerRefresh();
                } else {
                    alert(data.message || 'Failed to delete equipment');
                }
            })();
        }
    }
}

// ============ EQUIPMENT LIST FUNCTIONS ============

// Load equipment list
async function loadEquipmentList() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading equipment...</p></div></div>`;
    
    try {
        const response = await fetch(`equipment_ajax.php?action=get_equipment&category=${currentEquipmentFilter}`);
        const data = await response.json();
        if (data.success) {
            dashboardBody.innerHTML = renderEquipmentList(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load equipment</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}

// Render equipment list
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
            ${categories.map(cat => `<button class="filter-chip ${currentEquipmentFilter === cat ? 'active' : ''}" onclick="filterEquipment('${cat.replace(/'/g, "\\'")}')">${escapeHtml(cat)}</button>`).join('')}
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
        
        return `
            <div class="data-card shopee-card" data-id="${item.id}" data-name="${escapeHtml(item.name)}">
                <div class="card-actions-menu shopee-menu">
                    <button class="kebab-menu" onclick="event.stopPropagation(); toggleKebabMenu(${item.id})">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="kebab-dropdown" id="kebabMenu-${item.id}">
                        <div class="kebab-item" onclick="event.stopPropagation(); editEquipment(${item.id})">
                            <i class="fas fa-edit"></i> Edit
                        </div>
                        <div class="kebab-item" onclick="event.stopPropagation(); deleteEquipmentItem(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
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

// Show equipment items
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

// Render equipment items list
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

// Delete individual equipment item
function deleteIndividualItem(id, itemName) {
    const confirmMessage = `Delete "${itemName}"? This action cannot be undone. All booking history for this item will be removed.`;
    
    if (typeof showConfirmationModal === 'function') {
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
                        
                        // Auto-refresh the display
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
        });
    } else {
        if (confirm(confirmMessage)) {
            var formData = new FormData();
            formData.append('action', 'delete_equipment_item');
            formData.append('id', id);
            
            fetch('equipment_ajax.php', { method: 'POST', body: formData })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        alert('Item deleted successfully!');
                        triggerRefresh();
                    } else {
                        alert(data.message || 'Failed to delete item');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    alert('An error occurred');
                });
        }
    }
}

// Toggle kebab menu
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

// Close kebab menus when clicking elsewhere
document.addEventListener('click', function() {
    document.querySelectorAll('.kebab-dropdown.show').forEach(menu => {
        menu.classList.remove('show');
    });
});

// Filter equipment
function filterEquipment(category) {
    currentEquipmentFilter = category;
    loadEquipmentList();
}

// ============ EQUIPMENT SCHEDULE CALENDAR FUNCTIONS ============

// Show equipment schedule in modal
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

// Render schedule calendar
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

// Generate calendar HTML
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

// Generate bookings list HTML
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

// Change calendar month
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

// View day bookings
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

// Fetch equipment bookings helper
async function fetchEquipmentBookings(equipmentId) {
    const response = await fetch(`equipment_ajax.php?action=get_equipment_bookings&id=${equipmentId}`);
    const data = await response.json();
    return data.success ? data.bookings : [];
}

// Show day bookings modal
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

// Close day bookings modal
function closeDayBookingsModal() {
    const modal = document.getElementById('dayBookingsModal');
    if (modal) modal.style.display = 'none';
}

// Close schedule modal
function closeScheduleModal() {
    const modal = document.getElementById('scheduleModal');
    if (modal) modal.style.display = 'none';
    currentScheduleEquipmentId = null;
    currentScheduleEquipmentName = '';
    currentCalendarYear = new Date().getFullYear();
    currentCalendarMonth = new Date().getMonth();
}

// ============ BOOKINGS FUNCTIONS ============

// Load bookings
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

// Render bookings list
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
    
    if (!bookings || bookings.length === 0) {
        return `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
                <div class="section-sub">Manage equipment and facility booking requests from residents</div>
                <div class="filter-bar">
                    <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadBookings('pending', 1)">Pending</button>
                    <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadBookings('approved', 1)">Approved</button>
                    <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadBookings('borrowed', 1)">Borrowed</button>
                    <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadBookings('returned', 1)">Returned</button>
                    <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadBookings('rejected', 1)">Rejected</button>
                </div>
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-3x"></i>
                    <p>No ${currentBookingFilter} booking requests found.</p>
                </div>
            </div>
        `;
    }
    
    const cardsHtml = bookings.map(booking => `
        <div class="data-card" onclick="viewBookingDetails(${booking.id})">
            <div class="card-header-gradient ${booking.status === 'pending' ? 'pending' : (booking.status === 'approved' ? 'approved' : 'completed')}">
                <div class="card-title-large">
                    <i class="fas fa-calendar-alt"></i>
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
                        <div class="info-icon"><i class="fas fa-calendar"></i></div>
                        <div class="info-label-card">Booking Date:</div>
                        <div class="info-value-card">${formatDate(booking.booking_date)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="info-label-card">Duration:</div>
                        <div class="info-value-card">${booking.duration_days || 1} day(s)</div>
                    </div>
                </div>
            </div>
            <div class="click-hint">
                <i class="fas fa-mouse-pointer"></i> Click to view details and take action
            </div>
        </div>
    `).join('');
    
    let paginationHtml = '';
    if (totalPages > 1) {
        paginationHtml = '<div class="pagination-wrapper"><div class="pagination">';
        if (currentPage > 1) paginationHtml += `<button class="page-btn" onclick="loadBookings('${currentBookingFilter}', ${currentPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
        for (let i = 1; i <= totalPages; i++) {
            paginationHtml += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="loadBookings('${currentBookingFilter}', ${i})">${i}</button>`;
        }
        if (currentPage < totalPages) paginationHtml += `<button class="page-btn" onclick="loadBookings('${currentBookingFilter}', ${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
        paginationHtml += '</div></div>';
    }
    
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
            <div class="section-sub">Manage equipment and facility booking requests from residents</div>
            <div class="filter-bar">
                <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadBookings('pending', 1)">Pending</button>
                <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadBookings('approved', 1)">Approved</button>
                <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadBookings('borrowed', 1)">Borrowed</button>
                <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadBookings('returned', 1)">Returned</button>
                <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadBookings('rejected', 1)">Rejected</button>
            </div>
            <div class="cards-grid">
                ${cardsHtml}
            </div>
            ${paginationHtml}
        </div>
    `;
}

// View booking details
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
            
            modalBody.innerHTML = `
                <div class="detail-section"><h4>Resident Information</h4>
                    <div class="detail-row"><div class="detail-label">Full Name:</div><div class="detail-value"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Email:</div><div class="detail-value">${escapeHtml(booking.email)}</div></div>
                    <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(booking.phone || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Address:</div><div class="detail-value">${escapeHtml(booking.address || '-')}</div></div>
                </div>
                <div class="detail-section"><h4>Booking Information</h4>
                    <div class="detail-row"><div class="detail-label">Equipment/Facility:</div><div class="detail-value"><strong>${escapeHtml(booking.equipment_name)}</strong> (${escapeHtml(booking.category)})</div></div>
                    <div class="detail-row"><div class="detail-label">Purpose:</div><div class="detail-value">${escapeHtml(booking.purpose || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Booking Date:</div><div class="detail-value">${formatDate(booking.booking_date)}</div></div>
                    <div class="detail-row"><div class="detail-label">Duration:</div><div class="detail-value">${booking.duration_days || 1} day(s)</div></div>
                    <div class="detail-row"><div class="detail-label">Expected Return:</div><div class="detail-value">${booking.expected_return_date ? formatDate(booking.expected_return_date) : '-'}</div></div>
                    <div class="detail-row"><div class="detail-label">Status:</div><div class="detail-value">${getStatusBadgeHtml(booking.status)}</div></div>
                    <div class="detail-row"><div class="detail-label">Request Date:</div><div class="detail-value">${formatDate(booking.created_at)}</div></div>
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

// Show booking action modal
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

// Submit booking action
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
                
                // Auto-refresh bookings list
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

// Show return modal
function showReturnModal(id) {
    pendingBookingActionId = id;
    const modal = document.getElementById('returnModal');
    const notesField = document.getElementById('returnNotes');
    if (modal) {
        modal.style.display = 'block';
        if (notesField) notesField.value = '';
    }
}

// Confirm return
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
            
            // Auto-refresh bookings list
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

// Load equipment dashboard
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

// Render equipment dashboard
function renderEquipmentDashboard(counts, pendingBookings) {
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-chart-pie"></i> Equipment & Facilities Dashboard</div>
            <div class="section-sub">Overview of barangay resources and bookings (Individual Item Tracking)</div>
            
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

// ============ SIDEBAR EVENT LISTENERS ============

// Initialize equipment sidebar
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

   // Update the equipmentSubOptions click handler - remove equipment_dashboard
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
        }
        // equipment_dashboard removed from here
        
        if (!equipmentExpanded) toggleEquipmentSubmenu(true);
        
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileDropdown) profileDropdown.classList.remove('show');
    });
});
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquipmentSidebar);
} else {
    initEquipmentSidebar();
}