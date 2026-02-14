<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/payments.php';

require_login();
require_role('driver');

$user      = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}

$driverId = (int)$driverRow['DriverID'];

// Get date range filter
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // Start of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Today

// Get earnings dashboard data from stored procedure
$totalGross = 0;
$totalServiceFee = 0;
$totalNet = 0;
$tripCount = 0;
$recentPayments = [];

// Call stored procedure for summary
$stmt = db_call_procedure('dbo.spDriverGetEarningsDashboard', [$driverId, $startDate, $endDate]);
if ($stmt !== false) {
    // First result set: Summary statistics
    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $totalGross = (float)($row['TotalGross'] ?? 0);
        $totalServiceFee = (float)($row['TotalServiceFee'] ?? 0);
        $totalNet = (float)($row['TotalNet'] ?? 0);
        $tripCount = (int)($row['TripCount'] ?? 0);
    }
    
    // Consume any remaining rows from first result set
    while (sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {}
    
    // Move to second result set (recent payments)
    if (sqlsrv_next_result($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $recentPayments[] = $row;
        }
    }
    
    sqlsrv_free_stmt($stmt);
}

// If second result set failed, call separate stored procedure for recent payments
if (empty($recentPayments)) {
    $stmt2 = db_call_procedure('dbo.spDriverGetRecentPayments', [$driverId, $startDate, $endDate]);
    if ($stmt2 !== false) {
        while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
            $recentPayments[] = $row;
        }
        sqlsrv_free_stmt($stmt2);
    }
}

// Get monthly and yearly earnings (original data)
$monthly = [];
$yearly  = [];

$stmt = db_call_procedure('dbo.spReportDriverEarnings', [$driverId]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $monthly[] = $row;
    }

    if (sqlsrv_next_result($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $yearly[] = $row;
        }
    }
    sqlsrv_free_stmt($stmt);
}

// Get detailed earnings with service fee breakdown
$earningsDetails = get_driver_earnings_summary($driverId, $startDate, $endDate);

function osrh_fmt_amount($v): string
{
    if ($v === null) {
        return '-';
    }
    if (is_numeric($v)) {
        return '€' . number_format((float)$v, 2);
    }
    return (string)$v;
}

function osrh_fmt_percent($v): string
{
    if ($v === null) {
        return '-';
    }
    return number_format((float)$v * 100, 0) . '%';
}

$pageTitle = 'Earnings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Earnings Dashboard</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                Track your earnings, service fees, and net income from completed trips.
            </p>
        </div>
    </div>

    <!-- Earnings Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1.5rem 0;">
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);">
            <div style="font-size: 0.85rem; opacity: 0.9;">Total Earnings</div>
            <div style="font-size: 1.8rem; font-weight: 700;"><?php echo osrh_fmt_amount($totalGross); ?></div>
            <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;">100% yours to keep!</div>
        </div>
        <div style="background: linear-gradient(135deg, #49EACB 0%, #70C7BA 100%); color: #0a2e2a; padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(73, 234, 203, 0.35); position: relative; overflow: hidden;">
            <div style="position: absolute; top: -10px; right: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%;"></div>
            <div style="font-size: 0.85rem; font-weight: 600;">Platform Fee</div>
            <div style="font-size: 1.8rem; font-weight: 700;">€0.00</div>
            <div style="font-size: 0.75rem; font-weight: 600; margin-top: 0.3rem;">✨ NO MIDDLEMAN FEE!</div>
        </div>
        <div style="background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: white; padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);">
            <div style="font-size: 0.85rem; opacity: 0.9;">Your Earnings</div>
            <div style="font-size: 1.8rem; font-weight: 700;"><?php echo osrh_fmt_amount($totalNet); ?></div>
            <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;">You keep everything!</div>
        </div>
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 1.25rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);">
            <div style="font-size: 0.85rem; opacity: 0.9;">Completed Trips</div>
            <div style="font-size: 1.8rem; font-weight: 700;"><?php echo $tripCount; ?></div>
            <div style="font-size: 0.75rem; opacity: 0.8; margin-top: 0.3rem;">Recent paid trips</div>
        </div>
    </div>

    <!-- No Middleman Fee Banner -->
    <div style="background: linear-gradient(135deg, #0a2e2a 0%, #134e4a 100%); border: 2px solid #49EACB; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; position: relative; overflow: hidden;">
        <div style="position: absolute; top: 0; right: 0; width: 150px; height: 150px; background: radial-gradient(circle, rgba(73, 234, 203, 0.15) 0%, transparent 70%);"></div>
        <div style="display: flex; align-items: center; gap: 1rem; position: relative; z-index: 1;">
            <div style="font-size: 2.5rem;">💎</div>
            <div>
                <div style="font-size: 1.1rem; font-weight: 700; color: #49EACB; text-transform: uppercase; letter-spacing: 1px;">
                    Zero Platform Fees
                </div>
                <p style="font-size: 0.9rem; color: #a7f3d0; margin: 0.3rem 0 0 0;">
                    Powered by <strong style="color: #49EACB;">Kaspa</strong> — keep <strong>100%</strong> of every fare. 
                    No commissions, no hidden charges. What passengers pay is what you earn!
                </p>
            </div>
        </div>
    </div>

    <!-- Recent Payments with Breakdown -->
    <h3 style="font-size: 1rem; margin-top: 1.5rem; margin-bottom: 0.75rem;">
        <span style="margin-right: 0.5rem;">💳</span>Recent Payments with Breakdown
    </h3>

    <?php if (empty($recentPayments)): ?>
        <p class="text-muted" style="font-size: 0.84rem;">
            No completed payments recorded yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Trip ID</th>
                        <th>Type</th>
                        <th>Gross Fare</th>
                        <th>Service Fee</th>
                        <th>Net Earnings</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentPayments as $payment): 
                    $isSegment = !empty($payment['IsSegmentPayment']);
                ?>
                    <tr>
                        <td><?php echo e($payment['PaymentID'] ?? 'N/A'); ?></td>
                        <td><?php echo e($payment['TripID'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($isSegment): ?>
                                <span style="display: inline-block; font-size: 0.7rem; padding: 0.15rem 0.4rem; background: #3b82f6; color: #fff; border-radius: 4px;">
                                    Segment
                                </span>
                            <?php else: ?>
                                <span style="display: inline-block; font-size: 0.7rem; padding: 0.15rem 0.4rem; background: #22c55e; color: #fff; border-radius: 4px;">
                                    Full Trip
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: 500;"><?php echo osrh_fmt_amount($payment['TotalFare'] ?? 0); ?></td>
                        <td style="color: #49EACB;">
                            €0.00
                            <div style="font-size: 0.7rem; color: #49EACB;">(0%)</div>
                        </td>
                        <td style="color: #28a745; font-weight: 600;"><?php echo osrh_fmt_amount($payment['DriverEarnings'] ?? 0); ?></td>
                        <td><?php echo e($payment['Status'] ?? 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3 style="font-size: 1rem; margin-top: 2rem; margin-bottom: 0.75rem;">
        <span style="margin-right: 0.5rem;">📅</span>Monthly Summary (current year)
    </h3>

    <?php if (empty($monthly)): ?>
        <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
            No completed trips with payments recorded for the current year yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto; margin-top: 0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Month</th>
                        <th>Completed Trips</th>
                        <th>Gross Earnings</th>
                        <th>Platform Fee</th>
                        <th>Net Earnings</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($monthly as $row): 
                    $grossEarnings = (float)($row['TotalEarnings'] ?? 0);
                    $serviceFee = 0;
                    $netEarnings = $grossEarnings;
                ?>
                    <tr>
                        <td><?php echo e($row['Year']); ?></td>
                        <td><?php echo e($row['Month']); ?></td>
                        <td><?php echo e($row['CompletedTrips']); ?></td>
                        <td><?php echo osrh_fmt_amount($grossEarnings); ?></td>
                        <td style="color: #49EACB;">€0.00</td>
                        <td style="color: #28a745; font-weight: 500;"><?php echo osrh_fmt_amount($netEarnings); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3 style="font-size: 1rem; margin-top: 2rem; margin-bottom: 0.75rem;">
        <span style="margin-right: 0.5rem;">📊</span>Yearly Totals (last 3 years)
    </h3>

    <?php if (empty($yearly)): ?>
        <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
            No earnings data for the last 3 years.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto; margin-top: 0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Completed Trips</th>
                        <th>Gross Earnings</th>
                        <th>Platform Fee</th>
                        <th>Net Earnings</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($yearly as $row): 
                    $grossEarnings = (float)($row['TotalEarnings'] ?? 0);
                    $serviceFee = 0;
                    $netEarnings = $grossEarnings;
                ?>
                    <tr>
                        <td><?php echo e($row['Year']); ?></td>
                        <td><?php echo e($row['CompletedTrips']); ?></td>
                        <td><?php echo osrh_fmt_amount($grossEarnings); ?></td>
                        <td style="color: #49EACB;">€0.00</td>
                        <td style="color: #28a745; font-weight: 600;"><?php echo osrh_fmt_amount($netEarnings); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Earnings Charts -->
    <div style="margin-top: 2rem;">
        <h3 style="font-size: 1rem; margin-bottom: 1rem;">
            <span style="margin-right: 0.5rem;">📈</span>Earnings Trends
        </h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- Monthly Earnings Chart -->
            <div style="background: #0b1120; padding: 1.5rem; border-radius: 12px; border: 1px solid #1e293b; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);">
                <h4 style="font-size: 0.9rem; margin-bottom: 1rem; color: #94a3b8;">Monthly Earnings (This Year)</h4>
                <canvas id="monthlyChart" style="max-height: 300px;"></canvas>
            </div>
            
            <!-- Yearly Earnings Chart -->
            <div style="background: #0b1120; padding: 1.5rem; border-radius: 12px; border: 1px solid #1e293b; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);">
                <h4 style="font-size: 0.9rem; margin-bottom: 1rem; color: #94a3b8;">Yearly Totals</h4>
                <canvas id="yearlyChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
        
        <!-- Earnings Breakdown Pie Chart -->
        <div style="background: #0b1120; padding: 1.5rem; border-radius: 12px; border: 1px solid #1e293b; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);">
            <h4 style="font-size: 0.9rem; margin-bottom: 1rem; color: #94a3b8;">Earnings Breakdown</h4>
            <div style="max-width: 400px; margin: 0 auto;">
                <canvas id="breakdownChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Chart.js global configuration for dark theme
Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = '#1e293b';
Chart.defaults.font.family = 'system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", sans-serif';

// Monthly Earnings Chart
<?php 
$monthLabels = [];
$monthGross = [];
$monthNet = [];
foreach ($monthly as $row) {
    $monthLabels[] = $row['Month'] ?? '';
    $gross = (float)($row['TotalEarnings'] ?? 0);
    $net = $gross;
    $monthGross[] = $gross;
    $monthNet[] = $net;
}
?>
const monthlyCtx = document.getElementById('monthlyChart');
if (monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($monthLabels); ?>,
            datasets: [{
                label: 'Gross Earnings',
                data: <?php echo json_encode($monthGross); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.15)',
                tension: 0.4,
                fill: true,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#0b1120',
                pointBorderWidth: 2
            }, {
                label: 'Net Earnings',
                data: <?php echo json_encode($monthNet); ?>,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.15)',
                tension: 0.4,
                fill: true,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#22c55e',
                pointBorderColor: '#0b1120',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            size: 12
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: '#0b1120',
                    titleColor: '#e5e7eb',
                    bodyColor: '#94a3b8',
                    borderColor: '#1e293b',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': €' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(30, 41, 59, 0.5)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return '€' + value.toFixed(0);
                        },
                        color: '#94a3b8'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#94a3b8'
                    }
                }
            }
        }
    });
}

// Yearly Earnings Chart
<?php 
$yearLabels = [];
$yearGross = [];
$yearNet = [];
foreach ($yearly as $row) {
    $yearLabels[] = $row['Year'] ?? '';
    $gross = (float)($row['TotalEarnings'] ?? 0);
    $net = $gross;
    $yearGross[] = $gross;
    $yearNet[] = $net;
}
?>
const yearlyCtx = document.getElementById('yearlyChart');
if (yearlyCtx) {
    new Chart(yearlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($yearLabels); ?>,
            datasets: [{
                label: 'Gross Earnings',
                data: <?php echo json_encode($yearGross); ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderColor: '#3b82f6',
                borderWidth: 2,
                borderRadius: 6
            }, {
                label: 'Net Earnings',
                data: <?php echo json_encode($yearNet); ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.8)',
                borderColor: '#22c55e',
                borderWidth: 2,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            size: 12
                        },
                        usePointStyle: true,
                        pointStyle: 'rect'
                    }
                },
                tooltip: {
                    backgroundColor: '#0b1120',
                    titleColor: '#e5e7eb',
                    bodyColor: '#94a3b8',
                    borderColor: '#1e293b',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': €' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(30, 41, 59, 0.5)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return '€' + value.toFixed(0);
                        },
                        color: '#94a3b8'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#94a3b8'
                    }
                }
            }
        }
    });
}

// Earnings Breakdown Pie Chart
const breakdownCtx = document.getElementById('breakdownChart');
if (breakdownCtx) {
    new Chart(breakdownCtx, {
        type: 'doughnut',
        data: {
            labels: ['Your Earnings (100%)', 'Platform Fee (0%)'],
            datasets: [{
                data: [<?php echo $totalNet; ?>, <?php echo $totalServiceFee; ?>],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(239, 68, 68, 0.8)'
                ],
                borderColor: [
                    '#22c55e',
                    '#ef4444'
                ],
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            size: 12
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: '#0b1120',
                    titleColor: '#e5e7eb',
                    bodyColor: '#94a3b8',
                    borderColor: '#1e293b',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return label + ': €' + value.toFixed(2) + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
