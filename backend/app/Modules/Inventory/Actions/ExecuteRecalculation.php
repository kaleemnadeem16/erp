<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Backdating\BackdatingHorizonEvaluator;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Actions\Recalculation\FifoReceiptReallocator;
use App\Modules\Inventory\Actions\Recalculation\LayerCounterfactualReplayer;
use App\Modules\Inventory\Actions\Recalculation\LayerReplayPlan;
use App\Modules\Inventory\Actions\Recalculation\OutboundRecalculationExecutor;
use App\Modules\Inventory\Actions\Recalculation\RecalculationImpactBuilder;
use App\Modules\Inventory\Actions\Recalculation\RegenerationLimit;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\RecalculationExecution;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Executes one fully-approved, currently-unexpired RecalculationImpactVersion
 * per ADR 0040's "Approved execution lifetime" — the expected flow is
 * approve, lock, revalidate and execute. Posts the backdated receipt
 * truthfully at its physical moved_at (original movements are never
 * mutated — this is a new, additional move), then reconciles live valuation
 * state to the exact approved counterfactual.
 *
 * AVCO's pool is a running average, not per-entry history, so execution
 * converges `AvcoPoolManager` state to the approved `counterfactual_*`
 * figures via one additional VALUATION_ADJUSTMENT entry.
 *
 * SPECIFIC and FIFO share layer-based storage with no pool, but their
 * execution paths differ:
 * - SPECIFIC's layer group is scoped to one exact serial. Nothing could have
 *   consumed a unit before it was received, so a new serial's group is
 *   always empty beforehand and untouched by any later event — its
 *   valuation_adjustment is provably always exactly the receipt's own value
 *   (see RequestStockRecalculationTest::
 *   test_specific_items_new_serial_computes_its_own_value_with_no_repricing_of_other_stock).
 *   Its zero-residual guard below is a defensive assertion of that proof.
 * - FIFO's layer group can hold many layers, and a backdated receipt's
 *   earlier received_at can shift later allocations. The replay plan compares
 *   exact layer quantities, independently of whether equal costs happen to
 *   make the monetary residual zero. Direct later issues are corrected by
 *   append-only ALLOCATION_REVERSAL/replacement ALLOCATION pairs. An unchanged
 *   later transfer is harmless; a transfer whose allocation changes has its
 *   own source-side draw corrected the same way, then its destination
 *   layer-component(s) superseded by freshly minted ones and the same
 *   correction cascaded into whatever the destination's own later issues or
 *   further transfers already consumed from them (see
 *   FifoReceiptReallocator) — however many hops real history contains, not
 *   capped at one.
 *
 * A backdated ISSUE or TRANSFER request itself also executes now
 * (OutboundRecalculationExecutor), whether or not its replay changes other
 * history — the same FifoReceiptReallocator correction engine handles a new
 * outbound draw exactly like a new receipt layer, just without minting one.
 * The append-only correction package this produces (RecalculationExecution)
 * is Inventory-only for the same reason as everywhere else in this slice:
 * Sales/Job Execution/Accounting, the other potential owners of a
 * correction delta, don't exist yet, so every executable case today is
 * "remaining stock," the one slice ADR 0040 lets Inventory own outright.
 *
 * Execution authority is deliberately not required to be a person distinct
 * from the requester or approvers — ADR 0040 only mandates that separation
 * for the approval decision itself, not for triggering the already-approved,
 * already-revalidated posting.
 */
final class ExecuteRecalculation
{
    public function __construct(
        private readonly RecalculationImpactBuilder $impacts,
        private readonly BackdatingHorizonEvaluator $horizon,
        private readonly RegenerationLimit $limit,
        private readonly AvcoPoolManager $pools,
        private readonly LayerCounterfactualReplayer $layerReplay,
        private readonly FifoReceiptReallocator $fifo,
        private readonly OutboundRecalculationExecutor $outbound,
    ) {}

    public function __invoke(RecalculationImpactVersion $version, User $actor): RecalculationExecution
    {
        if (! $actor->can('inventory.recalculation.execute')) {
            throw new DomainInvariantException('The actor is not permitted to execute recalculation requests.', 'executed_by');
        }

        $outcome = DB::transaction(function () use ($version, $actor): RecalculationExecution|null {
            $company = Company::query()->lockForUpdate()->findOrFail(app(CompanyContext::class)->id());
            $request = RecalculationRequest::query()->lockForUpdate()->findOrFail($version->recalculation_request_id);
            $version = RecalculationImpactVersion::query()->lockForUpdate()->with('approvals')->findOrFail($version->id);

            $existing = RecalculationExecution::query()->where('recalculation_request_id', $request->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($version->is_stale) {
                throw new DomainInvariantException("This impact version is stale — execute the request's current version instead.", 'impact_version_id');
            }
            if ($version->isRejected()) {
                throw new DomainInvariantException('A rejected impact version cannot execute.', 'impact_version_id');
            }
            $item = Item::query()->findOrFail($request->item_id);
            if (! in_array($item->valuation_method, [ValuationMethod::Avco, ValuationMethod::Specific, ValuationMethod::Fifo], true)) {
                throw new DomainInvariantException('Execution is only implemented for AVCO, FIFO and SPECIFIC items so far.', 'item_id');
            }
            if (! $version->hasUnexpiredFullApproval()) {
                throw new DomainInvariantException('Execution requires every required approval to be present and currently unexpired.', 'impact_version_id');
            }

            // ADR 0040: eligibility is snapshotted at request time so ordinary
            // clock progression cannot invalidate it, but a material policy or
            // boundary change since then must. Re-evaluating here catches a
            // tightened horizon, a newly closed period and a cutover approved
            // after the request was raised.
            if (! $this->horizon->evaluate($company, Carbon::instance($request->moved_at))->withinHorizon) {
                throw new DomainInvariantException('The proposed date is no longer within the configured backdating horizon.', 'moved_at');
            }

            // A transfer request needs both locations locked together in
            // ascending id order up front, matching TransferPoster itself —
            // a receipt never has a destination_location_id.
            $locations = $request->destination_location_id === null
                ? collect([$request->location_id => Location::query()->with('warehouse')->lockForUpdate()->findOrFail($request->location_id)])
                : Location::query()->with('warehouse')->whereIn('id', [$request->location_id, $request->destination_location_id])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $location = $locations->get($request->location_id);
            $destination = $request->destination_location_id === null ? null : $locations->get($request->destination_location_id);
            $batch = $request->batch_id === null ? null : StockBatch::query()->findOrFail($request->batch_id);
            foreach (array_filter([$location, $destination]) as $place) {
                if (NegativeStockShortage::query()->where('item_id', $item->id)->where('location_id', $place->id)
                    ->when($batch === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $batch->id))
                    ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE'])->exists()) {
                    throw new DomainInvariantException('Execution alongside an open negative-stock shortage is not yet supported.', 'item_id');
                }
            }

            // Same revalidate-before-acting precondition as DecideRecalculationApproval:
            // if the ledger changed since approval, stale-flip and require fresh review
            // rather than executing against numbers nobody actually approved.
            $attributes = $this->impacts->attributes($request, $company);
            if (! $this->impacts->matches($version, $attributes)) {
                $version->update(['is_stale' => true]);
                if ($this->limit->pending($request) !== null) {
                    return null;
                }
                $this->impacts->append($request, $attributes);

                return null;
            }

            if ($request->movement_kind !== RecalculationMovementKind::Receipt) {
                $move = $this->outbound->post($request, $item, $company, $location, $destination, $batch, $attributes);

                return RecalculationExecution::query()->create([
                    'recalculation_request_id' => $request->id, 'impact_version_id' => $version->id,
                    'stock_move_id' => $move->id, 'executed_by' => $actor->id, 'executed_at' => now(),
                ]);
            }

            $pool = $item->valuation_method === ValuationMethod::Avco ? $this->pools->lock($item, $location, $batch) : null;
            $qty = BigDecimal::of($request->qty);
            $unitCost = BigDecimal::of($request->unit_cost);
            $baseUnitCost = BigDecimal::of($request->base_unit_cost);
            $layerPlan = $item->valuation_method === ValuationMethod::Fifo
                ? $this->layerReplay->plan($item, $location, $batch, $request->serial_no, $qty, $baseUnitCost, $request->moved_at)
                : null;
            if ($layerPlan instanceof LayerReplayPlan) {
                // A changed transfer needs its destination location locked
                // too, before anything is mutated there — matching how
                // TransferPoster itself locks both sides up front.
                $transferDestinationIds = StockMove::query()
                    ->whereIn('id', $layerPlan->allocationsByMove->keys())
                    ->where('movement_kind', 'TRANSFER')->where('item_id', $item->id)
                    ->pluck('to_location_id')->filter(fn (?int $id): bool => $id !== null && $id !== $location->id)
                    ->unique()->sort()->values();
                if ($transferDestinationIds->isNotEmpty()) {
                    Location::query()->whereIn('id', $transferDestinationIds)->orderBy('id')->lockForUpdate()->get();
                }
            }

            $move = StockMove::query()->create([
                'movement_kind' => 'RECEIPT',
                'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
                'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $request->moved_at,
                'source_type' => $request->source_type, 'source_id' => $request->source_id,
                'unit_cost' => (string) $unitCost, 'currency_id' => $request->currency_id, 'fx_rate' => $request->fx_rate,
                'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
            ]);
            $newLayer = StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id,
                'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty,
                'unit_cost' => (string) $unitCost, 'currency_id' => $request->currency_id,
                'fx_rate' => $request->fx_rate, 'base_unit_cost' => (string) $baseUnitCost,
                'received_at' => $request->moved_at, 'source_type' => $request->source_type, 'source_id' => $request->source_id,
                'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
            ]);
            if ($layerPlan instanceof LayerReplayPlan) {
                $this->fifo->apply($request, $newLayer, $layerPlan);
            }

            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
            }
            // The receipt's own true economic fact plus, when nonzero, the
            // approved residual. Their base_amount sum always equals the
            // valuation adjustment and converges the live method state to the
            // approved counterfactual without rewriting an older feed row.
            $receiptBaseAmount = $qty->multipliedBy($baseUnitCost)->toScale(4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'DESTINATION_IN',
                'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
                'amount' => (string) $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP),
                'base_amount' => (string) $receiptBaseAmount, 'account_id' => $accountId,
            ]);
            $residualAdjustment = BigDecimal::of($attributes['valuation_adjustment'])->minus($receiptBaseAmount)->toScale(4, RoundingMode::HALF_UP);
            if (! $residualAdjustment->isZero()) {
                if ($pool === null && $item->valuation_method !== ValuationMethod::Fifo) {
                    // Provably unreachable for SPECIFIC; retain the assertion
                    // rather than silently dropping an impossible residual.
                    throw new DomainInvariantException('This request needs a downstream valuation correction execution does not support for this method yet.', 'item_id');
                }
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'VALUATION_ADJUSTMENT',
                    'qty' => '0.0000', 'unit_cost' => '0.0000',
                    'amount' => (string) $residualAdjustment, 'base_amount' => (string) $residualAdjustment, 'account_id' => $accountId,
                ]);
            }
            if ($pool !== null) {
                $this->pools->write($pool, BigDecimal::of($attributes['counterfactual_qty_on_hand']), BigDecimal::of($attributes['counterfactual_inventory_value']));
            }

            return RecalculationExecution::query()->create([
                'recalculation_request_id' => $request->id, 'impact_version_id' => $version->id,
                'stock_move_id' => $move->id, 'executed_by' => $actor->id, 'executed_at' => now(),
            ]);
        });

        if ($outcome === null) {
            $request = RecalculationRequest::query()->findOrFail($version->recalculation_request_id);
            $pause = $this->limit->pending($request);
            throw new DomainInvariantException($pause === null
                ? 'The impact or its risk/dependencies changed — a new version requires fresh review before execution.'
                : 'Automatic regeneration is paused for '.$pause['escalation'].'. Manual coordination is required.', 'impact_version_id');
        }

        return $outcome;
    }
}
