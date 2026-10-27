<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Estimating\Actions\ReadAssemblyCostEvidence;
use App\Modules\Estimating\Support\CostEvidenceCertainty;
use App\Modules\Estimating\Support\CostEvidenceFailure;
use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use Brick\Math\BigDecimal;

/**
 * The one Sales consumer of Estimating's cost-evidence seam (#29a), under the single named workflow `QUOTATION_CONTROL_EVALUATION`; the architecture ratchet
 * `CostEvidenceSeamHasOneCallerTest` pins that. Everything it returns is certainty plus, inside the trusted evaluation, the figures — and whatever cannot be
 * established is UNKNOWN with a fixed code, never zero.
 */
final class EstimatingAssemblyCostEvidence implements AssemblyCostEvidenceProvider
{
    public function __construct(private readonly ReadAssemblyCostEvidence $evidence) {}

    public function costFor(SalesActorContext $actor, int $assemblyVersionId, BigDecimal $quantity): FinancialResult
    {
        $workflow = CostEvidenceWorkflow::QuotationControlEvaluation;
        try {
            $evidence = ($this->evidence)($assemblyVersionId, (string) $quantity, $workflow, $actor->actor);
        } catch (CostEvidenceFailure) {
            return FinancialResult::unknown([['code' => 'COST_EVIDENCE_UNAVAILABLE']]);
        }
        $amounts = $evidence->amounts($workflow);
        if ($amounts === null || $amounts->currencyId() === null) {
            return FinancialResult::unknown([['code' => $evidence->reasonCode ?? 'COST_EVIDENCE_UNAVAILABLE']]);
        }

        return match ($evidence->certainty) {
            CostEvidenceCertainty::Complete => $amounts->total() === null
                ? FinancialResult::unknown([['code' => 'COST_EVIDENCE_UNAVAILABLE']])
                : FinancialResult::complete($amounts->total(), $amounts->currencyId()),
            CostEvidenceCertainty::Partial => $amounts->known() === null
                ? FinancialResult::unknown([['code' => 'COST_EVIDENCE_UNAVAILABLE']])
                : FinancialResult::partial($amounts->known(), $amounts->currencyId(), [['code' => $evidence->reasonCode ?? 'COST_PARTIAL']]),
            CostEvidenceCertainty::Unknown => FinancialResult::unknown([['code' => $evidence->reasonCode ?? 'COST_EVIDENCE_UNAVAILABLE']]),
        };
    }
}
