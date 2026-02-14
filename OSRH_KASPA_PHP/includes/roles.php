<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Get the current authenticated user array from the session or null.
 * Structure (set in auth_set_logged_in_user):
 *   [
 *     'id'       => int,
 *     'email'    => string,
 *     'name'     => string,
 *     'roles'    => ['passenger' => bool, 'driver' => bool, 'operator' => bool, 'admin' => bool],
 *     'passenger'=> array|null,
 *     'driver'   => array|null,
 *     'operator' => array|null,
 *   ]
 */
function current_user(): ?array
{
    return (isset($_SESSION['user']) && is_array($_SESSION['user']))
        ? $_SESSION['user']
        : null;
}

/**
 * Get current user ID or null.
 */
function current_user_id(): ?int
{
    $user = current_user();
    return $user ? (int)$user['id'] : null;
}

/**
 * Get current user name or null.
 */
function current_user_name(): ?string
{
    $user = current_user();
    return ($user && isset($user['name'])) ? (string)$user['name'] : null;
}

/**
 * Get current user email or null.
 */
function current_user_email(): ?string
{
    $user = current_user();
    return ($user && isset($user['email'])) ? (string)$user['email'] : null;
}

/**
 * Get roles flags array or [].
 */
function current_user_roles(): array
{
    $user = current_user();
    if ($user && isset($user['roles']) && is_array($user['roles'])) {
        return $user['roles'];
    }
    return [];
}

/**
 * Check if the current user has a specific role flag.
 * Roles: 'passenger', 'driver', 'operator', 'admin'.
 */
function user_has_role(string $role): bool
{
    $roles = current_user_roles();
    return !empty($roles[$role]);
}

/**
 * Convenience wrappers for role checks.
 */
function is_passenger(): bool
{
    return user_has_role('passenger');
}

function is_driver(): bool
{
    return user_has_role('driver');
}

function is_operator(): bool
{
    // Admins are also considered operators.
    return user_has_role('operator') || user_has_role('admin');
}

function is_admin(): bool
{
    return user_has_role('admin');
}

/**
 * Single "primary" role for UI decisions (e.g. which dashboard to show).
 */
function primary_role(): ?string
{
    $priority = ['admin', 'operator', 'driver', 'passenger'];

    foreach ($priority as $role) {
        if (user_has_role($role)) {
            return $role;
        }
    }

    return null;
}

/**
 * Whether any user is logged in.
 */
function is_logged_in(): bool
{
    return current_user_id() !== null;
}
