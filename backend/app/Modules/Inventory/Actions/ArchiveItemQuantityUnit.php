<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class ArchiveItemQuantityUnit
{
    // Hard delete, not a soft archive flag: a PO/receipt line snapshots the
    // resolved factor onto itself at creation (uom_factor), so removing the
    // override later never rewrites already-posted history — matches
    // ArchiveApplicationRate, the same shape of table.
    public function __invoke(ItemQuantityUnit $itemQuantityUnit, User $actor): void
    {
        if (! $actor->can('inventory.item.manage')) {
            throw new DomainInvariantException('The actor cannot maintain item conversions.');
        }
        DB::transaction(function () use ($itemQuantityUnit, $actor): void {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.item.manage', 'maintain item conversions');
            $itemQuantityUnit->delete();
        });
    }
}
