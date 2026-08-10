<?php

declare(strict_types=1);

use Liberu\Ecommerce\Orders\Data\OrderLineInput;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\UsesTestUser;

/*
 * `UsesTestUser` brings `RefreshDatabase` with it, and both halves are wanted:
 * the policy reads `current_team_id` off a real actor, and the migrations this
 * package's provider loads need a database to run against.
 */
uses(PackageTestCase::class, UsesTestUser::class)->in(__DIR__);

/**
 * A line, described rather than fetched.
 *
 * The default `productId` is a number nothing in this database has heard of, and
 * that is the assertion the helper carries: no catalogue is installed in this
 * suite, and no checkout either. An order line is an identifier plus values that
 * were already resolved when the customer agreed to them.
 */
function line(int $quantity = 1, int $unitPriceMinor = 1999, int $taxRateBp = 2000, string $name = 'Merino Crew'): OrderLineInput
{
    $subtotal = $unitPriceMinor * $quantity;
    $tax = intdiv($subtotal * $taxRateBp + 5000, 10000);

    return new OrderLineInput(
        name: $name,
        quantity: $quantity,
        unitPriceMinor: $unitPriceMinor,
        subtotalMinor: $subtotal,
        netMinor: $subtotal,
        taxMinor: $tax,
        grossMinor: $subtotal + $tax,
        taxRateBp: $taxRateBp,
        productId: 987654321,
        sku: 'GHOST-1',
    );
}

/**
 * A placement, with the totals made consistent with its lines.
 *
 * Consistent rather than computed: this module never derives a total. The helper
 * adds them up so a test is not asserting against numbers that disagree with each
 * other, which would be a fixture bug wearing a domain bug's clothes.
 *
 * @param  list<OrderLineInput>|null  $lines
 */
function placement(string $key = 'placement-1', ?array $lines = null, string $source = 'checkout', ?int $teamId = null, ?int $checkoutSessionId = 4242): OrderPlacement
{
    $lines ??= [line()];

    $net = array_sum(array_map(fn (OrderLineInput $line): int => $line->netMinor, $lines));
    $tax = array_sum(array_map(fn (OrderLineInput $line): int => $line->taxMinor, $lines));

    return new OrderPlacement(
        placementKey: $key,
        currency: 'GBP',
        lines: $lines,
        source: $source,
        subtotalMinor: $net,
        netMinor: $net,
        taxMinor: $tax,
        grandTotalMinor: $net + $tax,
        checkoutSessionId: $checkoutSessionId,
        teamId: $teamId,
        email: 'shopper@example.com',
        placedAt: '2026-08-10T09:00:00+00:00',
    );
}
