// secretary_household.js - Household Management for Secretary Dashboard
// Reuses household_ajax.php (same as admin). UI + behavior identical to admin's household.js.

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

// Modal state
let currentModalHouseholdId = null;
let currentModalMode = 'add';
let confirmationCallback = null;

// In-modal member draft list
let modalMembers = [];

// Resident detail chart handles
var rdCharts = { docs: null, equipment: null, complaints: null };

// Data holder for the complaints pie
var rdComplaintsPieData = { labels: [], values: [], colors: [] };

// ============ RENDER HOUSEHOLD VIEW ============
function renderHouseholdView() {
    return `
        <div class="household-module">
            <style>
                .table-fit-wrapper { width:100%; overflow:hidden; position:relative; }
                .table-fit-inner { width:100%; transform-origin: top left; transition: transform 0.15s ease-out; will-change: transform; }
                .table-fit-inner > table { width: max-content; min-width: 100%; white-space: nowrap; border-collapse: collapse; }
                .table-fit-inner > table th,
                .table-fit-inner > table td { white-space: nowrap; }

                #modalMemberTable thead th .col-hint { display:block; font-size:10px; font-weight:400; color:#8a9a91; margin-top:2px; letter-spacing:0.2px; }
                #modalMemberTable .editable-cell { position:relative; min-width:60px; }
                #modalMemberTable .editable-cell:empty::before { content: attr(data-placeholder); color:#b8c4bd; font-style:italic; pointer-events:none; }
                #modalMemberTable .editable-cell.invalid-cell { background:#fdecec; box-shadow: inset 0 0 0 2px #f5a5a5; }
                #modalMemberTable .editable-cell.valid-cell { background:#eaf7ef; }

                .format-legend { margin-top:10px; padding:10px 14px; background:#f4f8f6; border-left:4px solid #17a2b8; border-radius:8px; font-size:12px; color:#4a5d55; line-height:1.7; }
                .format-legend .legend-item { display:inline-block; margin-right:18px; white-space:nowrap; }
                .format-legend .legend-item i { color:#17a2b8; margin-right:5px; }
                .format-legend code { background:#e6efeb; padding:1px 6px; border-radius:4px; color:#1a472a; font-size:11px; }

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
            </style>
            <div class="content-card">
                <div class="section-title">
                    <div>
                        <i class="fas fa-home"></i> Household Management
                        <span style="font-size:12px; color:#6c757d; margin-left:10px;">Click a row to edit, or use the buttons to add</span>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn-add-household" onclick="openAddHouseholdModal()" style="background:#17a2b8;">
                            <i class="fas fa-plus"></i> Add Household
                        </button>
                        <button class="btn-add-household" onclick="loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch)" style="background:#6c757d;">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="section-sub">Click any household row to edit its details and manage members.</div>

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
                                    <th style="min-width:70px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="householdTableBody">
                                <tr><td colspan="19" class="text-center"><div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="householdPagination"></div>
            </div>
        </div>

        <!-- HOUSEHOLD MODAL -->
        <div id="householdModal" class="modal" style="display:none; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 0;">
            <div class="modal-content" style="max-width: 1300px; width: 95%; margin:0 auto;">
                <div class="modal-header" style="background:#17a2b8;">
                    <h2 id="householdModalTitle"><i class="fas fa-home"></i> Add Household</h2>
                    <button class="close-modal" onclick="closeHouseholdModal()">&times;</button>
                </div>
                <div class="modal-body" style="max-height: 78vh; overflow-y: auto; padding: 20px 25px;">
                    <form id="householdForm" onsubmit="event.preventDefault(); saveHouseholdModal();">
                        <input type="hidden" id="modalHouseholdId">
                        <div class="form-row">
                            <div class="form-group half">
                                <label>Purok *</label>
                                <input type="text" id="modalPurok" class="form-control" required placeholder="e.g. 1, 2, 3...">
                            </div>
                            <div class="form-group half">
                                <label>Household Address *</label>
                                <input type="text" id="modalAddress" class="form-control" required placeholder="e.g. 123 Main St.">
                            </div>
                        </div>
                        <div style="margin-top:20px; border-top:1px solid #eef2ef; padding-top:15px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:10px;">
                                <h3 style="margin:0; font-size:1.1rem; color:#1a472a;">
                                    <i class="fas fa-users"></i> Members
                                    <span id="modalMemberCount" style="font-size:0.9rem; color:#6c757d; margin-left:8px;">(0)</span>
                                </h3>
                                <button type="button" class="btn-add-household" style="background:#28a745;" onclick="addModalMemberRow()">
                                    <i class="fas fa-user-plus"></i> Add Member
                                </button>
                            </div>
                            <div class="table-fit-wrapper" id="modalTableFitWrapper">
                                <div class="table-fit-inner" id="modalTableFitInner">
                                    <table class="household-table" id="modalMemberTable" style="font-size:13px;">
                                        <thead>
                                            <tr>
                                                <th style="min-width:110px;">Last Name *<span class="col-hint">required</span></th>
                                                <th style="min-width:90px;">First Name<span class="col-hint">optional</span></th>
                                                <th style="min-width:90px;">Middle Name<span class="col-hint">optional</span></th>
                                                <th style="min-width:50px;">Ext<span class="col-hint">Jr/Sr/III</span></th>
                                                <th style="min-width:130px;">Place of Birth<span class="col-hint">city, province</span></th>
                                                <th style="min-width:140px;">Date of Birth<span class="col-hint">YYYY-MM-DD</span></th>
                                                <th style="min-width:50px;">Age<span class="col-hint">auto</span></th>
                                                <th style="min-width:70px;">Sex<span class="col-hint">M or F</span></th>
                                                <th style="min-width:100px;">Civil Status<span class="col-hint">Single/Married…</span></th>
                                                <th style="min-width:100px;">Citizenship<span class="col-hint">e.g. Filipino</span></th>
                                                <th style="min-width:110px;">Occupation<span class="col-hint">optional</span></th>
                                                <th style="min-width:120px;">Employment<span class="col-hint">Employed/Student…</span></th>
                                                <th style="min-width:150px;">Email<span class="col-hint">name@mail.com</span></th>
                                                <th style="min-width:120px;">Phone Number<span class="col-hint">digits only</span></th>
                                                <th style="width:60px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modalMembersBody">
                                            <tr><td colspan="15" class="text-center" style="color:#6c757d; padding:20px;">No members yet. Click "Add Member".</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="format-legend">
                                <span class="legend-item"><i class="fas fa-calendar-alt"></i> Date of Birth: <code>YYYY-MM-DD</code> (e.g. <code>1990-05-14</code>)</span>
                                <span class="legend-item"><i class="fas fa-venus-mars"></i> Sex: <code>M</code> or <code>F</code></span>
                                <span class="legend-item"><i class="fas fa-envelope"></i> Email: <code>name@mail.com</code></span>
                                <span class="legend-item"><i class="fas fa-phone"></i> Phone: digits only, optional <code>+</code></span>
                            </div>
                        </div>
                        <div class="form-buttons" style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                            <button type="button" class="btn-close" onclick="closeHouseholdModal()" style="background:#6c757d; color:white; border:none; padding:10px 25px; border-radius:8px; cursor:pointer;">Cancel</button>
                            <button type="submit" class="btn-verify" style="background:#28a745; color:white; border:none; padding:10px 25px; border-radius:8px; cursor:pointer; font-weight:600;">
                                <i class="fas fa-save"></i> Save Household
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- RESIDENT DETAIL MODAL -->
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

        <!-- CONFIRMATION MODAL -->
        <div id="confirmationModal" class="modal" style="display:none; z-index:2200; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
            <div class="confirmation-modal-content" style="background:white; border-radius:20px; max-width:440px; width:90%; text-align:center; padding:30px 25px; box-shadow:0 25px 60px rgba(0,0,0,0.3); animation: slideDown 0.25s ease;">
                <div style="width:80px; height:80px; border-radius:50%; background:#fff4e5; display:flex; align-items:center; justify-content:center; margin: 0 auto 18px;">
                    <i class="fas fa-exclamation-triangle" style="font-size:38px; color:#ff9800;"></i>
                </div>
                <div id="confirmationModalTitle" style="font-size:1.25rem; font-weight:700; color:#1a472a; margin-bottom:10px;">Confirm</div>
                <div id="confirmationModalMessage" style="color:#5f7f6e; font-size:15px; line-height:1.6; margin-bottom:25px;">Are you sure?</div>
                <div style="display:flex; gap:12px; justify-content:center;">
                    <button id="confirmationModalConfirm" style="background:#ff9800; color:white; border:none; padding:11px 28px; border-radius:40px; font-weight:600; cursor:pointer; font-size:15px; transition:all 0.2s; min-width:130px;">
                        <i class="fas fa-check"></i> Yes, Proceed
                    </button>
                    <button onclick="closeConfirmationModal()" style="background:#e9ecef; color:#495057; border:none; padding:11px 28px; border-radius:40px; font-weight:600; cursor:pointer; font-size:15px; transition:all 0.2s; min-width:100px;">
                        Cancel
                    </button>
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
    fitTableToWidth('modalTableFitWrapper', 'modalTableFitInner');
}

(function () {
    var t;
    window.addEventListener('resize', function () {
        clearTimeout(t);
        t = setTimeout(refitAllHouseholdTables, 120);
    });
})();

// ============ CAPITALIZATION HELPERS ============
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
function capitalizeNameField(v) { return v ? capitalizeText(v) : ''; }
function capitalizePlaceOfBirth(v) { if (!v) return ''; return v.split(',').map(function(p) { return capitalizeText(p.trim()); }).join(', '); }
function capitalizeCivilStatus(v) {
    if (!v) return '';
    var valid = ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'];
    var m = valid.find(function(s) { return s.toLowerCase() === v.toLowerCase(); });
    return m || capitalizeText(v);
}
function capitalizeEmploymentStatus(v) {
    if (!v) return '';
    var valid = ['Employed', 'Unemployed', 'Solo Parent', 'OSY', 'OSC', 'Student', 'Retired'];
    var m = valid.find(function(s) { return s.toLowerCase() === v.toLowerCase(); });
    return m || capitalizeText(v);
}

// ============ VALIDATION HELPERS ============
function isValidDateYMD(val) {
    if (!val) return true;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(val)) return false;
    var parts = val.split('-');
    var y = parseInt(parts[0]), m = parseInt(parts[1]), d = parseInt(parts[2]);
    if (m < 1 || m > 12) return false;
    if (d < 1 || d > 31) return false;
    var dt = new Date(y, m - 1, d);
    return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
}
function isValidSex(val) { if (!val) return true; return val === 'M' || val === 'F'; }
function isValidEmail(val) { if (!val) return true; return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val); }
function isValidPhone(val) { if (!val) return true; return /^\+?\d{7,15}$/.test(val); }

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
        let url = 'secretary_household_ajax.php?action=get_households&page=' + page + '&per_page=50';
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
        document.getElementById('householdTableBody').innerHTML = '<tr><td colspan="19" class="text-center">Error loading data. Please refresh.</td></tr>';
    }
}

// ============ RENDER TABLE ============
function renderHouseholdTable(data) {
    var tbody = document.getElementById('householdTableBody');

    if (!data.data || data.data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="19" class="text-center"><div class="empty-state"><i class="fas fa-home fa-3x"></i><p>No households found. Click "Add Household" to create one.</p></div></td></tr>`;
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
                    <tr data-household-id="${household.household_id}" style="cursor:pointer;" onclick="openEditHouseholdModal(${household.household_id})">
                        ${firstGroupRow ? '<td class="row-number" rowspan="' + groupRowspan + '">' + displayCount + '</td>' : ''}
                        ${firstGroupRow ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.purok) + '</td>' : ''}
                        ${firstGroupRow ? '<td rowspan="' + groupRowspan + '">' + escapeHtml(group.address) + '</td>' : ''}
                        <td><span class="member-count-badge">0</span></td>
                        <td colspan="14" style="color:#6c757d; font-style:italic;">No members - click row to add</td>
                        <td onclick="event.stopPropagation();">
                            <button class="btn-action delete-household" onclick="deleteHousehold(${household.household_id})" title="Delete Household"><i class="fas fa-trash"></i></button>
                            <button class="btn-action add" onclick="openEditHouseholdModal(${household.household_id})" title="Add Member"><i class="fas fa-user-plus"></i></button>
                        </td>
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
                var rowClass = (showGroupCells ? 'address-first-row ' : 'address-member-row ') +
                               (idx === 0 ? 'household-first-member ' : '') +
                               (hasAccount ? 'member-has-account' : '');

                var accountBadge = hasAccount
                    ? ' <span class="account-badge" title="This resident has a registered account"><i class="fas fa-check-circle"></i> Registered</span>'
                    : '';

                var lastNameCell;
                if (hasAccount) {
                    lastNameCell = '<a href="javascript:void(0);" class="member-lastname-link" ' +
                                   'onclick="event.stopPropagation(); openResidentDetailModal(' + member.member_id + ')" ' +
                                   'title="View resident analytics" ' +
                                   'style="color:#1e7e6c;font-weight:700;text-decoration:none;border-bottom:1px dashed #7fbfa4;cursor:pointer;">' +
                                   escapeHtml(member.last_name || '') + '</a>' + accountBadge;
                } else {
                    lastNameCell = escapeHtml(member.last_name || '') + accountBadge;
                }

                html += `
                    <tr class="${rowClass}"
                        data-household-id="${household.household_id}" data-member-id="${member.member_id}"
                        style="cursor:pointer;" onclick="openEditHouseholdModal(${household.household_id})">
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
                        <td onclick="event.stopPropagation();">
                            ${idx === 0 ? `<button class="btn-action delete-household" onclick="deleteHousehold(${household.household_id})" title="Delete Household"><i class="fas fa-trash"></i></button>` : ''}
                            <button class="btn-action delete" onclick="deleteMember(${member.member_id}, ${household.household_id})" title="Delete Member"><i class="fas fa-user-minus"></i></button>
                            ${idx === 0 ? '<button class="btn-action add" onclick="openEditHouseholdModal(' + household.household_id + ')" title="Add Member"><i class="fas fa-user-plus"></i></button>' : ''}
                        </td>
                    </tr>`;
                firstGroupRow = false;
                rowNumber++;
            });
        });
    });

    tbody.innerHTML = html;
    setTimeout(refitAllHouseholdTables, 30);
}

// ============ HOUSEHOLD MODAL OPEN/CLOSE ============
function openAddHouseholdModal() {
    currentModalMode = 'add';
    currentModalHouseholdId = null;
    modalMembers = [];
    document.getElementById('householdModalTitle').innerHTML = '<i class="fas fa-home"></i> Add Household';
    document.getElementById('modalHouseholdId').value = '';
    document.getElementById('modalPurok').value = '';
    document.getElementById('modalAddress').value = '';
    renderModalMembers();
    document.getElementById('householdModal').style.display = 'flex';
    setTimeout(refitAllHouseholdTables, 60);
}

function openEditHouseholdModal(householdId) {
    currentModalMode = 'edit';
    currentModalHouseholdId = householdId;
    fetch('secretary_household_ajax.php?action=get_household_with_members&household_id=' + householdId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { showErrorModal(data.message || 'Failed to load household.'); return; }
            var h = data.household;
            document.getElementById('householdModalTitle').innerHTML = '<i class="fas fa-home"></i> Edit Household';
            document.getElementById('modalHouseholdId').value = h.id;
            document.getElementById('modalPurok').value = h.purok || '';
            document.getElementById('modalAddress').value = h.address || '';

            modalMembers = (data.members || []).map(function(m) {
                return {
                    id: m.id,
                    last_name: m.last_name || '',
                    first_name: m.first_name || '',
                    middle_name: m.middle_name || '',
                    ext: m.ext || '',
                    place_of_birth: m.place_of_birth || '',
                    date_of_birth: m.date_of_birth || '',
                    age: m.age || '',
                    sex: m.sex || '',
                    civil_status: m.civil_status || '',
                    citizenship: m.citizenship || '',
                    occupation: m.occupation || '',
                    employment_status: m.employment_status || '',
                    email: m.email || '',
                    phone_number: m.phone_number || '',
                    _deleted: false
                };
            });
            renderModalMembers();
            document.getElementById('householdModal').style.display = 'flex';
            setTimeout(refitAllHouseholdTables, 60);
        })
        .catch(function() { showErrorModal('Failed to load household.'); });
}

function closeHouseholdModal() {
    document.getElementById('householdModal').style.display = 'none';
    currentModalHouseholdId = null;
    modalMembers = [];
}

// ============ RENDER MODAL MEMBERS ============
function renderModalMembers() {
    var tbody = document.getElementById('modalMembersBody');
    var activeMembers = modalMembers.filter(function(m) { return !m._deleted; });
    document.getElementById('modalMemberCount').textContent = '(' + activeMembers.length + ')';

    if (activeMembers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="15" class="text-center" style="color:#6c757d; padding:20px;">No members yet. Click "Add Member".</td></tr>';
        attachModalMemberEvents();
        setTimeout(refitAllHouseholdTables, 30);
        return;
    }

    var html = '';
    modalMembers.forEach(function(m, i) {
        if (m._deleted) return;
        html += buildModalMemberRow(m, i);
    });
    tbody.innerHTML = html;
    attachModalMemberEvents();
    setTimeout(refitAllHouseholdTables, 30);
}

function buildModalMemberRow(m, idx) {
    var ageDisplay = m.age || '';
    if (m.date_of_birth) {
        var a = calculateAgeFromDateStr(m.date_of_birth);
        if (a !== null) ageDisplay = a;
    }

    var dobClass = m.date_of_birth ? (isValidDateYMD(m.date_of_birth) ? 'valid-cell' : 'invalid-cell') : '';
    var sexClass = m.sex ? (isValidSex(m.sex) ? 'valid-cell' : 'invalid-cell') : '';
    var emailClass = m.email ? (isValidEmail(m.email) ? 'valid-cell' : 'invalid-cell') : '';
    var phoneClass = m.phone_number ? (isValidPhone(m.phone_number) ? 'valid-cell' : 'invalid-cell') : '';

    return `
        <tr data-modal-idx="${idx}">
            <td class="editable-cell" contenteditable="true" data-field="last_name" data-placeholder="e.g. Dela Cruz">${escapeHtml(m.last_name || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="first_name" data-placeholder="e.g. Juan">${escapeHtml(m.first_name || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="middle_name" data-placeholder="e.g. Santos">${escapeHtml(m.middle_name || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="ext" data-placeholder="Jr/Sr/III">${escapeHtml(m.ext || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="place_of_birth" data-placeholder="City, Province">${escapeHtml(m.place_of_birth || '')}</td>
            <td class="editable-cell date-cell ${dobClass}" contenteditable="true" data-field="date_of_birth" data-placeholder="YYYY-MM-DD">${escapeHtml(m.date_of_birth || '')}</td>
            <td class="age-cell" data-field="age">${ageDisplay}</td>
            <td class="editable-cell ${sexClass}" contenteditable="true" data-field="sex" data-placeholder="M/F">${escapeHtml(m.sex || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="civil_status" data-placeholder="Single/Married...">${escapeHtml(m.civil_status || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="citizenship" data-placeholder="Filipino">${escapeHtml(m.citizenship || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="occupation" data-placeholder="e.g. Teacher">${escapeHtml(m.occupation || '')}</td>
            <td class="editable-cell" contenteditable="true" data-field="employment_status" data-placeholder="Employed/Student...">${escapeHtml(m.employment_status || '')}</td>
            <td class="editable-cell ${emailClass}" contenteditable="true" data-field="email" data-placeholder="name@mail.com">${escapeHtml(m.email || '')}</td>
            <td class="editable-cell ${phoneClass}" contenteditable="true" data-field="phone_number" data-placeholder="09171234567">${escapeHtml(m.phone_number || '')}</td>
            <td>
                <button type="button" class="btn-action delete" onclick="removeModalMemberRow(${idx})" title="Remove"><i class="fas fa-trash"></i></button>
            </td>
        </tr>
    `;
}

function attachModalMemberEvents() {
    var cells = document.querySelectorAll('#modalMembersBody .editable-cell[contenteditable="true"]');
    cells.forEach(function(cell) {
        cell.addEventListener('focus', function() { cell.dataset.originalValue = cell.textContent; });
        cell.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                cell.blur();
                var all = Array.from(document.querySelectorAll('#modalMembersBody .editable-cell[contenteditable="true"]'));
                var i = all.indexOf(cell);
                if (i >= 0 && i < all.length - 1) all[i + 1].focus();
            }
            if (e.key === 'Escape') { cell.textContent = cell.dataset.originalValue || ''; cell.blur(); }
        });
        cell.addEventListener('blur', function() {
            var row = cell.closest('tr');
            if (!row) return;
            var idx = parseInt(row.dataset.modalIdx);
            var field = cell.dataset.field;
            var val = cell.textContent.trim();
            var raw = cell.dataset.originalValue || '';
            if (val === raw) return;

            cell.classList.remove('invalid-cell', 'valid-cell');

            if (field === 'last_name' || field === 'first_name' || field === 'middle_name' || field === 'citizenship') {
                val = capitalizeNameField(val); if (val) cell.classList.add('valid-cell');
            } else if (field === 'place_of_birth') {
                val = capitalizePlaceOfBirth(val); if (val) cell.classList.add('valid-cell');
            } else if (field === 'civil_status') {
                val = capitalizeCivilStatus(val); if (val) cell.classList.add('valid-cell');
            } else if (field === 'employment_status') {
                val = capitalizeEmploymentStatus(val); if (val) cell.classList.add('valid-cell');
            } else if (field === 'occupation') {
                val = val.split(' ').map(capitalizeNameField).join(' '); if (val) cell.classList.add('valid-cell');
            } else if (field === 'sex') {
                val = val.toUpperCase().replace(/[^MF]/g, '');
                if (val.length > 1) val = val.charAt(0);
                if (val && !isValidSex(val)) { cell.classList.add('invalid-cell'); cell.title = 'Sex must be M or F'; }
                else if (val) { cell.classList.add('valid-cell'); cell.title = ''; }
            } else if (field === 'email') {
                val = val.toLowerCase().replace(/\s+/g, '');
                if (val && !isValidEmail(val)) { cell.classList.add('invalid-cell'); cell.title = 'Enter a valid email'; }
                else if (val) { cell.classList.add('valid-cell'); cell.title = ''; }
            } else if (field === 'phone_number') {
                val = val.replace(/[^\d+]/g, '');
                if (val.indexOf('+') > 0) val = val.replace(/\+/g, '');
                if ((val.match(/\+/g) || []).length > 1) val = val.replace(/\+/g, '');
                if (val && !isValidPhone(val)) { cell.classList.add('invalid-cell'); cell.title = 'Enter 7–15 digits'; }
                else if (val) { cell.classList.add('valid-cell'); cell.title = ''; }
            } else if (field === 'date_of_birth') {
                var norm = normalizeDate(val);
                if (norm) val = norm;

                if (val && !isValidDateYMD(val)) {
                    cell.textContent = val;
                    cell.classList.add('invalid-cell');
                    cell.title = 'Date must be YYYY-MM-DD';
                    modalMembers[idx][field] = val;
                    modalMembers[idx].age = '';
                    var ageCell = row.querySelector('.age-cell');
                    if (ageCell) ageCell.textContent = '';
                    return;
                }

                cell.textContent = val;
                modalMembers[idx][field] = val;
                if (val) cell.classList.add('valid-cell');
                var calcAge = calculateAgeFromDateStr(val);
                modalMembers[idx].age = calcAge !== null ? calcAge : '';
                var ageCell2 = row.querySelector('.age-cell');
                if (ageCell2) ageCell2.textContent = (calcAge !== null ? calcAge : '');
                return;
            }

            cell.textContent = val;
            modalMembers[idx][field] = val;
        });
    });
}

// ============ DATE NORMALIZER ============
var MONTH_NAMES = {
    january:1, jan:1, february:2, feb:2, march:3, mar:3, april:4, apr:4, may:5,
    june:6, jun:6, july:7, jul:7, august:8, aug:8, september:9, sep:9, sept:9,
    october:10, oct:10, november:11, nov:11, december:12, dec:12
};
function pad2(n) { return String(n).padStart(2, '0'); }
function buildDate(y, m, d) {
    y = parseInt(y); m = parseInt(m); d = parseInt(d);
    if (isNaN(y) || isNaN(m) || isNaN(d)) return '';
    if (y < 1900 || y > 2100) return '';
    if (m < 1 || m > 12) return '';
    if (d < 1 || d > 31) return '';
    var dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return '';
    return y + '-' + pad2(m) + '-' + pad2(d);
}
function normalizeDate(input) {
    if (!input) return '';
    var raw = String(input).trim();
    if (!raw) return '';

    var s = raw
        .replace(/,/g, ' ')
        .replace(/[\.\/\-]/g, ' ')
        .replace(/(\d+)(st|nd|rd|th)\b/gi, '$1')
        .replace(/\s+/g, ' ')
        .trim();

    var lower = s.toLowerCase();
    var tokens = lower.split(' ');
    var monthNum = null, dayNum = null, yearNum = null;

    for (var i = 0; i < tokens.length; i++) {
        var t = tokens[i];
        if (!t) continue;
        if (MONTH_NAMES.hasOwnProperty(t)) monthNum = MONTH_NAMES[t];
        else if (/^\d{4}$/.test(t)) yearNum = parseInt(t);
        else if (/^\d{1,2}$/.test(t)) { if (dayNum === null) dayNum = parseInt(t); else if (monthNum === null) monthNum = parseInt(t); }
        else if (/^\d+$/.test(t)) {
            if (t.length <= 2) { var yy = parseInt(t); yearNum = yy >= 50 ? 1900 + yy : 2000 + yy; }
            else yearNum = parseInt(t);
        }
    }
    if (monthNum && dayNum && yearNum) return buildDate(yearNum, monthNum, dayNum);

    var nums = tokens.filter(function(x) { return /^\d+$/.test(x); });
    if (nums.length === 3) {
        var a = parseInt(nums[0]), b = parseInt(nums[1]), c = parseInt(nums[2]);
        if (nums[0].length === 4) return buildDate(a, b, c);
        if (nums[2].length === 4) {
            if (a > 12) return buildDate(c, b, a);
            if (b > 12) return buildDate(c, a, b);
            return buildDate(c, a, b);
        }
        if (nums[2].length <= 2) {
            var y2 = parseInt(nums[2]);
            var yyyy = y2 >= 50 ? 1900 + y2 : 2000 + y2;
            if (a > 12) return buildDate(yyyy, b, a);
            return buildDate(yyyy, a, b);
        }
    }
    return raw;
}

// ============ MODAL MEMBER ROW ACTIONS ============
function addModalMemberRow() {
    modalMembers.push({
        id: 0, last_name: '', first_name: '', middle_name: '', ext: '',
        place_of_birth: '', date_of_birth: '', age: '', sex: '',
        civil_status: '', citizenship: 'Filipino', occupation: '',
        employment_status: '', email: '', phone_number: '', _deleted: false
    });
    renderModalMembers();
    setTimeout(function() {
        var rows = document.querySelectorAll('#modalMembersBody tr');
        if (rows.length) {
            var lastRow = rows[rows.length - 1];
            var ln = lastRow.querySelector('[data-field="last_name"]');
            if (ln) ln.focus();
        }
    }, 50);
}

function removeModalMemberRow(idx) {
    var m = modalMembers[idx];
    if (!m) return;
    if (!m.id) { modalMembers.splice(idx, 1); renderModalMembers(); return; }
    var name = (m.first_name ? m.first_name + ' ' : '') + (m.last_name || '');
    showConfirmationModal('Remove member "' + name.trim() + '"? This will be applied when you save.', 'Confirm Remove', function() {
        modalMembers[idx]._deleted = true;
        renderModalMembers();
    });
}

// ============ SAVE HOUSEHOLD ============
async function saveHouseholdModal() {
    var id = document.getElementById('modalHouseholdId').value;
    var purok = capitalizeText(document.getElementById('modalPurok').value.trim());
    var address = capitalizeText(document.getElementById('modalAddress').value.trim());

    if (!purok || !address) { showErrorModal('Purok and Household Address are required.'); return; }

    var activeMembers = modalMembers.filter(function(m) { return !m._deleted; });
    var problems = [];

    for (var i = 0; i < activeMembers.length; i++) {
        var m = activeMembers[i];
        var label = 'Member #' + (i + 1) + (m.last_name ? ' (' + m.last_name + ')' : '');
        if (!m.last_name || !m.last_name.trim()) problems.push(label + ': Last Name is required');
        if (m.date_of_birth && !isValidDateYMD(m.date_of_birth)) problems.push(label + ': Date of Birth must be YYYY-MM-DD');
        if (m.sex && !isValidSex(m.sex)) problems.push(label + ': Sex must be M or F');
        if (m.email && !isValidEmail(m.email)) problems.push(label + ': Email format is invalid');
        if (m.phone_number && !isValidPhone(m.phone_number)) problems.push(label + ': Phone must be 7–15 digits');
    }

    if (problems.length > 0) { showErrorModal('Please fix the following before saving:\n\n• ' + problems.join('\n• '), 'Format Errors'); return; }

    var saveBtn = document.querySelector('#householdForm button[type="submit"]');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    try {
        var fd = new FormData();
        fd.append('action', id ? 'edit_household' : 'add_household');
        if (id) fd.append('id', id);
        fd.append('purok', purok);
        fd.append('address', address);

        var res = await fetch('secretary_household_ajax.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (!data.success) { showErrorModal(data.message || 'Failed to save household.'); return; }
        var householdId = id ? parseInt(id) : parseInt(data.id);

        var errors = [];
        for (var j = 0; j < modalMembers.length; j++) {
            var m = modalMembers[j];
            if (m._deleted && m.id) {
                var dfd = new FormData();
                dfd.append('action', 'delete_member');
                dfd.append('id', m.id);
                var dres = await fetch('secretary_household_ajax.php', { method: 'POST', body: dfd });
                var ddata = await dres.json();
                if (!ddata.success) errors.push(ddata.message);
                continue;
            }
            if (m._deleted) continue;
            if (!m.last_name && !m.id) continue;

            var mfd = new FormData();
            if (m.id) { mfd.append('action', 'edit_member'); mfd.append('id', m.id); }
            else { mfd.append('action', 'add_member'); }
            mfd.append('household_id', householdId);
            mfd.append('last_name', m.last_name || '');
            mfd.append('first_name', m.first_name || '');
            mfd.append('middle_name', m.middle_name || '');
            mfd.append('ext', m.ext || '');
            mfd.append('place_of_birth', m.place_of_birth || '');
            mfd.append('date_of_birth', m.date_of_birth || '');
            mfd.append('age', m.age || 0);
            mfd.append('sex', m.sex || '');
            mfd.append('civil_status', m.civil_status || '');
            mfd.append('citizenship', m.citizenship || 'Filipino');
            mfd.append('occupation', m.occupation || '');
            mfd.append('employment_status', m.employment_status || '');
            mfd.append('email', m.email || '');
            mfd.append('phone_number', m.phone_number || '');

            var mres = await fetch('secretary_household_ajax.php', { method: 'POST', body: mfd });
            var mdata = await mres.json();
            if (!mdata.success) errors.push(mdata.message || 'Failed to save member: ' + (m.last_name || ''));
        }

        if (errors.length > 0) showWarningModal('Household saved, but some members failed:\n\n' + errors.join('\n'), 'Partial Save');
        else showSuccessModal('Household and members saved successfully.', 'Saved');

        closeHouseholdModal();
        loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch);
    } catch (e) {
        console.error(e);
        showErrorModal('An error occurred while saving.');
    } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Household'; }
    }
}

// ============ RESIDENT DETAIL MODAL ============
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

    fetch('secretary_household_ajax.php?action=get_resident_analytics&member_id=' + memberId)
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

// ============ COMPLAINTS BLOCK ============
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

// ============ DELETE ============
function deleteMember(memberId, householdId) {
    showConfirmationModal('Delete this member? This action cannot be undone.', 'Confirm Delete', async function() {
        try {
            var fd = new FormData();
            fd.append('action', 'delete_member');
            fd.append('id', memberId);
            var res = await fetch('secretary_household_ajax.php', { method: 'POST', body: fd });
            var data = await res.json();
            if (data.success) { showSuccessModal(data.message || 'Member deleted.', 'Deleted'); loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch); }
            else showErrorModal(data.message || 'Delete failed.');
        } catch (e) { showErrorModal('An error occurred.'); }
    });
}

function deleteHousehold(householdId) {
    fetch('secretary_household_ajax.php?action=get_household_members&household_id=' + householdId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data.length > 0) {
                showErrorModal('Cannot delete this household because it has ' + data.data.length + ' member(s). Please delete all members first.', 'Cannot Delete');
                return;
            }
            showConfirmationModal('Are you sure you want to delete this household? This action cannot be undone.', 'Confirm Delete', async function() {
                try {
                    var fd = new FormData();
                    fd.append('action', 'delete_household');
                    fd.append('id', householdId);
                    var res = await fetch('secretary_household_ajax.php', { method: 'POST', body: fd });
                    var data = await res.json();
                    if (data.success) { showSuccessModal(data.message || 'Household deleted.', 'Deleted'); loadHouseholds(currentHouseholdPurok, currentHouseholdPage, currentHouseholdSearch); }
                    else showErrorModal(data.message || 'Delete failed.');
                } catch (e) { showErrorModal('An error occurred.'); }
            });
        })
        .catch(function() { showErrorModal('An error occurred while checking members.'); });
}

// ============ CONFIRMATION MODAL ============
function showConfirmationModal(message, title, onConfirm) {
    document.getElementById('confirmationModalTitle').textContent = title || 'Confirm';
    document.getElementById('confirmationModalMessage').textContent = message;
    var modal = document.getElementById('confirmationModal');
    modal.style.display = 'flex';
    confirmationCallback = onConfirm;

    var yesBtn = document.getElementById('confirmationModalConfirm');
    var newBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newBtn, yesBtn);
    newBtn.addEventListener('click', function() {
        modal.style.display = 'none';
        if (typeof confirmationCallback === 'function') confirmationCallback();
        confirmationCallback = null;
    });
}
function closeConfirmationModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    confirmationCallback = null;
}

// ============ NOTIFICATIONS ============
function showNotificationModal(type, title, message) {
    var color, iconHtml;
    switch (type) {
        case 'success': color = '#28a745'; iconHtml = '<i class="fas fa-check-circle"></i>'; break;
        case 'error': color = '#dc3545'; iconHtml = '<i class="fas fa-times-circle"></i>'; break;
        case 'warning': color = '#ff9800'; iconHtml = '<i class="fas fa-exclamation-triangle"></i>'; break;
        default: color = '#17a2b8'; iconHtml = '<i class="fas fa-info-circle"></i>';
    }
    var existing = document.querySelector('.notification-modal');
    if (existing) existing.remove();
    var modalHtml = `
        <div class="notification-modal" style="display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:2300; align-items:center; justify-content:center;">
            <div class="notification-modal-content" style="background:white; border-radius:16px; max-width:500px; width:90%; padding:25px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="font-size:50px; text-align:center; margin-bottom:12px; color:${color};">${iconHtml}</div>
                <div style="font-size:20px; font-weight:700; text-align:center; margin-bottom:10px; color:${color};">${escapeHtml(title)}</div>
                <div style="font-size:14px; color:#555; text-align:left; line-height:1.7; white-space:pre-wrap; max-height:300px; overflow-y:auto;">${escapeHtml(message)}</div>
                <div style="text-align:center; margin-top:20px;">
                    <button onclick="this.closest('.notification-modal').remove()" style="background:${color}; color:white; border:none; padding:10px 30px; border-radius:8px; cursor:pointer; font-weight:600;">OK</button>
                </div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}
function showSuccessModal(msg, title) { showNotificationModal('success', title || 'Success!', msg); }
function showErrorModal(msg, title) { showNotificationModal('error', title || 'Error!', msg); }
function showWarningModal(msg, title) { showNotificationModal('warning', title || 'Warning!', msg); }

// ============ UTILITIES ============
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
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

    fetch('secretary_household_ajax.php?action=get_household_counts')
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
        fetch('secretary_household_ajax.php?action=get_puroks')
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

// ============ SIDEBAR HOOK (deferred to secretary_dashboard.php) ============
// The Secretary dashboard's own nav click handler will call this function
// to render the household view into #dashboardBody.
window.__renderHouseholdDashboard = function() {
    var body = document.getElementById('dashboardBody');
    if (!body) return;
    body.innerHTML = renderHouseholdView();
    loadHouseholds('all', 1);
};