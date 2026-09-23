<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\FormulationLine;
use Brick\Math\BigDecimal;

final readonly class FormulationLineBreakdown
{
    public function __construct(
        public FormulationLine $line,
        public BigDecimal $unitCost,
        public BigDecimal $lineCost,
        public ?FormulationExplosion $subExplosion,
    ) {}
}
