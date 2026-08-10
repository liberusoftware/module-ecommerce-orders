<?php

namespace Liberu\Ecommerce\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Orders\Data\OrderData;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;

/**
 * The order moved, legally.
 *
 * Both ends are carried, because a listener's question is almost always about
 * the *edge* and not the destination — "confirmed, from pending" is a sale, and
 * there is no other way into `confirmed`, but a listener that only sees the new
 * status has to keep its own copy of the transition table to know that.
 *
 * Never dispatched for a refused move. An illegal transition throws and nothing
 * is written, so there is no event for something that did not happen.
 */
final class OrderTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly OrderData $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly ?string $reason = null,
    ) {}
}
