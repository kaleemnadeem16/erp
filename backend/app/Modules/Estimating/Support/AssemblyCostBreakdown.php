<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\OverheadPolicy;
use Brick\Math\BigDecimal;

/**
 * The full five-part cost/unit breakdown for one assembly version — see
 * docs/domain/assemblies.md#cost-roll-up. Kept fully explainable per Step
 * 3's requirements: which version was costed, which overhead scope tier
 * resolved, which driver, what the four direct-cost bases were, the
 * resulting overhead amount, and the final cost/sell per unit — everything
 * a quotation, job-costing or audit screen will eventually need without
 * recomputing anything.
 *
 * materialCost/labourCost/plantCost/subcontractCost/directCost are always
 * computed before overheadAmount, and overheadAmount is never folded back
 * into them — see ExplodeAssemblyVersion, which is the only place these
 * four figures and overheadAmount are ever combined, and does so exactly
 * once, in that order, so overhead can never end up inside its own base.
 */
final readonly class AssemblyCostBreakdown
{
    /** @param list<AssemblyComponentBreakdown> $components */
    public function __construct(
        public AssemblyVersion $version,
        public array $components,
        public BigDecimal $materialCost,
        public BigDecimal $labourCost,
        public BigDecimal $labourHours,
        public BigDecimal $plantCost,
        public BigDecimal $subcontractCost,
        public BigDecimal $directCost,
        public OverheadResolutionMethod $overheadMethod,
        public ?OverheadPolicy $overheadPolicy,
        public BigDecimal $overheadAmount,
        public BigDecimal $costPerUnit,
        public BigDecimal $sellPerUnit,
    ) {}
}
