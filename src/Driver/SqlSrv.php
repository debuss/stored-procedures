<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOStatement;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

class SqlSrv extends AbstractDriver implements DriverInterface
{

    /**
     * Bound references for OUT/INOUT parameters.
     *
     * PDO writes output values back into these references after execute().
     * They are collected in {@see getOutputValues()} and then cleared.
     *
     * @var array<string, mixed>
     */
    private array $outputReferences = [];

    /**
     * Build the ODBC escape syntax for SQL Server stored procedures.
     *
     * Uses {CALL [sp_name](:p1, :p2, :p3)} — all parameters use named PDO
     * placeholders regardless of mode. Output behavior is controlled by
     * PDO::PARAM_INPUT_OUTPUT in {@see bindParameters()}, not by SQL keywords.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function getQuerySyntax(string $stored_procedure, array $parameters = []): string
    {
        $this->assertValidProcedureName($stored_procedure);
        $this->assertValidParameterNames($parameters);

        $escapedName = $this->escapeIdentifier($stored_procedure);

        if (empty($parameters)) {
            return "{CALL {$escapedName}}";
        }

        $placeholders = array_map(
            fn(SqlParameter $param): string => ":{$param->name}",
            $parameters
        );

        return "{CALL {$escapedName}(" . implode(', ', $placeholders) . ")}";
    }

    /**
     * Bind parameters to the prepared statement.
     *
     * IN parameters use bindValue(). OUT and INOUT parameters use bindParam()
     * with PDO::PARAM_INPUT_OUTPUT so the driver can write output values back
     * into the bound variable after execution.
     *
     * @inheritDoc
     */
    public function bindParameters(PDOStatement $statement, array $parameters, PDO $pdo): void
    {
        $this->outputReferences = [];

        foreach ($parameters as $parameter) {
            $placeholder = ":{$parameter->name}";
            $pdoType = $parameter->type->toPdoType();

            if ($parameter->mode === ParameterMode::IN) {
                $statement->bindValue($placeholder, $parameter->value, $pdoType);
            } else {
                // Store by reference so PDO can write the output value back after execute()
                $this->outputReferences[$parameter->name] = $parameter->value;
                $statement->bindParam(
                    $placeholder,
                    $this->outputReferences[$parameter->name],
                    $pdoType | PDO::PARAM_INPUT_OUTPUT,
                    $parameter->size
                );
            }
        }
    }

    /**
     * Collect output values from the bound references populated by execute().
     *
     * Note: For SQL Server, output parameter values are only available after all
     * result sets have been consumed (closeCursor / nextRowset). SqlConnection
     * handles this before calling this method.
     *
     * @inheritDoc
     * @return array<string, mixed> Output values keyed by parameter name
     */
    public function getOutputValues(array $parameters, PDO $pdo): array
    {
        $output = [];
        foreach ($parameters as $parameter) {
            if ($parameter->mode !== ParameterMode::IN) {
                $output[$parameter->name] = $this->outputReferences[$parameter->name] ?? null;
            }
        }

        // Clear references for the next call
        $this->outputReferences = [];

        return $output;
    }

    /**
     * Escape a SQL Server identifier with brackets.
     *
     * Schema-qualified names (e.g. "dbo.sp_name") are split and each part
     * is individually escaped: [dbo].[sp_name].
     */
    private function escapeIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            fn(string $part): string => "[{$part}]",
            explode('.', $identifier)
        ));
    }
}
