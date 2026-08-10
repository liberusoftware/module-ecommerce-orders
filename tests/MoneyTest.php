<?php

declare(strict_types=1);

use Liberu\Ecommerce\Orders\Data\Money;
use Liberu\Ecommerce\Orders\Exceptions\InvalidMoney;
use Liberu\Ecommerce\Orders\Support\MinorUnits;

it('parses a decimal string without ever building a float', function () {
    // The whole reason `MinorUnits` exists, and the reason `docs/adoption.md`
    // points a host at it before converting `orders.total_amount`. The naive
    // multiplication is written out next to the right answer so the finding
    // survives the person who made it.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(MinorUnits::fromDecimalString('19.99'))->toBe(1999);
});

it('converts the amounts a host s decimal column actually holds', function (string $decimal, int $minor) {
    expect(MinorUnits::fromDecimalString($decimal))->toBe($minor);
})->with([
    ['0.00', 0],
    ['0.01', 1],
    ['0.1', 10],
    ['1', 100],
    ['19.99', 1999],
    ['29.97', 2997],
    ['1234.56', 123456],
    ['-19.99', -1999],
    ['  19.99  ', 1999],
]);

it('round-trips every amount back to the string it came from', function (int $minor, int $exponent, string $decimal) {
    expect(MinorUnits::toDecimalString($minor, $exponent))->toBe($decimal)
        ->and(MinorUnits::fromDecimalString($decimal, $exponent))->toBe($minor);
})->with([
    [1999, 2, '19.99'],
    [1, 2, '0.01'],
    [0, 2, '0.00'],
    [-1999, 2, '-19.99'],
    // A zero-exponent currency. Yen is not divided by a hundred, which is the
    // whole reason the exponent travels with the amount.
    [1999, 0, '1999'],
    // And a three-exponent one, so the padding is exercised in both directions.
    [1999, 3, '1.999'],
]);

it('refuses precision it cannot hold rather than rounding somebody s invoice', function () {
    MinorUnits::fromDecimalString('19.995');
})->throws(InvalidMoney::class, 'more precision');

it('refuses an amount that is not an amount', function (string $bad) {
    MinorUnits::fromDecimalString($bad);
})->with(['', 'nineteen', '19,99', '1.2.3', '£19.99', '1e3'])->throws(InvalidMoney::class);

it('refuses a negative exponent in both directions', function (string $method) {
    MinorUnits::$method('1', -1);
})->with(['fromDecimalString'])->throws(InvalidMoney::class);

it('refuses a negative exponent when rendering too', function () {
    MinorUnits::toDecimalString(1, -1);
})->throws(InvalidMoney::class);

it('serialises money in the shape every Liberu commerce API uses', function () {
    // Four keys, and `decimal` is a string. A JSON number `19.99` is a float the
    // moment it is parsed, which is the entire problem this shape avoids.
    expect((new Money(1999, 'GBP'))->toArray())->toBe([
        'minor' => 1999,
        'currency' => 'GBP',
        'exponent' => 2,
        'decimal' => '19.99',
    ])->and(json_decode(json_encode(new Money(1999, 'GBP')), true)['decimal'])->toBeString();
});
