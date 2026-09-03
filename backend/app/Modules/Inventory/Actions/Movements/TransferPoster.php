<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockTransferDetail;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Physical transfer posting for PostInventoryMovement. Internal to the sole
 * movement boundary — never call this from outside that Action. Runs inside
 * the boundary's transaction.
 */
final class TransferPoster
{
    public function __construct(
        private readonly StockIdentity $identity,
        private readonly LayerAllocator $layers,
        private readonly AvcoPoolManager $pools,
        private readonly ShortageSettler $settler,
    ) {}

    /** @param array{item_id:int, from_location_id:int, to_location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null} $input */
    public function post(array $input): StockMove
    {
        $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
        if ($existing !== null) {
            return $existing->load(['valuationEntries', 'transferDetail']);
        }
        if ($input['from_location_id'] === $input['to_location_id']) {
            throw new DomainInvariantException('A stock transfer requires different source and destination locations.', 'to_location_id');
        }
        $item = Item::query()->findOrFail($input['item_id']);
        $locations = Location::query()->with('warehouse')->whereIn('id', [$input['from_location_id'], $input['to_location_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $locations->get($input['from_location_id']);
        $destination = $locations->get($input['to_location_id']);
        if ($source === null || $destination === null) {
            throw new DomainInvariantException('Both transfer locations must exist.', 'to_location_id');
        }
        // A concurrent retry may have passed the unlocked check before the
        // first request committed. The location locks serialise every transfer
        // over these locations; re-check before creating anything so a retry
        // returns the committed move instead of tripping the unique source key.
        $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
        if ($existing !== null) {
            return $existing->load(['valuationEntries', 'transferDetail']);
        }
        foreach ([$source, $destination] as $location) {
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Transfers require active stock-holding locations.', 'to_location_id');
            }
        }
        $qty = BigDecimal::of($input['qty']);
        if ($qty->isLessThanOrEqualTo(0) || ! $item->is_stocked || $item->stock_uom_id !== $input['uom_id']) {
            throw new DomainInvariantException('Transfers require positive stock quantity in the pinned stock UoM.', 'qty');
        }
        if (StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $input['moved_at'])->exists()) {
            throw new DomainInvariantException('This transfer conflicts with later valuation history and requires the controlled backdating workflow.', 'moved_at');
        }
        $batchNo = $this->identity->normalisedBatchNo($input);
        $serialNo = $this->identity->normalisedSerialNo($input);
        $this->identity->validateIssueTracking($item, $qty, $batchNo, $serialNo);
        $batch = $this->identity->findOnHandBatch($item, $batchNo);
        $layers = $this->layers->lockIssueLayers($item, $source, $batch, $serialNo);
        $allocations = $this->layers->allocate($layers, $qty);
        $available = $allocations->reduce(fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']), BigDecimal::zero());
        if (! $available->isEqualTo($qty)) {
            throw new DomainInvariantException('A transfer cannot create negative stock at its physical source.', 'qty');
        }
        $company = Company::query()->findOrFail(app(CompanyContext::class)->id());
        if ($company->base_currency_id === null) {
            throw new DomainInvariantException('A company base currency is required before stock can be transferred.', 'item_id');
        }
        [$sourcePool, $destinationPool] = $item->valuation_method === ValuationMethod::Avco
            ? $this->pools->lockPair($item, $source, $destination, $batch)
            : [null, null];
        // A transfer's exact source location always has full physical cover
        // for its quantity (checked above), so its value is final at post
        // time whether the wider pool is currently active or frozen —
        // last_valid_avg_unit_cost is the pool's live average when active and
        // its preserved last-known rate when frozen, so one field covers both
        // (ADR 0045: a non-positive pool never makes a physically-backed
        // transfer provisional; that state only reflects unrelated shortage
        // debt elsewhere in the pool).
        $unitCost = $sourcePool !== null
            ? BigDecimal::of($sourcePool->last_valid_avg_unit_cost)
            : $allocations->reduce(fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))), BigDecimal::zero())->dividedBy($qty, 4, RoundingMode::HALF_UP);
        if ($unitCost->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('No defensible transfer cost exists.', 'item_id');
        }
        // A full drawdown of a positive pool moves its precise remaining value,
        // so rounding can never strand a residual on the emptied source pool.
        $baseAmount = $sourcePool !== null && BigDecimal::of($sourcePool->qty_on_hand)->isEqualTo($qty)
            ? BigDecimal::of($sourcePool->inventory_value)
            : $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);
        $settlements = $this->settler->allocate($item, $destination, $batch, $qty);
        $settledQty = $settlements->reduce(fn (BigDecimal $total, array $settlement): BigDecimal => $total->plus($settlement['qty']), BigDecimal::zero());

        $move = StockMove::query()->create([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'],
            'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
            'unit_cost' => (string) $unitCost, 'currency_id' => $company->base_currency_id, 'fx_rate' => '1.000000000',
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);
        foreach ($allocations as $index => $allocation) {
            $layer = $allocation['layer'];
            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($allocation['qty'])]);
            StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $destination->id,
                'qty_received' => (string) $allocation['qty'], 'qty_remaining' => (string) $allocation['qty'],
                'unit_cost' => $layer->unit_cost, 'currency_id' => $layer->currency_id, 'fx_rate' => $layer->fx_rate,
                'base_unit_cost' => $sourcePool === null ? $layer->base_unit_cost : (string) $unitCost,
                // The source layer's original receipt time survives the move —
                // FIFO age is a property of the stock, not of its custody path.
                'received_at' => $layer->received_at, 'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'source_line_no' => $index + 1, 'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);
        }
        $this->reduceTransferredDestinationLayers($move, $settledQty);
        $accountId = $item->resolveInventoryAccountId();
        if ($accountId === null) {
            throw new DomainInvariantException('A stock transfer requires a resolved inventory account.', 'item_id');
        }
        $this->postTransferValuation($move, $allocations, $sourcePool, $destinationPool, $qty, $unitCost, $baseAmount, $accountId);
        StockTransferDetail::query()->create([
            'stock_move_id' => $move->id, 'source_avco_pool_id' => $sourcePool?->id,
            'destination_avco_pool_id' => $destinationPool?->id,
        ]);
        $repair = $this->settler->applyTransferSettlements($move, $settlements, $this->costSlices($allocations, $sourcePool, $qty, $unitCost), $destinationPool, $accountId);
        $this->updateTransferPools($sourcePool, $destinationPool, $qty, $baseAmount, $repair);

        return $move->load(['valuationEntries', 'transferDetail']);
    }

    /**
     * The incoming stock's actual costs, in the order the destination consumes
     * it: one uniform slice at pool rate for AVCO, one slice per preserved
     * source layer for FIFO/SPECIFIC.
     *
     * @param  Collection<int, array{layer:StockLayer, qty:BigDecimal}>  $allocations
     * @return Collection<int, array{qty:BigDecimal, cost:BigDecimal}>
     */
    private function costSlices(Collection $allocations, ?AvcoPool $sourcePool, BigDecimal $qty, BigDecimal $unitCost): Collection
    {
        if ($sourcePool !== null) {
            return collect([['qty' => $qty, 'cost' => $unitCost]]);
        }

        return $allocations->map(fn (array $allocation): array => [
            'qty' => $allocation['qty'], 'cost' => BigDecimal::of($allocation['layer']->base_unit_cost),
        ])->values();
    }

    private function reduceTransferredDestinationLayers(StockMove $move, BigDecimal $settledQty): void
    {
        $layers = StockLayer::query()->where('source_type', $move->source_type)->where('source_id', $move->source_id)->orderBy('source_line_no')->get();
        foreach ($layers as $layer) {
            $received = BigDecimal::of($layer->qty_received);
            $consume = $received->isLessThan($settledQty) ? $received : $settledQty;
            $layer->update(['qty_remaining' => (string) $received->minus($consume)]);
            $settledQty = $settledQty->minus($consume);
        }
    }

    /** @param Collection<int, array{layer:StockLayer, qty:BigDecimal}> $allocations */
    private function postTransferValuation(StockMove $move, Collection $allocations, ?AvcoPool $sourcePool, ?AvcoPool $destinationPool, BigDecimal $qty, BigDecimal $unitCost, BigDecimal $baseAmount, int $accountId): void
    {
        if ($sourcePool !== null) {
            foreach ([[$sourcePool, 'SOURCE_OUT', $qty->negated(), $baseAmount->negated()], [$destinationPool, 'DESTINATION_IN', $qty, $baseAmount]] as [$pool, $effect, $entryQty, $amount]) {
                $move->valuationEntries()->create(['avco_pool_id' => $pool->id, 'pool_effect' => $effect, 'qty' => (string) $entryQty, 'unit_cost' => (string) $unitCost, 'amount' => (string) $amount, 'base_amount' => (string) $amount, 'account_id' => $accountId]);
            }

            return;
        }
        foreach ($allocations as $allocation) {
            $amount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
            foreach ([[$allocation['qty']->negated(), $amount->negated()], [$allocation['qty'], $amount]] as [$entryQty, $entryAmount]) {
                $move->valuationEntries()->create(['avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $entryQty, 'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $entryAmount, 'base_amount' => (string) $entryAmount, 'account_id' => $accountId]);
            }
        }
    }

    private function updateTransferPools(?AvcoPool $source, ?AvcoPool $destination, BigDecimal $qty, BigDecimal $amount, BigDecimal $repair): void
    {
        if ($source === null || $destination === null) {
            return;
        }
        if ($source->id === $destination->id) {
            if (! $repair->isZero()) {
                $this->pools->write($source, BigDecimal::of($source->qty_on_hand), BigDecimal::of($source->inventory_value)->plus($repair));
            }

            return;
        }
        $this->pools->write($source, BigDecimal::of($source->qty_on_hand)->minus($qty), BigDecimal::of($source->inventory_value)->minus($amount));
        $this->pools->write($destination, BigDecimal::of($destination->qty_on_hand)->plus($qty), BigDecimal::of($destination->inventory_value)->plus($amount)->plus($repair));
    }
}
