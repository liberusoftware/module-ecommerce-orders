<?php

namespace Liberu\Ecommerce\Orders\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Ecommerce\Orders\Models\Order;

/**
 * Who, among staff, may act on somebody else's order.
 *
 * Registered rather than left absent, because **a model with no policy is
 * exposed and not safe** — Laravel's unanswered gate case is permissive, and
 * that has produced a live leak three times in this fleet. An order holds an
 * email address, two postal addresses, a VAT number and a gift message somebody
 * wrote to a named person. It is exactly the model you do not want defaulting
 * open.
 *
 * Every ability is answered **by name**, including the ones that are always
 * false. A policy that is present but silent on an ability is the sharper
 * version of no policy at all: Filament's `get_authorization_response()` returns
 * *allow* when a present policy has no method for the ability asked about, and
 * the file existing makes it look like a control.
 *
 * Tenancy is read off the actor, so it answers the same way in a console command,
 * a queued job and an API request. An order belonging to nobody (`team_id` null)
 * is nobody's to edit: visible, so an orphan can be found and fixed, and not
 * writable, so it cannot be quietly claimed.
 *
 * **Three abilities are permanently false, and each is a domain rule rather than
 * a caution.**
 *
 * - `create` — an order is *placed*, from a placement, with an idempotency key
 *   the caller supplies. A button that mints a fresh key per press writes a
 *   second order on a double click, which is the whole thing `PlaceOrder` exists
 *   to prevent. There is no such thing as an order somebody typed into a form.
 * - `update` — the lines are a snapshot of what a customer agreed to. Editing
 *   one is not an edit, it is a different agreement.
 * - `delete` — Fulfillment and Returns hold line ids. Deleting an order deletes
 *   rows another module is pointing at.
 *
 * What staff *can* do is transition and cancel, and those are separate abilities
 * from `view` because they are different-sized mistakes.
 */
class OrderPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $this->teamOf($actor) !== null;
    }

    public function view(Authenticatable $actor, Order $order): bool
    {
        return $this->ownsIt($actor, $order);
    }

    /** Orders are placed, never created. See the class docblock. */
    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    /** A line is a snapshot of an agreement. There is no editing one. */
    public function update(Authenticatable $actor, Order $order): bool
    {
        return false;
    }

    /** Two other modules hold ids into this order's lines. */
    public function delete(Authenticatable $actor, Order $order): bool
    {
        return false;
    }

    public function restore(Authenticatable $actor, Order $order): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $actor, Order $order): bool
    {
        return false;
    }

    /**
     * Move the order along its state machine.
     *
     * Separate from `update` on purpose: correcting nothing and confirming that
     * an order is owed for are different-sized decisions, and a deployment that
     * wants a second pair of eyes on the second one needs somewhere to say so
     * without a breaking change.
     */
    public function transition(Authenticatable $actor, Order $order): bool
    {
        return $this->ownsIt($actor, $order) && ! $order->status->isTerminal();
    }

    /**
     * Call the whole order off.
     *
     * Its own ability, and additionally gated on the domain's own answer:
     * `isCancellable()` is false once anything has shipped, because that is a
     * return and belongs to another module. A staff member with the ability
     * still cannot get round the boundary.
     */
    public function cancel(Authenticatable $actor, Order $order): bool
    {
        return $this->ownsIt($actor, $order) && $order->isCancellable();
    }

    private function ownsIt(Authenticatable $actor, Order $order): bool
    {
        $team = $this->teamOf($actor);

        // Bound comparison, never `null === null`. An orphan order matching an
        // actor with no team is how a leak is written as a tautology.
        return $team !== null && $order->team_id === $team;
    }

    private function teamOf(Authenticatable $actor): ?int
    {
        $team = $actor->getAttribute('current_team_id');

        return is_numeric($team) ? (int) $team : null;
    }
}
