<?php

namespace Liberu\Ecommerce\Orders\Support;

use Liberu\Ecommerce\Orders\Exceptions\InvalidMoney;

/**
 * Money as an integer count of the currency's smallest unit, and the one
 * conversion this module is allowed to perform.
 *
 * Restated here rather than imported. Orders requires no sibling
 * `liberusoftware/ecommerce-*` package — not Checkout, not Commerce Core — so a
 * shared `Money` helper would be a shared dependency, and the whole wave-5
 * boundary rule is that there is not one. Forty lines duplicated is the price of
 * a module that installs alone.
 *
 * **Parsing is string arithmetic, not multiplication.** `(int) (19.99 * 100)` is
 * `1998`, because `19.99` is not representable in binary floating point and the
 * cast truncates what is left. Splitting on the point, padding the fraction to
 * the currency's exponent and concatenating cannot lose a penny, because no
 * float is ever constructed. `MoneyTest` pins `19.99 → 1999` with the float
 * result written next to it, so the reason survives the person who found it —
 * and it is the test `docs/adoption.md` points at, because converting the host's
 * `decimal(10,2)` columns is exactly this conversion at scale.
 *
 * A value more precise than the currency is **refused**, not rounded. `"19.995"`
 * in GBP is a rounding decision — half up, half even, towards the merchant — and
 * this module is not entitled to make it silently on somebody's invoice.
 */
final class MinorUnits
{
    /**
     * Parse a decimal string into minor units.
     *
     * The input is a **string** on purpose. A float parameter would let a
     * caller's `19.99` arrive already wrong, and no amount of care in here could
     * recover it.
     */
    public static function fromDecimalString(string $amount, int $exponent = 2): int
    {
        if ($exponent < 0) {
            throw new InvalidMoney("A currency exponent cannot be negative, got {$exponent}.");
        }

        $trimmed = trim($amount);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidMoney("`{$amount}` is not a decimal amount.");
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new InvalidMoney("`{$amount}` carries more precision than an exponent of {$exponent} can hold. Round it before converting, so the rounding is a decision somebody made.");
        }

        $minor = (int) ($matches[2].str_pad($fraction, $exponent, '0'));

        return $matches[1] === '-' ? -$minor : $minor;
    }

    /**
     * Render minor units as a decimal string.
     *
     * A string, never a float, for the same reason the parser takes one: this
     * value is going into JSON and onto an invoice, and `19.99` as a JSON number
     * is a float on the other side of the wire.
     */
    public static function toDecimalString(int $minor, int $exponent = 2): string
    {
        if ($exponent < 0) {
            throw new InvalidMoney("A currency exponent cannot be negative, got {$exponent}.");
        }

        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        if ($exponent === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}
