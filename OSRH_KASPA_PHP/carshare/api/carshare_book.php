<?php
declare(strict_types=1);
/**
 * CARSHARE API - Create Booking
 * 
 * Creates a new car-share booking/reservation.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

// Require login and POST
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

try {
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request body');
    }
    
    // Validate CSRF
    if (!verify_csrf_token($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }
    
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $vehicleId = isset($input['vehicle_id']) ? (int)$input['vehicle_id'] : null;
    $pricingMode = $input['pricing_mode'] ?? 'per_minute';
    
    if (!$customerId || !$vehicleId) {
        throw new Exception('Missing required parameters');
    }
    
    // Validate pricing mode
    if (!in_array($pricingMode, ['per_minute', 'per_hour', 'per_day'])) {
        $pricingMode = 'per_minute';
    }
    
    // Verify customer is approved
    $stmtCust = db_query(
        "EXEC dbo.CarshareVerifyCustomerApproval ?",
        [$customerId]
    );
    
    if (!$stmtCust) {
        throw new Exception('Database error');
    }
    
    $customer = sqlsrv_fetch_array($stmtCust, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtCust);
    
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    if ($customer['VerificationStatus'] !== 'approved') {
        throw new Exception('Customer not approved for car-sharing');
    }
    
    // Get passenger ID from customer to check for other active rides
    $stmtPassenger = db_query(
        "SELECT PassengerID FROM dbo.CarshareCustomer WHERE CustomerID = ?",
        [$customerId]
    );
    
    if ($stmtPassenger) {
        $passengerRow = sqlsrv_fetch_array($stmtPassenger, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtPassenger);
        
        if ($passengerRow && isset($passengerRow['PassengerID'])) {
            $passengerId = (int)$passengerRow['PassengerID'];
            
            // Check for active driver trip
            $stmtTrip = db_query("EXEC dbo.spGetPassengerActiveTrip ?", [$passengerId]);
            if ($stmtTrip) {
                $activeTrip = sqlsrv_fetch_array($stmtTrip, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmtTrip);
                if ($activeTrip && !empty($activeTrip['TripID'])) {
                    throw new Exception('You have an active driver trip in progress. Please complete it before booking a car-share vehicle.');
                }
            }
            
            // Check for active autonomous ride
            $stmtAV = db_query("EXEC dbo.spGetPassengerActiveAutonomousRide ?", [$passengerId]);
            if ($stmtAV) {
                $activeAV = sqlsrv_fetch_array($stmtAV, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmtAV);
                if ($activeAV && !empty($activeAV['AutonomousRideID'])) {
                    throw new Exception('You have an active autonomous ride in progress. Please complete it before booking a car-share vehicle.');
                }
            }
        }
    }
    
    // Check for existing active booking
    $stmtCheck = db_query(
        "EXEC dbo.CarshareCheckExistingBooking ?",
        [$customerId]
    );
    
    if ($stmtCheck) {
        $existing = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);
        
        if ($existing) {
            throw new Exception('You already have an active booking');
        }
    }
    
    // Verify vehicle is available
    $stmtVehicle = db_query(
        "EXEC dbo.CarshareGetVehicleForBooking ?",
        [$vehicleId]
    );
    
    if (!$stmtVehicle) {
        throw new Exception('Database error');
    }
    
    $vehicle = sqlsrv_fetch_array($stmtVehicle, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtVehicle);
    
    if (!$vehicle) {
        throw new Exception('Vehicle not found');
    }
    
    if ($vehicle['Status'] !== 'available') {
        throw new Exception('Vehicle is not available (Status: ' . $vehicle['Status'] . ')');
    }
    
    if (!$vehicle['CurrentZoneID']) {
        throw new Exception('Vehicle is not in a valid pickup zone');
    }
    
    // Create booking (20 minute window)
    $now = new DateTime();
    $expiresAt = (clone $now)->add(new DateInterval('PT20M'));
    
    $stmtInsert = db_query(
        "EXEC dbo.CarshareCreateBooking ?, ?, ?, ?, ?, ?, ?, ?, ?, ?",
        [
            $customerId,
            $vehicleId,
            $now->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
            $pricingMode,
            $vehicle['CurrentZoneID'],
            $vehicle['CurrentLatitude'],
            $vehicle['CurrentLongitude'],
            $vehicle['DepositAmount']
        ]
    );
    
    if (!$stmtInsert) {
        $dbErrors = sqlsrv_errors();
        $details = '';
        if ($dbErrors && isset($dbErrors[0]['message'])) {
            $details = ': ' . preg_replace('/\s+/', ' ', $dbErrors[0]['message']);
        }
        throw new Exception('Failed to create booking' . $details);
    }
    
    $bookingId = null;
    do {
        $row = sqlsrv_fetch_array($stmtInsert, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['BookingID'])) {
            $bookingId = (int)$row['BookingID'];
            break;
        }
    } while (sqlsrv_next_result($stmtInsert));

    sqlsrv_free_stmt($stmtInsert);

    if (!$bookingId) {
        // Fallback: look up the freshest reserved booking for this user/vehicle in case the result set was consumed by triggers
        $stmtLookup = db_query(
            "EXEC dbo.CarshareGetLatestBooking ?, ?",
            [$customerId, $vehicleId]
        );

        if ($stmtLookup) {
            $row = sqlsrv_fetch_array($stmtLookup, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtLookup);
            if ($row && isset($row['BookingID'])) {
                $bookingId = (int)$row['BookingID'];
            }
        }
    }

    if (!$bookingId) {
        throw new Exception('Failed to get booking ID');
    }
    
    // Update vehicle status
    $stmtUpdate = db_query(
        "EXEC dbo.CarshareUpdateVehicleStatus ?, ?",
        [$vehicleId, 'reserved']
    );
    if ($stmtUpdate) {
        sqlsrv_free_stmt($stmtUpdate);
    }
    
    // Log the booking
    $stmtLog = db_query(
        "EXEC dbo.CarshareLogEvent ?, ?, ?, ?, ?, ?",
        ['info', 'booking', 'Vehicle booked', $vehicleId, $customerId, $bookingId]
    );
    if ($stmtLog) {
        sqlsrv_free_stmt($stmtLog);
    }
    
    echo json_encode([
        'success' => true,
        'booking_id' => (int)$bookingId,
        'status' => 'reserved',
        'expires_at' => $expiresAt->format('c'),
        'minutes_to_unlock' => 20,
        'deposit_amount' => (float)$vehicle['DepositAmount']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
