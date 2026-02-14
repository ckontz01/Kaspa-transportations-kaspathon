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

$statusFilter = trim((string)array_get($_GET, 'status', 'pending'));
$allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)array_get($_POST, 'action', ''));
    $customerId = (int)array_get($_POST, 'customer_id', 0);
    $notes = trim((string)array_get($_POST, 'notes', ''));
    
    if ($customerId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        
        $sql = "UPDATE dbo.CarshareCustomer SET VerificationStatus = ?, VerificationNotes = ?, UpdatedAt = SYSDATETIME() WHERE CustomerID = ?";
        $stmt = db_query($sql, [$newStatus, $notes, $customerId]);
        
        if ($stmt !== false) {
            sqlsrv_free_stmt($stmt);
            flash_add('success', 'Customer has been ' . $newStatus . '.');
        } else {
            flash_add('error', 'Failed to update customer.');
        }
        redirect('operator/carshare_approvals.php?status=' . $statusFilter);
    }
}

// Check if table exists first
$tableExists = false;
$checkStmt = db_query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'CarshareCustomer'");
if ($checkStmt) {
    $tableExists = (sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) !== null);
    sqlsrv_free_stmt($checkStmt);
}

$customers = [];
$pendingCount = 0;

if ($tableExists) {
    $conn = db_get_connection();
    
    // Build WHERE clause based on status filter
    $whereClause = "";
    $queryParams = [];
    
    if ($statusFilter === 'pending') {
        $whereClause = "WHERE cc.VerificationStatus IN ('pending', 'documents_submitted')";
    } elseif ($statusFilter !== 'all') {
        $whereClause = "WHERE cc.VerificationStatus = ?";
        $queryParams[] = $statusFilter;
    }

    // First, get customers from CarshareCustomer table with JOINs
    $sql = "
        SELECT 
            cc.CustomerID, cc.PassengerID, cc.LicenseNumber, cc.LicenseCountry, 
            cc.LicenseExpiryDate, cc.DateOfBirth, cc.NationalID,
            cc.LicensePhotoUrl, cc.NationalIDPhotoUrl,
            cc.VerificationStatus, cc.VerificationNotes, cc.CreatedAt,
            u.FullName, u.Email, u.Phone
        FROM dbo.CarshareCustomer cc
        LEFT JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
        LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
        $whereClause
        ORDER BY cc.CreatedAt DESC
    ";

    $stmt = db_query($sql, $queryParams);
    
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Process user info
            $fullName = $row['FullName'] ?? 'Unknown User';
            $nameParts = explode(' ', $fullName, 2);
            
            $row['FirstName'] = $nameParts[0] ?? 'Unknown';
            $row['LastName'] = $nameParts[1] ?? '';
            $row['PhoneNumber'] = $row['Phone'] ?? '';
            
            // -- DEBUGGING --
            if ($fullName === 'Unknown User') {
                error_log("Carshare Debug: CustomerID {$row['CustomerID']} has PassengerID {$row['PassengerID']} but no user was found.");
            }
            // -- END DEBUGGING --

            $customers[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    // Count pending
    $countStmt = db_query("SELECT COUNT(*) AS cnt FROM dbo.CarshareCustomer WHERE VerificationStatus IN ('pending', 'documents_submitted')");
    if ($countStmt) {
        $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $pendingCount = (int)($countRow['cnt'] ?? 0);
        sqlsrv_free_stmt($countStmt);
    }
}

function format_date_cs($v): string {
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    return $v ? (string)$v : '-';
}

$pageTitle = 'CarShare Approvals';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1200px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">CarShare Approvals</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Review and approve customer applications for car-sharing service.
                <?php if ($pendingCount > 0): ?>
                <strong style="color:#f59e0b;"><?php echo $pendingCount; ?> pending</strong>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php flash_render(); ?>

    <?php if (!$tableExists): ?>
    <div style="padding:2rem;text-align:center;color:#f87171;">
        <p><strong>Database Error:</strong> CarshareCustomer table does not exist.</p>
        <p style="font-size:0.85rem;margin-top:0.5rem;">Run carshare_tables.sql to create it.</p>
    </div>
    <?php else: ?>

    <div style="margin:1rem 0;display:flex;gap:0.5rem;flex-wrap:wrap;">
        <a href="?status=pending" class="btn btn-small <?php echo $statusFilter === 'pending' ? 'btn-primary' : 'btn-ghost'; ?>">Pending</a>
        <a href="?status=approved" class="btn btn-small <?php echo $statusFilter === 'approved' ? 'btn-primary' : 'btn-ghost'; ?>">Approved</a>
        <a href="?status=rejected" class="btn btn-small <?php echo $statusFilter === 'rejected' ? 'btn-primary' : 'btn-ghost'; ?>">Rejected</a>
        <a href="?status=all" class="btn btn-small <?php echo $statusFilter === 'all' ? 'btn-primary' : 'btn-ghost'; ?>">All</a>
    </div>

    <div style="overflow-x:auto;margin-top:0.5rem;">
        <?php if (empty($customers)): ?>
            <p class="text-muted" style="font-size:0.84rem;padding:2rem;text-align:center;">
                No <?php echo $statusFilter !== 'all' ? e($statusFilter) : ''; ?> applications found.
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>License</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Documents</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo e($c['FirstName'] . ' ' . $c['LastName']); ?></td>
                        <td><?php echo e($c['Email']); ?></td>
                        <td><?php echo e($c['LicenseNumber']); ?> (<?php echo e($c['LicenseCountry']); ?>)</td>
                        <td><?php echo e(format_date_cs($c['LicenseExpiryDate'])); ?></td>
                        <td>
                            <?php 
                            $st = $c['VerificationStatus'];
                            $stColor = $st === 'approved' ? '#22c55e' : ($st === 'rejected' ? '#ef4444' : '#f59e0b');
                            ?>
                            <span style="color:<?php echo $stColor; ?>;font-weight:600;">
                                <?php echo e(ucfirst(str_replace('_', ' ', $st))); ?>
                            </span>
                        </td>
                        <td><?php echo e(format_date_cs($c['CreatedAt'])); ?></td>
                        <td>
                            <?php if (!empty($c['LicensePhotoUrl'])): ?>
                                <a href="<?php echo e(url($c['LicensePhotoUrl'])); ?>" target="_blank" style="font-size:0.8rem;">License</a>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">-</span>
                            <?php endif; ?>
                            <?php if (!empty($c['NationalIDPhotoUrl'])): ?>
                                | <a href="<?php echo e(url($c['NationalIDPhotoUrl'])); ?>" target="_blank" style="font-size:0.8rem;">ID</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($c['VerificationStatus'], ['pending', 'documents_submitted'])): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="customer_id" value="<?php echo (int)$c['CustomerID']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="notes" value="">
                                <button type="button" class="btn btn-small btn-primary" onclick="confirmApprove(this.form)">Approve</button>
                            </form>
                            <form method="POST" style="display:inline;margin-left:0.25rem;">
                                <input type="hidden" name="customer_id" value="<?php echo (int)$c['CustomerID']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="notes" value="">
                                <button type="button" class="btn btn-small btn-outline" style="color:#ef4444;border-color:#ef4444;" onclick="confirmReject(this.form)">Reject</button>
                            <script>
                            function confirmApprove(form) {
                                OSRH.confirm('Approve this customer?', {
                                    type: 'success',
                                    icon: '✅',
                                    title: 'Approve Customer',
                                    confirmText: 'Approve',
                                    cancelText: 'Cancel'
                                }).then(function(confirmed) {
                                    if (confirmed) {
                                        form.submit();
                                    }
                                });
                            }
                            function confirmReject(form) {
                                OSRH.confirm('Reject this customer?', {
                                    type: 'danger',
                                    icon: '❌',
                                    title: 'Reject Customer',
                                    confirmText: 'Reject',
                                    cancelText: 'Cancel',
                                    confirmClass: 'btn-danger'
                                }).then(function(confirmed) {
                                    if (confirmed) {
                                        form.submit();
                                    }
                                });
                            }
                            </script>
                            </form>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
