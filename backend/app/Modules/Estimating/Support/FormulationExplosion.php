<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Inventory\Support\Costing\CostResolution;

final readonly class FormulationExplosion
{
    /** @param list<FormulationLineBreakdown> $lines */
    public function __construct(public FormulationVersion $version, public array $lines, public CostResolution $cost) {}
}
