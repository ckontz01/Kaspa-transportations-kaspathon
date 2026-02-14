<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';

require_login();
require_role('passenger');

$user = current_user();
$passengerRow = $user['passenger'] ?? null;

if (!$passengerRow || !isset($passengerRow['PassengerID'])) {
    http_response_code(403);
    echo 'Passenger profile not found.';
    exit;
}

$passengerId = (int)$passengerRow['PassengerID'];
$userId = (int)$user['id'];
$errors = [];

// Get passenger's Kaspa wallet (for sending payments)
$kaspaWallet = kaspa_get_default_wallet($userId, 'send');

// Handle Kaspa wallet update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_kaspa_wallet') {
    $token = array_get($_POST, 'csrf_token', null);
    
    if (!verify_csrf_token($token)) {
        flash_add('error', 'Security check failed. Please try again.');
    } else {
        $walletAddress = trim((string)array_get($_POST, 'kaspa_wallet', ''));
        $walletLabel = trim((string)array_get($_POST, 'wallet_label', '')) ?: 'My Kaspa Wallet';
        
        if (empty($walletAddress)) {
            // Allow clearing wallet
            flash_add('info', 'Kaspa wallet cleared.');
            redirect('passenger/settings.php');
        } else {
            $result = kaspa_add_wallet($userId, $walletAddress, 'send', $walletLabel, true);
            
            if ($result['success']) {
                flash_add('success', '💎 Kaspa wallet saved successfully! You can now pay for rides with KAS.');
                redirect('passenger/settings.php');
            } else {
                flash_add('error', $result['error'] ?? 'Failed to save wallet address.');
            }
        }
    }
}

// Page meta
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div style="padding: 1rem; max-width: 700px; margin: 0 auto;">
    <h1 style="font-size: 1.3rem; margin-bottom: 1.5rem;">⚙️ Settings</h1>

    <!-- Account Info -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <h2 class="card-title">👤 Account Information</h2>
        </div>
        <div class="card-body" style="padding: 1.2rem;">
            <div style="display: grid; gap: 0.8rem;">
                <div>
                    <span style="color: #666; font-size: 0.85rem;">Name</span>
                    <div style="font-weight: 500;"><?php echo e($user['name'] ?? 'Not set'); ?></div>
                </div>
                <div>
                    <span style="color: #666; font-size: 0.85rem;">Email</span>
                    <div style="font-weight: 500;"><?php echo e($user['email'] ?? 'Not set'); ?></div>
                </div>
                <div>
                    <span style="color: #666; font-size: 0.85rem;">Phone</span>
                    <div style="font-weight: 500;"><?php echo e($user['passenger']['Phone'] ?? 'Not set'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Kaspa Wallet Section -->
    <div class="card">
        <div class="card-header" style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1) 0%, rgba(112, 184, 176, 0.1) 100%); border-bottom: 1px solid rgba(73, 234, 203, 0.3);">
            <div>
                <h2 class="card-title" style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="color: #49EACB;">💎</span> Kaspa Wallet
                </h2>
                <p class="text-muted" style="font-size:0.86rem;margin-top:0.25rem;">
                    Pay for rides with Kaspa (KAS) cryptocurrency — <strong style="color: #49EACB;">0% platform fees!</strong>
                </p>
            </div>
        </div>
        <div class="card-body" style="padding:1.2rem;">
            <?php if ($kaspaWallet): ?>
                <div style="background: rgba(73, 234, 203, 0.1); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <div style="font-size: 0.8rem; color: #70B8B0; margin-bottom: 0.3rem;">
                                <?php echo e($kaspaWallet['Label'] ?? 'Your Kaspa Wallet'); ?>
                                <?php if ($kaspaWallet['IsVerified']): ?>
                                    <span style="color: #10b981; margin-left: 0.3rem;">✓ Verified</span>
                                <?php endif; ?>
                            </div>
                            <code style="font-size: 0.85rem; color: #49EACB; word-break: break-all;">
                                <?php echo e($kaspaWallet['WalletAddress']); ?>
                            </code>
                        </div>
                        <a href="<?php echo e(kaspa_address_explorer_url($kaspaWallet['WalletAddress'])); ?>" 
                           target="_blank" 
                           style="font-size: 0.8rem; color: #70B8B0; text-decoration: none;">
                            View in Explorer →
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo e(url('passenger/settings.php')); ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_kaspa_wallet">
                
                <div class="form-group">
                    <label class="form-label" for="kaspa_wallet">
                        Kaspa Wallet Address
                        <span style="font-size: 0.8rem; color: #666; font-weight: normal;">(starts with kaspa:)</span>
                    </label>
                    <input 
                        type="text" 
                        id="kaspa_wallet" 
                        name="kaspa_wallet" 
                        class="form-control"
                        placeholder="kaspa:qp..."
                        value="<?php echo e($kaspaWallet['WalletAddress'] ?? ''); ?>"
                        style="font-family: Monaco, Menlo, monospace; font-size: 0.9rem;"
                    >
                    <p style="font-size: 0.8rem; color: #666; margin-top: 0.3rem;">
                        Enter your Kaspa wallet address to enable crypto payments. Get a wallet from 
                        <a href="https://kasware.xyz" target="_blank" style="color: #49EACB;">KasWare</a>,
                        <a href="https://kaspium.io" target="_blank" style="color: #49EACB;">Kaspium</a>, or
                        <a href="https://wallet.kaspa.org" target="_blank" style="color: #49EACB;">Kaspa Web Wallet</a>.
                    </p>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="wallet_label">Wallet Label (optional)</label>
                    <input 
                        type="text" 
                        id="wallet_label" 
                        name="wallet_label" 
                        class="form-control"
                        placeholder="My Kaspa Wallet"
                        value="<?php echo e($kaspaWallet['Label'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group" style="margin-top: 1rem;">
                    <button type="submit" class="btn" style="background: linear-gradient(135deg, #49EACB 0%, #70B8B0 100%); color: #1a1a2e; font-weight: 600;">
                        💎 Save Kaspa Wallet
                    </button>
                </div>
            </form>
            
            <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(34, 197, 94, 0.1); border-radius: 8px; font-size: 0.85rem;">
                <strong style="color: #22c55e;">✓ Why pay with Kaspa?</strong>
                <ul style="margin: 0.5rem 0 0 1rem; color: #666; line-height: 1.6;">
                    <li><strong>0% platform fees</strong> — drivers keep 100% of your payment</li>
                    <li><strong>Instant confirmation</strong> — payments settle in ~1 second</li>
                    <li><strong>Secure</strong> — cryptographically verified on the blockchain</li>
                    <li><strong>Private</strong> — no bank or card details needed</li>
                </ul>
            </div>

            <!-- Browser Wallet Connection -->
            <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(73, 234, 203, 0.05); border: 1px solid rgba(73, 234, 203, 0.2); border-radius: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <strong style="color: #49EACB; font-size: 0.9rem;">🔗 Browser Wallet</strong>
                        <p style="font-size: 0.8rem; color: #666; margin: 0.3rem 0 0;">
                            Connect KasWare extension for one-click payments
                        </p>
                    </div>
                    <button type="button" id="connectWalletBtn" onclick="connectKaspaWallet()" class="btn btn-secondary btn-small">
                        Connect Wallet
                    </button>
                </div>
                <div id="walletConnectionStatus" style="margin-top: 0.5rem; font-size: 0.8rem; display: none;">
                    <!-- Status will be shown here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Preferences Section -->
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h2 class="card-title">🎛️ Preferences</h2>
        </div>
        <div class="card-body" style="padding: 1.2rem;">
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="preferKaspa" <?php if ($kaspaWallet): ?>checked<?php endif; ?> onchange="savePreference('preferKaspa', this.checked)">
                    <span>Default to Kaspa payments when available</span>
                </label>
                <p style="font-size: 0.8rem; color: #666; margin: 0.3rem 0 0 1.5rem;">
                    Automatically select Kaspa as payment method when the driver accepts KAS
                </p>
            </div>
        </div>
    </div>
</div>

<script>
// Check for KasWare browser extension
async function connectKaspaWallet() {
    const statusDiv = document.getElementById('walletConnectionStatus');
    const btn = document.getElementById('connectWalletBtn');
    statusDiv.style.display = 'block';
    
    // Check for KasWare (most popular Kaspa browser wallet)
    if (typeof window.kasware !== 'undefined') {
        try {
            btn.disabled = true;
            btn.textContent = 'Connecting...';
            
            const accounts = await window.kasware.requestAccounts();
            if (accounts && accounts.length > 0) {
                const address = accounts[0];
                statusDiv.innerHTML = '<span style="color: #22c55e;">✓ Connected: </span><code style="font-size: 0.75rem; color: #49EACB;">' + address.substring(0, 20) + '...' + address.slice(-8) + '</code>';
                btn.textContent = 'Connected';
                btn.style.background = 'rgba(34, 197, 94, 0.2)';
                btn.style.borderColor = '#22c55e';
                btn.style.color = '#22c55e';
                
                // Auto-fill wallet address if empty
                const walletInput = document.getElementById('kaspa_wallet');
                if (walletInput && !walletInput.value) {
                    walletInput.value = address;
                }
            }
        } catch (err) {
            statusDiv.innerHTML = '<span style="color: #ef4444;">✗ Connection rejected or failed</span>';
            btn.disabled = false;
            btn.textContent = 'Try Again';
        }
    } else {
        statusDiv.innerHTML = '<span style="color: #f59e0b;">⚠️ KasWare extension not found. </span><a href="https://kasware.xyz" target="_blank" style="color: #49EACB;">Install KasWare →</a>';
        btn.textContent = 'Install Wallet';
        btn.onclick = function() { window.open('https://kasware.xyz', '_blank'); };
    }
}

// Save user preference
function savePreference(key, value) {
    // Store in localStorage for now
    localStorage.setItem('osrh_' + key, value ? '1' : '0');
}

// Check wallet connection on page load
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.kasware !== 'undefined') {
        // KasWare is available, check if already connected
        window.kasware.getAccounts().then(function(accounts) {
            if (accounts && accounts.length > 0) {
                const statusDiv = document.getElementById('walletConnectionStatus');
                const btn = document.getElementById('connectWalletBtn');
                statusDiv.style.display = 'block';
                statusDiv.innerHTML = '<span style="color: #22c55e;">✓ KasWare detected: </span><code style="font-size: 0.75rem; color: #49EACB;">' + accounts[0].substring(0, 20) + '...</code>';
                btn.textContent = 'Connected';
                btn.style.background = 'rgba(34, 197, 94, 0.2)';
                btn.style.borderColor = '#22c55e';
                btn.style.color = '#22c55e';
            }
        }).catch(function() {});
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
