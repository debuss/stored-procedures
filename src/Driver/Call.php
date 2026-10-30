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
     * @param Closure(list<array<mixed>>): array<string, mixed> $outputs Reads the output values, given the fetched row sets
     * @param (Closure(PDOStatement): void)|null $execute Executes the statement, when it takes more than
     *                                                    PDOStatement::execute() (e.g. to apply a timeout)
     */
    public function __construct(
        public PDOStatement $statement,
        private Closure $outputs,
        private ?Closure $execute = null
    ) {}

    /**
     * Execute the prepared statement.
     */
    public function execute(): void
    {
        if ($this->execute === null) {
            $this->statement->execute();

            return;
        }

        ($this->execute)($this->statement);
    }

    /**
     * Get the output parameter values, keyed by parameter name.
     *
     * Must be called after the statement has been executed and its row sets fetched.
     *
     * @param list<array<mixed>> $row_sets
     * @return array<string, mixed>
     */
    public function getOutputValues(array $row_sets): array
    {
        return ($this->outputs)($row_sets);
    }
}
