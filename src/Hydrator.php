<?php

// No strict_types on purpose: the scalar values fetched by PDO (often strings) are
// coerced by PHP to the declared types (e.g. "42" to int) when passed to the class.

namespace StoredProcedures;

use BackedEnum;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use stdClass;
use StoredProcedures\Exception\MappingException;
use TypeError;
use ValueError;

/**
 * Map database rows to objects of a given class.
 *
 * Columns are matched to the constructor parameters (or, for a class without constructor
 * parameters, to its public properties) by name, ignoring case and underscores: the column
 * `country_id` fills `$countryId`. Extra columns are ignored; a missing column leaves an
 * optional parameter to its default value.
 *
 * On top of PHP's scalar coercion, string values are converted to backed enums (`from()`)
 * and to DateTimeInterface implementations.
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

        if ($class === stdClass::class) {
            return (object) $values;
        }

        $columns = [];
        foreach ($values as $name => $value) {
            $columns[self::normalize((string) $name)] = $value;
        }

        ['constructor' => $constructor, 'members' => $members] = self::metadata($class);

        try {
            return $constructor
                ? self::construct($class, $members, $columns)
                : self::assign($class, $members, $columns);
        } catch (TypeError|ValueError|DateMalformedStringException $e) {
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
                $arguments[$parameter->getName()] = self::convert($columns[$key], $parameter->getType());
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
     * @param array<string, mixed> $columns
     * @return T
     */
    private static function assign(string $class, array $properties, array $columns): object
    {
        $object = new $class();

        foreach ($properties as $key => $property) {
            if (array_key_exists($key, $columns)) {
                $object->{$property->getName()} = self::convert($columns[$key], $property->getType());
            }
        }

        return $object;
    }

    /**
     * Convert a column value to a backed enum or a date, when the type requires it.
     */
    private static function convert(mixed $value, ?ReflectionType $type): mixed
    {
        if ($value === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $name = $type->getName();

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
            $keyed[self::normalize($member->getName())] = $member;
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
}
