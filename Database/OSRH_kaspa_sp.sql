
--A. USER & SECURITY QUERIES

-- 1) Register a passenger (AX)
-- Updated to include address and GDPR preferences as per project requirements
CREATE OR ALTER PROCEDURE dbo.spRegisterPassenger
    @Email                NVARCHAR(255),
    @Phone                NVARCHAR(30) = NULL,
    @FullName             NVARCHAR(200),
    @PasswordHash         VARBINARY(256),
    @PasswordSalt         VARBINARY(128) = NULL,
    @LoyaltyLevel         NVARCHAR(50) = NULL,
    -- Address fields
    @StreetAddress        NVARCHAR(255) = NULL,
    @City                 NVARCHAR(100) = NULL,
    @PostalCode           NVARCHAR(20) = NULL,
    @Country              NVARCHAR(100) = 'Cyprus',
    -- GDPR Preferences
    @PrefLocationTracking BIT = 1,
    @PrefNotifications    BIT = 1,
    @PrefEmailUpdates     BIT = 1,
    @PrefDataSharing      BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (SELECT 1 FROM dbo.[User] WHERE Email = @Email)
    BEGIN
        RAISERROR('Email already registered.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.[User] (
        Email, Phone, FullName, Status,
        StreetAddress, City, PostalCode, Country,
        PrefLocationTracking, PrefNotifications, PrefEmailUpdates, PrefDataSharing
    ) 
    VALUES (
        @Email, @Phone, @FullName, 'active',
        @StreetAddress, @City, @PostalCode, @Country,
        @PrefLocationTracking, @PrefNotifications, @PrefEmailUpdates, @PrefDataSharing
    );

    DECLARE @UserID INT = SCOPE_IDENTITY();

    INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
    VALUES (@UserID, @PasswordHash, @PasswordSalt, 1);

    INSERT INTO dbo.Passenger (UserID, LoyaltyLevel)
    VALUES (@UserID, @LoyaltyLevel);

    SELECT @UserID AS UserID;
END
GO
-- 2) Register a driver
CREATE OR ALTER PROCEDURE dbo.spRegisterDriver
    @Email         NVARCHAR(255),
    @Phone         NVARCHAR(30) = NULL,
    @FullName      NVARCHAR(200),
    @PasswordHash  VARBINARY(256),
    @PasswordSalt  VARBINARY(128) = NULL,
    @DriverType    NVARCHAR(50),   -- 'ride', 'cargo', etc.
    @VerificationStatus NVARCHAR(50) = 'pending'
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (SELECT 1 FROM dbo.[User] WHERE Email = @Email)
    BEGIN
        RAISERROR('Email already registered.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.[User] (Email, Phone, FullName, Status)
    VALUES (@Email, @Phone, @FullName, 'active');

    DECLARE @UserID INT = SCOPE_IDENTITY();

    INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
    VALUES (@UserID, @PasswordHash, @PasswordSalt, 1);

    INSERT INTO dbo.Driver (UserID, DriverType, IsAvailable, VerificationStatus)
    VALUES (@UserID, @DriverType, 0, @VerificationStatus);

    SELECT @UserID AS UserID, SCOPE_IDENTITY() AS DriverID;
END
GO

-- 3) Register an operator 
CREATE OR ALTER PROCEDURE dbo.spRegisterOperator
    @Email         NVARCHAR(255),
    @Phone         NVARCHAR(30) = NULL,
    @FullName      NVARCHAR(200),
    @PasswordHash  VARBINARY(256),
    @PasswordSalt  VARBINARY(128) = NULL,
    @Role          NVARCHAR(50) = 'Operator'  -- 'Admin' or 'Operator'
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (SELECT 1 FROM dbo.[User] WHERE Email = @Email)
    BEGIN
        RAISERROR('Email already registered.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.[User] (Email, Phone, FullName, Status)
    VALUES (@Email, @Phone, @FullName, 'active');

    DECLARE @UserID INT = SCOPE_IDENTITY();

    INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
    VALUES (@UserID, @PasswordHash, @PasswordSalt, 1);

    INSERT INTO dbo.Operator (UserID, Role)
    VALUES (@UserID, @Role);

    SELECT @UserID AS UserID;
END
GO

-- 3b) Register a driver (simplified with documents)
-- Creates User and Driver records with minimal required info
CREATE OR ALTER PROCEDURE dbo.spRegisterDriverSimple
    @Email NVARCHAR(255),
    @Phone NVARCHAR(30),
    @FullName NVARCHAR(200),
    @PasswordHash VARBINARY(256),
    @PasswordSalt VARBINARY(128),
    @DateOfBirth DATE,
    @IDCardNumber NVARCHAR(100),
    @IDCardDocumentPath NVARCHAR(500) = NULL,
    @LicenseNumber NVARCHAR(100),
    @LicenseDocumentPath NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    -- Check if email already exists
    IF EXISTS (SELECT 1 FROM dbo.[User] WHERE Email = @Email)
    BEGIN
        RAISERROR('Email already registered.', 16, 1);
        RETURN;
    END

    -- Check age (must be 18+)
    IF @DateOfBirth IS NOT NULL AND DATEDIFF(YEAR, @DateOfBirth, GETDATE()) < 18
    BEGIN
        RAISERROR('You must be at least 18 years old to register as a driver.', 16, 1);
        RETURN;
    END

    DECLARE @UserID INT;
    DECLARE @DriverID INT;

    BEGIN TRANSACTION;

    BEGIN TRY
        -- 1. Create User
        INSERT INTO dbo.[User] (Email, Phone, FullName, DateOfBirth, Status)
        VALUES (@Email, @Phone, @FullName, @DateOfBirth, 'active');
        
        SET @UserID = SCOPE_IDENTITY();

        -- 2. Create Password History entry
        INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
        VALUES (@UserID, @PasswordHash, @PasswordSalt, 1);

        -- 3. Create Driver with approved status
        INSERT INTO dbo.Driver (UserID, DriverType, IsAvailable, VerificationStatus, UseGPS)
        VALUES (@UserID, 'ride', 1, 'approved', 1);
        
        SET @DriverID = SCOPE_IDENTITY();

        -- 4. Create ID Card Document record
        IF @IDCardNumber IS NOT NULL OR @IDCardDocumentPath IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, StorageUrl, Status)
            VALUES (@DriverID, 'id_card', @IDCardNumber, @IDCardDocumentPath, 'pending');
        END

        -- 5. Create License Document record
        IF @LicenseNumber IS NOT NULL OR @LicenseDocumentPath IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, StorageUrl, Status)
            VALUES (@DriverID, 'license', @LicenseNumber, @LicenseDocumentPath, 'pending');
        END

        COMMIT TRANSACTION;

        -- Return the created IDs
        SELECT 
            @UserID AS UserID,
            @DriverID AS DriverID,
            'approved' AS Status;

    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        
        THROW;
    END CATCH
END
GO

-- 4) Login: check email + password hash
CREATE OR ALTER PROCEDURE dbo.spLoginUser
    @Email        NVARCHAR(255),
    @PasswordHash VARBINARY(256)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1 u.UserID, u.Email, u.FullName, u.Status
    FROM dbo.[User] u
    JOIN dbo.PasswordHistory ph
        ON u.UserID = ph.UserID AND ph.IsCurrent = 1
    WHERE u.Email = @Email
      AND ph.PasswordHash = @PasswordHash
      AND u.Status = 'active';
END
GO

-- 5) Change password
CREATE OR ALTER PROCEDURE dbo.spChangePassword
    @UserID       INT,
    @NewHash      VARBINARY(256),
    @NewSalt      VARBINARY(128) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.PasswordHistory
        SET IsCurrent = 0
    WHERE UserID = @UserID
      AND IsCurrent = 1;

    INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
    VALUES (@UserID, @NewHash, @NewSalt, 1);
END
GO

/*
   B. GDPR "RIGHT TO BE FORGOTTEN"
*/

-- Create a GDPR request
CREATE OR ALTER PROCEDURE dbo.spCreateGdprRequest
    @UserID      INT,
    @Reason      NVARCHAR(500)
AS
BEGIN
    INSERT INTO dbo.GDPRRequest (UserID, RequestedAt, Status, Reason)
    VALUES (@UserID, SYSDATETIME(), 'pending', @Reason);
END
GO

-- Process GDPR deletion
CREATE OR ALTER PROCEDURE dbo.spProcessGdprDeletion
    @RequestID   INT,
    @OperatorID  INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @UserID INT;

    SELECT @UserID = UserID
    FROM dbo.GDPRRequest
    WHERE RequestID = @RequestID
      AND Status = 'pending';

    IF @UserID IS NULL
    BEGIN
        RAISERROR('Invalid or already processed request.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.GDPRLog (RequestID, OperatorID, Action, ActionAt)
    VALUES (@RequestID, @OperatorID, 'approved_and_deleted', SYSDATETIME());

    UPDATE dbo.[User]
        SET Email    = CONCAT('anon_', UserID, '@example.com'),
            Phone    = NULL,
            FullName = 'Anonymized User',
            Status   = 'deleted'
    WHERE UserID = @UserID;

    DELETE FROM dbo.Message WHERE FromUserID = @UserID OR ToUserID = @UserID;
    DELETE FROM dbo.Rating  WHERE FromUserID = @UserID;

    UPDATE dbo.GDPRRequest
        SET Status       = 'completed',
            CompletedAt  = SYSDATETIME()
    WHERE RequestID = @RequestID;
END
GO

-- C. DRIVER, VEHICLE, DOCUMENTS, INSPECTIONS

-- 1) Add a driver document
CREATE OR ALTER PROCEDURE dbo.spAddDriverDocument
    @DriverID    INT,
    @DocType     NVARCHAR(50),
    @IdNumber    NVARCHAR(100),
    @IssueDate   DATE,
    @ExpiryDate  DATE = NULL,
    @Status      NVARCHAR(30) = 'submitted',
    @StorageUrl  NVARCHAR(500) = NULL
AS
BEGIN
    INSERT INTO dbo.DriverDocument
        (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
    VALUES
        (@DriverID, @DocType, @IdNumber, @IssueDate, @ExpiryDate, @Status, @StorageUrl);
END
GO

-- 2) Register a vehicle for a driver
CREATE OR ALTER PROCEDURE dbo.spRegisterVehicle
    @DriverID        INT,
    @VehicleTypeID   INT,
    @PlateNo         NVARCHAR(20),
    @Make            NVARCHAR(100),
    @Model           NVARCHAR(100),
    @Year            SMALLINT,
    @Color           NVARCHAR(50),
    @SeatingCapacity INT,
    @MaxWeightKg     INT = NULL
AS
BEGIN
    INSERT INTO dbo.Vehicle
        (DriverID, VehicleTypeID, PlateNo, Make, Model, Year, Color,
         SeatingCapacity, MaxWeightKg, IsActive)
    VALUES
        (@DriverID, @VehicleTypeID, @PlateNo, @Make, @Model, @Year, @Color,
         @SeatingCapacity, @MaxWeightKg, 1);
END
GO

-- 3) Add a vehicle document
CREATE OR ALTER PROCEDURE dbo.spAddVehicleDocument
    @VehicleID   INT,
    @DocType     NVARCHAR(50),
    @IdNumber    NVARCHAR(100),
    @IssueDate   DATE,
    @ExpiryDate  DATE = NULL,
    @Status      NVARCHAR(30) = 'submitted',
    @StorageUrl  NVARCHAR(500) = NULL
AS
BEGIN
    INSERT INTO dbo.VehicleDocument
        (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
    VALUES
        (@VehicleID, @DocType, @IdNumber, @IssueDate, @ExpiryDate, @Status, @StorageUrl);
END
GO

-- 4) Record safety inspection
CREATE OR ALTER PROCEDURE dbo.spRecordSafetyInspection
    @VehicleID      INT,
    @Status         NVARCHAR(30),   -- 'passed', 'failed', 'needs_followup'
    @Notes          NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Insert the new inspection record
    INSERT INTO dbo.SafetyInspection
        (VehicleID, InspectionDate, InspectorName, InspectionType, Result, Notes)
    VALUES
        (@VehicleID, GETDATE(), 'System', 'General', @Status, @Notes);
    
    -- If inspection passed, activate the vehicle
    IF @Status = 'passed'
    BEGIN
        UPDATE dbo.Vehicle 
        SET IsActive = 1 
        WHERE VehicleID = @VehicleID;
    END
    
    -- If inspection failed, ensure vehicle stays inactive
    IF @Status = 'failed'
    BEGIN
        UPDATE dbo.Vehicle 
        SET IsActive = 0 
        WHERE VehicleID = @VehicleID;
    END
        
    SELECT SCOPE_IDENTITY() AS InspectionID;
END
GO

-- 4a) Get all vehicles for inspection dropdown
CREATE OR ALTER PROCEDURE dbo.spGetVehiclesForInspection
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Show all vehicles that need inspection:
    -- 1. Active vehicles (for regular inspections)
    -- 2. Inactive vehicles with pending safety inspection (newly registered by drivers)
    SELECT
        v.VehicleID,
        v.PlateNo,
        vt.Name AS VehicleTypeName,
        v.IsActive,
        CASE 
            WHEN v.IsActive = 0 AND EXISTS (
                SELECT 1 FROM dbo.SafetyInspection si 
                WHERE si.VehicleID = v.VehicleID AND si.Result = 'pending'
            ) THEN 1
            ELSE 0
        END AS IsPendingApproval
    FROM dbo.Vehicle v
    JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.IsActive = 1
       OR (v.IsActive = 0 AND EXISTS (
           SELECT 1 FROM dbo.SafetyInspection si 
           WHERE si.VehicleID = v.VehicleID AND si.Result = 'pending'
       ))
    ORDER BY 
        IsPendingApproval DESC,  -- Pending approval first
        v.PlateNo;
END
GO

-- 4b) Get recent safety inspections (latest per vehicle for pending/failed, all for history)
CREATE OR ALTER PROCEDURE dbo.spGetRecentSafetyInspections
    @MaxRows INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get only the latest inspection per vehicle
    ;WITH LatestInspections AS (
        SELECT 
            si.SafetyInspectionID,
            si.VehicleID,
            si.InspectionDate,
            si.Result,
            si.Notes,
            ROW_NUMBER() OVER (PARTITION BY si.VehicleID ORDER BY si.InspectionDate DESC, si.SafetyInspectionID DESC) AS rn
        FROM dbo.SafetyInspection si
    )
    SELECT TOP (@MaxRows)
        li.SafetyInspectionID,
        li.VehicleID,
        li.InspectionDate,
        li.Result AS Status,
        li.Notes,
        v.PlateNo,
        vt.Name AS VehicleTypeName,
        v.IsActive AS VehicleIsActive
    FROM LatestInspections li
    JOIN dbo.Vehicle v      ON li.VehicleID    = v.VehicleID
    JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE li.rn = 1  -- Only the latest inspection per vehicle
    ORDER BY 
        CASE WHEN li.Result = 'pending' THEN 0 ELSE 1 END,  -- Pending first
        li.InspectionDate DESC, 
        li.SafetyInspectionID DESC;
END
GO

-- D. LOCATIONS & GEOFENCES

-- Function to check if a point is inside a specific geofence polygon using ray-casting algorithm
-- ============================================================================
-- OPTIMIZED Point-in-Polygon Function using Set-Based Ray-Casting
-- This replaces the slow WHILE loop version with a single efficient query
-- ============================================================================
CREATE OR ALTER FUNCTION dbo.fnIsPointInGeofence
(
    @Lat DECIMAL(9,6),
    @Lon DECIMAL(9,6),
    @GeofenceID INT
)
RETURNS BIT
AS
BEGIN
    DECLARE @Crossings INT;
    
    -- Set-based ray-casting: count edge crossings in a single query
    -- An edge from point i to point j crosses our horizontal ray if:
    -- 1. One point is above @Lat and one is below (or equal)
    -- 2. The X coordinate where the edge crosses @Lat is to the right of @Lon
    SELECT @Crossings = COUNT(*)
    FROM (
        SELECT 
            p1.LatDegrees AS Yi, p1.LonDegrees AS Xi,
            COALESCE(p2.LatDegrees, pFirst.LatDegrees) AS Yj,
            COALESCE(p2.LonDegrees, pFirst.LonDegrees) AS Xj
        FROM dbo.GeofencePoint p1
        -- Join to next point (or wrap to first point for the last edge)
        LEFT JOIN dbo.GeofencePoint p2 
            ON p2.GeofenceID = p1.GeofenceID 
            AND p2.SequenceNo = p1.SequenceNo + 1
        -- Get first point for wrapping the polygon closed
        CROSS APPLY (
            SELECT TOP 1 LatDegrees, LonDegrees 
            FROM dbo.GeofencePoint 
            WHERE GeofenceID = @GeofenceID 
            ORDER BY SequenceNo
        ) pFirst
        WHERE p1.GeofenceID = @GeofenceID
    ) edges
    WHERE 
        -- One point above, one below (or equal to) the test latitude
        ((Yi > @Lat AND Yj <= @Lat) OR (Yj > @Lat AND Yi <= @Lat))
        -- X intersection point is to the right of test point
        AND @Lon < (Xi + (Xj - Xi) * (@Lat - Yi) / (Yj - Yi));
    
    -- Odd number of crossings = inside polygon
    RETURN CASE WHEN @Crossings % 2 = 1 THEN 1 ELSE 0 END;
END
GO

-- Function to check if a point is inside ANY active geofence
CREATE OR ALTER FUNCTION dbo.fnIsPointInAnyGeofence
(
    @Lat DECIMAL(9,6),
    @Lon DECIMAL(9,6)
)
RETURNS BIT
AS
BEGIN
    DECLARE @IsInside BIT = 0;
    
    IF EXISTS (
        SELECT 1 
        FROM dbo.Geofence g
        WHERE g.IsActive = 1
          AND dbo.fnIsPointInGeofence(@Lat, @Lon, g.GeofenceID) = 1
    )
    BEGIN
        SET @IsInside = 1;
    END
    
    RETURN @IsInside;
END
GO

-- Function to get which geofence a point is in (returns NULL if not in any)
CREATE OR ALTER FUNCTION dbo.fnGetGeofenceForPoint
(
    @Lat DECIMAL(9,6),
    @Lon DECIMAL(9,6)
)
RETURNS INT
AS
BEGIN
    DECLARE @GeofenceID INT = NULL;
    
    SELECT TOP 1 @GeofenceID = g.GeofenceID
    FROM dbo.Geofence g
    WHERE g.IsActive = 1
      AND dbo.fnIsPointInGeofence(@Lat, @Lon, g.GeofenceID) = 1;
    
    RETURN @GeofenceID;
END
GO

-- Insert a location
CREATE OR ALTER PROCEDURE dbo.spInsertLocation
    @Description    NVARCHAR(255) = NULL,
    @StreetAddress  NVARCHAR(255) = NULL,
    @PostalCode     NVARCHAR(20)  = NULL,
    @LatDegrees     DECIMAL(9,6),
    @LonDegrees     DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.Location (Description, StreetAddress, PostalCode, LatDegrees, LonDegrees)
    VALUES (@Description, @StreetAddress, @PostalCode, @LatDegrees, @LonDegrees);

    SELECT SCOPE_IDENTITY() AS LocationID;
END;
GO

-- E. RIDE REQUESTS, DISPATCH & TRIPS

-- 1) Create a ride request (AX)
CREATE OR ALTER PROCEDURE dbo.spCreateRideRequest
    @PassengerID       INT,
    @PickupLocationID  INT,
    @DropoffLocationID INT,
    @ServiceTypeID     INT = 1,  -- Default to standard service
    @PassengerNotes    NVARCHAR(500) = NULL,
    @LuggageVolume     DECIMAL(10,2) = NULL,
    @WheelchairNeeded  BIT = 0,
    @PaymentMethodTypeID INT = 1,  -- Default to Card (1)
    @EstimatedDistanceKm DECIMAL(10,3) = NULL,  -- OSRM route distance from passenger
    @EstimatedDurationMin INT = NULL,  -- OSRM route duration from passenger
    @EstimatedFare DECIMAL(10,2) = NULL,  -- Passenger's estimated total (including surge)
    @RealDriversOnly BIT = 0  -- If 1, only real GPS drivers can accept
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get pickup and dropoff coordinates
    DECLARE @PickupLat DECIMAL(9,6), @PickupLon DECIMAL(9,6);
    DECLARE @DropoffLat DECIMAL(9,6), @DropoffLon DECIMAL(9,6);
    
    SELECT @PickupLat = LatDegrees, @PickupLon = LonDegrees
    FROM dbo.Location
    WHERE LocationID = @PickupLocationID;
    
    SELECT @DropoffLat = LatDegrees, @DropoffLon = LonDegrees
    FROM dbo.Location
    WHERE LocationID = @DropoffLocationID;
    
    -- Check if pickup location is within a service area (geofence)
    IF dbo.fnIsPointInAnyGeofence(@PickupLat, @PickupLon) = 0
    BEGIN
        RAISERROR('Pickup location is outside our service area. Please select a location within our covered districts.', 16, 1);
        RETURN;
    END
    
    -- Check if dropoff location is within a service area (geofence)
    IF dbo.fnIsPointInAnyGeofence(@DropoffLat, @DropoffLon) = 0
    BEGIN
        RAISERROR('Dropoff location is outside our service area. Please select a location within our covered districts.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.RideRequest
        (PassengerID, RequestedAt, PickupLocationID, DropoffLocationID,
         ServiceTypeID, Status, PassengerNotes, LuggageVolume, WheelchairNeeded, PaymentMethodTypeID,
         EstimatedDistanceKm, EstimatedDurationMin, EstimatedFare, RealDriversOnly)
    VALUES
        (@PassengerID, SYSDATETIME(), @PickupLocationID, @DropoffLocationID,
         @ServiceTypeID, 'pending', @PassengerNotes, @LuggageVolume, @WheelchairNeeded, @PaymentMethodTypeID,
         @EstimatedDistanceKm, @EstimatedDurationMin, @EstimatedFare, @RealDriversOnly);

    SELECT SCOPE_IDENTITY() AS RideRequestID;
END;
GO

-- 2) List open ride requests (for operators)
CREATE OR ALTER VIEW dbo.vOpenRideRequests
AS
SELECT
    rr.RideRequestID,
    rr.RequestedAt,
    p.PassengerID,
    u.FullName   AS PassengerName,
    rr.Status,
    rr.PickupLocationID,
    rr.DropoffLocationID
FROM dbo.RideRequest rr
JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
JOIN dbo.[User]  u   ON p.UserID = u.UserID
WHERE rr.Status IN ('pending','searching_driver');
GO

-- 3) Find available drivers near a pickup location
-- Updated to filter by service type compatibility
CREATE OR ALTER PROCEDURE dbo.spFindAvailableDriversForLocation
    @GeofenceID INT,
    @ServiceTypeID INT = NULL  -- Optional: filter by requested service type
AS
BEGIN
    SELECT
        d.DriverID,
        u.FullName AS DriverName,
        v.VehicleID,
        v.PlateNo,
        vt.Name    AS VehicleType
    FROM dbo.Driver d
    JOIN dbo.[User] u       ON d.UserID = u.UserID
    JOIN dbo.Vehicle v      ON d.DriverID = v.DriverID AND v.IsActive = 1
    JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      -- Filter by service type compatibility if provided
      AND (@ServiceTypeID IS NULL OR EXISTS (
          SELECT 1 
          FROM dbo.VehicleType_ServiceType vts
          WHERE vts.VehicleTypeID = v.VehicleTypeID
            AND vts.ServiceTypeID = @ServiceTypeID
      ))
      AND EXISTS (
             SELECT 1
             FROM dbo.GeofenceLog gl
             WHERE gl.VehicleID = v.VehicleID
               AND gl.GeofenceID = @GeofenceID
               AND gl.ExitedAt IS NULL
      );
END
GO

-- ============================================================================
-- AUTO-ASSIGN SIMULATED DRIVER TO SEGMENT
-- For multi-vehicle journeys: assigns driver to segment based on geofence binding
-- This must be defined early as it's called by other procedures
-- ============================================================================
CREATE OR ALTER PROCEDURE dbo.spAutoAssignSimulatedDriverToSegment
    @SegmentID INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @RideRequestID INT;
    DECLARE @SegmentGeofenceID INT;
    DECLARE @ServiceTypeID INT;
    DECLARE @PickupLat DECIMAL(9,6), @PickupLng DECIMAL(9,6);
    DECLARE @ClosestDriverID INT;
    DECLARE @ClosestVehicleID INT;
    DECLARE @DistanceKm DECIMAL(10,3);
    DECLARE @SegmentOrder INT;
    
    -- Get segment details
    SELECT 
        @RideRequestID = rs.RideRequestID,
        @SegmentGeofenceID = rs.GeofenceID,
        @SegmentOrder = rs.SegmentOrder,
        @ServiceTypeID = rr.ServiceTypeID,
        @PickupLat = fromLoc.LatDegrees,
        @PickupLng = fromLoc.LonDegrees
    FROM dbo.RideSegment rs
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    WHERE rs.SegmentID = @SegmentID
      AND rs.TripID IS NULL; -- Segment must not already be assigned
    
    IF @RideRequestID IS NULL
    BEGIN
        SELECT 
            0 AS Success,
            'Segment not found or already assigned.' AS Message,
            NULL AS TripID;
        RETURN;
    END
    
    -- For segments after the first, verify previous segment is completed
    IF @SegmentOrder > 1
    BEGIN
        IF NOT EXISTS (
            SELECT 1 
            FROM dbo.RideSegment rs
            INNER JOIN dbo.Trip t ON rs.TripID = t.TripID
            WHERE rs.RideRequestID = @RideRequestID
              AND rs.SegmentOrder = @SegmentOrder - 1
              AND t.Status = 'completed'
        )
        BEGIN
            SELECT 
                0 AS Success,
                'Previous segment must be completed first.' AS Message,
                NULL AS TripID;
            RETURN;
        END
    END
    
    -- Find closest simulated driver whose vehicle is bound to this segment's geofence
    SELECT TOP 1
        @ClosestDriverID = d.DriverID,
        @ClosestVehicleID = v.VehicleID,
        @DistanceKm = ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ))
            ), 3)
    FROM dbo.Driver d
    INNER JOIN dbo.Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
    -- CRITICAL: Vehicle must be bound to this segment's geofence
    INNER JOIN dbo.GeofenceLog gl ON gl.VehicleID = v.VehicleID 
        AND gl.ExitedAt IS NULL 
        AND gl.GeofenceID = @SegmentGeofenceID
    -- Service type compatibility
    INNER JOIN dbo.VehicleType_ServiceType vts ON vts.VehicleTypeID = v.VehicleTypeID 
        AND vts.ServiceTypeID = @ServiceTypeID
    WHERE d.UseGPS = 0  -- Simulated drivers only
      AND d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      AND d.CurrentLatitude IS NOT NULL
      -- Exclude vehicles with failed safety inspection
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.SafetyInspection si
          WHERE si.VehicleID = v.VehicleID
            AND si.Result = 'failed'
            AND si.SafetyInspectionID = (
                SELECT MAX(si2.SafetyInspectionID)
                FROM dbo.SafetyInspection si2
                WHERE si2.VehicleID = v.VehicleID
            )
      )
    ORDER BY 
        6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
            ))
        ) ASC;
    
    -- Try unavailable drivers if none available
    IF @ClosestDriverID IS NULL
    BEGIN
        SELECT TOP 1
            @ClosestDriverID = d.DriverID,
            @ClosestVehicleID = v.VehicleID,
            @DistanceKm = ROUND(
                6371 * 2 * ATN2(
                    SQRT(
                        POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                        COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                        POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                    ),
                    SQRT(1 - (
                        POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                        COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                        POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                    ))
                ), 3)
        FROM dbo.Driver d
        INNER JOIN dbo.Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
        INNER JOIN dbo.GeofenceLog gl ON gl.VehicleID = v.VehicleID 
            AND gl.ExitedAt IS NULL 
            AND gl.GeofenceID = @SegmentGeofenceID
        INNER JOIN dbo.VehicleType_ServiceType vts ON vts.VehicleTypeID = v.VehicleTypeID 
            AND vts.ServiceTypeID = @ServiceTypeID
        WHERE d.UseGPS = 0
          AND d.IsAvailable = 0  -- Try unavailable
          AND d.VerificationStatus = 'approved'
          AND d.CurrentLatitude IS NOT NULL
          AND NOT EXISTS (
              SELECT 1
              FROM dbo.SafetyInspection si
              WHERE si.VehicleID = v.VehicleID
                AND si.Result = 'failed'
                AND si.SafetyInspectionID = (
                    SELECT MAX(si2.SafetyInspectionID)
                    FROM dbo.SafetyInspection si2
                    WHERE si2.VehicleID = v.VehicleID
                )
          )
        ORDER BY 
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ))
            ) ASC;
    END
    
    IF @ClosestDriverID IS NULL
    BEGIN
        SELECT 
            0 AS Success,
            'No simulated drivers available in this service area.' AS Message,
            NULL AS TripID;
        RETURN;
    END
    
    DECLARE @DriverLat DECIMAL(9,6), @DriverLng DECIMAL(9,6);
    SELECT 
        @DriverLat = CurrentLatitude,
        @DriverLng = CurrentLongitude
    FROM dbo.Driver
    WHERE DriverID = @ClosestDriverID;

    DECLARE @EstimatedMinutes INT = CEILING(@DistanceKm / 0.67);
    IF @EstimatedMinutes < 2 SET @EstimatedMinutes = 2;
    
    DECLARE @EstimatedPickupTime DATETIME2 = DATEADD(MINUTE, @EstimatedMinutes, SYSDATETIME());

    -- Mark driver as unavailable
    UPDATE dbo.Driver SET IsAvailable = 0 WHERE DriverID = @ClosestDriverID;
    
    -- Update ride request status if this is the first segment
    IF @SegmentOrder = 1
    BEGIN
        UPDATE dbo.RideRequest SET Status = 'accepted' WHERE RideRequestID = @RideRequestID;
    END

    -- Create trip for this segment
    DECLARE @TripID INT;
    INSERT INTO dbo.Trip (
        RideRequestID, DriverID, VehicleID, Status,
        DriverStartLat, DriverStartLng, EstimatedPickupTime, SimulationStartTime
    )
    VALUES (
        @RideRequestID, @ClosestDriverID, @ClosestVehicleID, 'assigned',
        @DriverLat, @DriverLng, @EstimatedPickupTime, SYSDATETIME()
    );

    SET @TripID = SCOPE_IDENTITY();
    
    -- Link segment to trip
    UPDATE dbo.RideSegment
    SET TripID = @TripID
    WHERE SegmentID = @SegmentID;

    -- Log the assignment
    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    VALUES (@RideRequestID, @ClosestDriverID, 'assigned', 
            'Segment ' + CAST(@SegmentOrder AS VARCHAR) + ' auto-assigned to simulated driver (' + CAST(@DistanceKm AS VARCHAR) + ' km away)');

    SELECT 
        1 AS Success,
        'Driver assigned to segment ' + CAST(@SegmentOrder AS VARCHAR) + '!' AS Message,
        @TripID AS TripID,
        @SegmentID AS SegmentID,
        @SegmentOrder AS SegmentOrder,
        @ClosestDriverID AS DriverID,
        @ClosestVehicleID AS VehicleID,
        @DistanceKm AS DistanceKm,
        @EstimatedMinutes AS EstimatedMinutesToPickup,
        @DriverLat AS DriverStartLat,
        @DriverLng AS DriverStartLng,
        @PickupLat AS PickupLat,
        @PickupLng AS PickupLng;
END
GO

-- 4) Assign a driver to a ride request
-- Updated to validate vehicle-service type compatibility
CREATE OR ALTER PROCEDURE dbo.spAssignDriverToRideRequest
    @RideRequestID INT,
    @DriverID      INT,
    @VehicleID     INT,
    @OperatorID    INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Check if vehicle has failed its most recent safety inspection
    IF EXISTS (
        SELECT 1
        FROM dbo.SafetyInspection si
        WHERE si.VehicleID = @VehicleID
          AND si.Result = 'failed'
          AND si.SafetyInspectionID = (
              SELECT MAX(si2.SafetyInspectionID)
              FROM dbo.SafetyInspection si2
              WHERE si2.VehicleID = @VehicleID
          )
    )
    BEGIN
        RAISERROR('Vehicle has failed its most recent safety inspection and cannot be used for rides.', 16, 1);
        RETURN;
    END

    -- Get the requested service type
    DECLARE @ServiceTypeID INT;
    DECLARE @VehicleTypeID INT;
    
    SELECT @ServiceTypeID = ServiceTypeID
    FROM dbo.RideRequest
    WHERE RideRequestID = @RideRequestID;
    
    SELECT @VehicleTypeID = VehicleTypeID
    FROM dbo.Vehicle
    WHERE VehicleID = @VehicleID;
    
    -- Validate vehicle can provide this service type
    IF NOT EXISTS (
        SELECT 1 
        FROM dbo.VehicleType_ServiceType
        WHERE VehicleTypeID = @VehicleTypeID
          AND ServiceTypeID = @ServiceTypeID
    )
    BEGIN
        RAISERROR('Vehicle type is not compatible with requested service type.', 16, 1);
        RETURN;
    END

    UPDATE dbo.RideRequest
        SET Status = 'assigned'
    WHERE RideRequestID = @RideRequestID;

    INSERT INTO dbo.Trip
        (RideRequestID, DriverID, VehicleID, DispatchTime, Status)
    VALUES
        (@RideRequestID, @DriverID, @VehicleID, SYSDATETIME(), 'assigned');

    DECLARE @TripID INT = SCOPE_IDENTITY();

    INSERT INTO dbo.DispatchLog
        (RideRequestID, DriverID, OperatorID, CreatedAt, Status, ChangeReason)
    VALUES
        (@RideRequestID, @DriverID, @OperatorID, SYSDATETIME(), 'assigned', NULL);

    UPDATE dbo.Driver
        SET IsAvailable = 0
    WHERE DriverID = @DriverID;

    SELECT @TripID AS TripID;
END
GO

-- 5) Update trip status (start/end ride)
CREATE OR ALTER PROCEDURE dbo.spUpdateTripStatus
    @TripID          INT,
    @NewStatus       NVARCHAR(30),
    @TotalDistanceKm DECIMAL(10,3) = NULL,
    @TotalDurationSec INT = NULL
AS
BEGIN
    DECLARE @OldStatus NVARCHAR(30),
            @DriverID  INT;

    SELECT @OldStatus = Status,
           @DriverID  = DriverID
    FROM dbo.Trip WHERE TripID = @TripID;

    IF @OldStatus IS NULL
    BEGIN
        RAISERROR('Trip not found.', 16, 1);
        RETURN;
    END

    IF @OldStatus = 'assigned' AND @NewStatus = 'in_progress'
    BEGIN
        UPDATE dbo.Trip
            SET Status = 'in_progress',
                StartTime = SYSDATETIME()
        WHERE TripID = @TripID;
    END
    ELSE IF @NewStatus = 'completed'
    BEGIN
        UPDATE dbo.Trip
            SET Status = 'completed',
                EndTime = SYSDATETIME(),
                TotalDistanceKm  = ISNULL(@TotalDistanceKm, TotalDistanceKm),
                TotalDurationSec = ISNULL(@TotalDurationSec, TotalDurationSec)
        WHERE TripID = @TripID;

        UPDATE dbo.Driver SET IsAvailable = 1 WHERE DriverID = @DriverID;
    END
    ELSE IF @NewStatus = 'cancelled'
    BEGIN
        UPDATE dbo.Trip
            SET Status = 'cancelled',
                EndTime = SYSDATETIME()
        WHERE TripID = @TripID;

        UPDATE dbo.Driver SET IsAvailable = 1 WHERE DriverID = @DriverID;
    END
    ELSE
    BEGIN
        UPDATE dbo.Trip SET Status = @NewStatus WHERE TripID = @TripID;
    END
END
GO

-- 6) Passenger trip history
CREATE OR ALTER PROCEDURE dbo.spGetPassengerTripHistory
    @PassengerID INT
AS
BEGIN
    SELECT
        t.TripID,
        rr.RequestedAt,
        t.StartTime,
        t.EndTime,
        t.Status,
        -- For segment trips, show segment payment; for regular trips, show trip payment
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN COALESCE(segPmt.Amount, rs.EstimatedFare)
            ELSE pmt.Amount
        END AS Amount,
        COALESCE(segPmt.CurrencyCode, pmt.CurrencyCode, 'EUR') AS CurrencyCode,
        d.DriverID,
        du.FullName AS DriverName,
        -- Segment info
        rs.SegmentID,
        rs.SegmentOrder,
        CASE WHEN rs.SegmentID IS NOT NULL THEN 1 ELSE 0 END AS IsSegmentTrip,
        -- Segment payment status
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN segPmt.Status
            ELSE pmt.Status
        END AS PaymentStatus
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr    ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p       ON rr.PassengerID = p.PassengerID
    JOIN dbo.Driver d          ON t.DriverID = d.DriverID
    JOIN dbo.[User] du         ON d.UserID = du.UserID
    -- Get segment info if this trip is part of a multi-vehicle journey
    LEFT JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
    -- Segment payment (for multi-vehicle trips)
    OUTER APPLY (
        SELECT TOP 1 sp.Amount, sp.CurrencyCode, sp.Status
        FROM dbo.Payment sp
        WHERE sp.SegmentID = rs.SegmentID
        ORDER BY sp.CreatedAt DESC
    ) segPmt
    -- Trip payment (for regular trips)
    LEFT JOIN dbo.Payment pmt  ON pmt.TripID = t.TripID AND pmt.SegmentID IS NULL AND pmt.Status = 'completed'
    WHERE p.PassengerID = @PassengerID
    ORDER BY rr.RequestedAt DESC, t.TripID DESC;
END
GO

-- 7) Driver trip history
CREATE OR ALTER PROCEDURE dbo.spGetDriverTripHistory
    @DriverID INT
AS
BEGIN
    SELECT
        t.TripID,
        rr.RequestedAt,
        t.StartTime,
        t.EndTime,
        t.Status,
        pmt.Amount,
        pmt.CurrencyCode,
        p.PassengerID,
        pu.FullName AS PassengerName
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr    ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p       ON rr.PassengerID = p.PassengerID
    JOIN dbo.[User] pu         ON p.UserID = pu.UserID
    LEFT JOIN dbo.Payment pmt  ON pmt.TripID = t.TripID AND pmt.Status = 'completed'
    WHERE t.DriverID = @DriverID
    ORDER BY rr.RequestedAt DESC;
END
GO

-- F. PAYMENTS

-- 1) Create payment record for a trip
CREATE OR ALTER PROCEDURE dbo.spCreatePaymentForTrip
    @TripID              INT,
    @PaymentMethodTypeID INT,
    @Amount              DECIMAL(10,2),
    @CurrencyCode        CHAR(3),
    @ProviderReference   NVARCHAR(255) = NULL
AS
BEGIN
    INSERT INTO dbo.Payment
        (TripID, PaymentMethodTypeID, Amount, CurrencyCode,
         ProviderReference, Status, CreatedAt)
    VALUES
        (@TripID, @PaymentMethodTypeID, @Amount, @CurrencyCode,
         @ProviderReference, 'pending', SYSDATETIME());
END
GO

-- 2) Mark payment as completed
CREATE OR ALTER PROCEDURE dbo.spCompletePayment
    @PaymentID INT,
    @ProviderReference NVARCHAR(255) = NULL
AS
BEGIN
    UPDATE dbo.Payment
        SET Status = 'completed',
            CompletedAt = SYSDATETIME(),
            ProviderReference = COALESCE(@ProviderReference, ProviderReference)
    WHERE PaymentID = @PaymentID;
    
    -- If this is a segment payment and there's now a Trip assigned, update the TripID
    UPDATE p
    SET p.TripID = rs.TripID
    FROM dbo.Payment p
    INNER JOIN dbo.RideSegment rs ON p.SegmentID = rs.SegmentID
    WHERE p.PaymentID = @PaymentID
      AND p.SegmentID IS NOT NULL
      AND rs.TripID IS NOT NULL
      AND p.TripID IS NULL;
END
GO

-- 3) Create payment for a segment (multi-vehicle journey)
CREATE OR ALTER PROCEDURE dbo.spCreatePaymentForSegment
    @SegmentID           INT,
    @PaymentMethodTypeID INT,
    @Amount              DECIMAL(10,2),
    @CurrencyCode        CHAR(3),
    @ProviderReference   NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @TripID INT;
    DECLARE @EstimatedDistanceKm DECIMAL(8,2);
    DECLARE @EstimatedDurationMin INT;
    
    -- Get segment info
    SELECT 
        @TripID = TripID,
        @EstimatedDistanceKm = EstimatedDistanceKm,
        @EstimatedDurationMin = EstimatedDurationMin
    FROM dbo.RideSegment
    WHERE SegmentID = @SegmentID;
    
    -- Calculate fare breakdown (0% service fee - no platform fee)
    DECLARE @ServiceFeeRate DECIMAL(5,4) = 0.0000;
    DECLARE @ServiceFeeAmount DECIMAL(10,2) = 0.00;
    DECLARE @DriverEarnings DECIMAL(10,2) = @Amount;
    
    INSERT INTO dbo.Payment
        (TripID, SegmentID, PaymentMethodTypeID, Amount, CurrencyCode,
         ProviderReference, Status, CreatedAt,
         ServiceFeeRate, ServiceFeeAmount, DriverEarnings,
         DistanceKm, DurationMinutes)
    VALUES
        (@TripID, @SegmentID, @PaymentMethodTypeID, @Amount, @CurrencyCode,
         @ProviderReference, 'pending', SYSDATETIME(),
         @ServiceFeeRate, @ServiceFeeAmount, @DriverEarnings,
         @EstimatedDistanceKm, @EstimatedDurationMin);
    
    SELECT SCOPE_IDENTITY() AS PaymentID;
END
GO

-- 4) Get payment for a specific segment
CREATE OR ALTER PROCEDURE dbo.spGetSegmentPayment
    @SegmentID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        p.*,
        pmt.Code AS PaymentMethodName,
        pmt.Description AS PaymentMethodDescription
    FROM dbo.Payment p
    INNER JOIN dbo.PaymentMethodType pmt ON p.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE p.SegmentID = @SegmentID
    ORDER BY p.CreatedAt DESC;
END
GO

-- 5) Complete segment payment (marks payment as completed and updates driver earnings)
CREATE OR ALTER PROCEDURE dbo.spCompleteSegmentPayment
    @PaymentID INT,
    @ProviderReference NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @SegmentID INT;
    DECLARE @TripID INT;
    
    -- Get segment and trip info
    SELECT 
        @SegmentID = p.SegmentID,
        @TripID = COALESCE(p.TripID, rs.TripID)
    FROM dbo.Payment p
    LEFT JOIN dbo.RideSegment rs ON p.SegmentID = rs.SegmentID
    WHERE p.PaymentID = @PaymentID;
    
    -- Update payment status
    UPDATE dbo.Payment
    SET Status = 'completed',
        CompletedAt = SYSDATETIME(),
        ProviderReference = COALESCE(@ProviderReference, ProviderReference),
        TripID = @TripID  -- Update TripID if it was assigned after payment was created
    WHERE PaymentID = @PaymentID;
    
    SELECT 1 AS Success, 'Payment completed successfully' AS Message;
END
GO

-- 6) Complete trip payment (marks payment as completed for regular trips - passenger initiated)
CREATE OR ALTER PROCEDURE dbo.spCompleteTripPayment
    @PaymentID INT,
    @PaymentMethodTypeID INT = NULL,
    @ProviderReference NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @TripID INT;
    DECLARE @CurrentStatus NVARCHAR(50);
    
    -- Get payment info
    SELECT 
        @TripID = p.TripID,
        @CurrentStatus = p.Status
    FROM dbo.Payment p
    WHERE p.PaymentID = @PaymentID;
    
    IF @TripID IS NULL
    BEGIN
        SELECT 0 AS Success, 'Payment not found' AS Message;
        RETURN;
    END
    
    IF @CurrentStatus = 'completed'
    BEGIN
        SELECT 0 AS Success, 'Payment already completed' AS Message;
        RETURN;
    END
    
    -- Update payment status to completed
    UPDATE dbo.Payment
    SET Status = 'completed',
        CompletedAt = SYSDATETIME(),
        PaymentMethodTypeID = COALESCE(@PaymentMethodTypeID, PaymentMethodTypeID),
        ProviderReference = COALESCE(@ProviderReference, ProviderReference)
    WHERE PaymentID = @PaymentID;
    
    SELECT 1 AS Success, 'Payment completed successfully' AS Message, @TripID AS TripID;
END
GO

-- G. MESSAGES & RATINGS

-- 1) Send message between users
CREATE OR ALTER PROCEDURE dbo.spSendMessage
    @FromUserID INT,
    @ToUserID   INT,
    @TripID     INT = NULL,
    @Content    NVARCHAR(MAX)
AS
BEGIN
    INSERT INTO dbo.Message (FromUserID, ToUserID, TripID, Content, SentAt, IsSystem)
    VALUES (@FromUserID, @ToUserID, @TripID, @Content, SYSDATETIME(), 0);
END
GO

-- 2) Get conversation between two users
CREATE OR ALTER PROCEDURE dbo.spGetConversation
    @User1ID INT,
    @User2ID INT
AS
BEGIN
    SELECT
        m.MessageID,
        m.FromUserID,
        m.ToUserID,
        m.TripID,
        m.Content,
        m.SentAt,
        m.IsSystem
    FROM dbo.Message m
    WHERE (m.FromUserID = @User1ID AND m.ToUserID = @User2ID)
       OR (m.FromUserID = @User2ID AND m.ToUserID = @User1ID)
    ORDER BY m.SentAt;
END
GO

-- 3) Rate a trip
CREATE OR ALTER PROCEDURE dbo.spRateTrip
    @TripID     INT,
    @FromUserID INT,
    @ToUserID   INT,
    @Stars      INT,
    @Comment    NVARCHAR(1000) = NULL
AS
BEGIN
    IF @Stars < 1 OR @Stars > 5
    BEGIN
        RAISERROR('Stars must be between 1 and 5.', 16, 1);
        RETURN;
    END

    IF EXISTS (SELECT 1 FROM dbo.Rating WHERE TripID = @TripID AND FromUserID = @FromUserID)
    BEGIN
        UPDATE dbo.Rating
            SET ToUserID = @ToUserID,
                Stars    = @Stars,
                Comment  = @Comment,
                CreatedAt = SYSDATETIME()
        WHERE TripID = @TripID AND FromUserID = @FromUserID;
    END
    ELSE
    BEGIN
        INSERT INTO dbo.Rating (TripID, FromUserID, ToUserID, Stars, Comment, CreatedAt)
        VALUES (@TripID, @FromUserID, @ToUserID, @Stars, @Comment, SYSDATETIME());
    END
END
GO

-- H. ANALYTIC REPORTS

-- Helper view: completed trips with useful joins
CREATE OR ALTER VIEW dbo.vCompletedTrips
AS
SELECT
    t.TripID,
    t.StartTime,
    t.EndTime,
    t.TotalDistanceKm,
    t.TotalDurationSec,
    rr.RequestedAt,
    rr.PickupLocationID,
    rr.DropoffLocationID,
    v.VehicleID,
    vt.VehicleTypeID,
    vt.Name AS VehicleTypeName,
    d.DriverID,
    du.FullName AS DriverName,
    p.PassengerID,
    pu.FullName AS PassengerName,
    pmt.Amount,
    pmt.CurrencyCode,
    -- Add location details
    pickup.LatDegrees AS PickupLat,
    pickup.LonDegrees AS PickupLng,
    pickup.PostalCode AS PickupPostalCode,
    pickup.StreetAddress AS PickupAddress,
    dropoff.LatDegrees AS DropoffLat,
    dropoff.LonDegrees AS DropoffLng,
    dropoff.PostalCode AS DropoffPostalCode,
    dropoff.StreetAddress AS DropoffAddress
FROM dbo.Trip t
JOIN dbo.RideRequest rr   ON t.RideRequestID = rr.RideRequestID
JOIN dbo.Driver d         ON t.DriverID = d.DriverID
JOIN dbo.[User] du        ON d.UserID = du.UserID
JOIN dbo.Passenger p      ON rr.PassengerID = p.PassengerID
JOIN dbo.[User] pu        ON p.UserID = pu.UserID
JOIN dbo.Vehicle v        ON t.VehicleID = v.VehicleID
JOIN dbo.VehicleType vt   ON v.VehicleTypeID = vt.VehicleTypeID
LEFT JOIN dbo.Payment pmt ON pmt.TripID = t.TripID AND pmt.Status = 'completed'
LEFT JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
LEFT JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
WHERE t.Status = 'completed';
GO

-- =============================================
-- COMPREHENSIVE TRIP ANALYSIS REPORT
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spReportTripAnalysis
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL, -- Comma-separated IDs
    @PostalCode NVARCHAR(20) = NULL,
    @CenterLat DECIMAL(9,6) = NULL,
    @CenterLng DECIMAL(9,6) = NULL,
    @RadiusKm DECIMAL(10,2) = NULL,
    @GroupBy NVARCHAR(50) = NULL -- 'day', 'week', 'month', 'quarter', 'year', 'vehicle_type', 'postal_code'
AS
BEGIN
    SET NOCOUNT ON;
    
    ;WITH FilteredTrips AS (
        SELECT *
        FROM dbo.vCompletedTrips ct
        WHERE (@FromDate IS NULL OR ct.StartTime >= @FromDate)
          AND (@ToDate IS NULL OR ct.StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
          AND (@CenterLat IS NULL OR @CenterLng IS NULL OR @RadiusKm IS NULL OR
               SQRT(POWER((ct.PickupLat - @CenterLat) * 111, 2) + POWER((ct.PickupLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm
               OR SQRT(POWER((ct.DropoffLat - @CenterLat) * 111, 2) + POWER((ct.DropoffLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm)
    )
    SELECT
        CASE
            WHEN @GroupBy = 'day' THEN CAST(CAST(StartTime AS DATE) AS NVARCHAR(50))
            WHEN @GroupBy = 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, StartTime), CAST(StartTime AS DATE)) AS NVARCHAR(50))
            WHEN @GroupBy = 'month' THEN FORMAT(StartTime, 'yyyy-MM')
            WHEN @GroupBy = 'quarter' THEN CAST(YEAR(StartTime) AS NVARCHAR) + '-Q' + CAST(DATEPART(QUARTER, StartTime) AS NVARCHAR)
            WHEN @GroupBy = 'year' THEN CAST(YEAR(StartTime) AS NVARCHAR)
            WHEN @GroupBy = 'vehicle_type' THEN CAST(VehicleTypeName AS NVARCHAR(50))
            WHEN @GroupBy = 'postal_code' THEN CAST(COALESCE(PickupPostalCode, 'Unknown') AS NVARCHAR(50))
            ELSE 'All'
        END AS GroupLabel,
        COUNT(*) AS TripCount,
        AVG(TotalDistanceKm) AS AvgDistance,
        AVG(Amount) AS AvgCost,
        SUM(Amount) AS TotalRevenue
    FROM FilteredTrips
    GROUP BY
        CASE
            WHEN @GroupBy = 'day' THEN CAST(CAST(StartTime AS DATE) AS NVARCHAR(50))
            WHEN @GroupBy = 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, StartTime), CAST(StartTime AS DATE)) AS NVARCHAR(50))
            WHEN @GroupBy = 'month' THEN FORMAT(StartTime, 'yyyy-MM')
            WHEN @GroupBy = 'quarter' THEN CAST(YEAR(StartTime) AS NVARCHAR) + '-Q' + CAST(DATEPART(QUARTER, StartTime) AS NVARCHAR)
            WHEN @GroupBy = 'year' THEN CAST(YEAR(StartTime) AS NVARCHAR)
            WHEN @GroupBy = 'vehicle_type' THEN CAST(VehicleTypeName AS NVARCHAR(50))
            WHEN @GroupBy = 'postal_code' THEN CAST(COALESCE(PickupPostalCode, 'Unknown') AS NVARCHAR(50))
            ELSE 'All'
        END
    ORDER BY TripCount DESC;
END
GO

-- 1) Number of trips (with optional filters)
CREATE OR ALTER PROCEDURE dbo.spReportTripCount
    @FromDate      DATE = NULL,
    @ToDate        DATE = NULL,
    @VehicleTypeID INT  = NULL
AS
BEGIN
    SELECT COUNT(*) AS TripCount
    FROM dbo.vCompletedTrips ct
    WHERE (@FromDate IS NULL OR ct.StartTime >= @FromDate)
      AND (@ToDate   IS NULL OR ct.StartTime < DATEADD(DAY, 1, @ToDate))
      AND (@VehicleTypeID IS NULL OR ct.VehicleTypeID = @VehicleTypeID);
END
GO

-- 2) Share of trips per vehicle type
CREATE OR ALTER PROCEDURE dbo.spReportTripShareByVehicleType
    @FromDate DATE = NULL,
    @ToDate   DATE = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @CenterLat DECIMAL(9,6) = NULL,
    @CenterLng DECIMAL(9,6) = NULL,
    @RadiusKm DECIMAL(10,2) = NULL
AS
BEGIN
    ;WITH Filtered AS (
        SELECT * FROM dbo.vCompletedTrips
        WHERE (@FromDate IS NULL OR StartTime >= @FromDate)
          AND (@ToDate   IS NULL OR StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@PostalCode IS NULL OR PickupPostalCode = @PostalCode OR DropoffPostalCode = @PostalCode)
          AND (@CenterLat IS NULL OR @CenterLng IS NULL OR @RadiusKm IS NULL OR
               SQRT(POWER((PickupLat - @CenterLat) * 111, 2) + POWER((PickupLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm
               OR SQRT(POWER((DropoffLat - @CenterLat) * 111, 2) + POWER((DropoffLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm)
    )
    SELECT
        VehicleTypeName,
        COUNT(*) AS TripCount,
        100.0 * COUNT(*) / SUM(COUNT(*)) OVER () AS PercentageOfTotal
    FROM Filtered
    GROUP BY VehicleTypeName
    ORDER BY TripCount DESC;
END
GO

-- 3) Peak activity periods (count per hour, ordered desc)
CREATE OR ALTER PROCEDURE dbo.spReportPeakActivityByHour
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @GroupBy NVARCHAR(50) = 'hour' -- 'hour', 'day', 'week', 'month'
AS
BEGIN
    SET NOCOUNT ON;
    
    ;WITH Filtered AS (
        SELECT * FROM dbo.vCompletedTrips
        WHERE (@FromDate IS NULL OR StartTime >= @FromDate)
          AND (@ToDate IS NULL OR StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR PickupPostalCode = @PostalCode OR DropoffPostalCode = @PostalCode)
    )
    SELECT
        CASE
            WHEN @GroupBy = 'hour' THEN CAST(StartTime AS DATE)
            WHEN @GroupBy = 'day' THEN CAST(StartTime AS DATE)
            WHEN @GroupBy = 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, StartTime), CAST(StartTime AS DATE)) AS DATE)
            WHEN @GroupBy = 'month' THEN CAST(DATEADD(DAY, 1 - DAY(StartTime), CAST(StartTime AS DATE)) AS DATE)
        END AS PeriodDate,
        CASE
            WHEN @GroupBy = 'hour' THEN DATEPART(HOUR, StartTime)
            ELSE NULL
        END AS PeriodHour,
        COUNT(*) AS TripCount
    FROM Filtered
    GROUP BY
        CASE
            WHEN @GroupBy = 'hour' THEN CAST(StartTime AS DATE)
            WHEN @GroupBy = 'day' THEN CAST(StartTime AS DATE)
            WHEN @GroupBy = 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, StartTime), CAST(StartTime AS DATE)) AS DATE)
            WHEN @GroupBy = 'month' THEN CAST(DATEADD(DAY, 1 - DAY(StartTime), CAST(StartTime AS DATE)) AS DATE)
        END,
        CASE
            WHEN @GroupBy = 'hour' THEN DATEPART(HOUR, StartTime)
            ELSE NULL
        END
    ORDER BY TripCount DESC;
END
GO

-- 4) Average trip cost per vehicle type
CREATE OR ALTER PROCEDURE dbo.spReportAverageCostByVehicleType
    @FromDate DATE = NULL,
    @ToDate   DATE = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @CenterLat DECIMAL(9,6) = NULL,
    @CenterLng DECIMAL(9,6) = NULL,
    @RadiusKm DECIMAL(10,2) = NULL
AS
BEGIN
    SELECT
        VehicleTypeName,
        AVG(Amount) AS AvgCost,
        COUNT(*)    AS TripCount
    FROM dbo.vCompletedTrips
    WHERE Amount IS NOT NULL
      AND (@FromDate IS NULL OR StartTime >= @FromDate)
      AND (@ToDate   IS NULL OR StartTime < DATEADD(DAY, 1, @ToDate))
      AND (@PostalCode IS NULL OR PickupPostalCode = @PostalCode OR DropoffPostalCode = @PostalCode)
      AND (@CenterLat IS NULL OR @CenterLng IS NULL OR @RadiusKm IS NULL OR
           SQRT(POWER((PickupLat - @CenterLat) * 111, 2) + POWER((PickupLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm
           OR SQRT(POWER((DropoffLat - @CenterLat) * 111, 2) + POWER((DropoffLng - @CenterLng) * 111 * COS(RADIANS(@CenterLat)), 2)) <= @RadiusKm)
    GROUP BY VehicleTypeName
    ORDER BY AvgCost DESC;
END
GO

-- 5) Trips with highest and lowest costs (top N each)
CREATE OR ALTER PROCEDURE dbo.spReportExtremeTripCosts
    @FromDate DATE = NULL,
    @ToDate   DATE = NULL,
    @TopN     INT  = 10,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL,
    @PostalCode NVARCHAR(20) = NULL
AS
BEGIN
    ;WITH Filtered AS (
        SELECT *,
               ROW_NUMBER() OVER (ORDER BY Amount DESC) AS rnHigh,
               ROW_NUMBER() OVER (ORDER BY Amount ASC)  AS rnLow
        FROM dbo.vCompletedTrips
        WHERE Amount IS NOT NULL
          AND (@FromDate IS NULL OR StartTime >= @FromDate)
          AND (@ToDate   IS NULL OR StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR PickupPostalCode = @PostalCode OR DropoffPostalCode = @PostalCode)
    )
    SELECT 'HIGHEST' AS Category, *
    FROM Filtered
    WHERE rnHigh <= @TopN
    UNION ALL
    SELECT 'LOWEST'  AS Category, *
    FROM Filtered
    WHERE rnLow  <= @TopN
    ORDER BY Category, Amount DESC;
END
GO

-- 6) Driver performance: completed trips & avg rating
CREATE OR ALTER PROCEDURE dbo.spReportDriverPerformance
    @FromDate DATE = NULL,
    @ToDate   DATE = NULL,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @MinTrips INT = NULL
AS
BEGIN
    ;WITH FilteredTrips AS (
        SELECT * FROM dbo.vCompletedTrips
        WHERE (@FromDate IS NULL OR StartTime >= @FromDate)
          AND (@ToDate   IS NULL OR StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR PickupPostalCode = @PostalCode OR DropoffPostalCode = @PostalCode)
    )
    SELECT
        d.DriverID,
        du.FullName AS DriverName,
        COUNT(DISTINCT ft.TripID) AS CompletedTrips,
        AVG(CAST(r.Stars AS DECIMAL(3,2))) AS AvgRating,
        SUM(ft.Amount) AS TotalEarnings
    FROM FilteredTrips ft
    JOIN dbo.Driver d    ON ft.DriverID = d.DriverID
    JOIN dbo.[User] du   ON d.UserID = du.UserID
    LEFT JOIN dbo.Rating r ON r.ToUserID = du.UserID
    GROUP BY d.DriverID, du.FullName
    HAVING (@MinTrips IS NULL OR COUNT(DISTINCT ft.TripID) >= @MinTrips)
    ORDER BY CompletedTrips DESC;
END
GO

-- 7) Driver earnings: per month this year + last 3 years summary
CREATE OR ALTER PROCEDURE dbo.spReportDriverEarnings
    @DriverID INT,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL,
    @PostalCode NVARCHAR(20) = NULL
AS
BEGIN
    DECLARE @CurrentYear INT = YEAR(SYSDATETIME());

    -- Monthly for current year
    SELECT
        YEAR(ct.StartTime)  AS Year,
        MONTH(ct.StartTime) AS Month,
        SUM(ct.Amount)      AS TotalEarnings,
        COUNT(*)            AS CompletedTrips
    FROM dbo.vCompletedTrips ct
    WHERE ct.DriverID = @DriverID
      AND ct.Amount IS NOT NULL
      AND YEAR(ct.StartTime) = @CurrentYear
      AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
      AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
    GROUP BY YEAR(ct.StartTime), MONTH(ct.StartTime)
    ORDER BY Year, Month;

    -- Totals for last 3 years
    SELECT
        YEAR(ct.StartTime) AS Year,
        SUM(ct.Amount)     AS TotalEarnings,
        COUNT(*)           AS CompletedTrips
    FROM dbo.vCompletedTrips ct
    WHERE ct.DriverID = @DriverID
      AND ct.Amount IS NOT NULL
      AND YEAR(ct.StartTime) BETWEEN @CurrentYear - 2 AND @CurrentYear
      AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
      AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
    GROUP BY YEAR(ct.StartTime)
    ORDER BY Year;
END
GO

-- =============================================
-- ALL DRIVERS EARNINGS REPORT (for operators)
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spReportAllDriversEarnings
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @VehicleTypeIDs NVARCHAR(MAX) = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @GroupBy NVARCHAR(50) = 'driver' -- 'driver', 'month', 'year'
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @GroupBy = 'driver'
    BEGIN
        SELECT
            d.DriverID,
            du.FullName AS DriverName,
            COUNT(*) AS CompletedTrips,
            SUM(ct.Amount) AS TotalEarnings,
            AVG(ct.Amount) AS AvgEarningPerTrip
        FROM dbo.vCompletedTrips ct
        JOIN dbo.Driver d ON ct.DriverID = d.DriverID
        JOIN dbo.[User] du ON d.UserID = du.UserID
        WHERE ct.Amount IS NOT NULL
          AND (@FromDate IS NULL OR ct.StartTime >= @FromDate)
          AND (@ToDate IS NULL OR ct.StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
        GROUP BY d.DriverID, du.FullName
        ORDER BY TotalEarnings DESC;
    END
    ELSE IF @GroupBy = 'month'
    BEGIN
        SELECT
            FORMAT(ct.StartTime, 'yyyy-MM') AS Period,
            COUNT(*) AS CompletedTrips,
            SUM(ct.Amount) AS TotalEarnings,
            AVG(ct.Amount) AS AvgEarningPerTrip
        FROM dbo.vCompletedTrips ct
        WHERE ct.Amount IS NOT NULL
          AND (@FromDate IS NULL OR ct.StartTime >= @FromDate)
          AND (@ToDate IS NULL OR ct.StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
        GROUP BY FORMAT(ct.StartTime, 'yyyy-MM')
        ORDER BY Period;
    END
    ELSE IF @GroupBy = 'year'
    BEGIN
        SELECT
            YEAR(ct.StartTime) AS Period,
            COUNT(*) AS CompletedTrips,
            SUM(ct.Amount) AS TotalEarnings,
            AVG(ct.Amount) AS AvgEarningPerTrip
        FROM dbo.vCompletedTrips ct
        WHERE ct.Amount IS NOT NULL
          AND (@FromDate IS NULL OR ct.StartTime >= @FromDate)
          AND (@ToDate IS NULL OR ct.StartTime < DATEADD(DAY, 1, @ToDate))
          AND (@VehicleTypeIDs IS NULL OR ct.VehicleTypeID IN (SELECT value FROM STRING_SPLIT(@VehicleTypeIDs, ',')))
          AND (@PostalCode IS NULL OR ct.PickupPostalCode = @PostalCode OR ct.DropoffPostalCode = @PostalCode)
        GROUP BY YEAR(ct.StartTime)
        ORDER BY Period;
    END
END
GO

/* ============================================================
   AUTONOMOUS VEHICLE REPORT PROCEDURES
   ============================================================ */

-- AV Ride Analysis Report
CREATE OR ALTER PROCEDURE dbo.spReportAVAnalysis
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @GroupBy NVARCHAR(50) = 'day' -- 'day', 'week', 'month', 'vehicle'
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(ar.TripCompletedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, ar.TripCompletedAt), CAST(ar.TripCompletedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(ar.TripCompletedAt, 'yyyy-MM')
            WHEN 'vehicle' THEN av.VehicleCode
            ELSE 'All'
        END AS GroupLabel,
        COUNT(*) AS RideCount,
        ISNULL(AVG(ar.ActualDistanceKm), 0) AS AvgDistance,
        ISNULL(AVG(arp.Amount), 0) AS AvgFare,
        ISNULL(SUM(arp.Amount), 0) AS TotalRevenue,
        ISNULL(SUM(arp.ServiceFeeAmount), 0) AS TotalServiceFees
    FROM dbo.AutonomousRide ar
    JOIN dbo.AutonomousVehicle av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    LEFT JOIN dbo.AutonomousRidePayment arp ON ar.AutonomousRideID = arp.AutonomousRideID AND arp.Status = 'completed'
    WHERE ar.Status = 'completed'
      AND CAST(ar.TripCompletedAt AS DATE) BETWEEN @FromDate AND @ToDate
    GROUP BY
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(ar.TripCompletedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, ar.TripCompletedAt), CAST(ar.TripCompletedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(ar.TripCompletedAt, 'yyyy-MM')
            WHEN 'vehicle' THEN av.VehicleCode
            ELSE 'All'
        END
    ORDER BY TotalRevenue DESC;
END
GO

-- AV Vehicle Performance Report
CREATE OR ALTER PROCEDURE dbo.spReportAVVehiclePerformance
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT TOP (@TopN)
        av.VehicleCode,
        av.Make + ' ' + av.Model AS VehicleName,
        av.Status AS CurrentStatus,
        COUNT(ar.AutonomousRideID) AS TotalRides,
        ISNULL(SUM(ar.ActualDistanceKm), 0) AS TotalDistanceKm,
        ISNULL(AVG(ar.ActualDistanceKm), 0) AS AvgDistancePerRide,
        ISNULL(SUM(arp.Amount), 0) AS TotalRevenue,
        ISNULL(AVG(arp.Amount), 0) AS AvgFare,
        ISNULL(SUM(arp.ServiceFeeAmount), 0) AS TotalServiceFees,
        ISNULL(AVG(arr.Stars), 0) AS AvgRating
    FROM dbo.AutonomousVehicle av
    LEFT JOIN dbo.AutonomousRide ar ON av.AutonomousVehicleID = ar.AutonomousVehicleID
        AND ar.Status = 'completed'
        AND CAST(ar.TripCompletedAt AS DATE) BETWEEN @FromDate AND @ToDate
    LEFT JOIN dbo.AutonomousRidePayment arp ON ar.AutonomousRideID = arp.AutonomousRideID AND arp.Status = 'completed'
    LEFT JOIN dbo.AutonomousRideRating arr ON ar.AutonomousRideID = arr.AutonomousRideID
    GROUP BY av.AutonomousVehicleID, av.VehicleCode, av.Make, av.Model, av.Status
    ORDER BY TotalRevenue DESC;
END
GO

/* ============================================================
   CARSHARE REPORT PROCEDURES
   ============================================================ */

-- Carshare Rental Analysis Report
CREATE OR ALTER PROCEDURE dbo.spReportCarshareAnalysis
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @GroupBy NVARCHAR(50) = 'day' -- 'day', 'week', 'month', 'vehicle_type', 'zone'
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, r.StartedAt), CAST(r.StartedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(r.StartedAt, 'yyyy-MM')
            WHEN 'vehicle_type' THEN vt.TypeName
            WHEN 'zone' THEN z.ZoneName
            ELSE 'All'
        END AS GroupLabel,
        COUNT(*) AS RentalCount,
        ISNULL(AVG(r.TotalDurationMin), 0) AS AvgDurationMin,
        ISNULL(AVG(r.DistanceKm), 0) AS AvgDistanceKm,
        ISNULL(AVG(r.TotalCost), 0) AS AvgCost,
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        0 AS TotalServiceFees
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    JOIN dbo.CarshareZone z ON r.StartZoneID = z.ZoneID
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @FromDate AND @ToDate
    GROUP BY
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, r.StartedAt), CAST(r.StartedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(r.StartedAt, 'yyyy-MM')
            WHEN 'vehicle_type' THEN vt.TypeName
            WHEN 'zone' THEN z.ZoneName
            ELSE 'All'
        END
    ORDER BY TotalRevenue DESC;
END
GO

-- Carshare Vehicle Performance Report
CREATE OR ALTER PROCEDURE dbo.spReportCarshareVehiclePerformance
    @FromDate DATE = NULL,
    @ToDate DATE = NULL,
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT TOP (@TopN)
        v.PlateNumber,
        v.Make + ' ' + v.Model AS VehicleName,
        vt.TypeName,
        v.Status AS CurrentStatus,
        COUNT(r.RentalID) AS TotalRentals,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutesUsed,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistanceKm,
        ISNULL(AVG(r.TotalDurationMin), 0) AS AvgDurationMin,
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        ISNULL(AVG(r.TotalCost), 0) AS AvgRentalCost,
        0 AS TotalServiceFees
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareRental r ON v.VehicleID = r.VehicleID
        AND r.Status = 'completed'
        AND CAST(r.StartedAt AS DATE) BETWEEN @FromDate AND @ToDate
    WHERE v.IsActive = 1
    GROUP BY v.VehicleID, v.PlateNumber, v.Make, v.Model, vt.TypeName, v.Status
    ORDER BY TotalRevenue DESC;
END
GO

-- Carshare Zone Performance Report
CREATE OR ALTER PROCEDURE dbo.spReportCarshareZonePerformance
    @FromDate DATE = NULL,
    @ToDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT
        z.ZoneName,
        z.City,
        z.ZoneType,
        COUNT(r.RentalID) AS TotalRentals,
        COUNT(DISTINCT r.CustomerID) AS UniqueCustomers,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutesRented,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistanceKm,
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        ISNULL(AVG(r.TotalCost), 0) AS AvgRentalCost,
        ISNULL(SUM(r.InterCityFee), 0) AS TotalInterCityFees,
        z.CurrentVehicleCount AS CurrentVehicles,
        z.MaxCapacity
    FROM dbo.CarshareZone z
    LEFT JOIN dbo.CarshareRental r ON z.ZoneID = r.StartZoneID
        AND r.Status = 'completed'
        AND CAST(r.StartedAt AS DATE) BETWEEN @FromDate AND @ToDate
    WHERE z.IsActive = 1
    GROUP BY z.ZoneID, z.ZoneName, z.City, z.ZoneType, z.CurrentVehicleCount, z.MaxCapacity
    ORDER BY TotalRevenue DESC;
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Currency WHERE CurrencyCode = 'EUR')
INSERT INTO dbo.Currency (CurrencyCode, Name)
VALUES ('EUR', 'Euro');

-- only for dba to use!!!
GO
CREATE OR ALTER PROCEDURE dbo.spPromotePassengerToOperator
    @PassengerID INT,
    @Role        NVARCHAR(50) = N'Operator'   -- only Operator allowed from app
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @UserID INT;

    -- 1) Find the UserID behind this passenger
    SELECT @UserID = UserID
    FROM dbo.Passenger
    WHERE PassengerID = @PassengerID;

    IF @UserID IS NULL
    BEGIN
        RAISERROR('Passenger not found.', 16, 1);
        RETURN;
    END

    -- 2) You said: admin is seeded manually → block Admin promotion here
    IF @Role = N'Admin'
    BEGIN
        RAISERROR('Cannot promote to Admin via this procedure.', 16, 1);
        RETURN;
    END

    -- 3) Don’t create duplicate Operator rows
    IF EXISTS (SELECT 1 FROM dbo.Operator WHERE UserID = @UserID)
    BEGIN
        RAISERROR('User is already an operator.', 16, 1);
        RETURN;
    END

    -- 4) Create operator record
    INSERT INTO dbo.Operator (UserID, Role)
    VALUES (@UserID, @Role);

    SELECT
        OperatorID = SCOPE_IDENTITY(),
        UserID     = @UserID,
        Role       = @Role;
END;
GO

-- 20/11/2025
CREATE OR ALTER PROCEDURE dbo.spDriverGetTripWithLocations
    @TripID INT,
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        t.TripID,
        t.RideRequestID,
        t.DriverID,
        t.VehicleID,
        t.Status,
        t.StartTime AS StartedAt,
        t.EndTime AS CompletedAt,
        t.TotalDistanceKm AS DistanceKm,
        CAST(t.TotalDurationSec / 60.0 AS DECIMAL(10,2)) AS DurationMinutes,
        rr.RequestedAt AS CreatedAt,
        rr.PassengerNotes AS Notes,
        rr.LuggageVolume AS LuggageCount,
        rr.WheelchairNeeded AS RequiresWheelchair,
        rr.PaymentMethodTypeID,
        pmt.Code AS PaymentMethodCode,
        pmt.Description AS PaymentMethodDescription,
        -- Format payment method display with text and color class (no emojis to avoid encoding issues)
        CASE 
            WHEN pmt.Code = 'CARD' THEN 'Card'
            WHEN pmt.Code = 'CASH' THEN 'Cash'
            ELSE 'Not specified'
        END AS PaymentMethodDisplay,
        CASE 
            WHEN pmt.Code = 'CARD' THEN 'card'
            WHEN pmt.Code = 'CASH' THEN 'cash'
            ELSE 'unknown'
        END AS PaymentMethodClass,
        CAST(NULL AS DECIMAL(10,2)) AS EstimatedCost,
        -- For segment trips, get payment from segment payment; otherwise from trip payment
        COALESCE(segPay.Amount, pay.Amount) AS ActualCost,
        COALESCE(segPay.DriverEarnings, pay.DriverEarnings) AS DriverEarnings,
        COALESCE(segPay.ServiceFeeAmount, pay.ServiceFeeAmount) AS ServiceFeeAmount,
        COALESCE(segPay.BaseFare, pay.BaseFare) AS BaseFare,
        COALESCE(segPay.DistanceFare, pay.DistanceFare) AS DistanceFare,
        COALESCE(segPay.TimeFare, pay.TimeFare) AS TimeFare,
        COALESCE(segPay.SurgeMultiplier, pay.SurgeMultiplier) AS SurgeMultiplier,
        COALESCE(segPay.CurrencyCode, pay.CurrencyCode) AS CurrencyCode,
        -- Segment payment status - driver only sees earnings after passenger pays
        segPay.Status AS SegmentPaymentStatus,
        CASE 
            WHEN rs.SegmentID IS NOT NULL AND segPay.PaymentID IS NULL THEN 'awaiting_payment'
            WHEN rs.SegmentID IS NOT NULL AND segPay.Status = 'pending' THEN 'payment_pending'
            WHEN rs.SegmentID IS NOT NULL AND segPay.Status = 'completed' THEN 'payment_completed'
            ELSE NULL
        END AS SegmentPaymentState,
        rs.EstimatedFare AS SegmentEstimatedFare,
        p.PassengerID,
        uP.UserID AS PassengerUserID,
        uP.FullName AS PassengerName,
        uP.Email    AS PassengerEmail,
        v.PlateNo,
        v.VehicleTypeID,
        vt.Name AS VehicleTypeName,
        lp.LocationID AS PickupLocationID,
        COALESCE(lp.StreetAddress, lp.Description) AS PickupAddress,
        lp.LatDegrees AS PickupLat,
        lp.LonDegrees AS PickupLng,
        ld.LocationID AS DropoffLocationID,
        COALESCE(ld.StreetAddress, ld.Description) AS DropoffAddress,
        ld.LatDegrees AS DropoffLat,
        ld.LonDegrees AS DropoffLng,
        -- Segment information
        rs.SegmentID,
        rs.SegmentOrder,
        CASE WHEN rs.SegmentID IS NOT NULL THEN 1 ELSE 0 END AS IsSegmentTrip,
        -- Real driver trip flag (no tracking/simulation)
        ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip,
        -- Calculate segment distance if this is a segment trip
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN
                ROUND(
                    6371 * 2 * ATN2(
                        SQRT(
                            POWER(SIN(RADIANS(segToLoc.LatDegrees - segFromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(segFromLoc.LatDegrees)) * COS(RADIANS(segToLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(segToLoc.LonDegrees - segFromLoc.LonDegrees) / 2), 2)
                        ),
                        SQRT(1 - (
                            POWER(SIN(RADIANS(segToLoc.LatDegrees - segFromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(segFromLoc.LatDegrees)) * COS(RADIANS(segToLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(segToLoc.LonDegrees - segFromLoc.LonDegrees) / 2), 2)
                        ))
                    ) * 1.3,
                    2
                )
            ELSE NULL
        END AS SegmentDistanceKm,
        rr.EstimatedDistanceKm AS TotalTripDistanceKm
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p     ON rr.PassengerID = p.PassengerID
    JOIN dbo.[User] uP       ON p.UserID      = uP.UserID
    JOIN dbo.Vehicle v       ON t.VehicleID   = v.VehicleID
    JOIN dbo.VehicleType vt  ON v.VehicleTypeID = vt.VehicleTypeID
    JOIN dbo.Location lp     ON rr.PickupLocationID  = lp.LocationID
    JOIN dbo.Location ld     ON rr.DropoffLocationID = ld.LocationID
    LEFT JOIN dbo.PaymentMethodType pmt ON rr.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    LEFT JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
    LEFT JOIN dbo.Location segFromLoc ON rs.FromLocationID = segFromLoc.LocationID
    LEFT JOIN dbo.Location segToLoc ON rs.ToLocationID = segToLoc.LocationID
    -- Get segment payment (for segment trips)
    OUTER APPLY (
        SELECT TOP 1 
            spay.PaymentID,
            spay.Amount, 
            spay.CurrencyCode,
            spay.DriverEarnings,
            spay.ServiceFeeAmount,
            spay.BaseFare,
            spay.DistanceFare,
            spay.TimeFare,
            spay.SurgeMultiplier,
            spay.Status
        FROM dbo.Payment spay
        WHERE spay.SegmentID = rs.SegmentID
        ORDER BY spay.PaymentID DESC
    ) AS segPay
    -- Get trip payment (for regular trips)
    OUTER APPLY (
        SELECT TOP 1 
            pay.Amount, 
            pay.CurrencyCode,
            pay.DriverEarnings,
            pay.ServiceFeeAmount,
            pay.BaseFare,
            pay.DistanceFare,
            pay.TimeFare,
            pay.SurgeMultiplier
        FROM dbo.Payment pay
        WHERE pay.TripID = t.TripID AND pay.SegmentID IS NULL
        ORDER BY pay.PaymentID DESC
    ) AS pay
    WHERE t.TripID = @TripID AND t.DriverID = @DriverID;
END
GO
-- =====================================================
-- DRIVER ACTIVE TRIPS STORED PROCEDURE
-- =====================================================
-- This procedure retrieves all active trips for a driver
-- (trips that are assigned or in progress)

CREATE OR ALTER PROCEDURE dbo.spDriverGetActiveTrips
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        t.TripID,
        t.Status,
        t.StartTime,
        rr.RequestedAt,
        p.PassengerID,
        uP.FullName AS PassengerName,
        COALESCE(lp.StreetAddress, lp.Description) AS PickupAddress,
        COALESCE(ld.StreetAddress, ld.Description) AS DropoffAddress,
        rr.EstimatedDistanceKm AS DistanceKm,
        -- Calculate estimated total fare (what passenger will pay)
        dbo.fnCalculateTotalFare(
            rr.ServiceTypeID,
            COALESCE(rr.EstimatedDistanceKm, 1.0),
            COALESCE(rr.EstimatedDurationMin, 10)
        ) AS EstimatedTotal,
        -- Calculate estimated driver earnings (100% of total - no platform fee)
        dbo.fnCalculateEstimatedDriverPayment(
            rr.ServiceTypeID,
            COALESCE(rr.EstimatedDistanceKm, 1.0),
            COALESCE(rr.EstimatedDurationMin, 10)
        ) AS EstimatedDriverEarnings
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p     ON rr.PassengerID = p.PassengerID
    JOIN dbo.[User] uP       ON p.UserID = uP.UserID
    JOIN dbo.Location lp     ON rr.PickupLocationID = lp.LocationID
    JOIN dbo.Location ld     ON rr.DropoffLocationID = ld.LocationID
    WHERE t.DriverID = @DriverID
      AND t.Status IN ('assigned', 'in_progress')
    ORDER BY rr.RequestedAt DESC;
END
GO

-- =====================================================
-- DRIVER PREVIOUS TRIPS STORED PROCEDURE
-- =====================================================
-- This procedure retrieves previous completed/cancelled trips for a driver
-- Limited to the most recent N trips

CREATE OR ALTER PROCEDURE dbo.spDriverGetPreviousTrips
    @DriverID INT,
    @MaxResults INT = 10
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP (@MaxResults)
        t.TripID,
        t.Status,
        t.EndTime AS CompletedAt,
        t.TotalDistanceKm AS DistanceKm,
        rr.RequestedAt,
        p.PassengerID,
        uP.FullName AS PassengerName,
        pay.Amount,
        pay.CurrencyCode
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p     ON rr.PassengerID = p.PassengerID
    JOIN dbo.[User] uP       ON p.UserID = uP.UserID
    OUTER APPLY (
        SELECT TOP 1 pay.Amount, pay.CurrencyCode
        FROM dbo.Payment pay
        WHERE pay.TripID = t.TripID
        ORDER BY pay.PaymentID DESC
    ) AS pay
    WHERE t.DriverID = @DriverID
      AND t.Status IN ('completed', 'cancelled')
    ORDER BY t.EndTime DESC, t.TripID DESC;
END
GO
-- =====================================================
-- DRIVER LIST ALL TRIPS STORED PROCEDURE
-- =====================================================
-- This procedure retrieves ALL trips for a driver (active and history)
-- with full details including payment information

CREATE OR ALTER PROCEDURE dbo.spDriverListTrips
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        t.TripID,
        t.Status,
        t.StartTime,
        t.EndTime,
        rr.RequestedAt,
        rr.EstimatedFare AS EstimatedCost,
        -- For segment trips, use segment payment or segment fare; otherwise trip payment
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN COALESCE(segPay.Amount, rs.EstimatedFare)
            ELSE pay.Amount
        END AS ActualCost,
        t.TotalDistanceKm AS DistanceKm,
        CAST(t.TotalDurationSec / 60.0 AS DECIMAL(10,2)) AS DurationMinutes,
        rr.PassengerID,
        u.FullName AS PassengerName,
        -- For segment trips, show segment payment/fare; for regular trips, show trip payment
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN COALESCE(segPay.Amount, rs.EstimatedFare)
            ELSE pay.Amount
        END AS Amount,
        COALESCE(segPay.CurrencyCode, pay.CurrencyCode, 'EUR') AS CurrencyCode,
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN segPay.Status
            ELSE pay.Status
        END AS PaymentStatus,
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN segPay.CompletedAt
            ELSE pay.CompletedAt
        END AS PaymentCompletedAt,
        -- Segment information
        rs.SegmentID,
        rs.SegmentOrder,
        -- Segment fare with fallback calculation when NULL
        -- Uses service type pricing: Base + (Distance * PerKm) + (Duration * PerMin)
        CASE 
            WHEN rs.SegmentID IS NOT NULL AND rs.EstimatedFare IS NOT NULL THEN rs.EstimatedFare
            WHEN rs.SegmentID IS NOT NULL THEN
                -- Calculate on-the-fly if EstimatedFare is NULL
                ROUND(
                    -- Base fare based on service type
                    CASE rr.ServiceTypeID
                        WHEN 1 THEN 3.00  -- Standard
                        WHEN 2 THEN 8.00  -- Luxury
                        WHEN 3 THEN 5.00  -- Light Cargo
                        WHEN 4 THEN 10.00 -- Heavy Cargo
                        WHEN 5 THEN 4.00  -- Multi-Stop
                        ELSE 3.00
                    END
                    +
                    -- Per km rate
                    (COALESCE(rs.EstimatedDistanceKm,
                        6371 * 2 * ATN2(
                            SQRT(
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ),
                            SQRT(1 - (
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ))
                        ) * 1.3
                    ) * CASE rr.ServiceTypeID
                        WHEN 1 THEN 1.20
                        WHEN 2 THEN 2.50
                        WHEN 3 THEN 1.80
                        WHEN 4 THEN 2.20
                        WHEN 5 THEN 1.40
                        ELSE 1.20
                    END)
                    +
                    -- Per minute rate (estimated at 40km/h)
                    (CEILING((COALESCE(rs.EstimatedDistanceKm,
                        6371 * 2 * ATN2(
                            SQRT(
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ),
                            SQRT(1 - (
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ))
                        ) * 1.3
                    ) / 40.0) * 60) * CASE rr.ServiceTypeID
                        WHEN 1 THEN 0.20
                        WHEN 2 THEN 0.40
                        WHEN 3 THEN 0.25
                        WHEN 4 THEN 0.30
                        WHEN 5 THEN 0.22
                        ELSE 0.20
                    END)
                , 2)
            ELSE NULL
        END AS SegmentEstimatedFare,
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN 1
            ELSE 0
        END AS IsSegmentTrip,
        -- Segment payment state for UI
        CASE 
            WHEN rs.SegmentID IS NOT NULL AND segPay.PaymentID IS NULL THEN 'awaiting_payment'
            WHEN rs.SegmentID IS NOT NULL AND segPay.Status = 'pending' THEN 'payment_pending'
            WHEN rs.SegmentID IS NOT NULL AND segPay.Status = 'completed' THEN 'payment_completed'
            ELSE NULL
        END AS SegmentPaymentState,
        -- Real driver trip flag (no tracking/simulation)
        ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip,
        fromLoc.Description AS SegmentFromLocation,
        toLoc.Description AS SegmentToLocation,
        fromBridge.BridgeName AS SegmentFromBridge,
        toBridge.BridgeName AS SegmentToBridge,
        g.Name AS SegmentGeofenceName,
        -- Calculate segment distance
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN
                COALESCE(rs.EstimatedDistanceKm,
                    ROUND(
                        6371 * 2 * ATN2(
                            SQRT(
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ),
                            SQRT(1 - (
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ))
                        ) * 1.3,
                        2
                    )
                )
            ELSE NULL
        END AS SegmentDistanceKm,
        -- Get total trip distance from ride request
        rr.EstimatedDistanceKm AS TotalTripDistanceKm,
        -- Calculate driver earnings - only show actual earnings if payment completed
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN
                -- For segment trips
                CASE 
                    WHEN segPay.Status = 'completed' THEN
                        -- Only show actual earnings if passenger has paid
                        COALESCE(segPay.DriverEarnings, segPay.Amount)
                    ELSE
                        -- Show estimated earnings (greyed out in UI)
                        -- Use segment fare with fallback calculation
                        ROUND(
                            COALESCE(rs.EstimatedFare,
                                -- Calculate on-the-fly if EstimatedFare is NULL
                                ROUND(
                                    CASE rr.ServiceTypeID
                                        WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00
                                        WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00
                                    END
                                    + (COALESCE(rs.EstimatedDistanceKm,
                                        6371 * 2 * ATN2(
                                            SQRT(
                                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                                            ),
                                            SQRT(1 - (
                                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                                            ))
                                        ) * 1.3
                                    ) * CASE rr.ServiceTypeID
                                        WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80
                                        WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20
                                    END)
                                    + (CEILING((COALESCE(rs.EstimatedDistanceKm,
                                        6371 * 2 * ATN2(
                                            SQRT(
                                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                                            ),
                                            SQRT(1 - (
                                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                                            ))
                                        ) * 1.3
                                    ) / 40.0) * 60) * CASE rr.ServiceTypeID
                                        WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25
                                        WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20
                                    END)
                                , 2)
                            )
                        , 2)
                END
            ELSE
                -- For full trips
                CASE 
                    WHEN pay.Status = 'completed' THEN
                        COALESCE(pay.DriverEarnings, pay.Amount)
                    ELSE
                        COALESCE(rr.EstimatedFare, 0)
                END
        END AS EstimatedDriverEarnings,
        -- Flag to indicate if earnings are confirmed (payment completed)
        CASE 
            WHEN rs.SegmentID IS NOT NULL THEN
                CASE WHEN segPay.Status = 'completed' THEN 1 ELSE 0 END
            ELSE
                CASE WHEN pay.Status = 'completed' THEN 1 ELSE 0 END
        END AS EarningsConfirmed
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON rr.RideRequestID = t.RideRequestID
    JOIN dbo.Passenger p    ON p.PassengerID     = rr.PassengerID
    JOIN dbo.[User] u       ON u.UserID          = p.UserID
    LEFT JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
    LEFT JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    LEFT JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    LEFT JOIN dbo.GeofenceBridge fromBridge ON rs.FromBridgeID = fromBridge.BridgeID
    LEFT JOIN dbo.GeofenceBridge toBridge ON rs.ToBridgeID = toBridge.BridgeID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    -- Get segment payment (for segment trips)
    OUTER APPLY (
        SELECT TOP 1
            spy.PaymentID,
            spy.Amount,
            spy.CurrencyCode,
            spy.Status,
            spy.CompletedAt,
            spy.DriverEarnings
        FROM dbo.Payment spy
        WHERE spy.SegmentID = rs.SegmentID
        ORDER BY spy.CreatedAt DESC
    ) segPay
    -- Get trip payment (for regular trips)
    OUTER APPLY (
        SELECT TOP 1
            py.PaymentID,
            py.Amount,
            py.CurrencyCode,
            py.Status,
            py.CompletedAt,
            py.DriverEarnings
        FROM dbo.Payment py
        WHERE py.TripID = t.TripID AND py.SegmentID IS NULL
        ORDER BY
            CASE WHEN py.Status = 'completed' THEN 0 ELSE 1 END,
            py.CreatedAt DESC
    ) pay
    WHERE t.DriverID = @DriverID
    ORDER BY rr.RequestedAt DESC, t.TripID DESC;
END
GO

-- =====================================================
-- PROCESS TRIP PAYMENT (MUST BE BEFORE spDriverUpdateTripStatus)
-- =====================================================
-- Process Payment for Trip
CREATE OR ALTER PROCEDURE dbo.spProcessTripPayment
    @TripID INT,
    @PaymentMethodTypeID INT,
    @TipAmount DECIMAL(10,2) = 0.00,
    @ProviderReference NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if this is a segment-based trip - if so, do NOT create a whole-trip payment
    -- Segment trips have payments created separately via spCreatePaymentForSegment when passenger pays per segment
    IF EXISTS (
        SELECT 1 
        FROM dbo.Trip t
        JOIN dbo.RideSegment rs ON t.TripID = rs.TripID
        WHERE t.TripID = @TripID
    )
    BEGIN
        -- Just return without producing any result set for segment trips
        RETURN;
    END
    
    BEGIN TRANSACTION;
    
    BEGIN TRY
        DECLARE @RideRequestID INT;
        DECLARE @DriverID INT;
        DECLARE @DistanceKm DECIMAL(10,3);
        DECLARE @DurationSec INT;
        DECLARE @ServiceTypeID INT;
        DECLARE @TripStatus NVARCHAR(30);
        
        SELECT 
            @RideRequestID = t.RideRequestID,
            @DriverID = t.DriverID,
            @DistanceKm = t.TotalDistanceKm,
            @DurationSec = t.TotalDurationSec,
            @TripStatus = t.Status,
            @ServiceTypeID = rr.ServiceTypeID
        FROM dbo.Trip t
        JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        WHERE t.TripID = @TripID;
        
        IF @RideRequestID IS NULL
        BEGIN
            RAISERROR('Trip not found.', 16, 1);
            RETURN;
        END
        
        IF EXISTS (SELECT 1 FROM dbo.Payment WHERE TripID = @TripID AND Status = 'completed')
        BEGIN
            RAISERROR('Payment already completed for this trip.', 16, 1);
            RETURN;
        END
        
        DECLARE @BaseFare DECIMAL(10,2);
        DECLARE @PricePerKm DECIMAL(10,2);
        DECLARE @PricePerMinute DECIMAL(10,2);
        DECLARE @MinimumFare DECIMAL(10,2);
        DECLARE @ServiceFeeRate DECIMAL(5,4);
        DECLARE @SurgeMultiplier DECIMAL(4,2);
        
        SELECT 
            @BaseFare = ISNULL(pc.BaseFare, 3.00),
            @PricePerKm = ISNULL(pc.PricePerKm, 1.20),
            @PricePerMinute = ISNULL(pc.PricePerMinute, 0.20),
            @MinimumFare = ISNULL(pc.MinimumFare, 5.00),
            @ServiceFeeRate = ISNULL(pc.ServiceFeeRate, 0.0000),
            @SurgeMultiplier = ISNULL(pc.SurgeMultiplier, 1.00)
        FROM dbo.PricingConfig pc
        WHERE pc.ServiceTypeID = @ServiceTypeID AND pc.IsActive = 1;
        
        IF @BaseFare IS NULL SET @BaseFare = 3.00;
        IF @PricePerKm IS NULL SET @PricePerKm = 1.20;
        IF @PricePerMinute IS NULL SET @PricePerMinute = 0.20;
        IF @MinimumFare IS NULL SET @MinimumFare = 5.00;
        IF @ServiceFeeRate IS NULL SET @ServiceFeeRate = 0.0000;
        IF @SurgeMultiplier IS NULL SET @SurgeMultiplier = 1.00;
        
        DECLARE @DurationMin INT = CASE 
            WHEN @DurationSec IS NOT NULL THEN CEILING(@DurationSec / 60.0)
            ELSE 0
        END;
        
        IF @DistanceKm IS NULL OR @DistanceKm <= 0 SET @DistanceKm = 1.0;
        
        DECLARE @DistanceFare DECIMAL(10,2) = ROUND(@DistanceKm * @PricePerKm, 2);
        DECLARE @TimeFare DECIMAL(10,2) = ROUND(@DurationMin * @PricePerMinute, 2);
        DECLARE @Subtotal DECIMAL(10,2) = @BaseFare + @DistanceFare + @TimeFare;
        DECLARE @SubtotalWithSurge DECIMAL(10,2) = ROUND(@Subtotal * @SurgeMultiplier, 2);
        
        DECLARE @FareBeforeTip DECIMAL(10,2) = CASE 
            WHEN @SubtotalWithSurge < @MinimumFare THEN @MinimumFare
            ELSE @SubtotalWithSurge
        END;
        
        DECLARE @TotalAmount DECIMAL(10,2) = @FareBeforeTip + ISNULL(@TipAmount, 0);
        DECLARE @ServiceFeeAmount DECIMAL(10,2) = ROUND(@FareBeforeTip * @ServiceFeeRate, 2);
        DECLARE @DriverEarnings DECIMAL(10,2) = (@FareBeforeTip - @ServiceFeeAmount) + ISNULL(@TipAmount, 0);
        
        DECLARE @TransactionRef NVARCHAR(255) = 'OSRH-' + 
            FORMAT(GETDATE(), 'yyyyMMddHHmmss') + '-' + 
            CAST(@TripID AS NVARCHAR(20));
        
        IF EXISTS (SELECT 1 FROM dbo.Payment WHERE TripID = @TripID)
        BEGIN
            -- Payment already exists, update it but keep as pending until passenger pays
            UPDATE dbo.Payment
            SET Amount = @TotalAmount,
                PaymentMethodTypeID = @PaymentMethodTypeID,
                Status = 'pending',
                BaseFare = @BaseFare,
                DistanceFare = @DistanceFare,
                TimeFare = @TimeFare,
                SurgeMultiplier = @SurgeMultiplier,
                ServiceFeeRate = @ServiceFeeRate,
                ServiceFeeAmount = @ServiceFeeAmount,
                DriverEarnings = @DriverEarnings,
                TipAmount = @TipAmount,
                DistanceKm = @DistanceKm,
                DurationMinutes = @DurationMin,
                ProviderReference = ISNULL(@ProviderReference, @TransactionRef)
            WHERE TripID = @TripID AND Status != 'completed';
        END
        ELSE
        BEGIN
            INSERT INTO dbo.Payment (
                TripID, PaymentMethodTypeID, Amount, CurrencyCode, Status, 
                CreatedAt, ProviderReference,
                BaseFare, DistanceFare, TimeFare, SurgeMultiplier,
                ServiceFeeRate, ServiceFeeAmount, DriverEarnings, TipAmount,
                DistanceKm, DurationMinutes
            )
            VALUES (
                @TripID, @PaymentMethodTypeID, @TotalAmount, 'EUR', 'pending',
                SYSDATETIME(), ISNULL(@ProviderReference, @TransactionRef),
                @BaseFare, @DistanceFare, @TimeFare, @SurgeMultiplier,
                @ServiceFeeRate, @ServiceFeeAmount, @DriverEarnings, @TipAmount,
                @DistanceKm, @DurationMin
            );
        END
        
        DECLARE @PaymentID INT = SCOPE_IDENTITY();
        IF @PaymentID IS NULL
        BEGIN
            SELECT @PaymentID = PaymentID FROM dbo.Payment WHERE TripID = @TripID;
        END
        
        COMMIT TRANSACTION;
        
        -- Note: Not returning a result set here because this procedure is called
        -- from spDriverUpdateTripStatus which needs to return its own Success message.
        -- The payment was created successfully if we reach this point.
            
    END TRY
    BEGIN CATCH
        ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END
GO

-- =====================================================
-- DRIVER UPDATE TRIP STATUS STORED PROCEDURE
-- =====================================================
-- This procedure allows a driver to update their trip status
-- with validation for allowed status transitions

CREATE OR ALTER PROCEDURE dbo.spDriverUpdateTripStatus
    @DriverID INT,
    @TripID INT,
    @NewStatus NVARCHAR(50),
    @DistanceKm DECIMAL(10,3) = NULL,
    @DurationMinutes DECIMAL(10,2) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @CurrentStatus NVARCHAR(50);
    DECLARE @DriverIDFromTrip INT;
    DECLARE @TripUpdateCount INT = 0;

    -- Get current trip status and verify driver ownership
    SELECT
        @CurrentStatus = Status,
        @DriverIDFromTrip = DriverID
    FROM dbo.Trip
    WHERE TripID = @TripID
      AND DriverID = @DriverID;

    IF @CurrentStatus IS NULL
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Trip not found for this driver.' AS ErrorMessage;
        RETURN;
    END

    -- Validate status transitions
    DECLARE @CurrentLower NVARCHAR(50) = LOWER(@CurrentStatus);
    DECLARE @NewLower NVARCHAR(50) = LOWER(@NewStatus);
    DECLARE @DurationSeconds INT = CASE
                                       WHEN @DurationMinutes IS NULL THEN NULL
                                       ELSE CAST(ROUND(@DurationMinutes * 60.0, 0) AS INT)
                                   END;

    -- Check if transition is allowed
    IF NOT EXISTS (
        SELECT 1
        FROM (VALUES
                (N'assigned',   N'in_progress'),
                (N'assigned',   N'cancelled'),
                (N'dispatched', N'in_progress'),
                (N'dispatched', N'cancelled'),
                (N'in_progress',N'completed'),
                (N'in_progress',N'cancelled')
             ) AS Allowed(FromStatus, ToStatus)
        WHERE FromStatus = @CurrentLower
          AND ToStatus = @NewLower
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Status change is not allowed from the current state.' AS ErrorMessage;
        RETURN;
    END

    -- Update trip based on new status
    IF @NewLower = 'in_progress'
    BEGIN
        -- For segment trips, check if this segment can be started
        DECLARE @StartSegmentID INT;
        DECLARE @StartSegmentOrder INT;
        DECLARE @StartRideRequestID INT;
        
        SELECT TOP 1 
            @StartSegmentID = rs.SegmentID,
            @StartSegmentOrder = rs.SegmentOrder,
            @StartRideRequestID = rs.RideRequestID
        FROM dbo.RideSegment rs
        WHERE rs.TripID = @TripID;
        
        IF @StartSegmentID IS NOT NULL AND @StartSegmentOrder > 1
        BEGIN
            -- This is a segment trip - validate that previous segment is completed
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.RideSegment rs
                INNER JOIN dbo.Trip t ON rs.TripID = t.TripID
                WHERE rs.RideRequestID = @StartRideRequestID
                  AND rs.SegmentOrder = @StartSegmentOrder - 1
                  AND t.Status = 'completed'
            )
            BEGIN
                SELECT CAST(0 AS BIT) AS Success, 
                       N'Cannot start this segment. Previous segment must be completed first.' AS ErrorMessage;
                RETURN;
            END
        END
        
        UPDATE dbo.Trip
        SET Status = 'in_progress',
            StartTime = COALESCE(StartTime, SYSDATETIME())
        WHERE TripID = @TripID AND DriverID = @DriverID;

        SET @TripUpdateCount = @@ROWCOUNT;
    END
    ELSE IF @NewLower = 'cancelled'
    BEGIN
        UPDATE dbo.Trip
        SET Status = 'cancelled',
            EndTime = COALESCE(EndTime, SYSDATETIME())
        WHERE TripID = @TripID AND DriverID = @DriverID;

        SET @TripUpdateCount = @@ROWCOUNT;

        -- Make driver available again
        UPDATE dbo.Driver
        SET IsAvailable = 1
        WHERE DriverID = @DriverIDFromTrip;
    END
    ELSE IF @NewLower = 'completed'
    BEGIN
        -- For segment trips, check if this is a segment and validate completion order
        DECLARE @SegmentID INT;
        DECLARE @SegmentOrder INT;
        DECLARE @RideRequestID INT;
        DECLARE @TotalSegments INT;
        
        SELECT TOP 1 
            @SegmentID = rs.SegmentID,
            @SegmentOrder = rs.SegmentOrder,
            @RideRequestID = rs.RideRequestID
        FROM dbo.RideSegment rs
        WHERE rs.TripID = @TripID;
        
        IF @SegmentID IS NOT NULL
        BEGIN
            -- This is a segment trip - validate completion order
            -- Count total segments for this ride request
            SELECT @TotalSegments = COUNT(*)
            FROM dbo.RideSegment
            WHERE RideRequestID = @RideRequestID;
            
            -- Check if there are any incomplete segments before this one
            IF EXISTS (
                SELECT 1
                FROM dbo.RideSegment rs
                LEFT JOIN dbo.Trip t ON rs.TripID = t.TripID
                WHERE rs.RideRequestID = @RideRequestID
                  AND rs.SegmentOrder < @SegmentOrder
                  AND (t.Status IS NULL OR t.Status != 'completed')
            )
            BEGIN
                SELECT CAST(0 AS BIT) AS Success, 
                       N'Cannot complete this segment. Previous segments must be completed first.' AS ErrorMessage;
                RETURN;
            END
        END
        
        -- Get trip details BEFORE updating for payment calculation
        DECLARE @PaymentMethodTypeID INT;
        DECLARE @FinalDistanceKm DECIMAL(10,3);
        DECLARE @ServiceTypeID INT;
        DECLARE @TripStartTime DATETIME2;
        DECLARE @TripEndTime DATETIME2;
        DECLARE @CurrentDistanceKm DECIMAL(10,3);

        -- Get current trip details
        SELECT 
            @PaymentMethodTypeID = rr.PaymentMethodTypeID,
            @CurrentDistanceKm = t.TotalDistanceKm,
            @ServiceTypeID = rr.ServiceTypeID,
            @TripStartTime = t.StartTime
        FROM dbo.Trip t
        INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        WHERE t.TripID = @TripID;

        -- Calculate distance if not provided or if default value (1.0)
        IF @DistanceKm IS NULL OR @CurrentDistanceKm <= 1.0
        BEGIN
            DECLARE @PickupLat DECIMAL(9,6), @PickupLon DECIMAL(9,6);
            DECLARE @DropoffLat DECIMAL(9,6), @DropoffLon DECIMAL(9,6);
            
            SELECT 
                @PickupLat = pickup.LatDegrees,
                @PickupLon = pickup.LonDegrees,
                @DropoffLat = dropoff.LatDegrees,
                @DropoffLon = dropoff.LonDegrees
            FROM dbo.Trip t
            INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
            INNER JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
            INNER JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
            WHERE t.TripID = @TripID;
            
            -- Calculate straight-line distance using Haversine formula
            -- Then multiply by 1.3 to estimate road distance
            IF @PickupLat IS NOT NULL AND @DropoffLat IS NOT NULL
            BEGIN
                DECLARE @dLat FLOAT = RADIANS(@DropoffLat - @PickupLat);
                DECLARE @dLon FLOAT = RADIANS(@DropoffLon - @PickupLon);
                DECLARE @a FLOAT = 
                    POWER(SIN(@dLat / 2), 2) + 
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(@DropoffLat)) * 
                    POWER(SIN(@dLon / 2), 2);
                DECLARE @c FLOAT = 2 * ATN2(SQRT(@a), SQRT(1 - @a));
                DECLARE @earthRadiusKm FLOAT = 6371;
                
                SET @FinalDistanceKm = @earthRadiusKm * @c * 1.3; -- Multiply by 1.3 for road distance
                SET @FinalDistanceKm = CASE WHEN @FinalDistanceKm < 1.0 THEN 1.0 ELSE @FinalDistanceKm END;
            END
            ELSE
            BEGIN
                SET @FinalDistanceKm = 1.0; -- Fallback minimum distance
            END
        END
        ELSE
        BEGIN
            SET @FinalDistanceKm = COALESCE(@DistanceKm, @CurrentDistanceKm);
        END

        -- Update the trip with calculated distance and duration
        UPDATE dbo.Trip
        SET Status = 'completed',
            EndTime = COALESCE(EndTime, SYSDATETIME()),
            TotalDistanceKm = @FinalDistanceKm,
            TotalDurationSec = COALESCE(@DurationSeconds, TotalDurationSec)
        WHERE TripID = @TripID AND DriverID = @DriverID;

        SET @TripUpdateCount = @@ROWCOUNT;
        SET @TripEndTime = SYSDATETIME();

        -- Make driver available again
        UPDATE dbo.Driver
        SET IsAvailable = 1
        WHERE DriverID = @DriverIDFromTrip;
        
        -- If this is NOT a segment trip, mark the ride request as completed
        -- For segment trips, each segment is independent and ride request stays as 'accepted'
        IF @SegmentID IS NULL
        BEGIN
            -- Regular trip - mark ride request as completed
            UPDATE dbo.RideRequest
            SET Status = 'completed'
            WHERE RideRequestID = (SELECT RideRequestID FROM dbo.Trip WHERE TripID = @TripID);
        END
        ELSE
        BEGIN
            -- Segment trip - check if ALL segments are completed to mark ride request as fully completed
            DECLARE @CompletedSegments INT;
            DECLARE @DebugMessage NVARCHAR(500);
            DECLARE @IsRealDriverTrip BIT;
            DECLARE @NextSegmentID INT;
            
            -- Check if this is a real driver trip
            SELECT @IsRealDriverTrip = ISNULL(t.IsRealDriverTrip, 0)
            FROM dbo.Trip t
            WHERE t.TripID = @TripID;
            
            -- Count how many segments are completed (including the one we just completed above)
            SELECT @CompletedSegments = COUNT(*)
            FROM dbo.RideSegment rs
            INNER JOIN dbo.Trip t ON rs.TripID = t.TripID
            WHERE rs.RideRequestID = @RideRequestID
              AND t.Status = 'completed';
            
            -- Debug: Log the counts
            SET @DebugMessage = 'Segment ' + CAST(@SegmentOrder AS NVARCHAR) + ' completed. Total completed: ' + 
                               CAST(@CompletedSegments AS NVARCHAR) + ' of ' + CAST(@TotalSegments AS NVARCHAR);
            
            INSERT INTO dbo.SystemLog (Severity, Category, Message, TripID)
            VALUES ('info', 'SegmentCompletion', @DebugMessage, @TripID);
            
            -- Only mark ride request as completed if ALL segments are done
            IF @CompletedSegments >= @TotalSegments
            BEGIN
                UPDATE dbo.RideRequest
                SET Status = 'completed'
                WHERE RideRequestID = @RideRequestID;
                
                INSERT INTO dbo.SystemLog (Severity, Category, Message, TripID)
                VALUES ('info', 'RideRequestCompletion', 'All segments completed. Marking ride request as completed.', @TripID);
            END
            ELSE IF @IsRealDriverTrip = 0
            BEGIN
                -- For simulated drivers, auto-assign the next segment
                -- BUT only if the ride request is NOT marked as RealDriversOnly
                DECLARE @RideRealDriversOnly BIT;
                SELECT @RideRealDriversOnly = ISNULL(RealDriversOnly, 0) 
                FROM dbo.RideRequest 
                WHERE RideRequestID = @RideRequestID;
                
                IF @RideRealDriversOnly = 0
                BEGIN
                    SELECT TOP 1 @NextSegmentID = rs.SegmentID
                    FROM dbo.RideSegment rs
                    WHERE rs.RideRequestID = @RideRequestID
                      AND rs.TripID IS NULL  -- Unassigned
                    ORDER BY rs.SegmentOrder;
                    
                    IF @NextSegmentID IS NOT NULL
                    BEGIN
                        EXEC dbo.spAutoAssignSimulatedDriverToSegment @NextSegmentID;
                        
                        INSERT INTO dbo.SystemLog (Severity, Category, Message, TripID)
                        VALUES ('info', 'SegmentAutoAssign', 'Auto-assigning next segment to simulated driver.', @TripID);
                    END
                END
                ELSE
                BEGIN
                    -- Real drivers only ride - don't auto-assign, wait for real driver to accept
                    INSERT INTO dbo.SystemLog (Severity, Category, Message, TripID)
                    VALUES ('info', 'SegmentWaiting', 'RealDriversOnly ride - waiting for real driver to accept next segment.', @TripID);
                END
            END
        END

        -- Create payment using the payment system stored procedure
        -- This ensures consistent fare calculation across the entire application
        -- For segment trips, spProcessTripPayment will skip creating a payment (returns early)
        BEGIN TRY
            EXEC dbo.spProcessTripPayment 
                @TripID = @TripID,
                @PaymentMethodTypeID = @PaymentMethodTypeID,
                @TipAmount = 0.00,
                @ProviderReference = NULL;
        END TRY
        BEGIN CATCH
            -- If payment processing fails, log it but don't fail the trip completion
            INSERT INTO dbo.SystemLog (Severity, Category, Message, TripID)
            VALUES ('error', 'PaymentError', ERROR_MESSAGE(), @TripID);
        END CATCH
        
        -- Successfully completed - return success immediately for 'completed' status
        SELECT CAST(1 AS BIT) AS Success, NULL AS ErrorMessage;
        RETURN;
    END

    IF @TripUpdateCount = 0
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'No changes were applied.' AS ErrorMessage;
        RETURN;
    END

    SELECT CAST(1 AS BIT) AS Success, NULL AS ErrorMessage;
END
GO
-- =====================================================
-- DRIVER GET GEOFENCE STORED PROCEDURE
-- =====================================================
-- Get driver's current geofence settings

CREATE OR ALTER PROCEDURE dbo.spDriverGetGeofence
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        g.GeofenceID,
        g.Name,
        g.RadiusMeters,
        g.IsActive,
        gp.LatDegrees AS CenterLat,
        gp.LonDegrees AS CenterLng,
        CASE WHEN g.Name LIKE '%AutoAccept%' THEN 1 ELSE 0 END AS AutoAccept
    FROM dbo.Geofence g
    INNER JOIN dbo.GeofencePoint gp ON g.GeofenceID = gp.GeofenceID AND gp.SequenceNo = 1
    WHERE g.Name = 'Driver_' + CAST(@DriverID AS NVARCHAR(20)) + '_Pickup'
      AND g.IsActive = 1;
END
GO

-- =====================================================
-- GET RIDE REQUEST DETAILS WITH ESTIMATED COST
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetRideRequestDetails
    @RideRequestID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        rr.RideRequestID,
        rr.EstimatedDistanceKm,
        rr.EstimatedDurationMin,
        rr.ServiceTypeID,
        -- Calculate estimated cost using the fare calculation function
        dbo.fnCalculateTotalFare(
            rr.ServiceTypeID,
            COALESCE(rr.EstimatedDistanceKm, 1.0),
            COALESCE(rr.EstimatedDurationMin, 10)
        ) AS EstimatedCost
    FROM dbo.RideRequest rr
    WHERE rr.RideRequestID = @RideRequestID;
END
GO

-- =====================================================
-- DRIVER SAVE GEOFENCE STORED PROCEDURE
-- =====================================================
-- Save or update driver's geofence settings

CREATE OR ALTER PROCEDURE dbo.spDriverSaveGeofence
    @DriverID INT,
    @CenterLat DECIMAL(9,6),
    @CenterLng DECIMAL(9,6),
    @RadiusMeters INT,
    @AutoAccept BIT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @GeofenceName NVARCHAR(100) = 'Driver_' + CAST(@DriverID AS NVARCHAR(20)) + '_Pickup';
    DECLARE @GeofenceID INT;

    -- First, delete ALL existing geofences and points for this driver
    DELETE gp
    FROM dbo.GeofencePoint gp
    INNER JOIN dbo.Geofence g ON gp.GeofenceID = g.GeofenceID
    WHERE g.Name = @GeofenceName;

    DELETE FROM dbo.Geofence
    WHERE Name = @GeofenceName;

    -- Now create a fresh geofence
    INSERT INTO dbo.Geofence (Name, Description, IsActive, RadiusMeters)
    VALUES (
        @GeofenceName,
        'Driver ' + CAST(@DriverID AS NVARCHAR(20)) + ' pickup radius' + 
        CASE WHEN @AutoAccept = 1 THEN ' (AutoAccept)' ELSE '' END,
        1,
        @RadiusMeters
    );

    SET @GeofenceID = SCOPE_IDENTITY();

    -- Insert center point
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES (@GeofenceID, 1, @CenterLat, @CenterLng);

    SELECT CAST(1 AS BIT) AS Success, @GeofenceID AS GeofenceID;
END
GO

-- =====================================================
-- DRIVER GET AVAILABLE RIDE REQUESTS
-- =====================================================
-- Get pending ride requests within driver's geofence

CREATE OR ALTER PROCEDURE dbo.spDriverGetAvailableRideRequests
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    -- Check if driver has any active trips
    DECLARE @ActiveTripCount INT;
    SELECT @ActiveTripCount = COUNT(*)
    FROM dbo.Trip
    WHERE DriverID = @DriverID AND Status IN ('assigned', 'in_progress');

    -- If driver has active trips, return empty result
    IF @ActiveTripCount > 0
    BEGIN
        SELECT 
            0 AS RideRequestID, '' AS PassengerName, '' AS PickupLocation, 
            '' AS DropoffLocation, NULL AS RequestedAt, 0 AS DistanceKm
        WHERE 1=0;
        RETURN;
    END

    -- Get driver info (UseGPS for RealDriversOnly filtering)
    DECLARE @UseGPS BIT;
    SELECT @UseGPS = UseGPS
    FROM dbo.Driver
    WHERE DriverID = @DriverID;

    -- Get driver's geofence center and radius
    DECLARE @CenterLat DECIMAL(9,6);
    DECLARE @CenterLng DECIMAL(9,6);
    DECLARE @RadiusMeters INT;
    DECLARE @GeofenceID INT;
    DECLARE @HasGeofence BIT = 0;

    SELECT 
        @GeofenceID = g.GeofenceID,
        @CenterLat = gp.LatDegrees,
        @CenterLng = gp.LonDegrees,
        @RadiusMeters = g.RadiusMeters
    FROM dbo.Geofence g
    INNER JOIN dbo.GeofencePoint gp ON g.GeofenceID = gp.GeofenceID AND gp.SequenceNo = 1
    WHERE g.Name = 'Driver_' + CAST(@DriverID AS NVARCHAR(20)) + '_Pickup'
      AND g.IsActive = 1;

    IF @GeofenceID IS NOT NULL
        SET @HasGeofence = 1;

    -- Get driver's vehicle type to check service compatibility
    DECLARE @DriverVehicleTypeID INT;
    SELECT TOP 1 @DriverVehicleTypeID = VehicleTypeID
    FROM dbo.Vehicle
    WHERE DriverID = @DriverID AND IsActive = 1;

    -- Get pending ride requests within geofence radius
    -- Calculate route distance and driver earnings
    SELECT 
        rr.RideRequestID,
        rr.ServiceTypeID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        COALESCE(pickup.StreetAddress, pickup.Description) AS PickupLocation,
        pickup.LatDegrees AS PickupLat,
        pickup.LonDegrees AS PickupLng,
        COALESCE(dropoff.StreetAddress, dropoff.Description) AS DropoffLocation,
        dropoff.LatDegrees AS DropoffLat,
        dropoff.LonDegrees AS DropoffLng,
        rr.RequestedAt,
        rr.PassengerNotes,
        rr.WheelchairNeeded,
        st.Name AS ServiceType,
        pmt.Code AS PaymentMethodCode,
        CASE 
            WHEN pmt.Code = 'CARD' THEN 'Card'
            WHEN pmt.Code = 'CASH' THEN 'Cash'
            ELSE 'Not specified'
        END AS PaymentMethodDisplay,
        CASE 
            WHEN pmt.Code = 'CARD' THEN 'card'
            WHEN pmt.Code = 'CASH' THEN 'cash'
            ELSE 'unknown'
        END AS PaymentMethodClass,
        dist.DistanceKm,
        -- Use passenger's estimated fare if available (includes surge), otherwise calculate base fare
        COALESCE(
            rr.EstimatedFare,
            ROUND(
                CASE 
                    WHEN (
                        COALESCE(pc.BaseFare, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00 
                            WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00 END) +
                        (dist.DistanceKm * COALESCE(pc.PricePerKm, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80 
                            WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20 END)) +
                        ((dist.DistanceKm / 30.0 * 60) * COALESCE(pc.PricePerMinute, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25 
                            WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20 END))
                    ) < COALESCE(pc.MinimumFare, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 5.00 WHEN 2 THEN 15.00 WHEN 3 THEN 10.00 
                        WHEN 4 THEN 20.00 WHEN 5 THEN 8.00 ELSE 5.00 END)
                    THEN COALESCE(pc.MinimumFare, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 5.00 WHEN 2 THEN 15.00 WHEN 3 THEN 10.00 
                        WHEN 4 THEN 20.00 WHEN 5 THEN 8.00 ELSE 5.00 END)
                    ELSE (
                        COALESCE(pc.BaseFare, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00 
                            WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00 END) +
                        (dist.DistanceKm * COALESCE(pc.PricePerKm, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80 
                            WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20 END)) +
                        ((dist.DistanceKm / 30.0 * 60) * COALESCE(pc.PricePerMinute, CASE rr.ServiceTypeID 
                            WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25 
                            WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20 END))
                    )
                END,
                2
            )
        ) AS EstimatedTotalFare,
        -- Calculate estimated driver payment (100% of total fare - no platform fee) using actual distance
        ROUND(
            CASE 
                WHEN (
                    -- Base fare + (distance * rate) + (time * rate)
                    COALESCE(pc.BaseFare, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00 
                        WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00 END) +
                    (dist.DistanceKm * COALESCE(pc.PricePerKm, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80 
                        WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20 END)) +
                    ((dist.DistanceKm / 30.0 * 60) * COALESCE(pc.PricePerMinute, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25 
                        WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20 END))
                ) < COALESCE(pc.MinimumFare, CASE rr.ServiceTypeID 
                    WHEN 1 THEN 5.00 WHEN 2 THEN 15.00 WHEN 3 THEN 10.00 
                    WHEN 4 THEN 20.00 WHEN 5 THEN 8.00 ELSE 5.00 END)
                THEN COALESCE(pc.MinimumFare, CASE rr.ServiceTypeID 
                    WHEN 1 THEN 5.00 WHEN 2 THEN 15.00 WHEN 3 THEN 10.00 
                    WHEN 4 THEN 20.00 WHEN 5 THEN 8.00 ELSE 5.00 END)
                ELSE (
                    COALESCE(pc.BaseFare, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00 
                        WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00 END) +
                    (dist.DistanceKm * COALESCE(pc.PricePerKm, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80 
                        WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20 END)) +
                    ((dist.DistanceKm / 30.0 * 60) * COALESCE(pc.PricePerMinute, CASE rr.ServiceTypeID 
                        WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25 
                        WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20 END))
                )
            END,
            2
        ) AS EstimatedDriverPayment,
        ISNULL(rr.RealDriversOnly, 0) AS RealDriversOnly
    FROM dbo.RideRequest rr
    INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
    INNER JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
    LEFT JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    LEFT JOIN dbo.PaymentMethodType pmt ON rr.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    LEFT JOIN dbo.PricingConfig pc ON rr.ServiceTypeID = pc.ServiceTypeID AND pc.IsActive = 1
    -- Calculate distance once using stored value or Haversine fallback
    CROSS APPLY (
        SELECT COALESCE(
            rr.EstimatedDistanceKm,
            -- Fallback: Haversine formula * 1.3
            (6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(dropoff.LatDegrees - pickup.LatDegrees) / 2), 2) +
                    COS(RADIANS(pickup.LatDegrees)) * COS(RADIANS(dropoff.LatDegrees)) *
                    POWER(SIN(RADIANS(dropoff.LonDegrees - pickup.LonDegrees) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(dropoff.LatDegrees - pickup.LatDegrees) / 2), 2) +
                    COS(RADIANS(pickup.LatDegrees)) * COS(RADIANS(dropoff.LatDegrees)) *
                    POWER(SIN(RADIANS(dropoff.LonDegrees - pickup.LonDegrees) / 2), 2)
                ))
            ) * 1.3)
        ) AS DistanceKm
    ) AS dist
    WHERE rr.Status = 'pending'
      -- Filter by driver type matching ride type:
      -- Real drivers (UseGPS=1): Only see rides where RealDriversOnly=1
      -- Simulated drivers (UseGPS=0): Only see rides where RealDriversOnly=0
      AND (
          (@UseGPS = 1 AND ISNULL(rr.RealDriversOnly, 0) = 1)
          OR (@UseGPS = 0 AND ISNULL(rr.RealDriversOnly, 0) = 0)
      )
      -- Check if pickup location is within geofence radius
      -- For RealDriversOnly rides AND real drivers without geofence, show anyway
      AND (
          @HasGeofence = 0
          OR SQRT(
              POWER((@CenterLat - pickup.LatDegrees) * 111, 2) + 
              POWER((@CenterLng - pickup.LonDegrees) * 111 * COS(RADIANS(@CenterLat)), 2)
          ) <= (@RadiusMeters / 1000.0)
          OR (ISNULL(rr.RealDriversOnly, 0) = 1 AND @UseGPS = 1)  -- Real drivers see RealDriversOnly rides regardless of geofence
      )
      -- CRITICAL: Only show rides compatible with driver's vehicle type
      AND (
          @DriverVehicleTypeID IS NULL
          OR EXISTS (
              SELECT 1 
              FROM dbo.VehicleType_ServiceType vts
              WHERE vts.VehicleTypeID = @DriverVehicleTypeID
                AND vts.ServiceTypeID = rr.ServiceTypeID
          )
      )
      -- Exclude rides that have been segmented (multi-vehicle journeys)
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.RideSegment rs
          WHERE rs.RideRequestID = rr.RideRequestID
      )
      -- CRITICAL: Only show rides where pickup is in driver's vehicle's bound geofence
      AND EXISTS (
          SELECT 1
          FROM dbo.Vehicle v
          INNER JOIN dbo.GeofenceLog gl ON v.VehicleID = gl.VehicleID AND gl.ExitedAt IS NULL
          WHERE v.DriverID = @DriverID 
            AND v.IsActive = 1
            AND dbo.fnIsPointInGeofence(pickup.LatDegrees, pickup.LonDegrees, gl.GeofenceID) = 1
      )
    ORDER BY dist.DistanceKm ASC, rr.RequestedAt ASC;
END
GO

-- =====================================================
-- DRIVER GET AVAILABLE SEGMENT REQUESTS
-- =====================================================
-- Get pending segment requests for real drivers
-- This shows segments where the From location is within the driver's geofence
-- Used for multi-vehicle journeys where different drivers handle different segments

CREATE OR ALTER PROCEDURE dbo.spDriverGetAvailableSegmentRequests
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if driver has any active trips
    DECLARE @ActiveTripCount INT;
    SELECT @ActiveTripCount = COUNT(*)
    FROM dbo.Trip
    WHERE DriverID = @DriverID AND Status IN ('assigned', 'in_progress');

    IF @ActiveTripCount > 0
    BEGIN
        SELECT 0 AS SegmentID WHERE 1=0;
        RETURN;
    END

    -- Get driver info
    DECLARE @UseGPS BIT;
    SELECT @UseGPS = UseGPS FROM dbo.Driver WHERE DriverID = @DriverID;

    -- Get driver's vehicle type
    DECLARE @DriverVehicleTypeID INT;
    SELECT TOP 1 @DriverVehicleTypeID = VehicleTypeID
    FROM dbo.Vehicle
    WHERE DriverID = @DriverID AND IsActive = 1;

    -- Get available segments where:
    -- 1. The segment has no trip assigned yet
    -- 2. The ride request is for real drivers (RealDriversOnly=1)
    -- 3. The segment's From location is within the driver's vehicle's geofence
    -- 4. Previous segment (if any) is completed
    SELECT 
        rs.SegmentID,
        rs.RideRequestID,
        rs.SegmentOrder,
        (SELECT COUNT(*) FROM dbo.RideSegment WHERE RideRequestID = rs.RideRequestID) AS TotalSegments,
        rr.ServiceTypeID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        -- Segment From/To (which may be OSRM crossing points)
        COALESCE(fromLoc.StreetAddress, fromLoc.Description, 'Transfer Point') AS PickupLocation,
        fromLoc.LatDegrees AS PickupLat,
        fromLoc.LonDegrees AS PickupLng,
        COALESCE(toLoc.StreetAddress, toLoc.Description, 'Transfer Point') AS DropoffLocation,
        toLoc.LatDegrees AS DropoffLat,
        toLoc.LonDegrees AS DropoffLng,
        rr.RequestedAt,
        rr.PassengerNotes,
        rr.WheelchairNeeded,
        st.Name AS ServiceType,
        pmt.Code AS PaymentMethodCode,
        CASE 
            WHEN pmt.Code = 'CARD' THEN 'Card'
            WHEN pmt.Code = 'CASH' THEN 'Cash'
            ELSE 'Not specified'
        END AS PaymentMethodDisplay,
        rs.EstimatedDistanceKm AS DistanceKm,
        rs.EstimatedFare AS EstimatedTotalFare,
        ROUND(ISNULL(rs.EstimatedFare, 0), 2) AS EstimatedDriverPayment,
        rs.EstimatedDurationMin,
        g.Name AS GeofenceName,
        -- Original journey endpoints for context
        origPickup.Description AS OriginalPickup,
        origDropoff.Description AS OriginalDropoff,
        1 AS IsSegmentRequest
    FROM dbo.RideSegment rs
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    INNER JOIN dbo.Location origPickup ON rr.PickupLocationID = origPickup.LocationID
    INNER JOIN dbo.Location origDropoff ON rr.DropoffLocationID = origDropoff.LocationID
    LEFT JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    LEFT JOIN dbo.PaymentMethodType pmt ON rr.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    WHERE 
        -- Segment has no trip assigned
        rs.TripID IS NULL
        -- Ride request is for real drivers
        AND ISNULL(rr.RealDriversOnly, 0) = 1
        -- Driver is a real driver
        AND @UseGPS = 1
        -- Previous segment is completed (or this is first segment)
        AND (
            rs.SegmentOrder = 1
            OR EXISTS (
                SELECT 1 
                FROM dbo.RideSegment prevSeg
                INNER JOIN dbo.Trip prevTrip ON prevSeg.TripID = prevTrip.TripID
                WHERE prevSeg.RideRequestID = rs.RideRequestID
                  AND prevSeg.SegmentOrder = rs.SegmentOrder - 1
                  AND prevTrip.Status = 'completed'
            )
        )
        -- Segment's geofence matches driver's vehicle's current geofence
        -- Using the segment's assigned GeofenceID is more reliable than point-in-polygon
        -- since crossing points may be on the boundary
        AND EXISTS (
            SELECT 1
            FROM dbo.Vehicle v
            INNER JOIN dbo.GeofenceLog gl ON v.VehicleID = gl.VehicleID AND gl.ExitedAt IS NULL
            WHERE v.DriverID = @DriverID 
              AND v.IsActive = 1
              AND gl.GeofenceID = rs.GeofenceID  -- Use segment's assigned geofence
        )
        -- Vehicle type is compatible with service type
        AND (
            @DriverVehicleTypeID IS NULL
            OR EXISTS (
                SELECT 1 
                FROM dbo.VehicleType_ServiceType vts
                WHERE vts.VehicleTypeID = @DriverVehicleTypeID
                  AND vts.ServiceTypeID = rr.ServiceTypeID
            )
        )
    ORDER BY rs.SegmentOrder ASC, rr.RequestedAt ASC;
END
GO

-- =====================================================
-- DRIVER ACCEPT SEGMENT REQUEST
-- =====================================================
-- Driver accepts a segment request from a multi-vehicle journey

CREATE OR ALTER PROCEDURE dbo.spDriverAcceptSegmentRequest
    @DriverID INT,
    @SegmentID INT,
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if vehicle has failed safety inspection
    IF EXISTS (
        SELECT 1
        FROM dbo.SafetyInspection si
        WHERE si.VehicleID = @VehicleID
          AND si.Result = 'failed'
          AND si.SafetyInspectionID = (
              SELECT MAX(si2.SafetyInspectionID)
              FROM dbo.SafetyInspection si2
              WHERE si2.VehicleID = @VehicleID
          )
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Vehicle has failed its most recent safety inspection.' AS ErrorMessage;
        RETURN;
    END
    
    -- Get segment info
    DECLARE @RideRequestID INT;
    DECLARE @SegmentOrder INT;
    DECLARE @FromLocationID INT;
    DECLARE @ToLocationID INT;
    DECLARE @EstimatedDistanceKm DECIMAL(8,2);
    DECLARE @EstimatedDurationMin INT;
    
    SELECT 
        @RideRequestID = RideRequestID,
        @SegmentOrder = SegmentOrder,
        @FromLocationID = FromLocationID,
        @ToLocationID = ToLocationID,
        @EstimatedDistanceKm = EstimatedDistanceKm,
        @EstimatedDurationMin = EstimatedDurationMin
    FROM dbo.RideSegment
    WHERE SegmentID = @SegmentID AND TripID IS NULL;
    
    IF @RideRequestID IS NULL
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Segment not found or already assigned.' AS ErrorMessage;
        RETURN;
    END
    
    -- Check previous segment is completed (unless this is first segment)
    IF @SegmentOrder > 1
    BEGIN
        IF NOT EXISTS (
            SELECT 1 
            FROM dbo.RideSegment prevSeg
            INNER JOIN dbo.Trip prevTrip ON prevSeg.TripID = prevTrip.TripID
            WHERE prevSeg.RideRequestID = @RideRequestID
              AND prevSeg.SegmentOrder = @SegmentOrder - 1
              AND prevTrip.Status = 'completed'
        )
        BEGIN
            SELECT CAST(0 AS BIT) AS Success, N'Previous segment has not been completed yet.' AS ErrorMessage;
            RETURN;
        END
    END
    
    -- Create trip for this segment
    DECLARE @TripID INT;
    DECLARE @DriverLat DECIMAL(9,6), @DriverLng DECIMAL(9,6);
    
    -- Get driver's current location from their vehicle's last known position
    SELECT TOP 1 @DriverLat = dl.Latitude, @DriverLng = dl.Longitude
    FROM dbo.DriverLocation dl
    WHERE dl.DriverID = @DriverID
    ORDER BY dl.RecordedAt DESC;
    
    INSERT INTO dbo.Trip (
        RideRequestID, DriverID, VehicleID, DispatchTime, Status, 
        IsRealDriverTrip, DriverStartLat, DriverStartLng
    )
    VALUES (
        @RideRequestID, @DriverID, @VehicleID, SYSDATETIME(), 'assigned',
        1, @DriverLat, @DriverLng
    );
    
    SET @TripID = SCOPE_IDENTITY();
    
    -- Update segment with trip ID
    UPDATE dbo.RideSegment
    SET TripID = @TripID
    WHERE SegmentID = @SegmentID;
    
    -- If this is the first segment, update ride request status
    IF @SegmentOrder = 1
    BEGIN
        UPDATE dbo.RideRequest
        SET Status = 'accepted'
        WHERE RideRequestID = @RideRequestID AND Status = 'pending';
    END
    
    SELECT 
        CAST(1 AS BIT) AS Success,
        @TripID AS TripID,
        @SegmentID AS SegmentID,
        @SegmentOrder AS SegmentOrder,
        N'Segment accepted successfully' AS Message;
END
GO

-- =====================================================
-- DRIVER ACCEPT RIDE REQUEST
-- =====================================================
-- Driver accepts a ride request and creates a trip

CREATE OR ALTER PROCEDURE dbo.spDriverAcceptRideRequest
    @DriverID INT,
    @RideRequestID INT,
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;

    -- Check if vehicle has failed its most recent safety inspection
    IF EXISTS (
        SELECT 1
        FROM dbo.SafetyInspection si
        WHERE si.VehicleID = @VehicleID
          AND si.Result = 'failed'
          AND si.SafetyInspectionID = (
              SELECT MAX(si2.SafetyInspectionID)
              FROM dbo.SafetyInspection si2
              WHERE si2.VehicleID = @VehicleID
          )
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Vehicle has failed its most recent safety inspection and cannot be used for rides.' AS ErrorMessage;
        RETURN;
    END

    -- Check if driver is available
    DECLARE @IsAvailable BIT;
    DECLARE @VerificationStatus NVARCHAR(50);

    SELECT @IsAvailable = IsAvailable, @VerificationStatus = VerificationStatus
    FROM dbo.Driver
    WHERE DriverID = @DriverID;

    IF @IsAvailable = 0
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Driver is not available.' AS ErrorMessage;
        RETURN;
    END

    IF @VerificationStatus <> 'approved'
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Driver is not approved.' AS ErrorMessage;
        RETURN;
    END

    -- Check if ride request is still pending
    DECLARE @RequestStatus NVARCHAR(30);
    DECLARE @RealDriversOnly BIT;
    
    SELECT @RequestStatus = Status, @RealDriversOnly = ISNULL(RealDriversOnly, 0)
    FROM dbo.RideRequest
    WHERE RideRequestID = @RideRequestID;

    IF @RequestStatus IS NULL
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Ride request not found.' AS ErrorMessage;
        RETURN;
    END

    IF @RequestStatus <> 'pending'
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Ride request is no longer available.' AS ErrorMessage;
        RETURN;
    END

    -- Get driver's UseGPS flag
    DECLARE @UseGPS BIT;
    SELECT @UseGPS = ISNULL(UseGPS, 0) FROM dbo.Driver WHERE DriverID = @DriverID;
    
    -- Validate driver type: Simulated drivers CANNOT accept RealDriversOnly rides
    -- But real GPS drivers CAN accept any ride (they just use real GPS for tracking)
    IF @UseGPS = 0 AND @RealDriversOnly = 1
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'This ride requires a real GPS-enabled driver.' AS ErrorMessage;
        RETURN;
    END

    -- CRITICAL: Validate vehicle type can provide requested service type
    DECLARE @ServiceTypeID INT;
    DECLARE @VehicleTypeID INT;
    
    SELECT @ServiceTypeID = ServiceTypeID
    FROM dbo.RideRequest
    WHERE RideRequestID = @RideRequestID;
    
    SELECT @VehicleTypeID = VehicleTypeID
    FROM dbo.Vehicle
    WHERE VehicleID = @VehicleID;
    
    IF NOT EXISTS (
        SELECT 1 
        FROM dbo.VehicleType_ServiceType
        WHERE VehicleTypeID = @VehicleTypeID
          AND ServiceTypeID = @ServiceTypeID
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Your vehicle type cannot provide this service type.' AS ErrorMessage;
        RETURN;
    END

    -- CRITICAL: Validate pickup is within vehicle's bound geofence
    DECLARE @PickupLat DECIMAL(9,6);
    DECLARE @PickupLng DECIMAL(9,6);
    
    SELECT @PickupLat = l.LatDegrees, @PickupLng = l.LonDegrees
    FROM dbo.RideRequest rr
    INNER JOIN dbo.Location l ON rr.PickupLocationID = l.LocationID
    WHERE rr.RideRequestID = @RideRequestID;
    
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.GeofenceLog gl
        WHERE gl.VehicleID = @VehicleID 
          AND gl.ExitedAt IS NULL
          AND dbo.fnIsPointInGeofence(@PickupLat, @PickupLng, gl.GeofenceID) = 1
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'The pickup location is outside your vehicle''s service area.' AS ErrorMessage;
        RETURN;
    END

    -- Update ride request status
    UPDATE dbo.RideRequest
    SET Status = 'accepted'
    WHERE RideRequestID = @RideRequestID;

    -- Determine if this is a real driver trip
    -- IsRealDriverTrip = 1 if: (1) ride request required real drivers, OR (2) driver has UseGPS=1
    DECLARE @IsRealTrip BIT;
    SET @IsRealTrip = CASE WHEN @RealDriversOnly = 1 OR @UseGPS = 1 THEN 1 ELSE 0 END;

    -- Create trip with IsRealDriverTrip flag
    INSERT INTO dbo.Trip (RideRequestID, DriverID, VehicleID, DispatchTime, Status, IsRealDriverTrip)
    VALUES (@RideRequestID, @DriverID, @VehicleID, SYSDATETIME(), 'assigned', @IsRealTrip);

    DECLARE @NewTripID INT = SCOPE_IDENTITY();

    -- Make driver unavailable
    UPDATE dbo.Driver
    SET IsAvailable = 0
    WHERE DriverID = @DriverID;

    -- Log to dispatch
    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    VALUES (@RideRequestID, @DriverID, 'accepted', 
            CASE WHEN @IsRealTrip = 1 
                 THEN 'Manually accepted by real GPS driver' 
                 ELSE 'Manually accepted by driver' 
            END);

    SELECT CAST(1 AS BIT) AS Success, NULL AS ErrorMessage, @NewTripID AS TripID, @IsRealTrip AS IsRealDriverTrip;
END
GO

-- =====================================================
-- DRIVER GET TRIP FOR VALIDATION
-- =====================================================
-- Get trip status to validate state transitions before update

CREATE OR ALTER PROCEDURE dbo.spDriverGetTripForValidation
    @DriverID INT,
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TripID, Status
    FROM dbo.Trip
    WHERE TripID = @TripID AND DriverID = @DriverID;
END
GO

-- =====================================================
-- DRIVER GET AVAILABILITY STATUS
-- =====================================================
-- Get driver's current availability status

CREATE OR ALTER PROCEDURE dbo.spDriverGetAvailability
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT IsAvailable, VerificationStatus
    FROM dbo.Driver
    WHERE DriverID = @DriverID;
END
GO

-- =====================================================
-- DRIVER UPDATE AVAILABILITY
-- =====================================================
-- Update driver's availability status

CREATE OR ALTER PROCEDURE dbo.spDriverUpdateAvailability
    @DriverID INT,
    @IsAvailable BIT
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Driver
    SET IsAvailable = @IsAvailable
    WHERE DriverID = @DriverID;

    SELECT CAST(1 AS BIT) AS Success;
END
GO

-- =====================================================
-- DRIVER GO ONLINE - Set location, vehicle, and bind to geofence
-- =====================================================
-- Driver goes online with GPS location and selected vehicle
-- Vehicle gets bound to the district geofence based on driver's location

CREATE OR ALTER PROCEDURE dbo.spDriverGoOnline
    @DriverID INT,
    @VehicleID INT,
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate driver exists and is approved
    IF NOT EXISTS (
        SELECT 1 FROM dbo.Driver 
        WHERE DriverID = @DriverID AND VerificationStatus = 'approved'
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, 
               N'Driver not found or not approved.' AS ErrorMessage,
               NULL AS GeofenceID,
               NULL AS GeofenceName;
        RETURN;
    END
    
    -- Validate vehicle belongs to this driver and is active
    IF NOT EXISTS (
        SELECT 1 FROM dbo.Vehicle 
        WHERE VehicleID = @VehicleID AND DriverID = @DriverID AND IsActive = 1
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, 
               N'Vehicle not found, not active, or does not belong to you.' AS ErrorMessage,
               NULL AS GeofenceID,
               NULL AS GeofenceName;
        RETURN;
    END
    
    -- Check if vehicle has failed safety inspection
    IF EXISTS (
        SELECT 1
        FROM dbo.SafetyInspection si
        WHERE si.VehicleID = @VehicleID
          AND si.Result = 'failed'
          AND si.SafetyInspectionID = (
              SELECT MAX(si2.SafetyInspectionID)
              FROM dbo.SafetyInspection si2
              WHERE si2.VehicleID = @VehicleID
          )
    )
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, 
               N'This vehicle has failed its most recent safety inspection and cannot be used.' AS ErrorMessage,
               NULL AS GeofenceID,
               NULL AS GeofenceName;
        RETURN;
    END
    
    -- Find which district geofence the driver's location is in
    DECLARE @GeofenceID INT;
    DECLARE @GeofenceName NVARCHAR(100);
    
    SELECT TOP 1 @GeofenceID = g.GeofenceID, @GeofenceName = g.Name
    FROM dbo.Geofence g
    WHERE g.IsActive = 1
      AND g.Name LIKE '%_District'  -- Only district geofences
      AND dbo.fnIsPointInGeofence(@Latitude, @Longitude, g.GeofenceID) = 1;
    
    IF @GeofenceID IS NULL
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, 
               N'Your location is outside our service areas. Please move to a covered district (Paphos, Limassol, Larnaca, or Nicosia).' AS ErrorMessage,
               NULL AS GeofenceID,
               NULL AS GeofenceName;
        RETURN;
    END
    
    -- Update driver's location and set as available
    UPDATE dbo.Driver
    SET CurrentLatitude = @Latitude,
        CurrentLongitude = @Longitude,
        LocationUpdatedAt = SYSDATETIME(),
        IsAvailable = 1,
        UseGPS = 1
    WHERE DriverID = @DriverID;
    
    -- Bind the vehicle to this geofence by creating/updating a GeofenceLog entry
    -- First, close any existing open geofence log entries for this vehicle
    UPDATE dbo.GeofenceLog
    SET ExitedAt = SYSDATETIME(), EventType = 'exit'
    WHERE VehicleID = @VehicleID AND ExitedAt IS NULL;
    
    -- Create new geofence log entry binding vehicle to this district
    INSERT INTO dbo.GeofenceLog (VehicleID, GeofenceID, EnteredAt, ExitedAt, EventType)
    VALUES (@VehicleID, @GeofenceID, SYSDATETIME(), NULL, 'enter');
    
    SELECT CAST(1 AS BIT) AS Success, 
           NULL AS ErrorMessage,
           @GeofenceID AS GeofenceID,
           @GeofenceName AS GeofenceName;
END
GO

-- =====================================================
-- DRIVER GO OFFLINE - Set unavailable
-- =====================================================

CREATE OR ALTER PROCEDURE dbo.spDriverGoOffline
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Set driver as unavailable
    UPDATE dbo.Driver
    SET IsAvailable = 0
    WHERE DriverID = @DriverID;
    
    -- Close all open geofence log entries for driver's vehicles
    UPDATE gl
    SET gl.ExitedAt = SYSDATETIME(), gl.EventType = 'exit'
    FROM dbo.GeofenceLog gl
    INNER JOIN dbo.Vehicle v ON gl.VehicleID = v.VehicleID
    WHERE v.DriverID = @DriverID AND gl.ExitedAt IS NULL;
    
    SELECT CAST(1 AS BIT) AS Success;
END
GO

-- =====================================================
-- DRIVER GET CURRENT STATUS
-- =====================================================
-- Get driver's current online status, location, and active vehicle

CREATE OR ALTER PROCEDURE dbo.spDriverGetCurrentStatus
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        d.DriverID,
        d.IsAvailable,
        d.CurrentLatitude,
        d.CurrentLongitude,
        d.LocationUpdatedAt,
        d.UseGPS,
        v.VehicleID AS ActiveVehicleID,
        v.PlateNo AS ActiveVehiclePlate,
        g.GeofenceID AS BoundGeofenceID,
        g.Name AS BoundGeofenceName
    FROM dbo.Driver d
    LEFT JOIN dbo.Vehicle v ON v.DriverID = d.DriverID AND v.IsActive = 1
    LEFT JOIN dbo.GeofenceLog gl ON gl.VehicleID = v.VehicleID AND gl.ExitedAt IS NULL
    LEFT JOIN dbo.Geofence g ON gl.GeofenceID = g.GeofenceID
    WHERE d.DriverID = @DriverID;
END
GO

-- =====================================================
-- DRIVER GET VEHICLES
-- =====================================================
-- Get all vehicles for a driver with operator-assigned types

CREATE OR ALTER PROCEDURE dbo.spDriverGetVehicles
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        v.VehicleID,
        v.PlateNo,
        COALESCE(vt.Name, 'Pending operator review') AS VehicleTypeName,
        v.Make,
        v.Model,
        v.Year,
        v.Color,
        v.SeatingCapacity,
        v.MaxWeightKg,
        v.IsActive
    FROM dbo.Vehicle v
    LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.DriverID = @DriverID
    ORDER BY v.IsActive DESC, v.VehicleID ASC;
END
GO

-- =====================================================
-- REGISTER DRIVER WITH VEHICLE AND DOCUMENTS
-- =====================================================
-- Register a new driver along with their vehicle and documents
-- Includes all required documents per OSRH requirements:
-- - Personal: ID Card, Driving License, Criminal Record, Medical Certificate
-- - Vehicle: Registration, Insurance, MOT (Technical Inspection)

CREATE OR ALTER PROCEDURE dbo.spRegisterDriverWithVehicle
    -- Personal Info
    @Email              NVARCHAR(255),
    @Phone              NVARCHAR(30) = NULL,
    @FullName           NVARCHAR(200),
    @PasswordHash       VARBINARY(256),
    @PasswordSalt       VARBINARY(128) = NULL,
    @DateOfBirth        DATE = NULL,           -- For age verification (18+)
    @DriverPhotoUrl     NVARCHAR(500) = NULL,  -- Photo of driver for identification
    
    -- Address
    @StreetAddress      NVARCHAR(255) = NULL,
    @City               NVARCHAR(100) = NULL,
    @PostalCode         NVARCHAR(20) = NULL,
    @Country            NVARCHAR(100) = 'Cyprus',
    
    -- User Preferences (GDPR)
    @PrefLocationTracking BIT = 1,
    @PrefNotifications    BIT = 1,
    @PrefEmailUpdates     BIT = 1,
    @PrefDataSharing      BIT = 0,
    
    @DriverType         NVARCHAR(50),   -- 'ride', 'cargo', etc.
    @UseGPS             BIT = 0,        -- 0 = Simulated driver, 1 = Real GPS driver
    
    -- Vehicle Info
    @VehicleTypeID      INT,
    @PlateNo            NVARCHAR(20),
    @Make               NVARCHAR(100) = NULL,
    @Model              NVARCHAR(100) = NULL,
    @Year               SMALLINT = NULL,
    @Color              NVARCHAR(50) = NULL,
    @NumberOfDoors      INT = 4,
    @SeatingCapacity    INT = NULL,
    @HasPassengerSeat   BIT = 1,
    @MaxWeightKg        INT = NULL,
    @CargoVolume        DECIMAL(10,2) = NULL,  -- Cargo volume in cubic meters (for cargo/delivery vehicles)
    
    -- Vehicle Photos
    @VehiclePhotosExterior NVARCHAR(MAX) = NULL,  -- JSON array of exterior photo URLs
    @VehiclePhotosInterior NVARCHAR(MAX) = NULL,  -- JSON array of interior photo URLs
    
    -- DRIVER DOCUMENTS --
    -- ID Card / Passport
    @IDCardNumber       NVARCHAR(100) = NULL,
    @IDCardIssueDate    DATE = NULL,
    @IDCardExpiryDate   DATE = NULL,
    @IDCardStorageUrl   NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Residence Permit
    @ResidencePermitNumber   NVARCHAR(100) = NULL,
    @ResidencePermitIssueDate DATE = NULL,
    @ResidencePermitExpiryDate DATE = NULL,
    @ResidencePermitStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Driver License
    @LicenseIdNumber    NVARCHAR(100) = NULL,
    @LicenseIssueDate   DATE = NULL,
    @LicenseExpiryDate  DATE = NULL,
    @LicenseStorageUrl  NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Criminal Record Certificate
    @CriminalRecordNumber   NVARCHAR(100) = NULL,
    @CriminalRecordIssueDate DATE = NULL,
    @CriminalRecordStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Medical Certificate
    @MedicalCertNumber  NVARCHAR(100) = NULL,
    @MedicalCertIssueDate DATE = NULL,
    @MedicalCertExpiryDate DATE = NULL,
    @MedicalCertStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Psychological Certificate
    @PsychCertNumber    NVARCHAR(100) = NULL,
    @PsychCertIssueDate DATE = NULL,
    @PsychCertExpiryDate DATE = NULL,
    @PsychCertStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- VEHICLE DOCUMENTS --
    -- Insurance
    @InsuranceIdNumber  NVARCHAR(100) = NULL,
    @InsuranceIssueDate DATE = NULL,
    @InsuranceExpiryDate DATE = NULL,
    @InsuranceStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Vehicle Registration
    @RegistrationIdNumber NVARCHAR(100) = NULL,
    @RegistrationIssueDate DATE = NULL,
    @RegistrationExpiryDate DATE = NULL,
    @RegistrationStorageUrl NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- MOT / Technical Inspection
    @MOTIdNumber        NVARCHAR(100) = NULL,
    @MOTIssueDate       DATE = NULL,
    @MOTExpiryDate      DATE = NULL,
    @MOTStorageUrl      NVARCHAR(MAX) = NULL,  -- JSON array for multiple files
    
    -- Vehicle Classification Certificate
    @ClassificationCertNumber NVARCHAR(100) = NULL,
    @ClassificationCertIssueDate DATE = NULL,
    @ClassificationCertExpiryDate DATE = NULL,
    @ClassificationCertStorageUrl NVARCHAR(MAX) = NULL  -- JSON array for multiple files
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRANSACTION;
    
    BEGIN TRY
        -- Check if email already exists
        IF EXISTS (SELECT 1 FROM dbo.[User] WHERE Email = @Email)
        BEGIN
            RAISERROR('Email already registered.', 16, 1);
            RETURN;
        END
        
        -- Age verification: Driver must be at least 18 years old
        IF @DateOfBirth IS NOT NULL AND DATEDIFF(YEAR, @DateOfBirth, GETDATE()) < 18
        BEGIN
            RAISERROR('Driver must be at least 18 years old.', 16, 1);
            RETURN;
        END

        -- Create User account with all new fields
        INSERT INTO dbo.[User] (
            Email, Phone, FullName, DateOfBirth,
            StreetAddress, City, PostalCode, Country,
            PrefLocationTracking, PrefNotifications, PrefEmailUpdates, PrefDataSharing,
            PhotoUrl, Status
        )
        VALUES (
            @Email, @Phone, @FullName, @DateOfBirth,
            @StreetAddress, @City, @PostalCode, @Country,
            @PrefLocationTracking, @PrefNotifications, @PrefEmailUpdates, @PrefDataSharing,
            @DriverPhotoUrl, 'active'
        );

        DECLARE @UserID INT = SCOPE_IDENTITY();

        -- Create Password History
        INSERT INTO dbo.PasswordHistory (UserID, PasswordHash, PasswordSalt, IsCurrent)
        VALUES (@UserID, @PasswordHash, @PasswordSalt, 1);

        -- Create Driver with pending status and GPS mode
        INSERT INTO dbo.Driver (UserID, DriverType, IsAvailable, VerificationStatus, UseGPS)
        VALUES (@UserID, @DriverType, 0, 'pending', @UseGPS);

        DECLARE @DriverID INT = SCOPE_IDENTITY();
        DECLARE @VehicleID INT;

        -- Create Vehicle with new fields including cargo volume and photos
        INSERT INTO dbo.Vehicle (
            DriverID, VehicleTypeID, PlateNo, Make, Model, Year, Color,
            NumberOfDoors, SeatingCapacity, HasPassengerSeat, MaxWeightKg, 
            CargoVolume, PhotosExterior, PhotosInterior, IsActive
        )
        VALUES (
            @DriverID, @VehicleTypeID, @PlateNo, @Make, @Model, @Year, @Color,
            @NumberOfDoors, @SeatingCapacity, @HasPassengerSeat, @MaxWeightKg,
            @CargoVolume, @VehiclePhotosExterior, @VehiclePhotosInterior, 1
        );
        
        SET @VehicleID = SCOPE_IDENTITY();

        -- ========== DRIVER DOCUMENTS ==========
        
        -- Add ID Card Document
        IF @IDCardNumber IS NOT NULL OR @IDCardStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'id_card', @IDCardNumber, @IDCardIssueDate, @IDCardExpiryDate, 'pending', @IDCardStorageUrl);
        END
        
        -- Add Residence Permit Document
        IF @ResidencePermitNumber IS NOT NULL OR @ResidencePermitStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'residence_permit', @ResidencePermitNumber, @ResidencePermitIssueDate, @ResidencePermitExpiryDate, 'pending', @ResidencePermitStorageUrl);
        END

        -- Add Driver License Document
        IF @LicenseIdNumber IS NOT NULL OR @LicenseStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'license', @LicenseIdNumber, @LicenseIssueDate, @LicenseExpiryDate, 'pending', @LicenseStorageUrl);
        END
        
        -- Add Criminal Record Certificate
        IF @CriminalRecordNumber IS NOT NULL OR @CriminalRecordStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'criminal_record', @CriminalRecordNumber, @CriminalRecordIssueDate, NULL, 'pending', @CriminalRecordStorageUrl);
        END
        
        -- Add Medical Certificate
        IF @MedicalCertNumber IS NOT NULL OR @MedicalCertStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'medical_cert', @MedicalCertNumber, @MedicalCertIssueDate, @MedicalCertExpiryDate, 'pending', @MedicalCertStorageUrl);
        END
        
        -- Add Psychological Certificate
        IF @PsychCertNumber IS NOT NULL OR @PsychCertStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.DriverDocument (DriverID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@DriverID, 'psych_cert', @PsychCertNumber, @PsychCertIssueDate, @PsychCertExpiryDate, 'pending', @PsychCertStorageUrl);
        END

        -- ========== VEHICLE DOCUMENTS ==========
        
        -- Add Insurance Document
        IF @InsuranceIdNumber IS NOT NULL OR @InsuranceStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@VehicleID, 'insurance', @InsuranceIdNumber, @InsuranceIssueDate, @InsuranceExpiryDate, 'pending', @InsuranceStorageUrl);
        END

        -- Add Vehicle Registration Document
        IF @RegistrationIdNumber IS NOT NULL OR @RegistrationStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@VehicleID, 'registration', @RegistrationIdNumber, @RegistrationIssueDate, @RegistrationExpiryDate, 'pending', @RegistrationStorageUrl);
        END
        
        -- Add MOT/Technical Inspection Document
        IF @MOTIdNumber IS NOT NULL OR @MOTStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@VehicleID, 'mot', @MOTIdNumber, @MOTIssueDate, @MOTExpiryDate, 'pending', @MOTStorageUrl);
        END
        
        -- Add Vehicle Classification Certificate
        IF @ClassificationCertNumber IS NOT NULL OR @ClassificationCertStorageUrl IS NOT NULL
        BEGIN
            INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
            VALUES (@VehicleID, 'classification_cert', @ClassificationCertNumber, @ClassificationCertIssueDate, @ClassificationCertExpiryDate, 'pending', @ClassificationCertStorageUrl);
        END

        COMMIT TRANSACTION;
        
        -- Return IDs for reference
        SELECT @UserID AS UserID, @DriverID AS DriverID, @VehicleID AS VehicleID;
        
    END TRY
    BEGIN CATCH
        ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END
GO

-- =====================================================
-- GET SERVICE TYPE REQUIREMENTS
-- =====================================================
-- Returns the requirements for each service type
-- Used during driver registration to inform drivers of requirements

CREATE OR ALTER PROCEDURE dbo.spGetServiceTypeRequirements
    @ServiceTypeID INT = NULL  -- NULL returns all active requirements
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        str.RequirementID,
        str.ServiceTypeID,
        st.Code AS ServiceTypeCode,
        st.Name AS ServiceTypeName,
        st.Description AS ServiceTypeDescription,
        str.MinVehicleYear,
        str.MaxVehicleAge,
        str.MinDoors,
        str.RequirePassengerSeat,
        str.MinSeatingCapacity,
        str.MaxWeightCapacityKg,
        str.MinDriverAge,
        str.RequireCriminalRecord,
        str.RequireMedicalCert,
        str.RequireInsurance,
        str.RequireMOT,
        str.RequirementsDescription
    FROM dbo.ServiceTypeRequirements str
    INNER JOIN dbo.ServiceType st ON str.ServiceTypeID = st.ServiceTypeID
    WHERE str.IsActive = 1
      AND st.IsActive = 1
      AND (@ServiceTypeID IS NULL OR str.ServiceTypeID = @ServiceTypeID)
    ORDER BY st.Name;
END
GO

-- Get driver details with user info
CREATE OR ALTER PROCEDURE dbo.spGetDriverDetails
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        d.DriverID,
        d.DriverType,
        d.IsAvailable,
        d.VerificationStatus,
        d.RatingAverage,
        d.CreatedAt,
        u.UserID,
        u.FullName,
        u.Email,
        u.Phone,
        u.PhotoUrl,
        u.Status AS UserStatus
    FROM Driver d
    JOIN [User] u ON d.UserID = u.UserID
    WHERE d.DriverID = @DriverID;
END
GO

-- Get driver documents
CREATE OR ALTER PROCEDURE dbo.spGetDriverDocuments
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        DriverDocumentID,
        DocType,
        IdNumber,
        IssueDate,
        ExpiryDate,
        StorageUrl,
        Status
    FROM DriverDocument
    WHERE DriverID = @DriverID
    ORDER BY 
        CASE DocType 
            WHEN 'license' THEN 1 
            WHEN 'insurance' THEN 2 
            WHEN 'registration' THEN 3 
            ELSE 4 
        END,
        DriverDocumentID ASC;
END
GO

-- Get vehicle documents for a driver
CREATE OR ALTER PROCEDURE dbo.spGetDriverVehicleDocuments
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        vd.VehicleDocumentID,
        vd.VehicleID,
        vd.DocType,
        vd.IdNumber,
        vd.IssueDate,
        vd.ExpiryDate,
        vd.StorageUrl,
        vd.Status,
        v.PlateNo,
        vt.Name AS VehicleTypeName
    FROM VehicleDocument vd
    JOIN Vehicle v ON vd.VehicleID = v.VehicleID
    LEFT JOIN VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.DriverID = @DriverID
    ORDER BY v.PlateNo, 
        CASE vd.DocType 
            WHEN 'license' THEN 1 
            WHEN 'insurance' THEN 2 
            WHEN 'registration' THEN 3 
            ELSE 4 
        END,
        vd.VehicleDocumentID ASC;
END
GO

-- Get driver vehicles
CREATE OR ALTER PROCEDURE dbo.spGetDriverVehiclesDetails
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        v.VehicleID,
        v.PlateNo,
        v.Make,
        v.Model,
        v.Year,
        v.Color,
        v.SeatingCapacity,
        v.MaxWeightKg,
        v.CargoVolume,
        v.PhotosExterior,
        v.PhotosInterior,
        v.IsActive,
        vt.Name AS VehicleTypeName
    FROM Vehicle v
    LEFT JOIN VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.DriverID = @DriverID
    ORDER BY v.PlateNo;
END
GO

-- Get driver trip statistics
CREATE OR ALTER PROCEDURE dbo.spGetDriverTripStats
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        SUM(CASE WHEN Status = 'completed' THEN 1 ELSE 0 END) AS CompletedTrips,
        SUM(CASE WHEN Status = 'cancelled' THEN 1 ELSE 0 END) AS CancelledTrips,
        COUNT(*) AS TotalTrips
    FROM Trip
    WHERE DriverID = @DriverID;
END
GO

-- =============================================
-- Get driver's safety inspections
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverSafetyInspections
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        si.SafetyInspectionID,
        si.VehicleID,
        si.InspectionDate,
        si.Result AS Status,
        si.Notes,
        v.PlateNo,
        vt.Name AS VehicleTypeName
    FROM dbo.SafetyInspection si
    JOIN dbo.Vehicle v      ON si.VehicleID    = v.VehicleID
    JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.DriverID = @DriverID
    ORDER BY si.InspectionDate DESC, si.SafetyInspectionID DESC;
END
GO

-- =============================================
-- Update driver verification status (approve/reject)
-- =============================================
CREATE OR ALTER PROCEDURE spUpdateDriverVerificationStatus
    @DriverID INT,
    @VerificationStatus NVARCHAR(50)  -- 'approved', 'rejected', 'pending'
AS
BEGIN
    SET NOCOUNT ON;
    
    IF NOT EXISTS (SELECT 1 FROM Driver WHERE DriverID = @DriverID)
    BEGIN
        RAISERROR('Driver not found.', 16, 1);
        RETURN;
    END
    
    IF @VerificationStatus NOT IN ('approved', 'rejected', 'pending')
    BEGIN
        RAISERROR('Invalid verification status. Must be: approved, rejected, or pending.',  16, 1);
        RETURN;
    END
    
    UPDATE Driver
    SET VerificationStatus = @VerificationStatus
    WHERE DriverID = @DriverID;
    
    SELECT @@ROWCOUNT AS RowsAffected;
END
GO

-- =============================================
-- Operator Dashboard: Get all active drivers with locations
-- =============================================
CREATE OR ALTER PROCEDURE spOperatorGetActiveDriversMap
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        d.DriverID,
        u.FullName AS DriverName,
        u.Phone AS DriverPhone,
        d.IsAvailable,
        v.VehicleID,
        v.PlateNo,
        v.Make,
        v.Model,
        vt.Name AS VehicleTypeName,
        -- Get last known location from geofence center if available
        gp.LatDegrees AS LastLat,
        gp.LonDegrees AS LastLng,
        g.RadiusMeters AS GeofenceRadius,
        -- Check if driver has active trip
        CASE WHEN EXISTS (
            SELECT 1 FROM Trip t 
            WHERE t.DriverID = d.DriverID 
            AND t.Status IN ('assigned', 'in_progress')
        ) THEN 1 ELSE 0 END AS HasActiveTrip
    FROM Driver d
    JOIN [User] u ON d.UserID = u.UserID
    LEFT JOIN Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
    LEFT JOIN VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN Geofence g ON g.Name = 'Driver_' + CAST(d.DriverID AS NVARCHAR(20)) + '_Pickup' AND g.IsActive = 1
    LEFT JOIN GeofencePoint gp ON g.GeofenceID = gp.GeofenceID AND gp.SequenceNo = 1
    WHERE d.VerificationStatus = 'approved'
    ORDER BY d.IsAvailable DESC, u.FullName;
END
GO

-- =============================================
-- Operator Dashboard: Get all pending ride requests with locations
-- =============================================
CREATE OR ALTER PROCEDURE spOperatorGetPendingRideRequestsMap
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        rr.RideRequestID,
        rr.RequestedAt,
        rr.Status,
        rr.PassengerNotes,
        rr.WheelchairNeeded,
        rr.LuggageVolume,
        p.PassengerID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        -- Pickup location
        pickup.LocationID AS PickupLocationID,
        COALESCE(pickup.StreetAddress, pickup.Description) AS PickupAddress,
        pickup.LatDegrees AS PickupLat,
        pickup.LonDegrees AS PickupLng,
        -- Dropoff location
        dropoff.LocationID AS DropoffLocationID,
        COALESCE(dropoff.StreetAddress, dropoff.Description) AS DropoffAddress,
        dropoff.LatDegrees AS DropoffLat,
        dropoff.LonDegrees AS DropoffLng
    FROM RideRequest rr
    JOIN Passenger p ON rr.PassengerID = p.PassengerID
    JOIN [User] u ON p.UserID = u.UserID
    JOIN Location pickup ON rr.PickupLocationID = pickup.LocationID
    JOIN Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
    WHERE rr.Status = 'pending'
    ORDER BY rr.RequestedAt ASC;
END
GO

-- =============================================
-- Operator Dashboard: Get statistics
-- =============================================
CREATE OR ALTER PROCEDURE spOperatorGetDashboardStats
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        (SELECT COUNT(*) FROM RideRequest WHERE Status = 'pending') AS OpenRequestsCount,
        (SELECT COUNT(*) FROM Trip WHERE Status IN ('assigned', 'in_progress')) AS ActiveTripsCount,
        (SELECT COUNT(*) FROM Driver WHERE IsAvailable = 1 AND VerificationStatus = 'approved') AS AvailableDriversCount,
        (SELECT COUNT(*) FROM GDPRRequest WHERE Status = 'pending') AS PendingGdprCount;
END
GO

-- =============================================
-- System Logs: Get all operator actions (dispatch, inspections, approvals)
-- =============================================
CREATE OR ALTER PROCEDURE spGetSystemLogs
    @MaxRows INT = 200
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Combine all operator actions into one result set
    SELECT TOP (@MaxRows) *
    FROM (
        -- Dispatch logs
        SELECT
            dl.CreatedAt,
            'Dispatch' AS ActionType,
            uO.FullName AS OperatorName,
            'Ride Request #' + CAST(dl.RideRequestID AS NVARCHAR(20)) + ' assigned to ' + COALESCE(uD.FullName, 'Driver #' + CAST(dl.DriverID AS NVARCHAR(20))) AS ActionDescription,
            dl.Status AS Status,
            dl.RideRequestID AS ReferenceID,
            NULL AS VehicleInfo,
            NULL AS InspectionResult
        FROM DispatchLog dl
        LEFT JOIN Driver d    ON dl.DriverID   = d.DriverID
        LEFT JOIN [User] uD   ON d.UserID      = uD.UserID
        LEFT JOIN Operator o  ON dl.OperatorID = o.OperatorID
        LEFT JOIN [User] uO   ON o.UserID      = uO.UserID
        WHERE dl.OperatorID IS NOT NULL
        
        UNION ALL
        
        -- Safety inspections (no direct operator link, showing all inspections)
        SELECT
            si.InspectionDate AS CreatedAt,
            'Safety Inspection' AS ActionType,
            'System' AS OperatorName,
            'Vehicle ' + v.PlateNo + ' (' + vt.Name + ') inspected' AS ActionDescription,
            si.Result AS Status,
            si.SafetyInspectionID AS ReferenceID,
            v.PlateNo + ' - ' + vt.Name AS VehicleInfo,
            si.Result AS InspectionResult
        FROM SafetyInspection si
        JOIN Vehicle v ON si.VehicleID = v.VehicleID
        JOIN VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
        
        UNION ALL
        
        -- Driver verification status changes (inferred from driver verification status)
        -- Note: This shows current status, not historical changes unless you have audit table
        SELECT
            d.CreatedAt,
            'Driver Verification' AS ActionType,
            'System' AS OperatorName,
            'Driver ' + u.FullName + ' status: ' + d.VerificationStatus AS ActionDescription,
            d.VerificationStatus AS Status,
            d.DriverID AS ReferenceID,
            NULL AS VehicleInfo,
            NULL AS InspectionResult
        FROM Driver d
        JOIN [User] u ON d.UserID = u.UserID
        WHERE d.VerificationStatus IN ('approved', 'rejected')
    ) AS AllLogs
    ORDER BY CreatedAt DESC;
END
GO

-- =============================================
-- Get Dispatch Logs
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDispatchLogs
    @MaxRows INT = 200
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@MaxRows)
        dl.DispatchLogID,
        dl.RideRequestID,
        dl.DriverID,
        dl.OperatorID,
        dl.CreatedAt,
        dl.Status,
        dl.ChangeReason,
        uD.FullName AS DriverName,
        uO.FullName AS OperatorName,
        rr.Status AS RideRequestStatus
    FROM dbo.DispatchLog dl
    LEFT JOIN dbo.Driver d ON dl.DriverID = d.DriverID
    LEFT JOIN dbo.[User] uD ON d.UserID = uD.UserID
    LEFT JOIN dbo.Operator o ON dl.OperatorID = o.OperatorID
    LEFT JOIN dbo.[User] uO ON o.UserID = uO.UserID
    LEFT JOIN dbo.RideRequest rr ON dl.RideRequestID = rr.RideRequestID
    ORDER BY dl.CreatedAt DESC;
END
GO

-- 22/11/2025

-- New SPs for ride_detail.php
-- SEGMENT AWARE: Returns segment From/To locations for segment trips
CREATE OR ALTER PROCEDURE dbo.spGetPassengerTripDetails
    @TripID INT,
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Check if this is a segment trip
    DECLARE @SegmentID INT;
    DECLARE @IsSegmentTrip BIT = 0;
    
    SELECT @SegmentID = rs.SegmentID
    FROM dbo.RideSegment rs
    WHERE rs.TripID = @TripID;
    
    IF @SegmentID IS NOT NULL
        SET @IsSegmentTrip = 1;
    
    IF @IsSegmentTrip = 1
    BEGIN
        -- SEGMENT TRIP: Use segment's From/To locations
        SELECT
            t.TripID,
            t.RideRequestID,
            t.DriverID,
            t.VehicleID,
            t.DispatchTime,
            t.StartTime,
            t.EndTime,
            t.TotalDistanceKm,
            t.TotalDurationSec,
            t.Status AS TripStatus,
            ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip,
            rr.RequestedAt,
            rr.PassengerNotes,
            rr.LuggageVolume,
            rr.WheelchairNeeded,
            rs.EstimatedFare,               -- Segment's estimated fare
            rs.EstimatedDistanceKm,         -- Segment's estimated distance
            rs.EstimatedDurationMin,        -- Segment's estimated duration
            -- SEGMENT pickup = FromLocationID
            fromLoc.LocationID AS PickupLocationID,
            fromLoc.Description AS PickupDescription,
            fromLoc.StreetAddress AS PickupStreet,
            fromLoc.PostalCode AS PickupPostal,
            fromLoc.LatDegrees AS PickupLat,
            fromLoc.LonDegrees AS PickupLon,
            -- SEGMENT dropoff = ToLocationID (the bridge!)
            toLoc.LocationID AS DropoffLocationID,
            toLoc.Description AS DropoffDescription,
            toLoc.StreetAddress AS DropoffStreet,
            toLoc.PostalCode AS DropoffPostal,
            toLoc.LatDegrees AS DropoffLat,
            toLoc.LonDegrees AS DropoffLon,
            d.DriverID,
            du.UserID AS DriverUserID,
            du.FullName AS DriverName,
            v.VehicleID,
            v.PlateNo AS PlateNumber,
            vt.Name AS VehicleType,
            v.SeatingCapacity AS Capacity,
            -- Segment info
            @IsSegmentTrip AS IsSegmentTrip,
            rs.SegmentID,
            rs.SegmentOrder,
            (SELECT COUNT(*) FROM dbo.RideSegment WHERE RideRequestID = rr.RideRequestID) AS TotalSegments,
            g.Name AS SegmentGeofenceName
        FROM dbo.Trip t
        INNER JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
        INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
        INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
        INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
        LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
        LEFT JOIN dbo.Driver d ON t.DriverID = d.DriverID
        LEFT JOIN dbo.[User] du ON d.UserID = du.UserID
        LEFT JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
        LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
        WHERE t.TripID = @TripID AND p.PassengerID = @PassengerID;
    END
    ELSE
    BEGIN
        -- REGULAR TRIP: Use RideRequest's Pickup/Dropoff locations
        SELECT
            t.TripID,
            t.RideRequestID,
            t.DriverID,
            t.VehicleID,
            t.DispatchTime,
            t.StartTime,
            t.EndTime,
            t.TotalDistanceKm,
            t.TotalDurationSec,
            t.Status AS TripStatus,
            ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip,
            rr.RequestedAt,
            rr.PassengerNotes,
            rr.LuggageVolume,
            rr.WheelchairNeeded,
            rr.EstimatedFare,
            rr.EstimatedDistanceKm,
            rr.EstimatedDurationMin,
            pl.LocationID AS PickupLocationID,
            pl.Description AS PickupDescription,
            pl.StreetAddress AS PickupStreet,
            pl.PostalCode AS PickupPostal,
            pl.LatDegrees AS PickupLat,
            pl.LonDegrees AS PickupLon,
            dl.LocationID AS DropoffLocationID,
            dl.Description AS DropoffDescription,
            dl.StreetAddress AS DropoffStreet,
            dl.PostalCode AS DropoffPostal,
            dl.LatDegrees AS DropoffLat,
            dl.LonDegrees AS DropoffLon,
            d.DriverID,
            du.UserID AS DriverUserID,
            du.FullName AS DriverName,
            v.VehicleID,
            v.PlateNo AS PlateNumber,
            vt.Name AS VehicleType,
            v.SeatingCapacity AS Capacity,
            -- Not a segment trip
            CAST(0 AS BIT) AS IsSegmentTrip,
            NULL AS SegmentID,
            NULL AS SegmentOrder,
            NULL AS TotalSegments,
            NULL AS SegmentGeofenceName
        FROM dbo.Trip t
        JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        JOIN dbo.Passenger p    ON rr.PassengerID = p.PassengerID
        JOIN dbo.Location pl    ON rr.PickupLocationID = pl.LocationID
        JOIN dbo.Location dl    ON rr.DropoffLocationID = dl.LocationID
        LEFT JOIN dbo.Driver d       ON t.DriverID = d.DriverID
        LEFT JOIN dbo.[User] du      ON d.UserID = du.UserID
        LEFT JOIN dbo.Vehicle v      ON t.VehicleID = v.VehicleID
        LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
        WHERE t.TripID = @TripID AND p.PassengerID = @PassengerID;
    END
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetTripPayment
    @TripID INT
AS
BEGIN
    SELECT TOP 1 
        PaymentID, 
        Amount, 
        CurrencyCode, 
        Status, 
        CreatedAt, 
        CompletedAt, 
        PaymentMethodTypeID, 
        ProviderReference
    FROM dbo.Payment
    WHERE TripID = @TripID
    ORDER BY CreatedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetTripRating
    @TripID INT,
    @FromUserID INT
AS
BEGIN
    SELECT TOP 1 
        Stars, 
        Comment, 
        CreatedAt
    FROM dbo.Rating
    WHERE TripID = @TripID AND FromUserID = @FromUserID
    ORDER BY CreatedAt DESC;
END
GO

-- =============================================
-- Get passenger's active trip for navbar indicator
-- Returns the most recent active trip (assigned, dispatched, or in_progress)
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerActiveTrip
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get the most recent active trip for this passenger
    SELECT TOP 1
        t.TripID,
        t.Status,
        t.DispatchTime,
        t.StartTime,
        ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip,
        u.FullName AS DriverName,
        v.PlateNo AS VehiclePlate,
        pickup.Description AS PickupLocation,
        dropoff.Description AS DropoffLocation
    FROM dbo.Trip t
    INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Driver d ON t.DriverID = d.DriverID
    INNER JOIN dbo.[User] u ON d.UserID = u.UserID
    LEFT JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
    LEFT JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
    LEFT JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
    WHERE rr.PassengerID = @PassengerID
      AND t.Status IN ('assigned', 'dispatched', 'in_progress')
    ORDER BY t.DispatchTime DESC;
END
GO

-- =============================================
-- Conclude a real driver trip (simplified flow without tracking)
-- Calculates fare based on estimated distance and creates payment record
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spDriverConcludeRealTrip
    @DriverID INT,
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate trip belongs to driver and is a real driver trip
    DECLARE @CurrentStatus NVARCHAR(50);
    DECLARE @IsRealDriverTrip BIT;
    DECLARE @RideRequestID INT;
    
    SELECT 
        @CurrentStatus = t.Status,
        @IsRealDriverTrip = ISNULL(t.IsRealDriverTrip, 0),
        @RideRequestID = t.RideRequestID
    FROM dbo.Trip t
    WHERE t.TripID = @TripID AND t.DriverID = @DriverID;
    
    IF @CurrentStatus IS NULL
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Trip not found for this driver.' AS ErrorMessage;
        RETURN;
    END
    
    IF @IsRealDriverTrip = 0
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'This procedure is only for real driver trips.' AS ErrorMessage;
        RETURN;
    END
    
    IF @CurrentStatus NOT IN ('assigned', 'in_progress')
    BEGIN
        SELECT CAST(0 AS BIT) AS Success, N'Trip cannot be concluded from current status: ' + @CurrentStatus AS ErrorMessage;
        RETURN;
    END
    
    -- Get ride request details for fare calculation
    DECLARE @ServiceTypeID INT;
    DECLARE @PaymentMethodTypeID INT;
    DECLARE @EstimatedDistanceKm DECIMAL(10,3);
    DECLARE @EstimatedFare DECIMAL(10,2);
    
    SELECT 
        @ServiceTypeID = rr.ServiceTypeID,
        @PaymentMethodTypeID = rr.PaymentMethodTypeID,
        @EstimatedDistanceKm = rr.EstimatedDistanceKm,
        @EstimatedFare = rr.EstimatedFare
    FROM dbo.RideRequest rr
    WHERE rr.RideRequestID = @RideRequestID;
    
    -- Calculate fare if not already estimated
    DECLARE @FinalDistanceKm DECIMAL(10,3);
    DECLARE @FinalFare DECIMAL(10,2);
    DECLARE @BaseFare DECIMAL(10,2);
    DECLARE @PerKmRate DECIMAL(10,2);
    
    -- Get pricing info from PricingConfig table (falls back to defaults if not found)
    SELECT 
        @BaseFare = ISNULL(pc.BaseFare, 3.00),
        @PerKmRate = ISNULL(pc.PricePerKm, 1.20)
    FROM dbo.PricingConfig pc
    WHERE pc.ServiceTypeID = @ServiceTypeID AND pc.IsActive = 1;
    
    -- If no pricing config found, use defaults
    IF @BaseFare IS NULL SET @BaseFare = 3.00;
    IF @PerKmRate IS NULL SET @PerKmRate = 1.20;
    
    -- Use estimated distance or calculate from locations
    IF @EstimatedDistanceKm IS NOT NULL AND @EstimatedDistanceKm > 0
    BEGIN
        SET @FinalDistanceKm = @EstimatedDistanceKm;
    END
    ELSE
    BEGIN
        -- Calculate distance from pickup/dropoff locations
        DECLARE @PickupLat DECIMAL(9,6), @PickupLon DECIMAL(9,6);
        DECLARE @DropoffLat DECIMAL(9,6), @DropoffLon DECIMAL(9,6);
        
        SELECT 
            @PickupLat = pickup.LatDegrees,
            @PickupLon = pickup.LonDegrees,
            @DropoffLat = dropoff.LatDegrees,
            @DropoffLon = dropoff.LonDegrees
        FROM dbo.RideRequest rr
        INNER JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
        INNER JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
        WHERE rr.RideRequestID = @RideRequestID;
        
        IF @PickupLat IS NOT NULL AND @DropoffLat IS NOT NULL
        BEGIN
            DECLARE @dLat FLOAT = RADIANS(@DropoffLat - @PickupLat);
            DECLARE @dLon FLOAT = RADIANS(@DropoffLon - @PickupLon);
            DECLARE @a FLOAT = 
                POWER(SIN(@dLat / 2), 2) + 
                COS(RADIANS(@PickupLat)) * COS(RADIANS(@DropoffLat)) * 
                POWER(SIN(@dLon / 2), 2);
            DECLARE @c FLOAT = 2 * ATN2(SQRT(@a), SQRT(1 - @a));
            DECLARE @earthRadiusKm FLOAT = 6371;
            
            SET @FinalDistanceKm = @earthRadiusKm * @c * 1.3;
            SET @FinalDistanceKm = CASE WHEN @FinalDistanceKm < 1.0 THEN 1.0 ELSE @FinalDistanceKm END;
        END
        ELSE
        BEGIN
            SET @FinalDistanceKm = 5.0; -- Default fallback
        END
    END
    
    -- Use estimated fare or calculate
    IF @EstimatedFare IS NOT NULL AND @EstimatedFare > 0
    BEGIN
        SET @FinalFare = @EstimatedFare;
    END
    ELSE
    BEGIN
        SET @FinalFare = @BaseFare + (@FinalDistanceKm * @PerKmRate);
    END
    
    -- Calculate driver earnings (100%) and platform fee (0%)
    DECLARE @DriverEarnings DECIMAL(10,2) = @FinalFare;
    DECLARE @PlatformFee DECIMAL(10,2) = 0.00;
    
    -- Update trip to completed
    UPDATE dbo.Trip
    SET 
        Status = 'completed',
        StartTime = COALESCE(StartTime, DATEADD(MINUTE, -15, SYSDATETIME())),
        EndTime = SYSDATETIME(),
        TotalDistanceKm = @FinalDistanceKm,
        ActualCost = @FinalFare,
        DriverPayout = @DriverEarnings,
        PlatformFee = @PlatformFee
    WHERE TripID = @TripID AND DriverID = @DriverID;
    
    -- Update ride request status
    UPDATE dbo.RideRequest
    SET Status = 'completed'
    WHERE RideRequestID = @RideRequestID;
    
    -- Create payment record with pending status - passenger will complete it
    DECLARE @PaymentMethodCode NVARCHAR(10);
    SELECT @PaymentMethodCode = Code FROM dbo.PaymentMethodType WHERE PaymentMethodTypeID = @PaymentMethodTypeID;
    
    -- Insert into Payment table with PENDING status
    -- Payment will be completed by passenger via spCompletePayment
    INSERT INTO dbo.Payment (
        TripID,
        Amount,
        CurrencyCode,
        PaymentMethodTypeID,
        Status,
        CreatedAt,
        BaseFare,
        DistanceFare,
        ServiceFeeRate,
        ServiceFeeAmount,
        DriverEarnings
    )
    VALUES (
        @TripID,
        @FinalFare,
        'EUR',
        @PaymentMethodTypeID,
        'pending',  -- Always pending - passenger completes payment
        SYSDATETIME(),
        @BaseFare,
        @FinalDistanceKm * @PerKmRate,
        0.00,
        @PlatformFee,
        @DriverEarnings
    );
    
    -- Make driver available again
    UPDATE dbo.Driver
    SET IsAvailable = 1
    WHERE DriverID = @DriverID;
    
    -- Log to dispatch
    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    VALUES (@RideRequestID, @DriverID, 'completed', 
            'Real driver trip concluded - Fare: €' + CAST(@FinalFare AS VARCHAR) + ', Distance: ' + CAST(@FinalDistanceKm AS VARCHAR) + ' km');
    
    SELECT 
        CAST(1 AS BIT) AS Success, 
        NULL AS ErrorMessage,
        @TripID AS TripID,
        @FinalDistanceKm AS DistanceKm,
        @FinalFare AS TotalFare,
        @DriverEarnings AS DriverEarnings,
        @PlatformFee AS PlatformFee,
        @PaymentMethodCode AS PaymentMethod;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetPaymentMethods
AS
BEGIN
    SELECT 
        PaymentMethodTypeID, 
        Code, 
        Description
    FROM dbo.PaymentMethodType
    ORDER BY Code;
END
GO

-- =============================================
-- Get all vehicle types
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetVehicleTypes
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        VehicleTypeID, 
        Name,
        Description,
        MaxPassengers,
        IsWheelchairReady
    FROM dbo.VehicleType
    ORDER BY Name;
END
GO

-- =============================================
-- Get vehicle types filtered by driver type
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetVehicleTypesByDriverType
    @DriverType NVARCHAR(50) = NULL  -- 'ride' or 'cargo'
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        vt.VehicleTypeID, 
        vt.Name,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM dbo.VehicleType_ServiceType vts 
                WHERE vts.VehicleTypeID = vt.VehicleTypeID 
                AND vts.ServiceTypeID IN (3, 4) -- Light or Heavy Cargo
            ) THEN 'cargo'
            ELSE 'ride'
        END AS Category
    FROM dbo.VehicleType vt
    WHERE @DriverType IS NULL 
       OR (@DriverType = 'cargo' AND EXISTS (
           SELECT 1 FROM dbo.VehicleType_ServiceType vts 
           WHERE vts.VehicleTypeID = vt.VehicleTypeID 
           AND vts.ServiceTypeID IN (3, 4) -- Has cargo capability
       ))
       OR (@DriverType = 'ride' AND EXISTS (
           SELECT 1 FROM dbo.VehicleType_ServiceType vts 
           WHERE vts.VehicleTypeID = vt.VehicleTypeID 
           AND vts.ServiceTypeID IN (1, 2) -- Has passenger capability (Standard or Luxury)
       ))
    ORDER BY vt.Name;
END
GO

-- =====================================================
-- GET USER SALT FOR LOGIN
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetUserPasswordSalt
    @Email NVARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 
        u.UserID, 
        u.Email, 
        u.FullName, 
        u.Status, 
        ph.PasswordSalt
    FROM [User] u
    INNER JOIN PasswordHistory ph ON u.UserID = ph.UserID AND ph.IsCurrent = 1
    WHERE u.Email = @Email;
END
GO

-- =====================================================
-- GET USER ROLES
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetUserRoles
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Return passenger info
    SELECT PassengerID, LoyaltyLevel 
    FROM Passenger 
    WHERE UserID = @UserID;
    
    -- Return driver info (include UseGPS for real driver trip detection)
    SELECT DriverID, DriverType, IsAvailable, VerificationStatus, UseGPS 
    FROM Driver 
    WHERE UserID = @UserID;
    
    -- Return operator info
    SELECT OperatorID, Role 
    FROM Operator 
    WHERE UserID = @UserID;
END
GO

-- =====================================================
-- GET USER PROFILE
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetUserProfile
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT UserID, Email, Phone, FullName, Status, CreatedAt,
           StreetAddress, City, PostalCode, Country,
           PrefLocationTracking, PrefNotifications, PrefEmailUpdates, PrefDataSharing
    FROM [User]
    WHERE UserID = @UserID;
END
GO

-- =====================================================
-- UPDATE USER PROFILE
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateUserProfile
    @UserID INT,
    @FullName NVARCHAR(255),
    @Phone NVARCHAR(50),
    -- Address fields
    @StreetAddress NVARCHAR(255) = NULL,
    @City NVARCHAR(100) = NULL,
    @PostalCode NVARCHAR(20) = NULL,
    @Country NVARCHAR(100) = NULL,
    -- GDPR Preferences
    @PrefLocationTracking BIT = NULL,
    @PrefNotifications BIT = NULL,
    @PrefEmailUpdates BIT = NULL,
    @PrefDataSharing BIT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE [User] 
    SET FullName = @FullName, 
        Phone = @Phone,
        StreetAddress = COALESCE(@StreetAddress, StreetAddress),
        City = COALESCE(@City, City),
        PostalCode = COALESCE(@PostalCode, PostalCode),
        Country = COALESCE(@Country, Country),
        PrefLocationTracking = COALESCE(@PrefLocationTracking, PrefLocationTracking),
        PrefNotifications = COALESCE(@PrefNotifications, PrefNotifications),
        PrefEmailUpdates = COALESCE(@PrefEmailUpdates, PrefEmailUpdates),
        PrefDataSharing = COALESCE(@PrefDataSharing, PrefDataSharing)
    WHERE UserID = @UserID;
    
    SELECT CAST(@@ROWCOUNT AS BIT) AS Success;
END
GO

-- =====================================================
-- GET USER CURRENT PASSWORD
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetUserCurrentPassword
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT ph.PasswordSalt, ph.PasswordHash
    FROM PasswordHistory ph
    WHERE ph.UserID = @UserID AND ph.IsCurrent = 1;
END
GO

-- =====================================================
-- GET SERVICE TYPES
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetServiceTypes
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT ServiceTypeID, Code, Name, Description 
    FROM dbo.ServiceType 
    WHERE IsActive = 1 
    ORDER BY ServiceTypeID;
END
GO

-- =====================================================
-- CHECK USER EXISTS BY EMAIL
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spCheckUserByEmail
    @Email NVARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT u.UserID 
    FROM dbo.[User] u 
    WHERE u.Email = @Email;
END
GO

-- =====================================================
-- GET DRIVER VERIFICATION STATUS
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverVerificationStatus
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT VerificationStatus 
    FROM dbo.Driver 
    WHERE UserID = @UserID;
END
GO

-- =====================================================
-- GET GDPR REQUEST STATUS
-- =====================================================
CREATE OR ALTER PROCEDURE dbo.spGetGDPRRequestStatus
    @RequestID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT Status 
    FROM GDPRRequest 
    WHERE RequestID = @RequestID;
END
GO

-- ============================================================================
-- GDPR Request Management
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.spGetUserGDPRRequests
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        RequestID, 
        RequestedAt, 
        Status, 
        Reason, 
        CompletedAt
    FROM GDPRRequest
    WHERE UserID = @UserID
    ORDER BY RequestedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spCancelGdprRequest
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE GDPRRequest
    SET Status = 'cancelled'
    WHERE UserID = @UserID
      AND Status = 'pending';
    
    SELECT 1 AS Success;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetPendingGDPRRequests
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        g.RequestID,
        g.UserID,
        u.FullName,
        u.Email,
        g.RequestedAt,
        g.Status,
        g.Reason
    FROM GDPRRequest g
    JOIN [User] u ON g.UserID = u.UserID
    WHERE g.Status = 'pending'
    ORDER BY g.RequestedAt ASC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetAllGDPRRequests
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        g.RequestID,
        g.UserID,
        u.FullName,
        u.Email,
        g.RequestedAt,
        g.Status,
        g.Reason,
        g.CompletedAt
    FROM GDPRRequest g
    JOIN [User] u ON g.UserID = u.UserID
    ORDER BY g.RequestedAt DESC;
END
GO

-- ============================================================================
-- Driver Management
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.spGetDriversWithFilters
    @DriverType VARCHAR(10) = NULL,
    @VerificationStatus VARCHAR(20) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        d.DriverID,
        d.DriverType,
        d.IsAvailable,
        d.VerificationStatus,
        d.RatingAverage,
        d.CreatedAt,
        u.FullName,
        u.Email
    FROM Driver d
    JOIN [User] u ON d.UserID = u.UserID
    WHERE 
        (@DriverType IS NULL OR d.DriverType = @DriverType)
        AND (@VerificationStatus IS NULL OR d.VerificationStatus = @VerificationStatus)
    ORDER BY d.VerificationStatus ASC, u.FullName ASC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetDriverDocumentCounts
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        DriverID, 
        COUNT(*) AS Cnt
    FROM DriverDocument
    GROUP BY DriverID;
END
GO

-- ============================================================================
-- Messaging System
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.spGetSupportOperator
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 
        u.UserID, 
        u.FullName
    FROM Operator o
    JOIN [User] u ON o.UserID = u.UserID
    ORDER BY o.OperatorID;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetPassengerLatestDriver
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 
        du.UserID AS DriverUserID, 
        du.FullName AS DriverName, 
        t.TripID
    FROM Trip t
    JOIN RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN Passenger p ON rr.PassengerID = p.PassengerID
    JOIN Driver d ON t.DriverID = d.DriverID
    JOIN [User] du ON d.UserID = du.UserID
    WHERE p.PassengerID = @PassengerID
        AND t.Status IN ('completed', 'active', 'assigned')
    ORDER BY t.DispatchTime DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetDriverLatestPassenger
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 
        pu.UserID AS PassengerUserID, 
        pu.FullName AS PassengerName, 
        t.TripID
    FROM Trip t
    JOIN RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN Passenger p ON rr.PassengerID = p.PassengerID
    JOIN [User] pu ON p.UserID = pu.UserID
    JOIN Driver d ON t.DriverID = d.DriverID
    WHERE d.DriverID = @DriverID
        AND t.Status IN ('completed', 'active', 'assigned')
    ORDER BY t.DispatchTime DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetOperatorMessageContacts
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get all drivers
    SELECT 
        u.UserID,
        u.FullName,
        'Driver' AS UserType,
        d.DriverID AS RoleID
    FROM Driver d
    JOIN [User] u ON d.UserID = u.UserID
    
    UNION ALL
    
    -- Get all passengers
    SELECT 
        u.UserID,
        u.FullName,
        'Passenger' AS UserType,
        p.PassengerID AS RoleID
    FROM Passenger p
    JOIN [User] u ON p.UserID = u.UserID
    
    ORDER BY FullName;
END
GO

-- ============================================================================
-- Database Viewer Procedures
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.spViewUsers
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        UserID, 
        Email, 
        FullName, 
        Status, 
        CreatedAt 
    FROM [User]
    ORDER BY CreatedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewPassengers
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Passenger
    ORDER BY PassengerID;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewDrivers
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Driver
    ORDER BY DriverID;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewOperators
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Operator
    ORDER BY OperatorID;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewPasswordHistory
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        PasswordID, 
        UserID, 
        IsCurrent, 
        CreatedAt 
    FROM PasswordHistory
    ORDER BY CreatedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewVehicles
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Vehicle
    ORDER BY VehicleID;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewRideRequests
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM RideRequest
    ORDER BY RequestedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewTrips
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Trip
    ORDER BY TripID DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewPayments
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Payment
    ORDER BY CreatedAt DESC;
END
GO

CREATE OR ALTER PROCEDURE dbo.spViewMessages
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@TopN) * 
    FROM Message 
    ORDER BY SentAt DESC;
END
GO
    
CREATE OR ALTER PROCEDURE dbo.spViewRatings
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM Rating
    ORDER BY CreatedAt DESC;
END
GO

-- =====================================================
-- FINANCIAL REPORTS PROCEDURES
-- =====================================================

-- Get financial summary for a date range
CREATE OR ALTER PROCEDURE dbo.spGetFinancialSummary
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ServiceFeeRate DECIMAL(5,4) = 0.00; -- 0% platform fee (no middleman)
    
    SELECT 
        COUNT(DISTINCT p.PaymentID) as TotalPayments,
        COUNT(DISTINCT t.TripID) as TotalTrips,
        COUNT(DISTINCT t.DriverID) as ActiveDrivers,
        SUM(p.Amount) as TotalRevenue,
        0 as TotalServiceFees,
        SUM(p.Amount) as TotalDriverPayouts,
        AVG(p.Amount) as AvgFare,
        SUM(t.TotalDistanceKm) as TotalDistance
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE p.Status = 'completed'
      AND p.CreatedAt >= @StartDate
      AND p.CreatedAt <= DATEADD(DAY, 1, @EndDate);
END
GO

-- Get daily revenue breakdown
CREATE OR ALTER PROCEDURE dbo.spGetDailyRevenue
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        CAST(p.CreatedAt AS DATE) as PaymentDay,
        COUNT(*) as PaymentCount,
        SUM(p.Amount) as DailyRevenue,
        0 as DailyServiceFees,
        SUM(p.Amount) as DailyDriverPayouts
    FROM dbo.Payment p
    WHERE p.Status = 'completed'
      AND p.CreatedAt >= @StartDate
      AND p.CreatedAt <= DATEADD(DAY, 1, @EndDate)
    GROUP BY CAST(p.CreatedAt AS DATE)
    ORDER BY PaymentDay DESC;
END
GO

-- Get payment method breakdown
CREATE OR ALTER PROCEDURE dbo.spGetPaymentMethodBreakdown
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        CASE pmt.Code
            WHEN 'CARD' THEN 'Card'
            WHEN 'CASH' THEN 'Cash'
            WHEN 'WALLET' THEN 'Wallet'
            ELSE pmt.Code
        END as MethodName,
        COUNT(*) as PaymentCount,
        SUM(p.Amount) as TotalAmount,
        0 as ServiceFees
    FROM dbo.Payment p
    INNER JOIN dbo.PaymentMethodType pmt ON p.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE p.Status = 'completed'
      AND p.CreatedAt >= @StartDate
      AND p.CreatedAt <= DATEADD(DAY, 1, @EndDate)
    GROUP BY pmt.PaymentMethodTypeID, pmt.Code
    ORDER BY TotalAmount DESC;
END
GO

-- Get top drivers by earnings
CREATE OR ALTER PROCEDURE dbo.spGetTopDrivers
    @StartDate DATE,
    @EndDate DATE,
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@TopN)
        d.DriverID,
        u.FullName,
        SUBSTRING(u.FullName, 1, CHARINDEX(' ', u.FullName + ' ') - 1) as FirstName,
        SUBSTRING(u.FullName, CHARINDEX(' ', u.FullName + ' ') + 1, LEN(u.FullName)) as LastName,
        COUNT(DISTINCT t.TripID) as TripCount,
        SUM(p.Amount) as GrossEarnings,
        0 as ServiceFeesPaid,
        SUM(p.Amount) as NetEarnings
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    INNER JOIN dbo.Driver d ON t.DriverID = d.DriverID
    INNER JOIN dbo.[User] u ON d.UserID = u.UserID
    WHERE p.Status = 'completed'
      AND p.CreatedAt >= @StartDate
      AND p.CreatedAt <= DATEADD(DAY, 1, @EndDate)
    GROUP BY d.DriverID, u.FullName
    ORDER BY GrossEarnings DESC;
END
GO

-- Get service type breakdown
CREATE OR ALTER PROCEDURE dbo.spGetServiceTypeBreakdown
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        st.Name as ServiceType,
        COUNT(*) as TripCount,
        SUM(p.Amount) as TotalRevenue,
        AVG(p.Amount) as AvgFare,
        0 as ServiceFees
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    WHERE p.Status = 'completed'
      AND p.CreatedAt >= @StartDate
      AND p.CreatedAt <= DATEADD(DAY, 1, @EndDate)
    GROUP BY st.ServiceTypeID, st.Name
    ORDER BY TotalRevenue DESC;
END
GO

-- Get driver earnings dashboard with summary and recent payments
CREATE OR ALTER PROCEDURE dbo.spDriverGetEarningsDashboard
    @DriverID INT,
    @StartDate DATE = NULL,
    @EndDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Default to current month if no dates provided
    IF @StartDate IS NULL
        SET @StartDate = DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1);
    IF @EndDate IS NULL
        SET @EndDate = CAST(GETDATE() AS DATE);
    
    -- Result Set 1: Summary statistics
    SELECT 
        COALESCE(SUM(p.Amount), 0) as TotalGross,
        0 as TotalServiceFee,
        COALESCE(SUM(p.Amount), 0) as TotalNet,
        COUNT(DISTINCT p.PaymentID) as TripCount
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE t.DriverID = @DriverID
      AND p.Status = 'completed'
      AND CAST(COALESCE(p.CompletedAt, p.CreatedAt) AS DATE) BETWEEN @StartDate AND @EndDate;
    
    -- Result Set 2: Simple payment list (no complex joins)
    SELECT 
        p.PaymentID,
        p.TripID,
        p.SegmentID,
        p.Amount as TotalFare,
        0 as ServiceFeeAmount,
        0.00 as ServiceFeeRate,
        p.Amount as DriverEarnings,
        p.Status,
        CASE WHEN p.SegmentID IS NOT NULL THEN 1 ELSE 0 END AS IsSegmentPayment
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE t.DriverID = @DriverID
      AND p.Status = 'completed'
      AND CAST(COALESCE(p.CompletedAt, p.CreatedAt) AS DATE) BETWEEN @StartDate AND @EndDate
    ORDER BY p.PaymentID DESC;
END
GO

-- Get driver recent payments (fallback for multi-result set issues)
CREATE OR ALTER PROCEDURE dbo.spDriverGetRecentPayments
    @DriverID INT,
    @StartDate DATE = NULL,
    @EndDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Default to current month if no dates provided
    IF @StartDate IS NULL
        SET @StartDate = DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1);
    IF @EndDate IS NULL
        SET @EndDate = CAST(GETDATE() AS DATE);
    
    SELECT 
        p.PaymentID,
        p.TripID,
        p.SegmentID,
        p.Amount as TotalFare,
        0 as ServiceFeeAmount,
        0.00 as ServiceFeeRate,
        p.Amount as DriverEarnings,
        p.Status,
        CASE WHEN p.SegmentID IS NOT NULL THEN 1 ELSE 0 END AS IsSegmentPayment
    FROM dbo.Payment p
    INNER JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE t.DriverID = @DriverID
      AND p.Status = 'completed'
      AND CAST(COALESCE(p.CompletedAt, p.CreatedAt) AS DATE) BETWEEN @StartDate AND @EndDate
    ORDER BY p.PaymentID DESC;
END
GO

-- View for completed trips with all necessary fields for reporting
CREATE OR ALTER VIEW dbo.vCompletedTrips AS
SELECT 
    t.TripID,
    t.RideRequestID,
    t.DriverID,
    t.VehicleID,
    t.Status,
    t.StartTime,
    t.EndTime,
    t.TotalDistanceKm,
    t.TotalDurationSec,
    v.VehicleTypeID,
    vt.Name AS VehicleTypeName,
    rr.PassengerID,
    pickupLoc.PostalCode AS PickupPostalCode,
    pickupLoc.LatDegrees AS PickupLat,
    pickupLoc.LonDegrees AS PickupLng,
    dropoffLoc.PostalCode AS DropoffPostalCode,
    dropoffLoc.LatDegrees AS DropoffLat,
    dropoffLoc.LonDegrees AS DropoffLng,
    pay.Amount,
    pay.CurrencyCode,
    pay.DriverEarnings,
    d.UserID AS DriverUserID,
    dUser.FullName AS DriverName,
    p.UserID AS PassengerUserID,
    pUser.FullName AS PassengerName
FROM dbo.Trip t
INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
INNER JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
INNER JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
INNER JOIN dbo.Location pickupLoc ON rr.PickupLocationID = pickupLoc.LocationID
INNER JOIN dbo.Location dropoffLoc ON rr.DropoffLocationID = dropoffLoc.LocationID
INNER JOIN dbo.Driver d ON t.DriverID = d.DriverID
INNER JOIN dbo.[User] dUser ON d.UserID = dUser.UserID
INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
INNER JOIN dbo.[User] pUser ON p.UserID = pUser.UserID
OUTER APPLY (
    SELECT TOP 1 
        py.Amount,
        py.CurrencyCode,
        py.DriverEarnings
    FROM dbo.Payment py
    WHERE py.TripID = t.TripID AND py.Status = 'completed'
    ORDER BY py.PaymentID DESC
) pay
WHERE t.Status = 'completed';
GO

-- ============================================================================
-- ============================================================================
-- DRIVER LOCATION TRACKING STORED PROCEDURES
-- (Consolidated from driver_location_tracking.sql)
-- ============================================================================
-- ============================================================================

-- Update Driver Location (GPS Simulation)
CREATE OR ALTER PROCEDURE dbo.spUpdateDriverLocation
    @DriverID   INT,
    @Latitude   DECIMAL(9,6),
    @Longitude  DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Driver
    SET 
        CurrentLatitude = @Latitude,
        CurrentLongitude = @Longitude,
        LocationUpdatedAt = SYSDATETIME()
    WHERE DriverID = @DriverID;

    INSERT INTO dbo.DriverLocationHistory 
        (DriverID, TripID, Latitude, Longitude, IsSimulated)
    VALUES 
        (@DriverID, NULL, @Latitude, @Longitude, 0);

    SELECT 'Location updated' AS Status, @Latitude AS Latitude, @Longitude AS Longitude;
END
GO

-- Set Driver Available with Location
CREATE OR ALTER PROCEDURE dbo.spSetDriverAvailable
    @DriverID   INT,
    @Latitude   DECIMAL(9,6),
    @Longitude  DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Driver
    SET 
        IsAvailable = 1,
        CurrentLatitude = @Latitude,
        CurrentLongitude = @Longitude,
        LocationUpdatedAt = SYSDATETIME()
    WHERE DriverID = @DriverID
      AND VerificationStatus = 'approved';

    IF @@ROWCOUNT = 0
    BEGIN
        RAISERROR('Driver not found or not approved.', 16, 1);
        RETURN;
    END

    INSERT INTO dbo.DriverLocationHistory 
        (DriverID, TripID, Latitude, Longitude, IsSimulated)
    VALUES 
        (@DriverID, NULL, @Latitude, @Longitude, 0);

    SELECT 'Driver is now available' AS Status;
END
GO

-- Set Driver Unavailable
CREATE OR ALTER PROCEDURE dbo.spSetDriverUnavailable
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.Driver
    SET IsAvailable = 0
    WHERE DriverID = @DriverID;

    SELECT 'Driver is now unavailable' AS Status;
END
GO

-- Initialize Trip Tracking
CREATE OR ALTER PROCEDURE dbo.spInitializeTripTracking
    @TripID             INT,
    @OverrideStartLat   DECIMAL(9,6) = NULL,
    @OverrideStartLng   DECIMAL(9,6) = NULL,
    @EstimatedMinutes   INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @DriverID INT;
    DECLARE @DriverLat DECIMAL(9,6), @DriverLng DECIMAL(9,6);
    DECLARE @PickupLat DECIMAL(9,6), @PickupLng DECIMAL(9,6);
    DECLARE @DistanceKm DECIMAL(10,3);
    DECLARE @EstMinutes INT;

    SELECT 
        @DriverID = t.DriverID,
        @DriverLat = COALESCE(@OverrideStartLat, d.CurrentLatitude),
        @DriverLng = COALESCE(@OverrideStartLng, d.CurrentLongitude),
        @PickupLat = l.LatDegrees,
        @PickupLng = l.LonDegrees
    FROM dbo.Trip t
    JOIN dbo.Driver d ON t.DriverID = d.DriverID
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Location l ON rr.PickupLocationID = l.LocationID
    WHERE t.TripID = @TripID;

    IF @PickupLat IS NULL
    BEGIN
        RAISERROR('Trip or pickup location not found.', 16, 1);
        RETURN;
    END

    IF @DriverLat IS NULL OR @DriverLng IS NULL
    BEGIN
        DECLARE @Angle FLOAT = RAND() * 2 * PI();
        DECLARE @Distance FLOAT = 0.8 + RAND() * 2.2;
        
        SET @DriverLat = @PickupLat + (@Distance / 111.0) * COS(@Angle);
        SET @DriverLng = @PickupLng + (@Distance / (111.0 * COS(RADIANS(@PickupLat)))) * SIN(@Angle);
        
        UPDATE dbo.Driver
        SET CurrentLatitude = @DriverLat,
            CurrentLongitude = @DriverLng,
            LocationUpdatedAt = SYSDATETIME()
        WHERE DriverID = @DriverID;
    END

    SET @DistanceKm = ROUND(
        6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(@PickupLat - @DriverLat) / 2), 2) +
                COS(RADIANS(@DriverLat)) * COS(RADIANS(@PickupLat)) *
                POWER(SIN(RADIANS(@PickupLng - @DriverLng) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(@PickupLat - @DriverLat) / 2), 2) +
                COS(RADIANS(@DriverLat)) * COS(RADIANS(@PickupLat)) *
                POWER(SIN(RADIANS(@PickupLng - @DriverLng) / 2), 2)
            ))
        ) * 1.3, 3);

    IF @EstimatedMinutes IS NULL
    BEGIN
        SET @EstMinutes = CEILING((@DistanceKm / 30.0) * 60);
        SET @EstMinutes = CASE 
            WHEN @EstMinutes < 2 THEN 2 
            WHEN @EstMinutes > 60 THEN 60 
            ELSE @EstMinutes 
        END;
    END
    ELSE
    BEGIN
        SET @EstMinutes = @EstimatedMinutes;
    END

    UPDATE dbo.Trip
    SET 
        DriverStartLat = @DriverLat,
        DriverStartLng = @DriverLng,
        SimulationStartTime = SYSDATETIME(),
        EstimatedPickupTime = DATEADD(MINUTE, @EstMinutes, SYSDATETIME())
    WHERE TripID = @TripID;

    INSERT INTO dbo.DriverLocationHistory 
        (DriverID, TripID, Latitude, Longitude, IsSimulated, Speed)
    VALUES 
        (@DriverID, @TripID, @DriverLat, @DriverLng, 1, 0);

    SELECT 
        @TripID AS TripID,
        @DriverID AS DriverID,
        @DriverLat AS DriverStartLat,
        @DriverLng AS DriverStartLng,
        @PickupLat AS PickupLat,
        @PickupLng AS PickupLng,
        @DistanceKm AS DistanceKm,
        @EstMinutes AS EstimatedMinutes,
        SYSDATETIME() AS SimulationStartTime,
        DATEADD(MINUTE, @EstMinutes, SYSDATETIME()) AS EstimatedPickupTime;
END
GO

-- Get Simulated Driver Position
-- Returns driver's current simulated position during pickup phase
-- Also returns PickupRouteGeometry for client-side route interpolation
-- SEGMENT AWARE: Uses segment FromLocationID for pickup in segment trips
CREATE OR ALTER PROCEDURE dbo.spGetSimulatedDriverPosition
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @DriverID INT;
    DECLARE @DriverStartLat DECIMAL(9,6), @DriverStartLng DECIMAL(9,6);
    DECLARE @PickupLat DECIMAL(9,6), @PickupLng DECIMAL(9,6);
    DECLARE @SimulationStartTime DATETIME2, @EstimatedPickupTime DATETIME2;
    DECLARE @TripStatus NVARCHAR(30);
    DECLARE @PickupRouteGeometry NVARCHAR(MAX);
    DECLARE @CurrentTime DATETIME2 = SYSDATETIME();
    DECLARE @ElapsedSeconds FLOAT, @TotalSeconds FLOAT, @Progress FLOAT;
    DECLARE @CurrentLat DECIMAL(9,6), @CurrentLng DECIMAL(9,6);
    DECLARE @DistanceKm DECIMAL(10,3), @SpeedKmh DECIMAL(5,2);
    
    -- Pickup speed multiplier variables
    DECLARE @PickupSpeedMultiplier DECIMAL(4,2);
    DECLARE @AccumulatedPickupSeconds FLOAT;
    DECLARE @LastPickupSpeedChangeAt DATETIME2;
    
    -- Segment awareness variables
    DECLARE @SegmentID INT;
    DECLARE @IsSegmentTrip BIT = 0;
    
    -- Check if this trip is linked to a segment
    SELECT @SegmentID = rs.SegmentID
    FROM dbo.RideSegment rs
    WHERE rs.TripID = @TripID;

    IF @SegmentID IS NOT NULL
    BEGIN
        SET @IsSegmentTrip = 1;
        
        -- SEGMENT TRIP: Pickup is segment's FromLocationID (e.g., bridge where previous driver dropped off)
        SELECT 
            @DriverID = t.DriverID,
            @DriverStartLat = t.DriverStartLat,
            @DriverStartLng = t.DriverStartLng,
            @SimulationStartTime = t.SimulationStartTime,
            @EstimatedPickupTime = t.EstimatedPickupTime,
            @TripStatus = t.Status,
            @PickupRouteGeometry = t.PickupRouteGeometry,
            @PickupLat = fromLoc.LatDegrees,
            @PickupLng = fromLoc.LonDegrees,
            @PickupSpeedMultiplier = ISNULL(t.PickupSpeedMultiplier, 1.0),
            @AccumulatedPickupSeconds = ISNULL(t.AccumulatedPickupSeconds, 0),
            @LastPickupSpeedChangeAt = t.LastPickupSpeedChangeAt
        FROM dbo.Trip t
        INNER JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
        INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
        WHERE t.TripID = @TripID;
    END
    ELSE
    BEGIN
        -- REGULAR TRIP: Pickup is ride request's PickupLocationID
        SELECT 
            @DriverID = t.DriverID,
            @DriverStartLat = t.DriverStartLat,
            @DriverStartLng = t.DriverStartLng,
            @SimulationStartTime = t.SimulationStartTime,
            @EstimatedPickupTime = t.EstimatedPickupTime,
            @TripStatus = t.Status,
            @PickupRouteGeometry = t.PickupRouteGeometry,
            @PickupLat = pl.LatDegrees,
            @PickupLng = pl.LonDegrees,
            @PickupSpeedMultiplier = ISNULL(t.PickupSpeedMultiplier, 1.0),
            @AccumulatedPickupSeconds = ISNULL(t.AccumulatedPickupSeconds, 0),
            @LastPickupSpeedChangeAt = t.LastPickupSpeedChangeAt
        FROM dbo.Trip t
        JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        JOIN dbo.Location pl ON rr.PickupLocationID = pl.LocationID
        WHERE t.TripID = @TripID;
    END

    IF @DriverStartLat IS NULL OR @SimulationStartTime IS NULL
    BEGIN
        SELECT 
            @TripID AS TripID,
            @PickupLat AS CurrentLat,
            @PickupLng AS CurrentLng,
            1.0 AS Progress,
            0 AS RemainingSeconds,
            @TripStatus AS TripStatus,
            1 AS HasArrived,
            'no_simulation_data' AS SimulationStatus,
            NULL AS PickupRouteGeometry,
            1.0 AS PickupSpeedMultiplier;
        RETURN;
    END

    SET @TotalSeconds = DATEDIFF(SECOND, @SimulationStartTime, @EstimatedPickupTime);
    IF @TotalSeconds <= 0 SET @TotalSeconds = 1;

    -- Calculate elapsed seconds with speed multiplier
    DECLARE @RealElapsed FLOAT;
    IF @LastPickupSpeedChangeAt IS NOT NULL
    BEGIN
        -- Time elapsed since last speed change, scaled by current multiplier
        SET @RealElapsed = DATEDIFF_BIG(MILLISECOND, @LastPickupSpeedChangeAt, @CurrentTime) / 1000.0;
        SET @ElapsedSeconds = @AccumulatedPickupSeconds + (@RealElapsed * @PickupSpeedMultiplier);
    END
    ELSE
    BEGIN
        -- No speed change yet, use simple calculation with multiplier
        SET @RealElapsed = DATEDIFF_BIG(MILLISECOND, @SimulationStartTime, @CurrentTime) / 1000.0;
        SET @ElapsedSeconds = @RealElapsed * @PickupSpeedMultiplier;
    END

    SET @Progress = @ElapsedSeconds / @TotalSeconds;
    IF @Progress < 0 SET @Progress = 0;
    IF @Progress > 1 SET @Progress = 1;

    -- Fallback interpolation (used if client doesn't have route)
    DECLARE @EasedProgress FLOAT;
    IF @Progress < 0.5
        SET @EasedProgress = 2 * POWER(@Progress, 2);
    ELSE
        SET @EasedProgress = 1 - POWER(-2 * @Progress + 2, 2) / 2;

    SET @CurrentLat = @DriverStartLat + (@PickupLat - @DriverStartLat) * @EasedProgress;
    SET @CurrentLng = @DriverStartLng + (@PickupLng - @DriverStartLng) * @EasedProgress;

    SET @DistanceKm = ROUND(
        6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(@PickupLat - @DriverStartLat) / 2), 2) +
                COS(RADIANS(@DriverStartLat)) * COS(RADIANS(@PickupLat)) *
                POWER(SIN(RADIANS(@PickupLng - @DriverStartLng) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(@PickupLat - @DriverStartLat) / 2), 2) +
                COS(RADIANS(@DriverStartLat)) * COS(RADIANS(@PickupLat)) *
                POWER(SIN(RADIANS(@PickupLng - @DriverStartLng) / 2), 2)
            ))
        ) * 1.3, 3);

    IF @TotalSeconds > 0
        SET @SpeedKmh = (@DistanceKm / @TotalSeconds) * 3600;
    ELSE
        SET @SpeedKmh = 0;

    INSERT INTO dbo.DriverLocationHistory 
        (DriverID, TripID, Latitude, Longitude, IsSimulated, Speed)
    VALUES 
        (@DriverID, @TripID, @CurrentLat, @CurrentLng, 1, @SpeedKmh);

    UPDATE dbo.Driver
    SET 
        CurrentLatitude = @CurrentLat,
        CurrentLongitude = @CurrentLng,
        LocationUpdatedAt = SYSDATETIME()
    WHERE DriverID = @DriverID;

    SELECT 
        @TripID AS TripID,
        @DriverID AS DriverID,
        ROUND(@CurrentLat, 6) AS CurrentLat,
        ROUND(@CurrentLng, 6) AS CurrentLng,
        @DriverStartLat AS StartLat,
        @DriverStartLng AS StartLng,
        @PickupLat AS PickupLat,
        @PickupLng AS PickupLng,
        ROUND(@Progress * 100, 1) AS ProgressPercent,
        CASE 
            WHEN @Progress >= 1 THEN 0 
            ELSE CAST((@TotalSeconds - @ElapsedSeconds) / @PickupSpeedMultiplier AS INT) 
        END AS RemainingSeconds,
        @TripStatus AS TripStatus,
        CASE WHEN @Progress >= 1 THEN 1 ELSE 0 END AS HasArrived,
        @SpeedKmh * @PickupSpeedMultiplier AS CurrentSpeedKmh,
        'active' AS SimulationStatus,
        @PickupRouteGeometry AS PickupRouteGeometry,
        @PickupSpeedMultiplier AS PickupSpeedMultiplier,
        @IsSegmentTrip AS IsSegmentTrip,
        @SegmentID AS SegmentID;
END
GO

-- Update Pickup Simulation Speed
CREATE OR ALTER PROCEDURE dbo.spUpdatePickupSimulationSpeed
    @TripID INT,
    @SpeedMultiplier DECIMAL(4,2)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @CurrentTime DATETIME2 = SYSDATETIME();
    DECLARE @SimulationStartTime DATETIME2;
    DECLARE @EstimatedPickupTime DATETIME2;
    DECLARE @OldSpeedMultiplier DECIMAL(4,2);
    DECLARE @AccumulatedPickupSeconds FLOAT;
    DECLARE @LastPickupSpeedChangeAt DATETIME2;
    DECLARE @TotalSeconds FLOAT;

    -- Clamp speed multiplier to valid range
    IF @SpeedMultiplier < 0.5 SET @SpeedMultiplier = 0.5;
    IF @SpeedMultiplier > 100 SET @SpeedMultiplier = 100;

    -- Get current values
    SELECT 
        @SimulationStartTime = SimulationStartTime,
        @EstimatedPickupTime = EstimatedPickupTime,
        @OldSpeedMultiplier = ISNULL(PickupSpeedMultiplier, 1.0),
        @AccumulatedPickupSeconds = ISNULL(AccumulatedPickupSeconds, 0),
        @LastPickupSpeedChangeAt = LastPickupSpeedChangeAt
    FROM dbo.Trip
    WHERE TripID = @TripID;

    IF @SimulationStartTime IS NULL
    BEGIN
        SELECT 0 AS Success, 'Trip not found or simulation not started' AS Message;
        RETURN;
    END

    SET @TotalSeconds = DATEDIFF(SECOND, @SimulationStartTime, @EstimatedPickupTime);
    IF @TotalSeconds <= 0 SET @TotalSeconds = 1;

    -- Calculate accumulated simulated seconds up to now
    DECLARE @RealElapsed FLOAT;
    IF @LastPickupSpeedChangeAt IS NOT NULL
    BEGIN
        SET @RealElapsed = DATEDIFF_BIG(MILLISECOND, @LastPickupSpeedChangeAt, @CurrentTime) / 1000.0;
        SET @AccumulatedPickupSeconds = @AccumulatedPickupSeconds + (@RealElapsed * @OldSpeedMultiplier);
    END
    ELSE
    BEGIN
        SET @RealElapsed = DATEDIFF_BIG(MILLISECOND, @SimulationStartTime, @CurrentTime) / 1000.0;
        SET @AccumulatedPickupSeconds = @RealElapsed * @OldSpeedMultiplier;
    END

    -- Cap accumulated seconds at total duration
    IF @AccumulatedPickupSeconds > @TotalSeconds 
        SET @AccumulatedPickupSeconds = @TotalSeconds;

    -- Update the speed multiplier and accumulated time
    UPDATE dbo.Trip
    SET 
        PickupSpeedMultiplier = @SpeedMultiplier,
        AccumulatedPickupSeconds = @AccumulatedPickupSeconds,
        LastPickupSpeedChangeAt = @CurrentTime
    WHERE TripID = @TripID;

    -- Calculate remaining time at new speed
    DECLARE @RemainingSimSeconds FLOAT = @TotalSeconds - @AccumulatedPickupSeconds;
    DECLARE @RemainingRealSeconds INT = CEILING(@RemainingSimSeconds / @SpeedMultiplier);

    SELECT 
        1 AS Success, 
        'Pickup speed updated to ' + CAST(@SpeedMultiplier AS VARCHAR) + 'x' AS Message,
        @SpeedMultiplier AS SpeedMultiplier,
        @RemainingRealSeconds AS RemainingSeconds,
        ROUND((@AccumulatedPickupSeconds / @TotalSeconds) * 100, 1) AS ProgressPercent;
END
GO

-- Get Trip Location History
CREATE OR ALTER PROCEDURE dbo.spGetTripLocationHistory
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        LocationHistoryID,
        DriverID,
        TripID,
        Latitude,
        Longitude,
        RecordedAt,
        Speed,
        Heading,
        IsSimulated
    FROM dbo.DriverLocationHistory
    WHERE TripID = @TripID
    ORDER BY RecordedAt ASC;
END
GO

-- Check Trip Tracking Status
CREATE OR ALTER PROCEDURE dbo.spCheckTripTrackingStatus
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        t.TripID,
        t.DriverID,
        t.Status AS TripStatus,
        t.DriverStartLat,
        t.DriverStartLng,
        t.SimulationStartTime,
        t.EstimatedPickupTime,
        d.CurrentLatitude AS DriverCurrentLat,
        d.CurrentLongitude AS DriverCurrentLng,
        d.LocationUpdatedAt AS DriverLocationUpdatedAt,
        CASE 
            WHEN t.Status IN ('assigned', 'in_progress') 
                AND t.SimulationStartTime IS NOT NULL 
                AND t.EstimatedPickupTime > SYSDATETIME()
            THEN 1 
            ELSE 0 
        END AS IsTrackingActive,
        CASE 
            WHEN t.EstimatedPickupTime IS NOT NULL 
            THEN DATEDIFF(SECOND, SYSDATETIME(), t.EstimatedPickupTime)
            ELSE NULL 
        END AS SecondsUntilArrival,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng
    FROM dbo.Trip t
    JOIN dbo.Driver d ON t.DriverID = d.DriverID
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Location pl ON rr.PickupLocationID = pl.LocationID
    JOIN dbo.Location dl ON rr.DropoffLocationID = dl.LocationID
    WHERE t.TripID = @TripID;
END
GO

-- Get Nearby Available Drivers
CREATE OR ALTER PROCEDURE dbo.spGetNearbyAvailableDrivers
    @Latitude   DECIMAL(9,6),
    @Longitude  DECIMAL(9,6),
    @RadiusKm   DECIMAL(5,2) = 10.0,
    @MaxResults INT = 20
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP (@MaxResults)
        d.DriverID,
        u.FullName AS DriverName,
        d.RatingAverage,
        d.CurrentLatitude,
        d.CurrentLongitude,
        d.LocationUpdatedAt,
        ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ))
            ), 2) AS DistanceKm,
        CEILING(
            (6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ))
            ) * 1.3 / 30.0) * 60
        ) AS EstimatedArrivalMinutes
    FROM dbo.Driver d
    JOIN dbo.[User] u ON d.UserID = u.UserID
    WHERE d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      AND d.CurrentLatitude IS NOT NULL
      AND d.CurrentLongitude IS NOT NULL
      AND 6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
            ))
        ) <= @RadiusKm
    ORDER BY DistanceKm ASC;
END
GO

-- Generate Simulated Driver Locations
CREATE OR ALTER PROCEDURE dbo.spGenerateSimulatedDriverLocations
    @CenterLat  DECIMAL(9,6) = 35.1856,
    @CenterLng  DECIMAL(9,6) = 33.3823,
    @RadiusKm   DECIMAL(5,2) = 5.0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @DriverID INT;
    DECLARE @Angle FLOAT;
    DECLARE @Distance FLOAT;
    DECLARE @NewLat DECIMAL(9,6);
    DECLARE @NewLng DECIMAL(9,6);

    DECLARE driver_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT DriverID 
        FROM dbo.Driver 
        WHERE UseGPS = 0 
          AND VerificationStatus = 'approved';

    OPEN driver_cursor;
    FETCH NEXT FROM driver_cursor INTO @DriverID;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @Angle = RAND() * 2 * PI();
        SET @Distance = RAND() * @RadiusKm;
        
        SET @NewLat = @CenterLat + (@Distance / 111.0) * COS(@Angle);
        SET @NewLng = @CenterLng + (@Distance / (111.0 * COS(RADIANS(@CenterLat)))) * SIN(@Angle);

        UPDATE dbo.Driver
        SET 
            CurrentLatitude = @NewLat,
            CurrentLongitude = @NewLng,
            LocationUpdatedAt = SYSDATETIME(),
            IsAvailable = 1
        WHERE DriverID = @DriverID;

        FETCH NEXT FROM driver_cursor INTO @DriverID;
    END

    CLOSE driver_cursor;
    DEALLOCATE driver_cursor;

    SELECT COUNT(*) AS DriversUpdated 
    FROM dbo.Driver 
    WHERE UseGPS = 0 AND VerificationStatus = 'approved';
END
GO

-- Auto-Assign Closest Simulated Driver
CREATE OR ALTER PROCEDURE dbo.spAutoAssignClosestSimulatedDriver
    @RideRequestID  INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @PickupLat DECIMAL(9,6), @PickupLng DECIMAL(9,6);
    DECLARE @DropoffLat DECIMAL(9,6), @DropoffLng DECIMAL(9,6);
    DECLARE @ClosestDriverID INT;
    DECLARE @ClosestVehicleID INT;
    DECLARE @DistanceKm DECIMAL(10,3);
    DECLARE @ServiceTypeID INT;
    DECLARE @PickupLocationID INT;
    DECLARE @DropoffLocationID INT;
    DECLARE @RealDriversOnly BIT;
    DECLARE @FirstSegmentID INT;
    DECLARE @SegmentCount INT;

    SELECT 
        @PickupLat = pl.LatDegrees,
        @PickupLng = pl.LonDegrees,
        @PickupLocationID = rr.PickupLocationID,
        @DropoffLat = dl.LatDegrees,
        @DropoffLng = dl.LonDegrees,
        @DropoffLocationID = rr.DropoffLocationID,
        @ServiceTypeID = rr.ServiceTypeID,
        @RealDriversOnly = ISNULL(rr.RealDriversOnly, 0)
    FROM dbo.RideRequest rr
    JOIN dbo.Location pl ON rr.PickupLocationID = pl.LocationID
    JOIN dbo.Location dl ON rr.DropoffLocationID = dl.LocationID
    WHERE rr.RideRequestID = @RideRequestID
      AND rr.Status = 'pending';

    IF @PickupLat IS NULL
    BEGIN
        SELECT 
            0 AS Success,
            'Ride request not found or not pending.' AS Message,
            NULL AS TripID;
        RETURN;
    END

    -- CRITICAL: If RealDriversOnly=1, do NOT auto-assign simulated driver
    -- Leave the ride pending for a real driver to manually accept
    IF @RealDriversOnly = 1
    BEGIN
        -- Count available real drivers (UseGPS = 1)
        DECLARE @AvailableRealDrivers INT = 0;
        SELECT @AvailableRealDrivers = COUNT(*)
        FROM dbo.Driver d
        WHERE d.UseGPS = 1
          AND d.IsAvailable = 1
          AND d.VerificationStatus = 'approved';

        SELECT 
            1 AS Success,
            'Ride request marked for real drivers only. Waiting for a real driver to accept.' AS Message,
            NULL AS TripID,
            NULL AS DriverID,
            NULL AS VehicleID,
            NULL AS DistanceKm,
            NULL AS EstimatedMinutesToPickup,
            NULL AS DriverStartLat,
            NULL AS DriverStartLng,
            @PickupLat AS PickupLat,
            @PickupLng AS PickupLng,
            1 AS WaitingForManualAccept,
            @AvailableRealDrivers AS AvailableRealDrivers;
        RETURN;
    END

    -- =====================================================
    -- CHECK FOR SEGMENT-BASED TRIPS (MULTI-VEHICLE JOURNEY)
    -- =====================================================
    SELECT 
        @SegmentCount = COUNT(*),
        @FirstSegmentID = MIN(SegmentID)
    FROM dbo.RideSegment
    WHERE RideRequestID = @RideRequestID
      AND TripID IS NULL;  -- Unassigned segments only
    
    -- If there are segments, use segment-based assignment
    IF @SegmentCount > 0 AND @FirstSegmentID IS NOT NULL
    BEGIN
        -- Get the first unassigned segment (SegmentOrder = 1 or next in sequence)
        SELECT TOP 1 @FirstSegmentID = rs.SegmentID
        FROM dbo.RideSegment rs
        WHERE rs.RideRequestID = @RideRequestID
          AND rs.TripID IS NULL
          AND (
              rs.SegmentOrder = 1
              OR EXISTS (
                  SELECT 1 
                  FROM dbo.RideSegment rs_prev
                  INNER JOIN dbo.Trip t_prev ON rs_prev.TripID = t_prev.TripID
                  WHERE rs_prev.RideRequestID = rs.RideRequestID
                    AND rs_prev.SegmentOrder = rs.SegmentOrder - 1
                    AND t_prev.Status = 'completed'
              )
          )
        ORDER BY rs.SegmentOrder;
        
        IF @FirstSegmentID IS NOT NULL
        BEGIN
            -- Use segment-based assignment
            EXEC dbo.spAutoAssignSimulatedDriverToSegment @FirstSegmentID;
            RETURN;
        END
    END
    
    -- =====================================================
    -- REGULAR (NON-SEGMENT) TRIP ASSIGNMENT
    -- =====================================================

    -- Try available drivers first
    SELECT TOP 1
        @ClosestDriverID = d.DriverID,
        @ClosestVehicleID = v.VehicleID,
        @DistanceKm = ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ))
            ), 3)
    FROM dbo.Driver d
    JOIN dbo.Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
    WHERE d.UseGPS = 0
      AND d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      AND d.CurrentLatitude IS NOT NULL
      -- Exclude vehicles with failed safety inspection
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.SafetyInspection si
          WHERE si.VehicleID = v.VehicleID
            AND si.Result = 'failed'
            AND si.SafetyInspectionID = (
                SELECT MAX(si2.SafetyInspectionID)
                FROM dbo.SafetyInspection si2
                WHERE si2.VehicleID = v.VehicleID
            )
      )
    ORDER BY 
        6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
            ))
        ) ASC;

    -- Try inactive drivers if none available
    IF @ClosestDriverID IS NULL
    BEGIN
        SELECT TOP 1
            @ClosestDriverID = d.DriverID,
            @ClosestVehicleID = v.VehicleID,
            @DistanceKm = ROUND(
                6371 * 2 * ATN2(
                    SQRT(
                        POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                        COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                        POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                    ),
                    SQRT(1 - (
                        POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                        COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                        POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                    ))
                ), 3)
        FROM dbo.Driver d
        JOIN dbo.Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
        WHERE d.UseGPS = 0
          AND d.IsAvailable = 0
          AND d.VerificationStatus = 'approved'
          AND d.CurrentLatitude IS NOT NULL
          -- Exclude vehicles with failed safety inspection
          AND NOT EXISTS (
              SELECT 1
              FROM dbo.SafetyInspection si
              WHERE si.VehicleID = v.VehicleID
                AND si.Result = 'failed'
                AND si.SafetyInspectionID = (
                    SELECT MAX(si2.SafetyInspectionID)
                    FROM dbo.SafetyInspection si2
                    WHERE si2.VehicleID = v.VehicleID
                )
          )
        ORDER BY 
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @PickupLat) / 2), 2) +
                    COS(RADIANS(@PickupLat)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @PickupLng) / 2), 2)
                ))
            ) ASC;
    END

    IF @ClosestDriverID IS NULL
    BEGIN
        SELECT 
            0 AS Success,
            'No simulated drivers available.' AS Message,
            NULL AS TripID;
        RETURN;
    END

    DECLARE @DriverLat DECIMAL(9,6), @DriverLng DECIMAL(9,6);
    SELECT 
        @DriverLat = CurrentLatitude,
        @DriverLng = CurrentLongitude
    FROM dbo.Driver
    WHERE DriverID = @ClosestDriverID;

    DECLARE @EstimatedMinutes INT = CEILING(@DistanceKm / 0.67);
    IF @EstimatedMinutes < 2 SET @EstimatedMinutes = 2;
    
    DECLARE @EstimatedPickupTime DATETIME2 = DATEADD(MINUTE, @EstimatedMinutes, SYSDATETIME());

    UPDATE dbo.Driver SET IsAvailable = 0 WHERE DriverID = @ClosestDriverID;
    UPDATE dbo.RideRequest SET Status = 'accepted' WHERE RideRequestID = @RideRequestID;

    INSERT INTO dbo.Trip (
        RideRequestID, DriverID, VehicleID, Status,
        DriverStartLat, DriverStartLng, EstimatedPickupTime, SimulationStartTime
    )
    VALUES (
        @RideRequestID, @ClosestDriverID, @ClosestVehicleID, 'assigned',
        @DriverLat, @DriverLng, @EstimatedPickupTime, SYSDATETIME()
    );

    DECLARE @TripID INT = SCOPE_IDENTITY();

    IF EXISTS (SELECT 1 FROM sys.tables WHERE name = 'DriverLocationHistory')
    BEGIN
        INSERT INTO dbo.DriverLocationHistory 
            (DriverID, TripID, Latitude, Longitude, IsSimulated)
        VALUES 
            (@ClosestDriverID, @TripID, @DriverLat, @DriverLng, 1);
    END

    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    VALUES (@RideRequestID, @ClosestDriverID, 'accepted', 
            'Auto-assigned to closest simulated driver (' + CAST(@DistanceKm AS VARCHAR) + ' km away)');

    SELECT 
        1 AS Success,
        'Driver assigned and en route to pickup!' AS Message,
        @TripID AS TripID,
        @ClosestDriverID AS DriverID,
        @ClosestVehicleID AS VehicleID,
        @DistanceKm AS DistanceKm,
        @EstimatedMinutes AS EstimatedMinutesToPickup,
        @DriverLat AS DriverStartLat,
        @DriverLng AS DriverStartLng,
        @PickupLat AS PickupLat,
        @PickupLng AS PickupLng;
END
GO

-- Get Available Drivers by Mode
CREATE OR ALTER PROCEDURE dbo.spGetAvailableDriversByMode
    @UseGPS     BIT = NULL,
    @Latitude   DECIMAL(9,6) = NULL,
    @Longitude  DECIMAL(9,6) = NULL,
    @RadiusKm   DECIMAL(5,2) = 10.0
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        d.DriverID,
        u.FullName AS DriverName,
        d.RatingAverage,
        d.CurrentLatitude,
        d.CurrentLongitude,
        d.LocationUpdatedAt,
        d.UseGPS,
        CASE WHEN d.UseGPS = 1 THEN 'GPS' ELSE 'Simulated' END AS LocationMode,
        CASE 
            WHEN @Latitude IS NOT NULL AND @Longitude IS NOT NULL THEN
                ROUND(
                    6371 * 2 * ATN2(
                        SQRT(
                            POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                            COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                            POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                        ),
                        SQRT(1 - (
                            POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                            COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                            POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                        ))
                    ), 2)
            ELSE NULL
        END AS DistanceKm
    FROM dbo.Driver d
    JOIN dbo.[User] u ON d.UserID = u.UserID
    WHERE d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      AND d.CurrentLatitude IS NOT NULL
      AND (@UseGPS IS NULL OR d.UseGPS = @UseGPS)
      AND (
          @Latitude IS NULL OR @Longitude IS NULL OR
          6371 * 2 * ATN2(
              SQRT(
                  POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                  COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                  POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
              ),
              SQRT(1 - (
                  POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                  COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                  POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
              ))
          ) <= @RadiusKm
      )
    ORDER BY 
        CASE WHEN @Latitude IS NOT NULL AND @Longitude IS NOT NULL THEN
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(d.CurrentLatitude - @Latitude) / 2), 2) +
                    COS(RADIANS(@Latitude)) * COS(RADIANS(d.CurrentLatitude)) *
                    POWER(SIN(RADIANS(d.CurrentLongitude - @Longitude) / 2), 2)
                ))
            )
        ELSE 0 END ASC;
END
GO

-- ============================================================================
-- ============================================================================
-- DYNAMIC PRICING STORED PROCEDURES
-- (Consolidated from dynamic_pricing.sql)
-- ============================================================================
-- ============================================================================

-- Calculate Demand Surge
CREATE OR ALTER PROCEDURE dbo.spCalculateDemandSurge
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6),
    @ServiceTypeID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @PendingRequests INT;
    DECLARE @AvailableDrivers INT;
    DECLARE @DemandSurge DECIMAL(4,2) = 1.00;
    DECLARE @SearchRadiusKm DECIMAL(5,2) = 5.0;
    
    DECLARE @LatDelta DECIMAL(9,6) = @SearchRadiusKm / 111.32;
    DECLARE @LonDelta DECIMAL(9,6) = @SearchRadiusKm / (111.32 * COS(RADIANS(@Latitude)));
    
    SELECT @PendingRequests = COUNT(*)
    FROM dbo.RideRequest rr
    INNER JOIN dbo.Location l ON rr.PickupLocationID = l.LocationID
    INNER JOIN dbo.RideRequestStatus rrs ON rr.RideRequestStatusID = rrs.RideRequestStatusID
    WHERE rrs.StatusName IN ('Pending', 'Searching')
      AND l.LatDegrees BETWEEN (@Latitude - @LatDelta) AND (@Latitude + @LatDelta)
      AND l.LonDegrees BETWEEN (@Longitude - @LonDelta) AND (@Longitude + @LonDelta)
      AND (@ServiceTypeID IS NULL OR rr.ServiceTypeID = @ServiceTypeID)
      AND rr.RequestedAt >= DATEADD(MINUTE, -30, GETDATE());
    
    SELECT @AvailableDrivers = COUNT(DISTINCT d.DriverID)
    FROM dbo.Driver d
    INNER JOIN dbo.DriverStatus ds ON d.DriverStatusID = ds.DriverStatusID
    WHERE ds.StatusName = 'Available'
      AND d.IsActive = 1;
    
    IF @AvailableDrivers > 0
    BEGIN
        DECLARE @Ratio DECIMAL(10,4) = CAST(@PendingRequests AS DECIMAL(10,4)) / CAST(@AvailableDrivers AS DECIMAL(10,4));
        
        SET @DemandSurge = CASE
            WHEN @Ratio <= 0.5 THEN 1.00
            WHEN @Ratio <= 1.0 THEN 1.10
            WHEN @Ratio <= 1.5 THEN 1.20
            WHEN @Ratio <= 2.0 THEN 1.35
            WHEN @Ratio <= 3.0 THEN 1.50
            WHEN @Ratio <= 5.0 THEN 1.75
            ELSE 2.00
        END;
    END
    ELSE IF @PendingRequests > 0
    BEGIN
        SET @DemandSurge = 2.00;
    END
    
    SELECT 
        @DemandSurge AS DemandSurge,
        @PendingRequests AS PendingRequests,
        @AvailableDrivers AS AvailableDrivers,
        CASE 
            WHEN @DemandSurge >= 1.75 THEN 'Very High'
            WHEN @DemandSurge >= 1.35 THEN 'High'
            WHEN @DemandSurge >= 1.10 THEN 'Moderate'
            ELSE 'Normal'
        END AS DemandLevel;
END
GO

-- Get Time-Based Surge
CREATE OR ALTER PROCEDURE dbo.spGetTimeSurge
    @CheckTime DATETIME2 = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @CheckTime IS NULL SET @CheckTime = GETDATE();
    
    DECLARE @DayOfWeek INT = DATEPART(WEEKDAY, @CheckTime);
    DECLARE @Hour INT = DATEPART(HOUR, @CheckTime);
    DECLARE @TimeSurge DECIMAL(4,2) = 1.00;
    DECLARE @Description NVARCHAR(100) = 'Standard Rate';
    
    SELECT TOP 1 
        @TimeSurge = SurgeMultiplier,
        @Description = ISNULL(Description, 'Peak Hours')
    FROM dbo.PeakHours
    WHERE DayOfWeek = @DayOfWeek
      AND @Hour >= StartHour
      AND @Hour < EndHour
      AND IsActive = 1
    ORDER BY SurgeMultiplier DESC;
    
    SELECT 
        @TimeSurge AS TimeSurge,
        @Description AS SurgeDescription,
        @DayOfWeek AS DayOfWeek,
        @Hour AS CurrentHour;
END
GO

-- Get Vehicle Type Pricing
CREATE OR ALTER PROCEDURE dbo.spGetVehicleTypePricing
    @VehicleTypeID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        vtp.VehicleTypePricingID,
        vtp.VehicleTypeID,
        vt.Name AS VehicleTypeName,
        vtp.PriceMultiplier,
        vtp.MinimumFareOverride,
        vtp.IsActive
    FROM dbo.VehicleTypePricing vtp
    INNER JOIN dbo.VehicleType vt ON vtp.VehicleTypeID = vt.VehicleTypeID
    WHERE (@VehicleTypeID IS NULL OR vtp.VehicleTypeID = @VehicleTypeID)
      AND vtp.IsActive = 1
    ORDER BY vt.Name;
END
GO

-- Calculate Dynamic Fare
CREATE OR ALTER PROCEDURE dbo.spCalculateDynamicFare
    @ServiceTypeID INT,
    @VehicleTypeID INT = NULL,
    @PickupLatitude DECIMAL(9,6),
    @PickupLongitude DECIMAL(9,6),
    @DropoffLatitude DECIMAL(9,6),
    @DropoffLongitude DECIMAL(9,6),
    @DistanceKm DECIMAL(10,3) = NULL,
    @EstimatedDurationMin INT = NULL,
    @DriverID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @DistanceKm IS NULL OR @DistanceKm <= 0
    BEGIN
        DECLARE @EarthRadiusKm DECIMAL(10,2) = 6371.0;
        DECLARE @LatDiff DECIMAL(20,15) = RADIANS(@DropoffLatitude - @PickupLatitude);
        DECLARE @LonDiff DECIMAL(20,15) = RADIANS(@DropoffLongitude - @PickupLongitude);
        
        DECLARE @A DECIMAL(30,20) = SIN(@LatDiff/2) * SIN(@LatDiff/2) +
            COS(RADIANS(@PickupLatitude)) * COS(RADIANS(@DropoffLatitude)) *
            SIN(@LonDiff/2) * SIN(@LonDiff/2);
        
        DECLARE @C DECIMAL(30,20) = 2 * ATN2(SQRT(@A), SQRT(1-@A));
        
        SET @DistanceKm = ROUND(@EarthRadiusKm * @C * 1.3, 2);
    END
    
    IF @EstimatedDurationMin IS NULL OR @EstimatedDurationMin <= 0
    BEGIN
        SET @EstimatedDurationMin = CEILING((@DistanceKm / 30.0) * 60);
        IF @EstimatedDurationMin < 5 SET @EstimatedDurationMin = 5;
    END
    
    DECLARE @BaseFare DECIMAL(10,2);
    DECLARE @PricePerKm DECIMAL(10,2);
    DECLARE @PricePerMinute DECIMAL(10,2);
    DECLARE @MinimumFare DECIMAL(10,2);
    DECLARE @ServiceFeeRate DECIMAL(5,4);
    DECLARE @ConfigSurge DECIMAL(4,2);
    
    SELECT 
        @BaseFare = ISNULL(pc.BaseFare, 3.00),
        @PricePerKm = ISNULL(pc.PricePerKm, 1.20),
        @PricePerMinute = ISNULL(pc.PricePerMinute, 0.20),
        @MinimumFare = ISNULL(pc.MinimumFare, 5.00),
        @ServiceFeeRate = ISNULL(pc.ServiceFeeRate, 0.00),
        @ConfigSurge = ISNULL(pc.SurgeMultiplier, 1.00)
    FROM dbo.PricingConfig pc
    WHERE pc.ServiceTypeID = @ServiceTypeID AND pc.IsActive = 1;
    
    IF @BaseFare IS NULL SET @BaseFare = 3.00;
    IF @PricePerKm IS NULL SET @PricePerKm = 1.20;
    IF @PricePerMinute IS NULL SET @PricePerMinute = 0.20;
    IF @MinimumFare IS NULL SET @MinimumFare = 5.00;
    IF @ServiceFeeRate IS NULL SET @ServiceFeeRate = 0.00;
    IF @ConfigSurge IS NULL SET @ConfigSurge = 1.00;
    
    DECLARE @VehicleMultiplier DECIMAL(4,2) = 1.00;
    DECLARE @VehicleMinFare DECIMAL(10,2) = NULL;
    
    IF @VehicleTypeID IS NOT NULL
    BEGIN
        SELECT 
            @VehicleMultiplier = ISNULL(PriceMultiplier, 1.00),
            @VehicleMinFare = MinimumFareOverride
        FROM dbo.VehicleTypePricing
        WHERE VehicleTypeID = @VehicleTypeID AND IsActive = 1;
    END
    
    DECLARE @DemandSurge DECIMAL(4,2) = 1.00;
    DECLARE @PendingRequests INT = 0;
    DECLARE @AvailableDrivers INT = 0;
    
    DECLARE @SearchRadiusKm DECIMAL(5,2) = 5.0;
    DECLARE @LatDelta DECIMAL(9,6) = @SearchRadiusKm / 111.32;
    DECLARE @LonDelta DECIMAL(9,6) = @SearchRadiusKm / (111.32 * COS(RADIANS(@PickupLatitude)));
    
    SELECT @PendingRequests = COUNT(*)
    FROM dbo.RideRequest rr
    INNER JOIN dbo.Location l ON rr.PickupLocationID = l.LocationID
    INNER JOIN dbo.RideRequestStatus rrs ON rr.RideRequestStatusID = rrs.RideRequestStatusID
    WHERE rrs.StatusName IN ('Pending', 'Searching')
      AND l.LatDegrees BETWEEN (@PickupLatitude - @LatDelta) AND (@PickupLatitude + @LatDelta)
      AND l.LonDegrees BETWEEN (@PickupLongitude - @LonDelta) AND (@PickupLongitude + @LonDelta)
      AND rr.RequestedAt >= DATEADD(MINUTE, -30, GETDATE());
    
    SELECT @AvailableDrivers = COUNT(DISTINCT d.DriverID)
    FROM dbo.Driver d
    INNER JOIN dbo.DriverStatus ds ON d.DriverStatusID = ds.DriverStatusID
    WHERE ds.StatusName = 'Available' AND d.IsActive = 1;
    
    IF @AvailableDrivers > 0
    BEGIN
        DECLARE @Ratio DECIMAL(10,4) = CAST(@PendingRequests AS DECIMAL) / CAST(@AvailableDrivers AS DECIMAL);
        SET @DemandSurge = CASE
            WHEN @Ratio <= 0.5 THEN 1.00
            WHEN @Ratio <= 1.0 THEN 1.10
            WHEN @Ratio <= 1.5 THEN 1.20
            WHEN @Ratio <= 2.0 THEN 1.35
            WHEN @Ratio <= 3.0 THEN 1.50
            WHEN @Ratio <= 5.0 THEN 1.75
            ELSE 2.00
        END;
    END
    ELSE IF @PendingRequests > 0
    BEGIN
        SET @DemandSurge = 2.00;
    END
    
    DECLARE @TimeSurge DECIMAL(4,2) = 1.00;
    DECLARE @DayOfWeek INT = DATEPART(WEEKDAY, GETDATE());
    DECLARE @CurrentHour INT = DATEPART(HOUR, GETDATE());
    
    SELECT TOP 1 @TimeSurge = SurgeMultiplier
    FROM dbo.PeakHours
    WHERE DayOfWeek = @DayOfWeek
      AND @CurrentHour >= StartHour
      AND @CurrentHour < EndHour
      AND IsActive = 1
    ORDER BY SurgeMultiplier DESC;
    
    IF @TimeSurge IS NULL SET @TimeSurge = 1.00;
    
    DECLARE @DriverMinFare DECIMAL(10,2) = NULL;
    
    IF @DriverID IS NOT NULL
    BEGIN
        SELECT TOP 1 @DriverMinFare = MinimumFare
        FROM dbo.DriverPricing
        WHERE DriverID = @DriverID 
          AND (ServiceTypeID = @ServiceTypeID OR ServiceTypeID IS NULL)
          AND IsActive = 1
        ORDER BY ServiceTypeID DESC;
    END
    
    DECLARE @CombinedSurge DECIMAL(4,2) = @VehicleMultiplier * 
        CASE WHEN @DemandSurge > @TimeSurge THEN @DemandSurge ELSE @TimeSurge END;
    
    IF @CombinedSurge > 3.00 SET @CombinedSurge = 3.00;
    
    DECLARE @DistanceFare DECIMAL(10,2) = ROUND(@DistanceKm * @PricePerKm, 2);
    DECLARE @TimeFare DECIMAL(10,2) = ROUND(@EstimatedDurationMin * @PricePerMinute, 2);
    DECLARE @Subtotal DECIMAL(10,2) = @BaseFare + @DistanceFare + @TimeFare;
    DECLARE @SubtotalWithSurge DECIMAL(10,2) = ROUND(@Subtotal * @CombinedSurge, 2);
    
    DECLARE @EffectiveMinFare DECIMAL(10,2) = @MinimumFare;
    IF @VehicleMinFare IS NOT NULL AND @VehicleMinFare > @EffectiveMinFare
        SET @EffectiveMinFare = @VehicleMinFare;
    IF @DriverMinFare IS NOT NULL AND @DriverMinFare > @EffectiveMinFare
        SET @EffectiveMinFare = @DriverMinFare;
    
    DECLARE @TotalFare DECIMAL(10,2) = CASE 
        WHEN @SubtotalWithSurge < @EffectiveMinFare THEN @EffectiveMinFare
        ELSE @SubtotalWithSurge
    END;
    
    DECLARE @ServiceFeeAmount DECIMAL(10,2) = ROUND(@TotalFare * @ServiceFeeRate, 2);
    DECLARE @DriverEarnings DECIMAL(10,2) = @TotalFare - @ServiceFeeAmount;
    
    SELECT 
        @BaseFare AS BaseFare,
        @PricePerKm AS PricePerKm,
        @PricePerMinute AS PricePerMinute,
        @DistanceFare AS DistanceFare,
        @TimeFare AS TimeFare,
        @Subtotal AS Subtotal,
        @VehicleMultiplier AS VehicleMultiplier,
        @DemandSurge AS DemandSurge,
        @TimeSurge AS TimeSurge,
        @CombinedSurge AS TotalSurgeMultiplier,
        @PendingRequests AS PendingRequestsInArea,
        @AvailableDrivers AS AvailableDriversInArea,
        CASE 
            WHEN @DemandSurge >= 1.75 THEN 'Very High'
            WHEN @DemandSurge >= 1.35 THEN 'High'
            WHEN @DemandSurge >= 1.10 THEN 'Moderate'
            ELSE 'Normal'
        END AS DemandLevel,
        CASE @DayOfWeek
            WHEN 1 THEN 'Sunday'
            WHEN 2 THEN 'Monday'
            WHEN 3 THEN 'Tuesday'
            WHEN 4 THEN 'Wednesday'
            WHEN 5 THEN 'Thursday'
            WHEN 6 THEN 'Friday'
            WHEN 7 THEN 'Saturday'
        END AS CurrentDay,
        @CurrentHour AS CurrentHour,
        CASE WHEN @TimeSurge > 1.00 THEN 1 ELSE 0 END AS IsPeakHours,
        @MinimumFare AS BaseMinimumFare,
        @VehicleMinFare AS VehicleMinimumFare,
        @DriverMinFare AS DriverMinimumFare,
        @EffectiveMinFare AS EffectiveMinimumFare,
        @SubtotalWithSurge AS SubtotalWithSurge,
        @TotalFare AS TotalFare,
        @ServiceFeeRate AS ServiceFeeRate,
        @ServiceFeeAmount AS ServiceFeeAmount,
        @DriverEarnings AS DriverEarnings,
        @DistanceKm AS DistanceKm,
        @EstimatedDurationMin AS EstimatedDurationMin,
        CASE WHEN @CombinedSurge > 1.00 THEN 1 ELSE 0 END AS IsSurgeActive;
END
GO

-- Update Peak Hours
CREATE OR ALTER PROCEDURE dbo.spUpdatePeakHours
    @PeakHourID INT = NULL,
    @DayOfWeek INT,
    @StartHour INT,
    @EndHour INT,
    @SurgeMultiplier DECIMAL(4,2),
    @Description NVARCHAR(100) = NULL,
    @IsActive BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @PeakHourID IS NULL
    BEGIN
        INSERT INTO dbo.PeakHours (DayOfWeek, StartHour, EndHour, SurgeMultiplier, Description, IsActive)
        VALUES (@DayOfWeek, @StartHour, @EndHour, @SurgeMultiplier, @Description, @IsActive);
        
        SET @PeakHourID = SCOPE_IDENTITY();
    END
    ELSE
    BEGIN
        UPDATE dbo.PeakHours
        SET DayOfWeek = @DayOfWeek,
            StartHour = @StartHour,
            EndHour = @EndHour,
            SurgeMultiplier = @SurgeMultiplier,
            Description = @Description,
            IsActive = @IsActive
        WHERE PeakHourID = @PeakHourID;
    END
    
    SELECT @PeakHourID AS PeakHourID, 'OK' AS Result;
END
GO

-- Get All Peak Hours
CREATE OR ALTER PROCEDURE dbo.spGetPeakHours
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        PeakHourID,
        DayOfWeek,
        CASE DayOfWeek
            WHEN 1 THEN 'Sunday'
            WHEN 2 THEN 'Monday'
            WHEN 3 THEN 'Tuesday'
            WHEN 4 THEN 'Wednesday'
            WHEN 5 THEN 'Thursday'
            WHEN 6 THEN 'Friday'
            WHEN 7 THEN 'Saturday'
        END AS DayName,
        StartHour,
        EndHour,
        FORMAT(DATEADD(HOUR, StartHour, 0), 'HH:mm') + ' - ' + FORMAT(DATEADD(HOUR, EndHour, 0), 'HH:mm') AS TimeRange,
        SurgeMultiplier,
        Description,
        IsActive
    FROM dbo.PeakHours
    ORDER BY DayOfWeek, StartHour;
END
GO

-- Update Vehicle Type Pricing
CREATE OR ALTER PROCEDURE dbo.spUpdateVehicleTypePricing
    @VehicleTypeID INT,
    @PriceMultiplier DECIMAL(4,2),
    @MinimumFareOverride DECIMAL(10,2) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF EXISTS (SELECT 1 FROM dbo.VehicleTypePricing WHERE VehicleTypeID = @VehicleTypeID)
    BEGIN
        UPDATE dbo.VehicleTypePricing
        SET PriceMultiplier = @PriceMultiplier,
            MinimumFareOverride = @MinimumFareOverride
        WHERE VehicleTypeID = @VehicleTypeID;
    END
    ELSE
    BEGIN
        INSERT INTO dbo.VehicleTypePricing (VehicleTypeID, PriceMultiplier, MinimumFareOverride)
        VALUES (@VehicleTypeID, @PriceMultiplier, @MinimumFareOverride);
    END
    
    SELECT 'OK' AS Result;
END
GO

-- Get Current Surge Status
CREATE OR ALTER PROCEDURE dbo.spGetCurrentSurgeStatus
    @Latitude DECIMAL(9,6) = NULL,
    @Longitude DECIMAL(9,6) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @TimeSurge DECIMAL(4,2) = 1.00;
    DECLARE @TimeDescription NVARCHAR(100) = 'Standard Rate';
    DECLARE @DayOfWeek INT = DATEPART(WEEKDAY, GETDATE());
    DECLARE @Hour INT = DATEPART(HOUR, GETDATE());
    
    SELECT TOP 1 
        @TimeSurge = SurgeMultiplier,
        @TimeDescription = ISNULL(Description, 'Peak Hours')
    FROM dbo.PeakHours
    WHERE DayOfWeek = @DayOfWeek
      AND @Hour >= StartHour
      AND @Hour < EndHour
      AND IsActive = 1;
    
    DECLARE @DemandSurge DECIMAL(4,2) = 1.00;
    DECLARE @DemandLevel NVARCHAR(20) = 'Normal';
    
    IF @Latitude IS NOT NULL AND @Longitude IS NOT NULL
    BEGIN
        DECLARE @SearchRadiusKm DECIMAL(5,2) = 5.0;
        DECLARE @LatDelta DECIMAL(9,6) = @SearchRadiusKm / 111.32;
        DECLARE @LonDelta DECIMAL(9,6) = @SearchRadiusKm / (111.32 * COS(RADIANS(@Latitude)));
        
        DECLARE @Pending INT, @Available INT;
        
        SELECT @Pending = COUNT(*)
        FROM dbo.RideRequest rr
        INNER JOIN dbo.Location l ON rr.PickupLocationID = l.LocationID
        INNER JOIN dbo.RideRequestStatus rrs ON rr.RideRequestStatusID = rrs.RideRequestStatusID
        WHERE rrs.StatusName IN ('Pending', 'Searching')
          AND l.LatDegrees BETWEEN (@Latitude - @LatDelta) AND (@Latitude + @LatDelta)
          AND l.LonDegrees BETWEEN (@Longitude - @LonDelta) AND (@Longitude + @LonDelta)
          AND rr.RequestedAt >= DATEADD(MINUTE, -30, GETDATE());
        
        SELECT @Available = COUNT(*)
        FROM dbo.Driver d
        INNER JOIN dbo.DriverStatus ds ON d.DriverStatusID = ds.DriverStatusID
        WHERE ds.StatusName = 'Available' AND d.IsActive = 1;
        
        IF @Available > 0
        BEGIN
            DECLARE @Ratio DECIMAL(10,4) = CAST(@Pending AS DECIMAL) / CAST(@Available AS DECIMAL);
            SET @DemandSurge = CASE
                WHEN @Ratio <= 0.5 THEN 1.00
                WHEN @Ratio <= 1.0 THEN 1.10
                WHEN @Ratio <= 1.5 THEN 1.20
                WHEN @Ratio <= 2.0 THEN 1.35
                WHEN @Ratio <= 3.0 THEN 1.50
                ELSE 1.75
            END;
        END
        
        SET @DemandLevel = CASE 
            WHEN @DemandSurge >= 1.50 THEN 'High'
            WHEN @DemandSurge >= 1.20 THEN 'Moderate'
            ELSE 'Normal'
        END;
    END
    
    SELECT 
        @TimeSurge AS TimeSurge,
        @TimeDescription AS TimeDescription,
        @DemandSurge AS DemandSurge,
        @DemandLevel AS DemandLevel,
        CASE WHEN @TimeSurge > @DemandSurge THEN @TimeSurge ELSE @DemandSurge END AS EffectiveSurge,
        CASE WHEN @TimeSurge > 1.00 OR @DemandSurge > 1.00 THEN 1 ELSE 0 END AS IsSurgeActive;
END
GO

-- ============================================================================
-- ============================================================================
-- PAYMENT SYSTEM STORED PROCEDURES
-- (Consolidated from payment_system.sql)
-- ============================================================================
-- ============================================================================

-- Get Pricing Configuration
CREATE OR ALTER PROCEDURE dbo.spGetPricingConfig
    @ServiceTypeID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        pc.PricingConfigID,
        pc.ServiceTypeID,
        st.Code AS ServiceTypeCode,
        st.Name AS ServiceTypeName,
        pc.BaseFare,
        pc.PricePerKm,
        pc.PricePerMinute,
        pc.MinimumFare,
        pc.ServiceFeeRate,
        pc.SurgeMultiplier,
        pc.IsActive
    FROM dbo.PricingConfig pc
    JOIN dbo.ServiceType st ON pc.ServiceTypeID = st.ServiceTypeID
    WHERE (@ServiceTypeID IS NULL OR pc.ServiceTypeID = @ServiceTypeID)
      AND pc.IsActive = 1
    ORDER BY st.ServiceTypeID;
END
GO

-- Calculate Fare Estimate
CREATE OR ALTER PROCEDURE dbo.spCalculateFareEstimate
    @ServiceTypeID INT,
    @DistanceKm DECIMAL(10,3),
    @EstimatedDurationMin INT = NULL,
    @DriverID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @BaseFare DECIMAL(10,2);
    DECLARE @PricePerKm DECIMAL(10,2);
    DECLARE @PricePerMinute DECIMAL(10,2);
    DECLARE @MinimumFare DECIMAL(10,2);
    DECLARE @ServiceFeeRate DECIMAL(5,4);
    DECLARE @SurgeMultiplier DECIMAL(4,2);
    DECLARE @DriverMinFare DECIMAL(10,2) = NULL;
    
    SELECT 
        @BaseFare = pc.BaseFare,
        @PricePerKm = pc.PricePerKm,
        @PricePerMinute = pc.PricePerMinute,
        @MinimumFare = pc.MinimumFare,
        @ServiceFeeRate = pc.ServiceFeeRate,
        @SurgeMultiplier = pc.SurgeMultiplier
    FROM dbo.PricingConfig pc
    WHERE pc.ServiceTypeID = @ServiceTypeID AND pc.IsActive = 1;
    
    IF @BaseFare IS NULL
    BEGIN
        SET @BaseFare = 3.00;
        SET @PricePerKm = 1.20;
        SET @PricePerMinute = 0.20;
        SET @MinimumFare = 5.00;
        SET @ServiceFeeRate = 0.0000;
        SET @SurgeMultiplier = 1.00;
    END
    
    IF @DriverID IS NOT NULL
    BEGIN
        SELECT TOP 1 @DriverMinFare = MinimumFare
        FROM dbo.DriverPricing
        WHERE DriverID = @DriverID 
          AND (ServiceTypeID = @ServiceTypeID OR ServiceTypeID IS NULL)
          AND IsActive = 1
        ORDER BY ServiceTypeID DESC;
    END
    
    DECLARE @DistanceFare DECIMAL(10,2) = ROUND(@DistanceKm * @PricePerKm, 2);
    DECLARE @TimeFare DECIMAL(10,2) = 0.00;
    
    IF @EstimatedDurationMin IS NOT NULL AND @EstimatedDurationMin > 0
    BEGIN
        SET @TimeFare = ROUND(@EstimatedDurationMin * @PricePerMinute, 2);
    END
    
    DECLARE @Subtotal DECIMAL(10,2) = @BaseFare + @DistanceFare + @TimeFare;
    DECLARE @SubtotalWithSurge DECIMAL(10,2) = ROUND(@Subtotal * @SurgeMultiplier, 2);
    
    DECLARE @EffectiveMinimum DECIMAL(10,2) = @MinimumFare;
    IF @DriverMinFare IS NOT NULL AND @DriverMinFare > @MinimumFare
    BEGIN
        SET @EffectiveMinimum = @DriverMinFare;
    END
    
    DECLARE @TotalFare DECIMAL(10,2) = CASE 
        WHEN @SubtotalWithSurge < @EffectiveMinimum THEN @EffectiveMinimum
        ELSE @SubtotalWithSurge
    END;
    
    DECLARE @ServiceFeeAmount DECIMAL(10,2) = ROUND(@TotalFare * @ServiceFeeRate, 2);
    DECLARE @DriverEarnings DECIMAL(10,2) = @TotalFare - @ServiceFeeAmount;
    
    SELECT 
        @BaseFare AS BaseFare,
        @DistanceFare AS DistanceFare,
        @TimeFare AS TimeFare,
        @Subtotal AS Subtotal,
        @SurgeMultiplier AS SurgeMultiplier,
        @SubtotalWithSurge AS SubtotalWithSurge,
        @EffectiveMinimum AS MinimumFare,
        @TotalFare AS TotalFare,
        @ServiceFeeRate AS ServiceFeeRate,
        @ServiceFeeAmount AS ServiceFeeAmount,
        @DriverEarnings AS DriverEarnings,
        @DistanceKm AS DistanceKm,
        @EstimatedDurationMin AS EstimatedDurationMin,
        CASE WHEN @SurgeMultiplier > 1.00 THEN 1 ELSE 0 END AS IsSurgeActive,
        @PricePerKm AS PricePerKm,
        @PricePerMinute AS PricePerMinute;
END
GO

-- Get Driver Earnings Summary
CREATE OR ALTER PROCEDURE dbo.spGetDriverEarningsSummary
    @DriverID INT,
    @StartDate DATE = NULL,
    @EndDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @StartDate IS NULL SET @StartDate = DATEADD(MONTH, -1, GETDATE());
    IF @EndDate IS NULL SET @EndDate = GETDATE();
    
    SELECT 
        COUNT(*) AS TotalTrips,
        SUM(p.Amount) AS GrossRevenue,
        SUM(p.ServiceFeeAmount) AS TotalServiceFees,
        SUM(p.DriverEarnings) AS NetEarnings,
        SUM(p.TipAmount) AS TotalTips,
        AVG(p.Amount) AS AvgFarePerTrip,
        AVG(p.DriverEarnings) AS AvgEarningsPerTrip,
        SUM(p.DistanceKm) AS TotalDistanceKm,
        SUM(p.DurationMinutes) AS TotalDurationMinutes
    FROM dbo.Payment p
    JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE t.DriverID = @DriverID
      AND p.Status = 'completed'
      AND CAST(p.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate;
    
    SELECT 
        CAST(p.CompletedAt AS DATE) AS Date,
        COUNT(*) AS Trips,
        SUM(p.Amount) AS GrossRevenue,
        SUM(p.ServiceFeeAmount) AS ServiceFees,
        SUM(p.DriverEarnings) AS NetEarnings,
        SUM(p.TipAmount) AS Tips
    FROM dbo.Payment p
    JOIN dbo.Trip t ON p.TripID = t.TripID
    WHERE t.DriverID = @DriverID
      AND p.Status = 'completed'
      AND CAST(p.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY CAST(p.CompletedAt AS DATE)
    ORDER BY CAST(p.CompletedAt AS DATE) DESC;
END
GO

-- Get Passenger Payment History
CREATE OR ALTER PROCEDURE dbo.spGetPassengerPaymentHistory
    @PassengerID INT,
    @MaxRows INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@MaxRows)
        p.PaymentID,
        p.TripID,
        p.Amount AS TotalAmount,
        p.BaseFare,
        p.DistanceFare,
        p.TimeFare,
        p.TipAmount,
        p.SurgeMultiplier,
        p.CurrencyCode,
        p.Status AS PaymentStatus,
        p.CreatedAt,
        p.CompletedAt,
        p.ProviderReference,
        pmt.Code AS PaymentMethod,
        t.StartTime,
        t.EndTime,
        p.DistanceKm,
        p.DurationMinutes,
        du.FullName AS DriverName,
        COALESCE(pickup.StreetAddress, pickup.Description) AS PickupLocation,
        COALESCE(dropoff.StreetAddress, dropoff.Description) AS DropoffLocation
    FROM dbo.Payment p
    JOIN dbo.Trip t ON p.TripID = t.TripID
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Driver d ON t.DriverID = d.DriverID
    JOIN dbo.[User] du ON d.UserID = du.UserID
    JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
    JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
    LEFT JOIN dbo.PaymentMethodType pmt ON p.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE rr.PassengerID = @PassengerID
    ORDER BY p.CreatedAt DESC;
END
GO

-- Operator Financial Report
CREATE OR ALTER PROCEDURE dbo.spOperatorFinancialReport
    @StartDate DATE = NULL,
    @EndDate DATE = NULL,
    @GroupBy NVARCHAR(20) = 'day'
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @StartDate IS NULL SET @StartDate = DATEADD(MONTH, -1, GETDATE());
    IF @EndDate IS NULL SET @EndDate = GETDATE();
    
    SELECT 
        COUNT(*) AS TotalTransactions,
        SUM(p.Amount) AS TotalRevenue,
        SUM(p.ServiceFeeAmount) AS TotalCommission,
        SUM(p.DriverEarnings) AS TotalDriverPayouts,
        SUM(p.TipAmount) AS TotalTips,
        AVG(p.Amount) AS AvgTransactionAmount,
        AVG(p.ServiceFeeRate * 100) AS AvgCommissionRate
    FROM dbo.Payment p
    WHERE p.Status = 'completed'
      AND CAST(p.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate;
    
    SELECT 
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(p.CompletedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, p.CompletedAt), CAST(p.CompletedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(p.CompletedAt, 'yyyy-MM')
            WHEN 'year' THEN CAST(YEAR(p.CompletedAt) AS NVARCHAR(50))
            ELSE CAST(CAST(p.CompletedAt AS DATE) AS NVARCHAR(50))
        END AS Period,
        COUNT(*) AS Transactions,
        SUM(p.Amount) AS Revenue,
        SUM(p.ServiceFeeAmount) AS Commission,
        SUM(p.DriverEarnings) AS DriverPayouts,
        SUM(p.TipAmount) AS Tips
    FROM dbo.Payment p
    WHERE p.Status = 'completed'
      AND CAST(p.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY 
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(p.CompletedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1 - DATEPART(WEEKDAY, p.CompletedAt), CAST(p.CompletedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(p.CompletedAt, 'yyyy-MM')
            WHEN 'year' THEN CAST(YEAR(p.CompletedAt) AS NVARCHAR(50))
            ELSE CAST(CAST(p.CompletedAt AS DATE) AS NVARCHAR(50))
        END
    ORDER BY Period DESC;
END
GO

-- Operator Driver Earnings Report
CREATE OR ALTER PROCEDURE dbo.spOperatorDriverEarningsReport
    @StartDate DATE = NULL,
    @EndDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @StartDate IS NULL SET @StartDate = DATEADD(MONTH, -1, GETDATE());
    IF @EndDate IS NULL SET @EndDate = GETDATE();
    
    SELECT 
        d.DriverID,
        u.FullName AS DriverName,
        u.Email AS DriverEmail,
        COUNT(p.PaymentID) AS CompletedTrips,
        SUM(p.Amount) AS GrossRevenue,
        SUM(p.ServiceFeeAmount) AS ServiceFeesPaid,
        SUM(p.DriverEarnings) AS NetEarnings,
        SUM(p.TipAmount) AS TipsReceived,
        AVG(p.Amount) AS AvgFarePerTrip,
        SUM(p.DistanceKm) AS TotalDistanceKm
    FROM dbo.Driver d
    JOIN dbo.[User] u ON d.UserID = u.UserID
    LEFT JOIN dbo.Trip t ON d.DriverID = t.DriverID
    LEFT JOIN dbo.Payment p ON t.TripID = p.TripID 
        AND p.Status = 'completed'
        AND CAST(p.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY d.DriverID, u.FullName, u.Email
    ORDER BY SUM(p.DriverEarnings) DESC;
END
GO

-- Update Pricing Config
CREATE OR ALTER PROCEDURE dbo.spUpdatePricingConfig
    @ServiceTypeID INT,
    @BaseFare DECIMAL(10,2) = NULL,
    @PricePerKm DECIMAL(10,2) = NULL,
    @PricePerMinute DECIMAL(10,2) = NULL,
    @MinimumFare DECIMAL(10,2) = NULL,
    @ServiceFeeRate DECIMAL(5,4) = NULL,
    @SurgeMultiplier DECIMAL(4,2) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF EXISTS (SELECT 1 FROM dbo.PricingConfig WHERE ServiceTypeID = @ServiceTypeID)
    BEGIN
        UPDATE dbo.PricingConfig
        SET BaseFare = ISNULL(@BaseFare, BaseFare),
            PricePerKm = ISNULL(@PricePerKm, PricePerKm),
            PricePerMinute = ISNULL(@PricePerMinute, PricePerMinute),
            MinimumFare = ISNULL(@MinimumFare, MinimumFare),
            ServiceFeeRate = ISNULL(@ServiceFeeRate, ServiceFeeRate),
            SurgeMultiplier = ISNULL(@SurgeMultiplier, SurgeMultiplier),
            UpdatedAt = SYSDATETIME()
        WHERE ServiceTypeID = @ServiceTypeID;
    END
    ELSE
    BEGIN
        INSERT INTO dbo.PricingConfig (
            ServiceTypeID, BaseFare, PricePerKm, PricePerMinute, 
            MinimumFare, ServiceFeeRate, SurgeMultiplier
        )
        VALUES (
            @ServiceTypeID, 
            ISNULL(@BaseFare, 3.00),
            ISNULL(@PricePerKm, 1.20),
            ISNULL(@PricePerMinute, 0.20),
            ISNULL(@MinimumFare, 5.00),
            ISNULL(@ServiceFeeRate, 0.0000),
            ISNULL(@SurgeMultiplier, 1.00)
        );
    END
    
    SELECT 'OK' AS Result;
END
GO

-- Driver Set Minimum Pricing
CREATE OR ALTER PROCEDURE dbo.spDriverSetMinimumPricing
    @DriverID INT,
    @MinimumFare DECIMAL(10,2),
    @ServiceTypeID INT = NULL,
    @VehicleID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF NOT EXISTS (SELECT 1 FROM dbo.Driver WHERE DriverID = @DriverID)
    BEGIN
        RAISERROR('Driver not found.', 16, 1);
        RETURN;
    END
    
    IF EXISTS (
        SELECT 1 FROM dbo.DriverPricing 
        WHERE DriverID = @DriverID 
          AND ISNULL(ServiceTypeID, 0) = ISNULL(@ServiceTypeID, 0)
          AND ISNULL(VehicleID, 0) = ISNULL(@VehicleID, 0)
    )
    BEGIN
        UPDATE dbo.DriverPricing
        SET MinimumFare = @MinimumFare,
            UpdatedAt = SYSDATETIME()
        WHERE DriverID = @DriverID 
          AND ISNULL(ServiceTypeID, 0) = ISNULL(@ServiceTypeID, 0)
          AND ISNULL(VehicleID, 0) = ISNULL(@VehicleID, 0);
    END
    ELSE
    BEGIN
        INSERT INTO dbo.DriverPricing (DriverID, VehicleID, ServiceTypeID, MinimumFare)
        VALUES (@DriverID, @VehicleID, @ServiceTypeID, @MinimumFare);
    END
    
    SELECT 'OK' AS Result;
END
GO

-- Get Driver Pricing Settings
CREATE OR ALTER PROCEDURE dbo.spGetDriverPricing
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        dp.DriverPricingID,
        dp.DriverID,
        dp.VehicleID,
        dp.ServiceTypeID,
        dp.MinimumFare,
        dp.PricePerKmOverride,
        dp.IsActive,
        v.PlateNo AS VehiclePlate,
        st.Name AS ServiceTypeName
    FROM dbo.DriverPricing dp
    LEFT JOIN dbo.Vehicle v ON dp.VehicleID = v.VehicleID
    LEFT JOIN dbo.ServiceType st ON dp.ServiceTypeID = st.ServiceTypeID
    WHERE dp.DriverID = @DriverID AND dp.IsActive = 1
    ORDER BY dp.ServiceTypeID, dp.VehicleID;
END
GO

-- Get Payment Details with Full Breakdown
CREATE OR ALTER PROCEDURE dbo.spGetPaymentDetails
    @PaymentID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        p.PaymentID,
        p.TripID,
        p.Amount AS TotalAmount,
        p.BaseFare,
        p.DistanceFare,
        p.TimeFare,
        p.TipAmount,
        p.SurgeMultiplier,
        p.ServiceFeeRate,
        p.ServiceFeeAmount,
        p.DriverEarnings,
        p.DistanceKm,
        p.DurationMinutes,
        p.CurrencyCode,
        p.Status AS PaymentStatus,
        p.CreatedAt,
        p.CompletedAt,
        p.ProviderReference,
        pmt.Code AS PaymentMethodCode,
        pmt.Description AS PaymentMethodDescription,
        t.TripID,
        t.Status AS TripStatus,
        t.StartTime,
        t.EndTime,
        rr.RideRequestID,
        rr.RequestedAt,
        st.Name AS ServiceTypeName,
        pu.FullName AS PassengerName,
        pu.Email AS PassengerEmail,
        du.FullName AS DriverName,
        du.Email AS DriverEmail,
        COALESCE(pickup.StreetAddress, pickup.Description) AS PickupLocation,
        pickup.LatDegrees AS PickupLat,
        pickup.LonDegrees AS PickupLng,
        COALESCE(dropoff.StreetAddress, dropoff.Description) AS DropoffLocation,
        dropoff.LatDegrees AS DropoffLat,
        dropoff.LonDegrees AS DropoffLng
    FROM dbo.Payment p
    JOIN dbo.Trip t ON p.TripID = t.TripID
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    JOIN dbo.Passenger pa ON rr.PassengerID = pa.PassengerID
    JOIN dbo.[User] pu ON pa.UserID = pu.UserID
    JOIN dbo.Driver d ON t.DriverID = d.DriverID
    JOIN dbo.[User] du ON d.UserID = du.UserID
    JOIN dbo.Location pickup ON rr.PickupLocationID = pickup.LocationID
    JOIN dbo.Location dropoff ON rr.DropoffLocationID = dropoff.LocationID
    LEFT JOIN dbo.PaymentMethodType pmt ON p.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE p.PaymentID = @PaymentID;
END
GO

-- Get Trip Payment
CREATE OR ALTER PROCEDURE dbo.spGetTripPayment
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 
        p.PaymentID, 
        p.Amount, 
        p.CurrencyCode, 
        p.Status,
        p.CreatedAt, 
        p.CompletedAt,
        pmt.Code AS PaymentMethodName,
        p.PaymentMethodTypeID,
        p.ProviderReference,
        p.BaseFare,
        p.DistanceFare,
        p.TimeFare,
        p.SurgeMultiplier,
        p.ServiceFeeRate,
        p.ServiceFeeAmount,
        p.DriverEarnings,
        p.TipAmount,
        p.DistanceKm,
        p.DurationMinutes
    FROM dbo.Payment p
    LEFT JOIN dbo.PaymentMethodType pmt ON p.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE p.TripID = @TripID
    ORDER BY p.CreatedAt DESC;
END
GO

-- Calculate Total Fare Function
CREATE OR ALTER FUNCTION dbo.fnCalculateTotalFare(
    @ServiceTypeID INT,
    @DistanceKm DECIMAL(10,3),
    @DurationMin INT
)
RETURNS DECIMAL(10,2)
AS
BEGIN
    DECLARE @BaseFare DECIMAL(10,2);
    DECLARE @PricePerKm DECIMAL(10,2);
    DECLARE @PricePerMinute DECIMAL(10,2);
    DECLARE @MinimumFare DECIMAL(10,2);
    
    SELECT 
        @BaseFare = pc.BaseFare,
        @PricePerKm = pc.PricePerKm,
        @PricePerMinute = pc.PricePerMinute,
        @MinimumFare = pc.MinimumFare
    FROM dbo.PricingConfig pc
    WHERE pc.ServiceTypeID = @ServiceTypeID AND pc.IsActive = 1;
    
    IF @BaseFare IS NULL
    BEGIN
        SET @BaseFare = 3.00;
        SET @PricePerKm = 1.20;
        SET @PricePerMinute = 0.20;
        SET @MinimumFare = 5.00;
    END
    
    DECLARE @Total DECIMAL(10,2) = @BaseFare + (@DistanceKm * @PricePerKm) + (@DurationMin * @PricePerMinute);
    
    IF @Total < @MinimumFare
        SET @Total = @MinimumFare;
    
    RETURN ROUND(@Total, 2);
END
GO

-- Calculate Estimated Driver Payment Function
-- No platform fee: driver earns 100% of the fare
CREATE OR ALTER FUNCTION dbo.fnCalculateEstimatedDriverPayment(
    @ServiceTypeID INT,
    @DistanceKm DECIMAL(10,3),
    @DurationMin INT
)
RETURNS DECIMAL(10,2)
AS
BEGIN
    -- No platform fee - driver gets 100% of the fare
    RETURN dbo.fnCalculateTotalFare(@ServiceTypeID, @DistanceKm, @DurationMin);
END
GO

-- ============================================================================
-- ============================================================================
-- GEOFENCE BRIDGE SYSTEM FUNCTIONS AND STORED PROCEDURES
-- (Consolidated from geofence_bridge_system.sql)
-- ============================================================================
-- ============================================================================

-- Check if Location is in Geofence
-- OPTIMIZED: Delegates to fnIsPointInGeofence instead of duplicating cursor logic
-- Uses the set-based ray-casting implementation in fnIsPointInGeofence
CREATE OR ALTER FUNCTION dbo.fnIsLocationInGeofence(
    @LocationID INT,
    @GeofenceID INT
)
RETURNS BIT
AS
BEGIN
    DECLARE @Lat DECIMAL(9,6), @Lon DECIMAL(9,6);
    
    -- Get coordinates from Location table
    SELECT @Lat = LatDegrees, @Lon = LonDegrees
    FROM dbo.Location
    WHERE LocationID = @LocationID;
    
    -- If location not found or has no coordinates, return 0
    IF @Lat IS NULL OR @Lon IS NULL
        RETURN 0;
    
    -- Delegate to the already-optimized fnIsPointInGeofence
    RETURN dbo.fnIsPointInGeofence(@Lat, @Lon, @GeofenceID);
END
GO

-- Get Location Geofence
CREATE OR ALTER FUNCTION dbo.fnGetLocationGeofence(
    @LocationID INT
)
RETURNS INT
AS
BEGIN
    DECLARE @GeofenceID INT = NULL;
    
    SELECT TOP 1 @GeofenceID = g.GeofenceID
    FROM dbo.Geofence g
    WHERE g.IsActive = 1
      AND dbo.fnIsLocationInGeofence(@LocationID, g.GeofenceID) = 1
    ORDER BY g.GeofenceID;
    
    RETURN @GeofenceID;
END
GO

-- Find Geofence Paths
CREATE OR ALTER PROCEDURE dbo.spFindGeofencePaths
    @FromLocationID INT,
    @ToLocationID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @FromGeofenceID INT, @ToGeofenceID INT;
    
    SET @FromGeofenceID = dbo.fnGetLocationGeofence(@FromLocationID);
    SET @ToGeofenceID = dbo.fnGetLocationGeofence(@ToLocationID);
    
    IF @FromGeofenceID IS NULL OR @ToGeofenceID IS NULL
    BEGIN
        SELECT 
            1 as PathID,
            1 as PathLength,
            @FromGeofenceID as FromGeofenceID,
            @ToGeofenceID as ToGeofenceID,
            NULL as BridgeID,
            CAST(@FromGeofenceID AS NVARCHAR(MAX)) as GeofencePath,
            CAST('' AS NVARCHAR(MAX)) as BridgePath;
        RETURN;
    END
    
    IF @FromGeofenceID = @ToGeofenceID
    BEGIN
        SELECT 
            1 as PathID,
            1 as PathLength,
            @FromGeofenceID as FromGeofenceID,
            @ToGeofenceID as ToGeofenceID,
            NULL as BridgeID,
            CAST(@FromGeofenceID AS NVARCHAR(MAX)) as GeofencePath,
            CAST('' AS NVARCHAR(MAX)) as BridgePath;
        RETURN;
    END;
    
    WITH GeofencePaths AS (
        SELECT 
            @FromGeofenceID as CurrentGeofenceID,
            @ToGeofenceID as TargetGeofenceID,
            gb.BridgeID,
            CASE 
                WHEN gb.Geofence1ID = @FromGeofenceID THEN gb.Geofence2ID
                ELSE gb.Geofence1ID
            END as NextGeofenceID,
            1 as PathLength,
            CAST(@FromGeofenceID AS NVARCHAR(MAX)) as GeofencePath,
            CAST(CAST(gb.BridgeID AS NVARCHAR(10)) AS NVARCHAR(MAX)) as BridgePath
        FROM dbo.GeofenceBridge gb
        WHERE gb.IsActive = 1
          AND (@FromGeofenceID IN (gb.Geofence1ID, gb.Geofence2ID))
          
        UNION ALL
        
        SELECT 
            gp.NextGeofenceID as CurrentGeofenceID,
            gp.TargetGeofenceID,
            gb.BridgeID,
            CASE 
                WHEN gb.Geofence1ID = gp.NextGeofenceID THEN gb.Geofence2ID
                ELSE gb.Geofence1ID
            END as NextGeofenceID,
            gp.PathLength + 1,
            gp.GeofencePath + ',' + CAST(gp.NextGeofenceID AS NVARCHAR(MAX)),
            gp.BridgePath + ',' + CAST(gb.BridgeID AS NVARCHAR(10))
        FROM GeofencePaths gp
        INNER JOIN dbo.GeofenceBridge gb ON gb.IsActive = 1
            AND gp.NextGeofenceID IN (gb.Geofence1ID, gb.Geofence2ID)
        WHERE gp.PathLength < 10
          AND gp.NextGeofenceID <> gp.TargetGeofenceID
          AND ',' + gp.GeofencePath + ',' NOT LIKE '%,' + CAST(gp.NextGeofenceID AS NVARCHAR(10)) + ',%'
    )
    SELECT 
        ROW_NUMBER() OVER (ORDER BY PathLength, BridgePath) as PathID,
        PathLength,
        @FromGeofenceID as FromGeofenceID,
        @ToGeofenceID as ToGeofenceID,
        BridgeID as LastBridgeID,
        GeofencePath + ',' + CAST(NextGeofenceID AS NVARCHAR(MAX)) as GeofencePath,
        BridgePath as BridgePath
    FROM GeofencePaths
    WHERE NextGeofenceID = @ToGeofenceID
    ORDER BY PathLength, BridgePath;
END
GO

-- Calculate segments for a multi-vehicle journey
CREATE OR ALTER PROCEDURE dbo.spCalculateRideSegments
    @RideRequestID INT,
    @FromLocationID INT,
    @ToLocationID INT,
    @PathID INT = 1  -- Which path to use (default to shortest)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get service type pricing info from the ride request
    DECLARE @ServiceTypeID INT;
    DECLARE @BaseFare DECIMAL(10,2), @PerKmRate DECIMAL(10,2), @PerMinRate DECIMAL(10,2), @MinFare DECIMAL(10,2);
    
    SELECT @ServiceTypeID = rr.ServiceTypeID
    FROM dbo.RideRequest rr
    WHERE rr.RideRequestID = @RideRequestID;
    
    -- Default pricing based on service type (matching JS client-side logic)
    -- ServiceTypeID 1=Standard, 2=Luxury, 3=Light Cargo, 4=Heavy Cargo, 5=Multi-Stop
    SELECT @BaseFare = CASE @ServiceTypeID
        WHEN 1 THEN 3.00
        WHEN 2 THEN 8.00
        WHEN 3 THEN 5.00
        WHEN 4 THEN 10.00
        WHEN 5 THEN 4.00
        ELSE 3.00
    END,
    @PerKmRate = CASE @ServiceTypeID
        WHEN 1 THEN 1.20
        WHEN 2 THEN 2.50
        WHEN 3 THEN 1.80
        WHEN 4 THEN 2.20
        WHEN 5 THEN 1.40
        ELSE 1.20
    END,
    @PerMinRate = CASE @ServiceTypeID
        WHEN 1 THEN 0.20
        WHEN 2 THEN 0.40
        WHEN 3 THEN 0.25
        WHEN 4 THEN 0.30
        WHEN 5 THEN 0.22
        ELSE 0.20
    END,
    @MinFare = CASE @ServiceTypeID
        WHEN 1 THEN 5.00
        WHEN 2 THEN 15.00
        WHEN 3 THEN 10.00
        WHEN 4 THEN 20.00
        WHEN 5 THEN 8.00
        ELSE 5.00
    END;
    
    -- Get the path to use
    DECLARE @PathTable TABLE (
        PathID INT,
        PathLength INT,
        FromGeofenceID INT,
        ToGeofenceID INT,
        LastBridgeID INT,
        GeofencePath NVARCHAR(MAX),
        BridgePath NVARCHAR(MAX)
    );
    
    INSERT INTO @PathTable
    EXEC dbo.spFindGeofencePaths @FromLocationID, @ToLocationID;
    
    DECLARE @GeofencePath NVARCHAR(MAX), @BridgePath NVARCHAR(MAX);
    DECLARE @PathLength INT;
    
    SELECT TOP 1 
        @GeofencePath = GeofencePath,
        @BridgePath = BridgePath,
        @PathLength = PathLength
    FROM @PathTable
    WHERE PathID = @PathID;
    
    -- If no path found or no bridges needed, create single segment with calculated fare
    -- BridgePath is empty when pickup and dropoff are in the same geofence
    IF @GeofencePath IS NULL OR @BridgePath IS NULL OR @BridgePath = ''
    BEGIN
        -- Calculate distance and fare for single segment
        DECLARE @SingleDistanceKm DECIMAL(8,2), @SingleDurationMin INT, @SingleFare DECIMAL(10,2);
        DECLARE @FromLat DECIMAL(9,6), @FromLon DECIMAL(9,6), @ToLat DECIMAL(9,6), @ToLon DECIMAL(9,6);
        
        SELECT @FromLat = LatDegrees, @FromLon = LonDegrees FROM dbo.Location WHERE LocationID = @FromLocationID;
        SELECT @ToLat = LatDegrees, @ToLon = LonDegrees FROM dbo.Location WHERE LocationID = @ToLocationID;
        
        -- Calculate distance using Haversine formula with 1.3x road factor
        SET @SingleDistanceKm = ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(@ToLat - @FromLat) / 2), 2) +
                    COS(RADIANS(@FromLat)) * COS(RADIANS(@ToLat)) *
                    POWER(SIN(RADIANS(@ToLon - @FromLon) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(@ToLat - @FromLat) / 2), 2) +
                    COS(RADIANS(@FromLat)) * COS(RADIANS(@ToLat)) *
                    POWER(SIN(RADIANS(@ToLon - @FromLon) / 2), 2)
                ))
            ) * 1.3, 2);
        
        -- Estimate duration at ~40 km/h average
        SET @SingleDurationMin = CEILING((@SingleDistanceKm / 40.0) * 60);
        IF @SingleDurationMin < 2 SET @SingleDurationMin = 2;
        
        -- Calculate fare
        SET @SingleFare = @BaseFare + (@SingleDistanceKm * @PerKmRate) + (@SingleDurationMin * @PerMinRate);
        IF @SingleFare < @MinFare SET @SingleFare = @MinFare;
        
        INSERT INTO dbo.RideSegment (
            RideRequestID, SegmentOrder, FromLocationID, ToLocationID,
            FromBridgeID, ToBridgeID, GeofenceID,
            EstimatedDistanceKm, EstimatedDurationMin, EstimatedFare
        )
        VALUES (
            @RideRequestID, 1, @FromLocationID, @ToLocationID,
            NULL, NULL, dbo.fnGetLocationGeofence(@FromLocationID),
            @SingleDistanceKm, @SingleDurationMin, @SingleFare
        );
        
        SELECT * FROM dbo.RideSegment WHERE RideRequestID = @RideRequestID;
        RETURN;
    END
    
    -- Parse geofences and bridges to create segments
    DECLARE @SegmentOrder INT = 1;
    DECLARE @CurrentFromLocationID INT = @FromLocationID;
    DECLARE @CurrentToLocationID INT;
    DECLARE @CurrentGeofenceID INT;
    DECLARE @BridgeIDs TABLE (SegmentOrder INT, BridgeID INT);
    
    -- Parse bridge IDs
    DECLARE @BridgeID INT, @BridgePos INT = 1, @BridgeCount INT = 0;
    WHILE @BridgePos <= LEN(@BridgePath)
    BEGIN
        DECLARE @CommaPos INT = CHARINDEX(',', @BridgePath, @BridgePos);
        IF @CommaPos = 0 SET @CommaPos = LEN(@BridgePath) + 1;
        
        SET @BridgeID = CAST(SUBSTRING(@BridgePath, @BridgePos, @CommaPos - @BridgePos) AS INT);
        SET @BridgeCount = @BridgeCount + 1;
        
        INSERT INTO @BridgeIDs VALUES (@BridgeCount, @BridgeID);
        SET @BridgePos = @CommaPos + 1;
    END
    
    -- Variables for segment fare calculation
    DECLARE @SegDistanceKm DECIMAL(8,2), @SegDurationMin INT, @SegFare DECIMAL(10,2);
    DECLARE @SegFromLat DECIMAL(9,6), @SegFromLon DECIMAL(9,6), @SegToLat DECIMAL(9,6), @SegToLon DECIMAL(9,6);
    
    -- Create segments for each hop
    DECLARE @CurrentBridgeID INT, @PreviousBridgeID INT = NULL;
    DECLARE @PreviousGeofence1ID INT = NULL, @PreviousGeofence2ID INT = NULL;
    DECLARE bridge_cursor CURSOR FOR 
        SELECT BridgeID FROM @BridgeIDs ORDER BY SegmentOrder;
    
    OPEN bridge_cursor;
    FETCH NEXT FROM bridge_cursor INTO @CurrentBridgeID;
    
    -- For the first segment, get geofence from the pickup location
    SET @CurrentGeofenceID = dbo.fnGetLocationGeofence(@FromLocationID);
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Get bridge location and geofences
        DECLARE @BridgeGeofence1ID INT, @BridgeGeofence2ID INT;
        SELECT @CurrentToLocationID = LocationID, 
               @BridgeGeofence1ID = Geofence1ID,
               @BridgeGeofence2ID = Geofence2ID
        FROM dbo.GeofenceBridge
        WHERE BridgeID = @CurrentBridgeID;
        
        -- For segments after the first, determine which geofence we're entering
        -- The segment's geofence is where we're coming FROM (the geofence we're operating in)
        IF @SegmentOrder > 1
        BEGIN
            -- We came from the previous bridge. The previous bridge connects two geofences.
            -- We need to find which geofence the current segment is in.
            -- The current bridge also connects to one of those geofences - that's where we are.
            IF @PreviousGeofence1ID = @BridgeGeofence1ID OR @PreviousGeofence1ID = @BridgeGeofence2ID
                SET @CurrentGeofenceID = @PreviousGeofence1ID;
            ELSE IF @PreviousGeofence2ID = @BridgeGeofence1ID OR @PreviousGeofence2ID = @BridgeGeofence2ID
                SET @CurrentGeofenceID = @PreviousGeofence2ID;
        END
        
        -- Calculate segment distance and fare
        SELECT @SegFromLat = LatDegrees, @SegFromLon = LonDegrees FROM dbo.Location WHERE LocationID = @CurrentFromLocationID;
        SELECT @SegToLat = LatDegrees, @SegToLon = LonDegrees FROM dbo.Location WHERE LocationID = @CurrentToLocationID;
        
        SET @SegDistanceKm = ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(@SegToLat - @SegFromLat) / 2), 2) +
                    COS(RADIANS(@SegFromLat)) * COS(RADIANS(@SegToLat)) *
                    POWER(SIN(RADIANS(@SegToLon - @SegFromLon) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(@SegToLat - @SegFromLat) / 2), 2) +
                    COS(RADIANS(@SegFromLat)) * COS(RADIANS(@SegToLat)) *
                    POWER(SIN(RADIANS(@SegToLon - @SegFromLon) / 2), 2)
                ))
            ) * 1.3, 2);
        
        SET @SegDurationMin = CEILING((@SegDistanceKm / 40.0) * 60);
        IF @SegDurationMin < 2 SET @SegDurationMin = 2;
        
        SET @SegFare = @BaseFare + (@SegDistanceKm * @PerKmRate) + (@SegDurationMin * @PerMinRate);
        IF @SegFare < @MinFare SET @SegFare = @MinFare;
        
        -- Create segment with calculated values
        INSERT INTO dbo.RideSegment (
            RideRequestID, SegmentOrder, FromLocationID, ToLocationID,
            FromBridgeID, ToBridgeID, GeofenceID,
            EstimatedDistanceKm, EstimatedDurationMin, EstimatedFare
        )
        VALUES (
            @RideRequestID, @SegmentOrder, @CurrentFromLocationID, @CurrentToLocationID,
            @PreviousBridgeID,  -- Previous bridge (NULL for first segment)
            @CurrentBridgeID,   -- Current bridge (dropoff point)
            @CurrentGeofenceID,
            @SegDistanceKm, @SegDurationMin, @SegFare
        );
        
        SET @SegmentOrder = @SegmentOrder + 1;
        SET @CurrentFromLocationID = @CurrentToLocationID;
        SET @PreviousBridgeID = @CurrentBridgeID;
        SET @PreviousGeofence1ID = @BridgeGeofence1ID;
        SET @PreviousGeofence2ID = @BridgeGeofence2ID;
        
        FETCH NEXT FROM bridge_cursor INTO @CurrentBridgeID;
    END
    
    CLOSE bridge_cursor;
    DEALLOCATE bridge_cursor;
    
    -- Determine the geofence for the final segment
    -- It should be the "other" geofence from the last bridge we crossed
    DECLARE @FinalGeofenceID INT;
    IF @PreviousGeofence1ID IS NOT NULL AND @PreviousGeofence2ID IS NOT NULL
    BEGIN
        -- We're in the geofence that's NOT the one we just came from
        -- The previous segment's geofence was @CurrentGeofenceID (which was updated in the loop)
        -- So the final segment's geofence is the OTHER one at the bridge
        SET @FinalGeofenceID = CASE 
            WHEN @CurrentGeofenceID = @PreviousGeofence1ID THEN @PreviousGeofence2ID
            WHEN @CurrentGeofenceID = @PreviousGeofence2ID THEN @PreviousGeofence1ID
            ELSE dbo.fnGetLocationGeofence(@ToLocationID)
        END;
    END
    ELSE
    BEGIN
        -- Fallback to destination-based geofence
        SET @FinalGeofenceID = dbo.fnGetLocationGeofence(@ToLocationID);
    END
    
    -- Create final segment to destination with calculated fare
    SELECT @SegFromLat = LatDegrees, @SegFromLon = LonDegrees FROM dbo.Location WHERE LocationID = @CurrentFromLocationID;
    SELECT @SegToLat = LatDegrees, @SegToLon = LonDegrees FROM dbo.Location WHERE LocationID = @ToLocationID;
    
    SET @SegDistanceKm = ROUND(
        6371 * 2 * ATN2(
            SQRT(
                POWER(SIN(RADIANS(@SegToLat - @SegFromLat) / 2), 2) +
                COS(RADIANS(@SegFromLat)) * COS(RADIANS(@SegToLat)) *
                POWER(SIN(RADIANS(@SegToLon - @SegFromLon) / 2), 2)
            ),
            SQRT(1 - (
                POWER(SIN(RADIANS(@SegToLat - @SegFromLat) / 2), 2) +
                COS(RADIANS(@SegFromLat)) * COS(RADIANS(@SegToLat)) *
                POWER(SIN(RADIANS(@SegToLon - @SegFromLon) / 2), 2)
            ))
        ) * 1.3, 2);
    
    SET @SegDurationMin = CEILING((@SegDistanceKm / 40.0) * 60);
    IF @SegDurationMin < 2 SET @SegDurationMin = 2;
    
    SET @SegFare = @BaseFare + (@SegDistanceKm * @PerKmRate) + (@SegDurationMin * @PerMinRate);
    IF @SegFare < @MinFare SET @SegFare = @MinFare;
    
    INSERT INTO dbo.RideSegment (
        RideRequestID, SegmentOrder, FromLocationID, ToLocationID,
        FromBridgeID, ToBridgeID, GeofenceID,
        EstimatedDistanceKm, EstimatedDurationMin, EstimatedFare
    )
    VALUES (
        @RideRequestID, @SegmentOrder, @CurrentFromLocationID, @ToLocationID,
        @PreviousBridgeID, NULL, @FinalGeofenceID,
        @SegDistanceKm, @SegDurationMin, @SegFare
    );
    
    -- Return all segments
    SELECT 
        rs.*,
        fromLoc.Description as FromLocationName,
        toLoc.Description as ToLocationName,
        fromBridge.BridgeName as FromBridgeName,
        toBridge.BridgeName as ToBridgeName,
        g.Name as GeofenceName
    FROM dbo.RideSegment rs
    LEFT JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    LEFT JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    LEFT JOIN dbo.GeofenceBridge fromBridge ON rs.FromBridgeID = fromBridge.BridgeID
    LEFT JOIN dbo.GeofenceBridge toBridge ON rs.ToBridgeID = toBridge.BridgeID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    WHERE rs.RideRequestID = @RideRequestID
    ORDER BY rs.SegmentOrder;
END
GO

-- Update Segment Pricing with OSRM route data
-- Called after segments are created to update with actual road distances from client
CREATE OR ALTER PROCEDURE dbo.spUpdateSegmentPricing
    @SegmentID INT,
    @DistanceKm DECIMAL(8,2),
    @DurationMin INT,
    @EstimatedFare DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.RideSegment
    SET EstimatedDistanceKm = @DistanceKm,
        EstimatedDurationMin = @DurationMin,
        EstimatedFare = @EstimatedFare
    WHERE SegmentID = @SegmentID;
    
    SELECT 1 AS Success;
END
GO

-- Update segment with OSRM crossing point coordinates
-- This creates Location records for the actual OSRM route crossing points
-- and updates the segment's From/To to use them instead of the fixed bridge locations
CREATE OR ALTER PROCEDURE dbo.spUpdateSegmentCrossingPoints
    @SegmentID INT,
    @FromLat DECIMAL(9,6),
    @FromLng DECIMAL(9,6),
    @ToLat DECIMAL(9,6),
    @ToLng DECIMAL(9,6),
    @DistanceKm DECIMAL(8,2),
    @DurationMin INT,
    @EstimatedFare DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @SegmentOrder INT;
    DECLARE @RideRequestID INT;
    DECLARE @CurrentFromLocationID INT;
    DECLARE @CurrentToLocationID INT;
    DECLARE @NewFromLocationID INT;
    DECLARE @NewToLocationID INT;
    DECLARE @TotalSegments INT;
    
    -- Get segment info
    SELECT 
        @SegmentOrder = SegmentOrder,
        @RideRequestID = RideRequestID,
        @CurrentFromLocationID = FromLocationID,
        @CurrentToLocationID = ToLocationID
    FROM dbo.RideSegment
    WHERE SegmentID = @SegmentID;
    
    IF @RideRequestID IS NULL
    BEGIN
        SELECT 0 AS Success, 'Segment not found' AS Message;
        RETURN;
    END
    
    -- Get total segments for this ride
    SELECT @TotalSegments = COUNT(*) 
    FROM dbo.RideSegment 
    WHERE RideRequestID = @RideRequestID;
    
    -- For first segment: Keep the original pickup location (don't change From)
    -- For middle segments: Create new location for the crossing point
    -- For last segment: Keep the original dropoff location (don't change To)
    
    -- Handle FromLocation
    IF @SegmentOrder = 1
    BEGIN
        -- First segment: Use original pickup location
        SET @NewFromLocationID = @CurrentFromLocationID;
    END
    ELSE
    BEGIN
        -- Middle/later segments: Create location for the OSRM crossing point
        INSERT INTO dbo.Location (Description, LatDegrees, LonDegrees, StreetAddress)
        VALUES (
            'OSRM Transfer Point (Segment ' + CAST(@SegmentOrder AS VARCHAR) + ' start)',
            @FromLat,
            @FromLng,
            'Auto-generated crossing point'
        );
        SET @NewFromLocationID = SCOPE_IDENTITY();
    END
    
    -- Handle ToLocation
    IF @SegmentOrder = @TotalSegments
    BEGIN
        -- Last segment: Use original dropoff location
        SET @NewToLocationID = @CurrentToLocationID;
    END
    ELSE
    BEGIN
        -- First/middle segments: Create location for the OSRM crossing point
        INSERT INTO dbo.Location (Description, LatDegrees, LonDegrees, StreetAddress)
        VALUES (
            'OSRM Transfer Point (Segment ' + CAST(@SegmentOrder AS VARCHAR) + ' end)',
            @ToLat,
            @ToLng,
            'Auto-generated crossing point'
        );
        SET @NewToLocationID = SCOPE_IDENTITY();
    END
    
    -- Determine the correct geofence for this segment based on the new From location
    DECLARE @NewGeofenceID INT;
    DECLARE @ExistingGeofenceID INT;
    
    -- First, get the existing geofence (which may have been set by a previous segment's processing)
    SELECT @ExistingGeofenceID = GeofenceID FROM dbo.RideSegment WHERE SegmentID = @SegmentID;
    
    -- For segments after the first, prefer keeping the existing geofence if it was already set
    -- (because the previous segment's processing would have set it correctly based on bridge crossings)
    IF @SegmentOrder > 1 AND @ExistingGeofenceID IS NOT NULL
    BEGIN
        SET @NewGeofenceID = @ExistingGeofenceID;
    END
    ELSE
    BEGIN
        -- Try to determine geofence from the location
        SET @NewGeofenceID = dbo.fnGetLocationGeofence(@NewFromLocationID);
        
        -- If fnGetLocationGeofence returns NULL (point not strictly inside any geofence),
        -- try to find the closest geofence or use existing geofence
        IF @NewGeofenceID IS NULL
        BEGIN
            -- Try to find the geofence by checking which geofence the crossing point is closest to
            -- This handles cases where the crossing point is exactly on the boundary
            SELECT TOP 1 @NewGeofenceID = g.GeofenceID
            FROM dbo.Geofence g
            INNER JOIN dbo.GeofenceBridge gb ON (gb.Geofence1ID = g.GeofenceID OR gb.Geofence2ID = g.GeofenceID)
            INNER JOIN dbo.RideSegment rs ON rs.SegmentID = @SegmentID
            WHERE (rs.FromBridgeID = gb.BridgeID OR rs.ToBridgeID = gb.BridgeID)
              -- For segments after first, pick the geofence that's NOT the previous segment's geofence
              AND (@SegmentOrder = 1 OR g.GeofenceID NOT IN (
                  SELECT rs2.GeofenceID FROM dbo.RideSegment rs2 
                  WHERE rs2.RideRequestID = @RideRequestID AND rs2.SegmentOrder = @SegmentOrder - 1
              ))
            ORDER BY g.GeofenceID;
            
            -- If still NULL, keep the existing GeofenceID
            IF @NewGeofenceID IS NULL
            BEGIN
                SET @NewGeofenceID = @ExistingGeofenceID;
            END
        END
    END
    
    -- Update the segment with new locations and correct geofence
    UPDATE dbo.RideSegment
    SET FromLocationID = @NewFromLocationID,
        ToLocationID = @NewToLocationID,
        GeofenceID = @NewGeofenceID,
        EstimatedDistanceKm = @DistanceKm,
        EstimatedDurationMin = @DurationMin,
        EstimatedFare = @EstimatedFare
    WHERE SegmentID = @SegmentID;
    
    -- If this segment's To becomes next segment's From, update that too
    -- Also update the next segment's geofence to be the "other" geofence at the bridge
    IF @SegmentOrder < @TotalSegments
    BEGIN
        DECLARE @NextGeofenceID INT;
        
        -- The next segment's geofence should be the OTHER geofence at the current bridge
        SELECT @NextGeofenceID = CASE 
            WHEN gb.Geofence1ID = @NewGeofenceID THEN gb.Geofence2ID
            ELSE gb.Geofence1ID
        END
        FROM dbo.RideSegment rs
        INNER JOIN dbo.GeofenceBridge gb ON rs.ToBridgeID = gb.BridgeID
        WHERE rs.SegmentID = @SegmentID;
        
        UPDATE dbo.RideSegment
        SET FromLocationID = @NewToLocationID,
            GeofenceID = COALESCE(@NextGeofenceID, GeofenceID)
        WHERE RideRequestID = @RideRequestID
          AND SegmentOrder = @SegmentOrder + 1;
    END
    
    SELECT 
        1 AS Success,
        @NewFromLocationID AS FromLocationID,
        @NewToLocationID AS ToLocationID,
        'Segment updated with OSRM crossing points' AS Message;
END
GO

-- Get Ride Segments
CREATE OR ALTER PROCEDURE dbo.spGetRideSegments
    @RideRequestID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        rs.SegmentID,
        rs.RideRequestID,
        rs.SegmentOrder,
        rs.FromLocationID,
        rs.ToLocationID,
        rs.FromBridgeID,
        rs.ToBridgeID,
        rs.TripID,
        rs.GeofenceID,
        rs.EstimatedDistanceKm,
        rs.EstimatedDurationMin,
        rs.EstimatedFare,
        rs.CreatedAt,
        fromLoc.Description as FromLocationName,
        fromLoc.LatDegrees as FromLat,
        fromLoc.LonDegrees as FromLon,
        toLoc.Description as ToLocationName,
        toLoc.LatDegrees as ToLat,
        toLoc.LonDegrees as ToLon,
        fromBridge.BridgeName as FromBridgeName,
        toBridge.BridgeName as ToBridgeName,
        g.Name as GeofenceName,
        t.TripID as AssignedTripID,
        t.Status as TripStatus,
        d.DriverID,
        d.UserID as DriverUserID,
        u.FullName as DriverName,
        v.PlateNo,
        vt.Name as VehicleType,
        -- Payment info for this segment
        pay.PaymentID,
        pay.Amount as PaymentAmount,
        pay.Status as PaymentStatus,
        pay.CompletedAt as PaymentCompletedAt,
        pay.DriverEarnings as PaymentDriverEarnings,
        pmt.Code as PaymentMethodName
    FROM dbo.RideSegment rs
    LEFT JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    LEFT JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    LEFT JOIN dbo.GeofenceBridge fromBridge ON rs.FromBridgeID = fromBridge.BridgeID
    LEFT JOIN dbo.GeofenceBridge toBridge ON rs.ToBridgeID = toBridge.BridgeID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    LEFT JOIN dbo.Trip t ON rs.TripID = t.TripID
    LEFT JOIN dbo.Driver d ON t.DriverID = d.DriverID
    LEFT JOIN dbo.[User] u ON d.UserID = u.UserID
    LEFT JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
    LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    -- Get the most recent payment for this segment
    OUTER APPLY (
        SELECT TOP 1 *
        FROM dbo.Payment p
        WHERE p.SegmentID = rs.SegmentID
        ORDER BY p.CreatedAt DESC
    ) pay
    LEFT JOIN dbo.PaymentMethodType pmt ON pay.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE rs.RideRequestID = @RideRequestID
    ORDER BY rs.SegmentOrder;
END
GO

-- Get Single Segment Details (for driver segment_detail page)
CREATE OR ALTER PROCEDURE dbo.spGetSegmentDetails
    @SegmentID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        rs.SegmentID,
        rs.RideRequestID,
        rs.SegmentOrder,
        rs.FromLocationID,
        rs.ToLocationID,
        rs.GeofenceID,
        rs.EstimatedDistanceKm,
        rs.EstimatedDurationMin,
        rs.EstimatedFare,
        rs.TripID,
        fromLoc.LatDegrees AS FromLat,
        fromLoc.LonDegrees AS FromLng,
        fromLoc.Description AS FromDescription,
        fromLoc.StreetAddress AS FromAddress,
        toLoc.LatDegrees AS ToLat,
        toLoc.LonDegrees AS ToLng,
        toLoc.Description AS ToDescription,
        toLoc.StreetAddress AS ToAddress,
        g.Name AS GeofenceName,
        rr.PassengerID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        st.Name AS ServiceTypeName,
        (SELECT COUNT(*) FROM dbo.RideSegment WHERE RideRequestID = rs.RideRequestID) AS TotalSegments,
        origPickup.Description AS OriginalPickup,
        origPickup.LatDegrees AS OriginalPickupLat,
        origPickup.LonDegrees AS OriginalPickupLng,
        origDropoff.Description AS OriginalDropoff,
        origDropoff.LatDegrees AS OriginalDropoffLat,
        origDropoff.LonDegrees AS OriginalDropoffLng
    FROM dbo.RideSegment rs
    INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.Location origPickup ON rr.PickupLocationID = origPickup.LocationID
    INNER JOIN dbo.Location origDropoff ON rr.DropoffLocationID = origDropoff.LocationID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    LEFT JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    WHERE rs.SegmentID = @SegmentID;
END
GO

-- ============================================================================
-- ============================================================================
-- TRIP SIMULATION SYSTEM STORED PROCEDURES
-- (Consolidated from trip_simulation_system.sql)
-- ============================================================================
-- ============================================================================

-- Start Trip
CREATE OR ALTER PROCEDURE dbo.spStartTrip
    @TripID INT,
    @DriverID INT,
    @EstimatedDurationSec INT = NULL,
    @RouteGeometry NVARCHAR(MAX) = NULL,
    @SpeedMultiplier DECIMAL(4,2) = 1.0
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @TripDriverID INT;
    
    SELECT 
        @CurrentStatus = Status,
        @TripDriverID = DriverID
    FROM dbo.Trip
    WHERE TripID = @TripID;
    
    IF @TripDriverID IS NULL
    BEGIN
        SELECT 0 AS Success, 'Trip not found.' AS Message;
        RETURN;
    END
    
    IF @TripDriverID != @DriverID
    BEGIN
        SELECT 0 AS Success, 'This trip is not assigned to you.' AS Message;
        RETURN;
    END
    
    IF @CurrentStatus NOT IN ('assigned', 'driver_arrived')
    BEGIN
        SELECT 0 AS Success, 'Trip cannot be started. Current status: ' + @CurrentStatus AS Message;
        RETURN;
    END
    
    UPDATE dbo.Trip
    SET 
        Status = 'in_progress',
        StartTime = SYSDATETIME(),
        TripStartedAt = SYSDATETIME(),
        EstimatedTripDurationSec = @EstimatedDurationSec,
        RouteGeometry = @RouteGeometry,
        TripSimulationSpeedMultiplier = ISNULL(@SpeedMultiplier, 1.0)
    WHERE TripID = @TripID;
    
    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    SELECT RideRequestID, @DriverID, 'in_progress', 'Trip started - passenger picked up'
    FROM dbo.Trip WHERE TripID = @TripID;
    
    SELECT 
        1 AS Success, 
        'Trip started! Passenger picked up.' AS Message,
        @TripID AS TripID,
        SYSDATETIME() AS TripStartedAt;
END
GO

-- Get Simulated Trip Position
-- Uses accumulated time to handle speed changes correctly
-- SEGMENT AWARE: Uses segment From/To locations for segment trips
CREATE OR ALTER PROCEDURE dbo.spGetSimulatedTripPosition
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @TripStatus NVARCHAR(30);
    DECLARE @TripStartedAt DATETIME2;
    DECLARE @EstimatedDurationSec INT;
    DECLARE @SpeedMultiplier DECIMAL(4,2);
    DECLARE @PickupLat DECIMAL(9,6), @PickupLng DECIMAL(9,6);
    DECLARE @DropoffLat DECIMAL(9,6), @DropoffLng DECIMAL(9,6);
    DECLARE @RouteGeometry NVARCHAR(MAX);
    DECLARE @AccumulatedSimulatedSeconds FLOAT;
    DECLARE @LastSpeedChangeAt DATETIME2;
    DECLARE @CurrentTime DATETIME2 = SYSDATETIME();
    DECLARE @SegmentID INT;
    DECLARE @IsSegmentTrip BIT = 0;
    
    -- First check if this trip is linked to a segment
    SELECT @SegmentID = rs.SegmentID
    FROM dbo.RideSegment rs
    WHERE rs.TripID = @TripID;
    
    IF @SegmentID IS NOT NULL
    BEGIN
        SET @IsSegmentTrip = 1;
        
        -- SEGMENT TRIP: Use segment From/To locations (NOT ride request locations)
        SELECT 
            @TripStatus = t.Status,
            @TripStartedAt = t.TripStartedAt,
            @EstimatedDurationSec = COALESCE(t.EstimatedTripDurationSec, rs.EstimatedDurationMin * 60),
            @SpeedMultiplier = ISNULL(t.TripSimulationSpeedMultiplier, 1.0),
            @RouteGeometry = t.RouteGeometry,
            @AccumulatedSimulatedSeconds = ISNULL(t.AccumulatedSimulatedSeconds, 0),
            @LastSpeedChangeAt = t.LastSpeedChangeAt,
            @PickupLat = fromLoc.LatDegrees,
            @PickupLng = fromLoc.LonDegrees,
            @DropoffLat = toLoc.LatDegrees,
            @DropoffLng = toLoc.LonDegrees
        FROM dbo.Trip t
        INNER JOIN dbo.RideSegment rs ON rs.TripID = t.TripID
        INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
        INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
        WHERE t.TripID = @TripID;
    END
    ELSE
    BEGIN
        -- REGULAR TRIP: Use ride request pickup/dropoff locations
        SELECT 
            @TripStatus = t.Status,
            @TripStartedAt = t.TripStartedAt,
            @EstimatedDurationSec = t.EstimatedTripDurationSec,
            @SpeedMultiplier = ISNULL(t.TripSimulationSpeedMultiplier, 1.0),
            @RouteGeometry = t.RouteGeometry,
            @AccumulatedSimulatedSeconds = ISNULL(t.AccumulatedSimulatedSeconds, 0),
            @LastSpeedChangeAt = t.LastSpeedChangeAt,
            @PickupLat = pl.LatDegrees,
            @PickupLng = pl.LonDegrees,
            @DropoffLat = dl.LatDegrees,
            @DropoffLng = dl.LonDegrees
        FROM dbo.Trip t
        JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
        JOIN dbo.Location pl ON rr.PickupLocationID = pl.LocationID
        JOIN dbo.Location dl ON rr.DropoffLocationID = dl.LocationID
        WHERE t.TripID = @TripID;
    END
    
    IF @TripStatus != 'in_progress'
    BEGIN
        SELECT 
            @TripID AS TripID,
            @TripStatus AS TripStatus,
            @PickupLat AS CurrentLat,
            @PickupLng AS CurrentLng,
            @PickupLat AS PickupLat,
            @PickupLng AS PickupLng,
            @DropoffLat AS DropoffLat,
            @DropoffLng AS DropoffLng,
            0.0 AS ProgressPercent,
            0 AS RemainingSeconds,
            0 AS ElapsedSeconds,
            CASE WHEN @TripStatus = 'completed' THEN 1 ELSE 0 END AS HasArrived,
            @SpeedMultiplier AS SpeedMultiplier,
            'trip_not_in_progress' AS SimulationStatus;
        RETURN;
    END
    
    IF @TripStartedAt IS NULL OR @EstimatedDurationSec IS NULL OR @EstimatedDurationSec <= 0
    BEGIN
        SELECT 
            @TripID AS TripID,
            @TripStatus AS TripStatus,
            @PickupLat AS CurrentLat,
            @PickupLng AS CurrentLng,
            @PickupLat AS PickupLat,
            @PickupLng AS PickupLng,
            @DropoffLat AS DropoffLat,
            @DropoffLng AS DropoffLng,
            0.0 AS ProgressPercent,
            0 AS RemainingSeconds,
            0 AS ElapsedSeconds,
            0 AS HasArrived,
            @SpeedMultiplier AS SpeedMultiplier,
            'no_simulation_data' AS SimulationStatus;
        RETURN;
    END
    
    -- Calculate elapsed time using accumulated time for proper speed change handling
    -- If LastSpeedChangeAt is set, calculate time since last speed change at current speed
    -- and add to accumulated time. Otherwise, use simple calculation from trip start.
    DECLARE @RealElapsedSinceSpeedChange FLOAT;
    DECLARE @SimulatedElapsedSeconds FLOAT;
    DECLARE @AdjustedDurationSec FLOAT = CAST(@EstimatedDurationSec AS FLOAT);
    
    IF @LastSpeedChangeAt IS NOT NULL
    BEGIN
        -- Time since last speed change, multiplied by current speed, plus accumulated time
        SET @RealElapsedSinceSpeedChange = DATEDIFF(SECOND, @LastSpeedChangeAt, @CurrentTime);
        SET @SimulatedElapsedSeconds = @AccumulatedSimulatedSeconds + (@RealElapsedSinceSpeedChange * @SpeedMultiplier);
    END
    ELSE
    BEGIN
        -- No speed changes yet, use simple calculation from trip start
        DECLARE @RealElapsedSeconds FLOAT = DATEDIFF(SECOND, @TripStartedAt, @CurrentTime);
        SET @SimulatedElapsedSeconds = @RealElapsedSeconds * @SpeedMultiplier;
    END
    
    DECLARE @Progress FLOAT = @SimulatedElapsedSeconds / @AdjustedDurationSec;
    IF @Progress < 0 SET @Progress = 0;
    IF @Progress > 1 SET @Progress = 1;
    
    DECLARE @EasedProgress FLOAT;
    IF @Progress < 0.5
        SET @EasedProgress = 2 * POWER(@Progress, 2);
    ELSE
        SET @EasedProgress = 1 - POWER(-2 * @Progress + 2, 2) / 2;
    
    DECLARE @CurrentLat DECIMAL(9,6) = @PickupLat + (@DropoffLat - @PickupLat) * @EasedProgress;
    DECLARE @CurrentLng DECIMAL(9,6) = @PickupLng + (@DropoffLng - @PickupLng) * @EasedProgress;
    
    DECLARE @RemainingSeconds INT = CAST((@AdjustedDurationSec - @SimulatedElapsedSeconds) / @SpeedMultiplier AS INT);
    IF @RemainingSeconds < 0 SET @RemainingSeconds = 0;
    
    DECLARE @HasArrived BIT = CASE WHEN @Progress >= 1 THEN 1 ELSE 0 END;
    
    SELECT 
        @TripID AS TripID,
        @TripStatus AS TripStatus,
        ROUND(@CurrentLat, 6) AS CurrentLat,
        ROUND(@CurrentLng, 6) AS CurrentLng,
        @PickupLat AS PickupLat,
        @PickupLng AS PickupLng,
        @DropoffLat AS DropoffLat,
        @DropoffLng AS DropoffLng,
        ROUND(@Progress * 100, 1) AS ProgressPercent,
        @RemainingSeconds AS RemainingSeconds,
        CAST(@SimulatedElapsedSeconds AS INT) AS ElapsedSeconds,
        @HasArrived AS HasArrived,
        @SpeedMultiplier AS SpeedMultiplier,
        @RouteGeometry AS RouteGeometry,
        'active' AS SimulationStatus,
        @IsSegmentTrip AS IsSegmentTrip,
        @SegmentID AS SegmentID;
END
GO

-- Update Trip Simulation Speed
-- When speed changes, we save the accumulated simulated time so far
-- This prevents the car from jumping backwards when speed is reduced
CREATE OR ALTER PROCEDURE dbo.spUpdateTripSimulationSpeed
    @TripID INT,
    @SpeedMultiplier DECIMAL(4,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate speed multiplier (0.5x to 100x)
    IF @SpeedMultiplier < 0.5 SET @SpeedMultiplier = 0.5;
    IF @SpeedMultiplier > 100 SET @SpeedMultiplier = 100;
    
    DECLARE @CurrentTime DATETIME2 = SYSDATETIME();
    DECLARE @TripStartedAt DATETIME2;
    DECLARE @LastSpeedChangeAt DATETIME2;
    DECLARE @OldSpeedMultiplier DECIMAL(4,2);
    DECLARE @AccumulatedSimulatedSeconds FLOAT;
    
    -- Get current trip state
    SELECT 
        @TripStartedAt = TripStartedAt,
        @LastSpeedChangeAt = LastSpeedChangeAt,
        @OldSpeedMultiplier = ISNULL(TripSimulationSpeedMultiplier, 1.0),
        @AccumulatedSimulatedSeconds = ISNULL(AccumulatedSimulatedSeconds, 0)
    FROM dbo.Trip
    WHERE TripID = @TripID;
    
    -- Calculate the new accumulated simulated seconds
    DECLARE @RealElapsedSinceLastChange FLOAT;
    DECLARE @NewAccumulatedSeconds FLOAT;
    
    IF @LastSpeedChangeAt IS NOT NULL
    BEGIN
        -- Time since last speed change at old speed
        SET @RealElapsedSinceLastChange = DATEDIFF(SECOND, @LastSpeedChangeAt, @CurrentTime);
        SET @NewAccumulatedSeconds = @AccumulatedSimulatedSeconds + (@RealElapsedSinceLastChange * @OldSpeedMultiplier);
    END
    ELSE IF @TripStartedAt IS NOT NULL
    BEGIN
        -- First speed change - calculate from trip start
        SET @RealElapsedSinceLastChange = DATEDIFF(SECOND, @TripStartedAt, @CurrentTime);
        SET @NewAccumulatedSeconds = @RealElapsedSinceLastChange * @OldSpeedMultiplier;
    END
    ELSE
    BEGIN
        SET @NewAccumulatedSeconds = 0;
    END
    
    -- Update trip with new speed and accumulated time
    UPDATE dbo.Trip
    SET 
        TripSimulationSpeedMultiplier = @SpeedMultiplier,
        AccumulatedSimulatedSeconds = @NewAccumulatedSeconds,
        LastSpeedChangeAt = @CurrentTime
    WHERE TripID = @TripID;
    
    SELECT 
        1 AS Success,
        'Speed updated to ' + CAST(@SpeedMultiplier AS VARCHAR) + 'x' AS Message,
        @SpeedMultiplier AS SpeedMultiplier,
        CAST(@NewAccumulatedSeconds AS INT) AS AccumulatedSimulatedSeconds;
END
GO

-- Complete Trip
CREATE OR ALTER PROCEDURE dbo.spCompleteTrip
    @TripID INT,
    @DriverID INT = NULL,
    @TotalDistanceKm DECIMAL(10,3) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @TripDriverID INT;
    DECLARE @StartTime DATETIME2;
    DECLARE @RideRequestID INT;
    DECLARE @SegmentID INT;
    DECLARE @NextSegmentID INT;
    DECLARE @IsRealDriverTrip BIT;
    DECLARE @RemainingSegments INT;
    
    SELECT 
        @CurrentStatus = Status,
        @TripDriverID = DriverID,
        @StartTime = StartTime,
        @RideRequestID = RideRequestID,
        @IsRealDriverTrip = ISNULL(IsRealDriverTrip, 0)
    FROM dbo.Trip
    WHERE TripID = @TripID;
    
    IF @TripDriverID IS NULL
    BEGIN
        SELECT 0 AS Success, 'Trip not found.' AS Message;
        RETURN;
    END
    
    IF @DriverID IS NOT NULL AND @TripDriverID != @DriverID
    BEGIN
        SELECT 0 AS Success, 'This trip is not assigned to you.' AS Message;
        RETURN;
    END
    
    IF @CurrentStatus != 'in_progress'
    BEGIN
        SELECT 0 AS Success, 'Trip is not in progress. Current status: ' + @CurrentStatus AS Message;
        RETURN;
    END
    
    -- Check if this is a segment trip
    SELECT @SegmentID = rs.SegmentID
    FROM dbo.RideSegment rs
    WHERE rs.TripID = @TripID;
    
    DECLARE @EndTime DATETIME2 = SYSDATETIME();
    DECLARE @DurationSec INT = DATEDIFF(SECOND, @StartTime, @EndTime);
    
    UPDATE dbo.Trip
    SET 
        Status = 'completed',
        EndTime = @EndTime,
        TotalDurationSec = @DurationSec,
        TotalDistanceKm = ISNULL(@TotalDistanceKm, TotalDistanceKm)
    WHERE TripID = @TripID;
    
    -- Make driver available again
    UPDATE dbo.Driver
    SET IsAvailable = 1
    WHERE DriverID = @TripDriverID;
    
    INSERT INTO dbo.DispatchLog (RideRequestID, DriverID, Status, ChangeReason)
    VALUES (@RideRequestID, @TripDriverID, 'completed', 
            CASE WHEN @SegmentID IS NOT NULL THEN 'Segment completed - passenger transferred' ELSE 'Trip completed - passenger dropped off' END);
    
    -- For segment trips, check if there are more segments
    IF @SegmentID IS NOT NULL
    BEGIN
        -- Find the next unassigned segment
        SELECT TOP 1 @NextSegmentID = rs.SegmentID
        FROM dbo.RideSegment rs
        WHERE rs.RideRequestID = @RideRequestID
          AND rs.TripID IS NULL  -- Unassigned
        ORDER BY rs.SegmentOrder;
        
        -- Count remaining unassigned segments
        SELECT @RemainingSegments = COUNT(*)
        FROM dbo.RideSegment rs
        WHERE rs.RideRequestID = @RideRequestID
          AND rs.TripID IS NULL;
        
        IF @RemainingSegments = 0
        BEGIN
            -- All segments completed - mark ride request as completed
            UPDATE dbo.RideRequest
            SET Status = 'completed'
            WHERE RideRequestID = @RideRequestID;
            
            SELECT 
                1 AS Success,
                'All segments completed! Journey finished.' AS Message,
                @TripID AS TripID,
                @DurationSec AS TotalDurationSec,
                @TotalDistanceKm AS TotalDistanceKm,
                NULL AS NextSegmentID,
                1 AS JourneyCompleted;
        END
        ELSE
        BEGIN
            -- More segments to go - auto-assign next segment if NOT real driver trip
            IF @IsRealDriverTrip = 0 AND @NextSegmentID IS NOT NULL
            BEGIN
                EXEC dbo.spAutoAssignSimulatedDriverToSegment @NextSegmentID;
            END
            
            SELECT 
                1 AS Success,
                'Segment completed! ' + CAST(@RemainingSegments AS VARCHAR) + ' segment(s) remaining.' AS Message,
                @TripID AS TripID,
                @DurationSec AS TotalDurationSec,
                @TotalDistanceKm AS TotalDistanceKm,
                @NextSegmentID AS NextSegmentID,
                0 AS JourneyCompleted;
        END
    END
    ELSE
    BEGIN
        -- Regular trip (no segments) - mark ride request as completed
        UPDATE dbo.RideRequest
        SET Status = 'completed'
        WHERE RideRequestID = @RideRequestID;
        
        SELECT 
            1 AS Success,
            'Trip completed successfully!' AS Message,
            @TripID AS TripID,
            @DurationSec AS TotalDurationSec,
            @TotalDistanceKm AS TotalDistanceKm,
            NULL AS NextSegmentID,
            1 AS JourneyCompleted;
    END
END
GO

-- Auto-start Trip on Arrival
CREATE OR ALTER PROCEDURE dbo.spAutoStartTripOnArrival
    @TripID INT,
    @EstimatedDurationSec INT = NULL,
    @RouteGeometry NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @DriverID INT;
    
    SELECT 
        @CurrentStatus = Status,
        @DriverID = DriverID
    FROM dbo.Trip
    WHERE TripID = @TripID;
    
    IF @CurrentStatus = 'assigned'
    BEGIN
        EXEC dbo.spStartTrip 
            @TripID = @TripID, 
            @DriverID = @DriverID,
            @EstimatedDurationSec = @EstimatedDurationSec,
            @RouteGeometry = @RouteGeometry,
            @SpeedMultiplier = 1.0;
    END
    ELSE
    BEGIN
        SELECT 
            0 AS Success, 
            'Trip already started or not in assigned status.' AS Message,
            @CurrentStatus AS CurrentStatus;
    END
END
GO

-- Get Trip Tracking Status (comprehensive)
CREATE OR ALTER PROCEDURE dbo.spGetTripTrackingStatus
    @TripID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        t.TripID,
        t.Status AS TripStatus,
        t.DriverID,
        t.VehicleID,
        t.DriverStartLat,
        t.DriverStartLng,
        t.SimulationStartTime,
        t.EstimatedPickupTime,
        t.TripStartedAt,
        t.EstimatedTripDurationSec,
        t.TripSimulationSpeedMultiplier,
        t.StartTime,
        t.EndTime,
        t.RouteGeometry,
        d.CurrentLatitude AS DriverCurrentLat,
        d.CurrentLongitude AS DriverCurrentLng,
        du.FullName AS DriverName,
        v.PlateNo,
        vt.Name AS VehicleType,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription,
        rr.EstimatedFare,
        rr.EstimatedDistanceKm,
        rr.EstimatedDurationMin,
        CASE 
            WHEN t.Status = 'assigned' THEN 'driver_en_route'
            WHEN t.Status = 'in_progress' THEN 'trip_in_progress'
            WHEN t.Status = 'completed' THEN 'trip_completed'
            ELSE 'unknown'
        END AS TrackingPhase
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Location pl ON rr.PickupLocationID = pl.LocationID
    JOIN dbo.Location dl ON rr.DropoffLocationID = dl.LocationID
    LEFT JOIN dbo.Driver d ON t.DriverID = d.DriverID
    LEFT JOIN dbo.[User] du ON d.UserID = du.UserID
    LEFT JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
    LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE t.TripID = @TripID;
END
GO

-- ============================================================================
-- ============================================================================
-- DRIVER SEGMENT ASSIGNMENT STORED PROCEDURES
-- (Consolidated from driver_segment_assignment.sql)
-- ============================================================================
-- ============================================================================

-- Get Available Drivers For Segment
CREATE OR ALTER PROCEDURE dbo.spGetAvailableDriversForSegment
    @SegmentID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @SegmentGeofenceID INT;
    DECLARE @FromLat DECIMAL(9,6), @FromLon DECIMAL(9,6);
    DECLARE @ToLat DECIMAL(9,6), @ToLon DECIMAL(9,6);
    DECLARE @ServiceTypeID INT;
    DECLARE @RealDriversOnly BIT;
    
    SELECT 
        @SegmentGeofenceID = rs.GeofenceID,
        @FromLat = fromLoc.LatDegrees,
        @FromLon = fromLoc.LonDegrees,
        @ToLat = toLoc.LatDegrees,
        @ToLon = toLoc.LonDegrees,
        @ServiceTypeID = rr.ServiceTypeID,
        @RealDriversOnly = ISNULL(rr.RealDriversOnly, 0)
    FROM dbo.RideSegment rs
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    WHERE rs.SegmentID = @SegmentID;
    
    IF @SegmentGeofenceID IS NULL
    BEGIN
        SELECT 0 AS DriverID, '' AS DriverName WHERE 1=0;
        RETURN;
    END
    
    SELECT 
        d.DriverID,
        u.FullName AS DriverName,
        u.Phone AS DriverPhone,
        v.PlateNo,
        v.VehicleID,
        vt.Name AS VehicleType,
        d.RatingAverage,
        ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(@FromLat - d.CurrentLatitude) / 2), 2) +
                    COS(RADIANS(d.CurrentLatitude)) * COS(RADIANS(@FromLat)) *
                    POWER(SIN(RADIANS(@FromLon - d.CurrentLongitude) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(@FromLat - d.CurrentLatitude) / 2), 2) +
                    COS(RADIANS(d.CurrentLatitude)) * COS(RADIANS(@FromLat)) *
                    POWER(SIN(RADIANS(@FromLon - d.CurrentLongitude) / 2), 2)
                ))
            ),
            2
        ) AS DistanceToPickupKm
    FROM dbo.Driver d
    INNER JOIN dbo.[User] u ON d.UserID = u.UserID
    INNER JOIN dbo.Vehicle v ON v.DriverID = d.DriverID AND v.IsActive = 1
    INNER JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      AND d.CurrentLatitude IS NOT NULL
      -- Filter by driver type matching ride type: Real drivers for RealDriversOnly, simulated otherwise
      AND (
          (@RealDriversOnly = 1 AND d.UseGPS = 1)
          OR (@RealDriversOnly = 0 AND d.UseGPS = 0)
      )
      AND EXISTS (
          SELECT 1 
          FROM dbo.VehicleType_ServiceType vts
          WHERE vts.VehicleTypeID = v.VehicleTypeID
            AND vts.ServiceTypeID = @ServiceTypeID
      )
      -- Exclude vehicles whose most recent safety inspection failed
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.SafetyInspection si
          WHERE si.VehicleID = v.VehicleID
            AND si.Result = 'failed'
            AND si.SafetyInspectionID = (
                SELECT MAX(si2.SafetyInspectionID)
                FROM dbo.SafetyInspection si2
                WHERE si2.VehicleID = v.VehicleID
            )
      )
      -- CRITICAL: Only drivers whose vehicle is bound to the segment's geofence
      AND EXISTS (
          SELECT 1
          FROM dbo.GeofenceLog gl
          WHERE gl.VehicleID = v.VehicleID
            AND gl.ExitedAt IS NULL
            AND gl.GeofenceID = @SegmentGeofenceID
      )
    ORDER BY DistanceToPickupKm ASC;
END
GO

-- Assign Driver To Segment
CREATE OR ALTER PROCEDURE dbo.spAssignDriverToSegment
    @SegmentID INT,
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @RideRequestID INT;
    DECLARE @SegmentGeofenceID INT;
    DECLARE @ServiceTypeID INT;
    DECLARE @RealDriversOnly BIT;
    
    SELECT 
        @RideRequestID = rs.RideRequestID,
        @SegmentGeofenceID = rs.GeofenceID,
        @ServiceTypeID = rr.ServiceTypeID,
        @RealDriversOnly = ISNULL(rr.RealDriversOnly, 0)
    FROM dbo.RideSegment rs
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    WHERE rs.SegmentID = @SegmentID;
    
    IF @RideRequestID IS NULL
    BEGIN
        SELECT 0 AS TripID, 'Segment not found' AS ErrorMessage;
        RETURN;
    END
    
    -- Validate driver type: Simulated drivers CANNOT accept RealDriversOnly rides
    -- But real GPS drivers CAN accept any ride (they just use real GPS for tracking)
    DECLARE @UseGPS BIT;
    SELECT @UseGPS = ISNULL(UseGPS, 0) FROM dbo.Driver WHERE DriverID = @DriverID;
    
    IF @UseGPS = 0 AND @RealDriversOnly = 1
    BEGIN
        SELECT 0 AS TripID, 'This ride requires a real GPS-enabled driver.' AS ErrorMessage;
        RETURN;
    END
    
    DECLARE @SegmentOrder INT;
    SELECT @SegmentOrder = SegmentOrder FROM dbo.RideSegment WHERE SegmentID = @SegmentID;
    
    IF @SegmentOrder > 1
    BEGIN
        IF NOT EXISTS (
            SELECT 1 
            FROM dbo.RideSegment rs
            INNER JOIN dbo.Trip t ON rs.TripID = t.TripID
            WHERE rs.RideRequestID = @RideRequestID
              AND rs.SegmentOrder = @SegmentOrder - 1
              AND t.Status = 'completed'
        )
        BEGIN
            SELECT 0 AS TripID, 'Previous segment must be completed first' AS ErrorMessage;
            RETURN;
        END
    END
    
    IF EXISTS (SELECT 1 FROM dbo.RideSegment WHERE SegmentID = @SegmentID AND TripID IS NOT NULL)
    BEGIN
        SELECT 0 AS TripID, 'Segment already has a driver assigned' AS ErrorMessage;
        RETURN;
    END
    
    DECLARE @VehicleID INT;
    
    SELECT @VehicleID = v.VehicleID
    FROM dbo.Driver d
    LEFT JOIN dbo.Vehicle v ON v.DriverID = d.DriverID AND v.IsActive = 1
    WHERE d.DriverID = @DriverID
      AND d.IsAvailable = 1
      AND d.VerificationStatus = 'approved'
      -- Exclude vehicles whose most recent safety inspection failed
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.SafetyInspection si
          WHERE si.VehicleID = v.VehicleID
            AND si.Result = 'failed'
            AND si.SafetyInspectionID = (
                SELECT MAX(si2.SafetyInspectionID)
                FROM dbo.SafetyInspection si2
                WHERE si2.VehicleID = v.VehicleID
            )
      );
    
    IF @VehicleID IS NULL
    BEGIN
        SELECT 0 AS TripID, 'Driver not found or not available (vehicle may have failed safety inspection)' AS ErrorMessage;
        RETURN;
    END
    
    -- CRITICAL: Validate vehicle is bound to the segment's geofence
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.GeofenceLog gl
        WHERE gl.VehicleID = @VehicleID
          AND gl.ExitedAt IS NULL
          AND gl.GeofenceID = @SegmentGeofenceID  -- Segment's assigned geofence must match vehicle's bound geofence
    )
    BEGIN
        SELECT 0 AS TripID, 'Your vehicle is not assigned to this segment''s service area.' AS ErrorMessage;
        RETURN;
    END
    
    -- Determine if this is a real driver trip
    DECLARE @IsRealTrip BIT;
    SET @IsRealTrip = CASE WHEN @RealDriversOnly = 1 OR @UseGPS = 1 THEN 1 ELSE 0 END;
    
    DECLARE @TripID INT;
    
    INSERT INTO dbo.Trip (
        DriverID,
        VehicleID,
        RideRequestID,
        DispatchTime,
        Status,
        IsRealDriverTrip
    )
    VALUES (
        @DriverID,
        @VehicleID,
        @RideRequestID,
        GETDATE(),
        'dispatched',
        @IsRealTrip
    );
    
    SET @TripID = SCOPE_IDENTITY();
    
    UPDATE dbo.RideSegment
    SET TripID = @TripID
    WHERE SegmentID = @SegmentID;
    
    SELECT @TripID AS TripID, NULL AS ErrorMessage, @SegmentID AS SegmentID, @IsRealTrip AS IsRealDriverTrip;
END
GO

-- Get Pending Segments For Driver
-- Shows pending ride segments for drivers to accept
-- Respects RealDriversOnly flag: simulated drivers won't see RealDriversOnly rides
CREATE OR ALTER PROCEDURE dbo.spGetPendingSegmentsForDriver
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @DriverLat DECIMAL(9,6), @DriverLon DECIMAL(9,6);
    DECLARE @UseGPS BIT;
    DECLARE @IsAvailable BIT;
    
    SELECT 
        @DriverLat = d.CurrentLatitude,
        @DriverLon = d.CurrentLongitude,
        @UseGPS = d.UseGPS,
        @IsAvailable = d.IsAvailable
    FROM dbo.Driver d
    WHERE d.DriverID = @DriverID
      AND d.VerificationStatus = 'approved';
    
    -- For real drivers (UseGPS=1), allow them to see segments even without location
    -- For simulated drivers, require location to be set
    IF @UseGPS = 0 AND @DriverLat IS NULL
    BEGIN
        SELECT 0 AS SegmentID WHERE 1=0;
        RETURN;
    END
    
    -- Use default location if driver location not set (for real drivers)
    IF @DriverLat IS NULL SET @DriverLat = 35.1667;
    IF @DriverLon IS NULL SET @DriverLon = 33.3667;
    
    DECLARE @DriverVehicleTypeID INT;
    SELECT TOP 1 @DriverVehicleTypeID = VehicleTypeID
    FROM dbo.Vehicle
    WHERE DriverID = @DriverID AND IsActive = 1;
    
    SELECT 
        rs.SegmentID,
        rs.RideRequestID,
        rs.SegmentOrder,
        fromLoc.Description AS FromLocation,
        fromLoc.LatDegrees AS FromLat,
        fromLoc.LonDegrees AS FromLon,
        toLoc.Description AS ToLocation,
        toLoc.LatDegrees AS ToLat,
        toLoc.LonDegrees AS ToLon,
        g.Name AS GeofenceName,
        rr.RequestedAt,
        rr.ServiceTypeID,
        st.Name AS ServiceTypeName,
        u.FullName AS PassengerName,
        rr.RealDriversOnly,
        -- Segment fare with fallback calculation when EstimatedFare is NULL
        COALESCE(rs.EstimatedFare,
            -- Calculate on-the-fly if EstimatedFare is NULL
            ROUND(
                CASE rr.ServiceTypeID
                    WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00
                    WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00
                END
                + (COALESCE(rs.EstimatedDistanceKm,
                    6371 * 2 * ATN2(
                        SQRT(
                            POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                        ),
                        SQRT(1 - (
                            POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                        ))
                    ) * 1.3
                ) * CASE rr.ServiceTypeID
                    WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80
                    WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20
                END)
                + (CEILING((COALESCE(rs.EstimatedDistanceKm,
                    6371 * 2 * ATN2(
                        SQRT(
                            POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                        ),
                        SQRT(1 - (
                            POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                            COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                            POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                        ))
                    ) * 1.3
                ) / 40.0) * 60) * CASE rr.ServiceTypeID
                    WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25
                    WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20
                END)
            , 2)
        ) AS SegmentFare,
        -- Driver payment (100% of segment fare - no platform fee) with fallback calculation
        ROUND(
            COALESCE(rs.EstimatedFare,
                ROUND(
                    CASE rr.ServiceTypeID
                        WHEN 1 THEN 3.00 WHEN 2 THEN 8.00 WHEN 3 THEN 5.00
                        WHEN 4 THEN 10.00 WHEN 5 THEN 4.00 ELSE 3.00
                    END
                    + (COALESCE(rs.EstimatedDistanceKm,
                        6371 * 2 * ATN2(
                            SQRT(
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ),
                            SQRT(1 - (
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ))
                        ) * 1.3
                    ) * CASE rr.ServiceTypeID
                        WHEN 1 THEN 1.20 WHEN 2 THEN 2.50 WHEN 3 THEN 1.80
                        WHEN 4 THEN 2.20 WHEN 5 THEN 1.40 ELSE 1.20
                    END)
                    + (CEILING((COALESCE(rs.EstimatedDistanceKm,
                        6371 * 2 * ATN2(
                            SQRT(
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ),
                            SQRT(1 - (
                                POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                                COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                                POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                            ))
                        ) * 1.3
                    ) / 40.0) * 60) * CASE rr.ServiceTypeID
                        WHEN 1 THEN 0.20 WHEN 2 THEN 0.40 WHEN 3 THEN 0.25
                        WHEN 4 THEN 0.30 WHEN 5 THEN 0.22 ELSE 0.20
                    END)
                , 2)
            )
        , 2) AS EstimatedDriverPayment,
        -- Segment distance with fallback calculation
        COALESCE(rs.EstimatedDistanceKm,
            ROUND(
                6371 * 2 * ATN2(
                    SQRT(
                        POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                        COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                        POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                    ),
                    SQRT(1 - (
                        POWER(SIN(RADIANS(toLoc.LatDegrees - fromLoc.LatDegrees) / 2), 2) +
                        COS(RADIANS(fromLoc.LatDegrees)) * COS(RADIANS(toLoc.LatDegrees)) *
                        POWER(SIN(RADIANS(toLoc.LonDegrees - fromLoc.LonDegrees) / 2), 2)
                    ))
                ) * 1.3,
                2
            )
        ) AS SegmentDistanceKm,
        ROUND(
            6371 * 2 * ATN2(
                SQRT(
                    POWER(SIN(RADIANS(fromLoc.LatDegrees - @DriverLat) / 2), 2) +
                    COS(RADIANS(@DriverLat)) * COS(RADIANS(fromLoc.LatDegrees)) *
                    POWER(SIN(RADIANS(fromLoc.LonDegrees - @DriverLon) / 2), 2)
                ),
                SQRT(1 - (
                    POWER(SIN(RADIANS(fromLoc.LatDegrees - @DriverLat) / 2), 2) +
                    COS(RADIANS(@DriverLat)) * COS(RADIANS(fromLoc.LatDegrees)) *
                    POWER(SIN(RADIANS(fromLoc.LonDegrees - @DriverLon) / 2), 2)
                ))
            ),
            2
        ) AS DistanceToPickupKm
    FROM dbo.RideSegment rs
    INNER JOIN dbo.RideRequest rr ON rs.RideRequestID = rr.RideRequestID
    INNER JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.Location fromLoc ON rs.FromLocationID = fromLoc.LocationID
    INNER JOIN dbo.Location toLoc ON rs.ToLocationID = toLoc.LocationID
    LEFT JOIN dbo.Geofence g ON rs.GeofenceID = g.GeofenceID
    LEFT JOIN dbo.ServiceType st ON rr.ServiceTypeID = st.ServiceTypeID
    WHERE rs.TripID IS NULL
      AND rr.Status IN ('pending', 'accepted')
      -- Filter by driver type matching ride type:
      -- Real drivers (UseGPS=1) only see RealDriversOnly=1, simulated only see RealDriversOnly=0
      AND (
          (@UseGPS = 1 AND ISNULL(rr.RealDriversOnly, 0) = 1)
          OR (@UseGPS = 0 AND ISNULL(rr.RealDriversOnly, 0) = 0)
      )
      -- Only show segments that are ready to be picked up
      AND (
          rs.SegmentOrder = 1
          OR EXISTS (
              SELECT 1 
              FROM dbo.RideSegment rs_prev
              INNER JOIN dbo.Trip t_prev ON rs_prev.TripID = t_prev.TripID
              WHERE rs_prev.RideRequestID = rs.RideRequestID
                AND rs_prev.SegmentOrder = rs.SegmentOrder - 1
                AND t_prev.Status = 'completed'
          )
      )
      -- Check vehicle type compatibility (if driver has a vehicle)
      AND (
          @DriverVehicleTypeID IS NULL
          OR EXISTS (
              SELECT 1 
              FROM dbo.VehicleType_ServiceType vts
              WHERE vts.VehicleTypeID = @DriverVehicleTypeID
                AND vts.ServiceTypeID = rr.ServiceTypeID
          )
      )
      -- CRITICAL: Only show segments that belong to driver's vehicle's bound geofence
      AND EXISTS (
          SELECT 1
          FROM dbo.Vehicle v
          INNER JOIN dbo.GeofenceLog gl ON v.VehicleID = gl.VehicleID AND gl.ExitedAt IS NULL
          WHERE v.DriverID = @DriverID 
            AND v.IsActive = 1
            AND gl.GeofenceID = rs.GeofenceID  -- Segment's assigned geofence must match vehicle's bound geofence
      )
    ORDER BY rr.RequestedAt ASC, rs.SegmentOrder ASC;
END
GO

-- =============================================
-- Stored Procedure: spGetGeofencesWithPoints
-- Gets all active geofences with their boundary points for map visualization
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetGeofencesWithPoints
AS
BEGIN
    SET NOCOUNT ON;
    
    -- First result set: Geofences
    SELECT 
        g.GeofenceID,
        g.Name,
        g.Description,
        g.IsActive
    FROM dbo.Geofence g
    WHERE g.IsActive = 1
    ORDER BY g.GeofenceID;
    
    -- Second result set: Geofence points
    SELECT 
        gp.GeofenceID,
        gp.SequenceNo,
        gp.LatDegrees,
        gp.LonDegrees
    FROM dbo.GeofencePoint gp
    INNER JOIN dbo.Geofence g ON gp.GeofenceID = g.GeofenceID
    WHERE g.IsActive = 1
    ORDER BY gp.GeofenceID, gp.SequenceNo;
END
GO

-- =============================================
-- Stored Procedure: spGetGeofenceBridges
-- Gets all active bridges connecting geofences
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetGeofenceBridges
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        gb.BridgeID,
        gb.BridgeName,
        gb.Geofence1ID,
        gb.Geofence2ID,
        l.LatDegrees,
        l.LonDegrees,
        l.Description as LocationDescription,
        g1.Name as Geofence1Name,
        g2.Name as Geofence2Name
    FROM dbo.GeofenceBridge gb
    INNER JOIN dbo.Location l ON gb.LocationID = l.LocationID
    INNER JOIN dbo.Geofence g1 ON gb.Geofence1ID = g1.GeofenceID
    INNER JOIN dbo.Geofence g2 ON gb.Geofence2ID = g2.GeofenceID
    WHERE gb.IsActive = 1
    ORDER BY gb.BridgeID;
END
GO

-- =============================================
-- Stored Procedure: spGetAllDriverLocations
-- Gets all driver locations for map display with filtering options
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetAllDriverLocations
    @AvailableFilter VARCHAR(10) = 'all',  -- 'all', '1' (available only), '0' (unavailable only)
    @SimulatedOnly BIT = 1,                 -- Only show simulated drivers (UseGPS = 0)
    @Limit INT = 10000,
    @MinLat FLOAT = NULL,
    @MinLng FLOAT = NULL,
    @MaxLat FLOAT = NULL,
    @MaxLng FLOAT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@Limit)
        d.DriverID,
        u.FullName AS DriverName,
        d.CurrentLatitude AS lat,
        d.CurrentLongitude AS lng,
        d.IsAvailable,
        d.RatingAverage,
        d.LocationUpdatedAt,
        d.UseGPS,
        v.PlateNo,
        v.Make,
        v.Model,
        v.Color,
        vt.Name AS VehicleType
    FROM dbo.Driver d
    JOIN dbo.[User] u ON d.UserID = u.UserID
    LEFT JOIN dbo.Vehicle v ON d.DriverID = v.DriverID AND v.IsActive = 1
    LEFT JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE d.CurrentLatitude IS NOT NULL
      AND d.CurrentLongitude IS NOT NULL
      AND (@SimulatedOnly = 0 OR d.UseGPS = 0)
      AND (@AvailableFilter = 'all' 
           OR (@AvailableFilter = '1' AND d.IsAvailable = 1)
           OR (@AvailableFilter = '0' AND d.IsAvailable = 0))
      AND (@MinLat IS NULL OR d.CurrentLatitude BETWEEN @MinLat AND @MaxLat)
      AND (@MinLng IS NULL OR d.CurrentLongitude BETWEEN @MinLng AND @MaxLng)
    ORDER BY d.DriverID;
END
GO

-- =============================================
-- Stored Procedure: spGetDriverStats
-- Gets driver statistics for map display
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverStats
    @SimulatedOnly BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        COUNT(*) AS Total,
        SUM(CASE WHEN IsAvailable = 1 THEN 1 ELSE 0 END) AS Available,
        SUM(CASE WHEN IsAvailable = 0 THEN 1 ELSE 0 END) AS Unavailable
    FROM dbo.Driver
    WHERE CurrentLatitude IS NOT NULL
      AND (@SimulatedOnly = 0 OR UseGPS = 0);
END
GO

-- =============================================
-- Stored Procedure: spCheckDriverActiveSegmentTrip
-- Checks if a driver already has an active segment trip
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spCheckDriverActiveSegmentTrip
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT COUNT(*) AS ActiveCount
    FROM dbo.Trip t
    INNER JOIN dbo.RideSegment rs ON t.TripID = rs.TripID
    WHERE t.DriverID = @DriverID
      AND t.Status IN ('dispatched', 'in_progress');
END
GO

-- =============================================
-- Stored Procedure: spDriverAddVehicle
-- Allows a driver to add a new vehicle with documents
-- Vehicle will be inactive until operator approves (via safety inspection)
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spDriverAddVehicle
    @DriverID INT,
    @VehicleTypeID INT,
    @PlateNo NVARCHAR(20),
    @Make NVARCHAR(100),
    @Model NVARCHAR(100),
    @Year SMALLINT,
    @Color NVARCHAR(50),
    @NumDoors INT = 4,
    @SeatingCapacity INT,
    @HasPassengerSeat BIT = 1,
    @MaxWeightKg INT = NULL,
    @CargoVolumeLiters INT = NULL,
    @VehiclePhotosExterior NVARCHAR(MAX) = NULL,
    @VehiclePhotosInterior NVARCHAR(MAX) = NULL,
    -- Insurance Document
    @InsuranceNumber NVARCHAR(100),
    @InsuranceIssue DATE,
    @InsuranceExpiry DATE,
    @InsuranceStorageUrl NVARCHAR(MAX) = NULL,
    -- Registration Document
    @RegistrationNumber NVARCHAR(100),
    @RegistrationIssue DATE,
    @RegistrationExpiry DATE,
    @RegistrationStorageUrl NVARCHAR(MAX) = NULL,
    -- MOT/Technical Inspection Document
    @MotNumber NVARCHAR(100),
    @MotIssue DATE,
    @MotExpiry DATE,
    @MotStorageUrl NVARCHAR(MAX) = NULL,
    -- Classification Certificate Document
    @ClassificationNumber NVARCHAR(100),
    @ClassificationIssue DATE,
    @ClassificationExpiry DATE,
    @ClassificationStorageUrl NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @VehicleID INT;
    DECLARE @ErrorMessage NVARCHAR(500);
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Check if plate number already exists
        IF EXISTS (SELECT 1 FROM dbo.Vehicle WHERE PlateNo = @PlateNo)
        BEGIN
            SET @ErrorMessage = 'A vehicle with this plate number already exists.';
            RAISERROR(@ErrorMessage, 16, 1);
            RETURN;
        END
        
        -- Validate VehicleTypeID exists
        IF NOT EXISTS (SELECT 1 FROM dbo.VehicleType WHERE VehicleTypeID = @VehicleTypeID)
        BEGIN
            SET @ErrorMessage = 'Invalid vehicle type selected.';
            RAISERROR(@ErrorMessage, 16, 1);
            RETURN;
        END
        
        -- Insert vehicle with IsActive = 0 (pending operator approval)
        INSERT INTO dbo.Vehicle (
            DriverID, VehicleTypeID, PlateNo, Make, Model, Year, Color,
            NumberOfDoors, SeatingCapacity, HasPassengerSeat, MaxWeightKg, CargoVolume,
            PhotosExterior, PhotosInterior, IsActive
        )
        VALUES (
            @DriverID, @VehicleTypeID, @PlateNo, @Make, @Model, @Year, @Color,
            @NumDoors, @SeatingCapacity, @HasPassengerSeat, @MaxWeightKg, @CargoVolumeLiters,
            @VehiclePhotosExterior, @VehiclePhotosInterior, 0
        );
        
        SET @VehicleID = SCOPE_IDENTITY();
        
        -- Insert Insurance document
        INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
        VALUES (@VehicleID, 'insurance', @InsuranceNumber, @InsuranceIssue, @InsuranceExpiry, 'submitted', @InsuranceStorageUrl);
        
        -- Insert Registration document
        INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
        VALUES (@VehicleID, 'registration', @RegistrationNumber, @RegistrationIssue, @RegistrationExpiry, 'submitted', @RegistrationStorageUrl);
        
        -- Insert MOT document
        INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
        VALUES (@VehicleID, 'mot', @MotNumber, @MotIssue, @MotExpiry, 'submitted', @MotStorageUrl);
        
        -- Insert Classification Certificate document
        INSERT INTO dbo.VehicleDocument (VehicleID, DocType, IdNumber, IssueDate, ExpiryDate, Status, StorageUrl)
        VALUES (@VehicleID, 'classification_cert', @ClassificationNumber, @ClassificationIssue, @ClassificationExpiry, 'submitted', @ClassificationStorageUrl);
        
        -- Create a failed safety inspection to flag for operator review
        INSERT INTO dbo.SafetyInspection (VehicleID, InspectionDate, InspectorName, InspectionType, Result, Notes)
        VALUES (@VehicleID, GETDATE(), 'System', 'Initial Review', 'pending', 'New vehicle pending operator approval. Documents require verification.');
        
        COMMIT TRANSACTION;
        
        SELECT 
            @VehicleID AS VehicleID,
            1 AS Success,
            'Vehicle registered successfully. Pending operator approval.' AS Message;
            
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
            
        SELECT 
            0 AS VehicleID,
            0 AS Success,
            ERROR_MESSAGE() AS Message;
    END CATCH
END
GO

-- =============================================
-- Check if passenger has ANY active ride (driver OR autonomous OR carshare)
-- Returns HasActiveRide = 1 if any active ride exists
-- Used to prevent multiple concurrent ride requests
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spCheckPassengerHasAnyActiveRide
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @HasActiveDriverTrip BIT = 0;
    DECLARE @HasActiveAutonomousRide BIT = 0;
    DECLARE @HasActiveCarshareBooking BIT = 0;
    DECLARE @ActiveTripID INT = NULL;
    DECLARE @ActiveRideID INT = NULL;
    DECLARE @ActiveBookingID INT = NULL;
    DECLARE @ActiveTripStatus NVARCHAR(50) = NULL;
    DECLARE @ActiveRideStatus NVARCHAR(50) = NULL;
    DECLARE @ActiveBookingStatus NVARCHAR(50) = NULL;
    
    -- Check for active driver trip
    SELECT TOP 1 
        @HasActiveDriverTrip = 1,
        @ActiveTripID = t.TripID,
        @ActiveTripStatus = t.Status
    FROM dbo.Trip t
    INNER JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    WHERE rr.PassengerID = @PassengerID
      AND t.Status IN ('assigned', 'dispatched', 'in_progress')
    ORDER BY t.DispatchTime DESC;
    
    -- Check for active autonomous ride
    SELECT TOP 1 
        @HasActiveAutonomousRide = 1,
        @ActiveRideID = ar.AutonomousRideID,
        @ActiveRideStatus = ar.Status
    FROM dbo.AutonomousRide ar
    WHERE ar.PassengerID = @PassengerID
      AND ar.Status NOT IN ('completed', 'cancelled')
    ORDER BY ar.RequestedAt DESC;
    
    -- Check for active carshare booking
    SELECT TOP 1 
        @HasActiveCarshareBooking = 1,
        @ActiveBookingID = cb.BookingID,
        @ActiveBookingStatus = cb.Status
    FROM dbo.CarshareBooking cb
    INNER JOIN dbo.CarshareCustomer cc ON cb.CustomerID = cc.CustomerID
    WHERE cc.PassengerID = @PassengerID
      AND cb.Status IN ('reserved', 'active')
    ORDER BY cb.BookedAt DESC;
    
    -- Return consolidated result
    SELECT 
        CASE WHEN @HasActiveDriverTrip = 1 OR @HasActiveAutonomousRide = 1 OR @HasActiveCarshareBooking = 1 THEN 1 ELSE 0 END AS HasActiveRide,
        @HasActiveDriverTrip AS HasActiveDriverTrip,
        @HasActiveAutonomousRide AS HasActiveAutonomousRide,
        @HasActiveCarshareBooking AS HasActiveCarshareBooking,
        @ActiveTripID AS ActiveTripID,
        @ActiveTripStatus AS ActiveTripStatus,
        @ActiveRideID AS ActiveAutonomousRideID,
        @ActiveRideStatus AS ActiveAutonomousRideStatus,
        @ActiveBookingID AS ActiveCarshareBookingID,
        @ActiveBookingStatus AS ActiveCarshareBookingStatus;
END
GO

-- =============================================
-- Check if a database object (function, procedure) exists
-- Returns 1 if exists, 0 if not
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spCheckDatabaseObjectExists
    @ObjectName NVARCHAR(256)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT CASE WHEN OBJECT_ID(@ObjectName) IS NOT NULL THEN 1 ELSE 0 END AS ObjectExists;
END
GO

-- =============================================
-- Get geofence ID for a location using fnGetLocationGeofence
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetLocationGeofenceID
    @LocationID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT dbo.fnGetLocationGeofence(@LocationID) AS GeofenceID;
END
GO

-- =============================================
-- Get geofence name by ID
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetGeofenceName
    @GeofenceID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT Name FROM dbo.Geofence WHERE GeofenceID = @GeofenceID;
END
GO

-- =============================================
-- Get bridge details by ID including location coordinates
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetBridgeDetails
    @BridgeID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        gb.BridgeID, 
        gb.BridgeName, 
        l.LatDegrees, 
        l.LonDegrees, 
        l.Description,
        g1.Name as Geofence1Name, 
        g2.Name as Geofence2Name
    FROM dbo.GeofenceBridge gb
    JOIN dbo.Location l ON gb.LocationID = l.LocationID
    JOIN dbo.Geofence g1 ON gb.Geofence1ID = g1.GeofenceID
    JOIN dbo.Geofence g2 ON gb.Geofence2ID = g2.GeofenceID
    WHERE gb.BridgeID = @BridgeID;
END
GO

-- =============================================
-- Get Unread Message Count for a User
-- Used by operator/dashboard.php for alerts
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetUnreadMessageCount
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT COUNT(*) AS UnreadCount
    FROM dbo.[Message]
    WHERE ToUserID = @UserID
      AND IsRead = 0;
END
GO

-- =============================================
-- Mark Messages as Read
-- Used when user views a conversation
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spMarkMessagesAsRead
    @UserID INT,
    @FromUserID INT = NULL  -- Optional: mark only messages from specific user
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.[Message]
    SET IsRead = 1,
        ReadAt = SYSDATETIME()
    WHERE ToUserID = @UserID
      AND IsRead = 0
      AND (@FromUserID IS NULL OR FromUserID = @FromUserID);
    
    SELECT @@ROWCOUNT AS MessagesMarked;
END
GO

-- =============================================
-- Get Operator Conversations
-- Returns all conversations for an operator, sorted by unread first, then by last message time
-- Used by operator/messages.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetOperatorConversations
    @OperatorUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    ;WITH ConversationSummary AS (
        SELECT 
            CASE 
                WHEN m.FromUserID = @OperatorUserID THEN m.ToUserID 
                ELSE m.FromUserID 
            END AS OtherUserID,
            MAX(m.SentAt) AS LastMessageTime,
            SUM(CASE WHEN m.ToUserID = @OperatorUserID AND m.IsRead = 0 THEN 1 ELSE 0 END) AS UnreadCount
        FROM dbo.[Message] m
        WHERE m.FromUserID = @OperatorUserID OR m.ToUserID = @OperatorUserID
        GROUP BY CASE 
            WHEN m.FromUserID = @OperatorUserID THEN m.ToUserID 
            ELSE m.FromUserID 
        END
    )
    SELECT 
        cs.OtherUserID AS UserID,
        u.FullName,
        u.Email,
        u.PhotoUrl,
        cs.LastMessageTime,
        cs.UnreadCount,
        (SELECT TOP 1 Content FROM dbo.[Message] 
         WHERE (FromUserID = @OperatorUserID AND ToUserID = cs.OtherUserID)
            OR (FromUserID = cs.OtherUserID AND ToUserID = @OperatorUserID)
         ORDER BY SentAt DESC) AS LastMessageContent,
        CASE 
            WHEN EXISTS (SELECT 1 FROM dbo.Driver d WHERE d.UserID = cs.OtherUserID) THEN 'driver'
            WHEN EXISTS (SELECT 1 FROM dbo.Passenger p WHERE p.UserID = cs.OtherUserID) THEN 'passenger'
            WHEN EXISTS (SELECT 1 FROM dbo.Operator o WHERE o.UserID = cs.OtherUserID) THEN 'operator'
            ELSE 'unknown'
        END AS UserRole
    FROM ConversationSummary cs
    JOIN dbo.[User] u ON cs.OtherUserID = u.UserID
    WHERE u.Status = 'active'
    ORDER BY cs.UnreadCount DESC, cs.LastMessageTime DESC;
END
GO

-- =============================================
-- Get Driver Conversations
-- Returns all conversations for a driver, sorted by unread first, then by last message time
-- Used by driver/messages.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverConversations
    @DriverUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    ;WITH ConversationSummary AS (
        SELECT 
            CASE 
                WHEN m.FromUserID = @DriverUserID THEN m.ToUserID 
                ELSE m.FromUserID 
            END AS OtherUserID,
            MAX(m.SentAt) AS LastMessageTime,
            SUM(CASE WHEN m.ToUserID = @DriverUserID AND m.IsRead = 0 THEN 1 ELSE 0 END) AS UnreadCount
        FROM dbo.[Message] m
        WHERE m.FromUserID = @DriverUserID OR m.ToUserID = @DriverUserID
        GROUP BY CASE 
            WHEN m.FromUserID = @DriverUserID THEN m.ToUserID 
            ELSE m.FromUserID 
        END
    )
    SELECT 
        cs.OtherUserID AS UserID,
        u.FullName,
        u.Email,
        u.PhotoUrl,
        cs.LastMessageTime,
        cs.UnreadCount,
        (SELECT TOP 1 Content FROM dbo.[Message] 
         WHERE (FromUserID = @DriverUserID AND ToUserID = cs.OtherUserID)
            OR (FromUserID = cs.OtherUserID AND ToUserID = @DriverUserID)
         ORDER BY SentAt DESC) AS LastMessageContent,
        CASE 
            WHEN EXISTS (SELECT 1 FROM dbo.Passenger p WHERE p.UserID = cs.OtherUserID) THEN 'passenger'
            WHEN EXISTS (SELECT 1 FROM dbo.Operator o WHERE o.UserID = cs.OtherUserID) THEN 'operator'
            ELSE 'unknown'
        END AS UserRole
    FROM ConversationSummary cs
    JOIN dbo.[User] u ON cs.OtherUserID = u.UserID
    WHERE u.Status = 'active'
    ORDER BY cs.UnreadCount DESC, cs.LastMessageTime DESC;
END
GO

-- =============================================
-- Get Passenger Conversations
-- Returns all conversations for a passenger, sorted by unread first, then by last message time
-- Used by passenger/messages.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerConversations
    @PassengerUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    ;WITH ConversationSummary AS (
        SELECT 
            CASE 
                WHEN m.FromUserID = @PassengerUserID THEN m.ToUserID 
                ELSE m.FromUserID 
            END AS OtherUserID,
            MAX(m.SentAt) AS LastMessageTime,
            SUM(CASE WHEN m.ToUserID = @PassengerUserID AND m.IsRead = 0 THEN 1 ELSE 0 END) AS UnreadCount
        FROM dbo.[Message] m
        WHERE m.FromUserID = @PassengerUserID OR m.ToUserID = @PassengerUserID
        GROUP BY CASE 
            WHEN m.FromUserID = @PassengerUserID THEN m.ToUserID 
            ELSE m.FromUserID 
        END
    )
    SELECT 
        cs.OtherUserID AS UserID,
        u.FullName,
        u.Email,
        u.PhotoUrl,
        cs.LastMessageTime,
        cs.UnreadCount,
        (SELECT TOP 1 Content FROM dbo.[Message] 
         WHERE (FromUserID = @PassengerUserID AND ToUserID = cs.OtherUserID)
            OR (FromUserID = cs.OtherUserID AND ToUserID = @PassengerUserID)
         ORDER BY SentAt DESC) AS LastMessageContent,
        CASE 
            WHEN EXISTS (SELECT 1 FROM dbo.Driver d WHERE d.UserID = cs.OtherUserID) THEN 'driver'
            WHEN EXISTS (SELECT 1 FROM dbo.Operator o WHERE o.UserID = cs.OtherUserID) THEN 'operator'
            ELSE 'unknown'
        END AS UserRole
    FROM ConversationSummary cs
    JOIN dbo.[User] u ON cs.OtherUserID = u.UserID
    WHERE u.Status = 'active'
    ORDER BY cs.UnreadCount DESC, cs.LastMessageTime DESC;
END
GO

-- =============================================
-- Get Driver Contactable Passengers
-- Returns passengers the driver has had trips with (for new message dropdown)
-- Used by driver/messages.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverContactablePassengers
    @DriverUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT DISTINCT
        u.UserID,
        u.FullName,
        u.Email,
        u.PhotoUrl,
        MAX(t.EndTime) AS LastTripDate
    FROM dbo.[User] u
    JOIN dbo.Passenger p ON u.UserID = p.UserID
    JOIN dbo.RideRequest rr ON p.PassengerID = rr.PassengerID
    JOIN dbo.Trip t ON rr.RideRequestID = t.RideRequestID
    JOIN dbo.Driver d ON t.DriverID = d.DriverID
    WHERE d.UserID = @DriverUserID
      AND u.Status = 'active'
      AND t.Status IN ('completed', 'in_progress')
    GROUP BY u.UserID, u.FullName, u.Email, u.PhotoUrl
    ORDER BY LastTripDate DESC;
END
GO

-- =============================================
-- Get Passenger Contactable Drivers
-- Returns drivers the passenger has had trips with (for new message dropdown)
-- Used by passenger/messages.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerContactableDrivers
    @PassengerUserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT DISTINCT
        u.UserID,
        u.FullName,
        u.Email,
        u.PhotoUrl,
        MAX(t.EndTime) AS LastTripDate
    FROM dbo.[User] u
    JOIN dbo.Driver d ON u.UserID = d.UserID
    JOIN dbo.Trip t ON d.DriverID = t.DriverID
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    WHERE p.UserID = @PassengerUserID
      AND u.Status = 'active'
      AND t.Status IN ('completed', 'in_progress')
    GROUP BY u.UserID, u.FullName, u.Email, u.PhotoUrl
    ORDER BY LastTripDate DESC;
END
GO

-- =============================================
-- Get Driver Dashboard Stats
-- Returns statistics for the driver dashboard
-- Used by driver/dashboard.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverDashboardStats
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT
        -- Active trips count (assigned or in_progress)
        (SELECT COUNT(*) FROM dbo.Trip WHERE DriverID = @DriverID AND Status IN ('assigned', 'in_progress')) AS ActiveTripsCount,
        -- Completed trips count
        (SELECT COUNT(*) FROM dbo.Trip WHERE DriverID = @DriverID AND Status = 'completed') AS CompletedTripsCount,
        -- Available ride requests in driver's geofence(s)
        (SELECT COUNT(*) FROM dbo.RideRequest WHERE Status = 'pending') AS PendingRequestsCount,
        -- Today's earnings
        (SELECT ISNULL(SUM(p.DriverEarnings), 0) 
         FROM dbo.Payment p 
         JOIN dbo.Trip t ON p.TripID = t.TripID 
         WHERE t.DriverID = @DriverID 
           AND p.Status = 'completed'
           AND CAST(p.CompletedAt AS DATE) = CAST(GETDATE() AS DATE)) AS TodayEarnings,
        -- This week's earnings
        (SELECT ISNULL(SUM(p.DriverEarnings), 0) 
         FROM dbo.Payment p 
         JOIN dbo.Trip t ON p.TripID = t.TripID 
         WHERE t.DriverID = @DriverID 
           AND p.Status = 'completed'
           AND p.CompletedAt >= DATEADD(DAY, -7, GETDATE())) AS WeekEarnings,
        -- Total earnings
        (SELECT ISNULL(SUM(p.DriverEarnings), 0) 
         FROM dbo.Payment p 
         JOIN dbo.Trip t ON p.TripID = t.TripID 
         WHERE t.DriverID = @DriverID 
           AND p.Status = 'completed') AS TotalEarnings;
END
GO

-- =============================================
-- Check if Driver Has Active Trip
-- Returns 1 if driver has an active trip, 0 otherwise
-- Used by driver/dashboard.php to prevent accepting new trips
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spCheckDriverHasActiveTrip
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    IF EXISTS (
        SELECT 1 FROM dbo.Trip 
        WHERE DriverID = @DriverID 
          AND Status IN ('assigned', 'in_progress')
    )
        SELECT 1 AS HasActiveTrip;
    ELSE
        SELECT 0 AS HasActiveTrip;
END
GO

-- =============================================
-- Get Driver Active Trip Details
-- Returns the current active trip for dashboard display
-- Used by driver/dashboard.php
-- =============================================
CREATE OR ALTER PROCEDURE dbo.spGetDriverActiveTrip
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        t.TripID,
        t.Status,
        t.StartTime,
        t.DispatchTime,
        rr.RequestedAt,
        rr.PassengerNotes,
        p.PassengerID,
        uP.UserID AS PassengerUserID,
        uP.FullName AS PassengerName,
        COALESCE(lp.StreetAddress, lp.Description) AS PickupAddress,
        lp.LatDegrees AS PickupLat,
        lp.LonDegrees AS PickupLng,
        COALESCE(ld.StreetAddress, ld.Description) AS DropoffAddress,
        ld.LatDegrees AS DropoffLat,
        ld.LonDegrees AS DropoffLng,
        rr.EstimatedDistanceKm,
        rr.EstimatedDurationMin,
        rr.EstimatedFare,
        v.PlateNo,
        vt.Name AS VehicleTypeName,
        ISNULL(t.IsRealDriverTrip, 0) AS IsRealDriverTrip
    FROM dbo.Trip t
    JOIN dbo.RideRequest rr ON t.RideRequestID = rr.RideRequestID
    JOIN dbo.Passenger p ON rr.PassengerID = p.PassengerID
    JOIN dbo.[User] uP ON p.UserID = uP.UserID
    JOIN dbo.Location lp ON rr.PickupLocationID = lp.LocationID
    JOIN dbo.Location ld ON rr.DropoffLocationID = ld.LocationID
    JOIN dbo.Vehicle v ON t.VehicleID = v.VehicleID
    JOIN dbo.VehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE t.DriverID = @DriverID
      AND t.Status IN ('assigned', 'in_progress')
    ORDER BY t.DispatchTime DESC;
END
GO

/* ============================================================
   AUTONOMOUS VEHICLES STORED PROCEDURES
   Procedures for Waymo-style autonomous vehicle ride service
   ============================================================ */

-- ============================================================
-- Get all available autonomous vehicles
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAvailableAutonomousVehicles
    @GeofenceID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        av.AutonomousVehicleID,
        av.VehicleCode,
        av.VehicleTypeID,
        vt.Name AS VehicleTypeName,
        av.PlateNo,
        av.Make,
        av.Model,
        av.Year,
        av.Color,
        av.SeatingCapacity,
        av.IsWheelchairReady,
        av.Status,
        av.CurrentLatitude,
        av.CurrentLongitude,
        av.LocationUpdatedAt,
        av.GeofenceID,
        g.Name AS GeofenceName,
        av.BatteryLevel,
        av.PhotoUrl
    FROM dbo.[AutonomousVehicle] av
    INNER JOIN dbo.[VehicleType] vt ON av.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.[Geofence] g ON av.GeofenceID = g.GeofenceID
    WHERE av.IsActive = 1
      AND av.Status = 'available'
      AND (@GeofenceID IS NULL OR av.GeofenceID = @GeofenceID)
    ORDER BY av.VehicleCode;
END
GO

CREATE OR ALTER PROCEDURE dbo.spGetAllAutonomousVehicles
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        av.AutonomousVehicleID,
        av.VehicleCode,
        av.VehicleTypeID,
        vt.Name AS VehicleTypeName,
        av.PlateNo,
        av.Make,
        av.Model,
        av.Year,
        av.Color,
        av.SeatingCapacity,
        av.IsWheelchairReady,
        av.Status,
        av.CurrentLatitude,
        av.CurrentLongitude,
        av.LocationUpdatedAt,
        av.GeofenceID,
        g.Name AS GeofenceName,
        av.BatteryLevel,
        av.PhotoUrl,
        av.IsActive,
        av.CreatedAt
    FROM dbo.[AutonomousVehicle] av
    INNER JOIN dbo.[VehicleType] vt ON av.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.[Geofence] g ON av.GeofenceID = g.GeofenceID
    ORDER BY av.VehicleCode;
END
GO

-- ============================================================
-- Get nearest available autonomous vehicle to a location
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetNearestAutonomousVehicle
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6),
    @WheelchairNeeded BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        av.AutonomousVehicleID,
        av.VehicleCode,
        av.VehicleTypeID,
        vt.Name AS VehicleTypeName,
        av.PlateNo,
        av.Make,
        av.Model,
        av.Color,
        av.SeatingCapacity,
        av.CurrentLatitude,
        av.CurrentLongitude,
        av.BatteryLevel,
        av.PhotoUrl,
        -- Haversine distance calculation (approximate, in km)
        (6371 * ACOS(
            COS(RADIANS(@Latitude)) * COS(RADIANS(av.CurrentLatitude)) *
            COS(RADIANS(av.CurrentLongitude) - RADIANS(@Longitude)) +
            SIN(RADIANS(@Latitude)) * SIN(RADIANS(av.CurrentLatitude))
        )) AS DistanceKm
    FROM dbo.[AutonomousVehicle] av
    INNER JOIN dbo.[VehicleType] vt ON av.VehicleTypeID = vt.VehicleTypeID
    WHERE av.IsActive = 1
      AND av.Status = 'available'
      AND av.CurrentLatitude IS NOT NULL
      AND av.CurrentLongitude IS NOT NULL
      AND av.BatteryLevel >= 20  -- Minimum battery level for a ride
      AND (@WheelchairNeeded = 0 OR av.IsWheelchairReady = 1)
    ORDER BY DistanceKm ASC;
END
GO

-- ============================================================
-- Create an autonomous ride request (auto-assigns vehicle)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spCreateAutonomousRide
    @PassengerID INT,
    @PickupLocationID INT,
    @DropoffLocationID INT,
    @PassengerNotes NVARCHAR(500) = NULL,
    @WheelchairNeeded BIT = 0,
    @PaymentMethodTypeID INT = 1,
    @EstimatedPickupDistanceKm DECIMAL(10,3) = NULL,
    @EstimatedPickupDurationSec INT = NULL,
    @EstimatedTripDistanceKm DECIMAL(10,3) = NULL,
    @EstimatedTripDurationSec INT = NULL,
    @EstimatedFare DECIMAL(10,2) = NULL,
    @PickupRouteGeometry NVARCHAR(MAX) = NULL,
    @TripRouteGeometry NVARCHAR(MAX) = NULL,
    @AutonomousVehicleID INT = NULL  -- Optional: specific vehicle, otherwise auto-select
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @SelectedVehicleID INT;
    DECLARE @VehicleStartLat DECIMAL(9,6);
    DECLARE @VehicleStartLng DECIMAL(9,6);
    DECLARE @PickupLat DECIMAL(9,6);
    DECLARE @PickupLng DECIMAL(9,6);
    
    -- Get pickup location coordinates
    SELECT @PickupLat = LatDegrees, @PickupLng = LonDegrees
    FROM dbo.[Location]
    WHERE LocationID = @PickupLocationID;
    
    IF @PickupLat IS NULL
    BEGIN
        RAISERROR('Invalid pickup location.', 16, 1);
        RETURN;
    END
    
    -- If specific vehicle requested, use it; otherwise find nearest available
    IF @AutonomousVehicleID IS NOT NULL
    BEGIN
        SELECT @SelectedVehicleID = AutonomousVehicleID,
               @VehicleStartLat = CurrentLatitude,
               @VehicleStartLng = CurrentLongitude
        FROM dbo.[AutonomousVehicle]
        WHERE AutonomousVehicleID = @AutonomousVehicleID
          AND IsActive = 1
          AND Status = 'available'
          AND BatteryLevel >= 20;
          
        IF @SelectedVehicleID IS NULL
        BEGIN
            RAISERROR('Selected autonomous vehicle is not available.', 16, 1);
            RETURN;
        END
    END
    ELSE
    BEGIN
        -- Find nearest available vehicle
        SELECT TOP 1 
            @SelectedVehicleID = AutonomousVehicleID,
            @VehicleStartLat = CurrentLatitude,
            @VehicleStartLng = CurrentLongitude
        FROM dbo.[AutonomousVehicle]
        WHERE IsActive = 1
          AND Status = 'available'
          AND CurrentLatitude IS NOT NULL
          AND CurrentLongitude IS NOT NULL
          AND BatteryLevel >= 20
          AND (@WheelchairNeeded = 0 OR IsWheelchairReady = 1)
        ORDER BY 
            (6371 * ACOS(
                COS(RADIANS(@PickupLat)) * COS(RADIANS(CurrentLatitude)) *
                COS(RADIANS(CurrentLongitude) - RADIANS(@PickupLng)) +
                SIN(RADIANS(@PickupLat)) * SIN(RADIANS(CurrentLatitude))
            )) ASC;
            
        IF @SelectedVehicleID IS NULL
        BEGIN
            RAISERROR('No autonomous vehicles available at this time.', 16, 1);
            RETURN;
        END
    END
    
    -- Mark vehicle as busy
    UPDATE dbo.[AutonomousVehicle]
    SET Status = 'busy',
        UpdatedAt = SYSDATETIME()
    WHERE AutonomousVehicleID = @SelectedVehicleID;
    
    -- Create the autonomous ride
    INSERT INTO dbo.[AutonomousRide] (
        PassengerID,
        AutonomousVehicleID,
        PickupLocationID,
        DropoffLocationID,
        RequestedAt,
        PassengerNotes,
        WheelchairNeeded,
        Status,
        VehicleDispatchedAt,
        VehicleStartLat,
        VehicleStartLng,
        PickupRouteGeometry,
        TripRouteGeometry,
        EstimatedPickupDistanceKm,
        EstimatedPickupDurationSec,
        EstimatedTripDistanceKm,
        EstimatedTripDurationSec,
        EstimatedFare,
        PaymentMethodTypeID,
        SimulationPhase,
        SimulationStartTime
    )
    VALUES (
        @PassengerID,
        @SelectedVehicleID,
        @PickupLocationID,
        @DropoffLocationID,
        SYSDATETIME(),
        @PassengerNotes,
        @WheelchairNeeded,
        'vehicle_dispatched',
        SYSDATETIME(),
        @VehicleStartLat,
        @VehicleStartLng,
        @PickupRouteGeometry,
        @TripRouteGeometry,
        @EstimatedPickupDistanceKm,
        @EstimatedPickupDurationSec,
        @EstimatedTripDistanceKm,
        @EstimatedTripDurationSec,
        @EstimatedFare,
        @PaymentMethodTypeID,
        'pickup',
        SYSDATETIME()
    );
    
    DECLARE @RideID INT = SCOPE_IDENTITY();
    
    -- Log to system
    INSERT INTO dbo.[SystemLog] (UserID, Severity, Category, Message)
    SELECT u.UserID, 'info', 'autonomous_ride', 
           'Autonomous ride ' + CAST(@RideID AS NVARCHAR) + ' created and vehicle dispatched.'
    FROM dbo.[Passenger] p
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    WHERE p.PassengerID = @PassengerID;
    
    -- Return ride details
    SELECT 
        ar.AutonomousRideID,
        ar.PassengerID,
        ar.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        av.Color,
        av.PlateNo,
        ar.Status,
        ar.RequestedAt,
        ar.VehicleDispatchedAt,
        ar.VehicleStartLat,
        ar.VehicleStartLng,
        ar.EstimatedPickupDistanceKm,
        ar.EstimatedPickupDurationSec,
        ar.EstimatedTripDistanceKm,
        ar.EstimatedTripDurationSec,
        ar.EstimatedFare,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE ar.AutonomousRideID = @RideID;
END
GO

-- ============================================================
-- Get autonomous ride details
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousRideDetails
    @AutonomousRideID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.PassengerID,
        p.UserID AS PassengerUserID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        ar.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        av.Color,
        av.PlateNo,
        av.SeatingCapacity,
        av.PhotoUrl AS VehiclePhotoUrl,
        av.CurrentLatitude AS VehicleCurrentLat,
        av.CurrentLongitude AS VehicleCurrentLng,
        av.BatteryLevel,
        ar.PickupLocationID,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        pl.StreetAddress AS PickupAddress,
        ar.DropoffLocationID,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription,
        dl.StreetAddress AS DropoffAddress,
        ar.Status,
        ar.RequestedAt,
        ar.VehicleDispatchedAt,
        ar.VehicleArrivedAt,
        ar.PassengerBoardedAt,
        ar.TripStartedAt,
        ar.TripCompletedAt,
        ar.PassengerNotes,
        ar.WheelchairNeeded,
        ar.PickupRouteGeometry,
        ar.TripRouteGeometry,
        ar.EstimatedPickupDistanceKm,
        ar.EstimatedPickupDurationSec,
        ar.EstimatedTripDistanceKm,
        ar.EstimatedTripDurationSec,
        ar.ActualDistanceKm,
        ar.ActualDurationSec,
        ar.VehicleStartLat,
        ar.VehicleStartLng,
        ar.SimulationPhase,
        ar.SimulationStartTime,
        ar.SimulationSpeedMultiplier,
        ar.AccumulatedSimulatedSeconds,
        ar.LastSpeedChangeAt,
        ar.PaymentMethodTypeID,
        pmt.Code AS PaymentMethodCode,
        pmt.Description AS PaymentMethodDescription,
        ar.EstimatedFare,
        ar.ActualFare,
        ar.CancelledAt,
        ar.CancellationReason
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Passenger] p ON ar.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    INNER JOIN dbo.[PaymentMethodType] pmt ON ar.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE ar.AutonomousRideID = @AutonomousRideID;
END
GO

-- ============================================================
-- Update autonomous ride status
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAutonomousRideStatus
    @AutonomousRideID INT,
    @NewStatus NVARCHAR(30)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @VehicleID INT;
    
    SELECT @CurrentStatus = Status, @VehicleID = AutonomousVehicleID
    FROM dbo.[AutonomousRide]
    WHERE AutonomousRideID = @AutonomousRideID;
    
    IF @CurrentStatus IS NULL
    BEGIN
        RAISERROR('Autonomous ride not found.', 16, 1);
        RETURN;
    END
    
    -- Update status and relevant timestamps
    UPDATE dbo.[AutonomousRide]
    SET Status = @NewStatus,
        VehicleArrivedAt = CASE WHEN @NewStatus = 'vehicle_arrived' THEN SYSDATETIME() ELSE VehicleArrivedAt END,
        PassengerBoardedAt = CASE WHEN @NewStatus = 'passenger_boarding' THEN SYSDATETIME() ELSE PassengerBoardedAt END,
        TripStartedAt = CASE WHEN @NewStatus = 'in_progress' THEN SYSDATETIME() ELSE TripStartedAt END,
        TripCompletedAt = CASE WHEN @NewStatus = 'completed' THEN SYSDATETIME() ELSE TripCompletedAt END,
        SimulationPhase = CASE 
            WHEN @NewStatus IN ('in_progress', 'arriving_destination') THEN 'trip'
            WHEN @NewStatus = 'completed' THEN NULL
            ELSE SimulationPhase 
        END,
        SimulationStartTime = CASE 
            WHEN @NewStatus = 'in_progress' THEN SYSDATETIME()
            ELSE SimulationStartTime 
        END,
        AccumulatedSimulatedSeconds = CASE 
            WHEN @NewStatus = 'in_progress' THEN 0
            ELSE AccumulatedSimulatedSeconds 
        END
    WHERE AutonomousRideID = @AutonomousRideID;
    
    -- If completed, make vehicle available again
    IF @NewStatus = 'completed'
    BEGIN
        UPDATE dbo.[AutonomousVehicle]
        SET Status = 'available',
            UpdatedAt = SYSDATETIME()
        WHERE AutonomousVehicleID = @VehicleID;
    END
    
    SELECT @AutonomousRideID AS AutonomousRideID, @NewStatus AS Status;
END
GO

-- ============================================================
-- Cancel autonomous ride
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spCancelAutonomousRide
    @AutonomousRideID INT,
    @CancellationReason NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @VehicleID INT;
    
    SELECT @CurrentStatus = Status, @VehicleID = AutonomousVehicleID
    FROM dbo.[AutonomousRide]
    WHERE AutonomousRideID = @AutonomousRideID;
    
    IF @CurrentStatus IS NULL
    BEGIN
        RAISERROR('Autonomous ride not found.', 16, 1);
        RETURN;
    END
    
    IF @CurrentStatus IN ('completed', 'cancelled')
    BEGIN
        RAISERROR('Cannot cancel a ride that is already completed or cancelled.', 16, 1);
        RETURN;
    END
    
    -- Cancel the ride
    UPDATE dbo.[AutonomousRide]
    SET Status = 'cancelled',
        CancelledAt = SYSDATETIME(),
        CancellationReason = @CancellationReason
    WHERE AutonomousRideID = @AutonomousRideID;
    
    -- Make vehicle available again
    UPDATE dbo.[AutonomousVehicle]
    SET Status = 'available',
        UpdatedAt = SYSDATETIME()
    WHERE AutonomousVehicleID = @VehicleID;
    
    SELECT @AutonomousRideID AS AutonomousRideID, 'cancelled' AS Status;
END
GO

-- ============================================================
-- Complete autonomous ride and process payment
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spCompleteAutonomousRide
    @AutonomousRideID INT,
    @ActualDistanceKm DECIMAL(10,3) = NULL,
    @ActualDurationSec INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @CurrentStatus NVARCHAR(30);
    DECLARE @VehicleID INT;
    DECLARE @EstimatedFare DECIMAL(10,2);
    DECLARE @PaymentMethodTypeID INT;
    DECLARE @FinalFare DECIMAL(10,2);
    
    SELECT @CurrentStatus = Status, 
           @VehicleID = AutonomousVehicleID,
           @EstimatedFare = EstimatedFare,
           @PaymentMethodTypeID = PaymentMethodTypeID
    FROM dbo.[AutonomousRide]
    WHERE AutonomousRideID = @AutonomousRideID;
    
    IF @CurrentStatus IS NULL
    BEGIN
        RAISERROR('Autonomous ride not found.', 16, 1);
        RETURN;
    END
    
    IF @CurrentStatus IN ('completed', 'cancelled')
    BEGIN
        RAISERROR('Ride is already completed or cancelled.', 16, 1);
        RETURN;
    END
    
    -- Calculate final fare (use estimated if no actual provided)
    SET @FinalFare = COALESCE(@EstimatedFare, 10.00);
    
    -- Update ride as completed
    UPDATE dbo.[AutonomousRide]
    SET Status = 'completed',
        TripCompletedAt = SYSDATETIME(),
        ActualDistanceKm = COALESCE(@ActualDistanceKm, EstimatedTripDistanceKm),
        ActualDurationSec = COALESCE(@ActualDurationSec, EstimatedTripDurationSec),
        ActualFare = @FinalFare,
        PlatformEarnings = @FinalFare,  -- 100% to platform for autonomous vehicles
        SimulationPhase = NULL
    WHERE AutonomousRideID = @AutonomousRideID;
    
    -- Make vehicle available again
    UPDATE dbo.[AutonomousVehicle]
    SET Status = 'available',
        UpdatedAt = SYSDATETIME()
    WHERE AutonomousVehicleID = @VehicleID;
    
    -- Create payment record
    INSERT INTO dbo.[AutonomousRidePayment] (
        AutonomousRideID,
        PaymentMethodTypeID,
        Amount,
        CurrencyCode,
        Status,
        CreatedAt,
        CompletedAt,
        BaseFare,
        DistanceFare,
        ServiceFeeRate,
        ServiceFeeAmount,
        DistanceKm,
        DurationMinutes
    )
    VALUES (
        @AutonomousRideID,
        @PaymentMethodTypeID,
        @FinalFare,
        'EUR',
        'completed',
        SYSDATETIME(),
        SYSDATETIME(),
        3.00,
        @FinalFare - 3.00,
        0.0000,  -- No driver, so no service fee split
        0.00,
        @ActualDistanceKm,
        CAST(@ActualDurationSec AS DECIMAL) / 60.0
    );
    
    SELECT @AutonomousRideID AS AutonomousRideID, 
           'completed' AS Status,
           @FinalFare AS ActualFare;
END
GO

-- ============================================================
-- Get passenger's autonomous ride history
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerAutonomousRides
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        av.Color,
        av.PlateNo,
        ar.Status,
        ar.RequestedAt,
        ar.TripCompletedAt,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        pl.StreetAddress AS PickupAddress,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription,
        dl.StreetAddress AS DropoffAddress,
        ar.EstimatedFare,
        ar.ActualFare,
        ar.ActualDistanceKm,
        ar.ActualDurationSec,
        pmt.Code AS PaymentMethodCode
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    INNER JOIN dbo.[PaymentMethodType] pmt ON ar.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    WHERE ar.PassengerID = @PassengerID
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Get passenger's active autonomous ride
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerActiveAutonomousRide
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        ar.AutonomousRideID,
        ar.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        av.Color,
        av.PlateNo,
        av.CurrentLatitude AS VehicleCurrentLat,
        av.CurrentLongitude AS VehicleCurrentLng,
        ar.Status,
        ar.RequestedAt,
        ar.VehicleDispatchedAt,
        ar.VehicleArrivedAt,
        ar.TripStartedAt,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription,
        ar.EstimatedPickupDurationSec,
        ar.EstimatedTripDurationSec,
        ar.EstimatedFare
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE ar.PassengerID = @PassengerID
      AND ar.Status NOT IN ('completed', 'cancelled')
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Update autonomous vehicle location
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAutonomousVehicleLocation
    @AutonomousVehicleID INT,
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6),
    @AutonomousRideID INT = NULL,
    @Phase NVARCHAR(30) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Update vehicle current location
    UPDATE dbo.[AutonomousVehicle]
    SET CurrentLatitude = @Latitude,
        CurrentLongitude = @Longitude,
        LocationUpdatedAt = SYSDATETIME(),
        UpdatedAt = SYSDATETIME()
    WHERE AutonomousVehicleID = @AutonomousVehicleID;
    
    -- Record in history if ride is in progress
    IF @AutonomousRideID IS NOT NULL
    BEGIN
        INSERT INTO dbo.[AutonomousVehicleLocationHistory] (
            AutonomousVehicleID,
            AutonomousRideID,
            Latitude,
            Longitude,
            RecordedAt,
            Phase
        )
        VALUES (
            @AutonomousVehicleID,
            @AutonomousRideID,
            @Latitude,
            @Longitude,
            SYSDATETIME(),
            @Phase
        );
    END
    
    SELECT @AutonomousVehicleID AS AutonomousVehicleID, 
           @Latitude AS Latitude, 
           @Longitude AS Longitude;
END
GO

-- ============================================================
-- Get all autonomous rides (for operator)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAllAutonomousRides
    @StatusFilter NVARCHAR(30) = NULL,
    @DateFrom DATETIME2 = NULL,
    @DateTo DATETIME2 = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.PassengerID,
        u.FullName AS PassengerName,
        u.Phone AS PassengerPhone,
        ar.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        av.PlateNo,
        ar.Status,
        ar.RequestedAt,
        ar.VehicleDispatchedAt,
        ar.VehicleArrivedAt,
        ar.TripStartedAt,
        ar.TripCompletedAt,
        pl.Description AS PickupDescription,
        pl.StreetAddress AS PickupAddress,
        dl.Description AS DropoffDescription,
        dl.StreetAddress AS DropoffAddress,
        ar.EstimatedFare,
        ar.ActualFare,
        ar.ActualDistanceKm,
        ar.CancellationReason
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Passenger] p ON ar.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE (@StatusFilter IS NULL OR ar.Status = @StatusFilter)
      AND (@DateFrom IS NULL OR ar.RequestedAt >= @DateFrom)
      AND (@DateTo IS NULL OR ar.RequestedAt <= @DateTo)
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Get autonomous vehicle statistics (for operator dashboard)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousVehicleStats
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Vehicle stats
    SELECT 
        COUNT(*) AS TotalVehicles,
        SUM(CASE WHEN Status = 'available' AND IsActive = 1 THEN 1 ELSE 0 END) AS AvailableVehicles,
        SUM(CASE WHEN Status = 'busy' THEN 1 ELSE 0 END) AS BusyVehicles,
        SUM(CASE WHEN Status = 'maintenance' THEN 1 ELSE 0 END) AS MaintenanceVehicles,
        SUM(CASE WHEN Status = 'charging' THEN 1 ELSE 0 END) AS ChargingVehicles,
        SUM(CASE WHEN Status = 'offline' OR IsActive = 0 THEN 1 ELSE 0 END) AS OfflineVehicles
    FROM dbo.[AutonomousVehicle];
    
    -- Today's ride stats
    SELECT 
        COUNT(*) AS TodayTotalRides,
        SUM(CASE WHEN Status = 'completed' THEN 1 ELSE 0 END) AS TodayCompletedRides,
        SUM(CASE WHEN Status = 'cancelled' THEN 1 ELSE 0 END) AS TodayCancelledRides,
        SUM(CASE WHEN Status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS TodayActiveRides,
        COALESCE(SUM(ActualFare), 0) AS TodayRevenue
    FROM dbo.[AutonomousRide]
    WHERE CAST(RequestedAt AS DATE) = CAST(SYSDATETIME() AS DATE);
    
    -- All time stats
    SELECT 
        COUNT(*) AS TotalRides,
        SUM(CASE WHEN Status = 'completed' THEN 1 ELSE 0 END) AS CompletedRides,
        COALESCE(SUM(ActualFare), 0) AS TotalRevenue,
        COALESCE(AVG(ActualDistanceKm), 0) AS AvgDistanceKm,
        COALESCE(AVG(ActualDurationSec / 60.0), 0) AS AvgDurationMin
    FROM dbo.[AutonomousRide];
END
GO

-- ============================================================
-- Add/Update autonomous vehicle (for operator)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpsertAutonomousVehicle
    @AutonomousVehicleID INT = NULL,  -- NULL for insert, ID for update
    @VehicleCode NVARCHAR(50),
    @VehicleTypeID INT,
    @PlateNo NVARCHAR(20),
    @Make NVARCHAR(100) = NULL,
    @Model NVARCHAR(100) = NULL,
    @Year SMALLINT = NULL,
    @Color NVARCHAR(50) = NULL,
    @SeatingCapacity INT = 4,
    @IsWheelchairReady BIT = 0,
    @GeofenceID INT = NULL,
    @CurrentLatitude DECIMAL(9,6) = NULL,
    @CurrentLongitude DECIMAL(9,6) = NULL,
    @BatteryLevel INT = 100,
    @PhotoUrl NVARCHAR(500) = NULL,
    @IsActive BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @AutonomousVehicleID IS NULL
    BEGIN
        -- Insert new vehicle
        INSERT INTO dbo.[AutonomousVehicle] (
            VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color,
            SeatingCapacity, IsWheelchairReady, Status, GeofenceID,
            CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
            BatteryLevel, PhotoUrl, IsActive, CreatedAt, UpdatedAt
        )
        VALUES (
            @VehicleCode, @VehicleTypeID, @PlateNo, @Make, @Model, @Year, @Color,
            @SeatingCapacity, @IsWheelchairReady, 'available', @GeofenceID,
            @CurrentLatitude, @CurrentLongitude, 
            CASE WHEN @CurrentLatitude IS NOT NULL THEN SYSDATETIME() ELSE NULL END,
            @BatteryLevel, @PhotoUrl, @IsActive, SYSDATETIME(), SYSDATETIME()
        );
        
        SET @AutonomousVehicleID = SCOPE_IDENTITY();
    END
    ELSE
    BEGIN
        -- Update existing vehicle
        UPDATE dbo.[AutonomousVehicle]
        SET VehicleCode = @VehicleCode,
            VehicleTypeID = @VehicleTypeID,
            PlateNo = @PlateNo,
            Make = @Make,
            Model = @Model,
            Year = @Year,
            Color = @Color,
            SeatingCapacity = @SeatingCapacity,
            IsWheelchairReady = @IsWheelchairReady,
            GeofenceID = @GeofenceID,
            CurrentLatitude = COALESCE(@CurrentLatitude, CurrentLatitude),
            CurrentLongitude = COALESCE(@CurrentLongitude, CurrentLongitude),
            LocationUpdatedAt = CASE WHEN @CurrentLatitude IS NOT NULL THEN SYSDATETIME() ELSE LocationUpdatedAt END,
            BatteryLevel = @BatteryLevel,
            PhotoUrl = @PhotoUrl,
            IsActive = @IsActive,
            UpdatedAt = SYSDATETIME()
        WHERE AutonomousVehicleID = @AutonomousVehicleID;
    END
    
    SELECT @AutonomousVehicleID AS AutonomousVehicleID;
END
GO

-- ============================================================
-- Rate autonomous ride
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spRateAutonomousRide
    @AutonomousRideID INT,
    @PassengerID INT,
    @Stars INT,
    @Comment NVARCHAR(1000) = NULL,
    @ComfortRating INT = NULL,
    @SafetyRating INT = NULL,
    @CleanlinessRating INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Verify the ride belongs to this passenger and is completed
    IF NOT EXISTS (
        SELECT 1 FROM dbo.[AutonomousRide]
        WHERE AutonomousRideID = @AutonomousRideID
          AND PassengerID = @PassengerID
          AND Status = 'completed'
    )
    BEGIN
        RAISERROR('Cannot rate this ride.', 16, 1);
        RETURN;
    END
    
    -- Check if already rated
    IF EXISTS (
        SELECT 1 FROM dbo.[AutonomousRideRating]
        WHERE AutonomousRideID = @AutonomousRideID
    )
    BEGIN
        RAISERROR('You have already rated this ride.', 16, 1);
        RETURN;
    END
    
    INSERT INTO dbo.[AutonomousRideRating] (
        AutonomousRideID, PassengerID, Stars, Comment,
        ComfortRating, SafetyRating, CleanlinessRating, CreatedAt
    )
    VALUES (
        @AutonomousRideID, @PassengerID, @Stars, @Comment,
        @ComfortRating, @SafetyRating, @CleanlinessRating, SYSDATETIME()
    );
    
    SELECT SCOPE_IDENTITY() AS RatingID;
END
GO

-- ============================================================
-- Get autonomous ride simulation data (for position calculation)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousRideSimulationData
    @AutonomousRideID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.Status,
        ar.SimulationPhase,
        ar.SimulationStartTime,
        ar.SimulationSpeedMultiplier,
        ar.AccumulatedSimulatedSeconds,
        ar.LastSpeedChangeAt,
        ar.VehicleStartLat,
        ar.VehicleStartLng,
        ar.PickupRouteGeometry,
        ar.TripRouteGeometry,
        ar.EstimatedPickupDurationSec,
        ar.EstimatedTripDurationSec,
        ar.VehicleDispatchedAt,
        ar.TripStartedAt,
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        av.AutonomousVehicleID,
        av.CurrentLatitude AS VehicleCurrentLat,
        av.CurrentLongitude AS VehicleCurrentLng
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    INNER JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    INNER JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE ar.AutonomousRideID = @AutonomousRideID;
END
GO

-- ============================================================
-- Update autonomous ride simulation speed
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAutonomousRideSpeed
    @AutonomousRideID INT,
    @NewSpeedMultiplier DECIMAL(4,2),
    @AccumulatedSeconds FLOAT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.[AutonomousRide]
    SET SimulationSpeedMultiplier = @NewSpeedMultiplier,
        AccumulatedSimulatedSeconds = @AccumulatedSeconds,
        LastSpeedChangeAt = SYSDATETIME()
    WHERE AutonomousRideID = @AutonomousRideID;
    
    SELECT @AutonomousRideID AS AutonomousRideID;
END
GO

-- ============================================================
-- Get autonomous ride rating
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousRideRating
    @AutonomousRideID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        RatingID,
        AutonomousRideID,
        PassengerID,
        Stars,
        Comment,
        ComfortRating,
        SafetyRating,
        CleanlinessRating,
        CreatedAt
    FROM dbo.[AutonomousRideRating]
    WHERE AutonomousRideID = @AutonomousRideID;
END
GO

-- ============================================================
-- Update autonomous vehicle status (for operator actions)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAutonomousVehicleStatus
    @AutonomousVehicleID INT,
    @NewStatus NVARCHAR(30)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate status
    IF @NewStatus NOT IN ('available', 'busy', 'maintenance', 'offline', 'charging')
    BEGIN
        RAISERROR('Invalid vehicle status. Must be: available, busy, maintenance, offline, or charging.', 16, 1);
        RETURN;
    END
    
    -- Check if vehicle exists
    IF NOT EXISTS (SELECT 1 FROM dbo.[AutonomousVehicle] WHERE AutonomousVehicleID = @AutonomousVehicleID)
    BEGIN
        RAISERROR('Vehicle not found.', 16, 1);
        RETURN;
    END
    
    -- Don't allow changing status to available if vehicle is currently busy with an active ride
    IF @NewStatus = 'available'
    BEGIN
        DECLARE @CurrentStatus NVARCHAR(30);
        SELECT @CurrentStatus = Status FROM dbo.[AutonomousVehicle] WHERE AutonomousVehicleID = @AutonomousVehicleID;
        
        -- Only check for active rides if the vehicle is currently marked as 'busy'
        IF @CurrentStatus = 'busy' AND EXISTS (
            SELECT 1 FROM dbo.[AutonomousRide] 
            WHERE AutonomousVehicleID = @AutonomousVehicleID 
            AND Status NOT IN ('completed', 'cancelled')
        )
        BEGIN
            RAISERROR('Cannot set vehicle to available while it has an active ride.', 16, 1);
            RETURN;
        END
    END
    
    UPDATE dbo.[AutonomousVehicle]
    SET Status = @NewStatus,
        UpdatedAt = SYSDATETIME()
    WHERE AutonomousVehicleID = @AutonomousVehicleID;
    
    SELECT @AutonomousVehicleID AS AutonomousVehicleID, @NewStatus AS Status;
END
GO

-- ============================================================
-- Get autonomous vehicle by ID (for detail page)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousVehicleById
    @AutonomousVehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        av.AutonomousVehicleID,
        av.VehicleCode,
        av.VehicleTypeID,
        av.PlateNo,
        av.Make,
        av.Model,
        av.Year,
        av.Color,
        av.SeatingCapacity,
        av.IsWheelchairReady,
        av.Status,
        av.CurrentLatitude,
        av.CurrentLongitude,
        av.LocationUpdatedAt,
        av.GeofenceID,
        av.BatteryLevel,
        av.PhotoUrl,
        av.IsActive,
        av.CreatedAt,
        av.UpdatedAt,
        vt.Name AS VehicleTypeName,
        g.Name AS GeofenceName,
        -- Stats
        (SELECT COUNT(*) FROM dbo.[AutonomousRide] ar WHERE ar.AutonomousVehicleID = av.AutonomousVehicleID) AS TotalRides,
        (SELECT COUNT(*) FROM dbo.[AutonomousRide] ar WHERE ar.AutonomousVehicleID = av.AutonomousVehicleID AND ar.Status = 'completed') AS CompletedRides,
        (SELECT AVG(CAST(arr.Stars AS FLOAT)) FROM dbo.[AutonomousRideRating] arr 
         INNER JOIN dbo.[AutonomousRide] ar2 ON arr.AutonomousRideID = ar2.AutonomousRideID 
         WHERE ar2.AutonomousVehicleID = av.AutonomousVehicleID) AS AverageRating
    FROM dbo.[AutonomousVehicle] av
    LEFT JOIN dbo.[VehicleType] vt ON av.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.[Geofence] g ON av.GeofenceID = g.GeofenceID
    WHERE av.AutonomousVehicleID = @AutonomousVehicleID;
END
GO

-- ============================================================
-- Get autonomous ride details by ID (for operator detail page)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousRideById
    @AutonomousRideID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.PassengerID,
        ar.AutonomousVehicleID,
        ar.PickupLocationID,
        ar.DropoffLocationID,
        ar.RequestedAt,
        ar.PassengerNotes,
        ar.WheelchairNeeded,
        ar.Status,
        ar.VehicleDispatchedAt,
        ar.VehicleArrivedAt,
        ar.PassengerBoardedAt,
        ar.TripStartedAt,
        ar.TripCompletedAt,
        ar.EstimatedPickupDistanceKm,
        ar.EstimatedPickupDurationSec,
        ar.EstimatedTripDistanceKm,
        ar.EstimatedTripDurationSec,
        ar.ActualDistanceKm,
        ar.ActualDurationSec,
        ar.EstimatedFare,
        ar.ActualFare,
        ar.CancellationReason,
        ar.CancelledAt,
        -- Passenger info
        u.FullName AS PassengerName,
        u.Email AS PassengerEmail,
        u.Phone AS PassengerPhone,
        -- Vehicle info
        av.VehicleCode,
        av.Make AS VehicleMake,
        av.Model AS VehicleModel,
        av.PlateNo AS VehiclePlate,
        av.Color AS VehicleColor,
        av.BatteryLevel,
        av.Status AS VehicleStatus,
        -- Pickup location
        pl.LatDegrees AS PickupLat,
        pl.LonDegrees AS PickupLng,
        pl.Description AS PickupDescription,
        pl.StreetAddress AS PickupAddress,
        -- Dropoff location
        dl.LatDegrees AS DropoffLat,
        dl.LonDegrees AS DropoffLng,
        dl.Description AS DropoffDescription,
        dl.StreetAddress AS DropoffAddress,
        -- Payment
        pmt.Code AS PaymentMethodType,
        -- Rating
        arr.Stars AS Rating,
        arr.Comment AS RatingComment,
        arr.CreatedAt AS RatingCreatedAt
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[Passenger] p ON ar.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    LEFT JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    LEFT JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    LEFT JOIN dbo.[PaymentMethodType] pmt ON ar.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    LEFT JOIN dbo.[AutonomousRideRating] arr ON ar.AutonomousRideID = arr.AutonomousRideID
    WHERE ar.AutonomousRideID = @AutonomousRideID;
END
GO

-- ============================================================
-- Get recent rides for a vehicle (for vehicle detail page)
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetVehicleRecentRides
    @AutonomousVehicleID INT,
    @Limit INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@Limit)
        ar.AutonomousRideID,
        ar.Status,
        ar.RequestedAt,
        ar.TripCompletedAt,
        ar.EstimatedFare,
        ar.ActualFare,
        u.FullName AS PassengerName,
        pl.Description AS PickupDescription,
        pl.StreetAddress AS PickupAddress,
        dl.Description AS DropoffDescription,
        dl.StreetAddress AS DropoffAddress,
        arr.Stars AS Rating
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[Passenger] p ON ar.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    LEFT JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    LEFT JOIN dbo.[AutonomousRideRating] arr ON ar.AutonomousRideID = arr.AutonomousRideID
    WHERE ar.AutonomousVehicleID = @AutonomousVehicleID
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Get AV Financial Summary for date range
-- Used by operator/financial_reports.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAVFinancialSummary
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        COUNT(*) AS TotalPayments,
        ISNULL(SUM(arp.Amount), 0) AS TotalRevenue,
        ISNULL(SUM(arp.ServiceFeeAmount), 0) AS ServiceFees,
        ISNULL(AVG(arp.Amount), 0) AS AvgFare,
        (SELECT COUNT(*) FROM dbo.[AutonomousRide] 
         WHERE Status = 'completed' 
         AND CAST(TripCompletedAt AS DATE) BETWEEN @StartDate AND @EndDate) AS TotalRides
    FROM dbo.[AutonomousRidePayment] arp
    INNER JOIN dbo.[AutonomousRide] ar ON arp.AutonomousRideID = ar.AutonomousRideID
    WHERE arp.Status = 'completed'
    AND CAST(arp.CompletedAt AS DATE) BETWEEN @StartDate AND @EndDate;
END
GO

-- ============================================================
-- Get Top Autonomous Vehicles by Revenue
-- Used by operator/financial_reports.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetTopAutonomousVehicles
    @StartDate DATE,
    @EndDate DATE,
    @Limit INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@Limit)
        av.AutonomousVehicleID,
        av.VehicleCode,
        av.Make,
        av.Model,
        COUNT(ar.AutonomousRideID) AS TripCount,
        ISNULL(SUM(arp.Amount), 0) AS GrossEarnings,
        ISNULL(SUM(arp.ServiceFeeAmount), 0) AS ServiceFees
    FROM dbo.[AutonomousVehicle] av
    LEFT JOIN dbo.[AutonomousRide] ar ON av.AutonomousVehicleID = ar.AutonomousVehicleID 
        AND ar.Status = 'completed'
        AND CAST(ar.TripCompletedAt AS DATE) BETWEEN @StartDate AND @EndDate
    LEFT JOIN dbo.[AutonomousRidePayment] arp ON ar.AutonomousRideID = arp.AutonomousRideID 
        AND arp.Status = 'completed'
    GROUP BY av.AutonomousVehicleID, av.VehicleCode, av.Make, av.Model
    ORDER BY GrossEarnings DESC;
END
GO

-- ============================================================
-- Get Active AV Rides Count
-- Used by operator/dashboard.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetActiveAVRidesCount
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT COUNT(*) AS ActiveRides 
    FROM dbo.[AutonomousRide] 
    WHERE Status NOT IN ('completed', 'cancelled');
END
GO

-- ============================================================
-- Get Active Drivers Count
-- Used by operator/dashboard.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetActiveDriversCount
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT COUNT(*) AS Total 
    FROM dbo.[Driver] d 
    INNER JOIN dbo.[User] u ON d.UserID = u.UserID 
    WHERE u.Status = 'active';
END
GO

-- ============================================================
-- Get AV Hub Statistics (today's rides, active rides)
-- Used by operator/autonomous_hub.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAVHubStats
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        COUNT(*) AS TotalToday,
        SUM(CASE WHEN Status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS ActiveRides
    FROM dbo.[AutonomousRide] 
    WHERE CAST(RequestedAt AS DATE) = CAST(GETDATE() AS DATE);
END
GO

-- ============================================================
-- Get All Autonomous Rides with Filters
-- Used by operator/autonomous_rides.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAllAutonomousRidesFiltered
    @StatusFilter NVARCHAR(30) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID,
        ar.Status,
        pl.StreetAddress AS PickupAddress,
        dl.StreetAddress AS DropoffAddress,
        ar.EstimatedFare,
        ar.ActualFare,
        ar.EstimatedTripDurationSec AS EstimatedDuration,
        ar.EstimatedTripDistanceKm AS EstimatedDistance,
        ar.RequestedAt,
        ar.TripStartedAt AS StartedAt,
        ar.TripCompletedAt AS CompletedAt,
        u.FullName AS PassengerName,
        u.Email AS PassengerEmail,
        av.Make,
        av.Model,
        av.PlateNo,
        av.VehicleCode
    FROM dbo.[AutonomousRide] ar
    INNER JOIN dbo.[Passenger] p ON ar.PassengerID = p.PassengerID
    INNER JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    LEFT JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    LEFT JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE (@StatusFilter IS NULL OR @StatusFilter = '' OR ar.Status = @StatusFilter)
      AND (@DateFrom IS NULL OR CAST(ar.RequestedAt AS DATE) >= @DateFrom)
      AND (@DateTo IS NULL OR CAST(ar.RequestedAt AS DATE) <= @DateTo)
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Get Drivers Hub Statistics
-- Used by operator/drivers_hub.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetDriversHubStats
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Return multiple result sets
    -- Result 1: Driver counts
    SELECT 
        COUNT(*) AS TotalDrivers,
        SUM(CASE WHEN d.VerificationStatus = 'approved' THEN 1 ELSE 0 END) AS VerifiedDrivers
    FROM dbo.[Driver] d
    INNER JOIN dbo.[User] u ON d.UserID = u.UserID
    WHERE u.Status = 'active';
    
    -- Result 2: Today's trip count
    SELECT COUNT(*) AS TodayTrips
    FROM dbo.[Trip] 
    WHERE CAST(DispatchTime AS DATE) = CAST(GETDATE() AS DATE);
END
GO

-- ============================================================
-- Get Passenger Dashboard Statistics
-- Used by passenger/dashboard.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerDashboardStats
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        -- Driver trips (Trip links through RideRequest to Passenger)
        (SELECT COUNT(*) FROM dbo.[Trip] t 
         INNER JOIN dbo.[RideRequest] rr ON t.RideRequestID = rr.RideRequestID 
         WHERE rr.PassengerID = @PassengerID) AS DriverTrips,
        (SELECT COUNT(*) FROM dbo.[Trip] t 
         INNER JOIN dbo.[RideRequest] rr ON t.RideRequestID = rr.RideRequestID 
         WHERE rr.PassengerID = @PassengerID 
         AND t.Status NOT IN ('completed', 'cancelled')) AS ActiveDriverTrips,
        -- Autonomous rides
        (SELECT COUNT(*) FROM dbo.[AutonomousRide] WHERE PassengerID = @PassengerID) AS AVRides,
        (SELECT COUNT(*) FROM dbo.[AutonomousRide] WHERE PassengerID = @PassengerID 
         AND Status NOT IN ('completed', 'cancelled')) AS ActiveAVRides;
END
GO

-- ============================================================
-- Get Passenger Autonomous Ride Payments
-- Used by passenger/payments.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetPassengerAVPayments
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ar.AutonomousRideID AS RideID,
        pl.StreetAddress AS PickupAddress,
        dl.StreetAddress AS DropoffAddress,
        ar.Status AS RideStatus,
        ar.TripCompletedAt AS CompletedAt,
        ar.RequestedAt,
        arp.PaymentID,
        arp.Amount AS TotalAmount,
        arp.Status AS PaymentStatus,
        arp.BaseFare,
        arp.DistanceFare,
        arp.TimeFare,
        arp.ServiceFeeAmount,
        arp.DistanceKm,
        arp.DurationMinutes,
        arp.CreatedAt AS PaymentCreatedAt,
        pmt.Code AS PaymentMethod,
        av.VehicleCode,
        av.Make AS VehicleMake,
        av.Model AS VehicleModel
    FROM dbo.[AutonomousRide] ar
    LEFT JOIN dbo.[AutonomousRidePayment] arp ON ar.AutonomousRideID = arp.AutonomousRideID
    LEFT JOIN dbo.[PaymentMethodType] pmt ON arp.PaymentMethodTypeID = pmt.PaymentMethodTypeID
    LEFT JOIN dbo.[AutonomousVehicle] av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    LEFT JOIN dbo.[Location] pl ON ar.PickupLocationID = pl.LocationID
    LEFT JOIN dbo.[Location] dl ON ar.DropoffLocationID = dl.LocationID
    WHERE ar.PassengerID = @PassengerID
    ORDER BY ar.RequestedAt DESC;
END
GO

-- ============================================================
-- Update AV Ride Phase (for simulation)
-- Used by api/autonomous_ride_position.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAVRidePhase
    @AutonomousRideID INT,
    @Phase NVARCHAR(20),
    @ResetAccumulatedTime BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.[AutonomousRide]
    SET SimulationPhase = @Phase,
        AccumulatedSimulatedSeconds = CASE WHEN @ResetAccumulatedTime = 1 THEN 0 ELSE AccumulatedSimulatedSeconds END,
        LastSpeedChangeAt = GETDATE(),
        TripStartedAt = CASE WHEN @Phase = 'trip' THEN GETDATE() ELSE TripStartedAt END
    WHERE AutonomousRideID = @AutonomousRideID;
    
    SELECT @AutonomousRideID AS AutonomousRideID, @Phase AS Phase;
END
GO

-- ============================================================
-- Get Autonomous Ride Payment by Ride ID
-- Used by passenger/autonomous_ride_detail.php
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spGetAutonomousRidePayment
    @AutonomousRideID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT * FROM dbo.AutonomousRidePayment 
    WHERE AutonomousRideID = @AutonomousRideID;
END
GO

-- ============================================================
-- Update Autonomous Ride Speed Multiplier
-- Used by api/autonomous_ride_position.php for simulation speed control
-- ============================================================
CREATE OR ALTER PROCEDURE dbo.spUpdateAVRideSpeed
    @AutonomousRideID INT,
    @NewSpeed DECIMAL(4,2),
    @BaseSpeedFactor DECIMAL(4,2) = 1.0
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.[AutonomousRide]
    SET SimulationSpeedMultiplier = @NewSpeed,
        AccumulatedSimulatedSeconds = ISNULL(AccumulatedSimulatedSeconds, 0) + 
            (DATEDIFF(SECOND, 
                COALESCE(LastSpeedChangeAt, VehicleDispatchedAt, SimulationStartTime, RequestedAt), 
                GETDATE()
            ) * @BaseSpeedFactor * ISNULL(SimulationSpeedMultiplier, 1)),
        LastSpeedChangeAt = GETDATE()
    WHERE AutonomousRideID = @AutonomousRideID;
    
    SELECT @AutonomousRideID AS AutonomousRideID, @NewSpeed AS Speed;
END
GO


/* ============================================================
   CARSHARE SYSTEM - STORED PROCEDURES
   ============================================================ */


/* ============================================================
   A. CUSTOMER MANAGEMENT
   ============================================================ */

-- Register a new carshare customer (extends existing passenger)
CREATE OR ALTER PROCEDURE dbo.spCarshareRegisterCustomer
    @PassengerID        INT,
    @LicenseNumber      NVARCHAR(50),
    @LicenseCountry     NVARCHAR(100) = 'Cyprus',
    @LicenseIssueDate   DATE,
    @LicenseExpiryDate  DATE,
    @DateOfBirth        DATE,
    @NationalID         NVARCHAR(50) = NULL,
    @PreferredLanguage  NVARCHAR(10) = 'en',
    @LicensePhotoUrl    NVARCHAR(500) = NULL,
    @NationalIDPhotoUrl NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate passenger exists
    IF NOT EXISTS (SELECT 1 FROM dbo.Passenger WHERE PassengerID = @PassengerID)
    BEGIN
        RAISERROR('Passenger not found.', 16, 1);
        RETURN;
    END
    
    -- Check if already registered
    IF EXISTS (SELECT 1 FROM dbo.CarshareCustomer WHERE PassengerID = @PassengerID)
    BEGIN
        RAISERROR('Customer already registered for car-sharing.', 16, 1);
        RETURN;
    END
    
    -- Validate license not expired
    IF @LicenseExpiryDate < GETDATE()
    BEGIN
        RAISERROR('Driver license has expired.', 16, 1);
        RETURN;
    END
    
    -- Calculate age
    DECLARE @Age INT = DATEDIFF(YEAR, @DateOfBirth, GETDATE());
    IF @Age < 18
    BEGIN
        RAISERROR('Customer must be at least 18 years old.', 16, 1);
        RETURN;
    END
    
    -- Insert customer
    INSERT INTO dbo.CarshareCustomer (
        PassengerID, LicenseNumber, LicenseCountry, LicenseIssueDate,
        LicenseExpiryDate, DateOfBirth, NationalID, PreferredLanguage,
        LicensePhotoUrl, NationalIDPhotoUrl, VerificationStatus
    )
    VALUES (
        @PassengerID, @LicenseNumber, @LicenseCountry, @LicenseIssueDate,
        @LicenseExpiryDate, @DateOfBirth, @NationalID, @PreferredLanguage,
        @LicensePhotoUrl, @NationalIDPhotoUrl, 'documents_submitted'
    );
    
    DECLARE @CustomerID INT = SCOPE_IDENTITY();
    
    -- Log the registration
    INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, CustomerID)
    VALUES ('info', 'customer', 'New carshare customer registered', @CustomerID);
    
    SELECT @CustomerID AS CustomerID, 'documents_submitted' AS VerificationStatus;
END
GO

-- Verify carshare customer (operator action)
CREATE OR ALTER PROCEDURE dbo.spCarshareVerifyCustomer
    @CustomerID         INT,
    @OperatorID         INT,
    @Approved           BIT,
    @Notes              NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF NOT EXISTS (SELECT 1 FROM dbo.CarshareCustomer WHERE CustomerID = @CustomerID)
    BEGIN
        RAISERROR('Customer not found.', 16, 1);
        RETURN;
    END
    
    DECLARE @NewStatus NVARCHAR(50) = CASE WHEN @Approved = 1 THEN 'approved' ELSE 'rejected' END;
    
    UPDATE dbo.CarshareCustomer
    SET VerificationStatus = @NewStatus,
        VerificationNotes = @Notes,
        LicenseVerified = @Approved,
        LicenseVerifiedAt = CASE WHEN @Approved = 1 THEN SYSDATETIME() ELSE NULL END,
        LicenseVerifiedBy = @OperatorID,
        UpdatedAt = SYSDATETIME()
    WHERE CustomerID = @CustomerID;
    
    INSERT INTO dbo.CarshareAuditLog (TableName, RecordID, Action, NewValues, ChangedBy)
    VALUES ('CarshareCustomer', @CustomerID, 'UPDATE', 
            '{"VerificationStatus":"' + @NewStatus + '","VerifiedBy":' + CAST(@OperatorID AS NVARCHAR) + '}',
            (SELECT u.UserID FROM dbo.Operator o JOIN dbo.[User] u ON o.UserID = u.UserID WHERE o.OperatorID = @OperatorID));
    
    SELECT @CustomerID AS CustomerID, @NewStatus AS VerificationStatus;
END
GO

-- Get customer details
CREATE OR ALTER PROCEDURE dbo.spCarshareGetCustomer
    @CustomerID         INT = NULL,
    @PassengerID        INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        cc.CustomerID,
        cc.PassengerID,
        u.FullName,
        u.Email,
        u.Phone,
        cc.LicenseNumber,
        cc.LicenseCountry,
        cc.LicenseIssueDate,
        cc.LicenseExpiryDate,
        cc.LicenseVerified,
        cc.DateOfBirth,
        DATEDIFF(YEAR, cc.DateOfBirth, GETDATE()) AS Age,
        cc.VerificationStatus,
        cc.HasValidPaymentMethod,
        cc.TotalRentals,
        cc.TotalDistanceKm,
        cc.TotalSpentEUR,
        cc.LoyaltyPoints,
        cc.MembershipTier,
        cc.LastRentalAt,
        cc.CreatedAt
    FROM dbo.CarshareCustomer cc
    JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    JOIN dbo.[User] u ON p.UserID = u.UserID
    WHERE (@CustomerID IS NULL OR cc.CustomerID = @CustomerID)
      AND (@PassengerID IS NULL OR cc.PassengerID = @PassengerID);
END
GO

/* ============================================================
   B. ZONE MANAGEMENT
   ============================================================ */

-- Get all active zones
CREATE OR ALTER PROCEDURE dbo.spCarshareGetZones
    @City               NVARCHAR(100) = NULL,
    @ZoneType           NVARCHAR(50) = NULL,
    @IncludeVehicleCounts BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        z.ZoneID,
        z.ZoneName,
        z.ZoneType,
        z.Description,
        z.CenterLatitude,
        z.CenterLongitude,
        z.RadiusMeters,
        z.City,
        z.District,
        z.MaxCapacity,
        z.CurrentVehicleCount,
        z.InterCityFee,
        z.BonusAmount,
        z.OperatingHoursStart,
        z.OperatingHoursEnd,
        CASE 
            WHEN @IncludeVehicleCounts = 1 THEN
                (SELECT COUNT(*) FROM dbo.CarshareVehicle v 
                 WHERE v.CurrentZoneID = z.ZoneID AND v.Status = 'available')
            ELSE NULL
        END AS AvailableVehicles
    FROM dbo.CarshareZone z
    WHERE z.IsActive = 1
      AND (@City IS NULL OR z.City = @City)
      AND (@ZoneType IS NULL OR z.ZoneType = @ZoneType)
    ORDER BY z.City, z.ZoneName;
END
GO

-- Check if location is within a zone
CREATE OR ALTER FUNCTION dbo.fnCarshareGetZoneForLocation
(
    @Latitude   DECIMAL(9,6),
    @Longitude  DECIMAL(9,6)
)
RETURNS INT
AS
BEGIN
    DECLARE @ZoneID INT = NULL;
    
    -- Simple circular radius check
    SELECT TOP 1 @ZoneID = z.ZoneID
    FROM dbo.CarshareZone z
    WHERE z.IsActive = 1
      AND (
          -- Haversine distance approximation in meters
          6371000 * 2 * ATN2(
              SQRT(
                  POWER(SIN(RADIANS(@Latitude - z.CenterLatitude) / 2), 2) +
                  COS(RADIANS(z.CenterLatitude)) * COS(RADIANS(@Latitude)) *
                  POWER(SIN(RADIANS(@Longitude - z.CenterLongitude) / 2), 2)
              ),
              SQRT(1 - (
                  POWER(SIN(RADIANS(@Latitude - z.CenterLatitude) / 2), 2) +
                  COS(RADIANS(z.CenterLatitude)) * COS(RADIANS(@Latitude)) *
                  POWER(SIN(RADIANS(@Longitude - z.CenterLongitude) / 2), 2)
              ))
          ) <= z.RadiusMeters
      )
    ORDER BY 
        -- Order by distance to center
        POWER(@Latitude - z.CenterLatitude, 2) + POWER(@Longitude - z.CenterLongitude, 2);
    
    RETURN @ZoneID;
END
GO

/* ============================================================
   C. VEHICLE SEARCH & AVAILABILITY
   ============================================================ */

-- Search for available vehicles
CREATE OR ALTER PROCEDURE dbo.spCarshareSearchVehicles
    @Latitude           DECIMAL(9,6) = NULL,
    @Longitude          DECIMAL(9,6) = NULL,
    @RadiusKm           DECIMAL(5,2) = 5.0,
    @ZoneID             INT = NULL,
    @VehicleTypeID      INT = NULL,
    @IsElectric         BIT = NULL,
    @MinSeats           INT = NULL,
    @CustomerID         INT = NULL          -- To check eligibility
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get customer age if provided
    DECLARE @CustomerAge INT = NULL;
    DECLARE @LicenseYears INT = NULL;
    
    IF @CustomerID IS NOT NULL
    BEGIN
        SELECT 
            @CustomerAge = DATEDIFF(YEAR, DateOfBirth, GETDATE()),
            @LicenseYears = DATEDIFF(YEAR, LicenseIssueDate, GETDATE())
        FROM dbo.CarshareCustomer
        WHERE CustomerID = @CustomerID;
    END
    
    SELECT 
        v.VehicleID,
        v.PlateNumber,
        v.Make,
        v.Model,
        v.Year,
        v.Color,
        vt.TypeCode,
        vt.TypeName,
        vt.SeatingCapacity,
        vt.IsElectric,
        vt.IsHybrid,
        v.FuelLevelPercent,
        v.BatteryLevelPercent,
        v.HasBluetooth,
        v.HasUSBCharger,
        v.HasChildSeat,
        v.CleanlinessRating,
        v.CurrentLatitude,
        v.CurrentLongitude,
        z.ZoneID,
        z.ZoneName,
        z.City,
        -- Pricing
        COALESCE(v.PricePerMinuteOverride, vt.PricePerMinute) AS PricePerMinute,
        COALESCE(v.PricePerHourOverride, vt.PricePerHour) AS PricePerHour,
        vt.PricePerDay,
        COALESCE(v.PricePerKmOverride, vt.PricePerKm) AS PricePerKm,
        vt.MinimumRentalFee,
        vt.DepositAmount,
        -- Distance from search point
        CASE 
            WHEN @Latitude IS NOT NULL AND @Longitude IS NOT NULL THEN
                ROUND(
                    6371 * 2 * ATN2(
                        SQRT(
                            POWER(SIN(RADIANS(v.CurrentLatitude - @Latitude) / 2), 2) +
                            COS(RADIANS(@Latitude)) * COS(RADIANS(v.CurrentLatitude)) *
                            POWER(SIN(RADIANS(v.CurrentLongitude - @Longitude) / 2), 2)
                        ),
                        SQRT(1 - (
                            POWER(SIN(RADIANS(v.CurrentLatitude - @Latitude) / 2), 2) +
                            COS(RADIANS(@Latitude)) * COS(RADIANS(v.CurrentLatitude)) *
                            POWER(SIN(RADIANS(v.CurrentLongitude - @Longitude) / 2), 2)
                        ))
                    ), 2)
            ELSE NULL
        END AS DistanceKm,
        -- Eligibility check
        CASE 
            WHEN @CustomerAge IS NOT NULL AND @CustomerAge < vt.MinDriverAge THEN 0
            WHEN @LicenseYears IS NOT NULL AND @LicenseYears < vt.MinLicenseYears THEN 0
            ELSE 1
        END AS IsEligible,
        CASE 
            WHEN @CustomerAge IS NOT NULL AND @CustomerAge < vt.MinDriverAge 
                THEN 'Minimum age ' + CAST(vt.MinDriverAge AS NVARCHAR) + ' required'
            WHEN @LicenseYears IS NOT NULL AND @LicenseYears < vt.MinLicenseYears 
                THEN 'Minimum ' + CAST(vt.MinLicenseYears AS NVARCHAR) + ' years license required'
            ELSE NULL
        END AS EligibilityMessage
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone z ON v.CurrentZoneID = z.ZoneID
        WHERE v.Status = 'available'
            -- Exclude vehicles that already have an active/reserved booking to prevent double booking
            AND NOT EXISTS (
                    SELECT 1 FROM dbo.CarshareBooking b
                    WHERE b.VehicleID = v.VehicleID
                        AND b.Status IN ('reserved', 'active')
            )
      AND v.IsActive = 1
      AND vt.IsActive = 1
      AND (@ZoneID IS NULL OR v.CurrentZoneID = @ZoneID)
      AND (@VehicleTypeID IS NULL OR v.VehicleTypeID = @VehicleTypeID)
      AND (@IsElectric IS NULL OR vt.IsElectric = @IsElectric)
      AND (@MinSeats IS NULL OR vt.SeatingCapacity >= @MinSeats)
      AND (
          @Latitude IS NULL OR @Longitude IS NULL OR
          -- Within radius
          6371 * 2 * ATN2(
              SQRT(
                  POWER(SIN(RADIANS(v.CurrentLatitude - @Latitude) / 2), 2) +
                  COS(RADIANS(@Latitude)) * COS(RADIANS(v.CurrentLatitude)) *
                  POWER(SIN(RADIANS(v.CurrentLongitude - @Longitude) / 2), 2)
              ),
              SQRT(1 - (
                  POWER(SIN(RADIANS(v.CurrentLatitude - @Latitude) / 2), 2) +
                  COS(RADIANS(@Latitude)) * COS(RADIANS(v.CurrentLatitude)) *
                  POWER(SIN(RADIANS(v.CurrentLongitude - @Longitude) / 2), 2)
              ))
          ) <= @RadiusKm
      )
    ORDER BY 
        CASE WHEN @Latitude IS NOT NULL AND @Longitude IS NOT NULL THEN
            POWER(v.CurrentLatitude - @Latitude, 2) + POWER(v.CurrentLongitude - @Longitude, 2)
        ELSE 0 END,
        v.FuelLevelPercent DESC;
END
GO

-- Get vehicle details
CREATE OR ALTER PROCEDURE dbo.spCarshareGetVehicleDetails
    @VehicleID          INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        v.VehicleID,
        v.PlateNumber,
        v.Make,
        v.Model,
        v.Year,
        v.Color,
        v.VIN,
        v.Status,
        v.CurrentLatitude,
        v.CurrentLongitude,
        v.LocationUpdatedAt,
        v.FuelLevelPercent,
        v.BatteryLevelPercent,
        v.OdometerKm,
        v.CleanlinessRating,
        v.LastCleanedAt,
        v.LastInspectedAt,
        v.HasGPS,
        v.HasBluetooth,
        v.HasUSBCharger,
        v.HasChildSeat,
        v.HasRoofRack,
        vt.TypeCode,
        vt.TypeName,
        vt.Description AS TypeDescription,
        vt.SeatingCapacity,
        vt.HasAutomaticTrans,
        vt.HasAirCon,
        vt.CargoVolumeM3,
        vt.IsElectric,
        vt.IsHybrid,
        vt.MinDriverAge,
        vt.MinLicenseYears,
        COALESCE(v.PricePerMinuteOverride, vt.PricePerMinute) AS PricePerMinute,
        COALESCE(v.PricePerHourOverride, vt.PricePerHour) AS PricePerHour,
        vt.PricePerDay,
        COALESCE(v.PricePerKmOverride, vt.PricePerKm) AS PricePerKm,
        vt.MinimumRentalFee,
        vt.DepositAmount,
        z.ZoneID,
        z.ZoneName,
        z.City,
        z.District
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone z ON v.CurrentZoneID = z.ZoneID
    WHERE v.VehicleID = @VehicleID;
END
GO

/* ============================================================
   D. BOOKING MANAGEMENT
   ============================================================ */

-- Create a booking (reserve a vehicle)
CREATE OR ALTER PROCEDURE dbo.spCarshareCreateBooking
    @CustomerID         INT,
    @VehicleID          INT,
    @PricingMode        NVARCHAR(20) = 'per_minute',
    @EstimatedDurationMin INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Validate customer
        DECLARE @VerificationStatus NVARCHAR(50);
        DECLARE @HasPayment BIT;
        
        SELECT @VerificationStatus = VerificationStatus, @HasPayment = HasValidPaymentMethod
        FROM dbo.CarshareCustomer
        WHERE CustomerID = @CustomerID;
        
        IF @VerificationStatus IS NULL
        BEGIN
            RAISERROR('Customer not found.', 16, 1);
            RETURN;
        END
        
        IF @VerificationStatus != 'approved'
        BEGIN
            RAISERROR('Customer not approved for car-sharing. Current status: %s', 16, 1, @VerificationStatus);
            RETURN;
        END
        
        -- Check vehicle availability
        DECLARE @VehicleStatus NVARCHAR(50);
        DECLARE @ZoneID INT;
        DECLARE @PickupLat DECIMAL(9,6);
        DECLARE @PickupLon DECIMAL(9,6);
        DECLARE @DepositAmount DECIMAL(10,2);
        
        SELECT 
            @VehicleStatus = v.Status,
            @ZoneID = v.CurrentZoneID,
            @PickupLat = v.CurrentLatitude,
            @PickupLon = v.CurrentLongitude,
            @DepositAmount = vt.DepositAmount
        FROM dbo.CarshareVehicle v
        JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
        WHERE v.VehicleID = @VehicleID AND v.IsActive = 1;
        
        IF @VehicleStatus IS NULL
        BEGIN
            RAISERROR('Vehicle not found or inactive.', 16, 1);
            RETURN;
        END
        
        IF @VehicleStatus != 'available'
        BEGIN
            RAISERROR('Vehicle is not available. Current status: %s', 16, 1, @VehicleStatus);
            RETURN;
        END
        
        IF @ZoneID IS NULL
        BEGIN
            RAISERROR('Vehicle is not in a valid pickup zone.', 16, 1);
            RETURN;
        END

        -- Prevent double-booking: ensure no active/reserved booking already exists for this vehicle
        IF EXISTS (
            SELECT 1 FROM dbo.CarshareBooking
            WHERE VehicleID = @VehicleID
              AND Status IN ('reserved', 'active')
        )
        BEGIN
            RAISERROR('Vehicle is already booked.', 16, 1);
            RETURN;
        END
        
        -- Check for active bookings by this customer
        IF EXISTS (
            SELECT 1 FROM dbo.CarshareBooking 
            WHERE CustomerID = @CustomerID 
              AND Status IN ('reserved', 'active')
        )
        BEGIN
            RAISERROR('Customer already has an active booking.', 16, 1);
            RETURN;
        END
        
        -- Create booking (20 minutes to unlock vehicle)
        DECLARE @Now DATETIME2 = SYSDATETIME();
        DECLARE @ExpiresAt DATETIME2 = DATEADD(MINUTE, 20, @Now);
        
        INSERT INTO dbo.CarshareBooking (
            CustomerID, VehicleID, BookedAt, ReservationStartAt, ReservationExpiresAt,
            PricingMode, EstimatedDurationMin, Status, PickupZoneID,
            PickupLatitude, PickupLongitude, DepositAmount
        )
        VALUES (
            @CustomerID, @VehicleID, @Now, @Now, @ExpiresAt,
            @PricingMode, @EstimatedDurationMin, 'reserved', @ZoneID,
            @PickupLat, @PickupLon, @DepositAmount
        );
        
        DECLARE @BookingID INT = SCOPE_IDENTITY();
        
        -- Update vehicle status to reserved
        UPDATE dbo.CarshareVehicle
        SET Status = 'reserved',
            UpdatedAt = SYSDATETIME()
        WHERE VehicleID = @VehicleID;
        
        -- Log booking
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, BookingID)
        VALUES ('info', 'booking', 'Vehicle booked', @VehicleID, @CustomerID, @BookingID);
        
        COMMIT TRANSACTION;
        
        SELECT 
            @BookingID AS BookingID,
            'reserved' AS Status,
            @ExpiresAt AS ExpiresAt,
            @DepositAmount AS DepositAmount,
            20 AS MinutesToUnlock;
            
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, ExceptionDetails)
        VALUES ('error', 'booking', 'Booking creation failed', @VehicleID, @CustomerID, ERROR_MESSAGE());
        
        THROW;
    END CATCH
END
GO

-- Cancel a booking
CREATE OR ALTER PROCEDURE dbo.spCarshareCancelBooking
    @BookingID          INT,
    @CustomerID         INT,
    @Reason             NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @VehicleID INT;
    DECLARE @Status NVARCHAR(50);
    
    SELECT @VehicleID = VehicleID, @Status = Status
    FROM dbo.CarshareBooking
    WHERE BookingID = @BookingID AND CustomerID = @CustomerID;
    
    IF @Status IS NULL
    BEGIN
        RAISERROR('Booking not found.', 16, 1);
        RETURN;
    END
    
    IF @Status NOT IN ('reserved')
    BEGIN
        RAISERROR('Booking cannot be cancelled. Current status: %s', 16, 1, @Status);
        RETURN;
    END
    
    -- Cancel booking
    UPDATE dbo.CarshareBooking
    SET Status = 'cancelled',
        CancellationReason = @Reason,
        CancelledAt = SYSDATETIME(),
        UpdatedAt = SYSDATETIME()
    WHERE BookingID = @BookingID;
    
    -- Release vehicle
    UPDATE dbo.CarshareVehicle
    SET Status = 'available',
        UpdatedAt = SYSDATETIME()
    WHERE VehicleID = @VehicleID;
    
    INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, BookingID)
    VALUES ('info', 'booking', 'Booking cancelled', @VehicleID, @CustomerID, @BookingID);
    
    SELECT @BookingID AS BookingID, 'cancelled' AS Status;
END
GO

/* ============================================================
   E. RENTAL START/END
   ============================================================ */

-- Start rental (unlock vehicle)
CREATE OR ALTER PROCEDURE dbo.spCarshareStartRental
    @BookingID          INT,
    @CustomerID         INT,
    @ConfirmCondition   BIT = 1         -- Customer confirms vehicle is in acceptable condition
AS
BEGIN
    SET NOCOUNT ON;
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Validate booking
        DECLARE @BookingStatus NVARCHAR(50);
        DECLARE @VehicleID INT;
        DECLARE @ExpiresAt DATETIME2;
        DECLARE @ZoneID INT;
        DECLARE @PricingMode NVARCHAR(20);
        
        SELECT 
            @BookingStatus = b.Status,
            @VehicleID = b.VehicleID,
            @ExpiresAt = b.ReservationExpiresAt,
            @ZoneID = b.PickupZoneID,
            @PricingMode = b.PricingMode
        FROM dbo.CarshareBooking b
        WHERE b.BookingID = @BookingID AND b.CustomerID = @CustomerID;
        
        IF @BookingStatus IS NULL
        BEGIN
            RAISERROR('Booking not found.', 16, 1);
            RETURN;
        END
        
        IF @BookingStatus != 'reserved'
        BEGIN
            RAISERROR('Booking is not in reserved status. Current status: %s', 16, 1, @BookingStatus);
            RETURN;
        END
        
        IF @ExpiresAt < SYSDATETIME()
        BEGIN
            -- Mark as expired and release vehicle
            UPDATE dbo.CarshareBooking SET Status = 'expired', UpdatedAt = SYSDATETIME() WHERE BookingID = @BookingID;
            UPDATE dbo.CarshareVehicle SET Status = 'available', UpdatedAt = SYSDATETIME() WHERE VehicleID = @VehicleID;
            RAISERROR('Booking has expired.', 16, 1);
            RETURN;
        END
        
        -- Get vehicle details
        DECLARE @OdometerKm INT;
        DECLARE @FuelPercent INT;
        DECLARE @VehicleLat DECIMAL(9,6);
        DECLARE @VehicleLon DECIMAL(9,6);
        DECLARE @PricePerMin DECIMAL(8,4);
        DECLARE @PricePerHour DECIMAL(8,2);
        DECLARE @PricePerDay DECIMAL(8,2);
        DECLARE @PricePerKm DECIMAL(8,4);
        
        SELECT 
            @OdometerKm = v.OdometerKm,
            @FuelPercent = v.FuelLevelPercent,
            @VehicleLat = v.CurrentLatitude,
            @VehicleLon = v.CurrentLongitude,
            @PricePerMin = COALESCE(v.PricePerMinuteOverride, vt.PricePerMinute),
            @PricePerHour = COALESCE(v.PricePerHourOverride, vt.PricePerHour),
            @PricePerDay = vt.PricePerDay,
            @PricePerKm = COALESCE(v.PricePerKmOverride, vt.PricePerKm)
        FROM dbo.CarshareVehicle v
        JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
        WHERE v.VehicleID = @VehicleID;
        
        -- Create rental record
        INSERT INTO dbo.CarshareRental (
            BookingID, CustomerID, VehicleID, StartedAt,
            OdometerStartKm, FuelStartPercent,
            StartZoneID, StartLatitude, StartLongitude,
            Status, PricingMode,
            PricePerMinute, PricePerHour, PricePerDay, PricePerKm
        )
        VALUES (
            @BookingID, @CustomerID, @VehicleID, SYSDATETIME(),
            @OdometerKm, @FuelPercent,
            @ZoneID, @VehicleLat, @VehicleLon,
            'active', @PricingMode,
            @PricePerMin, @PricePerHour, @PricePerDay, @PricePerKm
        );
        
        DECLARE @RentalID INT = SCOPE_IDENTITY();
        
        -- Update booking status
        UPDATE dbo.CarshareBooking
        SET Status = 'active',
            UpdatedAt = SYSDATETIME()
        WHERE BookingID = @BookingID;
        
        -- Update vehicle: unlock and enable engine
        UPDATE dbo.CarshareVehicle
        SET Status = 'in_use',
            IsLockedRemotely = 0,
            EngineEnabled = 1,
            UpdatedAt = SYSDATETIME()
        WHERE VehicleID = @VehicleID;
        
        -- Decrease zone vehicle count
        UPDATE dbo.CarshareZone
        SET CurrentVehicleCount = CurrentVehicleCount - 1,
            UpdatedAt = SYSDATETIME()
        WHERE ZoneID = @ZoneID AND CurrentVehicleCount > 0;
        
        -- Log
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, RentalID, BookingID)
        VALUES ('info', 'rental', 'Rental started - vehicle unlocked', @VehicleID, @CustomerID, @RentalID, @BookingID);
        
        COMMIT TRANSACTION;
        
        SELECT 
            @RentalID AS RentalID,
            @BookingID AS BookingID,
            'active' AS Status,
            @OdometerKm AS OdometerStartKm,
            @FuelPercent AS FuelStartPercent,
            @PricePerMin AS PricePerMinute,
            @PricePerHour AS PricePerHour,
            @PricePerKm AS PricePerKm,
            'Vehicle unlocked. Have a safe trip!' AS Message;
            
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, BookingID, CustomerID, ExceptionDetails)
        VALUES ('error', 'rental', 'Rental start failed', @BookingID, @CustomerID, ERROR_MESSAGE());
        
        THROW;
    END CATCH
END
GO

-- End rental (lock vehicle and calculate charges)
CREATE OR ALTER PROCEDURE dbo.spCarshareEndRental
    @RentalID           INT,
    @CustomerID         INT,
    @EndLatitude        DECIMAL(9,6),
    @EndLongitude       DECIMAL(9,6),
    @OdometerEndKm      INT,
    @FuelEndPercent     INT,
    @CustomerNotes      NVARCHAR(1000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    BEGIN TRY
        BEGIN TRANSACTION;
        
        -- Validate rental
        DECLARE @Status NVARCHAR(50);
        DECLARE @VehicleID INT;
        DECLARE @BookingID INT;
        DECLARE @StartedAt DATETIME2;
        DECLARE @OdometerStartKm INT;
        DECLARE @FuelStartPercent INT;
        DECLARE @StartZoneID INT;
        DECLARE @PricingMode NVARCHAR(20);
        DECLARE @PricePerMin DECIMAL(8,4);
        DECLARE @PricePerHour DECIMAL(8,2);
        DECLARE @PricePerDay DECIMAL(8,2);
        DECLARE @PricePerKm DECIMAL(8,4);
        
        SELECT 
            @Status = Status,
            @VehicleID = VehicleID,
            @BookingID = BookingID,
            @StartedAt = StartedAt,
            @OdometerStartKm = OdometerStartKm,
            @FuelStartPercent = FuelStartPercent,
            @StartZoneID = StartZoneID,
            @PricingMode = PricingMode,
            @PricePerMin = PricePerMinute,
            @PricePerHour = PricePerHour,
            @PricePerDay = PricePerDay,
            @PricePerKm = PricePerKm
        FROM dbo.CarshareRental
        WHERE RentalID = @RentalID AND CustomerID = @CustomerID;
        
        IF @Status IS NULL
        BEGIN
            RAISERROR('Rental not found.', 16, 1);
            RETURN;
        END
        
        IF @Status != 'active'
        BEGIN
            RAISERROR('Rental is not active. Current status: %s', 16, 1, @Status);
            RETURN;
        END
        
        -- Calculate end zone
        DECLARE @EndZoneID INT = dbo.fnCarshareGetZoneForLocation(@EndLatitude, @EndLongitude);
        DECLARE @ParkedInZone BIT = CASE WHEN @EndZoneID IS NOT NULL THEN 1 ELSE 0 END;
        
        -- Calculate duration
        DECLARE @Now DATETIME2 = SYSDATETIME();
        DECLARE @DurationMin INT = DATEDIFF(MINUTE, @StartedAt, @Now);
        
        -- Calculate distance
        DECLARE @DistanceKm INT = @OdometerEndKm - @OdometerStartKm;
        IF @DistanceKm < 0 SET @DistanceKm = 0;
        
        -- Calculate time cost based on pricing mode
        DECLARE @TimeCost DECIMAL(10,2);
        IF @PricingMode = 'per_minute'
            SET @TimeCost = @DurationMin * @PricePerMin;
        ELSE IF @PricingMode = 'per_hour'
            SET @TimeCost = CEILING(@DurationMin / 60.0) * @PricePerHour;
        ELSE IF @PricingMode = 'per_day'
            SET @TimeCost = CEILING(@DurationMin / 1440.0) * @PricePerDay;
        
        -- Calculate distance cost
        DECLARE @DistanceCost DECIMAL(10,2) = @DistanceKm * @PricePerKm;
        
        -- Calculate fees
        DECLARE @InterCityFee DECIMAL(10,2) = 0;
        DECLARE @OutOfZoneFee DECIMAL(10,2) = 0;
        DECLARE @LowFuelFee DECIMAL(10,2) = 0;
        DECLARE @BonusCredit DECIMAL(10,2) = 0;
        
        -- InterCity fee if ending in different city
        IF @EndZoneID IS NOT NULL
        BEGIN
            DECLARE @StartCity NVARCHAR(100);
            DECLARE @EndCity NVARCHAR(100);
            DECLARE @ZoneBonusAmount DECIMAL(10,2);
            DECLARE @ZoneInterCityFee DECIMAL(10,2);
            
            SELECT @StartCity = City FROM dbo.CarshareZone WHERE ZoneID = @StartZoneID;
            SELECT @EndCity = City, @ZoneBonusAmount = BonusAmount, @ZoneInterCityFee = InterCityFee 
            FROM dbo.CarshareZone WHERE ZoneID = @EndZoneID;
            
            IF @StartCity != @EndCity AND @ZoneInterCityFee IS NOT NULL
                SET @InterCityFee = @ZoneInterCityFee;
            
            -- Pink zone bonus
            IF @ZoneBonusAmount IS NOT NULL AND @EndZoneID != @StartZoneID
                SET @BonusCredit = @ZoneBonusAmount;
        END
        ELSE
        BEGIN
            -- Out of zone penalty
            SET @OutOfZoneFee = 25.00;  -- Fixed penalty
        END
        
        -- Low fuel penalty (if returned with <25% and used more than 10%)
        DECLARE @FuelUsed INT = @FuelStartPercent - @FuelEndPercent;
        IF @FuelEndPercent < 25 AND @FuelUsed > 10
            SET @LowFuelFee = 10.00;
        
        -- Calculate total
        DECLARE @TotalCost DECIMAL(10,2) = @TimeCost + @DistanceCost + @InterCityFee + @OutOfZoneFee + @LowFuelFee - @BonusCredit;
        IF @TotalCost < 0 SET @TotalCost = 0;
        
        -- Update rental
        UPDATE dbo.CarshareRental
        SET EndedAt = @Now,
            TotalDurationMin = @DurationMin,
            OdometerEndKm = @OdometerEndKm,
            FuelEndPercent = @FuelEndPercent,
            EndZoneID = @EndZoneID,
            EndLatitude = @EndLatitude,
            EndLongitude = @EndLongitude,
            ParkedInZone = @ParkedInZone,
            Status = 'completed',
            TimeCost = @TimeCost,
            DistanceCost = @DistanceCost,
            InterCityFee = @InterCityFee,
            OutOfZoneFee = @OutOfZoneFee,
            LowFuelFee = @LowFuelFee,
            BonusCredit = @BonusCredit,
            TotalCost = @TotalCost,
            CustomerNotes = @CustomerNotes,
            UpdatedAt = @Now
        WHERE RentalID = @RentalID;
        
        -- Update booking
        UPDATE dbo.CarshareBooking
        SET Status = 'completed',
            UpdatedAt = @Now
        WHERE BookingID = @BookingID;
        
        -- Update vehicle
        UPDATE dbo.CarshareVehicle
        SET Status = CASE WHEN @ParkedInZone = 1 THEN 'available' ELSE 'out_of_zone' END,
            CurrentZoneID = @EndZoneID,
            CurrentLatitude = @EndLatitude,
            CurrentLongitude = @EndLongitude,
            LocationUpdatedAt = @Now,
            OdometerKm = @OdometerEndKm,
            FuelLevelPercent = @FuelEndPercent,
            IsLockedRemotely = 1,
            EngineEnabled = 0,
            UpdatedAt = @Now
        WHERE VehicleID = @VehicleID;
        
        -- Update zone vehicle count
        IF @EndZoneID IS NOT NULL
        BEGIN
            UPDATE dbo.CarshareZone
            SET CurrentVehicleCount = CurrentVehicleCount + 1,
                UpdatedAt = @Now
            WHERE ZoneID = @EndZoneID;
        END
        
        -- Update customer stats
        UPDATE dbo.CarshareCustomer
        SET TotalRentals = TotalRentals + 1,
            TotalDistanceKm = TotalDistanceKm + @DistanceKm,
            TotalSpentEUR = TotalSpentEUR + @TotalCost,
            LastRentalAt = @Now,
            UpdatedAt = @Now
        WHERE CustomerID = @CustomerID;
        
        -- Create payment record
        INSERT INTO dbo.CarsharePayment (
            RentalID, CustomerID, Amount, CurrencyCode,
            PaymentMethodTypeID, PaymentType, Status
        )
        VALUES (
            @RentalID, @CustomerID, @TotalCost, 'EUR',
            1, 'rental', 'pending'
        );
        
        DECLARE @PaymentID INT = SCOPE_IDENTITY();
        
        -- Log
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, RentalID)
        VALUES ('info', 'rental', 
                'Rental ended. Duration: ' + CAST(@DurationMin AS NVARCHAR) + 'min, Distance: ' + CAST(@DistanceKm AS NVARCHAR) + 'km, Total: €' + CAST(@TotalCost AS NVARCHAR),
                @VehicleID, @CustomerID, @RentalID);
        
        COMMIT TRANSACTION;
        
        SELECT 
            @RentalID AS RentalID,
            'completed' AS Status,
            @DurationMin AS TotalDurationMin,
            @DistanceKm AS TotalDistanceKm,
            @TimeCost AS TimeCost,
            @DistanceCost AS DistanceCost,
            @InterCityFee AS InterCityFee,
            @OutOfZoneFee AS OutOfZoneFee,
            @LowFuelFee AS LowFuelFee,
            @BonusCredit AS BonusCredit,
            @TotalCost AS TotalCost,
            @PaymentID AS PaymentID,
            @ParkedInZone AS ParkedInZone,
            CASE WHEN @EndZoneID IS NOT NULL THEN (SELECT ZoneName FROM dbo.CarshareZone WHERE ZoneID = @EndZoneID) ELSE 'Outside Zone' END AS EndZoneName;
            
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        
        INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, RentalID, CustomerID, ExceptionDetails)
        VALUES ('error', 'rental', 'Rental end failed', @RentalID, @CustomerID, ERROR_MESSAGE());
        
        THROW;
    END CATCH
END
GO

/* ============================================================
   F. RENTAL QUERIES
   ============================================================ */

-- Get active rental for customer
CREATE OR ALTER PROCEDURE dbo.spCarshareGetActiveRental
    @CustomerID         INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        r.RentalID,
        r.BookingID,
        r.VehicleID,
        r.StartedAt,
        DATEDIFF(MINUTE, r.StartedAt, SYSDATETIME()) AS CurrentDurationMin,
        r.OdometerStartKm,
        r.FuelStartPercent,
        r.StartLatitude,
        r.StartLongitude,
        r.PricingMode,
        r.PricePerMinute,
        r.PricePerHour,
        r.PricePerKm,
        v.PlateNumber,
        v.Make,
        v.Model,
        v.CurrentLatitude,
        v.CurrentLongitude,
        v.FuelLevelPercent AS CurrentFuelPercent,
        vt.TypeName,
        sz.ZoneName AS StartZoneName,
        sz.City AS StartCity,
        -- Estimated current cost
        CASE 
            WHEN r.PricingMode = 'per_minute' THEN DATEDIFF(MINUTE, r.StartedAt, SYSDATETIME()) * r.PricePerMinute
            WHEN r.PricingMode = 'per_hour' THEN CEILING(DATEDIFF(MINUTE, r.StartedAt, SYSDATETIME()) / 60.0) * r.PricePerHour
            ELSE 0
        END AS EstimatedCurrentCost
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    WHERE r.CustomerID = @CustomerID
      AND r.Status = 'active';
END
GO

-- Get rental history for customer
CREATE OR ALTER PROCEDURE dbo.spCarshareGetRentalHistory
    @CustomerID         INT,
    @PageNumber         INT = 1,
    @PageSize           INT = 20
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @Offset INT = (@PageNumber - 1) * @PageSize;
    
    SELECT 
        r.RentalID,
        r.BookingID,
        r.StartedAt,
        r.EndedAt,
        r.TotalDurationMin,
        r.DistanceKm,
        r.Status,
        r.TotalCost,
        r.ParkedInZone,
        v.PlateNumber,
        v.Make,
        v.Model,
        vt.TypeName,
        sz.ZoneName AS StartZone,
        sz.City AS StartCity,
        ez.ZoneName AS EndZone,
        ez.City AS EndCity,
        p.Status AS PaymentStatus
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    LEFT JOIN dbo.CarshareZone ez ON r.EndZoneID = ez.ZoneID
    LEFT JOIN dbo.CarsharePayment p ON r.RentalID = p.RentalID AND p.PaymentType = 'rental'
    WHERE r.CustomerID = @CustomerID
    ORDER BY r.StartedAt DESC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;
END
GO

/* ============================================================
   G. REPORTS
   ============================================================ */

-- Fleet utilization report
CREATE OR ALTER PROCEDURE dbo.spCarshareReportFleetUtilization
    @FromDate           DATE = NULL,
    @ToDate             DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT 
        vt.TypeName,
        COUNT(DISTINCT v.VehicleID) AS TotalVehicles,
        COUNT(r.RentalID) AS TotalRentals,
        SUM(r.TotalDurationMin) AS TotalMinutesRented,
        SUM(r.DistanceKm) AS TotalKmDriven,
        SUM(r.TotalCost) AS TotalRevenue,
        AVG(r.TotalDurationMin) AS AvgRentalDurationMin,
        AVG(CAST(r.DistanceKm AS DECIMAL(10,2))) AS AvgDistanceKm,
        AVG(r.TotalCost) AS AvgRentalCost
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareRental r ON v.VehicleID = r.VehicleID 
        AND r.Status = 'completed'
        AND r.StartedAt >= @FromDate AND r.StartedAt < DATEADD(DAY, 1, @ToDate)
    WHERE v.IsActive = 1
    GROUP BY vt.TypeName
    ORDER BY TotalRevenue DESC;
END
GO

-- Zone performance report
CREATE OR ALTER PROCEDURE dbo.spCarshareReportZonePerformance
    @FromDate           DATE = NULL,
    @ToDate             DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT 
        z.ZoneID,
        z.ZoneName,
        z.City,
        z.ZoneType,
        z.CurrentVehicleCount,
        z.MaxCapacity,
        -- Pickups from this zone
        (SELECT COUNT(*) FROM dbo.CarshareRental r 
         WHERE r.StartZoneID = z.ZoneID 
           AND r.StartedAt >= @FromDate AND r.StartedAt < DATEADD(DAY, 1, @ToDate)) AS PickupCount,
        -- Dropoffs to this zone
        (SELECT COUNT(*) FROM dbo.CarshareRental r 
         WHERE r.EndZoneID = z.ZoneID 
           AND r.EndedAt >= @FromDate AND r.EndedAt < DATEADD(DAY, 1, @ToDate)) AS DropoffCount,
        -- Revenue from pickups
        (SELECT ISNULL(SUM(r.TotalCost), 0) FROM dbo.CarshareRental r 
         WHERE r.StartZoneID = z.ZoneID 
           AND r.StartedAt >= @FromDate AND r.StartedAt < DATEADD(DAY, 1, @ToDate)) AS Revenue
    FROM dbo.CarshareZone z
    WHERE z.IsActive = 1
    ORDER BY 
        (SELECT COUNT(*) FROM dbo.CarshareRental r WHERE r.StartZoneID = z.ZoneID 
         AND r.StartedAt >= @FromDate AND r.StartedAt < DATEADD(DAY, 1, @ToDate)) DESC;
END
GO

-- Customer report
CREATE OR ALTER PROCEDURE dbo.spCarshareReportCustomers
    @MinRentals         INT = 0,
    @MembershipTier     NVARCHAR(50) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        cc.CustomerID,
        u.FullName,
        u.Email,
        cc.VerificationStatus,
        cc.MembershipTier,
        cc.TotalRentals,
        cc.TotalDistanceKm,
        cc.TotalSpentEUR,
        cc.LoyaltyPoints,
        cc.LastRentalAt,
        cc.CreatedAt
    FROM dbo.CarshareCustomer cc
    JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    JOIN dbo.[User] u ON p.UserID = u.UserID
    WHERE cc.TotalRentals >= @MinRentals
      AND (@MembershipTier IS NULL OR cc.MembershipTier = @MembershipTier)
    ORDER BY cc.TotalSpentEUR DESC;
END
GO

-- Get Carshare Financial Summary
CREATE OR ALTER PROCEDURE dbo.spGetCarshareFinancialSummary
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        COUNT(*) AS TotalRentals,
        ISNULL(AVG(r.TotalCost), 0) AS AvgRentalCost,
        ISNULL(SUM(r.TimeCost), 0) AS TotalTimeCost,
        ISNULL(SUM(r.DistanceCost), 0) AS TotalDistanceCost,
        ISNULL(SUM(r.InterCityFee), 0) AS TotalInterCityFees,
        ISNULL(SUM(r.OutOfZoneFee + r.LowFuelFee + r.DamageFee + r.CleaningFee), 0) AS TotalPenalties,
        ISNULL(SUM(r.BonusCredit + r.Discount), 0) AS TotalDiscounts,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutesRented,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistanceKm,
        ISNULL(SUM(r.TotalCost) * 0.00, 0) AS ServiceFees,  -- 0% platform fee (no middleman)
        COUNT(DISTINCT r.CustomerID) AS UniqueCustomers,
        COUNT(DISTINCT r.VehicleID) AS ActiveVehicles
    FROM dbo.CarshareRental r
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate;
END
GO

-- Get Carshare Daily Revenue
CREATE OR ALTER PROCEDURE dbo.spGetCarshareDailyRevenue
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        CAST(r.StartedAt AS DATE) AS RentalDay,
        COUNT(*) AS RentalCount,
        ISNULL(SUM(r.TotalCost), 0) AS DailyRevenue,
        0 AS DailyServiceFees,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutes,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistance
    FROM dbo.CarshareRental r
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY CAST(r.StartedAt AS DATE)
    ORDER BY RentalDay DESC;
END
GO

-- Get Carshare Vehicle Type Breakdown
CREATE OR ALTER PROCEDURE dbo.spGetCarshareVehicleTypeBreakdown
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        vt.TypeName,
        vt.TypeCode,
        COUNT(r.RentalID) AS RentalCount,
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        ISNULL(AVG(r.TotalCost), 0) AS AvgRentalCost,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutes,
        ISNULL(AVG(r.TotalDurationMin), 0) AS AvgMinutesPerRental
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY vt.TypeName, vt.TypeCode
    ORDER BY TotalRevenue DESC;
END
GO

-- Get Top Carshare Vehicles by Revenue
CREATE OR ALTER PROCEDURE dbo.spGetTopCarshareVehicles
    @StartDate DATE,
    @EndDate DATE,
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@TopN)
        v.VehicleID,
        v.PlateNumber,
        v.Make,
        v.Model,
        vt.TypeName,
        COUNT(r.RentalID) AS RentalCount,
        ISNULL(SUM(r.TotalCost), 0) AS GrossRevenue,
        0 AS ServiceFees,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutesUsed,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistanceKm
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY v.VehicleID, v.PlateNumber, v.Make, v.Model, vt.TypeName
    ORDER BY GrossRevenue DESC;
END
GO

-- Get Top Carshare Customers by Spending
CREATE OR ALTER PROCEDURE dbo.spGetTopCarshareCustomers
    @StartDate DATE,
    @EndDate DATE,
    @TopN INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@TopN)
        cc.CustomerID,
        u.FullName,
        cc.MembershipTier,
        COUNT(r.RentalID) AS RentalCount,
        ISNULL(SUM(r.TotalCost), 0) AS TotalSpent,
        ISNULL(SUM(r.TotalDurationMin), 0) AS TotalMinutesRented,
        ISNULL(SUM(r.DistanceKm), 0) AS TotalDistanceKm
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareCustomer cc ON r.CustomerID = cc.CustomerID
    JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    JOIN dbo.[User] u ON p.UserID = u.UserID
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY cc.CustomerID, u.FullName, cc.MembershipTier
    ORDER BY TotalSpent DESC;
END
GO

-- Get Carshare Zone Revenue Breakdown
CREATE OR ALTER PROCEDURE dbo.spGetCarshareZoneBreakdown
    @StartDate DATE,
    @EndDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        sz.ZoneName AS StartZone,
        sz.City AS StartCity,
        COUNT(r.RentalID) AS RentalCount,
        ISNULL(SUM(r.TotalCost), 0) AS TotalRevenue,
        ISNULL(SUM(r.InterCityFee), 0) AS InterCityFees,
        COUNT(DISTINCT r.CustomerID) AS UniqueCustomers
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    WHERE r.Status = 'completed'
      AND CAST(r.StartedAt AS DATE) BETWEEN @StartDate AND @EndDate
    GROUP BY sz.ZoneName, sz.City
    ORDER BY TotalRevenue DESC;
END
GO

-- Revenue report by period
CREATE OR ALTER PROCEDURE dbo.spCarshareReportRevenue
    @FromDate           DATE = NULL,
    @ToDate             DATE = NULL,
    @GroupBy            NVARCHAR(20) = 'day'  -- day, week, month
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @FromDate IS NULL SET @FromDate = DATEADD(DAY, -30, GETDATE());
    IF @ToDate IS NULL SET @ToDate = GETDATE();
    
    SELECT 
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, r.StartedAt), CAST(r.StartedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(r.StartedAt, 'yyyy-MM')
            ELSE CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
        END AS Period,
        COUNT(*) AS RentalCount,
        SUM(r.TotalDurationMin) AS TotalMinutes,
        SUM(r.DistanceKm) AS TotalKm,
        SUM(r.TimeCost) AS TimeCostTotal,
        SUM(r.DistanceCost) AS DistanceCostTotal,
        SUM(r.InterCityFee) AS InterCityFeeTotal,
        SUM(r.OutOfZoneFee) AS PenaltiesTotal,
        SUM(r.BonusCredit) AS BonusesGiven,
        SUM(r.TotalCost) AS GrossRevenue
    FROM dbo.CarshareRental r
    WHERE r.Status = 'completed'
      AND r.StartedAt >= @FromDate AND r.StartedAt < DATEADD(DAY, 1, @ToDate)
    GROUP BY 
        CASE @GroupBy
            WHEN 'day' THEN CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
            WHEN 'week' THEN CAST(DATEADD(DAY, 1-DATEPART(WEEKDAY, r.StartedAt), CAST(r.StartedAt AS DATE)) AS NVARCHAR(50))
            WHEN 'month' THEN FORMAT(r.StartedAt, 'yyyy-MM')
            ELSE CAST(CAST(r.StartedAt AS DATE) AS NVARCHAR(50))
        END
    ORDER BY Period;
END
GO

-- Vehicle type statistics
CREATE OR ALTER PROCEDURE dbo.spCarshareGetVehicleTypes
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        vt.VehicleTypeID,
        vt.TypeCode,
        vt.TypeName,
        vt.Description,
        vt.SeatingCapacity,
        vt.IsElectric,
        vt.IsHybrid,
        vt.MinDriverAge,
        vt.MinLicenseYears,
        vt.PricePerMinute,
        vt.PricePerHour,
        vt.PricePerDay,
        vt.PricePerKm,
        vt.MinimumRentalFee,
        vt.DepositAmount,
        (SELECT COUNT(*) FROM dbo.CarshareVehicle v WHERE v.VehicleTypeID = vt.VehicleTypeID AND v.IsActive = 1) AS TotalVehicles,
        (SELECT COUNT(*) FROM dbo.CarshareVehicle v WHERE v.VehicleTypeID = vt.VehicleTypeID AND v.Status = 'available') AS AvailableVehicles
    FROM dbo.CarshareVehicleType vt
    WHERE vt.IsActive = 1
    ORDER BY vt.TypeName;
END
GO

/* ============================================================
   H. GEOFENCE RESTRICTION PROCEDURES
   ============================================================ */

-- Check if a point is inside the operating polygon
-- Using a stored procedure instead of function due to T-SQL limitations with data access in scalar functions
CREATE OR ALTER PROCEDURE dbo.spCarsharePointInPolygon
    @Latitude   DECIMAL(9,6),
    @Longitude  DECIMAL(9,6),
    @AreaID     INT,
    @IsInside   BIT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @IsInside = 0;
    
    DECLARE @NumPoints INT;
    DECLARE @j INT;
    DECLARE @i INT;
    DECLARE @xi DECIMAL(9,6), @yi DECIMAL(9,6);
    DECLARE @xj DECIMAL(9,6), @yj DECIMAL(9,6);
    
    -- Get number of polygon points
    SELECT @NumPoints = COUNT(*) FROM dbo.CarshareOperatingAreaPolygon WHERE AreaID = @AreaID;
    
    IF @NumPoints < 3 
    BEGIN
        SET @IsInside = 0;
        RETURN;
    END
    
    -- Ray casting algorithm
    SET @j = @NumPoints;
    SET @i = 1;
    
    WHILE @i <= @NumPoints
    BEGIN
        SELECT @xi = LatDegrees, @yi = LonDegrees 
        FROM dbo.CarshareOperatingAreaPolygon 
        WHERE AreaID = @AreaID AND SequenceNo = @i;
        
        SELECT @xj = LatDegrees, @yj = LonDegrees 
        FROM dbo.CarshareOperatingAreaPolygon 
        WHERE AreaID = @AreaID AND SequenceNo = @j;
        
        -- Ray casting: check if ray crosses edge
        DECLARE @yiGreater BIT = CASE WHEN @yi > @Longitude THEN 1 ELSE 0 END;
        DECLARE @yjGreater BIT = CASE WHEN @yj > @Longitude THEN 1 ELSE 0 END;
        
        IF @yiGreater <> @yjGreater
        BEGIN
            DECLARE @crossX DECIMAL(18,10);
            IF (@yj - @yi) <> 0
            BEGIN
                SET @crossX = (@xj - @xi) * (@Longitude - @yi) / (@yj - @yi) + @xi;
                IF @Latitude < @crossX
                BEGIN
                    SET @IsInside = CASE WHEN @IsInside = 0 THEN 1 ELSE 0 END;
                END
            END
        END
        
        SET @j = @i;
        SET @i = @i + 1;
    END
END
GO

-- Check vehicle location against geofences and log violations
CREATE OR ALTER PROCEDURE dbo.spCarshareCheckGeofence
    @RentalID           INT,
    @Latitude           DECIMAL(9,6),
    @Longitude          DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @VehicleID INT;
    DECLARE @CustomerID INT;
    DECLARE @IsInsideOperatingArea BIT = 0;
    DECLARE @ViolationType NVARCHAR(50) = NULL;
    DECLARE @AreaID INT = NULL;
    DECLARE @PenaltyAmount DECIMAL(10,2) = 0;
    DECLARE @DistanceToCenter DECIMAL(10,2);
    DECLARE @WarningDistanceM INT;
    
    -- Get rental info
    SELECT @VehicleID = VehicleID, @CustomerID = CustomerID
    FROM dbo.CarshareRental
    WHERE RentalID = @RentalID AND Status = 'active';
    
    IF @VehicleID IS NULL
    BEGIN
        SELECT 0 AS IsValid, 'Rental not found or not active' AS Message, NULL AS ViolationType;
        RETURN;
    END
    
    -- Check against all active operating areas
    DECLARE @AreaCursor CURSOR;
    SET @AreaCursor = CURSOR FOR
        SELECT AreaID, CenterLatitude, CenterLongitude, RadiusMeters, 
               UsePolygon, WarningDistanceM, PenaltyPerMinute, AreaType
        FROM dbo.CarshareOperatingArea
        WHERE IsActive = 1;
    
    DECLARE @CenterLat DECIMAL(9,6), @CenterLon DECIMAL(9,6);
    DECLARE @RadiusM INT, @UsePolygon BIT, @PenaltyPerMin DECIMAL(8,2), @AreaType NVARCHAR(50);
    
    OPEN @AreaCursor;
    FETCH NEXT FROM @AreaCursor INTO @AreaID, @CenterLat, @CenterLon, @RadiusM, 
                                     @UsePolygon, @WarningDistanceM, @PenaltyPerMin, @AreaType;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @AreaType = 'operating'
        BEGIN
            IF @UsePolygon = 1
            BEGIN
                -- Check polygon boundary using stored procedure
                EXEC dbo.spCarsharePointInPolygon @Latitude, @Longitude, @AreaID, @IsInsideOperatingArea OUTPUT;
            END
            ELSE IF @CenterLat IS NOT NULL AND @CenterLon IS NOT NULL AND @RadiusM IS NOT NULL
            BEGIN
                -- Check circular boundary
                SET @DistanceToCenter = 6371000 * 2 * ATN2(
                    SQRT(
                        POWER(SIN(RADIANS(@Latitude - @CenterLat) / 2), 2) +
                        COS(RADIANS(@CenterLat)) * COS(RADIANS(@Latitude)) *
                        POWER(SIN(RADIANS(@Longitude - @CenterLon) / 2), 2)
                    ),
                    SQRT(1 - (
                        POWER(SIN(RADIANS(@Latitude - @CenterLat) / 2), 2) +
                        COS(RADIANS(@CenterLat)) * COS(RADIANS(@Latitude)) *
                        POWER(SIN(RADIANS(@Longitude - @CenterLon) / 2), 2)
                    ))
                );
                
                SET @IsInsideOperatingArea = CASE WHEN @DistanceToCenter <= @RadiusM THEN 1 ELSE 0 END;
                
                -- Check for warning (approaching boundary)
                IF @IsInsideOperatingArea = 1 AND (@RadiusM - @DistanceToCenter) < @WarningDistanceM
                BEGIN
                    SET @ViolationType = 'boundary_warning';
                END
            END
            
            -- If outside operating area, log violation
            IF @IsInsideOperatingArea = 0
            BEGIN
                SET @ViolationType = 'boundary_exit';
                SET @PenaltyAmount = @PenaltyPerMin;  -- Per minute penalty
                
                -- Log violation
                INSERT INTO dbo.CarshareGeofenceViolation (
                    RentalID, VehicleID, CustomerID, AreaID, ViolationType,
                    Latitude, Longitude, DistanceOutsideM, PenaltyAmount
                )
                VALUES (
                    @RentalID, @VehicleID, @CustomerID, @AreaID, @ViolationType,
                    @Latitude, @Longitude, 
                    CASE WHEN @DistanceToCenter IS NOT NULL THEN @DistanceToCenter - @RadiusM ELSE NULL END,
                    @PenaltyAmount
                );
                
                -- Log to system log
                INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, RentalID)
                VALUES ('warning', 'geofence', 
                        'Vehicle exited operating area. Location: ' + CAST(@Latitude AS NVARCHAR) + ',' + CAST(@Longitude AS NVARCHAR),
                        @VehicleID, @CustomerID, @RentalID);
            END
        END
        ELSE IF @AreaType = 'restricted'
        BEGIN
            -- Check if inside restricted area (should NOT be)
            DECLARE @IsInsideRestricted BIT = 0;
            
            IF @UsePolygon = 1
                EXEC dbo.spCarsharePointInPolygon @Latitude, @Longitude, @AreaID, @IsInsideRestricted OUTPUT;
            ELSE IF @CenterLat IS NOT NULL
            BEGIN
                SET @DistanceToCenter = 6371000 * 2 * ATN2(
                    SQRT(POWER(SIN(RADIANS(@Latitude - @CenterLat) / 2), 2) +
                         COS(RADIANS(@CenterLat)) * COS(RADIANS(@Latitude)) *
                         POWER(SIN(RADIANS(@Longitude - @CenterLon) / 2), 2)),
                    SQRT(1 - (POWER(SIN(RADIANS(@Latitude - @CenterLat) / 2), 2) +
                              COS(RADIANS(@CenterLat)) * COS(RADIANS(@Latitude)) *
                              POWER(SIN(RADIANS(@Longitude - @CenterLon) / 2), 2)))
                );
                SET @IsInsideRestricted = CASE WHEN @DistanceToCenter <= @RadiusM THEN 1 ELSE 0 END;
            END
            
            IF @IsInsideRestricted = 1
            BEGIN
                SET @ViolationType = 'restricted_entry';
                SET @PenaltyAmount = @PenaltyPerMin;
                
                INSERT INTO dbo.CarshareGeofenceViolation (
                    RentalID, VehicleID, CustomerID, AreaID, ViolationType,
                    Latitude, Longitude, PenaltyAmount
                )
                VALUES (@RentalID, @VehicleID, @CustomerID, @AreaID, @ViolationType, @Latitude, @Longitude, @PenaltyAmount);
                
                INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, RentalID)
                VALUES ('warning', 'geofence', 'Vehicle entered restricted area', @VehicleID, @CustomerID, @RentalID);
            END
        END
        
        FETCH NEXT FROM @AreaCursor INTO @AreaID, @CenterLat, @CenterLon, @RadiusM, 
                                         @UsePolygon, @WarningDistanceM, @PenaltyPerMin, @AreaType;
    END
    
    CLOSE @AreaCursor;
    DEALLOCATE @AreaCursor;
    
    -- Return result
    SELECT 
        CASE WHEN @ViolationType IS NULL OR @ViolationType = 'boundary_warning' THEN 1 ELSE 0 END AS IsValid,
        CASE 
            WHEN @ViolationType = 'boundary_exit' THEN 'Warning: Vehicle has left the operating area. Additional charges apply.'
            WHEN @ViolationType = 'boundary_warning' THEN 'Warning: Approaching operating area boundary.'
            WHEN @ViolationType = 'restricted_entry' THEN 'Warning: Vehicle has entered a restricted area. Additional charges apply.'
            ELSE 'Location OK'
        END AS Message,
        @ViolationType AS ViolationType,
        @PenaltyAmount AS PenaltyPerMinute;
END
GO

-- Get all operating areas for map display
CREATE OR ALTER PROCEDURE dbo.spCarshareGetOperatingAreas
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Get areas
    SELECT 
        a.AreaID,
        a.AreaName,
        a.AreaType,
        a.Description,
        a.CenterLatitude,
        a.CenterLongitude,
        a.RadiusMeters,
        a.UsePolygon,
        a.WarningDistanceM,
        a.PenaltyPerMinute,
        a.MaxPenalty,
        a.DisableEngineOutside
    FROM dbo.CarshareOperatingArea a
    WHERE a.IsActive = 1;
    
    -- Get polygon points
    SELECT 
        p.AreaID,
        p.SequenceNo,
        p.LatDegrees,
        p.LonDegrees
    FROM dbo.CarshareOperatingAreaPolygon p
    JOIN dbo.CarshareOperatingArea a ON p.AreaID = a.AreaID
    WHERE a.IsActive = 1
    ORDER BY p.AreaID, p.SequenceNo;
END
GO

-- Calculate total geofence penalties for a rental
CREATE OR ALTER PROCEDURE dbo.spCarshareGetGeofencePenalties
    @RentalID           INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        COUNT(*) AS TotalViolations,
        SUM(PenaltyAmount) AS TotalPenalty,
        SUM(DurationSeconds) AS TotalSecondsOutside,
        MAX(DistanceOutsideM) AS MaxDistanceOutside
    FROM dbo.CarshareGeofenceViolation
    WHERE RentalID = @RentalID;
    
    SELECT 
        ViolationID,
        ViolationType,
        Latitude,
        Longitude,
        DistanceOutsideM,
        DurationSeconds,
        PenaltyAmount,
        CreatedAt
    FROM dbo.CarshareGeofenceViolation
    WHERE RentalID = @RentalID
    ORDER BY CreatedAt;
END
GO

/* ============================================================
   CARSHARE ADDITIONAL STORED PROCEDURES
   ============================================================ */

/* ============================================================
   CARSHARE STORED PROCEDURES
   Refactored from inline SQL in PHP files
   ============================================================ */

SET NOCOUNT ON;
GO

-- ============================================================
-- REGISTER.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareCheckCustomerRegistration', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCheckCustomerRegistration;
GO
CREATE PROCEDURE dbo.CarshareCheckCustomerRegistration
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT CustomerID, VerificationStatus 
    FROM dbo.CarshareCustomer 
    WHERE PassengerID = @PassengerID;
END;
GO

-- ============================================================
-- CARSHARE_BOOK.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareVerifyCustomerApproval', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareVerifyCustomerApproval;
GO
CREATE PROCEDURE dbo.CarshareVerifyCustomerApproval
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT CustomerID, VerificationStatus 
    FROM dbo.CarshareCustomer 
    WHERE CustomerID = @CustomerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCheckExistingBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCheckExistingBooking;
GO
CREATE PROCEDURE dbo.CarshareCheckExistingBooking
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT BookingID 
    FROM dbo.CarshareBooking 
    WHERE CustomerID = @CustomerID 
      AND Status IN ('reserved', 'active');
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetVehicleForBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetVehicleForBooking;
GO
CREATE PROCEDURE dbo.CarshareGetVehicleForBooking
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT v.VehicleID, v.Status, v.CurrentZoneID, v.CurrentLatitude, v.CurrentLongitude,
           vt.DepositAmount
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.VehicleID = @VehicleID AND v.IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCreateBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCreateBooking;
GO
CREATE PROCEDURE dbo.CarshareCreateBooking
    @CustomerID INT,
    @VehicleID INT,
    @BookedAt DATETIME,
    @ReservationStartAt DATETIME,
    @ReservationExpiresAt DATETIME,
    @PricingMode NVARCHAR(20),
    @PickupZoneID INT,
    @PickupLatitude DECIMAL(9,6),
    @PickupLongitude DECIMAL(9,6),
    @DepositAmount DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @NewBooking TABLE (BookingID INT);
    
    INSERT INTO dbo.CarshareBooking (
        CustomerID, VehicleID, BookedAt, ReservationStartAt, ReservationExpiresAt,
        PricingMode, Status, PickupZoneID, PickupLatitude, PickupLongitude, DepositAmount
    )
    OUTPUT INSERTED.BookingID INTO @NewBooking
    VALUES (
        @CustomerID, @VehicleID, @BookedAt, @ReservationStartAt, @ReservationExpiresAt,
        @PricingMode, 'reserved', @PickupZoneID, @PickupLatitude, @PickupLongitude, @DepositAmount
    );
    
    SELECT BookingID FROM @NewBooking;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetLatestBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetLatestBooking;
GO
CREATE PROCEDURE dbo.CarshareGetLatestBooking
    @CustomerID INT,
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 BookingID 
    FROM dbo.CarshareBooking
    WHERE CustomerID = @CustomerID 
      AND VehicleID = @VehicleID 
      AND Status = 'reserved'
    ORDER BY BookingID DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareUpdateVehicleStatus', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUpdateVehicleStatus;
GO
CREATE PROCEDURE dbo.CarshareUpdateVehicleStatus
    @VehicleID INT,
    @Status NVARCHAR(30)
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareVehicle 
    SET Status = @Status, UpdatedAt = GETDATE() 
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareLogEvent', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareLogEvent;
GO
CREATE PROCEDURE dbo.CarshareLogEvent
    @Severity NVARCHAR(20),
    @Category NVARCHAR(50),
    @Message NVARCHAR(500),
    @VehicleID INT = NULL,
    @CustomerID INT = NULL,
    @BookingID INT = NULL,
    @RentalID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, BookingID, RentalID)
    VALUES (@Severity, @Category, @Message, @VehicleID, @CustomerID, @BookingID, @RentalID);
END;
GO

-- ============================================================
-- CARSHARE_START.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetBookingForStart', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetBookingForStart;
GO
CREATE PROCEDURE dbo.CarshareGetBookingForStart
    @BookingID INT,
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT b.BookingID, b.VehicleID, b.CustomerID, b.Status, b.ReservationExpiresAt,
           b.PickupZoneID, b.PricingMode
    FROM dbo.CarshareBooking b
    WHERE b.BookingID = @BookingID AND b.CustomerID = @CustomerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareExpireBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareExpireBooking;
GO
CREATE PROCEDURE dbo.CarshareExpireBooking
    @BookingID INT,
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareBooking 
    SET Status = 'expired', UpdatedAt = GETDATE() 
    WHERE BookingID = @BookingID;
    
    UPDATE dbo.CarshareVehicle 
    SET Status = 'available', UpdatedAt = GETDATE() 
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetVehicleForStart', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetVehicleForStart;
GO
CREATE PROCEDURE dbo.CarshareGetVehicleForStart
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT v.VehicleID, v.OdometerKm, v.FuelLevelPercent, v.CurrentLatitude, v.CurrentLongitude,
           COALESCE(v.PricePerMinuteOverride, vt.PricePerMinute) AS PricePerMinute,
           COALESCE(v.PricePerHourOverride, vt.PricePerHour) AS PricePerHour,
           vt.PricePerDay,
           COALESCE(v.PricePerKmOverride, vt.PricePerKm) AS PricePerKm
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    WHERE v.VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetZoneCenter', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetZoneCenter;
GO
CREATE PROCEDURE dbo.CarshareGetZoneCenter
    @ZoneID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT CenterLatitude, CenterLongitude 
    FROM dbo.CarshareZone 
    WHERE ZoneID = @ZoneID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCreateRental', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCreateRental;
GO
CREATE PROCEDURE dbo.CarshareCreateRental
    @BookingID INT,
    @CustomerID INT,
    @VehicleID INT,
    @StartedAt DATETIME,
    @OdometerStartKm INT,
    @FuelStartPercent INT,
    @StartZoneID INT,
    @StartLatitude DECIMAL(9,6),
    @StartLongitude DECIMAL(9,6),
    @PricingMode NVARCHAR(20),
    @PricePerMinute DECIMAL(10,2),
    @PricePerHour DECIMAL(10,2),
    @PricePerDay DECIMAL(10,2),
    @PricePerKm DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @NewRental TABLE (RentalID INT);
    
    INSERT INTO dbo.CarshareRental (
        BookingID, CustomerID, VehicleID, StartedAt,
        OdometerStartKm, FuelStartPercent,
        StartZoneID, StartLatitude, StartLongitude,
        Status, PricingMode,
        PricePerMinute, PricePerHour, PricePerDay, PricePerKm
    )
    OUTPUT INSERTED.RentalID INTO @NewRental
    VALUES (
        @BookingID, @CustomerID, @VehicleID, @StartedAt,
        @OdometerStartKm, @FuelStartPercent,
        @StartZoneID, @StartLatitude, @StartLongitude,
        'active', @PricingMode,
        @PricePerMinute, @PricePerHour, @PricePerDay, @PricePerKm
    );
    
    SELECT RentalID FROM @NewRental;
END;
GO

IF OBJECT_ID(N'dbo.CarshareActivateBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareActivateBooking;
GO
CREATE PROCEDURE dbo.CarshareActivateBooking
    @BookingID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareBooking 
    SET Status = 'active', UpdatedAt = GETDATE() 
    WHERE BookingID = @BookingID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareUnlockVehicle', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUnlockVehicle;
GO
CREATE PROCEDURE dbo.CarshareUnlockVehicle
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareVehicle 
    SET Status = 'in_use', IsLockedRemotely = 0, EngineEnabled = 1, UpdatedAt = GETDATE()
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareDecrementZoneCount', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareDecrementZoneCount;
GO
CREATE PROCEDURE dbo.CarshareDecrementZoneCount
    @ZoneID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareZone 
    SET CurrentVehicleCount = CASE WHEN CurrentVehicleCount > 0 THEN CurrentVehicleCount - 1 ELSE 0 END,
        UpdatedAt = GETDATE()
    WHERE ZoneID = @ZoneID;
END;
GO

-- ============================================================
-- CARSHARE_END.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetActiveRentalForEnd', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveRentalForEnd;
GO
CREATE PROCEDURE dbo.CarshareGetActiveRentalForEnd
    @RentalID INT,
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT r.*, b.BookingID
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareBooking b ON r.BookingID = b.BookingID
    WHERE r.RentalID = @RentalID AND r.CustomerID = @CustomerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetVehicleTelemetry', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetVehicleTelemetry;
GO
CREATE PROCEDURE dbo.CarshareGetVehicleTelemetry
    @VehicleID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT CurrentLatitude, CurrentLongitude, OdometerKm, FuelLevelPercent
    FROM dbo.CarshareVehicle 
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareFindZoneAtLocation', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareFindZoneAtLocation;
GO
CREATE PROCEDURE dbo.CarshareFindZoneAtLocation
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 ZoneID, ZoneName, City, BonusAmount, InterCityFee
    FROM dbo.CarshareZone
    WHERE IsActive = 1
      AND 6371000 * 2 * ATN2(
          SQRT(
              POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
              COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
              POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)
          ),
          SQRT(1 - (
              POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
              COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
              POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)
          ))
      ) <= RadiusMeters
    ORDER BY RadiusMeters ASC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetInterCityFee', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetInterCityFee;
GO
CREATE PROCEDURE dbo.CarshareGetInterCityFee
    @StartZoneID INT,
    @EndZoneID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        (SELECT City FROM dbo.CarshareZone WHERE ZoneID = @StartZoneID) AS StartCity,
        (SELECT City FROM dbo.CarshareZone WHERE ZoneID = @EndZoneID) AS EndCity,
        (SELECT InterCityFee FROM dbo.CarshareZone WHERE ZoneID = @EndZoneID) AS InterCityFee;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCompleteRental', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCompleteRental;
GO
CREATE PROCEDURE dbo.CarshareCompleteRental
    @RentalID INT,
    @EndedAt DATETIME,
    @TotalDurationMin INT,
    @OdometerEndKm INT,
    @FuelEndPercent INT,
    @EndZoneID INT,
    @EndLatitude DECIMAL(9,6),
    @EndLongitude DECIMAL(9,6),
    @ParkedInZone BIT,
    @TimeCost DECIMAL(10,2),
    @DistanceCost DECIMAL(10,2),
    @InterCityFee DECIMAL(10,2),
    @OutOfZoneFee DECIMAL(10,2),
    @LowFuelFee DECIMAL(10,2),
    @DamageFee DECIMAL(10,2),
    @BonusCredit DECIMAL(10,2),
    @TotalCost DECIMAL(10,2),
    @CustomerNotes NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareRental SET
        EndedAt = @EndedAt,
        TotalDurationMin = @TotalDurationMin,
        OdometerEndKm = @OdometerEndKm,
        FuelEndPercent = @FuelEndPercent,
        EndZoneID = @EndZoneID,
        EndLatitude = @EndLatitude,
        EndLongitude = @EndLongitude,
        ParkedInZone = @ParkedInZone,
        Status = 'completed',
        TimeCost = @TimeCost,
        DistanceCost = @DistanceCost,
        InterCityFee = @InterCityFee,
        OutOfZoneFee = @OutOfZoneFee,
        LowFuelFee = @LowFuelFee,
        DamageFee = @DamageFee,
        BonusCredit = @BonusCredit,
        TotalCost = @TotalCost,
        CustomerNotes = @CustomerNotes,
        UpdatedAt = GETDATE()
    WHERE RentalID = @RentalID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCompleteBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCompleteBooking;
GO
CREATE PROCEDURE dbo.CarshareCompleteBooking
    @BookingID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareBooking 
    SET Status = 'completed', UpdatedAt = GETDATE() 
    WHERE BookingID = @BookingID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareLockVehicle', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareLockVehicle;
GO
CREATE PROCEDURE dbo.CarshareLockVehicle
    @VehicleID INT,
    @Status NVARCHAR(30),
    @ZoneID INT,
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6),
    @OdometerKm INT,
    @FuelLevelPercent INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareVehicle SET
        Status = @Status,
        CurrentZoneID = @ZoneID,
        CurrentLatitude = @Latitude,
        CurrentLongitude = @Longitude,
        LocationUpdatedAt = GETDATE(),
        OdometerKm = @OdometerKm,
        FuelLevelPercent = @FuelLevelPercent,
        IsLockedRemotely = 1,
        EngineEnabled = 0,
        UpdatedAt = GETDATE()
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareIncrementZoneCount', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareIncrementZoneCount;
GO
CREATE PROCEDURE dbo.CarshareIncrementZoneCount
    @ZoneID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareZone 
    SET CurrentVehicleCount = CurrentVehicleCount + 1, UpdatedAt = GETDATE() 
    WHERE ZoneID = @ZoneID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareUpdateCustomerStats', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUpdateCustomerStats;
GO
CREATE PROCEDURE dbo.CarshareUpdateCustomerStats
    @CustomerID INT,
    @DistanceKm DECIMAL(10,2),
    @TotalCost DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareCustomer SET
        TotalRentals = TotalRentals + 1,
        TotalDistanceKm = TotalDistanceKm + @DistanceKm,
        TotalSpentEUR = TotalSpentEUR + @TotalCost,
        LastRentalAt = GETDATE(),
        UpdatedAt = GETDATE()
    WHERE CustomerID = @CustomerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCreatePayment', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCreatePayment;
GO
CREATE PROCEDURE dbo.CarshareCreatePayment
    @RentalID INT,
    @CustomerID INT,
    @Amount DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO dbo.CarsharePayment (
        RentalID, CustomerID, Amount, CurrencyCode, PaymentMethodTypeID,
        PaymentType, Status, ProcessedAt, CompletedAt
    ) VALUES (
        @RentalID, @CustomerID, @Amount, 'EUR', 1, 
        'rental', 'completed', SYSDATETIME(), SYSDATETIME()
    );
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetActiveGeofences', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveGeofences;
GO
CREATE PROCEDURE dbo.CarshareGetActiveGeofences
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT GeofenceID, Name 
    FROM dbo.Geofence 
    WHERE IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetGeofencePoints', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetGeofencePoints;
GO
CREATE PROCEDURE dbo.CarshareGetGeofencePoints
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT gp.GeofenceID, gp.LatDegrees, gp.LonDegrees
    FROM dbo.GeofencePoint gp
    INNER JOIN dbo.Geofence g ON gp.GeofenceID = g.GeofenceID
    WHERE g.IsActive = 1
    ORDER BY gp.GeofenceID, gp.SequenceNo;
END;
GO

-- ============================================================
-- CARSHARE_GEOFENCE.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetRentalForGeofence', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetRentalForGeofence;
GO
CREATE PROCEDURE dbo.CarshareGetRentalForGeofence
    @RentalID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT RentalID, VehicleID, CustomerID, Status 
    FROM dbo.CarshareRental 
    WHERE RentalID = @RentalID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareUpdateVehicleLocation', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUpdateVehicleLocation;
GO
CREATE PROCEDURE dbo.CarshareUpdateVehicleLocation
    @VehicleID INT,
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.CarshareVehicle 
    SET CurrentLatitude = @Latitude, 
        CurrentLongitude = @Longitude, 
        LocationUpdatedAt = GETDATE()
    WHERE VehicleID = @VehicleID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetActiveOperatingAreas', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveOperatingAreas;
GO
CREATE PROCEDURE dbo.CarshareGetActiveOperatingAreas
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT AreaID, AreaName, AreaType, CenterLatitude, CenterLongitude, RadiusMeters,
           UsePolygon, WarningDistanceM, PenaltyPerMinute, MaxPenalty
    FROM dbo.CarshareOperatingArea 
    WHERE IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetAreaPolygonPoints', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetAreaPolygonPoints;
GO
CREATE PROCEDURE dbo.CarshareGetAreaPolygonPoints
    @AreaID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT LatDegrees, LonDegrees 
    FROM dbo.CarshareOperatingAreaPolygon 
    WHERE AreaID = @AreaID 
    ORDER BY SequenceNo;
END;
GO

IF OBJECT_ID(N'dbo.CarshareLogGeofenceViolation', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareLogGeofenceViolation;
GO
CREATE PROCEDURE dbo.CarshareLogGeofenceViolation
    @RentalID INT,
    @VehicleID INT,
    @CustomerID INT,
    @AreaID INT,
    @ViolationType NVARCHAR(30),
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6),
    @DistanceOutsideM INT = NULL,
    @PenaltyAmount DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO dbo.CarshareGeofenceViolation 
        (RentalID, VehicleID, CustomerID, AreaID, ViolationType, Latitude, Longitude, 
         DistanceOutsideM, PenaltyAmount)
    VALUES 
        (@RentalID, @VehicleID, @CustomerID, @AreaID, @ViolationType, @Latitude, @Longitude,
         @DistanceOutsideM, @PenaltyAmount);
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetZoneAtLocation', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetZoneAtLocation;
GO
CREATE PROCEDURE dbo.CarshareGetZoneAtLocation
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 ZoneID, ZoneName, City, ZoneType, BonusAmount
    FROM dbo.CarshareZone
    WHERE IsActive = 1
      AND 6371000 * 2 * ATN2(
          SQRT(POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
               COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
               POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)),
          SQRT(1 - (POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
                    COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
                    POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)))
      ) <= RadiusMeters;
END;
GO

-- ============================================================
-- CARSHARE_AREAS.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetAllOperatingAreas', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetAllOperatingAreas;
GO
CREATE PROCEDURE dbo.CarshareGetAllOperatingAreas
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT AreaID, AreaName, AreaType, Description, CenterLatitude, CenterLongitude, 
           RadiusMeters, UsePolygon, WarningDistanceM, PenaltyPerMinute, MaxPenalty
    FROM dbo.CarshareOperatingArea 
    WHERE IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetAllAreaPolygons', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetAllAreaPolygons;
GO
CREATE PROCEDURE dbo.CarshareGetAllAreaPolygons
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT p.AreaID, p.SequenceNo, p.LatDegrees, p.LonDegrees
    FROM dbo.CarshareOperatingAreaPolygon p
    JOIN dbo.CarshareOperatingArea a ON p.AreaID = a.AreaID
    WHERE a.IsActive = 1
    ORDER BY p.AreaID, p.SequenceNo;
END;
GO

-- ============================================================
-- CARSHARE_CANCEL.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetBookingForCancel', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetBookingForCancel;
GO
CREATE PROCEDURE dbo.CarshareGetBookingForCancel
    @BookingID INT,
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT BookingID, VehicleID, Status 
    FROM dbo.CarshareBooking 
    WHERE BookingID = @BookingID AND CustomerID = @CustomerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCancelBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCancelBooking;
GO
CREATE PROCEDURE dbo.CarshareCancelBooking
    @BookingID INT,
    @VehicleID INT,
    @CustomerID INT,
    @Reason NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Cancel booking
    UPDATE dbo.CarshareBooking 
    SET Status = 'cancelled', 
        CancellationReason = @Reason, 
        CancelledAt = GETDATE(), 
        UpdatedAt = GETDATE()
    WHERE BookingID = @BookingID;
    
    -- Release vehicle
    UPDATE dbo.CarshareVehicle 
    SET Status = 'available', 
        UpdatedAt = GETDATE() 
    WHERE VehicleID = @VehicleID;
    
    -- Log
    INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, BookingID)
    VALUES ('info', 'booking', 'Booking cancelled by customer', @VehicleID, @CustomerID, @BookingID);
END;
GO

-- ============================================================
-- CARSHARE_AREAS.PHP PROCEDURES
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetOperatingAreasForDisplay', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetOperatingAreasForDisplay;
GO
CREATE PROCEDURE dbo.CarshareGetOperatingAreasForDisplay
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT AreaID, AreaName, AreaType, Description, CenterLatitude, CenterLongitude, 
           RadiusMeters, UsePolygon, WarningDistanceM, PenaltyPerMinute, MaxPenalty
    FROM dbo.CarshareOperatingArea 
    WHERE IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetAreaPolygonsForDisplay', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetAreaPolygonsForDisplay;
GO
CREATE PROCEDURE dbo.CarshareGetAreaPolygonsForDisplay
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT p.AreaID, p.SequenceNo, p.LatDegrees, p.LonDegrees
    FROM dbo.CarshareOperatingAreaPolygon p
    JOIN dbo.CarshareOperatingArea a ON p.AreaID = a.AreaID
    WHERE a.IsActive = 1
    ORDER BY p.AreaID, p.SequenceNo;
END;
GO

-- ============================================================
-- CARSHARE_GEOFENCE.PHP - CURRENT ZONE LOOKUP
-- ============================================================

IF OBJECT_ID(N'dbo.CarshareGetCurrentZone', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetCurrentZone;
GO
CREATE PROCEDURE dbo.CarshareGetCurrentZone
    @Latitude DECIMAL(9,6),
    @Longitude DECIMAL(9,6)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1 ZoneID, ZoneName, City, ZoneType, BonusAmount
    FROM dbo.CarshareZone
    WHERE IsActive = 1
      AND 6371000 * 2 * ATN2(
          SQRT(POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
               COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
               POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)),
          SQRT(1 - (POWER(SIN(RADIANS(@Latitude - CenterLatitude) / 2), 2) +
                    COS(RADIANS(CenterLatitude)) * COS(RADIANS(@Latitude)) *
                    POWER(SIN(RADIANS(@Longitude - CenterLongitude) / 2), 2)))
      ) <= RadiusMeters;
END;
GO

/* ============================================================
   CARSHARE TELEDRIVE STORED PROCEDURES
   ============================================================ */

GO

IF OBJECT_ID(N'dbo.CarshareGetCustomerByPassenger', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetCustomerByPassenger;
GO
CREATE PROCEDURE dbo.CarshareGetCustomerByPassenger
    @PassengerID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT CustomerID, VerificationStatus, MembershipTier, VerificationNotes
    FROM dbo.CarshareCustomer
    WHERE PassengerID = @PassengerID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetBookingForTeleDrive', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetBookingForTeleDrive;
GO
CREATE PROCEDURE dbo.CarshareGetBookingForTeleDrive
    @BookingID INT,
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1
        b.BookingID,
        b.Status,
        b.VehicleID,
        b.PickupZoneID,
        z.CenterLatitude,
        z.CenterLongitude,
        z.ZoneName,
        v.CurrentLatitude,
        v.CurrentLongitude
    FROM dbo.CarshareBooking b
    JOIN dbo.CarshareVehicle v ON b.VehicleID = v.VehicleID
    JOIN dbo.CarshareZone z ON b.PickupZoneID = z.ZoneID
    WHERE b.BookingID = @BookingID
      AND b.CustomerID = @CustomerID
      AND b.Status IN ('reserved','active');
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetActiveTeleDriveByBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveTeleDriveByBooking;
GO
CREATE PROCEDURE dbo.CarshareGetActiveTeleDriveByBooking
    @BookingID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1 *
    FROM dbo.CarshareTeleDriveRequest
    WHERE BookingID = @BookingID
      AND Status IN ('pending','en_route','arrived')
    ORDER BY TeleDriveID DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareCreateTeleDriveRequest', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareCreateTeleDriveRequest;
GO
CREATE PROCEDURE dbo.CarshareCreateTeleDriveRequest
    @BookingID INT,
    @CustomerID INT,
    @VehicleID INT,
    @StartZoneID INT,
    @StartLatitude DECIMAL(9,6),
    @StartLongitude DECIMAL(9,6),
    @TargetLatitude DECIMAL(9,6),
    @TargetLongitude DECIMAL(9,6),
    @EstimatedDurationSec INT,
    @EstimatedDistanceKm DECIMAL(10,3),
    @RouteGeometry NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @NewTeleDrive TABLE (TeleDriveID INT);

    INSERT INTO dbo.CarshareTeleDriveRequest (
        BookingID,
        CustomerID,
        VehicleID,
        StartZoneID,
        StartLatitude,
        StartLongitude,
        TargetLatitude,
        TargetLongitude,
        EstimatedDurationSec,
        EstimatedDistanceKm,
        RouteGeometry,
        Status,
        CreatedAt,
        StartedAt
    )
    OUTPUT INSERTED.TeleDriveID INTO @NewTeleDrive
    VALUES (
        @BookingID,
        @CustomerID,
        @VehicleID,
        @StartZoneID,
        @StartLatitude,
        @StartLongitude,
        @TargetLatitude,
        @TargetLongitude,
        @EstimatedDurationSec,
        @EstimatedDistanceKm,
        @RouteGeometry,
        'en_route',
        SYSDATETIME(),
        SYSDATETIME()
    );

    UPDATE dbo.CarshareVehicle
    SET Status = 'remote_dispatch',
        UpdatedAt = GETDATE()
    WHERE VehicleID = @VehicleID;

    INSERT INTO dbo.CarshareSystemLog (Severity, Category, Message, VehicleID, CustomerID, BookingID)
    VALUES ('info', 'tele_drive', 'Tele-drive dispatch started', @VehicleID, @CustomerID, @BookingID);

    SELECT TeleDriveID FROM @NewTeleDrive;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetTeleDriveById', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetTeleDriveById;
GO
CREATE PROCEDURE dbo.CarshareGetTeleDriveById
    @TeleDriveID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TeleDriveID,
           BookingID,
           Status,
           StartLatitude,
           StartLongitude,
           TargetLatitude,
           TargetLongitude,
           EstimatedDurationSec,
           EstimatedDistanceKm,
           RouteGeometry,
           StartedAt,
           ArrivedAt,
           LastProgressPercent
    FROM dbo.CarshareTeleDriveRequest
    WHERE TeleDriveID = @TeleDriveID;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetTeleDriveStatus', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetTeleDriveStatus;
GO
CREATE PROCEDURE dbo.CarshareGetTeleDriveStatus
    @CustomerID INT,
    @TeleDriveID INT = NULL,
    @BookingID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1
        td.TeleDriveID,
        td.BookingID,
        td.CustomerID,
        td.VehicleID,
        td.StartZoneID,
        td.StartLatitude,
        td.StartLongitude,
        td.TargetLatitude,
        td.TargetLongitude,
        td.EstimatedDurationSec,
        td.EstimatedDistanceKm,
        td.RouteGeometry,
        td.Status,
        td.CreatedAt,
        td.StartedAt,
        td.ArrivedAt,
        td.CompletedAt,
        td.CancelledAt,
        td.LastProgressPercent,
        td.LastStatusNote,
        td.SpeedMultiplier,
        td.SpeedChangedAt,
        td.ProgressAtSpeedChange,
        b.VehicleID AS BookingVehicleID,
        v.Status AS VehicleStatus,
        v.Make,
        v.Model,
        z.ZoneName
    FROM dbo.CarshareTeleDriveRequest td
    JOIN dbo.CarshareBooking b ON td.BookingID = b.BookingID
    JOIN dbo.CarshareVehicle v ON b.VehicleID = v.VehicleID
    JOIN dbo.CarshareZone z ON td.StartZoneID = z.ZoneID
    WHERE td.CustomerID = @CustomerID
      AND (
            (@TeleDriveID IS NOT NULL AND td.TeleDriveID = @TeleDriveID)
         OR (@TeleDriveID IS NULL AND @BookingID IS NOT NULL AND td.BookingID = @BookingID)
      )
    ORDER BY td.TeleDriveID DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareUpdateTeleDriveProgress', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUpdateTeleDriveProgress;
GO
CREATE PROCEDURE dbo.CarshareUpdateTeleDriveProgress
    @TeleDriveID INT,
    @Status NVARCHAR(30) = NULL,
    @ProgressPercent DECIMAL(5,2) = NULL,
    @CurrentLatitude DECIMAL(9,6) = NULL,
    @CurrentLongitude DECIMAL(9,6) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @VehicleID INT;

    SELECT @VehicleID = VehicleID
    FROM dbo.CarshareTeleDriveRequest
    WHERE TeleDriveID = @TeleDriveID;

    IF @VehicleID IS NULL
        RETURN;

    UPDATE dbo.CarshareTeleDriveRequest
    SET LastProgressPercent = COALESCE(@ProgressPercent, LastProgressPercent),
        Status = COALESCE(@Status, Status),
        ArrivedAt = CASE WHEN COALESCE(@Status, Status) = 'arrived' THEN COALESCE(ArrivedAt, SYSDATETIME()) ELSE ArrivedAt END,
        CompletedAt = CASE WHEN COALESCE(@Status, Status) = 'completed' THEN COALESCE(CompletedAt, SYSDATETIME()) ELSE CompletedAt END
    WHERE TeleDriveID = @TeleDriveID;

    IF @CurrentLatitude IS NOT NULL AND @CurrentLongitude IS NOT NULL
    BEGIN
        UPDATE dbo.CarshareVehicle
        SET CurrentLatitude = @CurrentLatitude,
            CurrentLongitude = @CurrentLongitude,
            LocationUpdatedAt = SYSDATETIME(),
            UpdatedAt = GETDATE(),
            Status = CASE WHEN COALESCE(@Status, 'en_route') IN ('arrived','completed') THEN 'reserved' ELSE Status END
        WHERE VehicleID = @VehicleID;
    END
    ELSE IF COALESCE(@Status, '') IN ('arrived','completed')
    BEGIN
        UPDATE dbo.CarshareVehicle
        SET Status = 'reserved',
            UpdatedAt = GETDATE()
        WHERE VehicleID = @VehicleID;
    END
END;
GO

IF OBJECT_ID(N'dbo.CarshareUpdateTeleDriveSpeed', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareUpdateTeleDriveSpeed;
GO
CREATE PROCEDURE dbo.CarshareUpdateTeleDriveSpeed
    @TeleDriveID INT,
    @CustomerID INT,
    @SpeedMultiplier DECIMAL(5,2),
    @CurrentProgress DECIMAL(5,4) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Verify ownership and active status
    IF NOT EXISTS (
        SELECT 1 FROM dbo.CarshareTeleDriveRequest
        WHERE TeleDriveID = @TeleDriveID
          AND CustomerID = @CustomerID
          AND Status IN ('pending', 'en_route')
    )
    BEGIN
        RAISERROR('Tele-drive session not found or not active', 16, 1);
        RETURN;
    END

    -- Clamp speed multiplier between 1 and 50
    SET @SpeedMultiplier = CASE 
        WHEN @SpeedMultiplier < 1.0 THEN 1.0
        WHEN @SpeedMultiplier > 50.0 THEN 50.0
        ELSE @SpeedMultiplier
    END;

    -- Clamp progress between 0 and 1
    SET @CurrentProgress = CASE
        WHEN @CurrentProgress IS NULL THEN 0.0
        WHEN @CurrentProgress < 0.0 THEN 0.0
        WHEN @CurrentProgress > 1.0 THEN 1.0
        ELSE @CurrentProgress
    END;

    UPDATE dbo.CarshareTeleDriveRequest
    SET SpeedMultiplier = @SpeedMultiplier,
        SpeedChangedAt = SYSDATETIME(),
        ProgressAtSpeedChange = @CurrentProgress
    WHERE TeleDriveID = @TeleDriveID;

    SELECT @SpeedMultiplier AS SpeedMultiplier, @CurrentProgress AS ProgressAtSpeedChange;
END;
GO

IF OBJECT_ID(N'dbo.CarshareListActiveZones', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareListActiveZones;
GO
CREATE PROCEDURE dbo.CarshareListActiveZones
AS
BEGIN
    SET NOCOUNT ON;

    SELECT ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude,
           RadiusMeters, City, District, MaxCapacity, CurrentVehicleCount,
           InterCityFee, BonusAmount
    FROM dbo.CarshareZone
    WHERE IsActive = 1
    ORDER BY City, ZoneName;
END;
GO

IF OBJECT_ID(N'dbo.CarshareListActiveVehicleTypes', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareListActiveVehicleTypes;
GO
CREATE PROCEDURE dbo.CarshareListActiveVehicleTypes
AS
BEGIN
    SET NOCOUNT ON;

    SELECT VehicleTypeID, TypeName, PricePerMinute
    FROM dbo.CarshareVehicleType
    WHERE IsActive = 1
    ORDER BY TypeName;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetActiveBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveBooking;
GO
CREATE PROCEDURE dbo.CarshareGetActiveBooking
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1 b.*, v.PlateNumber, v.Make, v.Model, v.CurrentLatitude, v.CurrentLongitude,
           z.ZoneName, z.City, z.CenterLatitude AS ZoneCenterLatitude,
           z.CenterLongitude AS ZoneCenterLongitude, z.RadiusMeters AS ZoneRadiusMeters
    FROM dbo.CarshareBooking b
    JOIN dbo.CarshareVehicle v ON b.VehicleID = v.VehicleID
    JOIN dbo.CarshareZone z ON b.PickupZoneID = z.ZoneID
    WHERE b.CustomerID = @CustomerID
      AND b.Status IN ('reserved','active')
    ORDER BY b.BookedAt DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetLatestTeleDriveByBooking', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetLatestTeleDriveByBooking;
GO
CREATE PROCEDURE dbo.CarshareGetLatestTeleDriveByBooking
    @BookingID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1 td.TeleDriveID, td.Status, td.StartLatitude, td.StartLongitude,
           td.TargetLatitude, td.TargetLongitude, td.EstimatedDurationSec,
           td.EstimatedDistanceKm, td.RouteGeometry, td.StartedAt, td.ArrivedAt,
           td.LastProgressPercent, td.CreatedAt
    FROM dbo.CarshareTeleDriveRequest td
    WHERE td.BookingID = @BookingID
    ORDER BY td.TeleDriveID DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetActiveRental', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetActiveRental;
GO
CREATE PROCEDURE dbo.CarshareGetActiveRental
    @CustomerID INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT r.*, v.PlateNumber, v.Make, v.Model, v.CurrentLatitude, v.CurrentLongitude,
           v.FuelLevelPercent, vt.TypeName,
           sz.ZoneName AS StartZoneName, sz.City AS StartCity
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    WHERE r.CustomerID = @CustomerID
      AND r.Status = 'active';
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetRecentRentals', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetRecentRentals;
GO
CREATE PROCEDURE dbo.CarshareGetRecentRentals
    @CustomerID INT,
    @TopN INT = 5
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP (@TopN)
        r.RentalID, r.StartedAt, r.EndedAt, r.TotalCost, r.DistanceKm, r.Status,
        r.PricingMode, r.TimeCost, r.DistanceCost, r.BonusCredit, r.InterCityFee,
        v.Make, v.Model, v.PlateNumber,
        sz.ZoneName AS StartZoneName,
        ez.ZoneName AS EndZoneName
    FROM dbo.CarshareRental r
    JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    LEFT JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    LEFT JOIN dbo.CarshareZone ez ON r.EndZoneID = ez.ZoneID
    WHERE r.CustomerID = @CustomerID
      AND r.Status IN ('completed','ended_by_support','terminated')
    ORDER BY r.StartedAt DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetRecentPayments', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetRecentPayments;
GO
CREATE PROCEDURE dbo.CarshareGetRecentPayments
    @CustomerID INT,
    @TopN INT = 5
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP (@TopN)
        PaymentID, RentalID, Amount, CurrencyCode, PaymentType, Status,
        PaymentMethodTypeID, CreatedAt
    FROM dbo.CarsharePayment
    WHERE CustomerID = @CustomerID
    ORDER BY CreatedAt DESC;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetOperatingAreas', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetOperatingAreas;
GO
CREATE PROCEDURE dbo.CarshareGetOperatingAreas
AS
BEGIN
    SET NOCOUNT ON;

    SELECT AreaID, AreaName, AreaType, Description, CenterLatitude, CenterLongitude,
           RadiusMeters, UsePolygon, WarningDistanceM, PenaltyPerMinute
    FROM dbo.CarshareOperatingArea
    WHERE IsActive = 1;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetOperatingAreaPolygons', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetOperatingAreaPolygons;
GO
CREATE PROCEDURE dbo.CarshareGetOperatingAreaPolygons
AS
BEGIN
    SET NOCOUNT ON;

    SELECT p.AreaID, p.SequenceNo, p.LatDegrees, p.LonDegrees
    FROM dbo.CarshareOperatingAreaPolygon p
    JOIN dbo.CarshareOperatingArea a ON p.AreaID = a.AreaID
    WHERE a.IsActive = 1
    ORDER BY p.AreaID, p.SequenceNo;
END;
GO

IF OBJECT_ID(N'dbo.CarshareGetAvailableVehicles', N'P') IS NOT NULL
    DROP PROCEDURE dbo.CarshareGetAvailableVehicles;
GO
CREATE PROCEDURE dbo.CarshareGetAvailableVehicles
AS
BEGIN
    SET NOCOUNT ON;

    SELECT v.VehicleID, v.PlateNumber, v.Make, v.Model, v.CurrentLatitude, v.CurrentLongitude,
           v.FuelLevelPercent, v.Status, vt.TypeName, vt.IsElectric,
           z.ZoneName, z.City
    FROM dbo.CarshareVehicle v
    JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone z ON v.CurrentZoneID = z.ZoneID
        WHERE v.Status = 'available'
            AND v.IsActive = 1
            AND NOT EXISTS (
                    SELECT 1 FROM dbo.CarshareBooking b
                    WHERE b.VehicleID = v.VehicleID
                        AND b.Status IN ('reserved', 'active')
            );
END;
GO

-- Get Carshare Customers for Approval page
CREATE OR ALTER PROCEDURE dbo.spGetCarshareCustomersList
    @StatusFilter NVARCHAR(20) = 'pending'  -- 'all', 'pending', 'approved', 'rejected'
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        cc.CustomerID, 
        cc.PassengerID, 
        cc.LicenseNumber, 
        cc.LicenseCountry, 
        cc.LicenseExpiryDate, 
        cc.DateOfBirth, 
        cc.NationalID,
        cc.LicensePhotoUrl, 
        cc.NationalIDPhotoUrl,
        cc.VerificationStatus, 
        cc.VerificationNotes, 
        cc.CreatedAt,
        u.FullName, 
        u.Email, 
        u.Phone
    FROM dbo.CarshareCustomer cc
    LEFT JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
    WHERE 
        (@StatusFilter = 'all')
        OR (@StatusFilter = 'pending' AND cc.VerificationStatus IN ('pending', 'documents_submitted'))
        OR (@StatusFilter <> 'all' AND @StatusFilter <> 'pending' AND cc.VerificationStatus = @StatusFilter)
    ORDER BY cc.CreatedAt DESC;
END;
GO

-- Get CarShare Hub Stats
CREATE OR ALTER PROCEDURE dbo.spGetCarshareHubStats
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        (SELECT COUNT(*) FROM dbo.CarshareVehicle WHERE IsActive = 1) AS TotalVehicles,
        (SELECT COUNT(*) FROM dbo.CarshareVehicle WHERE IsActive = 1 AND Status = 'available') AS AvailableVehicles,
        (SELECT COUNT(*) FROM dbo.CarshareVehicle WHERE IsActive = 1 AND Status = 'rented') AS RentedVehicles,
        (SELECT COUNT(*) FROM dbo.CarshareVehicle WHERE IsActive = 1 AND Status = 'maintenance') AS MaintenanceVehicles,
        (SELECT COUNT(*) FROM dbo.CarshareZone WHERE IsActive = 1) AS TotalZones,
        (SELECT COUNT(*) FROM dbo.CarshareCustomer WHERE VerificationStatus IN ('pending', 'documents_submitted')) AS PendingApprovals,
        (SELECT COUNT(*) FROM dbo.CarshareCustomer WHERE VerificationStatus = 'approved') AS ApprovedCustomers,
        (SELECT COUNT(*) FROM dbo.CarshareRental WHERE Status IN ('active', 'paused')) AS ActiveRentals,
        (SELECT COUNT(*) FROM dbo.CarshareRental WHERE CAST(StartedAt AS DATE) = CAST(GETDATE() AS DATE)) AS RentalsToday;
END;
GO

-- Get all CarShare Vehicles with type and zone info
CREATE OR ALTER PROCEDURE dbo.spGetCarshareVehicles
    @StatusFilter NVARCHAR(50) = NULL  -- NULL = all, or 'available', 'rented', 'maintenance', etc.
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        v.VehicleID,
        v.PlateNumber,
        v.Make,
        v.Model,
        v.Year,
        v.Color,
        v.Status,
        v.CurrentLatitude,
        v.CurrentLongitude,
        v.FuelLevelPercent,
        v.BatteryLevelPercent,
        v.OdometerKm,
        v.CleanlinessRating,
        v.IsActive,
        v.CreatedAt,
        v.UpdatedAt,
        vt.VehicleTypeID,
        vt.TypeCode,
        vt.TypeName,
        vt.IsElectric,
        vt.IsHybrid,
        vt.PricePerMinute,
        vt.PricePerHour,
        vt.PricePerDay,
        z.ZoneID,
        z.ZoneName,
        z.City
    FROM dbo.CarshareVehicle v
    LEFT JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone z ON v.CurrentZoneID = z.ZoneID
    WHERE (@StatusFilter IS NULL OR v.Status = @StatusFilter)
      AND v.IsActive = 1
    ORDER BY v.PlateNumber;
END;
GO

-- Get all CarShare Zones
CREATE OR ALTER PROCEDURE dbo.spGetCarshareZones
    @ActiveOnly BIT = 1
AS
BEGIN
    SET NOCOUNT ON;

    SELECT 
        z.ZoneID,
        z.ZoneName,
        z.ZoneType,
        z.Description,
        z.CenterLatitude,
        z.CenterLongitude,
        z.RadiusMeters,
        z.City,
        z.District,
        z.MaxCapacity,
        z.CurrentVehicleCount,
        z.InterCityFee,
        z.BonusAmount,
        z.IsActive,
        z.OperatingHoursStart,
        z.OperatingHoursEnd,
        z.CreatedAt
    FROM dbo.CarshareZone z
    WHERE (@ActiveOnly = 0 OR z.IsActive = 1)
    ORDER BY z.City, z.ZoneName;
END;
GO

-- View CarShare Vehicle Types (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareVehicleTypes
AS
BEGIN
    SET NOCOUNT ON;
    SELECT VehicleTypeID, TypeCode, TypeName, SeatingCapacity, IsElectric, IsHybrid,
           PricePerMinute, PricePerHour, PricePerDay, PricePerKm, DepositAmount, IsActive
    FROM dbo.CarshareVehicleType
    ORDER BY TypeCode;
END;
GO

-- View CarShare Vehicles (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareVehicles
AS
BEGIN
    SET NOCOUNT ON;
    SELECT v.VehicleID, v.PlateNumber, v.Make, v.Model, v.Year, v.Status,
           vt.TypeName, z.ZoneName, v.FuelLevelPercent, v.OdometerKm, v.IsActive
    FROM dbo.CarshareVehicle v
    LEFT JOIN dbo.CarshareVehicleType vt ON v.VehicleTypeID = vt.VehicleTypeID
    LEFT JOIN dbo.CarshareZone z ON v.CurrentZoneID = z.ZoneID
    ORDER BY v.PlateNumber;
END;
GO

-- View CarShare Zones (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareZones
AS
BEGIN
    SET NOCOUNT ON;
    SELECT ZoneID, ZoneName, ZoneType, City, District, RadiusMeters,
           MaxCapacity, CurrentVehicleCount, BonusAmount, IsActive
    FROM dbo.CarshareZone
    ORDER BY City, ZoneName;
END;
GO

-- View CarShare Customers (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareCustomers
AS
BEGIN
    SET NOCOUNT ON;
    SELECT cc.CustomerID, u.FullName, u.Email, cc.LicenseNumber, cc.LicenseCountry,
           cc.LicenseExpiryDate, cc.VerificationStatus, cc.CreatedAt
    FROM dbo.CarshareCustomer cc
    LEFT JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
    ORDER BY cc.CreatedAt DESC;
END;
GO

-- View CarShare Bookings (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareBookings
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 50 b.BookingID, u.FullName AS Customer, v.PlateNumber, v.Make, v.Model,
           z.ZoneName AS PickupZone, b.Status, b.EstimatedCost, b.CreatedAt
    FROM dbo.CarshareBooking b
    LEFT JOIN dbo.CarshareCustomer cc ON b.CustomerID = cc.CustomerID
    LEFT JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.CarshareVehicle v ON b.VehicleID = v.VehicleID
    LEFT JOIN dbo.CarshareZone z ON b.PickupZoneID = z.ZoneID
    ORDER BY b.CreatedAt DESC;
END;
GO

-- View CarShare Rentals (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewCarshareRentals
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 50 r.RentalID, u.FullName AS Customer, v.PlateNumber, v.Make, v.Model,
           sz.ZoneName AS StartZone, ez.ZoneName AS EndZone,
           r.StartedAt, r.EndedAt, r.DistanceKm, r.Status
    FROM dbo.CarshareRental r
    LEFT JOIN dbo.CarshareBooking b ON r.BookingID = b.BookingID
    LEFT JOIN dbo.CarshareCustomer cc ON r.CustomerID = cc.CustomerID
    LEFT JOIN dbo.Passenger p ON cc.PassengerID = p.PassengerID
    LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.CarshareVehicle v ON r.VehicleID = v.VehicleID
    LEFT JOIN dbo.CarshareZone sz ON r.StartZoneID = sz.ZoneID
    LEFT JOIN dbo.CarshareZone ez ON r.EndZoneID = ez.ZoneID
    ORDER BY r.StartedAt DESC;
END;
GO

-- View Geofences (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewGeofences
AS
BEGIN
    SET NOCOUNT ON;
    SELECT GeofenceID, Name, Description, RadiusMeters, IsActive
    FROM dbo.Geofence
    ORDER BY Name;
END;
GO

-- View Autonomous Vehicles (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewAutonomousVehicles
AS
BEGIN
    SET NOCOUNT ON;
    SELECT av.AutonomousVehicleID, av.VehicleCode, av.PlateNo, av.Make, av.Model, av.Year,
           av.SeatingCapacity, av.Status, av.BatteryLevel, g.Name AS Geofence, av.IsActive
    FROM dbo.AutonomousVehicle av
    LEFT JOIN dbo.Geofence g ON av.GeofenceID = g.GeofenceID
    ORDER BY av.VehicleCode;
END;
GO

-- View Autonomous Rides (for database viewer)
CREATE OR ALTER PROCEDURE dbo.spViewAutonomousRides
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 50 ar.AutonomousRideID, u.FullName AS Passenger, av.VehicleCode,
           ar.Status, ar.RequestedAt, ar.TripStartedAt, ar.TripCompletedAt,
           ar.EstimatedFare, ar.ActualFare, ar.ActualDistanceKm
    FROM dbo.AutonomousRide ar
    LEFT JOIN dbo.Passenger p ON ar.PassengerID = p.PassengerID
    LEFT JOIN dbo.[User] u ON p.UserID = u.UserID
    LEFT JOIN dbo.AutonomousVehicle av ON ar.AutonomousVehicleID = av.AutonomousVehicleID
    ORDER BY ar.RequestedAt DESC;
END;
GO

-- ============================================================
-- KASPA CRYPTOCURRENCY PAYMENT PROCEDURES
-- ============================================================

-- Add/Update user Kaspa wallet
CREATE OR ALTER PROCEDURE dbo.spKaspaAddWallet
    @UserID INT,
    @WalletAddress NVARCHAR(100),
    @WalletType NVARCHAR(50) = 'receive',
    @Label NVARCHAR(100) = NULL,
    @IsDefault BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Validate address format (basic check)
    IF @WalletAddress NOT LIKE 'kaspa:%' 
       AND @WalletAddress NOT LIKE 'kaspatest:%'
       AND @WalletAddress NOT LIKE 'kaspadev:%'
    BEGIN
        SELECT 0 AS Success, 'Invalid Kaspa address format. Must start with kaspa:, kaspatest:, or kaspadev:' AS Message;
        RETURN;
    END
    
    -- Extract prefix
    DECLARE @Prefix NVARCHAR(20) = LEFT(@WalletAddress, CHARINDEX(':', @WalletAddress) - 1);
    
    -- Check if wallet already exists for this user
    IF EXISTS (SELECT 1 FROM dbo.KaspaWallet WHERE UserID = @UserID AND WalletAddress = @WalletAddress)
    BEGIN
        -- Update existing
        UPDATE dbo.KaspaWallet
        SET Label = COALESCE(@Label, Label),
            WalletType = @WalletType,
            IsDefault = @IsDefault,
            UpdatedAt = SYSDATETIME(),
            IsActive = 1
        WHERE UserID = @UserID AND WalletAddress = @WalletAddress;
    END
    ELSE
    BEGIN
        -- Insert new
        INSERT INTO dbo.KaspaWallet (UserID, WalletAddress, AddressPrefix, WalletType, Label, IsDefault)
        VALUES (@UserID, @WalletAddress, @Prefix, @WalletType, @Label, @IsDefault);
    END
    
    -- If this is set as default, unset other defaults
    IF @IsDefault = 1
    BEGIN
        UPDATE dbo.KaspaWallet
        SET IsDefault = 0
        WHERE UserID = @UserID 
          AND WalletAddress != @WalletAddress
          AND WalletType = @WalletType;
    END
    
    SELECT 1 AS Success, 'Wallet added successfully' AS Message;
END
GO

-- Get user's Kaspa wallets
CREATE OR ALTER PROCEDURE dbo.spKaspaGetUserWallets
    @UserID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        WalletID,
        WalletAddress,
        AddressPrefix,
        WalletType,
        Label,
        IsDefault,
        IsVerified,
        VerifiedAt,
        CreatedAt
    FROM dbo.KaspaWallet
    WHERE UserID = @UserID AND IsActive = 1
    ORDER BY IsDefault DESC, CreatedAt DESC;
END
GO

-- Get user's default receive wallet
CREATE OR ALTER PROCEDURE dbo.spKaspaGetDefaultWallet
    @UserID INT,
    @WalletType NVARCHAR(50) = 'receive'
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        WalletID,
        WalletAddress,
        AddressPrefix,
        WalletType,
        Label,
        IsVerified
    FROM dbo.KaspaWallet
    WHERE UserID = @UserID 
      AND IsActive = 1
      AND (WalletType = @WalletType OR WalletType = 'both')
    ORDER BY IsDefault DESC, CreatedAt DESC;
END
GO

-- Get driver's Kaspa wallet for receiving trip payments
CREATE OR ALTER PROCEDURE dbo.spKaspaGetDriverWallet
    @DriverID INT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @UserID INT;
    
    -- Get the UserID for this driver
    SELECT @UserID = UserID 
    FROM dbo.Driver 
    WHERE DriverID = @DriverID;
    
    IF @UserID IS NULL
    BEGIN
        -- Driver not found, return empty
        SELECT NULL AS WalletID, NULL AS WalletAddress;
        RETURN;
    END
    
    -- Get driver's default receive wallet
    SELECT TOP 1
        WalletID,
        WalletAddress,
        AddressPrefix,
        WalletType,
        Label,
        IsVerified
    FROM dbo.KaspaWallet
    WHERE UserID = @UserID 
      AND IsActive = 1
      AND (WalletType = 'receive' OR WalletType = 'both')
    ORDER BY IsDefault DESC, CreatedAt DESC;
END
GO

-- Get current exchange rate
CREATE OR ALTER PROCEDURE dbo.spKaspaGetExchangeRate
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        RateID,
        RateKAStoEUR,
        RateEURtoKAS,
        Source,
        FetchedAt,
        ValidUntil
    FROM dbo.KaspaExchangeRate
    WHERE ValidUntil > SYSDATETIME()
    ORDER BY FetchedAt DESC;
    
    -- If no valid rate, return last known rate
    IF @@ROWCOUNT = 0
    BEGIN
        SELECT TOP 1
            RateID,
            RateKAStoEUR,
            RateEURtoKAS,
            Source,
            FetchedAt,
            ValidUntil
        FROM dbo.KaspaExchangeRate
        ORDER BY FetchedAt DESC;
    END
END
GO

-- Update exchange rate
CREATE OR ALTER PROCEDURE dbo.spKaspaUpdateExchangeRate
    @RateKAStoEUR DECIMAL(18,8),
    @Source NVARCHAR(100) = 'manual',
    @ValidMinutes INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    
    INSERT INTO dbo.KaspaExchangeRate (RateKAStoEUR, RateEURtoKAS, Source, ValidUntil)
    VALUES (
        @RateKAStoEUR,
        1.0 / @RateKAStoEUR,
        @Source,
        DATEADD(MINUTE, @ValidMinutes, SYSDATETIME())
    );
    
    SELECT SCOPE_IDENTITY() AS RateID;
END
GO

-- Convert EUR to KAS
CREATE OR ALTER PROCEDURE dbo.spKaspaConvertEURtoKAS
    @AmountEUR DECIMAL(10,2)
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @Rate DECIMAL(18,8);
    DECLARE @RateID INT;
    
    SELECT TOP 1 
        @Rate = RateEURtoKAS,
        @RateID = RateID
    FROM dbo.KaspaExchangeRate
    WHERE ValidUntil > SYSDATETIME()
    ORDER BY FetchedAt DESC;
    
    -- Fallback to last known rate
    IF @Rate IS NULL
    BEGIN
        SELECT TOP 1 
            @Rate = RateEURtoKAS,
            @RateID = RateID
        FROM dbo.KaspaExchangeRate
        ORDER BY FetchedAt DESC;
    END
    
    -- Default rate if none exists (should be updated regularly)
    IF @Rate IS NULL
        SET @Rate = 10.0;  -- 1 EUR = 10 KAS (placeholder)
    
    DECLARE @AmountKAS DECIMAL(18,8) = @AmountEUR * @Rate;
    DECLARE @AmountSompi BIGINT = CAST(@AmountKAS * 100000000 AS BIGINT);
    
    SELECT 
        @AmountEUR AS AmountEUR,
        @AmountKAS AS AmountKAS,
        @AmountSompi AS AmountSompi,
        @Rate AS ExchangeRate,
        @RateID AS RateID;
END
GO

-- Create Kaspa payment request
CREATE OR ALTER PROCEDURE dbo.spKaspaCreatePaymentRequest
    @ToWalletAddress NVARCHAR(100),
    @AmountKAS DECIMAL(18,8),
    @AmountEUR DECIMAL(10,2) = NULL,
    @PaymentID INT = NULL,
    @TripID INT = NULL,
    @SegmentID INT = NULL,
    @AutonomousRideID INT = NULL,
    @RentalID INT = NULL,
    @Description NVARCHAR(255) = NULL,
    @ExpiresInMinutes INT = 30
AS
BEGIN
    SET NOCOUNT ON;
    
    -- Generate unique request code
    DECLARE @RequestCode NVARCHAR(50) = CONCAT(
        'KAS-',
        FORMAT(SYSDATETIME(), 'yyMMdd'),
        '-',
        UPPER(LEFT(CONVERT(NVARCHAR(36), NEWID()), 8))
    );
    
    INSERT INTO dbo.KaspaPaymentRequest (
        RequestCode, ToWalletAddress, AmountKAS, AmountEUR,
        PaymentID, TripID, SegmentID, AutonomousRideID, RentalID,
        Description, ExpiresAt
    )
    VALUES (
        @RequestCode, @ToWalletAddress, @AmountKAS, @AmountEUR,
        @PaymentID, @TripID, @SegmentID, @AutonomousRideID, @RentalID,
        @Description,
        DATEADD(MINUTE, @ExpiresInMinutes, SYSDATETIME())
    );
    
    SELECT 
        SCOPE_IDENTITY() AS RequestID,
        @RequestCode AS RequestCode,
        @ToWalletAddress AS ToWalletAddress,
        @AmountKAS AS AmountKAS,
        DATEADD(MINUTE, @ExpiresInMinutes, SYSDATETIME()) AS ExpiresAt;
END
GO

-- Record Kaspa transaction
CREATE OR ALTER PROCEDURE dbo.spKaspaRecordTransaction
    @PaymentID INT = NULL,
    @FromUserID INT = NULL,
    @ToUserID INT = NULL,
    @FromWalletAddress NVARCHAR(100) = NULL,
    @ToWalletAddress NVARCHAR(100),
    @AmountKAS DECIMAL(18,8),
    @AmountEUR DECIMAL(10,2) = NULL,
    @ExchangeRate DECIMAL(18,8) = NULL,
    @NetworkID NVARCHAR(20) = 'mainnet',
    @TransactionHash NVARCHAR(100) = NULL,
    @TransactionType NVARCHAR(50) = 'payment',
    @TripID INT = NULL,
    @SegmentID INT = NULL,
    @AutonomousRideID INT = NULL,
    @RentalID INT = NULL,
    @Status NVARCHAR(50) = 'pending'
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @AmountSompi BIGINT = CAST(@AmountKAS * 100000000 AS BIGINT);
    
    INSERT INTO dbo.KaspaTransaction (
        PaymentID, FromUserID, ToUserID, FromWalletAddress, ToWalletAddress,
        AmountKAS, AmountSompi, AmountEUR, ExchangeRate,
        NetworkID, TransactionHash, TransactionType,
        TripID, SegmentID, AutonomousRideID, RentalID, Status
    )
    VALUES (
        @PaymentID, @FromUserID, @ToUserID, @FromWalletAddress, @ToWalletAddress,
        @AmountKAS, @AmountSompi, @AmountEUR, @ExchangeRate,
        @NetworkID, @TransactionHash, @TransactionType,
        @TripID, @SegmentID, @AutonomousRideID, @RentalID, @Status
    );
    
    SELECT SCOPE_IDENTITY() AS KaspaTransactionID;
END
GO

-- Update Kaspa transaction status (after blockchain confirmation)
CREATE OR ALTER PROCEDURE dbo.spKaspaUpdateTransactionStatus
    @KaspaTransactionID INT,
    @Status NVARCHAR(50),
    @TransactionHash NVARCHAR(100) = NULL,
    @BlockHash NVARCHAR(100) = NULL,
    @BlockDaaScore BIGINT = NULL,
    @Confirmations INT = NULL,
    @FailureReason NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    UPDATE dbo.KaspaTransaction
    SET Status = @Status,
        TransactionHash = COALESCE(@TransactionHash, TransactionHash),
        BlockHash = COALESCE(@BlockHash, BlockHash),
        BlockDaaScore = COALESCE(@BlockDaaScore, BlockDaaScore),
        Confirmations = COALESCE(@Confirmations, Confirmations),
        FailureReason = @FailureReason,
        BroadcastAt = CASE WHEN @Status = 'broadcasting' AND BroadcastAt IS NULL THEN SYSDATETIME() ELSE BroadcastAt END,
        ConfirmedAt = CASE WHEN @Status = 'confirmed' AND ConfirmedAt IS NULL THEN SYSDATETIME() ELSE ConfirmedAt END
    WHERE KaspaTransactionID = @KaspaTransactionID;
    
    -- If confirmed, also update the linked Payment record
    IF @Status = 'confirmed'
    BEGIN
        UPDATE p
        SET p.Status = 'completed',
            p.CompletedAt = SYSDATETIME(),
            p.ProviderReference = @TransactionHash
        FROM dbo.Payment p
        INNER JOIN dbo.KaspaTransaction kt ON p.PaymentID = kt.PaymentID
        WHERE kt.KaspaTransactionID = @KaspaTransactionID
          AND p.Status != 'completed';
    END
    
    SELECT 1 AS Success;
END
GO

-- Get user's Kaspa transaction history
CREATE OR ALTER PROCEDURE dbo.spKaspaGetUserTransactions
    @UserID INT,
    @MaxRows INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP (@MaxRows)
        kt.KaspaTransactionID,
        kt.TransactionHash,
        kt.FromWalletAddress,
        kt.ToWalletAddress,
        kt.AmountKAS,
        kt.AmountEUR,
        kt.ExchangeRate,
        kt.NetworkID,
        kt.Status,
        kt.TransactionType,
        kt.Confirmations,
        kt.CreatedAt,
        kt.ConfirmedAt,
        -- Direction relative to user
        CASE 
            WHEN kt.ToUserID = @UserID THEN 'received'
            WHEN kt.FromUserID = @UserID THEN 'sent'
            ELSE 'unknown'
        END AS Direction,
        -- Linked entities
        kt.TripID,
        kt.SegmentID,
        kt.AutonomousRideID,
        kt.RentalID
    FROM dbo.KaspaTransaction kt
    WHERE kt.FromUserID = @UserID OR kt.ToUserID = @UserID
    ORDER BY kt.CreatedAt DESC;
END
GO

-- Get driver's Kaspa earnings summary
CREATE OR ALTER PROCEDURE dbo.spKaspaGetDriverEarnings
    @DriverID INT,
    @StartDate DATE = NULL,
    @EndDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @UserID INT;
    SELECT @UserID = UserID FROM dbo.Driver WHERE DriverID = @DriverID;
    
    IF @StartDate IS NULL SET @StartDate = DATEADD(DAY, -30, GETDATE());
    IF @EndDate IS NULL SET @EndDate = GETDATE();
    
    SELECT
        COUNT(*) AS TotalTransactions,
        SUM(AmountKAS) AS TotalKAS,
        SUM(AmountEUR) AS TotalEUR,
        AVG(ExchangeRate) AS AvgExchangeRate
    FROM dbo.KaspaTransaction
    WHERE ToUserID = @UserID
      AND Status = 'confirmed'
      AND TransactionType IN ('payment', 'tip')
      AND CAST(ConfirmedAt AS DATE) BETWEEN @StartDate AND @EndDate;
END
GO

-- Get wallet owner by address
CREATE OR ALTER PROCEDURE dbo.spKaspaGetWalletOwner
    @WalletAddress NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT TOP 1
        kw.UserID,
        kw.WalletID,
        kw.WalletType,
        kw.Label,
        u.FullName,
        u.Email
    FROM dbo.KaspaWallet kw
    INNER JOIN dbo.[User] u ON kw.UserID = u.UserID
    WHERE kw.WalletAddress = @WalletAddress
      AND kw.IsActive = 1;
END
GO

-- Verify and record a Kaspa transaction
CREATE OR ALTER PROCEDURE dbo.spKaspaVerifyTransaction
    @TransactionHash NVARCHAR(100),
    @FromUserID INT = NULL,
    @ToUserID INT = NULL,
    @ToWalletAddress NVARCHAR(100),
    @AmountKAS DECIMAL(18,8),
    @AmountEUR DECIMAL(10,2) = NULL,
    @ExchangeRate DECIMAL(18,8) = NULL,
    @TransactionType NVARCHAR(50) = 'payment',
    @TripID INT = NULL,
    @SegmentID INT = NULL,
    @PaymentID INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ExistingID INT;
    DECLARE @AmountSompi BIGINT = CAST(@AmountKAS * 100000000 AS BIGINT);
    
    -- Check if transaction already recorded
    SELECT @ExistingID = KaspaTransactionID 
    FROM dbo.KaspaTransaction 
    WHERE TransactionHash = @TransactionHash;
    
    IF @ExistingID IS NOT NULL
    BEGIN
        -- Update existing transaction to confirmed
        UPDATE dbo.KaspaTransaction
        SET Status = 'confirmed',
            ConfirmedAt = SYSDATETIME(),
            Confirmations = 1
        WHERE KaspaTransactionID = @ExistingID;
        
        SELECT @ExistingID AS KaspaTransactionID, 'updated' AS Result;
        RETURN;
    END
    
    -- Get exchange rate if not provided
    IF @ExchangeRate IS NULL
    BEGIN
        SELECT TOP 1 @ExchangeRate = RateKAStoEUR
        FROM dbo.KaspaExchangeRate
        WHERE ValidUntil > SYSDATETIME()
        ORDER BY FetchedAt DESC;
    END
    
    -- Calculate EUR if not provided
    IF @AmountEUR IS NULL AND @ExchangeRate IS NOT NULL
    BEGIN
        SET @AmountEUR = @AmountKAS * @ExchangeRate;
    END
    
    -- Insert new transaction as confirmed
    INSERT INTO dbo.KaspaTransaction (
        PaymentID, FromUserID, ToUserID, ToWalletAddress,
        AmountKAS, AmountSompi, AmountEUR, ExchangeRate,
        TransactionHash, Status, TransactionType,
        TripID, SegmentID, ConfirmedAt, Confirmations
    ) VALUES (
        @PaymentID, @FromUserID, @ToUserID, @ToWalletAddress,
        @AmountKAS, @AmountSompi, @AmountEUR, @ExchangeRate,
        @TransactionHash, 'confirmed', @TransactionType,
        @TripID, @SegmentID, SYSDATETIME(), 1
    );
    
    SELECT SCOPE_IDENTITY() AS KaspaTransactionID, 'created' AS Result;
END
GO
