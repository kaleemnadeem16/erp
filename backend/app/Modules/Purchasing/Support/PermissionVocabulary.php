<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

/** Purchasing-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'purchasing.commercial-cost.view',
            'purchasing.purchase-order.view',
            'purchasing.purchase-order.create',
            'purchasing.purchase-order.submit',
            'purchasing.purchase-order.approve',
            'purchasing.purchase-order.issue',
            'purchasing.purchase-order.cancel',
            'purchasing.purchase-order.short-close',
            'purchasing.goods-receipt.view',
            'purchasing.goods-receipt.create',
            'purchasing.goods-receipt.post',
            'purchasing.goods-receipt.reverse',
            'purchasing.quality-disposition.post',
            'purchasing.item-supplier.view',
            'purchasing.item-supplier.manage',
            'purchasing.over-receipt-policy.manage',
            'purchasing.policy-history.view',
            'purchasing.cutover.commit',
            // Changes purchase-order and goods-receipt number series in Settings → Document Numbering (ADR 0079).
            'purchasing.numbering.manage',
        ];
    }
}
