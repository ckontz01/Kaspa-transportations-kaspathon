/**
 * OSRH Kaspa Payment Integration
 * 
 * Client-side JavaScript for:
 * - Wallet connection (KIP-12 browser extensions)
 * - QR code generation for payments
 * - Real-time payment status updates
 * - Exchange rate display
 * 
 * Requires: A KIP-12 compatible Kaspa wallet extension (like Kasware, KasWare, etc.)
 */

const OSRHKaspa = (function() {
    'use strict';

    // ============================================================
    // CONFIGURATION
    // ============================================================
    
    const config = {
        network: 'mainnet',
        apiUrl: '/api/kaspa',
        pollInterval: 5000,  // Check payment status every 5 seconds
        qrCodeSize: 256,
        minConfirmations: 10,
        debug: false
    };

    // State
    let provider = null;
    let providerInfo = null;
    let connectedAddress = null;
    let exchangeRate = null;
    let exchangeRateTimestamp = null;

    // ============================================================
    // WALLET CONNECTION (KIP-12)
    // ============================================================

    /**
     * Check if a Kaspa wallet extension is available
     */
    function isWalletAvailable() {
        return typeof window.kaspaProvider !== 'undefined' || 
               typeof window.kaspa !== 'undefined';
    }

    /**
     * Wait for wallet provider to be injected
     */
    function waitForProvider(timeout = 3000) {
        return new Promise((resolve, reject) => {
            if (isWalletAvailable()) {
                resolve(getProvider());
                return;
            }

            const handler = (event) => {
                window.removeEventListener('kaspa:provider', handler);
                provider = event.detail.provider;
                providerInfo = event.detail.info;
                resolve(provider);
            };

            window.addEventListener('kaspa:provider', handler);

            // Request provider
            window.dispatchEvent(new CustomEvent('kaspa:requestProvider'));

            setTimeout(() => {
                window.removeEventListener('kaspa:provider', handler);
                if (isWalletAvailable()) {
                    resolve(getProvider());
                } else {
                    reject(new Error('No Kaspa wallet extension found'));
                }
            }, timeout);
        });
    }

    /**
     * Get the wallet provider
     */
    function getProvider() {
        if (provider) return provider;
        
        if (window.kaspaProvider) {
            provider = window.kaspaProvider;
        } else if (window.kaspa) {
            provider = window.kaspa;
        }
        
        return provider;
    }

    /**
     * Connect to Kaspa wallet
     */
    async function connectWallet() {
        try {
            const walletProvider = await waitForProvider();
            
            if (!walletProvider) {
                throw new Error('No Kaspa wallet found. Please install a KIP-12 compatible wallet extension.');
            }

            // Connect to the wallet
            await walletProvider.connect();
            
            // Get the connected address
            const accounts = await walletProvider.request('kaspa:accounts', []);
            if (accounts && accounts.length > 0) {
                connectedAddress = accounts[0];
            }

            log('Wallet connected:', connectedAddress);
            
            return {
                success: true,
                address: connectedAddress,
                provider: walletProvider
            };
        } catch (error) {
            log('Wallet connection failed:', error.message);
            return {
                success: false,
                error: error.message
            };
        }
    }

    /**
     * Disconnect wallet
     */
    async function disconnectWallet() {
        try {
            const walletProvider = getProvider();
            if (walletProvider && walletProvider.disconnect) {
                await walletProvider.disconnect();
            }
            connectedAddress = null;
            return { success: true };
        } catch (error) {
            return { success: false, error: error.message };
        }
    }

    /**
     * Get connected wallet address
     */
    function getConnectedAddress() {
        return connectedAddress;
    }

    /**
     * Check if wallet is connected
     */
    function isConnected() {
        return connectedAddress !== null;
    }

    // ============================================================
    // PAYMENTS
    // ============================================================

    /**
     * Request a payment via wallet extension
     */
    async function requestPayment(toAddress, amountKas, options = {}) {
        try {
            const walletProvider = getProvider();
            if (!walletProvider) {
                throw new Error('Wallet not connected');
            }

            const amountSompi = Math.floor(amountKas * 100000000);

            // Build transaction request
            const txRequest = {
                to: toAddress,
                amount: amountSompi,
                ...(options.message && { data: options.message })
            };

            log('Requesting payment:', txRequest);

            // Send payment request to wallet
            const result = await walletProvider.request('kaspa:send', [txRequest]);

            if (result && result.txid) {
                return {
                    success: true,
                    transactionHash: result.txid,
                    amount: amountKas,
                    toAddress: toAddress
                };
            }

            return {
                success: false,
                error: 'Transaction was not completed'
            };
        } catch (error) {
            log('Payment request failed:', error.message);
            return {
                success: false,
                error: error.message
            };
        }
    }

    /**
     * Sign a message with the wallet
     */
    async function signMessage(message) {
        try {
            const walletProvider = getProvider();
            if (!walletProvider) {
                throw new Error('Wallet not connected');
            }

            const result = await walletProvider.request('kaspa:sign-personal', [message]);
            
            return {
                success: true,
                signature: result.signature,
                address: result.address || connectedAddress
            };
        } catch (error) {
            return {
                success: false,
                error: error.message
            };
        }
    }

    // ============================================================
    // QR CODE PAYMENTS
    // ============================================================

    /**
     * Generate payment QR code
     */
    function generatePaymentQR(address, amountKas, options = {}) {
        const amountSompi = Math.floor(amountKas * 100000000);
        let uri = `${address}?amount=${amountSompi}`;
        
        if (options.label) {
            uri += `&label=${encodeURIComponent(options.label)}`;
        }

        // Generate QR code using a library (assuming qrcode.js is loaded)
        if (typeof QRCode !== 'undefined') {
            const container = options.container || document.createElement('div');
            container.innerHTML = '';
            
            new QRCode(container, {
                text: uri,
                width: options.size || config.qrCodeSize,
                height: options.size || config.qrCodeSize,
                colorDark: options.colorDark || '#49EACB',
                colorLight: options.colorLight || '#1a1a2e',
                correctLevel: QRCode.CorrectLevel.M
            });

            return {
                success: true,
                uri: uri,
                container: container
            };
        }

        return {
            success: true,
            uri: uri,
            container: null
        };
    }

    /**
     * Create a payment request and display QR code
     */
    async function createPaymentWithQR(containerElement, toAddress, amountKas, options = {}) {
        const qrResult = generatePaymentQR(toAddress, amountKas, {
            ...options,
            container: containerElement.querySelector('.kaspa-qr-code') || containerElement
        });

        // Display payment info
        const infoHtml = `
            <div class="kaspa-payment-info">
                <div class="kaspa-amount">
                    <span class="kaspa-amount-kas">${formatKAS(amountKas)}</span>
                    ${options.amountEur ? `<span class="kaspa-amount-eur">≈ €${options.amountEur.toFixed(2)}</span>` : ''}
                </div>
                <div class="kaspa-address">
                    <span class="kaspa-address-label">Send to:</span>
                    <code class="kaspa-address-value">${shortenAddress(toAddress)}</code>
                    <button class="kaspa-copy-btn" onclick="OSRHKaspa.copyToClipboard('${toAddress}')">
                        📋 Copy
                    </button>
                </div>
                ${options.expiresAt ? `
                    <div class="kaspa-expires">
                        Payment expires: <span class="kaspa-timer" data-expires="${options.expiresAt}"></span>
                    </div>
                ` : ''}
            </div>
        `;

        const infoContainer = containerElement.querySelector('.kaspa-payment-details');
        if (infoContainer) {
            infoContainer.innerHTML = infoHtml;
        }

        // Start expiry countdown if applicable
        if (options.expiresAt) {
            startExpiryCountdown(containerElement.querySelector('.kaspa-timer'), options.expiresAt);
        }

        return qrResult;
    }

    // ============================================================
    // EXCHANGE RATE
    // ============================================================

    /**
     * Fetch current exchange rate
     */
    async function fetchExchangeRate() {
        try {
            const response = await fetch(`${config.apiUrl}/exchange-rate`);
            const data = await response.json();
            
            if (data && data.rateEURtoKAS) {
                exchangeRate = {
                    eurToKas: parseFloat(data.rateEURtoKAS),
                    kasToEur: parseFloat(data.rateKAStoEUR)
                };
                exchangeRateTimestamp = Date.now();
                return exchangeRate;
            }
        } catch (error) {
            log('Failed to fetch exchange rate:', error.message);
        }
        
        return null;
    }

    /**
     * Convert EUR to KAS
     */
    function convertEURtoKAS(amountEur) {
        if (!exchangeRate) {
            return null;
        }
        return amountEur * exchangeRate.eurToKas;
    }

    /**
     * Convert KAS to EUR
     */
    function convertKAStoEUR(amountKas) {
        if (!exchangeRate) {
            return null;
        }
        return amountKas * exchangeRate.kasToEur;
    }

    /**
     * Update exchange rate display elements
     */
    function updateRateDisplay() {
        const elements = document.querySelectorAll('.kaspa-rate-display');
        elements.forEach(el => {
            if (exchangeRate) {
                el.textContent = `1 EUR ≈ ${exchangeRate.eurToKas.toFixed(4)} KAS`;
                el.classList.remove('kaspa-rate-stale');
            } else {
                el.textContent = 'Rate unavailable';
                el.classList.add('kaspa-rate-stale');
            }
        });
    }

    // ============================================================
    // PAYMENT STATUS POLLING
    // ============================================================

    let statusPollers = new Map();

    /**
     * Start polling for payment status
     */
    function startPaymentStatusPolling(requestCode, onUpdate, options = {}) {
        if (statusPollers.has(requestCode)) {
            return;
        }

        const poll = async () => {
            try {
                const response = await fetch(`${config.apiUrl}/payment-status/${requestCode}`);
                const data = await response.json();
                
                if (onUpdate) {
                    onUpdate(data);
                }

                // Stop polling if payment is complete or expired
                if (data.status === 'completed' || data.status === 'expired' || data.status === 'cancelled') {
                    stopPaymentStatusPolling(requestCode);
                }
            } catch (error) {
                log('Payment status poll failed:', error.message);
            }
        };

        // Initial poll
        poll();

        // Set up interval
        const intervalId = setInterval(poll, options.interval || config.pollInterval);
        statusPollers.set(requestCode, intervalId);
    }

    /**
     * Stop polling for payment status
     */
    function stopPaymentStatusPolling(requestCode) {
        const intervalId = statusPollers.get(requestCode);
        if (intervalId) {
            clearInterval(intervalId);
            statusPollers.delete(requestCode);
        }
    }

    // ============================================================
    // UTILITIES
    // ============================================================

    /**
     * Format KAS amount
     */
    function formatKAS(amount, decimals = 4) {
        return parseFloat(amount).toFixed(decimals) + ' KAS';
    }

    /**
     * Shorten address for display
     */
    function shortenAddress(address, startChars = 12, endChars = 8) {
        if (address.length <= startChars + endChars + 3) {
            return address;
        }
        return address.substring(0, startChars) + '...' + address.substring(address.length - endChars);
    }

    /**
     * Copy text to clipboard
     */
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('Address copied to clipboard!');
        }).catch(err => {
            log('Copy failed:', err);
        });
    }

    /**
     * Show a toast notification
     */
    function showToast(message, duration = 3000) {
        const toast = document.createElement('div');
        toast.className = 'kaspa-toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    /**
     * Start expiry countdown
     */
    function startExpiryCountdown(element, expiresAt) {
        if (!element) return;

        const expiry = new Date(expiresAt).getTime();

        const update = () => {
            const now = Date.now();
            const diff = expiry - now;

            if (diff <= 0) {
                element.textContent = 'Expired';
                element.classList.add('expired');
                return false;
            }

            const minutes = Math.floor(diff / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            element.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            if (diff < 60000) {
                element.classList.add('warning');
            }

            return true;
        };

        if (update()) {
            const interval = setInterval(() => {
                if (!update()) {
                    clearInterval(interval);
                }
            }, 1000);
        }
    }

    /**
     * Validate Kaspa address format
     */
    function validateAddress(address) {
        if (!address) return { valid: false, error: 'Address is required' };
        
        address = address.trim();
        
        const validPrefixes = ['kaspa:', 'kaspatest:', 'kaspadev:'];
        const hasValidPrefix = validPrefixes.some(p => address.startsWith(p));
        
        if (!hasValidPrefix) {
            return { valid: false, error: 'Invalid address prefix' };
        }

        if (address.length < 60 || address.length > 70) {
            return { valid: false, error: 'Invalid address length' };
        }

        return { valid: true };
    }

    /**
     * Debug logging
     */
    function log(...args) {
        if (config.debug) {
            console.log('[OSRHKaspa]', ...args);
        }
    }

    // ============================================================
    // UI COMPONENTS
    // ============================================================

    /**
     * Create wallet connect button
     */
    function createConnectButton(container, options = {}) {
        const btn = document.createElement('button');
        btn.className = 'kaspa-connect-btn ' + (options.className || '');
        btn.innerHTML = `
            <span class="kaspa-icon">💎</span>
            <span class="kaspa-btn-text">Connect Kaspa Wallet</span>
        `;
        
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.classList.add('loading');
            
            const result = await connectWallet();
            
            btn.disabled = false;
            btn.classList.remove('loading');
            
            if (result.success) {
                btn.innerHTML = `
                    <span class="kaspa-icon">✓</span>
                    <span class="kaspa-btn-text">${shortenAddress(result.address)}</span>
                `;
                btn.classList.add('connected');
                
                if (options.onConnect) {
                    options.onConnect(result);
                }
            } else {
                showToast(result.error || 'Failed to connect wallet');
                
                if (options.onError) {
                    options.onError(result);
                }
            }
        });

        container.appendChild(btn);
        return btn;
    }

    /**
     * Create payment widget
     */
    function createPaymentWidget(container, options = {}) {
        const widget = document.createElement('div');
        widget.className = 'kaspa-payment-widget';
        widget.innerHTML = `
            <div class="kaspa-widget-header">
                <span class="kaspa-logo">💎</span>
                <span class="kaspa-title">Pay with Kaspa</span>
            </div>
            <div class="kaspa-payment-details"></div>
            <div class="kaspa-qr-code"></div>
            <div class="kaspa-payment-actions">
                <button class="kaspa-pay-btn" ${!isWalletAvailable() ? 'style="display:none"' : ''}>
                    Pay with Wallet
                </button>
                <div class="kaspa-or-divider" ${!isWalletAvailable() ? 'style="display:none"' : ''}>or scan QR code</div>
            </div>
            <div class="kaspa-payment-status"></div>
        `;

        container.appendChild(widget);

        // Set up wallet payment button
        const payBtn = widget.querySelector('.kaspa-pay-btn');
        if (payBtn && options.toAddress && options.amountKas) {
            payBtn.addEventListener('click', async () => {
                if (!isConnected()) {
                    const connectResult = await connectWallet();
                    if (!connectResult.success) {
                        showToast('Please connect your wallet first');
                        return;
                    }
                }

                payBtn.disabled = true;
                const result = await requestPayment(options.toAddress, options.amountKas, options);
                payBtn.disabled = false;

                if (result.success) {
                    if (options.onPaymentSent) {
                        options.onPaymentSent(result);
                    }
                } else {
                    showToast(result.error || 'Payment failed');
                }
            });
        }

        return widget;
    }

    // ============================================================
    // INITIALIZATION
    // ============================================================

    /**
     * Initialize the Kaspa module
     */
    async function init(options = {}) {
        Object.assign(config, options);
        
        // Fetch initial exchange rate
        await fetchExchangeRate();
        
        // Set up periodic rate updates
        setInterval(fetchExchangeRate, 60000);
        
        // Check for wallet on page load
        if (isWalletAvailable()) {
            log('Kaspa wallet extension detected');
        }

        log('OSRHKaspa initialized');
    }

    // ============================================================
    // PUBLIC API
    // ============================================================

    return {
        // Initialization
        init,
        
        // Wallet
        isWalletAvailable,
        connectWallet,
        disconnectWallet,
        getConnectedAddress,
        isConnected,
        
        // Payments
        requestPayment,
        signMessage,
        generatePaymentQR,
        createPaymentWithQR,
        
        // Exchange rate
        fetchExchangeRate,
        convertEURtoKAS,
        convertKAStoEUR,
        updateRateDisplay,
        
        // Status polling
        startPaymentStatusPolling,
        stopPaymentStatusPolling,
        
        // Utilities
        formatKAS,
        shortenAddress,
        copyToClipboard,
        validateAddress,
        
        // UI Components
        createConnectButton,
        createPaymentWidget,
        
        // Config
        config
    };
})();

// Auto-initialize if DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => OSRHKaspa.init());
} else {
    OSRHKaspa.init();
}
