<?php
/**
 * Kaspa Transaction Verification API
 * 
 * Verifies Kaspa transactions on the blockchain and updates payment status.
 * 
 * POST /api/kaspa_verify_transaction.php
 * 
 * Parameters:
 *   - transaction_hash: The Kaspa transaction hash to verify
 *   - payment_id: Optional payment ID to link transaction to
 *   - trip_id: Optional trip ID for context
 *   - expected_address: Expected recipient address
 *   - expected_amount_kas: Expected amount in KAS (with tolerance)
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/kaspa_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';

// Require login
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = current_user();
$userId = (int)$user['id'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get parameters
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$transactionHash = trim((string)($input['transaction_hash'] ?? ''));
$paymentId = isset($input['payment_id']) ? (int)$input['payment_id'] : null;
$tripId = isset($input['trip_id']) ? (int)$input['trip_id'] : null;
$expectedAddress = trim((string)($input['expected_address'] ?? ''));
$expectedAmountKas = isset($input['expected_amount_kas']) ? (float)$input['expected_amount_kas'] : null;

// Validate transaction hash
if (empty($transactionHash)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Transaction hash is required']);
    exit;
}

// Basic hash format validation (Kaspa tx hashes are 64 hex chars)
if (!preg_match('/^[a-f0-9]{64}$/i', $transactionHash)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction hash format']);
    exit;
}

try {
    // Query Kaspa API for transaction details
    $txDetails = fetchKaspaTransaction($transactionHash);
    
    if (!$txDetails) {
        // Transaction not found YET - this is normal for newly sent transactions
        echo json_encode([
            'success' => true,
            'verified' => false,
            'status' => 'not_found',
            'message' => 'Transaction not yet indexed on blockchain. This is normal for new transactions.',
            'transaction' => [
                'hash' => $transactionHash
            ]
        ]);
        exit;
    }
    
    // Extract transaction data
    $isAccepted = $txDetails['is_accepted'] ?? false;
    $blockTime = $txDetails['block_time'] ?? null;
    $outputs = $txDetails['outputs'] ?? [];
    
    // Check if transaction is confirmed
    if (!$isAccepted) {
        echo json_encode([
            'success' => true,
            'verified' => false,
            'status' => 'pending',
            'message' => 'Transaction is pending confirmation',
            'transaction' => [
                'hash' => $transactionHash,
                'is_accepted' => false
            ]
        ]);
        exit;
    }
    
    // Verify recipient address and amount if provided
    $addressVerified = false;
    $amountVerified = false;
    $receivedAmountKas = 0;
    
    // Check if transaction sent to the expected address
    if (!empty($expectedAddress)) {
        foreach ($outputs as $output) {
            $scriptPubKeyAddress = $output['script_public_key_address'] ?? '';
            if (strcasecmp($scriptPubKeyAddress, $expectedAddress) === 0) {
                $addressVerified = true;
                $receivedAmountKas = ($output['amount'] ?? 0) / 100000000; // Convert sompi to KAS
                break;
            }
        }
    } else {
        // No expected address, just verify transaction exists
        $addressVerified = true;
        // Get first output amount
        if (!empty($outputs)) {
            $receivedAmountKas = ($outputs[0]['amount'] ?? 0) / 100000000;
        }
    }
    
    // Check amount (if expected amount provided)
    if ($expectedAmountKas !== null && $expectedAmountKas > 0) {
        // Allow 5% tolerance for exchange rate fluctuations
        $tolerance = $expectedAmountKas * 0.05;
        $amountVerified = ($receivedAmountKas >= ($expectedAmountKas - $tolerance));
        // Also allow any amount >= expected (overpayment is fine)
    } else {
        // No expected amount provided — REJECT (amount is required for real payment verification)
        $amountVerified = false;
    }
    
    // Transaction is verified ONLY if BOTH address AND amount match
    $fullyVerified = $addressVerified && $amountVerified;
    $amountOk = $amountVerified;
    
    // Record transaction in database ONLY if fully verified (address AND amount match)
    if ($fullyVerified && ($paymentId || $tripId)) {
        $toUserResult = null;
        if (!empty($expectedAddress)) {
            // Find user by wallet address
            $toUserResult = db_exec_procedure('dbo.spKaspaGetWalletOwner', [$expectedAddress]);
        }
        $toUserId = $toUserResult['UserID'] ?? null;
        
        // Calculate EUR amount based on exchange rate
        $amountEur = 0;
        $stmt = db_call_procedure('dbo.spKaspaGetExchangeRate', []);
        if ($stmt !== false) {
            $rateRecord = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($rateRecord && isset($rateRecord['RateKAStoEUR'])) {
                $amountEur = $receivedAmountKas * (float)$rateRecord['RateKAStoEUR'];
            }
        }
        
        // Record the transaction using the stored procedure directly
        $recordStmt = db_call_procedure('dbo.spKaspaVerifyTransaction', [
            $transactionHash,
            $userId,           // FromUserID
            $toUserId,         // ToUserID
            $expectedAddress,  // ToWalletAddress
            $receivedAmountKas,
            $amountEur,
            null,              // ExchangeRate (will be fetched in SP)
            'payment',         // TransactionType
            $tripId,           // TripID
            null,              // SegmentID
            $paymentId         // PaymentID
        ]);
        
        if ($recordStmt !== false) {
            sqlsrv_free_stmt($recordStmt);
        }
    }
    
    // Build status message
    $statusMessage = '';
    if (!$addressVerified) {
        $statusMessage = 'Transaction does not include payment to the correct wallet address.';
    } elseif (!$amountOk) {
        $statusMessage = 'Insufficient payment: received ' . number_format($receivedAmountKas, 4) . ' KAS but expected at least ' . number_format($expectedAmountKas * 0.95, 4) . ' KAS (minimum 95% of ' . number_format($expectedAmountKas, 4) . ' KAS). Please send the correct amount.';
    } else {
        $statusMessage = 'Transaction verified successfully! Received: ' . number_format($receivedAmountKas, 4) . ' KAS';
    }
    
    // Return verification result
    echo json_encode([
        'success' => true,
        'verified' => $fullyVerified,  // True ONLY if BOTH address AND amount are verified
        'status' => $fullyVerified ? 'confirmed' : 'mismatch',
        'message' => $statusMessage,
        'transaction' => [
            'hash' => $transactionHash,
            'is_accepted' => $isAccepted,
            'block_time' => $blockTime,
            'amount_kas' => $receivedAmountKas,
            'expected_amount_kas' => $expectedAmountKas,
            'address_verified' => $addressVerified,
            'amount_verified' => $amountOk,
            'amount_difference' => $expectedAmountKas ? ($receivedAmountKas - $expectedAmountKas) : null
        ],
        'explorer_url' => kaspa_transaction_explorer_url($transactionHash)
    ]);
    
} catch (Exception $e) {
    error_log("Kaspa verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to verify transaction: ' . $e->getMessage()
    ]);
}

/**
 * Fetch transaction details from Kaspa API
 */
function fetchKaspaTransaction(string $txHash): ?array
{
    // Try multiple API endpoints
    $endpoints = [
        'https://api.kaspa.org/transactions/' . $txHash,
        'https://kaspa.chainexplorer.io/api/v1/tx/' . $txHash,
    ];
    
    foreach ($endpoints as $apiUrl) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Disable SSL verification for testing
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Log for debugging
        error_log("Kaspa API: $apiUrl => HTTP $httpCode" . ($curlError ? " Error: $curlError" : ""));
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                error_log("Kaspa API success: Found transaction");
                return $data;
            }
        }
        
        // If 404, transaction not found on this endpoint
        if ($httpCode === 404) {
            continue; // Try next endpoint
        }
    }
    
    // None of the endpoints worked
    return null;
}
