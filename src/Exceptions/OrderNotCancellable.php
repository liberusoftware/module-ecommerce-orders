<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use RuntimeException;

/**
 * Cancellation refused, because something has already shipped or the order is
 * already finished.
 *
 * **This is where Orders stops and Returns starts.** Cancelling is calling off
 * something that has not happened; once goods are with a courier, calling it off
 * is a physical workflow with a refund decision attached, and that is Returns'
 * (#906). Orders will not model it as a status change, because a status change
 * is a thing that leaves no goods to collect.
 *
 * A partly-shipped order is not a special case here: cancel the outstanding
 * quantity on its lines, which is `AccountForLine` and leaves the order
 * `confirmed`. Only a whole-order cancellation is refused.
 */
final class OrderNotCancellable extends RuntimeException
{
    public static function alreadyFinished(OrderStatus $status): self
    {
        return new self("An order that is `{$status->value}` cannot be cancelled.");
    }

    public static function alreadyFulfilled(int $lineId, int $fulfilled): self
    {
        return new self("Line {$lineId} has {$fulfilled} already fulfilled, so this order cannot be cancelled. Goods that have shipped come back through Returns, not through a status change. Cancel the outstanding quantity on the remaining lines instead.");
    }
}
