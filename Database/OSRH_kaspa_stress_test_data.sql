
-- =============================================
-- OSRH Stress Test Data Generation Script (HEAVY STRESS)
-- Generates 35,000 users, 10,000 AV rides, 15,000 CarShare bookings
-- =============================================

SET NOCOUNT ON;
PRINT 'Starting stress test data generation...';
PRINT 'This may take several minutes.';
GO

-- =============================================
-- 0. Ensure Currency exists
-- =============================================
IF NOT EXISTS (SELECT 1 FROM dbo.Currency WHERE CurrencyCode = 'EUR')
    INSERT INTO dbo.Currency (CurrencyCode, Name) VALUES ('EUR', 'Euro');
GO

-- =============================================
-- 1. Generate 25,000 Passenger Users
-- =============================================
PRINT 'Generating 25,000 passengers...';

DECLARE @PassengerCounter INT = 1;
DECLARE @Email NVARCHAR(255);
DECLARE @Phone NVARCHAR(30);
DECLARE @FullName NVARCHAR(200);
DECLARE @PasswordHash VARBINARY(256);

-- Simple test hash
SET @PasswordHash = CONVERT(VARBINARY(256), REPLICATE(0x00, 256));

WHILE @PassengerCounter <= 25000
BEGIN
    SET @Email = 'passenger' + CAST(@PassengerCounter AS NVARCHAR) + '@stresstest.com';
    SET @Phone = '+357' + RIGHT('00000000' + CAST(ABS(CHECKSUM(NEWID())) % 100000000 AS NVARCHAR), 8);
    SET @FullName = 'Passenger ' + CAST(@PassengerCounter AS NVARCHAR) + ' User';
    
    -- Insert User
    INSERT INTO [User] (Email, Phone, FullName, CreatedAt, Status)
    VALUES (@Email, @Phone, @FullName, 
            DATEADD(DAY, -ABS(CHECKSUM(NEWID())) % 365, SYSDATETIME()),
            'active');
    
    DECLARE @UserID INT = SCOPE_IDENTITY();
    
    -- Insert password history
    INSERT INTO PasswordHistory (UserID, PasswordHash, IsCurrent)
    VALUES (@UserID, @PasswordHash, 1);
    
    -- Create Passenger record
    INSERT INTO Passenger (UserID, LoyaltyLevel)
    VALUES (@UserID, CASE (ABS(CHECKSUM(NEWID())) % 3)
                       WHEN 0 THEN 'bronze'
                       WHEN 1 THEN 'silver'
                       ELSE 'gold'
                     END);
    
    IF @PassengerCounter % 5000 = 0
        PRINT 'Generated ' + CAST(@PassengerCounter AS NVARCHAR) + ' passengers...';
    
    SET @PassengerCounter = @PassengerCounter + 1;
END

PRINT 'Completed: 25,000 passengers created';
GO

-- =============================================
-- 2. Generate 250 Driver Users with Vehicles (inside geofences)
-- =============================================
PRINT 'Generating 250 drivers with vehicles (inside geofences)...';

-- Load district geofences for driver placement
DECLARE @DriverDistrictGeo TABLE (District NVARCHAR(20), GeofenceID INT, LatMin DECIMAL(9,6), LatMax DECIMAL(9,6), LonMin DECIMAL(9,6), LonMax DECIMAL(9,6));

INSERT INTO @DriverDistrictGeo (District, GeofenceID, LatMin, LatMax, LonMin, LonMax)
SELECT 'Paphos', GeofenceID, 34.65, 35.18, 32.26, 32.80 FROM Geofence WHERE Name = 'Paphos_District'
UNION ALL
SELECT 'Limassol', GeofenceID, 34.58, 35.01, 32.63, 33.40 FROM Geofence WHERE Name = 'Limassol_District'
UNION ALL
SELECT 'Nicosia', GeofenceID, 34.93, 35.20, 32.62, 33.50 FROM Geofence WHERE Name = 'Nicosia_District'
UNION ALL
SELECT 'Larnaca', GeofenceID, 34.65, 35.18, 33.30, 33.95 FROM Geofence WHERE Name = 'Larnaca_District';

DECLARE @DrvGeoCount INT = (SELECT COUNT(*) FROM @DriverDistrictGeo WHERE GeofenceID IS NOT NULL);
IF @DrvGeoCount < 4
BEGIN
    RAISERROR('ERROR: Not all geofences found for Drivers! Please run osrh_seeding.sql first.', 16, 1);
    RETURN;
END

DECLARE @DriverCounter INT = 1;
DECLARE @Email NVARCHAR(255);
DECLARE @Phone NVARCHAR(30);
DECLARE @FullName NVARCHAR(200);
DECLARE @PasswordHash VARBINARY(256);
DECLARE @PlateNo NVARCHAR(20);
DECLARE @DrvLat DECIMAL(9,6);
DECLARE @DrvLon DECIMAL(9,6);
DECLARE @DrvGeoID INT;
DECLARE @DrvDistIdx INT;
DECLARE @DrvLatMin DECIMAL(9,6), @DrvLatMax DECIMAL(9,6), @DrvLonMin DECIMAL(9,6), @DrvLonMax DECIMAL(9,6);
DECLARE @DrvTries INT;
DECLARE @DrvIsInside BIT;

SET @PasswordHash = CONVERT(VARBINARY(256), REPLICATE(0x00, 256));

WHILE @DriverCounter <= 250
BEGIN
    SET @Email = 'driver' + CAST(@DriverCounter AS NVARCHAR) + '@stresstest.com';
    SET @Phone = '+357' + RIGHT('00000000' + CAST(ABS(CHECKSUM(NEWID())) % 100000000 AS NVARCHAR), 8);
    SET @FullName = 'Driver ' + CAST(@DriverCounter AS NVARCHAR) + ' Test';
    
    -- Insert User
    INSERT INTO [User] (Email, Phone, FullName, CreatedAt, Status)
    VALUES (@Email, @Phone, @FullName, 
            DATEADD(DAY, -ABS(CHECKSUM(NEWID())) % 365, SYSDATETIME()),
            'active');
    
    DECLARE @UserID INT = SCOPE_IDENTITY();
    
    -- Insert password history
    INSERT INTO PasswordHistory (UserID, PasswordHash, IsCurrent)
    VALUES (@UserID, @PasswordHash, 1);
    
    -- Assign district in round-robin
    SET @DrvDistIdx = ((@DriverCounter - 1) % 4) + 1;
    
    -- Get geofence info
    SELECT TOP 1 @DrvGeoID = GeofenceID, @DrvLatMin = LatMin, @DrvLatMax = LatMax, @DrvLonMin = LonMin, @DrvLonMax = LonMax
    FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY District) AS rn FROM @DriverDistrictGeo) d
    WHERE rn = @DrvDistIdx;
    
    -- Generate random point inside geofence
    SET @DrvTries = 0;
    SET @DrvIsInside = 0;
    WHILE @DrvIsInside = 0 AND @DrvTries < 1000
    BEGIN
        SET @DrvLat = @DrvLatMin + (RAND(CHECKSUM(NEWID()) + @DrvTries) * (@DrvLatMax - @DrvLatMin));
        SET @DrvLon = @DrvLonMin + (RAND(CHECKSUM(NEWID()) - @DrvTries) * (@DrvLonMax - @DrvLonMin));
        SET @DrvIsInside = dbo.fnIsPointInGeofence(@DrvLat, @DrvLon, @DrvGeoID);
        SET @DrvTries = @DrvTries + 1;
    END
    
    -- Fallback to center if needed
    IF @DrvIsInside = 0
    BEGIN
        SET @DrvLat = (@DrvLatMin + @DrvLatMax) / 2.0;
        SET @DrvLon = (@DrvLonMin + @DrvLonMax) / 2.0;
    END
    
    -- Create Driver record with random rating and validated geofence location
    INSERT INTO Driver (UserID, DriverType, IsAvailable, VerificationStatus, RatingAverage,
                       CurrentLatitude, CurrentLongitude, LocationUpdatedAt, UseGPS)
    VALUES (@UserID, 
            CASE (ABS(CHECKSUM(NEWID())) % 2) WHEN 0 THEN 'employee' ELSE 'partner' END,
            CASE (ABS(CHECKSUM(NEWID())) % 3) WHEN 0 THEN 1 ELSE 0 END, -- 33% available
            'approved',
            ROUND(3.5 + (RAND(CHECKSUM(NEWID())) * 1.5), 2), -- Rating between 3.5-5.0
            @DrvLat,
            @DrvLon,
            DATEADD(MINUTE, -ABS(CHECKSUM(NEWID())) % 60, SYSDATETIME()),
            0); -- UseGPS = 0 means simulated
    
    DECLARE @DriverID INT = SCOPE_IDENTITY();
    
    -- Create 1-3 vehicles per driver
    DECLARE @VehicleCount INT = (ABS(CHECKSUM(NEWID())) % 3) + 1;
    DECLARE @VehCounter INT = 1;
    
    WHILE @VehCounter <= @VehicleCount
    BEGIN
        -- Generate unique plate using driver counter and vehicle counter
        SET @PlateNo = 'ST' + RIGHT('0000' + CAST(@DriverCounter AS NVARCHAR), 4) + '-' + CAST(@VehCounter AS NVARCHAR);
        
        INSERT INTO Vehicle (DriverID, VehicleTypeID, PlateNo, Make, Model, Color, SeatingCapacity, IsActive)
        VALUES (@DriverID, 
                (ABS(CHECKSUM(NEWID())) % 6) + 1, -- Random vehicle type 1-6
                @PlateNo,
                CASE (ABS(CHECKSUM(NEWID())) % 5)
                    WHEN 0 THEN 'Toyota'
                    WHEN 1 THEN 'Mercedes'
                    WHEN 2 THEN 'BMW'
                    WHEN 3 THEN 'Volkswagen'
                    ELSE 'Ford'
                END,
                'Model ' + CAST((ABS(CHECKSUM(NEWID())) % 10) AS NVARCHAR),
                CASE (ABS(CHECKSUM(NEWID())) % 8)
                    WHEN 0 THEN 'Black'
                    WHEN 1 THEN 'White'
                    WHEN 2 THEN 'Silver'
                    WHEN 3 THEN 'Blue'
                    WHEN 4 THEN 'Red'
                    WHEN 5 THEN 'Grey'
                    WHEN 6 THEN 'Green'
                    ELSE 'Yellow'
                END,
                (ABS(CHECKSUM(NEWID())) % 6) + 2, -- Capacity 2-7
                CASE WHEN @VehCounter = 1 THEN 1 ELSE 0 END); -- First vehicle is active
        
        -- Create GeofenceLog entry for active vehicle to bind it to the driver's geofence
        IF @VehCounter = 1
        BEGIN
            DECLARE @NewVehicleID INT = SCOPE_IDENTITY();
            INSERT INTO GeofenceLog (VehicleID, GeofenceID, EnteredAt, ExitedAt, EventType)
            VALUES (@NewVehicleID, @DrvGeoID, SYSDATETIME(), NULL, 'stress_test');
        END
        
        SET @VehCounter = @VehCounter + 1;
    END
    
    IF @DriverCounter % 50 = 0
        PRINT 'Generated ' + CAST(@DriverCounter AS NVARCHAR) + ' drivers...';
    
    SET @DriverCounter = @DriverCounter + 1;
END

PRINT 'Completed: 250 drivers created with vehicles';
GO

-- =============================================
-- 3. Generate Random Locations INSIDE Geofences
-- =============================================
PRINT 'Generating 20,000 random locations inside geofences...';

DECLARE @LocationCounter INT = 1;
DECLARE @LatDegrees DECIMAL(9,6);
DECLARE @LonDegrees DECIMAL(9,6);
DECLARE @StreetAddress NVARCHAR(255);
DECLARE @Description NVARCHAR(255);
DECLARE @PostalCode NVARCHAR(20);

-- Load geofence boundaries for location placement
DECLARE @LocationGeoTable TABLE (GeofenceName NVARCHAR(100), GeofenceID INT, LatMin DECIMAL(9,6), LatMax DECIMAL(9,6), LonMin DECIMAL(9,6), LonMax DECIMAL(9,6));
INSERT INTO @LocationGeoTable (GeofenceName, GeofenceID, LatMin, LatMax, LonMin, LonMax)
SELECT 'Paphos', GeofenceID, 34.65, 35.18, 32.26, 32.80 FROM Geofence WHERE Name = 'Paphos_District'
UNION ALL
SELECT 'Limassol', GeofenceID, 34.58, 35.01, 32.63, 33.40 FROM Geofence WHERE Name = 'Limassol_District'
UNION ALL
SELECT 'Nicosia', GeofenceID, 34.93, 35.20, 32.62, 33.50 FROM Geofence WHERE Name = 'Nicosia_District'
UNION ALL
SELECT 'Larnaca', GeofenceID, 34.65, 35.18, 33.30, 33.95 FROM Geofence WHERE Name = 'Larnaca_District';

DECLARE @LocGeoID INT, @LocLatMin DECIMAL(9,6), @LocLatMax DECIMAL(9,6), @LocLonMin DECIMAL(9,6), @LocLonMax DECIMAL(9,6);
DECLARE @LocTries INT, @LocIsInside BIT;
DECLARE @LocDistrictIdx INT;

WHILE @LocationCounter <= 20000
BEGIN
    -- Pick random district (round-robin)
    SET @LocDistrictIdx = ((@LocationCounter - 1) % 4) + 1;
    
    SELECT TOP 1 @Description = GeofenceName, @LocGeoID = GeofenceID, 
                 @LocLatMin = LatMin, @LocLatMax = LatMax, @LocLonMin = LonMin, @LocLonMax = LonMax,
                 @PostalCode = CASE GeofenceName 
                     WHEN 'Nicosia' THEN '1000' 
                     WHEN 'Limassol' THEN '3000' 
                     WHEN 'Larnaca' THEN '6000' 
                     WHEN 'Paphos' THEN '8000' 
                 END + CAST((ABS(CHECKSUM(NEWID())) % 900) + 100 AS NVARCHAR)
    FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY GeofenceName) AS rn FROM @LocationGeoTable) d
    WHERE rn = @LocDistrictIdx;
    
    -- Generate point inside geofence with retry
    SET @LocTries = 0;
    SET @LocIsInside = 0;
    WHILE @LocIsInside = 0 AND @LocTries < 100
    BEGIN
        SET @LatDegrees = @LocLatMin + (RAND(CHECKSUM(NEWID()) + @LocTries) * (@LocLatMax - @LocLatMin));
        SET @LonDegrees = @LocLonMin + (RAND(CHECKSUM(NEWID()) - @LocTries) * (@LocLonMax - @LocLonMin));
        SET @LocIsInside = dbo.fnIsPointInGeofence(@LatDegrees, @LonDegrees, @LocGeoID);
        SET @LocTries = @LocTries + 1;
    END
    
    -- Fallback to center if needed
    IF @LocIsInside = 0
    BEGIN
        SET @LatDegrees = (@LocLatMin + @LocLatMax) / 2.0;
        SET @LonDegrees = (@LocLonMin + @LocLonMax) / 2.0;
    END;
    
    SET @StreetAddress = CAST((ABS(CHECKSUM(NEWID())) % 999) + 1 AS NVARCHAR) + ' ' + 
                   CASE (ABS(CHECKSUM(NEWID())) % 10)
                       WHEN 0 THEN 'Main Street'
                       WHEN 1 THEN 'Kennedy Avenue'
                       WHEN 2 THEN 'Makarios Avenue'
                       WHEN 3 THEN 'Grivas Dhigenis'
                       WHEN 4 THEN 'Anexartisias Street'
                       WHEN 5 THEN 'Ledra Street'
                       WHEN 6 THEN 'Onasagorou Street'
                       WHEN 7 THEN 'Evagora Avenue'
                       WHEN 8 THEN 'Athalassas Avenue'
                       ELSE 'Archbishop Makarios III'
                   END;
    
    INSERT INTO [Location] (LatDegrees, LonDegrees, StreetAddress, Description, PostalCode)
    VALUES (@LatDegrees, @LonDegrees, @StreetAddress, @Description, @PostalCode);
    
    IF @LocationCounter % 5000 = 0
        PRINT 'Generated ' + CAST(@LocationCounter AS NVARCHAR) + ' locations...';
    
    SET @LocationCounter = @LocationCounter + 1;
END

PRINT 'Completed: 20,000 locations created';
GO

-- =============================================
-- 4. Generate 12,000 Ride Requests (various statuses)
-- =============================================
PRINT 'Generating 12,000 ride requests...';

-- Pre-load IDs for fast random selection (avoids ORDER BY NEWID() on large tables)
DECLARE @PassengerIDs TABLE (RowNum INT IDENTITY(1,1), PassengerID INT);
DECLARE @LocationIDs TABLE (RowNum INT IDENTITY(1,1), LocationID INT);

INSERT INTO @PassengerIDs (PassengerID) SELECT PassengerID FROM Passenger;
INSERT INTO @LocationIDs (LocationID) SELECT LocationID FROM [Location];

DECLARE @MaxPassenger INT = (SELECT COUNT(*) FROM @PassengerIDs);
DECLARE @MaxLocation INT = (SELECT COUNT(*) FROM @LocationIDs);

DECLARE @RideCounter INT = 1;
DECLARE @PassengerID INT;
DECLARE @PickupLocationID INT;
DECLARE @DropoffLocationID INT;
DECLARE @ServiceTypeID INT;
DECLARE @Status NVARCHAR(30);
DECLARE @RequestedAt DATETIME2;
DECLARE @RandPassenger INT;
DECLARE @RandPickup INT;
DECLARE @RandDropoff INT;

WHILE @RideCounter <= 12000
BEGIN
    -- Fast random selection using pre-loaded IDs
    SET @RandPassenger = (ABS(CHECKSUM(NEWID())) % @MaxPassenger) + 1;
    SET @RandPickup = (ABS(CHECKSUM(NEWID())) % @MaxLocation) + 1;
    SET @RandDropoff = (ABS(CHECKSUM(NEWID())) % @MaxLocation) + 1;
    IF @RandDropoff = @RandPickup SET @RandDropoff = (@RandDropoff % @MaxLocation) + 1;
    
    SELECT @PassengerID = PassengerID FROM @PassengerIDs WHERE RowNum = @RandPassenger;
    SELECT @PickupLocationID = LocationID FROM @LocationIDs WHERE RowNum = @RandPickup;
    SELECT @DropoffLocationID = LocationID FROM @LocationIDs WHERE RowNum = @RandDropoff;
    
    -- Random service type
    SET @ServiceTypeID = (ABS(CHECKSUM(NEWID())) % 5) + 1; -- Service types 1-5
    
    -- Random request time in past 180 days
    SET @RequestedAt = DATEADD(MINUTE, -ABS(CHECKSUM(NEWID())) % 259200, SYSDATETIME()); -- Past 180 days
    
    -- Status distribution: 60% completed, 15% cancelled, 10% assigned, 10% accepted, 5% pending
    DECLARE @StatusRand INT = ABS(CHECKSUM(NEWID())) % 100;
    SET @Status = CASE 
        WHEN @StatusRand < 60 THEN 'completed'
        WHEN @StatusRand < 75 THEN 'cancelled'
        WHEN @StatusRand < 85 THEN 'assigned'
        WHEN @StatusRand < 95 THEN 'accepted'
        ELSE 'pending'
    END;
    
    -- Set RealDriversOnly flag (50% for real drivers, 50% for simulated)
    DECLARE @IsRealDriverRide BIT = CASE WHEN (ABS(CHECKSUM(NEWID())) % 2) = 0 THEN 1 ELSE 0 END;
    
    INSERT INTO RideRequest (PassengerID, ServiceTypeID, RequestedAt, PickupLocationID, 
                            DropoffLocationID, Status, PaymentMethodTypeID, WheelchairNeeded, RealDriversOnly)
    VALUES (@PassengerID, @ServiceTypeID, @RequestedAt, @PickupLocationID, 
            @DropoffLocationID, @Status, 
            (ABS(CHECKSUM(NEWID())) % 2) + 1, -- Random payment method 1-2
            CASE WHEN (ABS(CHECKSUM(NEWID())) % 20) = 0 THEN 1 ELSE 0 END, -- 5% wheelchair
            @IsRealDriverRide); -- 50% real drivers, 50% simulated
    
    IF @RideCounter % 2000 = 0
        PRINT 'Generated ' + CAST(@RideCounter AS NVARCHAR) + ' ride requests...';
    
    SET @RideCounter = @RideCounter + 1;
END

PRINT 'Completed: 12,000 ride requests created';
GO

-- =============================================
-- 5. Generate Trips for Completed/Assigned Rides (SET-BASED - FAST)
-- =============================================
PRINT 'Generating trips for completed and assigned rides...';

-- Create trips matching driver type and geofence
-- For RealDriversOnly=0 rides, assign simulated drivers (UseGPS=0)
-- For RealDriversOnly=1 rides, assign simulated drivers too (stress test has UseGPS=0)
INSERT INTO Trip (RideRequestID, DriverID, VehicleID, DispatchTime, StartTime, EndTime, TotalDistanceKm, Status, IsRealDriverTrip)
SELECT 
    rr.RideRequestID,
    d.DriverID,
    v.VehicleID,
    rr.RequestedAt, -- DispatchTime
    DATEADD(MINUTE, ABS(CHECKSUM(NEWID())) % 30, rr.RequestedAt), -- StartTime 0-30 min after
    CASE 
        WHEN rr.Status = 'completed' THEN 
            DATEADD(MINUTE, (5.0 + (RAND(CHECKSUM(NEWID())) * 45.0)) * 2, 
                    DATEADD(MINUTE, ABS(CHECKSUM(NEWID())) % 30, rr.RequestedAt))
        ELSE NULL 
    END, -- EndTime
    CASE WHEN rr.Status = 'completed' THEN ROUND(5.0 + (RAND(CHECKSUM(NEWID())) * 45.0), 3) ELSE NULL END, -- Distance
    CASE 
        WHEN rr.Status = 'completed' THEN 'completed'
        WHEN rr.Status = 'accepted' THEN 'in_progress'
        ELSE 'assigned'
    END, -- Status
    0 -- IsRealDriverTrip (stress test drivers are simulated)
FROM RideRequest rr
INNER JOIN [Location] pickup ON rr.PickupLocationID = pickup.LocationID
-- Join to driver with vehicle in same geofence as pickup
INNER JOIN Vehicle v ON v.IsActive = 1
INNER JOIN Driver d ON v.DriverID = d.DriverID AND d.UseGPS = 0 -- Simulated drivers only
INNER JOIN GeofenceLog gl ON v.VehicleID = gl.VehicleID AND gl.ExitedAt IS NULL
WHERE rr.Status IN ('completed', 'assigned', 'accepted')
  AND dbo.fnIsPointInGeofence(pickup.LatDegrees, pickup.LonDegrees, gl.GeofenceID) = 1
  -- Match one driver per ride using modulo on RideRequestID
  AND v.VehicleID = (
      SELECT TOP 1 v2.VehicleID
      FROM Vehicle v2
      INNER JOIN GeofenceLog gl2 ON v2.VehicleID = gl2.VehicleID AND gl2.ExitedAt IS NULL
      WHERE v2.IsActive = 1
        AND dbo.fnIsPointInGeofence(pickup.LatDegrees, pickup.LonDegrees, gl2.GeofenceID) = 1
      ORDER BY (v2.VehicleID + rr.RideRequestID) % 997 -- Pseudo-random but deterministic
  );

DECLARE @TripCount INT = @@ROWCOUNT;

PRINT 'Completed: ' + CAST(@TripCount AS NVARCHAR) + ' trips created';
GO

-- =============================================
-- 6. Generate Payments for Completed Trips (SET-BASED - FAST)
-- =============================================
PRINT 'Generating payments for completed trips...';

-- SET-BASED insert - much faster than cursor!
INSERT INTO Payment (TripID, PaymentMethodTypeID, Amount, CurrencyCode, Status, CreatedAt, CompletedAt)
SELECT 
    t.TripID,
    (ABS(CHECKSUM(NEWID())) % 2) + 1, -- Random payment method 1-2
    ROUND(10.0 + (RAND(CHECKSUM(NEWID())) * 90.0), 2), -- 10-100 EUR
    'EUR',
    'completed',
    t.EndTime, -- CreatedAt
    DATEADD(MINUTE, ABS(CHECKSUM(NEWID())) % 10, t.EndTime) -- CompletedAt
FROM Trip t
WHERE t.Status = 'completed' AND t.EndTime IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM Payment p WHERE p.TripID = t.TripID);

DECLARE @PaymentCount INT = @@ROWCOUNT;
PRINT 'Completed: ' + CAST(@PaymentCount AS NVARCHAR) + ' payments created';
GO

-- =============================================
-- 7. Generate Ratings for Completed Trips (SET-BASED - FAST)
-- =============================================
PRINT 'Generating ratings for completed trips (80% of trips)...';

-- SET-BASED insert - much faster than cursor!
INSERT INTO Rating (TripID, FromUserID, ToUserID, Stars, Comment)
SELECT 
    t.TripID,
    pu.UserID AS PassengerUserID,
    du.UserID AS DriverUserID,
    -- Stars tend to be high (4-5 mostly)
    CASE (ABS(CHECKSUM(NEWID())) % 10)
        WHEN 0 THEN 3 WHEN 1 THEN 3 WHEN 2 THEN 4 WHEN 3 THEN 4 
        WHEN 4 THEN 4 WHEN 5 THEN 4 ELSE 5
    END,
    -- 30% get comments
    CASE WHEN (ABS(CHECKSUM(NEWID())) % 10) < 3 THEN
        CASE (ABS(CHECKSUM(NEWID())) % 10)
            WHEN 0 THEN 'Great service, very professional!'
            WHEN 1 THEN 'Good ride, on time.'
            WHEN 2 THEN 'Friendly driver, clean vehicle.'
            WHEN 3 THEN 'Excellent experience!'
            WHEN 4 THEN 'Very satisfied with the service.'
            WHEN 5 THEN 'Could be better, but acceptable.'
            WHEN 6 THEN 'Nice and comfortable ride.'
            WHEN 7 THEN 'Professional and courteous.'
            WHEN 8 THEN 'Quick and efficient.'
            ELSE 'Would recommend!'
        END
    ELSE NULL END
FROM Trip t
INNER JOIN RideRequest rr ON t.RideRequestID = rr.RideRequestID
INNER JOIN Passenger p ON rr.PassengerID = p.PassengerID
INNER JOIN [User] pu ON p.UserID = pu.UserID
INNER JOIN Driver d ON t.DriverID = d.DriverID
INNER JOIN [User] du ON d.UserID = du.UserID
WHERE t.Status = 'completed'
AND (ABS(CHECKSUM(NEWID()) + t.TripID) % 10) < 8; -- ~80% of trips

DECLARE @RatingCount INT = @@ROWCOUNT;
PRINT 'Completed: ' + CAST(@RatingCount AS NVARCHAR) + ' ratings created';
GO

-- =============================================
-- 8. Generate Autonomous Vehicles (500 vehicles)
-- =============================================

PRINT 'Generating 150 autonomous vehicles (Paphos, Nicosia, Larnaca, Limassol only)...';

-- Use the existing geofences with proper names from osrh_seeding.sql
DECLARE @DistrictGeofences TABLE (District NVARCHAR(20), GeofenceName NVARCHAR(50), GeofenceID INT, LatMin DECIMAL(9,6), LatMax DECIMAL(9,6), LonMin DECIMAL(9,6), LonMax DECIMAL(9,6));

-- Load existing geofence IDs (these must already exist from osrh_seeding.sql)
INSERT INTO @DistrictGeofences (District, GeofenceName, GeofenceID, LatMin, LatMax, LonMin, LonMax)
SELECT 'Paphos', 'Paphos_District', GeofenceID, 34.65, 35.18, 32.26, 32.80 FROM Geofence WHERE Name = 'Paphos_District'
UNION ALL
SELECT 'Limassol', 'Limassol_District', GeofenceID, 34.58, 35.01, 32.63, 33.40 FROM Geofence WHERE Name = 'Limassol_District'
UNION ALL
SELECT 'Nicosia', 'Nicosia_District', GeofenceID, 34.93, 35.20, 32.62, 33.50 FROM Geofence WHERE Name = 'Nicosia_District'
UNION ALL
SELECT 'Larnaca', 'Larnaca_District', GeofenceID, 34.65, 35.18, 33.30, 33.95 FROM Geofence WHERE Name = 'Larnaca_District';

-- Verify all 4 geofences exist
DECLARE @GeofenceCount INT = (SELECT COUNT(*) FROM @DistrictGeofences WHERE GeofenceID IS NOT NULL);
IF @GeofenceCount < 4
BEGIN
    RAISERROR('ERROR: Not all geofences found! Please run osrh_seeding.sql first to create the geofences.', 16, 1);
    RETURN;
END

PRINT 'Found all 4 district geofences.';

-- Now generate 150 AVs, distributed across the 4 districts
DECLARE @AVCounter INT = 1;
DECLARE @VehicleCode NVARCHAR(50);
DECLARE @AVPlateNo NVARCHAR(20);
DECLARE @AVLat DECIMAL(9,6);
DECLARE @AVLon DECIMAL(9,6);
DECLARE @AVStatus NVARCHAR(30);
DECLARE @DistrictIdx INT;
DECLARE @DistrictName NVARCHAR(20);
DECLARE @GeofenceID INT;
DECLARE @ZoneLatMin DECIMAL(9,6);
DECLARE @ZoneLatMax DECIMAL(9,6);
DECLARE @ZoneLonMin DECIMAL(9,6);
DECLARE @ZoneLonMax DECIMAL(9,6);

WHILE @AVCounter <= 150
BEGIN
    -- Assign district in round-robin (1-4)
    SET @DistrictIdx = ((@AVCounter - 1) % 4) + 1;
    
    -- Get the geofence info for this district
    SELECT TOP 1 
        @DistrictName = District, 
        @GeofenceID = GeofenceID,
        @ZoneLatMin = LatMin, 
        @ZoneLatMax = LatMax, 
        @ZoneLonMin = LonMin, 
        @ZoneLonMax = LonMax
    FROM (
        SELECT *, ROW_NUMBER() OVER (ORDER BY District) AS rn 
        FROM @DistrictGeofences
    ) d 
    WHERE rn = @DistrictIdx;

    SET @VehicleCode = 'AV-' + RIGHT('0000' + CAST(@AVCounter AS NVARCHAR), 4);
    SET @AVPlateNo = 'AV' + RIGHT('00000' + CAST(@AVCounter AS NVARCHAR), 5);

    -- Generate a random point inside the actual geofence polygon
    DECLARE @Tries INT = 0;
    DECLARE @IsInside BIT = 0;
    WHILE @IsInside = 0 AND @Tries < 1000
    BEGIN
        SET @AVLat = @ZoneLatMin + (RAND(CHECKSUM(NEWID()) + @Tries) * (@ZoneLatMax - @ZoneLatMin));
        SET @AVLon = @ZoneLonMin + (RAND(CHECKSUM(NEWID()) - @Tries) * (@ZoneLonMax - @ZoneLonMin));
        SET @IsInside = dbo.fnIsPointInGeofence(@AVLat, @AVLon, @GeofenceID);
        SET @Tries = @Tries + 1;
    END
    
    -- If we couldn't find a point inside (shouldn't happen), use the center of bounding box
    IF @IsInside = 0
    BEGIN
        SET @AVLat = (@ZoneLatMin + @ZoneLatMax) / 2.0;
        SET @AVLon = (@ZoneLonMin + @ZoneLonMax) / 2.0;
    END

    SET @AVStatus = CASE (ABS(CHECKSUM(NEWID())) % 5)
        WHEN 0 THEN 'available'
        WHEN 1 THEN 'available'
        WHEN 2 THEN 'busy'
        WHEN 3 THEN 'charging'
        ELSE 'maintenance'
    END;

    INSERT INTO AutonomousVehicle (VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color, 
        SeatingCapacity, IsWheelchairReady, Status, CurrentLatitude, CurrentLongitude, 
        LocationUpdatedAt, GeofenceID, BatteryLevel, IsActive, CreatedAt, UpdatedAt)
    VALUES (@VehicleCode,
            (ABS(CHECKSUM(NEWID())) % 4) + 1, -- Vehicle type 1-4
            @AVPlateNo,
            CASE (ABS(CHECKSUM(NEWID())) % 3) WHEN 0 THEN 'Waymo' WHEN 1 THEN 'Tesla' ELSE 'Cruise' END,
            CASE (ABS(CHECKSUM(NEWID())) % 3) WHEN 0 THEN 'One' WHEN 1 THEN 'Model Y' ELSE 'Origin' END,
            2024,
            CASE (ABS(CHECKSUM(NEWID())) % 4) WHEN 0 THEN 'White' WHEN 1 THEN 'Black' WHEN 2 THEN 'Silver' ELSE 'Blue' END,
            4,
            CASE WHEN (ABS(CHECKSUM(NEWID())) % 5) = 0 THEN 1 ELSE 0 END, -- 20% wheelchair ready
            @AVStatus,
            @AVLat, @AVLon,
            DATEADD(MINUTE, -ABS(CHECKSUM(NEWID())) % 60, SYSDATETIME()),
            @GeofenceID,
            50 + (ABS(CHECKSUM(NEWID())) % 50), -- Battery 50-100%
            1,
            DATEADD(DAY, -ABS(CHECKSUM(NEWID())) % 365, SYSDATETIME()),
            SYSDATETIME());

    IF @AVCounter % 50 = 0
        PRINT 'Generated ' + CAST(@AVCounter AS NVARCHAR) + ' autonomous vehicles...';

    SET @AVCounter = @AVCounter + 1;
END

PRINT 'Completed: 150 autonomous vehicles created';
GO

-- =============================================
-- 9. Generate Autonomous Rides (10000 rides)
-- =============================================
PRINT 'Generating 10,000 autonomous rides...';

-- Pre-load IDs for fast random selection
DECLARE @ARPassengerIDs TABLE (RowNum INT IDENTITY(1,1), PassengerID INT);
DECLARE @ARLocationIDs TABLE (RowNum INT IDENTITY(1,1), LocationID INT);
DECLARE @AVehicleIDs TABLE (RowNum INT IDENTITY(1,1), AutonomousVehicleID INT);

INSERT INTO @ARPassengerIDs (PassengerID) SELECT PassengerID FROM Passenger;
INSERT INTO @ARLocationIDs (LocationID) SELECT LocationID FROM [Location];
INSERT INTO @AVehicleIDs (AutonomousVehicleID) SELECT AutonomousVehicleID FROM AutonomousVehicle;

DECLARE @ARMaxPassenger INT = (SELECT COUNT(*) FROM @ARPassengerIDs);
DECLARE @ARMaxLocation INT = (SELECT COUNT(*) FROM @ARLocationIDs);
DECLARE @ARMaxVehicle INT = (SELECT COUNT(*) FROM @AVehicleIDs);

DECLARE @ARideCounter INT = 1;
DECLARE @ARPassengerID INT;
DECLARE @AVID INT;
DECLARE @ARPickupLocID INT;
DECLARE @ARDropoffLocID INT;
DECLARE @ARStatus NVARCHAR(30);
DECLARE @ARRequestedAt DATETIME2;
DECLARE @ARRandP INT, @ARRandV INT, @ARRandPickup INT, @ARRandDrop INT;

WHILE @ARideCounter <= 10000
BEGIN
    -- Fast random selection
    SET @ARRandP = (ABS(CHECKSUM(NEWID())) % @ARMaxPassenger) + 1;
    SET @ARRandV = (ABS(CHECKSUM(NEWID())) % @ARMaxVehicle) + 1;
    SET @ARRandPickup = (ABS(CHECKSUM(NEWID())) % @ARMaxLocation) + 1;
    SET @ARRandDrop = (ABS(CHECKSUM(NEWID())) % @ARMaxLocation) + 1;
    IF @ARRandDrop = @ARRandPickup SET @ARRandDrop = (@ARRandDrop % @ARMaxLocation) + 1;
    
    SELECT @ARPassengerID = PassengerID FROM @ARPassengerIDs WHERE RowNum = @ARRandP;
    SELECT @AVID = AutonomousVehicleID FROM @AVehicleIDs WHERE RowNum = @ARRandV;
    SELECT @ARPickupLocID = LocationID FROM @ARLocationIDs WHERE RowNum = @ARRandPickup;
    SELECT @ARDropoffLocID = LocationID FROM @ARLocationIDs WHERE RowNum = @ARRandDrop;
    
    SET @ARRequestedAt = DATEADD(MINUTE, -ABS(CHECKSUM(NEWID())) % 259200, SYSDATETIME());
    
    SET @ARStatus = CASE (ABS(CHECKSUM(NEWID())) % 10)
        WHEN 0 THEN 'requested'
        WHEN 1 THEN 'vehicle_dispatched'
        WHEN 2 THEN 'in_progress'
        ELSE 'completed'
    END;
    
    -- Generate fare and timestamps for completed rides
    DECLARE @AREstFare DECIMAL(10,2) = ROUND(8.0 + (RAND(CHECKSUM(NEWID())) * 40.0), 2);
    DECLARE @ARDistance DECIMAL(10,3) = ROUND(2.0 + (RAND(CHECKSUM(NEWID())) * 25.0), 3);
    DECLARE @ARDuration INT = 10 + (ABS(CHECKSUM(NEWID())) % 50); -- 10-60 minutes
    
    INSERT INTO AutonomousRide (PassengerID, AutonomousVehicleID, PickupLocationID, DropoffLocationID,
        RequestedAt, Status, PaymentMethodTypeID, EstimatedFare, ActualFare, 
        TripStartedAt, TripCompletedAt, ActualDistanceKm, ActualDurationSec, WheelchairNeeded)
    VALUES (@ARPassengerID, @AVID, @ARPickupLocID, @ARDropoffLocID,
            @ARRequestedAt, @ARStatus,
            (ABS(CHECKSUM(NEWID())) % 2) + 1,
            @AREstFare,
            CASE WHEN @ARStatus = 'completed' THEN @AREstFare ELSE NULL END,
            CASE WHEN @ARStatus IN ('in_progress', 'completed') THEN DATEADD(MINUTE, 5, @ARRequestedAt) ELSE NULL END,
            CASE WHEN @ARStatus = 'completed' THEN DATEADD(MINUTE, 5 + @ARDuration, @ARRequestedAt) ELSE NULL END,
            CASE WHEN @ARStatus = 'completed' THEN @ARDistance ELSE NULL END,
            CASE WHEN @ARStatus = 'completed' THEN @ARDuration * 60 ELSE NULL END,
            CASE WHEN (ABS(CHECKSUM(NEWID())) % 20) = 0 THEN 1 ELSE 0 END);
    
    IF @ARideCounter % 2000 = 0
        PRINT 'Generated ' + CAST(@ARideCounter AS NVARCHAR) + ' autonomous rides...';
    
    SET @ARideCounter = @ARideCounter + 1;
END

PRINT 'Completed: 10,000 autonomous rides created';
GO
-- =============================================
-- 10. Generate CarShare Zones (12 zones inside geofences)
-- =============================================
PRINT 'Generating 12 carshare zones (inside geofences)...';

-- Load district geofences for zone placement
DECLARE @ZoneDistrictGeo TABLE (District NVARCHAR(20), GeofenceID INT, LatMin DECIMAL(9,6), LatMax DECIMAL(9,6), LonMin DECIMAL(9,6), LonMax DECIMAL(9,6));

INSERT INTO @ZoneDistrictGeo (District, GeofenceID, LatMin, LatMax, LonMin, LonMax)
SELECT 'Paphos', GeofenceID, 34.65, 35.18, 32.26, 32.80 FROM Geofence WHERE Name = 'Paphos_District'
UNION ALL
SELECT 'Limassol', GeofenceID, 34.58, 35.01, 32.63, 33.40 FROM Geofence WHERE Name = 'Limassol_District'
UNION ALL
SELECT 'Nicosia', GeofenceID, 34.93, 35.20, 32.62, 33.50 FROM Geofence WHERE Name = 'Nicosia_District'
UNION ALL
SELECT 'Larnaca', GeofenceID, 34.65, 35.18, 33.30, 33.95 FROM Geofence WHERE Name = 'Larnaca_District';

-- Generate 3 zones per district (12 total)
DECLARE @ZoneCounter INT = 1;
DECLARE @ZCity NVARCHAR(100);
DECLARE @ZLat DECIMAL(9,6);
DECLARE @ZLon DECIMAL(9,6);
DECLARE @ZGeoID INT;
DECLARE @ZDistIdx INT;
DECLARE @ZLatMin DECIMAL(9,6), @ZLatMax DECIMAL(9,6), @ZLonMin DECIMAL(9,6), @ZLonMax DECIMAL(9,6);
DECLARE @ZTries INT;
DECLARE @ZIsInside BIT;

WHILE @ZoneCounter <= 12
BEGIN
    -- Assign district in round-robin (3 zones per district)
    SET @ZDistIdx = (((@ZoneCounter - 1) / 3) % 4) + 1;
    
    -- Get geofence info
    SELECT TOP 1 @ZCity = District, @ZGeoID = GeofenceID, @ZLatMin = LatMin, @ZLatMax = LatMax, @ZLonMin = LonMin, @ZLonMax = LonMax
    FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY District) AS rn FROM @ZoneDistrictGeo) d
    WHERE rn = @ZDistIdx;
    
    -- Generate random point inside geofence
    SET @ZTries = 0;
    SET @ZIsInside = 0;
    WHILE @ZIsInside = 0 AND @ZTries < 1000
    BEGIN
        SET @ZLat = @ZLatMin + (RAND(CHECKSUM(NEWID()) + @ZTries) * (@ZLatMax - @ZLatMin));
        SET @ZLon = @ZLonMin + (RAND(CHECKSUM(NEWID()) - @ZTries) * (@ZLonMax - @ZLonMin));
        SET @ZIsInside = dbo.fnIsPointInGeofence(@ZLat, @ZLon, @ZGeoID);
        SET @ZTries = @ZTries + 1;
    END
    
    -- Fallback to center if needed
    IF @ZIsInside = 0
    BEGIN
        SET @ZLat = (@ZLatMin + @ZLatMax) / 2.0;
        SET @ZLon = (@ZLonMin + @ZLonMax) / 2.0;
    END
    
    INSERT INTO CarshareZone (ZoneName, City, CenterLatitude, CenterLongitude, RadiusMeters, 
        ZoneType, IsActive, Description)
    VALUES (@ZCity + ' Zone ' + CAST(@ZoneCounter AS NVARCHAR),
            @ZCity,
            @ZLat,
            @ZLon,
            500 + (ABS(CHECKSUM(NEWID())) % 1500), -- Radius 500-2000m
            CASE (ABS(CHECKSUM(NEWID())) % 3) WHEN 0 THEN 'standard' WHEN 1 THEN 'premium' ELSE 'airport' END,
            1,
            'Carshare zone in ' + @ZCity);
    
    SET @ZoneCounter = @ZoneCounter + 1;
END

PRINT 'Completed: 12 carshare zones created';
GO

-- =============================================
-- 11. Generate CarShare Vehicles (150 vehicles)
-- =============================================
PRINT 'Generating 150 carshare vehicles (inside geofences)...';

-- Check for existing corrupt records
DECLARE @NullCount INT = (SELECT COUNT(*) FROM CarshareVehicle WHERE PlateNumber IS NULL);
IF @NullCount > 0
    PRINT 'WARNING: Found ' + CAST(@NullCount AS VARCHAR) + ' records with NULL PlateNumber!';

-- Show existing vehicle count
DECLARE @ExistingVehicles INT = (SELECT COUNT(*) FROM CarshareVehicle);
PRINT 'Existing vehicles in table: ' + CAST(@ExistingVehicles AS VARCHAR);

-- Aggressively clean up any corrupt data from previous runs
BEGIN TRY
    -- Delete dependent records first (need to handle FK constraints)
    DELETE FROM CarshareRental WHERE VehicleID IN (SELECT VehicleID FROM CarshareVehicle WHERE PlateNumber IS NULL);
    DELETE FROM CarshareBooking WHERE VehicleID IN (SELECT VehicleID FROM CarshareVehicle WHERE PlateNumber IS NULL);
    DELETE FROM CarshareVehicle WHERE PlateNumber IS NULL;
    PRINT 'Cleaned up corrupt vehicle records';
END TRY
BEGIN CATCH
    PRINT 'Warning: Could not clean up NULL plate records - ' + ERROR_MESSAGE();
END CATCH

-- DISABLE ALL TRIGGERS on CarshareVehicle during bulk insert
ALTER TABLE CarshareVehicle DISABLE TRIGGER ALL;
PRINT 'All CarshareVehicle triggers disabled';

-- Ensure we have at least one zone
IF NOT EXISTS (SELECT 1 FROM CarshareZone)
BEGIN
    PRINT 'ERROR: No CarshareZones found. Creating default zone...';
    INSERT INTO CarshareZone (ZoneName, City, CenterLatitude, CenterLongitude, RadiusMeters, ZoneType, IsActive, Description)
    VALUES ('Default Zone', 'Nicosia', 35.1856, 33.3823, 1000, 'standard', 1, 'Default carshare zone');
END

-- Ensure we have at least one vehicle type
IF NOT EXISTS (SELECT 1 FROM CarshareVehicleType)
BEGIN
    PRINT 'Creating default CarshareVehicleType...';
    INSERT INTO CarshareVehicleType (TypeCode, TypeName, Description, SeatingCapacity) 
    VALUES ('ECONOMY', 'Economy', 'Standard economy car', 5);
END

-- Load the 4 district geofences for carshare vehicle placement
DECLARE @CSDistrictGeofences TABLE (District NVARCHAR(20), GeofenceID INT, LatMin DECIMAL(9,6), LatMax DECIMAL(9,6), LonMin DECIMAL(9,6), LonMax DECIMAL(9,6));

INSERT INTO @CSDistrictGeofences (District, GeofenceID, LatMin, LatMax, LonMin, LonMax)
SELECT 'Paphos', GeofenceID, 34.65, 35.18, 32.26, 32.80 FROM Geofence WHERE Name = 'Paphos_District'
UNION ALL
SELECT 'Limassol', GeofenceID, 34.58, 35.01, 32.63, 33.40 FROM Geofence WHERE Name = 'Limassol_District'
UNION ALL
SELECT 'Nicosia', GeofenceID, 34.93, 35.20, 32.62, 33.50 FROM Geofence WHERE Name = 'Nicosia_District'
UNION ALL
SELECT 'Larnaca', GeofenceID, 34.65, 35.18, 33.30, 33.95 FROM Geofence WHERE Name = 'Larnaca_District';

DECLARE @CSGeoCount INT = (SELECT COUNT(*) FROM @CSDistrictGeofences WHERE GeofenceID IS NOT NULL);
IF @CSGeoCount < 4
BEGIN
    RAISERROR('ERROR: Not all geofences found for CarShare! Please run osrh_seeding.sql first.', 16, 1);
    RETURN;
END

-- Generate 150 plate numbers
DECLARE @VehiclePlates TABLE (PlateNum NVARCHAR(20) NOT NULL, RowNum INT);

;WITH Numbers AS (
    SELECT TOP 150 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n
    FROM sys.objects a CROSS JOIN sys.objects b
)
INSERT INTO @VehiclePlates (PlateNum, RowNum)
SELECT 'CS' + RIGHT('00000' + CAST(n AS VARCHAR(10)), 5), n
FROM Numbers;

-- Get default VehicleTypeID and ZoneID
DECLARE @DefaultVehicleTypeID INT;
DECLARE @DefaultZoneID INT;
SELECT TOP 1 @DefaultVehicleTypeID = VehicleTypeID FROM CarshareVehicleType;
SELECT TOP 1 @DefaultZoneID = ZoneID FROM CarshareZone;

-- Insert vehicles with locations INSIDE geofences
DECLARE @CSVCounter INT = 1;
DECLARE @CSPlate NVARCHAR(20);
DECLARE @CSLat DECIMAL(9,6);
DECLARE @CSLon DECIMAL(9,6);
DECLARE @CSGeoID INT;
DECLARE @CSDistIdx INT;
DECLARE @CSLatMin DECIMAL(9,6), @CSLatMax DECIMAL(9,6), @CSLonMin DECIMAL(9,6), @CSLonMax DECIMAL(9,6);
DECLARE @CSTries INT;
DECLARE @CSIsInside BIT;

WHILE @CSVCounter <= 150
BEGIN
    -- Assign district in round-robin
    SET @CSDistIdx = ((@CSVCounter - 1) % 4) + 1;
    
    -- Get geofence info
    SELECT TOP 1 @CSGeoID = GeofenceID, @CSLatMin = LatMin, @CSLatMax = LatMax, @CSLonMin = LonMin, @CSLonMax = LonMax
    FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY District) AS rn FROM @CSDistrictGeofences) d
    WHERE rn = @CSDistIdx;
    
    SET @CSPlate = 'CS' + RIGHT('00000' + CAST(@CSVCounter AS VARCHAR(10)), 5);
    
    -- Generate random point inside geofence
    SET @CSTries = 0;
    SET @CSIsInside = 0;
    WHILE @CSIsInside = 0 AND @CSTries < 1000
    BEGIN
        SET @CSLat = @CSLatMin + (RAND(CHECKSUM(NEWID()) + @CSTries) * (@CSLatMax - @CSLatMin));
        SET @CSLon = @CSLonMin + (RAND(CHECKSUM(NEWID()) - @CSTries) * (@CSLonMax - @CSLonMin));
        SET @CSIsInside = dbo.fnIsPointInGeofence(@CSLat, @CSLon, @CSGeoID);
        SET @CSTries = @CSTries + 1;
    END
    
    -- Fallback to center if needed
    IF @CSIsInside = 0
    BEGIN
        SET @CSLat = (@CSLatMin + @CSLatMax) / 2.0;
        SET @CSLon = (@CSLonMin + @CSLonMax) / 2.0;
    END
    
    -- Insert vehicle
    IF NOT EXISTS (SELECT 1 FROM CarshareVehicle WHERE PlateNumber = @CSPlate)
    BEGIN
        INSERT INTO CarshareVehicle (VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color,
            FuelLevelPercent, CurrentZoneID, Status, CurrentLatitude, CurrentLongitude, 
            OdometerKm, IsActive, CleanlinessRating)
        VALUES (
            @DefaultVehicleTypeID,
            @CSPlate,
            'STRESS' + RIGHT('0000000000' + CAST(@CSVCounter AS VARCHAR(10)), 10),
            CASE (@CSVCounter % 5) WHEN 0 THEN 'Toyota' WHEN 1 THEN 'VW' WHEN 2 THEN 'Renault' WHEN 3 THEN 'Fiat' ELSE 'Peugeot' END,
            CASE (@CSVCounter % 5) WHEN 0 THEN 'Yaris' WHEN 1 THEN 'Polo' WHEN 2 THEN 'Clio' WHEN 3 THEN '500' ELSE '208' END,
            2020 + (@CSVCounter % 5),
            CASE (@CSVCounter % 5) WHEN 0 THEN 'White' WHEN 1 THEN 'Black' WHEN 2 THEN 'Red' WHEN 3 THEN 'Blue' ELSE 'Silver' END,
            30 + (@CSVCounter % 70),
            @DefaultZoneID,
            'available',
            @CSLat,
            @CSLon,
            5000 + (@CSVCounter * 10),
            1,
            3 + (@CSVCounter % 3)
        );
    END
    
    IF @CSVCounter % 50 = 0
        PRINT 'Generated ' + CAST(@CSVCounter AS VARCHAR) + ' carshare vehicles...';
    
    SET @CSVCounter = @CSVCounter + 1;
END

PRINT 'Inserted carshare vehicles: ' + CAST(@@ROWCOUNT AS VARCHAR);
PRINT 'Completed: CarShare vehicles created';

-- RE-ENABLE all triggers after bulk insert
ALTER TABLE CarshareVehicle ENABLE TRIGGER ALL;
PRINT 'All CarshareVehicle triggers re-enabled';
GO

-- =============================================
-- 12. Generate CarShare Customers (SET-BASED - FAST)
-- =============================================
PRINT 'Generating 5,000 carshare customers...';

-- SET-BASED insert - much faster than cursor!
INSERT INTO CarshareCustomer (PassengerID, LicenseNumber, LicenseCountry, LicenseIssueDate, 
    LicenseExpiryDate, LicenseVerified, DateOfBirth, VerificationStatus, TotalRentals, 
    TotalDistanceKm, TotalSpentEUR, MembershipTier, EmailNotifications, SMSNotifications)
SELECT TOP 5000
    PassengerID,
    'CY' + RIGHT('000000' + CAST(ABS(CHECKSUM(NEWID())) % 1000000 AS NVARCHAR), 6),
    'Cyprus',
    DATEADD(YEAR, -5 - (ABS(CHECKSUM(NEWID())) % 10), SYSDATETIME()),
    DATEADD(YEAR, 1 + (ABS(CHECKSUM(NEWID())) % 5), SYSDATETIME()),
    CASE WHEN (ABS(CHECKSUM(NEWID())) % 10) < 8 THEN 1 ELSE 0 END, -- 80% verified
    DATEADD(YEAR, -21 - (ABS(CHECKSUM(NEWID())) % 40), SYSDATETIME()),
    CASE (ABS(CHECKSUM(NEWID())) % 10) WHEN 0 THEN 'pending' WHEN 1 THEN 'rejected' ELSE 'approved' END,
    ABS(CHECKSUM(NEWID())) % 50,
    ROUND(RAND(CHECKSUM(NEWID())) * 5000, 2),
    ROUND(RAND(CHECKSUM(NEWID())) * 2000, 2),
    CASE (ABS(CHECKSUM(NEWID())) % 4) WHEN 0 THEN 'basic' WHEN 1 THEN 'silver' WHEN 2 THEN 'gold' ELSE 'platinum' END,
    1, 1
FROM Passenger
ORDER BY NEWID();

DECLARE @CSCustCount INT = @@ROWCOUNT;
PRINT 'Completed: ' + CAST(@CSCustCount AS NVARCHAR) + ' carshare customers created';
GO

-- =============================================
-- 13. Generate CarShare Bookings (15000 bookings)
-- =============================================
PRINT 'Generating carshare bookings (target: 15,000)...';

-- Pre-load IDs for fast random selection
DECLARE @CSCustIDs TABLE (RowNum INT IDENTITY(1,1), CustomerID INT);
DECLARE @CSVehIDs TABLE (RowNum INT IDENTITY(1,1), VehicleID INT, CurrentZoneID INT);

INSERT INTO @CSCustIDs (CustomerID) SELECT CustomerID FROM CarshareCustomer WHERE VerificationStatus = 'approved';
INSERT INTO @CSVehIDs (VehicleID, CurrentZoneID) SELECT VehicleID, CurrentZoneID FROM CarshareVehicle;

DECLARE @CSMaxCust INT = (SELECT COUNT(*) FROM @CSCustIDs);
DECLARE @CSMaxVeh INT = (SELECT COUNT(*) FROM @CSVehIDs);

DECLARE @CSBookCounter INT = 0;
DECLARE @CSBookAttempts INT = 0;
DECLARE @CSCustID INT;
DECLARE @CSVehID INT;
DECLARE @CSBookZoneID INT;
DECLARE @CSBookedAt DATETIME2;
DECLARE @CSRandCust INT, @CSRandVeh INT;

WHILE @CSBookCounter < 20000 AND @CSBookAttempts < 50000
BEGIN
    SET @CSBookAttempts = @CSBookAttempts + 1;
    
    -- Fast random selection
    SET @CSRandCust = (ABS(CHECKSUM(NEWID())) % @CSMaxCust) + 1;
    SET @CSRandVeh = (ABS(CHECKSUM(NEWID())) % @CSMaxVeh) + 1;
    
    SELECT @CSCustID = CustomerID FROM @CSCustIDs WHERE RowNum = @CSRandCust;
    SELECT @CSVehID = VehicleID, @CSBookZoneID = CurrentZoneID FROM @CSVehIDs WHERE RowNum = @CSRandVeh;
    
    SET @CSBookedAt = DATEADD(MINUTE, -ABS(CHECKSUM(NEWID())) % 259200, SYSDATETIME());
    
    BEGIN TRY
        INSERT INTO CarshareBooking (CustomerID, VehicleID, BookedAt, ReservationStartAt, ReservationExpiresAt,
            PricingMode, EstimatedDurationMin, Status, PickupZoneID, DepositAmount, DepositHeld)
        VALUES (@CSCustID, @CSVehID, @CSBookedAt,
                DATEADD(MINUTE, 30, @CSBookedAt),
                DATEADD(MINUTE, 50, @CSBookedAt),
                CASE (ABS(CHECKSUM(NEWID())) % 3) WHEN 0 THEN 'per_minute' WHEN 1 THEN 'per_hour' ELSE 'per_day' END,
                30 + (ABS(CHECKSUM(NEWID())) % 480),
                -- Only create old bookings that are completed/expired/cancelled (vehicles available)
                CASE (ABS(CHECKSUM(NEWID())) % 10) 
                    WHEN 0 THEN 'cancelled' 
                    WHEN 1 THEN 'expired' 
                    ELSE 'completed' 
                END,
                @CSBookZoneID,
                50.00 + (ABS(CHECKSUM(NEWID())) % 150),
                CASE WHEN (ABS(CHECKSUM(NEWID())) % 10) < 7 THEN 1 ELSE 0 END);
        
        SET @CSBookCounter = @CSBookCounter + 1;
        
        IF @CSBookCounter % 3000 = 0
            PRINT 'Generated ' + CAST(@CSBookCounter AS NVARCHAR) + ' carshare bookings...';
    END TRY
    BEGIN CATCH
        -- Vehicle already booked, skip and try another
    END CATCH
END

PRINT 'Completed: ' + CAST(@CSBookCounter AS NVARCHAR) + ' carshare bookings created';
GO

-- =============================================
-- 14. Generate CarShare Rentals (SET-BASED - FAST)
-- =============================================
PRINT 'Generating carshare rentals (up to 15,000)...';

-- SET-BASED insert - much faster than cursor!
INSERT INTO CarshareRental (BookingID, CustomerID, VehicleID, StartedAt, EndedAt, TotalDurationMin,
    OdometerStartKm, OdometerEndKm, FuelStartPercent, FuelEndPercent, StartZoneID, EndZoneID,
    StartLatitude, StartLongitude, EndLatitude, EndLongitude, ParkedInZone, Status,
    PricingMode, PricePerMinute, PricePerKm, TimeCost, DistanceCost, TotalCost)
SELECT TOP 15000
    BookingID, CustomerID, VehicleID,
    ReservationStartAt, -- StartedAt
    DATEADD(MINUTE, 30 + (ABS(CHECKSUM(NEWID())) % 480), ReservationStartAt), -- EndedAt
    30 + (ABS(CHECKSUM(NEWID())) % 480), -- TotalDurationMin
    10000 + (ABS(CHECKSUM(NEWID())) % 50000), -- OdometerStartKm
    10000 + (ABS(CHECKSUM(NEWID())) % 50000) + 5 + (ABS(CHECKSUM(NEWID())) % 100), -- OdometerEndKm
    50 + (ABS(CHECKSUM(NEWID())) % 50), -- FuelStartPercent
    30 + (ABS(CHECKSUM(NEWID())) % 40), -- FuelEndPercent
    PickupZoneID, PickupZoneID, -- StartZoneID, EndZoneID
    34.6 + (RAND(CHECKSUM(NEWID())) * 0.8), 32.3 + (RAND(CHECKSUM(NEWID())) * 1.7), -- Start coords
    34.6 + (RAND(CHECKSUM(NEWID())) * 0.8), 32.3 + (RAND(CHECKSUM(NEWID())) * 1.7), -- End coords
    CASE WHEN (ABS(CHECKSUM(NEWID())) % 10) < 9 THEN 1 ELSE 0 END, -- ParkedInZone
    'completed',
    'per_minute', 0.25, 0.15,
    ROUND((30 + (ABS(CHECKSUM(NEWID())) % 480)) * 0.25, 2), -- TimeCost
    ROUND((5 + (ABS(CHECKSUM(NEWID())) % 100)) * 0.15, 2), -- DistanceCost
    ROUND(((30 + (ABS(CHECKSUM(NEWID())) % 480)) * 0.25) + ((5 + (ABS(CHECKSUM(NEWID())) % 100)) * 0.15), 2) -- TotalCost
FROM CarshareBooking 
WHERE Status = 'completed'
ORDER BY NEWID();

DECLARE @CSRentCount INT = @@ROWCOUNT;
PRINT 'Completed: ' + CAST(@CSRentCount AS NVARCHAR) + ' carshare rentals created';

-- Reset all CarShare vehicles to 'available' status so they can be booked by real users
UPDATE CarshareVehicle 
SET Status = 'available' 
WHERE VehicleID IN (
    SELECT DISTINCT VehicleID 
    FROM CarshareBooking 
    WHERE Status IN ('completed', 'cancelled', 'expired')
);

PRINT 'Reset ' + CAST(@@ROWCOUNT AS NVARCHAR) + ' carshare vehicles to available status';
GO

-- =============================================
-- 15. Generate Autonomous Ride Payments
-- =============================================
PRINT 'Generating payments for autonomous rides...';

INSERT INTO AutonomousRidePayment (AutonomousRideID, PaymentMethodTypeID, Amount, CurrencyCode, 
    Status, CreatedAt, CompletedAt, BaseFare, DistanceFare, TimeFare, 
    SurgeMultiplier, ServiceFeeRate, ServiceFeeAmount, DistanceKm, DurationMinutes)
SELECT 
    ar.AutonomousRideID,
    ar.PaymentMethodTypeID,
    ar.ActualFare,
    'EUR',
    'completed',
    ar.TripCompletedAt,
    DATEADD(MINUTE, 1, ar.TripCompletedAt),
    ROUND(ar.ActualFare * 0.3, 2), -- BaseFare ~30%
    ROUND(ar.ActualFare * 0.4, 2), -- DistanceFare ~40%
    ROUND(ar.ActualFare * 0.2, 2), -- TimeFare ~20%
    1.00, -- SurgeMultiplier
    0.00, -- ServiceFeeRate 0% (no platform fee)
    0.00, -- ServiceFeeAmount (no fee)
    ar.ActualDistanceKm,
    DATEDIFF(MINUTE, ar.TripStartedAt, ar.TripCompletedAt)
FROM AutonomousRide ar
WHERE ar.Status = 'completed'
AND ar.ActualFare IS NOT NULL
AND ar.ActualFare > 0
AND NOT EXISTS (SELECT 1 FROM AutonomousRidePayment p WHERE p.AutonomousRideID = ar.AutonomousRideID);

PRINT 'Completed: ' + CAST(@@ROWCOUNT AS NVARCHAR) + ' autonomous ride payments created';
GO

-- =============================================
-- 16. Generate CarShare Payments for Rentals
-- =============================================
PRINT 'Generating payments for carshare rentals...';

INSERT INTO CarsharePayment (RentalID, CustomerID, Amount, CurrencyCode, 
    PaymentMethodTypeID, PaymentType, Status, CreatedAt, ProcessedAt, CompletedAt)
SELECT 
    r.RentalID,
    r.CustomerID,
    r.TotalCost,
    'EUR',
    (ABS(CHECKSUM(NEWID())) % 2) + 1, -- Random payment method 1-2
    'rental',
    'completed',
    r.EndedAt,
    DATEADD(SECOND, 30, r.EndedAt),
    DATEADD(MINUTE, 1, r.EndedAt)
FROM CarshareRental r
WHERE r.Status = 'completed'
AND r.TotalCost IS NOT NULL
AND r.TotalCost > 0
AND NOT EXISTS (SELECT 1 FROM CarsharePayment p WHERE p.RentalID = r.RentalID AND p.PaymentType = 'rental');

PRINT 'Completed: ' + CAST(@@ROWCOUNT AS NVARCHAR) + ' carshare rental payments created';
GO

-- =============================================
-- 17. Generate Messages (Support tickets)
-- =============================================
PRINT 'Generating 2,000 support messages...';

DECLARE @MessageCounter INT = 1;
DECLARE @FromUserID INT;
DECLARE @ToUserID INT = 1; -- Assume admin user ID is 1
DECLARE @Content NVARCHAR(MAX);
DECLARE @SentAt DATETIME2;

WHILE @MessageCounter <= 2000
BEGIN
    -- Random user
    SELECT TOP 1 @FromUserID = UserID
    FROM [User]
    WHERE UserID > 1 -- Skip admin
    ORDER BY NEWID();
    
    SET @SentAt = DATEADD(HOUR, -ABS(CHECKSUM(NEWID())) % 4320, SYSDATETIME()); -- Past 6 months
    
    SET @Content = CASE (ABS(CHECKSUM(NEWID())) % 15)
        WHEN 0 THEN 'I need help with my recent trip payment.'
        WHEN 1 THEN 'How do I update my profile information?'
        WHEN 2 THEN 'The driver was very late, can I get a refund?'
        WHEN 3 THEN 'I lost an item in the vehicle, please help.'
        WHEN 4 THEN 'Can I cancel my ride request?'
        WHEN 5 THEN 'How long does payment processing take?'
        WHEN 6 THEN 'I want to register as a driver. What do I need?'
        WHEN 7 THEN 'My payment method is not working.'
        WHEN 8 THEN 'Can I change my pickup location?'
        WHEN 9 THEN 'The app is not showing available drivers.'
        WHEN 10 THEN 'How do I add a new payment method?'
        WHEN 11 THEN 'I was charged the wrong amount.'
        WHEN 12 THEN 'Can I schedule a ride in advance?'
        WHEN 13 THEN 'How do I report a safety concern?'
        ELSE 'I have a question about my account.'
    END;
    
    INSERT INTO [Message] (FromUserID, ToUserID, Content, SentAt, IsSystem)
    VALUES (@FromUserID, @ToUserID, @Content, @SentAt, 0);
    
    IF @MessageCounter % 500 = 0
        PRINT 'Generated ' + CAST(@MessageCounter AS NVARCHAR) + ' messages...';
    
    SET @MessageCounter = @MessageCounter + 1;
END

PRINT 'Completed: 2,000 messages created';
GO

-- =============================================
-- 18. Final Statistics
-- =============================================
PRINT '';
PRINT '========================================';
PRINT 'STRESS TEST DATA GENERATION COMPLETE';
PRINT '========================================';
PRINT '';

SELECT 'Total Users' AS [Metric], COUNT(*) AS [Count] FROM [User]
UNION ALL
SELECT 'Passengers', COUNT(*) FROM Passenger
UNION ALL
SELECT 'Drivers', COUNT(*) FROM Driver
UNION ALL
SELECT 'Vehicles', COUNT(*) FROM Vehicle
UNION ALL
SELECT 'Locations', COUNT(*) FROM [Location]
UNION ALL
SELECT 'Ride Requests', COUNT(*) FROM RideRequest
UNION ALL
SELECT 'Trips', COUNT(*) FROM Trip
UNION ALL
SELECT 'Completed Trips', COUNT(*) FROM Trip WHERE Status = 'completed'
UNION ALL
SELECT 'Payments', COUNT(*) FROM Payment
UNION ALL
SELECT 'Ratings', COUNT(*) FROM Rating
UNION ALL
SELECT 'Messages', COUNT(*) FROM [Message]
UNION ALL
SELECT '--- AUTONOMOUS ---', 0
UNION ALL
SELECT 'Autonomous Vehicles', COUNT(*) FROM AutonomousVehicle
UNION ALL
SELECT 'Autonomous Rides', COUNT(*) FROM AutonomousRide
UNION ALL
SELECT 'AV Ride Payments', COUNT(*) FROM AutonomousRidePayment
UNION ALL
SELECT '--- CARSHARE ---', 0
UNION ALL
SELECT 'CarShare Zones', COUNT(*) FROM CarshareZone
UNION ALL
SELECT 'CarShare Vehicles', COUNT(*) FROM CarshareVehicle
UNION ALL
SELECT 'CarShare Customers', COUNT(*) FROM CarshareCustomer
UNION ALL
SELECT 'CarShare Bookings', COUNT(*) FROM CarshareBooking
UNION ALL
SELECT 'CarShare Rentals', COUNT(*) FROM CarshareRental
UNION ALL
SELECT 'CarShare Payments', COUNT(*) FROM CarsharePayment;

PRINT '';
PRINT 'Stress test data ready for testing!';
PRINT 'All three service modes populated:';
PRINT '  - Driver Rides: Users, Drivers, Vehicles, RideRequests, Trips, Payments, Ratings';
PRINT '  - Autonomous: AutonomousVehicle, AutonomousRide, AutonomousRidePayment';
PRINT '  - CarShare: CarshareZone, CarshareVehicle, CarshareCustomer, CarshareBooking, CarshareRental, CarsharePayment';
GO
