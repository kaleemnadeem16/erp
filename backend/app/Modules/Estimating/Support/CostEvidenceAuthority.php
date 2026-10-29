<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Models\User;

/**
 * The authority of ONE cost-evidence workflow, supplied by the module that owns that workflow (never by Estimating, so Estimating depends on no consumer). It answers,
 * for this person, right now, whether the workflow's own duty and scope allow them to use cost evidence. The seam asks it before it reads any cost, on every call —
 * naming a workflow is not authority, the owning workflow's verdict is.
 */
interface CostEvidenceAuthority
{
    public function workflow(): CostEvidenceWorkflow;

    /**
     * A fresh verdict. Implementations must not trust the hydrated `$actor`: re-read the person (still an active member of this company?) and judge the workflow's
     * own duty and its record scope, failing closed on anything unsupported (a restricted scope, a disabled duty).
     */
    public function permits(User $actor): bool;
}
