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

$driverId = (int)array_get($_GET, 'driver_id', 0);
if ($driverId <= 0) {
    redirect('error.php?code=404');
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)array_get($_POST, 'action', ''));
    $token = array_get($_POST, 'csrf_token', null);

    if (verify_csrf_token($token)) {
        if ($action === 'approve') {
            $stmt = db_call_procedure('spUpdateDriverVerificationStatus', [$driverId, 'approved']);
            if ($stmt !== false) {
                sqlsrv_free_stmt($stmt);
                flash_add('success', 'Driver has been approved.');
            } else {
                flash_add('error', 'Failed to approve driver.');
            }
            redirect('operator/driver_details.php?driver_id=' . $driverId);
        } elseif ($action === 'reject') {
            $stmt = db_call_procedure('spUpdateDriverVerificationStatus', [$driverId, 'rejected']);
            if ($stmt !== false) {
                sqlsrv_free_stmt($stmt);
                flash_add('warning', 'Driver has been rejected.');
            } else {
                flash_add('error', 'Failed to reject driver.');
            }
            redirect('operator/driver_details.php?driver_id=' . $driverId);
        } elseif ($action === 'approve_doc' || $action === 'reject_doc') {
            $docId = (int)array_get($_POST, 'doc_id', 0);
            $newStatus = ($action === 'approve_doc') ? 'approved' : 'rejected';
            if ($docId > 0) {
                $conn = db_get_connection();
                $sql = "UPDATE dbo.DriverDocument SET Status = ? WHERE DriverDocumentID = ? AND DriverID = ?";
                $stmt = sqlsrv_query($conn, $sql, [$newStatus, $docId, $driverId]);
                if ($stmt !== false) {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Document status updated to ' . $newStatus . '.');
                } else {
                    flash_add('error', 'Failed to update document status.');
                }
            }
            redirect('operator/driver_details.php?driver_id=' . $driverId);
        }
    }
}

$conn = db_get_connection();

// Get driver details using stored procedure
$driver = null;
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverDetails(?)}', [$driverId]);
if ($stmt) {
    $driver = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

if (!$driver) {
    redirect('error.php?code=404');
}

// Get vehicles using stored procedure
$vehicles = [];
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverVehiclesDetails(?)}', [$driverId]);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get driver documents using stored procedure
$driverDocs = [];
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverDocuments(?)}', [$driverId]);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $driverDocs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get vehicle documents using stored procedure
$vehicleDocs = [];
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverVehicleDocuments(?)}', [$driverId]);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicleDocs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Get safety inspections using stored procedure
$inspections = [];
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverSafetyInspections(?)}', [$driverId]);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $inspections[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Trip stats
$tripStats = null;
$stmt = sqlsrv_query($conn, '{CALL dbo.spGetDriverTripStats(?)}', [$driverId]);
if ($stmt) {
    $tripStats = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}
if (!$tripStats) {
    $tripStats = ['CompletedTrips' => 0, 'CancelledTrips' => 0, 'TotalTrips' => 0];
}

function osrh_dt_driver_details($v, bool $withTime = false): string
{
    if ($v instanceof DateTimeInterface) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    if ($v instanceof DateTime) {
        return $withTime ? $v->format('Y-m-d H:i') : $v->format('Y-m-d');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'Driver #' . $driverId;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1120px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                Driver: <?php echo e($driver['FullName']); ?> (ID <?php echo e($driver['DriverID']); ?>)
            </h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Detailed information about the driver, vehicles, documents, and safety inspections.
            </p>
        </div>
        <div>
            <a href="<?php echo e(url('operator/drivers.php')); ?>" class="btn btn-ghost btn-small">
                &larr; Back to drivers
            </a>
        </div>
    </div>

    <?php if ($driver['VerificationStatus'] === 'pending'): ?>
    <div class="card" style="margin-top:1rem;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);">
        <div style="padding:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
            <div>
                <h3 style="font-size:0.95rem;margin:0 0 0.3rem;color:#60a5fa;">⏳ Pending Approval</h3>
                <p style="margin:0;font-size:0.85rem;color:#93c5fd;">
                    This driver is waiting for verification. Review their details and documents, then approve or reject.
                </p>
            </div>
            <div style="display:flex;gap:0.6rem;flex-shrink:0;">
                <form method="post" style="display:inline;" id="approve-form">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="approve">
                    <button type="button" class="btn btn-primary btn-small" onclick="confirmApprove()">
                        ✓ Approve
                    </button>
                </form>
                <form method="post" style="display:inline;" id="reject-form">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="reject">
                    <button type="button" class="btn btn-outline btn-small" style="border-color:#ef4444;color:#ef4444;" onclick="confirmReject()">
                        ✕ Reject
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function confirmApprove() {
        OSRH.confirm('Are you sure you want to approve this driver?\n\nThey will be able to start accepting ride requests.', {
            type: 'success',
            icon: '✅',
            title: 'Approve Driver',
            confirmText: 'Approve',
            cancelText: 'Cancel'
        }).then(function(confirmed) {
            if (confirmed) {
                document.getElementById('approve-form').submit();
            }
        });
    }
    
    function confirmReject() {
        OSRH.confirm('Are you sure you want to reject this driver?\n\nThey will need to reapply to become a driver.', {
            type: 'danger',
            icon: '❌',
            title: 'Reject Driver',
            confirmText: 'Reject',
            cancelText: 'Cancel',
            confirmClass: 'btn-danger'
        }).then(function(confirmed) {
            if (confirmed) {
                document.getElementById('reject-form').submit();
            }
        });
    }
    </script>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.2rem;margin-top:1.1rem;">
        <!-- Driver summary -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Driver summary</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <?php if (!empty($driver['PhotoUrl'])): ?>
                    <div style="text-align:center;margin-bottom:1rem;">
                        <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($driver['PhotoUrl']))); ?>" target="_blank">
                            <img src="<?php echo e(url('operator/view_document.php?file=' . urlencode($driver['PhotoUrl']))); ?>" 
                                 alt="Driver Photo" 
                                 style="max-width:120px;max-height:120px;border-radius:8px;border:2px solid #e0e0e0;object-fit:cover;">
                        </a>
                        <div style="font-size:0.75rem;color:#666;margin-top:0.3rem;">Driver Photo</div>
                    </div>
                <?php endif; ?>
                <dl class="key-value-list">
                    <dt>Name</dt>
                    <dd><?php echo e($driver['FullName']); ?></dd>

                    <dt>Email</dt>
                    <dd><?php echo e($driver['Email']); ?></dd>

                    <dt>Phone</dt>
                    <dd>
                        <?php if (!empty($driver['Phone'])): ?>
                            <?php echo e($driver['Phone']); ?>
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </dd>

                    <dt>User status</dt>
                    <dd><?php echo e($driver['UserStatus']); ?></dd>

                    <dt>Driver type</dt>
                    <dd><?php echo e($driver['DriverType']); ?></dd>

                    <dt>Verification</dt>
                    <dd><?php echo e($driver['VerificationStatus']); ?></dd>

                    <dt>Availability</dt>
                    <dd><?php echo !empty($driver['IsAvailable']) ? 'Available' : 'Unavailable'; ?></dd>

                    <dt>Rating</dt>
                    <dd>
                        <?php
                        if ($driver['RatingAverage'] !== null) {
                            echo e(number_format((float)$driver['RatingAverage'], 2));
                        } else {
                            echo '<span class="text-muted">No ratings yet</span>';
                        }
                        ?>
                    </dd>

                    <dt>Joined</dt>
                    <dd><?php echo e(osrh_dt_driver_details($driver['CreatedAt'] ?? null)); ?></dd>
                </dl>

                <h3 style="font-size:0.9rem;margin-top:0.9rem;">Trip stats</h3>
                <dl class="key-value-list">
                    <dt>Completed trips</dt>
                    <dd><?php echo (int)$tripStats['CompletedTrips']; ?></dd>
                    <dt>Cancelled trips</dt>
                    <dd><?php echo (int)$tripStats['CancelledTrips']; ?></dd>
                    <dt>Total trips</dt>
                    <dd><?php echo (int)$tripStats['TotalTrips']; ?></dd>
                </dl>
            </div>
        </div>

        <!-- Vehicles -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Vehicles</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <?php if (empty($vehicles)): ?>
                    <p class="text-muted" style="font-size:0.84rem;">
                        This driver has no registered vehicles.
                    </p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Plate</th>
                                    <th>Type</th>
                                    <th>Capacity</th>
                                    <th>Color</th>
                                    <th>Active</th>
                                    <th>Photos</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vehicles as $v): ?>
                                <tr>
                                    <td><?php echo e($v['PlateNo']); ?></td>
                                    <td><?php echo e($v['VehicleTypeName']); ?></td>
                                    <td><?php echo e($v['SeatingCapacity'] ?? '−'); ?></td>
                                    <td><?php echo e($v['Color'] ?? '−'); ?></td>
                                    <td><?php echo !empty($v['IsActive']) ? 'Yes' : 'No'; ?></td>
                                    <td>
                                        <?php if (!empty($v['PhotosExterior'])): ?>
                                            <?php
                                            $extPhotos = json_decode($v['PhotosExterior'], true);
                                            if (is_array($extPhotos) && count($extPhotos) > 0):
                                                foreach ($extPhotos as $idx => $photo): ?>
                                                    <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($photo))); ?>" 
                                                       target="_blank"
                                                       class="btn btn-small btn-ghost" style="margin-right:0.25rem;margin-bottom:0.25rem;">
                                                        <?php echo count($extPhotos) > 1 ? 'Photo ' . ($idx + 1) : 'View'; ?>
                                                    </a>
                                                <?php endforeach;
                                            else: ?>
                                                <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($v['PhotosExterior']))); ?>"
                                                   target="_blank"
                                                   class="btn btn-small btn-ghost">
                                                    View
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No photos</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Documents & inspections -->
    <div style="display:grid;grid-template-columns:1fr;gap:1.2rem;margin-top:1.2rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Driver documents</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <?php if (empty($driverDocs)): ?>
                    <p class="text-muted" style="font-size:0.84rem;">No driver documents recorded.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Number</th>
                                    <th>Issue</th>
                                    <th>Expiry</th>
                                    <th>Status</th>
                                    <th>File</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($driverDocs as $doc): ?>
                                <tr>
                                    <td><?php echo e($doc['DocType']); ?></td>
                                    <td><?php echo e($doc['IdNumber']); ?></td>
                                    <td><?php echo e(osrh_dt_driver_details($doc['IssueDate'] ?? null)); ?></td>
                                    <td><?php echo e(osrh_dt_driver_details($doc['ExpiryDate'] ?? null)); ?></td>
                                    <td>
                                        <?php 
                                        $docStatus = $doc['Status'] ?? 'pending';
                                        $statusColors = [
                                            'pending' => 'background:#fef3c7;color:#92400e;',
                                            'approved' => 'background:#dcfce7;color:#166534;',
                                            'rejected' => 'background:#fee2e2;color:#991b1b;',
                                        ];
                                        $statusStyle = $statusColors[$docStatus] ?? '';
                                        ?>
                                        <span style="padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;<?php echo $statusStyle; ?>">
                                            <?php echo e(ucfirst($docStatus)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($doc['StorageUrl'])): ?>
                                            <?php
                                            // Check if it's a JSON array of files
                                            $files = json_decode($doc['StorageUrl'], true);
                                            if (is_array($files) && count($files) > 0):
                                                foreach ($files as $idx => $file): ?>
                                                    <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($file))); ?>" 
                                                       target="_blank" 
                                                       class="btn btn-small btn-ghost" style="margin-right:0.25rem;margin-bottom:0.25rem;">
                                                        <?php echo count($files) > 1 ? 'View ' . ($idx + 1) : 'View'; ?>
                                                    </a>
                                                <?php endforeach;
                                            else: ?>
                                                <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($doc['StorageUrl']))); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-small btn-ghost">
                                                    View
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not uploaded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($doc['StorageUrl']) && ($doc['Status'] ?? 'pending') === 'pending'): ?>
                                            <form method="post" style="display:inline;">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve_doc">
                                                <input type="hidden" name="doc_id" value="<?php echo e($doc['DriverDocumentID']); ?>">
                                                <button type="submit" class="btn btn-small btn-primary" style="padding:0.2rem 0.5rem;font-size:0.7rem;">✓</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject_doc">
                                                <input type="hidden" name="doc_id" value="<?php echo e($doc['DriverDocumentID']); ?>">
                                                <button type="submit" class="btn btn-small btn-outline" style="padding:0.2rem 0.5rem;font-size:0.7rem;border-color:#ef4444;color:#ef4444;">✕</button>
                                            </form>
                                        <?php elseif (($doc['Status'] ?? 'pending') === 'approved'): ?>
                                            <span style="color:#22c55e;font-size:0.8rem;">✓ Verified</span>
                                        <?php elseif (($doc['Status'] ?? 'pending') === 'rejected'): ?>
                                            <span style="color:#ef4444;font-size:0.8rem;">✕ Rejected</span>
                                        <?php else: ?>
                                            <span class="text-muted">−</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Vehicle documents</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <?php if (empty($vehicleDocs)): ?>
                    <p class="text-muted" style="font-size:0.84rem;">No vehicle documents recorded.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Doc type</th>
                                    <th>Number</th>
                                    <th>Issue</th>
                                    <th>Expiry</th>
                                    <th>File</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vehicleDocs as $doc): ?>
                                <tr>
                                    <td><?php echo e($doc['PlateNo']); ?></td>
                                    <td><?php echo e($doc['VehicleTypeName']); ?></td>
                                    <td><?php echo e($doc['DocType']); ?></td>
                                    <td><?php echo e($doc['IdNumber']); ?></td>
                                    <td><?php echo e(osrh_dt_driver_details($doc['IssueDate'] ?? null)); ?></td>
                                    <td><?php echo e(osrh_dt_driver_details($doc['ExpiryDate'] ?? null)); ?></td>
                                    <td>
                                        <?php if (!empty($doc['StorageUrl'])): ?>
                                            <?php
                                            // Check if it's a JSON array of files
                                            $files = json_decode($doc['StorageUrl'], true);
                                            if (is_array($files) && count($files) > 0):
                                                foreach ($files as $idx => $file): ?>
                                                    <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($file))); ?>" 
                                                       target="_blank" 
                                                       class="btn btn-small btn-ghost" style="margin-right:0.25rem;margin-bottom:0.25rem;">
                                                        <?php echo count($files) > 1 ? 'View ' . ($idx + 1) : 'View'; ?>
                                                    </a>
                                                <?php endforeach;
                                            else: ?>
                                                <a href="<?php echo e(url('operator/view_document.php?file=' . urlencode($doc['StorageUrl']))); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-small btn-ghost">
                                                    View
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">−</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="grid-column:1/-1;">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Safety inspections</h2>
            </div>
            <div class="card-body" style="padding:0.9rem 1rem 1rem;font-size:0.86rem;">
                <?php if (empty($inspections)): ?>
                    <p class="text-muted" style="font-size:0.84rem;">
                        No safety inspections recorded for this driver’s vehicles.
                    </p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($inspections as $row): ?>
                                <tr>
                                    <td><?php echo e(osrh_dt_driver_details($row['InspectionDate'] ?? null, true)); ?></td>
                                    <td><?php echo e($row['PlateNo']); ?></td>
                                    <td><?php echo e($row['VehicleTypeName']); ?></td>
                                    <td><?php echo e($row['Status']); ?></td>
                                    <td style="max-width:280px;">
                                        <?php if (!empty($row['Notes'])): ?>
                                            <?php echo nl2br(e($row['Notes'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">–</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
