<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UpdateInventorySettings
{
    /**
     * Each group is one governed policy: changing any of its fields requires an
     * actor + reason and writes its own InventoryPolicyChange audit row, keyed
     * by the group name.
     */
    private const POLICY_FIELD_GROUPS = [
        'LOCATION_STRUCTURE' => ['inventory_location_mode'],
        'DEFAULT_AVCO_POOL_SCOPE' => ['default_avco_pool_scope'],
        'NEGATIVE_STOCK' => [
            'negative_stock_mode', 'negative_stock_authorisation_mode', 'negative_stock_review_sla_hours',
            'negative_stock_allow_self_review', 'negative_stock_sales_despatch_enabled',
            'negative_stock_material_consumption_enabled', 'negative_stock_supplier_return_enabled',
            'negative_stock_scrap_writeoff_enabled',
        ],
        'BACKDATING_HORIZON' => [
            'backdating_horizon_mode', 'backdating_posting_floor_subtype',
            'backdating_posting_floor_fixed_date', 'backdating_posting_floor_rolling_days',
        ],
        'RECALCULATION' => [
            'authorised_forward_recalculation_enabled', 'recalculation_review_sla_hours', 'recalculation_approval_ttl_hours',
            'recalculation_high_risk_threshold_base_amount',
        ],
    ];

    /** The capability each policy group needs; the Form Request asks the same, this is the authoritative check. */
    private const GROUP_PERMISSIONS = [
        'LOCATION_STRUCTURE' => 'inventory.location-policy.manage',
        'DEFAULT_AVCO_POOL_SCOPE' => 'inventory.valuation-policy.manage',
        'NEGATIVE_STOCK' => 'inventory.negative-stock-policy.manage',
        'BACKDATING_HORIZON' => 'inventory.backdating-horizon-policy.manage',
        'RECALCULATION' => 'inventory.recalculation-policy.manage',
    ];

    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor, ?string $reason = null): Company
    {
        foreach (self::GROUP_PERMISSIONS as $group => $permission) {
            if (array_intersect(array_keys($attributes), self::POLICY_FIELD_GROUPS[$group]) !== []) {
                ActionAuthorization::require($actor, $permission, 'change the '.strtolower(str_replace('_', ' ', $group)).' policy');
            }
        }
        $actorId = $actor->id;

        return DB::transaction(function () use ($attributes, $actorId, $reason): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());

            if ($company->inventory_location_mode === InventoryLocationMode::Structured
                && ($attributes['inventory_location_mode'] ?? null) === InventoryLocationMode::Simple->value) {
                $this->guardSimpleDowngrade();
            }

            $allPolicyFields = array_merge(...array_values(self::POLICY_FIELD_GROUPS));
            $oldValues = collect($allPolicyFields)
                ->filter(fn (string $field): bool => array_key_exists($field, $attributes))
                ->mapWithKeys(fn (string $field): array => [$field => $company->getRawOriginal($field)])
                ->all();
            $company->fill($attributes);
            // Eloquent's cast-aware dirty check, so resubmitting an unchanged
            // value in a different lexical form ("1" for a boolean, "24" for
            // an integer) is not recorded as a policy change.
            $changedPolicyFields = array_keys(array_intersect_key($company->getDirty(), array_flip($allPolicyFields)));
            if ($changedPolicyFields !== [] && trim((string) $reason) === '') {
                throw new DomainInvariantException('Policy changes require a reason.', 'reason');
            }

            $company->save();
            $company->refresh();

            foreach (self::POLICY_FIELD_GROUPS as $policy => $groupFields) {
                $changedInGroup = array_intersect($changedPolicyFields, $groupFields);
                if ($changedInGroup === []) {
                    continue;
                }

                InventoryPolicyChange::query()->create([
                    'policy' => $policy,
                    'old_values' => array_intersect_key($oldValues, array_flip($changedInGroup)),
                    'new_values' => collect($changedInGroup)->mapWithKeys(
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
                ->orWhere('allows_stock', false)
                ->orWhereNotNull('counts_as_usable'))
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
