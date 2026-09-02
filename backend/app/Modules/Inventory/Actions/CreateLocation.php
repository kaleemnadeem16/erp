<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class CreateLocation
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Location
    {
        return DB::transaction(function () use ($attributes): Location {
            $company = Company::query()->findOrFail($this->companyContext->id());
            if ($company->inventory_location_mode !== InventoryLocationMode::Structured) {
                throw new DomainInvariantException(
                    'Additional locations require STRUCTURED inventory location mode.',
                    'warehouse_id',
                );
            }

            $warehouse = Warehouse::query()->findOrFail($attributes['warehouse_id']);
            if (! $warehouse->active) {
                throw new DomainInvariantException('A location cannot be added to an inactive warehouse.', 'warehouse_id');
            }

            return Location::create($attributes)->refresh();
        });
    }
}
