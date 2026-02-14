/* ============================================================
   OSRH - CONSOLIDATED SEEDING FILE
   ============================================================
   This file combines all seeding scripts into one file.
   
   EXECUTION ORDER:
   1. osrh_tables.sql (create tables first)
   2. osrh_sp.sql (create stored procedures)
   3. osrh_triggers.sql (create triggers)
   4. osrh_seeding.sql (THIS FILE - seed data)
   
   CONTENTS:
   A. Payment Method Types
   B. Service Types
   C. Vehicle Types
   D. Service Type Requirements
   E. VehicleType-ServiceType Mappings
   F. Geofences & Bridges (Cyprus Districts)
   G. Simulated Drivers
   H. Autonomous Vehicles
   I. CarShare Data (Types, Zones, Vehicles)
   ============================================================ */

SET NOCOUNT ON;
GO

/* ============================================================
   A. PAYMENT METHOD TYPES
   ============================================================ */

-- Insert CASH payment method
IF NOT EXISTS (SELECT 1 FROM dbo.PaymentMethodType WHERE Code = 'CASH')
BEGIN
    INSERT INTO dbo.PaymentMethodType (Code, Description)
    VALUES ('CASH', 'Cash Payment');
END
GO

-- Insert KASPA payment method (Cryptocurrency)
IF NOT EXISTS (SELECT 1 FROM dbo.PaymentMethodType WHERE Code = 'KASPA')
BEGIN
    INSERT INTO dbo.PaymentMethodType (Code, Description)
    VALUES ('KASPA', 'Kaspa Cryptocurrency (KAS)');
END
GO

/* ============================================================
   B. SERVICE TYPES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.ServiceType WHERE Code = 'STANDARD')
BEGIN
    INSERT INTO dbo.[ServiceType] (Code, Name, Description, IsActive)
    VALUES
        ('STANDARD', 
         'Standard Passenger Ride', 
         'Basic passenger transportation from one location to another with standard vehicles.', 
         1),
        
        ('LUXURY', 
         'Luxury Passenger Ride', 
         'Premium passenger transportation with vehicles meeting higher standards for safety, amenities, passenger space, and comfort.', 
         1),
        
        ('LIGHT_CARGO', 
         'Light Household Cargo Transport', 
         'Transportation of light household goods using van-type vehicles. Suitable for items such as household appliances (up to washing machine size), TVs up to 75", and small furniture.', 
         1),
        
        ('HEAVY_CARGO', 
         'Heavy Household Cargo Transport', 
         'Transportation of large household goods with vehicles capable of moving larger volumes, suitable for moving purposes.', 
         1),
        
        ('MULTI_STOP', 
         'Multi-Stop Ride with Geofence Bridges', 
         'Advanced service allowing passengers to travel across multiple geofenced areas using different vehicles, with transfers at designated bridge points between geofences. Useful when a single vehicle cannot reach from point A to point B due to geofencing restrictions.', 
         1);
END
GO

/* ============================================================
   C. VEHICLE TYPES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.VehicleType WHERE Name = 'Sedan')
BEGIN
    INSERT INTO dbo.VehicleType (Name, Description, MaxPassengers, IsWheelchairReady)
    VALUES 
        ('Sedan', 'Standard 4-door sedan', 5, 0),
        ('Hatchback', 'Compact car with rear door', 5, 0),
        ('SUV', 'Sport Utility Vehicle', 7, 0),
        ('Coupe', '2-door sporty car', 4, 0),
        ('Convertible', 'Open-top vehicle', 4, 0),
        ('Pickup Truck', 'Light pickup truck', 3, 0),
        ('Minivan', 'Family minivan', 7, 0),
        ('Van', 'Passenger van', 8, 0),
        ('Wagon', 'Station wagon', 5, 0),
        ('Crossover', 'Crossover SUV', 5, 0),
        ('Luxury Car', 'Premium luxury vehicle', 5, 0),
        ('Sports Car', 'High-performance sports car', 2, 0),
        ('Electric Car', 'Battery electric vehicle', 5, 0),
        ('Hybrid Car', 'Hybrid electric vehicle', 5, 0),
        ('Truck', 'Commercial truck', 3, 0),
        ('Motorcycle', 'Two-wheeled motor vehicle', 1, 0);
END
GO

/* ============================================================
   D. SERVICE TYPE REQUIREMENTS
   ============================================================ */

-- Clear existing and insert fresh requirements
DELETE FROM dbo.ServiceTypeRequirements;
GO

-- STANDARD Service Requirements
INSERT INTO dbo.ServiceTypeRequirements (
    ServiceTypeID, MinVehicleYear, MaxVehicleAge, MinDoors, RequirePassengerSeat,
    MinSeatingCapacity, MaxWeightCapacityKg, MinDriverAge, RequireCriminalRecord,
    RequireMedicalCert, RequireInsurance, RequireMOT, RequirementsDescription
)
SELECT ServiceTypeID, 2015, 10, 4, 1, 4, NULL, 21, 1, 1, 1, 1,
    N'Requirements for Standard Passenger Transport Service:
• Driver age: At least 21 years old
• Vehicle: 4-door, year 2015 or newer (maximum age 10 years)
• Seats: At least 4 passenger seats
• Required driver documents: ID, License, Criminal Record, Medical Certificate
• Required vehicle documents: Registration, Insurance, MOT'
FROM dbo.ServiceType WHERE Code = 'STANDARD';
GO

-- LUXURY Service Requirements
INSERT INTO dbo.ServiceTypeRequirements (
    ServiceTypeID, MinVehicleYear, MaxVehicleAge, MinDoors, RequirePassengerSeat,
    MinSeatingCapacity, MaxWeightCapacityKg, MinDriverAge, RequireCriminalRecord,
    RequireMedicalCert, RequireInsurance, RequireMOT, RequirementsDescription
)
SELECT ServiceTypeID, 2020, 5, 4, 1, 4, NULL, 25, 1, 1, 1, 1,
    N'Requirements for Luxury Service:
• Driver age: At least 25 years old
• Vehicle: Premium 4-door, year 2020 or newer (maximum age 5 years)
• Premium vehicle category (Mercedes, BMW, Audi, etc.)
• Required driver documents: ID, License, Criminal Record, Medical Certificate
• Required vehicle documents: Registration, Insurance, MOT'
FROM dbo.ServiceType WHERE Code = 'LUXURY';
GO

-- LIGHT_CARGO Service Requirements
INSERT INTO dbo.ServiceTypeRequirements (
    ServiceTypeID, MinVehicleYear, MaxVehicleAge, MinDoors, RequirePassengerSeat,
    MinSeatingCapacity, MaxWeightCapacityKg, MinDriverAge, RequireCriminalRecord,
    RequireMedicalCert, RequireInsurance, RequireMOT, RequirementsDescription
)
SELECT ServiceTypeID, 2012, 12, 2, 0, 2, 500, 18, 1, 1, 1, 1,
    N'Requirements for Light Cargo (up to 500kg):
• Driver age: At least 18 years old
• Vehicle: Van or passenger vehicle, year 2012 or newer
• Cargo capacity: up to 500kg
• Required documents: ID, License, Criminal Record, Medical, Registration, Insurance, MOT'
FROM dbo.ServiceType WHERE Code = 'LIGHT_CARGO';
GO

-- HEAVY_CARGO Service Requirements
INSERT INTO dbo.ServiceTypeRequirements (
    ServiceTypeID, MinVehicleYear, MaxVehicleAge, MinDoors, RequirePassengerSeat,
    MinSeatingCapacity, MaxWeightCapacityKg, MinDriverAge, RequireCriminalRecord,
    RequireMedicalCert, RequireInsurance, RequireMOT, RequirementsDescription
)
SELECT ServiceTypeID, 2010, 15, 2, 0, 2, 2000, 21, 1, 1, 1, 1,
    N'Requirements for Heavy Cargo (up to 2000kg):
• Driver age: At least 21 years old
• Vehicle: Truck or large van, year 2010 or newer
• Cargo capacity: up to 2000kg
• Required: C1 category license if applicable'
FROM dbo.ServiceType WHERE Code = 'HEAVY_CARGO';
GO

-- MULTI_STOP Service Requirements
INSERT INTO dbo.ServiceTypeRequirements (
    ServiceTypeID, MinVehicleYear, MaxVehicleAge, MinDoors, RequirePassengerSeat,
    MinSeatingCapacity, MaxWeightCapacityKg, MinDriverAge, RequireCriminalRecord,
    RequireMedicalCert, RequireInsurance, RequireMOT, RequirementsDescription
)
SELECT ServiceTypeID, 2015, 10, 4, 1, 4, NULL, 21, 1, 1, 1, 1,
    N'Requirements for Multi-Stop Service:
• Driver age: At least 21 years old
• Vehicle: 4-door, year 2015 or newer
• Seats: At least 4 passenger seats
• Required documents: ID, License, Criminal Record, Medical, Registration, Insurance, MOT'
FROM dbo.ServiceType WHERE Code = 'MULTI_STOP';
GO

/* ============================================================
   E. VEHICLETYPE-SERVICETYPE MAPPINGS
   ============================================================ */

-- Only insert if not already populated
IF NOT EXISTS (SELECT 1 FROM dbo.VehicleType_ServiceType)
BEGIN
    -- Sedan -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (1, 1), (1, 5);
    
    -- Hatchback -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (2, 1), (2, 5);
    
    -- SUV -> Standard, Luxury, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (3, 1), (3, 2), (3, 5);
    
    -- Coupe -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (4, 1), (4, 5);
    
    -- Convertible -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (5, 1), (5, 5);
    
    -- Pickup Truck -> Light Cargo, Heavy Cargo, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (6, 3), (6, 4), (6, 5);
    
    -- Minivan -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (7, 1), (7, 5);
    
    -- Van -> Standard, Light Cargo, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (8, 1), (8, 3), (8, 5);
    
    -- Wagon -> Standard, Light Cargo, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (9, 1), (9, 3), (9, 5);
    
    -- Crossover -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (10, 1), (10, 5);
    
    -- Luxury Car -> Standard, Luxury, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (11, 1), (11, 2), (11, 5);
    
    -- Sports Car -> Luxury, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (12, 2), (12, 5);
    
    -- Electric Car -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (13, 1), (13, 5);
    
    -- Hybrid Car -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (14, 1), (14, 5);
    
    -- Truck -> Heavy Cargo, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (15, 4), (15, 5);
    
    -- Motorcycle -> Standard, Multi-Stop
    INSERT INTO dbo.[VehicleType_ServiceType] (VehicleTypeID, ServiceTypeID)
    VALUES (16, 1), (16, 5);
END
GO

/* ============================================================
   F. GEOFENCES & BRIDGES (Cyprus Districts)
   ============================================================ */

-- Only create if not already populated
IF NOT EXISTS (SELECT 1 FROM dbo.Geofence WHERE Name = 'Paphos_District')
BEGIN
    -- Geofence 1: Paphos District
    INSERT INTO dbo.Geofence (Name, Description, IsActive)
    VALUES ('Paphos_District', 'Paphos District - Western Cyprus', 1);
    
    DECLARE @PaphosGeofenceID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES 
        (@PaphosGeofenceID, 1, 34.9982, 32.3132), (@PaphosGeofenceID, 2, 35.0224, 32.2868),
        (@PaphosGeofenceID, 3, 35.0651, 32.2689), (@PaphosGeofenceID, 4, 35.0876, 32.2758),
        (@PaphosGeofenceID, 5, 35.1036, 32.2857), (@PaphosGeofenceID, 6, 35.0618, 32.3310),
        (@PaphosGeofenceID, 7, 35.0446, 32.3637), (@PaphosGeofenceID, 8, 35.0393, 32.3877),
        (@PaphosGeofenceID, 9, 35.0398, 32.4086), (@PaphosGeofenceID, 10, 35.0519, 32.4467),
        (@PaphosGeofenceID, 11, 35.0797, 32.4869), (@PaphosGeofenceID, 12, 35.1230, 32.5137),
        (@PaphosGeofenceID, 13, 35.1455, 32.5271), (@PaphosGeofenceID, 14, 35.1556, 32.5425),
        (@PaphosGeofenceID, 15, 35.1749, 32.5535), (@PaphosGeofenceID, 16, 35.1342, 32.5957),
        (@PaphosGeofenceID, 17, 35.0980, 32.6309), (@PaphosGeofenceID, 18, 35.0637, 32.6112),
        (@PaphosGeofenceID, 19, 35.0452, 32.6194), (@PaphosGeofenceID, 20, 35.0300, 32.6328),
        (@PaphosGeofenceID, 21, 35.0185, 32.6514), (@PaphosGeofenceID, 22, 34.9913, 32.6903),
        (@PaphosGeofenceID, 23, 34.9726, 32.7073), (@PaphosGeofenceID, 24, 34.9574, 32.7338),
        (@PaphosGeofenceID, 25, 34.9380, 32.7513), (@PaphosGeofenceID, 26, 34.9493, 32.7897),
        (@PaphosGeofenceID, 27, 34.9493, 32.8021), (@PaphosGeofenceID, 28, 34.9173, 32.7913),
        (@PaphosGeofenceID, 29, 34.8804, 32.7789), (@PaphosGeofenceID, 30, 34.8492, 32.7502),
        (@PaphosGeofenceID, 31, 34.8238, 32.7401), (@PaphosGeofenceID, 32, 34.7800, 32.7200),
        (@PaphosGeofenceID, 33, 34.6661, 32.6182), (@PaphosGeofenceID, 34, 34.6866, 32.5764),
        (@PaphosGeofenceID, 35, 34.6963, 32.5621), (@PaphosGeofenceID, 36, 34.6984, 32.5314),
        (@PaphosGeofenceID, 37, 34.7076, 32.5077), (@PaphosGeofenceID, 38, 34.7044, 32.4948),
        (@PaphosGeofenceID, 39, 34.7092, 32.4814), (@PaphosGeofenceID, 40, 34.7334, 32.4433),
        (@PaphosGeofenceID, 41, 34.7351, 32.4340), (@PaphosGeofenceID, 42, 34.7543, 32.4179),
        (@PaphosGeofenceID, 43, 34.7543, 32.4004), (@PaphosGeofenceID, 44, 34.7760, 32.4049),
        (@PaphosGeofenceID, 45, 34.7913, 32.3915), (@PaphosGeofenceID, 46, 34.8062, 32.3918),
        (@PaphosGeofenceID, 47, 34.8462, 32.3825), (@PaphosGeofenceID, 48, 34.8502, 32.3595),
        (@PaphosGeofenceID, 49, 34.8569, 32.3606), (@PaphosGeofenceID, 50, 34.8561, 32.3496),
        (@PaphosGeofenceID, 51, 34.8600, 32.3472), (@PaphosGeofenceID, 52, 34.8704, 32.3413),
        (@PaphosGeofenceID, 53, 34.8840, 32.3317), (@PaphosGeofenceID, 54, 34.9020, 32.3125),
        (@PaphosGeofenceID, 55, 34.9217, 32.3286), (@PaphosGeofenceID, 56, 34.9394, 32.3204),
        (@PaphosGeofenceID, 57, 34.9555, 32.3008), (@PaphosGeofenceID, 58, 34.9594, 32.3146),
        (@PaphosGeofenceID, 59, 34.9695, 32.3111);

    -- Geofence 2: Limassol District
    INSERT INTO dbo.Geofence (Name, Description, IsActive)
    VALUES ('Limassol_District', 'Limassol District - Southern Cyprus', 1);
    
    DECLARE @LimassolGeofenceID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES 
        (@LimassolGeofenceID, 1, 34.9521, 32.8107), (@LimassolGeofenceID, 2, 34.9600, 32.8471),
        (@LimassolGeofenceID, 3, 34.9788, 32.8622), (@LimassolGeofenceID, 4, 34.9549, 32.9082),
        (@LimassolGeofenceID, 5, 34.9541, 32.9525), (@LimassolGeofenceID, 6, 34.9324, 33.0211),
        (@LimassolGeofenceID, 7, 34.9020, 33.0932), (@LimassolGeofenceID, 8, 35.0019, 33.1561),
        (@LimassolGeofenceID, 9, 34.9884, 33.2515), (@LimassolGeofenceID, 10, 34.9180, 33.3147),
        (@LimassolGeofenceID, 11, 34.8842, 33.3291), (@LimassolGeofenceID, 12, 34.8502, 33.3428),
        (@LimassolGeofenceID, 13, 34.7996, 33.3353), (@LimassolGeofenceID, 14, 34.7709, 33.3614),
        (@LimassolGeofenceID, 15, 34.7537, 33.3747), (@LimassolGeofenceID, 16, 34.7487, 33.3806),
        (@LimassolGeofenceID, 17, 34.7436, 33.3907), (@LimassolGeofenceID, 18, 34.7073, 33.2707),
        (@LimassolGeofenceID, 19, 34.7052, 33.1991), (@LimassolGeofenceID, 20, 34.7131, 33.1564),
        (@LimassolGeofenceID, 21, 34.7103, 33.1403), (@LimassolGeofenceID, 22, 34.7017, 33.1070),
        (@LimassolGeofenceID, 23, 34.6910, 33.0737), (@LimassolGeofenceID, 24, 34.6672, 33.0314),
        (@LimassolGeofenceID, 25, 34.6461, 33.0137), (@LimassolGeofenceID, 26, 34.6260, 33.0043),
        (@LimassolGeofenceID, 27, 34.5910, 33.0036), (@LimassolGeofenceID, 28, 34.6000, 32.9737),
        (@LimassolGeofenceID, 29, 34.5969, 32.9600), (@LimassolGeofenceID, 30, 34.5877, 32.9403),
        (@LimassolGeofenceID, 31, 34.6290, 32.9178), (@LimassolGeofenceID, 32, 34.6641, 32.8850),
        (@LimassolGeofenceID, 33, 34.6708, 32.8484), (@LimassolGeofenceID, 34, 34.6644, 32.8409),
        (@LimassolGeofenceID, 35, 34.6667, 32.8199), (@LimassolGeofenceID, 36, 34.6616, 32.8223),
        (@LimassolGeofenceID, 37, 34.6654, 32.7932), (@LimassolGeofenceID, 38, 34.6569, 32.7738),
        (@LimassolGeofenceID, 39, 34.6575, 32.7590), (@LimassolGeofenceID, 40, 34.6480, 32.7514),
        (@LimassolGeofenceID, 41, 34.6517, 32.7236), (@LimassolGeofenceID, 42, 34.6417, 32.7169),
        (@LimassolGeofenceID, 43, 34.6404, 32.7053), (@LimassolGeofenceID, 44, 34.6634, 32.6318),
        (@LimassolGeofenceID, 45, 34.6855, 32.6534), (@LimassolGeofenceID, 46, 34.7103, 32.6761),
        (@LimassolGeofenceID, 47, 34.7791, 32.7290), (@LimassolGeofenceID, 48, 34.8228, 32.7478),
        (@LimassolGeofenceID, 49, 34.8479, 32.7568), (@LimassolGeofenceID, 50, 34.8789, 32.7842);

    -- Geofence 3: Nicosia District
    INSERT INTO dbo.Geofence (Name, Description, IsActive)
    VALUES ('Nicosia_District', 'Nicosia District - Central Cyprus (Republic of Cyprus controlled area)', 1);
    
    DECLARE @NicosiaGeofenceID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES 
        (@NicosiaGeofenceID, 1, 34.9383, 33.0263), (@NicosiaGeofenceID, 2, 34.9587, 32.9578),
        (@NicosiaGeofenceID, 3, 34.9591, 32.9101), (@NicosiaGeofenceID, 4, 34.9858, 32.8605),
        (@NicosiaGeofenceID, 5, 34.9612, 32.8350), (@NicosiaGeofenceID, 6, 34.9569, 32.7945),
        (@NicosiaGeofenceID, 7, 34.9508, 32.7657), (@NicosiaGeofenceID, 8, 34.9982, 32.6991),
        (@NicosiaGeofenceID, 9, 35.0196, 32.6665), (@NicosiaGeofenceID, 10, 35.0349, 32.6402),
        (@NicosiaGeofenceID, 11, 35.0348, 32.6350), (@NicosiaGeofenceID, 12, 35.0606, 32.6256),
        (@NicosiaGeofenceID, 13, 35.0738, 32.6277), (@NicosiaGeofenceID, 14, 35.0890, 32.6376),
        (@NicosiaGeofenceID, 15, 35.1179, 32.6318), (@NicosiaGeofenceID, 16, 35.1250, 32.6177),
        (@NicosiaGeofenceID, 17, 35.1469, 32.5964), (@NicosiaGeofenceID, 18, 35.1679, 32.5715),
        (@NicosiaGeofenceID, 19, 35.1769, 32.5903), (@NicosiaGeofenceID, 20, 35.1812, 32.6114),
        (@NicosiaGeofenceID, 21, 35.1887, 32.6151), (@NicosiaGeofenceID, 22, 35.1933, 32.6251),
        (@NicosiaGeofenceID, 23, 35.1876, 32.6292), (@NicosiaGeofenceID, 24, 35.1881, 32.6388),
        (@NicosiaGeofenceID, 25, 35.1922, 32.6436), (@NicosiaGeofenceID, 26, 35.1950, 32.6414),
        (@NicosiaGeofenceID, 27, 35.1962, 32.6521), (@NicosiaGeofenceID, 28, 35.1933, 32.6692),
        (@NicosiaGeofenceID, 29, 35.1832, 32.6958), (@NicosiaGeofenceID, 30, 35.1654, 32.6934),
        (@NicosiaGeofenceID, 31, 35.1415, 32.6977), (@NicosiaGeofenceID, 32, 35.1293, 32.6907),
        (@NicosiaGeofenceID, 33, 35.1234, 32.7106), (@NicosiaGeofenceID, 34, 35.1255, 32.7290),
        (@NicosiaGeofenceID, 35, 35.1170, 32.7398), (@NicosiaGeofenceID, 36, 35.1165, 32.7783),
        (@NicosiaGeofenceID, 37, 35.1105, 32.7886), (@NicosiaGeofenceID, 38, 35.0988, 32.7875),
        (@NicosiaGeofenceID, 39, 35.0792, 32.8132), (@NicosiaGeofenceID, 40, 35.0778, 32.8217),
        (@NicosiaGeofenceID, 41, 35.0796, 32.8317), (@NicosiaGeofenceID, 42, 35.0775, 32.8622),
        (@NicosiaGeofenceID, 43, 35.0740, 32.8742), (@NicosiaGeofenceID, 44, 35.0972, 32.8729),
        (@NicosiaGeofenceID, 45, 35.1004, 32.8863), (@NicosiaGeofenceID, 46, 35.0979, 32.8980),
        (@NicosiaGeofenceID, 47, 35.0837, 32.9088), (@NicosiaGeofenceID, 48, 35.0814, 32.9267),
        (@NicosiaGeofenceID, 49, 35.0941, 32.9621), (@NicosiaGeofenceID, 50, 35.1084, 32.9731),
        (@NicosiaGeofenceID, 51, 35.1134, 32.9809), (@NicosiaGeofenceID, 52, 35.1168, 32.9966),
        (@NicosiaGeofenceID, 53, 35.1249, 33.0077), (@NicosiaGeofenceID, 54, 35.1385, 33.0214),
        (@NicosiaGeofenceID, 55, 35.1403, 33.0269), (@NicosiaGeofenceID, 56, 35.1462, 33.0376),
        (@NicosiaGeofenceID, 57, 35.1449, 33.0493), (@NicosiaGeofenceID, 58, 35.1438, 33.0580),
        (@NicosiaGeofenceID, 59, 35.1420, 33.0628), (@NicosiaGeofenceID, 60, 35.1389, 33.0673),
        (@NicosiaGeofenceID, 61, 35.1372, 33.0706), (@NicosiaGeofenceID, 62, 35.1353, 33.0730),
        (@NicosiaGeofenceID, 63, 35.1334, 33.0752), (@NicosiaGeofenceID, 64, 35.1334, 33.0791),
        (@NicosiaGeofenceID, 65, 35.1358, 33.0826), (@NicosiaGeofenceID, 66, 35.1368, 33.0888),
        (@NicosiaGeofenceID, 67, 35.1363, 33.0982), (@NicosiaGeofenceID, 68, 35.1363, 33.1217),
        (@NicosiaGeofenceID, 69, 35.1398, 33.1267), (@NicosiaGeofenceID, 70, 35.1412, 33.1317),
        (@NicosiaGeofenceID, 71, 35.1490, 33.1339), (@NicosiaGeofenceID, 72, 35.1537, 33.1404),
        (@NicosiaGeofenceID, 73, 35.1551, 33.1466), (@NicosiaGeofenceID, 74, 35.1689, 33.1595),
        (@NicosiaGeofenceID, 75, 35.1762, 33.1670), (@NicosiaGeofenceID, 76, 35.1789, 33.1744),
        (@NicosiaGeofenceID, 77, 35.1720, 33.1968), (@NicosiaGeofenceID, 78, 35.1729, 33.1984),
        (@NicosiaGeofenceID, 79, 35.1713, 33.2000), (@NicosiaGeofenceID, 80, 35.1690, 33.2016),
        (@NicosiaGeofenceID, 81, 35.1699, 33.2136), (@NicosiaGeofenceID, 82, 35.1649, 33.2286),
        (@NicosiaGeofenceID, 83, 35.1631, 33.2356), (@NicosiaGeofenceID, 84, 35.1587, 33.2381),
        (@NicosiaGeofenceID, 85, 35.1533, 33.2378), (@NicosiaGeofenceID, 86, 35.1506, 33.2442),
        (@NicosiaGeofenceID, 87, 35.1518, 33.2530), (@NicosiaGeofenceID, 88, 35.1445, 33.2596),
        (@NicosiaGeofenceID, 89, 35.1422, 33.2681), (@NicosiaGeofenceID, 90, 35.1386, 33.2745),
        (@NicosiaGeofenceID, 91, 35.1320, 33.2827), (@NicosiaGeofenceID, 92, 35.1304, 33.2893),
        (@NicosiaGeofenceID, 93, 35.1343, 33.2924), (@NicosiaGeofenceID, 94, 35.1372, 33.2953),
        (@NicosiaGeofenceID, 95, 35.1397, 33.2931), (@NicosiaGeofenceID, 96, 35.1426, 33.2940),
        (@NicosiaGeofenceID, 97, 35.1453, 33.2966), (@NicosiaGeofenceID, 98, 35.1476, 33.2973),
        (@NicosiaGeofenceID, 99, 35.1486, 33.2961), (@NicosiaGeofenceID, 100, 35.1500, 33.2955);

    -- Additional Nicosia points (101-199)
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES 
        (@NicosiaGeofenceID, 101, 35.1527, 33.2965), (@NicosiaGeofenceID, 102, 35.1575, 33.3028),
        (@NicosiaGeofenceID, 103, 35.1661, 33.3140), (@NicosiaGeofenceID, 104, 35.1667, 33.3140),
        (@NicosiaGeofenceID, 105, 35.1675, 33.3139), (@NicosiaGeofenceID, 106, 35.1687, 33.3141),
        (@NicosiaGeofenceID, 107, 35.1695, 33.3173), (@NicosiaGeofenceID, 108, 35.1714, 33.3166),
        (@NicosiaGeofenceID, 109, 35.1719, 33.3179), (@NicosiaGeofenceID, 110, 35.1720, 33.3218),
        (@NicosiaGeofenceID, 111, 35.1729, 33.3219), (@NicosiaGeofenceID, 112, 35.1735, 33.3218),
        (@NicosiaGeofenceID, 113, 35.1743, 33.3216), (@NicosiaGeofenceID, 114, 35.1762, 33.3221),
        (@NicosiaGeofenceID, 115, 35.1764, 33.3213), (@NicosiaGeofenceID, 116, 35.1769, 33.3197),
        (@NicosiaGeofenceID, 117, 35.1776, 33.3199), (@NicosiaGeofenceID, 118, 35.1778, 33.3203),
        (@NicosiaGeofenceID, 119, 35.1785, 33.3198), (@NicosiaGeofenceID, 120, 35.1803, 33.3208),
        (@NicosiaGeofenceID, 121, 35.1801, 33.3218), (@NicosiaGeofenceID, 122, 35.1806, 33.3223),
        (@NicosiaGeofenceID, 123, 35.1803, 33.3238), (@NicosiaGeofenceID, 124, 35.1801, 33.3246),
        (@NicosiaGeofenceID, 125, 35.1806, 33.3248), (@NicosiaGeofenceID, 126, 35.1813, 33.3250),
        (@NicosiaGeofenceID, 127, 35.1814, 33.3262), (@NicosiaGeofenceID, 128, 35.1813, 33.3313),
        (@NicosiaGeofenceID, 129, 35.1810, 33.3339), (@NicosiaGeofenceID, 130, 35.1818, 33.3360),
        (@NicosiaGeofenceID, 131, 35.1813, 33.3369), (@NicosiaGeofenceID, 132, 35.1807, 33.3363),
        (@NicosiaGeofenceID, 133, 35.1801, 33.3363), (@NicosiaGeofenceID, 134, 35.1800, 33.3367),
        (@NicosiaGeofenceID, 135, 35.1802, 33.3381), (@NicosiaGeofenceID, 136, 35.1810, 33.3401),
        (@NicosiaGeofenceID, 137, 35.1812, 33.3457), (@NicosiaGeofenceID, 138, 35.1786, 33.3482),
        (@NicosiaGeofenceID, 139, 35.1783, 33.3486), (@NicosiaGeofenceID, 140, 35.1773, 33.3488),
        (@NicosiaGeofenceID, 141, 35.1763, 33.3494), (@NicosiaGeofenceID, 142, 35.1775, 33.3530),
        (@NicosiaGeofenceID, 143, 35.1768, 33.3539), (@NicosiaGeofenceID, 144, 35.1774, 33.3552),
        (@NicosiaGeofenceID, 145, 35.1759, 33.3553), (@NicosiaGeofenceID, 146, 35.1746, 33.3547),
        (@NicosiaGeofenceID, 147, 35.1738, 33.3562), (@NicosiaGeofenceID, 148, 35.1741, 33.3586),
        (@NicosiaGeofenceID, 149, 35.1737, 33.3586), (@NicosiaGeofenceID, 150, 35.1740, 33.3596),
        (@NicosiaGeofenceID, 151, 35.1741, 33.3603), (@NicosiaGeofenceID, 152, 35.1748, 33.3605),
        (@NicosiaGeofenceID, 153, 35.1747, 33.3641), (@NicosiaGeofenceID, 154, 35.1751, 33.3661),
        (@NicosiaGeofenceID, 155, 35.1759, 33.3677), (@NicosiaGeofenceID, 156, 35.1771, 33.3683),
        (@NicosiaGeofenceID, 157, 35.1778, 33.3699), (@NicosiaGeofenceID, 158, 35.1795, 33.3726),
        (@NicosiaGeofenceID, 159, 35.1819, 33.3731), (@NicosiaGeofenceID, 160, 35.1825, 33.3743),
        (@NicosiaGeofenceID, 161, 35.1854, 33.3736), (@NicosiaGeofenceID, 162, 35.1872, 33.3754),
        (@NicosiaGeofenceID, 163, 35.1893, 33.3751), (@NicosiaGeofenceID, 164, 35.1905, 33.3782),
        (@NicosiaGeofenceID, 165, 35.1954, 33.3889), (@NicosiaGeofenceID, 166, 35.1930, 33.3898),
        (@NicosiaGeofenceID, 167, 35.1900, 33.3944), (@NicosiaGeofenceID, 168, 35.1860, 33.3982),
        (@NicosiaGeofenceID, 169, 35.1812, 33.4030), (@NicosiaGeofenceID, 170, 35.1764, 33.4067),
        (@NicosiaGeofenceID, 171, 35.1761, 33.4015), (@NicosiaGeofenceID, 172, 35.1737, 33.3995),
        (@NicosiaGeofenceID, 173, 35.1712, 33.3996), (@NicosiaGeofenceID, 174, 35.1669, 33.3998),
        (@NicosiaGeofenceID, 175, 35.1651, 33.4001), (@NicosiaGeofenceID, 176, 35.1641, 33.4009),
        (@NicosiaGeofenceID, 177, 35.1635, 33.4024), (@NicosiaGeofenceID, 178, 35.1620, 33.4009),
        (@NicosiaGeofenceID, 179, 35.1555, 33.4083), (@NicosiaGeofenceID, 180, 35.1565, 33.4158),
        (@NicosiaGeofenceID, 181, 35.1545, 33.4203), (@NicosiaGeofenceID, 182, 35.1523, 33.4185),
        (@NicosiaGeofenceID, 183, 35.1520, 33.4153), (@NicosiaGeofenceID, 184, 35.1506, 33.4164),
        (@NicosiaGeofenceID, 185, 35.1499, 33.4185), (@NicosiaGeofenceID, 186, 35.1473, 33.4181),
        (@NicosiaGeofenceID, 187, 35.1447, 33.4170), (@NicosiaGeofenceID, 188, 35.1432, 33.4140),
        (@NicosiaGeofenceID, 189, 35.1341, 33.4196), (@NicosiaGeofenceID, 190, 35.1262, 33.4271),
        (@NicosiaGeofenceID, 191, 35.1200, 33.4420), (@NicosiaGeofenceID, 192, 35.1118, 33.4523),
        (@NicosiaGeofenceID, 193, 35.0931, 33.4439), (@NicosiaGeofenceID, 194, 35.0508, 33.4640),
        (@NicosiaGeofenceID, 195, 35.0373, 33.4450), (@NicosiaGeofenceID, 196, 35.0034, 33.4499),
        (@NicosiaGeofenceID, 197, 34.9988, 33.2563), (@NicosiaGeofenceID, 198, 35.0089, 33.1499),
        (@NicosiaGeofenceID, 199, 34.9103, 33.0887);

    -- Geofence 4: Larnaca District
    INSERT INTO dbo.Geofence (Name, Description, IsActive)
    VALUES ('Larnaca_District', 'Larnaca District - Eastern Cyprus', 1);
    
    DECLARE @LarnacaGeofenceID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.GeofencePoint (GeofenceID, SequenceNo, LatDegrees, LonDegrees)
    VALUES 
        (@LarnacaGeofenceID, 1, 34.9927, 33.2613), (@LarnacaGeofenceID, 2, 34.9998, 33.4602),
        (@LarnacaGeofenceID, 3, 35.0106, 33.4820), (@LarnacaGeofenceID, 4, 35.0238, 33.4820),
        (@LarnacaGeofenceID, 5, 35.0446, 33.4862), (@LarnacaGeofenceID, 6, 35.0566, 33.4865),
        (@LarnacaGeofenceID, 7, 35.0693, 33.4935), (@LarnacaGeofenceID, 8, 35.0795, 33.5323),
        (@LarnacaGeofenceID, 9, 35.0480, 33.5698), (@LarnacaGeofenceID, 10, 35.0370, 33.5835),
        (@LarnacaGeofenceID, 11, 35.0494, 33.6127), (@LarnacaGeofenceID, 12, 35.0140, 33.6934),
        (@LarnacaGeofenceID, 13, 35.0103, 33.7012), (@LarnacaGeofenceID, 14, 35.0525, 33.7370),
        (@LarnacaGeofenceID, 15, 35.0370, 33.7617), (@LarnacaGeofenceID, 16, 35.0452, 33.7922),
        (@LarnacaGeofenceID, 17, 35.0699, 33.8228), (@LarnacaGeofenceID, 18, 35.0553, 33.8434),
        (@LarnacaGeofenceID, 19, 35.0823, 33.8726), (@LarnacaGeofenceID, 20, 35.0629, 33.9114),
        (@LarnacaGeofenceID, 21, 35.0688, 33.9618), (@LarnacaGeofenceID, 22, 35.0559, 33.9793),
        (@LarnacaGeofenceID, 23, 35.0648, 34.0058), (@LarnacaGeofenceID, 24, 35.0331, 34.0456),
        (@LarnacaGeofenceID, 25, 35.0154, 34.0559), (@LarnacaGeofenceID, 26, 35.0128, 34.0659),
        (@LarnacaGeofenceID, 27, 34.9861, 34.0786), (@LarnacaGeofenceID, 28, 34.9839, 34.0748),
        (@LarnacaGeofenceID, 29, 34.9723, 34.0751), (@LarnacaGeofenceID, 30, 34.9583, 34.0875),
        (@LarnacaGeofenceID, 31, 34.9639, 34.0744), (@LarnacaGeofenceID, 32, 34.9583, 34.0652),
        (@LarnacaGeofenceID, 33, 34.9836, 34.0089), (@LarnacaGeofenceID, 34, 34.9850, 33.9618),
        (@LarnacaGeofenceID, 35, 34.9715, 33.9079), (@LarnacaGeofenceID, 36, 34.9507, 33.8860),
        (@LarnacaGeofenceID, 37, 34.9405, 33.8564), (@LarnacaGeofenceID, 38, 34.9732, 33.8036),
        (@LarnacaGeofenceID, 39, 34.9822, 33.7376), (@LarnacaGeofenceID, 40, 34.9749, 33.6903),
        (@LarnacaGeofenceID, 41, 34.9574, 33.6511), (@LarnacaGeofenceID, 42, 34.9299, 33.6415),
        (@LarnacaGeofenceID, 43, 34.8696, 33.6408), (@LarnacaGeofenceID, 44, 34.8437, 33.6134),
        (@LarnacaGeofenceID, 45, 34.8144, 33.6058), (@LarnacaGeofenceID, 46, 34.8228, 33.5722),
        (@LarnacaGeofenceID, 47, 34.8223, 33.5461), (@LarnacaGeofenceID, 48, 34.8059, 33.5337),
        (@LarnacaGeofenceID, 49, 34.7772, 33.4925), (@LarnacaGeofenceID, 50, 34.7729, 33.4575),
        (@LarnacaGeofenceID, 51, 34.7647, 33.4362), (@LarnacaGeofenceID, 52, 34.7505, 33.4056),
        (@LarnacaGeofenceID, 53, 34.7516, 33.3859), (@LarnacaGeofenceID, 54, 34.7767, 33.3638),
        (@LarnacaGeofenceID, 55, 34.8005, 33.3434), (@LarnacaGeofenceID, 56, 34.8501, 33.3517),
        (@LarnacaGeofenceID, 57, 34.9208, 33.3216);

    -- Bridge Locations
    INSERT INTO dbo.Location (LatDegrees, LonDegrees, Description, StreetAddress, PostalCode)
    VALUES (35.0034, 33.4499, 'Nicosia-Larnaca Highway Transfer Point', 'A3 Highway', '2540');
    DECLARE @Bridge1LocationID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.Location (LatDegrees, LonDegrees, Description, StreetAddress, PostalCode)
    VALUES (35.0100, 33.1500, 'Nicosia-Limassol Highway Transfer Point', 'A1 Highway', '2720');
    DECLARE @Bridge2LocationID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.Location (LatDegrees, LonDegrees, Description, StreetAddress, PostalCode)
    VALUES (34.8000, 33.3350, 'Limassol-Larnaca Transfer Point', 'A5 Highway', '7100');
    DECLARE @Bridge3LocationID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.Location (LatDegrees, LonDegrees, Description, StreetAddress, PostalCode)
    VALUES (34.8500, 32.7500, 'Limassol-Paphos Highway Transfer Point', 'A6 Highway', '8100');
    DECLARE @Bridge4LocationID INT = SCOPE_IDENTITY();
    
    INSERT INTO dbo.Location (LatDegrees, LonDegrees, Description, StreetAddress, PostalCode)
    VALUES (35.0350, 32.6400, 'Paphos-Nicosia Transfer Point', 'B6 Road', '8820');
    DECLARE @Bridge5LocationID INT = SCOPE_IDENTITY();
    
    -- Bridge Connections
    INSERT INTO dbo.GeofenceBridge (BridgeName, LocationID, Geofence1ID, Geofence2ID, IsActive)
    VALUES 
        ('Nicosia_Larnaca_Bridge', @Bridge1LocationID, @NicosiaGeofenceID, @LarnacaGeofenceID, 1),
        ('Limassol_Nicosia_Bridge', @Bridge2LocationID, @NicosiaGeofenceID, @LimassolGeofenceID, 1),
        ('Limassol_Larnaca_Bridge', @Bridge3LocationID, @LimassolGeofenceID, @LarnacaGeofenceID, 1),
        ('Paphos_Limassol_Bridge', @Bridge4LocationID, @LimassolGeofenceID, @PaphosGeofenceID, 1),
        ('Paphos_Nicosia_Bridge', @Bridge5LocationID, @PaphosGeofenceID, @NicosiaGeofenceID, 1);
END
GO

/* ============================================================
   G. AUTONOMOUS VEHICLES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.AutonomousVehicle WHERE VehicleCode = 'AV-PAF-001')
BEGIN
    -- Paphos District Vehicles (GeofenceID = 1)
    INSERT INTO dbo.[AutonomousVehicle] (
        VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color,
        SeatingCapacity, IsWheelchairReady, Status, GeofenceID,
        CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
        BatteryLevel, IsActive, CreatedAt, UpdatedAt
    )
    VALUES
        ('AV-PAF-001', 1, 'AV-001-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 1, 34.7780, 32.4290, SYSDATETIME(), 95, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-PAF-002', 1, 'AV-002-CY', 'Jaguar', 'I-PACE AV', 2024, 'Silver',
         4, 1, 'available', 1, 34.7850, 32.4350, SYSDATETIME(), 88, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-PAF-003', 1, 'AV-003-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 1, 34.7920, 32.4420, SYSDATETIME(), 92, 1, SYSDATETIME(), SYSDATETIME());

    -- Limassol District Vehicles (GeofenceID = 2)
    INSERT INTO dbo.[AutonomousVehicle] (
        VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color,
        SeatingCapacity, IsWheelchairReady, Status, GeofenceID,
        CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
        BatteryLevel, IsActive, CreatedAt, UpdatedAt
    )
    VALUES
        ('AV-LIM-001', 1, 'AV-004-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 2, 34.6950, 33.0350, SYSDATETIME(), 97, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-LIM-002', 1, 'AV-005-CY', 'Cruise', 'Origin', 2024, 'Blue',
         4, 1, 'available', 2, 34.7050, 33.0450, SYSDATETIME(), 85, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-LIM-003', 1, 'AV-006-CY', 'Jaguar', 'I-PACE AV', 2024, 'Black',
         4, 0, 'available', 2, 34.7150, 33.0250, SYSDATETIME(), 90, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-LIM-004', 1, 'AV-007-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 2, 34.7250, 33.0550, SYSDATETIME(), 78, 1, SYSDATETIME(), SYSDATETIME());

    -- Larnaca District Vehicles (GeofenceID = 4)
    INSERT INTO dbo.[AutonomousVehicle] (
        VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color,
        SeatingCapacity, IsWheelchairReady, Status, GeofenceID,
        CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
        BatteryLevel, IsActive, CreatedAt, UpdatedAt
    )
    VALUES
        ('AV-LAR-001', 1, 'AV-008-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 4, 34.9250, 33.6150, SYSDATETIME(), 94, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-LAR-002', 1, 'AV-009-CY', 'Cruise', 'Origin', 2024, 'Silver',
         4, 1, 'available', 4, 34.9350, 33.6050, SYSDATETIME(), 89, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-LAR-003', 1, 'AV-010-CY', 'Jaguar', 'I-PACE AV', 2024, 'Gray',
         4, 0, 'available', 4, 34.9150, 33.5950, SYSDATETIME(), 96, 1, SYSDATETIME(), SYSDATETIME());

    -- Nicosia District Vehicles (GeofenceID = 3)
    INSERT INTO dbo.[AutonomousVehicle] (
        VehicleCode, VehicleTypeID, PlateNo, Make, Model, Year, Color,
        SeatingCapacity, IsWheelchairReady, Status, GeofenceID,
        CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
        BatteryLevel, IsActive, CreatedAt, UpdatedAt
    )
    VALUES
        ('AV-NIC-001', 1, 'AV-011-CY', 'Waymo', 'One', 2024, 'White',
         4, 0, 'available', 3, 35.1580, 33.3550, SYSDATETIME(), 91, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-NIC-002', 1, 'AV-012-CY', 'Cruise', 'Origin', 2024, 'White',
         4, 1, 'available', 3, 35.1480, 33.3450, SYSDATETIME(), 87, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-NIC-003', 1, 'AV-013-CY', 'Jaguar', 'I-PACE AV', 2024, 'Black',
         4, 0, 'available', 3, 35.1520, 33.3650, SYSDATETIME(), 93, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-NIC-004', 1, 'AV-014-CY', 'Waymo', 'One', 2024, 'Silver',
         4, 0, 'available', 3, 35.1380, 33.3350, SYSDATETIME(), 82, 1, SYSDATETIME(), SYSDATETIME()),
        ('AV-NIC-005', 1, 'AV-015-CY', 'Cruise', 'Origin', 2024, 'Blue',
         4, 0, 'available', 3, 35.1280, 33.3750, SYSDATETIME(), 99, 1, SYSDATETIME(), SYSDATETIME());
END
GO

/* ============================================================
   H. CARSHARE VEHICLE TYPES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.CarshareVehicleType WHERE TypeCode = 'ECONOMY')
BEGIN
    SET IDENTITY_INSERT dbo.CarshareVehicleType ON;
    
    INSERT INTO dbo.CarshareVehicleType 
        (VehicleTypeID, TypeCode, TypeName, Description, SeatingCapacity, 
         HasAutomaticTrans, HasAirCon, CargoVolumeM3, IsElectric, IsHybrid,
         MinDriverAge, MinLicenseYears, PricePerMinute, PricePerHour, PricePerDay, PricePerKm,
         MinimumRentalFee, DepositAmount, IsActive)
    VALUES
        (1, 'ECONOMY', 'Economy', 'Small, fuel-efficient city cars', 4, 1, 1, 0.25, 0, 0, 18, 1, 0.19, 9.00, 45.00, 0.15, 2.50, 50.00, 1),
        (2, 'COMPACT', 'Compact', 'Comfortable compact cars for everyday use', 5, 1, 1, 0.35, 0, 0, 18, 1, 0.25, 12.00, 55.00, 0.18, 3.00, 75.00, 1),
        (3, 'PREMIUM', 'Premium', 'High-end vehicles with premium features', 5, 1, 1, 0.40, 0, 0, 21, 2, 0.45, 20.00, 95.00, 0.25, 5.00, 200.00, 1),
        (4, 'CABRIO', 'Convertible', 'Open-top driving experience', 2, 1, 1, 0.15, 0, 0, 21, 2, 0.55, 25.00, 110.00, 0.30, 6.00, 250.00, 1),
        (5, 'ELECTRIC', 'Electric', 'Zero-emission electric vehicles', 5, 1, 1, 0.35, 1, 0, 18, 1, 0.22, 10.00, 50.00, 0.10, 3.00, 100.00, 1),
        (6, 'HYBRID', 'Hybrid', 'Fuel-efficient hybrid vehicles', 5, 1, 1, 0.35, 0, 1, 18, 1, 0.23, 11.00, 52.00, 0.12, 3.00, 75.00, 1),
        (7, 'VAN', 'Van', 'Spacious vans for group travel', 9, 0, 1, 2.50, 0, 0, 23, 3, 0.35, 18.00, 85.00, 0.22, 5.00, 150.00, 1),
        (8, 'SUV', 'SUV', 'Sport utility vehicles', 5, 1, 1, 0.60, 0, 0, 21, 2, 0.40, 18.00, 80.00, 0.22, 4.50, 150.00, 1);
    
    SET IDENTITY_INSERT dbo.CarshareVehicleType OFF;
END
GO

/* ============================================================
   I. CARSHARE ZONES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.CarshareZone WHERE ZoneName = 'Nicosia Old Town')
BEGIN
    SET IDENTITY_INSERT dbo.CarshareZone ON;
    
    -- Nicosia Zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (1, 'Nicosia Old Town', 'standard', 'Central Nicosia near Ledra Street', 35.1725, 33.3617, 380, 'Nicosia', 'Central', 15, NULL, NULL, 1),
        (2, 'Nicosia Mall', 'standard', 'Near Nicosia Mall shopping center', 35.1521, 33.3750, 330, 'Nicosia', 'Strovolos', 20, NULL, NULL, 1),
        (3, 'University of Cyprus', 'standard', 'UCY Campus parking area', 35.1422, 33.4106, 380, 'Nicosia', 'Aglantzia', 25, NULL, 3.00, 1),
        (4, 'Nicosia Bus Station', 'intercity', 'Central bus station - intercity hub', 35.1698, 33.3578, 280, 'Nicosia', 'Central', 10, 5.00, NULL, 1),
        (5, 'Nicosia Industrial Area', 'pink', 'Bonus zone - help rebalance fleet', 35.1350, 33.3900, 480, 'Nicosia', 'Industrial', 8, NULL, 5.00, 1);

    -- Limassol Zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (6, 'Limassol Marina', 'premium', 'Premium zone at Limassol Marina', 34.6700, 33.0400, 320, 'Limassol', 'Marina', 12, 10.00, NULL, 1),
        (7, 'Limassol Old Town', 'standard', 'Historic center near castle', 34.6730, 33.0420, 380, 'Limassol', 'Central', 15, NULL, NULL, 1),
        (8, 'My Mall Limassol', 'standard', 'My Mall shopping center', 34.7058, 33.0228, 330, 'Limassol', 'Zakaki', 20, NULL, NULL, 1),
        (9, 'Limassol University', 'standard', 'Cyprus University of Technology', 34.6798, 33.0444, 380, 'Limassol', 'Central', 18, NULL, 2.00, 1),
        (10, 'Limassol Port', 'intercity', 'Near Limassol commercial port', 34.6650, 33.0500, 420, 'Limassol', 'Port', 10, 8.00, NULL, 1);

    -- Larnaca Zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (11, 'Larnaca Airport', 'airport', 'Larnaca International Airport', 34.8756, 33.6228, 480, 'Larnaca', 'Airport', 30, 15.00, NULL, 1),
        (12, 'Larnaca Promenade', 'standard', 'Finikoudes beach promenade area', 34.9119, 33.6353, 330, 'Larnaca', 'Central', 15, NULL, NULL, 1),
        (13, 'Larnaca Mall', 'standard', 'Near Metropolis Mall', 34.8970, 33.6150, 330, 'Larnaca', 'Livadia', 18, NULL, NULL, 1);

    -- Paphos Zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (14, 'Paphos Airport', 'airport', 'Paphos International Airport', 34.7180, 32.4856, 420, 'Paphos', 'Airport', 25, 15.00, NULL, 1),
        (15, 'Paphos Harbour', 'premium', 'Historic Paphos harbour area', 34.7530, 32.4067, 380, 'Paphos', 'Kato Paphos', 12, 8.00, NULL, 1),
        (16, 'Kings Avenue Mall', 'standard', 'Kings Avenue Mall Paphos', 34.7611, 32.4156, 330, 'Paphos', 'Central', 20, NULL, NULL, 1);

    -- Ayia Napa / Paralimni Zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (17, 'Ayia Napa Center', 'premium', 'Ayia Napa town center - tourist hub', 34.9878, 33.9994, 380, 'Ayia Napa', 'Central', 15, 12.00, NULL, 1),
        (18, 'Protaras Strip', 'standard', 'Protaras main tourist strip', 35.0125, 34.0575, 360, 'Paralimni', 'Protaras', 12, 10.00, NULL, 1);

    -- Additional city-centre zones
    INSERT INTO dbo.CarshareZone 
        (ZoneID, ZoneName, ZoneType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         City, District, MaxCapacity, InterCityFee, BonusAmount, IsActive)
    VALUES
        (19, 'Nicosia Eleftheria Square', 'standard', 'Drop-off area around Eleftheria Square', 35.1720, 33.3645, 420, 'Nicosia', 'Old Town', 18, NULL, 2.00, 1),
        (20, 'Limassol Saripolou Hub', 'standard', 'Central Limassol drop-off', 34.6755, 33.0440, 400, 'Limassol', 'Old Town', 16, NULL, 2.00, 1),
        (21, 'Larnaca Europe Square', 'standard', 'City-centre around Europe Square', 34.9168, 33.6360, 360, 'Larnaca', 'Europe Square', 14, NULL, 1.50, 1),
        (22, 'Paphos Town Hall', 'standard', 'Historic centre by town hall', 34.7723, 32.4295, 360, 'Paphos', 'Old Town', 12, NULL, 1.00, 1),
        (23, 'Ayia Napa Harbour North', 'premium', 'Extended harbour zone', 34.9945, 34.0007, 360, 'Ayia Napa', 'Harbour', 12, 12.00, 2.50, 1);
    
    SET IDENTITY_INSERT dbo.CarshareZone OFF;
END
GO

/* ============================================================
   J. CARSHARE VEHICLES
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.CarshareVehicle WHERE PlateNumber = 'LCA-101')
BEGIN
    SET IDENTITY_INSERT dbo.CarshareVehicle ON;
    
    -- Economy vehicles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (1, 1, 'LCA-101', 'JTDKN3DU5A0000001', 'Toyota', 'Yaris', 2022, 'White', 'available', 11, 34.8760, 33.6230, SYSDATETIME(), 85, 15420, 5, 1, 1, 1, 1),
        (2, 1, 'LCA-102', 'KNAFK4A63E5000002', 'Kia', 'Picanto', 2023, 'Red', 'available', 11, 34.8755, 33.6225, SYSDATETIME(), 92, 8500, 5, 1, 1, 1, 1),
        (3, 1, 'NIC-101', 'JTDKN3DU5A0000003', 'Toyota', 'Yaris', 2021, 'Silver', 'available', 1, 35.1728, 33.3620, SYSDATETIME(), 78, 22340, 4, 1, 1, 1, 1),
        (4, 1, 'NIC-102', 'KMHDN45D92U000004', 'Hyundai', 'i10', 2023, 'Blue', 'available', 2, 35.1525, 33.3755, SYSDATETIME(), 95, 5200, 5, 1, 1, 1, 1),
        (5, 1, 'LIM-101', 'ZFAFJX1B4LP000005', 'Fiat', '500', 2022, 'Yellow', 'available', 7, 34.6732, 33.0425, SYSDATETIME(), 88, 12100, 4, 1, 1, 1, 1);

    -- Compact vehicles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (6, 2, 'LCA-201', 'WVWZZZ1KZAW000006', 'Volkswagen', 'Golf', 2022, 'Black', 'available', 11, 34.8758, 33.6232, SYSDATETIME(), 90, 18500, 5, 1, 1, 1, 1),
        (7, 2, 'NIC-201', 'JTDBR32E0D0000007', 'Toyota', 'Corolla', 2023, 'White', 'available', 3, 35.1425, 33.4110, SYSDATETIME(), 82, 9800, 5, 1, 1, 1, 1),
        (8, 2, 'NIC-202', '2HGFC2F59MH000008', 'Honda', 'Civic', 2022, 'Gray', 'available', 1, 35.1722, 33.3615, SYSDATETIME(), 75, 21000, 4, 1, 1, 1, 1),
        (9, 2, 'LIM-201', 'JM1BL1SF9D1000009', 'Mazda', '3', 2023, 'Red', 'available', 6, 34.6702, 33.0405, SYSDATETIME(), 88, 7500, 5, 1, 1, 1, 1),
        (10, 2, 'PAF-201', '1FAHP3F21CL000010', 'Ford', 'Focus', 2022, 'Blue', 'available', 14, 34.7182, 32.4858, SYSDATETIME(), 95, 14200, 5, 1, 1, 1, 1);

    -- Premium vehicles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (11, 3, 'LIM-301', 'WBA8E9G50HNU00011', 'BMW', '3 Series', 2023, 'Black', 'available', 6, 34.6705, 33.0408, SYSDATETIME(), 85, 8200, 5, 1, 1, 1, 1),
        (12, 3, 'NIC-301', 'WDDWF8EB5KA000012', 'Mercedes', 'C-Class', 2022, 'Silver', 'available', 2, 35.1520, 33.3748, SYSDATETIME(), 78, 15800, 5, 1, 1, 1, 1),
        (13, 3, 'PAF-301', 'WAUFFAFL0HN000013', 'Audi', 'A4', 2023, 'White', 'available', 15, 34.7532, 32.4070, SYSDATETIME(), 92, 6500, 5, 1, 1, 1, 1);

    -- Convertibles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (14, 4, 'AYN-401', 'WBA4Z9C58JB000014', 'BMW', '4 Cabrio', 2023, 'White', 'available', 17, 34.9880, 33.9996, SYSDATETIME(), 88, 4500, 5, 1, 1, 1, 1),
        (15, 4, 'LIM-401', 'WMWWG9C57L2000015', 'Mini', 'Convertible', 2022, 'Red', 'available', 6, 34.6698, 33.0402, SYSDATETIME(), 80, 11200, 5, 1, 1, 1, 1);

    -- Electric vehicles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, BatteryLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (16, 5, 'NIC-501', '1N4AZ1CP0NC000016', 'Nissan', 'Leaf', 2023, 'Blue', 'available', 1, 35.1726, 33.3618, SYSDATETIME(), 100, 85, 8200, 5, 1, 1, 1, 1),
        (17, 5, 'LCA-501', '5YJ3E1EA5NF000017', 'Tesla', 'Model 3', 2023, 'White', 'available', 11, 34.8752, 33.6228, SYSDATETIME(), 100, 92, 5800, 5, 1, 1, 1, 1),
        (18, 5, 'LIM-501', 'WVWZZZCDZMW000018', 'Volkswagen', 'ID.4', 2023, 'Gray', 'available', 7, 34.6735, 33.0428, SYSDATETIME(), 100, 78, 12500, 5, 1, 1, 1, 1);

    -- Hybrid vehicles
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (19, 6, 'NIC-601', 'JTDKN3DU5L3000019', 'Toyota', 'Prius', 2023, 'Silver', 'available', 3, 35.1428, 33.4108, SYSDATETIME(), 88, 9500, 5, 1, 1, 1, 1),
        (20, 6, 'LCA-601', '19XZE4F95KE000020', 'Honda', 'Insight', 2022, 'Black', 'available', 12, 34.9122, 33.6355, SYSDATETIME(), 82, 14800, 4, 1, 1, 1, 1);

    -- Vans
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (21, 7, 'NIC-701', 'WDF44760313000021', 'Mercedes', 'Vito', 2022, 'White', 'available', 4, 35.1700, 33.3580, SYSDATETIME(), 75, 28500, 4, 1, 1, 1, 1),
        (22, 7, 'LIM-701', 'WV1ZZZ7HZMH000022', 'Volkswagen', 'Transporter', 2021, 'Gray', 'available', 10, 34.6655, 33.0505, SYSDATETIME(), 68, 35200, 4, 1, 1, 1, 1);

    -- SUVs
    INSERT INTO dbo.CarshareVehicle 
        (VehicleID, VehicleTypeID, PlateNumber, VIN, Make, Model, Year, Color, 
         Status, CurrentZoneID, CurrentLatitude, CurrentLongitude, LocationUpdatedAt,
         FuelLevelPercent, OdometerKm, CleanlinessRating, HasGPS, HasBluetooth, HasUSBCharger, IsActive)
    VALUES
        (23, 8, 'LCA-801', 'JTMRJREV2ND000023', 'Toyota', 'RAV4', 2023, 'Green', 'available', 11, 34.8754, 33.6226, SYSDATETIME(), 90, 7800, 5, 1, 1, 1, 1),
        (24, 8, 'PAF-801', 'JN8AT2MV2NW000024', 'Nissan', 'X-Trail', 2022, 'Black', 'available', 15, 34.7528, 32.4065, SYSDATETIME(), 85, 16200, 5, 1, 1, 1, 1),
        (25, 8, 'AYN-801', 'ZACNJDAB4NP000025', 'Jeep', 'Compass', 2023, 'White', 'available', 17, 34.9882, 33.9998, SYSDATETIME(), 92, 5500, 5, 1, 1, 1, 1);
    
    SET IDENTITY_INSERT dbo.CarshareVehicle OFF;
END
GO

-- Update zone vehicle counts
UPDATE z
SET z.CurrentVehicleCount = (
    SELECT COUNT(*) 
    FROM dbo.CarshareVehicle v 
    WHERE v.CurrentZoneID = z.ZoneID AND v.Status = 'available' AND v.IsActive = 1
)
FROM dbo.CarshareZone z;
GO

/* ============================================================
   K. CARSHARE OPERATING AREAS
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.CarshareOperatingArea WHERE AreaName = 'Cyprus Operating Zone')
BEGIN
    SET IDENTITY_INSERT dbo.CarshareOperatingArea ON;
    
    INSERT INTO dbo.CarshareOperatingArea 
        (AreaID, AreaName, AreaType, Description, CenterLatitude, CenterLongitude, RadiusMeters,
         UsePolygon, WarningDistanceM, PenaltyPerMinute, MaxPenalty, DisableEngineOutside, IsActive)
    VALUES
        (1, 'Cyprus Operating Zone', 'operating', 
         'Main operating area - vehicles must stay within this boundary',
         35.0000, 33.4000, 100000, 1, 1000, 0.50, 50.00, 0, 1);
    
    SET IDENTITY_INSERT dbo.CarshareOperatingArea OFF;
    
    -- Polygon for Cyprus operating boundary (simplified)
    INSERT INTO dbo.CarshareOperatingAreaPolygon (AreaID, SequenceNo, LatDegrees, LonDegrees)
    VALUES
        (1, 1, 35.1850, 32.2700),   -- Kato Pyrgos (NW)
        (1, 2, 35.3500, 32.9000),   -- North coast
        (1, 3, 35.3000, 33.5000),   -- Northern limit
        (1, 4, 35.1200, 33.9500),   -- Eastern limit
        (1, 5, 35.0800, 34.1000),   -- Paralimni area
        (1, 6, 34.9500, 34.0800),   -- Ayia Napa
        (1, 7, 34.8800, 33.7500),   -- Larnaca coast
        (1, 8, 34.6500, 33.2000),   -- Limassol
        (1, 9, 34.6200, 32.9000),   -- Limassol west
        (1, 10, 34.7200, 32.4000),  -- Paphos
        (1, 11, 34.8500, 32.2500),  -- Paphos north
        (1, 12, 35.0500, 32.3500),  -- Polis area
        (1, 13, 35.1850, 32.2700);  -- Back to start
END
GO

/* ============================================================
   SEEDING COMPLETE
   ============================================================ */

PRINT '';
PRINT '============================================================';
PRINT 'OSRH SEEDING COMPLETED SUCCESSFULLY';
PRINT '============================================================';
PRINT 'Summary:';
PRINT '  - Payment Methods: 2 (Cash, Kaspa)';
PRINT '  - Service Types: 5';
PRINT '  - Vehicle Types: 16';
PRINT '  - Service Type Requirements: 5';
PRINT '  - VehicleType-ServiceType Mappings: ~30';
PRINT '  - Geofences: 4 (Paphos, Limassol, Nicosia, Larnaca)';
PRINT '  - Geofence Bridges: 5';
PRINT '  - Autonomous Vehicles: 15';
PRINT '  - CarShare Vehicle Types: 8';
PRINT '  - CarShare Zones: 23';
PRINT '  - CarShare Vehicles: 25';
PRINT '  - CarShare Operating Areas: 1';
PRINT '============================================================';
PRINT '';
PRINT 'NOTE: Simulated drivers seeding (500+ drivers) is in a';
PRINT 'separate file: simulated_drivers_seeding.sql';
PRINT 'Run it separately due to its size and execution time.';
PRINT '============================================================';
GO
