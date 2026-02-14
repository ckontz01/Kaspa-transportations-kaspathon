<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Convenience wrapper to get the DB connection resource.
 */
function db()
{
    return db_get_connection();
}

/**
 * Normalize parameters for sqlsrv.
 * Allows passing either scalars or full param arrays.
 */
function db_normalize_params(array $params): array
{
    $normalized = [];

    foreach ($params as $param) {
        if (is_array($param)) {
            // Already a full sqlsrv param definition
            $normalized[] = $param;
        } else {
            // Simple IN parameter
            $normalized[] = [$param, SQLSRV_PARAM_IN];
        }
    }

    return $normalized;
}

/**
 * Execute a raw SQL query and return the statement resource (or false).
 */
function db_query(string $sql, array $params = [])
{
    $conn = db();
    $stmt = sqlsrv_query($conn, $sql, $params ? db_normalize_params($params) : []);

    if ($stmt === false) {
        error_log('DB query error: ' . print_r(sqlsrv_errors(), true));
    }

    return $stmt;
}

/**
 * Fetch all rows as associative arrays.
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db_query($sql, $params);
    if ($stmt === false) {
        return [];
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    return $rows;
}

/**
 * Fetch a single row as an associative array or null.
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db_query($sql, $params);
    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row !== null ? $row : null;
}

/**
 * Execute a non-query (INSERT/UPDATE/DELETE).
 * Returns true on success, false on failure.
 */
function db_execute(string $sql, array $params = []): bool
{
    $stmt = db_query($sql, $params);
    if ($stmt === false) {
        return false;
    }

    sqlsrv_free_stmt($stmt);
    return true;
}

/**
 * Build the CALL syntax for a stored procedure with N parameters.
 */
function db_build_procedure_call(string $procedureName, int $paramCount): string
{
    if ($paramCount <= 0) {
        return '{CALL ' . $procedureName . '}';
    }

    $placeholders = implode(',', array_fill(0, $paramCount, '?'));
    return '{CALL ' . $procedureName . '(' . $placeholders . ')}';
}

/**
 * Call a stored procedure and return the statement resource.
 *
 * Example:
 *   $stmt = db_call_procedure('spLoginUser', [$email, $passwordHash]);
 */
function db_call_procedure(string $procedureName, array $params = [])
{
    $sql  = db_build_procedure_call($procedureName, count($params));
    $stmt = sqlsrv_query(db(), $sql, $params ? db_normalize_params($params) : []);

    if ($stmt === false) {
        error_log('DB procedure error [' . $procedureName . ']: ' . print_r(sqlsrv_errors(), true));
    }

    return $stmt;
}

/**
 * Call a stored procedure using EXEC syntax (alternative to {CALL}).
 * Use this when {CALL} syntax has issues with certain parameter types.
 *
 * Example:
 *   $stmt = db_exec_procedure('dbo.spKaspaAddWallet', [$userId, $address, $type, $label, $isDefault]);
 */
function db_exec_procedure(string $procedureName, array $params = [])
{
    $placeholders = count($params) > 0 ? implode(', ', array_fill(0, count($params), '?')) : '';
    $sql = "EXEC $procedureName" . ($placeholders ? " $placeholders" : '');
    
    $normalizedParams = [];
    foreach ($params as $param) {
        $normalizedParams[] = [$param, SQLSRV_PARAM_IN];
    }
    
    $stmt = sqlsrv_query(db(), $sql, $normalizedParams);

    if ($stmt === false) {
        error_log('DB exec procedure error [' . $procedureName . ']: ' . print_r(sqlsrv_errors(), true));
    }

    return $stmt;
}

/**
 * Get last SQL Server errors (for debugging/logging).
 */
function db_last_errors(): ?array
{
    return sqlsrv_errors();
}
