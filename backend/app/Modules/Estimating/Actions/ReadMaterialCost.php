<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;

/** Guarded cost disclosure adapter. Blind business-control evaluation uses its own guarded Action. */
final class ReadMaterialCost
{
    public function __construct(private readonly QueryMaterialCost $query, private readonly RecordScope $scope, private readonly CompanyContext $company) {}

    public function __invoke(MaterialCostRequest $request, User $actor): CostResolution
    {
        ActionAuthorization::require($actor, 'estimating.internal_cost.view', 'read estimating costs');
        if ($actor->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The actor is not permitted to read estimating costs.');
        }
        if (! $this->scope->resolve($actor, 'estimating.internal_cost.view')->isAll()) {
            throw new DomainInvariantException('Cost evidence cannot yet be narrowed to the actor’s record scope.', reasonCode: 'REFUSED_WHEN_LIMITED');
        }

        return ($this->query)($request);
    }
}
