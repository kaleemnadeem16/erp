<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\Request;

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

    /**
     * Money on ONE purchase order: the capability AND the order inside that capability's own reach (a limited commercial grant never shows
     * the money of orders it does not reach, even to someone who may operate on them). Use this for anything that belongs to a single order.
     */
    public static function forOrder(?User $user, PurchaseOrder $order, ?Request $request = null): bool
    {
        return PurchaseOrderAccess::make()->mayViewCommercial($user, $order, $request);
    }

    /** Money that is not tied to one order the person reaches (receipts, supplier offers): only an unrestricted commercial grant shows it. */
    public static function companyWide(?User $user, ?Request $request = null): bool
    {
        return $user !== null && PurchaseOrderAccess::make()->commercialReach($user, $request)->isAll();
    }
}
