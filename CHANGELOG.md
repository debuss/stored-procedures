# Changelog

## [Unreleased]

### Breaking changes

- `Procedure::execute()` returns a `ResultSet` instead of an `int`, so that OUT and INOUT values are no longer lost. Use `->execute()->getAffectedRows()` for the previous behavior.
- PostgreSQL parameters are matched by name (named notation `fn(p_name => :p_name)`): parameter names must match the routine's argument names.
- `ResultSet::getOutputValue()` throws an `OutOfRangeException` for an unknown name instead of returning `null`.
- PostgreSQL OUT and INOUT values are also returned as output values (`getOutputValue()`), read from the columns of the first row.
- `SqlConnection::execute()` and `SqlConnection::query()` both return the row sets, the output values and the affected rows.
- Drivers receive the `PDO` instance in their constructor and expose a single `prepare()` method returning a `Call`; `bindParameters()` and `getOutputValues()` are removed from `DriverInterface`.
- `ParameterMode::toPdoType()` is removed.
- `Procedure::input()` and `inout()` infer the type from the value instead of defaulting to `DbType::STRING`: an `int` is now bound with `PDO::PARAM_INT`.
- `queryFirst()`, `querySingle()`, `Procedure::first()` and `single()` are declared as returning `array|object` instead of `array|stdClass`.

### Added

- Typed rows: pass a class to `query()`, `queryFirst()`, `querySingle()`, their `OrDefault` variants, and to `Procedure::all()`, `first()`, `single()`… to map the rows to objects (constructor parameters or public properties, with backed enum and date conversion). Generic return types for PHPStan and IDEs.
- `SqlConnection::queryScalar()` and `Procedure::scalar()`.
- Backed enums and `DateTimeInterface` values can be passed as parameters; their type is inferred.
- `Procedure::input()` and `inout()` infer the type from the value when none is given.
- PostgreSQL procedures (`CREATE PROCEDURE`), called with `CALL`.
- Integration tests against the Docker demo databases.

### Fixed

- `queryFirstOrDefault()` and `firstOrDefault()` threw a `TypeError` when no default was given.
- Schema-qualified MySQL procedure names were escaped as a single identifier.
- PostgreSQL INOUT parameters were not passed to the function.
- MySQL INOUT user variables are initialized with the parameter type.
- Procedure names with empty parts (e.g. `db..sp`) are rejected.

## [0.1.0]

- Initial release.
