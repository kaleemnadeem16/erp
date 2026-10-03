<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Support\ActionAuthorization;

/**
 * Dated cover: the delegate acts as the delegator, within the delegator's authority (ADR 0070 §6). A delegate cannot
 * pass authority on again — chained delegation is not honoured — and cannot delegate to themselves.
 */
final class CreateApprovalDelegation
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(array $attributes, User $actor): ApprovalDelegation
    {
        // Anyone may delegate their own authority; delegating someone else's takes the delegation-management capability.
        if ((int) $attributes['delegator_id'] !== $actor->id) {
            ActionAuthorization::require($actor, 'approvals.delegation.manage', 'delegate another person\'s authority');
        }
        $actorId = $actor->id;
        if (! empty($attributes['subject_type'])) {
            $this->subjects->get($attributes['subject_type']);
        }
        if ((int) $attributes['delegator_id'] === (int) $attributes['delegate_id']) {
            throw new DomainInvariantException('You cannot delegate to yourself.', 'delegate_id');
        }

        return ApprovalDelegation::query()->create([
            'delegator_id' => $attributes['delegator_id'], 'delegate_id' => $attributes['delegate_id'],
            'subject_type' => $attributes['subject_type'] ?? null, 'valid_from' => $attributes['valid_from'], 'valid_to' => $attributes['valid_to'],
            'reason' => $attributes['reason'], 'created_by' => $actorId,
        ])->refresh();
    }
}
