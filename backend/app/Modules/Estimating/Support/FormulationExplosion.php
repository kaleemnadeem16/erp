<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\FormulationVersion;
use Brick\Math\BigDecimal;

final readonly class FormulationExplosion
{
    /** @param list<FormulationLineBreakdown> $lines */
    public function __construct(
        public FormulationVersion $version,
        public array $lines,
        public BigDecimal $totalCost,
        public BigDecimal $costPerOutputUnit,
    ) {}
}
