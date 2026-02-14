<?php
/**
 * API: Get All Driver Locations
 * 
 * Returns locations of all drivers for displaying on the map.
 * Supports filtering by availability status.
 * 
 * Query Parameters:
 *   - available: 0|1|all (default: all) - Filter by availability
 *   - limit: int (default: 10000) - Max drivers to return
 *   - bounds: minLat,minLng,maxLat,maxLng - Filter by map bounds
 *   - simulated: 0|1 (default: 1) - Only show simulated drivers
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

try {
    // Parse query parameters
    $availableFilter = $_GET['available'] ?? 'all';
    $limit = min((int)($_GET['limit'] ?? 10000), 50000);
    $bounds = isset($_GET['bounds']) ? explode(',', $_GET['bounds']) : null;
    $simulatedOnly = ($_GET['simulated'] ?? '1') === '1';

    $conn = db_get_connection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Prepare bounds parameters
    $minLat = null;
    $minLng = null;
    $maxLat = null;
    $maxLng = null;
    
    if ($bounds && count($bounds) === 4) {
        $minLat = (float)$bounds[0];
        $minLng = (float)$bounds[1];
        $maxLat = (float)$bounds[2];
        $maxLng = (float)$bounds[3];
    }

    // Call stored procedure to get driver locations
    $sql = "EXEC dbo.spGetAllDriverLocations 
            @AvailableFilter = ?, 
            @SimulatedOnly = ?, 
            @Limit = ?,
            @MinLat = ?,
            @MinLng = ?,
            @MaxLat = ?,
            @MaxLng = ?";
    
    $params = [
        $availableFilter,
        $simulatedOnly ? 1 : 0,
        $limit,
        $minLat,
        $minLng,
        $maxLat,
        $maxLng
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Query failed: ' . ($errors[0]['message'] ?? 'Unknown error'));
    }

    $drivers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $drivers[] = [
            'id' => (int)$row['DriverID'],
            'name' => $row['DriverName'],
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'available' => (bool)$row['IsAvailable'],
            'simulated' => isset($row['UseGPS']) ? !$row['UseGPS'] : true,
            'rating' => $row['RatingAverage'] ? (float)$row['RatingAverage'] : null,
            'vehicle' => $row['PlateNo'] ? [
                'plate' => $row['PlateNo'],
                'make' => $row['Make'],
                'model' => $row['Model'],
                'color' => $row['Color'],
                'type' => $row['VehicleType']
            ] : null
        ];
    }
    sqlsrv_free_stmt($stmt);

    // Call stored procedure to get stats
    $statsStmt = sqlsrv_query($conn, "EXEC dbo.spGetDriverStats @SimulatedOnly = ?", [$simulatedOnly ? 1 : 0]);
    $stats = $statsStmt ? sqlsrv_fetch_array($statsStmt, SQLSRV_FETCH_ASSOC) : null;
    if ($statsStmt) sqlsrv_free_stmt($statsStmt);

    echo json_encode([
        'success' => true,
        'count' => count($drivers),
        'stats' => $stats ? [
            'total' => (int)$stats['Total'],
            'available' => (int)$stats['Available'],
            'unavailable' => (int)$stats['Unavailable']
        ] : null,
        'drivers' => $drivers
    ], JSON_NUMERIC_CHECK);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'count' => 0,
        'drivers' => []
    ]);
}
