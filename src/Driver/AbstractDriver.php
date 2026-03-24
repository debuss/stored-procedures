<?php

namespace StoredProcedures\Driver;

use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\SqlParameter;

abstract class AbstractDriver
{

    /**
     * Validate that a stored procedure name contains only safe characters.
     *
     * @throws StoredProcedureException
     */
    protected function assertValidProcedureName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new StoredProcedureException("Invalid stored procedure name: '$name'");
        }
    }

    /**
     * Validate that all parameter names contain only safe characters.
     *
     * Parameter names are interpolated into SQL (e.g. MySQL user variables @name,
     * SQL Server EXEC @name), so they must be validated to prevent injection.
     *
     * @param SqlParameter[] $parameters
     * @throws StoredProcedureException
     */
    protected function assertValidParameterNames(array $parameters): void
    {
        foreach ($parameters as $parameter) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $parameter->name)) {
                throw new StoredProcedureException("Invalid parameter name: '{$parameter->name}'");
            }
        }
    }

    /**
     * Default implementation: iterate through multiple row sets via nextRowset().
     *
     * Works for drivers that support multiple row sets (MySQL, SQL Server).
     * Override in drivers that do not (PostgreSQL).
     *
     * @param PDOStatement $statement An already-executed statement
     * @return array<int, array>
     */
    public function fetchRowSets(PDOStatement $statement): array
    {
        $rowSets = [];

        do {
            if ($statement->columnCount() > 0) {
                $rowSets[] = $statement->fetchAll();
            }
        } while ($statement->nextRowset());

        return $rowSets;
    }
}
