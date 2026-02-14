<?php
/**
 * Passenger Payment History Page
 * 
 * Shows all payment transactions for the logged-in passenger
 * including both driver trips and autonomous rides
 * with full fare breakdown and service fee details.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/payments.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId = (int)$passengerRow['PassengerID'];

// Get payment history for driver trips
$payments = get_passenger_payment_history($passengerId, 100);

// Get autonomous ride payments using stored procedure
$avPayments = [];
$stmt = db_call_procedure('dbo.spGetPassengerAVPayments', [$passengerId]);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $avPayments[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get CarShare rental payments
$carsharePayments = [];
$carshareCustomer = null;

// First check if passenger is a carshare customer
$stmt = @db_query('EXEC dbo.CarshareGetCustomerByPassenger ?', [$passengerId]);
if ($stmt) {
    $carshareCustomer = @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    @sqlsrv_free_stmt($stmt);
}

if ($carshareCustomer) {
    $customerId = (int)$carshareCustomer['CustomerID'];
    
    // Get recent payments for carshare
    $stmt = @db_query('EXEC dbo.CarshareGetRecentPayments ?, ?', [$customerId, 100]);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $carsharePayments[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
}

// Calculate totals for driver trips
$totalSpent = 0;
$totalTrips = 0;
$totalTips = 0;

foreach ($payments as $payment) {
    if (isset($payment['TotalAmount']) && $payment['PaymentStatus'] === 'completed') {
        $totalSpent += (float)$payment['TotalAmount'];
        $totalTrips++;
        if (isset($payment['TipAmount'])) {
            $totalTips += (float)$payment['TipAmount'];
        }
    }
}

// Calculate totals for autonomous rides
$avTotalSpent = 0;
$avTotalRides = 0;

foreach ($avPayments as $payment) {
    if (isset($payment['TotalAmount']) && $payment['PaymentStatus'] === 'completed') {
        $avTotalSpent += (float)$payment['TotalAmount'];
        $avTotalRides++;
    }
}

// Calculate totals for CarShare rentals
$carshareTotalSpent = 0;
$carshareTotalRentals = 0;

foreach ($carsharePayments as $payment) {
    $status = strtolower($payment['Status'] ?? 'completed');
    if (isset($payment['Amount']) && ($status === 'completed' || $status === 'paid')) {
        $carshareTotalSpent += (float)$payment['Amount'];
        $carshareTotalRentals++;
    }
}

// Combined totals
$combinedSpent = $totalSpent + $avTotalSpent + $carshareTotalSpent;
$combinedTrips = $totalTrips + $avTotalRides + $carshareTotalRentals;

$pageTitle = 'Payment History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Payment History</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                View all your ride payments and transaction details.
            </p>
        </div>
    </div>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">Total Spent</div>
            <div style="font-size: 1.5rem; font-weight: bold;">€<?php echo number_format($combinedSpent, 2); ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">All Rides</div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?php echo $combinedTrips; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">🚗 Driver Trips</div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?php echo $totalTrips; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">🤖 AV Rides</div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?php echo $avTotalRides; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">🚙 CarShare</div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?php echo $carshareTotalRentals; ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">Tips Given</div>
            <div style="font-size: 1.5rem; font-weight: bold;">€<?php echo number_format($totalTips, 2); ?></div>
        </div>
        <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.8rem; opacity: 0.9;">Avg. per Ride</div>
            <div style="font-size: 1.5rem; font-weight: bold;">€<?php echo $combinedTrips > 0 ? number_format($combinedSpent / $combinedTrips, 2) : '0.00'; ?></div>
        </div>
    </div>

    <?php if (empty($payments) && empty($avPayments) && empty($carsharePayments)): ?>
        <div style="text-align: center; padding: 3rem 1rem;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">💳</div>
            <h3 style="color: #666; margin-bottom: 0.5rem;">No payment history yet</h3>
            <p class="text-muted">Your payment transactions will appear here after completing rides.</p>
            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1rem; flex-wrap: wrap;">
                <a href="<?php echo url('passenger/request_ride.php'); ?>" class="btn btn-primary">
                    🚗 Request a Ride
                </a>
                <a href="<?php echo url('passenger/request_autonomous_ride.php'); ?>" class="btn btn-outline">
                    🤖 Try Autonomous
                </a>
                <a href="<?php echo url('carshare/request_vehicle.php'); ?>" class="btn btn-outline">
                    🚙 Rent a Car
                </a>
            </div>
        </div>
    <?php else: ?>
        
        <!-- Driver Trip Payments -->
        <?php if (!empty($payments)): ?>
        <h3 style="font-size: 1rem; margin: 1.5rem 0 0.75rem 0;">🚗 Driver Trip Payments</h3>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Trip</th>
                        <th>Driver</th>
                        <th>Route</th>
                        <th style="text-align: right;">Distance</th>
                        <th style="text-align: right;">Fare</th>
                        <th style="text-align: right;">Tip</th>
                        <th style="text-align: right;">Total</th>
                        <th>Status</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $completedAt = $payment['CompletedAt'] ?? $payment['CreatedAt'] ?? null;
                        $dateStr = '';
                        if ($completedAt instanceof DateTimeInterface) {
                            $dateStr = $completedAt->format('M j, Y H:i');
                        } elseif ($completedAt) {
                            $dateStr = (string)$completedAt;
                        }
                        
                        $totalAmount = isset($payment['TotalAmount']) ? (float)$payment['TotalAmount'] : 0;
                        $tipAmount = isset($payment['TipAmount']) ? (float)$payment['TipAmount'] : 0;
                        $fareAmount = $totalAmount - $tipAmount;
                        $distanceKm = isset($payment['DistanceKm']) ? (float)$payment['DistanceKm'] : 0;
                        
                        $statusClass = match($payment['PaymentStatus'] ?? '') {
                            'completed' => 'color: #059669;',
                            'pending' => 'color: #d97706;',
                            'failed' => 'color: #dc2626;',
                            default => ''
                        };
                        ?>
                        <tr>
                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                <?php echo e($dateStr); ?>
                            </td>
                            <td>
                                <a href="<?php echo url('passenger/ride_detail.php?trip_id=' . e($payment['TripID'])); ?>" 
                                   style="font-weight: 500;">
                                    View Details
                                </a>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?php echo e($payment['DriverName'] ?? 'N/A'); ?>
                            </td>
                            <td style="font-size: 0.8rem; max-width: 200px;">
                                <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" 
                                     title="<?php echo e($payment['PickupLocation'] ?? ''); ?> → <?php echo e($payment['DropoffLocation'] ?? ''); ?>">
                                    <?php echo e($payment['PickupLocation'] ?? 'N/A'); ?> → 
                                    <?php echo e($payment['DropoffLocation'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td style="text-align: right; font-size: 0.85rem;">
                                <?php echo $distanceKm > 0 ? number_format($distanceKm, 1) . ' km' : '-'; ?>
                            </td>
                            <td style="text-align: right; font-size: 0.85rem;">
                                €<?php echo number_format($fareAmount, 2); ?>
                            </td>
                            <td style="text-align: right; font-size: 0.85rem; color: #059669;">
                                <?php echo $tipAmount > 0 ? '€' . number_format($tipAmount, 2) : '-'; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold;">
                                €<?php echo number_format($totalAmount, 2); ?>
                            </td>
                            <td>
                                <span style="<?php echo $statusClass; ?> font-size: 0.8rem; font-weight: 500;">
                                    <?php echo e(ucfirst($payment['PaymentStatus'] ?? 'Unknown')); ?>
                                </span>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php 
                                $method = $payment['PaymentMethod'] ?? '';
                                $icon = match(strtoupper($method)) {
                                    'CARD' => '💳',
                                    'CASH' => '💵',
                                    'WALLET' => '👛',
                                    default => '💰'
                                };
                                echo $icon . ' ' . e($method);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Autonomous Ride Payments -->
        <?php if (!empty($avPayments)): ?>
        <h3 style="font-size: 1rem; margin: 1.5rem 0 0.75rem 0;">🤖 Autonomous Ride Payments</h3>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vehicle</th>
                        <th>Route</th>
                        <th style="text-align: right;">Distance</th>
                        <th style="text-align: right;">Total</th>
                        <th>Status</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($avPayments as $payment): ?>
                        <?php
                        $completedAt = $payment['CompletedAt'] ?? $payment['RequestedAt'] ?? null;
                        $dateStr = '';
                        if ($completedAt instanceof DateTimeInterface) {
                            $dateStr = $completedAt->format('M j, Y H:i');
                        } elseif ($completedAt) {
                            $dateStr = (string)$completedAt;
                        }
                        
                        $totalAmount = isset($payment['TotalAmount']) ? (float)$payment['TotalAmount'] : 0;
                        $distanceKm = isset($payment['DistanceKm']) ? (float)$payment['DistanceKm'] : 0;
                        
                        $statusClass = match($payment['PaymentStatus'] ?? '') {
                            'completed' => 'color: #059669;',
                            'pending' => 'color: #d97706;',
                            'failed' => 'color: #dc2626;',
                            default => ''
                        };
                        ?>
                        <tr>
                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                <?php echo e($dateStr); ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <a href="<?php echo url('passenger/autonomous_ride_detail.php?ride_id=' . e($payment['RideID'])); ?>" 
                                   title="<?php echo e($payment['VehicleMake'] . ' ' . $payment['VehicleModel']); ?>">
                                    🤖 <?php echo e($payment['VehicleCode'] ?? 'N/A'); ?>
                                </a>
                            </td>
                            <td style="font-size: 0.8rem; max-width: 200px;">
                                <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" 
                                     title="<?php echo e($payment['PickupAddress'] ?? ''); ?> → <?php echo e($payment['DropoffAddress'] ?? ''); ?>">
                                    <?php echo e($payment['PickupAddress'] ?? 'N/A'); ?> → 
                                    <?php echo e($payment['DropoffAddress'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td style="text-align: right; font-size: 0.85rem;">
                                <?php echo $distanceKm > 0 ? number_format($distanceKm, 1) . ' km' : '-'; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold;">
                                €<?php echo number_format($totalAmount, 2); ?>
                            </td>
                            <td>
                                <span style="<?php echo $statusClass; ?> font-size: 0.8rem; font-weight: 500;">
                                    <?php echo e(ucfirst($payment['PaymentStatus'] ?? ($payment['RideStatus'] ?? 'Unknown'))); ?>
                                </span>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php 
                                $method = $payment['PaymentMethod'] ?? '';
                                $icon = match(strtoupper($method)) {
                                    'CARD' => '💳',
                                    'CASH' => '💵',
                                    'WALLET' => '👛',
                                    default => '💰'
                                };
                                echo $icon . ' ' . e($method);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- CarShare Rental Payments -->
        <?php if (!empty($carsharePayments)): ?>
        <h3 style="font-size: 1rem; margin: 1.5rem 0 0.75rem 0;">🚙 CarShare Rental Payments</h3>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th style="text-align: right;">Amount</th>
                        <th>Status</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($carsharePayments as $payment): ?>
                        <?php
                        $createdAt = $payment['CreatedAt'] ?? null;
                        $dateStr = '';
                        if ($createdAt instanceof DateTimeInterface) {
                            $dateStr = $createdAt->format('M j, Y H:i');
                        } elseif ($createdAt) {
                            $dateStr = (string)$createdAt;
                        }
                        
                        $amount = isset($payment['Amount']) ? (float)$payment['Amount'] : 0;
                        $paymentStatus = strtolower($payment['Status'] ?? 'pending');
                        
                        $statusClass = match($paymentStatus) {
                            'completed', 'paid' => 'color: #059669;',
                            'pending' => 'color: #d97706;',
                            'failed', 'declined' => 'color: #dc2626;',
                            default => ''
                        };
                        
                        $paymentType = ucfirst(str_replace('_', ' ', $payment['PaymentType'] ?? 'rental'));
                        ?>
                        <tr>
                            <td style="font-size: 0.82rem; white-space: nowrap;">
                                <?php echo e($dateStr); ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <a href="<?php echo url('carshare/request_vehicle.php'); ?>">
                                    🚙 <?php echo e(($payment['Make'] ?? '') . ' ' . ($payment['Model'] ?? '')); ?>
                                </a>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?php echo e($paymentType); ?>
                            </td>
                            <td style="text-align: right; font-weight: bold;">
                                €<?php echo number_format($amount, 2); ?>
                            </td>
                            <td>
                                <span style="<?php echo $statusClass; ?> font-size: 0.8rem; font-weight: 500;">
                                    <?php echo e(ucfirst($paymentStatus)); ?>
                                </span>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php 
                                $methodId = (int)($payment['PaymentMethodTypeID'] ?? 0);
                                $icon = match($methodId) {
                                    1 => '💳 Card',
                                    2 => '👛 Wallet',
                                    3 => '🏦 Bank',
                                    default => '💰 Payment'
                                };
                                echo $icon;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Fare Breakdown Legend -->
        <div style="margin-top: 1.5rem; padding: 1rem; background: var(--color-surface, #f8f9fa); border-radius: 8px; font-size: 0.82rem;">
            <h4 style="font-size: 0.9rem; margin: 0 0 0.5rem 0;">Understanding Your Fare</h4>
            <p class="text-muted" style="margin: 0;">
                Each fare includes: <strong>Base fare</strong> (starting fee) + 
                <strong>Distance</strong> (€/km) + <strong>Time</strong> (€/min).
                There are no platform fees — 100% of your fare goes to the driver. Tips go directly to drivers.
                CarShare rentals are charged per minute/hour with distance fees.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
