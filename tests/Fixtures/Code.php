<?php

namespace Tests\Fixtures;

use InvalidArgumentException;
use Stringable;

/**
 * A value object, converted with a type handler.
 */
final readonly class Code implements Stringable
{

    public function __construct(
        public string $value
    ) {
        if (!preg_match('/^[A-Z]{3}-\d+$/', $value)) {
            throw new InvalidArgumentException("Invalid code '$value'.");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
