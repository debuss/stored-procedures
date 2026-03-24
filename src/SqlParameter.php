<?php

namespace StoredProcedures;

readonly class SqlParameter
{

    public function __construct(
        public string $name,
        public mixed $value,
        public DbType $type,
        public ParameterMode $mode = ParameterMode::IN,
        public int $size = 4000
    ) {}

    public static function from(string $name, mixed $value): SqlParameter
    {
        if ($value === null) {
            return new self($name, null, DbType::NULL);
        }

        $type = match (gettype($value)) {
            'boolean' => DbType::BOOLEAN,
            'integer' => DbType::INTEGER,
            'double'  => DbType::FLOAT,
            default   => DbType::STRING,
        };

        return new self($name, $value, $type);
    }
}
