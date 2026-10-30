<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\PgSql;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(PgSql::class);

/**
 * Helper: mock the pg_proc lookup made by PgSql to know whether a routine is a procedure.
 *
 * @param string[] $kinds The prokind values returned by the catalog ('f' function, 'p' procedure)
 * @param array|null $expectedArgs The expected lookup arguments, or null to accept any
 */
function expectCatalogLookup(PDO $pdo, array $kinds, ?array $expectedArgs = null): void
{
    $lookup = Mockery::mock(PDOStatement::class);
    $lookup->shouldReceive('execute')->once()->with($expectedArgs ?? Mockery::any());
    $lookup->shouldReceive('fetchAll')->once()->with(PDO::FETCH_COLUMN)->andReturn($kinds);
    $lookup->shouldReceive('closeCursor')->once();

    $pdo->shouldReceive('prepare')->once()->with(Mockery::pattern('/pg_catalog\.pg_proc/'))->andReturn($lookup);
}

beforeEach(function () {
    $this->pdo = Mockery::mock(PDO::class);
    $this->driver = new PgSql($this->pdo);
});

describe('getQuerySyntax() for functions', function () {
    beforeEach(function () {
        expectCatalogLookup($this->pdo, ['f']);
    });

    it('generates SELECT * FROM with no parameters', function () {
        expect($this->driver->getQuerySyntax('fn_get_all'))->toBe('SELECT * FROM fn_get_all()');
    });

    it('uses the named notation for IN parameters', function () {
        $params = [
            new SqlParameter('p_letter', 'P', DbType::STRING),
            new SqlParameter('p_limit', 10, DbType::INTEGER),
        ];

        expect($this->driver->getQuerySyntax('fn_search', $params))
            ->toBe('SELECT * FROM fn_search(p_letter => :p_letter, p_limit => :p_limit)');
    });

    it('excludes OUT parameters', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        expect($this->driver->getQuerySyntax('fn_insert', $params))
            ->toBe('SELECT * FROM fn_insert(p_iso => :p_iso)');
    });

    it('includes INOUT parameters', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT),
        ];

        expect($this->driver->getQuerySyntax('fn_search', $params))
            ->toBe('SELECT * FROM fn_search(p_iso => :p_iso, p_name => :p_name)');
    });

    it('allows schema-qualified names', function () {
        expect($this->driver->getQuerySyntax('public.fn_test'))->toBe('SELECT * FROM public.fn_test()');
    });
});

describe('getQuerySyntax() for procedures', function () {
    it('generates CALL, passing OUT parameters as NULL', function () {
        expectCatalogLookup($this->pdo, ['p'], ['sp_insert']);

        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', 'x', DbType::STRING, ParameterMode::INOUT),
        ];

        expect($this->driver->getQuerySyntax('sp_insert', $params))
            ->toBe('CALL sp_insert(p_iso => :p_iso, p_id => NULL, p_name => :p_name)');
    });

    it('falls back to a function when the name is unknown or ambiguous', function (array $kinds) {
        expectCatalogLookup($this->pdo, $kinds, ['fn_x']);

        expect($this->driver->getQuerySyntax('fn_x'))->toBe('SELECT * FROM fn_x()');
    })->with([
        'unknown' => [[]],
        'ambiguous' => [['f', 'p']],
    ]);

    it('looks up schema-qualified names in lower case', function () {
        expectCatalogLookup($this->pdo, ['p'], ['sp_insert', 'billing']);

        expect($this->driver->getQuerySyntax('Billing.SP_Insert'))->toBe('CALL Billing.SP_Insert()');
    });

    it('caches the routine kind per name', function () {
        expectCatalogLookup($this->pdo, ['p'], ['sp_insert']);

        $this->driver->getQuerySyntax('sp_insert');

        expect($this->driver->getQuerySyntax('sp_insert'))->toBe('CALL sp_insert()');
    });
});

describe('getQuerySyntax() validation', function () {
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

describe('prepare()', function () {
    it('binds IN and INOUT parameters, but not OUT parameters', function () {
        expectCatalogLookup($this->pdo, ['f']);
        $stmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')
            ->once()
            ->with('SELECT * FROM fn_search(p_iso => :p_iso, p_name => :p_name)')
            ->andReturn($stmt);
        $stmt->shouldReceive('bindValue')->once()->with(':p_iso', 'FR', PDO::PARAM_STR);
        $stmt->shouldReceive('bindValue')->once()->with(':p_name', 'France', PDO::PARAM_STR);

        $call = $this->driver->prepare('fn_search', [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', 'France', DbType::STRING, ParameterMode::INOUT),
        ]);

        expect($call->statement)->toBe($stmt);
    });
});

describe('output values', function () {
    beforeEach(function () {
        expectCatalogLookup($this->pdo, ['p']);
        $this->pdo->shouldReceive('prepare')->andReturn(Mockery::mock(PDOStatement::class)->shouldIgnoreMissing());

        $this->call = $this->driver->prepare('sp_insert', [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_Name', 'x', DbType::STRING, ParameterMode::INOUT),
        ]);
    });

    it('reads OUT and INOUT values from the columns of the first row', function (array|stdClass $row) {
        expect($this->call->getOutputValues([[$row]]))->toBe(['p_id' => 42, 'p_Name' => 'France']);
    })->with([
        'array row' => [['p_iso' => 'FR', 'p_id' => 42, 'p_name' => 'France']],
        'object row' => [(object) ['p_iso' => 'FR', 'p_id' => 42, 'p_name' => 'France']],
    ]);

    it('returns null values when there is no row', function () {
        expect($this->call->getOutputValues([[]]))->toBe(['p_id' => null, 'p_Name' => null]);
    });
});

describe('fetchRowSets()', function () {
    it('returns a single row set from fetchAll', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $rows = [['id' => 1, 'name' => 'France']];

        $stmt->shouldReceive('fetchAll')->once()->andReturn($rows);

        expect($this->driver->fetchRowSets($stmt))->toBe([$rows]);
    });

    it('wraps empty result in a single row set', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $stmt->shouldReceive('fetchAll')->once()->andReturn([]);

        expect($this->driver->fetchRowSets($stmt))->toBe([[]]);
    });
});
