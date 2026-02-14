<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/kaspa_functions.php';

require_login();
require_role('passenger');

$user       = current_user();
$passenger  = $user['passenger'] ?? null;

if (!$passenger || !isset($passenger['PassengerID'])) {
    redirect('error.php?code=403');
}

$passengerId   = (int)$passenger['PassengerID'];
$currentUserId = current_user_id();

$tripId = null;
if (isset($_GET['trip_id'])) {
    $tripId = (int)$_GET['trip_id'];
} elseif (isset($_POST['trip_id'])) {
    $tripId = (int)$_POST['trip_id'];
}

if (!$tripId || $tripId <= 0) {
    redirect('error.php?code=404');
}

function osrh_load_trip_for_passenger(int $tripId, int $passengerId): ?array
{
    $stmt = db_call_procedure('dbo.spGetPassengerTripDetails', [$tripId, $passengerId]);
    if ($stmt === false) {
        return null;
    }
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $result ?: null;
}

function osrh_format_dt_detail($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i');
    }
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i');
    }
    return $value !== null ? (string)$value : '';
}

$trip = osrh_load_trip_for_passenger($tripId, $passengerId);
if (!$trip) {
    redirect('error.php?code=404');
}

// Latest payment
$payment = null;
$stmt = db_call_procedure('dbo.spGetTripPayment', [$tripId]);
if ($stmt !== false) {
    $payment = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Rating from this user
$rating = null;
$stmt = db_call_procedure('dbo.spGetTripRating', [$tripId, $currentUserId]);
if ($stmt !== false) {
    $rating = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Payment methods
$paymentMethods = [];
$stmt = db_call_procedure('dbo.spGetPaymentMethods', []);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $paymentMethods[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

// Check for ride segments (multi-vehicle journey)
// Only load the segment that belongs to THIS trip (exclusive view)
$segments = [];
$currentSegment = null;
$allSegments = []; // All segments for journey overview
$stmt = db_call_procedure('dbo.spGetRideSegments', [$trip['RideRequestID']]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $allSegments[] = $row;
        // Check if this segment belongs to the current trip
        if (!empty($row['TripID']) && (int)$row['TripID'] === $tripId) {
            $currentSegment = $row;
            $segments[] = $row; // Only include the current segment for payment display
        }
    }
    sqlsrv_free_stmt($stmt);
}

// If this trip has a matching segment, it's a segment trip - show only that segment
$isSegmentTrip = !empty($currentSegment);
$totalSegmentCount = count($allSegments);

// Find the next segment (if any) for multi-segment journeys
$nextSegment = null;
$nextSegmentTripId = null;
if ($isSegmentTrip && $currentSegment) {
    $currentSegmentOrder = (int)($currentSegment['SegmentOrder'] ?? 0);
    foreach ($allSegments as $seg) {
        if ((int)($seg['SegmentOrder'] ?? 0) === $currentSegmentOrder + 1) {
            $nextSegment = $seg;
            $nextSegmentTripId = !empty($seg['TripID']) ? (int)$seg['TripID'] : null;
            break;
        }
    }
}

$errors = [];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)array_get($_POST, 'action', '');
    $token  = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($action === 'rate_trip') {
            $stars   = (int)array_get($_POST, 'stars', 0);
            $comment = trim((string)array_get($_POST, 'comment', ''));

            if ($stars < 1 || $stars > 5) {
                $errors['rating'] = 'Rating must be between 1 and 5 stars.';
            }

            if (!$errors) {
                $driverUserId = (int)$trip['DriverUserID'];

                $stmt = db_call_procedure('dbo.spRateTrip', [
                    $tripId,
                    $currentUserId,
                    $driverUserId,
                    $stars,
                    $comment !== '' ? $comment : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not save rating. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Your rating has been saved.');
                    redirect('passenger/ride_detail.php?trip_id=' . urlencode((string)$tripId));
                }
            }
        } elseif ($action === 'create_payment') {
            $methodId    = (int)array_get($_POST, 'payment_method_type_id', 0);
            $amountRaw   = trim((string)array_get($_POST, 'amount', ''));
            $currency    = trim((string)array_get($_POST, 'currency_code', 'EUR'));
            $providerRef = trim((string)array_get($_POST, 'provider_reference', ''));

            if ($methodId <= 0) {
                $errors['payment'] = 'Please select a payment method.';
            } elseif ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
                $errors['payment'] = 'Please enter a valid positive amount.';
            }

            // SERVER-SIDE: If this is a Kaspa payment, verify the transaction on the blockchain
            if (!$errors && str_starts_with($providerRef, 'KASPA:')) {
                $kaspaTxHash = substr($providerRef, 6); // Remove 'KASPA:' prefix
                
                // Validate hash format
                if (!preg_match('/^[a-f0-9]{64}$/i', $kaspaTxHash)) {
                    $errors['payment'] = 'Invalid Kaspa transaction hash.';
                } else {
                    // Get driver's Kaspa wallet address
                    $driverUserIdForPayment = $trip['DriverUserID'] ?? null;
                    $driverKaspaWalletForVerify = null;
                    if ($driverUserIdForPayment) {
                        $driverKaspaWalletForVerify = kaspa_get_default_wallet((int)$driverUserIdForPayment, 'receive');
                    }
                    
                    if (!$driverKaspaWalletForVerify || empty($driverKaspaWalletForVerify['WalletAddress'])) {
                        $errors['payment'] = 'Driver Kaspa wallet not found. Cannot verify transaction.';
                    } else {
                        $driverWalletAddr = $driverKaspaWalletForVerify['WalletAddress'];
                        
                        // Calculate expected KAS amount
                        $expectedEur = (float)$amountRaw;
                        $kaspaRate = kaspa_get_exchange_rate();
                        $expectedKas = $kaspaRate ? ($expectedEur / $kaspaRate) : 0;
                        
                        if ($expectedKas <= 0) {
                            $errors['payment'] = 'Could not determine Kaspa exchange rate for verification.';
                        } else {
                            // Verify the transaction on the blockchain
                            $verifyUrl = 'https://api.kaspa.org/transactions/' . $kaspaTxHash;
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $verifyUrl,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => 0,
                                CURLOPT_HTTPHEADER => ['Accept: application/json']
                            ]);
                            $txResponse = curl_exec($ch);
                            $txHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($txHttpCode !== 200 || !$txResponse) {
                                $errors['payment'] = 'Transaction not found on the Kaspa blockchain. Please ensure payment was sent.';
                            } else {
                                $txData = json_decode($txResponse, true);
                                $txAccepted = $txData['is_accepted'] ?? false;
                                
                                if (!$txAccepted) {
                                    $errors['payment'] = 'Transaction is not yet confirmed on the blockchain. Please wait and try again.';
                                } else {
                                    // Check outputs for correct address and amount
                                    $txOutputs = $txData['outputs'] ?? [];
                                    $foundValidPayment = false;
                                    
                                    foreach ($txOutputs as $txOut) {
                                        $outAddr = $txOut['script_public_key_address'] ?? '';
                                        $outSompi = (int)($txOut['amount'] ?? 0);
                                        $outKas = $outSompi / 100000000;
                                        
                                        if (strcasecmp($outAddr, $driverWalletAddr) === 0) {
                                            // Address matches — check amount (5% tolerance)
                                            $minRequired = $expectedKas * 0.95;
                                            if ($outKas >= $minRequired) {
                                                $foundValidPayment = true;
                                            } else {
                                                $errors['payment'] = 'Insufficient Kaspa payment: received ' . number_format($outKas, 4) . ' KAS, but at least ' . number_format($minRequired, 4) . ' KAS is required (95% of ' . number_format($expectedKas, 4) . ' KAS).';
                                            }
                                            break;
                                        }
                                    }
                                    
                                    if (!$foundValidPayment && !isset($errors['payment'])) {
                                        $errors['payment'] = 'Transaction does not include payment to the driver\'s Kaspa wallet address.';
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Check that Kaspa payments MUST have a verified transaction reference
            if (!$errors) {
                // Look up the payment method code to see if it's KASPA
                $selectedMethodCode = '';
                foreach ($paymentMethods as $pm) {
                    if ((int)$pm['PaymentMethodTypeID'] === $methodId) {
                        $selectedMethodCode = strtoupper($pm['Code'] ?? '');
                        break;
                    }
                }
                
                if ($selectedMethodCode === 'KASPA' && !str_starts_with($providerRef, 'KASPA:')) {
                    $errors['payment'] = 'Kaspa payment requires a verified blockchain transaction. Please use the "Pay with Wallet" button to send payment first.';
                }
            }

            if (!$errors) {
                $amount = (float)$amountRaw;

                $stmt = db_call_procedure('dbo.spCreatePaymentForTrip', [
                    $tripId,
                    $methodId,
                    $amount,
                    $currency !== '' ? $currency : 'EUR',
                    $providerRef !== '' ? $providerRef : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not create payment. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Payment record created.');
                    redirect('passenger/ride_detail.php?trip_id=' . urlencode((string)$tripId));
                }
            }
        } elseif ($action === 'complete_payment') {
            if (!$payment || !isset($payment['PaymentID'])) {
                $errors['payment'] = 'No payment found to complete.';
            } else {
                $providerRef = trim((string)array_get($_POST, 'provider_reference', ''));

                $stmt = db_call_procedure('dbo.spCompletePayment', [
                    (int)$payment['PaymentID'],
                    $providerRef !== '' ? $providerRef : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not complete payment. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    try {
                        $receiptPayment = get_payment_details((int)$payment['PaymentID']) ?: $payment;
                        $receiptAmount = $receiptPayment['TotalAmount'] ?? $receiptPayment['Amount'] ?? null;
                        $receiptCurrency = $receiptPayment['CurrencyCode'] ?? 'EUR';
                        $receiptMethod = $receiptPayment['PaymentMethodName'] ?? ($receiptPayment['PaymentMethod'] ?? null);
                        $receiptReference = $providerRef !== '' ? $providerRef : ($receiptPayment['ProviderReference'] ?? null);
                        $receiptWhen = $receiptPayment['CompletedAt'] ?? ($receiptPayment['UpdatedAt'] ?? new DateTime('now', new DateTimeZone('Europe/Nicosia')));

                        osrh_send_payment_receipt([
                            'email'    => current_user_email(),
                            'name'     => current_user_name() ?? ($trip['PassengerName'] ?? 'Passenger'),
                            'subject'  => 'Your payment receipt',
                            'amount'   => $receiptAmount,
                            'currency' => $receiptCurrency,
                            'method'   => $receiptMethod,
                            'reference'=> $receiptReference,
                            'when'     => $receiptWhen,
                            'context'  => array_filter([
                                isset($trip['DriverName']) ? '- Driver: ' . $trip['DriverName'] : null,
                                (isset($trip['PickupAddress']) || isset($trip['DropoffAddress']))
                                    ? '- Route: ' . ($trip['PickupAddress'] ?? 'Pickup') . ' -> ' . ($trip['DropoffAddress'] ?? 'Dropoff')
                                    : null,
                            ]),
                        ]);
                    } catch (Throwable $mailError) {
                        error_log('Trip payment receipt email failed: ' . $mailError->getMessage());
                    }
                    flash_add('success', 'Payment has been marked as completed.');
                    redirect('passenger/ride_detail.php?trip_id=' . urlencode((string)$tripId));
                }
            }
        } elseif ($action === 'create_segment_payment') {
            // Handle segment payment creation
            $segmentId = (int)array_get($_POST, 'segment_id', 0);
            $methodId = (int)array_get($_POST, 'payment_method_type_id', 0);
            $amountRaw = trim((string)array_get($_POST, 'amount', ''));
            $currency = trim((string)array_get($_POST, 'currency_code', 'EUR'));
            $providerRef = trim((string)array_get($_POST, 'provider_reference', ''));

            if ($segmentId <= 0) {
                $errors['segment_payment'] = 'Invalid segment.';
            } elseif ($methodId <= 0) {
                $errors['segment_payment'] = 'Please select a payment method.';
            } elseif ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
                $errors['segment_payment'] = 'Please enter a valid positive amount.';
            }

            // SERVER-SIDE: If Kaspa segment payment, verify transaction on blockchain
            if (!$errors && str_starts_with($providerRef, 'KASPA:')) {
                $kaspaTxHash = substr($providerRef, 6);
                
                if (!preg_match('/^[a-f0-9]{64}$/i', $kaspaTxHash)) {
                    $errors['segment_payment'] = 'Invalid Kaspa transaction hash.';
                } else {
                    // Find the segment's driver wallet address
                    $segDriverWallet = null;
                    foreach ($segments as $seg) {
                        if ((int)($seg['SegmentID'] ?? 0) === $segmentId) {
                            $segDriverUserId = $seg['DriverUserID'] ?? null;
                            if ($segDriverUserId) {
                                $segDriverWallet = kaspa_get_default_wallet((int)$segDriverUserId, 'receive');
                            }
                            break;
                        }
                    }
                    
                    if (!$segDriverWallet || empty($segDriverWallet['WalletAddress'])) {
                        $errors['segment_payment'] = 'Segment driver Kaspa wallet not found.';
                    } else {
                        $segWalletAddr = $segDriverWallet['WalletAddress'];
                        $expectedEur = (float)$amountRaw;
                        $kaspaRate = kaspa_get_exchange_rate();
                        $expectedKas = $kaspaRate ? ($expectedEur / $kaspaRate) : 0;
                        
                        if ($expectedKas <= 0) {
                            $errors['segment_payment'] = 'Could not determine Kaspa exchange rate.';
                        } else {
                            $verifyUrl = 'https://api.kaspa.org/transactions/' . $kaspaTxHash;
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $verifyUrl,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => 0,
                                CURLOPT_HTTPHEADER => ['Accept: application/json']
                            ]);
                            $txResponse = curl_exec($ch);
                            $txHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($txHttpCode !== 200 || !$txResponse) {
                                $errors['segment_payment'] = 'Transaction not found on the Kaspa blockchain.';
                            } else {
                                $txData = json_decode($txResponse, true);
                                if (!($txData['is_accepted'] ?? false)) {
                                    $errors['segment_payment'] = 'Transaction not yet confirmed. Please wait and try again.';
                                } else {
                                    $txOutputs = $txData['outputs'] ?? [];
                                    $foundValid = false;
                                    foreach ($txOutputs as $txOut) {
                                        $outAddr = $txOut['script_public_key_address'] ?? '';
                                        $outKas = (int)($txOut['amount'] ?? 0) / 100000000;
                                        if (strcasecmp($outAddr, $segWalletAddr) === 0) {
                                            if ($outKas >= $expectedKas * 0.95) {
                                                $foundValid = true;
                                            } else {
                                                $errors['segment_payment'] = 'Insufficient payment: ' . number_format($outKas, 4) . ' KAS received, need at least ' . number_format($expectedKas * 0.95, 4) . ' KAS.';
                                            }
                                            break;
                                        }
                                    }
                                    if (!$foundValid && !isset($errors['segment_payment'])) {
                                        $errors['segment_payment'] = 'Transaction does not include payment to the segment driver\'s wallet.';
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Check that Kaspa segment payments MUST have verified transaction
            if (!$errors) {
                $selectedMethodCode = '';
                foreach ($paymentMethods as $pm) {
                    if ((int)$pm['PaymentMethodTypeID'] === $methodId) {
                        $selectedMethodCode = strtoupper($pm['Code'] ?? '');
                        break;
                    }
                }
                if ($selectedMethodCode === 'KASPA' && !str_starts_with($providerRef, 'KASPA:')) {
                    $errors['segment_payment'] = 'Kaspa payment requires a verified blockchain transaction.';
                }
            }

            if (!$errors) {
                $amount = (float)$amountRaw;

                $stmt = db_call_procedure('dbo.spCreatePaymentForSegment', [
                    $segmentId,
                    $methodId,
                    $amount,
                    $currency !== '' ? $currency : 'EUR',
                    $providerRef !== '' ? $providerRef : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not create segment payment. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Payment for segment created successfully.');
                    redirect('passenger/ride_detail.php?trip_id=' . urlencode((string)$tripId));
                }
            }
        } elseif ($action === 'complete_segment_payment') {
            // Handle segment payment completion
            $paymentId = (int)array_get($_POST, 'payment_id', 0);
            $providerRef = trim((string)array_get($_POST, 'provider_reference', ''));

            if ($paymentId <= 0) {
                $errors['segment_payment'] = 'Invalid payment.';
            }

            if (!$errors) {
                $stmt = db_call_procedure('dbo.spCompleteSegmentPayment', [
                    $paymentId,
                    $providerRef !== '' ? $providerRef : null,
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not complete segment payment. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    try {
                        $segmentPayment = get_payment_details($paymentId);
                        $receiptAmount = $segmentPayment['TotalAmount'] ?? $segmentPayment['Amount'] ?? null;
                        $receiptCurrency = $segmentPayment['CurrencyCode'] ?? 'EUR';
                        $receiptMethod = $segmentPayment['PaymentMethodName'] ?? ($segmentPayment['PaymentMethod'] ?? null);
                        $receiptReference = $providerRef !== '' ? $providerRef : ($segmentPayment['ProviderReference'] ?? null);
                        $receiptWhen = $segmentPayment['CompletedAt'] ?? ($segmentPayment['UpdatedAt'] ?? new DateTime('now', new DateTimeZone('Europe/Nicosia')));

                        osrh_send_payment_receipt([
                            'email'    => current_user_email(),
                            'name'     => current_user_name() ?? ($trip['PassengerName'] ?? 'Passenger'),
                            'subject'  => 'Your payment receipt',
                            'amount'   => $receiptAmount,
                            'currency' => $receiptCurrency,
                            'method'   => $receiptMethod,
                            'reference'=> $receiptReference,
                            'when'     => $receiptWhen,
                            'context'  => array_filter([
                                isset($currentSegment['SegmentOrder']) ? '- Segment order: ' . $currentSegment['SegmentOrder'] : null,
                                (isset($trip['PickupAddress']) || isset($trip['DropoffAddress']))
                                    ? '- Route: ' . ($trip['PickupAddress'] ?? 'Pickup') . ' -> ' . ($trip['DropoffAddress'] ?? 'Dropoff')
                                    : null,
                            ]),
                        ]);
                    } catch (Throwable $mailError) {
                        error_log('Segment payment receipt email failed: ' . $mailError->getMessage());
                    }
                    flash_add('success', 'Segment payment has been marked as completed.');
                    redirect('passenger/ride_detail.php?trip_id=' . urlencode((string)$tripId));
                }
            }
        }
    }
}

$pageTitle = 'Trip Details';
$isRealDriverTrip = !empty($trip['IsRealDriverTrip']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1040px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                🚗 Trip Details
                <?php if ($isRealDriverTrip): ?>
                <span style="font-size: 0.7rem; color: #22c55e; padding: 0.15rem 0.4rem; background: rgba(34, 197, 94, 0.1); border-radius: 3px; margin-left: 0.3rem;">
                    ⭐ Real Driver
                </span>
                <?php endif; ?>
            </h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                <?php if ($isRealDriverTrip): ?>
                This is a real driver trip. Contact your driver directly for updates.
                <?php else: ?>
                Detailed information about this trip, payment, and rating.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php 
    // Segment trip indicator
    $isSegmentTrip = !empty($trip['IsSegmentTrip']);
    $segmentOrder = $trip['SegmentOrder'] ?? null;
    $totalSegments = $trip['TotalSegments'] ?? null;
    $segmentGeofenceName = $trip['SegmentGeofenceName'] ?? null;
    ?>
    
    <?php if ($isSegmentTrip): ?>
    <!-- Segment Trip Banner -->
    <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; padding: 1rem 1.2rem; border-radius: 10px; margin-bottom: 1rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.5rem;">🔀</span>
                <div>
                    <div style="font-weight: 600; font-size: 1rem;">Multi-Segment Journey</div>
                    <div style="font-size: 0.8rem; opacity: 0.9;">Segment <?php echo e($segmentOrder); ?> of <?php echo e($totalSegments); ?><?php if ($segmentGeofenceName): ?> · <?php echo e($segmentGeofenceName); ?><?php endif; ?></div>
                </div>
            </div>
            <div style="background: rgba(255,255,255,0.2); padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.85rem;">
                <?php 
                $segmentProgress = ($segmentOrder / $totalSegments) * 100;
                echo round($segmentProgress) . '% of journey';
                ?>
            </div>
        </div>
        <?php if ($segmentOrder < $totalSegments): ?>
        <div style="font-size: 0.78rem; margin-top: 0.6rem; opacity: 0.85; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 0.5rem;">
            ℹ️ This driver will drop you at the geofence boundary. A new driver from the next zone will continue your journey.
        </div>
        <?php else: ?>
        <div style="font-size: 0.78rem; margin-top: 0.6rem; opacity: 0.85; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 0.5rem;">
            🎯 Final segment! This driver will take you to your destination.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
            <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 1.4rem; margin-top: 0.8rem;">

        <div>
            <h3 style="font-size: 0.95rem; margin-bottom: 0.4rem;">Trip summary</h3>
            <div class="card" style="padding: 0.9rem 1rem;">
                <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.4rem;">
                    Driver: <strong><?php echo e($trip['DriverName']); ?></strong>
                    <?php if (!empty($trip['DriverUserID'])): ?>
                    <a href="<?php echo e(url('passenger/messages.php?user_id=' . (int)$trip['DriverUserID'])); ?>" 
                       class="btn btn-primary btn-small" 
                       style="display: inline-flex; align-items: center; gap: 0.3rem; margin-left: 0.5rem; padding: 0.2rem 0.6rem; font-size: 0.75rem;">
                        💬 Message
                    </a>
                    <?php endif; ?>
                    <br>
                    Vehicle: <strong><?php echo e($trip['VehicleType'] . ' · ' . $trip['PlateNumber']); ?></strong>
                </p>

                <table class="table" style="font-size: 0.82rem; margin-top: 0.3rem;">
                    <tbody>
                        <tr>
                            <th style="width: 30%;">Requested at</th>
                            <td><?php echo e(osrh_format_dt_detail($trip['RequestedAt'] ?? null)); ?></td>
                        </tr>
                        <tr>
                            <th>Dispatch time</th>
                            <td><?php echo e(osrh_format_dt_detail($trip['DispatchTime'] ?? null)); ?></td>
                        </tr>
                        <tr>
                            <th>Start</th>
                            <td><?php echo e(osrh_format_dt_detail($trip['StartTime'] ?? null)); ?></td>
                        </tr>
                        <tr>
                            <th>End</th>
                            <td><?php echo e(osrh_format_dt_detail($trip['EndTime'] ?? null)); ?></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td><?php echo e($trip['TripStatus']); ?></td>
                        </tr>
                        <tr>
                            <th>Distance (km)</th>
                            <td>
                                <?php
                                if (isset($trip['TotalDistanceKm']) && $trip['TotalDistanceKm'] !== null) {
                                    $dist = is_numeric($trip['TotalDistanceKm']) ? number_format((float)$trip['TotalDistanceKm'], 2) : $trip['TotalDistanceKm'];
                                    echo e($dist);
                                } else {
                                    echo '<span class="text-muted">N/A</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Duration (min)</th>
                            <td>
                                <?php
                                if (isset($trip['TotalDurationSec']) && $trip['TotalDurationSec'] !== null) {
                                    $mins = (int)round(((int)$trip['TotalDurationSec']) / 60);
                                    echo e((string)$mins);
                                } else {
                                    echo '<span class="text-muted">N/A</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Luggage</th>
                            <td>
                                <?php
                                if (isset($trip['LuggageVolume']) && $trip['LuggageVolume'] !== null) {
                                    $vol = is_numeric($trip['LuggageVolume']) ? number_format((float)$trip['LuggageVolume'], 2) : $trip['LuggageVolume'];
                                    echo e($vol . ' m³');
                                } else {
                                    echo '<span class="text-muted">None specified</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 0.7rem;">
                    <h3 style="font-size: 0.9rem; margin-bottom: 0.35rem;">Notes</h3>
                    <p class="text-muted" style="font-size: 0.84rem;">
                        <?php
                        if (!empty($trip['PassengerNotes'])) {
                            echo e($trip['PassengerNotes']);
                        } else {
                            echo '<span class="text-muted">No notes provided.</span>';
                        }
                        ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($segments) && $isSegmentTrip): ?>
            <h3 style="font-size: 0.95rem; margin-top: 1.4rem; margin-bottom: 0.4rem;">🚗 Segment Payment</h3>
            <div class="card" style="padding: 0.9rem 1rem; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2);">
                <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.8rem;">
                    This is <strong>segment <?php echo e($currentSegment['SegmentOrder']); ?> of <?php echo $totalSegmentCount; ?></strong> in your multi-vehicle journey.
                    Pay for this segment below.
                </p>
                
                <?php if (!empty($errors['segment_payment'])): ?>
                    <div class="flash flash-error" style="margin-bottom: 0.75rem;">
                        <span class="flash-text"><?php echo e($errors['segment_payment']); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php 
                // Only show current segment fare
                $segmentFare = (float)($currentSegment['EstimatedFare'] ?? 0);
                $isPaid = strtolower($currentSegment['PaymentStatus'] ?? '') === 'completed';
                ?>
                
                <!-- Segment Fare -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #1e293b; border-radius: 6px; margin-bottom: 1rem;">
                    <div>
                        <div style="font-size: 0.75rem; color: #94a3b8;">Segment <?php echo e($currentSegment['SegmentOrder']); ?> Fare</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: #e5e7eb;">€<?php echo number_format($segmentFare, 2); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Status</div>
                        <div style="font-size: 1rem; font-weight: 700; color: <?php echo $isPaid ? '#22c55e' : '#f59e0b'; ?>;">
                            <?php echo $isPaid ? '✓ Paid' : 'Awaiting Payment'; ?>
                        </div>
                    </div>
                </div>
                
                <?php 
                // Use currentSegment directly - no loop needed
                $segment = $currentSegment;
                $segmentId = (int)($segment['SegmentID'] ?? 0);
                $segmentFare = (float)($segment['EstimatedFare'] ?? 0);
                $segmentDistance = (float)($segment['EstimatedDistanceKm'] ?? 0);
                $segmentDuration = (int)($segment['EstimatedDurationMin'] ?? 0);
                $hasPayment = !empty($segment['PaymentID']);
                $paymentStatus = strtolower($segment['PaymentStatus'] ?? '');
                $isPaymentCompleted = $paymentStatus === 'completed';
                ?>
                <div style="margin-bottom: 1rem; padding: 0.9rem; background: #0b1120; border: 1px solid <?php echo $isPaymentCompleted ? '#22c55e' : '#1e293b'; ?>; border-radius: 8px;">
                    <!-- Route Info -->
                    <div style="font-size: 0.8rem; color: #e5e7eb; margin-bottom: 0.5rem; padding: 0.5rem; background: rgba(255,255,255,0.03); border-radius: 4px;">
                        <div style="margin-bottom: 0.3rem;">
                            <span style="color: #22c55e;">●</span> 
                            <?php echo e($segment['FromLocationName'] ?? 'N/A'); ?>
                            <?php if (!empty($segment['FromBridgeName'])): ?>
                            <span style="color: #3b82f6; font-size: 0.7rem;"> (Bridge: <?php echo e($segment['FromBridgeName']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span style="color: #ef4444;">●</span> 
                            <?php echo e($segment['ToLocationName'] ?? 'N/A'); ?>
                            <?php if (!empty($segment['ToBridgeName'])): ?>
                            <span style="color: #3b82f6; font-size: 0.7rem;"> (Bridge: <?php echo e($segment['ToBridgeName']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($segmentDistance > 0 || $segmentDuration > 0): ?>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.3rem;">
                            <?php if (!empty($segment['GeofenceName'])): ?>
                            <span style="margin-right: 0.5rem;">📍 <?php echo e($segment['GeofenceName']); ?></span>
                            <?php endif; ?>
                            <?php if ($segmentDistance > 0): ?>~<?php echo number_format($segmentDistance, 1); ?> km<?php endif; ?>
                            <?php if ($segmentDistance > 0 && $segmentDuration > 0): ?> · <?php endif; ?>
                            <?php if ($segmentDuration > 0): ?>~<?php echo $segmentDuration; ?> min<?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Payment Section for this segment -->
                    <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #1e293b;">
                        <?php if ($isPaymentCompleted): ?>
                            <!-- Payment completed -->
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="color: #22c55e; font-size: 1rem;">✓</span>
                                    <div>
                                        <div style="font-size: 0.85rem; color: #22c55e; font-weight: 600;">Payment Completed</div>
                                        <div style="font-size: 0.75rem; color: #94a3b8;">
                                            €<?php echo number_format((float)($segment['PaymentAmount'] ?? 0), 2); ?>
                                            via <?php echo e($segment['PaymentMethodName'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($segment['PaymentDriverEarnings'])): ?>
                                <div style="text-align: right; font-size: 0.75rem; color: #94a3b8;">
                                    Driver receives: €<?php echo number_format((float)$segment['PaymentDriverEarnings'], 2); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($hasPayment): ?>
                            <!-- Payment pending - show complete button -->
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <span class="badge badge-warning" style="font-size: 0.75rem;">Payment Pending</span>
                                <span style="font-size: 0.85rem; color: #e5e7eb;">€<?php echo number_format((float)($segment['PaymentAmount'] ?? 0), 2); ?></span>
                            </div>
                            <form method="post" style="display: flex; gap: 0.5rem; align-items: center;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="trip_id" value="<?php echo e($tripId); ?>">
                                <input type="hidden" name="action" value="complete_segment_payment">
                                <input type="hidden" name="payment_id" value="<?php echo e($segment['PaymentID']); ?>">
                                <input type="text" name="provider_reference" class="form-control" placeholder="Transaction ref (optional)" style="font-size: 0.8rem; padding: 0.3rem 0.5rem; flex: 1;">
                                <button type="submit" class="btn btn-success btn-small" style="font-size: 0.8rem; padding: 0.3rem 0.75rem;">
                                    Mark as Paid
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- No payment yet - show payment form -->
                            <?php 
                            // Get driver's Kaspa wallet for segment payment
                            $segmentDriverId = $segment['DriverUserID'] ?? null;
                            $segmentDriverWallet = null;
                            if ($segmentDriverId) {
                                $segmentDriverWallet = kaspa_get_default_wallet((int)$segmentDriverId, 'receive');
                            }
                            $segmentKaspaAddress = $segmentDriverWallet['WalletAddress'] ?? '';
                            ?>
                            <form method="post" class="js-validate segment-payment-form" id="segmentPaymentForm_<?php echo e($segmentId); ?>" novalidate>
                                <?php csrf_field(); ?>
                                <input type="hidden" name="trip_id" value="<?php echo e($tripId); ?>">
                                <input type="hidden" name="action" value="create_segment_payment">
                                <input type="hidden" name="segment_id" value="<?php echo e($segmentId); ?>">
                                
                                <div id="segmentStandardPayment_<?php echo e($segmentId); ?>">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                        <div>
                                            <label style="font-size: 0.75rem; color: #94a3b8; display: block; margin-bottom: 0.2rem;">Payment Method</label>
                                            <select name="payment_method_type_id" class="form-control segment-method-select" 
                                                    data-segment-id="<?php echo e($segmentId); ?>"
                                                    data-kaspa-address="<?php echo e($segmentKaspaAddress); ?>"
                                                    required style="font-size: 0.8rem; padding: 0.35rem 0.5rem;"
                                                    onchange="toggleSegmentKaspa(this)">
                                                <option value="">Select...</option>
                                                <?php foreach ($paymentMethods as $pm): 
                                                    // Skip CARD - only allow KASPA and CASH
                                                    $pmCode = strtoupper($pm['Code'] ?? '');
                                                    if ($pmCode === 'CARD') continue;
                                                    $isKaspa = ($pmCode === 'KASPA');
                                                ?>
                                                    <option value="<?php echo e($pm['PaymentMethodTypeID']); ?>" 
                                                            <?php if ($isKaspa): ?>data-kaspa="true"<?php endif; ?>>
                                                        <?php echo e($pm['Code']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size: 0.75rem; color: #94a3b8; display: block; margin-bottom: 0.2rem;">Amount (€)</label>
                                            <input type="text" name="amount" class="form-control segment-amount-input" 
                                                   id="segmentAmount_<?php echo e($segmentId); ?>"
                                                   required 
                                                   value="<?php echo number_format($segmentFare, 2, '.', ''); ?>" 
                                                   style="font-size: 0.8rem; padding: 0.35rem 0.5rem; background: rgba(34, 197, 94, 0.1); border-color: #22c55e;">
                                        </div>
                                    </div>
                                    <input type="hidden" name="currency_code" value="EUR">
                                    <div id="segmentCashPayment_<?php echo e($segmentId); ?>" style="display: flex; gap: 0.5rem; align-items: center;">
                                        <input type="text" name="provider_reference" class="form-control" placeholder="Transaction ref (optional)" style="font-size: 0.8rem; padding: 0.35rem 0.5rem; flex: 1;">
                                        <button type="submit" class="btn btn-primary btn-small" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                                            Pay Segment
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Kaspa Payment Section for Segment -->
                                <div id="segmentKaspaPayment_<?php echo e($segmentId); ?>" style="display: none;">
                                    <?php if ($segmentKaspaAddress): ?>
                                    <div style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1), rgba(34, 197, 94, 0.1)); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 0.75rem; margin-top: 0.5rem;">
                                        <div style="text-align: center; margin-bottom: 0.75rem;">
                                            <span style="color: #49eacb; font-weight: 600; font-size: 0.85rem;">Pay Segment with Kaspa</span>
                                            <p style="font-size: 0.7rem; color: #9ca3af; margin: 0.25rem 0 0;">0% platform fees</p>
                                        </div>
                                        
                                        <div style="background: rgba(0,0,0,0.2); border-radius: 6px; padding: 0.5rem; margin-bottom: 0.5rem;">
                                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem;">
                                                <span style="color: #9ca3af;">Amount:</span>
                                                <span id="segmentKasAmount_<?php echo e($segmentId); ?>" style="color: #49eacb; font-weight: 600;">-- KAS</span>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; font-size: 0.7rem; margin-top: 0.2rem;">
                                                <span style="color: #6b7280;">Rate:</span>
                                                <span id="segmentKasRate_<?php echo e($segmentId); ?>" style="color: #6b7280;">Loading...</span>
                                            </div>
                                        </div>
                                        
                                        <div id="segmentQrCode_<?php echo e($segmentId); ?>" style="text-align: center; margin: 0.5rem 0; background: white; padding: 0.5rem; border-radius: 6px; display: inline-block; width: 100%;"></div>
                                        
                                        <div style="background: rgba(0,0,0,0.2); border-radius: 4px; padding: 0.4rem; margin-bottom: 0.5rem;">
                                            <label style="font-size: 0.65rem; color: #6b7280;">Driver's Address:</label>
                                            <code id="segmentDriverAddress_<?php echo e($segmentId); ?>" style="font-size: 0.55rem; color: #49eacb; word-break: break-all; display: block;"><?php echo e($segmentKaspaAddress); ?></code>
                                        </div>
                                        
                                        <div style="display: flex; gap: 0.4rem;">
                                            <button type="button" onclick="paySegmentWithKaspa(<?php echo e($segmentId); ?>)" class="btn btn-primary btn-small" style="flex: 1; background: linear-gradient(135deg, #49eacb, #22c55e); border: none; font-size: 0.75rem; padding: 0.4rem;">
                                                💳 Pay with Wallet
                                            </button>
                                            <button type="button" onclick="confirmSegmentKaspaPayment(<?php echo e($segmentId); ?>)" class="btn btn-secondary btn-small" style="flex: 1; font-size: 0.75rem; padding: 0.4rem;">
                                                ✓ I've Sent
                                            </button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div style="background: rgba(251, 191, 36, 0.15); border: 1px solid rgba(251, 191, 36, 0.4); border-radius: 6px; padding: 0.75rem; margin-top: 0.5rem; text-align: center;">
                                        <span style="font-size: 1rem;">⚠️</span>
                                        <p style="margin: 0.3rem 0 0; font-size: 0.75rem; color: #fbbf24;">
                                            This driver hasn't set up their Kaspa wallet.
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isSegmentTrip): ?>
            <!-- Regular Payment Section (only shown for non-segment trips) -->
            <h3 style="font-size: 0.95rem; margin-top: 1.4rem; margin-bottom: 0.4rem;">Payment</h3>
            <div class="card" style="padding: 0.9rem 1rem;">
                <?php if (!empty($errors['payment'])): ?>
                    <div class="form-error" style="margin-bottom: 0.45rem;"><?php echo e($errors['payment']); ?></div>
                <?php endif; ?>

                <?php if ($payment && isset($payment['Status'])): ?>
                    <?php
                    // Calculate or retrieve fare breakdown
                    $totalAmount = is_numeric($payment['Amount']) ? (float)$payment['Amount'] : 0;
                    $baseFare = isset($payment['BaseFare']) && $payment['BaseFare'] !== null ? (float)$payment['BaseFare'] : null;
                    $distanceFare = isset($payment['DistanceFare']) && $payment['DistanceFare'] !== null ? (float)$payment['DistanceFare'] : null;
                    $timeFare = isset($payment['TimeFare']) && $payment['TimeFare'] !== null ? (float)$payment['TimeFare'] : null;
                    $surgeMultiplier = isset($payment['SurgeMultiplier']) && $payment['SurgeMultiplier'] !== null ? (float)$payment['SurgeMultiplier'] : 1.0;
                    $serviceRate = 0.00;
                    $serviceFee = 0.00;
                    $driverEarnings = $totalAmount;
                    $currency = $payment['CurrencyCode'] ?? 'EUR';
                    $paymentStatus = strtolower($payment['Status'] ?? 'pending');
                    $isPaymentCompleted = in_array($paymentStatus, ['completed', 'complete']);
                    $isTripDone = strtolower($trip['Status'] ?? '') === 'completed';
                    ?>
                    
                    <?php if ($isTripDone && !$isPaymentCompleted): ?>
                    <!-- Trip completed but payment pending - prominent notice -->
                    <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="font-size: 1.2rem;">💳</span>
                            <span style="font-weight: 600; color: #f59e0b;">Payment Required</span>
                        </div>
                        <p style="font-size: 0.85rem; color: #fbbf24; margin: 0;">
                            Your trip is complete! Please confirm your payment below to finalize the ride.
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Payment Status Badge -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <span class="badge <?php echo $isPaymentCompleted ? 'badge-success' : 'badge-warning'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                            <?php echo e(ucfirst($payment['Status'])); ?>
                        </span>
                        <span style="font-size: 1.25rem; font-weight: 700; color: #e5e7eb;">
                            <?php echo format_currency($totalAmount, $currency); ?>
                        </span>
                    </div>

                    <!-- Fare Breakdown -->
                    <div style="background: #0b1120; border: 1px solid #1e293b; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <h4 style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #94a3b8;">Fare Breakdown</h4>
                        <table style="width: 100%; font-size: 0.82rem;">
                            <?php if ($baseFare !== null): ?>
                            <tr>
                                <td style="padding: 0.2rem 0;">Base Fare</td>
                                <td style="text-align: right;"><?php echo format_currency($baseFare, $currency); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($distanceFare !== null): ?>
                            <tr>
                                <td style="padding: 0.2rem 0;">Distance Charge</td>
                                <td style="text-align: right;"><?php echo format_currency($distanceFare, $currency); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($timeFare !== null): ?>
                            <tr>
                                <td style="padding: 0.2rem 0;">Time Charge</td>
                                <td style="text-align: right;"><?php echo format_currency($timeFare, $currency); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($surgeMultiplier > 1.0): ?>
                            <tr>
                                <td style="padding: 0.2rem 0; color: #f59e0b;">Surge (<?php echo number_format($surgeMultiplier, 1); ?>x)</td>
                                <td style="text-align: right; color: #f59e0b;">Applied</td>
                            </tr>
                            <?php endif; ?>
                            <tr style="border-top: 1px solid #1e293b;">
                                <td style="padding: 0.4rem 0; font-weight: 600; color: #e5e7eb;">Total</td>
                                <td style="text-align: right; font-weight: 600; color: #e5e7eb;"><?php echo format_currency($totalAmount, $currency); ?></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Payment Method -->
                    <?php if (!empty($payment['PaymentMethodName'])): ?>
                    <p class="text-muted" style="font-size: 0.82rem; margin-bottom: 0.5rem;">
                        <strong>Payment Method:</strong> <?php echo e($payment['PaymentMethodName'] ?? 'N/A'); ?>
                    </p>
                    <?php endif; ?>

                    <?php if (!$isPaymentCompleted): ?>
                        <form method="post" class="js-validate" novalidate>
                            <?php csrf_field(); ?>
                            <input type="hidden" name="trip_id" value="<?php echo e($tripId); ?>">
                            <input type="hidden" name="action" value="complete_payment">

                            <div class="form-group">
                                <label class="form-label" for="provider_reference">Provider reference (optional)</label>
                                <input
                                    type="text"
                                    id="provider_reference"
                                    name="provider_reference"
                                    class="form-control"
                                    value=""
                                    placeholder="e.g. transaction id"
                                >
                            </div>

                            <div class="form-group" style="margin-top: 0.7rem;">
                                <button type="submit" class="btn btn-primary btn-small">
                                    Mark as completed
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; border-radius: 6px; padding: 0.75rem; margin-top: 0.5rem;">
                            <span style="color: #22c55e; font-size: 0.85rem;">✓ Payment completed successfully</span>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    // Get estimated fare from ride request (set during booking)
                    $estimatedFare = isset($trip['EstimatedFare']) && $trip['EstimatedFare'] !== null 
                        ? number_format((float)$trip['EstimatedFare'], 2, '.', '') 
                        : '';
                    ?>
                    <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.5rem;">
                        No payment record found for this trip. 
                        <?php if ($estimatedFare): ?>
                        The estimated fare from your booking is <strong>€<?php echo e($estimatedFare); ?></strong>.
                        <?php else: ?>
                        Create one below.
                        <?php endif; ?>
                    </p>

                    <form method="post" class="js-validate" id="paymentForm" novalidate>
                        <?php csrf_field(); ?>
                        <input type="hidden" name="trip_id" value="<?php echo e($tripId); ?>">
                        <input type="hidden" name="action" value="create_payment">

                        <div class="form-group">
                            <label class="form-label" for="payment_method_type_id">Payment method</label>
                            <select
                                id="payment_method_type_id"
                                name="payment_method_type_id"
                                class="form-control"
                                data-required="1"
                                onchange="toggleKaspaPayment(this.value)"
                            >
                                <option value="">Select method...</option>
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <?php 
                                    // Skip CARD payment method - only allow KASPA and CASH
                                    $code = strtoupper($pm['Code'] ?? '');
                                    if ($code === 'CARD') continue;
                                    
                                    $isKaspa = ($code === 'KASPA');
                                    $pmId = $pm['PaymentMethodTypeID'];
                                    ?>
                                    <option value="<?php echo e($pmId); ?>" <?php if ($isKaspa): ?>data-kaspa="true" style="background: linear-gradient(90deg, rgba(73, 234, 203, 0.2), rgba(34, 197, 94, 0.1));"<?php endif; ?>>
                                        <?php echo e($pm['Code']); ?><?php if (!empty($pm['Description'])): ?> – <?php echo e($pm['Description']); ?><?php endif; ?>
                                        <?php if ($isKaspa): ?> (0% fees)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Standard payment fields (hidden when Kaspa selected) -->
                        <div id="standardPaymentFields">
                            <div class="form-group">
                                <label class="form-label" for="amount">Amount (€)</label>
                                <input
                                    type="text"
                                    id="amount"
                                    name="amount"
                                    class="form-control"
                                    data-required="1"
                                    placeholder="e.g. 12.50"
                                    value="<?php echo e($estimatedFare); ?>"
                                    <?php if ($estimatedFare): ?>style="background: rgba(34, 197, 94, 0.1); border-color: #22c55e;"<?php endif; ?>
                                >
                                <?php if ($estimatedFare): ?>
                                <small style="color: #22c55e; font-size: 0.75rem;">✓ Pre-filled with your estimated fare from booking</small>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="currency_code">Currency</label>
                                <input
                                    type="text"
                                    id="currency_code"
                                    name="currency_code"
                                    class="form-control"
                                    value="EUR"
                                >
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="provider_reference_new">Provider reference (optional)</label>
                                <input
                                    type="text"
                                    id="provider_reference_new"
                                    name="provider_reference"
                                    class="form-control"
                                    value=""
                                    placeholder="e.g. bank code / transaction id"
                                >
                            </div>

                            <div class="form-group" style="margin-top: 0.7rem;">
                                <button type="submit" class="btn btn-primary btn-small">
                                    Create payment
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Kaspa Payment Section (shown when Kaspa selected) -->
                    <?php 
                    // Get driver's Kaspa wallet for receiving payments
                    $driverUserIdForPayment = $trip['DriverUserID'] ?? null;
                    $driverKaspaWallet = null;
                    if ($driverUserIdForPayment) {
                        $driverKaspaWallet = kaspa_get_default_wallet((int)$driverUserIdForPayment, 'receive');
                    }
                    ?>
                    <div id="kaspaPaymentSection" style="display: none;">
                        <?php if ($driverKaspaWallet && !empty($driverKaspaWallet['WalletAddress'])): ?>
                        <div style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1), rgba(34, 197, 94, 0.1)); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1rem; margin-top: 0.5rem;">
                            <div style="text-align: center; margin-bottom: 1rem;">
                                <span style="font-size: 2rem;">💎</span>
                                <h4 style="margin: 0.5rem 0 0.25rem; color: #49eacb; font-size: 1rem;">Pay with Kaspa</h4>
                                <p style="font-size: 0.8rem; color: #9ca3af; margin: 0;">Direct peer-to-peer • 0% platform fees</p>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Amount (EUR)</label>
                                <input type="text" id="kaspa_amount_eur" class="form-control" value="<?php echo e($estimatedFare); ?>" placeholder="Enter amount in EUR" style="text-align: center; font-weight: 600;">
                            </div>

                            <div style="background: rgba(0,0,0,0.2); border-radius: 6px; padding: 0.75rem; margin-bottom: 0.75rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                                    <span style="color: #9ca3af;">Amount in KAS:</span>
                                    <span id="kaspa_amount_kas" style="color: #49eacb; font-weight: 600;">-- KAS</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; margin-top: 0.3rem;">
                                    <span style="color: #6b7280;">Exchange rate:</span>
                                    <span id="kaspa_exchange_rate" style="color: #6b7280;">Loading...</span>
                                </div>
                            </div>

                            <div id="kaspaQrContainer" style="text-align: center; margin: 1rem 0;">
                                <div id="kaspaQrCode" style="background: white; padding: 1rem; border-radius: 8px; display: inline-block;"></div>
                            </div>

                            <div style="background: rgba(0,0,0,0.2); border-radius: 6px; padding: 0.75rem; margin-bottom: 0.75rem;">
                                <label style="font-size: 0.75rem; color: #6b7280; display: block; margin-bottom: 0.3rem;">Driver's Kaspa Address:</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <code id="driverKaspaAddress" style="flex: 1; font-size: 0.65rem; color: #49eacb; word-break: break-all; background: rgba(0,0,0,0.3); padding: 0.4rem; border-radius: 4px;"><?php echo e($driverKaspaWallet['WalletAddress']); ?></code>
                                    <button type="button" onclick="copyKaspaAddress()" class="btn btn-ghost btn-small" style="padding: 0.3rem 0.5rem; font-size: 0.75rem;">📋</button>
                                </div>
                            </div>

                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <button type="button" onclick="payWithKaspaWallet()" class="btn btn-primary" style="flex: 1; background: linear-gradient(135deg, #49eacb, #22c55e); border: none;">
                                    💳 Pay with Wallet
                                </button>
                                <button type="button" onclick="confirmKaspaPayment()" class="btn btn-secondary" style="flex: 1;">
                                    🔍 Detect Payment
                                </button>
                            </div>

                            <p style="font-size: 0.7rem; color: #6b7280; text-align: center; margin-top: 0.75rem;">
                                Scan QR with your Kaspa wallet or click "Pay with Wallet" — payment is detected automatically
                            </p>
                        </div>
                        <?php else: ?>
                        <div style="background: rgba(251, 191, 36, 0.15); border: 1px solid rgba(251, 191, 36, 0.4); border-radius: 8px; padding: 1rem; margin-top: 0.5rem; text-align: center;">
                            <span style="font-size: 1.5rem;">⚠️</span>
                            <p style="margin: 0.5rem 0 0; font-size: 0.85rem; color: #fbbf24;">
                                This driver hasn't set up their Kaspa wallet yet.
                            </p>
                            <p style="margin: 0.25rem 0 0; font-size: 0.75rem; color: #9ca3af;">
                                Please choose another payment method or contact the driver.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- End of Regular Payment Section -->
        </div>

        <div>
            <h3 style="font-size: 0.95rem; margin-bottom: 0.4rem;">
                <?php 
                $tripStatus = strtolower($trip['TripStatus'] ?? '');
                $isDriverEnRoute = ($tripStatus === 'assigned');
                $isTripInProgress = ($tripStatus === 'in_progress');
                $isTripCompleted = ($tripStatus === 'completed');
                $isTripCancelled = ($tripStatus === 'cancelled');
                // Enable live tracking for real driver trips too - they send GPS updates
                $isLiveTracking = in_array($tripStatus, ['assigned', 'in_progress']);
                
                if ($isTripCompleted) {
                    echo '✅ Trip Completed';
                } elseif ($isTripCancelled) {
                    echo '❌ Trip Cancelled';
                } elseif ($isRealDriverTrip && $isLiveTracking) {
                    echo '📍 Real Driver - Live Tracking';
                } elseif ($isRealDriverTrip) {
                    echo '⭐ Real Driver Trip';
                } elseif ($isTripInProgress) {
                    echo '🚗 Trip In Progress';
                } elseif ($isDriverEnRoute) {
                    echo '🚗 Driver En Route';
                } else {
                    echo 'Route';
                }
                ?>
            </h3>
            
            <?php if ($isTripCompleted): ?>
            <!-- Trip Completed Message -->
            <?php if ($isSegmentTrip && $nextSegment): ?>
            <!-- Segment completed - show next segment info -->
            <div class="card" style="padding: 1.2rem; margin-bottom: 0.5rem; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.4);">
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 0.5rem;">🔄</div>
                    <div style="font-weight: 600; color: #3b82f6; font-size: 1.1rem; margin-bottom: 0.5rem;">
                        Segment <?php echo e($currentSegment['SegmentOrder'] ?? ''); ?> of <?php echo e($totalSegmentCount); ?> Completed!
                    </div>
                    <div style="font-size: 0.85rem; color: #9ca3af; margin-bottom: 1rem;">
                        <?php if ($nextSegmentTripId): ?>
                        Your next driver is ready. Continue to the next segment of your journey.
                        <?php else: ?>
                        Waiting for a driver to be assigned to the next segment...
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                        <?php if ($nextSegmentTripId): ?>
                        <a href="<?php echo e(url('passenger/ride_detail.php?trip_id=' . $nextSegmentTripId)); ?>" class="btn btn-primary">
                            🚗 Continue to Next Segment
                        </a>
                        <?php else: ?>
                        <button class="btn btn-secondary" disabled>
                            ⏳ Waiting for Next Driver...
                        </button>
                        <script>
                            // Auto-refresh to check for next segment assignment
                            setTimeout(function() { location.reload(); }, 5000);
                        </script>
                        <?php endif; ?>
                        <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost btn-small">
                            View All Rides
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Segment Progress -->
            <div class="card" style="padding: 0.9rem 1rem; margin-top: 0.5rem;">
                <h4 style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #94a3b8;">Journey Progress</h4>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <?php for ($i = 1; $i <= $totalSegmentCount; $i++): ?>
                        <?php 
                        $segStatus = 'pending';
                        foreach ($allSegments as $seg) {
                            if ((int)($seg['SegmentOrder'] ?? 0) === $i) {
                                if (!empty($seg['TripID'])) {
                                    $segTripStatus = strtolower($seg['TripStatus'] ?? '');
                                    if ($segTripStatus === 'completed') {
                                        $segStatus = 'completed';
                                    } elseif (in_array($segTripStatus, ['assigned', 'dispatched', 'in_progress'])) {
                                        $segStatus = 'active';
                                    }
                                }
                                break;
                            }
                        }
                        $bgColor = $segStatus === 'completed' ? '#22c55e' : ($segStatus === 'active' ? '#3b82f6' : '#4b5563');
                        ?>
                        <div style="flex: 1; height: 6px; background: <?php echo $bgColor; ?>; border-radius: 3px;"></div>
                    <?php endfor; ?>
                </div>
                <div style="font-size: 0.8rem; color: #6b7280; text-align: center;">
                    Segment <?php echo e($currentSegment['SegmentOrder'] ?? ''); ?> of <?php echo e($totalSegmentCount); ?> completed
                </div>
            </div>
            <?php else: ?>
            <!-- Final segment or non-segment trip completed -->
            <div class="card" style="padding: 1.2rem; margin-bottom: 0.5rem; background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.4);">
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 0.5rem;">🎉</div>
                    <div style="font-weight: 600; color: #22c55e; font-size: 1.1rem; margin-bottom: 0.5rem;">
                        <?php if ($isSegmentTrip): ?>
                        Journey Completed Successfully!
                        <?php else: ?>
                        Trip Completed Successfully!
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #9ca3af; margin-bottom: 1rem;">
                        Thank you for riding with us. We hope you had a great experience!
                    </div>
                    <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                        <a href="<?php echo e(url('passenger/request_ride.php')); ?>" class="btn btn-primary btn-small">
                            Book Another Ride
                        </a>
                        <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost btn-small">
                            View History
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Trip Summary for Completed Trip -->
            <div class="card" style="padding: 0.9rem 1rem; margin-top: 0.5rem;">
                <h4 style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #94a3b8;">Trip Details</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.82rem;">
                    <div>
                        <span style="color: #6b7280;">From:</span><br>
                        <strong><?php echo e($trip['PickupLocation'] ?? 'Pickup'); ?></strong>
                    </div>
                    <div>
                        <span style="color: #6b7280;">To:</span><br>
                        <strong><?php echo e($trip['DropoffLocation'] ?? 'Dropoff'); ?></strong>
                    </div>
                </div>
                <?php if (!empty($trip['TotalDistanceKm']) || !empty($trip['TotalDurationSec'])): ?>
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #1e293b; display: flex; gap: 1.5rem; font-size: 0.82rem;">
                    <?php if (!empty($trip['TotalDistanceKm'])): ?>
                    <div>
                        <span style="color: #6b7280;">Distance:</span>
                        <strong><?php echo number_format((float)$trip['TotalDistanceKm'], 2); ?> km</strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($trip['TotalDurationSec'])): ?>
                    <div>
                        <span style="color: #6b7280;">Duration:</span>
                        <strong><?php echo round((int)$trip['TotalDurationSec'] / 60); ?> min</strong>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php elseif ($isTripCancelled): ?>
            <!-- Trip Cancelled Message -->
            <div class="card" style="padding: 1.2rem; margin-bottom: 0.5rem; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4);">
                <div style="text-align: center;">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">❌</div>
                    <div style="font-weight: 600; color: #ef4444; font-size: 1.1rem; margin-bottom: 0.5rem;">
                        Trip Cancelled
                    </div>
                    <div style="font-size: 0.85rem; color: #9ca3af; margin-bottom: 1rem;">
                        This trip was cancelled. No charges were applied.
                    </div>
                    <a href="<?php echo e(url('passenger/request_ride.php')); ?>" class="btn btn-primary btn-small">
                        Book a New Ride
                    </a>
                </div>
            </div>
            <?php elseif ($isLiveTracking): ?>
            <!-- Live Tracking Status Panel (works for both simulated and real driver trips) -->
            <div id="tracking-status" class="card" style="padding: 0.75rem 1rem; margin-bottom: 0.5rem; background: <?php echo $isRealDriverTrip ? 'rgba(34, 197, 94, 0.1)' : 'rgba(59, 130, 246, 0.1)'; ?>; border: 1px solid <?php echo $isRealDriverTrip ? 'rgba(34, 197, 94, 0.3)' : 'rgba(59, 130, 246, 0.3)'; ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span id="tracking-indicator" style="display: inline-block; width: 10px; height: 10px; background: <?php echo $isRealDriverTrip ? '#f59e0b' : '#22c55e'; ?>; border-radius: 50%; margin-right: 8px; animation: pulse 1.5s infinite;"></span>
                        <span id="tracking-status-text" style="font-size: 0.85rem; color: #e5e7eb;">
                            <?php if ($isRealDriverTrip): ?>
                            📍 Waiting for driver GPS...
                            <?php elseif ($isTripInProgress): ?>
                            Trip in progress - heading to destination
                            <?php else: ?>
                            Driver is on the way
                            <?php endif; ?>
                        </span>
                    </div>
                    <div id="eta-display" style="font-size: 0.9rem; font-weight: 600; color: <?php echo $isRealDriverTrip ? '#22c55e' : '#3b82f6'; ?>;">
                        <?php echo $isRealDriverTrip ? 'Real Driver' : 'Calculating ETA...'; ?>
                    </div>
                </div>
                <?php if ($isRealDriverTrip && !empty($trip['DriverName'])): ?>
                <div style="font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
                    <strong>Driver:</strong> <?php echo e($trip['DriverName']); ?>
                    <?php if (!empty($trip['PlateNumber'])): ?>
                    &nbsp;•&nbsp; <strong>Vehicle:</strong> <?php echo e($trip['PlateNumber']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div id="progress-bar-container" style="margin-top: 0.5rem; background: #1e293b; border-radius: 4px; height: 6px; overflow: hidden;">
                    <div id="progress-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, <?php echo $isRealDriverTrip ? '#22c55e, #10b981' : '#3b82f6, #22c55e'; ?>); transition: width 0.5s ease;"></div>
                </div>
            </div>
            
            <!-- Pickup Speed Control (for driver en route phase - SIMULATED ONLY) -->
            <div id="pickup-speed-controls" class="card" style="padding: 0.6rem 1rem; margin-bottom: 0.5rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                    <div style="flex: 1;">
                        <label style="font-size: 0.75rem; color: #3b82f6; display: block; margin-bottom: 0.3rem;">
                            🚗 Pickup Speed: <span id="pickup-speed-value">1x</span>
                        </label>
                        <input type="range" id="pickup-speed-slider" min="1" max="50" value="1" step="1" 
                               style="width: 100%; cursor: pointer; accent-color: #3b82f6;">
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" id="pickup-speed-1x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">1x</button>
                        <button type="button" id="pickup-speed-5x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">5x</button>
                        <button type="button" id="pickup-speed-10x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">10x</button>
                        <button type="button" id="pickup-speed-25x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">25x</button>
                    </div>
                </div>
                <p style="font-size: 0.65rem; color: #94a3b8; margin: 0.3rem 0 0 0;">
                    🧪 Speed up driver arrival for testing. Driver is coming to pick you up.
                </p>
            </div>
            
            <!-- Simulation Speed Control (for testing) -->
            <div id="simulation-controls" class="card" style="padding: 0.6rem 1rem; margin-bottom: 0.5rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                    <div style="flex: 1;">
                        <label style="font-size: 0.75rem; color: #f59e0b; display: block; margin-bottom: 0.3rem;">
                            ⚡ Simulation Speed: <span id="speed-value">1x</span>
                        </label>
                        <input type="range" id="speed-slider" min="1" max="50" value="1" step="1" 
                               style="width: 100%; cursor: pointer; accent-color: #f59e0b;">
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" id="speed-1x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">1x</button>
                        <button type="button" id="speed-5x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">5x</button>
                        <button type="button" id="speed-10x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">10x</button>
                        <button type="button" id="speed-25x" class="btn btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151;">25x</button>
                    </div>
                </div>
                <p style="font-size: 0.65rem; color: #94a3b8; margin: 0.3rem 0 0 0;">
                    🧪 Simulation mode: Speed up time to test the trip tracking. Only affects this trip.
                </p>
            </div>
            
            <!-- Start Trip Button (shown when driver arrives) -->
            <div id="start-trip-panel" class="card" style="padding: 0.75rem 1rem; margin-bottom: 0.5rem; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); display: none;">
                <div style="text-align: center;">
                    <p style="font-size: 0.85rem; color: #22c55e; margin-bottom: 0.5rem;">
                        🎉 Driver has arrived at pickup location!
                    </p>
                    <button type="button" id="start-trip-btn" class="btn" style="background: #22c55e; color: white; padding: 0.5rem 1.5rem; font-size: 0.9rem; border-radius: 6px;">
                        🚗 Start Trip
                    </button>
                </div>
            </div>
            
            <style>
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.4; }
                }
                #speed-slider::-webkit-slider-thumb {
                    cursor: pointer;
                }
            </style>
            <?php endif; ?>
            
            <?php if (!$isTripCompleted && !$isTripCancelled): ?>
            <div id="trip-map" class="map-container"></div>
            <div id="route-info"></div>
            <?php endif; ?>

            <h3 style="font-size: 0.95rem; margin-top: 1.4rem; margin-bottom: 0.35rem;">Your rating</h3>
            <div class="card" style="padding: 0.9rem 1rem;">
                <?php if (!empty($errors['rating'])): ?>
                    <div class="form-error" style="margin-bottom: 0.45rem;"><?php echo e($errors['rating']); ?></div>
                <?php endif; ?>

                <?php if ($rating): ?>
                    <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.4rem;">
                        You rated this trip
                        <strong><?php echo e($rating['Stars']); ?>/5</strong>
                        on <?php echo e(osrh_format_dt_detail($rating['CreatedAt'] ?? null)); ?>.
                    </p>
                    <?php if (!empty($rating['Comment'])): ?>
                        <p class="text-muted" style="font-size: 0.84rem;">
                            "<?php echo e($rating['Comment']); ?>"
                        </p>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 0.8rem;">
                            No written comment.
                        </p>
                    <?php endif; ?>
                    <p class="text-muted" style="font-size: 0.8rem; margin-top: 0.6rem;">
                        You can update your rating below. Submitting again will overwrite the previous one.
                    </p>
                <?php else: ?>
                    <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.5rem;">
                        You haven't rated this trip yet. Share your experience with the driver.
                    </p>
                <?php endif; ?>

                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="trip_id" value="<?php echo e($tripId); ?>">
                    <input type="hidden" name="action" value="rate_trip">

                    <div class="form-group">
                        <label class="form-label" for="stars">Stars (1–5)</label>
                        <select
                            id="stars"
                            name="stars"
                            class="form-control"
                            data-required="1"
                        >
                            <option value="">Select...</option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="comment">Comment (optional)</label>
                        <textarea
                            id="comment"
                            name="comment"
                            class="form-control"
                            rows="3"
                            placeholder="How was the ride?"
                        ></textarea>
                    </div>

                    <div class="form-group" style="margin-top: 0.7rem;">
                        <button type="submit" class="btn btn-primary btn-small">
                            Save rating
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Check if map container exists (not shown for completed/cancelled trips)
    var mapContainer = document.getElementById('trip-map');
    if (!mapContainer) {
        console.log('Map container not found - trip is completed or cancelled');
        return;
    }
    
    if (!window.OSRH || typeof window.OSRH.initMap !== 'function') {
        return;
    }

    var tripId = <?php echo json_encode($tripId); ?>;
    var isLiveTracking = <?php echo json_encode($isLiveTracking); ?>;
    var isRealDriverTrip = <?php echo json_encode($isRealDriverTrip); ?>;
    var initialTripStatus = '<?php echo e($trip['TripStatus'] ?? 'unknown'); ?>';
    var isDriverEnRoute = <?php echo json_encode($isDriverEnRoute); ?>;
    var isTripInProgress = <?php echo json_encode($isTripInProgress); ?>;
    
    // For segment trips, these are the segment's From/To locations (NOT the whole journey)
    var pickupLat = <?php echo $trip['PickupLat']   !== null ? json_encode((float)$trip['PickupLat'])   : 'null'; ?>;
    var pickupLon = <?php echo $trip['PickupLon']   !== null ? json_encode((float)$trip['PickupLon'])   : 'null'; ?>;
    var dropLat   = <?php echo $trip['DropoffLat'] !== null ? json_encode((float)$trip['DropoffLat']) : 'null'; ?>;
    var dropLon   = <?php echo $trip['DropoffLon'] !== null ? json_encode((float)$trip['DropoffLon']) : 'null'; ?>;
    var estimatedDurationMin = <?php echo isset($trip['EstimatedDurationMin']) && $trip['EstimatedDurationMin'] !== null ? json_encode((int)$trip['EstimatedDurationMin']) : '15'; ?>;
    
    // Segment info
    var isSegmentTrip = <?php echo json_encode($isSegmentTrip); ?>;
    var segmentOrder = <?php echo json_encode($segmentOrder); ?>;
    var totalSegments = <?php echo json_encode($totalSegments); ?>;
    var nextSegmentId = <?php echo json_encode($nextSegment ? (int)$nextSegment['SegmentID'] : null); ?>;
    var rideRequestId = <?php echo json_encode((int)$trip['RideRequestID']); ?>;
    
    // Function to check for next segment trip assignment and redirect
    function checkForNextSegmentTrip() {
        console.log('Checking for next segment trip assignment...');
        
        // Update UI to show we're waiting
        var trackingStatusText = document.getElementById('tracking-status-text');
        if (trackingStatusText) {
            trackingStatusText.textContent = '🔄 Segment completed! Looking for next driver...';
        }
        
        // Call API to get updated segment info
        fetch('../api/get_geofences.php?action=get_segments&ride_request_id=' + rideRequestId)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.segments) {
                    // Find the next segment
                    var nextTripId = null;
                    for (var i = 0; i < data.segments.length; i++) {
                        var seg = data.segments[i];
                        if (seg.SegmentOrder === segmentOrder + 1 && seg.TripID) {
                            nextTripId = seg.TripID;
                            break;
                        }
                    }
                    
                    if (nextTripId) {
                        console.log('Found next segment trip:', nextTripId);
                        if (trackingStatusText) {
                            trackingStatusText.textContent = '🚗 Next driver found! Redirecting...';
                        }
                        window.location.href = '<?php echo e(url('passenger/ride_detail.php')); ?>?trip_id=' + nextTripId;
                    } else {
                        console.log('Next segment not assigned yet, will check again in 3 seconds...');
                        if (trackingStatusText) {
                            trackingStatusText.textContent = '⏳ Waiting for next segment driver assignment...';
                        }
                        // Keep polling until assigned
                        setTimeout(checkForNextSegmentTrip, 3000);
                    }
                } else {
                    // Fallback - just reload to show the completion UI
                    window.location.reload();
                }
            })
            .catch(function(err) {
                console.warn('Error checking for next segment:', err);
                // Retry after a delay
                setTimeout(checkForNextSegmentTrip, 3000);
            });
    }
    
    // Current tracking phase: 'driver_en_route' or 'trip_in_progress' or 'completed'
    var currentPhase = isTripInProgress ? 'trip_in_progress' : (isDriverEnRoute ? 'driver_en_route' : 'completed');
    var currentSpeedMultiplier = 1.0;
    var currentPickupSpeedMultiplier = 1.0;
    var routeCoordinates = null; // Will store OSRM route for accurate animation
    
    console.log('=== Live Tracking Debug ===');
    console.log('Trip ID:', tripId);
    console.log('Initial Trip Status:', initialTripStatus);
    console.log('Current Phase:', currentPhase);
    if (isSegmentTrip) {
        console.log('🔀 SEGMENT TRIP: Segment', segmentOrder, 'of', totalSegments);
        console.log('Segment Pickup (From):', pickupLat, pickupLon);
        console.log('Segment Dropoff (To - Bridge):', dropLat, dropLon);
    } else {
        console.log('Regular Trip');
        console.log('Pickup:', pickupLat, pickupLon);
        console.log('Dropoff:', dropLat, dropLon);
    }

    var centerLat = 35.1667;
    var centerLon = 33.3667;

    if (pickupLat !== null && pickupLon !== null) {
        centerLat = pickupLat;
        centerLon = pickupLon;
    } else if (dropLat !== null && dropLon !== null) {
        centerLat = dropLat;
        centerLon = dropLon;
    }

    var map = window.OSRH.initMap('trip-map', { lat: centerLat, lng: centerLon, zoom: 14 });
    if (!map) {
        return;
    }

    // Markers and layers
    var driverMarker = null;      // Driver/car marker during pickup phase
    var passengerMarker = null;   // Passenger position during trip phase
    var driverRouteLayer = null;  // Dashed line to pickup
    var tripRouteLayer = null;    // Main route polyline
    var pollInterval = null;
    var lastPosition = null;
    var lastLiveDriverPos = null; // Track last live GPS position for route updates

    // Create car marker icon
    function createCarIcon() {
        return L.divIcon({
            className: 'car-marker',
            html: '<div style="background: #3b82f6; width: 36px; height: 36px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; font-size: 18px;">🚗</div>',
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        });
    }
    
    // Create passenger marker icon (for in-trip tracking)
    function createPassengerIcon() {
        return L.divIcon({
            className: 'passenger-marker',
            html: '<div style="background: #22c55e; width: 40px; height: 40px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; font-size: 20px;">🚕</div>',
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });
    }

    // Update status display
    function updateStatusDisplay(phase, remainingSeconds, progressPercent, hasArrived, speedMultiplier) {
        var etaDisplay = document.getElementById('eta-display');
        var progressBar = document.getElementById('progress-bar');
        var trackingIndicator = document.getElementById('tracking-indicator');
        var trackingStatus = document.getElementById('tracking-status');
        var trackingStatusText = document.getElementById('tracking-status-text');
        var startTripPanel = document.getElementById('start-trip-panel');
        var simulationControls = document.getElementById('simulation-controls');

        if (!etaDisplay) return;

        // Show simulation controls during trip (but NOT for real driver trips)
        if (simulationControls) {
            simulationControls.style.display = (phase === 'trip_in_progress' && !isRealDriverTrip) ? 'block' : 'none';
        }

        // Handle waiting for real driver GPS
        if (phase === 'waiting_for_gps') {
            etaDisplay.textContent = 'Waiting for driver GPS...';
            etaDisplay.style.color = '#f59e0b';
            if (progressBar) progressBar.style.width = '0%';
            if (trackingIndicator) {
                trackingIndicator.style.background = '#f59e0b';
                trackingIndicator.style.animation = 'pulse 1.5s ease-in-out infinite';
            }
            if (trackingStatus) {
                trackingStatus.style.background = 'rgba(245, 158, 11, 0.15)';
                trackingStatus.style.borderColor = '#f59e0b';
            }
            if (trackingStatusText) {
                trackingStatusText.textContent = '📍 Waiting for driver to enable GPS location...';
            }
            return;
        }

        if (phase === 'driver_en_route') {
            if (hasArrived) {
                // Driver has arrived - show start trip panel
                etaDisplay.textContent = 'Driver has arrived!';
                etaDisplay.style.color = '#22c55e';
                if (progressBar) progressBar.style.width = '100%';
                if (trackingIndicator) {
                    trackingIndicator.style.background = '#22c55e';
                    trackingIndicator.style.animation = 'none';
                }
                if (trackingStatus) {
                    trackingStatus.style.background = 'rgba(34, 197, 94, 0.15)';
                    trackingStatus.style.borderColor = '#22c55e';
                }
                if (trackingStatusText) {
                    trackingStatusText.textContent = 'Driver has arrived at pickup!';
                }
                // Show start trip panel
                if (startTripPanel) {
                    startTripPanel.style.display = 'block';
                }
                return;
            }
            
            // Driver on the way
            if (trackingStatusText) {
                var pickupSpeedText = speedMultiplier > 1 ? ' (' + speedMultiplier + 'x speed)' : '';
                var liveText = isRealDriverTrip ? ' 📡 Live' : '';
                trackingStatusText.textContent = 'Driver is on the way to pick you up' + pickupSpeedText + liveText;
            }
        } else if (phase === 'trip_in_progress') {
            // Hide start trip panel
            if (startTripPanel) {
                startTripPanel.style.display = 'none';
            }
            
            if (hasArrived) {
                // Trip completed - update display immediately
                etaDisplay.textContent = 'You have arrived!';
                etaDisplay.style.color = '#22c55e';
                if (progressBar) progressBar.style.width = '100%';
                if (trackingIndicator) {
                    trackingIndicator.style.background = '#22c55e';
                    trackingIndicator.style.animation = 'none';
                }
                if (trackingStatus) {
                    trackingStatus.style.background = 'rgba(34, 197, 94, 0.15)';
                    trackingStatus.style.borderColor = '#22c55e';
                }
                if (trackingStatusText) {
                    trackingStatusText.textContent = '🎉 Completing trip...';
                }
                if (simulationControls) {
                    simulationControls.style.display = 'none';
                }
                // Stop polling
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                
                // Call complete_trip API to mark as completed in database
                completeTrip();
                return;
            }
            
            // Trip in progress
            if (trackingStatusText) {
                var speedText = speedMultiplier > 1 ? ' (' + speedMultiplier + 'x speed)' : '';
                var liveText = isRealDriverTrip ? ' 📡 Live' : '';
                trackingStatusText.textContent = 'Trip in progress - heading to destination' + speedText + liveText;
            }
            if (trackingStatus) {
                trackingStatus.style.background = 'rgba(34, 197, 94, 0.1)';
                trackingStatus.style.borderColor = 'rgba(34, 197, 94, 0.3)';
            }
        }

        // Format remaining time
        var minutes = Math.floor(remainingSeconds / 60);
        var seconds = remainingSeconds % 60;
        
        if (minutes > 0) {
            etaDisplay.textContent = minutes + ' min ' + seconds + ' sec';
        } else if (seconds > 0) {
            etaDisplay.textContent = seconds + ' seconds';
        } else {
            etaDisplay.textContent = 'Arriving...';
        }

        // Update progress bar
        if (progressBar) {
            progressBar.style.width = progressPercent + '%';
        }
    }

    // Update position marker on map
    function updatePositionMarker(lat, lng, phase, animate) {
        var marker = (phase === 'trip_in_progress') ? passengerMarker : driverMarker;
        var icon = (phase === 'trip_in_progress') ? createPassengerIcon() : createCarIcon();
        var popupText = (phase === 'trip_in_progress') 
            ? '<strong>🚕 Your Trip</strong><br>Heading to destination'
            : '<strong>🚗 Your Driver</strong><br>En route to pickup';
        
        if (!marker) {
            if (phase === 'trip_in_progress') {
                passengerMarker = L.marker([lat, lng], { icon: icon, zIndexOffset: 1000 }).addTo(map);
                passengerMarker.bindPopup(popupText);
                marker = passengerMarker;
                // Remove driver marker when trip starts
                if (driverMarker) {
                    map.removeLayer(driverMarker);
                    driverMarker = null;
                }
            } else {
                driverMarker = L.marker([lat, lng], { icon: icon, zIndexOffset: 1000 }).addTo(map);
                driverMarker.bindPopup(popupText);
                marker = driverMarker;
            }
        } else {
            if (animate && lastPosition) {
                animateMarker(marker, lastPosition, { lat: lat, lng: lng }, 4500);
            } else {
                marker.setLatLng([lat, lng]);
            }
        }
        lastPosition = { lat: lat, lng: lng };
    }

    // Animate marker movement
    function animateMarker(marker, from, to, duration) {
        var startTime = Date.now();
        var startLat = from.lat;
        var startLng = from.lng;
        var deltaLat = to.lat - startLat;
        var deltaLng = to.lng - startLng;

        function animate() {
            var elapsed = Date.now() - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            
            var currentLat = startLat + deltaLat * eased;
            var currentLng = startLng + deltaLng * eased;
            
            marker.setLatLng([currentLat, currentLng]);
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        }
        
        requestAnimationFrame(animate);
    }

    // Calculate total distance of route
    function calculateRouteDistance(coords) {
        var totalDist = 0;
        for (var i = 1; i < coords.length; i++) {
            totalDist += getDistance(coords[i-1], coords[i]);
        }
        return totalDist;
    }

    // Get distance between two points in meters
    function getDistance(p1, p2) {
        var R = 6371000; // Earth radius in meters
        var lat1 = p1[1] * Math.PI / 180;
        var lat2 = p2[1] * Math.PI / 180;
        var deltaLat = (p2[1] - p1[1]) * Math.PI / 180;
        var deltaLng = (p2[0] - p1[0]) * Math.PI / 180;
        
        var a = Math.sin(deltaLat/2) * Math.sin(deltaLat/2) +
                Math.cos(lat1) * Math.cos(lat2) *
                Math.sin(deltaLng/2) * Math.sin(deltaLng/2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    // Get position along route at given progress (0 to 1)
    function getPositionAlongRoute(coords, progress) {
        if (!coords || coords.length < 2) return null;
        if (progress <= 0) return { lat: coords[0][1], lng: coords[0][0] };
        if (progress >= 1) return { lat: coords[coords.length-1][1], lng: coords[coords.length-1][0] };
        
        // Calculate total route distance
        var totalDist = calculateRouteDistance(coords);
        var targetDist = totalDist * progress;
        
        // Walk along the route to find the position
        var accumulatedDist = 0;
        for (var i = 1; i < coords.length; i++) {
            var segmentDist = getDistance(coords[i-1], coords[i]);
            
            if (accumulatedDist + segmentDist >= targetDist) {
                // Position is on this segment
                var remainingDist = targetDist - accumulatedDist;
                var segmentProgress = remainingDist / segmentDist;
                
                // Interpolate between the two points
                var lng = coords[i-1][0] + (coords[i][0] - coords[i-1][0]) * segmentProgress;
                var lat = coords[i-1][1] + (coords[i][1] - coords[i-1][1]) * segmentProgress;
                
                return { lat: lat, lng: lng };
            }
            accumulatedDist += segmentDist;
        }
        
        // Fallback to last point
        return { lat: coords[coords.length-1][1], lng: coords[coords.length-1][0] };
    }

    // Draw route line
    function updateRouteLine(fromLat, fromLng, toLat, toLng, phase) {
        if (phase === 'driver_en_route') {
            // Dashed line from driver to pickup
            if (driverRouteLayer) {
                map.removeLayer(driverRouteLayer);
            }
            driverRouteLayer = L.polyline([
                [fromLat, fromLng],
                [toLat, toLng]
            ], {
                color: '#f59e0b',
                weight: 4,
                opacity: 0.7,
                dashArray: '10, 10'
            }).addTo(map);
        }
    }

    // Store pickup route coordinates for driver tracking
    var pickupRouteCoordinates = null;

    // Fetch driver location (pickup phase)
    function fetchDriverLocation() {
        fetch('../api/driver_location.php?trip_id=' + tripId)
            .then(function(response) {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(function(result) {
                if (!result.success) {
                    console.warn('API error:', result.error);
                    return;
                }

                var data = result.data;
                
                // Check if trip has started (status changed to in_progress)
                if (data.tripStatus === 'in_progress') {
                    console.log('Trip has started! Switching to trip tracking...');
                    currentPhase = 'trip_in_progress';
                    // Stop current polling and start trip polling
                    if (pollInterval) {
                        clearInterval(pollInterval);
                    }
                    startTripTracking();
                    return;
                }
                
                // Handle real driver trip waiting for GPS
                if (data.waitingForDriverGps) {
                    console.log('Waiting for real driver to enable GPS...');
                    updateStatusDisplay('waiting_for_gps', null, 0, false, 1);
                    // Hide driver marker if showing
                    if (positionMarker) {
                        positionMarker.setOpacity(0);
                    }
                    return;
                }
                
                // Calculate position along pickup route if available
                var posLat = data.currentLat;
                var posLng = data.currentLng;

                var isLive = !!data.isLive;

                // Live GPS: use raw coordinates and skip simulation interpolation
                if (isLive) {
                    // For live GPS, fetch and draw route from driver to pickup
                    if (!driverRouteLayer && data.pickupLat && data.pickupLng) {
                        fetchLiveDriverRoute(posLat, posLng, data.pickupLat, data.pickupLng);
                    } else if (driverRouteLayer && lastLiveDriverPos) {
                        // Update route if driver has moved significantly (more than 50 meters)
                        var distMoved = getDistance(
                            { lat: lastLiveDriverPos.lat, lng: lastLiveDriverPos.lng },
                            { lat: posLat, lng: posLng }
                        );
                        if (distMoved > 50) {
                            fetchLiveDriverRoute(posLat, posLng, data.pickupLat, data.pickupLng);
                        }
                    }
                    lastLiveDriverPos = { lat: posLat, lng: posLng };
                    
                    updatePositionMarker(posLat, posLng, 'driver_en_route', true);
                    updateStatusDisplay('driver_en_route', data.remainingSeconds, data.progressPercent, data.hasArrived, currentPickupSpeedMultiplier);

                    if (data.hasArrived) {
                        var pickupSpeedControlsLive = document.getElementById('pickup-speed-controls');
                        if (pickupSpeedControlsLive) {
                            pickupSpeedControlsLive.style.display = 'none';
                        }
                        // Remove driver route when arrived
                        if (driverRouteLayer) {
                            map.removeLayer(driverRouteLayer);
                            driverRouteLayer = null;
                        }
                        console.log('Driver has arrived (live)! Auto-starting trip...');
                        setTimeout(function() {
                            startTrip();
                        }, 2000);
                    } else {
                        var liveBounds = L.latLngBounds([
                            [posLat, posLng],
                            [data.pickupLat, data.pickupLng]
                        ]);
                        if (dropLat && dropLon) {
                            liveBounds.extend([dropLat, dropLon]);
                        }
                        if (!map.getBounds().contains([posLat, posLng])) {
                            map.fitBounds(liveBounds.pad(0.2));
                        }
                    }

                    return;
                }
                
                // Use pickup route from API if available
                if (data.pickupRouteCoordinates && data.pickupRouteCoordinates.length >= 2) {
                    if (!pickupRouteCoordinates) {
                        pickupRouteCoordinates = data.pickupRouteCoordinates;
                        console.log('Loaded pickup route from API:', pickupRouteCoordinates.length, 'points');
                        // Draw the actual route on the map
                        if (driverRouteLayer) {
                            map.removeLayer(driverRouteLayer);
                        }
                        var routeLatLngs = pickupRouteCoordinates.map(function(coord) {
                            return [coord[1], coord[0]]; // [lat, lng]
                        });
                        driverRouteLayer = L.polyline(routeLatLngs, {
                            color: '#f59e0b',
                            weight: 4,
                            opacity: 0.7,
                            dashArray: '10, 10'
                        }).addTo(map);
                    }
                    var progress = data.progressPercent / 100.0;
                    var routePos = getPositionAlongRoute(pickupRouteCoordinates, progress);
                    if (routePos) {
                        posLat = routePos.lat;
                        posLng = routePos.lng;
                        console.log('Driver position from route:', progress.toFixed(2), '→', posLat.toFixed(6), posLng.toFixed(6));
                    }
                } else if (!pickupRouteCoordinates && data.startLat && data.startLng) {
                    // No route from DB, fetch from OSRM
                    fetchPickupRoute(data.startLat, data.startLng, data.pickupLat, data.pickupLng);
                }
                
                // If we have pickup route, use route-based position
                if (pickupRouteCoordinates && pickupRouteCoordinates.length >= 2) {
                    var progress = data.progressPercent / 100.0;
                    var routePos = getPositionAlongRoute(pickupRouteCoordinates, progress);
                    if (routePos) {
                        posLat = routePos.lat;
                        posLng = routePos.lng;
                    }
                }
                
                // Update driver marker with route-based position
                updatePositionMarker(posLat, posLng, 'driver_en_route', true);
                
                // Update pickup speed multiplier display (only sync if not user-modified)
                if (data.pickupSpeedMultiplier && data.pickupSpeedMultiplier > 0) {
                    // Only update if server speed differs significantly and we haven't just changed it
                    var serverSpeed = parseFloat(data.pickupSpeedMultiplier);
                    if (Math.abs(serverSpeed - currentPickupSpeedMultiplier) > 0.1) {
                        currentPickupSpeedMultiplier = serverSpeed;
                        var pickupSlider = document.getElementById('pickup-speed-slider');
                        var pickupSpeedValue = document.getElementById('pickup-speed-value');
                        if (pickupSlider) {
                            pickupSlider.value = serverSpeed;
                        }
                        if (pickupSpeedValue) {
                            pickupSpeedValue.textContent = serverSpeed + 'x';
                        }
                    }
                }
                
                // Update display
                updateStatusDisplay('driver_en_route', data.remainingSeconds, data.progressPercent, data.hasArrived, currentPickupSpeedMultiplier);

                // If driver has arrived, auto-start the trip after a moment
                if (data.hasArrived) {
                    // Hide pickup speed controls
                    var pickupSpeedControls = document.getElementById('pickup-speed-controls');
                    if (pickupSpeedControls) {
                        pickupSpeedControls.style.display = 'none';
                    }
                    
                    console.log('Driver has arrived! Auto-starting trip...');
                    setTimeout(function() {
                        startTrip();
                    }, 2000);
                }

                // Adjust map view
                if (!data.hasArrived) {
                    var bounds = L.latLngBounds([
                        [posLat, posLng],
                        [data.pickupLat, data.pickupLng]
                    ]);
                    if (dropLat && dropLon) {
                        bounds.extend([dropLat, dropLon]);
                    }
                    if (!map.getBounds().contains([posLat, posLng])) {
                        map.fitBounds(bounds.pad(0.2));
                    }
                }
            })
            .catch(function(err) {
                console.warn('Failed to fetch driver location:', err);
            });
    }

    // Fetch OSRM route from driver to pickup (fallback if not in DB)
    function fetchPickupRoute(startLat, startLng, endLat, endLng) {
        if (pickupRouteCoordinates) return; // Already have route
        
        var url = 'https://router.project-osrm.org/route/v1/driving/' + 
            startLng + ',' + startLat + ';' + endLng + ',' + endLat + 
            '?overview=full&geometries=geojson';
        
        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.routes && data.routes[0] && data.routes[0].geometry) {
                    pickupRouteCoordinates = data.routes[0].geometry.coordinates;
                    console.log('Fetched pickup route from OSRM:', pickupRouteCoordinates.length, 'points');
                    
                    // Draw the route on the map
                    if (driverRouteLayer) {
                        map.removeLayer(driverRouteLayer);
                    }
                    var routeLatLngs = pickupRouteCoordinates.map(function(coord) {
                        return [coord[1], coord[0]]; // [lat, lng]
                    });
                    driverRouteLayer = L.polyline(routeLatLngs, {
                        color: '#f59e0b',
                        weight: 4,
                        opacity: 0.7,
                        dashArray: '10, 10'
                    }).addTo(map);
                }
            })
            .catch(function(err) {
                console.warn('Failed to fetch pickup route:', err);
            });
    }

    // Fetch route from live driver GPS position to pickup (for real driver trips)
    function fetchLiveDriverRoute(driverLat, driverLng, pickupLat, pickupLng) {
        var url = 'https://router.project-osrm.org/route/v1/driving/' + 
            driverLng + ',' + driverLat + ';' + pickupLng + ',' + pickupLat + 
            '?overview=full&geometries=geojson';
        
        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.routes && data.routes[0] && data.routes[0].geometry) {
                    var routeCoords = data.routes[0].geometry.coordinates;
                    console.log('Fetched live driver route from OSRM:', routeCoords.length, 'points');
                    
                    // Draw the route on the map
                    if (driverRouteLayer) {
                        map.removeLayer(driverRouteLayer);
                    }
                    var routeLatLngs = routeCoords.map(function(coord) {
                        return [coord[1], coord[0]]; // [lat, lng]
                    });
                    driverRouteLayer = L.polyline(routeLatLngs, {
                        color: '#22c55e',  // Green for live driver route
                        weight: 5,
                        opacity: 0.8,
                        dashArray: '10, 10'
                    }).addTo(map);
                    
                    // Fit bounds to show driver and pickup
                    var bounds = L.latLngBounds(routeLatLngs);
                    if (dropLat && dropLon) {
                        bounds.extend([dropLat, dropLon]);
                    }
                    map.fitBounds(bounds.pad(0.15));
                }
            })
            .catch(function(err) {
                console.warn('Failed to fetch live driver route:', err);
            });
    }

    // Fetch trip position (in-progress phase)
    function fetchTripPosition() {
        fetch('../api/trip_position.php?trip_id=' + tripId)
            .then(function(response) {
                if (!response.ok) throw new Error('Network error: ' + response.status);
                return response.json();
            })
            .then(function(result) {
                if (!result.success) {
                    console.warn('API error:', result.error);
                    return;
                }

                var data = result.data;
                
                // Check if trip is already completed
                if (data.tripStatus === 'completed') {
                    console.log('Trip completed, checking for next segment...');
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                    
                    <?php if ($isSegmentTrip && ($segmentOrder ?? 0) < ($totalSegments ?? 1)): ?>
                    // This is a segment trip with more segments - check for next trip assignment
                    checkForNextSegmentTrip();
                    <?php else: ?>
                    // No more segments - just reload to show completion
                    window.location.reload();
                    <?php endif; ?>
                    return;
                }
                
                // Handle real driver trip waiting for GPS
                if (data.waitingForDriverGps) {
                    console.log('Waiting for real driver GPS during trip...');
                    updateStatusDisplay('waiting_for_gps', null, 0, false, 1);
                    // Hide driver marker if showing
                    if (positionMarker) {
                        positionMarker.setOpacity(0);
                    }
                    return;
                }
                
                currentSpeedMultiplier = data.speedMultiplier || 1.0;
                
                // Calculate position along route if we have route coordinates
                var posLat = data.currentLat;
                var posLng = data.currentLng;
                
                // For live GPS, use raw coordinates directly
                var isLive = !!data.isLive;
                if (isLive) {
                    console.log('Live GPS position:', posLat, posLng);
                    updatePositionMarker(posLat, posLng, 'trip_in_progress', true);
                    updateStatusDisplay('trip_in_progress', data.remainingSeconds, data.progressPercent, data.hasArrived, 1);
                    
                    if (data.hasArrived) {
                        console.log('Real driver arrived at destination!');
                        if (pollInterval) {
                            clearInterval(pollInterval);
                            pollInterval = null;
                        }
                        completeTrip();
                    }
                    return;
                }
                
                // First check: Use route from API response if available
                if (data.routeCoordinates && data.routeCoordinates.length >= 2) {
                    if (!routeCoordinates || routeCoordinates.length < 2) {
                        routeCoordinates = data.routeCoordinates;
                        console.log('Loaded trip route from API:', routeCoordinates.length, 'points');
                    }
                }
                
                // Use stored route coordinates for accurate positioning along the route
                if (routeCoordinates && routeCoordinates.length >= 2) {
                    var progress = data.progressPercent / 100.0; // Convert percent to 0-1
                    var routePos = getPositionAlongRoute(routeCoordinates, progress);
                    if (routePos) {
                        posLat = routePos.lat;
                        posLng = routePos.lng;
                        console.log('Trip position from route:', (progress * 100).toFixed(1) + '%', '→', posLat.toFixed(6), posLng.toFixed(6));
                    }
                } else {
                    // No route available - fetch from OSRM as fallback
                    console.log('No trip route coordinates, fetching from OSRM...');
                    fetchTripRoute(data.pickupLat, data.pickupLng, data.dropoffLat, data.dropoffLng);
                }
                
                // Update passenger marker on map
                updatePositionMarker(posLat, posLng, 'trip_in_progress', true);
                
                // Update display
                updateStatusDisplay('trip_in_progress', data.remainingSeconds, data.progressPercent, data.hasArrived, currentSpeedMultiplier);

                // Adjust map view to follow the trip
                var bounds = L.latLngBounds([
                    [posLat, posLng],
                    [data.dropoffLat, data.dropoffLng]
                ]);
                if (!map.getBounds().contains([posLat, posLng])) {
                    map.fitBounds(bounds.pad(0.3));
                }
            })
            .catch(function(err) {
                console.warn('Failed to fetch trip position:', err);
            });
    }

    // Fetch OSRM route for trip (fallback if not in DB)
    function fetchTripRoute(startLat, startLng, endLat, endLng) {
        if (routeCoordinates && routeCoordinates.length >= 2) return; // Already have route
        
        var url = 'https://router.project-osrm.org/route/v1/driving/' + 
            startLng + ',' + startLat + ';' + endLng + ',' + endLat + 
            '?overview=full&geometries=geojson';
        
        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.routes && data.routes[0] && data.routes[0].geometry) {
                    routeCoordinates = data.routes[0].geometry.coordinates;
                    console.log('Fetched trip route from OSRM:', routeCoordinates.length, 'points');
                }
            })
            .catch(function(err) {
                console.warn('Failed to fetch trip route:', err);
            });
    }

    // Start the trip (simulation mode)
    function startTrip() {
        console.log('Starting trip...');
        console.log('Route coordinates available:', routeCoordinates ? routeCoordinates.length + ' points' : 'none');
        
        // If no route, fetch it first then start
        if (!routeCoordinates || routeCoordinates.length < 2) {
            console.log('No route available, fetching from OSRM before starting trip...');
            var url = 'https://router.project-osrm.org/route/v1/driving/' + 
                pickupLon + ',' + pickupLat + ';' + dropLon + ',' + dropLat + 
                '?overview=full&geometries=geojson';
            
            fetch(url)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.routes && data.routes[0] && data.routes[0].geometry) {
                        routeCoordinates = data.routes[0].geometry.coordinates;
                        console.log('Fetched route before starting:', routeCoordinates.length, 'points');
                    }
                    // Now actually start the trip
                    doStartTrip();
                })
                .catch(function(err) {
                    console.warn('Failed to fetch route, starting without:', err);
                    doStartTrip();
                });
        } else {
            doStartTrip();
        }
    }
    
    // Actually start the trip (called by startTrip after route is ready)
    function doStartTrip() {
        // Calculate estimated duration in seconds
        var estimatedDurationSec = estimatedDurationMin * 60;
        
        // Get route geometry if available (format: [[lng, lat], [lng, lat], ...])
        var routeGeometry = routeCoordinates ? JSON.stringify(routeCoordinates) : null;
        
        var formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('action', 'start_trip');
        formData.append('estimated_duration_sec', estimatedDurationSec);
        formData.append('speed_multiplier', currentSpeedMultiplier);
        if (routeGeometry) {
            formData.append('route_geometry', routeGeometry);
            console.log('Sending route with', routeCoordinates.length, 'waypoints');
        } else {
            console.log('Starting trip without route geometry');
        }
        
        fetch('../api/trip_position.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            console.log('Start trip result:', result);
            if (result.success) {
                currentPhase = 'trip_in_progress';
                // Hide start trip panel
                var startTripPanel = document.getElementById('start-trip-panel');
                if (startTripPanel) {
                    startTripPanel.style.display = 'none';
                }
                // Start trip tracking
                if (pollInterval) {
                    clearInterval(pollInterval);
                }
                startTripTracking();
            }
        })
        .catch(function(err) {
            console.warn('Failed to start trip:', err);
        });
    }

    // Complete the trip (simulation mode) - called when hasArrived is true
    var tripCompletionInProgress = false;
    function completeTrip() {
        if (tripCompletionInProgress) {
            console.log('Trip completion already in progress...');
            return;
        }
        tripCompletionInProgress = true;
        
        console.log('Completing trip...');
        
        var formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('action', 'complete_trip');
        
        fetch('../api/trip_position.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            console.log('Complete trip result:', result);
            
            // Update display to show completion
            var trackingStatusText = document.getElementById('tracking-status-text');
            var etaDisplay = document.getElementById('eta-display');
            
            if (result.success) {
                // Check if this was a segment trip with more segments
                if (result.journeyCompleted === false && result.nextSegmentId) {
                    if (trackingStatusText) {
                        trackingStatusText.textContent = '🚗 Segment completed! Waiting for next driver...';
                    }
                    if (etaDisplay) {
                        etaDisplay.textContent = 'Transfer';
                    }
                } else {
                    if (trackingStatusText) {
                        trackingStatusText.textContent = '🎉 Trip completed! You have arrived at your destination.';
                    }
                    if (etaDisplay) {
                        etaDisplay.textContent = 'Completed!';
                    }
                }
                
                // Reload page after short delay to show completed status or next segment
                setTimeout(function() {
                    <?php if ($isSegmentTrip && ($segmentOrder ?? 0) < ($totalSegments ?? 1)): ?>
                    // This is a segment trip with more segments - check for next trip
                    checkForNextSegmentTrip();
                    <?php else: ?>
                    window.location.reload();
                    <?php endif; ?>
                }, 2000);
            } else {
                console.warn('Trip completion failed:', result.error || result.message);
                if (trackingStatusText) {
                    trackingStatusText.textContent = '⚠️ Could not mark trip as complete. Please refresh.';
                }
                tripCompletionInProgress = false;
            }
        })
        .catch(function(err) {
            console.warn('Failed to complete trip:', err);
            tripCompletionInProgress = false;
            
            var trackingStatusText = document.getElementById('tracking-status-text');
            if (trackingStatusText) {
                trackingStatusText.textContent = '⚠️ Error completing trip. Please refresh.';
            }
        });
    }

    // Update simulation speed
    function updateSimulationSpeed(speed) {
        currentSpeedMultiplier = speed;
        
        var formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('action', 'update_speed');
        formData.append('speed_multiplier', speed);
        
        fetch('../api/trip_position.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            console.log('Speed update result:', result);
            var speedValue = document.getElementById('speed-value');
            if (speedValue) {
                speedValue.textContent = speed + 'x';
            }
        })
        .catch(function(err) {
            console.warn('Failed to update speed:', err);
        });
    }

    // Update pickup simulation speed
    function updatePickupSpeed(speed) {
        currentPickupSpeedMultiplier = speed;
        
        var formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('action', 'update_pickup_speed');
        formData.append('speed_multiplier', speed);
        
        fetch('../api/driver_location.php?trip_id=' + tripId, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            console.log('Pickup speed update result:', result);
            var speedValue = document.getElementById('pickup-speed-value');
            if (speedValue) {
                speedValue.textContent = speed + 'x';
            }
        })
        .catch(function(err) {
            console.warn('Failed to update pickup speed:', err);
        });
    }

    // Start driver tracking (pickup phase)
    function startDriverTracking() {
        // Show pickup speed controls ONLY for simulated trips (not real driver trips)
        var pickupSpeedControls = document.getElementById('pickup-speed-controls');
        if (pickupSpeedControls && !isRealDriverTrip) {
            pickupSpeedControls.style.display = 'block';
        }
        
        fetchDriverLocation();
        // Poll more frequently for real driver trips (every 2 seconds) vs simulated (every 5 seconds)
        var pollFrequency = isRealDriverTrip ? 2000 : 5000;
        pollInterval = setInterval(fetchDriverLocation, pollFrequency);
    }

    // Start trip tracking (in-progress phase)
    function startTripTracking() {
        // Hide pickup speed controls
        var pickupSpeedControls = document.getElementById('pickup-speed-controls');
        if (pickupSpeedControls) {
            pickupSpeedControls.style.display = 'none';
        }
        
        // Show simulation controls ONLY for simulated trips (not real driver trips)
        var simulationControls = document.getElementById('simulation-controls');
        if (simulationControls && !isRealDriverTrip) {
            simulationControls.style.display = 'block';
        }
        
        fetchTripPosition();
        // Poll every 2 seconds for real-time tracking
        pollInterval = setInterval(fetchTripPosition, 2000);
    }

    // Initialize map with markers and routes
    function initializeMap() {
        // Add pickup marker
        if (pickupLat !== null && pickupLon !== null) {
            window.OSRH.addMarker(map, pickupLat, pickupLon, 'pickup', '<strong>📍 Pickup</strong>');
        }

        // Add dropoff marker
        if (dropLat !== null && dropLon !== null) {
            window.OSRH.addMarker(map, dropLat, dropLon, 'dropoff', '<strong>🏁 Dropoff</strong>');
        }

        // Show route from pickup to dropoff
        if (pickupLat !== null && pickupLon !== null && dropLat !== null && dropLon !== null) {
            window.OSRH.showRoute(map, 
                { lat: pickupLat, lng: pickupLon },
                { lat: dropLat, lng: dropLon },
                { color: '#22c55e', weight: 5, opacity: 0.8 }
            ).then(function(routeInfo) {
                // Store route coordinates for animation
                if (routeInfo && routeInfo.coordinates) {
                    routeCoordinates = routeInfo.coordinates;
                }
                var routeInfoContainer = document.getElementById('route-info');
                if (routeInfoContainer) {
                    window.OSRH.displayRouteInfo(routeInfoContainer, routeInfo);
                }
            }).catch(function(err) {
                console.warn('Route display error:', err);
            });
        }
    }

    // Setup simulation speed controls
    function setupSpeedControls() {
        var slider = document.getElementById('speed-slider');
        var speedValue = document.getElementById('speed-value');
        
        if (slider) {
            slider.addEventListener('input', function() {
                var speed = parseFloat(this.value);
                if (speedValue) {
                    speedValue.textContent = speed + 'x';
                }
            });
            
            slider.addEventListener('change', function() {
                var speed = parseFloat(this.value);
                updateSimulationSpeed(speed);
            });
        }
        
        // Quick speed buttons
        ['1x', '5x', '10x', '25x'].forEach(function(label) {
            var btn = document.getElementById('speed-' + label);
            if (btn) {
                btn.addEventListener('click', function() {
                    var speed = parseInt(label);
                    if (slider) {
                        slider.value = speed;
                    }
                    if (speedValue) {
                        speedValue.textContent = speed + 'x';
                    }
                    updateSimulationSpeed(speed);
                });
            }
        });
        
        // Start trip button
        var startTripBtn = document.getElementById('start-trip-btn');
        if (startTripBtn) {
            startTripBtn.addEventListener('click', function() {
                startTrip();
            });
        }
        
        // Pickup speed controls
        var pickupSlider = document.getElementById('pickup-speed-slider');
        var pickupSpeedValue = document.getElementById('pickup-speed-value');
        
        if (pickupSlider) {
            pickupSlider.addEventListener('input', function() {
                var speed = parseFloat(this.value);
                if (pickupSpeedValue) {
                    pickupSpeedValue.textContent = speed + 'x';
                }
            });
            
            pickupSlider.addEventListener('change', function() {
                var speed = parseFloat(this.value);
                updatePickupSpeed(speed);
            });
        }
        
        // Pickup quick speed buttons
        ['1x', '5x', '10x', '25x'].forEach(function(label) {
            var btn = document.getElementById('pickup-speed-' + label);
            if (btn) {
                btn.addEventListener('click', function() {
                    var speed = parseInt(label);
                    if (pickupSlider) {
                        pickupSlider.value = speed;
                    }
                    if (pickupSpeedValue) {
                        pickupSpeedValue.textContent = speed + 'x';
                    }
                    updatePickupSpeed(speed);
                });
            }
        });
    }

    // Initialize
    initializeMap();
    setupSpeedControls();

    // Start appropriate tracking based on current phase
    if (isLiveTracking) {
        console.log('Starting live tracking - phase:', currentPhase);
        setTimeout(function() {
            if (currentPhase === 'trip_in_progress') {
                startTripTracking();
            } else {
                startDriverTracking();
            }
        }, 500);
    } else {
        console.log('Not starting live tracking - trip status:', initialTripStatus);
        if (pickupLat !== null && pickupLon !== null && dropLat !== null && dropLon !== null) {
            var bounds = L.latLngBounds([
                [pickupLat, pickupLon],
                [dropLat, dropLon]
            ]);
            map.fitBounds(bounds.pad(0.2));
        }
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        if (pollInterval) {
            clearInterval(pollInterval);
        }
    });
});
</script>

<!-- Kaspa Payment Integration -->
<link rel="stylesheet" href="<?php echo e(url('assets/css/kaspa.css')); ?>">
<script src="<?php echo e(url('assets/js/kaspa.js')); ?>"></script>
<script>
// Kaspa payment functionality for ride_detail.php
var kaspaExchangeRate = null;
var driverKaspaAddress = '<?php echo e($driverKaspaWallet['WalletAddress'] ?? ''); ?>';
var tripId = <?php echo (int)$tripId; ?>;

function toggleKaspaPayment(selectedValue) {
    var selectedOption = document.querySelector('#payment_method_type_id option:checked');
    var isKaspa = selectedOption && selectedOption.dataset.kaspa === 'true';
    
    var standardFields = document.getElementById('standardPaymentFields');
    var kaspaSection = document.getElementById('kaspaPaymentSection');
    
    if (standardFields) {
        standardFields.style.display = isKaspa ? 'none' : 'block';
    }
    if (kaspaSection) {
        kaspaSection.style.display = isKaspa ? 'block' : 'none';
        
        if (isKaspa) {
            loadKaspaExchangeRate();
            setupKaspaAmountListener();
        }
    }
}

function loadKaspaExchangeRate() {
    fetch('<?php echo e(url('api/kaspa_api.php')); ?>?action=exchange-rate')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.rateKAStoEUR) {
                kaspaExchangeRate = data.rateKAStoEUR;
                var rateEl = document.getElementById('kaspa_exchange_rate');
                if (rateEl) {
                    rateEl.textContent = '1 KAS = €' + data.rateKAStoEUR.toFixed(4);
                }
                updateKasAmount();
            } else {
                console.error('Exchange rate not available:', data);
                // Use fallback rate
                kaspaExchangeRate = 0.10; // Fallback: 1 KAS = €0.10
                var rateEl = document.getElementById('kaspa_exchange_rate');
                if (rateEl) {
                    rateEl.textContent = '1 KAS ≈ €0.10 (fallback)';
                }
                updateKasAmount();
            }
        })
        .catch(function(err) {
            console.error('Error loading exchange rate:', err);
            // Use fallback rate on error
            kaspaExchangeRate = 0.10;
            var rateEl = document.getElementById('kaspa_exchange_rate');
            if (rateEl) {
                rateEl.textContent = '1 KAS ≈ €0.10 (fallback)';
            }
            updateKasAmount();
        });
}

function setupKaspaAmountListener() {
    var amountInput = document.getElementById('kaspa_amount_eur');
    if (amountInput) {
        amountInput.addEventListener('input', updateKasAmount);
    }
}

function updateKasAmount() {
    var amountInput = document.getElementById('kaspa_amount_eur');
    var kasDisplay = document.getElementById('kaspa_amount_kas');
    
    if (!amountInput || !kasDisplay || !kaspaExchangeRate) return;
    
    var eurAmount = parseFloat(amountInput.value) || 0;
    var kasAmount = kaspaExchangeRate > 0 ? (eurAmount / kaspaExchangeRate).toFixed(4) : 0;
    
    kasDisplay.textContent = kasAmount + ' KAS';
    
    // Generate QR code if available
    generateKaspaQR(kasAmount);
}

function generateKaspaQR(kasAmount) {
    var qrContainer = document.getElementById('kaspaQrCode');
    if (!qrContainer || !driverKaspaAddress) return;
    
    // Use kaspa URI format for QR
    var kaspaUri = 'kaspa:' + driverKaspaAddress.replace('kaspa:', '') + '?amount=' + kasAmount;
    
    // Generate QR code (using simple placeholder if QR library not available)
    if (typeof QRCode !== 'undefined') {
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, {
            text: kaspaUri,
            width: 150,
            height: 150,
            colorDark: '#000000',
            colorLight: '#ffffff'
        });
    } else {
        qrContainer.innerHTML = '<div style="width: 150px; height: 150px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #666;">QR: ' + kasAmount + ' KAS</div>';
    }
}

function copyKaspaAddress() {
    var addressEl = document.getElementById('driverKaspaAddress');
    if (addressEl) {
        var address = addressEl.textContent;
        navigator.clipboard.writeText(address).then(function() {
            alert('Kaspa address copied to clipboard!');
        }).catch(function() {
            // Fallback for older browsers
            var textArea = document.createElement('textarea');
            textArea.value = address;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            alert('Kaspa address copied to clipboard!');
        });
    }
}

function payWithKaspaWallet() {
    var amountInput = document.getElementById('kaspa_amount_eur');
    var eurAmount = parseFloat(amountInput ? amountInput.value : 0) || 0;
    var kasAmount = kaspaExchangeRate > 0 ? (eurAmount / kaspaExchangeRate).toFixed(8) : 0;
    var kasAmountSompi = Math.round(parseFloat(kasAmount) * 100000000); // Convert to sompi

    // Detect mobile device
    var isMobile = /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|WPDesktop/i.test(navigator.userAgent);
    
    // On mobile, go directly to kaspa: URI (opens Kaspium or other wallet app)
    if (isMobile) {
        // kaspa: URI uses KAS (not sompi)
        var kaspaUri = 'kaspa:' + driverKaspaAddress.replace('kaspa:', '') + '?amount=' + kasAmount;
        
        // Record the timestamp before opening wallet so we can search for transactions after this time
        var paymentInitTime = Math.floor(Date.now() / 1000);
        
        window.location.href = kaspaUri;
        
        // When user returns from wallet app, auto-detect the payment
        setTimeout(function() {
            showAutoDetectPaymentUI(kasAmount, eurAmount, paymentInitTime);
        }, 1500);
        return;
    }
    
    // On desktop, check for KasWare browser extension (most popular Kaspa wallet)
    if (typeof window.kasware !== 'undefined') {
        // KasWare is available
        window.kasware.requestAccounts().then(function(accounts) {
            if (!accounts || accounts.length === 0) {
                throw new Error('No account connected');
            }
            
            // Send transaction via KasWare
            return window.kasware.sendKaspa(driverKaspaAddress, kasAmountSompi);
        }).then(function(result) {
            console.log('KasWare result type:', typeof result);
            console.log('KasWare result:', result);
            
            var txId = null;
            
            // Handle different response formats from KasWare
            if (typeof result === 'string') {
                // Check if it's a JSON string
                if (result.startsWith('{')) {
                    try {
                        var parsed = JSON.parse(result);
                        txId = parsed.id || parsed.txId || parsed.transactionId || parsed.hash;
                    } catch (e) {
                        // Not JSON, might be raw txId
                        txId = result;
                    }
                } else {
                    // Plain txId string
                    txId = result;
                }
            } else if (result && typeof result === 'object') {
                // Transaction object
                txId = result.id || result.txId || result.transactionId || result.hash;
            }
            
            // Validate txId format (64 hex characters)
            if (txId && /^[a-f0-9]{64}$/i.test(txId)) {
                console.log('Valid transaction ID:', txId);
                showPaymentVerifying(txId);
                verifyAndRecordPayment(txId, kasAmount, eurAmount);
            } else {
                console.error('Invalid or missing txId. Result was:', result);
                console.error('Extracted txId:', txId);
                showPaymentStatus('error', 'Could not extract valid transaction ID from wallet response');
            }
        }).catch(function(err) {
            console.error('KasWare payment error:', err);
            if (err.message && err.message.includes('rejected')) {
                showPaymentStatus('error', 'Transaction rejected by user');
            } else {
                showPaymentStatus('error', 'Wallet payment failed: ' + (err.message || 'Unknown error'));
            }
        });
    } else {
        // KasWare not installed - show install prompt or use URI scheme
        var installModal = confirm('KasWare wallet extension not found.\n\nClick OK to install KasWare, or Cancel to open a kaspa: URI instead.');
        if (installModal) {
            window.open('https://kasware.xyz', '_blank');
        } else {
            // Fallback: open kaspa URI (amount in KAS, not sompi)
            var kaspaUri = 'kaspa:' + driverKaspaAddress.replace('kaspa:', '') + '?amount=' + kasAmount;
            window.location.href = kaspaUri;
        }
    }
}

function confirmKaspaPayment() {
    // First try auto-detection, fall back to manual entry
    var amountInput = document.getElementById('kaspa_amount_eur');
    var eurAmount = parseFloat(amountInput ? amountInput.value : 0) || 0;
    var kasAmount = kaspaExchangeRate > 0 ? (eurAmount / kaspaExchangeRate).toFixed(8) : 0;
    var paymentInitTime = Math.floor(Date.now() / 1000) - 300; // Check last 5 minutes
    
    showAutoDetectPaymentUI(kasAmount, eurAmount, paymentInitTime);
}

// Auto-detect payment by polling the Kaspa blockchain for incoming transactions
var autoDetectRetryCount = 0;
var autoDetectMaxRetries = 30; // 30 retries x 5s = 2.5 minutes
var autoDetectTimer = null;

function showAutoDetectPaymentUI(kasAmount, eurAmount, sinceTimestamp) {
    var kaspaSection = document.getElementById('kaspaPaymentSection');
    if (!kaspaSection) return;
    
    autoDetectRetryCount = 0;
    
    var html = '<div style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1), rgba(34, 197, 94, 0.1)); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1.5rem; text-align: center;">';
    html += '<div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>';
    html += '<h4 style="margin: 0.5rem 0; color: #49eacb;">Detecting Payment...</h4>';
    html += '<p style="font-size: 0.8rem; color: #9ca3af; margin-bottom: 0.5rem;">Monitoring the Kaspa blockchain for your payment</p>';
    html += '<p id="autoDetectStatus" style="font-size: 0.78rem; color: #6b7280; margin-bottom: 0.5rem;">Checking for incoming transactions...</p>';
    html += '<div id="autoDetectProgress" style="background: rgba(0,0,0,0.3); border-radius: 4px; height: 4px; margin: 0.75rem 0; overflow: hidden;"><div id="autoDetectBar" style="background: #49eacb; height: 100%; width: 0%; transition: width 0.5s;"></div></div>';
    html += '<p style="font-size: 0.7rem; color: #4b5563;">Looking for ~' + parseFloat(kasAmount).toFixed(4) + ' KAS to ' + driverKaspaAddress.substring(0, 20) + '...</p>';
    html += '<div id="verificationStatus" style="margin-top: 1rem;"></div>';
    html += '<div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid rgba(148, 163, 184, 0.1);">';
    html += '<button type="button" onclick="manualTxEntry()" class="btn btn-secondary" style="font-size: 0.78rem; padding: 0.5rem 1rem;">Enter TX Hash Manually</button>';
    html += '</div>';
    html += '</div>';
    
    kaspaSection.innerHTML = html;
    
    // Start auto-detection polling
    pollForIncomingPayment(kasAmount, eurAmount, sinceTimestamp);
}

function pollForIncomingPayment(kasAmount, eurAmount, sinceTimestamp) {
    autoDetectRetryCount++;
    
    // Update progress bar
    var progressBar = document.getElementById('autoDetectBar');
    if (progressBar) {
        progressBar.style.width = Math.min((autoDetectRetryCount / autoDetectMaxRetries) * 100, 100) + '%';
    }
    
    // Update status text
    var statusEl = document.getElementById('autoDetectStatus');
    if (statusEl) {
        statusEl.textContent = 'Scan ' + autoDetectRetryCount + '/' + autoDetectMaxRetries + ' — Waiting for blockchain confirmation...';
    }
    
    // Query our API endpoint that checks for incoming transactions to the driver's address
    var checkUrl = '../api/kaspa_check_incoming.php?address=' + encodeURIComponent(driverKaspaAddress) 
        + '&amount_kas=' + encodeURIComponent(kasAmount) 
        + '&since=' + sinceTimestamp;
    
    fetch(checkUrl)
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.found && data.transaction) {
            // Payment detected!
            var txHash = data.transaction.hash;
            var receivedKas = data.transaction.amount_kas;
            
            if (statusEl) {
                statusEl.textContent = '✓ Payment detected!';
                statusEl.style.color = '#22c55e';
            }
            
            // Now verify and record via the existing verification endpoint
            showPaymentVerifying(txHash);
            verifyAndRecordPayment(txHash, kasAmount, eurAmount);
        } else {
            // Not found yet — retry
            if (autoDetectRetryCount < autoDetectMaxRetries) {
                autoDetectTimer = setTimeout(function() {
                    pollForIncomingPayment(kasAmount, eurAmount, sinceTimestamp);
                }, 5000); // Poll every 5 seconds
            } else {
                // Max retries reached
                if (statusEl) {
                    statusEl.textContent = 'Auto-detection timed out.';
                    statusEl.style.color = '#f59e0b';
                }
                var verifyDiv = document.getElementById('verificationStatus');
                if (verifyDiv) {
                    verifyDiv.innerHTML = '<div style="color: #f59e0b; font-size: 0.82rem; margin-bottom: 0.75rem;">Could not auto-detect payment. The transaction may still be processing.</div>'
                        + '<button type="button" onclick="retryAutoDetect(' + kasAmount + ', ' + eurAmount + ', ' + sinceTimestamp + ')" class="btn btn-primary" style="font-size: 0.82rem; padding: 0.5rem 1rem; margin-right: 0.5rem;">🔄 Retry Detection</button>'
                        + '<button type="button" onclick="manualTxEntry()" class="btn btn-secondary" style="font-size: 0.82rem; padding: 0.5rem 1rem;">Enter TX Hash</button>';
                }
            }
        }
    })
    .catch(function(err) {
        console.error('Auto-detect poll error:', err);
        if (autoDetectRetryCount < autoDetectMaxRetries) {
            autoDetectTimer = setTimeout(function() {
                pollForIncomingPayment(kasAmount, eurAmount, sinceTimestamp);
            }, 5000);
        }
    });
}

function retryAutoDetect(kasAmount, eurAmount, sinceTimestamp) {
    autoDetectRetryCount = 0;
    autoDetectMaxRetries = 20;
    showAutoDetectPaymentUI(kasAmount, eurAmount, sinceTimestamp);
}

function manualTxEntry() {
    var txId = prompt('Enter the Kaspa transaction hash (64 hex characters):');
    if (!txId || txId.trim() === '') return;
    
    txId = txId.trim();
    if (!/^[a-f0-9]{64}$/i.test(txId)) {
        alert('Invalid transaction hash format. It should be 64 hexadecimal characters.');
        return;
    }
    
    // Cancel auto-detect if running
    if (autoDetectTimer) clearTimeout(autoDetectTimer);
    
    var amountInput = document.getElementById('kaspa_amount_eur');
    var eurAmount = parseFloat(amountInput ? amountInput.value : 0) || 0;
    var kasAmount = kaspaExchangeRate > 0 ? (eurAmount / kaspaExchangeRate).toFixed(8) : 0;
    
    showPaymentVerifying(txId);
    verifyAndRecordPayment(txId, kasAmount, eurAmount);
}

function showPaymentVerifying(txId) {
    var kaspaSection = document.getElementById('kaspaPaymentSection');
    if (!kaspaSection) return;
    
    var verifyingHtml = '<div style="background: linear-gradient(135deg, rgba(73, 234, 203, 0.1), rgba(34, 197, 94, 0.1)); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1.5rem; text-align: center;">';
    verifyingHtml += '<div style="font-size: 2rem; margin-bottom: 0.5rem;">⏳</div>';
    verifyingHtml += '<h4 style="margin: 0.5rem 0; color: #49eacb;">Verifying Transaction...</h4>';
    verifyingHtml += '<p style="font-size: 0.8rem; color: #9ca3af; margin-bottom: 0.5rem;">Checking blockchain for confirmation</p>';
    verifyingHtml += '<p id="retryCount" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 1rem;"></p>';
    verifyingHtml += '<code style="font-size: 0.65rem; color: #6b7280; word-break: break-all;">' + txId + '</code>';
    verifyingHtml += '<div style="margin-top: 0.5rem;"><a href="https://explorer.kaspa.org/txs/' + txId + '" target="_blank" style="font-size: 0.7rem; color: #49eacb;">View on Explorer →</a></div>';
    verifyingHtml += '<div id="verificationStatus" style="margin-top: 1rem;"></div>';
    verifyingHtml += '</div>';
    
    kaspaSection.innerHTML = verifyingHtml;
}

function showPaymentStatus(status, message) {
    var statusDiv = document.getElementById('verificationStatus');
    if (!statusDiv) {
        alert(message);
        return;
    }
    
    var color = status === 'success' ? '#22c55e' : (status === 'error' ? '#ef4444' : '#f59e0b');
    var icon = status === 'success' ? '✓' : (status === 'error' ? '✗' : '⚠');
    
    statusDiv.innerHTML = '<div style="color: ' + color + '; font-weight: 600;">' + icon + ' ' + message + '</div>';
    
    if (status === 'success') {
        statusDiv.innerHTML += '<p style="font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem;">Redirecting...</p>';
        setTimeout(function() {
            location.reload();
        }, 2000);
    }
}

var verificationRetryCount = 0;
var maxVerificationRetries = 12; // 12 retries x 5 seconds = 60 seconds max wait

function verifyAndRecordPayment(txId, kasAmount, eurAmount) {
    var tripId = <?php echo (int)$tripId; ?>;
    verificationRetryCount++;
    
    // Update retry counter display
    var retryEl = document.getElementById('retryCount');
    if (retryEl) {
        retryEl.textContent = 'Attempt ' + verificationRetryCount + ' of ' + maxVerificationRetries + '...';
    }
    
    fetch('../api/kaspa_verify_transaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            transaction_hash: txId,
            trip_id: tripId,
            expected_address: driverKaspaAddress,
            expected_amount_kas: parseFloat(kasAmount)
        })
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success && data.verified) {
            showPaymentStatus('success', 'Payment verified on blockchain!');
            // Record the payment in our system
            recordKaspaPayment(txId, kasAmount, eurAmount);
        } else if (data.status === 'pending') {
            showPaymentStatus('warning', 'Transaction pending confirmation. Please wait...');
            if (verificationRetryCount < maxVerificationRetries) {
                setTimeout(function() {
                    verifyAndRecordPayment(txId, kasAmount, eurAmount);
                }, 3000);
            } else {
                showPaymentStatus('error', 'Verification timed out. Your transaction may still be processing. Check the explorer link above.');
            }
        } else if (data.status === 'not_found') {
            if (verificationRetryCount < maxVerificationRetries) {
                showPaymentStatus('warning', 'Waiting for blockchain indexing...');
                setTimeout(function() {
                    verifyAndRecordPayment(txId, kasAmount, eurAmount);
                }, 5000);
            } else {
                showPaymentStatus('error', 'Transaction not found after ' + maxVerificationRetries + ' attempts. Please check the explorer link above to verify your transaction manually.');
            }
        } else {
            showPaymentStatus('error', data.error || 'Verification failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(err) {
        console.error('Verification error:', err);
        if (verificationRetryCount < maxVerificationRetries) {
            showPaymentStatus('warning', 'Network error, retrying...');
            setTimeout(function() {
                verifyAndRecordPayment(txId, kasAmount, eurAmount);
            }, 3000);
        } else {
            showPaymentStatus('error', 'Failed to verify transaction. Please try again.');
        }
    });
}

function recordKaspaPayment(txId, kasAmount, eurAmount) {
    // Get the KASPA payment method ID
    var paymentSelect = document.getElementById('payment_method_type_id');
    var kaspaMethodId = null;
    
    for (var i = 0; i < paymentSelect.options.length; i++) {
        if (paymentSelect.options[i].dataset.kaspa === 'true') {
            kaspaMethodId = paymentSelect.options[i].value;
            break;
        }
    }
    
    if (!kaspaMethodId) {
        alert('Error: Kaspa payment method not found.');
        return;
    }
    
    // Submit the form with Kaspa payment details
    var form = document.getElementById('paymentForm');
    
    // Set the payment method
    paymentSelect.value = kaspaMethodId;
    
    // Set the amount in EUR
    var amountField = document.getElementById('amount');
    if (amountField) {
        amountField.value = eurAmount.toFixed(2);
    }
    
    // Set the provider reference (TxID)
    var refField = document.getElementById('provider_reference_new');
    if (refField) {
        refField.value = 'KASPA:' + txId;
    }
    
    // Show standard fields temporarily to ensure form validation passes
    var standardFields = document.getElementById('standardPaymentFields');
    if (standardFields) {
        standardFields.style.display = 'block';
    }
    
    // Submit form
    form.submit();
}

// Initialize Kaspa when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Check if Kaspa is pre-selected
    var paymentSelect = document.getElementById('payment_method_type_id');
    if (paymentSelect && paymentSelect.value) {
        toggleKaspaPayment(paymentSelect.value);
    }
});

// ============================================================
// SEGMENT KASPA PAYMENT FUNCTIONS
// ============================================================

var segmentKaspaData = {};

function toggleSegmentKaspa(selectEl) {
    var segmentId = selectEl.dataset.segmentId;
    var kaspaAddress = selectEl.dataset.kaspaAddress;
    var selectedOption = selectEl.options[selectEl.selectedIndex];
    var isKaspa = selectedOption && selectedOption.dataset.kaspa === 'true';
    
    var cashSection = document.getElementById('segmentCashPayment_' + segmentId);
    var kaspaSection = document.getElementById('segmentKaspaPayment_' + segmentId);
    
    if (cashSection) {
        cashSection.style.display = isKaspa ? 'none' : 'flex';
    }
    if (kaspaSection) {
        kaspaSection.style.display = isKaspa ? 'block' : 'none';
        
        if (isKaspa && kaspaAddress) {
            // Initialize segment Kaspa data
            if (!segmentKaspaData[segmentId]) {
                segmentKaspaData[segmentId] = {
                    address: kaspaAddress,
                    rate: null
                };
            }
            loadSegmentKaspaRate(segmentId);
        }
    }
}

function loadSegmentKaspaRate(segmentId) {
    fetch('<?php echo e(url('api/kaspa_api.php')); ?>?action=exchange-rate')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.rateKAStoEUR) {
                segmentKaspaData[segmentId].rate = data.rateKAStoEUR;
                var rateEl = document.getElementById('segmentKasRate_' + segmentId);
                if (rateEl) {
                    rateEl.textContent = '1 KAS = €' + data.rateKAStoEUR.toFixed(4);
                }
                updateSegmentKasAmount(segmentId);
            }
        })
        .catch(function(err) {
            console.error('Error loading segment exchange rate:', err);
            segmentKaspaData[segmentId].rate = 0.10;
            updateSegmentKasAmount(segmentId);
        });
}

function updateSegmentKasAmount(segmentId) {
    var amountInput = document.getElementById('segmentAmount_' + segmentId);
    var kasDisplay = document.getElementById('segmentKasAmount_' + segmentId);
    var rate = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].rate : null;
    
    if (!amountInput || !kasDisplay || !rate) return;
    
    var eurAmount = parseFloat(amountInput.value) || 0;
    var kasAmount = rate > 0 ? (eurAmount / rate).toFixed(4) : 0;
    
    kasDisplay.textContent = kasAmount + ' KAS';
    generateSegmentQR(segmentId, kasAmount);
}

function generateSegmentQR(segmentId, kasAmount) {
    var qrContainer = document.getElementById('segmentQrCode_' + segmentId);
    var address = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].address : null;
    
    if (!qrContainer || !address) return;
    
    var kaspaUri = 'kaspa:' + address.replace('kaspa:', '') + '?amount=' + kasAmount;
    
    if (typeof QRCode !== 'undefined') {
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, {
            text: kaspaUri,
            width: 120,
            height: 120,
            colorDark: '#000000',
            colorLight: '#ffffff'
        });
    }
}

function paySegmentWithKaspa(segmentId) {
    var amountInput = document.getElementById('segmentAmount_' + segmentId);
    var rate = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].rate : null;
    var address = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].address : null;
    
    if (!rate || !address) {
        alert('Kaspa payment not configured for this segment.');
        return;
    }
    
    var eurAmount = parseFloat(amountInput ? amountInput.value : 0) || 0;
    var kasAmount = rate > 0 ? (eurAmount / rate).toFixed(8) : 0;
    var kasAmountSompi = Math.round(parseFloat(kasAmount) * 100000000);
    
    // Detect mobile device
    var isMobile = /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|WPDesktop/i.test(navigator.userAgent);
    
    if (isMobile) {
        // On mobile, open kaspa: URI and auto-detect
        var kaspaUri = 'kaspa:' + address.replace('kaspa:', '') + '?amount=' + kasAmount;
        var paymentInitTime = Math.floor(Date.now() / 1000);
        window.location.href = kaspaUri;
        
        setTimeout(function() {
            startSegmentAutoDetect(segmentId, address, kasAmount, eurAmount, paymentInitTime);
        }, 1500);
        return;
    }
    
    if (typeof window.kasware !== 'undefined') {
        window.kasware.requestAccounts().then(function(accounts) {
            if (!accounts || accounts.length === 0) {
                throw new Error('No account connected');
            }
            return window.kasware.sendKaspa(address, kasAmountSompi);
        }).then(function(result) {
            var txId = null;
            if (typeof result === 'string') {
                if (result.startsWith('{')) {
                    try {
                        var parsed = JSON.parse(result);
                        txId = parsed.id || parsed.txId || parsed.transactionId || parsed.hash;
                    } catch (e) {
                        txId = result;
                    }
                } else {
                    txId = result;
                }
            } else if (result && typeof result === 'object') {
                txId = result.id || result.txId || result.transactionId || result.hash;
            }
            
            if (txId && /^[a-f0-9]{64}$/i.test(txId)) {
                showSegmentVerifying(segmentId, txId);
                verifySegmentPayment(segmentId, txId, kasAmount, eurAmount);
            } else {
                alert('Could not extract transaction ID from wallet.');
            }
        }).catch(function(err) {
            if (err.message && err.message.includes('rejected')) {
                alert('Transaction rejected by user.');
            } else {
                alert('Wallet payment failed: ' + (err.message || 'Unknown error'));
            }
        });
    } else {
        var installModal = confirm('KasWare wallet not found. Click OK to install.');
        if (installModal) {
            window.open('https://kasware.xyz', '_blank');
        }
    }
}

// Segment auto-detect payment
var segmentAutoDetectCount = {};
var segmentAutoDetectTimer = {};

function startSegmentAutoDetect(segmentId, address, kasAmount, eurAmount, sinceTimestamp) {
    segmentAutoDetectCount[segmentId] = 0;
    
    var kaspaSection = document.getElementById('segmentKaspaPayment_' + segmentId);
    if (kaspaSection) {
        kaspaSection.innerHTML = '<div style="background: rgba(73, 234, 203, 0.1); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1rem; text-align: center;">' +
            '<div style="font-size: 1.5rem;">🔍</div>' +
            '<p style="margin: 0.5rem 0; color: #49eacb; font-size: 0.85rem;">Detecting payment...</p>' +
            '<p id="segAutoStatus_' + segmentId + '" style="font-size: 0.75rem; color: #6b7280;">Monitoring blockchain...</p>' +
            '<div style="background: rgba(0,0,0,0.3); border-radius: 4px; height: 4px; margin: 0.5rem 0; overflow: hidden;"><div id="segAutoBar_' + segmentId + '" style="background: #49eacb; height: 100%; width: 0%; transition: width 0.5s;"></div></div>' +
            '<div id="segAutoResult_' + segmentId + '"></div>' +
            '</div>';
    }
    
    pollSegmentPayment(segmentId, address, kasAmount, eurAmount, sinceTimestamp);
}

function pollSegmentPayment(segmentId, address, kasAmount, eurAmount, sinceTimestamp) {
    segmentAutoDetectCount[segmentId] = (segmentAutoDetectCount[segmentId] || 0) + 1;
    var count = segmentAutoDetectCount[segmentId];
    var maxRetries = 30;
    
    var bar = document.getElementById('segAutoBar_' + segmentId);
    if (bar) bar.style.width = Math.min((count / maxRetries) * 100, 100) + '%';
    
    var statusEl = document.getElementById('segAutoStatus_' + segmentId);
    if (statusEl) statusEl.textContent = 'Scan ' + count + '/' + maxRetries;
    
    fetch('../api/kaspa_check_incoming.php?address=' + encodeURIComponent(address) + '&amount_kas=' + encodeURIComponent(kasAmount) + '&since=' + sinceTimestamp)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.found && data.transaction) {
            if (statusEl) { statusEl.textContent = '✓ Payment detected!'; statusEl.style.color = '#22c55e'; }
            showSegmentVerifying(segmentId, data.transaction.hash);
            verifySegmentPayment(segmentId, data.transaction.hash, kasAmount, eurAmount);
        } else if (count < maxRetries) {
            segmentAutoDetectTimer[segmentId] = setTimeout(function() {
                pollSegmentPayment(segmentId, address, kasAmount, eurAmount, sinceTimestamp);
            }, 5000);
        } else {
            var resultDiv = document.getElementById('segAutoResult_' + segmentId);
            if (resultDiv) {
                resultDiv.innerHTML = '<button type="button" onclick="startSegmentAutoDetect(\'' + segmentId + '\', \'' + address + '\', ' + kasAmount + ', ' + eurAmount + ', ' + sinceTimestamp + ')" class="btn btn-sm" style="font-size: 0.75rem; margin-right: 0.5rem;">🔄 Retry</button>';
            }
        }
    })
    .catch(function() {
        if (count < maxRetries) {
            segmentAutoDetectTimer[segmentId] = setTimeout(function() {
                pollSegmentPayment(segmentId, address, kasAmount, eurAmount, sinceTimestamp);
            }, 5000);
        }
    });
}

function confirmSegmentKaspaPayment(segmentId) {
    // Try auto-detect first, with manual fallback
    var amountInput = document.getElementById('segmentAmount_' + segmentId);
    var rate = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].rate : 0.10;
    var address = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].address : '';
    var eurAmount = parseFloat(amountInput ? amountInput.value : 0) || 0;
    var kasAmount = rate > 0 ? (eurAmount / rate).toFixed(8) : 0;
    var sinceTimestamp = Math.floor(Date.now() / 1000) - 300;
    
    startSegmentAutoDetect(segmentId, address, kasAmount, eurAmount, sinceTimestamp);
}

function showSegmentVerifying(segmentId, txId) {
    var kaspaSection = document.getElementById('segmentKaspaPayment_' + segmentId);
    if (!kaspaSection) return;
    
    kaspaSection.innerHTML = '<div style="background: rgba(73, 234, 203, 0.1); border: 1px solid rgba(73, 234, 203, 0.3); border-radius: 8px; padding: 1rem; text-align: center;">' +
        '<div style="font-size: 1.5rem;">⏳</div>' +
        '<p style="margin: 0.5rem 0; color: #49eacb; font-size: 0.85rem;">Verifying...</p>' +
        '<code style="font-size: 0.5rem; color: #6b7280; word-break: break-all;">' + txId + '</code>' +
        '<div id="segmentVerifyStatus_' + segmentId + '" style="margin-top: 0.5rem;"></div>' +
        '</div>';
}

var segmentRetryCount = {};
var maxSegmentRetries = 12;

function verifySegmentPayment(segmentId, txId, kasAmount, eurAmount) {
    if (!segmentRetryCount[segmentId]) segmentRetryCount[segmentId] = 0;
    segmentRetryCount[segmentId]++;
    
    var address = segmentKaspaData[segmentId] ? segmentKaspaData[segmentId].address : '';
    
    fetch('../api/kaspa_verify_transaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            transaction_hash: txId,
            trip_id: tripId,
            segment_id: segmentId,
            expected_address: address,
            expected_amount_kas: parseFloat(kasAmount)
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var statusDiv = document.getElementById('segmentVerifyStatus_' + segmentId);
        
        if (data.success && data.verified) {
            if (statusDiv) statusDiv.innerHTML = '<span style="color: #22c55e;">✓ Verified!</span>';
            // Submit the form
            recordSegmentKaspaPayment(segmentId, txId, kasAmount, eurAmount);
        } else if (segmentRetryCount[segmentId] < maxSegmentRetries) {
            if (statusDiv) statusDiv.innerHTML = '<span style="color: #f59e0b;">Attempt ' + segmentRetryCount[segmentId] + '/' + maxSegmentRetries + '</span>';
            setTimeout(function() {
                verifySegmentPayment(segmentId, txId, kasAmount, eurAmount);
            }, 5000);
        } else {
            if (statusDiv) statusDiv.innerHTML = '<span style="color: #ef4444;">Verification timeout. Check explorer.</span>';
        }
    })
    .catch(function(err) {
        if (segmentRetryCount[segmentId] < maxSegmentRetries) {
            setTimeout(function() {
                verifySegmentPayment(segmentId, txId, kasAmount, eurAmount);
            }, 3000);
        }
    });
}

function recordSegmentKaspaPayment(segmentId, txId, kasAmount, eurAmount) {
    var form = document.getElementById('segmentPaymentForm_' + segmentId);
    if (!form) {
        alert('Form not found. Please refresh and try again.');
        return;
    }
    
    // Find and set the KASPA payment method
    var select = form.querySelector('select[name="payment_method_type_id"]');
    if (select) {
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].dataset.kaspa === 'true') {
                select.value = select.options[i].value;
                break;
            }
        }
    }
    
    // Set amount
    var amountField = form.querySelector('input[name="amount"]');
    if (amountField) {
        amountField.value = eurAmount.toFixed(2);
    }
    
    // Add transaction reference
    var refField = form.querySelector('input[name="provider_reference"]');
    if (refField) {
        refField.value = 'KASPA:' + txId;
    }
    
    // Show standard section for form submission
    var cashSection = document.getElementById('segmentCashPayment_' + segmentId);
    if (cashSection) cashSection.style.display = 'flex';
    
    form.submit();
}

// Add input listeners for segment amounts
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.segment-amount-input').forEach(function(input) {
        input.addEventListener('input', function() {
            var segmentId = this.id.replace('segmentAmount_', '');
            if (segmentKaspaData[segmentId]) {
                updateSegmentKasAmount(segmentId);
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
