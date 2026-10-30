<?php

namespace StoredProcedures;

use Closure;
use PDO;
use PDOStatement;
use stdClass;
use StoredProcedures\Driver\{Call, DriverInterface, MySql, PgSql, SqlSrv};
use StoredProcedures\Exception\{MultipleResultsException, NoResultsException, StoredProcedureException, UnsupportedDriverException};

class SqlConnection
{

    protected DriverInterface $driver;

    /**
     * @throws StoredProcedureException When PDO is not in the PDO::ERRMODE_EXCEPTION error mode
     * @throws UnsupportedDriverException
     */
    public function __construct(
        protected PDO $pdo
    ) {
        // Errors are reported with exceptions: in the other modes, a failing call would go unnoticed
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new StoredProcedureException('The PDO instance must use the PDO::ERRMODE_EXCEPTION error mode.');
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->driver = match ($driver) {
            'mysql' => new MySql($this->pdo),
            'pgsql' => new PgSql($this->pdo),
            'sqlsrv', 'dblib' => new SqlSrv($this->pdo),
            default => throw new UnsupportedDriverException("Unsupported database driver: $driver"),
        };
    }

    /**
     * Get the PDO instance.
     *
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function procedure(string $stored_procedure): Procedure
    {
        return new Procedure($this, $stored_procedure);
    }

    /**
     * Execute a stored procedure (INSERT/UPDATE/DELETE) with the provided parameters.
     *
     * Same as {@see query()}: the name tells the intent, read the affected rows
     * and output values from the result set.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return ResultSet
     * @throws StoredProcedureException
     */
    public function execute(string $stored_procedure, array $parameters = []): ResultSet
    {
        return $this->query($stored_procedure, $parameters);
    }

    /**
     * Execute a stored procedure with the provided parameters and return the result set,
     * with its row sets, output values and affected rows.
     *
     * @template T of object
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param class-string<T>|null $class The class to map the rows to (see {@see Hydrator}), or null to keep them as fetched
     * @return ($class is null ? ResultSet<array<string, mixed>|stdClass> : ResultSet<T>)
     * @throws StoredProcedureException
     */
    public function query(string $stored_procedure, array $parameters = [], ?string $class = null): ResultSet
    {
        // The affected rows must be read before the row sets are fetched
        [[$affected_rows, $row_sets], $call] = $this->run(
            $stored_procedure,
            $parameters,
            fn(PDOStatement $statement): array => [$statement->rowCount(), $this->driver->fetchRowSets($statement)]
        );

        return new ResultSet($row_sets, $call->getOutputValues($row_sets), $affected_rows, $class);
    }

    /**
     * Get the first row of a result set.
     * If no rows are found, a NoResultsException is thrown.
     *
     * @template T of object
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass : T)
     * @throws NoResultsException When no rows are found
     * @throws StoredProcedureException
     */
    public function queryFirst(string $stored_procedure, array $parameters = [], ?string $class = null): array|object
    {
        [$row] = $this->run($stored_procedure, $parameters, fn(PDOStatement $statement): mixed => $statement->fetch());

        if ($row === false) {
            throw new NoResultsException("No results found for '$stored_procedure'.");
        }

        return $class === null ? $row : Hydrator::hydrate($row, $class);
    }

    /**
     * Get the first row of a result set, or the default value if none is found.
     *
     * @template T of object
     * @template D of array<string, mixed>|object|null = null
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param D $default
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass|D : T|D)
     * @throws StoredProcedureException
     */
    public function queryFirstOrDefault(string $stored_procedure, array $parameters = [], null|array|object $default = null, ?string $class = null): null|array|object
    {
        try {
            return $this->queryFirst($stored_procedure, $parameters, $class);
        } catch (NoResultsException) {
            return $default;
        }
    }

    /**
     * Get the only row of a result set.
     * If no rows, or more than one row is found, an exception is thrown.
     *
     * @template T of object
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass : T)
     * @throws NoResultsException When no rows are found
     * @throws MultipleResultsException When more than one row is found
     * @throws StoredProcedureException
     */
    public function querySingle(string $stored_procedure, array $parameters = [], ?string $class = null): array|object
    {
        // pdo_sqlsrv throws when fetching past the end of the result set
        [[$first, $second]] = $this->run(
            $stored_procedure,
            $parameters,
            fn(PDOStatement $statement): array => ($row = $statement->fetch()) === false ? [false, false] : [$row, $statement->fetch()]
        );

        if ($first === false) {
            throw new NoResultsException("No results found for '$stored_procedure'.");
        }

        if ($second !== false) {
            throw new MultipleResultsException("Expected a single result for '$stored_procedure', but multiple rows were returned.");
        }

        return $class === null ? $first : Hydrator::hydrate($first, $class);
    }

    /**
     * Get the only row of a result set, or the default value if none or more than 1 is found.
     *
     * @template T of object
     * @template D = null
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param D $default
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass|D : T|D)
     * @throws StoredProcedureException
     */
    public function querySingleOrDefault(string $stored_procedure, array $parameters = [], mixed $default = null, ?string $class = null): mixed
    {
        try {
            return $this->querySingle($stored_procedure, $parameters, $class);
        } catch (NoResultsException|MultipleResultsException) {
            return $default;
        }
    }

    /**
     * Get the first column of the first row, or null if no rows are found.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return mixed
     * @throws StoredProcedureException
     */
    public function queryScalar(string $stored_procedure, array $parameters = []): mixed
    {
        // fetchColumn() cannot tell a false value from no rows
        [$row] = $this->run($stored_procedure, $parameters, fn(PDOStatement $statement): mixed => $statement->fetch(PDO::FETCH_NUM));

        return $row === false ? null : $row[0];
    }

    /**
     * Prepare and execute a stored procedure, fetch its result, and close the cursor.
     *
     * @template T
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param Closure(PDOStatement): T $fetch Fetches the result from the executed statement
     * @return array{T, Call} The fetched result, and the call to read the output values from
     * @throws StoredProcedureException
     */
    private function run(string $stored_procedure, array $parameters, Closure $fetch): array
    {
        $call = $this->driver->prepare($stored_procedure, $this->normalizeParameters($parameters));

        try {
            $call->statement->execute();
            $result = $fetch($call->statement);
        } finally {
            $call->statement->closeCursor();
        }

        return [$result, $call];
    }

    /**
     * Convert parameters to SqlParameter instances if they are not already.
     *
     * Accepts either an array of SqlParameter objects or a named associative array
     * of key => value pairs (e.g. ['p_letter' => 'P']).
     *
     * @param array<array-key, mixed> $parameters
     * @return SqlParameter[] A numerically indexed array of SqlParameter
     * @throws StoredProcedureException When a parameter key is not a valid name
     */
    private function normalizeParameters(array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $key => $value) {
            if ($value instanceof SqlParameter) {
                $normalized[] = $value;
                continue;
            }

            if (!is_string($key)) {
                throw new StoredProcedureException(
                    "Parameter keys must be named strings (e.g. ['p_name' => 'value']), got index '$key'."
                );
            }

            $normalized[] = SqlParameter::from($key, $value);
        }

        return $normalized;
    }
}
