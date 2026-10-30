<?php

namespace StoredProcedures\Driver;

use PDO;
use PDOException;
use PDOStatement;
use StoredProcedures\Exception\ExecutionException;
use StoredProcedures\Exception\ProcedureErrorException;
use StoredProcedures\Exception\StoredProcedureException;
use StoredProcedures\Exception\TimeoutException;
use StoredProcedures\ParameterMode;
use StoredProcedures\SqlParameter;

abstract class AbstractDriver implements DriverInterface
{

    /**
     * Declared parameter names, per procedure, or null for a procedure not found in the metadata.
     *
     * @var array<string, list<string>|null>
     */
    private array $declarations = [];

    /**
     * @param PDO $pdo
     * @param bool $match_parameters_by_name Match the parameters to the declared ones by name, instead of by position
     */
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly bool $match_parameters_by_name = false
    ) {}

    /**
     * Build the SQL calling the stored procedure.
     *
     * @param string $stored_procedure
     * @param SqlParameter[] $parameters
     * @return string
     * @throws StoredProcedureException
     */
    abstract public function getQuerySyntax(string $stored_procedure, array $parameters = []): string;

    /**
     * Validate that a stored procedure name contains only safe characters.
     *
     * @throws StoredProcedureException
     */
    protected function assertValidProcedureName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $name)) {
            throw new StoredProcedureException("Invalid stored procedure name: '$name'");
        }
    }

    /**
     * Validate that all parameter names contain only safe characters.
     *
     * Parameter names are interpolated into SQL (e.g. MySQL user variables @name,
     * PostgreSQL named notation name => value), so they must be validated to prevent injection.
     *
     * @param array<SqlParameter|null> $parameters
     * @throws StoredProcedureException
     */
    protected function assertValidParameterNames(array $parameters): void
    {
        foreach (array_filter($parameters) as $parameter) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $parameter->name)) {
                throw new StoredProcedureException("Invalid parameter name: '{$parameter->name}'");
            }
        }
    }

    /**
     * @param SqlParameter[] $parameters
     * @return SqlParameter[] The OUT and INOUT parameters
     */
    protected function outputParameters(array $parameters): array
    {
        return array_filter(
            $parameters,
            fn(SqlParameter $param): bool => $param->mode !== ParameterMode::IN
        );
    }

    /**
     * Order the parameters as the procedure declares them, matching their names case-insensitively.
     *
     * @param string $stored_procedure
     * @param SqlParameter[] $parameters
     * @return list<SqlParameter|null> The parameters in declaration order, null for a declared parameter not given.
     *                                 Unchanged when the procedure is not found in the metadata: the database reports it.
     * @throws StoredProcedureException When a parameter is not declared by the procedure
     */
    protected function orderByDeclaration(string $stored_procedure, array $parameters): array
    {
        $declared = $this->declaredParameters($stored_procedure);

        if ($declared === null) {
            return array_values($parameters);
        }

        $given = [];
        foreach ($parameters as $parameter) {
            $given[strtolower($parameter->name)] = $parameter;
        }

        $ordered = [];
        foreach ($declared as $name) {
            $ordered[] = $given[strtolower($name)] ?? null;
            unset($given[strtolower($name)]);
        }

        if (!empty($given)) {
            throw new StoredProcedureException(sprintf(
                "Procedure '%s' has no parameter named '%s'. Declared parameters: %s.",
                $stored_procedure,
                reset($given)->name,
                empty($declared) ? 'none' : implode(', ', $declared)
            ));
        }

        return $ordered;
    }

    /**
     * Get the names of the parameters declared by the procedure, cached per procedure.
     *
     * @param string $stored_procedure
     * @return list<string>|null The parameter names, or null when the procedure is not found
     */
    protected function declaredParameters(string $stored_procedure): ?array
    {
        if (!array_key_exists($stored_procedure, $this->declarations)) {
            $this->declarations[$stored_procedure] = $this->fetchDeclaredParameters($stored_procedure);
        }

        return $this->declarations[$stored_procedure];
    }

    /**
     * Get the names of the parameters declared by the procedure, in declaration order.
     *
     * Drivers that match the parameters by name override it.
     *
     * @param string $stored_procedure
     * @return list<string>|null The parameter names, or null when the procedure is not found
     */
    protected function fetchDeclaredParameters(string $stored_procedure): ?array
    {
        return null;
    }

    /**
     * Convert an error reported by the database while calling the procedure.
     */
    public function convertException(string $stored_procedure, PDOException $exception): ExecutionException
    {
        [$sql_state, $code, $message] = ($exception->errorInfo ?? []) + [null, null, null];

        $sql_state = is_string($sql_state) ? $sql_state : (string) $exception->getCode();
        $code = is_int($code) ? $this->driverCode($code) : null;
        $message = $this->cleanMessage(is_string($message) && $message !== '' ? $message : $exception->getMessage());

        $class = match (true) {
            $this->isTimeout($sql_state, $code) => TimeoutException::class,
            $this->isRaisedError($sql_state, $code) => ProcedureErrorException::class,
            default => ExecutionException::class,
        };

        return new $class($message, $stored_procedure, $sql_state, $code, $exception);
    }

    /**
     * Tell whether the call was canceled because it took longer than the timeout.
     */
    protected function isTimeout(string $sql_state, ?int $code): bool
    {
        return false;
    }

    /**
     * Tell whether the error was raised on purpose by the procedure (e.g. a business rule violation).
     */
    abstract protected function isRaisedError(string $sql_state, ?int $code): bool;

    /**
     * Get the database-specific error code, or null when it is meaningless.
     */
    protected function driverCode(int $code): ?int
    {
        return $code;
    }

    /**
     * Remove the driver prefixes and the extra lines from the database message.
     */
    protected function cleanMessage(string $message): string
    {
        return trim(explode("\n", $message, 2)[0]);
    }

    /**
     * Default implementation: iterate through multiple row sets via nextRowset().
     *
     * Works for drivers that support multiple row sets (MySQL, SQL Server).
     * Override in drivers that do not (PostgreSQL).
     *
     * @param PDOStatement $statement An already-executed statement
     * @return list<array<mixed>>
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
