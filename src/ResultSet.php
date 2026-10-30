<?php

namespace StoredProcedures;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use OutOfRangeException;
use stdClass;
use StoredProcedures\Exception\MappingException;
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
 *
 * Typed rows: when a class is given (to the constructor, or to rows(), rowSet() and
 * getAllRowSets() to override it), rows are mapped to objects of this class with
 * {@see Hydrator}. Mapping happens on access, so each access returns new objects.
 *
 * @template T = array<string, mixed>|stdClass
 * @implements IteratorAggregate<int, T>
 */
readonly class ResultSet implements IteratorAggregate, Countable
{

    /**
     * @param list<array<mixed>> $row_sets
     * @param array<string, mixed> $output_values
     * @param int $affected_rows
     * @param class-string<T>|null $class The class to map the rows to, or null to keep them as fetched
     */
    public function __construct(
        private array   $row_sets,
        private array   $output_values = [],
        private int     $affected_rows = 0,
        private ?string $class = null
    ) {}

    // ---------------------------------------------------------------
    //  Single-result-set convenience (delegates to row set 0)
    // ---------------------------------------------------------------

    /**
     * Get the first (or only) row set.
     *
     * Shortcut for the common case where a procedure returns a single SELECT.
     *
     * @template U of object
     * @param class-string<U>|null $class The class to map the rows to, instead of the result set's one
     * @return ($class is null ? list<T> : list<U>)
     * @throws MappingException
     */
    public function rows(?string $class = null): array
    {
        return $this->map($this->row_sets[0] ?? [], $class);
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
     * @template U of object
     * @param int $index
     * @param class-string<U>|null $class The class to map the rows to, instead of the result set's one
     * @return ($class is null ? list<T> : list<U>)
     * @throws OutOfRangeException When the index is out of bounds
     * @throws MappingException
     */
    public function rowSet(int $index, ?string $class = null): array
    {
        if ($index < 0 || $index >= $this->rowSetCount()) {
            throw new OutOfRangeException(
                "Row set index $index is out of range (0..{$this->rowSetCount()} available)."
            );
        }

        return $this->map($this->row_sets[$index], $class);
    }

    /**
     * Get all row sets as a nested array.
     *
     * @template U of object
     * @param class-string<U>|null $class The class to map the rows to, instead of the result set's one
     * @return ($class is null ? list<list<T>> : list<list<U>>)
     * @throws MappingException
     */
    public function getAllRowSets(?string $class = null): array
    {
        return array_map(fn(array $rows): array => $this->map($rows, $class), $this->row_sets);
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
        return count($this->row_sets[0] ?? []);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * @param array<mixed> $rows
     * @param class-string|null $class
     * @return list<mixed>
     * @throws MappingException
     */
    private function map(array $rows, ?string $class): array
    {
        $class ??= $this->class;

        if ($class === null) {
            return array_values($rows);
        }

        return array_map(fn(array|object $row): object => Hydrator::hydrate($row, $class), array_values($rows));
    }
}
