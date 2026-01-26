<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';

if (is_logged_in()) {
    auth_redirect_after_login();
}

$errors = [];
$data   = [
    // Personal Information
    'full_name'        => '',
    'email'            => '',
    'phone'            => '',
    'password'         => '',
    'password_confirm' => '',
    
    // Address
    'street_address'   => '',
    'city'             => '',
    'postal_code'      => '',
    'country'          => 'Cyprus',
    
    // User Preferences (GDPR)
    'pref_location'     => 1,
    'pref_notifications'=> 1,
    'pref_email'        => 1,
    'pref_data_sharing' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Personal Information
    $data['full_name']        = trim((string)array_get($_POST, 'full_name', ''));
    $data['email']            = trim((string)array_get($_POST, 'email', ''));
    $data['phone']            = trim((string)array_get($_POST, 'phone', ''));
    $data['password']         = (string)array_get($_POST, 'password', '');
    $data['password_confirm'] = (string)array_get($_POST, 'password_confirm', '');
    
    // Address
    $data['street_address']   = trim((string)array_get($_POST, 'street_address', ''));
    $data['city']             = trim((string)array_get($_POST, 'city', ''));
    $data['postal_code']      = trim((string)array_get($_POST, 'postal_code', ''));
    $data['country']          = trim((string)array_get($_POST, 'country', 'Cyprus'));
    
    // User Preferences (GDPR) - checkboxes
    $data['pref_location']     = isset($_POST['pref_location']) ? 1 : 0;
    $data['pref_notifications'] = isset($_POST['pref_notifications']) ? 1 : 0;
    $data['pref_email']        = isset($_POST['pref_email']) ? 1 : 0;
    $data['pref_data_sharing'] = isset($_POST['pref_data_sharing']) ? 1 : 0;
    
    $token = array_get($_POST, 'csrf_token', null);

    // CSRF
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        // Basic validation
        if ($data['full_name'] === '') {
            $errors['full_name'] = 'Full name is required.';
        }

        if ($data['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format.';
        }

        if ($data['password'] === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if ($data['password_confirm'] === '') {
            $errors['password_confirm'] = 'Please confirm your password.';
        } elseif ($data['password'] !== $data['password_confirm']) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
    }

    if (!$errors) {
        // Prepare hash & salt (osrh_password_hash should return binary-safe strings)
        $hashData = osrh_password_hash($data['password']);
        $hash     = $hashData['hash'];  // binary
        $salt     = $hashData['salt'];  // binary

        // Optional fields -> NULL for SQL
        $phone         = $data['phone'] !== '' ? $data['phone'] : null;
        $streetAddress = $data['street_address'] !== '' ? $data['street_address'] : null;
        $city          = $data['city'] !== '' ? $data['city'] : null;
        $postalCode    = $data['postal_code'] !== '' ? $data['postal_code'] : null;
        $country       = $data['country'] !== '' ? $data['country'] : 'Cyprus';

        // --------- CALL TO spRegisterPassenger WITH UPDATED PARAMS ---------
        $conn = db_get_connection();
        $sql  = '{CALL dbo.spRegisterPassenger(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)}';

        $params = [
            // Email
            [$data['email'], SQLSRV_PARAM_IN],
            // Phone (nullable)
            [$phone, SQLSRV_PARAM_IN],
            // Full name
            [$data['full_name'], SQLSRV_PARAM_IN],
            // PasswordHash as VARBINARY(MAX)
            [
                $hash,
                SQLSRV_PARAM_IN,
                SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
                SQLSRV_SQLTYPE_VARBINARY('max')
            ],
            // PasswordSalt as VARBINARY(MAX)
            [
                $salt,
                SQLSRV_PARAM_IN,
                SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
                SQLSRV_SQLTYPE_VARBINARY('max')
            ],
            // Loyalty level (NULL - handled by operator later)
            [null, SQLSRV_PARAM_IN],
            // Address fields
            [$streetAddress, SQLSRV_PARAM_IN],
            [$city, SQLSRV_PARAM_IN],
            [$postalCode, SQLSRV_PARAM_IN],
            [$country, SQLSRV_PARAM_IN],
            // GDPR Preferences
            [$data['pref_location'], SQLSRV_PARAM_IN],
            [$data['pref_notifications'], SQLSRV_PARAM_IN],
            [$data['pref_email'], SQLSRV_PARAM_IN],
            [$data['pref_data_sharing'], SQLSRV_PARAM_IN],
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $dbErrors = sqlsrv_errors();
            $message  = 'Registration failed. Please try again.';

            // DEBUG: show full SQL error info in development
            if (APP_ENV === 'development' && $dbErrors) {
                echo '<pre style="background:#fee;border:1px solid #f99;padding:8px;margin:8px 0;">';
                echo "spRegisterPassenger SQL errors:\n";
                print_r($dbErrors);
                echo "</pre>";
            }

            if ($dbErrors) {
                foreach ($dbErrors as $err) {
                    if (strpos($err['message'], 'Email already registered') !== false ||
                        strpos($err['message'], 'email already exists') !== false) {
                        $errors['email'] = 'This email is already registered.';
                        $message = null;
                        break;
                    }
                }
            }

            if ($message !== null) {
                $errors['general'] = $message;
            }
        } else {
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            if (!$result || !isset($result['UserID'])) {
                if (APP_ENV === 'development') {
                    echo '<pre style="background:#eef;border:1px solid #99f;padding:8px;margin:8px 0;">';
                    echo "spRegisterPassenger result (no UserID):\n";
                    var_dump($result);
                    echo "</pre>";
                }

                $errors['general'] = 'Registration failed. Please contact support.';
            } else {
                flash_add('success', 'Passenger account created. Please sign in.');
                redirect('login.php');
            }
        }
    }
}

$pageTitle = 'Register Passenger';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 2rem auto;">
    <div class="card-header">
        <h1 class="card-title">Passenger Registration</h1>
        <p style="margin: 0.5rem 0 0; color: #666; font-size: 0.9rem;">Create your account to book rides and deliveries.</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <form method="post" class="js-validate" novalidate>
        <?php csrf_field(); ?>

        <h3 style="margin: 1rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e0e0e0; color: #333;">Personal Information</h3>

        <div class="form-group">
            <label class="form-label" for="full_name">Full Name</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                class="form-control<?php echo !empty($errors['full_name']) ? ' input-error' : ''; ?>"
                value="<?php echo e($data['full_name']); ?>"
                data-required="1"
                autocomplete="name"
            >
            <?php if (!empty($errors['full_name'])): ?>
                <div class="form-error"><?php echo e($errors['full_name']); ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-control<?php echo !empty($errors['email']) ? ' input-error' : ''; ?>"
                value="<?php echo e($data['email']); ?>"
                data-required="1"
                autocomplete="email"
            >
            <?php if (!empty($errors['email'])): ?>
                <div class="form-error"><?php echo e($errors['email']); ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="phone">Phone <span class="text-muted" style="font-weight: normal;">(optional)</span></label>
            <input
                type="text"
                id="phone"
                name="phone"
                class="form-control"
                value="<?php echo e($data['phone']); ?>"
                autocomplete="tel"
                placeholder="e.g., 99123456"
            >
        </div>

        <h3 style="margin: 1.5rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e0e0e0; color: #333;">Address <span style="font-weight: normal; font-size: 0.85rem; color: #666;">(Optional)</span></h3>

        <div class="form-group">
            <label class="form-label" for="street_address">Street Address</label>
            <input
                type="text"
                id="street_address"
                name="street_address"
                class="form-control"
                value="<?php echo e($data['street_address']); ?>"
                autocomplete="street-address"
                placeholder="e.g., 123 Main Street"
            >
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label class="form-label" for="city">City</label>
                <input
                    type="text"
                    id="city"
                    name="city"
                    class="form-control"
                    value="<?php echo e($data['city']); ?>"
                    autocomplete="address-level2"
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
                    value="<?php echo e($data['postal_code']); ?>"
                    autocomplete="postal-code"
                    placeholder="e.g., 1000"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="country">Country</label>
                <input
                    type="text"
                    id="country"
                    name="country"
                    class="form-control"
                    value="<?php echo e($data['country']); ?>"
                    autocomplete="country-name"
                >
            </div>
        </div>

        <h3 style="margin: 1.5rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e0e0e0; color: #333;">Security</h3>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control<?php echo !empty($errors['password']) ? ' input-error' : ''; ?>"
                data-required="1"
                autocomplete="new-password"
            >
            <div class="form-hint" style="font-size: 0.8rem; color: #666;">Minimum 8 characters</div>
            <?php if (!empty($errors['password'])): ?>
                <div class="form-error"><?php echo e($errors['password']); ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Confirm Password</label>
            <input
                type="password"
                id="password_confirm"
                name="password_confirm"
                class="form-control<?php echo !empty($errors['password_confirm']) ? ' input-error' : ''; ?>"
                data-required="1"
                autocomplete="new-password"
            >
            <?php if (!empty($errors['password_confirm'])): ?>
                <div class="form-error"><?php echo e($errors['password_confirm']); ?></div>
            <?php endif; ?>
        </div>

        <h3 style="margin: 1.5rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e0e0e0; color: #333;">Privacy Preferences (GDPR)</h3>
        <p style="font-size: 0.85rem; color: #666; margin-bottom: 1rem;">Control how we use your data. You can change these settings anytime in your profile.</p>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input
                    type="checkbox"
                    name="pref_location"
                    value="1"
                    <?php echo $data['pref_location'] ? 'checked' : ''; ?>
                    style="width: auto; margin-right: 0.5rem;"
                >
                <span>Allow location tracking for ride pickup</span>
            </label>
            <div class="form-hint" style="font-size: 0.8rem; color: #666; margin-left: 1.5rem;">Required for automatic pickup location detection</div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input
                    type="checkbox"
                    name="pref_notifications"
                    value="1"
                    <?php echo $data['pref_notifications'] ? 'checked' : ''; ?>
                    style="width: auto; margin-right: 0.5rem;"
                >
                <span>Allow push notifications</span>
            </label>
            <div class="form-hint" style="font-size: 0.8rem; color: #666; margin-left: 1.5rem;">Receive updates about your rides and driver arrival</div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input
                    type="checkbox"
                    name="pref_email"
                    value="1"
                    <?php echo $data['pref_email'] ? 'checked' : ''; ?>
                    style="width: auto; margin-right: 0.5rem;"
                >
                <span>Allow email updates</span>
            </label>
            <div class="form-hint" style="font-size: 0.8rem; color: #666; margin-left: 1.5rem;">Receive receipts and service updates via email</div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input
                    type="checkbox"
                    name="pref_data_sharing"
                    value="1"
                    <?php echo $data['pref_data_sharing'] ? 'checked' : ''; ?>
                    style="width: auto; margin-right: 0.5rem;"
                >
                <span>Allow data sharing with partners</span>
            </label>
            <div class="form-hint" style="font-size: 0.8rem; color: #666; margin-left: 1.5rem;">Help us improve our services through analytics</div>
        </div>

        <div class="form-group" style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary" style="width: 100%;">Create Account</button>
        </div>

        <p class="text-muted" style="margin-top: 0.9rem; font-size: 0.85rem; text-align: center;">
            Already have an account? <a href="<?php echo e(url('login.php')); ?>">Sign in</a>
        </p>
        
        <p class="text-muted" style="margin-top: 0.5rem; font-size: 0.85rem; text-align: center;">
            Want to become a driver? <a href="<?php echo e(url('register_driver.php')); ?>">Register as driver</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
