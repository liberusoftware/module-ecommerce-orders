<?php

declare(strict_types=1);

use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Actions\TransitionOrder;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Queries\OrderQuery;

it('finds an order by the number a customer quotes', function () {
    $placed = (new PlaceOrder())->handle(placement());

    $found = (new OrderQuery())->byNumber($placed->order->number);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($placed->order->id)
        ->and($found->relationLoaded('lines'))->toBeTrue();
});

it('has no lookup by id, because an incrementing id in a URL enumerates everybody s orders', function () {
    expect(method_exists(OrderQuery::class, 'byId'))->toBeFalse();
});

it('answers whether a placement landed, without attempting it again', function () {
    $placed = (new PlaceOrder())->handle(placement('did-it-land'));

    expect((new OrderQuery())->byPlacement('did-it-land')->id)->toBe($placed->order->id)
        ->and((new OrderQuery())->byPlacement('never-sent'))->toBeNull()
        // Scoped by source: a marketplace's key is not a checkout's.
        ->and((new OrderQuery())->byPlacement('did-it-land', 'marketplace'))->toBeNull();
});

it('lists only the orders a downstream module may act on', function () {
    $pending = Order::query()->findOrFail((new PlaceOrder())->handle(placement('p', teamId: 9000001))->order->id);
    $confirmed = Order::query()->findOrFail((new PlaceOrder())->handle(placement('c', teamId: 9000001))->order->id);
    (new TransitionOrder())->handle($confirmed, OrderStatus::Confirmed);

    $open = (new OrderQuery())->openForWork()->pluck('id')->all();

    expect($open)->toBe([$confirmed->id])
        ->and($open)->not->toContain($pending->id);
});

it('scopes by team and store with bound values, never with a null that compiles to is null', function () {
    // `where('team_id', null)` compiles to `is null` and would list exactly the
    // orphan orders the policy denies. The scope binds a value or omits the
    // clause entirely.
    (new PlaceOrder())->handle(placement('mine', teamId: 9000001));
    (new PlaceOrder())->handle(placement('theirs', teamId: 9000002));
    (new PlaceOrder())->handle(placement('orphan'));

    Order::query()->update(['status' => OrderStatus::Confirmed->value]);

    expect((new OrderQuery())->openForWork(9000001)->count())->toBe(1)
        ->and((new OrderQuery())->openForWork()->count())->toBe(3);
});

it('finds orders still owed for, oldest first', function () {
    $old = Order::factory()->create(['placed_at' => now()->subDays(3)]);
    Order::factory()->create(['placed_at' => now()]);
    Order::factory()->confirmed()->create(['placed_at' => now()->subDays(3)]);

    $stale = (new OrderQuery())->pendingSince(now()->subDay())->get();

    expect($stale)->toHaveCount(1)
        ->and($stale->first()->id)->toBe($old->id);
});

it('lists one customer s orders, newest first', function () {
    $older = Order::factory()->create(['customer_id' => 9000005, 'placed_at' => now()->subDay()]);
    $newer = Order::factory()->create(['customer_id' => 9000005, 'placed_at' => now()]);
    Order::factory()->create(['customer_id' => 9000006]);

    expect((new OrderQuery())->forCustomer(9000005)->pluck('id')->all())->toBe([$newer->id, $older->id]);
});
