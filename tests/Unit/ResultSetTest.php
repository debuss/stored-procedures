<?php

use StoredProcedures\ResultSet;

covers(ResultSet::class);

describe('empty ResultSet', function () {
    it('returns empty rows for no row sets', function () {
        $rs = new ResultSet([]);

        expect($rs->rows())->toBe([])
            ->and($rs->rowSetCount())->toBe(0)
            ->and($rs->getAllRowSets())->toBe([])
            ->and($rs->count())->toBe(0)
            ->and($rs->isEmpty())->toBeTrue();
    });

    it('defaults affected rows to 0 and output values to empty', function () {
        $rs = new ResultSet([]);

        expect($rs->getAffectedRows())->toBe(0)
            ->and($rs->getOutputValues())->toBe([]);
    });
});

describe('single row set', function () {
    beforeEach(function () {
        $this->rs = new ResultSet([
            [['id' => 1, 'name' => 'France'], ['id' => 2, 'name' => 'Germany']],
        ]);
    });

    it('returns the first row set via rows()', function () {
        expect($this->rs->rows())->toHaveCount(2)
            ->and($this->rs->rows()[0]['name'])->toBe('France');
    });

    it('reports rowSetCount as 1', function () {
        expect($this->rs->rowSetCount())->toBe(1);
    });

    it('accesses rowSet(0)', function () {
        expect($this->rs->rowSet(0))->toHaveCount(2);
    });

    it('counts rows in the first row set', function () {
        expect(count($this->rs))->toBe(2)
            ->and($this->rs->isEmpty())->toBeFalse();
    });

    it('is iterable over the first row set', function () {
        $names = [];
        foreach ($this->rs as $row) {
            $names[] = $row['name'];
        }

        expect($names)->toBe(['France', 'Germany']);
    });
});

describe('multiple row sets', function () {
    beforeEach(function () {
        $this->rs = new ResultSet([
            [['id' => 1, 'name' => 'France']],
            [['id' => 10, 'product' => 'Widget']],
        ]);
    });

    it('reports rowSetCount as 2', function () {
        expect($this->rs->rowSetCount())->toBe(2);
    });

    it('accesses each row set by index', function () {
        expect($this->rs->rowSet(0)[0]['name'])->toBe('France')
            ->and($this->rs->rowSet(1)[0]['product'])->toBe('Widget');
    });

    it('rows() only returns the first row set', function () {
        expect($this->rs->rows())->toHaveCount(1)
            ->and($this->rs->rows()[0]['name'])->toBe('France');
    });

    it('getAllRowSets returns all', function () {
        expect($this->rs->getAllRowSets())->toHaveCount(2);
    });
});

describe('rowSet() bounds checking', function () {
    it('throws OutOfRangeException for negative index', function () {
        $rs = new ResultSet([[]]);

        expect(fn() => $rs->rowSet(-1))->toThrow(OutOfRangeException::class);
    });

    it('throws OutOfRangeException for index >= count', function () {
        $rs = new ResultSet([[]]);

        expect(fn() => $rs->rowSet(1))->toThrow(OutOfRangeException::class);
    });

    it('throws OutOfRangeException on empty result', function () {
        $rs = new ResultSet([]);

        expect(fn() => $rs->rowSet(0))->toThrow(OutOfRangeException::class);
    });
});

describe('output values', function () {
    it('returns all output values', function () {
        $rs = new ResultSet([], ['p_id' => 42, 'p_name' => 'France']);

        expect($rs->getOutputValues())->toBe(['p_id' => 42, 'p_name' => 'France']);
    });

    it('returns a single output value by name', function () {
        $rs = new ResultSet([], ['p_id' => 42]);

        expect($rs->getOutputValue('p_id'))->toBe(42);
    });

    it('returns a null output value', function () {
        $rs = new ResultSet([], ['p_id' => null]);

        expect($rs->getOutputValue('p_id'))->toBeNull();
    });

    it('throws OutOfRangeException for an unknown output parameter', function () {
        $rs = new ResultSet([], ['p_id' => 42]);

        expect(fn() => $rs->getOutputValue('p_missing'))
            ->toThrow(OutOfRangeException::class, "No output parameter named 'p_missing'.");
    });
});

describe('affected rows', function () {
    it('stores and returns affected rows', function () {
        $rs = new ResultSet([], [], 5);

        expect($rs->getAffectedRows())->toBe(5);
    });
});


describe('typed rows', function () {
    beforeEach(function () {
        $this->countries = [['id' => 1, 'iso' => 'FR', 'name' => 'France'], ['id' => 2, 'iso' => 'DE', 'name' => 'Germany']];
        $this->orders = [['id' => 10, 'name' => 'Order']];
    });

    it('maps the rows to the class given to the constructor', function () {
        $rs = new ResultSet([$this->countries], [], 0, Tests\Fixtures\Country::class);

        expect($rs->rows())->toEqual([new Tests\Fixtures\Country(1, 'FR', 'France'), new Tests\Fixtures\Country(2, 'DE', 'Germany')]);
    });

    it('maps the rows when iterating', function () {
        $rs = new ResultSet([$this->countries], [], 0, Tests\Fixtures\Country::class);

        expect(iterator_to_array($rs))->each->toBeInstanceOf(Tests\Fixtures\Country::class);
    });

    it('overrides the class per access', function () {
        $rs = new ResultSet([$this->countries, $this->orders]);

        expect($rs->rows(Tests\Fixtures\Country::class)[0])->toBeInstanceOf(Tests\Fixtures\Country::class)
            ->and($rs->rowSet(1, Tests\Fixtures\MutableCountry::class)[0]->id)->toBe(10)
            ->and($rs->getAllRowSets(Tests\Fixtures\MutableCountry::class)[1][0]->name)->toBe('Order');
    });

    it('keeps the rows as fetched without class', function () {
        $rs = new ResultSet([$this->countries]);

        expect($rs->rows())->toBe($this->countries)
            ->and($rs->getAllRowSets())->toBe([$this->countries]);
    });

    it('counts the rows without mapping them', function () {
        $rs = new ResultSet([[['not' => 'a country']]], [], 0, Tests\Fixtures\Country::class);

        expect($rs)->toHaveCount(1)
            ->and($rs->isEmpty())->toBeFalse();
    });
});
