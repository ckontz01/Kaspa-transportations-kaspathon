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

$rideId = (int)array_get($_GET, 'id', 0);

if ($rideId <= 0) {
    flash_add('error', 'Invalid ride ID.');
    header('Location: ' . url('operator/autonomous_rides.php'));
    exit;
}

// Get ride details using stored procedure
$stmt = db_call_procedure('dbo.spGetAutonomousRideById', [$rideId]);
$ride = null;
if ($stmt) {
    $ride = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

if (!$ride) {
    flash_add('error', 'Ride not found.');
    header('Location: ' . url('operator/autonomous_rides.php'));
    exit;
}

function formatDt($dt): string {
    if (!$dt) return '-';
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s');
    return (string)$dt;
}

function formatDuration($seconds): string {
    if (!$seconds || $seconds <= 0) return '-';
    $seconds = (int)$seconds;
    $hours = floor($seconds / 3600);
    $mins = floor(($seconds % 3600) / 60);
    if ($hours > 0) return $hours . 'h ' . $mins . 'm';
    return $mins . ' min';
}

function getStatusBadge(string $status): string {
    $colors = [
        'requested' => '#6b7280',
        'vehicle_dispatched' => '#8b5cf6',
        'vehicle_arriving' => '#3b82f6',
        'vehicle_arrived' => '#22c55e',
        'passenger_boarding' => '#f59e0b',
        'in_progress' => '#3b82f6',
        'arriving_destination' => '#22c55e',
        'completed' => '#22c55e',
        'cancelled' => '#ef4444'
    ];
    $color = $colors[strtolower($status)] ?? '#6b7280';
    return '<span style="display:inline-block;padding:0.25rem 0.75rem;border-radius:4px;font-size:0.8rem;font-weight:600;background:' . $color . '20;color:' . $color . ';">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

$pageTitle = 'Ride #' . $rideId;
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
    text-align: right;
}
.timeline {
    padding: 0;
    margin: 0;
    list-style: none;
}
.timeline-item {
    position: relative;
    padding-left: 1.5rem;
    padding-bottom: 1rem;
    border-left: 2px solid var(--border-color, #e5e7eb);
}
.timeline-item:last-child {
    border-left: none;
}
.timeline-item::before {
    content: '';
    position: absolute;
    left: -5px;
    top: 0;
    width: 10px;
    height: 10px;
    background: var(--border-color, #e5e7eb);
    border-radius: 50%;
}
.timeline-item.active::before {
    background: #22c55e;
}
.timeline-item .time {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.timeline-item .event {
    font-size: 0.85rem;
    font-weight: 500;
}
</style>

<div class="card" style="margin:2rem auto;max-width:1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                🤖 Autonomous Ride #<?php echo $rideId; ?>
            </h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Requested: <?php echo formatDt($ride['RequestedAt'] ?? null); ?>
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <?php echo getStatusBadge($ride['Status'] ?? 'unknown'); ?>
            <a href="<?php echo e(url('operator/autonomous_rides.php')); ?>" class="btn btn-ghost btn-small">
                ← Back to Rides
            </a>
        </div>
    </div>

    <div class="detail-grid">
        <!-- Passenger Info -->
        <div class="info-card">
            <h3>👤 Passenger</h3>
            <div class="info-row">
                <span class="label">Name</span>
                <span class="value"><?php echo e($ride['PassengerName'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Email</span>
                <span class="value"><?php echo e($ride['PassengerEmail'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Phone</span>
                <span class="value"><?php echo e($ride['PassengerPhone'] ?? '-'); ?></span>
            </div>
            <?php if (!empty($ride['PassengerNotes'])): ?>
            <div class="info-row">
                <span class="label">Notes</span>
                <span class="value"><?php echo e($ride['PassengerNotes']); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Vehicle Info -->
        <div class="info-card">
            <h3>🚗 Vehicle</h3>
            <?php if ($ride['VehicleCode']): ?>
            <div class="info-row">
                <span class="label">Code</span>
                <span class="value">
                    <a href="<?php echo e(url('operator/autonomous_vehicle_detail.php?id=' . (int)($ride['AutonomousVehicleID'] ?? 0))); ?>">
                        <?php echo e($ride['VehicleCode']); ?>
                    </a>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Make / Model</span>
                <span class="value"><?php echo e(($ride['VehicleMake'] ?? '') . ' ' . ($ride['VehicleModel'] ?? '')); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Plate</span>
                <span class="value"><?php echo e($ride['VehiclePlate'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Color</span>
                <span class="value"><?php echo e($ride['VehicleColor'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Battery</span>
                <span class="value">
                    <?php if ($ride['BatteryLevel'] !== null): ?>
                        <?php echo (int)$ride['BatteryLevel']; ?>%
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Vehicle Status</span>
                <span class="value"><?php echo e(ucfirst($ride['VehicleStatus'] ?? '-')); ?></span>
            </div>
            <?php else: ?>
            <p class="text-muted" style="font-size: 0.85rem;">No vehicle assigned yet.</p>
            <?php endif; ?>
        </div>

        <!-- Route Info -->
        <div class="info-card">
            <h3>📍 Route</h3>
            <div class="info-row">
                <span class="label">Pickup</span>
                <span class="value"><?php echo e($ride['PickupDescription'] ?? $ride['PickupAddress'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Pickup Coords</span>
                <span class="value" style="font-size: 0.75rem;">
                    <?php if ($ride['PickupLat'] && $ride['PickupLng']): ?>
                        <?php echo number_format((float)$ride['PickupLat'], 5); ?>, <?php echo number_format((float)$ride['PickupLng'], 5); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Dropoff</span>
                <span class="value"><?php echo e($ride['DropoffDescription'] ?? $ride['DropoffAddress'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Dropoff Coords</span>
                <span class="value" style="font-size: 0.75rem;">
                    <?php if ($ride['DropoffLat'] && $ride['DropoffLng']): ?>
                        <?php echo number_format((float)$ride['DropoffLat'], 5); ?>, <?php echo number_format((float)$ride['DropoffLng'], 5); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <hr style="margin: 0.75rem 0; border: none; border-top: 1px solid var(--border-color);">
            <div class="info-row">
                <span class="label">Est. Distance</span>
                <span class="value">
                    <?php if ($ride['EstimatedTripDistanceKm']): ?>
                        <?php echo number_format((float)$ride['EstimatedTripDistanceKm'], 1); ?> km
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Est. Duration</span>
                <span class="value"><?php echo formatDuration($ride['EstimatedTripDurationSec'] ?? 0); ?></span>
            </div>
            <?php if ($ride['ActualDistanceKm'] || $ride['ActualDurationSec']): ?>
            <div class="info-row">
                <span class="label">Actual Distance</span>
                <span class="value">
                    <?php echo $ride['ActualDistanceKm'] ? number_format((float)$ride['ActualDistanceKm'], 1) . ' km' : '-'; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Actual Duration</span>
                <span class="value"><?php echo formatDuration($ride['ActualDurationSec'] ?? 0); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment & Fare -->
        <div class="info-card">
            <h3>💰 Payment</h3>
            <div class="info-row">
                <span class="label">Payment Method</span>
                <span class="value"><?php echo e($ride['PaymentMethodType'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Estimated Fare</span>
                <span class="value">
                    <?php if ($ride['EstimatedFare']): ?>
                        €<?php echo number_format((float)$ride['EstimatedFare'], 2); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Actual Fare</span>
                <span class="value" style="color: #22c55e; font-size: 1.1rem;">
                    <?php if ($ride['ActualFare']): ?>
                        €<?php echo number_format((float)$ride['ActualFare'], 2); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </span>
            </div>
            
            <?php if ($ride['Rating']): ?>
            <hr style="margin: 0.75rem 0; border: none; border-top: 1px solid var(--border-color);">
            <div class="info-row">
                <span class="label">Rating</span>
                <span class="value" style="color: #f59e0b;"><?php echo (int)$ride['Rating']; ?> ★</span>
            </div>
            <?php if (!empty($ride['RatingComment'])): ?>
            <div class="info-row">
                <span class="label">Comment</span>
                <span class="value"><?php echo e($ride['RatingComment']); ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Timeline -->
    <div class="info-card" style="margin-top: 1.5rem;">
        <h3>⏱️ Ride Timeline</h3>
        <ul class="timeline">
            <li class="timeline-item <?php echo $ride['RequestedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['RequestedAt'] ?? null); ?></div>
                <div class="event">Ride Requested</div>
            </li>
            <li class="timeline-item <?php echo $ride['VehicleDispatchedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['VehicleDispatchedAt'] ?? null); ?></div>
                <div class="event">Vehicle Dispatched</div>
            </li>
            <li class="timeline-item <?php echo $ride['VehicleArrivedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['VehicleArrivedAt'] ?? null); ?></div>
                <div class="event">Vehicle Arrived at Pickup</div>
            </li>
            <li class="timeline-item <?php echo $ride['PassengerBoardedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['PassengerBoardedAt'] ?? null); ?></div>
                <div class="event">Passenger Boarded</div>
            </li>
            <li class="timeline-item <?php echo $ride['TripStartedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['TripStartedAt'] ?? null); ?></div>
                <div class="event">Trip Started</div>
            </li>
            <li class="timeline-item <?php echo $ride['TripCompletedAt'] ? 'active' : ''; ?>">
                <div class="time"><?php echo formatDt($ride['TripCompletedAt'] ?? null); ?></div>
                <div class="event">Trip Completed</div>
            </li>
            <?php if ($ride['CancelledAt']): ?>
            <li class="timeline-item active" style="border-left-color: #ef4444;">
                <div class="time" style="color: #ef4444;"><?php echo formatDt($ride['CancelledAt']); ?></div>
                <div class="event" style="color: #ef4444;">
                    Cancelled
                    <?php if ($ride['CancellationReason']): ?>
                        - <?php echo e($ride['CancellationReason']); ?>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
