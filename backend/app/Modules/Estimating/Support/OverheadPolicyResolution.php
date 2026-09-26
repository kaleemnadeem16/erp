<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\OverheadPolicy;

final readonly class OverheadPolicyResolution
{
    public function __construct(
        public ?OverheadPolicy $policy,
        public OverheadResolutionMethod $method,
    ) {}
}
