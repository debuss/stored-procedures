<?php

// No strict_types on purpose: the scalar values fetched by PDO (often strings) are
// coerced by PHP to the declared types (e.g. "42" to int) when passed to the class.

namespace StoredProcedures;

use BackedEnum;
use Closure;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use stdClass;
use StoredProcedures\Attribute\Column;
use StoredProcedures\Exception\MappingException;
use Throwable;
use TypeError;
use ValueError;

/**
 * Map database rows to objects of a given class.
 *
 * Columns are matched to the constructor parameters (or, for a class without constructor
 * parameters, to its public properties) by name, ignoring case and underscores: the column
 * `country_id` fills `$countryId`. Another column name can be given with the {@see Column}
 * attribute. Extra columns are ignored; a missing column leaves an optional parameter to its
 * default value.
 *
 * On top of PHP's scalar coercion, string values are converted to backed enums (`from()`),
 * to DateTimeInterface implementations, and decoded from JSON for the `array` type. The
 * conversion to a given type can be customized with {@see addTypeHandler()}.
 */
final class Hydrator
{

    /**
     * Parameters or properties to fill, per class, keyed by normalized name.
     *
     * @var array<class-string, array{constructor: bool, members: array<string, ReflectionParameter|ReflectionProperty>}>
     */
    private static array $metadata = [];

    /**
     * Converters of the column values, keyed by lower-cased type name.
     *
     * @var array<string, Closure(mixed): mixed>
     */
    private static array $type_handlers = [];

    /**
     * Register a converter of the column values to a type, for every class (like Dapper's SqlMapper.AddTypeHandler()).
     *
     * The handler receives the column value, never null, and replaces the built-in conversions for this type.
     *
     * @param string $type The type as declared by the parameters or properties: a class, interface or enum name
     *                     (e.g. Uuid::class), or a built-in type (e.g. 'bool')
     * @param Closure(mixed): mixed $handler
     */
    public static function addTypeHandler(string $type, Closure $handler): void
    {
        self::$type_handlers[self::typeKey($type)] = $handler;
    }

    /**
     * Remove the converter registered for a type.
     */
    public static function removeTypeHandler(string $type): void
    {
        unset(self::$type_handlers[self::typeKey($type)]);
    }

    /**
     * @template T of object
     * @param array<array-key, mixed>|object $row
     * @param class-string<T> $class
     * @return T
     * @throws MappingException When the class cannot be instantiated from the row
     */
    public static function hydrate(array|object $row, string $class): object
    {
        if ($row instanceof $class) {
            return $row;
        }

        $values = is_object($row) ? get_object_vars($row) : $row;

        $columns = [];
        foreach ($values as $name => $value) {
            $columns[self::normalize((string) $name)] = $value;
        }

        ['constructor' => $constructor, 'members' => $members] = self::metadata($class);

        try {
            return $constructor
                ? self::construct($class, $members, $columns)
                : self::assign($class, $members, $columns, $values);
        } catch (TypeError|ValueError|DateMalformedStringException|JsonException $e) {
            throw new MappingException("Cannot map the row to $class: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, ReflectionParameter|ReflectionProperty> $parameters
     * @param array<string, mixed> $columns
     * @return T
     * @throws MappingException
     */
    private static function construct(string $class, array $parameters, array $columns): object
    {
        $arguments = [];

        foreach ($parameters as $key => $parameter) {
            /** @var ReflectionParameter $parameter */
            if (array_key_exists($key, $columns)) {
                $arguments[$parameter->getName()] = self::convert($columns[$key], $parameter->getType(), $class);
            } elseif (!$parameter->isOptional()) {
                throw new MappingException(
                    "Cannot map the row to $class: no column for the parameter \${$parameter->getName()}."
                );
            }
        }

        return new $class(...$arguments);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, ReflectionParameter|ReflectionProperty> $properties
     * @param array<string, mixed> $columns The values, keyed by normalized name
     * @param array<array-key, mixed> $values The values, keyed by column name
     * @return T
     */
    private static function assign(string $class, array $properties, array $columns, array $values): object
    {
        $object = new $class();

        // stdClass has no declared properties: every column becomes a property
        if ($object instanceof stdClass) {
            foreach ($values as $name => $value) {
                $object->{$name} = $value;
            }

            return $object;
        }

        foreach ($properties as $key => $property) {
            if (array_key_exists($key, $columns)) {
                $object->{$property->getName()} = self::convert($columns[$key], $property->getType(), $class);
            }
        }

        return $object;
    }

    /**
     * Convert a column value with the type handler of its type, or to a backed enum, a date
     * or an array decoded from JSON, when the type requires it.
     *
     * @param class-string $class The class being mapped
     * @throws MappingException When the type handler fails
     * @throws JsonException When an array column is not valid JSON
     */
    private static function convert(mixed $value, ?ReflectionType $type, string $class): mixed
    {
        if ($value === null || !$type instanceof ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();
        $handler = self::$type_handlers[self::typeKey($name)] ?? null;

        if ($handler !== null) {
            try {
                return $handler($value);
            } catch (Throwable $e) {
                throw new MappingException(
                    "Cannot map the row to $class: the type handler of $name failed: {$e->getMessage()}",
                    previous: $e
                );
            }
        }

        if ($name === 'array' && is_string($value)) {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        if ($type->isBuiltin()) {
            return $value;
        }

        if (is_subclass_of($name, BackedEnum::class) && (is_int($value) || is_string($value))) {
            return $name::from($value);
        }

        if (is_string($value) && is_a($name, DateTimeInterface::class, true)) {
            return $name === DateTimeInterface::class
                ? new DateTimeImmutable($value)
                : new $name($value);
        }

        return $value;
    }

    /**
     * @param class-string $class
     * @return array{constructor: bool, members: array<string, ReflectionParameter|ReflectionProperty>}
     * @throws MappingException
     */
    private static function metadata(string $class): array
    {
        if (isset(self::$metadata[$class])) {
            return self::$metadata[$class];
        }

        if (!class_exists($class) && !interface_exists($class)) {
            throw new MappingException("Cannot map the row to $class: the class does not exist.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new MappingException("Cannot map the row to $class: the class is not instantiable.");
        }

        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $constructor = !empty($parameters);

        $members = $constructor
            ? array_filter($parameters, fn(ReflectionParameter $parameter): bool => !$parameter->isVariadic())
            : array_filter(
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
                fn(ReflectionProperty $property): bool => !$property->isStatic() && !$property->isReadOnly()
            );

        $keyed = [];
        foreach ($members as $member) {
            $column = ($member->getAttributes(Column::class)[0] ?? null)?->newInstance();
            $keyed[self::normalize($column->name ?? $member->getName())] = $member;
        }

        return self::$metadata[$class] = ['constructor' => $constructor, 'members' => $keyed];
    }

    /**
     * Normalize a column or member name, so that `country_id`, `CountryId` and `countryId` match.
     */
    private static function normalize(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }

    /**
     * Class names are case-insensitive, and may be given with a leading backslash.
     */
    private static function typeKey(string $type): string
    {
        return strtolower(ltrim($type, '\\'));
    }
}
