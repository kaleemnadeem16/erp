<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Cutover\OpeningStateItemPoster;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventorySourceFact;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The method-specific opening-state Action FinaliseInventoryCutover waits
 * for (see that Action and README.md "Inventory cutover"). Posts every
 * validated OPENING_STATE batch's InventorySourceFact rows as real stock —
 * AVCO pools, FIFO real/synthetic layers, per-serial SPECIFIC costs, all at
 * the cutover timestamp — via OpeningStateItemPoster, then commits those
 * batches so finalisation can proceed. Idempotent: re-running after every
 * batch is already COMMITTED is a no-op.
 *
 * This Action owns only OPENING_STATE batches. Other entity types are
 * committed by their owning module Action before finalisation.
 */
final class CommitInventoryCutoverOpeningState
{
    public function __construct(private readonly OpeningStateItemPoster $poster) {}

    public function __invoke(InventoryCutover $cutover, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.commit')) {
            throw new DomainInvariantException('The actor is not permitted to commit an inventory cutover opening state.', 'status');
        }

        return DB::transaction(function () use ($cutover): InventoryCutover {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            $batches = $cutover->batches()->lockForUpdate()->get();
            $openingBatches = $batches->where('entity_type', 'OPENING_STATE');
            $pending = $openingBatches->filter(fn ($batch): bool => $batch->status === InventoryCutoverBatchStatus::Validated);

            if ($pending->isEmpty()) {
                if ($openingBatches->isNotEmpty() && $openingBatches->every(fn ($batch): bool => $batch->status === InventoryCutoverBatchStatus::Committed)) {
                    return $cutover->refresh()->load('batches');
                }
                throw new DomainInvariantException('Every batch must be validated before its opening state can be committed.', 'status');
            }
            if ($cutover->status !== InventoryCutoverStatus::Approved) {
                throw new DomainInvariantException('Only an approved inventory cutover can have its opening state committed.', 'status');
            }
            $company = Company::query()->findOrFail(app(CompanyContext::class)->id());
            $facts = InventorySourceFact::query()
                ->whereIn('inventory_cutover_batch_id', $pending->pluck('id'))
                ->orderBy('id')->get()->groupBy('item_id');
            $transitionsByItem = $cutover->policyTransitions()->get()->groupBy('item_id');

            foreach ($facts as $itemId => $itemFacts) {
                $item = Item::query()->lockForUpdate()->findOrFail($itemId);
                $transitions = $transitionsByItem->get($itemId);
                if ($transitions === null || $transitions->isEmpty()) {
                    throw new DomainInvariantException("No recorded policy transition covers item {$item->code}'s opening state.", 'target_valuation_policy');
                }
                $this->poster->post($cutover, $item, $itemFacts, $transitions, $company);
            }

            foreach ($pending as $batch) {
                $batch->update(['status' => InventoryCutoverBatchStatus::Committed]);
            }

            return $cutover->refresh()->load('batches');
        });
    }
}
