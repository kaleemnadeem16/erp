<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use Illuminate\Support\Facades\DB;

final class CancelPhysicalCount
{
    public function __invoke(PhysicalCount $count, User $actor): PhysicalCount
    {
        if (! $actor->can('inventory.physical_count.create')) {
            throw new DomainInvariantException('The actor is not permitted to cancel a physical count.', 'cancelled_by');
        }

        return DB::transaction(function () use ($count, $actor): PhysicalCount {
            $count = PhysicalCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === PhysicalCountStatus::Cancelled) {
                return $count;
            }
            if ($count->status !== PhysicalCountStatus::Draft) {
                throw new DomainInvariantException('Only a draft physical count can be cancelled.', 'status');
            }
            $count->update(['status' => PhysicalCountStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);

            return $count->refresh();
        });
    }
}
