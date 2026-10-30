<?php

namespace StoredProcedures\Driver;

use Closure;
use PDOStatement;

/**
 * A prepared stored procedure call: the statement with its parameters bound,
 * and how to read the output values once it has been executed.
 *
 * @internal Created by the drivers, consumed by SqlConnection.
 */
final readonly class Call
{

    /**
     * @param PDOStatement $statement The prepared statement, parameters already bound
     * @param Closure(array<int, array<mixed>>): array<string, mixed> $outputs Reads the output values, given the fetched row sets
     */
    public function __construct(
        public PDOStatement $statement,
        private Closure $outputs
    ) {}

    /**
     * Get the output parameter values, keyed by parameter name.
     *
     * Must be called after the statement has been executed and its row sets fetched.
     *
     * @param array<int, array<mixed>> $row_sets
     * @return array<string, mixed>
     */
    public function getOutputValues(array $row_sets): array
    {
        return ($this->outputs)($row_sets);
    }
}
