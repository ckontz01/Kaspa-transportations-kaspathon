// assets/js/map.js
(function () {
    'use strict';

    window.OSRH = window.OSRH || {};

    // Custom marker icons
    var markerIcons = {
        pickup: null,
        dropoff: null,
        driver: null,
        passenger: null
    };

    /**
     * Create custom colored marker icons
     */
    function createMarkerIcon(color, label) {
        if (typeof L === 'undefined') return null;
        
        return L.divIcon({
            className: 'osrh-marker',
            html: '<div style="background-color: ' + color + '; width: 30px; height: 30px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><span style="display: block; transform: rotate(45deg); text-align: center; line-height: 24px; color: white; font-weight: bold; font-size: 12px;">' + label + '</span></div>',
            iconSize: [30, 30],
            iconAnchor: [15, 30],
            popupAnchor: [0, -30]
        });
    }

    /**
     * Initialize marker icons
     */
    function initMarkerIcons() {
        if (markerIcons.pickup) return; // Already initialized
        
        markerIcons.pickup = createMarkerIcon('#22c55e', 'P');    // Green for pickup
        markerIcons.dropoff = createMarkerIcon('#ef4444', 'D');   // Red for dropoff
        markerIcons.driver = createMarkerIcon('#3b82f6', '🚗');   // Blue for driver
        markerIcons.passenger = createMarkerIcon('#f59e0b', '👤'); // Orange for passenger
    }

    /**
     * Initialize a Leaflet map.
     * @param {string|HTMLElement} containerId
     * @param {{lat?: number, lng?: number, zoom?: number}} options
     * @returns {L.Map|null}
     */
    window.OSRH.initMap = function (containerId, options) {
        if (typeof L === 'undefined') {
            console.warn('Leaflet (L) is not loaded.');
            return null;
        }

        initMarkerIcons();

        var el = containerId;
        if (typeof containerId === 'string') {
            el = document.getElementById(containerId);
        }

        if (!el) {
            console.warn('Map container not found: ' + containerId);
            return null;
        }

        options = options || {};
        var lat = typeof options.lat === 'number' ? options.lat : 35.1667;   // Nicosia-ish
        var lng = typeof options.lng === 'number' ? options.lng : 33.3667;
        var zoom = typeof options.zoom === 'number' ? options.zoom : 13;

        var map = L.map(el).setView([lat, lng], zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Store route layers for easy removal
        map._osrhRouteLayers = [];

        return map;
    };

    /**
     * Fetch and draw geofences on the map.
     * @param {L.Map} map - Leaflet map instance
     */
    window.OSRH.drawGeofences = function (map) {
        if (!map || typeof L === 'undefined') return;

        // Color palette for geofences (distinct colors)
        var geofenceColors = [
            { fill: '#3b82f6', border: '#1d4ed8', name: 'blue' },      // Nicosia
            { fill: '#ef4444', border: '#b91c1c', name: 'red' },       // Limassol
            { fill: '#22c55e', border: '#15803d', name: 'green' },     // Larnaca
            { fill: '#f59e0b', border: '#d97706', name: 'orange' },    // Paphos
            { fill: '#8b5cf6', border: '#6d28d9', name: 'purple' },    // Famagusta
            { fill: '#ec4899', border: '#be185d', name: 'pink' }       // Kyrenia
        ];

        var url = (window.OSRH.baseURL || '') + 'api/get_geofences.php';

        fetch(url)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(function(data) {
                if (data && data.geofences) {
                    data.geofences.forEach(function (geofence, index) {
                        if (geofence.points && geofence.points.length > 2) {
                            var color = geofenceColors[index % geofenceColors.length];
                            var latLngs = geofence.points.map(function (p) {
                                return [p.lat, p.lng];
                            });

                            // Close the polygon
                            latLngs.push(latLngs[0]);

                            var polygon = L.polygon(latLngs, {
                                color: color.border,
                                fillColor: color.fill,
                                fillOpacity: 0.15,
                                weight: 2,
                                dashArray: '5, 5',
                                interactive: false
                            }).addTo(map);

                            // Add label in center of polygon
                            var bounds = polygon.getBounds();
                            var center = bounds.getCenter();
                            
                            L.marker(center, {
                                icon: L.divIcon({
                                    className: 'geofence-label',
                                    html: '<div style="background: ' + color.fill + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3); pointer-events: none;">' + 
                                          geofence.name.replace('_Region', '').replace('_', ' ') + '</div>',
                                    iconSize: null,
                                    iconAnchor: [40, 10]
                                }),
                                interactive: false
                            }).addTo(map);
                        }
                    });
                }

                if (data && data.bridges) {
                    data.bridges.forEach(function(bridge) {
                        var bridgeIcon = L.divIcon({
                            className: 'bridge-marker',
                            html: '<div style="background: #fbbf24; border: 2px solid #d97706; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">🔗</div>',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        });

                        var marker = L.marker([bridge.lat, bridge.lng], { icon: bridgeIcon }).addTo(map);
                        
                        var popupContent = '<div style="text-align: center; min-width: 150px;">' +
                            '<strong>🔗 Transfer Point</strong><br>' +
                            '<span style="color: #666; font-size: 0.85em;">' + bridge.name.replace(/_/g, ' ') + '</span><br>' +
                            '<span style="color: #3b82f6; font-size: 0.8em;">' + bridge.connects + '</span>' +
                            '</div>';
                        marker.bindPopup(popupContent);
                    });
                }
            })
            .catch(function(error) {
                console.error('Error fetching or drawing geofences:', error);
            });
    };

    /**
     * Add a marker with custom icon
     * @param {L.Map} map - Leaflet map instance
     * @param {number} lat - Latitude
     * @param {number} lng - Longitude
     * @param {string} type - 'pickup', 'dropoff', 'driver', 'passenger'
     * @param {string} popupText - Text for popup
     * @returns {L.Marker}
     */
    window.OSRH.addMarker = function (map, lat, lng, type, popupText) {
        if (!map || typeof L === 'undefined') return null;
        
        initMarkerIcons();
        
        var icon = markerIcons[type] || null;
        var markerOptions = icon ? { icon: icon } : {};
        
        var marker = L.marker([lat, lng], markerOptions).addTo(map);
        
        if (popupText) {
            marker.bindPopup(popupText);
        }
        
        return marker;
    };

    /**
     * Fetch and display route between two points using OSRM
     * @param {L.Map} map - Leaflet map instance
     * @param {Object} from - {lat, lng} origin
     * @param {Object} to - {lat, lng} destination
     * @param {Object} options - {color, weight, opacity, showDistance, showDuration}
     * @returns {Promise} - Resolves with route info {distance, duration, polyline}
     */
    window.OSRH.showRoute = function (map, from, to, options) {
        if (!map || typeof L === 'undefined') {
            return Promise.reject('Map not available');
        }

        options = options || {};
        var color = options.color || '#3b82f6';
        var weight = options.weight || 5;
        var opacity = options.opacity || 0.7;

        // OSRM public server (free, no API key needed)
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' +
            from.lng + ',' + from.lat + ';' +
            to.lng + ',' + to.lat +
            '?overview=full&geometries=geojson';

        return fetch(osrmUrl)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Routing service unavailable');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data.routes || data.routes.length === 0) {
                    throw new Error('No route found');
                }

                var route = data.routes[0];
                var coordinates = route.geometry.coordinates;
                
                // Convert [lng, lat] to [lat, lng] for Leaflet
                var latLngs = coordinates.map(function (coord) {
                    return [coord[1], coord[0]];
                });

                // Draw the route polyline
                var polyline = L.polyline(latLngs, {
                    color: color,
                    weight: weight,
                    opacity: opacity,
                    lineJoin: 'round'
                }).addTo(map);

                // Store for later removal
                map._osrhRouteLayers.push(polyline);

                // Calculate distance and duration
                var distanceKm = (route.distance / 1000).toFixed(2);
                var durationMin = Math.round(route.duration / 60);

                return {
                    distance: distanceKm,
                    duration: durationMin,
                    polyline: polyline,
                    rawRoute: route,
                    coordinates: coordinates // [lng, lat] format from OSRM
                };
            })
            .catch(function (error) {
                console.warn('OSRH Route Error:', error.message);
                
                // Fallback: draw a straight line
                var straightLine = L.polyline([
                    [from.lat, from.lng],
                    [to.lat, to.lng]
                ], {
                    color: color,
                    weight: weight,
                    opacity: opacity * 0.5,
                    dashArray: '10, 10'
                }).addTo(map);

                map._osrhRouteLayers.push(straightLine);

                // Estimate distance using Haversine formula
                var R = 6371; // Earth radius in km
                var dLat = (to.lat - from.lat) * Math.PI / 180;
                var dLon = (to.lng - from.lng) * Math.PI / 180;
                var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                        Math.cos(from.lat * Math.PI / 180) * Math.cos(to.lat * Math.PI / 180) *
                        Math.sin(dLon/2) * Math.sin(dLon/2);
                var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                var straightDistance = (R * c).toFixed(2);

                return {
                    distance: straightDistance,
                    duration: Math.round(straightDistance * 2), // ~30 km/h estimate
                    polyline: straightLine,
                    isFallback: true,
                    coordinates: [[from.lng, from.lat], [to.lng, to.lat]] // Fallback straight line
                };
            });
    };

    /**
     * Show multi-segment route (e.g., driver → pickup → dropoff)
     * @param {L.Map} map - Leaflet map instance
     * @param {Array} waypoints - Array of {lat, lng, type, label} objects
     * @param {Object} options - Route display options
     * @returns {Promise} - Resolves with combined route info
     */
    window.OSRH.showMultiRoute = function (map, waypoints, options) {
        if (!map || !waypoints || waypoints.length < 2) {
            return Promise.reject('Invalid waypoints');
        }

        options = options || {};
        var segmentColors = options.segmentColors || ['#f59e0b', '#22c55e', '#3b82f6'];
        var markers = [];
        var routePromises = [];
        var totalDistance = 0;
        var totalDuration = 0;

        // Add markers for each waypoint
        waypoints.forEach(function (wp, index) {
            if (wp.lat && wp.lng) {
                var marker = window.OSRH.addMarker(map, wp.lat, wp.lng, wp.type || 'pickup', wp.label || '');
                markers.push(marker);
            }
        });

        // Create route segments
        for (var i = 0; i < waypoints.length - 1; i++) {
            var from = waypoints[i];
            var to = waypoints[i + 1];
            
            if (from.lat && from.lng && to.lat && to.lng) {
                var segmentColor = segmentColors[i % segmentColors.length];
                routePromises.push(
                    window.OSRH.showRoute(map, from, to, {
                        color: segmentColor,
                        weight: options.weight || 5,
                        opacity: options.opacity || 0.8
                    })
                );
            }
        }

        return Promise.all(routePromises).then(function (segments) {
            segments.forEach(function (seg) {
                totalDistance += parseFloat(seg.distance);
                totalDuration += seg.duration;
            });

            // Fit map to show all markers
            if (markers.length > 0) {
                var group = L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.2));
            }

            return {
                segments: segments,
                totalDistance: totalDistance.toFixed(2),
                totalDuration: totalDuration,
                markers: markers
            };
        });
    };

    /**
     * Clear all route lines from the map
     * @param {L.Map} map - Leaflet map instance
     */
    window.OSRH.clearRoutes = function (map) {
        if (!map || !map._osrhRouteLayers) return;

        map._osrhRouteLayers.forEach(function (layer) {
            map.removeLayer(layer);
        });
        map._osrhRouteLayers = [];
    };

    /**
     * Display route info in a DOM element
     * @param {string|HTMLElement} element - Target element
     * @param {Object} routeInfo - {distance, duration, isFallback}
     */
    window.OSRH.displayRouteInfo = function (element, routeInfo) {
        var el = typeof element === 'string' ? document.getElementById(element) : element;
        if (!el || !routeInfo) return;

        var html = '<div class="route-info" style="background: #0b1120; border: 1px solid #1e293b; border-radius: 8px; padding: 0.75rem; margin-top: 0.5rem;">';
        html += '<div style="display: flex; gap: 1.5rem; font-size: 0.9rem; color: #e5e7eb;">';
        html += '<div><strong>Distance:</strong> ' + routeInfo.distance + ' km</div>';
        html += '<div><strong>Est. Time:</strong> ' + routeInfo.duration + ' min</div>';
        html += '</div>';
        
        if (routeInfo.isFallback) {
            html += '<div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">';
            html += '(Straight-line estimate - routing service unavailable)';
            html += '</div>';
        }
        
        html += '</div>';
        
        el.innerHTML = html;
    };

    /**
     * Show driver location relative to pickup (real-time tracking simulation)
     * @param {L.Map} map - Leaflet map instance
     * @param {Object} driver - {lat, lng}
     * @param {Object} pickup - {lat, lng}
     * @param {Object} dropoff - {lat, lng}
     */
    window.OSRH.showTripProgress = function (map, driver, pickup, dropoff) {
        if (!map) return Promise.reject('Map not available');

        var waypoints = [];

        // Add driver location if available
        if (driver && driver.lat && driver.lng) {
            waypoints.push({
                lat: driver.lat,
                lng: driver.lng,
                type: 'driver',
                label: '<strong>🚗 Driver</strong><br>Current location'
            });
        }

        // Add pickup
        if (pickup && pickup.lat && pickup.lng) {
            waypoints.push({
                lat: pickup.lat,
                lng: pickup.lng,
                type: 'pickup',
                label: '<strong>📍 Pickup</strong><br>' + (pickup.address || 'Pickup location')
            });
        }

        // Add dropoff
        if (dropoff && dropoff.lat && dropoff.lng) {
            waypoints.push({
                lat: dropoff.lat,
                lng: dropoff.lng,
                type: 'dropoff',
                label: '<strong>🏁 Dropoff</strong><br>' + (dropoff.address || 'Destination')
            });
        }

        return window.OSRH.showMultiRoute(map, waypoints, {
            segmentColors: ['#f59e0b', '#22c55e'], // Orange for driver→pickup, green for pickup→dropoff
            weight: 5,
            opacity: 0.8
        });
    };

})();
