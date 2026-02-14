<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

$user      = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}

$driverId = (int)$driverRow['DriverID'];
$errors = [];
$data = [
    'driver_type' => 'ride',
    'vehicle_type_id' => '',
    'plate_no' => '',
    'make' => '',
    'model' => '',
    'year' => '',
    'color' => '',
    'num_doors' => '4',
    'capacity' => '',
    'has_passenger_seat' => 1,
    'max_weight' => '',
    'cargo_volume' => '',
    'insurance_id' => '',
    'insurance_issue' => '',
    'insurance_expiry' => '',
    'registration_id' => '',
    'registration_issue' => '',
    'registration_expiry' => '',
    'mot_number' => '',
    'mot_issue' => '',
    'mot_expiry' => '',
    'classification_cert_number' => '',
    'classification_cert_issue' => '',
    'classification_cert_expiry' => '',
];

// Get vehicle types for dropdown
$vehicleTypes = [];
$vtStmt = db_call_procedure('dbo.spGetVehicleTypesByDriverType', [null]); // null = show all initially
if ($vtStmt) {
    while ($vt = sqlsrv_fetch_array($vtStmt, SQLSRV_FETCH_ASSOC)) {
        $vehicleTypes[] = $vt;
    }
    sqlsrv_free_stmt($vtStmt);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {
    $token = $_POST['csrf_token'] ?? null;
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        // Get form data
        $data['driver_type'] = trim($_POST['driver_type'] ?? 'ride');
        $data['vehicle_type_id'] = trim($_POST['vehicle_type_id'] ?? '');
        $data['plate_no'] = trim($_POST['plate_no'] ?? '');
        $data['make'] = trim($_POST['make'] ?? '');
        $data['model'] = trim($_POST['model'] ?? '');
        $data['year'] = trim($_POST['year'] ?? '');
        $data['color'] = trim($_POST['color'] ?? '');
        $data['num_doors'] = trim($_POST['num_doors'] ?? '4');
        $data['capacity'] = trim($_POST['capacity'] ?? '');
        $data['has_passenger_seat'] = isset($_POST['has_passenger_seat']) ? 1 : 0;
        $data['max_weight'] = trim($_POST['max_weight'] ?? '');
        $data['cargo_volume'] = trim($_POST['cargo_volume'] ?? '');
        
        // Document data
        $data['insurance_id'] = trim($_POST['insurance_id'] ?? '');
        $data['insurance_issue'] = trim($_POST['insurance_issue'] ?? '');
        $data['insurance_expiry'] = trim($_POST['insurance_expiry'] ?? '');
        $data['registration_id'] = trim($_POST['registration_id'] ?? '');
        $data['registration_issue'] = trim($_POST['registration_issue'] ?? '');
        $data['registration_expiry'] = trim($_POST['registration_expiry'] ?? '');
        $data['mot_number'] = trim($_POST['mot_number'] ?? '');
        $data['mot_issue'] = trim($_POST['mot_issue'] ?? '');
        $data['mot_expiry'] = trim($_POST['mot_expiry'] ?? '');
        $data['classification_cert_number'] = trim($_POST['classification_cert_number'] ?? '');
        $data['classification_cert_issue'] = trim($_POST['classification_cert_issue'] ?? '');
        $data['classification_cert_expiry'] = trim($_POST['classification_cert_expiry'] ?? '');
        
        // Validation
        if ($data['vehicle_type_id'] === '') $errors['vehicle_type_id'] = 'Vehicle type is required.';
        if ($data['plate_no'] === '') $errors['plate_no'] = 'Plate number is required.';
        if ($data['make'] === '') $errors['make'] = 'Vehicle make is required.';
        if ($data['model'] === '') $errors['model'] = 'Vehicle model is required.';
        if ($data['year'] === '' || !is_numeric($data['year']) || strlen($data['year']) !== 4) {
            $errors['year'] = 'Valid 4-digit year is required.';
        }
        if ($data['color'] === '') $errors['color'] = 'Vehicle color is required.';
        if ($data['capacity'] === '' || !is_numeric($data['capacity'])) {
            $errors['capacity'] = 'Valid seating capacity is required.';
        }
        
        // Document validation
        if ($data['insurance_id'] === '') $errors['insurance_id'] = 'Insurance policy number is required.';
        if ($data['insurance_issue'] === '') $errors['insurance_issue'] = 'Insurance issue date is required.';
        if ($data['insurance_expiry'] === '') $errors['insurance_expiry'] = 'Insurance expiry date is required.';
        if ($data['registration_id'] === '') $errors['registration_id'] = 'Registration number is required.';
        if ($data['registration_issue'] === '') $errors['registration_issue'] = 'Registration issue date is required.';
        if ($data['registration_expiry'] === '') $errors['registration_expiry'] = 'Registration expiry date is required.';
        if ($data['mot_number'] === '') $errors['mot_number'] = 'MOT number is required.';
        if ($data['mot_issue'] === '') $errors['mot_issue'] = 'MOT issue date is required.';
        if ($data['mot_expiry'] === '') $errors['mot_expiry'] = 'MOT expiry date is required.';
        if ($data['classification_cert_number'] === '') $errors['classification_cert_number'] = 'Classification certificate number is required.';
        if ($data['classification_cert_issue'] === '') $errors['classification_cert_issue'] = 'Classification certificate issue date is required.';
        if ($data['classification_cert_expiry'] === '') $errors['classification_cert_expiry'] = 'Classification certificate expiry date is required.';
        
        // File uploads validation
        $insuranceFile = $_FILES['insurance_file'] ?? null;
        if (!$insuranceFile || $insuranceFile['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['insurance_file'] = 'Insurance document is required.';
        }
        $registrationFile = $_FILES['registration_file'] ?? null;
        if (!$registrationFile || $registrationFile['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['registration_file'] = 'Registration document is required.';
        }
        $motFile = $_FILES['mot_file'] ?? null;
        if (!$motFile || $motFile['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['mot_file'] = 'MOT document is required.';
        }
        $classificationFile = $_FILES['classification_cert_file'] ?? null;
        if (!$classificationFile || $classificationFile['error'][0] === UPLOAD_ERR_NO_FILE) {
            $errors['classification_cert_file'] = 'Classification certificate is required.';
        }
        $exteriorPhotos = $_FILES['vehicle_photos_exterior'] ?? null;
        if (!$exteriorPhotos || $exteriorPhotos['error'][0] === UPLOAD_ERR_NO_FILE) {
            $errors['vehicle_photos_exterior'] = 'Vehicle exterior photos are required.';
        }
        $interiorPhotos = $_FILES['vehicle_photos_interior'] ?? null;
        if (!$interiorPhotos || $interiorPhotos['error'][0] === UPLOAD_ERR_NO_FILE) {
            $errors['vehicle_photos_interior'] = 'Vehicle interior photos are required.';
        }
        
        if (empty($errors)) {
            // Upload files
            $uploadDir = sys_get_temp_dir() . '/osrh_uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // File upload helper
            $uploadMultipleFiles = function($fileKey, $prefix) use ($uploadDir, &$errors) {
                $files = $_FILES[$fileKey] ?? null;
                if (!$files || !is_array($files['name'])) return null;
                
                $uploadedFiles = [];
                $fileCount = is_array($files['name']) ? count($files['name']) : 1;
                
                for ($i = 0; $i < $fileCount; $i++) {
                    $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    
                    if ($error === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '_' . $i . '.' . $ext;
                        $path = $uploadDir . $filename;
                        if (move_uploaded_file($tmpName, $path)) {
                            $uploadedFiles[] = $filename;
                        }
                    }
                }
                return count($uploadedFiles) > 0 ? json_encode($uploadedFiles) : null;
            };
            
            $insuranceStorageUrl = $uploadMultipleFiles('insurance_file', 'insurance');
            $registrationStorageUrl = $uploadMultipleFiles('registration_file', 'registration');
            $motStorageUrl = $uploadMultipleFiles('mot_file', 'mot');
            $classificationStorageUrl = $uploadMultipleFiles('classification_cert_file', 'classification');
            $exteriorUrl = $uploadMultipleFiles('vehicle_photos_exterior', 'vehicle_exterior');
            $interiorUrl = $uploadMultipleFiles('vehicle_photos_interior', 'vehicle_interior');
            
            // Call stored procedure
            $conn = db_get_connection();
            $sql = "EXEC dbo.spDriverAddVehicle 
                @DriverID = ?,
                @VehicleTypeID = ?,
                @PlateNo = ?,
                @Make = ?,
                @Model = ?,
                @Year = ?,
                @Color = ?,
                @NumDoors = ?,
                @SeatingCapacity = ?,
                @HasPassengerSeat = ?,
                @MaxWeightKg = ?,
                @CargoVolumeLiters = ?,
                @VehiclePhotosExterior = ?,
                @VehiclePhotosInterior = ?,
                @InsuranceNumber = ?,
                @InsuranceIssue = ?,
                @InsuranceExpiry = ?,
                @InsuranceStorageUrl = ?,
                @RegistrationNumber = ?,
                @RegistrationIssue = ?,
                @RegistrationExpiry = ?,
                @RegistrationStorageUrl = ?,
                @MotNumber = ?,
                @MotIssue = ?,
                @MotExpiry = ?,
                @MotStorageUrl = ?,
                @ClassificationNumber = ?,
                @ClassificationIssue = ?,
                @ClassificationExpiry = ?,
                @ClassificationStorageUrl = ?";
            
            $params = [
                $driverId,
                (int)$data['vehicle_type_id'],
                $data['plate_no'],
                $data['make'],
                $data['model'],
                (int)$data['year'],
                $data['color'],
                (int)$data['num_doors'],
                (int)$data['capacity'],
                $data['has_passenger_seat'],
                $data['max_weight'] !== '' ? (int)$data['max_weight'] : null,
                $data['cargo_volume'] !== '' ? (int)$data['cargo_volume'] : null,
                $exteriorUrl,
                $interiorUrl,
                $data['insurance_id'],
                $data['insurance_issue'],
                $data['insurance_expiry'],
                $insuranceStorageUrl,
                $data['registration_id'],
                $data['registration_issue'],
                $data['registration_expiry'],
                $registrationStorageUrl,
                $data['mot_number'],
                $data['mot_issue'],
                $data['mot_expiry'],
                $motStorageUrl,
                $data['classification_cert_number'],
                $data['classification_cert_issue'],
                $data['classification_cert_expiry'],
                $classificationStorageUrl
            ];
            
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt) {
                $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                
                if ($result && $result['Success']) {
                    flash_add('success', 'Vehicle added successfully! It will be inactive until an operator reviews and approves it.');
                    redirect('driver/vehicles.php');
                    exit;
                } else {
                    $errors['general'] = $result['Message'] ?? 'Failed to add vehicle.';
                }
            } else {
                $sqlErrors = sqlsrv_errors();
                $errors['general'] = 'Database error: ' . ($sqlErrors[0]['message'] ?? 'Unknown error');
            }
        }
    }
}

// Load vehicles for this driver using stored procedure
$stmt = db_call_procedure('dbo.spDriverGetVehicles', [$driverId]);
$vehicles = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $vehicles[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

$pageTitle = 'My vehicles';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 960px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">My vehicles</h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                View your registered vehicles and their operator-assigned types.
            </p>
        </div>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('addVehicleModal').style.display='flex'">
            ➕ Add New Vehicle
        </button>
    </div>

    <?php if (empty($vehicles)): ?>
        <p class="text-muted" style="font-size: 0.84rem; margin-top: 0.4rem;">
            You have not registered any vehicles yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto; margin-top: 0.6rem;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Plate</th>
                        <th>Type (Assigned by Operator)</th>
                        <th>Make / Model</th>
                        <th>Year</th>
                        <th>Color</th>
                        <th>Seats</th>
                        <th>Max weight (kg)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td><?php echo e($v['PlateNo']); ?></td>
                        <td><?php echo $v['VehicleTypeName'] ? e($v['VehicleTypeName']) : '<span class="text-muted" style="font-size:0.8rem;">Pending assignment</span>'; ?></td>
                        <td><?php echo e(trim(($v['Make'] ?? '') . ' ' . ($v['Model'] ?? ''))); ?></td>
                        <td><?php echo e($v['Year']); ?></td>
                        <td><?php echo e($v['Color']); ?></td>
                        <td><?php echo e($v['SeatingCapacity']); ?></td>
                        <td>
                            <?php
                            if ($v['MaxWeightKg'] !== null) {
                                echo e($v['MaxWeightKg']);
                            } else {
                                echo '<span class="text-muted" style="font-size:0.8rem;">–</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($v['IsActive'])): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-warning" title="Pending operator approval">⏳ Pending Review</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Add Vehicle Modal -->
<div id="addVehicleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: flex-start; overflow-y: auto; padding: 2rem 1rem;">
    <div class="card" style="max-width: 800px; width: 100%; margin: 0 auto;">
        <div class="card-header">
            <h2 class="card-title">Add New Vehicle</h2>
            <button type="button" onclick="document.getElementById('addVehicleModal').style.display='none'" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af;">&times;</button>
        </div>
        
        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error" style="margin: 1rem;">
                <?php echo e($errors['general']); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" enctype="multipart/form-data" style="padding: 1rem;">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="add_vehicle" value="1">
            
            <!-- Vehicle Type Selection -->
            <h3 style="font-size: 1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #334155);">📋 Vehicle Type</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Driver Type *</label>
                    <select name="driver_type" id="add_driver_type" class="form-control" onchange="filterVehicleTypesAdd()">
                        <option value="ride" <?php echo $data['driver_type'] === 'ride' ? 'selected' : ''; ?>>🚗 Passenger / Ride</option>
                        <option value="cargo" <?php echo $data['driver_type'] === 'cargo' ? 'selected' : ''; ?>>📦 Cargo / Delivery</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Vehicle Type *</label>
                    <select name="vehicle_type_id" id="add_vehicle_type_id" class="form-control <?php echo isset($errors['vehicle_type_id']) ? 'is-invalid' : ''; ?>" data-selected="<?php echo e($data['vehicle_type_id']); ?>">
                        <option value="">-- Select Vehicle Type --</option>
                    </select>
                    <?php if (isset($errors['vehicle_type_id'])): ?><div class="invalid-feedback"><?php echo e($errors['vehicle_type_id']); ?></div><?php endif; ?>
                </div>
            </div>
            
            <!-- Vehicle Information -->
            <h3 style="font-size: 1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color, #334155);">🚗 Vehicle Information</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Plate Number *</label>
                    <input type="text" name="plate_no" class="form-control <?php echo isset($errors['plate_no']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['plate_no']); ?>" placeholder="ABC 123">
                    <?php if (isset($errors['plate_no'])): ?><div class="invalid-feedback"><?php echo e($errors['plate_no']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Make *</label>
                    <input type="text" name="make" class="form-control <?php echo isset($errors['make']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['make']); ?>" placeholder="Toyota">
                    <?php if (isset($errors['make'])): ?><div class="invalid-feedback"><?php echo e($errors['make']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Model *</label>
                    <input type="text" name="model" class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['model']); ?>" placeholder="Corolla">
                    <?php if (isset($errors['model'])): ?><div class="invalid-feedback"><?php echo e($errors['model']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Year *</label>
                    <input type="number" name="year" class="form-control <?php echo isset($errors['year']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['year']); ?>" placeholder="2020" min="1990" max="<?php echo date('Y') + 1; ?>">
                    <?php if (isset($errors['year'])): ?><div class="invalid-feedback"><?php echo e($errors['year']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Color *</label>
                    <input type="text" name="color" class="form-control <?php echo isset($errors['color']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['color']); ?>" placeholder="White">
                    <?php if (isset($errors['color'])): ?><div class="invalid-feedback"><?php echo e($errors['color']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Number of Doors</label>
                    <input type="number" name="num_doors" class="form-control" value="<?php echo e($data['num_doors']); ?>" min="2" max="6">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Seating Capacity *</label>
                    <input type="number" name="capacity" class="form-control <?php echo isset($errors['capacity']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['capacity']); ?>" placeholder="5" min="1" max="50">
                    <?php if (isset($errors['capacity'])): ?><div class="invalid-feedback"><?php echo e($errors['capacity']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Max Weight (kg)</label>
                    <input type="number" name="max_weight" class="form-control" value="<?php echo e($data['max_weight']); ?>" placeholder="Optional">
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="has_passenger_seat" <?php echo $data['has_passenger_seat'] ? 'checked' : ''; ?>>
                    Has front passenger seat
                </label>
            </div>
            
            <!-- Vehicle Photos -->
            <h3 style="font-size: 1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e5e7eb;">📷 Vehicle Photos</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Exterior Photos * (multiple allowed)</label>
                    <input type="file" name="vehicle_photos_exterior[]" class="form-control <?php echo isset($errors['vehicle_photos_exterior']) ? 'is-invalid' : ''; ?>" accept="image/*" multiple>
                    <?php if (isset($errors['vehicle_photos_exterior'])): ?><div class="invalid-feedback"><?php echo e($errors['vehicle_photos_exterior']); ?></div><?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Interior Photos * (multiple allowed)</label>
                    <input type="file" name="vehicle_photos_interior[]" class="form-control <?php echo isset($errors['vehicle_photos_interior']) ? 'is-invalid' : ''; ?>" accept="image/*" multiple>
                    <?php if (isset($errors['vehicle_photos_interior'])): ?><div class="invalid-feedback"><?php echo e($errors['vehicle_photos_interior']); ?></div><?php endif; ?>
                </div>
            </div>
            
            <!-- Vehicle Documents -->
            <h3 style="font-size: 1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e5e7eb;">📄 Vehicle Documents</h3>
            
            <!-- Insurance -->
            <div style="background: var(--bg-secondary, #1e293b); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--border-color, #334155);">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.75rem;">🛡️ Insurance</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Policy Number *</label>
                        <input type="text" name="insurance_id" class="form-control <?php echo isset($errors['insurance_id']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['insurance_id']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issue Date *</label>
                        <input type="date" name="insurance_issue" class="form-control <?php echo isset($errors['insurance_issue']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['insurance_issue']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expiry Date *</label>
                        <input type="date" name="insurance_expiry" class="form-control <?php echo isset($errors['insurance_expiry']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['insurance_expiry']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document *</label>
                        <input type="file" name="insurance_file[]" class="form-control <?php echo isset($errors['insurance_file']) ? 'is-invalid' : ''; ?>" accept=".pdf,.jpg,.jpeg,.png" multiple>
                    </div>
                </div>
            </div>
            
            <!-- Registration -->
            <div style="background: var(--bg-secondary, #1e293b); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--border-color, #334155);">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.75rem;">📋 Vehicle Registration</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Registration Number *</label>
                        <input type="text" name="registration_id" class="form-control <?php echo isset($errors['registration_id']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['registration_id']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issue Date *</label>
                        <input type="date" name="registration_issue" class="form-control <?php echo isset($errors['registration_issue']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['registration_issue']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expiry Date *</label>
                        <input type="date" name="registration_expiry" class="form-control <?php echo isset($errors['registration_expiry']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['registration_expiry']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document *</label>
                        <input type="file" name="registration_file[]" class="form-control <?php echo isset($errors['registration_file']) ? 'is-invalid' : ''; ?>" accept=".pdf,.jpg,.jpeg,.png" multiple>
                    </div>
                </div>
            </div>
            
            <!-- MOT/Technical Inspection -->
            <div style="background: var(--bg-secondary, #1e293b); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--border-color, #334155);">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.75rem;">🔧 MOT / Technical Inspection</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label">MOT Number *</label>
                        <input type="text" name="mot_number" class="form-control <?php echo isset($errors['mot_number']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['mot_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issue Date *</label>
                        <input type="date" name="mot_issue" class="form-control <?php echo isset($errors['mot_issue']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['mot_issue']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expiry Date *</label>
                        <input type="date" name="mot_expiry" class="form-control <?php echo isset($errors['mot_expiry']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['mot_expiry']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document *</label>
                        <input type="file" name="mot_file[]" class="form-control <?php echo isset($errors['mot_file']) ? 'is-invalid' : ''; ?>" accept=".pdf,.jpg,.jpeg,.png" multiple>
                    </div>
                </div>
            </div>
            
            <!-- Classification Certificate -->
            <div style="background: var(--bg-secondary, #1e293b); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid var(--border-color, #334155);">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.75rem;">📜 Vehicle Classification Certificate</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Certificate Number *</label>
                        <input type="text" name="classification_cert_number" class="form-control <?php echo isset($errors['classification_cert_number']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['classification_cert_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issue Date *</label>
                        <input type="date" name="classification_cert_issue" class="form-control <?php echo isset($errors['classification_cert_issue']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['classification_cert_issue']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expiry Date *</label>
                        <input type="date" name="classification_cert_expiry" class="form-control <?php echo isset($errors['classification_cert_expiry']) ? 'is-invalid' : ''; ?>" value="<?php echo e($data['classification_cert_expiry']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document *</label>
                        <input type="file" name="classification_cert_file[]" class="form-control <?php echo isset($errors['classification_cert_file']) ? 'is-invalid' : ''; ?>" accept=".pdf,.jpg,.jpeg,.png" multiple>
                    </div>
                </div>
            </div>
            
            <div style="background: #fef3c7; border: 1px solid #f59e0b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <p style="margin: 0; font-size: 0.85rem; color: #92400e;">
                    ⚠️ <strong>Note:</strong> Your vehicle will be marked as "Pending Review" until an operator verifies your documents and approves it. You won't be able to accept rides with this vehicle until it's approved.
                </p>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addVehicleModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Vehicle for Review</button>
            </div>
        </form>
    </div>
</div>

<script>
// Vehicle types data from PHP
const vehicleTypes = <?php echo json_encode($vehicleTypes); ?>;

// Filter vehicle types based on driver type selection
function filterVehicleTypesAdd() {
    const driverType = document.getElementById('add_driver_type').value;
    const vehicleTypeSelect = document.getElementById('add_vehicle_type_id');
    const selectedValue = vehicleTypeSelect.getAttribute('data-selected') || vehicleTypeSelect.value;
    
    // Clear existing options
    vehicleTypeSelect.innerHTML = '<option value="">-- Select Vehicle Type --</option>';
    
    if (driverType) {
        vehicleTypes.forEach(function(vt) {
            if (vt.Category === driverType) {
                const option = document.createElement('option');
                option.value = vt.VehicleTypeID;
                option.textContent = vt.Name;
                if (vt.VehicleTypeID == selectedValue) {
                    option.selected = true;
                }
                vehicleTypeSelect.appendChild(option);
            }
        });
    }
    
    // Clear the data-selected after first use
    vehicleTypeSelect.removeAttribute('data-selected');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    filterVehicleTypesAdd();
});

// Show modal if there were errors
<?php if (!empty($errors) && isset($_POST['add_vehicle'])): ?>
document.getElementById('addVehicleModal').style.display = 'flex';
<?php endif; ?>

// Close modal when clicking outside
document.getElementById('addVehicleModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
