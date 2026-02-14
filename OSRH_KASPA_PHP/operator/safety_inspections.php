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

$errors = [
    'general'   => null,
    'inspection'=> [],
];

// Handle POST: record inspection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $action = (string)array_get($_POST, 'action', '');

        if ($action === 'add_inspection') {
            $vehicleId = (int)array_get($_POST, 'vehicle_id', 0);
            $status    = trim((string)array_get($_POST, 'status', ''));
            $notes     = trim((string)array_get($_POST, 'notes', ''));

            if ($vehicleId <= 0) {
                $errors['inspection']['vehicle_id'] = 'Please select a vehicle.';
            }

            $allowedStatuses = ['passed', 'failed', 'needs_followup'];
            if ($status === '' || !in_array($status, $allowedStatuses, true)) {
                $errors['inspection']['status'] = 'Please select a valid status.';
            }

            if (strlen($notes) > 1000) {
                $notes = substr($notes, 0, 1000);
            }

            if (!$errors['inspection'] && !$errors['general']) {
                $stmt = db_call_procedure('spRecordSafetyInspection', [
                    $vehicleId,
                    $status,
                    $notes !== '' ? $notes : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not record safety inspection. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Safety inspection recorded successfully.');
                    redirect('operator/safety_inspections.php');
                }
            }
        }
    }
}

// Load vehicles for dropdown (includes pending approval vehicles)
$vehicles = [];
$stmt = db_call_procedure('spGetVehiclesForInspection', []);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Load all inspections
$inspections = [];
$stmt = db_call_procedure('spGetRecentSafetyInspections', [100]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $inspections[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Categorize inspections
$pendingInspections = array_filter($inspections, fn($i) => $i['Status'] === 'pending');
$failedInspections = array_filter($inspections, fn($i) => $i['Status'] === 'failed');
$passedInspections = array_filter($inspections, fn($i) => $i['Status'] === 'passed');

// Categorize vehicles
$pendingVehicles = array_filter($vehicles, fn($v) => !empty($v['IsPendingApproval']));

// Get current tab
$activeTab = array_get($_GET, 'tab', 'pending');
if (!in_array($activeTab, ['pending', 'failed', 'all'], true)) {
    $activeTab = 'pending';
}

function osrh_format_dt_inspection($v): string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i');
    }
    if ($v instanceof DateTime) {
        return $v->format('Y-m-d H:i');
    }
    return $v ? (string)$v : '';
}

$pageTitle = 'Safety Inspections';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .inspection-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid var(--border-color, rgba(148, 163, 184, 0.15));
        padding-bottom: 0;
    }
    .inspection-tab {
        padding: 0.75rem 1.25rem;
        border: none;
        background: transparent;
        color: var(--color-text-muted, #94a3b8);
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    .inspection-tab:hover {
        color: var(--color-text, #e5e7eb);
        background: rgba(148, 163, 184, 0.05);
    }
    .inspection-tab.active {
        color: var(--color-text, #e5e7eb);
        border-bottom-color: #3b82f6;
    }
    .tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.5rem;
        height: 1.5rem;
        padding: 0 0.4rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .tab-badge-warning {
        background: rgba(245, 158, 11, 0.2);
        color: #fbbf24;
    }
    .tab-badge-danger {
        background: rgba(239, 68, 68, 0.2);
        color: #f87171;
    }
    .tab-badge-muted {
        background: rgba(148, 163, 184, 0.15);
        color: #94a3b8;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 500;
    }
    .status-pending {
        background: rgba(245, 158, 11, 0.15);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .status-failed {
        background: rgba(239, 68, 68, 0.15);
        color: #f87171;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
    .status-passed {
        background: rgba(34, 197, 94, 0.15);
        color: #4ade80;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .status-followup {
        background: rgba(59, 130, 246, 0.15);
        color: #60a5fa;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }
    
    .vehicle-card {
        background: var(--bg-secondary, rgba(15, 23, 42, 0.6));
        border: 1px solid var(--border-color, rgba(148, 163, 184, 0.1));
        border-radius: 0.75rem;
        padding: 1rem;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
    }
    .vehicle-card:hover {
        border-color: rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.8);
    }
    .vehicle-card-pending {
        border-left: 3px solid #f59e0b;
    }
    .vehicle-card-failed {
        border-left: 3px solid #ef4444;
    }
    
    .vehicle-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }
    .vehicle-plate {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--color-text, #e5e7eb);
    }
    .vehicle-type {
        font-size: 0.85rem;
        color: var(--color-text-muted, #94a3b8);
        margin-top: 0.15rem;
    }
    .vehicle-meta {
        display: flex;
        gap: 1rem;
        font-size: 0.82rem;
        color: var(--color-text-muted, #94a3b8);
    }
    .vehicle-notes {
        margin-top: 0.75rem;
        padding: 0.6rem;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 0.5rem;
        font-size: 0.82rem;
        color: var(--color-text-muted, #94a3b8);
    }
    .vehicle-actions {
        margin-top: 1rem;
        display: flex;
        gap: 0.5rem;
    }
    
    .action-btn {
        padding: 0.4rem 0.85rem;
        border-radius: 0.5rem;
        border: none;
        font-size: 0.82rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .action-btn-approve {
        background: rgba(34, 197, 94, 0.15);
        color: #4ade80;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .action-btn-approve:hover {
        background: rgba(34, 197, 94, 0.25);
    }
    .action-btn-fail {
        background: rgba(239, 68, 68, 0.15);
        color: #f87171;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
    .action-btn-fail:hover {
        background: rgba(239, 68, 68, 0.25);
    }
    .action-btn-followup {
        background: rgba(59, 130, 246, 0.15);
        color: #60a5fa;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }
    .action-btn-followup:hover {
        background: rgba(59, 130, 246, 0.25);
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--color-text-muted, #94a3b8);
    }
    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    .empty-state-text {
        font-size: 0.9rem;
    }
</style>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Safety Inspections</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Review and approve vehicle safety inspections. Vehicles pending approval are from new driver registrations.
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-top:0.8rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="inspection-tabs">
        <a href="?tab=pending" class="inspection-tab <?php echo $activeTab === 'pending' ? 'active' : ''; ?>">
            ⏳ Pending Approval
            <?php if (count($pendingInspections) > 0): ?>
                <span class="tab-badge tab-badge-warning"><?php echo count($pendingInspections); ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=failed" class="inspection-tab <?php echo $activeTab === 'failed' ? 'active' : ''; ?>">
            ✗ Failed
            <?php if (count($failedInspections) > 0): ?>
                <span class="tab-badge tab-badge-danger"><?php echo count($failedInspections); ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=all" class="inspection-tab <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
            📋 All Inspections
            <span class="tab-badge tab-badge-muted"><?php echo count($inspections); ?></span>
        </a>
    </div>

    <!-- Pending Tab Content -->
    <?php if ($activeTab === 'pending'): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 1rem;">
            <?php if (empty($pendingInspections)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">✓</div>
                    <div class="empty-state-text">No vehicles pending approval. All caught up!</div>
                </div>
            <?php else: ?>
                <?php foreach ($pendingInspections as $inspection): ?>
                    <div class="vehicle-card vehicle-card-pending">
                        <div class="vehicle-header">
                            <div>
                                <div class="vehicle-plate"><?php echo e($inspection['PlateNo']); ?></div>
                                <div class="vehicle-type"><?php echo e($inspection['VehicleTypeName']); ?></div>
                            </div>
                            <span class="status-badge status-pending">⏳ Pending</span>
                        </div>
                        <div class="vehicle-meta">
                            <span>📅 <?php echo e(osrh_format_dt_inspection($inspection['InspectionDate'] ?? null)); ?></span>
                        </div>
                        <?php if (!empty($inspection['Notes'])): ?>
                            <div class="vehicle-notes">
                                <?php echo nl2br(e($inspection['Notes'])); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" style="margin-top: 1rem;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_inspection">
                            <input type="hidden" name="vehicle_id" value="<?php echo e($inspection['VehicleID']); ?>">
                            
                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.8rem;">Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Inspection notes..." style="font-size: 0.85rem;"></textarea>
                            </div>
                            
                            <div class="vehicle-actions">
                                <button type="submit" name="status" value="passed" class="action-btn action-btn-approve">✓ Approve</button>
                                <button type="submit" name="status" value="failed" class="action-btn action-btn-fail">✗ Fail</button>
                                <button type="submit" name="status" value="needs_followup" class="action-btn action-btn-followup">⟳ Follow-up</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Failed Tab Content -->
    <?php if ($activeTab === 'failed'): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 1rem;">
            <?php if (empty($failedInspections)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">✓</div>
                    <div class="empty-state-text">No failed inspections. Great news!</div>
                </div>
            <?php else: ?>
                <?php foreach ($failedInspections as $inspection): ?>
                    <div class="vehicle-card vehicle-card-failed">
                        <div class="vehicle-header">
                            <div>
                                <div class="vehicle-plate"><?php echo e($inspection['PlateNo']); ?></div>
                                <div class="vehicle-type"><?php echo e($inspection['VehicleTypeName']); ?></div>
                            </div>
                            <span class="status-badge status-failed">✗ Failed</span>
                        </div>
                        <div class="vehicle-meta">
                            <span>📅 <?php echo e(osrh_format_dt_inspection($inspection['InspectionDate'] ?? null)); ?></span>
                        </div>
                        <?php if (!empty($inspection['Notes'])): ?>
                            <div class="vehicle-notes">
                                <?php echo nl2br(e($inspection['Notes'])); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" style="margin-top: 1rem;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="add_inspection">
                            <input type="hidden" name="vehicle_id" value="<?php echo e($inspection['VehicleID']); ?>">
                            
                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.8rem;">Re-inspection notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Re-inspection notes..." style="font-size: 0.85rem;"></textarea>
                            </div>
                            
                            <div class="vehicle-actions">
                                <button type="submit" name="status" value="passed" class="action-btn action-btn-approve">✓ Approve Now</button>
                                <button type="submit" name="status" value="needs_followup" class="action-btn action-btn-followup">⟳ Needs Follow-up</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- All Inspections Tab Content -->
    <?php if ($activeTab === 'all'): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <!-- Record New Inspection -->
            <div class="card" style="padding: 1rem;">
                <h3 style="font-size: 0.95rem; margin-bottom: 1rem; font-weight: 600;">Record New Inspection</h3>
                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_inspection">

                    <div class="form-group">
                        <label class="form-label" for="vehicle_id">Vehicle</label>
                        <select
                            id="vehicle_id"
                            name="vehicle_id"
                            class="form-control<?php echo !empty($errors['inspection']['vehicle_id']) ? ' input-error' : ''; ?>"
                            data-required="1"
                        >
                            <option value="">Select vehicle...</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo e($v['VehicleID']); ?>">
                                    <?php echo e($v['PlateNo']); ?> (<?php echo e($v['VehicleTypeName']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['inspection']['vehicle_id'])): ?>
                            <div class="form-error"><?php echo e($errors['inspection']['vehicle_id']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select
                            id="status"
                            name="status"
                            class="form-control<?php echo !empty($errors['inspection']['status']) ? ' input-error' : ''; ?>"
                            data-required="1"
                        >
                            <option value="">Select status...</option>
                            <option value="passed">✓ Passed</option>
                            <option value="failed">✗ Failed</option>
                            <option value="needs_followup">⟳ Needs follow-up</option>
                        </select>
                        <?php if (!empty($errors['inspection']['status'])): ?>
                            <div class="form-error"><?php echo e($errors['inspection']['status']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="notes">Notes (optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Inspection findings, issues, etc."
                        ></textarea>
                    </div>

                    <div class="form-group" style="margin-top:0.9rem;">
                        <button type="submit" class="btn btn-primary btn-small">Save Inspection</button>
                    </div>
                </form>
            </div>

            <!-- Stats Summary -->
            <div class="card" style="padding: 1rem;">
                <h3 style="font-size: 0.95rem; margin-bottom: 1rem; font-weight: 600;">Inspection Summary</h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                    <div style="text-align: center; padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; border: 1px solid rgba(245, 158, 11, 0.2);">
                        <div style="font-size: 2rem; font-weight: 700; color: #fbbf24;"><?php echo count($pendingInspections); ?></div>
                        <div style="font-size: 0.8rem; color: #fbbf24;">Pending</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 0.5rem; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <div style="font-size: 2rem; font-weight: 700; color: #f87171;"><?php echo count($failedInspections); ?></div>
                        <div style="font-size: 0.8rem; color: #f87171;">Failed</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 0.5rem; border: 1px solid rgba(34, 197, 94, 0.2);">
                        <div style="font-size: 2rem; font-weight: 700; color: #4ade80;"><?php echo count($passedInspections); ?></div>
                        <div style="font-size: 0.8rem; color: #4ade80;">Passed</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: rgba(148, 163, 184, 0.1); border-radius: 0.5rem; border: 1px solid rgba(148, 163, 184, 0.2);">
                        <div style="font-size: 2rem; font-weight: 700; color: #94a3b8;"><?php echo count($inspections); ?></div>
                        <div style="font-size: 0.8rem; color: #94a3b8;">Total</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Inspections Table -->
        <div style="margin-top: 1.5rem;">
            <h3 style="font-size: 0.95rem; margin-bottom: 1rem; font-weight: 600;">Inspection History</h3>
            <?php if (empty($inspections)): ?>
                <p class="text-muted" style="font-size:0.84rem;">
                    No safety inspections have been recorded yet.
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
                            <?php 
                                $statusClass = 'status-badge ';
                                $statusLabel = $row['Status'];
                                switch ($row['Status']) {
                                    case 'passed':
                                        $statusClass .= 'status-passed';
                                        $statusLabel = '✓ Passed';
                                        break;
                                    case 'failed':
                                        $statusClass .= 'status-failed';
                                        $statusLabel = '✗ Failed';
                                        break;
                                    case 'pending':
                                        $statusClass .= 'status-pending';
                                        $statusLabel = '⏳ Pending';
                                        break;
                                    case 'needs_followup':
                                        $statusClass .= 'status-followup';
                                        $statusLabel = '⟳ Follow-up';
                                        break;
                                }
                            ?>
                            <tr>
                                <td><?php echo e(osrh_format_dt_inspection($row['InspectionDate'] ?? null)); ?></td>
                                <td style="font-weight: 500;"><?php echo e($row['PlateNo']); ?></td>
                                <td><?php echo e($row['VehicleTypeName']); ?></td>
                                <td><span class="<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                <td style="max-width:260px;">
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
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

