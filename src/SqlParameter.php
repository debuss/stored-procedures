<?php

namespace StoredProcedures;

use BackedEnum;
use DateTimeInterface;
use StoredProcedures\Exception\StoredProcedureException;
use Stringable;
use UnitEnum;

readonly class SqlParameter
{

    public function __construct(
        public string $name,
        public mixed $value,
        public DbType $type,
        public ParameterMode $mode = ParameterMode::IN,
        public int $size = 4000
    ) {}

    /**
     * Create an IN parameter, inferring its type from the value.
     */
    public static function from(string $name, mixed $value): SqlParameter
    {
        return new self($name, $value, DbType::fromValue($value));
    }

    /**
     * Get the value to bind to the statement.
     *
     * Backed enums are bound as their value, dates as a 'Y-m-d H:i:s' string
     * ('Y-m-d' for DbType::DATE) in their own timezone, and Stringable objects
     * (e.g. UUID, money) as their string.
     *
     * @throws StoredProcedureException When the value is an enum without backing value
     */
    public function toPdoValue(): mixed
    {
        return match (true) {
            $this->value instanceof BackedEnum => $this->value->value,
            $this->value instanceof UnitEnum => throw new StoredProcedureException(
                "Parameter '{$this->name}': the enum " . $this->value::class . " must be backed to be bound."
            ),
            $this->value instanceof DateTimeInterface => $this->value->format($this->type === DbType::DATE ? 'Y-m-d' : 'Y-m-d H:i:s'),
            $this->value instanceof Stringable => (string) $this->value,
            default => $this->value,
        };
    }
}
