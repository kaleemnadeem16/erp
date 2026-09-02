<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;

final class ShortClosePurchaseOrderLine
{
    public function __invoke(PurchaseOrderLine $line): PurchaseOrderLine
    {
        return DB::transaction(function () use ($line): PurchaseOrderLine {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($line->purchase_order_id);
            $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($order->status !== PurchaseOrderStatus::Issued) {
                throw new DomainInvariantException('Only an issued purchase-order line can be short-closed.', 'status');
            }
            // No receipt tables exist in this slice, so effective received quantity is zero.
            // Goods Receipt adds the locked cumulative calculation before this Action is exposed there.
            $line->update(['short_closed_stock_qty' => $line->ordered_stock_qty]);

            $hasOpenLines = $order->lines()
                ->whereColumn('short_closed_stock_qty', '<', 'ordered_stock_qty')
                ->exists();
            if (! $hasOpenLines) {
                $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()]);
            }

            return $line->refresh();
        });
    }
}
