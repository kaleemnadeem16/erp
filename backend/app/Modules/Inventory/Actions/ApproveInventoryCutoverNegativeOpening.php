<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutoverNegativeOpening;
use App\Modules\Inventory\Support\InventoryCutoverNegativeOpeningStatus;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0037's cutover-shortage exception, approval half — the "dedicated
 * cutover approval" the ADR requires. Independent of the request: the
 * requester cannot self-approve, mirroring ApproveInventoryCutover. Approving
 * here grants no live ADR 0037 override permission and is invisible to it.
 */
final class ApproveInventoryCutoverNegativeOpening
{
    public function __invoke(InventoryCutoverNegativeOpening $exception, User $actor): InventoryCutoverNegativeOpening
    {
        if (! $actor->can('inventory.cutover.negative_opening.approve')) {
            throw new DomainInvariantException('The actor is not permitted to approve an inventory cutover negative-opening exception.', 'approved_by');
        }

        return DB::transaction(function () use ($exception, $actor): InventoryCutoverNegativeOpening {
            $exception = InventoryCutoverNegativeOpening::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($exception->status === InventoryCutoverNegativeOpeningStatus::Approved) {
                return $exception;
            }
            if ($actor->id === $exception->requested_by) {
                throw new DomainInvariantException('The actor who requested this exception cannot also approve it.', 'approved_by');
            }
            $exception->update(['status' => InventoryCutoverNegativeOpeningStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $exception->refresh();
        });
    }
}
