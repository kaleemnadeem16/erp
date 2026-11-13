<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;

/** Assembly work proven for a quotation: the same source, version, unit and quantity proof as the pricing adapter, with its cost deliberately not read (UNKNOWN, never zero). */
final readonly class CostBlindAssemblyAdapter implements PricingSourceAdapter
{
    public function __construct(private AssemblyWorkSourceAdapter $inner) {}

    public function kind(): PricingSourceKind
    {
        return PricingSourceKind::AssemblyWork;
    }

    public function resolve(PricingInput $input): ResolvedSource
    {
        return $this->inner->identify($input);
    }
}
