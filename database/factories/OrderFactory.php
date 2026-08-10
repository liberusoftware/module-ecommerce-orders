<?php

namespace Liberu\Ecommerce\Orders\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use Liberu\Ecommerce\Orders\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => Order::generateNumber(),
            'team_id' => null,
            'store_id' => null,
            'customer_id' => null,
            'email' => $this->faker->safeEmail(),
            'source' => 'checkout',
            'placement_key' => (string) Str::uuid(),
            'placement_hash' => hash('sha256', (string) Str::uuid()),
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'placed_at' => now(),
        ];
    }

    public function ownedBy(int $teamId): static
    {
        return $this->state(fn (): array => ['team_id' => $teamId]);
    }

    public function inStore(int $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }

    /**
     * A status set directly, which no production path may do.
     *
     * Only a factory is allowed this: every real move goes through
     * `Actions\TransitionOrder` and its transition table. A test that needs an
     * order *in* a state, rather than a test of how it got there, uses this and
     * says nothing about legality.
     */
    public function status(OrderStatus $status): static
    {
        return $this->state(function () use ($status): array {
            $stamp = $status->timestampColumn();

            return $stamp === null ? ['status' => $status] : ['status' => $status, $stamp => now()];
        });
    }

    public function confirmed(): static
    {
        return $this->status(OrderStatus::Confirmed);
    }

    public function completed(): static
    {
        return $this->status(OrderStatus::Completed);
    }

    public function cancelled(): static
    {
        return $this->status(OrderStatus::Cancelled);
    }
}
