<?php

namespace StoredProcedures;

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
