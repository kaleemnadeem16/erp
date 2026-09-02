<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Brick\Math\BigDecimal;
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
            $received = $this->effectiveReceived($line);
            $remaining = BigDecimal::of($line->ordered_stock_qty)->minus($received);
            if (! $remaining->isGreaterThan(0)) {
                throw new DomainInvariantException('A fully received PO line cannot be short-closed.', 'status');
            }
            $line->update(['short_closed_stock_qty' => (string) $remaining]);

            $hasOpenLines = $order->lines()->get()->contains(function (PurchaseOrderLine $orderLine): bool {
                $terminalQty = BigDecimal::of($orderLine->short_closed_stock_qty)->plus($this->effectiveReceived($orderLine));

                return $terminalQty->isLessThan(BigDecimal::of($orderLine->ordered_stock_qty));
            });
            if (! $hasOpenLines) {
                $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()]);
            }

            return $line->refresh();
        });
    }

    private function effectiveReceived(PurchaseOrderLine $line): BigDecimal
    {
        return BigDecimal::of((string) GoodsReceiptLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted->value))
            ->sum('stock_qty'));
    }
}
