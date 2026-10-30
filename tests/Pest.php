<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Connect to one of the demo databases from docker/docker-compose.yml.
 *
 * The DSN can be overridden with the MYSQL_DSN, PGSQL_DSN and SQLSRV_DSN environment
 * variables. The current test is skipped when the database is unreachable.
 */
function integrationPdo(string $driver): PDO
{
    static $unreachable = [];

    [$dsn, $user, $password] = match ($driver) {
        'mysql'  => [getenv('MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=demo', 'demo', 'demo'],
        'pgsql'  => [getenv('PGSQL_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=demo', 'demo', 'demo'],
        'sqlsrv' => [getenv('SQLSRV_DSN') ?: 'sqlsrv:Server=127.0.0.1,1433;Database=demo;TrustServerCertificate=1;LoginTimeout=2', 'sa', 'Demo@12345'],
    };

    if (!extension_loaded("pdo_$driver")) {
        test()->markTestSkipped("Extension pdo_$driver is not loaded.");
    }

    if (isset($unreachable[$driver])) {
        test()->markTestSkipped($unreachable[$driver]);
    }

    try {
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ] + ($driver === 'sqlsrv' ? [] : [PDO::ATTR_TIMEOUT => 2]));
    } catch (PDOException $e) {
        $unreachable[$driver] = "Database $driver is unreachable: {$e->getMessage()}";
        test()->markTestSkipped($unreachable[$driver]);
    }
}
