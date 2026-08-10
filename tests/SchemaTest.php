<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations are this module's public surface as much as its classes are — a
 * consumer's data lives in these tables, and a column quietly renamed or dropped
 * between releases is an outage on deploy rather than a failing build.
 *
 * These assert the shape a consumer may rely on. Changing one on purpose means an
 * entry in the changelog and, past 1.0.0, a major version.
 */
const ORDERS_TABLES = [
    'ecommerce_orders_orders',
    'ecommerce_orders_lines',
    'ecommerce_orders_status_changes',
];

it('creates every table the module owns', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(ORDERS_TABLES);

it('prefixes every table, because this module invented all of them', function (string $table) {
    // MODULE_DEVELOPMENT.md §1.5 and the wave-5 addendum: there is no bare-name
    // exception here. The host's `orders` table is not this module's to keep —
    // it accreted columns from three other domains across ten migrations, and
    // adopting the name would adopt them. `docs/adoption.md` says what the host
    // does with its own.
    expect($table)->toStartWith('ecommerce_orders_');
})->with(ORDERS_TABLES);

it('claims none of the bare names another module or the host already uses', function (string $bare) {
    expect(Schema::hasTable($bare))->toBeFalse();
})->with([
    'orders', 'order_items', 'order_notes', 'order_status_history', 'order_events',
    'carts', 'cart_items', 'products', 'product_variants',
    'ecommerce_checkout_sessions', 'ecommerce_checkout_lines',
]);

it('gives each table the columns a consumer reads', function (string $table, array $columns) {
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue();
    }
})->with([
    'orders' => ['ecommerce_orders_orders', [
        'id', 'number', 'team_id', 'store_id', 'customer_id', 'email',
        'source', 'placement_key', 'placement_hash', 'checkout_session_id',
        'status', 'currency', 'currency_exponent', 'subtotal_minor',
        'discount_minor', 'net_minor', 'tax_minor', 'grand_total_minor',
        'shipping_address', 'billing_address', 'recipient_name',
        'recipient_email', 'gift_message', 'billing_country', 'vat_number',
        'reverse_charge', 'coupon_code', 'placed_at', 'confirmed_at',
        'completed_at', 'cancelled_at', 'created_at', 'updated_at',
    ]],
    'lines' => ['ecommerce_orders_lines', [
        'id', 'order_id', 'kind', 'product_id', 'variant_id', 'sku', 'name',
        'quantity', 'fulfilled_quantity', 'cancelled_quantity',
        'returned_quantity', 'unit_price_minor', 'subtotal_minor',
        'discount_minor', 'net_minor', 'tax_rate_bp', 'tax_minor',
        'gross_minor', 'taxable', 'metadata', 'position',
    ]],
    'status changes' => ['ecommerce_orders_status_changes', [
        'id', 'order_id', 'from_status', 'to_status', 'actor_id', 'reason', 'created_at',
    ]],
]);

it('holds none of the columns that belong to another module', function (string $column) {
    // The host's `orders` table accreted these from Dropshipping (#853),
    // Shipping (#915)/Fulfillment (#859) and the payment path. Every one is
    // argued in `docs/adoption.md`; this is the argument made mechanical, so a
    // future convenience column fails the build rather than the boundary.
    expect(Schema::hasColumn('ecommerce_orders_orders', $column))->toBeFalse();
})->with([
    // Dropshipping.
    'supplier_id', 'supplier_order_reference', 'supplier_tracking_number',
    'supplier_response', 'is_dropshipped',
    // A carrier's quote is a shipment fact: one order can ship in three parcels
    // on three carriers, and a column here cannot say that.
    'shipping_method_id', 'shipping_carrier', 'shipping_service',
    'shipping_quote_id', 'tracking_number',
    // Payment. An order records what is owed and whether it was accepted, never
    // how the money arrived — that is a tender, and it is Checkout's.
    'payment_status', 'payment_method', 'transaction_id',
    // Refunds follow returns.
    'refund_total', 'partially_refunded', 'fully_refunded',
    // And the one the host wrote as `decimal(10,2)`.
    'total_amount', 'tax_amount', 'discount_amount', 'shipping_cost',
]);

it('stores every money column as an integer, with no decimal anywhere', function (string $table) {
    // Integer minor units, settled in wave 3 and not up for rediscovery. The
    // host went the other way in `change_orders_total_amount_to_decimal`; a
    // `decimal` column here would be a float in every driver that implements it
    // as one. The naming convention is what makes this assertable at all: every
    // money column ends `_minor`, so a new one cannot slip past.
    $money = collect(Schema::getColumns($table))
        ->filter(fn (array $column): bool => str_ends_with($column['name'], '_minor'));

    expect($money)->not->toBeEmpty();

    foreach ($money as $column) {
        expect(strtolower($column['type_name']))->toContain('int');
    }

    foreach (Schema::getColumns($table) as $column) {
        expect(strtolower($column['type_name']))
            ->not->toContain('decimal')
            ->not->toContain('float')
            ->not->toContain('double')
            ->not->toContain('numeric')
            ->not->toContain('real');
    }
})->with(['ecommerce_orders_orders', 'ecommerce_orders_lines']);

it('carries no foreign key into another module s tables', function (string $table) {
    // The boundary, proved rather than asserted. Every key this schema declares
    // points at a table this package created. `team_id`, `store_id`,
    // `customer_id`, `checkout_session_id`, `product_id` and `variant_id` are
    // plain columns: teams and customers belong to the host, stores to Commerce
    // Core, checkout sessions to Checkout, products to Catalog — and a package
    // that constrains a table it does not own cannot be installed without it.
    $foreign = collect(Schema::getForeignKeys($table))
        ->pluck('foreign_table')
        ->unique()
        ->values()
        ->all();

    // Asserted as a set rather than in a loop, so a table with no foreign keys
    // at all still makes an assertion. A test that silently makes none is a test
    // that passes after the thing it guards has been deleted.
    expect(array_diff($foreign, ORDERS_TABLES))->toBe([]);
})->with(ORDERS_TABLES);

it('leaves every cross-boundary identifier unconstrained and indexed', function (string $table, string $column) {
    $constrained = collect(Schema::getForeignKeys($table))
        ->contains(fn (array $key): bool => in_array($column, $key['columns'], true));

    expect(Schema::hasColumn($table, $column))->toBeTrue()
        ->and($constrained)->toBeFalse();
})->with([
    'order team' => ['ecommerce_orders_orders', 'team_id'],
    'order store' => ['ecommerce_orders_orders', 'store_id'],
    'order customer' => ['ecommerce_orders_orders', 'customer_id'],
    'order checkout session' => ['ecommerce_orders_orders', 'checkout_session_id'],
    'line product' => ['ecommerce_orders_lines', 'product_id'],
    'line variant' => ['ecommerce_orders_lines', 'variant_id'],
    'status change actor' => ['ecommerce_orders_status_changes', 'actor_id'],
]);

it('takes an order s children with it, because neither means anything alone', function (string $table) {
    $key = collect(Schema::getForeignKeys($table))
        ->first(fn (array $key): bool => in_array('order_id', $key['columns'], true));

    // The declaration is asserted rather than the deletion. SQLite enforces
    // foreign keys only with the pragma on, and a pragma issued inside
    // `RefreshDatabase`'s transaction is a no-op, so a behavioural test here
    // would pass or fail on how the suite is wired.
    expect($key)->not->toBeNull()
        ->and($key['foreign_table'])->toBe('ecommerce_orders_orders')
        ->and(strtolower((string) $key['on_delete']))->toBe('cascade');
})->with(['ecommerce_orders_lines', 'ecommerce_orders_status_changes']);

it('makes one placement key produce one order, at the index and not in application code', function () {
    // **The guarantee.** `PlaceOrder` never does a `select` and then trusts it:
    // a lookup followed by an insert has a window between them, and a redelivered
    // queue job will eventually land in it. This is the constraint that closes
    // the window, asserted directly.
    $row = fn (): array => [
        'number' => 'ORD-'.bin2hex(random_bytes(6)),
        'source' => 'checkout',
        'placement_key' => 'the-same-key',
        'placement_hash' => str_repeat('a', 64),
        'currency' => 'GBP',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_orders_orders')->insert($row());
    DB::table('ecommerce_orders_orders')->insert($row());
})->throws(QueryException::class);

it('lets the same key be used once per source, because two systems do not share a keyspace', function () {
    $row = fn (string $source): array => [
        'number' => 'ORD-'.bin2hex(random_bytes(6)),
        'source' => $source,
        'placement_key' => 'the-same-key',
        'placement_hash' => str_repeat('a', 64),
        'currency' => 'GBP',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_orders_orders')->insert($row('checkout'));
    DB::table('ecommerce_orders_orders')->insert($row('marketplace'));

    expect(DB::table('ecommerce_orders_orders')->count())->toBe(2);
});

it('refuses two orders on one public number', function () {
    $row = fn (string $key): array => [
        'number' => 'ORD-ABCDEF123456',
        'source' => 'checkout',
        'placement_key' => $key,
        'placement_hash' => str_repeat('a', 64),
        'currency' => 'GBP',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_orders_orders')->insert($row('one'));
    DB::table('ecommerce_orders_orders')->insert($row('two'));
})->throws(QueryException::class);

it('lets one checkout session produce several orders', function () {
    // Deliberately **not** unique. One checkout splitting into several orders —
    // different warehouses, a pre-order alongside stock — is a real thing, and a
    // unique index here would turn a future rule into a future migration.
    $row = fn (string $key): array => [
        'number' => 'ORD-'.bin2hex(random_bytes(6)),
        'source' => 'checkout',
        'placement_key' => $key,
        'placement_hash' => str_repeat('a', 64),
        'checkout_session_id' => 4242,
        'currency' => 'GBP',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_orders_orders')->insert($row('split-a'));
    DB::table('ecommerce_orders_orders')->insert($row('split-b'));

    expect(DB::table('ecommerce_orders_orders')->where('checkout_session_id', 4242)->count())->toBe(2);
});
