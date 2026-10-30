<?php

namespace Tests\Fixtures;

use StoredProcedures\Attribute\Column;

final readonly class Product
{

    public function __construct(
        #[Column('product_code')]
        public Code $code,
        #[Column('LABEL')]
        public string $name,
        public array $tags = [],
        public ?array $attributes = null,
        public bool $active = false
    ) {}
}
