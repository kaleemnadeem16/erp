<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Exceptions\DomainInvariantException;

/**
 * What a policy version may contain (ADR 0070 §3–§5). Conditions must use facts the subject type declares with
 * operators and values that make sense for them; steps need a positive approver count, and an escalation capability is
 * only meaningful on a step that can go overdue.
 */
final class ApprovalPolicyGuard
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $steps
     */
    public function assertValid(string $subjectType, array $conditions, array $steps): void
    {
        $facts = $this->subjects->get($subjectType)->facts();
        foreach ($conditions as $i => $condition) {
            $fact = $facts[$condition['fact'] ?? ''] ?? null;
            if ($fact === null) {
                throw new DomainInvariantException('That is not something this kind of approval can be routed on.', "conditions.{$i}.fact");
            }
            $operator = ApprovalOperator::tryFrom((string) ($condition['operator'] ?? ''));
            if ($operator === null || ! $operator->appliesTo($fact['type'])) {
                throw new DomainInvariantException("That comparison does not make sense for {$fact['label']}.", "conditions.{$i}.operator");
            }
            $value = $condition['value'] ?? null;
            if ($operator === ApprovalOperator::In ? ! is_array($value) || $value === [] : (is_array($value) || $value === null || $value === '')) {
                throw new DomainInvariantException($operator === ApprovalOperator::In ? 'List at least one value.' : 'Give one value to compare with.', "conditions.{$i}.value");
            }
            if ($fact['type'] === ApprovalFactType::Decimal && collect((array) $value)->contains(fn ($v): bool => ! is_numeric($v))) {
                throw new DomainInvariantException("{$fact['label']} is compared with a number.", "conditions.{$i}.value");
            }
        }

        if ($steps === []) {
            throw new DomainInvariantException('An approval policy needs at least one step.', 'steps');
        }
        foreach ($steps as $i => $step) {
            if (((int) ($step['required_approvals'] ?? 0)) < 1) {
                throw new DomainInvariantException('A step needs at least one approval.', "steps.{$i}.required_approvals");
            }
            if (! empty($step['escalation_permission']) && empty($step['expires_after_hours'])) {
                throw new DomainInvariantException('An escalation capability only applies to a step that can become overdue: set its time limit.', "steps.{$i}.escalation_permission");
            }
            if (($step['on_expiry'] ?? 'FLAG_OVERDUE') === 'EXPIRE_REQUEST' && empty($step['expires_after_hours'])) {
                throw new DomainInvariantException('Ending the request on expiry needs a time limit.', "steps.{$i}.on_expiry");
            }
        }
    }
}
