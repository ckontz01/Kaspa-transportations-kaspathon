# OSRH Kaspa Ridehailing Platform

OSRH Kaspa Ridehailing is a full-stack smart mobility web platform built in PHP with a T-SQL database backend.Its an open-source alternative for transportation apps such as UBER, BOLT,WAYMO etc. Uses the Kaspa network for payments and several other features.

Core capabilities include:
- Passenger, driver, and operator role-based workflows
- Ride-hailing request, dispatch, trip lifecycle, and messaging
- Geofence-aware routing, bridges, and segmented trips
- Autonomous vehicle ride management
- Carshare operations (zones, bookings, rentals, tele-drive requests)
- Kaspa-based payment configuration and hybrid payment support
- Reporting, safety, audit, and GDPR-related operations

---

## Deployment Context

- This repository can be run locally for development and testing.
- The team’s primary/official deployment is hosted on a university-managed server.
- The application can also be deployed on commercial hosting infrastructure.

---

## Compatibility Matrix

### Web Server Compatibility

The platform runs on local or commercial web servers as long as they support PHP and SQL Server connectivity.

Common supported patterns:
- Apache (Linux/Windows)
- Nginx + PHP-FPM
- IIS (Windows)

### Database Compatibility

- Native target: Microsoft SQL Server (including SQL Server Express).
- Commercial database support: any production database engine/environment that supports T-SQL and the required SQL Server access path from PHP.
- Current PHP integration uses `sqlsrv`/`pdo_sqlsrv`, so SQL Server-compatible connectivity is required at runtime.

---

## Architecture Overview

### Application Layer

- Root-level entry/auth pages: login, registration, profile, errors
- Role modules:
  - `passenger/`
  - `driver/`
  - `operator/`
- Feature modules:
  - `carshare/` + `carshare/api/`
- Centralized configuration under `config/`

### Database Layer

Database scripts are in `Database/` and include:
- Full schema creation (`tables`)
- Performance indexes (`indexes`)
- Stored procedures (`sp`) for operational workflows
- Triggers (`triggers`) for auditing/constraints
- Seed data and simulation/stress data
- Drop scripts for reset/rebuild workflows

---

## How the Platform Works (End-to-End)

This section documents the operational behavior of the platform across all mobility modes.

### 1) Identity and role model

- `User` is the base account.
- Each account can be linked to role tables: `Passenger`, `Driver`, `Operator`.
- Main role UX is separated by module:
  - Passenger flows in `passenger/`
  - Driver flows in `driver/`
  - Operator flows in `operator/`
  - CarShare customer mode in `carshare/`

### 2) Ride-hailing workflow (passenger -> driver)

Passenger request path:
1. Passenger opens `passenger/request_ride.php`.
2. System blocks duplicate active mobility sessions (active driver trip, active autonomous ride, or active carshare booking).
3. Passenger selects pickup/dropoff on map; backend inserts both via `spInsertLocation`.
4. Ride request is created via `spCreateRideRequest` with service type, payment method, accessibility/luggage options, and estimated route metrics.
5. If the trip is multi-geofence and path-based routing is selected, segment generation is performed via `spCalculateRideSegments` (with geofence path search logic through `spFindGeofencePaths`).

Request lifecycle and assignment:
- Passenger waits on `passenger/request_status.php` until a trip is assigned.
- Drivers retrieve eligible requests via `spDriverGetAvailableRideRequests`.
- Driver accepts via `spDriverAcceptRideRequest` (or `spDriverAcceptSegmentRequest` / `spAssignDriverToSegment` for segment journeys).
- Trip then appears in passenger `ride_detail.php` and driver `trip_detail.php` / `trips_assigned.php`.

Trip execution and completion:
- Trip starts via `spStartTrip` / driver status update procedures.
- Trip ends via `spCompleteTrip` (or real-driver conclusion procedures when GPS/manual flow is used).
- Passenger sees final status, can rate the trip, and can complete payment from trip detail.

### 3) Driver city/geofence settings and in-city acceptance model

Driver availability is geofence-bound:
1. Driver opens `driver/settings.php`.
2. Driver selects active vehicle and map location.
3. `spDriverGoOnline` binds the driver/vehicle to the detected district geofence (city area).
4. Driver receives only location-eligible requests for that operational area through availability procedures.
5. Driver can go offline via `spDriverGoOffline`.

Operational implications:
- Driver supply is city/geofence scoped, not globally unconstrained.
- Segment trips allow handoff across districts/bridges when one driver cannot serve full A->B route.

### 4) Autonomous ride workflow

Passenger autonomous request path (`passenger/request_autonomous_ride.php`):
1. Passenger chooses pickup/dropoff and payment method.
2. System enforces no parallel active ride mode (same exclusivity checks as regular rides).
3. Route geometry and zone validity are validated for autonomous operation.
4. Ride is created via `spCreateAutonomousRide` with automatic AV selection (or explicit vehicle if provided).

Autonomous dispatch and tracking:
- AV inventory/selection uses autonomous procedures such as:
  - `spGetAvailableAutonomousVehicles`
  - `spGetNearestAutonomousVehicle`
  - `spCreateAutonomousRide`
- Passenger tracks on `passenger/autonomous_ride_detail.php`.
- Ride status transitions are managed through:
  - `spUpdateAutonomousRideStatus`
  - `spCompleteAutonomousRide`
  - `spCancelAutonomousRide`
- Vehicle telemetry/location updates use `spUpdateAutonomousVehicleLocation`.

Operator AV control:
- Operators manage AV fleet in `operator/autonomous_vehicles.php` and related hub pages.
- Vehicle status actions (available/offline/maintenance) call `spUpdateAutonomousVehicleStatus`.

### 5) CarShare workflow (register -> approve -> book -> tele-drive -> unlock -> rental -> return)

Customer onboarding:
1. Passenger registers as carshare customer in `carshare/register.php`.
2. License/ID docs are uploaded and stored in filesystem; paths are persisted.
3. Operator reviews in `operator/carshare_approvals.php` and sets status (`pending`/`approved`/`rejected`).

Vehicle discovery and reservation:
1. Search via `carshare/api/carshare_search.php` (calls `spCarshareSearchVehicles`).
2. Book via `carshare/api/carshare_book.php`:
   - approval checks
   - active-ride exclusivity checks
   - active-booking checks
   - booking creation (20-minute unlock window)

Tele-drive delivery (optional):
- Customer can request remote repositioning via `carshare/api/tele_drive_request.php`.
- Session progress/speed is tracked in `carshare/api/tele_drive_status.php`.

Start and end rental:
- Start/unlock via `carshare/api/carshare_start.php`.
- End via `carshare/api/carshare_end.php`, which calculates:
  - time cost + distance cost
  - inter-city fees
  - out-of-zone penalties
  - low-fuel penalties
  - geofence crossing fee logic
  - bonus credits for specific return zones
- System updates booking/rental/vehicle states and creates carshare payment records.

### 6) Payments model (ride-hailing, segment, autonomous, carshare)

Supported seeded payment methods:
- `CASH`
- `KASPA`

Ride-hailing payment flows:
- Trip-level payment lifecycle uses procedures such as:
  - `spCreatePaymentForTrip`
  - `spCompletePayment`
  - `spCompleteTripPayment`
  - `spProcessTripPayment`
- Segment journeys can use per-segment payments:
  - `spCreatePaymentForSegment`
  - `spGetSegmentPayment`
  - `spCompleteSegmentPayment`

Application behavior:
- Passenger trip detail page handles payment creation and completion.
- Kaspa payment flows include transaction-hash verification checks before completion.
- CarShare generates payment at rental closure.
- Autonomous payment records are tracked in autonomous payment tables/procedures.

### 7) Operator operations workflow

Operator control planes include:
- Main operations dashboard: `operator/dashboard.php`
- Driver operations hub/map/list: `operator/drivers_hub.php`, `operator/driver_map.php`, `operator/drivers.php`
- Safety/compliance: `operator/safety_inspections.php`, document/driver verification views
- CarShare operations hub: approvals, vehicles, zones
- Autonomous operations hub: vehicle map/list/detail and ride monitoring
- Reports: financial, operational, and system logs

Database support includes operator-focused procedures for:
- dashboard stats
- dispatch/assignment visibility
- driver and earnings reports
- carshare approval and lifecycle oversight
- autonomous fleet and ride management

### 8) Seeded data and operator bootstrap

Base seeding (`OSRH_kaspa_seeding.sql`) includes:
- payment methods (Cash, Kaspa)
- service types and mappings
- district geofences and bridges
- autonomous fleet seed (15 vehicles)
- carshare seed set (types, 23 zones, 25 vehicles, operating area polygon)

Operator account bootstrap:
- Base seeding does **not** insert a default operator row directly.
- Bootstrap options provided by the project:
  - register operator via `spRegisterOperator`
  - promote an existing passenger via `spPromotePassengerToOperator`
  - use helper script: `Database/OSRH_kaspa_promote_passenger_to_operator.sql`

Recommended bootstrap sequence after seeding:
1. Create at least one passenger/admin candidate account.
2. Run `OSRH_kaspa_promote_passenger_to_operator.sql` (or call `spRegisterOperator`).
3. Log in through operator module and begin approvals/operations.

---

## Repository Structure

```text
/
├── index.php
├── login.php
├── register_driver.php
├── register_passenger.php
├── profile.php
├── logout.php
├── error.php
├── 404.php
├── config/
│   ├── config.php
│   ├── database.php
│   └── kaspa_config.php
├── passenger/
├── driver/
├── operator/
├── carshare/
│   └── api/
└── Database/
    ├── OSRH_kaspa_tables.sql
    ├── OSRH_kaspa_indexes.sql
    ├── OSRH_kaspa_sp.sql
    ├── OSRH_kaspa_triggers.sql
    ├── OSRH_kaspa_seeding.sql
    ├── OSRH_kaspa_simulated_drivers_seeding.sql
    ├── OSRH_kaspa_stress_test_data.sql
    ├── OSRH_kaspa_performance_tests.sql
    ├── OSRH_kaspa_promote_passenger_to_operator.sql
    ├── OSRH_kaspa_drop_sp.sql
    └── OSRH_kaspa_drop_tables.sql
```

---

## Local Prerequisites

- PHP 8.x (aligned with your local runtime)
- Apache/Nginx/IIS (XAMPP Apache is the quickest local path)
- Microsoft SQL Server / SQL Server Express
- SQL Server PHP drivers enabled:
  - `php_sqlsrv`
  - `php_pdo_sqlsrv`
- SQL Server Management Studio (recommended for schema execution)

---

## Local Setup (Developer Runbook)

### 1) Place project in web root

Example (XAMPP):

```text
C:\xampp\htdocs\osrh
```

### 2) Configure application settings

Edit `config/config.php`:
- `APP_ENV = development`
- `BASE_URL = http://localhost/osrh/` (or your chosen local path)
- `DB_HOST` (example `localhost\SQLEXPRESS`)
- `DB_DATABASE` (default in project configs is `OSRH_DB`)

`config/database.php` uses `sqlsrv_connect(...)`; make sure SQLSRV extension loading is valid in `php.ini`.

### 3) Enable SQLSRV extensions

In `php.ini`, ensure:

```ini
extension=php_sqlsrv.dll
extension=php_pdo_sqlsrv.dll
```

Restart the web server after changes.

### 4) Create database

Create database (example):

```sql
CREATE DATABASE OSRH_DB;
GO
```

### 5) Execute database scripts in order

Use SSMS (or equivalent SQL tool) and run scripts in this order:

1. `Database/OSRH_kaspa_tables.sql`
2. `Database/OSRH_kaspa_indexes.sql`
3. `Database/OSRH_kaspa_sp.sql`
4. `Database/OSRH_kaspa_triggers.sql`
5. `Database/OSRH_kaspa_seeding.sql`

Optional datasets and test tooling:
6. `Database/OSRH_kaspa_simulated_drivers_seeding.sql` (large simulated driver population)
7. `Database/OSRH_kaspa_stress_test_data.sql` (heavy load dataset)
8. `Database/OSRH_kaspa_performance_tests.sql` (query/perf validation)

Operator bootstrap (required for operator dashboards):
9. Run `Database/OSRH_kaspa_promote_passenger_to_operator.sql` after you have a valid passenger to promote, or register directly with `spRegisterOperator`.

### 6) Start web server and run

Open:

```text
http://localhost/osrh/
```

---

## Database Lifecycle Operations

### Clean Rebuild (Dev/Staging Only)

For a full reset:
1. `Database/OSRH_kaspa_drop_sp.sql`
2. `Database/OSRH_kaspa_drop_tables.sql`
3. Re-run setup order from `tables` through `seeding`

Do not execute drop scripts on production systems.

### Role Promotion Utility

To promote a passenger to operator:
- Use `Database/OSRH_kaspa_promote_passenger_to_operator.sql`
- It executes `spPromotePassengerToOperator`

---

## Data Model Scope (High-Level)

The schema includes core domains for:
- Identity and auth (`User`, `PasswordHistory`, role tables)
- Driver/vehicle onboarding and compliance documents
- Geospatial entities (`Location`, `Geofence`, bridge/link tables)
- Ride lifecycle (`RideRequest`, `Trip`, `TripLeg`, logs)
- Payments/ratings/messaging
- GDPR request management and audit logs
- Autonomous ride entities
- Carshare entities (zones, vehicles, bookings, rentals, payments, logs)

---

## Stored Procedure Layer

`Database/OSRH_kaspa_sp.sql` contains extensive operational procedures for:
- Registration/login/security operations
- Driver dispatch and assignment flows
- Trip state transitions and validations
- Fare and dynamic pricing logic
- Segment/geofence routing operations
- Financial and operational reporting
- Autonomous and carshare workflows

This procedure-first approach centralizes business logic in the database for consistency and reporting integrity.

---

## Trigger and Audit Behavior

`Database/OSRH_kaspa_triggers.sql` implements:
- Audit logging for critical transactional tables
- Status-change monitoring
- Semantic validation (example: rating constraints)
- Derived updates (example: driver rating aggregation)

---

## Runtime Entry Points

Main application pages:
- `/index.php`
- `/login.php`
- `/register_driver.php`
- `/register_passenger.php`

Role dashboards:
- Passenger: `/passenger/dashboard.php`
- Driver: `/driver/dashboard.php`
- Operator: `/operator/dashboard.php`

---

## Kaspa Configuration

Kaspa settings are in `config/kaspa_config.php`.

Key parameters include:
- `KASPA_NETWORK`
- `KASPA_API_URL`
- `KASPA_OSRH_WALLET`
- payment confirmation/expiry controls

Use environment variables for production-sensitive values.

---

## Production Hardening Checklist

- Set `APP_ENV` to production
- Disable debug output in production
- Use HTTPS + secure cookie/session settings
- Keep secrets out of source control
- Limit DB permissions by role/environment
- Back up database before schema/data migrations
- Restrict access to admin/operator endpoints by policy

---

## Troubleshooting

### SQLSRV extension missing

- Verify `php_sqlsrv` and `php_pdo_sqlsrv` are enabled
- Ensure extension binaries match PHP version + architecture
- Restart the web server

### Database connection failure

- Validate `DB_HOST` and instance name
- Validate `DB_DATABASE` exists
- Check SQL Server service state and firewall rules
- Test credentials/connectivity in SSMS

### Route/base URL issues

- Ensure project folder path matches `BASE_URL`
- Confirm document root points to the expected directory

---

## Team Note

This repository is suitable for local development and controlled deployments. The team’s official hosted version runs on university infrastructure, while commercial deployments are also possible when runtime and database compatibility requirements are satisfied.

