<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Models\User;
use App\Modules\Estimating\Support\CostEvidenceAuthority;
use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sales' authority for the `QUOTATION_CONTROL_EVALUATION` cost-evidence workflow, registered with Estimating's `CostEvidenceAuthorities`. Cost evidence is opened for a
 * person only if, judged now and from the database, they are an active member of this company AND hold the preparer duty (`sales.quotation.prepare`) with a
 * scope the quotation-family policy supports (QuotationAccess: OWN or ALL). A STAGED or disabled duty, a missing permission and any other restricted scope (a "my units"
 * limit) are refused, never read as company-wide. The exact revision is checked by the one caller, EvaluateAssemblyLineControls. It is not an estimating cost permission and nobody is granted one.
 */
final class QuotationControlEvaluationAuthority implements CostEvidenceAuthority
{
    public function workflow(): CostEvidenceWorkflow
    {
        return CostEvidenceWorkflow::QuotationControlEvaluation;
    }

    public function permits(User $actor): bool
    {
        $current = ActiveActor::current($actor);
        if ($current === null) {
            return false;
        }
        // The preparer duty through the shared quotation-family policy (QA3): an OWN or ALL grant reaches the workflow; a restricted or unsupported scope, a STAGED
        // duty or a missing grant resolves to none and is refused. The revision itself is judged by EvaluateAssemblyLineControls before any evidence is read.
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($current, [PermissionVocabulary::QUOTATION_PREPARE]));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
