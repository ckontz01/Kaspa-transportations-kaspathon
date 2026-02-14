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

$search = trim((string)array_get($_GET, 'q', ''));
$status = trim((string)array_get($_GET, 'verification_status', ''));

$allowedStatuses = ['', 'pending', 'approved', 'rejected'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

// Call stored procedure with filters
$filterType = null;
$filterStatus = null;

if ($status !== '') {
    $filterStatus = $status;
}

$stmt = db_call_procedure('dbo.spGetDriversWithFilters', [$filterType, $filterStatus]);
$drivers = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Apply search filter if provided (SP doesn't handle search by name/email)
        if ($search !== '') {
            $searchLower = mb_strtolower($search, 'UTF-8');
            $fullNameLower = mb_strtolower($row['FullName'] ?? '', 'UTF-8');
            $emailLower = mb_strtolower($row['Email'] ?? '', 'UTF-8');
            
            if (mb_strpos($fullNameLower, $searchLower) === false && 
                mb_strpos($emailLower, $searchLower) === false) {
                continue; // Skip this row
            }
        }
        $drivers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Quick aggregate doc counts
$stmt = db_call_procedure('dbo.spGetDriverDocumentCounts', []);
$docByDriver = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $docByDriver[(int)$row['DriverID']] = (int)$row['Cnt'];
    }
    sqlsrv_free_stmt($stmt);
}

function osrh_dt_drivers($v): string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    if ($v instanceof DateTime) {
        return $v->format('Y-m-d');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'Drivers';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Drivers</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Overview of all drivers, with verification status, rating, and document counts.
            </p>
        </div>
    </div>

    <form method="get" class="js-validate" novalidate style="margin-top:0.8rem;margin-bottom:0.8rem;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label" for="q">Search (name or email)</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="form-control"
                    value="<?php echo e($search); ?>"
                    placeholder="e.g. John, john@ucy.ac.cy"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="verification_status">Verification status</label>
                <select id="verification_status" name="verification_status" class="form-control">
                    <option value="">All</option>
                    <option value="pending"  <?php echo $status === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-top:0.7rem;">
            <button type="submit" class="btn btn-primary btn-small">Filter</button>
        </div>
    </form>

    <div style="overflow-x:auto;margin-top:0.5rem;">
        <?php if (empty($drivers)): ?>
            <p class="text-muted" style="font-size:0.84rem;">No drivers found for the current filters.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Verification</th>
                        <th>Available</th>
                        <th>Rating</th>
                        <th>Documents</th>
                        <th>Joined</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $d): ?>
                    <tr>
                        <td><?php echo e($d['FullName']); ?> (ID <?php echo e($d['DriverID']); ?>)</td>
                        <td><?php echo e($d['Email']); ?></td>
                        <td><?php echo e($d['DriverType']); ?></td>
                        <td><?php echo e($d['VerificationStatus']); ?></td>
                        <td><?php echo !empty($d['IsAvailable']) ? 'Yes' : 'No'; ?></td>
                        <td>
                            <?php
                            if ($d['RatingAverage'] !== null) {
                                echo e(number_format((float)$d['RatingAverage'], 2));
                            } else {
                                echo '<span class="text-muted" style="font-size:0.8rem;">–</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $cnt = $docByDriver[(int)$d['DriverID']] ?? 0;
                            echo $cnt > 0 ? (int)$cnt : '<span class="text-muted" style="font-size:0.8rem;">0</span>';
                            ?>
                        </td>
                        <td><?php echo e(osrh_dt_drivers($d['CreatedAt'] ?? null)); ?></td>
                        <td>
                            <a href="<?php echo e(url('operator/driver_details.php?driver_id=' . urlencode((string)$d['DriverID']))); ?>"
                               class="btn btn-small btn-ghost">
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
