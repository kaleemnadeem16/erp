<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * What a band measures. Quantity, percentage and cost are not interchangeable and keep
 * separate thresholds. Waste/residual is intentionally absent: it needs package-level tracking
 * (ADR 0052) and is tracked in the Phase 3 deferred list.
 */
enum VarianceMeasure: string
{
    case Quantity = 'QUANTITY';
    case Percent = 'PERCENT';
    case CostAmount = 'COST_AMOUNT';
    case CostPercent = 'COST_PERCENT';
}
