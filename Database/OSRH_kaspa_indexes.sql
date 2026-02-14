
-- ============================================================================
-- OSRH Database Indexes
-- Creates indexes for frequently queried columns to improve performance
-- Run this AFTER osrh_tables.sql
-- ============================================================================

-- ============================================================================
-- USER TABLE INDEXES
-- ============================================================================

-- Index for email lookups during login
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_User_Email' AND object_id = OBJECT_ID('dbo.[User]'))
    CREATE NONCLUSTERED INDEX IX_User_Email ON dbo.[User](Email);
GO

-- Index for phone number lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_User_Phone' AND object_id = OBJECT_ID('dbo.[User]'))
    CREATE NONCLUSTERED INDEX IX_User_Phone ON dbo.[User](Phone) WHERE Phone IS NOT NULL;
GO

-- Index for status filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_User_Status' AND object_id = OBJECT_ID('dbo.[User]'))
    CREATE NONCLUSTERED INDEX IX_User_Status ON dbo.[User](Status);
GO

-- ============================================================================
-- DRIVER TABLE INDEXES
-- ============================================================================

-- Index for available driver lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Driver_IsAvailable' AND object_id = OBJECT_ID('dbo.Driver'))
    CREATE NONCLUSTERED INDEX IX_Driver_IsAvailable ON dbo.Driver(IsAvailable, VerificationStatus)
    INCLUDE (UserID, DriverType);
GO

-- Index for verification status queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Driver_VerificationStatus' AND object_id = OBJECT_ID('dbo.Driver'))
    CREATE NONCLUSTERED INDEX IX_Driver_VerificationStatus ON dbo.Driver(VerificationStatus);
GO

-- Index for rating-based queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Driver_RatingAverage' AND object_id = OBJECT_ID('dbo.Driver'))
    CREATE NONCLUSTERED INDEX IX_Driver_RatingAverage ON dbo.Driver(RatingAverage DESC)
    WHERE RatingAverage IS NOT NULL;
GO

-- ============================================================================
-- VEHICLE TABLE INDEXES
-- ============================================================================

-- Index for vehicle type filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Vehicle_VehicleTypeID' AND object_id = OBJECT_ID('dbo.Vehicle'))
    CREATE NONCLUSTERED INDEX IX_Vehicle_VehicleTypeID ON dbo.Vehicle(VehicleTypeID)
    INCLUDE (PlateNo, Make, Model);
GO

-- Index for driver vehicle lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Vehicle_DriverID' AND object_id = OBJECT_ID('dbo.Vehicle'))
    CREATE NONCLUSTERED INDEX IX_Vehicle_DriverID ON dbo.Vehicle(DriverID, IsActive);
GO

-- Index for active vehicle queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Vehicle_IsActive' AND object_id = OBJECT_ID('dbo.Vehicle'))
    CREATE NONCLUSTERED INDEX IX_Vehicle_IsActive ON dbo.Vehicle(IsActive)
    WHERE IsActive = 1;
GO

-- ============================================================================
-- RIDE REQUEST TABLE INDEXES
-- ============================================================================

-- Index for pending request queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_RideRequest_Status' AND object_id = OBJECT_ID('dbo.RideRequest'))
    CREATE NONCLUSTERED INDEX IX_RideRequest_Status ON dbo.RideRequest(Status, RequestedAt DESC)
    INCLUDE (PassengerID, ServiceTypeID);
GO

-- Index for passenger ride history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_RideRequest_PassengerID' AND object_id = OBJECT_ID('dbo.RideRequest'))
    CREATE NONCLUSTERED INDEX IX_RideRequest_PassengerID ON dbo.RideRequest(PassengerID, RequestedAt DESC);
GO

-- Index for service type filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_RideRequest_ServiceTypeID' AND object_id = OBJECT_ID('dbo.RideRequest'))
    CREATE NONCLUSTERED INDEX IX_RideRequest_ServiceTypeID ON dbo.RideRequest(ServiceTypeID);
GO

-- ============================================================================
-- TRIP TABLE INDEXES
-- ============================================================================

-- Index for date-based reporting queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Trip_DispatchTime' AND object_id = OBJECT_ID('dbo.Trip'))
    CREATE NONCLUSTERED INDEX IX_Trip_DispatchTime ON dbo.Trip(DispatchTime DESC)
    INCLUDE (DriverID, VehicleID, Status);
GO

-- Index for status-based queries (finding pending/active trips)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Trip_Status' AND object_id = OBJECT_ID('dbo.Trip'))
    CREATE NONCLUSTERED INDEX IX_Trip_Status ON dbo.Trip(Status)
    INCLUDE (DriverID, RideRequestID, DispatchTime);
GO

-- Index for driver trip history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Trip_DriverID' AND object_id = OBJECT_ID('dbo.Trip'))
    CREATE NONCLUSTERED INDEX IX_Trip_DriverID ON dbo.Trip(DriverID, DispatchTime DESC);
GO

-- Index for vehicle trip history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Trip_VehicleID' AND object_id = OBJECT_ID('dbo.Trip'))
    CREATE NONCLUSTERED INDEX IX_Trip_VehicleID ON dbo.Trip(VehicleID)
    WHERE VehicleID IS NOT NULL;
GO

-- Index for ride request lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Trip_RideRequestID' AND object_id = OBJECT_ID('dbo.Trip'))
    CREATE NONCLUSTERED INDEX IX_Trip_RideRequestID ON dbo.Trip(RideRequestID);
GO

-- ============================================================================
-- TRIPLEG TABLE INDEXES
-- ============================================================================

-- Index for trip leg lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_TripLeg_TripID' AND object_id = OBJECT_ID('dbo.TripLeg'))
    CREATE NONCLUSTERED INDEX IX_TripLeg_TripID ON dbo.TripLeg(TripID, SequenceNo);
GO

-- ============================================================================
-- LOCATION TABLE INDEXES
-- ============================================================================

-- Index for geospatial queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Location_LatLon' AND object_id = OBJECT_ID('dbo.Location'))
    CREATE NONCLUSTERED INDEX IX_Location_LatLon ON dbo.Location(LatDegrees, LonDegrees);
GO

-- Index for postal code filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Location_PostalCode' AND object_id = OBJECT_ID('dbo.Location'))
    CREATE NONCLUSTERED INDEX IX_Location_PostalCode ON dbo.Location(PostalCode)
    WHERE PostalCode IS NOT NULL;
GO

-- ============================================================================
-- PAYMENT TABLE INDEXES
-- ============================================================================

-- Index for payment date-based queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Payment_CreatedAt' AND object_id = OBJECT_ID('dbo.Payment'))
    CREATE NONCLUSTERED INDEX IX_Payment_CreatedAt ON dbo.Payment(CreatedAt DESC)
    INCLUDE (TripID, Amount, Status);
GO

-- Index for payment status lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Payment_Status' AND object_id = OBJECT_ID('dbo.Payment'))
    CREATE NONCLUSTERED INDEX IX_Payment_Status ON dbo.Payment(Status)
    INCLUDE (TripID, Amount, CreatedAt);
GO

-- Index for trip payment lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Payment_TripID' AND object_id = OBJECT_ID('dbo.Payment'))
    CREATE NONCLUSTERED INDEX IX_Payment_TripID ON dbo.Payment(TripID);
GO

-- ============================================================================
-- RATING TABLE INDEXES
-- ============================================================================

-- Index for user rating aggregation
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Rating_ToUserID' AND object_id = OBJECT_ID('dbo.Rating'))
    CREATE NONCLUSTERED INDEX IX_Rating_ToUserID ON dbo.Rating(ToUserID, Stars)
    INCLUDE (TripID, CreatedAt);
GO

-- Index for recent ratings
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Rating_CreatedAt' AND object_id = OBJECT_ID('dbo.Rating'))
    CREATE NONCLUSTERED INDEX IX_Rating_CreatedAt ON dbo.Rating(CreatedAt DESC)
    INCLUDE (FromUserID, ToUserID, Stars);
GO

-- Index for trip ratings
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Rating_TripID' AND object_id = OBJECT_ID('dbo.Rating'))
    CREATE NONCLUSTERED INDEX IX_Rating_TripID ON dbo.Rating(TripID);
GO

-- ============================================================================
-- MESSAGE TABLE INDEXES
-- ============================================================================

-- Index for user inbox queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Message_ToUserID' AND object_id = OBJECT_ID('dbo.Message'))
    CREATE NONCLUSTERED INDEX IX_Message_ToUserID ON dbo.Message(ToUserID, SentAt DESC)
    INCLUDE (FromUserID, TripID);
GO

-- Index for user sent messages
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Message_FromUserID' AND object_id = OBJECT_ID('dbo.Message'))
    CREATE NONCLUSTERED INDEX IX_Message_FromUserID ON dbo.Message(FromUserID, SentAt DESC);
GO

-- Index for trip-related messages
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Message_TripID' AND object_id = OBJECT_ID('dbo.Message'))
    CREATE NONCLUSTERED INDEX IX_Message_TripID ON dbo.Message(TripID)
    WHERE TripID IS NOT NULL;
GO

-- ============================================================================
-- SAFETY INSPECTION INDEXES
-- ============================================================================

-- Index for vehicle inspection history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_SafetyInspection_VehicleID' AND object_id = OBJECT_ID('dbo.SafetyInspection'))
    CREATE NONCLUSTERED INDEX IX_SafetyInspection_VehicleID ON dbo.SafetyInspection(VehicleID, InspectionDate DESC);
GO

-- Index for inspection result filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_SafetyInspection_Result' AND object_id = OBJECT_ID('dbo.SafetyInspection'))
    CREATE NONCLUSTERED INDEX IX_SafetyInspection_Result ON dbo.SafetyInspection(Result)
    INCLUDE (VehicleID, InspectionDate);
GO

-- ============================================================================
-- DRIVER DOCUMENT INDEXES
-- ============================================================================

-- Index for driver document lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DriverDocument_DriverID' AND object_id = OBJECT_ID('dbo.DriverDocument'))
    CREATE NONCLUSTERED INDEX IX_DriverDocument_DriverID ON dbo.DriverDocument(DriverID, DocType);
GO

-- Index for expiring documents
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DriverDocument_ExpiryDate' AND object_id = OBJECT_ID('dbo.DriverDocument'))
    CREATE NONCLUSTERED INDEX IX_DriverDocument_ExpiryDate ON dbo.DriverDocument(ExpiryDate)
    WHERE ExpiryDate IS NOT NULL;
GO

-- ============================================================================
-- VEHICLE DOCUMENT INDEXES
-- ============================================================================

-- Index for vehicle document lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_VehicleDocument_VehicleID' AND object_id = OBJECT_ID('dbo.VehicleDocument'))
    CREATE NONCLUSTERED INDEX IX_VehicleDocument_VehicleID ON dbo.VehicleDocument(VehicleID, DocType);
GO

-- Index for expiring vehicle documents
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_VehicleDocument_ExpiryDate' AND object_id = OBJECT_ID('dbo.VehicleDocument'))
    CREATE NONCLUSTERED INDEX IX_VehicleDocument_ExpiryDate ON dbo.VehicleDocument(ExpiryDate)
    WHERE ExpiryDate IS NOT NULL;
GO

-- ============================================================================
-- GDPR REQUEST INDEXES
-- ============================================================================

-- Index for pending GDPR requests
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_GDPRRequest_Status' AND object_id = OBJECT_ID('dbo.GDPRRequest'))
    CREATE NONCLUSTERED INDEX IX_GDPRRequest_Status ON dbo.GDPRRequest(Status, RequestedAt)
    INCLUDE (UserID);
GO

-- Index for user GDPR request lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_GDPRRequest_UserID' AND object_id = OBJECT_ID('dbo.GDPRRequest'))
    CREATE NONCLUSTERED INDEX IX_GDPRRequest_UserID ON dbo.GDPRRequest(UserID);
GO

-- ============================================================================
-- DISPATCH LOG INDEXES
-- ============================================================================

-- Index for ride request dispatch history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DispatchLog_RideRequestID' AND object_id = OBJECT_ID('dbo.DispatchLog'))
    CREATE NONCLUSTERED INDEX IX_DispatchLog_RideRequestID ON dbo.DispatchLog(RideRequestID, CreatedAt DESC);
GO

-- Index for driver dispatch history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DispatchLog_DriverID' AND object_id = OBJECT_ID('dbo.DispatchLog'))
    CREATE NONCLUSTERED INDEX IX_DispatchLog_DriverID ON dbo.DispatchLog(DriverID, CreatedAt DESC);
GO

-- ============================================================================
-- SYSTEM LOG INDEXES
-- ============================================================================

-- Index for date-based log queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_SystemLog_CreatedAt' AND object_id = OBJECT_ID('dbo.SystemLog'))
    CREATE NONCLUSTERED INDEX IX_SystemLog_CreatedAt ON dbo.SystemLog(CreatedAt DESC)
    INCLUDE (Severity, Category);
GO

-- Index for severity filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_SystemLog_Severity' AND object_id = OBJECT_ID('dbo.SystemLog'))
    CREATE NONCLUSTERED INDEX IX_SystemLog_Severity ON dbo.SystemLog(Severity, CreatedAt DESC);
GO

-- Index for user activity logs
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_SystemLog_UserID' AND object_id = OBJECT_ID('dbo.SystemLog'))
    CREATE NONCLUSTERED INDEX IX_SystemLog_UserID ON dbo.SystemLog(UserID)
    WHERE UserID IS NOT NULL;
GO

-- ============================================================================
-- GEOFENCE INDEXES
-- ============================================================================

-- Index for active geofences
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_Geofence_IsActive' AND object_id = OBJECT_ID('dbo.Geofence'))
    CREATE NONCLUSTERED INDEX IX_Geofence_IsActive ON dbo.Geofence(IsActive)
    WHERE IsActive = 1;
GO

-- Index for geofence log queries
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_GeofenceLog_GeofenceID' AND object_id = OBJECT_ID('dbo.GeofenceLog'))
    CREATE NONCLUSTERED INDEX IX_GeofenceLog_GeofenceID ON dbo.GeofenceLog(GeofenceID, EnteredAt DESC);
GO

-- ============================================================================
-- GEOFENCE POINT INDEXES (Critical for point-in-polygon performance)
-- ============================================================================

-- Index for geofence point lookups - CRITICAL for fnIsPointInGeofence performance
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_GeofencePoint_GeofenceID_SequenceNo' AND object_id = OBJECT_ID('dbo.GeofencePoint'))
    CREATE NONCLUSTERED INDEX IX_GeofencePoint_GeofenceID_SequenceNo 
    ON dbo.GeofencePoint(GeofenceID, SequenceNo)
    INCLUDE (LatDegrees, LonDegrees);
GO

-- ============================================================================
-- PASSWORD HISTORY INDEXES
-- ============================================================================

-- Index for current password lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_PasswordHistory_UserID' AND object_id = OBJECT_ID('dbo.PasswordHistory'))
    CREATE NONCLUSTERED INDEX IX_PasswordHistory_UserID ON dbo.PasswordHistory(UserID, IsCurrent)
    WHERE IsCurrent = 1;
GO

-- ============================================================================
-- DRIVER LOCATION HISTORY INDEXES
-- ============================================================================

-- Indexes for fast queries on driver location history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DriverLocationHistory_Driver_Time' AND object_id = OBJECT_ID('dbo.DriverLocationHistory'))
    CREATE INDEX IX_DriverLocationHistory_Driver_Time 
    ON dbo.[DriverLocationHistory](DriverID, RecordedAt DESC);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_DriverLocationHistory_Trip' AND object_id = OBJECT_ID('dbo.DriverLocationHistory'))
    CREATE INDEX IX_DriverLocationHistory_Trip 
    ON dbo.[DriverLocationHistory](TripID) 
    WHERE TripID IS NOT NULL;
GO

-- ============================================================================
-- AUTONOMOUS VEHICLE INDEXES
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AutonomousVehicle_Status' AND object_id = OBJECT_ID('dbo.AutonomousVehicle'))
    CREATE INDEX IX_AutonomousVehicle_Status 
    ON dbo.[AutonomousVehicle](Status) 
    WHERE IsActive = 1;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AutonomousVehicle_Geofence' AND object_id = OBJECT_ID('dbo.AutonomousVehicle'))
    CREATE INDEX IX_AutonomousVehicle_Geofence 
    ON dbo.[AutonomousVehicle](GeofenceID) 
    WHERE IsActive = 1;
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AutonomousRide_Passenger' AND object_id = OBJECT_ID('dbo.AutonomousRide'))
    CREATE INDEX IX_AutonomousRide_Passenger 
    ON dbo.[AutonomousRide](PassengerID);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AutonomousRide_Vehicle' AND object_id = OBJECT_ID('dbo.AutonomousRide'))
    CREATE INDEX IX_AutonomousRide_Vehicle 
    ON dbo.[AutonomousRide](AutonomousVehicleID);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AutonomousRide_Status' AND object_id = OBJECT_ID('dbo.AutonomousRide'))
    CREATE INDEX IX_AutonomousRide_Status 
    ON dbo.[AutonomousRide](Status);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AVLocationHistory_Vehicle_Time' AND object_id = OBJECT_ID('dbo.AutonomousVehicleLocationHistory'))
    CREATE INDEX IX_AVLocationHistory_Vehicle_Time 
    ON dbo.[AutonomousVehicleLocationHistory](AutonomousVehicleID, RecordedAt DESC);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_AVLocationHistory_Ride' AND object_id = OBJECT_ID('dbo.AutonomousVehicleLocationHistory'))
    CREATE INDEX IX_AVLocationHistory_Ride 
    ON dbo.[AutonomousVehicleLocationHistory](AutonomousRideID) 
    WHERE AutonomousRideID IS NOT NULL;
GO

-- ============================================================================
-- CARSHARE VEHICLE INDEXES
-- ============================================================================

-- Index for finding available vehicles by location (most common query)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicle_StatusLocation' AND object_id = OBJECT_ID('dbo.CarshareVehicle'))
    CREATE NONCLUSTERED INDEX IX_CarshareVehicle_StatusLocation 
    ON dbo.CarshareVehicle (Status, IsActive, CurrentLatitude, CurrentLongitude)
    INCLUDE (VehicleTypeID, CurrentZoneID, FuelLevelPercent, Make, Model, PlateNumber)
    WHERE Status = 'available' AND IsActive = 1;
GO

-- Index for zone-based vehicle lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicle_Zone' AND object_id = OBJECT_ID('dbo.CarshareVehicle'))
    CREATE NONCLUSTERED INDEX IX_CarshareVehicle_Zone
    ON dbo.CarshareVehicle (CurrentZoneID, Status)
    INCLUDE (VehicleID, PlateNumber, Make, Model, FuelLevelPercent)
    WHERE CurrentZoneID IS NOT NULL;
GO

-- Index for vehicle type filtering
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicle_Type' AND object_id = OBJECT_ID('dbo.CarshareVehicle'))
    CREATE NONCLUSTERED INDEX IX_CarshareVehicle_Type
    ON dbo.CarshareVehicle (VehicleTypeID, Status, IsActive)
    INCLUDE (VehicleID, CurrentLatitude, CurrentLongitude, FuelLevelPercent);
GO

-- Index for plate number lookup (unique check)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicle_Plate' AND object_id = OBJECT_ID('dbo.CarshareVehicle'))
    CREATE UNIQUE NONCLUSTERED INDEX IX_CarshareVehicle_Plate
    ON dbo.CarshareVehicle (PlateNumber);
GO

-- Index for vehicles needing attention (maintenance, low fuel, etc.)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicle_NeedsAttention' AND object_id = OBJECT_ID('dbo.CarshareVehicle'))
    CREATE NONCLUSTERED INDEX IX_CarshareVehicle_NeedsAttention
    ON dbo.CarshareVehicle (Status)
    INCLUDE (VehicleID, PlateNumber, FuelLevelPercent, CurrentZoneID)
    WHERE Status IN ('maintenance', 'low_fuel', 'damaged', 'out_of_zone');
GO

-- ============================================================================
-- CARSHARE CUSTOMER INDEXES
-- ============================================================================

-- Index for passenger ID lookup (linking to existing user)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareCustomer_Passenger' AND object_id = OBJECT_ID('dbo.CarshareCustomer'))
    CREATE UNIQUE NONCLUSTERED INDEX IX_CarshareCustomer_Passenger
    ON dbo.CarshareCustomer (PassengerID);
GO

-- Index for verification status (operator workflow)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareCustomer_Verification' AND object_id = OBJECT_ID('dbo.CarshareCustomer'))
    CREATE NONCLUSTERED INDEX IX_CarshareCustomer_Verification
    ON dbo.CarshareCustomer (VerificationStatus)
    INCLUDE (CustomerID, PassengerID, LicenseNumber, CreatedAt)
    WHERE VerificationStatus IN ('pending', 'documents_submitted');
GO

-- Index for membership tier (loyalty programs)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareCustomer_Membership' AND object_id = OBJECT_ID('dbo.CarshareCustomer'))
    CREATE NONCLUSTERED INDEX IX_CarshareCustomer_Membership
    ON dbo.CarshareCustomer (MembershipTier, TotalSpentEUR)
    INCLUDE (CustomerID, LoyaltyPoints, TotalRentals);
GO

-- ============================================================================
-- CARSHARE BOOKING INDEXES
-- ============================================================================

-- Index for customer's active bookings
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareBooking_CustomerActive' AND object_id = OBJECT_ID('dbo.CarshareBooking'))
    CREATE NONCLUSTERED INDEX IX_CarshareBooking_CustomerActive
    ON dbo.CarshareBooking (CustomerID, Status)
    INCLUDE (BookingID, VehicleID, ReservationExpiresAt, PricingMode)
    WHERE Status IN ('reserved', 'active');
GO

-- Index for vehicle's active booking
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareBooking_VehicleActive' AND object_id = OBJECT_ID('dbo.CarshareBooking'))
    CREATE NONCLUSTERED INDEX IX_CarshareBooking_VehicleActive
    ON dbo.CarshareBooking (VehicleID, Status)
    INCLUDE (BookingID, CustomerID, ReservationExpiresAt)
    WHERE Status IN ('reserved', 'active');
GO

-- Index for expired bookings cleanup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareBooking_Expiry' AND object_id = OBJECT_ID('dbo.CarshareBooking'))
    CREATE NONCLUSTERED INDEX IX_CarshareBooking_Expiry
    ON dbo.CarshareBooking (ReservationExpiresAt, Status)
    INCLUDE (BookingID, VehicleID)
    WHERE Status = 'reserved';
GO

-- Index for booking history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareBooking_CustomerHistory' AND object_id = OBJECT_ID('dbo.CarshareBooking'))
    CREATE NONCLUSTERED INDEX IX_CarshareBooking_CustomerHistory
    ON dbo.CarshareBooking (CustomerID, BookedAt DESC)
    INCLUDE (BookingID, VehicleID, Status, EstimatedCost);
GO

-- ============================================================================
-- CARSHARE RENTAL INDEXES
-- ============================================================================

-- Index for customer's active rental
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_CustomerActive' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_CustomerActive
    ON dbo.CarshareRental (CustomerID, Status)
    INCLUDE (RentalID, VehicleID, BookingID, StartedAt, OdometerStartKm)
    WHERE Status = 'active';
GO

-- Index for vehicle's active rental
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_VehicleActive' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_VehicleActive
    ON dbo.CarshareRental (VehicleID, Status)
    INCLUDE (RentalID, CustomerID, StartedAt)
    WHERE Status = 'active';
GO

-- Index for rental history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_CustomerHistory' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_CustomerHistory
    ON dbo.CarshareRental (CustomerID, StartedAt DESC)
    INCLUDE (RentalID, VehicleID, TotalDurationMin, TotalCost, Status);
GO

-- Index for rental by date (reporting)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_DateRange' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_DateRange
    ON dbo.CarshareRental (StartedAt, Status)
    INCLUDE (RentalID, VehicleID, CustomerID, TotalCost)
    WHERE Status = 'completed';
GO

-- Index for zone-based analysis
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_StartZone' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_StartZone
    ON dbo.CarshareRental (StartZoneID, StartedAt)
    INCLUDE (RentalID, EndZoneID, TotalCost);
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareRental_EndZone' AND object_id = OBJECT_ID('dbo.CarshareRental'))
    CREATE NONCLUSTERED INDEX IX_CarshareRental_EndZone
    ON dbo.CarshareRental (EndZoneID, EndedAt)
    INCLUDE (RentalID, StartZoneID, TotalCost);
GO

-- ============================================================================
-- CARSHARE ZONE INDEXES
-- ============================================================================

-- Index for zone location search
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareZone_Location' AND object_id = OBJECT_ID('dbo.CarshareZone'))
    CREATE NONCLUSTERED INDEX IX_CarshareZone_Location
    ON dbo.CarshareZone (IsActive, CenterLatitude, CenterLongitude)
    INCLUDE (ZoneID, ZoneName, RadiusMeters, ZoneType);
GO

-- Index for city-based zone lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareZone_City' AND object_id = OBJECT_ID('dbo.CarshareZone'))
    CREATE NONCLUSTERED INDEX IX_CarshareZone_City
    ON dbo.CarshareZone (City, IsActive)
    INCLUDE (ZoneID, ZoneName, ZoneType, CenterLatitude, CenterLongitude);
GO

-- ============================================================================
-- CARSHARE PAYMENT INDEXES
-- ============================================================================

-- Index for rental payment lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarsharePayment_Rental' AND object_id = OBJECT_ID('dbo.CarsharePayment'))
    CREATE NONCLUSTERED INDEX IX_CarsharePayment_Rental
    ON dbo.CarsharePayment (RentalID, PaymentType)
    INCLUDE (PaymentID, Amount, Status, ProcessedAt);
GO

-- Index for customer payment history
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarsharePayment_Customer' AND object_id = OBJECT_ID('dbo.CarsharePayment'))
    CREATE NONCLUSTERED INDEX IX_CarsharePayment_Customer
    ON dbo.CarsharePayment (CustomerID, CreatedAt DESC)
    INCLUDE (PaymentID, RentalID, Amount, Status);
GO

-- Index for pending payments
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarsharePayment_Pending' AND object_id = OBJECT_ID('dbo.CarsharePayment'))
    CREATE NONCLUSTERED INDEX IX_CarsharePayment_Pending
    ON dbo.CarsharePayment (Status)
    INCLUDE (PaymentID, RentalID, CustomerID, Amount)
    WHERE Status = 'pending';
GO

-- ============================================================================
-- CARSHARE AUDIT & LOG INDEXES
-- ============================================================================

-- Index for audit log by table
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareAuditLog_Table' AND object_id = OBJECT_ID('dbo.CarshareAuditLog'))
    CREATE NONCLUSTERED INDEX IX_CarshareAuditLog_Table
    ON dbo.CarshareAuditLog (TableName, ChangedAt DESC)
    INCLUDE (RecordID, Action);
GO

-- Index for system log severity
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareSystemLog_Severity' AND object_id = OBJECT_ID('dbo.CarshareSystemLog'))
    CREATE NONCLUSTERED INDEX IX_CarshareSystemLog_Severity
    ON dbo.CarshareSystemLog (Severity, CreatedAt DESC)
    INCLUDE (Category, Message);
GO

-- Index for system log by vehicle
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareSystemLog_Vehicle' AND object_id = OBJECT_ID('dbo.CarshareSystemLog'))
    CREATE NONCLUSTERED INDEX IX_CarshareSystemLog_Vehicle
    ON dbo.CarshareSystemLog (VehicleID, CreatedAt DESC)
    INCLUDE (Severity, Category, Message)
    WHERE VehicleID IS NOT NULL;
GO

-- Index for system log by customer
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareSystemLog_Customer' AND object_id = OBJECT_ID('dbo.CarshareSystemLog'))
    CREATE NONCLUSTERED INDEX IX_CarshareSystemLog_Customer
    ON dbo.CarshareSystemLog (CustomerID, CreatedAt DESC)
    INCLUDE (Severity, Category, Message)
    WHERE CustomerID IS NOT NULL;
GO

-- ============================================================================
-- CARSHARE VEHICLE TYPE INDEXES
-- ============================================================================

-- Index for active vehicle types
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicleType_Active' AND object_id = OBJECT_ID('dbo.CarshareVehicleType'))
    CREATE NONCLUSTERED INDEX IX_CarshareVehicleType_Active
    ON dbo.CarshareVehicleType (IsActive)
    INCLUDE (VehicleTypeID, TypeCode, TypeName, PricePerMinute, PricePerHour, PricePerKm)
    WHERE IsActive = 1;
GO

-- Index for type code lookup
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareVehicleType_Code' AND object_id = OBJECT_ID('dbo.CarshareVehicleType'))
    CREATE UNIQUE NONCLUSTERED INDEX IX_CarshareVehicleType_Code
    ON dbo.CarshareVehicleType (TypeCode);
GO

-- ============================================================================
-- CARSHARE GEOFENCE & OPERATING AREA INDEXES
-- ============================================================================

-- Index for active operating areas
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareOperatingArea_Active' AND object_id = OBJECT_ID('dbo.CarshareOperatingArea'))
    CREATE NONCLUSTERED INDEX IX_CarshareOperatingArea_Active
    ON dbo.CarshareOperatingArea (IsActive, AreaType)
    INCLUDE (AreaID, CenterLatitude, CenterLongitude, RadiusMeters, UsePolygon);
GO

-- Index for polygon points by area
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareOperatingAreaPolygon_Area' AND object_id = OBJECT_ID('dbo.CarshareOperatingAreaPolygon'))
    CREATE NONCLUSTERED INDEX IX_CarshareOperatingAreaPolygon_Area
    ON dbo.CarshareOperatingAreaPolygon (AreaID, SequenceNo)
    INCLUDE (LatDegrees, LonDegrees);
GO

-- Index for geofence violations by rental
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareGeofenceViolation_Rental' AND object_id = OBJECT_ID('dbo.CarshareGeofenceViolation'))
    CREATE NONCLUSTERED INDEX IX_CarshareGeofenceViolation_Rental
    ON dbo.CarshareGeofenceViolation (RentalID, CreatedAt DESC)
    INCLUDE (ViolationType, PenaltyAmount, Latitude, Longitude);
GO

-- Index for unresolved violations
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareGeofenceViolation_Unresolved' AND object_id = OBJECT_ID('dbo.CarshareGeofenceViolation'))
    CREATE NONCLUSTERED INDEX IX_CarshareGeofenceViolation_Unresolved
    ON dbo.CarshareGeofenceViolation (IsResolved, CreatedAt DESC)
    INCLUDE (RentalID, VehicleID, CustomerID, ViolationType, PenaltyAmount)
    WHERE IsResolved = 0;
GO

-- Index for violations by vehicle
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_CarshareGeofenceViolation_Vehicle' AND object_id = OBJECT_ID('dbo.CarshareGeofenceViolation'))
    CREATE NONCLUSTERED INDEX IX_CarshareGeofenceViolation_Vehicle
    ON dbo.CarshareGeofenceViolation (VehicleID, CreatedAt DESC)
    INCLUDE (RentalID, ViolationType, PenaltyAmount);
GO

-- ============================================================================
-- KASPA CRYPTOCURRENCY PAYMENT INDEXES
-- ============================================================================

-- Wallet lookups by user
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_KaspaWallet_UserID' AND object_id = OBJECT_ID('dbo.KaspaWallet'))
    CREATE NONCLUSTERED INDEX IX_KaspaWallet_UserID 
    ON dbo.KaspaWallet(UserID, IsActive, IsDefault);
GO

-- Transaction lookups by status
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_KaspaTransaction_Status' AND object_id = OBJECT_ID('dbo.KaspaTransaction'))
    CREATE NONCLUSTERED INDEX IX_KaspaTransaction_Status 
    ON dbo.KaspaTransaction(Status, CreatedAt DESC);
GO

-- Transaction lookups by recipient user
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_KaspaTransaction_ToUser' AND object_id = OBJECT_ID('dbo.KaspaTransaction'))
    CREATE NONCLUSTERED INDEX IX_KaspaTransaction_ToUser 
    ON dbo.KaspaTransaction(ToUserID, Status, CreatedAt DESC);
GO

-- Transaction hash lookups (for blockchain verification)
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_KaspaTransaction_Hash' AND object_id = OBJECT_ID('dbo.KaspaTransaction'))
    CREATE NONCLUSTERED INDEX IX_KaspaTransaction_Hash 
    ON dbo.KaspaTransaction(TransactionHash)
    WHERE TransactionHash IS NOT NULL;
GO

-- Payment request lookups by code
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_KaspaPaymentRequest_Code' AND object_id = OBJECT_ID('dbo.KaspaPaymentRequest'))
    CREATE NONCLUSTERED INDEX IX_KaspaPaymentRequest_Code 
    ON dbo.KaspaPaymentRequest(RequestCode, Status);
GO

PRINT 'All indexes created successfully.';
GO
