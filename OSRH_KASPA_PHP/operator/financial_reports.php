<?php
/**
 * Operator Financial Reports
 * Shows revenue, service fees, and driver/AV payouts
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';

require_login();
require_role('operator');

// Date range filter
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // Start of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Today

// Helper functions
function fmt_currency($amount): string {
    if ($amount === null) return '€0.00';
    return '€' . number_format((float)$amount, 2);
}

// Get financial summary for driver trips
$summary = [];
$stmt = db_call_procedure('dbo.spGetFinancialSummary', [$startDate, $endDate]);
if ($stmt !== false) {
    $summary = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?? [];
    sqlsrv_free_stmt($stmt);
}

// Get autonomous vehicle financial summary
$avSummary = ['TotalRevenue' => 0, 'TotalPayments' => 0, 'ServiceFees' => 0, 'AvgFare' => 0, 'TotalRides' => 0];
$stmt = db_call_procedure('dbo.spGetAVFinancialSummary', [$startDate, $endDate]);
if ($stmt !== false) {
    $avSummary = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?? $avSummary;
    sqlsrv_free_stmt($stmt);
}

// Get daily revenue
$dailyData = [];
$stmt = db_call_procedure('dbo.spGetDailyRevenue', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dailyData[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get payment method breakdown
$paymentMethods = [];
$stmt = db_call_procedure('dbo.spGetPaymentMethodBreakdown', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $paymentMethods[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get top drivers
$topDrivers = [];
$stmt = db_call_procedure('dbo.spGetTopDrivers', [$startDate, $endDate, 10]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $topDrivers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get service type breakdown
$serviceTypes = [];
$stmt = db_call_procedure('dbo.spGetServiceTypeBreakdown', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $serviceTypes[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get top performing autonomous vehicles
$topAVs = [];
$stmt = db_call_procedure('dbo.spGetTopAutonomousVehicles', [$startDate, $endDate, 5]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $topAVs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get carshare financial summary
$carshareSummary = ['TotalRevenue' => 0, 'TotalRentals' => 0, 'ServiceFees' => 0, 'AvgRentalCost' => 0, 'TotalMinutesRented' => 0, 'TotalDistanceKm' => 0, 'UniqueCustomers' => 0];
$stmt = db_call_procedure('dbo.spGetCarshareFinancialSummary', [$startDate, $endDate]);
if ($stmt !== false) {
    $carshareSummary = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?? $carshareSummary;
    sqlsrv_free_stmt($stmt);
}

// Get carshare daily revenue
$carshareDailyData = [];
$stmt = db_call_procedure('dbo.spGetCarshareDailyRevenue', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $carshareDailyData[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get carshare vehicle type breakdown
$carshareVehicleTypes = [];
$stmt = db_call_procedure('dbo.spGetCarshareVehicleTypeBreakdown', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $carshareVehicleTypes[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get top carshare vehicles
$topCarshareVehicles = [];
$stmt = db_call_procedure('dbo.spGetTopCarshareVehicles', [$startDate, $endDate, 5]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $topCarshareVehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get top carshare customers
$topCarshareCustomers = [];
$stmt = db_call_procedure('dbo.spGetTopCarshareCustomers', [$startDate, $endDate, 5]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $topCarshareCustomers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get carshare zone breakdown
$carshareZones = [];
$stmt = db_call_procedure('dbo.spGetCarshareZoneBreakdown', [$startDate, $endDate]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $carshareZones[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Calculate combined totals (now including carshare)
$combinedRevenue = (float)($summary['TotalRevenue'] ?? 0) + (float)($avSummary['TotalRevenue'] ?? 0) + (float)($carshareSummary['TotalRevenue'] ?? 0);
$combinedServiceFees = (float)($summary['TotalServiceFees'] ?? 0) + (float)($avSummary['ServiceFees'] ?? 0) + (float)($carshareSummary['ServiceFees'] ?? 0);
$combinedTrips = (int)($summary['TotalTrips'] ?? 0) + (int)($avSummary['TotalRides'] ?? 0) + (int)($carshareSummary['TotalRentals'] ?? 0);

$pageTitle = 'Financial Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - OSRH</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container" style="margin: 2rem auto; max-width: 1200px;">
        <div class="card">
            <div class="card-header">
                <div>
                    <h1 class="card-title">Financial Reports</h1>
                    <p style="font-size: 0.86rem; margin-top: 0.25rem; color: var(--color-text-muted);">
                        Revenue tracking, service fee collection, and driver payout reports.
                    </p>
                </div>
            </div>

            <!-- Date Range Filter -->
            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.5rem; padding: 1rem; background: var(--color-surface); border: 1px solid var(--color-border-subtle); border-radius: 8px;">
                <div>
                    <label for="start_date" style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo e($startDate); ?>" class="form-control" style="padding: 0.5rem;">
                </div>
                <div>
                    <label for="end_date" style="display: block; font-size: 0.85rem; margin-bottom: 0.3rem;">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo e($endDate); ?>" class="form-control" style="padding: 0.5rem;">
                </div>
                <button type="submit" class="btn btn-primary">Generate Report</button>
                <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">This Month</a>
                <a href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">This Year</a>
            </form>

            <!-- Financial Summary Cards -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">📊 Combined Platform Revenue</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div style="background: var(--color-primary); color: white; padding: 1.25rem; border-radius: 12px;">
                    <div style="font-size: 0.85rem; opacity: 0.9;">Total Platform Revenue</div>
                    <div style="font-size: 1.8rem; font-weight: 700;"><?php echo fmt_currency($combinedRevenue); ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;"><?php echo $combinedTrips; ?> total trips/rides/rentals</div>
                </div>
                
                <div style="background: var(--color-success); color: white; padding: 1.25rem; border-radius: 12px;">
                    <div style="font-size: 0.85rem; opacity: 0.9;">Total Service Fees</div>
                    <div style="font-size: 1.8rem; font-weight: 700;"><?php echo fmt_currency($combinedServiceFees); ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;">0% platform commission</div>
                </div>
                
                <div style="background: var(--color-info); color: white; padding: 1.25rem; border-radius: 12px;">
                    <div style="font-size: 0.85rem; opacity: 0.9;">Driver Payouts</div>
                    <div style="font-size: 1.8rem; font-weight: 700;"><?php echo fmt_currency($summary['TotalDriverPayouts'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;"><?php echo $summary['ActiveDrivers'] ?? 0; ?> active drivers</div>
                </div>
                
                <div style="background: var(--color-warning); color: white; padding: 1.25rem; border-radius: 12px;">
                    <div style="font-size: 0.85rem; opacity: 0.9;">Average Fare</div>
                    <div style="font-size: 1.8rem; font-weight: 700;"><?php echo fmt_currency($combinedTrips > 0 ? $combinedRevenue / $combinedTrips : 0); ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;">across all ride types</div>
                </div>
            </div>

            <!-- Driver vs Autonomous vs Carshare Breakdown -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">🚗 Driver Trips vs 🤖 Autonomous Rides vs 🚙 CarShare Rentals</h3>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                <div style="background: var(--color-surface, #f8fafc); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
                    <h4 style="font-size: 0.95rem; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        🚗 Driver Trips
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Revenue</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #3b82f6;"><?php echo fmt_currency($summary['TotalRevenue'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Trips</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $summary['TotalTrips'] ?? 0; ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Service Fees</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #22c55e;"><?php echo fmt_currency($summary['TotalServiceFees'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Avg Fare</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo fmt_currency($summary['AvgFare'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                
                <div style="background: var(--color-surface, #f8fafc); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
                    <h4 style="font-size: 0.95rem; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        🤖 Autonomous Rides
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Revenue</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #8b5cf6;"><?php echo fmt_currency($avSummary['TotalRevenue'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Rides</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $avSummary['TotalRides'] ?? 0; ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Service Fees</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #22c55e;"><?php echo fmt_currency($avSummary['ServiceFees'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Avg Fare</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo fmt_currency($avSummary['AvgFare'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                
                <div style="background: var(--color-surface, #f8fafc); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem;">
                    <h4 style="font-size: 0.95rem; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                        🚙 CarShare Rentals
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Revenue</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #f59e0b;"><?php echo fmt_currency($carshareSummary['TotalRevenue'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Rentals</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $carshareSummary['TotalRentals'] ?? 0; ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Service Fees</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: #22c55e;"><?php echo fmt_currency($carshareSummary['ServiceFees'] ?? 0); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Avg Rental</div>
                            <div style="font-size: 1.25rem; font-weight: 700;"><?php echo fmt_currency($carshareSummary['AvgRentalCost'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Breakdown by Payment Method -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                        Revenue by Payment Method
                    </h3>
                    <?php if (empty($paymentMethods)): ?>
                        <p style="color: var(--color-text-muted);">No payment data available for this period.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Count</th>
                                        <th>Amount</th>
                                        <th>Fees</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <tr>
                                        <td>
                                            <?php echo e($method['MethodName']); ?>
                                        </td>
                                        <td><?php echo $method['PaymentCount']; ?></td>
                                        <td style="font-weight: 500;"><?php echo fmt_currency($method['TotalAmount']); ?></td>
                                        <td style="color: var(--color-success);"><?php echo fmt_currency($method['ServiceFees']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                        Revenue by Service Type
                    </h3>
                    <?php if (empty($serviceTypes)): ?>
                        <p style="color: var(--color-text-muted);">No service type data available for this period.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th>Trips</th>
                                        <th>Revenue</th>
                                        <th>Avg Fare</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($serviceTypes as $service): ?>
                                    <tr>
                                        <td><?php echo e($service['ServiceType']); ?></td>
                                        <td><?php echo $service['TripCount']; ?></td>
                                        <td style="font-weight: 500;"><?php echo fmt_currency($service['TotalRevenue']); ?></td>
                                        <td><?php echo fmt_currency($service['AvgFare']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Drivers -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                🚗 Top Drivers by Revenue
            </h3>
            <?php if (empty($topDrivers)): ?>
                <p style="color: var(--color-text-muted);">No driver data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto; margin-bottom: 2rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Driver</th>
                                <th>Trips</th>
                                <th>Gross Earnings</th>
                                <th>Platform Fee</th>
                                <th>Net Earnings</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topDrivers as $index => $driver): ?>
                            <tr>
                                <td>
                                    <?php echo $index + 1; ?>
                                </td>
                                <td>
                                    <a href="driver_details.php?id=<?php echo $driver['DriverID']; ?>">
                                        <?php echo e($driver['FirstName'] . ' ' . $driver['LastName']); ?>
                                    </a>
                                </td>
                                <td><?php echo $driver['TripCount']; ?></td>
                                <td><?php echo fmt_currency($driver['GrossEarnings']); ?></td>
                                <td style="color: var(--color-danger);">-<?php echo fmt_currency($driver['ServiceFeesPaid']); ?></td>
                                <td style="color: var(--color-success); font-weight: 600;"><?php echo fmt_currency($driver['NetEarnings']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Top Autonomous Vehicles -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                🤖 Top Autonomous Vehicles by Revenue
            </h3>
            <?php if (empty($topAVs)): ?>
                <p style="color: var(--color-text-muted);">No autonomous vehicle data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto; margin-bottom: 2rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vehicle</th>
                                <th>Make/Model</th>
                                <th>Rides</th>
                                <th>Gross Revenue</th>
                                <th>Service Fees</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topAVs as $index => $av): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <a href="autonomous_vehicle_detail.php?id=<?php echo $av['AutonomousVehicleID']; ?>">
                                        <?php echo e($av['VehicleCode']); ?>
                                    </a>
                                </td>
                                <td><?php echo e($av['Make'] . ' ' . $av['Model']); ?></td>
                                <td><?php echo $av['TripCount']; ?></td>
                                <td style="font-weight: 500;"><?php echo fmt_currency($av['GrossEarnings']); ?></td>
                                <td style="color: var(--color-success);"><?php echo fmt_currency($av['ServiceFees']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Daily Revenue -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                Daily Revenue Breakdown
            </h3>
            <?php if (empty($dailyData)): ?>
                <p style="color: var(--color-text-muted);">No daily data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payments</th>
                                <th>Daily Revenue</th>
                                <th>Service Fees</th>
                                <th>Driver Payouts</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dailyData as $day): 
                            $dayDate = $day['PaymentDay'] instanceof DateTime 
                                ? $day['PaymentDay']->format('D, M j, Y') 
                                : $day['PaymentDay'];
                        ?>
                            <tr>
                                <td><?php echo e($dayDate); ?></td>
                                <td><?php echo $day['PaymentCount']; ?></td>
                                <td style="font-weight: 500;"><?php echo fmt_currency($day['DailyRevenue']); ?></td>
                                <td style="color: var(--color-success);"><?php echo fmt_currency($day['DailyServiceFees']); ?></td>
                                <td><?php echo fmt_currency($day['DailyDriverPayouts']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- CARSHARE SECTION -->
            <!-- ============================================================ -->
            
            <hr style="margin: 2rem 0; border-color: var(--color-border-subtle);">
            
            <h2 style="font-size: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                🚙 CarShare Financial Details
            </h2>
            
            <!-- Carshare Summary Stats -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 1rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.75rem; opacity: 0.9;">Total Minutes</div>
                    <div style="font-size: 1.4rem; font-weight: 700;"><?php echo number_format($carshareSummary['TotalMinutesRented'] ?? 0); ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 1rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.75rem; opacity: 0.9;">Total Distance</div>
                    <div style="font-size: 1.4rem; font-weight: 700;"><?php echo number_format($carshareSummary['TotalDistanceKm'] ?? 0); ?> km</div>
                </div>
                <div style="background: linear-gradient(135deg, #6366f1, #4f46e5); color: white; padding: 1rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.75rem; opacity: 0.9;">Unique Customers</div>
                    <div style="font-size: 1.4rem; font-weight: 700;"><?php echo $carshareSummary['UniqueCustomers'] ?? 0; ?></div>
                </div>
                <div style="background: linear-gradient(135deg, #ec4899, #db2777); color: white; padding: 1rem; border-radius: 10px; text-align: center;">
                    <div style="font-size: 0.75rem; opacity: 0.9;">Active Vehicles</div>
                    <div style="font-size: 1.4rem; font-weight: 700;"><?php echo $carshareSummary['ActiveVehicles'] ?? 0; ?></div>
                </div>
            </div>
            
            <!-- Carshare Vehicle Types & Zones -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                        🚙 Revenue by Vehicle Type
                    </h3>
                    <?php if (empty($carshareVehicleTypes)): ?>
                        <p style="color: var(--color-text-muted);">No vehicle type data available for this period.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Rentals</th>
                                        <th>Revenue</th>
                                        <th>Avg Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($carshareVehicleTypes as $vt): ?>
                                    <tr>
                                        <td><?php echo e($vt['TypeName']); ?></td>
                                        <td><?php echo $vt['RentalCount']; ?></td>
                                        <td style="font-weight: 500;"><?php echo fmt_currency($vt['TotalRevenue']); ?></td>
                                        <td><?php echo fmt_currency($vt['AvgRentalCost']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                        📍 Revenue by Zone
                    </h3>
                    <?php if (empty($carshareZones)): ?>
                        <p style="color: var(--color-text-muted);">No zone data available for this period.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Zone</th>
                                        <th>Rentals</th>
                                        <th>Revenue</th>
                                        <th>Customers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($carshareZones as $zone): ?>
                                    <tr>
                                        <td>
                                            <?php echo e($zone['StartZone']); ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo e($zone['StartCity']); ?></span>
                                        </td>
                                        <td><?php echo $zone['RentalCount']; ?></td>
                                        <td style="font-weight: 500;"><?php echo fmt_currency($zone['TotalRevenue']); ?></td>
                                        <td><?php echo $zone['UniqueCustomers']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Top Carshare Vehicles -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                🏆 Top CarShare Vehicles by Revenue
            </h3>
            <?php if (empty($topCarshareVehicles)): ?>
                <p style="color: var(--color-text-muted);">No carshare vehicle data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto; margin-bottom: 2rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Plate</th>
                                <th>Vehicle</th>
                                <th>Type</th>
                                <th>Rentals</th>
                                <th>Revenue</th>
                                <th>Minutes</th>
                                <th>Distance</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topCarshareVehicles as $index => $cv): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo e($cv['PlateNumber']); ?></strong></td>
                                <td><?php echo e($cv['Make'] . ' ' . $cv['Model']); ?></td>
                                <td><?php echo e($cv['TypeName']); ?></td>
                                <td><?php echo $cv['RentalCount']; ?></td>
                                <td style="font-weight: 500; color: var(--color-success);"><?php echo fmt_currency($cv['GrossRevenue']); ?></td>
                                <td><?php echo number_format($cv['TotalMinutesUsed']); ?></td>
                                <td><?php echo number_format($cv['TotalDistanceKm']); ?> km</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Top Carshare Customers -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                👤 Top CarShare Customers
            </h3>
            <?php if (empty($topCarshareCustomers)): ?>
                <p style="color: var(--color-text-muted);">No carshare customer data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto; margin-bottom: 2rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Tier</th>
                                <th>Rentals</th>
                                <th>Total Spent</th>
                                <th>Minutes</th>
                                <th>Distance</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topCarshareCustomers as $index => $cust): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo e($cust['FullName']); ?></td>
                                <td>
                                    <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; background: <?php 
                                        echo match($cust['MembershipTier'] ?? 'standard') {
                                            'gold' => '#f59e0b',
                                            'platinum' => '#6366f1',
                                            'vip' => '#ec4899',
                                            default => '#6b7280'
                                        };
                                    ?>; color: white;">
                                        <?php echo e(ucfirst($cust['MembershipTier'] ?? 'standard')); ?>
                                    </span>
                                </td>
                                <td><?php echo $cust['RentalCount']; ?></td>
                                <td style="font-weight: 500; color: var(--color-success);"><?php echo fmt_currency($cust['TotalSpent']); ?></td>
                                <td><?php echo number_format($cust['TotalMinutesRented']); ?></td>
                                <td><?php echo number_format($cust['TotalDistanceKm']); ?> km</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Carshare Daily Revenue -->
            <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">
                📅 CarShare Daily Revenue
            </h3>
            <?php if (empty($carshareDailyData)): ?>
                <p style="color: var(--color-text-muted);">No carshare daily data available for this period.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Rentals</th>
                                <th>Revenue</th>
                                <th>Service Fees</th>
                                <th>Minutes</th>
                                <th>Distance</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($carshareDailyData as $day): 
                            $dayDate = $day['RentalDay'] instanceof DateTime 
                                ? $day['RentalDay']->format('D, M j, Y') 
                                : $day['RentalDay'];
                        ?>
                            <tr>
                                <td><?php echo e($dayDate); ?></td>
                                <td><?php echo $day['RentalCount']; ?></td>
                                <td style="font-weight: 500;"><?php echo fmt_currency($day['DailyRevenue']); ?></td>
                                <td style="color: var(--color-success);"><?php echo fmt_currency($day['DailyServiceFees']); ?></td>
                                <td><?php echo number_format($day['TotalMinutes']); ?></td>
                                <td><?php echo number_format($day['TotalDistance']); ?> km</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
