<?php

use Psr\Log\AbstractLogger;
use StoredProcedures\Attribute\Column;

/**
 * Classes used by the demo scripts to show the typed mapping and the logging.
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

/**
 * A value object: converted from the column values with a type handler,
 * and bound as its string when passed as a parameter (Stringable).
 */
final readonly class IsoCode implements Stringable
{

    public function __construct(
        public string $value
    ) {
        if (!preg_match('/^[A-Z]{2}$/', $value)) {
            throw new InvalidArgumentException("Invalid ISO code '$value'.");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * The #[Column] attribute maps a parameter to a column with another name.
 */
final readonly class CountryView
{

    public function __construct(
        #[Column('iso')]
        public IsoCode $code,
        #[Column('name')]
        public string $label
    ) {}
}

/**
 * The `isos` column holds a JSON array: it is decoded for the `array` type.
 */
final readonly class CountrySummary
{

    public function __construct(
        public int $total,
        public array $isos
    ) {}
}

/**
 * A PSR-3 logger printing the records, with their {placeholders} replaced by the context values.
 */
final class EchoLogger extends AbstractLogger
{

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        echo "  [$level] " . strtr((string) $message, $replacements) . PHP_EOL;
    }
}
