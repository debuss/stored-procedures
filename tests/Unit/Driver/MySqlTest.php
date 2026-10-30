<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\MySql;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(MySql::class);

beforeEach(function () {
    $this->pdo = Mockery::mock(PDO::class);
    $this->driver = new MySql($this->pdo);
});

describe('getQuerySyntax()', function () {
    it('generates CALL with no parameters', function () {
        $sql = $this->driver->getQuerySyntax('sp_get_all');

        expect($sql)->toBe('CALL `sp_get_all`()');
    });

    it('generates CALL with IN parameters as named placeholders', function () {
        $params = [
            new SqlParameter('p_name', 'France', DbType::STRING),
            new SqlParameter('p_id', 1, DbType::INTEGER),
        ];

        $sql = $this->driver->getQuerySyntax('sp_find', $params);

        expect($sql)->toBe('CALL `sp_find`(:p_name, :p_id)');
    });

    it('generates CALL with OUT parameters as user variables', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_country_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        $sql = $this->driver->getQuerySyntax('sp_insert', $params);

        expect($sql)->toBe('CALL `sp_insert`(:p_iso, @p_country_id)');
    });

    it('generates CALL with INOUT parameters as user variables', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT, 100),
        ];

        $sql = $this->driver->getQuerySyntax('sp_search', $params);

        expect($sql)->toBe('CALL `sp_search`(:p_iso, @p_name)');
    });

    it('generates CALL with mixed IN, OUT and INOUT', function () {
        $params = [
            new SqlParameter('p_in', 'val', DbType::STRING),
            new SqlParameter('p_out', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_inout', 'x', DbType::STRING, ParameterMode::INOUT),
        ];

        $sql = $this->driver->getQuerySyntax('sp_mixed', $params);

        expect($sql)->toBe('CALL `sp_mixed`(:p_in, @p_out, @p_inout)');
    });

    it('throws on invalid procedure name', function () {
        expect(fn() => $this->driver->getQuerySyntax('DROP TABLE; --'))
            ->toThrow(StoredProcedureException::class, 'Invalid stored procedure name');
    });

    it('throws on invalid parameter name', function () {
        $params = [new SqlParameter('p name', 'val', DbType::STRING)];

        expect(fn() => $this->driver->getQuerySyntax('sp_test', $params))
            ->toThrow(StoredProcedureException::class, 'Invalid parameter name');
    });

    it('throws on empty name parts', function (string $name) {
        expect(fn() => $this->driver->getQuerySyntax($name))
            ->toThrow(StoredProcedureException::class, 'Invalid stored procedure name');
    })->with(['mydb..sp_test', 'mydb.', '.sp_test']);

    it('allows dots in procedure names for schema-qualified calls', function () {
        $sql = $this->driver->getQuerySyntax('mydb.sp_test');

        expect($sql)->toBe('CALL `mydb`.`sp_test`()');
    });
});

describe('prepare()', function () {
    it('prepares the CALL and binds IN parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->once()->with('CALL `sp_find`(:p_name)')->andReturn($stmt);
        $stmt->shouldReceive('bindValue')->once()->with(':p_name', 'France', PDO::PARAM_STR);

        $call = $this->driver->prepare('sp_find', [new SqlParameter('p_name', 'France', DbType::STRING)]);

        expect($call->statement)->toBe($stmt);
    });

    it('does not bind OUT parameters to the statement', function () {
        $stmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->once()->andReturn($stmt);
        $stmt->shouldNotReceive('bindValue');
        $stmt->shouldNotReceive('bindParam');

        $this->driver->prepare('sp_insert', [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)]);
    });

    it('initializes a typed user variable for INOUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $initStmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->once()->with('CALL `sp_search`(@p_count)')->andReturn($stmt);
        $this->pdo->shouldReceive('prepare')->once()->with('SET @p_count = ?')->andReturn($initStmt);
        $initStmt->shouldReceive('bindValue')->once()->with(1, 5, PDO::PARAM_INT);
        $initStmt->shouldReceive('execute')->once();
        $initStmt->shouldReceive('closeCursor')->once();

        $this->driver->prepare('sp_search', [new SqlParameter('p_count', 5, DbType::INTEGER, ParameterMode::INOUT)]);
    });
});

describe('output values', function () {
    it('returns empty array without querying when there are no output parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();

        // Only the CALL is prepared: no SELECT of user variables
        $this->pdo->shouldReceive('prepare')->once()->andReturn($stmt);

        $call = $this->driver->prepare('sp_find', [new SqlParameter('p_name', 'France', DbType::STRING)]);

        expect($call->getOutputValues([]))->toBe([]);
    });

    it('fetches output values in a single SELECT query', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $select = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->with('SET @p_name = ?')->andReturn($stmt);
        $this->pdo->shouldReceive('prepare')->with('CALL `sp_mixed`(:p_in, @p_id, @p_name)')->andReturn($stmt);
        $this->pdo->shouldReceive('prepare')
            ->once()
            ->with('SELECT @p_id, @p_name')
            ->andReturn($select);
        $select->shouldReceive('execute')->once();
        $select->shouldReceive('fetch')
            ->with(PDO::FETCH_NUM)
            ->andReturn([42, 'France']);
        $select->shouldReceive('closeCursor')->once();

        $call = $this->driver->prepare('sp_mixed', [
            new SqlParameter('p_in', 'x', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT),
        ]);

        expect($call->getOutputValues([]))->toBe(['p_id' => 42, 'p_name' => 'France']);
    });

    it('returns null values when SELECT returns no row', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $select = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->with('CALL `sp_insert`(@p_id)')->andReturn($stmt);
        $this->pdo->shouldReceive('prepare')->with('SELECT @p_id')->andReturn($select);
        $select->shouldReceive('execute');
        $select->shouldReceive('fetch')->with(PDO::FETCH_NUM)->andReturn(false);
        $select->shouldReceive('closeCursor');

        $call = $this->driver->prepare('sp_insert', [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)]);

        expect($call->getOutputValues([]))->toBe(['p_id' => null]);
    });
});
