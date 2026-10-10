<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\FormulationLine;
use App\Modules\Inventory\Support\Costing\CostResolution;

final readonly class FormulationLineBreakdown
{
    public function __construct(public FormulationLine $line, public CostResolution $cost, public ?FormulationExplosion $subExplosion) {}
}
