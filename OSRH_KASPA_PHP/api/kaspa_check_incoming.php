<?php
/**
 * Kaspa Incoming Transaction Checker API
 * 
 * Checks for recent incoming transactions to a specific Kaspa address.
 * Used for automatic payment detection after mobile wallet redirect.
 * 
 * GET /api/kaspa_check_incoming.php?address=kaspa:xxx&amount_kas=5.0&since=1700000000
 * 
 * Parameters:
 *   - address: The recipient Kaspa address to check
 *   - amount_kas: Expected amount in KAS (matches with 10% tolerance)
 *   - since: Unix timestamp - only check transactions after this time (optional)
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/config.php';
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

// Only GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$address = trim($_GET['address'] ?? '');
$expectedAmountKas = isset($_GET['amount_kas']) ? (float)$_GET['amount_kas'] : 0;
$sinceTimestamp = isset($_GET['since']) ? (int)$_GET['since'] : (time() - 300); // Default: last 5 minutes

// Validate address
if (empty($address)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Address is required']);
    exit;
}

// Normalize address — ensure it has kaspa: prefix for API
$cleanAddress = $address;
if (strpos($address, 'kaspa:') === 0) {
    $cleanAddress = $address;
} else {
    $cleanAddress = 'kaspa:' . $address;
}

try {
    // Query Kaspa API for recent transactions to this address
    // The Kaspa API endpoint: /addresses/{address}/full-transactions
    $apiUrl = 'https://api.kaspa.org/addresses/' . urlencode($cleanAddress) . '/full-transactions?limit=10&resolve_previous_outpoints=no';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: OSRH-Payment-Checker/1.0'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        error_log("Kaspa incoming check failed: HTTP $httpCode, Error: $curlError, URL: $apiUrl");
        echo json_encode([
            'success' => false,
            'error' => 'Could not query Kaspa network',
            'found' => false
        ]);
        exit;
    }
    
    $transactions = json_decode($response, true);
    
    if (!is_array($transactions)) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'message' => 'No transactions found'
        ]);
        exit;
    }
    
    // Search through recent transactions for a matching incoming payment
    $tolerance = $expectedAmountKas > 0 ? $expectedAmountKas * 0.05 : 0; // 5% tolerance
    
    foreach ($transactions as $tx) {
        // Check if transaction is accepted/confirmed
        $isAccepted = $tx['is_accepted'] ?? false;
        if (!$isAccepted) continue;
        
        // Check block time — must be after our 'since' timestamp
        // Kaspa block_time is in milliseconds
        $blockTime = isset($tx['block_time']) ? (int)($tx['block_time'] / 1000) : 0;
        if ($blockTime > 0 && $blockTime < $sinceTimestamp) continue;
        
        // Check outputs for payment to our target address
        $outputs = $tx['outputs'] ?? [];
        foreach ($outputs as $output) {
            $outputAddress = $output['script_public_key_address'] ?? '';
            
            // Match address (case-insensitive)
            if (strcasecmp($outputAddress, $cleanAddress) !== 0) continue;
            
            // Convert sompi to KAS
            $receivedSompi = (int)($output['amount'] ?? 0);
            $receivedKas = $receivedSompi / 100000000;
            
            // Check amount match (if expected amount specified)
            if ($expectedAmountKas > 0) {
                $diff = abs($receivedKas - $expectedAmountKas);
                if ($diff > $tolerance) continue; // Amount doesn't match
            }
            
            // Found a matching transaction!
            $txHash = $tx['transaction_id'] ?? ($tx['hash'] ?? ($tx['id'] ?? ''));
            
            echo json_encode([
                'success' => true,
                'found' => true,
                'transaction' => [
                    'hash' => $txHash,
                    'amount_kas' => $receivedKas,
                    'amount_sompi' => $receivedSompi,
                    'block_time' => $blockTime,
                    'is_accepted' => true,
                    'address_matched' => true,
                    'amount_matched' => ($expectedAmountKas > 0) ? ($diff <= $tolerance) : true
                ],
                'message' => 'Payment detected! Transaction: ' . substr($txHash, 0, 16) . '...'
            ]);
            exit;
        }
    }
    
    // No matching transaction found
    echo json_encode([
        'success' => true,
        'found' => false,
        'checked_count' => count($transactions),
        'message' => 'No matching incoming transaction found yet'
    ]);
    
} catch (Exception $e) {
    error_log("Kaspa incoming check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to check transactions: ' . $e->getMessage(),
        'found' => false
    ]);
}
