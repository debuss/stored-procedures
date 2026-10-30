<?php

namespace Tests\Fixtures;

use DateTimeImmutable;

final readonly class Moment
{

    public function __construct(
        public DateTimeImmutable $value
    ) {}
}
