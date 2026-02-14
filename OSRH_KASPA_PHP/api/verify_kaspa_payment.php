<?php
/**
 * Kaspa Payment Verification API
 * 
 * Verifies and records Kaspa payments for:
 * - Autonomous rides (to OSRH platform wallet)
 * - Carshare bookings (to OSRH platform wallet)
 * - Regular trips (to driver wallet) - delegates to existing flow
 * 
 * POST /api/verify_kaspa_payment.php
 * 
 * Parameters:
 *   - payment_type: 'autonomous', 'carshare', or 'trip'
 *   - ride_id: For autonomous rides
 *   - booking_id: For carshare
 *   - trip_id: For regular trips
 *   - amount_kas: Amount in KAS
 *   - amount_eur: Amount in EUR
 *   - to_address: Recipient wallet address
 *   - tx_id: Optional transaction hash if available
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/kaspa_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';
require_once __DIR__ . '/../includes/payments.php';

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

$paymentType = trim((string)($input['payment_type'] ?? ''));
$rideId = isset($input['ride_id']) ? (int)$input['ride_id'] : null;
$bookingId = isset($input['booking_id']) ? (int)$input['booking_id'] : null;
$tripId = isset($input['trip_id']) ? (int)$input['trip_id'] : null;
$amountKas = isset($input['amount_kas']) ? (float)$input['amount_kas'] : 0;
$amountEur = isset($input['amount_eur']) ? (float)$input['amount_eur'] : 0;
$toAddress = trim((string)($input['to_address'] ?? ''));
$txId = trim((string)($input['tx_id'] ?? ''));

// Validate payment type
if (!in_array($paymentType, ['autonomous', 'carshare', 'trip', 'segment'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payment type']);
    exit;
}

// Validate IDs based on payment type
if ($paymentType === 'autonomous' && (!$rideId || $rideId <= 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ride ID is required for autonomous payments']);
    exit;
}

if ($paymentType === 'carshare' && (!$bookingId || $bookingId <= 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Booking ID is required for carshare payments']);
    exit;
}

// Validate amount
if ($amountKas <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid KAS amount']);
    exit;
}

// Validate recipient address
if (empty($toAddress)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Recipient address is required']);
    exit;
}

$addressValidation = kaspa_validate_address($toAddress);
if (!$addressValidation['valid']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Kaspa address']);
    exit;
}

try {
    // For autonomous rides, verify it belongs to the user
    if ($paymentType === 'autonomous') {
        $passenger = $user['passenger'] ?? null;
        if (!$passenger) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not authorized']);
            exit;
        }
        
        $passengerId = (int)$passenger['PassengerID'];
        
        // Get the ride details
        $stmtRide = db_call_procedure('dbo.spGetAutonomousRideDetails', [$rideId]);
        if ($stmtRide === false) {
            throw new Exception('Could not load ride details');
        }
        $ride = sqlsrv_fetch_array($stmtRide, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtRide);
        
        if (!$ride || (int)$ride['PassengerID'] !== $passengerId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ride not found or unauthorized']);
            exit;
        }
        
        // Verify the amount matches expected fare (5% tolerance)
        $expectedFare = $ride['ActualFare'] ? (float)$ride['ActualFare'] : (float)($ride['EstimatedFare'] ?? 0);
        
        if ($expectedFare > 0 && abs($amountEur - $expectedFare) / $expectedFare > 0.05) {
            echo json_encode([
                'success' => false,
                'verified' => false,
                'message' => 'Amount mismatch. Expected €' . number_format($expectedFare, 2)
            ]);
            exit;
        }
        
        // Verify address matches platform wallet
        $platformWallet = kaspa_get_platform_wallet();
        if (!$platformWallet || $platformWallet['WalletAddress'] !== $toAddress) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid payment recipient']);
            exit;
        }
        
        // If we have a transaction hash, verify it on the blockchain
        if (!empty($txId)) {
            $txDetails = kaspa_verify_transaction($txId, $toAddress, $amountKas);
            
            if ($txDetails && $txDetails['verified']) {
                // Transaction verified - mark payment as complete
                $result = recordAutonomousRidePayment($rideId, $amountEur, $amountKas, $txId);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'verified' => true,
                        'status' => 'completed',
                        'message' => 'Payment verified and recorded successfully',
                        'transaction' => [
                            'hash' => $txId,
                            'amount_kas' => $amountKas,
                            'amount_eur' => $amountEur
                        ]
                    ]);
                } else {
                    // Transaction verified but couldn't record - still success
                    echo json_encode([
                        'success' => true,
                        'verified' => true,
                        'status' => 'completed',
                        'message' => 'Payment verified (recording pending)',
                        'transaction' => ['hash' => $txId]
                    ]);
                }
                exit;
            } else {
                // Transaction not yet confirmed
                echo json_encode([
                    'success' => true,
                    'verified' => false,
                    'status' => 'pending',
                    'message' => 'Transaction submitted but not yet confirmed on blockchain'
                ]);
                exit;
            }
        } else {
            // No transaction hash — cannot verify without proof of payment
            echo json_encode([
                'success' => false,
                'verified' => false,
                'status' => 'no_transaction',
                'message' => 'Transaction hash is required. Please complete the Kaspa payment first.'
            ]);
            exit;
        }
    }
    
    // For carshare bookings
    if ($paymentType === 'carshare') {
        // TODO: Implement carshare payment verification
        // Similar logic to autonomous rides
        
        echo json_encode([
            'success' => true,
            'verified' => false,
            'status' => 'pending',
            'message' => 'Carshare payment verification pending implementation'
        ]);
        exit;
    }
    
    // For regular trips, delegate to existing verification
    if ($paymentType === 'trip' || $paymentType === 'segment') {
        // Use the existing trip payment verification flow
        echo json_encode([
            'success' => true,
            'verified' => false,
            'status' => 'pending',
            'message' => 'Use the trip-specific payment endpoint'
        ]);
        exit;
    }
    
} catch (Exception $e) {
    error_log('Kaspa payment verification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Payment verification failed: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * Record a completed autonomous ride payment
 */
function recordAutonomousRidePayment(int $rideId, float $amountEur, float $amountKas, string $txHash): bool
{
    try {
        // Update existing payment record or create new one
        $stmt = db_call_procedure('dbo.spUpdateAutonomousRidePayment', [
            $rideId,
            $amountEur,
            'KASPA',
            'completed',
            $txHash,
            json_encode([
                'kaspa_amount' => $amountKas,
                'kaspa_tx' => $txHash,
                'verified_at' => date('Y-m-d H:i:s')
            ])
        ]);
        
        if ($stmt === false) {
            error_log('Failed to update autonomous ride payment for ride ' . $rideId);
            return false;
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    } catch (Exception $e) {
        error_log('Error recording autonomous ride payment: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verify a Kaspa transaction on the blockchain
 */
function kaspa_verify_transaction(string $txHash, string $expectedAddress, float $expectedAmountKas): ?array
{
    // Query Kaspa API
    $apiUrl = 'https://api.kaspa.org/transactions/' . $txHash;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        return null;
    }
    
    $txData = json_decode($response, true);
    if (!$txData) {
        return null;
    }
    
    // Check if transaction is accepted (confirmed)
    $isAccepted = $txData['is_accepted'] ?? false;
    if (!$isAccepted) {
        return ['verified' => false, 'status' => 'pending'];
    }
    
    // Check outputs for the expected address and amount
    $outputs = $txData['outputs'] ?? [];
    foreach ($outputs as $output) {
        $scriptAddress = $output['script_public_key_address'] ?? '';
        // Amount in sompi (1 KAS = 100,000,000 sompi)
        $sompiAmount = (float)($output['amount'] ?? 0);
        $kasAmount = $sompiAmount / 100000000;
        
        // Check if this output matches our expected payment
        if ($scriptAddress === $expectedAddress) {
            // Allow 5% tolerance — payment must be at least 95% of expected amount
            $minRequired = $expectedAmountKas * 0.95;
            if ($kasAmount >= $minRequired) {
                return [
                    'verified' => true,
                    'status' => 'confirmed',
                    'amount_kas' => $kasAmount,
                    'block_time' => $txData['block_time'] ?? null
                ];
            } else {
                return [
                    'verified' => false,
                    'status' => 'insufficient_amount',
                    'amount_kas' => $kasAmount,
                    'expected_kas' => $expectedAmountKas,
                    'min_required_kas' => $minRequired
                ];
            }
        }
    }
    
    return ['verified' => false, 'status' => 'amount_mismatch'];
}
