<?php

namespace Liberu\Ecommerce\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Orders\Data\OrderLineData;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Events\OrderLineAccounted;
use Liberu\Ecommerce\Orders\Exceptions\LineAccountingExceeded;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * Record that some of a line has stopped being outstanding.
 *
 * **The published write path for Fulfillment and Returns.** They depend on this
 * package and hold `order_line_id`; this is what they call with it. One action
 * for all three counters rather than three, because all three share one guard,
 * and a guard implemented three times is a guard implemented differently three
 * times.
 *
 * Two invariants, refused rather than clamped:
 *
 *     fulfilled + cancelled ≤ quantity
 *     returned ≤ fulfilled
 *
 * The second is the Orders/Returns boundary written as arithmetic: nothing can
 * come back that never went out. A downstream module asking to return six of
 * five delivered has a bug, and quietly recording five would hide it in the one
 * place — a returns count — where a wrong number becomes a refund.
 *
 * **Append-only.** There is no negative move that un-ships something. A parcel
 * that never left is a cancellation of the outstanding quantity, and a parcel
 * that came back is a return; neither is a decrement, and allowing one would make
 * the counters a running total nobody can audit.
 *
 * The increment is done with `DB::raw` inside a transaction on a locked row, so
 * two carriers confirming two parcels of the same line at the same moment cannot
 * both read `fulfilled_quantity = 0` and both write `1`.
 */
final class AccountForLine
{
    public function handle(OrderLine $line, LineAccount $account, int $quantity = 1): OrderLine
    {
        if ($quantity <= 0) {
            throw LineAccountingExceeded::notPositive($quantity);
        }

        DB::transaction(function () use ($line, $account, $quantity): void {
            // Re-read under a row lock. The guard has to be checked against what
            // is in the database rather than what this caller loaded, or two
            // concurrent callers each pass a check the other invalidated.
            /** @var OrderLine $fresh */
            $fresh = OrderLine::query()->lockForUpdate()->findOrFail($line->getKey());

            if ($account === LineAccount::Returned) {
                $returnable = $fresh->returnableQuantity();

                if ($quantity > $returnable) {
                    throw LineAccountingExceeded::overFulfilled($fresh->id, $quantity, $returnable);
                }
            } else {
                $outstanding = $fresh->outstandingQuantity();

                if ($quantity > $outstanding) {
                    throw LineAccountingExceeded::overQuantity($fresh->id, $account, $quantity, $outstanding);
                }
            }

            $column = $account->column();

            $fresh->forceFill([$column => $fresh->{$column} + $quantity])->save();

            $line->forceFill([$column => $fresh->{$column}]);
        });

        $order = $line->order;

        OrderLineAccounted::dispatch(
            OrderLineData::from($line, $order->currency, $order->currency_exponent),
            $account,
            $quantity,
        );

        return $line;
    }
}
