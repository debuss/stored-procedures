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
 * When they are matched by name, they are ordered with the parameters declared in
 * INFORMATION_SCHEMA, and the parameters not given use their default value.
 */
class SqlSrv extends AbstractDriver
{

    /**
     * Build the ODBC escape syntax for SQL Server stored procedures.
     *
     * Uses {CALL [sp_name](:p1, :p2, :p3)} — all parameters use named PDO
     * placeholders regardless of mode. Output behavior is controlled by
     * PDO::PARAM_INPUT_OUTPUT in {@see prepare()}, not by SQL keywords.
     * A null parameter is passed as DEFAULT, to use its default value.
     *
     * @param string $stored_procedure
     * @param array<SqlParameter|null> $parameters
     * @return string
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
            fn(?SqlParameter $param): string => $param === null ? 'DEFAULT' : ":{$param->name}",
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
        if ($this->match_parameters_by_name) {
            $parameters = $this->orderByDeclaration($stored_procedure, $parameters);

            // The parameters omitted at the end use their default value without DEFAULT
            while (!empty($parameters) && end($parameters) === null) {
                array_pop($parameters);
            }
        }

        $statement = $this->pdo->prepare($this->getQuerySyntax($stored_procedure, $parameters));
        $outputs = [];

        foreach (array_filter($parameters) as $parameter) {
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
     * Catalog-qualified names (e.g. "db.dbo.sp_name") are not looked up: their parameters are passed by position.
     *
     * @inheritDoc
     */
    protected function fetchDeclaredParameters(string $stored_procedure): ?array
    {
        $parts = explode('.', $stored_procedure);

        if (count($parts) > 2) {
            return null;
        }

        $routine = array_pop($parts);
        $schema = array_pop($parts);

        $statement = $this->pdo->prepare(
            'SELECT p.PARAMETER_NAME FROM INFORMATION_SCHEMA.ROUTINES r'
            . ' LEFT JOIN INFORMATION_SCHEMA.PARAMETERS p ON p.SPECIFIC_SCHEMA = r.ROUTINE_SCHEMA AND p.SPECIFIC_NAME = r.SPECIFIC_NAME AND p.ORDINAL_POSITION > 0'
            . " WHERE r.ROUTINE_TYPE = 'PROCEDURE' AND r.ROUTINE_SCHEMA = COALESCE(?, SCHEMA_NAME()) AND r.ROUTINE_NAME = ?"
            . ' ORDER BY p.ORDINAL_POSITION'
        );
        $statement->execute([$schema, $routine]);
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement->closeCursor();

        // No row: unknown procedure. A single NULL row: procedure without parameters.
        return empty($names) ? null : array_map(
            fn(string $name): string => ltrim($name, '@'),
            array_values(array_filter($names, 'is_string'))
        );
    }

    /**
     * THROW and RAISERROR with a message use error numbers from 50000; system errors are below.
     */
    protected function isRaisedError(string $sql_state, ?int $code): bool
    {
        return $code !== null && $code >= 50000;
    }

    /**
     * Remove the "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]" prefix.
     */
    protected function cleanMessage(string $message): string
    {
        return parent::cleanMessage(preg_replace('/^(\[[^\]]*\])+/', '', $message) ?? $message);
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
