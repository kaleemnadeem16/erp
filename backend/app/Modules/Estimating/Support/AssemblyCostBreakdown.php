<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Inventory\Support\Costing\CostResolution;
use Brick\Math\BigDecimal;

final readonly class AssemblyCostBreakdown
{
    /** @param list<AssemblyComponentBreakdown> $components
     * @param  array<string, CostResolution>  $costByKind
     */
    public function __construct(
        public AssemblyVersion $version,
        public array $components,
        public CostResolution $cost,
        public array $costByKind,
        public BigDecimal $labourHours,
        public CostResolution $directCost,
        public OverheadResolutionMethod $overheadMethod,
        public ?OverheadPolicy $overheadPolicy,
        public CostResolution $overheadCost,
        public ?BigDecimal $sellPerUnit,
    ) {}
}
