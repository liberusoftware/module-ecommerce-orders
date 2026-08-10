<?php

namespace Liberu\Ecommerce\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Orders\Data\OrderData;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Events\OrderTransitioned;
use Liberu\Ecommerce\Orders\Exceptions\IllegalOrderTransition;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderStatusChange;

/**
 * The only way an order's status changes.
 *
 * `status` is not in `Order::$fillable` and there is no setter, so this is not a
 * convenience — it is the single door, and the transition table on `OrderStatus`
 * is consulted before anything is written.
 *
 * **An illegal move throws and writes nothing.** Not the status, not a history
 * row, not an event. An attempt that was refused is not a transition that
 * happened, and a history containing refusals answers a different question from
 * the one it is kept for.
 *
 * The status and its timestamp move in one transaction with the history row, so
 * an order can never be `confirmed` with no record of when it was confirmed.
 *
 * `$reason` is a **short slug** and not free text. The domain event logger copies
 * it, and a text box next to an event logger is where a customer's email address
 * gets typed into a log line. Sixty-four characters, enforced by the column.
 */
final class TransitionOrder
{
    public function handle(Order $order, OrderStatus $to, ?int $actorId = null, ?string $reason = null): Order
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw IllegalOrderTransition::from($from, $to);
        }

        DB::transaction(function () use ($order, $from, $to, $actorId, $reason): void {
            $attributes = ['status' => $to];

            $stamp = $to->timestampColumn();

            if ($stamp !== null) {
                $attributes[$stamp] = now();
            }

            // `forceFill` because `status` is deliberately not fillable: the
            // guard above is the whole control, and a fillable status is a way
            // round it from any caller holding a request array.
            $order->forceFill($attributes)->save();

            OrderStatusChange::query()->create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);
        });

        OrderTransitioned::dispatch(OrderData::from($order->load('lines')), $from, $to, $reason);

        return $order;
    }
}
