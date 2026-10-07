<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\PackageTrackedLevels;
use App\Modules\Inventory\Support\PackageTransferComposition;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\GoodsReceiptPackageObservation;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Internal receipt posting adapter: immutable inbound evidence, never an active balance. */
final class RecordReceiptPackageEvidence
{
    /** @var array<int, array{predecessor:int|null, composition:list<array<string,mixed>>}> evidence found fresh before the move posted, by receipt line */
    private array $pending = [];

    /**
     * For an item with grouped package tracking: checked before the receipt posts (ADR 0091 §4). The arrival must be fully described by observed
     * composition, must not be QA-held, and the scope it lands in must either hold nothing or hold stock with fresh reconciled evidence.
     */
    public function preflight(GoodsReceiptLine $line, User $actor): void
    {
        if (! PackageWriteScope::isAuthoritative($line->item_id)) {
            return;
        }
        if (! $actor->can('purchasing.goods-receipt.post') || ! $actor->can('inventory.package-state.view') || (int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('Receipt posting and package-state view authority in the current company is required for package-tracked stock.');
        }
        $line = GoodsReceiptLine::query()->with('purchaseOrderLine')->findOrFail($line->id);
        $item = Item::query()->findOrFail($line->item_id);
        if ($item->tracking === ItemTracking::Serial || $line->serial_no !== null) {
            throw new DomainInvariantException('Serial stock cannot be received into grouped package tracking.');
        }
        if ($line->purchaseOrderLine?->qa_required) {
            throw new DomainInvariantException('A QA-required line cannot be received into grouped package tracking yet.');
        }
        $observed = GoodsReceiptPackageObservation::query()->where('goods_receipt_line_id', $line->id)->get();
        if ($observed->isEmpty() || ! $observed->reduce(fn (BigDecimal $sum, $row) => $sum->plus($row->stock_quantity), BigDecimal::zero())->isEqualTo($line->stock_qty)) {
            throw new DomainInvariantException('This item uses grouped package tracking: record the observed package composition of the whole received quantity before posting.');
        }
        if (NegativeStockShortage::query()->where('item_id', $line->item_id)->where('qty_open', '>', 0)->exists()) {
            throw new DomainInvariantException('An open stock shortage must be settled before package stock is received.');
        }
        PackageTrackedLevels::require($line->item_id, $observed->pluck('item_packaging_id')->filter()->map(fn ($id) => (int) $id)->all(), 'a receipt');
        $batch = $line->batch_no === null ? null : StockBatch::query()->where('item_id', $line->item_id)->where('code', $line->batch_no)->first();
        $holds = StockLayer::query()->where(['item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $batch?->id, 'serial_no' => null])->where('qty_remaining', '>', 0)->exists();
        if (! $holds) {
            $this->pending[$line->id] = ['predecessor' => null, 'composition' => []];

            return;
        }
        $latest = PackageOperation::query()->where(['item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $batch?->id])
            ->whereIn('evidence_kind', ['COUNTED', 'OPEN', 'UNPACK', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'RECEIPT_IN', 'COUNT_VARIANCE', 'RECEIPT_REVERSAL'])->orderByDesc('id')->first();
        if ($latest === null) {
            throw new DomainInvariantException('This location already holds stock without package evidence: a counted checkpoint is required before receiving.');
        }
        $snapshot = app(PackageDrawPlanner::class)->fresh($latest->id, $actor);
        $this->pending[$line->id] = ['predecessor' => $latest->id, 'composition' => $snapshot['composition']];
    }

    /** The arrival as one more leg of the scope's evidence chain: its predecessor plus exactly what was observed. */
    private function receiptIn(GoodsReceiptLine $line, User $actor): PackageOperation
    {
        $line = GoodsReceiptLine::query()->findOrFail($line->id);
        $pending = $this->pending[$line->id] ?? null;
        $key = 'receipt-in:'.$line->id;
        $existing = PackageOperation::query()->where('request_key', $key)->first();
        if ($existing !== null && $pending === null) {
            return $existing;
        }
        if ($pending === null) {
            throw new DomainInvariantException('Package receipt evidence needs the receipt to be checked before it posts.');
        }
        $observed = GoodsReceiptPackageObservation::query()->where('goods_receipt_line_id', $line->id)->orderBy('id')->get();
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->lockForUpdate()->sole();
        $move = StockMove::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->sole();
        $total = $observed->reduce(fn (BigDecimal $sum, $row) => $sum->plus($row->stock_quantity), BigDecimal::zero());
        if (! $total->isEqualTo($layer->qty_remaining) || ! $total->isEqualTo($move->qty) || ! $total->isEqualTo($line->stock_qty)) {
            throw new DomainInvariantException('Observed receipt composition does not reconcile with the stock that arrived.');
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'line' => $line->id, 'layer' => $layer->id, 'predecessor' => $pending['predecessor'],
            'observations' => $observed->map(fn ($row) => $row->getAttributes())->all()], JSON_THROW_ON_ERROR));
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('Receipt package evidence conflicts with the recorded source.');
            }

            return $existing;
        }
        $draws = [];
        $source = [];
        foreach ($observed as $row) {
            if ($row->form === 'SEALED') {
                $pack = ItemPackaging::query()->where('item_id', $line->item_id)->findOrFail($row->item_packaging_id);
                $source[] = ['form' => 'SEALED', 'availability_evidence' => 'USABLE', 'packaging_id' => $pack->id, 'packaging_code' => $pack->code,
                    'packaging_name' => $pack->name, 'content_quantity' => (string) $row->content_quantity];
                $draws[] = ['kind' => 'SEALED', 'packaging_id' => $pack->id, 'count' => (int) $row->sealed_count, 'quantity' => BigDecimal::of($row->stock_quantity)->toScale(4)];
            } else {
                $draws[] = ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => BigDecimal::of($row->stock_quantity)->toScale(4)];
            }
        }
        $rows = app(PackageTransferComposition::class)($pending['composition'], $source, $draws, [['layer_id' => $layer->id, 'quantity' => (string) $total->toScale(4)]]);
        $operation = PackageOperation::query()->create([
            'item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $layer->batch_id, 'source_receipt_line_id' => $line->id,
            'request_key' => $key, 'payload_hash' => $hash, 'evidence_kind' => 'RECEIPT_IN', 'actor_id' => $actor->id,
            'reason' => $observed->first()->reason, 'recorded_at' => now(), 'stock_move_watermark' => $move->id,
            'predecessor_id' => $pending['predecessor'], 'stock_move_id' => $move->id,
        ]);
        foreach ($rows as $row) {
            $state = PackageState::query()->create([
                'operation_id' => $operation->id, 'origin_state_id' => $row['origin_state_id'], 'item_packaging_id' => $row['packaging_id'],
                'form' => $row['form'], 'availability' => $row['availability_evidence'], 'packaging_code' => $row['packaging_code'],
                'packaging_name' => $row['packaging_name'], 'sealed_count' => $row['sealed_count'], 'content_quantity' => $row['content_quantity'],
                'stock_quantity' => $row['quantity'],
            ]);
            foreach ($row['contributions'] as $part) {
                PackageContribution::query()->create(['state_id' => $state->id, 'stock_layer_id' => $part['layer_id'], 'position' => $part['position'], 'quantity' => $part['quantity']]);
            }
        }
        foreach ($draws as $number => $draw) {
            PackageIssueDraw::query()->create(['operation_id' => $operation->id, 'line_no' => $number + 1, 'kind' => $draw['kind'],
                'item_packaging_id' => $draw['packaging_id'], 'whole_count' => $draw['count'], 'quantity' => (string) $draw['quantity']]);
        }
        PackageIssueAllocation::query()->create(['operation_id' => $operation->id, 'stock_layer_id' => $layer->id, 'quantity' => (string) $total->toScale(4)]);
        unset($this->pending[$line->id]);

        return $operation->refresh();
    }

    public function __invoke(GoodsReceiptLine $line, User $actor): ?PackageOperation
    {
        if (! $actor->can('purchasing.goods-receipt.post') || (int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('Receipt posting authority in the current company is required.');
        }
        if (DB::transactionLevel() < 1) {
            throw new DomainInvariantException('Receipt evidence must share the stock posting transaction.');
        }
        if (PackageWriteScope::isAuthoritative($line->item_id)) {
            return $this->receiptIn($line, $actor);
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
