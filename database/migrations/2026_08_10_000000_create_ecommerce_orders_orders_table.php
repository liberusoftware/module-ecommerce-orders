<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order: what a shopper committed to, made durable.
 *
 * Every table this module owns is invented here, so every one carries the
 * `ecommerce_orders_` prefix (MODULE_DEVELOPMENT.md §1.5). There is **no
 * bare-name exception**: the host's `orders` table is not this module's to keep.
 * It has accreted columns from at least three other domains across ten
 * migrations, and adopting it would adopt them. `docs/adoption.md` says what the
 * host does with its own.
 *
 * The awkward name is deliberate rather than clever. The prefix rule is
 * mechanical, `SchemaTest` asserts it mechanically, and `ecommerce_orders` (no
 * trailing underscore) would be the one table in the fleet that has to be
 * special-cased in the check that keeps two modules from claiming a name.
 *
 * **`placement_key` and `source` are the idempotency guarantee**, and the
 * guarantee is the unique index on the pair — not a `select` before an `insert`,
 * which has a window between the two that a redelivered queue job will
 * eventually land in. See `Actions\PlaceOrder`.
 *
 * **`checkout_session_id` is indexed and deliberately *not* unique.** One
 * checkout splitting into several orders — different warehouses, a pre-order
 * alongside stock — is a real thing, and a unique index here would make it a
 * migration rather than a rule.
 *
 * Every money column is an integer count of minor units, named `*_minor` so a
 * `decimal` slipping back in is visible in a diff. The host's
 * `change_orders_total_amount_to_decimal` went the other way; `SchemaTest`
 * asserts no column anywhere in this module is decimal, float or numeric.
 *
 * `team_id`, `store_id` and `customer_id` are plain indexed columns with no
 * foreign key: teams and customers belong to the host application, stores to
 * `ecommerce-commerce-core`, and a package that constrains a table it does not
 * own cannot be installed without it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_orders_orders', function (Blueprint $table) {
            $table->id();
            // The public reference. An incrementing id in a customer-facing URL
            // or a support email is an enumeration of everybody's orders, and
            // this is the same argument that gave a checkout session its token.
            $table->string('number', 32)->unique();
            $table->foreignId('team_id')->nullable()->index();
            $table->foreignId('store_id')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('email')->nullable();

            // Where this order came from, and the caller's key for it. Unique
            // together: a redelivered `CheckoutCompleted` carries the same
            // idempotency key, loses at this index, and replays the order that
            // already exists instead of writing a second one.
            $table->string('source')->default('checkout');
            $table->string('placement_key', 191);
            // SHA-256 of the canonical placement, hex. What separates a
            // redelivery (same key, same facts — replay) from a client that
            // reused a key for a different order (same key, different facts —
            // a permanent conflict, and its own exception class).
            $table->string('placement_hash', 64);
            // Kept for provenance and for operators reading this table without
            // joining anything. Not unique — see the class docblock.
            $table->unsignedBigInteger('checkout_session_id')->nullable()->index();

            $table->string('status')->default('pending')->index();

            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);

            // The addresses the customer gave. An **order** fact: it is what
            // they agreed to and what the invoice cites, and it must not change
            // when a shipment is rerouted. A shipment's destination is
            // Fulfillment's own copy, taken from here at the moment it ships.
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            // Who the parcel is addressed to, when that is not the buyer. A gift
            // is an order fact — the buyer chose it — not a carrier's business.
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('gift_message')->nullable();

            // Tax evidence, never tax logic. `billing_country` is the place of
            // supply the order was priced against; it also lives inside
            // `billing_address`, and is duplicated here as its own indexed
            // column because an OSS return groups by it and no portable index
            // reaches into JSON. `vat_number` and `reverse_charge` are what the
            // customer asserted — the reason the tax input was what it was.
            // Nothing here validates a number or looks a rate up.
            $table->string('billing_country', 2)->nullable()->index();
            $table->string('vat_number')->nullable();
            $table->boolean('reverse_charge')->default(false);
            // The code the customer typed, kept as a label. No validation, no
            // lookup, no Promotions dependency: the amount arrived already
            // decided and this is only what to print next to it.
            $table->string('coupon_code')->nullable();

            $table->timestamp('placed_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'placement_key']);
            // The two reads a staff surface actually performs.
            $table->index(['team_id', 'status']);
            $table->index(['customer_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_orders_orders');
    }
};
