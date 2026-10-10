<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Costing;

use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\RejectedStockBalance;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\DispositionOutcome;
use Brick\Math\BigDecimal;

/** Read-only eligible quantities; mirrors QA release/exits without calling the posting allocator. */
final class ReadEligibleCostLayers
{
    /** @return list<array{layer: StockLayer, quantity: BigDecimal}> */
    public function __invoke(MaterialCostRequest $request): array
    {
        $layers = StockLayer::query()->with('location.warehouse')->where('item_id', $request->item->id)
            ->where('qty_remaining', '>', 0)
            ->when($request->warehouseId !== null, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $request->warehouseId)))
            ->when($request->locationId !== null, fn ($q) => $q->where('location_id', $request->locationId))
            ->when($request->batchId !== null, fn ($q) => $q->where('batch_id', $request->batchId))
            ->when($request->serialNo !== null, fn ($q) => $q->where('serial_no', $request->serialNo))
            ->orderBy('received_at')->orderBy('id')->get();
        $result = [];
        foreach ($layers as $layer) {
            $location = $layer->location;
            if (! $location->active || ! $location->warehouse->active
                || ! ($location->counts_as_usable ?? LocationType::from($location->type->value)->holdsUsableStock())) {
                continue;
            }
            $quantity = BigDecimal::of($layer->qty_remaining);
            if ($layer->source_type === 'GOODS_RECEIPT_LINE') {
                $receipt = GoodsReceiptLine::query()->with('purchaseOrderLine')->find($layer->source_id);
                if ($receipt === null) {
                    continue;
                }
                if ($receipt->purchaseOrderLine?->qa_required) {
                    $accepted = QualityDisposition::query()->where('goods_receipt_line_id', $receipt->id)
                        ->whereIn('disposition', [DispositionOutcome::Pass->value, DispositionOutcome::AcceptWithConcession->value])->sum('qty');
                    $consumed = BigDecimal::of($layer->qty_received)->minus($quantity)->minus(RejectedStockBalance::exitedFor($receipt->id));
                    $released = BigDecimal::of((string) $accepted)->minus($consumed);
                    $quantity = $released->isLessThan($quantity) ? $released : $quantity;
                }
            }
            if ($quantity->isPositive()) {
                $result[] = ['layer' => $layer, 'quantity' => $quantity];
            }
        }

        return $result;
    }
}
