<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
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
use Illuminate\Support\Facades\DB;

/**
 * Executes one fully-approved, currently-unexpired RecalculationImpactVersion
 * per ADR 0040's "Approved execution lifetime" — the expected flow is
 * approve, lock, revalidate and execute. Posts the backdated receipt
 * truthfully at its physical moved_at (original movements are never
 * mutated — this is a new, additional move), then converges the AVCO pool's
 * live running average to the exact approved counterfactual. That
 * convergence *is* the correction here: AVCO tracks a running average, not
 * per-entry history, so there is nothing else to rewrite.
 *
 * Scoped to the one case this codebase can execute correctly today: an AVCO
 * receipt with no negative-stock shortage involvement for the item/location/
 * batch. FIFO/SPECIFIC execution (needs real layer re-insertion and
 * downstream consumption re-linking, not just a converged average) and
 * issue/transfer execution remain unbuilt — the same honesty this ADR slice
 * has used throughout; see docs/domain/stock-recalculation.md. The
 * append-only correction package this produces (RecalculationExecution) is
 * Inventory-only for the same reason: Sales/Job Execution/Accounting, the
 * other potential owners of a correction delta, don't exist yet, so every
 * executable case today is "remaining stock," the one slice ADR 0040 lets
 * Inventory own outright.
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
        private readonly RegenerationLimit $limit,
        private readonly AvcoPoolManager $pools,
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
            if ($request->movement_kind !== RecalculationMovementKind::Receipt) {
                throw new DomainInvariantException('Execution is only implemented for backdated receipts so far.', 'movement_kind');
            }
            $item = Item::query()->findOrFail($request->item_id);
            if ($item->valuation_method !== ValuationMethod::Avco) {
                throw new DomainInvariantException('Execution is only implemented for AVCO items so far.', 'item_id');
            }
            if (! $version->hasUnexpiredFullApproval()) {
                throw new DomainInvariantException('Execution requires every required approval to be present and currently unexpired.', 'impact_version_id');
            }

            $location = Location::query()->with('warehouse')->lockForUpdate()->findOrFail($request->location_id);
            $batch = $request->batch_id === null ? null : StockBatch::query()->findOrFail($request->batch_id);
            if (NegativeStockShortage::query()->where('item_id', $item->id)->where('location_id', $location->id)
                ->when($batch === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $batch->id))
                ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE'])->exists()) {
                throw new DomainInvariantException('Execution alongside an open negative-stock shortage is not yet supported.', 'item_id');
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

            $pool = $this->pools->lock($item, $location, $batch);
            $qty = BigDecimal::of($request->qty);
            $unitCost = BigDecimal::of($request->unit_cost);
            $baseUnitCost = BigDecimal::of($request->base_unit_cost);

            $move = StockMove::query()->create([
                'movement_kind' => 'RECEIPT',
                'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
                'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $request->moved_at,
                'source_type' => $request->source_type, 'source_id' => $request->source_id,
                'unit_cost' => (string) $unitCost, 'currency_id' => $request->currency_id, 'fx_rate' => $request->fx_rate,
                'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
            ]);
            StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id,
                'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty,
                'unit_cost' => (string) $unitCost, 'currency_id' => $request->currency_id,
                'fx_rate' => $request->fx_rate, 'base_unit_cost' => (string) $baseUnitCost,
                'received_at' => $request->moved_at, 'source_type' => $request->source_type, 'source_id' => $request->source_id,
                'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
            ]);

            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
            }
            // Two entries, auditable independently: the receipt's own true
            // economic fact, plus whatever residual the approved counterfactual
            // needs on top of it. Their base_amount sum always equals
            // valuation_adjustment, which by construction brings the pool from
            // its pre-execution value to exactly the approved counterfactual.
            $receiptBaseAmount = $qty->multipliedBy($baseUnitCost)->toScale(4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool->id, 'pool_effect' => 'DESTINATION_IN',
                'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
                'amount' => (string) $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP),
                'base_amount' => (string) $receiptBaseAmount, 'account_id' => $accountId,
            ]);
            $residualAdjustment = BigDecimal::of($attributes['valuation_adjustment'])->minus($receiptBaseAmount)->toScale(4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool->id, 'pool_effect' => 'VALUATION_ADJUSTMENT',
                'qty' => '0.0000', 'unit_cost' => '0.0000',
                'amount' => (string) $residualAdjustment, 'base_amount' => (string) $residualAdjustment, 'account_id' => $accountId,
            ]);
            $this->pools->write($pool, BigDecimal::of($attributes['counterfactual_qty_on_hand']), BigDecimal::of($attributes['counterfactual_inventory_value']));

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
