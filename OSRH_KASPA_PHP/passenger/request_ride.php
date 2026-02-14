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

// Check if passenger already has an active ride (driver, autonomous, or carshare)
$activeDriverTrip = null;
$stmt = @db_call_procedure('dbo.spGetPassengerActiveTrip', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeDriverTrip = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmt);
}

$activeAVRide = null;
$stmt = @db_call_procedure('dbo.spGetPassengerActiveAutonomousRide', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeAVRide = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmt);
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

if ($activeDriverTrip && !empty($activeDriverTrip['TripID'])) {
    flash_add('info', 'You already have an active driver trip in progress. Please complete it before requesting a new ride.');
    redirect('passenger/ride_detail.php?trip_id=' . $activeDriverTrip['TripID']);
}

if ($activeAVRide && !empty($activeAVRide['AutonomousRideID'])) {
    flash_add('info', 'You already have an active autonomous ride in progress. Please complete it before requesting a new ride.');
    redirect('passenger/autonomous_ride_detail.php?ride_id=' . $activeAVRide['AutonomousRideID']);
}

if ($activeCarshareBooking && !empty($activeCarshareBooking['BookingID'])) {
    flash_add('info', 'You already have an active car-share booking. Please complete it before requesting a new ride.');
    redirect('carshare/request_vehicle.php');
}

$errors = [];
$data = [
    'service_type_id'       => '1',  // Default to standard service
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
    'luggage_volume'        => '',
    'wheelchair_needed'     => false,
    'use_simulation'        => false,  // Default to real drivers
    'payment_method_type_id' => '2',  // Default to Cash
];

// Fetch available service types
$serviceTypes = [];
$stmtTypes = db_call_procedure('dbo.spGetServiceTypes', []);
if ($stmtTypes) {
    while ($row = sqlsrv_fetch_array($stmtTypes, SQLSRV_FETCH_ASSOC)) {
        $serviceTypes[] = $row;
    }
    sqlsrv_free_stmt($stmtTypes);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['service_type_id']         = trim((string)array_get($_POST, 'service_type_id', '1'));
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
    $data['luggage_volume']          = trim((string)array_get($_POST, 'luggage_volume', ''));
    $data['wheelchair_needed']       = array_get($_POST, 'wheelchair_needed', '') === '1';
    $data['use_simulation']          = array_get($_POST, 'use_simulation', '') === '1';
    $data['payment_method_type_id']  = trim((string)array_get($_POST, 'payment_method_type_id', '1'));
    $data['estimated_distance_km']   = trim((string)array_get($_POST, 'estimated_distance_km', ''));
    $data['estimated_duration_min']  = trim((string)array_get($_POST, 'estimated_duration_min', ''));
    $data['estimated_fare']          = trim((string)array_get($_POST, 'estimated_fare', ''));
    $token                           = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($data['service_type_id'] === '' || !is_numeric($data['service_type_id'])) {
            $errors['service_type'] = 'Please select a service type.';
        }

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

        if ($data['luggage_volume'] !== '' && !is_numeric($data['luggage_volume'])) {
            $errors['luggage_volume'] = 'Luggage volume must be a number (e.g. 2.5).';
        }
    }

    if (!$errors) {
        $pickupLat   = (float)$data['pickup_lat'];
        $pickupLon   = (float)$data['pickup_lon'];
        $dropoffLat  = (float)$data['dropoff_lat'];
        $dropoffLon  = (float)$data['dropoff_lon'];
        $luggageVol  = $data['luggage_volume'] !== '' ? (float)$data['luggage_volume'] : null;
        $wheelchair  = $data['wheelchair_needed'] ? 1 : 0;

        // 1) Insert pickup
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

        // 2) Insert dropoff
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

        // 3) Create ride request
        if (!$errors) {
            $serviceTypeId = (int)$data['service_type_id'];
            $paymentMethodTypeId = (int)$data['payment_method_type_id'];
            $estimatedDistanceKm = ($data['estimated_distance_km'] !== '' && is_numeric($data['estimated_distance_km'])) 
                ? (float)$data['estimated_distance_km'] 
                : null;
            $estimatedDurationMin = ($data['estimated_duration_min'] !== '' && is_numeric($data['estimated_duration_min'])) 
                ? (int)$data['estimated_duration_min'] 
                : null;
            $estimatedFare = ($data['estimated_fare'] !== '' && is_numeric($data['estimated_fare'])) 
                ? (float)$data['estimated_fare'] 
                : null;
            // If use_simulation is checked, real_drivers_only = 0; otherwise real_drivers_only = 1
            $realDriversOnly = $data['use_simulation'] ? 0 : 1;
            
            $stmtRequest = db_call_procedure('dbo.spCreateRideRequest', [
                $passengerId,
                (int)$pickupLocationId,
                (int)$dropoffLocationId,
                $serviceTypeId,
                $data['notes'] !== '' ? $data['notes'] : null,
                $luggageVol,
                $wheelchair,
                $paymentMethodTypeId,
                $estimatedDistanceKm,
                $estimatedDurationMin,
                $estimatedFare,
                $realDriversOnly
            ]);
            
            error_log("DEBUG request_ride: Called spCreateRideRequest with passengerId=$passengerId, realDriversOnly=$realDriversOnly");

            if ($stmtRequest === false) {
                $sqlErrors = sqlsrv_errors();
                error_log('spCreateRideRequest failed: ' . print_r($sqlErrors, true));
                
                // Check if the error is about geofence/service area
                $isGeofenceError = false;
                if ($sqlErrors) {
                    foreach ($sqlErrors as $err) {
                        if (isset($err['message']) && (
                            stripos($err['message'], 'outside our service area') !== false ||
                            stripos($err['message'], 'geofence') !== false ||
                            stripos($err['message'], 'covered districts') !== false
                        )) {
                            $isGeofenceError = true;
                            break;
                        }
                    }
                }
                
                if ($isGeofenceError) {
                    $errors['general'] = 'Sorry, we cannot provide rides for that region. Please select pickup and dropoff locations within our service areas (Paphos, Limassol, Larnaca, or Nicosia districts).';
                } else {
                    $errors['general'] = 'Failed to create ride request. Please try again.';
                }
            } else {
                $reqRow = sqlsrv_fetch_array($stmtRequest, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmtRequest);
                $rideRequestId = $reqRow['RideRequestID'] ?? null;
                
                error_log("DEBUG request_ride: spCreateRideRequest returned rideRequestId=$rideRequestId");

                if (!$rideRequestId) {
                    $errors['general'] = 'Ride request was created but could not be retrieved.';
                } else {
                    // Check if this is a multi-segment journey and create segments if needed
                    $selectedPathIdRaw = isset($_POST['selected_path_id']) ? $_POST['selected_path_id'] : null;
                    $useDirectRoute = isset($_POST['use_direct_route']) && $_POST['use_direct_route'] === '1';
                    $segmentPricingDataRaw = isset($_POST['segment_pricing_data']) ? $_POST['segment_pricing_data'] : null;
                    
                    // If user selected "direct" route, they want single driver crossing geofences (with surcharge)
                    $isDirectRoute = ($selectedPathIdRaw === 'direct' || $useDirectRoute);
                    $selectedPathId = $isDirectRoute ? null : (int)$selectedPathIdRaw;
                    
                    // Parse segment pricing data from client (OSRM route distances)
                    $segmentPricingData = null;
                    if ($segmentPricingDataRaw) {
                        $segmentPricingData = json_decode($segmentPricingDataRaw, true);
                    }
                    
                    if ($selectedPathId) {
                        
                        // Call stored procedure to calculate and create segments
                        $segmentStmt = db_call_procedure('dbo.spCalculateRideSegments', [
                            $rideRequestId,
                            $pickupLocationId,
                            $dropoffLocationId,
                            $selectedPathId
                        ]);
                        
                        if ($segmentStmt === false) {
                            // Segments failed to create, but ride request exists
                            flash_add('warning', 'Your ride request was created, but segment calculation failed. Please contact support.');
                        } else {
                            // Get the created segments
                            $createdSegments = [];
                            while ($segRow = sqlsrv_fetch_array($segmentStmt, SQLSRV_FETCH_ASSOC)) {
                                $createdSegments[] = $segRow;
                            }
                            sqlsrv_free_stmt($segmentStmt);
                            
                            $segmentCount = count($createdSegments);
                            
                            if ($segmentCount == 0) {
                                // Single segment (direct path within same geofence)
                                $segmentCount = 1;
                            }
                            
                            // Update segment pricing with OSRM data if available
                            // This also updates the segment From/To coordinates to use the actual OSRM crossing points
                            if ($segmentPricingData && is_array($segmentPricingData) && count($segmentPricingData) > 0) {
                                
                                // Update each segment with client-calculated pricing and crossing coordinates
                                foreach ($createdSegments as $idx => $segment) {
                                    if (isset($segmentPricingData[$idx])) {
                                        $pricingItem = $segmentPricingData[$idx];
                                        $segmentId = $segment['SegmentID'];
                                        $distance = (float)($pricingItem['distance'] ?? 0);
                                        $duration = (int)($pricingItem['duration'] ?? 0);
                                        $fare = (float)($pricingItem['price'] ?? 0);
                                        
                                        // Get crossing point coordinates from OSRM data
                                        $fromLat = isset($pricingItem['fromLat']) ? (float)$pricingItem['fromLat'] : null;
                                        $fromLng = isset($pricingItem['fromLng']) ? (float)$pricingItem['fromLng'] : null;
                                        $toLat = isset($pricingItem['toLat']) ? (float)$pricingItem['toLat'] : null;
                                        $toLng = isset($pricingItem['toLng']) ? (float)$pricingItem['toLng'] : null;
                                        
                                        // If we have crossing point coordinates, use the new procedure
                                        if ($fromLat !== null && $fromLng !== null && $toLat !== null && $toLng !== null) {
                                            $updateStmt = db_call_procedure('dbo.spUpdateSegmentCrossingPoints', [
                                                $segmentId,
                                                $fromLat,
                                                $fromLng,
                                                $toLat,
                                                $toLng,
                                                $distance,
                                                $duration,
                                                $fare
                                            ]);
                                        } else {
                                            // Fallback: just update pricing without coordinates
                                            $updateStmt = db_call_procedure('dbo.spUpdateSegmentPricing', [
                                                $segmentId,
                                                $distance,
                                                $duration,
                                                $fare
                                            ]);
                                        }
                                        if ($updateStmt !== false) {
                                            sqlsrv_free_stmt($updateStmt);
                                        }
                                    }
                                }
                            }
                            
                            // Auto-assign a driver for the first segment (live tracking)
                            $autoAssignStmt = db_call_procedure('dbo.spAutoAssignClosestSimulatedDriver', [
                                $rideRequestId
                            ]);
                            
                            if ($autoAssignStmt !== false) {
                                $assignResult = sqlsrv_fetch_array($autoAssignStmt, SQLSRV_FETCH_ASSOC);
                                sqlsrv_free_stmt($autoAssignStmt);
                                
                                if (isset($assignResult['Success']) && $assignResult['Success'] == 1) {
                                    $distance = isset($assignResult['DistanceKm']) ? number_format((float)$assignResult['DistanceKm'], 1) : null;
                                    $tripId = $assignResult['TripID'] ?? null;
                                    
                                    if ($distance) {
                                        flash_add('success', "Your multi-stop ride with {$segmentCount} segments has been booked! A driver has been assigned ({$distance}km away) for the first segment.");
                                    } else {
                                        flash_add('success', "Your multi-stop ride with {$segmentCount} segments has been booked! A driver is on the way.");
                                    }
                                    
                                    // Redirect to ride detail for live tracking
                                    if ($tripId) {
                                        redirect('passenger/ride_detail.php?trip_id=' . $tripId);
                                    } else {
                                        redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                    }
                                } else {
                                    $msg = $assignResult['Message'] ?? 'Waiting for a driver to accept.';
                                    flash_add('info', "Your multi-stop ride with {$segmentCount} segments has been submitted. " . $msg);
                                    if ($rideRequestId) {
                                        redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                    }
                                    flash_add('error', 'Ride request reference was not created. Please try again.');
                                    redirect('passenger/request_ride.php');
                                }
                            } else {
                                flash_add('success', "Your multi-stop ride with {$segmentCount} segments has been submitted. Waiting for driver assignment.");
                                if ($rideRequestId) {
                                    redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                }
                                flash_add('error', 'Ride request reference was not created. Please try again.');
                                redirect('passenger/request_ride.php');
                            }
                        }
                    } elseif ($isDirectRoute) {
                        // Direct route - single driver crossing multiple geofences with surcharge
                        
                        // Try to auto-assign the closest simulated driver
                        $autoAssignStmt = db_call_procedure('dbo.spAutoAssignClosestSimulatedDriver', [
                            $rideRequestId
                        ]);
                        
                        if ($autoAssignStmt !== false) {
                            $assignResult = sqlsrv_fetch_array($autoAssignStmt, SQLSRV_FETCH_ASSOC);
                            sqlsrv_free_stmt($autoAssignStmt);
                            
                            if (isset($assignResult['Success']) && $assignResult['Success'] == 1) {
                                $distance = isset($assignResult['DistanceKm']) ? number_format((float)$assignResult['DistanceKm'], 1) : null;
                                $tripId = $assignResult['TripID'] ?? null;
                                
                                if ($distance) {
                                    flash_add('success', "Your DIRECT ride request has been submitted (crossing multiple zones with 35% surcharge). A driver has been assigned ({$distance}km away).");
                                } else {
                                    flash_add('success', 'Your DIRECT ride request has been submitted (crossing multiple zones with 35% surcharge). A driver has been assigned.');
                                }
                                
                                // If we have a trip ID, redirect to ride detail
                                if ($tripId) {
                                    redirect('passenger/ride_detail.php?trip_id=' . $tripId);
                                } else {
                                    redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                }
                            } else {
                                $msg = $assignResult['Message'] ?? 'Waiting for a driver to accept.';
                                flash_add('info', 'Your DIRECT ride request has been submitted (35% cross-zone surcharge applied). ' . $msg);
                                if ($rideRequestId) {
                                    redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                }
                                flash_add('error', 'Ride request reference was not created. Please try again.');
                                redirect('passenger/request_ride.php');
                            }
                        } else {
                            flash_add('success', 'Your DIRECT ride request has been submitted (35% cross-zone surcharge applied).');
                            if ($rideRequestId) {
                                redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                            }
                            flash_add('error', 'Ride request reference was not created. Please try again.');
                            redirect('passenger/request_ride.php');
                        }
                    } else {
                        error_log("No path selected - creating standard single-driver ride request");
                        
                        // Determine driver type message
                        $driverTypeLabel = $realDriversOnly ? 'real GPS driver' : 'driver';
                        
                        // Try to auto-assign the closest driver (respects RealDriversOnly flag)
                        $autoAssignStmt = db_call_procedure('dbo.spAutoAssignClosestSimulatedDriver', [
                            $rideRequestId
                        ]);
                        
                        if ($autoAssignStmt !== false) {
                            $assignResult = sqlsrv_fetch_array($autoAssignStmt, SQLSRV_FETCH_ASSOC);
                            sqlsrv_free_stmt($autoAssignStmt);
                            
                            if (isset($assignResult['Success']) && $assignResult['Success'] == 1) {
                                $distance = isset($assignResult['DistanceKm']) ? number_format((float)$assignResult['DistanceKm'], 1) : null;
                                $tripId = $assignResult['TripID'] ?? null;
                                $waitingForManualAccept = isset($assignResult['WaitingForManualAccept']) && $assignResult['WaitingForManualAccept'] == 1;
                                $availableCount = $assignResult['AvailableRealDrivers'] ?? 0;

                                if ($realDriversOnly && (!$tripId || $waitingForManualAccept)) {
                                    if ($availableCount > 0) {
                                        flash_add('info', "Your ride request has been submitted. {$availableCount} real driver(s) have been notified and need to accept. Check your ride history for updates.");
                                    } else {
                                        flash_add('info', 'Your ride request has been submitted. Waiting for a real driver to come online and accept your ride.');
                                    }
                                    if ($rideRequestId) {
                                        redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                    }
                                    flash_add('error', 'Ride request reference was not created. Please try again.');
                                    redirect('passenger/request_ride.php');
                                } elseif ($distance && $tripId) {
                                    flash_add('success', "Your ride request has been submitted and a {$driverTypeLabel} has been assigned ({$distance}km away). You can track them on the ride detail page.");
                                    redirect('passenger/ride_detail.php?trip_id=' . $tripId);
                                } elseif ($tripId) {
                                    flash_add('success', "Your ride request has been submitted and a {$driverTypeLabel} has been assigned.");
                                    redirect('passenger/ride_detail.php?trip_id=' . $tripId);
                                } else {
                                    flash_add('info', 'Your ride request has been submitted. Waiting for a driver to accept.');
                                    if ($rideRequestId) {
                                        redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                    }
                                    flash_add('error', 'Ride request reference was not created. Please try again.');
                                    redirect('passenger/request_ride.php');
                                }
                            } else {
                                // No driver available
                                $msg = $assignResult['Message'] ?? 'Waiting for a driver to accept.';
                                if ($realDriversOnly) {
                                    flash_add('warning', 'Your ride request has been submitted (real drivers only). ' . $msg);
                                } else {
                                    flash_add('info', 'Your ride request has been submitted. ' . $msg);
                                }
                                if ($rideRequestId) {
                                    redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                                }
                                flash_add('error', 'Ride request reference was not created. Please try again.');
                                redirect('passenger/request_ride.php');
                            }
                        } else {
                            // Auto-assign call failed, proceed normally
                            flash_add('success', 'Your ride request has been submitted.');
                            if ($rideRequestId) {
                                redirect('passenger/request_status.php?ride_request_id=' . $rideRequestId);
                            }
                            flash_add('error', 'Ride request reference was not created. Please try again.');
                            redirect('passenger/request_ride.php');
                        }
                    }
                    
                    redirect('passenger/rides_history.php');
                }
            }
        }
    }
}

$pageTitle = 'Request a ride';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 980px; margin: 2rem auto;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Request a ride</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                Select pickup and dropoff locations on the map, then add any notes or preferences.
            </p>
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
                        <button type="button" id="use-my-location-btn" class="btn btn-sm" style="background: #2563eb; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px; display: flex; align-items: center; gap: 0.3rem;">
                            <span id="gps-icon">📍</span> Use My Location
                        </button>
                        <button type="button" id="toggle-geofences-btn" onclick="toggleGeofences()" class="btn btn-sm" style="background: #2563eb; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 4px;">
                            🗺️ Hide Zones
                        </button>
                    </div>
                    <div style="font-size: 0.8rem;">
                        Click on the map to set coordinates, or use your device GPS for pickup.
                    </div>
                </div>

                <div id="request-map" class="map-container"></div>

                <?php if (!empty($errors['pickup_location'])): ?>
                    <div class="form-error" style="margin-top: 0.45rem;"><?php echo e($errors['pickup_location']); ?></div>
                <?php endif; ?>
                <?php if (!empty($errors['dropoff_location'])): ?>
                    <div class="form-error" style="margin-top: 0.2rem;"><?php echo e($errors['dropoff_location']); ?></div>
                <?php endif; ?>

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
                        <!-- Hidden inputs to store OSRM route calculations -->
                        <input type="hidden" id="estimated_distance_km" name="estimated_distance_km" value="">
                        <input type="hidden" id="estimated_duration_min" name="estimated_duration_min" value="">
                        <input type="hidden" id="estimated_fare" name="estimated_fare" value="">
                        <input type="hidden" id="selected_path_id" name="selected_path_id" value="">
                        <input type="hidden" id="use_direct_route" name="use_direct_route" value="0">
                        <input type="hidden" id="segment_pricing_data" name="segment_pricing_data" value="">
                    </div>
                </div>
            </div>

            <div>
                <h3 style="font-size: 0.95rem; margin-bottom: 0.6rem;">Details</h3>

                <div class="form-group">
                    <label class="form-label" for="service_type_id">Service Type <span style="color: #c53030;">*</span></label>
                    <select
                        id="service_type_id"
                        name="service_type_id"
                        class="form-control<?php echo !empty($errors['service_type']) ? ' input-error' : ''; ?>"
                        data-required="1"
                    >
                        <?php foreach ($serviceTypes as $st): ?>
                            <option
                                value="<?php echo e($st['ServiceTypeID']); ?>"
                                <?php echo (string)$st['ServiceTypeID'] === $data['service_type_id'] ? 'selected' : ''; ?>
                                title="<?php echo e($st['Description']); ?>"
                            >
                                <?php echo e($st['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['service_type'])): ?>
                        <div class="form-error"><?php echo e($errors['service_type']); ?></div>
                    <?php endif; ?>
                    <div style="font-size: 0.8rem; color: #666; margin-top: 0.3rem;">
                        Select the type of service you need for this ride.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="pickup_description">Pickup description (optional)</label>
                    <input
                        type="text"
                        id="pickup_description"
                        name="pickup_description"
                        class="form-control"
                        value="<?php echo e($data['pickup_description']); ?>"
                        placeholder="e.g. Front of CS building"
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
                    <label class="form-label" for="pickup_postal">Pickup postal code (optional)</label>
                    <input
                        type="text"
                        id="pickup_postal"
                        name="pickup_postal"
                        class="form-control"
                        value="<?php echo e($data['pickup_postal']); ?>"
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
                    <label class="form-label" for="dropoff_postal">Dropoff postal code (optional)</label>
                    <input
                        type="text"
                        id="dropoff_postal"
                        name="dropoff_postal"
                        class="form-control"
                        value="<?php echo e($data['dropoff_postal']); ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="luggage_volume">Luggage volume (m³, optional)</label>
                    <input
                        type="text"
                        id="luggage_volume"
                        name="luggage_volume"
                        class="form-control<?php echo !empty($errors['luggage_volume']) ? ' input-error' : ''; ?>"
                        value="<?php echo e($data['luggage_volume']); ?>"
                        placeholder="e.g. 2.5"
                    >
                    <?php if (!empty($errors['luggage_volume'])): ?>
                        <div class="form-error"><?php echo e($errors['luggage_volume']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer;">
                        <input
                            type="checkbox"
                            name="use_simulation"
                            id="use_simulation"
                            value="1"
                            <?php echo !empty($data['use_simulation']) ? 'checked' : ''; ?>
                            style="margin-top: 0.2rem;"
                        >
                        <span>
                            <strong>Use simulation mode</strong>
                            <div style="font-size: 0.8rem; color: #666; margin-top: 0.2rem;">
                                Enable simulated/test drivers for demo purposes. 
                                <span style="color: #3b82f6;">ℹ️ Uncheck for real drivers with GPS tracking.</span>
                            </div>
                        </span>
                    </label>
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
                    <div style="font-size: 0.8rem; color: #666; margin-top: 0.3rem;">
                        Choose how you will pay for this ride.
                    </div>
                    <!-- Kaspa Payment Info -->
                    <div id="kaspa-payment-info" style="display: none; margin-top: 0.6rem; padding: 0.6rem; background: rgba(73, 234, 203, 0.1); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 6px; font-size: 0.8rem;">
                        <span style="color: #49EACB; font-weight: 600;">💎 Pay with Kaspa</span>
                        <div style="color: #a7f3d0; margin-top: 0.3rem;">
                            Pay directly to the driver with cryptocurrency — <strong>0% fees</strong>, instant settlement!
                            <span id="kaspa-rate-display" style="display: block; margin-top: 0.3rem; font-size: 0.75rem; color: #70B8B0;"></span>
                        </div>
                    </div>
                </div>

                <!-- Multi-Segment Journey Alert -->
                <div class="form-group" id="multi-segment-alert" style="display: none; margin-top: 1rem; padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.3);">
                    <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0; color: #3b82f6;">
                        🚗 Multi-Vehicle Journey Required
                    </h4>
                    <div id="multi-segment-content">
                        <p class="text-muted" style="font-size: 0.82rem; margin: 0 0 0.5rem 0;">
                            Your pickup and dropoff locations are in different service areas. Multiple drivers will handle your journey with transfers at bridge points.
                        </p>
                        <div id="path-options" style="margin-top: 0.8rem;"></div>
                    </div>
                </div>

                <!-- Fare Estimate Section -->
                <div class="form-group" id="fare-estimate-section" style="margin-top: 1rem; padding: 1rem; background: var(--color-surface); border-radius: 8px; border: 1px solid var(--color-border-subtle);">
                    <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0; color: var(--color-text);">
                        <span style="margin-right: 0.4rem;">💰</span> Estimated Fare
                        <span id="route-status" style="display: none; margin-left: 0.5rem; padding: 0.15rem 0.4rem; font-size: 0.7rem; border-radius: 4px; font-weight: 600; background: #3b82f6; color: #fff;">Calculating route...</span>
                        <span id="surge-badge" style="display: none; margin-left: 0.5rem; padding: 0.15rem 0.4rem; font-size: 0.7rem; border-radius: 4px; font-weight: 600;"></span>
                    </h4>
                    <div id="fare-estimate-content">
                        <p class="text-muted" style="font-size: 0.82rem; margin: 0;">
                            Select pickup and dropoff locations to see fare estimate.
                        </p>
                    </div>
                    <!-- Dynamic Pricing Info -->
                    <div id="dynamic-pricing-info" style="display: none; margin-top: 0.75rem; padding: 0.6rem; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 6px; font-size: 0.78rem;">
                        <span style="font-weight: 600; color: var(--color-warning);">⚡ Dynamic Pricing Active</span>
                        <div id="surge-details" style="color: var(--color-text-muted); margin-top: 0.3rem;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Notes for the driver (optional)</label>
                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        rows="3"
                        placeholder="Anything special the driver should know?"
                    ><?php echo e($data['notes']); ?></textarea>
                </div>

                <div class="form-group" style="margin-top: 1.2rem;">
                    <button type="submit" id="submit-btn" class="btn btn-primary" style="width: 100%;">Submit ride request</button>
                    <div id="submit-warning" style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 4px; font-size: 0.8rem; color: #92400e;">
                        ⚠️ Please wait for the route to be calculated before submitting
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.OSRH || typeof window.OSRH.initMap !== 'function') {
        return;
    }

    var map = window.OSRH.initMap('request-map', { lat: 35.1667, lng: 33.3667, zoom: 13 });
    if (!map) {
        return;
    }

    var pickupMarker = null;
    var dropoffMarker = null;
    var routeLine = null;
    var userLocationMarker = null;
    var geofenceLayers = [];      // Store geofence polygon layers
    var bridgeMarkers = [];       // Store bridge markers
    var geofencesVisible = true;  // Toggle state
    var segmentMarkers = [];      // Store segment stop markers
    var segmentRoutes = [];       // Store segment route lines
    var useDirectRoute = false;   // Whether user chose direct route (crossing geofences)
    var cachedGeofenceData = null; // Cache the geofence check response
    var currentRouteType = 'single'; // 'single', 'multi-segment', or 'direct'
    var cachedMultiSegmentPricing = null; // Cache multi-segment pricing for fare display
    
    // Direct route fee multiplier (extra charge for crossing geofences with same driver)
    var DIRECT_ROUTE_FEE_MULTIPLIER = 1.35; // 35% extra for direct route
    
    // Waiting time estimates at transfer points (in minutes)
    var WAITING_TIME_PER_TRANSFER = 8; // Average waiting time at each transfer point
    var WAITING_TIME_COST_PER_MIN = 0.15; // Cost per minute of waiting

    // =====================================================
    // GEOFENCE VISUALIZATION
    // =====================================================
    
    // Color palette for geofences (distinct colors)
    var geofenceColors = [
        { fill: '#3b82f6', border: '#1d4ed8', name: 'blue' },      // Nicosia
        { fill: '#ef4444', border: '#b91c1c', name: 'red' },       // Limassol
        { fill: '#22c55e', border: '#15803d', name: 'green' },     // Larnaca
        { fill: '#f59e0b', border: '#d97706', name: 'orange' },    // Paphos
        { fill: '#8b5cf6', border: '#6d28d9', name: 'purple' },    // Famagusta
        { fill: '#ec4899', border: '#be185d', name: 'pink' }       // Kyrenia
    ];

    function loadGeofences() {
        fetch('../api/get_geofences.php')
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    console.warn('Failed to load geofences:', data.error);
                    return;
                }

                console.log('Loaded', data.count.geofences, 'geofences and', data.count.bridges, 'bridges');

                // Draw geofence polygons
                data.geofences.forEach(function(geofence, index) {
                    if (geofence.points.length < 3) return; // Need at least 3 points for polygon

                    var color = geofenceColors[index % geofenceColors.length];
                    var latlngs = geofence.points.map(function(p) {
                        return [p.lat, p.lng];
                    });

                    // Close the polygon
                    latlngs.push(latlngs[0]);

                    var polygon = L.polygon(latlngs, {
                        color: color.border,
                        fillColor: color.fill,
                        fillOpacity: 0.15,
                        weight: 2,
                        dashArray: '5, 5',
                        interactive: false  // Allow clicks to pass through to map
                    }).addTo(map);

                    // Add label in center of polygon
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
                        interactive: false  // Allow clicks to pass through
                    }).addTo(map);

                    geofenceLayers.push(polygon);
                    geofenceLayers.push(label);
                });

                // Draw bridge markers
                data.bridges.forEach(function(bridge) {
                    var bridgeIcon = L.divIcon({
                        className: 'bridge-marker',
                        html: '<div style="background: #fbbf24; border: 2px solid #d97706; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">🔗</div>',
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });

                    var marker = L.marker([bridge.lat, bridge.lng], { icon: bridgeIcon }).addTo(map);
                    
                    var popupContent = '<div style="text-align: center; min-width: 150px;">' +
                        '<strong>🔗 Transfer Point</strong><br>' +
                        '<span style="color: #666; font-size: 0.85em;">' + bridge.name.replace(/_/g, ' ') + '</span><br>' +
                        '<span style="color: #3b82f6; font-size: 0.8em;">' + bridge.connects + '</span>' +
                        '</div>';
                    marker.bindPopup(popupContent);

                    bridgeMarkers.push(marker);
                    geofenceLayers.push(marker);
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

    // Load geofences on map init
    loadGeofences();

    // =====================================================
    // GPS LOCATION - Use My Location Button
    // =====================================================
    var gpsBtn = document.getElementById('use-my-location-btn');
    var gpsIcon = document.getElementById('gps-icon');
    
    if (gpsBtn && navigator.geolocation) {
        gpsBtn.addEventListener('click', function() {
            // Show loading state
            gpsBtn.disabled = true;
            gpsIcon.textContent = '⏳';
            gpsBtn.querySelector('span:last-child') || gpsBtn.appendChild(document.createTextNode(''));
            var btnText = gpsBtn.childNodes[gpsBtn.childNodes.length - 1];
            btnText.textContent = ' Locating...';

            var options = {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            };

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success - got GPS coordinates
                    var lat = position.coords.latitude;
                    var lng = position.coords.longitude;
                    var accuracy = position.coords.accuracy;

                    console.log('GPS Location:', lat, lng, 'Accuracy:', accuracy, 'm');

                    // Check if location is roughly in Cyprus (lat: 34.5-35.7, lng: 32.2-34.6)
                    if (lat < 34.0 || lat > 36.0 || lng < 31.5 || lng > 35.0) {
                        OSRH.warning('Your GPS location appears to be outside Cyprus.\n\nThe location has been set, but you may want to adjust the pickup point on the map.', 'Location Warning');
                    }

                    // Set pickup to GPS location
                    var latlng = { lat: lat, lng: lng };
                    
                    // Select pickup mode
                    document.querySelector('input[name="point_mode"][value="pickup"]').checked = true;
                    
                    // Set the marker
                    setMarker('pickup', latlng);
                    
                    // Center map on the location with appropriate zoom
                    map.setView([lat, lng], 16);

                    // Show accuracy circle briefly
                    if (userLocationMarker) {
                        map.removeLayer(userLocationMarker);
                    }
                    userLocationMarker = L.circle([lat, lng], {
                        color: '#2563eb',
                        fillColor: '#3b82f6',
                        fillOpacity: 0.15,
                        radius: accuracy,
                        weight: 2
                    }).addTo(map);

                    // Remove accuracy circle after 5 seconds
                    setTimeout(function() {
                        if (userLocationMarker) {
                            map.removeLayer(userLocationMarker);
                            userLocationMarker = null;
                        }
                    }, 5000);

                    // Reset button
                    gpsBtn.disabled = false;
                    gpsIcon.textContent = '✓';
                    gpsBtn.style.background = '#16a34a';
                    btnText.textContent = ' Location Set!';

                    // Reset button text after 2 seconds
                    setTimeout(function() {
                        gpsIcon.textContent = '📍';
                        gpsBtn.style.background = '#2563eb';
                        btnText.textContent = ' Use My Location';
                    }, 2000);

                    // Now select dropoff mode for next click - also update mode variable!
                    setTimeout(function() {
                        document.querySelector('input[name="point_mode"][value="dropoff"]').checked = true;
                        mode = 'dropoff';  // Important: also update the mode variable
                    }, 500);
                },
                function(error) {
                    // Error handling
                    gpsBtn.disabled = false;
                    gpsIcon.textContent = '❌';
                    gpsBtn.style.background = '#dc2626';
                    
                    var errorMsg = '';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg = 'Location permission denied.\n\nPlease allow location access in your browser settings, then try again.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = 'Location information unavailable.\n\nYour device could not determine your location. Please try again or set pickup manually.';
                            break;
                        case error.TIMEOUT:
                            errorMsg = 'Location request timed out.\n\nPlease try again or set pickup manually on the map.';
                            break;
                        default:
                            errorMsg = 'An unknown error occurred while getting your location.';
                    }
                    
                    OSRH.error(errorMsg, 'GPS Error');

                    // Reset button after delay
                    setTimeout(function() {
                        gpsIcon.textContent = '📍';
                        gpsBtn.style.background = '#2563eb';
                        var btnText = gpsBtn.childNodes[gpsBtn.childNodes.length - 1];
                        btnText.textContent = ' Use My Location';
                    }, 2000);
                },
                options
            );
        });
    } else if (gpsBtn) {
        // Geolocation not supported
        gpsBtn.disabled = true;
        gpsBtn.style.background = '#9ca3af';
        gpsBtn.title = 'GPS not available in your browser';
        gpsIcon.textContent = '🚫';
    }

    function setMarker(mode, latlng) {
        if (mode === 'pickup') {
            if (!pickupMarker) {
                pickupMarker = window.OSRH.addMarker(map, latlng.lat, latlng.lng, 'pickup', '<strong>📍 Pickup</strong>');
                // Make marker draggable
                pickupMarker.dragging.enable();
                pickupMarker.on('dragend', function (e) {
                    var pos = e.target.getLatLng();
                    updateInputs('pickup', pos);
                    updateRoutePreview();
                });
            } else {
                pickupMarker.setLatLng(latlng);
            }
            updateInputs('pickup', latlng);
        } else {
            if (!dropoffMarker) {
                dropoffMarker = window.OSRH.addMarker(map, latlng.lat, latlng.lng, 'dropoff', '<strong>🏁 Dropoff</strong>');
                // Make marker draggable
                dropoffMarker.dragging.enable();
                dropoffMarker.on('dragend', function (e) {
                    var pos = e.target.getLatLng();
                    updateInputs('dropoff', pos);
                    updateRoutePreview();
                });
            } else {
                dropoffMarker.setLatLng(latlng);
            }
            updateInputs('dropoff', latlng);
        }
        updateRoutePreview();
        checkGeofencePath(); // Check if multi-segment journey needed
    }

    // Update route preview on the map
    function updateRoutePreview() {
        var pickupLatVal = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLonVal = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLatVal = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLonVal = parseFloat(document.getElementById('dropoff_lon').value);

        console.log('updateRoutePreview called');

        // Clear existing route
        window.OSRH.clearRoutes(map);

        // Only show route if both points are set
        if (!isNaN(pickupLatVal) && !isNaN(pickupLonVal) && !isNaN(dropoffLatVal) && !isNaN(dropoffLonVal)) {
            var pickup = { lat: pickupLatVal, lng: pickupLonVal };
            var dropoff = { lat: dropoffLatVal, lng: dropoffLonVal };

            // Show calculating status
            var routeStatus = document.getElementById('route-status');
            if (routeStatus) {
                routeStatus.style.display = 'inline';
            }

            window.OSRH.showRoute(map, pickup, dropoff, {
                color: '#22c55e',
                weight: 5,
                opacity: 0.8
            }).then(function(routeInfo) {
                console.log('OSRM success in updateRoutePreview');
                // Hide calculating status
                if (routeStatus) {
                    routeStatus.style.display = 'none';
                }
                // Update fare estimate with actual route distance
                updateFareEstimateWithRoute(routeInfo);
            }).catch(function(err) {
                console.warn('Route preview error:', err);
                console.log('OSRM failed, using Haversine fallback');
                // Hide calculating status on error
                if (routeStatus) {
                    routeStatus.style.display = 'none';
                }
                
                // Only set fallback if no distance is already set
                var currentDistance = document.getElementById('estimated_distance_km').value;
                if (!currentDistance || parseFloat(currentDistance) <= 0) {
                    console.log('No existing distance, calculating Haversine fallback');
                    // Calculate fallback distance using Haversine
                    var R = 6371; // Earth's radius in km
                    var dLat = (dropoffLatVal - pickupLatVal) * Math.PI / 180;
                    var dLon = (dropoffLonVal - pickupLonVal) * Math.PI / 180;
                    var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                            Math.cos(pickupLatVal * Math.PI / 180) * Math.cos(dropoffLatVal * Math.PI / 180) *
                            Math.sin(dLon/2) * Math.sin(dLon/2);
                    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                    var distanceKm = R * c * 1.3; // Multiply by 1.3 for road distance estimate
                    var durationMin = Math.max(5, Math.ceil((distanceKm / 30) * 60));
                    
                    console.log('Haversine fallback distance:', distanceKm);
                    
                    // Set fallback distance
                    document.getElementById('estimated_distance_km').value = distanceKm.toFixed(3);
                    document.getElementById('estimated_duration_min').value = durationMin;
                    
                    // Enable submit with fallback
                    var submitBtn = document.getElementById('submit-btn');
                    var submitWarning = document.getElementById('submit-warning');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                        submitBtn.style.cursor = 'pointer';
                    }
                    if (submitWarning) {
                        submitWarning.style.display = 'none';
                    }
                    
                    // Update fare estimate
                    updateFareEstimate();
                } else {
                    console.log('Distance already set to:', currentDistance, '- not overwriting');
                }
            });

            // Fit map to show both markers
            if (pickupMarker && dropoffMarker) {
                var group = L.featureGroup([pickupMarker, dropoffMarker]);
                map.fitBounds(group.getBounds().pad(0.2));
            }
        }
    }

    function updateInputs(prefix, latlng) {
        var latInput = document.getElementById(prefix + '_lat');
        var lonInput = document.getElementById(prefix + '_lon');
        if (latInput && lonInput) {
            latInput.value = latlng.lat.toFixed(6);
            lonInput.value = latlng.lng.toFixed(6);
        }
    }

    var radios = document.querySelectorAll('input[name="point_mode"]');
    var mode = 'pickup';
    Array.prototype.forEach.call(radios, function (r) {
        if (r.checked) {
            mode = r.value;
        }
        r.addEventListener('change', function () {
            if (this.checked) {
                mode = this.value;
            }
        });
    });

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

    map.on('click', function (e) {
        setMarker(mode, e.latlng);
        updateFareEstimate();
    });

    // Listen for service type changes
    document.getElementById('service_type_id').addEventListener('change', function() {
        updateFareEstimate();
        updateRoutePreview();
    });

    // Store route info for fare calculation
    var cachedRouteInfo = null;
    var selectedPathId = null; // Track which path user selected for multi-segment journey
    var geofencePaths = []; // Store all available paths

    // Check for multi-segment geofence journey
    function checkGeofencePath() {
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLon = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLon = parseFloat(document.getElementById('dropoff_lon').value);

        console.log('=== CHECKING GEOFENCE PATH ===');
        console.log('Pickup:', pickupLat, pickupLon);
        console.log('Dropoff:', dropoffLat, dropoffLon);

        if (isNaN(pickupLat) || isNaN(pickupLon) || isNaN(dropoffLat) || isNaN(dropoffLon)) {
            console.log('Missing coordinates, hiding alert');
            document.getElementById('multi-segment-alert').style.display = 'none';
            return;
        }

        console.log('Calling AJAX endpoint...');
        // Call AJAX endpoint
        fetch('check_geofence_path.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'pickup_lat=' + pickupLat + '&pickup_lon=' + pickupLon + 
                  '&dropoff_lat=' + dropoffLat + '&dropoff_lon=' + dropoffLon
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                var data = JSON.parse(text);
                console.log('Parsed JSON:', data);
                
                if (data.requires_bridges && data.paths && data.paths.length > 0) {
                    console.log('Multi-segment journey detected! Showing alert box.');
                    // Show multi-segment alert
                    document.getElementById('multi-segment-alert').style.display = 'block';
                    geofencePaths = data.paths;
                    
                    // First, determine which path matches the actual OSRM route
                    // Then reorder paths so recommended one is first and display
                    findOSRMRecommendedPath(data.paths, function(recommendedPathIndex) {
                        console.log('OSRM recommended path index:', recommendedPathIndex);
                        
                        // Reorder paths: move recommended path to first position
                        var reorderedPaths = data.paths.slice(); // copy array
                        if (recommendedPathIndex > 0) {
                            var recommended = reorderedPaths.splice(recommendedPathIndex, 1)[0];
                            reorderedPaths.unshift(recommended);
                        }
                        geofencePaths = reorderedPaths; // update global reference
                        
                        // Display with index 0 as recommended (since we reordered)
                        displayPathOptions(reorderedPaths, 0);
                        
                        // Auto-select the OSRM-recommended path (now first)
                        var recommendedPath = reorderedPaths[0];
                        selectedPathId = recommendedPath.path_id;
                        document.getElementById('selected_path_id').value = selectedPathId;
                    });
                } else {
                    console.log('Single-geofence journey or no paths found. Hiding alert.');
                    console.log('requires_bridges:', data.requires_bridges);
                    console.log('paths:', data.paths);
                    document.getElementById('multi-segment-alert').style.display = 'none';
                    geofencePaths = [];
                    selectedPathId = null;
                    
                    // Reset to single-zone pricing
                    currentRouteType = 'single';
                    cachedMultiSegmentPricing = null;
                    clearSegmentVisualization();
                    updateFareEstimate();
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response was:', text);
                document.getElementById('multi-segment-alert').style.display = 'none';
                
                // Reset to single-zone pricing on error
                currentRouteType = 'single';
                cachedMultiSegmentPricing = null;
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            document.getElementById('multi-segment-alert').style.display = 'none';
            
            // Reset to single-zone pricing on error
            currentRouteType = 'single';
            cachedMultiSegmentPricing = null;
        });
    }

    // Display available path options to user
    // recommendedIndex: the index of the path that matches OSRM route (default 0)
    function displayPathOptions(paths, recommendedIndex) {
        if (typeof recommendedIndex === 'undefined') recommendedIndex = 0;
        
        var pathOptionsDiv = document.getElementById('path-options');
        
        // Clear previous segment markers and routes
        clearSegmentVisualization();
        
        if (paths.length === 0) {
            pathOptionsDiv.innerHTML = '<p style="color: #ef4444; font-size: 0.8rem;">No available routes found between these locations.</p>';
            return;
        }

        var html = '<div style="font-size: 0.85rem; margin-bottom: 0.5rem; font-weight: 600;">Choose Your Route:</div>';
        
        // Direct Route Option (crossing geofences with same driver - extra fee)
        html += '<div class="path-option direct-route-option" data-path-id="direct" style="';
        html += 'padding: 0.75rem; margin: 0.5rem 0; background: rgba(245, 158, 11, 0.1); ';
        html += 'border: 2px solid #f59e0b; border-radius: 6px; cursor: pointer; ';
        html += 'transition: all 0.2s;">';
        html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">';
        html += '<div style="font-weight: 600; color: #f59e0b; font-size: 0.85rem;">🚗 Direct Route (Single Driver)</div>';
        html += '<div style="font-size: 0.75rem; color: #fbbf24; font-weight: 600;">+35% Fee</div>';
        html += '</div>';
        html += '<div style="font-size: 0.8rem; color: var(--color-text); margin-bottom: 0.3rem;">';
        html += 'One driver takes you directly to destination, crossing all zones without transfers.';
        html += '</div>';
        html += '<div style="font-size: 0.75rem; color: #9ca3af;">';
        html += '⚠️ Higher cost but no driver changes or waiting at transfer points.';
        html += '</div>';
        html += '</div>';
        
        // Multi-segment path options
        paths.forEach(function(path, index) {
            var isRecommended = (index === recommendedIndex);
            var isSelected = isRecommended && !useDirectRoute; // Recommended path is default
            var bgColor = isSelected ? 'rgba(59, 130, 246, 0.15)' : 'rgba(255, 255, 255, 0.05)';
            var borderColor = isSelected ? '#3b82f6' : '#2d3748';
            
            html += '<div class="path-option multi-segment-option" data-path-id="' + path.path_id + '" style="';
            html += 'padding: 0.75rem; margin: 0.5rem 0; background: ' + bgColor + '; ';
            html += 'border: 2px solid ' + borderColor + '; border-radius: 6px; cursor: pointer; ';
            html += 'transition: all 0.2s;">';
            
            // Route header
            html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">';
            html += '<div style="font-weight: 600; color: #3b82f6; font-size: 0.85rem;">';
            html += '🔄 Multi-Stop Route ' + (index + 1);
            if (isRecommended) html += ' (Recommended)';
            html += '</div>';
            html += '<div style="font-size: 0.75rem; color: #9ca3af;">';
            html += path.segment_count + ' segment' + (path.segment_count > 1 ? 's' : '');
            html += '</div>';
            html += '</div>';
            
            // Route description
            html += '<div style="font-size: 0.8rem; color: var(--color-text); margin-bottom: 0.3rem;">';
            html += path.route_description;
            html += '</div>';
            
            // Transfer points
            if (path.transfer_count > 0 && path.bridge_details && path.bridge_details.length > 0) {
                html += '<div style="font-size: 0.75rem; color: #10b981; margin-top: 0.3rem;">';
                html += '📍 Transfer Points: ';
                var transferNames = path.bridge_details.map(function(b) { return b.name.replace(/_/g, ' '); });
                html += transferNames.join(' → ');
                html += '</div>';
            }
            
            html += '</div>';
        });
        
        // Pricing breakdown section (will be populated when route is selected)
        html += '<div id="segment-pricing" style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; border-radius: 6px; display: none;">';
        html += '<div style="font-weight: 600; color: #10b981; margin-bottom: 0.5rem;">💰 Pricing Breakdown</div>';
        html += '<div id="pricing-details"></div>';
        html += '</div>';
        
        pathOptionsDiv.innerHTML = html;
        
        // Add click handlers to select path
        document.querySelectorAll('.path-option').forEach(function(el) {
            el.addEventListener('click', function() {
                var pathId = this.getAttribute('data-path-id');
                selectPath(pathId);
            });
        });
        
        // Auto-select and visualize the OSRM-recommended path
        if (paths.length > 0) {
            selectPath(paths[recommendedIndex].path_id);
        }
    }
    
    // Find which path best matches the actual OSRM route by counting geofence crossings
    function findOSRMRecommendedPath(paths, callback) {
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLng = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLng = parseFloat(document.getElementById('dropoff_lon').value);
        
        if (isNaN(pickupLat) || isNaN(dropoffLat)) {
            console.log('Invalid coordinates, defaulting to first path');
            callback(0);
            return;
        }
        
        // Get OSRM route
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
            pickupLng + ',' + pickupLat + ';' + dropoffLng + ',' + dropoffLat + 
            '?overview=full&geometries=geojson';
        
        fetch(osrmUrl)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.code !== 'Ok' || !data.routes || !data.routes[0]) {
                    console.warn('OSRM route failed, defaulting to first path');
                    callback(0);
                    return;
                }
                
                var routeCoords = data.routes[0].geometry.coordinates;
                console.log('OSRM route for recommendation:', routeCoords.length, 'points');
                
                // Fetch geofence polygons to find actual crossings
                fetch('../api/get_geofences.php')
                    .then(function(response) { return response.json(); })
                    .then(function(geoData) {
                        if (!geoData.success || !geoData.geofences) {
                            console.warn('Could not load geofences, defaulting to first path');
                            callback(0);
                            return;
                        }
                        
                        // Find geofences crossed along the actual OSRM route
                        var crossedGeofenceIds = [];
                        var lastGeofenceId = null;
                        
                        for (var i = 0; i < routeCoords.length; i++) {
                            var lng = routeCoords[i][0];
                            var lat = routeCoords[i][1];
                            
                            var currentGeofenceId = null;
                            for (var g = 0; g < geoData.geofences.length; g++) {
                                var geofence = geoData.geofences[g];
                                if (pointInPolygon(lat, lng, geofence.points)) {
                                    currentGeofenceId = geofence.id;
                                    break;
                                }
                            }
                            
                            if (currentGeofenceId !== null && currentGeofenceId !== lastGeofenceId) {
                                if (lastGeofenceId !== null) {
                                    // Record crossing from lastGeofenceId to currentGeofenceId
                                }
                                crossedGeofenceIds.push(currentGeofenceId);
                                lastGeofenceId = currentGeofenceId;
                            }
                        }
                        
                        console.log('OSRM route crosses geofences:', crossedGeofenceIds);
                        var osrmSegmentCount = crossedGeofenceIds.length;
                        
                        // Find path that matches the OSRM segment count
                        // The path with segment_count matching osrmSegmentCount is recommended
                        var bestMatchIndex = 0;
                        var bestMatchDiff = Infinity;
                        
                        paths.forEach(function(path, index) {
                            var diff = Math.abs(path.segment_count - osrmSegmentCount);
                            if (diff < bestMatchDiff) {
                                bestMatchDiff = diff;
                                bestMatchIndex = index;
                            }
                        });
                        
                        console.log('Best matching path index:', bestMatchIndex, 
                                    'with', paths[bestMatchIndex].segment_count, 'segments',
                                    '(OSRM:', osrmSegmentCount, 'segments)');
                        callback(bestMatchIndex);
                    })
                    .catch(function(err) {
                        console.warn('Geofence fetch error:', err);
                        callback(0);
                    });
            })
            .catch(function(err) {
                console.warn('OSRM error:', err);
                callback(0);
            });
    }

    // Clear segment visualization from map
    function clearSegmentVisualization() {
        segmentMarkers.forEach(function(marker) {
            map.removeLayer(marker);
        });
        segmentMarkers = [];
        
        segmentRoutes.forEach(function(route) {
            map.removeLayer(route);
        });
        segmentRoutes = [];
    }
    
    // Show segment stops on map for selected path
    // UPDATED: Uses OSRM route and finds actual geofence crossing points
    function visualizePathOnMap(path) {
        console.log('visualizePathOnMap called with path:', path);
        
        // Clear previous visualization
        clearSegmentVisualization();
        
        // Get pickup and dropoff coordinates
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLng = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLng = parseFloat(document.getElementById('dropoff_lon').value);
        
        if (isNaN(pickupLat) || isNaN(dropoffLat)) {
            console.log('Invalid coordinates');
            return;
        }
        
        // No bridges means single geofence - simple route
        if (!path || !path.bridge_details || path.bridge_details.length === 0) {
            console.log('No bridges to visualize for this path');
            var points = [
                {lat: pickupLat, lng: pickupLng, label: 'Pickup'},
                {lat: dropoffLat, lng: dropoffLng, label: 'Dropoff'}
            ];
            calculateSegmentPricing(points, path);
            return;
        }
        
        // Get full OSRM route from pickup to dropoff
        var osrmUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
            pickupLng + ',' + pickupLat + ';' + dropoffLng + ',' + dropoffLat + 
            '?overview=full&geometries=geojson';
        
        console.log('Fetching OSRM route for segment visualization...');
        
        fetch(osrmUrl)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.code !== 'Ok' || !data.routes || !data.routes[0]) {
                    console.warn('OSRM route failed, falling back to bridge points');
                    visualizePathWithBridgePoints(path, pickupLat, pickupLng, dropoffLat, dropoffLng);
                    return;
                }
                
                var routeCoords = data.routes[0].geometry.coordinates; // [lng, lat] pairs
                var routeDuration = data.routes[0].duration; // seconds
                var routeDistance = data.routes[0].distance; // meters
                
                console.log('OSRM route received:', routeCoords.length, 'points,', (routeDistance/1000).toFixed(1), 'km');
                
                // Find where the route crosses geofence boundaries
                findGeofenceCrossings(routeCoords, path, function(crossingPoints) {
                    console.log('Found', crossingPoints.length, 'geofence crossing points');
                    
                    // Build segment points: Pickup -> Crossings -> Dropoff
                    var segmentPoints = [{
                        lat: pickupLat, 
                        lng: pickupLng, 
                        label: 'Pickup',
                        routeIndex: 0
                    }];
                    
                    crossingPoints.forEach(function(crossing, idx) {
                        segmentPoints.push({
                            lat: crossing.lat,
                            lng: crossing.lng,
                            label: 'Transfer ' + (idx + 1),
                            routeIndex: crossing.routeIndex,
                            geofenceName: crossing.geofenceName
                        });
                    });
                    
                    segmentPoints.push({
                        lat: dropoffLat, 
                        lng: dropoffLng, 
                        label: 'Dropoff',
                        routeIndex: routeCoords.length - 1
                    });
                    
                    // Draw the OSRM route on map with colored segments
                    drawSegmentedOSRMRoute(routeCoords, segmentPoints);
                    
                    // Create transfer point markers
                    crossingPoints.forEach(function(crossing, idx) {
                        var stopIcon = L.divIcon({
                            html: '<div style="background: #8b5cf6; color: white; padding: 6px 10px; border-radius: 50%; font-weight: bold; font-size: 14px; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.4); text-align: center; min-width: 20px;">' + (idx + 1) + '</div>',
                            className: 'segment-stop-marker',
                            iconSize: [36, 36],
                            iconAnchor: [18, 18]
                        });
                        
                        var stopMarker = L.marker([crossing.lat, crossing.lng], {
                            icon: stopIcon,
                            zIndexOffset: 1000
                        }).addTo(map);
                        
                        var popupContent = '<div style="text-align: center; min-width: 150px;">';
                        popupContent += '<div style="font-weight: bold; color: #8b5cf6; margin-bottom: 5px;">🔄 Transfer Point ' + (idx + 1) + '</div>';
                        popupContent += '<div style="font-size: 12px;">Geofence Boundary</div>';
                        if (crossing.geofenceName) {
                            popupContent += '<div style="font-size: 11px; color: #666; margin-top: 3px;">' + crossing.geofenceName + '</div>';
                        }
                        popupContent += '<div style="font-size: 11px; color: #666; margin-top: 5px;">Driver change here</div>';
                        popupContent += '</div>';
                        stopMarker.bindPopup(popupContent);
                        
                        segmentMarkers.push(stopMarker);
                    });
                    
                    // Calculate pricing using the actual segment points on the OSRM route
                    calculateSegmentPricingFromRoute(routeCoords, segmentPoints, routeDuration, routeDistance, path);
                });
            })
            .catch(function(err) {
                console.warn('OSRM error, falling back to bridge points:', err);
                visualizePathWithBridgePoints(path, pickupLat, pickupLng, dropoffLat, dropoffLng);
            });
    }
    
    // Fallback: visualize path using predefined bridge points
    function visualizePathWithBridgePoints(path, pickupLat, pickupLng, dropoffLat, dropoffLng) {
        var stopNumber = 1;
        path.bridge_details.forEach(function(bridge, index) {
            if (bridge.lat && bridge.lng) {
                var stopIcon = L.divIcon({
                    html: '<div style="background: #8b5cf6; color: white; padding: 6px 10px; border-radius: 50%; font-weight: bold; font-size: 14px; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.4); text-align: center; min-width: 20px;">' + stopNumber + '</div>',
                    className: 'segment-stop-marker',
                    iconSize: [36, 36],
                    iconAnchor: [18, 18]
                });
                
                var stopMarker = L.marker([bridge.lat, bridge.lng], {
                    icon: stopIcon,
                    zIndexOffset: 1000
                }).addTo(map);
                
                var popupContent = '<div style="text-align: center; min-width: 150px;">';
                popupContent += '<div style="font-weight: bold; color: #8b5cf6; margin-bottom: 5px;">🔄 Transfer Point ' + stopNumber + '</div>';
                popupContent += '<div style="font-size: 12px;">' + bridge.name.replace(/_/g, ' ') + '</div>';
                popupContent += '<div style="font-size: 11px; color: #666; margin-top: 5px;">Driver change here</div>';
                popupContent += '</div>';
                stopMarker.bindPopup(popupContent);
                
                segmentMarkers.push(stopMarker);
                stopNumber++;
            }
        });
        
        var segmentColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
        var points = [{lat: pickupLat, lng: pickupLng, label: 'Pickup'}];
        
        path.bridge_details.forEach(function(bridge, index) {
            if (bridge.lat && bridge.lng) {
                points.push({lat: bridge.lat, lng: bridge.lng, label: 'Transfer ' + (index + 1)});
            }
        });
        
        points.push({lat: dropoffLat, lng: dropoffLng, label: 'Dropoff'});
        
        for (var i = 0; i < points.length - 1; i++) {
            var segmentLine = L.polyline([
                [points[i].lat, points[i].lng],
                [points[i + 1].lat, points[i + 1].lng]
            ], {
                color: segmentColors[i % segmentColors.length],
                weight: 5,
                opacity: 0.7,
                dashArray: '10, 10'
            }).addTo(map);
            
            segmentRoutes.push(segmentLine);
        }
        
        calculateSegmentPricingWithOSRM(points, path);
    }
    
    // Find where the OSRM route crosses geofence boundaries
    function findGeofenceCrossings(routeCoords, path, callback) {
        // Fetch geofence polygons
        fetch('../api/get_geofences.php')
            .then(function(response) { return response.json(); })
            .then(function(geoData) {
                if (!geoData.success || !geoData.geofences) {
                    console.warn('Could not load geofences for crossing detection');
                    callback([]);
                    return;
                }
                
                var crossings = [];
                var lastGeofenceId = null;
                
                // For each point in the route, determine which geofence it's in
                for (var i = 0; i < routeCoords.length; i++) {
                    var lng = routeCoords[i][0];
                    var lat = routeCoords[i][1];
                    
                    var currentGeofenceId = null;
                    var currentGeofenceName = null;
                    
                    // Check which geofence this point is in
                    for (var g = 0; g < geoData.geofences.length; g++) {
                        var geofence = geoData.geofences[g];
                        if (pointInPolygon(lat, lng, geofence.points)) {
                            currentGeofenceId = geofence.id;
                            currentGeofenceName = geofence.name;
                            break;
                        }
                    }
                    
                    // Detect geofence change
                    if (lastGeofenceId !== null && currentGeofenceId !== null && 
                        lastGeofenceId !== currentGeofenceId) {
                        // Found a crossing! Use the midpoint between this and previous point
                        var prevLng = routeCoords[i - 1][0];
                        var prevLat = routeCoords[i - 1][1];
                        
                        crossings.push({
                            lat: (prevLat + lat) / 2,
                            lng: (prevLng + lng) / 2,
                            routeIndex: i,
                            fromGeofence: lastGeofenceId,
                            toGeofence: currentGeofenceId,
                            geofenceName: currentGeofenceName
                        });
                    }
                    
                    if (currentGeofenceId !== null) {
                        lastGeofenceId = currentGeofenceId;
                    }
                }
                
                callback(crossings);
            })
            .catch(function(err) {
                console.warn('Error finding geofence crossings:', err);
                callback([]);
            });
    }
    
    // Point-in-polygon test (ray casting algorithm)
    function pointInPolygon(lat, lng, polygon) {
        var inside = false;
        var n = polygon.length;
        
        for (var i = 0, j = n - 1; i < n; j = i++) {
            var yi = polygon[i].lat, xi = polygon[i].lng;
            var yj = polygon[j].lat, xj = polygon[j].lng;
            
            if (((yi > lat) !== (yj > lat)) &&
                (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi)) {
                inside = !inside;
            }
        }
        
        return inside;
    }
    
    // Draw the OSRM route with colored segments
    function drawSegmentedOSRMRoute(routeCoords, segmentPoints) {
        var segmentColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
        
        // Draw each segment with its own color
        for (var s = 0; s < segmentPoints.length - 1; s++) {
            var startIdx = segmentPoints[s].routeIndex || 0;
            var endIdx = segmentPoints[s + 1].routeIndex || (routeCoords.length - 1);
            
            // Extract the route points for this segment
            var segmentCoords = [];
            for (var i = startIdx; i <= endIdx && i < routeCoords.length; i++) {
                segmentCoords.push([routeCoords[i][1], routeCoords[i][0]]); // [lat, lng]
            }
            
            if (segmentCoords.length >= 2) {
                var segmentLine = L.polyline(segmentCoords, {
                    color: segmentColors[s % segmentColors.length],
                    weight: 5,
                    opacity: 0.85
                }).addTo(map);
                
                segmentRoutes.push(segmentLine);
            }
        }
    }
    
    // Calculate segment pricing from the actual OSRM route
    function calculateSegmentPricingFromRoute(routeCoords, segmentPoints, totalDuration, totalDistance, path) {
        var serviceTypeId = document.getElementById('service_type_id').value;
        var pricing = {
            '1': { base: 3.00, perKm: 1.20, perMin: 0.20, min: 5.00, name: 'Standard' },
            '2': { base: 8.00, perKm: 2.50, perMin: 0.40, min: 15.00, name: 'Luxury' },
            '3': { base: 5.00, perKm: 1.80, perMin: 0.25, min: 10.00, name: 'Light Cargo' },
            '4': { base: 10.00, perKm: 2.20, perMin: 0.30, min: 20.00, name: 'Heavy Cargo' },
            '5': { base: 4.00, perKm: 1.40, perMin: 0.22, min: 8.00, name: 'Multi-Stop' }
        };
        var p = pricing[serviceTypeId] || pricing['1'];
        
        var numTransfers = segmentPoints.length - 2; // Exclude pickup and dropoff
        var segmentPrices = [];
        var totalPrice = 0;
        var accumulatedDistance = 0;
        
        // Calculate distance for each segment
        for (var s = 0; s < segmentPoints.length - 1; s++) {
            var startIdx = segmentPoints[s].routeIndex || 0;
            var endIdx = segmentPoints[s + 1].routeIndex || (routeCoords.length - 1);
            
            // Calculate segment distance by summing route point distances
            var segmentDistance = 0;
            for (var i = startIdx; i < endIdx && i < routeCoords.length - 1; i++) {
                var d = haversineDistance(
                    routeCoords[i][1], routeCoords[i][0],
                    routeCoords[i + 1][1], routeCoords[i + 1][0]
                );
                segmentDistance += d;
            }
            
            // Estimate duration proportionally
            var segmentDuration = Math.ceil((segmentDistance / (totalDistance / 1000)) * (totalDuration / 60));
            if (segmentDuration < 2) segmentDuration = 2;
            
            var segmentPrice = p.base + (segmentDistance * p.perKm) + (segmentDuration * p.perMin);
            if (segmentPrice < p.min) segmentPrice = p.min;
            
            segmentPrices.push({
                index: s,
                from: segmentPoints[s].label,
                to: segmentPoints[s + 1].label,
                fromLat: segmentPoints[s].lat,
                fromLng: segmentPoints[s].lng,
                toLat: segmentPoints[s + 1].lat,
                toLng: segmentPoints[s + 1].lng,
                distance: segmentDistance,
                duration: segmentDuration,
                price: segmentPrice,
                isTransfer: s < segmentPoints.length - 2
            });
            
            totalPrice += segmentPrice;
            accumulatedDistance += segmentDistance;
        }
        
        // Add waiting time costs
        var totalWaitingTime = numTransfers * WAITING_TIME_PER_TRANSFER;
        var waitingCost = totalWaitingTime * WAITING_TIME_COST_PER_MIN;
        totalPrice += waitingCost;
        var totalDurationMin = Math.ceil(totalDuration / 60) + totalWaitingTime;
        
        // Build pricing HTML
        var pricingHtml = '';
        segmentPrices.forEach(function(seg, idx) {
            var segColor = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'][idx % 5];
            pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; padding: 0.4rem; background: rgba(255,255,255,0.05); border-radius: 4px; border-left: 3px solid ' + segColor + ';">';
            pricingHtml += '<div style="font-size: 0.8rem;"><span style="color: ' + segColor + '; font-weight: 600;">Leg ' + (idx + 1) + ':</span> ' + seg.from + ' → ' + seg.to + '</div>';
            pricingHtml += '<div style="font-size: 0.8rem; color: #10b981; font-weight: 600;">€' + seg.price.toFixed(2) + '</div>';
            pricingHtml += '</div>';
            pricingHtml += '<div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 0.3rem; padding-left: 0.5rem;">';
            pricingHtml += seg.distance.toFixed(1) + ' km • ~' + seg.duration + ' min';
            pricingHtml += '</div>';
            
            if (seg.isTransfer) {
                pricingHtml += '<div style="font-size: 0.75rem; color: #f59e0b; margin-bottom: 0.5rem; padding: 0.3rem 0.5rem; background: rgba(245, 158, 11, 0.1); border-radius: 4px;">';
                pricingHtml += '⏱️ Est. waiting for next driver: ~' + WAITING_TIME_PER_TRANSFER + ' min (€' + (WAITING_TIME_PER_TRANSFER * WAITING_TIME_COST_PER_MIN).toFixed(2) + ')';
                pricingHtml += '</div>';
            }
        });
        
        // Summary
        pricingHtml += '<div style="border-top: 1px solid #374151; margin-top: 0.5rem; padding-top: 0.5rem;">';
        if (numTransfers > 0) {
            pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; font-size: 0.8rem;">';
            pricingHtml += '<div style="color: #f59e0b;">Total waiting time (' + numTransfers + ' transfer' + (numTransfers > 1 ? 's' : '') + ')</div>';
            pricingHtml += '<div style="color: #f59e0b;">~' + totalWaitingTime + ' min / €' + waitingCost.toFixed(2) + '</div>';
            pricingHtml += '</div>';
        }
        pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; font-size: 0.8rem;">';
        pricingHtml += '<div>Total journey time</div>';
        pricingHtml += '<div>~' + totalDurationMin + ' min</div>';
        pricingHtml += '</div>';
        pricingHtml += '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 0.95rem; margin-top: 0.4rem;">';
        pricingHtml += '<div>Total (' + accumulatedDistance.toFixed(1) + ' km)</div>';
        pricingHtml += '<div style="color: #10b981; font-size: 1.1rem;">€' + totalPrice.toFixed(2) + '</div>';
        pricingHtml += '</div>';
        pricingHtml += '</div>';
        
        document.getElementById('pricing-details').innerHTML = pricingHtml;
        document.getElementById('segment-pricing').style.display = 'block';
        
        // Cache pricing data
        cachedMultiSegmentPricing = {
            type: 'multi-segment',
            totalDistance: accumulatedDistance,
            totalDuration: totalDurationMin,
            totalWaitingTime: totalWaitingTime,
            waitingCost: waitingCost,
            totalPrice: totalPrice,
            numTransfers: numTransfers,
            segmentPrices: segmentPrices
        };
        currentRouteType = 'multi-segment';
        
        // Store segment pricing data in hidden field for form submission
        document.getElementById('segment_pricing_data').value = JSON.stringify(segmentPrices);
        
        updateMainFareEstimate();
    }
    
    // Calculate per-segment pricing using OSRM for actual road distances
    function calculateSegmentPricingWithOSRM(points, path) {
        console.log('calculateSegmentPricingWithOSRM called with points:', points, 'path:', path);
        
        if (points.length < 2) {
            console.log('Not enough points for pricing calculation');
            return;
        }
        
        // Get service type pricing
        var serviceTypeId = document.getElementById('service_type_id').value;
        var pricing = {
            '1': { base: 3.00, perKm: 1.20, perMin: 0.20, min: 5.00, name: 'Standard' },
            '2': { base: 8.00, perKm: 2.50, perMin: 0.40, min: 15.00, name: 'Luxury' },
            '3': { base: 5.00, perKm: 1.80, perMin: 0.25, min: 10.00, name: 'Light Cargo' },
            '4': { base: 10.00, perKm: 2.20, perMin: 0.30, min: 20.00, name: 'Heavy Cargo' },
            '5': { base: 4.00, perKm: 1.40, perMin: 0.22, min: 8.00, name: 'Multi-Stop' }
        };
        var p = pricing[serviceTypeId] || pricing['1'];
        
        var numTransfers = path.bridge_details ? path.bridge_details.length : 0;
        
        // Show loading state
        document.getElementById('pricing-details').innerHTML = '<div style="text-align: center; padding: 1rem; color: #9ca3af;"><span style="animation: pulse 1.5s infinite;">Calculating route distances...</span></div>';
        document.getElementById('segment-pricing').style.display = 'block';
        
        // Build array of segment route requests
        var segmentPromises = [];
        for (var i = 0; i < points.length - 1; i++) {
            (function(index) {
                var from = points[index];
                var to = points[index + 1];
                var fromLabel = index === 0 ? 'Pickup' : (path.bridge_details ? path.bridge_details[index - 1].name.replace(/_/g, ' ') : 'Transfer ' + index);
                var toLabel = index === points.length - 2 ? 'Dropoff' : (path.bridge_details ? path.bridge_details[index].name.replace(/_/g, ' ') : 'Transfer ' + (index + 1));
                
                // Use OSRM to get actual route distance
                var routeUrl = 'https://router.project-osrm.org/route/v1/driving/' + 
                    from.lng + ',' + from.lat + ';' + to.lng + ',' + to.lat + 
                    '?overview=false&alternatives=false';
                
                var promise = fetch(routeUrl)
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        var distance, duration;
                        if (data.code === 'Ok' && data.routes && data.routes.length > 0) {
                            distance = data.routes[0].distance / 1000; // Convert meters to km
                            duration = Math.ceil(data.routes[0].duration / 60); // Convert seconds to minutes
                        } else {
                            // Fallback to Haversine with 1.3x multiplier
                            distance = haversineDistance(from.lat, from.lng, to.lat, to.lng) * 1.3;
                            duration = Math.ceil((distance / 40) * 60);
                        }
                        
                        var segmentPrice = p.base + (distance * p.perKm) + (duration * p.perMin);
                        if (segmentPrice < p.min) segmentPrice = p.min;
                        
                        return {
                            index: index,
                            from: fromLabel,
                            to: toLabel,
                            fromLat: from.lat,
                            fromLng: from.lng,
                            toLat: to.lat,
                            toLng: to.lng,
                            distance: distance,
                            duration: duration,
                            price: segmentPrice,
                            isTransfer: index < points.length - 2
                        };
                    })
                    .catch(function(err) {
                        console.warn('OSRM error for segment ' + index + ':', err);
                        // Fallback to Haversine
                        var distance = haversineDistance(from.lat, from.lng, to.lat, to.lng) * 1.3;
                        var duration = Math.ceil((distance / 40) * 60);
                        var segmentPrice = p.base + (distance * p.perKm) + (duration * p.perMin);
                        if (segmentPrice < p.min) segmentPrice = p.min;
                        
                        return {
                            index: index,
                            from: fromLabel,
                            to: toLabel,
                            fromLat: from.lat,
                            fromLng: from.lng,
                            toLat: to.lat,
                            toLng: to.lng,
                            distance: distance,
                            duration: duration,
                            price: segmentPrice,
                            isTransfer: index < points.length - 2
                        };
                    });
                
                segmentPromises.push(promise);
            })(i);
        }
        
        // Wait for all segment routes to be calculated
        Promise.all(segmentPromises).then(function(segments) {
            // Sort by index to maintain order
            segments.sort(function(a, b) { return a.index - b.index; });
            
            var totalDistance = 0;
            var totalPrice = 0;
            var totalDuration = 0;
            var segmentPrices = [];
            
            segments.forEach(function(seg) {
                totalDistance += seg.distance;
                totalDuration += seg.duration;
                totalPrice += seg.price;
                segmentPrices.push(seg);
            });
            
            // Add waiting time costs at transfer points
            var totalWaitingTime = numTransfers * WAITING_TIME_PER_TRANSFER;
            var waitingCost = totalWaitingTime * WAITING_TIME_COST_PER_MIN;
            totalPrice += waitingCost;
            totalDuration += totalWaitingTime;
            
            // Build pricing breakdown HTML
            var pricingHtml = '';
            segmentPrices.forEach(function(seg, idx) {
                var segColor = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'][idx % 5];
                pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; padding: 0.4rem; background: rgba(255,255,255,0.05); border-radius: 4px; border-left: 3px solid ' + segColor + ';">';
                pricingHtml += '<div style="font-size: 0.8rem;"><span style="color: ' + segColor + '; font-weight: 600;">Leg ' + (idx + 1) + ':</span> ' + seg.from + ' → ' + seg.to + '</div>';
                pricingHtml += '<div style="font-size: 0.8rem; color: #10b981; font-weight: 600;">€' + seg.price.toFixed(2) + '</div>';
                pricingHtml += '</div>';
                pricingHtml += '<div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 0.3rem; padding-left: 0.5rem;">';
                pricingHtml += seg.distance.toFixed(1) + ' km • ~' + seg.duration + ' min';
                pricingHtml += '</div>';
                
                if (seg.isTransfer) {
                    pricingHtml += '<div style="font-size: 0.75rem; color: #f59e0b; margin-bottom: 0.5rem; padding: 0.3rem 0.5rem; background: rgba(245, 158, 11, 0.1); border-radius: 4px;">';
                    pricingHtml += '⏱️ Est. waiting for next driver: ~' + WAITING_TIME_PER_TRANSFER + ' min (€' + (WAITING_TIME_PER_TRANSFER * WAITING_TIME_COST_PER_MIN).toFixed(2) + ')';
                    pricingHtml += '</div>';
                }
            });
            
            // Summary
            pricingHtml += '<div style="border-top: 1px solid #374151; margin-top: 0.5rem; padding-top: 0.5rem;">';
            if (numTransfers > 0) {
                pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; font-size: 0.8rem;">';
                pricingHtml += '<div style="color: #f59e0b;">Total waiting time (' + numTransfers + ' transfer' + (numTransfers > 1 ? 's' : '') + ')</div>';
                pricingHtml += '<div style="color: #f59e0b;">~' + totalWaitingTime + ' min / €' + waitingCost.toFixed(2) + '</div>';
                pricingHtml += '</div>';
            }
            pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; font-size: 0.8rem;">';
            pricingHtml += '<div>Total journey time</div>';
            pricingHtml += '<div>~' + totalDuration + ' min</div>';
            pricingHtml += '</div>';
            pricingHtml += '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 0.95rem; margin-top: 0.4rem;">';
            pricingHtml += '<div>Total (' + totalDistance.toFixed(1) + ' km)</div>';
            pricingHtml += '<div style="color: #10b981; font-size: 1.1rem;">€' + totalPrice.toFixed(2) + '</div>';
            pricingHtml += '</div>';
            pricingHtml += '</div>';
            
            // Update UI
            document.getElementById('pricing-details').innerHTML = pricingHtml;
            document.getElementById('segment-pricing').style.display = 'block';
            
            // Cache pricing data
            cachedMultiSegmentPricing = {
                type: 'multi-segment',
                totalDistance: totalDistance,
                totalDuration: totalDuration,
                totalWaitingTime: totalWaitingTime,
                waitingCost: waitingCost,
                totalPrice: totalPrice,
                numTransfers: numTransfers,
                segmentPrices: segmentPrices
            };
            currentRouteType = 'multi-segment';
            
            // Store segment pricing data in hidden field for form submission
            document.getElementById('segment_pricing_data').value = JSON.stringify(segmentPrices);
            
            updateMainFareEstimate();
        });
    }
    
    // Haversine formula for distance calculation (fallback)
    function haversineDistance(lat1, lng1, lat2, lng2) {
        var R = 6371; // Earth's radius in km
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }
    
    // Calculate direct route pricing (with surcharge, but no waiting)
    function calculateDirectRoutePricing() {
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLng = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLng = parseFloat(document.getElementById('dropoff_lon').value);
        
        // Get service type pricing
        var serviceTypeId = document.getElementById('service_type_id').value;
        var pricing = {
            '1': { base: 3.00, perKm: 1.20, perMin: 0.20, min: 5.00, name: 'Standard' },
            '2': { base: 8.00, perKm: 2.50, perMin: 0.40, min: 15.00, name: 'Luxury' },
            '3': { base: 5.00, perKm: 1.80, perMin: 0.25, min: 10.00, name: 'Light Cargo' },
            '4': { base: 10.00, perKm: 2.20, perMin: 0.30, min: 20.00, name: 'Heavy Cargo' },
            '5': { base: 4.00, perKm: 1.40, perMin: 0.22, min: 8.00, name: 'Multi-Stop' }
        };
        var p = pricing[serviceTypeId] || pricing['1'];
        
        var distance = haversineDistance(pickupLat, pickupLng, dropoffLat, dropoffLng);
        // Estimate duration: ~40 km/h average
        var durationMin = Math.ceil((distance / 40) * 60);
        
        var basePrice = p.base + (distance * p.perKm) + (durationMin * p.perMin);
        var directPrice = basePrice * DIRECT_ROUTE_FEE_MULTIPLIER;
        var surcharge = directPrice - basePrice;
        
        var pricingHtml = '';
        pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem; padding: 0.4rem; background: rgba(245, 158, 11, 0.1); border-radius: 4px; border-left: 3px solid #f59e0b;">';
        pricingHtml += '<div style="font-size: 0.8rem;"><span style="color: #f59e0b; font-weight: 600;">🚗 Direct Route:</span> Pickup → Dropoff</div>';
        pricingHtml += '<div style="font-size: 0.8rem; color: #10b981; font-weight: 600;">€' + basePrice.toFixed(2) + '</div>';
        pricingHtml += '</div>';
        pricingHtml += '<div style="font-size: 0.7rem; color: #9ca3af; margin-bottom: 0.5rem; padding-left: 0.5rem;">';
        pricingHtml += distance.toFixed(1) + ' km • ~' + durationMin + ' min • No waiting!';
        pricingHtml += '</div>';
        
        pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem; padding: 0.4rem; background: rgba(245, 158, 11, 0.2); border-radius: 4px;">';
        pricingHtml += '<div style="font-size: 0.8rem; color: #f59e0b;">⚠️ Cross-Zone Surcharge (+35%)</div>';
        pricingHtml += '<div style="font-size: 0.8rem; color: #f59e0b; font-weight: 600;">+€' + surcharge.toFixed(2) + '</div>';
        pricingHtml += '</div>';
        
        pricingHtml += '<div style="font-size: 0.75rem; color: #10b981; margin-bottom: 0.5rem; padding: 0.3rem 0.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 4px;">';
        pricingHtml += '✓ No transfers • No waiting time • Single driver entire journey';
        pricingHtml += '</div>';
        
        pricingHtml += '<div style="border-top: 1px solid #374151; margin-top: 0.5rem; padding-top: 0.5rem;">';
        pricingHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem; font-size: 0.8rem;">';
        pricingHtml += '<div>Total journey time</div>';
        pricingHtml += '<div>~' + durationMin + ' min (fastest!)</div>';
        pricingHtml += '</div>';
        pricingHtml += '<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 0.95rem; margin-top: 0.4rem;">';
        pricingHtml += '<div>Total (' + distance.toFixed(1) + ' km)</div>';
        pricingHtml += '<div style="color: #f59e0b; font-size: 1.1rem;">€' + directPrice.toFixed(2) + '</div>';
        pricingHtml += '</div>';
        pricingHtml += '</div>';
        
        document.getElementById('pricing-details').innerHTML = pricingHtml;
        document.getElementById('segment-pricing').style.display = 'block';
        
        // Cache the pricing and update main fare estimate
        cachedMultiSegmentPricing = {
            type: 'direct',
            totalDistance: distance,
            totalDuration: durationMin,
            totalWaitingTime: 0,
            basePrice: basePrice,
            surcharge: surcharge,
            totalPrice: directPrice
        };
        currentRouteType = 'direct';
        updateMainFareEstimate();
    }

    // Select a specific path
    function selectPath(pathId) {
        console.log('selectPath called with:', pathId, 'type:', typeof pathId);
        
        // Clear segment visualization first
        clearSegmentVisualization();
        
        // Handle direct route selection
        if (pathId === 'direct') {
            useDirectRoute = true;
            selectedPathId = null;
            document.getElementById('selected_path_id').value = 'direct';
            document.getElementById('use_direct_route').value = '1';
            
            // Update visual selection
            document.querySelectorAll('.path-option').forEach(function(el) {
                var elPathId = el.getAttribute('data-path-id');
                if (elPathId === 'direct') {
                    el.style.background = 'rgba(245, 158, 11, 0.2)';
                    el.style.borderColor = '#f59e0b';
                } else {
                    el.style.background = 'rgba(255, 255, 255, 0.05)';
                    el.style.borderColor = '#2d3748';
                }
            });
            
            // Calculate and show direct route pricing - this updates both segment and main fare
            calculateDirectRoutePricing();
            
            console.log('Selected: Direct Route, cachedMultiSegmentPricing:', cachedMultiSegmentPricing);
            return;
        }
        
        // Handle multi-segment path selection
        useDirectRoute = false;
        selectedPathId = typeof pathId === 'string' ? parseInt(pathId) : pathId;
        
        // Update hidden field for form submission
        document.getElementById('selected_path_id').value = selectedPathId;
        document.getElementById('use_direct_route').value = '0';
        
        // Update visual selection
        document.querySelectorAll('.path-option').forEach(function(el) {
            var elPathId = el.getAttribute('data-path-id');
            var elPathIdNum = elPathId === 'direct' ? null : parseInt(elPathId);
            if (elPathIdNum === selectedPathId) {
                el.style.background = 'rgba(59, 130, 246, 0.15)';
                el.style.borderColor = '#3b82f6';
            } else if (elPathId === 'direct') {
                el.style.background = 'rgba(245, 158, 11, 0.1)';
                el.style.borderColor = '#f59e0b';
            } else {
                el.style.background = 'rgba(255, 255, 255, 0.05)';
                el.style.borderColor = '#2d3748';
            }
        });
        
        // Find and visualize the selected path
        console.log('Looking for path with id:', selectedPathId, 'in geofencePaths:', geofencePaths);
        var selectedPath = geofencePaths.find(function(p) { 
            return p.path_id === selectedPathId || parseInt(p.path_id) === selectedPathId; 
        });
        console.log('Found selectedPath:', selectedPath);
        
        if (selectedPath) {
            visualizePathOnMap(selectedPath);
        } else {
            console.warn('Could not find path with id:', selectedPathId);
        }
        
        console.log('Selected path:', selectedPathId, 'cachedMultiSegmentPricing:', cachedMultiSegmentPricing);
    }

    // Store route info for fare calculation
    var cachedRouteInfo = null;

    // Update fare estimate with actual route distance (from OSRM)
    function updateFareEstimateWithRoute(routeInfo) {
        cachedRouteInfo = routeInfo;
        
        var submitBtn = document.getElementById('submit-btn');
        var submitWarning = document.getElementById('submit-warning');
        
        console.log('Route Info:', routeInfo);
        
        // Store route distance and duration in hidden inputs for form submission
        if (routeInfo && !routeInfo.isFallback) {
            var distKm = parseFloat(routeInfo.distance);
            var durMin = Math.round(routeInfo.duration);
            
            console.log('Setting distance:', distKm, 'duration:', durMin);
            
            document.getElementById('estimated_distance_km').value = distKm.toFixed(3);
            document.getElementById('estimated_duration_min').value = durMin;
            
            // Enable submit button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
            if (submitWarning) {
                submitWarning.style.display = 'none';
            }
        } else {
            // Clear if fallback (straight line) - will be set by error handler
            console.log('Fallback route, clearing distance');
            document.getElementById('estimated_distance_km').value = '';
            document.getElementById('estimated_duration_min').value = '';
            
            // Disable submit button
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
            if (submitWarning) {
                submitWarning.style.display = 'block';
            }
        }
        
        updateFareEstimate();
    }
    
    // Update main fare estimate based on selected route type
    function updateMainFareEstimate() {
        console.log('updateMainFareEstimate called, cachedMultiSegmentPricing:', cachedMultiSegmentPricing, 'currentRouteType:', currentRouteType);
        
        var contentDiv = document.getElementById('fare-estimate-content');
        var surgeBadge = document.getElementById('surge-badge');
        var dynamicInfo = document.getElementById('dynamic-pricing-info');
        
        if (!cachedMultiSegmentPricing) {
            console.log('No cached pricing, falling back to standard updateFareEstimate');
            // No multi-segment pricing cached, use default fare estimate
            // But DON'T call updateFareEstimate here to avoid infinite loop
            return;
        }
        
        console.log('Updating fare estimate content with type:', cachedMultiSegmentPricing.type);
        
        var pricing = cachedMultiSegmentPricing;
        var html = '';
        
        if (pricing.type === 'multi-segment') {
            // Multi-segment route pricing
            surgeBadge.style.display = 'inline';
            surgeBadge.textContent = '🔄 Multi-Stop';
            surgeBadge.style.background = '#3b82f6';
            surgeBadge.style.color = '#fff';
            dynamicInfo.style.display = 'none';
            
            html = '<table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">';
            html += '<tr style="background: rgba(59, 130, 246, 0.1);"><td colspan="2" style="padding: 0.4rem; font-weight: 600; color: #3b82f6;">🔄 Multi-Segment Route Selected</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0;">Total Distance</td><td style="text-align: right; padding: 0.2rem 0;">' + pricing.totalDistance.toFixed(1) + ' km</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0;">Travel Time</td><td style="text-align: right; padding: 0.2rem 0;">~' + (pricing.totalDuration - pricing.totalWaitingTime) + ' min</td></tr>';
            if (pricing.totalWaitingTime > 0) {
                html += '<tr><td style="padding: 0.2rem 0; color: #f59e0b;">⏱️ Waiting at ' + pricing.numTransfers + ' transfer(s)</td><td style="text-align: right; padding: 0.2rem 0; color: #f59e0b;">~' + pricing.totalWaitingTime + ' min</td></tr>';
                html += '<tr><td style="padding: 0.2rem 0; color: #f59e0b;">Waiting Cost</td><td style="text-align: right; padding: 0.2rem 0; color: #f59e0b;">€' + pricing.waitingCost.toFixed(2) + '</td></tr>';
            }
            html += '<tr><td style="padding: 0.2rem 0; font-weight: 500;">Total Journey Time</td><td style="text-align: right; padding: 0.2rem 0; font-weight: 500;">~' + pricing.totalDuration + ' min</td></tr>';
            html += '<tr style="border-top: 1px solid #dee2e6; font-weight: bold;"><td style="padding: 0.4rem 0; color: #10b981;">Estimated Total</td><td style="text-align: right; padding: 0.4rem 0; color: #10b981; font-size: 1.1rem;">€' + pricing.totalPrice.toFixed(2) + '</td></tr>';
            html += '</table>';
            html += '<p style="font-size: 0.72rem; color: #3b82f6; margin: 0.5rem 0 0 0;">💡 You save money with transfers, but expect ~' + pricing.totalWaitingTime + ' min wait time at bridge points.</p>';
            
        } else if (pricing.type === 'direct') {
            // Direct route pricing
            surgeBadge.style.display = 'inline';
            surgeBadge.textContent = '🚗 Direct +35%';
            surgeBadge.style.background = '#f59e0b';
            surgeBadge.style.color = '#fff';
            dynamicInfo.style.display = 'block';
            document.getElementById('surge-details').innerHTML = '⚡ Cross-zone surcharge applied for direct route (no transfers)';
            
            html = '<table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">';
            html += '<tr style="background: rgba(245, 158, 11, 0.1);"><td colspan="2" style="padding: 0.4rem; font-weight: 600; color: #f59e0b;">🚗 Direct Route Selected (Fastest)</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0;">Total Distance</td><td style="text-align: right; padding: 0.2rem 0;">' + pricing.totalDistance.toFixed(1) + ' km</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0;">Travel Time</td><td style="text-align: right; padding: 0.2rem 0;">~' + pricing.totalDuration + ' min</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0; color: #10b981;">✓ No waiting time</td><td style="text-align: right; padding: 0.2rem 0; color: #10b981;">0 min</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0;">Base Fare</td><td style="text-align: right; padding: 0.2rem 0;">€' + pricing.basePrice.toFixed(2) + '</td></tr>';
            html += '<tr><td style="padding: 0.2rem 0; color: #f59e0b;">Cross-Zone Surcharge (+35%)</td><td style="text-align: right; padding: 0.2rem 0; color: #f59e0b;">+€' + pricing.surcharge.toFixed(2) + '</td></tr>';
            html += '<tr style="border-top: 1px solid #dee2e6; font-weight: bold;"><td style="padding: 0.4rem 0; color: #f59e0b;">Estimated Total</td><td style="text-align: right; padding: 0.4rem 0; color: #f59e0b; font-size: 1.1rem;">€' + pricing.totalPrice.toFixed(2) + '</td></tr>';
            html += '</table>';
            html += '<p style="font-size: 0.72rem; color: #f59e0b; margin: 0.5rem 0 0 0;">⚡ Fastest option! No waiting, single driver takes you directly to destination.</p>';
        }
        
        console.log('Setting fare-estimate-content innerHTML, html length:', html.length);
        contentDiv.innerHTML = html;
        document.getElementById('estimated_fare').value = pricing.totalPrice.toFixed(2);
        console.log('Main fare estimate updated successfully');
    }

    // Fare estimation function with DYNAMIC PRICING (for single-zone journeys)
    function updateFareEstimate() {
        // If we have multi-segment pricing cached, use that instead
        if (cachedMultiSegmentPricing && (currentRouteType === 'multi-segment' || currentRouteType === 'direct')) {
            updateMainFareEstimate();
            return;
        }
        
        var pickupLat = parseFloat(document.getElementById('pickup_lat').value);
        var pickupLon = parseFloat(document.getElementById('pickup_lon').value);
        var dropoffLat = parseFloat(document.getElementById('dropoff_lat').value);
        var dropoffLon = parseFloat(document.getElementById('dropoff_lon').value);
        var serviceTypeId = document.getElementById('service_type_id').value;

        var contentDiv = document.getElementById('fare-estimate-content');
        var surgeBadge = document.getElementById('surge-badge');
        var dynamicInfo = document.getElementById('dynamic-pricing-info');
        var surgeDetails = document.getElementById('surge-details');

        if (isNaN(pickupLat) || isNaN(pickupLon) || isNaN(dropoffLat) || isNaN(dropoffLon)) {
            contentDiv.innerHTML = '<p class="text-muted" style="font-size: 0.82rem; margin: 0;">Select pickup and dropoff locations to see fare estimate.</p>';
            surgeBadge.style.display = 'none';
            dynamicInfo.style.display = 'none';
            return;
        }
        
        // Reset multi-segment pricing for single-zone journeys
        currentRouteType = 'single';

        // Use route distance if available from OSRM, otherwise calculate straight-line distance
        var distanceKm, durationMin;
        var isRouteDistance = false;

        if (cachedRouteInfo && !cachedRouteInfo.isFallback) {
            // Use actual route distance from OSRM
            distanceKm = parseFloat(cachedRouteInfo.distance);
            durationMin = cachedRouteInfo.duration;
            isRouteDistance = true;
        } else {
            // Fallback: Calculate distance using Haversine formula (client-side approximation)
            var R = 6371; // Earth's radius in km
            var dLat = (dropoffLat - pickupLat) * Math.PI / 180;
            var dLon = (dropoffLon - pickupLon) * Math.PI / 180;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(pickupLat * Math.PI / 180) * Math.cos(dropoffLat * Math.PI / 180) *
                    Math.sin(dLon/2) * Math.sin(dLon/2);
            var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            distanceKm = R * c * 1.3; // Multiply by 1.3 for road distance estimate
            distanceKm = Math.max(1, distanceKm); // Minimum 1 km
            // Estimate duration (average 30 km/h in urban areas)
            durationMin = Math.max(5, Math.ceil((distanceKm / 30) * 60));
        }

        distanceKm = Math.max(1, distanceKm); // Minimum 1 km

        // Get pricing based on service type (simplified client-side calculation)
        var pricing = {
            '1': { base: 3.00, perKm: 1.20, perMin: 0.20, min: 5.00, name: 'Standard', vehicleMult: 1.0 },
            '2': { base: 8.00, perKm: 2.50, perMin: 0.40, min: 15.00, name: 'Luxury', vehicleMult: 1.8 },
            '3': { base: 5.00, perKm: 1.80, perMin: 0.25, min: 10.00, name: 'Light Cargo', vehicleMult: 1.4 },
            '4': { base: 10.00, perKm: 2.20, perMin: 0.30, min: 20.00, name: 'Heavy Cargo', vehicleMult: 1.5 },
            '5': { base: 4.00, perKm: 1.40, perMin: 0.22, min: 8.00, name: 'Multi-Stop', vehicleMult: 1.1 }
        };

        var p = pricing[serviceTypeId] || pricing['1'];
        var serviceFeeRate = <?php echo OSRH_SERVICE_FEE_RATE; ?>; // Kaspa commission

        // =====================================================
        // DYNAMIC PRICING CALCULATION
        // =====================================================
        
        // 1. Time-based surge (peak hours)
        var now = new Date();
        var dayOfWeek = now.getDay(); // 0=Sunday, 1=Monday, etc.
        var hour = now.getHours();
        var timeSurge = 1.0;
        var timeSurgeReason = '';

        // Morning rush (weekdays 7-9 AM)
        if (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 7 && hour < 9) {
            timeSurge = 1.25;
            timeSurgeReason = 'Morning rush hour';
        }
        // Evening rush (weekdays 5-7 PM)
        else if (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 17 && hour < 19) {
            timeSurge = dayOfWeek === 5 ? 1.35 : 1.30; // Higher on Friday
            timeSurgeReason = 'Evening rush hour';
        }
        // Weekend nights (Friday & Saturday 10 PM - 2 AM)
        else if ((dayOfWeek === 5 && hour >= 22) || (dayOfWeek === 6 && hour < 2)) {
            timeSurge = 1.40;
            timeSurgeReason = 'Friday night demand';
        }
        else if ((dayOfWeek === 6 && hour >= 22) || (dayOfWeek === 0 && hour < 2)) {
            timeSurge = 1.40;
            timeSurgeReason = 'Saturday night demand';
        }

        // 2. Vehicle type multiplier (already in pricing object)
        var vehicleMultiplier = p.vehicleMult;

        // 3. Simulated demand surge (in real app, this comes from server)
        // Using a deterministic pseudo-random based on coordinates for demo
        var demandSeed = (Math.abs(pickupLat * 1000) + Math.abs(pickupLon * 1000)) % 10;
        var demandSurge = 1.0;
        var demandLevel = 'Normal';
        if (demandSeed > 8) {
            demandSurge = 1.35;
            demandLevel = 'High';
        } else if (demandSeed > 6) {
            demandSurge = 1.20;
            demandLevel = 'Moderate';
        } else if (demandSeed > 4) {
            demandSurge = 1.10;
            demandLevel = 'Slight';
        }

        // Combined surge = vehicle * max(time, demand)
        var effectiveSurge = vehicleMultiplier * Math.max(timeSurge, demandSurge);
        effectiveSurge = Math.min(effectiveSurge, 3.0); // Cap at 3x

        // =====================================================
        // FARE CALCULATION
        // =====================================================
        var baseFare = p.base;
        var distanceFare = Math.round(distanceKm * p.perKm * 100) / 100;
        var timeFare = Math.round(durationMin * p.perMin * 100) / 100;
        var subtotal = baseFare + distanceFare + timeFare;
        var subtotalWithSurge = Math.round(subtotal * effectiveSurge * 100) / 100;
        var totalFare = Math.max(subtotalWithSurge, p.min);
        var serviceFee = Math.round(totalFare * serviceFeeRate * 100) / 100;
        var driverEarnings = totalFare - serviceFee;

        // Show/hide surge badge
        var isSurgeActive = effectiveSurge > 1.05;
        if (isSurgeActive) {
            surgeBadge.style.display = 'inline';
            surgeBadge.textContent = effectiveSurge.toFixed(1) + 'x Surge';
            if (effectiveSurge >= 1.5) {
                surgeBadge.style.background = '#dc3545';
                surgeBadge.style.color = '#fff';
            } else if (effectiveSurge >= 1.25) {
                surgeBadge.style.background = '#fd7e14';
                surgeBadge.style.color = '#fff';
            } else {
                surgeBadge.style.background = '#ffc107';
                surgeBadge.style.color = '#333';
            }

            dynamicInfo.style.display = 'block';
            var surgeText = [];
            if (timeSurge > 1.0) surgeText.push(' ' + timeSurgeReason + ' (+' + Math.round((timeSurge - 1) * 100) + '%)');
            if (demandSurge > 1.0) surgeText.push(' ' + demandLevel + ' demand in area (+' + Math.round((demandSurge - 1) * 100) + '%)');
            if (vehicleMultiplier > 1.0) surgeText.push(' ' + p.name + ' service (+' + Math.round((vehicleMultiplier - 1) * 100) + '%)');
            surgeDetails.innerHTML = surgeText.join('<br>');
        } else {
            surgeBadge.style.display = 'none';
            dynamicInfo.style.display = 'none';
        }

        // Build HTML for fare breakdown
        var html = '<table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;">';
        var distanceLabel = isRouteDistance ? 'Route Distance' : 'Distance (est.)';
        html += '<tr><td style="padding: 0.2rem 0;">' + distanceLabel + '</td><td style="text-align: right; padding: 0.2rem 0;">' + distanceKm.toFixed(1) + ' km' + (isRouteDistance ? ' ✓' : '') + '</td></tr>';
        html += '<tr><td style="padding: 0.2rem 0;">Est. Duration</td><td style="text-align: right; padding: 0.2rem 0;">~' + durationMin + ' min</td></tr>';
        html += '<tr style="border-top: 1px solid #dee2e6;"><td style="padding: 0.3rem 0;">Base fare</td><td style="text-align: right; padding: 0.3rem 0;">€' + baseFare.toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding: 0.2rem 0;">Distance (€' + p.perKm.toFixed(2) + '/km)</td><td style="text-align: right; padding: 0.2rem 0;">€' + distanceFare.toFixed(2) + '</td></tr>';
        html += '<tr><td style="padding: 0.2rem 0;">Time (€' + p.perMin.toFixed(2) + '/min)</td><td style="text-align: right; padding: 0.2rem 0;">€' + timeFare.toFixed(2) + '</td></tr>';
        if (isSurgeActive) {
            html += '<tr><td style="padding: 0.2rem 0; color: #dc3545;">Dynamic pricing (' + effectiveSurge.toFixed(2) + 'x)</td><td style="text-align: right; padding: 0.2rem 0; color: #dc3545;">+€' + (subtotalWithSurge - subtotal).toFixed(2) + '</td></tr>';
        }
        html += '<tr style="border-top: 1px solid #dee2e6; font-weight: bold;"><td style="padding: 0.4rem 0; color: #2563eb;">Estimated Total</td><td style="text-align: right; padding: 0.4rem 0; color: #2563eb; font-size: 1rem;">€' + totalFare.toFixed(2) + '</td></tr>';
        html += '</table>';
        if (isRouteDistance) {
            html += '<p style="font-size: 0.72rem; color: #16a34a; margin: 0.5rem 0 0 0;">✓ Using actual route distance for accurate pricing</p>';
        }
        html += '<div style="background: linear-gradient(135deg, #0a2e2a 0%, #134e4a 100%); border-radius: 8px; padding: 0.6rem 0.8rem; margin-top: 0.6rem; display: flex; align-items: center; gap: 0.5rem;">';
        html += '<span style="font-size: 1.1rem;">💎</span>';
        html += '<span style="color: #49EACB; font-size: 0.78rem; font-weight: 600;">NO MIDDLEMAN FEE</span>';
        html += '<span style="color: #a7f3d0; font-size: 0.72rem;">— 100% goes to your driver!</span>';
        html += '</div>';

        contentDiv.innerHTML = html;
        
        // Store the total fare in hidden field for form submission
        document.getElementById('estimated_fare').value = totalFare.toFixed(2);
    }

    // Initial fare estimate and route preview if coordinates are already set
    updateFareEstimate();
    updateRoutePreview();

    // Form validation - ensure route distance is calculated before submission
    document.querySelector('form.js-validate').addEventListener('submit', function(e) {
        var estimatedDistance = document.getElementById('estimated_distance_km').value;
        var pickupLat = document.getElementById('pickup_lat').value;
        var pickupLon = document.getElementById('pickup_lon').value;
        var dropoffLat = document.getElementById('dropoff_lat').value;
        var dropoffLon = document.getElementById('dropoff_lon').value;

        // Check if coordinates are set
        if (!pickupLat || !pickupLon || !dropoffLat || !dropoffLon) {
            e.preventDefault();
            OSRH.warning('Please select both pickup and dropoff locations on the map.', 'Locations Required');
            return false;
        }

        // Check if route distance was calculated
        if (!estimatedDistance || parseFloat(estimatedDistance) <= 0) {
            e.preventDefault();
            OSRH.warning('Please wait a moment for the route distance to be calculated before submitting.\n\nIf this message persists, try clicking on the map again to refresh the route.', 'Route Calculation');
            return false;
        }

        // Additional check: distance should be reasonable (not exactly 1.0 which is fallback)
        var distKm = parseFloat(estimatedDistance);
        if (distKm < 0.1) {
            e.preventDefault();
            OSRH.warning('Route distance seems too short.\n\nPlease select different pickup/dropoff locations or wait for route calculation.', 'Invalid Distance');
            return false;
        }

        return true;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
