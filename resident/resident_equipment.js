// resident_equipment.js - Equipment management for residents with inline booking form

let currentEquipmentList = [];
let currentEquipmentTypes = [];
let currentBookingsList = [];
let currentSelectedEquipmentType = null;
let currentBookingFilter = 'all';
let selectedItems = [];
let currentBookingFormData = null;
let currentStep = 'list'; // list, form, selection

// ============ LOAD EQUIPMENT TYPES ============
async function loadEquipmentList() {
    currentStep = 'list';
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading equipment...</div></div>`;
    
    try {
        const response = await fetch('resident_equipment_ajax.php?action=get_equipment_types');
        const data = await response.json();
        
        if (data.success) {
            currentEquipmentTypes = data.data;
            renderEquipmentTypes(data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load equipment</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading equipment</p></div></div>`;
    }
}

function renderEquipmentTypes(equipmentTypes) {
    if (!equipmentTypes || equipmentTypes.length === 0) {
        document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-tools"></i> Available Equipment & Facilities</div>
                <div class="section-sub">Browse and book barangay resources</div>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <p>No equipment or facilities available at the moment.</p>
                </div>
            </div>
        `;
        return;
    }
    
    const categories = [...new Set(equipmentTypes.map(e => e.category))];
    const filterButtons = `
        <div class="filter-buttons" style="margin-bottom: 20px;">
            <button class="filter-btn ${currentEquipmentFilter === 'all' ? 'active' : ''}" onclick="filterEquipmentByCategory('all')">All</button>
            ${categories.map(cat => `<button class="filter-btn ${currentEquipmentFilter === cat ? 'active' : ''}" onclick="filterEquipmentByCategory('${cat.replace(/'/g, "\\'")}')">${escapeHtml(cat)}</button>`).join('')}
        </div>
    `;
    
    const cardsHtml = equipmentTypes.map(type => {
        const isAvailable = type.available_quantity > 0;
        const availabilityText = isAvailable ? `${type.available_quantity} of ${type.total_quantity} available` : 'Not Available';
        const imagePath = type.image ? '../' + type.image : null;
        
        let defaultIcon = 'fa-tools';
        if (type.category === 'Facility') defaultIcon = 'fa-building';
        else if (type.category === 'Vehicle') defaultIcon = 'fa-truck';
        
        return `
            <div class="equipment-card" onclick="showBookingForm(${type.id}, '${escapeHtml(type.name)}', ${type.total_quantity}, '${type.category}')">
                <div class="equipment-card-image">
                    ${imagePath ? 
                        `<img src="${imagePath}" alt="${escapeHtml(type.name)}" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-fallback\'><i class=\'fas ${defaultIcon}\'></i></div>';">` : 
                        `<div class="image-fallback"><i class="fas ${defaultIcon}"></i></div>`
                    }
                    <span class="equipment-status-badge ${isAvailable ? 'available' : 'unavailable'}">${availabilityText}</span>
                </div>
                <div class="equipment-card-content">
                    <div class="equipment-card-title">${escapeHtml(type.name)}</div>
                    <div class="equipment-card-category">${escapeHtml(type.category)}</div>
                    <div class="equipment-card-desc">${escapeHtml(type.description || 'No description available')}</div>
                    <div class="equipment-card-footer">
                        <div class="equipment-availability ${isAvailable ? 'available' : 'unavailable'}">
                            <i class="fas ${isAvailable ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${availabilityText}
                        </div>
                        <button class="book-equipment-btn" onclick="event.stopPropagation(); showBookingForm(${type.id}, '${escapeHtml(type.name)}', ${type.total_quantity}, '${type.category}')">
                            <i class="fas fa-calendar-plus"></i> Book Now
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-tools"></i> Available Equipment & Facilities</div>
            <div class="section-sub">Browse and book barangay resources for your needs</div>
            ${filterButtons}
            <div class="equipment-grid">
                ${cardsHtml}
            </div>
        </div>
    `;
}

let currentEquipmentFilter = 'all';

function filterEquipmentByCategory(category) {
    currentEquipmentFilter = category;
    
    let filtered = currentEquipmentTypes;
    if (category !== 'all') {
        filtered = currentEquipmentTypes.filter(item => item.category === category);
    }
    
    renderEquipmentTypes(filtered);
}

// ============ STEP 1: SHOW BOOKING FORM (INLINE) ============
function showBookingForm(typeId, typeName, totalQuantity, category) {
    currentSelectedEquipmentType = { id: typeId, name: typeName, category: category };
    currentStep = 'form';
    
    const dashboardBody = document.getElementById('dashboardBody');
    
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="booking-progress">
                <div class="progress-step active">1. Booking Details</div>
                <div class="progress-step">2. Select Items</div>
                <div class="progress-step">3. Confirm</div>
            </div>
            
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Book: ${escapeHtml(typeName)}</div>
            <div class="section-sub">Fill in your booking details below</div>
            
            <div class="booking-info-panel">
                <i class="fas fa-info-circle"></i> 
                <span>Please fill in your booking details. After submitting, you'll see which items are available for your selected date.</span>
            </div>
            
            <form id="bookingForm" onsubmit="submitBookingForm(event)">
                <div class="form-row">
                    <div class="booking-form-group">
                        <label class="required">Booking Date & Time <span style="color:red;">*</span></label>
                        <input type="datetime-local" name="booking_date" id="bookingDateTime" required>
                        <small>Select when you want to use the equipment</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Duration (Days) <span style="color:red;">*</span></label>
                        <select name="duration_days" id="durationDays" required>
                            <option value="1">1 Day</option>
                            <option value="2">2 Days</option>
                            <option value="3">3 Days</option>
                            <option value="4">4 Days</option>
                            <option value="5">5 Days</option>
                            <option value="6">6 Days</option>
                            <option value="7">7 Days</option>
                        </select>
                    </div>
                </div>
                
                <div class="booking-form-group">
                    <label class="required">Purpose <span style="color:red;">*</span></label>
                    <textarea name="purpose" id="bookingPurpose" rows="3" required placeholder="Please state the purpose of booking..."></textarea>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn-secondary" onclick="loadEquipmentList()">
                        <i class="fas fa-arrow-left"></i> Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-arrow-right"></i> Check Availability
                    </button>
                </div>
            </form>
        </div>
    `;
    
    // Set min date to today
    const today = new Date();
    today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
    const minDateTime = today.toISOString().slice(0, 16);
    document.getElementById('bookingDateTime').min = minDateTime;
}

async function submitBookingForm(event) {
    event.preventDefault();
    
    const bookingDate = document.getElementById('bookingDateTime').value;
    const durationDays = parseInt(document.getElementById('durationDays').value);
    const purpose = document.getElementById('bookingPurpose').value;
    
    if (!bookingDate) {
        showErrorModal('Please select a booking date and time');
        return;
    }
    
    if (!purpose.trim()) {
        showErrorModal('Please state the purpose of booking');
        return;
    }
    
    // Store booking form data
    currentBookingFormData = {
        booking_date: bookingDate,
        duration_days: durationDays,
        purpose: purpose
    };
    
    // Show loading
    const dashboardBody = document.getElementById('dashboardBody');
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i> 
                Checking available items for ${escapeHtml(currentSelectedEquipmentType.name)}...
            </div>
        </div>
    `;
    
    // Fetch and display available items for this date
    await loadAvailableItemsForDate(
        currentSelectedEquipmentType.id,
        currentSelectedEquipmentType.name,
        bookingDate,
        durationDays
    );
}

// ============ STEP 2: SHOW AVAILABLE ITEMS ============
async function loadAvailableItemsForDate(typeId, typeName, bookingDate, durationDays) {
    try {
        const response = await fetch(`resident_equipment_ajax.php?action=get_available_items_for_date&type_id=${typeId}&booking_date=${encodeURIComponent(bookingDate)}&duration_days=${durationDays}`);
        const data = await response.json();
        
        if (data.success) {
            renderAvailableItemsSelection(data.available_items, data.unavailable_items, typeName, bookingDate, durationDays);
        } else {
            showError('Failed to load available items');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('Error loading available items');
    }
}

function renderAvailableItemsSelection(availableItems, unavailableItems, typeName, bookingDate, durationDays) {
    currentStep = 'selection';
    const formattedDate = new Date(bookingDate).toLocaleString();
    
    const availableHtml = availableItems.map(item => `
        <div class="item-selection-card available" data-item-id="${item.id}" data-item-name="${escapeHtml(item.item_name)}">
            <div class="item-checkbox">
                <input type="checkbox" class="item-checkbox-input" id="item_${item.id}" value="${item.id}" data-name="${escapeHtml(item.item_name)}">
            </div>
            <div class="item-info">
                <div class="item-name">${escapeHtml(item.item_name)}</div>
                <div class="item-status available">
                    <i class="fas fa-check-circle"></i> Available
                </div>
            </div>
            <div class="item-number">#${item.item_number}</div>
        </div>
    `).join('');
    
    const unavailableHtml = unavailableItems.map(item => `
        <div class="item-selection-card unavailable disabled" data-item-id="${item.id}" data-item-name="${escapeHtml(item.item_name)}">
            <div class="item-checkbox">
                <input type="checkbox" class="item-checkbox-input" id="item_${item.id}" value="${item.id}" data-name="${escapeHtml(item.item_name)}" disabled>
            </div>
            <div class="item-info">
                <div class="item-name">${escapeHtml(item.item_name)}</div>
                <div class="item-status unavailable">
                    <i class="fas fa-times-circle"></i> Not Available
                </div>
                ${item.conflicting_booking ? `<div class="item-conflict"><small>Booked: ${escapeHtml(item.conflicting_booking)}</small></div>` : ''}
            </div>
            <div class="item-number">#${item.item_number}</div>
        </div>
    `).join('');
    
    const dashboardBody = document.getElementById('dashboardBody');
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="booking-progress">
                <div class="progress-step completed">1. Booking Details</div>
                <div class="progress-step active">2. Select Items</div>
                <div class="progress-step">3. Confirm</div>
            </div>
            
            <div class="section-title"><i class="fas fa-boxes"></i> Select Items: ${escapeHtml(typeName)}</div>
            <div class="section-sub">Choose the specific items you want to book</div>
            
            <div class="booking-summary">
                <div class="summary-card">
                    <i class="fas fa-calendar"></i>
                    <div>
                        <strong>Booking Date & Time</strong>
                        <p>${formattedDate}</p>
                    </div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-hourglass-half"></i>
                    <div>
                        <strong>Duration</strong>
                        <p>${durationDays} day(s)</p>
                    </div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-comment"></i>
                    <div>
                        <strong>Purpose</strong>
                        <p>${escapeHtml(currentBookingFormData.purpose.substring(0, 50))}${currentBookingFormData.purpose.length > 50 ? '...' : ''}</p>
                    </div>
                </div>
            </div>
            
            <div class="availability-stats">
                <span class="available-count"><i class="fas fa-check-circle"></i> ${availableItems.length} Available</span>
                <span class="unavailable-count"><i class="fas fa-times-circle"></i> ${unavailableItems.length} Not Available</span>
            </div>
            
            <div class="items-selection-wrapper">
                <div class="available-section">
                    <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Available Items</h4>
                    <div class="items-selection-grid">
                        ${availableHtml || '<p class="no-items">No available items for this date.</p>'}
                    </div>
                </div>
                ${unavailableItems.length > 0 ? `
                    <div class="unavailable-section">
                        <h4><i class="fas fa-times-circle" style="color: #dc3545;"></i> Unavailable Items</h4>
                        <div class="items-selection-grid">
                            ${unavailableHtml}
                        </div>
                    </div>
                ` : ''}
            </div>
            
            <div class="selected-items-summary" id="selectedItemsSummary" style="margin-top: 20px; display: none;">
                <h4>Selected Items:</h4>
                <div id="selectedItemsList"></div>
            </div>
            
            <div class="booking-controls">
                <button class="btn-secondary" onclick="goBackToBookingForm()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="btn-primary" id="proceedToConfirmBtn" onclick="confirmBooking()" disabled>
                    <i class="fas fa-check-circle"></i> Confirm Booking
                </button>
            </div>
        </div>
    `;
    
    // Add event listeners to checkboxes
    const checkboxes = document.querySelectorAll('.item-selection-card.available .item-checkbox-input');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedItemsSummary);
    });
    
    // Add click to card for available items only
    const cards = document.querySelectorAll('.item-selection-card.available');
    cards.forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.item-checkbox-input');
                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = !checkbox.checked;
                    updateSelectedItemsSummary();
                }
            }
        });
    });
}

function updateSelectedItemsSummary() {
    const checkboxes = document.querySelectorAll('.item-selection-card.available .item-checkbox-input:checked');
    const selectedItemsList = document.getElementById('selectedItemsList');
    const summaryDiv = document.getElementById('selectedItemsSummary');
    const confirmBtn = document.getElementById('proceedToConfirmBtn');
    
    if (checkboxes.length === 0) {
        summaryDiv.style.display = 'none';
        confirmBtn.disabled = true;
        return;
    }
    
    const selectedNames = Array.from(checkboxes).map(cb => cb.getAttribute('data-name'));
    selectedItemsList.innerHTML = selectedNames.map(name => `<span class="selected-item-badge">${escapeHtml(name)}</span>`).join('');
    summaryDiv.style.display = 'block';
    confirmBtn.disabled = false;
    
    // Store selected items
    selectedItems = Array.from(checkboxes).map(cb => ({
        id: parseInt(cb.value),
        name: cb.getAttribute('data-name')
    }));
}

function goBackToBookingForm() {
    // Go back to the booking form with preserved data
    showBookingForm(
        currentSelectedEquipmentType.id,
        currentSelectedEquipmentType.name,
        0,
        currentSelectedEquipmentType.category
    );
    
    // Pre-fill the form with previous data
    setTimeout(() => {
        const dateInput = document.getElementById('bookingDateTime');
        const durationSelect = document.getElementById('durationDays');
        const purposeText = document.getElementById('bookingPurpose');
        
        if (dateInput && currentBookingFormData && currentBookingFormData.booking_date) {
            dateInput.value = currentBookingFormData.booking_date;
        }
        if (durationSelect && currentBookingFormData && currentBookingFormData.duration_days) {
            durationSelect.value = currentBookingFormData.duration_days;
        }
        if (purposeText && currentBookingFormData && currentBookingFormData.purpose) {
            purposeText.value = currentBookingFormData.purpose;
        }
    }, 100);
}

async function confirmBooking() {
    if (!selectedItems.length) {
        showErrorModal('Please select at least one item to book.');
        return;
    }
    
    const confirmBtn = document.getElementById('proceedToConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    try {
        const response = await fetch('resident_equipment_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_multiple_bookings',
                item_ids: selectedItems.map(item => item.id),
                booking_date: currentBookingFormData.booking_date,
                duration_days: currentBookingFormData.duration_days,
                purpose: currentBookingFormData.purpose
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal(`Successfully booked ${data.booked_count} item(s)!`);
            // Reset data
            selectedItems = [];
            currentBookingFormData = null;
            currentSelectedEquipmentType = null;
            loadMyBookings();
        } else {
            showErrorModal(data.message || 'Failed to create booking');
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred. Please try again.');
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
    }
}

function showError(message) {
    const dashboardBody = document.getElementById('dashboardBody');
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle fa-3x"></i>
                <p>${escapeHtml(message)}</p>
                <button class="btn-secondary" onclick="loadEquipmentList()" style="margin-top: 15px;">
                    <i class="fas fa-arrow-left"></i> Back to Equipment
                </button>
            </div>
        </div>
    `;
}

// ============ LOAD MY BOOKINGS ============
async function loadMyBookings() {
    currentStep = 'list';
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading your bookings...</div></div>`;
    
    try {
        const response = await fetch('resident_equipment_ajax.php?action=get_my_bookings');
        const data = await response.json();
        
        if (data.success) {
            currentBookingsList = data.bookings;
            renderMyBookings(data.bookings);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading bookings</p></div></div>`;
    }
}

function renderMyBookings(bookings) {
    if (!bookings || bookings.length === 0) {
        document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-calendar-alt"></i> My Equipment Bookings</div>
                <div class="section-sub">View and track your equipment booking requests</div>
                <div class="filter-buttons" style="margin-bottom: 20px;">
                    <button class="filter-btn active" onclick="filterBookings('all')">All</button>
                    <button class="filter-btn" onclick="filterBookings('pending')">Pending</button>
                    <button class="filter-btn" onclick="filterBookings('approved')">Approved</button>
                    <button class="filter-btn" onclick="filterBookings('borrowed')">Borrowed</button>
                    <button class="filter-btn" onclick="filterBookings('returned')">Returned</button>
                    <button class="filter-btn" onclick="filterBookings('rejected')">Rejected</button>
                </div>
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-3x"></i>
                    <p>You have no booking requests yet.</p>
                    <button class="submit-btn" onclick="loadEquipmentList()" style="margin-top: 15px; width: auto; padding: 10px 25px;">
                        <i class="fas fa-tools"></i> Browse Equipment
                    </button>
                </div>
            </div>
        `;
        return;
    }
    
    const filterButtons = `
        <div class="filter-buttons" style="margin-bottom: 20px;">
            <button class="filter-btn ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="filterBookings('all')">All</button>
            <button class="filter-btn ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="filterBookings('pending')">Pending</button>
            <button class="filter-btn ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="filterBookings('approved')">Approved</button>
            <button class="filter-btn ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="filterBookings('borrowed')">Borrowed</button>
            <button class="filter-btn ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="filterBookings('returned')">Returned</button>
            <button class="filter-btn ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="filterBookings('rejected')">Rejected</button>
        </div>
    `;
    
    let filteredBookings = bookings;
    if (currentBookingFilter !== 'all') {
        filteredBookings = bookings.filter(b => b.status === currentBookingFilter);
    }
    
    const bookingsHtml = filteredBookings.map(booking => {
        const bookingDate = new Date(booking.booking_date);
        const expectedReturn = new Date(booking.expected_return_date);
        
        return `
            <div class="booking-card ${booking.status}">
                <div class="booking-header">
                    <div class="booking-equipment-name">
                        <i class="fas ${booking.category === 'Facility' ? 'fa-building' : (booking.category === 'Vehicle' ? 'fa-truck' : 'fa-tools')}"></i>
                        ${escapeHtml(booking.item_name || booking.equipment_name)}
                    </div>
                    <div class="booking-status ${booking.status}">
                        ${getStatusIcon(booking.status)} ${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}
                    </div>
                </div>
                <div class="booking-details">
                    <div class="booking-detail-item">
                        <i class="fas fa-calendar"></i>
                        <span>Booking: ${bookingDate.toLocaleDateString()} at ${bookingDate.toLocaleTimeString()}</span>
                    </div>
                    <div class="booking-detail-item">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Duration: ${booking.duration_days} day(s)</span>
                    </div>
                    <div class="booking-detail-item">
                        <i class="fas fa-undo-alt"></i>
                        <span>Expected Return: ${expectedReturn.toLocaleDateString()}</span>
                    </div>
                    <div class="booking-detail-item">
                        <i class="fas fa-calendar-check"></i>
                        <span>Requested: ${new Date(booking.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                ${booking.purpose ? `
                    <div class="booking-purpose">
                        <i class="fas fa-comment"></i> <strong>Purpose:</strong> ${escapeHtml(booking.purpose)}
                    </div>
                ` : ''}
                ${booking.admin_notes ? `
                    <div class="booking-admin-notes">
                        <i class="fas fa-user-shield"></i> <strong>Admin Note:</strong> ${escapeHtml(booking.admin_notes)}
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> My Equipment Bookings</div>
            <div class="section-sub">View and track your equipment booking requests</div>
            ${filterButtons}
            <div class="bookings-list">
                ${filteredBookings.length === 0 ? 
                    `<div class="empty-state"><i class="fas fa-inbox"></i><p>No ${currentBookingFilter} bookings found.</p></div>` : 
                    bookingsHtml
                }
            </div>
        </div>
    `;
}

function filterBookings(status) {
    currentBookingFilter = status;
    renderMyBookings(currentBookingsList);
}

function getStatusIcon(status) {
    switch(status) {
        case 'pending': return '<i class="fas fa-clock"></i>';
        case 'approved': return '<i class="fas fa-check-circle"></i>';
        case 'borrowed': return '<i class="fas fa-hand-holding"></i>';
        case 'returned': return '<i class="fas fa-undo-alt"></i>';
        case 'rejected': return '<i class="fas fa-times-circle"></i>';
        default: return '<i class="fas fa-question-circle"></i>';
    }
}

// ============ HELPER FUNCTIONS ============
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showSuccessModal(message) {
    const modal = document.getElementById('successModal');
    const messageEl = document.getElementById('successMessage');
    if (modal && messageEl) {
        messageEl.innerHTML = message;
        modal.style.display = 'flex';
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
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 3000);
    } else {
        alert(message);
    }
}