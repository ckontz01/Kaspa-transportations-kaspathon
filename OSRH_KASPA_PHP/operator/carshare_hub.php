<?php
/**
 * CarShare Hub - Central navigation for CarShare management
 * 
 * Provides quick access to:
 * - CarShare Vehicles
 * - CarShare Zones
 * - CarShare Customer Approvals
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

// Get CarShare stats using stored procedure
$stats = [
    'totalVehicles' => 0,
    'availableVehicles' => 0,
    'rentedVehicles' => 0,
    'maintenanceVehicles' => 0,
    'totalZones' => 0,
    'pendingApprovals' => 0,
    'approvedCustomers' => 0,
    'activeRentals' => 0,
    'rentalsToday' => 0
];

$stmt = db_call_procedure('dbo.spGetCarshareHubStats', []);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $stats['totalVehicles'] = (int)($row['TotalVehicles'] ?? 0);
        $stats['availableVehicles'] = (int)($row['AvailableVehicles'] ?? 0);
        $stats['rentedVehicles'] = (int)($row['RentedVehicles'] ?? 0);
        $stats['maintenanceVehicles'] = (int)($row['MaintenanceVehicles'] ?? 0);
        $stats['totalZones'] = (int)($row['TotalZones'] ?? 0);
        $stats['pendingApprovals'] = (int)($row['PendingApprovals'] ?? 0);
        $stats['approvedCustomers'] = (int)($row['ApprovedCustomers'] ?? 0);
        $stats['activeRentals'] = (int)($row['ActiveRentals'] ?? 0);
        $stats['rentalsToday'] = (int)($row['RentalsToday'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

$pageTitle = 'CarShare Management';
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
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
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
    font-size: 0.7rem;
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

.hub-card.vehicles-card .hub-card-badge {
    background: #dcfce7;
    color: #166534;
}

.hub-card.zones-card .hub-card-badge {
    background: #dbeafe;
    color: #1e40af;
}

.hub-card.approvals-card .hub-card-badge {
    background: #fef3c7;
    color: #92400e;
}

.hub-card.approvals-card .hub-card-badge.has-pending {
    background: #fee2e2;
    color: #991b1b;
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
        <h1>🚙 CarShare Management</h1>
        <p>Manage your car-sharing fleet, zones, and customer approvals</p>
    </div>

    <div class="hub-stats">
        <div class="hub-stat">
            <div class="value"><?php echo $stats['totalVehicles']; ?></div>
            <div class="label">Vehicles</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #22c55e;"><?php echo $stats['availableVehicles']; ?></div>
            <div class="label">Available</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #06b6d4;"><?php echo $stats['rentedVehicles']; ?></div>
            <div class="label">Rented</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #8b5cf6;"><?php echo $stats['totalZones']; ?></div>
            <div class="label">Zones</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #f59e0b;"><?php echo $stats['pendingApprovals']; ?></div>
            <div class="label">Pending</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #3b82f6;"><?php echo $stats['activeRentals']; ?></div>
            <div class="label">Active Rentals</div>
        </div>
    </div>

    <div class="hub-cards">
        <a href="<?php echo e(url('operator/carshare_vehicles.php')); ?>" class="hub-card vehicles-card">
            <div class="hub-card-icon">🚗</div>
            <h2 class="hub-card-title">Vehicles</h2>
            <p class="hub-card-desc">
                Manage your CarShare fleet. View vehicle details, update status, set availability, and track locations.
            </p>
            <span class="hub-card-badge"><?php echo $stats['totalVehicles']; ?> Vehicles</span>
        </a>

        <a href="<?php echo e(url('operator/carshare_zones.php')); ?>" class="hub-card zones-card">
            <div class="hub-card-icon">📍</div>
            <h2 class="hub-card-title">Zones</h2>
            <p class="hub-card-desc">
                Manage pickup and drop-off zones. Configure zone boundaries, pricing, and vehicle distribution.
            </p>
            <span class="hub-card-badge"><?php echo $stats['totalZones']; ?> Zones</span>
        </a>

        <a href="<?php echo e(url('operator/carshare_approvals.php')); ?>" class="hub-card approvals-card">
            <div class="hub-card-icon">👤</div>
            <h2 class="hub-card-title">Customer Approvals</h2>
            <p class="hub-card-desc">
                Review and approve customer applications. Verify licenses, documents, and grant access to CarShare.
            </p>
            <?php if ($stats['pendingApprovals'] > 0): ?>
            <span class="hub-card-badge has-pending"><?php echo $stats['pendingApprovals']; ?> Pending</span>
            <?php else: ?>
            <span class="hub-card-badge"><?php echo $stats['approvedCustomers']; ?> Approved</span>
            <?php endif; ?>
        </a>
    </div>

    <div class="hub-footer">
        <a href="<?php echo e(url('operator/dashboard.php')); ?>">← Back to Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
