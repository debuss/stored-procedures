<?php

/**
 * Classes used by the demo scripts to show the typed mapping.
 */

/**
 * A row of the `countries` table.
 *
 * Columns are mapped to the constructor parameters by name; the values
 * fetched by PDO are coerced to the declared types.
 */
final readonly class Country
{

    public function __construct(
        public int $id,
        public string $iso,
        public string $name
    ) {}
}

/**
 * Backed enums can be passed as parameters: they are bound as their value.
 */
enum Iso: string
{

    case France = 'FR';
    case Germany = 'DE';
}
