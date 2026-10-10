<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Internal to a single hypothetical scenario: no lock, reservation or stock consumption. */
final class CostReadSession
{
    /** @var array<int, BigDecimal> */
    private array $drawn = [];

    /** @var array<int, BigDecimal> */
    private array $exactCost = [];

    public function __construct(public readonly CarbonImmutable $observedAt) {}

    public function remaining(int $layerId, BigDecimal $available): BigDecimal
    {
        $remaining = $available->minus($this->drawn[$layerId] ?? BigDecimal::zero());

        return $remaining->isPositive() ? $remaining : BigDecimal::zero();
    }

    /** Cumulative rounding attributes each layer’s residual to the next stable demand. */
    public function attributeCost(int $layerId, BigDecimal $amount): BigDecimal
    {
        $previous = $this->exactCost[$layerId] ?? BigDecimal::zero();
        $next = $previous->plus($amount);
        $this->exactCost[$layerId] = $next;

        return $next->toScale(4, RoundingMode::HALF_UP)->minus($previous->toScale(4, RoundingMode::HALF_UP));
    }

    public function attribute(int $layerId, BigDecimal $quantity): void
    {
        $this->drawn[$layerId] = ($this->drawn[$layerId] ?? BigDecimal::zero())->plus($quantity);
    }
}
