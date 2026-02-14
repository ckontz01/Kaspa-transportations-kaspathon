<?php
declare(strict_types=1);
/**
 * CARSHARE API - Start Rental (Unlock Vehicle)
 * 
 * Starts the rental by unlocking the vehicle.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

function format_sqlsrv_errors(): string
{
    $errors = sqlsrv_errors();
    if (!$errors) {
        return 'No SQL Server error details available.';
    }

    $messages = array_map(function (array $error): string {
        $parts = [];
        if (isset($error['SQLSTATE'])) {
            $parts[] = 'SQLSTATE ' . $error['SQLSTATE'];
        }
        if (isset($error['code'])) {
            $parts[] = 'Code ' . $error['code'];
        }
        if (isset($error['message'])) {
            $parts[] = trim($error['message']);
        }
        return implode(' - ', $parts);
    }, $errors);

    return implode(' | ', $messages);
}

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
    
    if (!$bookingId || !$customerId) {
        throw new Exception('Missing required parameters');
    }
    
    // Get booking details
    $stmtBooking = db_query(
        "EXEC dbo.CarshareGetBookingForStart ?, ?",
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
        throw new Exception('Booking is not in reserved status (Status: ' . $booking['Status'] . ')');
    }
    
    // Check if expired
    $expiresAt = $booking['ReservationExpiresAt'];
    if ($expiresAt instanceof DateTime && $expiresAt < new DateTime()) {
        // Mark as expired
        db_query(
            "EXEC dbo.CarshareExpireBooking ?, ?",
            [$bookingId, $booking['VehicleID']]
        );
        throw new Exception('Booking has expired');
    }
    
    // Get vehicle details
    $stmtVehicle = db_query(
        "EXEC dbo.CarshareGetVehicleForStart ?",
        [$booking['VehicleID']]
    );
    
    if (!$stmtVehicle) {
        throw new Exception('Database error');
    }
    
    $vehicle = sqlsrv_fetch_array($stmtVehicle, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtVehicle);
    
    if (!$vehicle) {
        throw new Exception('Vehicle not found');
    }
    
    $now = new DateTime();

    // Ensure we have valid telemetry before creating the rental
    $startLatitude = $vehicle['CurrentLatitude'];
    $startLongitude = $vehicle['CurrentLongitude'];

    if ($startLatitude === null || $startLongitude === null) {
        $stmtZoneCoords = db_query(
            "EXEC dbo.CarshareGetZoneCenter ?",
            [$booking['PickupZoneID']]
        );

        if ($stmtZoneCoords) {
            $zoneCoords = sqlsrv_fetch_array($stmtZoneCoords, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtZoneCoords);

            if ($zoneCoords) {
                $startLatitude = $zoneCoords['CenterLatitude'];
                $startLongitude = $zoneCoords['CenterLongitude'];
            }
        }

        if ($startLatitude === null || $startLongitude === null) {
            throw new Exception('Vehicle location unavailable. Please contact support.');
        }
    }

    $startLatitude = (float)$startLatitude;
    $startLongitude = (float)$startLongitude;

    $odometerStart = $vehicle['OdometerKm'];
    if ($odometerStart === null) {
        $odometerStart = 0;
    }

    $fuelStart = $vehicle['FuelLevelPercent'];
    if ($fuelStart === null) {
        $fuelStart = 0;
    }
    
    // Create rental record
    $stmtInsert = db_query(
        "EXEC dbo.CarshareCreateRental ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?",
        [
            $bookingId,
            $customerId,
            $booking['VehicleID'],
            $now->format('Y-m-d H:i:s'),
            $odometerStart,
            $fuelStart,
            $booking['PickupZoneID'],
            $startLatitude,
            $startLongitude,
            $booking['PricingMode'],
            $vehicle['PricePerMinute'],
            $vehicle['PricePerHour'],
            $vehicle['PricePerDay'],
            $vehicle['PricePerKm']
        ]
    );
    
    if (!$stmtInsert) {
        throw new Exception('Failed to create rental record: ' . format_sqlsrv_errors());
    }
    
    // Walk through result sets until the SELECT with RentalID is found
    $rentalId = null;
    do {
        $result = sqlsrv_fetch_array($stmtInsert, SQLSRV_FETCH_ASSOC);
        if ($result && isset($result['RentalID'])) {
            $rentalId = (int)$result['RentalID'];
            break;
        }
    } while (sqlsrv_next_result($stmtInsert));

    if (!$rentalId) {
        throw new Exception('Failed to get rental ID');
    }
    sqlsrv_free_stmt($stmtInsert);
    
    // Update booking status
    $stmtBookingUpdate = db_query(
        "EXEC dbo.CarshareActivateBooking ?",
        [$bookingId]
    );
    if ($stmtBookingUpdate) sqlsrv_free_stmt($stmtBookingUpdate);
    
    // Update vehicle: unlock and enable engine
    $stmtVehicleUpdate = db_query(
        "EXEC dbo.CarshareUnlockVehicle ?",
        [$booking['VehicleID']]
    );
    if ($stmtVehicleUpdate) sqlsrv_free_stmt($stmtVehicleUpdate);
    
    // Decrease zone vehicle count
    $stmtZoneUpdate = db_query(
        "EXEC dbo.CarshareDecrementZoneCount ?",
        [$booking['PickupZoneID']]
    );
    if ($stmtZoneUpdate) sqlsrv_free_stmt($stmtZoneUpdate);
    
    // Log
    $stmtLog = db_query(
        "EXEC dbo.CarshareLogEvent ?, ?, ?, ?, ?, ?, ?",
        ['info', 'rental', 'Rental started - vehicle unlocked', $booking['VehicleID'], $customerId, $bookingId, $rentalId]
    );
    if ($stmtLog) sqlsrv_free_stmt($stmtLog);
    
    echo json_encode([
        'success' => true,
        'rental_id' => (int)$rentalId,
        'status' => 'active',
        'odometer_start' => (int)$vehicle['OdometerKm'],
        'fuel_start' => (int)$vehicle['FuelLevelPercent'],
        'price_per_minute' => (float)$vehicle['PricePerMinute'],
        'price_per_hour' => (float)$vehicle['PricePerHour'],
        'price_per_km' => (float)$vehicle['PricePerKm'],
        'message' => 'Vehicle unlocked. Have a safe trip!'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
