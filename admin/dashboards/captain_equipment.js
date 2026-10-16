// secretary_equipment.js
// Secretary-side equipment module.
// - Endpoint: equipment_role_ajax.php
// - Read + individual item status edit + booking handling.
// - Cannot add / edit info / delete / change quantities.

// ============ GLOBAL VARIABLES ============
let currentEquipmentFilter = 'all';
let currentBookingFilter   = 'approved';
let currentBookingTypeFilter = 'all';   // ← NEW: shared by type-filter chips
let currentBookingPage     = 1;
let currentBookingTotalPages = 1;
let pendingBookingActionId = null;
let pendingBookingAction   = null;
let currentCalendarYear    = new Date().getFullYear();
let currentCalendarMonth   = new Date().getMonth();
let currentScheduleEquipmentId   = null;
let currentScheduleEquipmentName = '';

let currentEquipmentTypeId   = null;
let currentEquipmentTypeName = '';

let autoRefreshEnabled = true;

window.CURRENT_ROLE       = window.CURRENT_ROLE       || 'secretary';
window.CURRENT_ROLE_LABEL = window.CURRENT_ROLE_LABEL || 'Secretary';

// ============ DEBOUNCE ============
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => { clearTimeout(timeout); func(...args); };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============ AUTO-REFRESH ============
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
    switch (view) {
        case 'equipment_list':      await loadEquipmentList(); break;
        case 'equipment_bookings':  await loadBookings(currentBookingFilter, currentBookingPage); break;
        case 'equipment_dashboard': await loadEquipmentDashboard(); break;
    }
}

async function refreshEquipmentItemsList() {
    if (!currentEquipmentTypeId) return;
    try {
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment_items&type_id=${currentEquipmentTypeId}`);
        const data = await response.json();
        if (data.success) {
            const modalBody = document.getElementById('equipmentItemsModalBody');
            if (modalBody && modalBody.style.display !== 'none') {
                modalBody.innerHTML = renderEquipmentItemsList(data.data, currentEquipmentTypeName);
            }
        }
    } catch (error) { console.error('Error refreshing items:', error); }
}

function triggerRefresh() {
    if (!autoRefreshEnabled) return;
    setTimeout(async () => {
        await refreshCurrentView();
        const itemsModal = document.getElementById('equipmentItemsModal');
        if (itemsModal && itemsModal.style.display === 'block') await refreshEquipmentItemsList();
    }, 500);
}
function bookingTypeChipsHtml() {
    const t = currentBookingTypeFilter || 'all';
    return `
        <div class="filter-row filter-row-type">
            <span class="filter-row-label"><i class="fas fa-filter"></i> Type:</span>
            <button class="filter-chip ${t === 'all'       ? 'active' : ''}"
                    onclick="filterBookingByType('all')">
                <i class="fas fa-layer-group"></i> All Types
            </button>
            <button class="filter-chip ${t === 'equipment' ? 'active' : ''}"
                    onclick="filterBookingByType('equipment')">
                <i class="fas fa-tools"></i> Equipment
            </button>
            <button class="filter-chip ${t === 'facility'  ? 'active' : ''}"
                    onclick="filterBookingByType('facility')">
                <i class="fas fa-building"></i> Facilities
            </button>
            <button class="filter-chip ${t === 'vehicle'   ? 'active' : ''}"
                    onclick="filterBookingByType('vehicle')">
                <i class="fas fa-truck"></i> Vehicles
            </button>
        </div>
    `;
}

function bookingTypeChipsCalendarHtml() {
    const t = currentBookingTypeFilter || 'all';
    return `
        <div class="filter-row filter-row-type">
            <span class="filter-row-label"><i class="fas fa-filter"></i> Type:</span>
            <button class="filter-chip ${t === 'all'       ? 'active' : ''}"
                    onclick="filterCalendarByType('all')">
                <i class="fas fa-layer-group"></i> All Types
            </button>
            <button class="filter-chip ${t === 'equipment' ? 'active' : ''}"
                    onclick="filterCalendarByType('equipment')">
                <i class="fas fa-tools"></i> Equipment
            </button>
            <button class="filter-chip ${t === 'facility'  ? 'active' : ''}"
                    onclick="filterCalendarByType('facility')">
                <i class="fas fa-building"></i> Facilities
            </button>
            <button class="filter-chip ${t === 'vehicle'   ? 'active' : ''}"
                    onclick="filterCalendarByType('vehicle')">
                <i class="fas fa-truck"></i> Vehicles
            </button>
        </div>
    `;
}



// ============ INDIVIDUAL ITEM MANAGEMENT (SECRETARY) ============
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
                    <option value="available"   ${currentStatus === 'available'   ? 'selected' : ''}>Available</option>
                    <option value="maintenance" ${currentStatus === 'maintenance' ? 'selected' : ''}>Under Maintenance</option>
                    <option value="lost"        ${currentStatus === 'lost'        ? 'selected' : ''}>Lost / Missing</option>
                    <option value="borrowed"    ${currentStatus === 'borrowed'    ? 'selected' : ''}>Borrowed</option>
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
        </form>`;
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
    fetch('equipment_role_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (typeof showSuccessModal === 'function') showSuccessModal('Item status updated successfully!', 'Success');
                else alert('Item status updated successfully!');
                closeManageItemModal();
                triggerRefresh();
            } else {
                if (typeof showErrorModal === 'function') showErrorModal(data.message || 'Failed to update item', 'Error');
                else alert(data.message || 'Failed to update item');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof showErrorModal === 'function') showErrorModal('An error occurred', 'Error');
            else alert('An error occurred');
        });
}

function closeManageItemModal() {
    const modal = document.getElementById('manageItemModal');
    if (modal) modal.style.display = 'none';
}

// ============ EQUIPMENT LIST ============
async function loadEquipmentList() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading equipment...</p></div></div>`;
    try {
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment&category=${currentEquipmentFilter}`);
        const data = await response.json();
        if (data.success) dashboardBody.innerHTML = renderEquipmentList(data.data);
        else dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${escapeHtml(data.message || 'Failed to load equipment')}</p></div></div>`;
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}

function renderEquipmentList(equipment) {
    if (!equipment || equipment.length === 0) {
        return `<div class="content-card">
            <div class="section-title"><i class="fas fa-tools"></i> Equipment & Facilities</div>
            <div class="section-sub">View barangay equipment and facilities</div>
            <div class="empty-state"><i class="fas fa-box-open fa-3x"></i><p>No equipment or facilities found.</p></div>
        </div>`;
    }
    const filterButtons = `<div class="filter-bar">
        <button class="filter-chip ${currentEquipmentFilter === 'all' ? 'active' : ''}" onclick="filterEquipment('all')">All</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Equipment' ? 'active' : ''}" onclick="filterEquipment('Equipment')">Equipment</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Facility' ? 'active' : ''}" onclick="filterEquipment('Facility')">Facility</button>
        <button class="filter-chip ${currentEquipmentFilter === 'Vehicle' ? 'active' : ''}" onclick="filterEquipment('Vehicle')">Vehicle</button>
    </div>`;

    const cardsHtml = equipment.map(item => {
        const imagePath = item.image ? '../../' + item.image : null;
        let defaultIcon = 'fa-tools';
        if (item.category === 'Facility') defaultIcon = 'fa-building';
        else if (item.category === 'Vehicle') defaultIcon = 'fa-truck';
        const availableCount   = parseInt(item.available_count)   || 0;
        const borrowedCount    = parseInt(item.borrowed_count)    || 0;
        const maintenanceCount = parseInt(item.maintenance_count) || 0;
        const lostCount        = parseInt(item.lost_count)        || 0;
        const totalItems       = parseInt(item.total_items)       || 0;
        let overallStatus = '', overallStatusClass = '';
        if (availableCount > 0)   { overallStatus = `${availableCount} available`; overallStatusClass = 'status-available'; }
        else if (borrowedCount > 0)    { overallStatus = 'All Borrowed'; overallStatusClass = 'status-borrowed'; }
        else if (maintenanceCount > 0) { overallStatus = 'Under Maintenance'; overallStatusClass = 'status-maintenance'; }
        else                      { overallStatus = 'Unavailable'; overallStatusClass = 'status-unavailable'; }

        return `
            <div class="data-card shopee-card" data-id="${item.id}" data-name="${escapeHtml(item.name)}">
                <div class="shopee-image-container" onclick="showEquipmentDetails(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    ${imagePath ? `<img src="${imagePath}" class="shopee-image" alt="${escapeHtml(item.name)}" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-fallback\'><i class=\'fas ${defaultIcon}\'></i></div>';">` : `<div class="image-fallback"><i class="fas ${defaultIcon}"></i></div>`}
                </div>
                <div class="shopee-info" onclick="showEquipmentDetails(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    <div class="shopee-title">${escapeHtml(item.name)}</div>
                    <div class="shopee-category">${escapeHtml(item.category)}</div>
                    <div class="shopee-status"><span class="status-badge ${overallStatusClass}"><i class="fas ${availableCount > 0 ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${overallStatus}</span></div>
                    ${item.description ? `<div class="shopee-description">${escapeHtml(item.description.substring(0, 80))}${item.description.length > 80 ? '...' : ''}</div>` : ''}
                </div>
                <div class="shopee-footer" onclick="showEquipmentItems(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}')">
                    <div class="stats-row" style="display:flex; gap:8px; flex-wrap:wrap; font-size:0.65rem;">
                        ${availableCount > 0 ? `<span style="color:#28a745;"><i class="fas fa-check-circle"></i> ${availableCount} Available</span>` : ''}
                        ${borrowedCount > 0 ? `<span style="color:#17a2b8;"><i class="fas fa-hand-holding"></i> ${borrowedCount} Borrowed</span>` : ''}
                        ${maintenanceCount > 0 ? `<span style="color:#f4b942;"><i class="fas fa-wrench"></i> ${maintenanceCount} Maintenance</span>` : ''}
                        ${lostCount > 0 ? `<span style="color:#dc3545;"><i class="fas fa-question-circle"></i> ${lostCount} Lost</span>` : ''}
                    </div>
                </div>
            </div>`;
    }).join('');

    return `<div class="content-card">
        <div class="section-title"><i class="fas fa-tools"></i> Equipment & Facilities</div>
        <div class="section-sub">View barangay equipment, facilities, and resources</div>
        ${filterButtons}
        <div class="cards-grid shopee-grid">${cardsHtml}</div>
    </div>`;
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
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment_items&type_id=${typeId}`);
        const data = await response.json();
        if (data.success) modalBody.innerHTML = renderEquipmentItemsList(data.data, typeName);
        else modalBody.innerHTML = `<div class="empty-state-mini"><p>Failed to load items</p></div>`;
    } catch (error) { modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading items</p></div>`; }
}

function renderEquipmentItemsList(items, typeName) {
    if (!items || items.length === 0) return `<div class="empty-state-mini"><p>No items found for ${escapeHtml(typeName)}</p></div>`;
    const getStatusBadge = (status) => {
        switch(status) {
            case 'available':   return '<span class="badge-verified"><i class="fas fa-check-circle"></i> Available</span>';
            case 'borrowed':    return '<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>';
            case 'maintenance': return '<span class="badge-unverified" style="background:#fff3cd;color:#856404;"><i class="fas fa-wrench"></i> Maintenance</span>';
            case 'lost':        return '<span class="status-unverified" style="background:#f8d7da;color:#721c24;"><i class="fas fa-question-circle"></i> Lost</span>';
            default:            return '<span class="badge-unverified">' + status + '</span>';
        }
    };
    return `<div class="items-grid" style="display:grid; gap:15px;">
        ${items.map(item => `
            <div class="item-card" style="background:#f8faf8; border-radius:12px; padding:15px; border:1px solid #e2efe8;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong style="font-size:1.1rem;">${escapeHtml(item.item_name)}</strong>
                        <div style="font-size:0.8rem; color:#666; margin-top:5px;"><i class="fas fa-tag"></i> Item #${item.item_number}</div>
                    </div>
                    <div>${getStatusBadge(item.status)}</div>
                </div>
                ${item.condition_notes ? `<div style="margin-top:10px; font-size:0.75rem; background:#eef5f0; padding:8px; border-radius:8px;"><i class="fas fa-sticky-note"></i> <strong>Notes:</strong> ${escapeHtml(item.condition_notes)}</div>` : ''}
                <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="btn-verify" onclick="manageItem(${item.id}, '${escapeHtml(item.item_name)}', '${item.status}', '${escapeHtml(item.condition_notes || '')}')" style="background:#17a2b8; padding:6px 12px; font-size:12px;"><i class="fas fa-edit"></i> Manage Status</button>
                    <button class="btn-verify" onclick="showEquipmentSchedule(${item.id}, '${escapeHtml(item.item_name)}')" style="background:#28a745; padding:6px 12px; font-size:12px;"><i class="fas fa-calendar-alt"></i> View Schedule</button>
                </div>
                <div style="margin-top:8px; font-size:0.7rem; color:#999;">
                    <i class="fas fa-calendar"></i> Added: ${new Date(item.created_at).toLocaleDateString()}
                    ${item.updated_at !== item.created_at ? `<br><i class="fas fa-history"></i> Last updated: ${new Date(item.updated_at).toLocaleDateString()}` : ''}
                </div>
            </div>`).join('')}
    </div>`;
}

function filterEquipment(category) {
    currentEquipmentFilter = category === 'all' ? 'all' : category;
    loadEquipmentList();
}

// ============ SCHEDULE ============
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
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment_bookings&id=${equipmentId}`);
        const data = await response.json();
        if (data.success) modalBody.innerHTML = renderScheduleCalendar(equipmentId, equipmentName, data.bookings);
        else modalBody.innerHTML = `<div class="empty-state-mini"><p>Failed to load schedule</p></div>`;
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading schedule</p></div>`;
    }
}

function renderScheduleCalendar(equipmentId, equipmentName, bookings) {
    const calendarHtml = generateCalendarHtml(currentCalendarYear, currentCalendarMonth, bookings);
    const bookingsListHtml = generateBookingsListHtml(bookings);
    return `<div class="schedule-container">
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
        <div class="calendar-wrapper">${calendarHtml}</div>
        <div class="bookings-list-wrapper"><h4><i class="fas fa-list"></i> All Bookings</h4>${bookingsListHtml}</div>
    </div>`;
}

function generateCalendarHtml(year, month, bookings) {
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const startingDayOfWeek = firstDayOfMonth.getDay();
    const daysInMonth = lastDayOfMonth.getDate();
    const bookingsByDate = {};
    bookings.forEach(booking => {
        const bookingDate = new Date(booking.booking_date);
        const dateKey = `${bookingDate.getFullYear()}-${bookingDate.getMonth() + 1}-${bookingDate.getDate()}`;
        if (!bookingsByDate[dateKey]) bookingsByDate[dateKey] = [];
        bookingsByDate[dateKey].push(booking);
    });
    let calendarHtml = `<div class="calendar-header">
        <button class="calendar-nav" onclick="changeCalendarMonth(${year}, ${month}, -1)"><i class="fas fa-chevron-left"></i></button>
        <h3>${monthNames[month]} ${year}</h3>
        <button class="calendar-nav" onclick="changeCalendarMonth(${year}, ${month}, 1)"><i class="fas fa-chevron-right"></i></button>
    </div>
    <div class="calendar-weekdays"><div class="weekday">Sun</div><div class="weekday">Mon</div><div class="weekday">Tue</div><div class="weekday">Wed</div><div class="weekday">Thu</div><div class="weekday">Fri</div><div class="weekday">Sat</div></div>
    <div class="calendar-days">`;
    for (let i = 0; i < startingDayOfWeek; i++) calendarHtml += `<div class="calendar-day empty"></div>`;
    for (let day = 1; day <= daysInMonth; day++) {
        const dateKey = `${year}-${month + 1}-${day}`;
        const dayBookings = bookingsByDate[dateKey] || [];
        if (dayBookings.length > 0) {
            let statusClass = '';
            if (dayBookings.some(b => b.status === 'pending'))      statusClass = 'has-pending';
            else if (dayBookings.some(b => b.status === 'borrowed'))statusClass = 'has-borrowed';
            else if (dayBookings.some(b => b.status === 'approved'))statusClass = 'has-approved';
            else if (dayBookings.some(b => b.status === 'returned'))statusClass = 'has-returned';
            const tooltipContent = dayBookings.map(b => `${b.status === 'pending' ? '⏳' : (b.status === 'approved' ? '✓' : (b.status === 'borrowed' ? '📋' : (b.status === 'returned' ? '↺' : '✗')))} ${escapeHtml(b.first_name)} ${escapeHtml(b.last_name)} - ${b.status}`).join('<br>');
            calendarHtml += `<div class="calendar-day ${statusClass}" data-tooltip="${escapeHtml(tooltipContent)}" onclick="viewDayBookings(${year}, ${month}, ${day})">
                <span class="day-number">${day}</span>
                <div class="booking-indicators">${dayBookings.slice(0,3).map(() => '<i class="fas fa-calendar-check"></i>').join('')}</div>
                ${dayBookings.length > 3 ? `<small class="more-indicator">+${dayBookings.length - 3}</small>` : ''}
            </div>`;
        } else {
            calendarHtml += `<div class="calendar-day"><span class="day-number">${day}</span></div>`;
        }
    }
    calendarHtml += `</div>`;
    return calendarHtml;
}

function generateBookingsListHtml(bookings) {
    if (!bookings || bookings.length === 0) return `<div class="empty-state-mini"><p>No bookings found for this equipment.</p></div>`;
    const formatDate = (dateString) => { if (!dateString) return '-'; return new Date(dateString).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); };
    const getStatusClass = (status) => ({ pending:'badge-unverified', approved:'badge-verified', borrowed:'badge-borrowed', returned:'badge-returned', rejected:'status-unverified' }[status] || 'badge-unverified');
    const getStatusIcon = (status) => ({ pending:'<i class="fas fa-clock"></i>', approved:'<i class="fas fa-check-circle"></i>', borrowed:'<i class="fas fa-hand-holding"></i>', returned:'<i class="fas fa-undo-alt"></i>', rejected:'<i class="fas fa-times-circle"></i>' }[status] || '<i class="fas fa-question-circle"></i>');
    const sorted = [...bookings].sort((a, b) => new Date(b.booking_date) - new Date(a.booking_date));
    return `<div class="bookings-list">${sorted.map(booking => `
        <div class="booking-item" onclick="viewBookingDetails(${booking.id})">
            <div class="booking-item-header">
                <div class="booking-resident"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div>
                <div class="booking-status ${getStatusClass(booking.status)}">${getStatusIcon(booking.status)} ${booking.status}</div>
            </div>
            <div class="booking-item-details">
                <div><i class="fas fa-calendar"></i> ${formatDate(booking.booking_date)}</div>
                <div><i class="fas fa-hourglass-half"></i> ${booking.duration_days || 1} day(s)</div>
                ${booking.purpose ? `<div><i class="fas fa-comment"></i> ${escapeHtml(booking.purpose.substring(0, 100))}</div>` : ''}
            </div>
        </div>`).join('')}</div>`;
}

async function changeCalendarMonth(year, month, delta) {
    let newMonth = month + delta, newYear = year, newMonthIndex = newMonth;
    if (newMonth < 0) { newYear = year - 1; newMonthIndex = 11; }
    else if (newMonth > 11) { newYear = year + 1; newMonthIndex = 0; }
    currentCalendarYear = newYear;
    currentCalendarMonth = newMonthIndex;
    if (currentScheduleEquipmentId) {
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment_bookings&id=${currentScheduleEquipmentId}`);
        const data = await response.json();
        if (data.success) {
            const modalBody = document.getElementById('scheduleModalBody');
            if (modalBody) modalBody.innerHTML = renderScheduleCalendar(currentScheduleEquipmentId, currentScheduleEquipmentName, data.bookings);
        }
    }
}

async function viewDayBookings(year, month, day) {
    const selectedDate = new Date(year, month, day);
    const formattedDate = selectedDate.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const allBookings = await fetchEquipmentBookings(currentScheduleEquipmentId);
    const dayBookings = allBookings.filter(b => {
        const bd = new Date(b.booking_date);
        return bd.getFullYear() === year && bd.getMonth() === month && bd.getDate() === day;
    });
    if (dayBookings.length === 0) {
        if (typeof showInfoModal === 'function') showInfoModal(`No bookings on ${formattedDate}`, 'No Bookings');
        else alert(`No bookings on ${formattedDate}`);
        return;
    }
    showDayBookingsModal(formattedDate, dayBookings);
}

async function fetchEquipmentBookings(equipmentId) {
    const response = await fetch(`equipment_role_ajax.php?action=get_equipment_bookings&id=${equipmentId}`);
    const data = await response.json();
    return data.success ? data.bookings : [];
}

function showDayBookingsModal(date, bookings) {
    const modal = document.getElementById('dayBookingsModal');
    const modalTitle = document.getElementById('dayBookingsModalTitle');
    const modalBody = document.getElementById('dayBookingsModalBody');
    if (!modal || !modalTitle || !modalBody) return;
    modalTitle.innerHTML = `<i class="fas fa-calendar-day"></i> Bookings for ${date}`;
    const formatDateTime = (dateString) => new Date(dateString).toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
    const getStatusClass = (status) => ({ pending:'badge-unverified', approved:'badge-verified', borrowed:'badge-borrowed', returned:'badge-returned', rejected:'status-unverified' }[status] || 'badge-unverified');
    modalBody.innerHTML = `<div class="day-bookings-list">${bookings.map(booking => `
        <div class="day-booking-item" onclick="viewBookingDetails(${booking.id}); closeDayBookingsModal();">
            <div class="day-booking-time"><i class="fas fa-clock"></i> ${formatDateTime(booking.booking_date)}</div>
            <div class="day-booking-info">
                <div class="day-booking-resident"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div>
                <div class="day-booking-status ${getStatusClass(booking.status)}">${booking.status}</div>
            </div>
            <div class="day-booking-purpose"><i class="fas fa-comment"></i> ${escapeHtml(booking.purpose || 'No purpose specified')}</div>
            <div class="day-booking-duration"><i class="fas fa-hourglass-half"></i> Duration: ${booking.duration_days || 1} day(s)</div>
        </div>`).join('')}</div>`;
    modal.style.display = 'block';
}

function closeDayBookingsModal() { const m = document.getElementById('dayBookingsModal'); if (m) m.style.display = 'none'; }
function closeScheduleModal() {
    const modal = document.getElementById('scheduleModal');
    if (modal) modal.style.display = 'none';
    currentScheduleEquipmentId = null;
    currentScheduleEquipmentName = '';
    currentCalendarYear = new Date().getFullYear();
    currentCalendarMonth = new Date().getMonth();
}

// ============ BOOKINGS ============
async function loadBookings(status = 'pending', page = 1) {
    currentBookingFilter = status;
    currentBookingPage = page;
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bookings...</p></div></div>`;
    try {
        const typeFilter = currentBookingTypeFilter || 'all';
        const response = await fetch(`equipment_role_ajax.php?action=get_bookings&status=${status}&page=${page}&type=${typeFilter}`);
        const data = await response.json();
        if (data.success) dashboardBody.innerHTML = renderBookingsList(data.data);
        else dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}

// ============ BOOKINGS CALENDAR VIEW FUNCTIONS ============
let currentBookingView = 'cards';
let currentBookingCalendarYear = new Date().getFullYear();
let currentBookingCalendarMonth = new Date().getMonth();

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

    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner">
        <i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading calendar...</p></div></div>`;

    try {
        const typeFilter = currentBookingTypeFilter || 'all';
        const response = await fetch(
            `equipment_role_ajax.php?action=get_all_bookings&status=${currentBookingFilter}&type=${typeFilter}`
        );
        const data = await response.json();

        if (data.success) {
            dashboardBody.innerHTML = renderBookingsCalendar(data.data.bookings, data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i><p>Failed to load calendar</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
            <i class="fas fa-exclamation-circle"></i><p>Error loading calendar</p></div></div>`;
    }
}
function renderBookingsCalendar(bookings, metadata) {
    const year = currentBookingCalendarYear;
    const month = currentBookingCalendarMonth;
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const daysInMonth = lastDayOfMonth.getDate();
    const startingDayOfWeek = firstDayOfMonth.getDay();

    const daysInMonthArray = [];
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        daysInMonthArray.push({
            day: i, date,
            dayOfWeek: date.getDay(),
            isToday: date.toDateString() === new Date().toDateString()
        });
    }

    // ═══════════════════════════════════════════════════════════
    // SEPARATE: Vehicles (grouped) vs Equipment/Facility (bars)
    // ═══════════════════════════════════════════════════════════
    const vehicleBookings = bookings.filter(b => b.request_type === 'vehicle');
    const otherBookings   = bookings.filter(b => b.request_type !== 'vehicle');

    // ── Non-vehicle span bars (existing logic) ──
    const processedBookings = [];
    otherBookings.forEach(booking => {
        const startDate = new Date(booking.start_datetime || booking.booking_date);
        const endDate   = new Date(booking.end_datetime   || booking.return_date);
        let startDay = startDate.getDate(), endDay = endDate.getDate();
        let startMonth = startDate.getMonth(), endMonth = endDate.getMonth();
        let startYear = startDate.getFullYear(), endYear = endDate.getFullYear();

        if (startYear < year || (startYear === year && startMonth < month)) startDay = 1;
        if (endYear > year   || (endYear   === year && endMonth   > month)) endDay   = daysInMonth;

        const overlaps = (startYear === year && startMonth === month) ||
                         (endYear === year && endMonth === month) ||
                         (startYear < year && endYear > year) ||
                         (startYear === year && startMonth < month && endYear > year) ||
                         (startYear < year && endYear === year && endMonth > month) ||
                         (startMonth < month && endMonth > month);

        if (overlaps) {
            let span = (startDay === endDay) ? 1 : endDay - startDay + 1;
            if (startDay + span - 1 > daysInMonth) span = daysInMonth - startDay + 1;
            processedBookings.push({ ...booking, startDay, endDay, span, startDate, endDate });
        }
    });

    processedBookings.sort((a, b) => {
        if (a.span !== b.span) return b.span - a.span;
        return a.startDay - b.startDay;
    });

    // ═══════════════════════════════════════════════════════════
    // VEHICLE GROUPING – one entry per (vehicle, day)
    // ═══════════════════════════════════════════════════════════
    const vehicleGroupsByDay = {};

    vehicleBookings.forEach(booking => {
        const customData = (() => {
            try { return booking.custom_data ? JSON.parse(booking.custom_data) : {}; }
            catch(e) { return {}; }
        })();

        const tripDateRaw = customData.trip_date || booking.trip_date || booking.start_datetime;
        const tripDate = new Date(tripDateRaw);
        if (isNaN(tripDate.getTime())) return;

        if (tripDate.getFullYear() !== year || tripDate.getMonth() !== month) return;

        const dayNum = tripDate.getDate();
        const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
        const vehicleKey = booking.equipment_name || booking.item_global_id || 'Vehicle';

        if (!vehicleGroupsByDay[dateKey]) vehicleGroupsByDay[dateKey] = {};
        if (!vehicleGroupsByDay[dateKey][vehicleKey]) vehicleGroupsByDay[dateKey][vehicleKey] = [];
        vehicleGroupsByDay[dateKey][vehicleKey].push({ ...booking, __customData: customData, __tripDate: tripDate });
    });

    // Sort trips within each vehicle by pickup time
    Object.values(vehicleGroupsByDay).forEach(vehicleMap => {
        Object.values(vehicleMap).forEach(arr => {
            arr.sort((a, b) => {
                const ta = a.__customData.pickup_time || '00:00';
                const tb = b.__customData.pickup_time || '00:00';
                return ta.localeCompare(tb);
            });
        });
    });

    const usedRows = [];
    const MAX_ROWS = 6;
    processedBookings.forEach(booking => {
        let rowIndex = 0, placed = false;
        while (!placed && rowIndex < MAX_ROWS) {
            let hasConflict = false;
            for (let existing of usedRows) {
                if (existing.row === rowIndex && !(existing.endDay < booking.startDay || existing.startDay > booking.endDay)) {
                    hasConflict = true; break;
                }
            }
            if (!hasConflict) {
                booking.row = rowIndex;
                usedRows.push({ row: rowIndex, startDay: booking.startDay, endDay: booking.endDay, id: booking.id });
                placed = true;
            }
            rowIndex++;
        }
        if (!placed) booking.row = MAX_ROWS - 1;
    });

    const maxRow = Math.max(...processedBookings.map(b => b.row), -1) + 1;
    const totalRows = Math.min(maxRow, MAX_ROWS);

    let calendarHtml = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Calendar</div>
            <div class="section-sub">Manage equipment, facility, and vehicle booking requests</div>

            <div class="filter-bar filter-bar-stacked">

                <!-- ROW 1: TYPE (top) -->
                ${bookingTypeChipsCalendarHtml()}

                <!-- ROW 2: STATUS + Actions (bottom) -->
                <div class="filter-row filter-row-status">
                    <span class="filter-row-label"><i class="fas fa-list"></i> Status:</span>
                    <button class="filter-chip ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="loadCalendarWithFilters('all')">All</button>
                    <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadCalendarWithFilters('pending')">Pending</button>
                    <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadCalendarWithFilters('approved')">Approved</button>
                    <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadCalendarWithFilters('borrowed')">Borrowed</button>
                                               <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadCalendarWithFilters('returned')">Returned</button>
                            <button class="filter-chip ${currentBookingFilter === 'unreturned' ? 'active' : ''}" onclick="loadCalendarWithFilters('unreturned')">
                                <i class="fas fa-hourglass-half"></i> Unreturned
                            </button>
                            <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadCalendarWithFilters('rejected')">Rejected</button>

                    <div class="filter-row-actions">
                        <button class="btn-verify" onclick="toggleBookingView()" style="background:#17a2b8;">
                            <i class="fas fa-list"></i> Card View
                        </button>
                        <button class="btn-verify" onclick="refreshBookingsList()" style="background:#28a745;">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

            <div class="schedule-legend" style="margin: 15px 0; display: flex; gap: 15px; flex-wrap: wrap; justify-content: center;">
                <span class="legend-badge pending"><i class="fas fa-clock"></i> Pending</span>
                <span class="legend-badge approved"><i class="fas fa-check-circle"></i> Approved</span>
                <span class="legend-badge borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>
                <span class="legend-badge returned"><i class="fas fa-undo-alt"></i> Returned</span>
                <span class="legend-badge rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                <span class="legend-badge"><i class="fas fa-arrows-alt-h"></i> Duration bar</span>
            </div>
            <div class="calendar-header">
                <button class="calendar-nav" onclick="changeBookingCalendarMonth(${year}, ${month}, -1)"><i class="fas fa-chevron-left"></i></button>
                <h3>${monthNames[month]} ${year}</h3>
                <button class="calendar-nav" onclick="changeBookingCalendarMonth(${year}, ${month}, 1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="booking-calendar">
                <table class="calendar-table">
                    <thead><tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr></thead>
                    <tbody>
    `;

    let currentDay = 1, cellCount = 0, rowCount = 0;

    for (let i = 0; i < startingDayOfWeek; i++) {
        calendarHtml += `<td style="background:#fafbfa;">` + (cellCount + 1 === 7 ? '</tr><tr>' : '') + `</td>`;
        cellCount++;
    }

    while (currentDay <= daysInMonth) {
        if (cellCount % 7 === 0) {
            if (rowCount > 0) calendarHtml += `</tr>`;
            calendarHtml += `<tr style="vertical-align: top;">`;
            rowCount++;
        }

        const dayData = daysInMonthArray[currentDay - 1];
        const todayClass = dayData.isToday ? 'today' : '';
        const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(currentDay).padStart(2, '0')}`;

        const bookingsForDay = processedBookings.filter(b => b.startDay <= currentDay && b.endDay >= currentDay);
        let barsHtml = '';

        for (let row = 0; row < totalRows; row++) {
            const bookingAtRow = bookingsForDay.find(b => b.row === row);

            if (bookingAtRow && bookingAtRow.startDay === currentDay) {
                const getTypeIcon = (type) => type === 'equipment'
                    ? '<i class="fas fa-tools"></i>'
                    : (type === 'facility' ? '<i class="fas fa-building"></i>'
                    : (type === 'vehicle' ? '<i class="fas fa-truck"></i>' : '<i class="fas fa-question-circle"></i>'));

                const formatShortDate = (date) => `${date.getMonth() + 1}/${date.getDate()}`;
                const startDateStr = formatShortDate(bookingAtRow.startDate);
                const endDateStr = formatShortDate(bookingAtRow.endDate);
                const durationText = bookingAtRow.span > 1 ? ` (${startDateStr}-${endDateStr})` : '';

                let widthPercentage;
                if (bookingAtRow.startDay === bookingAtRow.endDay) widthPercentage = 100;
                else {
                    const isLastDayOfBooking = currentDay === bookingAtRow.endDay || (bookingAtRow.endDay > daysInMonth && currentDay === daysInMonth);
                    if (isLastDayOfBooking) widthPercentage = 100;
                    else {
                        const remainingDays = Math.min(bookingAtRow.endDay, daysInMonth) - bookingAtRow.startDay;
                        widthPercentage = (remainingDays + 1) * 100;
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
                    </div>`;
            } else if (bookingAtRow) {
                const remainingDays = bookingAtRow.endDay - currentDay;
                const widthPercentage = (remainingDays + 1) * 100;
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
                    </div>`;
            } else {
                barsHtml += `<div class="booking-row" style="height: 28px;"></div>`;
            }
        }

        // ═══════════════════════════════════════════════════════════
        // VEHICLE BARS – one bar per vehicle on this day
        // ═══════════════════════════════════════════════════════════
        const dayVehicles = vehicleGroupsByDay[dateKey] || {};
        let vehicleBarsHtml = '';

        Object.keys(dayVehicles).forEach(vehicleName => {
            const trips = dayVehicles[vehicleName];
            const tripCount = trips.length;

            const statusRank = { pending: 1, approved: 2, borrowed: 3, returned: 4, rejected: 5, cancelled: 6 };
            const dominant = trips.reduce((acc, t) => {
                return (statusRank[t.status] || 99) < (statusRank[acc] || 99) ? t.status : acc;
            }, trips[0].status);

            const statusIcon = {
                pending:   'fa-clock',
                approved:  'fa-check-circle',
                borrowed:  'fa-hand-holding',
                returned:  'fa-undo-alt',
                rejected:  'fa-times-circle',
                cancelled: 'fa-ban'
            }[dominant] || 'fa-truck';

            const tripTimes = trips
                .map(t => t.__customData.pickup_time || '--:--')
                .join(', ');

            vehicleBarsHtml += `
                <div class="booking-row vehicle-row">
                    <div class="booking-bar vehicle-bar ${dominant}"
                         style="width: 100%; left: 0; cursor: pointer;"
                         title="${tripCount} trip(s): ${escapeHtml(tripTimes)}"
                         onclick="event.stopPropagation(); showVehicleTripsForDay('${dateKey}', '${escapeHtml(vehicleName).replace(/'/g, "\\'")}');">
                        <i class="fas fa-truck"></i>
                        <span class="booking-name">${escapeHtml(vehicleName)}</span>
                        <span class="vehicle-trip-count">${tripCount} trip${tripCount > 1 ? 's' : ''}</span>
                        <i class="fas ${statusIcon} vehicle-status-icon"></i>
                    </div>
                </div>`;
        });

        calendarHtml += `
            <td>
                <div class="day-cell ${todayClass}" data-date="${dateKey}">
                    <span class="day-number">${currentDay}</span>
                    ${barsHtml}
                    ${vehicleBarsHtml}
                </div>
            </td>`;

        currentDay++;
        cellCount++;
    }

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
        <div class="calendar-summary" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
            <h4 style="margin-bottom: 10px;"><i class="fas fa-chart-pie"></i> Summary</h4>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div><i class="fas fa-calendar-day"></i> Total Bookings: <strong>${bookings.length}</strong></div>
                <div><i class="fas fa-clock" style="color: #ffc107;"></i> Pending: <strong>${bookings.filter(b => b.status === 'pending').length}</strong></div>
                <div><i class="fas fa-check-circle" style="color: #28a745;"></i> Approved: <strong>${bookings.filter(b => b.status === 'approved').length}</strong></div>
                <div><i class="fas fa-hand-holding" style="color: #17a2b8;"></i> Borrowed: <strong>${bookings.filter(b => b.status === 'borrowed').length}</strong></div>
                <div><i class="fas fa-undo-alt" style="color: #6c757d;"></i> Returned: <strong>${bookings.filter(b => b.status === 'returned').length}</strong></div>
                <div><i class="fas fa-times-circle" style="color: #dc3545;"></i> Rejected: <strong>${bookings.filter(b => b.status === 'rejected').length}</strong></div>
                <div><i class="fas fa-truck" style="color: #1b5e20;"></i> Vehicle Trips: <strong>${vehicleBookings.length}</strong></div>
            </div>
        </div>
    </div>`;

    return calendarHtml;
}
/**
 * Captain — show all trips for a specific vehicle on a specific day.
 * Clicking a trip opens the full booking details modal.
 */
function showVehicleTripsForDay(dateKey, vehicleName) {
    const modal = document.getElementById('dayBookingsModal');
    const modalTitle = document.getElementById('dayBookingsModalTitle');
    const modalBody = document.getElementById('dayBookingsModalBody');
    if (!modal || !modalTitle || !modalBody) return;

    const typeFilter = currentBookingTypeFilter || 'all';

    fetch(`equipment_role_ajax.php?action=get_all_bookings&status=${currentBookingFilter}&type=${typeFilter}&date=${dateKey}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data.bookings) {
                modalBody.innerHTML = `<div class="empty-state-mini"><p>No trips found.</p></div>`;
                modalTitle.innerHTML = `<i class="fas fa-truck"></i> ${escapeHtml(vehicleName)}`;
                modal.style.display = 'block';
                return;
            }

            const trips = data.data.bookings.filter(b => {
                if (b.request_type !== 'vehicle') return false;
                if ((b.equipment_name || '') !== vehicleName) return false;

                let cd = {};
                try { cd = b.custom_data ? JSON.parse(b.custom_data) : {}; } catch(e) {}
                const tripRaw = cd.trip_date || b.trip_date || b.start_datetime;
                const td = new Date(tripRaw);
                if (isNaN(td.getTime())) return false;

                const key = `${td.getFullYear()}-${String(td.getMonth() + 1).padStart(2, '0')}-${String(td.getDate()).padStart(2, '0')}`;
                return key === dateKey;
            });

            const selectedDate = new Date(dateKey + 'T00:00:00');
            const formattedDate = selectedDate.toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });

            modalTitle.innerHTML = `<i class="fas fa-truck"></i> ${escapeHtml(vehicleName)} — ${formattedDate}`;

            if (trips.length === 0) {
                modalBody.innerHTML = `<div class="empty-state-mini"><p>No trips scheduled for this vehicle on this day.</p></div>`;
                modal.style.display = 'block';
                return;
            }

            trips.sort((a, b) => {
                const ta = (a.custom_data ? JSON.parse(a.custom_data).pickup_time : '') || '00:00';
                const tb = (b.custom_data ? JSON.parse(b.custom_data).pickup_time : '') || '00:00';
                return ta.localeCompare(tb);
            });

            const getStatusClass = (s) => ({
                pending:'badge-unverified', approved:'badge-verified',
                borrowed:'badge-borrowed', returned:'badge-returned',
                rejected:'status-unverified', cancelled:'badge-cancelled'
            }[s] || 'badge-unverified');

            const getStatusIcon = (s) => ({
                pending:'<i class="fas fa-clock"></i>',
                approved:'<i class="fas fa-check-circle"></i>',
                borrowed:'<i class="fas fa-hand-holding"></i>',
                returned:'<i class="fas fa-undo-alt"></i>',
                rejected:'<i class="fas fa-times-circle"></i>',
                cancelled:'<i class="fas fa-ban"></i>'
            }[s] || '<i class="fas fa-question-circle"></i>');

            const tripsHtml = trips.map((t, idx) => {
                let cd = {};
                try { cd = t.custom_data ? JSON.parse(t.custom_data) : {}; } catch(e) {}

                const pickupTime = cd.pickup_time || '--:--';
                const windowEnd  = cd.pickup_window_end || '';
                const dropoff    = cd.dropoff_location || '-';
                const pickup     = cd.pickup_location || '-';
                const pax        = cd.passenger_count || 1;
                const hours      = cd.estimated_hours || '-';

                return `
                    <div class="day-booking-item vehicle-trip-item"
                         onclick="closeDayBookingsModal(); viewBookingDetails(${t.id});"
                         style="background:#f8faf8; border-radius:12px; padding:14px;
                                margin-bottom:10px; cursor:pointer; border-left:4px solid #1b5e20;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="background:#1b5e20; color:#fff; width:32px; height:32px;
                                            border-radius:50%; display:flex; align-items:center;
                                            justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                                    ${idx + 1}
                                </div>
                                <div>
                                    <strong style="font-size:0.95rem;">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}</strong>
                                    <div style="font-size:0.75rem; color:#666; margin-top:2px;">
                                        <i class="fas fa-clock"></i> Pickup: <strong>${pickupTime}</strong>
                                        ${windowEnd ? ` <span style="color:#999;">(until ${windowEnd})</span>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="${getStatusClass(t.status)}"
                                 style="padding:4px 12px; border-radius:40px; font-size:11px; white-space:nowrap;">
                                ${getStatusIcon(t.status)} ${t.status}
                            </div>
                        </div>
                        <div style="margin-top:10px; font-size:0.78rem; color:#555;
                                    display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                            <div><i class="fas fa-map-marker-alt" style="color:#2E7D32;"></i>
                                 <strong>From:</strong> ${escapeHtml(pickup)}</div>
                            <div><i class="fas fa-flag-checkered" style="color:#c62828;"></i>
                                 <strong>To:</strong> ${escapeHtml(dropoff)}</div>
                            <div><i class="fas fa-users" style="color:#1565c0;"></i>
                                 <strong>Passengers:</strong> ${pax}</div>
                            <div><i class="fas fa-hourglass-half" style="color:#f57c00;"></i>
                                 <strong>Est. Hours:</strong> ${hours}</div>
                        </div>
                        ${t.purpose ? `
                            <div style="margin-top:8px; font-size:0.78rem; color:#444;
                                        background:#eef5f0; padding:6px 10px; border-radius:6px;">
                                <i class="fas fa-comment"></i> ${escapeHtml(t.purpose.substring(0, 120))}${t.purpose.length > 120 ? '...' : ''}
                            </div>` : ''}
                        <div style="margin-top:8px; font-size:0.72rem; color:#2E7D32;
                                    font-weight:600; text-align:right;">
                            <i class="fas fa-info-circle"></i> Click to view full details
                        </div>
                    </div>`;
            }).join('');

            modalBody.innerHTML = `
                <div style="margin-bottom:12px; padding:10px 14px; background:#e8f5e9;
                            border-left:4px solid #1b5e20; border-radius:8px;
                            font-size:0.85rem; color:#1b5e20;">
                    <i class="fas fa-truck"></i>
                    <strong>${trips.length}</strong> trip${trips.length > 1 ? 's' : ''} scheduled
                    for <strong>${escapeHtml(vehicleName)}</strong> on this day.
                </div>
                <div class="day-bookings-list" style="max-height:60vh; overflow-y:auto;">
                    ${tripsHtml}
                </div>`;

            modal.style.display = 'block';
        })
        .catch(err => {
            console.error('Error loading vehicle trips:', err);
            modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading trips.</p></div>`;
            modalTitle.innerHTML = `<i class="fas fa-truck"></i> ${escapeHtml(vehicleName)}`;
            modal.style.display = 'block';
        });
}

async function loadCalendarWithFilters(status) {
    currentBookingFilter = status;
    currentBookingView   = 'calendar';
    const sel = document.getElementById('calendarTypeFilterSelect');
    if (sel) currentBookingTypeFilter = sel.value || 'all';

    await loadBookingsCalendar();   // server-side filter
}

async function loadBookingsCalendarWithUnreturned() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner">
        <i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading unreturned bookings...</p></div></div>`;

    try {
        const typeFilter = document.getElementById('calendarTypeFilterSelect')
            ? document.getElementById('calendarTypeFilterSelect').value
            : (currentBookingTypeFilter || 'all');

        const response = await fetch(
            `equipment_role_ajax.php?action=get_all_bookings&status=all&type=${typeFilter}`
        );
        const data = await response.json();

        if (data.success) {
            const raw = (data.data && data.data.bookings) ? data.data.bookings : [];
            const unreturned = raw.filter(isUnreturnedBooking);
            dashboardBody.innerHTML = renderBookingsCalendar(unreturned, data.data);
        } else {
            dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i><p>Failed to load unreturned bookings</p></div></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
            <i class="fas fa-exclamation-circle"></i><p>Error loading unreturned bookings</p></div></div>`;
    }
}

function filterCalendarByType(type) {
    currentBookingTypeFilter = type || 'all';
    loadCalendarWithFilters(currentBookingFilter);
}

async function changeBookingCalendarMonth(year, month, delta) {
    let newMonth = month + delta, newYear = year, newMonthIndex = newMonth;
    if (newMonth < 0) { newYear = year - 1; newMonthIndex = 11; }
    else if (newMonth > 11) { newYear = year + 1; newMonthIndex = 0; }
    currentBookingCalendarYear = newYear;
    currentBookingCalendarMonth = newMonthIndex;
    await loadBookingsCalendar();
}

async function showDayBookingsModalForBooking(dateKey, hasBookings) {
    if (!hasBookings) return;
    const selectedDate = new Date(dateKey);
    const formattedDate = selectedDate.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const typeFilter = currentBookingTypeFilter || 'all';

    const response = await fetch(`equipment_role_ajax.php?action=get_all_bookings&status=${currentBookingFilter}&type=${typeFilter}&date=${dateKey}`);
    const data = await response.json();

    if (data.success && data.data.bookings) {
        const dayBookings = data.data.bookings.filter(booking => {
            const bookingDate = new Date(booking.start_datetime || booking.booking_date);
            const key = `${bookingDate.getFullYear()}-${String(bookingDate.getMonth() + 1).padStart(2, '0')}-${String(bookingDate.getDate()).padStart(2, '0')}`;
            return key === dateKey;
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

    const formatFullDate = (d) => new Date(d).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const getStatusClass = (s) => ({ pending:'badge-unverified', approved:'badge-verified', borrowed:'badge-borrowed', returned:'badge-returned', rejected:'status-unverified' }[s] || 'badge-unverified');
    const getTypeIcon   = (t) => t === 'equipment' ? '<i class="fas fa-tools"></i>' : (t === 'facility' ? '<i class="fas fa-building"></i>' : (t === 'vehicle' ? '<i class="fas fa-truck"></i>' : '<i class="fas fa-question-circle"></i>'));
    const getStatusIcon = (s) => ({ pending:'<i class="fas fa-clock"></i>', approved:'<i class="fas fa-check-circle"></i>', borrowed:'<i class="fas fa-hand-holding"></i>', returned:'<i class="fas fa-undo-alt"></i>', rejected:'<i class="fas fa-times-circle"></i>' }[s] || '<i class="fas fa-question-circle"></i>');

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
                if (isStartDate && isEndDate && durationDays === 1) positionLabel = '<span class="duration-badge" style="background:#28a745;">Single Day</span>';
                else if (isStartDate) positionLabel = `<span class="duration-badge" style="background:#ff9800;">Start (${durationDays} days)</span>`;
                else if (isEndDate)   positionLabel = `<span class="duration-badge" style="background:#2196f3;">End Date</span>`;
                else                  positionLabel = `<span class="duration-badge" style="background:#6c757d;">During Booking</span>`;

                return `
                    <div class="day-booking-item" onclick="viewBookingDetails(${booking.id}); closeDayBookingsModal();"
                         style="background: #f8faf8; border-radius: 12px; padding: 12px; margin-bottom: 10px; cursor: pointer;">
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
                    </div>`;
            }).join('')}
        </div>`;
    modal.style.display = 'block';
}

function refreshBookingsList() {
    if (currentBookingView === 'calendar') {
        loadBookingsCalendar();
    } else {
        loadFilteredBookings(currentBookingFilter, currentBookingPage);
    }
}

async function loadFilteredBookings(status, page) {
    currentBookingFilter = status;
    currentBookingPage   = page;
    const typeFilter = currentBookingTypeFilter || 'all';

    if (currentBookingView === 'calendar') {
        await loadBookingsCalendar();
        return;
    }

    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;

    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner">
        <i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading bookings...</p></div></div>`;

    try {
        const response = await fetch(
            `equipment_role_ajax.php?action=get_bookings&status=${status}&page=${page}&type=${typeFilter}`
        );
        const data = await response.json();
        if (data.success) dashboardBody.innerHTML = renderBookingsList(data.data);
        else dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
            <i class="fas fa-exclamation-triangle"></i><p>Failed to load bookings</p></div></div>`;
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state">
            <i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div></div>`;
    }
}

function filterBookingByType(type) {
    currentBookingTypeFilter = type || 'all';
    currentBookingView = 'cards';
    loadFilteredBookings(currentBookingFilter, 1);
}

function renderBookingsList(bookingsData) {
    const bookings = bookingsData.bookings;
    const totalPages = bookingsData.totalPages;
    const currentPage = bookingsData.currentPage;
    const formatDate = (dateString) => { if (!dateString) return '-'; return new Date(dateString).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); };
    const getStatusBadge = (status) => ({ pending:'<span class="badge-unverified"><i class="fas fa-clock"></i> Pending</span>', approved:'<span class="badge-verified"><i class="fas fa-check-circle"></i> Approved</span>', borrowed:'<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>', returned:'<span class="badge-returned"><i class="fas fa-undo-alt"></i> Returned</span>', rejected:'<span class="status-unverified"><i class="fas fa-times-circle"></i> Rejected</span>' }[status] || '<span class="badge-unverified">' + status + '</span>');
    const getTypeBadge = (type) => ({ equipment:'<span class="type-badge equipment"><i class="fas fa-tools"></i> Equipment</span>', facility:'<span class="type-badge facility"><i class="fas fa-building"></i> Facility</span>', vehicle:'<span class="type-badge vehicle"><i class="fas fa-truck"></i> Vehicle</span>' }[type] || '<span class="type-badge">' + type + '</span>');

    // ═══════════════════════════════════════════════
    // STACKED FILTER BAR — Type on TOP, Status BELOW
    // ═══════════════════════════════════════════════
    const filterBarHtml = `
        <div class="filter-bar filter-bar-stacked">
            <div class="filter-row filter-row-type">
                <span class="filter-row-label"><i class="fas fa-filter"></i> Type:</span>
                <button class="filter-chip ${currentBookingTypeFilter === 'all' ? 'active' : ''}" onclick="filterBookingByType('all')"><i class="fas fa-layer-group"></i> All Types</button>
                <button class="filter-chip ${currentBookingTypeFilter === 'equipment' ? 'active' : ''}" onclick="filterBookingByType('equipment')"><i class="fas fa-tools"></i> Equipment</button>
                <button class="filter-chip ${currentBookingTypeFilter === 'facility' ? 'active' : ''}" onclick="filterBookingByType('facility')"><i class="fas fa-building"></i> Facilities</button>
                <button class="filter-chip ${currentBookingTypeFilter === 'vehicle' ? 'active' : ''}" onclick="filterBookingByType('vehicle')"><i class="fas fa-truck"></i> Vehicles</button>
            </div>
            <div class="filter-row filter-row-status">
                <span class="filter-row-label"><i class="fas fa-list"></i> Status:</span>
                <button class="filter-chip ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="loadFilteredBookings('all', 1)">All</button>
                <button class="filter-chip ${currentBookingFilter === 'pending' ? 'active' : ''}" onclick="loadFilteredBookings('pending', 1)">Pending</button>
                <button class="filter-chip ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="loadFilteredBookings('approved', 1)">Approved</button>
                <button class="filter-chip ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="loadFilteredBookings('borrowed', 1)">Borrowed</button>
                 <button class="filter-chip ${currentBookingFilter === 'returned' ? 'active' : ''}" onclick="loadFilteredBookings('returned', 1)">Returned</button>
                <button class="filter-chip ${currentBookingFilter === 'unreturned' ? 'active' : ''}" onclick="loadFilteredBookings('unreturned', 1)">
                    <i class="fas fa-hourglass-half"></i> Unreturned
                </button>
                <button class="filter-chip ${currentBookingFilter === 'rejected' ? 'active' : ''}" onclick="loadFilteredBookings('rejected', 1)">Rejected</button>
                <div class="filter-row-actions">
                    <button class="btn-verify" onclick="toggleBookingView()" style="background:#17a2b8;"><i class="fas fa-calendar-alt"></i> Calendar View</button>
                    <button class="btn-verify" onclick="refreshBookingsList()" style="background:#28a745;"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
            </div>
        </div>
    `;

    if (!bookings || bookings.length === 0) {
        return `<div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
            <div class="section-sub">Manage equipment, facility, and vehicle booking requests from residents</div>
            ${filterBarHtml}
            <div class="empty-state"><i class="fas fa-calendar-times fa-3x"></i><p>No ${currentBookingFilter} booking requests found.</p></div>
        </div>`;
    }

    const cardsHtml = bookings.map(booking => {
        let customData = {};
        if (booking.custom_data) { try { customData = JSON.parse(booking.custom_data); } catch(e) {} }
        let displayDate = formatDate(booking.start_datetime || booking.booking_date);
        if (booking.request_type === 'facility' && customData.start_time) displayDate = `${formatDate(booking.start_datetime)} (${customData.start_time} - ${customData.end_time})`;
        if (booking.request_type === 'vehicle' && customData.trip_date) displayDate = formatDate(customData.trip_date);
        let typeInfo = '';
        if (booking.request_type === 'equipment') typeInfo = `<div class="info-row-card"><div class="info-icon"><i class="fas fa-cubes"></i></div><div class="info-label-card">Quantity:</div><div class="info-value-card">${customData.quantity || booking.quantity || 1} item(s)</div></div>`;
        else if (booking.request_type === 'facility') typeInfo = `<div class="info-row-card"><div class="info-icon"><i class="fas fa-clock"></i></div><div class="info-label-card">Time:</div><div class="info-value-card">${customData.start_time || '--:--'} - ${customData.end_time || '--:--'}</div></div><div class="info-row-card"><div class="info-icon"><i class="fas fa-users"></i></div><div class="info-label-card">Attendees:</div><div class="info-value-card">${customData.expected_attendees || '-'}</div></div>`;
        else if (booking.request_type === 'vehicle') typeInfo = `<div class="info-row-card"><div class="info-icon"><i class="fas fa-map-marker-alt"></i></div><div class="info-label-card">Pickup:</div><div class="info-value-card">${escapeHtml((customData.pickup_location || '-').substring(0, 50))}</div></div><div class="info-row-card"><div class="info-icon"><i class="fas fa-users"></i></div><div class="info-label-card">Passengers:</div><div class="info-value-card">${customData.passenger_count || 1}</div></div>`;
        return `<div class="data-card" onclick="viewBookingDetails(${booking.id})" data-id="${booking.id}">
            <div class="card-header-gradient ${booking.status === 'pending' ? 'pending' : (booking.status === 'approved' ? 'approved' : 'completed')}">
                <div class="card-title-large">${getTypeBadge(booking.request_type)}<span>${escapeHtml(booking.equipment_name)}</span></div>
                <div class="status-chip">${getStatusBadge(booking.status)}</div>
            </div>
            <div class="card-content">
                <div class="info-section">
                    <div class="info-row-card"><div class="info-icon"><i class="fas fa-user"></i></div><div class="info-label-card">Resident:</div><div class="info-value-card"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div></div>
                    <div class="info-row-card"><div class="info-icon"><i class="fas fa-phone"></i></div><div class="info-label-card">Phone:</div><div class="info-value-card">${escapeHtml(booking.phone || '-')}</div></div>
                    <div class="info-row-card"><div class="info-icon"><i class="fas fa-calendar"></i></div><div class="info-label-card">${booking.request_type === 'vehicle' ? 'Trip Date:' : 'Booking Date:'}</div><div class="info-value-card">${displayDate}</div></div>
                    ${typeInfo}
                    <div class="info-row-card"><div class="info-icon"><i class="fas fa-hourglass-half"></i></div><div class="info-label-card">Duration:</div><div class="info-value-card">${booking.duration_days || 1} day(s)</div></div>
                    ${booking.purpose ? `<div class="info-row-card"><div class="info-icon"><i class="fas fa-comment"></i></div><div class="info-label-card">Purpose:</div><div class="info-value-card">${escapeHtml(booking.purpose.substring(0, 100))}${booking.purpose.length > 100 ? '...' : ''}</div></div>` : ''}
                </div>
            </div>
            <div class="click-hint"><i class="fas fa-mouse-pointer"></i> Click to view details and take action</div>
        </div>`;
    }).join('');

    let paginationHtml = '';
    if (totalPages > 1) {
        paginationHtml = '<div class="pagination-wrapper"><div class="pagination">';
        if (currentPage > 1) paginationHtml += `<button class="page-btn" onclick="loadFilteredBookings('${currentBookingFilter}', ${currentPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
        for (let i = 1; i <= totalPages; i++) paginationHtml += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="loadFilteredBookings('${currentBookingFilter}', ${i})">${i}</button>`;
        if (currentPage < totalPages) paginationHtml += `<button class="page-btn" onclick="loadFilteredBookings('${currentBookingFilter}', ${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
        paginationHtml += '</div></div>';
    }

    return `<div class="content-card">
        <div class="section-title"><i class="fas fa-calendar-alt"></i> Booking Requests</div>
        <div class="section-sub">Manage equipment, facility, and vehicle booking requests from residents</div>
        ${filterBarHtml}
        <div class="cards-grid">${cardsHtml}</div>
        ${paginationHtml}
    </div>`;
}

function refreshBookingsList() { loadFilteredBookings(currentBookingFilter, currentBookingPage); }

async function viewBookingDetails(id) {
    const modal = document.getElementById('bookingModal');
    const modalBody = document.getElementById('bookingModalBody');
    if (!modal || !modalBody) return;
    modal.style.display = 'block';
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading booking details...</p></div>`;
    try {
        const response = await fetch(`equipment_role_ajax.php?action=get_booking_by_id&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const booking = data.data;
            const formatDate = (dateString) => { if (!dateString) return '-'; return new Date(dateString).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' }); };
            let customData = {};
            if (booking.custom_data) { try { customData = JSON.parse(booking.custom_data); } catch(e) {} }

            let typeSpecificHtml = '';
            if (booking.request_type === 'equipment') {
                typeSpecificHtml = `<div class="detail-section"><h4><i class="fas fa-cubes"></i> Equipment Details</h4><div class="detail-row"><div class="detail-label">Quantity:</div><div class="detail-value">${booking.quantity || 1} item(s)</div></div><div class="detail-row"><div class="detail-label">Expected Return:</div><div class="detail-value">${formatDate(booking.end_datetime)}</div></div></div>`;
            } else if (booking.request_type === 'facility') {
                typeSpecificHtml = `<div class="detail-section"><h4><i class="fas fa-building"></i> Facility Details</h4><div class="detail-row"><div class="detail-label">Start Time:</div><div class="detail-value">${customData.start_time || formatDate(booking.start_datetime)}</div></div><div class="detail-row"><div class="detail-label">End Time:</div><div class="detail-value">${customData.end_time || formatDate(booking.end_datetime)}</div></div><div class="detail-row"><div class="detail-label">Duration (Hours):</div><div class="detail-value">${customData.duration_hours || '-'}</div></div><div class="detail-row"><div class="detail-label">Event Type:</div><div class="detail-value">${escapeHtml(customData.event_type || '-')}</div></div><div class="detail-row"><div class="detail-label">Expected Attendees:</div><div class="detail-value">${customData.expected_attendees || '-'}</div></div></div>`;
            } else if (booking.request_type === 'vehicle') {
                const tripDate    = customData.trip_date || booking.trip_date || booking.start_datetime;
                const pickupTime  = customData.pickup_time || '--:--';
                const windowEnd   = customData.pickup_window_end || '';
                const estHours    = customData.estimated_hours || '-';
                const paxCount    = customData.passenger_count || 1;
                const pickupLoc   = customData.pickup_location || '-';
                const dropoffLoc  = customData.dropoff_location || '-';
                const vehicleCap  = customData.vehicle_capacity || booking.vehicle_capacity || '-';
                const specialReq  = customData.special_requests || booking.special_requests || '';
                const isEmergency = customData.is_emergency || booking.is_emergency || 0;
                const slotType    = customData.slot_type || booking.slot_type || 'open';

                typeSpecificHtml = `
                    <div class="detail-section">
                        <h4><i class="fas fa-truck"></i> Vehicle Trip Details</h4>
                        ${isEmergency ? `
                            <div style="background:#ffebee; border-left:4px solid #c62828;
                                        padding:8px 12px; border-radius:8px; margin-bottom:10px;
                                        color:#b71c1c; font-weight:700; font-size:0.85rem;">
                                <i class="fas fa-exclamation-triangle"></i> EMERGENCY TRIP
                            </div>` : ''}
                        <div class="detail-row"><div class="detail-label">Vehicle:</div><div class="detail-value"><strong>${escapeHtml(booking.equipment_name)}</strong></div></div>
                        <div class="detail-row"><div class="detail-label">Vehicle Capacity:</div><div class="detail-value">${vehicleCap} persons</div></div>
                        <div class="detail-row"><div class="detail-label">Trip Date:</div><div class="detail-value"><strong>${formatDate(tripDate)}</strong></div></div>
                        <div class="detail-row"><div class="detail-label">Pickup Time:</div><div class="detail-value"><strong>${pickupTime}</strong>${windowEnd ? `<span style="color:#666; font-size:0.85rem;"> (window until ${windowEnd})</span>` : ''}</div></div>
                        <div class="detail-row"><div class="detail-label">Estimated Duration:</div><div class="detail-value">${estHours} hour(s)</div></div>
                        <div class="detail-row"><div class="detail-label">Passenger Count:</div><div class="detail-value">${paxCount} person(s)</div></div>
                        <div class="detail-row"><div class="detail-label">Pickup Location:</div><div class="detail-value">${escapeHtml(pickupLoc)}</div></div>
                        <div class="detail-row"><div class="detail-label">Dropoff Location:</div><div class="detail-value">${escapeHtml(dropoffLoc)}</div></div>
                        <div class="detail-row"><div class="detail-label">Slot Type:</div><div class="detail-value" style="text-transform:capitalize;">${escapeHtml(slotType)}</div></div>
                        ${specialReq ? `<div class="detail-row"><div class="detail-label">Special Requests:</div><div class="detail-value">${escapeHtml(specialReq)}</div></div>` : ''}
                    </div>`;
            }

            const getStatusActions = () => {
                if (booking.status === 'pending')   return `<div class="modal-action-buttons"><button class="btn-verify" onclick="showBookingActionModal(${booking.id}, 'approved')"><i class="fas fa-check-circle"></i> Approve</button><button class="btn-close" onclick="showBookingActionModal(${booking.id}, 'rejected')" style="background:#dc3545;"><i class="fas fa-times-circle"></i> Reject</button></div>`;
                if (booking.status === 'approved')  return `<div class="modal-action-buttons"><button class="btn-verify" onclick="showBookingActionModal(${booking.id}, 'borrowed')" style="background:#17a2b8;"><i class="fas fa-hand-holding"></i> Mark as Borrowed</button></div>`;
                if (booking.status === 'borrowed')  return `<div class="modal-action-buttons"><button class="btn-verify" onclick="showReturnModal(${booking.id})" style="background:#28a745;"><i class="fas fa-undo-alt"></i> Mark as Returned</button></div>`;
                return '';
            };
            const getTypeIcon = () => booking.request_type === 'equipment' ? '<i class="fas fa-tools"></i>' : (booking.request_type === 'facility' ? '<i class="fas fa-building"></i>' : (booking.request_type === 'vehicle' ? '<i class="fas fa-truck"></i>' : '<i class="fas fa-question-circle"></i>'));

            modalBody.innerHTML = `
                <div class="detail-section">
                    <h4><i class="fas fa-user-circle"></i> Resident Information</h4>
                    <div class="detail-row"><div class="detail-label">Full Name:</div><div class="detail-value"><strong>${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Email:</div><div class="detail-value">${escapeHtml(booking.email)}</div></div>
                    <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(booking.phone || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Address:</div><div class="detail-value">${escapeHtml(booking.address || '-')}</div></div>
                </div>
                <div class="detail-section">
                    <h4>${getTypeIcon()} Booking Information</h4>
                    <div class="detail-row"><div class="detail-label">Request Type:</div><div class="detail-value"><strong>${escapeHtml(booking.request_type).toUpperCase()}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Item/Facility Name:</div><div class="detail-value"><strong>${escapeHtml(booking.equipment_name)}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Global ID:</div><div class="detail-value">${escapeHtml(booking.item_global_id || '-')}</div></div>
                </div>
                ${typeSpecificHtml}
                <div class="detail-section">
                    <h4><i class="fas fa-info-circle"></i> Request Information</h4>
                    <div class="detail-row"><div class="detail-label">Purpose:</div><div class="detail-value">${escapeHtml(booking.purpose || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Request Date:</div><div class="detail-value">${formatDate(booking.request_date)}</div></div>
                    <div class="detail-row"><div class="detail-label">Start Date/Time:</div><div class="detail-value">${formatDate(booking.start_datetime)}</div></div>
                    <div class="detail-row"><div class="detail-label">End Date/Time:</div><div class="detail-value">${formatDate(booking.end_datetime)}</div></div>
                    <div class="detail-row"><div class="detail-label">Status:</div><div class="detail-value">${getStatusBadgeHtml(booking.status)}</div></div>
                    ${booking.admin_notes ? `<div class="detail-row"><div class="detail-label">Admin Notes:</div><div class="detail-value"><strong>${escapeHtml(booking.admin_notes)}</strong></div></div>` : ''}
                </div>
                ${getStatusActions()}`;
        } else {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>${escapeHtml(data.message)}</p></div>`;
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading details</p></div>`;
    }
}

function getStatusBadgeHtml(status) {
    return { pending:'<span class="badge-unverified"><i class="fas fa-clock"></i> Pending</span>', approved:'<span class="badge-verified"><i class="fas fa-check-circle"></i> Approved</span>', borrowed:'<span class="badge-borrowed"><i class="fas fa-hand-holding"></i> Borrowed</span>', returned:'<span class="badge-returned"><i class="fas fa-undo-alt"></i> Returned</span>', rejected:'<span class="status-unverified"><i class="fas fa-times-circle"></i> Rejected</span>' }[status] || '<span class="badge-unverified">' + status + '</span>';
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
    if (statusDiv) { statusDiv.style.display = 'block'; statusDiv.className = 'email-status'; statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }
    const formData = new FormData();
    formData.append('action', 'update_booking_status');
    formData.append('id', pendingBookingActionId);
    formData.append('status', pendingBookingAction);
    formData.append('admin_notes', notes);
    try {
        const response = await fetch('equipment_role_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            if (statusDiv) { statusDiv.className = 'email-status success'; statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message; }
            setTimeout(() => {
                closeBookingApprovalModal();
                if (typeof showSuccessModal === 'function') showSuccessModal(data.message, 'Success'); else alert(data.message);
                loadBookings(currentBookingFilter, currentBookingPage);
                closeBookingModal();
            }, 1500);
        } else {
            if (statusDiv) { statusDiv.className = 'email-status error'; statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to process'); }
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm';
        }
    } catch (error) {
        console.error('Error:', error);
        if (statusDiv) { statusDiv.className = 'email-status error'; statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred'; }
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm';
    }
}

function showReturnModal(id) {
    pendingBookingActionId = id;
    const modal = document.getElementById('returnModal');
    const notesField = document.getElementById('returnNotes');
    if (modal) { modal.style.display = 'block'; if (notesField) notesField.value = ''; }
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
        const response = await fetch('equipment_role_ajax.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            if (modal) modal.style.display = 'none';
            if (typeof showSuccessModal === 'function') showSuccessModal('Item marked as returned!', 'Success'); else alert('Item marked as returned!');
            loadBookings(currentBookingFilter, currentBookingPage);
            closeBookingModal();
        } else {
            if (typeof showErrorModal === 'function') showErrorModal(data.message || 'Failed to process return', 'Error'); else alert(data.message || 'Failed to process return');
        }
    } catch (error) {
        console.error('Error:', error);
        if (typeof showErrorModal === 'function') showErrorModal('An error occurred', 'Error'); else alert('An error occurred');
    }
}

// ============ DASHBOARD ============
async function loadEquipmentDashboard() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading dashboard...</p></div></div>`;
    try {
        const response = await fetch('equipment_role_ajax.php?action=get_counts');
        const data = await response.json();
        if (data.success) dashboardBody.innerHTML = renderEquipmentDashboard(data.counts, data.pending_bookings);
        else dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load dashboard</p></div></div>`;
    } catch (error) {
        console.error('Error:', error);
        dashboardBody.innerHTML = `<div class="content-card"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading dashboard</p></div></div>`;
    }
}

function renderEquipmentDashboard(counts, pendingBookings) {
    return `<div class="content-card">
        <div class="section-title"><i class="fas fa-chart-pie"></i> Equipment & Facilities Dashboard</div>
        <div class="section-sub">Overview of barangay resources and bookings</div>
        <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px;">
            <div class="stat-card" style="text-align:center;"><i class="fas fa-tools fa-2x" style="color:#1a472a;"></i><h3>${counts.total}</h3><p>Total Items</p></div>
            <div class="stat-card" style="text-align:center;"><i class="fas fa-check-circle fa-2x" style="color:#43e97b;"></i><h3>${counts.available}</h3><p>Available Items</p></div>
            <div class="stat-card" style="text-align:center;"><i class="fas fa-wrench fa-2x" style="color:#f4b942;"></i><h3>${counts.maintenance}</h3><p>Under Maintenance</p></div>
            <div class="stat-card" style="text-align:center;"><i class="fas fa-clock fa-2x" style="color:#17a2b8;"></i><h3>${pendingBookings}</h3><p>Pending Bookings</p></div>
        </div>
        <div style="margin-top:20px;"><h4 style="color:#1a472a;margin-bottom:15px;">Categories</h4>
            <div style="display:flex;gap:15px;flex-wrap:wrap;">
                ${counts.categories && counts.categories.length > 0 ? counts.categories.map(cat => `<div style="background:#e9f9ef;padding:10px 20px;border-radius:20px;"><strong>${escapeHtml(cat.category)}</strong>: ${cat.count} items</div>`).join('') : '<p>No categories found</p>'}
            </div>
        </div>
        <div class="action-buttons-group" style="margin-top:30px;display:flex;gap:15px;justify-content:center;flex-wrap:wrap;">
            <button class="btn-verify" onclick="loadEquipmentList()"><i class="fas fa-list"></i> View Equipment</button>
            <button class="btn-verify" onclick="loadBookings('pending',1)" style="background:#17a2b8;"><i class="fas fa-calendar-alt"></i> View Bookings</button>
        </div>
    </div>`;
}

// ============ MODAL CLOSE ============
function closeEquipmentModal()       { const m = document.getElementById('equipmentModal');        if (m) m.style.display = 'none'; }
function closeBookingModal()         { const m = document.getElementById('bookingModal');          if (m) m.style.display = 'none'; }
function closeBookingApprovalModal() { const m = document.getElementById('bookingApprovalModal'); if (m) m.style.display = 'none'; }
function closeReturnModal()          { const m = document.getElementById('returnModal');           if (m) m.style.display = 'none'; }
function closeEquipmentItemsModal() {
    const m = document.getElementById('equipmentItemsModal');
    if (m) m.style.display = 'none';
    currentEquipmentTypeId = null;
    currentEquipmentTypeName = '';
}

// ============ HELPERS ============
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showConfirmationModal(message, title, onConfirm, onCancel, type = 'warning') {
    const existingModal = document.querySelector('.modal-confirm');
    if (existingModal) existingModal.remove();
    const modal = document.createElement('div');
    modal.className = 'modal-confirm';
    let icon = '<i class="fas fa-exclamation-triangle"></i>';
    let confirmBtnClass = 'btn-confirm';
    switch(type) {
        case 'danger':  icon = '<i class="fas fa-exclamation-circle"></i>'; confirmBtnClass = 'btn-confirm'; break;
        case 'warning': icon = '<i class="fas fa-exclamation-triangle"></i>'; confirmBtnClass = 'btn-confirm warning'; break;
        case 'success': icon = '<i class="fas fa-check-circle"></i>'; confirmBtnClass = 'btn-confirm success'; break;
        case 'info':    icon = '<i class="fas fa-info-circle"></i>'; confirmBtnClass = 'btn-confirm info'; break;
        default:        icon = '<i class="fas fa-question-circle"></i>'; confirmBtnClass = 'btn-confirm warning';
    }
    modal.innerHTML = `
        <div class="modal-confirm-content">
            <div class="modal-confirm-header ${type}">${icon}<h3>${escapeHtml(title)}</h3></div>
            <div class="modal-confirm-body"><p>${escapeHtml(message)}</p></div>
            <div class="modal-confirm-footer">
                <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                <button class="${confirmBtnClass}" onclick="confirmAction()">Confirm</button>
            </div>
        </div>`;
    document.body.appendChild(modal);
    window._confirmCallback = onConfirm || null;
    window._cancelCallback  = onCancel  || null;
    const handleEsc = (e) => { if (e.key === 'Escape') { closeConfirmationModal(); document.removeEventListener('keydown', handleEsc); } };
    document.addEventListener('keydown', handleEsc);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeConfirmationModal(); });
}

function closeConfirmationModal() {
    const modal = document.querySelector('.modal-confirm');
    if (modal) modal.remove();
    if (window._cancelCallback) { window._cancelCallback(); window._cancelCallback = null; }
    window._confirmCallback = null;
}
function confirmAction() {
    if (window._confirmCallback) { window._confirmCallback(); window._confirmCallback = null; }
    closeConfirmationModal();
}

// ============ EQUIPMENT DETAILS MODAL (card click) ============
async function showEquipmentDetails(typeId, typeName) {
    const modal = document.getElementById('equipmentItemsModal');
    const modalTitle = document.getElementById('equipmentItemsModalTitle');
    const modalBody = document.getElementById('equipmentItemsModalBody');
    if (!modal || !modalTitle || !modalBody) return;

    currentEquipmentTypeId = typeId;
    currentEquipmentTypeName = typeName;

    modalTitle.innerHTML = `<i class="fas fa-info-circle"></i> Equipment Details`;
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>`;
    modal.style.display = 'block';

    try {
        const response = await fetch(`equipment_role_ajax.php?action=get_equipment&category=all`);
        const data = await response.json();

        if (!data.success) throw new Error(data.message || 'Failed');

        const item = (data.data || []).find(x => Number(x.id) === Number(typeId));
        if (!item) {
            modalBody.innerHTML = `<div class="empty-state-mini"><p>Equipment not found.</p></div>`;
            return;
        }

        const imagePath = item.image ? '../../' + item.image : null;
        let defaultIcon = 'fa-tools';
        if (item.category === 'Facility') defaultIcon = 'fa-building';
        else if (item.category === 'Vehicle') defaultIcon = 'fa-truck';

        const availableCount   = parseInt(item.available_count)   || 0;
        const borrowedCount    = parseInt(item.borrowed_count)    || 0;
        const maintenanceCount = parseInt(item.maintenance_count) || 0;
        const lostCount        = parseInt(item.lost_count)        || 0;
        const totalItems       = parseInt(item.total_items)       || 0;

        // HIDE "Borrowed" for Facility & Vehicle only
        const showBorrowed = (item.category === 'Equipment');

        const denom = showBorrowed
            ? totalItems
            : (availableCount + maintenanceCount + lostCount);

        const pctAvailable   = denom > 0 ? Math.round((availableCount   / denom) * 100) : 0;
        const pctBorrowed    = denom > 0 ? Math.round((borrowedCount    / denom) * 100) : 0;
        const pctMaintenance = denom > 0 ? Math.round((maintenanceCount / denom) * 100) : 0;
        const pctLost        = denom > 0 ? Math.round((lostCount        / denom) * 100) : 0;

        const createdDate = item.created_at ? new Date(item.created_at).toLocaleDateString('en-US', {dateStyle:'medium'}) : '—';
        const updatedDate = item.updated_at ? new Date(item.updated_at).toLocaleDateString('en-US', {dateStyle:'medium'}) : '—';

        modalBody.innerHTML = `
        <div class="eq-mgmt-wrap">
            <div class="eq-mgmt-hero">
                <div class="eq-mgmt-hero-image">
                    ${imagePath
                        ? `<img src="${imagePath}" alt="${escapeHtml(item.name)}" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\\'eq-mgmt-hero-fallback\\'><i class=\\'fas ${defaultIcon}\\'></i></div>';">`
                        : `<div class="eq-mgmt-hero-fallback"><i class="fas ${defaultIcon}"></i></div>`}
                </div>
                <div class="eq-mgmt-hero-info">
                    <span class="eq-cat-badge">${escapeHtml(item.category)}</span>
                    <h3 class="eq-mgmt-hero-title">${escapeHtml(item.name)}</h3>
                    ${item.description ? `<p class="eq-hero-desc">${escapeHtml(item.description)}</p>` : '<p class="eq-hero-desc muted">No description provided.</p>'}
                    <div class="eq-mgmt-hero-total"><i class="fas fa-boxes"></i> Total Items: <strong>${totalItems}</strong></div>
                </div>
            </div>

            <div class="eq-mgmt-section">
                <div class="eq-mgmt-section-title"><i class="fas fa-chart-pie"></i> Status Distribution</div>
                <div class="eq-mgmt-status-bar">
                    ${availableCount > 0   ? `<div class="eq-mgmt-bar-seg available"   style="width:${pctAvailable}%"   title="Available: ${availableCount}"></div>` : ''}
                    ${(showBorrowed && borrowedCount > 0)
                                            ? `<div class="eq-mgmt-bar-seg borrowed"    style="width:${pctBorrowed}%"    title="Borrowed: ${borrowedCount}"></div>` : ''}
                    ${maintenanceCount > 0 ? `<div class="eq-mgmt-bar-seg maintenance" style="width:${pctMaintenance}%" title="Maintenance: ${maintenanceCount}"></div>` : ''}
                    ${lostCount > 0        ? `<div class="eq-mgmt-bar-seg lost"        style="width:${pctLost}%"        title="Lost: ${lostCount}"></div>` : ''}
                </div>
                <div class="eq-mgmt-stats-grid ${showBorrowed ? '' : 'eq-mgmt-stats-grid-3'}">
                    <div class="eq-mgmt-stat available">
                        <i class="fas fa-check-circle"></i>
                        <div class="eq-mgmt-stat-num">${availableCount}</div>
                        <div class="eq-mgmt-stat-lbl">Available</div>
                        <div class="eq-mgmt-stat-pct">${pctAvailable}%</div>
                    </div>
                    ${showBorrowed ? `
                    <div class="eq-mgmt-stat borrowed">
                        <i class="fas fa-hand-holding"></i>
                        <div class="eq-mgmt-stat-num">${borrowedCount}</div>
                        <div class="eq-mgmt-stat-lbl">Borrowed</div>
                        <div class="eq-mgmt-stat-pct">${pctBorrowed}%</div>
                    </div>` : ''}
                    <div class="eq-mgmt-stat maintenance">
                        <i class="fas fa-wrench"></i>
                        <div class="eq-mgmt-stat-num">${maintenanceCount}</div>
                        <div class="eq-mgmt-stat-lbl">Maintenance</div>
                        <div class="eq-mgmt-stat-pct">${pctMaintenance}%</div>
                    </div>
                    <div class="eq-mgmt-stat lost">
                        <i class="fas fa-question-circle"></i>
                        <div class="eq-mgmt-stat-num">${lostCount}</div>
                        <div class="eq-mgmt-stat-lbl">Lost / Damaged</div>
                        <div class="eq-mgmt-stat-pct">${pctLost}%</div>
                    </div>
                </div>
            </div>

            <div class="eq-mgmt-section">
                <div class="eq-mgmt-section-title"><i class="fas fa-info-circle"></i> Record Information</div>
                <div class="eq-mgmt-meta-grid">
                    <div class="eq-mgmt-meta-item"><label>Category</label><span>${escapeHtml(item.category)}</span></div>
                    <div class="eq-mgmt-meta-item"><label>Total Quantity</label><span>${totalItems} item(s)</span></div>
                    <div class="eq-mgmt-meta-item"><label>Created</label><span>${createdDate}</span></div>
                    <div class="eq-mgmt-meta-item"><label>Last Updated</label><span>${updatedDate}</span></div>
                </div>
            </div>

            <div class="eq-mgmt-footer">
                <button type="button" class="eq-btn ghost" onclick="closeEquipmentItemsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="eq-btn primary" onclick="showEquipmentItems(${typeId}, '${escapeHtml(typeName).replace(/'/g, "\\'")}')">
                    <i class="fas fa-list"></i> View Items
                </button>
            </div>
        </div>`;
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `<div class="empty-state-mini"><p>Error loading details</p></div>`;
    }
}

// ============ SIDEBAR INIT (Secretary) ============
function initSecretaryEquipmentSidebar() {
    const equipmentParent  = document.getElementById('equipmentParent');
    const equipmentSubmenu = document.getElementById('equipmentSubmenu');
    let equipmentExpanded = false;

    function toggleEquipmentSubmenu(expand) {
        if (expand === undefined) equipmentExpanded = !equipmentExpanded;
        else equipmentExpanded = expand;
        if (equipmentExpanded) {
            if (equipmentSubmenu) equipmentSubmenu.classList.add('open');
            if (equipmentParent) { const icon = equipmentParent.querySelector('.toggle-icon'); if (icon) icon.style.transform = 'rotate(180deg)'; }
        } else {
            if (equipmentSubmenu) equipmentSubmenu.classList.remove('open');
            if (equipmentParent) { const icon = equipmentParent.querySelector('.toggle-icon'); if (icon) icon.style.transform = 'rotate(0deg)'; }
        }
    }

    if (equipmentParent) {
        toggleEquipmentSubmenu(false);
        equipmentParent.addEventListener('click', (e) => { e.stopPropagation(); toggleEquipmentSubmenu(); });
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
            if (view === 'equipment_list')           await loadEquipmentList();
            else if (view === 'equipment_bookings')  await loadBookings('approved', 1);
            else if (view === 'equipment_dashboard') await loadEquipmentDashboard();
            if (!equipmentExpanded) toggleEquipmentSubmenu(true);
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileDropdown) profileDropdown.classList.remove('show');
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSecretaryEquipmentSidebar);
else initSecretaryEquipmentSidebar();