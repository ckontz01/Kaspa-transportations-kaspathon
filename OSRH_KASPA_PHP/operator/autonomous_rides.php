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

// Filter parameters
$statusFilter = trim((string)array_get($_GET, 'status', ''));
$dateFrom = trim((string)array_get($_GET, 'date_from', ''));
$dateTo = trim((string)array_get($_GET, 'date_to', ''));

$allowedStatuses = ['', 'requested', 'vehicle_assigned', 'en_route_to_pickup', 'arrived_at_pickup', 'in_progress', 'completed', 'cancelled'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Get all autonomous rides using stored procedure
$stmt = db_call_procedure('dbo.spGetAllAutonomousRidesFiltered', [
    $statusFilter ?: null,
    $dateFrom ?: null,
    $dateTo ?: null
]);

$rides = [];
$stats = [
    'total' => 0, 
    'active' => 0, 
    'completed' => 0, 
    'cancelled' => 0,
    'total_revenue' => 0.0
];

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rides[] = $row;
        $stats['total']++;
        
        $rideStatus = strtolower($row['Status'] ?? '');
        if ($rideStatus === 'completed') {
            $stats['completed']++;
            $stats['total_revenue'] += (float)($row['ActualFare'] ?? $row['EstimatedFare'] ?? 0);
        } elseif ($rideStatus === 'cancelled') {
            $stats['cancelled']++;
        } else {
            $stats['active']++;
        }
    }
    sqlsrv_free_stmt($stmt);
}

function formatRideDateTime($dt): string {
    if (!$dt) return '<span class="text-muted">-</span>';
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d H:i');
    }
    return (string)$dt;
}

function getRideStatusBadge(string $status): string {
    $colors = [
        'requested' => '#6b7280',
        'vehicle_assigned' => '#3b82f6',
        'en_route_to_pickup' => '#8b5cf6',
        'arrived_at_pickup' => '#f59e0b',
        'in_progress' => '#22c55e',
        'completed' => '#10b981',
        'cancelled' => '#ef4444'
    ];
    $labels = [
        'requested' => 'Requested',
        'vehicle_assigned' => 'Vehicle Assigned',
        'en_route_to_pickup' => 'En Route to Pickup',
        'arrived_at_pickup' => 'Arrived at Pickup',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled'
    ];
    $color = $colors[strtolower($status)] ?? '#6b7280';
    $label = $labels[strtolower($status)] ?? ucfirst($status);
    return '<span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:4px;font-size:0.7rem;font-weight:600;background:' . $color . '20;color:' . $color . ';white-space:nowrap;">' . e($label) . '</span>';
}

function truncateAddress(string $address, int $maxLen = 30): string {
    if (strlen($address) <= $maxLen) return $address;
    return substr($address, 0, $maxLen) . '...';
}

$pageTitle = 'Autonomous Rides';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}
.stat-card .stat-value {
    font-size: 1.75rem;
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
.address-cell {
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1300px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">🤖 Autonomous Rides</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Monitor and manage all autonomous vehicle rides.
            </p>
        </div>
        <a href="<?php echo e(url('operator/autonomous_vehicles.php')); ?>" class="btn btn-outline btn-small">
            Manage Vehicles
        </a>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Rides</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#3b82f6;"><?php echo $stats['active']; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#22c55e;"><?php echo $stats['completed']; ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#ef4444;"><?php echo $stats['cancelled']; ?></div>
            <div class="stat-label">Cancelled</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#10b981;">€<?php echo number_format($stats['total_revenue'], 2); ?></div>
            <div class="stat-label">Revenue</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="js-validate" novalidate style="margin-top:0.8rem;margin-bottom:0.8rem;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="">All</option>
                    <option value="requested" <?php echo $statusFilter === 'requested' ? 'selected' : ''; ?>>Requested</option>
                    <option value="vehicle_assigned" <?php echo $statusFilter === 'vehicle_assigned' ? 'selected' : ''; ?>>Vehicle Assigned</option>
                    <option value="en_route_to_pickup" <?php echo $statusFilter === 'en_route_to_pickup' ? 'selected' : ''; ?>>En Route</option>
                    <option value="arrived_at_pickup" <?php echo $statusFilter === 'arrived_at_pickup' ? 'selected' : ''; ?>>At Pickup</option>
                    <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="date_from">From Date</label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="date_to">To Date</label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>">
            </div>
        </div>

        <div class="form-group" style="margin-top:0.7rem;">
            <button type="submit" class="btn btn-primary btn-small">Filter</button>
            <?php if ($statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                <a href="<?php echo e(url('operator/autonomous_rides.php')); ?>" class="btn btn-ghost btn-small">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Rides Table -->
    <div style="overflow-x:auto;margin-top:0.5rem;">
        <?php if (empty($rides)): ?>
            <p class="text-muted" style="font-size:0.84rem;">No rides found for the current filters.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Passenger</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Pickup</th>
                        <th>Dropoff</th>
                        <th>Distance</th>
                        <th>Fare</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rides as $r): ?>
                    <tr>
                        <td><?php echo e($r['RideID'] ?? ''); ?></td>
                        <td>
                            <strong><?php echo e($r['PassengerName']); ?></strong>
                            <br><small class="text-muted"><?php echo e($r['PassengerEmail']); ?></small>
                        </td>
                        <td>
                            <?php if (isset($r['VehicleID']) && $r['VehicleID']): ?>
                                <strong><?php echo e(($r['Make'] ?? '') . ' ' . ($r['Model'] ?? '')); ?></strong>
                                <br><small class="text-muted"><?php echo e($r['PlateNo'] ?? $r['VehicleCode'] ?? ''); ?></small>
                            <?php else: ?>
                                <span class="text-muted">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo getRideStatusBadge($r['Status'] ?? 'requested'); ?></td>
                        <td class="address-cell" title="<?php echo e($r['PickupAddress'] ?? ''); ?>">
                            <?php echo e(truncateAddress($r['PickupAddress'] ?? 'Unknown')); ?>
                        </td>
                        <td class="address-cell" title="<?php echo e($r['DropoffAddress'] ?? ''); ?>">
                            <?php echo e(truncateAddress($r['DropoffAddress'] ?? 'Unknown')); ?>
                        </td>
                        <td>
                            <?php 
                            $dist = (float)($r['EstimatedDistance'] ?? 0);
                            echo $dist > 0 ? number_format($dist, 1) . ' km' : '-';
                            ?>
                        </td>
                        <td>
                            <?php 
                            $fare = $r['ActualFare'] ?? $r['EstimatedFare'] ?? 0;
                            echo '€' . number_format((float)$fare, 2);
                            if ($r['ActualFare'] === null && $r['EstimatedFare'] !== null) {
                                echo ' <small class="text-muted">(est)</small>';
                            }
                            ?>
                        </td>
                        <td><?php echo formatRideDateTime($r['RequestedAt'] ?? null); ?></td>
                        <td>
                            <a href="<?php echo e(url('operator/autonomous_ride_detail.php?id=' . urlencode((string)$r['AutonomousRideID']))); ?>" 
                               class="btn btn-small btn-ghost" title="View Details">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
