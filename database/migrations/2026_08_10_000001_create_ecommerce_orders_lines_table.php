<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **The published contract.** Fulfillment (#859) and Returns (#906) are both
 * definitionally about order lines, so this row is not an internal detail — it is
 * the thing two later modules hold identifiers into.
 *
 * `id` is that identifier: a plain auto-increment, **stable and public**. What
 * makes it stable is a rule rather than a column — *a line is never deleted and
 * never replaced*. A cancelled line stays, with its `cancelled_quantity` raised
 * to meet its `quantity`; there is no operation in this module that removes a
 * row from this table or swaps one line for another. A downstream module holding
 * `order_line_id = 4711` may rely on 4711 continuing to mean the same line of
 * the same order forever.
 *
 * **The money here is a snapshot and is never recomputed.** It is what the
 * customer agreed to. No live catalogue, no pricing module, no re-read: a
 * product renamed, re-priced or deleted three months later does not change what
 * somebody was charged. `name` and `sku` are copied for that reason, and
 * `product_id` / `variant_id` are plain nullable columns with no foreign key and
 * no relation — Catalog is not a dependency of this package.
 *
 * **Three accounting counters, and they answer three different questions.**
 *
 *   fulfilled_quantity  — shipped or delivered.        Written by Fulfillment.
 *   cancelled_quantity  — called off before shipping.  Written by Orders.
 *   returned_quantity   — came back after delivery.    Written by Returns.
 *
 * They are counters and not a status because a line of five can be three shipped,
 * one cancelled and one still outstanding at the same moment, and a single status
 * column cannot say that. Two invariants hold, enforced in
 * `Actions\AccountForLine` and asserted by tests:
 *
 *   fulfilled + cancelled ≤ quantity
 *   returned ≤ fulfilled
 *
 * The second is where the Orders/Returns boundary lives: you cannot return what
 * was never shipped. Calling that off is a cancellation, and it is Orders'.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_orders_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ecommerce_orders_orders')->cascadeOnDelete();
            // `item`, `shipping` or `fee`. Shipping is a line rather than a
            // column on the order, so discount allocation, the tax rate and the
            // rounding have exactly one implementation instead of two that
            // drift — and so a two-parcel order can carry two shipping lines.
            $table->string('kind')->default('item');
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);

            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('cancelled_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);

            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            // Basis points, so 20% VAT is 2000 and 8.375% is 838 — representable
            // without a float. Null means an amount was handed in instead of a
            // rate, which is equally allowed. Tax is an input either way and
            // nothing in this module looks a rate up or compounds one.
            $table->unsignedInteger('tax_rate_bp')->nullable();
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->boolean('taxable')->default(true);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_orders_lines');
    }
};
