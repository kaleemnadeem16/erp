<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/** Opening-stock value is part of cutover reconciliation and approval evidence. */
final class CutoverReadAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->can('inventory.cutover.create')
            || $user?->can('inventory.cutover.stage')
            || $user?->can('inventory.cutover.validate')
            || $user?->can('inventory.cutover.approve')
            || $user?->can('inventory.cutover.commit')
            || $user?->can('inventory.cutover.finalise')
            || $user?->can('inventory.cutover.negative_opening.request')
            || $user?->can('inventory.cutover.negative_opening.approve')
            || $user?->can('inventory.valuation.view');
    }
}
