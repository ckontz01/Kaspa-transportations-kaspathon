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

$rideId = null;
if (isset($_GET['ride_id'])) {
    $rideId = (int)$_GET['ride_id'];
} elseif (isset($_POST['ride_id'])) {
    $rideId = (int)$_POST['ride_id'];
}

if (!$rideId || $rideId <= 0) {
    redirect('error.php?code=404');
}

// Load ride details
function loadAutonomousRide(int $rideId): ?array
{
    $stmt = db_call_procedure('dbo.spGetAutonomousRideDetails', [$rideId]);
    if ($stmt === false) {
        return null;
    }
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $result ?: null;
}

function formatDateTime($value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    return $value !== null ? (string)$value : '';
}

$ride = loadAutonomousRide($rideId);
if (!$ride || (int)$ride['PassengerID'] !== $passengerId) {
    redirect('error.php?code=404');
}

// Check for existing rating
$rating = null;
$stmt = db_call_procedure('dbo.spGetAutonomousRideRating', [$rideId]);
if ($stmt !== false) {
    $rating = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Get payment info
$payment = null;
$stmtPay = db_call_procedure('dbo.spGetAutonomousRidePayment', [$rideId]);
if ($stmtPay !== false) {
    $payment = sqlsrv_fetch_array($stmtPay, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtPay);
}

// Get OSRH platform wallet for Kaspa payments (autonomous vehicles have no driver)
$platformWallet = kaspa_get_platform_wallet();
$kaspaExchangeRate = kaspa_get_exchange_rate('EUR');

// Fire receipt sending as soon as we know payment is complete (auto-created on ride finish)
$totalFareForReceipt = $ride['ActualFare'] ? (float)$ride['ActualFare'] : (float)($ride['EstimatedFare'] ?? 0);
$paymentStatusRaw = $payment['Status'] ?? 'pending';
$paymentStatusLc = strtolower(trim((string)$paymentStatusRaw));
$isPaymentDone = (
    $payment
    && (
        in_array($paymentStatusLc, ['completed', 'complete', 'paid', 'success', 'successful'], true)
        || !empty($payment['CompletedAt'])
        || (($ride['Status'] ?? '') === 'completed' && (float)($payment['Amount'] ?? 0) > 0)
    )
);

if ($isPaymentDone) {
    $receiptSessionKey = $payment && !empty($payment['PaymentID'])
        ? 'av_receipt_sent_payment_' . (int)$payment['PaymentID']
        : 'av_receipt_sent_ride_' . (int)$rideId;

    if (empty($_SESSION[$receiptSessionKey])) {
        try {
            $receiptDetails = ($payment && !empty($payment['PaymentID']))
                ? (get_payment_details((int)$payment['PaymentID']) ?: $payment)
                : $payment;

            $receiptAmount   = $receiptDetails['TotalAmount'] ?? $receiptDetails['Amount'] ?? ($ride['ActualFare'] ?? $totalFareForReceipt);
            $receiptCurrency = $receiptDetails['CurrencyCode'] ?? 'EUR';
            $receiptMethod   = $receiptDetails['PaymentMethodName']
                ?? ($receiptDetails['PaymentMethod'] ?? null)
                ?? (($ride['PaymentMethodCode'] ?? '') === 'KASPA' ? 'Kaspa (KAS)' : 'Cash');
            $receiptRef      = $receiptDetails['ProviderReference'] ?? null;
            $receiptWhen     = $receiptDetails['CompletedAt']
                ?? ($receiptDetails['UpdatedAt'] ?? ($ride['TripCompletedAt'] ?? new DateTime('now', new DateTimeZone('Europe/Nicosia'))));

            osrh_send_payment_receipt([
                'email'    => current_user_email(),
                'name'     => current_user_name() ?? ($ride['PassengerName'] ?? 'Passenger'),
                'subject'  => 'Your autonomous ride receipt',
                'amount'   => $receiptAmount,
                'currency' => $receiptCurrency,
                'method'   => $receiptMethod,
                'reference'=> $receiptRef,
                'when'     => $receiptWhen,
                'context'  => array_filter([
                    (isset($ride['PickupAddress']) || isset($ride['DropoffAddress']))
                        ? '- Route: ' . ($ride['PickupAddress'] ?? 'Pickup') . ' -> ' . ($ride['DropoffAddress'] ?? 'Dropoff')
                        : null,
                    isset($ride['VehicleCode']) ? '- Vehicle: ' . $ride['VehicleCode'] : null,
                ]),
            ]);

            $_SESSION[$receiptSessionKey] = true;
        } catch (Throwable $mailError) {
            error_log('Autonomous ride receipt email failed (pre-render): ' . $mailError->getMessage());
        }
    }
}

$errors = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)array_get($_POST, 'action', '');
    $token  = array_get($_POST, 'csrf_token', null);

    if (!verify_csrf_token($token)) {
        $errors['general'] = 'Security check failed. Please try again.';
    } else {
        if ($action === 'cancel_ride') {
            $reason = trim((string)array_get($_POST, 'cancellation_reason', ''));
            
            $stmt = db_call_procedure('dbo.spCancelAutonomousRide', [
                $rideId,
                $reason !== '' ? $reason : null
            ]);
            
            if ($stmt === false) {
                $errors['general'] = 'Could not cancel ride. Please try again.';
            } else {
                sqlsrv_free_stmt($stmt);
                flash_add('info', 'Your autonomous ride has been cancelled.');
                redirect('passenger/rides_history.php');
            }
        } elseif ($action === 'rate_ride') {
            $stars = (int)array_get($_POST, 'stars', 0);
            $comment = trim((string)array_get($_POST, 'comment', ''));
            $comfortRating = (int)array_get($_POST, 'comfort_rating', 0);
            $safetyRating = (int)array_get($_POST, 'safety_rating', 0);
            $cleanlinessRating = (int)array_get($_POST, 'cleanliness_rating', 0);

            if ($stars < 1 || $stars > 5) {
                $errors['rating'] = 'Rating must be between 1 and 5 stars.';
            }

            if (!$errors) {
                $stmt = db_call_procedure('dbo.spRateAutonomousRide', [
                    $rideId,
                    $passengerId,
                    $stars,
                    $comment !== '' ? $comment : null,
                    $comfortRating > 0 ? $comfortRating : null,
                    $safetyRating > 0 ? $safetyRating : null,
                    $cleanlinessRating > 0 ? $cleanlinessRating : null
                ]);

                if ($stmt === false) {
                    $errors['general'] = 'Could not save rating. Please try again.';
                } else {
                    sqlsrv_free_stmt($stmt);
                    flash_add('success', 'Thank you for rating your autonomous ride!');
                    redirect('passenger/autonomous_ride_detail.php?ride_id=' . $rideId);
                }
            }
        }
    }
}

// Reload ride after any changes
$ride = loadAutonomousRide($rideId);

$status = strtolower($ride['Status'] ?? '');
$isActive = !in_array($status, ['completed', 'cancelled']);

// Create vehicle label for title
$vehicleLabel = trim(($ride['Make'] ?? '') . ' ' . ($ride['Model'] ?? ''));
if (empty($vehicleLabel)) {
    $vehicleLabel = 'Autonomous Ride';
}

$pageTitle = 'Autonomous Ride Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="margin: 2rem auto 1.5rem; max-width: 1100px;">
    <div class="card-header">
        <div>
            <h1 class="card-title">
                <span style="margin-right: 0.4rem;">🤖</span> Autonomous Ride
            </h1>
            <p class="text-muted" style="font-size: 0.86rem; margin-top: 0.25rem;">
                <?php if ($isActive): ?>
                Track your self-driving vehicle in real-time
                <?php else: ?>
                <?php echo e($vehicleLabel); ?> • Ride details and receipt
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash flash-error" style="margin-bottom: 0.75rem;">
            <span class="flash-text"><?php echo e($errors['general']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <?php
    $statusColors = [
        'requested' => ['bg' => '#6366f1', 'text' => 'Requested'],
        'vehicle_dispatched' => ['bg' => '#3b82f6', 'text' => 'Vehicle Dispatched'],
        'vehicle_arriving' => ['bg' => '#8b5cf6', 'text' => 'Vehicle Arriving'],
        'vehicle_arrived' => ['bg' => '#10b981', 'text' => 'Vehicle Arrived - Board Now!'],
        'passenger_boarding' => ['bg' => '#f59e0b', 'text' => 'Boarding'],
        'in_progress' => ['bg' => '#22c55e', 'text' => 'Trip In Progress'],
        'arriving_destination' => ['bg' => '#14b8a6', 'text' => 'Arriving at Destination'],
        'completed' => ['bg' => '#059669', 'text' => 'Completed'],
        'cancelled' => ['bg' => '#ef4444', 'text' => 'Cancelled'],
    ];
    $statusInfo = $statusColors[$status] ?? ['bg' => '#6b7280', 'text' => ucfirst($status)];
    ?>
    
    <?php if ($status === 'completed'): ?>
    <!-- ===================== COMPLETED RIDE VIEW ===================== -->
    
    <!-- Trip Completed Success Banner -->
    <div class="card" style="padding: 1.5rem; margin-bottom: 1rem; background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.4);">
        <div style="text-align: center;">
            <div style="font-size: 3.5rem; margin-bottom: 0.5rem;">🎉</div>
            <div style="font-weight: 600; color: #22c55e; font-size: 1.2rem; margin-bottom: 0.5rem;">
                Autonomous Ride Completed Successfully!
            </div>
            <div style="font-size: 0.9rem; color: #9ca3af; margin-bottom: 1.2rem;">
                Thank you for riding with our autonomous vehicle. We hope you had a great experience!
            </div>
            <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                <a href="<?php echo e(url('passenger/request_autonomous_ride.php')); ?>" class="btn btn-primary">
                    🤖 Book Another Autonomous Ride
                </a>
                <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost">
                    📋 View History
                </a>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 1.4rem;">
        <!-- Left Column - Map & Trip Details -->
        <div>
            <h3 style="font-size: 0.95rem; margin-bottom: 0.6rem;">🗺️ Route Map</h3>
            <div id="ride-map" class="map-container" style="height: 350px;"></div>
            
            <!-- Trip Details Summary -->
            <div class="card" style="padding: 1rem; margin-top: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.75rem 0; color: #94a3b8;">Trip Details</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem;">
                    <div>
                        <span style="color: #6b7280;">From:</span><br>
                        <strong style="color: #22c55e;">📍 <?php echo e($ride['PickupDescription'] ?: $ride['PickupAddress'] ?: 'Pickup Location'); ?></strong>
                    </div>
                    <div>
                        <span style="color: #6b7280;">To:</span><br>
                        <strong style="color: #ef4444;">🏁 <?php echo e($ride['DropoffDescription'] ?: $ride['DropoffAddress'] ?: 'Dropoff Location'); ?></strong>
                    </div>
                </div>
                <?php if ($ride['ActualDistanceKm'] || $ride['ActualDurationSec']): ?>
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #1e293b; display: flex; gap: 1.5rem; font-size: 0.85rem;">
                    <?php if ($ride['ActualDistanceKm']): ?>
                    <div>
                        <span style="color: #6b7280;">Distance:</span>
                        <strong><?php echo number_format((float)$ride['ActualDistanceKm'], 2); ?> km</strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($ride['ActualDurationSec']): ?>
                    <div>
                        <span style="color: #6b7280;">Duration:</span>
                        <strong><?php echo round((int)$ride['ActualDurationSec'] / 60); ?> min</strong>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Timeline -->
            <div class="card" style="padding: 1rem; margin-top: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0;">⏰ Timeline</h4>
                <div style="font-size: 0.82rem;">
                    <table class="table" style="margin: 0;">
                        <tr>
                            <th style="width: 45%;">Requested</th>
                            <td><?php echo e(formatDateTime($ride['RequestedAt'])); ?></td>
                        </tr>
                        <?php if ($ride['VehicleDispatchedAt']): ?>
                        <tr>
                            <th>Vehicle Dispatched</th>
                            <td><?php echo e(formatDateTime($ride['VehicleDispatchedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['VehicleArrivedAt']): ?>
                        <tr>
                            <th>Vehicle Arrived</th>
                            <td><?php echo e(formatDateTime($ride['VehicleArrivedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['TripStartedAt']): ?>
                        <tr>
                            <th>Trip Started</th>
                            <td><?php echo e(formatDateTime($ride['TripStartedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['TripCompletedAt']): ?>
                        <tr style="color: #22c55e;">
                            <th>✅ Completed</th>
                            <td><?php echo e(formatDateTime($ride['TripCompletedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column - Vehicle, Payment, Rating -->
        <div>
            <!-- Vehicle Info -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); border: 1px solid rgba(99, 102, 241, 0.3);">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0; color: #6366f1;">
                    🚗 Your Autonomous Vehicle
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; font-size: 0.85rem;">
                    <div><strong><?php echo e($ride['Make'] . ' ' . $ride['Model']); ?></strong></div>
                    <div style="text-align: right;"><?php echo e($ride['VehicleCode']); ?></div>
                    <div>Color: <?php echo e($ride['Color']); ?></div>
                    <div style="text-align: right;">Plate: <?php echo e($ride['PlateNo']); ?></div>
                </div>
            </div>

            <!-- Payment Section -->
            <h3 style="font-size: 0.95rem; margin-bottom: 0.4rem;">💰 Payment</h3>
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <?php
                $totalAmount = $ride['ActualFare'] ? (float)$ride['ActualFare'] : (float)($ride['EstimatedFare'] ?? 0);
                $paymentStatusRaw = $payment['Status'] ?? 'pending';
                $paymentStatus = strtolower(trim((string)$paymentStatusRaw));
                $isPaymentCompleted = (
                    in_array($paymentStatus, ['completed', 'complete', 'paid', 'success', 'successful'], true)
                    || !empty($payment['CompletedAt'])
                    || ($status === 'completed' && $payment && (float)($payment['Amount'] ?? 0) > 0)
                );

                // Send receipt once per payment (or ride if payment id missing) after auto-created payment
                if ($isPaymentCompleted) {
                    $receiptSessionKey = $payment && !empty($payment['PaymentID'])
                        ? 'av_receipt_sent_payment_' . (int)$payment['PaymentID']
                        : 'av_receipt_sent_ride_' . (int)$rideId;

                    if (empty($_SESSION[$receiptSessionKey])) {
                        try {
                            $receiptDetails = ($payment && !empty($payment['PaymentID']))
                                ? (get_payment_details((int)$payment['PaymentID']) ?: $payment)
                                : $payment;

                            $receiptAmount   = $receiptDetails['TotalAmount'] ?? $receiptDetails['Amount'] ?? ($ride['ActualFare'] ?? $totalAmount);
                            $receiptCurrency = $receiptDetails['CurrencyCode'] ?? 'EUR';
                            $receiptMethod   = $receiptDetails['PaymentMethodName']
                                ?? ($receiptDetails['PaymentMethod'] ?? null)
                                ?? (($ride['PaymentMethodCode'] ?? '') === 'KASPA' ? 'Kaspa (KAS)' : 'Cash');
                            $receiptRef      = $receiptDetails['ProviderReference'] ?? null;
                            $receiptWhen     = $receiptDetails['CompletedAt']
                                ?? ($receiptDetails['UpdatedAt'] ?? ($ride['TripCompletedAt'] ?? new DateTime('now', new DateTimeZone('Europe/Nicosia'))));

                            osrh_send_payment_receipt([
                                'email'    => current_user_email(),
                                'name'     => current_user_name() ?? ($ride['PassengerName'] ?? 'Passenger'),
                                'subject'  => 'Your autonomous ride receipt',
                                'amount'   => $receiptAmount,
                                'currency' => $receiptCurrency,
                                'method'   => $receiptMethod,
                                'reference'=> $receiptRef,
                                'when'     => $receiptWhen,
                                'context'  => array_filter([
                                    (isset($ride['PickupAddress']) || isset($ride['DropoffAddress']))
                                        ? '- Route: ' . ($ride['PickupAddress'] ?? 'Pickup') . ' -> ' . ($ride['DropoffAddress'] ?? 'Dropoff')
                                        : null,
                                    isset($ride['VehicleCode']) ? '- Vehicle: ' . $ride['VehicleCode'] : null,
                                ]),
                            ]);

                            $_SESSION[$receiptSessionKey] = true;
                        } catch (Throwable $mailError) {
                            error_log('Autonomous ride receipt email failed: ' . $mailError->getMessage());
                        }
                    }
                }
                ?>
                
                <!-- Payment Status Badge -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <span class="badge <?php echo $isPaymentCompleted ? 'badge-success' : 'badge-warning'; ?>" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                        <?php echo $isPaymentCompleted ? '✓ Paid' : 'Pending'; ?>
                    </span>
                    <span style="font-size: 1.4rem; font-weight: 700; color: #22c55e;">
                        €<?php echo number_format($totalAmount, 2); ?>
                    </span>
                </div>

                <!-- Fare Breakdown -->
                <div style="background: #0b1120; border: 1px solid #1e293b; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <h4 style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #94a3b8;">Fare Breakdown</h4>
                    <table style="width: 100%; font-size: 0.82rem;">
                        <?php if ($ride['ActualDistanceKm']): ?>
                        <tr>
                            <td style="padding: 0.2rem 0;">Distance (<?php echo number_format((float)$ride['ActualDistanceKm'], 2); ?> km)</td>
                            <td style="text-align: right;">€<?php echo number_format((float)$ride['ActualDistanceKm'] * 0.50, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['ActualDurationSec']): ?>
                        <tr>
                            <td style="padding: 0.2rem 0;">Time (<?php echo round((int)$ride['ActualDurationSec'] / 60); ?> min)</td>
                            <td style="text-align: right;">€<?php echo number_format(((int)$ride['ActualDurationSec'] / 60) * 0.20, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding: 0.2rem 0;">Base Fare</td>
                            <td style="text-align: right;">€2.50</td>
                        </tr>
                        <tr style="border-top: 1px solid #1e293b;">
                            <td style="padding: 0.4rem 0; font-weight: 600; color: #e5e7eb;">Total</td>
                            <td style="text-align: right; font-weight: 600; color: #22c55e;">€<?php echo number_format($totalAmount, 2); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Payment Method -->
                <p style="font-size: 0.82rem; margin: 0; color: #94a3b8;">
                    <strong>Payment Method:</strong> <?php echo e($ride['PaymentMethodCode'] === 'KASPA' ? '💎 Kaspa (KAS)' : '💵 Cash'); ?>
                </p>
                
                <?php if ($isPaymentCompleted): ?>
                <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; border-radius: 6px; padding: 0.75rem; margin-top: 0.75rem;">
                    <span style="color: #22c55e; font-size: 0.85rem;">✓ Payment completed successfully</span>
                </div>
                <?php elseif ($ride['PaymentMethodCode'] === 'KASPA' && $platformWallet && !$isPaymentCompleted): ?>
                <!-- KASPA Payment Panel for Autonomous Rides - Pays to OSRH Platform Wallet -->
                <?php
                $kaspaAmount = $kaspaExchangeRate > 0 ? round($totalAmount / $kaspaExchangeRate, 2) : 0;
                $platformAddress = $platformWallet['WalletAddress'];
                ?>
                <div id="kaspa-payment-panel" style="margin-top: 1rem; padding: 1rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%); border: 1px solid rgba(99, 102, 241, 0.4); border-radius: 8px;">
                    <h4 style="font-size: 0.95rem; color: #a78bfa; margin: 0 0 0.75rem 0;">
                        💎 Pay with Kaspa
                    </h4>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <div>
                            <div style="font-size: 0.8rem; color: #9ca3af;">Amount Due</div>
                            <div style="font-size: 1.3rem; font-weight: 700; color: #a78bfa;">
                                <?php echo number_format($kaspaAmount, 2); ?> KAS
                            </div>
                            <div style="font-size: 0.75rem; color: #6b7280;">
                                ≈ €<?php echo number_format($totalAmount, 2); ?>
                                <span style="color: #4ade80; margin-left: 0.3rem;">
                                    (1 KAS = €<?php echo number_format($kaspaExchangeRate, 4); ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Options -->
                    <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                        <button type="button" onclick="showKaspaQR()" class="btn btn-sm" style="background: #374151; color: white; padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                            📱 QR Code
                        </button>
                        <button type="button" onclick="payWithKasWare()" class="btn btn-sm" style="background: #6366f1; color: white; padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                            💼 Pay with Wallet
                        </button>
                    </div>
                    
                    <!-- QR Code Panel (hidden by default) -->
                    <div id="kaspa-qr-panel" style="display: none; text-align: center; padding: 1rem; background: #0f172a; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="font-size: 0.8rem; color: #9ca3af; margin-bottom: 0.5rem;">
                            Scan with your Kaspa wallet app
                        </div>
                        <div id="kaspa-qr-code" style="display: inline-block; padding: 1rem; background: white; border-radius: 8px; margin-bottom: 0.75rem;"></div>
                        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.5rem;">
                            Platform Wallet:
                        </div>
                        <div style="font-size: 0.7rem; color: #a78bfa; word-break: break-all; padding: 0.5rem; background: rgba(99, 102, 241, 0.1); border-radius: 4px;">
                            <?php echo e($platformAddress); ?>
                        </div>
                        <button type="button" onclick="copyKaspaAddress()" class="btn btn-sm" style="margin-top: 0.5rem; background: #374151; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem;">
                            📋 Copy Address
                        </button>
                    </div>
                    
                    <!-- Payment Status -->
                    <div id="kaspa-payment-status" style="display: none; padding: 0.75rem; border-radius: 6px; font-size: 0.85rem; text-align: center;"></div>
                    
                    <!-- Verify Payment Button -->
                    <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                        <button type="button" onclick="startAutoDetectAutonomous(Math.floor(Date.now()/1000) - 300)" class="btn btn-sm" style="flex: 1; background: #059669; color: white; padding: 0.5rem;">
                            🔍 Detect Payment
                        </button>
                        <button type="button" id="verify-kaspa-btn" onclick="manualTxEntryAutonomous()" class="btn btn-sm" style="flex: 1; background: #374151; color: white; padding: 0.5rem;">
                            ⌨ Enter TX Hash
                        </button>
                    </div>
                    
                    <p style="font-size: 0.7rem; color: #6b7280; margin: 0.75rem 0 0 0; text-align: center;">
                        Payment goes directly to OSRH Platform for autonomous vehicle services
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Rating Section -->
            <h3 style="font-size: 0.95rem; margin-bottom: 0.4rem;">⭐ Your Rating</h3>
            <div class="card" style="padding: 1rem;">
                <?php if (!empty($errors['rating'])): ?>
                    <div class="form-error" style="margin-bottom: 0.45rem;"><?php echo e($errors['rating']); ?></div>
                <?php endif; ?>

                <?php if ($rating): ?>
                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; border-radius: 8px; padding: 1rem;">
                        <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 0.5rem;">
                            <span style="color: #10b981; font-weight: 600;">✅ You rated this ride</span>
                        </div>
                        <div style="font-size: 1.5rem; margin-bottom: 0.3rem;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span style="color: <?php echo $i <= (int)$rating['Stars'] ? '#fbbf24' : '#d1d5db'; ?>;">★</span>
                            <?php endfor; ?>
                            <span style="font-size: 0.9rem; color: #94a3b8; margin-left: 0.5rem;"><?php echo e($rating['Stars']); ?>/5</span>
                        </div>
                        <?php if (!empty($rating['Comment'])): ?>
                        <p style="font-size: 0.85rem; color: #9ca3af; margin: 0.5rem 0 0 0; font-style: italic;">
                            "<?php echo e($rating['Comment']); ?>"
                        </p>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted" style="font-size: 0.8rem; margin-top: 0.75rem;">
                        You can update your rating below. Submitting again will overwrite the previous one.
                    </p>
                <?php else: ?>
                    <p class="text-muted" style="font-size: 0.84rem; margin-bottom: 0.75rem;">
                        You haven't rated this ride yet. Share your experience!
                    </p>
                <?php endif; ?>

                <form method="post" class="js-validate" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="rate_ride">
                    <input type="hidden" name="ride_id" value="<?php echo e($rideId); ?>">

                    <div class="form-group">
                        <label class="form-label">Stars (1–5) <span style="color: #c53030;">*</span></label>
                        <select name="stars" class="form-control" required>
                            <option value="">Select...</option>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?php echo $i; ?>"><?php echo str_repeat('⭐', $i); ?> (<?php echo $i; ?>)</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-top: 0.5rem;">
                        <label class="form-label">Comment (optional)</label>
                        <textarea name="comment" class="form-control" rows="2" placeholder="Tell us about your experience..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-small" style="margin-top: 0.5rem;">
                        <?php echo $rating ? 'Update Rating' : 'Submit Rating'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <?php elseif ($status === 'cancelled'): ?>
    <!-- ===================== CANCELLED RIDE VIEW ===================== -->
    
    <!-- Trip Cancelled Banner -->
    <div class="card" style="padding: 1.5rem; margin-bottom: 1rem; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4);">
        <div style="text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 0.5rem;">❌</div>
            <div style="font-weight: 600; color: #ef4444; font-size: 1.2rem; margin-bottom: 0.5rem;">
                Ride Cancelled
            </div>
            <div style="font-size: 0.9rem; color: #9ca3af; margin-bottom: 1rem;">
                This autonomous ride was cancelled. No charges were applied.
            </div>
            <?php if ($ride['CancellationReason']): ?>
            <div style="font-size: 0.85rem; color: #f87171; margin-bottom: 1rem;">
                Reason: <?php echo e($ride['CancellationReason']); ?>
            </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                <a href="<?php echo e(url('passenger/request_autonomous_ride.php')); ?>" class="btn btn-primary">
                    🤖 Book a New Ride
                </a>
                <a href="<?php echo e(url('passenger/rides_history.php')); ?>" class="btn btn-ghost">
                    📋 View History
                </a>
            </div>
        </div>
    </div>
    
    <!-- Cancelled ride details -->
    <div class="card" style="padding: 1rem;">
        <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0;">Ride Details</h4>
        <div style="font-size: 0.85rem;">
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                <span style="color: #22c55e;">●</span>
                <div>
                    <strong>Pickup:</strong> <?php echo e($ride['PickupDescription'] ?: $ride['PickupAddress'] ?: 'Location set on map'); ?>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <span style="color: #ef4444;">●</span>
                <div>
                    <strong>Dropoff:</strong> <?php echo e($ride['DropoffDescription'] ?: $ride['DropoffAddress'] ?: 'Location set on map'); ?>
                </div>
            </div>
            <?php if ($ride['CancelledAt']): ?>
            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border-subtle); color: #ef4444;">
                Cancelled at: <?php echo e(formatDateTime($ride['CancelledAt'])); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    <!-- ===================== ACTIVE RIDE VIEW ===================== -->
    
    <!-- Active Status Banner -->
    <div style="background: <?php echo $statusInfo['bg']; ?>; color: white; padding: 1rem 1.2rem; border-radius: 10px; margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span class="status-pulse" style="width: 12px; height: 12px; background: white; border-radius: 50%; animation: pulse 1.5s infinite;"></span>
                <div>
                    <div style="font-weight: 600; font-size: 1.1rem;"><?php echo $statusInfo['text']; ?></div>
                    <?php if ($status === 'vehicle_dispatched' || $status === 'vehicle_arriving'): ?>
                    <div style="font-size: 0.85rem; opacity: 0.9;">Your vehicle is on its way to pick you up</div>
                    <?php elseif ($status === 'vehicle_arrived'): ?>
                    <div style="font-size: 0.85rem; opacity: 0.9;">The vehicle has arrived! Please board now.</div>
                    <?php elseif ($status === 'in_progress'): ?>
                    <div style="font-size: 0.85rem; opacity: 0.9;">Enjoy your ride! Sit back and relax.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!in_array($status, ['in_progress', 'arriving_destination'])): ?>
            <button type="button" onclick="showCancelModal()" class="btn" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                Cancel Ride
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 1.4rem;">
        <!-- Map Section -->
        <div>
            <h3 style="font-size: 0.95rem; margin-bottom: 0.6rem;">
                <?php if ($isActive): ?>
                    <span id="map-title">🗺️ Live Tracking</span>
                <?php else: ?>
                    🗺️ Route Map
                <?php endif; ?>
            </h3>
            <div id="ride-map" class="map-container" style="height: 400px;"></div>
            
            <?php if ($isActive): ?>
            <div style="margin-top: 0.75rem; padding: 0.6rem; background: rgba(99, 102, 241, 0.1); border-radius: 6px; font-size: 0.85rem;">
                <div id="live-eta">
                    <span style="color: #6366f1; font-weight: 600;">⏱️ Calculating ETA...</span>
                </div>
            </div>
            
            <!-- Simulation Speed Control (for testing) -->
            <div id="simulation-controls" class="card" style="padding: 0.6rem 1rem; margin-top: 0.75rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 150px;">
                        <label style="font-size: 0.75rem; color: #f59e0b; display: block; margin-bottom: 0.3rem;">
                            ⚡ Simulation Speed: <span id="speed-value">1x</span>
                        </label>
                        <input type="range" id="speed-slider" min="1" max="50" value="1" step="1" 
                               style="width: 100%; cursor: pointer; accent-color: #f59e0b;">
                    </div>
                    <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                        <button type="button" class="speed-btn btn btn-sm" data-speed="1" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #f59e0b; color: white;">1x</button>
                        <button type="button" class="speed-btn btn btn-sm" data-speed="5" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151; color: white;">5x</button>
                        <button type="button" class="speed-btn btn btn-sm" data-speed="10" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151; color: white;">10x</button>
                        <button type="button" class="speed-btn btn btn-sm" data-speed="25" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151; color: white;">25x</button>
                        <button type="button" class="speed-btn btn btn-sm" data-speed="50" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; background: #374151; color: white;">50x</button>
                    </div>
                </div>
                <p style="font-size: 0.65rem; color: #94a3b8; margin: 0.3rem 0 0 0;">
                    🧪 Simulation mode: Speed up time to test the ride. 1x = real-time, 50x = 50× faster.
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Ride Details Section -->
        <div>
            <!-- Vehicle Info -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); border: 1px solid rgba(99, 102, 241, 0.3);">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0; color: #6366f1;">
                    🚗 Your Autonomous Vehicle
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; font-size: 0.85rem;">
                    <div><strong><?php echo e($ride['Make'] . ' ' . $ride['Model']); ?></strong></div>
                    <div style="text-align: right;"><?php echo e($ride['VehicleCode']); ?></div>
                    <div>Color: <?php echo e($ride['Color']); ?></div>
                    <div style="text-align: right;">Plate: <?php echo e($ride['PlateNo']); ?></div>
                    <div>Seats: <?php echo e($ride['SeatingCapacity']); ?></div>
                    <div style="text-align: right;">Battery: <?php echo e($ride['BatteryLevel'] ?? 'N/A'); ?>%</div>
                </div>
            </div>

            <!-- Locations -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0;">📍 Route</h4>
                <div style="font-size: 0.85rem;">
                    <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <span style="color: #22c55e;">●</span>
                        <div>
                            <strong>Pickup:</strong><br>
                            <?php echo e($ride['PickupDescription'] ?: $ride['PickupAddress'] ?: 'Location set on map'); ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <span style="color: #ef4444;">●</span>
                        <div>
                            <strong>Dropoff:</strong><br>
                            <?php echo e($ride['DropoffDescription'] ?: $ride['DropoffAddress'] ?: 'Location set on map'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0;">⏰ Timeline</h4>
                <div style="font-size: 0.82rem;">
                    <table class="table" style="margin: 0;">
                        <tr>
                            <th style="width: 45%;">Requested</th>
                            <td><?php echo e(formatDateTime($ride['RequestedAt'])); ?></td>
                        </tr>
                        <?php if ($ride['VehicleDispatchedAt']): ?>
                        <tr>
                            <th>Vehicle Dispatched</th>
                            <td><?php echo e(formatDateTime($ride['VehicleDispatchedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['VehicleArrivedAt']): ?>
                        <tr>
                            <th>Vehicle Arrived</th>
                            <td><?php echo e(formatDateTime($ride['VehicleArrivedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['TripStartedAt']): ?>
                        <tr>
                            <th>Trip Started</th>
                            <td><?php echo e(formatDateTime($ride['TripStartedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['TripCompletedAt']): ?>
                        <tr>
                            <th>Completed</th>
                            <td><?php echo e(formatDateTime($ride['TripCompletedAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ride['CancelledAt']): ?>
                        <tr>
                            <th style="color: #ef4444;">Cancelled</th>
                            <td style="color: #ef4444;"><?php echo e(formatDateTime($ride['CancelledAt'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Fare / Payment -->
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.6rem 0;">💰 Fare</h4>
                <div style="font-size: 0.85rem;">
                    <?php if ($status === 'completed' && $ride['ActualFare']): ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.3rem;">
                        <span>Total Fare:</span>
                        <strong style="color: #10b981; font-size: 1.2rem;">€<?php echo number_format((float)$ride['ActualFare'], 2); ?></strong>
                    </div>
                    <?php if ($ride['ActualDistanceKm']): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666;">
                        <span>Distance:</span>
                        <span><?php echo number_format((float)$ride['ActualDistanceKm'], 1); ?> km</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($ride['ActualDurationSec']): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666;">
                        <span>Duration:</span>
                        <span><?php echo round((int)$ride['ActualDurationSec'] / 60); ?> min</span>
                    </div>
                    <?php endif; ?>
                    <?php elseif ($ride['EstimatedFare']): ?>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Estimated Fare:</span>
                        <strong>€<?php echo number_format((float)$ride['EstimatedFare'], 2); ?></strong>
                    </div>
                    <div style="font-size: 0.75rem; color: #666; margin-top: 0.3rem;">
                        Final fare calculated upon completion
                    </div>
                    <?php else: ?>
                    <span class="text-muted">Fare will be calculated</span>
                    <?php endif; ?>
                    
                    <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--color-border-subtle); font-size: 0.8rem;">
                        Payment: <?php echo e($ride['PaymentMethodCode'] === 'KASPA' ? '💎 Kaspa (KAS)' : '💵 Cash'); ?>
                    </div>
                </div>
            </div>

            <?php if ($ride['PassengerNotes']): ?>
            <div class="card" style="padding: 1rem; margin-bottom: 1rem;">
                <h4 style="font-size: 0.9rem; margin: 0 0 0.4rem 0;">📝 Notes</h4>
                <p style="font-size: 0.85rem; margin: 0; color: #666;"><?php echo e($ride['PassengerNotes']); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <!-- End of completed/cancelled/active conditional -->
</div>

<!-- Cancel Modal -->
<div id="cancel-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--color-bg); padding: 1.5rem; border-radius: 10px; max-width: 400px; width: 90%;">
        <h3 style="margin: 0 0 1rem 0;">Cancel Ride?</h3>
        <p style="font-size: 0.9rem; color: #666; margin-bottom: 1rem;">Are you sure you want to cancel this autonomous ride?</p>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="cancel_ride">
            <input type="hidden" name="ride_id" value="<?php echo e($rideId); ?>">
            <div class="form-group">
                <label class="form-label">Reason (optional)</label>
                <textarea name="cancellation_reason" class="form-control" rows="2" placeholder="Why are you cancelling?"></textarea>
            </div>
            <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
                <button type="button" onclick="hideCancelModal()" class="btn btn-ghost">Keep Ride</button>
                <button type="submit" class="btn" style="background: #ef4444; color: white;">Cancel Ride</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}
.star-rating label:hover .star,
.star-rating label:hover ~ label .star {
    color: #fbbf24 !important;
}
.star-rating input:checked ~ label .star {
    color: #d1d5db;
}
.star-rating input:checked + label .star,
.star-rating label:has(input:checked) ~ label .star {
    color: #fbbf24;
}
.star { transition: color 0.2s; color: #d1d5db; }
</style>

<script>
function showCancelModal() {
    document.getElementById('cancel-modal').style.display = 'flex';
}
function hideCancelModal() {
    document.getElementById('cancel-modal').style.display = 'none';
}

// Star rating interaction
document.querySelectorAll('.star-rating .star').forEach(function(star) {
    star.addEventListener('click', function() {
        var value = this.dataset.value;
        document.querySelector('.star-rating input[value="' + value + '"]').checked = true;
        document.querySelectorAll('.star-rating .star').forEach(function(s) {
            s.textContent = parseInt(s.dataset.value) <= parseInt(value) ? '★' : '☆';
            s.style.color = parseInt(s.dataset.value) <= parseInt(value) ? '#fbbf24' : '#d1d5db';
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    if (!window.OSRH || typeof window.OSRH.initMap !== 'function') {
        console.error('OSRH map library not loaded');
        return;
    }

    // Ride data from PHP
    var rideData = {
        rideId: <?php echo (int)$rideId; ?>,
        status: '<?php echo e($status); ?>',
        isActive: <?php echo $isActive ? 'true' : 'false'; ?>,
        pickupLat: <?php echo (float)$ride['PickupLat']; ?>,
        pickupLng: <?php echo (float)$ride['PickupLng']; ?>,
        dropoffLat: <?php echo (float)$ride['DropoffLat']; ?>,
        dropoffLng: <?php echo (float)$ride['DropoffLng']; ?>,
        vehicleLat: <?php echo $ride['VehicleCurrentLat'] ? (float)$ride['VehicleCurrentLat'] : 'null'; ?>,
        vehicleLng: <?php echo $ride['VehicleCurrentLng'] ? (float)$ride['VehicleCurrentLng'] : 'null'; ?>,
        vehicleStartLat: <?php echo $ride['VehicleStartLat'] ? (float)$ride['VehicleStartLat'] : 'null'; ?>,
        vehicleStartLng: <?php echo $ride['VehicleStartLng'] ? (float)$ride['VehicleStartLng'] : 'null'; ?>,
        vehicleCode: '<?php echo e($ride['VehicleCode']); ?>',
        simulationPhase: '<?php echo e($ride['SimulationPhase'] ?? ''); ?>',
        simulationStartTime: '<?php echo $ride['SimulationStartTime'] ? formatDateTime($ride['SimulationStartTime']) : ''; ?>',
        estimatedPickupDurationSec: <?php echo (int)($ride['EstimatedPickupDurationSec'] ?? 0); ?>,
        estimatedTripDurationSec: <?php echo (int)($ride['EstimatedTripDurationSec'] ?? 0); ?>,
        pickupRouteGeometry: <?php echo $ride['PickupRouteGeometry'] ? $ride['PickupRouteGeometry'] : 'null'; ?>,
        tripRouteGeometry: <?php echo $ride['TripRouteGeometry'] ? $ride['TripRouteGeometry'] : 'null'; ?>,
        speedMultiplier: <?php echo (float)($ride['SimulationSpeedMultiplier'] ?? 1.0); ?>,
        accumulatedSeconds: <?php echo (float)($ride['AccumulatedSimulatedSeconds'] ?? 0); ?>
    };

    console.log('Ride data:', rideData);

    // Initialize map centered on pickup
    var centerLat = rideData.pickupLat;
    var centerLng = rideData.pickupLng;
    var map = window.OSRH.initMap('ride-map', { lat: centerLat, lng: centerLng, zoom: 14 });

    var pickupMarker = null;
    var dropoffMarker = null;
    var vehicleMarker = null;
    var pickupRouteLine = null;
    var tripRouteLine = null;

    // Add pickup marker - small green circle
    pickupMarker = L.marker([rideData.pickupLat, rideData.pickupLng], {
        icon: L.divIcon({
            className: 'pickup-marker',
            html: '<div style="background: #22c55e; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); font-size: 12px;">📍</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        })
    }).addTo(map).bindPopup('<strong>📍 Pickup Location</strong>');

    // Add dropoff marker - small red circle
    dropoffMarker = L.marker([rideData.dropoffLat, rideData.dropoffLng], {
        icon: L.divIcon({
            className: 'dropoff-marker',
            html: '<div style="background: #ef4444; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3); font-size: 12px;">🏁</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        })
    }).addTo(map).bindPopup('<strong>🏁 Dropoff Location</strong>');

    // Draw routes
    function drawRoutes() {
        // Pickup route (vehicle to pickup) - dashed purple
        if (rideData.pickupRouteGeometry) {
            try {
                var pickupGeom = typeof rideData.pickupRouteGeometry === 'string' 
                    ? JSON.parse(rideData.pickupRouteGeometry) 
                    : rideData.pickupRouteGeometry;
                var pickupCoords = pickupGeom.coordinates.map(function(c) { return [c[1], c[0]]; });
                pickupRouteLine = L.polyline(pickupCoords, {
                    color: '#6366f1',
                    weight: 4,
                    opacity: 0.7,
                    dashArray: '10, 10'
                }).addTo(map);
            } catch (e) {
                console.warn('Could not parse pickup route:', e);
            }
        }

        // Trip route (pickup to dropoff) - solid green
        if (rideData.tripRouteGeometry) {
            try {
                var tripGeom = typeof rideData.tripRouteGeometry === 'string' 
                    ? JSON.parse(rideData.tripRouteGeometry) 
                    : rideData.tripRouteGeometry;
                var tripCoords = tripGeom.coordinates.map(function(c) { return [c[1], c[0]]; });
                tripRouteLine = L.polyline(tripCoords, {
                    color: '#22c55e',
                    weight: 5,
                    opacity: 0.8
                }).addTo(map);
            } catch (e) {
                console.warn('Could not parse trip route:', e);
            }
        }
    }

    drawRoutes();

    // Add vehicle marker - small car icon, click for details
    function updateVehicleMarker(lat, lng) {
        var vehicleIcon = L.divIcon({
            className: 'vehicle-marker',
            html: '<div style="background: #6366f1; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 3px 10px rgba(0,0,0,0.4); font-size: 16px; cursor: pointer;">🚗</div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });

        if (!vehicleMarker) {
            vehicleMarker = L.marker([lat, lng], { icon: vehicleIcon }).addTo(map);
            vehicleMarker.bindPopup('<strong style="color: #6366f1;">' + rideData.vehicleCode + '</strong><br>Your autonomous vehicle');
        } else {
            vehicleMarker.setLatLng([lat, lng]);
        }
    }

    // Initial vehicle position
    if (rideData.vehicleLat && rideData.vehicleLng) {
        updateVehicleMarker(rideData.vehicleLat, rideData.vehicleLng);
    } else if (rideData.vehicleStartLat && rideData.vehicleStartLng) {
        updateVehicleMarker(rideData.vehicleStartLat, rideData.vehicleStartLng);
    }

    // Fit bounds to show everything
    var bounds = L.latLngBounds([
        [rideData.pickupLat, rideData.pickupLng],
        [rideData.dropoffLat, rideData.dropoffLng]
    ]);
    if (vehicleMarker) {
        bounds.extend(vehicleMarker.getLatLng());
    }
    map.fitBounds(bounds.pad(0.15));

    // Live tracking for active rides
    if (rideData.isActive) {
        var pollInterval = setInterval(function() {
            fetch('../api/autonomous_ride_position.php?ride_id=' + rideData.rideId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        console.warn('Position update failed:', data.error);
                        return;
                    }

                    // Debug: Log speed info
                    console.log('Poll response - Speed:', data.speed_multiplier + 'x, Effective:', data.effective_speed + 'x, Progress:', data.progress + '%', 
                        'Simulated:', data.simulated_seconds + 's/', data.total_duration_sec + 's');
                    if (data.debug) {
                        console.log('Debug:', data.debug);
                    }

                    // Update vehicle position
                    if (data.vehicle_lat && data.vehicle_lng) {
                        updateVehicleMarker(data.vehicle_lat, data.vehicle_lng);
                    }

                    // Update ETA display with speed info
                    var etaDiv = document.getElementById('live-eta');
                    if (data.eta_seconds !== null && data.eta_seconds !== undefined) {
                        var etaMins = Math.ceil(data.eta_seconds / 60);
                        var phaseText = data.phase === 'pickup' ? 'Vehicle arrives in' : 'Arriving at destination in';
                        var speedInfo = ' <span style="color: #f59e0b;">(' + data.speed_multiplier + 'x speed)</span>';
                        etaDiv.innerHTML = '<span style="color: #6366f1; font-weight: 600;">⏱️ ' + phaseText + ': ' + etaMins + ' min</span>' + speedInfo +
                            '<br><span style="font-size: 0.75rem; color: #94a3b8;">Progress: ' + data.progress + '% | Simulated: ' + data.simulated_seconds + 's / ' + data.total_duration_sec + 's</span>';
                    }

                    // Check if status changed
                    if (data.status && data.status !== rideData.status) {
                        // Reload page to show updated status
                        location.reload();
                    }

                    // Stop polling if ride is no longer active
                    if (data.status === 'completed' || data.status === 'cancelled') {
                        clearInterval(pollInterval);
                        location.reload();
                    }
                })
                .catch(function(err) {
                    console.warn('Position poll error:', err);
                });
        }, 3000);  // Poll every 3 seconds

        // Setup simulation speed controls
        var speedSlider = document.getElementById('speed-slider');
        var speedValue = document.getElementById('speed-value');
        var speedBtns = document.querySelectorAll('.speed-btn');

        function updateSpeedDisplay(speed) {
            speedValue.textContent = speed + 'x';
            speedSlider.value = Math.min(speed, 50);
            
            // Update button styles
            speedBtns.forEach(function(btn) {
                var btnSpeed = parseInt(btn.dataset.speed);
                if (btnSpeed === speed) {
                    btn.style.background = '#f59e0b';
                    btn.style.color = 'white';
                } else {
                    btn.style.background = '#374151';
                    btn.style.color = 'white';
                }
            });
        }

        function setSimulationSpeed(speed) {
            // Clamp speed to valid range
            speed = Math.max(1, Math.min(50, speed));
            updateSpeedDisplay(speed);
            
            console.log('Setting simulation speed to', speed + 'x');
            
            // Send speed update to server
            fetch('../api/autonomous_ride_position.php?ride_id=' + rideData.rideId + '&set_speed=' + speed)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        console.log('Speed updated to', speed + 'x (effective: ' + (speed * 3) + 'x real-time)');
                    } else {
                        console.warn('Speed update failed:', data.error);
                    }
                })
                .catch(function(err) {
                    console.warn('Failed to update speed:', err);
                });
        }

        // Slider change
        if (speedSlider) {
            speedSlider.addEventListener('input', function() {
                var speed = parseInt(this.value, 10);
                speedValue.textContent = speed + 'x';
                // Also update button highlighting
                speedBtns.forEach(function(btn) {
                    var btnSpeed = parseInt(btn.dataset.speed, 10);
                    if (btnSpeed === speed) {
                        btn.style.background = '#f59e0b';
                    } else {
                        btn.style.background = '#374151';
                    }
                });
            });
            speedSlider.addEventListener('change', function() {
                var speed = parseInt(this.value, 10);
                setSimulationSpeed(speed);
            });
        }

        // Speed buttons
        speedBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var speed = parseInt(this.dataset.speed, 10);
                console.log('Speed button clicked:', speed);
                setSimulationSpeed(speed);
            });
        });

        // Initialize with current speed from database
        var initialSpeed = Math.round(rideData.speedMultiplier) || 1;
        console.log('Initial speed multiplier:', initialSpeed);
        updateSpeedDisplay(initialSpeed);
    }
});

// ============================================================
// KASPA PAYMENT FUNCTIONALITY FOR AUTONOMOUS RIDES
// ============================================================

var kaspaPaymentData = {
    rideId: <?php echo (int)$rideId; ?>,
    amountEur: <?php echo $ride['ActualFare'] ? (float)$ride['ActualFare'] : (float)($ride['EstimatedFare'] ?? 0); ?>,
    amountKas: <?php echo isset($kaspaAmount) ? $kaspaAmount : 0; ?>,
    platformAddress: '<?php echo isset($platformAddress) ? e($platformAddress) : ''; ?>',
    exchangeRate: <?php echo $kaspaExchangeRate ?: 0; ?>,
    paymentMethod: '<?php echo e($ride['PaymentMethodCode'] ?? 'CASH'); ?>'
};

// Show QR Code for Kaspa payment
function showKaspaQR() {
    var qrPanel = document.getElementById('kaspa-qr-panel');
    if (!qrPanel) return;
    
    qrPanel.style.display = 'block';
    
    // Generate QR code if not already done
    var qrContainer = document.getElementById('kaspa-qr-code');
    if (qrContainer && qrContainer.innerHTML === '') {
        if (typeof QRCode !== 'undefined') {
            // Kaspa URI format: kaspa:address?amount=xxx (strip kaspa: prefix if present to avoid double prefix)
            var cleanAddr = kaspaPaymentData.platformAddress.replace('kaspa:', '');
            var kaspaUri = 'kaspa:' + cleanAddr + '?amount=' + kaspaPaymentData.amountKas;
            new QRCode(qrContainer, {
                text: kaspaUri,
                width: 180,
                height: 180,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            qrContainer.innerHTML = '<p style="color: #ef4444;">QR library not loaded</p>';
        }
    }
}

// Copy Kaspa address to clipboard
function copyKaspaAddress() {
    if (!kaspaPaymentData.platformAddress) return;
    
    navigator.clipboard.writeText(kaspaPaymentData.platformAddress).then(function() {
        showKaspaStatus('Address copied to clipboard!', 'info');
    }).catch(function() {
        // Fallback for older browsers
        var textArea = document.createElement('textarea');
        textArea.value = kaspaPaymentData.platformAddress;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showKaspaStatus('Address copied!', 'info');
    });
}

// Pay with KasWare wallet extension
function payWithKasWare() {
    if (!kaspaPaymentData.platformAddress || kaspaPaymentData.amountKas <= 0) {
        showKaspaStatus('Payment data not available', 'error');
        return;
    }

    // Detect mobile device
    var isMobile = /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|WPDesktop/i.test(navigator.userAgent);
    
    // On mobile, open kaspa: URI directly (opens Kaspium or other wallet app)
    if (isMobile) {
        // kaspa: URI uses KAS amount (not sompi)
        var cleanAddress = kaspaPaymentData.platformAddress.replace('kaspa:', '');
        var kaspaUri = 'kaspa:' + cleanAddress + '?amount=' + kaspaPaymentData.amountKas;
        
        // Record the timestamp before opening wallet so we can search for transactions after this time
        var paymentInitTime = Math.floor(Date.now() / 1000);
        
        window.location.href = kaspaUri;
        
        // When user returns from wallet app, auto-detect the payment
        setTimeout(function() {
            startAutoDetectAutonomous(paymentInitTime);
        }, 1500);
        return;
    }
    
    // On desktop, check if KasWare is available
    if (typeof window.kasware === 'undefined') {
        showKaspaStatus('KasWare wallet not detected. Please install the KasWare browser extension.', 'error');
        return;
    }
    
    showKaspaStatus('Connecting to KasWare wallet...', 'info');
    
    // Request accounts from KasWare
    window.kasware.requestAccounts().then(function(accounts) {
        if (!accounts || accounts.length === 0) {
            showKaspaStatus('No wallet accounts found. Please unlock your KasWare wallet.', 'error');
            return;
        }
        
        var fromAddress = accounts[0];
        showKaspaStatus('Initiating payment from ' + fromAddress.substring(0, 20) + '...', 'info');
        
        // Send transaction via KasWare
        // Convert KAS to sompi (1 KAS = 100,000,000 sompi)
        var sompiAmount = Math.round(kaspaPaymentData.amountKas * 100000000);
        
        return window.kasware.sendKaspa(kaspaPaymentData.platformAddress, sompiAmount);
    }).then(function(txId) {
        if (txId) {
            console.log('KasWare transaction sent:', txId);
            showKaspaStatus('Transaction sent! TX: ' + txId.substring(0, 20) + '...', 'success');
            
            // Store transaction ID for verification
            kaspaPaymentData.lastTxId = txId;
            
            // Auto-verify after short delay
            setTimeout(function() {
                verifyKaspaPayment(txId);
            }, 2000);
        }
    }).catch(function(error) {
        console.error('KasWare payment error:', error);
        showKaspaStatus('Payment failed: ' + (error.message || error), 'error');
    });
}

// ============================================================
// AUTO-DETECT PAYMENT (polls blockchain for incoming transactions)
// ============================================================
var autoDetectRetryCount = 0;
var autoDetectMaxRetries = 30; // 30 x 5s = 2.5 minutes
var autoDetectTimer = null;

function startAutoDetectAutonomous(sinceTimestamp) {
    autoDetectRetryCount = 0;
    
    var paymentPanel = document.getElementById('kaspa-payment-panel');
    if (paymentPanel) {
        var html = '<div style="text-align: center; padding: 1rem;">';
        html += '<div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>';
        html += '<div style="color: #49eacb; font-weight: 600; margin-bottom: 0.5rem;">Detecting Payment...</div>';
        html += '<p id="autoDetectStatusAuto" style="font-size: 0.78rem; color: #9ca3af; margin-bottom: 0.5rem;">Monitoring the Kaspa blockchain...</p>';
        html += '<div style="background: rgba(0,0,0,0.3); border-radius: 4px; height: 4px; margin: 0.75rem 0; overflow: hidden;"><div id="autoDetectBarAuto" style="background: #49eacb; height: 100%; width: 0%; transition: width 0.5s;"></div></div>';
        html += '<p style="font-size: 0.7rem; color: #4b5563;">Looking for ~' + parseFloat(kaspaPaymentData.amountKas).toFixed(4) + ' KAS</p>';
        html += '<div id="autoDetectResultAuto" style="margin-top: 0.75rem;"></div>';
        html += '<div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(148, 163, 184, 0.1);">';
        html += '<button type="button" onclick="manualTxEntryAutonomous()" class="btn btn-secondary" style="font-size: 0.78rem; padding: 0.5rem 1rem;">Enter TX Hash Manually</button>';
        html += '</div>';
        html += '</div>';
        paymentPanel.innerHTML = html;
    }
    
    pollForIncomingAutonomous(sinceTimestamp);
}

function pollForIncomingAutonomous(sinceTimestamp) {
    autoDetectRetryCount++;
    
    var progressBar = document.getElementById('autoDetectBarAuto');
    if (progressBar) {
        progressBar.style.width = Math.min((autoDetectRetryCount / autoDetectMaxRetries) * 100, 100) + '%';
    }
    
    var statusEl = document.getElementById('autoDetectStatusAuto');
    if (statusEl) {
        statusEl.textContent = 'Scan ' + autoDetectRetryCount + '/' + autoDetectMaxRetries + ' — Waiting for confirmation...';
    }
    
    var checkUrl = '../api/kaspa_check_incoming.php?address=' + encodeURIComponent(kaspaPaymentData.platformAddress) 
        + '&amount_kas=' + encodeURIComponent(kaspaPaymentData.amountKas) 
        + '&since=' + sinceTimestamp;
    
    fetch(checkUrl)
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.found && data.transaction) {
            // Payment detected!
            var txHash = data.transaction.hash;
            if (statusEl) {
                statusEl.textContent = '✓ Payment detected!';
                statusEl.style.color = '#22c55e';
            }
            showKaspaStatus('Payment detected! Verifying...', 'success');
            // Now verify and record using the existing verification endpoint
            verifyKaspaPayment(txHash);
        } else {
            if (autoDetectRetryCount < autoDetectMaxRetries) {
                autoDetectTimer = setTimeout(function() {
                    pollForIncomingAutonomous(sinceTimestamp);
                }, 5000);
            } else {
                if (statusEl) {
                    statusEl.textContent = 'Auto-detection timed out.';
                    statusEl.style.color = '#f59e0b';
                }
                var resultDiv = document.getElementById('autoDetectResultAuto');
                if (resultDiv) {
                    resultDiv.innerHTML = '<div style="color: #f59e0b; font-size: 0.82rem; margin-bottom: 0.75rem;">Could not auto-detect. The transaction may still be processing.</div>'
                        + '<button type="button" onclick="retryAutoDetectAutonomous(' + sinceTimestamp + ')" class="btn btn-primary" style="font-size: 0.82rem; padding: 0.5rem 1rem; margin-right: 0.5rem;">🔄 Retry</button>'
                        + '<button type="button" onclick="manualTxEntryAutonomous()" class="btn btn-secondary" style="font-size: 0.82rem; padding: 0.5rem 1rem;">Enter TX Hash</button>';
                }
            }
        }
    })
    .catch(function(err) {
        console.error('Auto-detect error:', err);
        if (autoDetectRetryCount < autoDetectMaxRetries) {
            autoDetectTimer = setTimeout(function() {
                pollForIncomingAutonomous(sinceTimestamp);
            }, 5000);
        }
    });
}

function retryAutoDetectAutonomous(sinceTimestamp) {
    autoDetectRetryCount = 0;
    autoDetectMaxRetries = 20;
    startAutoDetectAutonomous(sinceTimestamp);
}

function manualTxEntryAutonomous() {
    if (autoDetectTimer) clearTimeout(autoDetectTimer);
    
    var txId = prompt('Enter the Kaspa transaction hash (64 hex characters):');
    if (!txId || txId.trim() === '') return;
    txId = txId.trim();
    if (!/^[a-f0-9]{64}$/i.test(txId)) {
        alert('Invalid hash format. Must be 64 hex characters.');
        return;
    }
    verifyKaspaPayment(txId);
}

// Verify Kaspa payment
function verifyKaspaPayment(txId) {
    var verifyBtn = document.getElementById('verify-kaspa-btn');
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '⏳ Verifying...';
    }
    
    showKaspaStatus('Verifying payment on Kaspa network...', 'info');
    
    var verifyData = {
        ride_id: kaspaPaymentData.rideId,
        payment_type: 'autonomous',
        amount_kas: kaspaPaymentData.amountKas,
        amount_eur: kaspaPaymentData.amountEur,
        to_address: kaspaPaymentData.platformAddress,
        tx_id: txId || kaspaPaymentData.lastTxId || null
    };
    
    fetch('../api/verify_kaspa_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(verifyData)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            showKaspaStatus('✓ Payment verified successfully!', 'success');
            
            // Update UI to show payment complete
            var paymentPanel = document.getElementById('kaspa-payment-panel');
            if (paymentPanel) {
                paymentPanel.innerHTML = '<div style="text-align: center; padding: 1rem;"><div style="font-size: 2rem; margin-bottom: 0.5rem;">✅</div><div style="color: #22c55e; font-weight: 600;">Payment Completed!</div><div style="font-size: 0.8rem; color: #9ca3af; margin-top: 0.5rem;">Thank you for paying with Kaspa</div></div>';
            }
            
            // Reload page after delay
            setTimeout(function() {
                window.location.reload();
            }, 2000);
        } else {
            showKaspaStatus('Verification pending: ' + (data.message || 'Payment not yet confirmed'), 'warning');
            if (verifyBtn) {
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = '✓ Verify Payment';
            }
        }
    })
    .catch(function(error) {
        console.error('Verification error:', error);
        showKaspaStatus('Could not verify payment. Please try again.', 'error');
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = '✓ Verify Payment';
        }
    });
}

// Show Kaspa payment status message
function showKaspaStatus(message, type) {
    var statusEl = document.getElementById('kaspa-payment-status');
    if (!statusEl) return;
    
    var colors = {
        success: { bg: 'rgba(34, 197, 94, 0.15)', border: '#22c55e', text: '#22c55e' },
        error: { bg: 'rgba(239, 68, 68, 0.15)', border: '#ef4444', text: '#ef4444' },
        warning: { bg: 'rgba(245, 158, 11, 0.15)', border: '#f59e0b', text: '#f59e0b' },
        info: { bg: 'rgba(99, 102, 241, 0.15)', border: '#6366f1', text: '#a78bfa' }
    };
    
    var color = colors[type] || colors.info;
    statusEl.style.display = 'block';
    statusEl.style.background = color.bg;
    statusEl.style.border = '1px solid ' + color.border;
    statusEl.style.color = color.text;
    statusEl.textContent = message;
}
</script>

<!-- QRCode.js library for Kaspa QR codes -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
