
/* ============================================================
   DROP ALL TABLES FOR THE RIDE-HAILING SCHEMA
   This script drops ALL foreign key constraints first,
   then drops all tables to avoid dependency errors.
   ============================================================ */

-- Step 1: Drop ALL foreign key constraints in the database
PRINT 'Dropping all foreign key constraints...';

DECLARE @sql NVARCHAR(MAX) = N'';

SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id)) 
    + '.' + QUOTENAME(OBJECT_NAME(parent_object_id)) 
    + ' DROP CONSTRAINT ' + QUOTENAME(name) + ';' + CHAR(13)
FROM sys.foreign_keys;

EXEC sp_executesql @sql;
GO

PRINT 'All foreign key constraints dropped.';
GO

-- Step 2: Drop all tables
-- Drop deepest child tables first
IF OBJECT_ID(N'dbo.DriverLocationHistory', N'U') IS NOT NULL
    DROP TABLE dbo.[DriverLocationHistory];
GO

IF OBJECT_ID(N'dbo.RideSegment', N'U') IS NOT NULL
    DROP TABLE dbo.[RideSegment];
GO

IF OBJECT_ID(N'dbo.VehicleTypePricing', N'U') IS NOT NULL
    DROP TABLE dbo.[VehicleTypePricing];
GO

IF OBJECT_ID(N'dbo.DriverPricing', N'U') IS NOT NULL
    DROP TABLE dbo.[DriverPricing];
GO

IF OBJECT_ID(N'dbo.PricingConfig', N'U') IS NOT NULL
    DROP TABLE dbo.[PricingConfig];
GO

IF OBJECT_ID(N'dbo.PeakHours', N'U') IS NOT NULL
    DROP TABLE dbo.[PeakHours];
GO

IF OBJECT_ID(N'dbo.DemandZone', N'U') IS NOT NULL
    DROP TABLE dbo.[DemandZone];
GO

IF OBJECT_ID(N'dbo.AuditLog', N'U') IS NOT NULL
    DROP TABLE dbo.[AuditLog];
GO

IF OBJECT_ID(N'dbo.GDPRLog', N'U') IS NOT NULL
    DROP TABLE dbo.[GDPRLog];
GO

IF OBJECT_ID(N'dbo.Rating', N'U') IS NOT NULL
    DROP TABLE dbo.[Rating];
GO

IF OBJECT_ID(N'dbo.Message', N'U') IS NOT NULL
    DROP TABLE dbo.[Message];
GO

IF OBJECT_ID(N'dbo.Payment', N'U') IS NOT NULL
    DROP TABLE dbo.[Payment];
GO

IF OBJECT_ID(N'dbo.TripLeg', N'U') IS NOT NULL
    DROP TABLE dbo.[TripLeg];
GO

IF OBJECT_ID(N'dbo.GeofenceLog', N'U') IS NOT NULL
    DROP TABLE dbo.[GeofenceLog];
GO

IF OBJECT_ID(N'dbo.SystemLog', N'U') IS NOT NULL
    DROP TABLE dbo.[SystemLog];
GO

IF OBJECT_ID(N'dbo.DispatchLog', N'U') IS NOT NULL
    DROP TABLE dbo.[DispatchLog];
GO

IF OBJECT_ID(N'dbo.SafetyInspection', N'U') IS NOT NULL
    DROP TABLE dbo.[SafetyInspection];
GO

IF OBJECT_ID(N'dbo.VehicleDocument', N'U') IS NOT NULL
    DROP TABLE dbo.[VehicleDocument];
GO

IF OBJECT_ID(N'dbo.DriverDocument', N'U') IS NOT NULL
    DROP TABLE dbo.[DriverDocument];
GO

IF OBJECT_ID(N'dbo.Trip', N'U') IS NOT NULL
    DROP TABLE dbo.[Trip];
GO

IF OBJECT_ID(N'dbo.GeofenceBridge', N'U') IS NOT NULL
    DROP TABLE dbo.[GeofenceBridge];
GO

IF OBJECT_ID(N'dbo.GeofencePoint', N'U') IS NOT NULL
    DROP TABLE dbo.[GeofencePoint];
GO

IF OBJECT_ID(N'dbo.RideRequest', N'U') IS NOT NULL
    DROP TABLE dbo.[RideRequest];
GO

IF OBJECT_ID(N'dbo.GDPRRequest', N'U') IS NOT NULL
    DROP TABLE dbo.[GDPRRequest];
GO

IF OBJECT_ID(N'dbo.Vehicle', N'U') IS NOT NULL
    DROP TABLE dbo.[Vehicle];
GO

IF OBJECT_ID(N'dbo.Passenger', N'U') IS NOT NULL
    DROP TABLE dbo.[Passenger];
GO

IF OBJECT_ID(N'dbo.Driver', N'U') IS NOT NULL
    DROP TABLE dbo.[Driver];
GO

IF OBJECT_ID(N'dbo.Operator', N'U') IS NOT NULL
    DROP TABLE dbo.[Operator];
GO

IF OBJECT_ID(N'dbo.Geofence', N'U') IS NOT NULL
    DROP TABLE dbo.[Geofence];
GO

IF OBJECT_ID(N'dbo.Location', N'U') IS NOT NULL
    DROP TABLE dbo.[Location];
GO

IF OBJECT_ID(N'dbo.PasswordHistory', N'U') IS NOT NULL
    DROP TABLE dbo.[PasswordHistory];
GO

IF OBJECT_ID(N'dbo.[User]', N'U') IS NOT NULL
    DROP TABLE dbo.[User];
GO

IF OBJECT_ID(N'dbo.VehicleType_ServiceType', N'U') IS NOT NULL
    DROP TABLE dbo.[VehicleType_ServiceType];
GO

IF OBJECT_ID(N'dbo.ServiceTypeRequirements', N'U') IS NOT NULL
    DROP TABLE dbo.[ServiceTypeRequirements];
GO

IF OBJECT_ID(N'dbo.VehicleType', N'U') IS NOT NULL
    DROP TABLE dbo.[VehicleType];
GO

IF OBJECT_ID(N'dbo.ServiceType', N'U') IS NOT NULL
    DROP TABLE dbo.[ServiceType];
GO

IF OBJECT_ID(N'dbo.PaymentMethodType', N'U') IS NOT NULL
    DROP TABLE dbo.[PaymentMethodType];
GO

IF OBJECT_ID(N'dbo.Currency', N'U') IS NOT NULL
    DROP TABLE dbo.[Currency];
GO

-- Carshare Tables (drop child tables first)
IF OBJECT_ID(N'dbo.CarsharePromoCodeUsage', N'U') IS NOT NULL
    DROP TABLE dbo.[CarsharePromoCodeUsage];
GO

IF OBJECT_ID(N'dbo.CarsharePromoCode', N'U') IS NOT NULL
    DROP TABLE dbo.[CarsharePromoCode];
GO

IF OBJECT_ID(N'dbo.CarsharePayment', N'U') IS NOT NULL
    DROP TABLE dbo.[CarsharePayment];
GO

IF OBJECT_ID(N'dbo.CarshareGeofenceViolation', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareGeofenceViolation];
GO

IF OBJECT_ID(N'dbo.CarshareConditionReport', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareConditionReport];
GO

IF OBJECT_ID(N'dbo.CarshareRental', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareRental];
GO

IF OBJECT_ID(N'dbo.CarshareBooking', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareBooking];
GO

IF OBJECT_ID(N'dbo.CarshareVehicleLocationHistory', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareVehicleLocationHistory];
GO

IF OBJECT_ID(N'dbo.CarshareVehicle', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareVehicle];
GO

IF OBJECT_ID(N'dbo.CarshareVehicleType', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareVehicleType];
GO

IF OBJECT_ID(N'dbo.CarshareZonePolygon', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareZonePolygon];
GO

IF OBJECT_ID(N'dbo.CarshareZone', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareZone];
GO

IF OBJECT_ID(N'dbo.CarshareOperatingAreaPolygon', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareOperatingAreaPolygon];
GO

IF OBJECT_ID(N'dbo.CarshareOperatingArea', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareOperatingArea];
GO

IF OBJECT_ID(N'dbo.CarshareCustomer', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareCustomer];
GO

IF OBJECT_ID(N'dbo.CarshareSystemLog', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareSystemLog];
GO

IF OBJECT_ID(N'dbo.CarshareAuditLog', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareAuditLog];
GO

IF OBJECT_ID(N'dbo.CarshareTeleDriveRequest', N'U') IS NOT NULL
    DROP TABLE dbo.[CarshareTeleDriveRequest];
GO

-- Autonomous Vehicle Tables (drop child tables first)
IF OBJECT_ID(N'dbo.AutonomousRidePayment', N'U') IS NOT NULL
    DROP TABLE dbo.[AutonomousRidePayment];
GO

IF OBJECT_ID(N'dbo.AutonomousRideRating', N'U') IS NOT NULL
    DROP TABLE dbo.[AutonomousRideRating];
GO

IF OBJECT_ID(N'dbo.AutonomousVehicleLocationHistory', N'U') IS NOT NULL
    DROP TABLE dbo.[AutonomousVehicleLocationHistory];
GO

IF OBJECT_ID(N'dbo.AutonomousRide', N'U') IS NOT NULL
    DROP TABLE dbo.[AutonomousRide];
GO

IF OBJECT_ID(N'dbo.AutonomousVehicle', N'U') IS NOT NULL
    DROP TABLE dbo.[AutonomousVehicle];
GO

PRINT 'All tables dropped successfully!';
