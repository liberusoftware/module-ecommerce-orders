<?php

namespace Liberu\Ecommerce\Orders\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Ecommerce\Orders\Enums\LineKind;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\Ecommerce\Orders\Models\OrderLine;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    /**
     * The default `product_id` is a number nothing in this database has heard of.
     * Catalog is not installed in this suite and never will be — a line is an
     * identifier plus an already-resolved value, and it has to work with nothing
     * behind it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'kind' => LineKind::Item,
            'product_id' => 987654321,
            'variant_id' => null,
            'sku' => 'GHOST-1',
            'name' => 'Merino Crew',
            'quantity' => 1,
            'unit_price_minor' => 1999,
            'subtotal_minor' => 1999,
            'discount_minor' => 0,
            'net_minor' => 1999,
            'tax_rate_bp' => 2000,
            'tax_minor' => 400,
            'gross_minor' => 2399,
            'taxable' => true,
            'position' => 0,
        ];
    }

    public function of(Order $order): static
    {
        return $this->state(fn (): array => ['order_id' => $order->id]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }
}
