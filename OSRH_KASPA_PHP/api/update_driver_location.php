<?php
/**
 * API Endpoint: Update Driver Location
 * 
 * Called by driver app to update their current GPS position.
 * Should be called:
 *   - When driver goes online
 *   - Every 10-30 seconds while online
 *   - When driver moves significantly
 * 
 * POST Parameters:
 *   - latitude: Driver's current latitude (decimal degrees)
 *   - longitude: Driver's current longitude (decimal degrees)
 *   - available: (optional) 1 to go online, 0 to go offline
 * 
 * Returns JSON:
 *   - success: boolean
 *   - message: status message
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

// Check if user is a driver
$user = current_user();
$driverId = $user['driver']['DriverID'] ?? null;

if (!$driverId) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Driver access required'
    ]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'POST method required'
    ]);
    exit;
}

// Get parameters from POST body (support both form data and JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    // Try form data
    $data = $_POST;
}

$latitude = isset($data['latitude']) ? (float)$data['latitude'] : null;
$longitude = isset($data['longitude']) ? (float)$data['longitude'] : null;
$setAvailable = isset($data['available']) ? (int)$data['available'] : null;

// Validate coordinates
if ($latitude === null || $longitude === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Latitude and longitude are required'
    ]);
    exit;
}

// Validate coordinate ranges
if ($latitude < -90 || $latitude > 90) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Latitude must be between -90 and 90'
    ]);
    exit;
}

if ($longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Longitude must be between -180 and 180'
    ]);
    exit;
}

// Call appropriate stored procedure
if ($setAvailable === 1) {
    // Driver going online with location
    $stmt = db_call_procedure('dbo.spSetDriverAvailable', [
        $driverId,
        $latitude,
        $longitude
    ]);
    $action = 'online';
} elseif ($setAvailable === 0) {
    // Driver going offline
    $stmt = db_call_procedure('dbo.spSetDriverUnavailable', [$driverId]);
    $action = 'offline';
} else {
    // Just updating location
    $stmt = db_call_procedure('dbo.spUpdateDriverLocation', [
        $driverId,
        $latitude,
        $longitude
    ]);
    $action = 'location_update';
}

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error updating location'
    ]);
    exit;
}

sqlsrv_free_stmt($stmt);

echo json_encode([
    'success' => true,
    'action' => $action,
    'message' => $action === 'online' 
        ? 'Driver is now online and available' 
        : ($action === 'offline' 
            ? 'Driver is now offline' 
            : 'Location updated'),
    'data' => [
        'driverId' => $driverId,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'timestamp' => date('c')
    ]
]);
