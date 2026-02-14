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

$vehicleId = (int)array_get($_GET, 'id', 0);

if ($vehicleId <= 0) {
    flash_add('error', 'Invalid vehicle ID.');
    header('Location: ' . url('operator/autonomous_vehicles.php'));
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)array_get($_POST, 'action', ''));
    
    if ($action === 'update_status') {
        $newStatus = trim((string)array_get($_POST, 'status', ''));
        $allowedStatuses = ['available', 'offline', 'maintenance', 'charging'];
        
        if (in_array($newStatus, $allowedStatuses, true)) {
            $stmt = db_call_procedure('dbo.spUpdateAutonomousVehicleStatus', [$vehicleId, $newStatus]);
            if ($stmt) {
                sqlsrv_free_stmt($stmt);
                flash_add('success', 'Vehicle status updated to ' . ucfirst($newStatus) . '.');
            } else {
                $errors = db_last_errors();
                $errorMsg = 'Failed to update vehicle status.';
                if ($errors && isset($errors[0]['message'])) {
                    $errorMsg = $errors[0]['message'];
                }
                flash_add('error', $errorMsg);
            }
        }
    }
    
    header('Location: ' . url('operator/autonomous_vehicle_detail.php?id=' . $vehicleId));
    exit;
}

// Get vehicle details
$stmt = db_call_procedure('dbo.spGetAutonomousVehicleById', [$vehicleId]);
$vehicle = null;
if ($stmt) {
    $vehicle = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

if (!$vehicle) {
    flash_add('error', 'Vehicle not found.');
    header('Location: ' . url('operator/autonomous_vehicles.php'));
    exit;
}

// Get recent rides for this vehicle
$stmt = db_call_procedure('dbo.spGetVehicleRecentRides', [$vehicleId, 10]);
$recentRides = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $recentRides[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

function formatDt($dt): string {
    if (!$dt) return '-';
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i');
    return (string)$dt;
}

function getStatusBadge(string $status): string {
    $colors = [
        'available' => '#22c55e',
        'busy' => '#f59e0b',
        'maintenance' => '#ef4444',
        'offline' => '#6b7280',
        'charging' => '#3b82f6',
        'completed' => '#22c55e',
        'cancelled' => '#ef4444',
        'in_progress' => '#3b82f6',
        'vehicle_dispatched' => '#8b5cf6'
    ];
    $color = $colors[strtolower($status)] ?? '#6b7280';
    return '<span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:4px;font-size:0.75rem;font-weight:600;background:' . $color . '20;color:' . $color . ';">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

function getBatteryIcon(?int $battery): string {
    if ($battery === null) return '<span class="text-muted">N/A</span>';
    if ($battery >= 80) return '<span style="color:#22c55e;">🔋 ' . $battery . '%</span>';
    if ($battery >= 50) return '<span style="color:#f59e0b;">🔋 ' . $battery . '%</span>';
    if ($battery >= 20) return '<span style="color:#ef4444;">🪫 ' . $battery . '%</span>';
    return '<span style="color:#ef4444;font-weight:bold;">⚠️ ' . $battery . '%</span>';
}

$pageTitle = 'Vehicle Detail: ' . ($vehicle['VehicleCode'] ?? 'Unknown');
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}
.info-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1.25rem;
}
.info-card h3 {
    font-size: 0.95rem;
    margin: 0 0 1rem 0;
    color: var(--text-color);
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    padding-bottom: 0.5rem;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.4rem 0;
    font-size: 0.85rem;
}
.info-row .label {
    color: var(--text-muted, #6b7280);
}
.info-row .value {
    font-weight: 500;
}
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-box {
    text-align: center;
    padding: 1rem;
    background: var(--color-surface);
    border-radius: 8px;
}
.stat-box .value {
    font-size: 1.75rem;
    font-weight: 700;
}
.stat-box .label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
}
</style>

<div class="card" style="margin:2rem auto;max-width:1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                🚗 <?php echo e($vehicle['VehicleCode'] ?? 'Unknown'); ?>
            </h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                <?php echo e(($vehicle['Make'] ?? '') . ' ' . ($vehicle['Model'] ?? '')); ?>
                <?php if (!empty($vehicle['Year'])): ?>(<?php echo e($vehicle['Year']); ?>)<?php endif; ?>
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <?php echo getStatusBadge($vehicle['Status'] ?? 'offline'); ?>
            <a href="<?php echo e(url('operator/autonomous_vehicles.php')); ?>" class="btn btn-ghost btn-small">
                ← Back to Manage Vehicles
            </a>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="value"><?php echo (int)($vehicle['TotalRides'] ?? 0); ?></div>
            <div class="label">Total Rides</div>
        </div>
        <div class="stat-box">
            <div class="value"><?php echo (int)($vehicle['CompletedRides'] ?? 0); ?></div>
            <div class="label">Completed</div>
        </div>
        <div class="stat-box">
            <div class="value" style="color:#f59e0b;">
                <?php 
                $avgRating = $vehicle['AverageRating'] ?? null;
                echo $avgRating ? number_format((float)$avgRating, 1) . '★' : 'N/A';
                ?>
            </div>
            <div class="label">Avg Rating</div>
        </div>
    </div>

    <div class="detail-grid">
        <!-- Vehicle Info -->
        <div class="info-card">
            <h3>🚗 Vehicle Information</h3>
            <div class="info-row">
                <span class="label">Code</span>
                <span class="value"><?php echo e($vehicle['VehicleCode'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Make / Model</span>
                <span class="value"><?php echo e(($vehicle['Make'] ?? '') . ' ' . ($vehicle['Model'] ?? '')); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Year</span>
                <span class="value"><?php echo e($vehicle['Year'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Color</span>
                <span class="value"><?php echo e($vehicle['Color'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">License Plate</span>
                <span class="value"><?php echo e($vehicle['PlateNo'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Seating Capacity</span>
                <span class="value"><?php echo (int)($vehicle['SeatingCapacity'] ?? 4); ?> passengers</span>
            </div>
            <div class="info-row">
                <span class="label">Vehicle Type</span>
                <span class="value"><?php echo e($vehicle['VehicleTypeName'] ?? '-'); ?></span>
            </div>
        </div>

        <!-- Status & Location -->
        <div class="info-card">
            <h3>📍 Status & Location</h3>
            <div class="info-row">
                <span class="label">Current Status</span>
                <span class="value"><?php echo getStatusBadge($vehicle['Status'] ?? 'offline'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Battery Level</span>
                <span class="value"><?php echo getBatteryIcon(isset($vehicle['BatteryLevel']) ? (int)$vehicle['BatteryLevel'] : null); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Operating Zone</span>
                <span class="value"><?php echo e($vehicle['GeofenceName'] ?? 'Not assigned'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Current Location</span>
                <span class="value">
                    <?php if ($vehicle['CurrentLatitude'] && $vehicle['CurrentLongitude']): ?>
                        <?php echo number_format((float)$vehicle['CurrentLatitude'], 5); ?>, 
                        <?php echo number_format((float)$vehicle['CurrentLongitude'], 5); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Location Updated</span>
                <span class="value"><?php echo formatDt($vehicle['LocationUpdatedAt'] ?? null); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Created</span>
                <span class="value"><?php echo formatDt($vehicle['CreatedAt'] ?? null); ?></span>
            </div>

            <!-- Status Update Form -->
            <form method="post" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color, #e5e7eb);">
                <input type="hidden" name="action" value="update_status">
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <select name="status" class="form-control" style="flex: 1;">
                        <option value="available" <?php echo ($vehicle['Status'] ?? '') === 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="offline" <?php echo ($vehicle['Status'] ?? '') === 'offline' ? 'selected' : ''; ?>>Offline</option>
                        <option value="maintenance" <?php echo ($vehicle['Status'] ?? '') === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="charging" <?php echo ($vehicle['Status'] ?? '') === 'charging' ? 'selected' : ''; ?>>Charging</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-small">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recent Rides -->
    <div style="margin-top: 1.5rem;">
        <h3 style="font-size: 1rem; margin-bottom: 1rem;">📋 Recent Rides</h3>
        
        <?php if (empty($recentRides)): ?>
            <p class="text-muted" style="font-size: 0.85rem;">No rides found for this vehicle.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ride ID</th>
                            <th>Passenger</th>
                            <th>From / To</th>
                            <th>Status</th>
                            <th>Fare</th>
                            <th>Rating</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentRides as $ride): ?>
                        <tr>
                            <td>#<?php echo (int)($ride['AutonomousRideID'] ?? 0); ?></td>
                            <td><?php echo e($ride['PassengerName'] ?? '-'); ?></td>
                            <td style="font-size: 0.8rem;">
                                <?php echo e($ride['PickupDescription'] ?? $ride['PickupAddress'] ?? '-'); ?><br>
                                → <?php echo e($ride['DropoffDescription'] ?? $ride['DropoffAddress'] ?? '-'); ?>
                            </td>
                            <td><?php echo getStatusBadge($ride['Status'] ?? 'unknown'); ?></td>
                            <td>
                                <?php if ($ride['ActualFare']): ?>
                                    €<?php echo number_format((float)$ride['ActualFare'], 2); ?>
                                <?php elseif ($ride['EstimatedFare']): ?>
                                    <span class="text-muted">~€<?php echo number_format((float)$ride['EstimatedFare'], 2); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ride['Rating']): ?>
                                    <span style="color:#f59e0b;"><?php echo (int)$ride['Rating']; ?>★</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8rem;"><?php echo formatDt($ride['RequestedAt'] ?? null); ?></td>
                            <td>
                                <a href="<?php echo e(url('operator/autonomous_ride_detail.php?id=' . (int)($ride['AutonomousRideID'] ?? 0))); ?>" 
                                   class="btn btn-ghost btn-small" title="View">
                                    👁️
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
