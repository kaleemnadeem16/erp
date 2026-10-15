<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use App\Exceptions\DomainInvariantException;

/** Stable refusal codes of the internal quotation FX resolver (FR1, ADR 0118). A refusal never degrades to a rate of one, a zero or a substitute rate. */
final class FxRefusal
{
    public const FX_RATE_MISSING = 'FX_RATE_MISSING';

    public const FX_RATE_TOO_OLD = 'FX_RATE_TOO_OLD';

    public const FX_RATE_INVALID = 'FX_RATE_INVALID';

    public const FX_ROUTE_UNSUPPORTED = 'FX_ROUTE_UNSUPPORTED';

    public const FX_POLICY_INVALID = 'FX_POLICY_INVALID';

    public const FX_POLICY_MISSING = 'FX_POLICY_MISSING';

    public const FX_POLICY_AMBIGUOUS = 'FX_POLICY_AMBIGUOUS';

    public const FINANCIAL_DATE_INVALID = 'FINANCIAL_DATE_INVALID';

    /** The codes a caller may turn into an UNKNOWN outcome instead of a refusal (see ResolveQuotationFx::attempt). */
    public const RATE_CODES = [self::FX_RATE_MISSING, self::FX_RATE_TOO_OLD, self::FX_RATE_INVALID];

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
