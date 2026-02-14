<?php
declare(strict_types=1);
/**
 * CARSHARE API - Check Geofence
 * 
 * Checks if vehicle is within operating boundaries during active rental.
 * Returns warnings/violations if outside.
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

try {
    $rentalId = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : null;
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
    
    if (!$rentalId || $lat === null || $lon === null) {
        throw new Exception('Missing required parameters: rental_id, lat, lon');
    }
    
    // Verify rental exists and is active
    $stmtRental = db_query(
        "EXEC dbo.CarshareGetRentalForGeofence ?",
        [$rentalId]
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
        throw new Exception('Rental is not active');
    }
    
    // Update vehicle location
    $stmtUpdate = db_query(
        "EXEC dbo.CarshareUpdateVehicleLocation ?, ?, ?",
        [$rental['VehicleID'], $lat, $lon]
    );
    if ($stmtUpdate) sqlsrv_free_stmt($stmtUpdate);
    
    // Check against operating areas
    $violations = [];
    $warnings = [];
    $isValid = true;
    $totalPenaltyPerMin = 0;
    
    // Get all active operating areas
    $stmtAreas = db_query(
        "EXEC dbo.CarshareGetActiveOperatingAreas",
        []
    );
    
    if ($stmtAreas) {
        while ($area = sqlsrv_fetch_array($stmtAreas, SQLSRV_FETCH_ASSOC)) {
            $isInsideArea = false;
            $distanceToCenter = null;
            
            if ($area['UsePolygon'] == 1) {
                // Check polygon using ray casting
                $stmtPoly = db_query(
                    "EXEC dbo.CarshareGetAreaPolygonPoints ?",
                    [$area['AreaID']]
                );
                
                if ($stmtPoly) {
                    $points = [];
                    while ($pt = sqlsrv_fetch_array($stmtPoly, SQLSRV_FETCH_ASSOC)) {
                        $points[] = [(float)$pt['LatDegrees'], (float)$pt['LonDegrees']];
                    }
                    sqlsrv_free_stmt($stmtPoly);
                    
                    // Ray casting algorithm
                    if (count($points) >= 3) {
                        $isInsideArea = pointInPolygon($lat, $lon, $points);
                    }
                }
            } else if ($area['CenterLatitude'] && $area['CenterLongitude'] && $area['RadiusMeters']) {
                // Check circular boundary
                $distanceToCenter = haversineDistance(
                    $lat, $lon,
                    (float)$area['CenterLatitude'], (float)$area['CenterLongitude']
                );
                
                $isInsideArea = $distanceToCenter <= (int)$area['RadiusMeters'];
                
                // Check warning distance
                if ($isInsideArea && 
                    ($area['RadiusMeters'] - $distanceToCenter) < $area['WarningDistanceM']) {
                    $warnings[] = [
                        'type' => 'boundary_warning',
                        'area' => $area['AreaName'],
                        'message' => 'Approaching operating area boundary',
                        'distance_to_boundary' => (int)($area['RadiusMeters'] - $distanceToCenter)
                    ];
                }
            }
            
            // Process based on area type
            if ($area['AreaType'] === 'operating' && !$isInsideArea) {
                $isValid = false;
                $totalPenaltyPerMin += (float)$area['PenaltyPerMinute'];
                
                $violations[] = [
                    'type' => 'boundary_exit',
                    'area' => $area['AreaName'],
                    'penalty_per_minute' => (float)$area['PenaltyPerMinute'],
                    'max_penalty' => (float)$area['MaxPenalty'],
                    'message' => 'Vehicle has left the operating area. Additional charges apply.'
                ];
                
                // Log violation
                $stmtLog = db_query(
                    "EXEC dbo.CarshareLogGeofenceViolation ?, ?, ?, ?, ?, ?, ?, ?, ?",
                    [
                        $rentalId, 
                        $rental['VehicleID'], 
                        $rental['CustomerID'],
                        $area['AreaID'],
                        'boundary_exit',
                        $lat, 
                        $lon,
                        $distanceToCenter ? (int)($distanceToCenter - $area['RadiusMeters']) : null,
                        (float)$area['PenaltyPerMinute']
                    ]
                );
                if ($stmtLog) sqlsrv_free_stmt($stmtLog);
                
            } else if ($area['AreaType'] === 'restricted' && $isInsideArea) {
                $isValid = false;
                $totalPenaltyPerMin += (float)$area['PenaltyPerMinute'];
                
                $violations[] = [
                    'type' => 'restricted_entry',
                    'area' => $area['AreaName'],
                    'penalty_per_minute' => (float)$area['PenaltyPerMinute'],
                    'message' => 'Vehicle has entered a restricted area. Additional charges apply.'
                ];
                
                // Log violation
                $stmtLog = db_query(
                    "EXEC dbo.CarshareLogGeofenceViolation ?, ?, ?, ?, ?, ?, ?, ?, ?",
                    [
                        $rentalId, 
                        $rental['VehicleID'], 
                        $rental['CustomerID'],
                        $area['AreaID'],
                        'restricted_entry',
                        $lat, 
                        $lon,
                        null,
                        (float)$area['PenaltyPerMinute']
                    ]
                );
                if ($stmtLog) sqlsrv_free_stmt($stmtLog);
            }
        }
        sqlsrv_free_stmt($stmtAreas);
    }
    
    // Get current zone if any
    $currentZone = null;
    $stmtZone = db_query(
        "EXEC dbo.CarshareGetCurrentZone ?, ?",
        [$lat, $lon]
    );
    
    if ($stmtZone) {
        $currentZone = sqlsrv_fetch_array($stmtZone, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtZone);
    }
    
    echo json_encode([
        'success' => true,
        'is_valid' => $isValid,
        'violations' => $violations,
        'warnings' => $warnings,
        'total_penalty_per_minute' => $totalPenaltyPerMin,
        'current_zone' => $currentZone,
        'in_parking_zone' => $currentZone !== null
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Calculate distance between two points using Haversine formula
 */
function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000; // Earth radius in meters
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $R * $c;
}

/**
 * Check if point is inside polygon using ray casting algorithm
 */
function pointInPolygon(float $lat, float $lon, array $polygon): bool {
    $inside = false;
    $n = count($polygon);
    
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        
        if ((($yi > $lon) !== ($yj > $lon)) &&
            ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}
