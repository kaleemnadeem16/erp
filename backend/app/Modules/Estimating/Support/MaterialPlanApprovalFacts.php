<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Approvals\Support\ApprovalRouting;

/**
 * How an adopted material plan tells the approval engine what to route (ADR 0054 §7, ADR 0076, ADR 0122). A plan only exists once a document adopts it, and the document's module
 * owns it, so Estimating's approval subjects ask the adopting module through this interface instead of depending on it: Phase 4 Sales binds it. Until something binds it, a plan
 * cannot be submitted (fail closed).
 */
interface MaterialPlanApprovalFacts
{
    /** @param  string  $subjectCode  `MATERIAL_VARIANCE` or `STOCK_MIX`; the id is the adopting module's approval row */
    public function routing(string $subjectCode, int $subjectId): ApprovalRouting;
}
