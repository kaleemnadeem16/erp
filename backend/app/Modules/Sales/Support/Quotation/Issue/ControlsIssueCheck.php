<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/**
 * Whether the company's mandatory final-net controls were evaluated and none blocks (FR4; QL0 record §6: an empty control set is not issue readiness). The real check
 * needs a configured control set and cost evidence for every line's source; until both exist `UnconfiguredControlsIssueCheck` says so. Codes only; an
 * UNEVALUABLE mandatory control is a blocker, never a pass.
 */
interface ControlsIssueCheck
{
    /** @return list<IssueBlocker> */
    public function blockers(SalesActorContext $actor, QuotationRevision $revision): array;
}
