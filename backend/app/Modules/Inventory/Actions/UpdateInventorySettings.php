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

            $policyChanges = array_intersect_key($attributes, array_flip(self::NEGATIVE_STOCK_FIELDS));
            $oldValues = collect(array_keys($policyChanges))->mapWithKeys(
                fn (string $field): array => [$field => $company->getRawOriginal($field)],
            )->all();
            $changedPolicyValues = array_filter(
                $policyChanges,
                fn (mixed $value, string $field): bool => $oldValues[$field] !== $value,
                ARRAY_FILTER_USE_BOTH,
            );
            if ($changedPolicyValues !== [] && ($actorId === null || trim((string) $reason) === '')) {
                throw new DomainInvariantException('Negative-stock policy changes require an actor and reason.', 'reason');
            }

            $company->update($attributes);

            if ($changedPolicyValues !== []) {
                InventoryPolicyChange::query()->create([
                    'policy' => 'NEGATIVE_STOCK',
                    'old_values' => array_intersect_key($oldValues, $changedPolicyValues),
                    'new_values' => collect(array_keys($changedPolicyValues))->mapWithKeys(
                        fn (string $field): array => [$field => $company->getRawOriginal($field)],
                    )->all(),
                    'reason' => trim((string) $reason), 'changed_by' => $actorId, 'changed_at' => now(),
                ]);
            }

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
