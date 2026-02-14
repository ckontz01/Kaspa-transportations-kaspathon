<?php
/**
 * API: Get all autonomous vehicles
 * Returns list of autonomous vehicles with their current status and location
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Allow from passenger pages
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $vehicles = [];
    
    $stmt = db_call_procedure('dbo.spGetAllAutonomousVehicles', []);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $vehicles[] = [
                'AutonomousVehicleID' => (int)$row['AutonomousVehicleID'],
                'VehicleCode' => $row['VehicleCode'],
                'VehicleTypeID' => (int)$row['VehicleTypeID'],
                'VehicleTypeName' => $row['VehicleTypeName'],
                'PlateNo' => $row['PlateNo'],
                'Make' => $row['Make'],
                'Model' => $row['Model'],
                'Year' => $row['Year'],
                'Color' => $row['Color'],
                'SeatingCapacity' => (int)$row['SeatingCapacity'],
                'IsWheelchairReady' => (bool)$row['IsWheelchairReady'],
                'Status' => $row['Status'],
                'CurrentLatitude' => $row['CurrentLatitude'] !== null ? (float)$row['CurrentLatitude'] : null,
                'CurrentLongitude' => $row['CurrentLongitude'] !== null ? (float)$row['CurrentLongitude'] : null,
                'GeofenceID' => $row['GeofenceID'] !== null ? (int)$row['GeofenceID'] : null,
                'GeofenceName' => $row['GeofenceName'],
                'BatteryLevel' => $row['BatteryLevel'] !== null ? (int)$row['BatteryLevel'] : null,
                'IsActive' => (bool)$row['IsActive']
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    echo json_encode([
        'success' => true,
        'vehicles' => $vehicles,
        'count' => count($vehicles)
    ]);
    
} catch (Exception $e) {
    error_log('get_autonomous_vehicles.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to load vehicles']);
}
