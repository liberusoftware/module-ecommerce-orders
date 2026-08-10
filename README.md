# Ecommerce: Orders

> This package is the authoritative, provider-neutral implementation of Orders. It owns domain behavior and data; optional API, Filament, Livewire, React, Vue, and Nuxt packages translate its public contracts for their surfaces.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-orders?sort=semver)](https://github.com/liberusoftware/module-ecommerce-orders/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-orders/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-orders/actions/workflows/tests.yml)

The order: a placement made durable. Integer-minor-unit line snapshots that are
never recomputed, a status state machine that refuses an illegal move, idempotent
creation guaranteed by a unique index, and a published line contract for
Fulfillment and Returns to hold.

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

```bash
composer require liberusoftware/ecommerce-orders
```

Installing boots nothing. The module ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers it, and only when
the deployment names it:

```dotenv
MODULES_ENABLED=ecommerce-orders
```

```php
use Liberu\Ecommerce\Orders\Actions\{PlaceOrder, TransitionOrder, CancelOrder, AccountForLine};
use Liberu\Ecommerce\Orders\Data\{OrderPlacement, OrderLineInput};
use Liberu\Ecommerce\Orders\Enums\{OrderStatus, LineAccount};

$placement = new OrderPlacement(
    placementKey: $idempotencyKey,
    currency: 'GBP',
    lines: [new OrderLineInput(
        name: 'Merino Crew', quantity: 2, unitPriceMinor: 1999,
        subtotalMinor: 3998, netMinor: 3998, taxMinor: 800, grossMinor: 4798,
        taxRateBp: 2000, productId: 42,
    )],
    grandTotalMinor: 4798,
    teamId: 7,
    email: 'shopper@example.com',
);

$result = (new PlaceOrder())->handle($placement);
$result->created;        // true the first time, false on a redelivery
$result->order->number;  // 'ORD-…' — the public reference

(new TransitionOrder())->handle($order, OrderStatus::Confirmed, reason: 'payment-settled');
(new AccountForLine())->handle($line, LineAccount::Fulfilled, 2);
```

## The listener the host must write

**Orders does not subscribe to `CheckoutCompleted`.** Subscribing means writing
`use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted`, and that is an import —
this package requires no sibling `liberusoftware/ecommerce-*` package and imports
from none. Its whole suite runs with no checkout module installed, under a test
named for the fact.

So the host — the only place entitled to know both modules exist — writes this:

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
can copy; a class is a dependency you have to install. The suite pins that shape
against a literal array written out by hand, so if Checkout ever changes it, this
repository's build is what says so.

Three order facts a checkout does not carry are passed alongside it:

```php
OrderPlacement::fromCheckoutArray(
    $event->checkout->toArray(),
    vatNumber: $vatNumber,          // customer evidence
    reverseCharge: $reverseCharge,  // customer evidence
    couponCode: $couponCode,        // a label for the invoice
);
```

A **queued** listener can be redelivered by the queue — which is exactly what the
placement key is for. See below.

## The order line is a published contract

Fulfillment ([#859](https://github.com/liberusoftware/ecommerce-laravel/issues/859))
and Returns ([#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906))
are both definitionally about order lines, so the line is a contract and not an
internal detail.

**The identifier is stable and public.** A downstream module may store
`OrderLineData::$id`. What makes that safe is a rule: **a line is never deleted
and never replaced.** Cancelling raises `cancelled_quantity`; it does not remove
the row.

**They hold the read model, not the Eloquent model:**

```php
use Liberu\Ecommerce\Orders\Queries\OrderQuery;

$line = (new OrderQuery())->line($orderLineId);   // ?OrderLineData

$line->id;                      // stable, public
$line->productId;               // int|null — no foreign key, no Catalog dependency
$line->variantId;               // int|null
$line->quantity;                // what was ordered
$line->fulfilledQuantity;       // shipped or delivered   — Fulfillment writes this
$line->cancelledQuantity;       // called off             — Orders writes this
$line->returnedQuantity;        // came back              — Returns writes this
$line->outstandingQuantity();   // quantity − fulfilled − cancelled
$line->returnableQuantity();    // fulfilled − returned
$line->unitPrice();             // Money — frozen, never recomputed
```

One action writes all three counters, because all three share one guard:

```php
(new AccountForLine())->handle($line, LineAccount::Fulfilled, 3);
```

Two invariants, refused rather than clamped: `fulfilled + cancelled ≤ quantity`,
and `returned ≤ fulfilled`. The second is the Orders/Returns boundary written as
arithmetic.

## Where Orders stops and Returns starts

**The line is delivery.**

| | Owner |
| --- | --- |
| Calling off an order before anything has shipped | **Orders** — `CancelOrder` |
| Calling off the outstanding part of a part-shipped order | **Orders** — `AccountForLine` with `Cancelled` |
| Getting delivered goods back, and the refund | **Returns (#906)** |
| Recording that goods came back | Returns calls `AccountForLine` with `Returned` |

Cancelling is calling off something that has not happened. Once goods are with a
courier, calling it off is a physical workflow with a refund decision attached,
and none of that is a status change. Three places enforce it: `completed →
cancelled` is not in the transition table, `CancelOrder` refuses an order with
anything fulfilled, and `returned ≤ fulfilled`.

## The status is a state machine

```
pending ──▶ confirmed ──▶ completed
   │            │
   └────────────┴──────▶ cancelled
```

`Actions\TransitionOrder` is the only door. `status` is deliberately absent from
`Order::$fillable`, and so are the four state timestamps. An illegal move throws
`IllegalOrderTransition` and **writes nothing** — not the status, not a history
row, not an event.

`completed → cancelled` is the refusal that matters most: that is a return.
Backwards moves, resurrections, skipping `confirmed`, and self-transitions are
all refused too — a no-op would put a history row against a move nobody made.

Six of the host's ten statuses are deliberately absent, because they describe
facts this module does not own: `paid`/`failed` are a tender,
`supplier_queued`/`supplier_failed` are a supplier, and
`refunded`/`partially_refunded` follow a return.

## Idempotency

| | |
| --- | --- |
| **The key** | `(source, placement_key)`. For a checkout, the placement key is the checkout's own idempotency key. |
| **The guarantee** | A **unique index** on `ecommerce_orders_orders`, not a `select`. A lookup followed by an insert has a window, and a redelivered queue job will land in it. |
| **Same key, same facts** | A redelivery. The existing order comes back with `created: false`. Nothing is written and **no event is dispatched**. |
| **Same key, different facts** | `OrderPlacementConflict` — **permanent**. Retrying cannot help. Answer `409`. |
| **Same key, winner not committed** | `OrderPlacementInFlight` — **transient**. Retry and it clears. Answer `423`. |

**Two conditions, two exception classes.** Checkout ships one class for both and
its API has to rebuild a message string from the domain's own factory to tell a
`409` from a `423`. A surface over this module uses `instanceof`.

`checkout_session_id` is stored, indexed and deliberately **not** unique: one
checkout splitting into several orders is a real thing, and a unique index there
would turn a future rule into a future migration.

## Money

**Integer minor units, everywhere.** No float, no `decimal` column. Every money
column ends `_minor`, and a schema test asserts every one is an integer type
*and* that no column anywhere is decimal, float or numeric.

Converting a decimal is **string arithmetic**:

```php
(int) (19.99 * 100);                        // 1998  ← wrong
MinorUnits::fromDecimalString('19.99');     // 1999  ← right
```

A value more precise than its currency is **refused**, not rounded. `"19.995"` in
GBP is a rounding decision, and this package will not make it silently on
somebody's invoice.

**Tax is an input**, arriving as a rate in basis points or an already-computed
amount. Nothing here looks a rate up, knows a jurisdiction, or compounds.
`gross === net + tax` by construction.

**Line money is a snapshot and is never recomputed** — not from a catalogue, not
from a pricing module, not from what is left after a cancellation. It is what the
customer agreed to.

## What this module does not own

- **No payment capture, no gateway, no provider name anywhere in `src/`.**
- **No tenders and no consents.** A `PlacedCheckout` carries both, and
  `OrderPlacement` deliberately drops them — they are Checkout's evidence, and an
  order that copied them would be a second, diverging record of a payment it
  never takes.
- **No shipments, carriers or tracking.** A carrier's quote is a *shipment* fact:
  one order can ship in three parcels on three carriers. Fulfillment (#859) and
  Shipping (#915).
- **No suppliers.** Dropshipping (#853).
- **No refunds and no returns.** Returns (#906).
- **No products, prices or stock.** `product_id` and `variant_id` are numbers
  with no foreign key and no relation, and `name`, `sku` and `unit_price_minor`
  are copied — a line has to keep meaning something after the product it names is
  renamed, re-priced or deleted.
- **No coupon or VAT validation.** `couponCode` is the label the customer typed;
  `vatNumber` is what they asserted. No lookup, no VIES, no jurisdiction.
- **No notes or customer-service timeline.** A free-text field next to an event
  logger is where personal data gets typed.
- **No Filament, Livewire or HTTP surface.**

### What the host owns

**The team and customer models.** Both are resolved from
`config('orders.*_model')` at call time and never imported. `customer_model` has
no default — asking for the relation without configuring it throws, rather than
guessing a class and failing later with a message about a missing table.

```bash
php artisan vendor:publish --tag=orders-config
```

**Confirming an order.** Nothing here decides that money has been accounted for.
Until the host calls `TransitionOrder` with `Confirmed`, the order is `pending`
and `isOpenForWork()` is false.

**Its own `orders` table.** This module does not adopt it —
[`docs/adoption.md`](docs/adoption.md) §2 lists every column and where it goes,
and §2.2 covers the decimal-to-minor-units conversion.

**The schedule.** Nothing here runs on a timer.

## Documentation

- [Adoption guide](docs/adoption.md) — install, the listener to write, and what to do with the host's `orders` table
- [The domain](docs/domain.md) — the line contract, the state machine, the Orders/Returns boundary, tables
- [Runbook](docs/runbook.md) — what breaks in production and what to do about it
- [Changelog](CHANGELOG.md)
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-orders/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-orders" alt="Contributors to liberusoftware/module-ecommerce-orders">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-orders/graphs/contributors).
