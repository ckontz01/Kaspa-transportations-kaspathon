<?php
/**
 * Operations Hub - Central navigation for operational management
 * 
 * Provides quick access to:
 * - Financial Reports
 * - Messages
 * - Reports & Analytics
 * - GDPR Requests
 * - Database Viewer
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('operator');

// Get quick stats
$operationStats = ['gdprPending' => 0, 'pendingDrivers' => 0, 'totalTrips' => 0, 'totalRevenue' => 0];

// Get GDPR pending count from dashboard stats
$stmt = db_call_procedure('spOperatorGetDashboardStats', []);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $operationStats['gdprPending'] = (int)($row['PendingGdprCount'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

// Get pending drivers count
$stmt = db_call_procedure('dbo.spGetDriversWithFilters', [null, 'pending']);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $operationStats['pendingDrivers']++;
    }
    sqlsrv_free_stmt($stmt);
}

$pageTitle = 'Operations';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.hub-container {
    max-width: 900px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.hub-header {
    text-align: center;
    margin-bottom: 2rem;
}

.hub-header h1 {
    font-size: 2rem;
    margin: 0 0 0.5rem 0;
}

.hub-header p {
    color: var(--text-muted, #6b7280);
    font-size: 1rem;
    margin: 0;
}

.hub-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
    padding: 1.25rem;
    background: var(--color-surface, #f8fafc);
    border-radius: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
}

.hub-stat {
    text-align: center;
}

.hub-stat .value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
}

.hub-stat .label {
    font-size: 0.75rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 0.25rem;
}

.hub-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.hub-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.5rem;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.hub-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border-color: var(--color-primary, #3b82f6);
}

.hub-card-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    line-height: 1;
}

.hub-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 0.5rem 0;
    color: #1e40af;
}

.hub-card-desc {
    font-size: 0.875rem;
    color: var(--text-muted, #6b7280);
    margin: 0 0 1rem 0;
    line-height: 1.5;
}

.hub-card-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.hub-card.financial-card .hub-card-title {
    color: #059669;
}

.hub-card.financial-card .hub-card-badge {
    background: #dcfce7;
    color: #166534;
}

.hub-card.messages-card .hub-card-title {
    color: #1e40af;
}

.hub-card.messages-card .hub-card-badge {
    background: #dbeafe;
    color: #1e40af;
}

.hub-card.reports-card .hub-card-title {
    color: #7c3aed;
}

.hub-card.reports-card .hub-card-badge {
    background: #ede9fe;
    color: #7c3aed;
}

.hub-card.gdpr-card .hub-card-title {
    color: #b45309;
}

.hub-card.gdpr-card .hub-card-badge {
    background: #fef3c7;
    color: #b45309;
}

.hub-card.database-card .hub-card-title {
    color: #0891b2;
}

.hub-card.database-card .hub-card-badge {
    background: #cffafe;
    color: #0e7490;
}

.hub-footer {
    margin-top: 2rem;
    text-align: center;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
}

.hub-footer a {
    color: var(--color-primary, #3b82f6);
    text-decoration: none;
    font-size: 0.875rem;
}

.hub-footer a:hover {
    text-decoration: underline;
}
</style>

<div class="hub-container">
    <div class="hub-header">
        <h1>⚙️ Operations</h1>
        <p>Manage finances, communications, and compliance</p>
    </div>

    <div class="hub-stats">
        <div class="hub-stat">
            <div class="value" style="color: <?php echo $operationStats['gdprPending'] > 0 ? '#f59e0b' : '#22c55e'; ?>;"><?php echo $operationStats['gdprPending']; ?></div>
            <div class="label">GDPR Pending</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: <?php echo $operationStats['pendingDrivers'] > 0 ? '#f59e0b' : '#22c55e'; ?>;"><?php echo $operationStats['pendingDrivers']; ?></div>
            <div class="label">Pending Drivers</div>
        </div>
    </div>

    <div class="hub-cards">
                <a href="<?php echo e(url('operator/safety_inspections.php')); ?>" class="hub-card safety-card">
                    <div class="hub-card-icon">🛡️</div>
                    <h2 class="hub-card-title">Safety Inspections</h2>
                    <p class="hub-card-desc">
                        Schedule and track vehicle safety inspections. Ensure compliance and maintain vehicle safety standards.
                    </p>
                    <span class="hub-card-badge">Compliance</span>
                </a>

                <a href="<?php echo e(url('operator/system_logs.php')); ?>" class="hub-card logs-card">
                    <div class="hub-card-icon">📋</div>
                    <h2 class="hub-card-title">System Logs</h2>
                    <p class="hub-card-desc">
                        View system activity logs, track operations, and monitor all events including CarShare.
                    </p>
                    <span class="hub-card-badge">Activity</span>
                </a>
        <a href="<?php echo e(url('operator/financial_reports.php')); ?>" class="hub-card financial-card">
            <div class="hub-card-icon">💰</div>
            <h2 class="hub-card-title">Financial Reports</h2>
            <p class="hub-card-desc">
                View revenue reports, driver earnings, payment summaries, and financial analytics for your operations.
            </p>
            <span class="hub-card-badge">Revenue</span>
        </a>

        <a href="<?php echo e(url('operator/messages.php')); ?>" class="hub-card messages-card">
            <div class="hub-card-icon">💬</div>
            <h2 class="hub-card-title">Messages</h2>
            <p class="hub-card-desc">
                Communicate with drivers and passengers. Send notifications, announcements, and manage support requests.
            </p>
            <span class="hub-card-badge">Communication</span>
        </a>

        <a href="<?php echo e(url('operator/reports.php')); ?>" class="hub-card reports-card">
            <div class="hub-card-icon">📊</div>
            <h2 class="hub-card-title">Reports & Analytics</h2>
            <p class="hub-card-desc">
                Access detailed analytics, performance reports, and operational insights to optimize your business.
            </p>
            <span class="hub-card-badge">Analytics</span>
        </a>

        <a href="<?php echo e(url('operator/gdpr_requests.php')); ?>" class="hub-card gdpr-card">
            <div class="hub-card-icon">🔒</div>
            <h2 class="hub-card-title">GDPR Requests</h2>
            <p class="hub-card-desc">
                Manage data privacy requests. Handle data exports, deletions, and ensure GDPR compliance.
            </p>
            <span class="hub-card-badge"><?php echo $operationStats['gdprPending']; ?> Pending</span>
        </a>

        <a href="<?php echo e(url('operator/view_data.php')); ?>" class="hub-card database-card">
            <div class="hub-card-icon">🗄️</div>
            <h2 class="hub-card-title">Database Viewer</h2>
            <p class="hub-card-desc">
                Browse and query database tables. View records, inspect data, and perform administrative lookups.
            </p>
            <span class="hub-card-badge">Admin</span>
        </a>
    </div>

    <div class="hub-footer">
        <a href="<?php echo e(url('operator/dashboard.php')); ?>">← Back to Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
