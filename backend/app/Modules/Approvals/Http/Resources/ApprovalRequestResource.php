<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalRequest */
class ApprovalRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApprovalRequest $row */
        $row = $this->resource;
        $row->loadMissing(['steps.policyStep', 'steps.decisions.decider', 'policy', 'submitter']);
        $actionable = $request->user() !== null && $row->isOpen() ? app(ApprovalInbox::class)->actionableStep($request->user(), $row) : null;

        return [
            'id' => $row->id, 'subject_type' => $row->subject_type, 'subject_id' => $row->subject_id,
            'status' => $row->status->value, 'summary' => $row->summary,
            'policy' => ['id' => $row->policy_id, 'code' => $row->policy->code, 'version_no' => $row->policy->version_no, 'name' => $row->policy->name],
            'submitted_by' => ['id' => $row->submitted_by, 'name' => $row->submitter->name],
            'submitted_at' => $row->submitted_at->toIso8601String(), 'closed_at' => $row->closed_at?->toIso8601String(),
            'closing_reason' => $row->closing_reason,
            // The routing evidence an approver decides on — including the pinned currency conversion.
            'amount_base' => $row->facts['amount_base'] ?? null, 'evidence' => $row->facts['evidence'] ?? [],
            'can_decide' => $actionable !== null, 'my_step_id' => $actionable?->id,
            'steps' => $row->steps->map(fn ($s): array => [
                'id' => $s->id, 'stage' => $s->stage, 'name' => $s->name, 'status' => $s->status->value,
                'required_approvals' => $s->required_approvals,
                'approved_count' => $s->decisions->where('decision', ApprovalDecisionKind::Approved)->count(),
                'deadline_at' => $s->deadline_at?->toIso8601String(), 'overdue' => $s->overdue_at !== null,
                'uses_authority_limit' => $s->policyStep->use_authority_limit,
                'decisions' => $s->decisions->map(fn ($d): array => [
                    'id' => $d->id, 'decision' => $d->decision->value, 'reason' => $d->reason,
                    'decided_by' => ['id' => $d->decided_by, 'name' => $d->decider->name],
                    'acting_for' => $d->delegation_id === null ? null : $d->principal_id,
                    'decided_at' => $d->decided_at->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
