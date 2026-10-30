<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\Call;
use StoredProcedures\Driver\DriverInterface;
use StoredProcedures\Exception\MultipleResultsException;
use StoredProcedures\Exception\NoResultsException;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\Exception\UnsupportedDriverException;
use StoredProcedures\ParameterMode;
use StoredProcedures\ResultSet;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

covers(SqlConnection::class);

/**
 * Helper: create a SqlConnection with a mocked PDO for a given driver name.
 *
 * We use reflection to inject a mock driver so we can test SqlConnection
 * logic without hitting a real database.
 */
function createConnection(string $driverName = 'mysql'): array
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')
        ->with(PDO::ATTR_DRIVER_NAME)
        ->andReturn($driverName);

    $connection = new SqlConnection($pdo);

    return [$connection, $pdo];
}

/**
 * Helper: inject a custom mock driver into a SqlConnection via reflection.
 */
function injectDriver(SqlConnection $connection, DriverInterface $driver): void
{
    $ref = new ReflectionProperty($connection, 'driver');
    $ref->setValue($connection, $driver);
}

/**
 * Helper: set up a full mock chain (driver + statement) for SqlConnection calls.
 */
function setupMocks(string $driverName = 'mysql', array $outputs = []): array
{
    [$connection, $pdo] = createConnection($driverName);

    $driver = Mockery::mock(DriverInterface::class);
    $stmt = Mockery::mock(PDOStatement::class);

    injectDriver($connection, $driver);

    $driver->shouldReceive('prepare')->andReturn(new Call($stmt, fn(): array => $outputs))->byDefault();
    $driver->shouldReceive('fetchRowSets')->andReturn([])->byDefault();
    $stmt->shouldReceive('rowCount')->andReturn(0)->byDefault();

    return [$connection, $pdo, $driver, $stmt];
}

// ---------------------------------------------------------------
//  Constructor / driver resolution
// ---------------------------------------------------------------

describe('constructor', function () {
    it('creates MySql driver for mysql', function () {
        [$conn] = createConnection('mysql');
        expect($conn)->toBeInstanceOf(SqlConnection::class);
    });

    it('creates PgSql driver for pgsql', function () {
        [$conn] = createConnection('pgsql');
        expect($conn)->toBeInstanceOf(SqlConnection::class);
    });

    it('creates SqlSrv driver for sqlsrv', function () {
        [$conn] = createConnection('sqlsrv');
        expect($conn)->toBeInstanceOf(SqlConnection::class);
    });

    it('creates SqlSrv driver for dblib', function () {
        [$conn] = createConnection('dblib');
        expect($conn)->toBeInstanceOf(SqlConnection::class);
    });

    it('throws UnsupportedDriverException for unknown driver', function () {
        expect(function () {
            createConnection('sqlite');
        })->toThrow(UnsupportedDriverException::class, 'Unsupported database driver: sqlite');
    });
});

// ---------------------------------------------------------------
//  getPdo()
// ---------------------------------------------------------------

describe('getPdo()', function () {
    it('returns the injected PDO instance', function () {
        [$conn, $pdo] = createConnection();

        expect($conn->getPdo())->toBe($pdo);
    });
});

// ---------------------------------------------------------------
//  procedure()
// ---------------------------------------------------------------

describe('procedure()', function () {
    it('returns a Procedure instance', function () {
        [$conn] = createConnection();

        $procedure = $conn->procedure('sp_test');

        expect($procedure)->toBeInstanceOf(\StoredProcedures\Procedure::class);
    });
});

// ---------------------------------------------------------------
//  normalizeParameters (tested indirectly)
// ---------------------------------------------------------------

describe('parameter normalization', function () {
    it('rejects numerically indexed parameters', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        expect(fn() => $connection->query('sp_test', ['value']))
            ->toThrow(StoredProcedureException::class, 'Parameter keys must be named strings');
    });

    it('accepts named string keys', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $driver->shouldReceive('fetchRowSets')->andReturn([]);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->query('sp_test', ['p_name' => 'France']);

        expect($result)->toBeInstanceOf(ResultSet::class);
    });

    it('passes SqlParameter objects through unchanged', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $driver->shouldReceive('fetchRowSets')->andReturn([]);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        $param = new SqlParameter('p_name', 'France', DbType::STRING);
        $result = $connection->query('sp_test', [$param]);

        expect($result)->toBeInstanceOf(ResultSet::class);
    });
});

// ---------------------------------------------------------------
//  execute()
// ---------------------------------------------------------------

describe('execute()', function () {
    it('returns a ResultSet with affected rows and output values', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks('mysql', ['p_id' => 42]);

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('rowCount')->once()->andReturn(3);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->execute('sp_test', ['p_name' => 'France']);

        expect($result)->toBeInstanceOf(ResultSet::class)
            ->and($result->getAffectedRows())->toBe(3)
            ->and($result->getOutputValue('p_id'))->toBe(42)
            ->and($result->rows())->toBe([]);
    });
});

// ---------------------------------------------------------------
//  query()
// ---------------------------------------------------------------

describe('query()', function () {
    it('passes the normalized parameters to the driver', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $driver->shouldReceive('prepare')
            ->once()
            ->with('sp_test', Mockery::on(fn(array $params): bool => $params == [new SqlParameter('p_name', 'France', DbType::STRING)]))
            ->andReturn(new Call($stmt, fn(): array => []));
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        $connection->query('sp_test', ['p_name' => 'France']);
    });

    it('closes the cursor when execution fails', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $stmt->shouldReceive('execute')->once()->andThrow(new PDOException('boom'));
        $stmt->shouldReceive('closeCursor')->once();

        expect(fn() => $connection->query('sp_test'))->toThrow(PDOException::class, 'boom');
    });

    it('passes the fetched row sets to the output values reader', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $rows = [['p_id' => 7]];
        $driver->shouldReceive('prepare')->andReturn(new Call($stmt, fn(array $row_sets): array => ['p_id' => $row_sets[0][0]['p_id']]));
        $driver->shouldReceive('fetchRowSets')->andReturn([$rows]);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        expect($connection->query('sp_test')->getOutputValue('p_id'))->toBe(7);
    });

    it('returns a ResultSet with row sets and output values', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $rows = [['id' => 1, 'name' => 'France']];

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();
        $driver->shouldReceive('fetchRowSets')->andReturn([$rows]);

        $result = $connection->query('sp_test');

        expect($result->rows())->toBe($rows)
            ->and($result->rowSetCount())->toBe(1);
    });
});

// ---------------------------------------------------------------
//  queryFirst()
// ---------------------------------------------------------------

describe('queryFirst()', function () {
    it('returns the first row', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $row = (object) ['id' => 1, 'name' => 'France'];

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn($row);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->queryFirst('sp_test');

        expect($result)->toBe($row);
    });

    it('throws NoResultsException when no rows', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        expect(fn() => $connection->queryFirst('sp_test'))
            ->toThrow(NoResultsException::class, "No results found for 'sp_test'");
    });
});

// ---------------------------------------------------------------
//  queryFirstOrDefault()
// ---------------------------------------------------------------

describe('queryFirstOrDefault()', function () {
    it('returns the first row when results exist', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $row = (object) ['id' => 1];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn($row);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->queryFirstOrDefault('sp_test');

        expect($result)->toBe($row);
    });

    it('returns null when no results and no default is given', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        expect($connection->queryFirstOrDefault('sp_test'))->toBeNull();
    });

    it('returns the default when no results', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $default = (object) ['id' => 0, 'name' => 'Unknown'];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->queryFirstOrDefault('sp_test', [], $default);

        expect($result)->toBe($default);
    });
});

// ---------------------------------------------------------------
//  querySingle()
// ---------------------------------------------------------------

describe('querySingle()', function () {
    it('returns the only row', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $row = (object) ['id' => 1, 'name' => 'France'];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->twice()->andReturn($row, false);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->querySingle('sp_test');

        expect($result)->toBe($row);
    });

    it('throws NoResultsException when no rows', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        expect(fn() => $connection->querySingle('sp_test'))
            ->toThrow(NoResultsException::class);
    });

    it('throws MultipleResultsException when more than one row', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $row1 = (object) ['id' => 1];
        $row2 = (object) ['id' => 2];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->twice()->andReturn($row1, $row2);
        $stmt->shouldReceive('closeCursor')->once();

        expect(fn() => $connection->querySingle('sp_test'))
            ->toThrow(MultipleResultsException::class, 'multiple rows were returned');
    });
});

// ---------------------------------------------------------------
//  querySingleOrDefault()
// ---------------------------------------------------------------

describe('querySingleOrDefault()', function () {
    it('returns the row when exactly one result', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $row = (object) ['id' => 1];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->twice()->andReturn($row, false);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->querySingleOrDefault('sp_test');

        expect($result)->toBe($row);
    });

    it('returns default when no results', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $default = ['fallback' => true];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->querySingleOrDefault('sp_test', [], $default);

        expect($result)->toBe($default);
    });

    it('returns default when multiple results', function () {
        [$connection, $pdo, $driver, $stmt] = setupMocks();

        $default = ['fallback' => true];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->twice()->andReturn((object) ['id' => 1], (object) ['id' => 2]);
        $stmt->shouldReceive('closeCursor')->once();

        $result = $connection->querySingleOrDefault('sp_test', [], $default);

        expect($result)->toBe($default);
    });
});

