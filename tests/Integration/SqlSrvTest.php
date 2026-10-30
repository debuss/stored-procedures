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
    $this->pdo = integrationPdo('sqlsrv');
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
    $result = $this->connection->query('dbo.sp_fetch_all_countries');

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

it('returns OUT parameters from execute()', function () {
    $result = $this->connection
        ->procedure('sp_insert_and_return_country')
        ->input('p_iso', 'LU')
        ->input('p_name', 'Luxembourg')
        ->output('p_country_id', DbType::INTEGER)
        ->execute();

    expect((int) $result->getOutputValue('p_country_id'))->toBeGreaterThan(10);
});

it('maps rows to a class', function () {
    $countries = $this->connection->query('sp_fetch_all_countries', [], Country::class)->rows();

    expect($countries)->not->toBeEmpty()
        ->each->toBeInstanceOf(Country::class)
        ->and($countries[0]->id)->toBeInt();
});

it('binds a backed enum parameter and maps the row', function () {
    $country = $this->connection
        ->procedure('sp_fetch_country_by_iso')
        ->input('p_iso', Iso::France)
        ->single(Country::class);

    expect($country->name)->toBe('France');
});

it('returns a scalar', function () {
    expect((int) $this->connection->procedure('sp_count_countries')->scalar())->toBeGreaterThanOrEqual(10)
        ->and($this->connection->procedure('sp_fetch_country_by_iso')->input('p_iso', 'ZZ')->scalar())->toBeNull();
});

it('round-trips a date parameter to a date property', function () {
    $moment = $this->connection
        ->procedure('sp_echo_datetime')
        ->input('p_value', new DateTimeImmutable('2026-10-30 12:34:56'))
        ->single(Moment::class);

    expect($moment->value->format('Y-m-d H:i:s'))->toBe('2026-10-30 12:34:56');
});

it('converts the errors raised by the procedure', function () {
    try {
        $this->connection->execute('sp_raise_error', ['p_message' => 'Country ZZ not found']);
        $this->fail('No exception thrown');
    } catch (ProcedureErrorException $e) {
        expect($e->getMessage())->toBe('Country ZZ not found')
            ->and($e->getProcedure())->toBe('sp_raise_error')
            ->and($e->getSqlState())->toBe('42000')
            ->and($e->getDriverCode())->toBe(50001);
    }
});

it('converts the other database errors', function () {
    expect(fn() => $this->connection->execute('sp_does_not_exist'))
        ->toThrow(fn(ExecutionException $e) => expect($e)->not->toBeInstanceOf(ProcedureErrorException::class)
            ->and($e->getSqlState())->toBe('42000')
            ->and($e->getMessage())->toContain("Could not find stored procedure 'sp_does_not_exist'"));
});

it('matches the parameters by name when enabled', function () {
    $connection = new SqlConnection($this->pdo, match_parameters_by_name: true);

    $result = $connection
        ->procedure('sp_search_and_return_country')
        ->inout('p_name', '', DbType::STRING, 100)
        ->input('P_ISO', 'FR')
        ->execute();

    expect($result->getOutputValue('p_name'))->toBe('France');
});

it('rejects a parameter not declared by the procedure when matching by name', function () {
    $connection = new SqlConnection($this->pdo, match_parameters_by_name: true);

    expect(fn() => $connection->query('sp_fetch_country_by_iso', ['p_isoo' => 'FR']))
        ->toThrow(StoredProcedureException::class, "Procedure 'sp_fetch_country_by_iso' has no parameter named 'p_isoo'. Declared parameters: p_iso.");
});

it('uses the default value of the parameters omitted when matching by name', function () {
    $connection = new SqlConnection($this->pdo, match_parameters_by_name: true);

    $countries = $connection->query('dbo.sp_fetch_countries_page', ['p_offset' => 1, 'p_letter' => 'N'], Country::class)->rows();

    expect(array_column($countries, 'iso'))->toBe(['NO']);
});

it('cancels a call longer than the timeout', function () {
    $start = microtime(true);

    expect(fn() => $this->connection->procedure('sp_sleep')->timeout(1)->input('p_seconds', 3)->execute())
        ->toThrow(TimeoutException::class, 'Query timeout expired');

    expect(microtime(true) - $start)->toBeLessThan(2.5)
        ->and($this->connection->queryScalar('sp_count_countries'))->toBeGreaterThanOrEqual(10);
});

it('completes a call shorter than the timeout', function () {
    expect((int) $this->connection->withTimeout(5)->procedure('sp_sleep')->input('p_seconds', 1)->scalar())->toBe(1);
});
