<?php

declare(strict_types=1);

namespace App\Support\Quantity;

use App\Exceptions\DomainInvariantException;

/**
 * The stable reason codes of the shared quantity contract (ADR 0142). Every refusal is a DomainInvariantException, so an HTTP caller sees a 422 with the code,
 * never an uncaught arithmetic exception, and no quantity is ever rounded to make it fit.
 */
final class QuantityRefusal
{
    /** Not an exact decimal number (letters, exponent form, empty, a float). */
    public const QUANTITY_INVALID = 'QUANTITY_INVALID';

    public const QUANTITY_NEGATIVE = 'QUANTITY_NEGATIVE';

    public const QUANTITY_ZERO = 'QUANTITY_ZERO';

    public const QUANTITY_OVERFLOW = 'QUANTITY_OVERFLOW';

    /** More fractional digits than the destination holds, or a converted result that is not exact at the destination scale. */
    public const QUANTITY_NOT_REPRESENTABLE = 'QUANTITY_NOT_REPRESENTABLE';

    /** Representable, but not a multiple of the permitted increment. */
    public const QUANTITY_INCREMENT_VIOLATED = 'QUANTITY_INCREMENT_VIOLATED';

    /** An increment that is not a positive decimal. */
    public const INCREMENT_INVALID = 'INCREMENT_INVALID';

    /** An increment finer than the destination scale could ever reach. */
    public const INCREMENT_NOT_REPRESENTABLE = 'INCREMENT_NOT_REPRESENTABLE';

    /** A transaction-unit increment whose stock-unit equivalent is not a whole multiple of the stock increment. */
    public const INCREMENT_INCOMPATIBLE = 'INCREMENT_INCOMPATIBLE';

    public const FACTOR_INVALID = 'FACTOR_INVALID';

    /** A factor with more than nine fractional digits, or beyond its integer range. */
    public const FACTOR_NOT_REPRESENTABLE = 'FACTOR_NOT_REPRESENTABLE';

    /** The contract itself is misconfigured (an unsupported scale). A programming error, surfaced loudly. */
    public const CONTRACT_INVALID = 'CONTRACT_INVALID';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
