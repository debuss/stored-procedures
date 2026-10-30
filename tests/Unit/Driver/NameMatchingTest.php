<?php

use StoredProcedures\DbType;
use StoredProcedures\Driver\AbstractDriver;
use StoredProcedures\Driver\MySql;
use StoredProcedures\Driver\SqlSrv;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

covers(AbstractDriver::class, MySql::class, SqlSrv::class);

/**
 * Helper: mock the INFORMATION_SCHEMA lookup of the declared parameters.
 *
 * @param list<string|null> $names The PARAMETER_NAME column (a single null: procedure without parameters, none: unknown procedure)
 */
function expectDeclaredParameters(PDO $pdo, array $names, array $expectedArgs): void
{
    $lookup = Mockery::mock(PDOStatement::class);
    $lookup->shouldReceive('execute')->once()->with($expectedArgs);
    $lookup->shouldReceive('fetchAll')->once()->with(PDO::FETCH_COLUMN)->andReturn($names);
    $lookup->shouldReceive('closeCursor')->once();

    $pdo->shouldReceive('prepare')->once()->with(Mockery::pattern('/INFORMATION_SCHEMA\.PARAMETERS/'))->andReturn($lookup);
}

/**
 * Helper: expect the CALL statement, and return it.
 */
function expectCall(PDO $pdo, string $sql): PDOStatement
{
    $statement = Mockery::mock(PDOStatement::class)->shouldIgnoreMissing();
    $pdo->shouldReceive('prepare')->once()->with($sql)->andReturn($statement);

    return $statement;
}

beforeEach(function () {
    $this->pdo = Mockery::mock(PDO::class);
    $this->iso = new SqlParameter('p_iso', 'FR', DbType::STRING);
    $this->name = new SqlParameter('p_name', 'France', DbType::STRING);
});

describe('MySQL', function () {
    beforeEach(function () {
        $this->driver = new MySql($this->pdo, true);
    });

    it('orders the parameters as declared, matching their names case-insensitively', function () {
        expectDeclaredParameters($this->pdo, ['p_iso', 'P_NAME'], [null, 'sp_insert_country']);
        expectCall($this->pdo, 'CALL `sp_insert_country`(:p_iso, :p_name)');

        $this->driver->prepare('sp_insert_country', [$this->name, $this->iso]);
    });

    it('matches the given names case-insensitively', function () {
        expectDeclaredParameters($this->pdo, ['p_iso'], [null, 'sp_fetch_country_by_iso']);
        expectCall($this->pdo, 'CALL `sp_fetch_country_by_iso`(:P_ISO)');

        $this->driver->prepare('sp_fetch_country_by_iso', [new SqlParameter('P_ISO', 'FR', DbType::STRING)]);
    });

    it('looks up schema-qualified procedures in their schema', function () {
        expectDeclaredParameters($this->pdo, ['p_iso'], ['demo', 'sp_fetch_country_by_iso']);
        expectCall($this->pdo, 'CALL `demo`.`sp_fetch_country_by_iso`(:p_iso)');

        $this->driver->prepare('demo.sp_fetch_country_by_iso', [$this->iso]);
    });

    it('throws for a parameter not declared by the procedure', function () {
        expectDeclaredParameters($this->pdo, ['p_iso', 'p_name'], [null, 'sp_insert_country']);
        $typo = new SqlParameter('p_nme', 'France', DbType::STRING);

        expect(fn() => $this->driver->prepare('sp_insert_country', [$this->iso, $typo]))
            ->toThrow(StoredProcedureException::class, "Procedure 'sp_insert_country' has no parameter named 'p_nme'. Declared parameters: p_iso, p_name.");
    });

    it('throws for a procedure without parameters', function () {
        expectDeclaredParameters($this->pdo, [null], [null, 'sp_fetch_all_countries']);

        expect(fn() => $this->driver->prepare('sp_fetch_all_countries', [$this->iso]))
            ->toThrow(StoredProcedureException::class, 'Declared parameters: none.');
    });

    it('throws for a declared parameter not given, MySQL having no default values', function () {
        expectDeclaredParameters($this->pdo, ['p_iso', 'p_name'], [null, 'sp_insert_country']);

        expect(fn() => $this->driver->prepare('sp_insert_country', [$this->iso]))
            ->toThrow(StoredProcedureException::class, "Procedure 'sp_insert_country' expects the parameter 'p_name'.");
    });

    it('keeps the given order for a procedure not found in the metadata', function () {
        expectDeclaredParameters($this->pdo, [], [null, 'sp_unknown']);
        expectCall($this->pdo, 'CALL `sp_unknown`(:p_name, :p_iso)');

        $this->driver->prepare('sp_unknown', [$this->name, $this->iso]);
    });

    it('looks up the declared parameters once per procedure', function () {
        expectDeclaredParameters($this->pdo, ['p_iso'], [null, 'sp_fetch_country_by_iso']);
        $this->pdo->shouldReceive('prepare')->twice()->with('CALL `sp_fetch_country_by_iso`(:p_iso)')
            ->andReturn(Mockery::mock(PDOStatement::class)->shouldIgnoreMissing());

        $this->driver->prepare('sp_fetch_country_by_iso', [$this->iso]);
        $this->driver->prepare('sp_fetch_country_by_iso', [$this->iso]);
    });

    it('does not look up the declared parameters by default', function () {
        expectCall($this->pdo, 'CALL `sp_insert_country`(:p_name, :p_iso)');

        (new MySql($this->pdo))->prepare('sp_insert_country', [$this->name, $this->iso]);
    });
});

describe('SQL Server', function () {
    beforeEach(function () {
        $this->driver = new SqlSrv($this->pdo, true);
    });

    it('orders the parameters as declared, without the @ prefix', function () {
        expectDeclaredParameters($this->pdo, ['@p_iso', '@p_name'], [null, 'sp_search_and_return_country']);
        $statement = expectCall($this->pdo, '{CALL [sp_search_and_return_country](:p_iso, :p_name)}');
        $statement->shouldReceive('bindParam')->once()->with(':p_name', Mockery::any(), Mockery::any(), 100);

        $this->driver->prepare('sp_search_and_return_country', [
            new SqlParameter('p_name', '', DbType::STRING, ParameterMode::OUT, 100),
            $this->iso,
        ]);
    });

    it('passes DEFAULT for an omitted parameter, and drops the omitted trailing ones', function () {
        expectDeclaredParameters($this->pdo, ['@p_letter', '@p_limit', '@p_offset', '@p_order'], ['dbo', 'sp_fetch_countries_page']);
        expectCall($this->pdo, '{CALL [dbo].[sp_fetch_countries_page](:p_letter, DEFAULT, :p_offset)}');

        $this->driver->prepare('dbo.sp_fetch_countries_page', [
            new SqlParameter('p_offset', 1, DbType::INTEGER),
            new SqlParameter('p_letter', 'N', DbType::STRING),
        ]);
    });

    it('keeps the given order for catalog-qualified names, which are not looked up', function () {
        expectCall($this->pdo, '{CALL [demo].[dbo].[sp_insert_country](:p_name, :p_iso)}');

        $this->driver->prepare('demo.dbo.sp_insert_country', [$this->name, $this->iso]);
    });
});
