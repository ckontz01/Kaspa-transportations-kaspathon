<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';

if (is_logged_in()) {
    auth_redirect_after_login();
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)array_get($_POST, 'email', ''));
    $password = (string)array_get($_POST, 'password', '');
    $token    = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if (!$errors) {
            // First check if this email belongs to a driver with pending/rejected status
            $stmt = db_call_procedure('dbo.spCheckUserByEmail', [$email]);
            $userCheck = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
            if ($stmt) sqlsrv_free_stmt($stmt);
            
            if ($userCheck) {
                $stmt = db_call_procedure('dbo.spGetDriverVerificationStatus', [(int)$userCheck['UserID']]);
                $driverCheck = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                if ($stmt) sqlsrv_free_stmt($stmt);
                
                if ($driverCheck) {
                    $status = $driverCheck['VerificationStatus'];
                    if ($status === 'pending') {
                        $errors['pending'] = true;
                    } elseif ($status === 'rejected') {
                        $errors['rejected'] = true;
                    }
                }
            }
            
            // Only attempt login if not pending/rejected
            if (empty($errors['pending']) && empty($errors['rejected'])) {
                if (auth_attempt_login($email, $password)) {
                    flash_add('success', 'Welcome back.');
                    auth_redirect_after_login();
                } else {
                    $errors['general'] = 'Invalid email or password.';
                }
            }
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.login-container {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}

.login-wrapper {
    display: grid;
    grid-template-columns: 1fr 1fr;
    max-width: 1000px;
    width: 100%;
    background: rgba(11, 17, 32, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
}

.login-brand {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(16, 185, 129, 0.08) 100%);
    padding: 3rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.login-brand::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 70%, rgba(59, 130, 246, 0.2) 0%, transparent 50%);
    animation: floatBg 20s ease-in-out infinite;
}

@keyframes floatBg {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(5%, -5%); }
}

.login-brand > * {
    position: relative;
    z-index: 1;
}

.brand-logo {
    font-size: 2.5rem;
    margin-bottom: 1rem;
}

.brand-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.75rem;
    background: linear-gradient(135deg, #ffffff 0%, #94a3b8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.brand-subtitle {
    font-size: 1rem;
    color: var(--color-text-muted);
    line-height: 1.6;
    margin-bottom: 2rem;
}

.brand-features {
    list-style: none;
    padding: 0;
    margin: 0;
}

.brand-features li {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 0;
    font-size: 0.95rem;
    color: var(--color-text-muted);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.brand-features li:last-child {
    border-bottom: none;
}

.brand-features .feature-icon {
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.login-form-section {
    padding: 3rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.login-header {
    margin-bottom: 2rem;
}

.login-header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
}

.login-header p {
    font-size: 0.95rem;
    color: var(--color-text-muted);
}

.login-form .form-group {
    margin-bottom: 1.25rem;
}

.login-form .form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #e5e7eb;
}

.login-form .form-control {
    width: 100%;
    padding: 0.875rem 1rem;
    font-size: 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    color: white;
    transition: all 0.2s ease;
}

.login-form .form-control:focus {
    outline: none;
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.08);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.login-form .form-control.input-error {
    border-color: #ef4444;
}

.login-form .form-error {
    font-size: 0.8rem;
    color: #ef4444;
    margin-top: 0.375rem;
}

.login-btn {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
    font-weight: 600;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 0.5rem;
}

.login-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.login-divider {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin: 1.5rem 0;
}

.login-divider::before,
.login-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255, 255, 255, 0.1);
}

.login-divider span {
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.register-links {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0.75rem;
}

.register-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    color: #e5e7eb;
    text-decoration: none;
    transition: all 0.2s ease;
}

.register-link:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
}

.register-link.passenger:hover {
    border-color: rgba(59, 130, 246, 0.5);
    background: rgba(59, 130, 246, 0.1);
}

.register-link.driver:hover {
    border-color: rgba(16, 185, 129, 0.5);
    background: rgba(16, 185, 129, 0.1);
}

.register-link.carshare:hover {
    border-color: rgba(249, 115, 22, 0.5);
    background: rgba(249, 115, 22, 0.1);
}

.flash-error-custom {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 10px;
    padding: 0.875rem 1rem;
    margin-bottom: 1.25rem;
    font-size: 0.9rem;
    color: #fca5a5;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-text-muted);
    text-decoration: none;
    margin-bottom: 1.5rem;
    transition: color 0.2s;
}

.back-link:hover {
    color: #60a5fa;
}

/* Status pages */
.status-container {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}

.status-card {
    max-width: 480px;
    text-align: center;
    background: rgba(11, 17, 32, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 24px;
    padding: 3rem;
}

.status-icon {
    font-size: 4rem;
    margin-bottom: 1.5rem;
}

.status-card h1 {
    font-size: 1.75rem;
    margin-bottom: 0.75rem;
}

.status-card p {
    font-size: 1rem;
    color: var(--color-text-muted);
    margin-bottom: 2rem;
    line-height: 1.6;
}

.status-card .btn {
    padding: 0.875rem 2rem;
    font-size: 1rem;
}

@media (max-width: 768px) {
    .login-wrapper {
        grid-template-columns: 1fr;
        max-width: 480px;
    }
    
    .login-brand {
        display: none;
    }
    
    .login-form-section {
        padding: 2rem;
    }
    
    .register-links {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (!empty($errors['pending'])): ?>
<div class="status-container">
    <div class="status-card">
        <div class="status-icon">⏳</div>
        <h1>Pending Approval</h1>
        <p>Your driver account is waiting for operator approval. You will be able to log in once an operator reviews your application.</p>
        <a href="<?php echo e(url('index.php')); ?>" class="btn btn-primary">
            ← Back to Homepage
        </a>
    </div>
</div>
<?php elseif (!empty($errors['rejected'])): ?>
<div class="status-container">
    <div class="status-card">
        <div class="status-icon">❌</div>
        <h1>Application Rejected</h1>
        <p>Unfortunately, your driver application has been rejected. Please contact support for more information.</p>
        <a href="<?php echo e(url('index.php')); ?>" class="btn btn-primary">
            ← Back to Homepage
        </a>
    </div>
</div>
<?php else: ?>

<div class="login-container">
    <div class="login-wrapper">
        <!-- Left Side - Branding -->
        <div class="login-brand">
            <div class="brand-logo">🚗</div>
            <h2 class="brand-title">Welcome to OSRH</h2>
            <p class="brand-subtitle">
                Your smart ride-hailing platform. Connect with drivers and autonomous vehicles for seamless transportation.
            </p>
            
            <ul class="brand-features">
                <li>
                    <span class="feature-icon">🎯</span>
                    <span>Easy ride booking in seconds</span>
                </li>
                <li>
                    <span class="feature-icon">🔒</span>
                    <span>Secure and verified platform</span>
                </li>
                <li>
                    <span class="feature-icon">🤖</span>
                    <span>Autonomous vehicle support</span>
                </li>
                <li>
                    <span class="feature-icon">🔑</span>
                    <span>Flexible car sharing</span>
                </li>
                <li>
                    <span class="feature-icon">💰</span>
                    <span>Transparent pricing</span>
                </li>
            </ul>
        </div>
        
        <!-- Right Side - Login Form -->
        <div class="login-form-section">
            <a href="<?php echo e(url('index.php')); ?>" class="back-link">
                ← Back to home
            </a>
            
            <div class="login-header">
                <h1>Sign In</h1>
                <p>Enter your credentials to access your account</p>
            </div>
            
            <?php if (!empty($errors['general'])): ?>
            <div class="flash-error-custom">
                <span>⚠️</span>
                <span><?php echo e($errors['general']); ?></span>
            </div>
            <?php endif; ?>
            
            <form method="post" class="login-form js-validate" novalidate>
                <?php csrf_field(); ?>
                
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control<?php echo !empty($errors['email']) ? ' input-error' : ''; ?>"
                        value="<?php echo e($email); ?>"
                        placeholder="name@example.com"
                        data-required="1"
                        autocomplete="email"
                    >
                    <?php if (!empty($errors['email'])): ?>
                        <div class="form-error"><?php echo e($errors['email']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control<?php echo !empty($errors['password']) ? ' input-error' : ''; ?>"
                        placeholder="Enter your password"
                        data-required="1"
                        autocomplete="current-password"
                    >
                    <?php if (!empty($errors['password'])): ?>
                        <div class="form-error"><?php echo e($errors['password']); ?></div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="login-btn">
                    Sign In →
                </button>
            </form>
            
            <div class="login-divider">
                <span>New to OSRH?</span>
            </div>
            
            <div class="register-links">
                <a href="<?php echo e(url('register_passenger.php')); ?>" class="register-link passenger">
                    🚕 Passenger
                </a>
                <a href="<?php echo e(url('register_driver.php')); ?>" class="register-link driver">
                    🚗 Driver
                </a>
                <a href="<?php echo e(url('register_passenger.php')); ?>" class="register-link carshare">
                    🔑 Car Share
                </a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
