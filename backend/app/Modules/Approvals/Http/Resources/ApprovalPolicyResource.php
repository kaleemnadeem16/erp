<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalPolicy */
class ApprovalPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApprovalPolicy $row */
        $row = $this->resource;
        $facts = app(ApprovalSubjectRegistry::class)->has($row->subject_type) ? app(ApprovalSubjectRegistry::class)->get($row->subject_type)->facts() : [];

        return [
            'id' => $row->id, 'subject_type' => $row->subject_type, 'code' => $row->code, 'version_no' => $row->version_no,
            'name' => $row->name, 'priority' => $row->priority, 'status' => $row->status->value,
            'effective_from' => $row->effective_from?->toDateString(), 'effective_to' => $row->effective_to?->toDateString(),
            'reason' => $row->reason, 'changed_by' => $row->changed_by,
            'conditions' => $row->relationLoaded('conditions') ? $row->conditions->map(fn ($c): array => [
                'id' => $c->id, 'fact' => $c->fact, 'fact_label' => $facts[$c->fact]['label'] ?? $c->fact,
                'operator' => $c->operator->value, 'operator_label' => $c->operator->label(), 'value' => $c->value,
            ])->values()->all() : [],
            'steps' => $row->relationLoaded('steps') ? $row->steps->map(fn ($s): array => [
                'id' => $s->id, 'stage' => $s->stage, 'name' => $s->name, 'required_approvals' => $s->required_approvals,
                'permission' => $s->permission, 'use_authority_limit' => $s->use_authority_limit, 'allow_submitter' => $s->allow_submitter,
                'expires_after_hours' => $s->expires_after_hours, 'on_expiry' => $s->on_expiry->value, 'escalation_permission' => $s->escalation_permission,
            ])->values()->all() : [],
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
