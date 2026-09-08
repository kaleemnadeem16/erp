<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverNegativeOpening;
use App\Modules\Inventory\Models\InventoryCutoverPolicyTransition;
use App\Modules\Inventory\Models\InventorySourceFact;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\InventoryCutoverNegativeOpeningStatus;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0037's cutover-shortage exception, request half: records the evidence
 * for one genuinely negative AVCO/FIFO opening balance at an exact item,
 * location and batch, computed from the cutover's own immutable
 * InventorySourceFact rows — never accepted as free-form input. Dedicated
 * cutover authority, structurally separate from live ADR 0037 overrides
 * (RequestNegativeStockOverride): this workflow grants no live override
 * permission and this Action's permission implies nothing about that one.
 * ApproveInventoryCutoverNegativeOpening is the required second, independent
 * actor.
 */
final class RequestInventoryCutoverNegativeOpening
{
    public function __construct(private readonly StockIdentity $identity) {}

    /**
     * @param  array{item_id:int, location_id:int, batch_no?:string|null, provisional_unit_cost:string, reason:string, supporting_evidence:array<string, mixed>, responsible_owner_id:int, resolution_plan:string, target_date?:string|null, target_date_reason?:string|null}  $input
     */
    public function __invoke(InventoryCutover $cutover, array $input, User $actor): InventoryCutoverNegativeOpening
    {
        if (! $actor->can('inventory.cutover.negative_opening.request')) {
            throw new DomainInvariantException('The actor is not permitted to request an inventory cutover negative-opening exception.', 'requested_by');
        }

        return DB::transaction(function () use ($cutover, $input, $actor): InventoryCutoverNegativeOpening {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            if (in_array($cutover->status, [InventoryCutoverStatus::Failed, InventoryCutoverStatus::Finalised], true)) {
                throw new DomainInvariantException('A negative-opening exception cannot be requested for a failed or finalised cutover.', 'status');
            }

            $item = Item::query()->findOrFail($input['item_id']);
            $location = Location::query()->findOrFail($input['location_id']);
            $batchNo = $this->identity->normalisedBatchNo($input);
            $batch = $batchNo === null ? null : $this->identity->resolveOrCreateBatch($item, $batchNo);

            $existing = InventoryCutoverNegativeOpening::query()
                ->where('inventory_cutover_id', $cutover->id)->where('item_id', $item->id)
                ->where('location_id', $location->id)->where('batch_id', $batch?->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            if ($item->tracking === ItemTracking::Serial) {
                throw new DomainInvariantException('A serial-tracked item can never have a negative-opening exception.', 'item_id');
            }
            $transition = InventoryCutoverPolicyTransition::query()
                ->where('inventory_cutover_id', $cutover->id)->where('item_id', $item->id)->first();
            if ($transition === null) {
                throw new DomainInvariantException('This item has no recorded target valuation policy yet; validate the cutover first.', 'item_id');
            }
            if ($transition->target_valuation_method === ValuationMethod::Specific) {
                throw new DomainInvariantException('A SPECIFIC target valuation method can never have a negative-opening exception.', 'item_id');
            }

            $facts = InventorySourceFact::query()
                ->where('inventory_cutover_id', $cutover->id)->where('item_id', $item->id)->where('location_id', $location->id)
                ->where(fn ($query) => $batchNo === null ? $query->whereNull('batch_no') : $query->where('batch_no', $input['batch_no']))
                ->where('qty', '<', 0)->get();
            if ($facts->isEmpty()) {
                throw new DomainInvariantException('No negative source facts exist at this exact item, location and batch.', 'item_id');
            }
            if ($facts->contains(fn (InventorySourceFact $fact): bool => $fact->serial_no !== null)) {
                throw new DomainInvariantException('A serial-identified negative opening can never have an exception.', 'item_id');
            }
            $sourceMethod = $facts->first()->source_valuation_method;
            if ($sourceMethod === ValuationMethod::Specific) {
                throw new DomainInvariantException('A SPECIFIC source valuation method can never have a negative-opening exception.', 'item_id');
            }
            if ($facts->contains(fn (InventorySourceFact $fact): bool => $fact->source_valuation_method !== $sourceMethod)) {
                throw new DomainInvariantException('Every negative fact at this exact identity must share the same source valuation method.', 'item_id');
            }

            $qty = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $fact): BigDecimal => $sum->plus($fact->qty), BigDecimal::zero());
            $value = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $fact): BigDecimal => $sum->plus($fact->amount), BigDecimal::zero());
            $baseValue = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $fact): BigDecimal => $sum->plus($fact->base_amount), BigDecimal::zero());

            $provisionalUnitCost = BigDecimal::of($input['provisional_unit_cost']);
            if ($provisionalUnitCost->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException('A negative-opening exception requires a positive defensible provisional cost.', 'provisional_unit_cost');
            }
            if (trim($input['reason']) === '' || trim($input['resolution_plan']) === '' || $input['supporting_evidence'] === []) {
                throw new DomainInvariantException('A negative-opening exception requires a reason, resolution plan and supporting evidence.', 'reason');
            }
            User::query()->findOrFail($input['responsible_owner_id']);

            $defaultTargetDate = $cutover->cutover_at->addDays(30)->toDateString();
            $targetDate = $input['target_date'] ?? $defaultTargetDate;
            $targetDateReason = $targetDate === $defaultTargetDate ? null : $input['target_date_reason'] ?? null;
            if ($targetDate !== $defaultTargetDate && trim((string) $targetDateReason) === '') {
                throw new DomainInvariantException('A target resolution date other than 30 days after cutover requires an explicit reason.', 'target_date_reason');
            }

            return InventoryCutoverNegativeOpening::query()->create([
                'inventory_cutover_id' => $cutover->id, 'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batch?->id,
                'source_valuation_method' => $sourceMethod,
                'source_quantity' => (string) $qty, 'source_value' => (string) $value, 'base_source_value' => (string) $baseValue,
                'provisional_unit_cost' => (string) $provisionalUnitCost,
                'reason' => trim($input['reason']), 'supporting_evidence' => $input['supporting_evidence'],
                'responsible_owner_id' => $input['responsible_owner_id'], 'resolution_plan' => trim($input['resolution_plan']),
                'target_date' => $targetDate, 'target_date_reason' => $targetDateReason,
                'status' => InventoryCutoverNegativeOpeningStatus::Requested,
                'requested_by' => $actor->id, 'requested_at' => now(),
            ]);
        });
    }
}
