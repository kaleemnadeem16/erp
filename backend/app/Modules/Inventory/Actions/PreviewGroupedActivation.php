<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\PackageTrackingMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Read-only readiness report for activating grouped package tracking (ADR 0091 §2): which blockers stand between this item and activation,
 * and which scope (location and batch) holding stock still needs a fresh counted checkpoint. Writes nothing and activates nothing.
 */
final class PreviewGroupedActivation
{
    /**
     * @param  list<int>  $checkpointIds  operations offered as the opening count, one per stocked scope
     * @return array{ready:bool, blockers:list<string>, scopes:list<array{location_id:int, batch_id:int|null, quantity:string, operation_id:int|null, problems:list<string>}>}
     */
    public function __invoke(int $itemId, array $checkpointIds, User $actor): array
    {
        if ((int) $actor->company_id !== app(CompanyContext::class)->id() || ! $actor->can('inventory.package-state.view')
            || ! $actor->can('inventory.package-state.activate')) {
            throw new DomainInvariantException('Package activation and view authority in the current company is required.');
        }

        return DB::transaction(fn (): array => $this->report($itemId, $checkpointIds, $actor));
    }

    /**
     * Shared with the activation Action, which calls it inside its own locks.
     *
     * @param  list<int>  $checkpointIds
     * @return array{ready:bool, blockers:list<string>, scopes:list<array{location_id:int, batch_id:int|null, quantity:string, operation_id:int|null, problems:list<string>}>}
     */
    public function report(int $itemId, array $checkpointIds, User $actor): array
    {
        $item = Item::query()->findOrFail($itemId);
        $blockers = [];
        if ($item->package_tracking_mode !== PackageTrackingMode::QuantityOnly) {
            $blockers[] = 'The item already has grouped package tracking.';
        }
        if (! $item->is_stocked) {
            $blockers[] = 'Only stocked items can be tracked by package.';
        }
        if (! in_array($item->tracking, [ItemTracking::None, ItemTracking::Batch], true)) {
            $blockers[] = 'Serial items cannot be tracked by package.';
        }
        if (! in_array($item->valuation_method, [ValuationMethod::Fifo, ValuationMethod::Avco], true)) {
            $blockers[] = 'Only FIFO and AVCO items can be tracked by package.';
        }
        if (NegativeStockShortage::query()->where('item_id', $item->id)->where('qty_open', '>', 0)->exists()) {
            $blockers[] = 'An open stock shortage must be settled first.';
        }
        $cutoverInProgress = DB::table('inventory_source_facts as f')->join('inventory_cutovers as c', 'c.id', '=', 'f.inventory_cutover_id')
            ->where('f.item_id', $item->id)->whereNotIn('c.status', ['FINALISED', 'FAILED'])->exists();
        if ($cutoverInProgress) {
            $blockers[] = 'An inventory cutover that includes this item is still in progress.';
        }
        $unfinished = DB::table('recalculation_requests as r')->where('r.item_id', $item->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('recalculation_executions as e')->whereColumn('e.recalculation_request_id', 'r.id'))->exists();
        if ($unfinished) {
            $blockers[] = 'A recalculation request for this item has not been executed.';
        }

        $scopes = [];
        $layers = StockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)->get();
        foreach ($layers->groupBy(fn (StockLayer $layer) => $layer->location_id.'|'.($layer->batch_id ?? 'none')) as $group) {
            $first = $group->first();
            $scopes[] = ['location_id' => $first->location_id, 'batch_id' => $first->batch_id, 'quantity' => (string) $group->reduce(fn ($sum, $layer) => bcadd($sum, (string) $layer->qty_remaining, 4), '0.0000'),
                'operation_id' => null, 'problems' => $group->contains(fn (StockLayer $layer) => $layer->serial_no !== null) ? ['Serial stock cannot be grouped.'] : []];
        }
        $used = [];
        foreach ($checkpointIds as $id) {
            $operation = PackageOperation::query()->find($id);
            $matched = false;
            foreach ($scopes as $index => $scope) {
                if ($operation !== null && $operation->item_id === $item->id && $operation->location_id === $scope['location_id'] && $operation->batch_id === $scope['batch_id']) {
                    $matched = true;
                    if (isset($used[$index])) {
                        $scopes[$index]['problems'][] = 'More than one checkpoint was offered for this scope.';

                        break;
                    }
                    $used[$index] = true;
                    $scopes[$index]['operation_id'] = $operation->id;
                    $snapshot = app(ReplayPackageCheckpoint::class)($operation->id, $actor);
                    if (! $snapshot['matches_current_stock']) {
                        $scopes[$index]['problems'][] = 'The checkpoint is not current: '.implode(', ', $snapshot['mismatch_reasons']).'.';
                    }
                    if ($snapshot['evidence_kind'] === 'RECEIPT') {
                        $scopes[$index]['problems'][] = 'A receipt observation is not a counted checkpoint.';
                    }

                    break;
                }
            }
            if (! $matched) {
                $blockers[] = "Checkpoint {$id} does not belong to a location and batch of this item that holds stock.";
            }
        }
        $ready = $blockers === [];
        foreach ($scopes as $index => $scope) {
            if ($scope['operation_id'] === null) {
                $scopes[$index]['problems'][] = 'A fresh counted checkpoint is required.';
            }
            if ($scopes[$index]['problems'] !== []) {
                $ready = false;
            }
        }

        return ['ready' => $ready, 'blockers' => $blockers, 'scopes' => $scopes];
    }
}
