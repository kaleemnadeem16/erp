<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/** Economic impact is inseparable from the decision this workflow presents. */
final class RecalculationReadAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->can('inventory.recalculation.request')
            || $user?->can('inventory.recalculation.approve')
            || $user?->can('inventory.recalculation.approve-high-risk')
            || $user?->can('inventory.recalculation.execute')
            || $user?->can('inventory.recalculation.review-regeneration')
            || $user?->can('inventory.valuation.view');
    }
}
