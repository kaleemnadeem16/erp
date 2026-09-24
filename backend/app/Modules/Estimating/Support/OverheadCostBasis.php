<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * The roll-up figures an overhead driver reads from — see
 * docs/domain/assemblies.md#cost-roll-up. Step 3 (the remaining Assembly
 * slice) is what will populate these from real assembly_components; every
 * field defaults to '0' so a caller previewing a policy in isolation (no
 * assembly to roll up yet) doesn't need to supply figures it doesn't have.
 */
final readonly class OverheadCostBasis
{
    public function __construct(
        public string $materialCost = '0',
        public string $labourCost = '0',
        public string $plantCost = '0',
        public string $subcontractCost = '0',
        public string $labourHours = '0',
        public string $workQty = '0',
    ) {}
}
