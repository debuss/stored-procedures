<?php

namespace StoredProcedures;

use PDO;
use PDOStatement;
use stdClass;
use StoredProcedures\Driver\{DriverInterface, MySql, PgSql, SqlSrv};
use StoredProcedures\Exception\{MultipleResultsException, NoResultsException, StoredProcedureException, UnsupportedDriverException};

class SqlConnection
{

    protected DriverInterface $driver;

    /**
     * @throws UnsupportedDriverException
     */
    public function __construct(
        protected PDO $pdo
    ) {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->driver = match ($driver) {
            'mysql' => new MySql(),
            'pgsql' => new PgSql($this->pdo),
            'sqlsrv', 'dblib' => new SqlSrv(),
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
     * Execute a stored procedure with the provided parameters.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return ResultSet
     * @throws StoredProcedureException
     */
    public function execute(string $stored_procedure, array $parameters = []): ResultSet
    {
        $parameters = $this->normalizeParameters($parameters);
        $statement = $this->getStatement($stored_procedure, $parameters);

        try {
            $statement->execute();
            $affectedRows = $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }

        return new ResultSet(
            [],
            $this->driver->getOutputValues($parameters, $this->pdo),
            $affectedRows
        );
    }

    /**
     * Execute a stored procedure with the provided parameters and return the result set.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return ResultSet
     * @throws StoredProcedureException
     */
    public function query(string $stored_procedure, array $parameters = []): ResultSet
    {
        $parameters = $this->normalizeParameters($parameters);
        $statement = $this->getStatement($stored_procedure, $parameters);

        try {
            $statement->execute();
            $row_sets = $this->driver->fetchRowSets($statement);
        } finally {
            $statement->closeCursor();
        }

        return new ResultSet(
            $row_sets,
            $this->driver->getOutputValues($parameters, $this->pdo)
        );
    }

    /**
     * Get the first row of a result set.
     * If no rows are found, a NoResultsException is thrown.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return array|stdClass
     * @throws NoResultsException When no rows are found
     * @throws StoredProcedureException
     */
    public function queryFirst(string $stored_procedure, array $parameters = []): array|stdClass
    {
        $parameters = $this->normalizeParameters($parameters);
        $statement = $this->getStatement($stored_procedure, $parameters);

        try {
            $statement->execute();
            $result = $statement->fetch();
        } finally {
            $statement->closeCursor();
        }

        if ($result === false) {
            throw new NoResultsException("No results found for '$stored_procedure'.");
        }

        return $result;
    }

    /**
     * Get the first row of a result set, or the default value if none is found.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param array|stdClass|null $default
     * @return array|stdClass|null
     * @throws StoredProcedureException
     */
    public function queryFirstOrDefault(string $stored_procedure, array $parameters = [], null|array|stdClass $default = null): null|array|stdClass
    {
        try {
            return $this->queryFirst($stored_procedure, $parameters);
        } catch (NoResultsException) {
            return $default;
        }
    }

    /**
     * Get the only row of a result set.
     * If no rows, or more than one row is found, an exception is thrown.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return array|stdClass
     * @throws NoResultsException When no rows are found
     * @throws MultipleResultsException When more than one row is found
     * @throws StoredProcedureException
     */
    public function querySingle(string $stored_procedure, array $parameters = []): array|stdClass
    {
        $parameters = $this->normalizeParameters($parameters);
        $statement = $this->getStatement($stored_procedure, $parameters);

        try {
            $statement->execute();
            $first = $statement->fetch();
            $second = $first === false ? false : $statement->fetch();
        } finally {
            $statement->closeCursor();
        }

        if ($first === false) {
            throw new NoResultsException("No results found for '$stored_procedure'.");
        }

        if ($second !== false) {
            throw new MultipleResultsException("Expected a single result for '$stored_procedure', but multiple rows were returned.");
        }

        return $first;
    }

    /**
     * Get the only row of a result set, or the default value if none or more than 1 is found.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @param array|stdClass $default
     * @return mixed
     * @throws StoredProcedureException
     */
    public function querySingleOrDefault(string $stored_procedure, array $parameters = [], mixed $default = null): mixed
    {
        try {
            return $this->querySingle($stored_procedure, $parameters);
        } catch (NoResultsException|MultipleResultsException) {
            return $default;
        }
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
        array_walk($parameters, function (&$value, $key) {
            if ($value instanceof SqlParameter) {
                return;
            }

            if (!is_string($key)) {
                throw new StoredProcedureException(
                    "Parameter keys must be named strings (e.g. ['p_name' => 'value']), got index '$key'."
                );
            }

            $value = SqlParameter::from($key, $value);
        });

        return array_values($parameters);
    }

    /**
     * Create a PDOStatement for the given stored procedure and parameters, caching it for future use.
     *
     * @param string $stored_procedure
     * @param array<string, mixed>|SqlParameter[] $parameters
     * @return PDOStatement
     * @throws StoredProcedureException
     */
    private function getStatement(string $stored_procedure, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare(
            $this->driver->getQuerySyntax($stored_procedure, $parameters)
        );

        $this->driver->bindParameters(
            $statement,
            $parameters,
            $this->pdo
        );

        return $statement;
    }
}
