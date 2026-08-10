# Adoption

Installing the module, wiring the one listener it cannot wire itself, and what to
do with the host's existing `orders` table.

---

## 1. Install and enable

```bash
composer require liberusoftware/ecommerce-orders
```

Installing boots nothing. The package ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers it, and only when
the deployment names it:

```dotenv
MODULES_ENABLED=ecommerce-orders
```

Then:

```bash
php artisan migrate
php artisan vendor:publish --tag=orders-config   # optional
```

### What the host must supply

| Setting | Default | Notes |
| --- | --- | --- |
| `orders.team_model` | `App\Models\Team` | Resolved at call time, never imported. |
| `orders.customer_model` | *none* | No default. Asking for `Order::customer()` without setting it **throws**, rather than guessing a class and failing later with a message about a missing table. |
| `orders.telemetry.enabled` | `false` | Structured domain-event records. Off by default; a busy storefront writes thousands an hour. |
| `orders.telemetry.channel` | *null* | A Laravel log channel name. |

---

## 2. The host's `orders` table

**This module does not adopt it.** Every table here is new and carries the
`ecommerce_orders_` prefix, and `SchemaTest` asserts both halves — that the
module's tables are prefixed, and that `orders`, `order_items`, `order_notes`,
`order_status_history` and `order_events` are *not* present.

That is a deliberate departure from `MODULE_DEVELOPMENT.md` §1.5's "a table that
existed in the host keeps its bare name". The rule exists so an extraction does
not rename a hundred tables mid-migration. It does not fit here, because the
host's `orders` table is not the same table:

- It has accreted columns from **at least three other domains** across ten
  migrations.
- Its money is `decimal(10,2)` — the wrong direction, see §2.2.
- Its `order_date` is a `string`.
- Half its columns are nullable because a later migration had to make them so
  after guest checkout started failing on them.

Keeping the name would mean keeping the shape, and the shape is what is being
fixed.

### 2.1 The column split, and the reasoning for each

| Host column | Goes to | Why |
| --- | --- | --- |
| `supplier_id`, `supplier_order_reference`, `supplier_tracking_number`, `supplier_response`, `is_dropshipped` | **Dropshipping (#853)** | A supplier is a *sourcing* decision made after the order exists, and it can change — a second supplier, a re-route — without anything about the order changing. It is a fact about how the merchant fills the order, not about what the customer bought. |
| `shipping_carrier`, `shipping_service`, `shipping_quote_id`, `shipping_method_id` | **Shipping (#915)** / **Fulfillment (#859)** | **A carrier's quote is a shipment fact.** One order can ship in three parcels on three carriers on three days, so a carrier column on the order is a shape that cannot represent what happens — it can only hold the *first* answer and then be wrong. `shipping_quote_id` additionally points at `shipping_quotes`, a table this package does not own. |
| `shipping_cost` | **Orders**, as a **line** | Money the customer agreed to, so it is an order fact. It arrives as a line with `kind = shipping` rather than as a column, so discount allocation, the tax rate and the rounding have exactly one implementation — and so a two-parcel order can carry two shipping lines. |
| `shipping_address`, `recipient_name`, `recipient_email`, `gift_message` | **Orders** | **The address the customer gave is an order fact.** It is what they agreed to, it is on the invoice, and it must not change when a shipment is rerouted. A *shipment's* destination is Fulfillment's own copy, taken from here at the moment it ships — which is exactly why the two must not be the same column. A gift is a choice the buyer made, not a courier's business. |
| `billing_country` | **Orders** | The place of supply the order was priced against; the invoice cites it and an OSS return groups by it. Kept as its own indexed 2-char column *as well as* inside `billing_address`, because no portable index reaches into a JSON column. |
| `vat_number`, `reverse_charge` | **Orders** | **Customer evidence**, in the same sense a consent record is. Tax is an input per the settled rule, and a VAT number is not a rate — it is the *reason* the input was what it was, and the thing an auditor asks for when no VAT was charged. Kept, and **never validated here**: no VIES lookup, no jurisdiction. |
| `tax_amount`, `discount_amount` | **Orders**, as `tax_minor` / `discount_minor` | Tax is an input, arriving already computed. Discount likewise; it was allocated pro-rata before the customer agreed, and re-allocating it here would be a second answer to a settled rule. |
| `coupon_code` | **Orders**, as a label | The code the customer typed, kept so an invoice can print it. No validation, no lookup, no Promotions dependency — the amount it was worth is already in the lines. |
| `total_amount` `decimal(10,2)` | **Refused.** See §2.2 | |
| `payment_status`, `payment_method`, `transaction_id` | **Checkout / whoever owns the tender** | An order records what is *owed* and whether it was *accepted*, never how the money arrived. `confirmed` is the whole of this module's opinion about payment. A `transaction_id` here would also be a provider reference in a package that names no provider. |
| `refund_total`, `partially_refunded`, `fully_refunded` | **Returns (#906)** | Money going back follows a return. |
| `download_link`, `download_expires_at`, `download_count` (on `order_items`) | **Digital fulfillment** | A download is a fulfillment method. |
| `customer_id`, `user_id`, `customer_email` | **Orders**, as `customer_id` + `email` | One id, resolved from `orders.customer_model` at call time. The host's split into a `Customer` and a `User` is the host's own; this package holds an id. |
| `order_date` (a `string`) | **Orders**, as `placed_at` (a timestamp) | |
| `status` | **Orders**, as a four-state machine | See [`domain.md`](./domain.md) §4 for the six statuses that belong elsewhere. |

`SchemaTest` asserts the absence of every column in the "goes elsewhere" rows, so
a future convenience column fails the build rather than the boundary.

### 2.2 The money conversion

The host's `2026_07_14_001101_change_orders_total_amount_to_decimal` widened
`total_amount` from `integer` to `decimal(10,2)`. **That is the wrong direction.**
Money here is an integer count of minor units, there is no decimal column
anywhere, and `SchemaTest` asserts it.

Converting is **string arithmetic**, and the reason is one line long:

```php
(int) (19.99 * 100);                             // 1998  ← wrong
MinorUnits::fromDecimalString('19.99');          // 1999  ← right
```

`19.99` is not representable in binary floating point, and the cast truncates
what is left. Splitting on the point, padding the fraction to the currency's
exponent and concatenating cannot lose a penny, because no float is ever built.
`tests/MoneyTest.php` pins it with the wrong answer written next to the right one.

So a host converting its own rows must:

1. Read the decimal column **as a string**, not as a float. A `SELECT` into PHP
   that lands in a float has already lost the penny before any conversion runs.
2. Convert with `MinorUnits::fromDecimalString($value, $exponent)`.
3. Expect a throw on any value with more precision than the currency holds.
   `"19.995"` is a rounding decision, and this package will not make it silently
   on somebody's invoice — round it deliberately first.

`shipping_cost`, `tax_amount` and `discount_amount` convert the same way.

### 2.3 What to do with the table itself

This is pre-production, and nothing consumes the host's `orders` table across a
boundary yet. So:

- **Delete the host's `orders`, `order_items`, `order_notes`,
  `order_status_history` and `order_events` migrations** and the
  `App\Models\Order*` classes, in the change that adopts this module. Do not
  write a corrective migration; the tables are new, not renamed.
- If a deployment has rows worth keeping, convert them with a one-off command
  that reads the decimal columns as strings (§2.2) and calls `PlaceOrder` with a
  `source` of its own — `'legacy'`, say — and the old order id as the placement
  key. That gets the idempotency guarantee for free: running the command twice
  imports nothing twice.
- `abandoned_carts.recovery_order_id` points at the old table. It belongs to
  Cart, and Cart holds identifiers rather than foreign keys, so it becomes an
  `ecommerce_orders_orders.id`.

---

## 3. The listener the host must write

**This is the one integration this package cannot do for itself.**

Checkout emits `CheckoutCompleted` carrying a `PlacedCheckout`. **Orders does not
subscribe to it** — subscribing means writing
`use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted`, and that is an import.
Orders requires no sibling `liberusoftware/ecommerce-*` package and imports from
none; its whole suite runs with no checkout module installed, under a test named
for the fact.

So the host — the only place entitled to know that both modules exist — writes
this:

```php
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;

Event::listen(CheckoutCompleted::class, function (CheckoutCompleted $event): void {
    (new PlaceOrder())->handle(
        OrderPlacement::fromCheckoutArray($event->checkout->toArray())
    );
});
```

`fromCheckoutArray()` reads the **wire shape** a `PlacedCheckout` serialises to —
snake_case keys, integer minor units — not the class. A shape is a contract you
can copy; a class is a dependency you have to install. `tests/BoundaryTest.php`
pins that shape against a literal array written out by hand, so if Checkout ever
changes it, this repository's suite is what says so.

### Facts a checkout does not carry

Three order facts have to come from whatever surface collected them:

```php
OrderPlacement::fromCheckoutArray(
    $event->checkout->toArray(),
    vatNumber: $vatNumber,          // customer evidence
    reverseCharge: $reverseCharge,  // customer evidence
    couponCode: $couponCode,        // a label for the invoice
);
```

`billingCountry` is derived from the billing address, then the shipping one, and
can be overridden.

### If the listener is queued

`CheckoutCompleted` is dispatched exactly once per placement, but a *queued*
listener can be redelivered by the queue. That is precisely what the placement
key is for: a redelivery calls `PlaceOrder` with the same key, loses at the unique
index, and gets the order that already exists back with `created: false`. Nothing
is written and `OrderPlaced` is not dispatched a second time.

Catch the two placement exceptions if the listener needs to distinguish them:

```php
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementConflict;   // permanent — do not retry
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementInFlight;   // transient — release and retry
```

---

## 4. What the host still owns

**Confirming an order.** Nothing here decides that money has been accounted for.
The host settles its tenders and calls:

```php
(new TransitionOrder())->handle($order, OrderStatus::Confirmed, reason: 'payment-settled');
```

Until that happens the order is `pending` and `isOpenForWork()` is false, so no
downstream module should act on it. Allocating stock against an order whose money
has not been accounted for is how a fraud loses inventory as well as goods.

**The schedule.** Nothing here runs on a timer. `OrderQuery::pendingSince()`
finds orders still owed for; what to do about them is the host's policy. See the
[runbook](./runbook.md).

**Notes, invoices and the customer-service timeline.** The host's `order_notes`
and `order_events` tables are not reproduced. Notes are a customer-service
concern, and a free-text field next to an event logger is where personal data
gets typed — the wave-4 finding. `OrderStatusChange::$reason` is a **short slug**,
capped at 64 characters by the column, for the same reason. A host that wants a
timeline builds it from this module's three domain events, which carry no
personal data at all.

**Authorization of guests.** `OrderPolicy` governs **staff**. A customer reaching
their own order does so by `number`, and `OrderQuery` has no lookup by id on
purpose.

---

## 5. Presentation packages

`module-ecommerce-orders-api`, `-filament` and `-livewire` are separate
repositories that delegate to the actions, queries and policy here. This package
references no Filament, no Livewire and no HTTP, and a boundary test greps for
them.
