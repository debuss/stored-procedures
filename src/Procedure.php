<?php

namespace StoredProcedures;

use stdClass;
use StoredProcedures\Exception\StoredProcedureException;

class Procedure
{

    /** @var SqlParameter[] */
    protected array $parameters = [];

    public function __construct(
        protected SqlConnection $connection,
        protected string $stored_procedure
    ) {}

    /**
     * Add an IN parameter.
     *
     * @param DbType|null $type The type, or null to infer it from the value
     */
    public function input(string $name, mixed $value, ?DbType $type = null): self
    {
        $this->parameters[$name] = new SqlParameter($name, $value, $type ?? DbType::fromValue($value), ParameterMode::IN);

        return $this;
    }

    /**
     * Add an OUT parameter.
     */
    public function output(string $name, DbType $type = DbType::STRING, int $size = 4000): self
    {
        $this->parameters[$name] = new SqlParameter($name, null, $type, ParameterMode::OUT, $size);

        return $this;
    }

    /**
     * Add an INOUT parameter.
     *
     * @param DbType|null $type The type, or null to infer it from the value
     */
    public function inout(string $name, mixed $value, ?DbType $type = null, int $size = 4000): self
    {
        $this->parameters[$name] = new SqlParameter($name, $value, $type ?? DbType::fromValue($value), ParameterMode::INOUT, $size);

        return $this;
    }

    /**
     * Execute the procedure (INSERT/UPDATE/DELETE) and return the result set
     * containing affected rows and any output parameter values.
     *
     * @throws StoredProcedureException
     */
    public function execute(): ResultSet
    {
        return $this->connection->execute($this->stored_procedure, $this->parameters);
    }

    /**
     * Execute the procedure and return all result rows.
     *
     * @template T of object
     * @param class-string<T>|null $class The class to map the rows to (see {@see Hydrator}), or null to keep them as fetched
     * @return ($class is null ? ResultSet<array<string, mixed>|stdClass> : ResultSet<T>)
     * @throws StoredProcedureException
     */
    public function all(?string $class = null): ResultSet
    {
        return $this->connection->query($this->stored_procedure, $this->parameters, $class);
    }

    /**
     * Execute the procedure and return the first row.
     *
     * @template T of object
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass : T)
     * @throws StoredProcedureException
     */
    public function first(?string $class = null): array|object
    {
        return $this->connection->queryFirst($this->stored_procedure, $this->parameters, $class);
    }

    /**
     * Execute the procedure and return the first row, or the default if none found.
     *
     * @template T of object
     * @template D of array<string, mixed>|object|null = null
     * @param D $default
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass|D : T|D)
     * @throws StoredProcedureException
     */
    public function firstOrDefault(null|array|object $default = null, ?string $class = null): null|array|object
    {
        return $this->connection->queryFirstOrDefault($this->stored_procedure, $this->parameters, $default, $class);
    }

    /**
     * Execute the procedure and return the only row.
     *
     * @template T of object
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass : T)
     * @throws StoredProcedureException
     */
    public function single(?string $class = null): array|object
    {
        return $this->connection->querySingle($this->stored_procedure, $this->parameters, $class);
    }

    /**
     * Execute the procedure and return the only row, or the default if none or multiple found.
     *
     * @template T of object
     * @template D = null
     * @param D $default
     * @param class-string<T>|null $class The class to map the row to (see {@see Hydrator}), or null to keep it as fetched
     * @return ($class is null ? array<string, mixed>|stdClass|D : T|D)
     * @throws StoredProcedureException
     */
    public function singleOrDefault(mixed $default = null, ?string $class = null): mixed
    {
        return $this->connection->querySingleOrDefault($this->stored_procedure, $this->parameters, $default, $class);
    }

    /**
     * Execute the procedure and return the first column of the first row, or null if none found.
     *
     * @throws StoredProcedureException
     */
    public function scalar(): mixed
    {
        return $this->connection->queryScalar($this->stored_procedure, $this->parameters);
    }
}
