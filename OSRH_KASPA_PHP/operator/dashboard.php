<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('operator');

$user        = current_user();
$operatorRow = $user['operator'] ?? null;

if (!$operatorRow || !isset($operatorRow['OperatorID'])) {
    redirect('error.php?code=403');
}

// Get dashboard statistics using stored procedure
$stats = null;
$stmt = db_call_procedure('spOperatorGetDashboardStats', []);
if ($stmt !== false) {
    $stats = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

if (!$stats) {
    $stats = [
        'OpenRequestsCount' => 0,
        'ActiveTripsCount' => 0,
        'AvailableDriversCount' => 0,
        'PendingGdprCount' => 0,
    ];
}

// Get AV stats using stored procedure
$avStats = ['total' => 0, 'available' => 0, 'busy' => 0, 'activeRides' => 0];
$stmt = db_call_procedure('dbo.spGetAllAutonomousVehicles', []);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $avStats['total']++;
        $status = strtolower($row['Status'] ?? 'offline');
        if ($status === 'available') $avStats['available']++;
        if ($status === 'busy') $avStats['busy']++;
    }
    sqlsrv_free_stmt($stmt);
}

// Get active AV rides using stored procedure
$stmt = db_call_procedure('dbo.spGetActiveAVRidesCount', []);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $avStats['activeRides'] = (int)($row['ActiveRides'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Get total drivers using stored procedure
$totalDrivers = 0;
$stmt = db_call_procedure('dbo.spGetActiveDriversCount', []);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $totalDrivers = (int)($row['Total'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Get pending drivers count
$pendingDriversCount = 0;
$stmt = db_call_procedure('dbo.spGetDriversWithFilters', [null, 'pending']);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pendingDriversCount++;
    }
    sqlsrv_free_stmt($stmt);
}

// Get pending driver documents count
$pendingDocumentsCount = 0;
$stmt = db_query("SELECT COUNT(*) as cnt FROM dbo.DriverDocument WHERE Status = 'pending'");
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $pendingDocumentsCount = (int)$row['cnt'];
    }
    sqlsrv_free_stmt($stmt);
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

// Get CarShare stats
$carshareStats = ['totalVehicles' => 0, 'available' => 0, 'rented' => 0, 'activeRentals' => 0, 'totalZones' => 0, 'pendingCustomers' => 0];

// Count vehicles by status
$stmt = db_query("SELECT Status, COUNT(*) as cnt FROM dbo.CarshareVehicle WHERE IsActive = 1 GROUP BY Status");
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $carshareStats['totalVehicles'] += (int)$row['cnt'];
        $status = strtolower($row['Status'] ?? '');
        if ($status === 'available') $carshareStats['available'] = (int)$row['cnt'];
        if ($status === 'rented') $carshareStats['rented'] = (int)$row['cnt'];
    }
    sqlsrv_free_stmt($stmt);
}

// Count active rentals
$stmt = db_query("SELECT COUNT(*) as cnt FROM dbo.CarshareRental WHERE Status IN ('active', 'in_progress')");
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) $carshareStats['activeRentals'] = (int)($row['cnt'] ?? 0);
    sqlsrv_free_stmt($stmt);
}

// Count zones
$stmt = db_query("SELECT COUNT(*) as cnt FROM dbo.CarshareZone WHERE IsActive = 1");
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) $carshareStats['totalZones'] = (int)($row['cnt'] ?? 0);
    sqlsrv_free_stmt($stmt);
}

// Count pending customer approvals
$stmt = db_query("SELECT COUNT(*) as cnt FROM dbo.CarshareCustomer WHERE VerificationStatus IN ('pending', 'documents_submitted')");
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) $carshareStats['pendingCustomers'] = (int)($row['cnt'] ?? 0);
    sqlsrv_free_stmt($stmt);
}

$pageTitle = 'Operator Dashboard';
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
    grid-template-columns: 1fr 1fr;
    gap: 2.5rem;
}

.section-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
    width: 100%;
}

.section-card.operations-card {
    min-height: 260px;
    padding-bottom: 1.5rem;
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
    padding: 1.25rem 2.5rem;
    display: flex;
    flex-direction: column;
    align-items: stretch;
}

.quick-stats {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 3.5rem;
    margin-bottom: 1.2rem;
    width: 100%;
}

.quick-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 120px;
}

.section-actions {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.2rem;
    justify-items: stretch;
    margin-top: 0.5rem;
    margin-bottom: 0.5rem;
    width: 100%;
}

.section-actions-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.2rem;
    justify-items: stretch;
    margin-bottom: 0.5rem;
    width: 100%;
}

.section-actions-last {
    display: grid;
    grid-template-columns: 3fr;
    margin-bottom: 0.5rem;
    width: 100%;
}

.section-actions .btn, .section-actions-row .btn {
    min-width: 0;
    font-size: 1.15rem;
    padding: 1.2rem 0.2rem;
    height: 64px;
    text-align: center;
    width: 100%;
}

.section-actions-last .btn {
    font-size: 1.15rem;
    padding: 1.2rem 0.2rem;
    height: 64px;
    text-align: center;
    width: 100%;
    grid-column: 1 / span 3;
}

.alerts-section {
    margin-top: 1.5rem;
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

.alert-item .alert-icon {
    font-size: 1.25rem;
}

@media (max-width: 768px) {
    .section-grid {
        grid-template-columns: 1fr !important;
        gap: 1.25rem;
    }

    .section-body {
        padding: 0.75rem;
    }

    .section-actions,
    .section-actions-row {
        grid-template-columns: 1fr !important;
        gap: 0.6rem;
    }

    .section-actions .btn,
    .section-actions-row .btn,
    .section-actions-last .btn {
        font-size: 0.95rem;
        height: auto;
        padding: 0.75rem;
    }

    .section-actions-last .btn {
        grid-column: auto;
    }

    .quick-stats {
        gap: 1rem;
    }

    .quick-stat {
        min-width: auto;
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
        <h1>👋 Operator Dashboard</h1>
        <p>Welcome back! Here's an overview of your ride-hailing operations.</p>
    </div>

    <!-- Alerts Section -->
    <div class="alerts-section" style="margin-top: 0; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">📢 Alerts</h3>
        <?php if ($pendingDriversCount > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">👤</span>
            <span><strong><?php echo e($pendingDriversCount); ?></strong> driver<?php echo $pendingDriversCount !== 1 ? 's' : ''; ?> pending verification.</span>
            <a href="<?php echo e(url('operator/drivers.php?verification_status=pending')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">Review Drivers</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No pending driver verifications.</span>
        </div>
        <?php endif; ?>

        <?php if ($pendingDocumentsCount > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">📄</span>
            <span><strong><?php echo e($pendingDocumentsCount); ?></strong> driver document<?php echo $pendingDocumentsCount !== 1 ? 's' : ''; ?> pending verification.</span>
            <a href="<?php echo e(url('operator/drivers.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">Review Documents</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No pending driver documents.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($stats['PendingGdprCount'] > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">⚠️</span>
            <span>You have <strong><?php echo e($stats['PendingGdprCount']); ?></strong> pending GDPR requests requiring attention.</span>
            <a href="<?php echo e(url('operator/gdpr_requests.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">Review</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No pending GDPR requests.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($stats['OpenRequestsCount'] > 0): ?>
        <div class="alert-item <?php echo $stats['OpenRequestsCount'] > 5 ? 'warning' : 'info'; ?>">
            <span class="alert-icon">📋</span>
            <span><strong><?php echo e($stats['OpenRequestsCount']); ?></strong> ride request<?php echo $stats['OpenRequestsCount'] !== 1 ? 's' : ''; ?> waiting for drivers.</span>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No pending ride requests.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($unreadMessagesCount > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">💬</span>
            <span>You have <strong><?php echo e($unreadMessagesCount); ?></strong> unread message<?php echo $unreadMessagesCount !== 1 ? 's' : ''; ?>.</span>
            <a href="<?php echo e(url('operator/messages.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">View Messages</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No unread messages.</span>
        </div>
        <?php endif; ?>
        
        <?php if ($carshareStats['pendingCustomers'] > 0): ?>
        <div class="alert-item warning">
            <span class="alert-icon">🚙</span>
            <span><strong><?php echo e($carshareStats['pendingCustomers']); ?></strong> CarShare customer<?php echo $carshareStats['pendingCustomers'] !== 1 ? 's' : ''; ?> pending approval.</span>
            <a href="<?php echo e(url('operator/carshare_approvals.php')); ?>" class="btn btn-small btn-ghost" style="margin-left: auto;">Review</a>
        </div>
        <?php else: ?>
        <div class="alert-item info">
            <span class="alert-icon">✅</span>
            <span>No pending CarShare approvals.</span>
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
            <div class="stat-icon">🤖</div>
            <div class="stat-value" style="color: #8b5cf6;"><?php echo $avStats['activeRides']; ?></div>
            <div class="stat-label">Active AV Rides</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚙</div>
            <div class="stat-value" style="color: #06b6d4;"><?php echo e($carshareStats['activeRentals']); ?></div>
            <div class="stat-label">Active Rentals</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value" style="color: #22c55e;">
                <?php echo e($stats['AvailableDriversCount'] + $avStats['available'] + $carshareStats['available']); ?>
            </div>
            <div class="stat-label">Available Rides</div>
        </div>

    </div>

    <!-- Section Cards -->
    <div class="section-grid">


        <!-- Rides Section (Drivers, AV, CarShare) -->
        <div class="section-card" style="grid-column: span 2; width: 100%;">
            <div class="section-header">
                <h2>🚗 Available Rides</h2>
            </div>
            <div class="section-body">
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="value">Total Drivers: <?php echo number_format($totalDrivers); ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="value">Autonomous Vehicles: <?php echo $avStats['total']; ?></div>
                    </div>
                    <div class="quick-stat">
                        <div class="value">CarShare Vehicles: <?php echo $carshareStats['totalVehicles']; ?></div>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="<?php echo e(url('operator/drivers_hub.php')); ?>" class="btn btn-primary btn-small">🚗 Drivers Hub</a>
                    <a href="<?php echo e(url('operator/autonomous_hub.php')); ?>" class="btn btn-outline btn-small">🤖 Autonomous Hub</a>
                    <a href="<?php echo e(url('operator/carshare_hub.php')); ?>" class="btn btn-outline btn-small">🚙 CarShare Hub</a>
                </div>
            </div>
        </div>

        <!-- Operations Section -->
        <div class="section-card operations-card" style="grid-column: span 2; width: 100%;">
            <div class="section-header">
                <h2>⚙️ Operations</h2>
                <a href="<?php echo e(url('operator/operations_hub.php')); ?>" class="btn btn-ghost btn-small">View All →</a>
            </div>
            <div class="section-body">
                <div class="section-actions">
                    <a href="<?php echo e(url('operator/safety_inspections.php')); ?>" class="btn btn-outline btn-small">🛡️ Safety Inspections</a>
                    <a href="<?php echo e(url('operator/system_logs.php')); ?>" class="btn btn-outline btn-small">📋 System Logs</a>
                    <a href="<?php echo e(url('operator/financial_reports.php')); ?>" class="btn btn-outline btn-small">💰 Financial Reports</a>
                </div>
                <div class="section-actions-row">
                    <a href="<?php echo e(url('operator/messages.php')); ?>" class="btn btn-outline btn-small">💬 Messages</a>
                    <a href="<?php echo e(url('operator/reports.php')); ?>" class="btn btn-outline btn-small">📊 Reports & Analytics</a>
                    <?php if ($stats['PendingGdprCount'] > 0): ?>
                    <a href="<?php echo e(url('operator/gdpr_requests.php')); ?>" class="btn btn-primary btn-small" style="background: #f59e0b;">⚠️ GDPR Requests (<?php echo e($stats['PendingGdprCount']); ?>)</a>
                    <?php else: ?>
                    <a href="<?php echo e(url('operator/gdpr_requests.php')); ?>" class="btn btn-outline btn-small">🔒 GDPR Requests</a>
                    <?php endif; ?>
                </div>
                <div class="section-actions-last">
                    <a href="<?php echo e(url('operator/view_data.php')); ?>" class="btn btn-outline btn-small">🗄️ Database Viewer</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
