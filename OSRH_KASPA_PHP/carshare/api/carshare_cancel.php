<?php
declare(strict_types=1);
/**
 * CARSHARE API - Cancel Booking
 * 
 * Cancels a reserved booking.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request body');
    }
    
    if (!verify_csrf_token($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }
    
    $bookingId = isset($input['booking_id']) ? (int)$input['booking_id'] : null;
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $reason = $input['reason'] ?? 'Customer requested cancellation';
    
    if (!$bookingId || !$customerId) {
        throw new Exception('Missing required parameters');
    }
    
    // Get booking details
    $stmtBooking = db_query(
        "EXEC dbo.CarshareGetBookingForCancel ?, ?",
        [$bookingId, $customerId]
    );
    
    if (!$stmtBooking) {
        throw new Exception('Database error');
    }
    
    $booking = sqlsrv_fetch_array($stmtBooking, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtBooking);
    
    if (!$booking) {
        throw new Exception('Booking not found');
    }
    
    if ($booking['Status'] !== 'reserved') {
        throw new Exception('Only reserved bookings can be cancelled (Status: ' . $booking['Status'] . ')');
    }
    
    // Cancel booking (updates booking, releases vehicle, logs event)
    $stmtCancel = db_query(
        "EXEC dbo.CarshareCancelBooking ?, ?, ?, ?",
        [$bookingId, $booking['VehicleID'], $customerId, $reason]
    );
    if ($stmtCancel) sqlsrv_free_stmt($stmtCancel);
    
    echo json_encode([
        'success' => true,
        'booking_id' => $bookingId,
        'status' => 'cancelled'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
