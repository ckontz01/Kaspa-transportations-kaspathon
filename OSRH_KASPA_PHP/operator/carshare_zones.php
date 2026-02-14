<?php
/**
 * CarShare Zones Management
 * View and manage car-sharing pickup/dropoff zones
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

// Get zones using stored procedure
$zones = [];
$stmt = db_call_procedure('dbo.spGetCarshareZones', [1]); // Active only
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $zones[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Group by city
$zonesByCity = [];
foreach ($zones as $z) {
    $city = $z['City'] ?? 'Unknown';
    if (!isset($zonesByCity[$city])) {
        $zonesByCity[$city] = [];
    }
    $zonesByCity[$city][] = $z;
}

$pageTitle = 'CarShare Zones';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.zones-container {
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

.stats-bar {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding: 1rem 1.5rem;
    background: var(--color-surface, #f8fafc);
    border-radius: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
}

.stat-item {
    text-align: center;
}

.stat-item .value {
    font-size: 1.5rem;
    font-weight: 700;
}

.stat-item .label {
    font-size: 0.75rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
}

.city-section {
    margin-bottom: 2rem;
}

.city-header {
    font-size: 1.25rem;
    margin: 0 0 1rem 0;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.city-header .count {
    font-size: 0.85rem;
    font-weight: 400;
    color: var(--text-muted, #6b7280);
}

.zones-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.zone-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 10px;
    padding: 1rem;
    transition: all 0.2s;
}

.zone-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.zone-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.zone-name {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.zone-type {
    padding: 0.2rem 0.6rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.type-standard { background: #e5e7eb; color: #374151; }
.type-airport { background: #dbeafe; color: #1e40af; }
.type-premium { background: #fef3c7; color: #92400e; }
.type-pink { background: #fce7f3; color: #9d174d; }
.type-intercity { background: #dcfce7; color: #166534; }

.zone-info {
    font-size: 0.85rem;
    color: var(--text-muted, #6b7280);
    margin-bottom: 0.75rem;
}

.zone-info div {
    margin-bottom: 0.25rem;
}

.zone-capacity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.capacity-bar {
    flex: 1;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.capacity-bar .fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s;
}

.capacity-bar .fill.low { background: #22c55e; }
.capacity-bar .fill.medium { background: #f59e0b; }
.capacity-bar .fill.high { background: #ef4444; }

.zone-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
    font-size: 0.8rem;
    color: var(--text-muted, #6b7280);
}

.zone-bonus {
    color: #22c55e;
    font-weight: 600;
}

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

<div class="zones-container">
    <div class="page-header">
        <h1>📍 CarShare Zones</h1>
    </div>

    <?php flash_render(); ?>

    <div class="stats-bar">
        <div class="stat-item">
            <div class="value"><?php echo count($zones); ?></div>
            <div class="label">Total Zones</div>
        </div>
        <div class="stat-item">
            <div class="value"><?php echo count($zonesByCity); ?></div>
            <div class="label">Cities</div>
        </div>
        <div class="stat-item">
            <div class="value"><?php echo array_sum(array_column($zones, 'CurrentVehicleCount')); ?></div>
            <div class="label">Vehicles in Zones</div>
        </div>
        <div class="stat-item">
            <div class="value"><?php echo array_sum(array_column($zones, 'MaxCapacity')); ?></div>
            <div class="label">Total Capacity</div>
        </div>
    </div>

    <?php if (empty($zones)): ?>
    <div class="empty-state">
        <p>No zones found.</p>
    </div>
    <?php else: ?>
    
    <?php foreach ($zonesByCity as $city => $cityZones): ?>
    <div class="city-section">
        <h2 class="city-header">
            🏙️ <?php echo e($city); ?>
            <span class="count">(<?php echo count($cityZones); ?> zones)</span>
        </h2>
        
        <div class="zones-grid">
            <?php foreach ($cityZones as $z): ?>
            <?php
                $type = strtolower($z['ZoneType'] ?? 'standard');
                $current = (int)($z['CurrentVehicleCount'] ?? 0);
                $max = (int)($z['MaxCapacity'] ?? 1);
                $percentage = $max > 0 ? ($current / $max) * 100 : 0;
                $fillClass = $percentage < 50 ? 'low' : ($percentage < 80 ? 'medium' : 'high');
                $bonus = (float)($z['BonusAmount'] ?? 0);
            ?>
            <div class="zone-card">
                <div class="zone-header">
                    <h3 class="zone-name"><?php echo e($z['ZoneName']); ?></h3>
                    <span class="zone-type type-<?php echo e($type); ?>">
                        <?php echo e($type); ?>
                    </span>
                </div>

                <div class="zone-info">
                    <?php if (!empty($z['Description'])): ?>
                    <div><?php echo e($z['Description']); ?></div>
                    <?php endif; ?>
                    <div>📏 Radius: <?php echo number_format((int)($z['RadiusMeters'] ?? 0)); ?>m</div>
                    <?php if (!empty($z['District'])): ?>
                    <div>📍 <?php echo e($z['District']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="zone-capacity">
                    <span>🚗 <?php echo $current; ?>/<?php echo $max; ?></span>
                    <div class="capacity-bar">
                        <div class="fill <?php echo $fillClass; ?>" style="width: <?php echo min(100, $percentage); ?>%;"></div>
                    </div>
                </div>

                <div class="zone-footer">
                    <span>
                        🕐 <?php 
                        $start = $z['OperatingHoursStart'] ?? null;
                        $end = $z['OperatingHoursEnd'] ?? null;
                        if ($start instanceof DateTime && $end instanceof DateTime) {
                            echo $start->format('H:i') . ' - ' . $end->format('H:i');
                        } else {
                            echo '24h';
                        }
                        ?>
                    </span>
                    <?php if ($bonus > 0): ?>
                    <span class="zone-bonus">+€<?php echo number_format($bonus, 2); ?> bonus</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>

    <div class="back-link">
        <a href="<?php echo e(url('operator/carshare_hub.php')); ?>">← Back to CarShare Hub</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
