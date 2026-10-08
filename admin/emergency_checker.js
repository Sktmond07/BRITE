// emergency_checker.js - WITH VIEW DIRECTION BUTTON (Call button removed, routes stay visible)

const EMERGENCY_CHECK_INTERVAL = 5000;
const EMERGENCY_API_URL = 'emergency.php';
const EMERGENCY_SOUND_URL = '/BRITE/admin/sounds/emergency_siren.mp3';

let lastEmergencyId = 0;
let checkInterval = null;
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

// Multi-emergency management
let pendingEmergencyQueue = [];
let isModalOpen = false;
let currentEmergenciesInModal = [];
let currentSwalInstance = null;
let isUpdatingModal = false;
let activeHighlightedRoute = null;

// Emergency icons and colors
const EMERGENCY_ICONS = {
    medical: '🚑',
    fire: '🔥',
    crime: '👮',
    accident: '💥',
    default: '🚨'
};

const EMERGENCY_COLORS = {
    medical: '#28a745',
    fire: '#fd7e14',
    crime: '#dc3545',
    accident: '#ffc107',
    default: '#6c757d'
};

// Store original route styles for reset
let originalRouteStyles = [];

// ============ LEAFLET MAP LOADER ============
function loadLeafletCSS() {
    return new Promise((resolve, reject) => {
        if (document.querySelector('link[href*="leaflet.css"]')) {
            resolve();
            return;
        }
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
        if (typeof L !== 'undefined') {
            leafletLoaded = true;
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = () => {
            leafletLoaded = true;
            resolve();
        };
        script.onerror = () => reject(new Error('Failed to load Leaflet JS'));
        document.head.appendChild(script);
    });
}

async function loadLeaflet() {
    try {
        await loadLeafletCSS();
        await loadLeafletJS();
        console.log('✅ Leaflet loaded successfully');
        return true;
    } catch (error) {
        console.error('Failed to load Leaflet:', error);
        return false;
    }
}

// ============ ADMIN LOCATION TRACKING ============
async function getAdminCurrentLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported'));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                adminCurrentLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    timestamp: new Date()
                };
                console.log('📍 Admin current location:', adminCurrentLocation);
                resolve(adminCurrentLocation);
            },
            (error) => {
                console.error('Geolocation error:', error);
                reject(error);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    });
}

function startWatchingAdminLocation() {
    if (!navigator.geolocation) return;
    if (watchPositionId) navigator.geolocation.clearWatch(watchPositionId);
    
    watchPositionId = navigator.geolocation.watchPosition(
        (position) => {
            const newLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude,
                accuracy: position.coords.accuracy,
                timestamp: new Date()
            };
            adminCurrentLocation = newLocation;
            console.log('📍 Admin location updated:', adminCurrentLocation);
            
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
    if (watchPositionId) {
        navigator.geolocation.clearWatch(watchPositionId);
        watchPositionId = null;
    }
}

// ============ INITIALIZATION ============
async function initEmergencyChecker() {
    console.log('🚨 Emergency Checker STARTED');
    
    const savedId = localStorage.getItem('lastEmergencyId');
    if (savedId) lastEmergencyId = parseInt(savedId);
    
    preloadEmergencySound();
    await autoRequestPushPermission();
    
    try {
        await getAdminCurrentLocation();
        startWatchingAdminLocation();
    } catch (error) {
        console.warn('Could not get admin location:', error.message);
    }
    
    await loadLeaflet().catch(console.warn);
    loadEmergencies();
    
    if (checkInterval) clearInterval(checkInterval);
    checkInterval = setInterval(checkNewEmergencies, EMERGENCY_CHECK_INTERVAL);
    
    console.log('Checking every', EMERGENCY_CHECK_INTERVAL, 'ms | Last ID:', lastEmergencyId);
}

// ============ UPDATE ALL ROUTES AND MARKERS ============
async function updateAllRoutesAndMarkers() {
    if (!currentMap || !adminCurrentLocation || !isModalOpen) return;
    
    console.log('Updating all routes and markers for', currentEmergenciesInModal.length, 'emergencies');
    
    // Clear existing routes and labels
    currentRoutes.forEach(route => {
        if (currentMap && route) currentMap.removeLayer(route);
    });
    currentRoutes = [];
    
    currentRouteLabels.forEach(label => {
        if (currentMap && label) currentMap.removeLayer(label);
    });
    currentRouteLabels = [];
    
    // Clear existing circles
    currentCircles.forEach(circle => {
        if (currentMap && circle) currentMap.removeLayer(circle);
    });
    currentCircles = [];
    
    // Update each emergency marker and redraw routes
    for (let i = 0; i < currentEmergenciesInModal.length; i++) {
        const emergency = currentEmergenciesInModal[i];
        const lat = parseFloat(emergency.latitude);
        const lng = parseFloat(emergency.longitude);
        const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#dc3545';
        
        // Update or create marker
        if (currentEmergencyMarkers[i]) {
            currentEmergencyMarkers[i].setLatLng([lat, lng]);
            currentEmergencyMarkers[i].setIcon(createEmergencyIcon(i + 1, color));
        } else {
            const marker = createEmergencyMarker(lat, lng, i + 1, color, emergency);
            marker.addTo(currentMap);
            currentEmergencyMarkers.push(marker);
        }
        
        // Update circle
        const circle = L.circle([lat, lng], {
            color: color,
            fillColor: color,
            fillOpacity: 0.12,
            radius: 400,
            weight: 2
        }).addTo(currentMap);
        currentCircles.push(circle);
        
        // Draw route from admin to emergency
        if (adminCurrentLocation) {
            await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, i);
        }
    }
    
    // Fit bounds to show all points
    fitBoundsToAllPoints();
    
    // Update distance panel
    updateMultiEmergencyDistancePanel();
}

function createEmergencyIcon(number, color) {
    return L.divIcon({
        html: `<div style="background-color: ${color}; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.3); animation: pulse 1.5s infinite;">
                <span style="color: white; font-size: 22px; font-weight: bold;">${number}</span>
               </div>`,
        className: 'custom-emergency-marker',
        iconSize: [48, 48],
        iconAnchor: [24, 24],
        popupAnchor: [0, -24]
    });
}

function createEmergencyMarker(lat, lng, number, color, emergency) {
    const icon = createEmergencyIcon(number, color);
    return L.marker([lat, lng], { icon: icon })
        .bindPopup(`
            <div style="min-width: 200px;">
                <b style="color: ${color};">🚨 EMERGENCY #${number}</b><br>
                <b>Type:</b> ${emergency.emergency_type.toUpperCase()}<br>
                <b>Name:</b> ${escapeHtml(emergency.name)}<br>
                <b>Barangay:</b> ${escapeHtml(emergency.barangay)}<br>
                <b>Time:</b> ${new Date(emergency.created_at).toLocaleString()}
            </div>
        `);
}

async function drawRouteOnMap(originLat, originLng, destLat, destLng, index) {
    try {
        const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${originLng},${originLat};${destLng},${destLat}?overview=full&geometries=geojson`);
        const data = await response.json();
        
        if (data.code === 'Ok' && data.routes && data.routes.length > 0) {
            const route = data.routes[0];
            const distance = (route.distance / 1000).toFixed(1);
            const duration = Math.round(route.duration / 60);
            
            // Different colors for different routes
            const colors = ['#43e97b', '#ff6b6b', '#4ecdc4', '#ffe66d', '#a8e6cf', '#ffd3b6'];
            const routeColor = colors[index % colors.length];
            
            const routeLayer = L.geoJSON(route.geometry, {
                style: {
                    color: routeColor,
                    weight: 3,
                    opacity: 0.7,
                    dashArray: '8, 6'
                }
            }).addTo(currentMap);
            
            // Store route data for later reference
            routeLayer.routeData = {
                index: index,
                distance: distance,
                duration: duration,
                color: routeColor,
                originLat: originLat,
                originLng: originLng,
                destLat: destLat,
                destLng: destLng
            };
            
            currentRoutes.push(routeLayer);
        }
    } catch (error) {
        console.warn('Route calculation failed for emergency', index, ':', error);
        const straightLine = L.polyline([[originLat, originLng], [destLat, destLng]], {
            color: '#ff9800',
            weight: 3,
            opacity: 0.6,
            dashArray: '5, 10'
        }).addTo(currentMap);
        currentRoutes.push(straightLine);
    }
}

// Function to highlight a specific route and show directions
async function showDirectionsForEmergency(index) {
    if (!currentMap || !adminCurrentLocation) return;
    
    const emergency = currentEmergenciesInModal[index];
    if (!emergency) return;
    
    console.log('Showing directions for emergency #', index + 1);
    
    // If this route is already highlighted, just reset to normal
    if (activeHighlightedRoute === index) {
        resetAllRoutesToNormal();
        return;
    }
    
    // Reset previous highlighted route
    resetAllRoutesToNormal();
    
    // Highlight the selected route
    if (currentRoutes[index] && currentRoutes[index].setStyle) {
        currentRoutes[index].setStyle({
            color: '#ff9800',
            weight: 6,
            opacity: 1,
            dashArray: 'none'
        });
        
        // Bring to front
        currentRoutes[index].bringToFront();
        
        activeHighlightedRoute = index;
    }
    
    // Center map on the route
    const destLat = parseFloat(emergency.latitude);
    const destLng = parseFloat(emergency.longitude);
    const midLat = (adminCurrentLocation.lat + destLat) / 2;
    const midLng = (adminCurrentLocation.lng + destLng) / 2;
    currentMap.setView([midLat, midLng], 12);
    
    // Open popup on the emergency marker
    if (currentEmergencyMarkers[index]) {
        currentEmergencyMarkers[index].openPopup();
    }
    
    // Show direction instructions panel
    showDirectionInstructions(emergency, index);
}

function resetAllRoutesToNormal() {
    for (let i = 0; i < currentRoutes.length; i++) {
        const route = currentRoutes[i];
        const colors = ['#43e97b', '#ff6b6b', '#4ecdc4', '#ffe66d', '#a8e6cf', '#ffd3b6'];
        if (route && route.setStyle) {
            route.setStyle({
                color: colors[i % colors.length],
                weight: 3,
                opacity: 0.7,
                dashArray: '8, 6'
            });
        }
    }
    activeHighlightedRoute = null;
}

function showDirectionInstructions(emergency, index) {
    const panel = document.getElementById('directionsPanel');
    if (!panel) return;
    
    const distance = calculateDistance(
        adminCurrentLocation.lat, adminCurrentLocation.lng,
        parseFloat(emergency.latitude), parseFloat(emergency.longitude)
    );
    const distanceKm = (distance / 1000).toFixed(1);
    const distanceMeters = distance.toFixed(0);
    const estimatedMinutes = Math.ceil(distance / 500);
    
    const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#dc3545';
    
    panel.innerHTML = `
        <div style="background: linear-gradient(135deg, #1e2a3a 0%, #16212e 100%); border-radius: 12px; padding: 15px; border-left: 5px solid ${color};">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 style="margin: 0; color: ${color};">
                    <i class="fas fa-directions"></i> DIRECTIONS TO EMERGENCY #${index + 1}
                </h4>
                <button onclick="closeDirections()" style="background: none; border: none; color: #888; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            
            <div style="background: ${color}20; border-radius: 10px; padding: 12px; margin-bottom: 12px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #aaa;">🚨 EMERGENCY TYPE</p>
                        <p style="margin: 0; font-weight: bold; font-size: 16px;">${emergency.emergency_type.toUpperCase()}</p>
                    </div>
                    <div>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #aaa;">📍 DISTANCE</p>
                        <p style="margin: 0; font-weight: bold; font-size: 16px;">${distanceKm} km (${distanceMeters} m)</p>
                    </div>
                    <div>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #aaa;">⏱️ ESTIMATED TRAVEL TIME</p>
                        <p style="margin: 0; font-weight: bold;">${estimatedMinutes} minutes by vehicle</p>
                    </div>
                    <div>
                        <p style="margin: 0 0 5px 0; font-size: 12px; color: #aaa;">👤 CALLER</p>
                        <p style="margin: 0; font-weight: bold;">${escapeHtml(emergency.name)}</p>
                    </div>
                </div>
            </div>
            
            <div style="background: #0d1b2a; border-radius: 8px; padding: 10px;">
                <p style="margin: 0 0 5px 0; font-size: 11px; color: #aaa;">
                    <i class="fas fa-info-circle"></i> The highlighted route shows the best path from your current location
                </p>
                <p style="margin: 0; font-size: 11px; color: #aaa;">
                    <i class="fas fa-sync-alt"></i> Route updates automatically when you move
                </p>
            </div>
        </div>
    `;
}

function closeDirections() {
    resetAllRoutesToNormal();
    
    // Reset distance panel to normal
    updateMultiEmergencyDistancePanel();
}

function fitBoundsToAllPoints() {
    if (!currentMap) return;
    
    const allPoints = [];
    if (adminCurrentLocation) {
        allPoints.push([adminCurrentLocation.lat, adminCurrentLocation.lng]);
    }
    currentEmergenciesInModal.forEach(e => {
        allPoints.push([parseFloat(e.latitude), parseFloat(e.longitude)]);
    });
    
    if (allPoints.length > 0) {
        const bounds = L.latLngBounds(allPoints);
        currentMap.fitBounds(bounds, { padding: [50, 50] });
    }
}

function updateMultiEmergencyDistancePanel() {
    const panel = document.getElementById('directionsPanel');
    if (!panel || !adminCurrentLocation || !isModalOpen) return;
    
    let html = '<div style="background: #1e2a3a; border-radius: 12px; padding: 15px;">';
    html += '<p style="margin: 0 0 12px 0; font-weight: bold; color: #43e97b; font-size: 14px;">';
    html += '<i class="fas fa-chart-line"></i> 📍 EMERGENCIES DASHBOARD</p>';
    
    for (let i = 0; i < currentEmergenciesInModal.length; i++) {
        const emergency = currentEmergenciesInModal[i];
        const distance = calculateDistance(
            adminCurrentLocation.lat, adminCurrentLocation.lng,
            parseFloat(emergency.latitude), parseFloat(emergency.longitude)
        );
        const distanceKm = (distance / 1000).toFixed(1);
        const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#dc3545';
        
        html += `
            <div class="emergency-dashboard-item" style="background: ${color}15; border-left: 4px solid ${color}; margin-bottom: 12px; padding: 12px; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-weight: bold; font-size: 14px;">${EMERGENCY_ICONS[emergency.emergency_type.toLowerCase()] || '🚨'} #${i+1}: ${emergency.emergency_type.toUpperCase()}</span>
                    <span style="background: ${color}; padding: 2px 8px; border-radius: 20px; font-size: 11px; color: white;">${distanceKm} km</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 11px; margin-bottom: 10px;">
                    <div><i class="fas fa-user"></i> ${escapeHtml(emergency.name)}</div>
                    <div><i class="fas fa-map-marker-alt"></i> ${escapeHtml(emergency.barangay)}</div>
                </div>
                <div>
                    <button onclick="showDirectionsForEmergency(${i})" style="background: ${color}; border: none; padding: 6px 12px; border-radius: 6px; color: white; cursor: pointer; font-size: 12px; width: 100%;">
                        <i class="fas fa-directions"></i> View Direction & Route
                    </button>
                </div>
            </div>
        `;
    }
    
    html += '</div>';
    panel.innerHTML = html;
}

function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

// ============ SOUND FUNCTIONS ============
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
        if (emergencyAudio) {
            emergencyAudio.currentTime = 0;
            emergencyAudio.loop = true;
            emergencyAudio.volume = 0.9;
            emergencyAudio.play().catch(e => console.warn('Audio play failed:', e));
        }
    } catch(e) { console.warn('Sound error:', e); }
}

function stopEmergencySound() {
    if (emergencyAudio) {
        emergencyAudio.pause();
        emergencyAudio.currentTime = 0;
    }
}

// ============ NOTIFICATION PERMISSION ============
async function autoRequestPushPermission() {
    if (Notification.permission === 'granted') return;
    if (Notification.permission === 'denied') {
        showNotificationGuide();
        return;
    }
    try {
        await Notification.requestPermission();
    } catch (error) { console.error('Permission request error:', error); }
}

function showNotificationGuide() {
    const guide = document.createElement('div');
    guide.style.cssText = `position: fixed; bottom: 20px; right: 20px; background: #333; color: white; padding: 12px 18px; border-radius: 10px; z-index: 99999; font-size: 12px; max-width: 280px; cursor: pointer;`;
    guide.innerHTML = `<i class="fas fa-bell"></i> <strong>Enable Notifications</strong><br>Allow notifications for alerts<br><button onclick="this.parentElement.remove()" style="background: #43e97b; border: none; padding: 3px 10px; border-radius: 5px; margin-top: 8px; cursor: pointer;">OK</button>`;
    guide.onclick = () => guide.remove();
    document.body.appendChild(guide);
    setTimeout(() => guide.remove(), 8000);
}

// ============ MAP INITIALIZATION ============
async function initializeMap(emergencies) {
    if (!leafletLoaded && typeof L === 'undefined') {
        await loadLeaflet();
    }
    if (typeof L === 'undefined') {
        console.error('Leaflet not available');
        return;
    }
    
    if (!adminCurrentLocation) {
        try { await getAdminCurrentLocation(); } catch(e) { console.warn(e); }
    }
    
    setTimeout(async () => {
        const mapContainer = document.getElementById('emergencyMap');
        if (!mapContainer) return;
        
        if (currentMap) {
            currentMap.remove();
        }
        
        currentMap = L.map(mapContainer);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OSM & CartoDB',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(currentMap);
        
        // Reset arrays
        currentEmergencyMarkers = [];
        currentRoutes = [];
        currentCircles = [];
        currentRouteLabels = [];
        
        // Add admin marker if available
        if (adminCurrentLocation) {
            const adminIcon = L.divIcon({
                html: '<div style="background-color: #007bff; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3);"><i class="fas fa-user-shield" style="color: white; font-size: 22px;"></i><div style="position: absolute; bottom: -22px; left: 50%; transform: translateX(-50%); white-space: nowrap; background: #007bff; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: bold;">YOU ARE HERE</div></div>',
                iconSize: [44, 44],
                iconAnchor: [22, 22]
            });
            currentUserMarker = L.marker([adminCurrentLocation.lat, adminCurrentLocation.lng], { icon: adminIcon })
                .addTo(currentMap)
                .bindPopup('<b>📍 YOUR CURRENT LOCATION</b><br>Admin position');
        }
        
        // Add all emergency markers and routes
        for (let i = 0; i < emergencies.length; i++) {
            const e = emergencies[i];
            const lat = parseFloat(e.latitude);
            const lng = parseFloat(e.longitude);
            const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
            
            // Add marker
            const marker = createEmergencyMarker(lat, lng, i + 1, color, e);
            marker.addTo(currentMap);
            currentEmergencyMarkers.push(marker);
            
            // Add circle
            const circle = L.circle([lat, lng], {
                color: color,
                fillColor: color,
                fillOpacity: 0.12,
                radius: 400,
                weight: 2
            }).addTo(currentMap);
            currentCircles.push(circle);
            
            // Draw route
            if (adminCurrentLocation) {
                await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, i);
            }
        }
        
        // Fit bounds
        fitBoundsToAllPoints();
        
        // Add scale control
        L.control.scale({ metric: true, imperial: true }).addTo(currentMap);
        
        // Update distance panel
        updateMultiEmergencyDistancePanel();
        
        console.log('Map initialized with', emergencies.length, 'emergencies');
    }, 200);
}

function cleanupMap() {
    if (currentMap) {
        currentMap.remove();
        currentMap = null;
    }
    currentUserMarker = null;
    currentEmergencyMarkers = [];
    currentRoutes = [];
    currentCircles = [];
    currentRouteLabels = [];
    activeHighlightedRoute = null;
}

// ============ EMERGENCY CHECKING ============
async function loadEmergencies() {
    try {
        const response = await fetch(EMERGENCY_API_URL + '?limit=50');
        const data = await response.json();
        
        if (data.success && data.data) {
            const container = document.getElementById('emergencyListContainer');
            if (container) {
                if (data.data.length === 0) {
                    container.innerHTML = '<div class="empty-state-mini"><p>No emergencies yet</p></div>';
                } else {
                    container.innerHTML = '';
                    data.data.forEach(emergency => addEmergencyCard(emergency));
                }
            }
            
            if (data.data.length > 0) {
                const maxId = Math.max(...data.data.map(e => e.id));
                if (maxId > lastEmergencyId) {
                    lastEmergencyId = maxId;
                    localStorage.setItem('lastEmergencyId', lastEmergencyId);
                }
            }
        }
    } catch (error) { console.error('Load error:', error); }
}

async function checkNewEmergencies() {
    try {
        const url = EMERGENCY_API_URL + '?since=' + lastEmergencyId;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            const newEmergencies = data.data.filter(e => e.id > lastEmergencyId);
            
            if (newEmergencies.length > 0) {
                console.log('🚨 NEW EMERGENCY(IES)!', newEmergencies.length);
                const maxId = Math.max(...newEmergencies.map(e => e.id));
                lastEmergencyId = maxId;
                localStorage.setItem('lastEmergencyId', lastEmergencyId);
                
                for (const emergency of newEmergencies) {
                    await addToEmergencyQueue(emergency);
                }
            }
        }
    } catch (error) { console.error('Check error:', error); }
}

// ============ CORE MULTI-EMERGENCY LOGIC ============
async function addToEmergencyQueue(emergency) {
    console.log('Adding emergency:', emergency.emergency_type, 'Modal open:', isModalOpen);
    
    if (isModalOpen && currentSwalInstance) {
        console.log('Adding to existing modal');
        await addEmergencyToExistingModal(emergency);
    } else if (!isModalOpen) {
        console.log('No modal open, adding to queue');
        pendingEmergencyQueue.push(emergency);
        await processEmergencyQueue();
    } else {
        pendingEmergencyQueue.push(emergency);
    }
}

async function processEmergencyQueue() {
    if (pendingEmergencyQueue.length === 0) return;
    if (isModalOpen) return;
    
    const emergenciesToShow = [...pendingEmergencyQueue];
    pendingEmergencyQueue = [];
    
    console.log(`Processing ${emergenciesToShow.length} emergencies`);
    await showMultiEmergencyModal(emergenciesToShow);
}

async function addEmergencyToExistingModal(emergency) {
    if (isUpdatingModal) {
        pendingEmergencyQueue.push(emergency);
        return;
    }
    
    isUpdatingModal = true;
    
    try {
        console.log('Adding emergency to existing modal');
        currentEmergenciesInModal.push(emergency);
        
        // Update modal title
        const newTitle = currentEmergenciesInModal.length > 1 ? 
            `🚨 ${currentEmergenciesInModal.length} ACTIVE EMERGENCIES 🚨` : 
            '🚨 EMERGENCY ALERT 🚨';
        
        if (currentSwalInstance) {
            currentSwalInstance.update({ title: newTitle });
        }
        
        // Add to map without recreating the whole map
        if (currentMap && typeof L !== 'undefined') {
            const newIndex = currentEmergencyMarkers.length;
            const lat = parseFloat(emergency.latitude);
            const lng = parseFloat(emergency.longitude);
            const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#dc3545';
            
            // Add marker
            const marker = createEmergencyMarker(lat, lng, newIndex + 1, color, emergency);
            marker.addTo(currentMap);
            currentEmergencyMarkers.push(marker);
            
            // Add circle
            const circle = L.circle([lat, lng], {
                color: color,
                fillColor: color,
                fillOpacity: 0.12,
                radius: 400,
                weight: 2
            }).addTo(currentMap);
            currentCircles.push(circle);
            
            // Draw route
            if (adminCurrentLocation) {
                await drawRouteOnMap(adminCurrentLocation.lat, adminCurrentLocation.lng, lat, lng, newIndex);
            }
            
            // Update marker numbers (renumber all)
            for (let i = 0; i < currentEmergencyMarkers.length; i++) {
                const m = currentEmergencyMarkers[i];
                const newColor = EMERGENCY_COLORS[currentEmergenciesInModal[i].emergency_type.toLowerCase()] || '#dc3545';
                m.setIcon(createEmergencyIcon(i + 1, newColor));
            }
            
            // Fit bounds
            fitBoundsToAllPoints();
        }
        
        // Add emergency card to modal content
        const container = document.getElementById('multiEmergencyContainer');
        if (container) {
            const icon = EMERGENCY_ICONS[emergency.emergency_type.toLowerCase()] || EMERGENCY_ICONS.default;
            const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#dc3545';
            const date = new Date(emergency.created_at).toLocaleString();
            const lat = parseFloat(emergency.latitude);
            const lng = parseFloat(emergency.longitude);
            
            const newCard = `
                <div class="multi-emergency-card" style="background: ${color}15; border-left: 4px solid ${color}; margin-bottom: 12px; padding: 10px; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0; color: ${color}; font-size: 14px;">${icon} ${emergency.emergency_type.toUpperCase()} EMERGENCY</h4>
                        <span style="font-size: 10px; color: #888;">${date}</span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; font-size: 12px;">
                        <div><strong>👤</strong> ${escapeHtml(emergency.name)}</div>
                        <div><strong>📍</strong> ${escapeHtml(emergency.barangay)}</div>
                        <div><strong>🗺️</strong> ${lat.toFixed(4)}, ${lng.toFixed(4)}</div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', newCard);
        }
        
        // Update distance panel
        updateMultiEmergencyDistancePanel();
        
        // Show toast notification
        if (typeof Swal !== 'undefined') {
            const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
            toast.fire({
                icon: 'warning',
                title: '🚨 NEW EMERGENCY',
                html: `${EMERGENCY_ICONS[emergency.emergency_type.toLowerCase()] || '🚨'} <strong>${emergency.emergency_type.toUpperCase()}</strong><br>${escapeHtml(emergency.name)}<br>📍 ${escapeHtml(emergency.barangay)}`,
                background: '#dc3545',
                color: 'white'
            });
        }
        
        console.log('Emergency added, total:', currentEmergenciesInModal.length);
        
    } catch (error) {
        console.error('Error adding emergency:', error);
    } finally {
        isUpdatingModal = false;
    }
}

async function showMultiEmergencyModal(emergencies) {
    console.log('Showing modal with', emergencies.length, 'emergencies');
    
    isModalOpen = true;
    currentEmergenciesInModal = [...emergencies];
    
    playEmergencySound();
    if (navigator.vibrate) navigator.vibrate([500, 200, 500]);
    
    // Send notifications
    if (Notification.permission === 'granted') {
        for (const e of emergencies) {
            new Notification('🚨 ' + e.emergency_type.toUpperCase() + ' EMERGENCY!', {
                body: `${e.name} from ${e.barangay}`,
                icon: '/BRITE/logo.jpg',
                requireInteraction: true,
                vibrate: [500, 200, 500]
            });
        }
    }
    
    // Build emergencies HTML
    let emergenciesHtml = '<div id="multiEmergencyContainer" style="max-height: 280px; overflow-y: auto; margin-bottom: 15px;">';
    for (let i = 0; i < emergencies.length; i++) {
        const e = emergencies[i];
        const icon = EMERGENCY_ICONS[e.emergency_type.toLowerCase()] || EMERGENCY_ICONS.default;
        const color = EMERGENCY_COLORS[e.emergency_type.toLowerCase()] || '#dc3545';
        const date = new Date(e.created_at).toLocaleString();
        const lat = parseFloat(e.latitude);
        const lng = parseFloat(e.longitude);
        
        emergenciesHtml += `
            <div class="multi-emergency-card" style="background: ${color}15; border-left: 4px solid ${color}; margin-bottom: 12px; padding: 10px; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="margin: 0; color: ${color}; font-size: 14px;">${icon} ${e.emergency_type.toUpperCase()} EMERGENCY</h4>
                    <span style="font-size: 10px; color: #888;">${date}</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; font-size: 12px;">
                    <div><strong>👤</strong> ${escapeHtml(e.name)}</div>
                    <div><strong>📍</strong> ${escapeHtml(e.barangay)}</div>
                    <div><strong>🗺️</strong> ${lat.toFixed(4)}, ${lng.toFixed(4)}</div>
                </div>
            </div>
        `;
    }
    emergenciesHtml += '</div>';
    
    const result = await Swal.fire({
        title: emergencies.length > 1 ? `🚨 ${emergencies.length} ACTIVE EMERGENCIES 🚨` : '🚨 EMERGENCY ALERT 🚨',
        html: `
            <div style="text-align: left;">
                ${emergenciesHtml}
                <div id="emergencyMap" style="width: 100%; height: 380px; border-radius: 12px; background: #2d3748; margin-bottom: 10px;"></div>
                <div id="directionsPanel" style="margin-bottom: 10px;"></div>
                <hr>
                <div style="background: #dc3545; padding: 8px; border-radius: 8px; text-align: center;">
                    <p style="color: white; margin: 0; font-weight: bold; font-size: 13px;">
                        <i class="fas fa-volume-up"></i> ${emergencies.length} EMERGENC${emergencies.length > 1 ? 'IES' : 'Y'} ACTIVE - SIREN PLAYING
                    </p>
                </div>
                <p style="font-size: 10px; color: #888; text-align: center; margin-top: 8px;">
                    <i class="fas fa-map-marked-alt"></i> Numbered markers = Emergency locations | Click "View Direction" to see route
                </p>
            </div>
        `,
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
        },
        willClose: () => {
            isModalOpen = false;
            currentSwalInstance = null;
            cleanupMap();
            stopEmergencySound();
            if (navigator.vibrate) navigator.vibrate(0);
            
            if (pendingEmergencyQueue.length > 0) {
                console.log('Modal closed, processing queue:', pendingEmergencyQueue.length);
                setTimeout(() => processEmergencyQueue(), 500);
            }
        }
    });
}

function addEmergencyCard(emergency) {
    const container = document.getElementById('emergencyListContainer');
    if (!container) return;
    
    const icon = EMERGENCY_ICONS[emergency.emergency_type.toLowerCase()] || EMERGENCY_ICONS.default;
    const color = EMERGENCY_COLORS[emergency.emergency_type.toLowerCase()] || '#6c757d';
    const date = new Date(emergency.created_at).toLocaleString();
    const locationUrl = `https://www.openstreetmap.org/?mlat=${emergency.latitude}&mlon=${emergency.longitude}#map=14/${emergency.latitude}/${emergency.longitude}`;
    
    const card = `
        <div class="emergency-card" data-id="${emergency.id}" style="background: #fff; border-radius: 12px; padding: 15px; margin-bottom: 12px; border-left: 4px solid ${color}; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 8px 0; color: ${color};">${icon} ${emergency.emergency_type.toUpperCase()}</h4>
                    <p style="margin: 5px 0;"><strong>Name:</strong> ${escapeHtml(emergency.name)}</p>
                    <p style="margin: 5px 0;"><strong>Barangay:</strong> ${escapeHtml(emergency.barangay)}</p>
                    <p style="margin: 5px 0; font-size: 11px; color: #999;">${date}</p>
                </div>
                <div>
                    <a href="${locationUrl}" target="_blank" style="background: #17a2b8; color: white; padding: 8px 15px; border-radius: 8px; text-decoration: none; display: inline-block; font-size: 12px;">
                        <i class="fas fa-map-marker-alt"></i> Map
                    </a>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('afterbegin', card);
    const cards = container.querySelectorAll('.emergency-card');
    if (cards.length > 20) cards[cards.length - 1].remove();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function refreshEmergencyList() {
    loadEmergencies();
}

function testEmergencySound() {
    console.log('Testing emergency sound...');
    playEmergencySound();
    setTimeout(() => stopEmergencySound(), 5000);
}

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    stopWatchingAdminLocation();
    stopEmergencySound();
    cleanupMap();
});

// Expose global functions
window.initEmergencyChecker = initEmergencyChecker;
window.refreshEmergencyList = refreshEmergencyList;
window.testEmergencySound = testEmergencySound;
window.showDirectionsForEmergency = showDirectionsForEmergency;
window.closeDirections = closeDirections;

console.log('✅ emergency_checker.js loaded');