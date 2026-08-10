<?php

namespace Liberu\Ecommerce\Orders\Data;

use JsonSerializable;
use Liberu\Ecommerce\Orders\Enums\LineKind;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * **The order line as a published contract.**
 *
 * Fulfillment (#859) and Returns (#906) are both definitionally about order
 * lines. They depend on this package, so they *could* hold an `OrderLine`
 * model — and they must not. A module holding another module's Eloquent model
 * holds its table name, its casts, its scopes and its relations, and every one
 * of those becomes a breaking change the day it moves. What they hold is this
 * value and the `id` on it.
 *
 * ### The identifier is stable and public
 *
 * `id` is the auto-increment primary key of `ecommerce_orders_lines`, and it is
 * documented as something other modules will store. What makes it safe to store
 * is a rule this module keeps: **a line is never deleted and never replaced.**
 * Cancelling raises `cancelledQuantity`, it does not remove the row. There is no
 * operation here that swaps one line for another or renumbers anything. A
 * shipment row in Fulfillment carrying `order_line_id = 4711` may rely on 4711
 * naming the same line of the same order for as long as the order exists.
 *
 * ### The quantities are the interesting part
 *
 * A downstream module's first question is never "how many were ordered" — it is
 * "how many are left for me to act on". All four counts are here so nobody has
 * to derive one and get it wrong:
 *
 *   outstanding = quantity − fulfilled − cancelled   (Fulfillment's to ship)
 *   returnable  = fulfilled − returned               (Returns' to take back)
 *
 * ### The money is a snapshot
 *
 * These figures are what the customer agreed to. They are never recomputed from
 * a live catalogue or a pricing module, and they do not move when a product is
 * re-priced. `name` and `sku` are copied for the same reason: a line has to keep
 * meaning something after the product it names has been deleted.
 */
final readonly class OrderLineData implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $id,
        public int $orderId,
        public LineKind $kind,
        public string $name,
        public int $quantity,
        public int $fulfilledQuantity,
        public int $cancelledQuantity,
        public int $returnedQuantity,
        public string $currency,
        public int $currencyExponent,
        public int $unitPriceMinor,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public bool $taxable,
        public ?int $taxRateBp = null,
        public ?int $productId = null,
        public ?int $variantId = null,
        public ?string $sku = null,
        public ?array $metadata = null,
        public int $position = 0,
    ) {}

    public static function from(OrderLine $line, string $currency, int $exponent): self
    {
        return new self(
            id: $line->id,
            orderId: $line->order_id,
            kind: $line->kind,
            name: $line->name,
            quantity: $line->quantity,
            fulfilledQuantity: $line->fulfilled_quantity,
            cancelledQuantity: $line->cancelled_quantity,
            returnedQuantity: $line->returned_quantity,
            currency: $currency,
            currencyExponent: $exponent,
            unitPriceMinor: $line->unit_price_minor,
            subtotalMinor: $line->subtotal_minor,
            discountMinor: $line->discount_minor,
            netMinor: $line->net_minor,
            taxMinor: $line->tax_minor,
            grossMinor: $line->gross_minor,
            taxable: $line->taxable,
            taxRateBp: $line->tax_rate_bp,
            productId: $line->product_id,
            variantId: $line->variant_id,
            sku: $line->sku,
            metadata: $line->metadata,
            position: $line->position,
        );
    }

    /** What Fulfillment still has to ship. */
    public function outstandingQuantity(): int
    {
        return $this->quantity - $this->fulfilledQuantity - $this->cancelledQuantity;
    }

    /** What Returns may still take back. Never more than what went out. */
    public function returnableQuantity(): int
    {
        return $this->fulfilledQuantity - $this->returnedQuantity;
    }

    public function unitPrice(): Money
    {
        return new Money($this->unitPriceMinor, $this->currency, $this->currencyExponent);
    }

    public function gross(): Money
    {
        return new Money($this->grossMinor, $this->currency, $this->currencyExponent);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'fulfilled_quantity' => $this->fulfilledQuantity,
            'cancelled_quantity' => $this->cancelledQuantity,
            'returned_quantity' => $this->returnedQuantity,
            'outstanding_quantity' => $this->outstandingQuantity(),
            'returnable_quantity' => $this->returnableQuantity(),
            'currency' => $this->currency,
            'exponent' => $this->currencyExponent,
            'unit_price_minor' => $this->unitPriceMinor,
            'subtotal_minor' => $this->subtotalMinor,
            'discount_minor' => $this->discountMinor,
            'net_minor' => $this->netMinor,
            'tax_rate_bp' => $this->taxRateBp,
            'tax_minor' => $this->taxMinor,
            'gross_minor' => $this->grossMinor,
            'taxable' => $this->taxable,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'sku' => $this->sku,
            'metadata' => $this->metadata,
            'position' => $this->position,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
