<?php
/**
 * Segment Detail Page - Shows segment route on map for real drivers
 * 
 * Displays the OSRM route for a specific segment of a multi-vehicle journey
 * so drivers can see exactly where they need to pick up and drop off.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

// Get driver info from session (same pattern as trips_assigned.php)
$user = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    flash_add('error', 'Driver profile not found.');
    redirect('driver/trips_assigned.php');
}

$driverId = (int)$driverRow['DriverID'];

// Get segment ID from URL
$segmentId = isset($_GET['segment_id']) ? (int)$_GET['segment_id'] : 0;

if (!$segmentId) {
    flash_add('error', 'Invalid segment ID.');
    redirect('driver/trips_assigned.php');
}

// Fetch segment details using stored procedure
$stmt = db_call_procedure('dbo.spGetSegmentDetails', [$segmentId]);

if ($stmt === false) {
    flash_add('error', 'Failed to load segment details.');
    redirect('driver/trips_assigned.php');
}

$segment = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$segment) {
    flash_add('error', 'Segment not found.');
    redirect('driver/trips_assigned.php');
}

// Check if segment is already assigned
$isAssigned = !empty($segment['TripID']);

$driverEarnings = round((float)$segment['EstimatedFare'], 2);

$pageTitle = 'Segment ' . $segment['SegmentOrder'] . ' of ' . $segment['TotalSegments'] . ' - Route Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding: 1rem;">
    <div style="margin-bottom: 1rem;">
        <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost btn-small">
            ← Back to Trips
        </a>
    </div>
    
    <div class="card" style="padding: 1.5rem; margin-bottom: 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="margin: 0 0 0.5rem 0; font-size: 1.2rem;">
                    <span style="background: #007bff; color: white; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.85rem; margin-right: 0.5rem;">
                        SEGMENT <?php echo e($segment['SegmentOrder']); ?>
                    </span>
                    of <?php echo e($segment['TotalSegments']); ?> - Multi-Vehicle Journey
                </h2>
                <p class="text-muted" style="margin: 0; font-size: 0.9rem;">
                    Service Area: <?php echo e($segment['GeofenceName'] ?? 'Unknown'); ?>
                </p>
            </div>
            
            <?php if ($isAssigned): ?>
                <span style="background: rgba(255, 193, 7, 0.2); color: #ffc107; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600;">
                    Already Assigned
                </span>
            <?php else: ?>
                <form method="post" action="<?php echo e(url('driver/accept_segment.php')); ?>">
                    <input type="hidden" name="segment_id" value="<?php echo e($segment['SegmentID']); ?>">
                    <button type="submit" class="btn btn-primary">
                        Accept This Segment
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Route Info -->
    <div class="card" style="padding: 1.5rem; margin-bottom: 1rem;">
        <h3 style="font-size: 1rem; margin: 0 0 1rem 0;">Your Segment Route</h3>
        
        <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; margin-bottom: 1rem;">
            <div style="padding: 1rem; background: rgba(76, 175, 80, 0.1); border-radius: 8px; border-left: 4px solid #4caf50;">
                <div class="text-muted" style="font-size: 0.75rem; margin-bottom: 0.3rem;">PICKUP POINT</div>
                <div style="font-weight: 600; font-size: 0.95rem;">
                    <?php echo e($segment['FromDescription'] ?? $segment['FromAddress'] ?? 'Transfer Point'); ?>
                </div>
                <div style="font-size: 0.8rem; color: #6b7280; margin-top: 0.3rem;">
                    <?php echo number_format((float)$segment['FromLat'], 5); ?>, <?php echo number_format((float)$segment['FromLng'], 5); ?>
                </div>
            </div>
            
            <div style="text-align: center;">
                <div style="font-size: 1.5rem;">→</div>
                <div style="font-size: 0.85rem; font-weight: 600;"><?php echo number_format((float)$segment['EstimatedDistanceKm'], 1); ?> km</div>
                <div style="font-size: 0.75rem; color: #6b7280;">~<?php echo e($segment['EstimatedDurationMin']); ?> min</div>
            </div>
            
            <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border-left: 4px solid #ef4444;">
                <div class="text-muted" style="font-size: 0.75rem; margin-bottom: 0.3rem;">DROPOFF POINT</div>
                <div style="font-weight: 600; font-size: 0.95rem;">
                    <?php echo e($segment['ToDescription'] ?? $segment['ToAddress'] ?? 'Transfer Point'); ?>
                </div>
                <div style="font-size: 0.8rem; color: #6b7280; margin-top: 0.3rem;">
                    <?php echo number_format((float)$segment['ToLat'], 5); ?>, <?php echo number_format((float)$segment['ToLng'], 5); ?>
                </div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem;">
            <div style="text-align: center; padding: 0.8rem; background: rgba(59, 130, 246, 0.1); border-radius: 8px;">
                <div class="text-muted" style="font-size: 0.75rem;">Distance</div>
                <div style="font-weight: 600; font-size: 1.1rem;"><?php echo number_format((float)$segment['EstimatedDistanceKm'], 1); ?> km</div>
            </div>
            <div style="text-align: center; padding: 0.8rem; background: rgba(139, 92, 246, 0.1); border-radius: 8px;">
                <div class="text-muted" style="font-size: 0.75rem;">Est. Duration</div>
                <div style="font-weight: 600; font-size: 1.1rem;">~<?php echo e($segment['EstimatedDurationMin']); ?> min</div>
            </div>
            <div style="text-align: center; padding: 0.8rem; background: rgba(16, 185, 129, 0.1); border-radius: 8px;">
                <div class="text-muted" style="font-size: 0.75rem;">Your Earnings</div>
                <div style="font-weight: 600; font-size: 1.1rem; color: #10b981;">€<?php echo number_format($driverEarnings, 2); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Map -->
    <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
        <h3 style="font-size: 1rem; margin: 0 0 0.5rem 0;">Route Map</h3>
        <p class="text-muted" style="font-size: 0.8rem; margin-bottom: 0.8rem;">
            The blue route shows your segment path from pickup to dropoff using OSRM road routing.
        </p>
        <div id="segment-map" style="height: 400px; border-radius: 8px;"></div>
    </div>
    
    <!-- Passenger Info -->
    <div class="card" style="padding: 1.5rem; margin-bottom: 1rem;">
        <h3 style="font-size: 1rem; margin: 0 0 1rem 0;">Passenger Information</h3>
        
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
            <div>
                <div class="text-muted" style="font-size: 0.75rem;">Passenger Name</div>
                <div style="font-weight: 600;"><?php echo e($segment['PassengerName']); ?></div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 0.75rem;">Phone</div>
                <div style="font-weight: 600;"><?php echo e($segment['PassengerPhone'] ?? 'Not provided'); ?></div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 0.75rem;">Service Type</div>
                <div style="font-weight: 600;"><?php echo e($segment['ServiceTypeName'] ?? 'Standard'); ?></div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 0.75rem;">Full Journey</div>
                <div style="font-size: 0.85rem;">
                    <?php echo e($segment['OriginalPickup']); ?> → <?php echo e($segment['OriginalDropoff']); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Journey Context -->
    <div class="card" style="padding: 1.5rem; background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.2);">
        <h3 style="font-size: 1rem; margin: 0 0 0.5rem 0; color: #8b5cf6;">
            ℹ️ Multi-Vehicle Journey Context
        </h3>
        <p style="font-size: 0.85rem; margin: 0; color: #9ca3af;">
            This is segment <?php echo e($segment['SegmentOrder']); ?> of <?php echo e($segment['TotalSegments']); ?> in a multi-vehicle journey.
            <?php if ($segment['SegmentOrder'] == 1): ?>
                You will pick up the passenger at their original pickup location.
            <?php else: ?>
                The passenger will be dropped off by the previous driver at the pickup point.
            <?php endif; ?>
            <?php if ($segment['SegmentOrder'] < $segment['TotalSegments']): ?>
                After you complete this segment, another driver will continue the journey.
            <?php else: ?>
                This is the final segment - you will drop the passenger at their destination.
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Leaflet CSS/JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fromLat = <?php echo json_encode((float)$segment['FromLat']); ?>;
    var fromLng = <?php echo json_encode((float)$segment['FromLng']); ?>;
    var toLat = <?php echo json_encode((float)$segment['ToLat']); ?>;
    var toLng = <?php echo json_encode((float)$segment['ToLng']); ?>;
    
    // Initialize map
    var map = L.map('segment-map').setView([(fromLat + toLat) / 2, (fromLng + toLng) / 2], 12);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    
    // Pickup marker (green)
    var pickupIcon = L.divIcon({
        html: '<div style="background: #22c55e; color: white; padding: 8px 12px; border-radius: 8px; font-weight: bold; font-size: 12px; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">P</div>',
        className: 'custom-marker',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });
    L.marker([fromLat, fromLng], {icon: pickupIcon}).addTo(map)
        .bindPopup('<strong>Pickup Point</strong><br><?php echo e(addslashes($segment['FromDescription'] ?? 'Transfer Point')); ?>');
    
    // Dropoff marker (red)
    var dropoffIcon = L.divIcon({
        html: '<div style="background: #ef4444; color: white; padding: 8px 12px; border-radius: 8px; font-weight: bold; font-size: 12px; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">D</div>',
        className: 'custom-marker',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });
    L.marker([toLat, toLng], {icon: dropoffIcon}).addTo(map)
        .bindPopup('<strong>Dropoff Point</strong><br><?php echo e(addslashes($segment['ToDescription'] ?? 'Transfer Point')); ?>');
    
    // Fetch OSRM route
    var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
        fromLng + ',' + fromLat + ';' + toLng + ',' + toLat + 
        '?overview=full&geometries=geojson';
    
    fetch(osrmUrl)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.code === 'Ok' && data.routes && data.routes[0]) {
                var routeCoords = data.routes[0].geometry.coordinates.map(function(coord) {
                    return [coord[1], coord[0]]; // [lat, lng]
                });
                
                var routeLine = L.polyline(routeCoords, {
                    color: '#3b82f6',
                    weight: 5,
                    opacity: 0.8
                }).addTo(map);
                
                // Fit bounds to show entire route
                map.fitBounds(routeLine.getBounds().pad(0.1));
            } else {
                // Fallback: straight line
                var straightLine = L.polyline([[fromLat, fromLng], [toLat, toLng]], {
                    color: '#3b82f6',
                    weight: 4,
                    opacity: 0.7,
                    dashArray: '10, 10'
                }).addTo(map);
                map.fitBounds(straightLine.getBounds().pad(0.2));
            }
        })
        .catch(function(err) {
            console.warn('OSRM error:', err);
            // Fallback: straight line
            var straightLine = L.polyline([[fromLat, fromLng], [toLat, toLng]], {
                color: '#3b82f6',
                weight: 4,
                opacity: 0.7,
                dashArray: '10, 10'
            }).addTo(map);
            map.fitBounds(straightLine.getBounds().pad(0.2));
        });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
