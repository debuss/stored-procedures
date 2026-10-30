<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

/**
 * PostgreSQL driver for stored functions and procedures.
 *
 * Functions are called with `SELECT * FROM function_name(...)`, procedures with
 * `CALL procedure_name(...)`. The routine kind is looked up in `pg_proc` (when a
 * PDO instance is available) and cached per name.
 *
 * In PostgreSQL, OUT and INOUT values are returned as columns of a single result
 * row — read them from the result set rows. INOUT parameters are passed in the call
 * like IN parameters. OUT parameters are omitted for functions and passed as NULL
 * for procedures, as PostgreSQL requires.
 */
class PgSql extends AbstractDriver implements DriverInterface
{

    /**
     * Cache of routine kinds, keyed by routine name: true for a procedure, false for a function.
     *
     * @var array<string, bool>
     */
    private array $procedures = [];

    /**
     * @param PDO|null $pdo Used to detect whether a routine is a procedure. Without it, every routine is called as a function.
     */
    public function __construct(
        private readonly ?PDO $pdo = null
    ) {}

    /**
     * Build the CALL (procedure) or SELECT * FROM (function) syntax for PostgreSQL.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string
    {
        $this->assertValidProcedureName($stored_procedure);
        $this->assertValidParameterNames($parameters);

        if ($this->isProcedure($stored_procedure)) {
            $placeholders = array_map(
                fn(SqlParameter $param): string => $param->mode === ParameterMode::OUT ? 'NULL' : ":{$param->name}",
                $parameters
            );

            return "CALL {$stored_procedure}(" . implode(', ', $placeholders) . ")";
        }

        $inParams = array_filter(
            $parameters,
            fn(SqlParameter $param): bool => $param->mode !== ParameterMode::OUT
        );

        $placeholders = array_map(
            fn(SqlParameter $param): string => ":{$param->name}",
            $inParams
        );

        return "SELECT * FROM {$stored_procedure}(" . implode(', ', $placeholders) . ")";
    }

    /**
     * Bind IN and INOUT parameters to the statement.
     *
     * OUT parameters are ignored — PostgreSQL returns them as result set columns.
     *
     * @inheritDoc
     */
    public function bindParameters(PDOStatement $statement, array $parameters, PDO $pdo): void
    {
        foreach ($parameters as $parameter) {
            if ($parameter->mode === ParameterMode::OUT) {
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

    /**
     * Check in the catalog whether the routine is a procedure (prokind = 'p').
     *
     * Unquoted identifiers are folded to lower case by PostgreSQL, so the name is
     * lower-cased before the lookup. An unqualified name is resolved through the
     * search_path. If the name is unknown or ambiguous, it is treated as a function.
     */
    private function isProcedure(string $name): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        if (isset($this->procedures[$name])) {
            return $this->procedures[$name];
        }

        $parts = explode('.', strtolower($name));
        $routine = array_pop($parts);
        $schema = array_pop($parts);

        if ($schema === null) {
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT p.prokind FROM pg_catalog.pg_proc p WHERE p.proname = ? AND pg_catalog.pg_function_is_visible(p.oid)'
            );
            $statement->execute([$routine]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT p.prokind FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace WHERE p.proname = ? AND n.nspname = ?'
            );
            $statement->execute([$routine, $schema]);
        }

        $kinds = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement->closeCursor();

        return $this->procedures[$name] = $kinds === ['p'];
    }
}
