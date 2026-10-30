<?php

namespace StoredProcedures\Driver;

use PDOException;
use PDOStatement;
use StoredProcedures\Exception\ExecutionException;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\SqlParameter;

interface DriverInterface
{

    /**
     * Prepare the call to a stored procedure and bind its parameters.
     *
     * @param string $stored_procedure
     * @param SqlParameter[] $parameters
     * @param int|null $timeout The maximum duration of the call in seconds, or null for none
     * @return Call
     * @throws StoredProcedureException When the database cannot apply the timeout
     */
    public function prepare(string $stored_procedure, array $parameters, ?int $timeout = null): Call;

    /**
     * Fetch all row sets from an executed statement.
     *
     * Each driver handles this differently: MySQL/SQL Server support multiple
     * row sets via nextRowset(), while PostgreSQL returns a single row set.
     *
     * @param PDOStatement $statement An already-executed statement
     * @return list<array<mixed>> Array of row sets
     */
    public function fetchRowSets(PDOStatement $statement): array;

    /**
     * Convert an error reported by the database while calling the procedure.
     *
     * @param string $stored_procedure
     * @param PDOException $exception
     * @return ExecutionException A TimeoutException when the call took longer than the timeout,
     *                             a ProcedureErrorException when the procedure raised the error on purpose
     */
    public function convertException(string $stored_procedure, PDOException $exception): ExecutionException;

}
