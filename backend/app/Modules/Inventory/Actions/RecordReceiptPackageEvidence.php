<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\GoodsReceiptPackageObservation;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Internal receipt posting adapter: immutable inbound evidence, never an active balance. */
final class RecordReceiptPackageEvidence
{
    public function __invoke(GoodsReceiptLine $line, User $actor): ?PackageOperation
    {
        if (! $actor->can('purchasing.goods-receipt.post') || (int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('Receipt posting authority in the current company is required.');
        }
        if (DB::transactionLevel() < 1) {
            throw new DomainInvariantException('Receipt evidence must share the stock posting transaction.');
        }
        $line = GoodsReceiptLine::query()->findOrFail($line->id);
        $observed = GoodsReceiptPackageObservation::query()->where('goods_receipt_line_id', $line->id)->orderBy('id')->get();
        if ($observed->isEmpty()) {
            return null; // No inference from commercial package quantities.
        }
        if ($line->goodsReceipt->status !== GoodsReceiptStatus::Draft) {
            throw new DomainInvariantException('Receipt evidence is recorded only during original posting.');
        }
        if (Item::query()->findOrFail($line->item_id)->tracking === ItemTracking::Serial) {
            throw new DomainInvariantException('Serial physical receipt evidence is not supported yet.');
        }
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->lockForUpdate()->sole();
        $move = StockMove::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->sole();
        $total = BigDecimal::zero();
        foreach ($observed as $row) {
            $total = $total->plus($row->stock_quantity);
        }
        if (! $total->isEqualTo($line->stock_qty) || ! $total->isEqualTo($layer->qty_remaining)
            || ! $total->isEqualTo($move->qty) || $layer->item_id !== $line->item_id || $layer->location_id !== $line->location_id) {
            throw new DomainInvariantException('Observed receipt evidence does not reconcile; debt settlement and stale composition require a supported adapter.');
        }
        $hash = hash('sha256', json_encode([
            'actor' => $actor->id, 'line' => $line->id, 'layer' => $layer->id,
            'observations' => $observed->map(fn ($row) => $row->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR));
        $key = 'receipt-package:'.$line->id;
        $existing = PackageOperation::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('Receipt package evidence conflicts with the recorded source.');
            }

            return $existing;
        }
        $operation = PackageOperation::query()->create([
            'item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $layer->batch_id,
            'source_receipt_line_id' => $line->id, 'request_key' => $key,
            'payload_hash' => $hash, 'evidence_kind' => 'RECEIPT', 'actor_id' => $actor->id,
            'reason' => $observed->first()->reason, 'recorded_at' => now(), 'stock_move_watermark' => $move->id,
        ]);
        $availability = $line->purchaseOrderLine->qa_required ? 'HELD' : 'USABLE';
        foreach ($observed as $row) {
            $pack = $row->item_packaging_id === null ? null : ItemPackaging::query()->where('item_id', $line->item_id)->findOrFail($row->item_packaging_id);
            $state = PackageState::query()->create([
                'operation_id' => $operation->id, 'item_packaging_id' => $pack?->id,
                'form' => $row->form, 'availability' => $availability,
                'packaging_code' => $pack?->code, 'packaging_name' => $pack?->name,
                'sealed_count' => $row->sealed_count, 'content_quantity' => $row->content_quantity,
                'stock_quantity' => $row->stock_quantity,
            ]);
            PackageContribution::query()->create([
                'state_id' => $state->id, 'stock_layer_id' => $layer->id,
                'position' => 1, 'quantity' => $row->stock_quantity,
            ]);
        }

        return $operation;
    }
}
