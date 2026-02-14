<?php
declare(strict_types=1);
/**
 * Driver Registration - Simplified Version (No File Uploads)
 * Only requires: Full Name, Email, Phone, DOB, Password, ID Card Number, License Number
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';

if (is_logged_in()) {
    auth_redirect_after_login();
}

$errors = [];
$data = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'date_of_birth' => '',
    'password' => '',
    'password_confirm' => '',
    'id_card_number' => '',
    'license_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['full_name'] = trim($_POST['full_name'] ?? '');
    $data['email'] = trim($_POST['email'] ?? '');
    $data['phone'] = trim($_POST['phone'] ?? '');
    $data['date_of_birth'] = trim($_POST['date_of_birth'] ?? '');
    $data['password'] = $_POST['password'] ?? '';
    $data['password_confirm'] = $_POST['password_confirm'] ?? '';
    $data['id_card_number'] = trim($_POST['id_card_number'] ?? '');
    $data['license_number'] = trim($_POST['license_number'] ?? '');
    
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        // Validation
        if (empty($data['full_name'])) {
            $errors['full_name'] = 'Full name is required.';
        }
        
        if (empty($data['email'])) {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format.';
        }
        
        if (empty($data['phone'])) {
            $errors['phone'] = 'Phone is required.';
        }
        
        if (empty($data['date_of_birth'])) {
            $errors['date_of_birth'] = 'Date of birth is required.';
        } else {
            $dob = new DateTime($data['date_of_birth']);
            $age = (new DateTime())->diff($dob)->y;
            if ($age < 18) {
                $errors['date_of_birth'] = 'You must be at least 18 years old.';
            }
        }
        
        if (empty($data['password'])) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        
        if ($data['password'] !== $data['password_confirm']) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        
        if (empty($data['id_card_number'])) {
            $errors['id_card_number'] = 'ID card number is required.';
        }
        
        if (empty($data['license_number'])) {
            $errors['license_number'] = 'License number is required.';
        }
    }

    if (empty($errors)) {
        // Hash password
        $hashData = osrh_password_hash($data['password']);
        $hash = $hashData['hash'];
        $salt = $hashData['salt'];

        // Call simplified stored procedure (no file paths)
        $conn = db_get_connection();
        $sql = '{CALL dbo.spRegisterDriverSimple(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)}';

        $params = [
            [$data['email'], SQLSRV_PARAM_IN],
            [$data['phone'], SQLSRV_PARAM_IN],
            [$data['full_name'], SQLSRV_PARAM_IN],
            [$hash, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
            [$salt, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
            [$data['date_of_birth'], SQLSRV_PARAM_IN],
            [$data['id_card_number'], SQLSRV_PARAM_IN],
            [null, SQLSRV_PARAM_IN],  // id_card_path - no file upload
            [$data['license_number'], SQLSRV_PARAM_IN],
            [null, SQLSRV_PARAM_IN],  // license_path - no file upload
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $dbErrors = sqlsrv_errors();
            $errorMsg = $dbErrors[0]['message'] ?? 'Unknown error';
            
            if (strpos($errorMsg, 'Email already registered') !== false || 
                strpos($errorMsg, 'UNIQUE KEY') !== false) {
                $errors['email'] = 'This email is already registered.';
            } else {
                $errors['general'] = 'Registration failed: ' . $errorMsg;
            }
        } else {
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            if ($result && isset($result['UserID'])) {
                flash_add('success', 'Registration successful! You can now log in. Please upload your documents for verification.');
                redirect('login.php');
            } else {
                $errors['general'] = 'Registration failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Register as Driver';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width: 500px; margin: 2rem auto;">
    <div class="card-header">
        <h1 class="card-title">Driver Registration</h1>
        <p style="color: #666; margin-top: 0.5rem;">Create your driver account</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error"><?php echo e($errors['general']); ?></div>
    <?php endif; ?>

    <form method="post" class="js-validate" novalidate>
        <?php csrf_field(); ?>

        <h3 style="margin: 1rem 0; padding-bottom: 0.5rem; border-bottom: 1px solid #333;">Personal Information</h3>

        <div class="form-group">
            <label class="form-label" for="full_name">Full Name *</label>
            <input type="text" id="full_name" name="full_name" class="form-control<?php echo isset($errors['full_name']) ? ' input-error' : ''; ?>" value="<?php echo e($data['full_name']); ?>" required>
            <?php if (isset($errors['full_name'])): ?><div class="form-error"><?php echo e($errors['full_name']); ?></div><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">Email *</label>
            <input type="email" id="email" name="email" class="form-control<?php echo isset($errors['email']) ? ' input-error' : ''; ?>" value="<?php echo e($data['email']); ?>" required>
            <?php if (isset($errors['email'])): ?><div class="form-error"><?php echo e($errors['email']); ?></div><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="phone">Phone *</label>
            <input type="tel" id="phone" name="phone" class="form-control<?php echo isset($errors['phone']) ? ' input-error' : ''; ?>" value="<?php echo e($data['phone']); ?>" required>
            <?php if (isset($errors['phone'])): ?><div class="form-error"><?php echo e($errors['phone']); ?></div><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="date_of_birth">Date of Birth *</label>
            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control<?php echo isset($errors['date_of_birth']) ? ' input-error' : ''; ?>" value="<?php echo e($data['date_of_birth']); ?>" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" required>
            <?php if (isset($errors['date_of_birth'])): ?><div class="form-error"><?php echo e($errors['date_of_birth']); ?></div><?php endif; ?>
        </div>

        <h3 style="margin: 1.5rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #333;">Password</h3>

        <div class="form-group">
            <label class="form-label" for="password">Password *</label>
            <input type="password" id="password" name="password" class="form-control<?php echo isset($errors['password']) ? ' input-error' : ''; ?>" required minlength="8">
            <?php if (isset($errors['password'])): ?><div class="form-error"><?php echo e($errors['password']); ?></div><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Confirm Password *</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control<?php echo isset($errors['password_confirm']) ? ' input-error' : ''; ?>" required>
            <?php if (isset($errors['password_confirm'])): ?><div class="form-error"><?php echo e($errors['password_confirm']); ?></div><?php endif; ?>
        </div>

        <h3 style="margin: 1.5rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #333;">Documents</h3>

        <div class="form-group">
            <label class="form-label" for="id_card_number">ID Card Number *</label>
            <input type="text" id="id_card_number" name="id_card_number" class="form-control<?php echo isset($errors['id_card_number']) ? ' input-error' : ''; ?>" value="<?php echo e($data['id_card_number']); ?>" required>
            <?php if (isset($errors['id_card_number'])): ?><div class="form-error"><?php echo e($errors['id_card_number']); ?></div><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="license_number">Driver License Number *</label>
            <input type="text" id="license_number" name="license_number" class="form-control<?php echo isset($errors['license_number']) ? ' input-error' : ''; ?>" value="<?php echo e($data['license_number']); ?>" required>
            <?php if (isset($errors['license_number'])): ?><div class="form-error"><?php echo e($errors['license_number']); ?></div><?php endif; ?>
        </div>

        <div class="form-group" style="margin-top: 2rem;">
            <button type="submit" class="btn btn-primary" style="width: 100%;">Register as Driver</button>
        </div>

        <p style="text-align: center; margin-top: 1rem; color: #888;">
            Already have an account? <a href="<?php echo e(url('login.php')); ?>">Sign in</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
