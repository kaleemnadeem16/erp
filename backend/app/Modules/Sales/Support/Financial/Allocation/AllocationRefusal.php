<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Allocation;

use App\Exceptions\DomainInvariantException;

/** Stable refusal codes of residual allocation (FR4, ADR 0120). An allocation that cannot be done exactly is refused; it never degrades to a rounded or partial answer. */
final class AllocationRefusal
{
    /** The total is not a plain decimal, is beyond storage, or is not a whole number of the currency's quanta (it is not a settled amount). */
    public const ALLOCATION_TOTAL_INVALID = 'ALLOCATION_TOTAL_INVALID';

    /** A component has no key, a repeated key, an invalid weight or cap, or an ineligible component has no reason. */
    public const ALLOCATION_COMPONENT_INVALID = 'ALLOCATION_COMPONENT_INVALID';

    /** There is nothing to allocate across: no components at all, or an amount to place and no eligible component. */
    public const ALLOCATION_NO_ELIGIBLE_COMPONENT = 'ALLOCATION_NO_ELIGIBLE_COMPONENT';

    /** An amount to place and every eligible component weighs zero, so there is no basis to divide by. */
    public const ALLOCATION_NO_WEIGHT = 'ALLOCATION_NO_WEIGHT';

    /** The eligible components, within their caps, cannot hold the whole amount. */
    public const ALLOCATION_CAPACITY_EXCEEDED = 'ALLOCATION_CAPACITY_EXCEEDED';

    /** The result did not conserve the total. Never expected; refused rather than returned. */
    public const ALLOCATION_INCONSISTENT = 'ALLOCATION_INCONSISTENT';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
