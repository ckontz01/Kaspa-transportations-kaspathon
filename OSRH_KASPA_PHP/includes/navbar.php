<?php
/**
 * Navigation bar - included by header.php
 * Note: Do NOT use declare(strict_types=1) here as this is an included file
 */

if (!function_exists('current_user')) {
    require_once __DIR__ . '/roles.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/helpers.php';
}
require_once __DIR__ . '/db.php';

$user      = current_user();
$isLogged  = is_logged_in();
$userName  = $user ? current_user_name() : null;
$role      = $user ? primary_role() : null;

// Check for active trip (for passengers)
$activeTrip = null;
$isOnRideDetailPage = false;

// Check if we're currently on the ride_detail.php page
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($currentScript, 'passenger/ride_detail.php') !== false) {
    $isOnRideDetailPage = true;
}

if ($isLogged && $role === 'passenger') {
    $passenger = $user['passenger'] ?? null;
    if ($passenger && isset($passenger['PassengerID'])) {
        $passengerId = (int)$passenger['PassengerID'];
        // Query for active trips - only assigned, dispatched, or in_progress
        // Completed and cancelled trips should NOT show here
        try {
            $stmt = db_call_procedure('dbo.spGetPassengerActiveTrip', [$passengerId]);
            if ($stmt !== false) {
                $activeTrip = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                // Double-check that we only show active trips (not completed/cancelled)
                if ($activeTrip) {
                    $tripStatus = strtolower($activeTrip['Status'] ?? '');
                    if (!in_array($tripStatus, ['assigned', 'dispatched', 'in_progress'])) {
                        $activeTrip = null; // Don't show completed/cancelled trips
                    } elseif (!empty($activeTrip['IsRealDriverTrip'])) {
                        // Hide driver-ride indicator in navbar per requirement
                        $activeTrip = null;
                    }
                }
            }
        } catch (Exception $e) {
            // Silently fail - navbar should still work even if procedure doesn't exist
            $activeTrip = null;
        }
    }
}
?>

<nav class="navbar">
    <div class="navbar-inner">
        <a href="<?php echo e(url('index.php')); ?>" class="navbar-brand">
            <img src="<?php echo e(url('assets/img/Kaspa-logo.svg.png')); ?>" alt="Kaspa Transportations logo" class="navbar-logo">
            <span class="navbar-title">Kaspa</span>
        </a>

        <button class="navbar-toggle" type="button" aria-label="Toggle navigation">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <div class="navbar-links">
            <ul class="navbar-menu">
                <?php if (!$isLogged): ?>
                    <li><a href="<?php echo e(url('index.php')); ?>">Home</a></li>
                    <li><a href="<?php echo e(url('login.php')); ?>">Login</a></li>
                    <li><a href="<?php echo e(url('register_passenger.php')); ?>">Register Passenger</a></li>
                    <li><a href="<?php echo e(url('register_driver.php')); ?>">Register Driver</a></li>
                <?php else: ?>
                    <?php if ($role === 'passenger'): ?>
                        <li><a href="<?php echo e(url('passenger/dashboard.php')); ?>">Dashboard</a></li>
                        <li><a href="<?php echo e(url('passenger/request_ride.php')); ?>">Driver Ride</a></li>
                        <li><a href="<?php echo e(url('passenger/request_autonomous_ride.php')); ?>">Autonomous Ride</a></li>
                        <li><a href="<?php echo e(url('carshare/request_vehicle.php')); ?>">Carshare Ride</a></li>
                        <li><a href="<?php echo e(url('passenger/rides_history.php')); ?>">History</a></li>
                        <li><a href="<?php echo e(url('passenger/payments.php')); ?>">Payments</a></li>
                        <li><a href="<?php echo e(url('passenger/messages.php')); ?>">Messages</a></li>
                        <li><a href="<?php echo e(url('passenger/settings.php')); ?>">Settings</a></li>
                        <li><a href="<?php echo e(url('passenger/gdpr_request.php')); ?>">Privacy</a></li>
                    <?php elseif ($role === 'driver'): ?>
                        <li><a href="<?php echo e(url('driver/dashboard.php')); ?>">Dashboard</a></li>
                        <li><a href="<?php echo e(url('driver/trips_assigned.php')); ?>">Trips</a></li>
                        <li><a href="<?php echo e(url('driver/vehicles.php')); ?>">Vehicles</a></li>
                        <li><a href="<?php echo e(url('driver/earnings.php')); ?>">Earnings</a></li>
                        <li><a href="<?php echo e(url('driver/messages.php')); ?>">Messages</a></li>
                        <li><a href="<?php echo e(url('driver/settings.php')); ?>">Settings</a></li>
                    <?php elseif ($role === 'operator' || $role === 'admin'): ?>
                        <li><a href="<?php echo e(url('operator/dashboard.php')); ?>">Dashboard</a></li>
                        <li><a href="<?php echo e(url('operator/drivers_hub.php')); ?>">Drivers Hub</a></li>
                        <li><a href="<?php echo e(url('operator/autonomous_hub.php')); ?>">Autonomous Hub</a></li>
                        <li><a href="<?php echo e(url('operator/carshare_hub.php')); ?>">CarShare Hub</a></li>
                        <li><a href="<?php echo e(url('operator/operations_hub.php')); ?>">Operations Hub</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>

            <div class="navbar-right">
                <?php if ($isLogged): ?>
                    <a href="<?php echo e(url('logout.php')); ?>" class="btn btn-outline btn-small">Logout</a>
                    <a href="<?php echo e(url('profile.php')); ?>" class="navbar-username">
                        <span class="navbar-username-icon">👤</span>
                        <?php echo e($userName ?? ''); ?>
                    </a>
                <?php else: ?>
                    <a href="<?php echo e(url('login.php')); ?>" class="btn btn-primary btn-small">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
