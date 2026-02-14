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

$logs = [];
$stmt = db_call_procedure('spGetSystemLogs', [200]);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $logs[] = $row;
}
sqlsrv_free_stmt($stmt);

function osrh_dt_log($v): string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i');
    }
    if ($v instanceof DateTime) {
        return $v->format('Y-m-d H:i');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'System logs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">System logs</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                All operator actions: dispatches, safety inspections, and driver approvals (read-only).
            </p>
        </div>
    </div>

    <div class="card-body" style="padding:1rem 1.2rem 1.2rem;font-size:0.86rem;">
        <?php if (empty($logs)): ?>
            <p class="text-muted" style="font-size:0.84rem;">No system log entries found.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action Type</th>
                            <th>Operator</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Ref ID</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td><?php echo e(osrh_dt_log($row['CreatedAt'] ?? null)); ?></td>
                            <td>
                                <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.8rem;background:<?php 
                                    echo $row['ActionType'] === 'Dispatch' ? '#3b82f6' : 
                                         ($row['ActionType'] === 'Safety Inspection' ? '#10b981' : '#f59e0b'); 
                                ?>;color:white;">
                                    <?php echo e($row['ActionType']); ?>
                                </span>
                            </td>
                            <td><?php echo e($row['OperatorName'] ?? '–'); ?></td>
                            <td><?php echo e($row['ActionDescription']); ?></td>
                            <td>
                                <span style="padding:0.15rem 0.4rem;border-radius:3px;font-size:0.8rem;background:<?php 
                                    $status = strtolower($row['Status'] ?? '');
                                    echo ($status === 'approved' || $status === 'accepted' || $status === 'passed' || $status === 'completed') ? '#10b981' : 
                                         (($status === 'rejected' || $status === 'failed') ? '#ef4444' : '#6b7280');
                                ?>;color:white;">
                                    <?php echo e($row['Status'] ?? '–'); ?>
                                </span>
                            </td>
                            <td><?php echo e($row['ReferenceID'] ?? '–'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="text-muted" style="font-size:0.8rem;margin-top:0.9rem;">
            For audit purposes only. All operator actions including dispatch assignments, safety inspections, and driver verifications are recorded here.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

