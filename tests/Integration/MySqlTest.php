<?php

use StoredProcedures\DbType;
use StoredProcedures\Exception\NoResultsException;
use StoredProcedures\SqlConnection;

beforeEach(function () {
    $this->pdo = integrationPdo('mysql');
    $this->pdo->beginTransaction();
    $this->connection = new SqlConnection($this->pdo);
});

afterEach(function () {
    if (isset($this->pdo) && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
});

it('queries all rows', function () {
    $result = $this->connection->query('sp_fetch_all_countries');

    expect($result)->toHaveCount((int) $this->connection->querySingle('sp_count_countries')->total)
        ->and($result->rows()[0])->toHaveProperties(['id', 'iso', 'name']);
});

it('calls a schema-qualified procedure', function () {
    $result = $this->connection->query('demo.sp_fetch_all_countries');

    expect($result)->toHaveCount((int) $this->connection->querySingle('sp_count_countries')->total);
});

it('returns null from queryFirstOrDefault when no default is given', function () {
    expect($this->connection->queryFirstOrDefault('sp_fetch_country_by_iso', ['p_iso' => 'ZZ']))->toBeNull();
});

it('keeps the connection usable after querySingle finds no rows', function () {
    expect(fn() => $this->connection->querySingle('sp_fetch_country_by_iso', ['p_iso' => 'ZZ']))
        ->toThrow(NoResultsException::class);

    expect($this->connection->querySingle('sp_fetch_country_by_iso', ['p_iso' => 'FR'])->name)->toBe('France');
});

it('reads an OUT parameter', function () {
    $result = $this->connection
        ->procedure('sp_insert_and_return_country')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_country_id', DbType::INTEGER)
        ->all();

    expect((int) $result->getOutputValue('p_country_id'))->toBeGreaterThan(10);
});

it('reads an INOUT parameter', function () {
    $result = $this->connection
        ->procedure('sp_search_and_return_country')
        ->input('p_iso', 'FR')
        ->inout('p_name', '', DbType::STRING, 100)
        ->all();

    expect($result->getOutputValue('p_name'))->toBe('France');
});

it('returns the affected rows and OUT parameters from execute()', function () {
    $result = $this->connection
        ->procedure('sp_insert_and_return_country')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_country_id', DbType::INTEGER)
        ->execute();

    expect($result->getAffectedRows())->toBe(1)
        ->and((int) $result->getOutputValue('p_country_id'))->toBeGreaterThan(10);
});
