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

$reportKey = (string)array_get($_GET, 'r', 'trip_analysis');
$allowedReports = [
    // Driver trips
    'trip_analysis',
    'trip_share',
    'peak_activity',
    'avg_cost',
    'extreme_costs',
    'driver_perf',
    'driver_earnings',
    // Autonomous rides
    'av_analysis',
    'av_vehicle_perf',
    // Carshare rentals
    'carshare_analysis',
    'carshare_vehicle_perf',
    'carshare_zone_perf',
];
if (!in_array($reportKey, $allowedReports, true)) {
    $reportKey = 'trip_analysis';
}

function osrh_fmt_amount($v): string
{
    if ($v === null) return '-';
    if (is_numeric($v)) return number_format((float)$v, 2);
    return (string)$v;
}

function osrh_dt_rep($v): string
{
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d H:i');
    if ($v instanceof DateTime) return $v->format('Y-m-d H:i');
    return $v ? (string)$v : '';
}

// Filters - raw values, database will validate
$filters = [
    'from_date'       => trim((string)array_get($_GET, 'from_date', '')),
    'to_date'         => trim((string)array_get($_GET, 'to_date', '')),
    'vehicle_types'   => array_get($_GET, 'vehicle_types', []),
    'postal_code'     => trim((string)array_get($_GET, 'postal_code', '')),
    'center_lat'      => trim((string)array_get($_GET, 'center_lat', '')),
    'center_lng'      => trim((string)array_get($_GET, 'center_lng', '')),
    'radius_km'       => trim((string)array_get($_GET, 'radius_km', '')),
    'group_by'        => trim((string)array_get($_GET, 'group_by', '')),
    'min_trips'       => trim((string)array_get($_GET, 'min_trips', '')),
    'top_n'           => trim((string)array_get($_GET, 'top_n', '10')),
];

$errors = [];

// Load vehicle types via stored procedure
$vehicleTypes = [];
$stmt = db_call_procedure('dbo.spGetVehicleTypes', []);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicleTypes[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Report data
$rows = [];

// Prepare parameters - pass dates in YYYY-MM-DD format for DATE type
// Default to last 30 days if not specified
$fromDateSql = null;
$toDateSql = null;

if ($filters['from_date'] !== '') {
    $fromDateSql = $filters['from_date']; // Just the date, no time
} else {
    // Default to 30 days ago
    $fromDateSql = date('Y-m-d', strtotime('-30 days'));
}

if ($filters['to_date'] !== '') {
    $toDateSql = $filters['to_date']; // Just the date, no time
} else {
    // Default to today
    $toDateSql = date('Y-m-d');
}

$vehicleTypeIdsSql = !empty($filters['vehicle_types']) ? implode(',', array_map('intval', $filters['vehicle_types'])) : null;
$postalCodeSql = $filters['postal_code'] !== '' ? $filters['postal_code'] : null;
$centerLatSql = $filters['center_lat'] !== '' ? $filters['center_lat'] : null;
$centerLngSql = $filters['center_lng'] !== '' ? $filters['center_lng'] : null;
$radiusKmSql = $filters['radius_km'] !== '' ? $filters['radius_km'] : null;
$groupBySql = $filters['group_by'] !== '' ? $filters['group_by'] : null;
$minTripsSql = $filters['min_trips'] !== '' ? $filters['min_trips'] : null;
$topNSql = $filters['top_n'] !== '' ? $filters['top_n'] : 10;

// Run appropriate stored procedure
switch ($reportKey) {
    case 'trip_analysis':
        // Prepare parameters with explicit SQL types for dates
        $params = [
            [$fromDateSql, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING('UTF-8'), SQLSRV_SQLTYPE_DATE],
            [$toDateSql, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING('UTF-8'), SQLSRV_SQLTYPE_DATE],
            $vehicleTypeIdsSql,
            $postalCodeSql,
            $centerLatSql,
            $centerLngSql,
            $radiusKmSql,
            $groupBySql
        ];
        
        // Debug logging
        error_log("Calling spReportTripAnalysis with params: fromDate=" . var_export($fromDateSql, true) . 
                  ", toDate=" . var_export($toDateSql, true));
        
        $stmt = db_call_procedure('dbo.spReportTripAnalysis', $params);
        if ($stmt === false) {
            $sqlErrors = sqlsrv_errors();
            $errorMsg = $sqlErrors ? $sqlErrors[0]['message'] : 'Unknown error';
            error_log("Report trip_analysis failed: " . $errorMsg);
            if ($sqlErrors) {
                error_log("Full SQL error: " . print_r($sqlErrors, true));
            }
            $errors['general'] = 'Could not run report: ' . $errorMsg;
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'trip_share':
        $stmt = db_call_procedure('dbo.spReportTripShareByVehicleType', [
            $fromDateSql, $toDateSql, $postalCodeSql, $centerLatSql, $centerLngSql, $radiusKmSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'peak_activity':
        $stmt = db_call_procedure('dbo.spReportPeakActivityByHour', [
            $fromDateSql, $toDateSql, $vehicleTypeIdsSql, $postalCodeSql, $groupBySql ?: 'hour'
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'avg_cost':
        $stmt = db_call_procedure('dbo.spReportAverageCostByVehicleType', [
            $fromDateSql, $toDateSql, $postalCodeSql, $centerLatSql, $centerLngSql, $radiusKmSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'extreme_costs':
        $stmt = db_call_procedure('dbo.spReportExtremeTripCosts', [
            $fromDateSql, $toDateSql, $topNSql, $vehicleTypeIdsSql, $postalCodeSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'driver_perf':
        $stmt = db_call_procedure('dbo.spReportDriverPerformance', [
            $fromDateSql, $toDateSql, $vehicleTypeIdsSql, $postalCodeSql, $minTripsSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'driver_earnings':
        $stmt = db_call_procedure('dbo.spReportAllDriversEarnings', [
            $fromDateSql, $toDateSql, $vehicleTypeIdsSql, $postalCodeSql, $groupBySql ?: 'driver'
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    // ============================================================
    // AUTONOMOUS VEHICLE REPORTS
    // ============================================================
    case 'av_analysis':
        $stmt = db_call_procedure('dbo.spReportAVAnalysis', [
            $fromDateSql, $toDateSql, $groupBySql ?: 'day'
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run autonomous vehicle analysis report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'av_vehicle_perf':
        $stmt = db_call_procedure('dbo.spReportAVVehiclePerformance', [
            $fromDateSql, $toDateSql, $topNSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run autonomous vehicle performance report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    // ============================================================
    // CARSHARE REPORTS
    // ============================================================
    case 'carshare_analysis':
        $stmt = db_call_procedure('dbo.spReportCarshareAnalysis', [
            $fromDateSql, $toDateSql, $groupBySql ?: 'day'
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run carshare analysis report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'carshare_vehicle_perf':
        $stmt = db_call_procedure('dbo.spReportCarshareVehiclePerformance', [
            $fromDateSql, $toDateSql, $topNSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run carshare vehicle performance report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;

    case 'carshare_zone_perf':
        $stmt = db_call_procedure('dbo.spReportCarshareZonePerformance', [
            $fromDateSql, $toDateSql
        ]);
        if ($stmt === false) {
            $errors['general'] = 'Could not run carshare zone performance report.';
        } else {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        break;
}

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1200px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Comprehensive reports</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Advanced analytics with filtering by time period, vehicle type, and location.
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-top:0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Driver Trips Reports -->
    <h3 style="font-size: 0.9rem; margin: 1rem 0 0.5rem 0; color: #3b82f6;">🚗 Driver Trip Reports</h3>
    <div class="tabs" style="display:flex;gap:0.4rem;flex-wrap:wrap;">
        <?php
        $driverTabs = [
            'trip_analysis'   => 'Trip analysis',
            'trip_share'      => 'Trip share by type',
            'peak_activity'   => 'Peak activity',
            'avg_cost'        => 'Average cost',
            'extreme_costs'   => 'Extreme costs',
            'driver_perf'     => 'Driver performance',
            'driver_earnings' => 'Driver earnings',
        ];
        foreach ($driverTabs as $key => $label): ?>
            <a href="<?php echo e(url('operator/reports.php?r=' . urlencode($key))); ?>"
               class="btn btn-small <?php echo $reportKey === $key ? 'btn-primary' : 'btn-ghost'; ?>">
                <?php echo e($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Autonomous Vehicle Reports -->
    <h3 style="font-size: 0.9rem; margin: 1rem 0 0.5rem 0; color: #8b5cf6;">🤖 Autonomous Vehicle Reports</h3>
    <div class="tabs" style="display:flex;gap:0.4rem;flex-wrap:wrap;">
        <?php
        $avTabs = [
            'av_analysis'     => 'AV ride analysis',
            'av_vehicle_perf' => 'AV vehicle performance',
        ];
        foreach ($avTabs as $key => $label): ?>
            <a href="<?php echo e(url('operator/reports.php?r=' . urlencode($key))); ?>"
               class="btn btn-small <?php echo $reportKey === $key ? 'btn-primary' : 'btn-ghost'; ?>">
                <?php echo e($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Carshare Reports -->
    <h3 style="font-size: 0.9rem; margin: 1rem 0 0.5rem 0; color: #f59e0b;">🚙 CarShare Reports</h3>
    <div class="tabs" style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.9rem;">
        <?php
        $carshareTabs = [
            'carshare_analysis'     => 'Rental analysis',
            'carshare_vehicle_perf' => 'Vehicle performance',
            'carshare_zone_perf'    => 'Zone performance',
        ];
        foreach ($carshareTabs as $key => $label): ?>
            <a href="<?php echo e(url('operator/reports.php?r=' . urlencode($key))); ?>"
               class="btn btn-small <?php echo $reportKey === $key ? 'btn-primary' : 'btn-ghost'; ?>">
                <?php echo e($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:1.2rem;">
        <form method="get" class="js-validate" novalidate style="margin-bottom:0.9rem;">
            <input type="hidden" name="r" value="<?php echo e($reportKey); ?>">

            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Time period</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="from_date">From date</label>
                        <input type="date" id="from_date" name="from_date" class="form-control" value="<?php echo e($filters['from_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="to_date">To date</label>
                        <input type="date" id="to_date" name="to_date" class="form-control" value="<?php echo e($filters['to_date']); ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Vehicle types</legend>
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
                    <?php foreach ($vehicleTypes as $vt): ?>
                        <label style="display:flex;align-items:center;gap:0.4rem;">
                            <input type="checkbox" name="vehicle_types[]" value="<?php echo e($vt['VehicleTypeID']); ?>"
                                <?php echo in_array((string)$vt['VehicleTypeID'], $filters['vehicle_types'], true) ? 'checked' : ''; ?>>
                            <?php echo e($vt['Name']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Location filters</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="postal_code">Postal code</label>
                        <input type="text" id="postal_code" name="postal_code" class="form-control" value="<?php echo e($filters['postal_code']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="center_lat">Center latitude</label>
                        <input type="text" id="center_lat" name="center_lat" class="form-control" value="<?php echo e($filters['center_lat']); ?>" placeholder="35.1667">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="center_lng">Center longitude</label>
                        <input type="text" id="center_lng" name="center_lng" class="form-control" value="<?php echo e($filters['center_lng']); ?>" placeholder="33.3667">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="radius_km">Radius (km)</label>
                        <input type="text" id="radius_km" name="radius_km" class="form-control" value="<?php echo e($filters['radius_km']); ?>" placeholder="10">
                    </div>
                </div>
            </fieldset>

            <?php if (in_array($reportKey, ['trip_analysis', 'peak_activity', 'driver_earnings', 'av_analysis', 'carshare_analysis'], true)): ?>
            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Grouping</legend>
                <div class="form-group">
                    <label class="form-label" for="group_by">Group by</label>
                    <select id="group_by" name="group_by" class="form-control">
                        <option value="">None</option>
                        <?php if ($reportKey === 'trip_analysis'): ?>
                            <option value="day" <?php echo $filters['group_by'] === 'day' ? 'selected' : ''; ?>>Day</option>
                            <option value="week" <?php echo $filters['group_by'] === 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $filters['group_by'] === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="quarter" <?php echo $filters['group_by'] === 'quarter' ? 'selected' : ''; ?>>Quarter</option>
                            <option value="year" <?php echo $filters['group_by'] === 'year' ? 'selected' : ''; ?>>Year</option>
                            <option value="vehicle_type" <?php echo $filters['group_by'] === 'vehicle_type' ? 'selected' : ''; ?>>Vehicle type</option>
                            <option value="postal_code" <?php echo $filters['group_by'] === 'postal_code' ? 'selected' : ''; ?>>Postal code</option>
                        <?php elseif ($reportKey === 'peak_activity'): ?>
                            <option value="hour" <?php echo $filters['group_by'] === 'hour' ? 'selected' : ''; ?>>Hour</option>
                            <option value="day" <?php echo $filters['group_by'] === 'day' ? 'selected' : ''; ?>>Day</option>
                            <option value="week" <?php echo $filters['group_by'] === 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $filters['group_by'] === 'month' ? 'selected' : ''; ?>>Month</option>
                        <?php elseif ($reportKey === 'driver_earnings'): ?>
                            <option value="driver" <?php echo $filters['group_by'] === 'driver' ? 'selected' : ''; ?>>Driver</option>
                            <option value="month" <?php echo $filters['group_by'] === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="year" <?php echo $filters['group_by'] === 'year' ? 'selected' : ''; ?>>Year</option>
                        <?php elseif ($reportKey === 'av_analysis'): ?>
                            <option value="day" <?php echo $filters['group_by'] === 'day' ? 'selected' : ''; ?>>Day</option>
                            <option value="week" <?php echo $filters['group_by'] === 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $filters['group_by'] === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="vehicle" <?php echo $filters['group_by'] === 'vehicle' ? 'selected' : ''; ?>>Vehicle</option>
                        <?php elseif ($reportKey === 'carshare_analysis'): ?>
                            <option value="day" <?php echo $filters['group_by'] === 'day' ? 'selected' : ''; ?>>Day</option>
                            <option value="week" <?php echo $filters['group_by'] === 'week' ? 'selected' : ''; ?>>Week</option>
                            <option value="month" <?php echo $filters['group_by'] === 'month' ? 'selected' : ''; ?>>Month</option>
                            <option value="vehicle_type" <?php echo $filters['group_by'] === 'vehicle_type' ? 'selected' : ''; ?>>Vehicle type</option>
                            <option value="zone" <?php echo $filters['group_by'] === 'zone' ? 'selected' : ''; ?>>Zone</option>
                        <?php endif; ?>
                    </select>
                </div>
            </fieldset>
            <?php endif; ?>

            <?php if ($reportKey === 'extreme_costs'): ?>
            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Options</legend>
                <div class="form-group">
                    <label class="form-label" for="top_n">Top N (highest/lowest)</label>
                    <input type="number" id="top_n" name="top_n" class="form-control" value="<?php echo e($filters['top_n']); ?>" min="1" max="100">
                </div>
            </fieldset>
            <?php endif; ?>

            <?php if (in_array($reportKey, ['av_vehicle_perf', 'carshare_vehicle_perf'], true)): ?>
            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Options</legend>
                <div class="form-group">
                    <label class="form-label" for="top_n">Top N vehicles</label>
                    <input type="number" id="top_n" name="top_n" class="form-control" value="<?php echo e($filters['top_n']); ?>" min="1" max="100">
                </div>
            </fieldset>
            <?php endif; ?>

            <?php if ($reportKey === 'driver_perf'): ?>
            <fieldset style="border:1px solid #e5e7eb;border-radius:6px;padding:1rem;margin-bottom:1rem;">
                <legend style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;">Options</legend>
                <div class="form-group">
                    <label class="form-label" for="min_trips">Minimum trips</label>
                    <input type="number" id="min_trips" name="min_trips" class="form-control" value="<?php echo e($filters['min_trips']); ?>" placeholder="Optional">
                </div>
            </fieldset>
            <?php endif; ?>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Run report</button>
                <a href="<?php echo e(url('operator/reports.php?r=' . urlencode($reportKey))); ?>" class="btn btn-ghost">Clear filters</a>
            </div>
        </form>

        <div style="margin-top:1.5rem;">
            <?php if ($reportKey === 'trip_analysis'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Trip analysis results</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Trip count</th>
                                <th>Avg distance (km)</th>
                                <th>Avg cost</th>
                                <th>Total revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['GroupLabel']); ?></td>
                                <td><?php echo e($row['TripCount']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgDistance'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgCost'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'trip_share'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Trip share by vehicle type</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vehicle type</th>
                                <th>Trip count</th>
                                <th>Percentage (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['VehicleTypeName']); ?></td>
                                <td><?php echo e($row['TripCount']); ?></td>
                                <td><?php echo e(number_format((float)$row['PercentageOfTotal'], 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'peak_activity'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Peak activity periods</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Period date</th>
                                <th>Hour</th>
                                <th>Trip count</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['PeriodDate'] ? osrh_dt_rep($row['PeriodDate']) : '–'); ?></td>
                                <td><?php echo e($row['PeriodHour'] ?? '–'); ?></td>
                                <td><?php echo e($row['TripCount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'avg_cost'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Average cost by vehicle type</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vehicle type</th>
                                <th>Avg cost</th>
                                <th>Trip count</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['VehicleTypeName']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgCost'])); ?></td>
                                <td><?php echo e($row['TripCount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'extreme_costs'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Extreme trip costs</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Trip ID</th>
                                <th>Driver</th>
                                <th>Passenger</th>
                                <th>Vehicle type</th>
                                <th>Amount</th>
                                <th>Start time</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><span style="font-weight:600;color:<?php echo $row['Category'] === 'HIGHEST' ? '#10b981' : '#ef4444'; ?>"><?php echo e($row['Category']); ?></span></td>
                                <td>#<?php echo e($row['TripID']); ?></td>
                                <td><?php echo e($row['DriverName']); ?></td>
                                <td><?php echo e($row['PassengerName']); ?></td>
                                <td><?php echo e($row['VehicleTypeName']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['Amount'])); ?></td>
                                <td><?php echo e(osrh_dt_rep($row['StartTime'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'driver_perf'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Driver performance</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Driver</th>
                                <th>Completed trips</th>
                                <th>Avg rating</th>
                                <th>Total earnings</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['DriverName']); ?> (ID <?php echo e($row['DriverID']); ?>)</td>
                                <td><?php echo e($row['CompletedTrips']); ?></td>
                                <td><?php echo $row['AvgRating'] !== null ? number_format((float)$row['AvgRating'], 2) : '<span class="text-muted">No ratings</span>'; ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalEarnings'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'driver_earnings'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">Driver earnings</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No data.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?php echo $filters['group_by'] === 'month' || $filters['group_by'] === 'year' ? 'Period' : 'Driver'; ?></th>
                                <th>Completed trips</th>
                                <th>Total earnings</th>
                                <th>Avg per trip</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['DriverName'] ?? $row['Period'] ?? '–'); ?></td>
                                <td><?php echo e($row['CompletedTrips']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalEarnings'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgEarningPerTrip'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php /* ========================================
                      AUTONOMOUS VEHICLE REPORTS
                   ======================================== */ ?>

            <?php elseif ($reportKey === 'av_analysis'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">🤖 Autonomous Vehicle Ride Analysis</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No autonomous ride data for this period.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Ride count</th>
                                <th>Avg distance (km)</th>
                                <th>Avg fare (€)</th>
                                <th>Total revenue (€)</th>
                                <th>Service fees (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['GroupLabel']); ?></td>
                                <td><?php echo e($row['RideCount']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgDistance'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgFare'])); ?></td>
                                <td style="font-weight:600;color:#10b981;"><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalServiceFees'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'av_vehicle_perf'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">🤖 Autonomous Vehicle Performance</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No autonomous vehicle data for this period.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vehicle code</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Total rides</th>
                                <th>Total km</th>
                                <th>Avg km/ride</th>
                                <th>Total revenue (€)</th>
                                <th>Avg fare (€)</th>
                                <th>Avg rating</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><code><?php echo e($row['VehicleCode']); ?></code></td>
                                <td><?php echo e($row['VehicleName']); ?></td>
                                <td><span class="badge badge-<?php echo $row['CurrentStatus'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo e($row['CurrentStatus']); ?></span></td>
                                <td><?php echo e($row['TotalRides']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalDistanceKm'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgDistancePerRide'])); ?></td>
                                <td style="font-weight:600;color:#10b981;"><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgFare'])); ?></td>
                                <td><?php echo $row['AvgRating'] > 0 ? number_format((float)$row['AvgRating'], 1) . ' ⭐' : '<span class="text-muted">–</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php /* ========================================
                      CARSHARE REPORTS
                   ======================================== */ ?>

            <?php elseif ($reportKey === 'carshare_analysis'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">🚙 CarShare Rental Analysis</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No carshare rental data for this period.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Rental count</th>
                                <th>Avg duration (min)</th>
                                <th>Avg distance (km)</th>
                                <th>Avg cost (€)</th>
                                <th>Total revenue (€)</th>
                                <th>Service fees (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['GroupLabel']); ?></td>
                                <td><?php echo e($row['RentalCount']); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgDurationMin'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgDistanceKm'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgCost'])); ?></td>
                                <td style="font-weight:600;color:#10b981;"><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalServiceFees'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'carshare_vehicle_perf'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">🚙 CarShare Vehicle Performance</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No carshare vehicle data for this period.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Plate</th>
                                <th>Vehicle</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Total rentals</th>
                                <th>Total minutes</th>
                                <th>Total km</th>
                                <th>Total revenue (€)</th>
                                <th>Avg rental (€)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><code><?php echo e($row['PlateNumber']); ?></code></td>
                                <td><?php echo e($row['VehicleName']); ?></td>
                                <td><?php echo e($row['TypeName']); ?></td>
                                <td><span class="badge badge-<?php echo $row['CurrentStatus'] === 'available' ? 'success' : 'secondary'; ?>"><?php echo e($row['CurrentStatus']); ?></span></td>
                                <td><?php echo e($row['TotalRentals']); ?></td>
                                <td><?php echo e(number_format($row['TotalMinutesUsed'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalDistanceKm'])); ?></td>
                                <td style="font-weight:600;color:#10b981;"><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['AvgRentalCost'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($reportKey === 'carshare_zone_perf'): ?>
                <h2 style="font-size:0.95rem;margin-bottom:0.5rem;">🚙 CarShare Zone Performance</h2>
                <?php if (empty($rows)): ?>
                    <p class="text-muted">No carshare zone data for this period.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Zone</th>
                                <th>City</th>
                                <th>Type</th>
                                <th>Rentals</th>
                                <th>Unique customers</th>
                                <th>Total minutes</th>
                                <th>Total km</th>
                                <th>Total revenue (€)</th>
                                <th>Intercity fees (€)</th>
                                <th>Vehicles</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong><?php echo e($row['ZoneName']); ?></strong></td>
                                <td><?php echo e($row['City']); ?></td>
                                <td><span class="badge badge-<?php 
                                    echo $row['ZoneType'] === 'premium' ? 'warning' : 
                                        ($row['ZoneType'] === 'airport' ? 'info' : 
                                        ($row['ZoneType'] === 'pink' ? 'secondary' : 'primary')); 
                                ?>"><?php echo e($row['ZoneType']); ?></span></td>
                                <td><?php echo e($row['TotalRentals']); ?></td>
                                <td><?php echo e($row['UniqueCustomers']); ?></td>
                                <td><?php echo e(number_format($row['TotalMinutesRented'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalDistanceKm'])); ?></td>
                                <td style="font-weight:600;color:#10b981;"><?php echo e(osrh_fmt_amount($row['TotalRevenue'])); ?></td>
                                <td><?php echo e(osrh_fmt_amount($row['TotalInterCityFees'])); ?></td>
                                <td><?php echo e($row['CurrentVehicles']); ?>/<?php echo e($row['MaxCapacity']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
