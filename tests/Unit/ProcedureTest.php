<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\Call;
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
        ->with(PDO::ATTR_ERRMODE)
        ->andReturn(PDO::ERRMODE_EXCEPTION);
    $pdo->shouldReceive('getAttribute')
        ->with(PDO::ATTR_DRIVER_NAME)
        ->andReturn('mysql');

    $connection = new SqlConnection($pdo);

    $driver = Mockery::mock(DriverInterface::class);
    $stmt = Mockery::mock(PDOStatement::class);

    $ref = new ReflectionProperty($connection, 'driver');
    $ref->setValue($connection, $driver);

    $driver->shouldReceive('prepare')->andReturn(new Call($stmt, fn(): array => []))->byDefault();
    $driver->shouldReceive('fetchRowSets')->andReturn([])->byDefault();
    $stmt->shouldReceive('rowCount')->andReturn(0)->byDefault();

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
    it('returns a ResultSet with the affected rows', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('rowCount')->andReturn(2);
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_insert');
        $result = $procedure->input('p_name', 'France')->execute();

        expect($result)->toBeInstanceOf(ResultSet::class)
            ->and($result->getAffectedRows())->toBe(2);
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

        $driver->shouldReceive('prepare')
            ->once()
            ->with('sp_test', Mockery::on(fn(array $params): bool => $params == [new SqlParameter('p_name', 'Germany', DbType::STRING)]))
            ->andReturn(new Call($stmt, fn(): array => []));
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        $procedure = new Procedure($connection, 'sp_test');
        $procedure
            ->input('p_name', 'France')
            ->input('p_name', 'Germany')
            ->execute();
    });
});


// ---------------------------------------------------------------
//  Type inference, typed rows and scalar()
// ---------------------------------------------------------------

describe('type inference', function () {
    it('infers the type of input() and inout() parameters when none is given', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $driver->shouldReceive('prepare')
            ->once()
            ->with('sp_test', Mockery::on(fn(array $params): bool => array_map(fn(SqlParameter $p) => $p->type, $params) == [DbType::INTEGER, DbType::DATETIME, DbType::BOOLEAN]))
            ->andReturn(new Call($stmt, fn(): array => []));
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        (new Procedure($connection, 'sp_test'))
            ->input('p_id', 42)
            ->input('p_date', new DateTimeImmutable())
            ->inout('p_flag', true)
            ->execute();
    });

    it('keeps the type given explicitly', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $driver->shouldReceive('prepare')
            ->once()
            ->with('sp_test', Mockery::on(fn(array $params): bool => $params[0]->type === DbType::STRING))
            ->andReturn(new Call($stmt, fn(): array => []));
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('closeCursor')->once();

        (new Procedure($connection, 'sp_test'))->input('p_code', 42, DbType::STRING)->execute();
    });
});

describe('typed rows', function () {
    it('maps the rows of all(), first() and single()', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $row = ['id' => 1, 'iso' => 'FR', 'name' => 'France'];
        $country = new Tests\Fixtures\Country(1, 'FR', 'France');
        $driver->shouldReceive('fetchRowSets')->andReturn([[$row]]);
        $stmt->shouldReceive('execute');
        $stmt->shouldReceive('fetch')->andReturn($row, $row, false, $row, $row, false);
        $stmt->shouldReceive('closeCursor');

        $procedure = new Procedure($connection, 'sp_fetch');

        expect($procedure->all(Tests\Fixtures\Country::class)->rows())->toEqual([$country])
            ->and($procedure->first(Tests\Fixtures\Country::class))->toEqual($country)
            ->and($procedure->single(Tests\Fixtures\Country::class))->toEqual($country)
            ->and($procedure->firstOrDefault(class: Tests\Fixtures\Country::class))->toEqual($country)
            ->and($procedure->singleOrDefault(class: Tests\Fixtures\Country::class))->toEqual($country);
    });
});

describe('scalar()', function () {
    it('returns the first column of the first row', function () {
        [$connection, $driver, $stmt] = createProcedureTestConnection();

        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->with(PDO::FETCH_NUM)->andReturn([10]);
        $stmt->shouldReceive('closeCursor')->once();

        expect((new Procedure($connection, 'sp_count'))->scalar())->toBe(10);
    });
});
