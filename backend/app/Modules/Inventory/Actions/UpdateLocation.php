<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UpdateLocation
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Location $location, array $attributes): Location
    {
        return DB::transaction(function () use ($location, $attributes): Location {
            $structuredFields = ['parent_id', 'type', 'barcode', 'sort_order', 'allows_stock'];
            if (array_intersect(array_keys($attributes), $structuredFields) !== []) {
                $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());
                if ($company->inventory_location_mode !== InventoryLocationMode::Structured) {
                    throw new DomainInvariantException(
                        'Structured location fields require STRUCTURED inventory location mode.',
                        'type',
                    );
                }
            }

            if (($attributes['active'] ?? true) === false && $location->active
                && $location->warehouse->locations()->where('active', true)->count() === 1) {
                throw new DomainInvariantException('A usable warehouse must retain at least one active location.', 'active');
            }
            $location->update($attributes);

            return $location->refresh();
        });
    }
}
