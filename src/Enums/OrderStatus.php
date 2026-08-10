<?php

namespace Liberu\Ecommerce\Orders\Enums;

/**
 * **A state machine, not a free enum.** The transition table lives here and
 * nowhere else, and `Actions\TransitionOrder` refuses anything it does not
 * name — an illegal move throws rather than silently writing.
 *
 *     pending ──▶ confirmed ──▶ completed
 *        │            │
 *        └────────────┴──────▶ cancelled
 *
 * Four states, and the reason there are only four is that the other six the
 * host's `Order` carried belong to other modules. `paid` and `failed` describe a
 * *tender*, which is Checkout's; `supplier_queued` and `supplier_failed`
 * describe a *supplier*, which is Dropshipping's (#853); `refunded` and
 * `partially_refunded` describe *money going back*, which follows a return and
 * is Returns' (#906). Copying them here would make this module the second place
 * each of those facts is written, and a fact written in two places eventually
 * disagrees with itself.
 *
 * **What this module's four actually mean:**
 *
 * - `pending` — the order exists and is owed for. The only state a placement
 *   creates. Nothing downstream should act on it: allocating stock against an
 *   order whose money has not been accounted for is how a fraud loses inventory
 *   as well as goods.
 * - `confirmed` — accepted, and open for work. This is the signal Fulfillment
 *   waits on. *How* the money was accounted for is not asked here; the host
 *   settles its tenders and calls this.
 * - `completed` — everything owed has been delivered or called off. Terminal.
 * - `cancelled` — called off in full before anything shipped. Terminal.
 *
 * **Progress is not a status.** A half-shipped order is `confirmed` with
 * counters on its lines, not a `partially_shipped` state — an order of five
 * lines can be shipping, cancelled and outstanding at the same moment, and one
 * column cannot say that. Partial *cancellation* is likewise a line fact: the
 * order stays `confirmed` and the cancelled line carries its own count.
 *
 * **`completed → cancelled` is the illegal move that matters most.** Undoing a
 * delivered order is a return, not a cancellation, and it belongs to Returns.
 * Leaving that transition open would put a physical-goods workflow behind a
 * status change, which is exactly the seam the next two modules are being built
 * against. `docs/domain.md` draws the line; this enum is where it is enforced.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether a downstream module may act on this order's lines.
     *
     * Only `confirmed`. A `pending` order is not yet owed-for, and a terminal
     * one is finished — allocating stock against either is work nobody asked
     * for.
     */
    public function isOpenForWork(): bool
    {
        return $this === self::Confirmed;
    }

    /** The column stamped when an order arrives here, or null for `pending`. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Pending => null,
            self::Confirmed => 'confirmed_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
        };
    }
}
