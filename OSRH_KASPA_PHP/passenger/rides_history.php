<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId = (int)$passengerRow['PassengerID'];

// Get filter from URL
$filter = $_GET['type'] ?? 'all';

// Get regular trips (with driver)
$trips = [];
$stmt = db_call_procedure('dbo.spGetPassengerTripHistory', [$passengerId]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $row['ride_type'] = 'driver';
        $row['ride_type_label'] = '🚗 Driver';
        $row['ride_type_color'] = '#3b82f6';
        $trips[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get autonomous rides
$autonomousRides = [];
$stmt = db_call_procedure('dbo.spGetPassengerAutonomousRides', [$passengerId]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $autonomousRides[] = [
            'TripID' => null,
            'AutonomousRideID' => $row['AutonomousRideID'],
            'CarshareRentalID' => null,
            'RequestedAt' => $row['RequestedAt'],
            'StartTime' => $row['TripStartedAt'] ?? null,
            'EndTime' => $row['TripCompletedAt'] ?? null,
            'Status' => $row['Status'],
            'IsSegmentTrip' => false,
            'SegmentOrder' => null,
            'DriverName' => ($row['VehicleCode'] ?? 'AV') . ' (Autonomous)',
            'Amount' => $row['ActualFare'] ?? $row['EstimatedFare'],
            'CurrencyCode' => 'EUR',
            'PaymentStatus' => 'completed',
            'ride_type' => 'autonomous',
            'ride_type_label' => '🤖 Autonomous',
            'ride_type_color' => '#8b5cf6',
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// Get CarShare rentals
$carshareRentals = [];
$carshareCustomer = null;

// First check if passenger is a carshare customer
$stmt = @db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if ($stmt) {
    $carshareCustomer = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    @sqlsrv_free_stmt($stmt);
}

if ($carshareCustomer) {
    $customerId = (int)$carshareCustomer['CustomerID'];
    
    // Get completed rentals
    $stmt = @db_query('EXEC dbo.CarshareGetRecentRentals ?, ?', [$customerId, 100]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $carshareRentals[] = [
                'TripID' => null,
                'AutonomousRideID' => null,
                'CarshareRentalID' => $row['RentalID'],
                'RequestedAt' => $row['StartedAt'] ?? $row['CreatedAt'] ?? null,
                'StartTime' => $row['StartedAt'] ?? null,
                'EndTime' => $row['EndedAt'] ?? null,
                'Status' => $row['Status'] ?? 'completed',
                'IsSegmentTrip' => false,
                'SegmentOrder' => null,
                'DriverName' => ($row['Make'] ?? '') . ' ' . ($row['Model'] ?? '') . ' (' . ($row['PlateNumber'] ?? 'N/A') . ')',
                'Amount' => $row['TotalCost'] ?? 0,
                'CurrencyCode' => 'EUR',
                'PaymentStatus' => 'completed',
                'ride_type' => 'carshare',
                'ride_type_label' => '🚙 CarShare',
                'ride_type_color' => '#06b6d4',
                'StartZone' => $row['StartZoneName'] ?? null,
                'EndZone' => $row['EndZoneName'] ?? null,
                'DistanceKm' => $row['DistanceKm'] ?? null,
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Combine all rides
$allRides = array_merge($trips, $autonomousRides, $carshareRentals);

// Sort by RequestedAt descending (newest first)
usort($allRides, function($a, $b) {
    $dateA = $a['RequestedAt'] ?? null;
    $dateB = $b['RequestedAt'] ?? null;
    
    if ($dateA instanceof DateTime && $dateB instanceof DateTime) {
        return $dateB->getTimestamp() - $dateA->getTimestamp();
    }
    return 0;
});

// Store original counts before filtering
$totalTrips = count($trips);
$totalAutonomous = count($autonomousRides);
$totalCarshare = count($carshareRentals);
$totalAll = count($allRides);

// Apply filter
if ($filter !== 'all') {
    $allRides = array_filter($allRides, function($ride) use ($filter) {
        return $ride['ride_type'] === $filter;
    });
}

function osrh_format_dt($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i');
    }
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i');
    }
    return $value ? (string)$value : '-';
}

$pageTitle = 'Ride History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">📋 All Ride History</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                All your rides - with drivers, autonomous vehicles, and more.
            </p>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
        <a href="?type=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-ghost'; ?>">
            All Rides (<?php echo $totalAll; ?>)
        </a>
        <a href="?type=driver" class="btn btn-sm <?php echo $filter === 'driver' ? 'btn-primary' : 'btn-ghost'; ?>">
            🚗 With Driver (<?php echo $totalTrips; ?>)
        </a>
        <a href="?type=autonomous" class="btn btn-sm <?php echo $filter === 'autonomous' ? 'btn-primary' : 'btn-ghost'; ?>">
            🤖 Autonomous (<?php echo $totalAutonomous; ?>)
        </a>
        <a href="?type=carshare" class="btn btn-sm <?php echo $filter === 'carshare' ? 'btn-primary' : 'btn-ghost'; ?>">
            🚙 CarShare (<?php echo $totalCarshare; ?>)
        </a>
    </div>

    <?php if (empty($allRides)): ?>
        <div style="text-align: center; padding: 2rem;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🚗</div>
            <p class="text-muted" style="margin-bottom: 1rem;">
                <?php if ($filter === 'all'): ?>
                No rides found yet. Book your first ride to see it here!
                <?php elseif ($filter === 'carshare'): ?>
                No CarShare rentals found.
                <?php else: ?>
                No <?php echo $filter === 'autonomous' ? 'autonomous' : 'driver'; ?> rides found.
                <?php endif; ?>
            </p>
            <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                <a href="request_ride.php" class="btn btn-primary">🚗 Book a Ride</a>
                <a href="request_autonomous_ride.php" class="btn btn-ghost">🤖 Book Autonomous</a>
                <a href="<?php echo url('carshare/request_vehicle.php'); ?>" class="btn btn-ghost">🚙 Rent a Car</a>
            </div>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Driver/Vehicle</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allRides as $ride): ?>
                    <?php 
                    $isSegmentTrip = !empty($ride['IsSegmentTrip']) && $ride['IsSegmentTrip'] == 1;
                    $segmentOrder = $ride['SegmentOrder'] ?? null;
                    $paymentStatus = strtolower($ride['PaymentStatus'] ?? '');
                    $status = strtolower($ride['Status'] ?? '');
                    
                    // Status styling
                    $statusColors = [
                        'completed' => ['bg' => 'rgba(34, 197, 94, 0.2)', 'color' => '#22c55e', 'icon' => '✅'],
                        'in_progress' => ['bg' => 'rgba(59, 130, 246, 0.2)', 'color' => '#3b82f6', 'icon' => '🚗'],
                        'cancelled' => ['bg' => 'rgba(239, 68, 68, 0.2)', 'color' => '#ef4444', 'icon' => '❌'],
                        'assigned' => ['bg' => 'rgba(245, 158, 11, 0.2)', 'color' => '#f59e0b', 'icon' => '⏳'],
                        'vehicle_dispatched' => ['bg' => 'rgba(99, 102, 241, 0.2)', 'color' => '#6366f1', 'icon' => '🚙'],
                        'vehicle_arrived' => ['bg' => 'rgba(16, 185, 129, 0.2)', 'color' => '#10b981', 'icon' => '📍'],
                    ];
                    $statusStyle = $statusColors[$status] ?? ['bg' => 'rgba(107, 114, 128, 0.2)', 'color' => '#6b7280', 'icon' => '•'];
                    ?>
                    <tr>
                        <td>
                            <div style="font-size: 0.85rem;"><?php echo e(osrh_format_dt($ride['RequestedAt'] ?? '')); ?></div>
                            <?php if (!empty($ride['EndTime'])): ?>
                            <div style="font-size: 0.75rem; color: #6b7280;">
                                Ended: <?php echo e(osrh_format_dt($ride['EndTime'])); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size: 0.8rem; padding: 0.2rem 0.5rem; background: <?php echo $ride['ride_type'] === 'autonomous' ? 'rgba(139, 92, 246, 0.2)' : ($ride['ride_type'] === 'carshare' ? 'rgba(6, 182, 212, 0.2)' : 'rgba(59, 130, 246, 0.2)'); ?>; color: <?php echo $ride['ride_type_color']; ?>; border-radius: 4px; white-space: nowrap;">
                                <?php echo e($ride['ride_type_label']); ?>
                            </span>
                            <?php if ($isSegmentTrip): ?>
                            <br>
                            <span style="font-size: 0.7rem; color: #94a3b8;">Segment <?php echo e($segmentOrder); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size: 0.8rem; padding: 0.2rem 0.5rem; background: <?php echo $statusStyle['bg']; ?>; color: <?php echo $statusStyle['color']; ?>; border-radius: 4px; white-space: nowrap;">
                                <?php echo $statusStyle['icon']; ?> <?php echo e(ucfirst(str_replace('_', ' ', $ride['Status'] ?? ''))); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem;"><?php echo e($ride['DriverName'] ?? '-'); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($ride['Amount'])): ?>
                                <?php
                                $amount = is_numeric($ride['Amount']) ? number_format((float)$ride['Amount'], 2) : $ride['Amount'];
                                ?>
                                <?php if ($paymentStatus === 'completed'): ?>
                                    <span style="color: #22c55e; font-weight: 600;">€<?php echo e($amount); ?> ✓</span>
                                <?php elseif ($paymentStatus === 'pending'): ?>
                                    <span style="color: #f59e0b;">€<?php echo e($amount); ?> ⏳</span>
                                <?php else: ?>
                                    <span>€<?php echo e($amount); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ride['ride_type'] === 'autonomous'): ?>
                                <a href="autonomous_ride_detail.php?ride_id=<?php echo e((string)$ride['AutonomousRideID']); ?>"
                                   class="btn btn-ghost btn-small" style="white-space: nowrap;">
                                    View Details
                                </a>
                            <?php elseif ($ride['ride_type'] === 'carshare'): ?>
                                <a href="<?php echo url('carshare/request_vehicle.php'); ?>"
                                   class="btn btn-ghost btn-small" style="white-space: nowrap;">
                                    View Details
                                </a>
                            <?php else: ?>
                                <a href="ride_detail.php?trip_id=<?php echo e((string)$ride['TripID']); ?>"
                                   class="btn btn-ghost btn-small" style="white-space: nowrap;">
                                    View Details
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary Stats -->
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border-subtle);">
            <div style="display: flex; gap: 2rem; flex-wrap: wrap; font-size: 0.85rem; color: #94a3b8;">
                <div>
                    <strong style="color: #e5e7eb;"><?php echo $totalAll; ?></strong> total rides
                </div>
                <div>
                    <strong style="color: #3b82f6;"><?php echo $totalTrips; ?></strong> with driver
                </div>
                <div>
                    <strong style="color: #8b5cf6;"><?php echo $totalAutonomous; ?></strong> autonomous
                </div>
                <div>
                    <strong style="color: #06b6d4;"><?php echo $totalCarshare; ?></strong> carshare
                </div>
                <?php
                $completedCount = count(array_filter(array_merge($trips, $autonomousRides, $carshareRentals), function($r) {
                    return strtolower($r['Status'] ?? '') === 'completed';
                }));
                ?>
                <div>
                    <strong style="color: #22c55e;"><?php echo $completedCount; ?></strong> completed
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
