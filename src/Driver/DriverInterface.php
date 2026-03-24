<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOStatement;
use StoredProcedures\SqlParameter;

interface DriverInterface
{

    /**
     * @param string $stored_procedure
     * @param SqlParameter[] $parameters
     * @return string
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string;

    /**
     * @param PDOStatement $statement
     * @param SqlParameter[] $parameters
     * @param PDO $pdo
     * @return void
     */
    public function bindParameters(PDOStatement $statement, array $parameters, PDO $pdo): void;

    /**
     * Fetch all row sets from an executed statement.
     *
     * Each driver handles this differently: MySQL/SQL Server support multiple
     * row sets via nextRowset(), while PostgreSQL returns a single row set.
     *
     * @param PDOStatement $statement An already-executed statement
     * @return array<int, array> Array of row sets
     */
    public function fetchRowSets(PDOStatement $statement): array;

    /**
     * @param SqlParameter[] $parameters
     * @param PDO $pdo
     * @return array
     */
    public function getOutputValues(array $parameters, PDO $pdo): array;

}
