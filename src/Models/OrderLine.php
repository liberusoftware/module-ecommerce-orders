<?php

namespace Liberu\Ecommerce\Orders\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Orders\Database\Factories\OrderLineFactory;
use Liberu\Ecommerce\Orders\Enums\LineKind;

/**
 * One frozen line of an order — and the row two later modules key against.
 *
 * **This model is not the contract**; `Data\OrderLineData` is. Fulfillment and
 * Returns depend on this package and could therefore import this class, and they
 * must not: importing it means importing its table name, its casts and its
 * relations, every one of which becomes a breaking change when it moves. What
 * they hold is `id` and the read model.
 *
 * **A line is never deleted and never replaced.** That rule is what makes `id`
 * safe for another module to store. Cancelling raises `cancelled_quantity`; it
 * does not remove the row, and nothing here renumbers or swaps anything.
 *
 * `product_id` and `variant_id` are numbers. There is no `product()` relation
 * and there will not be one: Catalog is not a dependency of this package, and a
 * line has to keep meaning something after the product it names has been
 * renamed, re-priced or deleted. `name` and `sku` are copied for that reason.
 *
 * The three accounting counters are **not** fillable. They move through
 * `Actions\AccountForLine` and nowhere else, because the invariants that keep
 * them honest — `fulfilled + cancelled ≤ quantity` and `returned ≤ fulfilled` —
 * live there, and a mass-assigned counter is a way round both.
 *
 * @property int $id
 * @property int $order_id
 * @property LineKind $kind
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string|null $sku
 * @property string $name
 * @property int $quantity
 * @property int $fulfilled_quantity
 * @property int $cancelled_quantity
 * @property int $returned_quantity
 * @property int $unit_price_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $net_minor
 * @property int|null $tax_rate_bp
 * @property int $tax_minor
 * @property int $gross_minor
 * @property bool $taxable
 * @property array<string, mixed>|null $metadata
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Order $order
 */
class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_orders_lines';

    protected $fillable = [
        'order_id', 'kind', 'product_id', 'variant_id', 'sku', 'name',
        'quantity', 'unit_price_minor', 'subtotal_minor', 'discount_minor',
        'net_minor', 'tax_rate_bp', 'tax_minor', 'gross_minor', 'taxable',
        'metadata', 'position',
    ];

    protected $attributes = [
        'kind' => 'item',
        'quantity' => 1,
        'fulfilled_quantity' => 0,
        'cancelled_quantity' => 0,
        'returned_quantity' => 0,
        'unit_price_minor' => 0,
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'net_minor' => 0,
        'tax_minor' => 0,
        'gross_minor' => 0,
        'taxable' => true,
        'position' => 0,
    ];

    protected $casts = [
        'kind' => LineKind::class,
        'quantity' => 'integer',
        'fulfilled_quantity' => 'integer',
        'cancelled_quantity' => 'integer',
        'returned_quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'net_minor' => 'integer',
        'tax_rate_bp' => 'integer',
        'tax_minor' => 'integer',
        'gross_minor' => 'integer',
        'taxable' => 'boolean',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** What Fulfillment still has to ship. */
    public function outstandingQuantity(): int
    {
        return $this->quantity - $this->fulfilled_quantity - $this->cancelled_quantity;
    }

    /** What Returns may still take back. Never more than what went out. */
    public function returnableQuantity(): int
    {
        return $this->fulfilled_quantity - $this->returned_quantity;
    }

    protected static function newFactory(): Factory
    {
        return OrderLineFactory::new();
    }
}
