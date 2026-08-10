<?php

namespace Liberu\Ecommerce\Orders\Enums;

/**
 * The three ways a line's quantity stops being outstanding, and who writes each.
 *
 * One enum rather than three actions, because all three share one guard and a
 * guard implemented three times is a guard implemented differently three times.
 *
 * - `Fulfilled` — shipped or delivered. Written by **Fulfillment** (#859).
 * - `Cancelled` — called off before anything shipped. Written by **Orders**.
 * - `Returned`  — came back after delivery. Written by **Returns** (#906).
 *
 * The ownership split is the whole point and is documented in `docs/domain.md`:
 * cancellation before shipping is a decision about an order, and a physical
 * return after delivery is a workflow with goods in it. They are not two names
 * for the same thing, and `returned ≤ fulfilled` is where the difference is
 * enforced.
 */
enum LineAccount: string
{
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function column(): string
    {
        return $this->value.'_quantity';
    }
}
