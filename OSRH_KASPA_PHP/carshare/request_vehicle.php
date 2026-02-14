<?php
declare(strict_types=1);
/**
 * CARSHARE - Request Vehicle Page
 * 
 * This is the car-sharing interface (option ii) for renting driverless vehicles.
 * Separate from the ride-hailing system (option i).
 * 
 * Flow:
 * 1. Customer searches for available vehicles near them
 * 2. Select a vehicle to see details and pricing
 * 3. Book/reserve the vehicle (20 min to unlock)
 * 4. Unlock and start rental
 * 5. Drive to destination
 * 6. Park in designated zone and end rental
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

if (!defined('CARSHARE_REQUEST_VEHICLE_SHUTDOWN')) {
    define('CARSHARE_REQUEST_VEHICLE_SHUTDOWN', true);
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $log = '[' . date('c') . '] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL;
            @file_put_contents(__DIR__ . '/request_vehicle_error.log', $log, FILE_APPEND);
        }
    });
}

require_login();
if (is_operator()) {
    redirect('operator/carshare_approvals.php');
}
require_role('passenger');

$carshareCustomer = null;
$carshareRegistered = false;
$zones = [];
$vehicleTypes = [];

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId = (int)$passengerRow['PassengerID'];

$stmtCustomer = db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if ($stmtCustomer) {
    $carshareCustomer = sqlsrv_fetch_array($stmtCustomer, SQLSRV_FETCH_ASSOC) ?: null;
    sqlsrv_free_stmt($stmtCustomer);
}

// Check for active driver trip or autonomous ride (restricts booking new carshare)
$hasOtherActiveRide = false;
$otherActiveRideType = null;
$otherActiveRideRedirect = null;

$stmtTrip = @db_call_procedure('dbo.spGetPassengerActiveTrip', [$passengerId]);
if ($stmtTrip !== false && $stmtTrip !== null) {
    $activeDriverTrip = @sqlsrv_fetch_array($stmtTrip, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmtTrip);
    if ($activeDriverTrip && !empty($activeDriverTrip['TripID'])) {
        $hasOtherActiveRide = true;
        $otherActiveRideType = 'driver';
        $otherActiveRideRedirect = 'passenger/ride_detail.php?trip_id=' . $activeDriverTrip['TripID'];
    }
}

if (!$hasOtherActiveRide) {
    $stmtAV = @db_call_procedure('dbo.spGetPassengerActiveAutonomousRide', [$passengerId]);
    if ($stmtAV !== false && $stmtAV !== null) {
        $activeAVRide = @sqlsrv_fetch_array($stmtAV, SQLSRV_FETCH_ASSOC);
        @sqlsrv_free_stmt($stmtAV);
        if ($activeAVRide && !empty($activeAVRide['AutonomousRideID'])) {
            $hasOtherActiveRide = true;
            $otherActiveRideType = 'autonomous';
            $otherActiveRideRedirect = 'passenger/autonomous_ride_detail.php?ride_id=' . $activeAVRide['AutonomousRideID'];
        }
    }
}

if ($hasOtherActiveRide) {
    $rideTypeText = $otherActiveRideType === 'driver' ? 'driver trip' : 'autonomous ride';
    flash_add('info', "You have an active {$rideTypeText} in progress. Please complete it before booking a car-share vehicle.");
    redirect($otherActiveRideRedirect);
}

if ($carshareCustomer) {
    $carshareRegistered = true;
    if (empty($carshareCustomer['MembershipTier'])) {
        $carshareCustomer['MembershipTier'] = 'basic';
    }
}

$activeBookingPayload = null;
$activeTeleDrivePayload = null;

$stmtZones = db_query('EXEC dbo.CarshareListActiveZones');
if ($stmtZones) {
    while ($row = sqlsrv_fetch_array($stmtZones, SQLSRV_FETCH_ASSOC)) {
        $zones[] = $row;
    }
    sqlsrv_free_stmt($stmtZones);
}

$stmtVehicleTypes = db_query('EXEC dbo.CarshareListActiveVehicleTypes');
if ($stmtVehicleTypes) {
    while ($row = sqlsrv_fetch_array($stmtVehicleTypes, SQLSRV_FETCH_ASSOC)) {
        $vehicleTypes[] = $row;
    }
    sqlsrv_free_stmt($stmtVehicleTypes);
}

// Check for active booking/rental
$activeBooking = null;
$activeRental = null;
$activeTeleDrive = null;
$rentalHistory = [];
$paymentHistory = [];

if ($carshareCustomer) {
    $customerId = (int)$carshareCustomer['CustomerID'];
    
    // Check active booking
    $stmtBooking = db_query('EXEC dbo.CarshareGetActiveBooking ?', [$customerId]);
    if ($stmtBooking) {
        $activeBooking = sqlsrv_fetch_array($stmtBooking, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtBooking);
    }

    if ($activeBooking) {
        $stmtTele = db_query('EXEC dbo.CarshareGetLatestTeleDriveByBooking ?', [(int)$activeBooking['BookingID']]);

        if ($stmtTele) {
            $activeTeleDrive = sqlsrv_fetch_array($stmtTele, SQLSRV_FETCH_ASSOC) ?: null;
            sqlsrv_free_stmt($stmtTele);
        }
    }
    
    // Check active rental
    $stmtRental = db_query('EXEC dbo.CarshareGetActiveRental ?', [$customerId]);
    if ($stmtRental) {
        $activeRental = sqlsrv_fetch_array($stmtRental, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtRental);
    }

    // Recent completed rentals
    $stmtHistory = db_query('EXEC dbo.CarshareGetRecentRentals ?, ?', [$customerId, 5]);
    if ($stmtHistory) {
        while ($row = sqlsrv_fetch_array($stmtHistory, SQLSRV_FETCH_ASSOC)) {
            $rentalHistory[] = $row;
        }
        sqlsrv_free_stmt($stmtHistory);
    }

    // Recent payments
    $stmtPayments = db_query('EXEC dbo.CarshareGetRecentPayments ?, ?', [$customerId, 5]);
    if ($stmtPayments) {
        while ($row = sqlsrv_fetch_array($stmtPayments, SQLSRV_FETCH_ASSOC)) {
            $paymentHistory[] = $row;
        }
        sqlsrv_free_stmt($stmtPayments);
    }
}

if ($activeBooking) {
    $activeBookingPayload = [
        'bookingId'            => (int)$activeBooking['BookingID'],
        'vehicleId'            => (int)$activeBooking['VehicleID'],
        'pickupZoneId'         => (int)$activeBooking['PickupZoneID'],
        'zoneName'             => $activeBooking['ZoneName'] ?? 'Pickup zone',
        'zoneCenterLat'        => isset($activeBooking['ZoneCenterLatitude']) ? (float)$activeBooking['ZoneCenterLatitude'] : null,
        'zoneCenterLon'        => isset($activeBooking['ZoneCenterLongitude']) ? (float)$activeBooking['ZoneCenterLongitude'] : null,
        'vehicleLat'           => isset($activeBooking['CurrentLatitude']) ? (float)$activeBooking['CurrentLatitude'] : null,
        'vehicleLon'           => isset($activeBooking['CurrentLongitude']) ? (float)$activeBooking['CurrentLongitude'] : null,
        'reservationExpiresAt' => ($activeBooking['ReservationExpiresAt'] instanceof DateTime)
            ? $activeBooking['ReservationExpiresAt']->format('c')
            : null
    ];
}

if ($activeTeleDrive) {
    $activeTeleDrivePayload = [
        'teleDriveId'        => (int)$activeTeleDrive['TeleDriveID'],
        'status'             => (string)$activeTeleDrive['Status'],
        'startLat'           => isset($activeTeleDrive['StartLatitude']) ? (float)$activeTeleDrive['StartLatitude'] : null,
        'startLon'           => isset($activeTeleDrive['StartLongitude']) ? (float)$activeTeleDrive['StartLongitude'] : null,
        'targetLat'          => isset($activeTeleDrive['TargetLatitude']) ? (float)$activeTeleDrive['TargetLatitude'] : null,
        'targetLon'          => isset($activeTeleDrive['TargetLongitude']) ? (float)$activeTeleDrive['TargetLongitude'] : null,
        'estimatedDuration'  => isset($activeTeleDrive['EstimatedDurationSec']) ? (int)$activeTeleDrive['EstimatedDurationSec'] : null,
        'estimatedDistance'  => isset($activeTeleDrive['EstimatedDistanceKm']) ? (float)$activeTeleDrive['EstimatedDistanceKm'] : null,
        'routeGeometry'      => $activeTeleDrive['RouteGeometry'] ?? null,
        'startedAt'          => ($activeTeleDrive['StartedAt'] instanceof DateTime)
            ? $activeTeleDrive['StartedAt']->format('c')
            : (($activeTeleDrive['CreatedAt'] instanceof DateTime)
                ? $activeTeleDrive['CreatedAt']->format('c')
                : null),
        'arrivedAt'          => ($activeTeleDrive['ArrivedAt'] instanceof DateTime)
            ? $activeTeleDrive['ArrivedAt']->format('c')
            : null,
        'lastProgress'       => isset($activeTeleDrive['LastProgressPercent']) ? (float)$activeTeleDrive['LastProgressPercent'] : null
    ];
}

// Fetch operating areas (geofence boundaries)
$operatingAreas = [];
$operatingAreaPolygons = [];

$stmtAreas = db_query('EXEC dbo.CarshareGetOperatingAreas');
if ($stmtAreas) {
    while ($row = sqlsrv_fetch_array($stmtAreas, SQLSRV_FETCH_ASSOC)) {
        $operatingAreas[] = $row;
    }
    sqlsrv_free_stmt($stmtAreas);
}

$stmtPolygons = db_query('EXEC dbo.CarshareGetOperatingAreaPolygons');
if ($stmtPolygons) {
    while ($row = sqlsrv_fetch_array($stmtPolygons, SQLSRV_FETCH_ASSOC)) {
        $areaId = (int)$row['AreaID'];
        if (!isset($operatingAreaPolygons[$areaId])) {
            $operatingAreaPolygons[$areaId] = [];
        }
        $operatingAreaPolygons[$areaId][] = [
            (float)$row['LatDegrees'],
            (float)$row['LonDegrees']
        ];
    }
    sqlsrv_free_stmt($stmtPolygons);
}

// Fetch all available vehicles for initial map display
$allVehicles = [];
$stmtAllVehicles = db_query('EXEC dbo.CarshareGetAvailableVehicles');
if ($stmtAllVehicles) {
    while ($row = sqlsrv_fetch_array($stmtAllVehicles, SQLSRV_FETCH_ASSOC)) {
        $allVehicles[] = $row;
    }
    sqlsrv_free_stmt($stmtAllVehicles);
}

$pageTitle = 'Car Share - Rent a Vehicle';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Car Share specific styles */
.carshare-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1rem;
}

.carshare-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.carshare-header h1 {
    color: var(--color-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.carshare-header h1::before {
    content: "🚗";
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.status-badge.approved { background: var(--color-success); color: white; }
.status-badge.pending { background: var(--color-warning); color: #1a1a1a; }
.status-badge.rejected { background: var(--color-danger); color: white; }

/* Registration prompt */
.registration-prompt {
    background: linear-gradient(135deg, var(--color-primary-dark), var(--color-primary));
    border-radius: 1rem;
    padding: 2rem;
    text-align: center;
    color: white;
    margin-bottom: 2rem;
}

.registration-prompt h2 {
    margin-bottom: 1rem;
}

.registration-prompt p {
    margin-bottom: 1.5rem;
    opacity: 0.9;
}

/* Active rental panel */
.active-rental-panel {
    background: var(--color-success);
    color: white;
    border-radius: 1rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.rental-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}

.rental-stat {
    background: rgba(255,255,255,0.2);
    padding: 1rem;
    border-radius: 0.5rem;
    text-align: center;
}

.rental-stat .value {
    font-size: 1.5rem;
    font-weight: 700;
}

.rental-stat .label {
    font-size: 0.875rem;
    opacity: 0.9;
}

/* Search section */
.search-section {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

@media (max-width: 900px) {
    .search-section {
        grid-template-columns: 1fr;
    }
}

.map-container {
    height: 500px;
    border-radius: 1rem;
    overflow: hidden;
    border: 2px solid var(--color-border);
}

.search-filters {
    background: var(--color-surface);
    border-radius: 1rem;
    padding: 1.5rem;
}

.search-filters h3 {
    margin-bottom: 1rem;
    color: var(--color-primary);
}

.filter-group {
    margin-bottom: 1rem;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 0.75rem;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border);
    background: var(--color-bg);
    color: var(--color-text);
}

.filter-group .checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.filter-group .checkbox-label input {
    width: auto;
}

/* Tele-drive panel */
.tele-drive-card {
    margin: 1.5rem 0 2rem;
    background: var(--color-surface);
    border-radius: 1rem;
    padding: 1.5rem;
    border: 2px solid rgba(255,255,255,0.1);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.tele-drive-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.tele-drive-header h3 {
    margin: 0 0 0.25rem;
    color: var(--color-primary);
}

.tele-drive-badge {
    background: #ff9800;
    color: #1a1a1a;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.tele-drive-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 0.75rem;
}

.tele-drive-actions .btn-carshare,
.tele-drive-actions .btn-outline {
    flex: 1;
    min-width: 180px;
}

.tele-drive-note {
    font-size: 0.9rem;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
}

.tele-drive-tracking {
    background: rgba(0,0,0,0.2);
    border-radius: 0.75rem;
    padding: 1rem;
}

.tele-drive-status {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 0.75rem;
    gap: 1rem;
}

.tele-drive-progress {
    height: 8px;
    border-radius: 999px;
    background: rgba(255,255,255,0.1);
    overflow: hidden;
    margin-bottom: 0.75rem;
}

.tele-drive-progress-fill {
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, #00c6ff, #0072ff);
    transition: width 0.6s ease;
}

.tele-drive-status-message {
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

/* Simulation speed controls */
.tele-drive-simulation-controls {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 0.5rem;
    padding: 0.6rem 1rem;
    margin-bottom: 0.75rem;
}

.tele-drive-speed-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.tele-drive-speed-slider-wrap {
    flex: 1;
    min-width: 140px;
}

.tele-drive-speed-label {
    font-size: 0.75rem;
    color: #f59e0b;
    display: block;
    margin-bottom: 0.3rem;
}

#tele-drive-speed-slider {
    width: 100%;
    cursor: pointer;
    accent-color: #f59e0b;
}

.tele-drive-speed-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-speed {
    padding: 0.2rem 0.5rem;
    font-size: 0.7rem;
    background: #374151;
    border: none;
    border-radius: 4px;
    color: #e5e7eb;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-speed:hover {
    background: #4b5563;
}

.tele-drive-speed-hint {
    font-size: 0.65rem;
    color: #94a3b8;
    margin: 0.3rem 0 0 0;
}

.tele-drive-map {
    height: 260px;
    border-radius: 0.75rem;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.1);
}

.tele-drive-modal-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 1rem;
}

@media (max-width: 900px) {
    .tele-drive-modal-grid {
        grid-template-columns: 1fr;
    }
    .tele-drive-actions {
        flex-direction: column;
    }
}

#tele-drive-location-map {
    height: 360px;
    border-radius: 0.75rem;
    overflow: hidden;
    border: 1px solid var(--color-border);
}

.tele-drive-modal-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.tele-drive-modal-sidebar {
    background: var(--color-bg);
    border-radius: 0.75rem;
    padding: 1rem;
}

.tele-drive-location-summary {
    font-size: 0.95rem;
    line-height: 1.4;
}

.tele-drive-modal-hint {
    display: block;
    margin-top: 0.5rem;
    color: var(--color-text-secondary);
}

.tele-drive-modal-copy {
    color: var(--color-text-secondary);
}

/* Vehicle cards */
.vehicle-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.vehicle-card {
    background: var(--color-surface);
    border-radius: 1rem;
    overflow: hidden;
    border: 2px solid var(--color-border);
    transition: all 0.3s ease;
    cursor: pointer;
}

.vehicle-card:hover {
    border-color: var(--color-primary);
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.vehicle-card.selected {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.3);
}

.vehicle-card-header {
    padding: 1rem;
    background: var(--color-primary);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.vehicle-type-badge {
    background: rgba(255,255,255,0.2);
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
}

.vehicle-card-body {
    padding: 1.25rem;
}

.vehicle-name {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.vehicle-plate {
    color: var(--color-text-secondary);
    font-family: monospace;
    margin-bottom: 1rem;
}

.vehicle-features {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.vehicle-feature {
    background: var(--color-bg);
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.vehicle-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    margin-bottom: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border);
}

.vehicle-stat {
    text-align: center;
}

.vehicle-stat .value {
    font-weight: 600;
    color: var(--color-primary);
}

.vehicle-stat .label {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.vehicle-pricing {
    background: var(--color-bg);
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
}

.pricing-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.25rem;
}

.pricing-row .price {
    font-weight: 600;
    color: var(--color-primary);
}

.vehicle-distance {
    text-align: center;
    padding: 0.75rem;
    background: var(--color-primary-dark);
    color: white;
    border-radius: 0.5rem;
    font-weight: 500;
}

/* Booking modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--color-surface);
    border-radius: 1rem;
    max-width: 500px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content.modal-large {
    max-width: 960px;
    width: 100%;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    color: var(--color-primary);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--color-text-secondary);
}

.modal-body {
    padding: 1.5rem;
}

.end-rental-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 1.25rem;
}

@media (max-width: 900px) {
    .end-rental-grid {
        grid-template-columns: 1fr;
    }
}

#end-rental-map {
    width: 100%;
    height: 420px;
    border-radius: 0.75rem;
    border: 1px solid var(--color-border);
    overflow: hidden;
}

.end-map-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.75rem;
}

.end-location-panel {
    background: var(--color-bg);
    border-radius: 0.75rem;
    padding: 1rem;
    border: 1px dashed var(--color-border);
}

.end-location-summary {
    background: var(--color-surface);
    border-radius: 0.5rem;
    border: 1px solid var(--color-border);
    padding: 0.75rem;
    font-size: 0.9rem;
    line-height: 1.4;
    min-height: 96px;
}

.end-location-summary strong {
    display: block;
    color: var(--color-primary);
}

.end-location-hints {
    margin: 0.75rem 0 0;
    padding-left: 1.25rem;
    font-size: 0.9rem;
    color: var(--color-text-secondary);
}

.end-location-hints li {
    margin-bottom: 0.35rem;
}

.end-location-warning {
    margin-top: 0.5rem;
    color: #f97316;
    font-weight: 600;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

/* Zone markers */
.zone-marker {
    background: var(--color-primary);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 1rem;
    font-weight: 500;
    white-space: nowrap;
}

.zone-marker.pink {
    background: #e91e63;
}

.zone-marker.premium {
    background: #9c27b0;
}

.zone-marker.airport {
    background: #2196f3;
}

/* Loading state */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.8);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    color: white;
    font-size: 1.25rem;
}

.loading-overlay.active {
    display: flex;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--color-text-secondary);
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}

/* Button styles */
.btn-carshare {
    background: linear-gradient(135deg, #4CAF50, #45a049);
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-carshare:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
}

.btn-carshare:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-outline {
    background: transparent;
    border: 2px solid var(--color-border);
    color: var(--color-text);
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 500;
    cursor: pointer;
}

.btn-danger {
    background: var(--color-danger);
    color: white;
}

/* History & payments */
.history-section {
    margin-top: 3rem;
}

.history-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.5rem;
}

.history-card {
    background: var(--color-surface);
    border-radius: 1rem;
    border: 2px solid var(--color-border);
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.history-card h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: var(--color-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.history-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.history-item {
    padding: 1rem;
    border-radius: 0.75rem;
    border: 1px solid var(--color-border);
    background: var(--color-bg);
}

.history-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}

.history-item-meta {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

.history-item-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.75rem;
    font-size: 0.85rem;
}

.status-chip {
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    text-transform: capitalize;
}

.status-chip.completed { background: rgba(76,175,80,0.15); color: #2e7d32; }
.status-chip.pending { background: rgba(255,193,7,0.2); color: #b28704; }
.status-chip.failed { background: rgba(244,67,54,0.15); color: #c62828; }
.status-chip.terminated { background: rgba(156,39,176,0.15); color: #7b1fa2; }
.status-chip.in_progress { background: rgba(33,150,243,0.15); color: #1565c0; }

.empty-history {
    text-align: center;
    padding: 1.5rem;
    color: var(--color-text-secondary);
    border: 1px dashed var(--color-border);
    border-radius: 0.75rem;
}
</style>

<div class="carshare-container">
    <div class="carshare-header">
        <h1>Car Share</h1>
        <?php if ($carshareRegistered && $carshareCustomer): ?>
            <div>
                <span class="status-badge <?php echo e(strtolower($carshareCustomer['VerificationStatus'])); ?>">
                    <?php echo e(ucfirst($carshareCustomer['VerificationStatus'])); ?>
                </span>
                <?php if ($carshareCustomer['MembershipTier'] !== 'basic'): ?>
                    <span class="status-badge" style="background: gold; color: #1a1a1a; margin-left: 0.5rem;">
                        <?php echo e(ucfirst($carshareCustomer['MembershipTier'])); ?> Member
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$carshareRegistered): ?>
        <!-- Registration prompt for new users -->
        <div class="registration-prompt">
            <h2>🚗 Welcome to OSRH Car Share!</h2>
            <p>Rent a car by the minute, hour, or day. Drive yourself anywhere in Cyprus!</p>
            <p>To get started, you'll need to register and verify your driver's license.</p>
            <a href="<?php echo e(url('carshare/register.php')); ?>" class="btn-carshare" style="display: inline-block; text-decoration: none;">
                Register for Car Share
            </a>
        </div>
    <?php elseif ($carshareCustomer['VerificationStatus'] === 'pending' || $carshareCustomer['VerificationStatus'] === 'documents_submitted'): ?>
        <!-- Pending verification -->
        <div class="registration-prompt" style="background: linear-gradient(135deg, #ff9800, #f57c00); color: #111;">
            <h2 style="color: #111;">⏳ Verification Pending</h2>
            <p style="color: #111;">Your account is being verified. This usually takes 1-2 business days.</p>
            <p style="color: #111;">You'll receive an email once your account is approved.</p>
        </div>
    <?php elseif ($carshareCustomer['VerificationStatus'] === 'rejected'): ?>
        <!-- Rejected -->
        <div class="registration-prompt" style="background: linear-gradient(135deg, #f44336, #d32f2f);">
            <h2>❌ Verification Failed</h2>
            <p>Unfortunately, your verification was not successful.</p>
            <?php if (!empty($carshareCustomer['VerificationNotes'])): ?>
                <p>Reason: <?php echo e($carshareCustomer['VerificationNotes']); ?></p>
            <?php endif; ?>
            <a href="<?php echo e(url('carshare/register.php')); ?>" class="btn-carshare" style="display: inline-block; text-decoration: none; background: white; color: #d32f2f;">
                Resubmit Documents
            </a>
        </div>
    <?php elseif ($activeRental): ?>
        <!-- Active rental panel -->
        <div class="active-rental-panel">
            <h2>🚗 You have an active rental!</h2>
            <div class="rental-info">
                <div class="rental-stat">
                    <div class="value"><?php echo e($activeRental['Make'] . ' ' . $activeRental['Model']); ?></div>
                    <div class="label"><?php echo e($activeRental['PlateNumber']); ?></div>
                </div>
                <div class="rental-stat">
                    <div class="value" id="rental-duration">--:--</div>
                    <div class="label">Duration</div>
                </div>
                <div class="rental-stat">
                    <div class="value">€<span id="rental-cost">0.00</span></div>
                    <div class="label">Estimated Cost</div>
                </div>
                <div class="rental-stat">
                    <div class="value"><?php echo e($activeRental['FuelLevelPercent']); ?>%</div>
                    <div class="label">Fuel Level</div>
                </div>
            </div>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <button onclick="openEndRentalModal(<?php echo (int)$activeRental['RentalID']; ?>)" class="btn-carshare btn-danger">
                    End Rental
                </button>
            </div>
        </div>
        
        <script>
            // Update rental duration and cost in real-time
            const rentalStartedAt = new Date('<?php echo $activeRental['StartedAt']->format('c'); ?>');
            const pricePerMinute = <?php echo (float)$activeRental['PricePerMinute']; ?>;
            
            function updateRentalStats() {
                const now = new Date();
                const diffMs = now - rentalStartedAt;
                const totalSeconds = Math.floor(diffMs / 1000);
                const hours = Math.floor(totalSeconds / 3600);
                const mins = Math.floor((totalSeconds % 3600) / 60);
                const secs = totalSeconds % 60;
                const diffMin = Math.floor(diffMs / 60000);
                
                document.getElementById('rental-duration').textContent = 
                    hours.toString().padStart(2, '0') + ':' + mins.toString().padStart(2, '0') + ':' + secs.toString().padStart(2, '0');
                document.getElementById('rental-cost').textContent = 
                    (diffMin * pricePerMinute).toFixed(2);
            }
            
            updateRentalStats();
            setInterval(updateRentalStats, 1000);
        </script>
        
    <?php elseif ($activeBooking): ?>
        <!-- Active booking panel -->
        <div class="active-rental-panel" style="background: var(--color-primary);">
            <h2>📅 You have a reservation!</h2>
            <div class="rental-info">
                <div class="rental-stat">
                    <div class="value"><?php echo e($activeBooking['Make'] . ' ' . $activeBooking['Model']); ?></div>
                    <div class="label"><?php echo e($activeBooking['PlateNumber']); ?></div>
                </div>
                <div class="rental-stat">
                    <div class="value"><?php echo e($activeBooking['ZoneName']); ?></div>
                    <div class="label">Pickup Zone</div>
                </div>
                <div class="rental-stat">
                    <div class="value" id="time-remaining">--:--</div>
                    <div class="label">Time to Unlock</div>
                </div>
            </div>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <button onclick="unlockVehicle(<?php echo (int)$activeBooking['BookingID']; ?>)" class="btn-carshare">
                    Unlock Vehicle & Start
                </button>
            </div>
        </div>

        <div class="tele-drive-card" id="tele-drive-panel"
             data-booking-id="<?php echo (int)$activeBooking['BookingID']; ?>"
             data-zone-id="<?php echo (int)$activeBooking['PickupZoneID']; ?>"
             data-zone-name="<?php echo e($activeBooking['ZoneName']); ?>">
            <div class="tele-drive-header">
                <div>
                    <h3>🚘 Drive this car to me</h3>
                    <p>Share a location within 10 km of the <?php echo e($activeBooking['ZoneName']); ?> zone and a tele-operator will bring the vehicle curbside.</p>
                </div>
                <span class="tele-drive-badge">Beta</span>
            </div>

            <div id="tele-drive-cta" class="tele-drive-actions">
                <button type="button" class="btn-carshare" onclick="teleDriveUseGPS()">📍 Use my GPS</button>
                <button type="button" class="btn-outline" onclick="openTeleDriveLocationModal()">Pick location on map</button>
                <button type="button" class="btn-carshare" id="tele-drive-request-btn" onclick="startTeleDrive()" disabled>
                    🚗 Drive to me
                </button>
            </div>
            <div class="tele-drive-note" id="tele-drive-distance-note">
                Set your location to unlock the remote delivery option.
            </div>

            <div id="tele-drive-tracking" class="tele-drive-tracking" style="display: none;">
                <div class="tele-drive-status">
                    <div>
                        <strong>Status:</strong>
                        <span id="tele-drive-status-label">Initializing...</span>
                    </div>
                    <div>
                        <strong>ETA:</strong>
                        <span id="tele-drive-eta-label">--</span>
                    </div>
                </div>
                <div class="tele-drive-progress">
                    <div class="tele-drive-progress-fill" id="tele-drive-progress-fill"></div>
                </div>
                <div class="tele-drive-status-message" id="tele-drive-status-message"></div>

                <!-- Simulation Speed Control -->
                <div id="tele-drive-simulation-controls" class="tele-drive-simulation-controls">
                    <div class="tele-drive-speed-row">
                        <div class="tele-drive-speed-slider-wrap">
                            <label class="tele-drive-speed-label">
                                ⚡ Simulation Speed: <span id="tele-drive-speed-value">1x</span>
                            </label>
                            <input type="range" id="tele-drive-speed-slider" min="1" max="50" value="1" step="1">
                        </div>
                        <div class="tele-drive-speed-buttons">
                            <button type="button" class="btn-speed" onclick="setTeleDriveSpeed(1)">1x</button>
                            <button type="button" class="btn-speed" onclick="setTeleDriveSpeed(5)">5x</button>
                            <button type="button" class="btn-speed" onclick="setTeleDriveSpeed(10)">10x</button>
                            <button type="button" class="btn-speed" onclick="setTeleDriveSpeed(25)">25x</button>
                        </div>
                    </div>
                    <p class="tele-drive-speed-hint">🧪 Speed up simulation to test tele-drive tracking.</p>
                </div>

                <div id="tele-drive-map" class="tele-drive-map"></div>
            </div>

            <input type="hidden" id="tele-drive-lat" value="">
            <input type="hidden" id="tele-drive-lon" value="">
        </div>
        
        <script>
            const expiresAt = new Date('<?php echo $activeBooking['ReservationExpiresAt']->format('c'); ?>');
            
            function updateCountdown() {
                const now = new Date();
                const diffMs = expiresAt - now;
                
                if (diffMs <= 0) {
                    document.getElementById('time-remaining').textContent = 'EXPIRED';
                    document.getElementById('time-remaining').style.color = '#ff5252';
                    return;
                }
                
                const mins = Math.floor(diffMs / 60000);
                const secs = Math.floor((diffMs % 60000) / 1000);
                document.getElementById('time-remaining').textContent = 
                    mins.toString().padStart(2, '0') + ':' + secs.toString().padStart(2, '0');
            }
            
            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
        
    <?php else: ?>
        <!-- Main search interface -->
        <div class="search-section">
            <div class="map-container" id="map"></div>
            
            <div class="search-filters">
                <h3>🔍 Find a Vehicle</h3>
                
                <div class="filter-group">
                    <label>Your Location</label>
                    <button onclick="useMyLocation()" class="btn-carshare" style="width: 100%;">
                        📍 Use My Location
                    </button>
                    <input type="hidden" id="user-lat" value="">
                    <input type="hidden" id="user-lon" value="">
                </div>
                
                <div class="filter-group">
                    <label>Search Radius</label>
                    <select id="filter-radius">
                        <option value="2">2 km</option>
                        <option value="5" selected>5 km</option>
                        <option value="10">10 km</option>
                        <option value="20">20 km</option>
                        <option value="50">All Cyprus</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Zone</label>
                    <select id="filter-zone">
                        <option value="">All Zones</option>
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo (int)$zone['ZoneID']; ?>">
                                <?php echo e($zone['ZoneName']); ?> (<?php echo (int)$zone['CurrentVehicleCount']; ?> cars)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Vehicle Type</label>
                    <select id="filter-type">
                        <option value="">All Types</option>
                        <?php foreach ($vehicleTypes as $type): ?>
                            <option value="<?php echo (int)$type['VehicleTypeID']; ?>">
                                <?php echo e($type['TypeName']); ?>
                                (€<?php echo number_format((float)$type['PricePerMinute'], 2); ?>/min)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="filter-electric">
                        Electric vehicles only
                    </label>
                </div>
                
                <div class="filter-group">
                    <label>Minimum Seats</label>
                    <select id="filter-seats">
                        <option value="">Any</option>
                        <option value="2">2+</option>
                        <option value="4">4+</option>
                        <option value="5">5+</option>
                        <option value="7">7+</option>
                    </select>
                </div>
                
                <button onclick="searchVehicles()" class="btn-carshare" style="width: 100%; margin-top: 1rem;">
                    Search Vehicles
                </button>
            </div>
        </div>
        
        <h2 style="margin-bottom: 1rem;">Available Vehicles</h2>
        <div class="vehicle-list" id="vehicle-list">
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <p>Use your location or select a zone to find available vehicles</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$jsonFlags = 0;
if (defined('JSON_UNESCAPED_UNICODE')) {
    $jsonFlags |= JSON_UNESCAPED_UNICODE;
}
if (defined('JSON_UNESCAPED_SLASHES')) {
    $jsonFlags |= JSON_UNESCAPED_SLASHES;
}

if (!function_exists('carshare_json_encode')) {
    function carshare_json_encode($value, $flags = 0)
    {
        if ($flags) {
            return json_encode($value, $flags);
        }
        return json_encode($value);
    }
}
?>

<?php if ($carshareCustomer): ?>
    <div class="carshare-container history-section">
        <h2 style="margin-bottom: 1rem;">History &amp; Payments</h2>
        <div class="history-grid">
            <div class="history-card">
                <h3>📘 Recent Trips</h3>
                <?php if (empty($rentalHistory)): ?>
                    <div class="empty-history">
                        No completed rentals yet. Once you finish a trip, it will appear here.
                    </div>
                <?php else: ?>
                    <ul class="history-list">
                        <?php foreach ($rentalHistory as $trip): ?>
                            <?php
                                $startAt = ($trip['StartedAt'] instanceof DateTime)
                                    ? $trip['StartedAt']->format('M d, Y H:i')
                                    : 'N/A';
                                $endAt = ($trip['EndedAt'] instanceof DateTime)
                                    ? $trip['EndedAt']->format('M d, Y H:i')
                                    : 'In progress';
                                $statusLabel = (string)($trip['Status'] ?? 'completed');
                                $statusRaw = strtolower($statusLabel);
                                $statusClass = 'in_progress';
                                if ($statusRaw === 'completed') {
                                    $statusClass = 'completed';
                                } elseif (in_array($statusRaw, ['terminated', 'ended_by_support'], true)) {
                                    $statusClass = 'terminated';
                                }
                                $distanceKm = isset($trip['DistanceKm']) ? (float)$trip['DistanceKm'] : 0.0;
                                $timeCost = isset($trip['TimeCost']) ? (float)$trip['TimeCost'] : 0.0;
                                $distanceCost = isset($trip['DistanceCost']) ? (float)$trip['DistanceCost'] : 0.0;
                                $bonusCredit = isset($trip['BonusCredit']) ? (float)$trip['BonusCredit'] : 0.0;
                                $interCityFee = isset($trip['InterCityFee']) ? (float)$trip['InterCityFee'] : 0.0;
                                $totalCost = isset($trip['TotalCost']) ? (float)$trip['TotalCost'] : 0.0;
                                $pricingModeLabel = (string)($trip['PricingMode'] ?? 'per_minute');
                            ?>
                            <li class="history-item">
                                <div class="history-item-header">
                                    <span>
                                        <?php echo e($trip['Make']); ?> <?php echo e($trip['Model']); ?>
                                        <small style="color: var(--color-text-secondary); font-weight: 400;">(<?php echo e($trip['PlateNumber']); ?>)</small>
                                    </span>
                                    <span class="status-chip <?php echo e($statusClass); ?>">
                                        <?php echo e(str_replace('_', ' ', $statusLabel)); ?>
                                    </span>
                                </div>
                                <div class="history-item-meta">
                                    <?php echo e($startAt); ?> → <?php echo e($endAt); ?>
                                </div>
                                <div class="history-item-meta">
                                    <?php echo e($trip['StartZoneName'] ?? 'Unknown Zone'); ?> → <?php echo e($trip['EndZoneName'] ?? 'Unknown Zone'); ?>
                                </div>
                                <div class="history-item-breakdown">
                                    <span>Distance: <?php echo number_format($distanceKm, 1); ?> km</span>
                                    <span>Total: €<?php echo number_format($totalCost, 2); ?></span>
                                    <span>Pricing: <?php echo e(str_replace('_', ' ', $pricingModeLabel)); ?></span>
                                    <?php if ($timeCost > 0): ?><span>Time: €<?php echo number_format($timeCost, 2); ?></span><?php endif; ?>
                                    <?php if ($distanceCost > 0): ?><span>Distance fee: €<?php echo number_format($distanceCost, 2); ?></span><?php endif; ?>
                                    <?php if ($interCityFee > 0): ?><span>Inter-city fee: €<?php echo number_format($interCityFee, 2); ?></span><?php endif; ?>
                                    <?php if ($bonusCredit > 0): ?><span>Bonus credit: -€<?php echo number_format($bonusCredit, 2); ?></span><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="history-card">
                <h3>💳 Recent Payments</h3>
                <?php if (empty($paymentHistory)): ?>
                    <div class="empty-history">
                        No payment activity yet. Complete a rental to generate a charge.
                    </div>
                <?php else: ?>
                    <ul class="history-list">
                        <?php foreach ($paymentHistory as $payment): ?>
                            <?php
                                $amount = isset($payment['Amount']) ? (float)$payment['Amount'] : 0.0;
                                $currency = $payment['CurrencyCode'] ?? 'EUR';
                                $createdAt = ($payment['CreatedAt'] instanceof DateTime)
                                    ? $payment['CreatedAt']->format('M d, Y H:i')
                                    : 'N/A';
                                $paymentStatusLabel = (string)($payment['Status'] ?? 'pending');
                                $statusRaw = strtolower($paymentStatusLabel);
                                $statusClass = 'pending';
                                if ($statusRaw === 'completed' || $statusRaw === 'paid') {
                                    $statusClass = 'completed';
                                } elseif (in_array($statusRaw, ['failed', 'declined'], true)) {
                                    $statusClass = 'failed';
                                }
                                $method = 'Payment Method #' . (int)($payment['PaymentMethodTypeID'] ?? 0);
                                switch ((int)($payment['PaymentMethodTypeID'] ?? 0)) {
                                    case 2:
                                        $method = 'Cash';
                                        break;
                                    case 3:
                                        $method = 'Kaspa (KAS)';
                                        break;
                                }
                            ?>
                            <li class="history-item">
                                <div class="history-item-header">
                                    <span>€<?php echo number_format($amount, 2); ?> <?php echo e($currency); ?></span>
                                    <span class="status-chip <?php echo e($statusClass); ?>">
                                        <?php echo e($paymentStatusLabel); ?>
                                    </span>
                                </div>
                                <div class="history-item-meta">
                                    <?php echo e($createdAt); ?> • <?php echo e(ucfirst($payment['PaymentType'] ?? 'rental')); ?>
                                </div>
                                <div class="history-item-breakdown">
                                    <span>Method: <?php echo e($method); ?></span>
                                    <?php if (!empty($payment['RentalID'])): ?>
                                        <span>Rental #<?php echo (int)$payment['RentalID']; ?></span>
                                    <?php endif; ?>
                                    <span>Payment #<?php echo (int)$payment['PaymentID']; ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Booking Modal -->
<div class="modal-overlay" id="booking-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Book Vehicle</h2>
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
        </div>
        <div class="modal-body" id="booking-modal-body">
            <!-- Filled dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn-outline" onclick="closeBookingModal()">Cancel</button>
            <button class="btn-carshare" id="confirm-booking-btn" onclick="confirmBooking()">
                Confirm Booking
            </button>
        </div>
    </div>
</div>

<!-- End Rental Modal -->
<div class="modal-overlay" id="end-rental-modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2>Confirm Parking Location</h2>
            <button class="modal-close" onclick="closeEndRentalModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 1rem; color: var(--color-text-secondary);">
                Drop a pin exactly where the vehicle is parked. Staying inside a colored drop-off zone avoids the
                €25 out-of-zone penalty. Crossing into a different service geofence adds an extra €100 surcharge.
            </p>
            <div class="end-rental-grid">
                <div>
                    <div id="end-rental-map"></div>
                    <div class="end-map-actions">
                        <button type="button" class="btn-carshare" onclick="captureEndLocationFromGPS()">📍 Use GPS</button>
                        <button type="button" class="btn-outline" onclick="clearEndLocation()">Reset Pin</button>
                    </div>
                </div>
                <div class="end-location-panel">
                    <input type="hidden" id="end-lat" value="">
                    <input type="hidden" id="end-lon" value="">
                    <div class="end-location-summary" id="end-location-summary">
                        Tap anywhere on the map or use GPS to mark your parking spot.
                    </div>
                    <ul class="end-location-hints">
                        <li>Colored circles indicate designated drop-off zones.</li>
                        <li>Parking outside a zone triggers the standard €25 penalty.</li>
                        <li>Finishing in a different geofence than you started adds €100.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-outline" type="button" onclick="closeEndRentalModal()">Cancel</button>
            <button class="btn-carshare" type="button" onclick="submitEndRental()">Confirm Drop-Off</button>
        </div>
    </div>
</div>

<!-- Tele-Drive Location Modal -->
<div class="modal-overlay" id="tele-drive-location-modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2>Choose delivery spot</h2>
            <button class="modal-close" onclick="closeTeleDriveLocationModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="tele-drive-modal-copy">
                Drop a pin or use GPS to let our tele-driver know where to deliver your reserved vehicle.
                This location must stay within 10 km of your pickup zone.
            </p>
            <div class="tele-drive-modal-grid">
                <div>
                    <div id="tele-drive-location-map"></div>
                    <div class="tele-drive-modal-actions">
                        <button type="button" class="btn-carshare" onclick="teleDriveUseGPS()">📍 Use my GPS</button>
                        <button type="button" class="btn-outline" onclick="teleDriveResetTarget()">Reset</button>
                    </div>
                </div>
                <div class="tele-drive-modal-sidebar">
                    <div id="tele-drive-location-summary" class="tele-drive-location-summary">
                        Tap anywhere on the map to set a meeting point.
                    </div>
                    <small class="tele-drive-modal-hint">
                        Tip: choose an open area where the car can safely pull over.
                    </small>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-outline" type="button" onclick="closeTeleDriveLocationModal()">Close</button>
        </div>
    </div>
</div>

<!-- Loading overlay -->
<div class="loading-overlay" id="loading-overlay">
    <div class="spinner"></div>
    <span id="loading-text">Loading...</span>
</div>

<script>
// Constants
const OSRM_URL = 'https://router.project-osrm.org';
const CYPRUS_CENTER = [35.1264, 33.4299];
const TELE_DRIVE_REQUEST_URL = '<?php echo e(url('carshare/api/tele_drive_request.php')); ?>';
const TELE_DRIVE_STATUS_URL = '<?php echo e(url('carshare/api/tele_drive_status.php')); ?>';
const CUSTOMER_ID = <?php echo $carshareCustomer ? (int)$carshareCustomer['CustomerID'] : 'null'; ?>;
const HAS_OTHER_ACTIVE_RIDE = <?php echo $hasOtherActiveRide ? 'true' : 'false'; ?>;
const OTHER_ACTIVE_RIDE_TYPE = <?php echo $otherActiveRideType ? "'" . e($otherActiveRideType) . "'" : 'null'; ?>;
const OTHER_ACTIVE_RIDE_REDIRECT = <?php echo $otherActiveRideRedirect ? "'" . e(url($otherActiveRideRedirect)) . "'" : 'null'; ?>;
const CARSHARE_ZONES = <?php echo carshare_json_encode($zones, $jsonFlags); ?>;
const ACTIVE_RENTAL_START = <?php
    if ($activeRental) {
        echo carshare_json_encode([
            'startLat' => isset($activeRental['StartLatitude']) ? (float)$activeRental['StartLatitude'] : null,
            'startLon' => isset($activeRental['StartLongitude']) ? (float)$activeRental['StartLongitude'] : null,
            'startZoneId' => isset($activeRental['StartZoneID']) ? (int)$activeRental['StartZoneID'] : null,
            'startZoneName' => $activeRental['StartZoneName'] ?? null
        ], $jsonFlags);
    } else {
        echo 'null';
    }
?>;

const CARSHARE_ZONES_LOOKUP = {};
if (Array.isArray(CARSHARE_ZONES)) {
    CARSHARE_ZONES.forEach(zone => {
        if (zone && typeof zone.ZoneID !== 'undefined') {
            CARSHARE_ZONES_LOOKUP[zone.ZoneID] = zone;
        }
    });
}

const ACTIVE_BOOKING_DATA = <?php echo $activeBookingPayload ? carshare_json_encode($activeBookingPayload, $jsonFlags) : 'null'; ?>;
const ACTIVE_TELE_DRIVE = <?php echo $activeTeleDrivePayload ? carshare_json_encode($activeTeleDrivePayload, $jsonFlags) : 'null'; ?>;
const TELE_DRIVE_SETTINGS = { maxRadiusKm: 10 };
const TELE_DRIVE_STORAGE_KEY = 'carshare.teleDriveTarget';
const TELE_DRIVE_POLL_INTERVAL = 5000;
const CSRF_TOKEN = '<?php echo csrf_token(); ?>';

const teleDriveState = {
    active: null,
    pollingTimer: null,
    map: null,
    routeLayer: null,
    vehicleMarker: null,
    targetMarker: null,
    mapFitted: false,
    speedMultiplier: 1
};

let teleDriveLocationMap = null;
let teleDriveLocationMarker = null;

function rememberTeleDriveTarget(lat, lon) {
    if (typeof Storage !== 'undefined') {
        try {
            localStorage.setItem(TELE_DRIVE_STORAGE_KEY, JSON.stringify({ lat, lon, updatedAt: Date.now() }));
        } catch (err) {
            console.warn('Failed to persist tele-drive target', err);
        }
    }
    setTeleDriveHiddenInputs(lat, lon);
}

function loadStoredTeleDriveTarget() {
    if (typeof Storage === 'undefined') {
        return;
    }
    try {
        const stored = localStorage.getItem(TELE_DRIVE_STORAGE_KEY);
        if (!stored) {
            return;
        }
        const parsed = JSON.parse(stored);
        if (parsed && typeof parsed.lat === 'number' && typeof parsed.lon === 'number') {
            setTeleDriveHiddenInputs(parsed.lat, parsed.lon);
        }
    } catch (err) {
        console.warn('Failed to load stored tele-drive target', err);
    }
}

function initTeleDrivePanel() {
    const panel = document.getElementById('tele-drive-panel');
    if (!panel) {
        return;
    }

    const cta = document.getElementById('tele-drive-cta');
    const tracking = document.getElementById('tele-drive-tracking');

    if (ACTIVE_TELE_DRIVE && ACTIVE_TELE_DRIVE.teleDriveId) {
        hydrateTeleDriveState(ACTIVE_TELE_DRIVE);
        startTeleDrivePolling(ACTIVE_TELE_DRIVE.teleDriveId);
        fetchTeleDriveStatus(ACTIVE_TELE_DRIVE.teleDriveId);
    } else {
        if (cta) {
            cta.style.display = 'flex';
        }
        if (tracking) {
            tracking.style.display = 'none';
        }
    }

    updateTeleDriveEligibility();
}

function setTeleDriveHiddenInputs(lat, lon) {
    const latInput = document.getElementById('tele-drive-lat');
    const lonInput = document.getElementById('tele-drive-lon');
    if (!latInput || !lonInput) {
        return;
    }
    if (typeof lat === 'number' && typeof lon === 'number') {
        latInput.value = lat;
        lonInput.value = lon;
    } else {
        latInput.value = '';
        lonInput.value = '';
    }
    updateTeleDriveEligibility();
    updateTeleDriveLocationSummary();
}

function getTeleDriveTarget() {
    const latInput = document.getElementById('tele-drive-lat');
    const lonInput = document.getElementById('tele-drive-lon');
    if (!latInput || !lonInput) {
        return { lat: null, lon: null };
    }
    const lat = parseFloat(latInput.value);
    const lon = parseFloat(lonInput.value);
    return {
        lat: Number.isFinite(lat) ? lat : null,
        lon: Number.isFinite(lon) ? lon : null
    };
}

function updateTeleDriveEligibility() {
    const button = document.getElementById('tele-drive-request-btn');
    const note = document.getElementById('tele-drive-distance-note');
    if (!button || !note) {
        return;
    }

    const { lat, lon } = getTeleDriveTarget();
    if (!ACTIVE_BOOKING_DATA) {
        button.disabled = true;
        note.textContent = 'Remote delivery becomes available once you have a reservation.';
        return;
    }

    if (lat === null || lon === null) {
        button.disabled = true;
        note.textContent = 'Set your meeting point to enable Drive to me.';
        return;
    }

    const zone = CARSHARE_ZONES_LOOKUP[ACTIVE_BOOKING_DATA.pickupZoneId] || null;
    const zoneLat = Number.isFinite(ACTIVE_BOOKING_DATA.zoneCenterLat)
        ? ACTIVE_BOOKING_DATA.zoneCenterLat
        : (zone ? Number(zone.CenterLatitude) : null);
    const zoneLon = Number.isFinite(ACTIVE_BOOKING_DATA.zoneCenterLon)
        ? ACTIVE_BOOKING_DATA.zoneCenterLon
        : (zone ? Number(zone.CenterLongitude) : null);

    if (!Number.isFinite(zoneLat) || !Number.isFinite(zoneLon)) {
        button.disabled = true;
        note.textContent = 'Pickup zone coordinates unavailable. Please refresh the page.';
        return;
    }

    const distanceKm = haversineDistanceMeters(lat, lon, zoneLat, zoneLon) / 1000;
    const maxKm = TELE_DRIVE_SETTINGS.maxRadiusKm;

    if (distanceKm <= maxKm) {
        button.disabled = false;
        const zoneName = ACTIVE_BOOKING_DATA.zoneName || 'pickup zone';
        note.textContent = `Great! You are ${distanceKm.toFixed(2)} km from ${zoneName}. Tap "Drive to me" when ready.`;
    } else {
        button.disabled = true;
        note.textContent = `Your pin is ${distanceKm.toFixed(2)} km away. Remote delivery works within ${maxKm} km of the pickup zone.`;
    }
}

function updateTeleDriveLocationSummary() {
    const summaryEl = document.getElementById('tele-drive-location-summary');
    if (!summaryEl) {
        return;
    }
    const { lat, lon } = getTeleDriveTarget();
    if (lat === null || lon === null) {
        summaryEl.textContent = 'Tap anywhere on the map or use GPS to set a meeting point.';
        return;
    }

    let message = `<div><strong>Latitude:</strong> ${lat.toFixed(6)}</div>`;
    message += `<div><strong>Longitude:</strong> ${lon.toFixed(6)}</div>`;

    if (ACTIVE_BOOKING_DATA) {
        const zone = CARSHARE_ZONES_LOOKUP[ACTIVE_BOOKING_DATA.pickupZoneId] || null;
        const zoneLat = Number.isFinite(ACTIVE_BOOKING_DATA.zoneCenterLat)
            ? ACTIVE_BOOKING_DATA.zoneCenterLat
            : (zone ? Number(zone.CenterLatitude) : null);
        const zoneLon = Number.isFinite(ACTIVE_BOOKING_DATA.zoneCenterLon)
            ? ACTIVE_BOOKING_DATA.zoneCenterLon
            : (zone ? Number(zone.CenterLongitude) : null);
        if (Number.isFinite(zoneLat) && Number.isFinite(zoneLon)) {
            const distanceKm = haversineDistanceMeters(lat, lon, zoneLat, zoneLon) / 1000;
            message += `<div><strong>Distance to zone:</strong> ${distanceKm.toFixed(2)} km</div>`;
        }
    }

    summaryEl.innerHTML = message;
}

function teleDriveResetTarget() {
    if (typeof Storage !== 'undefined') {
        localStorage.removeItem(TELE_DRIVE_STORAGE_KEY);
    }
    const latInput = document.getElementById('tele-drive-lat');
    const lonInput = document.getElementById('tele-drive-lon');
    if (latInput) latInput.value = '';
    if (lonInput) lonInput.value = '';
    if (teleDriveLocationMarker && teleDriveLocationMap) {
        teleDriveLocationMap.removeLayer(teleDriveLocationMarker);
        teleDriveLocationMarker = null;
    }
    updateTeleDriveEligibility();
    updateTeleDriveLocationSummary();
}

function teleDriveUseGPS() {
    if (!navigator.geolocation) {
        OSRH.alert('Geolocation is not supported by this browser.');
        return;
    }
    showLoading('Fetching your GPS location...');
    navigator.geolocation.getCurrentPosition(
        position => {
            hideLoading();
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;
            rememberTeleDriveTarget(lat, lon);
            if (teleDriveLocationMap) {
                updateTeleDriveLocationMarker(lat, lon, true);
            }
        },
        error => {
            hideLoading();
            OSRH.alert('Unable to get your location: ' + error.message);
        },
        { enableHighAccuracy: true, timeout: 12000 }
    );
}

function openTeleDriveLocationModal() {
    const modal = document.getElementById('tele-drive-location-modal');
    if (!modal) {
        return;
    }
    modal.classList.add('active');
    if (!teleDriveLocationMap) {
        initTeleDriveLocationMap();
    } else {
        setTimeout(() => teleDriveLocationMap.invalidateSize(), 150);
    }
}

function closeTeleDriveLocationModal() {
    const modal = document.getElementById('tele-drive-location-modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function initTeleDriveLocationMap() {
    const mapEl = document.getElementById('tele-drive-location-map');
    if (!mapEl) {
        return;
    }
    teleDriveLocationMap = L.map(mapEl).setView(CYPRUS_CENTER, 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(teleDriveLocationMap);

    drawZonesOnMap(teleDriveLocationMap);

    teleDriveLocationMap.on('click', event => {
        const lat = event.latlng.lat;
        const lon = event.latlng.lng;
        rememberTeleDriveTarget(lat, lon);
        updateTeleDriveLocationMarker(lat, lon, false);
    });

    const { lat, lon } = getTeleDriveTarget();
    if (lat !== null && lon !== null) {
        updateTeleDriveLocationMarker(lat, lon, true);
    }
}

function updateTeleDriveLocationMarker(lat, lon, panTo) {
    if (!teleDriveLocationMap) {
        return;
    }
    if (!teleDriveLocationMarker) {
        teleDriveLocationMarker = L.marker([lat, lon]).addTo(teleDriveLocationMap);
    } else {
        teleDriveLocationMarker.setLatLng([lat, lon]);
    }
    if (panTo) {
        teleDriveLocationMap.setView([lat, lon], Math.max(teleDriveLocationMap.getZoom(), 14));
    }
}

function startTeleDrive() {
    if (!ACTIVE_BOOKING_DATA) {
        OSRH.alert('You need an active reservation before requesting remote delivery.');
        return;
    }

    const { lat, lon } = getTeleDriveTarget();
    if (lat === null || lon === null) {
        OSRH.alert('Please set a delivery location first.');
        return;
    }

    const startLat = Number.isFinite(ACTIVE_BOOKING_DATA.vehicleLat)
        ? ACTIVE_BOOKING_DATA.vehicleLat
        : ACTIVE_BOOKING_DATA.zoneCenterLat;
    const startLon = Number.isFinite(ACTIVE_BOOKING_DATA.vehicleLon)
        ? ACTIVE_BOOKING_DATA.vehicleLon
        : ACTIVE_BOOKING_DATA.zoneCenterLon;

    if (!Number.isFinite(startLat) || !Number.isFinite(startLon)) {
        OSRH.alert('Vehicle location unavailable. Please try again after refreshing.');
        return;
    }

    const requestBtn = document.getElementById('tele-drive-request-btn');
    if (requestBtn) {
        requestBtn.disabled = true;
        requestBtn.textContent = 'Starting...';
    }

    showLoading('Summoning remote driver...');
    fetchRouteForTeleDrive(startLat, startLon, lat, lon)
        .then(route => requestTeleDrive(route, lat, lon))
        .catch(err => {
            console.error(err);
            OSRH.alert('Unable to start tele-drive: ' + err.message);
        })
        .finally(() => {
            hideLoading();
            if (requestBtn) {
                requestBtn.textContent = '🚗 Drive to me';
            }
        });
}

function fetchRouteForTeleDrive(startLat, startLon, targetLat, targetLon) {
    const fallback = () => {
        const distanceMeters = haversineDistanceMeters(startLat, startLon, targetLat, targetLon);
        const distanceKm = distanceMeters / 1000;
        const durationSec = Math.max(180, Math.round((distanceKm / 25) * 3600));
        return {
            distanceKm,
            durationSec,
            geometry: {
                type: 'LineString',
                coordinates: [
                    [startLon, startLat],
                    [targetLon, targetLat]
                ]
            }
        };
    };

    const url = `${OSRM_URL}/route/v1/driving/${startLon},${startLat};${targetLon},${targetLat}?overview=full&geometries=geojson`;
    return fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Routing service unavailable');
            }
            return response.json();
        })
        .then(data => {
            if (!data.routes || !data.routes.length) {
                return fallback();
            }
            const route = data.routes[0];
            return {
                distanceKm: route.distance / 1000,
                durationSec: Math.max(120, Math.round(route.duration)),
                geometry: route.geometry
            };
        })
        .catch(() => fallback());
}

function requestTeleDrive(route, targetLat, targetLon) {
    const payload = {
        booking_id: ACTIVE_BOOKING_DATA.bookingId,
        target_lat: targetLat,
        target_lon: targetLon,
        estimated_duration_sec: route.durationSec,
        estimated_distance_km: route.distanceKm,
        route_geometry: route.geometry,
        csrf_token: CSRF_TOKEN
    };

    return fetch(TELE_DRIVE_REQUEST_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(parseJsonResponse)
        .then(result => {
            if (!result.success) {
                throw new Error(result.error || 'Unknown error');
            }
            const normalized = normalizeTeleDrivePayload(result.data || {});
            hydrateTeleDriveState(normalized);
            startTeleDrivePolling(normalized.teleDriveId);
            fetchTeleDriveStatus(normalized.teleDriveId);
            return normalized;
        });
}

function normalizeTeleDrivePayload(payload) {
    if (!payload) {
        return {};
    }
    return {
        teleDriveId: payload.tele_drive_id ?? payload.teleDriveId ?? null,
        bookingId: payload.booking_id ?? payload.bookingId ?? (ACTIVE_BOOKING_DATA ? ACTIVE_BOOKING_DATA.bookingId : null),
        status: payload.status || 'pending',
        progressPercent: payload.progress_percent ?? payload.progressPercent ?? 0,
        remainingSeconds: payload.remaining_seconds ?? payload.remainingSeconds ?? null,
        etaText: payload.eta_text ?? payload.etaText ?? null,
        message: payload.message || payload.status_message || '',
        startLat: payload.start_lat ?? payload.startLat ?? null,
        startLon: payload.start_lon ?? payload.startLon ?? null,
        targetLat: payload.target_lat ?? payload.targetLat ?? null,
        targetLon: payload.target_lon ?? payload.targetLon ?? null,
        currentLat: payload.current_lat ?? payload.currentLat ?? payload.start_lat ?? null,
        currentLon: payload.current_lon ?? payload.currentLon ?? payload.start_lon ?? null,
        routeCoordinates: payload.route_coordinates ?? payload.routeGeometry ?? null,
        estimatedDistanceKm: payload.estimated_distance_km ?? payload.estimatedDistanceKm ?? null,
        estimatedDurationSec: payload.estimated_duration_sec ?? payload.estimatedDurationSec ?? null,
        speedMultiplier: payload.speed_multiplier ?? payload.speedMultiplier ?? 1
    };
}

function hydrateTeleDriveState(payload) {
    const panel = document.getElementById('tele-drive-panel');
    if (!panel) {
        return;
    }
    teleDriveState.active = payload;
    const cta = document.getElementById('tele-drive-cta');
    const tracking = document.getElementById('tele-drive-tracking');
    if (cta) {
        cta.style.display = 'none';
    }
    if (tracking) {
        tracking.style.display = 'block';
    }
    renderTeleDriveStatus(payload);
}

function startTeleDrivePolling(teleDriveId) {
    stopTeleDrivePolling();
    teleDriveState.pollingTimer = setInterval(() => {
        fetchTeleDriveStatus(teleDriveId);
    }, TELE_DRIVE_POLL_INTERVAL);
}

function stopTeleDrivePolling() {
    if (teleDriveState.pollingTimer) {
        clearInterval(teleDriveState.pollingTimer);
        teleDriveState.pollingTimer = null;
    }
}

function fetchTeleDriveStatus(teleDriveId) {
    if (!teleDriveId) {
        return Promise.resolve();
    }
    return fetch(TELE_DRIVE_STATUS_URL + '?tele_drive_id=' + encodeURIComponent(teleDriveId))
        .then(parseJsonResponse)
        .then(result => {
            if (!result.success) {
                throw new Error(result.error || 'Status unavailable');
            }
            const normalized = normalizeTeleDrivePayload(result.data || {});
            teleDriveState.active = normalized;
            renderTeleDriveStatus(normalized);
            return normalized;
        })
        .catch(err => console.error('Tele-drive status error:', err.message || err));
}

function renderTeleDriveStatus(status) {
    const statusLabel = document.getElementById('tele-drive-status-label');
    const etaLabel = document.getElementById('tele-drive-eta-label');
    const statusMessage = document.getElementById('tele-drive-status-message');
    const progressFill = document.getElementById('tele-drive-progress-fill');
    const speedSlider = document.getElementById('tele-drive-speed-slider');
    const speedValue = document.getElementById('tele-drive-speed-value');

    if (statusLabel) {
        statusLabel.textContent = status.status ? status.status.replace(/_/g, ' ') : 'pending';
    }
    if (etaLabel) {
        etaLabel.textContent = status.remainingSeconds != null ? formatEta(status.remainingSeconds) : '—';
    }
    if (statusMessage) {
        statusMessage.textContent = status.message || 'Remote driver en route to your location.';
    }
    if (progressFill) {
        const percent = Math.max(0, Math.min(100, status.progressPercent || 0));
        progressFill.style.width = percent + '%';
    }

    // Sync speed multiplier slider with server value
    if (status.speedMultiplier && status.speedMultiplier !== teleDriveState.speedMultiplier) {
        teleDriveState.speedMultiplier = status.speedMultiplier;
        if (speedSlider) speedSlider.value = status.speedMultiplier;
        if (speedValue) speedValue.textContent = status.speedMultiplier + 'x';
    }

    updateTeleDriveMapDisplay(status);

    if (status.status === 'arrived' || status.status === 'completed') {
        stopTeleDrivePolling();
        const btn = document.getElementById('tele-drive-request-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Vehicle is waiting';
        }
    }
}

function ensureTeleDriveMap() {
    if (teleDriveState.map) {
        return teleDriveState.map;
    }
    const mapEl = document.getElementById('tele-drive-map');
    if (!mapEl) {
        return null;
    }
    teleDriveState.map = L.map(mapEl).setView(CYPRUS_CENTER, 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(teleDriveState.map);
    return teleDriveState.map;
}

function toLatLngPairs(routeCoordinates, fallback) {
    if (routeCoordinates && Array.isArray(routeCoordinates.coordinates)) {
        return routeCoordinates.coordinates.map(pair => [pair[1], pair[0]]);
    }
    if (Array.isArray(routeCoordinates)) {
        return routeCoordinates.map(pair => [pair[1], pair[0]]);
    }
    return fallback;
}

function updateTeleDriveMapDisplay(status) {
    const map = ensureTeleDriveMap();
    if (!map) {
        return;
    }

    const fallbackRoute = [];
    if (Number.isFinite(status.startLat) && Number.isFinite(status.startLon)) {
        fallbackRoute.push([status.startLat, status.startLon]);
    }
    if (Number.isFinite(status.targetLat) && Number.isFinite(status.targetLon)) {
        fallbackRoute.push([status.targetLat, status.targetLon]);
    }

    const route = toLatLngPairs(status.routeCoordinates, fallbackRoute);
    if (route.length >= 2) {
        if (!teleDriveState.routeLayer) {
            teleDriveState.routeLayer = L.polyline(route, { color: '#00bcd4', weight: 4, opacity: 0.7 }).addTo(map);
        } else {
            teleDriveState.routeLayer.setLatLngs(route);
        }
    }

    if (Number.isFinite(status.targetLat) && Number.isFinite(status.targetLon)) {
        if (!teleDriveState.targetMarker) {
            teleDriveState.targetMarker = L.marker([status.targetLat, status.targetLon], {
                icon: L.divIcon({
                    className: 'tele-drive-target',
                    html: '<div style="background:#ff9800;color:#1a1a1a;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:600;">You</div>'
                }),
                interactive: false
            }).addTo(map);
        } else {
            teleDriveState.targetMarker.setLatLng([status.targetLat, status.targetLon]);
        }
    }

    if (Number.isFinite(status.currentLat) && Number.isFinite(status.currentLon)) {
        if (!teleDriveState.vehicleMarker) {
            teleDriveState.vehicleMarker = L.marker([status.currentLat, status.currentLon], {
                icon: L.divIcon({
                    className: 'tele-drive-vehicle',
                    html: '<div style="background:#00c6ff;color:#002b36;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;">🚗 Vehicle</div>'
                })
            }).addTo(map);
        } else {
            teleDriveState.vehicleMarker.setLatLng([status.currentLat, status.currentLon]);
        }
    }

    if (!teleDriveState.mapFitted && route.length >= 2) {
        const bounds = L.latLngBounds(route);
        map.fitBounds(bounds.pad(0.2));
        teleDriveState.mapFitted = true;
    }
}

function formatEta(seconds) {
    if (!Number.isFinite(seconds)) {
        return '—';
    }
    if (seconds < 60) {
        return seconds + 's';
    }
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return minutes + 'm ' + secs + 's';
}

function parseJsonResponse(response) {
    return response.text().then(text => {
        if (!text) {
            throw new Error('Empty response from server');
        }
        try {
            return JSON.parse(text);
        } catch (err) {
            console.error('Tele-drive API raw response:', text);
            throw new Error(text.slice(0, 200));
        }
    });
}

// Tele-drive speed control
function setTeleDriveSpeed(speed) {
    speed = Math.max(1, Math.min(50, speed));
    teleDriveState.speedMultiplier = speed;

    const slider = document.getElementById('tele-drive-speed-slider');
    const valueLabel = document.getElementById('tele-drive-speed-value');
    if (slider) slider.value = speed;
    if (valueLabel) valueLabel.textContent = speed + 'x';

    if (teleDriveState.active && teleDriveState.active.teleDriveId) {
        // Get current progress to preserve position on speed change
        const currentProgress = (teleDriveState.active.progressPercent || 0) / 100;
        updateTeleDriveSpeedOnServer(teleDriveState.active.teleDriveId, speed, currentProgress);
    }
}

function updateTeleDriveSpeedOnServer(teleDriveId, speed, currentProgress) {
    const payload = {
        tele_drive_id: teleDriveId,
        speed_multiplier: speed,
        current_progress: currentProgress || 0,
        csrf_token: CSRF_TOKEN
    };

    fetch(TELE_DRIVE_STATUS_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(parseJsonResponse)
        .then(result => {
            if (result.success) {
                console.log('Tele-drive speed updated to', speed + 'x', 'at progress', currentProgress);
            } else {
                console.warn('Speed update failed:', result.error);
            }
        })
        .catch(err => console.warn('Speed update error:', err));
}

function initTeleDriveSpeedSlider() {
    const slider = document.getElementById('tele-drive-speed-slider');
    if (!slider) return;

    slider.addEventListener('input', function() {
        const speed = parseInt(this.value, 10);
        setTeleDriveSpeed(speed);
    });
}

// Geofence colors (matching request_ride.php)
const geofenceColors = [
    { fill: '#3b82f6', border: '#1d4ed8', name: 'blue' },      // Nicosia
    { fill: '#ef4444', border: '#b91c1c', name: 'red' },       // Limassol
    { fill: '#22c55e', border: '#15803d', name: 'green' },     // Larnaca
    { fill: '#f59e0b', border: '#d97706', name: 'orange' },    // Paphos
    { fill: '#8b5cf6', border: '#6d28d9', name: 'purple' },    // Famagusta
    { fill: '#ec4899', border: '#be185d', name: 'pink' }       // Kyrenia
];

// State
let map = null;
let userMarker = null;
let vehicleMarkers = [];
let zoneCircles = [];
let operatingBoundaryLayer = null;
let selectedVehicle = null;
let geofenceLayers = [];
let cachedGeofenceData = null;
let geofenceFetchPromise = null;
let startGeofenceName = null;

let endRentalMap = null;
let endRentalMarker = null;
let endRentalMapInitialized = false;
let endingRentalId = null;
let selectedEndLocation = { lat: null, lon: null };

function ensureGeofenceData() {
    if (cachedGeofenceData) {
        return Promise.resolve(cachedGeofenceData);
    }

    if (geofenceFetchPromise) {
        return geofenceFetchPromise;
    }

    geofenceFetchPromise = fetch('../api/get_geofences.php')
        .then(response => response.json())
        .then(data => {
            cachedGeofenceData = data;

            if (ACTIVE_RENTAL_START && ACTIVE_RENTAL_START.startLat && ACTIVE_RENTAL_START.startLon) {
                startGeofenceName = findGeofenceName(
                    ACTIVE_RENTAL_START.startLat,
                    ACTIVE_RENTAL_START.startLon,
                    data
                );
            }

            return data;
        })
        .catch(err => {
            console.error('Error loading geofences:', err);
            throw err;
        })
        .finally(() => {
            geofenceFetchPromise = null;
        });

    return geofenceFetchPromise;
}

function drawGeofencesOnMap(targetMap) {
    if (!targetMap) {
        return;
    }

    ensureGeofenceData()
        .then(data => {
            if (data && data.geofences) {
                data.geofences.forEach((geofence, index) => {
                    if (!geofence.points || geofence.points.length < 3) {
                        return;
                    }

                    const color = geofenceColors[index % geofenceColors.length];
                    const latLngs = geofence.points.map(p => [p.lat, p.lng]);

                    const polygon = L.polygon(latLngs, {
                        color: color.border,
                        fillColor: color.fill,
                        fillOpacity: 0.12,
                        weight: 2,
                        dashArray: '5, 5'
                    }).addTo(targetMap);

                    const bounds = polygon.getBounds();
                    const center = bounds.getCenter();

                    L.marker(center, {
                        icon: L.divIcon({
                            className: 'geofence-label',
                            html: '<div style="background: ' + color.fill + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3); pointer-events: none;">' + 
                                  geofence.name.replace('_Region', '').replace('_', ' ') + '</div>',
                            iconSize: null,
                            iconAnchor: [40, 10]
                        }),
                        interactive: false
                    }).addTo(targetMap);
                });
            }

            if (data && data.bridges) {
                data.bridges.forEach(bridge => {
                    const bridgeIcon = L.divIcon({
                        className: 'bridge-marker',
                        html: '<div style="background: #fbbf24; border: 2px solid #d97706; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">🔗</div>',
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });

                    const marker = L.marker([bridge.lat, bridge.lng], { icon: bridgeIcon }).addTo(targetMap);
                    const popupContent = '<div style="text-align: center; min-width: 150px;">' +
                        '<strong>🔗 Transfer Point</strong><br>' +
                        '<span style="color: #666; font-size: 0.85em;">' + bridge.name.replace(/_/g, ' ') + '</span><br>' +
                        '<span style="color: #3b82f6; font-size: 0.8em;">' + bridge.connects + '</span>' +
                        '</div>';
                    marker.bindPopup(popupContent);
                });
            }
        })
        .catch(err => console.error('Error drawing geofences:', err));
}

// Initialize map
document.addEventListener('DOMContentLoaded', function() {
    loadStoredTeleDriveTarget();
    if (ACTIVE_BOOKING_DATA) {
        initTeleDrivePanel();
        initTeleDriveSpeedSlider();
    }
    <?php if ($carshareCustomer && $carshareCustomer['VerificationStatus'] === 'approved' && !$activeRental && !$activeBooking): ?>
    initMap();
    drawZonesOnMap(map, zoneCircles);
    drawGeofencesOnMap(map); // Load the geofences from API
    loadAllVehicles();
    <?php endif; ?>
});

function initMap() {
    map = L.map('map').setView(CYPRUS_CENTER, 10);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    
    // Add legend
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = function() {
        const div = L.DomUtil.create('div', 'map-legend');
        div.innerHTML = `
            <div style="background:var(--color-surface);padding:10px;border-radius:8px;font-size:12px;box-shadow:0 2px 10px rgba(0,0,0,0.3);">
                <strong style="display:block;margin-bottom:5px;">Legend</strong>
                <div><span style="display:inline-block;width:12px;height:12px;background:#4CAF50;border-radius:50%;margin-right:5px;"></span>Standard Zone</div>
                <div><span style="display:inline-block;width:12px;height:12px;background:#e91e63;border-radius:50%;margin-right:5px;"></span>Pink Zone (Bonus)</div>
                <div><span style="display:inline-block;width:12px;height:12px;background:#9c27b0;border-radius:50%;margin-right:5px;"></span>Premium Zone</div>
                <div><span style="display:inline-block;width:12px;height:12px;background:#2196f3;border-radius:50%;margin-right:5px;"></span>Airport Zone</div>
                <div><span style="display:inline-block;width:12px;height:3px;background:#ff5722;margin-right:5px;"></span>Operating Boundary</div>
                <div><span style="display:inline-block;width:12px;height:12px;background:#4CAF50;margin-right:5px;"></span>🚗 Vehicle</div>
            </div>
        `;
        return div;
    };
    legend.addTo(map);

    // Add click handler to set location
    map.on('click', function(e) {
        const lat = e.latlng.lat;
        const lon = e.latlng.lng;
        
        document.getElementById('user-lat').value = lat;
        document.getElementById('user-lon').value = lon;
        rememberTeleDriveTarget(lat, lon);
        
        if (userMarker) {
            map.removeLayer(userMarker);
        }
        
        userMarker = L.marker([lat, lon], {
            icon: L.divIcon({
                className: 'user-marker',
                html: '<div style="background:#2196f3;color:white;padding:5px 10px;border-radius:20px;font-weight:bold;white-space:nowrap;">📍 You</div>',
                iconSize: [60, 30],
                iconAnchor: [30, 15]
            })
        }).addTo(map);
        
        searchVehicles();
    });
}

function loadOperatingBoundary() {
    const areas = <?php echo json_encode($operatingAreas); ?>;
    const polygons = <?php echo json_encode($operatingAreaPolygons); ?>;
    
    areas.forEach(area => {
        if (area.UsePolygon == 1 && polygons[area.AreaID]) {
            // Draw polygon boundary
            const coords = polygons[area.AreaID];
            
            const color = area.AreaType === 'restricted' ? '#f44336' : '#ff5722';
            
            const polygon = L.polygon(coords, {
                color: color,
                weight: 3,
                fillColor: color,
                fillOpacity: 0.05,
                dashArray: area.AreaType === 'restricted' ? '10, 5' : null
            }).addTo(map);
            
            polygon.bindPopup(`
                <strong>${area.AreaName}</strong><br>
                ${area.Description || ''}<br>
                <small style="color:#ff5722">
                    ⚠️ Penalty if outside: €${parseFloat(area.PenaltyPerMinute).toFixed(2)}/min
                </small>
            `);
            
            operatingBoundaryLayer = polygon;
        } else if (area.CenterLatitude && area.CenterLongitude && area.RadiusMeters) {
            // Draw circular boundary
            const color = area.AreaType === 'restricted' ? '#f44336' : '#ff5722';
            
            const circle = L.circle([area.CenterLatitude, area.CenterLongitude], {
                radius: area.RadiusMeters,
                color: color,
                weight: 3,
                fillColor: color,
                fillOpacity: 0.05,
                dashArray: area.AreaType === 'restricted' ? '10, 5' : null
            }).addTo(map);
            
            circle.bindPopup(`
                <strong>${area.AreaName}</strong><br>
                ${area.Description || ''}<br>
                <small style="color:#ff5722">
                    ⚠️ Penalty if outside: €${parseFloat(area.PenaltyPerMinute).toFixed(2)}/min
                </small>
            `);
        }
    });
}

function loadAllVehicles() {
    const vehicles = <?php echo json_encode($allVehicles); ?>;
    
    vehicles.forEach(v => {
        if (v.CurrentLatitude && v.CurrentLongitude) {
            const iconColor = v.IsElectric == 1 ? '#00bcd4' : '#4CAF50';
            const icon = L.divIcon({
                className: 'vehicle-marker',
                html: `<div style="background:${iconColor};color:white;padding:3px 6px;border-radius:4px;font-size:10px;font-weight:bold;white-space:nowrap;box-shadow:0 2px 5px rgba(0,0,0,0.3);">🚗 ${v.Make}</div>`,
                iconSize: [70, 24],
                iconAnchor: [35, 12]
            });
            
            const marker = L.marker([v.CurrentLatitude, v.CurrentLongitude], { icon })
                .addTo(map)
                .bindPopup(`
                    <strong>${v.Make} ${v.Model}</strong><br>
                    <code>${v.PlateNumber}</code><br>
                    ${v.TypeName} ${v.IsElectric == 1 ? '⚡' : ''}<br>
                    ${v.IsElectric == 1 ? 'Battery' : 'Fuel'}: ${v.FuelLevelPercent}%<br>
                    ${v.ZoneName ? '📍 ' + v.ZoneName + ', ' + v.City : ''}
                `);
            
            vehicleMarkers.push(marker);
        }
    });
    
    // Fit map to show all markers
    if (vehicleMarkers.length > 0) {
        const group = L.featureGroup(vehicleMarkers);
        zoneCircles.forEach(z => group.addLayer(z));
        map.fitBounds(group.getBounds().pad(0.1));
    }
}

function drawZonesOnMap(targetMap, storageArray = null) {
    if (!targetMap || !Array.isArray(CARSHARE_ZONES)) {
        return;
    }

    CARSHARE_ZONES.forEach(zone => {
        if (!zone.CenterLatitude || !zone.CenterLongitude) {
            return;
        }

        const color = zone.ZoneType === 'pink' ? '#e91e63'
            : zone.ZoneType === 'premium' ? '#9c27b0'
            : zone.ZoneType === 'airport' ? '#2196f3'
            : '#4CAF50';

        const circle = L.circle([zone.CenterLatitude, zone.CenterLongitude], {
            radius: zone.RadiusMeters,
            color,
            fillColor: color,
            fillOpacity: 0.15,
            weight: 2
        }).addTo(targetMap);

        L.marker([zone.CenterLatitude, zone.CenterLongitude], {
            icon: L.divIcon({
                className: 'zone-label',
                html: `<div style="background:${color};color:white;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:500;white-space:nowrap;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.3);">${zone.ZoneName}<br><small>${zone.CurrentVehicleCount} 🚗</small></div>`,
                iconSize: [80, 30],
                iconAnchor: [40, 15]
            })
        }).addTo(targetMap);

        circle.bindPopup(`
            <strong>${zone.ZoneName}</strong><br>
            ${zone.City}<br>
            <small>${zone.CurrentVehicleCount} vehicles available</small>
            ${zone.BonusAmount ? '<br><span style="color:#e91e63">+€' + zone.BonusAmount + ' bonus for dropping off here!</span>' : ''}
            ${zone.InterCityFee ? '<br><span style="color:#ff9800">€' + zone.InterCityFee + ' intercity fee</span>' : ''}
        `);

        if (Array.isArray(storageArray)) {
            storageArray.push(circle);
        }
    });
}


function useMyLocation() {
    if (!navigator.geolocation) {
        OSRH.alert('Geolocation is not supported by your browser');
        return;
    }
    
    showLoading('Getting your location...');
    
    navigator.geolocation.getCurrentPosition(
        position => {
            hideLoading();
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;
            
            document.getElementById('user-lat').value = lat;
            document.getElementById('user-lon').value = lon;
            rememberTeleDriveTarget(lat, lon);
            
            if (userMarker) {
                map.removeLayer(userMarker);
            }
            
            userMarker = L.marker([lat, lon], {
                icon: L.divIcon({
                    className: 'user-marker',
                    html: '<div style="background:#2196f3;color:white;padding:5px 10px;border-radius:20px;font-weight:bold;white-space:nowrap;">📍 You</div>',
                    iconSize: [60, 30],
                    iconAnchor: [30, 15]
                })
            }).addTo(map);
            
            map.setView([lat, lon], 13);
            searchVehicles();
        },
        error => {
            hideLoading();
            OSRH.alert('Unable to get your location: ' + error.message);
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function searchVehicles() {
    const lat = document.getElementById('user-lat').value;
    const lon = document.getElementById('user-lon').value;
    const radius = document.getElementById('filter-radius').value;
    const zoneId = document.getElementById('filter-zone').value;
    const typeId = document.getElementById('filter-type').value;
    const electric = document.getElementById('filter-electric').checked ? '1' : '';
    const seats = document.getElementById('filter-seats').value;
    
    showLoading('Searching for vehicles...');
    
    const params = new URLSearchParams({
        lat: lat,
        lon: lon,
        radius: radius,
        zone_id: zoneId,
        type_id: typeId,
        electric: electric,
        min_seats: seats,
        customer_id: CUSTOMER_ID || ''
    });
    
    fetch('api/carshare_search.php?' + params)
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                displayVehicles(data.vehicles);
            } else {
                OSRH.alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            OSRH.alert('Failed to search for vehicles');
        });
}

function displayVehicles(vehicles) {
    const container = document.getElementById('vehicle-list');
    
    // Clear existing markers
    vehicleMarkers.forEach(m => map.removeLayer(m));
    vehicleMarkers = [];
    
    if (vehicles.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🚗</div>
                <p>No vehicles found in this area. Try expanding your search radius.</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = vehicles.map(v => `
        <div class="vehicle-card" onclick="selectVehicle(${v.VehicleID})" data-vehicle-id="${v.VehicleID}">
            <div class="vehicle-card-header">
                <span class="vehicle-type-badge">${v.TypeName}</span>
                ${v.IsElectric == 1 ? '<span class="vehicle-type-badge">⚡ Electric</span>' : ''}
            </div>
            <div class="vehicle-card-body">
                <div class="vehicle-name">${v.Make} ${v.Model}</div>
                <div class="vehicle-plate">${v.PlateNumber}</div>
                
                <div class="vehicle-features">
                    <span class="vehicle-feature">👤 ${v.SeatingCapacity} seats</span>
                    ${v.HasBluetooth == 1 ? '<span class="vehicle-feature">🔵 Bluetooth</span>' : ''}
                    ${v.HasUSBCharger == 1 ? '<span class="vehicle-feature">🔌 USB</span>' : ''}
                    ${v.HasChildSeat == 1 ? '<span class="vehicle-feature">👶 Child seat</span>' : ''}
                </div>
                
                <div class="vehicle-stats">
                    <div class="vehicle-stat">
                        <div class="value">${v.FuelLevelPercent}%</div>
                        <div class="label">${v.IsElectric == 1 ? 'Battery' : 'Fuel'}</div>
                    </div>
                    <div class="vehicle-stat">
                        <div class="value">${v.CleanlinessRating || '-'}/5</div>
                        <div class="label">Clean</div>
                    </div>
                    <div class="vehicle-stat">
                        <div class="value">${v.Year}</div>
                        <div class="label">Year</div>
                    </div>
                </div>
                
                <div class="vehicle-pricing">
                    <div class="pricing-row">
                        <span>Per Minute</span>
                        <span class="price">€${parseFloat(v.PricePerMinute).toFixed(2)}</span>
                    </div>
                    <div class="pricing-row">
                        <span>Per Hour</span>
                        <span class="price">€${parseFloat(v.PricePerHour).toFixed(2)}</span>
                    </div>
                    <div class="pricing-row">
                        <span>Per Km</span>
                        <span class="price">€${parseFloat(v.PricePerKm).toFixed(2)}</span>
                    </div>
                </div>
                
                ${v.DistanceKm !== null ? `
                    <div class="vehicle-distance">
                        📍 ${parseFloat(v.DistanceKm).toFixed(1)} km away
                    </div>
                ` : ''}
                
                ${v.IsEligible == 0 ? `
                    <div style="color: var(--color-danger); font-size: 0.875rem; margin-top: 0.5rem;">
                        ⚠️ ${v.EligibilityMessage}
                    </div>
                ` : ''}
            </div>
        </div>
    `).join('');
    
    // Add markers to map
    vehicles.forEach(v => {
        if (v.CurrentLatitude && v.CurrentLongitude) {
            const icon = L.divIcon({
                className: 'vehicle-marker',
                html: `<div style="background:#4CAF50;color:white;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:bold;">${v.Make}</div>`,
                iconSize: [60, 24],
                iconAnchor: [30, 12]
            });
            
            const marker = L.marker([v.CurrentLatitude, v.CurrentLongitude], { icon })
                .addTo(map)
                .on('click', () => selectVehicle(v.VehicleID));
            
            marker.vehicleData = v;
            vehicleMarkers.push(marker);
        }
    });
    
    // Fit map to show all markers
    if (vehicleMarkers.length > 0) {
        const group = L.featureGroup(vehicleMarkers);
        if (userMarker) group.addLayer(userMarker);
        map.fitBounds(group.getBounds().pad(0.1));
    }
}

function selectVehicle(vehicleId) {
    // Highlight selected card
    document.querySelectorAll('.vehicle-card').forEach(card => {
        card.classList.remove('selected');
    });
    const selectedCard = document.querySelector(`[data-vehicle-id="${vehicleId}"]`);
    if (selectedCard) {
        selectedCard.classList.add('selected');
    }
    
    // Find vehicle data
    const marker = vehicleMarkers.find(m => m.vehicleData && m.vehicleData.VehicleID == vehicleId);
    if (marker) {
        selectedVehicle = marker.vehicleData;
        map.setView([selectedVehicle.CurrentLatitude, selectedVehicle.CurrentLongitude], 15);
        openBookingModal(selectedVehicle);
    }
}

function openBookingModal(vehicle) {
    // Check if user has another active ride (driver trip or AV ride)
    if (HAS_OTHER_ACTIVE_RIDE) {
        const rideTypeText = OTHER_ACTIVE_RIDE_TYPE === 'driver' ? 'driver trip' : 'autonomous ride';
        OSRH.confirm(`You have an active ${rideTypeText} in progress. You cannot book a car-share vehicle until it is completed.\n\nWould you like to view your active ride?`, {
            title: 'Active Ride',
            confirmText: 'View Ride',
            cancelText: 'Cancel',
            type: 'warning',
            icon: '🚗'
        }).then(function(confirmed) {
            if (confirmed) {
                window.location.href = OTHER_ACTIVE_RIDE_REDIRECT;
            }
        });
        return;
    }
    
    const modal = document.getElementById('booking-modal');
    const body = document.getElementById('booking-modal-body');
    
    body.innerHTML = `
        <div style="text-align: center; margin-bottom: 1rem;">
            <h3>${vehicle.Make} ${vehicle.Model}</h3>
            <p style="color: var(--color-text-secondary);">${vehicle.PlateNumber}</p>
        </div>
        
        <div style="background: var(--color-bg); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
            <strong>Pickup Location:</strong><br>
            ${vehicle.ZoneName || 'Zone'}, ${vehicle.City || 'Cyprus'}
        </div>
        
        <div class="filter-group">
            <label>Pricing Mode</label>
            <select id="booking-pricing-mode">
                <option value="per_minute">Per Minute (€${parseFloat(vehicle.PricePerMinute).toFixed(2)}/min)</option>
                <option value="per_hour">Per Hour (€${parseFloat(vehicle.PricePerHour).toFixed(2)}/hour)</option>
                <option value="per_day">Per Day (€${parseFloat(vehicle.PricePerDay || 0).toFixed(2)}/day)</option>
            </select>
        </div>
        
        <div style="background: var(--color-bg); padding: 1rem; border-radius: 0.5rem;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span>Security Deposit</span>
                <strong>€${parseFloat(vehicle.DepositAmount || 50).toFixed(2)}</strong>
            </div>
            <small style="color: var(--color-text-secondary);">
                Deposit will be held on your card and released after the rental.
            </small>
        </div>
        
        <div style="margin-top: 1rem; padding: 1rem; background: var(--color-primary-dark); color: white; border-radius: 0.5rem;">
            <strong>⏱️ You have 20 minutes to unlock the vehicle after booking.</strong>
        </div>
        
        <input type="hidden" id="booking-vehicle-id" value="${vehicle.VehicleID}">
    `;
    
    modal.classList.add('active');
}

function closeBookingModal() {
    document.getElementById('booking-modal').classList.remove('active');
    selectedVehicle = null;
}

function confirmBooking() {
    if (!CUSTOMER_ID) {
        OSRH.alert('Please register for car-sharing first');
        return;
    }
    
    const vehicleId = document.getElementById('booking-vehicle-id').value;
    const pricingMode = document.getElementById('booking-pricing-mode').value;
    
    showLoading('Creating booking...');
    
    fetch('api/carshare_book.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            customer_id: CUSTOMER_ID,
            vehicle_id: vehicleId,
            pricing_mode: pricingMode,
            csrf_token: '<?php echo csrf_token(); ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            OSRH.alert('Vehicle booked! You have 20 minutes to unlock it.').then(function() {
                window.location.reload();
            });
        } else {
            OSRH.alert('Booking failed: ' + data.error);
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        OSRH.alert('Failed to create booking');
    });
}

function unlockVehicle(bookingId) {
    OSRH.confirm('Start the rental? The meter will begin running.', {
        title: 'Start Rental',
        confirmText: 'Start',
        cancelText: 'Cancel',
        type: 'warning',
        icon: '🚗'
    }).then(function(confirmed) {
        if (!confirmed) {
            return;
        }
        showLoading('Unlocking vehicle...');
        fetch('api/carshare_start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                booking_id: bookingId,
                customer_id: CUSTOMER_ID,
                csrf_token: '<?php echo csrf_token(); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                OSRH.alert('Vehicle unlocked! Enjoy your trip!').then(function() {
                    window.location.reload();
                });
            } else {
                OSRH.alert('Failed to unlock: ' + data.error);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            OSRH.alert('Failed to unlock vehicle');
        });
    });
    return;
    
    // ...existing code...
}

function openEndRentalModal(rentalId) {
    if (!CUSTOMER_ID) {
        OSRH.alert('Please register for car-sharing first');
        return;
    }

    if (!rentalId) {
        OSRH.alert('No active rental found.');
        return;
    }

    endingRentalId = rentalId;
    document.getElementById('end-rental-modal').classList.add('active');
    updateEndLocationSummary();

    if (!endRentalMapInitialized) {
        initEndRentalMap();
        endRentalMapInitialized = true;
    } else if (endRentalMap) {
        setTimeout(() => endRentalMap.invalidateSize(), 150);
    }
}

function closeEndRentalModal() {
    document.getElementById('end-rental-modal').classList.remove('active');
    endingRentalId = null;
}

function initEndRentalMap() {
    const defaultView = ACTIVE_RENTAL_START && ACTIVE_RENTAL_START.startLat && ACTIVE_RENTAL_START.startLon
        ? [ACTIVE_RENTAL_START.startLat, ACTIVE_RENTAL_START.startLon]
        : CYPRUS_CENTER;
    const defaultZoom = ACTIVE_RENTAL_START && ACTIVE_RENTAL_START.startLat ? 13 : 10;

    endRentalMap = L.map('end-rental-map').setView(defaultView, defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(endRentalMap);

    drawZonesOnMap(endRentalMap);
    drawGeofencesOnMap(endRentalMap);

    endRentalMap.on('click', event => {
        setEndLocation(event.latlng.lat, event.latlng.lng, 'map');
    });

    ensureGeofenceData().then(() => updateEndLocationSummary());
}

function setEndLocation(lat, lon) {
    if (!endRentalMap) {
        return;
    }

    selectedEndLocation = {
        lat: Number(lat),
        lon: Number(lon)
    };

    document.getElementById('end-lat').value = selectedEndLocation.lat;
    document.getElementById('end-lon').value = selectedEndLocation.lon;

    if (!endRentalMarker) {
        endRentalMarker = L.marker([selectedEndLocation.lat, selectedEndLocation.lon], {
            draggable: false
        }).addTo(endRentalMap);
    } else {
        endRentalMarker.setLatLng([selectedEndLocation.lat, selectedEndLocation.lon]);
    }

    endRentalMap.panTo([selectedEndLocation.lat, selectedEndLocation.lon]);
    updateEndLocationSummary();
}

function captureEndLocationFromGPS() {
    if (!navigator.geolocation) {
        OSRH.alert('Geolocation is not supported by your browser');
        return;
    }

    showLoading('Fetching your GPS location...');

    navigator.geolocation.getCurrentPosition(
        position => {
            hideLoading();
            setEndLocation(position.coords.latitude, position.coords.longitude);
        },
        error => {
            hideLoading();
            OSRH.alert('Unable to get your location: ' + error.message);
        },
        { enableHighAccuracy: true, timeout: 12000 }
    );
}

function clearEndLocation() {
    selectedEndLocation = { lat: null, lon: null };
    document.getElementById('end-lat').value = '';
    document.getElementById('end-lon').value = '';

    if (endRentalMarker && endRentalMap) {
        endRentalMap.removeLayer(endRentalMarker);
        endRentalMarker = null;
    }

    updateEndLocationSummary();
}

function updateEndLocationSummary() {
    const summaryEl = document.getElementById('end-location-summary');
    if (!summaryEl) {
        return;
    }

    if (selectedEndLocation.lat === null || selectedEndLocation.lon === null) {
        summaryEl.textContent = 'Tap on the map or use GPS to drop a pin where you parked.';
        return;
    }

    const zone = findZoneForPoint(selectedEndLocation.lat, selectedEndLocation.lon);
    const geofenceName = findGeofenceName(selectedEndLocation.lat, selectedEndLocation.lon);

    let html = '';
    html += `<div><strong>Latitude:</strong> ${selectedEndLocation.lat.toFixed(5)}</div>`;
    html += `<div><strong>Longitude:</strong> ${selectedEndLocation.lon.toFixed(5)}</div>`;
    html += `<div><strong>Drop-off zone:</strong> ${zone ? zone.ZoneName + ' (' + zone.City + ')' : 'Outside designated zone (adds €25)'}</div>`;

    if (ACTIVE_RENTAL_START && ACTIVE_RENTAL_START.startZoneName) {
        html += `<div><strong>Start zone:</strong> ${ACTIVE_RENTAL_START.startZoneName}</div>`;
    }

    html += `<div><strong>Geofence:</strong> ${geofenceName || 'No geofence match'}</div>`;

    if (startGeofenceName && geofenceName && startGeofenceName !== geofenceName) {
        html += '<div class="end-location-warning">Different geofence detected (+€100 surcharge)</div>';
    }

    summaryEl.innerHTML = html;
}

function findZoneForPoint(lat, lon) {
    if (!Array.isArray(CARSHARE_ZONES)) {
        return null;
    }

    for (const zone of CARSHARE_ZONES) {
        if (!zone.CenterLatitude || !zone.CenterLongitude || !zone.RadiusMeters) {
            continue;
        }

        const distance = haversineDistanceMeters(lat, lon, zone.CenterLatitude, zone.CenterLongitude);
        if (distance <= zone.RadiusMeters) {
            return zone;
        }
    }

    return null;
}

function findGeofenceName(lat, lon, dataOverride = null) {
    const data = dataOverride || cachedGeofenceData;
    if (!data || !data.geofences) {
        return null;
    }

    for (const geofence of data.geofences) {
        if (!geofence.points || geofence.points.length < 3) {
            continue;
        }

        if (isPointInsidePolygon(lat, lon, geofence.points)) {
            return geofence.name.replace('_Region', '').replace(/_/g, ' ');
        }
    }

    return null;
}

function isPointInsidePolygon(lat, lon, points) {
    let inside = false;
    for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
        const xi = points[i].lat;
        const yi = points[i].lng;
        const xj = points[j].lat;
        const yj = points[j].lng;

        const intersects = ((yi > lon) !== (yj > lon)) &&
            (lat < (xj - xi) * (lon - yi) / ((yj - yi) || 1e-12) + xi);
        if (intersects) {
            inside = !inside;
        }
    }
    return inside;
}

function haversineDistanceMeters(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function submitEndRental() {
    if (!endingRentalId) {
        OSRH.alert('Missing rental reference. Refresh the page and try again.');
        return;
    }

    if (selectedEndLocation.lat === null || selectedEndLocation.lon === null) {
        OSRH.alert('Please drop a pin or use GPS to share your parking location.');
        return;
    }

    const payload = {
        rental_id: endingRentalId,
        customer_id: CUSTOMER_ID,
        csrf_token: '<?php echo csrf_token(); ?>',
        end_latitude: selectedEndLocation.lat,
        end_longitude: selectedEndLocation.lon
    };

    showLoading('Ending rental...');

    fetch('api/carshare_end.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(response => response.text())
        .then(text => {
            hideLoading();
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (err) {
                console.error('Invalid JSON response:', text);
                OSRH.alert('Failed to end rental: unexpected server response');
                return;
            }

            if (data.success) {
                closeEndRentalModal();
                const total = typeof data.total_cost !== 'undefined'
                    ? parseFloat(data.total_cost).toFixed(2)
                    : '0.00';

                let message = 'Rental completed! Total charge: €' + total;
                const geofenceFee = Number(data.geofence_crossing_fee || 0);
                if (geofenceFee > 0) {
                    message += '\nIncludes geofence crossing fee of €' + geofenceFee.toFixed(2);
                }
                const outOfZoneFee = Number(data.out_of_zone_fee || 0);
                if (outOfZoneFee > 0) {
                    message += '\nIncludes out-of-zone parking fee.';
                }

                OSRH.alert(message).then(function() {
                    // Redirect to rental detail page
                    window.location.href = '<?php echo url('carshare/request_vehicle.php'); ?>';
                });
            } else {
                OSRH.alert('Failed to end rental: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            OSRH.alert('Failed to end rental');
        });
}

function showLoading(text) {
    document.getElementById('loading-text').textContent = text || 'Loading...';
    document.getElementById('loading-overlay').classList.add('active');
}

function hideLoading() {
    document.getElementById('loading-overlay').classList.remove('active');
}


</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
