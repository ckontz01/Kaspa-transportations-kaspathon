<?php
declare(strict_types=1);

/**
 * Global configuration for the OSRH web app.
 */

// App name
if (!defined('APP_NAME')) {
    define('APP_NAME', 'OSRH');
}

// Environment: 'development' or 'production'
if (!defined('APP_ENV')) {
    define('APP_ENV', 'development'); // keep this for local
}

// Base URL of your app on the local XAMPP server
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/osrh/');
}

// ---------- DATABASE SETTINGS ----------
// Local SQL Server Express with Windows Authentication

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost\SQLEXPRESS');   // instance name from installer
}
if (!defined('DB_DATABASE')) {
    define('DB_DATABASE', 'OSRH_DB');           // the DB you created in SSMS
}

// For local Windows Authentication these are NOT used;
// leave them empty or make sure db_connect.php ignores them.
if (!defined('DB_USERNAME')) {
    define('DB_USERNAME', '');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', '');
}

// ---------- PASSWORD HASHING SETTINGS ----------

if (!defined('PASSWORD_ITERATIONS')) {
    define('PASSWORD_ITERATIONS', 100000);
}
if (!defined('PASSWORD_HASH_BYTES')) {
    define('PASSWORD_HASH_BYTES', 64);
}
if (!defined('PASSWORD_SALT_BYTES')) {
    define('PASSWORD_SALT_BYTES', 32);
}

// ---------- TIMEZONE & ERROR REPORTING ----------

date_default_timezone_set('Europe/Nicosia');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// ---------- SESSION SETUP ----------

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/');
    }

    session_name('osrh_session');
    session_start();
}
