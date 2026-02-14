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

$tripId = (int)array_get($_GET, 'trip_id', 0);
if ($tripId <= 0) {
    redirect('error.php?code=404');
}

$user      = current_user();
$driverRow = $user['driver'] ?? null;
if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}
$driverId = (int)$driverRow['DriverID'];

// Call stored procedure to get trip details
$stmt = db_call_procedure('dbo.spDriverGetTripWithLocations', [$tripId, $driverId]);

if ($stmt === false) {
    http_response_code(500);
    echo 'Error retrieving trip details.';
    exit;
}

$trip = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$trip) {
    redirect('error.php?code=404');
}

// Direct query to get driver's UseGPS flag (reliable - doesn't depend on session)
$driverUseGPS = false;
$useGpsRow = db_fetch_one('SELECT UseGPS FROM dbo.Driver WHERE DriverID = ?', [$driverId]);
if ($useGpsRow && !empty($useGpsRow['UseGPS'])) {
    $driverUseGPS = true;
}

// Check if this is a real driver trip
// Either from Trip.IsRealDriverTrip flag OR from driver's UseGPS setting
$isRealDriverTrip = !empty($trip['IsRealDriverTrip']);

// If driver has UseGPS=1, treat as real driver trip even if Trip record wasn't marked
if ($driverUseGPS && !$isRealDriverTrip) {
    $isRealDriverTrip = true;
    // Also update the trip record to mark it correctly for future
    db_execute(
        'UPDATE dbo.Trip SET IsRealDriverTrip = 1 WHERE TripID = ?',
        [$tripId]
    );
}

// Get active trips for this driver
$activeTripsStmt = db_call_procedure('dbo.spDriverGetActiveTrips', [$driverId]);
$activeTrips = [];
if ($activeTripsStmt !== false) {
    while ($row = sqlsrv_fetch_array($activeTripsStmt, SQLSRV_FETCH_ASSOC)) {
        $activeTrips[] = $row;
    }
    sqlsrv_free_stmt($activeTripsStmt);
}

// Get previous trips for this driver (last 10)
$previousTripsStmt = db_call_procedure('dbo.spDriverGetPreviousTrips', [$driverId, 10]);
$previousTrips = [];
if ($previousTripsStmt !== false) {
    while ($row = sqlsrv_fetch_array($previousTripsStmt, SQLSRV_FETCH_ASSOC)) {
        $previousTrips[] = $row;
    }
    sqlsrv_free_stmt($previousTripsStmt);
}

function osrh_dt_driver_trip($v, bool $withTime = false): string
{
    if ($v instanceof DateTimeInterface) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    if ($v instanceof DateTime) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    return $v ? (string)$v : '';
}

// Check if GPS prompt should be shown
// Show if: enable_gps=1 param AND (trip is real driver OR driver uses GPS)
$showGpsPrompt = isset($_GET['enable_gps']) && $_GET['enable_gps'] === '1' && $isRealDriverTrip;

// Check if auto-start GPS is requested (when driver just accepted ride)
$autoStartGps = isset($_GET['auto_start']) && $_GET['auto_start'] === '1';

$pageTitle = 'Trip #' . $trip['TripID'];
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($showGpsPrompt): ?>
<!-- GPS Permission Modal -->
<div id="gps-modal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; display: flex; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 400px; margin: 1rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">📍</div>
        <h2 style="margin: 0 0 0.5rem 0; font-size: 1.25rem;">Enable Location Tracking</h2>
        <p style="color: #6b7280; margin-bottom: 1.5rem; font-size: 0.9rem;">
            <?php if ($autoStartGps): ?>
            You just accepted a ride! Enable GPS so the passenger can track your location in real-time.
            <?php else: ?>
            This is a real driver trip. Please enable GPS so the passenger can track your location in real-time.
            <?php endif; ?>
        </p>
        <div id="gps-status" style="padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; display: none;"></div>
        <button id="enable-gps-btn" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-size: 1rem;">
            🛰️ Enable GPS Tracking
        </button>
        <button id="skip-gps-btn" class="btn btn-ghost" style="width: 100%; margin-top: 0.5rem;">
            Skip for now
        </button>
    </div>
</div>
<script>
(function() {
    var modal = document.getElementById('gps-modal');
    var enableBtn = document.getElementById('enable-gps-btn');
    var skipBtn = document.getElementById('skip-gps-btn');
    var statusDiv = document.getElementById('gps-status');
    var autoStart = <?php echo $autoStartGps ? 'true' : 'false'; ?>;
    
    function showStatus(message, isError) {
        statusDiv.style.display = 'block';
        statusDiv.style.background = isError ? 'rgba(239, 68, 68, 0.1)' : 'rgba(34, 197, 94, 0.1)';
        statusDiv.style.color = isError ? '#ef4444' : '#22c55e';
        statusDiv.textContent = message;
    }
    
    function sendLocation(lat, lng, callback) {
        fetch('../api/update_driver_location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ latitude: lat, longitude: lng })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                callback(true);
            } else {
                callback(false, data.error || 'Failed to update location');
            }
        })
        .catch(function(err) {
            callback(false, err.message);
        });
    }
    
    function requestGps() {
        enableBtn.disabled = true;
        enableBtn.textContent = 'Requesting GPS...';
        
        if (!('geolocation' in navigator)) {
            showStatus('GPS not supported in this browser', true);
            enableBtn.disabled = false;
            enableBtn.textContent = '🛰️ Enable GPS Tracking';
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                showStatus('Got location, sending to server...', false);
                sendLocation(pos.coords.latitude, pos.coords.longitude, function(success, error) {
                    if (success) {
                        showStatus('✓ GPS enabled! Passenger can now see your location.', false);
                        setTimeout(function() {
                            modal.style.display = 'none';
                            // Start continuous tracking
                            window.dispatchEvent(new CustomEvent('gps-enabled'));
                        }, 1000);
                    } else {
                        showStatus('Error: ' + error, true);
                        enableBtn.disabled = false;
                        enableBtn.textContent = '🛰️ Try Again';
                    }
                });
            },
            function(err) {
                var msg = 'Location error: ';
                switch(err.code) {
                    case 1: msg += 'Permission denied. Please allow location access.'; break;
                    case 2: msg += 'Position unavailable.'; break;
                    case 3: msg += 'Timeout. Try again.'; break;
                    default: msg += err.message;
                }
                showStatus(msg, true);
                enableBtn.disabled = false;
                enableBtn.textContent = '🛰️ Try Again';
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    }
    
    enableBtn.addEventListener('click', requestGps);
    
    // Auto-start GPS request if coming from ride acceptance
    if (autoStart) {
        setTimeout(function() {
            showStatus('Requesting GPS access automatically...', false);
            requestGps();
        }, 500);
    }
    
    skipBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
})();
</script>
<?php endif; ?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                Trip #<?php echo e($trip['TripID']); ?>
                <span class="badge badge-pill" style="margin-left:0.5rem;"><?php echo e($trip['Status']); ?></span>
                <?php if ($isRealDriverTrip): ?>
                <span style="font-size: 0.7rem; color: #22c55e; padding: 0.15rem 0.4rem; background: rgba(34, 197, 94, 0.1); border-radius: 3px; margin-left: 0.3rem;">
                    ⭐ Real Driver Trip
                </span>
                <?php endif; ?>
            </h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                <?php if ($isRealDriverTrip): ?>
                Real driver trip - Use Start Trip and Conclude Ride buttons. No tracking simulation.
                <?php else: ?>
                Driver view of trip details, route, and passenger information.
                <?php endif; ?>
            </p>
        </div>
        <div>
            <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost btn-small">
                &larr; Back to trips
            </a>
        </div>
    </div>

    <?php if ($isRealDriverTrip && in_array(strtolower($trip['Status']), ['assigned', 'dispatched', 'in_progress'])): ?>
    <!-- Real Driver Trip Action Buttons -->
    <div id="trip-action-panel" style="padding: 1rem; background: rgba(34, 197, 94, 0.08); border-bottom: 1px solid rgba(34, 197, 94, 0.2);">
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <?php $status = strtolower($trip['Status']); ?>
            
            <div id="pickup-phase-buttons" style="display: <?php echo in_array($status, ['assigned', 'dispatched']) ? 'flex' : 'none'; ?>; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <button type="button" id="start-trip-btn" class="btn btn-primary" style="font-size: 1rem; padding: 0.6rem 1.5rem;">
                    🚗 Start Trip
                </button>
                <button type="button" id="cancel-trip-btn" class="btn btn-ghost">
                    Cancel Trip
                </button>
                <span id="pickup-phase-hint" class="text-muted" style="font-size: 0.85rem; margin-left: 0.5rem;">
                    Click "Start Trip" when passenger is in the car
                </span>
            </div>
            
            <div id="trip-phase-buttons" style="display: <?php echo $status === 'in_progress' ? 'flex' : 'none'; ?>; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <button type="button" id="conclude-trip-btn" class="btn btn-primary" style="font-size: 1rem; padding: 0.6rem 1.5rem; background: #22c55e;">
                    ✓ Conclude Ride
                </button>
                <span class="text-muted" style="font-size: 0.85rem; margin-left: 0.5rem;">
                    Click "Conclude Ride" when you arrive at destination
                </span>
            </div>
        </div>
        
        <!-- Status message area -->
        <div id="action-status-message" style="display: none; margin-top: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.9rem;"></div>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.2rem;margin-top:1.1rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Trip summary</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <dl class="key-value-list">
                    <dt>Status</dt>
                    <dd><?php echo e($trip['Status']); ?></dd>

                    <dt>Distance</dt>
                    <dd>
                        <?php
                        if ($trip['DistanceKm'] !== null) {
                            echo '<strong>' . e(number_format((float)$trip['DistanceKm'], 2)) . ' km</strong>';
                        } else {
                            echo '<span class="text-muted">Not recorded</span>';
                        }
                        ?>
                    </dd>

                    <?php 
                    // For segment trips, check payment status
                    $isSegmentTrip = !empty($trip['IsSegmentTrip']);
                    $segmentPaymentState = $trip['SegmentPaymentState'] ?? null;
                    $segmentEstimatedFare = (float)($trip['SegmentEstimatedFare'] ?? 0);
                    $hasSegmentPaymentCompleted = ($segmentPaymentState === 'payment_completed');
                    
                    // Show payment breakdown only if payment is completed (for segment trips) or trip has actual cost
                    $showPaymentBreakdown = $trip['Status'] === 'completed' && (
                        (!$isSegmentTrip && $trip['ActualCost'] !== null) ||
                        ($isSegmentTrip && $hasSegmentPaymentCompleted && $trip['ActualCost'] !== null)
                    );
                    ?>
                    
                    <?php if ($isSegmentTrip): ?>
                        <!-- Segment Payment Status -->
                        <dt style="margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid var(--color-border-subtle); font-weight: 600;">Segment Payment</dt>
                        <dd>
                            <?php if ($segmentPaymentState === 'payment_completed'): ?>
                                <span style="display: inline-block; padding: 0.25rem 0.5rem; background: rgba(34, 197, 94, 0.15); color: #22c55e; border-radius: 4px; font-size: 0.85rem;">
                                    ✓ Passenger has paid
                                </span>
                            <?php elseif ($segmentPaymentState === 'payment_pending'): ?>
                                <span style="display: inline-block; padding: 0.25rem 0.5rem; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border-radius: 4px; font-size: 0.85rem;">
                                    ⏳ Payment pending confirmation
                                </span>
                            <?php else: ?>
                                <span style="display: inline-block; padding: 0.25rem 0.5rem; background: rgba(107, 114, 128, 0.15); color: #6b7280; border-radius: 4px; font-size: 0.85rem;">
                                    ⏳ Awaiting passenger payment
                                </span>
                            <?php endif; ?>
                        </dd>
                        
                        <dt>Segment fare (estimated)</dt>
                        <dd>
                            <strong>€<?php echo number_format($segmentEstimatedFare, 2); ?></strong>
                            <?php if (!empty($trip['SegmentDistanceKm'])): ?>
                            <div style="font-size: 0.75rem; color: #6c757d; margin-top: 0.2rem;">
                                ~<?php echo number_format((float)$trip['SegmentDistanceKm'], 1); ?> km segment
                            </div>
                            <?php endif; ?>
                        </dd>
                        
                        <?php if ($hasSegmentPaymentCompleted): ?>
                            <dt style="color: #49EACB;">Platform fee</dt>
                            <dd style="color: #49EACB; font-weight: 600;">
                                €0.00 <span style="font-size: 0.75rem;">✨ No middleman!</span>
                            </dd>
                            
                            <dt style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle); font-weight: 600; color: var(--color-success);">Your earnings (100%)</dt>
                            <dd style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle);">
                                <strong style="color: var(--color-success); font-size: 1.15em;">
                                    €<?php echo e(number_format((float)($trip['DriverEarnings'] ?? $segmentEstimatedFare), 2)); ?>
                                </strong>
                            </dd>
                        <?php else: ?>
                            <dt>Your earnings (100%)</dt>
                            <dd>
                                <span style="color: #22c55e; font-weight: 600;">
                                    €<?php echo number_format($segmentEstimatedFare, 2); ?>
                                </span>
                                <div style="font-size: 0.75rem; color: #49EACB; margin-top: 0.2rem;">
                                    ✨ No platform fee - you keep it all!
                                </div>
                            </dd>
                        <?php endif; ?>
                        
                    <?php elseif ($trip['ActualCost'] !== null && $trip['Status'] === 'completed'): ?>
                        <!-- Regular trip payment breakdown -->
                        <dt style="margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid var(--color-border-subtle); font-weight: 600;">Payment Breakdown</dt>
                        <dd></dd>
                        
                        <?php if ($trip['BaseFare'] !== null): ?>
                            <dt>Base fare</dt>
                            <dd>€<?php echo e(number_format((float)$trip['BaseFare'], 2)); ?></dd>
                        <?php endif; ?>
                        
                        <?php if ($trip['DistanceFare'] !== null): ?>
                            <dt>Distance fare</dt>
                            <dd>€<?php echo e(number_format((float)$trip['DistanceFare'], 2)); ?></dd>
                        <?php endif; ?>
                        
                        <?php if ($trip['TimeFare'] !== null): ?>
                            <dt>Time fare</dt>
                            <dd>€<?php echo e(number_format((float)$trip['TimeFare'], 2)); ?></dd>
                        <?php endif; ?>
                        
                        <?php if ($trip['SurgeMultiplier'] !== null && (float)$trip['SurgeMultiplier'] > 1.0): ?>
                            <dt>Surge multiplier</dt>
                            <dd><?php echo e(number_format((float)$trip['SurgeMultiplier'], 2)); ?>x</dd>
                        <?php endif; ?>
                        
                        <dt style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle); font-weight: 600;">Total amount</dt>
                        <dd style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle);">
                            <strong>€<?php echo e(number_format((float)$trip['ActualCost'], 2)); ?></strong>
                        </dd>
                        
                        <?php if ($trip['ServiceFeeAmount'] !== null): ?>
                            <dt style="color: #49EACB;">Platform fee</dt>
                            <dd style="color: #49EACB; font-weight: 600;">€0.00 <span style="font-size: 0.75rem;">✨ No middleman!</span></dd>
                        <?php endif; ?>
                        
                        <dt style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle); font-weight: 600; color: var(--color-success);">Your earnings (100%)</dt>
                        <dd style="padding-top: 0.4rem; border-top: 1px solid var(--color-border-subtle);">
                            <strong style="color: var(--color-success); font-size: 1.15em;">€<?php echo e(number_format((float)$trip['DriverEarnings'], 2)); ?></strong>
                        </dd>
                    <?php else: ?>
                        <!-- Non-segment trip without payment yet -->
                        <dt>Total cost</dt>
                        <dd><span class="text-muted">No payment yet</span></dd>

                        <dt>Your earnings (100%)</dt>
                        <dd><span class="text-muted">No payment yet</span> <span style="font-size: 0.75rem; color: #49EACB;">✨ No fee!</span></dd>
                    <?php endif; ?>

                    <dt>Started at</dt>
                    <dd><?php echo e(osrh_dt_driver_trip($trip['StartedAt'] ?? null, true)); ?></dd>

                    <dt>Completed at</dt>
                    <dd><?php echo e(osrh_dt_driver_trip($trip['CompletedAt'] ?? null, true)); ?></dd>

                    <dt>Created</dt>
                    <dd><?php echo e(osrh_dt_driver_trip($trip['CreatedAt'] ?? null, true)); ?></dd>
                </dl>

                <h3 style="font-size:0.9rem;margin-top:0.9rem;">Passenger</h3>
                <dl class="key-value-list">
                    <dt>Name</dt>
                    <dd><?php echo e($trip['PassengerName']); ?></dd>
                    <dt>Email</dt>
                    <dd><?php echo e($trip['PassengerEmail']); ?></dd>
                </dl>
                <?php if (!empty($trip['PassengerUserID'])): ?>
                <div style="margin-top: 0.8rem;">
                    <a href="<?php echo e(url('driver/messages.php?user_id=' . (int)$trip['PassengerUserID'])); ?>" 
                       class="btn btn-primary btn-small" 
                       style="display: inline-flex; align-items: center; gap: 0.4rem;">
                        💬 Message Passenger
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Vehicle & notes</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <dl class="key-value-list">
                    <dt>Vehicle</dt>
                    <dd><?php echo e($trip['VehicleTypeName']); ?> (<?php echo e($trip['PlateNo']); ?>)</dd>

                    <dt>Luggage</dt>
                    <dd>
                        <?php
                        if ($trip['LuggageCount'] !== null) {
                            echo (int)$trip['LuggageCount'] . ' piece(s)';
                        } else {
                            echo '<span class="text-muted">Not specified</span>';
                        }
                        ?>
                    </dd>

                    <dt>Payment Method</dt>
                    <dd>
                        <?php
                        $paymentClass = $trip['PaymentMethodClass'] ?? 'unknown';
                        $paymentDisplay = $trip['PaymentMethodDisplay'] ?? 'Not specified';
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

                <h3 style="font-size:0.9rem;margin-top:0.9rem;">Notes</h3>
                <p class="text-muted" style="font-size:0.84rem;">
                    <?php if (!empty($trip['Notes'])): ?>
                        <?php echo nl2br(e($trip['Notes'])); ?>
                    <?php else: ?>
                        <span>No special notes from passenger.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Route / map -->
    <div class="card" style="margin-top:1.3rem;">
        <div class="card-header">
            <h2 class="card-title" style="font-size:0.95rem;">Route Map</h2>
        </div>
        <div class="card-body" style="padding:0.9rem 1rem 0.6rem;font-size:0.86rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom:0.6rem;">
                <div>
                    <p class="text-muted" style="font-size:0.84rem;margin:0;">
                        <strong>Pickup:</strong> <?php echo e($trip['PickupAddress']); ?><br>
                        <strong>Dropoff:</strong> <?php echo e($trip['DropoffAddress']); ?>
                    </p>
                </div>
                <?php if ($trip['DistanceKm'] !== null && $trip['Status'] === 'completed'): ?>
                <div style="text-align: right;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">Actual Distance</div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: var(--color-primary);">
                        <?php echo e(number_format((float)$trip['DistanceKm'], 2)); ?> km
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div id="trip-map" class="map-container" style="height:320px;"></div>
    </div>

    <!-- Active Trips -->
    <?php if (!empty($activeTrips)): ?>
    <div class="card" style="margin-top:1.3rem;">
        <div class="card-header">
            <h2 class="card-title" style="font-size:0.95rem;">Your Active Trips</h2>
        </div>
        <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Trip ID</th>
                            <th>Status</th>
                            <th>Passenger</th>
                            <th>Pickup</th>
                            <th>Dropoff</th>
                            <th>Requested</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeTrips as $at): ?>
                        <tr<?php echo ($at['TripID'] == $tripId) ? ' style="background:rgba(59,130,246,0.1);"' : ''; ?>>
                            <td>#<?php echo e($at['TripID']); ?></td>
                            <td>
                                <span class="badge badge-pill"><?php echo e($at['Status']); ?></span>
                            </td>
                            <td><?php echo e($at['PassengerName']); ?></td>
                            <td><?php echo e($at['PickupAddress']); ?></td>
                            <td><?php echo e($at['DropoffAddress']); ?></td>
                            <td><?php echo e(osrh_dt_driver_trip($at['RequestedAt'] ?? null, true)); ?></td>
                            <td>
                                <?php if ($at['TripID'] != $tripId): ?>
                                <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . urlencode((string)$at['TripID']))); ?>" 
                                   class="btn btn-small btn-ghost">View</a>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">Current</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Previous Trips -->
    <?php if (!empty($previousTrips)): ?>
    <div class="card" style="margin-top:1.3rem;">
        <div class="card-header">
            <h2 class="card-title" style="font-size:0.95rem;">Recent Completed Trips</h2>
        </div>
        <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Trip ID</th>
                            <th>Status</th>
                            <th>Passenger</th>
                            <th>Distance</th>
                            <th>Amount</th>
                            <th>Completed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previousTrips as $pt): ?>
                        <tr>
                            <td>#<?php echo e($pt['TripID']); ?></td>
                            <td>
                                <span class="badge badge-pill"><?php echo e($pt['Status']); ?></span>
                            </td>
                            <td><?php echo e($pt['PassengerName']); ?></td>
                            <td>
                                <?php 
                                if ($pt['DistanceKm'] !== null) {
                                    echo e(number_format((float)$pt['DistanceKm'], 2)) . ' km';
                                } else {
                                    echo '<span class="text-muted">–</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($pt['Amount'] !== null) {
                                    echo e(number_format((float)$pt['Amount'], 2));
                                    if (!empty($pt['CurrencyCode'])) {
                                        echo ' ' . e($pt['CurrencyCode']);
                                    }
                                } else {
                                    echo '<span class="text-muted">–</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo e(osrh_dt_driver_trip($pt['CompletedAt'] ?? null, true)); ?></td>
                            <td>
                                <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . urlencode((string)$pt['TripID']))); ?>" 
                                   class="btn btn-small btn-ghost">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.OSRH || !window.OSRH.initMap) return;

    var isRealTrip = <?php echo $isRealDriverTrip ? 'true' : 'false'; ?>;
    var tripId = <?php echo json_encode($tripId); ?>;
    var currentTripStatus = '<?php echo strtolower($trip['Status']); ?>';
    var isActiveTrip = ['assigned', 'dispatched', 'in_progress'].indexOf(currentTripStatus) !== -1;

    var pickup = {
        lat: <?php echo json_encode((float)$trip['PickupLat']); ?>,
        lng: <?php echo json_encode((float)$trip['PickupLng']); ?>,
        type: 'pickup',
        label: '<strong>📍 Pickup</strong><br><?php echo e(addslashes($trip['PickupAddress'] ?? 'Pickup location')); ?>'
    };
    var dropoff = {
        lat: <?php echo json_encode((float)$trip['DropoffLat']); ?>,
        lng: <?php echo json_encode((float)$trip['DropoffLng']); ?>,
        type: 'dropoff',
        label: '<strong>🏁 Dropoff</strong><br><?php echo e(addslashes($trip['DropoffAddress'] ?? 'Destination')); ?>'
    };

    var centerLat = (pickup.lat + dropoff.lat) / 2;
    var centerLng = (pickup.lng + dropoff.lng) / 2;

    var map = window.OSRH.initMap('trip-map', {
        lat: centerLat,
        lng: centerLng,
        zoom: 13
    });
    if (!map) return;
    
    // Store map reference globally for GPS tracker to use
    window.OSRH.map = map;

    var driverMarker = null;
    var pickupMarker = null;
    var dropoffMarker = null;
    var currentRouteLayer = null;
    var etaDiv = null;
    var currentDriverLat = null;
    var currentDriverLng = null;

    // Create driver marker icon
    function createDriverIcon() {
        return L.divIcon({
            className: 'driver-marker',
            html: '<div style="background: #3b82f6; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.4); border: 3px solid white;">🚗</div>',
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });
    }
    
    // Create pickup marker icon
    function createPickupIcon() {
        return L.divIcon({
            className: 'pickup-marker',
            html: '<div style="background: #22c55e; color: white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.3); border: 2px solid white;">👤</div>',
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });
    }
    
    // Create dropoff marker icon
    function createDropoffIcon() {
        return L.divIcon({
            className: 'dropoff-marker',
            html: '<div style="background: #ef4444; color: white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.3); border: 2px solid white;">🏁</div>',
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });
    }
    
    // Update or create ETA display
    function updateEtaDisplay(title, distKm, durationMin, color) {
        if (!etaDiv) {
            etaDiv = document.createElement('div');
            etaDiv.id = 'route-eta';
            var mapContainer = document.getElementById('trip-map');
            if (mapContainer && mapContainer.parentNode) {
                mapContainer.parentNode.insertBefore(etaDiv, mapContainer);
            }
        }
        etaDiv.style.cssText = 'background: ' + color + '; color: white; padding: 0.75rem 1rem; border-radius: 8px; margin: 0.5rem 1rem 1rem; display: flex; justify-content: space-between; align-items: center;';
        etaDiv.innerHTML = '<div><strong>' + title + '</strong></div><div style="text-align: right;"><div style="font-size: 1.1rem; font-weight: 700;">' + distKm + ' km</div><div style="font-size: 0.85rem; opacity: 0.9;">~' + durationMin + ' min</div></div>';
    }
    
    // Show route to pickup (Phase 1: Driver heading to passenger)
    function showRouteToPickup(driverLat, driverLng) {
        currentDriverLat = driverLat;
        currentDriverLng = driverLng;
        
        // Clear existing route
        if (currentRouteLayer) {
            map.removeLayer(currentRouteLayer);
            currentRouteLayer = null;
        }
        
        // Add/update driver marker
        if (!driverMarker) {
            driverMarker = L.marker([driverLat, driverLng], { icon: createDriverIcon(), zIndexOffset: 1000 }).addTo(map);
            driverMarker.bindPopup('<strong>📍 You are here</strong>');
        } else {
            driverMarker.setLatLng([driverLat, driverLng]);
        }
        
        // Add pickup marker
        if (!pickupMarker) {
            pickupMarker = L.marker([pickup.lat, pickup.lng], { icon: createPickupIcon() }).addTo(map);
            pickupMarker.bindPopup('<strong>🎯 Passenger Pickup</strong><br><?php echo e(addslashes($trip['PickupAddress'] ?? '')); ?>');
        }
        
        // Remove dropoff marker during pickup phase
        if (dropoffMarker) {
            map.removeLayer(dropoffMarker);
            dropoffMarker = null;
        }
        
        // Fetch route from driver to pickup
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
            driverLng + ',' + driverLat + ';' + pickup.lng + ',' + pickup.lat + 
            '?overview=full&geometries=geojson';
        
        fetch(osrmUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.routes && data.routes[0]) {
                    var route = data.routes[0];
                    var coords = route.geometry.coordinates.map(function(c) {
                        return [c[1], c[0]];
                    });
                    
                    // Draw route to pickup in blue dashed line
                    currentRouteLayer = L.polyline(coords, {
                        color: '#3b82f6',
                        weight: 5,
                        opacity: 0.8,
                        dashArray: '10, 10'
                    }).addTo(map);
                    
                    var distKm = (route.distance / 1000).toFixed(1);
                    var durationMin = Math.ceil(route.duration / 60);
                    updateEtaDisplay('🚗 Route to Passenger', distKm, durationMin, '#3b82f6');
                    
                    // Fit map to show route
                    map.fitBounds(currentRouteLayer.getBounds(), { padding: [50, 50] });
                }
            })
            .catch(function(err) {
                console.warn('Route error:', err);
                map.fitBounds([[driverLat, driverLng], [pickup.lat, pickup.lng]], { padding: [50, 50] });
            });
    }
    
    // Show route to destination (Phase 2: Trip in progress)
    function showRouteToDestination(startLat, startLng) {
        // Clear existing route
        if (currentRouteLayer) {
            map.removeLayer(currentRouteLayer);
            currentRouteLayer = null;
        }
        
        // Use current driver position or pickup as start
        var fromLat = startLat || currentDriverLat || pickup.lat;
        var fromLng = startLng || currentDriverLng || pickup.lng;
        
        // Update driver marker position
        if (driverMarker) {
            driverMarker.setLatLng([fromLat, fromLng]);
        } else {
            driverMarker = L.marker([fromLat, fromLng], { icon: createDriverIcon(), zIndexOffset: 1000 }).addTo(map);
        }
        driverMarker.bindPopup('<strong>🚕 Trip in Progress</strong>');
        
        // Remove pickup marker, add dropoff marker
        if (pickupMarker) {
            map.removeLayer(pickupMarker);
            pickupMarker = null;
        }
        
        if (!dropoffMarker) {
            dropoffMarker = L.marker([dropoff.lat, dropoff.lng], { icon: createDropoffIcon() }).addTo(map);
            dropoffMarker.bindPopup('<strong>🏁 Destination</strong><br><?php echo e(addslashes($trip['DropoffAddress'] ?? '')); ?>');
        }
        
        // Fetch route from current position to dropoff
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
            fromLng + ',' + fromLat + ';' + dropoff.lng + ',' + dropoff.lat + 
            '?overview=full&geometries=geojson';
        
        fetch(osrmUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.routes && data.routes[0]) {
                    var route = data.routes[0];
                    var coords = route.geometry.coordinates.map(function(c) {
                        return [c[1], c[0]];
                    });
                    
                    // Draw route to destination in green solid line
                    currentRouteLayer = L.polyline(coords, {
                        color: '#22c55e',
                        weight: 5,
                        opacity: 0.9
                    }).addTo(map);
                    
                    var distKm = (route.distance / 1000).toFixed(1);
                    var durationMin = Math.ceil(route.duration / 60);
                    updateEtaDisplay('🏁 Route to Destination', distKm, durationMin, '#22c55e');
                    
                    // Fit map to show route
                    map.fitBounds(currentRouteLayer.getBounds(), { padding: [50, 50] });
                }
            })
            .catch(function(err) {
                console.warn('Route error:', err);
                map.fitBounds([[fromLat, fromLng], [dropoff.lat, dropoff.lng]], { padding: [50, 50] });
            });
    }
    
    // Show completed trip view
    function showCompletedTrip() {
        if (etaDiv) {
            etaDiv.style.background = '#6b7280';
            etaDiv.innerHTML = '<div><strong>✓ Trip Completed</strong></div><div style="font-size: 0.9rem;">Thank you for driving!</div>';
        }
    }
    
    // Initialize map based on current status
    function initializeMapForStatus() {
        if (!isRealTrip || !isActiveTrip) {
            // Show standard pickup-dropoff route for non-real trips
            showStandardRoute();
            return;
        }
        
        if (currentTripStatus === 'in_progress') {
            // Trip already in progress - show route to destination
            if ('geolocation' in navigator) {
                navigator.geolocation.getCurrentPosition(function(pos) {
                    showRouteToDestination(pos.coords.latitude, pos.coords.longitude);
                }, function() {
                    showRouteToDestination(pickup.lat, pickup.lng);
                }, { enableHighAccuracy: true, timeout: 10000 });
            } else {
                showRouteToDestination(pickup.lat, pickup.lng);
            }
        } else {
            // Assigned/dispatched - show route to pickup
            if ('geolocation' in navigator) {
                navigator.geolocation.getCurrentPosition(function(pos) {
                    showRouteToPickup(pos.coords.latitude, pos.coords.longitude);
                }, function(err) {
                    console.warn('Geolocation error:', err);
                    showStandardRoute();
                }, { enableHighAccuracy: true, timeout: 10000 });
            } else {
                showStandardRoute();
            }
        }
    }
    
    function showStandardRoute() {
        // Show route with custom markers (pickup to dropoff)
        window.OSRH.showMultiRoute(map, [pickup, dropoff], {
            segmentColors: ['#22c55e'],
            weight: 5,
            opacity: 0.8
        }).catch(function(err) {
            console.warn('Route display error:', err);
            window.OSRH.addMarker(map, pickup.lat, pickup.lng, 'pickup', 'Pickup');
            window.OSRH.addMarker(map, dropoff.lat, dropoff.lng, 'dropoff', 'Dropoff');
        });
    }
    
    // Handle AJAX trip status updates
    function updateTripStatus(action, callback) {
        var statusMsg = document.getElementById('action-status-message');
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.style.background = 'rgba(59, 130, 246, 0.1)';
            statusMsg.style.color = '#3b82f6';
            statusMsg.textContent = 'Processing...';
        }
        
        fetch('../api/update_trip_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trip_id: tripId, action: action })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (statusMsg) {
                    statusMsg.style.background = 'rgba(34, 197, 94, 0.1)';
                    statusMsg.style.color = '#22c55e';
                    statusMsg.textContent = '✓ ' + data.message;
                }
                currentTripStatus = data.newStatus;
                if (callback) callback(true, data);
            } else {
                if (statusMsg) {
                    statusMsg.style.background = 'rgba(239, 68, 68, 0.1)';
                    statusMsg.style.color = '#ef4444';
                    statusMsg.textContent = '✗ ' + (data.error || 'Failed');
                }
                if (callback) callback(false, data);
            }
        })
        .catch(function(err) {
            console.error('Error updating trip status:', err);
            if (statusMsg) {
                statusMsg.style.background = 'rgba(239, 68, 68, 0.1)';
                statusMsg.style.color = '#ef4444';
                statusMsg.textContent = '✗ Network error. Please try again.';
            }
            if (callback) callback(false, { error: err.message });
        });
    }
    
    // Button handlers
    var startTripBtn = document.getElementById('start-trip-btn');
    var cancelTripBtn = document.getElementById('cancel-trip-btn');
    var concludeTripBtn = document.getElementById('conclude-trip-btn');
    var pickupPhaseButtons = document.getElementById('pickup-phase-buttons');
    var tripPhaseButtons = document.getElementById('trip-phase-buttons');
    
    if (startTripBtn) {
        startTripBtn.addEventListener('click', function() {
            startTripBtn.disabled = true;
            startTripBtn.textContent = 'Starting...';
            
            updateTripStatus('start', function(success, data) {
                if (success) {
                    // Switch UI to trip phase
                    if (pickupPhaseButtons) pickupPhaseButtons.style.display = 'none';
                    if (tripPhaseButtons) tripPhaseButtons.style.display = 'flex';
                    
                    // Update badge
                    var badge = document.querySelector('.badge-pill');
                    if (badge) {
                        badge.textContent = 'in_progress';
                        badge.style.background = '#22c55e';
                    }
                    
                    // Update map to show route to destination
                    showRouteToDestination(currentDriverLat, currentDriverLng);
                    
                    // Hide status message after 3 seconds
                    setTimeout(function() {
                        var statusMsg = document.getElementById('action-status-message');
                        if (statusMsg) statusMsg.style.display = 'none';
                    }, 3000);
                } else {
                    startTripBtn.disabled = false;
                    startTripBtn.textContent = '🚗 Start Trip';
                }
            });
        });
    }
    
    if (cancelTripBtn) {
        cancelTripBtn.addEventListener('click', function() {
            if (!confirm('Are you sure you want to cancel this trip?')) return;
            
            cancelTripBtn.disabled = true;
            updateTripStatus('cancel', function(success) {
                if (success) {
                    setTimeout(function() {
                        window.location.href = '<?php echo e(url('driver/trips_assigned.php')); ?>';
                    }, 1500);
                } else {
                    cancelTripBtn.disabled = false;
                }
            });
        });
    }
    
    if (concludeTripBtn) {
        concludeTripBtn.addEventListener('click', function() {
            concludeTripBtn.disabled = true;
            concludeTripBtn.textContent = 'Completing...';
            
            updateTripStatus('complete', function(success) {
                if (success) {
                    showCompletedTrip();
                    if (tripPhaseButtons) tripPhaseButtons.style.display = 'none';
                    
                    // Update badge
                    var badge = document.querySelector('.badge-pill');
                    if (badge) {
                        badge.textContent = 'completed';
                        badge.style.background = '#6b7280';
                    }
                } else {
                    concludeTripBtn.disabled = false;
                    concludeTripBtn.textContent = '✓ Conclude Ride';
                }
            });
        });
    }
    
    // Expose functions globally for GPS tracker to use
    window.OSRH.updateDriverMarker = function(lat, lng) {
        currentDriverLat = lat;
        currentDriverLng = lng;
        if (driverMarker) {
            driverMarker.setLatLng([lat, lng]);
        }
    };
    
    window.OSRH.refreshRouteToPickup = function() {
        if (currentDriverLat && currentDriverLng && currentTripStatus !== 'in_progress') {
            showRouteToPickup(currentDriverLat, currentDriverLng);
        }
    };
    
    // Initialize
    initializeMapForStatus();
});
</script>

<script>
// Live GPS sender for real-driver trips - Real-time continuous tracking
document.addEventListener('DOMContentLoaded', function () {
    var isRealTrip = <?php echo $isRealDriverTrip ? 'true' : 'false'; ?>;
    var tripStatus = '<?php echo strtolower($trip['Status']); ?>';
    var trackableStatuses = ['assigned', 'dispatched', 'in_progress'];
    var tripId = <?php echo json_encode($tripId); ?>;

    if (!isRealTrip || trackableStatuses.indexOf(tripStatus) === -1) {
        return;
    }

    if (!('geolocation' in navigator)) {
        console.warn('Geolocation not available in this browser.');
        return;
    }

    var lastSentAt = 0;
    var lastLat = null;
    var lastLng = null;
    var firstPayload = true;
    var minIntervalMs = 3000; // Send every 3 seconds for real-time tracking
    var minDistanceMeters = 10; // Send if moved more than 10 meters
    var watchId = null;
    var gpsStatusDiv = null;
    var lastUpdateTime = null;

    // Create GPS status indicator
    function createGpsStatusIndicator() {
        if (gpsStatusDiv) return;
        gpsStatusDiv = document.createElement('div');
        gpsStatusDiv.id = 'gps-status-indicator';
        gpsStatusDiv.style.cssText = 'position: fixed; bottom: 1rem; right: 1rem; background: #22c55e; color: white; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.85rem; z-index: 1000; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.2);';
        gpsStatusDiv.innerHTML = '<span class="gps-pulse">🛰️</span> GPS Active';
        document.body.appendChild(gpsStatusDiv);
        
        // Add pulse animation
        var style = document.createElement('style');
        style.textContent = '@keyframes gps-pulse-anim { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } } .gps-pulse { animation: gps-pulse-anim 2s infinite; }';
        document.head.appendChild(style);
    }

    function updateGpsStatus(status, isError, extraInfo) {
        if (!gpsStatusDiv) createGpsStatusIndicator();
        gpsStatusDiv.style.background = isError ? '#ef4444' : '#22c55e';
        var icon = isError ? '⚠️' : '<span class="gps-pulse">🛰️</span>';
        var html = icon + ' ' + status;
        if (extraInfo) {
            html += '<span style="margin-left: 0.5rem; font-size: 0.75rem; opacity: 0.8;">' + extraInfo + '</span>';
        }
        gpsStatusDiv.innerHTML = html;
    }

    function haversineMeters(lat1, lng1, lat2, lng2) {
        var R = 6371000; // meters
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    // Update driver marker on map in real-time (use the main map script's function)
    function updateDriverMarkerOnMap(lat, lng) {
        // Use the main map script's function if available
        if (window.OSRH && window.OSRH.updateDriverMarker) {
            window.OSRH.updateDriverMarker(lat, lng);
        }
    }

    function sendLocation(lat, lng, forceUpdate) {
        var now = Date.now();
        
        if (!forceUpdate && !firstPayload) {
            if (now - lastSentAt < minIntervalMs) return;
            if (lastLat !== null && lastLng !== null) {
                var moved = haversineMeters(lastLat, lastLng, lat, lng);
                if (moved < minDistanceMeters) {
                    // Still update map marker even if not sending to server
                    updateDriverMarkerOnMap(lat, lng);
                    return;
                }
            }
        }

        lastSentAt = now;
        lastLat = lat;
        lastLng = lng;
        firstPayload = false;
        lastUpdateTime = new Date();

        // Update map marker immediately
        updateDriverMarkerOnMap(lat, lng);

        fetch('../api/update_driver_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ latitude: lat, longitude: lng })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var timeStr = new Date().toLocaleTimeString();
                updateGpsStatus('GPS Active', false, timeStr);
            } else {
                updateGpsStatus('Sync Error', true);
            }
        })
        .catch(function(err) {
            console.warn('Failed to send location', err);
            updateGpsStatus('Connection Error', true);
        });
    }

    function startWatch() {
        if (watchId !== null) return;
        
        createGpsStatusIndicator();
        updateGpsStatus('Getting location...', false);
        
        // Use watchPosition for continuous real-time tracking
        watchId = navigator.geolocation.watchPosition(function(pos) {
            sendLocation(pos.coords.latitude, pos.coords.longitude, false);
        }, function(err) {
            console.warn('Geolocation error', err);
            var msg = 'GPS Error';
            if (err.code === 1) msg = 'GPS Permission Denied';
            else if (err.code === 2) msg = 'GPS Unavailable';
            else if (err.code === 3) msg = 'GPS Timeout';
            updateGpsStatus(msg, true);
            
            // Try to restart after error
            setTimeout(function() {
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                startWatch();
            }, 5000);
        }, {
            enableHighAccuracy: true,
            maximumAge: 2000, // Accept positions up to 2 seconds old
            timeout: 10000
        });
        
        // Also send periodic updates even if position hasn't changed (keep-alive)
        setInterval(function() {
            if (lastLat !== null && lastLng !== null) {
                var timeSinceUpdate = Date.now() - lastSentAt;
                if (timeSinceUpdate > 15000) { // Force update every 15 seconds
                    sendLocation(lastLat, lastLng, true);
                }
            }
        }, 15000);
    }

    function stopWatch() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
    }

    // Keep tracking active even when page is in background (for mobile)
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            startWatch();
        }
        // Don't stop on hidden - keep tracking for real-time updates
    });

    // Listen for GPS enabled event from modal
    window.addEventListener('gps-enabled', function() {
        console.log('GPS enabled event received - starting continuous tracking');
        startWatch();
    });

    // Auto-start if no modal or already dismissed
    var modal = document.getElementById('gps-modal');
    if (!modal || modal.style.display === 'none') {
        startWatch();
    }
    
    // Also start if modal doesn't exist at all (returning to page)
    setTimeout(function() {
        if (!modal) {
            startWatch();
        }
    }, 1000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
