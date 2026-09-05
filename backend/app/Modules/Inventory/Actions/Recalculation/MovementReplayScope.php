<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Internal to outbound recalculation: discover transfer closure, lock it, then verify discovery. */
final class MovementReplayScope
{
    public function load(RecalculationRequest $request): MovementReplayHistory
    {
        $history = $this->discover($request);
        $locationIds = $history->locations->keys()->all();
        Location::query()->whereIn('id', $locationIds)->orderBy('id')->lockForUpdate()->get();
        AvcoPool::query()->whereIn('id', $history->pools->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $fresh = $this->discover($request);
        if ($fresh->locations->keys()->all() !== $locationIds || $fresh->pools->pluck('id')->all() !== $history->pools->pluck('id')->all()) {
            throw new DomainInvariantException('Transfer dependencies changed during locking — retry the recalculation request.', 'moved_at');
        }
        $this->guard($fresh);

        return $fresh;
    }

    private function discover(RecalculationRequest $request): MovementReplayHistory
    {
        $item = Item::query()->findOrFail($request->item_id);
        $moves = StockMove::query()->where('item_id', $item->id)
            ->when($item->valuation_method !== ValuationMethod::Avco || $item->avco_pool_scope === AvcoPoolScope::CompanyItemBatch,
                fn ($q) => $q->where('batch_id', $request->batch_id))
            ->when($item->valuation_method !== ValuationMethod::Avco, fn ($q) => $q->where('serial_no', $request->serial_no))
            ->with('valuationEntries')->orderBy('moved_at')->orderBy('id')->get();
        $locationIds = $moves->pluck('from_location_id')->merge($moves->pluck('to_location_id'))
            ->push($request->location_id)->push($request->destination_location_id)->filter()->unique();
        $locations = Location::query()->whereIn('id', $locationIds)->orderBy('id')->get()->keyBy('id');
        $empty = new MovementReplayHistory($item, collect(), collect(), $locations, collect(), []);
        $keys = [$empty->key($request->location_id, $request->batch_id)];
        if ($request->destination_location_id !== null) {
            $keys[] = $empty->key($request->destination_location_id, $request->batch_id);
        }
        do {
            $previous = count($keys);
            foreach ($moves as $move) {
                if ($move->from_location_id === null || $move->to_location_id === null) {
                    continue;
                }
                $source = $empty->key($move->from_location_id, $move->batch_id);
                $destination = $empty->key($move->to_location_id, $move->batch_id);
                if (in_array($source, $keys, true) || in_array($destination, $keys, true)) {
                    $keys = array_values(array_unique([...$keys, $source, $destination]));
                }
            }
        } while (count($keys) !== $previous);
        sort($keys);
        $moves = $moves->filter(fn (StockMove $m): bool => in_array($empty->key($m->from_location_id ?? $m->to_location_id, $m->batch_id), $keys, true))->values();
        $selectedLocations = $moves->pluck('from_location_id')->merge($moves->pluck('to_location_id'))
            ->push($request->location_id)->push($request->destination_location_id)->filter()->unique();
        $locations = $locations->only($selectedLocations->all())->keyBy('id');
        $layers = StockLayer::query()->where('item_id', $item->id)->whereIn('location_id', $selectedLocations)
            ->when($item->valuation_method !== ValuationMethod::Avco || $item->avco_pool_scope === AvcoPoolScope::CompanyItemBatch, fn ($q) => $q->where('batch_id', $request->batch_id))
            ->when($item->valuation_method !== ValuationMethod::Avco, fn ($q) => $q->where('serial_no', $request->serial_no))
            ->orderBy('id')->get();
        $pools = AvcoPool::query()->where('item_id', $item->id)->orderBy('id')->get()
            ->filter(fn (AvcoPool $p): bool => in_array($empty->poolKey($p), $keys, true))->values();

        return new MovementReplayHistory($item, $moves, $layers, $locations, $pools, $keys);
    }

    private function guard(MovementReplayHistory $history): void
    {
        if (NegativeStockShortage::query()->whereIn('source_move_id', $history->moves->pluck('id'))->exists()) {
            throw new DomainInvariantException('This transfer-connected history includes unsupported shortage/settlement activity.', 'moved_at');
        }
        $receiptIds = $history->moves->where('source_type', 'GOODS_RECEIPT_LINE')->pluck('source_id');
        if (GoodsReceiptLine::query()->whereIn('id', $receiptIds)->whereHas('purchaseOrderLine', fn ($q) => $q->where('qa_required', true))->exists()) {
            throw new DomainInvariantException('Historical QA availability is not supported by movement recalculation.', 'moved_at');
        }
        if ($history->item->valuation_method !== ValuationMethod::Avco) {
            $consumed = DB::table('stock_layer_consumptions')->where('company_id', $history->item->company_id)
                ->whereIn('stock_layer_id', $history->layers->pluck('id'))->selectRaw('stock_layer_id, SUM(qty_consumed) AS qty')
                ->groupBy('stock_layer_id')->pluck('qty', 'stock_layer_id');
            foreach ($history->layers as $layer) {
                if (! BigDecimal::of($layer->qty_received)->minus($consumed->get($layer->id, '0'))->isEqualTo($layer->qty_remaining)) {
                    throw new DomainInvariantException('Incomplete layer-consumption history cannot be replayed.', 'moved_at');
                }
            }
        }
        $consumptions = StockLayerConsumption::query()->whereIn('stock_move_id', $history->moves->pluck('id'))->get()->groupBy('stock_move_id');
        foreach ($history->moves as $move) {
            if ($move->movement_kind !== 'LEGACY' || $move->from_location_id === null || $move->to_location_id !== null) {
                continue;
            }
            $rows = $consumptions->get($move->id, collect());
            $qty = $rows->reduce(fn (BigDecimal $sum, StockLayerConsumption $row): BigDecimal => $sum->plus($row->qty_consumed), BigDecimal::zero());
            if ($history->item->valuation_method === ValuationMethod::Avco || ! $qty->isEqualTo($move->qty)) {
                throw new DomainInvariantException('Legacy outbound movements need verified issue/reversal identity before connected replay.', 'moved_at');
            }
            if ($rows->every(fn (StockLayerConsumption $row): bool => $row->purpose === 'ALLOCATION')) {
                continue;
            }
            if ($rows->count() !== 1) {
                throw new DomainInvariantException('A legacy reversal must identify exactly one receipt layer.', 'moved_at');
            }
            $row = $rows->sole();
            $layer = $history->layers->firstWhere('id', $row->stock_layer_id);
            if ($layer === null) {
                throw new DomainInvariantException('The legacy reversal is missing its original layer.', 'moved_at');
            }
            $receipt = $history->moves->first(fn (StockMove $m): bool => $m->from_location_id === null
                && $m->source_type === $layer->source_type && $m->source_id === $layer->source_id);
            if ($row->purpose !== 'REVERSAL' || $receipt === null) {
                throw new DomainInvariantException('The legacy reversal has no exact recorded receipt identity.', 'moved_at');
            }
            // Read-only interpretation of immutable ledger evidence, never a historical row update.
            $move->movement_kind = 'REVERSAL';
            $move->reverses_move_id = $receipt->id;
        }
    }
}
