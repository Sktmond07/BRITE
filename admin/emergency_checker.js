// emergency_checker.js - WITH VIEW DIRECTION BUTTON + LIVE STATUS BADGE

const EMERGENCY_CHECK_INTERVAL = 5000;
const EMERGENCY_API_URL = 'emergency.php';
const EMERGENCY_SOUND_URL = '/BRITE/admin/sounds/emergency_siren.mp3';
const STATUS_REFRESH_INTERVAL = 5000;

let lastEmergencyId = 0;
let checkInterval = null;
let statusRefreshInterval = null;
let emergencyAudio = null;
let soundEnabled = true;
let currentMap = null;
let currentUserMarker = null;
let currentEmergencyMarkers = [];
let currentRoutes = [];
let currentCircles = [];
let currentRouteLabels = [];
let leafletLoaded = false;
let adminCurrentLocation = null;
let watchPositionId = null;

let pendingEmergencyQueue = [];
let isModalOpen = false;
let currentEmergenciesInModal = [];
let currentSwalInstance = null;
let isUpdatingModal = false;
let activeHighlightedRoute = null;

const EMERGENCY_ICONS = {
    medical: '🚑', fire: '🔥', crime: '👮', accident: '💥', default: '🚨'
};

const EMERGENCY_COLORS = {
    medical: '#28a745', fire: '#fd7e14', crime: '#dc3545', accident: '#ffc107', default: '#6c757d'
};

// ============ LEAFLET LOADER ============
function loadLeafletCSS() {
    return new Promise((resolve, reject) => {
        if (document.querySelector('link[href*="leaflet.css"]')) { resolve(); return; }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        link.onload = () => resolve();
        link.onerror = () => reject(new Error('Failed to load Leaflet CSS'));
        document.head.appendChild(link);
    });
}
function loadLeafletJS() {
    return new Promise((resolve, reject) => {
        if (typeof L !== 'undefined') { leafletLoaded = true; resolve(); return; }
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => { leafletLoaded = true; resolve(); };
        script.onerror = () => reject(new Error('Failed to load Leaflet JS'));
        document.head.appendChild(script);
    });
}
async function loadLeaflet() {
    try { await loadLeafletCSS(); await loadLeafletJS(); return true; }
    catch (e) { console.error('Leaflet load failed:', e); return false; }
}

// ============ ADMIN LOCATION ============
async function getAdminCurrentLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) { reject(new Error('Geolocation not supported')); return; }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                adminCurrentLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    timestamp: new Date()
                };
                resolve(adminCurrentLocation);
            },
            (error) => reject(error),
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    });
}
function startWatchingAdminLocation() {
    if (!navigator.geolocation) return;
    if (watchPositionId) navigator.geolocation.clearWatch(watchPositionId);
    watchPositionId = navigator.geolocation.watchPosition(
        (position) => {
            adminCurrentLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude,
                accuracy: position.coords.accuracy,
                timestamp: new Date()
            };
            if (isModalOpen && currentMap && currentUserMarker) {
                currentUserMarker.setLatLng([adminCurrentLocation.lat, adminCurrentLocation.lng]);
                updateAllRoutesAndMarkers();
            }
        },
        (error) => console.error('Watch position error:', error),
        { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
    );
}
function stopWatchingAdminLocation() {
    if (watchPositionId) { navigator.geolocation.clearWatch(watchPositionId); watchPositionId = null; }
}

// ============ INIT ============
async function initEmergencyChecker() {
    console.log('🚨 Emergency Checker STARTED');
    const savedId = localStorage.getItem('lastEmergencyId');
    if (savedId) lastEmergencyId = parseInt(savedId);

    preloadEmergencySound();
    await autoRequestPushPermission();

    try { await getAdminCurrentLocation(); startWatchingAdminLocation(); }
    catch (e) { console.warn('No admin location:', e.message); }

    await loadLeaflet().catch(console.warn);
    loadEmergencies();

    if (checkInterval) clearInterval(checkInterval);
    checkInterval = setInterval(checkNewEmergencies, EMERGENCY_CHECK_INTERVAL);
}

// ============ LIVE STATUS REFRESH WHILE MODAL IS OPEN ============
function startStatusRefresh() {
    if (statusRefreshInterval) clearInterval(statusRefreshInterval);
    statusRefreshInterval = setInterval(refreshModalStatuses, STATUS_REFRESH_INTERVAL);
}
function stopStatusRefresh() {
    if (statusRefreshInterval) { clearInterval(statusRefreshInterval); statusRefreshInterval = null; }
}
async function refreshModalStatuses() {
    if (!isModalOpen || currentEmergenciesInModal.length === 0) return;
    try {
        const response = await fetch(EMERGENCY_API_URL + '?limit=50');
        const data = await response.json();
        if (!data.success || !data.data) return;

        const latestById = {};
        data.data.forEach(row => { latestById[row.id] = row; });

        let changed = false;
        for (let i = 0; i < currentEmergenciesInModal.length; i++) {
            const e = currentEmergenciesInModal[i];
            const latest = latestById[e.id];
            if (!latest) continue;
            if (latest.status !== e.status || latest.accepted_by_name !== e.accepted_by_name) {
                e.status = latest.status;
                e.accepted_by_name = latest.accepted_by_name;
                changed = true;
            }
        }
        if (changed) updateModalBadges();
    } catch (e) { console.warn('Status refresh failed:', e); }
}
function updateModalBadges() {
    currentEmergenciesInModal.forEach((e, i) => {
        // 1. Update the badge in the modal card
        const badge = document.getElementById('status-badge-' + i);
        if (badge) badge.outerHTML = buildStatusBadgeHtml(e, i);

        // 2. Update the map marker popup so clicking shows new status
        if (currentEmergencyMarkers[i]) {
            const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
            const acceptedLine = (e.status === 'ACCEPTED' && e.accepted_by_name)
                ? `<br><b style="color:#28a745;">✅ Accepted by:</b> ${escapeHtml(e.accepted_by_name)}`
                : `<br><b style="color:#ffc107;">⏳ Pending</b>`;
            currentEmergencyMarkers[i].setPopupContent(
                `<div style="min-width: 200px;">
                    <b style="color: ${color};">🚨 EMERGENCY #${i+1}</b><br>
                    <b>Type:</b> ${e.emergency_type.toUpperCase()}<br>
                    <b>Name:</b> ${escapeHtml(e.name)}<br>
                    <b>Barangay:</b> ${escapeHtml(e.barangay)}<br>
                    <b>Time:</b> ${new Date(e.created_at).toLocaleString()}
                    ${acceptedLine}
                </div>`);
        }
    });

    // 3. Also refresh the distance panel (which also shows the badge)
    updateMultiEmergencyDistancePanel();
}
function buildStatusBadgeHtml(e, index) {
    const accepted = (e.status === 'ACCEPTED' && e.accepted_by_name);
    if (accepted) {
        return `<div id="status-badge-${index}" style="margin-top: 8px;">
            <span style="background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">
                ✅ Accepted by ${escapeHtml(e.accepted_by_name)}
            </span>
        </div>`;
    }
    return `<div id="status-badge-${index}" style="margin-top: 8px;">
        <span style="background: #ffc107; color: #333; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">
            ⏳ Pending — no responder yet
        </span>
    </div>`;
}

// ============ MAP ============
async function updateAllRoutesAndMarkers() {
    if (!currentMap || !adminCurrentLocation || !isModalOpen) return;

    currentRoutes.forEach(r => currentMap.removeLayer(r)); currentRoutes = [];
    currentRouteLabels.forEach(l => currentMap.removeLayer(l)); currentRouteLabels = [];
    currentCircles.forEach(c => currentMap.removeLayer(c)); currentCircles = [];

    for (let i = 0; i < currentEmergenciesInModal.length; i++) {
        const e = currentEmergenciesInModal[i];
        const lat = parseFloat(e.latitude), lng = parseFloat(e.longitude);
        const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';

        if (currentEmergencyMarkers[i]) {
            currentEmergencyMarkers[i].setLatLng([lat, lng]);
            currentEmergencyMarkers[i].setIcon(createEmergencyIcon(i + 1, color));
        } else {
            const m = createEmergencyMarker(lat, lng, i + 1, color, e);
            m.addTo(currentMap);
            currentEmergencyMarkers.push(m);
        }

        const circle = L.circle([lat, lng], {
            color, fillColor: color, fillOpacity: 0.12, radius: 400, weight: 2
        }).addTo(currentMap);
        currentCircles.push(circle);

        if (adminCurrentLocation) {
            await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, i);
        }
    }
    fitBoundsToAllPoints();
    updateMultiEmergencyDistancePanel();
}

function createEmergencyIcon(number, color) {
    return L.divIcon({
        html: `<div style="background-color: ${color}; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.3);"><span style="color: white; font-size: 22px; font-weight: bold;">${number}</span></div>`,
        className: 'custom-emergency-marker', iconSize: [48, 48], iconAnchor: [24, 24], popupAnchor: [0, -24]
    });
}
function createEmergencyMarker(lat, lng, number, color, emergency) {
    const icon = createEmergencyIcon(number, color);
    const acceptedLine = (emergency.status === 'ACCEPTED' && emergency.accepted_by_name)
        ? `<br><b style="color:#28a745;">✅ Accepted by:</b> ${escapeHtml(emergency.accepted_by_name)}`
        : `<br><b style="color:#ffc107;">⏳ Pending</b>`;
    return L.marker([lat, lng], { icon })
        .bindPopup(`<div style="min-width: 200px;"><b style="color: ${color};">🚨 EMERGENCY #${number}</b><br><b>Type:</b> ${emergency.emergency_type.toUpperCase()}<br><b>Name:</b> ${escapeHtml(emergency.name)}<br><b>Barangay:</b> ${escapeHtml(emergency.barangay)}<br><b>Time:</b> ${new Date(emergency.created_at).toLocaleString()}${acceptedLine}</div>`);
}
async function drawRouteOnMap(oLat, oLng, dLat, dLng, index) {
    try {
        const r = await fetch(`https://router.project-osrm.org/route/v1/driving/${oLng},${oLat};${dLng},${dLat}?overview=full&geometries=geojson`);
        const data = await r.json();
        if (data.code === 'Ok' && data.routes?.length > 0) {
            const colors = ['#43e97b', '#ff6b6b', '#4ecdc4', '#ffe66d', '#a8e6cf', '#ffd3b6'];
            const layer = L.geoJSON(data.routes[0].geometry, {
                style: { color: colors[index % colors.length], weight: 3, opacity: 0.7, dashArray: '8, 6' }
            }).addTo(currentMap);
            currentRoutes.push(layer);
        }
    } catch {
        const straight = L.polyline([[oLat, oLng], [dLat, dLng]], { color: '#ff9800', weight: 3, opacity: 0.6, dashArray: '5, 10' }).addTo(currentMap);
        currentRoutes.push(straight);
    }
}

function fitBoundsToAllPoints() {
    if (!currentMap) return;
    const pts = [];
    if (adminCurrentLocation) pts.push([adminCurrentLocation.lat, adminCurrentLocation.lng]);
    currentEmergenciesInModal.forEach(e => pts.push([parseFloat(e.latitude), parseFloat(e.longitude)]));
    if (pts.length > 0) currentMap.fitBounds(L.latLngBounds(pts), { padding: [50, 50] });
}

function updateMultiEmergencyDistancePanel() {
    const panel = document.getElementById('directionsPanel');
    if (!panel || !adminCurrentLocation || !isModalOpen) return;

    let html = '<div style="background: #1e2a3a; border-radius: 12px; padding: 15px;">';
    html += '<p style="margin: 0 0 12px 0; font-weight: bold; color: #43e97b;">📍 EMERGENCIES DASHBOARD</p>';

    for (let i = 0; i < currentEmergenciesInModal.length; i++) {
        const e = currentEmergenciesInModal[i];
        const dist = calculateDistance(adminCurrentLocation.lat, adminCurrentLocation.lng, parseFloat(e.latitude), parseFloat(e.longitude));
        const km = (dist / 1000).toFixed(1);
        const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
        const accepted = e.status === 'ACCEPTED' && e.accepted_by_name;

        html += `<div style="background: ${color}15; border-left: 4px solid ${color}; margin-bottom: 12px; padding: 12px; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-weight: bold;">${EMERGENCY_ICONS[e.emergency_type.toLowerCase()] || '🚨'} #${i+1}: ${e.emergency_type.toUpperCase()}</span>
                <span style="background: ${color}; padding: 2px 8px; border-radius: 20px; color: white;">${km} km</span>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 11px; margin-bottom: 10px;">
                <div><i class="fas fa-user"></i> ${escapeHtml(e.name)}</div>
                <div><i class="fas fa-map-marker-alt"></i> ${escapeHtml(e.barangay)}</div>
            </div>
            <div style="margin-bottom: 8px;">
                ${accepted
                    ? `<span style="background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: bold;">✅ Accepted by ${escapeHtml(e.accepted_by_name)}</span>`
                    : `<span style="background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: bold;">⏳ Pending</span>`}
            </div>
        </div>`;
    }
    html += '</div>';
    panel.innerHTML = html;
}

function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) ** 2 + Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180) * Math.sin(dLng/2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// ============ SOUND ============
function preloadEmergencySound() {
    emergencyAudio = new Audio();
    emergencyAudio.preload = 'auto';
    emergencyAudio.loop = true;
    emergencyAudio.volume = 0.8;
    emergencyAudio.src = EMERGENCY_SOUND_URL;
    emergencyAudio.load();
}
function playEmergencySound() {
    if (!soundEnabled) return;
    stopEmergencySound();
    try {
        emergencyAudio.currentTime = 0;
        emergencyAudio.loop = true;
        emergencyAudio.volume = 0.9;
        emergencyAudio.play().catch(e => console.warn('Audio:', e));
    } catch (e) {}
}
function stopEmergencySound() {
    if (emergencyAudio) { emergencyAudio.pause(); emergencyAudio.currentTime = 0; }
}

// ============ NOTIFICATIONS ============
async function autoRequestPushPermission() {
    if (Notification.permission === 'granted') return;
    try { await Notification.requestPermission(); } catch {}
}

// ============ MAP INIT ============
async function initializeMap(emergencies) {
    if (!leafletLoaded && typeof L === 'undefined') await loadLeaflet();
    if (typeof L === 'undefined') return;
    if (!adminCurrentLocation) { try { await getAdminCurrentLocation(); } catch {} }

    setTimeout(async () => {
        const container = document.getElementById('emergencyMap');
        if (!container) return;
        if (currentMap) currentMap.remove();

currentMap = L.map(container);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
}).addTo(currentMap);

        currentEmergencyMarkers = [];
        currentRoutes = [];
        currentCircles = [];

        if (adminCurrentLocation) {
            const adminIcon = L.divIcon({
                html: '<div style="background-color: #007bff; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white;"><i class="fas fa-user-shield" style="color: white; font-size: 22px;"></i></div>',
                iconSize: [44, 44], iconAnchor: [22, 22]
            });
            currentUserMarker = L.marker([adminCurrentLocation.lat, adminCurrentLocation.lng], { icon: adminIcon }).addTo(currentMap);
        }

        for (let i = 0; i < emergencies.length; i++) {
            const e = emergencies[i];
            const lat = parseFloat(e.latitude), lng = parseFloat(e.longitude);
            const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
            const m = createEmergencyMarker(lat, lng, i + 1, color, e);
            m.addTo(currentMap);
            currentEmergencyMarkers.push(m);

            const c = L.circle([lat, lng], { color, fillColor: color, fillOpacity: 0.12, radius: 400, weight: 2 }).addTo(currentMap);
            currentCircles.push(c);

            if (adminCurrentLocation) {
                await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, i);
            }
        }
        fitBoundsToAllPoints();
        L.control.scale({ metric: true, imperial: true }).addTo(currentMap);
        updateMultiEmergencyDistancePanel();
    }, 200);
}

function cleanupMap() {
    if (currentMap) { currentMap.remove(); currentMap = null; }
    currentUserMarker = null; currentEmergencyMarkers = [];
    currentRoutes = []; currentCircles = []; activeHighlightedRoute = null;
}

// ============ POLLING ============
async function loadEmergencies() {
    try {
        const r = await fetch(EMERGENCY_API_URL + '?limit=50');
        const data = await r.json();
        if (data.success && data.data) {
            const c = document.getElementById('emergencyListContainer');
            if (c) {
                c.innerHTML = data.data.length === 0
                    ? '<div class="empty-state-mini"><p>No emergencies yet</p></div>'
                    : '';
                if (data.data.length > 0) data.data.forEach(e => addEmergencyCard(e));
            }
            if (data.data.length > 0) {
                const maxId = Math.max(...data.data.map(e => e.id));
                if (maxId > lastEmergencyId) {
                    lastEmergencyId = maxId;
                    localStorage.setItem('lastEmergencyId', lastEmergencyId);
                }
            }
        }
    } catch (e) { console.error(e); }
}

async function checkNewEmergencies() {
    try {
        const r = await fetch(EMERGENCY_API_URL + '?since=' + lastEmergencyId);
        const data = await r.json();
        if (data.success && data.data?.length > 0) {
            const newE = data.data.filter(e => e.id > lastEmergencyId);
            if (newE.length > 0) {
                lastEmergencyId = Math.max(...newE.map(e => e.id));
                localStorage.setItem('lastEmergencyId', lastEmergencyId);
                for (const e of newE) await addToEmergencyQueue(e);
            }
        }
    } catch (e) { console.error(e); }
}

// ============ QUEUE & MODAL ============
async function addToEmergencyQueue(e) {
    if (isModalOpen && currentSwalInstance) await addEmergencyToExistingModal(e);
    else if (!isModalOpen) { pendingEmergencyQueue.push(e); await processEmergencyQueue(); }
    else pendingEmergencyQueue.push(e);
}

async function processEmergencyQueue() {
    if (pendingEmergencyQueue.length === 0 || isModalOpen) return;
    const toShow = [...pendingEmergencyQueue];
    pendingEmergencyQueue = [];
    await showMultiEmergencyModal(toShow);
}

async function addEmergencyToExistingModal(e) {
    if (isUpdatingModal) { pendingEmergencyQueue.push(e); return; }
    isUpdatingModal = true;
    try {
        currentEmergenciesInModal.push(e);
        const newTitle = currentEmergenciesInModal.length > 1
            ? `🚨 ${currentEmergenciesInModal.length} ACTIVE EMERGENCIES 🚨`
            : '🚨 EMERGENCY ALERT 🚨';
        if (currentSwalInstance) currentSwalInstance.update({ title: newTitle });

        if (currentMap && typeof L !== 'undefined') {
            const idx = currentEmergencyMarkers.length;
            const lat = parseFloat(e.latitude), lng = parseFloat(e.longitude);
            const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
            const m = createEmergencyMarker(lat, lng, idx + 1, color, e);
            m.addTo(currentMap);
            currentEmergencyMarkers.push(m);
            const c = L.circle([lat, lng], { color, fillColor: color, fillOpacity: 0.12, radius: 400, weight: 2 }).addTo(currentMap);
            currentCircles.push(c);
            if (adminCurrentLocation) await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, idx);
            fitBoundsToAllPoints();
        }

        const container = document.getElementById('multiEmergencyContainer');
        if (container) {
            const idx = currentEmergenciesInModal.length - 1;
            container.insertAdjacentHTML('beforeend', buildEmergencyCardHtml(e, idx));
        }
        updateMultiEmergencyDistancePanel();
    } catch (err) { console.error(err); }
    finally { isUpdatingModal = false; }
}

function buildEmergencyCardHtml(e, index) {
    const icon = EMERGENCY_ICONS[e.emergency_type.toLowerCase()] || EMERGENCY_ICONS.default;
    const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
    const date = new Date(e.created_at).toLocaleString();
    const lat = parseFloat(e.latitude), lng = parseFloat(e.longitude);
    return `<div class="multi-emergency-card" id="emergency-card-${index}" style="background: ${color}15; border-left: 4px solid ${color}; margin-bottom: 12px; padding: 10px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between;">
            <h4 style="margin: 0; color: ${color}; font-size: 14px;">${icon} ${e.emergency_type.toUpperCase()} EMERGENCY</h4>
            <span style="font-size: 10px; color: #888;">${date}</span>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; font-size: 12px;">
            <div><strong>👤</strong> ${escapeHtml(e.name)}</div>
            <div><strong>📍</strong> ${escapeHtml(e.barangay)}</div>
            <div><strong>🗺️</strong> ${lat.toFixed(4)}, ${lng.toFixed(4)}</div>
        </div>
        ${buildStatusBadgeHtml(e, index)}
    </div>`;
}

async function showMultiEmergencyModal(emergencies) {
    isModalOpen = true;
    currentEmergenciesInModal = [...emergencies];
    playEmergencySound();
    if (navigator.vibrate) navigator.vibrate([500, 200, 500]);

    if (Notification.permission === 'granted') {
        for (const e of emergencies) {
            new Notification('🚨 ' + e.emergency_type.toUpperCase() + ' EMERGENCY!', {
                body: `${e.name} from ${e.barangay}`,
                requireInteraction: true,
                vibrate: [500, 200, 500]
            });
        }
    }

    let html = '<div id="multiEmergencyContainer" style="max-height: 280px; overflow-y: auto; margin-bottom: 15px;">';
    for (let i = 0; i < emergencies.length; i++) html += buildEmergencyCardHtml(emergencies[i], i);
    html += '</div>';

    await Swal.fire({
        title: emergencies.length > 1 ? `🚨 ${emergencies.length} ACTIVE EMERGENCIES 🚨` : '🚨 EMERGENCY ALERT 🚨',
        html: `<div style="text-align: left;">
            ${html}
            <div id="emergencyMap" style="width: 100%; height: 380px; border-radius: 12px; background: #2d3748; margin-bottom: 10px;"></div>
            <div id="directionsPanel" style="margin-bottom: 10px;"></div>
            <div style="background: #dc3545; padding: 8px; border-radius: 8px; text-align: center;">
                <p style="color: white; margin: 0; font-weight: bold; font-size: 13px;">
                    <i class="fas fa-volume-up"></i> ${emergencies.length} EMERGENC${emergencies.length > 1 ? 'IES' : 'Y'} ACTIVE
                </p>
            </div>
        </div>`,
        icon: 'error',
        confirmButtonText: '✅ ACKNOWLEDGE & RESPOND ✅',
        confirmButtonColor: '#dc3545',
        allowOutsideClick: false,
        allowEscapeKey: false,
        backdrop: 'rgba(0,0,0,0.95)',
        width: '950px',
        didOpen: async () => {
            currentSwalInstance = Swal.getPopup();
            await initializeMap(emergencies);
            startStatusRefresh();
        },
        willClose: () => {
            isModalOpen = false;
            currentSwalInstance = null;
            cleanupMap();
            stopEmergencySound();
            stopStatusRefresh();
            if (navigator.vibrate) navigator.vibrate(0);
            if (pendingEmergencyQueue.length > 0) setTimeout(() => processEmergencyQueue(), 500);
        }
    });
}

function addEmergencyCard(e) {
    const container = document.getElementById('emergencyListContainer');
    if (!container) return;
    const icon = EMERGENCY_ICONS[e.emergency_type.toLowerCase()] || EMERGENCY_ICONS.default;
    const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#6c757d';
    const date = new Date(e.created_at).toLocaleString();
    const url = `https://www.openstreetmap.org/?mlat=${e.latitude}&mlon=${e.longitude}#map=14/${e.latitude}/${e.longitude}`;
    const accepted = e.status === 'ACCEPTED' && e.accepted_by_name;
    const badge = accepted
        ? `<span style="background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">✅ Accepted by ${escapeHtml(e.accepted_by_name)}</span>`
        : `<span style="background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">⏳ Pending</span>`;

    container.insertAdjacentHTML('afterbegin', `<div class="emergency-card" data-id="${e.id}" style="background: #fff; border-radius: 12px; padding: 15px; margin-bottom: 12px; border-left: 4px solid ${color};">
        <h4 style="margin: 0 0 8px 0; color: ${color};">${icon} ${e.emergency_type.toUpperCase()}</h4>
        <p style="margin: 5px 0;"><strong>Name:</strong> ${escapeHtml(e.name)}</p>
        <p style="margin: 5px 0;"><strong>Barangay:</strong> ${escapeHtml(e.barangay)}</p>
        <p style="margin: 5px 0;">${badge}</p>
        <p style="margin: 5px 0; font-size: 11px; color: #999;">${date}</p>
        <a href="${url}" target="_blank" style="background: #17a2b8; color: white; padding: 8px 15px; border-radius: 8px; text-decoration: none; display: inline-block; font-size: 12px;">Map</a>
    </div>`);
    const cards = container.querySelectorAll('.emergency-card');
    if (cards.length > 20) cards[cards.length - 1].remove();
}

function escapeHtml(t) {
    if (!t) return '';
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}
function refreshEmergencyList() { loadEmergencies(); }
function testEmergencySound() { playEmergencySound(); setTimeout(() => stopEmergencySound(), 5000); }

window.addEventListener('beforeunload', () => {
    stopWatchingAdminLocation(); stopEmergencySound(); stopStatusRefresh(); cleanupMap();
});

window.initEmergencyChecker = initEmergencyChecker;
window.refreshEmergencyList = refreshEmergencyList;
window.testEmergencySound = testEmergencySound;

console.log('✅ emergency_checker.js loaded');