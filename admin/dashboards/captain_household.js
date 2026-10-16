// captain_household.js — READ-ONLY Household Management for Captain Dashboard
// Uses secretary_household_ajax.php for reads only.

// ============ LOAD CHART.JS ONCE ============
(function loadChartJsOnce() {
    if (window.Chart) return;
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    document.head.appendChild(s);
})();

// ============ GLOBAL STATE ============
let currentHouseholdPurok = 'all';
let currentHouseholdPage = 1;
let currentHouseholdTotalPages = 1;
let currentHouseholdData = [];
let currentHouseholdSearch = '';

// Resident detail chart handles
var rdCharts = { docs: null, equipment: null, complaints: null };
var rdComplaintsPieData = { labels: [], values: [], colors: [] };

// Endpoint — read-only, so we reuse the secretary's endpoint
const CPT_HOUSEHOLD_ENDPOINT = 'secretary_household_ajax.php';

// ============ RENDER HOUSEHOLD VIEW (READ-ONLY) ============
function renderHouseholdView() {
    return `
        <div class="household-module">
            <style>
                .table-fit-wrapper { width:100%; overflow:hidden; position:relative; }
                .table-fit-inner { width:100%; transform-origin: top left; transition: transform 0.15s ease-out; will-change: transform; }
                .table-fit-inner > table { width: max-content; min-width: 100%; white-space: nowrap; border-collapse: collapse; }
                .table-fit-inner > table th,
                .table-fit-inner > table td { white-space: nowrap; }

                #householdTable tr.member-has-account > td {
                    background: linear-gradient(180deg, #e8f7ee 0%, #ddf2e6 100%);
                    box-shadow: inset 0 0 0 1px rgba(46, 160, 90, 0.08);
                }
                #householdTable tr.member-has-account:hover > td {
                    background: linear-gradient(180deg, #dcf1e3 0%, #d1ebd9 100%);
                }
                #householdTable tr.member-has-account td:first-child {
                    box-shadow: inset 0 0 0 1px rgba(46, 160, 90, 0.08), inset 4px 0 0 #2ea05a;
                }
                #householdTable tr.member-has-account .account-badge {
                    display:inline-flex; align-items:center; gap:4px;
                    background:#2ea05a; color:#fff; font-size:10px; font-weight:600;
                    padding:1px 6px; border-radius:10px; margin-left:6px;
                    vertical-align:middle; letter-spacing:0.2px;
                }
                #householdTable tr.member-has-account .account-badge i { font-size:9px; }
                .registered-legend { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#5f7f6e; margin-bottom:8px; }
                .registered-legend .swatch { display:inline-block; width:14px; height:14px; border-radius:4px; background:#e2f3e9; border-left:4px solid #2ea05a; }

                .rd-section { background:#fff; border:1px solid #e6efe9; border-radius:12px; padding:16px 18px; margin-bottom:16px; }
                .rd-section h4 { margin:0 0 12px 0; font-size:14px; color:#1a472a; display:flex; align-items:center; gap:8px; font-weight:700; letter-spacing:0.2px; }
                .rd-section h4 i { color:#2ea05a; }
                .rd-header { display:flex; gap:16px; align-items:center; margin-bottom:18px; padding-bottom:16px; border-bottom:1px solid #eef2ef; }
                .rd-avatar { width:64px; height:64px; border-radius:50%; background: linear-gradient(135deg,#2ea05a,#1e7e6c); display:flex; align-items:center; justify-content:center; color:#fff; font-size:26px; font-weight:700; flex-shrink:0; }
                .rd-name { font-size:18px; font-weight:700; color:#1a472a; margin-bottom:3px; }
                .rd-sub { font-size:12px; color:#6c757d; }
                .rd-badge { display:inline-flex; align-items:center; gap:4px; background:#e8f7ee; color:#1e7e6c; font-size:11px; font-weight:600; padding:2px 10px; border-radius:20px; margin-left:8px; border:1px solid #c6e6d5; }
                .rd-stats { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; margin-bottom:18px; }
                @media (max-width:640px) { .rd-stats { grid-template-columns: 1fr; } }
                .rd-stat { background: linear-gradient(135deg,#f6fbf8,#eff8f3); border-radius:12px; padding:14px; border-left:4px solid #2ea05a; }
                .rd-stat.blue { background: linear-gradient(135deg,#f3f8fd,#eaf2fb); border-left-color:#2196f3; }
                .rd-stat.orange { background: linear-gradient(135deg,#fff8ee,#fff3e0); border-left-color:#ff9800; }
                .rd-stat .label { font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; }
                .rd-stat .value { font-size:26px; font-weight:800; color:#1a472a; line-height:1.2; margin-top:4px; }
                .rd-stat.blue .value { color:#1565c0; }
                .rd-stat.orange .value { color:#e65100; }
                .rd-stat .hint { font-size:11px; color:#8896a0; margin-top:3px; }
                .rd-charts { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
                @media (max-width:720px) { .rd-charts { grid-template-columns: 1fr; } }
                .rd-chart-box { background:#fbfdfc; border:1px solid #eef2ef; border-radius:12px; padding:12px; }
                .rd-chart-box h5 { margin:0 0 8px 0; font-size:12px; color:#4a5d55; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
                .rd-chart-wrap { position:relative; height:200px; }
                .rd-list { list-style:none; padding:0; margin:0; font-size:13px; }
                .rd-list li { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #eef2ef; }
                .rd-list li:last-child { border-bottom:none; }
                .rd-list .pill { background:#e8f7ee; color:#1e7e6c; font-size:11px; font-weight:700; padding:1px 8px; border-radius:10px; }
                .rd-empty { text-align:center; color:#8896a0; font-size:12px; padding:20px 0; }

                .rd-recent { border-radius:12px; padding:12px 14px; margin-bottom:14px; border-left:5px solid #2ea05a; background:#f6fbf8; }
                .rd-recent.recent-unresolved { background:#fff5f5; border-left-color:#dc3545; }
                .rd-recent.recent-pending    { background:#fffaf0; border-left-color:#ff9800; }
                .rd-recent.recent-resolved   { background:#f2fbf5; border-left-color:#2ea05a; }
                .rd-recent-label { font-size:11px; font-weight:700; color:#4a5d55; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; display:flex; align-items:center; gap:8px; }
                .rd-recent-role { margin-left:auto; font-size:10px; color:#fff; padding:2px 8px; border-radius:10px; text-transform:none; letter-spacing:0.2px; font-weight:600; }
                .rd-recent-title { font-size:14px; font-weight:700; color:#1a472a; margin-bottom:4px; }
                .rd-recent-meta { display:flex; flex-wrap:wrap; gap:14px; font-size:11px; color:#6c757d; }
                .rd-recent-meta i { margin-right:3px; }

                .rd-complist { list-style:none; padding:0; margin:0; max-height:240px; overflow-y:auto; }
                .rd-complist li { border-left:3px solid #dc3545; background:#fff; border-radius:6px; padding:8px 10px; margin-bottom:6px; }
                .rd-complist li.rc-resolved   { border-left-color:#2ea05a; }
                .rd-complist li.rc-pending    { border-left-color:#ff9800; }
                .rd-complist li.rc-unresolved { border-left-color:#dc3545; }
                .rd-complist .rc-ref { font-size:10px; color:#8896a0; font-weight:600; letter-spacing:0.3px; }
                .rd-complist .rc-title { font-size:12px; color:#1a472a; font-weight:600; margin:2px 0 4px; line-height:1.3; }
                .rd-complist .rc-meta { display:flex; justify-content:space-between; font-size:10px; color:#8896a0; }
                .rd-complist .rc-status { font-weight:700; color:#b26a00; }
                .rd-complist li.rc-resolved .rc-status   { color:#2e7d32; }
                .rd-complist li.rc-unresolved .rc-status { color:#c62828; }

                /* Read-only notice banner */
                .ro-banner {
                    display:flex; align-items:center; gap:10px;
                    padding:10px 14px; margin-bottom:12px;
                    background:#e3f2fd; border-left:4px solid #1976d2;
                    border-radius:10px; color:#0d47a1; font-size:0.83rem;
                }
                .ro-banner i { font-size:1rem; flex-shrink:0; }
            </style>
            <div class="content-card">
                <div class="section-title">
                    <div>
                        <i class="fas fa-home"></i> Household Management
                        <span style="font-size:12px; color:#6c757d; margin-left:10px;">Read-only view — click a registered resident's last name to see analytics</span>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn-add-household" onclick="loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch)" style="background:#6c757d;">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                </div>

                <div class="ro-banner">
                    <i class="fas fa-eye"></i>
                    <span>You are viewing this page in <b>read-only mode</b>. Adding, editing, and deleting households is handled by the Secretary.</span>
                </div>

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
                </div>

                <div class="household-stats" id="householdStats">
                    <div class="stat-item"><span class="stat-label">Total Households</span><span class="stat-number" id="totalHouseholds">-</span></div>
                    <div class="stat-item"><span class="stat-label">Total Members</span><span class="stat-number" id="totalMembers">-</span></div>
                    <div class="stat-item"><span class="stat-label">Puroks</span><span class="stat-number" id="totalPuroks">-</span></div>
                </div>

                <div class="registered-legend">
                    <span class="swatch"></span>
                    Resident with a registered account — click the last name to view analytics
                </div>

                <div class="table-fit-wrapper" id="householdTableFitWrapper">
                    <div class="table-fit-inner" id="householdTableFitInner">
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
                                    <th style="min-width:140px;">Email</th>
                                    <th style="min-width:110px;">Phone Number</th>
                                </tr>
                            </thead>
                            <tbody id="householdTableBody">
                                <tr><td colspan="18" class="text-center"><div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="householdPagination"></div>
            </div>
        </div>

        <!-- RESIDENT DETAIL MODAL (read-only) -->
        <div id="residentDetailModal" class="modal" style="display:none; z-index:2250; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 0;">
            <div class="modal-content" style="max-width: 900px; width: 95%; margin:0 auto;">
                <div class="modal-header" style="background: linear-gradient(135deg,#2ea05a,#1e7e6c);">
                    <h2 id="residentDetailTitle"><i class="fas fa-user-circle"></i> Resident Details</h2>
                    <button class="close-modal" onclick="closeResidentDetailModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding: 22px 26px; max-height: 80vh; overflow-y: auto;">
                    <div id="residentDetailBody">
                        <div style="text-align:center; padding:40px; color:#6c757d;">
                            <i class="fas fa-spinner fa-spin" style="font-size:32px;"></i>
                            <p>Loading analytics...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// ============ AUTO-FIT TABLE ============
function fitTableToWidth(wrapperId, innerId) {
    var wrapper = document.getElementById(wrapperId);
    var inner = document.getElementById(innerId);
    if (!wrapper || !inner) return;
    var table = inner.querySelector('table');
    if (!table) return;

    var isSmallScreen = window.innerWidth <= 1100;

    inner.style.transform = 'scale(1)';
    inner.style.width = '100%';
    wrapper.style.height = 'auto';
    wrapper.style.overflowX = 'visible';

    if (!isSmallScreen) { wrapper.style.overflowX = 'auto'; return; }

    var naturalWidth = table.scrollWidth;
    var availableWidth = wrapper.clientWidth;

    if (naturalWidth <= availableWidth || availableWidth === 0) {
        inner.style.transform = 'scale(1)';
        wrapper.style.height = '';
        wrapper.style.overflowX = 'auto';
        return;
    }

    var scale = availableWidth / naturalWidth;
    if (scale < 0.45) scale = 0.45;

    inner.style.transform = 'scale(' + scale + ')';

    var naturalHeight = table.scrollHeight;
    wrapper.style.height = (naturalHeight * scale) + 'px';
    wrapper.style.overflowX = (scale <= 0.45) ? 'auto' : 'hidden';
}

function refitAllHouseholdTables() {
    fitTableToWidth('householdTableFitWrapper', 'householdTableFitInner');
}

(function () {
    var t;
    window.addEventListener('resize', function () {
        clearTimeout(t);
        t = setTimeout(refitAllHouseholdTables, 120);
    });
})();

// ============ CAPITALIZATION / VALIDATION HELPERS (needed by analytics) ============
function capitalizeText(text) {
    if (!text) return '';
    text = text.trim();
    var words = text.split(/([\s,]+)/);
    var result = [];
    var specialAbbr = ['Sto', 'San', 'Santa', 'Santo', 'Sta', 'Sr', 'Jr', 'Dr', 'Mr', 'Mrs', 'Ms'];
    for (var i = 0; i < words.length; i++) {
        var word = words[i];
        if (/^[\s,]+$/.test(word)) { result.push(word); continue; }
        var lowerWord = word.toLowerCase();
        var matched = specialAbbr.find(function(abbr) { return abbr.toLowerCase() === lowerWord; });
        if (matched) result.push(matched);
        else result.push(word.charAt(0).toUpperCase() + word.slice(1).toLowerCase());
    }
    return result.join('');
}

function calculateAgeFromDateStr(dateStr) {
    if (!dateStr) return null;
    var parts = dateStr.split('-');
    if (parts.length !== 3) return null;
    var year = parseInt(parts[0]), month = parseInt(parts[1]), day = parseInt(parts[2]);
    if (isNaN(year) || isNaN(month) || isNaN(day)) return null;
    if (year < 1900 || year > new Date().getFullYear()) return null;
    var birth = new Date(year, month - 1, day);
    var today = new Date();
    var age = today.getFullYear() - birth.getFullYear();
    var m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age > 0 ? age : 0;
}

// ============ LOAD HOUSEHOLDS ============
async function loadHouseholds(purok, page, search) {
    purok = purok || 'all';
    page = page || 1;
    search = search || '';
    currentHouseholdPurok = purok;
    currentHouseholdPage = page;
    currentHouseholdSearch = search;

    try {
        let url = CPT_HOUSEHOLD_ENDPOINT + '?action=get_households&page=' + page + '&per_page=50';
        if (purok && purok !== 'all') url += '&purok=' + encodeURIComponent(purok);
        if (search) url += '&search=' + encodeURIComponent(search);

        var response = await fetch(url);
        var data = await response.json();

        if (data.success) {
            currentHouseholdData = data.data;
            currentHouseholdTotalPages = data.totalPages;
            renderHouseholdTable(data);
            renderHouseholdPagination(data);
            updateHouseholdStats(data);
            loadPurokFilter(data.purokCounts || {});
        }
    } catch (error) {
        console.error('Error loading households:', error);
        document.getElementById('householdTableBody').innerHTML = '<tr><td colspan="18" class="text-center">Error loading data. Please refresh.</td></tr>';
    }
}

// ============ RENDER TABLE (READ-ONLY) ============
function renderHouseholdTable(data) {
    var tbody = document.getElementById('householdTableBody');

    if (!data.data || data.data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="18" class="text-center"><div class="empty-state"><i class="fas fa-home fa-3x"></i><p>No households found.</p></div></td></tr>`;
        setTimeout(refitAllHouseholdTables, 30);
        return;
    }

    var households = {};
    data.data.forEach(function(item) {
        if (!households[item.household_id]) {
            households[item.household_id] = {
                household_id: item.household_id,
                purok: item.purok || '',
                address: item.household_address || '',
                no_of_members: item.no_of_members || 0,
                members: []
            };
        }
        if (item.member_id) households[item.household_id].members.push(item);
    });

    var addressGroups = {};
    Object.values(households).forEach(function(h) {
        var key = h.address + '|' + h.purok;
        if (!addressGroups[key]) addressGroups[key] = { address: h.address, purok: h.purok, households: [] };
        addressGroups[key].households.push(h);
    });

    var html = '';
    var displayCount = 0;
    var rowNumber = ((data.currentPage - 1) * data.perPage) + 1;

    Object.values(addressGroups).forEach(function(group) {
        var totalMembersInAddress = 0;
        group.households.forEach(function(h) { totalMembersInAddress += h.members.length || 0; });
        var groupRowspan = totalMembersInAddress || 1;
        var firstGroupRow = true;

        group.households.forEach(function(household) {
            var members = household.members;
            var memberCount = members.length || 0;

            if (memberCount === 0) {
                displayCount++;
                html += `
                    <tr>
                        ${firstGroupRow ? '<td class="row-number" rowspan="' + groupRowspan + '">' + displayCount + '</td>' : ''}
                        ${firstGroupRow ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.purok) + '</td>' : ''}
                        ${firstGroupRow ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.address) + '</td>' : ''}
                        <td><span class="member-count-badge">0</span></td>
                        <td colspan="14" style="color:#6c757d; font-style:italic;">No members</td>
                    </tr>`;
                firstGroupRow = false;
                return;
            }

            members.forEach(function(member, idx) {
                displayCount++;
                var showGroupCells = firstGroupRow && idx === 0;
                var showMemberCount = idx === 0;
                var memberCountRowspan = memberCount || 1;

                var displayAge = member.age || '';
                if (member.date_of_birth) {
                    var calcAge = calculateAgeFromDateStr(member.date_of_birth);
                    if (calcAge !== null) displayAge = calcAge;
                }

                var hasAccount = parseInt(member.has_account || 0) === 1;
                var rowClass = hasAccount ? 'member-has-account' : '';

                var accountBadge = hasAccount
                    ? ' <span class="account-badge" title="This resident has a registered account"><i class="fas fa-check-circle"></i> Registered</span>'
                    : '';

                var lastNameCell;
                if (hasAccount) {
                    lastNameCell = '<a href="javascript:void(0);" class="member-lastname-link" ' +
                                   'onclick="openResidentDetailModal(' + member.member_id + ')" ' +
                                   'title="View resident analytics" ' +
                                   'style="color:#1e7e6c;font-weight:700;text-decoration:none;border-bottom:1px dashed #7fbfa4;cursor:pointer;">' +
                                   escapeHtml(member.last_name || '') + '</a>' + accountBadge;
                } else {
                    lastNameCell = escapeHtml(member.last_name || '') + accountBadge;
                }

                html += `
                    <tr class="${rowClass}">
                        ${showGroupCells ? '<td class="row-number" rowspan="' + groupRowspan + '">' + rowNumber + '</td>' : ''}
                        ${showGroupCells ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.purok) + '</td>' : ''}
                        ${showGroupCells ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.address) + '</td>' : ''}
                        ${showMemberCount ? '<td rowspan="' + memberCountRowspan + '"><span class="member-count-badge">' + memberCount + '</span></td>' : ''}
                        <td>${lastNameCell}</td>
                        <td>${escapeHtml(member.first_name || '')}</td>
                        <td>${escapeHtml(member.middle_name || '')}</td>
                        <td>${escapeHtml(member.ext || '')}</td>
                        <td>${escapeHtml(member.place_of_birth || '')}</td>
                        <td>${escapeHtml(member.date_of_birth || '')}</td>
                        <td>${displayAge}</td>
                        <td>${escapeHtml(member.sex || '')}</td>
                        <td>${escapeHtml(member.civil_status || '')}</td>
                        <td>${escapeHtml(member.citizenship || '')}</td>
                        <td>${escapeHtml(member.occupation || '')}</td>
                        <td>${escapeHtml(member.employment_status || '')}</td>
                        <td>${escapeHtml(member.email || '')}</td>
                        <td>${escapeHtml(member.phone_number || '')}</td>
                    </tr>`;
                firstGroupRow = false;
                rowNumber++;
            });
        });
    });

    tbody.innerHTML = html;
    setTimeout(refitAllHouseholdTables, 30);
}

// ============ RESIDENT ANALYTICS (READ-ONLY) ============
function openResidentDetailModal(memberId) {
    var modal = document.getElementById('residentDetailModal');
    var body = document.getElementById('residentDetailBody');
    if (!modal || !body) return;

    body.innerHTML = `<div style="text-align:center; padding:40px; color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:32px;"></i><p>Loading analytics...</p></div>`;
    modal.style.display = 'flex';

    try { if (rdCharts.docs) { rdCharts.docs.destroy(); rdCharts.docs = null; } } catch(e){}
    try { if (rdCharts.equipment) { rdCharts.equipment.destroy(); rdCharts.equipment = null; } } catch(e){}
    try { if (rdCharts.complaints) { rdCharts.complaints.destroy(); rdCharts.complaints = null; } } catch(e){}
    rdComplaintsPieData = { labels: [], values: [], colors: [] };

    fetch(CPT_HOUSEHOLD_ENDPOINT + '?action=get_resident_analytics&member_id=' + memberId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                body.innerHTML = `<div style="text-align:center; padding:30px;"><div style="font-size:44px; color:#ff9800; margin-bottom:8px;"><i class="fas fa-info-circle"></i></div><p style="color:#555;">${escapeHtml(data.message || 'Failed to load analytics.')}</p></div>`;
                return;
            }
            renderResidentDetail(data);
            setTimeout(function() { drawResidentCharts(data); }, 60);
        })
        .catch(function() {
            body.innerHTML = `<div style="text-align:center; padding:30px;"><div style="font-size:44px; color:#dc3545; margin-bottom:8px;"><i class="fas fa-times-circle"></i></div><p style="color:#555;">Could not load analytics. Please try again.</p></div>`;
        });
}

function closeResidentDetailModal() {
    var modal = document.getElementById('residentDetailModal');
    if (modal) modal.style.display = 'none';
    try { if (rdCharts.docs) { rdCharts.docs.destroy(); rdCharts.docs = null; } } catch(e){}
    try { if (rdCharts.equipment) { rdCharts.equipment.destroy(); rdCharts.equipment = null; } } catch(e){}
    try { if (rdCharts.complaints) { rdCharts.complaints.destroy(); rdCharts.complaints = null; } } catch(e){}
}

function renderResidentDetail(data) {
    var m = data.member || {};
    var r = data.resident || {};
    var docs = data.documents || { total: 0, byType: {}, byStatus: {} };
    var eq = data.equipment || { total: 0, byItem: {}, byStatus: {} };
    var cmp = data.complaints || {
        total: 0,
        as_complainant: { total: 0, resolved: 0, unresolved: 0, pending: 0, list: [] },
        as_respondent: { total: 0, resolved: 0, unresolved: 0, pending: 0, list: [] },
        most_recent: null
    };

    var first = r.first_name || m.first_name || '';
    var last = r.last_name || m.last_name || '';
    var initials = ((first.charAt(0) || '') + (last.charAt(0) || '')).toUpperCase();

    var fullName = (last ? last + ', ' : '') + first + (m.middle_name ? ' ' + m.middle_name : '');
    var accountStatus = '';
    if (r.is_active == 0) accountStatus = '<span class="rd-badge" style="background:#ffebee;color:#c62828;border-color:#f5c6c6;"><i class="fas fa-user-slash"></i> Inactive</span>';
    else if (r.is_verified == 0) accountStatus = '<span class="rd-badge" style="background:#fff8e1;color:#b26a00;border-color:#f0d99a;"><i class="fas fa-hourglass-half"></i> Pending</span>';
    else accountStatus = '<span class="rd-badge"><i class="fas fa-check-circle"></i> Active</span>';

    var householdLine = (m.purok ? 'Purok ' + escapeHtml(m.purok) + ' • ' : '') + (m.household_address ? escapeHtml(m.household_address) : '');
    var combinedItemCount = Object.keys(eq.byItem).length;

    var html = `
        <div class="rd-header">
            <div class="rd-avatar">${escapeHtml(initials || '?')}</div>
            <div style="flex:1;">
                <div class="rd-name">${escapeHtml(fullName.trim())} ${accountStatus}</div>
                <div class="rd-sub"><i class="fas fa-home"></i> ${householdLine || 'No household info'}</div>
                <div class="rd-sub"><i class="fas fa-envelope"></i> ${escapeHtml(r.email || '—')} &nbsp; <i class="fas fa-user"></i> ${escapeHtml(r.username || '—')}</div>
            </div>
        </div>

        <div class="rd-stats">
            <div class="rd-stat">
                <div class="label">Document Requests</div>
                <div class="value">${docs.total}</div>
                <div class="hint">${Object.keys(docs.byType).length} type(s)</div>
            </div>
            <div class="rd-stat blue">
                <div class="label">Equipment / Facility / Vehicle</div>
                <div class="value">${eq.total}</div>
                <div class="hint">${combinedItemCount} item(s)</div>
            </div>
            <div class="rd-stat orange">
                <div class="label">Complaints</div>
                <div class="value">${cmp.total}</div>
                <div class="hint">${(cmp.as_complainant.total || 0)} filed • ${(cmp.as_respondent.total || 0)} against</div>
            </div>
        </div>

        <div class="rd-section">
            <h4><i class="fas fa-file-alt"></i> Document Requests by Type</h4>
            <div class="rd-charts">
                <div class="rd-chart-box">
                    <h5>Distribution</h5>
                    <div class="rd-chart-wrap"><canvas id="rdDocsChart"></canvas></div>
                </div>
                <div class="rd-chart-box">
                    <h5>Breakdown</h5>
                    ${Object.keys(docs.byType).length === 0
                        ? '<div class="rd-empty">No document requests yet.</div>'
                        : '<ul class="rd-list">' + Object.keys(docs.byType).map(function(k){
                            return '<li><span>' + escapeHtml(k) + '</span><span class="pill">' + docs.byType[k] + '</span></li>';
                          }).join('') + '</ul>'}
                </div>
            </div>
        </div>

        <div class="rd-section">
            <h4><i class="fas fa-tools"></i> Equipment / Facility / Vehicle Requests</h4>
            <div class="rd-charts">
                <div class="rd-chart-box">
                    <h5>Frequency (all types)</h5>
                    <div class="rd-chart-wrap"><canvas id="rdEquipmentChart"></canvas></div>
                </div>
                <div class="rd-chart-box">
                    <h5>Breakdown</h5>
                    ${Object.keys(eq.byItem).length === 0
                        ? '<div class="rd-empty">No requests yet.</div>'
                        : '<ul class="rd-list">' + Object.keys(eq.byItem).map(function(k){
                            return '<li><span>' + escapeHtml(k) + '</span><span class="pill">' + eq.byItem[k] + '</span></li>';
                          }).join('') + '</ul>'}
                </div>
            </div>
        </div>

        <div class="rd-section">
            <h4><i class="fas fa-exclamation-circle"></i> Complaints — Filed vs Filed Against</h4>
            ${cmp.total === 0 ? '<div class="rd-empty">No complaints related to this resident.</div>' : buildComplaintsBlock(cmp)}
        </div>
    `;
    document.getElementById('residentDetailBody').innerHTML = html;
}

function buildComplaintsBlock(cmp) {
    var asComp = cmp.as_complainant || { total: 0, resolved: 0, unresolved: 0, pending: 0, list: [] };
    var asResp = cmp.as_respondent  || { total: 0, resolved: 0, unresolved: 0, pending: 0, list: [] };
    var recent = cmp.most_recent;

    var recentHtml = '';
    if (recent) {
        var recentClass = recent._class === 'resolved' ? 'recent-resolved' :
                          recent._class === 'pending'  ? 'recent-pending'  : 'recent-unresolved';
        var roleLabel = recent._role === 'complainant' ? 'Filed by this resident' : 'Filed against this resident';
        var roleColor = recent._role === 'complainant' ? '#2ea05a' : '#c0392b';
        var recentStatusLabel = recent.status.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        recentHtml = `
            <div class="rd-recent ${recentClass}">
                <div class="rd-recent-label">
                    <i class="fas fa-star" style="color:#ffb300;"></i> Most Recent Complaint
                    <span class="rd-recent-role" style="background:${roleColor};">${roleLabel}</span>
                </div>
                <div class="rd-recent-title">${escapeHtml(recent.title || '')}</div>
                <div class="rd-recent-meta">
                    <span><i class="fas fa-hashtag"></i> ${escapeHtml(recent.reference_number || '')}</span>
                    <span><i class="fas fa-calendar-alt"></i> ${escapeHtml((recent.created_at || '').substring(0,10))}</span>
                    <span><i class="fas fa-circle" style="color:${recent._class === 'resolved' ? '#2ea05a' : recent._class === 'pending' ? '#ff9800' : '#dc3545'}; font-size:8px;"></i> ${escapeHtml(recentStatusLabel)}</span>
                </div>
            </div>`;
    }

    var pieLabels = [], pieValues = [], pieColors = [];
    if (asComp.resolved > 0)   { pieLabels.push('Filed — Resolved');     pieValues.push(asComp.resolved);   pieColors.push('#4caf50'); }
    if (asComp.unresolved > 0) { pieLabels.push('Filed — Unresolved');   pieValues.push(asComp.unresolved); pieColors.push('#ff9800'); }
    if (asComp.pending > 0)    { pieLabels.push('Filed — Pending');      pieValues.push(asComp.pending);    pieColors.push('#ffd54f'); }
    if (asResp.resolved > 0)   { pieLabels.push('Against — Resolved');   pieValues.push(asResp.resolved);   pieColors.push('#2196f3'); }
    if (asResp.unresolved > 0) { pieLabels.push('Against — Unresolved'); pieValues.push(asResp.unresolved); pieColors.push('#e91e63'); }
    if (asResp.pending > 0)    { pieLabels.push('Against — Pending');    pieValues.push(asResp.pending);    pieColors.push('#9c27b0'); }

    rdComplaintsPieData = { labels: pieLabels, values: pieValues, colors: pieColors };

    var pieCanvas = (pieLabels.length > 0) ? '<canvas id="rdComplaintsChart"></canvas>' : '<div class="rd-empty">No chart data yet.</div>';

    var listHtml = function(list) {
        if (!list || list.length === 0) return '<div class="rd-empty" style="padding:12px 0;">None</div>';
        return '<ul class="rd-complist">' + list.map(function(c) {
            var cls = c._class === 'resolved' ? 'rc-resolved' :
                      c._class === 'pending'  ? 'rc-pending'  : 'rc-unresolved';
            var statusLabel = c.status.replace(/_/g, ' ').replace(/\b\w/g, function(s){ return s.toUpperCase(); });
            return '<li class="' + cls + '">' +
                     '<div class="rc-ref">' + escapeHtml(c.reference_number || '') + '</div>' +
                     '<div class="rc-title">' + escapeHtml(c.title || '') + '</div>' +
                     '<div class="rc-meta"><span>' + escapeHtml((c.created_at || '').substring(0,10)) + '</span><span class="rc-status">' + escapeHtml(statusLabel) + '</span></div>' +
                   '</li>';
        }).join('') + '</ul>';
    };

    return `
        ${recentHtml}
        <div class="rd-charts" style="grid-template-columns: 1fr 1fr;">
            <div class="rd-chart-box">
                <h5>Overall Breakdown</h5>
                <div class="rd-chart-wrap">${pieCanvas}</div>
            </div>
            <div class="rd-chart-box">
                <h5>Summary</h5>
                <ul class="rd-list" style="margin-top:4px;">
                    <li><span><i class="fas fa-file-signature" style="color:#2ea05a;"></i> Filed by resident</span><span class="pill">${asComp.total}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#4caf50;">Resolved</span><span class="pill" style="background:#e8f5e9;color:#2e7d32;">${asComp.resolved}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#c62828;">Unresolved</span><span class="pill" style="background:#ffebee;color:#c62828;">${asComp.unresolved}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#b26a00;">Pending</span><span class="pill" style="background:#fff8e1;color:#b26a00;">${asComp.pending}</span></li>
                    <li style="margin-top:6px;border-top:1px solid #eef2ef;padding-top:8px;"><span><i class="fas fa-user-shield" style="color:#c0392b;"></i> Filed against resident</span><span class="pill">${asResp.total}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#4caf50;">Resolved</span><span class="pill" style="background:#e8f5e9;color:#2e7d32;">${asResp.resolved}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#c62828;">Unresolved</span><span class="pill" style="background:#ffebee;color:#c62828;">${asResp.unresolved}</span></li>
                    <li><span style="padding-left:14px;font-size:12px;color:#b26a00;">Pending</span><span class="pill" style="background:#fff8e1;color:#b26a00;">${asResp.pending}</span></li>
                </ul>
            </div>
        </div>
        <div class="rd-charts" style="grid-template-columns: 1fr 1fr; margin-top:12px;">
            <div class="rd-chart-box"><h5><i class="fas fa-file-signature" style="color:#2ea05a;"></i> Filed by this resident</h5>${listHtml(asComp.list)}</div>
            <div class="rd-chart-box"><h5><i class="fas fa-user-shield" style="color:#c0392b;"></i> Filed against this resident</h5>${listHtml(asResp.list)}</div>
        </div>`;
}

function drawResidentCharts(data) {
    if (!window.Chart) { setTimeout(function() { drawResidentCharts(data); }, 150); return; }
    var docs = data.documents || { byType: {} };
    var eq = data.equipment || { byItem: {} };

    var docLabels = Object.keys(docs.byType);
    var docValues = docLabels.map(function(k){ return docs.byType[k]; });
    var docCanvas = document.getElementById('rdDocsChart');
    if (docCanvas) {
        if (docLabels.length === 0) docCanvas.parentNode.innerHTML = '<div class="rd-empty">No document requests yet.</div>';
        else rdCharts.docs = new Chart(docCanvas.getContext('2d'), {
            type: 'doughnut',
            data: { labels: docLabels, datasets: [{ data: docValues, backgroundColor: ['#2ea05a','#2196f3','#ff9800','#9c27b0','#e91e63','#00bcd4','#8bc34a','#ffc107','#795548','#607d8b'], borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12 } } }, cutout: '60%' }
        });
    }

    var eqLabels = Object.keys(eq.byItem || {});
    var eqValues = eqLabels.map(function(k){ return eq.byItem[k]; });
    var eqCanvas = document.getElementById('rdEquipmentChart');
    if (eqCanvas) {
        if (eqLabels.length === 0) eqCanvas.parentNode.innerHTML = '<div class="rd-empty">No requests yet.</div>';
        else rdCharts.equipment = new Chart(eqCanvas.getContext('2d'), {
            type: 'bar',
            data: { labels: eqLabels, datasets: [{ label: 'Times Requested', data: eqValues, backgroundColor: 'rgba(33,150,243,0.75)', borderColor: '#1565c0', borderWidth: 1.5, borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#eef2ef' } },
                          x: { ticks: { font: { size: 10 }, autoSkip: false, maxRotation: 45, minRotation: 0 }, grid: { display: false } } }
            }
        });
    }

    var cmpPie = document.getElementById('rdComplaintsChart');
    if (cmpPie && rdComplaintsPieData && rdComplaintsPieData.labels && rdComplaintsPieData.labels.length > 0) {
        rdCharts.complaints = new Chart(cmpPie.getContext('2d'), {
            type: 'pie',
            data: { labels: rdComplaintsPieData.labels, datasets: [{ data: rdComplaintsPieData.values, backgroundColor: rdComplaintsPieData.colors, borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, padding: 8 } } } }
        });
    }
}

// ============ PAGINATION ============
function renderHouseholdPagination(data) {
    var container = document.getElementById('householdPagination');
    if (!container) return;
    if (data.totalPages <= 1) { container.innerHTML = ''; return; }

    var html = '<div class="pagination-wrapper"><div class="pagination">';
    if (data.currentPage > 1) html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + (data.currentPage - 1) + ', \'' + currentHouseholdSearch + '\')"><i class="fas fa-chevron-left"></i> Prev</button>';
    else html += '<button class="page-btn disabled" disabled><i class="fas fa-chevron-left"></i> Prev</button>';

    var maxVisible = 5;
    var startPage = Math.max(1, data.currentPage - Math.floor(maxVisible / 2));
    var endPage = Math.min(data.totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);

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

    if (data.currentPage < data.totalPages) html += '<button class="page-btn" onclick="loadHouseholds(\'' + currentHouseholdPurok + '\', ' + (data.currentPage + 1) + ', \'' + currentHouseholdSearch + '\')">Next <i class="fas fa-chevron-right"></i></button>';
    else html += '<button class="page-btn disabled" disabled>Next <i class="fas fa-chevron-right"></i></button>';

    html += '</div></div>';
    container.innerHTML = html;
}

// ============ STATS ============
function updateHouseholdStats(data) {
    var totalEl = document.getElementById('totalHouseholds');
    var membersEl = document.getElementById('totalMembers');
    var puroksEl = document.getElementById('totalPuroks');
    if (totalEl) totalEl.textContent = data.total || 0;

    fetch(CPT_HOUSEHOLD_ENDPOINT + '?action=get_household_counts')
        .then(function(r) { return r.json(); })
        .then(function(cd) {
            if (cd.success) {
                if (membersEl) membersEl.textContent = cd.totalMembers || 0;
                if (puroksEl) puroksEl.textContent = Object.keys(cd.data || {}).length || 0;
            }
        })
        .catch(function(err) { console.error(err); });
}

// ============ PUROK FILTER ============
function loadPurokFilter(purokCounts) {
    var select = document.getElementById('purokFilter');
    if (!select) return;
    if (select.options.length <= 1) {
        fetch(CPT_HOUSEHOLD_ENDPOINT + '?action=get_puroks')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    select.innerHTML = '<option value="all">All Puroks</option>';
                    data.data.forEach(function(purok) {
                        var count = purokCounts[purok] || 0;
                        select.innerHTML += '<option value="' + escapeHtml(purok) + '">Purok ' + escapeHtml(purok) + ' (' + count + ')</option>';
                    });
                    if (currentHouseholdPurok !== 'all') select.value = currentHouseholdPurok;
                }
            });
    } else {
        select.querySelectorAll('option').forEach(function(option) {
            if (option.value !== 'all') {
                var count = purokCounts[option.value] || 0;
                option.textContent = 'Purok ' + option.value + ' (' + count + ')';
            }
        });
    }
}

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

// ============ ENTRY HOOK FOR CAPTAIN DASHBOARD ============
window.__renderHouseholdDashboard = function() {
    var body = document.getElementById('dashboardBody');
    if (!body) return;
    body.innerHTML = renderHouseholdView();
    loadHouseholds('all', 1);
};