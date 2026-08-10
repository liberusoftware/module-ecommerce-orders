<?php

namespace Liberu\Ecommerce\Orders\Queries;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Orders\Data\OrderLineData;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * The reads a surface actually performs, in one place.
 *
 * `byNumber` is the only lookup a customer-facing surface should use. There is
 * **no `byId`** here on purpose: an incrementing id in a URL is an enumeration of
 * everybody's orders, and the number exists so that never has to be weighed up at
 * a call site.
 *
 * `line()` is the read **Fulfillment and Returns perform**, and it returns the
 * published read model rather than the model. They hold an `order_line_id`; this
 * is how they turn one back into quantities without importing an Eloquent class
 * or joining two of this module's tables themselves.
 */
final class OrderQuery
{
    /** Everything an order page renders, in one query plus one eager load. */
    public function byNumber(string $number): ?Order
    {
        return Order::query()->with('lines')->where('number', $number)->first();
    }

    /**
     * The order a placement produced, or null.
     *
     * What a caller uses to answer "did my retry land" without attempting the
     * placement again.
     */
    public function byPlacement(string $placementKey, string $source = 'checkout'): ?Order
    {
        return Order::query()
            ->with('lines')
            ->where('source', $source)
            ->where('placement_key', $placementKey)
            ->first();
    }

    /**
     * A line by its stable public id, as the published contract.
     *
     * Null when nothing has that id — never a guess, and never a partially
     * populated value. A downstream module holding a stale id is entitled to a
     * clear answer.
     */
    public function line(int $orderLineId): ?OrderLineData
    {
        $line = OrderLine::query()->with('order')->find($orderLineId);

        if ($line === null) {
            return null;
        }

        return OrderLineData::from($line, $line->order->currency, $line->order->currency_exponent);
    }

    /**
     * Every line of an order a downstream module may act on, as read models.
     *
     * @return list<OrderLineData>
     */
    public function lines(Order $order): array
    {
        return $order->lines
            ->map(fn (OrderLine $line): OrderLineData => OrderLineData::from($line, $order->currency, $order->currency_exponent))
            ->values()
            ->all();
    }

    /**
     * Confirmed orders, which are the only ones a downstream module should act
     * on.
     *
     * @return Builder<Order>
     */
    public function openForWork(?int $teamId = null, ?int $storeId = null): Builder
    {
        return Order::query()
            ->openForWork()
            // Bound values, never `where(…, null)` — that compiles to `is null`
            // and would return every orphan order rather than none.
            ->when($teamId !== null, fn (Builder $query): Builder => $query->where('team_id', $teamId))
            ->when($storeId !== null, fn (Builder $query): Builder => $query->where('store_id', $storeId));
    }

    /**
     * Orders still owed for, older than a moment — the payment-chase sweep.
     *
     * Nothing here runs on a timer. The host's schedule decides what to do with
     * these; see the runbook.
     *
     * @return Builder<Order>
     */
    public function pendingSince(DateTimeInterface $since): Builder
    {
        return Order::query()->pendingSince($since)->orderBy('placed_at');
    }

    /**
     * One customer's orders, newest first.
     *
     * @return Builder<Order>
     */
    public function forCustomer(int $customerId): Builder
    {
        return Order::query()->where('customer_id', $customerId)->orderByDesc('placed_at');
    }
}
