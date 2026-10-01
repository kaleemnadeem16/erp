<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** How tightly a company controls hand-chosen stock (ADR 0076). */
enum StockMixLevel: string
{
    case NotAllowed = 'NOT_ALLOWED';
    case Information = 'INFORMATION';
    case ReasonRequired = 'REASON_REQUIRED';
    case ApprovalRequired = 'APPROVAL_REQUIRED';

    /** The built-in default when a company has set nothing: allowed, cost shown, nothing required. */
    public static function builtIn(): self
    {
        return self::Information;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotAllowed => 'Not allowed',
            self::Information => 'Allowed, show the cost',
            self::ReasonRequired => 'Reason needed when it costs much more',
            self::ApprovalRequired => 'Approval needed when it costs much more',
        };
    }

    /** The two stricter levels act on a threshold: how far above the proposal's cost a mix may go. */
    public function needsThreshold(): bool
    {
        return $this === self::ReasonRequired || $this === self::ApprovalRequired;
    }
}
