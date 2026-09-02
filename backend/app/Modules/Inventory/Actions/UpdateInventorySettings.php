<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UpdateInventorySettings
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Company
    {
        return DB::transaction(function () use ($attributes): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());

            if ($company->inventory_location_mode === InventoryLocationMode::Structured
                && ($attributes['inventory_location_mode'] ?? null) === InventoryLocationMode::Simple->value) {
                $this->guardSimpleDowngrade();
            }

            $company->update($attributes);

            return $company->refresh();
        });
    }

    private function guardSimpleDowngrade(): void
    {
        $warehouseCount = Warehouse::query()->count();
        $locationCount = Location::query()->count();
        $hasStructuredAttributes = Location::query()
            ->where(fn ($query) => $query
                ->whereNotNull('parent_id')
                ->orWhere('type', '!=', 'STORAGE')
                ->orWhereNotNull('barcode')
                ->orWhere('sort_order', '!=', 0)
                ->orWhere('allows_stock', false))
            ->exists();

        if ($locationCount > $warehouseCount || $hasStructuredAttributes
            || WarehouseLocationAssignment::query()->exists()) {
            throw new DomainInvariantException(
                'STRUCTURED mode cannot be disabled while structured locations or roles exist.',
                'inventory_location_mode',
            );
        }
    }
}
