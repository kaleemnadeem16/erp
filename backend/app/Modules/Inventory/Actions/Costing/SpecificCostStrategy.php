<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Costing;

use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostResolutionFactory;
use App\Modules\Inventory\Support\Costing\CostStrategy;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Modules\Inventory\Support\ItemTracking;

final class SpecificCostStrategy implements CostStrategy
{
    public function __construct(private readonly FifoCostStrategy $selectedLayers, private readonly CostResolutionFactory $results) {}

    public function method(): string
    {
        return 'SPECIFIC';
    }

    public function resolve(MaterialCostRequest $request, CostReadSession $session): CostResolution
    {
        if ($request->item->tracking === ItemTracking::Serial ? $request->serialNo === null : $request->batchId === null) {
            return $this->results->unknown($request, $session, 'SPECIFIC_IDENTIFICATION_REQUIRED');
        }

        return $this->selectedLayers->resolve($request, $session);
    }
}
