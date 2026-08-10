<?php

namespace Liberu\Ecommerce\Orders\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;

/**
 * One move of the state machine, written down.
 *
 * Append-only, and there is no update path: a history you can edit answers a
 * different question from the one it was kept for.
 *
 * No factory. Rows here are written by `Actions\TransitionOrder` and by nothing
 * else, and a factory would exist only to let a test fabricate a history the
 * state machine could not have produced.
 *
 * `from_status` is null for exactly one row per order — the placement, which came
 * from nowhere.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Order $order
 */
class OrderStatusChange extends Model
{
    protected $table = 'ecommerce_orders_status_changes';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'actor_id', 'reason'];

    protected $casts = [
        'from_status' => OrderStatus::class,
        'to_status' => OrderStatus::class,
        'actor_id' => 'integer',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
