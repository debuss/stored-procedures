<?php

use StoredProcedures\DbType;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

require_once __DIR__ . '/../../vendor/autoload.php';

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=demo',
    'demo',
    'demo',
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

$result = $connection->query('fn_fetch_all_countries');

echo "Row count: " . count($result) . PHP_EOL;

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  2. query() with named parameter — filter by first letter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'P' ===" . PHP_EOL;

$result = $connection->query('fn_fetch_countries_by_first_letter', [
    'p_letter' => 'P',
]);

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  3. query() with SqlParameter object
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'S' (SqlParameter) ===" . PHP_EOL;

$result = $connection->query('fn_fetch_countries_by_first_letter', [
    new SqlParameter('p_letter', 'S', DbType::STRING),
]);

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  4. queryFirst() — get the first row
// ---------------------------------------------------------------
echo PHP_EOL . "=== First country starting with 'N' ===" . PHP_EOL;

$row = $connection->queryFirst('fn_fetch_countries_by_first_letter', [
    'p_letter' => 'N',
]);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  5. querySingle() — get exactly one row (by ISO)
// ---------------------------------------------------------------
echo PHP_EOL . "=== Single country by ISO 'FR' ===" . PHP_EOL;

$row = $connection->querySingle('fn_fetch_country_by_iso', [
    'p_iso' => 'FR',
]);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  6. queryFirstOrDefault() — returns default when no match
// ---------------------------------------------------------------
echo PHP_EOL . "=== Country starting with 'Z' (or default) ===" . PHP_EOL;

$default = (object) ['iso' => '??', 'name' => 'Unknown'];
$row = $connection->queryFirstOrDefault(
    'fn_fetch_countries_by_first_letter',
    ['p_letter' => 'Z'],
    $default
);

echo "  [{$row->iso}] {$row->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  7. Scalar function — count
// ---------------------------------------------------------------
echo PHP_EOL . "=== Count countries ===" . PHP_EOL;

$row = $connection->queryFirst('fn_count_countries');

echo "  Total: {$row->fn_count_countries}" . PHP_EOL;

// ---------------------------------------------------------------
//  8. Insert via query() — PG RETURNS the new row
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'FI' Finland ===" . PHP_EOL;

$result = $connection->query('fn_insert_country', [
    'p_iso'  => 'FI',
    'p_name' => 'Finland',
]);

$newRow = $result->rows()[0] ?? null;
echo "  Inserted: [{$newRow->iso}] {$newRow->name} (id={$newRow->id})" . PHP_EOL;

// ---------------------------------------------------------------
//  9. OUT parameter — returned as a result column in PG
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'DK' Denmark with OUT p_id ===" . PHP_EOL;

$result = $connection->query('fn_insert_country_returning_id', [
    'p_iso'  => 'DK',
    'p_name' => 'Denmark',
]);

$row = $result->rows()[0];
echo "  New ID (from OUT column): {$row->p_id}" . PHP_EOL;

// ---------------------------------------------------------------
//  10. Fluent Procedure builder
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — countries starting with 'B' ===" . PHP_EOL;

$result = $connection
    ->procedure('fn_fetch_countries_by_first_letter')
    ->input('p_letter', 'B')
    ->all();

foreach ($result as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  11. Fluent builder — insert + read result
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — insert 'AT' Austria ===" . PHP_EOL;

$result = $connection
    ->procedure('fn_insert_country')
    ->input('p_iso', 'AT')
    ->input('p_name', 'Austria')
    ->all();

$newRow = $result->rows()[0];
echo "  Inserted: [{$newRow->iso}] {$newRow->name} (id={$newRow->id})" . PHP_EOL;

// ---------------------------------------------------------------
//  12. Delete — scalar return via query
// ---------------------------------------------------------------
echo PHP_EOL . "=== Delete 'FI' ===" . PHP_EOL;

$result = $connection->query('fn_delete_country', ['p_iso' => 'FI']);
$row = $result->rows()[0];
echo "  Deleted rows: {$row->fn_delete_country}" . PHP_EOL;

// ---------------------------------------------------------------
//  Final count
// ---------------------------------------------------------------
echo PHP_EOL . "=== Final count ===" . PHP_EOL;

$row = $connection->queryFirst('fn_count_countries');
echo "  Total: {$row->fn_count_countries}" . PHP_EOL;


