<?php

use StoredProcedures\DbType;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=demo;charset=utf8mb4',
    'root',
    'demo',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ]
);

$connection = new SqlConnection($pdo);

// The demo runs in a transaction rolled back at the end, so it leaves the database unchanged
$pdo->beginTransaction();

// ---------------------------------------------------------------
//  1. query() — fetch all countries as Country objects
// ---------------------------------------------------------------
echo "=== All countries ===" . PHP_EOL;

$countries = $connection->query('sp_fetch_all_countries', [], Country::class);

echo "Row count: " . count($countries) . PHP_EOL;

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name} (id={$country->id})" . PHP_EOL;
}

// ---------------------------------------------------------------
//  2. query() with named parameter — filter by first letter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'P' ===" . PHP_EOL;

$countries = $connection->query('sp_fetch_countries_by_first_letter', [
    'p_letter' => 'P',
], Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  3. query() with SqlParameter object — explicit type
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'S' (SqlParameter) ===" . PHP_EOL;

$countries = $connection->query('sp_fetch_countries_by_first_letter', [
    new SqlParameter('p_letter', 'S', DbType::STRING),
], Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  4. Rows as fetched — without class, the PDO fetch mode applies
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'N' (stdClass rows) ===" . PHP_EOL;

foreach ($connection->query('sp_fetch_countries_by_first_letter', ['p_letter' => 'N']) as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  5. queryFirst() — get the first row
// ---------------------------------------------------------------
echo PHP_EOL . "=== First country starting with 'N' ===" . PHP_EOL;

$country = $connection->queryFirst('sp_fetch_countries_by_first_letter', [
    'p_letter' => 'N',
], Country::class);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  6. querySingle() with a backed enum parameter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Single country by ISO Iso::France ===" . PHP_EOL;

$country = $connection->querySingle('sp_fetch_country_by_iso', [
    'p_iso' => Iso::France,
], Country::class);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  7. queryFirstOrDefault() — returns the default when no match
// ---------------------------------------------------------------
echo PHP_EOL . "=== Country starting with 'Z' (or default) ===" . PHP_EOL;

$country = $connection->queryFirstOrDefault(
    'sp_fetch_countries_by_first_letter',
    ['p_letter' => 'Z'],
    new Country(0, '??', 'Unknown'),
    Country::class
);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  8. queryScalar() — count countries
// ---------------------------------------------------------------
echo PHP_EOL . "=== Count countries ===" . PHP_EOL;

echo "  Total: " . $connection->queryScalar('sp_count_countries') . PHP_EOL;

// ---------------------------------------------------------------
//  9. execute() — insert (no result set, just affected rows)
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'FI' Finland ===" . PHP_EOL;

$result = $connection->execute('sp_insert_country', [
    'p_iso'  => 'FI',
    'p_name' => 'Finland',
]);

echo "  Affected rows: {$result->getAffectedRows()}" . PHP_EOL;

// ---------------------------------------------------------------
//  10. execute() with OUT parameter — insert and get new ID
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'IE' Ireland with OUT p_country_id ===" . PHP_EOL;

$result = $connection->execute('sp_insert_and_return_country', [
    new SqlParameter('p_iso', 'IE', DbType::STRING),
    new SqlParameter('p_name', 'Ireland', DbType::STRING),
    new SqlParameter('p_country_id', null, DbType::INTEGER, ParameterMode::OUT),
]);

echo "  New ID (from OUT): " . $result->getOutputValue('p_country_id') . PHP_EOL;

// ---------------------------------------------------------------
//  11. execute() with INOUT parameter — lookup country name by ISO
// ---------------------------------------------------------------
echo PHP_EOL . "=== INOUT — search country name for ISO 'FR' ===" . PHP_EOL;

$result = $connection->execute('sp_search_and_return_country', [
    new SqlParameter('p_iso', 'FR', DbType::STRING),
    new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT, 100),
]);

echo "  Country name (from INOUT): " . $result->getOutputValue('p_name') . PHP_EOL;

// ---------------------------------------------------------------
//  12. Fluent Procedure builder — query
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — countries starting with 'B' ===" . PHP_EOL;

$countries = $connection
    ->procedure('sp_fetch_countries_by_first_letter')
    ->input('p_letter', 'B')
    ->all(Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  13. Fluent Procedure builder — insert with OUT
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — insert 'GR' Greece with OUT ===" . PHP_EOL;

$result = $connection
    ->procedure('sp_insert_and_return_country')
    ->input('p_iso', 'GR')
    ->input('p_name', 'Greece')
    ->output('p_country_id', DbType::INTEGER)
    ->execute();

echo "  New ID (from OUT): " . $result->getOutputValue('p_country_id') . PHP_EOL;

// ---------------------------------------------------------------
//  14. Fluent Procedure builder — single typed row and scalar
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — single country by ISO Iso::Germany ===" . PHP_EOL;

$country = $connection
    ->procedure('sp_fetch_country_by_iso')
    ->input('p_iso', Iso::Germany)
    ->single(Country::class);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
echo "  Total: " . $connection->procedure('sp_count_countries')->scalar() . PHP_EOL;

// ---------------------------------------------------------------
//  15. Delete
// ---------------------------------------------------------------
echo PHP_EOL . "=== Delete 'FI' ===" . PHP_EOL;

$result = $connection->execute('sp_delete_country', ['p_iso' => 'FI']);

echo "  Affected rows: {$result->getAffectedRows()}" . PHP_EOL;

// ---------------------------------------------------------------
//  Final count
// ---------------------------------------------------------------
echo PHP_EOL . "=== Final count ===" . PHP_EOL;

echo "  Total: " . $connection->queryScalar('sp_count_countries') . PHP_EOL;

$pdo->rollBack();
echo PHP_EOL . "Transaction rolled back: the database is unchanged." . PHP_EOL;
