<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Models\User;
use App\Modules\Estimating\Support\CostReadAuthorization;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;

/** Guarded cost disclosure adapter. Blind business-control evaluation uses its own guarded Action. */
final class ReadMaterialCost
{
    public function __construct(private readonly QueryMaterialCost $query, private readonly CostReadAuthorization $authorise) {}

    public function __invoke(MaterialCostRequest $request, User $actor): CostResolution
    {
        ($this->authorise)($actor);

        return ($this->query)($request);
    }
}
