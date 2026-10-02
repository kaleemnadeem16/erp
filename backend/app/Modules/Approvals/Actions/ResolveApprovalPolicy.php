<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;

/**
 * Which active policy applies to a document's routing (ADR 0070 §3): among the active policies of the subject type
 * whose conditions all match, the highest priority wins, the lowest id breaking a tie so the answer is deterministic.
 * Read-only: it is also how a preview says which approval a plan *would* need.
 */
final class ResolveApprovalPolicy
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function __invoke(string $subjectType, ApprovalRouting $routing): ?ApprovalPolicy
    {
        $facts = $this->subjects->get($subjectType)->facts();
        $policies = ApprovalPolicy::query()->active()->where('subject_type', $subjectType)
            ->with(['conditions', 'steps'])->orderByDesc('priority')->orderBy('id')->get();

        foreach ($policies as $policy) {
            if ($this->matches($policy, $facts, $routing)) {
                return $policy;
            }
        }

        return null;
    }

    /** @param  array<string, array{label: string, type: ApprovalFactType}>  $facts */
    private function matches(ApprovalPolicy $policy, array $facts, ApprovalRouting $routing): bool
    {
        foreach ($policy->conditions as $condition) {
            $definition = $facts[$condition->fact] ?? null;
            if ($definition === null) {
                return false;
            }
            $actual = $condition->fact === 'amount_base' ? $routing->amountBase : ($routing->facts[$condition->fact] ?? null);
            if (! $condition->operator->matches($definition['type'], $actual, $condition->value)) {
                return false;
            }
        }

        return true;
    }
}
