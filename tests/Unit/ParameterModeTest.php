<?php

use StoredProcedures\ParameterMode;

covers(ParameterMode::class);

it('maps IN to 0', function () {
    expect(ParameterMode::IN->toPdoType())->toBe(0);
});

it('maps OUT to PDO::PARAM_INPUT_OUTPUT', function () {
    expect(ParameterMode::OUT->toPdoType())->toBe(PDO::PARAM_INPUT_OUTPUT);
});

it('maps INOUT to PDO::PARAM_INPUT_OUTPUT', function () {
    expect(ParameterMode::INOUT->toPdoType())->toBe(PDO::PARAM_INPUT_OUTPUT);
});

it('has exactly three cases', function () {
    expect(ParameterMode::cases())->toHaveCount(3);
});

