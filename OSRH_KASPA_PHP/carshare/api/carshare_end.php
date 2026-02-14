<?php
declare(strict_types=1);
/**
 * CARSHARE API - End Rental
 * 
 * Ends an active rental, calculates costs, and locks the vehicle.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/payments.php';

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
    
    $rentalId = isset($input['rental_id']) ? (int)$input['rental_id'] : null;
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $endLat = isset($input['end_latitude']) ? (float)$input['end_latitude'] : null;
    $endLon = isset($input['end_longitude']) ? (float)$input['end_longitude'] : null;
    $odometerEnd = isset($input['odometer_end']) ? (int)$input['odometer_end'] : null;
    $fuelEnd = isset($input['fuel_end']) ? (int)$input['fuel_end'] : null;
    $customerNotes = $input['customer_notes'] ?? null;
    
    if (!$rentalId || !$customerId) {
        throw new Exception('Missing required parameters');
    }
    
    // Get rental details
    $stmtRental = db_query(
        "EXEC dbo.CarshareGetActiveRentalForEnd ?, ?",
        [$rentalId, $customerId]
    );
    
    if (!$stmtRental) {
        throw new Exception('Database error');
    }
    
    $rental = sqlsrv_fetch_array($stmtRental, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtRental);
    
    if (!$rental) {
        throw new Exception('Rental not found');
    }
    
    if ($rental['Status'] !== 'active') {
        throw new Exception('Rental is not active (Status: ' . $rental['Status'] . ')');
    }
    
    // Get current vehicle location if not provided
    if ($endLat === null || $endLon === null || $odometerEnd === null || $fuelEnd === null) {
        $stmtVehicle = db_query(
            "EXEC dbo.CarshareGetVehicleTelemetry ?",
            [$rental['VehicleID']]
        );
        
        if ($stmtVehicle) {
            $vData = sqlsrv_fetch_array($stmtVehicle, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtVehicle);
            
            if ($vData) {
                if ($endLat === null) $endLat = (float)$vData['CurrentLatitude'];
                if ($endLon === null) $endLon = (float)$vData['CurrentLongitude'];
                if ($odometerEnd === null) $odometerEnd = (int)$vData['OdometerKm'];
                if ($fuelEnd === null) $fuelEnd = (int)$vData['FuelLevelPercent'];
            }
        }
    }
    
    // Check if parked in a zone
    $endZoneId = null;
    $parkedInZone = false;
    
    if ($endLat && $endLon) {
        $stmtZone = db_query(
            "EXEC dbo.CarshareFindZoneAtLocation ?, ?",
            [$endLat, $endLon]
        );
        
        if ($stmtZone) {
            $endZone = sqlsrv_fetch_array($stmtZone, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtZone);
            
            if ($endZone) {
                $endZoneId = (int)$endZone['ZoneID'];
                $parkedInZone = true;
            }
        }
    }
    
    $startGeofence = detectGeofenceForPoint(
        isset($rental['StartLatitude']) ? (float)$rental['StartLatitude'] : null,
        isset($rental['StartLongitude']) ? (float)$rental['StartLongitude'] : null
    );
    $endGeofence = detectGeofenceForPoint($endLat, $endLon);
    $geofenceCrossingFee = 0.0;
    if ($startGeofence && $endGeofence && $startGeofence['id'] !== $endGeofence['id']) {
        $geofenceCrossingFee = 100.0;
    }

    // Calculate costs
    $now = new DateTime();
    $startedAt = $rental['StartedAt'];
    $durationMin = ($now->getTimestamp() - $startedAt->getTimestamp()) / 60;
    $durationMin = max(1, (int)ceil($durationMin));
    
    $odometerStart = (int)$rental['OdometerStartKm'];
    $distanceKm = max(0, $odometerEnd - $odometerStart);
    
    $pricePerMin = (float)$rental['PricePerMinute'];
    $pricePerHour = (float)$rental['PricePerHour'];
    $pricePerDay = (float)$rental['PricePerDay'];
    $pricePerKm = (float)$rental['PricePerKm'];
    $pricingMode = $rental['PricingMode'];
    
    // Calculate time cost
    switch ($pricingMode) {
        case 'per_hour':
            $timeCost = ceil($durationMin / 60) * $pricePerHour;
            break;
        case 'per_day':
            $timeCost = ceil($durationMin / 1440) * $pricePerDay;
            break;
        default:
            $timeCost = $durationMin * $pricePerMin;
    }
    
    // Distance cost
    $distanceCost = $distanceKm * $pricePerKm;
    
    // Fees
    $interCityFee = 0;
    $outOfZoneFee = 0;
    $lowFuelFee = 0;
    $bonusCredit = 0;
    
    // Out of zone penalty
    if (!$parkedInZone) {
        $outOfZoneFee = 25.00;
    }
    
    // Low fuel penalty
    $fuelStart = (int)$rental['FuelStartPercent'];
    $fuelUsed = $fuelStart - $fuelEnd;
    if ($fuelEnd < 25 && $fuelUsed > 10) {
        $lowFuelFee = 10.00;
    }
    
    // Inter-city fee (if ending in different city)
    if ($endZoneId) {
        $stmtCities = db_query(
            "EXEC dbo.CarshareGetInterCityFee ?, ?",
            [$rental['StartZoneID'], $endZoneId]
        );
        
        if ($stmtCities) {
            $cities = sqlsrv_fetch_array($stmtCities, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtCities);
            
            if ($cities && $cities['StartCity'] !== $cities['EndCity'] && $cities['InterCityFee']) {
                $interCityFee = (float)$cities['InterCityFee'];
            }
        }
        
        // Bonus for parking in pink zone
        if (isset($endZone) && $endZone['BonusAmount'] && $endZoneId != $rental['StartZoneID']) {
            $bonusCredit = (float)$endZone['BonusAmount'];
        }
    }
    
    // Total
    $totalCost = max(0, $timeCost + $distanceCost + $interCityFee + $outOfZoneFee + $lowFuelFee + $geofenceCrossingFee - $bonusCredit);
    
    // Update rental
    $stmtUpdateRental = db_query(
        "EXEC dbo.CarshareCompleteRental ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?",
        [
            $rentalId,
            $now->format('Y-m-d H:i:s'),
            $durationMin,
            $odometerEnd,
            $fuelEnd,
            $endZoneId,
            $endLat,
            $endLon,
            $parkedInZone ? 1 : 0,
            $timeCost,
            $distanceCost,
            $interCityFee,
            $outOfZoneFee,
            $lowFuelFee,
            $geofenceCrossingFee,
            $bonusCredit,
            $totalCost,
            $customerNotes
        ]
    );
    if ($stmtUpdateRental) sqlsrv_free_stmt($stmtUpdateRental);
    
    // Update booking
    $stmtUpdateBooking = db_query(
        "EXEC dbo.CarshareCompleteBooking ?",
        [$rental['BookingID']]
    );
    if ($stmtUpdateBooking) sqlsrv_free_stmt($stmtUpdateBooking);
    
    // Update vehicle
    $vehicleStatus = $parkedInZone ? 'available' : 'out_of_zone';
    $stmtUpdateVehicle = db_query(
        "EXEC dbo.CarshareLockVehicle ?, ?, ?, ?, ?, ?, ?",
        [
            $rental['VehicleID'],
            $vehicleStatus,
            $endZoneId,
            $endLat,
            $endLon,
            $odometerEnd,
            $fuelEnd
        ]
    );
    if ($stmtUpdateVehicle) sqlsrv_free_stmt($stmtUpdateVehicle);
    
    // Update zone count if parked in zone
    if ($endZoneId) {
        $stmtZoneUpdate = db_query(
            "EXEC dbo.CarshareIncrementZoneCount ?",
            [$endZoneId]
        );
        if ($stmtZoneUpdate) sqlsrv_free_stmt($stmtZoneUpdate);
    }
    
    // Update customer stats
    $stmtCustUpdate = db_query(
        "EXEC dbo.CarshareUpdateCustomerStats ?, ?, ?",
        [$customerId, $distanceKm, $totalCost]
    );
    if ($stmtCustUpdate) sqlsrv_free_stmt($stmtCustUpdate);
    
    // Create payment record (auto-settled for simulation)
    $stmtPayment = db_query(
        "EXEC dbo.CarshareCreatePayment ?, ?, ?",
        [$rentalId, $customerId, $totalCost]
    );
    if ($stmtPayment) sqlsrv_free_stmt($stmtPayment);

    try {
        osrh_send_payment_receipt([
            'email'    => current_user_email(),
            'name'     => current_user_name() ?? 'Customer',
            'subject'  => 'Your CarShare rental receipt',
            'amount'   => $totalCost,
            'currency' => 'EUR',
            'method'   => 'CarShare charge',
            'reference'=> null,
            'when'     => $now,
            'context'  => [
                '- CarShare rental completed',
                '- Duration: ' . $durationMin . ' minutes',
                $parkedInZone ? '- Returned inside a service zone' : '- Returned outside service zone',
            ],
        ]);
    } catch (Throwable $mailError) {
        error_log('CarShare receipt email failed: ' . $mailError->getMessage());
    }
    
    // Log
    $logMessage = "Rental ended. Duration: {$durationMin}min, Distance: {$distanceKm}km, Total: €" . number_format($totalCost, 2);
    if ($geofenceCrossingFee > 0) {
        $logMessage .= ' (geofence crossing fee applied)';
    }

    $stmtLog = db_query(
        "EXEC dbo.CarshareLogEvent ?, ?, ?, ?, ?, NULL, ?",
        [
            'info',
            'rental',
            $logMessage,
            $rental['VehicleID'],
            $customerId,
            $rentalId
        ]
    );
    if ($stmtLog) sqlsrv_free_stmt($stmtLog);
    
    echo json_encode([
        'success' => true,
        'rental_id' => $rentalId,
        'status' => 'completed',
        'duration_min' => $durationMin,
        'distance_km' => $distanceKm,
        'time_cost' => round($timeCost, 2),
        'distance_cost' => round($distanceCost, 2),
        'inter_city_fee' => round($interCityFee, 2),
        'out_of_zone_fee' => round($outOfZoneFee, 2),
        'low_fuel_fee' => round($lowFuelFee, 2),
        'bonus_credit' => round($bonusCredit, 2),
        'total_cost' => round($totalCost, 2),
        'parked_in_zone' => $parkedInZone,
        'end_zone_name' => isset($endZone) ? $endZone['ZoneName'] : 'Outside Zone',
        'geofence_crossing_fee' => round($geofenceCrossingFee, 2),
        'start_geofence_name' => $startGeofence['name'] ?? null,
        'end_geofence_name' => $endGeofence['name'] ?? null
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function detectGeofenceForPoint(?float $lat, ?float $lon): ?array
{
    if ($lat === null || $lon === null) {
        return null;
    }

    foreach (loadGeofencePolygons() as $geofence) {
        if (count($geofence['points']) < 3) {
            continue;
        }

        if (pointInPolygon($lat, $lon, $geofence['points'])) {
            return $geofence;
        }
    }

    return null;
}

function loadGeofencePolygons(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $geofences = [];

    $stmtGeofence = db_query(
        "EXEC dbo.CarshareGetActiveGeofences",
        []
    );

    if ($stmtGeofence) {
        while ($row = sqlsrv_fetch_array($stmtGeofence, SQLSRV_FETCH_ASSOC)) {
            $geofences[(int)$row['GeofenceID']] = [
                'id' => (int)$row['GeofenceID'],
                'name' => (string)$row['Name'],
                'points' => []
            ];
        }
        sqlsrv_free_stmt($stmtGeofence);
    }

    if (!$geofences) {
        $cache = [];
        return $cache;
    }

    $stmtPoints = db_query(
        "EXEC dbo.CarshareGetGeofencePoints",
        []
    );

    if ($stmtPoints) {
        while ($row = sqlsrv_fetch_array($stmtPoints, SQLSRV_FETCH_ASSOC)) {
            $geoId = (int)$row['GeofenceID'];
            if (!isset($geofences[$geoId])) {
                continue;
            }

            $geofences[$geoId]['points'][] = [
                (float)$row['LatDegrees'],
                (float)$row['LonDegrees']
            ];
        }
        sqlsrv_free_stmt($stmtPoints);
    }

    $cache = array_values($geofences);
    return $cache;
}

function pointInPolygon(float $lat, float $lon, array $polygon): bool
{
    $inside = false;
    $points = count($polygon);

    for ($i = 0, $j = $points - 1; $i < $points; $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];

        $denominator = $yj - $yi;
        if (abs($denominator) < 1e-10) {
            $denominator = $denominator >= 0 ? 1e-10 : -1e-10;
        }

        $intersect = (($yi > $lon) !== ($yj > $lon)) &&
            ($lat < ($xj - $xi) * ($lon - $yi) / $denominator + $xi);

        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}
