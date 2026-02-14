<?php
declare(strict_types=1);
/**
 * Driver Document Upload Page
 * Allows drivers to upload ID card and license photos after registration
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('driver');

$user = current_user();
$driverRow = $user['driver'] ?? null;

if (!$driverRow || !isset($driverRow['DriverID'])) {
    redirect('error.php?code=403');
}

$driverId = (int)$driverRow['DriverID'];

// Document upload configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads/driver/documents/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

// Create upload directory if it doesn't exist
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    
    // Create .htaccess to protect directory
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
        throw new Exception('File too large. Maximum size is 5MB.');
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
    
    // Generate unique filename
    $uniqueName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = UPLOAD_DIR . $uniqueName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save uploaded file.');
    }
    
    return $uniqueName;
}

// Get existing documents
$existingDocs = [];
$stmtDocs = db_query(
    "SELECT DocType, IdNumber, StorageUrl, Status FROM dbo.DriverDocument WHERE DriverID = ?",
    [$driverId]
);
if ($stmtDocs) {
    while ($row = sqlsrv_fetch_array($stmtDocs, SQLSRV_FETCH_ASSOC)) {
        $existingDocs[$row['DocType']] = $row;
    }
    sqlsrv_free_stmt($stmtDocs);
}

$errors = [];
$success = [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $conn = db_get_connection();
        
        // Handle ID Card upload
        if (isset($_FILES['id_card_file']) && $_FILES['id_card_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $filename = handleFileUpload($_FILES['id_card_file'], 'id_' . $driverId);
                if ($filename) {
                    // Update or insert document record
                    if (isset($existingDocs['id_card'])) {
                        $sql = "UPDATE dbo.DriverDocument SET StorageUrl = ?, Status = 'pending' WHERE DriverID = ? AND DocType = 'id_card'";
                        sqlsrv_query($conn, $sql, [$filename, $driverId]);
                    } else {
                        $sql = "INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, StorageUrl, Status) VALUES (?, 'id_card', ?, ?, 'pending')";
                        $idNumber = $existingDocs['id_card']['IdNumber'] ?? 'N/A';
                        sqlsrv_query($conn, $sql, [$driverId, $idNumber, $filename]);
                    }
                    $success[] = 'ID Card uploaded successfully.';
                    $existingDocs['id_card']['StorageUrl'] = $filename;
                    $existingDocs['id_card']['Status'] = 'pending';
                }
            } catch (Exception $e) {
                $errors['id_card'] = $e->getMessage();
            }
        }
        
        // Handle License upload
        if (isset($_FILES['license_file']) && $_FILES['license_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $filename = handleFileUpload($_FILES['license_file'], 'lic_' . $driverId);
                if ($filename) {
                    // Update or insert document record
                    if (isset($existingDocs['license'])) {
                        $sql = "UPDATE dbo.DriverDocument SET StorageUrl = ?, Status = 'pending' WHERE DriverID = ? AND DocType = 'license'";
                        sqlsrv_query($conn, $sql, [$filename, $driverId]);
                    } else {
                        $sql = "INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, StorageUrl, Status) VALUES (?, 'license', ?, ?, 'pending')";
                        $licNumber = $existingDocs['license']['IdNumber'] ?? 'N/A';
                        sqlsrv_query($conn, $sql, [$driverId, $licNumber, $filename]);
                    }
                    $success[] = 'Driver License uploaded successfully.';
                    $existingDocs['license']['StorageUrl'] = $filename;
                    $existingDocs['license']['Status'] = 'pending';
                }
            } catch (Exception $e) {
                $errors['license'] = $e->getMessage();
            }
        }
        
        if (empty($errors) && !empty($success)) {
            flash_add('success', implode(' ', $success));
            redirect('driver/upload_documents.php');
        }
    }
}

$pageTitle = 'Upload Documents';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.upload-container {
    max-width: 600px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.upload-card {
    background: rgba(15, 23, 42, 0.95);
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.1);
    padding: 2rem;
}

.upload-header {
    text-align: center;
    margin-bottom: 2rem;
}

.upload-header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
}

.upload-header p {
    color: var(--color-text-muted);
    font-size: 0.95rem;
}

.doc-status {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.doc-status.uploaded {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #86efac;
}

.doc-status.pending {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: #fcd34d;
}

.doc-status.missing {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #fca5a5;
}

.doc-status.approved {
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.4);
    color: #4ade80;
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section h3 {
    font-size: 1.1rem;
    margin-bottom: 1rem;
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
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.05);
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
    color: #3b82f6;
}

.file-upload-hint {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-top: 0.5rem;
}

.file-name {
    margin-top: 0.75rem;
    padding: 0.5rem;
    background: rgba(59, 130, 246, 0.1);
    border-radius: 6px;
    font-size: 0.8rem;
    color: #93c5fd;
    display: none;
}

.file-name.visible {
    display: block;
}

.btn-upload {
    width: 100%;
    padding: 0.85rem;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 1.5rem;
}

.btn-upload:hover {
    opacity: 0.9;
}

.btn-upload:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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

.form-error {
    color: #ef4444;
    font-size: 0.8rem;
    margin-top: 0.5rem;
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
</style>

<div class="upload-container">
    <a href="<?php echo e(url('driver/dashboard.php')); ?>" class="back-link">
        ← Back to Dashboard
    </a>
    
    <div class="upload-card">
        <div class="upload-header">
            <h1>📄 Upload Documents</h1>
            <p>Upload your ID card and driver's license photos for verification</p>
        </div>
        
        <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error">
            <?php echo e($errors['general']); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <?php csrf_field(); ?>
            
            <!-- ID Card Section -->
            <div class="form-section">
                <h3>🪪 ID Card</h3>
                
                <?php 
                $idStatus = $existingDocs['id_card']['Status'] ?? null;
                $idFile = $existingDocs['id_card']['StorageUrl'] ?? null;
                ?>
                
                <?php if ($idStatus === 'approved'): ?>
                <div class="doc-status approved">
                    ✓ ID Card verified and approved
                </div>
                <?php elseif ($idFile): ?>
                <div class="doc-status pending">
                    ⏳ ID Card uploaded - pending verification
                </div>
                <?php else: ?>
                <div class="doc-status missing">
                    ✗ ID Card photo not uploaded
                </div>
                <?php endif; ?>
                
                <?php if ($idStatus !== 'approved'): ?>
                <div class="file-upload-wrapper" id="idCardWrapper">
                    <input type="file" name="id_card_file" id="id_card_file" 
                           accept="image/jpeg,image/png,image/webp,application/pdf">
                    <div class="file-upload-icon">📷</div>
                    <div class="file-upload-text">
                        <strong>Click to upload</strong> or drag and drop<br>
                        <?php echo $idFile ? 'Upload new file to replace' : 'Front side of your ID card'; ?>
                    </div>
                    <div class="file-upload-hint">JPG, PNG, WebP or PDF (max 5MB)</div>
                    <div class="file-name" id="idCardFileName"></div>
                </div>
                <?php if (isset($errors['id_card'])): ?>
                <div class="form-error"><?php echo e($errors['id_card']); ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- License Section -->
            <div class="form-section">
                <h3>🚗 Driver's License</h3>
                
                <?php 
                $licStatus = $existingDocs['license']['Status'] ?? null;
                $licFile = $existingDocs['license']['StorageUrl'] ?? null;
                ?>
                
                <?php if ($licStatus === 'approved'): ?>
                <div class="doc-status approved">
                    ✓ Driver's License verified and approved
                </div>
                <?php elseif ($licFile): ?>
                <div class="doc-status pending">
                    ⏳ Driver's License uploaded - pending verification
                </div>
                <?php else: ?>
                <div class="doc-status missing">
                    ✗ Driver's License photo not uploaded
                </div>
                <?php endif; ?>
                
                <?php if ($licStatus !== 'approved'): ?>
                <div class="file-upload-wrapper" id="licenseWrapper">
                    <input type="file" name="license_file" id="license_file" 
                           accept="image/jpeg,image/png,image/webp,application/pdf">
                    <div class="file-upload-icon">📷</div>
                    <div class="file-upload-text">
                        <strong>Click to upload</strong> or drag and drop<br>
                        <?php echo $licFile ? 'Upload new file to replace' : 'Front side of your driver\'s license'; ?>
                    </div>
                    <div class="file-upload-hint">JPG, PNG, WebP or PDF (max 5MB)</div>
                    <div class="file-name" id="licenseFileName"></div>
                </div>
                <?php if (isset($errors['license'])): ?>
                <div class="form-error"><?php echo e($errors['license']); ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php 
            $canUpload = ($idStatus !== 'approved') || ($licStatus !== 'approved');
            ?>
            
            <?php if ($canUpload): ?>
            <button type="submit" class="btn-upload" id="uploadBtn">
                Upload Documents
            </button>
            <?php else: ?>
            <div class="doc-status approved" style="justify-content: center;">
                ✓ All documents verified!
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function setupFileInput(inputId, wrapperId, fileNameId) {
    const input = document.getElementById(inputId);
    const wrapper = document.getElementById(wrapperId);
    const fileName = document.getElementById(fileNameId);
    
    if (!input || !wrapper) return;
    
    input.addEventListener('change', function() {
        const file = this.files[0];
        if (file && fileName) {
            const size = file.size < 1024 * 1024 
                ? (file.size / 1024).toFixed(1) + ' KB'
                : (file.size / (1024 * 1024)).toFixed(1) + ' MB';
            fileName.textContent = '✓ ' + file.name + ' (' + size + ')';
            fileName.classList.add('visible');
            wrapper.style.borderColor = '#22c55e';
        }
    });
}

setupFileInput('id_card_file', 'idCardWrapper', 'idCardFileName');
setupFileInput('license_file', 'licenseWrapper', 'licenseFileName');

// Form submission
document.getElementById('uploadForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('uploadBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Uploading...';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
