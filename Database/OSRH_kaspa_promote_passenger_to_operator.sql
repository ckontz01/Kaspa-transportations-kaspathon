/* ============================================================
   PROMOTE PASSENGER TO OPERATOR
   ============================================================
   This script promotes a passenger to operator role.
   
   Instructions:
   1. First, find the PassengerID you want to promote:
      SELECT PassengerID, UserID, FullName 
      FROM dbo.Passenger p
      JOIN dbo.[User] u ON p.UserID = u.UserID;
   
   2. Update the @PassengerID value below
   3. Run this script
   ============================================================ */

-- Promote PassengerID 6 to Operator
EXEC dbo.spPromotePassengerToOperator
    @PassengerID = 1,
    @Role        = N'Operator';
GO

PRINT 'Passenger promoted to Operator successfully!';
GO
