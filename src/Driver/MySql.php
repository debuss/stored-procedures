<?php

namespace StoredProcedures\Driver;

use PDO;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

/**
 * MySQL driver.
 *
 * MySQL has no named notation for CALL: parameters are passed in the order they are declared.
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
    public function prepare(string $stored_procedure, array $parameters): Call
    {
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
