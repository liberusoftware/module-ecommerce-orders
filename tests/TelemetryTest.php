<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Orders\Actions\AccountForLine;
use Liberu\Ecommerce\Orders\Actions\CancelOrder;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Actions\TransitionOrder;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Models\Order;

/**
 * Capture what the logger wrote, in order.
 *
 * The reader is a long closure with an explicit `use (&$records)` rather than an
 * arrow function: `fn` captures by value at the point it is defined, so it would
 * hand back the empty array this starts as and never see anything the listener
 * appended.
 */
function captureLog(): Closure
{
    $records = [];

    Log::listen(function ($record) use (&$records) {
        $records[] = ['level' => $record->level, 'message' => $record->message, 'context' => $record->context];
    });

    return function () use (&$records): array {
        return $records;
    };
}

beforeEach(function () {
    config()->set('orders.telemetry.enabled', true);
    config()->set('orders.telemetry.channel', null);
});

it('writes nothing at all until a deployment asks for it', function () {
    config()->set('orders.telemetry.enabled', false);
    $records = captureLog();

    (new PlaceOrder())->handle(placement());

    expect($records())->toBe([]);
});

it('names the events in this module s own vocabulary', function () {
    $records = captureLog();

    $placed = (new PlaceOrder())->handle(placement());
    $order = Order::query()->findOrFail($placed->order->id);
    (new TransitionOrder())->handle($order, OrderStatus::Confirmed, reason: 'payment-settled');
    (new AccountForLine())->handle($order->lines[0], LineAccount::Fulfilled, 1);

    expect(collect($records())->pluck('message')->all())->toBe([
        'order.placed',
        'order.transitioned',
        'order.line_accounted',
    ]);
});

it('raises the level for a cancellation, so an alert needs no message parsing', function () {
    $records = captureLog();

    $order = Order::query()->findOrFail((new PlaceOrder())->handle(placement())->order->id);
    (new CancelOrder())->handle($order, reason: 'out-of-stock');

    $levels = collect($records())->keyBy('message')->map(fn (array $record): string => $record['level']);

    expect($levels['order.placed'])->toBe('info')
        ->and($levels['order.transitioned'])->toBe('warning');
});

it('never copies personal data into a log line', function () {
    // A log is the store in an application with the loosest access control and
    // the longest reach. The gift message is the sharpest case: free text a
    // customer wrote for one named person.
    $records = captureLog();

    $placement = new OrderPlacement(
        placementKey: 'pii',
        currency: 'GBP',
        lines: [line()],
        grandTotalMinor: 2399,
        email: 'shopper@example.com',
        shippingAddress: ['line1' => '1 High Street', 'country' => 'GB'],
        recipientName: 'Alex Recipient',
        recipientEmail: 'alex@example.com',
        giftMessage: 'Happy birthday, love from everyone at number 12',
        vatNumber: 'GB123456789',
    );

    $order = Order::query()->findOrFail((new PlaceOrder())->handle($placement)->order->id);
    (new TransitionOrder())->handle($order, OrderStatus::Confirmed);

    $written = json_encode($records());

    expect($written)
        ->not->toContain('shopper@example.com')
        ->not->toContain('alex@example.com')
        ->not->toContain('Alex Recipient')
        ->not->toContain('High Street')
        ->not->toContain('Happy birthday')
        ->not->toContain('GB123456789');
});

it('writes to the channel a deployment names', function () {
    config()->set('orders.telemetry.channel', 'stack');
    $records = captureLog();

    (new PlaceOrder())->handle(placement());

    expect(collect($records())->pluck('message')->all())->toContain('order.placed');
});
