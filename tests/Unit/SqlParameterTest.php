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


describe('from() with objects', function () {
    it('infers DATETIME for a date', function () {
        expect(SqlParameter::from('p_date', new DateTimeImmutable())->type)->toBe(DbType::DATETIME);
    });

    it('infers the type of a backed enum from its value', function () {
        expect(SqlParameter::from('p_status', Tests\Fixtures\Status::Published)->type)->toBe(DbType::INTEGER);
    });
});

describe('toPdoValue()', function () {
    it('returns scalar values unchanged', function (mixed $value) {
        expect(SqlParameter::from('p', $value)->toPdoValue())->toBe($value);
    })->with([null, true, 42, 9.5, 'France']);

    it('returns the value of a backed enum', function () {
        expect(SqlParameter::from('p_iso', Tests\Fixtures\Iso::France)->toPdoValue())->toBe('FR');
    });

    it('formats a date as a datetime', function () {
        $date = new DateTimeImmutable('2026-10-30 12:34:56.789');

        expect(SqlParameter::from('p_date', $date)->toPdoValue())->toBe('2026-10-30 12:34:56');
    });

    it('formats a date as a date for DbType::DATE', function () {
        $param = new SqlParameter('p_date', new DateTimeImmutable('2026-10-30 12:34:56'), DbType::DATE);

        expect($param->toPdoValue())->toBe('2026-10-30');
    });

    it('throws for an enum without backing value', function () {
        expect(fn() => SqlParameter::from('p_color', Tests\Fixtures\Color::Red)->toPdoValue())
            ->toThrow(StoredProcedures\Exception\StoredProcedureException::class, "Parameter 'p_color': the enum Tests\Fixtures\Color must be backed to be bound.");
    });
});
