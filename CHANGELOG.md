# Changelog

All notable changes to `liberusoftware/ecommerce-orders` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions are bare `MAJOR.MINOR.PATCH` tags — no `v` prefix — per ADR 0005 of the
Ecommerce repository.

## 0.1.0 — 2026-08-10

First release. The module is extracted from
`liberusoftware/ecommerce-laravel`, where these classes shipped as
`App\Models\Order`, `App\Models\OrderItem`, `App\Models\OrderStatusHistory`,
`App\Models\OrderNote` and `App\Models\OrderEvent`. The namespace is new, and so
are the tables — see *Changed* below for why nothing kept a bare name.

### Added

- **The order.** `Order` with lines, a four-state machine, integer-minor-unit
  totals, the addresses the customer gave, and the tax evidence they supplied.
  Its public reference is `number` and not `id`: an incrementing id in a
  customer-facing URL is an enumeration of everybody's orders.
- **The order line as a published contract.** `Data\OrderLineData`, reachable
  through `Queries\OrderQuery::line()` and `::lines()`. Fulfillment
  ([#859](https://github.com/liberusoftware/ecommerce-laravel/issues/859)) and
  Returns ([#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906))
  are both definitionally about order lines, so the line is a contract rather
  than an internal detail.

  Its `id` is **stable and public**, and what makes that safe is a rule rather
  than a column: a line is never deleted and never replaced. Cancelling raises
  `cancelled_quantity`; it does not remove the row.
- **Three accounting counters and one action.** `fulfilled_quantity` (written by
  Fulfillment), `cancelled_quantity` (written by Orders) and `returned_quantity`
  (written by Returns), all moving through `Actions\AccountForLine` because all
  three share one guard. Counters and not a status, because a line of five can be
  three shipped, one cancelled and one outstanding at the same moment.

  Two invariants, refused rather than clamped: `fulfilled + cancelled ≤ quantity`
  and `returned ≤ fulfilled`. Append-only — there is no move that un-ships
  something.
- **The Orders/Returns boundary, drawn at delivery**, and enforced in three
  places rather than documented in one: `completed → cancelled` is absent from
  the transition table, `CancelOrder` refuses an order with anything fulfilled,
  and `returned ≤ fulfilled` is arithmetic. Cancelling is calling off something
  that has not happened; getting delivered goods back is a physical workflow with
  a refund decision attached, and that is Returns'.
- **A status state machine, not a free enum.** `pending → confirmed → completed`,
  with `cancelled` reachable from the first two. `Actions\TransitionOrder` is the
  only door — `status` and the four state timestamps are deliberately absent from
  `$fillable` — and an illegal move throws `IllegalOrderTransition` and writes
  nothing: not the status, not a history row, not an event. Twelve illegal moves
  have a test each, including every self-transition, because a no-op would put a
  history row against a move nobody made.
- **Idempotent placement, guaranteed by a unique index.** `unique(source,
  placement_key)`, not a `select` — a lookup followed by an insert has a window
  and a redelivered queue job will land in it. A redelivery returns the existing
  order with `created: false` and dispatches nothing.
- **Two exception classes for two opposite conditions.**
  `OrderPlacementConflict` is permanent (a key reused for different facts; answer
  `409`) and `OrderPlacementInFlight` is transient (the winner has not committed;
  answer `423`). Checkout publishes one class for both and its API has to rebuild
  a message string from the domain's own factory to tell them apart — recorded in
  `MIGRATION_PLAN.md` as a seam. A surface over this module uses `instanceof`.
- **`Data\OrderPlacement`, this module's own input shape**, with
  `fromCheckoutArray()` reading the *wire shape* a `PlacedCheckout` serialises to
  rather than the class. A shape is a contract you can copy; a class is a
  dependency you have to install.
- **Append-only status history**, `ecommerce_orders_status_changes`, with both
  ends of every move. A state machine that cannot say when it moved is a state
  machine nobody can audit.
- **Three domain events** — `OrderPlaced`, `OrderTransitioned`,
  `OrderLineAccounted` — carrying plain values rather than Eloquent models.
  `OrderTransitioned` carries both ends of the edge, so no listener keeps a
  second copy of the transition table.
- **`OrderPolicy`**, registered explicitly, answering every ability by name
  including the ones that are always false. `create`, `update`, `delete`,
  `restore` and `forceDelete` are permanently refused, each for a domain reason
  rather than out of caution.
- **`Queries\OrderQuery`** — `byNumber`, `byPlacement`, `line`, `lines`,
  `openForWork`, `pendingSince`, `forCustomer`. No `byId`, on purpose.
- **Telemetry**, off by default, writing three structured records and no personal
  data at all.

### Changed

- **Every table is new and prefixed `ecommerce_orders_`.** There is no bare-name
  exception here, which is a deliberate departure from
  `MODULE_DEVELOPMENT.md` §1.5. The host's `orders` table had accreted columns
  from at least three other domains across ten migrations; keeping the name would
  have meant keeping the shape, and the shape is what is being fixed.
  `docs/adoption.md` §2 lists every column and where it went.
- **Money is integer minor units.** The host's
  `change_orders_total_amount_to_decimal` went the other way. There is no decimal
  column anywhere here, and a schema test asserts it across every table.
  Converting is string arithmetic — `(int) (19.99 * 100)` is `1998` — and a value
  more precise than its currency is refused rather than rounded.
- **One `create` migration per table**, with every column already on it. The ten
  accretion migrations in the host are exactly the thing not reproduced.
- **`order_date`, a `string`, becomes `placed_at`, a timestamp.**
- **The status set shrank from ten to four.** `paid` and `failed` describe a
  tender; `supplier_queued` and `supplier_failed` describe a supplier;
  `refunded` and `partially_refunded` follow a return. Copying them here would
  make this module the second place each of those facts is written.

### Deliberately not included

- **No payment capture, no gateway, and no provider name anywhere in `src/`.** A
  boundary test greps for five of them. `payment_status`, `payment_method` and
  `transaction_id` are all absent, and a schema test asserts their absence.
- **No tenders and no consents.** A `PlacedCheckout` carries both and
  `OrderPlacement` drops them. They are Checkout's evidence; an order that copied
  them would be a second, diverging record of a payment it never takes.
- **No carrier, service, quote or tracking columns.** A carrier's quote is a
  *shipment* fact — one order can ship in three parcels on three carriers, so a
  carrier column on the order can only hold the first answer and then be wrong.
  Shipping ([#915](https://github.com/liberusoftware/ecommerce-laravel/issues/915))
  and Fulfillment. `shipping_cost` survives as a **line** with `kind = shipping`,
  because it is money the customer agreed to.
- **No supplier columns.** Dropshipping
  ([#853](https://github.com/liberusoftware/ecommerce-laravel/issues/853)).
- **No refunds.** Returns.
- **No download fields on lines.** Digital fulfillment.
- **No notes and no event timeline.** The host's `order_notes` and `order_events`
  are not reproduced. Notes are a customer-service concern, and a free-text field
  next to an event logger is where personal data gets typed — the wave-4 finding.
  `OrderStatusChange::$reason` is a short slug capped at 64 characters for the
  same reason. A host wanting a timeline builds it from the three domain events.
- **No coupon or VAT validation.** `coupon_code` is the label the customer typed
  and `vat_number` is what they asserted. Both are kept because an invoice and an
  auditor ask for them; neither is looked up.
- **No scheduler.** `OrderQuery::pendingSince()` finds orders still owed for; what
  to do about them is the host's policy.

### Boundary

- **Orders imports nothing.** No `require` on any sibling
  `liberusoftware/ecommerce-*` package, and no `use Liberu\Ecommerce\<Other>\…`
  anywhere in `src/`.
- **Checkout emits `CheckoutCompleted` and this module does not subscribe to
  it** — subscribing is importing. The host writes one listener, carried verbatim
  in `README.md` and `docs/adoption.md`. The suite runs with no checkout module
  present, under a test named for the fact, over product ids nothing in the
  database has heard of.
- **No foreign key leaves this module.** `team_id`, `store_id`, `customer_id`,
  `checkout_session_id`, `product_id`, `variant_id` and `actor_id` are plain
  indexed columns, asserted as a set so a table with no keys still makes an
  assertion.
