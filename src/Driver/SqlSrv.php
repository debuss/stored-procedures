<?php

namespace StoredProcedures\Driver;

use PDO;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

/**
 * SQL Server driver.
 *
 * Parameters are passed in the order they are declared: the ODBC driver rejects
 * EXEC with named OUTPUT arguments when they are bound as output parameters.
 */
class SqlSrv extends AbstractDriver
{

    /**
     * Build the ODBC escape syntax for SQL Server stored procedures.
     *
     * Uses {CALL [sp_name](:p1, :p2, :p3)} — all parameters use named PDO
     * placeholders regardless of mode. Output behavior is controlled by
     * PDO::PARAM_INPUT_OUTPUT in {@see prepare()}, not by SQL keywords.
     *
     * @inheritDoc
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
     * into the bound variables. These values are only available once all the
     * row sets have been consumed.
     *
     * @inheritDoc
     * @throws StoredProcedureException
     */
    public function prepare(string $stored_procedure, array $parameters): Call
    {
        $statement = $this->pdo->prepare($this->getQuerySyntax($stored_procedure, $parameters));
        $outputs = [];

        foreach ($parameters as $parameter) {
            $placeholder = ":{$parameter->name}";
            $pdoType = $parameter->type->toPdoType();

            if ($parameter->mode === ParameterMode::IN) {
                $statement->bindValue($placeholder, $parameter->toPdoValue(), $pdoType);
                continue;
            }

            $outputs[$parameter->name] = $parameter->toPdoValue();
            $statement->bindParam(
                $placeholder,
                $outputs[$parameter->name],
                $pdoType | PDO::PARAM_INPUT_OUTPUT,
                $parameter->size
            );
        }

        // Captured by reference: PDO writes the output values into $outputs after execute()
        return new Call($statement, function () use (&$outputs): array {
            return $outputs;
        });
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
