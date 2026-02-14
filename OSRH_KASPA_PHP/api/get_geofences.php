<?php
/**
 * API Endpoint: Get All Geofences with Points
 * 
 * Returns all active geofences with their boundary points for map visualization.
 * Also returns bridge locations connecting geofences.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour (geofences don't change often)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

// Handle segment lookup action (for multi-segment journey tracking)
$action = $_GET['action'] ?? '';
if ($action === 'get_segments') {
    $rideRequestId = filter_input(INPUT_GET, 'ride_request_id', FILTER_VALIDATE_INT);
    
    if (!$rideRequestId) {
        echo json_encode(['success' => false, 'error' => 'Invalid ride request ID']);
        exit;
    }
    
    try {
        $conn = db();
        if (!$conn) {
            throw new Exception('Database connection failed');
        }
        
        // Call stored procedure
        $stmt = sqlsrv_query($conn, "EXEC dbo.spGetRideSegments @RideRequestID = ?", [$rideRequestId]);
        if ($stmt === false) {
            throw new Exception('Failed to fetch segments');
        }
        
        $segments = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $segments[] = [
                'SegmentID' => (int)$row['SegmentID'],
                'SegmentOrder' => (int)$row['SegmentOrder'],
                'TripID' => $row['TripID'] ? (int)$row['TripID'] : null,
                'TripStatus' => $row['TripStatus'] ?? null,
                'EstimatedDistanceKm' => (float)($row['EstimatedDistanceKm'] ?? 0),
                'EstimatedFare' => (float)($row['EstimatedFare'] ?? 0)
            ];
        }
        sqlsrv_free_stmt($stmt);
        
        echo json_encode([
            'success' => true,
            'segments' => $segments
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

try {
    $conn = db();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Call stored procedure to get geofences and points
    $stmt = sqlsrv_query($conn, "EXEC dbo.spGetGeofencesWithPoints");
    if ($stmt === false) {
        throw new Exception('Failed to fetch geofences: ' . print_r(sqlsrv_errors(), true));
    }

    // First result set: Geofences
    $geofences = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $geofences[$row['GeofenceID']] = [
            'id' => (int)$row['GeofenceID'],
            'name' => $row['Name'],
            'description' => $row['Description'],
            'points' => []
        ];
    }

    // Move to second result set: Geofence points
    if (sqlsrv_next_result($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $geoId = (int)$row['GeofenceID'];
            if (isset($geofences[$geoId])) {
                $geofences[$geoId]['points'][] = [
                    'lat' => (float)$row['LatDegrees'],
                    'lng' => (float)$row['LonDegrees']
                ];
            }
        }
    }
    sqlsrv_free_stmt($stmt);

    // Call stored procedure to get bridges
    $stmt = sqlsrv_query($conn, "EXEC dbo.spGetGeofenceBridges");
    $bridges = [];
    
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bridges[] = [
                'id' => (int)$row['BridgeID'],
                'name' => $row['BridgeName'],
                'lat' => (float)$row['LatDegrees'],
                'lng' => (float)$row['LonDegrees'],
                'description' => $row['LocationDescription'],
                'geofence1Id' => (int)$row['Geofence1ID'],
                'geofence2Id' => (int)$row['Geofence2ID'],
                'geofence1Name' => $row['Geofence1Name'],
                'geofence2Name' => $row['Geofence2Name'],
                'connects' => $row['Geofence1Name'] . ' ↔ ' . $row['Geofence2Name']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    // Convert geofences to indexed array
    $geofenceList = array_values($geofences);

    echo json_encode([
        'success' => true,
        'geofences' => $geofenceList,
        'bridges' => $bridges,
        'count' => [
            'geofences' => count($geofenceList),
            'bridges' => count($bridges)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
