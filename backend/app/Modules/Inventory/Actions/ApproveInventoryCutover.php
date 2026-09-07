<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use Illuminate\Support\Facades\DB;

final class ApproveInventoryCutover
{
    public function __invoke(InventoryCutover $cutover, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.approve')) {
            throw new DomainInvariantException('The actor is not permitted to approve an inventory cutover.', 'approved_by');
        }

        return DB::transaction(function () use ($cutover, $actor): InventoryCutover {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            if ($cutover->status === InventoryCutoverStatus::Approved) {
                return $cutover;
            }
            if ($cutover->status !== InventoryCutoverStatus::Validated) {
                throw new DomainInvariantException('Only a validated inventory cutover can be approved.', 'status');
            }
            if ($cutover->created_by === $actor->id || $cutover->validated_by === $actor->id) {
                throw new DomainInvariantException('Cutover approval must be independent from creation and validation.', 'approved_by');
            }
            $cutover->update(['status' => InventoryCutoverStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $cutover->refresh();
        });
    }
}
