// resident_equipment.js - Updated for new database structure with separate tables
// Cancel/pending functionality removed - all bookings are auto-approved

let currentEquipmentList = [];
let currentEquipmentTypes = [];
let currentBookingsList = [];
let currentSelectedEquipment = null;
let currentBookingFilter = 'all';

// ── Bookings list controls ───────────────────────────────────
let bookingSearch        = '';
let bookingDateFrom      = null;   // 'YYYY-MM-DD' or null
let bookingDateTo        = null;   // 'YYYY-MM-DD' or null
let bookingPage          = 1;
const BOOKING_PAGE_SIZE  = 5;      // change to whatever you prefer
const BOOKING_DEFAULT_DAYS = 30;   // default date window

// Filter collapse state for the two lists
window.__equipFilterOpen    = window.__equipFilterOpen    !== false; // default open
window.__bookingFilterOpen  = window.__bookingFilterOpen  !== false; // default open


// "YYYY-MM-DD" for a Date in local time
function _ymd(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// Reset the date window to "today .. today + BOOKING_DEFAULT_DAYS"
function initBookingDateWindow() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const to = new Date(today);
    to.setDate(to.getDate() + BOOKING_DEFAULT_DAYS);

    bookingDateFrom = _ymd(today);
    bookingDateTo   = _ymd(to);
}

// Apply search + date filters to the raw list
function applyBookingFilters(list) {
    const q = (bookingSearch || '').trim().toLowerCase();

    return list.filter(b => {
        // Search by name / reference / purpose
        if (q) {
            const hay = [
                b.vehicle_name, b.facility_name, b.equipment_name,
                b.item_global_id, b.reference, b.purpose
            ].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }

        // Date window (uses trip_date when present, else start_datetime)
        const raw = b.trip_date || b.start_datetime || b.request_date;
        if (!raw) return true;
        const d = new Date(raw);
        if (isNaN(d.getTime())) return true;

        const dStr = _ymd(d);
        if (bookingDateFrom && dStr < bookingDateFrom) return false;
        if (bookingDateTo   && dStr > bookingDateTo)   return false;

        return true;
    });
}

// ============ LOAD EQUIPMENT TYPES ============
async function loadEquipmentList() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;
    
    dashboardBody.innerHTML = `<div class="content-card"><div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading equipment...</div></div>`;
    
    try {
        const response = await fetch('resident_equipment_ajax.php?action=get_all_equipment');
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
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;

    // ── Filter state ───────────────────────────────────────
    // window.__equipFilterOpen is set once at startup; default = open.
    const isOpen = window.__equipFilterOpen !== false;

    const allCategories = ['Equipment', 'Facility'];
    const catIcons = {
        'Equipment': 'fa-tools',
        'Facility':  'fa-building'
        
    };

    const filterLabel = currentEquipmentFilter === 'all'
        ? 'All Categories'
        : currentEquipmentFilter;

    const filterHeader = `
        <div style="margin-bottom:20px;border:1px solid #e2efe8;border-radius:12px;overflow:hidden;background:#fff;">
            <button type="button"
                    onclick="toggleEquipmentFilter()"
                    style="width:100%;display:flex;align-items:center;justify-content:space-between;
                           padding:12px 16px;background:#f8faf8;border:none;cursor:pointer;
                           font-weight:700;color:#1a472a;font-size:0.95rem;">
                <span>
                    <i class="fas fa-filter" style="margin-right:8px;color:#2e7d32;"></i>
                    Filter:
                    <span style="color:#2e7d32;">${escapeHtml(filterLabel)}</span>
                </span>
                <i class="fas fa-chevron-${isOpen ? 'up' : 'down'}" style="color:#2e7d32;"></i>
            </button>

            <div id="equipmentFilterBody"
                 style="display:${isOpen ? 'block' : 'none'};padding:12px 16px;">
                <div class="filter-buttons"
                     style="display:flex;flex-wrap:wrap;gap:8px;">
                    <button class="filter-btn ${currentEquipmentFilter === 'all' ? 'active' : ''}"
                            onclick="filterEquipmentByCategory('all')">
                        <i class="fas fa-layer-group"></i> All
                    </button>
                    ${allCategories.map(cat => `
                        <button class="filter-btn ${currentEquipmentFilter === cat ? 'active' : ''}"
                                onclick="filterEquipmentByCategory('${cat.replace(/'/g, "\\'")}')">
                            <i class="fas ${catIcons[cat]}"></i> ${escapeHtml(cat)}
                        </button>
                    `).join('')}
                </div>
            </div>
        </div>
    `;

    // ── Empty-state branch ─────────────────────────────────
    if (!equipmentTypes || equipmentTypes.length === 0) {
        dashboardBody.innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-tools"></i> Available Equipment & Facilities</div>
                <div class="section-sub">Browse and book barangay resources</div>
                ${filterHeader}
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <p>No items match the current filter.</p>
                </div>
            </div>
        `;
        return;
    }

    // ── Vehicle entry card ────────────────────────────────
    // Shown when "All" or "Vehicle" filter is selected.
    const vehicleCard = (currentEquipmentFilter === 'all' || currentEquipmentFilter === 'Vehicle') ? `
        <div class="equipment-card" style="border:2px solid #2e7d32;" onclick="showVehicleTripForm()">
            <div class="equipment-card-image"
                 style="background:linear-gradient(135deg,#e8f5e9,#c8e6c9);
                        display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-truck" style="font-size:3.5rem;color:#2e7d32;"></i>
            </div>
            <div class="equipment-card-content">
                <div class="equipment-card-title" style="color:#1a472a;">🚐 Request a Barangay Vehicle</div>
                <div class="equipment-card-category">Vehicle Service</div>
                <div class="equipment-card-desc">
                    Tell us about your trip — we'll match you with the best vehicle and time slot.
                </div>
                <div class="equipment-card-footer">
                    <div class="equipment-availability available">
                        <i class="fas fa-info-circle"></i> Fill a short form first
                    </div>
                    <button class="book-equipment-btn"
                            onclick="event.stopPropagation(); showVehicleTripForm()">
                        <i class="fas fa-arrow-right"></i> Start
                    </button>
                </div>
            </div>
        </div>
    ` : '';

    // ── Regular equipment / facility cards ────────────────
    const cardsHtml = equipmentTypes.map(type => {
        let isAvailable = false;
        let availabilityText = '';

        if (type.category === 'Equipment') {
            const availableCount = parseInt(type.available_count) || 0;
            isAvailable = availableCount > 0;
            availabilityText = isAvailable ? `${availableCount} available` : 'Not Available';
        } else {
            isAvailable = type.status === 'available';
            availabilityText = isAvailable ? 'Available' : 'Not Available';
        }

        const imagePath = type.image ? '../' + type.image : null;

        let defaultIcon = 'fa-tools';
        if (type.category === 'Facility') defaultIcon = 'fa-building';
        else if (type.category === 'Vehicle') defaultIcon = 'fa-truck';

        const identifier = type.global_id;

        return `
            <div class="equipment-card"
                 onclick="showBookingForm('${identifier}', '${escapeHtml(type.name)}', '${type.category}', ${type.available_count || 0}, ${type.capacity || 0})">
                <div class="equipment-card-image">
                    ${imagePath
                        ? `<img src="${imagePath}" alt="${escapeHtml(type.name)}"
                                onerror="this.onerror=null;
                                         this.parentElement.innerHTML=
                                         '<div class=\\'image-fallback\\'><i class=\\'fas ${defaultIcon}\\'></i></div>';">
                          </img>`
                        : `<div class="image-fallback"><i class="fas ${defaultIcon}"></i></div>`
                    }
                    <span class="equipment-status-badge ${isAvailable ? 'available' : 'unavailable'}">
                        ${availabilityText}
                    </span>
                </div>
                <div class="equipment-card-content">
                    <div class="equipment-card-title">${escapeHtml(type.name)}</div>
                    <div class="equipment-card-category">${escapeHtml(type.category)}</div>
                    <div class="equipment-card-desc">
                        ${escapeHtml(type.description || 'No description available')}
                    </div>
                    <div class="equipment-card-footer">
                        <div class="equipment-availability ${isAvailable ? 'available' : 'unavailable'}">
                            <i class="fas ${isAvailable ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                            ${availabilityText}
                        </div>
                        <button class="book-equipment-btn"
                                onclick="event.stopPropagation();
                                         showBookingForm('${identifier}', '${escapeHtml(type.name)}', '${type.category}', ${type.available_count || 0}, ${type.capacity || 0})">
                            <i class="fas fa-calendar-plus"></i> Book Now
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    // ── Compose the whole card ────────────────────────────
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-tools"></i> Available Equipment & Facilities</div>
            <div class="section-sub">Browse and book barangay resources for your needs</div>
            ${filterHeader}
            <div class="equipment-grid">
                ${vehicleCard}
                ${cardsHtml}
            </div>
        </div>
    `;
}

let currentEquipmentFilter = 'all';

function toggleEquipmentFilter() {
    window.__equipFilterOpen = (window.__equipFilterOpen === false);
    renderEquipmentTypes(
        currentEquipmentFilter === 'all'
            ? currentEquipmentTypes
            : currentEquipmentTypes.filter(i => i.category === currentEquipmentFilter)
    );
}
window.toggleEquipmentFilter = toggleEquipmentFilter;

function filterEquipmentByCategory(category) {
    currentEquipmentFilter = category;
    
    let filtered = currentEquipmentTypes;
    if (category !== 'all') {
        filtered = currentEquipmentTypes.filter(item => item.category === category);
    }
    
    renderEquipmentTypes(filtered);
}


// ============ SHOW BOOKING FORM ============
function showBookingForm(globalId, name, category, availableCount, capacity) {
    currentSelectedEquipment = { 
        globalId: globalId, 
        name: name, 
        category: category, 
        availableCount: availableCount,
        capacity: capacity 
    };
    
    const dashboardBody = document.getElementById('dashboardBody');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayStr = today.toISOString().split('T')[0];
    const nowStr = new Date().toISOString().slice(0, 16);
    
    let formHtml = '';
    
    if (category === 'Equipment') {
    // Generate time slots from 8:00 AM to 6:00 PM with 30-minute intervals
    let timeOptions = '';
    for (let hour = 8; hour <= 18; hour++) {
        for (let minute = 0; minute < 60; minute += 30) {
            if (hour === 18 && minute > 0) continue;
            const timeStr = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
            const displayTime = formatTimeToAMPM(timeStr);
            timeOptions += `<option value="${timeStr}">${displayTime}</option>`;
        }
    }
    
    // Start with a placeholder quantity dropdown (will be updated dynamically)
    let quantityOptions = '';
    const initialMax = Math.min(availableCount, 10);
    for (let i = 1; i <= initialMax; i++) {
        quantityOptions += `<option value="${i}">${i}</option>`;
    }
    
    formHtml = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Book: ${escapeHtml(name)}</div>
            <div class="section-sub">Select date, pickup time, and duration for borrowing equipment</div>
            
            <div class="booking-info-panel" style="background: #e8f5e9; margin-bottom: 20px; padding: 15px; border-radius: 12px;">
                <i class="fas fa-info-circle" style="color: #2e7d32;"></i>
                <span>Equipment pickup available from <strong>8:00 AM - 6:00 PM</strong> (30-minute intervals). Please select your preferred pickup time.</span>
            </div>
            
            <form id="bookingForm" onsubmit="submitEquipmentBooking(event)">
                <div class="booking-form-group">
                    <label class="required">Select Date <span style="color:red;">*</span></label>
                    <div id="miniCalendar" style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2efe8;"></div>
                    <input type="hidden" id="selectedDate" name="booking_date" required>
                    <small>Click on a date to select it</small>
                </div>
                
                <div class="booking-form-group">
                    <label class="required">Duration (Days) <span style="color:red;">*</span></label>
                    <select id="durationDays" name="duration_days" required>
                        <option value="1">1 Day</option>
                        <option value="2">2 Days</option>
                        <option value="3">3 Days</option>
                        <option value="4">4 Days</option>
                        <option value="5">5 Days</option>
                        <option value="6">6 Days</option>
                        <option value="7">7 Days</option>
                    </select>
                    <small>Number of days you need the equipment</small>
                </div>
                
                <div class="booking-form-group">
                    <label>Quantity <span style="color:red;">*</span></label>
                    <select id="bookingQuantity" name="quantity" required style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #ddd; background: white; font-size: 14px;">
                        ${quantityOptions}
                    </select>
                    <small id="quantityLabel">Number of items to borrow (max ${initialMax} available)</small>
                    <div id="quantityError" style="color:red; font-size:12px; display:none;"></div>
                </div>
                
                <div id="availabilityMessage" style="display:none; margin-top: 10px; padding: 12px; border-radius: 8px; font-size: 13px;"></div>
                
                <div class="booking-form-group">
                    <label class="required">Pickup Time <span style="color:red;">*</span></label>
                    <select id="pickupTime" name="pickup_time" required style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #ddd;">
                        <option value="">Select pickup time</option>
                        ${timeOptions}
                    </select>
                    <small>Select your preferred pickup time (30-minute intervals from 8:00 AM - 6:00 PM)</small>
                </div>
                

                <div class="booking-form-group">
                    <label class="required">Purpose <span style="color:red;">*</span></label>
                    <textarea id="bookingPurpose" name="purpose" rows="3" required placeholder="Please state the purpose of booking..."></textarea>
                </div>
                
                <div class="form-buttons" style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="button" class="btn-secondary" onclick="loadEquipmentList()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button type="submit" class="btn-primary" id="submitBookingBtn">
                        <i class="fas fa-paper-plane"></i> Submit Booking Request
                    </button>
                </div>
            </form>
        </div>
    `;
    
    dashboardBody.innerHTML = formHtml;
    renderMiniCalendar('miniCalendar', todayStr);
    
    // Setup availability checking
    setTimeout(() => {
        const selectedDateInput = document.getElementById('selectedDate');
        const durationSelect = document.getElementById('durationDays');
        const quantitySelectEl = document.getElementById('bookingQuantity');
        
        if (selectedDateInput) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'value') {
                        checkAvailabilityForDate();
                    }
                });
            });
            observer.observe(selectedDateInput, { attributes: true });
        }
        if (durationSelect) durationSelect.addEventListener('change', checkAvailabilityForDate);
        if (quantitySelectEl) quantitySelectEl.addEventListener('change', checkAvailabilityForDate);
        
        // Initial availability check after form loads
        setTimeout(checkAvailabilityForDate, 300);
    }, 500);
} else if (category === 'Facility') {
    formHtml = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> Book: ${escapeHtml(name)}</div>
            <div class="section-sub">Select date, duration, and time for facility booking</div>
            
            <div class="booking-info-panel" style="background: #e3f2fd; margin-bottom: 20px; padding: 15px; border-radius: 12px;">
                <i class="fas fa-info-circle" style="color: #1565c0;"></i>
                <span>Facility operating hours: <strong>6:00 AM - 10:00 PM</strong>. Choose your preferred duration and time slot.</span>
            </div>
            
            <form id="bookingForm" onsubmit="submitFacilityBooking(event)">
                <div class="booking-form-group">
                    <label class="required">Select Date <span style="color:red;">*</span></label>
                    <div id="bookingCalendar" style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2efe8;"></div>
                    <input type="hidden" id="selectedDate" name="booking_date" required>
                    <small>Click on a date to select it</small>
                </div>
                
                <div class="booking-form-group">
                    <label class="required">Duration <span style="color:red;">*</span></label>
                    <select id="durationHours" name="duration_hours" required>
                        <option value="1">1 Hour</option>
                        <option value="2" selected>2 Hours</option>
                        <option value="3">3 Hours</option>
                        <option value="4">4 Hours</option>
                        <option value="5">5 Hours</option>
                        <option value="6">6 Hours</option>
                        <option value="7">7 Hours</option>
                        <option value="8">8 Hours</option>
                    </select>
                    <small>How long do you need the facility?</small>
                </div>
                
                <div class="booking-form-group" id="timeSlotGroup" style="display: none;">
    <label class="required">Select Time Slot <span style="color:red;">*</span></label>
    <div id="timeSlotsContainer" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-top: 10px;">
        <!-- Time slots will be loaded here -->
    </div>
    <div id="noTimeSlotsMsg" style="display: none; background: #fff3cd; padding: 15px; border-radius: 8px; color: #856404; margin-top: 10px;">
        <i class="fas fa-calendar-times"></i> No available time slots for the selected duration. Please choose a different duration or date.
    </div>
</div>
                
                <div class="booking-form-group">
                    <label>Expected Attendees (Optional)</label>
                    <input type="number" id="expectedAttendees" class="form-control" min="1" placeholder="Number of people">
                </div>
                
                <div class="booking-form-group">
                    <label>Event Type (Optional)</label>
                    <input type="text" id="eventType" class="form-control" placeholder="e.g., Wedding, Meeting, Sports">
                </div>
                
                <div class="booking-form-group">
                    <label class="required">Purpose <span style="color:red;">*</span></label>
                    <textarea id="bookingPurpose" name="purpose" rows="3" required placeholder="Please state the purpose of booking..."></textarea>
                </div>
                
                <div class="form-buttons" style="display: flex; gap: 15px; margin-top: 25px;">
                    <button type="button" class="btn-secondary" onclick="loadEquipmentList()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button type="submit" class="btn-primary" id="submitBookingBtn" disabled>
                        <i class="fas fa-paper-plane"></i> Submit Booking Request
                    </button>
                </div>
            </form>
        </div>
    `;
    
    dashboardBody.innerHTML = formHtml;
    renderMiniCalendar('bookingCalendar', todayStr);
    
    // Store selected global ID and current duration
    window.currentFacilityGlobalId = globalId;
    window.currentDurationHours = 2; // Default 2 hours
    
    // Add duration change listener
    const durationSelect = document.getElementById('durationHours');
    if (durationSelect) {
        durationSelect.addEventListener('change', function() {
            window.currentDurationHours = parseInt(this.value);
            const selectedDate = document.getElementById('selectedDate').value;
            if (selectedDate) {
                loadAvailableTimeSlots(selectedDate);
            }
        });
    }
    
    // Override the selectCalendarDate function for this specific calendar
    window.selectCalendarDate = function(contId, dateStr, isPast) {
        if (isPast) return;
        
        const container = document.getElementById(contId);
        if (!container) return;
        
        const days = container.querySelectorAll('.calendar-day');
        days.forEach(day => {
            day.classList.remove('selected');
            day.style.background = '';
            day.style.color = '';
        });
        
        const clickedDay = Array.from(days).find(d => d.dataset.date === dateStr);
        if (clickedDay) {
            clickedDay.classList.add('selected');
            clickedDay.style.background = '#43e97b';
            clickedDay.style.color = 'white';
        }
        
        const selectedDateInput = document.getElementById('selectedDate');
        if (selectedDateInput) {
            selectedDateInput.value = dateStr;
        }
        
        // Load available time slots for the selected date with current duration
        loadAvailableTimeSlots(dateStr);
    };
} else if (category === 'Vehicle') {
    showVehicleTripForm();
}
}

// ============ MINI CALENDAR RENDER FUNCTION ============
function renderMiniCalendar(containerId, minDate = null) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const today = new Date();
    const currentYear = today.getFullYear();
    const currentMonth = today.getMonth();
    const currentDate = today.getDate();
    
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    
    let selectedDate = null;
    
    // Helper function to check if a date is in the past OR today
    function isDisabledDate(year, month, day) {
        const dateToCheck = new Date(year, month, day);
        dateToCheck.setHours(0, 0, 0, 0);
        const todayMidnight = new Date();
        todayMidnight.setHours(0, 0, 0, 0);
        // Vehicle requests may be for today (emergencies); everything else needs a future date
        if (containerId === 'vehicleCalendar') return dateToCheck < todayMidnight;
        return dateToCheck <= todayMidnight;
    }
    
    // Helper function to check if a date is today
    function isToday(year, month, day) {
        return year === currentYear && month === currentMonth && day === currentDate;
    }
    
    function renderCalendar(year, month) {
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startingDay = firstDay.getDay();
        const daysInMonth = lastDay.getDate();
        
        let calendarHtml = `
            <div class="calendar-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <button type="button" class="calendar-nav" onclick="changeCalendarMonth('${containerId}', ${year}, ${month}, -1)" style="background: none; border: none; cursor: pointer; font-size: 18px; color: #2e7d32;">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <h4 style="margin: 0; color: #2e7d32;">${monthNames[month]} ${year}</h4>
                <button type="button" class="calendar-nav" onclick="changeCalendarMonth('${containerId}', ${year}, ${month}, 1)" style="background: none; border: none; cursor: pointer; font-size: 18px; color: #2e7d32;">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="calendar-weekdays" style="display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; margin-bottom: 8px;">
                ${dayNames.map(day => `<div style="font-weight: bold; font-size: 12px; color: #666;">${day}</div>`).join('')}
            </div>
            <div class="calendar-days" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;">
        `;
        
        for (let i = 0; i < startingDay; i++) {
            calendarHtml += `<div style="padding: 8px; text-align: center;"></div>`;
        }
        
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            // Check if date is disabled (past OR today)
            const isDisabled = isDisabledDate(year, month, day);
            const isCurrentDate = isToday(year, month, day);
            
            // Determine styling
            let bgColor = 'white';
            let textColor = '#333';
            let borderStyle = '';
            let cursorStyle = isDisabled ? 'not-allowed' : 'pointer';
            let onclickAction = !isDisabled ? `selectCalendarDate('${containerId}', '${dateStr}', false)` : '';
            
            if (isDisabled) {
                bgColor = '#f5f5f5';
                textColor = '#ccc';
            }
            
            if (isCurrentDate) {
                // Current date - highlight with special styling but still disabled
                bgColor = '#fff8e1';
                borderStyle = 'border: 2px solid #ff9800;';
                textColor = '#e65100';
            }
            
            calendarHtml += `
                <div class="calendar-day ${isCurrentDate ? 'current-day' : ''} ${isDisabled ? 'disabled-day' : ''}" 
                     data-date="${dateStr}"
                     onclick="${onclickAction}"
                     style="padding: 8px; text-align: center; cursor: ${cursorStyle}; border-radius: 8px; 
                            background: ${bgColor};
                            color: ${textColor};
                            ${borderStyle}
                            transition: all 0.2s;
                            position: relative;">
                    ${day}
                    ${isCurrentDate ? '<span style="position: absolute; bottom: 2px; right: 5px; font-size: 8px; color: #ff9800;">●</span>' : ''}
                </div>
            `;
        }
        
        calendarHtml += `</div>`;
        
        // Add legend
        if (containerId === 'bookingCalendar' || containerId === 'vehicleCalendar' || containerId === 'miniCalendar') {
            calendarHtml += `
                <div style="margin-top: 10px; font-size: 11px; color: #666; display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; border-top: 1px solid #eee; padding-top: 10px;">
                    <span><i class="fas fa-calendar-day" style="color: #ff9800;"></i> ${containerId === 'vehicleCalendar' ? 'Today' : 'Today (disabled)'}</span>
                    <span><i class="fas fa-calendar-times" style="color: #ccc;"></i> Past dates (disabled)</span>
                    <span><i class="fas fa-calendar-alt" style="color: #4caf50;"></i> Available dates</span>
                </div>
            `;
        }
        
        container.innerHTML = calendarHtml;
    }
    
    renderCalendar(currentYear, currentMonth);
    
    window.changeCalendarMonth = function(contId, year, month, delta) {
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
        renderCalendar(newYear, newMonthIndex);
    };
    
    window.selectCalendarDate = function(contId, dateStr, isPast) {
        if (isPast) return;
        
        const container = document.getElementById(contId);
        if (!container) return;
        
        const days = container.querySelectorAll('.calendar-day');
        days.forEach(day => {
            day.classList.remove('selected');
            day.style.background = '';
            day.style.color = '';
            // Restore original styling for current day
            if (day.classList.contains('current-day') && !day.classList.contains('selected')) {
                day.style.background = '#fff8e1';
                day.style.color = '#e65100';
                day.style.border = '2px solid #ff9800';
            } else if (day.classList.contains('disabled-day')) {
                day.style.background = '#f5f5f5';
                day.style.color = '#ccc';
            } else {
                day.style.background = 'white';
                day.style.color = '#333';
                day.style.border = '';
            }
        });
        
        const clickedDay = Array.from(days).find(d => d.dataset.date === dateStr);
        if (clickedDay && !clickedDay.classList.contains('disabled-day')) {
            clickedDay.classList.add('selected');
            clickedDay.style.background = '#43e97b';
            clickedDay.style.color = 'white';
            clickedDay.style.border = '';
        }
        
        const selectedDateInput = document.getElementById('selectedDate');
        if (selectedDateInput) {
            selectedDateInput.value = dateStr;
        }
        
        if (currentSelectedEquipment && currentSelectedEquipment.category === 'Equipment') {
            setTimeout(() => {
                if (typeof checkAvailabilityForDate === 'function') checkAvailabilityForDate();
            }, 100);
        }
        if (currentSelectedEquipment && currentSelectedEquipment.category === 'Vehicle') {
            setTimeout(() => {
                if (typeof loadVehicleAvailability === 'function') loadVehicleAvailability();
            }, 100);
        }
    };
}

// ============ AVAILABILITY CHECK ============
async function checkAvailabilityForDate() {
    if (currentSelectedEquipment.category !== 'Equipment') return;
    
    const bookingDate = document.getElementById('selectedDate').value;
    const durationDays = parseInt(document.getElementById('durationDays')?.value || 1);
    const quantity = parseInt(document.getElementById('bookingQuantity')?.value || 1);
    
    if (!bookingDate) {
        // Reset availability message if no date selected
        const availabilityMessage = document.getElementById('availabilityMessage');
        if (availabilityMessage) {
            availabilityMessage.style.display = 'none';
        }
        return;
    }
    
    const endDate = new Date(bookingDate);
    endDate.setDate(endDate.getDate() + durationDays);
    const endDateStr = endDate.toISOString().split('T')[0];
    const startDateTime = `${bookingDate}T00:00:00`;
    const endDateTime = `${endDateStr}T23:59:59`;
    
    try {
        const response = await fetch(`resident_equipment_ajax.php?action=check_availability&global_id=${currentSelectedEquipment.globalId}&start_date=${encodeURIComponent(startDateTime)}&end_date=${encodeURIComponent(endDateTime)}&quantity=${quantity}`);
        const data = await response.json();
        
        const availabilityMessage = document.getElementById('availabilityMessage');
        const submitBtn = document.getElementById('submitBookingBtn');
        const quantitySelect = document.getElementById('bookingQuantity');
        const quantityLabel = document.getElementById('quantityLabel');
        const quantityError = document.getElementById('quantityError');
        
        if (data.success) {
            // Get the actual available count from the server
            const availableCount = data.available_count || 0;
            const currentValue = parseInt(quantitySelect?.value || 1);
            
            // Update quantity dropdown based on available count
            if (quantitySelect) {
                // Clear and rebuild options based on actual availability
                quantitySelect.innerHTML = '';
                
                if (availableCount > 0) {
                    for (let i = 1; i <= availableCount; i++) {
                        const option = document.createElement('option');
                        option.value = i;
                        option.textContent = i;
                        quantitySelect.appendChild(option);
                    }
                    
                    // Set selected value
                    if (currentValue > 0 && currentValue <= availableCount) {
                        quantitySelect.value = currentValue;
                    } else {
                        quantitySelect.value = 1;
                    }
                    
                    // Update label
                    if (quantityLabel) {
                        quantityLabel.textContent = `Number of items to borrow (max ${availableCount} available)`;
                    }
                    
                    // Enable submit button if available
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                    }
                    
                } else {
                    // No items available
                    const option = document.createElement('option');
                    option.value = '0';
                    option.textContent = '0 (Not Available)';
                    option.disabled = true;
                    option.selected = true;
                    quantitySelect.appendChild(option);
                    
                    if (quantityLabel) {
                        quantityLabel.textContent = 'No items available for the selected date range';
                    }
                    
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.6';
                    }
                }
            }
            
            if (data.available) {
                if (availabilityMessage) {
                    availabilityMessage.style.display = 'block';
                    availabilityMessage.style.background = '#d4edda';
                    availabilityMessage.style.color = '#155724';
                    availabilityMessage.innerHTML = `<i class="fas fa-check-circle"></i> ${data.message}`;
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
                if (quantityError) {
                    quantityError.style.display = 'none';
                }
            } else {
                if (availabilityMessage) {
                    availabilityMessage.style.display = 'block';
                    availabilityMessage.style.background = '#f8d7da';
                    availabilityMessage.style.color = '#721c24';
                    availabilityMessage.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${data.message}`;
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                }
            }
        }
    } catch (error) {
        console.error('Error checking availability:', error);
    }
}

// ============ SUBMIT EQUIPMENTS BOOKINGS ============
async function submitEquipmentBooking(event) {
    event.preventDefault();
    
    const bookingDate = document.getElementById('selectedDate').value;
    const pickupTime = document.getElementById('pickupTime').value;
    const durationDays = parseInt(document.getElementById('durationDays').value);
    const quantity = parseInt(document.getElementById('bookingQuantity').value);
    const purpose = document.getElementById('bookingPurpose').value;
    
    if (!bookingDate) {
        showErrorModal('Please select a booking date');
        return;
    }
    
    if (!pickupTime) {
        showErrorModal('Please select a pickup time');
        return;
    }
    
    if (!purpose.trim()) {
        showErrorModal('Please state the purpose of booking');
        return;
    }
    
    // Check if quantity is valid (dropdown should prevent invalid values)
    if (isNaN(quantity) || quantity < 1) {
        showErrorModal('Please select a valid quantity');
        return;
    }
    
    // Format date and time for display
    const formattedDate = new Date(bookingDate).toLocaleDateString();
    const formattedPickupTime = formatTimeToAMPM(pickupTime);
    
    // Show confirmation before submitting
    showConfirmModal(
        'Confirm Equipment Booking',
        `<div style="text-align: left; font-size: 14px;">
            <p><i class="fas fa-tools" style="color: #2eaa5e; width: 25px;"></i> <strong>Equipment:</strong> ${escapeHtml(currentSelectedEquipment.name)}</p>
            <p><i class="fas fa-calendar-alt" style="color: #2eaa5e; width: 25px;"></i> <strong>Date:</strong> ${formattedDate}</p>
            <p><i class="fas fa-clock" style="color: #2eaa5e; width: 25px;"></i> <strong>Pickup Time:</strong> ${formattedPickupTime}</p>
            <p><i class="fas fa-hourglass-half" style="color: #2eaa5e; width: 25px;"></i> <strong>Duration:</strong> ${durationDays} day(s)</p>
            <p><i class="fas fa-boxes" style="color: #2eaa5e; width: 25px;"></i> <strong>Quantity:</strong> ${quantity} item(s)</p>
            <p><i class="fas fa-comment" style="color: #2eaa5e; width: 25px;"></i> <strong>Purpose:</strong> ${escapeHtml(purpose.substring(0, 150))}${purpose.length > 150 ? '...' : ''}</p>
            <hr style="margin: 10px 0;">
            <p style="color: #ff9800;"><i class="fas fa-info-circle"></i> Please review your booking details before submitting.</p>
        </div>`,
        async function() {
            await executeEquipmentBooking(bookingDate, pickupTime, durationDays, quantity, purpose);
        }
    );
}

async function executeEquipmentBooking(bookingDate, pickupTime, durationDays, quantity, purpose) {
    const startDateTime = `${bookingDate}T${pickupTime}:00`;
    const endDate = new Date(bookingDate);
    endDate.setDate(endDate.getDate() + durationDays);
    const endDateTime = endDate.toISOString().split('T')[0] + 'T23:59:59';
    
    const submitBtn = document.getElementById('submitBookingBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    try {
        const response = await fetch('resident_equipment_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_booking',
                global_id: currentSelectedEquipment.globalId,
                category: 'Equipment',
                start_datetime: startDateTime,
                end_datetime: endDateTime,
                duration_days: durationDays,
                quantity: quantity,
                purpose: purpose,
                custom_data: {
                    pickup_time: pickupTime
                }
            })
        });
        
        const data = await response.json();

        if (data.success) {
            showSuccessModal(`✅ Booking approved successfully! Reference: ${data.reference}`);
            loadMyBookings();
        } else {
            showErrorModal(data.message || 'Failed to submit booking');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
    }
}

async function loadAvailableTimeSlots(bookingDate) {
    const timeSlotGroup = document.getElementById('timeSlotGroup');
    const timeSlotsContainer = document.getElementById('timeSlotsContainer');
    const noTimeSlotsMsg = document.getElementById('noTimeSlotsMsg');
    const submitBtn = document.getElementById('submitBookingBtn');
    const durationHours = window.currentDurationHours || 2;
    
    console.log('loadAvailableTimeSlots called with date:', bookingDate, 'duration:', durationHours);
    
    if (!timeSlotGroup || !timeSlotsContainer) {
        console.error('Required elements not found!');
        return;
    }
    
    // Show the time slot group with loading state
    timeSlotGroup.style.display = 'block';
    timeSlotsContainer.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading available time slots...</div>';
    submitBtn.disabled = true;
    if (noTimeSlotsMsg) noTimeSlotsMsg.style.display = 'none';
    
    try {
        const url = `resident_equipment_ajax.php?action=check_facility_availability&global_id=${window.currentFacilityGlobalId}&booking_date=${bookingDate}&duration_hours=${durationHours}`;
        console.log('Fetching URL:', url);
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Response data:', data);
        
        if (data.success) {
            const availableSlots = data.available_slots || [];
            const blockedSlots = data.blocked_slots || [];
            
            console.log('Available slots count:', availableSlots.length);
            console.log('Blocked slots count:', blockedSlots.length);
            
            if (availableSlots.length > 0 || blockedSlots.length > 0) {
                timeSlotsContainer.innerHTML = '';
                noTimeSlotsMsg.style.display = 'none';
                
                // First, display blocked slots (approved bookings - RED)
                if (blockedSlots.length > 0) {
                    const blockedTitle = document.createElement('div');
                    blockedTitle.style.cssText = 'grid-column: 1/-1; margin-top: 10px; margin-bottom: 5px; font-size: 12px; color: #dc3545; border-top: 1px solid #eee; padding-top: 10px;';
                    blockedTitle.innerHTML = '<i class="fas fa-lock"></i> Already Booked (Approved)';
                    timeSlotsContainer.appendChild(blockedTitle);
                    
                    blockedSlots.forEach(slot => {
                        const slotBtn = document.createElement('button');
                        slotBtn.type = 'button';
                        slotBtn.className = 'time-slot-btn blocked';
                        slotBtn.setAttribute('data-start', slot.start);
                        slotBtn.setAttribute('data-end', slot.end);
                        slotBtn.disabled = true;
                        slotBtn.innerHTML = `
                            <i class="fas fa-ban"></i> ${slot.display}
                        `;
                        slotBtn.style.cssText = `
                            padding: 12px;
                            border: 2px solid #dc3545;
                            border-radius: 10px;
                            background: #f8d7da;
                            cursor: not-allowed;
                            font-size: 14px;
                            font-weight: 500;
                            color: #721c24;
                            opacity: 0.8;
                        `;
                        slotBtn.title = 'This time slot is already booked and approved';
                        timeSlotsContainer.appendChild(slotBtn);
                    });
                }
                
                // Then display available slots (GREEN)
                if (availableSlots.length > 0) {
                    const availableTitle = document.createElement('div');
                    availableTitle.style.cssText = 'grid-column: 1/-1; margin-top: 15px; margin-bottom: 5px; font-size: 12px; color: #28a745; border-top: 1px solid #eee; padding-top: 10px;';
                    availableTitle.innerHTML = '<i class="fas fa-check-circle"></i> Available Time Slots';
                    timeSlotsContainer.appendChild(availableTitle);
                    
                    availableSlots.forEach(slot => {
                        const slotBtn = document.createElement('button');
                        slotBtn.type = 'button';
                        slotBtn.className = 'time-slot-btn available';
                        slotBtn.setAttribute('data-start', slot.start);
                        slotBtn.setAttribute('data-end', slot.end);
                        
                        slotBtn.innerHTML = `
                            <i class="fas fa-clock"></i> ${slot.display}
                        `;
                        slotBtn.style.cssText = `
                            padding: 12px;
                            border: 2px solid #28a745;
                            border-radius: 10px;
                            background: white;
                            cursor: pointer;
                            transition: all 0.3s;
                            font-size: 14px;
                            font-weight: 500;
                        `;
                        
                        slotBtn.onmouseenter = () => {
                            if (!slotBtn.classList.contains('selected')) {
                                slotBtn.style.background = '#e8f5e9';
                                slotBtn.style.transform = 'translateY(-2px)';
                            }
                        };
                        slotBtn.onmouseleave = () => {
                            if (!slotBtn.classList.contains('selected')) {
                                slotBtn.style.background = 'white';
                                slotBtn.style.transform = 'translateY(0)';
                            }
                        };
                        
                        slotBtn.onclick = () => {
                            // Remove selected class from all available buttons
                            document.querySelectorAll('.time-slot-btn.available').forEach(btn => {
                                btn.classList.remove('selected');
                                btn.style.background = 'white';
                                btn.style.borderColor = '#28a745';
                            });
                            // Add selected class to this button
                            slotBtn.classList.add('selected');
                            slotBtn.style.background = '#d4edda';
                            slotBtn.style.borderColor = '#155724';
                            
                            // Store selected times
                            window.selectedStartTime = slot.start;
                            window.selectedEndTime = slot.end;
                            
                            // Enable submit button
                            submitBtn.disabled = false;
                        };
                        
                        timeSlotsContainer.appendChild(slotBtn);
                    });
                }
                
                if (availableSlots.length === 0 && blockedSlots.length === 0) {
                    timeSlotsContainer.innerHTML = '';
                    noTimeSlotsMsg.style.display = 'block';
                    submitBtn.disabled = true;
                }
            } else {
                timeSlotsContainer.innerHTML = '';
                noTimeSlotsMsg.style.display = 'block';
                submitBtn.disabled = true;
            }
        } else {
            timeSlotsContainer.innerHTML = `<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-circle"></i> ${data.message || 'Failed to load time slots'}</div>`;
            submitBtn.disabled = true;
        }
    } catch (error) {
        console.error('Error loading time slots:', error);
        timeSlotsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Error loading available time slots</div>';
        submitBtn.disabled = true;
    }
}

// ============ SUBMIT FACILITY BOOKINGS ============
async function submitFacilityBooking(event) {
    event.preventDefault();
    
    const bookingDate = document.getElementById('selectedDate').value;
    const durationHours = parseInt(document.getElementById('durationHours').value);
    const expectedAttendees = document.getElementById('expectedAttendees')?.value;
    const eventType = document.getElementById('eventType')?.value;
    const purpose = document.getElementById('bookingPurpose').value;
    
    // Get selected time slot
    const startTime = window.selectedStartTime;
    const endTime = window.selectedEndTime;
    
    if (!bookingDate) {
        showErrorModal('Please select a booking date');
        return;
    }
    
    if (!startTime || !endTime) {
        showErrorModal('Please select a time slot');
        return;
    }
    
    if (!purpose.trim()) {
        showErrorModal('Please state the purpose of booking');
        return;
    }
    
    // Format date and time for display
    const formattedDate = new Date(bookingDate).toLocaleDateString();
    const formattedStartTime = formatTimeToAMPM(startTime);
    const formattedEndTime = formatTimeToAMPM(endTime);
    
    // Show confirmation before submitting
    let detailsHtml = `
        <div style="text-align: left; font-size: 14px;">
            <p><i class="fas fa-building" style="color: #2eaa5e; width: 25px;"></i> <strong>Facility:</strong> ${escapeHtml(currentSelectedEquipment.name)}</p>
            <p><i class="fas fa-calendar-alt" style="color: #2eaa5e; width: 25px;"></i> <strong>Date:</strong> ${formattedDate}</p>
            <p><i class="fas fa-clock" style="color: #2eaa5e; width: 25px;"></i> <strong>Time:</strong> ${formattedStartTime} - ${formattedEndTime}</p>
            <p><i class="fas fa-hourglass-half" style="color: #2eaa5e; width: 25px;"></i> <strong>Duration:</strong> ${durationHours} hour(s)</p>`;
    
    if (expectedAttendees) {
        detailsHtml += `<p><i class="fas fa-users" style="color: #2eaa5e; width: 25px;"></i> <strong>Expected Attendees:</strong> ${expectedAttendees}</p>`;
    }
    
    if (eventType && eventType.trim()) {
        detailsHtml += `<p><i class="fas fa-tag" style="color: #2eaa5e; width: 25px;"></i> <strong>Event Type:</strong> ${escapeHtml(eventType)}</p>`;
    }
    
    detailsHtml += `
            <p><i class="fas fa-comment" style="color: #2eaa5e; width: 25px;"></i> <strong>Purpose:</strong> ${escapeHtml(purpose.substring(0, 150))}${purpose.length > 150 ? '...' : ''}</p>
            <hr style="margin: 10px 0;">
            <p style="color: #ff9800;"><i class="fas fa-info-circle"></i> Please review your booking details before submitting.</p>
        </div>`;
    
    showConfirmModal('Confirm Facility Booking', detailsHtml, async function() {
        await executeFacilityBooking(bookingDate, durationHours, expectedAttendees, eventType, purpose, startTime, endTime);
    });
}

function formatTimeToAMPM(time) {
    if (!time) return 'N/A';
    const [hours, minutes] = time.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${hour12}:${minutes} ${ampm}`;
}

async function executeFacilityBooking(bookingDate, durationHours, expectedAttendees, eventType, purpose, startTime, endTime) {
    const startDateTime = `${bookingDate}T${startTime}:00`;
    const endDateTime = `${bookingDate}T${endTime}:00`;
    
    const submitBtn = document.getElementById('submitBookingBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    try {
        const response = await fetch('resident_equipment_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_booking',
                global_id: currentSelectedEquipment.globalId,
                category: 'Facility',
                start_datetime: startDateTime,
                end_datetime: endDateTime,
                duration_days: durationHours / 24,
                purpose: purpose,
                custom_data: {
                    expected_attendees: expectedAttendees ? parseInt(expectedAttendees) : null,
                    event_type: eventType,
                    duration_hours: durationHours
                }
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal(`✅ Facility booking approved successfully! Reference: ${data.reference}`);
            loadMyBookings();
        } else {
            showErrorModal(data.message || 'Failed to submit booking');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
    }
}


// ============ VEHICLE REQUEST RULES (server re-checks everything) ============
var VEHICLE_PURPOSES = [
    { code: 'emergency_medical',    label: 'Emergency medical transportation',                         tier: 'urgent',    group: 'Emergency' },
    { code: 'evacuation_disaster',  label: 'Evacuation due to flood, fire, or other disaster',         tier: 'urgent',    group: 'Emergency' },
    { code: 'evacuation_center',    label: 'Transportation to an evacuation center',                   tier: 'urgent',    group: 'Emergency' },
    { code: 'urgent_family',        label: 'Urgent family or humanitarian emergency',                  tier: 'urgent',    group: 'Emergency' },

    { code: 'hospital_appointment', label: 'Hospital appointment or checkup',                          tier: 'flexible',  group: 'Medical & health' },
    { code: 'hospital_admission',   label: 'Hospital admission or discharge',                          tier: 'flexible',  group: 'Medical & health' },
    { code: 'dialysis_treatment',   label: 'Dialysis or regular medical treatment',                    tier: 'flexible',  group: 'Medical & health' },
    { code: 'laboratory_procedure', label: 'Laboratory tests or medical procedures',                   tier: 'flexible',  group: 'Medical & health' },
    { code: 'prenatal_checkup',     label: 'Prenatal checkup',                                         tier: 'flexible',  group: 'Medical & health' },
    { code: 'senior_medical',       label: 'Senior citizen medical appointment',                       tier: 'flexible',  group: 'Medical & health' },
    { code: 'pharmacy_pickup',      label: 'Pharmacy or medicine pickup for an ill resident',          tier: 'flexible',  group: 'Medical & health' },
    { code: 'funeral',              label: 'Funeral-related transportation',                           tier: 'flexible',  group: 'Funeral' },

    { code: 'pwd_transport',        label: 'Transportation for a person with disability',              tier: 'scheduled', group: 'Assistance & mobility' },
    { code: 'limited_mobility',     label: 'Transportation for a resident with limited mobility',      tier: 'scheduled', group: 'Assistance & mobility' },
    { code: 'indigent_services',    label: 'Transportation for indigent residents needing essential services', tier: 'scheduled', group: 'Assistance & mobility' },
    { code: 'government_office',    label: 'Government office appointment',                            tier: 'scheduled', group: 'Government & barangay' },
    { code: 'dswd_mswdo',           label: 'DSWD or MSWDO assistance appointment',                     tier: 'scheduled', group: 'Government & barangay' },
    { code: 'barangay_hearing',     label: 'Barangay mediation or hearing',                            tier: 'scheduled', group: 'Government & barangay' },
    { code: 'barangay_document',    label: 'Barangay document-related appointment',                    tier: 'scheduled', group: 'Government & barangay' },

    { code: 'other',                label: 'Other valid personal or emergency reason (needs approval)', tier: 'other',     group: 'Other' }
];

var VEHICLE_TIER_INFO = {
    urgent:    { notice: 0,  officeOnly: false, text: 'Emergency trips are approved right away and can be booked for today. If the vehicle is already booked, your request goes to the Captain/Secretary as urgent.' },
    flexible:  { notice: 2,  officeOnly: false, text: 'Approved automatically when the vehicle is free. Needs at least 2 hours notice, any time of day.' },
    scheduled: { notice: 24, officeOnly: true,  text: 'Approved automatically when the vehicle is free. Needs at least 24 hours notice, between 6:00 AM and 6:00 PM.' },
    other:     { notice: 24, officeOnly: true,  text: 'This reason needs approval from the Barangay Captain or Secretary. You will be notified once it is reviewed.' }
};
var VEHICLE_BUFFER_MIN = 30;
var VEHICLE_OFFICE = { start: '06:00', end: '18:00' };
// Funeral / bereavement: resident picks the exact time, window is 08:00–17:00.
var VEHICLE_FUNERAL_WINDOW = { start: '08:00', end: '17:00' };
var _vehicleBusy = [];

function isFullVehicleReason(code) {
    return code === 'funeral';
}

function getVehiclePurpose(code) {
    return VEHICLE_PURPOSES.find(p => p.code === code) || null;
}

function vehiclePurposeOptionsHtml() {
    const groups = {};
    VEHICLE_PURPOSES.forEach(p => { (groups[p.group] = groups[p.group] || []).push(p); });
    return Object.keys(groups).map(g =>
        `<optgroup label="${g}">${groups[g].map(p => `<option value="${p.code}">${escapeHtml(p.label)}</option>`).join('')}</optgroup>`
    ).join('');
}

function initVehicleFormUI() {
    _vehicleBusy = [];
    const purposeSel = document.getElementById('vehiclePurpose');
    if (purposeSel) purposeSel.addEventListener('change', updateVehiclePurposeUI);
    ['pickupTime', 'durationHours'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', updateVehicleConflictHint);
    });
}

function updateVehiclePurposeUI() {
    const purpose = getVehiclePurpose(document.getElementById('vehiclePurpose').value);
    const hint = document.getElementById('vehiclePurposeHint');
    const otherWrap = document.getElementById('vehicleOtherWrap');

    if (otherWrap) otherWrap.style.display = (purpose && purpose.tier === 'other') ? 'block' : 'none';
    if (hint) {
        if (purpose) {
            hint.style.display = 'block';
            hint.style.color = purpose.tier === 'other' ? '#e65100' : (purpose.tier === 'urgent' ? '#c62828' : '#2e7d32');
            hint.textContent = VEHICLE_TIER_INFO[purpose.tier].text;
        } else {
            hint.style.display = 'none';
        }
    }
    updateVehicleConflictHint();
}

async function loadVehicleAvailability() {
    const date = document.getElementById('selectedDate')?.value;
    const panel = document.getElementById('vehicleAvailability');
    if (!date || !panel || !currentSelectedEquipment) return;

    panel.style.display = 'block';
    panel.style.background = '#f5f5f5';
    panel.style.color = '#666';
    panel.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking the vehicle schedule...';

    try {
        const resp = await fetch(`resident_equipment_ajax.php?action=check_vehicle_availability&global_id=${encodeURIComponent(currentSelectedEquipment.globalId)}&date=${encodeURIComponent(date)}`);
        const data = await resp.json();
        _vehicleBusy = (data && data.success) ? data.busy : [];
    } catch (e) {
        _vehicleBusy = [];
    }
    updateVehicleConflictHint();
}

function _hmToMin(hm) { const [h, m] = hm.split(':').map(Number); return h * 60 + m; }
function _minToAmPm(min) { return formatTimeToAMPM(`${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`); }

function updateVehicleConflictHint() {
    const panel = document.getElementById('vehicleAvailability');
    const date = document.getElementById('selectedDate')?.value;
    const submitBtn = document.getElementById('submitBookingBtn');
    if (!panel || !date) return;

    const purpose = getVehiclePurpose(document.getElementById('vehiclePurpose')?.value);
    const time = document.getElementById('pickupTime')?.value;
    const hours = parseInt(document.getElementById('durationHours')?.value || 1);

    let conflict = false;
    if (time) {
        const start = _hmToMin(time), end = start + hours * 60;
        conflict = _vehicleBusy.some(b => start < _hmToMin(b.end) + VEHICLE_BUFFER_MIN && end > _hmToMin(b.start) - VEHICLE_BUFFER_MIN);
    }

    const busyText = _vehicleBusy.length
        ? '<br>Already booked: ' + _vehicleBusy.map(b => `<strong>${_minToAmPm(_hmToMin(b.start))} - ${_minToAmPm(_hmToMin(b.end))}</strong>`).join(', ')
          + ` (the vehicle needs a ${VEHICLE_BUFFER_MIN}-minute gap between trips)`
        : '';

    panel.style.display = 'block';
    const urgent = purpose && purpose.tier === 'urgent';
    if (!conflict) {
        panel.style.background = '#e8f5e9';
        panel.style.color = '#2e7d32';
        panel.innerHTML = (_vehicleBusy.length ? '<i class="fas fa-check-circle"></i> Your selected time is free.' : '<i class="fas fa-check-circle"></i> No trips booked on this date.') + busyText;
    } else if (urgent) {
        panel.style.background = '#fff3e0';
        panel.style.color = '#e65100';
        panel.innerHTML = '<i class="fas fa-exclamation-triangle"></i> The vehicle is already booked at this time. Your emergency request will be sent to the Captain/Secretary as urgent.' + busyText;
    } else {
        panel.style.background = '#ffebee';
        panel.style.color = '#c62828';
        panel.innerHTML = '<i class="fas fa-times-circle"></i> The vehicle is not available at this time. Please choose another time.' + busyText;
    }
    if (submitBtn) submitBtn.disabled = conflict && !urgent;
}

function validateVehicleSchedule(tier, date, time, hours) {
    const info = VEHICLE_TIER_INFO[tier];
    const pad = n => String(n).padStart(2, '0');
    const start = new Date(`${date}T${time}:00`);
    const end = new Date(start.getTime() + hours * 3600000);
    const now = new Date();

    if (start < new Date(now.getTime() - 5 * 60000)) return 'The pick-up time has already passed.';
    if (info.notice > 0 && start < new Date(now.getTime() + info.notice * 3600000)) {
        return `This kind of trip needs at least ${info.notice} hour(s) notice. For an emergency, choose an emergency reason.`;
    }
    if (info.officeOnly) {
        const endHm = `${pad(end.getHours())}:${pad(end.getMinutes())}`;
        if (time < VEHICLE_OFFICE.start || endHm > VEHICLE_OFFICE.end || end.toDateString() !== start.toDateString()) {
            return 'This kind of trip must be between 6:00 AM and 6:00 PM.';
        }
    }
    return null;
}

// ============ SUBMIT VEHICLE REQUESTS ============
async function submitVehicleBooking(event) {
    event.preventDefault();

    const bookingDate = document.getElementById('selectedDate').value;
    const pickupTime = document.getElementById('pickupTime').value;
    const pickupLocation = document.getElementById('pickupLocation').value;
    const dropoffLocation = document.getElementById('dropoffLocation').value;
    const passengerCount = parseInt(document.getElementById('passengerCount').value);
    const durationHours = parseInt(document.getElementById('durationHours').value);
    const specialRequests = document.getElementById('specialRequests')?.value;
    const purpose = getVehiclePurpose(document.getElementById('vehiclePurpose').value);
    const otherReason = (document.getElementById('vehicleOtherReason')?.value || '').trim();

    if (!purpose) {
        showErrorModal('Please select the reason for your vehicle request');
        return;
    }
    if (purpose.tier === 'other' && otherReason.length < 10) {
        showErrorModal('Please describe your reason (at least 10 characters)');
        return;
    }
    if (!bookingDate) {
        showErrorModal('Please select a date');
        return;
    }
    if (!pickupTime) {
        showErrorModal('Please select pickup time');
        return;
    }
    if (!pickupLocation.trim()) {
        showErrorModal('Please enter pick-up location');
        return;
    }
    if (!dropoffLocation.trim()) {
        showErrorModal('Please enter drop-off location');
        return;
    }
    if (passengerCount > (currentSelectedEquipment.capacity || 12)) {
        showErrorModal(`Maximum ${currentSelectedEquipment.capacity || 12} passengers allowed`);
        return;
    }
    const scheduleError = validateVehicleSchedule(purpose.tier, bookingDate, pickupTime, durationHours);
    if (scheduleError) {
        showErrorModal(scheduleError);
        return;
    }

    const formattedDate = new Date(`${bookingDate}T00:00:00`).toLocaleDateString();
    const formattedPickupTime = formatTimeToAMPM(pickupTime);
    const reasonText = purpose.tier === 'other' ? `Other: ${otherReason}` : purpose.label;
    const needsApproval = purpose.tier === 'other';

    let detailsHtml = `
        <div style="text-align: left; font-size: 14px;">
            <p><i class="fas fa-truck" style="color: #2eaa5e; width: 25px;"></i> <strong>Vehicle:</strong> ${escapeHtml(currentSelectedEquipment.name)}</p>
            <p><i class="fas fa-comment" style="color: #2eaa5e; width: 25px;"></i> <strong>Reason:</strong> ${escapeHtml(reasonText.substring(0, 160))}</p>
            <p><i class="fas fa-calendar-alt" style="color: #2eaa5e; width: 25px;"></i> <strong>Date:</strong> ${formattedDate}</p>
            <p><i class="fas fa-clock" style="color: #2eaa5e; width: 25px;"></i> <strong>Pickup Time:</strong> ${formattedPickupTime}</p>
            <p><i class="fas fa-map-marker-alt" style="color: #2eaa5e; width: 25px;"></i> <strong>Pickup Location:</strong> ${escapeHtml(pickupLocation)}</p>
            <p><i class="fas fa-flag-checkered" style="color: #2eaa5e; width: 25px;"></i> <strong>Drop-off Location:</strong> ${escapeHtml(dropoffLocation)}</p>
            <p><i class="fas fa-users" style="color: #2eaa5e; width: 25px;"></i> <strong>Passengers:</strong> ${passengerCount}</p>
            <p><i class="fas fa-hourglass-half" style="color: #2eaa5e; width: 25px;"></i> <strong>Estimated Duration:</strong> ${durationHours} hour(s)</p>`;

    if (specialRequests && specialRequests.trim()) {
        detailsHtml += `<p><i class="fas fa-sticky-note" style="color: #2eaa5e; width: 25px;"></i> <strong>Special Requests:</strong> ${escapeHtml(specialRequests.substring(0, 150))}${specialRequests.length > 150 ? '...' : ''}</p>`;
    }

    detailsHtml += `
            <hr style="margin: 10px 0;">
            <p style="color: #ff9800;"><i class="fas fa-info-circle"></i> ${needsApproval
                ? 'This request will be sent to the Barangay Captain/Secretary for approval.'
                : 'Please review your request before submitting.'}</p>
        </div>`;

    showConfirmModal('Confirm Vehicle Request', detailsHtml, async function() {
        await executeVehicleBooking(bookingDate, pickupTime, pickupLocation, dropoffLocation, passengerCount, durationHours, specialRequests, purpose, otherReason);
    });
}

async function executeVehicleBooking(bookingDate, pickupTime, pickupLocation, dropoffLocation, passengerCount, durationHours, specialRequests, purpose, otherReason) {
    // Build local date-times by hand: toISOString() converts to UTC and shifts the end time back
    const pad = n => String(n).padStart(2, '0');
    const fmt = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
    const startObj = new Date(`${bookingDate}T${pickupTime}:00`);
    const endObj = new Date(startObj.getTime() + durationHours * 3600000);
    const startDateTime = fmt(startObj);
    const endDateTime = fmt(endObj);

    const submitBtn = document.getElementById('submitBookingBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const response = await fetch('resident_equipment_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_booking',
                global_id: currentSelectedEquipment.globalId,
                category: 'Vehicle',
                start_datetime: startDateTime,
                end_datetime: endDateTime,
                duration_days: durationHours / 24,
                quantity: 1,
                purpose: purpose.label,
                custom_data: {
                    purpose_code: purpose.code,
                    other_reason: otherReason,
                    pickup_location: pickupLocation,
                    dropoff_location: dropoffLocation,
                    passenger_count: passengerCount,
                    estimated_hours: durationHours,
                    special_requests: specialRequests
                }
            })
        });

        const data = await response.json();

        if (data.success) {
            if (data.status === 'pending') {
                showSuccessModal(`📨 Request submitted. The Barangay Captain/Secretary will review it and you will be notified. Reference: ${data.reference}`);
            } else {
                showSuccessModal(`✅ Vehicle request approved! Reference: ${data.reference}`);
            }
            loadMyBookings();
        } else {
            showErrorModal(data.message || 'Failed to submit request');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Booking Request';
    }
}

// ============ LOAD MY BOOKINGS ============
async function loadMyBookings() {
    const dashboardBody = document.getElementById('dashboardBody');
    if (!dashboardBody) return;

    // Reset the controls the first time the tab is opened.
    if (window.__bookingsInitialised !== true) {
        window.__bookingsInitialised = true;
        bookingSearch   = '';
        bookingPage     = 1;
        initBookingDateWindow();
    }

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
    // First-time setup: default date window (30 days from today).
    if (bookingDateFrom === null && bookingDateTo === null) {
        initBookingDateWindow();
    }

    const isFilterOpen = window.__bookingFilterOpen !== false;

    // ── Filter UI ─────────────────────────────────────────
    const statusButtons = `
        <div class="filter-buttons" style="display:flex;flex-wrap:wrap;gap:8px;">
            <button class="filter-btn ${currentBookingFilter === 'all'        ? 'active' : ''}" onclick="filterBookings('all')">All</button>
            <button class="filter-btn ${currentBookingFilter === 'pending'    ? 'active' : ''}" onclick="filterBookings('pending')">Pending</button>
            <button class="filter-btn ${currentBookingFilter === 'approved'   ? 'active' : ''}" onclick="filterBookings('approved')">Approved</button>
            <button class="filter-btn ${currentBookingFilter === 'borrowed'   ? 'active' : ''}" onclick="filterBookings('borrowed')">Borrowed</button>
            <button class="filter-btn ${currentBookingFilter === 'cancelled'  ? 'active' : ''}" onclick="filterBookings('cancelled')">Cancelled</button>
            <button class="filter-btn ${currentBookingFilter === 'completed'  ? 'active' : ''}" onclick="filterBookings('completed')">Completed</button>
        </div>
    `;

    const filterPanel = `
        <div style="margin-bottom:20px;border:1px solid #e2efe8;border-radius:12px;overflow:hidden;background:#fff;">
            <button type="button" onclick="toggleBookingFilter()"
                    style="width:100%;display:flex;align-items:center;justify-content:space-between;
                           padding:12px 16px;background:#f8faf8;border:none;cursor:pointer;
                           font-weight:700;color:#1a472a;font-size:0.95rem;">
                <span>
                    <i class="fas fa-sliders-h" style="margin-right:8px;color:#2e7d32;"></i>
                    Filters &amp; Search
                    ${bookingSearch || bookingDateFrom || bookingDateTo
                        ? `<span style="margin-left:8px;background:#e8f5e9;color:#2e7d32;border-radius:20px;
                                         padding:2px 10px;font-size:0.75rem;font-weight:700;">active</span>`
                        : ''}
                </span>
                <i class="fas fa-chevron-${isFilterOpen ? 'up' : 'down'}" style="color:#2e7d32;"></i>
            </button>

            <div id="bookingFilterBody" style="display:${isFilterOpen ? 'block' : 'none'};padding:14px 16px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;color:#666;margin-bottom:4px;">Search</label>
                        <input type="text" id="bookingSearchInput" value="${escapeHtml(bookingSearch)}"
                               oninput="onBookingSearchInput(this.value)"
                               placeholder="Reference, vehicle, purpose…"
                               style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid #ddd;font-size:14px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;color:#666;margin-bottom:4px;">From</label>
                        <input type="date" id="bookingDateFrom" value="${bookingDateFrom || ''}"
                               onchange="onBookingDateChange('from', this.value)"
                               style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid #ddd;font-size:14px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;color:#666;margin-bottom:4px;">To</label>
                        <input type="date" id="bookingDateTo" value="${bookingDateTo || ''}"
                               onchange="onBookingDateChange('to', this.value)"
                               style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid #ddd;font-size:14px;">
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:8px;">
                        <button type="button" onclick="resetBookingFilters()"
                                style="padding:10px 14px;border-radius:9px;border:1px solid #ccc;background:#f5f5f5;cursor:pointer;font-weight:600;">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>

                <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee;">
                    <div style="font-size:12px;font-weight:700;color:#666;margin-bottom:8px;">Status</div>
                    ${statusButtons}
                </div>
            </div>
        </div>
    `;

    // ── Apply filters ─────────────────────────────────────
    let filtered = applyBookingFilters(bookings);
    if (currentBookingFilter !== 'all') {
        filtered = filtered.filter(b => b.status === currentBookingFilter);
    }

    // ── Pagination ────────────────────────────────────────
    const totalPages = Math.max(1, Math.ceil(filtered.length / BOOKING_PAGE_SIZE));
    if (bookingPage > totalPages) bookingPage = totalPages;
    if (bookingPage < 1) bookingPage = 1;
    const startIdx = (bookingPage - 1) * BOOKING_PAGE_SIZE;
    const pageSlice = filtered.slice(startIdx, startIdx + BOOKING_PAGE_SIZE);

    const bookingsHtml = pageSlice.map(generateBookingCardHtml).join('');

    const pageButtons = [];
    const win = 2; // how many neighbours on each side of current
    const first = Math.max(1, bookingPage - win);
    const last  = Math.min(totalPages, bookingPage + win);

    pageButtons.push(`
        <button class="filter-btn" ${bookingPage === 1 ? 'disabled' : ''}
                onclick="gotoBookingPage(${bookingPage - 1})"
                style="min-width:38px;">
            <i class="fas fa-chevron-left"></i>
        </button>
    `);
    if (first > 1) {
        pageButtons.push(`<button class="filter-btn" onclick="gotoBookingPage(1)">1</button>`);
        if (first > 2) pageButtons.push(`<span style="padding:0 6px;color:#888;">…</span>`);
    }
    for (let p = first; p <= last; p++) {
        pageButtons.push(`
            <button class="filter-btn ${p === bookingPage ? 'active' : ''}"
                    onclick="gotoBookingPage(${p})" style="min-width:38px;">${p}</button>
        `);
    }
    if (last < totalPages) {
        if (last < totalPages - 1) pageButtons.push(`<span style="padding:0 6px;color:#888;">…</span>`);
        pageButtons.push(`<button class="filter-btn" onclick="gotoBookingPage(${totalPages})">${totalPages}</button>`);
    }
    pageButtons.push(`
        <button class="filter-btn" ${bookingPage === totalPages ? 'disabled' : ''}
                onclick="gotoBookingPage(${bookingPage + 1})"
                style="min-width:38px;">
            <i class="fas fa-chevron-right"></i>
        </button>
    `);

    const paginationBar = totalPages > 1 ? `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;">
            <div style="font-size:12.5px;color:#666;">
                Showing <b>${startIdx + 1}</b>–<b>${Math.min(startIdx + BOOKING_PAGE_SIZE, filtered.length)}</b>
                of <b>${filtered.length}</b>
            </div>
            <div class="filter-buttons" style="display:flex;gap:6px;flex-wrap:wrap;">
                ${pageButtons.join('')}
            </div>
        </div>
    ` : '';

    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> My Bookings</div>
            <div class="section-sub">Search, filter by date, and page through your requests</div>
            ${filterPanel}
            <div class="bookings-list">
                ${filtered.length === 0
                    ? `<div class="empty-state"><i class="fas fa-inbox"></i><p>No bookings match your filters.</p></div>`
                    : bookingsHtml}
            </div>
            ${paginationBar}
        </div>
    `;
}

// ── Filter/search/pagination handlers ────────────────────────
function toggleBookingFilter() {
    window.__bookingFilterOpen = (window.__bookingFilterOpen === false);
    renderMyBookings(currentBookingsList);
}

let __bookingSearchTimer = null;
function onBookingSearchInput(value) {
    bookingSearch = value || '';
    bookingPage   = 1;
    clearTimeout(__bookingSearchTimer);
    __bookingSearchTimer = setTimeout(() => renderMyBookings(currentBookingsList), 250);
}

function onBookingDateChange(which, value) {
    if (which === 'from') bookingDateFrom = value || null;
    if (which === 'to')   bookingDateTo   = value || null;
    bookingPage = 1;
    renderMyBookings(currentBookingsList);
}

function resetBookingFilters() {
    bookingSearch = '';
    currentBookingFilter = 'all';
    bookingPage = 1;
    initBookingDateWindow();
    renderMyBookings(currentBookingsList);
}

function gotoBookingPage(p) {
    bookingPage = p;
    renderMyBookings(currentBookingsList);
    // Scroll to the top of the list
    const card = document.querySelector('#dashboardBody .content-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function filterBookings(status) {
    currentBookingFilter = status;
    bookingPage = 1;
    renderMyBookings(currentBookingsList);
}

window.toggleBookingFilter   = toggleBookingFilter;
window.onBookingSearchInput  = onBookingSearchInput;
window.onBookingDateChange   = onBookingDateChange;
window.resetBookingFilters   = resetBookingFilters;
window.gotoBookingPage       = gotoBookingPage;
window.filterBookings        = filterBookings;



function generateBookingCardHtml(booking) {
    const requestDate = new Date(booking.request_date);
    const startDateTime = new Date(booking.start_datetime);
    const endDateTime = new Date(booking.end_datetime);
    const hasExpanded = window.expandedBookingId === booking.id;
    
    let categoryIcon = 'fa-tools';
    let categoryTitle = 'Equipment';
    if (booking.category === 'facility') {
        categoryIcon = 'fa-building';
        categoryTitle = 'Facility';
    } else if (booking.category === 'vehicle') {
        categoryIcon = 'fa-truck';
        categoryTitle = 'Vehicle';
    }
    
    let statusClass = '';
    let statusIcon = '';
    let statusText = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
    switch(booking.status) {
        case 'approved':  statusClass = 'status-approved';  statusIcon = '<i class="fas fa-check-circle"></i> '; break;
        case 'borrowed':  statusClass = 'status-approved';  statusIcon = '<i class="fas fa-hand-holding"></i> '; statusText = 'Borrowed'; break;
        case 'pending':   statusClass = 'status-pending';   statusIcon = '<i class="fas fa-clock"></i> '; statusText = 'Pending approval'; break;
        case 'rejected':  statusClass = 'status-rejected';  statusIcon = '<i class="fas fa-times-circle"></i> '; break;
        case 'completed': statusClass = 'status-completed'; statusIcon = '<i class="fas fa-check-double"></i> '; break;
        case 'cancelled': statusClass = 'status-rejected';  statusIcon = '<i class="fas fa-ban"></i> '; statusText = 'Cancelled'; break;
        default:          statusClass = 'status-approved';  statusIcon = '<i class="fas fa-check-circle"></i> ';
    }
    let itemName = '';
    if (booking.category === 'equipment') {
        itemName = booking.equipment_name || booking.name || 'Equipment';
    } else if (booking.category === 'facility') {
        itemName = booking.facility_name || booking.name || 'Facility';
    } else if (booking.category === 'vehicle') {
        itemName = booking.vehicle_name || booking.name || 'Vehicle';
    }
    
    // Build summary details for the header
    let summaryDetails = '';
    if (booking.category === 'equipment') {
        summaryDetails = `
            <div class="booking-summary-item">
                <i class="fas fa-calendar"></i> ${startDateTime.toLocaleDateString()}
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-boxes"></i> ${booking.quantity} item(s)
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-clock"></i> ${booking.duration_days} day(s)
            </div>
        `;
    } else if (booking.category === 'facility') {
        const startTime = booking.start_time ? booking.start_time.substring(0, 5) : '';
        const endTime = booking.end_time ? booking.end_time.substring(0, 5) : '';
        summaryDetails = `
            <div class="booking-summary-item">
                <i class="fas fa-calendar"></i> ${startDateTime.toLocaleDateString()}
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-clock"></i> ${startTime} - ${endTime}
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-hourglass-half"></i> ${booking.duration_hours || 1} hour(s)
            </div>
        `;
    } else if (booking.category === 'vehicle') {
        summaryDetails = `
            <div class="booking-summary-item">
                <i class="fas fa-calendar"></i> ${startDateTime.toLocaleDateString()}
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-clock"></i> ${booking.pickup_time ? booking.pickup_time.substring(0, 5) : ''}
            </div>
            <div class="booking-summary-item">
                <i class="fas fa-users"></i> ${booking.passenger_count || 1} passenger(s)
            </div>
        `;
    }
    
    // Build detailed content for expanded section
    let detailsHtml = '';
    
    if (booking.category === 'equipment') {
        const bookingDate = new Date(booking.booking_date);
        const returnDate = new Date(booking.return_date);
        const pickupDateTime = new Date(booking.start_datetime);
        const pickupTimeFormatted = pickupDateTime.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Schedule Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Booking Date</label>
                        <span>${bookingDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Pickup Time</label>
                        <span>${pickupTimeFormatted}</span>
                    </div>
                    <div class="detail-item">
                        <label>Return Date</label>
                        <span>${returnDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Duration</label>
                        <span>${booking.duration_days} day(s)</span>
                    </div>
                    <div class="detail-item">
                        <label>Quantity</label>
                        <span>${booking.quantity} item(s)</span>
                    </div>
                </div>
            </div>
        `;
    } else if (booking.category === 'facility') {
        const facilityDate = new Date(booking.booking_date);
        const startTime = booking.start_time ? booking.start_time.substring(0, 5) : 'N/A';
        const endTime = booking.end_time ? booking.end_time.substring(0, 5) : 'N/A';
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Schedule Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Date</label>
                        <span>${facilityDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Time</label>
                        <span>${startTime} - ${endTime}</span>
                    </div>
                    <div class="detail-item">
                        <label>Duration</label>
                        <span>${booking.duration_hours || 1} hour(s)</span>
                    </div>
                    ${booking.expected_attendees ? `<div class="detail-item"><label>Expected Attendees</label><span>${escapeHtml(booking.expected_attendees)}</span></div>` : ''}
                    ${booking.event_type ? `<div class="detail-item"><label>Event Type</label><span>${escapeHtml(booking.event_type)}</span></div>` : ''}
                </div>
            </div>
        `;
    } else if (booking.category === 'vehicle') {
        const tripDate = new Date(booking.trip_date || booking.start_datetime);
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Trip Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Trip Date</label>
                        <span>${tripDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Pickup Time</label>
                        <span>${booking.pickup_time ? booking.pickup_time.substring(0, 5) : 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Pickup Location</label>
                        <span>${escapeHtml(booking.pickup_location || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Drop-off Location</label>
                        <span>${escapeHtml(booking.dropoff_location || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Passengers</label>
                        <span>${booking.passenger_count || 1}</span>
                    </div>
                    <div class="detail-item">
                        <label>Estimated Duration</label>
                        <span>${booking.estimated_hours || 1} hour(s)</span>
                    </div>
                    <div class="detail-item">
                        <label>Vehicle Capacity</label>
                        <span>${booking.vehicle_capacity || 12} passengers</span>
                    </div>
                </div>
            </div>
        `;
        
        if (booking.special_requests) {
            detailsHtml += `
                <div class="details-section">
                    <h4><i class="fas fa-star"></i> Special Requests</h4>
                    <div style="background: #fff8e1; padding: 12px; border-radius: 8px; border-left: 4px solid #ff9800;">
                        <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.special_requests)}</p>
                    </div>
                </div>
            `;
        }

        // If cancelled, show why + when.
        if (booking.status === 'cancelled') {
            detailsHtml += `
                <div class="details-section">
                    <h4><i class="fas fa-ban" style="color:#c62828;"></i> Cancellation</h4>
                    <div style="background:#ffebee; padding:12px; border-radius:8px; border-left:4px solid #c62828;">
                        <p style="margin:0; color:#b71c1c;">
                            This trip has been cancelled.
                            ${booking.cancelled_at
                                ? `<br><small style="color:#7f1d1d;">on ${new Date(booking.cancelled_at).toLocaleString()}</small>`
                                : ''}
                        </p>
                    </div>
                </div>
            `;
        } else {
            // Cancel button — only while the trip can still be cancelled.
            const canCancel =
                booking.status !== 'completed' &&
                booking.status !== 'rejected' &&
                booking.status !== 'cancelled';

            if (canCancel) {
                detailsHtml += `
                    <div class="details-section" style="text-align:right;">
                        <button type="button"
                                style="background:#fff; border:2px solid #c62828; color:#c62828;
                                       padding:10px 20px; border-radius:9px; font-weight:700; cursor:pointer;"
                                onclick="event.stopPropagation(); showCancelRequestModal(${booking.id});">
                            <i class="fas fa-times-circle"></i> Cancel Request
                        </button>
                    </div>
                `;
            }
        }
    }
    
    // Add approval information if approved
    if (booking.status === 'approved' && booking.approved_by_name) {
        detailsHtml += `
            <div class="details-section">
                <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Approval Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Approved By</label>
                        <span>${escapeHtml(booking.approved_by_name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Approved At</label>
                        <span>${booking.approved_at ? new Date(booking.approved_at).toLocaleString() : 'N/A'}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Add purpose
    if (booking.purpose) {
        detailsHtml += `
            <div class="details-section">
                <h4><i class="fas fa-comment"></i> Purpose</h4>
                <div style="background: #f8f9fa; padding: 12px; border-radius: 8px;">
                    <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.purpose)}</p>
                </div>
            </div>
        `;
    }
    
    // Add admin notes
    if (booking.admin_notes) {
        detailsHtml += `
            <div class="details-section">
                <h4><i class="fas fa-user-shield"></i> Admin Response</h4>
                <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; border-left: 4px solid #2196f3;">
                    <p style="margin: 0; color: #1565c0;">${escapeHtml(booking.admin_notes)}</p>
                    <small style="color: #666; display: block; margin-top: 8px;"><i class="fas fa-clock"></i> ${booking.processed_at ? new Date(booking.processed_at).toLocaleString() : 'N/A'}</small>
                </div>
            </div>
        `;
    }
    
    return `
        <div class="booking-request-card ${booking.status}" data-id="${booking.id}" data-status="${booking.status}">
            <div class="booking-request-card-header" onclick="toggleBookingDetails(${booking.id})">
                <div class="booking-request-info">
                    <div class="booking-type-badge">
                        <i class="fas ${categoryIcon}"></i>
                        <span class="booking-type-name">${escapeHtml(itemName)}</span>
                        <span class="booking-ref">(${booking.item_global_id || 'N/A'})</span>
                    </div>
                    <div class="status-badge ${statusClass}">${statusIcon} ${statusText}</div>
                </div>
                <div class="booking-request-meta">
                    ${summaryDetails}
                    <div class="booking-date-simple">
                        <i class="fas fa-calendar-alt"></i> ${requestDate.toLocaleDateString()}
                    </div>
                    <i class="fas fa-chevron-down expand-icon" id="expandIconBooking-${booking.id}"></i>
                </div>
            </div>
            <div class="booking-request-details" id="bookingDetails-${booking.id}" style="display: ${hasExpanded ? 'block' : 'none'};">
                <div class="details-content">
                    ${detailsHtml}
                </div>
            </div>
        </div>
    `;
}



// ============================================================
//  CANCEL REQUEST — immediate, reason required, notify staff
// ============================================================
function showCancelRequestModal(bookingId) {
    let modal = document.getElementById('cancelRequestModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'cancelRequestModal';
        modal.style.cssText = `
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55);
            z-index:9999; align-items:center; justify-content:center; padding:16px;`;
        modal.innerHTML = `
            <div style="background:#fff; border-radius:14px; max-width:520px; width:100%;
                        padding:22px; box-shadow:0 12px 30px rgba(0,0,0,0.25);">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                    <i class="fas fa-times-circle" style="color:#c62828; font-size:1.4rem;"></i>
                    <h3 style="margin:0; color:#1a472a;">Cancel this trip?</h3>
                </div>
                <p style="font-size:14px; color:#555; margin:0 0 12px 0;">
                    This will cancel your vehicle request right away and notify the
                    Barangay Captain and Secretary. Please tell us why.
                </p>
                <textarea id="cancelReasonInput" rows="4" maxlength="500"
                          placeholder="Reason for cancellation (at least 10 characters)..."
                          style="width:100%; padding:12px; border-radius:10px; border:1px solid #ddd;
                                 font-family:inherit; font-size:14px; resize:vertical;"></textarea>
                <div id="cancelReasonFeedback"
                     style="font-size:12px; color:#c62828; margin-top:6px; min-height:16px;"></div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                    <button type="button"
                            style="padding:10px 18px; border-radius:9px; border:1px solid #ccc;
                                   background:#f5f5f5; cursor:pointer;"
                            onclick="closeCancelRequestModal()">Keep trip</button>
                    <button type="button" id="cancelSubmitBtn"
                            style="background:#c62828; border:none; color:#fff; padding:10px 20px;
                                   border-radius:9px; font-weight:700; cursor:pointer;"
                            onclick="submitCancelRequest(${bookingId})">
                        <i class="fas fa-paper-plane"></i> Yes, cancel it
                    </button>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }

    document.getElementById('cancelReasonInput').value = '';
    document.getElementById('cancelReasonFeedback').textContent = '';
    const btn = document.getElementById('cancelSubmitBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Yes, cancel it';
    modal.style.display = 'flex';
}

function closeCancelRequestModal() {
    const modal = document.getElementById('cancelRequestModal');
    if (modal) modal.style.display = 'none';
}

async function submitCancelRequest(bookingId) {
    const reason   = (document.getElementById('cancelReasonInput')?.value || '').trim();
    const feedback = document.getElementById('cancelReasonFeedback');
    const btn      = document.getElementById('cancelSubmitBtn');

    if (reason.length < 10) {
        feedback.textContent = 'Please write at least 10 characters.';
        return;
    }
    if (reason.length > 500) {
        feedback.textContent = 'Please keep it under 500 characters.';
        return;
    }
    feedback.textContent = '';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling…';

    try {
        const r = await fetch('resident_equipment_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cancel_request',
                request_id: bookingId,
                reason: reason
            })
        });
        const d = await r.json();

        if (!d.success) {
            feedback.textContent = d.message || 'Could not cancel.';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Yes, cancel it';
            return;
        }

        closeCancelRequestModal();
        showSuccessModal('Request cancelled. The Captain and Secretary were notified.');
        setTimeout(() => loadMyBookings(), 1200);

    } catch (err) {
        feedback.textContent = 'Network error. Please try again.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Yes, cancel it';
    }
}

window.showCancelRequestModal = showCancelRequestModal;
window.closeCancelRequestModal = closeCancelRequestModal;
window.submitCancelRequest    = submitCancelRequest;

// Track expanded booking ID
window.expandedBookingId = null;

// Toggle booking details (similar to document toggle)
function toggleBookingDetails(bookingId) {
    const detailsDiv = document.getElementById(`bookingDetails-${bookingId}`);
    const expandIcon = document.getElementById(`expandIconBooking-${bookingId}`);
    
    if (detailsDiv.style.display === 'none') {
        // Close any other open booking details
        document.querySelectorAll('.booking-request-details').forEach(detail => {
            detail.style.display = 'none';
        });
        document.querySelectorAll('.expand-icon').forEach(icon => {
            icon.style.transform = 'rotate(0deg)';
        });
        
        detailsDiv.style.display = 'block';
        if (expandIcon) expandIcon.style.transform = 'rotate(180deg)';
        window.expandedBookingId = bookingId;
    } else {
        detailsDiv.style.display = 'none';
        if (expandIcon) expandIcon.style.transform = 'rotate(0deg)';
        window.expandedBookingId = null;
    }
}



// ============ SHOW BOOKING DETAILS MODAL ============
function showBookingDetails(bookingId) {
    // Find the booking from currentBookingsList
    const booking = currentBookingsList.find(b => b.id === bookingId);
    
    if (!booking) {
        showErrorModal('Booking details not found');
        return;
    }
    
    const requestDate = new Date(booking.request_date);
    const startDateTime = new Date(booking.start_datetime);
    const endDateTime = new Date(booking.end_datetime);
    
    let categoryIcon = 'fa-tools';
    let categoryTitle = 'Equipment';
    if (booking.category === 'facility') {
        categoryIcon = 'fa-building';
        categoryTitle = 'Facility';
    } else if (booking.category === 'vehicle') {
        categoryIcon = 'fa-truck';
        categoryTitle = 'Vehicle';
    }
    
    let statusClass = '';
    let statusIcon = '';
    let statusText = booking.status.charAt(0).toUpperCase() + booking.status.slice(1);
    switch(booking.status) {
        case 'approved': statusClass = 'status-approved'; statusIcon = '<i class="fas fa-check-circle"></i>'; break;
        case 'borrowed': statusClass = 'status-approved'; statusIcon = '<i class="fas fa-hand-holding"></i>'; statusText = 'Borrowed'; break;
        case 'pending': statusClass = 'status-pending'; statusIcon = '<i class="fas fa-clock"></i>'; statusText = 'Pending approval'; break;
        case 'rejected': statusClass = 'status-rejected'; statusIcon = '<i class="fas fa-times-circle"></i>'; break;
        case 'completed': statusClass = 'status-completed'; statusIcon = '<i class="fas fa-check-double"></i>'; break;
        default: statusClass = 'status-approved'; statusIcon = '<i class="fas fa-check-circle"></i>';
    }
    
    let detailsHtml = '';
    
    if (booking.category === 'equipment') {
        const bookingDate = new Date(booking.booking_date);
        const returnDate = new Date(booking.return_date);
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Schedule Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Booking Date</label>
                        <span>${bookingDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Return Date</label>
                        <span>${returnDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Duration</label>
                        <span>${booking.duration_days} day(s)</span>
                    </div>
                    <div class="detail-item">
                        <label>Quantity</label>
                        <span>${booking.quantity} item(s)</span>
                    </div>
                </div>
            </div>
        `;
    } else if (booking.category === 'facility') {
        const facilityDate = new Date(booking.booking_date);
        const startTime = booking.start_time ? booking.start_time.substring(0, 5) : 'N/A';
        const endTime = booking.end_time ? booking.end_time.substring(0, 5) : 'N/A';
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Schedule Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Date</label>
                        <span>${facilityDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Time</label>
                        <span>${startTime} - ${endTime}</span>
                    </div>
                    <div class="detail-item">
                        <label>Duration</label>
                        <span>${booking.duration_hours} hour(s)</span>
                    </div>
                    ${booking.expected_attendees ? `<div class="detail-item"><label>Expected Attendees</label><span>${booking.expected_attendees}</span></div>` : ''}
                    ${booking.event_type ? `<div class="detail-item"><label>Event Type</label><span>${escapeHtml(booking.event_type)}</span></div>` : ''}
                </div>
            </div>
        `;
    } else if (booking.category === 'vehicle') {
        const tripDate = new Date(booking.trip_date);
        
        detailsHtml = `
            <div class="details-section">
                <h4><i class="fas fa-calendar-alt"></i> Trip Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Trip Date</label>
                        <span>${tripDate.toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Pickup Time</label>
                        <span>${booking.pickup_time ? booking.pickup_time.substring(0, 5) : 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Pickup Location</label>
                        <span>${escapeHtml(booking.pickup_location || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Drop-off Location</label>
                        <span>${escapeHtml(booking.dropoff_location || 'N/A')}</span>
                    </div>
                    <div class="detail-item">
                        <label>Passengers</label>
                        <span>${booking.passenger_count || 1}</span>
                    </div>
                    <div class="detail-item">
                        <label>Estimated Duration</label>
                        <span>${booking.estimated_hours} hour(s)</span>
                    </div>
                    <div class="detail-item">
                        <label>Vehicle Capacity</label>
                        <span>${booking.vehicle_capacity} passengers</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Build modal body HTML
    let modalHtml = `
        <div style="margin-bottom: 20px;">
            <div class="selected-doc-info" style="background: #e8f5e9;">
                <div>
                    <strong><i class="fas ${categoryIcon}"></i> ${categoryTitle}:</strong> ${escapeHtml(booking.equipment_name || booking.facility_name || booking.vehicle_name || 'N/A')}
                    <br>
                    <small style="color: #666;">Reference: ${booking.item_global_id || 'N/A'} | Request #${booking.id}</small>
                </div>
                <span class="status-badge ${statusClass}">${statusIcon} ${statusText}</span>
            </div>
        </div>
        
        <div class="details-section">
            <h4><i class="fas fa-info-circle"></i> Request Information</h4>
            <div class="details-grid">
                <div class="detail-item">
                    <label>Request Date</label>
                    <span>${requestDate.toLocaleString()}</span>
                </div>
                <div class="detail-item">
                    <label>Status</label>
                    <span class="status-badge ${statusClass}">${statusIcon} ${statusText}</span>
                </div>
            </div>
        </div>
        
        ${detailsHtml}
    `;
    
    // Add approval information if approved
    if (booking.status === 'approved' && booking.approved_by_name) {
        modalHtml += `
            <div class="details-section">
                <h4><i class="fas fa-check-circle" style="color: #28a745;"></i> Approval Information</h4>
                <div class="details-grid">
                    <div class="detail-item">
                        <label>Approved By</label>
                        <span>${escapeHtml(booking.approved_by_name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Approved At</label>
                        <span>${booking.approved_at ? new Date(booking.approved_at).toLocaleString() : 'N/A'}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Add purpose
    if (booking.purpose) {
        modalHtml += `
            <div class="details-section">
                <h4><i class="fas fa-comment"></i> Purpose</h4>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.purpose)}</p>
                </div>
            </div>
        `;
    }
    
    // Add notes from specific request tables
    if (booking.notes) {
        modalHtml += `
            <div class="details-section">
                <h4><i class="fas fa-sticky-note"></i> Notes</h4>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.notes)}</p>
                </div>
            </div>
        `;
    }
    
    // Add admin notes
    if (booking.admin_notes) {
        modalHtml += `
            <div class="details-section">
                <h4><i class="fas fa-user-shield"></i> Admin Response</h4>
                <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #2196f3;">
                    <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.admin_notes)}</p>
                    <small style="color: #666; display: block; margin-top: 8px;"><i class="fas fa-clock"></i> ${booking.processed_at ? new Date(booking.processed_at).toLocaleString() : 'N/A'}</small>
                </div>
            </div>
        `;
    }
    
    // Add special requests for vehicles
    if (booking.special_requests) {
        modalHtml += `
            <div class="details-section">
                <h4><i class="fas fa-star"></i> Special Requests</h4>
                <div style="background: #fff8e1; padding: 15px; border-radius: 8px; border-left: 4px solid #ff9800;">
                    <p style="margin: 0; white-space: pre-wrap;">${escapeHtml(booking.special_requests)}</p>
                </div>
            </div>
        `;
    }
    
    const modalBody = document.getElementById('bookingDetailsBody');
    if (!modalBody) {
        console.error('Booking details modal not found');
        return;
    }
    
    modalBody.innerHTML = modalHtml;
    
    const modal = document.getElementById('bookingDetailsModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeBookingDetailsModal() {
    const modal = document.getElementById('bookingDetailsModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Make functions globally available
window.showBookingDetails = showBookingDetails;
window.closeBookingDetailsModal = closeBookingDetailsModal;



function getStatusIcon(status) {
    switch(status) {
        case 'approved': return '<i class="fas fa-check-circle"></i>';
        case 'pending': return '<i class="fas fa-clock"></i>';
        case 'rejected': return '<i class="fas fa-times-circle"></i>';
        case 'borrowed': return '<i class="fas fa-hand-holding"></i>';
        case 'completed': return '<i class="fas fa-check-double"></i>';
        default: return '<i class="fas fa-check-circle"></i>';
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
        // Always render as plain text — callers pass plain strings.
        messageEl.textContent = message;
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.display = 'none';
            messageEl.textContent = '';
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
        setTimeout(() => { modal.style.display = 'none'; }, 3000);
    } else {
        alert(message);
    }
}

function showConfirmModal(message, warning, onConfirm) {
    const modal = document.getElementById('confirmModal');
    const messageEl = document.getElementById('confirmMessage');
    const warningEl = document.getElementById('confirmWarning');
    const confirmBtn = document.getElementById('confirmYesBtn');
    
    if (messageEl) messageEl.innerHTML = message;
    if (warningEl) warningEl.style.display = warning ? 'block' : 'none';
    
    window._pendingConfirmCallback = onConfirm;
    
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    newConfirmBtn.addEventListener('click', function() {
        if (window._pendingConfirmCallback) {
            window._pendingConfirmCallback();
            window._pendingConfirmCallback = null;
        }
        closeConfirmModal();
    });
    
    if (modal) modal.style.display = 'flex';
}

// ============================================================
//  VEHICLE WIZARD — 3-step cache-first flow
// ============================================================

var VEHICLE_PURPOSES_V2 = [
    { code:'medical_appointment', label:'Medical appointment or hospital checkup',        tier:'flexible',  group:'Medical' },
    { code:'dialysis_treatment',  label:'Dialysis or scheduled medical treatment',        tier:'flexible',  group:'Medical' },
    { code:'hospital_discharge',  label:'Hospital discharge or return home',              tier:'flexible',  group:'Medical' },
    { code:'urgent_medical',      label:'Urgent medical assistance',                      tier:'emergency', group:'Medical' },
    { code:'senior_assistance',   label:'Senior citizen transportation assistance',       tier:'scheduled', group:'Assistance' },
    { code:'pwd_assistance',      label:'PWD transportation assistance',                  tier:'scheduled', group:'Assistance' },
    { code:'government_office',   label:'Government office or social service appointment',tier:'scheduled', group:'Government' },
    { code:'court_hearing',       label:'Court hearing or official legal appointment',    tier:'scheduled', group:'Government' },
    { code:'funeral',             label:'Funeral or bereavement assistance',              tier:'flexible',  group:'Other' },
    { code:'disaster_evacuation', label:'Disaster evacuation or emergency assistance',    tier:'emergency', group:'Other' },
    { code:'family_crisis',       label:'Transportation assistance during a family crisis',tier:'urgent',   group:'Other' },
    { code:'other',               label:'Other valid reason (requires explanation)',       tier:'other',     group:'Other' },
];

var VEHICLE_TIER_TEXT_V2 = {
    emergency: 'Approved immediately. Can be booked for today, any hour.',
    urgent:    'Approved immediately. Can be booked for today.',
    flexible:  'Auto-approved when the vehicle is free. Needs at least 2 hours notice.',
    scheduled: 'Auto-approved when the vehicle is free. Needs at least 24 hours notice, within office hours.',
    other:     'Needs approval from the Captain or Secretary.'
};

function vehiclePurposeOptionsV2Html() {
    const groups = {};
    VEHICLE_PURPOSES_V2.forEach(p => { (groups[p.group] = groups[p.group] || []).push(p); });
    return Object.keys(groups).map(g =>
        `<optgroup label="${g}">${groups[g].map(p => `<option value="${p.code}">${escapeHtml(p.label)}</option>`).join('')}</optgroup>`
    ).join('');
}

function showVehicleTripForm() {
    const dashboardBody = document.getElementById('dashboardBody');
    const today = new Date().toISOString().split('T')[0];

    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-truck"></i> Request a Barangay Vehicle</div>
            <div class="section-sub">Tell us about your trip — we'll match you with the best vehicle and time slot.</div>

            <form id="vehicleTripForm" onsubmit="submitVehicleTripForm(event)">
                <div class="booking-form-group">
                    <label class="required">Reason for the trip <span style="color:red;">*</span></label>
                    <select id="vReason" required style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;">
                        <option value="">-- Select a reason --</option>
                        ${vehiclePurposeOptionsV2Html()}
                    </select>
                    <small id="vReasonHint" style="display:none;margin-top:6px;"></small>
                </div>

                <div class="booking-form-group" id="vOtherWrap" style="display:none;">
                    <label class="required">Please explain your reason <span style="color:red;">*</span></label>
                    <textarea id="vOtherReason" rows="3" maxlength="300"
                              style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;"
                              placeholder="Describe why you need the vehicle (at least 10 characters)..."></textarea>
                    <div id="vOtherFeedback" style="font-size:12px;margin-top:6px;"></div>
                </div>

                <div class="booking-form-group">
                    <label class="required">Destination <span style="color:red;">*</span></label>
                    <input type="text" id="vDestination" required
                           style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;"
                           placeholder="e.g., Sto. Tomas Hospital">
                </div>

                <div class="booking-form-group">
                    <label class="required">Pick-up Location <span style="color:red;">*</span></label>
                    <input type="text" id="vPickup" required
                           style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;"
                           placeholder="e.g., Barangay Hall, San Bartolome">
                </div>

                <div class="booking-form-group">
                    <label class="required">Date <span style="color:red;">*</span></label>
                    <input type="date" id="vDate" required min="${today}" value="${today}"
                           style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;">
                </div>

                <div class="booking-form-group" id="vCustomTimeWrap" style="display:none;">
                    <label class="required">Pick-up time <span style="color:red;">*</span></label>
                    <input type="time" id="vCustomTime" step="900"
                           style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;">
                    <small style="color:#666;">
                        For a funeral, you decide the exact time.
                        Available between <strong>8:00 AM</strong> and <strong>5:00 PM</strong>.
                        The vehicle must be back by 5:00 PM.
                    </small>
                </div>

                <div class="booking-form-group" id="vPassengersWrap">
                    <label class="required">Number of passengers <span style="color:red;">*</span></label>
                    <input type="number" id="vPassengers" required min="1" value="1"
                           style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;">
                    <small id="vPassengersHint" style="display:none;color:#2e7d32;"></small>
                </div>

                <div class="booking-form-group">
                    <label>Special requests (optional)</label>
                    <textarea id="vSpecial" rows="2" maxlength="300"
                              style="width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;"
                              placeholder="e.g., wheelchair access needed"></textarea>
                </div>

                <div class="form-buttons" style="display:flex;gap:15px;margin-top:25px;">
                    <button type="button" class="btn-secondary" onclick="loadEquipmentList()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button type="submit" class="btn-primary" id="vContinueBtn">
                        <i class="fas fa-arrow-right"></i> Find Vehicle & Slot
                    </button>
                </div>
            </form>
        </div>
    `;

    initVehicleTripForm();
}

function initVehicleTripForm() {
    const sel = document.getElementById('vReason');
    const hint = document.getElementById('vReasonHint');
    const otherWrap = document.getElementById('vOtherWrap');
    const otherInput = document.getElementById('vOtherReason');
    const feedback = document.getElementById('vOtherFeedback');
    const continueBtn = document.getElementById('vContinueBtn');

    let debounceTimer = null;

    sel.addEventListener('change', () => {
        const p = VEHICLE_PURPOSES_V2.find(x => x.code === sel.value);

        const customWrap = document.getElementById('vCustomTimeWrap');
        const paxWrap    = document.getElementById('vPassengersWrap');
        const paxInput   = document.getElementById('vPassengers');
        const paxHint    = document.getElementById('vPassengersHint');
        const timeInput  = document.getElementById('vCustomTime');

        if (!p) {
            hint.style.display = 'none';
            otherWrap.style.display = 'none';
            if (customWrap) customWrap.style.display = 'none';
            if (paxWrap)    paxWrap.style.display    = 'block';
            if (paxInput)   paxInput.disabled        = false;
            if (paxHint) { paxHint.style.display = 'none'; paxHint.textContent = ''; }
            if (timeInput) {
                timeInput.removeAttribute('min');
                timeInput.removeAttribute('max');
                timeInput.removeAttribute('title');
            }
            return;
        }

        hint.style.display = 'block';
        hint.style.color = p.tier === 'emergency' ? '#c62828' : (p.tier === 'other' ? '#e65100' : '#2e7d32');
        hint.textContent = VEHICLE_TIER_TEXT_V2[p.tier];
        otherWrap.style.display = p.tier === 'other' ? 'block' : 'none';

        const fullVeh = isFullVehicleReason(sel.value);

        if (customWrap) customWrap.style.display = fullVeh ? 'block' : 'none';
        if (paxWrap)    paxWrap.style.display    = 'block';
        if (paxInput)   paxInput.disabled        = fullVeh;
        if (paxHint) {
            paxHint.style.display = fullVeh ? 'block' : 'none';
            paxHint.textContent   = fullVeh
                ? 'The whole vehicle is reserved — all seats will be assigned automatically.'
                : '';
        }

        // Funeral window on the time input.
        if (timeInput) {
            if (fullVeh) {
                timeInput.min = VEHICLE_FUNERAL_WINDOW.start;
                timeInput.max = VEHICLE_FUNERAL_WINDOW.end;
                timeInput.title =
                    `Funeral trips must be between ` +
                    `${formatTimeToAMPM(VEHICLE_FUNERAL_WINDOW.start)} and ` +
                    `${formatTimeToAMPM(VEHICLE_FUNERAL_WINDOW.end)}.`;
            } else {
                timeInput.removeAttribute('min');
                timeInput.removeAttribute('max');
                timeInput.removeAttribute('title');
            }
        }
    });

    otherInput?.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const val = otherInput.value.trim();
        if (val.length < 5) { feedback.innerHTML = ''; continueBtn.disabled = false; return; }
        feedback.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
        debounceTimer = setTimeout(async () => {
            try {
                const r = await fetch('resident_equipment_ajax.php', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'vehicle_text_check', text: val })
                });
                const d = await r.json();
                if (d.ok) {
                    feedback.innerHTML = '<span style="color:#2e7d32;"><i class="fas fa-check-circle"></i> Looks good.</span>';
                    continueBtn.disabled = false;
                } else {
                    feedback.innerHTML = '<span style="color:#c62828;"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(d.message) + '</span>';
                    continueBtn.disabled = true;
                }
            } catch (e) {
                feedback.innerHTML = '';
                continueBtn.disabled = false;
            }
        }, 350);
    });
}

async function submitVehicleTripForm(e) {
    e.preventDefault();
    const reason_code = document.getElementById('vReason').value;
    const other_reason = (document.getElementById('vOtherReason')?.value || '').trim();
    const destination = document.getElementById('vDestination').value.trim();
    const pickup_location = document.getElementById('vPickup').value.trim();
    const date = document.getElementById('vDate').value;
    const passengers = parseInt(document.getElementById('vPassengers').value || 1);
    const special_requests = (document.getElementById('vSpecial')?.value || '').trim();
    const custom_time = (document.getElementById('vCustomTime')?.value || '').trim();

    if (!reason_code)  return showErrorModal('Please pick a reason.');
    if (!destination)  return showErrorModal('Please enter a destination.');
    if (!pickup_location) return showErrorModal('Please enter a pickup location.');
    if (!date)         return showErrorModal('Please pick a date.');
    if (passengers < 1) return showErrorModal('At least 1 passenger.');

    if (isFullVehicleReason(reason_code)) {
        if (!custom_time) {
            return showErrorModal('Please choose a pick-up time.');
        }
        const winStart = VEHICLE_FUNERAL_WINDOW.start;
        const winEnd   = VEHICLE_FUNERAL_WINDOW.end;

        const [h, m]   = custom_time.split(':').map(Number);
        const startMin = h * 60 + m;
        const endMin   = startMin + 120;   // VEH_TRIP_HOURS = 2
        const toHm = (min) =>
            String(Math.floor(min / 60)).padStart(2, '0') + ':' +
            String(min % 60).padStart(2, '0');

        const startHm = toHm(startMin);
        const endHm   = toHm(endMin);

        if (startHm < winStart || endHm > winEnd) {
            return showErrorModal(
                `Funeral / bereavement trips must be between ` +
                `${formatTimeToAMPM(winStart)} and ${formatTimeToAMPM(winEnd)}. ` +
                `Please pick a time that starts at or after ` +
                `${formatTimeToAMPM(winStart)} and finishes by ` +
                `${formatTimeToAMPM(winEnd)}.`
            );
        }
    }

    const btn = document.getElementById('vContinueBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    try {
        const r1 = await fetch('resident_equipment_ajax.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                action:'vehicle_draft_save',
                reason_code, other_reason, destination, pickup_location,
                date, passengers, special_requests, custom_time
            })
        });
        const d1 = await r1.json();
        if (!d1.success) throw new Error(d1.message || 'Could not save.');

        window.__vehToken = d1.token;
        localStorage.setItem('vehicle_draft_token', d1.token);
        showVehicleChecking();
    } catch (err) {
        showErrorModal(err.message || 'Error saving trip.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Find Vehicle & Slot';
    }
}

function showVehicleChecking() {
    const dashboardBody = document.getElementById('dashboardBody');
    dashboardBody.innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-search"></i> Checking availability…</div>
            <div style="padding:30px 20px;text-align:center;">
                <div id="checkStep1" style="margin:12px 0;color:#666;">
                    <i class="fas fa-spinner fa-spin"></i> Validating reason…
                </div>
                <div id="checkStep2" style="margin:12px 0;color:#666;">
                    <i class="fas fa-spinner fa-spin"></i> Checking date & household…
                </div>
                <div id="checkStep3" style="margin:12px 0;color:#666;">
                    <i class="fas fa-spinner fa-spin"></i> Matching vehicles and time slots…
                </div>
                <p style="color:#888;font-size:12px;margin-top:20px;">Nothing has been booked yet.</p>
            </div>
        </div>
    `;
    runVehicleCheck();
}

async function runVehicleCheck() {
    try {
        const r = await fetch('resident_equipment_ajax.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'vehicle_check', token: window.__vehToken })
        });
        const d = await r.json();

        const ok1 = !(d.errors && d.errors.length && !d.success);
        document.getElementById('checkStep1').innerHTML = ok1
            ? '<i class="fas fa-check-circle" style="color:#2e7d32;"></i> Validated'
            : '<i class="fas fa-times-circle" style="color:#c62828;"></i> Failed';
        document.getElementById('checkStep2').innerHTML =
            '<i class="fas fa-check-circle" style="color:#2e7d32;"></i> Date & household checked';
        document.getElementById('checkStep3').innerHTML =
            '<i class="fas fa-check-circle" style="color:#2e7d32;"></i> Slots matched';

        if (!d.success) {
            const errHtml = (d.errors || []).map(x => `<li>${escapeHtml(x)}</li>`).join('');
            dashboardBody.innerHTML = `
                <div class="content-card">
                    <div class="section-title" style="color:#c62828;"><i class="fas fa-exclamation-triangle"></i> We can't process this request</div>
                    <ul style="margin:20px 0 0 20px;color:#b71c1c;line-height:1.7;">${errHtml}</ul>
                    <div class="form-buttons" style="margin-top:24px;display:flex;gap:12px;">
                        <button class="btn-secondary" onclick="showVehicleTripForm()"><i class="fas fa-arrow-left"></i> Edit details</button>
                    </div>
                </div>`;
            return;
        }
        if (!d.candidates || !d.candidates.length) {
            dashboardBody.innerHTML = `
                <div class="content-card">
                    <div class="section-title" style="color:#e65100;"><i class="fas fa-calendar-times"></i> No vehicles available on that date</div>
                    <p style="padding:20px 0;color:#666;">Try another date — the vehicle may already be booked.</p>
                    <button class="btn-secondary" onclick="showVehicleTripForm()"><i class="fas fa-arrow-left"></i> Change date</button>
                </div>`;
            return;
        }
        await showVehicleRecommendation(d);
    } catch (err) {
        dashboardBody.innerHTML = `<div class="content-card"><p>Error: ${escapeHtml(err.message)}</p></div>`;
    }
}
async function showVehicleRecommendation(checkResult) {
    const r = await fetch('resident_equipment_ajax.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'vehicle_recommend', token: window.__vehToken })
    });
    const d = await r.json();
    if (!d.success) return showErrorModal(d.message || 'No recommendation.');

    const rec     = d.recommended;
    const alts    = d.alternatives || [];
    const draft   = checkResult.draft || {};
    const needPax = parseInt(draft.passengers || 1);

    // ── Helpers ────────────────────────────────────────────
    const vehFmtTime = (hm) => {
        if (!hm) return '';
        const [hStr, m] = String(hm).split(':');
        let h = parseInt(hStr, 10);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${h}:${m} ${ampm}`;
    };
    const slotTimeLabel = (slot) =>
        slot.label || `${vehFmtTime(slot.start)} – ${vehFmtTime(slot.end)}`;

    const slotSeatsLeft = (vehicle, slot) => {
        if (slot.type === 'share') {
            return parseInt(slot.seats_left ?? (vehicle.capacity - (slot.taken || 0)));
        }
        return vehicle.capacity;
    };
    const slotIsBookable = (vehicle, slot) => slotSeatsLeft(vehicle, slot) >= needPax;

    // Group alternatives by vehicle (recommended first)
    const byVehicle = {};
    [rec, ...alts].forEach(entry => {
        const key = entry.vehicle.global_id;
        if (!byVehicle[key]) byVehicle[key] = { vehicle: entry.vehicle, slots: [] };
        byVehicle[key].slots.push(entry.slot);
    });
    Object.values(byVehicle).forEach(v => {
        v.slots.sort((a, b) => a.start.localeCompare(b.start));
    });

    // ── Slot chip (right column) — click to expand ─────────
    const renderSlotChip = (v, slot, idx) => {
        const bookable  = slotIsBookable(v, slot);
        const seatsLeft = slotSeatsLeft(v, slot);
        const isShare   = slot.type === 'share';
        const rowId     = `slot-exp-${v.global_id}-${idx}`;

        const rowBg     = bookable ? '#fff' : '#fbeaea';
        const rowBorder = bookable ? '#e2efe8' : '#ef9a9a';
        const iconColor = bookable ? '#2e7d32' : '#c62828';
        const iconCls   = bookable ? 'fa-check-circle' : 'fa-ban';
        const cursor    = bookable ? 'pointer' : 'not-allowed';

        const warn = !bookable
            ? `<div style="margin-top:8px;padding:8px 10px;background:#ffebee;
                           border-left:3px solid #c62828;border-radius:6px;
                           font-size:12px;color:#b71c1c;line-height:1.5;">
                   <i class="fas fa-exclamation-triangle"></i>
                   Only <b>${seatsLeft}</b> seat${seatsLeft===1?'':'s'} left — you need <b>${needPax}</b>.
                   <a href="#" onclick="event.preventDefault();showVehicleTripForm();"
                      style="color:#1565c0;text-decoration:underline;margin-left:4px;">
                       Edit passengers
                   </a>
               </div>`
            : '';

        return `
            <div class="vslot-row" style="margin-bottom:8px;
                    border:1.5px solid ${rowBorder};border-radius:10px;
                    background:${rowBg};overflow:hidden;transition:all .15s;">

                <div class="vslot-head"
                     data-toggle="${rowId}"
                     data-bookable="${bookable ? '1' : '0'}"
                     style="display:flex;justify-content:space-between;align-items:center;
                            gap:10px;padding:11px 14px;cursor:${cursor};">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        <i class="fas fa-clock" style="color:#1a472a;font-size:0.95rem;"></i>
                        <div style="min-width:0;">
                            <div style="font-weight:700;color:#1a2b22;font-size:14px;">
                                ${escapeHtml(slotTimeLabel(slot))}
                            </div>
                            <div style="margin-top:2px;font-size:10.5px;font-weight:700;color:#666;
                                        text-transform:uppercase;letter-spacing:0.3px;">
                                ${isShare ? '<i class="fas fa-users"></i> Shared trip' : '<i class="fas fa-bus"></i> Open trip'}
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                        <span style="font-size:11.5px;font-weight:700;color:${iconColor};">
                            ${seatsLeft} left
                        </span>
                        <i class="fas ${iconCls}" style="color:${iconColor};font-size:1rem;"></i>
                        <i class="fas fa-chevron-down vslot-arrow" data-arrow="${rowId}"
                           style="color:#888;transition:transform .2s;font-size:0.85rem;"></i>
                    </div>
                </div>

                <div id="${rowId}" class="vslot-body" style="display:none;
                        padding:12px 14px;background:#fafbfa;border-top:1px dashed ${rowBorder};">
                    <div style="font-size:13px;color:#333;line-height:1.6;margin-bottom:5px;">
                        <i class="fas fa-users" style="color:#2e7d32;margin-right:5px;"></i>
                        ${isShare
                            ? `<b>${seatsLeft}</b> of ${v.capacity} seats still available`
                            : `Whole vehicle free · <b>${v.capacity}</b> seats`}
                    </div>
                    <div style="font-size:13px;color:#333;line-height:1.6;margin-bottom:5px;">
                        <i class="fas fa-user" style="color:#2e7d32;margin-right:5px;"></i>
                        Your booking: <b>${needPax}</b> passenger${needPax===1?'':'s'}
                    </div>
                    <div style="font-size:13px;color:#333;line-height:1.6;margin-bottom:8px;">
                        <i class="fas fa-info-circle" style="color:#2e7d32;margin-right:5px;"></i>
                        Trip duration: <b>2 hours</b>
                    </div>
                    ${warn}
                    ${bookable ? `
                        <button type="button" class="btn-primary confirm-slot-btn"
                                data-veh="${v.global_id}" data-start="${slot.start}"
                                data-end="${slot.end}" data-type="${slot.type}"
                                data-vname="${escapeHtml(v.name)}"
                                style="margin-top:8px;padding:10px 20px;border-radius:9px;
                                       font-weight:700;font-size:13.5px;">
                            <i class="fas fa-check-circle"></i> Confirm This Slot
                        </button>
                    ` : ''}
                </div>
            </div>`;
    };

    // ── Vehicle card (left column) — Shopee-style ──────────
    const renderVehicleCard = (v, isRecommended) => {
        const imagePath = v.image ? '../' + v.image : null;
        let defaultIcon = 'fa-shuttle-van';
        const lname = v.name.toLowerCase();
        if (lname.includes('ambulance'))   defaultIcon = 'fa-ambulance';
        else if (lname.includes('truck'))  defaultIcon = 'fa-truck-pickup';
        else if (lname.includes('motor'))  defaultIcon = 'fa-motorcycle';
        else if (lname.includes('car'))    defaultIcon = 'fa-car';

        const recommendedPill = isRecommended
            ? `<span style="position:absolute;top:10px;right:10px;z-index:3;
                            background:#2e7d32;color:#fff;padding:4px 12px;
                            border-radius:20px;font-size:10px;font-weight:800;
                            letter-spacing:0.6px;display:inline-flex;
                            align-items:center;gap:5px;
                            box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                   <i class="fas fa-star"></i> RECOMMENDED
               </span>`
            : '';

        const statusPill = `
            <span style="position:absolute;top:10px;left:10px;z-index:3;
                         background:#2e7d32;color:#fff;padding:4px 10px;
                         border-radius:20px;font-size:0.7rem;font-weight:700;">
                <i class="fas fa-user-friends"></i> ${v.capacity} seats
            </span>`;

        return `
            <div class="equipment-card"
                 style="border:${isRecommended ? '2px solid #2e7d32' : '1px solid #e2efe8'};
                        border-radius:16px;overflow:hidden;background:#fff;
                        box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                <div class="equipment-card-image"
                     style="position:relative;width:100%;aspect-ratio:16/10;
                            overflow:hidden;background:linear-gradient(135deg,#f1f8e9,#e8f5e9);">
                    ${imagePath
                        ? `<img src="${imagePath}" alt="${escapeHtml(v.name)}"
                                style="width:100%;height:100%;object-fit:cover;"
                                onerror="this.onerror=null;
                                         this.parentElement.innerHTML=
                                         '<div class=\\'image-fallback\\' style=\\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;\\'><i class=\\'fas ${defaultIcon}\\' style=\\'font-size:3.5rem;color:#43a047;\\'></i></div>';">
                          </img>`
                        : `<div class="image-fallback"
                               style="display:flex;align-items:center;justify-content:center;
                                      width:100%;height:100%;">
                               <i class="fas ${defaultIcon}" style="font-size:3.5rem;color:#43a047;"></i>
                           </div>`}
                    ${statusPill}
                    ${recommendedPill}
                </div>

                <div class="equipment-card-content" style="padding:14px 16px 10px;">
                    <div class="equipment-card-title"
                         style="font-size:1.05rem;font-weight:800;color:#1a472a;">
                        ${escapeHtml(v.name)}
                    </div>
                    <div class="equipment-card-category"
                         style="font-size:0.7rem;color:#7a8a82;text-transform:uppercase;
                                letter-spacing:0.5px;font-weight:700;margin-top:2px;">
                        Vehicle
                    </div>
                    <div class="equipment-card-desc"
                         style="font-size:0.82rem;color:#7a8a82;line-height:1.5;margin-top:8px;">
                        ${escapeHtml(v.description || 'Barangay vehicle for official trips and emergencies.')}
                    </div>
                </div>

                <div class="equipment-card-footer"
                     style="padding:10px 14px;background:#f8faf8;border-top:1px solid #e2efe8;
                            display:flex;justify-content:space-between;align-items:center;
                            gap:10px;flex-wrap:wrap;">
                    <div style="color:#2e7d32;font-weight:600;font-size:0.82rem;">
                        <i class="fas fa-check-circle"></i>
                        Available
                    </div>
                    <div style="font-size:0.78rem;color:#666;">
                        <i class="fas fa-user-friends"></i> ${v.capacity} seats
                    </div>
                </div>
            </div>`;
    };

    // ── Two-column block per vehicle ───────────────────────
    const renderVehicleBlock = (vEntry, isRecommended) => {
        const v = vEntry.vehicle;
        const slotsHtml = vEntry.slots.length
            ? vEntry.slots.map((slot, i) => renderSlotChip(v, slot, i)).join('')
            : '<p style="color:#888;font-size:13px;">No slots available for this vehicle.</p>';

        return `
            <div style="display:grid;grid-template-columns:minmax(220px, 1fr) minmax(280px, 1.2fr);
                        gap:18px;margin-bottom:20px;align-items:start;">

                <!-- LEFT: vehicle card -->
                <div>${renderVehicleCard(v, isRecommended)}</div>

                <!-- RIGHT: time slot chips -->
                <div>
                    <div style="font-size:12px;color:#888;text-transform:uppercase;
                                letter-spacing:0.4px;font-weight:700;margin-bottom:10px;">
                        <i class="fas fa-clock"></i> Choose a time slot
                    </div>
                    ${slotsHtml}
                </div>
            </div>`;
    };

    const blocksHtml = Object.values(byVehicle)
        .map((vEntry, i) => renderVehicleBlock(vEntry, i === 0))
        .join('');

    // ── Whole screen ───────────────────────────────────────
    dashboardBody.innerHTML = `
        <div class="content-card" style="border:2px solid #2e7d32;">
            <div class="section-title" style="color:#2e7d32;">
                <i class="fas fa-check-circle"></i> We recommend
            </div>
            <div style="margin-top:6px;font-size:13px;color:#555;">
                Urgency: <b>${escapeHtml((d.urgency || 'scheduled').toUpperCase())}</b>
                ${d.urgency === 'other'
                    ? '— a Captain or Secretary will review your request.'
                    : '— this will be auto-approved.'}
            </div>

            <div style="margin-top:18px;">
                ${blocksHtml}
            </div>

            <div class="form-buttons" style="display:flex;gap:12px;margin-top:8px;">
                <button class="btn-secondary" onclick="showVehicleTripForm()">
                    <i class="fas fa-arrow-left"></i> Edit trip
                </button>
            </div>
        </div>

        <!-- Small responsive tweak: stack columns on mobile -->
        <style>
            @media (max-width: 640px) {
                #dashboardBody [style*="grid-template-columns:minmax(220px, 1fr)"] {
                    grid-template-columns: 1fr !important;
                }
            }
        </style>
    `;

    // ── Funeral: offer a "use my own time" button above the slots ──
    if (isFullVehicleReason(draft.reason_code) && draft.custom_time) {
        const startHm = draft.custom_time;
        const [h, m]  = startHm.split(':').map(Number);
        const total   = h * 60 + m + 120;                 // VEH_TRIP_HOURS = 2
        const endHm   = String(Math.floor(total / 60)).padStart(2, '0') + ':' +
                        String(total % 60).padStart(2, '0');

        const wrap = document.createElement('div');
        wrap.style.cssText = 'margin:16px 0;padding:14px;background:#e8f5e9;' +
                             'border-left:4px solid #2e7d32;border-radius:8px;';
        wrap.innerHTML = `
            <div style="font-weight:700;color:#1a472a;margin-bottom:6px;">
                <i class="fas fa-clock"></i> Your requested time
            </div>
            <div style="font-size:14px;color:#333;margin-bottom:10px;">
                ${escapeHtml(draft.date)} at ${escapeHtml(vehFmtTime(startHm))} – ${escapeHtml(vehFmtTime(endHm))}
            </div>
            <button class="btn-primary" id="useCustomTimeBtn"
                    style="padding:10px 20px;border-radius:9px;font-weight:700;">
                <i class="fas fa-check-circle"></i> Book this time
            </button>`;
        const formButtons = dashboardBody.querySelector('.form-buttons');
        if (formButtons) formButtons.parentNode.insertBefore(wrap, formButtons);

        const veh = rec.vehicle;
        document.getElementById('useCustomTimeBtn')?.addEventListener('click', () => {
            commitVehicleChoice(veh.global_id, startHm, endHm, 'open');
        });
    }

    // ── Wire the expandable slot chips ────────────────────
    dashboardBody.querySelectorAll('.vslot-head').forEach(head => {
        head.addEventListener('click', () => {
            const toggleId = head.dataset.toggle;
            const body     = document.getElementById(toggleId);
            const arrow    = dashboardBody.querySelector(`[data-arrow="${toggleId}"]`);
            if (!body) return;

            const isOpen = body.style.display === 'block';

            dashboardBody.querySelectorAll('.vslot-body').forEach(b => b.style.display = 'none');
            dashboardBody.querySelectorAll('.vslot-arrow').forEach(a => a.style.transform = '');

            if (!isOpen) {
                body.style.display = 'block';
                if (arrow) arrow.style.transform = 'rotate(180deg)';
            }
        });
    });

    // ── Wire "Confirm This Slot" ──────────────────────────
    dashboardBody.querySelectorAll('.confirm-slot-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const vehId = btn.dataset.veh;
            const vName = btn.dataset.vname || vehId;
            const start = btn.dataset.start;
            const end   = btn.dataset.end;
            const type  = btn.dataset.type;

            const nice = `${vehFmtTime(start)} – ${vehFmtTime(end)}`;
            Swal.fire({
                title: 'Confirm this trip?',
                html: `
                    <div style="text-align:left;font-size:0.9rem;line-height:1.7;">
                        <div><b>Vehicle:</b> ${escapeHtml(vName)}</div>
                        <div><b>Time:</b> ${escapeHtml(nice)}</div>
                        <div><b>Passengers:</b> ${needPax}</div>
                    </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Yes, book it',
                confirmButtonColor: '#2E7D32',
                cancelButtonColor: '#6c757d',
                cancelButtonText: 'Cancel'
            }).then(res => {
                if (res.isConfirmed) {
                    commitVehicleChoice(vehId, start, end, type);
                }
            });
        });
    });

    window.__vehRec = rec;
}
function toggleVehicleAlts() {
    const w = document.getElementById('vehicleAltsWrap');
    if (w) w.style.display = w.style.display === 'none' ? 'block' : 'none';
}

async function commitVehicleChoice(vehicleGlobalId, slotStart, slotEnd, slotType) {
    try {
        const r = await fetch('resident_equipment_ajax.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                action:'vehicle_commit',
                token: window.__vehToken,
                vehicle_global_id: vehicleGlobalId,
                slot_start: slotStart,
                slot_end: slotEnd,
                slot_type: slotType
            })
        });
        const d = await r.json();

        if (!d.success) {
            // Show debug info if the server included it
            if (d.debug) {
                console.error('Commit failed:', d.message, d.debug);
            }
            // Offer a "try another" shortcut when the slot filled up
            const msg = d.message || 'Could not book.';
            const showRetry = /filled|unavailable|capacity|enough|notice|already booked|no longer available/i.test(msg);
            if (showRetry) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Slot unavailable',
                    text: msg,
                    confirmButtonText: 'Pick another slot',
                    confirmButtonColor: '#2E7D32'
                }).then(() => {
                    runVehicleCheck();   // re-fetch slots
                });
            } else {
                showErrorModal(msg);
            }
            return;
        }

        localStorage.removeItem('vehicle_draft_token');
        const statusMsg = d.auto_approved
            ? `Booking confirmed! Reference: ${d.reference}`
            : `Submitted for review. Reference: ${d.reference}`;
        showSuccessModal(statusMsg);
        setTimeout(() => loadMyBookings(), 1500);
    } catch (err) {
        showErrorModal('Network error.');
    }
}

// expose
window.showVehicleTripForm = showVehicleTripForm;
window.submitVehicleTripForm = submitVehicleTripForm;
window.toggleVehicleAlts = toggleVehicleAlts;
window.commitVehicleChoice = commitVehicleChoice;

function closeConfirmModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) modal.style.display = 'none';
    window._pendingConfirmCallback = null;
}

// Make functions globally available
window.loadEquipmentList = loadEquipmentList;
window.loadMyBookings = loadMyBookings;
window.filterBookings = filterBookings;
window.loadVehicleAvailability = loadVehicleAvailability;
window.updateVehicleConflictHint = updateVehicleConflictHint;
window.filterEquipmentByCategory = filterEquipmentByCategory;
window.submitEquipmentBooking = submitEquipmentBooking;
window.submitFacilityBooking = submitFacilityBooking;
window.submitVehicleBooking = submitVehicleBooking;