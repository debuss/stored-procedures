<?php

use StoredProcedures\Driver\AbstractDriver;
use StoredProcedures\Driver\MySql;
use StoredProcedures\Driver\PgSql;
use StoredProcedures\Driver\SqlSrv;
use StoredProcedures\Exception\ExecutionException;
use StoredProcedures\Exception\ProcedureErrorException;

covers(AbstractDriver::class, MySql::class, PgSql::class, SqlSrv::class);

/**
 * Helper: a PDOException as reported by a driver.
 */
function pdoError(array $error_info): PDOException
{
    $exception = new PDOException("SQLSTATE[{$error_info[0]}]: {$error_info[2]}");
    $exception->errorInfo = $error_info;

    return $exception;
}

it('converts the errors raised by the procedure to ProcedureErrorException', function (string $driver, array $error_info, ?int $code) {
    $exception = (new $driver(Mockery::mock(PDO::class)))->convertException('sp_raise_error', pdoError($error_info));

    expect($exception)->toBeInstanceOf(ProcedureErrorException::class)
        ->and($exception->getMessage())->toBe('Country ZZ not found')
        ->and($exception->getProcedure())->toBe('sp_raise_error')
        ->and($exception->getSqlState())->toBe($error_info[0])
        ->and($exception->getDriverCode())->toBe($code)
        ->and($exception->getCode())->toBe($code ?? 0)
        ->and($exception->getPrevious())->toBeInstanceOf(PDOException::class);
})->with([
    'MySQL SIGNAL' => [MySql::class, ['45000', 1644, 'Country ZZ not found'], 1644],
    'MySQL SIGNAL with another SQLSTATE' => [MySql::class, ['45001', 1644, 'Country ZZ not found'], 1644],
    'MySQL SIGNAL with a custom MYSQL_ERRNO' => [MySql::class, ['45000', 5001, 'Country ZZ not found'], 5001],
    'SQL Server THROW' => [SqlSrv::class, ['42000', 50001, '[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Country ZZ not found'], 50001],
    'SQL Server RAISERROR with a message' => [SqlSrv::class, ['42000', 50000, '[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Country ZZ not found'], 50000],
    'PostgreSQL RAISE EXCEPTION' => [PgSql::class, ['P0001', 7, "ERROR:  Country ZZ not found\nCONTEXT:  PL/pgSQL function fn_raise_error(character varying) line 3 at RAISE"], null],
]);

it('converts the other errors to ExecutionException', function (string $driver, array $error_info, string $message) {
    $exception = (new $driver(Mockery::mock(PDO::class)))->convertException('sp_missing', pdoError($error_info));

    expect($exception)->toBeInstanceOf(ExecutionException::class)
        ->not->toBeInstanceOf(ProcedureErrorException::class)
        ->and($exception->getMessage())->toBe($message);
})->with([
    'MySQL' => [MySql::class, ['42000', 1305, 'PROCEDURE demo.sp_missing does not exist'], 'PROCEDURE demo.sp_missing does not exist'],
    'SQL Server' => [SqlSrv::class, ['42000', 2812, "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Could not find stored procedure 'sp_missing'."], "Could not find stored procedure 'sp_missing'."],
    'SQL Server, highest system error number' => [SqlSrv::class, ['42000', 49999, '[Microsoft][SQL Server]System error'], 'System error'],
    'PostgreSQL' => [PgSql::class, ['42883', 7, "ERROR:  function sp_missing() does not exist\nLINE 1: SELECT * FROM sp_missing()\nHINT:  No function matches."], 'function sp_missing() does not exist'],
]);

it('falls back to the exception message when the error info has no message', function () {
    $exception = pdoError(['HY093', null, '']);

    expect((new MySql(Mockery::mock(PDO::class)))->convertException('sp_test', $exception)->getMessage())
        ->toBe('SQLSTATE[HY093]:');
});

it('falls back to the exception message without error info', function () {
    $exception = (new MySql(Mockery::mock(PDO::class)))->convertException('sp_test', new PDOException('Connection lost'));

    expect($exception->getMessage())->toBe('Connection lost')
        ->and($exception->getDriverCode())->toBeNull();
});
