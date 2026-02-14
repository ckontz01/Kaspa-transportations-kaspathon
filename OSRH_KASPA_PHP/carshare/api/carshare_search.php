<?php
declare(strict_types=1);
/**
 * CARSHARE API - Search Vehicles
 * 
 * Searches for available car-share vehicles based on location and filters.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

// Require login
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    // Get parameters
    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : null;
    $radiusKm = isset($_GET['radius']) && $_GET['radius'] !== '' ? (float)$_GET['radius'] : 5.0;
    if ($radiusKm <= 0) {
        $radiusKm = 5.0;
    }
    if ($radiusKm > 50) {
        $radiusKm = 50;
    }
    $zoneId = isset($_GET['zone_id']) && $_GET['zone_id'] !== '' ? (int)$_GET['zone_id'] : null;
    $typeId = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int)$_GET['type_id'] : null;
    $electric = isset($_GET['electric']) && $_GET['electric'] === '1' ? 1 : null;
    $minSeats = isset($_GET['min_seats']) && $_GET['min_seats'] !== '' ? (int)$_GET['min_seats'] : null;
    $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;

    $sql = "EXEC dbo.spCarshareSearchVehicles 
        @Latitude = ?,
        @Longitude = ?,
        @RadiusKm = ?,
        @ZoneID = ?,
        @VehicleTypeID = ?,
        @IsElectric = ?,
        @MinSeats = ?,
        @CustomerID = ?";

    $params = [
        $lat,
        $lon,
        $radiusKm,
        $zoneId,
        $typeId,
        $electric,
        $minSeats,
        $customerId
    ];

    $stmt = db_query($sql, $params);

    if (!$stmt) {
        throw new Exception('Database query failed');
    }

    $vehicles = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (isset($row['DistanceKm']) && $row['DistanceKm'] !== null) {
            $row['DistanceKm'] = (float)$row['DistanceKm'];
        }
        $row['IsEligible'] = isset($row['IsEligible']) ? (int)$row['IsEligible'] : 1;
        $row['EligibilityMessage'] = $row['EligibilityMessage'] ?? '';
        $vehicles[] = $row;
    }

    sqlsrv_free_stmt($stmt);

    echo json_encode([
        'success' => true,
        'count' => count($vehicles),
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
