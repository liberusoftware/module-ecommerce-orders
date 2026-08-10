<?php

declare(strict_types=1);

use Liberu\Ecommerce\Orders\Actions\AccountForLine;
use Liberu\Ecommerce\Orders\Actions\CancelOrder;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Actions\TransitionOrder;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Exceptions\OrderNotCancellable;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * **Where Orders stops and Returns starts.**
 *
 * Cancelling is calling off something that has not happened. Once a line has
 * been fulfilled there are goods in the world, and getting them back is a
 * physical workflow with a refund decision attached — that is Returns (#906),
 * and it is deliberately not modelled here.
 *
 * Everything in this file is a decision the next two modules are built against.
 */
it('cancels an order nothing has shipped from', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement(lines: [line(quantity: 3), line(quantity: 2)]))->order->id);

    (new CancelOrder())->handle($order, actorId: 9000001, reason: 'customer-changed-mind');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancelled_at)->not->toBeNull();

    // Every line's outstanding quantity is accounted for, so no line is left
    // claiming to be owed.
    foreach ($order->fresh()->lines as $line) {
        expect($line->cancelled_quantity)->toBe($line->quantity)
            ->and($line->outstandingQuantity())->toBe(0);
    }
});

it('cancels a confirmed order as readily as a pending one', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement())->order->id);
    (new TransitionOrder())->handle($order, OrderStatus::Confirmed);

    (new CancelOrder())->handle($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('keeps every line, because two other modules hold their ids', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement(lines: [line(), line()]))->order->id);
    $ids = $order->lines->pluck('id')->all();

    (new CancelOrder())->handle($order);

    // A cancelled line with `cancelled_quantity === quantity` says strictly more
    // than a missing row does, and a missing row is a dangling reference in
    // Fulfillment.
    expect(OrderLine::query()->whereIn('id', $ids)->count())->toBe(2);
});

it('refuses to cancel an order anything has shipped from, and says why', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement(lines: [line(quantity: 2), line(quantity: 2)]))->order->id);

    (new AccountForLine())->handle($order->lines[1], LineAccount::Fulfilled, 1);

    expect(fn () => (new CancelOrder())->handle($order->fresh()))
        ->toThrow(OrderNotCancellable::class, 'Returns');

    // And nothing moved. The check runs across every line before anything is
    // written — cancelling four lines and then finding the fifth has shipped
    // would leave an order that is neither cancelled nor whole.
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    foreach ($order->fresh()->lines as $line) {
        expect($line->cancelled_quantity)->toBe(0);
    }
});

it('refuses to cancel an order that is already finished', function (string $state) {
    $order = Order::factory()->{$state}()->create();

    expect(fn () => (new CancelOrder())->handle($order))
        ->toThrow(OrderNotCancellable::class);
})->with(['completed', 'cancelled']);

it('leaves a part-shipped order confirmed, with the rest cancelled line by line', function () {
    // The partial case is deliberately **not** a whole-order operation. Part of
    // this order is real, so the order stays `confirmed` and only the
    // outstanding quantity is called off.
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement(lines: [line(quantity: 5)]))->order->id);
    (new TransitionOrder())->handle($order, OrderStatus::Confirmed);

    $line = $order->lines[0];
    (new AccountForLine())->handle($line, LineAccount::Fulfilled, 2);
    (new AccountForLine())->handle($line->fresh(), LineAccount::Cancelled, 3);

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($line->fresh()->outstandingQuantity())->toBe(0)
        ->and($line->fresh()->returnableQuantity())->toBe(2);
});

it('answers whether cancelling is still honest, so a surface can hide the button', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement())->order->id);

    expect($order->isCancellable())->toBeTrue();

    (new AccountForLine())->handle($order->lines[0], LineAccount::Fulfilled, 1);

    expect($order->fresh()->isCancellable())->toBeFalse()
        ->and(Order::factory()->completed()->create()->isCancellable())->toBeFalse();
});

it('records the cancellation as a transition, with its reason', function () {
    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement())->order->id);

    (new CancelOrder())->handle($order, reason: 'out-of-stock');

    $last = $order->statusChanges()->get()->last();

    expect($last->from_status)->toBe(OrderStatus::Pending)
        ->and($last->to_status)->toBe(OrderStatus::Cancelled)
        ->and($last->reason)->toBe('out-of-stock');
});
