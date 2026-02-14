<?php
/**
 * Autonomous Vehicle Map View - Shows all autonomous vehicles on a Leaflet map
 * 
 * Vehicles are displayed as robot car icons:
 * 🤖🚗 - Available (green)
 * 🔄🚗 - Busy/On Ride (orange)
 * 🔧🚗 - Maintenance (red)
 * ⚡🚗 - Charging (blue)
 * ⬛🚗 - Offline (gray)
 * 
 * Only operators can access this page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('operator');

$pageTitle = 'Autonomous Vehicle Map';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .av-map-container {
        position: relative;
        height: calc(100vh - 180px);
        min-height: 500px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    #av-map {
        height: 100%;
        width: 100%;
    }
    
    .map-controls {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1000;
        background: var(--color-surface, #f8fafc);
        padding: 12px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        min-width: 220px;
        max-height: calc(100% - 30px);
        overflow-y: auto;
        color: var(--text-color, #fff);
    }
    
    .map-controls h4 {
        margin: 0 0 10px 0;
        font-size: 0.9rem;
        color: var(--text-color, #fff);
        border-bottom: 1px solid var(--border-color, #eee);
        padding-bottom: 8px;
    }
    
    .stats-row {
        display: flex;
        justify-content: space-between;
        margin: 6px 0;
        font-size: 0.85rem;
    }
    
    .stats-row .label {
        color: var(--text-color, #fff);
    }
    
    .stats-row .value {
        font-weight: 600;
    }
    
    .stats-row.available .value { color: #22c55e; }
    .stats-row.busy .value { color: #f59e0b; }
    .stats-row.charging .value { color: #3b82f6; }
    .stats-row.maintenance .value { color: #ef4444; }
    .stats-row.offline .value { color: #6b7280; }
    .stats-row.total .value { color: #3b82f6; }
    
    .filter-group {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--border-color, #eee);
    }
    
    .filter-group label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        cursor: pointer;
        margin: 6px 0;
    }
    
    .filter-group input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
    
    .legend {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--border-color, #eee);
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        margin: 4px 0;
    }
    
    .legend-icon {
        font-size: 1.1rem;
        width: 24px;
        text-align: center;
    }
    
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255,255,255,0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1001;
    }
    
    .loading-spinner {
        text-align: center;
    }
    
    .loading-spinner .spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #e0e0e0;
        border-top-color: #3b82f6;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* AV popup styling */
    .av-popup {
        min-width: 200px;
    }
    
    .av-popup h4 {
        margin: 0 0 8px 0;
        font-size: 0.95rem;
    }
    
    .av-popup .status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .av-popup .status.available {
        background: #dcfce7;
        color: #166534;
    }
    
    .av-popup .status.busy {
        background: #fef3c7;
        color: #92400e;
    }
    
    .av-popup .status.maintenance {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .av-popup .status.charging {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .av-popup .status.offline {
        background: #f1f5f9;
        color: #64748b;
    }
    
    .av-popup .detail {
        font-size: 0.8rem;
        color: #666;
        margin: 4px 0;
    }
    
    .av-popup .vehicle-info {
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #eee;
        font-size: 0.8rem;
    }
    
    .av-popup .battery-bar {
        height: 8px;
        background: #e5e7eb;
        border-radius: 4px;
        margin-top: 4px;
        overflow: hidden;
    }
    
    .av-popup .battery-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }
    
    .av-popup .view-btn {
        display: block;
        margin-top: 10px;
        padding: 6px 10px;
        background: #3b82f6;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        text-align: center;
        font-size: 0.8rem;
    }
    
    .av-popup .view-btn:hover {
        background: #2563eb;
    }
    
    /* Custom marker styles */
    .av-marker {
        background: none;
        border: none;
    }
    
    .av-marker-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    }
    
    .av-marker-available .av-marker-icon { background: #dcfce7; }
    .av-marker-busy .av-marker-icon { background: #fef3c7; }
    .av-marker-maintenance .av-marker-icon { background: #fee2e2; }
    .av-marker-charging .av-marker-icon { background: #dbeafe; }
    .av-marker-offline .av-marker-icon { background: #f1f5f9; }
</style>

<div class="card" style="max-width: 1400px; margin: 1rem auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">🗺️ Autonomous Vehicle Map</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                Real-time view of all autonomous vehicles. Click on a vehicle to see details.
            </p>
        </div>
        <a href="<?php echo e(url('operator/autonomous_vehicles.php')); ?>" class="btn btn-outline btn-small">
            AV Vehicle List
        </a>
    </div>
    
    <div class="av-map-container">
        <div id="av-map"></div>
        
        <div class="map-controls">
            <h4>🤖 Vehicle Statistics</h4>
            
            <div class="stats-row total">
                <span class="label">Total Vehicles:</span>
                <span class="value" id="stat-total">Loading...</span>
            </div>
            <div class="stats-row available">
                <span class="label">Available:</span>
                <span class="value" id="stat-available">-</span>
            </div>
            <div class="stats-row busy">
                <span class="label">On Ride:</span>
                <span class="value" id="stat-busy">-</span>
            </div>
            <div class="stats-row charging">
                <span class="label">Charging:</span>
                <span class="value" id="stat-charging">-</span>
            </div>
            <div class="stats-row maintenance">
                <span class="label">Maintenance:</span>
                <span class="value" id="stat-maintenance">-</span>
            </div>
            <div class="stats-row offline">
                <span class="label">Offline:</span>
                <span class="value" id="stat-offline">-</span>
            </div>
            <div class="stats-row">
                <span class="label">Visible on map:</span>
                <span class="value" id="stat-visible">-</span>
            </div>
            
            <div class="filter-group">
                <strong style="font-size: 0.85rem;">Filter by Status:</strong>
                <label>
                    <input type="checkbox" id="filter-available" checked>
                    <span style="color:#22c55e;">●</span> Available
                </label>
                <label>
                    <input type="checkbox" id="filter-busy" checked>
                    <span style="color:#f59e0b;">●</span> On Ride
                </label>
                <label>
                    <input type="checkbox" id="filter-charging" checked>
                    <span style="color:#3b82f6;">●</span> Charging
                </label>
                <label>
                    <input type="checkbox" id="filter-maintenance" checked>
                    <span style="color:#ef4444;">●</span> Maintenance
                </label>
                <label>
                    <input type="checkbox" id="filter-offline">
                    <span style="color:#6b7280;">●</span> Offline
                </label>
            </div>
            
            <div class="legend">
                <strong style="font-size: 0.85rem;">Legend:</strong>
                <div class="legend-item">
                    <span class="legend-icon">🚗</span>
                    <span>Available AV</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon">🚕</span>
                    <span>On Ride</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon">🔧</span>
                    <span>Maintenance</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon">⚡</span>
                    <span>Charging</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon" style="opacity:0.5;">🚗</span>
                    <span>Offline</span>
                </div>
            </div>
            
            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border-color, #eee);">
                <button id="btn-toggle-zones" class="btn btn-sm" style="width: 100%; margin-bottom: 6px; background: #2563eb; color: white;">
                    🗺️ Hide Zones
                </button>
                <button id="btn-refresh" class="btn btn-sm btn-secondary" style="width: 100%;">
                    🔄 Refresh Vehicles
                </button>
            </div>
        </div>
        
        <div class="loading-overlay" id="loading-overlay">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p style="margin-top: 10px; color: #666;">Loading autonomous vehicles...</p>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Cyprus center coordinates
    var CYPRUS_CENTER = [35.1264, 33.4299];
    
    // Map instance
    var map;
    var markers = [];
    var allVehicles = [];
    var geofenceLayers = [];
    var geofencesVisible = true;
    
    // Status icons and colors
    var statusConfig = {
        available: { emoji: '🚗', bg: '#dcfce7', color: '#166534' },
        busy: { emoji: '🚕', bg: '#fef3c7', color: '#92400e' },
        maintenance: { emoji: '🔧', bg: '#fee2e2', color: '#991b1b' },
        charging: { emoji: '⚡', bg: '#dbeafe', color: '#1e40af' },
        offline: { emoji: '🚗', bg: '#f1f5f9', color: '#64748b', opacity: 0.5 }
    };
    
    // Geofence color palette
    var geofenceColors = [
        { fill: '#3b82f6', border: '#1d4ed8' },
        { fill: '#ef4444', border: '#b91c1c' },
        { fill: '#22c55e', border: '#15803d' },
        { fill: '#f59e0b', border: '#d97706' },
        { fill: '#8b5cf6', border: '#6d28d9' },
        { fill: '#ec4899', border: '#be185d' }
    ];
    
    // Create custom icon for AV
    function createAVIcon(status) {
        var config = statusConfig[status] || statusConfig.offline;
        var opacityStyle = config.opacity ? 'opacity:' + config.opacity + ';' : '';
        
        return L.divIcon({
            html: '<div class="av-marker-icon" style="background:' + config.bg + ';' + opacityStyle + '">' + config.emoji + '</div>',
            className: 'av-marker av-marker-' + status,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });
    }
    
    // Initialize map
    function initMap() {
        map = L.map('av-map', {
            center: CYPRUS_CENTER,
            zoom: 9,
            minZoom: 7,
            maxZoom: 18
        });
        
        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Load vehicles
        loadVehicles();
        
        // Load geofences
        loadGeofences();
        
        // Set up filter handlers
        document.getElementById('filter-available').addEventListener('change', applyFilters);
        document.getElementById('filter-busy').addEventListener('change', applyFilters);
        document.getElementById('filter-charging').addEventListener('change', applyFilters);
        document.getElementById('filter-maintenance').addEventListener('change', applyFilters);
        document.getElementById('filter-offline').addEventListener('change', applyFilters);
        document.getElementById('btn-refresh').addEventListener('click', loadVehicles);
        document.getElementById('btn-toggle-zones').addEventListener('click', toggleGeofences);
        
        // Auto-refresh every 30 seconds
        setInterval(loadVehicles, 30000);
    }
    
    // Load geofences from API
    function loadGeofences() {
        fetch('../api/get_geofences.php')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    console.warn('Failed to load geofences:', data.error);
                    return;
                }

                data.geofences.forEach(function(geofence, index) {
                    if (geofence.points.length < 3) return;

                    var color = geofenceColors[index % geofenceColors.length];
                    var latlngs = geofence.points.map(function(p) { return [p.lat, p.lng]; });
                    latlngs.push(latlngs[0]);

                    var polygon = L.polygon(latlngs, {
                        color: color.border,
                        fillColor: color.fill,
                        fillOpacity: 0.12,
                        weight: 2,
                        dashArray: '5, 5',
                        interactive: false
                    }).addTo(map);
                    
                    var center = polygon.getBounds().getCenter();
                    var label = L.marker(center, {
                        icon: L.divIcon({
                            className: 'geofence-label',
                            html: '<div style="background: ' + color.fill + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3); pointer-events: none;">' + 
                                  geofence.name.replace('_Region', '') + '</div>',
                            iconSize: null,
                            iconAnchor: [35, 10]
                        }),
                        interactive: false
                    }).addTo(map);

                    geofenceLayers.push(polygon);
                    geofenceLayers.push(label);
                });

                data.bridges.forEach(function(bridge) {
                    var bridgeIcon = L.divIcon({
                        className: 'bridge-marker',
                        html: '<div style="background: #fbbf24; border: 2px solid #d97706; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">🔗</div>',
                        iconSize: [20, 20],
                        iconAnchor: [10, 10]
                    });

                    var marker = L.marker([bridge.lat, bridge.lng], { icon: bridgeIcon }).addTo(map);
                    marker.bindPopup('<strong>🔗 ' + bridge.name.replace(/_/g, ' ') + '</strong><br><span style="color: #666; font-size: 0.85em;">' + bridge.connects + '</span>');
                    geofenceLayers.push(marker);
                });

            })
            .catch(function(err) { console.warn('Error loading geofences:', err); });
    }
    
    // Toggle geofence visibility
    function toggleGeofences() {
        geofencesVisible = !geofencesVisible;
        geofenceLayers.forEach(function(layer) {
            if (geofencesVisible) {
                map.addLayer(layer);
            } else {
                map.removeLayer(layer);
            }
        });
        var btn = document.getElementById('btn-toggle-zones');
        btn.textContent = geofencesVisible ? '🗺️ Hide Zones' : '🗺️ Show Zones';
        btn.style.background = geofencesVisible ? '#2563eb' : '#6b7280';
    }
    
    // Load vehicles from API
    function loadVehicles() {
        showLoading(true);
        
        fetch('../api/get_autonomous_vehicles.php')
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load vehicles');
                }
                
                // Transform data for map use
                allVehicles = data.vehicles.map(function(v) {
                    return {
                        id: v.AutonomousVehicleID,
                        code: v.VehicleCode,
                        lat: v.CurrentLatitude,
                        lng: v.CurrentLongitude,
                        status: (v.Status || 'offline').toLowerCase(),
                        battery: v.BatteryLevel,
                        make: v.Make,
                        model: v.Model,
                        color: v.Color,
                        plate: v.PlateNo,
                        seats: v.SeatingCapacity,
                        geofence: v.GeofenceName,
                        isActive: v.IsActive
                    };
                });
                
                // Calculate stats
                var stats = { total: 0, available: 0, busy: 0, maintenance: 0, offline: 0, charging: 0 };
                allVehicles.forEach(function(v) {
                    stats.total++;
                    if (stats.hasOwnProperty(v.status)) {
                        stats[v.status]++;
                    }
                });
                
                // Update stats display
                document.getElementById('stat-total').textContent = stats.total;
                document.getElementById('stat-available').textContent = stats.available;
                document.getElementById('stat-busy').textContent = stats.busy;
                document.getElementById('stat-charging').textContent = stats.charging;
                document.getElementById('stat-maintenance').textContent = stats.maintenance;
                document.getElementById('stat-offline').textContent = stats.offline;
                
                applyFilters();
                showLoading(false);
            })
            .catch(function(error) {
                console.error('Error loading vehicles:', error);
                document.getElementById('stat-total').textContent = 'Error';
                OSRH.error('Failed to load vehicles: ' + error.message, 'Loading Error');
                showLoading(false);
            });
    }
    
    // Apply filters and update markers
    function applyFilters() {
        var showAvailable = document.getElementById('filter-available').checked;
        var showBusy = document.getElementById('filter-busy').checked;
        var showCharging = document.getElementById('filter-charging').checked;
        var showMaintenance = document.getElementById('filter-maintenance').checked;
        var showOffline = document.getElementById('filter-offline').checked;
        
        // Clear existing markers
        markers.forEach(function(marker) {
            map.removeLayer(marker);
        });
        markers = [];
        
        // Filter vehicles
        var filtered = allVehicles.filter(function(v) {
            if (v.lat === null || v.lng === null) return false;
            if (v.status === 'available' && !showAvailable) return false;
            if (v.status === 'busy' && !showBusy) return false;
            if (v.status === 'charging' && !showCharging) return false;
            if (v.status === 'maintenance' && !showMaintenance) return false;
            if (v.status === 'offline' && !showOffline) return false;
            return true;
        });
        
        // Create markers
        filtered.forEach(function(vehicle) {
            var icon = createAVIcon(vehicle.status);
            var marker = L.marker([vehicle.lat, vehicle.lng], { icon: icon });
            
            var popupContent = createPopupContent(vehicle);
            marker.bindPopup(popupContent, { maxWidth: 280 });
            
            marker.addTo(map);
            markers.push(marker);
        });
        
        // Update visible count
        document.getElementById('stat-visible').textContent = markers.length;
    }
    
    // Create popup content
    function createPopupContent(vehicle) {
        var statusClass = vehicle.status;
        var statusText = vehicle.status.charAt(0).toUpperCase() + vehicle.status.slice(1);
        if (vehicle.status === 'busy') statusText = 'On Ride';
        
        // Battery bar
        var batteryColor = '#22c55e';
        if (vehicle.battery !== null) {
            if (vehicle.battery < 20) batteryColor = '#ef4444';
            else if (vehicle.battery < 50) batteryColor = '#f59e0b';
        }
        
        var batteryHtml = vehicle.battery !== null ? 
            '<div class="detail">🔋 Battery: ' + vehicle.battery + '%</div>' +
            '<div class="battery-bar"><div class="battery-fill" style="width:' + vehicle.battery + '%;background:' + batteryColor + ';"></div></div>' : '';
        
        var vehicleInfo = '<div class="vehicle-info">' +
            '<strong>🚘 ' + vehicle.make + ' ' + vehicle.model + '</strong><br>' +
            'Color: ' + vehicle.color + '<br>' +
            'Plate: ' + vehicle.plate + '<br>' +
            'Seats: ' + vehicle.seats + ' passengers' +
            '</div>';
        
        var detailUrl = '<?php echo url("operator/autonomous_vehicle_detail.php?id="); ?>' + vehicle.id;
        
        return '<div class="av-popup">' +
            '<h4>🤖 ' + vehicle.code + '</h4>' +
            '<span class="status ' + statusClass + '">' + statusText + '</span>' +
            '<div class="detail">Vehicle ID: ' + vehicle.id + '</div>' +
            '<div class="detail">📍 Zone: ' + (vehicle.geofence || 'Not assigned') + '</div>' +
            batteryHtml +
            vehicleInfo +
            '<a href="' + detailUrl + '" class="view-btn">View Details →</a>' +
            '</div>';
    }
    
    // Show/hide loading overlay
    function showLoading(show) {
        document.getElementById('loading-overlay').style.display = show ? 'flex' : 'none';
    }
    
    // Start the map
    initMap();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
