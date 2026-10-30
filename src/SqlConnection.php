<?php

namespace StoredProcedures;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;
use StoredProcedures\Driver\{Call, DriverInterface, MySql, PgSql, SqlSrv};
use StoredProcedures\Exception\{ExecutionException, MultipleResultsException, NoResultsException, ProcedureErrorException, StoredProcedureException, UnsupportedDriverException};

class SqlConnection implements LoggerAwareInterface
{

    protected DriverInterface $driver;

    /**
     * The maximum duration of the calls in seconds, or null for none.
     */
    protected ?int $timeout = null;

    /**
     * @param PDO $pdo
     * @param LoggerInterface|null $logger Logs each call (debug) and each failure (warning for the errors raised by
     *                                     the procedure, error otherwise), with the parameter names but not their values
     * @param bool $match_parameters_by_name MySQL and SQL Server: match the parameters to the ones declared by the
     *                                       procedure by name, instead of by position (one metadata query per procedure).
     *                                       PostgreSQL always matches them by name.
     * @throws StoredProcedureException When PDO is not in the PDO::ERRMODE_EXCEPTION error mode
     * @throws UnsupportedDriverException
     */
    public function __construct(
        protected PDO $pdo,
        protected ?LoggerInterface $logger = null,
        bool $match_parameters_by_name = false
    ) {
        // Errors are reported with exceptions: in the other modes, a failing call would go unnoticed
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new StoredProcedureException('The PDO instance must use the PDO::ERRMODE_EXCEPTION error mode.');
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->driver = match ($driver) {
            'mysql' => new MySql($this->pdo, $match_parameters_by_name),
            'pgsql' => new PgSql($this->pdo, $match_parameters_by_name),
            'sqlsrv', 'dblib' => new SqlSrv($this->pdo, $match_parameters_by_name),
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

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Get a copy of the connection limiting the duration of the calls, like CommandTimeout in .NET.
     *
     * A call taking longer throws a TimeoutException. Supported by SQL Server (pdo_sqlsrv) and
     * PostgreSQL; MySQL cannot limit the execution time of a stored procedure.
     *
     * @param int|null $seconds The maximum duration of a call, or null for none
     * @throws StoredProcedureException When the timeout is not a positive number of seconds
     */
    public function withTimeout(?int $seconds): static
    {
        if ($seconds !== null && $seconds <= 0) {
            throw new StoredProcedureException("The timeout must be a positive number of seconds, got $seconds.");
        }

        $connection = clone $this;
        $connection->timeout = $seconds;

        return $connection;
    }

    /**
     * Get the maximum duration of the calls in seconds, or null for none.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
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
        return $this->invoke($stored_procedure, $parameters, function (Call $call) use ($class): ResultSet {
            // The affected rows must be read before the row sets are fetched
            [$affected_rows, $row_sets] = $this->fetch(
                $call,
                fn(PDOStatement $statement): array => [$statement->rowCount(), $this->driver->fetchRowSets($statement)]
            );

            return new ResultSet($row_sets, $call->getOutputValues($row_sets), $affected_rows, $class);
        });
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
        $row = $this->run($stored_procedure, $parameters, fn(PDOStatement $statement): mixed => $statement->fetch());

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
        [$first, $second] = $this->run(
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
        $row = $this->run($stored_procedure, $parameters, fn(PDOStatement $statement): mixed => $statement->fetch(PDO::FETCH_NUM));

        return $row === false ? null : $row[0];
    }

    /**
     * Prepare and execute a stored procedure, fetch its result, and close the cursor.
     *
     * @template T
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param Closure(PDOStatement): T $fetch Fetches the result from the executed statement
     * @return T
     * @throws StoredProcedureException
     */
    private function run(string $stored_procedure, array $parameters, Closure $fetch): mixed
    {
        return $this->invoke($stored_procedure, $parameters, fn(Call $call): mixed => $this->fetch($call, $fetch));
    }

    /**
     * Prepare the call to a stored procedure and run the operation on it.
     *
     * The call is logged, and the errors reported by the database are converted to ExecutionException.
     *
     * @template T
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param Closure(Call): T $operation
     * @return T
     * @throws StoredProcedureException
     */
    private function invoke(string $stored_procedure, array $parameters, Closure $operation): mixed
    {
        $parameters = $this->normalizeParameters($parameters);
        $context = [
            'procedure' => $stored_procedure,
            'parameters' => array_map(fn(SqlParameter $parameter): string => $parameter->name, $parameters),
        ] + ($this->timeout === null ? [] : ['timeout' => $this->timeout]);
        $start = hrtime(true);

        try {
            $result = $operation($this->driver->prepare($stored_procedure, $parameters, $this->timeout));
        } catch (PDOException $e) {
            throw $this->logFailure($this->driver->convertException($stored_procedure, $e), $context, $start);
        } catch (StoredProcedureException $e) {
            throw $this->logFailure($e, $context, $start);
        }

        $this->logger?->debug(
            'Stored procedure {procedure} executed in {duration_ms} ms',
            $context + ['duration_ms' => $this->elapsed($start)]
        );

        return $result;
    }

    /**
     * Execute the prepared call, fetch its result, and close the cursor.
     *
     * @template T
     * @param Call $call
     * @param Closure(PDOStatement): T $fetch Fetches the result from the executed statement
     * @return T
     */
    private function fetch(Call $call, Closure $fetch): mixed
    {
        try {
            $call->execute();

            return $fetch($call->statement);
        } finally {
            $call->statement->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(StoredProcedureException $exception, array $context, int|float $start): StoredProcedureException
    {
        $context += ['message' => $exception->getMessage(), 'duration_ms' => $this->elapsed($start)];

        if ($exception instanceof ExecutionException) {
            $context['sql_state'] = $exception->getSqlState();
        }

        $this->logger?->log(
            $exception instanceof ProcedureErrorException ? LogLevel::WARNING : LogLevel::ERROR,
            'Stored procedure {procedure} failed: {message}',
            $context + ['exception' => $exception]
        );

        return $exception;
    }

    private function elapsed(int|float $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 2);
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
