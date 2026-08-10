<?php

namespace Liberu\Ecommerce\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Exceptions\OrderNotCancellable;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * Call the whole order off, before anything has shipped.
 *
 * **This is where Orders stops and Returns starts.** Cancelling is calling off
 * something that has not happened. Once a line has been fulfilled there are goods
 * in the world, and getting them back is a physical workflow with a refund
 * decision attached — that is Returns (#906), and it is not a status change.
 * `docs/domain.md` draws the line; this action and `OrderStatus` enforce it.
 *
 * So an order with **anything** fulfilled is refused, whole. A partly-shipped
 * order is not a special case to be half-handled here: cancel the outstanding
 * quantity on its remaining lines with `AccountForLine`, and the order stays
 * `confirmed` because part of it is still real.
 *
 * Every line's outstanding quantity is cancelled, so no line is left claiming to
 * be owed. Lines are **not deleted** — a downstream module may already be holding
 * their ids, and a cancelled line with `cancelled_quantity === quantity` says
 * strictly more than a missing row does.
 */
final class CancelOrder
{
    public function __construct(
        private readonly AccountForLine $accountForLine = new AccountForLine(),
        private readonly TransitionOrder $transitionOrder = new TransitionOrder(),
    ) {}

    public function handle(Order $order, ?int $actorId = null, ?string $reason = null): Order
    {
        $order->load('lines');

        if ($order->status->isTerminal()) {
            throw OrderNotCancellable::alreadyFinished($order->status);
        }

        // Checked across every line before anything is written. Cancelling four
        // lines and then discovering the fifth has shipped would leave an order
        // that is neither cancelled nor whole.
        foreach ($order->lines as $line) {
            if ($line->fulfilled_quantity > 0) {
                throw OrderNotCancellable::alreadyFulfilled($line->id, $line->fulfilled_quantity);
            }
        }

        DB::transaction(function () use ($order): void {
            $order->lines
                ->filter(fn (OrderLine $line): bool => $line->outstandingQuantity() > 0)
                ->each(fn (OrderLine $line) => $this->accountForLine->handle($line, LineAccount::Cancelled, $line->outstandingQuantity()));
        });

        return $this->transitionOrder->handle($order, OrderStatus::Cancelled, $actorId, $reason ?? 'cancelled');
    }
}
