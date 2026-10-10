<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;

final class CostReadAuthorization
{
    public function __construct(private readonly RecordScope $scope, private readonly CompanyContext $company) {}

    public function __invoke(User $actor): void
    {
        ActionAuthorization::require($actor, 'estimating.internal_cost.view', 'read estimating costs');
        if ($actor->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The actor is outside the active company.');
        }
        if (! $this->scope->resolve($actor, 'estimating.internal_cost.view')->isAll()) {
            throw new DomainInvariantException('Cost evidence cannot yet be narrowed to the actor’s record scope.', reasonCode: 'REFUSED_WHEN_LIMITED');
        }
    }
}
