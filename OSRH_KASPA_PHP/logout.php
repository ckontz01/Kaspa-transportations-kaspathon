<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Destroy session & log out
auth_logout();

// Just send them to home. (We skip flash here because session is wiped.)
redirect('index.php');
