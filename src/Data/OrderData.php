<?php

namespace Liberu\Ecommerce\Orders\Data;

use JsonSerializable;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Models\Order;

/**
 * The order as anything outside this module is allowed to see it.
 *
 * Read models exist here for the same reason they do in Catalog and Checkout: an
 * `-api` package may not import a `Models\` class at all, and without something
 * like this that rule gets waived rather than met. They are also what makes the
 * event contract survivable — `OrderPlaced` carries one of these, so a listener
 * is not holding an Eloquent model belonging to a package it does not depend on.
 *
 * Every figure is in integer minor units, and `currency`/`exponent` travel with
 * them so a consumer can render without a currency table.
 *
 * `number`, not `id`, is what belongs in a customer-facing URL or a support
 * email. The id is here because a staff surface has to address a record, and the
 * *line* ids are here because two later modules hold them.
 */
final readonly class OrderData implements JsonSerializable
{
    /**
     * @param  list<OrderLineData>  $lines
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  array<string, mixed>|null  $billingAddress
     */
    public function __construct(
        public int $id,
        public string $number,
        public OrderStatus $status,
        public string $source,
        public string $placementKey,
        public string $currency,
        public int $currencyExponent,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $netMinor,
        public int $taxMinor,
        public int $grandTotalMinor,
        public array $lines,
        public string $placedAt,
        public ?int $checkoutSessionId = null,
        public ?int $teamId = null,
        public ?int $storeId = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?array $shippingAddress = null,
        public ?array $billingAddress = null,
        public ?string $recipientName = null,
        public ?string $recipientEmail = null,
        public ?string $billingCountry = null,
        public ?string $vatNumber = null,
        public bool $reverseCharge = false,
        public ?string $couponCode = null,
    ) {}

    public static function from(Order $order): self
    {
        $exponent = $order->currency_exponent;

        return new self(
            id: $order->id,
            number: $order->number,
            status: $order->status,
            source: $order->source,
            placementKey: $order->placement_key,
            currency: $order->currency,
            currencyExponent: $exponent,
            subtotalMinor: $order->subtotal_minor,
            discountMinor: $order->discount_minor,
            netMinor: $order->net_minor,
            taxMinor: $order->tax_minor,
            grandTotalMinor: $order->grand_total_minor,
            lines: $order->lines
                ->map(fn ($line): OrderLineData => OrderLineData::from($line, $order->currency, $exponent))
                ->values()
                ->all(),
            placedAt: $order->placed_at->toIso8601String(),
            checkoutSessionId: $order->checkout_session_id,
            teamId: $order->team_id,
            storeId: $order->store_id,
            customerId: $order->customer_id,
            email: $order->email,
            shippingAddress: $order->shipping_address,
            billingAddress: $order->billing_address,
            recipientName: $order->recipient_name,
            recipientEmail: $order->recipient_email,
            billingCountry: $order->billing_country,
            vatNumber: $order->vat_number,
            reverseCharge: $order->reverse_charge,
            couponCode: $order->coupon_code,
        );
    }

    public function grandTotal(): Money
    {
        return new Money($this->grandTotalMinor, $this->currency, $this->currencyExponent);
    }

    /**
     * The line a downstream module asked about, or null.
     *
     * Here rather than in a caller because `array_filter` over `->lines` is the
     * kind of thing four packages each write slightly differently.
     */
    public function line(int $id): ?OrderLineData
    {
        foreach ($this->lines as $line) {
            if ($line->id === $id) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The gift message is **deliberately absent** from this read model.
     *
     * It is free text a customer wrote for one named recipient, it is on the
     * order for whoever prints the packing slip, and every surface that has ever
     * serialised an order has eventually logged the serialisation. Read it off
     * the model, behind the policy, at the one place that needs it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'source' => $this->source,
            'placement_key' => $this->placementKey,
            'checkout_session_id' => $this->checkoutSessionId,
            'team_id' => $this->teamId,
            'store_id' => $this->storeId,
            'customer_id' => $this->customerId,
            'email' => $this->email,
            'currency' => $this->currency,
            'exponent' => $this->currencyExponent,
            'subtotal_minor' => $this->subtotalMinor,
            'discount_minor' => $this->discountMinor,
            'net_minor' => $this->netMinor,
            'tax_minor' => $this->taxMinor,
            'grand_total_minor' => $this->grandTotalMinor,
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'recipient_name' => $this->recipientName,
            'recipient_email' => $this->recipientEmail,
            'billing_country' => $this->billingCountry,
            'vat_number' => $this->vatNumber,
            'reverse_charge' => $this->reverseCharge,
            'coupon_code' => $this->couponCode,
            'placed_at' => $this->placedAt,
            'lines' => array_map(fn (OrderLineData $line): array => $line->toArray(), $this->lines),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
