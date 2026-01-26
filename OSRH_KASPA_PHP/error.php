<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/flash.php';

$code = (int)($_GET['code'] ?? 500);
if ($code < 400 || $code > 599) {
    $code = 500;
}
http_response_code($code);

$title = 'Unexpected error';
$message = 'Something went wrong while processing your request.';

if ($code === 403) {
    $title = 'Access denied';
    $message = 'You do not have permission to view this resource.';
} elseif ($code === 404) {
    $title = 'Page not found';
    $message = 'The page you requested could not be found.';
}

$pageTitle = $title;

require_once __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin:2.5rem auto 2rem;max-width:720px;text-align:center;">
    <div class="card-header" style="justify-content:center;">
        <h1 class="card-title"><?php echo e($title); ?></h1>
    </div>
    <div class="card-body" style="padding:1rem 1.5rem 1.4rem;">
        <p class="text-muted" style="font-size:0.9rem;margin-bottom:1.4rem;">
            <?php echo e($message); ?>
        </p>
        <a href="<?php echo e(url('index.php')); ?>" class="btn btn-primary" style="margin-right:0.5rem;">
            Back to home
        </a>
        <a href="javascript:history.back();" class="btn btn-ghost">
            Go back
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
