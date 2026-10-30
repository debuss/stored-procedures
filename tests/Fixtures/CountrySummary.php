<?php

namespace Tests\Fixtures;

final readonly class CountrySummary
{

    public function __construct(
        public int $total,
        public array $isos
    ) {}
}
