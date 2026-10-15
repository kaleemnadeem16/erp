<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

use App\Exceptions\DomainInvariantException;

/** The stable reason codes the internal Sales financial kernel refuses with (FR0). A refusal never degrades to a rounded, zero or partial answer. */
final class FinancialRefusal
{
    public const INVALID_AMOUNT = 'INVALID_AMOUNT';

    public const NEGATIVE_AMOUNT = 'NEGATIVE_AMOUNT';

    public const AMOUNT_OVERFLOW = 'AMOUNT_OVERFLOW';

    public const AMOUNT_NOT_REPRESENTABLE = 'AMOUNT_NOT_REPRESENTABLE';

    public const INVALID_QUANTITY = 'INVALID_QUANTITY';

    public const UNSUPPORTED_CURRENCY_PRECISION = 'UNSUPPORTED_CURRENCY_PRECISION';

    public const UNSUPPORTED_ROUNDING_MODE = 'UNSUPPORTED_ROUNDING_MODE';

    public const CURRENCY_MISMATCH = 'CURRENCY_MISMATCH';

    public const SETTLEMENT_MISMATCH = 'SETTLEMENT_MISMATCH';

    public const EMPTY_SUM = 'EMPTY_SUM';

    public const INCONSISTENT_RESULT = 'INCONSISTENT_RESULT';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
