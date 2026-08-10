<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every move the state machine made, append-only.
 *
 * A status column says where an order is; this says how it got there and when,
 * which is the question actually asked — by a customer wanting to know when
 * their order was confirmed, and by whoever is working out why an order was
 * cancelled at 03:00. A state machine that cannot say when it moved is a state
 * machine nobody can audit.
 *
 * `from_status` is nullable for exactly one row per order: the placement, which
 * came from nowhere.
 *
 * `actor_id` is a plain nullable column with no foreign key — the actor is the
 * host's user model, or nobody at all when a queue moved the order. `reason` is
 * a **short slug**, not free text, and the domain event logger copies it: a text
 * box next to an event logger is where a customer's email address gets typed
 * into a log line. Nothing in this module writes prose to a log.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_orders_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('ecommerce_orders_orders')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('reason', 64)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_orders_status_changes');
    }
};
