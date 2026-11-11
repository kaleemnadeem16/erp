<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class CreateWarehouseLocationAssignment
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): WarehouseLocationAssignment
    {
        ActionAuthorization::require($actor, 'inventory.location.manage', 'assign a warehouse location role');

        return DB::transaction(function () use ($attributes, $actor): WarehouseLocationAssignment {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.location.manage', 'assign a warehouse location role');
            $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());
            if ($company->inventory_location_mode !== InventoryLocationMode::Structured) {
                throw new DomainInvariantException(
                    'Warehouse location roles require STRUCTURED inventory location mode.',
                    'role',
                );
            }

            return WarehouseLocationAssignment::create($attributes)->refresh();
        });
    }
}
