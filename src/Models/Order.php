<?php

namespace Liberu\Ecommerce\Orders\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Ecommerce\Orders\Database\Factories\OrderFactory;
use Liberu\Ecommerce\Orders\Enums\OrderStatus;
use RuntimeException;

/**
 * What a shopper committed to, made durable.
 *
 * **This model never reads a checkout.** It has no `checkout_session_id` foreign
 * key, no relation to one, and this package does not require
 * `liberusoftware/ecommerce-checkout`. Its lines are its own rows, copied from
 * whatever the caller handed in — and the copy is not an optimisation. The money
 * on a line is what the customer agreed to, and anything that recomputes it from
 * a live catalogue or a pricing module has arranged for the receipt and the order
 * to disagree.
 *
 * **The status is a state machine, not a column somebody writes.** `$fillable`
 * deliberately does not contain `status`: every move goes through
 * `Actions\TransitionOrder`, which consults `OrderStatus` and throws on an
 * illegal move. A mass-assigned status would be a way round the one control this
 * model has.
 *
 * @property int $id
 * @property string $number
 * @property int|null $team_id
 * @property int|null $store_id
 * @property int|null $customer_id
 * @property string|null $email
 * @property string $source
 * @property string $placement_key
 * @property string $placement_hash
 * @property int|null $checkout_session_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int $currency_exponent
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $grand_total_minor
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property string|null $gift_message
 * @property string|null $billing_country
 * @property string|null $vat_number
 * @property bool $reverse_charge
 * @property string|null $coupon_code
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, OrderStatusChange> $statusChanges
 * @property-read Model|null $customer
 * @property-read Model|null $team
 */
class Order extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_orders_orders';

    protected $fillable = [
        'number', 'team_id', 'store_id', 'customer_id', 'email',
        'source', 'placement_key', 'placement_hash', 'checkout_session_id',
        'currency', 'currency_exponent', 'subtotal_minor', 'discount_minor',
        'net_minor', 'tax_minor', 'grand_total_minor',
        'shipping_address', 'billing_address',
        'recipient_name', 'recipient_email', 'gift_message',
        'billing_country', 'vat_number', 'reverse_charge', 'coupon_code',
        'placed_at',
    ];

    /*
     * Restated here as well as in the migration. `create()` does not read a
     * column default back, so a model built through Eloquent holds null for
     * anything whose default lives only in the schema — and a null `status` cast
     * to an enum is a fatal, not a fallback.
     */
    protected $attributes = [
        'status' => 'pending',
        'source' => 'checkout',
        'currency_exponent' => 2,
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'net_minor' => 0,
        'tax_minor' => 0,
        'grand_total_minor' => 0,
        'reverse_charge' => false,
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'currency_exponent' => 'integer',
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'net_minor' => 'integer',
        'tax_minor' => 'integer',
        'grand_total_minor' => 'integer',
        'checkout_session_id' => 'integer',
        'reverse_charge' => 'boolean',
        'placed_at' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    /**
     * A public reference that is not an incrementing id.
     *
     * An id in a customer-facing URL or a support email is an enumeration of
     * everybody's orders — the same argument that gave a checkout session its
     * token. Forty-eight bits from the CSPRNG, rendered as hex: no `I`, `L`, `O`
     * or `U` to be misheard, because this number gets read down a telephone.
     *
     * Uniqueness is still the index's job, not this function's. A collision is
     * a `QueryException` on insert, which is loud and rare, rather than a second
     * order quietly filed under somebody else's number.
     */
    public static function generateNumber(): string
    {
        return 'ORD-'.strtoupper(bin2hex(random_bytes(6)));
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<OrderStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /**
     * The shopper, if the host has told this package where its customers live.
     *
     * Opt-in and resolved from configuration at call time, never imported. A
     * package that names another package's class in a `use` statement has
     * quietly acquired a dependency on it, and `customer_id` alone is enough for
     * every rule this module enforces — the relation exists so a panel can show a
     * name instead of a number.
     *
     * Throws rather than guessing. A `belongsTo` against a guessed class name
     * fails at query time with a message about a missing table, which is a much
     * worse place to find out.
     *
     * @return BelongsTo<Model, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->relateToConfigured('orders.customer_model', 'customer_id', 'customer');
    }

    /**
     * The owning team, resolved the same way and for the same reason.
     *
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        return $this->relateToConfigured('orders.team_model', 'team_id', 'team');
    }

    /** Whether a downstream module may act on this order's lines. */
    public function isOpenForWork(): bool
    {
        return $this->status->isOpenForWork();
    }

    /**
     * Whether cancelling the whole order is still honest.
     *
     * Two conditions, and the second is the Orders/Returns line: a terminal
     * order has nothing to call off, and an order with anything already
     * fulfilled has goods in the world. Getting those back is a **return**, not
     * a status change.
     */
    public function isCancellable(): bool
    {
        return ! $this->status->isTerminal()
            && $this->lines->every(fn (OrderLine $line): bool => $line->fulfilled_quantity === 0);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpenForWork(Builder $query): void
    {
        $query->where('status', OrderStatus::Confirmed->value);
    }

    /**
     * Orders still owed for, older than a moment — what a payment-chase sweep
     * reads.
     *
     * The comparison is bound and the status is a value, never
     * `where('placed_at', null)`: that compiles to `is null` and would return
     * every order that has somehow never been stamped rather than none.
     *
     * @param  Builder<self>  $query
     */
    public function scopePendingSince(Builder $query, DateTimeInterface $since): void
    {
        $query->where('status', OrderStatus::Pending->value)->where('placed_at', '<', $since);
    }

    protected static function newFactory(): Factory
    {
        return OrderFactory::new();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    private function relateToConfigured(string $setting, string $foreignKey, string $what): BelongsTo
    {
        $model = config($setting);

        if (! is_string($model) || $model === '') {
            throw new RuntimeException("No {$what} model is configured. Set `{$setting}` before loading the `{$what}` relation.");
        }

        return $this->belongsTo($model, $foreignKey);
    }
}
