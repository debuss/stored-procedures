<?php

use StoredProcedures\DbType;
use StoredProcedures\Exception\NoResultsException;
use StoredProcedures\SqlConnection;

beforeEach(function () {
    $this->pdo = integrationPdo('pgsql');
    $this->pdo->beginTransaction();
    $this->connection = new SqlConnection($this->pdo);
});

afterEach(function () {
    if (isset($this->pdo) && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
});

it('queries all rows from a function', function () {
    $result = $this->connection->query('fn_fetch_all_countries');

    expect($result)->toHaveCount((int) $this->connection->querySingle('fn_count_countries')->fn_count_countries)
        ->and($result->rows()[0])->toHaveProperties(['id', 'iso', 'name']);
});

it('calls a schema-qualified function', function () {
    $result = $this->connection->query('public.fn_fetch_all_countries');

    expect($result)->toHaveCount((int) $this->connection->querySingle('fn_count_countries')->fn_count_countries);
});

it('returns null from queryFirstOrDefault when no default is given', function () {
    expect($this->connection->queryFirstOrDefault('fn_fetch_country_by_iso', ['p_iso' => 'ZZ']))->toBeNull();
});

it('keeps the connection usable after querySingle finds no rows', function () {
    expect(fn() => $this->connection->querySingle('fn_fetch_country_by_iso', ['p_iso' => 'ZZ']))
        ->toThrow(NoResultsException::class);

    expect($this->connection->querySingle('fn_fetch_country_by_iso', ['p_iso' => 'FR'])->name)->toBe('France');
});

it('reads a function OUT parameter from the result row', function () {
    $row = $this->connection
        ->procedure('fn_insert_country_returning_id')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_id', DbType::INTEGER)
        ->single();

    expect($row->p_id)->toBeGreaterThan(10);
});

it('passes a function INOUT parameter value', function () {
    $row = $this->connection
        ->procedure('fn_search_and_return_country')
        ->input('p_iso', 'ZZ')
        ->inout('p_name', 'Unknown')
        ->single();

    expect($row->p_name)->toBe('Unknown');
});

it('calls a procedure with an OUT parameter', function () {
    $row = $this->connection
        ->procedure('sp_insert_and_return_country')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_country_id', DbType::INTEGER)
        ->single();

    expect($row->p_country_id)->toBeGreaterThan(10);
});

it('calls a procedure with an INOUT parameter', function (string $iso, string $expected) {
    $row = $this->connection
        ->procedure('sp_search_and_return_country')
        ->input('p_iso', $iso)
        ->inout('p_name', 'Unknown')
        ->single();

    expect($row->p_name)->toBe($expected);
})->with([
    'known ISO' => ['FR', 'France'],
    'unknown ISO' => ['ZZ', 'Unknown'],
]);
