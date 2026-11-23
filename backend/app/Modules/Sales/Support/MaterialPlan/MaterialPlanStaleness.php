<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationRevision;

/**
 * Ends the approvals of a plan that no longer describes its line (ADR 0054 §6): an approval is given for exactly the facts adopted, so changing the line's version, work quantity
 * or unit — or removing the line — invalidates it. History stays (the engine keeps the request, marked invalidated); the plan itself is never rewritten.
 */
final class MaterialPlanStaleness
{
    public function __construct(private readonly MaterialPlanStanding $standing, private readonly InvalidateApprovals $invalidate) {}

    public function sweep(QuotationRevision $revision): void
    {
        foreach (QuotationDraftLine::query()->where('revision_id', $revision->id)->where('source_kind', 'ASSEMBLY_WORK')->get() as $line) {
            $plan = $this->standing->currentPlan($line->id);
            if ($plan !== null && ! $this->standing->describes($plan, $line)) {
                $this->invalidateApprovals($plan, 'The line was changed after its material plan was approved; adopt the plan again.');
            }
        }
    }

    /** Called before a line is deleted: its plan goes with it, so its approvals must not stay open. */
    public function discard(QuotationDraftLine $line): void
    {
        foreach (QuotationMaterialPlan::query()->where('line_id', $line->id)->get() as $plan) {
            $this->invalidateApprovals($plan, 'The line was removed from the draft.');
        }
    }

    public function invalidateApprovals(QuotationMaterialPlan $plan, string $reason): void
    {
        foreach ($this->standing->approvals($plan) as $row) {
            $this->invalidate->forSubject($row->subject_code, $row->id, $reason);
        }
    }
}
