<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\StrictApprovalSubject;

/**
 * Which active policy applies to a document's routing (ADR 0070 §3): among the active policies of the subject type
 * whose conditions all match, the highest priority wins, the lowest id breaking a tie so the answer is deterministic. Opt-in strict subjects instead use effective dates and refuse a best-priority tie.
 * Read-only: it is also how a preview says which approval a plan *would* need.
 */
final class ResolveApprovalPolicy
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function __invoke(string $subjectType, ApprovalRouting $routing): ?ApprovalPolicy
    {
        $subject = $this->subjects->get($subjectType);
        $facts = $subject->facts();
        $strict = $subject instanceof StrictApprovalSubject && $subject->strictPolicySelection();
        $policies = ApprovalPolicy::query()->active()->where('subject_type', $subjectType)
            ->with(['conditions', 'steps'])->orderByDesc('priority')->orderBy('id')->get();

        if (! $strict) {
            foreach ($policies as $policy) {
                if ($this->matches($policy, $facts, $routing)) {
                    return $policy;
                }
            }

            return null;
        }
        $date = $subject->policySelectionDate();
        $policies = $policies->filter(fn (ApprovalPolicy $p): bool => ($p->effective_from === null || $p->effective_from->format('Y-m-d') <= $date)
            && ($p->effective_to === null || $p->effective_to->format('Y-m-d') > $date));
        $matches = $policies->filter(fn (ApprovalPolicy $p): bool => $this->matches($p, $facts, $routing))->values();
        if ($matches->count() > 1 && $matches[0]->priority === $matches[1]->priority) {
            throw new DomainInvariantException('Equally ranked approval policies apply. Resolve the conflict before submitting.', 'approval_policy', 'APPROVAL_POLICY_AMBIGUOUS');
        }

        return $matches->first();
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
