<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}

require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_logged_in()) {
    respond_with_error('Authentication required', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_with_error('POST method required', 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond_with_error('Invalid request body');
}

if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    respond_with_error('Invalid security token', 403);
}

$user = current_user();
$passenger = $user['passenger'] ?? null;
if (!$passenger || !isset($passenger['PassengerID'])) {
    respond_with_error('Passenger account required', 403);
}

$passengerId = (int)$passenger['PassengerID'];

$stmtCustomer = db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if (!$stmtCustomer) {
    respond_with_error('Unable to verify car-share membership', 500);
}
$customerRow = sqlsrv_fetch_array($stmtCustomer, SQLSRV_FETCH_ASSOC) ?: null;
sqlsrv_free_stmt($stmtCustomer);
if (!$customerRow) {
    respond_with_error('You must register for car-sharing before requesting delivery', 403);
}
$customerId = (int)$customerRow['CustomerID'];
if (($customerRow['VerificationStatus'] ?? '') !== 'approved') {
    respond_with_error('Your car-sharing profile must be approved before using remote delivery', 403);
}

$bookingId = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;
$targetLat = isset($input['target_lat']) ? (float)$input['target_lat'] : null;
$targetLon = isset($input['target_lon']) ? (float)$input['target_lon'] : null;
if ($bookingId <= 0 || $targetLat === null || $targetLon === null) {
    respond_with_error('Missing location or booking reference');
}
if ($targetLat < -90 || $targetLat > 90 || $targetLon < -180 || $targetLon > 180) {
    respond_with_error('Invalid destination coordinates');
}

$stmtBooking = db_query('EXEC dbo.CarshareGetBookingForTeleDrive ?, ?', [$bookingId, $customerId]);
if (!$stmtBooking) {
    respond_with_error('Unable to load booking');
}
$booking = sqlsrv_fetch_array($stmtBooking, SQLSRV_FETCH_ASSOC) ?: null;
sqlsrv_free_stmt($stmtBooking);
if (!$booking) {
    respond_with_error('Booking not found or no longer eligible for delivery');
}

$zoneLat = isset($booking['CenterLatitude']) ? (float)$booking['CenterLatitude'] : null;
$zoneLon = isset($booking['CenterLongitude']) ? (float)$booking['CenterLongitude'] : null;
if ($zoneLat === null || $zoneLon === null) {
    respond_with_error('Pickup zone does not have reference coordinates');
}

$distanceToZone = haversine_distance_km($targetLat, $targetLon, $zoneLat, $zoneLon);
if ($distanceToZone > 10.0) {
    respond_with_error('Delivery location must be within 10 km of the pickup zone');
}

// Prevent duplicate tele-drive sessions
$stmtExisting = db_query('EXEC dbo.CarshareGetActiveTeleDriveByBooking ?', [$bookingId]);
if ($stmtExisting) {
    $existingRow = sqlsrv_fetch_array($stmtExisting, SQLSRV_FETCH_ASSOC) ?: null;
    sqlsrv_free_stmt($stmtExisting);
    if ($existingRow) {
        respond_with_success([
            'message' => 'Remote delivery already scheduled',
            'data' => format_teledrive_payload($existingRow)
        ]);
    }
}

$startLat = isset($booking['CurrentLatitude']) ? (float)$booking['CurrentLatitude'] : $zoneLat;
$startLon = isset($booking['CurrentLongitude']) ? (float)$booking['CurrentLongitude'] : $zoneLon;
if (!is_finite($startLat) || !is_finite($startLon)) {
    respond_with_error('Vehicle location unavailable. Please try again later.');
}

$estimatedDistanceKm = isset($input['estimated_distance_km']) ? (float)$input['estimated_distance_km'] : null;
if ($estimatedDistanceKm === null || $estimatedDistanceKm <= 0) {
    $estimatedDistanceKm = max(0.1, haversine_distance_km($startLat, $startLon, $targetLat, $targetLon));
}

$estimatedDurationSec = isset($input['estimated_duration_sec']) ? (int)$input['estimated_duration_sec'] : 0;
if ($estimatedDurationSec <= 0) {
    $estimatedDurationSec = max(180, (int)round(($estimatedDistanceKm / 25) * 3600));
}

$routeGeometry = $input['route_geometry'] ?? null;
if (is_array($routeGeometry)) {
    $routeGeometry = json_encode($routeGeometry, JSON_UNESCAPED_SLASHES);
} elseif (!is_string($routeGeometry)) {
    $routeGeometry = null;
}

$stmtInsert = db_query(
    'EXEC dbo.CarshareCreateTeleDriveRequest ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?',
    [
        $bookingId,
        $customerId,
        (int)$booking['VehicleID'],
        (int)$booking['PickupZoneID'],
        $startLat,
        $startLon,
        $targetLat,
        $targetLon,
        $estimatedDurationSec,
        $estimatedDistanceKm,
        $routeGeometry
    ]
);
if (!$stmtInsert) {
    respond_with_error('Failed to queue remote delivery');
}

$teleDriveId = null;
do {
    $row = sqlsrv_fetch_array($stmtInsert, SQLSRV_FETCH_ASSOC);
    if ($row && isset($row['TeleDriveID'])) {
        $teleDriveId = (int)$row['TeleDriveID'];
        break;
    }
} while (sqlsrv_next_result($stmtInsert));
sqlsrv_free_stmt($stmtInsert);

if (!$teleDriveId) {
    respond_with_error('Unable to create tele-drive request');
}

$payload = fetch_teledrive_row($teleDriveId);
respond_with_success([
    'message' => 'Remote driver is heading your way',
    'data' => $payload
]);

function format_teledrive_payload(array $row): array
{
    $route = null;
    if (!empty($row['RouteGeometry'])) {
        $decoded = json_decode((string)$row['RouteGeometry'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $route = $decoded;
        }
    }

    return [
        'tele_drive_id' => (int)$row['TeleDriveID'],
        'booking_id' => (int)$row['BookingID'],
        'status' => (string)$row['Status'],
        'start_lat' => isset($row['StartLatitude']) ? (float)$row['StartLatitude'] : null,
        'start_lon' => isset($row['StartLongitude']) ? (float)$row['StartLongitude'] : null,
        'target_lat' => isset($row['TargetLatitude']) ? (float)$row['TargetLatitude'] : null,
        'target_lon' => isset($row['TargetLongitude']) ? (float)$row['TargetLongitude'] : null,
        'estimated_duration_sec' => isset($row['EstimatedDurationSec']) ? (int)$row['EstimatedDurationSec'] : null,
        'estimated_distance_km' => isset($row['EstimatedDistanceKm']) ? (float)$row['EstimatedDistanceKm'] : null,
        'route_geometry' => $route,
        'progress_percent' => isset($row['LastProgressPercent']) ? (float)$row['LastProgressPercent'] : 0,
        'started_at' => ($row['StartedAt'] ?? null) instanceof DateTime ? $row['StartedAt']->format('c') : null,
        'arrived_at' => ($row['ArrivedAt'] ?? null) instanceof DateTime ? $row['ArrivedAt']->format('c') : null
    ];
}

function fetch_teledrive_row(int $teleDriveId): array
{
    $stmt = db_query('EXEC dbo.CarshareGetTeleDriveById ?', [$teleDriveId]);
    if (!$stmt) {
        respond_with_error('Failed to load tele-drive data after creation', 500);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    sqlsrv_free_stmt($stmt);
    if (!$row) {
        respond_with_error('Tele-drive record not found', 500);
    }
    return format_teledrive_payload($row);
}

function respond_with_success(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

function respond_with_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function haversine_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}
?>
