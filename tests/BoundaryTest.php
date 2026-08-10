<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Data\OrderPlacement;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Tests\Fixtures\FakeCustomer;

/**
 * The wave-5 boundary rule, proved rather than asserted.
 *
 * **Orders imports nothing.** No `require` on any sibling
 * `liberusoftware/ecommerce-*` package, and no `use Liberu\Ecommerce\<Other>\…`
 * anywhere in `src/`. Everything that crosses is an identifier or a value already
 * resolved.
 */
it('runs its whole suite with no checkout module present', function () {
    // **The named test.** This is the place the rule is most tempting to break:
    // Checkout emits `CheckoutCompleted`, and subscribing to it would be an
    // import. So nothing here requires `liberusoftware/ecommerce-checkout`,
    // nothing imports from it, no `ecommerce_checkout_*` table exists in this
    // database, and an order is written from a placement handed in.
    expect(class_exists('Liberu'.'\\Ecommerce\\Checkout\\Events\\CheckoutCompleted'))->toBeFalse()
        ->and(class_exists('Liberu'.'\\Ecommerce\\Checkout\\Data\\PlacedCheckout'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_checkout_sessions'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_checkout_lines'))->toBeFalse();

    $placed = (new PlaceOrder())->handle(placement('no-checkout-here'));

    expect($placed->created)->toBeTrue()
        ->and($placed->order->lines)->toHaveCount(1)
        ->and($placed->order->grandTotalMinor)->toBe(2399);
});

it('holds a product nothing in the database has heard of', function () {
    // No catalogue either. The line keeps the id, the name and the price it was
    // handed, and none of them needs a `products` table to mean something. It
    // also has to keep meaning something after that product is deleted, which is
    // why the name and the SKU are copied rather than joined.
    expect(Schema::hasTable('products'))->toBeFalse()
        ->and(Schema::hasTable('product_variants'))->toBeFalse();

    $placed = (new PlaceOrder())->handle(placement('ghost-product'));
    $line = $placed->order->lines[0];

    expect($line->productId)->toBe(987654321)
        ->and($line->sku)->toBe('GHOST-1')
        ->and($line->name)->toBe('Merino Crew');
});

it('places an order with no cart, no pricing and no inventory module either', function () {
    expect(Schema::hasTable('carts'))->toBeFalse()
        ->and(Schema::hasTable('cart_items'))->toBeFalse()
        ->and(Schema::hasTable('stock_levels'))->toBeFalse()
        ->and(Schema::hasTable('prices'))->toBeFalse();

    expect((new PlaceOrder())->handle(placement('nothing-installed'))->created)->toBeTrue();
});

it('names no sibling domain package anywhere in its manifest', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach (['checkout', 'cart', 'catalog', 'pricing', 'inventory-ledger', 'commerce-core', 'fulfillment', 'returns', 'shipping', 'dropshipping'] as $sibling) {
        expect($required)->not->toContain("liberusoftware/ecommerce-{$sibling}");
    }
});

it('imports nothing from a sibling domain package, and no provider name either', function (string $needle) {
    // A grep rather than a reflection check, because the thing being forbidden is
    // the *text* — a `use` statement is a dependency whether or not the class is
    // ever loaded, and a provider name in `src/` is a payment SDK waiting to be
    // required.
    $source = collect(glob(__DIR__.'/../src/**/*.php') ?: [])
        ->merge(glob(__DIR__.'/../src/*.php') ?: [])
        ->map(fn (string $file): string => (string) file_get_contents($file))
        ->implode("\n");

    expect($source)->not->toContain($needle);
})->with([
    'the checkout namespace' => ['Liberu\\Ecommerce\\Checkout'],
    'the cart namespace' => ['Liberu\\Ecommerce\\Cart'],
    'the catalog namespace' => ['Liberu\\Ecommerce\\Catalog'],
    'the pricing namespace' => ['Liberu\\Ecommerce\\Pricing'],
    'the inventory namespace' => ['Liberu\\Ecommerce\\InventoryLedger'],
    'the commerce core namespace' => ['Liberu\\Ecommerce\\CommerceCore'],
    'the fulfillment namespace' => ['Liberu\\Ecommerce\\Fulfillment'],
    'the returns namespace' => ['Liberu\\Ecommerce\\Returns'],
    'the application namespace' => ['use App\\'],
    // No payment capture and no gateway. If one of these ever appears in `src/`,
    // this package has acquired an opinion it is not entitled to.
    'Stripe' => ['Stripe'],
    'PayPal' => ['PayPal'],
    'Braintree' => ['Braintree'],
    'Adyen' => ['Adyen'],
    'Klarna' => ['Klarna'],
]);

it('subscribes to no event it does not own', function () {
    // Stated separately from the whole-`src/` grep because it is the specific
    // rule for this module: the provider registers a policy and this module's own
    // telemetry subscriber, and nothing else. Asserted as *every* commerce
    // namespace it mentions being this one, rather than by naming the class it
    // must not mention — a test that spelled that class out would put the
    // forbidden text in the repository to look for it.
    $provider = (string) file_get_contents(__DIR__.'/../src/OrdersServiceProvider.php');

    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', $provider, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_unique($matches[1]))->toBe(['Orders']);
});

it('accepts a checkout s wire shape without ever naming its class', function () {
    // The contract, copied rather than imported. This array is the shape a
    // `PlacedCheckout` serialises to, written out by hand — if Checkout ever
    // changes it, this repository's suite is what says so, and no `composer
    // require` was needed to find out.
    $wire = [
        'checkout_session_id' => 91,
        'token' => 'a-checkout-token',
        'team_id' => 7,
        'store_id' => 3,
        'customer_id' => 55,
        'email' => 'shopper@example.com',
        'currency' => 'GBP',
        'exponent' => 2,
        'subtotal_minor' => 3998,
        'discount_minor' => 400,
        'net_minor' => 3598,
        'tax_minor' => 720,
        'grand_total_minor' => 4318,
        'shipping_address' => ['line1' => '1 High Street', 'country' => 'gb'],
        'billing_address' => ['line1' => '2 Low Street', 'country' => 'ie'],
        'lines' => [[
            'kind' => 'item', 'name' => 'Merino Crew', 'quantity' => 2,
            'currency' => 'GBP', 'exponent' => 2, 'unit_price_minor' => 1999,
            'subtotal_minor' => 3998, 'discount_minor' => 400, 'net_minor' => 3598,
            'tax_rate_bp' => 2000, 'tax_minor' => 720, 'gross_minor' => 4318,
            'taxable' => true, 'product_id' => 42, 'variant_id' => 7,
            'sku' => 'MC-1', 'metadata' => null, 'position' => 0,
        ]],
        // Deliberately present and deliberately ignored — see `OrderPlacement`.
        'tenders' => [['kind' => 'payment', 'amount_minor' => 4318, 'provider' => 'acme', 'reference' => 'auth_1']],
        'consents' => [['type' => 'terms', 'document_version' => '2026-08-01']],
        'idempotency_key' => 'client-key-1',
        'placed_at' => '2026-08-10T09:00:00+00:00',
    ];

    $placed = (new PlaceOrder())->handle(OrderPlacement::fromCheckoutArray($wire, vatNumber: 'IE1234567X', reverseCharge: true, couponCode: 'AUTUMN'));

    expect($placed->created)->toBeTrue()
        ->and($placed->order->placementKey)->toBe('client-key-1')
        ->and($placed->order->checkoutSessionId)->toBe(91)
        ->and($placed->order->grandTotalMinor)->toBe(4318)
        // The billing address wins over the shipping one, and is upper-cased.
        ->and($placed->order->billingCountry)->toBe('IE')
        ->and($placed->order->vatNumber)->toBe('IE1234567X')
        ->and($placed->order->reverseCharge)->toBeTrue()
        ->and($placed->order->couponCode)->toBe('AUTUMN')
        ->and($placed->order->lines[0]->variantId)->toBe(7)
        ->and($placed->order->lines[0]->grossMinor)->toBe(4318);

    // And the two things that stay behind. Tenders and consents are Checkout's
    // evidence; an order that copied them would be a second, diverging record of
    // a payment it never takes.
    expect(json_encode($placed->order->toArray()))
        ->not->toContain('tender')
        ->not->toContain('consent');
});

it('resolves a host model from configuration at call time, against a class it has never seen', function () {
    Schema::create('fake_customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('orders.customer_model', FakeCustomer::class);

    $customer = FakeCustomer::query()->create(['name' => 'A Shopper']);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    expect($order->customer()->getRelated())->toBeInstanceOf(FakeCustomer::class)
        ->and($order->customer)->not->toBeNull()
        ->and($order->customer->name)->toBe('A Shopper');
});

it('throws rather than guessing when the host has named no model', function (string $relation, string $setting) {
    config()->set($setting, null);

    Order::factory()->create()->{$relation}();
})->with([
    'the customer' => ['customer', 'orders.customer_model'],
    'the team' => ['team', 'orders.team_model'],
])->throws(RuntimeException::class);

it('ships no auto-registered provider, so installing boots nothing', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../module.json'), true);

    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['extra']['liberu']['name'])->toBe($manifest['name'])
        ->and($manifest['category'])->toBe('product')
        ->and($manifest['name'])->toBe('ecommerce-orders');
});
