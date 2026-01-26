<?php
/**
 * Kaspa Cryptocurrency Payment Configuration
 * 
 * Configure your Kaspa network connection and wallet settings here.
 * Uses the Kaspa WASM SDK via Node.js backend for blockchain operations.
 */

declare(strict_types=1);

// ============================================================
// NETWORK CONFIGURATION
// ============================================================

// Network: 'mainnet', 'testnet-10', 'testnet-11'
define('KASPA_NETWORK', getenv('KASPA_NETWORK') ?: 'mainnet');

// Network prefix for addresses
define('KASPA_ADDRESS_PREFIX', KASPA_NETWORK === 'mainnet' ? 'kaspa' : 'kaspatest');

// Kaspa Public API URL (kas.fyi API for transaction lookups)
define('KASPA_API_URL', getenv('KASPA_API_URL') ?: 'https://api.kaspa.org');

// Alternative API endpoints
define('KASPA_EXPLORER_API', 'https://api.kaspa.org');
define('KASPA_EXPLORER_URL', 'https://explorer.kaspa.org');

// API Key for your Kaspa backend (if configured)
define('KASPA_API_KEY', getenv('KASPA_API_KEY') ?: '');

// ============================================================
// EXCHANGE RATE CONFIGURATION
// ============================================================

// Exchange rate API source
define('KASPA_RATE_SOURCE', 'coingecko');  // coingecko, manual

// Rate update interval in minutes
define('KASPA_RATE_UPDATE_INTERVAL', 5);

// Fallback rate if API is unavailable (1 EUR = X KAS)
define('KASPA_FALLBACK_RATE_EUR_TO_KAS', 10.0);

// Maximum rate age in minutes before considered stale
define('KASPA_MAX_RATE_AGE', 15);

// ============================================================
// PAYMENT CONFIGURATION
// ============================================================

// Payment request expiration in minutes
define('KASPA_PAYMENT_EXPIRY_MINUTES', 30);

// Minimum confirmations required for payment to be considered complete
define('KASPA_MIN_CONFIRMATIONS', 10);

// Minimum amount in KAS (prevent dust transactions)
define('KASPA_MIN_AMOUNT', 0.001);

// Maximum amount in KAS per transaction
define('KASPA_MAX_AMOUNT', 1000000.0);

// ============================================================
// OSRH PLATFORM WALLET (for autonomous vehicles & carshare)
// ============================================================

// Platform wallet address for receiving autonomous vehicle and carshare payments
// This wallet collects payments when there's no individual driver
// Set via environment variable or hardcode here
define('KASPA_OSRH_WALLET', getenv('KASPA_OSRH_WALLET') ?: 'kaspa:qz9fgzskf5n0nuyvuj5njc0hxhyye95t5qahtev6c3azj9mcpklyj0vj4c0np');

// ============================================================
// OPERATOR WALLET (for service fees - if applicable)
// ============================================================

// Platform wallet address for receiving service fees
// Note: OSRH has 0% fees, but this is here for future use
define('KASPA_PLATFORM_WALLET', getenv('KASPA_PLATFORM_WALLET') ?: '');

// ============================================================
// QR CODE CONFIGURATION
// ============================================================

// QR code size in pixels
define('KASPA_QR_SIZE', 256);

// QR code error correction level (L, M, Q, H)
define('KASPA_QR_ERROR_CORRECTION', 'M');

// ============================================================
// SECURITY
// ============================================================

// Enable address validation
define('KASPA_VALIDATE_ADDRESSES', true);

// Allow testnet addresses in production (should be false)
define('KASPA_ALLOW_TESTNET', KASPA_NETWORK !== 'mainnet');

// Webhook secret for transaction notifications
define('KASPA_WEBHOOK_SECRET', getenv('KASPA_WEBHOOK_SECRET') ?: '');

// ============================================================
// DEBUG & LOGGING
// ============================================================

// Enable debug mode
define('KASPA_DEBUG', getenv('KASPA_DEBUG') === 'true');

// Log file for Kaspa transactions
define('KASPA_LOG_FILE', __DIR__ . '/../logs/kaspa.log');
