<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\AssemblyComponent;
use App\Modules\Inventory\Support\Costing\CostResolution;
use Brick\Math\BigDecimal;

final readonly class AssemblyComponentBreakdown
{
    public function __construct(public AssemblyComponent $component, public BigDecimal $resolvedQuantity, public CostResolution $cost, public string $costSource) {}
}
