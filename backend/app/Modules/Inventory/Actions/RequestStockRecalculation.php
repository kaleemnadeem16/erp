<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Backdating\BackdatingHorizonEvaluator;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Actions\Recalculation\AvcoCounterfactualReplayer;
use App\Modules\Inventory\Actions\Recalculation\ImpactPreview;
use App\Modules\Inventory\Actions\Recalculation\LayerCounterfactualReplayer;
use App\Modules\Inventory\Actions\Recalculation\RecalculationImpactBuilder;
use App\Modules\Inventory\Actions\Recalculation\RecalculationRiskClassifier;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMove;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0040's live-policy entry point for a backdated receipt that conflicts
 * with already-posted later valuation history — see
 * docs/domain/stock-recalculation.md. A separate, proactive entry point, not
 * a catch on ReceiptPoster's conflict exception: the caller who knows they
 * have a backdated receipt calls this instead of the blocked receipt, the
 * same relationship RequestNegativeStockOverride has to IssuePoster.
 *
 * AVCO uses AvcoCounterfactualReplayer (the value ledger); FIFO/SPECIFIC uses
 * LayerCounterfactualReplayer (the layer-consumption ledger) — both produce
 * the same ImpactPreview shape through the shared RecalculationImpactBuilder.
 * The resulting preview is classified NORMAL_RISK/HIGH_RISK by
 * RecalculationRiskClassifier before the first impact version is created —
 * see DecideRecalculationApproval for how that classification determines the
 * required number of approvers.
 */
final class RequestStockRecalculation
{
    public function __construct(
        private readonly BackdatingHorizonEvaluator $horizon,
        private readonly RecalculationImpactBuilder $impacts,
        private readonly StockIdentity $identity,
    ) {}

    /** @param array{item_id:int, location_id:int, batch_no?:string|null, serial_no?:string|null, qty:string, unit_cost:string, currency_id:int, fx_rate:string, base_unit_cost:string, moved_at:mixed, source_type:string, source_id:int} $input */
    public function __invoke(array $input, User $actor): RecalculationRequest
    {
        return DB::transaction(function () use ($input, $actor): RecalculationRequest {
            $company = Company::query()->lockForUpdate()->findOrFail(app(CompanyContext::class)->id());
            if (! $company->authorised_forward_recalculation_enabled) {
                throw new DomainInvariantException('Authorised forward recalculation is disabled.', 'authorised_forward_recalculation_enabled');
            }
            if (! $actor->can('inventory.recalculation.request')) {
                throw new DomainInvariantException('The actor is not permitted to request stock recalculation.', 'requested_by');
            }

            $existing = RecalculationRequest::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load('impactVersions');
            }

            $item = Item::query()->findOrFail($input['item_id']);
            $location = Location::query()->findOrFail($input['location_id']);
            $batchNo = isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
            $batch = null;
            if ($batchNo !== null && $batchNo !== '') {
                $batch = StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->first();
                if ($batch === null) {
                    throw new DomainInvariantException('The requested batch is not on hand for this item.', 'batch_no');
                }
            }
            $serialNo = isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
            $serialNo = $serialNo === '' ? null : $serialNo;

            $movedAt = Carbon::parse($input['moved_at']);
            if (! StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $movedAt)->exists()) {
                throw new DomainInvariantException('This receipt does not conflict with any later valuation history — post it normally.', 'moved_at');
            }

            $boundary = $this->horizon->evaluate($company, $movedAt);
            if (! $boundary->withinHorizon) {
                throw new DomainInvariantException('The proposed date is outside the configured backdating horizon.', 'moved_at');
            }

            $qty = BigDecimal::of($input['qty']);
            $baseUnitCost = BigDecimal::of($input['base_unit_cost']);
            $this->identity->validateReceiptTracking($item, $qty, $batchNo === '' ? null : $batchNo, $serialNo);
            if ($qty->isLessThanOrEqualTo(0) || $baseUnitCost->isLessThanOrEqualTo(0) || ! $item->is_stocked) {
                throw new DomainInvariantException('Recalculation requires a stocked item and positive receipt quantity and base cost.', 'qty');
            }

            $request = RecalculationRequest::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batch?->id, 'serial_no' => $serialNo,
                'qty' => (string) $qty, 'unit_cost' => $input['unit_cost'], 'currency_id' => $input['currency_id'],
                'fx_rate' => $input['fx_rate'], 'base_unit_cost' => (string) $baseUnitCost, 'moved_at' => $movedAt,
                'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'horizon_mode' => $boundary->mode->value, 'horizon_permitted_floor' => $boundary->permittedFloor,
                'requested_by' => $actor->id, 'requested_at' => now(),
                'review_due_at' => now()->addHours($company->recalculation_review_sla_hours),
            ]);

            $this->impacts->append($request, $this->impacts->attributes($request, $company));

            return $request->load('impactVersions');
        });
    }
}
