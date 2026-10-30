<?php

namespace StoredProcedures\Exception;

use Throwable;

/**
 * The database reported an error while calling a stored procedure.
 *
 * The message is the database message, without the driver prefixes.
 */
class ExecutionException extends StoredProcedureException
{

    public function __construct(
        string $message,
        private readonly string $procedure,
        private readonly string $sql_state,
        private readonly ?int $driver_code = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $driver_code ?? 0, $previous);
    }

    /**
     * Get the name of the stored procedure that failed.
     */
    public function getProcedure(): string
    {
        return $this->procedure;
    }

    /**
     * Get the SQLSTATE error code (e.g. '45000').
     */
    public function getSqlState(): string
    {
        return $this->sql_state;
    }

    /**
     * Get the database-specific error code (e.g. 50001 for a SQL Server THROW),
     * or null when the database has none (PostgreSQL: use the SQLSTATE).
     */
    public function getDriverCode(): ?int
    {
        return $this->driver_code;
    }
}
