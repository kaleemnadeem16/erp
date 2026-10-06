<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalPolicyGuard;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/** Edits a DRAFT version in place. An active or superseded version is history: change it by creating a new version. */
final class UpdateApprovalPolicy
{
    public function __construct(private readonly ApprovalPolicyGuard $guard, private readonly CreateApprovalPolicy $create) {}

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(ApprovalPolicy $policy, array $attributes, User $actor): ApprovalPolicy
    {
        $policy = ApprovalPolicy::query()->findOrFail($policy->id);
        ActionAuthorization::require($actor, 'approvals.policy.manage', 'edit an approval policy');
        $actorId = $actor->id;
        if ($policy->status !== ApprovalPolicyStatus::Draft) {
            throw new DomainInvariantException('Only a draft can be edited. Start a new version instead.', 'status');
        }
        $conditions = $attributes['conditions'] ?? $policy->conditions->map(fn ($c): array => ['fact' => $c->fact, 'operator' => $c->operator->value, 'value' => $c->value])->all();
        $steps = $attributes['steps'] ?? $policy->steps->map(fn ($s): array => $s->only(['review_profile', 'stage', 'name', 'required_approvals', 'permission', 'use_authority_limit', 'allow_submitter', 'expires_after_hours', 'escalation_permission']) + ['on_expiry' => $s->on_expiry->value])->all();
        $this->guard->assertValid($policy->subject_type, $conditions, $steps);

        return DB::transaction(function () use ($policy, $attributes, $conditions, $steps, $actorId): ApprovalPolicy {
            $policy->update(array_filter([
                'name' => $attributes['name'] ?? null, 'priority' => $attributes['priority'] ?? null, 'reason' => $attributes['reason'] ?? null,
            ], fn ($v): bool => $v !== null) + ['changed_by' => $actorId]);
            if (isset($attributes['conditions']) || isset($attributes['steps'])) {
                $policy->conditions()->delete();
                $policy->steps()->delete();
                $this->create->fill($policy, $conditions, $steps);
            }

            return $policy->refresh()->load(['conditions', 'steps']);
        });
    }
}
