<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\MySql;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(MySql::class);

beforeEach(function () {
    $this->driver = new MySql();
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

describe('bindParameters()', function () {
    it('calls bindValue for IN parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindValue')
            ->once()
            ->with(':p_name', 'France', PDO::PARAM_STR);

        $params = [new SqlParameter('p_name', 'France', DbType::STRING)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('does not bind OUT parameters to the statement', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldNotReceive('bindValue');
        $stmt->shouldNotReceive('bindParam');

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('initializes user variable for INOUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);
        $initStmt = Mockery::mock(PDOStatement::class);

        $pdo->shouldReceive('prepare')
            ->once()
            ->with('SET @p_name = ?')
            ->andReturn($initStmt);
        $initStmt->shouldReceive('execute')->once()->with(['hello']);
        $initStmt->shouldReceive('closeCursor')->once();

        $params = [new SqlParameter('p_name', 'hello', DbType::STRING, ParameterMode::INOUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });
});

describe('getOutputValues()', function () {
    it('returns empty array when no output parameters', function () {
        $pdo = Mockery::mock(PDO::class);
        $params = [new SqlParameter('p_name', 'France', DbType::STRING)];

        expect($this->driver->getOutputValues($params, $pdo))->toBe([]);
    });

    it('fetches output values in a single SELECT query', function () {
        $pdo = Mockery::mock(PDO::class);
        $stmt = Mockery::mock(PDOStatement::class);

        $pdo->shouldReceive('query')
            ->once()
            ->with('SELECT @p_id AS `p_id`, @p_name AS `p_name`')
            ->andReturn($stmt);
        $stmt->shouldReceive('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->andReturn(['p_id' => 42, 'p_name' => 'France']);
        $stmt->shouldReceive('closeCursor')->once();

        $params = [
            new SqlParameter('p_in', 'x', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::INOUT),
        ];

        $result = $this->driver->getOutputValues($params, $pdo);

        expect($result)->toBe(['p_id' => 42, 'p_name' => 'France']);
    });

    it('returns empty array when SELECT returns false', function () {
        $pdo = Mockery::mock(PDO::class);
        $stmt = Mockery::mock(PDOStatement::class);

        $pdo->shouldReceive('query')->andReturn($stmt);
        $stmt->shouldReceive('fetch')->with(PDO::FETCH_ASSOC)->andReturn(false);
        $stmt->shouldReceive('closeCursor');

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        expect($this->driver->getOutputValues($params, $pdo))->toBe([]);
    });
});

