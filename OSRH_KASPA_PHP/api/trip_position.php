<?php
/**
 * API Endpoint: Get Trip Position (In-Trip Tracking)
 * 
 * Returns the current simulated position during an active trip.
 * Used for live tracking when the trip is in_progress.
 * 
 * GET Parameters:
 *   - trip_id: The trip ID to track
 * 
 * POST Parameters (optional):
 *   - action: 'start_trip', 'complete_trip', 'update_speed'
 *   - speed_multiplier: For speed updates (0.5 to 100)
 *   - estimated_duration_sec: For starting trip
 *   - route_geometry: JSON route waypoints for accurate tracking
 * 
 * Returns JSON with current position, progress, ETA, etc.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Check if user is logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required'
    ]);
    exit;
}

// Get trip ID from request
$tripId = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if (!$tripId) {
    $tripId = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
}

if (!$tripId || $tripId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid trip ID'
    ]);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'start_trip':
            // Start the trip (driver picked up passenger)
            $user = current_user();
            $driverId = $user['driver']['DriverID'] ?? null;
            
            // For simulation, allow passenger to trigger start too
            if (!$driverId) {
                // Check if passenger owns this trip and use system start
                $passengerId = $user['passenger']['PassengerID'] ?? null;
                if ($passengerId) {
                    // Verify passenger owns this trip
                    $checkStmt = db_call_procedure('dbo.spGetPassengerTripDetails', [$tripId, $passengerId]);
                    if ($checkStmt !== false) {
                        $tripData = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
                        sqlsrv_free_stmt($checkStmt);
                        if ($tripData) {
                            $driverId = $tripData['DriverID'];
                        }
                    }
                }
            }
            
            if (!$driverId) {
                echo json_encode(['success' => false, 'error' => 'Driver ID not found']);
                exit;
            }
            
            $estimatedDurationSec = isset($_POST['estimated_duration_sec']) ? (int)$_POST['estimated_duration_sec'] : null;
            $routeGeometry = $_POST['route_geometry'] ?? null;
            $speedMultiplier = isset($_POST['speed_multiplier']) ? (float)$_POST['speed_multiplier'] : 1.0;
            
            $stmt = db_call_procedure('dbo.spStartTrip', [
                $tripId,
                $driverId,
                $estimatedDurationSec,
                $routeGeometry,
                $speedMultiplier
            ]);
            
            if ($stmt === false) {
                echo json_encode(['success' => false, 'error' => 'Database error starting trip']);
                exit;
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            echo json_encode([
                'success' => (bool)($result['Success'] ?? false),
                'message' => $result['Message'] ?? 'Unknown error',
                'tripId' => $tripId,
                'tripStartedAt' => isset($result['TripStartedAt']) ? $result['TripStartedAt']->format('c') : null
            ]);
            exit;
            
        case 'complete_trip':
            $user = current_user();
            $driverId = $user['driver']['DriverID'] ?? null;
            $totalDistanceKm = isset($_POST['total_distance_km']) ? (float)$_POST['total_distance_km'] : null;
            
            $stmt = db_call_procedure('dbo.spCompleteTrip', [
                $tripId,
                $driverId,
                $totalDistanceKm
            ]);
            
            if ($stmt === false) {
                echo json_encode(['success' => false, 'error' => 'Database error completing trip']);
                exit;
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            echo json_encode([
                'success' => (bool)($result['Success'] ?? false),
                'message' => $result['Message'] ?? 'Unknown error',
                'tripId' => $tripId,
                'totalDurationSec' => $result['TotalDurationSec'] ?? null,
                'nextSegmentId' => $result['NextSegmentID'] ?? null,
                'journeyCompleted' => (bool)($result['JourneyCompleted'] ?? true)
            ]);
            exit;
            
        case 'update_speed':
            $speedMultiplier = isset($_POST['speed_multiplier']) ? (float)$_POST['speed_multiplier'] : 1.0;
            
            $stmt = db_call_procedure('dbo.spUpdateTripSimulationSpeed', [
                $tripId,
                $speedMultiplier
            ]);
            
            if ($stmt === false) {
                echo json_encode(['success' => false, 'error' => 'Database error updating speed']);
                exit;
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            echo json_encode([
                'success' => (bool)($result['Success'] ?? false),
                'message' => $result['Message'] ?? 'Speed updated',
                'speedMultiplier' => (float)($result['SpeedMultiplier'] ?? $speedMultiplier)
            ]);
            exit;
            
        case 'auto_start':
            // Auto-start trip when driver arrives (simulation mode)
            $estimatedDurationSec = isset($_POST['estimated_duration_sec']) ? (int)$_POST['estimated_duration_sec'] : null;
            $routeGeometry = $_POST['route_geometry'] ?? null;
            
            $stmt = db_call_procedure('dbo.spAutoStartTripOnArrival', [
                $tripId,
                $estimatedDurationSec,
                $routeGeometry
            ]);
            
            if ($stmt === false) {
                echo json_encode(['success' => false, 'error' => 'Database error auto-starting trip']);
                exit;
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            echo json_encode([
                'success' => (bool)($result['Success'] ?? false),
                'message' => $result['Message'] ?? 'Auto-start processed'
            ]);
            exit;
    }
}

// GET request - fetch current trip position

// First, check if this is a real driver trip
$tripInfo = db_fetch_one(
    'SELECT 
        t.TripID,
        t.IsRealDriverTrip,
        t.Status AS TripStatus,
        d.CurrentLatitude,
        d.CurrentLongitude,
        d.LocationUpdatedAt,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng
     FROM dbo.Trip t
     INNER JOIN dbo.Driver d ON d.DriverID = t.DriverID
     INNER JOIN dbo.RideRequest rr ON rr.RideRequestID = t.RideRequestID
     INNER JOIN dbo.Location pl ON pl.LocationID = rr.PickupLocationID
     LEFT JOIN dbo.Location dl ON dl.LocationID = rr.DropoffLocationID
     WHERE t.TripID = ?',
    [$tripId]
);

$isRealDriverTrip = (bool)($tripInfo['IsRealDriverTrip'] ?? false);

// For REAL DRIVER trips, only use live GPS - no simulation
if ($isRealDriverTrip) {
    $pickupLat = (float)($tripInfo['PickupLat'] ?? 35.1667);
    $pickupLng = (float)($tripInfo['PickupLng'] ?? 33.3667);
    $dropoffLat = (float)($tripInfo['DropoffLat'] ?? 35.1667);
    $dropoffLng = (float)($tripInfo['DropoffLng'] ?? 33.3667);
    $tripStatus = $tripInfo['TripStatus'] ?? 'unknown';
    
    // Check for fresh GPS data (within 3 minutes)
    $hasLiveGps = false;
    $currentLat = null;
    $currentLng = null;
    
    if ($tripInfo && $tripInfo['CurrentLatitude'] !== null && $tripInfo['CurrentLongitude'] !== null) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $updatedAt = $tripInfo['LocationUpdatedAt'] ?? null;
        $ageSeconds = 999999;
        
        if ($updatedAt instanceof DateTimeInterface) {
            $ageSeconds = max(0, $now->getTimestamp() - $updatedAt->getTimestamp());
        }
        
        $hasLiveGps = $ageSeconds <= 180; // 3 minute freshness
        if ($hasLiveGps) {
            $currentLat = (float)$tripInfo['CurrentLatitude'];
            $currentLng = (float)$tripInfo['CurrentLongitude'];
        }
    }
    
    if ($hasLiveGps) {
        // Calculate progress based on distance to dropoff
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($dropoffLat - $currentLat);
        $dLng = deg2rad($dropoffLng - $currentLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($currentLat)) * cos(deg2rad($dropoffLat)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $remainingDistanceKm = $earthRadiusKm * $c;
        
        // Calculate total distance for progress
        $dLatTotal = deg2rad($dropoffLat - $pickupLat);
        $dLngTotal = deg2rad($dropoffLng - $pickupLng);
        $aTotal = sin($dLatTotal / 2) ** 2 + cos(deg2rad($pickupLat)) * cos(deg2rad($dropoffLat)) * sin($dLngTotal / 2) ** 2;
        $cTotal = 2 * atan2(sqrt($aTotal), sqrt(1 - $aTotal));
        $totalDistanceKm = $earthRadiusKm * $cTotal;
        
        $progress = $totalDistanceKm > 0 ? 1.0 - ($remainingDistanceKm / $totalDistanceKm) : 0;
        $progressPercent = max(0.0, min(100.0, $progress * 100.0));
        
        // ETA: assume ~36 km/h (10 m/s)
        $remainingSeconds = (int)round(($remainingDistanceKm * 1000) / 10);
        $hasArrived = $remainingDistanceKm <= 0.05; // within 50m
        
        if ($hasArrived) {
            $remainingSeconds = 0;
            $progressPercent = 100.0;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'tripId' => $tripId,
                'tripStatus' => $tripStatus,
                'currentLat' => $currentLat,
                'currentLng' => $currentLng,
                'pickupLat' => $pickupLat,
                'pickupLng' => $pickupLng,
                'dropoffLat' => $dropoffLat,
                'dropoffLng' => $dropoffLng,
                'progressPercent' => $progressPercent,
                'remainingSeconds' => $remainingSeconds,
                'hasArrived' => $hasArrived,
                'speedMultiplier' => 1.0,
                'simulationStatus' => 'live',
                'isLive' => true,
                'isRealDriverTrip' => true,
                'routeCoordinates' => null
            ],
            'timestamp' => date('c')
        ]);
        exit;
    } else {
        // Waiting for GPS
        echo json_encode([
            'success' => true,
            'data' => [
                'tripId' => $tripId,
                'tripStatus' => $tripStatus,
                'currentLat' => null,
                'currentLng' => null,
                'pickupLat' => $pickupLat,
                'pickupLng' => $pickupLng,
                'dropoffLat' => $dropoffLat,
                'dropoffLng' => $dropoffLng,
                'progressPercent' => 0,
                'remainingSeconds' => null,
                'hasArrived' => false,
                'speedMultiplier' => 1.0,
                'simulationStatus' => 'waiting_for_gps',
                'isLive' => false,
                'isRealDriverTrip' => true,
                'waitingForDriverGps' => true,
                'message' => 'Waiting for driver GPS...',
                'routeCoordinates' => null
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }
}

// SIMULATED trips - use simulation stored procedure
$stmt = db_call_procedure('dbo.spGetSimulatedTripPosition', [$tripId]);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error getting trip position'
    ]);
    exit;
}

$position = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$position) {
    // Fallback to tracking status
    $stmt = db_call_procedure('dbo.spGetTripTrackingStatus', [$tripId]);
    if ($stmt !== false) {
        $trackingStatus = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($trackingStatus) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'tripId' => $tripId,
                    'tripStatus' => $trackingStatus['TripStatus'] ?? 'unknown',
                    'trackingPhase' => $trackingStatus['TrackingPhase'] ?? 'unknown',
                    'currentLat' => (float)($trackingStatus['PickupLat'] ?? 35.1667),
                    'currentLng' => (float)($trackingStatus['PickupLng'] ?? 33.3667),
                    'pickupLat' => (float)($trackingStatus['PickupLat'] ?? 35.1667),
                    'pickupLng' => (float)($trackingStatus['PickupLng'] ?? 33.3667),
                    'dropoffLat' => (float)($trackingStatus['DropoffLat'] ?? 35.1667),
                    'dropoffLng' => (float)($trackingStatus['DropoffLng'] ?? 33.3667),
                    'progressPercent' => 0,
                    'remainingSeconds' => 0,
                    'hasArrived' => false,
                    'speedMultiplier' => 1.0,
                    'simulationStatus' => 'no_position_data'
                ]
            ]);
            exit;
        }
    }
    
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Trip not found'
    ]);
    exit;
}

// Parse route geometry if available
$routeCoordinates = null;
if (!empty($position['RouteGeometry'])) {
    $routeCoordinates = json_decode($position['RouteGeometry'], true);
}

// Format response
$response = [
    'success' => true,
    'data' => [
        'tripId' => (int)$position['TripID'],
        'tripStatus' => $position['TripStatus'] ?? 'unknown',
        'currentLat' => (float)$position['CurrentLat'],
        'currentLng' => (float)$position['CurrentLng'],
        'pickupLat' => (float)$position['PickupLat'],
        'pickupLng' => (float)$position['PickupLng'],
        'dropoffLat' => (float)$position['DropoffLat'],
        'dropoffLng' => (float)$position['DropoffLng'],
        'progressPercent' => (float)$position['ProgressPercent'],
        'remainingSeconds' => (int)$position['RemainingSeconds'],
        'elapsedSeconds' => (int)($position['ElapsedSeconds'] ?? 0),
        'hasArrived' => (bool)$position['HasArrived'],
        'speedMultiplier' => (float)($position['SpeedMultiplier'] ?? 1.0),
        'simulationStatus' => $position['SimulationStatus'] ?? 'unknown',
        'routeCoordinates' => $routeCoordinates
    ],
    'timestamp' => date('c')
];

echo json_encode($response);
