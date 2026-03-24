<?php

use StoredProcedures\DbType;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(SqlParameter::class);

describe('constructor', function () {
    it('stores all properties', function () {
        $param = new SqlParameter('p_name', 'hello', DbType::STRING, ParameterMode::IN, 255);

        expect($param->name)->toBe('p_name')
            ->and($param->value)->toBe('hello')
            ->and($param->type)->toBe(DbType::STRING)
            ->and($param->mode)->toBe(ParameterMode::IN)
            ->and($param->size)->toBe(255);
    });

    it('defaults to IN mode with size 4000', function () {
        $param = new SqlParameter('p_id', 42, DbType::INTEGER);

        expect($param->mode)->toBe(ParameterMode::IN)
            ->and($param->size)->toBe(4000);
    });
});

describe('from()', function () {
    it('infers STRING for a string value', function () {
        $param = SqlParameter::from('p_name', 'hello');

        expect($param->name)->toBe('p_name')
            ->and($param->value)->toBe('hello')
            ->and($param->type)->toBe(DbType::STRING)
            ->and($param->mode)->toBe(ParameterMode::IN);
    });

    it('infers INTEGER for an int value', function () {
        $param = SqlParameter::from('p_id', 42);

        expect($param->type)->toBe(DbType::INTEGER)
            ->and($param->value)->toBe(42);
    });

    it('infers BOOLEAN for a bool value', function () {
        $param = SqlParameter::from('p_flag', true);

        expect($param->type)->toBe(DbType::BOOLEAN)
            ->and($param->value)->toBeTrue();
    });

    it('infers FLOAT for a double value', function () {
        $param = SqlParameter::from('p_price', 9.99);

        expect($param->type)->toBe(DbType::FLOAT)
            ->and($param->value)->toBe(9.99);
    });

    it('infers NULL for a null value', function () {
        $param = SqlParameter::from('p_nullable', null);

        expect($param->type)->toBe(DbType::NULL)
            ->and($param->value)->toBeNull();
    });

    it('defaults to STRING for arrays and objects', function () {
        $param = SqlParameter::from('p_data', [1, 2, 3]);

        expect($param->type)->toBe(DbType::STRING);
    });
});

