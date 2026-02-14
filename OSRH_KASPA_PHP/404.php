<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/flash.php';

http_response_code(404);
$pageTitle = 'Page not found';

require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin:2.5rem auto 2rem;max-width:720px;text-align:center;">
    <div class="card-header" style="justify-content:center;">
        <h1 class="card-title">404 – Page not found</h1>
    </div>
    <div class="card-body" style="padding:1rem 1.5rem 1.4rem;">
        <p class="text-muted" style="font-size:0.9rem;margin-bottom:1rem;">
            The page you’re looking for doesn’t exist or is no longer available.
        </p>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:1.4rem;">
            If you typed the address manually, check the URL. Otherwise, use the main navigation to get back.
        </p>
        <a href="<?php echo e(url('index.php')); ?>" class="btn btn-primary">
            Back to home
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
