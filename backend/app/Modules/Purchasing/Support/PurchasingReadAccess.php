<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;

/** Minimum document reads required by each Purchasing workflow. */
final class PurchasingReadAccess
{
    public static function purchaseOrders(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'purchasing.purchase-order.view',
            'purchasing.purchase-order.create',
            'purchasing.purchase-order.issue',
            'purchasing.purchase-order.cancel',
            'purchasing.purchase-order.short-close',
            'purchasing.goods-receipt.create',
            'purchasing.goods-receipt.post',
            'purchasing.goods-receipt.reverse',
            'purchasing.quality-disposition.post',
            'purchasing.cutover.commit',
        ]) === true;
    }

    public static function goodsReceipts(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'purchasing.goods-receipt.view',
            'purchasing.goods-receipt.create',
            'purchasing.goods-receipt.post',
            'purchasing.goods-receipt.reverse',
            'purchasing.quality-disposition.post',
        ]) === true;
    }

    public static function itemSuppliers(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'purchasing.item-supplier.view',
            'purchasing.item-supplier.manage',
            'purchasing.purchase-order.view',
            'purchasing.purchase-order.create',
        ]) === true;
    }
}
