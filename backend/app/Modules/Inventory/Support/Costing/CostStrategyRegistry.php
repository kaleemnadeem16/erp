<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

final class CostStrategyRegistry
{
    /** @var array<string, CostStrategy> */
    private array $strategies = [];

    public function register(CostStrategy $strategy): void
    {
        if (isset($this->strategies[$strategy->method()])) {
            throw new \LogicException('Duplicate material cost strategy.');
        }
        $this->strategies[$strategy->method()] = $strategy;
    }

    public function find(string $method): ?CostStrategy
    {
        return $this->strategies[$method] ?? null;
    }
}
