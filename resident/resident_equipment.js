// resident_equipment.js - Updated for new database structure with separate tables
// Cancel/pending functionality removed - all bookings are auto-approved

let currentEquipmentList = [];
let currentEquipmentTypes = [];
let currentBookingsList = [];
let currentSelectedEquipment = null;
let currentBookingFilter = 'all';

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
            <div class="equipment-card" onclick="showBookingForm('${identifier}', '${escapeHtml(type.name)}', '${type.category}', ${type.available_count || 0}, ${type.capacity || 0})">
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
                        <button class="book-equipment-btn" onclick="event.stopPropagation(); showBookingForm('${identifier}', '${escapeHtml(type.name)}', '${type.category}', ${type.available_count || 0}, ${type.capacity || 0})">
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
        const maxPassengers = capacity || 12;
        
        formHtml = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-calendar-alt"></i> Book: ${escapeHtml(name)}</div>
                <div class="section-sub">Fill in trip details for vehicle booking</div>
                
                <div class="booking-info-panel" style="background: #fff3e0; margin-bottom: 20px; padding: 15px; border-radius: 12px;">
                    <i class="fas fa-info-circle" style="color: #e65100;"></i>
                    <span>Vehicle capacity: <strong>${maxPassengers} passengers</strong>. Please provide complete trip details.</span>
                </div>
                
                <form id="bookingForm" onsubmit="submitVehicleBooking(event)">
                    <div class="booking-form-group">
                        <label class="required">Select Date <span style="color:red;">*</span></label>
                        <div id="vehicleCalendar" style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2efe8;"></div>
                        <input type="hidden" id="selectedDate" name="booking_date" required>
                        <small>Click on a date to select it</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Pick-up Time <span style="color:red;">*</span></label>
                        <input type="time" id="pickupTime" class="form-control" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #ddd;" required>
                        <small>What time should the vehicle pick you up?</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Pick-up Location <span style="color:red;">*</span></label>
                        <input type="text" id="pickupLocation" class="form-control" placeholder="e.g., Barangay Hall, specific address" required>
                        <small>Where should the vehicle pick you up?</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Drop-off Location <span style="color:red;">*</span></label>
                        <input type="text" id="dropoffLocation" class="form-control" placeholder="e.g., Destination address" required>
                        <small>Where is your destination?</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Number of Passengers <span style="color:red;">*</span></label>
                        <input type="number" id="passengerCount" min="1" max="${maxPassengers}" value="1" class="form-control" required>
                        <small>Maximum capacity: ${maxPassengers} passengers</small>
                        <div id="passengerError" style="color:red; font-size:12px; display:none;"></div>
                    </div>
                    
                    <div class="booking-form-group">
                        <label class="required">Estimated Duration <span style="color:red;">*</span></label>
                        <select id="durationHours" name="duration_hours" required>
                            <option value="1">1 Hour</option>
                            <option value="2">2 Hours</option>
                            <option value="3">3 Hours</option>
                            <option value="4">4 Hours</option>
                            <option value="5">5 Hours</option>
                            <option value="6">6 Hours</option>
                            <option value="7">7 Hours</option>
                            <option value="8">8 Hours</option>
                        </select>
                        <small>Estimated duration of the trip</small>
                    </div>
                    
                    <div class="booking-form-group">
                        <label>Special Requests (Optional)</label>
                        <textarea id="specialRequests" rows="2" class="form-control" placeholder="Any special requests or additional information..."></textarea>
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
        renderMiniCalendar('vehicleCalendar', todayStr);
        
        const pickupTimeInput = document.getElementById('pickupTime');
        if (pickupTimeInput) {
            const now = new Date();
            const nextHour = new Date(now.getTime() + 60 * 60 * 1000);
            const defaultTime = `${String(nextHour.getHours()).padStart(2, '0')}:00`;
            pickupTimeInput.value = defaultTime;
        }
        
        const passengerInput = document.getElementById('passengerCount');
        if (passengerInput) {
            passengerInput.addEventListener('input', function() {
                const count = parseInt(this.value) || 0;
                const maxPass = maxPassengers;
                const errorDiv = document.getElementById('passengerError');
                if (count > maxPass) {
                    errorDiv.style.display = 'block';
                    errorDiv.innerHTML = `Maximum ${maxPass} passengers allowed.`;
                    this.value = maxPass;
                } else if (count < 1) {
                    errorDiv.style.display = 'block';
                    errorDiv.innerHTML = 'At least 1 passenger required.';
                    this.value = 1;
                } else {
                    errorDiv.style.display = 'none';
                }
            });
        }
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
        // Disable if date is today or in the past
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
                    <span><i class="fas fa-calendar-day" style="color: #ff9800;"></i> Today (disabled)</span>
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


// ============ SUBMIT VEHICLE BOOKINGS ============
async function submitVehicleBooking(event) {
    event.preventDefault();
    
    const bookingDate = document.getElementById('selectedDate').value;
    const pickupTime = document.getElementById('pickupTime').value;
    const pickupLocation = document.getElementById('pickupLocation').value;
    const dropoffLocation = document.getElementById('dropoffLocation').value;
    const passengerCount = parseInt(document.getElementById('passengerCount').value);
    const durationHours = parseInt(document.getElementById('durationHours').value);
    const specialRequests = document.getElementById('specialRequests')?.value;
    
    if (!bookingDate) {
        showErrorModal('Please select a booking date');
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
    
    // Format date and time for display
    const formattedDate = new Date(bookingDate).toLocaleDateString();
    const formattedPickupTime = formatTimeToAMPM(pickupTime);
    
    // Show confirmation before submitting
    let detailsHtml = `
        <div style="text-align: left; font-size: 14px;">
            <p><i class="fas fa-truck" style="color: #2eaa5e; width: 25px;"></i> <strong>Vehicle:</strong> ${escapeHtml(currentSelectedEquipment.name)}</p>
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
            <p style="color: #ff9800;"><i class="fas fa-info-circle"></i> Please review your booking details before submitting.</p>
        </div>`;
    
    showConfirmModal('Confirm Vehicle Booking', detailsHtml, async function() {
        await executeVehicleBooking(bookingDate, pickupTime, pickupLocation, dropoffLocation, passengerCount, durationHours, specialRequests);
    });
}

async function executeVehicleBooking(bookingDate, pickupTime, pickupLocation, dropoffLocation, passengerCount, durationHours, specialRequests) {
    const startDateTime = `${bookingDate}T${pickupTime}:00`;
    const endDateObj = new Date(startDateTime);
    endDateObj.setHours(endDateObj.getHours() + durationHours);
    const endDateTime = endDateObj.toISOString().slice(0, 19);
    
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
                purpose: 'Vehicle transport request',
                custom_data: {
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
            showSuccessModal(`✅ Vehicle booking approved successfully! Reference: ${data.reference}`);
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

// ============ LOAD MY BOOKINGS ============
async function loadMyBookings() {
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
                <div class="section-title"><i class="fas fa-calendar-alt"></i> My Bookings</div>
                <div class="section-sub">View and track your booking requests</div>
                <div class="filter-buttons" style="margin-bottom: 20px;">
                    <button class="filter-btn ${currentBookingFilter === 'all' ? 'active' : ''}" onclick="filterBookings('all')">All</button>
                    <button class="filter-btn ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="filterBookings('approved')">Approved</button>
                    <button class="filter-btn ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="filterBookings('borrowed')">Borrowed</button>
                    <button class="filter-btn ${currentBookingFilter === 'completed' ? 'active' : ''}" onclick="filterBookings('completed')">Completed</button>
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
            <button class="filter-btn ${currentBookingFilter === 'approved' ? 'active' : ''}" onclick="filterBookings('approved')">Approved</button>
            <button class="filter-btn ${currentBookingFilter === 'borrowed' ? 'active' : ''}" onclick="filterBookings('borrowed')">Borrowed</button>
            <button class="filter-btn ${currentBookingFilter === 'completed' ? 'active' : ''}" onclick="filterBookings('completed')">Completed</button>
        </div>
    `;
    
    let filteredBookings = bookings;
    if (currentBookingFilter !== 'all') {
        filteredBookings = bookings.filter(b => b.status === currentBookingFilter);
    }
    
    const bookingsHtml = filteredBookings.map(booking => {
        return generateBookingCardHtml(booking);
    }).join('');
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-calendar-alt"></i> My Bookings</div>
            <div class="section-sub">Click on any booking to view full details</div>
            ${filterButtons}
            <div class="bookings-list">
                ${filteredBookings.length === 0 ? `<div class="empty-state"><i class="fas fa-inbox"></i><p>No ${currentBookingFilter} bookings found.</p></div>` : bookingsHtml}
            </div>
        </div>
    `;
}



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
        case 'approved': statusClass = 'status-approved'; statusIcon = '<i class="fas fa-check-circle"></i> '; break;
        case 'borrowed': statusClass = 'status-approved'; statusIcon = '<i class="fas fa-hand-holding"></i> '; statusText = 'Borrowed'; break;
        case 'completed': statusClass = 'status-completed'; statusIcon = '<i class="fas fa-check-double"></i> '; break;
        default: statusClass = 'status-approved'; statusIcon = '<i class="fas fa-check-circle"></i> ';
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

function filterBookings(status) {
    currentBookingFilter = status;
    renderMyBookings(currentBookingsList);
}

function getStatusIcon(status) {
    switch(status) {
        case 'approved': return '<i class="fas fa-check-circle"></i>';
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
        messageEl.innerHTML = message;
        modal.style.display = 'flex';
        setTimeout(() => { modal.style.display = 'none'; }, 3000);
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

function closeConfirmModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) modal.style.display = 'none';
    window._pendingConfirmCallback = null;
}

// Make functions globally available
window.loadEquipmentList = loadEquipmentList;
window.loadMyBookings = loadMyBookings;
window.filterBookings = filterBookings;
window.filterEquipmentByCategory = filterEquipmentByCategory;
window.submitEquipmentBooking = submitEquipmentBooking;
window.submitFacilityBooking = submitFacilityBooking;
window.submitVehicleBooking = submitVehicleBooking;