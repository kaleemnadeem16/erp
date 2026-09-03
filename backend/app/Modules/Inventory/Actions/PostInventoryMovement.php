<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockTransferDetail;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sole boundary allowed to create physical and valuation stock state.
 * This first slice implements inbound movements; issues and transfers extend
 * this same Action rather than introducing another write path.
 */
final class PostInventoryMovement
{
    /**
     * Posts an outbound issue, optionally consuming an explicit ADR 0037
     * authorisation when exact-location stock is insufficient.
     *
     * @param  array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null, override_request_id?:int|null}  $input
     */
    public function issue(array $input): StockMove
    {
        return DB::transaction(function () use ($input): StockMove {
            $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load(['valuationEntries', 'negativeStockShortage']);
            }

            $item = Item::query()->findOrFail($input['item_id']);
            // The location row is the stable lock even when this exact identity
            // has no layer yet. It closes the zero-stock issue/receipt race that
            // row-locking only existing layers cannot serialize.
            $location = Location::query()->with('warehouse')->lockForUpdate()->findOrFail($input['location_id']);
            $qty = BigDecimal::of($input['qty']);
            if ($qty->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException('Stock issue quantity must be greater than zero.', 'qty');
            }
            if (! $item->is_stocked || $item->stock_uom_id !== $input['uom_id']) {
                throw new DomainInvariantException('Stock issues require a stocked item in its pinned stock UoM.', 'uom_id');
            }
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Stock can only be issued from an active stock-holding location.', 'location_id');
            }
            if (StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $input['moved_at'])->exists()) {
                throw new DomainInvariantException('This issue conflicts with later valuation history and requires the controlled backdating workflow.', 'moved_at');
            }

            $batchNo = isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
            $serialNo = isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
            $this->validateIssueTracking($item, $qty, $batchNo, $serialNo);
            $batch = $batchNo === null ? null : StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->first();
            if ($batchNo !== null && $batch === null) {
                throw new DomainInvariantException('The requested batch is not on hand for this item.', 'batch_no');
            }

            $layers = $this->lockIssueLayers($item, $location, $batch, $serialNo);
            // A concurrent retry may have passed the unlocked check before the
            // first request committed. The layer lock serialises the ordinary
            // same-stock path; re-check before allocating or mutating anything.
            $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load(['valuationEntries', 'negativeStockShortage']);
            }
            $allocations = $this->allocateIssueLayers($layers, $qty);
            $availableQty = $allocations->reduce(
                fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']),
                BigDecimal::zero(),
            );
            $shortageQty = $qty->minus($availableQty);
            $override = $shortageQty->isGreaterThan(0)
                ? $this->authoriseShortage($input, $item, $location, $batch, $qty, $shortageQty)
                : null;
            if ($shortageQty->isZero() && isset($input['override_request_id'])) {
                $this->validateUnusedOverride($input, $item, $location, $batch, $qty);
            }
            $baseCurrencyId = Company::query()->findOrFail(app(CompanyContext::class)->id())->base_currency_id;
            if ($baseCurrencyId === null) {
                throw new DomainInvariantException('A company base currency is required before stock can be issued.', 'item_id');
            }

            $pool = $item->valuation_method === ValuationMethod::Avco ? $this->lockAvcoPool($item, $location, $batch) : null;
            if ($pool !== null && $override === null && BigDecimal::of($pool->qty_on_hand)->isLessThan($qty)) {
                throw new DomainInvariantException('Insufficient stock is available in the resolved AVCO pool.', 'qty');
            }

            $provisionalUnitCost = $shortageQty->isGreaterThan(0)
                ? $this->provisionalUnitCost($item, $location, $batch, $pool)
                : null;
            $baseAmount = $item->valuation_method === ValuationMethod::Avco
                ? ($override === null && BigDecimal::of($pool->qty_on_hand)->isEqualTo($qty)
                    ? BigDecimal::of($pool->inventory_value)
                    : $qty->multipliedBy($provisionalUnitCost ?? BigDecimal::of($pool->base_avg_unit_cost))->toScale(4, RoundingMode::HALF_UP))
                : $allocations->reduce(
                    fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))),
                    BigDecimal::zero(),
                )->plus($shortageQty->multipliedBy($provisionalUnitCost ?? BigDecimal::zero()))->toScale(4, RoundingMode::HALF_UP);
            $unitCost = $baseAmount->dividedBy($qty, 4, RoundingMode::HALF_UP);

            $move = StockMove::query()->create([
                'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => null,
                'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'],
                'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'unit_cost' => (string) $unitCost, 'currency_id' => $baseCurrencyId, 'fx_rate' => '1.000000000',
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);

            foreach ($allocations as $allocation) {
                $layer = $allocation['layer'];
                $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($allocation['qty'])]);
            }
            if ($pool !== null) {
                $newQty = BigDecimal::of($pool->qty_on_hand)->minus($qty);
                $newValue = BigDecimal::of($pool->inventory_value)->minus($baseAmount)->toScale(4, RoundingMode::HALF_UP);
                $poolRemainsPositive = $newQty->isGreaterThan(0);
                $pool->update([
                    'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                    'avg_unit_cost' => $override === null ? ($newQty->isZero() ? '0.0000' : (string) $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP)) : ($poolRemainsPositive ? (string) $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP) : $pool->last_valid_avg_unit_cost),
                    'base_avg_unit_cost' => $override === null ? ($newQty->isZero() ? '0.0000' : (string) $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP)) : ($poolRemainsPositive ? (string) $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP) : $pool->last_valid_avg_unit_cost),
                    'status' => $override !== null && ! $poolRemainsPositive ? 'FROZEN' : $pool->status,
                ]);
            }

            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock issue requires a resolved inventory account.', 'item_id');
            }
            if ($item->valuation_method === ValuationMethod::Avco) {
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool->id, 'pool_effect' => 'SOURCE_OUT', 'qty' => (string) $qty->negated(),
                    'unit_cost' => (string) $unitCost, 'amount' => (string) $baseAmount->negated(),
                    'base_amount' => (string) $baseAmount->negated(), 'account_id' => $accountId,
                ]);
            } else {
                foreach ($allocations as $allocation) {
                    $allocationAmount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                    $move->valuationEntries()->create([
                        'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $allocation['qty']->negated(),
                        'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $allocationAmount->negated(),
                        'base_amount' => (string) $allocationAmount->negated(), 'account_id' => $accountId,
                    ]);
                }
                if ($shortageQty->isGreaterThan(0)) {
                    $shortageAmount = $shortageQty->multipliedBy($provisionalUnitCost)->toScale(4, RoundingMode::HALF_UP);
                    $move->valuationEntries()->create([
                        'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $shortageQty->negated(),
                        'unit_cost' => (string) $provisionalUnitCost, 'amount' => (string) $shortageAmount->negated(),
                        'base_amount' => (string) $shortageAmount->negated(), 'account_id' => $accountId,
                    ]);
                }
            }
            if ($override !== null) {
                NegativeStockShortage::query()->create([
                    'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batch?->id,
                    'avco_pool_id' => $pool?->id, 'qty_open' => (string) $shortageQty,
                    'provisional_unit_cost' => (string) $provisionalUnitCost, 'status' => 'OPEN',
                    'source_move_id' => $move->id, 'override_request_id' => $override->id,
                ]);
            }

            return $move->load(['valuationEntries', 'negativeStockShortage']);
        });
    }

    /**
     * @param  array{item_id:int, from_location_id:int, to_location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null}  $input
     */
    public function transfer(array $input): StockMove
    {
        return DB::transaction(function () use ($input): StockMove {
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
            $batchNo = isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
            $serialNo = isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
            $this->validateIssueTracking($item, $qty, $batchNo, $serialNo);
            $batch = $batchNo === null ? null : StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->first();
            if ($batchNo !== null && $batch === null) {
                throw new DomainInvariantException('The requested batch is not on hand for this item.', 'batch_no');
            }
            $layers = $this->lockIssueLayers($item, $source, $batch, $serialNo);
            $allocations = $this->allocateIssueLayers($layers, $qty);
            $available = $allocations->reduce(fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']), BigDecimal::zero());
            if (! $available->isEqualTo($qty)) {
                throw new DomainInvariantException('A transfer cannot create negative stock at its physical source.', 'qty');
            }
            $company = Company::query()->findOrFail(app(CompanyContext::class)->id());
            if ($company->base_currency_id === null) {
                throw new DomainInvariantException('A company base currency is required before stock can be transferred.', 'item_id');
            }
            $sourcePool = $item->valuation_method === ValuationMethod::Avco ? $this->lockAvcoPool($item, $source, $batch) : null;
            $destinationPool = $item->valuation_method === ValuationMethod::Avco ? $this->lockAvcoPool($item, $destination, $batch) : null;
            $provisional = $sourcePool !== null && BigDecimal::of($sourcePool->qty_on_hand)->isLessThanOrEqualTo(0);
            $unitCost = $sourcePool !== null
                ? BigDecimal::of($provisional ? $sourcePool->last_valid_avg_unit_cost : $sourcePool->base_avg_unit_cost)
                : $allocations->reduce(fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))), BigDecimal::zero())->dividedBy($qty, 4, RoundingMode::HALF_UP);
            if ($unitCost->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException('No defensible transfer cost exists.', 'item_id');
            }
            $baseAmount = $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);
            $settlements = $this->allocateInboundSettlements($item, $destination, $batch, $qty);
            $settledQty = $settlements->reduce(fn (BigDecimal $total, array $settlement): BigDecimal => $total->plus($settlement['qty']), BigDecimal::zero());
            $repair = $settlements->reduce(fn (BigDecimal $total, array $settlement): BigDecimal => $total->plus(BigDecimal::of($settlement['shortage']->provisional_unit_cost)->minus($unitCost)->multipliedBy($settlement['qty'])->toScale(4, RoundingMode::HALF_UP)), BigDecimal::zero());

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
                    'received_at' => $input['moved_at'], 'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                    'source_line_no' => $index + 1, 'batch_id' => $batch?->id, 'serial_no' => $serialNo,
                ]);
            }
            $this->reduceTransferredDestinationLayers($move, $settledQty);
            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock transfer requires a resolved inventory account.', 'item_id');
            }
            $this->postTransferValuation($move, $allocations, $sourcePool, $destinationPool, $qty, $unitCost, $baseAmount, $accountId);
            $this->updateTransferPools($sourcePool, $destinationPool, $qty, $baseAmount, $repair);
            StockTransferDetail::query()->create([
                'stock_move_id' => $move->id, 'source_avco_pool_id' => $sourcePool?->id,
                'destination_avco_pool_id' => $destinationPool?->id, 'is_provisional' => $provisional,
            ]);
            $this->recordTransferSettlements($move, $settlements, $unitCost, $accountId, $destinationPool);

            return $move->load(['valuationEntries', 'transferDetail']);
        });
    }

    /**
     * @param  array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, unit_cost:string, currency_id:int, fx_rate:string, base_unit_cost:string, batch_no?:string|null, serial_no?:string|null, amount?:string, base_amount?:string}  $input
     *
     * amount/base_amount are optional pinned ledger totals. When the caller has an
     * authoritative total that isn't exactly qty × unit_cost — e.g. a UoM-converted
     * receipt, where unit_cost is a rounded per-stock-unit figure and re-deriving the
     * total from it would lose a rounding residual — pass it explicitly here rather
     * than letting it be silently recomputed. Defaults to qty × unit_cost.
     */
    public function __invoke(array $input): StockMove
    {
        return DB::transaction(function () use ($input): StockMove {
            $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load('valuationEntry');
            }

            $item = Item::query()->findOrFail($input['item_id']);
            if (! StockMove::query()->where('item_id', $item->id)->exists()) {
                // Serialise only first-use policy locking. Established independent
                // AVCO pools must remain able to post concurrently per ADR 0039.
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
            }
            $location = Location::query()->with('warehouse')->lockForUpdate()->findOrFail($input['location_id']);
            Currency::query()->findOrFail($input['currency_id']);
            if (! $item->is_stocked || $item->stock_uom_id !== $input['uom_id']) {
                throw new DomainInvariantException('Stock receipts require a stocked item in its pinned stock UoM.', 'uom_id');
            }
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Stock can only be received into an active stock-holding location.', 'location_id');
            }
            if (StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $input['moved_at'])->exists()) {
                throw new DomainInvariantException('This receipt conflicts with later valuation history and requires the controlled backdating workflow.', 'moved_at');
            }

            $qty = BigDecimal::of($input['qty']);
            $unitCost = BigDecimal::of($input['unit_cost']);
            $baseUnitCost = BigDecimal::of($input['base_unit_cost']);
            $batchNo = isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
            $serialNo = isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
            $this->validateTracking($item, $qty, $batchNo, $serialNo);

            $batch = $batchNo === null ? null : $this->resolveBatch($item, $batchNo);
            $settlements = $this->allocateInboundSettlements($item, $location, $batch, $qty);
            $settledQty = $settlements->reduce(
                fn (BigDecimal $total, array $settlement): BigDecimal => $total->plus($settlement['qty']),
                BigDecimal::zero(),
            );
            $pool = $item->valuation_method === ValuationMethod::Avco
                ? $this->lockAvcoPool($item, $location, $batch)
                : null;
            if ($pool !== null && $pool->getRawOriginal('status') === AvcoPoolStatus::Frozen->value && $settlements->isEmpty()) {
                throw new DomainInvariantException('Inbound stock for a frozen AVCO pool requires the negative-shortage settlement workflow.', 'location_id');
            }

            $move = StockMove::query()->create([
                'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
                'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'],
                'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'unit_cost' => (string) $unitCost, 'currency_id' => $input['currency_id'], 'fx_rate' => $input['fx_rate'],
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);

            StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id,
                'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty->minus($settledQty),
                'unit_cost' => (string) $unitCost, 'currency_id' => $input['currency_id'],
                'fx_rate' => $input['fx_rate'], 'base_unit_cost' => (string) $baseUnitCost,
                'received_at' => $input['moved_at'], 'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);

            $transactionAmount = isset($input['amount'])
                ? BigDecimal::of($input['amount'])
                : $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);
            $baseAmount = isset($input['base_amount'])
                ? BigDecimal::of($input['base_amount'])
                : $qty->multipliedBy($baseUnitCost)->toScale(4, RoundingMode::HALF_UP);
            $baseAdjustment = $settlements->reduce(
                fn (BigDecimal $total, array $settlement): BigDecimal => $total->plus(
                    BigDecimal::of($settlement['shortage']->provisional_unit_cost)->minus($baseUnitCost)
                        ->multipliedBy($settlement['qty'])->toScale(4, RoundingMode::HALF_UP),
                ),
                BigDecimal::zero(),
            )->toScale(4, RoundingMode::HALF_UP);
            if ($pool !== null) {
                $newQty = BigDecimal::of($pool->qty_on_hand)->plus($qty);
                $newValue = BigDecimal::of($pool->inventory_value)->plus($baseAmount)->plus($baseAdjustment)->toScale(4, RoundingMode::HALF_UP);
                $newAverage = $newQty->isGreaterThan(0)
                    ? $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP)
                    : BigDecimal::of($pool->last_valid_avg_unit_cost);
                $pool->update([
                    'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                    'avg_unit_cost' => (string) $newAverage, 'base_avg_unit_cost' => (string) $newAverage,
                    'last_valid_avg_unit_cost' => $newQty->isGreaterThan(0) ? (string) $newAverage : $pool->last_valid_avg_unit_cost,
                    'status' => $newQty->isGreaterThan(0) ? 'ACTIVE' : 'FROZEN',
                ]);
            }

            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
            }
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'DESTINATION_IN',
                'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
                'amount' => (string) $transactionAmount, 'base_amount' => (string) $baseAmount,
                'account_id' => $accountId,
            ]);

            foreach ($settlements as $settlement) {
                $shortage = $settlement['shortage'];
                $settlementQty = $settlement['qty'];
                $provisionalBaseCost = BigDecimal::of($shortage->provisional_unit_cost);
                $baseDifference = $baseUnitCost->minus($provisionalBaseCost);
                $baseRepair = $provisionalBaseCost->minus($baseUnitCost)->multipliedBy($settlementQty)->toScale(4, RoundingMode::HALF_UP);
                $provisionalTransactionCost = $provisionalBaseCost->dividedBy(BigDecimal::of($input['fx_rate']), 4, RoundingMode::HALF_UP);
                $transactionDifference = $unitCost->minus($provisionalTransactionCost);
                $transactionRepair = $provisionalTransactionCost->minus($unitCost)->multipliedBy($settlementQty)->toScale(4, RoundingMode::HALF_UP);
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'VALUATION_ADJUSTMENT',
                    'qty' => '0.0000', 'unit_cost' => (string) $transactionDifference->abs(),
                    'amount' => (string) $transactionRepair, 'base_amount' => (string) $baseRepair,
                    'account_id' => $accountId,
                ]);
                $newOpenQty = BigDecimal::of($shortage->qty_open)->minus($settlementQty);
                $shortage->update([
                    'qty_open' => (string) $newOpenQty,
                    'status' => $newOpenQty->isZero() ? 'SETTLED' : 'PARTIALLY_SETTLED',
                    'settled_at' => $newOpenQty->isZero() ? now() : null,
                ]);
                $shortage->settlements()->create([
                    'inbound_move_id' => $move->id, 'qty_settled' => (string) $settlementQty,
                    'provisional_unit_cost' => (string) $provisionalBaseCost,
                    'actual_unit_cost' => (string) $baseUnitCost,
                    'unit_cost_difference' => (string) $baseDifference,
                    'valuation_adjustment' => (string) $baseRepair, 'settled_at' => now(),
                ]);
            }

            return $move->load(['valuationEntry', 'valuationEntries']);
        });
    }

    /**
     * Reverses one specific prior inbound movement, identified by its own source
     * key, by its full originally-received quantity. Requires the layer it created
     * to be fully untouched (qty_remaining === qty_received) and, for AVCO items,
     * the resolved pool to still hold at least this quantity — checked and mutated
     * atomically here, not pre-checked by the caller, so multiple lines of one
     * reversal that share a pool correctly aggregate against each other rather than
     * each being validated against a stale pre-mutation snapshot.
     *
     * Deliberately narrow: exactly one known source, full original quantity only,
     * no oldest-layer selection and no negative-stock override branching. This is
     * not the general outbound/issue engine — that remains unbuilt.
     */
    public function reverseReceipt(
        string $originalSourceType,
        int $originalSourceId,
        string $reversalSourceType,
        int $reversalSourceId,
        mixed $reversedAt,
    ): StockMove {
        return DB::transaction(function () use ($originalSourceType, $originalSourceId, $reversalSourceType, $reversalSourceId, $reversedAt): StockMove {
            $existing = StockMove::query()->where('source_type', $reversalSourceType)->where('source_id', $reversalSourceId)->first();
            if ($existing !== null) {
                return $existing->load('valuationEntry');
            }

            $original = StockMove::query()->with('valuationEntry')
                ->where('source_type', $originalSourceType)->where('source_id', $originalSourceId)->first();
            if ($original === null || $original->from_location_id !== null) {
                throw new DomainInvariantException('No posted inbound movement found to reverse for the given source.', 'source_id');
            }

            $item = Item::query()->findOrFail($original->item_id);
            $location = Location::query()->lockForUpdate()->findOrFail($original->to_location_id);
            $layer = StockLayer::query()->where('source_type', $originalSourceType)->where('source_id', $originalSourceId)->lockForUpdate()->firstOrFail();
            $qty = BigDecimal::of($original->qty);
            if (! BigDecimal::of($layer->qty_remaining)->isEqualTo(BigDecimal::of($layer->qty_received))) {
                throw new DomainInvariantException('This receipt has already been partly consumed and cannot be reversed.', 'source_id');
            }

            $pool = null;
            if ($item->valuation_method === ValuationMethod::Avco) {
                $batch = $original->batch_id === null ? null : StockBatch::query()->find($original->batch_id);
                $pool = $this->lockAvcoPool($item, $location, $batch);
                if (BigDecimal::of($pool->qty_on_hand)->isLessThan($qty)) {
                    throw new DomainInvariantException('This receipt has already left its valuation pool and cannot be reversed.', 'source_id');
                }
            }

            $move = StockMove::query()->create([
                'item_id' => $item->id, 'from_location_id' => $original->to_location_id, 'to_location_id' => null,
                'qty' => (string) $qty, 'uom_id' => $original->uom_id, 'moved_at' => $reversedAt,
                'source_type' => $reversalSourceType, 'source_id' => $reversalSourceId,
                'unit_cost' => $original->unit_cost, 'currency_id' => $original->currency_id, 'fx_rate' => $original->fx_rate,
                'batch_id' => $original->batch_id, 'serial_no' => $original->serial_no,
            ]);

            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($qty)]);

            if ($pool !== null) {
                $newQty = BigDecimal::of($pool->qty_on_hand)->minus($qty);
                $newValue = BigDecimal::of($pool->inventory_value)->minus(BigDecimal::of($original->valuationEntry->base_amount))->toScale(4, RoundingMode::HALF_UP);
                // A clean full reversal reaching exactly zero is an ordinary drawdown,
                // not an ADR 0037 negative-stock excursion — never FREEZE for this, and
                // never overwrite last_valid_avg_unit_cost, whose whole purpose is
                // surviving zero/negative periods for later provisional costing.
                $newAverage = $newQty->isZero() ? BigDecimal::zero() : $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP);
                $pool->update([
                    'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                    'avg_unit_cost' => (string) $newAverage, 'base_avg_unit_cost' => (string) $newAverage,
                    'last_valid_avg_unit_cost' => $newQty->isZero() ? $pool->last_valid_avg_unit_cost : (string) $newAverage,
                ]);
            }

            $move->valuationEntry()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'SOURCE_OUT',
                'qty' => (string) $qty->negated(), 'unit_cost' => $original->valuationEntry->unit_cost,
                'amount' => (string) BigDecimal::of($original->valuationEntry->amount)->negated(),
                'base_amount' => (string) BigDecimal::of($original->valuationEntry->base_amount)->negated(),
                'account_id' => $original->valuationEntry->account_id,
            ]);

            return $move->load('valuationEntry');
        });
    }

    private function validateIssueTracking(Item $item, BigDecimal $qty, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked issues cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked issues require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || $batchNo !== null || ! $qty->isEqualTo(1))) {
            throw new DomainInvariantException('Serial-tracked issues require exactly one unit and one serial.', 'serial_no');
        }
        if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
            throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'item_id');
        }
    }

    /** @return Collection<int, StockLayer> */
    private function lockIssueLayers(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo): Collection
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
    private function allocateIssueLayers(Collection $layers, BigDecimal $required): Collection
    {
        $remaining = $required;
        $allocations = collect();
        foreach ($layers as $layer) {
            $available = $this->issuableLayerQuantity($layer);
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

    private function issuableLayerQuantity(StockLayer $layer): BigDecimal
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

    /**
     * @param  array{source_type:string, source_id:int, override_request_id?:int|null}  $input
     */
    private function authoriseShortage(array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty, BigDecimal $shortageQty): NegativeStockOverrideRequest
    {
        if (! isset($input['override_request_id'])) {
            throw new DomainInvariantException('Insufficient available stock requires an authorised negative-stock override.', 'qty');
        }

        $request = NegativeStockOverrideRequest::query()->with('approval')->lockForUpdate()->findOrFail($input['override_request_id']);
        $this->validateOverrideIdentity($request, $input, $item, $location, $batch, $qty);
        if ($shortageQty->isGreaterThan(BigDecimal::of($request->shortage_qty))) {
            throw new DomainInvariantException('The current shortage is worse than the authorised shortage.', 'qty');
        }
        if ($request->authorisation_mode === NegativeStockAuthorisationMode::PriorApproval
            && $request->approval?->decision !== NegativeStockApprovalDecision::Approved) {
            throw new DomainInvariantException('This negative-stock request has not been approved.', 'override_request_id');
        }
        if ($request->shortage()->lockForUpdate()->exists()) {
            throw new DomainInvariantException('This negative-stock override has already been consumed.', 'override_request_id');
        }
        if ($this->hasOpenShortage($item, $location, $batch, lock: true)) {
            throw new DomainInvariantException('An existing shortage at this exact stock identity must be settled before it can deepen.', 'qty');
        }

        return $request;
    }

    /**
     * @param  array{source_type:string, source_id:int, override_request_id?:int|null}  $input
     */
    private function validateUnusedOverride(array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty): void
    {
        $request = NegativeStockOverrideRequest::query()->lockForUpdate()->findOrFail($input['override_request_id']);
        $this->validateOverrideIdentity($request, $input, $item, $location, $batch, $qty);
    }

    /** @param array{source_type:string, source_id:int} $input */
    private function validateOverrideIdentity(NegativeStockOverrideRequest $request, array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty): void
    {
        if ($request->item_id !== $item->id
            || $request->location_id !== $location->id
            || $request->batch_id !== $batch?->id
            || $request->source_type !== $input['source_type']
            || $request->source_id !== $input['source_id']
            || ! BigDecimal::of($request->requested_qty)->isEqualTo($qty)) {
            throw new DomainInvariantException('The override does not match this exact stock issue.', 'override_request_id');
        }
    }

    private function provisionalUnitCost(Item $item, Location $location, ?StockBatch $batch, ?AvcoPool $pool): BigDecimal
    {
        if ($item->valuation_method === ValuationMethod::Avco) {
            if ($pool === null) {
                throw new DomainInvariantException('The resolved AVCO pool is missing.', 'item_id');
            }
            $cost = BigDecimal::of($pool->last_valid_avg_unit_cost);
            if ($cost->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException('No defensible AVCO cost history exists for this shortage.', 'override_request_id');
            }

            return $cost;
        }

        $layer = StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->whereNull('serial_no')->orderByDesc('received_at')->orderByDesc('id')->lockForUpdate()->first();
        if ($layer === null || BigDecimal::of($layer->base_unit_cost)->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('No defensible FIFO acquisition cost exists for this shortage.', 'override_request_id');
        }

        return BigDecimal::of($layer->base_unit_cost);
    }

    /** @return Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}> */
    private function allocateInboundSettlements(Item $item, Location $location, ?StockBatch $batch, BigDecimal $inboundQty): Collection
    {
        $shortages = NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE'])
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $remaining = $inboundQty;
        $allocations = collect();
        foreach ($shortages as $shortage) {
            if ($remaining->isZero()) {
                break;
            }
            $openQty = BigDecimal::of($shortage->qty_open);
            $settledQty = $openQty->isLessThan($remaining) ? $openQty : $remaining;
            $allocations->push(['shortage' => $shortage, 'qty' => $settledQty]);
            $remaining = $remaining->minus($settledQty);
        }

        /** @var Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}> $allocations */
        return $allocations;
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
                $this->writeTransferPoolState($source, BigDecimal::of($source->qty_on_hand), BigDecimal::of($source->inventory_value)->plus($repair));
            }

            return;
        }
        $this->writeTransferPoolState($source, BigDecimal::of($source->qty_on_hand)->minus($qty), BigDecimal::of($source->inventory_value)->minus($amount));
        $this->writeTransferPoolState($destination, BigDecimal::of($destination->qty_on_hand)->plus($qty), BigDecimal::of($destination->inventory_value)->plus($amount)->plus($repair));
    }

    private function writeTransferPoolState(AvcoPool $pool, BigDecimal $qty, BigDecimal $value): void
    {
        $positive = $qty->isGreaterThan(0);
        $average = $positive ? $value->dividedBy($qty, 4, RoundingMode::HALF_UP) : BigDecimal::of($pool->last_valid_avg_unit_cost);
        $pool->update(['qty_on_hand' => (string) $qty, 'inventory_value' => (string) $value->toScale(4, RoundingMode::HALF_UP), 'avg_unit_cost' => (string) $average, 'base_avg_unit_cost' => (string) $average, 'last_valid_avg_unit_cost' => $positive ? (string) $average : $pool->last_valid_avg_unit_cost, 'status' => $positive ? 'ACTIVE' : 'FROZEN']);
    }

    /** @param Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}> $settlements */
    private function recordTransferSettlements(StockMove $move, Collection $settlements, BigDecimal $actualCost, int $accountId, ?AvcoPool $pool): void
    {
        foreach ($settlements as $settlement) {
            $shortage = $settlement['shortage'];
            $settledQty = $settlement['qty'];
            $provisional = BigDecimal::of($shortage->provisional_unit_cost);
            $repair = $provisional->minus($actualCost)->multipliedBy($settledQty)->toScale(4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create(['avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'VALUATION_ADJUSTMENT', 'qty' => '0.0000', 'unit_cost' => (string) $actualCost->minus($provisional)->abs(), 'amount' => (string) $repair, 'base_amount' => (string) $repair, 'account_id' => $accountId]);
            $open = BigDecimal::of($shortage->qty_open)->minus($settledQty);
            $shortage->update(['qty_open' => (string) $open, 'status' => $open->isZero() ? 'SETTLED' : 'PARTIALLY_SETTLED', 'settled_at' => $open->isZero() ? now() : null]);
            $shortage->settlements()->create(['inbound_move_id' => $move->id, 'qty_settled' => (string) $settledQty, 'provisional_unit_cost' => (string) $provisional, 'actual_unit_cost' => (string) $actualCost, 'unit_cost_difference' => (string) $actualCost->minus($provisional), 'valuation_adjustment' => (string) $repair, 'settled_at' => now()]);
        }
    }

    private function hasOpenShortage(Item $item, Location $location, ?StockBatch $batch, bool $lock): bool
    {
        $query = NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($builder) => $builder->whereNull('batch_id'), fn ($builder) => $builder->where('batch_id', $batch->id))
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE']);

        return ($lock ? $query->lockForUpdate() : $query)->exists();
    }

    private function validateTracking(Item $item, BigDecimal $qty, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked items cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked items require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || ! $qty->isEqualTo(1))) {
            throw new DomainInvariantException('Serial-tracked receipts require exactly one unit and one serial per line.', 'serial_no');
        }
        if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
            throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'item_id');
        }
    }

    private function lockAvcoPool(Item $item, Location $location, ?StockBatch $batch): AvcoPool
    {
        $scope = $item->avco_pool_scope;
        if ($scope === AvcoPoolScope::CompanyItemBatch && $batch === null) {
            throw new DomainInvariantException('Batch-valued AVCO requires a captured batch identity.', 'batch_no');
        }

        $identity = ['item_id' => $item->id, 'scope' => $scope->value];
        $identity['warehouse_id'] = $scope === AvcoPoolScope::WarehouseItem ? $location->warehouse_id : null;
        $identity['batch_id'] = $scope === AvcoPoolScope::CompanyItemBatch ? $batch->id : null;
        DB::table('avco_pools')->insertOrIgnore(array_merge($identity, [
            'company_id' => app(CompanyContext::class)->id(), 'qty_on_hand' => 0, 'inventory_value' => 0,
            'avg_unit_cost' => 0, 'base_avg_unit_cost' => 0, 'last_valid_avg_unit_cost' => 0,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return AvcoPool::query()->where($identity)->lockForUpdate()->firstOrFail();
    }

    private function resolveBatch(Item $item, string $batchNo): StockBatch
    {
        DB::table('stock_batches')->insertOrIgnore([
            'company_id' => app(CompanyContext::class)->id(), 'item_id' => $item->id, 'code' => $batchNo,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->firstOrFail();
    }
}
