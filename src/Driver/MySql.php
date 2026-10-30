<?php

namespace StoredProcedures\Driver;

use PDO;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

/**
 * MySQL driver.
 *
 * MySQL has no named notation for CALL: parameters are passed in the order they are declared,
 * unless they are matched by name, using the parameters declared in INFORMATION_SCHEMA.
 * MySQL has no default parameter values: every declared parameter must then be given.
 *
 * MySQL cannot limit the execution time of a stored procedure: max_execution_time only
 * applies to SELECT statements, so a timeout is rejected.
 */
class MySql extends AbstractDriver
{

    /**
     * Build the CALL syntax for MySQL stored procedures.
     *
     * IN parameters use PDO named placeholders (:name), while OUT and INOUT
     * parameters use MySQL user variables (@name).
     *
     * @inheritDoc
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string
    {
        $this->assertValidProcedureName($stored_procedure);
        $this->assertValidParameterNames($parameters);

        $placeholders = array_map(
            fn(SqlParameter $param): string => match ($param->mode) {
                ParameterMode::IN => ":{$param->name}",
                ParameterMode::OUT, ParameterMode::INOUT => "@{$param->name}",
            },
            $parameters
        );

        return "CALL {$this->escapeIdentifier($stored_procedure)}(" . implode(', ', $placeholders) . ")";
    }

    /**
     * Bind IN parameters to the statement and initialize user variables for INOUT parameters.
     *
     * OUT parameters require no binding — they use MySQL @user_variables that are
     * written to by the procedure and read back with a single SELECT.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function prepare(string $stored_procedure, array $parameters, ?int $timeout = null): Call
    {
        if ($timeout !== null) {
            throw new StoredProcedureException(
                'MySQL cannot limit the execution time of a stored procedure: max_execution_time only applies to SELECT statements.'
            );
        }

        if ($this->match_parameters_by_name) {
            $parameters = $this->requireAll($stored_procedure, $this->orderByDeclaration($stored_procedure, $parameters));
        }

        $statement = $this->pdo->prepare($this->getQuerySyntax($stored_procedure, $parameters));

        foreach ($parameters as $parameter) {
            match ($parameter->mode) {
                ParameterMode::IN => $statement->bindValue(
                    ":{$parameter->name}",
                    $parameter->toPdoValue(),
                    $parameter->type->toPdoType()
                ),
                ParameterMode::INOUT => $this->initUserVariable($parameter),
                ParameterMode::OUT => null,
            };
        }

        return new Call($statement, fn(): array => $this->fetchUserVariables($this->outputParameters($parameters)));
    }

    /**
     * @param list<SqlParameter|null> $parameters The parameters in declaration order
     * @return list<SqlParameter>
     * @throws StoredProcedureException When a declared parameter is not given
     */
    private function requireAll(string $stored_procedure, array $parameters): array
    {
        $given = array_filter($parameters);

        if (count($given) !== count($parameters)) {
            $missing = array_diff_key($this->declaredParameters($stored_procedure) ?? [], $given);

            throw new StoredProcedureException(
                "Procedure '$stored_procedure' expects the parameter '" . reset($missing) . "'."
            );
        }

        return array_values($given);
    }

    /**
     * @inheritDoc
     */
    protected function fetchDeclaredParameters(string $stored_procedure): ?array
    {
        $parts = explode('.', $stored_procedure);
        $routine = array_pop($parts);
        $schema = array_pop($parts);

        $statement = $this->pdo->prepare(
            'SELECT p.PARAMETER_NAME FROM INFORMATION_SCHEMA.ROUTINES r'
            . ' LEFT JOIN INFORMATION_SCHEMA.PARAMETERS p ON p.SPECIFIC_SCHEMA = r.ROUTINE_SCHEMA AND p.SPECIFIC_NAME = r.SPECIFIC_NAME AND p.ORDINAL_POSITION > 0'
            . " WHERE r.ROUTINE_TYPE = 'PROCEDURE' AND r.ROUTINE_SCHEMA = COALESCE(?, DATABASE()) AND r.ROUTINE_NAME = ?"
            . ' ORDER BY p.ORDINAL_POSITION'
        );
        $statement->execute([$schema, $routine]);
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement->closeCursor();

        // No row: unknown procedure. A single NULL row: procedure without parameters.
        return empty($names) ? null : array_values(array_filter($names, 'is_string'));
    }

    /**
     * SIGNAL with the SQLSTATE '45000' ("unhandled user-defined exception"), reported with the error code 1644.
     */
    protected function isRaisedError(string $sql_state, ?int $code): bool
    {
        return $sql_state === '45000' || $code === 1644;
    }

    /**
     * Initialize a MySQL user variable with the INOUT parameter's initial value.
     */
    private function initUserVariable(SqlParameter $parameter): void
    {
        $statement = $this->pdo->prepare("SET @{$parameter->name} = ?");
        $statement->bindValue(1, $parameter->toPdoValue(), $parameter->type->toPdoType());
        $statement->execute();
        $statement->closeCursor();
    }

    /**
     * Retrieve output values from MySQL user variables in a single query.
     *
     * @param SqlParameter[] $parameters The OUT and INOUT parameters
     * @return array<string, mixed> Output values keyed by parameter name
     */
    private function fetchUserVariables(array $parameters): array
    {
        if (empty($parameters)) {
            return [];
        }

        $parameters = array_values($parameters);
        $variables = array_map(fn(SqlParameter $param): string => "@{$param->name}", $parameters);

        $statement = $this->pdo->prepare('SELECT ' . implode(', ', $variables));
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();

        $outputs = [];
        foreach ($parameters as $index => $parameter) {
            $outputs[$parameter->name] = is_array($row) ? $row[$index] : null;
        }

        return $outputs;
    }

    /**
     * Escape a MySQL identifier with backticks.
     *
     * Schema-qualified names (e.g. "db.sp_name") are split and each part
     * is individually escaped: `db`.`sp_name`.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            fn(string $part): string => "`{$part}`",
            explode('.', $identifier)
        ));
    }
}
