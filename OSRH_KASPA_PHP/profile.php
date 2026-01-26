<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/flash.php';

require_login();

$currentUserId = current_user_id();
if ($currentUserId <= 0) {
    redirect('error.php?code=403');
}

$errors = [];

// Load base user row
$stmt = db_call_procedure('dbo.spGetUserProfile', [$currentUserId]);
$userRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if ($stmt) sqlsrv_free_stmt($stmt);

if (!$userRow) {
    redirect('error.php?code=404');
}

// Role-specific rows (from session, already loaded in auth)
$passengerRow = isset($_SESSION['user']['passenger']) ? $_SESSION['user']['passenger'] : null;

$driverRow = isset($_SESSION['user']['driver']) ? $_SESSION['user']['driver'] : null;

$operatorRow = isset($_SESSION['user']['operator']) ? $_SESSION['user']['operator'] : null;

// Determine primary role label
$role = primary_role(); // from roles.php

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $action = (string)array_get($_POST, 'action', '');

        // UPDATE PROFILE
        if ($action === 'update_profile') {
            $fullName = trim((string)array_get($_POST, 'full_name', ''));
            $phone    = trim((string)array_get($_POST, 'phone', ''));
            
            // Address fields
            $streetAddress = trim((string)array_get($_POST, 'street_address', ''));
            $city          = trim((string)array_get($_POST, 'city', ''));
            $postalCode    = trim((string)array_get($_POST, 'postal_code', ''));
            $country       = trim((string)array_get($_POST, 'country', ''));
            
            // GDPR Preferences (checkboxes)
            $prefLocation     = isset($_POST['pref_location']) ? 1 : 0;
            $prefNotifications = isset($_POST['pref_notifications']) ? 1 : 0;
            $prefEmail        = isset($_POST['pref_email']) ? 1 : 0;
            $prefDataSharing  = isset($_POST['pref_data_sharing']) ? 1 : 0;

            if ($fullName === '') {
                $errors['full_name'] = 'Name cannot be empty.';
            }

            if (!$errors) {
                $stmt = db_call_procedure('dbo.spUpdateUserProfile', [
                    $currentUserId,
                    $fullName,
                    $phone !== '' ? $phone : null,
                    $streetAddress !== '' ? $streetAddress : null,
                    $city !== '' ? $city : null,
                    $postalCode !== '' ? $postalCode : null,
                    $country !== '' ? $country : null,
                    $prefLocation,
                    $prefNotifications,
                    $prefEmail,
                    $prefDataSharing
                ]);
                $result = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                $ok = $result && !empty($result['Success']);
                if ($stmt) sqlsrv_free_stmt($stmt);

                if (!$ok) {
                    $errors['general'] = 'Could not update profile. Please try again.';
                } else {
                    flash_add('success', 'Profile updated.');
                    // Reload user row
                    $stmt = db_call_procedure('dbo.spGetUserProfile', [$currentUserId]);
                    $userRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                    if ($stmt) sqlsrv_free_stmt($stmt);
                }
            }
        }

        // CHANGE PASSWORD
        if ($action === 'change_password' && !$errors) {
            $currentPassword = (string)array_get($_POST, 'current_password', '');
            $newPassword     = (string)array_get($_POST, 'new_password', '');
            $confirmPassword = (string)array_get($_POST, 'confirm_password', '');

            if ($currentPassword === '') {
                $errors['current_password'] = 'Current password is required.';
            }
            if ($newPassword === '') {
                $errors['new_password'] = 'New password is required.';
            } elseif (strlen($newPassword) < 8) {
                $errors['new_password'] = 'New password must be at least 8 characters.';
            }
            if ($confirmPassword === '') {
                $errors['confirm_password'] = 'Please confirm the new password.';
            } elseif ($newPassword !== $confirmPassword) {
                $errors['confirm_password'] = 'Passwords do not match.';
            }

            // Verify current password using database salt + hash
            if (!$errors) {
                $email = (string)$userRow['Email'];
                
                // Get current salt from database
                $stmt = db_call_procedure('dbo.spGetUserCurrentPassword', [$currentUserId]);
                $saltRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                if ($stmt) sqlsrv_free_stmt($stmt);
                
                if (!$saltRow || $saltRow['PasswordSalt'] === null) {
                    $errors['general'] = 'Could not verify current password. Please try again.';
                } else {
                    $saltBinary = $saltRow['PasswordSalt'];
                    $storedHash = $saltRow['PasswordHash'];
                    
                    // Compute hash with the salt
                    $hashData = osrh_password_hash($currentPassword, $saltBinary);
                    $computedHash = $hashData['hash'];
                    
                    // Compare hashes
                    if (!hash_equals($storedHash, $computedHash)) {
                        $errors['current_password'] = 'Current password is incorrect.';
                    }
                }
            }

            // Change password via spChangePassword
            if (!$errors) {
                // Generate new salt and hash for the new password
                $newHashData = osrh_password_hash($newPassword);
                $newHash = $newHashData['hash'];
                $newSalt = $newHashData['salt'];

                // Call stored procedure with proper VARBINARY typing
                $conn = db();
                $sql = '{CALL dbo.spChangePassword(?, ?, ?)}';
                
                $params = [
                    [$currentUserId, SQLSRV_PARAM_IN],
                    [
                        $newHash,
                        SQLSRV_PARAM_IN,
                        SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
                        SQLSRV_SQLTYPE_VARBINARY('max'),
                    ],
                    [
                        $newSalt,
                        SQLSRV_PARAM_IN,
                        SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
                        SQLSRV_SQLTYPE_VARBINARY('max'),
                    ],
                ];
                
                $stmtChange = sqlsrv_query($conn, $sql, $params);

                if ($stmtChange === false) {
                    $errors['general'] = 'Could not change password. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmtChange);
                    flash_add('success', 'Password changed successfully.');
                    // No redirect needed; just clear password fields by not reusing POST values
                }
            }
        }
    }
}

$pageTitle = 'My profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 600px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">My Profile</h1>
            <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                Update your personal information and password.
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-top:0.7rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <div style="margin-top:1rem;">
        <!-- Edit profile + change password -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;">Edit Profile</h2>
            </div>
            <div class="card-body" style="padding:0.8rem 1rem 1rem;font-size:0.86rem;">
                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-group">
                        <label class="form-label" for="full_name">Full name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="form-control<?php echo !empty($errors['full_name']) ? ' input-error' : ''; ?>"
                            value="<?php echo e($userRow['FullName']); ?>"
                            data-required="1"
                        >
                        <?php if (!empty($errors['full_name'])): ?>
                            <div class="form-error"><?php echo e($errors['full_name']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="phone">Phone</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            class="form-control"
                            value="<?php echo e($userRow['Phone'] ?? ''); ?>"
                        >
                    </div>
                    
                    <hr style="border-color:rgba(255,255,255,0.06);margin:1rem 0;">
                    <h3 style="font-size:0.9rem;margin-bottom:0.6rem;">Address</h3>
                    
                    <div class="form-group">
                        <label class="form-label" for="street_address">Street Address</label>
                        <input
                            type="text"
                            id="street_address"
                            name="street_address"
                            class="form-control"
                            value="<?php echo e($userRow['StreetAddress'] ?? ''); ?>"
                            placeholder="e.g., 123 Main Street"
                        >
                    </div>
                    
                    <div style="display:grid;grid-template-columns:2fr 1fr;gap:0.5rem;">
                        <div class="form-group">
                            <label class="form-label" for="city">City</label>
                            <input
                                type="text"
                                id="city"
                                name="city"
                                class="form-control"
                                value="<?php echo e($userRow['City'] ?? ''); ?>"
                                placeholder="e.g., Nicosia"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="postal_code">Postal Code</label>
                            <input
                                type="text"
                                id="postal_code"
                                name="postal_code"
                                class="form-control"
                                value="<?php echo e($userRow['PostalCode'] ?? ''); ?>"
                                placeholder="e.g., 1000"
                            >
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="country">Country</label>
                        <input
                            type="text"
                            id="country"
                            name="country"
                            class="form-control"
                            value="<?php echo e($userRow['Country'] ?? 'Cyprus'); ?>"
                        >
                    </div>

                    <div class="form-group" style="margin-top:0.8rem;">
                        <button type="submit" class="btn btn-primary btn-small">
                            Save changes
                        </button>
                    </div>
                </form>

                <hr style="border-color:rgba(255,255,255,0.06);margin:1.1rem 0;">

                <h3 style="font-size:0.9rem;margin-bottom:0.4rem;">Change password</h3>

                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label class="form-label" for="current_password">Current password</label>
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            class="form-control<?php echo !empty($errors['current_password']) ? ' input-error' : ''; ?>"
                            data-required="1"
                        >
                        <?php if (!empty($errors['current_password'])): ?>
                            <div class="form-error"><?php echo e($errors['current_password']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">New password</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            class="form-control<?php echo !empty($errors['new_password']) ? ' input-error' : ''; ?>"
                            data-required="1"
                        >
                        <?php if (!empty($errors['new_password'])): ?>
                            <div class="form-error"><?php echo e($errors['new_password']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-control<?php echo !empty($errors['confirm_password']) ? ' input-error' : ''; ?>"
                            data-required="1"
                        >
                        <?php if (!empty($errors['confirm_password'])): ?>
                            <div class="form-error"><?php echo e($errors['confirm_password']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group" style="margin-top:0.8rem;">
                        <button type="submit" class="btn btn-outline btn-small">
                            Change password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
