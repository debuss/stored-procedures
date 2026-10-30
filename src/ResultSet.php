<?php

namespace StoredProcedures;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use OutOfRangeException;
use Traversable;
use function array_key_exists;
use function count;

/**
 * Immutable container for one or more row sets returned by a stored procedure,
 * along with any output parameter values and an affected-row count.
 *
 * Single-result-set procedures (the most common case):
 *   foreach ($result as $row)          — iterates rows of the first row set
 *   $result->rows()                    — returns the first row set as an array
 *   count($result)                     — number of rows in the first row set
 *
 * Multi-result-set procedures:
 *   $result->rowSetCount()             — how many row sets were returned
 *   $result->rowSet(0), rowSet(1), …   — access each row set by index
 *   $result->getAllRowSets()           — all row sets as a nested array
 */
readonly class ResultSet implements IteratorAggregate, Countable
{

    public function __construct(
        private array $row_sets,
        private array $output_values = [],
        private int   $affected_rows = 0
    ) {}

    // ---------------------------------------------------------------
    //  Single-result-set convenience (delegates to row set 0)
    // ---------------------------------------------------------------

    /**
     * Get the first (or only) row set.
     *
     * Shortcut for the common case where a procedure returns a single SELECT.
     *
     * @return array
     */
    public function rows(): array
    {
        return $this->row_sets[0] ?? [];
    }

    // ---------------------------------------------------------------
    //  Multi-result-set access
    // ---------------------------------------------------------------

    /**
     * Get the number of row sets returned by the procedure.
     *
     * @return int
     */
    public function rowSetCount(): int
    {
        return count($this->row_sets);
    }

    /**
     * Get a specific row set by its zero-based index.
     *
     * @param int $index
     * @return array
     * @throws OutOfRangeException When the index is out of bounds
     */
    public function rowSet(int $index): array
    {
        if ($index < 0 || $index >= $this->rowSetCount()) {
            throw new OutOfRangeException(
                "Row set index $index is out of range (0..{$this->rowSetCount()} available)."
            );
        }

        return $this->row_sets[$index];
    }

    /**
     * Get all row sets as a nested array.
     *
     * @return array<int, array>
     */
    public function getAllRowSets(): array
    {
        return $this->row_sets;
    }

    // ---------------------------------------------------------------
    //  Output parameters
    // ---------------------------------------------------------------

    /**
     * Get all output parameter values keyed by parameter name.
     *
     * @return array<string, mixed>
     */
    public function getOutputValues(): array
    {
        return $this->output_values;
    }

    /**
     * Get a single output parameter value by name.
     *
     * @param string $name
     * @return mixed
     * @throws OutOfRangeException When no OUT or INOUT parameter has this name
     */
    public function getOutputValue(string $name): mixed
    {
        if (!array_key_exists($name, $this->output_values)) {
            throw new OutOfRangeException("No output parameter named '$name'.");
        }

        return $this->output_values[$name];
    }

    // ---------------------------------------------------------------
    //  Affected rows (for INSERT/UPDATE/DELETE procedures)
    // ---------------------------------------------------------------

    /**
     * Get the number of rows affected by an INSERT/UPDATE/DELETE procedure.
     *
     * @return int
     */
    public function getAffectedRows(): int
    {
        return $this->affected_rows;
    }

    // ---------------------------------------------------------------
    //  IteratorAggregate & Countable (operate on the first row set)
    // ---------------------------------------------------------------

    /**
     * Iterate over the rows of the first row set.
     *
     * For multi-result-set access, use {@see rowSet()} or {@see getAllRowSets()}.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows());
    }

    /**
     * Count the rows in the first row set.
     */
    public function count(): int
    {
        return count($this->rows());
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
