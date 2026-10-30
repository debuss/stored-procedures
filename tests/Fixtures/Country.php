<?php

namespace Tests\Fixtures;

final readonly class Country
{

    public function __construct(
        public int $id,
        public string $iso,
        public string $name
    ) {}
}
