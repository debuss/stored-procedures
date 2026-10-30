<?php

namespace Tests\Fixtures;

final readonly class Loose
{

    public function __construct(
        public mixed $value,
        public int|string $code,
        public Status|string $status = 'none'
    ) {}
}
