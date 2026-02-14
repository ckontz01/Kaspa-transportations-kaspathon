<?php
/**
 * API: Search Carshare Vehicles
 * 
 * Searches for available carshare vehicles based on location and filters.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    // Parse parameters
    $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : null;
    $radiusKm = isset($_GET['radius']) && $_GET['radius'] !== '' ? (float)$_GET['radius'] : 5.0;
    if ($radiusKm <= 0) {
        $radiusKm = 5.0;
    }
    // Cap radius to keep query light
    if ($radiusKm > 50) {
        $radiusKm = 50;
    }
    $zoneId = isset($_GET['zone_id']) && $_GET['zone_id'] !== '' ? (int)$_GET['zone_id'] : null;
    $typeId = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int)$_GET['type_id'] : null;
    $electricOnly = isset($_GET['electric']) && $_GET['electric'] === '1' ? 1 : null;
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
        $electricOnly,
        $minSeats,
        $customerId
    ];

    $stmt = db_query($sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Database query failed: ' . print_r(sqlsrv_errors(), true));
    }

    $vehicles = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Normalize numeric strings for frontend
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
