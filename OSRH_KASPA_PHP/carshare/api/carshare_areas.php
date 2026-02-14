<?php
declare(strict_types=1);
/**
 * CARSHARE API - Get Operating Areas
 * 
 * Returns all operating areas and their polygon boundaries for map display.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    $areas = [];
    $polygons = [];
    
    // Get all active operating areas
    $stmtAreas = db_query(
        "EXEC dbo.CarshareGetOperatingAreasForDisplay",
        []
    );
    
    if ($stmtAreas) {
        while ($row = sqlsrv_fetch_array($stmtAreas, SQLSRV_FETCH_ASSOC)) {
            $areas[] = $row;
        }
        sqlsrv_free_stmt($stmtAreas);
    }
    
    // Get polygon points for each area
    $stmtPolygons = db_query(
        "EXEC dbo.CarshareGetAreaPolygonsForDisplay",
        []
    );
    
    if ($stmtPolygons) {
        while ($row = sqlsrv_fetch_array($stmtPolygons, SQLSRV_FETCH_ASSOC)) {
            $areaId = (int)$row['AreaID'];
            if (!isset($polygons[$areaId])) {
                $polygons[$areaId] = [];
            }
            $polygons[$areaId][] = [
                (float)$row['LatDegrees'],
                (float)$row['LonDegrees']
            ];
        }
        sqlsrv_free_stmt($stmtPolygons);
    }
    
    echo json_encode([
        'success' => true,
        'areas' => $areas,
        'polygons' => $polygons
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
