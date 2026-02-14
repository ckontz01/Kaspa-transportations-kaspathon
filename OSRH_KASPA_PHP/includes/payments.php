<?php
/**
 * OSRH Payment System Helper Functions
 * 
 * Implements payment management as per project requirements:
 * - Payment processing from passengers to drivers
 * - No platform fee (0% commission)
 * - Fare calculation with breakdown
 * - Driver earnings management
 * - Dynamic pricing based on:
 *   - Vehicle type (luxury, standard, cargo)
 *   - Demand (pending requests vs available drivers)
 *   - Route characteristics (time of day, peak hours)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Kaspa Service Fee Rate (0% - No Middleman Fee!)
const OSRH_SERVICE_FEE_RATE = 0.00;

/**
 * Calculate estimated distance between two coordinates using Haversine formula
 * Returns distance in kilometers
 */
function calculate_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371; // Earth's radius in km
    
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    // Multiply by 1.3 to account for road distance (not straight line)
    return round($earthRadius * $c * 1.3, 2);
}

/**
 * Estimate trip duration based on distance
 * Uses average speed of 30 km/h for urban areas
 */
function estimate_duration_minutes(float $distanceKm): int
{
    $avgSpeedKmh = 30; // Average urban speed
    $minutes = ($distanceKm / $avgSpeedKmh) * 60;
    return max(5, (int)ceil($minutes)); // Minimum 5 minutes
}

/**
 * Get pricing configuration for a service type
 */
function get_pricing_config(int $serviceTypeId): ?array
{
    $stmt = db_call_procedure('dbo.spGetPricingConfig', [$serviceTypeId]);
    if ($stmt === false) {
        return null;
    }
    
    $config = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$config) {
        // Return defaults
        return [
            'BaseFare' => 3.00,
            'PricePerKm' => 1.20,
            'PricePerMinute' => 0.20,
            'MinimumFare' => 5.00,
            'ServiceFeeRate' => 0.1500,
            'SurgeMultiplier' => 1.00,
        ];
    }
    
    return $config;
}

/**
 * Calculate fare estimate for a ride
 * Returns array with full breakdown
 */
function calculate_fare_estimate(
    int $serviceTypeId,
    float $distanceKm,
    ?int $durationMinutes = null,
    ?int $driverId = null
): array {
    $stmt = db_call_procedure('dbo.spCalculateFareEstimate', [
        $serviceTypeId,
        $distanceKm,
        $durationMinutes,
        $driverId
    ]);
    
    if ($stmt === false) {
        // Fallback calculation
        $config = get_pricing_config($serviceTypeId);
        $baseFare = $config['BaseFare'] ?? 3.00;
        $pricePerKm = $config['PricePerKm'] ?? 1.20;
        $pricePerMin = $config['PricePerMinute'] ?? 0.20;
        $minFare = $config['MinimumFare'] ?? 5.00;
        $feeRate = $config['ServiceFeeRate'] ?? 0.1500;
        
        $distanceFare = round($distanceKm * $pricePerKm, 2);
        $timeFare = $durationMinutes ? round($durationMinutes * $pricePerMin, 2) : 0;
        $subtotal = $baseFare + $distanceFare + $timeFare;
        $totalFare = max($subtotal, $minFare);
        $serviceFee = round($totalFare * $feeRate, 2);
        $driverEarnings = $totalFare - $serviceFee;
        
        return [
            'BaseFare' => $baseFare,
            'DistanceFare' => $distanceFare,
            'TimeFare' => $timeFare,
            'Subtotal' => $subtotal,
            'SurgeMultiplier' => 1.00,
            'SubtotalWithSurge' => $subtotal,
            'MinimumFare' => $minFare,
            'TotalFare' => $totalFare,
            'ServiceFeeRate' => $feeRate,
            'ServiceFeeAmount' => $serviceFee,
            'DriverEarnings' => $driverEarnings,
            'DistanceKm' => $distanceKm,
            'EstimatedDurationMin' => $durationMinutes,
            'IsSurgeActive' => 0,
            'PricePerKm' => $pricePerKm,
            'PricePerMinute' => $pricePerMin,
        ];
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result ?: [];
}

/**
 * Process payment for a completed trip
 */
function process_trip_payment(
    int $tripId,
    int $paymentMethodTypeId,
    float $tipAmount = 0.00,
    ?string $providerReference = null
): array {
    $stmt = db_call_procedure('dbo.spProcessTripPayment', [
        $tripId,
        $paymentMethodTypeId,
        $tipAmount,
        $providerReference
    ]);
    
    if ($stmt === false) {
        return [
            'success' => false,
            'error' => 'Failed to process payment. Please try again.'
        ];
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if ($result && isset($result['PaymentID'])) {
        return [
            'success' => true,
            'payment' => $result
        ];
    }
    
    return [
        'success' => false,
        'error' => 'Payment processing failed.'
    ];
}

/**
 * Get payment details with full breakdown
 */
function get_payment_details(int $paymentId): ?array
{
    $stmt = db_call_procedure('dbo.spGetPaymentDetails', [$paymentId]);
    if ($stmt === false) {
        return null;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result ?: null;
}

/**
 * Get passenger's payment history
 */
function get_passenger_payment_history(int $passengerId, int $maxRows = 50): array
{
    $stmt = db_call_procedure('dbo.spGetPassengerPaymentHistory', [$passengerId, $maxRows]);
    if ($stmt === false) {
        return [];
    }
    
    $payments = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $payments[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $payments;
}

/**
 * Get driver's earnings summary
 */
function get_driver_earnings_summary(int $driverId, ?string $startDate = null, ?string $endDate = null): array
{
    $stmt = db_call_procedure('dbo.spGetDriverEarningsSummary', [
        $driverId,
        $startDate,
        $endDate
    ]);
    
    if ($stmt === false) {
        return ['summary' => null, 'daily' => []];
    }
    
    // First result: summary
    $summary = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    
    // Second result: daily breakdown
    $daily = [];
    if (sqlsrv_next_result($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $daily[] = $row;
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'summary' => $summary,
        'daily' => $daily
    ];
}

/**
 * Get operator financial report
 */
function get_operator_financial_report(?string $startDate = null, ?string $endDate = null, string $groupBy = 'day'): array
{
    $stmt = db_call_procedure('dbo.spOperatorFinancialReport', [
        $startDate,
        $endDate,
        $groupBy
    ]);
    
    if ($stmt === false) {
        return ['summary' => null, 'breakdown' => []];
    }
    
    // First result: summary
    $summary = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    
    // Second result: grouped breakdown
    $breakdown = [];
    if (sqlsrv_next_result($stmt)) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $breakdown[] = $row;
        }
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'summary' => $summary,
        'breakdown' => $breakdown
    ];
}

/**
 * Get driver earnings report for operators
 */
function get_operator_driver_earnings_report(?string $startDate = null, ?string $endDate = null): array
{
    $stmt = db_call_procedure('dbo.spOperatorDriverEarningsReport', [
        $startDate,
        $endDate
    ]);
    
    if ($stmt === false) {
        return [];
    }
    
    $drivers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $drivers[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $drivers;
}

/**
 * Format currency amount for display
 */
function format_currency(float $amount, string $currency = 'EUR'): string
{
    $symbol = match($currency) {
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        default => $currency . ' '
    };
    
    return $symbol . number_format($amount, 2);
}

/**
 * Get service fee rate as percentage string
 */
function format_fee_rate(float $rate): string
{
    return number_format($rate * 100, 1) . '%';
}

/**
 * Set driver's minimum pricing
 */
function set_driver_minimum_pricing(
    int $driverId,
    float $minimumFare,
    ?int $serviceTypeId = null,
    ?int $vehicleId = null
): bool {
    $stmt = db_call_procedure('dbo.spDriverSetMinimumPricing', [
        $driverId,
        $minimumFare,
        $serviceTypeId,
        $vehicleId
    ]);
    
    if ($stmt === false) {
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return ($result && isset($result['Result']) && $result['Result'] === 'OK');
}

/**
 * Get driver's pricing settings
 */
function get_driver_pricing(int $driverId): array
{
    $stmt = db_call_procedure('dbo.spGetDriverPricing', [$driverId]);
    if ($stmt === false) {
        return [];
    }
    
    $pricing = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pricing[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $pricing;
}

/**
 * Update pricing configuration (for operators)
 */
function update_pricing_config(
    int $serviceTypeId,
    ?float $baseFare = null,
    ?float $pricePerKm = null,
    ?float $pricePerMinute = null,
    ?float $minimumFare = null,
    ?float $serviceFeeRate = null,
    ?float $surgeMultiplier = null
): bool {
    $stmt = db_call_procedure('dbo.spUpdatePricingConfig', [
        $serviceTypeId,
        $baseFare,
        $pricePerKm,
        $pricePerMinute,
        $minimumFare,
        $serviceFeeRate,
        $surgeMultiplier
    ]);
    
    if ($stmt === false) {
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return ($result && isset($result['Result']) && $result['Result'] === 'OK');
}

/**
 * Get all pricing configurations
 */
function get_all_pricing_configs(): array
{
    $stmt = db_call_procedure('dbo.spGetPricingConfig', [null]);
    if ($stmt === false) {
        return [];
    }
    
    $configs = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $configs[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $configs;
}

// ============================================================
// DYNAMIC PRICING FUNCTIONS
// ============================================================

/**
 * Calculate dynamic fare with all pricing factors
 * - Vehicle type multiplier
 * - Demand-based surge
 * - Time-based surge (peak hours)
 * - Driver minimum pricing
 */
function calculate_dynamic_fare(
    int $serviceTypeId,
    float $pickupLat,
    float $pickupLon,
    float $dropoffLat,
    float $dropoffLon,
    ?int $vehicleTypeId = null,
    ?float $distanceKm = null,
    ?int $durationMin = null,
    ?int $driverId = null
): array {
    $stmt = db_call_procedure('dbo.spCalculateDynamicFare', [
        $serviceTypeId,
        $vehicleTypeId,
        $pickupLat,
        $pickupLon,
        $dropoffLat,
        $dropoffLon,
        $distanceKm,
        $durationMin,
        $driverId
    ]);
    
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($result) {
            return $result;
        }
    }
    
    // Fallback to basic calculation if stored procedure fails
    if ($distanceKm === null) {
        $distanceKm = calculate_distance_km($pickupLat, $pickupLon, $dropoffLat, $dropoffLon);
    }
    if ($durationMin === null) {
        $durationMin = estimate_duration_minutes($distanceKm);
    }
    
    return calculate_fare_estimate($serviceTypeId, $distanceKm, $durationMin, $driverId);
}

/**
 * Get current surge status for a location
 */
function get_current_surge_status(?float $latitude = null, ?float $longitude = null): array
{
    $stmt = db_call_procedure('dbo.spGetCurrentSurgeStatus', [$latitude, $longitude]);
    
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($result) {
            return $result;
        }
    }
    
    // Default response
    return [
        'TimeSurge' => 1.00,
        'TimeDescription' => 'Standard Rate',
        'DemandSurge' => 1.00,
        'DemandLevel' => 'Normal',
        'EffectiveSurge' => 1.00,
        'IsSurgeActive' => 0
    ];
}

/**
 * Get demand surge for a location
 */
function get_demand_surge(float $latitude, float $longitude, ?int $serviceTypeId = null): array
{
    $stmt = db_call_procedure('dbo.spCalculateDemandSurge', [$latitude, $longitude, $serviceTypeId]);
    
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($result) {
            return $result;
        }
    }
    
    return [
        'DemandSurge' => 1.00,
        'PendingRequests' => 0,
        'AvailableDrivers' => 0,
        'DemandLevel' => 'Normal'
    ];
}

/**
 * Get time-based surge
 */
function get_time_surge(?string $checkTime = null): array
{
    $stmt = db_call_procedure('dbo.spGetTimeSurge', [$checkTime]);
    
    if ($stmt !== false) {
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($result) {
            return $result;
        }
    }
    
    return [
        'TimeSurge' => 1.00,
        'SurgeDescription' => 'Standard Rate',
        'DayOfWeek' => (int)date('w') + 1,
        'CurrentHour' => (int)date('G')
    ];
}

/**
 * Get vehicle type pricing
 */
function get_vehicle_type_pricing(?int $vehicleTypeId = null): array
{
    $stmt = db_call_procedure('dbo.spGetVehicleTypePricing', [$vehicleTypeId]);
    
    if ($stmt === false) {
        return [];
    }
    
    $pricing = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pricing[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $vehicleTypeId !== null && count($pricing) === 1 ? $pricing[0] : $pricing;
}

/**
 * Get peak hours configuration
 */
function get_peak_hours(): array
{
    $stmt = db_call_procedure('dbo.spGetPeakHours', []);
    
    if ($stmt === false) {
        return [];
    }
    
    $hours = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $hours[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
    return $hours;
}

/**
 * Update peak hours (for operators)
 */
function update_peak_hours(
    int $dayOfWeek,
    int $startHour,
    int $endHour,
    float $surgeMultiplier,
    ?string $description = null,
    bool $isActive = true,
    ?int $peakHourId = null
): bool {
    $stmt = db_call_procedure('dbo.spUpdatePeakHours', [
        $peakHourId,
        $dayOfWeek,
        $startHour,
        $endHour,
        $surgeMultiplier,
        $description,
        $isActive ? 1 : 0
    ]);
    
    if ($stmt === false) {
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return ($result && isset($result['Result']) && $result['Result'] === 'OK');
}

/**
 * Update vehicle type pricing (for operators)
 */
function update_vehicle_type_pricing(
    int $vehicleTypeId,
    float $priceMultiplier,
    ?float $minimumFareOverride = null
): bool {
    $stmt = db_call_procedure('dbo.spUpdateVehicleTypePricing', [
        $vehicleTypeId,
        $priceMultiplier,
        $minimumFareOverride
    ]);
    
    if ($stmt === false) {
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return ($result && isset($result['Result']) && $result['Result'] === 'OK');
}

/**
 * Format surge multiplier for display
 */
function format_surge(float $multiplier): string
{
    if ($multiplier <= 1.0) {
        return 'Standard';
    }
    return number_format($multiplier, 1) . 'x';
}

/**
 * Get surge level CSS class
 */
function get_surge_class(float $multiplier): string
{
    if ($multiplier >= 1.75) return 'surge-very-high';
    if ($multiplier >= 1.35) return 'surge-high';
    if ($multiplier >= 1.10) return 'surge-moderate';
    return 'surge-normal';
}

/**
 * Check if surge pricing is currently active
 */
function is_surge_active(?float $latitude = null, ?float $longitude = null): bool
{
    $status = get_current_surge_status($latitude, $longitude);
    return (bool)($status['IsSurgeActive'] ?? false);
}

// ============================================================
// PAYMENT RECEIPT EMAILS
// ============================================================

/**
 * Compose and send a simple payment receipt email.
 */
function osrh_send_payment_receipt(array $payload): void
{
    $recipient = $payload['email'] ?? null;
    if (!$recipient) {
        return;
    }

    $name        = $payload['name'] ?? 'Customer';
    $subject     = $payload['subject'] ?? 'Payment receipt';
    $amount      = isset($payload['amount']) ? (float)$payload['amount'] : null;
    $currency    = $payload['currency'] ?? 'EUR';
    $method      = $payload['method'] ?? null;
    $reference   = $payload['reference'] ?? null;
    $when        = $payload['when'] ?? null;
    $contextRows = $payload['context'] ?? [];

    $lines = [];
    $lines[] = 'Hello ' . $name . ',';
    $lines[] = '';
    $lines[] = 'We received your payment. Here are the details:';

    if ($amount !== null) {
        $lines[] = '- Amount: ' . format_currency($amount, $currency);
    }
    if ($method) {
        $lines[] = '- Method: ' . $method;
    }
    if ($reference) {
        $lines[] = '- Reference: ' . $reference;
    }
    if ($when !== null) {
        $lines[] = '- Time: ' . osrh_format_cyprus_datetime($when);
    }

    foreach ($contextRows as $row) {
        $lines[] = $row;
    }

    $lines[] = '';
    $lines[] = 'If you have any questions about this payment, reply to this email and we will assist you.';
    $lines[] = '';
    $lines[] = 'Thank you for riding with OSRH!';

    osrh_send_email([$recipient], $subject, implode("\n", $lines));
}
