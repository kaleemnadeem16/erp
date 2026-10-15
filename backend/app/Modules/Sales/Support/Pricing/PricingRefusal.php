<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Exceptions\DomainInvariantException;

/** Stable refusal codes of the internal pricing kernel (PR0). Names follow the target codes in sales-pricing-resolution.md where one exists. */
final class PricingRefusal
{
    public const NO_ELIGIBLE_PRICE = 'NO_ELIGIBLE_PRICE';

    public const PRICING_RULE_CONFLICT = 'PRICING_RULE_CONFLICT';

    public const PRICE_BASIS_INCOMPATIBLE = 'PRICE_BASIS_INCOMPATIBLE';

    public const SOURCE_KIND_UNSUPPORTED = 'SOURCE_KIND_UNSUPPORTED';

    public const SOURCE_NOT_FOUND = 'SOURCE_NOT_FOUND';

    public const SOURCE_INCOMPATIBLE = 'SOURCE_INCOMPATIBLE';

    public const SELLING_UNIT_UNSUPPORTED = 'SELLING_UNIT_UNSUPPORTED';

    public const SOURCE_EVIDENCE_NOT_AUTHORISED = 'SOURCE_EVIDENCE_NOT_AUTHORISED';

    public const PARTY_NOT_FOUND = 'PARTY_NOT_FOUND';

    public const INVALID_INPUT = 'INVALID_INPUT';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}
