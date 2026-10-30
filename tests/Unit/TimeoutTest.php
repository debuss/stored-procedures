<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\AbstractDriver;
use StoredProcedures\Driver\Call;
use StoredProcedures\Driver\DriverInterface;
use StoredProcedures\Driver\MySql;
use StoredProcedures\Driver\PgSql;
use StoredProcedures\Driver\SqlSrv;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\Exception\TimeoutException;
use StoredProcedures\Procedure;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

covers(SqlConnection::class, Procedure::class, Call::class, AbstractDriver::class, MySql::class, PgSql::class, SqlSrv::class);

/**
 * Helper: a connection with a mocked driver expecting the given timeout, and the executed statement.
 */
function connectionExpectingTimeout(?int $timeout): array
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_ERRMODE)->andReturn(PDO::ERRMODE_EXCEPTION);
    $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->andReturn('mysql');
    $connection = new SqlConnection($pdo);

    $statement = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
    $driver = Mockery::mock(DriverInterface::class);
    $driver->shouldReceive('prepare')->with('sp_test', [], $timeout)->andReturn(new Call($statement, fn(): array => []));
    $driver->shouldReceive('fetchRowSets')->andReturn([]);
    (new ReflectionProperty($connection, 'driver'))->setValue($connection, $driver);

    return [$connection, $statement];
}

/**
 * Helper: mock the statement_timeout queries of PgSql.
 *
 * @param list<array{string, bool, string}> $calls The value set, whether it is local, and the previous value returned
 */
function expectStatementTimeouts(PDO $pdo, array $calls): void
{
    foreach ($calls as [$value, $local, $previous]) {
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('execute')->once()->with([$value]);
        $statement->shouldReceive('fetchColumn')->once()->andReturn($previous);
        $statement->shouldReceive('closeCursor')->once();

        $pdo->shouldReceive('prepare')
            ->once()
            ->with("SELECT current_setting('statement_timeout'), set_config('statement_timeout', ?, " . ($local ? 'true' : 'false') . ')')
            ->andReturn($statement)
            ->ordered();
    }
}

describe('SqlConnection::withTimeout()', function () {
    it('returns a copy of the connection with the timeout, leaving the original unchanged', function () {
        [$connection] = connectionExpectingTimeout(null);

        $limited = $connection->withTimeout(30);

        expect($limited)->not->toBe($connection)
            ->and($limited->getTimeout())->toBe(30)
            ->and($connection->getTimeout())->toBeNull()
            ->and($limited->withTimeout(null)->getTimeout())->toBeNull();
    });

    it('rejects a timeout that is not a positive number of seconds', function (int $seconds) {
        [$connection] = connectionExpectingTimeout(null);

        expect(fn() => $connection->withTimeout($seconds))
            ->toThrow(StoredProcedureException::class, "The timeout must be a positive number of seconds, got $seconds.");
    })->with([0, -5]);

    it('passes the timeout to the driver', function () {
        [$connection] = connectionExpectingTimeout(30);

        expect($connection->withTimeout(30)->query('sp_test'))->toBeInstanceOf(StoredProcedures\ResultSet::class);
    });

    it('logs the timeout', function () {
        [$connection] = connectionExpectingTimeout(30);
        $logger = new Tests\Fixtures\ArrayLogger();
        $connection->setLogger($logger);

        $connection->withTimeout(30)->query('sp_test');

        expect($logger->records[0]['context'])->toMatchArray(['timeout' => 30]);
    });
});

describe('Procedure::timeout()', function () {
    it('overrides the timeout of the connection for the call', function () {
        [$connection] = connectionExpectingTimeout(5);

        expect($connection->withTimeout(30)->procedure('sp_test')->timeout(5)->execute())->toBeInstanceOf(StoredProcedures\ResultSet::class);
    });

    it('removes the timeout of the connection for the call', function () {
        [$connection] = connectionExpectingTimeout(null);

        expect($connection->withTimeout(30)->procedure('sp_test')->timeout(null)->execute())->toBeInstanceOf(StoredProcedures\ResultSet::class);
    });

    it('does not change the timeout of the connection', function () {
        [$connection] = connectionExpectingTimeout(null);
        $limited = $connection->withTimeout(30);

        $limited->procedure('sp_test')->timeout(5);

        expect($limited->getTimeout())->toBe(30);
    });
});

describe('Call::execute()', function () {
    it('executes the statement', function () {
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('execute')->once();

        (new Call($statement, fn(): array => []))->execute();
    });

    it('delegates the execution when given', function () {
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldNotReceive('execute');
        $executed = null;

        (new Call($statement, fn(): array => [], function (PDOStatement $s) use (&$executed): void {
            $executed = $s;
        }))->execute();

        expect($executed)->toBe($statement);
    });
});

describe('MySQL', function () {
    it('rejects a timeout', function () {
        expect(fn() => (new MySql(Mockery::mock(PDO::class)))->prepare('sp_test', [], 5))
            ->toThrow(StoredProcedureException::class, 'MySQL cannot limit the execution time of a stored procedure');
    });
});

describe('SQL Server', function () {
    it('sets the query timeout attribute of the statement', function () {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->andReturn('sqlsrv');
        $pdo->shouldReceive('prepare')
            ->once()
            ->with('{CALL [sp_test]}', [constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') => 5])
            ->andReturn(Mockery::mock(PDOStatement::class));

        (new SqlSrv($pdo))->prepare('sp_test', [], 5);
    })->skip(!defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), 'pdo_sqlsrv is not loaded');

    it('rejects a timeout with pdo_dblib', function () {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->andReturn('dblib');

        expect(fn() => (new SqlSrv($pdo))->prepare('sp_test', [], 5))
            ->toThrow(StoredProcedureException::class, 'SQL Server timeouts require the pdo_sqlsrv driver.');
    });

    it('converts the timeouts to TimeoutException', function () {
        $exception = new PDOException('Query timeout expired');
        $exception->errorInfo = ['HYT00', 0, '[Microsoft][ODBC Driver 18 for SQL Server]Query timeout expired'];

        expect((new SqlSrv(Mockery::mock(PDO::class)))->convertException('sp_test', $exception))
            ->toBeInstanceOf(TimeoutException::class)
            ->getMessage()->toBe('Query timeout expired');
    });
});

describe('PostgreSQL', function () {
    beforeEach(function () {
        $this->pdo = Mockery::mock(PDO::class);
        // The function lookup in pg_proc
        $lookup = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $lookup->shouldReceive('fetchAll')->andReturn(['f']);
        $this->pdo->shouldReceive('prepare')->with(Mockery::pattern('/pg_catalog\.pg_proc/'))->andReturn($lookup);
        $this->statement = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $this->pdo->shouldReceive('prepare')->with('SELECT * FROM fn_test()')->andReturn($this->statement);
    });

    it('sets the session statement_timeout around the call, then restores it', function () {
        $this->pdo->shouldReceive('inTransaction')->andReturn(false);
        expectStatementTimeouts($this->pdo, [['5s', false, '30s'], ['30s', false, '5s']]);
        $this->statement->shouldReceive('execute')->once();

        (new PgSql($this->pdo))->prepare('fn_test', [], 5)->execute();
    });

    it('restores the session statement_timeout when the call fails', function () {
        $this->pdo->shouldReceive('inTransaction')->andReturn(false);
        expectStatementTimeouts($this->pdo, [['5s', false, '0'], ['0', false, '5s']]);
        $this->statement->shouldReceive('execute')->once()->andThrow(new PDOException('canceling statement due to statement timeout'));

        expect(fn() => (new PgSql($this->pdo))->prepare('fn_test', [], 5)->execute())->toThrow(PDOException::class);
    });

    it('sets a transaction-local statement_timeout within a transaction, then restores it', function () {
        $this->pdo->shouldReceive('inTransaction')->andReturn(true);
        expectStatementTimeouts($this->pdo, [['5s', true, '0'], ['0', true, '5s']]);
        $this->statement->shouldReceive('execute')->once();

        (new PgSql($this->pdo))->prepare('fn_test', [], 5)->execute();
    });

    it('leaves the restoration to the rollback when the call fails within a transaction', function () {
        $this->pdo->shouldReceive('inTransaction')->andReturn(true);
        // Only the first set_config(): the aborted transaction would reject the second one
        expectStatementTimeouts($this->pdo, [['5s', true, '0']]);
        $this->statement->shouldReceive('execute')->once()->andThrow(new PDOException('canceling statement due to statement timeout'));

        expect(fn() => (new PgSql($this->pdo))->prepare('fn_test', [], 5)->execute())->toThrow(PDOException::class);
    });

    it('does not touch statement_timeout without timeout', function () {
        $this->pdo->shouldNotReceive('inTransaction');
        $this->statement->shouldReceive('execute')->once();

        (new PgSql($this->pdo))->prepare('fn_test', [])->execute();
    });

    it('converts the timeouts to TimeoutException', function () {
        $exception = new PDOException('canceling statement due to statement timeout');
        $exception->errorInfo = ['57014', 7, "ERROR:  canceling statement due to statement timeout\nCONTEXT:  PL/pgSQL function fn_sleep(integer)"];

        expect((new PgSql($this->pdo))->convertException('fn_sleep', $exception))
            ->toBeInstanceOf(TimeoutException::class)
            ->getMessage()->toBe('canceling statement due to statement timeout');
    });
});
