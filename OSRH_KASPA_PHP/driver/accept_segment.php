<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('driver/dashboard.php');
    exit;
}

$user      = current_user();
$driverRow = $user['driver'] ?? null;
$driverID  = $driverRow['DriverID'] ?? null;

if (!$driverID) {
    flash_error('Driver profile not found.');
    redirect('driver/dashboard.php');
    exit;
}

$segmentID = (int)($_POST['segment_id'] ?? 0);

if (!$segmentID) {
    flash_add('error', 'Invalid segment ID.');
    redirect('driver/trips_assigned.php');
    exit;
}

// Check if driver already has an active segment trip
$db = db();
$checkStmt = sqlsrv_query($db, "EXEC dbo.spCheckDriverActiveSegmentTrip @DriverID = ?", [$driverID]);
if ($checkStmt) {
    $checkResult = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    
    if ($checkResult && $checkResult['ActiveCount'] > 0) {
        flash_add('error', 'You already have an active segment trip. Complete or cancel it before accepting another segment.');
        redirect('driver/trips_assigned.php');
        exit;
    }
}

try {
    // Call stored procedure to assign driver to segment using sqlsrv
    $stmt = db_call_procedure('dbo.spAssignDriverToSegment', [$segmentID, $driverID]);
    $result = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($stmt) sqlsrv_free_stmt($stmt);

    if ($result && isset($result['TripID']) && $result['TripID'] > 0) {
        $newTripId = (int)$result['TripID'];
        
        // Check if driver uses GPS
        $useGpsRow = db_fetch_one('SELECT UseGPS FROM dbo.Driver WHERE DriverID = ?', [$driverID]);
        $driverUsesGPS = ($useGpsRow && !empty($useGpsRow['UseGPS']));
        
        // Check if trip is marked as real driver trip
        $tripCheck = db_fetch_one('SELECT IsRealDriverTrip FROM dbo.Trip WHERE TripID = ?', [$newTripId]);
        $isRealTrip = ($tripCheck && !empty($tripCheck['IsRealDriverTrip']));
        
        flash_add('success', 'Segment accepted successfully! Trip #' . $newTripId . ' created.');
        
        // Redirect to trip detail page with GPS enabled if real driver
        $redirectUrl = 'driver/trip_detail.php?trip_id=' . urlencode((string)$newTripId);
        if ($isRealTrip || $driverUsesGPS) {
            $redirectUrl .= '&enable_gps=1&auto_start=1';
        }
        redirect($redirectUrl);
        exit;
    } else {
        $errorMsg = $result['ErrorMessage'] ?? 'Failed to assign segment.';
        flash_add('error', $errorMsg);
    }

} catch (Exception $e) {
    flash_add('error', 'Error accepting segment: ' . $e->getMessage());
}

redirect('driver/trips_assigned.php');
exit;
