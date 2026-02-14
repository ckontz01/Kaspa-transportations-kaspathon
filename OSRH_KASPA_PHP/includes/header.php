<?php
/**
 * Header template - included by pages to render HTML head and navbar
 * Note: Do NOT use declare(strict_types=1) here as this is an included file
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/helpers.php';
}
if (!function_exists('current_user')) {
    require_once __DIR__ . '/roles.php';
}
if (!function_exists('flash_render')) {
    require_once __DIR__ . '/flash.php';
}

// Page title - can be set before including header
if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($pageTitle); ?> | <?php echo e(APP_NAME); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Base styles -->
    <link rel="stylesheet" href="<?php echo e(url('assets/css/main.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/layout.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/components.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/maps.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/responsive.css')); ?>">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">

    <!-- QR Code library for Kaspa payments -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <!-- Modal system - must load early for inline scripts -->
    <script src="<?php echo e(url('assets/js/modal.js')); ?>"></script>

    <link rel="icon" type="image/x-icon" href="<?php echo e(url('assets/img/favicon.ico')); ?>">
</head>
<body class="theme-dark">
<div class="app">
    <?php require_once __DIR__ . '/navbar.php'; ?>

    <main class="app-main">
        <div class="container">
            <?php flash_render(); ?>
