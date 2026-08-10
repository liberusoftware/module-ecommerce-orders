<?php

namespace Liberu\Ecommerce\Orders\Data;

/**
 * What `PlaceOrder` hands back: the order, and whether this call is the one that
 * created it.
 *
 * The flag exists because the caller genuinely needs it and cannot derive it. An
 * API adapter answers `201` the first time and `200` on a redelivery; a queue
 * worker decides whether to log a placement or a duplicate delivery. Reading the
 * order afterwards cannot tell them apart — it looks identical either way, which
 * is exactly what idempotency is for.
 */
final readonly class Placement
{
    public function __construct(
        public OrderData $order,
        public bool $created,
    ) {}
}
