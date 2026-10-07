<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/** Economic impact is inseparable from the decision this workflow presents. */
final class RecalculationReadAccess
{
    /** Any one of these opens the read. Public so record scope resolves over the same list the gate uses. */
    public const PERMISSIONS = [
        'inventory.recalculation.request',
        'inventory.recalculation.approve',
        'inventory.recalculation.approve-high-risk',
        'inventory.recalculation.execute',
        'inventory.recalculation.review-regeneration',
        'inventory.valuation.view',
    ];

    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission(self::PERMISSIONS) === true;
    }
}
