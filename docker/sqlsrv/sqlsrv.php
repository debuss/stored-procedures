<?php

use StoredProcedures\DbType;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

require_once __DIR__ . '/../../vendor/autoload.php';

$pdo = new PDO(
    'sqlsrv:Server=127.0.0.1,1433;Database=demo;TrustServerCertificate=1',
    'sa',
    'Demo@12345',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ]
);

$connection = new SqlConnection($pdo);

// ---------------------------------------------------------------
//  1. query() — fetch all countries
// ---------------------------------------------------------------
echo "=== All countries ===" . PHP_EOL;

$result = $connection->query('sp_fetch_all_countries');

echo "Row count: " . count($result) . PHP_EOL;

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  2. query() with named parameter — filter by first letter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'P' ===" . PHP_EOL;

$result = $connection->query('sp_fetch_countries_by_first_letter', [
    'p_letter' => 'P',
]);

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  3. query() with SqlParameter object
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'S' (SqlParameter) ===" . PHP_EOL;

$result = $connection->query('sp_fetch_countries_by_first_letter', [
    new SqlParameter('p_letter', 'S', DbType::STRING),
]);

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  4. queryFirst() — get the first row
// ---------------------------------------------------------------
echo PHP_EOL . "=== First country starting with 'N' ===" . PHP_EOL;

$row = $connection->queryFirst('sp_fetch_countries_by_first_letter', [
    'p_letter' => 'N',
]);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  5. querySingle() — get exactly one row (by ISO)
// ---------------------------------------------------------------
echo PHP_EOL . "=== Single country by ISO 'FR' ===" . PHP_EOL;

$row = $connection->querySingle('sp_fetch_country_by_iso', [
    'p_iso' => 'FR',
]);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  6. queryFirstOrDefault() — returns default when no match
// ---------------------------------------------------------------
echo PHP_EOL . "=== Country starting with 'Z' (or default) ===" . PHP_EOL;

$default = (object) ['iso' => '??', 'name' => 'Unknown'];
$row = $connection->queryFirstOrDefault(
    'sp_fetch_countries_by_first_letter',
    ['p_letter' => 'Z'],
    $default
);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  7. Scalar — count countries
// ---------------------------------------------------------------
echo PHP_EOL . "=== Count countries ===" . PHP_EOL;

$row = $connection->queryFirst('sp_count_countries');

echo "  Total: {$row->total}" . PHP_EOL;

// ---------------------------------------------------------------
//  8. execute() — insert (no result set, just affected rows)
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'FI' Finland ===" . PHP_EOL;

$result = $connection->execute('sp_insert_country', [
    'p_iso'  => 'FI',
    'p_name' => 'Finland',
]);

echo "  Affected rows: {$result->getAffectedRows()}" . PHP_EOL;

// ---------------------------------------------------------------
//  9. execute() with OUTPUT parameter — insert and get new ID
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'DK' Denmark with OUTPUT p_country_id ===" . PHP_EOL;

$result = $connection->execute('sp_insert_and_return_country', [
    new SqlParameter('p_iso', 'DK', DbType::STRING),
    new SqlParameter('p_name', 'Denmark', DbType::STRING),
    new SqlParameter('p_country_id', null, DbType::INTEGER, ParameterMode::OUT),
]);

echo "  New ID (from OUTPUT): " . $result->getOutputValue('p_country_id') . PHP_EOL;

// ---------------------------------------------------------------
//  10. execute() with OUTPUT parameter — lookup country name by ISO
//      (SQL Server uses OUTPUT, not INOUT — but the driver handles
//      both OUT and INOUT the same way via PDO::PARAM_INPUT_OUTPUT)
// ---------------------------------------------------------------
echo PHP_EOL . "=== OUTPUT — search country name for ISO 'FR' ===" . PHP_EOL;

$result = $connection->execute('sp_search_and_return_country', [
    new SqlParameter('p_iso', 'FR', DbType::STRING),
    new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT, 100),
]);

echo "  Country name (from OUTPUT): " . $result->getOutputValue('p_name') . PHP_EOL;

// ---------------------------------------------------------------
//  11. Fluent Procedure builder — query
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — countries starting with 'B' ===" . PHP_EOL;

$result = $connection
    ->procedure('sp_fetch_countries_by_first_letter')
    ->input('p_letter', 'B')
    ->all();

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  12. Fluent Procedure builder — insert with OUTPUT
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — insert 'AT' Austria with OUTPUT ===" . PHP_EOL;

$result = $connection
    ->procedure('sp_insert_and_return_country')
    ->input('p_iso', 'AT')
    ->input('p_name', 'Austria')
    ->output('p_country_id', DbType::INTEGER)
    ->all();

echo "  New ID (from OUTPUT): " . $result->getOutputValue('p_country_id') . PHP_EOL;

// ---------------------------------------------------------------
//  13. Delete
// ---------------------------------------------------------------
echo PHP_EOL . "=== Delete 'FI' ===" . PHP_EOL;

$result = $connection->execute('sp_delete_country', ['p_iso' => 'FI']);

echo "  Affected rows: {$result->getAffectedRows()}" . PHP_EOL;

// ---------------------------------------------------------------
//  Final count
// ---------------------------------------------------------------
echo PHP_EOL . "=== Final count ===" . PHP_EOL;

$row = $connection->queryFirst('sp_count_countries');
echo "  Total: {$row->total}" . PHP_EOL;

