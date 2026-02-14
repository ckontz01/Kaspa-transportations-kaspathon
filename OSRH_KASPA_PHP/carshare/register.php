<?php
declare(strict_types=1);
/**
 * CARSHARE - Customer Registration Page
 * 
 * Allows passengers to register for the car-sharing service
 * by providing their driver's license details and uploading documents.
 * 
 * Document Storage Best Practice:
 * - Files are stored on filesystem in /uploads/carshare/documents/
 * - Only the file path is stored in the database
 * - Files are named with unique identifiers to prevent guessing
 * - Directory is outside web root or protected with .htaccess
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
if (is_operator()) {
    redirect('operator/carshare_approvals.php');
}
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId = (int)$passengerRow['PassengerID'];

// Check if already registered
$stmtCheck = db_query(
    "EXEC dbo.CarshareCheckCustomerRegistration ?",
    [$passengerId]
);

$existingCustomer = null;
if ($stmtCheck) {
    $existingCustomer = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtCheck);
}

if ($existingCustomer) {
    flash_add('info', 'You are already registered for car-sharing. Status: ' . ucfirst($existingCustomer['VerificationStatus']));
    redirect('carshare/request_vehicle.php');
}

// Document upload configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads/carshare/documents/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB (university server limit)
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

// Create upload directory if it doesn't exist
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    
    // Create .htaccess to protect directory (prevent direct access)
    $htaccess = UPLOAD_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|php5|phtml)$\">\nDeny from all\n</FilesMatch>");
    }
}

/**
 * Handle file upload securely
 */
function handleFileUpload(array $file, string $prefix): ?string {
    if (!isset($file['tmp_name']) || empty($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('File too large. Maximum size is 2MB.');
    }
    
    // Verify MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_TYPES)) {
        throw new Exception('Invalid file type. Allowed: JPG, PNG, WebP, PDF');
    }
    
    // Verify extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        throw new Exception('Invalid file extension.');
    }
    
    // Generate unique filename: prefix_timestamp_random.ext
    $uniqueName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = UPLOAD_DIR . $uniqueName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save uploaded file.');
    }
    
    // Return relative path for database storage
    return 'uploads/carshare/documents/' . $uniqueName;
}

$errors = [];
$formData = [
    'license_number' => '',
    'license_country' => 'Cyprus',
    'license_issue_date' => '',
    'license_expiry_date' => '',
    'date_of_birth' => '',
    'national_id' => '',
    'preferred_language' => 'en',
    'terms_accepted' => false
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if POST data was lost (server rejected large upload)
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $errors['general'] = 'The uploaded file was too large for the server. Please use a smaller file (under 2MB recommended).';
    } else {
        $formData['license_number'] = trim($_POST['license_number'] ?? '');
    $formData['license_country'] = trim($_POST['license_country'] ?? 'Cyprus');
    $formData['license_issue_date'] = trim($_POST['license_issue_date'] ?? '');
    $formData['license_expiry_date'] = trim($_POST['license_expiry_date'] ?? '');
    $formData['date_of_birth'] = trim($_POST['date_of_birth'] ?? '');
    $formData['national_id'] = trim($_POST['national_id'] ?? '');
    $formData['preferred_language'] = trim($_POST['preferred_language'] ?? 'en');
    $formData['terms_accepted'] = isset($_POST['terms_accepted']);
    
    // Validation
    if (empty($formData['license_number'])) {
        $errors['license_number'] = 'Driver\'s license number is required.';
    }
    
    if (empty($formData['license_issue_date'])) {
        $errors['license_issue_date'] = 'License issue date is required.';
    }
    
    if (empty($formData['license_expiry_date'])) {
        $errors['license_expiry_date'] = 'License expiry date is required.';
    } else {
        $expiryDate = strtotime($formData['license_expiry_date']);
        if ($expiryDate && $expiryDate < time()) {
            $errors['license_expiry_date'] = 'Your license has expired. Please renew it before registering.';
        }
    }
    
    if (empty($formData['date_of_birth'])) {
        $errors['date_of_birth'] = 'Date of birth is required.';
    } else {
        $dob = strtotime($formData['date_of_birth']);
        if ($dob) {
            $age = (int)((time() - $dob) / (365.25 * 24 * 60 * 60));
            if ($age < 18) {
                $errors['date_of_birth'] = 'You must be at least 18 years old to use car-sharing.';
            }
        }
    }
    
    // License photo is optional - can be uploaded later
    // Server may block large file uploads
    
    if (!$formData['terms_accepted']) {
        $errors['terms_accepted'] = 'You must accept the terms and conditions.';
    }
    
    // If no errors, process uploads and register
    if (empty($errors)) {
        $licensePhotoUrl = null;
        $nationalIdPhotoUrl = null;
        
        try {
            // Handle license photo upload
            if (isset($_FILES['license_photo']) && $_FILES['license_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $licensePhotoUrl = handleFileUpload($_FILES['license_photo'], 'license_' . $passengerId);
            }
            
            // Handle national ID photo upload (optional)
            if (isset($_FILES['national_id_photo']) && $_FILES['national_id_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $nationalIdPhotoUrl = handleFileUpload($_FILES['national_id_photo'], 'nationalid_' . $passengerId);
            }
            
            // Register customer with document URLs
            $stmt = db_call_procedure('dbo.spCarshareRegisterCustomer', [
                $passengerId,
                $formData['license_number'],
                $formData['license_country'],
                $formData['license_issue_date'],
                $formData['license_expiry_date'],
                $formData['date_of_birth'],
                $formData['national_id'] ?: null,
                $formData['preferred_language'],
                $licensePhotoUrl,
                $nationalIdPhotoUrl
            ]);
            
            if ($stmt) {
                $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);
                
                if ($result && isset($result['CustomerID'])) {
                    flash_add('success', 'Registration successful! Your documents are being reviewed. You will receive notification once approved.');
                    redirect('carshare/request_vehicle.php');
                } else {
                    $errors['general'] = 'Registration failed. Please try again.';
                }
            } else {
                $dbErrors = [];
                if (($errs = sqlsrv_errors()) !== null) {
                    foreach ($errs as $error) {
                        $dbErrors[] = $error['message'];
                    }
                }
                $errors['general'] = 'Registration failed: ' . implode(', ', $dbErrors);
            }
        } catch (Exception $e) {
            $errors['general'] = $e->getMessage();
        }
    }
    } // end else (POST data not lost)
}

$pageTitle = 'Register for Car Share';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.register-container {
    max-width: 600px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.register-card {
    background: rgba(15, 23, 42, 0.95);
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.1);
    padding: 2rem;
}

.register-header {
    text-align: center;
    margin-bottom: 2rem;
}

.register-header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
    background: linear-gradient(135deg, #f59e0b, #ef4444);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.register-header p {
    color: var(--color-text-muted);
    font-size: 0.95rem;
}

.form-section {
    margin-bottom: 1.5rem;
}

.form-section h3 {
    font-size: 1rem;
    color: var(--color-text);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 500px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.form-group label .required {
    color: #ef4444;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(15, 23, 42, 0.8);
    color: var(--color-text);
    font-size: 0.9rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}

.form-group input.error,
.form-group select.error {
    border-color: #ef4444;
}

.form-error {
    color: #ef4444;
    font-size: 0.8rem;
    margin-top: 0.3rem;
}

.checkbox-group {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-top: 0.15rem;
    accent-color: #f59e0b;
}

.checkbox-group label {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    line-height: 1.4;
}

.checkbox-group label a {
    color: #f59e0b;
    text-decoration: none;
}

.checkbox-group label a:hover {
    text-decoration: underline;
}

.btn-register {
    width: 100%;
    padding: 0.85rem;
    background: linear-gradient(135deg, #f59e0b, #ef4444);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.btn-register:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
}

.btn-register:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #fca5a5;
}

.alert-info {
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: #93c5fd;
}

.info-box {
    background: rgba(59, 130, 246, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.info-box h4 {
    color: #60a5fa;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.info-box ul {
    margin: 0;
    padding-left: 1.25rem;
    color: var(--color-text-muted);
    font-size: 0.85rem;
}

.info-box li {
    margin-bottom: 0.3rem;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--color-text-muted);
    text-decoration: none;
    font-size: 0.9rem;
    margin-bottom: 1rem;
}

.back-link:hover {
    color: var(--color-text);
}

/* File upload styles */
.file-upload-group {
    margin-bottom: 1rem;
}

.file-upload-label {
    display: block;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.file-upload-wrapper {
    position: relative;
    border: 2px dashed rgba(148, 163, 184, 0.3);
    border-radius: 10px;
    padding: 1.5rem;
    text-align: center;
    transition: border-color 0.2s ease, background 0.2s ease;
    cursor: pointer;
}

.file-upload-wrapper:hover {
    border-color: #f59e0b;
    background: rgba(245, 158, 11, 0.05);
}

.file-upload-wrapper.has-file {
    border-color: #22c55e;
    background: rgba(34, 197, 94, 0.05);
}

.file-upload-wrapper.error {
    border-color: #ef4444;
}

.file-upload-wrapper input[type="file"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.file-upload-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.file-upload-text {
    color: var(--color-text-muted);
    font-size: 0.85rem;
}

.file-upload-text strong {
    color: #f59e0b;
}

.file-upload-hint {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-top: 0.5rem;
}

.file-name {
    display: none;
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: rgba(34, 197, 94, 0.1);
    border-radius: 6px;
    font-size: 0.8rem;
    color: #22c55e;
}

.file-name.visible {
    display: block;
}

.file-preview {
    max-width: 150px;
    max-height: 100px;
    margin: 0.5rem auto 0;
    border-radius: 6px;
    display: none;
}

.file-preview.visible {
    display: block;
}
</style>

<div class="register-container">
    <a href="<?php echo e(url('carshare/request_vehicle.php')); ?>" class="back-link">
        ← Back to Car Share
    </a>
    
    <div class="register-card">
        <div class="register-header">
            <h1>🚗 Register for Car Share</h1>
            <p>Complete the form below to start renting vehicles</p>
        </div>
        
        <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error">
            <?php echo e($errors['general']); ?>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4>ℹ️ Requirements</h4>
            <ul>
                <li>Must be at least 18 years old</li>
                <li>Valid driver's license (not expired)</li>
                <li>Premium vehicles require minimum age 21 and 2+ years license</li>
                <li>Vans require minimum age 23 and 3+ years license</li>
            </ul>
        </div>
        
        <form method="POST" action="" id="registerForm">
            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
            <div class="form-section">
                <h3>📋 Driver's License Information</h3>
                
                <div class="form-group">
                    <label for="license_number">License Number <span class="required">*</span></label>
                    <input type="text" id="license_number" name="license_number" 
                           value="<?php echo e($formData['license_number']); ?>"
                           placeholder="e.g., CY123456"
                           class="<?php echo isset($errors['license_number']) ? 'error' : ''; ?>">
                    <?php if (isset($errors['license_number'])): ?>
                    <div class="form-error"><?php echo e($errors['license_number']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="license_country">Country of Issue</label>
                    <select id="license_country" name="license_country">
                        <option value="Cyprus" <?php echo $formData['license_country'] === 'Cyprus' ? 'selected' : ''; ?>>Cyprus</option>
                        <option value="Greece" <?php echo $formData['license_country'] === 'Greece' ? 'selected' : ''; ?>>Greece</option>
                        <option value="United Kingdom" <?php echo $formData['license_country'] === 'United Kingdom' ? 'selected' : ''; ?>>United Kingdom</option>
                        <option value="Germany" <?php echo $formData['license_country'] === 'Germany' ? 'selected' : ''; ?>>Germany</option>
                        <option value="France" <?php echo $formData['license_country'] === 'France' ? 'selected' : ''; ?>>France</option>
                        <option value="Other EU" <?php echo $formData['license_country'] === 'Other EU' ? 'selected' : ''; ?>>Other EU Country</option>
                        <option value="International" <?php echo $formData['license_country'] === 'International' ? 'selected' : ''; ?>>International</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="license_issue_date">Issue Date <span class="required">*</span></label>
                        <input type="date" id="license_issue_date" name="license_issue_date" 
                               value="<?php echo e($formData['license_issue_date']); ?>"
                               class="<?php echo isset($errors['license_issue_date']) ? 'error' : ''; ?>">
                        <?php if (isset($errors['license_issue_date'])): ?>
                        <div class="form-error"><?php echo e($errors['license_issue_date']); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="license_expiry_date">Expiry Date <span class="required">*</span></label>
                        <input type="date" id="license_expiry_date" name="license_expiry_date" 
                               value="<?php echo e($formData['license_expiry_date']); ?>"
                               class="<?php echo isset($errors['license_expiry_date']) ? 'error' : ''; ?>">
                        <?php if (isset($errors['license_expiry_date'])): ?>
                        <div class="form-error"><?php echo e($errors['license_expiry_date']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- License Photo Upload -->
                <div class="file-upload-group">
                    <label class="file-upload-label">Driver's License Photo (Optional)</label>
                    <div class="file-upload-wrapper <?php echo isset($errors['license_photo']) ? 'error' : ''; ?>" id="licenseUploadWrapper">
                        <input type="file" name="license_photo" id="license_photo" 
                               accept="image/jpeg,image/png,image/webp,application/pdf">
                        <div class="file-upload-icon">📄</div>
                        <div class="file-upload-text">
                            <strong>Click to upload</strong> or drag and drop<br>
                            Front side of your driver's license
                        </div>
                        <div class="file-upload-hint">JPG, PNG, WebP or PDF (max 2MB)</div>
                        <div class="file-name" id="licenseFileName"></div>
                        <img class="file-preview" id="licensePreview" alt="License preview">
                    </div>
                    <?php if (isset($errors['license_photo'])): ?>
                    <div class="form-error"><?php echo e($errors['license_photo']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-section">
                <h3>👤 Personal Information</h3>
                
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" 
                           value="<?php echo e($formData['date_of_birth']); ?>"
                           class="<?php echo isset($errors['date_of_birth']) ? 'error' : ''; ?>">
                    <?php if (isset($errors['date_of_birth'])): ?>
                    <div class="form-error"><?php echo e($errors['date_of_birth']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="national_id">National ID / Passport Number (Optional)</label>
                    <input type="text" id="national_id" name="national_id" 
                           value="<?php echo e($formData['national_id']); ?>"
                           placeholder="For identity verification">
                </div>
                
                <!-- National ID Photo Upload (Optional) -->
                <div class="file-upload-group">
                    <label class="file-upload-label">National ID / Passport Photo (Optional)</label>
                    <div class="file-upload-wrapper" id="nationalIdUploadWrapper">
                        <input type="file" name="national_id_photo" id="national_id_photo" 
                               accept="image/jpeg,image/png,image/webp,application/pdf">
                        <div class="file-upload-icon">🪪</div>
                        <div class="file-upload-text">
                            <strong>Click to upload</strong> or drag and drop<br>
                            Photo of your ID or Passport (optional)
                        </div>
                        <div class="file-upload-hint">JPG, PNG, WebP or PDF (max 2MB)</div>
                        <div class="file-name" id="nationalIdFileName"></div>
                        <img class="file-preview" id="nationalIdPreview" alt="ID preview">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="preferred_language">Preferred Language</label>
                    <select id="preferred_language" name="preferred_language">
                        <option value="en" <?php echo $formData['preferred_language'] === 'en' ? 'selected' : ''; ?>>English</option>
                        <option value="el" <?php echo $formData['preferred_language'] === 'el' ? 'selected' : ''; ?>>Ελληνικά (Greek)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-section">
                <div class="checkbox-group">
                    <input type="checkbox" id="terms_accepted" name="terms_accepted" 
                           <?php echo $formData['terms_accepted'] ? 'checked' : ''; ?>>
                    <label for="terms_accepted">
                        I accept the <a href="#" onclick="OSRH.alert('Terms and Conditions would be displayed here', {title: 'Terms and Conditions'}); return false;">Terms and Conditions</a> 
                        and <a href="#" onclick="OSRH.alert('Privacy Policy would be displayed here', {title: 'Privacy Policy'}); return false;">Privacy Policy</a> 
                        for the car-sharing service. I confirm that the information provided is accurate.
                    </label>
                </div>
                <?php if (isset($errors['terms_accepted'])): ?>
                <div class="form-error" style="margin-top: 0.5rem;"><?php echo e($errors['terms_accepted']); ?></div>
                <?php endif; ?>
            </div>
            
            <button type="submit" class="btn-register">
                Complete Registration
            </button>
        </form>
    </div>
</div>

<script>
// File upload handling with preview
function setupFileUpload(inputId, wrapperId, fileNameId, previewId) {
    const input = document.getElementById(inputId);
    const wrapper = document.getElementById(wrapperId);
    const fileName = document.getElementById(fileNameId);
    const preview = document.getElementById(previewId);
    
    if (!input || !wrapper) return;
    
    input.addEventListener('change', function() {
        const file = this.files[0];
        
        if (file) {
            // Update wrapper state
            wrapper.classList.add('has-file');
            
            // Show file name
            if (fileName) {
                fileName.textContent = '✓ ' + file.name + ' (' + formatFileSize(file.size) + ')';
                fileName.classList.add('visible');
            }
            
            // Show image preview if it's an image
            if (preview && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.add('visible');
                };
                reader.readAsDataURL(file);
            } else if (preview) {
                preview.classList.remove('visible');
            }
        } else {
            wrapper.classList.remove('has-file');
            if (fileName) {
                fileName.textContent = '';
                fileName.classList.remove('visible');
            }
            if (preview) {
                preview.classList.remove('visible');
            }
        }
    });
    
    // Drag and drop styling
    wrapper.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('has-file');
    });
    
    wrapper.addEventListener('dragleave', function() {
        if (!input.files.length) {
            this.classList.remove('has-file');
        }
    });
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// Initialize file uploads
setupFileUpload('license_photo', 'licenseUploadWrapper', 'licenseFileName', 'licensePreview');
setupFileUpload('national_id_photo', 'nationalIdUploadWrapper', 'nationalIdFileName', 'nationalIdPreview');

// Form submission
document.getElementById('registerForm').addEventListener('submit', function(e) {
    const btn = this.querySelector('.btn-register');
    const maxSize = 2 * 1024 * 1024; // 2MB
    const form = this;
    
    // Check if any files are selected
    const licenseInput = document.getElementById('license_photo');
    const nationalIdInput = document.getElementById('national_id_photo');
    const hasFiles = (licenseInput && licenseInput.files.length > 0) || 
                     (nationalIdInput && nationalIdInput.files.length > 0);
    
    // Only set multipart encoding if files are being uploaded
    if (hasFiles) {
        form.setAttribute('enctype', 'multipart/form-data');
        
        // Check file sizes before submitting
        if (licenseInput && licenseInput.files[0] && licenseInput.files[0].size > maxSize) {
            e.preventDefault();
            document.getElementById('licenseUploadWrapper').classList.add('error');
            OSRH.alert("License photo is too large (" + formatFileSize(licenseInput.files[0].size) + "). Maximum is 2MB. Please resize or compress the image.");
            return;
        }
        
        if (nationalIdInput && nationalIdInput.files[0] && nationalIdInput.files[0].size > maxSize) {
            e.preventDefault();
            document.getElementById('nationalIdUploadWrapper').classList.add('error');
            OSRH.alert("National ID photo is too large (" + formatFileSize(nationalIdInput.files[0].size) + "). Maximum is 2MB. Please resize or compress the image.");
            return;
        }
    } else {
        form.removeAttribute('enctype');
    }
    
    btn.disabled = true;
    btn.textContent = 'Submitting registration...';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
