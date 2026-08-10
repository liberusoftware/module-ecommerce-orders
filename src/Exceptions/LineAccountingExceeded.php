<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use Liberu\Ecommerce\Orders\Enums\LineAccount;
use RuntimeException;

/**
 * An accounting move that would make a line claim more than it holds.
 *
 * Two invariants, and both are refused here rather than clamped, because a
 * downstream module asking to ship six of five has a bug and quietly shipping
 * five hides it:
 *
 *   fulfilled + cancelled ≤ quantity
 *   returned ≤ fulfilled
 *
 * The second is the Orders/Returns boundary stated as arithmetic. You cannot
 * return what was never shipped; calling that off is a cancellation.
 */
final class LineAccountingExceeded extends RuntimeException
{
    public static function overQuantity(int $lineId, LineAccount $account, int $wanted, int $remaining): self
    {
        return new self("Line {$lineId} has {$remaining} outstanding, so {$wanted} cannot be recorded as `{$account->value}`.");
    }

    public static function overFulfilled(int $lineId, int $wanted, int $returnable): self
    {
        return new self("Line {$lineId} has {$returnable} delivered and not yet returned, so {$wanted} cannot be recorded as returned. Nothing can come back that never went out — that is a cancellation.");
    }

    public static function notPositive(int $quantity): self
    {
        return new self("A quantity to account for must be positive, got {$quantity}. Accounting is append-only: there is no negative move that un-ships something.");
    }
}
