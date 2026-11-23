<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationMaterialPlanApproval;
use App\Modules\Sales\Models\QuotationMaterialPlanRevalidation;
use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Support\Collection;

/**
 * Where a quotation Assembly line's Material Plan stands (ADR 0190), from stored facts only: no evaluation and no write. `READY` means the latest adoption still describes the
 * line (same pinned version, work quantity and unit), no deliberate re-check found it changed, and every approval it needs stands for exactly its routing facts. A plan that was
 * never adopted is `NONE` — a line may be priced without one. Whether the world has moved since (policy, stock, cost) is judged by evaluating again; this only reads what is recorded.
 */
class MaterialPlanStanding
{
    public const NONE = 'NONE';

    public const READY = 'READY';

    public const STALE = 'STALE';

    public const APPROVAL_NOT_REQUESTED = 'APPROVAL_NOT_REQUESTED';

    public const APPROVAL_PENDING = 'APPROVAL_PENDING';

    public const APPROVAL_REJECTED = 'APPROVAL_REJECTED';

    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    /** The line's latest adoption: the plan nothing supersedes. */
    public function currentPlan(int $lineId): ?QuotationMaterialPlan
    {
        return QuotationMaterialPlan::query()->where('line_id', $lineId)->orderByDesc('id')->first();
    }

    /** The line is still what the plan was adopted for, and so is the recipient (and the customer account the recipient resolves to). */
    public function describes(QuotationMaterialPlan $plan, QuotationDraftLine $line): bool
    {
        return $this->changedSince($plan, $line) === null;
    }

    /** @return string|null `LINE_CHANGED`, `RECIPIENT_CHANGED`, or null when nothing the plan was adopted for has moved */
    public function changedSince(QuotationMaterialPlan $plan, QuotationDraftLine $line): ?string
    {
        if ((int) $plan->assembly_version_id !== (int) $line->assembly_version_id || (string) $plan->work_quantity !== (string) $line->offered_quantity
            || (int) $plan->work_uom_id !== (int) $line->offered_uom_id) {
            return 'LINE_CHANGED';
        }
        $revision = QuotationRevision::query()->findOrFail($plan->revision_id);
        if ((string) $plan->recipient_party_id !== (string) $revision->recipient_party_id || (string) $plan->customer_id !== (string) MaterialPlanRecipient::customerFor($revision)) {
            return 'RECIPIENT_CHANGED';
        }

        return null;
    }

    /** @return array{state: string, plan: QuotationMaterialPlan|null} */
    public function forLine(QuotationDraftLine $line): array
    {
        $plan = $this->currentPlan($line->id);
        if ($plan === null) {
            return ['state' => self::NONE, 'plan' => null];
        }
        $latest = QuotationMaterialPlanRevalidation::query()->where('plan_id', $plan->id)->orderByDesc('id')->first();
        if (! $this->describes($plan, $line) || $latest?->outcome === 'CHANGED') {
            return ['state' => self::STALE, 'plan' => $plan];
        }
        // The state that needs the most from the seller wins: a rejection, then a request not yet made, then one still pending.
        $order = [self::APPROVAL_REJECTED, self::APPROVAL_NOT_REQUESTED, self::APPROVAL_PENDING];
        $states = $this->approvals($plan)->map(fn (QuotationMaterialPlanApproval $row): string => $this->approvalState($row))->all();
        $worst = self::READY;
        foreach ($order as $candidate) {
            if (in_array($candidate, $states, true)) {
                $worst = $candidate;

                break;
            }
        }

        return ['state' => $worst, 'plan' => $plan];
    }

    /** @return Collection<int, QuotationMaterialPlanApproval> */
    public function approvals(QuotationMaterialPlan $plan): Collection
    {
        return QuotationMaterialPlanApproval::query()->where('plan_id', $plan->id)->orderBy('id')->get();
    }

    public function latestRequest(QuotationMaterialPlanApproval $row): ?ApprovalRequest
    {
        return ApprovalRequest::query()->where('subject_type', $row->subject_code)->where('subject_id', $row->id)->orderByDesc('id')->first();
    }

    /** READY when an approval for exactly these routing facts is granted; otherwise why not. */
    public function approvalState(QuotationMaterialPlanApproval $row): string
    {
        $request = $this->latestRequest($row);
        if ($request === null || ! hash_equals($request->facts_hash, $this->subjects->get($row->subject_code)->routing($row->id)->hash())) {
            return self::APPROVAL_NOT_REQUESTED;
        }

        return match ($request->status) {
            ApprovalRequestStatus::Approved => self::READY,
            ApprovalRequestStatus::Pending => self::APPROVAL_PENDING,
            ApprovalRequestStatus::Rejected => self::APPROVAL_REJECTED,
            default => self::APPROVAL_NOT_REQUESTED,
        };
    }
}
