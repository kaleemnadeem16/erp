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
 * Only OPENING_STATE batches are supported today; OPEN_DOCUMENT/MOVEMENT/
 * LAYER entity types from docs/domain/inventory-cutover.md's planning schema
 * remain unimplemented, matching AttachInventoryCutoverBatch/
 * ValidateInventoryCutover's current scope.
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
            $pending = $batches->filter(fn ($batch): bool => $batch->status === InventoryCutoverBatchStatus::Validated);

            if ($pending->isEmpty()) {
                if ($batches->isNotEmpty() && $batches->every(fn ($batch): bool => $batch->status === InventoryCutoverBatchStatus::Committed)) {
                    return $cutover->refresh()->load('batches');
                }
                throw new DomainInvariantException('Every batch must be validated before its opening state can be committed.', 'status');
            }
            if ($cutover->status !== InventoryCutoverStatus::Approved) {
                throw new DomainInvariantException('Only an approved inventory cutover can have its opening state committed.', 'status');
            }
            if ($pending->contains(fn ($batch): bool => $batch->entity_type !== 'OPENING_STATE')) {
                throw new DomainInvariantException('Only OPENING_STATE batches are supported by this Action.', 'entity_type');
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
