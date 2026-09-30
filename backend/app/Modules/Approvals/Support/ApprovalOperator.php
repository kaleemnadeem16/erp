<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use Brick\Math\BigDecimal;

/**
 * The bounded comparisons a policy condition may make (ADR 0070 §4). No expressions, no code: a condition
 * is a fact, one of these operators and a value, so it can be validated, explained and versioned.
 */
enum ApprovalOperator: string
{
    case Gte = 'GTE';
    case Gt = 'GT';
    case Lte = 'LTE';
    case Lt = 'LT';
    case Eq = 'EQ';
    case Neq = 'NEQ';
    case In = 'IN';

    public function label(): string
    {
        return match ($this) {
            self::Gte => 'is at least',
            self::Gt => 'is more than',
            self::Lte => 'is at most',
            self::Lt => 'is less than',
            self::Eq => 'is',
            self::Neq => 'is not',
            self::In => 'is one of',
        };
    }

    /** Whether this operator is meaningful for a fact of the given type. */
    public function appliesTo(ApprovalFactType $type): bool
    {
        return match ($this) {
            self::Gte, self::Gt, self::Lte, self::Lt => $type === ApprovalFactType::Decimal,
            default => true,
        };
    }

    /** @param  mixed  $expected  a scalar, or a list for IN */
    public function matches(ApprovalFactType $type, mixed $actual, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }
        if ($this === self::In) {
            return in_array((string) $actual, array_map('strval', (array) $expected), true);
        }
        if ($type === ApprovalFactType::Decimal) {
            $order = BigDecimal::of((string) $actual)->compareTo(BigDecimal::of((string) $expected));

            return match ($this) {
                self::Gte => $order >= 0,
                self::Gt => $order > 0,
                self::Lte => $order <= 0,
                self::Lt => $order < 0,
                self::Eq => $order === 0,
                default => $order !== 0,
            };
        }

        return match ($this) {
            self::Eq => (string) $actual === (string) $expected,
            self::Neq => (string) $actual !== (string) $expected,
            default => false,
        };
    }
}
