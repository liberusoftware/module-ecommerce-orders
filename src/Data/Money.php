<?php

namespace Liberu\Ecommerce\Orders\Data;

use JsonSerializable;
use Liberu\Ecommerce\Orders\Support\MinorUnits;

/**
 * One money value, in the shape every Liberu commerce API serialises.
 *
 *     {"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}
 *
 * All four, and `decimal` is a **string**. The minor units are the truth; the
 * exponent is what lets a consumer render them without a currency table; and the
 * decimal is there so nobody on the far side of the wire has to divide, because
 * dividing is where the float comes back. A JSON *number* `19.99` is a float the
 * moment it is parsed, which is the entire problem this shape exists to avoid.
 *
 * Restated rather than imported, for the reason given on `Support\MinorUnits`.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(
        public int $minor,
        public string $currency,
        public int $exponent = 2,
    ) {}

    public function decimal(): string
    {
        return MinorUnits::toDecimalString($this->minor, $this->exponent);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'decimal' => $this->decimal(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
