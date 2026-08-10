<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Orders\Actions\AccountForLine;
use Liberu\Ecommerce\Orders\Actions\PlaceOrder;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Models\Order;
use Liberu\PackageTestbench\TestUser;

/**
 * An actor working in a team, the way a team switcher leaves them.
 *
 * Team ids here start at 9,000,00x so they cannot collide with anything
 * `TestUser::factory()` mints. A fixture id that collides makes an authorization
 * test pass for the wrong reason — a "stranger's" record quietly becomes the
 * actor's own — and that failure mode is invisible in a green suite.
 */
function actorInTeam(?int $teamId): TestUser
{
    $user = TestUser::factory()->create();
    $user->current_team_id = $teamId;

    return $user;
}

it('registers a policy at all, because an unpolicied model is exposed and not safe', function () {
    // Laravel's unanswered gate case is permissive, and this fleet has shipped
    // that leak three times. An order holds an email address, two postal
    // addresses, a VAT number and a gift message somebody wrote to a named
    // person.
    expect(Gate::getPolicyFor(Order::class))->not->toBeNull();
});

it('lets a merchant read and work on their own order', function () {
    $actor = actorInTeam(9000001);
    $order = Order::factory()->ownedBy(9000001)->create();

    expect($actor->can('viewAny', Order::class))->toBeTrue()
        ->and($actor->can('view', $order))->toBeTrue()
        ->and($actor->can('transition', $order))->toBeTrue()
        ->and($actor->can('cancel', $order))->toBeTrue();
});

it('refuses another merchant s order outright', function () {
    $actor = actorInTeam(9000001);
    $theirs = Order::factory()->ownedBy(9000002)->create();

    expect($actor->can('view', $theirs))->toBeFalse()
        ->and($actor->can('transition', $theirs))->toBeFalse()
        ->and($actor->can('cancel', $theirs))->toBeFalse();
});

it('refuses an order belonging to nobody, so an orphan cannot be quietly claimed', function () {
    $actor = actorInTeam(9000001);
    $orphan = Order::factory()->create(['team_id' => null]);

    expect($actor->can('view', $orphan))->toBeFalse()
        ->and($actor->can('transition', $orphan))->toBeFalse();
});

it('refuses an actor with no team at all', function () {
    // `null === null` would be a leak written as a tautology.
    $actor = actorInTeam(null);
    $order = Order::factory()->ownedBy(9000001)->create();

    expect($actor->can('viewAny', Order::class))->toBeFalse()
        ->and($actor->can('view', $order))->toBeFalse();
});

it('never lets staff create, edit or delete an order through a panel', function (string $ability) {
    // Answered **by name**, including the ones that are always false. A policy
    // present but silent on an ability is the sharper version of no policy at
    // all: Filament's `get_authorization_response()` returns *allow* when a
    // present policy has no method for the ability asked about, and the file
    // existing makes it look like a control.
    $actor = actorInTeam(9000001);
    $order = Order::factory()->ownedBy(9000001)->create();

    expect($actor->can($ability, $order))->toBeFalse();
})->with(['update', 'delete', 'restore', 'forceDelete']);

it('never lets staff place an order from a form', function () {
    // An order is *placed*, from a placement, under a key the caller supplies. A
    // button that mints a fresh key per press writes a second order on a double
    // click, which is the whole thing `PlaceOrder` exists to prevent.
    $actor = actorInTeam(9000001);

    expect($actor->can('create', Order::class))->toBeFalse();
});

it('will not let a finished order be transitioned or cancelled again', function (string $state) {
    $actor = actorInTeam(9000001);
    $order = Order::factory()->ownedBy(9000001)->{$state}()->create();

    expect($actor->can('transition', $order))->toBeFalse()
        ->and($actor->can('cancel', $order))->toBeFalse();
})->with(['completed', 'cancelled']);

it('will not let staff cancel round the Returns boundary, even holding the ability', function () {
    // The policy asks the domain. A staff member entitled to cancel still cannot
    // cancel an order something has shipped from, because that is a return.
    $actor = actorInTeam(9000001);

    $placed = (new PlaceOrder())->handle(placement(teamId: 9000001));
    $order = Order::query()->findOrFail($placed->order->id);

    expect($actor->can('cancel', $order))->toBeTrue();

    (new AccountForLine())->handle($order->lines[0], LineAccount::Fulfilled, 1);

    expect($actor->can('cancel', $order->fresh()))->toBeFalse();
});
