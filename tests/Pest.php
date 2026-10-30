<?php

/**
 * Connect to one of the demo databases from docker/docker-compose.yml.
 *
 * The DSN can be overridden with the MYSQL_DSN, PGSQL_DSN and SQLSRV_DSN environment
 * variables. The current test is skipped when the database is unreachable, or fails
 * when the DB_REQUIRED environment variable is set (in CI).
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
        skipIntegrationTest("Extension pdo_$driver is not loaded.");
    }

    if (isset($unreachable[$driver])) {
        skipIntegrationTest($unreachable[$driver]);
    }

    try {
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ] + ($driver === 'sqlsrv' ? [] : [PDO::ATTR_TIMEOUT => 2]));
    } catch (PDOException $e) {
        $unreachable[$driver] = "Database $driver is unreachable: {$e->getMessage()}";
        skipIntegrationTest($unreachable[$driver]);
    }
}

function skipIntegrationTest(string $reason): never
{
    if (getenv('DB_REQUIRED')) {
        throw new RuntimeException($reason);
    }

    test()->markTestSkipped($reason);
}
