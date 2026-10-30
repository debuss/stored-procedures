# Stored Procedures

A PHP package that makes working with **stored procedures** clean and effortless.

Built for teams where direct SQL queries are forbidden and all database access goes through stored procedures. Pass a `PDO` instance, call your procedure, get results — the package handles the driver-specific syntax, parameter binding, and output parameter retrieval for you.

## Features

- 🐬 **MySQL**, 🐘 **PostgreSQL** and 🪟 **SQL Server** support out of the box
- Driver auto-detection from your `PDO` connection
- Fluent `Procedure` builder with `input()` / `output()` / `inout()` chaining
- Convenient query methods: `query`, `queryFirst`, `querySingle` + `OrDefault` variants
- `OUT` and `INOUT` parameter support (driver-specific handling is abstracted away)
- Immutable `ResultSet` with multi-row-set navigation, iteration, and counting
- Named parameter validation to prevent SQL injection
- Specific exception hierarchy for clean error handling
- Requires only `ext-pdo` — no ORM, no query builder, no framework dependency
- Tested with [Pest](https://pestphp.com) + [Mockery](https://github.com/mockery/mockery)

## Requirements

- PHP **8.3+**
- `ext-pdo`
- One of: `ext-pdo_mysql`, `ext-pdo_pgsql`, `ext-pdo_sqlsrv` (or `ext-pdo_dblib`)

> [!NOTE]
> For PostgreSQL, version 14+ is required.

## Installation

```bash
composer require debuss-a/stored-procedures
```

## Quick Start

```php
use StoredProcedures\SqlConnection;

// 1. Wrap any PDO instance
$pdo = new PDO('mysql:host=127.0.0.1;dbname=demo', 'root', 'secret', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);

$connection = new SqlConnection($pdo);

// 2. Call a stored procedure
$result = $connection->query('sp_fetch_countries_by_first_letter', [
    'p_letter' => 'F',
]);

// 3. Iterate results
foreach ($result as $row) {
    echo "{$row->iso} — {$row->name}\n";
}
```

## Usage

### Querying

```php
// All rows
$result = $connection->query('sp_get_all_users');
$rows   = $result->rows();         // First (or only) row set
$count  = count($result);          // Row count
$empty  = $result->isEmpty();      // Boolean check

// First row (throws NoResultsException if empty)
$row = $connection->queryFirst('sp_find_user', ['p_id' => 42]);

// First row or default
$row = $connection->queryFirstOrDefault('sp_find_user', ['p_id' => 0], $default);

// Exactly one row (throws if 0 or 2+ rows)
$row = $connection->querySingle('sp_get_user_by_email', ['p_email' => 'a@b.com']);

// Exactly one row or default
$row = $connection->querySingleOrDefault('sp_get_user_by_email', ['p_email' => '?'], $default);
```

### Executing (INSERT / UPDATE / DELETE)

```php
$result = $connection->execute('sp_insert_user', [
    'p_name'  => 'Alice',
    'p_email' => 'alice@example.com',
]);

echo $result->getAffectedRows(); // e.g. 1
```

### Output Parameters

#### MySQL / SQL Server

OUT and INOUT parameters are retrieved automatically via the `ResultSet`:

```php
use StoredProcedures\{SqlParameter, DbType, ParameterMode};

$result = $connection->execute('sp_insert_and_return_id', [
    new SqlParameter('p_name', 'Alice', DbType::STRING),
    new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
]);

$newId = $result->getOutputValue('p_id');
```

#### PostgreSQL

Both functions and procedures are supported: the package looks up the routine in `pg_proc` and generates `SELECT * FROM fn(...)` for a function or `CALL sp(...)` for a procedure (the lookup is cached per name).

PostgreSQL returns OUT and INOUT values as **columns of the result row** — read them from the row instead of `getOutputValue()`:

```php
// Function: OUT parameters are not passed in the call
$row = $connection
    ->procedure('fn_insert_user_returning_id')
    ->input('p_name', 'Alice')
    ->output('p_id', DbType::INTEGER)
    ->single();

$newId = $row->p_id; // OUT column

// Procedure: INOUT values are passed in, OUT parameters are sent as NULL
$row = $connection
    ->procedure('sp_lookup_country_name')
    ->input('p_iso', 'FR')
    ->inout('p_name', '')
    ->single();

echo $row->p_name; // "France"
```

### Fluent Procedure Builder

For a more readable syntax, use the fluent builder:

```php
// Query
$result = $connection
    ->procedure('sp_search_users')
    ->input('p_name', 'Alice')
    ->input('p_active', true, DbType::BOOLEAN)
    ->all();

// Execute with OUT
$result = $connection
    ->procedure('sp_insert_user')
    ->input('p_name', 'Alice')
    ->output('p_id', DbType::INTEGER)
    ->all();

echo $result->getOutputValue('p_id');

// Execute with INOUT
$result = $connection
    ->procedure('sp_lookup_country_name')
    ->input('p_iso', 'FR')
    ->inout('p_name', '', DbType::STRING, 100)
    ->all();

echo $result->getOutputValue('p_name'); // "France"

// Shorthand for affected-rows-only calls
$affected = $connection
    ->procedure('sp_delete_user')
    ->input('p_id', 42)
    ->execute(); // Returns int
```

### Multi-Result-Set Procedures

Some stored procedures return more than one `SELECT`. The `ResultSet` gives you indexed access:

```php
$result = $connection->query('sp_dashboard_data');

$users  = $result->rowSet(0);        // First SELECT
$orders = $result->rowSet(1);        // Second SELECT
$count  = $result->rowSetCount();    // 2
$all    = $result->getAllRowSets();   // Nested array

// foreach always iterates the first row set
foreach ($result as $row) { /* ... */ }
```

> **Note:** Multi-row-set support requires MySQL or SQL Server. PostgreSQL functions return a single result set.

### Parameter Shorthand

You can pass a named associative array instead of `SqlParameter` objects — types are auto-detected:

```php
$result = $connection->query('sp_search', [
    'p_name'   => 'Alice',    // DbType::STRING
    'p_age'    => 30,          // DbType::INTEGER
    'p_active' => true,        // DbType::BOOLEAN
    'p_score'  => 9.5,         // DbType::FLOAT
    'p_notes'  => null,        // DbType::NULL
]);
```

For explicit control, use `SqlParameter` directly:

```php
use StoredProcedures\{SqlParameter, DbType};

$result = $connection->query('sp_search', [
    new SqlParameter('p_date', '2024-01-01', DbType::DATE),
    new SqlParameter('p_blob', $binary, DbType::BINARY),
]);
```

## Supported Drivers

| Driver | PDO name | Syntax generated         | OUT/INOUT mechanism |
|--------|----------|--------------------------|---------------------|
| **MySQL** | `mysql` | `CALL \`sp\`(:in, @out)` | `@user_variables` + `SELECT @var` |
| **PostgreSQL** | `pgsql` | `SELECT * FROM fn(:in, :inout)` / `CALL sp(:in, NULL, :inout)` | OUT/INOUT returned as result columns |
| **SQL Server** | `sqlsrv` / `dblib` | `{CALL [sp](:in, :out)}` | `PDO::PARAM_INPUT_OUTPUT` binding |

The correct driver is resolved automatically from `PDO::ATTR_DRIVER_NAME`.

## Exceptions

All exceptions extend `StoredProcedureException` for easy catch-all handling:

| Exception | Thrown when |
|-----------|------------|
| `StoredProcedureException` | Base class — invalid procedure/parameter names, numeric array keys, etc. |
| `NoResultsException` | `queryFirst()` or `querySingle()` returns zero rows |
| `MultipleResultsException` | `querySingle()` returns more than one row |
| `UnsupportedDriverException` | PDO driver is not `mysql`, `pgsql`, `sqlsrv`, or `dblib` |

```php
use StoredProcedures\Exception\{NoResultsException, MultipleResultsException};

try {
    $row = $connection->querySingle('sp_find_user', ['p_id' => 42]);
} catch (NoResultsException) {
    // 0 rows
} catch (MultipleResultsException) {
    // 2+ rows
}
```

## Testing

```bash
# Run the test suite
./vendor/bin/pest --parallel

# Run with mutation testing
XDEBUG_MODE=coverage ./vendor/bin/pest --mutate --min=80 --parallel
```

The integration tests in `tests/Integration` run against the demo databases below and are skipped when a database is unreachable. The DSNs can be overridden with the `MYSQL_DSN`, `PGSQL_DSN` and `SQLSRV_DSN` environment variables.

## Docker (demo databases)

The repository includes a `docker-compose.yml` with MySQL, PostgreSQL, and SQL Server containers — each pre-loaded with a `countries` table, seed data, and example stored procedures/functions.

```bash
docker compose -f docker/docker-compose.yml --project-directory . up -d

# Run the demo scripts
php docker/mysql/mysql.php
php docker/pgsql/pgsql.php
php docker/sqlsrv/sqlsrv.php
```

## License

MIT © Alexandre Debusschère  
The package is licensed under the MIT license. See [License File](https://github.com/debuss-a/stored-procedures/blob/master/LICENSE.md) for more information.

