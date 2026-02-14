
/* ============================================================
   SIMULATED DRIVERS SEEDING - GEOFENCE AWARE VERSION
   ============================================================
   This script creates simulated drivers distributed across the
   4 Republic of Cyprus districts (geofences) and properly binds
   their vehicles to the respective geofences via GeofenceLog.
   
   Districts:
   - Paphos_District
   - Limassol_District  
   - Nicosia_District
   - Larnaca_District
   
   Each driver is placed within their geofence boundaries and
   their vehicle is registered in GeofenceLog for segment trips.
   
   Execution Time: ~2-5 minutes depending on hardware
   ============================================================ */

SET NOCOUNT ON;

PRINT '=================================================';
PRINT 'SIMULATED DRIVERS SEEDING - GEOFENCE AWARE';
PRINT '=================================================';
PRINT 'Start Time: ' + CONVERT(VARCHAR, GETDATE(), 120);
PRINT '';

-- ============================================================
-- DISABLE TRIGGERS TO REDUCE LOG USAGE
-- ============================================================

PRINT 'Disabling audit triggers to reduce log usage...';

IF EXISTS (SELECT 1 FROM sys.triggers WHERE name = 'trg_Users_AuditInsert')
    DISABLE TRIGGER trg_Users_AuditInsert ON dbo.[User];
IF EXISTS (SELECT 1 FROM sys.triggers WHERE name = 'trg_User_AuditInsert')
    DISABLE TRIGGER trg_User_AuditInsert ON dbo.[User];

PRINT 'Triggers disabled.';
PRINT '';

-- ============================================================
-- CONFIGURATION
-- ============================================================

DECLARE @DriversPerGeofence INT = 125;  -- 125 per geofence = 500 total
DECLARE @BatchSize INT = 25;

-- ============================================================
-- VERIFY GEOFENCES EXIST
-- ============================================================

DECLARE @GeofenceCount INT = (
    SELECT COUNT(*) FROM dbo.Geofence 
    WHERE Name IN ('Paphos_District', 'Limassol_District', 'Nicosia_District', 'Larnaca_District')
      AND IsActive = 1
);

IF @GeofenceCount < 4
BEGIN
    RAISERROR('Missing geofences! Please run geofence_bridge_seeding.sql first.', 16, 1);
    RETURN;
END

PRINT 'Found ' + CAST(@GeofenceCount AS VARCHAR) + ' active geofences.';

-- ============================================================
-- GET GEOFENCE IDS
-- ============================================================

DECLARE @PaphosGeoID INT = (SELECT GeofenceID FROM dbo.Geofence WHERE Name = 'Paphos_District');
DECLARE @LimassolGeoID INT = (SELECT GeofenceID FROM dbo.Geofence WHERE Name = 'Limassol_District');
DECLARE @NicosiaGeoID INT = (SELECT GeofenceID FROM dbo.Geofence WHERE Name = 'Nicosia_District');
DECLARE @LarnacaGeoID INT = (SELECT GeofenceID FROM dbo.Geofence WHERE Name = 'Larnaca_District');

PRINT 'Geofence IDs: Paphos=' + CAST(@PaphosGeoID AS VARCHAR) + 
      ', Limassol=' + CAST(@LimassolGeoID AS VARCHAR) +
      ', Nicosia=' + CAST(@NicosiaGeoID AS VARCHAR) +
      ', Larnaca=' + CAST(@LarnacaGeoID AS VARCHAR);
PRINT '';

-- ============================================================
-- GET VEHICLE TYPES
-- ============================================================

DECLARE @VehicleTypes TABLE (RowNum INT IDENTITY, VehicleTypeID INT);
INSERT INTO @VehicleTypes SELECT VehicleTypeID FROM dbo.VehicleType;

DECLARE @VehicleTypeCount INT = (SELECT COUNT(*) FROM @VehicleTypes);
IF @VehicleTypeCount = 0
BEGIN
    RAISERROR('No vehicle types found. Please run vehicle_typeSeeding.sql first.', 16, 1);
    RETURN;
END

PRINT 'Found ' + CAST(@VehicleTypeCount AS VARCHAR) + ' vehicle types.';
PRINT '';

-- ============================================================
-- TEMPORARY TABLES
-- ============================================================

IF OBJECT_ID('tempdb..#SimulatedUsers') IS NOT NULL DROP TABLE #SimulatedUsers;
IF OBJECT_ID('tempdb..#SimulatedDrivers') IS NOT NULL DROP TABLE #SimulatedDrivers;

CREATE TABLE #SimulatedUsers (
    TempID INT IDENTITY(1,1) PRIMARY KEY,
    Email NVARCHAR(255),
    Phone NVARCHAR(30),
    FullName NVARCHAR(200),
    Latitude DECIMAL(9,6),
    Longitude DECIMAL(9,6),
    GeofenceName NVARCHAR(50),
    GeofenceID INT
);

CREATE TABLE #SimulatedDrivers (
    TempID INT,
    UserID INT,
    DriverID INT,
    VehicleID INT,
    GeofenceID INT
);

-- ============================================================
-- NAME POOLS
-- ============================================================

DECLARE @FirstNames TABLE (ID INT IDENTITY, Name NVARCHAR(50));
DECLARE @LastNames TABLE (ID INT IDENTITY, Name NVARCHAR(50));

INSERT INTO @FirstNames (Name) VALUES 
    ('Andreas'),('Georgios'),('Nikos'),('Michalis'),('Kostas'),
    ('Christos'),('Panagiotis'),('Dimitris'),('Ioannis'),('Stavros'),
    ('Maria'),('Elena'),('Anna'),('Katerina'),('Sofia'),
    ('Christina'),('Georgia'),('Eleni'),('Antonia'),('Despina'),
    ('Petros'),('Alexis'),('Markos'),('Pavlos'),('Leonidas'),
    ('Yiannis'),('Stelios'),('Marios'),('Giorgos'),('Kyriakos');

INSERT INTO @LastNames (Name) VALUES 
    ('Papadopoulos'),('Georgiou'),('Nicolaou'),('Christodoulou'),('Constantinou'),
    ('Ioannou'),('Michaelidis'),('Kyriacou'),('Andreou'),('Charalambous'),
    ('Stylianou'),('Antoniou'),('Demetriou'),('Loizou'),('Savvides'),
    ('Panayiotou'),('Vassiliou'),('Efthymiou'),('Philippou'),('Hadjipetrou'),
    ('Komodromos'),('Evangelou'),('Stavrou'),('Economou'),('Papacostas');

DECLARE @FnameCount INT = (SELECT COUNT(*) FROM @FirstNames);
DECLARE @LnameCount INT = (SELECT COUNT(*) FROM @LastNames);

-- ============================================================
-- VEHICLE MAKES/MODELS
-- ============================================================

DECLARE @VehicleMakes TABLE (ID INT IDENTITY, Make NVARCHAR(50), Model NVARCHAR(50));
INSERT INTO @VehicleMakes (Make, Model) VALUES
    ('Toyota', 'Corolla'), ('Toyota', 'Yaris'), ('Toyota', 'Camry'), ('Toyota', 'RAV4'),
    ('Mercedes', 'E-Class'), ('Mercedes', 'C-Class'), ('Mercedes', 'Vito'),
    ('BMW', '3 Series'), ('BMW', '5 Series'), ('BMW', 'X5'),
    ('Volkswagen', 'Golf'), ('Volkswagen', 'Passat'), ('Volkswagen', 'Transporter'),
    ('Hyundai', 'Tucson'), ('Hyundai', 'i30'), ('Hyundai', 'Santa Fe'),
    ('Kia', 'Sportage'), ('Kia', 'Ceed'), ('Kia', 'Sorento'),
    ('Nissan', 'Qashqai'), ('Nissan', 'Juke'), ('Nissan', 'X-Trail'),
    ('Ford', 'Focus'), ('Ford', 'Transit'), ('Ford', 'Fiesta'),
    ('Audi', 'A4'), ('Audi', 'A6'), ('Audi', 'Q5'),
    ('Mazda', 'CX-5'), ('Mazda', '3'), ('Mazda', '6'),
    ('Honda', 'Civic'), ('Honda', 'CR-V'), ('Honda', 'Jazz');

DECLARE @MakeCount INT = (SELECT COUNT(*) FROM @VehicleMakes);

DECLARE @Colors TABLE (ID INT IDENTITY, Color NVARCHAR(30));
INSERT INTO @Colors VALUES ('White'),('Black'),('Silver'),('Gray'),('Blue'),('Red'),('Green'),('Beige');
DECLARE @ColorCount INT = (SELECT COUNT(*) FROM @Colors);

-- ============================================================
-- GENERATE USERS FOR EACH GEOFENCE
-- ============================================================

PRINT 'Phase 1: Generating user records for each geofence...';

DECLARE @TotalDrivers INT = @DriversPerGeofence * 4;

;WITH Numbers AS (
    SELECT TOP (@TotalDrivers) 
        ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n
    FROM sys.all_objects a
    CROSS JOIN sys.all_objects b
),
GeofenceAssignment AS (
    SELECT 
        n,
        CASE 
            WHEN n <= @DriversPerGeofence THEN 'Paphos_District'
            WHEN n <= @DriversPerGeofence * 2 THEN 'Limassol_District'
            WHEN n <= @DriversPerGeofence * 3 THEN 'Nicosia_District'
            ELSE 'Larnaca_District'
        END AS GeofenceName,
        CASE 
            WHEN n <= @DriversPerGeofence THEN @PaphosGeoID
            WHEN n <= @DriversPerGeofence * 2 THEN @LimassolGeoID
            WHEN n <= @DriversPerGeofence * 3 THEN @NicosiaGeoID
            ELSE @LarnacaGeoID
        END AS GeofenceID,
        -- Use larger spread across each district
        -- Row within geofence (0-124 for 125 drivers)
        (n - 1) % @DriversPerGeofence AS RowInGeo,
        -- Random-like variation using different prime multipliers
        ((n * 7) % 100) / 100.0 AS Rand1,
        ((n * 13) % 100) / 100.0 AS Rand2,
        ((n * 17) % 100) / 100.0 AS Rand3,
        ((n * 3) % @FnameCount) + 1 AS FnameIdx,
        ((n * 7) % @LnameCount) + 1 AS LnameIdx,
        1000000 + (n % 9000000) AS PhoneSuffix
    FROM Numbers
),
CandidateUsers AS (
    SELECT 
        ga.n,
        ga.GeofenceName,
        ga.GeofenceID,
        ga.PhoneSuffix,
        fn.Name AS FirstName,
        ln.Name AS LastName,
        -- Place drivers in the MAIN TOWNS/CITIES of each district with WIDE spread to cover bridges
        -- Paphos city: 34.7550, 32.4150 (coastal town)
        -- Limassol city: 34.6850, 33.0350 (coastal town)
        -- Nicosia city: 35.1750, 33.3650 (capital, inland)
        -- Larnaca city: 34.9200, 33.6300 (coastal town)
        CASE ga.GeofenceName
            WHEN 'Paphos_District' THEN 34.7550 + ((ga.Rand1 - 0.5) * 0.22)
            WHEN 'Limassol_District' THEN 34.6850 + ((ga.Rand1 - 0.5) * 0.20)
            WHEN 'Nicosia_District' THEN 35.1750 + ((ga.Rand1 - 0.5) * 0.16)
            ELSE 34.9200 + ((ga.Rand1 - 0.5) * 0.14)
        END AS Latitude,
        CASE ga.GeofenceName
            WHEN 'Paphos_District' THEN 32.4150 + ((ga.Rand2 - 0.5) * 0.22)
            WHEN 'Limassol_District' THEN 33.0350 + ((ga.Rand2 - 0.5) * 0.28)
            WHEN 'Nicosia_District' THEN 33.3650 + ((ga.Rand2 - 0.5) * 0.20)
            ELSE 33.6300 + ((ga.Rand2 - 0.5) * 0.18)
        END AS Longitude
    FROM GeofenceAssignment ga
    JOIN @FirstNames fn ON fn.ID = ga.FnameIdx
    JOIN @LastNames ln ON ln.ID = ga.LnameIdx
)
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simdriver' + RIGHT('00000' + CAST(cu.n AS VARCHAR), 5) + '@osrh-test.cy' AS Email,
    '+357-9' + CAST(cu.PhoneSuffix AS VARCHAR) AS Phone,
    cu.FirstName + ' ' + cu.LastName AS FullName,
    cu.Latitude,
    cu.Longitude,
    cu.GeofenceName,
    cu.GeofenceID
FROM CandidateUsers cu
WHERE dbo.fnIsPointInGeofence(cu.Latitude, cu.Longitude, cu.GeofenceID) = 1;

PRINT 'Generated ' + CAST(@@ROWCOUNT AS VARCHAR) + ' user records (validated inside geofence)';

-- ============================================================
-- ADD EXTRA DRIVERS NEAR BRIDGE POINTS
-- ============================================================

PRINT 'Adding extra drivers near bridge transfer points...';

-- Bridge locations:
-- Bridge 1: Nicosia-Larnaca (35.0034, 33.4499)
-- Bridge 2: Nicosia-Limassol (35.0100, 33.1500)
-- Bridge 3: Limassol-Larnaca (34.8000, 33.3350)
-- Bridge 4: Limassol-Paphos (34.8500, 32.7500)

DECLARE @BridgeDriverCount INT = 0;

-- Bridge 1: Nicosia-Larnaca - Add drivers on BOTH sides
-- Nicosia side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge1n' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2000000 + n AS VARCHAR),
    'Bridge Driver N-L ' + CAST(n AS VARCHAR),
    35.0034 + ((n * 0.003) - 0.015),
    33.4499 - 0.02 + ((n % 3) * 0.01),
    'Nicosia_District',
    @NicosiaGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(35.0034 + ((n * 0.003) - 0.015), 33.4499 - 0.02 + ((n % 3) * 0.01), @NicosiaGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Larnaca side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge1l' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2100000 + n AS VARCHAR),
    'Bridge Driver L-N ' + CAST(n AS VARCHAR),
    35.0034 + ((n * 0.003) - 0.015),
    33.4499 + 0.02 + ((n % 3) * 0.01),
    'Larnaca_District',
    @LarnacaGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(35.0034 + ((n * 0.003) - 0.015), 33.4499 + 0.02 + ((n % 3) * 0.01), @LarnacaGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Bridge 2: Nicosia-Limassol - Add drivers on BOTH sides
-- Nicosia side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge2n' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2200000 + n AS VARCHAR),
    'Bridge Driver N-Li ' + CAST(n AS VARCHAR),
    35.0100 + ((n * 0.003) - 0.015),
    33.1500 + ((n % 3) * 0.01),
    'Nicosia_District',
    @NicosiaGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(35.0100 + ((n * 0.003) - 0.015), 33.1500 + ((n % 3) * 0.01), @NicosiaGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Limassol side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge2li' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2300000 + n AS VARCHAR),
    'Bridge Driver Li-N ' + CAST(n AS VARCHAR),
    35.0100 - 0.02 + ((n * 0.003) - 0.015),
    33.1500 + ((n % 3) * 0.01),
    'Limassol_District',
    @LimassolGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(35.0100 - 0.02 + ((n * 0.003) - 0.015), 33.1500 + ((n % 3) * 0.01), @LimassolGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Bridge 3: Limassol-Larnaca - Add drivers on BOTH sides
-- Limassol side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge3li' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2400000 + n AS VARCHAR),
    'Bridge Driver Li-La ' + CAST(n AS VARCHAR),
    34.8000 + ((n * 0.003) - 0.015),
    33.3350 - 0.02 + ((n % 3) * 0.01),
    'Limassol_District',
    @LimassolGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(34.8000 + ((n * 0.003) - 0.015), 33.3350 - 0.02 + ((n % 3) * 0.01), @LimassolGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Larnaca side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge3la' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2500000 + n AS VARCHAR),
    'Bridge Driver La-Li ' + CAST(n AS VARCHAR),
    34.8000 + ((n * 0.003) - 0.015),
    33.3350 + 0.02 + ((n % 3) * 0.01),
    'Larnaca_District',
    @LarnacaGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(34.8000 + ((n * 0.003) - 0.015), 33.3350 + 0.02 + ((n % 3) * 0.01), @LarnacaGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Bridge 4: Limassol-Paphos - Add drivers on BOTH sides
-- Limassol side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge4li' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2600000 + n AS VARCHAR),
    'Bridge Driver Li-P ' + CAST(n AS VARCHAR),
    34.8500 + ((n * 0.003) - 0.015),
    32.7500 + 0.02 + ((n % 3) * 0.01),
    'Limassol_District',
    @LimassolGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(34.8500 + ((n * 0.003) - 0.015), 32.7500 + 0.02 + ((n % 3) * 0.01), @LimassolGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

-- Paphos side
INSERT INTO #SimulatedUsers (Email, Phone, FullName, Latitude, Longitude, GeofenceName, GeofenceID)
SELECT 
    'simbridge4p' + RIGHT('00' + CAST(n AS VARCHAR), 2) + '@osrh-test.cy',
    '+357-9' + CAST(2700000 + n AS VARCHAR),
    'Bridge Driver P-Li ' + CAST(n AS VARCHAR),
    34.8500 + ((n * 0.003) - 0.015),
    32.7500 - 0.02 + ((n % 3) * 0.01),
    'Paphos_District',
    @PaphosGeoID
FROM (SELECT TOP 5 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.objects) nums
WHERE dbo.fnIsPointInGeofence(34.8500 + ((n * 0.003) - 0.015), 32.7500 - 0.02 + ((n % 3) * 0.01), @PaphosGeoID) = 1;

SET @BridgeDriverCount = @BridgeDriverCount + @@ROWCOUNT;

PRINT 'Added ' + CAST(@BridgeDriverCount AS VARCHAR) + ' drivers near bridge transfer points';

-- Show distribution
SELECT GeofenceName, COUNT(*) AS DriverCount 
FROM #SimulatedUsers 
GROUP BY GeofenceName 
ORDER BY GeofenceName;

-- ============================================================
-- INSERT USERS IN BATCHES
-- ============================================================

PRINT '';
PRINT 'Phase 2: Inserting users into database...';

DECLARE @BatchStart INT = 1;
DECLARE @BatchEnd INT;
DECLARE @TotalInserted INT = 0;
DECLARE @MaxTempID INT = (SELECT MAX(TempID) FROM #SimulatedUsers);

WHILE @BatchStart <= @MaxTempID
BEGIN
    SET @BatchEnd = @BatchStart + @BatchSize - 1;

    INSERT INTO dbo.[User] (Email, Phone, FullName, Status)
    SELECT su.Email, su.Phone, su.FullName, 'active'
    FROM #SimulatedUsers su
    WHERE su.TempID BETWEEN @BatchStart AND @BatchEnd
      AND NOT EXISTS (SELECT 1 FROM dbo.[User] u WHERE u.Email = su.Email);

    SET @TotalInserted = @TotalInserted + @@ROWCOUNT;
    SET @BatchStart = @BatchEnd + 1;
END

PRINT 'Inserted ' + CAST(@TotalInserted AS VARCHAR) + ' users';

-- Link user IDs
INSERT INTO #SimulatedDrivers (TempID, UserID, GeofenceID)
SELECT su.TempID, u.UserID, su.GeofenceID
FROM #SimulatedUsers su
JOIN dbo.[User] u ON u.Email = su.Email;

PRINT 'Linked ' + CAST(@@ROWCOUNT AS VARCHAR) + ' user IDs';

-- Insert passwords
DECLARE @DefaultPasswordHash VARBINARY(256) = HASHBYTES('SHA2_256', 'SimulatedDriver123!');
DECLARE @DefaultPasswordSalt VARBINARY(128) = 0x00;

SET @BatchStart = 1;
SET @TotalInserted = 0;

WHILE @BatchStart <= @MaxTempID
BEGIN
    SET @BatchEnd = @BatchStart + @BatchSize - 1;

    INSERT INTO dbo.[PasswordHistory] (UserID, PasswordHash, PasswordSalt, IsCurrent)
    SELECT sd.UserID, @DefaultPasswordHash, @DefaultPasswordSalt, 1
    FROM #SimulatedDrivers sd
    WHERE sd.TempID BETWEEN @BatchStart AND @BatchEnd
      AND sd.UserID IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM dbo.PasswordHistory ph WHERE ph.UserID = sd.UserID);

    SET @TotalInserted = @TotalInserted + @@ROWCOUNT;
    SET @BatchStart = @BatchEnd + 1;
END

PRINT 'Created ' + CAST(@TotalInserted AS VARCHAR) + ' password records';

-- ============================================================
-- CREATE DRIVERS
-- ============================================================

PRINT '';
PRINT 'Phase 3: Creating driver records...';

SET @BatchStart = 1;
SET @TotalInserted = 0;

WHILE @BatchStart <= @MaxTempID
BEGIN
    SET @BatchEnd = @BatchStart + @BatchSize - 1;

    INSERT INTO dbo.[Driver] (UserID, DriverType, IsAvailable, VerificationStatus, UseGPS, 
                               CurrentLatitude, CurrentLongitude, LocationUpdatedAt)
    SELECT 
        sd.UserID, 
        'partner',
        1,  -- Make drivers available by default
        'approved',
        0,  -- Simulated (UseGPS = 0)
        su.Latitude,
        su.Longitude,
        SYSDATETIME()
    FROM #SimulatedDrivers sd
    JOIN #SimulatedUsers su ON sd.TempID = su.TempID
    WHERE sd.TempID BETWEEN @BatchStart AND @BatchEnd
      AND sd.UserID IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM dbo.Driver d WHERE d.UserID = sd.UserID);

    SET @TotalInserted = @TotalInserted + @@ROWCOUNT;
    SET @BatchStart = @BatchEnd + 1;
END

PRINT 'Created ' + CAST(@TotalInserted AS VARCHAR) + ' driver records';

-- Update temp table with driver IDs
UPDATE sd
SET sd.DriverID = d.DriverID
FROM #SimulatedDrivers sd
JOIN dbo.Driver d ON d.UserID = sd.UserID
WHERE sd.DriverID IS NULL;

PRINT 'Linked ' + CAST(@@ROWCOUNT AS VARCHAR) + ' driver IDs';

-- ============================================================
-- CREATE VEHICLES
-- ============================================================

PRINT '';
PRINT 'Phase 4: Creating vehicles...';

SET @BatchStart = 1;
SET @TotalInserted = 0;

WHILE @BatchStart <= @MaxTempID
BEGIN
    SET @BatchEnd = @BatchStart + @BatchSize - 1;

    INSERT INTO dbo.[Vehicle] (DriverID, VehicleTypeID, PlateNo, Make, Model, Year, Color, SeatingCapacity, IsActive)
    SELECT 
        sd.DriverID,
        vt.VehicleTypeID,
        CHAR(65 + (sd.DriverID % 26)) + 
        CHAR(65 + ((sd.DriverID / 26) % 26)) + 
        CHAR(65 + ((sd.DriverID / 676) % 26)) + '-' +
        RIGHT('000' + CAST((sd.DriverID % 1000) AS VARCHAR), 3) AS PlateNo,
        vm.Make,
        vm.Model,
        2018 + (sd.DriverID % 7),
        c.Color,
        CASE WHEN sd.DriverID % 10 = 0 THEN 6 ELSE 4 END AS SeatingCapacity,
        1 AS IsActive
    FROM #SimulatedDrivers sd
    CROSS APPLY (SELECT TOP 1 VehicleTypeID FROM @VehicleTypes WHERE RowNum = ((sd.DriverID % @VehicleTypeCount) + 1)) vt
    CROSS APPLY (SELECT Make, Model FROM @VehicleMakes WHERE ID = ((sd.DriverID % @MakeCount) + 1)) vm
    CROSS APPLY (SELECT Color FROM @Colors WHERE ID = ((sd.DriverID % @ColorCount) + 1)) c
    WHERE sd.TempID BETWEEN @BatchStart AND @BatchEnd
      AND sd.DriverID IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM dbo.Vehicle v WHERE v.DriverID = sd.DriverID);

    SET @TotalInserted = @TotalInserted + @@ROWCOUNT;
    SET @BatchStart = @BatchEnd + 1;
END

PRINT 'Created ' + CAST(@TotalInserted AS VARCHAR) + ' vehicles';

-- Update temp table with vehicle IDs
UPDATE sd
SET sd.VehicleID = v.VehicleID
FROM #SimulatedDrivers sd
JOIN dbo.Vehicle v ON v.DriverID = sd.DriverID
WHERE sd.VehicleID IS NULL;

PRINT 'Linked ' + CAST(@@ROWCOUNT AS VARCHAR) + ' vehicle IDs';

-- ============================================================
-- CREATE GEOFENCE LOG ENTRIES (CRITICAL FOR SEGMENT TRIPS!)
-- ============================================================

PRINT '';
PRINT 'Phase 5: Creating GeofenceLog entries (binding vehicles to geofences)...';

-- Clear any existing entries for these vehicles first
DELETE gl
FROM dbo.GeofenceLog gl
INNER JOIN #SimulatedDrivers sd ON gl.VehicleID = sd.VehicleID
WHERE sd.VehicleID IS NOT NULL;

PRINT 'Cleared existing geofence log entries for simulated vehicles';

-- Insert new geofence log entries - each vehicle is bound to its driver's geofence
SET @BatchStart = 1;
SET @TotalInserted = 0;

WHILE @BatchStart <= @MaxTempID
BEGIN
    SET @BatchEnd = @BatchStart + @BatchSize - 1;

    INSERT INTO dbo.GeofenceLog (VehicleID, GeofenceID, EnteredAt, ExitedAt, EventType)
    SELECT 
        sd.VehicleID,
        sd.GeofenceID,
        DATEADD(DAY, -30, SYSDATETIME()),  -- Entered 30 days ago
        NULL,  -- Still inside (ExitedAt = NULL means currently in geofence)
        'entry'
    FROM #SimulatedDrivers sd
    WHERE sd.TempID BETWEEN @BatchStart AND @BatchEnd
      AND sd.VehicleID IS NOT NULL
      AND sd.GeofenceID IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM dbo.GeofenceLog gl 
          WHERE gl.VehicleID = sd.VehicleID 
            AND gl.GeofenceID = sd.GeofenceID 
            AND gl.ExitedAt IS NULL
      );

    SET @TotalInserted = @TotalInserted + @@ROWCOUNT;
    SET @BatchStart = @BatchEnd + 1;
END

PRINT 'Created ' + CAST(@TotalInserted AS VARCHAR) + ' GeofenceLog entries';

-- ============================================================
-- CLEANUP
-- ============================================================

DROP TABLE IF EXISTS #SimulatedUsers;
DROP TABLE IF EXISTS #SimulatedDrivers;

-- ============================================================
-- RE-ENABLE TRIGGERS
-- ============================================================

PRINT '';
PRINT 'Re-enabling audit triggers...';

IF EXISTS (SELECT 1 FROM sys.triggers WHERE name = 'trg_Users_AuditInsert')
    ENABLE TRIGGER trg_Users_AuditInsert ON dbo.[User];
IF EXISTS (SELECT 1 FROM sys.triggers WHERE name = 'trg_User_AuditInsert')
    ENABLE TRIGGER trg_User_AuditInsert ON dbo.[User];

PRINT 'Triggers re-enabled.';

-- ============================================================
-- SUMMARY STATISTICS
-- ============================================================

PRINT '';
PRINT '=================================================';
PRINT 'SEEDING COMPLETE';
PRINT '=================================================';

-- Overall stats
SELECT 
    'Simulated Drivers' AS Category,
    COUNT(*) AS Total,
    SUM(CASE WHEN IsAvailable = 1 THEN 1 ELSE 0 END) AS Available,
    SUM(CASE WHEN IsAvailable = 0 THEN 1 ELSE 0 END) AS Unavailable
FROM dbo.Driver
WHERE UseGPS = 0;

-- Distribution by geofence (using GeofenceLog)
SELECT 
    g.Name AS GeofenceName,
    COUNT(DISTINCT gl.VehicleID) AS VehiclesInGeofence,
    COUNT(DISTINCT d.DriverID) AS DriversInGeofence
FROM dbo.Geofence g
LEFT JOIN dbo.GeofenceLog gl ON g.GeofenceID = gl.GeofenceID AND gl.ExitedAt IS NULL
LEFT JOIN dbo.Vehicle v ON gl.VehicleID = v.VehicleID
LEFT JOIN dbo.Driver d ON v.DriverID = d.DriverID AND d.UseGPS = 0
WHERE g.Name IN ('Paphos_District', 'Limassol_District', 'Nicosia_District', 'Larnaca_District')
GROUP BY g.Name
ORDER BY g.Name;

PRINT '';
PRINT 'End Time: ' + CONVERT(VARCHAR, GETDATE(), 120);
PRINT '';
PRINT 'All simulated drivers are now:';
PRINT '  - Placed within their assigned geofence boundaries';
PRINT '  - Have vehicles bound to geofences via GeofenceLog';
PRINT '  - Set to IsAvailable = 1 (ready for trips)';
PRINT '  - Ready for multi-segment/bridge trips!';
PRINT '';

GO

