
/* ============================================================
   BASE LOOKUP TABLES
   ============================================================ */


CREATE TABLE dbo.[VehicleType] (
    VehicleTypeID      INT IDENTITY(1,1) PRIMARY KEY,
    Name               NVARCHAR(100)  NOT NULL,
    Description        NVARCHAR(500)  NULL,
    MaxPassengers      INT            NULL,
    IsWheelchairReady  BIT            NOT NULL DEFAULT 0
);

CREATE TABLE dbo.[PaymentMethodType] (
    PaymentMethodTypeID INT IDENTITY(1,1) PRIMARY KEY,
    Code                NVARCHAR(50)  NOT NULL UNIQUE,  -- e.g. 'CARD','CASH','WALLET'
    Description         NVARCHAR(200) NULL
);

CREATE TABLE dbo.[Currency] (
    CurrencyCode  CHAR(3)      PRIMARY KEY,             -- e.g. 'EUR'
    Name          NVARCHAR(50) NOT NULL
);

CREATE TABLE dbo.[ServiceType] (
    ServiceTypeID  INT IDENTITY(1,1) PRIMARY KEY,
    Code           NVARCHAR(50)  NOT NULL UNIQUE,  -- e.g. 'STANDARD','LUXURY','LIGHT_CARGO','HEAVY_CARGO','MULTI_STOP'
    Name           NVARCHAR(100) NOT NULL,
    Description    NVARCHAR(500) NULL,
    IsActive       BIT           NOT NULL DEFAULT 1
);

CREATE TABLE dbo.[VehicleType_ServiceType] (
    VehicleTypeID INT NOT NULL,
    ServiceTypeID INT NOT NULL,
    PRIMARY KEY (VehicleTypeID, ServiceTypeID),
    CONSTRAINT FK_VehicleType_ServiceType_VehicleType
        FOREIGN KEY (VehicleTypeID) REFERENCES dbo.[VehicleType](VehicleTypeID),
    CONSTRAINT FK_VehicleType_ServiceType_ServiceType
        FOREIGN KEY (ServiceTypeID) REFERENCES dbo.[ServiceType](ServiceTypeID)
);

/* ============================================================
   VEHICLE & DRIVER REQUIREMENTS (per Service Type)
   ============================================================ */

-- Requirements for vehicles based on service type
-- These are displayed during driver registration for information
CREATE TABLE dbo.[ServiceTypeRequirements] (
    RequirementID       INT IDENTITY(1,1) PRIMARY KEY,
    ServiceTypeID       INT           NOT NULL,
    -- Vehicle requirements
    MinVehicleYear      INT           NULL,             -- Minimum vehicle year (e.g., 2015)
    MaxVehicleAge       INT           NULL,             -- Maximum vehicle age in years
    MinDoors            INT           NULL DEFAULT 4,   -- Minimum number of doors
    RequirePassengerSeat BIT          NOT NULL DEFAULT 1,
    MinSeatingCapacity  INT           NULL,
    MaxWeightCapacityKg INT           NULL,             -- For cargo services
    -- Driver requirements
    MinDriverAge        INT           NOT NULL DEFAULT 18,
    RequireCriminalRecord BIT         NOT NULL DEFAULT 1,  -- Criminal record certificate
    RequireMedicalCert   BIT          NOT NULL DEFAULT 1,  -- Medical certificate
    -- Document requirements
    RequireInsurance    BIT           NOT NULL DEFAULT 1,
    RequireMOT          BIT           NOT NULL DEFAULT 1,  -- Technical inspection
    -- Description shown to drivers
    RequirementsDescription NVARCHAR(MAX) NULL,
    IsActive            BIT           NOT NULL DEFAULT 1,
    CONSTRAINT FK_ServiceTypeReq_ServiceType
        FOREIGN KEY (ServiceTypeID) REFERENCES dbo.[ServiceType](ServiceTypeID),
    CONSTRAINT UQ_ServiceTypeReq_ServiceType
        UNIQUE (ServiceTypeID)
);
GO

/* ============================================================
   USERS & ROLES
   ============================================================ */

CREATE TABLE dbo.[User] (
    UserID       INT IDENTITY(1,1) PRIMARY KEY,
    Email        NVARCHAR(255) NOT NULL UNIQUE,
    Phone        NVARCHAR(30)  NULL,
    FullName     NVARCHAR(200) NOT NULL,
    DateOfBirth  DATE          NULL,                   -- For age verification (18+)
    PhotoUrl     NVARCHAR(500) NULL,                   -- Driver photo for identification
    -- Address fields
    StreetAddress NVARCHAR(255) NULL,
    City          NVARCHAR(100) NULL,
    PostalCode    NVARCHAR(20)  NULL,
    Country       NVARCHAR(100) NULL DEFAULT 'Cyprus',
    -- User preferences (GDPR compliant)
    PrefLocationTracking BIT NOT NULL DEFAULT 1,       -- Allow location tracking
    PrefNotifications    BIT NOT NULL DEFAULT 1,       -- Allow push notifications
    PrefEmailUpdates     BIT NOT NULL DEFAULT 1,       -- Allow email updates
    PrefDataSharing      BIT NOT NULL DEFAULT 0,       -- Allow data sharing with partners
    CreatedAt    DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    Status       NVARCHAR(30)  NOT NULL DEFAULT 'active'  -- active, blocked, deleted_gdpr
);

CREATE TABLE dbo.[PasswordHistory] (
    PasswordID   INT IDENTITY(1,1) PRIMARY KEY,
    UserID       INT             NOT NULL,
    PasswordHash VARBINARY(256)  NOT NULL,
    PasswordSalt VARBINARY(128)  NULL,
    CreatedAt    DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    IsCurrent    BIT             NOT NULL DEFAULT 1,
    CONSTRAINT FK_PasswordHistory_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID)
);

CREATE TABLE dbo.[Passenger] (
    PassengerID  INT IDENTITY(1,1) PRIMARY KEY,
    UserID       INT NOT NULL UNIQUE,
    LoyaltyLevel NVARCHAR(50) NULL,
    CONSTRAINT FK_Passenger_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID)
);

CREATE TABLE dbo.[Driver] (
    DriverID           INT IDENTITY(1,1) PRIMARY KEY,
    UserID             INT          NOT NULL UNIQUE,
    DriverType         NVARCHAR(50) NOT NULL,          -- e.g. 'employee','partner'
    IsAvailable        BIT          NOT NULL DEFAULT 0,
    VerificationStatus NVARCHAR(50) NOT NULL DEFAULT 'pending',
    RatingAverage      DECIMAL(3,2) NULL,
    CreatedAt          DATETIME2    NOT NULL DEFAULT SYSDATETIME(),
    -- GPS and location tracking columns
    UseGPS             BIT          NOT NULL DEFAULT 0,  -- 0=Simulated, 1=Real GPS
    CurrentLatitude    DECIMAL(9,6) NULL,
    CurrentLongitude   DECIMAL(9,6) NULL,
    LocationUpdatedAt  DATETIME2    NULL,
    CONSTRAINT FK_Driver_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID)
);

CREATE TABLE dbo.[Operator] (
    OperatorID INT IDENTITY(1,1) PRIMARY KEY,
    UserID     INT NOT NULL UNIQUE,
    Role       NVARCHAR(50) NOT NULL,                 -- dispatcher, supervisor, etc.
    CONSTRAINT FK_Operator_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID)
);

/* ============================================================
   DOCUMENTS & SAFETY
   ============================================================ */

CREATE TABLE dbo.[DriverDocument] (
    DriverDocumentID INT IDENTITY(1,1) PRIMARY KEY,
    DriverID         INT          NOT NULL,
    DocType          NVARCHAR(50) NOT NULL,  -- id_card, residence_permit, license, criminal_record, medical_cert, psych_cert
    IdNumber         NVARCHAR(100) NULL,
    IssueDate        DATE         NULL,
    ExpiryDate       DATE         NULL,
    Status           NVARCHAR(30) NOT NULL DEFAULT 'valid',
    StorageUrl       NVARCHAR(MAX) NULL,     -- JSON array for multiple file URLs
    CONSTRAINT FK_DriverDocument_Driver
        FOREIGN KEY (DriverID) REFERENCES dbo.[Driver](DriverID)
);

CREATE TABLE dbo.[Vehicle] (
    VehicleID       INT IDENTITY(1,1) PRIMARY KEY,
    DriverID        INT          NOT NULL,
    VehicleTypeID   INT          NOT NULL,
    PlateNo         NVARCHAR(20) NOT NULL UNIQUE,
    Make            NVARCHAR(100) NULL,
    Model           NVARCHAR(100) NULL,
    Year            SMALLINT      NULL,
    Color           NVARCHAR(50)  NULL,
    NumberOfDoors   INT           NULL DEFAULT 4,      -- OSRH requires 4-door vehicles
    SeatingCapacity INT           NULL,
    HasPassengerSeat BIT          NOT NULL DEFAULT 1,  -- Must have front passenger seat
    MaxWeightKg     INT           NULL,
    CargoVolume     DECIMAL(10,2) NULL,                -- Cargo volume in m³ for cargo/delivery vehicles
    PhotosExterior  NVARCHAR(MAX) NULL,                -- JSON array of exterior photo URLs
    PhotosInterior  NVARCHAR(MAX) NULL,                -- JSON array of interior photo URLs
    IsActive        BIT           NOT NULL DEFAULT 1,
    CONSTRAINT FK_Vehicle_Driver
        FOREIGN KEY (DriverID)      REFERENCES dbo.[Driver](DriverID),
    CONSTRAINT FK_Vehicle_VehicleType
        FOREIGN KEY (VehicleTypeID) REFERENCES dbo.[VehicleType](VehicleTypeID),
    CONSTRAINT CHK_Vehicle_Doors
        CHECK (NumberOfDoors >= 2)
);

CREATE TABLE dbo.[VehicleDocument] (
    VehicleDocumentID INT IDENTITY(1,1) PRIMARY KEY,
    VehicleID         INT          NOT NULL,
    DocType           NVARCHAR(50) NOT NULL,  -- registration, MOT, insurance, classification_cert
    IdNumber          NVARCHAR(100) NULL,
    IssueDate         DATE         NULL,
    ExpiryDate        DATE         NULL,
    Status            NVARCHAR(30) NOT NULL DEFAULT 'valid',
    StorageUrl        NVARCHAR(MAX) NULL,     -- JSON array for multiple file URLs
    CONSTRAINT FK_VehicleDocument_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[Vehicle](VehicleID)
);

CREATE TABLE dbo.[SafetyInspection] (
    SafetyInspectionID INT IDENTITY(1,1) PRIMARY KEY,
    VehicleID          INT          NOT NULL,
    InspectionDate     DATE         NOT NULL,
    InspectorName      NVARCHAR(200) NULL,
    InspectionType     NVARCHAR(50)  NULL,
    Result             NVARCHAR(30)  NOT NULL,       -- pass/fail
    Notes              NVARCHAR(MAX) NULL,
    CONSTRAINT FK_SafetyInspection_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[Vehicle](VehicleID)
);

/* ============================================================
   LOCATION & GEOFENCING
   ============================================================ */

CREATE TABLE dbo.[Location] (
    LocationID       INT IDENTITY(1,1) PRIMARY KEY,
    Description      NVARCHAR(255) NULL,
    StreetAddress    NVARCHAR(255) NULL,
    PostalCode       NVARCHAR(20)  NULL,
    LatDegrees       DECIMAL(9,6)  NOT NULL,
    LonDegrees       DECIMAL(9,6)  NOT NULL
);

CREATE TABLE dbo.[Geofence] (
    GeofenceID   INT IDENTITY(1,1) PRIMARY KEY,
    Name         NVARCHAR(100) NOT NULL UNIQUE,
    Description  NVARCHAR(500) NULL,
    IsActive     BIT           NOT NULL DEFAULT 1,
    RadiusMeters INT           NULL         -- if circular, otherwise NULL
);

CREATE TABLE dbo.[GeofencePoint] (
    GeofencePointID INT IDENTITY(1,1) PRIMARY KEY,
    GeofenceID      INT          NOT NULL,
    SequenceNo      INT          NOT NULL,
    LatDegrees      DECIMAL(9,6) NOT NULL,
    LonDegrees      DECIMAL(9,6) NOT NULL,
    CONSTRAINT FK_GeofencePoint_Geofence
        FOREIGN KEY (GeofenceID) REFERENCES dbo.[Geofence](GeofenceID),
    CONSTRAINT UQ_GeofencePoint_Geofence_Seq
        UNIQUE (GeofenceID, SequenceNo)
);

CREATE TABLE dbo.[GeofenceLog] (
    GeofenceLogID INT IDENTITY(1,1) PRIMARY KEY,
    GeofenceID    INT       NOT NULL,
    TripID        INT       NULL,
    VehicleID     INT       NULL,
    EnteredAt     DATETIME2 NULL,
    ExitedAt      DATETIME2 NULL,
    EventType     NVARCHAR(30) NOT NULL,      -- enter / exit / violation etc.
    CONSTRAINT FK_GeofenceLog_Geofence
        FOREIGN KEY (GeofenceID) REFERENCES dbo.[Geofence](GeofenceID)
);

/* ============================================================
   RIDE REQUESTS & TRIPS
   ============================================================ */

CREATE TABLE dbo.[RideRequest] (
    RideRequestID     INT IDENTITY(1,1) PRIMARY KEY,
    PassengerID       INT       NOT NULL,
    ServiceTypeID     INT       NOT NULL DEFAULT 1,  -- Type of service requested
    RequestedAt       DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    PickupLocationID  INT       NOT NULL,
    DropoffLocationID INT       NOT NULL,
    Status            NVARCHAR(30) NOT NULL DEFAULT 'pending', 
        -- pending, accepted, cancelled, completed, expired
    PassengerNotes    NVARCHAR(500) NULL,
    LuggageVolume     DECIMAL(10,2) NULL,
    WheelchairNeeded  BIT          NOT NULL DEFAULT 0,
    PaymentMethodTypeID INT       NOT NULL DEFAULT 1,  -- Payment method: Card or Cash
    EstimatedDistanceKm DECIMAL(10,3) NULL,  -- OSRM route distance
    EstimatedDurationMin INT NULL,  -- OSRM route duration
    EstimatedFare     DECIMAL(10,2) NULL,  -- Estimated total fare
    RealDriversOnly   BIT          NOT NULL DEFAULT 0,  -- If 1, only real GPS drivers can accept
    CONSTRAINT FK_RideRequest_Passenger
        FOREIGN KEY (PassengerID) REFERENCES dbo.[Passenger](PassengerID),
    CONSTRAINT FK_RideRequest_ServiceType
        FOREIGN KEY (ServiceTypeID) REFERENCES dbo.[ServiceType](ServiceTypeID),
    CONSTRAINT FK_RideRequest_PickupLocation
        FOREIGN KEY (PickupLocationID)  REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_RideRequest_DropoffLocation
        FOREIGN KEY (DropoffLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_RideRequest_PaymentMethodType
        FOREIGN KEY (PaymentMethodTypeID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID)
);

CREATE TABLE dbo.[Trip] (
    TripID           INT IDENTITY(1,1) PRIMARY KEY,
    RideRequestID    INT          NOT NULL,
    DriverID         INT          NOT NULL,
    VehicleID        INT          NOT NULL,
    DispatchTime     DATETIME2    NOT NULL DEFAULT SYSDATETIME(),
    StartTime        DATETIME2    NULL,
    EndTime          DATETIME2    NULL,
    TotalDistanceKm  DECIMAL(10,3) NULL,
    TotalDurationSec INT           NULL,
    Status           NVARCHAR(30)  NOT NULL DEFAULT 'assigned', 
        -- assigned, in_progress, completed, cancelled
    FromGeofenceID   INT           NULL,
    ToGeofenceID     INT           NULL,
    -- Real driver trip tracking columns
    IsRealDriverTrip BIT          NOT NULL DEFAULT 0,  -- 1 if accepted by real GPS driver
    ActualCost       DECIMAL(10,2) NULL,  -- Final fare for the trip
    DriverPayout     DECIMAL(10,2) NULL,  -- Driver earnings (80%)
    PlatformFee      DECIMAL(10,2) NULL,  -- Platform commission (20%)
    -- Simulation tracking columns
    DriverStartLat   DECIMAL(9,6)  NULL,  -- Driver's location when assigned
    DriverStartLng   DECIMAL(9,6)  NULL,
    EstimatedPickupTime DATETIME2 NULL,   -- ETA at pickup
    SimulationStartTime DATETIME2 NULL,   -- When simulation started
    PickupRouteGeometry NVARCHAR(MAX) NULL,  -- OSRM route from driver to pickup
    -- Pickup simulation speed control
    PickupSpeedMultiplier DECIMAL(4,2) NULL DEFAULT 1.00,  -- Speed multiplier for pickup simulation
    AccumulatedPickupSeconds FLOAT NULL DEFAULT 0,  -- Accumulated simulated time for pickup speed change
    LastPickupSpeedChangeAt DATETIME2 NULL,  -- When pickup speed was last changed
    -- Trip simulation columns
    TripStartedAt    DATETIME2     NULL,  -- When actual trip started (for simulation)
    EstimatedTripDurationSec INT  NULL,   -- Estimated trip duration in seconds
    RouteGeometry    NVARCHAR(MAX) NULL,  -- Encoded polyline or GeoJSON for route
    TripSimulationSpeedMultiplier DECIMAL(4,2) NULL DEFAULT 1.00,  -- Speed multiplier for simulation
    AccumulatedSimulatedSeconds FLOAT NULL DEFAULT 0,  -- Accumulated simulated time for speed change handling
    LastSpeedChangeAt DATETIME2    NULL,  -- When speed was last changed
    CONSTRAINT FK_Trip_RideRequest
        FOREIGN KEY (RideRequestID) REFERENCES dbo.[RideRequest](RideRequestID),
    CONSTRAINT FK_Trip_Driver
        FOREIGN KEY (DriverID)      REFERENCES dbo.[Driver](DriverID),
    CONSTRAINT FK_Trip_Vehicle
        FOREIGN KEY (VehicleID)     REFERENCES dbo.[Vehicle](VehicleID),
    CONSTRAINT FK_Trip_FromGeofence
        FOREIGN KEY (FromGeofenceID) REFERENCES dbo.[Geofence](GeofenceID),
    CONSTRAINT FK_Trip_ToGeofence
        FOREIGN KEY (ToGeofenceID)   REFERENCES dbo.[Geofence](GeofenceID)
);

CREATE TABLE dbo.[TripLeg] (
    TripLegID       INT IDENTITY(1,1) PRIMARY KEY,
    TripID          INT          NOT NULL,
    SequenceNo      INT          NOT NULL,
    FromLocationID  INT          NOT NULL,
    ToLocationID    INT          NOT NULL,
    LegDistanceKm   DECIMAL(10,3) NULL,
    LegDurationSec  INT           NULL,
    CONSTRAINT FK_TripLeg_Trip
        FOREIGN KEY (TripID) REFERENCES dbo.[Trip](TripID) ON DELETE CASCADE,
    CONSTRAINT FK_TripLeg_FromLocation
        FOREIGN KEY (FromLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_TripLeg_ToLocation
        FOREIGN KEY (ToLocationID)   REFERENCES dbo.[Location](LocationID),
    CONSTRAINT UQ_TripLeg_Trip_Seq
        UNIQUE (TripID, SequenceNo)
);

/* ============================================================
   DISPATCH / OPERATIONS
   ============================================================ */

CREATE TABLE dbo.[DispatchLog] (
    DispatchLogID INT IDENTITY(1,1) PRIMARY KEY,
    RideRequestID INT       NOT NULL,
    DriverID      INT       NOT NULL,
    OperatorID    INT       NULL,
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    Status        NVARCHAR(30) NOT NULL,   -- offered, accepted, rejected, expired
    ChangeReason  NVARCHAR(500) NULL,
    CONSTRAINT FK_DispatchLog_RideRequest
        FOREIGN KEY (RideRequestID) REFERENCES dbo.[RideRequest](RideRequestID),
    CONSTRAINT FK_DispatchLog_Driver
        FOREIGN KEY (DriverID)      REFERENCES dbo.[Driver](DriverID),
    CONSTRAINT FK_DispatchLog_Operator
        FOREIGN KEY (OperatorID)    REFERENCES dbo.[Operator](OperatorID)
);

CREATE TABLE dbo.[SystemLog] (
    SystemLogID   INT IDENTITY(1,1) PRIMARY KEY,
    CreatedAt     DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UserID        INT       NULL,
    TripID        INT       NULL,
    Severity      NVARCHAR(20) NOT NULL,  -- info, warn, error
    Category      NVARCHAR(50) NOT NULL,
    Message       NVARCHAR(MAX) NOT NULL,
    CONSTRAINT FK_SystemLog_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT FK_SystemLog_Trip
        FOREIGN KEY (TripID) REFERENCES dbo.[Trip](TripID)
);

/* ============================================================
   GEOFENCE BRIDGE TABLES (Multi-Vehicle Journey Support)
   ============================================================ */

-- Bridge/connection points between geofences
-- These are physical locations where passengers can transfer between vehicles
CREATE TABLE dbo.[GeofenceBridge] (
    BridgeID        INT IDENTITY(1,1) PRIMARY KEY,
    BridgeName      NVARCHAR(100) NOT NULL UNIQUE,
    LocationID      INT NOT NULL,           -- Physical location of the bridge
    Geofence1ID     INT NOT NULL,           -- First geofence connected
    Geofence2ID     INT NOT NULL,           -- Second geofence connected
    IsActive        BIT NOT NULL DEFAULT 1,
    CreatedAt       DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_GeofenceBridge_Location 
        FOREIGN KEY (LocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_GeofenceBridge_Geofence1 
        FOREIGN KEY (Geofence1ID) REFERENCES dbo.[Geofence](GeofenceID),
    CONSTRAINT FK_GeofenceBridge_Geofence2 
        FOREIGN KEY (Geofence2ID) REFERENCES dbo.[Geofence](GeofenceID),
    CONSTRAINT CHK_GeofenceBridge_DifferentGeofences
        CHECK (Geofence1ID <> Geofence2ID)
);

-- Segments of a multi-vehicle journey
-- Each segment represents one leg of the trip with potentially different vehicles
CREATE TABLE dbo.[RideSegment] (
    SegmentID       INT IDENTITY(1,1) PRIMARY KEY,
    RideRequestID   INT NOT NULL,
    SegmentOrder    INT NOT NULL,           -- Order of this segment in the journey (1, 2, 3...)
    FromLocationID  INT NOT NULL,           -- Starting point of this segment
    ToLocationID    INT NOT NULL,           -- Ending point of this segment
    FromBridgeID    INT NULL,               -- Bridge at start (NULL for first segment)
    ToBridgeID      INT NULL,               -- Bridge at end (NULL for last segment)
    TripID          INT NULL,               -- Actual trip assigned to this segment
    GeofenceID      INT NULL,               -- Geofence this segment operates in
    EstimatedDistanceKm  DECIMAL(8,2) NULL,
    EstimatedDurationMin INT NULL,
    EstimatedFare   DECIMAL(10,2) NULL,
    CreatedAt       DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_RideSegment_RideRequest 
        FOREIGN KEY (RideRequestID) REFERENCES dbo.[RideRequest](RideRequestID),
    CONSTRAINT FK_RideSegment_FromLocation 
        FOREIGN KEY (FromLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_RideSegment_ToLocation 
        FOREIGN KEY (ToLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_RideSegment_FromBridge 
        FOREIGN KEY (FromBridgeID) REFERENCES dbo.[GeofenceBridge](BridgeID),
    CONSTRAINT FK_RideSegment_ToBridge 
        FOREIGN KEY (ToBridgeID) REFERENCES dbo.[GeofenceBridge](BridgeID),
    CONSTRAINT FK_RideSegment_Trip 
        FOREIGN KEY (TripID) REFERENCES dbo.[Trip](TripID),
    CONSTRAINT FK_RideSegment_Geofence 
        FOREIGN KEY (GeofenceID) REFERENCES dbo.[Geofence](GeofenceID),
    CONSTRAINT UQ_RideSegment_Request_Order
        UNIQUE (RideRequestID, SegmentOrder)
);
GO

/* ============================================================
   PAYMENTS
   ============================================================ */

CREATE TABLE dbo.[Payment] (
    PaymentID           INT IDENTITY(1,1) PRIMARY KEY,
    TripID              INT            NULL,       -- NULL for segment payments before trip is assigned
    SegmentID           INT            NULL,       -- For segment-based payments (multi-vehicle journey)
    PaymentMethodTypeID INT            NOT NULL,
    Amount              DECIMAL(10,2)  NOT NULL CHECK (Amount >= 0),
    CurrencyCode        CHAR(3)        NOT NULL,
    ProviderReference   NVARCHAR(255)  NULL,   -- transaction id from gateway
    Status              NVARCHAR(30)   NOT NULL DEFAULT 'pending',
    CreatedAt           DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    CompletedAt         DATETIME2      NULL,
    -- Fare breakdown columns
    BaseFare            DECIMAL(10,2)  NULL,
    DistanceFare        DECIMAL(10,2)  NULL,
    TimeFare            DECIMAL(10,2)  NULL,
    SurgeMultiplier     DECIMAL(4,2)   NULL DEFAULT 1.00,
    ServiceFeeRate      DECIMAL(5,4)   NULL DEFAULT 0.2000,
    ServiceFeeAmount    DECIMAL(10,2)  NULL,
    DriverEarnings      DECIMAL(10,2)  NULL,
    TipAmount           DECIMAL(10,2)  NULL DEFAULT 0.00,
    DistanceKm          DECIMAL(10,3)  NULL,
    DurationMinutes     DECIMAL(10,2)  NULL,
    CONSTRAINT FK_Payment_Trip
        FOREIGN KEY (TripID) REFERENCES dbo.[Trip](TripID),
    CONSTRAINT FK_Payment_Segment
        FOREIGN KEY (SegmentID) REFERENCES dbo.[RideSegment](SegmentID),
    CONSTRAINT FK_Payment_PaymentMethodType
        FOREIGN KEY (PaymentMethodTypeID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID),
    CONSTRAINT FK_Payment_Currency
        FOREIGN KEY (CurrencyCode) REFERENCES dbo.[Currency](CurrencyCode),
    -- Either TripID or SegmentID must be set
    CONSTRAINT CK_Payment_TripOrSegment
        CHECK (TripID IS NOT NULL OR SegmentID IS NOT NULL)
);

/* ============================================================
   MESSAGING & RATINGS
   ============================================================ */

CREATE TABLE dbo.[Message] (
    MessageID   INT IDENTITY(1,1) PRIMARY KEY,
    FromUserID  INT           NOT NULL,
    ToUserID    INT           NOT NULL,
    TripID      INT           NULL,
    Content     NVARCHAR(MAX) NOT NULL,
    SentAt      DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    IsSystem    BIT           NOT NULL DEFAULT 0,
    IsRead      BIT           NOT NULL DEFAULT 0,  -- Track if message has been read
    ReadAt      DATETIME2     NULL,                -- When the message was read
    CONSTRAINT FK_Message_FromUser
        FOREIGN KEY (FromUserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT FK_Message_ToUser
        FOREIGN KEY (ToUserID)   REFERENCES dbo.[User](UserID),
    CONSTRAINT FK_Message_Trip
        FOREIGN KEY (TripID)     REFERENCES dbo.[Trip](TripID)
);

CREATE TABLE dbo.[Rating] (
    RatingID     INT IDENTITY(1,1) PRIMARY KEY,
    TripID       INT          NOT NULL,
    FromUserID   INT          NOT NULL,
    ToUserID     INT          NOT NULL,
    Stars        INT          NOT NULL CHECK (Stars BETWEEN 1 AND 5),
    Comment      NVARCHAR(1000) NULL,
    CreatedAt    DATETIME2    NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_Rating_Trip
        FOREIGN KEY (TripID)     REFERENCES dbo.[Trip](TripID),
    CONSTRAINT FK_Rating_FromUser
        FOREIGN KEY (FromUserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT FK_Rating_ToUser
        FOREIGN KEY (ToUserID)   REFERENCES dbo.[User](UserID),
    CONSTRAINT UQ_Rating_Trip_FromUser
        UNIQUE (TripID, FromUserID)
);

/* ============================================================
   GDPR TABLES
   ============================================================ */

CREATE TABLE dbo.[GDPRRequest] (
    RequestID    INT IDENTITY(1,1) PRIMARY KEY,
    UserID       INT           NOT NULL,
    RequestedAt  DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    Status       NVARCHAR(30)  NOT NULL,         -- pending, completed, rejected...
    Reason       NVARCHAR(500) NULL,
    CompletedAt  DATETIME2     NULL,
    CONSTRAINT FK_GDPRRequest_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID)
);

CREATE TABLE dbo.[GDPRLog] (
    GDPRLogID   INT IDENTITY(1,1) PRIMARY KEY,
    RequestID   INT           NOT NULL,
    OperatorID  INT           NULL,
    Action      NVARCHAR(50)  NOT NULL,          -- approved_and_deleted, rejected, etc.
    ActionAt    DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_GDPRLog_Request
        FOREIGN KEY (RequestID)  REFERENCES dbo.[GDPRRequest](RequestID),
    CONSTRAINT FK_GDPRLog_Operator
        FOREIGN KEY (OperatorID) REFERENCES dbo.[Operator](OperatorID)
);

/* ============================================================
   DYNAMIC PRICING TABLES
   ============================================================ */

-- Demand zones for geographic demand-based pricing
CREATE TABLE dbo.[DemandZone] (
    DemandZoneID       INT IDENTITY(1,1) PRIMARY KEY,
    ZoneName           NVARCHAR(100) NOT NULL,
    CenterLatitude     DECIMAL(9,6) NOT NULL,
    CenterLongitude    DECIMAL(9,6) NOT NULL,
    RadiusKm           DECIMAL(5,2) NOT NULL DEFAULT 5.0,
    IsActive           BIT NOT NULL DEFAULT 1,
    CreatedAt          DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
GO

-- Peak hours for time-based pricing
CREATE TABLE dbo.[PeakHours] (
    PeakHourID         INT IDENTITY(1,1) PRIMARY KEY,
    DayOfWeek          INT NOT NULL,  -- 1=Sunday, 2=Monday, ... 7=Saturday
    StartHour          INT NOT NULL CHECK (StartHour >= 0 AND StartHour <= 23),
    EndHour            INT NOT NULL CHECK (EndHour >= 0 AND EndHour <= 23),
    SurgeMultiplier    DECIMAL(4,2) NOT NULL DEFAULT 1.25,
    Description        NVARCHAR(100) NULL,
    IsActive           BIT NOT NULL DEFAULT 1
);
GO

-- Vehicle type pricing multipliers
CREATE TABLE dbo.[VehicleTypePricing] (
    VehicleTypePricingID INT IDENTITY(1,1) PRIMARY KEY,
    VehicleTypeID        INT NOT NULL,
    PriceMultiplier      DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    MinimumFareOverride  DECIMAL(10,2) NULL,
    IsActive             BIT NOT NULL DEFAULT 1,
    CONSTRAINT FK_VTP_VehicleType
        FOREIGN KEY (VehicleTypeID) REFERENCES dbo.[VehicleType](VehicleTypeID)
);
GO

/* ============================================================
   PAYMENT & PRICING CONFIGURATION TABLES
   ============================================================ */

-- Pricing rules per service type
CREATE TABLE dbo.[PricingConfig] (
    PricingConfigID    INT IDENTITY(1,1) PRIMARY KEY,
    ServiceTypeID      INT NOT NULL,
    BaseFare           DECIMAL(10,2) NOT NULL DEFAULT 3.00,
    PricePerKm         DECIMAL(10,2) NOT NULL DEFAULT 1.50,
    PricePerMinute     DECIMAL(10,2) NOT NULL DEFAULT 0.25,
    MinimumFare        DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    ServiceFeeRate     DECIMAL(5,4) NOT NULL DEFAULT 0.2000,
    SurgeMultiplier    DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    IsActive           BIT NOT NULL DEFAULT 1,
    CreatedAt          DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt          DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_PricingConfig_ServiceType
        FOREIGN KEY (ServiceTypeID) REFERENCES dbo.[ServiceType](ServiceTypeID),
    CONSTRAINT UQ_PricingConfig_ServiceType 
        UNIQUE (ServiceTypeID)
);
GO

-- Driver pricing preferences
CREATE TABLE dbo.[DriverPricing] (
    DriverPricingID    INT IDENTITY(1,1) PRIMARY KEY,
    DriverID           INT NOT NULL,
    VehicleID          INT NULL,
    ServiceTypeID      INT NULL,
    MinimumFare        DECIMAL(10,2) NULL,
    PricePerKmOverride DECIMAL(10,2) NULL,
    IsActive           BIT NOT NULL DEFAULT 1,
    CreatedAt          DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt          DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_DriverPricing_Driver
        FOREIGN KEY (DriverID) REFERENCES dbo.[Driver](DriverID),
    CONSTRAINT FK_DriverPricing_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[Vehicle](VehicleID),
    CONSTRAINT FK_DriverPricing_ServiceType
        FOREIGN KEY (ServiceTypeID) REFERENCES dbo.[ServiceType](ServiceTypeID)
);
GO

/* ============================================================
   DRIVER LOCATION TRACKING TABLE
   ============================================================ */

-- Historical location data for drivers during trips
CREATE TABLE dbo.[DriverLocationHistory] (
    LocationHistoryID   INT IDENTITY(1,1) PRIMARY KEY,
    DriverID            INT            NOT NULL,
    TripID              INT            NULL,
    Latitude            DECIMAL(9,6)   NOT NULL,
    Longitude           DECIMAL(9,6)   NOT NULL,
    RecordedAt          DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    Speed               DECIMAL(5,2)   NULL,
    Heading             DECIMAL(5,2)   NULL,
    IsSimulated         BIT            NOT NULL DEFAULT 0,
    CONSTRAINT FK_DriverLocationHistory_Driver 
        FOREIGN KEY (DriverID) REFERENCES dbo.[Driver](DriverID),
    CONSTRAINT FK_DriverLocationHistory_Trip 
        FOREIGN KEY (TripID) REFERENCES dbo.[Trip](TripID)
);
GO

/* ============================================================
   AUTONOMOUS VEHICLES TABLES
   Tables for Waymo-style autonomous vehicle ride service
   ============================================================ */

/* ============================================================
   AUTONOMOUS VEHICLE FLEET
   ============================================================ */

CREATE TABLE dbo.[AutonomousVehicle] (
    AutonomousVehicleID  INT IDENTITY(1,1) PRIMARY KEY,
    VehicleCode          NVARCHAR(50)  NOT NULL UNIQUE,    -- e.g., 'AV-001', 'WAYMO-CY-001'
    VehicleTypeID        INT           NOT NULL,
    PlateNo              NVARCHAR(20)  NOT NULL UNIQUE,
    Make                 NVARCHAR(100) NULL,               -- e.g., 'Jaguar', 'Waymo'
    Model                NVARCHAR(100) NULL,               -- e.g., 'I-PACE', 'One'
    Year                 SMALLINT      NULL,
    Color                NVARCHAR(50)  NULL,
    SeatingCapacity      INT           NOT NULL DEFAULT 4,
    IsWheelchairReady    BIT           NOT NULL DEFAULT 0,
    -- Current status
    Status               NVARCHAR(30)  NOT NULL DEFAULT 'available',  
        -- available, busy, maintenance, offline, charging
    -- Current location
    CurrentLatitude      DECIMAL(9,6)  NULL,
    CurrentLongitude     DECIMAL(9,6)  NULL,
    LocationUpdatedAt    DATETIME2     NULL,
    -- Operating geofence (autonomous vehicles typically operate in specific areas)
    GeofenceID           INT           NULL,
    -- Battery/charge info
    BatteryLevel         INT           NULL CHECK (BatteryLevel >= 0 AND BatteryLevel <= 100),
    -- Photo
    PhotoUrl             NVARCHAR(500) NULL,
    -- Metadata
    IsActive             BIT           NOT NULL DEFAULT 1,
    CreatedAt            DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt            DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_AutonomousVehicle_VehicleType
        FOREIGN KEY (VehicleTypeID) REFERENCES dbo.[VehicleType](VehicleTypeID),
    CONSTRAINT FK_AutonomousVehicle_Geofence
        FOREIGN KEY (GeofenceID) REFERENCES dbo.[Geofence](GeofenceID)
);
GO

/* ============================================================
   AUTONOMOUS RIDES
   Separate table for autonomous vehicle rides (no driver involvement)
   ============================================================ */

CREATE TABLE dbo.[AutonomousRide] (
    AutonomousRideID     INT IDENTITY(1,1) PRIMARY KEY,
    PassengerID          INT           NOT NULL,
    AutonomousVehicleID  INT           NOT NULL,
    -- Locations
    PickupLocationID     INT           NOT NULL,
    DropoffLocationID    INT           NOT NULL,
    -- Request info
    RequestedAt          DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    PassengerNotes       NVARCHAR(500) NULL,
    WheelchairNeeded     BIT           NOT NULL DEFAULT 0,
    -- Status tracking
    Status               NVARCHAR(30)  NOT NULL DEFAULT 'requested',
        -- requested, vehicle_dispatched, vehicle_arriving, vehicle_arrived, 
        -- passenger_boarding, in_progress, arriving_destination, completed, cancelled
    -- Timestamps for ride phases
    VehicleDispatchedAt  DATETIME2     NULL,    -- When vehicle started heading to pickup
    VehicleArrivedAt     DATETIME2     NULL,    -- When vehicle arrived at pickup
    PassengerBoardedAt   DATETIME2     NULL,    -- When passenger got in
    TripStartedAt        DATETIME2     NULL,    -- When trip actually started moving
    TripCompletedAt      DATETIME2     NULL,    -- When arrived at destination
    -- Route info (from OSRM)
    PickupRouteGeometry  NVARCHAR(MAX) NULL,    -- Route from vehicle to pickup
    TripRouteGeometry    NVARCHAR(MAX) NULL,    -- Route from pickup to dropoff
    EstimatedPickupDistanceKm  DECIMAL(10,3) NULL,
    EstimatedPickupDurationSec INT       NULL,
    EstimatedTripDistanceKm    DECIMAL(10,3) NULL,
    EstimatedTripDurationSec   INT       NULL,
    ActualDistanceKm     DECIMAL(10,3) NULL,
    ActualDurationSec    INT           NULL,
    -- Vehicle start position (when dispatched)
    VehicleStartLat      DECIMAL(9,6)  NULL,
    VehicleStartLng      DECIMAL(9,6)  NULL,
    -- Simulation tracking
    SimulationPhase      NVARCHAR(30)  NULL,    -- 'pickup' or 'trip'
    SimulationStartTime  DATETIME2     NULL,
    SimulationSpeedMultiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    AccumulatedSimulatedSeconds FLOAT  NULL DEFAULT 0,
    LastSpeedChangeAt    DATETIME2     NULL,
    -- Payment info
    PaymentMethodTypeID  INT           NOT NULL DEFAULT 1,
    EstimatedFare        DECIMAL(10,2) NULL,
    ActualFare           DECIMAL(10,2) NULL,
    -- Platform earnings
    PlatformEarnings     DECIMAL(10,2) NULL,
    -- Cancellation
    CancelledAt          DATETIME2     NULL,
    CancellationReason   NVARCHAR(500) NULL,
    CONSTRAINT FK_AutonomousRide_Passenger
        FOREIGN KEY (PassengerID) REFERENCES dbo.[Passenger](PassengerID),
    CONSTRAINT FK_AutonomousRide_Vehicle
        FOREIGN KEY (AutonomousVehicleID) REFERENCES dbo.[AutonomousVehicle](AutonomousVehicleID),
    CONSTRAINT FK_AutonomousRide_PickupLocation
        FOREIGN KEY (PickupLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_AutonomousRide_DropoffLocation
        FOREIGN KEY (DropoffLocationID) REFERENCES dbo.[Location](LocationID),
    CONSTRAINT FK_AutonomousRide_PaymentMethodType
        FOREIGN KEY (PaymentMethodTypeID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID)
);
GO

/* ============================================================
   AUTONOMOUS VEHICLE LOCATION HISTORY
   Track autonomous vehicle movements during rides
   ============================================================ */

CREATE TABLE dbo.[AutonomousVehicleLocationHistory] (
    LocationHistoryID    INT IDENTITY(1,1) PRIMARY KEY,
    AutonomousVehicleID  INT            NOT NULL,
    AutonomousRideID     INT            NULL,
    Latitude             DECIMAL(9,6)   NOT NULL,
    Longitude            DECIMAL(9,6)   NOT NULL,
    RecordedAt           DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    Speed                DECIMAL(5,2)   NULL,      -- km/h
    Heading              DECIMAL(5,2)   NULL,      -- degrees
    Phase                NVARCHAR(30)   NULL,      -- 'pickup', 'trip', 'idle'
    CONSTRAINT FK_AVLocationHistory_Vehicle 
        FOREIGN KEY (AutonomousVehicleID) REFERENCES dbo.[AutonomousVehicle](AutonomousVehicleID),
    CONSTRAINT FK_AVLocationHistory_Ride 
        FOREIGN KEY (AutonomousRideID) REFERENCES dbo.[AutonomousRide](AutonomousRideID)
);
GO

/* ============================================================
   AUTONOMOUS RIDE PAYMENT
   Payment records for autonomous rides
   ============================================================ */

CREATE TABLE dbo.[AutonomousRidePayment] (
    PaymentID            INT IDENTITY(1,1) PRIMARY KEY,
    AutonomousRideID     INT            NOT NULL,
    PaymentMethodTypeID  INT            NOT NULL,
    Amount               DECIMAL(10,2)  NOT NULL CHECK (Amount >= 0),
    CurrencyCode         CHAR(3)        NOT NULL DEFAULT 'EUR',
    ProviderReference    NVARCHAR(255)  NULL,
    Status               NVARCHAR(30)   NOT NULL DEFAULT 'pending',
        -- pending, completed, failed, refunded
    CreatedAt            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    CompletedAt          DATETIME2      NULL,
    -- Fare breakdown
    BaseFare             DECIMAL(10,2)  NULL,
    DistanceFare         DECIMAL(10,2)  NULL,
    TimeFare             DECIMAL(10,2)  NULL,
    SurgeMultiplier      DECIMAL(4,2)   NULL DEFAULT 1.00,
    ServiceFeeRate       DECIMAL(5,4)   NULL DEFAULT 0.2000,
    ServiceFeeAmount     DECIMAL(10,2)  NULL,
    DistanceKm           DECIMAL(10,3)  NULL,
    DurationMinutes      DECIMAL(10,2)  NULL,
    CONSTRAINT FK_AVPayment_Ride
        FOREIGN KEY (AutonomousRideID) REFERENCES dbo.[AutonomousRide](AutonomousRideID),
    CONSTRAINT FK_AVPayment_PaymentMethodType
        FOREIGN KEY (PaymentMethodTypeID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID),
    CONSTRAINT FK_AVPayment_Currency
        FOREIGN KEY (CurrencyCode) REFERENCES dbo.[Currency](CurrencyCode)
);
GO

/* ============================================================
   AUTONOMOUS RIDE RATING
   Passengers can rate the autonomous ride experience
   ============================================================ */

CREATE TABLE dbo.[AutonomousRideRating] (
    RatingID             INT IDENTITY(1,1) PRIMARY KEY,
    AutonomousRideID     INT            NOT NULL,
    PassengerID          INT            NOT NULL,
    Stars                INT            NOT NULL CHECK (Stars BETWEEN 1 AND 5),
    Comment              NVARCHAR(1000) NULL,
    -- Rating categories specific to autonomous vehicles
    ComfortRating        INT            NULL CHECK (ComfortRating BETWEEN 1 AND 5),
    SafetyRating         INT            NULL CHECK (SafetyRating BETWEEN 1 AND 5),
    CleanlinessRating    INT            NULL CHECK (CleanlinessRating BETWEEN 1 AND 5),
    CreatedAt            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_AVRating_Ride
        FOREIGN KEY (AutonomousRideID) REFERENCES dbo.[AutonomousRide](AutonomousRideID),
    CONSTRAINT FK_AVRating_Passenger
        FOREIGN KEY (PassengerID) REFERENCES dbo.[Passenger](PassengerID),
    CONSTRAINT UQ_AVRating_Ride
        UNIQUE (AutonomousRideID)
);
GO

/* ============================================================
   CARSHARE SYSTEM - DRIVERLESS VEHICLE RENTAL TABLES
   ============================================================
   Implementation of option (ii): οχήματος χωρίς οδηγό (ridenow.tech style)
   
   This is a self-service car-sharing system where users:
   1. Find available vehicles near them via the app
   2. Book/reserve a vehicle
   3. Unlock the vehicle remotely
   4. Drive themselves to their destination
   5. Park in designated zones and end the rental
   6. Pay by minute/hour/day + kilometers driven
   
   Key differences from ride-hailing (option i):
   - No driver involved - customer drives the vehicle
   - Vehicles are stationed at designated parking zones
   - Pricing is per-minute/hour + per-kilometer
   - Must return to designated parking areas
   - Vehicle must pass cleanliness/condition checks
   ============================================================ */

/* ============================================================
   CARSHARE ZONES (Parking/Pickup Areas)
   ============================================================ */

-- Zones where carshare vehicles can be picked up and returned
CREATE TABLE dbo.[CarshareZone] (
    ZoneID              INT IDENTITY(1,1) PRIMARY KEY,
    ZoneName            NVARCHAR(100)   NOT NULL,
    ZoneType            NVARCHAR(50)    NOT NULL DEFAULT 'standard',  -- standard, airport, premium, pink (bonus zones)
    Description         NVARCHAR(500)   NULL,
    CenterLatitude      DECIMAL(9,6)    NOT NULL,
    CenterLongitude     DECIMAL(9,6)    NOT NULL,
    RadiusMeters        INT             NOT NULL DEFAULT 200,
    City                NVARCHAR(100)   NULL,
    District            NVARCHAR(100)   NULL,
    MaxCapacity         INT             NOT NULL DEFAULT 20,        -- Max vehicles allowed in zone
    CurrentVehicleCount INT             NOT NULL DEFAULT 0,
    InterCityFee        DECIMAL(10,2)   NULL,                       -- Fee for ending trip here from another city
    BonusAmount         DECIMAL(10,2)   NULL,                       -- Bonus for leaving car in "pink" zones
    IsActive            BIT             NOT NULL DEFAULT 1,
    OperatingHoursStart TIME            NULL DEFAULT '06:00:00',    -- When rentals can start
    OperatingHoursEnd   TIME            NULL DEFAULT '22:00:00',    -- When rentals must end
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT CHK_CarshareZone_Type 
        CHECK (ZoneType IN ('standard', 'airport', 'premium', 'pink', 'intercity'))
);
GO

-- Polygon points for zones (for precise boundary checking)
CREATE TABLE dbo.[CarshareZonePolygon] (
    PolygonPointID      INT IDENTITY(1,1) PRIMARY KEY,
    ZoneID              INT             NOT NULL,
    SequenceNo          INT             NOT NULL,
    LatDegrees          DECIMAL(9,6)    NOT NULL,
    LonDegrees          DECIMAL(9,6)    NOT NULL,
    CONSTRAINT FK_CarshareZonePolygon_Zone
        FOREIGN KEY (ZoneID) REFERENCES dbo.[CarshareZone](ZoneID) ON DELETE CASCADE,
    CONSTRAINT UQ_CarshareZonePolygon_ZoneSeq
        UNIQUE (ZoneID, SequenceNo)
);
GO

/* ============================================================
   CARSHARE VEHICLE TYPES
   ============================================================ */

-- Types of vehicles available for car-sharing
CREATE TABLE dbo.[CarshareVehicleType] (
    VehicleTypeID       INT IDENTITY(1,1) PRIMARY KEY,
    TypeCode            NVARCHAR(50)    NOT NULL UNIQUE,    -- e.g., 'ECONOMY', 'COMPACT', 'PREMIUM', 'CABRIO', 'VAN'
    TypeName            NVARCHAR(100)   NOT NULL,
    Description         NVARCHAR(500)   NULL,
    SeatingCapacity     INT             NOT NULL DEFAULT 5,
    HasAutomaticTrans   BIT             NOT NULL DEFAULT 1,
    HasAirCon           BIT             NOT NULL DEFAULT 1,
    CargoVolumeM3       DECIMAL(5,2)    NULL,
    IsElectric          BIT             NOT NULL DEFAULT 0,
    IsHybrid            BIT             NOT NULL DEFAULT 0,
    MinDriverAge        INT             NOT NULL DEFAULT 18,     -- Minimum age to rent this type
    MinLicenseYears     INT             NOT NULL DEFAULT 1,      -- Minimum years with license
    -- Pricing tiers (base rates, can be overridden per vehicle)
    PricePerMinute      DECIMAL(8,4)    NOT NULL DEFAULT 0.25,   -- €0.25/min
    PricePerHour        DECIMAL(8,2)    NOT NULL DEFAULT 12.00,  -- €12/hour
    PricePerDay         DECIMAL(8,2)    NOT NULL DEFAULT 60.00,  -- €60/day
    PricePerKm          DECIMAL(8,4)    NOT NULL DEFAULT 0.20,   -- €0.20/km
    MinimumRentalFee    DECIMAL(8,2)    NOT NULL DEFAULT 3.00,
    DepositAmount       DECIMAL(8,2)    NOT NULL DEFAULT 100.00, -- Held on card
    IsActive            BIT             NOT NULL DEFAULT 1,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME()
);
GO

/* ============================================================
   CARSHARE VEHICLES (The actual cars)
   ============================================================ */

-- Individual vehicles in the car-sharing fleet
CREATE TABLE dbo.[CarshareVehicle] (
    VehicleID           INT IDENTITY(1,1) PRIMARY KEY,
    VehicleTypeID       INT             NOT NULL,
    PlateNumber         NVARCHAR(20)    NOT NULL UNIQUE,
    Make                NVARCHAR(100)   NOT NULL,           -- e.g., 'Toyota', 'BMW', 'Nissan'
    Model               NVARCHAR(100)   NOT NULL,           -- e.g., 'Yaris', '4 Cabrio', 'Note'
    Year                SMALLINT        NOT NULL,
    Color               NVARCHAR(50)    NULL,
    VIN                 NVARCHAR(50)    NULL UNIQUE,        -- Vehicle Identification Number
    
    -- Current status and location
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'available',
        -- available, reserved, in_use, maintenance, out_of_zone, low_fuel, damaged
    CurrentZoneID       INT             NULL,               -- NULL if out of zone
    CurrentLatitude     DECIMAL(9,6)    NULL,
    CurrentLongitude    DECIMAL(9,6)    NULL,
    LocationUpdatedAt   DATETIME2       NULL,
    
    -- Vehicle condition
    FuelLevelPercent    INT             NOT NULL DEFAULT 100 CHECK (FuelLevelPercent BETWEEN 0 AND 100),
    BatteryLevelPercent INT             NULL,               -- For electric/hybrid
    OdometerKm          INT             NOT NULL DEFAULT 0,
    CleanlinessRating   INT             NULL CHECK (CleanlinessRating BETWEEN 1 AND 5),
    LastCleanedAt       DATETIME2       NULL,
    LastInspectedAt     DATETIME2       NULL,
    
    -- Features
    HasGPS              BIT             NOT NULL DEFAULT 1,
    HasBluetooth        BIT             NOT NULL DEFAULT 1,
    HasUSBCharger       BIT             NOT NULL DEFAULT 1,
    HasChildSeat        BIT             NOT NULL DEFAULT 0,
    HasRoofRack         BIT             NOT NULL DEFAULT 0,
    
    -- Telematics (for remote unlock/lock)
    TelematicsDeviceID  NVARCHAR(100)   NULL,
    IsLockedRemotely    BIT             NOT NULL DEFAULT 1,
    EngineEnabled       BIT             NOT NULL DEFAULT 0,  -- Can engine start?
    
    -- Pricing overrides (NULL = use type defaults)
    PricePerMinuteOverride  DECIMAL(8,4) NULL,
    PricePerHourOverride    DECIMAL(8,2) NULL,
    PricePerKmOverride      DECIMAL(8,4) NULL,
    
    -- Metadata
    IsActive            BIT             NOT NULL DEFAULT 1,
    DeactivationReason  NVARCHAR(500)   NULL,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT FK_CarshareVehicle_Type
        FOREIGN KEY (VehicleTypeID) REFERENCES dbo.[CarshareVehicleType](VehicleTypeID),
    CONSTRAINT FK_CarshareVehicle_Zone
        FOREIGN KEY (CurrentZoneID) REFERENCES dbo.[CarshareZone](ZoneID),
    CONSTRAINT CHK_CarshareVehicle_Status
        CHECK (Status IN ('available', 'reserved', 'in_use', 'maintenance', 'out_of_zone', 'low_fuel', 'damaged', 'charging', 'remote_dispatch'))
);
GO

/* ============================================================
   CARSHARE CUSTOMER (Extended Passenger for Car-Sharing)
   ============================================================ */

-- Additional customer data required for car-sharing
-- Links to existing Passenger table
CREATE TABLE dbo.[CarshareCustomer] (
    CustomerID          INT IDENTITY(1,1) PRIMARY KEY,
    PassengerID         INT             NOT NULL UNIQUE,    -- Link to Passenger table
    
    -- License verification
    LicenseNumber       NVARCHAR(50)    NOT NULL,
    LicenseCountry      NVARCHAR(100)   NOT NULL DEFAULT 'Cyprus',
    LicenseIssueDate    DATE            NOT NULL,
    LicenseExpiryDate   DATE            NOT NULL,
    LicenseVerified     BIT             NOT NULL DEFAULT 0,
    LicenseVerifiedAt   DATETIME2       NULL,
    LicenseVerifiedBy   INT             NULL,               -- OperatorID who verified
    LicensePhotoUrl     NVARCHAR(500)   NULL,
    
    -- Personal info for rental agreement
    DateOfBirth         DATE            NOT NULL,
    NationalID          NVARCHAR(50)    NULL,
    NationalIDPhotoUrl  NVARCHAR(500)   NULL,
    
    -- Payment info
    DefaultPaymentMethodID INT          NULL,
    StripeCustomerID    NVARCHAR(100)   NULL,               -- For payment processing
    HasValidPaymentMethod BIT           NOT NULL DEFAULT 0,
    
    -- Status
    VerificationStatus  NVARCHAR(50)    NOT NULL DEFAULT 'pending',
        -- pending, approved, rejected, suspended
    VerificationNotes   NVARCHAR(500)   NULL,
    
    -- Rental history & loyalty
    TotalRentals        INT             NOT NULL DEFAULT 0,
    TotalDistanceKm     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    TotalSpentEUR       DECIMAL(12,2)   NOT NULL DEFAULT 0,
    LoyaltyPoints       INT             NOT NULL DEFAULT 0,
    MembershipTier      NVARCHAR(50)    NOT NULL DEFAULT 'basic',  -- basic, silver, gold, platinum
    
    -- Preferences
    PreferredLanguage   NVARCHAR(10)    NOT NULL DEFAULT 'en',
    PrefersFuelDiscount BIT             NOT NULL DEFAULT 1,
    EmailNotifications  BIT             NOT NULL DEFAULT 1,
    SMSNotifications    BIT             NOT NULL DEFAULT 1,
    
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    LastRentalAt        DATETIME2       NULL,
    
    CONSTRAINT FK_CarshareCustomer_Passenger
        FOREIGN KEY (PassengerID) REFERENCES dbo.[Passenger](PassengerID),
    CONSTRAINT FK_CarshareCustomer_PaymentMethod
        FOREIGN KEY (DefaultPaymentMethodID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID),
    CONSTRAINT CHK_CarshareCustomer_VerificationStatus
        CHECK (VerificationStatus IN ('pending', 'documents_submitted', 'approved', 'rejected', 'suspended')),
    CONSTRAINT CHK_CarshareCustomer_MembershipTier
        CHECK (MembershipTier IN ('basic', 'silver', 'gold', 'platinum'))
);
GO

/* ============================================================
   CARSHARE BOOKINGS (Reservations)
   ============================================================ */

-- Reservations/bookings for vehicles
CREATE TABLE dbo.[CarshareBooking] (
    BookingID           INT IDENTITY(1,1) PRIMARY KEY,
    CustomerID          INT             NOT NULL,
    VehicleID           INT             NOT NULL,
    
    -- Booking times
    BookedAt            DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ReservationStartAt  DATETIME2       NOT NULL,           -- When reservation begins (up to 20 min free wait)
    ReservationExpiresAt DATETIME2      NOT NULL,           -- After this, booking cancelled
    
    -- Pricing mode selection at booking
    PricingMode         NVARCHAR(20)    NOT NULL DEFAULT 'per_minute',
        -- per_minute, per_hour, per_day
    EstimatedDurationMin INT            NULL,
    EstimatedDistanceKm DECIMAL(10,2)   NULL,
    EstimatedCost       DECIMAL(10,2)   NULL,
    
    -- Status
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'reserved',
        -- reserved, active, completed, cancelled, expired, no_show
    CancellationReason  NVARCHAR(500)   NULL,
    CancelledAt         DATETIME2       NULL,
    
    -- Pickup details
    PickupZoneID        INT             NOT NULL,
    PickupLatitude      DECIMAL(9,6)    NULL,
    PickupLongitude     DECIMAL(9,6)    NULL,
    
    -- Deposit
    DepositAmount       DECIMAL(10,2)   NOT NULL DEFAULT 0,
    DepositHeld         BIT             NOT NULL DEFAULT 0,
    DepositReleasedAt   DATETIME2       NULL,
    
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT FK_CarshareBooking_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarshareBooking_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_CarshareBooking_PickupZone
        FOREIGN KEY (PickupZoneID) REFERENCES dbo.[CarshareZone](ZoneID),
    CONSTRAINT CHK_CarshareBooking_Status
        CHECK (Status IN ('reserved', 'active', 'completed', 'cancelled', 'expired', 'no_show')),
    CONSTRAINT CHK_CarshareBooking_PricingMode
        CHECK (PricingMode IN ('per_minute', 'per_hour', 'per_day'))
);
GO

/* ============================================================
   CARSHARE RENTALS (Active/Completed Rentals)
   ============================================================ */

-- Actual rental sessions (when customer unlocks and uses vehicle)
CREATE TABLE dbo.[CarshareRental] (
    RentalID            INT IDENTITY(1,1) PRIMARY KEY,
    BookingID           INT             NOT NULL UNIQUE,    -- One rental per booking
    CustomerID          INT             NOT NULL,
    VehicleID           INT             NOT NULL,
    
    -- Rental timing
    StartedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),  -- Vehicle unlocked
    EndedAt             DATETIME2       NULL,               -- Vehicle locked & parked
    TotalDurationMin    INT             NULL,               -- Calculated on end
    
    -- Odometer readings
    OdometerStartKm     INT             NOT NULL,
    OdometerEndKm       INT             NULL,
    DistanceKm          AS (OdometerEndKm - OdometerStartKm) PERSISTED,
    
    -- Fuel readings
    FuelStartPercent    INT             NOT NULL,
    FuelEndPercent      INT             NULL,
    FuelUsedPercent     AS (FuelStartPercent - ISNULL(FuelEndPercent, FuelStartPercent)) PERSISTED,
    
    -- Start location
    StartZoneID         INT             NOT NULL,
    StartLatitude       DECIMAL(9,6)    NOT NULL,
    StartLongitude      DECIMAL(9,6)    NOT NULL,
    
    -- End location
    EndZoneID           INT             NULL,               -- NULL if in progress
    EndLatitude         DECIMAL(9,6)    NULL,
    EndLongitude        DECIMAL(9,6)    NULL,
    ParkedInZone        BIT             NULL,               -- Was it parked correctly?
    
    -- Status
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'active',
        -- active, paused, completed, ended_by_support, terminated
    StatusReason        NVARCHAR(500)   NULL,
    
    -- Pricing mode and calculation
    PricingMode         NVARCHAR(20)    NOT NULL,
    PricePerMinute      DECIMAL(8,4)    NOT NULL,
    PricePerHour        DECIMAL(8,2)    NULL,
    PricePerDay         DECIMAL(8,2)    NULL,
    PricePerKm          DECIMAL(8,4)    NOT NULL,
    
    -- Cost breakdown
    TimeCost            DECIMAL(10,2)   NULL,
    DistanceCost        DECIMAL(10,2)   NULL,
    InterCityFee        DECIMAL(10,2)   NULL DEFAULT 0,     -- If ending in different city
    LowFuelFee          DECIMAL(10,2)   NULL DEFAULT 0,     -- If returned with low fuel
    OutOfZoneFee        DECIMAL(10,2)   NULL DEFAULT 0,     -- If not parked in zone
    DamageFee           DECIMAL(10,2)   NULL DEFAULT 0,     -- If damage reported
    CleaningFee         DECIMAL(10,2)   NULL DEFAULT 0,     -- If excessively dirty
    Discount            DECIMAL(10,2)   NULL DEFAULT 0,     -- Promo codes, loyalty
    BonusCredit         DECIMAL(10,2)   NULL DEFAULT 0,     -- Pink zone bonus, refuel bonus
    TotalCost           DECIMAL(10,2)   NULL,               -- Final amount
    
    -- Refueling bonus (if customer refueled)
    DidRefuel           BIT             NOT NULL DEFAULT 0,
    RefuelAmount        DECIMAL(10,2)   NULL,               -- Amount spent on fuel
    RefuelBonusApplied  DECIMAL(10,2)   NULL DEFAULT 0,
    
    -- Condition reports
    PreRentalPhotos     NVARCHAR(MAX)   NULL,               -- JSON array of photo URLs
    PostRentalPhotos    NVARCHAR(MAX)   NULL,
    ConditionReportID   INT             NULL,               -- Link to condition report
    CustomerNotes       NVARCHAR(1000)  NULL,
    
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT FK_CarshareRental_Booking
        FOREIGN KEY (BookingID) REFERENCES dbo.[CarshareBooking](BookingID),
    CONSTRAINT FK_CarshareRental_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarshareRental_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_CarshareRental_StartZone
        FOREIGN KEY (StartZoneID) REFERENCES dbo.[CarshareZone](ZoneID),
    CONSTRAINT FK_CarshareRental_EndZone
        FOREIGN KEY (EndZoneID) REFERENCES dbo.[CarshareZone](ZoneID),
    CONSTRAINT CHK_CarshareRental_Status
        CHECK (Status IN ('active', 'paused', 'completed', 'ended_by_support', 'terminated'))
);
GO

/* ============================================================
   CARSHARE TELE-DRIVE REQUESTS (Remote Vehicle Delivery)
   ============================================================ */

CREATE TABLE dbo.[CarshareTeleDriveRequest] (
    TeleDriveID             INT IDENTITY(1,1) PRIMARY KEY,
    BookingID               INT             NOT NULL,
    CustomerID              INT             NOT NULL,
    VehicleID               INT             NOT NULL,
    StartZoneID             INT             NOT NULL,
    StartLatitude           DECIMAL(9,6)    NOT NULL,
    StartLongitude          DECIMAL(9,6)    NOT NULL,
    TargetLatitude          DECIMAL(9,6)    NOT NULL,
    TargetLongitude         DECIMAL(9,6)    NOT NULL,
    EstimatedDurationSec    INT             NOT NULL,
    EstimatedDistanceKm     DECIMAL(10,3)   NOT NULL,
    RouteGeometry           NVARCHAR(MAX)   NULL,
    Status                  NVARCHAR(30)    NOT NULL DEFAULT 'pending',
        -- pending, en_route, arrived, completed, cancelled, failed
    CreatedAt               DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    StartedAt               DATETIME2       NULL,
    ArrivedAt               DATETIME2       NULL,
    CompletedAt             DATETIME2       NULL,
    CancelledAt             DATETIME2       NULL,
    LastProgressPercent     DECIMAL(5,2)    NULL,
    LastStatusNote          NVARCHAR(500)   NULL,
    SpeedMultiplier         DECIMAL(4,2)    NOT NULL DEFAULT 1.0,
    SpeedChangedAt          DATETIME2       NULL,
    ProgressAtSpeedChange   DECIMAL(5,4)    NOT NULL DEFAULT 0.0,
    CONSTRAINT FK_TeleDrive_Booking
        FOREIGN KEY (BookingID) REFERENCES dbo.[CarshareBooking](BookingID),
    CONSTRAINT FK_TeleDrive_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_TeleDrive_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_TeleDrive_StartZone
        FOREIGN KEY (StartZoneID) REFERENCES dbo.[CarshareZone](ZoneID),
    CONSTRAINT CHK_TeleDrive_Status
        CHECK (Status IN ('pending', 'en_route', 'arrived', 'completed', 'cancelled', 'failed'))
);
GO

/* ============================================================
   CARSHARE RENTAL PAYMENTS
   ============================================================ */

-- Payment records for rentals
CREATE TABLE dbo.[CarsharePayment] (
    PaymentID           INT IDENTITY(1,1) PRIMARY KEY,
    RentalID            INT             NOT NULL,
    CustomerID          INT             NOT NULL,
    
    -- Amount
    Amount              DECIMAL(10,2)   NOT NULL,
    CurrencyCode        CHAR(3)         NOT NULL DEFAULT 'EUR',
    
    -- Payment details
    PaymentMethodTypeID INT             NOT NULL,
    PaymentType         NVARCHAR(50)    NOT NULL DEFAULT 'rental',
        -- rental, deposit_hold, deposit_release, penalty, refund, bonus_credit
    
    -- Processing
    ProviderReference   NVARCHAR(255)   NULL,               -- Stripe/payment gateway ref
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'pending',
        -- pending, processing, completed, failed, refunded, cancelled
    FailureReason       NVARCHAR(500)   NULL,
    
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ProcessedAt         DATETIME2       NULL,
    CompletedAt         DATETIME2       NULL,
    
    CONSTRAINT FK_CarsharePayment_Rental
        FOREIGN KEY (RentalID) REFERENCES dbo.[CarshareRental](RentalID),
    CONSTRAINT FK_CarsharePayment_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarsharePayment_PaymentMethod
        FOREIGN KEY (PaymentMethodTypeID) REFERENCES dbo.[PaymentMethodType](PaymentMethodTypeID),
    CONSTRAINT FK_CarsharePayment_Currency
        FOREIGN KEY (CurrencyCode) REFERENCES dbo.[Currency](CurrencyCode),
    CONSTRAINT CHK_CarsharePayment_Type
        CHECK (PaymentType IN ('rental', 'deposit_hold', 'deposit_release', 'penalty', 'refund', 'bonus_credit')),
    CONSTRAINT CHK_CarsharePayment_Status
        CHECK (Status IN ('pending', 'processing', 'completed', 'failed', 'refunded', 'cancelled'))
);
GO

/* ============================================================
   CARSHARE VEHICLE CONDITION REPORTS
   ============================================================ */

-- Condition reports for vehicles (before/after rentals)
CREATE TABLE dbo.[CarshareConditionReport] (
    ReportID            INT IDENTITY(1,1) PRIMARY KEY,
    VehicleID           INT             NOT NULL,
    RentalID            INT             NULL,               -- NULL if maintenance check
    CustomerID          INT             NULL,               -- NULL if staff report
    OperatorID          INT             NULL,               -- Staff who reviewed
    
    -- Report timing
    ReportType          NVARCHAR(50)    NOT NULL,           -- pre_rental, post_rental, maintenance, damage
    ReportedAt          DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ReviewedAt          DATETIME2       NULL,
    
    -- Exterior condition
    ExteriorRating      INT             NULL CHECK (ExteriorRating BETWEEN 1 AND 5),
    ExteriorNotes       NVARCHAR(1000)  NULL,
    HasExteriorDamage   BIT             NOT NULL DEFAULT 0,
    ExteriorDamageDesc  NVARCHAR(1000)  NULL,
    
    -- Interior condition
    InteriorRating      INT             NULL CHECK (InteriorRating BETWEEN 1 AND 5),
    InteriorNotes       NVARCHAR(1000)  NULL,
    CleanlinessRating   INT             NULL CHECK (CleanlinessRating BETWEEN 1 AND 5),
    HasInteriorDamage   BIT             NOT NULL DEFAULT 0,
    InteriorDamageDesc  NVARCHAR(1000)  NULL,
    
    -- Mechanical
    TiresCondition      NVARCHAR(50)    NULL,               -- good, worn, damaged
    LightsWorking       BIT             NULL,
    BrakesCondition     NVARCHAR(50)    NULL,
    
    -- Photos (JSON array of URLs)
    PhotoUrls           NVARCHAR(MAX)   NULL,
    
    -- Outcome
    ActionRequired      NVARCHAR(500)   NULL,
    PenaltyAmount       DECIMAL(10,2)   NULL DEFAULT 0,
    IsResolved          BIT             NOT NULL DEFAULT 0,
    ResolvedAt          DATETIME2       NULL,
    
    CONSTRAINT FK_CarshareConditionReport_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_CarshareConditionReport_Rental
        FOREIGN KEY (RentalID) REFERENCES dbo.[CarshareRental](RentalID),
    CONSTRAINT FK_CarshareConditionReport_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarshareConditionReport_Operator
        FOREIGN KEY (OperatorID) REFERENCES dbo.[Operator](OperatorID),
    CONSTRAINT CHK_CarshareConditionReport_Type
        CHECK (ReportType IN ('pre_rental', 'post_rental', 'maintenance', 'damage', 'inspection'))
);
GO

/* ============================================================
   CARSHARE VEHICLE LOCATION HISTORY
   ============================================================ */

-- Historical location data for vehicles during rentals
CREATE TABLE dbo.[CarshareVehicleLocationHistory] (
    LocationHistoryID   INT IDENTITY(1,1) PRIMARY KEY,
    VehicleID           INT             NOT NULL,
    RentalID            INT             NULL,
    Latitude            DECIMAL(9,6)    NOT NULL,
    Longitude           DECIMAL(9,6)    NOT NULL,
    RecordedAt          DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    Speed               DECIMAL(5,2)    NULL,               -- km/h
    Heading             DECIMAL(5,2)    NULL,               -- degrees
    FuelLevel           INT             NULL,
    EngineRunning       BIT             NULL,
    
    CONSTRAINT FK_CarshareVehicleLocationHistory_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_CarshareVehicleLocationHistory_Rental
        FOREIGN KEY (RentalID) REFERENCES dbo.[CarshareRental](RentalID)
);
GO

/* ============================================================
   CARSHARE PROMO CODES
   ============================================================ */

-- Promotional codes and discounts
CREATE TABLE dbo.[CarsharePromoCode] (
    PromoCodeID         INT IDENTITY(1,1) PRIMARY KEY,
    Code                NVARCHAR(50)    NOT NULL UNIQUE,
    Description         NVARCHAR(500)   NULL,
    DiscountType        NVARCHAR(20)    NOT NULL,           -- percentage, fixed, free_minutes
    DiscountValue       DECIMAL(10,2)   NOT NULL,
    MinimumRentalMin    INT             NULL,               -- Minimum rental duration to apply
    MaxDiscountEUR      DECIMAL(10,2)   NULL,               -- Cap on discount
    MaxUses             INT             NULL,               -- Total uses allowed
    MaxUsesPerCustomer  INT             NOT NULL DEFAULT 1,
    CurrentUses         INT             NOT NULL DEFAULT 0,
    ValidFrom           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ValidUntil          DATETIME2       NULL,
    IsActive            BIT             NOT NULL DEFAULT 1,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT CHK_CarsharePromoCode_DiscountType
        CHECK (DiscountType IN ('percentage', 'fixed', 'free_minutes'))
);
GO

-- Track promo code usage
CREATE TABLE dbo.[CarsharePromoCodeUsage] (
    UsageID             INT IDENTITY(1,1) PRIMARY KEY,
    PromoCodeID         INT             NOT NULL,
    CustomerID          INT             NOT NULL,
    RentalID            INT             NULL,
    DiscountApplied     DECIMAL(10,2)   NOT NULL,
    UsedAt              DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT FK_CarsharePromoCodeUsage_PromoCode
        FOREIGN KEY (PromoCodeID) REFERENCES dbo.[CarsharePromoCode](PromoCodeID),
    CONSTRAINT FK_CarsharePromoCodeUsage_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarsharePromoCodeUsage_Rental
        FOREIGN KEY (RentalID) REFERENCES dbo.[CarshareRental](RentalID)
);
GO

/* ============================================================
   CARSHARE GEOFENCE RESTRICTIONS
   ============================================================ */

-- Operating boundary geofence (vehicles cannot leave this area)
CREATE TABLE dbo.[CarshareOperatingArea] (
    AreaID              INT IDENTITY(1,1) PRIMARY KEY,
    AreaName            NVARCHAR(100)   NOT NULL,
    AreaType            NVARCHAR(50)    NOT NULL DEFAULT 'operating',  -- operating, restricted, no_parking
    Description         NVARCHAR(500)   NULL,
    -- For circular boundaries
    CenterLatitude      DECIMAL(9,6)    NULL,
    CenterLongitude     DECIMAL(9,6)    NULL,
    RadiusMeters        INT             NULL,
    -- For polygon boundaries (use CarshareOperatingAreaPolygon)
    UsePolygon          BIT             NOT NULL DEFAULT 0,
    -- Penalties
    WarningDistanceM    INT             NOT NULL DEFAULT 500,       -- Warn when this close to boundary
    PenaltyPerMinute    DECIMAL(8,2)    NOT NULL DEFAULT 1.00,      -- €/min when outside
    MaxPenalty          DECIMAL(10,2)   NOT NULL DEFAULT 100.00,    -- Max penalty cap
    -- Actions
    DisableEngineOutside BIT            NOT NULL DEFAULT 0,         -- Kill engine if outside?
    IsActive            BIT             NOT NULL DEFAULT 1,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT CHK_CarshareOperatingArea_Type 
        CHECK (AreaType IN ('operating', 'restricted', 'no_parking', 'speed_limit'))
);
GO

-- Polygon points for operating areas
CREATE TABLE dbo.[CarshareOperatingAreaPolygon] (
    PolygonPointID      INT IDENTITY(1,1) PRIMARY KEY,
    AreaID              INT             NOT NULL,
    SequenceNo          INT             NOT NULL,
    LatDegrees          DECIMAL(9,6)    NOT NULL,
    LonDegrees          DECIMAL(9,6)    NOT NULL,
    CONSTRAINT FK_CarshareOperatingAreaPolygon_Area
        FOREIGN KEY (AreaID) REFERENCES dbo.[CarshareOperatingArea](AreaID) ON DELETE CASCADE,
    CONSTRAINT UQ_CarshareOperatingAreaPolygon_AreaSeq
        UNIQUE (AreaID, SequenceNo)
);
GO

-- Geofence violation log
CREATE TABLE dbo.[CarshareGeofenceViolation] (
    ViolationID         INT IDENTITY(1,1) PRIMARY KEY,
    RentalID            INT             NOT NULL,
    VehicleID           INT             NOT NULL,
    CustomerID          INT             NOT NULL,
    AreaID              INT             NULL,               -- Which area was violated
    ViolationType       NVARCHAR(50)    NOT NULL,           -- boundary_exit, restricted_entry, speed_limit
    Latitude            DECIMAL(9,6)    NOT NULL,
    Longitude           DECIMAL(9,6)    NOT NULL,
    DistanceOutsideM    INT             NULL,               -- How far outside boundary
    DurationSeconds     INT             NULL,               -- How long outside
    PenaltyAmount       DECIMAL(10,2)   NOT NULL DEFAULT 0,
    IsResolved          BIT             NOT NULL DEFAULT 0,
    ResolvedAt          DATETIME2       NULL,
    ResolvedBy          INT             NULL,
    Notes               NVARCHAR(500)   NULL,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT FK_CarshareGeofenceViolation_Rental
        FOREIGN KEY (RentalID) REFERENCES dbo.[CarshareRental](RentalID),
    CONSTRAINT FK_CarshareGeofenceViolation_Vehicle
        FOREIGN KEY (VehicleID) REFERENCES dbo.[CarshareVehicle](VehicleID),
    CONSTRAINT FK_CarshareGeofenceViolation_Customer
        FOREIGN KEY (CustomerID) REFERENCES dbo.[CarshareCustomer](CustomerID),
    CONSTRAINT FK_CarshareGeofenceViolation_Area
        FOREIGN KEY (AreaID) REFERENCES dbo.[CarshareOperatingArea](AreaID),
    CONSTRAINT CHK_CarshareGeofenceViolation_Type
        CHECK (ViolationType IN ('boundary_exit', 'boundary_warning', 'restricted_entry', 'no_parking', 'speed_limit'))
);
GO

/* ============================================================
   CARSHARE AUDIT LOG
   ============================================================ */

-- Comprehensive audit log for car-sharing operations
CREATE TABLE dbo.[CarshareAuditLog] (
    AuditLogID          INT IDENTITY(1,1) PRIMARY KEY,
    TableName           NVARCHAR(100)   NOT NULL,
    RecordID            INT             NOT NULL,
    Action              NVARCHAR(20)    NOT NULL,           -- INSERT, UPDATE, DELETE
    OldValues           NVARCHAR(MAX)   NULL,               -- JSON of old values
    NewValues           NVARCHAR(MAX)   NULL,               -- JSON of new values
    ChangedBy           INT             NULL,               -- UserID
    ChangedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    IPAddress           NVARCHAR(50)    NULL,
    UserAgent           NVARCHAR(500)   NULL
);
GO

/* ============================================================
   CARSHARE SYSTEM LOG
   ============================================================ */

-- System events and errors for car-sharing
CREATE TABLE dbo.[CarshareSystemLog] (
    LogID               INT IDENTITY(1,1) PRIMARY KEY,
    Severity            NVARCHAR(20)    NOT NULL,           -- info, warning, error, critical
    Category            NVARCHAR(50)    NOT NULL,           -- booking, rental, payment, vehicle, telematics
    Message             NVARCHAR(MAX)   NOT NULL,
    VehicleID           INT             NULL,
    CustomerID          INT             NULL,
    RentalID            INT             NULL,
    BookingID           INT             NULL,
    ExceptionDetails    NVARCHAR(MAX)   NULL,
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    
    CONSTRAINT CHK_CarshareSystemLog_Severity
        CHECK (Severity IN ('info', 'warning', 'error', 'critical'))
);
GO

/* ============================================================
   KASPA CRYPTOCURRENCY PAYMENT TABLES
   Support for Kaspa (KAS) cryptocurrency payments
   ============================================================ */

-- User Kaspa Wallet
CREATE TABLE dbo.[KaspaWallet] (
    WalletID            INT IDENTITY(1,1) PRIMARY KEY,
    UserID              INT             NOT NULL,
    -- Kaspa Address (starts with kaspa: or kaspatest:)
    WalletAddress       NVARCHAR(100)   NOT NULL,
    -- Address validation
    AddressPrefix       NVARCHAR(20)    NOT NULL DEFAULT 'kaspa',  -- kaspa, kaspatest, kaspadev
    IsVerified          BIT             NOT NULL DEFAULT 0,
    VerifiedAt          DATETIME2       NULL,
    -- Wallet type
    WalletType          NVARCHAR(50)    NOT NULL DEFAULT 'receive',  -- receive, send, both
    IsDefault           BIT             NOT NULL DEFAULT 0,
    -- Metadata
    Label               NVARCHAR(100)   NULL,  -- User-friendly name like "My Main Wallet"
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    UpdatedAt           DATETIME2       NULL,
    -- Status
    IsActive            BIT             NOT NULL DEFAULT 1,
    
    CONSTRAINT FK_KaspaWallet_User
        FOREIGN KEY (UserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT UQ_KaspaWallet_Address
        UNIQUE (WalletAddress),
    CONSTRAINT CHK_KaspaWallet_Prefix
        CHECK (AddressPrefix IN ('kaspa', 'kaspatest', 'kaspadev', 'kaspasim'))
);
GO

-- Kaspa Transaction Table
CREATE TABLE dbo.[KaspaTransaction] (
    KaspaTransactionID  INT IDENTITY(1,1) PRIMARY KEY,
    -- Link to payment (if applicable)
    PaymentID           INT             NULL,
    -- Transaction parties
    FromUserID          INT             NULL,  -- Payer (NULL if external)
    ToUserID            INT             NULL,  -- Payee (NULL if external)
    FromWalletAddress   NVARCHAR(100)   NULL,
    ToWalletAddress     NVARCHAR(100)   NOT NULL,
    -- Transaction details
    AmountKAS           DECIMAL(18,8)   NOT NULL,  -- Amount in KAS (8 decimal places)
    AmountSompi         BIGINT          NOT NULL,  -- Amount in Sompi (1 KAS = 100,000,000 Sompi)
    AmountEUR           DECIMAL(10,2)   NULL,      -- EUR equivalent at time of transaction
    ExchangeRate        DECIMAL(18,8)   NULL,      -- KAS/EUR rate used
    -- Network details
    NetworkID           NVARCHAR(20)    NOT NULL DEFAULT 'mainnet',  -- mainnet, testnet-10
    TransactionHash     NVARCHAR(100)   NULL,      -- Kaspa transaction ID/hash
    BlockHash           NVARCHAR(100)   NULL,      -- Block containing the transaction
    BlockDaaScore       BIGINT          NULL,      -- DAA score of the block
    Confirmations       INT             NOT NULL DEFAULT 0,
    -- Status tracking
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'pending',
        -- pending, broadcasting, confirming, confirmed, failed, expired
    FailureReason       NVARCHAR(500)   NULL,
    -- Transaction type
    TransactionType     NVARCHAR(50)    NOT NULL DEFAULT 'payment',
        -- payment, tip, refund, withdrawal, deposit
    -- Context
    TripID              INT             NULL,
    SegmentID           INT             NULL,
    AutonomousRideID    INT             NULL,
    RentalID            INT             NULL,
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    BroadcastAt         DATETIME2       NULL,
    ConfirmedAt         DATETIME2       NULL,
    ExpiresAt           DATETIME2       NULL,  -- For pending payments that expire
    
    CONSTRAINT FK_KaspaTransaction_Payment
        FOREIGN KEY (PaymentID) REFERENCES dbo.[Payment](PaymentID),
    CONSTRAINT FK_KaspaTransaction_FromUser
        FOREIGN KEY (FromUserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT FK_KaspaTransaction_ToUser
        FOREIGN KEY (ToUserID) REFERENCES dbo.[User](UserID),
    CONSTRAINT CHK_KaspaTransaction_Network
        CHECK (NetworkID IN ('mainnet', 'testnet-10', 'testnet-11', 'devnet', 'simnet'))
);
GO

-- Kaspa Exchange Rate History
CREATE TABLE dbo.[KaspaExchangeRate] (
    RateID              INT IDENTITY(1,1) PRIMARY KEY,
    -- Exchange rate
    RateKAStoEUR        DECIMAL(18,8)   NOT NULL,  -- 1 KAS = X EUR
    RateEURtoKAS        DECIMAL(18,8)   NOT NULL,  -- 1 EUR = X KAS
    -- Source
    Source              NVARCHAR(100)   NOT NULL DEFAULT 'kaspa-api',  -- Price source
    -- Timestamps
    FetchedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ValidUntil          DATETIME2       NOT NULL,  -- Rate validity period
    
    INDEX IX_KaspaExchangeRate_FetchedAt (FetchedAt DESC)
);
GO

-- Kaspa Payment Request (for QR code payments)
CREATE TABLE dbo.[KaspaPaymentRequest] (
    RequestID           INT IDENTITY(1,1) PRIMARY KEY,
    -- Request identifier
    RequestCode         NVARCHAR(50)    NOT NULL UNIQUE,  -- Short code for URL/QR
    -- Payment details
    ToWalletAddress     NVARCHAR(100)   NOT NULL,
    AmountKAS           DECIMAL(18,8)   NOT NULL,
    AmountEUR           DECIMAL(10,2)   NULL,
    -- Context
    PaymentID           INT             NULL,
    TripID              INT             NULL,
    SegmentID           INT             NULL,
    AutonomousRideID    INT             NULL,
    RentalID            INT             NULL,
    -- Request metadata
    Description         NVARCHAR(255)   NULL,  -- "Payment for trip #123"
    -- Status
    Status              NVARCHAR(50)    NOT NULL DEFAULT 'pending',
        -- pending, partial, completed, expired, cancelled
    -- Timestamps
    CreatedAt           DATETIME2       NOT NULL DEFAULT SYSDATETIME(),
    ExpiresAt           DATETIME2       NOT NULL,  -- Payment requests expire
    CompletedAt         DATETIME2       NULL,
    -- Link to transaction once paid
    KaspaTransactionID  INT             NULL,
    
    CONSTRAINT FK_KaspaPaymentRequest_Transaction
        FOREIGN KEY (KaspaTransactionID) REFERENCES dbo.[KaspaTransaction](KaspaTransactionID)
);
GO

PRINT 'All tables created successfully.';
GO
