<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';

// If already logged in, shove them to their dashboard.
if (is_logged_in()) {
    auth_redirect_after_login();
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Hero Section */
.hero {
    min-height: 80vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 2rem 1.5rem;
    position: relative;
    overflow: hidden;
}

.hero::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 20%, rgba(59, 130, 246, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 70% 80%, rgba(16, 185, 129, 0.1) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.08) 0%, transparent 50%);
    animation: gradientMove 15s ease-in-out infinite;
    z-index: 0;
}

@keyframes gradientMove {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    25% { transform: translate(2%, 2%) rotate(1deg); }
    50% { transform: translate(-1%, 3%) rotate(-1deg); }
    75% { transform: translate(1%, -2%) rotate(0.5deg); }
}

.hero-content {
    position: relative;
    z-index: 1;
    max-width: 900px;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 50px;
    font-size: 0.85rem;
    color: #60a5fa;
    margin-bottom: 1.5rem;
    animation: fadeInUp 0.6s ease-out;
}

.hero-badge .pulse {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

.hero h1 {
    font-size: clamp(2.5rem, 6vw, 4rem);
    font-weight: 800;
    line-height: 1.1;
    margin: 0 0 1rem 0;
    background: linear-gradient(135deg, #ffffff 0%, #94a3b8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: fadeInUp 0.6s ease-out 0.1s both;
}

.hero h1 span {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #10b981 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.hero-subtitle {
    font-size: clamp(1rem, 2.5vw, 1.25rem);
    color: var(--color-text-muted);
    max-width: 600px;
    margin: 0 auto 2rem;
    line-height: 1.6;
    animation: fadeInUp 0.6s ease-out 0.2s both;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.hero-buttons {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    animation: fadeInUp 0.6s ease-out 0.3s both;
}

.hero-buttons .btn-hero {
    padding: 1rem 2rem;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 12px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-hero.primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.4);
}

.btn-hero.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 30px rgba(59, 130, 246, 0.5);
}

.btn-hero.secondary {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: white;
}

.btn-hero.secondary:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.25);
    transform: translateY(-2px);
}

/* Stats Section */
.stats-bar {
    display: flex;
    justify-content: center;
    gap: 3rem;
    margin-top: 4rem;
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    animation: fadeInUp 0.6s ease-out 0.4s both;
    flex-wrap: wrap;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #f9fafb;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin-top: 0.25rem;
}

/* Features Section */
.features-section {
    padding: 4rem 1.5rem;
    max-width: 1200px;
    margin: 0 auto;
}

.section-header {
    text-align: center;
    margin-bottom: 3rem;
}

.section-header h2 {
    font-size: 2rem;
    margin-bottom: 0.75rem;
}

.section-header p {
    font-size: 1.1rem;
    max-width: 600px;
    margin: 0 auto;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.feature-card {
    background: rgba(11, 17, 32, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 2rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.feature-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--feature-color, #3b82f6), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.feature-card:hover {
    border-color: rgba(255, 255, 255, 0.15);
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.feature-card:hover::before {
    opacity: 1;
}

.feature-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin-bottom: 1.25rem;
    background: var(--feature-bg, rgba(59, 130, 246, 0.15));
}

.feature-card h3 {
    font-size: 1.15rem;
    margin-bottom: 0.5rem;
}

.feature-card p {
    font-size: 0.95rem;
    line-height: 1.6;
    margin: 0;
}

.feature-card.passenger { --feature-color: #3b82f6; --feature-bg: rgba(59, 130, 246, 0.15); }
.feature-card.driver { --feature-color: #10b981; --feature-bg: rgba(16, 185, 129, 0.15); }
.feature-card.autonomous { --feature-color: #8b5cf6; --feature-bg: rgba(139, 92, 246, 0.15); }
.feature-card.carshare { --feature-color: #f97316; --feature-bg: rgba(249, 115, 22, 0.15); }
.feature-card.safe { --feature-color: #f59e0b; --feature-bg: rgba(245, 158, 11, 0.15); }

/* CTA Section */
.cta-section {
    padding: 4rem 1.5rem;
    max-width: 1000px;
    margin: 0 auto;
}

.cta-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.cta-card {
    background: linear-gradient(145deg, rgba(11, 17, 32, 0.8), rgba(11, 17, 32, 0.4));
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 2.5rem 2rem;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.cta-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: var(--cta-gradient);
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 0;
}

.cta-card:hover::before {
    opacity: 1;
}

.cta-card > * {
    position: relative;
    z-index: 1;
}

.cta-card.passenger-cta {
    --cta-gradient: linear-gradient(145deg, rgba(59, 130, 246, 0.1), transparent);
}

.cta-card.driver-cta {
    --cta-gradient: linear-gradient(145deg, rgba(16, 185, 129, 0.1), transparent);
}

.cta-card.carshare-cta {
    --cta-gradient: linear-gradient(145deg, rgba(249, 115, 22, 0.1), transparent);
}

.cta-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255, 255, 255, 0.2);
}

.cta-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1.5rem;
    background: var(--icon-bg, rgba(255, 255, 255, 0.1));
}

.cta-card.passenger-cta .cta-icon { --icon-bg: rgba(59, 130, 246, 0.2); }
.cta-card.driver-cta .cta-icon { --icon-bg: rgba(16, 185, 129, 0.2); }
.cta-card.carshare-cta .cta-icon { --icon-bg: rgba(249, 115, 22, 0.2); }

.cta-card h3 {
    font-size: 1.5rem;
    margin-bottom: 0.75rem;
}

.cta-card p {
    font-size: 0.95rem;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.cta-card .btn-cta {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.75rem;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 10px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.cta-card.passenger-cta .btn-cta {
    background: #3b82f6;
    color: white;
}

.cta-card.driver-cta .btn-cta {
    background: #10b981;
    color: white;
}

.cta-card.carshare-cta .btn-cta {
    background: #f97316;
    color: white;
}

.cta-card .btn-cta:hover {
    transform: scale(1.05);
}

/* Login Banner */
.login-banner {
    text-align: center;
    padding: 3rem 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.login-banner p {
    font-size: 1.1rem;
    margin-bottom: 1rem;
}

.login-banner a {
    color: #60a5fa;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s;
}

.login-banner a:hover {
    color: #93c5fd;
}

/* How It Works */
.how-it-works {
    padding: 4rem 1.5rem;
    max-width: 1000px;
    margin: 0 auto;
}

.steps-container {
    display: flex;
    justify-content: space-between;
    gap: 2rem;
    flex-wrap: wrap;
}

.step {
    flex: 1;
    min-width: 200px;
    text-align: center;
    position: relative;
}

.step-number {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 700;
    color: white;
    margin: 0 auto 1rem;
}

.step h4 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
}

.step p {
    font-size: 0.9rem;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-bar {
        gap: 2rem;
    }
    
    .stat-value {
        font-size: 1.5rem;
    }
    
    .hero-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .hero-buttons .btn-hero {
        width: 100%;
        max-width: 280px;
        justify-content: center;
    }
}
</style>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-content">
        <div class="hero-badge">
            <span class="pulse"></span>
            One-Stop Ride-Hail
        </div>
        
        <h1>Your Ride,<br><span>Your Way</span></h1>
        
        <p class="hero-subtitle">
            Experience seamless transportation with Kaspa Transportations! Connecting passengers with drivers, 
            autonomous vehicles, and flexible car-sharing for safe, reliable, on-demand mobility.
        </p>
        
        <div class="hero-buttons">
            <a href="#register" class="btn-hero primary">
                🚀 Get Started Free
            </a>
            <a href="<?php echo e(url('login.php')); ?>" class="btn-hero secondary">
                Sign In →
            </a>
        </div>
        
        <div class="stats-bar">
            <div class="stat-item" style="background: linear-gradient(135deg, #0a2e2a 0%, #134e4a 100%); border: 2px solid #49EACB; border-radius: 12px; padding: 0.8rem;">
                <div class="stat-value" style="color: #49EACB; font-size: 1.2rem;">0%</div>
                <div class="stat-label" style="color: #a7f3d0; font-weight: 600;">No Middleman Fee!</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">24/7</div>
                <div class="stat-label">Availability</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">🔒</div>
                <div class="stat-label">Secure Platform</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">🚗</div>
                <div class="stat-label">Verified Drivers</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">🤖</div>
                <div class="stat-label">Autonomous Ready</div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="features-section">
    <div class="section-header">
        <h2>Why Choose Kaspa?</h2>
        <p>A complete ride-hailing ecosystem designed for the University of Cyprus community</p>
    </div>
    
    <div class="features-grid">
        <div class="feature-card passenger">
            <div class="feature-icon">🎯</div>
            <h3>Easy Booking</h3>
            <p>Request rides in seconds with our intuitive interface. Set your pickup and dropoff, choose your ride type, and you're on your way.</p>
        </div>
        
        <div class="feature-card driver">
            <div class="feature-icon">💰</div>
            <h3>Earn as a Driver</h3>
            <p>Join our verified driver network. Set your own schedule, accept rides in your area, and earn money on your own terms.</p>
        </div>
        
        <div class="feature-card autonomous">
            <div class="feature-icon">🤖</div>
            <h3>Autonomous Vehicles</h3>
            <p>Experience the future of transportation with our autonomous vehicle fleet. Safe, efficient, and available within designated zones.</p>
        </div>
        
        <div class="feature-card carshare">
            <div class="feature-icon">🔑</div>
            <h3>Car Sharing</h3>
            <p>Rent vehicles by the minute, hour, or day. Pick up and drop off at convenient zones across Cyprus. Freedom to drive yourself.</p>
        </div>
        
        <div class="feature-card safe">
            <div class="feature-icon">🛡️</div>
            <h3>Safe & Verified</h3>
            <p>All drivers undergo verification. Real-time tracking, secure payments, and 24/7 support ensure your peace of mind.</p>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="how-it-works">
    <div class="section-header">
        <h2>How It Works</h2>
        <p>Get moving in just a few simple steps</p>
    </div>
    
    <div class="steps-container">
        <div class="step">
            <div class="step-number">1</div>
            <h4>Create Account</h4>
            <p>Sign up as a passenger or driver in under a minute</p>
        </div>
        
        <div class="step">
            <div class="step-number">2</div>
            <h4>Request or Accept</h4>
            <p>Passengers request rides, drivers accept and earn</p>
        </div>
        
        <div class="step">
            <div class="step-number">3</div>
            <h4>Track & Ride</h4>
            <p>Real-time tracking from pickup to destination</p>
        </div>
        
        <div class="step">
            <div class="step-number">4</div>
            <h4>Rate & Pay</h4>
            <p>Secure payment and feedback system</p>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section" id="register">
    <div class="section-header">
        <h2>Ready to Get Started?</h2>
        <p>Join thousands of users already enjoying smart transportation</p>
    </div>
    
    <div class="cta-cards">
        <div class="cta-card passenger-cta">
            <div class="cta-icon">🚕</div>
            <h3>Ride with Us</h3>
            <p>Create your passenger account and start requesting rides today. Fast, reliable, and always available.</p>
            <a href="<?php echo e(url('register_passenger.php')); ?>" class="btn-cta">
                Register as Passenger →
            </a>
        </div>
        
        <div class="cta-card driver-cta">
            <div class="cta-icon">🚗</div>
            <h3>Drive with Us</h3>
            <p>Become a verified driver and start earning. Flexible hours, great earnings, and full support.</p>
            <a href="<?php echo e(url('register_driver.php')); ?>" class="btn-cta">
                Register as Driver →
            </a>
        </div>
        
        <div class="cta-card carshare-cta">
            <div class="cta-icon">🔑</div>
            <h3>Rent a Car</h3>
            <p>Drive yourself with our car-sharing fleet. Register as a passenger and get approved for self-drive rentals.</p>
            <a href="<?php echo e(url('register_passenger.php')); ?>" class="btn-cta">
                Get Started →
            </a>
        </div>
    </div>
</section>

<!-- Login Banner -->
<div class="login-banner">
    <p>Already have an account? <a href="<?php echo e(url('login.php')); ?>">Sign in here</a></p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
