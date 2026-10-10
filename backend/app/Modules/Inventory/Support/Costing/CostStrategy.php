<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

interface CostStrategy
{
    public function method(): string;

    public function resolve(MaterialCostRequest $request, CostReadSession $session): CostResolution;
}
