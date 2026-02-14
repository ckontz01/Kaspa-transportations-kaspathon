<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/roles.php';

/**
 * Attempt to log in a user using email + plain password.
 * Returns true on success, false on failure.
 */
function auth_attempt_login(string $email, string $plainPassword): bool
{
    $email         = trim($email);
    $plainPassword = (string)$plainPassword;

    if ($email === '' || $plainPassword === '') {
        return false;
    }

    // 1) Get the salt for this user (current password only).
    $stmt = db_call_procedure('dbo.spGetUserPasswordSalt', [$email]);
    if ($stmt === false) {
        return false;
    }
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        return false;
    }

    if (isset($row['Status']) && $row['Status'] !== 'active') {
        return false;
    }

    $saltBinary = $row['PasswordSalt'];

    if ($saltBinary === null) {
        return false;
    }

        // 2) Compute password hash using the same salt.
    $hashData     = osrh_password_hash($plainPassword, $saltBinary);
    $passwordHash = $hashData['hash']; // binary

    // 3) Call stored procedure spLoginUser with typed params (VARBINARY for hash)
    $conn = db_get_connection();
    $sql  = '{CALL dbo.spLoginUser(?, ?)}';

    $params = [
        // Email (normal NVARCHAR)
        [$email, SQLSRV_PARAM_IN],
        // PasswordHash as VARBINARY(MAX)
        [
            $passwordHash,
            SQLSRV_PARAM_IN,
            SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
            SQLSRV_SQLTYPE_VARBINARY('max'),
        ],
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        // Optional debug while developing:
        if (APP_ENV === 'development') {
            echo '<pre style="background:#fee;border:1px solid #f99;padding:8px;margin:8px 0;">';
            echo "dbo.spLoginUser SQL errors:\n";
            print_r(sqlsrv_errors());
            echo "</pre>";
        }
        return false;
    }

    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$user) {
        return false;
    }


    // 4) Store user info + roles into the session.
    auth_set_logged_in_user((int)$user['UserID'], (string)$user['Email'], (string)$user['FullName']);

    return true;
}

/**
 * Fill $_SESSION['user'] with user data and role info.
 */
function auth_set_logged_in_user(int $userId, string $email, string $fullName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $rolesInfo = auth_fetch_user_roles($userId);

    $_SESSION['user'] = [
        'id'        => $userId,
        'email'     => $email,
        'name'      => $fullName,
        'roles'     => $rolesInfo['flags'],
        'passenger' => $rolesInfo['passenger'],
        'driver'    => $rolesInfo['driver'],
        'operator'  => $rolesInfo['operator'],
    ];
}

/**
 * Query DB for Passenger / Driver / Operator roles for this user.
 */
function auth_fetch_user_roles(int $userId): array
{
    $flags = [
        'passenger' => false,
        'driver'    => false,
        'operator'  => false,
        'admin'     => false,
    ];

    // Passenger
    $stmt = db_call_procedure('dbo.spGetUserRoles', [$userId]);
    if ($stmt === false) {
        return [
            'flags' => $flags,
            'passenger' => null,
            'driver' => null,
            'operator' => null,
        ];
    }
    
    // First result set: Passenger
    $passenger = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($passenger) {
        $flags['passenger'] = true;
    }
    
    // Second result set: Driver
    sqlsrv_next_result($stmt);
    $driver = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($driver) {
        // Only allow login if driver is approved
        if (isset($driver['VerificationStatus']) && $driver['VerificationStatus'] === 'approved') {
            $flags['driver'] = true;
        } else {
            // Driver not approved - don't grant driver role
            $driver = null;
        }
    }
    
    // Third result set: Operator
    sqlsrv_next_result($stmt);
    $operator = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($operator) {
        $role = strtolower((string)$operator['Role']);
        if ($role === 'admin') {
            $flags['admin']    = true;
            $flags['operator'] = true;
        } else {
            $flags['operator'] = true;
        }
    }
    
    sqlsrv_free_stmt($stmt);

    return [
        'flags'     => $flags,
        'passenger' => $passenger,
        'driver'    => $driver,
        'operator'  => $operator,
    ];
}

/**
 * Require that *someone* is logged in.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/**
 * Require that current user has at least one of the given roles.
 */
function require_role($roles): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }

    $rolesList = is_array($roles) ? $roles : [$roles];

    foreach ($rolesList as $role) {
        if (user_has_role($role)) {
            return;
        }
    }

    redirect('error.php?code=403');
}

/**
 * Log out current user and kill the session.
 */
function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Redirect to the default dashboard based on primary role.
 */
function auth_redirect_after_login(): void
{
    $role = primary_role();

    if ($role === 'passenger') {
        redirect('passenger/dashboard.php');
    } elseif ($role === 'driver') {
        redirect('driver/dashboard.php');
    } elseif ($role === 'operator' || $role === 'admin') {
        redirect('operator/dashboard.php');
    } else {
        redirect('index.php');
    }
}
