<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalPolicyGuard;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Creates a DRAFT policy version with its conditions and steps. A `code` that already exists for the subject type
 * becomes its next version, so the history of one named policy stays together and every version is a governed change
 * with a reason and an actor.
 */
final class CreateApprovalPolicy
{
    public function __construct(private readonly ApprovalPolicyGuard $guard) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, User $actor): ApprovalPolicy
    {
        ActionAuthorization::require($actor, 'approvals.policy.manage', 'create an approval policy');
        $actorId = $actor->id;
        /** @var list<array<string, mixed>> $conditions */
        $conditions = $attributes['conditions'] ?? [];
        /** @var list<array<string, mixed>> $steps */
        $steps = $attributes['steps'] ?? [];
        $this->guard->assertValid($attributes['subject_type'], $conditions, $steps);

        return DB::transaction(function () use ($attributes, $conditions, $steps, $actorId): ApprovalPolicy {
            $version = 1 + (int) ApprovalPolicy::query()->where('subject_type', $attributes['subject_type'])->where('code', $attributes['code'])->max('version_no');
            $policy = ApprovalPolicy::query()->create([
                'subject_type' => $attributes['subject_type'], 'code' => $attributes['code'], 'version_no' => $version,
                'name' => $attributes['name'], 'priority' => $attributes['priority'] ?? 0, 'status' => ApprovalPolicyStatus::Draft,
                'reason' => $attributes['reason'], 'changed_by' => $actorId,
            ]);
            $this->fill($policy, $conditions, $steps);

            return $policy->refresh()->load(['conditions', 'steps']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $steps
     */
    public function fill(ApprovalPolicy $policy, array $conditions, array $steps): void
    {
        foreach ($conditions as $condition) {
            $policy->conditions()->create(['fact' => $condition['fact'], 'operator' => $condition['operator'], 'value' => $condition['value']]);
        }
        foreach ($steps as $i => $step) {
            $policy->steps()->create([
                'stage' => $step['stage'] ?? $i + 1, 'name' => $step['name'], 'required_approvals' => $step['required_approvals'],
                'review_profile' => $step['review_profile'] ?? 'COMMERCIAL',
                'permission' => $step['permission'] ?? null, 'use_authority_limit' => (bool) ($step['use_authority_limit'] ?? false),
                'allow_submitter' => (bool) ($step['allow_submitter'] ?? false), 'expires_after_hours' => $step['expires_after_hours'] ?? null,
                'on_expiry' => $step['on_expiry'] ?? 'FLAG_OVERDUE', 'escalation_permission' => $step['escalation_permission'] ?? null,
            ]);
        }
    }
}
