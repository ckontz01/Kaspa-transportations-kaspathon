<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';

require_login();
require_role('driver');

$user      = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    http_response_code(403);
    echo 'Driver profile not found.';
    exit;
}

$driverId = (int)$driverRow['DriverID'];
$userId = (int)$user['id'];
$errors = [];

// Get driver's Kaspa wallet
$kaspaWallet = kaspa_get_default_wallet($userId, 'receive');

// Handle Kaspa wallet update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_kaspa_wallet') {
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        flash_add('error', 'Security check failed. Please try again.');
    } else {
        $walletAddress = trim((string)array_get($_POST, 'kaspa_wallet', ''));
        $walletLabel = trim((string)array_get($_POST, 'wallet_label', '')) ?: 'Driver Earnings Wallet';
        
        if (empty($walletAddress)) {
            flash_add('error', 'Please enter a Kaspa wallet address.');
        } else {
            $result = kaspa_add_wallet($userId, $walletAddress, 'receive', $walletLabel, true);
            
            if ($result['success']) {
                flash_add('success', '💎 Kaspa wallet saved successfully! You can now receive payments in KAS.');
                redirect('driver/settings.php');
            } else {
                flash_add('error', $result['error'] ?? 'Failed to save wallet address.');
            }
        }
    }
}

// Get driver's vehicles
$vehicles = [];
$vehiclesStmt = db_call_procedure('dbo.spDriverGetVehicles', [$driverId]);
if ($vehiclesStmt !== false) {
    while ($row = sqlsrv_fetch_array($vehiclesStmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($vehiclesStmt);
}

// Get driver's current status
$driverStatus = null;
$statusStmt = db_call_procedure('dbo.spDriverGetCurrentStatus', [$driverId]);
if ($statusStmt !== false) {
    $driverStatus = sqlsrv_fetch_array($statusStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($statusStmt);
}

$isAvailable = !empty($driverStatus) ? (bool)$driverStatus['IsAvailable'] : false;
$currentLat = $driverStatus['CurrentLatitude'] ?? null;
$currentLng = $driverStatus['CurrentLongitude'] ?? null;
$activeVehicleId = $driverStatus['ActiveVehicleID'] ?? null;
$boundGeofenceName = $driverStatus['BoundGeofenceName'] ?? null;

// Handle GET action (after redirect from POST)
if (isset($_GET['do']) && isset($_GET['t'])) {
    $action = $_GET['do'];
    $token = $_GET['t'];
    $sessionToken = $_SESSION['driver_action_token'] ?? '';
    
    if ($token === $sessionToken && !empty($token)) {
        unset($_SESSION['driver_action_token']);
        
        if ($action === 'on') {
            $vehicleId = (int)($_SESSION['driver_pending_vid'] ?? 0);
            $lat = (float)($_SESSION['driver_pending_lat'] ?? 0);
            $lng = (float)($_SESSION['driver_pending_lng'] ?? 0);
            
            unset($_SESSION['driver_pending_vid'], $_SESSION['driver_pending_lat'], $_SESSION['driver_pending_lng']);
            
            if ($vehicleId > 0) {
                $resultStmt = db_call_procedure('dbo.spDriverGoOnline', [$driverId, $vehicleId, $lat, $lng]);
                
                if ($resultStmt !== false) {
                    $result = sqlsrv_fetch_array($resultStmt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($resultStmt);
                    
                    if (!empty($result['Success'])) {
                        $geofenceName = $result['GeofenceName'] ?? 'Unknown';
                        $geofenceName = str_replace('_', ' ', str_replace('_District', '', $geofenceName));
                        flash_add('success', "You are now available in {$geofenceName} district.");
                        if (isset($_SESSION['user']['driver'])) {
                            $_SESSION['user']['driver']['IsAvailable'] = 1;
                        }
                    } else {
                        flash_add('error', $result['ErrorMessage'] ?? 'Failed to set availability.');
                    }
                } else {
                    $sqlErrors = sqlsrv_errors();
                    $errorMsg = 'Database error: ';
                    if (!empty($sqlErrors)) {
                        $errorMsg .= $sqlErrors[0]['message'] ?? 'Unknown error';
                    }
                    flash_add('error', $errorMsg);
                }
            }
            redirect('driver/settings.php');
            
        } elseif ($action === 'off') {
            $resultStmt = db_call_procedure('dbo.spDriverGoOffline', [$driverId]);
            if ($resultStmt !== false) {
                sqlsrv_free_stmt($resultStmt);
                flash_add('info', 'You are now unavailable.');
                if (isset($_SESSION['user']['driver'])) {
                    $_SESSION['user']['driver']['IsAvailable'] = 0;
                }
            }
            redirect('driver/settings.php');
        }
    }
}

// Handle POST - just store in session and redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $action = trim((string)array_get($_POST, 'action', ''));
        
        if ($action === 'set_available') {
            $vehicleId = (int)array_get($_POST, 'vid', 0);
            $latRaw = array_get($_POST, 'lt', '');
            $lngRaw = array_get($_POST, 'lg', '');
            
            if ($vehicleId <= 0) {
                $errors['vehicle'] = 'Please select a vehicle.';
            }
            if (empty($latRaw) || empty($lngRaw)) {
                $errors['location'] = 'Please set your location on the map.';
            }
            
            if (!$errors) {
                $_SESSION['driver_pending_vid'] = $vehicleId;
                $_SESSION['driver_pending_lat'] = (float)$latRaw;
                $_SESSION['driver_pending_lng'] = (float)$lngRaw;
                $_SESSION['driver_action_token'] = bin2hex(random_bytes(16));
                redirect('driver/settings.php?do=on&t=' . $_SESSION['driver_action_token']);
            }
            
        } elseif ($action === 'set_unavailable') {
            $_SESSION['driver_action_token'] = bin2hex(random_bytes(16));
            redirect('driver/settings.php?do=off&t=' . $_SESSION['driver_action_token']);
        }
    }
}

$pageTitle = 'Driver Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="card" style="margin:2rem auto 1.5rem;max-width:900px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">Driver Settings</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Set your location, select your vehicle, and mark yourself as available to accept rides.
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin:1rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Current Status Card -->
    <div class="card" style="margin:1rem;<?php echo $isAvailable ? 'background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);' : 'background:rgba(156,163,175,0.1);border:1px solid rgba(156,163,175,0.3);'; ?>">
        <div style="padding:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
                <h3 style="font-size:1rem;margin:0 0 0.3rem;color:<?php echo $isAvailable ? '#22c55e' : '#6b7280'; ?>;">
                    <?php echo $isAvailable ? 'Available' : 'Unavailable'; ?>
                </h3>
                <?php if ($isAvailable && $boundGeofenceName): ?>
                    <p style="margin:0;font-size:0.85rem;color:#666;">
                        Operating in: <strong><?php echo e(str_replace('_', ' ', str_replace('_District', '', $boundGeofenceName))); ?></strong>
                    </p>
                <?php endif; ?>
            </div>
            <?php if ($isAvailable): ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="set_unavailable">
                    <button type="submit" class="btn btn-outline btn-small" style="border-color:#ef4444;color:#ef4444;">
                        Set Unavailable
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isAvailable): ?>
    <div class="card-body" style="padding:1rem 1.2rem 1.2rem;font-size:0.86rem;">
        <form method="post" id="availabilityForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="set_available">
            <input type="hidden" name="lt" id="lat_field" value="">
            <input type="hidden" name="lg" id="lng_field" value="">

            <?php if (empty($vehicles)): ?>
                <div class="flash flash-warning">
                    <span class="flash-text">You don't have any registered vehicles. Please register a vehicle first.</span>
                </div>
            <?php else: ?>
            
            <!-- Vehicle Selection -->
            <div class="form-group">
                <label class="form-label" for="vid">Select Vehicle</label>
                <select id="vid" name="vid" class="form-control" required>
                    <option value="">-- Select your vehicle --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <?php if ($v['IsActive']): ?>
                        <option value="<?php echo e($v['VehicleID']); ?>">
                            <?php echo e($v['PlateNo']); ?> - <?php echo e($v['VehicleTypeName']); ?>
                            <?php if ($v['Make'] || $v['Model']): ?>
                                (<?php echo e(trim($v['Make'] . ' ' . $v['Model'])); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['vehicle'])): ?>
                    <div class="form-error"><?php echo e($errors['vehicle']); ?></div>
                <?php endif; ?>
                <p style="font-size:0.82rem;color:#666;margin-top:0.3rem;">
                    Your vehicle will be bound to the district where you set your location.
                </p>
            </div>

            <!-- Location Map -->
            <div class="form-group">
                <label class="form-label">Your Location</label>
                <?php if (!empty($errors['location'])): ?>
                    <div class="form-error"><?php echo e($errors['location']); ?></div>
                <?php endif; ?>
                <p style="font-size:0.82rem;color:#666;margin-bottom:0.5rem;">
                    Drag the marker to your current location within a service area.
                </p>
                <div id="driverMap" style="height:350px;border:1px solid #ddd;border-radius:4px;"></div>
                <p style="font-size:0.85rem;color:#666;margin-top:0.5rem;">
                    Location: <span id="locationCoords">Drag the marker</span>
                </p>
            </div>

            <div class="form-group" style="margin-top:1.5rem;">
                <button type="submit" class="btn btn-primary" id="setAvailableBtn" disabled>
                    Set Available
                </button>
            </div>
            
            <?php endif; ?>
        </form>
    </div>
    <?php else: ?>
    <!-- Show current location when available -->
    <div class="card-body" style="padding:1rem 1.2rem 1.2rem;font-size:0.86rem;">
        <h3 style="font-size:0.95rem;margin-bottom:0.8rem;">Your Current Location</h3>
        <div id="driverMap" style="height:300px;border:1px solid #ddd;border-radius:4px;"></div>
    </div>
    <?php endif; ?>
</div>

<!-- Kaspa Wallet Section -->
<div class="card" style="margin:1.5rem auto;max-width:900px;">
    <div class="card-header" style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1) 0%, rgba(112, 184, 176, 0.1) 100%); border-bottom: 1px solid rgba(73, 234, 203, 0.3);">
        <div>
            <h2 class="card-title" style="display: flex; align-items: center; gap: 0.5rem;">
                <span style="color: #49EACB;">💎</span> Kaspa Wallet
            </h2>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Receive ride payments directly in Kaspa (KAS) cryptocurrency — <strong style="color: #49EACB;">0% platform fees!</strong>
            </p>
        </div>
    </div>
    <div class="card-body" style="padding:1.2rem;">
        <?php if ($kaspaWallet): ?>
            <div style="background: rgba(73, 234, 203, 0.1); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <div style="font-size: 0.8rem; color: #70B8B0; margin-bottom: 0.3rem;">
                            <?php echo e($kaspaWallet['Label'] ?? 'Your Kaspa Wallet'); ?>
                            <?php if ($kaspaWallet['IsVerified']): ?>
                                <span style="color: #10b981; margin-left: 0.3rem;">✓ Verified</span>
                            <?php endif; ?>
                        </div>
                        <code style="font-size: 0.85rem; color: #49EACB; word-break: break-all;">
                            <?php echo e($kaspaWallet['WalletAddress']); ?>
                        </code>
                    </div>
                    <a href="<?php echo e(kaspa_address_explorer_url($kaspaWallet['WalletAddress'])); ?>" 
                       target="_blank" 
                       style="font-size: 0.8rem; color: #70B8B0; text-decoration: none;">
                        View in Explorer →
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo e(url('driver/settings.php')); ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_kaspa_wallet">
            
            <div class="form-group">
                <label class="form-label" for="kaspa_wallet">
                    Kaspa Wallet Address
                    <span style="font-size: 0.8rem; color: #666; font-weight: normal;">(starts with kaspa:)</span>
                </label>
                <input 
                    type="text" 
                    id="kaspa_wallet" 
                    name="kaspa_wallet" 
                    class="form-control"
                    placeholder="kaspa:qp..."
                    value="<?php echo e($kaspaWallet['WalletAddress'] ?? ''); ?>"
                    style="font-family: Monaco, Menlo, monospace; font-size: 0.9rem;"
                >
                <p style="font-size: 0.8rem; color: #666; margin-top: 0.3rem;">
                    Enter your Kaspa wallet address to receive payments. Get a wallet from 
                    <a href="https://kasware.xyz" target="_blank" style="color: #49EACB;">KasWare</a> or 
                    <a href="https://kaspium.io" target="_blank" style="color: #49EACB;">Kaspium</a>.
                </p>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="wallet_label">Wallet Label (optional)</label>
                <input 
                    type="text" 
                    id="wallet_label" 
                    name="wallet_label" 
                    class="form-control"
                    placeholder="My Earnings Wallet"
                    value="<?php echo e($kaspaWallet['Label'] ?? ''); ?>"
                >
            </div>
            
            <div class="form-group" style="margin-top: 1rem;">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, #49EACB 0%, #70B8B0 100%); color: #1a1a2e; font-weight: 600;">
                    💎 Save Kaspa Wallet
                </button>
            </div>
        </form>
        
        <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 8px; font-size: 0.85rem;">
            <strong style="color: #22c55e;">✓ Why Kaspa?</strong>
            <ul style="margin: 0.5rem 0 0 1rem; color: #666; line-height: 1.6;">
                <li><strong>0% platform fees</strong> — keep 100% of your earnings</li>
                <li><strong>Instant settlements</strong> — receive payments in seconds</li>
                <li><strong>No intermediaries</strong> — direct peer-to-peer payments</li>
                <li><strong>Global</strong> — works anywhere, anytime</li>
            </ul>
        </div>
    </div>
</div>

<script>
(function() {
    var isAvailable = <?php echo $isAvailable ? 'true' : 'false'; ?>;
    var currentLat = <?php echo $currentLat ? (float)$currentLat : 'null'; ?>;
    var currentLng = <?php echo $currentLng ? (float)$currentLng : 'null'; ?>;
    
    var defaultLat = 35.1667;
    var defaultLng = 33.3667;
    
    var mapCenter = (currentLat && currentLng) ? [currentLat, currentLng] : [defaultLat, defaultLng];
    var mapZoom = (currentLat && currentLng) ? 13 : 9;
    
    var map = L.map('driverMap').setView(mapCenter, mapZoom);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: 'OpenStreetMap'
    }).addTo(map);
    
    // Load geofences
    var geofenceColors = {
        'Paphos_District': '#3b82f6',
        'Limassol_District': '#22c55e',
        'Larnaca_District': '#f97316',
        'Nicosia_District': '#8b5cf6'
    };
    
    fetch('../api/get_geofences.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.geofences) {
                data.geofences.forEach(function(g) {
                    if (g.points.length < 3) return;
                    var color = geofenceColors[g.name] || '#3b82f6';
                    var latlngs = g.points.map(function(p) { return [p.lat, p.lng]; });
                    latlngs.push(latlngs[0]);
                    L.polygon(latlngs, {
                        color: color, weight: 2, fillColor: color, fillOpacity: 0.1, interactive: false
                    }).addTo(map);
                });
            }
        });
    
    var marker = null;
    
    if (isAvailable && currentLat && currentLng) {
        marker = L.marker([currentLat, currentLng]).addTo(map);
    } else if (!isAvailable) {
        var latInput = document.getElementById('lat_field');
        var lngInput = document.getElementById('lng_field');
        var coordsSpan = document.getElementById('locationCoords');
        var submitBtn = document.getElementById('setAvailableBtn');
        var vehicleSelect = document.getElementById('vid');
        
        marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);
        
        function checkForm() {
            var hasLoc = latInput && latInput.value;
            var hasVeh = vehicleSelect && vehicleSelect.value;
            if (submitBtn) submitBtn.disabled = !(hasLoc && hasVeh);
        }
        
        function setLocation(lat, lng) {
            latInput.value = lat.toFixed(4);
            lngInput.value = lng.toFixed(4);
            coordsSpan.textContent = lat.toFixed(4) + ', ' + lng.toFixed(4);
            checkForm();
        }
        
        marker.on('dragend', function() {
            var p = marker.getLatLng();
            setLocation(p.lat, p.lng);
        });
        
        map.on('click', function(e) {
            marker.setLatLng(e.latlng);
            setLocation(e.latlng.lat, e.latlng.lng);
        });
        
        if (vehicleSelect) vehicleSelect.addEventListener('change', checkForm);
        
        setLocation(defaultLat, defaultLng);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
