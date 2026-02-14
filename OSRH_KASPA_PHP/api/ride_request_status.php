<?php
/**
 * API: Ride Request Status
 * Returns pickup location and whether a trip has been assigned for a ride request.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = current_user();
$passengerId = $user['passenger']['PassengerID'] ?? null;
if (!$passengerId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Passenger access required']);
    exit;
}

$rideRequestId = filter_input(INPUT_GET, 'ride_request_id', FILTER_VALIDATE_INT);
if (!$rideRequestId || $rideRequestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ride_request_id']);
    exit;
}

// Fetch ride request and pickup location; ensure it belongs to this passenger.
$rr = db_fetch_one(
    'SELECT rr.RideRequestID, rr.Status, rr.CreatedAt, rr.PassengerID,
            pl.LatDegrees AS PickupLat, pl.LonDegrees AS PickupLng,
            pl.AddressLine AS PickupAddress
     FROM dbo.RideRequest rr
     JOIN dbo.Location pl ON pl.LocationID = rr.PickupLocationID
     WHERE rr.RideRequestID = ? AND rr.PassengerID = ?;',
    [$rideRequestId, $passengerId]
);

if (!$rr) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Ride request not found']);
    exit;
}

// Check if a trip has been created/assigned for this ride request.
$trip = db_fetch_one(
    'SELECT TOP 1 TripID, Status, DriverID FROM dbo.Trip WHERE RideRequestID = ? ORDER BY TripID DESC;',
    [$rideRequestId]
);

$status = 'waiting';
if ($rr['Status'] && strtolower((string)$rr['Status']) === 'cancelled') {
    $status = 'cancelled';
}
if ($trip) {
    $status = 'assigned';
}

echo json_encode([
    'success' => true,
    'data' => [
        'rideRequestId' => (int)$rr['RideRequestID'],
        'status' => $status,
        'tripId' => $trip ? (int)$trip['TripID'] : null,
        'pickupLat' => (float)$rr['PickupLat'],
        'pickupLng' => (float)$rr['PickupLng'],
        'pickupAddress' => $rr['PickupAddress'] ?? 'Pickup location',
    ],
    'timestamp' => date('c'),
]);
