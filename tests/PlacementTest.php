<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Data\OrderLineInput;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;
use Liberu\Ecommerce\Orders\Enums\LineKind;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Events\OrderPlaced;
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementConflict;
use Liberu\Ecommerce\Orders\Exceptions\OrderPlacementInFlight;
use Liberu\Ecommerce\Orders\Models\Order;

it('writes one order from one placement', function () {
    $placed = (new PlaceOrder())->handle(placement());

    expect($placed->created)->toBeTrue()
        ->and($placed->order->status)->toBe(OrderStatus::Pending)
        ->and($placed->order->number)->toStartWith('ORD-')
        ->and($placed->order->email)->toBe('shopper@example.com')
        ->and($placed->order->placedAt)->toStartWith('2026-08-10T09:00:00')
        ->and(Order::query()->count())->toBe(1);
});

it('starts every order pending, because nothing downstream should act on money that is not accounted for', function () {
    $placed = (new PlaceOrder())->handle(placement());

    expect($placed->order->status)->toBe(OrderStatus::Pending)
        ->and($placed->order->status->isOpenForWork())->toBeFalse();
});

it('copies the money it was handed and computes none of it', function () {
    // The snapshot rule. These figures are what the customer agreed to; nothing
    // here re-derives them from a catalogue, a pricing module or the lines.
    $placement = placement(lines: [line(quantity: 2, unitPriceMinor: 1999)]);
    $placed = (new PlaceOrder())->handle($placement);

    $line = $placed->order->lines[0];

    expect($line->unitPriceMinor)->toBe(1999)
        ->and($line->subtotalMinor)->toBe(3998)
        ->and($line->taxMinor)->toBe(800)
        ->and($line->grossMinor)->toBe(4798)
        ->and($placed->order->grandTotalMinor)->toBe(4798)
        // `gross === net + tax` by construction, all the way up.
        ->and($placed->order->grandTotalMinor)->toBe($placed->order->netMinor + $placed->order->taxMinor);
});

it('numbers lines from their position in the placement, not from what the caller claimed', function () {
    // Two lines both claiming position 3 would order arbitrarily, and the order
    // of lines on an invoice is a thing customers query.
    $placed = (new PlaceOrder())->handle(placement(lines: [
        line(name: 'First'),
        line(name: 'Second'),
        line(name: 'Third'),
    ]));

    expect(array_map(fn ($line): int => $line->position, $placed->order->lines))->toBe([0, 1, 2])
        ->and(array_map(fn ($line): string => $line->name, $placed->order->lines))->toBe(['First', 'Second', 'Third']);
});

it('records the placement as the one history row that came from nowhere', function () {
    (new PlaceOrder())->handle(placement());

    $change = Order::query()->firstOrFail()->statusChanges->first();

    expect($change->from_status)->toBeNull()
        ->and($change->to_status)->toBe(OrderStatus::Pending)
        ->and($change->reason)->toBe('placed');
});

it('replays the same order for a redelivered placement, and writes nothing', function () {
    // The whole reason this action exists. A redelivered queue job, a retried
    // API call and a double-clicked button are one event three times.
    $first = (new PlaceOrder())->handle(placement('redelivered'));
    $second = (new PlaceOrder())->handle(placement('redelivered'));

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->order->id)->toBe($first->order->id)
        ->and($second->order->number)->toBe($first->order->number)
        ->and(Order::query()->count())->toBe(1);
});

it('dispatches its event exactly once, however many times the placement arrives', function () {
    Event::fake([OrderPlaced::class]);

    (new PlaceOrder())->handle(placement('dispatched-once'));
    (new PlaceOrder())->handle(placement('dispatched-once'));
    (new PlaceOrder())->handle(placement('dispatched-once'));

    Event::assertDispatchedTimes(OrderPlaced::class, 1);
});

it('refuses a key reused for a different order, permanently and by its own class', function () {
    (new PlaceOrder())->handle(placement('reused', lines: [line(quantity: 1)]));

    // Same key, different facts. The only two safe answers are "conflict" or
    // "commit twice"; replaying the first order would be a third and the worst
    // of them — a success returned for an order that was never created.
    expect(fn () => (new PlaceOrder())->handle(placement('reused', lines: [line(quantity: 9)])))
        ->toThrow(OrderPlacementConflict::class);

    expect(Order::query()->count())->toBe(1);
});

it('tells the permanent conflict apart from the transient one by class, not by message', function () {
    // **The wave-4 lesson, not repeated.** Checkout publishes one exception for
    // both conditions and its API rebuilds a message string from the domain's own
    // factory to answer 409 or 423. Two opposite conditions, two classes, so a
    // surface uses `instanceof`.
    expect(is_subclass_of(OrderPlacementConflict::class, Throwable::class))->toBeTrue()
        ->and(is_subclass_of(OrderPlacementInFlight::class, Throwable::class))->toBeTrue()
        ->and(is_a(OrderPlacementInFlight::class, OrderPlacementConflict::class, true))->toBeFalse()
        ->and(is_a(OrderPlacementConflict::class, OrderPlacementInFlight::class, true))->toBeFalse();
});

it('answers in-flight when it loses the race and the winner has not committed', function () {
    // What is proved here is the **recovery branch**, not concurrency. SQLite
    // `:memory:` on one connection inside `RefreshDatabase` cannot run two
    // transactions at once, so the losing side is staged instead: a `creating`
    // hook writes the competing row inside the same transaction, the real insert
    // is refused by the unique index, and the rollback takes the competitor with
    // it. That is precisely the state a real loser observes — the index says the
    // key is taken and the re-read finds nothing, because the winner has not
    // committed yet.
    //
    // Being explicit about that is the point. A test claiming to prove a race
    // here would be claiming something the harness cannot deliver.
    Order::creating(function (Order $order): void {
        DB::table('ecommerce_orders_orders')->insert([
            'number' => Order::generateNumber(),
            'source' => $order->source,
            'placement_key' => $order->placement_key,
            'placement_hash' => $order->placement_hash,
            'currency' => 'GBP',
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => (new PlaceOrder())->handle(placement('in-flight')))
        ->toThrow(OrderPlacementInFlight::class);

    Order::flushEventListeners();

    // Nothing survived: the loser wrote nothing and the staged winner rolled
    // back with it.
    expect(Order::query()->count())->toBe(0);
});

it('keeps two sources in separate keyspaces', function () {
    // A marketplace feed and a checkout are entitled to generate the same key.
    $a = (new PlaceOrder())->handle(placement('shared-key', source: 'checkout'));
    $b = (new PlaceOrder())->handle(placement('shared-key', source: 'marketplace'));

    expect($a->order->id)->not->toBe($b->order->id)
        ->and(Order::query()->count())->toBe(2);
});

it('hashes the placement so field order in a caller s JSON is a retry and not a conflict', function () {
    $one = placement('canonical');
    $two = placement('canonical');

    expect($one->hash())->toBe($two->hash())
        ->and($one->hash())->not->toBe(placement('canonical', lines: [line(quantity: 3)])->hash());
});

it('places a shipping line as a line rather than as a column', function () {
    // Shipping is a line so that discount allocation, the tax rate and the
    // rounding have exactly one implementation. It also lets a two-parcel order
    // carry two shipping lines, which a column cannot.
    $placement = new OrderPlacement(
        placementKey: 'with-shipping',
        currency: 'GBP',
        lines: [
            line(),
            new OrderLineInput(name: 'Standard delivery', kind: LineKind::Shipping, unitPriceMinor: 499, subtotalMinor: 499, netMinor: 499, grossMinor: 499, taxable: false),
        ],
        grandTotalMinor: 2898,
    );

    $placed = (new PlaceOrder())->handle($placement);

    expect($placed->order->lines[1]->kind)->toBe(LineKind::Shipping)
        ->and($placed->order->lines[1]->kind->isShippable())->toBeFalse()
        ->and($placed->order->lines[1]->taxable)->toBeFalse();
});

it('places an order for a customer the host has never told it about', function () {
    $placed = (new PlaceOrder())->handle(placement(teamId: 7));

    expect($placed->order->teamId)->toBe(7)
        ->and($placed->order->customerId)->toBeNull()
        ->and($placed->order->email)->toBe('shopper@example.com');
});
