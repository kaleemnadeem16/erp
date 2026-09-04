<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Support\RecalculationRiskClassification;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;

/**
 * ADR 0040 / docs/domain/stock-recalculation.md "Review, staleness and risk
 * routing" — classifies one impact version as NORMAL_RISK or HIGH_RISK.
 * Called once per version (initial and every replacement), never re-evaluated
 * afterwards: risk_classification is frozen at creation the same as every
 * other economic column on RecalculationImpactVersion.
 *
 * Two of the ADR's named hard triggers are implemented here, both readable
 * from state that already exists in this codebase:
 *
 *  - Closed-period effects: the version's economic impact runs from the
 *    proposed valuation-effective date forward to now — every later movement
 *    is re-averaged. If any AccountingPeriod overlapping that window is
 *    CLOSED, the version is HIGH_RISK. This is deliberately independent of
 *    BackdatingHorizonEvaluator: PERIOD_LOCK_ONLY and
 *    CURRENT_AND_PREVIOUS_PERIOD already refuse a proposed date that falls in
 *    or before a closed period, so this trigger can never fire under those
 *    modes — it only ever matters under POSTING_FLOOR, which doesn't consult
 *    period status at all.
 *  - Optional company threshold: recalculation_high_risk_threshold_base_amount,
 *    when set, marks HIGH_RISK whenever the absolute valuation adjustment (in
 *    base/functional currency, per docs) meets or exceeds it. Additive only,
 *    per the ADR's "may strengthen, never disable, hard routing" — there is
 *    no company setting that can turn a hard trigger off.
 *
 * Two other named hard triggers are not implemented — there is nothing in
 * this codebase yet for them to observe, the same "not reachable yet" honesty
 * STATUS.md already applies to the regeneration cap:
 *
 *  - "Qualifying finalised downstream effects" (e.g. an already-invoiced sale
 *    or a closed job cost consuming the affected stock) — no invoicing or
 *    job-costing model exists yet (Phase 4/5/6).
 *  - "Controlled ordinary-tier self-review" — DecideRecalculationApproval
 *    enforces requester/approver separation absolutely; the small-company
 *    self-review exception that would make this trigger reachable is not
 *    built, so self-review of a Q14 impact cannot currently happen at all.
 */
final class RecalculationRiskClassifier
{
    public function classify(Company $company, CarbonInterface $movedAt, BigDecimal $valuationAdjustment): RecalculationRiskClassification
    {
        if ($this->touchesClosedPeriod($company, $movedAt)) {
            return RecalculationRiskClassification::HighRisk;
        }

        $threshold = $company->recalculation_high_risk_threshold_base_amount;
        if ($threshold !== null && $valuationAdjustment->abs()->isGreaterThanOrEqualTo(BigDecimal::of($threshold))) {
            return RecalculationRiskClassification::HighRisk;
        }

        return RecalculationRiskClassification::NormalRisk;
    }

    private function touchesClosedPeriod(Company $company, CarbonInterface $movedAt): bool
    {
        return AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('status', PeriodStatus::Closed)
            ->whereDate('ends_on', '>=', $movedAt)
            ->exists();
    }
}
