<?php

namespace Liberu\Ecommerce\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Orders\Data\OrderLineData;
use Liberu\Ecommerce\Orders\Enums\LineAccount;

/**
 * A quantity on a line stopped being outstanding.
 *
 * Carries the line **after** the move, so a listener reads `outstandingQuantity`
 * and `returnableQuantity` rather than recomputing them from a delta it would
 * have to apply itself. `$quantity` is the size of the move, for a listener that
 * genuinely wants the delta.
 *
 * This is the event a downstream module watches to know that its own work landed
 * — and the event Orders watches nothing for, because Fulfillment and Returns
 * call in rather than being read.
 */
final class OrderLineAccounted
{
    use Dispatchable;

    public function __construct(
        public readonly OrderLineData $line,
        public readonly LineAccount $account,
        public readonly int $quantity,
    ) {}
}
