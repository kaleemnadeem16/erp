<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;

final class PurchasingCommercialAccess
{
    public static function canView(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'purchasing.commercial-cost.view',
            // Drafting/editing a priced PO requires reading its own commercial fields.
            'purchasing.purchase-order.create',
        ]) === true;
    }
}
