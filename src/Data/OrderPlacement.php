<?php

namespace Liberu\Ecommerce\Orders\Data;

/**
 * **Orders' own input shape.** Everything needed to write one order, as a plain
 * immutable value.
 *
 * ### Why this exists rather than a listener on Checkout's event
 *
 * Checkout announces a completed placement with an event carrying a
 * `PlacedCheckout`, and Orders **does not subscribe to it**. Subscribing means
 * writing a `use` statement for a class in the checkout package, and that is an
 * import — the wave-5 rule is that Orders imports nothing from a sibling
 * `liberusoftware/ecommerce-*` package. Nothing in this file, or anywhere in
 * `src/`, names that event or its namespace; `BoundaryTest` greps for the text of
 * both. This module's whole suite runs with no checkout module installed, under a
 * test named for it.
 *
 * So the wiring is one listener in the **host**, which is the only place entitled
 * to know that both modules exist. It is four lines, and `README.md` and
 * `docs/adoption.md` carry it verbatim: the host's listener receives the event,
 * calls `$event->checkout->toArray()`, and hands the result to
 * `self::fromCheckoutArray()` and then to `Actions\PlaceOrder`.
 *
 * `fromCheckoutArray()` reads the **wire shape** a `PlacedCheckout` serialises
 * to — snake_case keys, integer minor units — not the class. A shape is a
 * contract you can copy; a class is a dependency you have to install.
 * `BoundaryTest` pins the shape against a literal array written out by hand, so
 * if Checkout ever changes it, this repository's suite is what says so.
 *
 * ### What is deliberately not copied
 *
 * A `PlacedCheckout` carries **tenders** and **consents**. Neither becomes an
 * order fact. They are Checkout's evidence — what was authorised, and what a
 * shopper agreed to at the moment they agreed — and copying them here would make
 * this module a second, diverging record of a payment it never takes and a
 * consent it never collected. What an order records is what is *owed*
 * (`grandTotalMinor`) and whether it has been accepted (`confirmed`). *How* the
 * money arrived is a question for whoever owns the tender.
 */
final readonly class OrderPlacement
{
    /**
     * @param  list<OrderLineInput>  $lines
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  array<string, mixed>|null  $billingAddress
     */
    public function __construct(
        public string $placementKey,
        public string $currency,
        public array $lines,
        public string $source = 'checkout',
        public int $currencyExponent = 2,
        public int $subtotalMinor = 0,
        public int $discountMinor = 0,
        public int $netMinor = 0,
        public int $taxMinor = 0,
        public int $grandTotalMinor = 0,
        public ?int $checkoutSessionId = null,
        public ?int $teamId = null,
        public ?int $storeId = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?array $shippingAddress = null,
        public ?array $billingAddress = null,
        public ?string $recipientName = null,
        public ?string $recipientEmail = null,
        public ?string $giftMessage = null,
        public ?string $billingCountry = null,
        public ?string $vatNumber = null,
        public bool $reverseCharge = false,
        public ?string $couponCode = null,
        public ?string $placedAt = null,
    ) {}

    /**
     * Build a placement from the array a `PlacedCheckout` serialises to.
     *
     * The five arguments after the payload are the order facts a checkout
     * session does not carry. A VAT number and a reverse-charge flag are
     * **customer evidence** — what the buyer asserted, which is the reason the
     * tax input was what it was — and they are collected by whatever surface
     * asked for them. A coupon code is the label the customer typed, kept so an
     * invoice can print it; the *amount* it was worth already arrived inside the
     * lines.
     *
     * `billingCountry` falls back to the country on the billing address, then
     * the shipping one. It is duplicated out of the JSON because an OSS return
     * groups by it and no portable index reaches into a JSON column.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromCheckoutArray(
        array $payload,
        string $source = 'checkout',
        ?string $vatNumber = null,
        bool $reverseCharge = false,
        ?string $couponCode = null,
        ?string $billingCountry = null,
    ): self {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $payload['lines'] ?? [];

        $shipping = isset($payload['shipping_address']) && is_array($payload['shipping_address']) ? $payload['shipping_address'] : null;
        $billing = isset($payload['billing_address']) && is_array($payload['billing_address']) ? $payload['billing_address'] : null;

        return new self(
            placementKey: (string) $payload['idempotency_key'],
            currency: (string) $payload['currency'],
            lines: array_values(array_map(OrderLineInput::fromArray(...), $lines)),
            source: $source,
            currencyExponent: (int) ($payload['exponent'] ?? 2),
            subtotalMinor: (int) ($payload['subtotal_minor'] ?? 0),
            discountMinor: (int) ($payload['discount_minor'] ?? 0),
            netMinor: (int) ($payload['net_minor'] ?? 0),
            taxMinor: (int) ($payload['tax_minor'] ?? 0),
            grandTotalMinor: (int) ($payload['grand_total_minor'] ?? 0),
            checkoutSessionId: isset($payload['checkout_session_id']) ? (int) $payload['checkout_session_id'] : null,
            teamId: isset($payload['team_id']) ? (int) $payload['team_id'] : null,
            storeId: isset($payload['store_id']) ? (int) $payload['store_id'] : null,
            customerId: isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            shippingAddress: $shipping,
            billingAddress: $billing,
            billingCountry: $billingCountry ?? self::countryOf($billing) ?? self::countryOf($shipping),
            vatNumber: $vatNumber,
            reverseCharge: $reverseCharge,
            couponCode: $couponCode,
            placedAt: isset($payload['placed_at']) ? (string) $payload['placed_at'] : null,
        );
    }

    /**
     * What separates a redelivery from a mistake.
     *
     * SHA-256 over a recursively key-sorted encoding, so a caller whose JSON
     * serialiser reorders fields is a retry and not a conflict. The whole
     * placement is hashed, including `placedAt`: a redelivered event carries the
     * identical value, and two placements that differ only in when they say they
     * happened are two different orders.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode(self::canonicalise($this->toArray())));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
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
            'gift_message' => $this->giftMessage,
            'billing_country' => $this->billingCountry,
            'vat_number' => $this->vatNumber,
            'reverse_charge' => $this->reverseCharge,
            'coupon_code' => $this->couponCode,
            'placed_at' => $this->placedAt,
            'lines' => array_map(fn (OrderLineInput $line): array => $line->toArray(), $this->lines),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private static function countryOf(?array $address): ?string
    {
        $country = $address['country'] ?? null;

        return is_string($country) && $country !== '' ? strtoupper(substr($country, 0, 2)) : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalise(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }
}
