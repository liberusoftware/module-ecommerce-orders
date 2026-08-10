<?php

namespace Liberu\Ecommerce\Orders\Enums;

/**
 * What a line is charging for.
 *
 * Shipping and fees are lines rather than columns on the order so that discount
 * allocation, the tax rate and the rounding have exactly one implementation.
 * The moment shipping becomes `shipping_cost_minor` on the order, a second
 * answer to "is this taxable" exists, and the two drift.
 *
 * It is also what lets a two-parcel order carry two shipping lines, which a
 * column cannot.
 */
enum LineKind: string
{
    case Item = 'item';
    case Shipping = 'shipping';
    case Fee = 'fee';

    /**
     * Whether this line names something physical that can be shipped or
     * returned. Only `item` — a courier does not deliver a fee, and Returns has
     * nothing to take back.
     */
    public function isShippable(): bool
    {
        return $this === self::Item;
    }
}
