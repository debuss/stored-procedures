<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

class MySql extends AbstractDriver implements DriverInterface
{

    /**
     * Build the CALL syntax for MySQL stored procedures.
     *
     * IN parameters use PDO named placeholders (:name), while OUT and INOUT
     * parameters use MySQL user variables (@name).
     *
     * @inheritDoc
     * @throws StoredProcedureException
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
     * written to by the procedure and read back via {@see getOutputValues()}.
     *
     * @inheritDoc
     */
    public function bindParameters(PDOStatement $statement, array $parameters, PDO $pdo): void
    {
        foreach ($parameters as $parameter) {
            match ($parameter->mode) {
                ParameterMode::IN => $statement->bindValue(
                    ":{$parameter->name}",
                    $parameter->value,
                    $parameter->type->toPdoType()
                ),
                ParameterMode::INOUT => $this->initUserVariable($pdo, $parameter),
                ParameterMode::OUT => null,
            };
        }
    }

    /**
     * Retrieve output values from MySQL user variables in a single query.
     *
     * @inheritDoc
     * @return array<string, mixed> Output values keyed by parameter name
     */
    public function getOutputValues(array $parameters, PDO $pdo): array
    {
        $outputParams = array_filter(
            $parameters,
            fn(SqlParameter $param): bool => $param->mode !== ParameterMode::IN
        );

        if (empty($outputParams)) {
            return [];
        }

        $selects = array_map(
            fn(SqlParameter $param): string => "@{$param->name} AS `{$param->name}`",
            $outputParams
        );

        $stmt = $pdo->query('SELECT ' . implode(', ', $selects));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ?: [];
    }

    /**
     * Initialize a MySQL user variable with the INOUT parameter's initial value.
     */
    private function initUserVariable(PDO $pdo, SqlParameter $parameter): void
    {
        $stmt = $pdo->prepare("SET @{$parameter->name} = ?");
        $stmt->execute([$parameter->value]);
        $stmt->closeCursor();
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

