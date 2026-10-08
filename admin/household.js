// household.js - Excel-like Editable Household Table with Validation

// Global state
let currentHouseholdPurok = 'all';
let currentHouseholdPage = 1;
let currentHouseholdTotalPages = 1;
let currentHouseholdData = [];
let currentHouseholdSearch = '';

// ============ RENDER HOUSEHOLD VIEW ============

function renderHouseholdView() {
    return `
        <div class="household-module">
            <div class="content-card">
                <div class="section-title">
                    <div>
                        <i class="fas fa-home"></i> Household Management
                        <span style="font-size:12px; color:#6c757d; margin-left:10px;">Click cells to edit, press Enter to save</span>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn-add-household" onclick="addNewHouseholdRow()" style="background:#17a2b8;">
                            <i class="fas fa-plus"></i> Add Household
                        </button>
                        <button class="btn-add-household" onclick="saveAllRows()" style="background:#28a745;">
                            <i class="fas fa-save"></i> Save All
                        </button>
                        <button class="btn-add-household" onclick="validateAllRows()" style="background:#ff9800;">
                            <i class="fas fa-check-double"></i> Validate
                        </button>
                    </div>
                </div>
                <div class="section-sub">Click any cell to edit. Press Enter to save, Escape to cancel. Add new rows at the bottom.</div>
                
                <!-- Filter Bar -->
                <div class="household-filter-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-map-marker-alt"></i> Purok:</label>
                        <select id="purokFilter" onchange="filterHouseholdsByPurok()">
                            <option value="all">All Puroks</option>
                        </select>
                    </div>
                    <div class="search-group">
                        <input type="text" id="householdSearchInput" placeholder="Search..." oninput="searchHouseholds()">
                        <i class="fas fa-search"></i>
                    </div>
                    <button onclick="loadHouseholds(currentHouseholdPurok, 1, currentHouseholdSearch)" style="padding:6px 16px; border-radius:8px; border:1px solid #dee2e6; background:white; cursor:pointer;">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
                
                <!-- Stats -->
                <div class="household-stats" id="householdStats">
                    <div class="stat-item">
                        <span class="stat-label">Total Households</span>
                        <span class="stat-number" id="totalHouseholds">-</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Total Members</span>
                        <span class="stat-number" id="totalMembers">-</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Puroks</span>
                        <span class="stat-number" id="totalPuroks">-</span>
                    </div>
                </div>
                
                <!-- Excel-Style Editable Table with Address Merged -->
                <div class="table-responsive">
                    <table class="household-table" id="householdTable">
                        <thead>
                            <tr>
                                <th style="min-width:50px; width:50px;">#</th>
                                <th style="min-width:70px; width:80px;">Purok</th>
                                <th style="min-width:120px; width:140px;">Household Address</th>
                                <th style="min-width:70px; width:80px;">Members</th>
                                <th style="min-width:100px;">Last Name</th>
                                <th style="min-width:90px;">First Name</th>
                                <th style="min-width:90px;">Middle Name</th>
                                <th style="min-width:50px;">Ext</th>
                                <th style="min-width:120px;">Place of Birth</th>
                                <th style="min-width:110px;">Date of Birth</th>
                                <th style="min-width:40px;">Age</th>
                                <th style="min-width:40px;">Sex</th>
                                <th style="min-width:90px;">Civil Status</th>
                                <th style="min-width:100px;">Citizenship</th>
                                <th style="min-width:120px;">Occupation</th>
                                <th style="min-width:130px;">Employment Status</th>
                                <th style="min-width:70px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="householdTableBody">
                            <tr>
                                <td colspan="17" class="text-center">
                                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div id="householdPagination"></div>
            </div>
        </div>
        
        <!-- Date Picker Modal -->
        <div id="datePickerModal" class="modal">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">
                    <h2><i class="fas fa-calendar-alt"></i> Select Date</h2>
                    <button class="close-modal" onclick="closeDatePicker()">&times;</button>
                </div>
                <div class="modal-body" id="datePickerBody">
                    <div class="form-group">
                        <label>Year</label>
                        <select id="datePickerYear" class="form-control" onchange="updateDatePickerDay()">
                            <!-- Filled by JS -->
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group half">
                            <label>Month</label>
                            <select id="datePickerMonth" class="form-control" onchange="updateDatePickerDay()">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                        <div class="form-group half">
                            <label>Day</label>
                            <select id="datePickerDay" class="form-control">
                                <!-- Filled by JS -->
                            </select>
                        </div>
                    </div>
                    <div class="form-buttons" style="margin-top:15px;">
                        <button class="btn-verify" onclick="confirmDatePicker()"><i class="fas fa-check"></i> Set Date</button>
                        <button class="btn-close" onclick="closeDatePicker()">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sex Dropdown Modal -->
        <div id="sexDropdownModal" class="modal">
            <div class="modal-content" style="max-width: 350px;">
                <div class="modal-header">
                    <h2><i class="fas fa-venus-mars"></i> Select Sex</h2>
                    <button class="close-modal" onclick="closeSexDropdown()">&times;</button>
                </div>
                <div class="modal-body" id="sexDropdownBody">
                    <div style="display:flex; gap:20px; justify-content:center; padding:20px 0;">
                        <button class="sex-option-btn" onclick="selectSex('M')" style="background: #4a90d9; color: white; padding: 20px 40px; border: none; border-radius: 12px; font-size: 24px; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-mars" style="display:block; font-size: 40px; margin-bottom: 10px;"></i>
                            Male
                        </button>
                        <button class="sex-option-btn" onclick="selectSex('F')" style="background: #e91e63; color: white; padding: 20px 40px; border: none; border-radius: 12px; font-size: 24px; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-venus" style="display:block; font-size: 40px; margin-bottom: 10px;"></i>
                            Female
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Confirmation Modal -->
        <div id="confirmationModal" class="modal" style="display:none; z-index:2000;">
            <div class="modal-content" style="max-width: 450px; margin-top: 15%;">
                <div class="modal-header" style="background: #ff9800;">
                    <h2 id="confirmationModalTitle"><i class="fas fa-question-circle"></i> Confirm</h2>
                    <button class="close-modal" onclick="closeConfirmationModal()">&times;</button>
                </div>
                <div class="modal-body" id="confirmationModalBody" style="text-align:center; padding: 30px;">
                    <div style="font-size: 48px; margin-bottom: 15px; color: #ff9800;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p id="confirmationModalMessage" style="font-size: 16px; color: #333; line-height: 1.6;">Are you sure?</p>
                </div>
                <div class="modal-footer" style="display:flex; gap:10px; justify-content:center; padding: 15px 24px; border-top: 1px solid #eef2ef;">
                    <button class="btn-confirm" id="confirmationModalConfirm" style="background: #ff9800; color: white; border: none; padding: 10px 30px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                        Yes, Proceed
                    </button>
                    <button class="btn-close" onclick="closeConfirmationModal()" style="background: #6c757d; color: white; border: none; padding: 10px 30px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    `;
}

// ============ CAPITALIZATION FUNCTION ============

function capitalizeText(text) {
    if (!text) return '';
    
    // Trim extra spaces
    text = text.trim();
    
    // Handle special cases like "Sto Tomas", "San Bartolome"
    // Split by space or comma
    var words = text.split(/([\s,]+)/);
    var result = [];
    
    for (var i = 0; i < words.length; i++) {
        var word = words[i];
        // Skip if it's a space or comma separator
        if (/^[\s,]+$/.test(word)) {
            result.push(word);
            continue;
        }
        
        // Handle special abbreviations (keep as is)
        var specialAbbr = ['Sto', 'San', 'Santa', 'Santo', 'Sta', 'Sr', 'Jr', 'Dr', 'Mr', 'Mrs', 'Ms'];
        var lowerWord = word.toLowerCase();
        
        // Check if it's a special abbreviation
        var isSpecial = specialAbbr.some(function(abbr) {
            return lowerWord === abbr.toLowerCase();
        });
        
        if (isSpecial) {
            // Find the correct capitalization from special list
            var matched = specialAbbr.find(function(abbr) {
                return abbr.toLowerCase() === lowerWord;
            });
            result.push(matched);
        } else {
            // Capitalize first letter, rest lowercase
            result.push(word.charAt(0).toUpperCase() + word.slice(1).toLowerCase());
        }
    }
    
    return result.join('');
}

function capitalizeNameField(value) {
    if (!value) return '';
    return capitalizeText(value);
}

function capitalizePlaceOfBirth(value) {
    if (!value) return '';
    // Split by comma for multiple locations
    var parts = value.split(',');
    var result = [];
    for (var i = 0; i < parts.length; i++) {
        result.push(capitalizeText(parts[i].trim()));
    }
    return result.join(', ');
}

function capitalizeCivilStatus(value) {
    if (!value) return '';
    var validStatus = ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'];
    var lowerValue = value.toLowerCase();
    
    // Find matching valid status
    var matched = validStatus.find(function(status) {
        return status.toLowerCase() === lowerValue;
    });
    
    return matched || capitalizeText(value);
}

function capitalizeEmploymentStatus(value) {
    if (!value) return '';
    var validEmployment = ['Employed', 'Unemployed', 'Solo Parent', 'OSY', 'OSC', 'Student', 'Retired'];
    var lowerValue = value.toLowerCase();
    
    // Find matching valid employment status
    var matched = validEmployment.find(function(status) {
        return status.toLowerCase() === lowerValue;
    });
    
    return matched || capitalizeText(value);
}

// ============ CONFIRMATION MODAL FUNCTIONS ============

let confirmationCallback = null;

function showConfirmationModal(message, title, onConfirm) {
    // Remove any existing confirmation modal
    const existing = document.querySelector('.confirmation-modal');
    if (existing) existing.remove();
    
    // Create modal dynamically (works even if #confirmationModal isn't in DOM)
    const modal = document.createElement('div');
    modal.className = 'confirmation-modal';
    modal.id = 'confirmationModal';
    modal.style.cssText = 'display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:2000; align-items:center; justify-content:center;';
    modal.innerHTML = `
        <div class="confirmation-modal-content" style="background:white; border-radius:28px; max-width:450px; width:90%; text-align:center; padding:30px 25px; animation:slideDown 0.3s ease; box-shadow:0 25px 50px rgba(0,0,0,0.3);">
            <div class="notification-icon warning" style="font-size:48px; margin-bottom:15px; color:#ff9800;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="notification-title" style="font-size:1.3rem; font-weight:700; color:#1a472a; margin-bottom:12px;">
                ${escapeHtml(title || 'Confirm')}
            </div>
            <div class="notification-message" style="color:#5f7f6e; margin-bottom:25px; line-height:1.5;">
                ${escapeHtml(message)}
            </div>
            <div class="confirmation-buttons" style="display:flex; gap:15px; justify-content:center; margin-top:25px;">
                <button class="confirmation-btn-confirm" id="confirmYesBtn" style="background:#ff9800; color:white; border:none; padding:10px 25px; border-radius:40px; font-weight:600; cursor:pointer; transition:all 0.2s;">
                    Yes, Proceed
                </button>
                <button class="confirmation-btn-cancel" id="confirmNoBtn" style="background:#6c757d; color:white; border:none; padding:10px 25px; border-radius:40px; font-weight:600; cursor:pointer; transition:all 0.2s;">
                    Cancel
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    confirmationCallback = onConfirm;
    
    document.getElementById('confirmYesBtn').addEventListener('click', function() {
        modal.remove();
        if (typeof confirmationCallback === 'function') {
            confirmationCallback();
        }
        confirmationCallback = null;
    });
    
    document.getElementById('confirmNoBtn').addEventListener('click', function() {
        modal.remove();
        confirmationCallback = null;
    });
}

function closeConfirmationModal() {
    const modal = document.querySelector('.confirmation-modal');
    if (modal) modal.remove();
    confirmationCallback = null;
}

function confirmAction() {
    if (typeof confirmationCallback === 'function') {
        confirmationCallback();
    }
    closeConfirmationModal();
}

// ============ DATE PICKER FUNCTIONS ============

let datePickerTarget = null;
let datePickerOriginalValue = '';

function openDatePicker(cell) {
    datePickerTarget = cell;
    datePickerOriginalValue = cell.textContent.trim();
    
    var modal = document.getElementById('datePickerModal');
    var yearSelect = document.getElementById('datePickerYear');
    var monthSelect = document.getElementById('datePickerMonth');
    var daySelect = document.getElementById('datePickerDay');
    
    // Populate years (1900 to current year)
    yearSelect.innerHTML = '';
    var currentYear = new Date().getFullYear();
    for (var y = currentYear; y >= 1900; y--) {
        var option = document.createElement('option');
        option.value = y;
        option.textContent = y;
        yearSelect.appendChild(option);
    }
    
    // Set default to current date or existing value
    var existingDate = datePickerOriginalValue;
    var year = currentYear;
    var month = new Date().getMonth() + 1;
    var day = new Date().getDate();
    
    if (existingDate) {
        var parts = existingDate.split('-');
        if (parts.length === 3) {
            year = parseInt(parts[0]) || currentYear;
            month = parseInt(parts[1]) || 1;
            day = parseInt(parts[2]) || 1;
        }
    }
    
    yearSelect.value = year;
    monthSelect.value = month;
    updateDatePickerDay();
    daySelect.value = day;
    
    modal.style.display = 'block';
}

function updateDatePickerDay() {
    var month = parseInt(document.getElementById('datePickerMonth').value);
    var year = parseInt(document.getElementById('datePickerYear').value);
    var daySelect = document.getElementById('datePickerDay');
    
    // Get days in month
    var daysInMonth = new Date(year, month, 0).getDate();
    
    daySelect.innerHTML = '';
    for (var d = 1; d <= daysInMonth; d++) {
        var option = document.createElement('option');
        option.value = d;
        option.textContent = d;
        daySelect.appendChild(option);
    }
}

function confirmDatePicker() {
    if (!datePickerTarget) return;
    
    var year = document.getElementById('datePickerYear').value;
    var month = String(document.getElementById('datePickerMonth').value).padStart(2, '0');
    var day = String(document.getElementById('datePickerDay').value).padStart(2, '0');
    
    var dateStr = year + '-' + month + '-' + day;
    datePickerTarget.textContent = dateStr;
    datePickerTarget.dataset.originalValue = dateStr;
    
    // Calculate age automatically
    calculateAgeFromDate(datePickerTarget);
    
    // Trigger change detection
    var row = datePickerTarget.closest('tr');
    if (row) {
        row.classList.add('changed');
        row.dataset.changed = 'true';
    }
    
    closeDatePicker();
    // Re-validate the row
    validateRow(row);
}

function closeDatePicker() {
    document.getElementById('datePickerModal').style.display = 'none';
    datePickerTarget = null;
}

// ============ SEX DROPDOWN FUNCTIONS ============

let sexDropdownTarget = null;

function openSexDropdown(cell) {
    sexDropdownTarget = cell;
    document.getElementById('sexDropdownModal').style.display = 'block';
}

function selectSex(sex) {
    if (!sexDropdownTarget) return;
    
    sexDropdownTarget.textContent = sex;
    sexDropdownTarget.dataset.originalValue = sex;
    
    var row = sexDropdownTarget.closest('tr');
    if (row) {
        row.classList.add('changed');
        row.dataset.changed = 'true';
        validateRow(row);
    }
    
    closeSexDropdown();
}

function closeSexDropdown() {
    document.getElementById('sexDropdownModal').style.display = 'none';
    sexDropdownTarget = null;
}

// ============ AGE CALCULATION ============

function calculateAgeFromDate(dateCell) {
    var dateStr = dateCell.textContent.trim();
    if (!dateStr) return;
    
    var parts = dateStr.split('-');
    if (parts.length !== 3) return;
    
    var year = parseInt(parts[0]);
    var month = parseInt(parts[1]);
    var day = parseInt(parts[2]);
    
    if (isNaN(year) || isNaN(month) || isNaN(day)) return;
    
    var birthDate = new Date(year, month - 1, day);
    var today = new Date();
    
    var age = today.getFullYear() - birthDate.getFullYear();
    var m = today.getMonth() - birthDate.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    
    // Find the age cell in the same row
    var row = dateCell.closest('tr');
    if (!row) return;
    
    var ageCell = row.querySelector('[data-field="age"]');
    if (ageCell) {
        ageCell.textContent = age > 0 ? age : 0;
        ageCell.dataset.originalValue = age > 0 ? age : 0;
    }
}

// ============ LOAD HOUSEHOLDS ============

async function loadHouseholds(purok = 'all', page = 1, search = '') {
    currentHouseholdPurok = purok;
    currentHouseholdPage = page;
    currentHouseholdSearch = search;
    
    try {
        let url = 'household_ajax.php?action=get_households&page=' + page + '&per_page=50';
        if (purok && purok !== 'all') url += '&purok=' + encodeURIComponent(purok);
        if (search) url += '&search=' + encodeURIComponent(search);
        
        var response = await fetch(url);
        var data = await response.json();
        
        if (data.success) {
            currentHouseholdData = data.data;
            currentHouseholdTotalPages = data.totalPages;
            
            renderEditableTable(data);
            renderHouseholdPagination(data);
            updateHouseholdStats(data);
            loadPurokFilter(data.purokCounts || {});
        }
    } catch (error) {
        console.error('Error loading households:', error);
        document.getElementById('householdTableBody').innerHTML = '<tr><td colspan="17" class="text-center">Error loading data. Please refresh.</td></tr>';
    }
}

// ============ RENDER EDITABLE TABLE WITH ADDRESS MERGED ============

function renderEditableTable(data) {
    var tbody = document.getElementById('householdTableBody');
    
    if (!data.data || data.data.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="17" class="text-center">
                    <div class="empty-state">
                        <i class="fas fa-home fa-3x"></i>
                        <p>No households found. Click "Add Household" to create one.</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    var html = '';
    var rowNumber = ((data.currentPage - 1) * data.perPage) + 1;
    var displayCount = 0;
    
    // Group data by address and purok
    var addressGroups = {};
    data.data.forEach(function(item) {
        var key = (item.household_address || '') + '|' + (item.purok || '');
        if (!addressGroups[key]) {
            addressGroups[key] = {
                address: item.household_address || '',
                purok: item.purok || '',
                households: []
            };
        }
        // Add household if not already in the group
        var existing = addressGroups[key].households.find(function(h) {
            return h.household_id === item.household_id;
        });
        if (!existing && item.household_id) {
            addressGroups[key].households.push({
                household_id: item.household_id,
                no_of_members: item.no_of_members || 0,
                members: []
            });
        }
        // Add member
        if (item.member_id) {
            var household = addressGroups[key].households.find(function(h) {
                return h.household_id === item.household_id;
            });
            if (household) {
                household.members.push(item);
            }
        }
    });
    
    // Render each address group
    var addressKeys = Object.keys(addressGroups);
    addressKeys.forEach(function(key) {
        var group = addressGroups[key];
        var totalMembersInAddress = 0;
        group.households.forEach(function(h) {
            totalMembersInAddress += h.members.length || 0;
        });
        
        var isFirstAddressRow = true;
        var addressRowspan = totalMembersInAddress || 1;
        var purokRowspan = addressRowspan;
        
        // For each household in this address
        group.households.forEach(function(household, householdIndex) {
            var members = household.members;
            var memberCount = members.length || 0;
            
            if (members.length === 0) {
                // Household with no members
                displayCount++;
                var showPurok = isFirstAddressRow && householdIndex === 0;
                var showAddress = isFirstAddressRow && householdIndex === 0;
                
                html += `
                    <tr class="address-group-row" data-household-id="${household.household_id}" data-member-id="" data-address="${escapeHtml(group.address)}" data-purok="${escapeHtml(group.purok)}">
                        ${showAddress ? '<td class="row-number" rowspan="' + addressRowspan + '">' + displayCount + '</td>' : ''}
                        ${showPurok ? '<td class="editable-cell purok-cell" data-field="purok" rowspan="' + purokRowspan + '" contenteditable="true">' + escapeHtml(group.purok) + '</td>' : ''}
                        ${showAddress ? '<td class="editable-cell household-address-cell" data-field="household_address" rowspan="' + addressRowspan + '" contenteditable="true">' + escapeHtml(group.address) + '</td>' : ''}
                        <td class="member-count-cell"><span class="member-count-badge">0</span></td>
                        <td colspan="12" style="color:#6c757d; font-style:italic;">No members - click + to add</td>
                        <td>
                            <button class="btn-action delete-household" onclick="deleteHousehold(${household.household_id})" title="Delete Household">
                                <i class="fas fa-home"></i>
                            </button>
                            <button class="btn-action add" onclick="addMemberRow(${household.household_id})" title="Add Member">
                                <i class="fas fa-user-plus"></i>
                            </button>
                        </td>
                    </tr>
                `;
                isFirstAddressRow = false;
                return;
            }
            
            // Render each member
            members.forEach(function(member, memberIndex) {
                displayCount++;
                var showAddress = isFirstAddressRow && householdIndex === 0 && memberIndex === 0;
                var showPurok = isFirstAddressRow && householdIndex === 0 && memberIndex === 0;
                var showMemberCount = memberIndex === 0;
                var rowNum = (memberIndex === 0 && householdIndex === 0) ? displayCount : '';
                
                // Calculate rowspan
                var addressRowspanTotal = totalMembersInAddress || 1;
                var purokRowspanTotal = addressRowspanTotal;
                var memberCountRowspan = memberCount || 1;
                
                var showAddressCell = isFirstAddressRow && householdIndex === 0 && memberIndex === 0;
                var addressRowspanAttr = showAddressCell ? ' rowspan="' + addressRowspanTotal + '"' : '';
                var purokRowspanAttr = showPurok ? ' rowspan="' + purokRowspanTotal + '"' : '';
                var memberCountRowspanAttr = showMemberCount ? ' rowspan="' + memberCountRowspan + '"' : '';
                var memberCountValue = showMemberCount ? '<span class="member-count-badge">' + memberCount + '</span>' : '';
                
                var displayDate = '';
                if (member.date_of_birth) {
                    displayDate = member.date_of_birth;
                }
                
                // Calculate age from date of birth for display
                var displayAge = member.age || '';
                if (member.date_of_birth) {
                    var calcAge = calculateAgeFromDateStr(member.date_of_birth);
                    if (calcAge !== null) {
                        displayAge = calcAge;
                    }
                }
                
                html += `
                    <tr class="${showAddressCell ? 'address-first-row' : 'address-member-row'} 
                              ${memberIndex === 0 ? 'household-first-member' : ''}" 
                        data-household-id="${household.household_id}" 
                        data-member-id="${member.member_id}"
                        data-address="${escapeHtml(group.address)}"
                        data-purok="${escapeHtml(group.purok)}">
                        
                        ${showAddressCell ? '<td class="row-number" ' + addressRowspanAttr + '>' + rowNum + '</td>' : ''}
                        ${showPurok ? '<td class="editable-cell purok-cell" data-field="purok" ' + purokRowspanAttr + ' contenteditable="true">' + escapeHtml(group.purok) + '</td>' : ''}
                        ${showAddressCell ? '<td class="editable-cell household-address-cell" data-field="household_address" ' + addressRowspanAttr + ' contenteditable="true">' + escapeHtml(group.address) + '</td>' : ''}
                        ${showMemberCount ? '<td class="member-count-cell" ' + memberCountRowspanAttr + '>' + memberCountValue + '</td>' : ''}
                        
                        <td class="editable-cell" data-field="last_name" contenteditable="true">${escapeHtml(member.last_name || '')}</td>
                        <td class="editable-cell" data-field="first_name" contenteditable="true">${escapeHtml(member.first_name || '')}</td>
                        <td class="editable-cell" data-field="middle_name" contenteditable="true">${escapeHtml(member.middle_name || '')}</td>
                        <td class="editable-cell" data-field="ext" contenteditable="true">${escapeHtml(member.ext || '')}</td>
                        <td class="editable-cell" data-field="place_of_birth" contenteditable="true">${escapeHtml(member.place_of_birth || '')}</td>
                        <td class="editable-cell date-cell" data-field="date_of_birth" contenteditable="true" onclick="openDatePicker(this)">${displayDate}</td>
                        <td class="editable-cell age-cell" data-field="age">${displayAge}</td>
                        <td class="editable-cell sex-cell" data-field="sex" contenteditable="false" onclick="openSexDropdown(this)">${escapeHtml(member.sex || '')}</td>
                        <td class="editable-cell" data-field="civil_status" contenteditable="true">${escapeHtml(member.civil_status || '')}</td>
                        <td class="editable-cell" data-field="citizenship" contenteditable="true">${escapeHtml(member.citizenship || '')}</td>
                        <td class="editable-cell" data-field="occupation" contenteditable="true">${escapeHtml(member.occupation || '')}</td>
                        <td class="editable-cell" data-field="employment_status" contenteditable="true">${escapeHtml(member.employment_status || '')}</td>
                        <td>
                            ${memberIndex === 0 ? `
                                <button class="btn-action delete-household" onclick="deleteHousehold(${member.household_id})" title="Delete Household">
                                    <i class="fas fa-home"></i>
                                </button>
                            ` : ''}
                            <button class="btn-action delete" onclick="deleteRow(${member.household_id}, ${member.member_id})" title="Delete Member">
                                <i class="fas fa-trash"></i>
                            </button>
                            ${memberIndex === 0 ? '<button class="btn-action add" onclick="addMemberRow(' + member.household_id + ')" title="Add Member"><i class="fas fa-user-plus"></i></button>' : ''}
                        </td>
                    </tr>
                `;
            });
            
            isFirstAddressRow = false;
        });
    });
    
    // Add a new empty row at the bottom for adding new household
    html += `
        <tr class="new-household-row">
            <td></td>
            <td class="editable-cell purok-cell" data-field="purok" contenteditable="true" placeholder="Purok"></td>
            <td class="editable-cell household-address-cell" data-field="household_address" contenteditable="true" placeholder="House Address"></td>
            <td></td>
            <td colspan="13" style="color:#6c757d; font-style:italic;">Enter Purok and Household Address, then press Enter to add new household</td>
        </tr>
    `;
    
    tbody.innerHTML = html;
    
    // Attach event listeners for inline editing
    attachInlineEditEvents();
}

// ============ AGE CALCULATION HELPER ============

function calculateAgeFromDateStr(dateStr) {
    if (!dateStr) return null;
    var parts = dateStr.split('-');
    if (parts.length !== 3) return null;
    
    var year = parseInt(parts[0]);
    var month = parseInt(parts[1]);
    var day = parseInt(parts[2]);
    
    if (isNaN(year) || isNaN(month) || isNaN(day)) return null;
    if (year < 1900 || year > new Date().getFullYear()) return null;
    
    var birthDate = new Date(year, month - 1, day);
    var today = new Date();
    
    var age = today.getFullYear() - birthDate.getFullYear();
    var m = today.getMonth() - birthDate.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    
    return age > 0 ? age : 0;
}

// ============ INLINE EDITING ============

function attachInlineEditEvents() {
    var editableCells = document.querySelectorAll('.editable-cell[contenteditable="true"]');
    
    editableCells.forEach(function(cell) {
        cell.removeEventListener('keydown', handleCellKeydown);
        cell.removeEventListener('blur', handleCellBlur);
        cell.removeEventListener('focus', handleCellFocus);
        
        cell.addEventListener('keydown', handleCellKeydown);
        cell.addEventListener('blur', handleCellBlur);
        cell.addEventListener('focus', handleCellFocus);
    });
    
    // Sex cells - click to open dropdown
    var sexCells = document.querySelectorAll('.sex-cell');
    sexCells.forEach(function(cell) {
        cell.removeEventListener('click', function() { openSexDropdown(cell); });
        cell.addEventListener('click', function() { openSexDropdown(cell); });
    });
}

function handleCellFocus(e) {
    var cell = e.target;
    var originalValue = cell.textContent;
    cell.dataset.originalValue = originalValue;
    cell.classList.add('editing');
    cell.classList.remove('invalid-field');
}

function handleCellBlur(e) {
    var cell = e.target;
    var rawValue = cell.textContent;
    var newValue = rawValue.trim();
    var originalValue = cell.dataset.originalValue || '';
    cell.classList.remove('editing');
    
    // Skip date fields (handled by date picker)
    if (cell.dataset.field === 'date_of_birth') {
        return;
    }
    
    // Skip sex fields (handled by dropdown)
    if (cell.dataset.field === 'sex') {
        return;
    }
    
    // Apply capitalization based on field type
    var field = cell.dataset.field;
    var formattedValue = newValue;
    
    if (newValue) {
        switch(field) {
            case 'last_name':
            case 'first_name':
            case 'middle_name':
            case 'citizenship':
                formattedValue = capitalizeNameField(newValue);
                break;
            case 'place_of_birth':
                formattedValue = capitalizePlaceOfBirth(newValue);
                break;
            case 'civil_status':
                formattedValue = capitalizeCivilStatus(newValue);
                break;
            case 'employment_status':
                formattedValue = capitalizeEmploymentStatus(newValue);
                break;
            case 'occupation':
                // Capitalize each word in occupation
                formattedValue = newValue.split(' ').map(function(word) {
                    return capitalizeNameField(word);
                }).join(' ');
                break;
            default:
                formattedValue = newValue;
        }
        
        // Update cell with formatted value if different
        if (formattedValue !== newValue) {
            cell.textContent = formattedValue;
        }
    }
    
    if (formattedValue !== originalValue) {
        var row = cell.closest('tr');
        if (row) {
            row.classList.add('changed');
            row.dataset.changed = 'true';
        }
        
        var rowEl = cell.closest('tr');
        if (rowEl && rowEl.classList.contains('new-household-row')) {
            var purokCell = rowEl.querySelector('[data-field="purok"]');
            var addressCell = rowEl.querySelector('[data-field="household_address"]');
            var purok = purokCell ? purokCell.textContent.trim() : '';
            var address = addressCell ? addressCell.textContent.trim() : '';
            if (purok && address) {
                addNewHousehold(purok, address);
            }
        }
        
        // Re-validate the row
        validateRow(row);
    }
}

function handleCellKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.target.blur();
        
        var currentCell = e.target;
        var currentRow = currentCell.closest('tr');
        var cells = currentRow.querySelectorAll('.editable-cell[contenteditable="true"]');
        var currentIndex = Array.from(cells).indexOf(currentCell);
        
        if (currentIndex < cells.length - 1) {
            cells[currentIndex + 1].focus();
        } else {
            var nextRow = currentRow.nextElementSibling;
            if (nextRow) {
                var nextCells = nextRow.querySelectorAll('.editable-cell[contenteditable="true"]');
                if (nextCells.length > 0) {
                    nextCells[0].focus();
                }
            }
        }
    }
    
    if (e.key === 'Escape') {
        var cell = e.target;
        var originalValue = cell.dataset.originalValue || '';
        cell.textContent = originalValue;
        cell.blur();
    }
}

// ============ VALIDATION FUNCTIONS ============

function validateRow(row) {
    if (!row) return;
    
    // Skip new household rows
    if (row.classList.contains('new-household-row')) return;
    
    // Skip rows without household id
    var householdId = row.dataset.householdId;
    if (!householdId) return;
    
    var fields = row.querySelectorAll('.editable-cell');
    var isValid = true;
    var errorMessages = [];
    
    // Check if this is a member row (has member_id)
    var isMemberRow = row.dataset.memberId && row.dataset.memberId !== '';
    
    fields.forEach(function(cell) {
        var field = cell.dataset.field;
        var value = cell.textContent.trim();
        cell.classList.remove('invalid-field');
        
        // Skip empty cells for non-required fields
        if (!value) {
            // Required fields: Last Name, Purok, Household Address
            if (field === 'last_name' && isMemberRow) {
                cell.classList.add('invalid-field');
                isValid = false;
                errorMessages.push('Last Name is required');
            }
            if (field === 'purok') {
                cell.classList.add('invalid-field');
                isValid = false;
                errorMessages.push('Purok is required');
            }
            if (field === 'household_address') {
                cell.classList.add('invalid-field');
                isValid = false;
                errorMessages.push('Household Address is required');
            }
            return;
        }
        
        // Validate field values
        switch(field) {
            case 'purok':
                if (!value) {
                    cell.classList.add('invalid-field');
                    isValid = false;
                    errorMessages.push('Purok is required');
                }
                break;
                
            case 'household_address':
                if (!value) {
                    cell.classList.add('invalid-field');
                    isValid = false;
                    errorMessages.push('Household Address is required');
                }
                break;
                
            case 'date_of_birth':
                if (value) {
                    var datePattern = /^\d{4}-\d{2}-\d{2}$/;
                    if (!datePattern.test(value)) {
                        cell.classList.add('invalid-field');
                        isValid = false;
                        errorMessages.push('Invalid date format. Use YYYY-MM-DD');
                    } else {
                        var parts = value.split('-');
                        var year = parseInt(parts[0]);
                        var month = parseInt(parts[1]);
                        var day = parseInt(parts[2]);
                        var dateObj = new Date(year, month - 1, day);
                        if (dateObj.getFullYear() !== year || 
                            dateObj.getMonth() !== month - 1 || 
                            dateObj.getDate() !== day) {
                            cell.classList.add('invalid-field');
                            isValid = false;
                            errorMessages.push('Invalid date');
                        }
                    }
                }
                break;
                
            case 'age':
                if (value) {
                    var ageNum = parseInt(value);
                    if (isNaN(ageNum) || ageNum < 0 || ageNum > 150) {
                        cell.classList.add('invalid-field');
                        isValid = false;
                        errorMessages.push('Age must be between 0 and 150');
                    }
                }
                break;
                
            case 'sex':
                if (value) {
                    var validSex = ['M', 'F'];
                    if (!validSex.some(function(s) { return s === value; })) {
                        cell.classList.add('invalid-field');
                        isValid = false;
                        errorMessages.push('Sex must be M or F');
                    }
                }
                break;
                
            case 'civil_status':
                if (value) {
                    var validStatus = ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'];
                    if (!validStatus.some(function(s) { return s.toLowerCase() === value.toLowerCase(); })) {
                        cell.classList.add('invalid-field');
                        isValid = false;
                        errorMessages.push('Invalid Civil Status');
                    }
                }
                break;
                
            case 'employment_status':
                if (value) {
                    var validEmployment = ['Employed', 'Unemployed', 'Solo Parent', 'OSY', 'OSC', 'Student', 'Retired'];
                    if (!validEmployment.some(function(s) { return s.toLowerCase() === value.toLowerCase(); })) {
                        cell.classList.add('invalid-field');
                        isValid = false;
                        errorMessages.push('Invalid Employment Status');
                    }
                }
                break;
        }
    });
    
    if (!isValid) {
        row.classList.add('has-errors');
        row.dataset.errors = errorMessages.join('; ');
    } else {
        row.classList.remove('has-errors');
        row.dataset.errors = '';
    }
    
    return isValid;
}

function validateAllRows() {
    var rows = document.querySelectorAll('#householdTableBody tr:not(.new-household-row):not(.new-member-row)');
    var hasErrors = false;
    var errorCount = 0;
    var errorDetails = [];
    
    rows.forEach(function(row) {
        var isValid = validateRow(row);
        if (!isValid) {
            hasErrors = true;
            errorCount++;
            var householdId = row.dataset.householdId;
            var nameCell = row.querySelector('[data-field="last_name"]');
            var name = nameCell ? nameCell.textContent.trim() : 'Unknown';
            var errors = row.dataset.errors || 'Unknown error';
            errorDetails.push('Row ' + errorCount + ' (' + name + '): ' + errors);
        }
    });
    
    if (hasErrors) {
        showNotificationModal('error', 'Validation Errors', 'Found ' + errorCount + ' row(s) with errors:\n\n' + errorDetails.join('\n'));
    } else {
        showNotificationModal('success', 'Validation Passed', 'All rows are valid!');
    }
}

// ============ NOTIFICATION MODAL ============

function showNotificationModal(type, title, message) {
    var iconClass = '';
    var iconHtml = '';
    var color = '';
    
    switch(type) {
        case 'success':
            iconClass = 'success';
            iconHtml = '<i class="fas fa-check-circle"></i>';
            color = '#28a745';
            break;
        case 'error':
            iconClass = 'error';
            iconHtml = '<i class="fas fa-times-circle"></i>';
            color = '#dc3545';
            break;
        case 'warning':
            iconClass = 'warning';
            iconHtml = '<i class="fas fa-exclamation-triangle"></i>';
            color = '#ff9800';
            break;
        case 'info':
            iconClass = 'info';
            iconHtml = '<i class="fas fa-info-circle"></i>';
            color = '#17a2b8';
            break;
    }
    
    var modalHtml = `
        <div class="notification-modal" id="notificationModal">
            <div class="notification-modal-content" style="max-width: 500px; max-height: 80vh; overflow-y: auto;">
                <div class="notification-icon ${iconClass}" style="font-size: 60px; text-align: center; margin-bottom: 15px;">
                    ${iconHtml}
                </div>
                <div class="notification-title" style="font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 10px; color: ${color};">
                    ${escapeHtml(title)}
                </div>
                <div class="notification-message" style="font-size: 14px; color: #555; text-align: left; line-height: 1.8; padding: 10px 0; white-space: pre-wrap; max-height: 300px; overflow-y: auto;">
                    ${escapeHtml(message).replace(/\n/g, '<br>')}
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button class="notification-btn" onclick="this.closest('.notification-modal').remove()" style="background: ${color}; color: white; border: none; padding: 10px 30px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600;">
                        OK
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing notification modal
    var existing = document.querySelector('.notification-modal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Shortcut functions for notifications
function showSuccessModal(message, title) {
    showNotificationModal('success', title || 'Success!', message);
}

function showErrorModal(message, title) {
    showNotificationModal('error', title || 'Error!', message);
}

function showWarningModal(message, title) {
    showNotificationModal('warning', title || 'Warning!', message);
}

// ============ ADD NEW ROWS ============

function addNewHouseholdRow() {
    var tbody = document.getElementById('householdTableBody');
    var newRow = document.createElement('tr');
    newRow.className = 'new-household-row';
    newRow.dataset.changed = 'true';
    newRow.innerHTML = `
        <td></td>
        <td class="editable-cell purok-cell" data-field="purok" contenteditable="true" placeholder="Purok"></td>
        <td class="editable-cell household-address-cell" data-field="household_address" contenteditable="true" placeholder="House Address"></td>
        <td></td>
        <td colspan="13" style="color:#6c757d; font-style:italic;">Enter Purok and Household Address, then press Enter to add new household</td>
    `;
    tbody.appendChild(newRow);
    attachInlineEditEvents();
    
    setTimeout(function() {
        var firstCell = newRow.querySelector('.editable-cell');
        if (firstCell) firstCell.focus();
    }, 100);
}

function addMemberRow(householdId) {
    var tbody = document.getElementById('householdTableBody');
    var rows = tbody.querySelectorAll('tr[data-household-id="' + householdId + '"]');
    var insertAfter = rows.length > 0 ? rows[rows.length - 1] : null;
    
    if (!insertAfter) {
        var allRows = tbody.querySelectorAll('tr');
        for (var i = 0; i < allRows.length; i++) {
            if (allRows[i].dataset.householdId == householdId) {
                insertAfter = allRows[i];
                break;
            }
        }
    }
    
    if (!insertAfter) {
        showErrorModal('Could not find household to add member to.', 'Error');
        return;
    }
    
    var address = insertAfter.dataset.address || '';
    var purok = insertAfter.dataset.purok || '';
    
    var newRow = document.createElement('tr');
    newRow.className = 'new-member-row';
    newRow.dataset.householdId = householdId;
    newRow.dataset.memberId = '';
    newRow.dataset.address = address;
    newRow.dataset.purok = purok;
    newRow.dataset.changed = 'true';
    newRow.innerHTML = `
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td class="editable-cell" data-field="last_name" contenteditable="true" placeholder="Last Name *"></td>
        <td class="editable-cell" data-field="first_name" contenteditable="true" placeholder="First Name"></td>
        <td class="editable-cell" data-field="middle_name" contenteditable="true" placeholder="Middle Name"></td>
        <td class="editable-cell" data-field="ext" contenteditable="true" placeholder="Ext"></td>
        <td class="editable-cell" data-field="place_of_birth" contenteditable="true" placeholder="Birth Place"></td>
        <td class="editable-cell date-cell" data-field="date_of_birth" contenteditable="true" onclick="openDatePicker(this)" placeholder="Click to select date"></td>
        <td class="editable-cell age-cell" data-field="age" contenteditable="true" placeholder="Auto-calculated"></td>
        <td class="editable-cell sex-cell" data-field="sex" contenteditable="false" onclick="openSexDropdown(this)" placeholder="Click to select"></td>
        <td class="editable-cell" data-field="civil_status" contenteditable="true" placeholder="Civil Status"></td>
        <td class="editable-cell" data-field="citizenship" contenteditable="true" placeholder="Citizenship"></td>
        <td class="editable-cell" data-field="occupation" contenteditable="true" placeholder="Occupation"></td>
        <td class="editable-cell" data-field="employment_status" contenteditable="true" placeholder="Employment"></td>
        <td>
            <button class="btn-action delete" onclick="cancelNewRow(this)" title="Cancel">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    insertAfter.parentNode.insertBefore(newRow, insertAfter.nextSibling);
    attachInlineEditEvents();
    
    setTimeout(function() {
        var lastNameCell = newRow.querySelector('[data-field="last_name"]');
        if (lastNameCell) lastNameCell.focus();
    }, 100);
}

function cancelNewRow(button) {
    var row = button.closest('tr');
    if (!row) return;
    
    // Use modal confirmation
    showConfirmationModal('Cancel this new row?', 'Confirm Cancel', function() {
        row.remove();
        var householdId = row.dataset.householdId;
        if (householdId) {
            updateMemberCount(householdId);
        }
    });
}

function updateMemberCount(householdId) {
    var rows = document.querySelectorAll('tr[data-household-id="' + householdId + '"]');
    var count = 0;
    rows.forEach(function(r) {
        if (r.dataset.memberId && r.dataset.memberId !== '' && !r.classList.contains('new-member-row')) {
            count++;
        }
    });
    
    var firstRow = rows[0];
    if (firstRow) {
        var countCell = firstRow.querySelector('.member-count-cell');
        if (countCell) {
            countCell.innerHTML = '<span class="member-count-badge">' + count + '</span>';
        }
    }
}

async function addNewHousehold(purok, address) {
    try {
        var formData = new FormData();
        formData.append('action', 'add_household');
        formData.append('purok', purok);
        formData.append('address', address);
        
        var response = await fetch('household_ajax.php', { method: 'POST', body: formData });
        var data = await response.json();
        
        if (data.success) {
            showSuccessModal('Household added successfully!', 'Success');
            loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch);
        } else {
            showErrorModal(data.message, 'Error');
        }
    } catch (error) {
        showErrorModal('An error occurred.', 'Error');
    }
}

// ============ DELETE ROW ============

async function deleteRow(householdId, memberId) {
    // Use modal confirmation
    showConfirmationModal('Delete this member? This action cannot be undone.', 'Confirm Delete', async function() {
        try {
            var formData = new FormData();
            formData.append('action', 'delete_member');
            formData.append('id', memberId);
            
            var response = await fetch('household_ajax.php', { method: 'POST', body: formData });
            var data = await response.json();
            
            if (data.success) {
                showSuccessModal('Member deleted successfully', 'Deleted');
                loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch);
            } else {
                showErrorModal(data.message, 'Error');
            }
        } catch (error) {
            showErrorModal('An error occurred.', 'Error');
        }
    });
}

// ============ DELETE HOUSEHOLD ============

async function deleteHousehold(householdId) {
    try {
        var response = await fetch('household_ajax.php?action=get_household_members&household_id=' + householdId);
        var data = await response.json();
        
        if (data.success && data.data.length > 0) {
            showErrorModal('Cannot delete this household because it has ' + data.data.length + ' member(s). Please delete all members first.', 'Cannot Delete');
            return;
        }
    } catch (error) {
        showErrorModal('An error occurred while checking members.', 'Error');
        return;
    }
    
    // Use modal confirmation
    showConfirmationModal('Are you sure you want to delete this household? This action cannot be undone.', 'Confirm Delete', async function() {
        try {
            var formData = new FormData();
            formData.append('action', 'delete_household');
            formData.append('id', householdId);
            
            var response = await fetch('household_ajax.php', { method: 'POST', body: formData });
            var data = await response.json();
            
            if (data.success) {
                showSuccessModal(data.message, 'Deleted');
                loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch);
            } else {
                showErrorModal(data.message, 'Error');
            }
        } catch (error) {
            showErrorModal('An error occurred.', 'Error');
        }
    });
}

// ============ SAVE ALL ============

async function saveAllRows() {
    // First validate all rows
    var rows = document.querySelectorAll('#householdTableBody tr:not(.new-household-row):not(.new-member-row)');
    var hasErrors = false;
    var errorDetails = [];
    
    rows.forEach(function(row) {
        var isValid = validateRow(row);
        if (!isValid) {
            hasErrors = true;
            var nameCell = row.querySelector('[data-field="last_name"]');
            var name = nameCell ? nameCell.textContent.trim() : 'Unknown';
            var errors = row.dataset.errors || 'Unknown error';
            errorDetails.push('Row ' + (name || 'Unknown') + ': ' + errors);
        }
    });
    
    if (hasErrors) {
        showErrorModal('Cannot save. Please fix the following errors:\n\n' + errorDetails.join('\n'), 'Validation Errors');
        return;
    }
    
    // Get all rows including new ones
    var allRows = document.querySelectorAll('#householdTableBody tr');
    var dataToSave = [];
    var hasChanges = false;
    var processedHouseholds = {};
    var validationErrors = [];
    
    // Check for new household rows
    var newHouseholdRows = document.querySelectorAll('.new-household-row');
    newHouseholdRows.forEach(function(row) {
        var purokCell = row.querySelector('[data-field="purok"]');
        var addressCell = row.querySelector('[data-field="household_address"]');
        var purok = purokCell ? purokCell.textContent.trim() : '';
        var address = addressCell ? addressCell.textContent.trim() : '';
        if (purok && address) {
            dataToSave.push({
                household_id: 0,
                member_id: 0,
                purok: purok,
                household_address: address,
                last_name: '',
                first_name: '',
                middle_name: '',
                ext: '',
                place_of_birth: '',
                date_of_birth: '',
                age: 0,
                sex: '',
                civil_status: '',
                citizenship: '',
                occupation: '',
                employment_status: '',
                _new_household: true
            });
            hasChanges = true;
        }
    });
    
    // Process all existing rows
    var existingRows = document.querySelectorAll('#householdTableBody tr:not(.new-household-row):not(.new-member-row)');
    
    existingRows.forEach(function(row) {
        var householdId = row.dataset.householdId;
        var memberId = row.dataset.memberId;
        
        if (!householdId) return;
        
        var purok = '';
        var address = '';
        var purokCell = row.querySelector('[data-field="purok"]');
        var addressCell = row.querySelector('[data-field="household_address"]');
        
        if (purokCell) {
            purok = purokCell.textContent.trim();
        } else {
            purok = row.dataset.purok || '';
        }
        
        if (addressCell) {
            address = addressCell.textContent.trim();
        } else {
            address = row.dataset.address || '';
        }
        
        var rowData = {
            household_id: parseInt(householdId),
            member_id: memberId ? parseInt(memberId) : 0,
            purok: purok,
            household_address: address,
            last_name: '',
            first_name: '',
            middle_name: '',
            ext: '',
            place_of_birth: '',
            date_of_birth: '',
            age: 0,
            sex: '',
            civil_status: '',
            citizenship: '',
            occupation: '',
            employment_status: ''
        };
        
        var cells = row.querySelectorAll('.editable-cell');
        cells.forEach(function(cell) {
            var field = cell.dataset.field;
            var value = cell.textContent.trim();
            if (field && rowData.hasOwnProperty(field)) {
                if (field === 'age') {
                    rowData[field] = parseInt(value) || 0;
                } else if (field === 'date_of_birth') {
                    rowData[field] = value;
                } else {
                    rowData[field] = value;
                }
            }
        });
        
        if (row.classList.contains('changed') || row.dataset.changed === 'true') {
            hasChanges = true;
        }
        
        var key = householdId + '_' + (memberId || 'new');
        if (!processedHouseholds[key]) {
            dataToSave.push(rowData);
            processedHouseholds[key] = true;
        }
    });
    
    // Check for new member rows
    var newMemberRows = document.querySelectorAll('.new-member-row');
    newMemberRows.forEach(function(row) {
        var householdId = row.dataset.householdId;
        if (!householdId) return;
        
        var rowData = {
            household_id: parseInt(householdId),
            member_id: 0,
            purok: row.dataset.purok || '',
            household_address: row.dataset.address || '',
            last_name: '',
            first_name: '',
            middle_name: '',
            ext: '',
            place_of_birth: '',
            date_of_birth: '',
            age: 0,
            sex: '',
            civil_status: '',
            citizenship: '',
            occupation: '',
            employment_status: ''
        };
        
        var cells = row.querySelectorAll('.editable-cell');
        var hasData = false;
        cells.forEach(function(cell) {
            var field = cell.dataset.field;
            var value = cell.textContent.trim();
            if (field && rowData.hasOwnProperty(field)) {
                if (field === 'age') {
                    rowData[field] = parseInt(value) || 0;
                } else if (field === 'date_of_birth') {
                    rowData[field] = value;
                    if (value) hasData = true;
                } else {
                    rowData[field] = value;
                    if (value) hasData = true;
                }
            }
        });
        
        if (hasData) {
            if (!rowData.last_name) {
                validationErrors.push('Last Name is required for new member');
                return;
            }
            dataToSave.push(rowData);
            hasChanges = true;
        } else {
            row.remove();
        }
    });
    
    if (validationErrors.length > 0) {
        showErrorModal(validationErrors.join('\n'), 'Validation Error');
        return;
    }
    
    if (!hasChanges) {
        showWarningModal('No changes to save.', 'No Changes');
        return;
    }
    
    try {
        var response = await fetch('household_ajax.php?action=save_all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows: dataToSave })
        });
        
        var data = await response.json();
        
        if (data.success) {
            showSuccessModal(data.message, 'Saved');
            document.querySelectorAll('.changed').forEach(function(el) {
                el.classList.remove('changed');
                el.dataset.changed = 'false';
            });
            loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch);
        } else {
            showErrorModal(data.message, 'Error');
        }
    } catch (error) {
        showErrorModal('An error occurred while saving.', 'Error');
    }
}

// ============ UTILITY FUNCTIONS ============

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============ RENDER PAGINATION ============

function renderHouseholdPagination(data) {
    var container = document.getElementById('householdPagination');
    if (!container) return;
    
    if (data.totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    var html = '<div class="pagination-wrapper"><div class="pagination">';
    
    if (data.currentPage > 1) {
        html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + (data.currentPage - 1) + ', \'' + currentHouseholdSearch + '\')">';
        html += '<i class="fas fa-chevron-left"></i> Prev</button>';
    } else {
        html += '<button class="page-btn disabled" disabled><i class="fas fa-chevron-left"></i> Prev</button>';
    }
    
    var maxVisible = 5;
    var startPage = Math.max(1, data.currentPage - Math.floor(maxVisible / 2));
    var endPage = Math.min(data.totalPages, startPage + maxVisible - 1);
    
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }
    
    if (startPage > 1) {
        html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', 1, \'' + currentHouseholdSearch + '\')">1</button>';
        if (startPage > 2) html += '<span class="page-dots">...</span>';
    }
    
    for (var i = startPage; i <= endPage; i++) {
        var activeClass = i === data.currentPage ? 'active' : '';
        html += '<button class="page-btn ' + activeClass + '" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + i + ', \'' + currentHouseholdSearch + '\')">' + i + '</button>';
    }
    
    if (endPage < data.totalPages) {
        if (endPage < data.totalPages - 1) html += '<span class="page-dots">...</span>';
        html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + data.totalPages + ', \'' + currentHouseholdSearch + '\')">' + data.totalPages + '</button>';
    }
    
    if (data.currentPage < data.totalPages) {
        html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + (data.currentPage + 1) + ', \'' + currentHouseholdSearch + '\')">';
        html += 'Next <i class="fas fa-chevron-right"></i></button>';
    } else {
        html += '<button class="page-btn disabled" disabled>Next <i class="fas fa-chevron-right"></i></button>';
    }
    
    html += '</div></div>';
    container.innerHTML = html;
}

// ============ UPDATE STATS ============

function updateHouseholdStats(data) {
    var totalEl = document.getElementById('totalHouseholds');
    var membersEl = document.getElementById('totalMembers');
    var puroksEl = document.getElementById('totalPuroks');
    
    if (totalEl) totalEl.textContent = data.total || 0;
    
    fetch('household_ajax.php?action=get_household_counts')
        .then(function(response) { return response.json(); })
        .then(function(countData) {
            if (countData.success) {
                if (membersEl) membersEl.textContent = countData.totalMembers || 0;
                if (puroksEl) {
                    var purokCount = Object.keys(countData.data || {}).length;
                    puroksEl.textContent = purokCount || 0;
                }
            }
        })
        .catch(function(err) { console.error('Error loading stats:', err); });
}

// ============ LOAD PUROK FILTER ============

function loadPurokFilter(purokCounts) {
    var select = document.getElementById('purokFilter');
    if (!select) return;
    
    if (select.options.length <= 1) {
        fetch('household_ajax.php?action=get_puroks')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    select.innerHTML = '<option value="all">All Puroks</option>';
                    data.data.forEach(function(purok) {
                        var count = purokCounts[purok] || 0;
                        select.innerHTML += '<option value="' + escapeHtml(purok) + '">Purok ' + escapeHtml(purok) + ' (' + count + ')</option>';
                    });
                    
                    if (currentHouseholdPurok !== 'all') {
                        select.value = currentHouseholdPurok;
                    }
                }
            })
            .catch(function(err) { console.error('Error loading puroks:', err); });
    } else {
        var options = select.querySelectorAll('option');
        options.forEach(function(option) {
            if (option.value !== 'all') {
                var count = purokCounts[option.value] || 0;
                var text = 'Purok ' + option.value + ' (' + count + ')';
                if (option.textContent !== text) {
                    option.textContent = text;
                }
            }
        });
    }
}

// ============ FILTER FUNCTIONS ============

function filterHouseholdsByPurok() {
    var select = document.getElementById('purokFilter');
    var purok = select ? select.value : 'all';
    var search = document.getElementById('householdSearchInput')?.value || '';
    loadHouseholds(purok, 1, search);
}

function searchHouseholds() {
    var input = document.getElementById('householdSearchInput');
    var search = input ? input.value : '';
    clearTimeout(window.householdSearchTimeout);
    window.householdSearchTimeout = setTimeout(function() {
        loadHouseholds(currentHouseholdPurok, 1, search);
    }, 300);
}

// ============ ADD HOUSEHOLD TO SIDEBAR ============

function addHouseholdToSidebar() {
    var householdItem = document.querySelector('.nav-item.standalone[data-view="household"]');
    
    var clickHandler = function() {
        document.querySelectorAll('.nav-item').forEach(function(nav) {
            nav.classList.remove('active-parent', 'active');
        });
        this.classList.add('active');
        document.querySelectorAll('.sub-option').forEach(function(sub) {
            sub.classList.remove('active-sub');
        });
        
        if (typeof closeCertification === 'function') closeCertification();
        
        var dashboardBody = document.getElementById('dashboardBody');
        if (dashboardBody) {
            dashboardBody.innerHTML = renderHouseholdView();
            loadHouseholds('all', 1);
        }
        
        if (typeof closeDropdown === 'function') closeDropdown();
        
        if (window.innerWidth <= 768) {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.add('hide');
        }
    };
    
    if (householdItem) {
        householdItem.addEventListener('click', clickHandler);
        return;
    }
    
    var navMenu = document.querySelector('.nav-menu');
    if (!navMenu) return;
    
    var dashboardItem = document.querySelector('.nav-item.standalone[data-view="dashboard"]');
    if (!dashboardItem) return;
    
    var newHouseholdItem = document.createElement('div');
    newHouseholdItem.className = 'nav-item standalone';
    newHouseholdItem.setAttribute('data-view', 'household');
    newHouseholdItem.innerHTML = '<i class="fas fa-home"></i><span>Household</span>';
    
    if (dashboardItem.nextSibling) {
        navMenu.insertBefore(newHouseholdItem, dashboardItem.nextSibling);
    } else {
        navMenu.appendChild(newHouseholdItem);
    }
    
    newHouseholdItem.addEventListener('click', clickHandler);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    addHouseholdToSidebar();
});

if (document.readyState === 'complete') {
    addHouseholdToSidebar();
} else {
    window.addEventListener('load', addHouseholdToSidebar);
}