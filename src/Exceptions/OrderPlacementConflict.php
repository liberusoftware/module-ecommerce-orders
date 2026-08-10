<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use RuntimeException;

/**
 * **Permanent.** One placement key, two genuinely different orders.
 *
 * A redelivery sends the same key *and* the same facts, and gets the order that
 * already exists. A key reused across two different placements is a caller bug,
 * and the only safe answers are "conflict" or "commit twice". Replaying the
 * first order would be a third answer and the worst of them: a success returned
 * for an order that was never created.
 *
 * **Retrying this will not help**, and that is why it is its own class.
 * Checkout published a single exception for both this and the transient case,
 * and its API has to rebuild a message string from the domain's own factory to
 * tell them apart — recorded as a seam in `MIGRATION_PLAN.md` under wave 4's
 * "open by design". Two conditions, two classes, so a surface answers `409`
 * here and `423` next door with `instanceof` rather than a substring.
 */
final class OrderPlacementConflict extends RuntimeException
{
    public static function from(string $source, string $key): self
    {
        return new self("The placement key `{$key}` from source `{$source}` has already created an order from different facts. Retrying will not help: use a new key, or send the facts the first call sent.");
    }
}
