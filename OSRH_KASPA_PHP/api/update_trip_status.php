<?php
/**
 * API Endpoint: Update Trip Status
 * 
 * Updates the status of a trip (start, complete, cancel).
 * Used for AJAX calls from driver trip detail page.
 * 
 * POST Parameters:
 *   - trip_id: The trip ID
 *   - action: 'start', 'complete', 'cancel'
 *   - csrf_token: CSRF token for security
 * 
 * Returns JSON:
 *   - success: boolean
 *   - message: status message
 *   - newStatus: the new trip status
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
    $data = $_POST;
}

$tripId = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
$action = isset($data['action']) ? trim($data['action']) : '';

// Validate input
if ($tripId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid trip ID'
    ]);
    exit;
}

$allowedActions = ['start', 'complete', 'cancel'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid action. Must be: start, complete, or cancel'
    ]);
    exit;
}

// Map action to status
$statusMap = [
    'start' => 'in_progress',
    'complete' => 'completed',
    'cancel' => 'cancelled'
];
$newStatus = $statusMap[$action];

// Verify trip belongs to this driver
$tripStmt = db_call_procedure('dbo.spDriverGetTripForValidation', [$driverId, $tripId]);
$tripRow = null;
if ($tripStmt !== false) {
    $tripRow = sqlsrv_fetch_array($tripStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($tripStmt);
}

if (!$tripRow) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Trip not found for this driver'
    ]);
    exit;
}

$currentStatus = strtolower((string)$tripRow['Status']);

// Validate state transition
$allowedTransitions = [
    'assigned'    => ['in_progress', 'cancelled'],
    'dispatched'  => ['in_progress', 'cancelled'],
    'in_progress' => ['completed', 'cancelled'],
    'completed'   => [],
    'cancelled'   => [],
];

if (!isset($allowedTransitions[$currentStatus]) || 
    !in_array($newStatus, $allowedTransitions[$currentStatus], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'This status change is not allowed from current state (' . $currentStatus . ')'
    ]);
    exit;
}

// Handle conclude real trip differently
if ($action === 'complete') {
    $resultStmt = db_call_procedure('dbo.spDriverConcludeRealTrip', [$driverId, $tripId]);
    
    if ($resultStmt !== false) {
        $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($resultStmt);
        
        if (!empty($result['Success'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Trip completed successfully',
                'newStatus' => 'completed'
            ]);
            exit;
        } else {
            $errorMsg = $result['ErrorMessage'] ?? 'Failed to complete trip';
            echo json_encode([
                'success' => false,
                'error' => $errorMsg
            ]);
            exit;
        }
    }
} else {
    // Update trip status using stored procedure
    $stmt = db_call_procedure('dbo.spDriverUpdateTripStatus', [
        $driverId,
        $tripId,
        $newStatus,
        null, // distanceKm
        null  // durationMin
    ]);
    
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($result && !empty($result['Success'])) {
            $messages = [
                'in_progress' => 'Trip started! Heading to destination.',
                'cancelled' => 'Trip has been cancelled.'
            ];
            
            echo json_encode([
                'success' => true,
                'message' => $messages[$newStatus] ?? 'Status updated',
                'newStatus' => $newStatus
            ]);
            exit;
        } else {
            $errorMsg = $result['ErrorMessage'] ?? 'Failed to update trip status';
            echo json_encode([
                'success' => false,
                'error' => $errorMsg
            ]);
            exit;
        }
    }
}

// Fallback error
http_response_code(500);
echo json_encode([
    'success' => false,
    'error' => 'Database error updating trip status'
]);
