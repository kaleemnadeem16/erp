<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;

/**
 * The outcome of comparing one planned quantity with its calculated standard. `effect` is null when
 * there is no adjustment at all (planned equals standard): nothing to control, nothing to explain.
 * `matched` says why the effect is what it is, `skipped` says which configured bands could not be
 * evaluated and why (never silently treated as passing), and `limitations` carries the rest.
 */
final class MaterialVarianceEvaluation
{
    /**
     * @param  list<array{source: string, band_id: int|null, direction: string|null, measure: string|null, threshold: string|null, unit: string|null, observed: string|null, effect: string}>  $matched
     * @param  list<array{band_id: int, reason: string}>  $skipped
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly BigDecimal $standard,
        public readonly BigDecimal $planned,
        public readonly BigDecimal $delta,
        public readonly ?BigDecimal $deltaPercent,
        public readonly ?VarianceDirection $direction,
        public readonly ?VarianceEffect $effect,
        public readonly VarianceResolutionMethod $method,
        public readonly ?int $policyId,
        public readonly array $matched,
        public readonly array $skipped,
        public readonly array $limitations,
    ) {}

    public function isAdjusted(): bool
    {
        return $this->effect !== null;
    }
}
