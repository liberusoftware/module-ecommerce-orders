<?php

namespace Liberu\Ecommerce\Orders\Exceptions;

use InvalidArgumentException;

/**
 * An amount this module will not guess at — a malformed decimal string, a
 * negative exponent, or a value more precise than its currency can hold.
 *
 * Refusing rather than rounding is the point. `"19.995"` in GBP needs a rounding
 * rule, and picking one silently on somebody's invoice is not this module's
 * decision to make.
 */
final class InvalidMoney extends InvalidArgumentException {}
