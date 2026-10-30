<?php

use StoredProcedures\DbType;
use StoredProcedures\Exception\ExecutionException;
use StoredProcedures\Exception\NoResultsException;
use StoredProcedures\Exception\ProcedureErrorException;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\Exception\TimeoutException;
use StoredProcedures\SqlConnection;
use Tests\Fixtures\Country;
use Tests\Fixtures\Iso;
use Tests\Fixtures\Moment;

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

it('returns OUT parameters from execute()', function () {
    $result = $this->connection
        ->procedure('sp_insert_and_return_country')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_country_id', DbType::INTEGER)
        ->execute();

    expect($result->getOutputValue('p_country_id'))->toBeGreaterThan(10);
});

it('reads function INOUT parameters as output values', function () {
    $result = $this->connection
        ->procedure('fn_search_and_return_country')
        ->input('p_iso', 'FR')
        ->inout('p_name', 'Unknown')
        ->all();

    expect($result->getOutputValue('p_name'))->toBe('France');
});

it('matches parameters by name, whatever their order', function (string $routine) {
    $result = $this->connection
        ->procedure($routine)
        ->inout('p_name', 'Unknown')
        ->input('p_iso', 'FR')
        ->all();

    expect($result->getOutputValue('p_name'))->toBe('France');
})->with(['fn_search_and_return_country', 'sp_search_and_return_country']);

it('maps rows to a class', function () {
    $countries = $this->connection->query('fn_fetch_all_countries', [], Country::class)->rows();

    expect($countries)->not->toBeEmpty()
        ->each->toBeInstanceOf(Country::class)
        ->and($countries[0]->id)->toBeInt();
});

it('binds a backed enum parameter and maps the row', function () {
    $country = $this->connection
        ->procedure('fn_fetch_country_by_iso')
        ->input('p_iso', Iso::France)
        ->single(Country::class);

    expect($country->name)->toBe('France');
});

it('returns a scalar', function () {
    expect((int) $this->connection->procedure('fn_count_countries')->scalar())->toBeGreaterThanOrEqual(10)
        ->and($this->connection->procedure('fn_fetch_country_by_iso')->input('p_iso', 'ZZ')->scalar())->toBeNull();
});

it('round-trips a date parameter to a date property', function () {
    $moment = $this->connection
        ->procedure('fn_echo_datetime')
        ->input('p_value', new DateTimeImmutable('2026-10-30 12:34:56'))
        ->single(Moment::class);

    expect($moment->value->format('Y-m-d H:i:s'))->toBe('2026-10-30 12:34:56');
});

it('converts the errors raised by the procedure', function () {
    try {
        $this->connection->execute('fn_raise_error', ['p_message' => 'Country ZZ not found']);
        $this->fail('No exception thrown');
    } catch (ProcedureErrorException $e) {
        expect($e->getMessage())->toBe('Country ZZ not found')
            ->and($e->getProcedure())->toBe('fn_raise_error')
            ->and($e->getSqlState())->toBe('P0001')
            ->and($e->getDriverCode())->toBe(null);
    }
});

it('converts the other database errors', function () {
    expect(fn() => $this->connection->execute('fn_does_not_exist'))
        ->toThrow(fn(ExecutionException $e) => expect($e)->not->toBeInstanceOf(ProcedureErrorException::class)
            ->and($e->getSqlState())->toBe('42883')
            ->and($e->getMessage())->toContain('function fn_does_not_exist() does not exist'));
});

it('cancels a call longer than the timeout', function () {
    $start = microtime(true);

    expect(fn() => $this->connection->procedure('fn_sleep')->timeout(1)->input('p_seconds', 3)->execute())
        ->toThrow(TimeoutException::class, 'canceling statement due to statement timeout');

    expect(microtime(true) - $start)->toBeLessThan(2.5);
});

it('restores statement_timeout after a call within a transaction', function () {
    $before = $this->pdo->query('SHOW statement_timeout')->fetchColumn();

    expect($this->connection->withTimeout(5)->procedure('fn_sleep')->input('p_seconds', 0)->scalar())->toBe(0)
        ->and($this->pdo->query('SHOW statement_timeout')->fetchColumn())->toBe($before);
});

it('restores statement_timeout after a call outside a transaction, even when it times out', function () {
    $this->pdo->rollBack();
    $before = $this->pdo->query('SHOW statement_timeout')->fetchColumn();
    $connection = $this->connection->withTimeout(1);

    $connection->procedure('fn_sleep')->input('p_seconds', 0)->scalar();
    expect($this->pdo->query('SHOW statement_timeout')->fetchColumn())->toBe($before);

    expect(fn() => $connection->procedure('fn_sleep')->input('p_seconds', 3)->scalar())->toThrow(TimeoutException::class);
    expect($this->pdo->query('SHOW statement_timeout')->fetchColumn())->toBe($before);
});
