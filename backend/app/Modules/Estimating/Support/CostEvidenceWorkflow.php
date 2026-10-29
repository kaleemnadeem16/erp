<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * The explicitly authorised workflows that may ask Estimating for cost evidence without holding `estimating.internal_cost.view`. A caller names its workflow; there
 * is no "any" and no free text, so adding a consumer is a deliberate change here (and in `CostEvidenceSeamHasOneCallerTest`), never a permission to grant.
 */
enum CostEvidenceWorkflow: string
{
    /** The Sales cost-blind evaluator judging a quotation line's FR4 final-net controls (#29b): the cost never leaves that trusted server-side evaluation. */
    case QuotationControlEvaluation = 'QUOTATION_CONTROL_EVALUATION';
}
