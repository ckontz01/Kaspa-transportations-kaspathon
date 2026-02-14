<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    header('Location: ' . url('error.php?code=403'));
    exit;
}

$passengerId = (int)$passengerRow['PassengerID'];
$userName = current_user_name() ?? 'Passenger';

// Get passenger dashboard stats using stored procedure
$stats = [
    'DriverTrips' => 0,
    'ActiveDriverTrips' => 0,
    'AVRides' => 0,
    'ActiveAVRides' => 0
];

$stmt = @db_call_procedure('dbo.spGetPassengerDashboardStats', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $row = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $stats['DriverTrips'] = (int)($row['DriverTrips'] ?? 0);
        $stats['ActiveDriverTrips'] = (int)($row['ActiveDriverTrips'] ?? 0);
        $stats['AVRides'] = (int)($row['AVRides'] ?? 0);
        $stats['ActiveAVRides'] = (int)($row['ActiveAVRides'] ?? 0);
    }
    @sqlsrv_free_stmt($stmt);
}

// Check for active driver trip
$activeTrip = null;
$stmt = @db_call_procedure('dbo.spGetPassengerActiveTrip', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeTrip = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmt);
}

// Check for active autonomous ride
$activeAVRide = null;
$stmt = @db_call_procedure('dbo.spGetPassengerActiveAutonomousRide', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeAVRide = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    @sqlsrv_free_stmt($stmt);
}

// Check if passenger has ANY active ride (prevents requesting new rides)
$hasAnyActiveRide = false;
$stmt = @db_call_procedure('dbo.spCheckPassengerHasAnyActiveRide', [$passengerId]);
if ($stmt !== false && $stmt !== null) {
    $activeRideCheck = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($activeRideCheck && isset($activeRideCheck['HasActiveRide'])) {
        $hasAnyActiveRide = (bool)$activeRideCheck['HasActiveRide'];
    }
    @sqlsrv_free_stmt($stmt);
}

// Get CarShare stats for passenger
$carshareStats = ['totalRentals' => 0, 'activeRental' => null, 'activeBooking' => null, 'isRegistered' => false];
$carshareCustomer = null;

// Check if passenger is a carshare customer
$stmt = @db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if ($stmt) {
    $carshareCustomer = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    @sqlsrv_free_stmt($stmt);
}

if ($carshareCustomer) {
    $carshareStats['isRegistered'] = true;
    $customerId = (int)$carshareCustomer['CustomerID'];
    
    // Check for active rental
    $stmt = @db_query('EXEC dbo.CarshareGetActiveRental ?', [$customerId]);
    if ($stmt) {
        $carshareStats['activeRental'] = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
        @sqlsrv_free_stmt($stmt);
    }
    
    // Check for active booking
    $stmt = @db_query('EXEC dbo.CarshareGetActiveBooking ?', [$customerId]);
    if ($stmt) {
        $carshareStats['activeBooking'] = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
        @sqlsrv_free_stmt($stmt);
    }
    
    // Get total completed rentals
    $stmt = @db_query("SELECT COUNT(*) as cnt FROM dbo.CarshareRental WHERE CustomerID = ? AND Status = 'completed'", [$customerId]);
    if ($stmt) {
        $row = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) $carshareStats['totalRentals'] = (int)($row['cnt'] ?? 0);
        @sqlsrv_free_stmt($stmt);
    }
}

$totalTrips = $stats['DriverTrips'] + $stats['AVRides'] + $carshareStats['totalRentals'];
$totalActive = $stats['ActiveDriverTrips'] + $stats['ActiveAVRides'] + ($carshareStats['activeRental'] ? 1 : 0);

// Get unread messages count
$unreadMessagesCount = 0;
$stmt = @db_call_procedure('dbo.spGetUnreadMessageCount', [current_user_id()]);
if ($stmt !== false && $stmt !== null) {
    $row = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $unreadMessagesCount = (int)($row['UnreadCount'] ?? 0);
    }
    @sqlsrv_free_stmt($stmt);
}

$pageTitle = 'Passenger Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.dashboard-container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.dashboard-header {
    margin-bottom: 2rem;
}

.dashboard-header h1 {
    font-size: 1.75rem;
    margin: 0 0 0.5rem 0;
}

.dashboard-header p {
    color: var(--text-muted, #6b7280);
    font-size: 0.95rem;
    margin: 0;
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--color-surface, #1f2937);
    border: 1px solid var(--border-color, #374151);
    border-radius: 10px;
    padding: 1.25rem;
    text-align: center;
}

.stat-card .stat-icon {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

.stat-card .stat-label {
    font-size: 0.75rem;
    color: var(--text-muted, #9ca3af);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 0.25rem;
}

.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.section-card {
    background: var(--color-surface, #1f2937);
    border: 1px solid var(--border-color, #374151);
    border-radius: 12px;
    overflow: hidden;
    width: 100%;
}

.section-card.full-width {
    grid-column: span 2;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #374151);
    background: var(--color-surface, #1f2937);
}

.section-header h2 {
    font-size: 1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.section-body {
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    align-items: stretch;
}

.quick-stats {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 2rem;
    margin-bottom: 1.2rem;
    width: 100%;
}

.quick-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.quick-stat .value {
    font-size: 1.5rem;
    font-weight: 700;
}

.quick-stat .label {
    font-size: 0.75rem;
    color: var(--text-muted, #9ca3af);
    text-transform: uppercase;
}

.alerts-section {
    margin-bottom: 1.5rem;
}

.alerts-section h3 {
    font-size: 1rem;
    margin-bottom: 0.75rem;
}

.alert-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--color-surface, #1f2937);
    border-radius: 8px;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.alert-item.warning {
    background: rgba(251, 191, 36, 0.15);
    border-left: 3px solid #fbbf24;
    color: #fcd34d;
}

.alert-item.info {
    background: rgba(99, 102, 241, 0.15);
    border-left: 3px solid #6366f1;
    color: #a5b4fc;
}

.alert-item.success {
    background: rgba(16, 185, 129, 0.15);
    border-left: 3px solid #10b981;
    color: #6ee7b7;
}

.alert-item .alert-icon {
    font-size: 1.25rem;
}

.active-ride-card {
    padding: 1rem;
    background: rgba(99, 102, 241, 0.1);
    border: 1px solid var(--color-primary, #6366f1);
    border-radius: 8px;
    margin-bottom: 1rem;
}

.active-ride-card.av {
    background: rgba(16, 185, 129, 0.1);
    border-color: #10b981;
}

.active-ride-card.carshare {
    background: rgba(6, 182, 212, 0.1);
    border-color: #06b6d4;
}

.active-ride-card h4 {
    margin: 0 0 0.5rem 0;
    font-size: 0.9rem;
}

.active-ride-card p {
    margin: 0.25rem 0;
    font-size: 0.85rem;
    color: var(--text-muted, #9ca3af);
}

.link-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

.link-list a {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1rem;
    text-decoration: none;
    color: inherit;
    background: var(--color-bg, #111827);
    border: 1px solid var(--border-color, #374151);
    border-radius: 8px;
    transition: all 0.2s;
    font-size: 0.9rem;
}

.link-list a:hover {
    border-color: var(--color-primary, #6366f1);
    color: var(--color-primary, #6366f1);
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted, #9ca3af);
}

.action-btn {
    display: block;
    padding: 0.875rem 1rem;
    margin-bottom: 0.75rem;
    background: var(--color-primary, #6366f1);
    color: white;
    text-decoration: none;
    border-radius: 8px;
    text-align: center;
    font-weight: 500;
    transition: opacity 0.2s;
}

.action-btn:hover {
    opacity: 0.9;
}

.action-btn.secondary {
    background: var(--color-surface, #374151);
    border: 1px solid var(--border-color, #4b5563);
}

.action-btn.success {
    background: #10b981;
}

.action-btn.cyan {
    background: #06b6d4;
}

.action-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
    background: var(--color-surface, #374151);
    border: 1px solid var(--border-color, #4b5563);
}

.active-ride-warning {
    background: rgba(251, 191, 36, 0.1);
    border: 1px solid #fbbf24;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: #fbbf24;
    text-align: center;
}

@media (max-width: 768px) {
    .section-grid {
        grid-template-columns: 1fr !important;
        gap: 1.25rem;
    }

    .section-card.full-width {
        grid-column: span 1;
    }

    .link-list {
        grid-template-columns: 1fr !important;
    }

    .quick-stats {
        gap: 0.75rem;
    }

    .action-btn {
        font-size: 0.88rem;
        padding: 0.75rem;
    }

    .alert-item {
        flex-wrap: wrap;
    }

    .alert-item .btn {
        margin-left: 0 !important;
        width: 100%;
    }
}
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h1>👋 Welcome, <?php echo e($userName); ?></h1>
        <p>Request rides, track your trips, and manage your account.</p>
    </div>

    <!-- Alerts Section -->
    <div class="alerts-section">
        <h3>📢 Alerts</h3>
        
        <?php if ($hasAnyActiveRide || $carshareStats['activeRental'] || $carshareStats['activeBooking']): ?>
        <div class="alert-item warning">
            <span class="alert-icon">🚗</span>
            <span>You have an <strong>active ride</strong> in progress.</span>
            <?php if ($activeTrip && !empty($activeTrip['TripID'])): ?>
            <a href="<?php echo e(url('passenger/ride_detail.php?trip_id=' . $activeTrip['TripID'])); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Trip</a>
            <?php elseif ($activeAVRide && !empty($activeAVRide['AutonomousRideID'])): ?>
            <a href="<?php echo e(url('passenger/autonomous_ride_detail.php?ride_id=' . $activeAVRide['AutonomousRideID'])); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Ride</a>
            <?php elseif ($carshareStats['activeRental'] || $carshareStats['activeBooking']): ?>
            <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View CarShare</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No active rides. Ready to book your next trip!</span>
        </div>
        <?php endif; ?>
        
        <?php if ($unreadMessagesCount > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">💬</span>
            <span>You have <strong><?php echo e($unreadMessagesCount); ?></strong> unread message<?php echo $unreadMessagesCount !== 1 ? 's' : ''; ?>.</span>
            <a href="<?php echo e(url('passenger/messages.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Messages</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No unread messages.</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Active Driver Trip Section -->
    <?php if ($activeTrip && !empty($activeTrip['TripID'])): ?>
    <div class="section-card full-width" style="margin-bottom: 2rem;">
        <div class="section-header">
            <h2>👤 Active Driver Trip</h2>
            <a href="<?php echo e(url('passenger/ride_detail.php?trip_id=' . $activeTrip['TripID'])); ?>" class="btn btn-ghost btn-small">View Details →</a>
        </div>
        <div class="section-body">
            <div class="active-ride-card">
                <h4>👤 Driver Trip in Progress</h4>
                <p>Status: <?php echo e(ucfirst($activeTrip['Status'] ?? 'Active')); ?></p>
                <?php if (!empty($activeTrip['DriverName'])): ?>
                <p>Driver: <?php echo e($activeTrip['DriverName']); ?></p>
                <?php endif; ?>
                <a href="<?php echo e(url('passenger/ride_detail.php?trip_id=' . $activeTrip['TripID'])); ?>" class="action-btn" style="margin-top: 0.75rem; margin-bottom: 0;">
                    View Trip
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active Autonomous Ride Section -->
    <?php if ($activeAVRide && !empty($activeAVRide['AutonomousRideID'])): ?>
    <div class="section-card full-width" style="margin-bottom: 2rem;">
        <div class="section-header">
            <h2>🤖 Active Autonomous Ride</h2>
            <a href="<?php echo e(url('passenger/autonomous_ride_detail.php?ride_id=' . $activeAVRide['AutonomousRideID'])); ?>" class="btn btn-ghost btn-small">View Details →</a>
        </div>
        <div class="section-body">
            <div class="active-ride-card av">
                <h4>🤖 Autonomous Ride in Progress</h4>
                <p>Status: <?php echo e(ucfirst($activeAVRide['Status'] ?? 'Active')); ?></p>
                <?php if (!empty($activeAVRide['Make']) || !empty($activeAVRide['Model'])): ?>
                <p>Vehicle: <?php echo e(trim(($activeAVRide['Make'] ?? '') . ' ' . ($activeAVRide['Model'] ?? ''))); ?></p>
                <?php endif; ?>
                <a href="<?php echo e(url('passenger/autonomous_ride_detail.php?ride_id=' . $activeAVRide['AutonomousRideID'])); ?>" class="action-btn success" style="margin-top: 0.75rem; margin-bottom: 0;">
                    View Ride
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active CarShare Section -->
    <?php if ($carshareStats['activeRental'] || $carshareStats['activeBooking']): ?>
    <div class="section-card full-width" style="margin-bottom: 2rem;">
        <div class="section-header">
            <h2>🚙 Active CarShare</h2>
            <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="btn btn-ghost btn-small">Manage →</a>
        </div>
        <div class="section-body">
            <?php if ($carshareStats['activeRental']): ?>
            <div class="active-ride-card carshare">
                <h4>🚙 CarShare Rental in Progress</h4>
                <p>Vehicle: <?php echo e(($carshareStats['activeRental']['Make'] ?? '') . ' ' . ($carshareStats['activeRental']['Model'] ?? '')); ?></p>
                <p>Plate: <?php echo e($carshareStats['activeRental']['PlateNumber'] ?? 'N/A'); ?></p>
                <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="action-btn cyan" style="margin-top: 0.75rem; margin-bottom: 0;">
                    View Rental
                </a>
            </div>
            <?php elseif ($carshareStats['activeBooking']): ?>
            <div class="active-ride-card carshare">
                <h4>📅 CarShare Reservation Active</h4>
                <p>Vehicle: <?php echo e(($carshareStats['activeBooking']['Make'] ?? '') . ' ' . ($carshareStats['activeBooking']['Model'] ?? '')); ?></p>
                <p>Zone: <?php echo e($carshareStats['activeBooking']['ZoneName'] ?? 'N/A'); ?></p>
                <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="action-btn cyan" style="margin-top: 0.75rem; margin-bottom: 0;">
                    Unlock Vehicle
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-icon">🚗</div>
            <div class="stat-value" style="color: #6366f1;"><?php echo $totalActive; ?></div>
            <div class="stat-label">Active Trips</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👤</div>
            <div class="stat-value"><?php echo $stats['ActiveDriverTrips']; ?></div>
            <div class="stat-label">Driver Trips</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🤖</div>
            <div class="stat-value" style="color: #10b981;"><?php echo $stats['ActiveAVRides']; ?></div>
            <div class="stat-label">AV Trips</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚙</div>
            <div class="stat-value" style="color: #06b6d4;"><?php echo $carshareStats['activeRental'] ? 1 : 0; ?></div>
            <div class="stat-label">CarShare</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?php echo $totalTrips; ?></div>
            <div class="stat-label">Total Trips</div>
        </div>
    </div>

    <!-- Section Cards - Row 1: Trip Summary & Book a Ride -->
    <div class="section-grid">
        
        <!-- Trip Summary Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>📊 Trip Summary</h2>
                <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost btn-small">View All →</a>
            </div>
            <div class="section-body">
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="value"><?php echo $stats['DriverTrips']; ?></div>
                        <div class="label">Driver Trips</div>
                    </div>
                    <div class="quick-stat">
                        <div class="value" style="color: #10b981;"><?php echo $stats['AVRides']; ?></div>
                        <div class="label">AV Trips</div>
                    </div>
                    <div class="quick-stat">
                        <div class="value" style="color: #06b6d4;"><?php echo $carshareStats['totalRentals']; ?></div>
                        <div class="label">CarShare</div>
                    </div>
                </div>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color, #374151); text-align: center;">
                    <div style="font-size: 2rem; font-weight: 700;"><?php echo $totalTrips; ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted, #9ca3af);">Total Completed Trips</div>
                </div>
            </div>
        </div>

        <!-- Book a Ride Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>🚗 Book a Ride</h2>
            </div>
            <div class="section-body">
                <?php if ($hasAnyActiveRide || $carshareStats['activeRental'] || $carshareStats['activeBooking']): ?>
                <div class="active-ride-warning">
                    You have an active ride. Complete or cancel it before requesting a new one.
                </div>
                <span class="action-btn disabled">👤 Request Driver Ride</span>
                <span class="action-btn disabled">🤖 Request Autonomous Ride</span>
                <span class="action-btn disabled">🚙 Rent a Car (CarShare)</span>
                <?php else: ?>
                <a href="<?php echo e(url('passenger/request_ride.php')); ?>" class="action-btn">
                    👤 Request Driver Ride
                </a>
                <a href="<?php echo e(url('passenger/request_autonomous_ride.php')); ?>" class="action-btn success">
                    🤖 Request Autonomous Ride
                </a>
                <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="action-btn cyan">
                    🚙 Rent a Car (CarShare)
                </a>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Account Section - Full Width -->
    <div class="section-card full-width" style="margin-top: 2rem;">
        <div class="section-header">
            <h2>👤 Account</h2>
        </div>
        <div class="section-body">
            <div class="link-list">
                <a href="<?php echo e(url('passenger/rides_history.php')); ?>">📜 History</a>
                <a href="<?php echo e(url('passenger/payments.php')); ?>">💳 Payments</a>
                <a href="<?php echo e(url('passenger/messages.php')); ?>">💬 Messages<?php if ($unreadMessagesCount > 0): ?> <span style="background: #ef4444; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.7rem; margin-left: auto;"><?php echo $unreadMessagesCount; ?></span><?php endif; ?></a>
                <a href="<?php echo e(url('passenger/gdpr_request.php')); ?>">🔒 Data & Privacy</a>
                <a href="<?php echo e(url('profile.php')); ?>">✏️ Edit Profile</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
