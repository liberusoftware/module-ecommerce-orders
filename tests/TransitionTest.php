<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Actions\TransitionOrder;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Events\OrderTransitioned;
use Liberu\Ecommerce\Orders\Exceptions\IllegalOrderTransition;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * The status is a state machine, not a free enum. The transition table lives on
 * `OrderStatus` and `TransitionOrder` is the only door through it — `status` is
 * deliberately absent from `Order::$fillable`, so there is no second way in.
 */
it('walks an order the whole way through its life', function () {
    $order = (new PlaceOrder())->handle(placement())->order;
    $model = Order::query()->findOrFail($order->id);

    $action = new TransitionOrder();

    $action->handle($model, OrderStatus::Confirmed);
    expect($model->fresh()->status)->toBe(OrderStatus::Confirmed);

    $action->handle($model, OrderStatus::Completed);
    expect($model->fresh()->status)->toBe(OrderStatus::Completed);
});

it('allows exactly the moves the table names', function (OrderStatus $from, OrderStatus $to) {
    $order = Order::factory()->status($from)->create();

    (new TransitionOrder())->handle($order, $to);

    expect($order->fresh()->status)->toBe($to);
})->with([
    'pending to confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed],
    'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
    'confirmed to completed' => [OrderStatus::Confirmed, OrderStatus::Completed],
    'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled],
]);

it('refuses every move the table does not name, and writes nothing when it does', function (OrderStatus $from, OrderStatus $to) {
    $order = Order::factory()->status($from)->create();

    expect(fn () => (new TransitionOrder())->handle($order, $to))
        ->toThrow(IllegalOrderTransition::class);

    // An attempt that was refused is not a transition that happened: the status
    // is unmoved and no history row was written.
    expect($order->fresh()->status)->toBe($from)
        ->and($order->statusChanges()->count())->toBe(0);
})->with([
    // **The one that matters most.** Undoing a delivered order is a return, not
    // a cancellation: there are goods in a van and a refund to decide, and none
    // of that is a status change. Orders draws the line at delivery and Returns
    // (#906) owns the far side of it.
    'completed to cancelled — that is a return' => [OrderStatus::Completed, OrderStatus::Cancelled],
    // Resurrection. A cancelled order that can be confirmed again is an order
    // whose history no longer explains its state.
    'cancelled to confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
    'cancelled to completed' => [OrderStatus::Cancelled, OrderStatus::Completed],
    'completed to confirmed' => [OrderStatus::Completed, OrderStatus::Confirmed],
    // No skipping. A digital order that is paid and delivered in one breath
    // still makes two calls, so `confirmed_at` is never null on a completed
    // order.
    'pending to completed' => [OrderStatus::Pending, OrderStatus::Completed],
    // Backwards.
    'confirmed to pending' => [OrderStatus::Confirmed, OrderStatus::Pending],
    'completed to pending' => [OrderStatus::Completed, OrderStatus::Pending],
    'cancelled to pending' => [OrderStatus::Cancelled, OrderStatus::Pending],
    // A no-op is not a transition. Allowing it would put a history row against a
    // move nobody made, and let a retried webhook stamp `confirmed_at` twice.
    'pending to pending' => [OrderStatus::Pending, OrderStatus::Pending],
    'confirmed to confirmed' => [OrderStatus::Confirmed, OrderStatus::Confirmed],
    'completed to completed' => [OrderStatus::Completed, OrderStatus::Completed],
    'cancelled to cancelled' => [OrderStatus::Cancelled, OrderStatus::Cancelled],
]);

it('explains a terminal refusal in terms of the domain, not the table', function () {
    $order = Order::factory()->completed()->create();

    try {
        (new TransitionOrder())->handle($order, OrderStatus::Cancelled);
    } catch (IllegalOrderTransition $exception) {
        expect($exception->getMessage())->toContain('return');
    }
});

it('records every move it made, in order, with both ends', function () {
    $order = Order::factory()->create();
    $action = new TransitionOrder();

    $action->handle($order, OrderStatus::Confirmed, actorId: 9000001, reason: 'payment-settled');
    $action->handle($order, OrderStatus::Completed, reason: 'delivered');

    $history = $order->statusChanges()->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->from_status)->toBe(OrderStatus::Pending)
        ->and($history[0]->to_status)->toBe(OrderStatus::Confirmed)
        ->and($history[0]->actor_id)->toBe(9000001)
        ->and($history[0]->reason)->toBe('payment-settled')
        ->and($history[1]->from_status)->toBe(OrderStatus::Confirmed)
        ->and($history[1]->to_status)->toBe(OrderStatus::Completed);
});

it('stamps the moment an order arrived at each state', function () {
    $order = Order::factory()->create();
    $action = new TransitionOrder();

    expect($order->confirmed_at)->toBeNull();

    $action->handle($order, OrderStatus::Confirmed);
    expect($order->fresh()->confirmed_at)->not->toBeNull()
        ->and($order->fresh()->completed_at)->toBeNull();

    $action->handle($order, OrderStatus::Completed);
    expect($order->fresh()->completed_at)->not->toBeNull();
});

it('carries both ends of the edge on its event, so no listener keeps a second copy of the table', function () {
    Event::fake([OrderTransitioned::class]);

    $order = Order::factory()->create();
    (new TransitionOrder())->handle($order, OrderStatus::Confirmed, reason: 'payment-settled');

    Event::assertDispatched(OrderTransitioned::class, function (OrderTransitioned $event) use ($order): bool {
        return $event->order->id === $order->id
            && $event->from === OrderStatus::Pending
            && $event->to === OrderStatus::Confirmed
            && $event->reason === 'payment-settled';
    });
});

it('dispatches nothing for a move it refused', function () {
    Event::fake([OrderTransitioned::class]);

    $order = Order::factory()->completed()->create();

    expect(fn () => (new TransitionOrder())->handle($order, OrderStatus::Cancelled))
        ->toThrow(IllegalOrderTransition::class);

    Event::assertNotDispatched(OrderTransitioned::class);
});

it('leaves no second way in, because the status is not fillable', function () {
    // The guard on `TransitionOrder` is the only control, and a fillable status
    // would be a way round it from any caller holding a request array. Asserted
    // on the list rather than by attempting a mass assignment, because whether
    // Eloquent throws or silently discards depends on a framework-wide strict
    // mode this package does not get to set.
    expect((new Order())->getFillable())->not->toContain('status')
        ->not->toContain('confirmed_at')
        ->not->toContain('completed_at')
        ->not->toContain('cancelled_at')
        // And the three line counters, for the same reason: their invariants
        // live in `AccountForLine`.
        ->and((new OrderLine())->getFillable())
        ->not->toContain('fulfilled_quantity')
        ->not->toContain('cancelled_quantity')
        ->not->toContain('returned_quantity');
});

it('answers whether a downstream module may act, and only confirmed says yes', function (OrderStatus $status, bool $open) {
    expect($status->isOpenForWork())->toBe($open)
        ->and(Order::factory()->status($status)->create()->isOpenForWork())->toBe($open);
})->with([
    'pending is not owed for yet' => [OrderStatus::Pending, false],
    'confirmed is the signal' => [OrderStatus::Confirmed, true],
    'completed is finished' => [OrderStatus::Completed, false],
    'cancelled is finished' => [OrderStatus::Cancelled, false],
]);

it('knows which of its states are terminal', function () {
    expect(OrderStatus::Pending->isTerminal())->toBeFalse()
        ->and(OrderStatus::Confirmed->isTerminal())->toBeFalse()
        ->and(OrderStatus::Completed->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Completed->allowedTransitions())->toBe([])
        ->and(OrderStatus::Cancelled->allowedTransitions())->toBe([]);
});
