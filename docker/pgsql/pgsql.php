<?php

use StoredProcedures\DbType;
use StoredProcedures\Exception\ExecutionException;
use StoredProcedures\Exception\ProcedureErrorException;
use StoredProcedures\Exception\TimeoutException;
use StoredProcedures\Hydrator;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models.php';

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

// The demo runs in a transaction rolled back at the end, so it leaves the database unchanged
$pdo->beginTransaction();

// ---------------------------------------------------------------
//  1. query() — fetch all countries as Country objects
// ---------------------------------------------------------------
echo "=== All countries ===" . PHP_EOL;

$countries = $connection->query('fn_fetch_all_countries', [], Country::class);

echo "Row count: " . count($countries) . PHP_EOL;

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name} (id={$country->id})" . PHP_EOL;
}

// ---------------------------------------------------------------
//  2. query() with named parameter — filter by first letter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'P' ===" . PHP_EOL;

$countries = $connection->query('fn_fetch_countries_by_first_letter', [
    'p_letter' => 'P',
], Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  3. query() with SqlParameter object — explicit type
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'S' (SqlParameter) ===" . PHP_EOL;

$countries = $connection->query('fn_fetch_countries_by_first_letter', [
    new SqlParameter('p_letter', 'S', DbType::STRING),
], Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  4. Rows as fetched — without class, the PDO fetch mode applies
// ---------------------------------------------------------------
echo PHP_EOL . "=== Countries starting with 'N' (stdClass rows) ===" . PHP_EOL;

foreach ($connection->query('fn_fetch_countries_by_first_letter', ['p_letter' => 'N']) as $row) {
    echo "  [{$row->iso}] {$row->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  5. queryFirst() — get the first row
// ---------------------------------------------------------------
echo PHP_EOL . "=== First country starting with 'N' ===" . PHP_EOL;

$country = $connection->queryFirst('fn_fetch_countries_by_first_letter', [
    'p_letter' => 'N',
], Country::class);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  6. querySingle() with a backed enum parameter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Single country by ISO Iso::France ===" . PHP_EOL;

$country = $connection->querySingle('fn_fetch_country_by_iso', [
    'p_iso' => Iso::France,
], Country::class);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  7. queryFirstOrDefault() — returns the default when no match
// ---------------------------------------------------------------
echo PHP_EOL . "=== Country starting with 'Z' (or default) ===" . PHP_EOL;

$country = $connection->queryFirstOrDefault(
    'fn_fetch_countries_by_first_letter',
    ['p_letter' => 'Z'],
    new Country(0, '??', 'Unknown'),
    Country::class
);

echo "  [{$country->iso}] {$country->name}" . PHP_EOL;

// ---------------------------------------------------------------
//  8. queryScalar() — scalar function
// ---------------------------------------------------------------
echo PHP_EOL . "=== Count countries ===" . PHP_EOL;

echo "  Total: " . $connection->queryScalar('fn_count_countries') . PHP_EOL;

// ---------------------------------------------------------------
//  9. Insert — the function RETURNS the new row, mapped to a Country
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'FI' Finland ===" . PHP_EOL;

$country = $connection->querySingle('fn_insert_country', [
    'p_iso'  => 'FI',
    'p_name' => 'Finland',
], Country::class);

echo "  Inserted: [{$country->iso}] {$country->name} (id={$country->id})" . PHP_EOL;

// ---------------------------------------------------------------
//  10. Function OUT parameter — read as an output value
// ---------------------------------------------------------------
echo PHP_EOL . "=== Insert 'IE' Ireland with OUT p_id ===" . PHP_EOL;

$result = $connection
    ->procedure('fn_insert_country_returning_id')
    ->input('p_iso', 'IE')
    ->input('p_name', 'Ireland')
    ->output('p_id', DbType::INTEGER)
    ->execute();

echo "  New ID (from OUT): " . $result->getOutputValue('p_id') . PHP_EOL;

// ---------------------------------------------------------------
//  11. Function INOUT parameter — the input value is kept for an unknown ISO
// ---------------------------------------------------------------
echo PHP_EOL . "=== INOUT — search country name for ISO 'ZZ' ===" . PHP_EOL;

$result = $connection
    ->procedure('fn_search_and_return_country')
    ->input('p_iso', 'ZZ')
    ->inout('p_name', 'Unknown')
    ->execute();

echo "  Country name (from INOUT): " . $result->getOutputValue('p_name') . PHP_EOL;

// ---------------------------------------------------------------
//  12. Procedure (CALL) with OUT parameter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Procedure — insert 'GR' Greece with OUT p_country_id ===" . PHP_EOL;

$result = $connection
    ->procedure('sp_insert_and_return_country')
    ->input('p_iso', 'GR')
    ->input('p_name', 'Greece')
    ->output('p_country_id', DbType::INTEGER)
    ->execute();

echo "  New ID (from OUT): " . $result->getOutputValue('p_country_id') . PHP_EOL;

// ---------------------------------------------------------------
//  13. Procedure (CALL) with INOUT parameter — parameters are matched
//      by name, so their order does not matter
// ---------------------------------------------------------------
echo PHP_EOL . "=== Procedure — INOUT search for ISO Iso::Germany ===" . PHP_EOL;

$result = $connection
    ->procedure('sp_search_and_return_country')
    ->inout('p_name', 'Unknown')
    ->input('p_iso', Iso::Germany)
    ->execute();

echo "  Country name (from INOUT): " . $result->getOutputValue('p_name') . PHP_EOL;

// ---------------------------------------------------------------
//  14. Fluent Procedure builder — query
// ---------------------------------------------------------------
echo PHP_EOL . "=== Fluent builder — countries starting with 'B' ===" . PHP_EOL;

$countries = $connection
    ->procedure('fn_fetch_countries_by_first_letter')
    ->input('p_letter', 'B')
    ->all(Country::class);

foreach ($countries as $country) {
    echo "  [{$country->iso}] {$country->name}" . PHP_EOL;
}

// ---------------------------------------------------------------
//  15. Delete — the function returns the number of deleted rows
// ---------------------------------------------------------------
echo PHP_EOL . "=== Delete 'FI' ===" . PHP_EOL;

$deleted = $connection->procedure('fn_delete_country')->input('p_iso', 'FI')->scalar();

echo "  Deleted rows: {$deleted}" . PHP_EOL;

// ---------------------------------------------------------------
//  Final count
// ---------------------------------------------------------------
echo PHP_EOL . "=== Final count ===" . PHP_EOL;

echo "  Total: " . $connection->queryScalar('fn_count_countries') . PHP_EOL;

$pdo->rollBack();
echo PHP_EOL . "Transaction rolled back: the database is unchanged." . PHP_EOL;

// The following calls only read the database: they run outside the transaction,
// as an error or a timeout aborts the current PostgreSQL transaction

// ---------------------------------------------------------------
//  16. Error raised by the function — ProcedureErrorException
// ---------------------------------------------------------------
echo PHP_EOL . "=== Error raised by the function (RAISE EXCEPTION) ===" . PHP_EOL;

try {
    $connection->execute('fn_raise_error', ['p_message' => 'Country ZZ not found']);
} catch (ProcedureErrorException $e) {
    // PostgreSQL has no error code besides the SQLSTATE
    echo "  {$e->getMessage()} (procedure: {$e->getProcedure()}, SQLSTATE: {$e->getSqlState()}, code: " . ($e->getDriverCode() ?? 'none') . ")" . PHP_EOL;
}

// ---------------------------------------------------------------
//  17. Other database error — ExecutionException
// ---------------------------------------------------------------
echo PHP_EOL . "=== Unknown function ===" . PHP_EOL;

try {
    $connection->execute('fn_does_not_exist');
} catch (ExecutionException $e) {
    echo "  {$e->getMessage()} (SQLSTATE: {$e->getSqlState()})" . PHP_EOL;
}

// ---------------------------------------------------------------
//  18. #[Column] attribute, type handler and Stringable parameter
// ---------------------------------------------------------------
echo PHP_EOL . "=== #[Column] and type handler ===" . PHP_EOL;

Hydrator::addTypeHandler(IsoCode::class, fn(string $value): IsoCode => new IsoCode($value));

$view = $connection
    ->procedure('fn_fetch_country_by_iso')
    ->input('p_iso', new IsoCode('IT'))
    ->single(CountryView::class);

echo "  " . $view->code::class . "({$view->code}) — {$view->label}" . PHP_EOL;

// ---------------------------------------------------------------
//  19. JSON column decoded to an array
// ---------------------------------------------------------------
echo PHP_EOL . "=== JSON column ===" . PHP_EOL;

$summary = $connection->querySingle('fn_fetch_countries_summary', [], CountrySummary::class);

echo "  {$summary->total} countries: " . implode(', ', $summary->isos) . PHP_EOL;

// ---------------------------------------------------------------
//  20. PSR-3 logging — parameter names are logged, never their values
// ---------------------------------------------------------------
echo PHP_EOL . "=== Logging ===" . PHP_EOL;

$logged = new SqlConnection($pdo, new EchoLogger());

$logged->queryScalar('fn_count_countries');

try {
    $logged->execute('fn_raise_error', ['p_message' => 'Country ZZ not found']);
} catch (ProcedureErrorException) {
    // Logged as a warning
}

// ---------------------------------------------------------------
//  21. Timeouts — the 3 seconds call is canceled after 1 second
// ---------------------------------------------------------------
echo PHP_EOL . "=== Timeout ===" . PHP_EOL;

$start = microtime(true);

try {
    $connection->procedure('fn_sleep')->timeout(1)->input('p_seconds', 3)->execute();
} catch (TimeoutException $e) {
    printf("  Canceled after %.1f s: %s" . PHP_EOL, microtime(true) - $start, $e->getMessage());
}

echo "  Within the timeout: slept " . $connection->withTimeout(5)->procedure('fn_sleep')->input('p_seconds', 1)->scalar() . " s" . PHP_EOL;
echo "  statement_timeout restored: " . $pdo->query('SHOW statement_timeout')->fetchColumn() . PHP_EOL;
