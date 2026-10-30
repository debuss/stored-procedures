<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\PgSql;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(PgSql::class);

beforeEach(function () {
    $this->driver = new PgSql();
});

describe('getQuerySyntax()', function () {
    it('generates SELECT * FROM with no parameters', function () {
        $sql = $this->driver->getQuerySyntax('fn_get_all');

        expect($sql)->toBe('SELECT * FROM fn_get_all()');
    });

    it('generates SELECT * FROM with IN parameters', function () {
        $params = [
            new SqlParameter('p_letter', 'P', DbType::STRING),
        ];

        $sql = $this->driver->getQuerySyntax('fn_search', $params);

        expect($sql)->toBe('SELECT * FROM fn_search(:p_letter)');
    });

    it('excludes OUT parameters from placeholders', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        $sql = $this->driver->getQuerySyntax('fn_insert', $params);

        expect($sql)->toBe('SELECT * FROM fn_insert(:p_iso)');
    });

    it('includes INOUT parameters in placeholders', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT),
        ];

        $sql = $this->driver->getQuerySyntax('fn_search', $params);

        expect($sql)->toBe('SELECT * FROM fn_search(:p_iso, :p_name)');
    });

    it('throws on invalid procedure name', function () {
        expect(fn() => $this->driver->getQuerySyntax('DROP TABLE; --'))
            ->toThrow(StoredProcedureException::class, 'Invalid stored procedure name');
    });

    it('throws on invalid parameter name', function () {
        $params = [new SqlParameter('p name', 'val', DbType::STRING)];

        expect(fn() => $this->driver->getQuerySyntax('fn_test', $params))
            ->toThrow(StoredProcedureException::class, 'Invalid parameter name');
    });
});

describe('bindParameters()', function () {
    it('calls bindValue for IN parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindValue')
            ->once()
            ->with(':p_letter', 'P', PDO::PARAM_STR);

        $params = [new SqlParameter('p_letter', 'P', DbType::STRING)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('skips OUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldNotReceive('bindValue');
        $stmt->shouldNotReceive('bindParam');

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('binds INOUT params like IN params', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindValue')
            ->once()
            ->with(':p_name', 'France', PDO::PARAM_STR);

        $params = [new SqlParameter('p_name', 'France', DbType::STRING, ParameterMode::INOUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('binds only IN params when mixed modes are present', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindValue')
            ->once()
            ->with(':p_iso', 'FR', PDO::PARAM_STR);

        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });
});

describe('procedure detection', function () {
    function catalogPdo(array $kinds, array $expectedArgs): PDO
    {
        $lookup = Mockery::mock(PDOStatement::class);
        $lookup->shouldReceive('execute')->once()->with($expectedArgs);
        $lookup->shouldReceive('fetchAll')->once()->with(PDO::FETCH_COLUMN)->andReturn($kinds);
        $lookup->shouldReceive('closeCursor')->once();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andReturn($lookup);

        return $pdo;
    }

    it('generates CALL for a procedure, passing OUT parameters as NULL', function () {
        $driver = new PgSql(catalogPdo(['p'], ['sp_insert']));
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', 'x', DbType::STRING, ParameterMode::INOUT),
        ];

        expect($driver->getQuerySyntax('sp_insert', $params))
            ->toBe('CALL sp_insert(:p_iso, NULL, :p_name)');
    });

    it('generates SELECT * FROM for a function', function () {
        $driver = new PgSql(catalogPdo(['f'], ['fn_get_all']));

        expect($driver->getQuerySyntax('fn_get_all'))->toBe('SELECT * FROM fn_get_all()');
    });

    it('falls back to a function when the name is unknown or ambiguous', function (array $kinds) {
        $driver = new PgSql(catalogPdo($kinds, ['fn_x']));

        expect($driver->getQuerySyntax('fn_x'))->toBe('SELECT * FROM fn_x()');
    })->with([
        'unknown' => [[]],
        'ambiguous' => [['f', 'p']],
    ]);

    it('looks up schema-qualified names in lower case', function () {
        $driver = new PgSql(catalogPdo(['p'], ['sp_insert', 'billing']));

        expect($driver->getQuerySyntax('Billing.SP_Insert'))->toBe('CALL Billing.SP_Insert()');
    });

    it('caches the routine kind per name', function () {
        $driver = new PgSql(catalogPdo(['p'], ['sp_insert']));

        $driver->getQuerySyntax('sp_insert');

        expect($driver->getQuerySyntax('sp_insert'))->toBe('CALL sp_insert()');
    });
});

describe('fetchRowSets()', function () {
    it('returns a single row set from fetchAll', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $rows = [['id' => 1, 'name' => 'France']];

        $stmt->shouldReceive('fetchAll')->once()->andReturn($rows);

        $result = $this->driver->fetchRowSets($stmt);

        expect($result)->toBe([$rows]);
    });

    it('wraps empty result in a single row set', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $stmt->shouldReceive('fetchAll')->once()->andReturn([]);

        expect($this->driver->fetchRowSets($stmt))->toBe([[]]);
    });
});

describe('getOutputValues()', function () {
    it('always returns empty array', function () {
        $pdo = Mockery::mock(PDO::class);
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        expect($this->driver->getOutputValues($params, $pdo))->toBe([]);
    });
});


