<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Orders\Actions\AccountForLine;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Data\OrderLineData;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Events\OrderLineAccounted;
use Liberu\Ecommerce\Orders\Exceptions\LineAccountingExceeded;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;
use Liberu\Ecommerce\Orders\Queries\OrderQuery;

/**
 * **The published contract.** Fulfillment (#859) and Returns (#906) are both
 * definitionally about order lines, so everything asserted here is something two
 * later modules will be built against. Changing any of it is a breaking change,
 * not a refactor.
 */
it('publishes a line identifier that is stable and public', function () {
    $order = (new PlaceOrder())->handle(placement())->order;
    $id = $order->lines[0]->id;

    // A downstream module stores this number. What makes that safe is a rule
    // rather than a column: a line is never deleted and never replaced.
    $line = OrderLine::query()->findOrFail($id);
    (new AccountForLine())->handle($line, LineAccount::Cancelled, 1);

    expect(OrderLine::query()->find($id))->not->toBeNull()
        ->and(OrderLine::query()->find($id)->id)->toBe($id)
        ->and(OrderLine::query()->find($id)->order_id)->toBe($order->id);
});

it('carries everything a downstream module needs, without exposing the model', function () {
    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 5)]))->order;
    $line = $order->lines[0];

    expect($line)->toBeInstanceOf(OrderLineData::class)
        ->and($line->id)->toBeInt()
        ->and($line->orderId)->toBe($order->id)
        ->and($line->productId)->toBe(987654321)
        ->and($line->variantId)->toBeNull()
        ->and($line->quantity)->toBe(5)
        ->and($line->fulfilledQuantity)->toBe(0)
        ->and($line->cancelledQuantity)->toBe(0)
        ->and($line->returnedQuantity)->toBe(0)
        ->and($line->outstandingQuantity())->toBe(5)
        ->and($line->returnableQuantity())->toBe(0);

    // And the serialised form carries the two derived counts too, so a consumer
    // over HTTP does not have to subtract anything itself.
    expect($line->toArray())->toHaveKeys([
        'id', 'order_id', 'product_id', 'variant_id', 'quantity',
        'fulfilled_quantity', 'cancelled_quantity', 'returned_quantity',
        'outstanding_quantity', 'returnable_quantity',
    ]);
});

it('hands a line back by its id alone, which is all a downstream module holds', function () {
    $order = (new PlaceOrder())->handle(placement())->order;

    $line = (new OrderQuery())->line($order->lines[0]->id);

    expect($line)->toBeInstanceOf(OrderLineData::class)
        ->and($line->id)->toBe($order->lines[0]->id)
        ->and($line->currency)->toBe('GBP')
        ->and($line->currencyExponent)->toBe(2);
});

it('answers null for an id nothing has, rather than guessing', function () {
    expect((new OrderQuery())->line(987654321))->toBeNull();
});

it('tracks the three ways a quantity stops being outstanding', function () {
    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 5)]))->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);
    $action = new AccountForLine();

    $action->handle($line, LineAccount::Fulfilled, 3);
    $action->handle($line, LineAccount::Cancelled, 1);
    $action->handle($line, LineAccount::Returned, 2);

    $fresh = $line->fresh();

    expect($fresh->fulfilled_quantity)->toBe(3)
        ->and($fresh->cancelled_quantity)->toBe(1)
        ->and($fresh->returned_quantity)->toBe(2)
        ->and($fresh->outstandingQuantity())->toBe(1)
        ->and($fresh->returnableQuantity())->toBe(1);
});

it('refuses to account for more than the line holds', function () {
    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 5)]))->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);

    (new AccountForLine())->handle($line, LineAccount::Fulfilled, 4);

    // fulfilled + cancelled ≤ quantity. Asking to cancel two of the one left is
    // a bug in the caller, and clamping it to one would hide it.
    expect(fn () => (new AccountForLine())->handle($line->fresh(), LineAccount::Cancelled, 2))
        ->toThrow(LineAccountingExceeded::class);

    expect($line->fresh()->cancelled_quantity)->toBe(0);
});

it('refuses a return of something that never shipped, which is where Returns stops and Orders starts', function () {
    // `returned ≤ fulfilled`, the Orders/Returns boundary written as arithmetic.
    // Nothing can come back that never went out; calling that off is a
    // cancellation, and cancellation is Orders'.
    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 5)]))->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);

    expect(fn () => (new AccountForLine())->handle($line, LineAccount::Returned, 1))
        ->toThrow(LineAccountingExceeded::class, 'never went out');

    (new AccountForLine())->handle($line, LineAccount::Fulfilled, 2);

    expect(fn () => (new AccountForLine())->handle($line->fresh(), LineAccount::Returned, 3))
        ->toThrow(LineAccountingExceeded::class);

    (new AccountForLine())->handle($line->fresh(), LineAccount::Returned, 2);

    expect($line->fresh()->returned_quantity)->toBe(2)
        ->and($line->fresh()->returnableQuantity())->toBe(0);
});

it('is append-only, because there is no move that un-ships something', function (int $quantity) {
    $order = (new PlaceOrder())->handle(placement())->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);

    (new AccountForLine())->handle($line, LineAccount::Fulfilled, $quantity);
})->with([0, -1, -5])->throws(LineAccountingExceeded::class);

it('tells a downstream module what its own move left behind', function () {
    Event::fake([OrderLineAccounted::class]);

    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 4)]))->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);

    (new AccountForLine())->handle($line, LineAccount::Fulfilled, 3);

    Event::assertDispatched(OrderLineAccounted::class, function (OrderLineAccounted $event) use ($line): bool {
        // The line **after** the move, so a listener reads the counts rather
        // than applying a delta itself.
        return $event->line->id === $line->id
            && $event->account === LineAccount::Fulfilled
            && $event->quantity === 3
            && $event->line->fulfilledQuantity === 3
            && $event->line->outstandingQuantity() === 1
            && $event->line->returnableQuantity() === 3;
    });
});

it('keeps the money on a line frozen while its quantities move', function () {
    // The snapshot rule, stated against the thing most likely to erode it: a
    // line whose counters change must not have its money recomputed from what is
    // left.
    $order = (new PlaceOrder())->handle(placement(lines: [line(quantity: 2)]))->order;
    $line = OrderLine::query()->findOrFail($order->lines[0]->id);

    (new AccountForLine())->handle($line, LineAccount::Cancelled, 1);

    $after = (new OrderQuery())->line($line->id);

    expect($after->grossMinor)->toBe(4798)
        ->and($after->netMinor)->toBe(3998)
        ->and($after->unitPriceMinor)->toBe(1999)
        ->and($after->quantity)->toBe(2);
});

it('renders a line s money in the shape every Liberu commerce API uses', function () {
    $order = (new PlaceOrder())->handle(placement())->order;
    $line = $order->lines[0];

    expect($line->unitPrice()->toArray())->toBe([
        'minor' => 1999, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '19.99',
    ])->and($line->gross()->decimal())->toBe('23.99');
});

it('publishes every line of an order at once, for a module walking the whole thing', function () {
    $order = (new PlaceOrder())->handle(placement(lines: [line(name: 'A'), line(name: 'B')]))->order;

    $lines = (new OrderQuery())->lines(Order::query()->findOrFail($order->id));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBeInstanceOf(OrderLineData::class)
        ->and($lines[0]->name)->toBe('A')
        ->and($lines[1]->name)->toBe('B');
});

it('finds one line among many on the read model without a caller writing a filter', function () {
    $order = (new PlaceOrder())->handle(placement(lines: [line(name: 'A'), line(name: 'B')]))->order;

    expect($order->line($order->lines[1]->id)->name)->toBe('B')
        ->and($order->line(987654321))->toBeNull();
});
