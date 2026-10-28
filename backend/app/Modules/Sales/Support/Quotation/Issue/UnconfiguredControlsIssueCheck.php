<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/** No control set or cost-evidence source is configured for issue: the controls were not evaluated, which is not a pass. */
final class UnconfiguredControlsIssueCheck implements ControlsIssueCheck
{
    public function blockers(SalesActorContext $actor, QuotationRevision $revision): array
    {
        return [new IssueBlocker('CONTROLS_NOT_CONFIGURED', IssueBlocker::CONTROLS)];
    }
}
