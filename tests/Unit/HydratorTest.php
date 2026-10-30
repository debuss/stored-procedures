<?php

use StoredProcedures\Exception\MappingException;
use StoredProcedures\Hydrator;
use Tests\Fixtures\Country;
use Tests\Fixtures\Event;
use Tests\Fixtures\Iso;
use Tests\Fixtures\MutableCountry;
use Tests\Fixtures\Status;

covers(Hydrator::class);

// Type handlers are global: remove the ones registered by the tests
afterEach(function () {
    Hydrator::removeTypeHandler(Tests\Fixtures\Code::class);
    Hydrator::removeTypeHandler('bool');
});

describe('constructor mapping', function () {
    it('maps an array row to the constructor parameters, coercing scalar values', function () {
        $country = Hydrator::hydrate(['id' => '1', 'iso' => 'FR', 'name' => 'France'], Country::class);

        expect($country)->toEqual(new Country(1, 'FR', 'France'));
    });

    it('maps an object row', function () {
        $country = Hydrator::hydrate((object) ['id' => 1, 'iso' => 'FR', 'name' => 'France'], Country::class);

        expect($country)->toEqual(new Country(1, 'FR', 'France'));
    });

    it('ignores extra columns', function () {
        $country = Hydrator::hydrate(['id' => 1, 'iso' => 'FR', 'name' => 'France', 'population' => 68], Country::class);

        expect($country)->toEqual(new Country(1, 'FR', 'France'));
    });

    it('matches column names ignoring case and underscores', function () {
        $event = Hydrator::hydrate(['EVENT_ID' => 7, 'status' => 1, 'starts_at' => '2026-10-30 12:00:00'], Event::class);

        expect($event->eventId)->toBe(7);
    });

    it('converts backed enums and dates', function () {
        $event = Hydrator::hydrate([
            'event_id' => 7,
            'status' => '1',
            'starts_at' => '2026-10-30 12:00:00',
            'ends_at' => '2026-10-31 08:30:00',
        ], Event::class);

        expect($event->status)->toBe(Status::Published)
            ->and($event->startsAt)->toEqual(new DateTimeImmutable('2026-10-30 12:00:00'))
            ->and($event->endsAt)->toBeInstanceOf(DateTimeImmutable::class)
            ->and($event->endsAt->format('Y-m-d H:i:s'))->toBe('2026-10-31 08:30:00');
    });

    it('keeps the default value of optional parameters without column', function () {
        $event = Hydrator::hydrate(['event_id' => 7, 'status' => 0, 'starts_at' => '2026-10-30'], Event::class);

        expect($event->endsAt)->toBeNull()
            ->and($event->label)->toBe('none');
    });

    it('passes null values through', function () {
        $event = Hydrator::hydrate(['event_id' => 7, 'status' => 0, 'starts_at' => '2026-10-30', 'ends_at' => null], Event::class);

        expect($event->endsAt)->toBeNull();
    });

    it('passes values unchanged to untyped and union-typed parameters', function () {
        $loose = Hydrator::hydrate(['value' => '1', 'code' => '42', 'status' => '1'], Tests\Fixtures\Loose::class);

        expect($loose->value)->toBe('1')
            ->and($loose->code)->toBe('42')
            ->and($loose->status)->toBe('1');
    });

    it('converts string values of string-backed enums', function () {
        $country = Hydrator::hydrate(['id' => 1, 'iso' => 'DE'], MutableCountry::class);

        expect($country->iso)->toBe(Iso::Germany);
    });

    it('throws when a required parameter has no column', function () {
        expect(fn() => Hydrator::hydrate(['id' => 1, 'iso' => 'FR'], Country::class))
            ->toThrow(MappingException::class, 'Cannot map the row to Tests\Fixtures\Country: no column for the parameter $name.');
    });

    it('throws when a value cannot be coerced', function () {
        expect(fn() => Hydrator::hydrate(['id' => 'abc', 'iso' => 'FR', 'name' => 'France'], Country::class))
            ->toThrow(MappingException::class, 'Cannot map the row to Tests\Fixtures\Country');
    });

    it('throws when a value is not a valid enum case', function () {
        expect(fn() => Hydrator::hydrate(['event_id' => 7, 'status' => 9, 'starts_at' => '2026-10-30'], Event::class))
            ->toThrow(MappingException::class, 'Cannot map the row to Tests\Fixtures\Event');
    });

    it('throws when a value is not a valid date', function () {
        expect(fn() => Hydrator::hydrate(['event_id' => 7, 'status' => 0, 'starts_at' => 'not a date'], Event::class))
            ->toThrow(MappingException::class, 'Cannot map the row to Tests\Fixtures\Event');
    });
});

describe('property mapping', function () {
    it('assigns the public properties of a class without constructor parameters', function () {
        $country = Hydrator::hydrate(['id' => '1', 'name' => 'France', 'iso' => 'FR'], MutableCountry::class);

        expect($country->id)->toBe(1)
            ->and($country->name)->toBe('France')
            ->and($country->iso)->toBe(Iso::France);
    });

    it('ignores static and non-public properties', function () {
        $country = Hydrator::hydrate(['id' => 1, 'instances' => 5, 'secret' => 'changed'], MutableCountry::class);

        expect(MutableCountry::$instances)->toBe(0)
            ->and($country->getSecret())->toBe('unchanged');
    });

    it('keeps the default value of properties without column', function () {
        $country = Hydrator::hydrate(['id' => 1], MutableCountry::class);

        expect($country->name)->toBe('');
    });
});

describe('special cases', function () {
    it('maps to stdClass', function () {
        $row = Hydrator::hydrate(['id' => 1, 'name' => 'France'], stdClass::class);

        expect($row)->toEqual((object) ['id' => 1, 'name' => 'France']);
    });

    it('returns rows already of the class unchanged', function () {
        $country = new Country(1, 'FR', 'France');

        expect(Hydrator::hydrate($country, Country::class))->toBe($country);
    });

    it('throws for an unknown class', function () {
        expect(fn() => Hydrator::hydrate(['id' => 1], 'Tests\Fixtures\Unknown'))
            ->toThrow(MappingException::class, 'the class does not exist');
    });

    it('throws for a class that cannot be instantiated', function () {
        expect(fn() => Hydrator::hydrate(['id' => 1], DateTimeInterface::class))
            ->toThrow(MappingException::class, 'the class is not instantiable');
    });
});

describe('Column attribute', function () {
    it('maps a constructor parameter to the column given by the attribute', function () {
        Hydrator::addTypeHandler(Tests\Fixtures\Code::class, fn(string $value) => new Tests\Fixtures\Code($value));

        $product = Hydrator::hydrate(['PRODUCT_CODE' => 'ABC-1', 'label' => 'Keyboard'], Tests\Fixtures\Product::class);

        expect((string) $product->code)->toBe('ABC-1')
            ->and($product->name)->toBe('Keyboard');
    });

    it('no longer maps the member own name', function () {
        expect(fn() => Hydrator::hydrate(['code' => 'ABC-1', 'name' => 'Keyboard'], Tests\Fixtures\Product::class))
            ->toThrow(MappingException::class, 'no column for the parameter $code');
    });

    it('maps a property to the column given by the attribute', function () {
        $country = Hydrator::hydrate(['cntry_iso' => 'FR', 'cntry_name' => 'France', 'name' => 'ignored'], Tests\Fixtures\LegacyCountry::class);

        expect($country->iso)->toBe('FR')
            ->and($country->name)->toBe('France');
    });
});

describe('JSON columns', function () {
    beforeEach(function () {
        Hydrator::addTypeHandler(Tests\Fixtures\Code::class, fn(string $value) => new Tests\Fixtures\Code($value));
        $this->row = ['product_code' => 'ABC-1', 'label' => 'Keyboard'];
    });

    it('decodes the JSON string of an array column', function () {
        $product = Hydrator::hydrate($this->row + ['tags' => '["usb","azerty"]', 'attributes' => '{"color":"black","keys":105}'], Tests\Fixtures\Product::class);

        expect($product->tags)->toBe(['usb', 'azerty'])
            ->and($product->attributes)->toBe(['color' => 'black', 'keys' => 105]);
    });

    it('keeps null and already decoded values', function () {
        $product = Hydrator::hydrate($this->row + ['tags' => ['usb'], 'attributes' => null], Tests\Fixtures\Product::class);

        expect($product->tags)->toBe(['usb'])
            ->and($product->attributes)->toBeNull();
    });

    it('throws for invalid JSON', function () {
        expect(fn() => Hydrator::hydrate($this->row + ['tags' => '[usb'], Tests\Fixtures\Product::class))
            ->toThrow(MappingException::class, 'Cannot map the row to Tests\Fixtures\Product: Syntax error');
    });
});

describe('type handlers', function () {
    it('converts the values of the registered type, whatever the case and leading backslash', function () {
        Hydrator::addTypeHandler('\TESTS\FIXTURES\CODE', fn(string $value) => new Tests\Fixtures\Code(strtoupper($value)));

        $product = Hydrator::hydrate(['product_code' => 'abc-1', 'label' => 'Keyboard'], Tests\Fixtures\Product::class);

        expect($product->code)->toEqual(new Tests\Fixtures\Code('ABC-1'));
    });

    it('replaces the built-in conversion of the type', function () {
        Hydrator::addTypeHandler(Tests\Fixtures\Code::class, fn(string $value) => new Tests\Fixtures\Code($value));
        Hydrator::addTypeHandler('bool', fn(string $value): bool => $value === 't');

        $product = Hydrator::hydrate(['product_code' => 'ABC-1', 'label' => 'Keyboard', 'active' => 'f'], Tests\Fixtures\Product::class);

        // Without the handler, the non-empty string 'f' would be coerced to true
        expect($product->active)->toBeFalse();
    });

    it('throws a MappingException when the handler fails', function () {
        Hydrator::addTypeHandler(Tests\Fixtures\Code::class, fn(string $value) => new Tests\Fixtures\Code($value));

        expect(fn() => Hydrator::hydrate(['product_code' => 'nope', 'label' => 'Keyboard'], Tests\Fixtures\Product::class))
            ->toThrow(MappingException::class, "Cannot map the row to Tests\Fixtures\Product: the type handler of Tests\Fixtures\Code failed: Invalid code 'nope'.");
    });

    it('stops converting the type once the handler is removed', function () {
        Hydrator::addTypeHandler(Tests\Fixtures\Code::class, fn(string $value) => new Tests\Fixtures\Code($value));
        Hydrator::removeTypeHandler(Tests\Fixtures\Code::class);

        expect(fn() => Hydrator::hydrate(['product_code' => 'ABC-1', 'label' => 'Keyboard'], Tests\Fixtures\Product::class))
            ->toThrow(MappingException::class, 'must be of type Tests\Fixtures\Code, string given');
    });
});
