<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use RuntimeException;

/**
 * **Transient. Retry.** The placement key is claimed and the winner's order is
 * not visible yet.
 *
 * Two callers arrived with the same key at once. One lost at the unique index,
 * re-read, and found nothing — because the winner's transaction has not
 * committed. That is a moment, not a state: the order is about to exist and the
 * honest answer is "ask again shortly".
 *
 * The pair to `OrderPlacementConflict`, and deliberately a **different class**.
 * They are opposite conditions — one is permanent and one clears by itself — and
 * a surface has to answer `409` for one and `423` for the other. Checkout ships
 * a single class for both and has to reconstruct a message to separate them;
 * not repeating that is a stated requirement of this module.
 *
 * Not a wait. Blocking here would hold a request open on a lock this module
 * cannot bound, and a queue worker that throws this gets its own retry policy
 * for free.
 */
final class OrderPlacementInFlight extends RuntimeException
{
    public static function from(string $source, string $key): self
    {
        return new self("The placement key `{$key}` from source `{$source}` is claimed by a call that has not committed. Retry shortly — this clears on its own.");
    }
}
