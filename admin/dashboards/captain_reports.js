/* BRITE Reports & Analytics — professional in-dashboard module; styles are embedded. */
(function injectBriteReportsStyles() {
    if (document.getElementById('briteReportsEmbeddedStyles')) return;
    var style = document.createElement('style');
    style.id = 'briteReportsEmbeddedStyles';
    style.textContent = `
.br-reports-module{--br-green:#236b3b;--br-green-dark:#174b2a;--br-green-soft:#edf6ef;--br-border:#e0e9e2;--br-muted:#718078;--br-ink:#24352a;color:var(--br-ink);width:100%;}
.br-reports-module *{box-sizing:border-box}.br-report-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:23px 25px;margin-bottom:17px;border-radius:14px;background:linear-gradient(120deg,#174b2a 0%,#267344 72%,#368653 100%);color:#fff;box-shadow:0 5px 18px rgba(23,75,42,.12)}
.br-report-eyebrow{font-size:11px;letter-spacing:1.4px;text-transform:uppercase;font-weight:800;opacity:.78;margin-bottom:8px}.br-report-hero h2{font-size:24px;line-height:1.2;margin:0 0 8px;color:#fff;font-weight:800}.br-report-hero p{margin:0;max-width:620px;font-size:13px;line-height:1.65;color:rgba(255,255,255,.83)}
.br-report-hero-meta{flex:0 0 auto;text-align:right;font-size:12px;line-height:1.8;color:rgba(255,255,255,.82)}.br-report-hero-meta strong{display:block;color:#fff;font-size:13px}
.br-report-toolbar{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;padding:16px;margin-bottom:14px;border:1px solid var(--br-border);border-radius:12px;background:#fff}.br-report-field{display:flex;flex-direction:column;gap:6px;min-width:145px;flex:1}.br-report-field label{font-size:11px;font-weight:800;color:#586b5e;text-transform:uppercase;letter-spacing:.45px}.br-report-field .form-control{width:100%;min-height:40px;padding:9px 11px;border:1px solid #d7e2d9;border-radius:8px;background:#fff;color:#283a2e;font:inherit;font-size:13px}.br-report-actions{display:flex;gap:8px;flex-wrap:wrap}.br-report-action{min-height:40px;padding:9px 13px;border:1px solid #d7e2d9;border-radius:8px;background:#fff;color:#31553c;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap}.br-report-action.primary{background:var(--br-green);border-color:var(--br-green);color:#fff}.br-report-action:hover{filter:brightness(.97);transform:translateY(-1px)}
.br-report-statusline{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 2px 15px;color:var(--br-muted);font-size:12px}.br-report-live{display:flex;align-items:center;gap:7px}.br-report-live:before{content:'';width:7px;height:7px;border-radius:50%;background:#3b9a59;display:inline-block}.br-report-live.error:before{background:#d64f4f}
.br-report-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:17px}.br-report-stat{position:relative;display:flex;align-items:center;gap:13px;min-width:0;min-height:108px;padding:18px;background:#fff;border:1px solid var(--br-border);border-radius:12px;box-shadow:0 3px 10px rgba(25,64,37,.035);overflow:hidden}.br-report-stat:after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--br-green)}.br-report-stat-icon{width:45px;height:45px;flex:0 0 45px;display:flex;align-items:center;justify-content:center;border-radius:12px;background:var(--br-green-soft);color:var(--br-green);font-size:18px}.br-report-stat-label{font-size:12px;line-height:1.4;color:var(--br-muted);margin-bottom:6px}.br-report-stat-value{font-size:26px;line-height:1.1;font-weight:800;letter-spacing:-.4px;color:var(--br-green-dark);overflow-wrap:anywhere}.br-report-stat-note{font-size:10px;color:#8a968e;margin-top:5px}
.br-report-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:23px 1px 12px}.br-report-section-head h3{font-size:15px;font-weight:800;color:#244b31;margin:0}.br-report-section-head p{font-size:12px;color:var(--br-muted);margin:4px 0 0}.br-report-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:14px}.br-report-charts-four .br-report-chart-card{min-width:0}.br-report-chart-card,.br-report-insight-card,.br-report-table-card{min-width:0;padding:18px;background:#fff;border:1px solid var(--br-border);border-radius:12px;box-shadow:0 3px 10px rgba(25,64,37,.035)}.br-report-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.br-report-card-head h4{font-size:14px;font-weight:800;color:#2a4933;margin:0}.br-report-card-head p{font-size:11px;color:var(--br-muted);margin:5px 0 0}.br-report-tag{display:inline-flex;align-items:center;gap:5px;border-radius:20px;background:#eef6f0;padding:5px 8px;color:#2c7141;font-size:10px;font-weight:800;white-space:nowrap}.br-report-chart-wrap{position:relative;height:270px;margin-top:13px}.br-report-insights{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}.br-report-insight-card{padding:15px 16px}.br-report-insight-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:800;color:#7a8b7f;margin-bottom:8px}.br-report-insight-value{font-size:17px;font-weight:800;color:#235b35;line-height:1.35;overflow-wrap:anywhere}.br-report-insight-copy{font-size:11px;color:var(--br-muted);line-height:1.5;margin-top:5px}
.br-report-table-card{padding:0;overflow:hidden}.br-report-table-top{padding:17px 18px;display:flex;justify-content:space-between;align-items:center;gap:14px;border-bottom:1px solid #e9f0ea}.br-report-table-top h3{font-size:15px;color:#244b31;margin:0;font-weight:800}.br-report-table-top p{font-size:11px;color:var(--br-muted);margin:5px 0 0}.br-report-table-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.br-report-search{width:220px;max-width:100%;min-height:36px;padding:8px 10px;border:1px solid #d7e2d9;border-radius:8px;font-size:12px}.br-report-table-wrap{width:100%;overflow:auto}.br-report-table{width:100%;border-collapse:collapse;font-size:12px}.br-report-table th{padding:12px 13px;text-align:left;white-space:nowrap;background:#f3f8f4;color:#47614e;border-bottom:1px solid #e0e9e2;font-size:10px;letter-spacing:.5px;text-transform:uppercase;font-weight:800}.br-report-table td{padding:12px 13px;border-bottom:1px solid #edf2ee;color:#405448;vertical-align:middle;max-width:260px}.br-report-table tbody tr:hover{background:#f8fbf9}.br-report-table tbody tr:last-child td{border-bottom:0}.br-report-status{display:inline-block;max-width:180px;padding:4px 8px;border-radius:6px;background:#edf6ef;color:#28653a;font-size:10px;font-weight:800;overflow-wrap:anywhere;text-transform:capitalize}.br-report-empty{text-align:center!important;padding:38px 12px!important;color:#849087!important}.br-report-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid #e9f0ea;color:var(--br-muted);font-size:11px}.br-report-page-buttons{display:flex;gap:6px}.br-report-page-buttons button{border:1px solid #d7e2d9;background:#fff;border-radius:6px;padding:6px 10px;color:#31553c;cursor:pointer}.br-report-page-buttons button:disabled{opacity:.4;cursor:not-allowed}.br-report-print-footer{display:none}

.br-report-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:-2px 0 15px;padding:5px;border:1px solid var(--br-border);background:#f7faf7;border-radius:11px}.br-report-tab{border:1px solid transparent;border-radius:8px;padding:9px 13px;background:transparent;color:#53675a;font-weight:800;font-size:12px;cursor:pointer}.br-report-tab:hover{background:#edf5ef}.br-report-tab.active{background:#236b3b;color:#fff;box-shadow:0 2px 5px rgba(35,107,59,.16)}.br-report-map-card{display:none;margin:0 0 15px;padding:17px;background:#fff;border:1px solid var(--br-border);border-radius:12px;box-shadow:0 3px 10px rgba(25,64,37,.035)}.br-report-map-card.visible{display:block}.br-report-map{height:260px;width:100%;margin-top:12px;border:1px solid #dce7de;border-radius:10px;overflow:hidden;background:#f2f6f3}.br-report-map-legend{display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:11px;color:#617267;margin-top:10px}.br-report-map-legend span{display:inline-flex;align-items:center;gap:6px}.br-report-legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block}.br-report-map-note{font-size:11px;color:#748278;margin-top:8px;line-height:1.5}.br-report-tabs-label{font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.7px;color:#829087;padding:10px 6px 0 2px}

@media(max-width:1100px){.br-report-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.br-report-charts{grid-template-columns:1fr}.br-report-chart-wrap{height:250px}}
@media(max-width:700px){.br-report-hero{padding:18px;display:block}.br-report-hero h2{font-size:21px}.br-report-hero-meta{text-align:left;margin-top:14px}.br-report-stats{gap:9px}.br-report-stat{padding:12px;gap:9px;min-height:88px}.br-report-stat-icon{width:36px;height:36px;flex-basis:36px;font-size:15px}.br-report-stat-value{font-size:21px}.br-report-toolbar{align-items:stretch}.br-report-field{min-width:100%}.br-report-actions{width:100%}.br-report-action{flex:1}.br-report-insights{grid-template-columns:1fr}.br-report-table-top{align-items:flex-start;flex-direction:column}.br-report-search{width:100%}.br-report-statusline{align-items:flex-start;flex-direction:column}}
@media print{body *{visibility:hidden!important}.br-reports-module,.br-reports-module *{visibility:visible!important}.br-reports-module{position:absolute;left:0;top:0;width:100%;color:#111!important}.br-report-hero{background:#fff!important;color:#111!important;border:1px solid #ccc;box-shadow:none}.br-report-hero h2,.br-report-hero p,.br-report-hero-meta,.br-report-hero-meta strong{color:#111!important}.br-reports-toolbar,.br-report-actions,.br-report-table-tools,.br-report-pagination{display:none!important}.br-report-stats{grid-template-columns:repeat(4,1fr)}.br-report-charts{grid-template-columns:repeat(2,1fr);break-inside:avoid}.br-report-chart-card,.br-report-stat,.br-report-insight-card,.br-report-table-card{box-shadow:none;break-inside:avoid}.br-report-print-footer{display:block;font-size:10px;color:#555;padding-top:14px}}
    `;
    document.head.appendChild(style);
})();

(function loadReportsChartJs() {
    if (window.Chart || document.querySelector('script[data-brite-chartjs]')) return;
    var script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    script.dataset.briteChartjs = '1';
    document.head.appendChild(script);
})();
(function loadBriteMapLibraries() {
    if (!document.querySelector('link[data-brite-leaflet]')) { var css=document.createElement('link'); css.rel='stylesheet'; css.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; css.dataset.briteLeaflet='1'; document.head.appendChild(css); }
    function loadHeatPlugin() {
        if (window.L && window.L.heatLayer) { if(window.briteEmergencyPointsCache) briteRenderCaptainEmergencyHeatmap(window.briteEmergencyPointsCache); return; }
        if (document.querySelector('script[data-brite-leaflet-heat]')) return;
        var h=document.createElement('script'); h.src='https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js'; h.dataset.briteLeafletHeat='1';
        h.onload=function(){ if(window.briteEmergencyPointsCache) briteRenderCaptainEmergencyHeatmap(window.briteEmergencyPointsCache); };
        document.head.appendChild(h);
    }
    if (window.L) { loadHeatPlugin(); return; }
    if (document.querySelector('script[data-brite-leaflet]')) return;
    var s=document.createElement('script'); s.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; s.dataset.briteLeaflet='1'; s.onload=loadHeatPlugin; document.head.appendChild(s);
})();

var briteCaptainReportsCharts = { monthly: null, classification: null, emergency: null, services: null };
var briteEmergencyMap = null, briteEmergencyHeatLayer = null, briteEmergencyBoundaryLayer = null, briteEmergencyMaskLayer = null, briteEmergencyPointsCache = [];
var briteCaptainReportsFilters = { type: 'overview', from: '', to: '' };
var briteCaptainReportAllRows = [], briteCaptainReportVisibleRows = [], briteCaptainReportPage = 1, briteCaptainReportPageSize = 10, briteCaptainReportColumns = [];

function briteRenderCaptainReportsView() {
    return `
    <section class="br-reports-module">
      <header class="br-report-hero">
        <div><div class="br-report-eyebrow">BRITE · Captain / Barangay Insights</div><h2>Reports &amp; Analytics</h2><p>Monitor barangay performance, identify high-demand services, and spot cases or incidents that need attention.</p></div>
        <div class="br-report-hero-meta"><span>REPORT GENERATED</span><strong id="brReportGeneratedAt">Preparing report…</strong><span id="brReportPeriodLabel">Select a reporting period</span></div>
      </header>
      <div class="br-report-toolbar">
        <div class="br-report-field"><label for="brReportType">Report classification / type</label><select id="brReportType" class="form-control" onchange="briteLoadCaptainReports()"><option value="overview">Barangay performance</option><option value="demand">Most requested services</option><option value="households">Residents</option><option value="documents">Document requests</option><option value="complaints">Complaints & Lupon</option><option value="vehicles">Barangay vehicle services</option><option value="resources">Equipment & facilities</option><option value="emergencies">Emergency insights</option></select></div>
        <div class="br-report-field"><label for="brReportFrom">Start date</label><input id="brReportFrom" type="date" class="form-control" onchange="briteLoadCaptainReports()"></div>
        <div class="br-report-field"><label for="brReportTo">End date</label><input id="brReportTo" type="date" class="form-control" onchange="briteLoadCaptainReports()"></div>
        <div class="br-report-actions"><button type="button" class="br-report-action primary" onclick="briteLoadCaptainReports(true)"><i class="fas fa-sync-alt"></i> Refresh report</button></div>
      </div>
      <nav class="br-report-tabs" aria-label="Report classifications">
        <span class="br-report-tabs-label">Quick filter</span>
        <button type="button" class="br-report-tab active" data-report-tab="overview" onclick="briteSetCaptainReportType('overview')">Performance</button>
        <button type="button" class="br-report-tab" data-report-tab="demand" onclick="briteSetCaptainReportType('demand')">In demand</button>
        <button type="button" class="br-report-tab" data-report-tab="households" onclick="briteSetCaptainReportType('households')">Residents</button>
        <button type="button" class="br-report-tab" data-report-tab="documents" onclick="briteSetCaptainReportType('documents')">Documents</button>
        <button type="button" class="br-report-tab" data-report-tab="complaints" onclick="briteSetCaptainReportType('complaints')">Complaints / Lupon</button>
        <button type="button" class="br-report-tab" data-report-tab="vehicles" onclick="briteSetCaptainReportType('vehicles')">Vehicle services</button>
        <button type="button" class="br-report-tab" data-report-tab="resources" onclick="briteSetCaptainReportType('resources')">Equipment & facilities</button>
        <button type="button" class="br-report-tab" data-report-tab="emergencies" onclick="briteSetCaptainReportType('emergencies')">Emergencies</button>
      </nav>
      <div class="br-report-statusline"><span id="brReportMessage" class="br-report-live" aria-live="polite">Loading current report data…</span><span id="brReportRangeSummary">Reporting period: —</span></div>
      <div class="br-report-stats" id="brReportStats"></div>
      <div class="br-report-section-head"><div><h3>Classification &amp; activity analysis</h3><p>Compare operational trends and record types. Charts group by classification or type, not workflow status.</p></div><span class="br-report-tag"><i class="fas fa-database"></i> Live database records</span></div>
      <div class="br-report-charts br-report-charts-four">
        <div class="br-report-chart-card"><div class="br-report-card-head"><div><h4 id="brReportMonthlyTitle">Monthly activity trend</h4><p id="brReportMonthlySubtitle">Six-month comparison of saved records</p></div><span class="br-report-tag">Trend</span></div><div class="br-report-chart-wrap"><canvas id="brReportMonthlyChart"></canvas></div></div>
        <div class="br-report-chart-card"><div class="br-report-card-head"><div><h4 id="brReportClassificationTitle">Classification / type breakdown</h4><p>Most common types in the selected report</p></div><span class="br-report-tag">Types</span></div><div class="br-report-chart-wrap"><canvas id="brReportClassificationChart"></canvas></div></div>
        <div class="br-report-chart-card"><div class="br-report-card-head"><div><h4 id="brReportWeekdayTitle">Records by weekday</h4><p id="brReportWeekdaySubtitle">Compare activity across days of the week</p></div><span class="br-report-tag">Pattern</span></div><div class="br-report-chart-wrap"><canvas id="brReportEmergencyChart"></canvas></div></div>
        <div class="br-report-chart-card"><div class="br-report-card-head"><div><h4 id="brReportDetailTitle">Record breakdown</h4><p id="brReportDetailSubtitle">Additional category-specific analysis</p></div><span class="br-report-tag">Analysis</span></div><div class="br-report-chart-wrap"><canvas id="brReportServicesChart"></canvas></div></div>
      </div>
      <div class="br-report-map-card" id="brReportEmergencyMapCard"><div class="br-report-card-head"><div><h4>Emergency hotspot heat map</h4><p>Geocoded emergency reports over the supplied San Bartolome GeoJSON boundary</p></div><span class="br-report-tag"><i class="fas fa-map-marked-alt"></i> Location analysis</span></div><div id="brReportEmergencyMap" class="br-report-map" role="img" aria-label="Emergency incident heat map"></div><div class="br-report-map-legend"><span><i class="br-report-legend-dot" style="background:#f4d35e"></i>Lower concentration</span><span><i class="br-report-legend-dot" style="background:#f08a24"></i>Medium concentration</span><span><i class="br-report-legend-dot" style="background:#d64545"></i>Higher concentration</span></div><div id="brReportMapNote" class="br-report-map-note">Heat points use emergency latitude and longitude saved in BRITE. Keep the supplied GeoJSON beside the Captain Reports files in the admin folder.</div></div>
      <div class="br-report-section-head"><div><h3>Executive highlights</h3><p>Quick observations from the selected reporting period.</p></div></div>
      <div class="br-report-insights" id="brReportInsights"></div>
      <div class="br-report-section-head"><div><h3>Detailed records</h3><p>Search and review the records included in this report.</p></div></div>
      <div class="br-report-table-card"><div class="br-report-table-top"><div><h3 id="brReportTableTitle">Report register</h3><p id="brReportTableDescription">Records matching the selected dates</p></div><div class="br-report-table-tools"><input class="br-report-search" id="brReportSearch" type="search" placeholder="Search records…" oninput="briteFilterCaptainReportTable()" aria-label="Search report records"></div></div><div class="br-report-table-wrap"><table class="br-report-table"><thead id="brReportTableHead"></thead><tbody id="brReportTableBody"><tr><td class="br-report-empty">Loading report records…</td></tr></tbody></table></div><div class="br-report-pagination"><span id="brReportPaginationInfo">0 records</span><div class="br-report-page-buttons"><button type="button" id="brReportPrev" onclick="briteChangeCaptainReportPage(-1)" disabled>Previous</button><button type="button" id="brReportNext" onclick="briteChangeCaptainReportPage(1)" disabled>Next</button></div></div></div>
      <div class="br-report-print-footer">BRITE — Barangay Responsive Information and Transaction E-portal · Generated from system records.</div>
    </section>`;
}

function briteInitCaptainReports() {
    var fromEl = document.getElementById('brReportFrom'), toEl = document.getElementById('brReportTo');
    if (!fromEl || !toEl) return;
    var now = new Date(), first = new Date(now.getFullYear(), now.getMonth(), 1);
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    if (!fromEl.value) fromEl.value = fmt(first); if (!toEl.value) toEl.value = fmt(now);
    briteLoadCaptainReports();
}
function briteLoadCaptainReports(showRefresh) {
    var typeEl = document.getElementById('brReportType'), fromEl = document.getElementById('brReportFrom'), toEl = document.getElementById('brReportTo');
    if (!typeEl || !fromEl || !toEl || !document.getElementById('brReportStats')) return;
    if (fromEl.value && toEl.value && fromEl.value > toEl.value) { briteCaptainReportSetMessage('Start date cannot be later than end date.', true); return; }
    briteCaptainReportsFilters = { type: typeEl.value, from: fromEl.value, to: toEl.value };
    document.querySelectorAll('[data-report-tab]').forEach(function(btn){btn.classList.toggle('active',btn.getAttribute('data-report-tab')===briteCaptainReportsFilters.type);});
    var mapCard=document.getElementById('brReportEmergencyMapCard'); if(mapCard) mapCard.classList.toggle('visible',briteCaptainReportsFilters.type==='emergencies');
    var params = new URLSearchParams(briteCaptainReportsFilters);
    briteCaptainReportSetMessage(showRefresh ? 'Refreshing report data…' : 'Loading report data…', false);
    var refreshButton = document.querySelector('.br-report-action.primary'); if (refreshButton) refreshButton.disabled = true;
    fetch('captain_reports_ajax.php?action=get_report&' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status === 401 ? 'Your admin session has expired. Sign in again.' : 'The report endpoint returned an error (' + r.status + ').'); return r.json(); })
      .then(function (data) {
        if (!data.success) throw new Error(data.message || 'Unable to load report data.');
        briteCaptainReportAllRows = data.rows || []; briteCaptainReportColumns = data.columns || [];
        briteRenderCaptainReportStats(data.stats || []); briteRenderCaptainReportTable(briteCaptainReportColumns, briteCaptainReportAllRows);
        briteDrawCaptainReportsCharts(data.monthly || [], data.classifications || [], data.emergency_types || [], data.service_types || [], briteCaptainReportsFilters.type, data.rows || []);
        briteEmergencyPointsCache = data.emergency_points || [];
        if (briteCaptainReportsFilters.type === 'emergencies') briteRenderCaptainEmergencyHeatmap(briteEmergencyPointsCache);
        briteRenderCaptainInsights(data);
        var now = new Date();
        var generated = document.getElementById('brReportGeneratedAt'); if (generated) generated.textContent = now.toLocaleString();
        var period = briteCaptainReportsFilters.from + ' — ' + briteCaptainReportsFilters.to;
        var periodEl = document.getElementById('brReportPeriodLabel'); if (periodEl) periodEl.textContent = period;
        var range = document.getElementById('brReportRangeSummary'); if (range) range.textContent = 'Reporting period: ' + period + ' · ' + briteCaptainReportAllRows.length + ' records shown (up to 1,000)';
        var title = document.getElementById('brReportTableTitle'); if (title) title.textContent = briteCaptainReportModuleTitle(briteCaptainReportsFilters.type) + ' register';
        var desc = document.getElementById('brReportTableDescription'); if (desc) desc.textContent = 'Records dated ' + period + ' · Most recent records first';
        briteCaptainReportSetMessage('Report loaded successfully from BRITE records.', false);
      })
      .catch(function (err) {
        console.error('BRITE Reports:', err); briteCaptainReportSetMessage(err.message || 'Unable to load reports. Check reports_ajax.php and the database connection.', true);
        var stats = document.getElementById('brReportStats'); if (stats) stats.innerHTML = '';
        var body = document.getElementById('brReportTableBody'); if (body) body.innerHTML = '<tr><td class="br-report-empty">Could not load report records. Check the AJAX endpoint and database connection.</td></tr>';
        var insights = document.getElementById('brReportInsights'); if (insights) insights.innerHTML = '';
        briteDestroyCaptainReportCharts();
      })
      .finally(function () { if (refreshButton) refreshButton.disabled = false; });
}
function briteSetCaptainReportType(type) {
    var select=document.getElementById('brReportType'); if(!select) return; select.value=type; briteLoadCaptainReports();
}
function briteCaptainReportSetMessage(text, isError) { var el = document.getElementById('brReportMessage'); if (!el) return; el.textContent = text; el.classList.toggle('error', !!isError); }
function briteCaptainReportModuleTitle(type) { return ({overview:'Barangay performance',demand:'Most requested services',households:'Residents & Household Registry',documents:'Document requests',complaints:'Complaints & Lupon',vehicles:'Barangay vehicle services',resources:'Equipment & facilities',emergencies:'Emergency insights'})[type] || 'Report'; }
function briteRenderCaptainReportStats(stats) {
    var el = document.getElementById('brReportStats'); if (!el) return;
    el.innerHTML = stats.map(function (item) { return '<div class="br-report-stat"><div class="br-report-stat-icon"><i class="fas ' + briteCaptainReportEscape(item.icon || 'fa-chart-bar') + '"></i></div><div><div class="br-report-stat-label">' + briteCaptainReportEscape(item.label) + '</div><div class="br-report-stat-value">' + briteCaptainReportEscape(String(item.value ?? 0)) + '</div><div class="br-report-stat-note">' + (briteCaptainReportsFilters.type === 'overview' && (item.label === 'Households' || item.label === 'Total Residents') ? 'Current household registry total' : 'Selected reporting period') + '</div></div></div>'; }).join('');
}
function briteRenderCaptainReportTable(columns, rows) {
    var head = document.getElementById('brReportTableHead'); if (!head) return;
    head.innerHTML = '<tr>' + columns.map(function (c) { return '<th>' + briteCaptainReportEscape(c.label) + '</th>'; }).join('') + '</tr>';
    var search = document.getElementById('brReportSearch'); if (search) search.value = '';
    briteCaptainReportPage = 1; briteCaptainReportVisibleRows = rows.slice(); britePaintCaptainReportPage();
}
function briteFilterCaptainReportTable() {
    var input = document.getElementById('brReportSearch'), query = (input ? input.value : '').trim().toLowerCase();
    briteCaptainReportVisibleRows = briteCaptainReportAllRows.filter(function (row) { return briteCaptainReportColumns.some(function (col) { return String(row[col.key] == null ? '' : row[col.key]).toLowerCase().indexOf(query) !== -1; }); });
    briteCaptainReportPage = 1; britePaintCaptainReportPage();
}
function britePaintCaptainReportPage() {
    var body = document.getElementById('brReportTableBody'); if (!body) return;
    var total = briteCaptainReportVisibleRows.length, pages = Math.max(1, Math.ceil(total / briteCaptainReportPageSize));
    briteCaptainReportPage = Math.min(briteCaptainReportPage, pages);
    var start = (briteCaptainReportPage - 1) * briteCaptainReportPageSize, rows = briteCaptainReportVisibleRows.slice(start, start + briteCaptainReportPageSize);
    if (!rows.length) body.innerHTML = '<tr><td colspan="' + Math.max(briteCaptainReportColumns.length,1) + '" class="br-report-empty"><i class="fas fa-inbox"></i><br>No records match the selected dates or search.</td></tr>';
    else body.innerHTML = rows.map(function (row) { return '<tr>' + briteCaptainReportColumns.map(function (c) { var value = row[c.key]; if (c.key === 'status' || c.key === 'is_verified' || c.key === 'is_active') return '<td><span class="br-report-status">' + briteCaptainReportEscape(briteCaptainReportFormatValue(c.key, value)) + '</span></td>'; return '<td>' + briteCaptainReportEscape(briteCaptainReportFormatValue(c.key, value)) + '</td>'; }).join('') + '</tr>'; }).join('');
    var info = document.getElementById('brReportPaginationInfo'); if (info) info.textContent = total ? ('Showing ' + (start+1) + '–' + Math.min(start+rows.length,total) + ' of ' + total + ' records') : '0 records';
    var count = document.getElementById('brReportRecordCount'); if (count) count.textContent = total.toLocaleString() + ' records';
    var prev = document.getElementById('brReportPrev'), next = document.getElementById('brReportNext'); if (prev) prev.disabled = briteCaptainReportPage <= 1; if (next) next.disabled = briteCaptainReportPage >= pages;
}
function briteCaptainReportFormatValue(key, value) {
    if (value == null || value === '') return '—';
    if (key === 'is_verified') return String(value) === '1' ? 'Verified' : 'Unverified';
    if (key === 'is_active') return String(value) === '1' ? 'Active' : 'Inactive';
    return String(value);
}
function briteChangeCaptainReportPage(delta) { briteCaptainReportPage += delta; britePaintCaptainReportPage(); }
function briteRenderCaptainInsights(data) {
    var el = document.getElementById('brReportInsights'); if (!el) return;
    var stats = data.stats || [], classifications = data.classifications || [], rows = data.rows || [], monthly = data.monthly || [];
    var topClass = classifications.slice().sort(function(a,b){return Number(b.total||0)-Number(a.total||0);})[0];
    var latest = monthly.length ? monthly[monthly.length-1] : null;
    var totalClass = classifications.reduce(function(n,x){return n+Number(x.total||0);},0);
    var periodRecords = rows.length;
    var cards = [
      {label:'Records in register',value:periodRecords.toLocaleString(),copy:'Rows returned for this report and date range.'},
      {label:'Most common type',value:topClass ? (topClass.label || 'Unspecified') : 'No type data',copy:topClass ? (Number(topClass.total||0).toLocaleString() + ' records · ' + (totalClass ? Math.round(Number(topClass.total||0)/totalClass*100) : 0) + '% of the type breakdown') : 'No classifications were found for the selected period.'},
      {label:'Latest trend point',value:latest ? Number(latest.total||0).toLocaleString() : '—',copy:latest ? ('Activity total for ' + latest.label + '.') : 'Monthly trend is not available.'}
    ];
    el.innerHTML = cards.map(function(c){return '<div class="br-report-insight-card"><div class="br-report-insight-label">' + briteCaptainReportEscape(c.label) + '</div><div class="br-report-insight-value">' + briteCaptainReportEscape(c.value) + '</div><div class="br-report-insight-copy">' + briteCaptainReportEscape(c.copy) + '</div></div>';}).join('');
}
function briteDrawCaptainReportsCharts(monthly, classifications, emergencyTypes, serviceTypes, type, rows) {
    var attempt = 0; rows = rows || [];
    function draw() {
        if (!window.Chart) { if (attempt++ < 30) return setTimeout(draw, 150); document.querySelectorAll('.br-report-chart-wrap').forEach(function(el){el.innerHTML='<div class="br-report-empty">Chart library could not load. Connect to the internet to display charts.</div>';}); return; }
        briteDestroyCaptainReportCharts();
        var monthlyCanvas=document.getElementById('brReportMonthlyChart'), classCanvas=document.getElementById('brReportClassificationChart'), weekdayCanvas=document.getElementById('brReportEmergencyChart'), detailCanvas=document.getElementById('brReportServicesChart');
        if(!monthlyCanvas||!classCanvas||!weekdayCanvas||!detailCanvas) return;
        var palette=['#236b3b','#4c83c3','#d79a24','#d75a54','#8a70c2','#53a7a4','#cf7a39','#82938a'];
        var monthlySets=[];
        if(type==='households' && monthly.length && monthly[0].residents!==undefined){
            monthlySets=[{label:'Residents',data:monthly.map(function(x){return Number(x.residents)||0;}),borderColor:palette[0],backgroundColor:'rgba(35,107,59,.08)',tension:.3,borderWidth:2}];
        } else if((type==='overview' || type==='demand') && monthly.length && monthly[0].documents!==undefined){
            monthlySets=[{label:'Documents',data:monthly.map(function(x){return Number(x.documents)||0;}),borderColor:palette[0],backgroundColor:'rgba(35,107,59,.08)',tension:.3,borderWidth:2},{label:'Complaints / Lupon',data:monthly.map(function(x){return Number(x.complaints)||0;}),borderColor:palette[2],backgroundColor:'rgba(215,154,36,.08)',tension:.3,borderWidth:2},{label:'Vehicle requests',data:monthly.map(function(x){return Number(x.vehicles)||0;}),borderColor:palette[1],backgroundColor:'rgba(76,131,195,.08)',tension:.3,borderWidth:2},{label:'Equipment / facility',data:monthly.map(function(x){return Number(x.resources)||0;}),borderColor:palette[4],backgroundColor:'rgba(138,112,194,.08)',tension:.3,borderWidth:2},{label:'Emergencies',data:monthly.map(function(x){return Number(x.emergencies)||0;}),borderColor:palette[3],backgroundColor:'rgba(215,90,84,.08)',tension:.3,borderWidth:2}];
        } else monthlySets=[{label:briteCaptainReportModuleTitle(type),data:monthly.map(function(x){return Number(x.total)||0;}),borderColor:palette[0],backgroundColor:'rgba(35,107,59,.12)',fill:true,tension:.3,borderWidth:3,pointRadius:3}];
        briteCaptainReportsCharts.monthly=new Chart(monthlyCanvas,{type:'line',data:{labels:monthly.map(function(x){return x.label;}),datasets:monthlySets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:monthlySets.length>1,position:'bottom',labels:{boxWidth:10,usePointStyle:true,padding:12}}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});
        var monthlyTitle=document.getElementById('brReportMonthlyTitle'), monthlySubtitle=document.getElementById('brReportMonthlySubtitle'); if(type==='households'){if(monthlyTitle)monthlyTitle.textContent='Total residents in household registry';if(monthlySubtitle)monthlySubtitle.textContent='Six-month resident registry trend';}else{if(monthlyTitle)monthlyTitle.textContent='Monthly activity trend';if(monthlySubtitle)monthlySubtitle.textContent='Six-month comparison of saved records';}
        var classTitle=document.getElementById('brReportClassificationTitle'); if(classTitle) classTitle.textContent=({overview:'Activity categories',demand:'Most requested services',households:'Residents by age classification',documents:'Most requested documents',complaints:'Complaint subject / classification',vehicles:'Vehicle request purposes',resources:'Equipment and facility demand',emergencies:'Emergency type distribution'})[type]||'Classification breakdown';
        briteCaptainReportsCharts.classification=new Chart(classCanvas,{type:'bar',data:{labels:classifications.map(function(x){return x.label;}),datasets:[{label:'Records',data:classifications.map(function(x){return Number(x.total)||0;}),backgroundColor:'#398451',borderRadius:6,maxBarThickness:42}]},options:{indexAxis:classifications.length>7?'y':'x',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(ctx){return ' '+Number(ctx.raw||0).toLocaleString()+' records';}}}},scales:{x:{beginAtZero:true,ticks:{precision:0},grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0}}}}});
        // Third chart: day-of-week pattern from actual dated records.
        var dateKey=({documents:'request_date',vehicles:'request_date',resources:'request_date'})[type]||'created_at';
        var weekdayLabels=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], weekdayCounts=[0,0,0,0,0,0,0];
        rows.forEach(function(row){var raw=row[dateKey]; if(!raw) return; var dt=new Date(String(raw).replace(' ','T')); if(!isNaN(dt.getTime())) weekdayCounts[dt.getDay()]++;});
        var weekTitle=document.getElementById('brReportWeekdayTitle'), weekSub=document.getElementById('brReportWeekdaySubtitle');
        if(type==='households') {
            if(weekTitle) weekTitle.textContent='Registered resident accounts';
            if(weekSub) weekSub.textContent='New resident account registrations by month';
            briteCaptainReportsCharts.emergency=new Chart(weekdayCanvas,{type:'bar',data:{labels:monthly.map(function(x){return x.label;}),datasets:[{label:'Registered residents',data:monthly.map(function(x){return Number(x.registered_residents)||0;}),backgroundColor:palette[1],borderRadius:5,maxBarThickness:34}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});
        } else {
            if(weekTitle) weekTitle.textContent=type==='emergencies'?'Emergency reports by weekday':type==='documents'?'Document requests by weekday':type==='vehicles'?'Vehicle requests by weekday':type==='resources'?'Equipment / facility requests by weekday':type==='complaints'?'Complaints filed by weekday':'Records by weekday';
            if(weekSub) weekSub.textContent='Based on the report records returned for the selected dates';
            briteCaptainReportsCharts.emergency=new Chart(weekdayCanvas,{type:'bar',data:{labels:weekdayLabels,datasets:[{label:'Records',data:weekdayCounts,backgroundColor:palette[1],borderRadius:5,maxBarThickness:34}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});
        }
        // Fourth chart is category-specific and intentionally avoids workflow status fields.
        var detailKey={overview:'complaint_type',demand:'request_type',households:'gender',documents:'purpose',complaints:'priority',vehicles:'purpose',resources:'request_type',emergencies:'emergency_type'}[type]||'purpose';
        var detailTitle={overview:'Complaint priority mix',demand:'Demand by service category',households:'Residents by gender',documents:'Common request purposes',complaints:'Complaint priority mix',vehicles:'Common vehicle trip purposes',resources:'Demand by resource type',emergencies:'Emergency type mix'}[type]||'Record grouping';
        var detailTitleEl=document.getElementById('brReportDetailTitle'), detailSub=document.getElementById('brReportDetailSubtitle'); if(detailTitleEl) detailTitleEl.textContent=detailTitle; if(detailSub) detailSub.textContent='Top categories from matching report records';
        var grouped={}; rows.forEach(function(row){var val=String(row[detailKey]||'Unspecified').trim()||'Unspecified'; if(detailKey==='purpose' && val.length>42) val=val.slice(0,39)+'…'; grouped[val]=(grouped[val]||0)+1;});
        var detailRows=Object.keys(grouped).map(function(k){return {label:k,total:grouped[k]};}).sort(function(a,b){return b.total-a.total;}).slice(0,8);
        briteCaptainReportsCharts.services=new Chart(detailCanvas,{type:'doughnut',data:{labels:detailRows.map(function(x){return x.label;}),datasets:[{data:detailRows.map(function(x){return x.total;}),backgroundColor:palette,borderWidth:3,borderColor:'#fff',hoverOffset:4}]},options:{responsive:true,maintainAspectRatio:false,cutout:'58%',plugins:{legend:{position:'bottom',labels:{boxWidth:9,usePointStyle:true,padding:10}},tooltip:{callbacks:{label:function(ctx){return ' '+ctx.label+': '+Number(ctx.raw||0).toLocaleString();}}}}}});
    }
    draw();
}
function briteDestroyCaptainReportCharts() { Object.keys(briteCaptainReportsCharts).forEach(function(key){if(briteCaptainReportsCharts[key]){briteCaptainReportsCharts[key].destroy();briteCaptainReportsCharts[key]=null;}}); }
function briteRenderCaptainEmergencyHeatmap(points) {
    var card=document.getElementById('brReportEmergencyMapCard'), host=document.getElementById('brReportEmergencyMap');
    if(!card||!host||briteCaptainReportsFilters.type!=='emergencies') return;
    card.classList.add('visible');
    if(!window.L || !window.L.heatLayer){setTimeout(function(){if(document.getElementById('brReportEmergencyMap')&&window.L&&window.L.heatLayer) briteRenderCaptainEmergencyHeatmap(points);},250);var note=document.getElementById('brReportMapNote');if(note)note.textContent='Loading map tools… An internet connection is required for map tiles.';return;}
    if(!briteEmergencyMap){briteEmergencyMap=window.L.map(host,{scrollWheelZoom:false,zoomControl:true}).setView([14.984,120.703],15);}
    if(briteEmergencyHeatLayer){briteEmergencyMap.removeLayer(briteEmergencyHeatLayer);briteEmergencyHeatLayer=null;}
    if(briteEmergencyBoundaryLayer){briteEmergencyMap.removeLayer(briteEmergencyBoundaryLayer);briteEmergencyBoundaryLayer=null;}
    if(briteEmergencyMaskLayer){briteEmergencyMap.removeLayer(briteEmergencyMaskLayer);briteEmergencyMaskLayer=null;}
    fetch('san_bartolome_boundary.geojson',{credentials:'same-origin'}).then(function(r){if(!r.ok)throw new Error('Boundary GeoJSON not found');return r.json();}).then(function(geo){
        var layer=window.L.geoJSON(geo,{style:{color:'#236b3b',weight:2,fillColor:'#4c9a62',fillOpacity:.10}}).addTo(briteEmergencyMap);
        var bounds=layer.getBounds(); if(!bounds.isValid())throw new Error('GeoJSON has no valid boundary');
        briteEmergencyBoundaryLayer=layer;
        var features=(geo.features||[]).filter(function(f){return f.geometry&&f.geometry.type==='Polygon'&&f.geometry.coordinates&&f.geometry.coordinates[0]&&f.geometry.coordinates[0].length>=4;});
        var rings=features.map(function(f){return f.geometry.coordinates[0].map(function(c){return [c[1],c[0]];});});
        // White mask covers the surrounding map; the supplied barangay polygon remains visible as the hole.
        var sw=bounds.getSouthWest(),ne=bounds.getNorthEast(),padLat=Math.max((ne.lat-sw.lat)*5,.02),padLng=Math.max((ne.lng-sw.lng)*5,.02);
        var outside=[[sw.lat-padLat,sw.lng-padLng],[sw.lat-padLat,ne.lng+padLng],[ne.lat+padLat,ne.lng+padLng],[ne.lat+padLat,sw.lng-padLng]];
        if(rings.length) briteEmergencyMaskLayer=window.L.polygon([outside].concat(rings),{stroke:false,fillColor:'#ffffff',fillOpacity:.92,interactive:false}).addTo(briteEmergencyMap);
        // Filter plotted points to the supplied boundary so outside incidents do not distort this barangay view.
        function inside(x,y,ring){var hit=false;for(var i=0,j=ring.length-1;i<ring.length;j=i++){var xi=ring[i][0],yi=ring[i][1],xj=ring[j][0],yj=ring[j][1];var cross=((yi>y)!==(yj>y))&&(x<(xj-xi)*(y-yi)/((yj-yi)||1e-12)+xi);if(cross)hit=!hit;}return hit;}
        points=(points||[]).filter(function(p){var lat=Number(p.lat),lng=Number(p.lng);if(!Number.isFinite(lat)||!Number.isFinite(lng))return false;return rings.some(function(r){return inside(lng,lat,r);});});
        if(points.length) briteEmergencyHeatLayer=window.L.heatLayer(points.map(function(p){return [Number(p.lat),Number(p.lng),1];}),{radius:22,blur:17,maxZoom:17,gradient:{0.2:'#f4d35e',0.5:'#f08a24',0.8:'#d64545'}}).addTo(briteEmergencyMap);
        briteEmergencyMap.fitBounds(bounds.pad(.08),{maxZoom:17});
        var note=document.getElementById('brReportMapNote');if(note)note.textContent=points.length+' emergency location(s) fall inside the San Bartolome GeoJSON boundary for the selected period. Outside areas are masked.';
    }).catch(function(){var n=document.getElementById('brReportMapNote');if(n)n.textContent='Could not load the supplied boundary. Place san_bartolome_boundary.geojson beside captain_reports_ajax.php and captain_reports.js in the admin folder.';});
    if(!briteEmergencyMap._briteBaseTiles){window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(briteEmergencyMap);briteEmergencyMap._briteBaseTiles=true;}
    setTimeout(function(){if(briteEmergencyMap)briteEmergencyMap.invalidateSize();},120);
}

function briteCaptainReportEscape(value) { return String(value == null ? '' : value).replace(/[&<>"']/g,function(ch){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]);}); }
