<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Numbering\GoodsReceiptNumbering;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PostGoodsReceipt
{
    public function __construct(private readonly PostInventoryMovement $postMovement, private readonly NumberingService $numbering) {}

    public function __invoke(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        if (! $actor->can('purchasing.goods-receipt.post')) {
            throw new DomainInvariantException('The actor is not permitted to post a goods receipt.', 'posted_by');
        }

        return DB::transaction(function () use ($receipt, $actor): GoodsReceipt {
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

            // Lock order headers before lines, matching ShortClosePurchaseOrderLine's
            // header-then-line order, so the two actions can't deadlock on the same PO.
            $orderIds = PurchaseOrderLine::query()->whereKey($poLineIds)->pluck('purchase_order_id')->unique()->sort()->values();
            foreach ($orderIds as $orderId) {
                PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            }

            $poLines = PurchaseOrderLine::query()->with(['purchaseOrder', 'item'])
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
                    // Divisibility is always an item(-and-UoM) rounding-precision rule,
                    // never inferred from a UoM's dimension — ADR 0050. No precision
                    // set means unrestricted, same as everywhere else this applies.
                    if ($poLine->item->quantity_precision !== null) {
                        $precision = BigDecimal::of($poLine->item->quantity_precision);
                        $maximum = $maximum->dividedBy($precision, 0, RoundingMode::FLOOR)->multipliedBy($precision);
                    }
                }
                if ($cumulative->isGreaterThan($maximum)) {
                    throw new DomainInvariantException('Cumulative receipt quantity exceeds the issued PO tolerance.', 'stock_qty');
                }
            }

            foreach ($receiptLines as $line) {
                $factor = BigDecimal::of($line->uom_factor);
                // provisional_unit_cost/base_unit_cost are priced per received UoM unit
                // (same convention as a PO line's unit_price). unit_cost/base_unit_cost
                // below rescale that to a per-stock-unit figure for display and future
                // layer costing, but for a non-divisible factor (e.g. 1 box = 3 stock
                // units) that rounded figure loses value on re-multiplication
                // (10.0000 / 3 = 3.3333, × 3 = 9.9999 instead of 10.0000). The ledger
                // amount is pinned to the exact total actually paid — received_qty ×
                // provisional_unit_cost — computed straight from the receipt, not
                // re-derived from the rounded per-unit cost.
                $unitCost = BigDecimal::of($line->provisional_unit_cost)->dividedBy($factor, 4, RoundingMode::HALF_UP);
                $baseUnitCost = BigDecimal::of($line->base_unit_cost)->dividedBy($factor, 4, RoundingMode::HALF_UP);
                $amount = BigDecimal::of($line->received_qty)->multipliedBy(BigDecimal::of($line->provisional_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                $baseAmount = $amount->multipliedBy(BigDecimal::of($line->fx_rate))->toScale(4, RoundingMode::HALF_UP);

                ($this->postMovement)([
                    'item_id' => $line->item_id, 'location_id' => $line->location_id,
                    'qty' => $line->stock_qty, 'uom_id' => $line->stock_uom_id,
                    'moved_at' => $receipt->physical_arrival_at,
                    'source_type' => 'GOODS_RECEIPT_LINE', 'source_id' => $line->id,
                    'unit_cost' => (string) $unitCost, 'currency_id' => $line->currency_id,
                    'fx_rate' => $line->fx_rate, 'base_unit_cost' => (string) $baseUnitCost,
                    'amount' => (string) $amount, 'base_amount' => (string) $baseAmount,
                    'batch_no' => $line->batch_no, 'serial_no' => $line->serial_no,
                ]);
            }

            $postedAt = CarbonImmutable::now();
            // The number is taken at the moment the receipt becomes official, by the central numbering framework (ADR 0079).
            $number = $this->numbering->allocate(GoodsReceiptNumbering::contextFor($receipt, $postedAt, $actor->id))->number;
            $receipt->update([
                'number' => $number,
                'status' => GoodsReceiptStatus::Posted,
                'posted_at' => $postedAt,
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
