<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Contracts\NumberingScheme;
use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PostGoodsReceipt
{
    public function __construct(private readonly PostInventoryMovement $postMovement, private readonly NumberingScheme $numbers) {}

    public function __invoke(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status === GoodsReceiptStatus::Posted) {
                return $receipt->load('lines');
            }
            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new DomainInvariantException('Only a draft goods receipt can be posted.', 'status');
            }

            /** @var Collection<int, GoodsReceiptLine> $receiptLines */
            $receiptLines = $receipt->lines()->orderBy('purchase_order_line_id')->get();
            if ($receiptLines->isEmpty()) {
                throw new DomainInvariantException('A goods receipt must contain at least one line.', 'lines');
            }
            $poLineIds = $receiptLines->pluck('purchase_order_line_id')->filter()->unique()->sort()->values();
            $poLines = PurchaseOrderLine::query()->with(['purchaseOrder', 'item.stockUom'])
                ->whereKey($poLineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($poLines->count() !== $poLineIds->count()) {
                throw new DomainInvariantException('Every receipt line must resolve to a visible PO line.', 'purchase_order_line_id');
            }

            foreach ($poLines as $poLine) {
                $matchingReceiptLines = $receiptLines->where('purchase_order_line_id', $poLine->id);
                foreach ($matchingReceiptLines as $line) {
                    if ($poLine->purchaseOrder->supplier_account_id !== $receipt->supplier_account_id
                        || $poLine->purchaseOrder->currency_id !== $line->currency_id || $poLine->item_id !== $line->item_id
                        || $poLine->stock_uom_id !== $line->stock_uom_id) {
                        throw new DomainInvariantException('Receipt line snapshots no longer match the issued PO line.', 'purchase_order_line_id');
                    }
                }
                if (BigDecimal::of($poLine->short_closed_stock_qty)->isGreaterThan(0)) {
                    throw new DomainInvariantException('A short-closed PO line cannot receive more stock.', 'purchase_order_line_id');
                }

                $previous = GoodsReceiptLine::query()->where('purchase_order_line_id', $poLine->id)
                    ->where('goods_receipt_id', '<>', $receipt->id)
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted->value))
                    ->sum('stock_qty');
                $current = $matchingReceiptLines->reduce(
                    fn (BigDecimal $total, GoodsReceiptLine $receiptLine) => $total->plus(BigDecimal::of($receiptLine->stock_qty)),
                    BigDecimal::zero(),
                );
                $cumulative = BigDecimal::of((string) $previous)->plus($current);
                $maximum = BigDecimal::of($poLine->ordered_stock_qty);
                if ($poLine->over_receipt_mode === OverReceiptMode::AllowWithinTolerance) {
                    $maximum = $maximum->multipliedBy(BigDecimal::one()->plus(
                        BigDecimal::of($poLine->over_receipt_tolerance_percent ?? '0')->dividedBy(100, 9, RoundingMode::HALF_UP)
                    ));
                    if ($poLine->item->stockUom->dimension->value === 'COUNT') {
                        $maximum = $maximum->toScale(0, RoundingMode::FLOOR);
                    }
                }
                if ($cumulative->isGreaterThan($maximum)) {
                    throw new DomainInvariantException('Cumulative receipt quantity exceeds the issued PO tolerance.', 'stock_qty');
                }
            }

            foreach ($receiptLines as $line) {
                ($this->postMovement)([
                    'item_id' => $line->item_id, 'location_id' => $line->location_id,
                    'qty' => $line->stock_qty, 'uom_id' => $line->stock_uom_id,
                    'moved_at' => $receipt->physical_arrival_at,
                    'source_type' => 'GOODS_RECEIPT_LINE', 'source_id' => $line->id,
                    'unit_cost' => $line->provisional_unit_cost, 'currency_id' => $line->currency_id,
                    'fx_rate' => $line->fx_rate, 'base_unit_cost' => $line->base_unit_cost,
                    'batch_no' => $line->batch_no, 'serial_no' => $line->serial_no,
                ]);
            }

            $receipt->update([
                'number' => $this->numbers->next('goods_receipt'),
                'status' => GoodsReceiptStatus::Posted,
                'posted_at' => now(),
            ]);

            foreach ($poLines->pluck('purchase_order_id')->unique()->sort() as $orderId) {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
                $allTerminal = $order->lines()->get()->every(function (PurchaseOrderLine $poLine): bool {
                    if (BigDecimal::of($poLine->short_closed_stock_qty)->isGreaterThan(0)) {
                        return true;
                    }
                    $posted = GoodsReceiptLine::query()->where('purchase_order_line_id', $poLine->id)
                        ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted->value))
                        ->sum('stock_qty');

                    return BigDecimal::of((string) $posted)->isGreaterThanOrEqualTo(BigDecimal::of($poLine->ordered_stock_qty));
                });
                if ($allTerminal) {
                    $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()]);
                }
            }

            return $receipt->refresh()->load('lines');
        });
    }
}
