<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/** Opening-stock value is part of cutover reconciliation and approval evidence. */
final class CutoverReadAccess
{
    /** Any one of these opens the read. Public so record scope resolves over the same list the gate uses. */
    public const PERMISSIONS = [
        'inventory.cutover.create',
        'inventory.cutover.stage',
        'inventory.cutover.validate',
        'inventory.cutover.approve',
        'inventory.cutover.commit',
        'inventory.cutover.finalise',
        'inventory.cutover.negative_opening.request',
        'inventory.cutover.negative_opening.approve',
        'inventory.valuation.view',
    ];

    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission(self::PERMISSIONS) === true;
    }
}
