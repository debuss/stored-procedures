# Stored Procedures

A PHP package that makes working with **stored procedures** clean and effortless.

Built for teams where direct SQL queries are forbidden and all database access goes through stored procedures. Pass a `PDO` instance, call your procedure, get results — the package handles the driver-specific syntax, parameter binding, and output parameter retrieval for you.

## Features

- 🐬 **MySQL**, 🐘 **PostgreSQL** and 🪟 **SQL Server** support out of the box
- Driver auto-detection from your `PDO` connection
- Fluent `Procedure` builder with `input()` / `output()` / `inout()` chaining
- Convenient query methods: `query`, `queryFirst`, `querySingle` + `OrDefault` variants, and `queryScalar`
- Typed rows: map rows to your own classes (readonly DTOs welcome), with PHPStan/IDE type inference
- `OUT` and `INOUT` parameter support (driver-specific handling is abstracted away)
- Immutable `ResultSet` with multi-row-set navigation, iteration, and counting
- Named parameter validation to prevent SQL injection
- Specific exception hierarchy for clean error handling, with the business errors raised by the procedures told apart
- Optional parameter matching by name on MySQL and SQL Server, like `SqlCommand` in .NET
- [PSR-3](https://www.php-fig.org/psr/psr-3/) logging of the calls and failures
- Requires only `ext-pdo` and `psr/log` — no ORM, no query builder, no framework dependency
- Tested with [Pest](https://pestphp.com) + [Mockery](https://github.com/mockery/mockery)

## Requirements

- PHP **8.3+**
- `ext-pdo`
- One of: `ext-pdo_mysql`, `ext-pdo_pgsql`, `ext-pdo_sqlsrv` (or `ext-pdo_dblib`)
- A `PDO` instance in the `PDO::ERRMODE_EXCEPTION` error mode (the default since PHP 8)

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

### Typed Rows

Pass a class to map the rows to objects. Columns are matched to the constructor parameters by name, ignoring case and underscores (`country_id` fills `$countryId`):

```php
final readonly class Country
{
    public function __construct(
        public int $id,
        public string $iso,
        public string $name,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}

$countries = $connection->query('sp_fetch_all_countries', [], Country::class)->rows(); // list<Country>
$country   = $connection->querySingle('sp_fetch_country_by_iso', ['p_iso' => 'FR'], Country::class);
$country   = $connection->queryFirstOrDefault('sp_fetch_country_by_iso', ['p_iso' => 'ZZ'], class: Country::class); // ?Country

// With the fluent builder
$country = $connection
    ->procedure('sp_fetch_country_by_iso')
    ->input('p_iso', 'FR')
    ->single(Country::class);

foreach ($connection->procedure('sp_fetch_all_countries')->all(Country::class) as $country) {
    echo $country->name;
}
```

Mapping rules:

- Scalar values are coerced to the declared types (`"42"` → `int`), string values are converted to **backed enums** (`from()`) and to **`DateTimeInterface`** implementations (`DateTimeInterface` itself gives a `DateTimeImmutable`).
- Extra columns are ignored. A missing column keeps the default value of an optional parameter, and throws a `MappingException` for a required one.
- A class without constructor parameters gets its public, non-readonly properties assigned instead.
- With multiple row sets, the class applies to all of them; override it per row set with `$result->rowSet(1, Order::class)`.

The return types are generic: PHPStan and your IDE know that `query(..., Country::class)->rows()` is a `list<Country>`.

### Scalar Values

```php
$total = $connection->queryScalar('sp_count_countries');        // first column of the first row, or null
$total = $connection->procedure('sp_count_countries')->scalar();
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

OUT and INOUT parameters are retrieved via the `ResultSet`, whatever the database:

```php
use StoredProcedures\{SqlParameter, DbType, ParameterMode};

$result = $connection->execute('sp_insert_and_return_id', [
    new SqlParameter('p_name', 'Alice', DbType::STRING),
    new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
]);

$newId = $result->getOutputValue('p_id');
```

`getOutputValue()` throws an `OutOfRangeException` when no OUT or INOUT parameter has the given name.

#### PostgreSQL

Both functions and procedures are supported: the package looks up the routine in `pg_proc` and generates `SELECT * FROM fn(...)` for a function or `CALL sp(...)` for a procedure (the lookup is cached per name).

PostgreSQL returns OUT and INOUT values as **columns of the result row**: they are available both as output values and in the rows.

```php
$row = $connection
    ->procedure('fn_insert_user_returning_id')
    ->input('p_name', 'Alice')
    ->output('p_id', DbType::INTEGER)
    ->single();

$newId = $row->p_id; // OUT column
```

### Parameter Order

| Driver | Parameters are matched… |
|--------|-------------------------|
| **PostgreSQL** | **by name** (named notation `fn(p_name => :p_name)`): the order does not matter, but the names must match the routine's argument names |
| **MySQL** / **SQL Server** | **by position** by default: pass them in the order they are declared in the procedure. **By name** with `match_parameters_by_name: true` |

> [!WARNING]
> By default, with MySQL and SQL Server, parameter names are only used as placeholders. Passing parameters in the wrong order silently swaps their values.

To match them by name, like `SqlCommand` with `CommandType.StoredProcedure` in .NET, enable the `match_parameters_by_name` option:

```php
$connection = new SqlConnection($pdo, match_parameters_by_name: true);

// Any order, names matched case-insensitively
$connection->execute('sp_insert_country', ['p_name' => 'Finland', 'p_iso' => 'FI']);

// A typo is reported before the call:
// "Procedure 'sp_insert_country' has no parameter named 'p_nme'. Declared parameters: p_iso, p_name."
$connection->execute('sp_insert_country', ['p_iso' => 'FI', 'p_nme' => 'Finland']);
```

- The declared parameters are read from `INFORMATION_SCHEMA` once per procedure and connection (one extra query per procedure).
- **SQL Server**: the parameters not given use their default value (`DEFAULT`). Catalog-qualified names (`db.dbo.sp`) are not looked up and keep the given order.
- **MySQL**: procedures have no default values, so every declared parameter must be given.
- A procedure not found in `INFORMATION_SCHEMA` (e.g. not visible to the user) keeps the given order: the database reports the error, if any.

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
    ->execute();

echo $result->getOutputValue('p_id');

// Execute with INOUT
$result = $connection
    ->procedure('sp_lookup_country_name')
    ->input('p_iso', 'FR')
    ->inout('p_name', '', DbType::STRING, 100)
    ->all();

echo $result->getOutputValue('p_name'); // "France"

// Affected rows
$affected = $connection
    ->procedure('sp_delete_user')
    ->input('p_id', 42)
    ->execute()
    ->getAffectedRows();
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
    'p_since'  => new DateTimeImmutable('2024-01-01'), // DbType::DATETIME, bound as '2024-01-01 00:00:00'
    'p_status' => Status::Active, // Backed enum: bound as its value, typed after it
]);
```

The fluent builder infers the type the same way when none is given: `->input('p_age', 30)` is bound as an integer.

Dates are bound as `'Y-m-d H:i:s'` in their own timezone (`'Y-m-d'` with `DbType::DATE`), without fractional seconds.

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
| **PostgreSQL** | `pgsql` | `SELECT * FROM fn(p_in => :p_in)` / `CALL sp(p_in => :p_in, p_out => NULL)` | OUT/INOUT read from the result columns |
| **SQL Server** | `sqlsrv` / `dblib` | `{CALL [sp](:in, :out)}` | `PDO::PARAM_INPUT_OUTPUT` binding |

The correct driver is resolved automatically from `PDO::ATTR_DRIVER_NAME`.

## Error Handling

The errors reported by the database are converted to an `ExecutionException`, with the procedure name, the SQLSTATE, the database error code and a clean message (without the driver prefixes such as `[Microsoft][ODBC Driver 18 for SQL Server]`). The original `PDOException` is available with `getPrevious()`.

The errors **raised on purpose by the procedure**, usually business rule violations, are converted to its subclass `ProcedureErrorException`:

| Database | Raised with | Recognized by |
|----------|-------------|---------------|
| **MySQL** | `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '…'` | SQLSTATE `45000` or error code 1644 |
| **PostgreSQL** | `RAISE EXCEPTION '…'` | SQLSTATE `P0001` |
| **SQL Server** | `THROW 50001, '…', 1` or `RAISERROR('…', 16, 1)` | error number ≥ 50000 |

```php
use StoredProcedures\Exception\{ExecutionException, ProcedureErrorException};

try {
    $connection->execute('sp_transfer', ['p_from' => 1, 'p_to' => 2, 'p_amount' => 500]);
} catch (ProcedureErrorException $e) {
    // Business error: show $e->getMessage() ("Insufficient balance")
} catch (ExecutionException $e) {
    // Technical error: $e->getProcedure(), $e->getSqlState(), $e->getDriverCode()
}
```

`getDriverCode()` returns `null` with PostgreSQL, which has no error codes besides the SQLSTATE.

## Logging

Pass a [PSR-3](https://www.php-fig.org/psr/psr-3/) logger (Monolog, the Laravel or Symfony logger…) to the constructor, or with `setLogger()`:

```php
$connection = new SqlConnection($pdo, $logger);
```

| Event | Level | Context |
|-------|-------|---------|
| Successful call | `debug` | `procedure`, `parameters`, `duration_ms` |
| Error raised by the procedure (`ProcedureErrorException`) | `warning` | + `message`, `sql_state`, `exception` |
| Other failure | `error` | + `message`, `sql_state`, `exception` |

Only the parameter **names** are logged, never their values, which may hold personal data or credentials.

## Exceptions

All exceptions extend `StoredProcedureException` for easy catch-all handling:

| Exception | Thrown when |
|-----------|------------|
| `StoredProcedureException` | Base class — invalid procedure/parameter names, numeric array keys, unknown parameter names, etc. |
| `ExecutionException` | The database reports an error during the call |
| `ProcedureErrorException` | The procedure raises an error on purpose (subclass of `ExecutionException`) |
| `NoResultsException` | `queryFirst()` or `querySingle()` returns zero rows |
| `MultipleResultsException` | `querySingle()` returns more than one row |
| `MappingException` | A row cannot be mapped to the requested class |
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
composer db:up          # Start the demo databases (see below)
composer test           # Run the test suite
composer test:mutate    # Run the mutation tests (requires Xdebug)
composer analyse        # Run PHPStan
composer db:down        # Stop the demo databases
```

The integration tests in `tests/Integration` run against the demo databases below and are skipped when a database is unreachable. Set the `DB_REQUIRED` environment variable to make them fail instead (as in CI). The DSNs can be overridden with the `MYSQL_DSN`, `PGSQL_DSN` and `SQLSRV_DSN` environment variables.

## Docker (demo databases)

The repository includes a `docker-compose.yml` with MySQL, PostgreSQL, and SQL Server containers — each pre-loaded with a `countries` table, seed data, and example stored procedures/functions.

```bash
docker compose -f docker/docker-compose.yml up -d    # or: composer db:up

# Run the demo scripts
php docker/mysql/mysql.php
php docker/pgsql/pgsql.php
php docker/sqlsrv/sqlsrv.php
```

The demo scripts map the rows to the `Country` class of `docker/models.php`, and run in a transaction rolled back at the end: they leave the database unchanged and can be run again.

## License

MIT © Alexandre Debusschère  
The package is licensed under the MIT license. See [License File](https://github.com/debuss-a/stored-procedures/blob/master/LICENSE.md) for more information.

