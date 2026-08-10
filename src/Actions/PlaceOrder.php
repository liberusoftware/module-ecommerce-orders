<?php

namespace Liberu\Ecommerce\Orders\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Orders\Data\OrderData;
use Liberu\Ecommerce\Orders\Data\OrderLineInput;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;
use Liberu\Ecommerce\Orders\Data\Placement;
use Liberu\Ecommerce\Orders\Events\OrderPlaced;
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementConflict;
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementInFlight;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderStatusChange;

/**
 * Turn a placement into exactly one order.
 *
 * ### It does not subscribe to anything
 *
 * Checkout emits `CheckoutCompleted`. Orders does **not** listen for it —
 * listening means importing the event class, and this package imports nothing
 * from a sibling `liberusoftware/ecommerce-*` package. The host writes one
 * listener and calls this; `README.md` and `docs/adoption.md` both carry it
 * verbatim. The suite here runs with no checkout module installed at all.
 *
 * ### Idempotency: the unique index is the guarantee
 *
 * A redelivered queue job, a retried API call and a double-clicked button are one
 * event three times, and the difference between one order and three is
 * `unique(source, placement_key)` on `ecommerce_orders_orders`.
 *
 * **Not a `select` first.** A lookup followed by an insert has a window between
 * them, and under load something lands in it — this is the lesson Checkout paid
 * for and stated in as many words. So the insert is attempted, and the loser
 * catches the integrity violation. The `select` at the top is an optimisation for
 * the common case, not the correctness argument, and deleting it would change
 * nothing except throughput.
 *
 * ### The three outcomes, and two of them are exceptions with different lifetimes
 *
 * - **New key** → the order is written and `OrderPlaced` is dispatched once.
 * - **Same key, same facts** → a redelivery. The existing order comes back with
 *   `created: false`, nothing is written and nothing is dispatched.
 * - **Same key, different facts** → `OrderPlacementConflict`. **Permanent**: a
 *   caller reused a key across two different placements, and retrying cannot
 *   help.
 * - **Same key, winner not committed yet** → `OrderPlacementInFlight`.
 *   **Transient**: retry and it clears.
 *
 * Those last two are **two classes on purpose**. Checkout ships one class for
 * both, and its API has to rebuild a message string from the domain's own factory
 * to tell a `409` from a `423` — recorded in `MIGRATION_PLAN.md` as a seam.
 * A surface over this module answers with `instanceof`.
 *
 * ### The natural key
 *
 * `(source, placement_key)`, and for a checkout the placement key is the
 * checkout's own **idempotency key** rather than its session id. Three reasons,
 * in order of weight: it is the key the *client* chose and therefore the one it
 * will send again on a retry; Checkout's own README names it as the thing a
 * listener should key on; and it exists for a placement that never had a session
 * at all — a phone order, an import, a marketplace feed — where a session id is
 * null and a nullable unique index guarantees nothing.
 *
 * `checkout_session_id` is stored, indexed, and deliberately **not** unique: one
 * checkout splitting into several orders is a real thing, and a unique index
 * there would turn a future rule into a future migration.
 */
final class PlaceOrder
{
    public function handle(OrderPlacement $placement): Placement
    {
        $hash = $placement->hash();

        $existing = $this->find($placement);

        if ($existing !== null) {
            return $this->replay($existing, $hash, $placement);
        }

        try {
            $order = DB::transaction(fn (): Order => $this->write($placement, $hash));
        } catch (QueryException $exception) {
            // Lost the race at the unique index. The winner either has the order
            // already or is about to commit one; either way this caller must not
            // write a second.
            $winner = $this->find($placement);

            if ($winner === null) {
                // Two possibilities, and they are told apart by the message we
                // can honestly give. Either the winner's transaction has not
                // committed — transient, clears by itself — or this exception was
                // never about the placement key at all, in which case rethrowing
                // is the only truthful answer.
                if ($this->isUniqueViolation($exception)) {
                    throw OrderPlacementInFlight::from($placement->source, $placement->placementKey);
                }

                throw $exception;
            }

            return $this->replay($winner, $hash, $placement);
        }

        $data = OrderData::from($order);

        OrderPlaced::dispatch($data);

        return new Placement($data, true);
    }

    private function write(OrderPlacement $placement, string $hash): Order
    {
        $order = Order::query()->create([
            'number' => Order::generateNumber(),
            'team_id' => $placement->teamId,
            'store_id' => $placement->storeId,
            'customer_id' => $placement->customerId,
            'email' => $placement->email,
            'source' => $placement->source,
            'placement_key' => $placement->placementKey,
            'placement_hash' => $hash,
            'checkout_session_id' => $placement->checkoutSessionId,
            'currency' => $placement->currency,
            'currency_exponent' => $placement->currencyExponent,
            'subtotal_minor' => $placement->subtotalMinor,
            'discount_minor' => $placement->discountMinor,
            'net_minor' => $placement->netMinor,
            'tax_minor' => $placement->taxMinor,
            'grand_total_minor' => $placement->grandTotalMinor,
            'shipping_address' => $placement->shippingAddress,
            'billing_address' => $placement->billingAddress,
            'recipient_name' => $placement->recipientName,
            'recipient_email' => $placement->recipientEmail,
            'gift_message' => $placement->giftMessage,
            'billing_country' => $placement->billingCountry,
            'vat_number' => $placement->vatNumber,
            'reverse_charge' => $placement->reverseCharge,
            'coupon_code' => $placement->couponCode,
            'placed_at' => $placement->placedAt ?? now(),
        ]);

        // Positions are restated from the loop index rather than trusted from
        // the input. Two lines claiming position 3 would order arbitrarily, and
        // the order of lines on an invoice is a thing customers query.
        foreach (array_values($placement->lines) as $index => $line) {
            $this->writeLine($order, $line, $index);
        }

        // The one history row whose `from_status` is null: this order came from
        // nowhere. Written here rather than by `TransitionOrder` because a
        // placement is not a transition — there is no previous state to have
        // moved out of, and no legality to check.
        OrderStatusChange::query()->create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $order->status,
            'reason' => 'placed',
        ]);

        return $order->load('lines');
    }

    private function writeLine(Order $order, OrderLineInput $line, int $position): void
    {
        $order->lines()->create([
            'kind' => $line->kind,
            'product_id' => $line->productId,
            'variant_id' => $line->variantId,
            'sku' => $line->sku,
            'name' => $line->name,
            'quantity' => $line->quantity,
            'unit_price_minor' => $line->unitPriceMinor,
            'subtotal_minor' => $line->subtotalMinor,
            'discount_minor' => $line->discountMinor,
            'net_minor' => $line->netMinor,
            'tax_rate_bp' => $line->taxRateBp,
            'tax_minor' => $line->taxMinor,
            'gross_minor' => $line->grossMinor,
            'taxable' => $line->taxable,
            'metadata' => $line->metadata,
            'position' => $position,
        ]);
    }

    private function find(OrderPlacement $placement): ?Order
    {
        return Order::query()
            ->with('lines')
            ->where('source', $placement->source)
            ->where('placement_key', $placement->placementKey)
            ->first();
    }

    private function replay(Order $order, string $hash, OrderPlacement $placement): Placement
    {
        if (! hash_equals($order->placement_hash, $hash)) {
            throw OrderPlacementConflict::from($placement->source, $placement->placementKey);
        }

        return new Placement(OrderData::from($order), false);
    }

    /**
     * Whether this driver is telling us a unique index refused the row.
     *
     * SQLSTATE `23000` and `23505` are the integrity-violation classes across
     * MySQL, Postgres and SQLite. Matched on the code rather than the message,
     * because messages are localised and vendor-specific and the code is not.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
