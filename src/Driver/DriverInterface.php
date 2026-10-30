<?php

namespace StoredProcedures\Driver;

use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\SqlParameter;

interface DriverInterface
{

    /**
     * Prepare the call to a stored procedure and bind its parameters.
     *
     * @param string $stored_procedure
     * @param SqlParameter[] $parameters
     * @return Call
     * @throws StoredProcedureException
     */
    public function prepare(string $stored_procedure, array $parameters): Call;

    /**
     * Fetch all row sets from an executed statement.
     *
     * Each driver handles this differently: MySQL/SQL Server support multiple
     * row sets via nextRowset(), while PostgreSQL returns a single row set.
     *
     * @param PDOStatement $statement An already-executed statement
     * @return array<int, array<mixed>> Array of row sets
     */
    public function fetchRowSets(PDOStatement $statement): array;

}
