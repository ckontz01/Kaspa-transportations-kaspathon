<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();

$currentUserId = current_user_id();

// Load user profile for privacy preferences
$stmt = db_call_procedure('dbo.spGetUserProfile', [$currentUserId]);
$userRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if ($stmt) sqlsrv_free_stmt($stmt);

// Existing GDPR requests
$stmt = db_call_procedure('dbo.spGetUserGDPRRequests', [$currentUserId]);
$requests = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

$hasPending = false;
foreach ($requests as $r) {
    if (isset($r['Status']) && $r['Status'] === 'pending') {
        $hasPending = true;
        break;
    }
}

$errors = [];
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);
    $action = (string)array_get($_POST, 'action', '');

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($action === 'privacy_prefs') {
            // Privacy Preferences (database-backed)
            $prefLocation = isset($_POST['pref_location']) ? 1 : 0;
            $prefNotifications = isset($_POST['pref_notifications']) ? 1 : 0;
            $prefEmail = isset($_POST['pref_email']) ? 1 : 0;
            $prefDataSharing = isset($_POST['pref_data_sharing']) ? 1 : 0;
            
            $stmt = db_call_procedure('dbo.spUpdateUserProfile', [
                $currentUserId,
                $userRow['FullName'],
                $userRow['Phone'],
                null, null, null, null,
                $prefLocation,
                $prefNotifications,
                $prefEmail,
                $prefDataSharing
            ]);
            $result = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
            $ok = $result && !empty($result['Success']);
            if ($stmt) sqlsrv_free_stmt($stmt);
            
            if ($ok) {
                flash_add('success', 'Privacy preferences updated.');
                // Reload user data
                $stmt = db_call_procedure('dbo.spGetUserProfile', [$currentUserId]);
                $userRow = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                if ($stmt) sqlsrv_free_stmt($stmt);
            } else {
                $errors['general'] = 'Could not save privacy preferences. Please try again.';
            }
        } elseif ($action === 'gdpr_request') {
            $reason = trim((string)array_get($_POST, 'reason', ''));

            if ($hasPending) {
                $errors['general'] = 'You already have a pending deletion request.';
            }
            if ($reason === '') {
                $errors['reason'] = 'Please provide a reason for your request.';
            }

            if (!$errors) {
                $stmt = db_call_procedure('dbo.spCreateGdprRequest', [
                    $currentUserId,
                    $reason,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not submit request. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Your data deletion request has been submitted.');
                    redirect('passenger/gdpr_request.php');
                }
            }
        } elseif ($action === 'cancel_gdpr_request') {
            // Cancel pending GDPR request
            if ($hasPending) {
                $stmt = db_call_procedure('dbo.spCancelGdprRequest', [$currentUserId]);
                if ($stmt !== false) {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Your deletion request has been cancelled.');
                    redirect('passenger/gdpr_request.php');
                } else {
                    $errors['general'] = 'Could not cancel request. Please try again.';
                }
            }
        }
    }
}

function osrh_format_dt_gdpr($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('M j, Y');
    }
    return (string)$value;
}

$pageTitle = 'Privacy';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.privacy-container {
    max-width: 800px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.privacy-header {
    text-align: center;
    margin-bottom: 2rem;
}

.privacy-header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
}

.privacy-header p {
    color: var(--text-muted, #888);
    font-size: 0.9rem;
}

.privacy-grid {
    display: grid;
    gap: 1.5rem;
}

.privacy-section {
    background: var(--card-bg, #1a1a2e);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid rgba(255,255,255,0.06);
}

.privacy-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.privacy-section-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.privacy-section-icon.data { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.privacy-section-icon.notifications { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.privacy-section-icon.danger { background: linear-gradient(135deg, #ef4444, #b91c1c); }

.privacy-section-title {
    font-size: 1.1rem;
    font-weight: 600;
}

.privacy-section-subtitle {
    font-size: 0.8rem;
    color: var(--text-muted, #888);
}

.privacy-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}

.privacy-toggle:last-of-type {
    border-bottom: none;
}

.privacy-toggle-info {
    flex: 1;
}

.privacy-toggle-label {
    font-size: 0.9rem;
    font-weight: 500;
    margin-bottom: 0.2rem;
}

.privacy-toggle-hint {
    font-size: 0.75rem;
    color: var(--text-muted, #888);
}

/* Toggle Switch */
.toggle-switch {
    position: relative;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255,255,255,0.1);
    transition: 0.3s;
    border-radius: 26px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

.toggle-switch input:checked + .toggle-slider {
    background: linear-gradient(135deg, #8b5cf6, #6d28d9);
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(22px);
}

/* Request History */
.request-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.request-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}

.request-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.request-date {
    font-size: 0.85rem;
    font-weight: 500;
}

.request-reason {
    font-size: 0.75rem;
    color: var(--text-muted, #888);
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.request-status {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: capitalize;
}

.request-status.pending {
    background: rgba(234, 179, 8, 0.15);
    color: #eab308;
}

.request-status.completed {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.request-status.rejected {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.empty-state {
    text-align: center;
    padding: 1.5rem;
    color: var(--text-muted, #888);
    font-size: 0.85rem;
}

.delete-warning {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    font-size: 0.8rem;
    color: #fca5a5;
}

.delete-warning strong {
    color: #ef4444;
}

.privacy-actions {
    margin-top: 1rem;
    display: flex;
    gap: 0.75rem;
}
</style>

<div class="privacy-container">
    <div class="privacy-header">
        <h1>🔒 Privacy Center</h1>
        <p>Manage how OSRH collects and uses your personal data</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 1.5rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <div class="privacy-grid">
        <!-- Data Collection Preferences -->
        <div class="privacy-section">
            <div class="privacy-section-header">
                <div class="privacy-section-icon data">📍</div>
                <div>
                    <div class="privacy-section-title">Data Collection</div>
                    <div class="privacy-section-subtitle">Control what data we collect</div>
                </div>
            </div>
            
            <form method="post" id="privacyForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="privacy_prefs">
                
                <div class="privacy-toggle">
                    <div class="privacy-toggle-info">
                        <div class="privacy-toggle-label">Location Tracking</div>
                        <div class="privacy-toggle-hint">Enable automatic pickup location detection</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="pref_location" value="1"
                            <?php echo !empty($userRow['PrefLocationTracking']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="privacy-toggle">
                    <div class="privacy-toggle-info">
                        <div class="privacy-toggle-label">Analytics & Improvements</div>
                        <div class="privacy-toggle-hint">Help us improve services through anonymous usage data</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="pref_data_sharing" value="1"
                            <?php echo !empty($userRow['PrefDataSharing']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="privacy-actions">
                    <button type="submit" class="btn btn-primary btn-small">Save Preferences</button>
                </div>
            </form>
        </div>

        <!-- Communication Preferences -->
        <div class="privacy-section">
            <div class="privacy-section-header">
                <div class="privacy-section-icon notifications">🔔</div>
                <div>
                    <div class="privacy-section-title">Communications</div>
                    <div class="privacy-section-subtitle">Manage how we contact you</div>
                </div>
            </div>
            
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="privacy_prefs">
                <!-- Hidden fields to preserve other settings -->
                <input type="hidden" name="pref_location" value="<?php echo !empty($userRow['PrefLocationTracking']) ? '1' : '0'; ?>">
                <input type="hidden" name="pref_data_sharing" value="<?php echo !empty($userRow['PrefDataSharing']) ? '1' : '0'; ?>">
                
                <div class="privacy-toggle">
                    <div class="privacy-toggle-info">
                        <div class="privacy-toggle-label">Push Notifications</div>
                        <div class="privacy-toggle-hint">Receive ride updates and driver arrival alerts</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="pref_notifications" value="1"
                            <?php echo !empty($userRow['PrefNotifications']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="privacy-toggle">
                    <div class="privacy-toggle-info">
                        <div class="privacy-toggle-label">Email Updates</div>
                        <div class="privacy-toggle-hint">Receive receipts and service announcements</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="pref_email" value="1"
                            <?php echo !empty($userRow['PrefEmailUpdates']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="privacy-actions">
                    <button type="submit" class="btn btn-primary btn-small">Save Preferences</button>
                </div>
            </form>
        </div>

        <!-- Data Deletion Requests -->
        <div class="privacy-section">
            <div class="privacy-section-header">
                <div class="privacy-section-icon danger">🗑️</div>
                <div>
                    <div class="privacy-section-title">Data Deletion</div>
                    <div class="privacy-section-subtitle">Request removal of your personal data</div>
                </div>
            </div>

            <?php if (!empty($requests)): ?>
                <h4 style="font-size: 0.85rem; margin-bottom: 0.75rem; color: var(--text-muted, #888);">Previous Requests</h4>
                <div class="request-list">
                    <?php foreach ($requests as $req): ?>
                        <div class="request-item">
                            <div class="request-info">
                                <span class="request-date"><?php echo e(osrh_format_dt_gdpr($req['RequestedAt'] ?? '')); ?></span>
                                <span class="request-reason"><?php echo e($req['Reason'] ?? ''); ?></span>
                            </div>
                            <span class="request-status <?php echo e(strtolower($req['Status'] ?? '')); ?>">
                                <?php echo e($req['Status'] ?? 'Unknown'); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <hr style="border-color: rgba(255,255,255,0.06); margin: 1.25rem 0;">
            <?php endif; ?>

            <?php if ($hasPending): ?>
                <div class="empty-state">
                    <p>⏳ You have a pending deletion request.</p>
                    <p style="margin-top: 0.5rem;">We'll notify you once it's processed.</p>
                    <form method="post" style="margin-top: 1rem;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel_gdpr_request">
                        <button type="submit" class="btn btn-outline btn-small">Cancel Request</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="delete-warning">
                    <strong>⚠️ Warning:</strong> Requesting data deletion will permanently remove your personal information from OSRH systems. 
                    Payment records may be retained as required by law.
                </div>

                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="gdpr_request">

                    <div class="form-group">
                        <label class="form-label" for="reason">Reason for request</label>
                        <textarea
                            id="reason"
                            name="reason"
                            class="form-control<?php echo !empty($errors['reason']) ? ' input-error' : ''; ?>"
                            rows="2"
                            placeholder="Briefly explain why you want your data deleted..."
                            style="font-size: 0.85rem;"
                        ><?php echo e($reason); ?></textarea>
                        <?php if (!empty($errors['reason'])): ?>
                            <div class="form-error"><?php echo e($errors['reason']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="privacy-actions">
                        <button type="submit" class="btn btn-outline btn-small" style="border-color: #ef4444; color: #ef4444;">
                            Request Data Deletion
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
