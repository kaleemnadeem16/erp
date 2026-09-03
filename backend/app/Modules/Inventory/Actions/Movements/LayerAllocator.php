<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\DispositionOutcome;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Exact-location/tracking layer locking and oldest-first allocation for
 * PostInventoryMovement. Internal to the sole movement boundary — never call
 * this from outside that Action.
 */
final class LayerAllocator
{
    /** @return Collection<int, StockLayer> */
    public function lockIssueLayers(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo): Collection
    {
        return StockLayer::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->where('qty_remaining', '>', 0)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->when($serialNo === null, fn ($query) => $query->whereNull('serial_no'), fn ($query) => $query->where('serial_no', $serialNo))
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, StockLayer>  $layers
     * @return Collection<int, array{layer:StockLayer, qty:BigDecimal}>
     */
    public function allocate(Collection $layers, BigDecimal $required): Collection
    {
        $remaining = $required;
        $allocations = collect();
        foreach ($layers as $layer) {
            $available = $this->issuableQuantity($layer);
            if ($available->isLessThanOrEqualTo(0)) {
                continue;
            }
            $take = $available->isLessThan($remaining) ? $available : $remaining;
            $allocations->push(['layer' => $layer, 'qty' => $take]);
            $remaining = $remaining->minus($take);
            if ($remaining->isZero()) {
                break;
            }
        }

        /** @var Collection<int, array{layer:StockLayer, qty:BigDecimal}> $allocations */
        return $allocations;
    }

    public function issuableQuantity(StockLayer $layer): BigDecimal
    {
        if ($layer->source_type !== 'GOODS_RECEIPT_LINE') {
            return BigDecimal::of($layer->qty_remaining);
        }

        $receiptLine = GoodsReceiptLine::query()->with('purchaseOrderLine')->find($layer->source_id);
        if ($receiptLine === null || $receiptLine->purchaseOrderLine === null || ! $receiptLine->purchaseOrderLine->qa_required) {
            return BigDecimal::of($layer->qty_remaining);
        }

        $accepted = QualityDisposition::query()
            ->where('goods_receipt_line_id', $receiptLine->id)
            ->whereIn('disposition', [DispositionOutcome::Pass->value, DispositionOutcome::AcceptWithConcession->value])
            ->sum('qty');
        $alreadyConsumed = BigDecimal::of($layer->qty_received)->minus(BigDecimal::of($layer->qty_remaining));
        $available = BigDecimal::of((string) $accepted)->minus($alreadyConsumed);

        return $available->isGreaterThan(0) ? $available : BigDecimal::zero();
    }
}
