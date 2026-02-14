<?php
/**
 * Driver Map View - Shows all simulated drivers on a Leaflet map
 * 
 * Drivers are displayed as car emojis:
 * 🚗 - Available drivers (green tint)
 * 🚙 - Unavailable drivers (gray tint)
 * 
 * Only operators can access this page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/roles.php';

// require_role('operator');  // Uncomment for production

$pageTitle = 'Driver Map';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .driver-map-container {
        position: relative;
        height: calc(100vh - 180px);
        min-height: 500px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    #driver-map {
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
        min-width: 200px;
    }
    
    .map-controls h4 {
        margin: 0 0 10px 0;
        font-size: 0.9rem;
        color: #333;
        border-bottom: 1px solid #eee;
        padding-bottom: 8px;
    }
    
    .stats-row {
        display: flex;
        justify-content: space-between;
        margin: 6px 0;
        font-size: 0.85rem;
    }
    
    .stats-row .label {
        color: #666;
    }
    
    .stats-row .value {
        font-weight: 600;
    }
    
    .stats-row.available .value { color: #22c55e; }
    .stats-row.unavailable .value { color: #94a3b8; }
    .stats-row.total .value { color: #3b82f6; }
    
    .filter-group {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid #eee;
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
        border-top: 1px solid #eee;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        margin: 4px 0;
    }
    
    .legend-icon {
        font-size: 1.2rem;
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
    
    /* Driver popup styling */
    .driver-popup {
        min-width: 180px;
    }
    
    .driver-popup h4 {
        margin: 0 0 8px 0;
        font-size: 0.95rem;
    }
    
    .driver-popup .status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .driver-popup .status.available {
        background: #dcfce7;
        color: #166534;
    }
    
    .driver-popup .status.unavailable {
        background: #f1f5f9;
        color: #64748b;
    }
    
    .driver-popup .detail {
        font-size: 0.8rem;
        color: #666;
        margin: 4px 0;
    }
    
    .driver-popup .vehicle-info {
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #eee;
        font-size: 0.8rem;
    }
    
    /* Custom marker styles */
    .driver-marker {
        background: none;
        border: none;
    }
    
    .driver-marker-available {
        filter: hue-rotate(100deg) saturate(1.5);  /* Green tint */
    }
    
    .driver-marker-unavailable {
        filter: grayscale(0.7) opacity(0.7);  /* Gray and faded */
    }
</style>

    <div class="card" style="max-width: 1400px; margin: 1rem auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">🗺️ Driver Map</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                View all drivers across Cyprus (simulated and real). Click on a driver to see details.
            </p>
        </div>
    </div>    <div class="driver-map-container">
        <div id="driver-map"></div>
        
        <div class="map-controls">
            <h4>📊 Driver Statistics</h4>
            
            <div class="stats-row total">
                <span class="label">Total Drivers:</span>
                <span class="value" id="stat-total">Loading...</span>
            </div>
            <div class="stats-row available">
                <span class="label">Available:</span>
                <span class="value" id="stat-available">-</span>
            </div>
            <div class="stats-row unavailable">
                <span class="label">Unavailable:</span>
                <span class="value" id="stat-unavailable">-</span>
            </div>
            <div class="stats-row">
                <span class="label">Visible on map:</span>
                <span class="value" id="stat-visible">-</span>
            </div>
            
            <div class="filter-group">
                <strong style="font-size: 0.85rem;">Status:</strong>
                <label>
                    <input type="checkbox" id="filter-available" checked>
                    Show available
                </label>
                <label>
                    <input type="checkbox" id="filter-unavailable" checked>
                    Show unavailable
                </label>
            </div>
            
            <div class="filter-group">
                <strong style="font-size: 0.85rem;">Driver Type:</strong>
                <label>
                    <input type="checkbox" id="filter-simulated" checked>
                    Simulated drivers
                </label>
                <label>
                    <input type="checkbox" id="filter-real" checked>
                    Real drivers
                </label>
            </div>
            
            <div class="legend">
                <strong style="font-size: 0.85rem;">Legend:</strong>
                <div class="legend-item">
                    <span class="legend-icon">🚗</span>
                    <span>Available (simulated)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon" style="filter: grayscale(0.7) opacity(0.7);">🚙</span>
                    <span>Unavailable (simulated)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon">🚕</span>
                    <span>Available (real)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon" style="filter: grayscale(0.7) opacity(0.7);">🚖</span>
                    <span>Unavailable (real)</span>
                </div>
                <div class="legend-item">
                    <span class="legend-icon">🔗</span>
                    <span>Transfer point (bridge)</span>
                </div>
            </div>
            
            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #eee;">
                <button id="btn-toggle-zones" class="btn btn-sm" style="width: 100%; margin-bottom: 6px; background: #2563eb; color: white;">
                    🗺️ Hide Zones
                </button>
                <button id="btn-refresh" class="btn btn-sm btn-secondary" style="width: 100%;">
                    🔄 Refresh Drivers
                </button>
            </div>
        </div>
        
        <div class="loading-overlay" id="loading-overlay">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p style="margin-top: 10px; color: #666;">Loading drivers...</p>
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
    var allDrivers = [];
    var geofenceLayers = [];
    var geofencesVisible = true;
    
    // Geofence color palette
    var geofenceColors = [
        { fill: '#3b82f6', border: '#1d4ed8' },  // Blue - Nicosia
        { fill: '#ef4444', border: '#b91c1c' },  // Red - Limassol
        { fill: '#22c55e', border: '#15803d' },  // Green - Larnaca
        { fill: '#f59e0b', border: '#d97706' },  // Orange - Paphos
        { fill: '#8b5cf6', border: '#6d28d9' },  // Purple - Famagusta
        { fill: '#ec4899', border: '#be185d' }   // Pink - Kyrenia
    ];
    
    // Custom icon using emoji
    function createDriverIcon(available, isSimulated) {
        var emoji;
        if (isSimulated) {
            emoji = available ? '🚗' : '🚙';
        } else {
            emoji = available ? '🚕' : '🚖';
        }
        var className = available ? 'driver-marker driver-marker-available' : 'driver-marker driver-marker-unavailable';
        
        return L.divIcon({
            html: '<div style="font-size: 24px; text-align: center;">' + emoji + '</div>',
            className: className,
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -15]
        });
    }
    
    // Initialize map
    function initMap() {
        map = L.map('driver-map', {
            center: CYPRUS_CENTER,
            zoom: 9,
            minZoom: 7,
            maxZoom: 18
        });
        
        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Load drivers
        loadDrivers();
        
        // Load geofences
        loadGeofences();
        
        // Set up filter handlers
        document.getElementById('filter-available').addEventListener('change', applyFilters);
        document.getElementById('filter-unavailable').addEventListener('change', applyFilters);
        document.getElementById('filter-simulated').addEventListener('change', applyFilters);
        document.getElementById('filter-real').addEventListener('change', applyFilters);
        document.getElementById('btn-refresh').addEventListener('click', loadDrivers);
        document.getElementById('btn-toggle-zones').addEventListener('click', toggleGeofences);
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

                // Draw geofence polygons
                data.geofences.forEach(function(geofence, index) {
                    if (geofence.points.length < 3) return;

                    var color = geofenceColors[index % geofenceColors.length];
                    var latlngs = geofence.points.map(function(p) { return [p.lat, p.lng]; });
                    latlngs.push(latlngs[0]); // Close polygon

                    var polygon = L.polygon(latlngs, {
                        color: color.border,
                        fillColor: color.fill,
                        fillOpacity: 0.12,
                        weight: 2,
                        dashArray: '5, 5',
                        interactive: false  // Allow clicks to pass through
                    }).addTo(map);
                    
                    // Add center label
                    var center = polygon.getBounds().getCenter();
                    var label = L.marker(center, {
                        icon: L.divIcon({
                            className: 'geofence-label',
                            html: '<div style="background: ' + color.fill + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3); pointer-events: none;">' + 
                                  geofence.name.replace('_Region', '') + '</div>',
                            iconSize: null,
                            iconAnchor: [35, 10]
                        }),
                        interactive: false  // Allow clicks to pass through
                    }).addTo(map);

                    geofenceLayers.push(polygon);
                    geofenceLayers.push(label);
                });

                // Draw bridge markers
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

                console.log('Loaded', data.count.geofences, 'geofences and', data.count.bridges, 'bridges');
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
    
    // Load drivers from API
    function loadDrivers() {
        showLoading(true);
        
        // Fetch all drivers (simulated=0 means all)
        fetch('../api/get_all_drivers.php?simulated=0')
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load drivers');
                }
                
                allDrivers = data.drivers;
                
                // Update stats
                if (data.stats) {
                    document.getElementById('stat-total').textContent = data.stats.total.toLocaleString();
                    document.getElementById('stat-available').textContent = data.stats.available.toLocaleString();
                    document.getElementById('stat-unavailable').textContent = data.stats.unavailable.toLocaleString();
                }
                
                // Apply filters and display markers
                applyFilters();
                showLoading(false);
            })
            .catch(function(error) {
                console.error('Error loading drivers:', error);
                document.getElementById('stat-total').textContent = 'Error';
                OSRH.error('Failed to load drivers: ' + error.message, 'Loading Error');
                showLoading(false);
            });
    }
    
    // Apply filters and update markers
    function applyFilters() {
        var showAvailable = document.getElementById('filter-available').checked;
        var showUnavailable = document.getElementById('filter-unavailable').checked;
        var showSimulated = document.getElementById('filter-simulated').checked;
        var showReal = document.getElementById('filter-real').checked;
        
        // Clear existing markers
        markers.forEach(function(marker) {
            map.removeLayer(marker);
        });
        markers = [];
        
        // Filter drivers
        var filtered = allDrivers.filter(function(d) {
            if (d.available && !showAvailable) return false;
            if (!d.available && !showUnavailable) return false;
            if (d.simulated && !showSimulated) return false;
            if (!d.simulated && !showReal) return false;
            return true;
        });
        
        // Create markers (limit to first 1000 for performance without clustering)
        var toShow = filtered.slice(0, 1000);
        
        toShow.forEach(function(driver) {
            var icon = createDriverIcon(driver.available, driver.simulated);
            var marker = L.marker([driver.lat, driver.lng], { icon: icon });
            
            // Create popup content
            var popupContent = createPopupContent(driver);
            marker.bindPopup(popupContent, { maxWidth: 250 });
            
            marker.addTo(map);
            markers.push(marker);
        });
        
        // Update visible count
        document.getElementById('stat-visible').textContent = markers.length.toLocaleString();
        
        if (filtered.length > 1000) {
            console.log('Showing first 1000 of ' + filtered.length + ' drivers');
        }
    }
    
    // Create popup content
    function createPopupContent(driver) {
        var statusClass = driver.available ? 'available' : 'unavailable';
        var statusText = driver.available ? 'Available' : 'Unavailable';
        var driverTypeText = driver.simulated ? '🤖 Simulated' : '👤 Real';
        
        var vehicleHtml = '';
        if (driver.vehicle) {
            vehicleHtml = '<div class="vehicle-info">' +
                '<strong>🚘 Vehicle:</strong><br>' +
                driver.vehicle.make + ' ' + driver.vehicle.model + '<br>' +
                'Color: ' + driver.vehicle.color + '<br>' +
                'Plate: ' + driver.vehicle.plate + '<br>' +
                'Type: ' + (driver.vehicle.type || 'Standard') +
                '</div>';
        }
        
        var ratingHtml = driver.rating ? '⭐ ' + driver.rating.toFixed(1) : 'No rating';
        
        return '<div class="driver-popup">' +
            '<h4>' + driver.name + '</h4>' +
            '<span class="status ' + statusClass + '">' + statusText + '</span>' +
            ' <span style="font-size: 0.75rem; color: #666;">' + driverTypeText + '</span>' +
            '<div class="detail">Driver ID: ' + driver.id + '</div>' +
            '<div class="detail">' + ratingHtml + '</div>' +
            '<div class="detail">📍 ' + driver.lat.toFixed(4) + ', ' + driver.lng.toFixed(4) + '</div>' +
            vehicleHtml +
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
