-- ============================================================
--  Table
-- ============================================================

CREATE TABLE IF NOT EXISTS countries (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    iso  VARCHAR(3)   NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
);

-- ============================================================
--  Seed data
-- ============================================================

INSERT IGNORE INTO countries (iso, name) VALUES
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

-- ============================================================
--  Stored procedures
-- ============================================================

-- 1. Return all countries
DROP PROCEDURE IF EXISTS sp_fetch_all_countries;
DELIMITER //
CREATE PROCEDURE sp_fetch_all_countries()
BEGIN
    SELECT * FROM countries ORDER BY name;
END //
DELIMITER ;

-- 2. Filter countries by first letter
DROP PROCEDURE IF EXISTS sp_fetch_countries_by_first_letter;
DELIMITER //
CREATE PROCEDURE sp_fetch_countries_by_first_letter(IN p_letter VARCHAR(10))
BEGIN
    SELECT * FROM countries WHERE name LIKE CONCAT(p_letter, '%') ORDER BY name;
END //
DELIMITER ;

-- 3. Find a single country by ISO code
DROP PROCEDURE IF EXISTS sp_fetch_country_by_iso;
DELIMITER //
CREATE PROCEDURE sp_fetch_country_by_iso(IN p_iso VARCHAR(3))
BEGIN
    SELECT * FROM countries WHERE iso = UPPER(p_iso) LIMIT 1;
END //
DELIMITER ;

-- 4. Insert a country (no return)
DROP PROCEDURE IF EXISTS sp_insert_country;
DELIMITER //
CREATE PROCEDURE sp_insert_country(IN p_iso VARCHAR(3), IN p_name VARCHAR(100))
BEGIN
    INSERT INTO countries (iso, name) VALUES (p_iso, p_name);
END //
DELIMITER ;

-- 5. Insert a country and return the new ID via OUT parameter
DROP PROCEDURE IF EXISTS sp_insert_and_return_country;
DELIMITER //
CREATE PROCEDURE sp_insert_and_return_country(
    IN  p_iso        VARCHAR(3),
    IN  p_name       VARCHAR(100),
    OUT p_country_id INT
)
BEGIN
    INSERT INTO countries (iso, name) VALUES (p_iso, p_name);
    SET p_country_id = LAST_INSERT_ID();
END //
DELIMITER ;

-- 6. Delete a country by ISO code
DROP PROCEDURE IF EXISTS sp_delete_country;
DELIMITER //
CREATE PROCEDURE sp_delete_country(IN p_iso VARCHAR(3))
BEGIN
    DELETE FROM countries WHERE iso = UPPER(p_iso);
END //
DELIMITER ;

-- 7. Count countries (returns a result set with a single scalar row)
DROP PROCEDURE IF EXISTS sp_count_countries;
DELIMITER //
CREATE PROCEDURE sp_count_countries()
BEGIN
    SELECT COUNT(*) AS total FROM countries;
END //
DELIMITER ;

-- 8. INOUT example: pass an ISO, get back the country name
DROP PROCEDURE IF EXISTS sp_search_and_return_country;
DELIMITER //
CREATE PROCEDURE sp_search_and_return_country(
    IN    p_iso  VARCHAR(3),
    INOUT p_name VARCHAR(100)
)
BEGIN
    SELECT name INTO p_name FROM countries WHERE iso = UPPER(p_iso) LIMIT 1;
END //
DELIMITER ;


-- 9. Echo a datetime (round trip of date parameters and columns)
DROP PROCEDURE IF EXISTS sp_echo_datetime;
DELIMITER //
CREATE PROCEDURE sp_echo_datetime(IN p_value DATETIME)
BEGIN
    SELECT p_value AS value;
END //
DELIMITER ;

-- 10. Raise a business error with SIGNAL
DROP PROCEDURE IF EXISTS sp_raise_error;
DELIMITER //
CREATE PROCEDURE sp_raise_error(IN p_message VARCHAR(255))
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = p_message;
END //
DELIMITER ;
