<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use RuntimeException;

/**
 * A status move the state machine does not allow, refused rather than written.
 *
 * The important instance is `completed → cancelled`. Undoing a delivered order
 * is a **return**, not a cancellation: there are goods in a van somewhere and a
 * refund to decide, and none of that is a status change. Orders draws the line
 * at delivery and Returns (#906) owns the far side of it — see `docs/domain.md`.
 *
 * Nothing is written when this throws. The order keeps the status it had and no
 * history row is recorded, because an attempt that was refused is not a
 * transition that happened.
 */
final class IllegalOrderTransition extends RuntimeException
{
    public static function from(OrderStatus $from, OrderStatus $to): self
    {
        if ($from === $to) {
            return new self("An order that is already `{$from->value}` cannot transition to `{$to->value}`. A no-op is not a transition, and recording one would put a history row against a move nobody made.");
        }

        if ($from->isTerminal()) {
            return new self("An order that is `{$from->value}` is finished and cannot become `{$to->value}`. If goods have to come back, that is a return, not a status change.");
        }

        $allowed = implode('`, `', array_map(fn (OrderStatus $status): string => $status->value, $from->allowedTransitions()));

        return new self("An order that is `{$from->value}` cannot become `{$to->value}`. It may only become `{$allowed}`.");
    }
}
