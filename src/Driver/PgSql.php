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
 * `CALL procedure_name(...)`. The routine kind is looked up in `pg_proc` and cached per name.
 *
 * Parameters use the named notation (`p_name => :p_name`), so they are matched by name
 * and their order does not matter (whatever the match_parameters_by_name option). OUT parameters are omitted for functions and passed
 * as NULL for procedures, as PostgreSQL requires.
 *
 * PostgreSQL returns OUT and INOUT values as columns of the result row: they are available
 * both in the rows and as output values.
 */
class PgSql extends AbstractDriver
{

    /**
     * Cache of routine kinds, keyed by routine name: true for a procedure, false for a function.
     *
     * @var array<string, bool>
     */
    private array $procedures = [];

    /**
     * Build the CALL (procedure) or SELECT * FROM (function) syntax for PostgreSQL.
     *
     * @inheritDoc
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string
    {
        $this->assertValidProcedureName($stored_procedure);
        $this->assertValidParameterNames($parameters);

        if ($this->isProcedure($stored_procedure)) {
            $arguments = array_map(
                fn(SqlParameter $param): string => "{$param->name} => " . ($param->mode === ParameterMode::OUT ? 'NULL' : ":{$param->name}"),
                $parameters
            );

            return "CALL {$stored_procedure}(" . implode(', ', $arguments) . ")";
        }

        $arguments = array_map(
            fn(SqlParameter $param): string => "{$param->name} => :{$param->name}",
            $this->inputParameters($parameters)
        );

        return "SELECT * FROM {$stored_procedure}(" . implode(', ', $arguments) . ")";
    }

    /**
     * Bind IN and INOUT parameters to the statement.
     *
     * OUT parameters are not bound — PostgreSQL returns them as result set columns.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function prepare(string $stored_procedure, array $parameters): Call
    {
        $statement = $this->pdo->prepare($this->getQuerySyntax($stored_procedure, $parameters));

        foreach ($this->inputParameters($parameters) as $parameter) {
            $statement->bindValue(
                ":{$parameter->name}",
                $parameter->toPdoValue(),
                $parameter->type->toPdoType()
            );
        }

        $outputs = $this->outputParameters($parameters);

        return new Call($statement, fn(array $row_sets): array => $this->readOutputColumns($outputs, $row_sets));
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
     * @param SqlParameter[] $parameters
     * @return SqlParameter[] The IN and INOUT parameters
     */
    private function inputParameters(array $parameters): array
    {
        return array_filter(
            $parameters,
            fn(SqlParameter $param): bool => $param->mode !== ParameterMode::OUT
        );
    }

    /**
     * Read the output values from the columns of the first row.
     *
     * Unquoted identifiers are folded to lower case by PostgreSQL, so the
     * lower-cased name is used when the exact name is not found.
     *
     * @param SqlParameter[] $parameters The OUT and INOUT parameters
     * @param list<array<mixed>> $row_sets
     * @return array<string, mixed>
     */
    private function readOutputColumns(array $parameters, array $row_sets): array
    {
        $row = $row_sets[0][0] ?? [];
        $columns = is_object($row) ? get_object_vars($row) : (array) $row;

        $outputs = [];
        foreach ($parameters as $parameter) {
            $outputs[$parameter->name] = $columns[$parameter->name] ?? $columns[strtolower($parameter->name)] ?? null;
        }

        return $outputs;
    }

    /**
     * RAISE EXCEPTION without ERRCODE uses the SQLSTATE 'P0001' (raise_exception).
     */
    protected function isRaisedError(string $sql_state, ?int $code): bool
    {
        return $sql_state === 'P0001';
    }

    /**
     * PDO reports the same libpq status (7, PGRES_FATAL_ERROR) for every error: use the SQLSTATE instead.
     */
    protected function driverCode(int $code): ?int
    {
        return null;
    }

    /**
     * Remove the "ERROR:  " prefix, and the CONTEXT, DETAIL and HINT lines.
     */
    protected function cleanMessage(string $message): string
    {
        return parent::cleanMessage(preg_replace('/^ERROR:\s+/', '', $message) ?? $message);
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
