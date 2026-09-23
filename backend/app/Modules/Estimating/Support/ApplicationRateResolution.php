<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\ApplicationRate;

final readonly class ApplicationRateResolution
{
    public function __construct(
        public ApplicationRate $rate,
        public ResolutionMethod $method,
    ) {}
}
