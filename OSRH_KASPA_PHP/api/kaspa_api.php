<?php
/**
 * Kaspa Payment API Endpoints
 * 
 * Provides REST API for:
 * - Exchange rate queries
 * - Payment request creation
 * - Payment status checking
 * - Wallet address validation
 * - Transaction recording
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Handle CORS for API requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$endpoint = end($pathParts);

// Get action from query parameter or path
$action = $_GET['action'] ?? $endpoint;

try {
    switch ($action) {
        // ============================================================
        // EXCHANGE RATE
        // ============================================================
        case 'exchange-rate':
            if ($method === 'GET') {
                // Get exchange rate from database or fetch fresh
                $rateRecord = null;
                $stmt = db_call_procedure('dbo.spKaspaGetExchangeRate', []);
                if ($stmt !== false) {
                    $rateRecord = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($stmt);
                }
                
                // Check if rate is valid (not expired)
                $rateValid = false;
                if ($rateRecord && isset($rateRecord['RateKAStoEUR']) && isset($rateRecord['ValidUntil'])) {
                    $validUntil = $rateRecord['ValidUntil'];
                    if ($validUntil instanceof DateTime) {
                        $rateValid = $validUntil > new DateTime();
                    }
                }
                
                if ($rateValid) {
                    // Use cached rate
                    echo json_encode([
                        'success' => true,
                        'rateKAStoEUR' => (float)$rateRecord['RateKAStoEUR'],
                        'rateEURtoKAS' => (float)$rateRecord['RateEURtoKAS'],
                        'source' => $rateRecord['Source'] ?? 'database',
                        'fetchedAt' => isset($rateRecord['FetchedAt']) ? format_datetime($rateRecord['FetchedAt']) : null,
                        'validUntil' => isset($rateRecord['ValidUntil']) ? format_datetime($rateRecord['ValidUntil']) : null
                    ]);
                } else {
                    // Try to fetch fresh rate from CoinGecko
                    $freshRate = kaspa_fetch_exchange_rate();
                    
                    if ($freshRate) {
                        echo json_encode([
                            'success' => true,
                            'rateKAStoEUR' => $freshRate,
                            'rateEURtoKAS' => 1.0 / $freshRate,
                            'source' => 'coingecko'
                        ]);
                    } else {
                        // Use fallback - either expired DB rate or hardcoded
                        $fallbackRate = ($rateRecord && isset($rateRecord['RateKAStoEUR'])) 
                            ? (float)$rateRecord['RateKAStoEUR']
                            : (defined('KASPA_FALLBACK_RATE_EUR_TO_KAS') ? (1.0 / KASPA_FALLBACK_RATE_EUR_TO_KAS) : 0.10);
                        echo json_encode([
                            'success' => true,
                            'rateKAStoEUR' => $fallbackRate,
                            'rateEURtoKAS' => 1.0 / $fallbackRate,
                            'source' => 'fallback'
                        ]);
                    }
                }
            } elseif ($method === 'POST') {
                // Admin only - manual rate update
                require_login();
                if (!is_operator()) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                    exit;
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                $rateKAStoEUR = (float)($input['rateKAStoEUR'] ?? 0);
                
                if ($rateKAStoEUR <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Invalid rate']);
                    exit;
                }
                
                $stmt = db_call_procedure('dbo.spKaspaUpdateExchangeRate', [
                    $rateKAStoEUR,
                    'manual',
                    60 // Valid for 60 minutes
                ]);
                
                echo json_encode(['success' => $stmt !== false]);
            }
            break;

        // ============================================================
        // CONVERT CURRENCY
        // ============================================================
        case 'convert':
            if ($method !== 'GET' && $method !== 'POST') {
                http_response_code(405);
                exit;
            }
            
            $input = $method === 'POST' 
                ? json_decode(file_get_contents('php://input'), true) 
                : $_GET;
            
            $amountEur = isset($input['eur']) ? (float)$input['eur'] : null;
            $amountKas = isset($input['kas']) ? (float)$input['kas'] : null;
            
            if ($amountEur !== null) {
                $result = kaspa_convert_eur_to_kas($amountEur);
                echo json_encode([
                    'success' => true,
                    'from' => 'EUR',
                    'to' => 'KAS',
                    ...$result
                ]);
            } elseif ($amountKas !== null) {
                $result = kaspa_convert_kas_to_eur($amountKas);
                echo json_encode([
                    'success' => true,
                    'from' => 'KAS',
                    'to' => 'EUR',
                    ...$result
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Provide eur or kas amount']);
            }
            break;

        // ============================================================
        // VALIDATE ADDRESS
        // ============================================================
        case 'validate-address':
            if ($method !== 'POST') {
                http_response_code(405);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $address = trim($input['address'] ?? '');
            
            if (empty($address)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Address is required']);
                exit;
            }
            
            $result = kaspa_validate_address($address);
            echo json_encode([
                'success' => true,
                ...$result
            ]);
            break;

        // ============================================================
        // CREATE PAYMENT REQUEST
        // ============================================================
        case 'create-payment':
            if ($method !== 'POST') {
                http_response_code(405);
                exit;
            }
            
            require_login();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $toAddress = trim($input['toAddress'] ?? '');
            $amountEur = isset($input['amountEur']) ? (float)$input['amountEur'] : null;
            $amountKas = isset($input['amountKas']) ? (float)$input['amountKas'] : null;
            $tripId = isset($input['tripId']) ? (int)$input['tripId'] : null;
            $segmentId = isset($input['segmentId']) ? (int)$input['segmentId'] : null;
            $autonomousRideId = isset($input['autonomousRideId']) ? (int)$input['autonomousRideId'] : null;
            $rentalId = isset($input['rentalId']) ? (int)$input['rentalId'] : null;
            $description = $input['description'] ?? null;
            
            // Validate address
            if (empty($toAddress)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Recipient address is required']);
                exit;
            }
            
            $validation = kaspa_validate_address($toAddress);
            if (!$validation['valid']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $validation['error']]);
                exit;
            }
            
            // Convert EUR to KAS if needed
            if ($amountKas === null && $amountEur !== null) {
                $conversion = kaspa_convert_eur_to_kas($amountEur);
                $amountKas = $conversion['amountKAS'];
            }
            
            if ($amountKas === null || $amountKas <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid amount is required']);
                exit;
            }
            
            // Create payment request
            $result = kaspa_create_payment_request(
                $toAddress,
                $amountKas,
                $amountEur,
                null,
                $tripId,
                $segmentId,
                $autonomousRideId,
                $rentalId,
                $description
            );
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'requestCode' => $result['RequestCode'],
                    'toAddress' => $toAddress,
                    'amountKAS' => $amountKas,
                    'amountEUR' => $amountEur,
                    'kaspaUri' => $result['KaspaUri'],
                    'expiresAt' => format_datetime($result['ExpiresAt'])
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to create payment request']);
            }
            break;

        // ============================================================
        // GET PAYMENT STATUS
        // ============================================================
        case 'payment-status':
            if ($method !== 'GET') {
                http_response_code(405);
                exit;
            }
            
            $requestCode = $_GET['code'] ?? $pathParts[count($pathParts) - 1] ?? '';
            
            if (empty($requestCode) || $requestCode === 'payment-status') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Request code is required']);
                exit;
            }
            
            $stmt = db_query(
                'SELECT * FROM dbo.KaspaPaymentRequest WHERE RequestCode = ?',
                [$requestCode]
            );
            
            if ($stmt === false) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Database error']);
                exit;
            }
            
            $request = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            
            if (!$request) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Payment request not found']);
                exit;
            }
            
            // Check if expired
            $expiresAt = $request['ExpiresAt'] instanceof DateTime 
                ? $request['ExpiresAt'] 
                : new DateTime($request['ExpiresAt']);
            
            if ($expiresAt < new DateTime() && $request['Status'] === 'pending') {
                // Update status to expired
                db_query(
                    'UPDATE dbo.KaspaPaymentRequest SET Status = ? WHERE RequestID = ?',
                    ['expired', $request['RequestID']]
                );
                $request['Status'] = 'expired';
            }
            
            echo json_encode([
                'success' => true,
                'requestCode' => $request['RequestCode'],
                'status' => $request['Status'],
                'amountKAS' => (float)$request['AmountKAS'],
                'amountEUR' => $request['AmountEUR'] ? (float)$request['AmountEUR'] : null,
                'toAddress' => $request['ToWalletAddress'],
                'createdAt' => format_datetime($request['CreatedAt']),
                'expiresAt' => format_datetime($expiresAt),
                'completedAt' => $request['CompletedAt'] ? format_datetime($request['CompletedAt']) : null,
                'transactionId' => $request['KaspaTransactionID']
            ]);
            break;

        // ============================================================
        // RECORD TRANSACTION
        // ============================================================
        case 'record-transaction':
            if ($method !== 'POST') {
                http_response_code(405);
                exit;
            }
            
            require_login();
            $user = current_user();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $transactionHash = trim($input['transactionHash'] ?? '');
            $toAddress = trim($input['toAddress'] ?? '');
            $amountKas = (float)($input['amountKAS'] ?? 0);
            $requestCode = $input['requestCode'] ?? null;
            
            if (empty($transactionHash) || empty($toAddress) || $amountKas <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit;
            }
            
            // Get conversion
            $conversion = kaspa_convert_kas_to_eur($amountKas);
            
            // Determine recipient user ID
            $toUserId = null;
            $stmtWallet = db_query(
                'SELECT UserID FROM dbo.KaspaWallet WHERE WalletAddress = ? AND IsActive = 1',
                [$toAddress]
            );
            if ($stmtWallet) {
                $wallet = sqlsrv_fetch_array($stmtWallet, SQLSRV_FETCH_ASSOC);
                if ($wallet) {
                    $toUserId = (int)$wallet['UserID'];
                }
                sqlsrv_free_stmt($stmtWallet);
            }
            
            // Get context from payment request if provided
            $tripId = null;
            $segmentId = null;
            $autonomousRideId = null;
            $rentalId = null;
            
            if ($requestCode) {
                $stmtReq = db_query(
                    'SELECT TripID, SegmentID, AutonomousRideID, RentalID FROM dbo.KaspaPaymentRequest WHERE RequestCode = ?',
                    [$requestCode]
                );
                if ($stmtReq) {
                    $req = sqlsrv_fetch_array($stmtReq, SQLSRV_FETCH_ASSOC);
                    if ($req) {
                        $tripId = $req['TripID'];
                        $segmentId = $req['SegmentID'];
                        $autonomousRideId = $req['AutonomousRideID'];
                        $rentalId = $req['RentalID'];
                    }
                    sqlsrv_free_stmt($stmtReq);
                }
            }
            
            // Record transaction
            $transactionId = kaspa_record_transaction(
                $toAddress,
                $amountKas,
                $input['fromAddress'] ?? null,
                $user['UserID'],
                $toUserId,
                $conversion['amountEUR'],
                $conversion['exchangeRate'],
                'payment',
                null,
                $tripId,
                $segmentId,
                $autonomousRideId,
                $rentalId,
                $transactionHash,
                'broadcasting'
            );
            
            // Update payment request if provided
            if ($requestCode && $transactionId) {
                db_query(
                    'UPDATE dbo.KaspaPaymentRequest SET Status = ?, KaspaTransactionID = ? WHERE RequestCode = ?',
                    ['confirming', $transactionId, $requestCode]
                );
            }
            
            echo json_encode([
                'success' => $transactionId !== null,
                'transactionId' => $transactionId,
                'transactionHash' => $transactionHash
            ]);
            break;

        // ============================================================
        // USER WALLETS
        // ============================================================
        case 'wallets':
            require_login();
            $user = current_user();
            
            if ($method === 'GET') {
                $wallets = kaspa_get_user_wallets($user['UserID']);
                echo json_encode([
                    'success' => true,
                    'wallets' => $wallets
                ]);
            } elseif ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                
                $address = trim($input['address'] ?? '');
                $label = $input['label'] ?? null;
                $isDefault = (bool)($input['isDefault'] ?? false);
                $walletType = $input['walletType'] ?? 'receive';
                
                $result = kaspa_add_wallet($user['UserID'], $address, $walletType, $label, $isDefault);
                
                if ($result['success']) {
                    echo json_encode($result);
                } else {
                    http_response_code(400);
                    echo json_encode($result);
                }
            }
            break;

        // ============================================================
        // USER TRANSACTIONS
        // ============================================================
        case 'transactions':
            require_login();
            $user = current_user();
            
            $maxRows = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            $transactions = kaspa_get_user_transactions($user['UserID'], $maxRows);
            
            echo json_encode([
                'success' => true,
                'transactions' => array_map(function($tx) {
                    return [
                        'id' => $tx['KaspaTransactionID'],
                        'hash' => $tx['TransactionHash'],
                        'direction' => $tx['Direction'],
                        'amountKAS' => (float)$tx['AmountKAS'],
                        'amountEUR' => $tx['AmountEUR'] ? (float)$tx['AmountEUR'] : null,
                        'status' => $tx['Status'],
                        'confirmations' => (int)$tx['Confirmations'],
                        'createdAt' => format_datetime($tx['CreatedAt']),
                        'confirmedAt' => $tx['ConfirmedAt'] ? format_datetime($tx['ConfirmedAt']) : null,
                        'tripId' => $tx['TripID'],
                        'explorerUrl' => $tx['TransactionHash'] ? kaspa_explorer_url($tx['TransactionHash']) : null
                    ];
                }, $transactions)
            ]);
            break;

        // ============================================================
        // DRIVER CREATE TRIP PAYMENT
        // ============================================================
        case 'trip-payment':
            if ($method !== 'POST') {
                http_response_code(405);
                exit;
            }
            
            require_login();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $tripId = (int)($input['tripId'] ?? 0);
            $driverId = (int)($input['driverId'] ?? 0);
            $amountEur = (float)($input['amountEur'] ?? 0);
            
            if ($tripId <= 0 || $driverId <= 0 || $amountEur <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            $result = kaspa_create_trip_payment_request($tripId, $driverId, $amountEur);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    ...$result
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to create payment request. Driver may not have a Kaspa wallet configured.'
                ]);
            }
            break;

        // ============================================================
        // DEFAULT
        // ============================================================
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Unknown endpoint',
                'endpoint' => $action
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => KASPA_DEBUG ? $e->getMessage() : 'Internal server error'
    ]);
    kaspa_log('error', 'API exception: ' . $e->getMessage());
}

/**
 * Format datetime for JSON output
 */
function format_datetime($datetime): ?string
{
    if ($datetime === null) {
        return null;
    }
    
    if ($datetime instanceof DateTime) {
        return $datetime->format('c');
    }
    
    return (new DateTime($datetime))->format('c');
}
