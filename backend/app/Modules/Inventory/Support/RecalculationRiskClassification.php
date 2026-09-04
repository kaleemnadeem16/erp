<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * ADR 0040 / docs/domain/stock-recalculation.md "Review, staleness and risk
 * routing" — every RecalculationImpactVersion is independently NORMAL_RISK or
 * HIGH_RISK. HIGH_RISK requires a second independent approver
 * (RecalculationRiskClassifier decides which; DecideRecalculationApproval
 * enforces the approver count).
 */
enum RecalculationRiskClassification: string
{
    case NormalRisk = 'NORMAL_RISK';
    case HighRisk = 'HIGH_RISK';

    public function requiredApprovalCount(): int
    {
        return $this === self::HighRisk ? 2 : 1;
    }
}
