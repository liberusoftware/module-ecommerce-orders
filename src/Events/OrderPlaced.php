<?php

namespace Liberu\Ecommerce\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Orders\Data\OrderData;

/**
 * An order now exists.
 *
 * **Dispatched exactly once per order**, not once per call: a redelivered
 * placement replays the existing order and dispatches nothing. That guarantees
 * one *dispatch*, not one *delivery* — a queued listener can still be
 * redelivered by the queue, and `$order->placementKey` is there so a listener has
 * a natural key of its own to make that cheap.
 *
 * The payload is a plain value, not an Eloquent model, so a listener in a
 * package that does not depend on this one is not holding one of its classes.
 * The line ids on it are the identifiers Fulfillment and Returns store.
 */
final class OrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly OrderData $order) {}
}
