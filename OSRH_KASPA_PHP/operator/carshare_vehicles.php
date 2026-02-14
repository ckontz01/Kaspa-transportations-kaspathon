<?php
/**
 * CarShare Vehicles Management
 * View and manage car-sharing fleet vehicles
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

// Get filter
$statusFilter = trim((string)array_get($_GET, 'status', ''));
$allowedStatuses = ['', 'available', 'reserved', 'rented', 'maintenance', 'out_of_zone', 'low_fuel', 'damaged'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Get vehicles using stored procedure
$vehicles = [];
$stmt = db_call_procedure('dbo.spGetCarshareVehicles', [$statusFilter ?: null]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Count by status
$statusCounts = ['available' => 0, 'reserved' => 0, 'rented' => 0, 'maintenance' => 0, 'other' => 0];
foreach ($vehicles as $v) {
    $st = strtolower($v['Status'] ?? '');
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    } else {
        $statusCounts['other']++;
    }
}

$pageTitle = 'CarShare Vehicles';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.vehicles-container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-header h1 {
    font-size: 1.75rem;
    margin: 0;
}

.filter-bar {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.filter-btn {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 20px;
    background: var(--color-surface, #f8fafc);
    color: var(--text-muted, #6b7280);
    text-decoration: none;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.filter-btn:hover {
    border-color: var(--color-primary, #3b82f6);
    color: var(--color-primary, #3b82f6);
}

.filter-btn.active {
    background: var(--color-primary, #3b82f6);
    border-color: var(--color-primary, #3b82f6);
    color: white;
}

.filter-btn .count {
    display: inline-block;
    background: rgba(255,255,255,0.2);
    padding: 0.1rem 0.4rem;
    border-radius: 10px;
    font-size: 0.75rem;
    margin-left: 0.25rem;
}

.filter-btn.active .count {
    background: rgba(255,255,255,0.3);
}

.vehicles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
}

.vehicle-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.2s;
}

.vehicle-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.vehicle-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.vehicle-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
}

.vehicle-plate {
    font-size: 0.85rem;
    color: var(--text-muted, #6b7280);
    margin-top: 0.25rem;
}

.vehicle-status {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-available { background: #dcfce7; color: #166534; }
.status-reserved { background: #fef3c7; color: #92400e; }
.status-rented, .status-in_use { background: #dbeafe; color: #1e40af; }
.status-maintenance { background: #fee2e2; color: #991b1b; }
.status-low_fuel { background: #ffedd5; color: #9a3412; }
.status-damaged { background: #fecaca; color: #dc2626; }
.status-out_of_zone { background: #e5e7eb; color: #374151; }

.vehicle-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.info-item {
    font-size: 0.85rem;
}

.info-item .label {
    color: var(--text-muted, #6b7280);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.info-item .value {
    font-weight: 500;
    margin-top: 0.1rem;
}

.vehicle-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
}

.fuel-bar {
    width: 100px;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.fuel-bar .fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s;
}

.fuel-bar .fill.high { background: #22c55e; }
.fuel-bar .fill.medium { background: #f59e0b; }
.fuel-bar .fill.low { background: #ef4444; }

.back-link {
    margin-top: 2rem;
    text-align: center;
}

.back-link a {
    color: var(--color-primary, #3b82f6);
    text-decoration: none;
    font-size: 0.875rem;
}

.back-link a:hover {
    text-decoration: underline;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-muted, #6b7280);
}
</style>

<div class="vehicles-container">
    <div class="page-header">
        <h1>🚗 CarShare Vehicles</h1>
    </div>

    <?php flash_render(); ?>

    <div class="filter-bar">
        <a href="?status=" class="filter-btn <?php echo $statusFilter === '' ? 'active' : ''; ?>">
            All <span class="count"><?php echo count($vehicles); ?></span>
        </a>
        <a href="?status=available" class="filter-btn <?php echo $statusFilter === 'available' ? 'active' : ''; ?>">
            Available <span class="count"><?php echo $statusCounts['available']; ?></span>
        </a>
        <a href="?status=rented" class="filter-btn <?php echo $statusFilter === 'rented' ? 'active' : ''; ?>">
            Rented <span class="count"><?php echo $statusCounts['rented']; ?></span>
        </a>
        <a href="?status=reserved" class="filter-btn <?php echo $statusFilter === 'reserved' ? 'active' : ''; ?>">
            Reserved <span class="count"><?php echo $statusCounts['reserved']; ?></span>
        </a>
        <a href="?status=maintenance" class="filter-btn <?php echo $statusFilter === 'maintenance' ? 'active' : ''; ?>">
            Maintenance <span class="count"><?php echo $statusCounts['maintenance']; ?></span>
        </a>
    </div>

    <?php if (empty($vehicles)): ?>
    <div class="empty-state">
        <p>No vehicles found<?php echo $statusFilter ? " with status '$statusFilter'" : ''; ?>.</p>
    </div>
    <?php else: ?>
    <div class="vehicles-grid">
        <?php foreach ($vehicles as $v): ?>
        <?php
            $status = strtolower($v['Status'] ?? 'unknown');
            $fuelLevel = (int)($v['FuelLevelPercent'] ?? 0);
            $fuelClass = $fuelLevel > 50 ? 'high' : ($fuelLevel > 20 ? 'medium' : 'low');
            $isElectric = (bool)($v['IsElectric'] ?? false);
        ?>
        <div class="vehicle-card">
            <div class="vehicle-header">
                <div>
                    <h3 class="vehicle-title"><?php echo e($v['Make'] . ' ' . $v['Model']); ?></h3>
                    <div class="vehicle-plate"><?php echo e($v['PlateNumber']); ?></div>
                </div>
                <span class="vehicle-status status-<?php echo e($status); ?>">
                    <?php echo e(str_replace('_', ' ', $status)); ?>
                </span>
            </div>

            <div class="vehicle-info">
                <div class="info-item">
                    <div class="label">Type</div>
                    <div class="value"><?php echo e($v['TypeName'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Year</div>
                    <div class="value"><?php echo e($v['Year']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Zone</div>
                    <div class="value"><?php echo e($v['ZoneName'] ?? 'Not in zone'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">City</div>
                    <div class="value"><?php echo e($v['City'] ?? '-'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Odometer</div>
                    <div class="value"><?php echo number_format((int)($v['OdometerKm'] ?? 0)); ?> km</div>
                </div>
                <div class="info-item">
                    <div class="label">Price/Hour</div>
                    <div class="value">€<?php echo number_format((float)($v['PricePerHour'] ?? 0), 2); ?></div>
                </div>
            </div>

            <div class="vehicle-footer">
                <div>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                        <?php echo $isElectric ? '🔋 Battery' : '⛽ Fuel'; ?>: <?php echo $fuelLevel; ?>%
                    </span>
                    <div class="fuel-bar" style="margin-top: 0.25rem;">
                        <div class="fill <?php echo $fuelClass; ?>" style="width: <?php echo $fuelLevel; ?>%;"></div>
                    </div>
                </div>
                <span style="font-size: 0.75rem; color: var(--text-muted);">
                    <?php echo $isElectric ? '⚡ Electric' : ($v['IsHybrid'] ? '🔄 Hybrid' : ''); ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="<?php echo e(url('operator/carshare_hub.php')); ?>">← Back to CarShare Hub</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
