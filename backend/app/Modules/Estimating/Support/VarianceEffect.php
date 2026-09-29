<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * ADR 0054's four control effects, ordered by strength. These are effects, not
 * role names: permission decides who may attempt an adjustment, the effective
 * policy decides the effect, and an approval is a separate immutable fact.
 */
enum VarianceEffect: string
{
    case AuditOnly = 'AUDIT_ONLY';
    case ReasonRequired = 'REASON_REQUIRED';
    case ApprovalRequired = 'APPROVAL_REQUIRED';
    case Blocked = 'BLOCKED';

    public function strength(): int
    {
        return match ($this) {
            self::AuditOnly => 1,
            self::ReasonRequired => 2,
            self::ApprovalRequired => 3,
            self::Blocked => 4,
        };
    }

    public function strongerThan(self $other): bool
    {
        return $this->strength() > $other->strength();
    }

    /**
     * Whether a company may choose this effect today. ADR 0054 §7: an approval-requiring band cannot
     * be offered until the shared approval workflow (Q27) exists — it is not simulated with a
     * permission or a text field.
     */
    public function isConfigurable(): bool
    {
        return $this !== self::ApprovalRequired;
    }

    public function label(): string
    {
        return match ($this) {
            self::AuditOnly => 'Record only',
            self::ReasonRequired => 'Reason required',
            self::ApprovalRequired => 'Approval required',
            self::Blocked => 'Blocked',
        };
    }
}
