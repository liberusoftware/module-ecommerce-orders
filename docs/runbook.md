# Runbook

What breaks in production, how to tell which thing broke, and what to do about
it.

---

## Nothing in this package runs on a timer

There is no scheduler, no queue worker and no background job here. Every sweep
below is a query this module exposes and a decision the host makes. That is
deliberate: a package that schedules work has decided somebody else's queue
depth.

```php
// app/Console/Kernel.php, or wherever the host schedules
$schedule->command('orders:chase-pending')->hourly();
```

---

## 1. Orders are piling up in `pending`

**Symptom.** `OrderQuery::pendingSince(now()->subHour())` returns a growing set.
Customers say they paid; nothing has shipped.

**What it means.** `pending` is "the order exists and is owed for". An order
leaves it only when something calls `TransitionOrder` with `Confirmed`. **This
module never decides that money arrived.** So a pile-up is almost always upstream
of here:

1. A payment webhook stopped arriving, so nothing is calling `confirm`.
2. The host's listener on `CheckoutCompleted` is queued and the queue is stopped
   — in which case there will be *no* orders at all, not pending ones. Check
   which.
3. The confirmation step is throwing. Look for `IllegalOrderTransition` in the
   log: an order already `cancelled` cannot be confirmed, and a retried webhook
   for an order already `confirmed` will throw on the no-op transition.

**What to do.** Find them, do not guess:

```php
(new OrderQuery())->pendingSince(now()->subHours(2))->get();
```

Confirming is safe to do by hand once the money is verified:

```php
(new TransitionOrder())->handle($order, OrderStatus::Confirmed, actorId: $staffId, reason: 'manual-confirm');
```

**What not to do.** Do not write `status` directly. It is not fillable, the
transition table is the only control, and a direct write leaves no history row —
so the next person investigating has an order in a state with no explanation of
how it got there.

---

## 2. A customer says they were charged twice, or has two order numbers

**First, check whether there really are two orders:**

```php
(new OrderQuery())->byPlacement($checkoutIdempotencyKey);
```

If that returns **one** order, the duplicate is not here. Two charges against one
order is a tender problem, and tenders belong to Checkout and the payment
provider.

If it returns one order but the customer has two *numbers*, they are looking at
two genuinely different placements — two checkout sessions, two keys. Check
`checkout_session_id` on both.

**Two orders with the same `placement_key` is impossible** while
`unique(source, placement_key)` exists. If you are looking at two, the index has
been dropped. Check:

```sql
SELECT source, placement_key, COUNT(*) c
FROM ecommerce_orders_orders
GROUP BY source, placement_key HAVING c > 1;
```

An empty result and a present index is the expected state. Restore the index
before anything else; it is the guarantee, not the `select` in `PlaceOrder`.

---

## 3. `OrderPlacementConflict` in the log

**Permanent. Retrying will not help, and a retry policy that keeps trying is
making it worse.**

A caller used one placement key for two genuinely different placements. The
`placement_hash` on the existing order does not match the hash of what just
arrived.

**Diagnose:**

```php
$order = (new OrderQuery())->byPlacement($key, $source);
$order->placement_hash;        // what the first call committed
$placement->hash();            // what this call sent
```

**Causes, most likely first:**

1. A client generating one key per *session* rather than one per *placement*.
2. A retry sent after the placement's facts changed — a line added, a total
   moved. That is a different order and needs a different key.
3. A test fixture or a seeder reusing a fixed key.

**Fix:** the caller sends a new key, or sends the facts the first call sent.
There is no way to make this succeed from inside this module, and that is the
point — replaying the first order would return a success for something that
never happened.

---

## 4. `OrderPlacementInFlight` in the log

**Transient. Retry and it clears.**

Two callers arrived with the same key at once. One lost at the unique index,
re-read, and found nothing because the winner's transaction had not committed
yet.

**This is normal under load and needs no action if it is rare.** A queued
listener should release and retry with backoff; an HTTP surface should answer
`423` and let the client retry.

**It needs action if it is frequent.** Frequent means callers are duplicating
work rather than racing occasionally — usually a queue with no unique-job lock
delivering the same message to several workers, or a client retrying faster than
the first request completes.

The two exceptions are **separate classes** precisely so a retry policy can tell
them apart without parsing a message. If something is catching a common parent
and retrying both, it will hammer `OrderPlacementConflict` forever; fix that
first.

---

## 5. An order cannot be cancelled

`OrderNotCancellable` has two forms and they mean different things:

**"…is `completed`/`cancelled` and cannot be cancelled."** The order is finished.
If goods need to come back, that is a return.

**"Line N has M already fulfilled…"** Something has shipped. **This is the
Orders/Returns boundary and it is working.** Cancelling would call off goods that
are already with a courier.

**What to do instead.** Cancel the part that has not shipped:

```php
foreach ($order->lines as $line) {
    if ($line->outstandingQuantity() > 0) {
        (new AccountForLine())->handle($line, LineAccount::Cancelled, $line->outstandingQuantity());
    }
}
```

The order stays `confirmed`, because part of it is real. The delivered part comes
back through Returns (#906) when that module exists; until then, whatever process
handles returns today does, and records nothing here.

---

## 6. `LineAccountingExceeded` from a downstream module

A module asked to account for more than the line holds. **The guard is working
and the caller has a bug** — clamping would hide it in the one place a wrong
number becomes a refund.

Two messages, two causes:

**"Line N has M outstanding, so X cannot be recorded as `fulfilled`/`cancelled`."**
`fulfilled + cancelled ≤ quantity`. Usually a retried shipment confirmation that
is not idempotent on the caller's side: the first call already counted it.
Accounting here is append-only and has no natural key, so **the caller owns its
own idempotency**. Check the line's counters before deciding it is a bug:

```php
(new OrderQuery())->line($orderLineId);   // fulfilledQuantity, outstandingQuantity()
```

**"Nothing can come back that never went out."** `returned ≤ fulfilled`. A return
was recorded against a quantity that was never shipped. Either the fulfilment was
never recorded — check the shipping side — or what is being recorded is a
cancellation wearing a return's name.

---

## 7. Telemetry

Off by default. Turn it on while investigating:

```dotenv
ORDERS_TELEMETRY=true
ORDERS_TELEMETRY_CHANNEL=orders
```

Three messages, all structured, all safe to ship anywhere:

| Message | Level | Notable context |
| --- | --- | --- |
| `order.placed` | `info` | `number`, `source`, `checkout_session_id`, `grand_total_minor`, `lines` |
| `order.transitioned` | `info`, or `warning` for a cancellation | `from`, `to`, `reason` |
| `order.line_accounted` | `info` | `order_line_id`, `account`, `quantity`, `outstanding`, `returnable` |

**No personal data is ever written** — no email, no address, no recipient name, no
VAT number, and no gift message. A test asserts each of those by name. `reason` is
a short slug, capped at 64 characters by the column, so a free-text box on some
future form cannot pipe a customer's sentence into a log line.

Turn it **off** again afterwards. A busy storefront writes thousands of these an
hour.

Everything the logger writes is a domain event any listener can subscribe to, so
a deployment wanting metrics rather than logs subscribes directly and leaves this
off.

---

## 8. Useful queries

```php
// Is this order ready for a downstream module to act on?
$order->isOpenForWork();                       // true only when `confirmed`

// What is left on a line, for Fulfillment and for Returns?
$line = (new OrderQuery())->line($orderLineId);
$line->outstandingQuantity();                  // still to ship
$line->returnableQuantity();                   // still returnable

// How did this order get to where it is?
$order->statusChanges;                         // append-only, oldest first

// Everything a downstream module may work on, for one team
(new OrderQuery())->openForWork($teamId)->get();
```

```sql
-- Orders stuck pending, oldest first
SELECT number, placed_at, grand_total_minor
FROM ecommerce_orders_orders
WHERE status = 'pending' AND placed_at < datetime('now', '-2 hours')
ORDER BY placed_at;

-- Lines with something outstanding on confirmed orders
SELECT l.id, l.order_id, l.quantity - l.fulfilled_quantity - l.cancelled_quantity AS outstanding
FROM ecommerce_orders_lines l
JOIN ecommerce_orders_orders o ON o.id = l.order_id
WHERE o.status = 'confirmed'
  AND l.quantity - l.fulfilled_quantity - l.cancelled_quantity > 0;
```

---

## 9. Things that are not incidents

- **An order sitting in `pending` for minutes.** That is the state it is created
  in. Only a growing backlog is a problem.
- **`created: false` from `PlaceOrder`.** A redelivery was handled correctly.
- **A cancelled line still in the table.** Lines are never deleted; two other
  modules hold their ids.
- **`billing_country` duplicated inside `billing_address`.** Deliberate. No
  portable index reaches into a JSON column, and an OSS return groups by it.
- **A rare `OrderPlacementInFlight`.** See §4.
