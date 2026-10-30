<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\SqlSrv;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(SqlSrv::class);

beforeEach(function () {
    $this->pdo = Mockery::mock(PDO::class);
    $this->driver = new SqlSrv($this->pdo);
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

describe('prepare()', function () {
    it('prepares the {CALL} and binds IN parameters with bindValue', function () {
        $stmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->once()->with('{CALL [sp_find](:p_name)}')->andReturn($stmt);
        $stmt->shouldReceive('bindValue')->once()->with(':p_name', 'France', PDO::PARAM_STR);

        $call = $this->driver->prepare('sp_find', [new SqlParameter('p_name', 'France', DbType::STRING)]);

        expect($call->statement)->toBe($stmt);
    });

    it('binds OUT and INOUT parameters with bindParam and PARAM_INPUT_OUTPUT', function (ParameterMode $mode) {
        $stmt = Mockery::mock(PDOStatement::class);

        $this->pdo->shouldReceive('prepare')->andReturn($stmt);
        $stmt->shouldReceive('bindParam')
            ->once()
            ->with(':p_name', Mockery::any(), PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 100);

        $this->driver->prepare('sp_search', [new SqlParameter('p_name', 'x', DbType::STRING, $mode, 100)]);
    })->with([ParameterMode::OUT, ParameterMode::INOUT]);
});

describe('output values', function () {
    it('returns empty array when there are no output parameters', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $this->pdo->shouldReceive('prepare')->andReturn($stmt);

        $call = $this->driver->prepare('sp_find', [new SqlParameter('p_name', 'France', DbType::STRING)]);

        expect($call->getOutputValues([]))->toBe([]);
    });

    it('returns the values PDO wrote into the bound references', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $bound = [];

        $this->pdo->shouldReceive('prepare')->andReturn($stmt);
        $stmt->shouldReceive('bindParam')->andReturnUsing(function (string $placeholder, &$variable) use (&$bound) {
            $bound[$placeholder] = &$variable;
            return true;
        });

        $call = $this->driver->prepare('sp_insert', [
            new SqlParameter('p_iso', 'FR', DbType::STRING),
            new SqlParameter('p_id', null, DbType::INTEGER, ParameterMode::OUT),
            new SqlParameter('p_name', 'initial', DbType::STRING, ParameterMode::INOUT),
        ]);

        // Simulate PDO writing the output values during execute()
        $bound[':p_id'] = 42;
        $bound[':p_name'] = 'France';

        expect($call->getOutputValues([]))->toBe(['p_id' => 42, 'p_name' => 'France']);
    });

    it('keeps the output values of each call separate', function () {
        $stmt = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
        $this->pdo->shouldReceive('prepare')->andReturn($stmt);

        $first = $this->driver->prepare('sp_a', [new SqlParameter('p_id', 1, DbType::INTEGER, ParameterMode::INOUT)]);
        $second = $this->driver->prepare('sp_b', [new SqlParameter('p_id', 2, DbType::INTEGER, ParameterMode::INOUT)]);

        expect($first->getOutputValues([]))->toBe(['p_id' => 1])
            ->and($second->getOutputValues([]))->toBe(['p_id' => 2]);
    });
});
