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
     */
    public function input(string $name, mixed $value, DbType $type = DbType::STRING): self
    {
        $this->parameters[$name] = new SqlParameter($name, $value, $type, ParameterMode::IN);

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
     */
    public function inout(string $name, mixed $value, DbType $type = DbType::STRING, int $size = 4000): self
    {
        $this->parameters[$name] = new SqlParameter($name, $value, $type, ParameterMode::INOUT, $size);

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
     * @throws StoredProcedureException
     */
    public function all(): ResultSet
    {
        return $this->connection->query($this->stored_procedure, $this->parameters);
    }

    /**
     * Execute the procedure and return the first row.
     *
     * @throws StoredProcedureException
     */
    public function first(): array|stdClass
    {
        return $this->connection->queryFirst($this->stored_procedure, $this->parameters);
    }

    /**
     * Execute the procedure and return the first row, or the default if none found.
     *
     * @throws StoredProcedureException
     */
    public function firstOrDefault(null|array|stdClass $default = null): null|array|stdClass
    {
        return $this->connection->queryFirstOrDefault($this->stored_procedure, $this->parameters, $default);
    }

    /**
     * Execute the procedure and return the only row.
     *
     * @throws StoredProcedureException
     */
    public function single(): array|stdClass
    {
        return $this->connection->querySingle($this->stored_procedure, $this->parameters);
    }

    /**
     * Execute the procedure and return the only row, or the default if none or multiple found.
     *
     * @throws StoredProcedureException
     */
    public function singleOrDefault(null|array|stdClass $default = null): mixed
    {
        return $this->connection->querySingleOrDefault($this->stored_procedure, $this->parameters, $default);
    }
}
