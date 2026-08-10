<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Orders\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for whatever the host calls a customer.
 *
 * The point of the fixture is that this package has never seen the real class.
 * `orders.customer_model` is resolved at call time, so anything with an id
 * works — the host's own `User`, a CRM contact, a `Customer` model this package
 * could not name if it wanted to.
 *
 * @property int $id
 * @property string $name
 */
class FakeCustomer extends Model
{
    protected $table = 'fake_customers';

    protected $fillable = ['name'];
}
