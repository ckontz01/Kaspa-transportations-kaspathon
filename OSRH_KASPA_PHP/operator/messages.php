<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';

require_login();
require_role('operator');

$currentUserId = current_user_id();
if ($currentUserId <= 0) {
    redirect('error.php?code=403');
}

$selectedUserId = (int)array_get($_GET, 'user_id', 0);
$errors = [];

// Handle sending a message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = array_get($_POST, 'csrf_token', null);
    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        $targetUserId = (int)array_get($_POST, 'user_id', 0);
        $body = trim((string)array_get($_POST, 'body', ''));

        if ($targetUserId <= 0) {
            $errors['user_id'] = 'Target user is required.';
        }
        if ($body === '') {
            $errors['body'] = 'Message cannot be empty.';
        } elseif (mb_strlen($body) > 1000) {
            $body = mb_substr($body, 0, 1000);
        }

        if (!$errors) {
            $stmt = db_call_procedure('spSendMessage', [
                $currentUserId,
                $targetUserId,
                null,
                $body,
            ]);
            if ($stmt === false) {
                $errors['general'] = 'Could not send message.';
            } else {
                sqlsrv_free_stmt($stmt);
                flash_add('success', 'Message sent.');
                redirect('operator/messages.php?user_id=' . urlencode((string)$targetUserId));
            }
        }
    }
}

// Get all conversations for the operator (sorted by unread first)
$conversations = [];
$stmt = db_call_procedure('dbo.spGetOperatorConversations', [$currentUserId]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $conversations[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Load selected user info and conversation
$selectedUser = null;
$messages = [];
if ($selectedUserId > 0) {
    // Mark messages as read
    $stmt = db_call_procedure('dbo.spMarkMessagesAsRead', [$currentUserId, $selectedUserId]);
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Get user info
    $stmt = db_call_procedure('dbo.spGetUserProfile', [$selectedUserId]);
    $selectedUser = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Get conversation
    if ($selectedUser) {
        $stmt = db_call_procedure('spGetConversation', [$currentUserId, $selectedUserId]);
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $messages[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

function format_msg_time($v): string {
    if ($v instanceof DateTimeInterface) {
        $now = new DateTime();
        $diff = $now->diff($v);
        
        if ($diff->days === 0) {
            return $v->format('H:i');
        } elseif ($diff->days === 1) {
            return 'Yesterday ' . $v->format('H:i');
        } elseif ($diff->days < 7) {
            return $v->format('l H:i');
        } else {
            return $v->format('M j, H:i');
        }
    }
    return $v ? (string)$v : '';
}

function format_last_msg_preview($content, $maxLen = 40): string {
    $content = strip_tags((string)$content);
    if (mb_strlen($content) > $maxLen) {
        return mb_substr($content, 0, $maxLen) . '...';
    }
    return $content;
}

$pageTitle = 'Messages';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.messages-container {
    max-width: 1100px;
    margin: 2rem auto;
    padding: 0 1rem;
}

.messages-header {
    margin-bottom: 1.5rem;
}

.messages-header h1 {
    font-size: 1.75rem;
    margin: 0 0 0.5rem 0;
}

.messages-header p {
    color: var(--text-muted, #6b7280);
    font-size: 0.9rem;
    margin: 0;
}

.messages-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 1.5rem;
    height: calc(100vh - 220px);
    min-height: 500px;
}

/* Conversations List */
.conversations-panel {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.conversations-header {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.conversations-header h2 {
    font-size: 1rem;
    margin: 0;
}

.conversations-list {
    flex: 1;
    overflow-y: auto;
}

.conversation-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    cursor: pointer;
    transition: background 0.15s ease;
    text-decoration: none;
    color: inherit;
}

.conversation-item:hover {
    background: rgba(59, 130, 246, 0.08);
}

.conversation-item.active {
    background: rgba(59, 130, 246, 0.15);
    border-left: 3px solid #3b82f6;
}

.conversation-item.unread {
    background: rgba(59, 130, 246, 0.05);
}

.conversation-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    color: white;
    flex-shrink: 0;
}

.conversation-avatar.driver {
    background: linear-gradient(135deg, #22c55e, #16a34a);
}

.conversation-avatar.passenger {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.conversation-info {
    flex: 1;
    min-width: 0;
}

.conversation-name {
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.conversation-role {
    font-size: 0.7rem;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    background: rgba(148, 163, 184, 0.2);
    color: var(--text-muted, #6b7280);
}

.conversation-preview {
    font-size: 0.8rem;
    color: var(--text-muted, #6b7280);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 0.2rem;
}

.conversation-item.unread .conversation-preview {
    color: #1e40af;
    font-weight: 500;
}

.conversation-meta {
    text-align: right;
    flex-shrink: 0;
}

.conversation-time {
    font-size: 0.7rem;
    color: var(--text-muted, #6b7280);
}

.unread-badge {
    display: inline-block;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    background: #3b82f6;
    color: white;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    text-align: center;
    line-height: 20px;
    margin-top: 0.25rem;
}

.no-conversations {
    padding: 2rem 1rem;
    text-align: center;
    color: var(--text-muted, #6b7280);
    font-size: 0.9rem;
}

/* Chat Panel */
.chat-panel {
    background: var(--color-surface, #f8fafc);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.chat-header-info h3 {
    margin: 0;
    font-size: 1rem;
}

.chat-header-info p {
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    color: var(--text-muted, #6b7280);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.chat-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text-muted, #6b7280);
    text-align: center;
    padding: 2rem;
}

.chat-empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.message-bubble {
    max-width: 70%;
    padding: 0.75rem 1rem;
    border-radius: 16px;
    font-size: 0.875rem;
    line-height: 1.4;
}

.message-bubble.outgoing {
    align-self: flex-end;
    background: #3b82f6;
    color: white;
    border-bottom-right-radius: 4px;
}

.message-bubble.incoming {
    align-self: flex-start;
    background: rgba(148, 163, 184, 0.15);
    border-bottom-left-radius: 4px;
}

.message-time {
    font-size: 0.7rem;
    margin-top: 0.35rem;
    opacity: 0.7;
}

.message-bubble.outgoing .message-time {
    text-align: right;
}

/* Chat Input */
.chat-input {
    padding: 1rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
}

.chat-input form {
    display: flex;
    gap: 0.75rem;
}

.chat-input textarea {
    flex: 1;
    resize: none;
    border-radius: 20px;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    min-height: 44px;
    max-height: 120px;
}

.chat-input .btn {
    align-self: flex-end;
    border-radius: 20px;
    padding: 0.6rem 1.25rem;
}

/* New Conversation Modal */
.new-conv-btn {
    padding: 0.4rem 0.75rem;
    font-size: 0.8rem;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .messages-layout {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .conversations-panel {
        max-height: 300px;
    }
    
    .chat-panel {
        min-height: 400px;
    }
}
</style>

<div class="messages-container">
    <div class="messages-header">
        <h1>💬 Messages</h1>
        <p>Communicate with passengers and drivers</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 1rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <div class="messages-layout">
        <!-- Conversations List -->
        <div class="conversations-panel">
            <div class="conversations-header">
                <h2>Conversations</h2>
            </div>
            <div class="conversations-list">
                <?php if (empty($conversations)): ?>
                    <div class="no-conversations">
                        <p>No messages yet.</p>
                        <p style="font-size: 0.8rem; margin-top: 0.5rem;">Passengers and drivers can contact you for support.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <?php 
                        $isActive = $selectedUserId === (int)$conv['UserID'];
                        $hasUnread = (int)($conv['UnreadCount'] ?? 0) > 0;
                        $initials = '';
                        $nameParts = explode(' ', $conv['FullName'] ?? '');
                        foreach ($nameParts as $part) {
                            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                        }
                        $initials = mb_substr($initials, 0, 2);
                        $roleClass = strtolower($conv['UserRole'] ?? '') === 'driver' ? 'driver' : 'passenger';
                        ?>
                        <a href="<?php echo e(url('operator/messages.php?user_id=' . $conv['UserID'])); ?>" 
                           class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $hasUnread ? 'unread' : ''; ?>">
                            <div class="conversation-avatar <?php echo e($roleClass); ?>">
                                <?php echo e($initials); ?>
                            </div>
                            <div class="conversation-info">
                                <div class="conversation-name">
                                    <?php echo e($conv['FullName']); ?>
                                    <span class="conversation-role"><?php echo e($conv['UserRole']); ?></span>
                                </div>
                                <div class="conversation-preview">
                                    <?php echo e(format_last_msg_preview($conv['LastMessage'] ?? '')); ?>
                                </div>
                            </div>
                            <div class="conversation-meta">
                                <div class="conversation-time"><?php echo e(format_msg_time($conv['LastMessageAt'] ?? null)); ?></div>
                                <?php if ($hasUnread): ?>
                                    <div class="unread-badge"><?php echo e($conv['UnreadCount']); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Panel -->
        <div class="chat-panel">
            <?php if ($selectedUser): ?>
                <div class="chat-header">
                    <?php 
                    $initials = '';
                    $nameParts = explode(' ', $selectedUser['FullName'] ?? '');
                    foreach ($nameParts as $part) {
                        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                    }
                    $initials = mb_substr($initials, 0, 2);
                    ?>
                    <div class="conversation-avatar">
                        <?php echo e($initials); ?>
                    </div>
                    <div class="chat-header-info">
                        <h3><?php echo e($selectedUser['FullName']); ?></h3>
                        <p><?php echo e($selectedUser['Email']); ?></p>
                    </div>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($messages)): ?>
                        <div class="chat-empty">
                            <div class="chat-empty-icon">💬</div>
                            <p>No messages yet.</p>
                            <p style="font-size: 0.8rem;">Send a message to start the conversation.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <?php $isMine = (int)$msg['FromUserID'] === $currentUserId; ?>
                            <div class="message-bubble <?php echo $isMine ? 'outgoing' : 'incoming'; ?>">
                                <div class="message-content"><?php echo nl2br(e($msg['Content'] ?? '')); ?></div>
                                <div class="message-time"><?php echo e(format_msg_time($msg['SentAt'] ?? null)); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="chat-input">
                    <form method="post" id="messageForm">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="user_id" value="<?php echo e($selectedUser['UserID']); ?>">
                        <textarea 
                            name="body" 
                            placeholder="Type a message..." 
                            class="form-control"
                            rows="1"
                            required
                            onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();this.form.submit();}"
                        ></textarea>
                        <button type="submit" class="btn btn-primary">Send</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="chat-empty" style="height: 100%;">
                    <div class="chat-empty-icon">👈</div>
                    <p>Select a conversation</p>
                    <p style="font-size: 0.8rem;">Choose a user from the list to view their messages.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Scroll to bottom of chat on load
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
