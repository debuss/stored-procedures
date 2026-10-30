-- ============================================================
--  Table
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'countries')
BEGIN
    CREATE TABLE countries (
        id   INT IDENTITY(1,1) PRIMARY KEY,
        iso  VARCHAR(3)   NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL
    );
END;
GO

-- ============================================================
--  Seed data
-- ============================================================

IF NOT EXISTS (SELECT 1 FROM countries)
BEGIN
    INSERT INTO countries (iso, name) VALUES
        ('FR', 'France'),
        ('DE', 'Germany'),
        ('IT', 'Italy'),
        ('ES', 'Spain'),
        ('PT', 'Portugal'),
        ('PL', 'Poland'),
        ('NL', 'Netherlands'),
        ('BE', 'Belgium'),
        ('SE', 'Sweden'),
        ('NO', 'Norway');
END;
GO

-- ============================================================
--  Stored procedures
-- ============================================================

-- 1. Return all countries
IF OBJECT_ID('sp_fetch_all_countries', 'P') IS NOT NULL DROP PROCEDURE sp_fetch_all_countries;
GO
CREATE PROCEDURE sp_fetch_all_countries
AS
BEGIN
    SET NOCOUNT ON;
    SELECT * FROM countries ORDER BY name;
END;
GO

-- 2. Filter countries by first letter
IF OBJECT_ID('sp_fetch_countries_by_first_letter', 'P') IS NOT NULL DROP PROCEDURE sp_fetch_countries_by_first_letter;
GO
CREATE PROCEDURE sp_fetch_countries_by_first_letter
    @p_letter VARCHAR(10)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT * FROM countries WHERE name LIKE @p_letter + '%' ORDER BY name;
END;
GO

-- 3. Find a single country by ISO code
IF OBJECT_ID('sp_fetch_country_by_iso', 'P') IS NOT NULL DROP PROCEDURE sp_fetch_country_by_iso;
GO
CREATE PROCEDURE sp_fetch_country_by_iso
    @p_iso VARCHAR(3)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 * FROM countries WHERE iso = UPPER(@p_iso);
END;
GO

-- 4. Insert a country (no return)
IF OBJECT_ID('sp_insert_country', 'P') IS NOT NULL DROP PROCEDURE sp_insert_country;
GO
CREATE PROCEDURE sp_insert_country
    @p_iso  VARCHAR(3),
    @p_name VARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO countries (iso, name) VALUES (@p_iso, @p_name);
END;
GO

-- 5. Insert a country and return the new ID via OUTPUT parameter
IF OBJECT_ID('sp_insert_and_return_country', 'P') IS NOT NULL DROP PROCEDURE sp_insert_and_return_country;
GO
CREATE PROCEDURE sp_insert_and_return_country
    @p_iso        VARCHAR(3),
    @p_name       VARCHAR(100),
    @p_country_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO countries (iso, name) VALUES (@p_iso, @p_name);
    SET @p_country_id = SCOPE_IDENTITY();
END;
GO

-- 6. Delete a country by ISO code
IF OBJECT_ID('sp_delete_country', 'P') IS NOT NULL DROP PROCEDURE sp_delete_country;
GO
CREATE PROCEDURE sp_delete_country
    @p_iso VARCHAR(3)
AS
BEGIN
    SET NOCOUNT ON;
    DELETE FROM countries WHERE iso = UPPER(@p_iso);
END;
GO

-- 7. Count countries (returns a result set with a single scalar row)
IF OBJECT_ID('sp_count_countries', 'P') IS NOT NULL DROP PROCEDURE sp_count_countries;
GO
CREATE PROCEDURE sp_count_countries
AS
BEGIN
    SET NOCOUNT ON;
    SELECT COUNT(*) AS total FROM countries;
END;
GO

-- 8. INOUT example: pass an ISO, get back the country name via OUTPUT
IF OBJECT_ID('sp_search_and_return_country', 'P') IS NOT NULL DROP PROCEDURE sp_search_and_return_country;
GO
CREATE PROCEDURE sp_search_and_return_country
    @p_iso  VARCHAR(3),
    @p_name VARCHAR(100) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 @p_name = name FROM countries WHERE iso = UPPER(@p_iso);
END;
GO


-- 9. Echo a datetime (round trip of date parameters and columns)
IF OBJECT_ID('sp_echo_datetime', 'P') IS NOT NULL DROP PROCEDURE sp_echo_datetime;
GO
CREATE PROCEDURE sp_echo_datetime
    @p_value DATETIME2(0)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT @p_value AS value;
END;
GO

-- 10. Raise a business error with THROW
IF OBJECT_ID('sp_raise_error', 'P') IS NOT NULL DROP PROCEDURE sp_raise_error;
GO
CREATE PROCEDURE sp_raise_error
    @p_message NVARCHAR(2048)
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50001, @p_message, 1;
END;
GO

-- 11. Parameters with default values (any of them can be omitted when matched by name)
IF OBJECT_ID('sp_fetch_countries_page', 'P') IS NOT NULL DROP PROCEDURE sp_fetch_countries_page;
GO
CREATE PROCEDURE sp_fetch_countries_page
    @p_letter VARCHAR(10) = '',
    @p_limit  INT = 100,
    @p_offset INT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SELECT * FROM countries WHERE name LIKE @p_letter + '%' ORDER BY name
    OFFSET @p_offset ROWS FETCH NEXT @p_limit ROWS ONLY;
END;
GO

-- 12. Wait for a number of seconds (timeouts)
IF OBJECT_ID('sp_sleep', 'P') IS NOT NULL DROP PROCEDURE sp_sleep;
GO
CREATE PROCEDURE sp_sleep
    @p_seconds INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @delay DATETIME = DATEADD(SECOND, @p_seconds, 0);
    WAITFOR DELAY @delay;
    SELECT @p_seconds AS slept;
END;
GO

-- 13. Summary with a JSON column
IF OBJECT_ID('sp_fetch_countries_summary', 'P') IS NOT NULL DROP PROCEDURE sp_fetch_countries_summary;
GO
CREATE PROCEDURE sp_fetch_countries_summary
AS
BEGIN
    SET NOCOUNT ON;
    SELECT COUNT(*) AS total, '[' + STRING_AGG('"' + iso + '"', ',') WITHIN GROUP (ORDER BY iso) + ']' AS isos FROM countries;
END;
GO
