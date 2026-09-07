<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use Illuminate\Support\Facades\DB;

final class FinaliseInventoryCutover
{
    public function __invoke(InventoryCutover $cutover, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.finalise')) {
            throw new DomainInvariantException('The actor is not permitted to finalise an inventory cutover.', 'finalised_by');
        }

        return DB::transaction(function () use ($cutover, $actor): InventoryCutover {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            if ($cutover->status === InventoryCutoverStatus::Finalised) {
                return $cutover;
            }
            if ($cutover->status !== InventoryCutoverStatus::Approved) {
                throw new DomainInvariantException('Only an approved inventory cutover can be finalised.', 'status');
            }
            $batches = $cutover->batches()->lockForUpdate()->get();
            if ($batches->isEmpty() || $batches->contains(fn ($batch): bool => $batch->status !== InventoryCutoverBatchStatus::Committed)) {
                throw new DomainInvariantException('Every cutover batch must be committed by its owning domain Action before finalisation.', 'batches');
            }
            $cutover->update(['status' => InventoryCutoverStatus::Finalised, 'finalised_by' => $actor->id, 'finalised_at' => now()]);

            return $cutover->refresh();
        });
    }
}
