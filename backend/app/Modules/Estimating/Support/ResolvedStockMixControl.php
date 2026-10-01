<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** The control that applies to one item, where it came from and the stored row (if any) that supplied it. */
final class ResolvedStockMixControl
{
    public function __construct(
        public readonly StockMixLevel $level,
        public readonly ?string $thresholdPercent,
        public readonly MaterialSelectionSource $source,
        public readonly ?int $policyId,
        public readonly ?int $scopeId,
    ) {}
}
