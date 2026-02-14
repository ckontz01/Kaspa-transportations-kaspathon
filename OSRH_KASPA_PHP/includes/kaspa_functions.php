<?php
/**
 * Kaspa Cryptocurrency Payment Integration
 * 
 * Provides functions for:
 * - Wallet address validation
 * - Exchange rate management
 * - Payment request creation
 * - Transaction tracking
 * - QR code generation for payments
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/kaspa_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// ============================================================
// ADDRESS VALIDATION
// ============================================================

/**
 * Validate a Kaspa wallet address format
 */
function kaspa_validate_address(string $address): array
{
    $address = trim($address);
    
    // Check for valid prefix
    $validPrefixes = ['kaspa:', 'kaspatest:', 'kaspadev:', 'kaspasim:'];
    $hasValidPrefix = false;
    $prefix = '';
    
    foreach ($validPrefixes as $p) {
        if (str_starts_with($address, $p)) {
            $hasValidPrefix = true;
            $prefix = rtrim($p, ':');
            break;
        }
    }
    
    if (!$hasValidPrefix) {
        return [
            'valid' => false,
            'error' => 'Invalid address prefix. Must start with kaspa:, kaspatest:, kaspadev:, or kaspasim:'
        ];
    }
    
    // Check if testnet addresses are allowed
    if (defined('KASPA_ALLOW_TESTNET') && !KASPA_ALLOW_TESTNET && $prefix !== 'kaspa') {
        return [
            'valid' => false,
            'error' => 'Only mainnet addresses (kaspa:) are allowed'
        ];
    }
    
    // Basic length check (Kaspa addresses are ~61-63 chars including prefix)
    if (strlen($address) < 60 || strlen($address) > 70) {
        return [
            'valid' => false,
            'error' => 'Invalid address length'
        ];
    }
    
    // Check for valid Bech32 characters after prefix
    $addressPart = substr($address, strpos($address, ':') + 1);
    if (!preg_match('/^[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/', $addressPart)) {
        return [
            'valid' => false,
            'error' => 'Invalid characters in address'
        ];
    }
    
    return [
        'valid' => true,
        'prefix' => $prefix,
        'network' => $prefix === 'kaspa' ? 'mainnet' : 'testnet'
    ];
}

// ============================================================
// WALLET MANAGEMENT
// ============================================================

/**
 * Add a Kaspa wallet for a user
 */
function kaspa_add_wallet(
    int $userId,
    string $walletAddress,
    string $walletType = 'receive',
    ?string $label = null,
    bool $isDefault = false
): array {
    // Validate address first
    $validation = kaspa_validate_address($walletAddress);
    if (!$validation['valid']) {
        return ['success' => false, 'error' => $validation['error']];
    }
    
    $labelValue = ($label !== null && $label !== '') ? $label : 'My Wallet';
    
    $stmt = db_exec_procedure('dbo.spKaspaAddWallet', [
        $userId,
        $walletAddress,
        $walletType,
        $labelValue,
        $isDefault ? 1 : 0
    ]);
    
    if ($stmt === false) {
        return ['success' => false, 'error' => 'Failed to save wallet'];
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if ($result && !empty($result['Success'])) {
        return ['success' => true, 'message' => $result['Message'] ?? 'Wallet saved'];
    }
    
    return ['success' => false, 'error' => $result['Message'] ?? 'Could not save wallet'];
}

/**
 * Get all Kaspa wallets for a user
 */
function kaspa_get_user_wallets(int $userId): array
{
    $stmt = db_call_procedure('dbo.spKaspaGetUserWallets', [$userId]);
    if ($stmt === false) {
        return [];
    }
    
    $wallets = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $wallets[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $wallets;
}

/**
 * Get user's default wallet for receiving payments
 */
function kaspa_get_default_wallet(int $userId, string $walletType = 'receive'): ?array
{
    $stmt = db_call_procedure('dbo.spKaspaGetDefaultWallet', [$userId, $walletType]);
    if ($stmt === false) {
        return null;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result ?: null;
}

/**
 * Get driver's Kaspa wallet for receiving trip payments
 */
function kaspa_get_driver_wallet(int $driverId): ?array
{
    $stmt = db_call_procedure('dbo.spKaspaGetDriverWallet', [$driverId]);
    if ($stmt === false) {
        return null;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result ?: null;
}

/**
 * Get OSRH platform wallet for autonomous vehicles and carshare payments
 * Returns the platform's wallet address when there's no individual driver
 */
function kaspa_get_platform_wallet(): ?array
{
    // Get from config constant
    $walletAddress = defined('KASPA_OSRH_WALLET') ? KASPA_OSRH_WALLET : '';
    
    if (empty($walletAddress)) {
        return null;
    }
    
    // Validate the address
    $validation = kaspa_validate_address($walletAddress);
    if (!$validation['valid']) {
        error_log('Invalid OSRH platform wallet address configured: ' . $walletAddress);
        return null;
    }
    
    return [
        'WalletID' => 0,  // Platform wallet has no DB ID
        'UserID' => 0,    // No user associated
        'WalletAddress' => $walletAddress,
        'AddressPrefix' => $validation['prefix'],
        'WalletType' => 'receive',
        'Label' => 'OSRH Platform Wallet',
        'IsDefault' => true,
        'IsPlatformWallet' => true
    ];
}

/**
 * Get the appropriate wallet for a payment type
 * - For regular trips: driver's wallet
 * - For autonomous rides: OSRH platform wallet  
 * - For carshare: OSRH platform wallet
 */
function kaspa_get_payment_wallet(string $paymentType, ?int $driverUserId = null): ?array
{
    switch ($paymentType) {
        case 'trip':
        case 'segment':
            // Regular trips go to the driver
            if ($driverUserId) {
                return kaspa_get_default_wallet($driverUserId, 'receive');
            }
            return null;
            
        case 'autonomous':
        case 'carshare':
            // Autonomous and carshare payments go to OSRH platform
            return kaspa_get_platform_wallet();
            
        default:
            return null;
    }
}

// ============================================================
// EXCHANGE RATES
// ============================================================

/**
 * Get current KAS/EUR exchange rate
 */
function kaspa_get_exchange_rate(): ?float
{
    // Try to get from database cache first
    $stmt = db_call_procedure('dbo.spKaspaGetExchangeRate', []);
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($result && isset($result['RateKAStoEUR'])) {
            return (float)$result['RateKAStoEUR'];
        }
    }
    
    // Fetch fresh rate from CoinGecko
    return kaspa_fetch_exchange_rate();
}

/**
 * Fetch fresh exchange rate from CoinGecko API
 */
function kaspa_fetch_exchange_rate(): ?float
{
    $url = 'https://api.coingecko.com/api/v3/simple/price?ids=kaspa&vs_currencies=eur';
    
    // Try cURL first (more reliable with SSL)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // For development - enable in production
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: OSRH-Transport/1.0'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            error_log("CoinGecko API error: HTTP $httpCode, Error: $error");
            // Fall through to file_get_contents
        } else {
            $data = json_decode($response, true);
            if (isset($data['kaspa']['eur'])) {
                $rate = (float)$data['kaspa']['eur'];
                
                // Cache the rate in database
                $stmt = db_call_procedure('dbo.spKaspaUpdateExchangeRate', [$rate, 'coingecko', 5]);
                if ($stmt !== false) {
                    sqlsrv_free_stmt($stmt);
                }
                
                return $rate;
            }
        }
    }
    
    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "Accept: application/json\r\nUser-Agent: OSRH-Transport/1.0"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        error_log("CoinGecko API: file_get_contents failed");
        return null;
    }
    
    $data = json_decode($response, true);
    if (!isset($data['kaspa']['eur'])) {
        error_log("CoinGecko API: Invalid response format");
        return null;
    }
    
    $rate = (float)$data['kaspa']['eur'];
    
    // Cache the rate in database
    $stmt = db_call_procedure('dbo.spKaspaUpdateExchangeRate', [$rate, 'coingecko', 5]);
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
    
    return $rate;
}

/**
 * Convert EUR to KAS
 */
function kaspa_convert_eur_to_kas(float $eurAmount): ?float
{
    $rate = kaspa_get_exchange_rate();
    if ($rate === null || $rate <= 0) {
        return null;
    }
    
    return $eurAmount / $rate;
}

/**
 * Convert KAS to EUR
 */
function kaspa_convert_kas_to_eur(float $kasAmount): ?float
{
    $rate = kaspa_get_exchange_rate();
    if ($rate === null || $rate <= 0) {
        return null;
    }
    
    return $kasAmount * $rate;
}

// ============================================================
// PAYMENT REQUESTS
// ============================================================

/**
 * Create a payment request for a trip
 */
function kaspa_create_payment_request(
    int $tripId,
    float $amountEur,
    string $receiverAddress
): ?array {
    $amountKas = kaspa_convert_eur_to_kas($amountEur);
    if ($amountKas === null) {
        return null;
    }
    
    $requestCode = 'KAS-' . strtoupper(bin2hex(random_bytes(8)));
    $rate = kaspa_get_exchange_rate();
    
    $stmt = db_call_procedure('dbo.spKaspaCreatePaymentRequest', [
        $tripId,
        $receiverAddress,
        $amountEur,
        $amountKas,
        $rate,
        $requestCode
    ]);
    
    if ($stmt === false) {
        return null;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$result) {
        return null;
    }
    
    return [
        'request_id' => $result['RequestID'] ?? null,
        'request_code' => $requestCode,
        'amount_eur' => $amountEur,
        'amount_kas' => $amountKas,
        'receiver_address' => $receiverAddress,
        'exchange_rate' => $rate,
        'kaspa_uri' => kaspa_generate_payment_uri($receiverAddress, $amountKas, $requestCode)
    ];
}

/**
 * Generate a Kaspa payment URI (for QR codes)
 */
function kaspa_generate_payment_uri(string $address, float $amount, ?string $label = null): string
{
    $cleanAddress = $address;
    if (str_contains($address, ':')) {
        $cleanAddress = substr($address, strpos($address, ':') + 1);
    }
    
    $uri = 'kaspa:' . $cleanAddress . '?amount=' . number_format($amount, 8, '.', '');
    
    if ($label) {
        $uri .= '&label=' . urlencode($label);
    }
    
    return $uri;
}

/**
 * Record a Kaspa transaction
 */
function kaspa_record_transaction(
    int $paymentRequestId,
    string $transactionHash,
    float $amountKas,
    float $amountEur,
    int $senderUserId,
    int $receiverUserId
): ?int {
    $stmt = db_call_procedure('dbo.spKaspaRecordTransaction', [
        $paymentRequestId,
        $transactionHash,
        $amountKas,
        $amountEur,
        $senderUserId,
        $receiverUserId
    ]);
    
    if ($stmt === false) {
        return null;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result['TransactionID'] ?? null;
}

// ============================================================
// EXPLORER LINKS
// ============================================================

/**
 * Get Kaspa explorer URL for an address
 */
function kaspa_address_explorer_url(string $address): string
{
    $network = defined('KASPA_NETWORK') ? KASPA_NETWORK : 'mainnet';
    $baseUrl = $network === 'mainnet' 
        ? 'https://explorer.kaspa.org/addresses/' 
        : 'https://explorer-tn10.kaspa.org/addresses/';
    
    return $baseUrl . urlencode($address);
}

/**
 * Get Kaspa explorer URL for a transaction
 */
function kaspa_transaction_explorer_url(string $txHash): string
{
    $network = defined('KASPA_NETWORK') ? KASPA_NETWORK : 'mainnet';
    $baseUrl = $network === 'mainnet' 
        ? 'https://explorer.kaspa.org/txs/' 
        : 'https://explorer-tn10.kaspa.org/txs/';
    
    return $baseUrl . urlencode($txHash);
}
