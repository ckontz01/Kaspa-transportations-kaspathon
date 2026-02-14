<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

$requestId = (int)array_get($_GET, 'request_id', 0);
if ($requestId <= 0) {
    redirect('error.php?code=404');
}

$user = current_user();
$driverRow = $user['driver'] ?? null;
if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}
$driverId = (int)$driverRow['DriverID'];

// Direct query to get driver's UseGPS flag (fallback if not in session)
$driverUseGPS = false;
$useGpsRow = db_fetch_one('SELECT UseGPS FROM dbo.Driver WHERE DriverID = ?', [$driverId]);
if ($useGpsRow && !empty($useGpsRow['UseGPS'])) {
    $driverUseGPS = true;
}

// Get ride request details
$stmt = db_call_procedure('dbo.spDriverGetAvailableRideRequests', [$driverId]);
$request = null;
$foundTripId = null;

if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if ((int)$row['RideRequestID'] === $requestId) {
            $request = $row;
            break;
        }
    }
    sqlsrv_free_stmt($stmt);
}

// If not found, check if this request is now a trip for this driver
if (!$request) {
    $tripStmt = db_call_procedure('dbo.spDriverListTrips', [$driverId]);
    if ($tripStmt !== false) {
        while ($row = sqlsrv_fetch_array($tripStmt, SQLSRV_FETCH_ASSOC)) {
            if ((int)$row['RideRequestID'] === $requestId) {
                $foundTripId = (int)$row['TripID'];
                break;
            }
        }
        sqlsrv_free_stmt($tripStmt);
    }
    if ($foundTripId) {
        // Redirect to trip detail page
        redirect('trip_detail.php?trip_id=' . urlencode((string)$foundTripId));
    } else {
        redirect('error.php?code=404');
    }
}

// Get driver's vehicles for the accept form
$vehiclesStmt = db_call_procedure('dbo.spDriverGetVehicles', [$driverId]);
$vehicles = [];
if ($vehiclesStmt !== false) {
    while ($row = sqlsrv_fetch_array($vehiclesStmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($vehiclesStmt);
}

// Handle POST: accept ride request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept_request'])) {
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        flash_add('error', 'Security check failed. Please try again.');
    } else {
        $vehicleId = (int)array_get($_POST, 'vehicle_id', 0);
        
        if ($requestId && $vehicleId) {
            $resultStmt = db_call_procedure('dbo.spDriverAcceptRideRequest', [$driverId, $requestId, $vehicleId]);
            
            if ($resultStmt !== false) {
                $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($resultStmt);
                
                if (!empty($result['Success'])) {
                    $newTripId = $result['TripID'] ?? 0;
                    
                    // If SP didn't return TripID, query for it directly
                    if (!$newTripId) {
                        $tripRow = db_fetch_one(
                            'SELECT TripID FROM dbo.Trip WHERE RideRequestID = ? AND DriverID = ?',
                            [$requestId, $driverId]
                        );
                        if ($tripRow) {
                            $newTripId = (int)$tripRow['TripID'];
                        }
                    }
                    
                    // Check if this is a real driver trip:
                    // 1. From stored procedure result (if SP is updated)
                    // 2. OR from driver's UseGPS flag (direct query - always works)
                    $isRealDriverTrip = !empty($result['IsRealDriverTrip']) || $driverUseGPS;
                    
                    // Always redirect to trip detail page immediately after accepting
                    if ($newTripId) {
                        if ($isRealDriverTrip) {
                            flash_add('success', 'Ride request accepted! Please enable GPS to track your location.');
                            redirect('trip_detail.php?trip_id=' . urlencode((string)$newTripId) . '&enable_gps=1&auto_start=1');
                        } else {
                            flash_add('success', 'Ride request accepted! Trip #' . $newTripId . ' has been created.');
                            redirect('trip_detail.php?trip_id=' . urlencode((string)$newTripId));
                        }
                    } else {
                        // Fallback only if we truly can't find the trip
                        flash_add('success', 'Ride request accepted!');
                        redirect('trips_assigned.php');
                    }
                } else {
                    $errorMsg = $result['ErrorMessage'] ?? 'Failed to accept ride request.';
                    flash_add('error', $errorMsg);
                }
            } else {
                flash_add('error', 'Failed to accept ride request.');
            }
        } else {
            flash_add('error', 'Please select a vehicle.');
        }
    }
}

function osrh_dt_request($v, bool $withTime = true): string
{
    if ($v instanceof DateTimeInterface) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    if ($v instanceof DateTime) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'Ride Request #' . $request['RideRequestID'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                Ride Request #<?php echo e($request['RideRequestID']); ?>
                <span class="badge badge-pill" style="margin-left:0.5rem; background: #ffa726;">Available</span>
            </h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Review the ride details before accepting this request.
            </p>
        </div>
        <div>
            <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost btn-small">
                &larr; Back to trips
            </a>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.2rem;margin-top:1.1rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Ride summary</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <dl class="key-value-list">
                    <dt>Passenger</dt>
                    <dd><?php echo e($request['PassengerName']); ?></dd>

                    <dt>Phone</dt>
                    <dd><?php echo e($request['PassengerPhone'] ?? 'Not provided'); ?></dd>

                    <dt>Requested at</dt>
                    <dd><?php echo e(osrh_dt_request($request['RequestedAt'] ?? null, true)); ?></dd>

                    <dt>Route Distance</dt>
                    <dd><strong data-summary-distance><?php echo e(number_format((float)($request['DistanceKm'] ?? 0), 2)); ?> km</strong></dd>

                    <dt style="color: #49EACB;">Your Earnings (100%)</dt>
                    <dd>
                        <strong data-summary-earnings style="color: var(--color-success); font-size: 1.15em;">
                            €<?php echo e(number_format((float)($request['EstimatedFare'] ?? ($request['EstimatedDriverPayment'] ?? 0)), 2)); ?>
                        </strong>
                        <div style="font-size: 0.72rem; color: #49EACB; margin-top: 0.2rem;">✨ No platform fee!</div>
                    </dd>

                    <dt>Service Type</dt>
                    <dd><?php echo e($request['ServiceType'] ?? 'Standard'); ?></dd>

                    <dt>Payment Method</dt>
                    <dd>
                        <?php
                        $paymentClass = $request['PaymentMethodClass'] ?? 'unknown';
                        $paymentDisplay = $request['PaymentMethodDisplay'] ?? 'Not specified';
                        if ($paymentClass === 'card') {
                            echo '<strong style="color: #2563eb;">' . e($paymentDisplay) . '</strong>';
                        } elseif ($paymentClass === 'cash') {
                            echo '<strong style="color: #16a34a;">' . e($paymentDisplay) . '</strong>';
                        } else {
                            echo '<span class="text-muted">' . e($paymentDisplay) . '</span>';
                        }
                        ?>
                    </dd>
                </dl>

                <h3 style="font-size:0.9rem;margin-top:0.9rem;">Passenger Notes</h3>
                <p class="text-muted" style="font-size:0.84rem;">
                    <?php if (!empty($request['PassengerNotes'])): ?>
                        <?php echo nl2br(e($request['PassengerNotes'])); ?>
                    <?php else: ?>
                        <span>No special notes from passenger.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Locations</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <dl class="key-value-list">
                    <dt>Pickup Location</dt>
                    <dd><?php echo e($request['PickupLocation']); ?></dd>

                    <dt>Pickup Coordinates</dt>
                    <dd>
                        <?php 
                        echo e(number_format((float)($request['PickupLat'] ?? 0), 6) . ', ' . 
                              number_format((float)($request['PickupLng'] ?? 0), 6)); 
                        ?>
                    </dd>

                    <dt>Dropoff Location</dt>
                    <dd><?php echo e($request['DropoffLocation']); ?></dd>

                    <dt>Dropoff Coordinates</dt>
                    <dd>
                        <?php 
                        echo e(number_format((float)($request['DropoffLat'] ?? 0), 6) . ', ' . 
                              number_format((float)($request['DropoffLng'] ?? 0), 6)); 
                        ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Map -->
    <div class="card" style="margin-top:1.3rem;">
        <div class="card-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title" style="font-size:0.95rem;">Route Map</h2>
                <div style="text-align: right;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">Estimated Distance</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-primary);">
                        <?php echo e(number_format((float)($request['DistanceKm'] ?? 0), 2)); ?> km
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding:0;font-size:0.86rem;">
            <div id="request-map" class="map-container" style="height: 400px;"></div>
        </div>
    </div>

    <!-- Accept Form -->
    <div class="card" style="margin-top:1.3rem; background: rgba(76, 175, 80, 0.08); border: 1px solid rgba(76, 175, 80, 0.3);">
        <div class="card-header">
            <h2 class="card-title" style="font-size:0.95rem; color: #2e7d32;">Accept This Ride Request</h2>
        </div>
        <div class="card-body" style="padding:1rem;">
            <?php if (empty($vehicles)): ?>
                <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 4px; margin-bottom: 1rem; border: 1px solid rgba(239, 68, 68, 0.3);">
                    <p style="margin: 0; font-size: 0.86rem; color: #ef4444;">
                        ⚠️ <strong>No vehicles registered.</strong> You need to add a vehicle before you can accept ride requests.
                    </p>
                </div>
                <a href="<?php echo e(url('driver/vehicles.php')); ?>" class="btn btn-primary">
                    Add Vehicle
                </a>
                <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost">
                    Back
                </a>
            <?php else: ?>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="accept_request" value="1">
                
                <div class="form-group">
                    <label class="form-label" for="vehicle_id">Select Vehicle <span style="color: #c53030;">*</span></label>
                    <select name="vehicle_id" id="vehicle_id" class="form-control" required style="max-width: 300px;">
                        <option value="">Choose a vehicle...</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo e($vehicle['VehicleID']); ?>">
                                <?php echo e($vehicle['PlateNo'] ?? ($vehicle['Make'] . ' ' . $vehicle['Model'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        Accept Ride Request
                    </button>
                    <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost">
                        Cancel
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.OSRH || typeof window.OSRH.initMap !== 'function') {
        return;
    }

    var pickupLat = <?php echo json_encode((float)($request['PickupLat'] ?? 0)); ?>;
    var pickupLng = <?php echo json_encode((float)($request['PickupLng'] ?? 0)); ?>;
    var dropoffLat = <?php echo json_encode((float)($request['DropoffLat'] ?? 0)); ?>;
    var dropoffLng = <?php echo json_encode((float)($request['DropoffLng'] ?? 0)); ?>;

    var centerLat = (pickupLat + dropoffLat) / 2;
    var centerLng = (pickupLng + dropoffLng) / 2;

    var map = window.OSRH.initMap('request-map', { lat: centerLat, lng: centerLng, zoom: 12 });
    if (!map) {
        return;
    }

    // Define pickup and dropoff points
    var pickup = {
        lat: pickupLat,
        lng: pickupLng,
        type: 'pickup',
        label: '<strong>Pickup Location</strong><br><?php echo e(addslashes($request['PickupLocation'] ?? 'Pickup')); ?>'
    };
    var dropoff = {
        lat: dropoffLat,
        lng: dropoffLng,
        type: 'dropoff',
        label: '<strong>Dropoff Location</strong><br><?php echo e(addslashes($request['DropoffLocation'] ?? 'Dropoff')); ?>'
    };

    // Show route with custom markers - just for visualization, don't update distance
    window.OSRH.showMultiRoute(map, [pickup, dropoff], {
        segmentColors: ['#4fc3f7'],  // Blue route
        weight: 5,
        opacity: 0.8
    }).catch(function(err) {
        console.warn('Route display error:', err);
        // Fallback to simple markers
        window.OSRH.addMarker(map, pickup.lat, pickup.lng, 'pickup', 'Pickup');
        window.OSRH.addMarker(map, dropoff.lat, dropoff.lng, 'dropoff', 'Dropoff');
        // Draw a dashed line as fallback
        L.polyline([[pickupLat, pickupLng], [dropoffLat, dropoffLng]], {
            color: '#4fc3f7',
            weight: 3,
            opacity: 0.7,
            dashArray: '10, 10'
        }).addTo(map);
        map.fitBounds([[pickupLat, pickupLng], [dropoffLat, dropoffLng]], { padding: [50, 50] });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
