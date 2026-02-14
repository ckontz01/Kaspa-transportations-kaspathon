<?php
/**
 * API: Get autonomous ride position
 * Returns current vehicle position and ride status for live tracking
 * Also simulates vehicle movement along the route
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$rideId = isset($_GET['ride_id']) ? (int)$_GET['ride_id'] : 0;

if ($rideId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ride ID']);
    exit;
}

// Handle speed change request
if (isset($_GET['set_speed'])) {
    $newSpeed = max(1, min(50, (float)$_GET['set_speed']));
    
    // Base speed factor must match the one used in simulation calculation
    // 1.0 = real-time (1:1), so 50x multiplier = 50x real-time
    $baseSpeedFactor = 1.0;
    
    // Update speed multiplier using stored procedure
    $stmt = db_call_procedure('dbo.spUpdateAVRideSpeed', [$rideId, $newSpeed, $baseSpeedFactor]);
    
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
        echo json_encode(['success' => true, 'speed' => $newSpeed, 'message' => 'Speed set to ' . $newSpeed . 'x']);
    } else {
        $errors = db_last_errors();
        echo json_encode(['success' => false, 'error' => 'Failed to update speed', 'details' => $errors]);
    }
    exit;
}

try {
    // Get ride simulation data
    $stmt = db_call_procedure('dbo.spGetAutonomousRideSimulationData', [$rideId]);
    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to load ride data']);
        exit;
    }
    
    $ride = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$ride) {
        echo json_encode(['success' => false, 'error' => 'Ride not found']);
        exit;
    }
    
    $status = strtolower($ride['Status'] ?? '');
    
    // If ride is completed or cancelled, just return current state
    if (in_array($status, ['completed', 'cancelled'])) {
        echo json_encode([
            'success' => true,
            'ride_id' => $rideId,
            'status' => $status,
            'vehicle_lat' => $ride['VehicleCurrentLat'] !== null ? (float)$ride['VehicleCurrentLat'] : null,
            'vehicle_lng' => $ride['VehicleCurrentLng'] !== null ? (float)$ride['VehicleCurrentLng'] : null,
            'phase' => null,
            'eta_seconds' => 0,
            'progress' => 100
        ]);
        exit;
    }
    
    // Simulation logic - calculate current position based on elapsed time
    $phase = $ride['SimulationPhase'] ?? 'pickup';
    $speedMultiplier = (float)($ride['SimulationSpeedMultiplier'] ?? 1.0);
    $accumulatedSeconds = (float)($ride['AccumulatedSimulatedSeconds'] ?? 0);
    
    // Base speed factor: 1.0 = real-time (1:1)
    // 1x = actual route duration, 50x = 50 times faster
    $baseSpeedFactor = 1.0;
    
    // Get route geometry based on phase
    $routeGeometry = null;
    $totalDurationSec = 0;
    $startLat = null;
    $startLng = null;
    $endLat = null;
    $endLng = null;
    
    if ($phase === 'pickup') {
        $routeGeometry = $ride['PickupRouteGeometry'];
        $totalDurationSec = (int)($ride['EstimatedPickupDurationSec'] ?? 300);
        $startLat = (float)$ride['VehicleStartLat'];
        $startLng = (float)$ride['VehicleStartLng'];
        $endLat = (float)$ride['PickupLat'];
        $endLng = (float)$ride['PickupLng'];
        $simStartTime = $ride['VehicleDispatchedAt'] ?? $ride['SimulationStartTime'];
    } else {
        $routeGeometry = $ride['TripRouteGeometry'];
        $totalDurationSec = (int)($ride['EstimatedTripDurationSec'] ?? 600);
        $startLat = (float)$ride['PickupLat'];
        $startLng = (float)$ride['PickupLng'];
        $endLat = (float)$ride['DropoffLat'];
        $endLng = (float)$ride['DropoffLng'];
        $simStartTime = $ride['TripStartedAt'] ?? $ride['SimulationStartTime'];
    }
    
    // Calculate elapsed time since last speed change (or phase start time)
    $lastSpeedChangeAt = $ride['LastSpeedChangeAt'] ?? null;
    $referenceTime = $lastSpeedChangeAt ?? $simStartTime ?? $ride['RequestedAt'];
    
    $elapsedSeconds = 0;
    if ($referenceTime) {
        if ($referenceTime instanceof DateTime) {
            $elapsedSeconds = max(0, time() - $referenceTime->getTimestamp());
        } else {
            $elapsedSeconds = max(0, time() - strtotime($referenceTime));
        }
    }
    
    // Debug logging
    error_log("Ride $rideId: phase=$phase, speedMultiplier=$speedMultiplier, elapsed=$elapsedSeconds, accumulated=$accumulatedSeconds");
    
    // Apply speed multiplier AND base speed factor to get simulated time
    // Total effective speed = baseSpeedFactor * speedMultiplier
    // At 1x: 10x real-time, at 2x: 20x real-time, etc.
    $effectiveSpeed = $baseSpeedFactor * $speedMultiplier;
    $simulatedSeconds = $accumulatedSeconds + ($elapsedSeconds * $effectiveSpeed);
    
    // Calculate progress (0 to 1)
    $progress = min(1.0, $simulatedSeconds / max(1, $totalDurationSec));
    
    // Calculate remaining ETA (accounting for effective speed)
    $remainingSeconds = max(0, ($totalDurationSec - $simulatedSeconds) / $effectiveSpeed);
    
    // Interpolate position along route
    $currentLat = $startLat;
    $currentLng = $startLng;
    
    if ($routeGeometry && $progress > 0 && $progress < 1) {
        try {
            $geom = is_string($routeGeometry) ? json_decode($routeGeometry, true) : $routeGeometry;
            if ($geom && isset($geom['coordinates']) && count($geom['coordinates']) > 1) {
                $coords = $geom['coordinates'];
                $totalPoints = count($coords);
                
                // Find position along the route based on progress
                $targetIndex = min($totalPoints - 1, floor($progress * ($totalPoints - 1)));
                $nextIndex = min($totalPoints - 1, $targetIndex + 1);
                
                // Interpolate between two points
                $localProgress = ($progress * ($totalPoints - 1)) - $targetIndex;
                
                $lat1 = $coords[$targetIndex][1];
                $lng1 = $coords[$targetIndex][0];
                $lat2 = $coords[$nextIndex][1];
                $lng2 = $coords[$nextIndex][0];
                
                $currentLat = $lat1 + ($lat2 - $lat1) * $localProgress;
                $currentLng = $lng1 + ($lng2 - $lng1) * $localProgress;
            }
        } catch (Exception $e) {
            // Fallback to linear interpolation
            $currentLat = $startLat + ($endLat - $startLat) * $progress;
            $currentLng = $startLng + ($endLng - $startLng) * $progress;
        }
    } elseif ($progress >= 1) {
        $currentLat = $endLat;
        $currentLng = $endLng;
    }
    
    // Update vehicle location in database
    $updateStmt = db_call_procedure('dbo.spUpdateAutonomousVehicleLocation', [
        (int)$ride['AutonomousVehicleID'],
        $currentLat,
        $currentLng,
        $rideId,
        $phase
    ]);
    if ($updateStmt !== false) {
        sqlsrv_free_stmt($updateStmt);
    }
    
    // Check for phase transitions
    $newStatus = $status;
    
    if ($phase === 'pickup' && $progress >= 1) {
        // Vehicle arrived at pickup
        if ($status === 'vehicle_dispatched' || $status === 'requested') {
            // First arrival - set status to vehicle_arrived
            $statusStmt = db_call_procedure('dbo.spUpdateAutonomousRideStatus', [$rideId, 'vehicle_arrived']);
            if ($statusStmt !== false) {
                sqlsrv_free_stmt($statusStmt);
            }
            $newStatus = 'vehicle_arrived';
        } elseif ($status === 'vehicle_arrived') {
            // Already arrived - after brief boarding delay, start the trip
            // Use 3 seconds simulated time as boarding delay
            $boardingDelaySimulated = 3 * $effectiveSpeed;
            $extraTime = $simulatedSeconds - $totalDurationSec;
            
            if ($extraTime >= $boardingDelaySimulated) {
                // Start the trip - update status and phase
                $statusStmt = db_call_procedure('dbo.spUpdateAutonomousRideStatus', [$rideId, 'in_progress']);
                if ($statusStmt !== false) {
                    sqlsrv_free_stmt($statusStmt);
                }
                
                // Update phase to 'trip' and reset accumulated time for new phase using stored procedure
                $phaseStmt = db_call_procedure('dbo.spUpdateAVRidePhase', [$rideId, 'trip', 1]);
                if ($phaseStmt !== false) {
                    sqlsrv_free_stmt($phaseStmt);
                }
                
                $newStatus = 'in_progress';
            }
        }
    } elseif ($phase === 'trip' && $progress >= 1) {
        // Arrived at destination - complete the ride
        if (!in_array($status, ['completed', 'cancelled'])) {
            $completeStmt = db_call_procedure('dbo.spCompleteAutonomousRide', [
                $rideId,
                $ride['EstimatedTripDistanceKm'],
                $ride['EstimatedTripDurationSec']
            ]);
            if ($completeStmt !== false) {
                sqlsrv_free_stmt($completeStmt);
            }
            $newStatus = 'completed';
        }
    }
    
    echo json_encode([
        'success' => true,
        'ride_id' => $rideId,
        'status' => $newStatus,
        'vehicle_lat' => round($currentLat, 6),
        'vehicle_lng' => round($currentLng, 6),
        'phase' => $phase,
        'eta_seconds' => round($remainingSeconds),
        'progress' => round($progress * 100, 1),
        'speed_multiplier' => $speedMultiplier,
        'effective_speed' => $effectiveSpeed,
        'simulated_seconds' => round($simulatedSeconds),
        'accumulated_seconds' => round($accumulatedSeconds),
        'elapsed_seconds' => $elapsedSeconds,
        'total_duration_sec' => $totalDurationSec,
        'debug' => [
            'base_speed_factor' => $baseSpeedFactor,
            'db_speed_multiplier' => $speedMultiplier,
            'elapsed_since_ref' => $elapsedSeconds,
            'accumulated_from_db' => $accumulatedSeconds,
            'new_time_contribution' => $elapsedSeconds * $effectiveSpeed,
            'total_simulated' => $simulatedSeconds
        ]
    ]);
    
} catch (Exception $e) {
    error_log('autonomous_ride_position.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to get ride position']);
}
