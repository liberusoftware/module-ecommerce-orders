<?php

namespace Liberu\Ecommerce\Orders;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Policies\OrderPolicy;
use Liberu\Ecommerce\Orders\Telemetry\DomainEventLogger;

/**
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — the package ships no `extra.laravel.providers`, so an
 * install boots nothing until the deployment names the module in
 * `MODULES_ENABLED`.
 *
 * **Nothing here subscribes to `CheckoutCompleted`.** That would be an import of
 * a sibling package, and this module has none. The host writes that listener; the
 * README and `docs/adoption.md` carry it verbatim.
 */
class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orders.php', 'orders');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Registered here rather than left to Laravel's convention: the
        // convention maps `App\Models\X` to `App\Policies\XPolicy`, and this
        // module's models are in neither namespace. An unregistered policy is not
        // a closed door — the unanswered gate case is permissive.
        Gate::policy(Order::class, OrderPolicy::class);

        // Subscribed unconditionally, and silent unless the deployment turns
        // telemetry on. Gating the subscription on config instead would make the
        // setting un-changeable at runtime, which is exactly the thing a
        // deployment wants to flip while it is investigating something.
        Event::subscribe(DomainEventLogger::class);

        $this->publishes([
            __DIR__.'/../config/orders.php' => config_path('orders.php'),
        ], 'orders-config');
    }
}
