<?php

namespace Liberu\Ecommerce\Orders\Data;

use Liberu\Ecommerce\Orders\Enums\LineKind;

/**
 * One line of a placement, as the caller describes it.
 *
 * **Orders' own input shape**, not somebody else's read model. That distinction
 * is the wave-5 boundary rule: this package requires no sibling
 * `liberusoftware/ecommerce-*` package and imports from none, so it cannot
 * accept a `Liberu\Ecommerce\Checkout\Data\LineData` — accepting one is what
 * `require`ing Checkout looks like from the inside.
 *
 * Every figure arrives **already computed**. Nothing here derives a price, looks
 * a tax rate up, or allocates a discount: those all happened before the customer
 * agreed, and recomputing them now is how the number on the receipt stops
 * matching the number on the order. `fromArray()` reads the wire shape a
 * `PlacedCheckout` line serialises to — see `OrderPlacement` for why that is a
 * copy of a contract and not a dependency on one.
 */
final readonly class OrderLineInput
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $name,
        public int $quantity = 1,
        public int $unitPriceMinor = 0,
        public int $subtotalMinor = 0,
        public int $discountMinor = 0,
        public int $netMinor = 0,
        public int $taxMinor = 0,
        public int $grossMinor = 0,
        public LineKind $kind = LineKind::Item,
        public bool $taxable = true,
        public ?int $taxRateBp = null,
        public ?int $productId = null,
        public ?int $variantId = null,
        public ?string $sku = null,
        public ?array $metadata = null,
        public int $position = 0,
    ) {}

    /** @param array<string, mixed> $line */
    public static function fromArray(array $line): self
    {
        $quantity = (int) ($line['quantity'] ?? 1);
        $unitPrice = (int) ($line['unit_price_minor'] ?? 0);
        // Defaulted rather than required, so a caller who has only a unit price
        // and a quantity — a phone order typed by staff — gets arithmetic that
        // is at least self-consistent. A caller handing in totals (every
        // checkout does) overrides all of them and none of this runs.
        $subtotal = (int) ($line['subtotal_minor'] ?? $unitPrice * $quantity);
        $discount = (int) ($line['discount_minor'] ?? 0);
        $net = (int) ($line['net_minor'] ?? $subtotal - $discount);
        $tax = (int) ($line['tax_minor'] ?? 0);

        return new self(
            name: (string) $line['name'],
            quantity: $quantity,
            unitPriceMinor: $unitPrice,
            subtotalMinor: $subtotal,
            discountMinor: $discount,
            netMinor: $net,
            taxMinor: $tax,
            grossMinor: (int) ($line['gross_minor'] ?? $net + $tax),
            kind: LineKind::tryFrom((string) ($line['kind'] ?? 'item')) ?? LineKind::Item,
            taxable: (bool) ($line['taxable'] ?? true),
            taxRateBp: isset($line['tax_rate_bp']) ? (int) $line['tax_rate_bp'] : null,
            productId: isset($line['product_id']) ? (int) $line['product_id'] : null,
            variantId: isset($line['variant_id']) ? (int) $line['variant_id'] : null,
            sku: isset($line['sku']) ? (string) $line['sku'] : null,
            metadata: isset($line['metadata']) && is_array($line['metadata']) ? $line['metadata'] : null,
            position: (int) ($line['position'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'quantity' => $this->quantity,
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
}
