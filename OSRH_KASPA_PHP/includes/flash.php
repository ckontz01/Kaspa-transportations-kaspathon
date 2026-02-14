<?php
declare(strict_types=1);

/**
 * Flash message utilities: store in session and display once.
 */

if (!isset($_SESSION)) {
    session_start();
}

/**
 * Add a flash message.
 *
 * $type: 'success', 'error', 'warning', 'info'
 */
function flash_add(string $type, string $message): void
{
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }

    if (!isset($_SESSION['_flash'][$type]) || !is_array($_SESSION['_flash'][$type])) {
        $_SESSION['_flash'][$type] = [];
    }

    $_SESSION['_flash'][$type][] = $message;
}

/**
 * Get and clear all flash messages.
 *
 * @return array [ 'success' => [...], 'error' => [...], ... ]
 */
function flash_get_all(): array
{
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return [];
    }

    $messages = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    return $messages;
}

/**
 * Render all flash messages as HTML.
 */
function flash_render(): void
{
    $messages = flash_get_all();
    if (empty($messages)) {
        return;
    }

    echo '<div class="flash-container">';
    foreach ($messages as $type => $list) {
        foreach ($list as $message) {
            $typeClass = 'flash-' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<div class="flash ' . $typeClass . '">';
            echo '<span class="flash-text">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            echo '<button type="button" class="flash-close" aria-label="Dismiss">&times;</button>';
            echo '</div>';
        }
    }
    echo '</div>';
}
