# The domain

What Orders owns, what it refuses to own, and where the lines are drawn — because
Fulfillment ([#859](https://github.com/liberusoftware/ecommerce-laravel/issues/859))
and Returns ([#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906))
are built against wherever they are drawn.

---

## 1. The aggregate

An **order** is what a shopper committed to, made durable. It has lines, a
status, totals, the addresses the customer gave, and the tax evidence the
customer supplied. It has no payment, no shipment, no supplier and no refund.

```
Order
 ├─ OrderLine        ×N   ← the published contract
 └─ OrderStatusChange ×N  ← append-only, one row per legal move
```

Three tables, all prefixed `ecommerce_orders_`. Orders invented all of them, so
there is no bare-name exception — see §7.

---

## 2. The order line is a published contract

This is the part of the module that is not an implementation detail. Two later
modules are definitionally about order lines and will store identifiers into
them.

### The identifier is stable and public

`ecommerce_orders_lines.id`, surfaced as `OrderLineData::$id`. **A downstream
module may store this number.**

What makes that safe is a rule rather than a column:

> **A line is never deleted and never replaced.**

Cancelling raises `cancelled_quantity` to meet `quantity`; it does not remove the
row. There is no operation in this module that deletes a line, swaps one line for
another, or renumbers anything. A shipment row in Fulfillment carrying
`order_line_id = 4711` may rely on 4711 naming the same line of the same order
for as long as the order exists.

Deleting an *order* cascades to its lines, which is the one way a line id can
stop resolving. `OrderPolicy::delete` is permanently false for exactly that
reason.

### The read model, not the Eloquent model

Fulfillment and Returns depend on this package, so they *could* import
`Models\OrderLine`. They must not. A module holding another module's Eloquent
model holds its table name, its casts, its scopes and its relations, and every
one of those becomes a breaking change the day it moves.

What they hold is `Data\OrderLineData`, reached through `Queries\OrderQuery`:

```php
$line = (new OrderQuery())->line($orderLineId);   // ?OrderLineData
$lines = (new OrderQuery())->lines($order);       // list<OrderLineData>
```

It carries, per line:

| | |
| --- | --- |
| `id`, `orderId` | The stable identifiers |
| `productId`, `variantId`, `sku`, `name` | What was bought. Plain columns — no foreign key, no relation, no Catalog dependency |
| `quantity` | What was ordered |
| `fulfilledQuantity` | Shipped or delivered |
| `cancelledQuantity` | Called off before shipping |
| `returnedQuantity` | Came back after delivery |
| `outstandingQuantity()` | `quantity − fulfilled − cancelled` — **Fulfillment's to ship** |
| `returnableQuantity()` | `fulfilled − returned` — **Returns' to take back** |
| `kind`, `taxable`, `taxRateBp` | Classification |
| `unitPriceMinor` … `grossMinor`, `currency`, `currencyExponent` | The frozen money |

Both derived counts are computed here so no consumer derives one and gets it
wrong, and both appear in `toArray()` so a consumer over HTTP does not subtract
anything either.

### The three counters, and who writes each

```
fulfilled_quantity  — shipped or delivered.        Written by Fulfillment (#859).
cancelled_quantity  — called off before shipping.  Written by Orders.
returned_quantity   — came back after delivery.    Written by Returns (#906).
```

They are counters and not a status because a line of five can be three shipped,
one cancelled and one still outstanding at the same moment, and one status column
cannot say that.

All three move through **one action**, because they share one guard:

```php
(new AccountForLine())->handle($line, LineAccount::Fulfilled, 3);
```

Two invariants, refused rather than clamped:

```
fulfilled + cancelled ≤ quantity
returned ≤ fulfilled
```

Accounting is **append-only**. There is no negative move that un-ships something:
a parcel that never left is a cancellation of the outstanding quantity, and a
parcel that came back is a return. Allowing a decrement would make the counters a
running total nobody can audit.

---

## 3. Where Orders stops and Returns starts

**The line is delivery.**

| | Owner |
| --- | --- |
| Calling off an order before anything has shipped | **Orders.** `CancelOrder`. |
| Calling off the outstanding part of a part-shipped order | **Orders.** `AccountForLine` with `LineAccount::Cancelled`. |
| Getting delivered goods back | **Returns (#906).** |
| Deciding and issuing a refund | **Returns**, with whoever owns the tender. |
| Recording that goods came back | Returns calls `AccountForLine` with `LineAccount::Returned`. |

Three places enforce it, and they are the same rule stated three ways:

1. **`OrderStatus`** — `completed → cancelled` is not in the transition table.
   `IllegalOrderTransition` says so in the domain's words, not the table's.
2. **`CancelOrder`** — refuses an order with anything fulfilled, whole, before
   writing anything. `OrderNotCancellable::alreadyFulfilled` names Returns.
3. **`AccountForLine`** — `returned ≤ fulfilled`. Nothing can come back that
   never went out.

The reasoning: cancelling is calling off something that has not happened. Once
goods are with a courier, calling it off is a physical workflow with a refund
decision attached, and none of that is a status change. A module that let a
`completed` order become `cancelled` would put a goods-handling workflow behind a
column write, and the next two modules would be built on top of that.

**A partly-shipped order is not a special case to be half-handled.** Part of it
is real, so the order stays `confirmed` and only the outstanding quantity is
called off. There is no `partially_cancelled` status, for the same reason there
is no `partially_shipped` one: progress lives on the lines.

---

## 4. The status is a state machine

```
pending ──▶ confirmed ──▶ completed
   │            │
   └────────────┴──────▶ cancelled
```

| State | Means |
| --- | --- |
| `pending` | The order exists and is owed for. The only state a placement creates. Nothing downstream should act on it. |
| `confirmed` | Accepted and open for work. The signal Fulfillment waits on. |
| `completed` | Everything owed has been delivered or called off. **Terminal.** |
| `cancelled` | Called off in full before anything shipped. **Terminal.** |

`OrderStatus::isOpenForWork()` answers `true` for `confirmed` and nothing else.

`Actions\TransitionOrder` is the **only** door. `status` is deliberately absent
from `Order::$fillable`, and so are the four state timestamps, so there is no
second way in. An illegal move throws `IllegalOrderTransition` and **writes
nothing** — not the status, not a history row, not an event.

### The illegal moves, and why each is illegal

| Refused | Because |
| --- | --- |
| `completed → cancelled` | That is a **return**. §3. |
| `completed → confirmed`, `cancelled → confirmed`, `cancelled → completed` | Resurrection. An order whose history no longer explains its state. |
| `pending → completed` | No skipping, so `confirmed_at` is never null on a completed order. |
| `confirmed → pending`, `completed → pending`, `cancelled → pending` | Backwards. |
| `x → x` | A no-op is not a transition. Recording one puts a history row against a move nobody made, and lets a retried webhook stamp `confirmed_at` twice. |

Every one of these has a case in `tests/TransitionTest.php`.

### What is deliberately not a status

The host's `Order` carried ten. Six describe facts this module does not own:

- `paid`, `failed` — a **tender**. Checkout's.
- `supplier_queued`, `supplier_failed` — a **supplier**. Dropshipping's (#853).
- `refunded`, `partially_refunded` — **money going back**, which follows a
  return. Returns'.

Copying them here would make this module the second place each of those facts is
written, and a fact written in two places eventually disagrees with itself. What
an order records is what is *owed* and whether it has been **accepted**; *how*
the money arrived is a question for whoever owns the tender.

---

## 5. Placement is idempotent, and the index is the guarantee

```php
$placement = OrderPlacement::fromCheckoutArray($checkoutPayload);
$result = (new PlaceOrder())->handle($placement);   // Data\Placement
$result->created;   // true the first time, false on a redelivery
```

### The natural key

`unique(source, placement_key)` on `ecommerce_orders_orders`.

For a checkout placement, `placement_key` is the **checkout's own idempotency
key**, not its session id. Three reasons, in order of weight:

1. It is the key the *client* chose, and therefore the one it sends again on a
   retry.
2. Checkout's own README names it as the thing a listener should key on.
3. It exists for placements that never had a session — a phone order, an import,
   a marketplace feed — where a session id is null, and a nullable unique index
   guarantees nothing.

`source` is in the key because a marketplace feed and a checkout are entitled to
generate the same key without colliding.

`checkout_session_id` is stored and indexed and **deliberately not unique**: one
checkout splitting into several orders — different warehouses, a pre-order
alongside stock — is a real thing, and a unique index there would turn a future
rule into a future migration.

### Not a `select` first

A lookup followed by an insert has a window between them, and a redelivered queue
job will eventually land in it. **This is the lesson Checkout paid for and stated
in as many words.** So the insert is attempted and the loser catches the
integrity violation from the database. The `select` at the top of `PlaceOrder` is
an optimisation for the common case; deleting it would change throughput and not
correctness.

`SchemaTest` asserts the unique index directly, by inserting the same pair twice.

### Two conditions, two exception classes

| | Class | Meaning | A surface answers |
| --- | --- | --- | --- |
| Same key, same facts | *(none)* | A redelivery. The existing order comes back with `created: false`. | `200` |
| Same key, **different** facts | `OrderPlacementConflict` | **Permanent.** A caller reused a key across two different placements. Retrying cannot help. | `409` |
| Same key, winner not committed | `OrderPlacementInFlight` | **Transient.** Retry and it clears. | `423` |

Two classes on purpose. Checkout ships one class for both conditions, and its API
has to rebuild a message string from the domain's own factory to tell a `409`
from a `423` — recorded in `MIGRATION_PLAN.md` as a seam left open by design. A
surface over this module uses `instanceof`.

What separates a redelivery from a mistake is `placement_hash`: SHA-256 over a
recursively key-sorted encoding of the whole placement, so a caller whose JSON
serialiser reorders fields is a retry and not a conflict.

---

## 6. Money

**Integer minor units, everywhere.** No float, no `decimal` column, no exception.
Every money column ends `_minor`, and `SchemaTest` asserts that every one is an
integer type *and* that no column anywhere is decimal, float, double, numeric or
real.

`Money` serialises as `{"minor": 1999, "currency": "GBP", "exponent": 2,
"decimal": "19.99"}`, and `decimal` is a **string** — a JSON number `19.99` is a
float the moment it is parsed.

**Converting a decimal to minor units is string arithmetic.** `(int) (19.99 *
100)` is `1998`. `MinorUnits::fromDecimalString()` splits on the point, pads the
fraction to the currency's exponent and concatenates, so no float is ever built.
A value more precise than its currency is **refused**, not rounded — `"19.995"`
in GBP is a rounding decision, and this module is not entitled to make it
silently on somebody's invoice. `tests/MoneyTest.php` pins all of it, with the
float answer written next to the right one.

**Tax is an input.** It arrives on a line as a rate in basis points or as an
already-computed amount. Nothing here looks a rate up, knows a jurisdiction, or
compounds. `gross === net + tax` by construction.

**The money on a line is a snapshot and is never recomputed.** Not from a
catalogue, not from a pricing module, not from what is left after a cancellation.
It is what the customer agreed to. `name` and `sku` are copied for the same
reason: a line has to keep meaning something after the product it names has been
renamed, re-priced or deleted.

---

## 7. Tables

All three carry the `ecommerce_orders_` prefix. **There is no bare-name
exception**: the host's `orders` table is not this module's to keep — it accreted
columns from three other domains across ten migrations, and adopting the name
would adopt them. See [`adoption.md`](./adoption.md) §2.

`ecommerce_orders_orders` is a mechanical application of the rule rather than a
clever name. `ecommerce_orders` (no trailing underscore) would be the one table
in the fleet needing a special case in the check that keeps two modules from
claiming a name.

| Table | Holds |
| --- | --- |
| `ecommerce_orders_orders` | The order. Unique on `(source, placement_key)` and on `number`. |
| `ecommerce_orders_lines` | The published contract. Cascades from the order. |
| `ecommerce_orders_status_changes` | Every legal move, append-only. Cascades from the order. |

**No foreign key leaves this module.** `team_id`, `store_id`, `customer_id`,
`checkout_session_id`, `product_id`, `variant_id` and `actor_id` are plain
indexed columns: teams and customers belong to the host, stores to Commerce Core,
checkout sessions to Checkout, products to Catalog — and a package that
constrains a table it does not own cannot be installed without it. `SchemaTest`
proves it as a set, so a table with no keys still makes an assertion.

`number` is the public reference. There is no `OrderQuery::byId()` on purpose: an
incrementing id in a customer-facing URL is an enumeration of everybody's orders,
which is the argument that gave a checkout session its token.

---

## 8. Events

| Event | Carries | Dispatched |
| --- | --- | --- |
| `OrderPlaced` | `OrderData` | Once per **order**, not per call. A redelivery dispatches nothing. |
| `OrderTransitioned` | `OrderData`, `from`, `to`, `reason` | On every legal move. Never on a refused one. |
| `OrderLineAccounted` | `OrderLineData` (after the move), `account`, `quantity` | On every accounting move. |

Payloads are plain values, never Eloquent models, so a listener in a package that
does not depend on this one is not holding one of its classes.

`OrderTransitioned` carries **both ends of the edge**, because a listener's
question is almost always about the edge and not the destination — and a listener
that only saw the new status would have to keep its own copy of the transition
table.

"Exactly once per order" guarantees one *dispatch*, not one *delivery*. A queued
listener can still be redelivered by the queue; `$order->placementKey` is there
so a listener has a natural key of its own.

---

## 9. Authorization

`OrderPolicy` is registered explicitly in the service provider, because Laravel's
convention maps `App\Models\X` to `App\Policies\XPolicy` and this module's models
are in neither namespace. **A model with no policy is exposed, not safe** — the
unanswered gate case is permissive, and this fleet has shipped that leak three
times.

Every ability is answered **by name**, including the ones that are always false.
Filament's `get_authorization_response()` returns *allow* when a present policy
has no method for the ability asked about, so a partial policy is the same hazard
as no policy and harder to see.

| Ability | Answer |
| --- | --- |
| `viewAny`, `view` | Own team only. An order with `team_id` null is nobody's. |
| `transition` | Own team, and not terminal. |
| `cancel` | Own team, and `isCancellable()` — so a staff member with the ability still cannot cancel round the Returns boundary. |
| `create` | **Always false.** An order is placed, from a placement, under a key the caller supplies. A button that mints a fresh key per press writes a second order on a double click. |
| `update` | **Always false.** A line is a snapshot of an agreement. Editing one is not an edit, it is a different agreement. |
| `delete`, `restore`, `forceDelete` | **Always false.** Two other modules hold ids into this order's lines. |

`transition` and `cancel` are separate abilities from `view` because they are
different-sized mistakes, and a deployment that wants a second pair of eyes on one
of them needs somewhere to say so without a breaking change.

---

## 10. What this module does not own

- **No payment capture, no gateway, no provider name anywhere in `src/`.** A
  boundary test greps for five of them.
- **No tenders and no consents.** A `PlacedCheckout` carries both, and
  `OrderPlacement` deliberately drops them. They are Checkout's evidence, and an
  order that copied them would be a second, diverging record of a payment it
  never takes.
- **No shipments, carriers or tracking.** Fulfillment (#859) and Shipping (#915).
- **No suppliers.** Dropshipping (#853).
- **No refunds and no returns.** Returns (#906).
- **No products, prices or stock.** `product_id` and `variant_id` are numbers.
- **No coupon validation.** `couponCode` is the label the customer typed; the
  amount it was worth arrived already decided, inside the lines.
- **No VAT validation.** `vatNumber` is what the customer asserted. No VIES
  lookup, no jurisdiction, no rate.
- **No invoices, notes or customer-service timeline.** See
  [`adoption.md`](./adoption.md) §3.
- **No Filament, Livewire or HTTP surface.** Those are one-to-one presentation
  packages that delegate to the actions, queries and policy here.
