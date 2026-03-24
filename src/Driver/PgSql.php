<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

/**
 * PostgreSQL driver for stored functions.
 *
 * Uses the `SELECT * FROM function_name(...)` syntax. In PostgreSQL, OUT parameters
 * are returned as columns in the result set — they are NOT passed in the function call.
 * Only IN parameters appear in the generated SQL.
 *
 * If you need output values from a PG function, declare them as OUT in the function
 * definition and read them from the result set rows.
 */
class PgSql extends AbstractDriver implements DriverInterface
{

    /**
     * Build the SELECT * FROM function(...) syntax for PostgreSQL.
     *
     * Only IN parameters are included as placeholders. OUT parameters are
     * returned as columns in the result set by PostgreSQL automatically.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string
    {
        $this->assertValidProcedureName($stored_procedure);
        $this->assertValidParameterNames($parameters);

        $inParams = array_filter(
            $parameters,
            fn(SqlParameter $param): bool => $param->mode === ParameterMode::IN
        );

        $placeholders = array_map(
            fn(SqlParameter $param): string => ":{$param->name}",
            $inParams
        );

        return "SELECT * FROM {$stored_procedure}(" . implode(', ', $placeholders) . ")";
    }

    /**
     * Bind IN parameters to the statement.
     *
     * OUT parameters are ignored — PostgreSQL returns them as result set columns.
     *
     * @inheritDoc
     */
    public function bindParameters(PDOStatement $statement, array $parameters, PDO $pdo): void
    {
        foreach ($parameters as $parameter) {
            if ($parameter->mode !== ParameterMode::IN) {
                continue;
            }

            $statement->bindValue(
                ":{$parameter->name}",
                $parameter->value,
                $parameter->type->toPdoType()
            );
        }
    }

    /**
     * PostgreSQL does not support multiple row sets via nextRowset().
     *
     * A single fetchAll() is used. If multiple result sets are needed,
     * call separate functions from the application layer.
     *
     * @inheritDoc
     */
    public function fetchRowSets(PDOStatement $statement): array
    {
        return [$statement->fetchAll()];
    }

    /**
     * PostgreSQL functions return output values as columns in the result set,
     * so there are no separate output variables to retrieve.
     *
     * @inheritDoc
     * @return array Always empty — read output from the result set rows instead.
     */
    public function getOutputValues(array $parameters, PDO $pdo): array
    {
        return [];
    }
}
