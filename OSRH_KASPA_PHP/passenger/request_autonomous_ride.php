<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/payments.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId = (int)$passengerRow['PassengerID'];

$errors = [];
$data = [
    'pickup_description'    => '',
    'pickup_address'        => '',
    'pickup_postal'         => '',
    'pickup_lat'            => '',
    'pickup_lon'            => '',
    'dropoff_description'   => '',
    'dropoff_address'       => '',
    'dropoff_postal'        => '',
    'dropoff_lat'           => '',
    'dropoff_lon'           => '',
    'notes'                 => '',
    'wheelchair_needed'     => false,
    'payment_method_type_id' => '2',  // Default to Cash
];

// Check if passenger already has an active driver trip
$activeDriverTrip = null;
$stmt = @db_call_procedure('dbo.spGetPassengerActiveTrip', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeDriverTrip = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmt);
}

if ($activeDriverTrip && !empty($activeDriverTrip['TripID'])) {
    flash_add('info', 'You already have an active driver trip in progress. Please complete it before requesting a new ride.');
    redirect('passenger/ride_detail.php?trip_id=' . $activeDriverTrip['TripID']);
}

// Check if passenger already has an active autonomous ride
$activeRide = null;
$stmt = db_call_procedure('dbo.spGetPassengerActiveAutonomousRide', [$passengerId]);
if ($stmt !== false) {
    $activeRide = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

if ($activeRide) {
    flash_add('info', 'You already have an active autonomous ride in progress.');
    redirect('passenger/autonomous_ride_detail.php?ride_id=' . $activeRide['AutonomousRideID']);
}

// Check for active carshare booking
$activeCarshareBooking = null;
$stmtCustomer = @db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if ($stmtCustomer) {
    $carshareCustomer = @sqlsrv_fetch_array($stmtCustomer, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmtCustomer);
    if ($carshareCustomer && isset($carshareCustomer['CustomerID'])) {
        $stmtBooking = @db_query('EXEC dbo.CarshareCheckExistingBooking ?', [(int)$carshareCustomer['CustomerID']]);
        if ($stmtBooking) {
            $activeCarshareBooking = @sqlsrv_fetch_array($stmtBooking, SQLSRV_FETCH_ASSOC);
            @sqlsrv_free_stmt($stmtBooking);
        }
    }
}

if ($activeCarshareBooking && !empty($activeCarshareBooking['BookingID'])) {
    flash_add('info', 'You already have an active car-share booking. Please complete it before requesting a new ride.');
    redirect('carshare/request_vehicle.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['pickup_description']      = trim((string)array_get($_POST, 'pickup_description', ''));
    $data['pickup_address']          = trim((string)array_get($_POST, 'pickup_address', ''));
    $data['pickup_postal']           = trim((string)array_get($_POST, 'pickup_postal', ''));
    $data['pickup_lat']              = trim((string)array_get($_POST, 'pickup_lat', ''));
    $data['pickup_lon']              = trim((string)array_get($_POST, 'pickup_lon', ''));
    $data['dropoff_description']     = trim((string)array_get($_POST, 'dropoff_description', ''));
    $data['dropoff_address']         = trim((string)array_get($_POST, 'dropoff_address', ''));
    $data['dropoff_postal']          = trim((string)array_get($_POST, 'dropoff_postal', ''));
    $data['dropoff_lat']             = trim((string)array_get($_POST, 'dropoff_lat', ''));
    $data['dropoff_lon']             = trim((string)array_get($_POST, 'dropoff_lon', ''));
    $data['notes']                   = trim((string)array_get($_POST, 'notes', ''));
    $data['wheelchair_needed']       = array_get($_POST, 'wheelchair_needed', '') === '1';
    $data['payment_method_type_id']  = trim((string)array_get($_POST, 'payment_method_type_id', '1'));
    $data['estimated_pickup_distance_km']   = trim((string)array_get($_POST, 'estimated_pickup_distance_km', ''));
    $data['estimated_pickup_duration_sec']  = trim((string)array_get($_POST, 'estimated_pickup_duration_sec', ''));
    $data['estimated_trip_distance_km']     = trim((string)array_get($_POST, 'estimated_trip_distance_km', ''));
    $data['estimated_trip_duration_sec']    = trim((string)array_get($_POST, 'estimated_trip_duration_sec', ''));
    $data['estimated_fare']          = trim((string)array_get($_POST, 'estimated_fare', ''));
    $data['pickup_route_geometry']   = trim((string)array_get($_POST, 'pickup_route_geometry', ''));
    $data['trip_route_geometry']     = trim((string)array_get($_POST, 'trip_route_geometry', ''));
    $token                           = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($data['payment_method_type_id'] === '' || !is_numeric($data['payment_method_type_id'])) {
            $errors['payment_method'] = 'Please select a payment method.';
        }

        if ($data['pickup_lat'] === '' || $data['pickup_lon'] === '') {
            $errors['pickup_location'] = 'Please select a pickup location on the map.';
        } elseif (!is_numeric($data['pickup_lat']) || !is_numeric($data['pickup_lon'])) {
            $errors['pickup_location'] = 'Invalid pickup coordinates.';
        }

        if ($data['dropoff_lat'] === '' || $data['dropoff_lon'] === '') {
            $errors['dropoff_location'] = 'Please select a dropoff location on the map.';
        } elseif (!is_numeric($data['dropoff_lat']) || !is_numeric($data['dropoff_lon'])) {
            $errors['dropoff_location'] = 'Invalid dropoff coordinates.';
        }
    }

    if (!$errors) {
        $pickupLat   = (float)$data['pickup_lat'];
        $pickupLon   = (float)$data['pickup_lon'];
        $dropoffLat  = (float)$data['dropoff_lat'];
        $dropoffLon  = (float)$data['dropoff_lon'];
        $wheelchair  = $data['wheelchair_needed'] ? 1 : 0;

        // 1) Insert pickup location
        $stmtPickup = db_call_procedure('dbo.spInsertLocation', [
            $data['pickup_description'] !== '' ? $data['pickup_description'] : null,
            $data['pickup_address']     !== '' ? $data['pickup_address']     : null,
            $data['pickup_postal']      !== '' ? $data['pickup_postal']      : null,
            $pickupLat,
            $pickupLon,
        ]);

        if ($stmtPickup === false) {
            $errors['general'] = 'Failed to save pickup location. Please try again.';
        } else {
            $pickupRow = sqlsrv_fetch_array($stmtPickup, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtPickup);
            $pickupLocationId = $pickupRow['LocationID'] ?? null;
            if (!$pickupLocationId) {
                $errors['general'] = 'Failed to get pickup location ID.';
            }
        }

        // 2) Insert dropoff location
        if (!$errors) {
            $stmtDropoff = db_call_procedure('dbo.spInsertLocation', [
                $data['dropoff_description'] !== '' ? $data['dropoff_description'] : null,
                $data['dropoff_address']     !== '' ? $data['dropoff_address']     : null,
                $data['dropoff_postal']      !== '' ? $data['dropoff_postal']      : null,
                $dropoffLat,
                $dropoffLon,
            ]);

            if ($stmtDropoff === false) {
                $errors['general'] = 'Failed to save dropoff location. Please try again.';
            } else {
                $dropoffRow = sqlsrv_fetch_array($stmtDropoff, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmtDropoff);
                $dropoffLocationId = $dropoffRow['LocationID'] ?? null;
                if (!$dropoffLocationId) {
                    $errors['general'] = 'Failed to get dropoff location ID.';
                }
            }
        }

        // 3) Create autonomous ride - vehicle is assigned automatically!
        if (!$errors) {
            $paymentMethodTypeId = (int)$data['payment_method_type_id'];
            
            // Validate that trip route geometry exists (required - proves route stays in zone)
            $tripRouteGeometry = $data['trip_route_geometry'] !== '' ? $data['trip_route_geometry'] : null;
            if (!$tripRouteGeometry) {
                $errors['general'] = 'No valid route found for this trip. The route may go outside the autonomous vehicle zone. Please try selecting locations closer together.';
            }
        }
        
        if (!$errors) {
            $paymentMethodTypeId = (int)$data['payment_method_type_id'];
            
            $estimatedPickupDistanceKm = ($data['estimated_pickup_distance_km'] !== '' && is_numeric($data['estimated_pickup_distance_km'])) 
                ? (float)$data['estimated_pickup_distance_km'] 
                : null;
            $estimatedPickupDurationSec = ($data['estimated_pickup_duration_sec'] !== '' && is_numeric($data['estimated_pickup_duration_sec'])) 
                ? (int)$data['estimated_pickup_duration_sec'] 
                : null;
            $estimatedTripDistanceKm = ($data['estimated_trip_distance_km'] !== '' && is_numeric($data['estimated_trip_distance_km'])) 
                ? (float)$data['estimated_trip_distance_km'] 
                : null;
            $estimatedTripDurationSec = ($data['estimated_trip_duration_sec'] !== '' && is_numeric($data['estimated_trip_duration_sec'])) 
                ? (int)$data['estimated_trip_duration_sec'] 
                : null;
            $estimatedFare = ($data['estimated_fare'] !== '' && is_numeric($data['estimated_fare'])) 
                ? (float)$data['estimated_fare'] 
                : null;
            
            $pickupRouteGeometry = $data['pickup_route_geometry'] !== '' ? $data['pickup_route_geometry'] : null;
            $tripRouteGeometry = $data['trip_route_geometry'] !== '' ? $data['trip_route_geometry'] : null;
            
            $stmtRide = db_call_procedure('dbo.spCreateAutonomousRide', [
                $passengerId,
                (int)$pickupLocationId,
                (int)$dropoffLocationId,
                $data['notes'] !== '' ? $data['notes'] : null,
                $wheelchair,
                $paymentMethodTypeId,
                $estimatedPickupDistanceKm,
                $estimatedPickupDurationSec,
                $estimatedTripDistanceKm,
                $estimatedTripDurationSec,
                $estimatedFare,
                $pickupRouteGeometry,
                $tripRouteGeometry,
                null  // Let system auto-select vehicle
            ]);

            if ($stmtRide === false) {
                $sqlErrors = sqlsrv_errors();
                error_log('spCreateAutonomousRide failed: ' . print_r($sqlErrors, true));
                
                // Check for specific error messages
                $errorMessage = 'Failed to create autonomous ride request. Please try again.';
                if ($sqlErrors) {
                    foreach ($sqlErrors as $err) {
                        if (isset($err['message'])) {
                            if (stripos($err['message'], 'No autonomous vehicles available') !== false) {
                                $errorMessage = 'No autonomous vehicles are currently available. Please try again later.';
                            } elseif (stripos($err['message'], 'not available') !== false) {
                                $errorMessage = 'The selected vehicle is not available. Please try again.';
                            }
                        }
                    }
                }
                $errors['general'] = $errorMessage;
            } else {
                $rideRow = sqlsrv_fetch_array($stmtRide, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmtRide);
                $rideId = $rideRow['AutonomousRideID'] ?? null;

                if (!$rideId) {
                    $errors['general'] = 'Ride was created but could not be retrieved.';
                } else {
                    // Success! Vehicle is already dispatched - redirect to ride detail
                    $vehicleInfo = '';
                    if (!empty($rideRow['VehicleCode'])) {
                        $vehicleInfo = ' (' . $rideRow['Make'] . ' ' . $rideRow['Model'] . ' - ' . $rideRow['VehicleCode'] . ')';
                    }
                    
                    flash_add('success', 'Your autonomous vehicle has been dispatched!' . $vehicleInfo . ' Track it in real-time below.');
                    redirect('passenger/autonomous_ride_detail.php?ride_id=' . $rideId);
                }
            }
        }
    }
}

$pageTitle = 'Request Autonomous Ride';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 980px; margin: 2rem auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                <span style="margin-right: 0.4rem;">🤖</span> Request Autonomous Ride
            </h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                Book a self-driving vehicle (Waymo-style). No driver needed - the vehicle will pick you up automatically!
            </p>
        </div>
    </div>

    <!-- Autonomous Vehicle Info Banner -->
    <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; padding: 1rem 1.2rem; border-radius: 10px; margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span style="font-size: 2rem;">🚗</span>
            <div>
                <div style="font-weight: 600; font-size: 1rem;">How Autonomous Rides Work</div>
                <div style="font-size: 0.85rem; opacity: 0.9; margin-top: 0.3rem;">
                    1. Select pickup & dropoff locations<br>
                    2. Confirm your ride - a vehicle is dispatched instantly<br>
                    3. Track the vehicle in real-time as it arrives<br>
                    4. Get in and enjoy your ride!
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <form method="post" class="js-validate" novalidate>
        <?php csrf_field(); ?>

        <div style="display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 1.2rem;">
            <div>
                <div class="map-toolbar">
                    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <div>
                            <label style="font-size: 0.82rem; margin-right: 0.6rem;">
                                <input type="radio" name="point_mode" value="pickup" checked>
                                Pickup
                            </label>
                            <label style="font-size: 0.82rem;">
                                <input type="radio" name="point_mode" value="dropoff">
                                Dropoff
                            </label>
                        </div>
                        <button type="button" id="use-my-location-btn" class="btn btn-sm" style="background: #6366f1; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px; display: flex; align-items: center; gap: 0.3rem;">
                            <span id="gps-icon">📍</span> Use My Location
                        </button>
                        <button type="button" id="toggle-geofences-btn" class="btn btn-sm" style="background: #2563eb; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px;">
                            🗺️ Hide Zones
                        </button>
                    </div>
                    <div style="font-size: 0.8rem;">
                        Click on the map to set coordinates. <strong>Pickup & dropoff must be within the same zone.</strong>
                    </div>
                </div>

                <div id="request-map" class="map-container"></div>

                <?php if (!empty($errors['pickup_location'])): ?>
                    <div class="form-error" style="margin-top: 0.45rem;"><?php echo e($errors['pickup_location']); ?></div>
                <?php endif; ?>
                <?php if (!empty($errors['dropoff_location'])): ?>
                    <div class="form-error" style="margin-top: 0.2rem;"><?php echo e($errors['dropoff_location']); ?></div>
                <?php endif; ?>

                <!-- Geofence Warning -->
                <div id="geofence-warning" style="display: none; margin-top: 0.5rem; padding: 0.75rem; background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; border-radius: 8px; color: #dc2626; font-size: 0.85rem;">
                    <strong>⚠️ Cross-Zone Trip Not Allowed</strong><br>
                    <span id="geofence-warning-text">Autonomous vehicles can only operate within their designated zone. Please select pickup and dropoff within the same zone.</span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-top: 0.8rem; font-size: 0.8rem;">
                    <div>
                        <div class="form-label">Pickup coordinates</div>
                        <input
                            type="text"
                            id="pickup_lat"
                            name="pickup_lat"
                            class="form-control"
                            value="<?php echo e($data['pickup_lat']); ?>"
                            placeholder="Latitude"
                            data-required="1"
                        >
                        <input
                            type="text"
                            id="pickup_lon"
                            name="pickup_lon"
                            class="form-control"
                            value="<?php echo e($data['pickup_lon']); ?>"
                            placeholder="Longitude"
                            style="margin-top: 0.3rem;"
                            data-required="1"
                        >
                    </div>
                    <div>
                        <div class="form-label">Dropoff coordinates</div>
                        <input
                            type="text"
                            id="dropoff_lat"
                            name="dropoff_lat"
                            class="form-control"
                            value="<?php echo e($data['dropoff_lat']); ?>"
                            placeholder="Latitude"
                            data-required="1"
                        >
                        <input
                            type="text"
                            id="dropoff_lon"
                            name="dropoff_lon"
                            class="form-control"
                            value="<?php echo e($data['dropoff_lon']); ?>"
                            placeholder="Longitude"
                            style="margin-top: 0.3rem;"
                            data-required="1"
                        >
                        <!-- Hidden inputs for route data -->
                        <input type="hidden" id="estimated_pickup_distance_km" name="estimated_pickup_distance_km" value="">
                        <input type="hidden" id="estimated_pickup_duration_sec" name="estimated_pickup_duration_sec" value="">
                        <input type="hidden" id="estimated_trip_distance_km" name="estimated_trip_distance_km" value="">
                        <input type="hidden" id="estimated_trip_duration_sec" name="estimated_trip_duration_sec" value="">
                        <input type="hidden" id="estimated_fare" name="estimated_fare" value="">
                        <input type="hidden" id="pickup_route_geometry" name="pickup_route_geometry" value="">
                        <input type="hidden" id="trip_route_geometry" name="trip_route_geometry" value="">
                        <input type="hidden" id="nearest_vehicle_id" name="nearest_vehicle_id" value="">
                        <input type="hidden" id="pickup_geofence_id" name="pickup_geofence_id" value="">
                        <input type="hidden" id="dropoff_geofence_id" name="dropoff_geofence_id" value="">
                    </div>
                </div>

                <!-- Nearest Vehicle Info -->
                <div id="nearest-vehicle-info" style="display: none; margin-top: 1rem; padding: 0.8rem; background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; border-radius: 8px;">
                    <div style="font-weight: 600; color: #10b981; margin-bottom: 0.4rem;">
                        <span style="margin-right: 0.3rem;">🚗</span> Nearest Autonomous Vehicle
                    </div>
                    <div id="nearest-vehicle-details" style="font-size: 0.85rem;"></div>
                </div>
            </div>

            <div>
                <h3 style="font-size: 0.95rem; margin-bottom: 0.6rem;">Ride Details</h3>

                <div class="form-group">
                    <label class="form-label" for="pickup_description">Pickup description (optional)</label>
                    <input
                        type="text"
                        id="pickup_description"
                        name="pickup_description"
                        class="form-control"
                        value="<?php echo e($data['pickup_description']); ?>"
                        placeholder="e.g. Front of shopping mall"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="pickup_address">Pickup address (optional)</label>
                    <input
                        type="text"
                        id="pickup_address"
                        name="pickup_address"
                        class="form-control"
                        value="<?php echo e($data['pickup_address']); ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="dropoff_description">Dropoff description (optional)</label>
                    <input
                        type="text"
                        id="dropoff_description"
                        name="dropoff_description"
                        class="form-control"
                        value="<?php echo e($data['dropoff_description']); ?>"
                        placeholder="e.g. Home, office, etc."
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="dropoff_address">Dropoff address (optional)</label>
                    <input
                        type="text"
                        id="dropoff_address"
                        name="dropoff_address"
                        class="form-control"
                        value="<?php echo e($data['dropoff_address']); ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="payment_method_type_id">Payment Method <span style="color: #c53030;">*</span></label>
                    <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input
                                type="radio"
                                name="payment_method_type_id"
                                value="2"
                                <?php echo $data['payment_method_type_id'] === '2' ? 'checked' : ''; ?>
                                data-required="1"
                            >
                            <span>💵 Cash</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input
                                type="radio"
                                name="payment_method_type_id"
                                value="3"
                                <?php echo $data['payment_method_type_id'] === '3' ? 'checked' : ''; ?>
                                data-required="1"
                            >
                            <span style="color: #49EACB;">Kaspa (KAS)</span>
                        </label>
                    </div>
                    <?php if (!empty($errors['payment_method'])): ?>
                        <div class="form-error"><?php echo e($errors['payment_method']); ?></div>
                    <?php endif; ?>
                    <div id="kaspa-av-info" style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: rgba(73, 234, 203, 0.1); border-radius: 6px; font-size: 0.78rem; color: #49EACB;">
                        Pay with Kaspa — <strong>0% platform fees</strong>, instant cryptocurrency payment!
                    </div>
                </div>

                <!-- Fare Estimate Section -->
                <div class="form-group" id="fare-estimate-section" style="margin-top: 1rem; padding: 1rem; background: var(--color-surface); border-radius: 8px; border: 1px solid var(--color-border-subtle);">
                    <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0; color: var(--color-text);">
                        <span style="margin-right: 0.4rem;">💰</span> Estimated Fare
                    </h4>
                    <div id="fare-estimate-content">
                        <p class="text-muted" style="font-size: 0.82rem; margin: 0;">
                            Select pickup and dropoff locations to see fare estimate.
                        </p>
                    </div>
                </div>

                <!-- ETA Section -->
                <div class="form-group" id="eta-section" style="display: none; margin-top: 0.75rem; padding: 0.8rem; background: rgba(99, 102, 241, 0.1); border: 1px solid #6366f1; border-radius: 8px;">
                    <div style="font-weight: 600; color: #6366f1; margin-bottom: 0.3rem;">
                        <span style="margin-right: 0.3rem;">⏱️</span> Estimated Time
                    </div>
                    <div id="eta-content" style="font-size: 0.85rem;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Notes (optional)</label>
                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        rows="2"
                        placeholder="Any special instructions for your ride?"
                    ><?php echo e($data['notes']); ?></textarea>
                </div>

                <div class="form-group" style="margin-top: 1.2rem;">
                    <button type="submit" id="submit-btn" class="btn btn-primary" style="width: 100%; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none;">
                        🚗 Request Autonomous Vehicle
                    </button>
                    <p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem; text-align: center;">
                        Vehicle will be dispatched immediately upon confirmation
                    </p>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.OSRH || typeof window.OSRH.initMap !== 'function') {
        console.error('OSRH map library not loaded');
        return;
    }

    var map = window.OSRH.initMap('request-map', { lat: 35.1667, lng: 33.3667, zoom: 11 });
    if (!map) {
        return;
    }

    var pickupMarker = null;
    var dropoffMarker = null;
    var pickupRouteLine = null;
    var tripRouteLine = null;
    var userLocationMarker = null;
    var avMarkers = [];  // Autonomous vehicle markers
    var nearestVehicle = null;
    
    // Geofence variables
    var geofenceLayers = [];      // Store geofence polygon layers
    var geofencesVisible = true;  // Toggle state
    var geofenceData = [];        // Store geofence data for point-in-polygon checks
    var pickupGeofenceId = null;
    var dropoffGeofenceId = null;

    // Color palette for geofences (distinct colors)
    var geofenceColors = [
        { fill: '#3b82f6', border: '#1d4ed8', name: 'blue' },      // Nicosia
        { fill: '#ef4444', border: '#b91c1c', name: 'red' },       // Limassol
        { fill: '#22c55e', border: '#15803d', name: 'green' },     // Larnaca
        { fill: '#f59e0b', border: '#d97706', name: 'orange' },    // Paphos
        { fill: '#8b5cf6', border: '#6d28d9', name: 'purple' },    // Famagusta
        { fill: '#ec4899', border: '#be185d', name: 'pink' }       // Kyrenia
    ];

    // Load and display geofences
    function loadGeofences() {
        fetch('../api/get_geofences.php')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    console.warn('Failed to load geofences:', data.error);
                    return;
                }

                console.log('Loaded', data.count.geofences, 'geofences');
                geofenceData = data.geofences; // Store for point-in-polygon checks

                // Draw geofence polygons
                data.geofences.forEach(function(geofence, index) {
                    if (geofence.points.length < 3) return;

                    var color = geofenceColors[index % geofenceColors.length];
                    var latlngs = geofence.points.map(function(p) {
                        return [p.lat, p.lng];
                    });
                    latlngs.push(latlngs[0]); // Close the polygon

                    var polygon = L.polygon(latlngs, {
                        color: color.border,
                        fillColor: color.fill,
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '5, 5',
                        interactive: false
                    }).addTo(map);

                    // Add label in center
                    var bounds = polygon.getBounds();
                    var center = bounds.getCenter();
                    
                    var label = L.marker(center, {
                        icon: L.divIcon({
                            className: 'geofence-label',
                            html: '<div style="background: ' + color.fill + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.3); pointer-events: none;">' + 
                                  geofence.name.replace('_Region', '').replace('_', ' ') + '</div>',
                            iconSize: null,
                            iconAnchor: [40, 10]
                        }),
                        interactive: false
                    }).addTo(map);

                    geofenceLayers.push(polygon);
                    geofenceLayers.push(label);
                });
            })
            .catch(function(err) {
                console.warn('Error loading geofences:', err);
            });
    }

    // Toggle geofence visibility
    window.toggleGeofences = function() {
        geofencesVisible = !geofencesVisible;
        geofenceLayers.forEach(function(layer) {
            if (geofencesVisible) {
                map.addLayer(layer);
            } else {
                map.removeLayer(layer);
            }
        });
        
        var btn = document.getElementById('toggle-geofences-btn');
        if (btn) {
            btn.textContent = geofencesVisible ? '🗺️ Hide Zones' : '🗺️ Show Zones';
            btn.style.background = geofencesVisible ? '#2563eb' : '#6b7280';
        }
    };

    // Point-in-polygon check using ray casting algorithm
    function pointInPolygon(lat, lng, polygon) {
        var x = lng, y = lat;
        var inside = false;
        
        for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            var xi = polygon[i].lng, yi = polygon[i].lat;
            var xj = polygon[j].lng, yj = polygon[j].lat;
            
            var intersect = ((yi > y) != (yj > y)) &&
                (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        
        return inside;
    }

    // Find which geofence a point is in
    function findGeofenceForPoint(lat, lng) {
        for (var i = 0; i < geofenceData.length; i++) {
            var geofence = geofenceData[i];
            if (geofence.points.length >= 3 && pointInPolygon(lat, lng, geofence.points)) {
                return geofence;
            }
        }
        return null;
    }

    // Validate that pickup and dropoff are in the same geofence
    function validateGeofences() {
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLng = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLng = parseFloat(document.getElementById('dropoff_lon').value);
        
        var warningDiv = document.getElementById('geofence-warning');
        var warningText = document.getElementById('geofence-warning-text');
        var submitBtn = document.getElementById('submit-btn');
        
        // Reset if not both points set
        if (isNaN(pickupLat) || isNaN(pickupLng) || isNaN(dropoffLat) || isNaN(dropoffLng)) {
            warningDiv.style.display = 'none';
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            return true;
        }
        
        var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);
        var dropoffGeofence = findGeofenceForPoint(dropoffLat, dropoffLng);
        
        // Store geofence IDs
        pickupGeofenceId = pickupGeofence ? pickupGeofence.id : null;
        dropoffGeofenceId = dropoffGeofence ? dropoffGeofence.id : null;
        document.getElementById('pickup_geofence_id').value = pickupGeofenceId || '';
        document.getElementById('dropoff_geofence_id').value = dropoffGeofenceId || '';
        
        // Check if both points are inside a geofence
        if (!pickupGeofence) {
            warningDiv.style.display = 'block';
            warningText.textContent = 'Pickup location is outside all service zones. Please select a location within a zone.';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            return false;
        }
        
        if (!dropoffGeofence) {
            warningDiv.style.display = 'block';
            warningText.textContent = 'Dropoff location is outside all service zones. Please select a location within a zone.';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            return false;
        }
        
        // Check if both are in the SAME geofence
        if (pickupGeofence.id !== dropoffGeofence.id) {
            warningDiv.style.display = 'block';
            var pickupZone = pickupGeofence.name.replace('_Region', '').replace('_', ' ');
            var dropoffZone = dropoffGeofence.name.replace('_Region', '').replace('_', ' ');
            warningText.innerHTML = 'Autonomous vehicles cannot travel between zones.<br>' +
                '<strong>Pickup:</strong> ' + pickupZone + '<br>' +
                '<strong>Dropoff:</strong> ' + dropoffZone + '<br>' +
                'Please select both locations within the same zone, or use <a href="request_ride.php" style="color: #3b82f6;">regular ride service</a> for cross-zone trips.';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            return false;
        }
        
        // Valid - same geofence (route validation happens in updateRoutes)
        // Don't enable submit yet - wait for route validation
        warningDiv.style.display = 'none';
        return true;
    }
    
    // Variable to track route validation state
    var routeIsValid = false;
    
    // Check if a route stays within a geofence
    // Sample points along the route (every few coordinates) to check
    function validateRouteWithinGeofence(routeCoords, geofence) {
        if (!geofence || !geofence.points || geofence.points.length < 3) {
            return { valid: false, failedPoints: [], message: 'No geofence defined' };
        }
        
        var failedPoints = [];
        var sampleRate = Math.max(1, Math.floor(routeCoords.length / 50)); // Check ~50 points max
        
        for (var i = 0; i < routeCoords.length; i += sampleRate) {
            var coord = routeCoords[i];
            var lat = coord[0]; // Already converted to [lat, lng]
            var lng = coord[1];
            
            if (!pointInPolygon(lat, lng, geofence.points)) {
                failedPoints.push({ lat: lat, lng: lng, index: i });
            }
        }
        
        // Also check the last point
        if (routeCoords.length > 0) {
            var lastCoord = routeCoords[routeCoords.length - 1];
            if (!pointInPolygon(lastCoord[0], lastCoord[1], geofence.points)) {
                failedPoints.push({ lat: lastCoord[0], lng: lastCoord[1], index: routeCoords.length - 1 });
            }
        }
        
        return {
            valid: failedPoints.length === 0,
            failedPoints: failedPoints,
            message: failedPoints.length > 0 
                ? 'Route goes outside the ' + geofence.name.replace('_Region', '').replace('_', ' ') + ' zone'
                : 'Route is valid'
        };
    }
    
    // Show route validation warning
    function showRouteWarning(message, failedPoints, geofence) {
        var warningDiv = document.getElementById('geofence-warning');
        var warningText = document.getElementById('geofence-warning-text');
        var submitBtn = document.getElementById('submit-btn');
        
        warningDiv.style.display = 'block';
        var zoneName = geofence ? geofence.name.replace('_Region', '').replace('_', ' ') : 'the zone';
        warningText.innerHTML = '<strong>⚠️ Route Issue:</strong> ' + message + '<br><br>' +
            'The suggested route leaves the <strong>' + zoneName + '</strong> autonomous vehicle zone. ' +
            'Autonomous vehicles must stay within their designated zones.<br><br>' +
            '<strong>Options:</strong><br>' +
            '• Try selecting pickup/dropoff locations closer together<br>' +
            '• Use <a href="request_ride.php" style="color: #3b82f6;">regular ride service</a> for this trip';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        routeIsValid = false;
    }
    
    // Clear route warning and enable submit
    function clearRouteWarning() {
        var warningDiv = document.getElementById('geofence-warning');
        var submitBtn = document.getElementById('submit-btn');
        
        warningDiv.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        routeIsValid = true;
    }
    
    // Markers for route violation points
    var routeViolationMarkers = [];
    
    function clearRouteViolationMarkers() {
        routeViolationMarkers.forEach(function(m) { map.removeLayer(m); });
        routeViolationMarkers = [];
    }
    
    function showRouteViolationMarkers(failedPoints) {
        clearRouteViolationMarkers();
        
        // Show up to 5 violation points
        var pointsToShow = failedPoints.slice(0, 5);
        pointsToShow.forEach(function(point) {
            var icon = L.divIcon({
                className: 'route-violation-marker',
                html: '<div style="background: #ef4444; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.4); font-size: 12px; color: white; font-weight: bold;">✕</div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });
            var marker = L.marker([point.lat, point.lng], { icon: icon }).addTo(map);
            marker.bindPopup('<div style="color: #ef4444; font-weight: bold;">⚠️ Route exits zone here</div>');
            routeViolationMarkers.push(marker);
        });
    }

    // Load geofences on init
    loadGeofences();

    // Toggle button click handler
    document.getElementById('toggle-geofences-btn').addEventListener('click', function() {
        toggleGeofences();
    });

    // Load and display autonomous vehicles
    function loadAutonomousVehicles() {
        fetch('../api/get_autonomous_vehicles.php')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    console.warn('Failed to load autonomous vehicles:', data.error);
                    return;
                }

                console.log('Loaded', data.vehicles.length, 'autonomous vehicles');

                // Clear existing markers
                avMarkers.forEach(function(m) { map.removeLayer(m); });
                avMarkers = [];

                // Add markers for each vehicle - small car icon, info on click
                data.vehicles.forEach(function(vehicle) {
                    if (!vehicle.CurrentLatitude || !vehicle.CurrentLongitude) return;

                    var statusColor = vehicle.Status === 'available' ? '#10b981' : '#6b7280';
                    
                    // Small car icon marker
                    var avIcon = L.divIcon({
                        className: 'av-marker',
                        html: '<div style="background: ' + statusColor + '; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); font-size: 14px; cursor: pointer;">🚗</div>',
                        iconSize: [28, 28],
                        iconAnchor: [14, 14]
                    });

                    var marker = L.marker([vehicle.CurrentLatitude, vehicle.CurrentLongitude], { 
                        icon: avIcon 
                    }).addTo(map);
                    
                    // Show vehicle info on click
                    var popupContent = '<div style="min-width: 180px;">' +
                        '<strong style="color: #6366f1; font-size: 1.1em;">' + vehicle.VehicleCode + '</strong><br>' +
                        '<span style="color: #333; font-weight: 500;">' + vehicle.Make + ' ' + vehicle.Model + '</span><br>' +
                        '<hr style="margin: 0.4rem 0; border: none; border-top: 1px solid #eee;">' +
                        '<span style="font-size: 0.85em;">🎨 Color: ' + vehicle.Color + '</span><br>' +
                        '<span style="font-size: 0.85em;">🔢 Plate: ' + vehicle.PlateNo + '</span><br>' +
                        '<span style="font-size: 0.85em;">💺 Seats: ' + vehicle.SeatingCapacity + '</span><br>' +
                        '<span style="font-size: 0.85em;">🔋 Battery: ' + (vehicle.BatteryLevel || 'N/A') + '%</span><br>' +
                        '<span style="font-size: 0.85em; color: ' + statusColor + '; font-weight: bold;">● ' + (vehicle.Status === 'available' ? 'Available' : vehicle.Status) + '</span>' +
                        '</div>';
                    marker.bindPopup(popupContent);
                    
                    marker.vehicleData = vehicle;
                    avMarkers.push(marker);
                });
            })
            .catch(function(err) {
                console.warn('Error loading autonomous vehicles:', err);
            });
    }

    // Load vehicles on init and refresh periodically
    loadAutonomousVehicles();
    setInterval(loadAutonomousVehicles, 30000);  // Refresh every 30 seconds

    // GPS Location button
    var gpsBtn = document.getElementById('use-my-location-btn');
    var gpsIcon = document.getElementById('gps-icon');
    
    if (gpsBtn && navigator.geolocation) {
        gpsBtn.addEventListener('click', function() {
            gpsBtn.disabled = true;
            gpsIcon.textContent = '⏳';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    var lat = position.coords.latitude;
                    var lng = position.coords.longitude;

                    document.querySelector('input[name="point_mode"][value="pickup"]').checked = true;
                    setMarker('pickup', { lat: lat, lng: lng });
                    map.setView([lat, lng], 15);

                    gpsBtn.disabled = false;
                    gpsIcon.textContent = '✓';
                    gpsBtn.style.background = '#16a34a';

                    setTimeout(function() {
                        gpsIcon.textContent = '📍';
                        gpsBtn.style.background = '#6366f1';
                        document.querySelector('input[name="point_mode"][value="dropoff"]').checked = true;
                        mode = 'dropoff';  // Important: also update the mode variable
                    }, 1500);
                },
                function(error) {
                    gpsBtn.disabled = false;
                    gpsIcon.textContent = '❌';
                    OSRH.error('Could not get your location. Please select pickup on the map.', 'GPS Error');
                    setTimeout(function() {
                        gpsIcon.textContent = '📍';
                    }, 2000);
                },
                { enableHighAccuracy: true, timeout: 15000 }
            );
        });
    }

    function setMarker(mode, latlng) {
        if (mode === 'pickup') {
            if (!pickupMarker) {
                pickupMarker = window.OSRH.addMarker(map, latlng.lat, latlng.lng, 'pickup', '<strong>📍 Pickup</strong>');
                pickupMarker.dragging.enable();
                pickupMarker.on('dragend', function(e) {
                    updateInputs('pickup', e.target.getLatLng());
                    updateRoutes();
                });
            } else {
                pickupMarker.setLatLng(latlng);
            }
            updateInputs('pickup', latlng);
        } else {
            if (!dropoffMarker) {
                dropoffMarker = window.OSRH.addMarker(map, latlng.lat, latlng.lng, 'dropoff', '<strong>🏁 Dropoff</strong>');
                dropoffMarker.dragging.enable();
                dropoffMarker.on('dragend', function(e) {
                    updateInputs('dropoff', e.target.getLatLng());
                    updateRoutes();
                });
            } else {
                dropoffMarker.setLatLng(latlng);
            }
            updateInputs('dropoff', latlng);
        }
        updateRoutes();
    }

    function updateInputs(prefix, latlng) {
        document.getElementById(prefix + '_lat').value = latlng.lat.toFixed(6);
        document.getElementById(prefix + '_lon').value = latlng.lng.toFixed(6);
    }

    function updateRoutes() {
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLng = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLng = parseFloat(document.getElementById('dropoff_lon').value);

        // Clear existing routes and violation markers
        if (pickupRouteLine) { map.removeLayer(pickupRouteLine); pickupRouteLine = null; }
        if (tripRouteLine) { map.removeLayer(tripRouteLine); tripRouteLine = null; }
        clearRouteViolationMarkers();

        if (isNaN(pickupLat) || isNaN(pickupLng)) return;

        // Validate geofences before proceeding
        var geofenceValid = validateGeofences();
        if (!geofenceValid) return;

        // Find nearest available vehicle in the same geofence
        findNearestVehicle(pickupLat, pickupLng);

        if (isNaN(dropoffLat) || isNaN(dropoffLng)) return;
        
        // Get the geofence for route validation
        var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);
        if (!pickupGeofence) return;

        // Calculate trip route (pickup to dropoff) with multiple alternatives
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' +
            pickupLng + ',' + pickupLat + ';' + dropoffLng + ',' + dropoffLat +
            '?overview=full&geometries=geojson&alternatives=3';

        fetch(osrmUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) return;

                // Try to find a valid route that stays within the geofence
                var validRoute = null;
                var validRouteIndex = -1;
                var firstInvalidResult = null;
                
                for (var i = 0; i < data.routes.length; i++) {
                    var route = data.routes[i];
                    var coords = route.geometry.coordinates.map(function(c) { return [c[1], c[0]]; });
                    var validation = validateRouteWithinGeofence(coords, pickupGeofence);
                    
                    if (validation.valid) {
                        validRoute = route;
                        validRouteIndex = i;
                        break;
                    } else if (!firstInvalidResult) {
                        firstInvalidResult = { route: route, validation: validation, coords: coords };
                    }
                }
                
                if (validRoute) {
                    // Found a valid route - use it
                    var coords = validRoute.geometry.coordinates.map(function(c) { return [c[1], c[0]]; });
                    
                    tripRouteLine = L.polyline(coords, {
                        color: '#22c55e',
                        weight: 5,
                        opacity: 0.8
                    }).addTo(map);

                    // Store trip route data
                    document.getElementById('estimated_trip_distance_km').value = (validRoute.distance / 1000).toFixed(3);
                    document.getElementById('estimated_trip_duration_sec').value = Math.round(validRoute.duration);
                    document.getElementById('trip_route_geometry').value = JSON.stringify(validRoute.geometry);

                    // Clear any warnings and enable submit
                    clearRouteWarning();
                    
                    // Update fare estimate
                    updateFareEstimate();
                    
                    if (validRouteIndex > 0) {
                        console.log('Using alternative route #' + (validRouteIndex + 1) + ' that stays within zone');
                    }
                } else if (firstInvalidResult) {
                    // No valid routes found - show the first route with warnings
                    var route = firstInvalidResult.route;
                    var coords = firstInvalidResult.coords;
                    var validation = firstInvalidResult.validation;
                    
                    tripRouteLine = L.polyline(coords, {
                        color: '#ef4444',  // Red to indicate invalid
                        weight: 5,
                        opacity: 0.6,
                        dashArray: '10, 10'
                    }).addTo(map);

                    // Show violation markers on map
                    showRouteViolationMarkers(validation.failedPoints);
                    
                    // Show warning and disable submit
                    showRouteWarning(validation.message, validation.failedPoints, pickupGeofence);

                    // Store route data anyway (for display purposes)
                    document.getElementById('estimated_trip_distance_km').value = (route.distance / 1000).toFixed(3);
                    document.getElementById('estimated_trip_duration_sec').value = Math.round(route.duration);
                    document.getElementById('trip_route_geometry').value = '';  // Don't store invalid route

                    // Update fare estimate anyway
                    updateFareEstimate();
                }

                // Fit bounds
                if (pickupMarker && dropoffMarker) {
                    var group = L.featureGroup([pickupMarker, dropoffMarker]);
                    map.fitBounds(group.getBounds().pad(0.2));
                }
            })
            .catch(function(err) {
                console.warn('Trip route error:', err);
            });
    }

    function findNearestVehicle(pickupLat, pickupLng) {
        var nearest = null;
        var nearestDist = Infinity;
        
        // Get pickup geofence
        var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);

        avMarkers.forEach(function(marker) {
            if (!marker.vehicleData || marker.vehicleData.Status !== 'available') return;
            
            var vLat = marker.vehicleData.CurrentLatitude;
            var vLng = marker.vehicleData.CurrentLongitude;
            
            // Only consider vehicles in the same geofence as pickup
            if (pickupGeofence) {
                var vehicleGeofence = findGeofenceForPoint(vLat, vLng);
                if (!vehicleGeofence || vehicleGeofence.id !== pickupGeofence.id) {
                    return; // Skip vehicles outside pickup geofence
                }
            }
            
            // Haversine distance
            var R = 6371;
            var dLat = (pickupLat - vLat) * Math.PI / 180;
            var dLng = (pickupLng - vLng) * Math.PI / 180;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(vLat * Math.PI / 180) * Math.cos(pickupLat * Math.PI / 180) *
                    Math.sin(dLng/2) * Math.sin(dLng/2);
            var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            var dist = R * c;

            if (dist < nearestDist) {
                nearestDist = dist;
                nearest = marker.vehicleData;
            }
        });

        if (nearest) {
            nearestVehicle = nearest;
            nearestVehicle.distanceKm = nearestDist;
            
            // Show nearest vehicle info with zone info
            var infoDiv = document.getElementById('nearest-vehicle-info');
            var detailsDiv = document.getElementById('nearest-vehicle-details');
            var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);
            var zoneName = pickupGeofence ? pickupGeofence.name.replace('_Region', '').replace('_', ' ') : 'Unknown';
            
            // Reset styling to success state
            infoDiv.style.display = 'block';
            infoDiv.style.background = 'rgba(16, 185, 129, 0.1)';
            infoDiv.style.borderColor = '#10b981';
            var headerDiv = infoDiv.querySelector('div:first-child');
            headerDiv.style.color = '#10b981';
            headerDiv.innerHTML = '<span style="margin-right: 0.3rem;">🚗</span> Nearest Autonomous Vehicle';
            
            detailsDiv.innerHTML = 
                '<strong>' + nearest.Make + ' ' + nearest.Model + '</strong> (' + nearest.VehicleCode + ')<br>' +
                'Color: ' + nearest.Color + ' | Plate: ' + nearest.PlateNo + '<br>' +
                'Distance: <strong>' + nearestDist.toFixed(1) + ' km</strong> | Battery: ' + nearest.BatteryLevel + '%<br>' +
                '<span style="color: #6366f1;">📍 Zone: ' + zoneName + '</span>';
            
            document.getElementById('nearest_vehicle_id').value = nearest.AutonomousVehicleID;

            // Draw pickup route (vehicle to pickup) - also validate it stays in zone
            var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' +
                nearest.CurrentLongitude + ',' + nearest.CurrentLatitude + ';' +
                pickupLng + ',' + pickupLat +
                '?overview=full&geometries=geojson&alternatives=3';

            fetch(osrmUrl)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) return;

                    // Find a valid pickup route that stays in zone
                    var validPickupRoute = null;
                    var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);
                    
                    for (var i = 0; i < data.routes.length; i++) {
                        var route = data.routes[i];
                        var coords = route.geometry.coordinates.map(function(c) { return [c[1], c[0]]; });
                        
                        if (pickupGeofence) {
                            var validation = validateRouteWithinGeofence(coords, pickupGeofence);
                            if (validation.valid) {
                                validPickupRoute = route;
                                break;
                            }
                        } else {
                            validPickupRoute = route;
                            break;
                        }
                    }
                    
                    // Use first route if no valid one found (pickup route is less critical)
                    if (!validPickupRoute) {
                        validPickupRoute = data.routes[0];
                    }

                    var coords = validPickupRoute.geometry.coordinates.map(function(c) { return [c[1], c[0]]; });
                    
                    if (pickupRouteLine) map.removeLayer(pickupRouteLine);
                    pickupRouteLine = L.polyline(coords, {
                        color: '#6366f1',
                        weight: 4,
                        opacity: 0.7,
                        dashArray: '10, 10'
                    }).addTo(map);

                    // Store pickup route data
                    document.getElementById('estimated_pickup_distance_km').value = (validPickupRoute.distance / 1000).toFixed(3);
                    document.getElementById('estimated_pickup_duration_sec').value = Math.round(validPickupRoute.duration);
                    document.getElementById('pickup_route_geometry').value = JSON.stringify(validPickupRoute.geometry);

                    // Update ETA
                    updateETA(route.duration);
                })
                .catch(function(err) {
                    console.warn('Pickup route error:', err);
                });
        } else {
            // No vehicle found - show helpful message based on zone
            var infoDiv = document.getElementById('nearest-vehicle-info');
            var detailsDiv = document.getElementById('nearest-vehicle-details');
            var pickupGeofence = findGeofenceForPoint(pickupLat, pickupLng);
            
            if (pickupGeofence) {
                var zoneName = pickupGeofence.name.replace('_Region', '').replace('_', ' ');
                infoDiv.style.display = 'block';
                infoDiv.style.background = 'rgba(245, 158, 11, 0.1)';
                infoDiv.style.borderColor = '#f59e0b';
                infoDiv.querySelector('div:first-child').style.color = '#f59e0b';
                infoDiv.querySelector('div:first-child').innerHTML = '<span style="margin-right: 0.3rem;">⚠️</span> No Vehicle Available';
                detailsDiv.innerHTML = 
                    'No autonomous vehicles are currently available in <strong>' + zoneName + '</strong>.<br>' +
                    'Please try again later or select a different zone.';
            } else {
                infoDiv.style.display = 'none';
            }
            document.getElementById('nearest_vehicle_id').value = '';
        }
    }

    function updateETA(pickupDurationSec) {
        var tripDurationSec = parseFloat(document.getElementById('estimated_trip_duration_sec').value) || 0;
        
        var etaSection = document.getElementById('eta-section');
        var etaContent = document.getElementById('eta-content');
        
        if (pickupDurationSec > 0) {
            etaSection.style.display = 'block';
            
            var pickupMins = Math.ceil(pickupDurationSec / 60);
            var tripMins = Math.ceil(tripDurationSec / 60);
            var totalMins = pickupMins + tripMins;
            
            etaContent.innerHTML = 
                '🚗 Vehicle arrives in: <strong>' + pickupMins + ' min</strong><br>' +
                '🏁 Trip duration: <strong>' + tripMins + ' min</strong><br>' +
                '⏰ Total time: <strong>' + totalMins + ' min</strong>';
        } else {
            etaSection.style.display = 'none';
        }
    }

    function updateFareEstimate() {
        var distanceKm = parseFloat(document.getElementById('estimated_trip_distance_km').value) || 0;
        var durationSec = parseFloat(document.getElementById('estimated_trip_duration_sec').value) || 0;
        var durationMin = durationSec / 60;

        var fareContent = document.getElementById('fare-estimate-content');

        if (distanceKm <= 0) {
            fareContent.innerHTML = '<p class="text-muted" style="font-size: 0.82rem; margin: 0;">Select pickup and dropoff locations to see fare estimate.</p>';
            return;
        }

        // Autonomous vehicle pricing (premium service)
        var baseFare = 4.00;
        var pricePerKm = 1.80;
        var pricePerMin = 0.30;
        var minimumFare = 8.00;

        var distanceFare = distanceKm * pricePerKm;
        var timeFare = durationMin * pricePerMin;
        var totalFare = baseFare + distanceFare + timeFare;
        
        if (totalFare < minimumFare) {
            totalFare = minimumFare;
        }

        document.getElementById('estimated_fare').value = totalFare.toFixed(2);

        fareContent.innerHTML = 
            '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem;">' +
            '<div>Base fare:</div><div style="text-align: right;">€' + baseFare.toFixed(2) + '</div>' +
            '<div>Distance (' + distanceKm.toFixed(1) + ' km):</div><div style="text-align: right;">€' + distanceFare.toFixed(2) + '</div>' +
            '<div>Time (' + Math.ceil(durationMin) + ' min):</div><div style="text-align: right;">€' + timeFare.toFixed(2) + '</div>' +
            '</div>' +
            '<div style="border-top: 1px solid var(--color-border-subtle); margin-top: 0.5rem; padding-top: 0.5rem; display: flex; justify-content: space-between; font-weight: 600;">' +
            '<span>Estimated Total:</span><span style="color: #10b981; font-size: 1.1rem;">€' + totalFare.toFixed(2) + '</span>' +
            '</div>' +
            '<div style="font-size: 0.75rem; color: #666; margin-top: 0.4rem;">* Autonomous vehicle premium service</div>';
    }

    // Point mode selection
    var radios = document.querySelectorAll('input[name="point_mode"]');
    var mode = 'pickup';
    radios.forEach(function(r) {
        if (r.checked) mode = r.value;
        r.addEventListener('change', function() {
            if (this.checked) mode = this.value;
        });
    });

    // Map click handler
    map.on('click', function(e) {
        setMarker(mode, e.latlng);
    });

    // Load existing values
    var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
    var pickupLon = parseFloat(document.getElementById('pickup_lon').value);
    if (!isNaN(pickupLat) && !isNaN(pickupLon)) {
        setMarker('pickup', { lat: pickupLat, lng: pickupLon });
    }

    var dropLat = parseFloat(document.getElementById('dropoff_lat').value);
    var dropLon = parseFloat(document.getElementById('dropoff_lon').value);
    if (!isNaN(dropLat) && !isNaN(dropLon)) {
        setMarker('dropoff', { lat: dropLat, lng: dropLon });
    }
    
    // Form submission validation - prevent submission if route is invalid
    var form = document.querySelector('form.js-validate');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Check if route is valid
            if (!routeIsValid) {
                e.preventDefault();
                OSRH.warning('The route goes outside the autonomous vehicle zone.\n\nPlease select locations that can be reached within the zone.', 'Invalid Route');
                return false;
            }
            
            // Check if trip route geometry is set
            var tripRouteGeometry = document.getElementById('trip_route_geometry').value;
            if (!tripRouteGeometry) {
                e.preventDefault();
                OSRH.warning('No valid route found.\n\nPlease wait for the route to be calculated or try different locations.', 'Route Required');
                return false;
            }
            
            return true;
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
