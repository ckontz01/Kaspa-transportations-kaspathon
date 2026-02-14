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

$user = current_user();
$passenger = $user['passenger'] ?? null;
if (!$passenger || !isset($passenger['PassengerID'])) {
    respond_with_error('Passenger access required', 403);
}
$passengerId = (int)$passenger['PassengerID'];

$stmtCustomer = db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if (!$stmtCustomer) {
    respond_with_error('Unable to verify passenger account', 500);
}
$customerRow = sqlsrv_fetch_array($stmtCustomer, SQLSRV_FETCH_ASSOC) ?: null;
sqlsrv_free_stmt($stmtCustomer);
if (!$customerRow) {
    respond_with_error('Car-share membership required', 403);
}
$customerId = (int)$customerRow['CustomerID'];

// Handle POST for speed updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond_with_error('Invalid request body');
    }
    if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
        respond_with_error('Invalid security token', 403);
    }

    $teleDriveId = isset($input['tele_drive_id']) ? (int)$input['tele_drive_id'] : 0;
    $speedMultiplier = isset($input['speed_multiplier']) ? (float)$input['speed_multiplier'] : 1.0;
    $speedMultiplier = max(1.0, min(50.0, $speedMultiplier));
    // Current progress (0.0 to 1.0) sent from client to preserve position on speed change
    $currentProgress = isset($input['current_progress']) ? (float)$input['current_progress'] : 0.0;
    $currentProgress = max(0.0, min(1.0, $currentProgress));

    if ($teleDriveId <= 0) {
        respond_with_error('tele_drive_id is required');
    }

    $stmt = db_query(
        'EXEC dbo.CarshareUpdateTeleDriveSpeed ?, ?, ?, ?',
        [$teleDriveId, $customerId, $speedMultiplier, $currentProgress]
    );
    if (!$stmt) {
        respond_with_error('Failed to update simulation speed');
    }
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    sqlsrv_free_stmt($stmt);

    respond_with_success([
        'message' => 'Speed updated',
        'speed_multiplier' => $speedMultiplier,
        'progress_at_change' => $currentProgress
    ]);
}

// GET request - fetch status
$teleDriveId = filter_input(INPUT_GET, 'tele_drive_id', FILTER_VALIDATE_INT);
$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$teleDriveId && !$bookingId) {
    respond_with_error('tele_drive_id or booking_id is required');
}

$stmt = db_query(
    'EXEC dbo.CarshareGetTeleDriveStatus ?, ?, ?',
    [$customerId, $teleDriveId ?: null, $bookingId ?: null]
);
if (!$stmt) {
    respond_with_error('Unable to load tele-drive status');
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
sqlsrv_free_stmt($stmt);
if (!$row) {
    respond_with_error('Tele-drive session not found', 404);
}

$now = new DateTimeImmutable('now');
$startTime = null;
if ($row['StartedAt'] instanceof DateTime) {
    $startTime = DateTimeImmutable::createFromMutable($row['StartedAt']);
} elseif ($row['CreatedAt'] instanceof DateTime) {
    $startTime = DateTimeImmutable::createFromMutable($row['CreatedAt']);
} else {
    $startTime = $now;
}

// Speed multiplier and base progress for proper speed change handling
$speedMultiplier = isset($row['SpeedMultiplier']) ? (float)$row['SpeedMultiplier'] : 1.0;
$speedMultiplier = max(1.0, $speedMultiplier);
$baseProgress = isset($row['ProgressAtSpeedChange']) ? (float)$row['ProgressAtSpeedChange'] : 0.0;

// Determine the reference time for progress calculation
$speedChangedAt = null;
if ($row['SpeedChangedAt'] instanceof DateTime) {
    $speedChangedAt = DateTimeImmutable::createFromMutable($row['SpeedChangedAt']);
}

$durationSec = max(60, (int)$row['EstimatedDurationSec']);

// Calculate progress: base progress + (time since speed change * current speed) / total duration
if ($speedChangedAt !== null) {
    // Time elapsed since speed was changed
    $secSinceSpeedChange = max(0, (int)$now->format('U') - (int)$speedChangedAt->format('U'));
    // Progress increment from speed change time at current speed
    $progressIncrement = ($secSinceSpeedChange * $speedMultiplier) / $durationSec;
    $progress = min(1.0, $baseProgress + $progressIncrement);
} else {
    // No speed change recorded - use simple calculation from start
    $realElapsedSec = max(0, (int)$now->format('U') - (int)$startTime->format('U'));
    $simulatedElapsedSec = $realElapsedSec * $speedMultiplier;
    $progress = $durationSec > 0 ? min(1.0, $simulatedElapsedSec / $durationSec) : 1.0;
}

// Calculate remaining time based on progress
$remainingProgress = max(0, 1.0 - $progress);
$remainingSimulatedSec = $remainingProgress * $durationSec;
// Remaining real seconds = remaining simulated seconds / speed multiplier
$remainingSeconds = $speedMultiplier > 0 ? (int)round($remainingSimulatedSec / $speedMultiplier) : 0;

$status = (string)$row['Status'];
if (in_array($status, ['arrived', 'completed', 'cancelled', 'failed'], true)) {
    $progress = 1.0;
}

$startLat = isset($row['StartLatitude']) ? (float)$row['StartLatitude'] : null;
$startLon = isset($row['StartLongitude']) ? (float)$row['StartLongitude'] : null;
$targetLat = isset($row['TargetLatitude']) ? (float)$row['TargetLatitude'] : null;
$targetLon = isset($row['TargetLongitude']) ? (float)$row['TargetLongitude'] : null;
$routePoints = extract_route_points($row['RouteGeometry'] ?? null, $startLat, $startLon, $targetLat, $targetLon);
[$currentLat, $currentLon] = compute_route_position($routePoints, $progress);

if ($currentLat === null || $currentLon === null) {
    $currentLat = $startLat;
    $currentLon = $startLon;
}

// Set remaining seconds to 0 if arrived
if ($progress >= 1.0) {
    $remainingSeconds = 0;
}
$statusMessage = build_status_message($status, $row['ZoneName'] ?? null);

// Update DB with latest progress
$teleDriveId = (int)$row['TeleDriveID'];
$vehicleId = (int)$row['VehicleID'];
$progressPercent = round($progress * 100, 2);

if (in_array($status, ['pending', 'en_route'], true)) {
    if ($progress >= 0.995 && $targetLat !== null && $targetLon !== null) {
        $status = 'arrived';
        $currentLat = $targetLat;
        $currentLon = $targetLon;
        $remainingSeconds = 0;
        $progressPercent = 100.0;
        $statusMessage = build_status_message($status, $row['ZoneName'] ?? null);
    }
}

db_query(
    'EXEC dbo.CarshareUpdateTeleDriveProgress ?, ?, ?, ?, ?',
    [$teleDriveId, $status, $progressPercent, $currentLat, $currentLon]
);

respond_with_success([
    'data' => [
        'tele_drive_id' => $teleDriveId,
        'booking_id' => (int)$row['BookingID'],
        'status' => $status,
        'progress_percent' => $progressPercent,
        'remaining_seconds' => $remainingSeconds,
        'start_lat' => $startLat,
        'start_lon' => $startLon,
        'target_lat' => $targetLat,
        'target_lon' => $targetLon,
        'current_lat' => $currentLat,
        'current_lon' => $currentLon,
        'estimated_distance_km' => isset($row['EstimatedDistanceKm']) ? (float)$row['EstimatedDistanceKm'] : null,
        'estimated_duration_sec' => $durationSec,
        'speed_multiplier' => $speedMultiplier,
        'route_coordinates' => decode_route_geometry($row['RouteGeometry'] ?? null),
        'message' => $statusMessage
    ],
    'timestamp' => $now->format('c')
]);

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

function extract_route_points(?string $geometry, ?float $startLat, ?float $startLon, ?float $targetLat, ?float $targetLon): array
{
    $points = [];
    if ($geometry) {
        $decoded = json_decode($geometry, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['coordinates']) && is_array($decoded['coordinates'])) {
            foreach ($decoded['coordinates'] as $pair) {
                if (is_array($pair) && count($pair) >= 2) {
                    $points[] = [(float)$pair[1], (float)$pair[0]];
                }
            }
        }
    }

    if (!$points) {
        if ($startLat !== null && $startLon !== null) {
            $points[] = [$startLat, $startLon];
        }
        if ($targetLat !== null && $targetLon !== null) {
            $points[] = [$targetLat, $targetLon];
        }
    }

    return $points;
}

function compute_route_position(array $points, float $progress): array
{
    $count = count($points);
    if ($count === 0) {
        return [null, null];
    }
    if ($count === 1) {
        return $points[0];
    }

    $progress = max(0.0, min(1.0, $progress));

    $distances = [];
    $total = 0.0;
    for ($i = 0; $i < $count - 1; $i++) {
        $segment = haversine_distance_meters($points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1]);
        $distances[] = $segment;
        $total += $segment;
    }

    if ($total <= 0) {
        return $points[$count - 1];
    }

    $target = $progress * $total;
    $covered = 0.0;

    for ($i = 0; $i < $count - 1; $i++) {
        $segment = $distances[$i];
        if ($covered + $segment >= $target) {
            $ratio = $segment > 0 ? ($target - $covered) / $segment : 0;
            $lat = $points[$i][0] + ($points[$i + 1][0] - $points[$i][0]) * $ratio;
            $lon = $points[$i][1] + ($points[$i + 1][1] - $points[$i][1]) * $ratio;
            return [$lat, $lon];
        }
        $covered += $segment;
    }

    return $points[$count - 1];
}

function haversine_distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function decode_route_geometry(?string $geometry)
{
    if (!$geometry) {
        return null;
    }
    $decoded = json_decode($geometry, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function build_status_message(string $status, ?string $zoneName): string
{
    $zoneLabel = $zoneName ? 'the ' . $zoneName . ' zone' : 'your pickup zone';
    switch ($status) {
        case 'arrived':
            return 'Your vehicle has arrived. Unlock it whenever you are ready.';
        case 'completed':
            return 'Tele-drive completed. Enjoy your ride!';
        case 'cancelled':
            return 'Remote delivery was cancelled. Please try again.';
        case 'failed':
            return 'We could not reach your location. Contact support for assistance.';
        default:
            return 'Vehicle is en route from ' . $zoneLabel . '.';
    }
}
?>
