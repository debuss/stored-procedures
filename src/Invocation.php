<?php

namespace StoredProcedures;

/**
 * A call to a stored procedure, as seen by the interceptors (see {@see SqlConnection::addInterceptor()}).
 *
 * The parameter values are left out on purpose: they may hold personal data or credentials.
 */
final readonly class Invocation
{

    /**
     * @param string $procedure The stored procedure name
     * @param list<string> $parameters The parameter names
     * @param string $driver The PDO driver name: mysql, pgsql, sqlsrv or dblib
     * @param int|null $timeout The maximum duration of the call in seconds, or null for none
     */
    public function __construct(
        public string $procedure,
        public array $parameters,
        public string $driver,
        public ?int $timeout = null
    ) {}
}
