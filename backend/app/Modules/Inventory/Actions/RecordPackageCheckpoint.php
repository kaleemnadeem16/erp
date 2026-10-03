<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

/**
 * Counted evidence only: never activates tracking or changes stock/valuation.
 * Existing physical-count creation authority permits recording this checkpoint.
 * Operational open/issue/transfer adapters must not use this as a posting bypass.
 */
final class RecordPackageCheckpoint
{
    /**
     * expected_layers is the counted snapshot's positive remaining layer vector.
     * Composition rows are ordered bookkeeping cohorts, not individual vessels.
     *
     * @param array{item_id:int,location_id:int,batch_id?:int|null,request_key:string,reason:string,
     * expected_quantity:string,expected_layers:array<int,string>,
     * composition:list<array{packaging_id?:int|null,count?:int,quantity?:string}>} $input
     */
    public function __invoke(array $input, User $actor): PackageOperation
    {
        if (! $actor->can('inventory.physical_count.create')) {
            throw new DomainInvariantException('Physical-count creation authority is required to record package evidence.');
        }
        $company = app(CompanyContext::class)->id();
        if ((int) $actor->company_id !== $company) {
            throw new DomainInvariantException('The actor must belong to the current company.');
        }
        $key = trim($input['request_key']);
        $reason = trim($input['reason']);
        if ($key === '' || strlen($key) > 100 || $reason === '') {
            throw new DomainInvariantException('A request key and counted-evidence reason are required.');
        }
        $expected = $this->positive($input['expected_quantity']);
        $vector = [];
        foreach ($input['expected_layers'] as $id => $qty) {
            if (! is_int($id) || $id < 1) {
                throw new DomainInvariantException('Expected layers require positive layer identities.');
            }
            $vector[$id] = $this->positive($qty);
        }
        ksort($vector);
        $composition = [];
        foreach ($input['composition'] as $row) {
            $packaging = $row['packaging_id'] ?? null;
            if ($packaging !== null) {
                if (! is_int($packaging) || $packaging < 1 || ! isset($row['count']) || ! is_int($row['count']) || $row['count'] < 1 || isset($row['quantity'])) {
                    throw new DomainInvariantException('Sealed evidence requires a definition and whole positive count.');
                }
                $composition[] = ['packaging_id' => $packaging, 'count' => $row['count']];
            } else {
                if (isset($row['count']) || ! isset($row['quantity'])) {
                    throw new DomainInvariantException('Loose evidence requires quantity without a sealed count.');
                }
                $composition[] = ['quantity' => $this->positive($row['quantity'])];
            }
        }
        if ($composition === [] || $vector === []) {
            throw new DomainInvariantException('A positive stock snapshot and counted composition are required.');
        }
        $hash = hash('sha256', json_encode([
            'actor' => $actor->id, 'item' => $input['item_id'], 'location' => $input['location_id'],
            'batch' => $input['batch_id'] ?? null, 'reason' => $reason,
            'quantity' => $expected, 'layers' => $vector, 'composition' => $composition,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $actor, $company, $key, $reason, $expected, $vector, $composition, $hash): PackageOperation {
            // Serialises even an absent request row, including reuse at another location.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["package-checkpoint:{$company}:{$key}"]);
            $existing = PackageOperation::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new DomainInvariantException('Request key already records different package evidence.');
                }

                return $existing;
            }
            $location = Location::query()->lockForUpdate()->findOrFail($input['location_id']);
            $item = Item::query()->findOrFail($input['item_id']);
            if (! $item->is_stocked || ! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Counted evidence requires a stocked item and active stock location.');
            }
            $batchId = $input['batch_id'] ?? null;
            if ($item->tracking === ItemTracking::Serial || $item->valuation_method === ValuationMethod::Specific) {
                throw new DomainInvariantException('Serial/SPECIFIC package checkpoints are not supported yet.');
            }
            if (($item->tracking === ItemTracking::Batch) !== ($batchId !== null)) {
                throw new DomainInvariantException('The counted batch identity must match item tracking.');
            }
            if ($batchId !== null && StockBatch::query()->where('item_id', $item->id)->find($batchId) === null) {
                throw new DomainInvariantException('Batch does not belong to the counted item.');
            }
            if (NegativeStockShortage::query()->where('item_id', $item->id)->where('qty_open', '>', 0)->exists()) {
                throw new DomainInvariantException('Resolve open stock shortages before recording package evidence.');
            }
            $scope = ['item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batchId, 'serial_no' => null];
            $layers = StockLayer::query()->where($scope)->where('qty_remaining', '>', 0)
                ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
            $actual = [];
            foreach ($layers as $layer) {
                $actual[$layer->id] = $this->positive($layer->qty_remaining);
                if ($layer->source_type === 'GOODS_RECEIPT_LINE' && GoodsReceiptLine::query()->whereKey($layer->source_id)
                    ->whereHas('purchaseOrderLine', fn ($q) => $q->where('qa_required', true))->exists()) {
                    throw new DomainInvariantException('QA receipt checkpoints require a physical availability adapter; not supported yet.');
                }
            }
            ksort($actual);
            $moveScope = ['item_id' => $item->id, 'batch_id' => $batchId, 'serial_no' => null];
            $incoming = StockMove::query()->where($moveScope)->where('to_location_id', $location->id)->sum('qty');
            $outgoing = StockMove::query()->where($moveScope)->where('from_location_id', $location->id)->sum('qty');
            $ledger = BigDecimal::of((string) $incoming)->minus((string) $outgoing);
            if ($actual !== $vector || ! $ledger->isEqualTo($expected)) {
                throw new DomainInvariantException('Stock changed since the count; refresh and recount package evidence.');
            }
            $operation = PackageOperation::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batchId,
                'request_key' => $key, 'payload_hash' => $hash, 'evidence_kind' => 'COUNTED',
                'actor_id' => $actor->id, 'reason' => $reason, 'recorded_at' => now(),
            ]);
            $remaining = $layers->mapWithKeys(fn ($layer) => [$layer->id => BigDecimal::of($layer->qty_remaining)])->all();
            $total = BigDecimal::zero();
            foreach ($composition as $row) {
                $pack = isset($row['packaging_id']) ? ItemPackaging::query()->where('item_id', $item->id)->findOrFail($row['packaging_id']) : null;
                $content = $pack === null ? null : $this->positive($pack->stock_quantity);
                $qty = $pack === null ? BigDecimal::of($row['quantity']) : BigDecimal::of($content)->multipliedBy($row['count']);
                $state = PackageState::query()->create([
                    'operation_id' => $operation->id, 'item_packaging_id' => $pack?->id,
                    'form' => $pack === null ? 'OPEN_LOOSE' : 'SEALED', 'availability' => 'USABLE',
                    'packaging_code' => $pack?->code, 'packaging_name' => $pack?->name,
                    'sealed_count' => $row['count'] ?? null, 'content_quantity' => $content,
                    'stock_quantity' => (string) $qty,
                ]);
                $needed = $qty;
                $position = 0;
                foreach ($remaining as $layerId => $available) {
                    if ($needed->isZero()) {
                        break;
                    }
                    $take = $available->isLessThan($needed) ? $available : $needed;
                    if ($take->isZero()) {
                        continue;
                    }
                    PackageContribution::query()->create([
                        'state_id' => $state->id, 'stock_layer_id' => $layerId,
                        'position' => ++$position, 'quantity' => (string) $take,
                    ]);
                    $remaining[$layerId] = $available->minus($take);
                    $needed = $needed->minus($take);
                }
                if (! $needed->isZero()) {
                    throw new DomainInvariantException('Counted composition exceeds current stock.');
                }
                $total = $total->plus($qty);
            }
            if (! $total->isEqualTo($expected) || ! array_reduce($remaining, fn ($ok, $qty) => $ok && $qty->isZero(), true)) {
                throw new DomainInvariantException('Counted composition does not reconcile to all current stock layers.');
            }

            return $operation;
        });
    }

    private function positive(string $qty): string
    {
        try {
            $value = BigDecimal::of($qty)->toScale(4);
        } catch (\InvalidArgumentException|MathException $error) {
            throw new DomainInvariantException('Package quantities must be exact decimals at stock precision.');
        }
        if ($value->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('Package quantities must be positive.');
        }

        return (string) $value;
    }
}
