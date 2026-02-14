<?php
/**
 * AJAX endpoint to check if a journey requires multi-segment geofence bridges
 */

// Catch all errors
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, return as JSON
ini_set('display_startup_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../includes/db.php';
    
    // Check if db function exists
    if (!function_exists('db')) {
        echo json_encode(['error' => 'db function not defined. Check includes/db.php']);
        exit;
    }

    // Get coordinates from POST
    $pickupLat = isset($_POST['pickup_lat']) ? (float)$_POST['pickup_lat'] : null;
    $pickupLon = isset($_POST['pickup_lon']) ? (float)$_POST['pickup_lon'] : null;
    $dropoffLat = isset($_POST['dropoff_lat']) ? (float)$_POST['dropoff_lat'] : null;
    $dropoffLon = isset($_POST['dropoff_lon']) ? (float)$_POST['dropoff_lon'] : null;

    if (!$pickupLat || !$pickupLon || !$dropoffLat || !$dropoffLon) {
        echo json_encode(['error' => 'Missing coordinates', 'debug' => $_POST]);
        exit;
    }

    // Get database connection
    $conn = db();
    
    if (!$conn) {
        echo json_encode(['error' => 'Database connection failed', 'sqlsrv_errors' => sqlsrv_errors()]);
        exit;
    }

    // First, create temporary locations to check geofences
    $pickupLocationId = null;
    $dropoffLocationId = null;

    // Insert pickup location
    $stmt = db_call_procedure('dbo.spInsertLocation', [
        'Temp Pickup Check',
        null,
        null,
        $pickupLat,
        $pickupLon
    ]);

    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $pickupLocationId = $row['LocationID'] ?? null;
        sqlsrv_free_stmt($stmt);
    }

    if (!$pickupLocationId) {
        echo json_encode(['error' => 'Failed to create pickup location', 'sql_errors' => sqlsrv_errors()]);
        exit;
    }

    // Insert dropoff location
    $stmt = db_call_procedure('dbo.spInsertLocation', [
        'Temp Dropoff Check',
        null,
        null,
        $dropoffLat,
        $dropoffLon
    ]);

    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $dropoffLocationId = $row['LocationID'] ?? null;
        sqlsrv_free_stmt($stmt);
    }

    if (!$dropoffLocationId) {
        echo json_encode(['error' => 'Failed to create dropoff location', 'sql_errors' => sqlsrv_errors()]);
        exit;
    }

    // Check if fnGetLocationGeofence function exists
    $stmt = db_call_procedure('dbo.spCheckDatabaseObjectExists', ['dbo.fnGetLocationGeofence']);
    $fnExists = false;
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fnExists = $row['ObjectExists'] ? true : false;
        sqlsrv_free_stmt($stmt);
    }

    if (!$fnExists) {
        echo json_encode([
            'error' => 'Function fnGetLocationGeofence does not exist. Please run DataBase/geofence_bridge_system.sql',
            'requires_bridges' => false,
            'pickup_location_id' => $pickupLocationId,
            'dropoff_location_id' => $dropoffLocationId
        ]);
        exit;
    }

    // Get pickup geofence
    $stmt = db_call_procedure('dbo.spGetLocationGeofenceID', [$pickupLocationId]);
    $pickupGeofenceId = null;
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pickupGeofenceId = $row['GeofenceID'];
        sqlsrv_free_stmt($stmt);
    }

    // Get dropoff geofence
    $stmt = db_call_procedure('dbo.spGetLocationGeofenceID', [$dropoffLocationId]);
    $dropoffGeofenceId = null;
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dropoffGeofenceId = $row['GeofenceID'];
        sqlsrv_free_stmt($stmt);
    }

    // Response object
    $response = [
        'pickup_location_id' => $pickupLocationId,
        'dropoff_location_id' => $dropoffLocationId,
        'pickup_geofence_id' => $pickupGeofenceId,
        'dropoff_geofence_id' => $dropoffGeofenceId,
        'requires_bridges' => false,
        'paths' => []
    ];

    // If both locations are in geofences and they're different, find paths
    if ($pickupGeofenceId && $dropoffGeofenceId && $pickupGeofenceId !== $dropoffGeofenceId) {
        $response['requires_bridges'] = true;
        
        // Check if stored procedure exists
        $stmt = db_call_procedure('dbo.spCheckDatabaseObjectExists', ['dbo.spFindGeofencePaths']);
        $spExists = false;
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $spExists = $row['ObjectExists'] ? true : false;
            sqlsrv_free_stmt($stmt);
        }

        if (!$spExists) {
            $response['error'] = 'Stored procedure spFindGeofencePaths does not exist. Please run DataBase/geofence_bridge_system.sql';
            echo json_encode($response);
            exit;
        }
        
        // Call stored procedure to find all possible paths
        // spFindGeofencePaths expects LocationIDs, not GeofenceIDs
        $pathStmt = db_call_procedure('dbo.spFindGeofencePaths', [
            $pickupLocationId,
            $dropoffLocationId
        ]);
        
        if ($pathStmt) {
            while ($pathRow = sqlsrv_fetch_array($pathStmt, SQLSRV_FETCH_ASSOC)) {
                $geofencePath = !empty($pathRow['GeofencePath']) ? explode(',', $pathRow['GeofencePath']) : [];
                $bridgePath = !empty($pathRow['BridgePath']) ? explode(',', $pathRow['BridgePath']) : [];
                
                // Get geofence names
                $geofenceNames = [];
                foreach ($geofencePath as $geoId) {
                    $nameStmt = db_call_procedure('dbo.spGetGeofenceName', [$geoId]);
                    if ($nameStmt && $nameRow = sqlsrv_fetch_array($nameStmt, SQLSRV_FETCH_ASSOC)) {
                        $geofenceNames[] = $nameRow['Name'];
                        sqlsrv_free_stmt($nameStmt);
                    }
                }
                
                // Get bridge details including coordinates
                $bridgeDetails = [];
                foreach ($bridgePath as $bridgeId) {
                    $bridgeStmt = db_call_procedure('dbo.spGetBridgeDetails', [$bridgeId]);
                    if ($bridgeStmt && $bridgeRow = sqlsrv_fetch_array($bridgeStmt, SQLSRV_FETCH_ASSOC)) {
                        $bridgeDetails[] = [
                            'id' => (int)$bridgeRow['BridgeID'],
                            'name' => $bridgeRow['BridgeName'],
                            'lat' => (float)$bridgeRow['LatDegrees'],
                            'lng' => (float)$bridgeRow['LonDegrees'],
                            'description' => $bridgeRow['Description'],
                            'connects' => $bridgeRow['Geofence1Name'] . ' ↔ ' . $bridgeRow['Geofence2Name']
                        ];
                        sqlsrv_free_stmt($bridgeStmt);
                    }
                }
                
                // Get bridge names for display
                $bridgeNames = array_map(function($b) { return $b['name']; }, $bridgeDetails);
                
                $response['paths'][] = [
                    'path_id' => $pathRow['PathID'],
                    'path_length' => $pathRow['PathLength'],
                    'segment_count' => count($geofencePath),
                    'transfer_count' => count($bridgePath),
                    'geofence_path' => $pathRow['GeofencePath'],
                    'bridge_path' => $pathRow['BridgePath'],
                    'geofence_names' => $geofenceNames,
                    'bridge_names' => $bridgeNames,
                    'bridge_details' => $bridgeDetails,
                    'route_description' => implode(' → ', $geofenceNames)
                ];
            }
            sqlsrv_free_stmt($pathStmt);
        } else {
            $response['error'] = 'Failed to execute spFindGeofencePaths';
            $response['sql_errors'] = sqlsrv_errors();
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    echo json_encode([
        'error' => 'Fatal Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
