<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Exceptions\DomainInvariantException;

/** Verifies one kind of commercial source in the current company and reads its cost read-only. An adapter refuses; it never fabricates cost or evidence. */
interface PricingSourceAdapter
{
    public function kind(): PricingSourceKind;

    /** @throws DomainInvariantException with a PricingRefusal code when the source is missing, foreign, inactive, incompatible or unsupported */
    public function resolve(PricingInput $input): ResolvedSource;
}
