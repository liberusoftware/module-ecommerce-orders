<?php

namespace Liberu\Ecommerce\Orders\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Events\OrderLineAccounted;
use Liberu\Ecommerce\Orders\Events\OrderPlaced;
use Liberu\Ecommerce\Orders\Events\OrderTransitioned;

/**
 * This module's own domain events, written as structured records.
 *
 * A listener, not an instrumentation layer: no metrics client, no tracer, no
 * second logging stack. What it adds is the vocabulary a foundation cannot
 * supply — an application's log has no idea that a spike in cancellations at one
 * store is a stockout, or that orders sitting in `pending` is a payment webhook
 * that stopped arriving.
 *
 * **Off by default.** A busy storefront writes thousands of these an hour, and a
 * package that starts filling a deployment's log the moment it installs has
 * decided somebody else's retention bill.
 *
 * **What is never written here:** no email address, no postal address, no
 * recipient name, no VAT number, and above all no gift message. Those are on the
 * order where they belong, behind a policy; a log is the store in an application
 * with the loosest access control and the longest reach. `reason` is a short slug
 * for the same reason — the column caps it at 64 characters and the domain
 * expects a slug, so a free-text box on some future form cannot pipe a customer's
 * sentence into a log line.
 *
 * Levels carry meaning so an alert needs no message parsing: a cancellation is
 * `warning`, everything else is `info`.
 */
final class DomainEventLogger
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'onOrderPlaced',
            OrderTransitioned::class => 'onOrderTransitioned',
            OrderLineAccounted::class => 'onOrderLineAccounted',
        ];
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->record('order.placed', [
            'order_id' => $event->order->id,
            'number' => $event->order->number,
            'team_id' => $event->order->teamId,
            'store_id' => $event->order->storeId,
            'source' => $event->order->source,
            'checkout_session_id' => $event->order->checkoutSessionId,
            'currency' => $event->order->currency,
            'grand_total_minor' => $event->order->grandTotalMinor,
            'lines' => count($event->order->lines),
        ]);
    }

    public function onOrderTransitioned(OrderTransitioned $event): void
    {
        $this->record('order.transitioned', [
            'order_id' => $event->order->id,
            'number' => $event->order->number,
            'team_id' => $event->order->teamId,
            'from' => $event->from->value,
            'to' => $event->to->value,
            'reason' => $event->reason,
            'grand_total_minor' => $event->order->grandTotalMinor,
        ], $event->to === OrderStatus::Cancelled ? 'warning' : 'info');
    }

    public function onOrderLineAccounted(OrderLineAccounted $event): void
    {
        $this->record('order.line_accounted', [
            'order_id' => $event->line->orderId,
            'order_line_id' => $event->line->id,
            'account' => $event->account->value,
            'quantity' => $event->quantity,
            'outstanding' => $event->line->outstandingQuantity(),
            'returnable' => $event->line->returnableQuantity(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $event, array $context, string $level = 'info'): void
    {
        if (config('orders.telemetry.enabled') !== true) {
            return;
        }

        $channel = config('orders.telemetry.channel');

        $logger = is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::channel();

        $logger->log($level, $event, $context);
    }
}
