<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Models\User;
use App\Modules\Estimating\Support\CostEvidenceAuthority;
use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Sales' authority for the `QUOTATION_CONTROL_EVALUATION` cost-evidence workflow, registered with Estimating's `CostEvidenceAuthorities`. Cost evidence is opened for a
 * person only if, judged now and from the database, they are an active member of this company AND hold the preparer duty (`sales.quotation.prepare`) through
 * `SalesAccess`: which already refuses a STAGED or disabled duty, a missing permission and any restricted scope (a "my units" limit is refused outright, never read as
 * company-wide). It is not an estimating cost permission and nobody is granted one.
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

        return $current !== null && SalesAccess::may($current, PermissionVocabulary::QUOTATION_PREPARE);
    }
}
