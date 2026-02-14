<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Require operator role to access this page
require_login();
require_role('operator');

function renderTable($conn, $tableName, $spName, $params = []) {
    echo "<div class='card' style='margin-bottom: 1.5rem;'>";
    echo "<div class='card-header'>";
    echo "<h2 class='card-title' style='font-size: 1.15rem; color: #f9fafb;'>Table: " . e($tableName) . "</h2>";
    echo "</div>";
    
    $stmt = db_call_procedure($spName, $params);
    
    if ($stmt === false) {
        echo "<div class='flash flash-error'>";
        echo "<div class='flash-text'>Error querying $tableName</div>";
        echo "</div>";
        echo "<pre style='color: var(--color-danger); font-size: 0.85rem;'>";
        print_r(sqlsrv_errors());
        echo "</pre>";
        echo "</div>";
        return;
    }

    echo "<div style='overflow-x: auto;'>";
    echo "<table class='table'>";
    
    $first = true;
    $rowCount = 0;
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if ($first) {
            echo "<thead><tr>";
            foreach (array_keys($row) as $col) {
                echo "<th>" . e($col) . "</th>";
            }
            echo "</tr></thead>";
            echo "<tbody>";
            $first = false;
        }
        echo "<tr>";
        foreach ($row as $key => $val) {
            if ($val instanceof DateTime) {
                $val = $val->format('Y-m-d H:i:s');
            }
            // Truncate long binary/text fields
            if (is_string($val) && strlen($val) > 50) {
                $val = substr($val, 0, 47) . '...';
            }
            echo "<td>" . e($val) . "</td>";
        }
        echo "</tr>";
        $rowCount++;
    }
    
    if ($first) {
        echo "<tbody><tr><td colspan='10' style='text-align: center; color: var(--color-text-muted); padding: 1.5rem;'>No data found.</td></tr></tbody>";
    } else {
        echo "</tbody>";
    }
    
    echo "</table>";
    echo "</div>";
    
    if ($rowCount > 0) {
        echo "<div style='margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border-subtle);'>";
        echo "<p style='font-size: 0.85rem; color: var(--color-text-muted); margin: 0;'>Total rows: " . $rowCount . "</p>";
        echo "</div>";
    }
    
    echo "</div>";
    sqlsrv_free_stmt($stmt);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Viewer - OSRH</title>
    <style>
        :root {
            --color-bg: #020617;
            --color-surface: #0b1120;
            --color-border-subtle: #1e293b;
            --color-text: #e5e7eb;
            --color-text-muted: #94a3b8;
            --color-accent: #3b82f6;
            --color-danger: #ef4444;
            --shadow-soft: 0 18px 35px rgba(15, 23, 42, 0.85);
            --radius-lg: 1.2rem;
            --transition-fast: 0.15s ease-out;
            --font-sans: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", sans-serif;
        }
        
        * { box-sizing: border-box; }
        
        html, body { margin: 0; padding: 0; min-height: 100%; }
        
        body {
            font-family: var(--font-sans);
            background: radial-gradient(circle at top left, #1e293b 0, #020617 45%, #000000 100%);
            color: var(--color-text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }
        
        .app { min-height: 100vh; display: flex; flex-direction: column; }
        
        h1, h2 { color: #f9fafb; margin-top: 0; letter-spacing: 0.03em; }
        h1 { font-size: 1.9rem; font-weight: 700; }
        h2 { font-size: 1.5rem; font-weight: 600; }
        
        .text-muted { color: var(--color-text-muted); }
        
        .navbar {
            position: sticky; top: 0; z-index: 50;
            backdrop-filter: blur(18px);
            background: linear-gradient(to right, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.92));
            border-bottom: 1px solid rgba(148, 163, 184, 0.08);
        }
        
        .navbar-inner {
            max-width: 1160px; margin: 0 auto; padding: 0.65rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        }
        
        .navbar-brand {
            display: flex; align-items: center; gap: 0.55rem;
            font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
            color: #e5e7eb; text-decoration: none;
        }
        
        .navbar-logo {
            width: 28px; height: 28px; border-radius: 0.75rem; padding: 3px;
            background: radial-gradient(circle at 0 0, #3b82f6, #14b8a6, #0f172a);
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.9), 0 14px 28px rgba(15, 23, 42, 0.85);
        }
        
        .navbar-title { font-size: 0.9rem; opacity: 0.92; }
        
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.4rem 0.85rem; border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            font-size: 0.82rem; font-weight: 500; cursor: pointer;
            background: rgba(15, 23, 42, 0.8); color: var(--color-text);
            text-decoration: none;
            transition: all var(--transition-fast);
        }
        
        .btn:hover {
            border-color: #e5e7eb;
            background: rgba(15, 23, 42, 1);
        }
        
        .container {
            width: 100%; max-width: 1160px; margin: 0 auto; padding: 1.5rem 1.25rem;
        }
        
        .app-main { flex: 1; }
        
        .card {
            background: radial-gradient(circle at top left, rgba(15, 23, 42, 1), rgba(15, 23, 42, 0.96));
            border-radius: var(--radius-lg);
            border: 1px solid rgba(148, 163, 184, 0.08);
            box-shadow: var(--shadow-soft);
            padding: 1.25rem 1.4rem;
        }
        
        .card-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .card-title {
            font-size: 1rem; font-weight: 600;
        }
        
        .table {
            width: 100%; border-collapse: collapse; font-size: 0.88rem;
        }
        
        .table th, .table td {
            padding: 0.55rem 0.65rem; text-align: left;
        }
        
        .table th {
            font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--color-text-muted);
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
        }
        
        .table tbody tr:nth-child(even) {
            background: rgba(15, 23, 42, 0.65);
        }
        
        .table tbody tr:hover {
            background: rgba(15, 23, 42, 0.9);
        }
        
        .flash {
            display: flex; align-items: center; justify-content: space-between;
            border-radius: 0.7rem; padding: 0.55rem 0.75rem;
            margin-bottom: 0.4rem; font-size: 0.86rem; border: 1px solid transparent;
        }
        
        .flash-error {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fecaca;
        }
        
        .app-footer {
            border-top: 1px solid rgba(15, 23, 42, 0.95);
            background: radial-gradient(circle at top, #020617 0, #000000 80%);
            color: var(--color-text-muted);
        }
        
        .footer-inner {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.75rem; padding: 0.75rem 0; font-size: 0.8rem;
        }
        
        .footer-meta { opacity: 0.6; }
        
        @media (max-width: 768px) {
            .container { padding: 1.25rem 1rem; }
        }
    </style>
</head>
<body class="theme-dark">
    <div class="app">
        <nav class="navbar">
            <div class="navbar-inner">
                <a href="<?php echo e(url('index.php')); ?>" class="navbar-brand">
                    <div class="navbar-logo"></div>
                    <span class="navbar-title">OSRH Database Viewer</span>
                </a>
                <div class="navbar-links">
                    <div class="navbar-right">
                        <a href="<?php echo e(url('operator/dashboard.php')); ?>" class="btn btn-small btn-outline">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </nav>

        <main class="app-main">
            <div class="container">
                <div class="card" style="margin-bottom: 2rem;">
                    <h1 style="margin-bottom: 0.5rem;">Database Contents Viewer</h1>
                    <p class="text-muted">Review all data currently stored in the OSRH database</p>
                </div>

    <?php
    $conn = db_get_connection();
    if (!$conn) {
        echo "<div class='card'>";
        echo "<div class='flash flash-error'>";
        echo "<div class='flash-text'>Database connection failed.</div>";
        echo "</div>";
        echo "</div>";
        echo "</div></main></div></body></html>";
        exit;
    }

    // 1. Users
    renderTable($conn, 'User', 'dbo.spViewUsers');

    // 2. Roles
    renderTable($conn, 'Passenger', 'dbo.spViewPassengers');
    renderTable($conn, 'Driver', 'dbo.spViewDrivers');
    renderTable($conn, 'Operator', 'dbo.spViewOperators');

    // 3. Password History (Check hashes)
    renderTable($conn, 'PasswordHistory', 'dbo.spViewPasswordHistory');

    // 4. Vehicles
    renderTable($conn, 'Vehicle', 'dbo.spViewVehicles');

    // 5. Rides & Trips
    renderTable($conn, 'RideRequest', 'dbo.spViewRideRequests');
    renderTable($conn, 'Trip', 'dbo.spViewTrips');
    renderTable($conn, 'Payment', 'dbo.spViewPayments');
    
    // 6. Messages & Ratings
    renderTable($conn, 'Message', 'dbo.spViewMessages', [10]);
    renderTable($conn, 'Rating', 'dbo.spViewRatings');

    // 7. Autonomous Vehicles Tables
    echo "<div class='card' style='margin: 2rem 0 1rem 0; padding: 1rem;'>";
    echo "<h2 style='margin: 0; font-size: 1.25rem;'>🤖 Autonomous Vehicles Tables</h2>";
    echo "</div>";
    
    renderTable($conn, 'Geofence', 'dbo.spViewGeofences');
    renderTable($conn, 'AutonomousVehicle', 'dbo.spViewAutonomousVehicles');
    renderTable($conn, 'AutonomousRide', 'dbo.spViewAutonomousRides');

    // 8. CarShare Tables
    echo "<div class='card' style='margin: 2rem 0 1rem 0; padding: 1rem;'>";
    echo "<h2 style='margin: 0; font-size: 1.25rem;'>🚙 CarShare Tables</h2>";
    echo "</div>";
    
    renderTable($conn, 'CarshareVehicleType', 'dbo.spViewCarshareVehicleTypes');
    renderTable($conn, 'CarshareVehicle', 'dbo.spViewCarshareVehicles');
    renderTable($conn, 'CarshareZone', 'dbo.spViewCarshareZones');
    renderTable($conn, 'CarshareCustomer', 'dbo.spViewCarshareCustomers');
    renderTable($conn, 'CarshareBooking', 'dbo.spViewCarshareBookings');
    renderTable($conn, 'CarshareRental', 'dbo.spViewCarshareRentals');

    ?>

            </div>
        </main>

        <footer class="app-footer">
            <div class="container">
                <div class="footer-inner">
                    <div>
                        <strong>OSRH</strong> - On-Demand Shared Ride Hailing System
                    </div>
                    <div class="footer-meta">
                        Database Viewer &copy; <?php echo date('Y'); ?>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>
