<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\DriverInterface;
use StoredProcedures\Exception\NoResultsException;
use StoredProcedures\ParameterMode;
use StoredProcedures\Procedure;
use StoredProcedures\ResultSet;
use StoredProcedures\SqlConnection;
use StoredProcedures\SqlParameter;

covers(Procedure::class);

/**
 * Helper: create a SqlConnection with a mock driver and injectable statement behavior.
 */
function createProcedureTestConnection(): array
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')
        ->with(PDO::ATTR_DRIVER_NAME)
        ->andReturn('mysql');

    $connection = new SqlConnection($pdo);

    $driver = Mockery::mock(DriverInterface::class);
    $stmt = Mockery::mock(PDOStatement::class);

    $ref = new ReflectionProperty($connection, 'driver');
    $ref->setValue($connection, $driver);

    $driver->shouldReceive('getQuerySyntax')->andReturn('CALL `sp_test`()');
    $driver->shouldReceive('bindParameters');
    $pdo->shouldReceive('prepare')->andReturn($stmt);

    return [$connection, $driver, $stmt];
}

// ---------------------------------------------------------------
//  Fluent parameter builders
// ---------------------------------------------------------------

describe('input()', function () {
    it('returns self for chaining', function () {
        [$connection] = createProcedureTestConnection();
        $procedure = new Procedure($connection, 'sp_test');

        $result = $procedure->input('p_name', 'France');

        expect($result)->toBe($procedure);
    });
});

describe('output()', function () {
    it('returns self for chaining', function () {
        [$connection] = createProcedureTestConnection();
        $procedure = new Procedure($connection, 'sp_test');

        $result = $procedure->output('p_id', DbType::INTEGER);

        expect($result)->toBe($procedure);
    });
});

describe('inout()', function () {
    it('returns self for chaining', function () {
        [$connection] = createProcedureTestConnection();
        $procedure = new Procedure($connection, 'sp_test');

        $result = $procedure->inout('p_name', '', DbType::STRING, 100);

        expect($result)->toBe($procedure);
    });
});

// ---------------------------------------------------------------
//  execute()
// ---------------------------------------------------------------

describe('execute()', function () {
    it('returns affected rows as int', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('rowCount')->andReturn(2);
        $stmt->shouldReceive('closeCursor')->once();
        $driver->shouldReceive('getOutputValues')->andReturn([]);

        $procedure = new Procedure($connection, 'sp_insert');
        $result = $procedure->input('p_name', 'France')->execute();

        expect($result)->toBe(2);
    });
});

// ---------------------------------------------------------------
//  all()
// ---------------------------------------------------------------

describe('all()', function () {
    it('returns a ResultSet', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $rows = [['id' => 1, 'name' => 'France']];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();
        $driver->shouldReceive('fetchRowSets')->andReturn([$rows]);
        $driver->shouldReceive('getOutputValues')->andReturn([]);

        $procedure = new Procedure($connection, 'sp_fetch');
        $result = $procedure->input('p_letter', 'F')->all();

        expect($result)->toBeInstanceOf(ResultSet::class)
            ->and($result->rows())->toBe($rows);
    });
});

// ---------------------------------------------------------------
//  first()
// ---------------------------------------------------------------

describe('first()', function () {
    it('returns the first row', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $row = (object) ['id' => 1, 'name' => 'France'];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn($row);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');
        $result = $procedure->input('p_iso', 'FR')->first();

        expect($result)->toBe($row);
    });
});

// ---------------------------------------------------------------
//  firstOrDefault()
// ---------------------------------------------------------------

describe('firstOrDefault()', function () {
    it('returns default when no results', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $default = (object) ['id' => 0, 'name' => 'Unknown'];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');
        $result = $procedure->input('p_iso', 'ZZ')->firstOrDefault($default);

        expect($result)->toBe($default);
    });

    it('returns null when no results and no default is given', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');

        expect($procedure->firstOrDefault())->toBeNull();
    });
});

// ---------------------------------------------------------------
//  single()
// ---------------------------------------------------------------

describe('single()', function () {
    it('returns the only row', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $row = (object) ['id' => 1, 'name' => 'France'];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->twice()->andReturn($row, false);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');
        $result = $procedure->input('p_iso', 'FR')->single();

        expect($result)->toBe($row);
    });

    it('throws NoResultsException when no rows', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');

        expect(fn() => $procedure->single())
            ->toThrow(NoResultsException::class);
    });
});

// ---------------------------------------------------------------
//  singleOrDefault()
// ---------------------------------------------------------------

describe('singleOrDefault()', function () {
    it('returns default when no results', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $default = ['fallback' => true];
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_fetch');
        $result = $procedure->singleOrDefault($default);

        expect($result)->toBe($default);
    });
});

// ---------------------------------------------------------------
//  Parameter overwrite (same name replaces)
// ---------------------------------------------------------------

describe('parameter overwrite', function () {
    it('replaces a parameter when the same name is used twice', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('rowCount')->andReturn(1);
        $stmt->shouldReceive('closeCursor')->once();
        $driver->shouldReceive('getOutputValues')->andReturn([]);

        $procedure = new Procedure($connection, 'sp_test');
        $result = $procedure
            ->input('p_name', 'France')
            ->input('p_name', 'Germany')
            ->execute();

        // Should not throw — just overwrites the parameter
        expect($result)->toBe(1);
    });
});

