<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\SqlSrv;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(SqlSrv::class);

beforeEach(function () {
    $this->driver = new SqlSrv();
});

describe('getQuerySyntax()', function () {
    it('generates {CALL} with no parameters', function () {
        $sql = $this->driver->getQuerySyntax('sp_get_all');

        expect($sql)->toBe('{CALL [sp_get_all]}');
    });

    it('generates {CALL} with IN parameters', function () {
        $params = [
            new SqlParameter('p_name', 'France', DbType::STRING),
            new SqlParameter('p_id', 1, DbType::INTEGER),
        ];

        $sql = $this->driver->getQuerySyntax('sp_find', $params);

        expect($sql)->toBe('{CALL [sp_find](:p_name, :p_id)}');
    });

    it('generates {CALL} with OUT parameters using same placeholder syntax', function () {
        $params = [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_country_id', null, DbType::INTEGER, ParameterMode::OUT),
        ];

        $sql = $this->driver->getQuerySyntax('sp_insert', $params);

        expect($sql)->toBe('{CALL [sp_insert](:p_iso, :p_country_id)}');
    });

    it('escapes schema-qualified names with brackets', function () {
        $sql = $this->driver->getQuerySyntax('dbo.sp_test');

        expect($sql)->toBe('{CALL [dbo].[sp_test]}');
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

    it('calls bindParam with PARAM_INPUT_OUTPUT for OUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindParam')
            ->once()
            ->withArgs(function ($placeholder, &$ref, $type, $size) {
                return $placeholder === ':p_id'
                    && $type === (PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT)
                    && $size === 4000;
            });

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });

    it('calls bindParam with PARAM_INPUT_OUTPUT for INOUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindParam')
            ->once()
            ->withArgs(function ($placeholder, &$ref, $type, $size) {
                return $placeholder === ':p_name'
                    && $ref === 'hello'
                    && $type === (PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT)
                    && $size === 100;
            });

        $params = [new SqlParameter('p_name', 'hello', DbType::STRING, ParameterMode::INOUT, 100)];

        $this->driver->bindParameters($stmt, $params, $pdo);
    });
});

describe('getOutputValues()', function () {
    it('returns empty array when no output parameters', function () {
        $pdo = Mockery::mock(PDO::class);
        $params = [new SqlParameter('p_name', 'France', DbType::STRING)];

        expect($this->driver->getOutputValues($params, $pdo))->toBe([]);
    });

    it('returns bound reference values for OUT parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        // bindParam stores a reference; simulate PDO writing to it
        $stmt->shouldReceive('bindParam')
            ->once()
            ->withArgs(function ($placeholder, &$ref, $type, $size) {
                // Simulate PDO writing an output value into the reference
                $ref = 42;
                return true;
            });

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);
        $result = $this->driver->getOutputValues($params, $pdo);

        expect($result)->toBe(['p_id' => 42]);
    });

    it('clears references after retrieval', function () {
        $stmt = Mockery::mock(PDOStatement::class);
        $pdo = Mockery::mock(PDO::class);

        $stmt->shouldReceive('bindParam')
            ->once()
            ->withArgs(function ($placeholder, &$ref, $type, $size) {
                $ref = 99;
                return true;
            });

        $params = [new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT)];

        $this->driver->bindParameters($stmt, $params, $pdo);

        // First call returns values
        $first = $this->driver->getOutputValues($params, $pdo);
        expect($first)->toBe(['p_id' => 99]);

        // Second call returns null (references cleared)
        $second = $this->driver->getOutputValues($params, $pdo);
        expect($second)->toBe(['p_id' => null]);
    });
});

