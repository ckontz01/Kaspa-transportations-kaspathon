<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns a singleton SQL Server connection using sqlsrv.
 */
function db_get_connection()
{
    static $connection = null;

    if ($connection !== null) {
        return $connection;
    }

    if (!function_exists('sqlsrv_connect')) {
        // Hard fail: the extension is missing.
        die('The SQLSRV PHP extension is not installed or enabled.');
    }

    // Minimal options: don't set CharacterSet for now
    $connectionOptions = [
        'Database' => DB_DATABASE,
    ];

    // If a username is provided, use SQL auth; otherwise use Windows auth
    if (defined('DB_USERNAME') && DB_USERNAME !== '') {
        $connectionOptions['UID'] = DB_USERNAME;
        $connectionOptions['PWD'] = DB_PASSWORD;
    }

     $connection = sqlsrv_connect(DB_HOST, $connectionOptions);

    if ($connection === false) {
    $errors = sqlsrv_errors();
    error_log('Database connection failed: ' . print_r($errors, true));

    if (APP_ENV === 'development') {
        // show full SQLSRV error info while debugging
        echo '<h3>Database connection failed</h3>';
        echo '<pre>';
        print_r($errors);
        echo '</pre>';
        exit;
    }

    die('Internal server error.');
}


    return $connection;
}
