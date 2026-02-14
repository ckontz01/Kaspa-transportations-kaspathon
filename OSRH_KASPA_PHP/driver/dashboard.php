<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

$user      = current_user();
$userName  = current_user_name() ?? 'Driver';
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}

$driverId           = (int)$driverRow['DriverID'];
$driverType         = $driverRow['DriverType'] ?? null;
$isAvailable        = $driverRow['IsAvailable'] ?? null;
$verificationStatus = $driverRow['VerificationStatus'] ?? null;

// Get dashboard statistics using stored procedure
$stats = [
    'ActiveTripsCount' => 0,
    'CompletedTripsCount' => 0,
    'PendingRequestsCount' => 0,
    'TodayEarnings' => 0,
    'WeekEarnings' => 0,
    'TotalEarnings' => 0,
];
$stmt = db_call_procedure('dbo.spGetDriverDashboardStats', [$driverId]);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $stats['ActiveTripsCount'] = (int)($row['ActiveTripsCount'] ?? 0);
        $stats['CompletedTripsCount'] = (int)($row['CompletedTripsCount'] ?? 0);
        $stats['PendingRequestsCount'] = (int)($row['PendingRequestsCount'] ?? 0);
        $stats['TodayEarnings'] = (float)($row['TodayEarnings'] ?? 0);
        $stats['WeekEarnings'] = (float)($row['WeekEarnings'] ?? 0);
        $stats['TotalEarnings'] = (float)($row['TotalEarnings'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Check if driver has active trip
$hasActiveTrip = false;
$stmt = db_call_procedure('dbo.spCheckDriverHasActiveTrip', [$driverId]);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $hasActiveTrip = (bool)($row['HasActiveTrip'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Get active trip details if exists
$activeTrip = null;
if ($hasActiveTrip) {
    $stmt = db_call_procedure('dbo.spGetDriverActiveTrip', [$driverId]);
    if ($stmt !== false) {
        $activeTrip = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
    }
}

// Get unread messages count
$unreadMessagesCount = 0;
$stmt = db_call_procedure('dbo.spGetUnreadMessageCount', [current_user_id()]);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $unreadMessagesCount = (int)($row['UnreadCount'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Check if driver has uploaded documents
$missingDocuments = [];
$stmtDocs = db_query(
    "SELECT DocType, StorageUrl, Status FROM dbo.DriverDocument WHERE DriverID = ?",
    [$driverId]
);
$uploadedDocs = [];
if ($stmtDocs) {
    while ($row = sqlsrv_fetch_array($stmtDocs, SQLSRV_FETCH_ASSOC)) {
        $uploadedDocs[$row['DocType']] = $row;
    }
    sqlsrv_free_stmt($stmtDocs);
}
if (!isset($uploadedDocs['id_card']) || empty($uploadedDocs['id_card']['StorageUrl'])) {
    $missingDocuments[] = 'ID Card';
}
if (!isset($uploadedDocs['license']) || empty($uploadedDocs['license']['StorageUrl'])) {
    $missingDocuments[] = "Driver's License";
}

$pageTitle = 'Driver Dashboard';
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
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
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
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 0.25rem;
}

.section-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 1.5rem;
}

.section-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    background: var(--color-surface, #f8fafc);
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
}

.quick-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.quick-stat {
    text-align: center;
    padding: 0.75rem;
    background: var(--color-surface, #f8fafc);
    border-radius: 8px;
}

.quick-stat .value {
    font-size: 1.5rem;
    font-weight: 700;
}

.quick-stat .label {
    font-size: 0.7rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
}

.section-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.section-actions .btn {
    flex: 1;
    min-width: 100px;
    text-align: center;
}

.alerts-section {
    margin-bottom: 1.5rem;
}

.alert-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--color-surface, #f8fafc);
    border-radius: 8px;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
    color: #1f2937;
}

.alert-item.warning {
    background: #fef3c7;
    border-left: 3px solid #f59e0b;
    color: #92400e;
}

.alert-item.info {
    background: #dbeafe;
    border-left: 3px solid #3b82f6;
    color: #1e3a8a;
}

.alert-item.success {
    background: #dcfce7;
    border-left: 3px solid #22c55e;
    color: #166534;
}

.alert-item .alert-icon {
    font-size: 1.25rem;
}

.active-trip-card {
    padding: 1rem;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid #3b82f6;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.active-trip-card h4 {
    margin: 0 0 0.5rem 0;
    font-size: 0.95rem;
    color: #1e40af;
}

.active-trip-card p {
    margin: 0.25rem 0;
    font-size: 0.85rem;
    color: var(--text-muted, #6b7280);
}

.active-trip-warning {
    background: rgba(251, 191, 36, 0.1);
    border: 1px solid #fbbf24;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: #92400e;
    text-align: center;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.available {
    background: #dcfce7;
    color: #166534;
}

.status-badge.unavailable {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.approved {
    background: #dcfce7;
    color: #166534;
}

@media (max-width: 768px) {
    .section-grid {
        grid-template-columns: 1fr !important;
        gap: 1.25rem;
    }

    .section-actions {
        flex-direction: column;
    }

    .section-actions .btn {
        min-width: auto;
        width: 100%;
    }

    .alert-item {
        flex-wrap: wrap;
    }

    .alert-item .btn {
        margin-left: 0 !important;
        width: 100%;
    }

    .quick-stats {
        gap: 0.6rem;
    }
}
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h1>👋 Welcome, <?php echo e($userName); ?></h1>
        <p>Manage your trips, vehicles, and earnings from this dashboard.</p>
    </div>

    <!-- Alerts Section -->
    <div class="alerts-section">
        <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">📢 Alerts</h3>
        
        <?php if ($hasActiveTrip && $activeTrip): ?>
        <div class="alert-item warning">
            <span class="alert-icon">🚗</span>
            <span>You have an <strong>active trip</strong> in progress.</span>
            <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . $activeTrip['TripID'])); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Trip</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No active trips. You're available for new requests.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($stats['PendingRequestsCount'] > 0 && !$hasActiveTrip): ?>
        <div class="alert-item <?php echo $stats['PendingRequestsCount'] > 3 ? 'warning' : 'info'; ?>">
            <span class="alert-icon">📋</span>
            <span><strong><?php echo e($stats['PendingRequestsCount']); ?></strong> ride request<?php echo $stats['PendingRequestsCount'] !== 1 ? 's' : ''; ?> available to accept.</span>
            <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Requests</a>
        </div>
        <?php elseif ($hasActiveTrip): ?>
        <div class="alert-item info">
            <span class="alert-icon">⏳</span>
            <span>Complete your current trip to accept new requests.</span>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">📋</span>
            <span>No pending ride requests at the moment.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($unreadMessagesCount > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">💬</span>
            <span>You have <strong><?php echo e($unreadMessagesCount); ?></strong> unread message<?php echo $unreadMessagesCount !== 1 ? 's' : ''; ?>.</span>
            <a href="<?php echo e(url('driver/messages.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Messages</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No unread messages.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($verificationStatus === 'pending'): ?>
        <div class="alert-item warning">
            <span class="alert-icon">⏳</span>
            <span>Your driver verification is <strong>pending</strong>. You cannot accept trips until approved.</span>
        </div>
        <?php elseif ($verificationStatus === 'rejected'): ?>
        <div class="alert-item warning" style="background: #fee2e2; border-color: #ef4444; color: #991b1b;">
            <span class="alert-icon">❌</span>
            <span>Your driver verification was <strong>rejected</strong>. Please contact support.</span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($missingDocuments)): ?>
        <div class="alert-item warning">
            <span class="alert-icon">📄</span>
            <span>Missing documents: <strong><?php echo e(implode(', ', $missingDocuments)); ?></strong>. Please upload them for verification.</span>
            <a href="<?php echo e(url('driver/upload_documents.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">Upload →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Stats Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-icon">🚗</div>
            <div class="stat-value" style="color: #3b82f6;"><?php echo e($stats['ActiveTripsCount']); ?></div>
            <div class="stat-label">Active Trips</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value" style="color: #22c55e;"><?php echo e($stats['CompletedTripsCount']); ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value" style="color: #f59e0b;">€<?php echo number_format($stats['TodayEarnings'], 0); ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value">€<?php echo number_format($stats['WeekEarnings'], 0); ?></div>
            <div class="stat-label">This Week</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-value" style="color: #8b5cf6;">€<?php echo number_format($stats['TotalEarnings'], 0); ?></div>
            <div class="stat-label">Total Earned</div>
        </div>
    </div>

    <!-- Section Cards -->
    <div class="section-grid">
        <!-- Active Trip Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>🚗 Active Trip</h2>
                <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-ghost btn-small">All Trips →</a>
            </div>
            <div class="section-body">
                <?php if ($hasActiveTrip && $activeTrip): ?>
                <div class="active-trip-card">
                    <h4>Trip #<?php echo e($activeTrip['TripID']); ?> - <?php echo e(ucfirst($activeTrip['Status'])); ?></h4>
                    <p><strong>Passenger:</strong> <?php echo e($activeTrip['PassengerName']); ?></p>
                    <p><strong>Pickup:</strong> <?php echo e($activeTrip['PickupAddress'] ?? 'N/A'); ?></p>
                    <p><strong>Dropoff:</strong> <?php echo e($activeTrip['DropoffAddress'] ?? 'N/A'); ?></p>
                    <?php if (!empty($activeTrip['EstimatedFare'])): ?>
                    <p><strong>Est. Fare:</strong> €<?php echo number_format((float)$activeTrip['EstimatedFare'], 2); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo e(url('driver/trip_detail.php?trip_id=' . $activeTrip['TripID'])); ?>" class="btn btn-primary btn-small" style="margin-top: 0.75rem; width: 100%;">
                        View Trip Details
                    </a>
                </div>
                <div class="active-trip-warning">
                    ⚠️ Complete this trip before accepting new requests.
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: var(--text-muted, #6b7280);">
                    <p style="font-size: 2rem; margin-bottom: 0.5rem;">✅</p>
                    <p>No active trips at the moment.</p>
                    <?php if ($stats['PendingRequestsCount'] > 0 && $verificationStatus === 'approved'): ?>
                    <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-primary" style="margin-top: 0.75rem;">
                        View Available Requests (<?php echo e($stats['PendingRequestsCount']); ?>)
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Driver Status Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>👤 Driver Status</h2>
                <a href="<?php echo e(url('driver/settings.php')); ?>" class="btn btn-ghost btn-small">Settings →</a>
            </div>
            <div class="section-body">
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="label">Driver Type</div>
                        <div class="value" style="font-size: 1rem;"><?php echo e(ucfirst($driverType ?? '—')); ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="label">Verification</div>
                        <div class="value" style="font-size: 1rem;">
                            <span class="status-badge <?php echo e($verificationStatus ?? 'pending'); ?>">
                                <?php echo e(ucfirst($verificationStatus ?? '—')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="label">Availability</div>
                        <div class="value" style="font-size: 1rem;">
                            <?php if ($isAvailable === null): ?>
                                <span class="status-badge pending">Unknown</span>
                            <?php else: ?>
                                <span class="status-badge <?php echo $isAvailable ? 'available' : 'unavailable'; ?>">
                                    <?php echo $isAvailable ? 'Available' : 'Unavailable'; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="label">Active Trip</div>
                        <div class="value" style="font-size: 1rem;">
                            <span class="status-badge <?php echo $hasActiveTrip ? 'pending' : 'available'; ?>">
                                <?php echo $hasActiveTrip ? 'On Trip' : 'Free'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>⚡ Quick Actions</h2>
            </div>
            <div class="section-body">
                <div class="section-actions" style="flex-direction: column;">
                    <a href="<?php echo e(url('driver/trips_assigned.php')); ?>" class="btn btn-primary btn-small" style="justify-content: flex-start; text-align: left;">
                        🚗 View Trips & Requests
                    </a>
                    <a href="<?php echo e(url('driver/vehicles.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        🚘 Manage Vehicles
                    </a>
                    <a href="<?php echo e(url('driver/earnings.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        💰 View Earnings
                    </a>
                    <a href="<?php echo e(url('driver/messages.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        💬 Messages <?php if ($unreadMessagesCount > 0): ?><span style="background: #ef4444; color: white; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem; margin-left: 0.5rem;"><?php echo $unreadMessagesCount; ?></span><?php endif; ?>
                    </a>
                    <a href="<?php echo e(url('driver/upload_documents.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        📄 Upload Documents <?php if (!empty($missingDocuments)): ?><span style="background: #f59e0b; color: white; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.7rem; margin-left: 0.5rem;"><?php echo count($missingDocuments); ?></span><?php endif; ?>
                    </a>
                    <a href="<?php echo e(url('driver/settings.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        ⚙️ Settings
                    </a>
                    <a href="<?php echo e(url('profile.php')); ?>" class="btn btn-outline btn-small" style="justify-content: flex-start; text-align: left;">
                        👤 Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Earnings Overview Section -->
        <div class="section-card">
            <div class="section-header">
                <h2>💰 Earnings Overview</h2>
                <a href="<?php echo e(url('driver/earnings.php')); ?>" class="btn btn-ghost btn-small">Details →</a>
            </div>
            <div class="section-body">
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="value" style="color: #22c55e;">€<?php echo number_format($stats['TodayEarnings'], 2); ?></div>
                        <div class="label">Today</div>
                    </div>
                    <div class="quick-stat">
                        <div class="value" style="color: #3b82f6;">€<?php echo number_format($stats['WeekEarnings'], 2); ?></div>
                        <div class="label">This Week</div>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(139, 92, 246, 0.1); border-radius: 8px; margin-top: 0.5rem;">
                    <div style="font-size: 0.75rem; color: var(--text-muted, #6b7280); text-transform: uppercase;">Total Earnings</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: #8b5cf6;">€<?php echo number_format($stats['TotalEarnings'], 2); ?></div>
                </div>
                <div style="margin-top: 1rem; font-size: 0.8rem; color: var(--text-muted, #6b7280); text-align: center;">
                    <?php echo e($stats['CompletedTripsCount']); ?> trips completed
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
