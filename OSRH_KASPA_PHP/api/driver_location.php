<?php
/**
 * API Endpoint: Get Simulated Driver Location
 * 
 * Returns the current simulated position of the driver for a trip.
 * Used for live tracking on the passenger ride detail page.
 * 
 * GET Parameters:
 *   - trip_id: The trip ID to track
 * 
 * Returns JSON:
 *   - success: boolean
 *   - data: {currentLat, currentLng, progress, remainingSeconds, hasArrived, ...}
 *   - error: string (if success is false)
 */

declare(strict_types=1);

// Set JSON content type
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Simple haversine distance in kilometers
function osrh_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

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

if (!$tripId || $tripId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid trip ID'
    ]);
    exit;
}

// Verify the user has access to this trip (is the passenger)
$user = current_user();
$passengerId = $user['passenger']['PassengerID'] ?? null;

if (!$passengerId) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Passenger access required'
    ]);
    exit;
}

// Verify trip belongs to this passenger
$stmt = db_call_procedure('dbo.spCheckTripTrackingStatus', [$tripId]);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error checking trip status'
    ]);
    exit;
}

$trackingStatus = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$trackingStatus) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Trip not found'
    ]);
    exit;
}

// -------------------------------------------------------------------------
// LIVE DRIVER POSITION (real GPS)
// For real driver trips: ONLY use real GPS, no simulation fallback
// For simulated trips: Use real GPS if available, otherwise simulation
// -------------------------------------------------------------------------

$liveRow = db_fetch_one(
    'SELECT 
        t.TripID,
        t.DriverID,
        t.Status AS TripStatus,
        t.DriverStartLat,
        t.DriverStartLng,
        t.PickupRouteGeometry,
        t.PickupSpeedMultiplier,
        t.IsRealDriverTrip,
        d.CurrentLatitude,
        d.CurrentLongitude,
        d.LocationUpdatedAt,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng
     FROM dbo.Trip t
     INNER JOIN dbo.Driver d ON d.DriverID = t.DriverID
     INNER JOIN dbo.RideRequest rr ON rr.RideRequestID = t.RideRequestID AND rr.PassengerID = ?
     INNER JOIN dbo.Location pl ON pl.LocationID = rr.PickupLocationID
     LEFT JOIN dbo.Location dl ON dl.LocationID = rr.DropoffLocationID
     WHERE t.TripID = ?;
    ',
    [$passengerId, $tripId]
);

$isRealDriverTrip = (bool)($liveRow['IsRealDriverTrip'] ?? false);

if ($liveRow && $liveRow['CurrentLatitude'] !== null && $liveRow['CurrentLongitude'] !== null) {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $updatedAt = $liveRow['LocationUpdatedAt'] ?? null;
    $ageSeconds = 999999;

    if ($updatedAt instanceof DateTimeInterface) {
        $ageSeconds = max(0, $now->getTimestamp() - $updatedAt->getTimestamp());
    }

    $isFresh = $ageSeconds <= 180; // 3 minutes freshness window

    if ($isFresh) {
        $currentLat = (float)$liveRow['CurrentLatitude'];
        $currentLng = (float)$liveRow['CurrentLongitude'];
        $pickupLat  = (float)($liveRow['PickupLat'] ?? $trackingStatus['PickupLat'] ?? 35.1667);
        $pickupLng  = (float)($liveRow['PickupLng'] ?? $trackingStatus['PickupLng'] ?? 33.3667);
        $dropLat    = (float)($liveRow['DropoffLat'] ?? $trackingStatus['DropoffLat'] ?? 35.1667);
        $dropLng    = (float)($liveRow['DropoffLng'] ?? $trackingStatus['DropoffLng'] ?? 33.3667);

        $pickupDistanceKm = osrh_haversine_km($currentLat, $currentLng, $pickupLat, $pickupLng);

        $startLat = $liveRow['DriverStartLat'] !== null ? (float)$liveRow['DriverStartLat'] : $currentLat;
        $startLng = $liveRow['DriverStartLng'] !== null ? (float)$liveRow['DriverStartLng'] : $currentLng;
        $startDistanceKm = osrh_haversine_km($startLat, $startLng, $pickupLat, $pickupLng);
        if ($startDistanceKm < 0.05) { // avoid zero/near-zero
            $startDistanceKm = max($pickupDistanceKm, 0.05);
        }

        $progress = 1.0 - ($pickupDistanceKm / $startDistanceKm);
        $progressPercent = max(0.0, min(100.0, $progress * 100.0));

        // ETA: assume ~36 km/h (10 m/s) as conservative urban speed
        $remainingSeconds = (int)round(($pickupDistanceKm * 1000) / 10);
        $hasArrived = $pickupDistanceKm <= 0.05; // within ~50m
        if ($hasArrived) {
            $remainingSeconds = 0;
            $progressPercent = 100.0;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'tripId' => (int)$liveRow['TripID'],
                'driverId' => (int)$liveRow['DriverID'],
                'currentLat' => $currentLat,
                'currentLng' => $currentLng,
                'startLat' => $startLat,
                'startLng' => $startLng,
                'pickupLat' => $pickupLat,
                'pickupLng' => $pickupLng,
                'dropoffLat' => $dropLat,
                'dropoffLng' => $dropLng,
                'progressPercent' => $progressPercent,
                'remainingSeconds' => $remainingSeconds,
                'hasArrived' => $hasArrived,
                'tripStatus' => $liveRow['TripStatus'] ?? 'unknown',
                'currentSpeedKmh' => null,
                'isTrackingActive' => true,
                'simulationStatus' => 'live',
                'pickupRouteCoordinates' => null,
                'pickupSpeedMultiplier' => (float)($liveRow['PickupSpeedMultiplier'] ?? 1.0),
                'isLive' => true,
                'isRealDriverTrip' => true,
            ],
            'timestamp' => date('c'),
        ]);
        exit;
    }
}

// -------------------------------------------------------------------------
// For REAL DRIVER trips: Do NOT use simulation - wait for real GPS
// -------------------------------------------------------------------------
if ($isRealDriverTrip) {
    // Real driver trip but no fresh GPS data - return waiting status
    $pickupLat = (float)($liveRow['PickupLat'] ?? $trackingStatus['PickupLat'] ?? 35.1667);
    $pickupLng = (float)($liveRow['PickupLng'] ?? $trackingStatus['PickupLng'] ?? 33.3667);
    $dropLat   = (float)($liveRow['DropoffLat'] ?? $trackingStatus['DropoffLat'] ?? 35.1667);
    $dropLng   = (float)($liveRow['DropoffLng'] ?? $trackingStatus['DropoffLng'] ?? 33.3667);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'tripId' => $tripId,
            'currentLat' => null,
            'currentLng' => null,
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
            'dropoffLat' => $dropLat,
            'dropoffLng' => $dropLng,
            'progressPercent' => 0,
            'remainingSeconds' => null,
            'hasArrived' => false,
            'tripStatus' => $liveRow['TripStatus'] ?? $trackingStatus['TripStatus'] ?? 'unknown',
            'isTrackingActive' => true,
            'simulationStatus' => 'waiting_for_gps',
            'isLive' => false,
            'isRealDriverTrip' => true,
            'waitingForDriverGps' => true,
            'message' => 'Waiting for driver to enable GPS...'
        ],
        'timestamp' => date('c'),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// SIMULATED trips only - use simulation data
// -------------------------------------------------------------------------

// Get simulated driver position
$stmt = db_call_procedure('dbo.spGetSimulatedDriverPosition', [$tripId]);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error getting driver position'
    ]);
    exit;
}

$position = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

if (!$position) {
    // No simulation data - driver may already be at pickup
    echo json_encode([
        'success' => true,
        'data' => [
            'tripId' => $tripId,
            'currentLat' => (float)($trackingStatus['PickupLat'] ?? 35.1667),
            'currentLng' => (float)($trackingStatus['PickupLng'] ?? 33.3667),
            'pickupLat' => (float)($trackingStatus['PickupLat'] ?? 35.1667),
            'pickupLng' => (float)($trackingStatus['PickupLng'] ?? 33.3667),
            'dropoffLat' => (float)($trackingStatus['DropoffLat'] ?? 35.1667),
            'dropoffLng' => (float)($trackingStatus['DropoffLng'] ?? 33.3667),
            'progressPercent' => 100.0,
            'remainingSeconds' => 0,
            'hasArrived' => true,
            'tripStatus' => $trackingStatus['TripStatus'] ?? 'unknown',
            'isTrackingActive' => false,
            'simulationStatus' => 'no_data',
            'pickupRouteCoordinates' => null
        ]
    ]);
    exit;
}

// Parse pickup route geometry if available
$pickupRouteCoordinates = null;
if (!empty($position['PickupRouteGeometry'])) {
    $pickupRouteCoordinates = json_decode($position['PickupRouteGeometry'], true);
}

// Format response
// Handle POST actions for pickup speed control
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_pickup_speed') {
        $speedMultiplier = isset($_POST['speed_multiplier']) ? (float)$_POST['speed_multiplier'] : 1.0;
        
        $stmt = db_call_procedure('dbo.spUpdatePickupSimulationSpeed', [
            $tripId,
            $speedMultiplier
        ]);
        
        if ($stmt === false) {
            echo json_encode(['success' => false, 'error' => 'Database error updating pickup speed']);
            exit;
        }
        
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'success' => (bool)($result['Success'] ?? false),
            'message' => $result['Message'] ?? 'Unknown error',
            'speedMultiplier' => (float)($result['SpeedMultiplier'] ?? 1.0),
            'remainingSeconds' => (int)($result['RemainingSeconds'] ?? 0),
            'progressPercent' => (float)($result['ProgressPercent'] ?? 0)
        ]);
        exit;
    }
}

$response = [
    'success' => true,
    'data' => [
        'tripId' => (int)$position['TripID'],
        'driverId' => (int)($position['DriverID'] ?? 0),
        'currentLat' => (float)$position['CurrentLat'],
        'currentLng' => (float)$position['CurrentLng'],
        'startLat' => (float)($position['StartLat'] ?? $position['CurrentLat']),
        'startLng' => (float)($position['StartLng'] ?? $position['CurrentLng']),
        'pickupLat' => (float)$position['PickupLat'],
        'pickupLng' => (float)$position['PickupLng'],
        'dropoffLat' => (float)($trackingStatus['DropoffLat'] ?? 35.1667),
        'dropoffLng' => (float)($trackingStatus['DropoffLng'] ?? 33.3667),
        'progressPercent' => (float)$position['ProgressPercent'],
        'remainingSeconds' => (int)$position['RemainingSeconds'],
        'hasArrived' => (bool)$position['HasArrived'],
        'tripStatus' => $position['TripStatus'] ?? 'unknown',
        'currentSpeedKmh' => (float)($position['CurrentSpeedKmh'] ?? 0),
        'isTrackingActive' => (bool)($trackingStatus['IsTrackingActive'] ?? false),
        'simulationStatus' => $position['SimulationStatus'] ?? 'unknown',
        'pickupRouteCoordinates' => $pickupRouteCoordinates,
        'pickupSpeedMultiplier' => (float)($position['PickupSpeedMultiplier'] ?? 1.0)
    ],
    'timestamp' => date('c')
];

echo json_encode($response);
