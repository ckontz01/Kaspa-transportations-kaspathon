<?php
/**
 * Fare Calculation API Endpoint
 * Calls SQL stored procedure to calculate fare estimate
 * Returns JSON with fare breakdown
 */

require_once __DIR__ . '/../config/database.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get input parameters
    $serviceTypeId = isset($_POST['serviceTypeId']) ? (int)$_POST['serviceTypeId'] : 1;
    $distanceKm = isset($_POST['distanceKm']) ? (float)$_POST['distanceKm'] : 0;
    $durationMin = isset($_POST['durationMin']) ? (int)$_POST['durationMin'] : null;
    $driverId = isset($_POST['driverId']) ? (int)$_POST['driverId'] : null;
    
    // Validate inputs
    if ($distanceKm <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid distance']);
        exit;
    }
    
    if ($serviceTypeId < 1 || $serviceTypeId > 5) {
        echo json_encode(['success' => false, 'error' => 'Invalid service type']);
        exit;
    }
    
    // Call stored procedure
    $conn = get_db_connection();
    
    $sql = "EXEC dbo.spCalculateFareEstimate 
            @ServiceTypeID = ?, 
            @DistanceKm = ?, 
            @EstimatedDurationMin = ?,
            @DriverID = ?";
    
    $params = [
        $serviceTypeId,
        $distanceKm,
        $durationMin,
        $driverId
    ];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception('Database query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    if (!$result) {
        throw new Exception('No fare data returned from stored procedure');
    }
    
    // Return fare breakdown
    echo json_encode([
        'success' => true,
        'baseFare' => (float)$result['BaseFare'],
        'distanceFare' => (float)$result['DistanceFare'],
        'timeFare' => (float)$result['TimeFare'],
        'subtotal' => (float)$result['Subtotal'],
        'surgeMultiplier' => (float)$result['SurgeMultiplier'],
        'subtotalWithSurge' => (float)$result['SubtotalWithSurge'],
        'minimumFare' => (float)$result['MinimumFare'],
        'totalFare' => (float)$result['TotalFare'],
        'serviceFeeRate' => (float)$result['ServiceFeeRate'],
        'serviceFeeAmount' => (float)$result['ServiceFeeAmount'],
        'driverEarnings' => (float)$result['DriverEarnings'],
        'distanceKm' => (float)$result['DistanceKm'],
        'estimatedDurationMin' => (int)$result['EstimatedDurationMin'],
        'isSurgeActive' => (bool)$result['IsSurgeActive'],
        'pricePerKm' => (float)$result['PricePerKm'],
        'pricePerMinute' => (float)$result['PricePerMinute']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
