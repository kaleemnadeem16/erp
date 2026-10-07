<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageIssueComposition;
use App\Modules\Inventory\Support\PackageTransferComposition;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A grouped, package-aware stock transfer between two locations (ADR 0090 §9). The caller declares what physically moves, with the same
 * draw lines as an issue (SEALED, LOOSE, UNPACK, OPEN_AND_DRAW); this posts the ordinary transfer through the sole movement boundary,
 * takes the vector it actually used — each source layer and the destination layer created for it — and writes both legs of package
 * evidence in the same transaction: TRANSFER_OUT at the source and TRANSFER_IN at the destination. Quantity stays in the ledger and
 * cost is whatever the transfer already decided.
 *
 * Whole sealed packages arrive sealed; loose quantity — including what was opened at the source for the transfer — arrives as open/loose
 * stock, merged into the destination's single loose quantity. The destination must either hold fresh, reconciled evidence (pass its
 * latest operation as destination_predecessor_id) or hold no stock at all; otherwise it is refused with "count first". Any failure in the
 * movement or either leg rolls the whole transfer back. Quantity-only items never reach this Action and transfer exactly as before.
 */
final class TransferPackagedStock
{
    public function __construct(private readonly PackageDrawPlanner $draw) {}

    /**
     * @param  array<string,mixed>  $input  source_predecessor_id, destination_predecessor_id (null when the destination holds no stock), to_location_id, request_key, reason, source_type, source_id, moved_at, draw, quantity (optional)
     * @return array{source:PackageOperation, destination:PackageOperation, move:StockMove}
     */
    public function __invoke(array $input, User $actor): array
    {
        $company = app(CompanyContext::class)->id();
        if ((int) $actor->company_id !== $company || ! $actor->can('inventory.package-state.transfer') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package transfer and view authority in the current company is required.');
        }
        $key = trim((string) ($input['request_key'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        $sourceId = $input['source_predecessor_id'] ?? null;
        $destinationId = $input['destination_predecessor_id'] ?? null;
        $toLocation = $input['to_location_id'] ?? null;
        if ($key === '' || strlen($key) > 85 || $reason === '' || ! is_int($sourceId) || $sourceId < 1 || ! is_int($toLocation)
            || ($destinationId !== null && (! is_int($destinationId) || $destinationId < 1))) {
            throw new DomainInvariantException('A request key, a reason, the source evidence and the destination location are required.');
        }
        $lines = $this->draw->parse($input['draw'] ?? []);
        $stated = isset($input['quantity']) ? $this->draw->exact((string) $input['quantity'], 'The stated quantity') : null;
        $hash = hash('sha256', json_encode([
            'actor' => $actor->id, 'source' => $sourceId, 'destination' => $destinationId, 'to' => $toLocation, 'reason' => $reason, 'lines' => $lines,
            'quantity' => $stated === null ? null : (string) $stated, 'move' => [$input['source_type'], $input['source_id']],
            'moved_at' => (string) Carbon::parse($input['moved_at'])->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $actor, $company, $key, $reason, $hash, $lines, $stated, $sourceId, $destinationId, $toLocation): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["package-checkpoint:{$company}:{$key}"]);
            $existing = PackageOperation::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->evidence_kind !== 'TRANSFER_OUT' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new DomainInvariantException('Request key already records different package evidence.');
                }

                return [
                    'source' => $existing, 'destination' => PackageOperation::query()->where('request_key', $key.'#in')->firstOrFail(),
                    'move' => StockMove::query()->findOrFail($existing->stock_move_id),
                ];
            }

            $parent = PackageOperation::query()->findOrFail($sourceId);
            if ($parent->location_id === $toLocation) {
                throw new DomainInvariantException('A transfer needs different source and destination locations.');
            }
            // Both stock locations are locked in ascending id order, as the transfer poster does, before anything is replayed.
            $locations = Location::query()->with('warehouse')->whereIn('id', [$parent->location_id, $toLocation])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ([$parent->location_id, $toLocation] as $id) {
                $location = $locations->get($id);
                if ($location === null || ! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                    throw new DomainInvariantException('A package transfer requires active stock locations and warehouses at both ends.');
                }
                WarehouseScope::requireLocation($actor, 'inventory.package-state.transfer', $id, 'transfer package-tracked stock');
            }
            $item = Item::query()->findOrFail($parent->item_id);

            $current = $this->draw->fresh($parent->id, $actor);
            $destination = $this->destination($destinationId, $parent, $toLocation, $actor);

            $prepared = $this->draw->prepare($lines, $current, $key, $reason, 'transfer', $actor);
            $current = $prepared['current'];
            $draws = $this->draw->quantify($prepared['draws'], $current['composition']);
            $total = array_reduce($draws, fn (BigDecimal $sum, array $draw) => $sum->plus($draw['quantity']), BigDecimal::zero());
            if ($stated !== null && ! $stated->isEqualTo($total)) {
                throw new DomainInvariantException('The stated quantity does not equal what the draw moves.');
            }
            $predecessor = PackageOperation::query()->findOrFail($current['operation_id']);

            $batchNo = $predecessor->batch_id === null ? null : StockBatch::query()->findOrFail($predecessor->batch_id)->code;
            $posted = app(PostInventoryMovement::class)->transferPosted([
                'item_id' => $item->id, 'from_location_id' => $predecessor->location_id, 'to_location_id' => $toLocation, 'qty' => (string) $total,
                'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'], 'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'batch_no' => $batchNo,
            ]);
            if ($posted->wasReplayed()) {
                throw new DomainInvariantException('Stock was already transferred for this source without package evidence; it cannot be attached afterwards.');
            }
            if (! BigDecimal::of($posted->uncoveredQty)->isZero()) {
                throw new DomainInvariantException('A package transfer cannot have part of its arrival consumed by negative-stock settlement.');
            }
            $vector = [];
            $incoming = [];
            foreach ($posted->allocations as $allocation) {
                if ($allocation->destinationLayerId === null) {
                    throw new DomainInvariantException('The transfer did not report its destination layers.');
                }
                $vector[$allocation->sourceLayerId] = $allocation->qty;
                $incoming[] = ['layer_id' => $allocation->destinationLayerId, 'quantity' => $allocation->qty];
            }

            $sourceRows = app(PackageIssueComposition::class)($current['composition'], $draws, $vector);
            $destinationRows = app(PackageTransferComposition::class)($destination['composition'], $current['composition'], $draws, $incoming);

            $out = $this->leg('TRANSFER_OUT', $predecessor, $predecessor->location_id, $key, $hash, $actor, $reason, $posted->move, $sourceRows, $draws, $vector);
            $in = $this->leg('TRANSFER_IN', $destination['operation'], $toLocation, $key.'#in', $hash, $actor, $reason, $posted->move, $destinationRows, $draws,
                array_column($incoming, 'quantity', 'layer_id'), $predecessor);

            return ['source' => $out->refresh(), 'destination' => $in->refresh(), 'move' => $posted->move];
        });
    }

    /**
     * The destination's fresh evidence, or an explicit statement that it holds nothing.
     *
     * @return array{operation:PackageOperation|null, composition:list<array<string,mixed>>}
     */
    private function destination(?int $operationId, PackageOperation $source, int $toLocation, User $actor): array
    {
        if ($operationId !== null) {
            $operation = PackageOperation::query()->findOrFail($operationId);
            if ($operation->location_id !== $toLocation || $operation->item_id !== $source->item_id || $operation->batch_id !== $source->batch_id) {
                throw new DomainInvariantException('The destination evidence does not belong to this item, batch and location.');
            }

            return ['operation' => $operation, 'composition' => $this->draw->fresh($operation->id, $actor)['composition']];
        }
        $holds = StockLayer::query()->where(['item_id' => $source->item_id, 'location_id' => $toLocation, 'batch_id' => $source->batch_id, 'serial_no' => null])
            ->where('qty_remaining', '>', 0)->exists();
        if ($holds) {
            throw new DomainInvariantException('The destination already holds stock without fresh package evidence: a counted checkpoint there is required first.');
        }
        if (NegativeStockShortage::query()->where('item_id', $source->item_id)->where('qty_open', '>', 0)->exists()) {
            throw new DomainInvariantException('An open stock shortage must be settled before package stock is transferred.');
        }

        return ['operation' => null, 'composition' => []];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>  $draws
     * @param  array<int,string>  $vector  layer id => quantity
     */
    private function leg(string $kind, ?PackageOperation $predecessor, int $locationId, string $key, string $hash, User $actor, string $reason, StockMove $move, array $rows, array $draws, array $vector, ?PackageOperation $scopeFrom = null): PackageOperation
    {
        $scope = $predecessor ?? $scopeFrom;
        $operation = PackageOperation::query()->create([
            'item_id' => $scope->item_id, 'location_id' => $locationId, 'batch_id' => $scope->batch_id, 'request_key' => $key,
            'payload_hash' => $kind === 'TRANSFER_OUT' ? $hash : hash('sha256', $hash.'#in'), 'evidence_kind' => $kind, 'actor_id' => $actor->id,
            'reason' => $reason, 'recorded_at' => now(), 'stock_move_watermark' => $move->id,
            'predecessor_id' => $predecessor?->id, 'stock_move_id' => $move->id,
        ]);
        foreach ($rows as $row) {
            $state = PackageState::query()->create([
                'operation_id' => $operation->id, 'origin_state_id' => $row['origin_state_id'],
                'item_packaging_id' => $row['packaging_id'], 'form' => $row['form'], 'availability' => $row['availability_evidence'],
                'packaging_code' => $row['packaging_code'], 'packaging_name' => $row['packaging_name'],
                'sealed_count' => $row['sealed_count'], 'content_quantity' => $row['content_quantity'], 'stock_quantity' => $row['quantity'],
            ]);
            foreach ($row['contributions'] as $part) {
                PackageContribution::query()->create(['state_id' => $state->id, 'stock_layer_id' => $part['layer_id'],
                    'position' => $part['position'], 'quantity' => $part['quantity']]);
            }
        }
        foreach ($draws as $number => $draw) {
            PackageIssueDraw::query()->create([
                'operation_id' => $operation->id, 'line_no' => $number + 1, 'kind' => $draw['kind'],
                'item_packaging_id' => $draw['packaging_id'], 'whole_count' => $draw['count'], 'quantity' => (string) $draw['quantity'],
            ]);
        }
        foreach ($vector as $layerId => $quantity) {
            PackageIssueAllocation::query()->create(['operation_id' => $operation->id, 'stock_layer_id' => $layerId, 'quantity' => $quantity]);
        }

        return $operation;
    }
}
