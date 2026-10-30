-- ============================================================
--  Table
-- ============================================================

CREATE TABLE IF NOT EXISTS countries (
    id   SERIAL PRIMARY KEY,
    iso  VARCHAR(3)   NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
);

-- ============================================================
--  Seed data
-- ============================================================

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
    ('NO', 'Norway')
ON CONFLICT (iso) DO NOTHING;

-- ============================================================
--  Functions
-- ============================================================

-- 1. Return all countries
CREATE OR REPLACE FUNCTION fn_fetch_all_countries()
RETURNS SETOF countries
LANGUAGE sql STABLE
AS $$
    SELECT * FROM countries ORDER BY name;
$$;

-- 2. Filter countries by first letter
CREATE OR REPLACE FUNCTION fn_fetch_countries_by_first_letter(p_letter VARCHAR)
RETURNS SETOF countries
LANGUAGE sql STABLE
AS $$
    SELECT * FROM countries WHERE name ILIKE p_letter || '%' ORDER BY name;
$$;

-- 3. Find a single country by ISO code
CREATE OR REPLACE FUNCTION fn_fetch_country_by_iso(p_iso VARCHAR)
RETURNS SETOF countries
LANGUAGE sql STABLE
AS $$
    SELECT * FROM countries WHERE iso = UPPER(p_iso) LIMIT 1;
$$;

-- 4. Insert a country and return the new row
CREATE OR REPLACE FUNCTION fn_insert_country(p_iso VARCHAR, p_name VARCHAR)
RETURNS countries
LANGUAGE sql VOLATILE
AS $$
    INSERT INTO countries (iso, name) VALUES (p_iso, p_name)
    RETURNING *;
$$;

-- 5. Delete a country by ISO code, return number of deleted rows
CREATE OR REPLACE FUNCTION fn_delete_country(p_iso VARCHAR)
RETURNS INTEGER
LANGUAGE plpgsql VOLATILE
AS $$
DECLARE
    deleted INTEGER;
BEGIN
    DELETE FROM countries WHERE iso = UPPER(p_iso);
    GET DIAGNOSTICS deleted = ROW_COUNT;
    RETURN deleted;
END;
$$;

-- 6. Count countries (scalar return)
CREATE OR REPLACE FUNCTION fn_count_countries()
RETURNS INTEGER
LANGUAGE sql STABLE
AS $$
    SELECT COUNT(*)::INTEGER FROM countries;
$$;

-- 7. Function with an OUT parameter (returned as a result column)
CREATE OR REPLACE FUNCTION fn_insert_country_returning_id(
    p_iso  VARCHAR,
    p_name VARCHAR,
    OUT    p_id INTEGER
)
LANGUAGE plpgsql VOLATILE
AS $$
BEGIN
    INSERT INTO countries (iso, name) VALUES (p_iso, p_name)
    RETURNING id INTO p_id;
END;
$$;


-- 8. Function with an INOUT parameter (the input value is kept when the ISO is unknown)
CREATE OR REPLACE FUNCTION fn_search_and_return_country(
    p_iso         VARCHAR,
    INOUT  p_name VARCHAR
)
LANGUAGE plpgsql STABLE
AS $$
BEGIN
    SELECT COALESCE((SELECT name FROM countries WHERE iso = UPPER(p_iso)), p_name) INTO p_name;
END;
$$;

-- ============================================================
--  Procedures (PostgreSQL 14+ for OUT parameters)
-- ============================================================

-- 9. Insert a country and return the new ID via OUT parameter
CREATE OR REPLACE PROCEDURE sp_insert_and_return_country(
    p_iso         VARCHAR,
    p_name        VARCHAR,
    OUT p_country_id INTEGER
)
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO countries (iso, name) VALUES (p_iso, p_name)
    RETURNING id INTO p_country_id;
END;
$$;

-- 10. INOUT example: pass an ISO, get back the country name (the input value is kept when the ISO is unknown)
CREATE OR REPLACE PROCEDURE sp_search_and_return_country(
    p_iso         VARCHAR,
    INOUT  p_name VARCHAR
)
LANGUAGE plpgsql
AS $$
BEGIN
    SELECT COALESCE((SELECT name FROM countries WHERE iso = UPPER(p_iso)), p_name) INTO p_name;
END;
$$;

-- 11. Echo a timestamp (round trip of date parameters and columns)
CREATE OR REPLACE FUNCTION fn_echo_datetime(p_value TIMESTAMP)
RETURNS TABLE (value TIMESTAMP)
LANGUAGE sql IMMUTABLE
AS $$
    SELECT p_value;
$$;

-- 12. Raise a business error with RAISE EXCEPTION
CREATE OR REPLACE FUNCTION fn_raise_error(p_message VARCHAR)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION '%', p_message;
END;
$$;

-- 13. Wait for a number of seconds (timeouts)
CREATE OR REPLACE FUNCTION fn_sleep(p_seconds INTEGER)
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM pg_sleep(p_seconds);
    RETURN p_seconds;
END;
$$;
