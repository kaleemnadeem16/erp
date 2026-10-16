<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

use App\Exceptions\DomainInvariantException;

/** Stable refusal codes of the Sales rounding and unit-price precision policy (FR4/PX6). */
final class RoundingPolicyRefusal
{
    /** A unit price has more places than the policy in force allows; it is refused, never rounded. */
    public const UNIT_PRICE_PRECISION_EXCEEDED = 'UNIT_PRICE_PRECISION_EXCEEDED';

    public const ROUNDING_POLICY_INVALID = 'ROUNDING_POLICY_INVALID';

    public const ROUNDING_POLICY_NOT_DRAFT = 'ROUNDING_POLICY_NOT_DRAFT';

    /** The effective date is in the past, or not after the version it would replace. */
    public const ROUNDING_POLICY_EFFECTIVE_DATE_INVALID = 'ROUNDING_POLICY_EFFECTIVE_DATE_INVALID';

    /** More than one version claims a date. The database forbids it, so this signals corrupted data, never a tie to break. */
    public const ROUNDING_POLICY_AMBIGUOUS = 'ROUNDING_POLICY_AMBIGUOUS';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
