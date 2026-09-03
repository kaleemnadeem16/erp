<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UpdateInventorySettings
{
    private const NEGATIVE_STOCK_FIELDS = [
        'negative_stock_mode', 'negative_stock_authorisation_mode', 'negative_stock_review_sla_hours',
        'negative_stock_allow_self_review', 'negative_stock_sales_despatch_enabled',
        'negative_stock_material_consumption_enabled', 'negative_stock_supplier_return_enabled',
        'negative_stock_scrap_writeoff_enabled',
    ];

    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, ?int $actorId = null, ?string $reason = null): Company
    {
        return DB::transaction(function () use ($attributes, $actorId, $reason): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());

            if ($company->inventory_location_mode === InventoryLocationMode::Structured
                && ($attributes['inventory_location_mode'] ?? null) === InventoryLocationMode::Simple->value) {
                $this->guardSimpleDowngrade();
            }

            $oldValues = collect(self::NEGATIVE_STOCK_FIELDS)
                ->filter(fn (string $field): bool => array_key_exists($field, $attributes))
                ->mapWithKeys(fn (string $field): array => [$field => $company->getRawOriginal($field)])
                ->all();
            $company->fill($attributes);
            // Eloquent's cast-aware dirty check, so resubmitting an unchanged
            // value in a different lexical form ("1" for a boolean, "24" for
            // an integer) is not recorded as a policy change.
            $changedPolicyFields = array_keys(array_intersect_key($company->getDirty(), array_flip(self::NEGATIVE_STOCK_FIELDS)));
            if ($changedPolicyFields !== [] && ($actorId === null || trim((string) $reason) === '')) {
                throw new DomainInvariantException('Negative-stock policy changes require an actor and reason.', 'reason');
            }

            $company->save();
            $company->refresh();

            if ($changedPolicyFields !== []) {
                InventoryPolicyChange::query()->create([
                    'policy' => 'NEGATIVE_STOCK',
                    'old_values' => array_intersect_key($oldValues, array_flip($changedPolicyFields)),
                    'new_values' => collect($changedPolicyFields)->mapWithKeys(
                        fn (string $field): array => [$field => $company->getRawOriginal($field)],
                    )->all(),
                    'reason' => trim((string) $reason), 'changed_by' => $actorId, 'changed_at' => now(),
                ]);
            }

            return $company;
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
