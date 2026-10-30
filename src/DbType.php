<?php

namespace StoredProcedures;

use BackedEnum;
use DateTimeInterface;
use PDO;

enum DbType
{

    case STRING;
    case INTEGER;
    case BOOLEAN;
    case FLOAT;
    case BINARY;
    case DATE;
    case DATETIME;
    case NULL;

    /**
     * Infer the type of a value: backed enums by their value, dates as DATETIME,
     * and anything else not boolean, integer, float or null as STRING.
     */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => self::NULL,
            is_bool($value) => self::BOOLEAN,
            is_int($value) => self::INTEGER,
            is_float($value) => self::FLOAT,
            $value instanceof DateTimeInterface => self::DATETIME,
            default => self::STRING,
        };
    }

    public function toPdoType(): int
    {
        return match ($this) {
            self::STRING, self::FLOAT, self::DATE, self::DATETIME => PDO::PARAM_STR,
            self::INTEGER => PDO::PARAM_INT,
            self::BOOLEAN => PDO::PARAM_BOOL,
            self::BINARY => PDO::PARAM_LOB,
            self::NULL => PDO::PARAM_NULL,
        };
    }
}
