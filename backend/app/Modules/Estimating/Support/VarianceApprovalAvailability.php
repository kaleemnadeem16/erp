<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;

/**
 * Whether the `APPROVAL_REQUIRED` effect may be offered (ADR 0054 §7): only while the company has switched approvals on
 * for material variance. It is never simulated with a permission or a text field, and switching it off again does not
 * silently downgrade bands already stored — they resolve to BLOCKED (fail closed), see `EvaluateMaterialVariance`.
 */
final class VarianceApprovalAvailability
{
    public function __construct(private readonly ApprovalModes $modes) {}

    public function enabled(): bool
    {
        return $this->modes->for(MaterialVarianceApprovalSubject::CODE) !== ApprovalMode::None;
    }

    public function allows(VarianceEffect $effect): bool
    {
        return $effect !== VarianceEffect::ApprovalRequired || $this->enabled();
    }

    /** @return list<string> */
    public function configurableEffects(): array
    {
        return array_values(array_map(fn (VarianceEffect $e): string => $e->value, array_filter(VarianceEffect::cases(), fn (VarianceEffect $e): bool => $this->allows($e))));
    }
}
