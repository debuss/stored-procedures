<?php

namespace StoredProcedures;

use PDO;

enum ParameterMode
{

    case IN;
    case OUT;
    case INOUT;

    public function toPdoType(): int
    {
        return match ($this) {
            ParameterMode::IN => 0,
            ParameterMode::OUT, ParameterMode::INOUT => PDO::PARAM_INPUT_OUTPUT
        };
    }
}
