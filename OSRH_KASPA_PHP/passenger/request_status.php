<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerId = $user['passenger']['PassengerID'] ?? null;
if (!$passengerId) {
    redirect('error.php?code=403');
}

$rideRequestId = (int)array_get($_GET, 'ride_request_id', 0);
if ($rideRequestId <= 0) {
    flash_add('error', 'Ride request reference is missing. Please submit a new request.');
    redirect('passenger/request_ride.php');
}

// Debug: Check what exists in database
$debugRR = db_fetch_one(
    'SELECT rr.RideRequestID, rr.PassengerID, rr.Status, rr.PickupLocationID
     FROM dbo.RideRequest rr
     WHERE rr.RideRequestID = ?;',
    [$rideRequestId]
);
error_log("DEBUG request_status: rideRequestId=$rideRequestId, passengerId=$passengerId");
error_log("DEBUG request_status: dbRideRequest=" . ($debugRR ? json_encode($debugRR) : 'null'));


$rr = db_fetch_one(
    'SELECT rr.RideRequestID, rr.Status, rr.RequestedAt, rr.PassengerID,
            pl.LatDegrees AS PickupLat, pl.LonDegrees AS PickupLng,
            pl.StreetAddress AS PickupAddress
     FROM dbo.RideRequest rr
     JOIN dbo.Location pl ON pl.LocationID = rr.PickupLocationID
     WHERE rr.RideRequestID = ? AND rr.PassengerID = ?;',
    [$rideRequestId, $passengerId]
);

if (!$rr) {
    // Show debug info in flash message
    if ($debugRR) {
        $dbPassengerId = $debugRR['PassengerID'] ?? 'null';
        if ($dbPassengerId == $passengerId) {
            // IDs match, so JOIN to Location failed for another reason
            $pickupLocationId = $debugRR['PickupLocationID'] ?? null;
            $locationRow = $pickupLocationId ? db_fetch_one('SELECT * FROM dbo.Location WHERE LocationID = ?;', [$pickupLocationId]) : null;
            flash_add('error', "RideRequest #{$rideRequestId} exists and belongs to your account, but the JOIN to Location failed.\nRideRequest: " . json_encode($debugRR) . "\nLocation: " . ($locationRow ? json_encode($locationRow) : 'null'));
            if (!$locationRow) {
                flash_add('error', "PickupLocationID {$pickupLocationId} does not exist in Location table.");
            }
        } else {
            flash_add('error', "RideRequest #{$rideRequestId} exists but belongs to PassengerID {$dbPassengerId}, not your account ({$passengerId}).");
            // Additional debug: check PickupLocationID and Location row
            $pickupLocationId = $debugRR['PickupLocationID'] ?? null;
            if ($pickupLocationId) {
                $locationRow = db_fetch_one('SELECT * FROM dbo.Location WHERE LocationID = ?;', [$pickupLocationId]);
                error_log("DEBUG request_status: PickupLocationID=$pickupLocationId, LocationRow=" . ($locationRow ? json_encode($locationRow) : 'null'));
                flash_add('error', 'Location row for PickupLocationID ' . $pickupLocationId . ': ' . ($locationRow ? json_encode($locationRow) : 'null'));
                if (!$locationRow) {
                    flash_add('error', "PickupLocationID {$pickupLocationId} does not exist in Location table.");
                }
            } else {
                flash_add('error', "RideRequest #{$rideRequestId} has no PickupLocationID.");
            }
            // Show full ride request row
            flash_add('error', 'Full RideRequest row: ' . json_encode($debugRR));
        }
    } else {
        flash_add('error', "RideRequest #{$rideRequestId} does not exist in the database. Please submit a new request.");
    }
    redirect('passenger/request_ride.php');
}

// If a trip already exists, jump straight to live tracking.
$trip = db_fetch_one(
    'SELECT TOP 1 TripID, Status FROM dbo.Trip WHERE RideRequestID = ? ORDER BY TripID DESC;',
    [$rideRequestId]
);
if ($trip) {
    redirect('passenger/ride_detail.php?trip_id=' . (int)$trip['TripID']);
}

$pageTitle = 'Awaiting driver confirmation';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 960px; margin: 2rem auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Awaiting driver confirmation</h1>
            <p class="text-muted" style="font-size: 0.9rem; margin-top: 0.35rem;">
                We are notifying available drivers. Your pickup point is shown on the map below.
            </p>
        </div>
        <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost">Back to rides</a>
    </div>
    <div class="card-body" style="padding: 1rem 1rem 1.25rem;">
        <div id="status-banner" class="alert alert-info" style="margin-bottom: 0.75rem;">
            Awaiting driver confirmation...
        </div>
        <div id="pending-map" class="map-container" style="height: 380px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!window.OSRH || !window.OSRH.initMap) return;

    var rideRequestId = <?php echo json_encode($rideRequestId); ?>;
    var pickupLat = <?php echo json_encode((float)$rr['PickupLat']); ?>;
    var pickupLng = <?php echo json_encode((float)$rr['PickupLng']); ?>;
    var pickupLabel = <?php echo json_encode($rr['PickupAddress'] ?? 'Pickup location'); ?>;

    var map = window.OSRH.initMap('pending-map', {
        lat: pickupLat,
        lng: pickupLng,
        zoom: 14
    });
    if (map) {
        window.OSRH.addMarker(map, pickupLat, pickupLng, 'pickup', '<strong>📍 Pickup</strong><br>' + pickupLabel);
    }

    function pollStatus() {
        fetch('../api/ride_request_status.php?ride_request_id=' + rideRequestId)
            .then(function(res) { return res.json(); })
            .then(function(payload) {
                if (!payload.success) return;
                var data = payload.data || {};
                if (data.status === 'assigned' && data.tripId) {
                    window.location.href = 'ride_detail.php?trip_id=' + data.tripId;
                } else if (data.status === 'cancelled') {
                    var banner = document.getElementById('status-banner');
                    if (banner) {
                        banner.className = 'alert alert-error';
                        banner.textContent = 'This ride request was cancelled.';
                    }
                }
            })
            .catch(function(err) { console.warn('status poll failed', err); });
    }

    pollStatus();
    setInterval(pollStatus, 5000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
