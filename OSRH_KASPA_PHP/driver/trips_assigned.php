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

$user      = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}

$driverId = (int)$driverRow['DriverID'];

$errors = [];

// Check if driver has an active trip
$hasActiveTrip = false;
$stmt = db_call_procedure('dbo.spCheckDriverHasActiveTrip', [$driverId]);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $hasActiveTrip = (bool)($row['HasActiveTrip'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Handle POST: accept ride request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept_request'])) {
    // Block if driver has active trip
    if ($hasActiveTrip) {
        flash_add('error', 'You cannot accept new ride requests while you have an active trip. Complete or cancel your current trip first.');
        redirect('driver/trips_assigned.php');
        exit;
    }
    
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $rideRequestId = (int)array_get($_POST, 'ride_request_id', 0);
        $vehicleId = (int)array_get($_POST, 'vehicle_id', 0);
        
        if ($rideRequestId && $vehicleId) {
            $resultStmt = db_call_procedure('dbo.spDriverAcceptRideRequest', [$driverId, $rideRequestId, $vehicleId]);
            
            if ($resultStmt !== false) {
                $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($resultStmt);
                
                if (!empty($result['Success'])) {
                    $newTripId = $result['TripID'] ?? 0;
                    $isRealTrip = !empty($result['IsRealDriverTrip']);
                    
                    // If SP didn't return TripID, query for it directly
                    if (!$newTripId) {
                        $tripRow = db_fetch_one(
                            'SELECT TripID FROM dbo.Trip WHERE RideRequestID = ? AND DriverID = ?',
                            [$rideRequestId, $driverId]
                        );
                        if ($tripRow) {
                            $newTripId = (int)$tripRow['TripID'];
                        }
                    }
                    
                    // Direct query to check if driver uses GPS (always reliable)
                    $useGpsRow = db_fetch_one('SELECT UseGPS FROM dbo.Driver WHERE DriverID = ?', [$driverId]);
                    $driverUsesGPS = ($useGpsRow && !empty($useGpsRow['UseGPS']));
                    
                    // Also check IsRealDriverTrip from the Trip record if SP didn't return it
                    if (!$isRealTrip && $newTripId) {
                        $tripCheck = db_fetch_one('SELECT IsRealDriverTrip FROM dbo.Trip WHERE TripID = ?', [$newTripId]);
                        if ($tripCheck && !empty($tripCheck['IsRealDriverTrip'])) {
                            $isRealTrip = true;
                        }
                    }
                    
                    // ALWAYS redirect to trip detail page after accepting
                    if ($newTripId) {
                        $redirectUrl = 'driver/trip_detail.php?trip_id=' . urlencode((string)$newTripId);
                        if ($isRealTrip || $driverUsesGPS) {
                            $redirectUrl .= '&enable_gps=1&auto_start=1';
                        }
                        flash_add('success', 'Ride request accepted!');
                        redirect($redirectUrl);
                    } else {
                        flash_add('success', 'Ride request accepted!');
                        redirect('driver/trips_assigned.php');
                    }
                } else {
                    $errorMsg = $result['ErrorMessage'] ?? 'Failed to accept ride request.';
                    flash_add('error', $errorMsg);
                }
            } else {
                flash_add('error', 'Failed to accept ride request.');
            }
        } else {
            flash_add('error', 'Invalid request or vehicle selection.');
        }
    }
}

// Handle POST: accept segment request (multi-vehicle journey)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept_segment'])) {
    // Block if driver has active trip
    if ($hasActiveTrip) {
        flash_add('error', 'You cannot accept new segment requests while you have an active trip. Complete or cancel your current trip first.');
        redirect('driver/trips_assigned.php');
        exit;
    }
    
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $segmentId = (int)array_get($_POST, 'segment_id', 0);
        $vehicleId = (int)array_get($_POST, 'vehicle_id', 0);
        
        if ($segmentId && $vehicleId) {
            $resultStmt = db_call_procedure('dbo.spDriverAcceptSegmentRequest', [$driverId, $segmentId, $vehicleId]);
            
            if ($resultStmt !== false) {
                $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($resultStmt);
                
                if (!empty($result['Success'])) {
                    $tripId = $result['TripID'] ?? 0;
                    $segmentOrder = $result['SegmentOrder'] ?? 1;
                    flash_add('success', "Segment {$segmentOrder} accepted! Trip #{$tripId} created. Head to the transfer point to pick up the passenger.");
                    redirect('driver/trips_assigned.php');
                } else {
                    $errorMsg = $result['ErrorMessage'] ?? 'Failed to accept segment request.';
                    flash_add('error', $errorMsg);
                }
            } else {
                flash_add('error', 'Failed to accept segment request. Database error.');
            }
        } else {
            flash_add('error', 'Invalid segment or vehicle selection.');
        }
    }
}

// Handle POST: conclude real driver trip (simplified flow without tracking)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['conclude_real_trip'])) {
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $tripIdRaw = array_get($_POST, 'trip_id', null);
        
        if ($tripIdRaw !== null && ctype_digit((string)$tripIdRaw)) {
            $tripId = (int)$tripIdRaw;
            
            $resultStmt = db_call_procedure('dbo.spDriverConcludeRealTrip', [$driverId, $tripId]);
            
            if ($resultStmt !== false) {
                $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($resultStmt);
                
                if (!empty($result['Success'])) {
                    flash_add('success', "Trip completed.");
                    redirect('driver/trips_assigned.php');
                } else {
                    $errorMsg = $result['ErrorMessage'] ?? 'Failed to conclude trip.';
                    flash_add('error', $errorMsg);
                }
            } else {
                flash_add('error', 'Failed to conclude trip. Database error.');
            }
        } else {
            flash_add('error', 'Invalid trip ID.');
        }
    }
}

// Handle POST: update trip status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $tripIdRaw     = array_get($_POST, 'trip_id', null);
        $newStatusRaw  = trim((string)array_get($_POST, 'new_status', ''));
        $allowedStatus = ['in_progress', 'completed', 'cancelled'];

        if ($tripIdRaw === null || !ctype_digit((string)$tripIdRaw)) {
            $errors['general'] = 'Invalid trip.';
        } else {
            $tripId = (int)$tripIdRaw;
        }

        if ($newStatusRaw === '' || !in_array($newStatusRaw, $allowedStatus, true)) {
            $errors['general'] = 'Invalid status change.';
        }

        // Distance and duration will be calculated automatically by the system
        $distanceKm = null;
        $durationMin = null;

        // Check that trip belongs to this driver
        if (!$errors) {
            $tripStmt = db_call_procedure('dbo.spDriverGetTripForValidation', [$driverId, $tripId]);
            $tripRow = null;
            if ($tripStmt !== false) {
                $tripRow = sqlsrv_fetch_array($tripStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($tripStmt);
            }

            if (!$tripRow) {
                $errors['general'] = 'Trip not found for this driver.';
            } else {
                $currentStatus = (string)$tripRow['Status'];

                // Simple state machine: what transitions are allowed
                $allowedTransitions = [
                    'assigned'   => ['in_progress', 'cancelled'],
                    'dispatched' => ['in_progress', 'cancelled'],
                    'in_progress'=> ['completed', 'cancelled'],
                    'completed'  => [],
                    'cancelled'  => [],
                ];

                $lowerNew    = $newStatusRaw;
                $lowerCur    = strtolower($currentStatus);

                if (!isset($allowedTransitions[$lowerCur]) ||
                    !in_array($lowerNew, $allowedTransitions[$lowerCur], true)) {
                    $errors['general'] = 'This status change is not allowed from the current state.';
                }
            }
        }

        if (!$errors) {
            // Use stored procedure to update trip status
            $durationSec = null;
            if ($durationMin !== null) {
                $durationSec = (int)($durationMin * 60);
            }
            
            $stmt = db_call_procedure('dbo.spDriverUpdateTripStatus', [
                $driverId,
                $tripId,
                $newStatusRaw,
                $distanceKm,
                $durationMin
            ]);
            
            if ($stmt === false) {
                $ok = false;
            } else {
                $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                $ok = ($result && !empty($result['Success']));
                if (!$ok && isset($result['ErrorMessage'])) {
                    $errors['general'] = $result['ErrorMessage'];
                }
            }

            if (!$ok) {
                $errors['general'] = 'Could not update trip status. Please try again.';
            } else {
                if ($newStatusRaw === 'completed') {
                    flash_add('success', 'Trip marked as completed.');
                    redirect('driver/trips_assigned.php');
                } elseif ($newStatusRaw === 'in_progress') {
                    flash_add('success', 'Trip started! GPS tracking is active.');
                    // Redirect to trip detail page with GPS enabled when starting trip
                    redirect('driver/trip_detail.php?trip_id=' . urlencode((string)$tripId) . '&enable_gps=1&auto_start=1');
                } else {
                    flash_add('success', 'Trip cancelled.');
                    redirect('driver/trips_assigned.php');
                }
            }
        }
    }
}

// Get driver availability and verification status
$driverInfoStmt = db_call_procedure('dbo.spDriverGetAvailability', [$driverId]);
$driverInfo = null;
if ($driverInfoStmt !== false) {
    $driverInfo = sqlsrv_fetch_array($driverInfoStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($driverInfoStmt);
}
$isAvailable = !empty($driverInfo) ? (bool)$driverInfo['IsAvailable'] : false;
$verificationStatus = !empty($driverInfo) ? (string)$driverInfo['VerificationStatus'] : '';

// Check session override for availability
if (isset($_SESSION['osrh_driver_temp_settings']['is_available'])) {
    $isAvailable = (bool)$_SESSION['osrh_driver_temp_settings']['is_available'];
}

// Get available ride requests within driver's geofence
$availableRequests = [];

if ($isAvailable && $verificationStatus === 'approved') {
    // Get regular ride requests
    $requestsStmt = db_call_procedure('dbo.spDriverGetAvailableRideRequests', [$driverId]);
    
    if ($requestsStmt !== false) {
        while ($row = sqlsrv_fetch_array($requestsStmt, SQLSRV_FETCH_ASSOC)) {
            $availableRequests[] = $row;
        }
        sqlsrv_free_stmt($requestsStmt);
    } else {
        // Check for SQL errors
        $sqlErrors = sqlsrv_errors();
        if ($sqlErrors) {
            $errors['general'] = 'Database error loading requests: ' . $sqlErrors[0]['message'];
        }
    }
    
    // Check if geofence exists
    if (empty($availableRequests) && !isset($errors['general'])) {
        $geofenceStmt = db_call_procedure('dbo.spDriverGetGeofence', [$driverId]);
        if ($geofenceStmt !== false) {
            $geofenceData = sqlsrv_fetch_array($geofenceStmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($geofenceStmt);
            if (empty($geofenceData)) {
                // Driver has no geofence set
                $availableRequests = null; // Will show "set geofence" message
            }
        }
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

// Fetch active trips (assigned or in_progress) using stored procedure
$activeTripsStmt = db_call_procedure('dbo.spDriverListTrips', [$driverId]);
$allTrips = [];
if ($activeTripsStmt !== false) {
    while ($row = sqlsrv_fetch_array($activeTripsStmt, SQLSRV_FETCH_ASSOC)) {
        $allTrips[] = $row;
    }
    sqlsrv_free_stmt($activeTripsStmt);
    
    // Debug: log first trip to see segment data
    if (!empty($allTrips)) {
        error_log("First trip data: " . print_r($allTrips[0], true));
    }
} else {
    // Check for SQL errors
    $sqlErrors = sqlsrv_errors();
    if ($sqlErrors) {
        $errors['general'] = 'Database error: ' . $sqlErrors[0]['message'];
    }
}

// Separate active and history trips
$activeTrips = [];
foreach ($allTrips as $trip) {
    if (in_array(strtolower($trip['Status'] ?? ''), ['assigned', 'dispatched', 'in_progress'])) {
        $activeTrips[] = $trip;
    }
}

// Filter history trips (completed or cancelled)
$historyTrips = [];
foreach ($allTrips as $trip) {
    if (in_array(strtolower($trip['Status']), ['completed', 'cancelled'])) {
        $historyTrips[] = $trip;
    }
}

function osrh_format_dt_driver($value, bool $withTime = true): string
{
    if ($value instanceof DateTimeInterface) {
        return $withTime ? $value->format('Y-m-d H:i') : $value->format('Y-m-d');
    }
    if ($value instanceof DateTime) {
        return $withTime ? $value->format('Y-m-d H:i') : $value->format('Y-m-d');
    }
    if (!$value) {
        return '';
    }
    return (string)$value;
}

$pageTitle = 'Trips';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1080px;">

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-top: 0.8rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['metrics'])): ?>
        <div class="flash flash-error" style="margin-top: 0.8rem;">
            <span class="flash-text"><?php echo e($errors['metrics']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Available Segments Section -->
    <?php if ($isAvailable && $verificationStatus === 'approved'): 
        // Check if driver has any active segment trips
        $hasActiveSegmentTrip = false;
        foreach ($activeTrips as $trip) {
            if (($trip['Status'] === 'dispatched' || $trip['Status'] === 'in_progress') && !empty($trip['SegmentID'])) {
                $hasActiveSegmentTrip = true;
                break;
            }
        }
        
        // Fetch pending segments for this driver
        $pendingSegments = [];
        try {
            $stmt = db_call_procedure('dbo.spGetPendingSegmentsForDriver', [$driverId]);
            if ($stmt) {
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $pendingSegments[] = $row;
                }
                sqlsrv_free_stmt($stmt);
            }
        } catch (Exception $e) {
            error_log("Error fetching pending segments: " . $e->getMessage());
        }
        
        if (count($pendingSegments) > 0): ?>
        <h3 style="font-size: 0.95rem; margin-top: 0.6rem;">Available Ride Segments</h3>
        <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.8rem;">
            These are multi-vehicle journeys in your service area. Accept a segment to handle one leg of the journey.
        </p>
        
        <?php if ($hasActiveSegmentTrip): ?>
        <div style="background: rgba(255, 193, 7, 0.1); padding: 1rem; border-radius: 4px; margin-bottom: 0.8rem; border: 1px solid rgba(255, 193, 7, 0.3);">
            <p style="margin: 0; font-size: 0.86rem; color: #ffc107;">
                ⚠️ You have an active segment trip. Complete or cancel it before accepting another segment.
            </p>
        </div>
        <?php endif; ?>
        
        <div style="display: grid; gap: 0.8rem; margin-bottom: 1.5rem;">
            <?php 
            // Group segments by RideRequestID
            $groupedSegments = [];
            foreach ($pendingSegments as $segment) {
                $rideRequestID = $segment['RideRequestID'];
                if (!isset($groupedSegments[$rideRequestID])) {
                    $groupedSegments[$rideRequestID] = [];
                }
                $groupedSegments[$rideRequestID][] = $segment;
            }
            
            foreach ($groupedSegments as $rideRequestID => $segments): 
                $firstSegment = $segments[0];
            ?>
            <div class="card" style="padding: 1rem;">
                <div style="margin-bottom: 0.8rem; padding-bottom: 0.8rem; border-bottom: 1px solid #dee2e6;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                Multi-Vehicle Journey #<?php echo e($rideRequestID); ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.84rem;">
                                Passenger: <?php echo e($firstSegment['PassengerName'] ?? 'Unknown'); ?> • 
                                <?php echo e($firstSegment['ServiceTypeName'] ?? 'Standard'); ?> • 
                                <?php echo count($segments); ?> segment<?php echo count($segments) > 1 ? 's' : ''; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php foreach ($segments as $segment): ?>
                <div style="margin-bottom: 1rem; padding: 0.8rem; border-left: 3px solid #007bff; background: transparent;">
                    <div style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: start;">
                        <div>
                            <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                <span style="background: #007bff; color: white; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                    SEGMENT <?php echo e($segment['SegmentOrder'] ?? '?'); ?>
                                </span>
                            </div>
                            
                            <div style="font-size: 0.9rem; margin-bottom: 0.5rem;">
                                <strong><?php 
                                    $fromLoc = $segment['FromLocation'] ?? '';
                                    if (empty($fromLoc)) {
                                        $fromLoc = str_replace('_', ' ', $segment['GeofenceName'] ?? 'Unknown');
                                    }
                                    echo e($fromLoc);
                                ?></strong>
                                <span style="color: #6c757d; margin: 0 0.5rem;">→</span>
                                <strong><?php echo e($segment['ToLocation'] ?? 'Unknown'); ?></strong>
                            </div>
                            
                            <?php if (!empty($segment['GeofenceName'])): ?>
                            <div class="text-muted" style="font-size: 0.8rem; margin-bottom: 0.3rem;">
                                Service Area: <?php echo e($segment['GeofenceName']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div style="display: grid; grid-template-columns: auto auto; gap: 0.8rem; margin-top: 0.5rem; margin-bottom: 0.5rem;">
                                <div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Segment Distance</div>
                                    <div style="font-weight: 600; font-size: 0.9rem;"><?php echo number_format((float)($segment['SegmentDistanceKm'] ?? 0), 1); ?> km</div>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size: 0.75rem;">Your Earnings</div>
                                    <div style="font-weight: 600; font-size: 0.9rem; color: var(--color-success);">€<?php echo number_format((float)($segment['SegmentFare'] ?? $segment['EstimatedDriverPayment'] ?? 0), 2); ?></div>
                                </div>
                            </div>
                            
                            <div class="text-muted" style="font-size: 0.8rem;">
                                Distance to pickup: <?php echo number_format((float)($segment['DistanceToPickupKm'] ?? 0), 1); ?> km
                            </div>
                            
                            <?php if (!empty($segment['FromBridge']) || !empty($segment['ToBridge'])): ?>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; padding-left: 0.8rem; border-left: 2px solid #6c757d; font-size: 0.8rem;">
                                <?php if (!empty($segment['FromBridge'])): ?>
                                <div>Pick up at bridge: <strong><?php echo e($segment['FromBridge']); ?></strong></div>
                                <?php endif; ?>
                                <?php if (!empty($segment['ToBridge'])): ?>
                                <div>Drop off at bridge: <strong><?php echo e($segment['ToBridge']); ?></strong></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <a href="<?php echo e(url('driver/segment_detail.php?segment_id=' . $segment['SegmentID'])); ?>" 
                               class="btn btn-ghost btn-small" style="white-space: nowrap;">
                                View Route
                            </a>
                            <?php if ($hasActiveSegmentTrip): ?>
                                <button type="button" class="btn btn-secondary" style="white-space: nowrap;" disabled>
                                    Accept
                                </button>
                            <?php else: ?>
                                <form method="post" action="<?php echo e(url('driver/accept_segment.php')); ?>">
                                    <input type="hidden" name="segment_id" value="<?php echo e($segment['SegmentID']); ?>">
                                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                                        Accept
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Available Ride Requests Section -->
    <?php if ($isAvailable && $verificationStatus === 'approved'): ?>
        <h3 style="font-size: 0.95rem; margin-top: 0.6rem;">Available Ride Requests</h3>
        
        <?php if ($hasActiveTrip): ?>
        <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 4px; margin-top: 0.6rem; margin-bottom: 0.8rem; border: 1px solid rgba(239, 68, 68, 0.3);">
            <p style="margin: 0; font-size: 0.86rem; color: #ef4444;">
                🚗 <strong>Active Trip In Progress.</strong> You cannot accept new ride requests while you have an active trip. Complete or cancel your current trip to view available requests.
            </p>
        </div>
        <?php else: ?>
        
        <?php if (empty($vehicles)): ?>
        <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 4px; margin-top: 0.6rem; margin-bottom: 0.8rem; border: 1px solid rgba(239, 68, 68, 0.3);">
            <p style="margin: 0; font-size: 0.86rem; color: #ef4444;">
                ⚠️ <strong>No vehicles registered.</strong> You need to <a href="<?php echo e(url('driver/vehicles.php')); ?>" style="color: #4fc3f7; text-decoration: underline;">add a vehicle</a> before you can accept ride requests.
            </p>
        </div>
        <?php endif; ?>
        
        <?php if ($hasActiveSegmentTrip): ?>
        <div style="background: rgba(255, 193, 7, 0.1); padding: 1rem; border-radius: 4px; margin-top: 0.6rem; margin-bottom: 0.8rem; border: 1px solid rgba(255, 193, 7, 0.3);">
            <p style="margin: 0; font-size: 0.86rem; color: #ffc107;">
                ⚠️ You have an active segment trip. Complete or cancel it before accepting regular ride requests.
            </p>
        </div>
        <?php endif; ?>
        
        <?php if ($availableRequests === null): ?>
            <div style="background: rgba(255, 193, 7, 0.1); padding: 1rem; border-radius: 4px; margin-top: 0.6rem; border: 1px solid rgba(255, 193, 7, 0.3);">
                <p style="margin: 0; font-size: 0.86rem; color: #ffc107;">
                    ⚠️ Please <a href="<?php echo e(url('driver/settings.php')); ?>" style="color: #4fc3f7; text-decoration: underline;">set your geofence location</a> to see available ride requests in your area.
                </p>
            </div>
        <?php elseif (empty($availableRequests)): ?>
            <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
                No ride requests available in your area right now.
            </p>
        <?php else: ?>
            <div style="overflow-x: auto; margin-top: 0.6rem; background: rgba(76, 175, 80, 0.08); padding: 1rem; border-radius: 4px; border: 1px solid rgba(76, 175, 80, 0.2);">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Passenger</th>
                            <th>Pickup Location</th>
                            <th>Dropoff Location</th>
                            <th>Distance</th>
                            <th>Total Fare</th>
                            <th>Your Earnings</th>
                            <th>Payment Method</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($availableRequests as $request): ?>
                        <tr>
                            <td>
                                <?php echo e($request['PassengerName'] ?? ''); ?>
                            </td>
                            <td><?php echo e($request['PickupLocation'] ?? ''); ?></td>
                            <td><?php echo e($request['DropoffLocation'] ?? ''); ?></td>
                            <td><?php echo e(number_format((float)($request['DistanceKm'] ?? 0), 2)); ?> km</td>
                            <td><strong>€<?php echo e(number_format((float)($request['EstimatedTotalFare'] ?? 0), 2)); ?></strong></td>
                            <td><strong style="color: var(--color-success);">€<?php echo e(number_format((float)($request['EstimatedTotalFare'] ?? 0), 2)); ?></strong></td>
                            <td>
                                <?php
                                $paymentClass = $request['PaymentMethodClass'] ?? 'unknown';
                                $paymentDisplay = $request['PaymentMethodDisplay'] ?? 'Not specified';
                                if ($paymentClass === 'card') {
                                    echo '<span style="color: #2563eb; font-weight: 500;">' . e($paymentDisplay) . '</span>';
                                } elseif ($paymentClass === 'cash') {
                                    echo '<span style="color: #16a34a; font-weight: 500;">' . e($paymentDisplay) . '</span>';
                                } else {
                                    echo '<span class="text-muted">' . e($paymentDisplay) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.3rem; align-items: center; flex-wrap: wrap;">
                                    <a href="<?php echo e(url('driver/ride_request_detail.php?request_id=' . urlencode((string)$request['RideRequestID']))); ?>"
                                       class="btn btn-small btn-ghost"
                                       style="white-space: nowrap;">
                                        View Details
                                    </a>
                                    <?php if ($hasActiveSegmentTrip): ?>
                                        <button type="button" class="btn btn-small btn-secondary" disabled title="Complete your active segment trip first">
                                            Accept
                                        </button>
                                    <?php elseif (empty($vehicles)): ?>
                                        <a href="<?php echo e(url('driver/vehicles.php')); ?>" class="btn btn-small btn-warning" style="white-space: nowrap;">
                                            Add Vehicle First
                                        </a>
                                    <?php else: ?>
                                    <form method="post" style="display:inline-flex; align-items: center; gap: 0.3rem;">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="accept_request" value="1">
                                        <input type="hidden" name="ride_request_id" value="<?php echo e($request['RideRequestID']); ?>">
                                        <select name="vehicle_id" class="form-control" style="width: 120px; display: inline-block; font-size: 0.8rem; padding: 0.2rem 0.35rem;" required>
                                            <option value="">Select vehicle</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?php echo e($vehicle['VehicleID']); ?>">
                                                    <?php echo e($vehicle['PlateNo'] ?? ($vehicle['Make'] . ' ' . $vehicle['Model'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-small btn-primary">
                                            Accept
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php endif; ?><!-- end of !$hasActiveTrip else -->
    <?php endif; ?>

    <h3 style="font-size: 0.95rem; margin-top: 0.6rem;">Active trips</h3>
    <?php if (empty($activeTrips)): ?>
        <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
            You have no active trips right now.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto; margin-top: 0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Passenger</th>
                        <th>Route</th>
                        <th>Requested at</th>
                        <th>Start</th>
                        <th>Status</th>
                        <th>Total Fare</th>
                        <th>Your Earnings</th>
                        <th style="width: 260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activeTrips as $trip): ?>
                    <tr>
                        <td><?php echo e($trip['PassengerName'] ?? ''); ?></td>
                        <td>
                            <?php if (!empty($trip['IsSegmentTrip'])): ?>
                                <div style="font-size: 0.85rem;">
                                    <span style="background: #007bff; color: white; padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.7rem; margin-right: 0.3rem;">
                                        SEGMENT <?php echo e($trip['SegmentOrder'] ?? ''); ?>
                                    </span>
                                    <div style="margin-top: 0.3rem;">
                                        <strong><?php echo e($trip['SegmentFromLocation'] ?: str_replace('_', ' ', $trip['SegmentGeofenceName'] ?? 'Unknown')); ?></strong>
                                        <span style="color: #6c757d; margin: 0 0.3rem;">→</span>
                                        <strong><?php echo e($trip['SegmentToLocation'] ?? 'Unknown'); ?></strong>
                                    </div>
                                    <?php if (!empty($trip['SegmentFromBridge']) || !empty($trip['SegmentToBridge'])): ?>
                                    <div style="font-size: 0.75rem; color: #6c757d; margin-top: 0.2rem;">
                                        <?php if (!empty($trip['SegmentFromBridge'])): ?>
                                        Pickup: <?php echo e($trip['SegmentFromBridge']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($trip['SegmentToBridge'])): ?>
                                        <?php if (!empty($trip['SegmentFromBridge'])) echo ' | '; ?>
                                        Dropoff: <?php echo e($trip['SegmentToBridge']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.85rem;">Full journey</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(osrh_format_dt_driver($trip['RequestedAt'] ?? null)); ?></td>
                        <td><?php echo e(osrh_format_dt_driver($trip['StartTime'] ?? null)); ?></td>
                        <td><?php echo e($trip['Status']); ?></td>
                        <td>
                            <?php 
                            // For segment trips, show the segment fare
                            $isSegmentTrip = !empty($trip['IsSegmentTrip']);
                            $segmentEstimatedFare = (float)($trip['SegmentEstimatedFare'] ?? 0);
                            
                            if ($isSegmentTrip && $segmentEstimatedFare > 0) {
                                echo '<strong>€' . e(number_format($segmentEstimatedFare, 2)) . '</strong>';
                                if (!empty($trip['SegmentDistanceKm'])) {
                                    echo '<div style="font-size: 0.75rem; color: #6c757d;">~' . number_format((float)$trip['SegmentDistanceKm'], 1) . ' km</div>';
                                }
                            } elseif (!empty($trip['EstimatedCost'])) {
                                echo '<strong>€' . e(number_format((float)$trip['EstimatedCost'], 2)) . '</strong>';
                            } else {
                                echo '<span class="text-muted">-</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            // For segment trips, show payment status and earnings
                            $segmentPaymentState = $trip['SegmentPaymentState'] ?? null;
                            $earningsConfirmed = !empty($trip['EarningsConfirmed']);
                            $estimatedEarnings = (float)($trip['EstimatedDriverEarnings'] ?? 0);
                            
                            if ($isSegmentTrip) {
                                if ($segmentPaymentState === 'payment_completed') {
                                    // Payment completed - show confirmed earnings in green
                                    echo '<strong style="color: var(--color-success);">€' . e(number_format($estimatedEarnings, 2)) . '</strong>';
                                    echo '<div style="font-size: 0.7rem; color: #22c55e;">✓ Paid</div>';
                                } elseif ($segmentPaymentState === 'payment_pending') {
                                    // Payment pending
                                    echo '<span style="color: #6b7280;">€' . e(number_format($estimatedEarnings, 2)) . '</span>';
                                    echo '<div style="font-size: 0.7rem; color: #f59e0b;">⏳ Pending</div>';
                                } else {
                                    // Awaiting payment
                                    echo '<span style="color: #6b7280;">€' . e(number_format($estimatedEarnings, 2)) . '</span>';
                                    echo '<div style="font-size: 0.7rem; color: #6b7280;">Awaiting payment</div>';
                                }
                            } elseif ($estimatedEarnings > 0) {
                                if ($earningsConfirmed) {
                                    echo '<strong style="color: var(--color-success);">€' . e(number_format($estimatedEarnings, 2)) . '</strong>';
                                } else {
                                    echo '<span style="color: #6b7280;">€' . e(number_format($estimatedEarnings, 2)) . '</span>';
                                }
                            } else {
                                echo '<span class="text-muted">-</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.3rem;">
                                <?php $status = strtolower((string)$trip['Status']); ?>
                                <?php $isRealDriverTrip = !empty($trip['IsRealDriverTrip']); ?>

                                <?php if ($isRealDriverTrip): ?>
                                    <!-- Real Driver Trip: Simplified flow -->
                                    <?php if (in_array($status, ['assigned', 'dispatched'])): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <input type="hidden" name="new_status" value="in_progress">
                                            <button type="submit" class="btn btn-small btn-primary">
                                                🚗 Start Trip
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($status === 'in_progress'): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="conclude_real_trip" value="1">
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <button type="submit" class="btn btn-small btn-primary" style="background: #22c55e;">
                                                ✓ Conclude Ride
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($status, ['assigned', 'dispatched'], true)): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <input type="hidden" name="new_status" value="cancelled">
                                            <button type="submit" class="btn btn-small btn-ghost">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Simulated Trip: Regular flow with tracking -->
                                    <?php if (in_array($status, ['assigned', 'dispatched'])): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <input type="hidden" name="new_status" value="in_progress">
                                            <button type="submit" class="btn btn-small btn-primary">
                                                Start trip
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($status === 'in_progress'): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <input type="hidden" name="new_status" value="completed">
                                            <button type="submit" class="btn btn-small btn-primary">
                                                Complete trip
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($status, ['assigned', 'dispatched', 'in_progress'], true)): ?>
                                        <form method="post" style="display:inline;">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="trip_id" value="<?php echo e($trip['TripID']); ?>">
                                            <input type="hidden" name="new_status" value="cancelled">
                                            <button type="submit" class="btn btn-small btn-ghost">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Driver-side trip details -->
                                <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . urlencode((string)$trip['TripID']))); ?>"
                                   class="btn btn-small btn-ghost">
                                    Details
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3 style="font-size: 0.95rem; margin-top: 1.6rem;">History</h3>
    <?php if (empty($historyTrips)): ?>
        <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
            No completed or cancelled trips yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto; margin-top: 0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Passenger</th>
                        <th>Requested at</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Trip Type</th>
                        <th>Status</th>
                        <th>Your Earnings</th>
                        <th style="width: 90px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historyTrips as $trip): ?>
                    <?php 
                    $isSegmentTrip = !empty($trip['IsSegmentTrip']) && $trip['IsSegmentTrip'] == 1;
                    $segmentOrder = $trip['SegmentOrder'] ?? null;
                    $segmentPaymentState = $trip['SegmentPaymentState'] ?? null;
                    $segmentFare = (float)($trip['SegmentEstimatedFare'] ?? 0);
                    $amount = (float)($trip['Amount'] ?? 0);
                    $hasPaymentCompleted = ($isSegmentTrip && $segmentPaymentState === 'payment_completed') || 
                                          (!$isSegmentTrip && strtolower($trip['PaymentStatus'] ?? '') === 'completed');
                    $driverEarnings = $hasPaymentCompleted ? $amount : 0;
                    ?>
                    <tr>
                        <td><?php echo e($trip['PassengerName'] ?? ''); ?></td>
                        <td><?php echo e(osrh_format_dt_driver($trip['RequestedAt'] ?? null)); ?></td>
                        <td><?php echo e(osrh_format_dt_driver($trip['StartTime'] ?? null)); ?></td>
                        <td><?php echo e(osrh_format_dt_driver($trip['EndTime'] ?? null)); ?></td>
                        <td>
                            <?php if ($isSegmentTrip): ?>
                                <span style="font-size: 0.75rem; padding: 0.15rem 0.4rem; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border-radius: 3px;">
                                    Segment <?php echo e($segmentOrder); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">Full trip</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($trip['Status']); ?></td>
                        <td>
                            <?php if ($hasPaymentCompleted): ?>
                                <span style="color: #22c55e; font-weight: 600;">
                                    €<?php echo number_format($driverEarnings, 2); ?>
                                </span>
                                <span style="color: #22c55e; font-size: 0.7rem;"> ✓</span>
                            <?php elseif ($isSegmentTrip): ?>
                                <?php if ($segmentPaymentState === 'payment_pending'): ?>
                                    <span style="color: #f59e0b; font-size: 0.8rem;">⏳ Pending</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.8rem;">Awaiting payment</span>
                                <?php endif; ?>
                            <?php elseif (!empty($trip['Amount'])): ?>
                                <?php
                                $currency = $trip['CurrencyCode'] ?? 'EUR';
                                ?>
                                €<?php echo number_format($amount, 2); ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">No payment</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . urlencode((string)$trip['TripID']))); ?>"
                               class="btn btn-small btn-ghost">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>