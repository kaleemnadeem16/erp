<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockValuationEntry;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;

/** Internal to recalculation Actions: one snapshot/replay path for initial and replacement versions. */
final class RecalculationImpactBuilder
{
    public function __construct(
        private readonly AvcoCounterfactualReplayer $avco,
        private readonly LayerCounterfactualReplayer $layers,
        private readonly RecalculationRiskClassifier $risk,
        private readonly AvcoPoolManager $pools,
        private readonly MovementCounterfactualReplayer $movements,
    ) {}

    /** @return array<string, mixed> */
    public function attributes(RecalculationRequest $request, Company $company): array
    {
        if ($request->movement_kind !== RecalculationMovementKind::Receipt) {
            return $this->movementAttributes($request, $company);
        }
        $item = Item::query()->findOrFail($request->item_id);
        $location = Location::query()->lockForUpdate()->findOrFail($request->location_id);
        $batch = $request->batch_id === null ? null : StockBatch::query()->findOrFail($request->batch_id);
        // Match posting lock order: location, then valuation pool. Reads below
        // must describe one stock state, not a mixture of concurrent commits.
        if ($item->valuation_method === ValuationMethod::Avco) {
            $pool = $this->pools->resolveExisting($item, $location, $batch);
            if ($pool !== null) {
                AvcoPool::query()->lockForUpdate()->findOrFail($pool->id);
            }
        }
        $qty = BigDecimal::of($request->qty);
        $cost = BigDecimal::of($request->base_unit_cost);
        $preview = $item->valuation_method === ValuationMethod::Avco
            ? $this->avco->replay($item, $location, $batch, $qty, $cost, $request->moved_at)
            : $this->layers->replay($item, $location, $batch, $request->serial_no, $qty, $cost, $request->moved_at);
        // Immutable movement/valuation membership is semantic even when the net delta is identical.
        $dependencies = $preview->pool !== null
            ? StockValuationEntry::query()->where('avco_pool_id', $preview->pool->id)->orderBy('id')->pluck('id')->all()
            : StockMove::query()->where('item_id', $item->id)->where('batch_id', $batch?->id)->where('serial_no', $request->serial_no)
                ->where(fn ($q) => $q->where('from_location_id', $location->id)->orWhere('to_location_id', $location->id))
                ->orderBy('id')->pluck('id')->all();
        $periods = AccountingPeriod::query()->whereDate('ends_on', '>=', $request->moved_at)
            ->whereDate('starts_on', '<=', now())->orderBy('id')->get(['id', 'starts_on', 'ends_on', 'status'])->toArray();

        return [
            'avco_pool_id' => $preview->pool?->id,
            'original_qty_on_hand' => (string) $preview->originalQtyOnHand,
            'original_inventory_value' => (string) $preview->originalInventoryValue,
            'original_avg_unit_cost' => (string) $preview->originalAvgUnitCost,
            'counterfactual_qty_on_hand' => (string) $preview->counterfactualQtyOnHand,
            'counterfactual_inventory_value' => (string) $preview->counterfactualInventoryValue,
            'counterfactual_avg_unit_cost' => (string) $preview->counterfactualAvgUnitCost,
            'valuation_adjustment' => (string) $preview->valuationAdjustment(),
            'risk_classification' => $this->risk->classify($company, $request->moved_at, $preview->valuationAdjustment())->value,
            'dependency_fingerprint' => hash('sha256', json_encode([$dependencies, $periods], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function append(RecalculationRequest $request, array $attributes): RecalculationImpactVersion
    {
        return RecalculationImpactVersion::query()->create($attributes + [
            'recalculation_request_id' => $request->id,
            'version_no' => ((int) $request->impactVersions()->max('version_no')) + 1,
            'computed_at' => now(), 'is_stale' => false,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function matches(RecalculationImpactVersion $version, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $stored = $version->getAttribute($key);
            if ($stored instanceof \BackedEnum) {
                $stored = $stored->value;
            }
            if ($key === 'movement_impact') {
                if ($this->canonical($stored) !== $this->canonical($value)) {
                    return false;
                }
            } elseif (str_ends_with($key, 'fingerprint') || $key === 'risk_classification' || $key === 'avco_pool_id') {
                if ($stored !== $value) {
                    return false;
                }
            } elseif (! BigDecimal::of($stored)->isEqualTo($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function movementAttributes(RecalculationRequest $request, Company $company): array
    {
        $attributes = $this->movements->attributes($request, $company);
        $attributes['risk_classification'] = $this->risk->classify($company, $request->moved_at, $attributes['risk_exposure'])->value;
        unset($attributes['risk_exposure']);
        $periods = AccountingPeriod::query()->whereDate('ends_on', '>=', $request->moved_at)
            ->whereDate('starts_on', '<=', now())->orderBy('id')->get(['id', 'starts_on', 'ends_on', 'status'])->toArray();
        $attributes['dependency_fingerprint'] = hash('sha256', json_encode($this->canonical([$attributes['movement_impact'], $periods]), JSON_THROW_ON_ERROR));

        return $attributes;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($entry) => $this->canonical($entry), $value);
    }
}
