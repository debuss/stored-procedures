<?php

namespace StoredProcedures\Attribute;

use Attribute;

/**
 * Map a constructor parameter or a property to a column with another name.
 *
 * The name is matched like the member names, ignoring case and underscores.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Column
{

    public function __construct(
        public string $name
    ) {}
}
