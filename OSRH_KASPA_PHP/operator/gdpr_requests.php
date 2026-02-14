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

$user        = current_user();
$operatorRow = $user['operator'] ?? null;

if (!$operatorRow || !isset($operatorRow['OperatorID'])) {
    redirect('error.php?code=403');
}

$operatorId = (int)$operatorRow['OperatorID'];

$errors = [];

// Handle POST: approve & delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $requestId = (int)array_get($_POST, 'request_id', 0);

        if ($requestId <= 0) {
            $errors['general'] = 'Invalid request.';
        }

        if (!$errors) {
            $stmt = db_call_procedure('dbo.spGetGDPRRequestStatus', [$requestId]);
            $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
            if ($stmt) sqlsrv_free_stmt($stmt);

            if (!$row) {
                $errors['general'] = 'GDPR request not found.';
            } elseif ((string)$row['Status'] !== 'pending') {
                $errors['general'] = 'This GDPR request is not pending.';
            }
        }

        if (!$errors) {
            $stmt = db_call_procedure('spProcessGdprDeletion', [
                $requestId,
                $operatorId,
            ]);

            if ($stmt === false) {
                $errors['general'] = 'Could not process GDPR request. Please try again.';
            } else {
                sqlsrv_free_stmt($stmt);
                flash_add('success', 'GDPR request processed, user anonymized and data cleaned.');
                redirect('operator/gdpr_requests.php');
            }
        }
    }
}

// Load pending and all requests
$stmt = db_call_procedure('dbo.spGetPendingGDPRRequests', []);
$pending = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pending[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

$stmt = db_call_procedure('dbo.spGetAllGDPRRequests', []);
$allRequests = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $allRequests[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

function osrh_dt_gdpr($v): string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i');
    }
    if ($v instanceof DateTime) {
        return $v->format('Y-m-d H:i');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'GDPR requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">GDPR requests</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                Handle “right to be forgotten” requests and record actions.
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-top:0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <h2 style="font-size:0.95rem;margin-top:1rem;">Pending requests</h2>

    <?php if (empty($pending)): ?>
        <p class="text-muted" style="font-size:0.84rem;margin-top:0.4rem;">
            There are no pending GDPR deletion requests.
        </p>
    <?php else: ?>
        <div style="overflow-x:auto;margin-top:0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Requested at</th>
                        <th>Reason</th>
                        <th style="width:140px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $r): ?>
                    <tr>
                        <td>#<?php echo e($r['RequestID']); ?></td>
                        <td><?php echo e($r['FullName']); ?> (ID <?php echo e($r['UserID']); ?>)</td>
                        <td><?php echo e($r['Email']); ?></td>
                        <td><?php echo e(osrh_dt_gdpr($r['RequestedAt'] ?? null)); ?></td>
                        <td style="max-width:260px;">
                            <?php echo $r['Reason'] ? nl2br(e($r['Reason'])) : '<span class="text-muted">–</span>'; ?>
                        </td>
                        <td>
                            <form method="post" id="gdpr-form-<?php echo e($r['RequestID']); ?>">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="request_id" value="<?php echo e($r['RequestID']); ?>">
                                <button type="button" class="btn btn-small btn-primary" onclick="confirmGdprDelete(<?php echo e($r['RequestID']); ?>, '<?php echo e(addslashes($r['FullName'])); ?>')">
                                    Approve &amp; delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 style="font-size:0.95rem;margin-top:1.8rem;">All GDPR requests</h2>

    <div style="overflow-x:auto;margin-top:0.6rem;">
        <table class="table">
            <thead>
                <tr>
                    <th>Request</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Requested at</th>
                    <th>Status</th>
                    <th>Completed at</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allRequests as $r): ?>
                <tr>
                    <td>#<?php echo e($r['RequestID']); ?></td>
                    <td><?php echo e($r['FullName']); ?> (ID <?php echo e($r['UserID']); ?>)</td>
                    <td><?php echo e($r['Email']); ?></td>
                    <td><?php echo e(osrh_dt_gdpr($r['RequestedAt'] ?? null)); ?></td>
                    <td><?php echo e($r['Status']); ?></td>
                    <td>
                        <?php
                        if (!empty($r['CompletedAt'])) {
                            echo e(osrh_dt_gdpr($r['CompletedAt']));
                        } else {
                            echo '<span class="text-muted" style="font-size:0.8rem;">–</span>';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmGdprDelete(requestId, userName) {
    OSRH.confirmDanger('This action will permanently anonymize the user "' + userName + '" and delete all their related personal data.\n\nThis action cannot be undone.', {
        icon: '🗑️',
        title: 'GDPR Data Deletion',
        confirmText: 'Delete Data',
        cancelText: 'Cancel'
    }).then(function(confirmed) {
        if (confirmed) {
            document.getElementById('gdpr-form-' + requestId).submit();
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
