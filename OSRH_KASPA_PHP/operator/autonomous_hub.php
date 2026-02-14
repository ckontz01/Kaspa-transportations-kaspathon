<?php
/**
 * Autonomous Vehicle Hub - Central navigation for AV management
 * 
 * Provides quick access to:
 * - AV Map (real-time vehicle locations)
 * - AV Vehicle Management
 * - AV Rides (ride monitoring)
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

// Get quick stats using stored procedures
$vehicleStats = ['total' => 0, 'available' => 0, 'busy' => 0];
$rideStats = ['active' => 0, 'today' => 0];

// Get vehicle stats using spGetAllAutonomousVehicles
$stmt = db_call_procedure('dbo.spGetAllAutonomousVehicles', []);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicleStats['total']++;
        $status = strtolower($row['Status'] ?? 'offline');
        if ($status === 'available') $vehicleStats['available']++;
        if ($status === 'busy') $vehicleStats['busy']++;
    }
    sqlsrv_free_stmt($stmt);
}

// Get ride stats using spGetAVHubStats
$stmt = db_call_procedure('dbo.spGetAVHubStats', []);
if ($stmt !== false) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row) {
        $rideStats['today'] = (int)($row['TotalToday'] ?? 0);
        $rideStats['active'] = (int)($row['ActiveRides'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);
}

$pageTitle = 'Autonomous Vehicles';
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

.hub-card.map-card .hub-card-badge {
    background: #dbeafe;
    color: #1e40af;
}

.hub-card.vehicles-card .hub-card-badge {
    background: #dcfce7;
    color: #166534;
}

.hub-card.rides-card .hub-card-badge {
    background: #fef3c7;
    color: #92400e;
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
        <h1>🤖 Autonomous Vehicles</h1>
        <p>Manage and monitor your autonomous vehicles</p>
    </div>

    <div class="hub-stats">
        <div class="hub-stat">
            <div class="value"><?php echo $vehicleStats['total']; ?></div>
            <div class="label">Total Vehicles</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #22c55e;"><?php echo $vehicleStats['available']; ?></div>
            <div class="label">Available</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #f59e0b;"><?php echo $vehicleStats['busy']; ?></div>
            <div class="label">On Ride</div>
        </div>
        <div class="hub-stat">
            <div class="value" style="color: #3b82f6;"><?php echo $rideStats['active']; ?></div>
            <div class="label">Active Rides</div>
        </div>
        <div class="hub-stat">
            <div class="value"><?php echo $rideStats['today']; ?></div>
            <div class="label">Rides Today</div>
        </div>
    </div>

    <div class="hub-cards">
        <a href="<?php echo e(url('operator/autonomous_vehicle_map.php')); ?>" class="hub-card map-card">
            <div class="hub-card-icon">🗺️</div>
            <h2 class="hub-card-title">Map</h2>
            <p class="hub-card-desc">
                Real-time map view of all autonomous vehicles. Track locations, see zones, and monitor vehicle distribution.
            </p>
            <span class="hub-card-badge">Live Tracking</span>
        </a>

        <a href="<?php echo e(url('operator/autonomous_vehicles.php')); ?>" class="hub-card vehicles-card">
            <div class="hub-card-icon">🚗</div>
            <h2 class="hub-card-title">Manage Vehicles</h2>
            <p class="hub-card-desc">
                Manage vehicle statuses, view details, and control availability. Activate, deactivate, or set maintenance mode.
            </p>
            <span class="hub-card-badge"><?php echo $vehicleStats['total']; ?> Vehicles</span>
        </a>

        <a href="<?php echo e(url('operator/autonomous_rides.php')); ?>" class="hub-card rides-card">
            <div class="hub-card-icon">📋</div>
            <h2 class="hub-card-title">Rides</h2>
            <p class="hub-card-desc">
                Monitor all autonomous rides. View ride details, track progress, and analyze completed trips.
            </p>
            <span class="hub-card-badge"><?php echo $rideStats['active']; ?> Active</span>
        </a>
    </div>

    <div class="hub-footer">
        <a href="<?php echo e(url('operator/dashboard.php')); ?>">← Back to Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
