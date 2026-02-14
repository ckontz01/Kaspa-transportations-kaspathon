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

// Handle status update action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)array_get($_POST, 'action', ''));
    $vehicleId = (int)array_get($_POST, 'vehicle_id', 0);
    
    if ($vehicleId > 0) {
        $newStatus = null;
        if ($action === 'activate') {
            $newStatus = 'available';
        } elseif ($action === 'deactivate') {
            $newStatus = 'offline';
        } elseif ($action === 'maintenance') {
            $newStatus = 'maintenance';
        }
        
        if ($newStatus) {
            $stmt = db_call_procedure('dbo.spUpdateAutonomousVehicleStatus', [$vehicleId, $newStatus]);
            if ($stmt) {
                sqlsrv_free_stmt($stmt);
                flash_add('success', 'Vehicle status updated to ' . ucfirst($newStatus) . '.');
            } else {
                $errors = db_last_errors();
                $errorMsg = 'Failed to update vehicle status.';
                if ($errors && isset($errors[0]['message'])) {
                    $errorMsg = $errors[0]['message'];
                }
                flash_add('error', $errorMsg);
            }
        }
    }
    
    header('Location: ' . url('operator/autonomous_vehicles.php'));
    exit;
}

// Filter parameters
$statusFilter = trim((string)array_get($_GET, 'status', ''));
$search = trim((string)array_get($_GET, 'q', ''));

$allowedStatuses = ['', 'available', 'busy', 'maintenance', 'offline', 'charging'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Get all autonomous vehicles
$stmt = db_call_procedure('dbo.spGetAllAutonomousVehicles', []);
$vehicles = [];
$stats = ['total' => 0, 'available' => 0, 'busy' => 0, 'maintenance' => 0, 'offline' => 0, 'charging' => 0];

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $stats['total']++;
        $vehicleStatus = strtolower($row['Status'] ?? 'offline');
        if (isset($stats[$vehicleStatus])) {
            $stats[$vehicleStatus]++;
        }
        
        // Apply status filter
        if ($statusFilter !== '' && strtolower($row['Status'] ?? '') !== $statusFilter) {
            continue;
        }
        
        // Apply search filter
        if ($search !== '') {
            $searchLower = mb_strtolower($search, 'UTF-8');
            $searchFields = [
                $row['Make'] ?? '',
                $row['Model'] ?? '',
                $row['PlateNo'] ?? '',
                $row['VehicleCode'] ?? '',
                $row['Color'] ?? ''
            ];
            
            $found = false;
            foreach ($searchFields as $field) {
                if (mb_strpos(mb_strtolower($field, 'UTF-8'), $searchLower) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) continue;
        }
        
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

function formatLastPing($lastPing): string {
    if (!$lastPing) return '<span class="text-muted">Never</span>';
    
    if ($lastPing instanceof DateTime) {
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastPing->getTimestamp();
        
        if ($diff < 60) return '<span style="color:#22c55e;">Just now</span>';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        return $lastPing->format('Y-m-d H:i');
    }
    
    return (string)$lastPing;
}

function getStatusBadge(string $status): string {
    $colors = [
        'available' => '#22c55e',
        'busy' => '#f59e0b',
        'maintenance' => '#ef4444',
        'offline' => '#6b7280',
        'charging' => '#3b82f6'
    ];
    $color = $colors[strtolower($status)] ?? '#6b7280';
    return '<span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:4px;font-size:0.75rem;font-weight:600;background:' . $color . '20;color:' . $color . ';">' . e(ucfirst($status)) . '</span>';
}

function getBatteryIcon(?int $battery): string {
    if ($battery === null) return '<span class="text-muted">N/A</span>';
    if ($battery >= 80) return '<span style="color:#22c55e;">🔋 ' . $battery . '%</span>';
    if ($battery >= 50) return '<span style="color:#f59e0b;">🔋 ' . $battery . '%</span>';
    if ($battery >= 20) return '<span style="color:#ef4444;">🪫 ' . $battery . '%</span>';
    return '<span style="color:#ef4444;font-weight:bold;">⚠️ ' . $battery . '%</span>';
}

$pageTitle = 'Autonomous Vehicle Management';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}
.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}
.stat-card .stat-label {
    font-size: 0.75rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 0.25rem;
}
.vehicle-actions {
    display: flex;
    gap: 0.25rem;
    flex-wrap: wrap;
}
.vehicle-actions form {
    margin: 0;
}
</style>

<div class="card" style="margin:2rem auto 1.5rem;max-width:1200px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">🤖 Autonomous Vehicle Management</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Manage and monitor all autonomous vehicles in your system.
            </p>
        </div>
        <a href="<?php echo e(url('operator/autonomous_rides.php')); ?>" class="btn btn-primary btn-small">
            View All AV Rides
        </a>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Vehicles</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#22c55e;"><?php echo $stats['available']; ?></div>
            <div class="stat-label">Available</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#f59e0b;"><?php echo $stats['busy']; ?></div>
            <div class="stat-label">On Ride</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#3b82f6;"><?php echo $stats['charging']; ?></div>
            <div class="stat-label">Charging</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#ef4444;"><?php echo $stats['maintenance']; ?></div>
            <div class="stat-label">Maintenance</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:#6b7280;"><?php echo $stats['offline']; ?></div>
            <div class="stat-label">Offline</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="js-validate" novalidate style="margin-top:0.8rem;margin-bottom:0.8rem;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
            <div class="form-group">
                <label class="form-label" for="q">Search</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    class="form-control"
                    value="<?php echo e($search); ?>"
                    placeholder="Make, model, plate..."
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="">All</option>
                    <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="busy" <?php echo $statusFilter === 'busy' ? 'selected' : ''; ?>>On Ride</option>
                    <option value="charging" <?php echo $statusFilter === 'charging' ? 'selected' : ''; ?>>Charging</option>
                    <option value="maintenance" <?php echo $statusFilter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="offline" <?php echo $statusFilter === 'offline' ? 'selected' : ''; ?>>Offline</option>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-top:0.7rem;">
            <button type="submit" class="btn btn-primary btn-small">Filter</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="<?php echo e(url('operator/autonomous_vehicles.php')); ?>" class="btn btn-ghost btn-small">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Vehicles Table -->
    <div style="overflow-x:auto;margin-top:0.5rem;">
        <?php if (empty($vehicles)): ?>
            <p class="text-muted" style="font-size:0.84rem;">No vehicles found for the current filters.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Vehicle</th>
                        <th>Plate</th>
                        <th>Status</th>
                        <th>Battery</th>
                        <th>Zone</th>
                        <th>Last Update</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td><strong><?php echo e($v['VehicleCode'] ?? ''); ?></strong></td>
                        <td>
                            <?php echo e(($v['Make'] ?? '') . ' ' . ($v['Model'] ?? '')); ?>
                            <?php if (!empty($v['Color'])): ?>
                                <br><small class="text-muted"><?php echo e($v['Color']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($v['PlateNo'] ?? ''); ?></td>
                        <td><?php echo getStatusBadge($v['Status'] ?? 'offline'); ?></td>
                        <td><?php echo getBatteryIcon(isset($v['BatteryLevel']) ? (int)$v['BatteryLevel'] : null); ?></td>
                        <td><?php echo e($v['GeofenceName'] ?? 'Not assigned'); ?></td>
                        <td><?php echo formatLastPing($v['LocationUpdatedAt'] ?? null); ?></td>
                        <td>
                            <div class="vehicle-actions">
                                <?php $status = strtolower($v['Status'] ?? 'offline'); ?>
                                <?php $vid = (int)($v['AutonomousVehicleID'] ?? 0); ?>
                                
                                <?php if ($status === 'offline' || $status === 'maintenance'): ?>
                                    <form method="post">
                                        <input type="hidden" name="vehicle_id" value="<?php echo $vid; ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="btn btn-small btn-primary" title="Activate">
                                            ✓
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($status === 'available'): ?>
                                    <form method="post">
                                        <input type="hidden" name="vehicle_id" value="<?php echo $vid; ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn-small btn-ghost" title="Deactivate">
                                            ✗
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($status !== 'maintenance' && $status !== 'busy'): ?>
                                    <form method="post">
                                        <input type="hidden" name="vehicle_id" value="<?php echo $vid; ?>">
                                        <input type="hidden" name="action" value="maintenance">
                                        <button type="submit" class="btn btn-small btn-outline" title="Set Maintenance">
                                            🔧
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="<?php echo e(url('operator/autonomous_vehicle_detail.php?id=' . $vid)); ?>" 
                                   class="btn btn-small btn-ghost" title="View Details">
                                    👁️
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
