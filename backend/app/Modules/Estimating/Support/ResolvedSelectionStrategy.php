<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** The strategy that applies to one item, where it came from and the stored row (if any) that supplied it. */
final class ResolvedSelectionStrategy
{
    public function __construct(
        public readonly MaterialSelectionStrategy $strategy,
        public readonly MaterialSelectionSource $source,
        public readonly ?int $policyId,
        public readonly ?int $scopeId,
    ) {}
}
