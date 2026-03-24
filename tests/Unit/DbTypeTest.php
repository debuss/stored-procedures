<?php

use StoredProcedures\DbType;

covers(DbType::class);

it('maps STRING to PDO::PARAM_STR', function () {
    expect(DbType::STRING->toPdoType())->toBe(PDO::PARAM_STR);
});

it('maps FLOAT to PDO::PARAM_STR', function () {
    expect(DbType::FLOAT->toPdoType())->toBe(PDO::PARAM_STR);
});

it('maps DATE to PDO::PARAM_STR', function () {
    expect(DbType::DATE->toPdoType())->toBe(PDO::PARAM_STR);
});

it('maps DATETIME to PDO::PARAM_STR', function () {
    expect(DbType::DATETIME->toPdoType())->toBe(PDO::PARAM_STR);
});

it('maps INTEGER to PDO::PARAM_INT', function () {
    expect(DbType::INTEGER->toPdoType())->toBe(PDO::PARAM_INT);
});

it('maps BOOLEAN to PDO::PARAM_BOOL', function () {
    expect(DbType::BOOLEAN->toPdoType())->toBe(PDO::PARAM_BOOL);
});

it('maps BINARY to PDO::PARAM_LOB', function () {
    expect(DbType::BINARY->toPdoType())->toBe(PDO::PARAM_LOB);
});

it('maps NULL to PDO::PARAM_NULL', function () {
    expect(DbType::NULL->toPdoType())->toBe(PDO::PARAM_NULL);
});

it('has all expected cases', function () {
    $cases = array_map(fn(DbType $t) => $t->name, DbType::cases());

    expect($cases)->toBe([
        'STRING', 'INTEGER', 'BOOLEAN', 'FLOAT',
        'BINARY', 'DATE', 'DATETIME', 'NULL',
    ]);
});

