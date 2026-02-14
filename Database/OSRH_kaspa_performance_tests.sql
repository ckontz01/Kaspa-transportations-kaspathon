
-- =============================================
-- OSRH Performance Testing Script
-- Tests various queries and operations under load
-- =============================================

SET NOCOUNT ON;
SET ANSI_WARNINGS OFF;  -- Suppress "Null value is eliminated by an aggregate" warnings
PRINT '========================================';
PRINT 'OSRH PERFORMANCE TESTING';
PRINT '========================================';
PRINT '';

-- =============================================
-- 1. Test Driver Trip Listing (Most Common Query)
-- =============================================
PRINT 'TEST 1: Driver Trip Listing Performance';
PRINT 'Testing spDriverListTrips with various drivers...';

DECLARE @TestStart DATETIME;
DECLARE @TestEnd DATETIME;
DECLARE @Duration INT;
DECLARE @DriverID_Test INT;
DECLARE @TestCounter INT = 0;

SET @TestStart = GETDATE();

WHILE @TestCounter < 100
BEGIN
    -- Random driver
    SELECT TOP 1 @DriverID_Test = DriverID FROM Driver ORDER BY NEWID();
    
    -- Execute the stored procedure
    EXEC spDriverListTrips @DriverID_Test;
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 driver trip listings';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 2. Test Available Drivers Query (SKIPPED - procedure doesn't exist)
-- =============================================
PRINT 'TEST 2: Available Drivers Query - SKIPPED (spGetAvailableDrivers not found)';
PRINT '';

-- SET @TestStart = GETDATE();
-- SET @TestCounter = 0;
-- 
-- WHILE @TestCounter < 100
-- BEGIN
--     EXEC spGetAvailableDrivers 35.1856, 33.3823, 1; -- Nicosia center
--     SET @TestCounter = @TestCounter + 1;
-- END
-- 
-- SET @TestEnd = GETDATE();
-- SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);
-- 
-- PRINT 'Completed 100 available driver queries';
-- PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
-- PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
-- PRINT '';

-- =============================================
-- 3. Test Segment Availability Query
-- =============================================
PRINT 'TEST 3: Pending Segments Query Performance';
PRINT 'Testing spGetPendingSegmentsForDriver...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 100
BEGIN
    SELECT TOP 1 @DriverID_Test = DriverID FROM Driver ORDER BY NEWID();
    EXEC spGetPendingSegmentsForDriver @DriverID_Test;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 pending segment queries';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 4. Test Trip Details Query
-- =============================================
PRINT 'TEST 4: Trip Details Query Performance';
PRINT 'Testing spDriverGetTripWithLocations...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

DECLARE @TripID_Test INT;
DECLARE @DriverID_Trip INT;

WHILE @TestCounter < 100
BEGIN
    SELECT TOP 1 @TripID_Test = t.TripID, @DriverID_Trip = t.DriverID 
    FROM Trip t
    ORDER BY NEWID();
    
    EXEC spDriverGetTripWithLocations @TripID_Test, @DriverID_Trip;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 trip detail queries';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 5. Test Reports Query (Trip Analysis)
-- =============================================
PRINT 'TEST 5: Trip Analysis Report Performance';
PRINT 'Testing spReportTripAnalysis...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC spReportTripAnalysis 
        @FromDate = '2024-01-01',
        @ToDate = '2025-12-31',
        @VehicleTypeIDs = NULL,
        @PostalCode = NULL,
        @CenterLat = NULL,
        @CenterLng = NULL,
        @RadiusKm = NULL,
        @GroupBy = 'month';
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 report queries';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 6. Test Complex Join Query Performance
-- =============================================
PRINT 'TEST 6: Complex Join Query Performance';
PRINT 'Testing vCompletedTrips view...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    SELECT TOP 100 *
    FROM vCompletedTrips
    WHERE StartTime >= DATEADD(DAY, -30, GETDATE())
    ORDER BY StartTime DESC;
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 complex view queries';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 7. Test Concurrent Ride Request Creation
-- =============================================
PRINT 'TEST 7: Ride Request Creation Performance';
PRINT 'Simulating 100 concurrent ride requests...';

DECLARE @PassengerID_Perf INT;
DECLARE @PickupLocationID_Perf INT;
DECLARE @DropoffLocationID_Perf INT;

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 100
BEGIN
    SELECT TOP 1 @PassengerID_Perf = PassengerID FROM Passenger ORDER BY NEWID();
    SELECT TOP 1 @PickupLocationID_Perf = LocationID FROM [Location] ORDER BY NEWID();
    SELECT TOP 1 @DropoffLocationID_Perf = LocationID FROM [Location] 
    WHERE LocationID != @PickupLocationID_Perf ORDER BY NEWID();
    
    INSERT INTO RideRequest (PassengerID, PickupLocationID, DropoffLocationID, ServiceTypeID,
                            [Status], RequestedAt, PaymentMethodTypeID)
    VALUES (@PassengerID_Perf, @PickupLocationID_Perf, @DropoffLocationID_Perf, 1,
            'pending', SYSDATETIME(), 1);
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 ride request insertions';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per insert: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 8. Test Index Effectiveness
-- =============================================
PRINT 'TEST 8: Index Effectiveness Analysis';
PRINT 'Checking index usage statistics...';
PRINT '';

SELECT 
    OBJECT_NAME(s.object_id) AS TableName,
    i.name AS IndexName,
    s.user_seeks AS UserSeeks,
    s.user_scans AS UserScans,
    s.user_lookups AS UserLookups,
    s.user_updates AS UserUpdates,
    s.last_user_seek AS LastSeek,
    s.last_user_scan AS LastScan
FROM sys.dm_db_index_usage_stats s
INNER JOIN sys.indexes i ON s.object_id = i.object_id AND s.index_id = i.index_id
WHERE database_id = DB_ID('OSRH')
  AND OBJECT_NAME(s.object_id) IN ('Trip', 'RideRequest', 'Driver', 'Vehicle', 'Payment', 'Location')
ORDER BY TableName, IndexName;

PRINT '';

-- =============================================
-- 9. Test Query Plan Cache
-- =============================================
PRINT 'TEST 9: Query Plan Analysis';
PRINT 'Analyzing cached query plans...';
PRINT '';

SELECT TOP 20
    SUBSTRING(qt.text, (qs.statement_start_offset/2)+1,
        ((CASE qs.statement_end_offset
            WHEN -1 THEN DATALENGTH(qt.text)
            ELSE qs.statement_end_offset
        END - qs.statement_start_offset)/2)+1) AS QueryText,
    qs.execution_count AS ExecutionCount,
    qs.total_logical_reads AS TotalLogicalReads,
    qs.total_logical_writes AS TotalLogicalWrites,
    qs.total_worker_time AS TotalWorkerTime,
    qs.total_elapsed_time AS TotalElapsedTime,
    qs.total_elapsed_time / qs.execution_count AS AvgElapsedTime
FROM sys.dm_exec_query_stats qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) qt
WHERE qt.dbid = DB_ID('OSRH')
ORDER BY qs.total_elapsed_time DESC;

PRINT '';

-- =============================================
-- 10. Table Size Analysis
-- =============================================
PRINT 'TEST 10: Table Size and Row Count Analysis';
PRINT '';

SELECT 
    t.NAME AS TableName,
    p.rows AS RowCounts,
    CAST(ROUND((SUM(a.total_pages) * 8) / 1024.00, 2) AS NUMERIC(36, 2)) AS TotalSpaceMB,
    CAST(ROUND((SUM(a.used_pages) * 8) / 1024.00, 2) AS NUMERIC(36, 2)) AS UsedSpaceMB,
    CAST(ROUND((SUM(a.total_pages) - SUM(a.used_pages)) * 8 / 1024.00, 2) AS NUMERIC(36, 2)) AS UnusedSpaceMB
FROM sys.tables t
INNER JOIN sys.indexes i ON t.OBJECT_ID = i.object_id
INNER JOIN sys.partitions p ON i.object_id = p.OBJECT_ID AND i.index_id = p.index_id
INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
WHERE t.NAME NOT LIKE 'dt%' AND t.is_ms_shipped = 0 AND i.OBJECT_ID > 255
GROUP BY t.Name, p.Rows
ORDER BY TotalSpaceMB DESC;

PRINT '';

-- =============================================
-- 11. Concurrent Update Test
-- =============================================
PRINT 'TEST 11: Concurrent Update Performance';
PRINT 'Testing 100 concurrent trip status updates...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 100
BEGIN
    UPDATE Trip
    SET [Status] = 'completed'
    WHERE TripID IN (
        SELECT TOP 1 TripID 
        FROM Trip 
        WHERE [Status] = 'assigned'
        ORDER BY NEWID()
    );
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 concurrent updates';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per update: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 12. Geospatial Query Performance
-- =============================================
PRINT 'TEST 12: Geospatial Distance Calculation Performance';
PRINT 'Testing Haversine formula calculations...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 100
BEGIN
    -- Find all locations within 5km of a random point
    DECLARE @TestLat DECIMAL(9,6) = 35.1856 + (RAND() * 0.1 - 0.05);
    DECLARE @TestLng DECIMAL(9,6) = 33.3823 + (RAND() * 0.1 - 0.05);
    
    SELECT TOP 50 LocationID, 
        111.045 * SQRT(
            POWER(LatDegrees - @TestLat, 2) + 
            POWER((LonDegrees - @TestLng) * COS(RADIANS(@TestLat)), 2)
        ) AS DistanceKm
    FROM [Location]
    WHERE 111.045 * SQRT(
            POWER(LatDegrees - @TestLat, 2) + 
            POWER((LonDegrees - @TestLng) * COS(RADIANS(@TestLat)), 2)
        ) <= 5.0
    ORDER BY DistanceKm;
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 100 geospatial queries';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 100.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13. AUTONOMOUS VEHICLE TESTS
-- =============================================
PRINT '========================================';
PRINT 'AUTONOMOUS VEHICLE PERFORMANCE TESTS';
PRINT '========================================';
PRINT '';

-- =============================================
-- 13a. Get All Autonomous Vehicles Performance
-- =============================================
PRINT 'TEST 13a: Get All Autonomous Vehicles';
PRINT 'Testing spGetAllAutonomousVehicles stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spGetAllAutonomousVehicles;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetAllAutonomousVehicles';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13b. Get Available Autonomous Vehicles Performance
-- =============================================
PRINT 'TEST 13b: Get Available Autonomous Vehicles';
PRINT 'Testing spGetAvailableAutonomousVehicles stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spGetAvailableAutonomousVehicles;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetAvailableAutonomousVehicles';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13c. Get Nearest Autonomous Vehicle Performance
-- =============================================
PRINT 'TEST 13c: Nearest Autonomous Vehicle Query';
PRINT 'Testing spGetNearestAutonomousVehicle with various pickup locations...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

DECLARE @TestPickupLat DECIMAL(9,6);
DECLARE @TestPickupLon DECIMAL(9,6);
DECLARE @TestDropoffLat DECIMAL(9,6);
DECLARE @TestDropoffLon DECIMAL(9,6);

WHILE @TestCounter < 50
BEGIN
    -- Generate random Nicosia coordinates
    SET @TestPickupLat = 35.1700 + (RAND() * 0.04);
    SET @TestPickupLon = 33.3600 + (RAND() * 0.04);
    
    EXEC dbo.spGetNearestAutonomousVehicle 
        @Latitude = @TestPickupLat, 
        @Longitude = @TestPickupLon;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetNearestAutonomousVehicle';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13d. Get All Autonomous Rides Performance
-- =============================================
PRINT 'TEST 13d: Get All Autonomous Rides';
PRINT 'Testing spGetAllAutonomousRides stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spGetAllAutonomousRides;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetAllAutonomousRides';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13e. Get Autonomous Vehicle Stats Performance
-- =============================================
PRINT 'TEST 13e: Autonomous Vehicle Statistics';
PRINT 'Testing spGetAutonomousVehicleStats stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spGetAutonomousVehicleStats;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetAutonomousVehicleStats';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 13f. Autonomous Ride Creation Performance
-- =============================================
PRINT 'TEST 13f: Autonomous Ride Creation Performance';
PRINT 'Testing spCreateAutonomousRide stored procedure (50 rides)...';

DECLARE @AVPassengerID INT;
DECLARE @AVVehicleID INT;
DECLARE @AVPickupLocationID INT;
DECLARE @AVDropoffLocationID INT;
DECLARE @AVSuccessCount INT = 0;

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    BEGIN TRY
        -- Get a random passenger
        SELECT TOP 1 @AVPassengerID = PassengerID FROM Passenger ORDER BY NEWID();
        
        -- Get a random available AV (with battery and active status)
        SELECT TOP 1 @AVVehicleID = AutonomousVehicleID 
        FROM AutonomousVehicle 
        WHERE [Status] = 'available' 
          AND IsActive = 1
          AND BatteryLevel >= 20
        ORDER BY NEWID();
        
        -- Get random pickup and dropoff locations
        SELECT TOP 1 @AVPickupLocationID = LocationID FROM [Location] ORDER BY NEWID();
        SELECT TOP 1 @AVDropoffLocationID = LocationID FROM [Location] WHERE LocationID != @AVPickupLocationID ORDER BY NEWID();
        
        IF @AVVehicleID IS NOT NULL AND @AVPassengerID IS NOT NULL AND @AVPickupLocationID IS NOT NULL
        BEGIN
            EXEC dbo.spCreateAutonomousRide
                @PassengerID = @AVPassengerID,
                @PickupLocationID = @AVPickupLocationID,
                @DropoffLocationID = @AVDropoffLocationID;
            SET @AVSuccessCount = @AVSuccessCount + 1;
        END
    END TRY
    BEGIN CATCH
        -- Silently continue on errors (vehicle became unavailable between select and insert)
    END CATCH
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed ' + CAST(@AVSuccessCount AS NVARCHAR) + ' autonomous ride creations (attempted 50)';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
IF @AVSuccessCount > 0
    PRINT 'Average per ride: ' + CAST(@Duration / CAST(@AVSuccessCount AS FLOAT) AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14. CARSHARE PERFORMANCE TESTS
-- =============================================
PRINT '========================================';
PRINT 'CARSHARE PERFORMANCE TESTS';
PRINT '========================================';
PRINT '';

-- =============================================
-- 14a. Get Carshare Zones Performance
-- =============================================
PRINT 'TEST 14a: Get Carshare Zones';
PRINT 'Testing spCarshareGetZones stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spCarshareGetZones;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareGetZones';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14b. Search Carshare Vehicles Performance
-- =============================================
PRINT 'TEST 14b: Search Carshare Vehicles';
PRINT 'Testing spCarshareSearchVehicles stored procedure...';

DECLARE @CSZoneID INT;

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    -- Get random zone
    SELECT TOP 1 @CSZoneID = ZoneID FROM CarshareZone ORDER BY NEWID();
    
    EXEC dbo.spCarshareSearchVehicles 
        @ZoneID = @CSZoneID,
        @VehicleTypeID = NULL;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareSearchVehicles';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14c. Get Carshare Vehicle Types Performance
-- =============================================
PRINT 'TEST 14c: Get Carshare Vehicle Types';
PRINT 'Testing spCarshareGetVehicleTypes stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spCarshareGetVehicleTypes;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareGetVehicleTypes';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14d. Fleet Utilization Report Performance
-- =============================================
PRINT 'TEST 14d: Fleet Utilization Report';
PRINT 'Testing spCarshareReportFleetUtilization stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spCarshareReportFleetUtilization;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareReportFleetUtilization';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14e. Zone Performance Report
-- =============================================
PRINT 'TEST 14e: Zone Performance Report';
PRINT 'Testing spCarshareReportZonePerformance stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spCarshareReportZonePerformance;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareReportZonePerformance';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14f. Carshare Financial Summary Performance
-- =============================================
PRINT 'TEST 14f: Carshare Financial Summary';
PRINT 'Testing spGetCarshareFinancialSummary stored procedure...';

DECLARE @CSStartDate DATE = DATEADD(DAY, -30, GETDATE());
DECLARE @CSEndDate DATE = GETDATE();

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spGetCarshareFinancialSummary 
        @StartDate = @CSStartDate,
        @EndDate = @CSEndDate;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spGetCarshareFinancialSummary';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14g. Carshare Revenue Report Performance
-- =============================================
PRINT 'TEST 14g: Carshare Revenue Report';
PRINT 'Testing spCarshareReportRevenue stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    EXEC dbo.spCarshareReportRevenue 
        @FromDate = @CSStartDate,
        @ToDate = @CSEndDate;
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareReportRevenue';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14h. Carshare Booking Creation Performance
-- =============================================
PRINT 'TEST 14h: Carshare Booking Creation';
PRINT 'Testing spCarshareCreateBooking stored procedure (50 bookings)...';

DECLARE @CSCustomerID INT;
DECLARE @CSVehicleID INT;
DECLARE @CSSuccessCount INT = 0;

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    BEGIN TRY
        -- Get a random carshare customer
        SELECT TOP 1 @CSCustomerID = CustomerID 
        FROM CarshareCustomer 
        WHERE VerificationStatus = 'approved' 
        ORDER BY NEWID();
        
        -- Get a random available carshare vehicle (not already booked)
        SELECT TOP 1 @CSVehicleID = v.VehicleID 
        FROM CarshareVehicle v
        WHERE v.[Status] = 'available' 
          AND v.IsActive = 1
          AND NOT EXISTS (
              SELECT 1 FROM CarshareBooking b 
              WHERE b.VehicleID = v.VehicleID 
                AND b.Status IN ('reserved', 'active')
          )
        ORDER BY NEWID();
        
        IF @CSCustomerID IS NOT NULL AND @CSVehicleID IS NOT NULL
        BEGIN
            EXEC dbo.spCarshareCreateBooking
                @CustomerID = @CSCustomerID,
                @VehicleID = @CSVehicleID;
            SET @CSSuccessCount = @CSSuccessCount + 1;
        END
    END TRY
    BEGIN CATCH
        -- Silently continue on errors (vehicle became booked between select and insert)
    END CATCH
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed ' + CAST(@CSSuccessCount AS NVARCHAR) + ' carshare booking creations (attempted 50)';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
IF @CSSuccessCount > 0
    PRINT 'Average per booking: ' + CAST(@Duration / CAST(@CSSuccessCount AS FLOAT) AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 14i. Get Rental History Performance
-- =============================================
PRINT 'TEST 14i: Get Rental History';
PRINT 'Testing spCarshareGetRentalHistory stored procedure...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 50
BEGIN
    -- Get a random carshare customer
    SELECT TOP 1 @CSCustomerID = CustomerID FROM CarshareCustomer ORDER BY NEWID();
    
    IF @CSCustomerID IS NOT NULL
    BEGIN
        EXEC dbo.spCarshareGetRentalHistory @CustomerID = @CSCustomerID;
    END
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 50 iterations of spCarshareGetRentalHistory';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per call: ' + CAST(@Duration / 50.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 15. CROSS-SERVICE COMPLEX QUERIES
-- =============================================
PRINT '========================================';
PRINT 'CROSS-SERVICE COMPLEX QUERY TESTS';
PRINT '========================================';
PRINT '';

-- =============================================
-- 15a. Combined Platform Revenue Query
-- =============================================
PRINT 'TEST 15a: Combined Platform Revenue Analysis';
PRINT 'Testing complex cross-service revenue aggregation...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 25
BEGIN
    SELECT 
        'Driver Trips' AS ServiceType,
        COUNT(DISTINCT p.TripID) AS TotalTransactions,
        SUM(p.Amount) AS TotalRevenue,
        AVG(p.Amount) AS AvgTransactionAmount
    FROM Payment p
    WHERE p.[Status] = 'completed'
    
    UNION ALL
    
    SELECT 
        'Autonomous Rides' AS ServiceType,
        COUNT(DISTINCT arp.AutonomousRideID) AS TotalTransactions,
        SUM(arp.Amount) AS TotalRevenue,
        AVG(arp.Amount) AS AvgTransactionAmount
    FROM AutonomousRidePayment arp
    WHERE arp.[Status] = 'completed'
    
    UNION ALL
    
    SELECT 
        'CarShare Rentals' AS ServiceType,
        COUNT(DISTINCT cr.RentalID) AS TotalTransactions,
        SUM(cr.TotalCost) AS TotalRevenue,
        AVG(cr.TotalCost) AS AvgTransactionAmount
    FROM CarshareRental cr
    WHERE cr.[Status] = 'completed';
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 25 iterations of combined revenue query';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 25.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 15b. Daily Activity Across All Services
-- =============================================
PRINT 'TEST 15b: Daily Activity Across All Services';
PRINT 'Testing complex daily activity aggregation...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 25
BEGIN
    SELECT 
        CAST(GETDATE() AS DATE) AS ActivityDate,
        (SELECT COUNT(*) FROM Trip WHERE CAST(DispatchTime AS DATE) = CAST(GETDATE() AS DATE)) AS DriverTripsToday,
        (SELECT COUNT(*) FROM AutonomousRide WHERE CAST(RequestedAt AS DATE) = CAST(GETDATE() AS DATE)) AS AVRidesToday,
        (SELECT COUNT(*) FROM CarshareRental WHERE CAST(StartedAt AS DATE) = CAST(GETDATE() AS DATE)) AS RentalsToday,
        (SELECT COUNT(*) FROM CarshareBooking WHERE CAST(BookedAt AS DATE) = CAST(GETDATE() AS DATE)) AS BookingsToday;
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 25 iterations of daily activity query';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 25.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- 15c. Passenger Cross-Service Usage Query
-- =============================================
PRINT 'TEST 15c: Passenger Cross-Service Usage Analysis';
PRINT 'Testing passenger usage across all service types...';

SET @TestStart = GETDATE();
SET @TestCounter = 0;

WHILE @TestCounter < 25
BEGIN
    SELECT TOP 100
        p.PassengerID,
        u.FullName,
        (SELECT COUNT(*) FROM RideRequest rr WHERE rr.PassengerID = p.PassengerID) AS TotalRideRequests,
        (SELECT COUNT(*) FROM AutonomousRide ar WHERE ar.PassengerID = p.PassengerID) AS TotalAVRides,
        (SELECT COUNT(*) FROM CarshareCustomer cc 
         JOIN CarshareRental cr ON cc.CustomerID = cr.CustomerID 
         WHERE cc.PassengerID = p.PassengerID) AS TotalCarshareRentals
    FROM Passenger p
    JOIN [User] u ON p.UserID = u.UserID
    ORDER BY p.PassengerID;
    
    SET @TestCounter = @TestCounter + 1;
END

SET @TestEnd = GETDATE();
SET @Duration = DATEDIFF(MILLISECOND, @TestStart, @TestEnd);

PRINT 'Completed 25 iterations of cross-service usage query';
PRINT 'Total time: ' + CAST(@Duration AS NVARCHAR) + ' ms';
PRINT 'Average per query: ' + CAST(@Duration / 25.0 AS NVARCHAR) + ' ms';
PRINT '';

-- =============================================
-- FINAL SUMMARY
-- =============================================
PRINT '========================================';
PRINT 'PERFORMANCE TESTING COMPLETE';
PRINT '========================================';
PRINT '';
PRINT 'Tests Performed:';
PRINT '  Driver Services: Tests 1-7, 11-12';
PRINT '  Autonomous Vehicles: Tests 13a-13f';
PRINT '  CarShare: Tests 14a-14i';
PRINT '  Cross-Service: Tests 15a-15c';
PRINT '';
PRINT 'Performance Recommendations:';
PRINT '1. Monitor queries taking >100ms average';
PRINT '2. Ensure indexes are being used (check TEST 8)';
PRINT '3. Review top resource-consuming queries (TEST 9)';
PRINT '4. Consider table partitioning for tables >10GB';
PRINT '5. Implement query result caching for reports';
PRINT '6. Monitor AV nearest vehicle queries under load';
PRINT '7. Consider caching CarShare zone data';
PRINT '';

-- =============================================
-- Wait Statistics
-- =============================================
PRINT 'Current Database Wait Statistics:';
SELECT TOP 10
    wait_type,
    wait_time_ms,
    waiting_tasks_count,
    signal_wait_time_ms
FROM sys.dm_os_wait_stats
WHERE wait_type NOT LIKE '%SLEEP%'
  AND wait_type NOT LIKE '%IDLE%'
  AND wait_type NOT LIKE '%QUEUE%'
ORDER BY wait_time_ms DESC;

PRINT '';
PRINT 'Testing complete!';
GO
