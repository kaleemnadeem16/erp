<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;

/** Grants a person an amount ceiling for a subject type (ADR 0070 §5). Ceilings are data, never permissions. */
final class CreateApprovalAuthority
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(array $attributes, int $actorId): ApprovalAuthority
    {
        $this->subjects->get($attributes['subject_type']);
        $unlimited = (bool) ($attributes['unlimited'] ?? false);
        if ($unlimited === isset($attributes['ceiling_base_amount'])) {
            throw new DomainInvariantException('Give either a ceiling or mark the authority unlimited, not both.', 'ceiling_base_amount');
        }

        return ApprovalAuthority::query()->create([
            'user_id' => $attributes['user_id'], 'subject_type' => $attributes['subject_type'], 'unlimited' => $unlimited,
            'ceiling_base_amount' => $unlimited ? null : $attributes['ceiling_base_amount'],
            'valid_from' => $attributes['valid_from'] ?? null, 'valid_to' => $attributes['valid_to'] ?? null,
            'reason' => $attributes['reason'], 'changed_by' => $actorId,
        ])->refresh();
    }
}
