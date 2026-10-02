<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\MaterialVariancePolicy;

final class MaterialVariancePolicyResolution
{
    public function __construct(
        public readonly ?MaterialVariancePolicy $policy,
        public readonly VarianceResolutionMethod $method,
    ) {}
}
