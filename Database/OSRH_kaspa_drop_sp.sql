
/* ============================================================
   DROP ALL STORED PROCEDURES
   ============================================================
   This script removes all stored procedures from the OSRH database.
   Run this BEFORE running osrh_sp.sql to ensure clean recreation.
   ============================================================ */

SET NOCOUNT ON;

PRINT '=================================================';
PRINT 'DROPPING ALL STORED PROCEDURES';
PRINT '=================================================';
PRINT '';

-- Drop all stored procedures dynamically
DECLARE @sql NVARCHAR(MAX) = N'';

SELECT @sql = @sql + 'DROP PROCEDURE IF EXISTS ' + QUOTENAME(SCHEMA_NAME(schema_id)) + '.' + QUOTENAME(name) + ';' + CHAR(13)
FROM sys.procedures
WHERE schema_id = SCHEMA_ID('dbo');

IF LEN(@sql) > 0
BEGIN
    PRINT 'Dropping stored procedures...';
    EXEC sp_executesql @sql;
    PRINT 'All stored procedures dropped successfully.';
END
ELSE
BEGIN
    PRINT 'No stored procedures found to drop.';
END

PRINT '';
PRINT '=================================================';
PRINT 'STORED PROCEDURES CLEANUP COMPLETE';
PRINT '=================================================';
GO
