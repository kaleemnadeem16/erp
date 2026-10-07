<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Backdating\BackdatingHorizonEvaluator;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Actions\Recalculation\RecalculationImpactBuilder;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Requests a preview for one backdated receipt, issue or transfer; never posts
 * physical stock. Receipt cost/FX are pinned inputs, while outbound costs are
 * derived by connected historical replay. The shared impact builder also
 * serves approval revalidation and manual regeneration resumption.
 */
final class RequestStockRecalculation
{
    public function __construct(
        private readonly BackdatingHorizonEvaluator $horizon,
        private readonly RecalculationImpactBuilder $impacts,
        private readonly StockIdentity $identity,
    ) {}

    /** @param array{item_id:int, location_id:int, movement_kind?:string, destination_location_id?:int, uom_id?:int, batch_no?:string|null, serial_no?:string|null, qty:string, unit_cost?:string, currency_id?:int, fx_rate?:string, base_unit_cost?:string, moved_at:mixed, source_type:string, source_id:int} $input */
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
            WarehouseScope::requireUnrestricted($actor, 'inventory.recalculation.request', 'request a stock recalculation');
            PackageWriteScope::refuse((int) $input['item_id'], 'a stock recalculation');

            $existing = RecalculationRequest::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load('impactVersions');
            }

            if (StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->exists()) {
                throw new DomainInvariantException('This source already has a posted movement; a new hypothetical movement would duplicate it.', 'source_id');
            }
            $kind = RecalculationMovementKind::tryFrom($input['movement_kind'] ?? 'RECEIPT');
            if ($kind === null) {
                throw new DomainInvariantException('Unsupported recalculation movement kind.', 'movement_kind');
            }
            $item = Item::query()->findOrFail($input['item_id']);
            $location = Location::query()->with('warehouse')->findOrFail($input['location_id']);
            $destination = isset($input['destination_location_id']) ? Location::query()->with('warehouse')->findOrFail($input['destination_location_id']) : null;
            if (($kind === RecalculationMovementKind::Transfer) !== ($destination !== null) || $destination?->id === $location->id) {
                throw new DomainInvariantException('A transfer needs a different destination; other movements cannot have one.', 'destination_location_id');
            }
            foreach (array_filter([$location, $destination]) as $place) {
                if (! $place->active || ! $place->allows_stock || ! $place->warehouse->active) {
                    throw new DomainInvariantException('Recalculation requires active stock-holding locations.', 'location_id');
                }
            }
            if (isset($input['uom_id']) && $input['uom_id'] !== $item->stock_uom_id) {
                throw new DomainInvariantException('Recalculation quantity must use the pinned stock UoM.', 'uom_id');
            }
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
                throw new DomainInvariantException('This movement does not conflict with any later valuation history — post it normally.', 'moved_at');
            }

            $boundary = $this->horizon->evaluate($company, $movedAt);
            if (! $boundary->withinHorizon) {
                throw new DomainInvariantException('The proposed date is outside the configured backdating horizon.', 'moved_at');
            }

            $qty = BigDecimal::of($input['qty']);
            if ($qty->isLessThanOrEqualTo(0) || ! $item->is_stocked) {
                throw new DomainInvariantException('Recalculation requires a stocked item and positive quantity.', 'qty');
            }
            $baseUnitCost = null;
            if ($kind === RecalculationMovementKind::Receipt) {
                foreach (['unit_cost', 'base_unit_cost', 'currency_id', 'fx_rate'] as $field) {
                    if (! isset($input[$field])) {
                        throw new DomainInvariantException('Receipt recalculation requires pinned cost and currency facts.', $field);
                    }
                }
                $baseUnitCost = BigDecimal::of($input['base_unit_cost']);
                $this->identity->validateReceiptTracking($item, $qty, $batchNo === '' ? null : $batchNo, $serialNo);
                if ($baseUnitCost->isLessThanOrEqualTo(0)) {
                    throw new DomainInvariantException('A receipt requires positive base cost.', 'base_unit_cost');
                }
            } else {
                $this->identity->validateIssueTracking($item, $qty, $batchNo === '' ? null : $batchNo, $serialNo);
                foreach (['unit_cost', 'base_unit_cost', 'currency_id', 'fx_rate'] as $field) {
                    if (array_key_exists($field, $input)) {
                        throw new DomainInvariantException('Outbound costs and currency are derived from historical stock, not supplied.', $field);
                    }
                }
                if ($company->base_currency_id === null) {
                    throw new DomainInvariantException('A company base currency is required.', 'currency_id');
                }
            }

            $request = RecalculationRequest::query()->create([
                'movement_kind' => $kind, 'destination_location_id' => $destination?->id,
                'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batch?->id, 'serial_no' => $serialNo,
                'qty' => (string) $qty, 'unit_cost' => $input['unit_cost'] ?? null, 'currency_id' => $input['currency_id'] ?? null,
                'fx_rate' => $input['fx_rate'] ?? null, 'base_unit_cost' => $baseUnitCost === null ? null : (string) $baseUnitCost, 'moved_at' => $movedAt,
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
