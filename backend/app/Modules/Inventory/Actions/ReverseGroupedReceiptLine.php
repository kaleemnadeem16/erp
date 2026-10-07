<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PackageIssueComposition;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;

/**
 * Reverses one received line of an item with grouped package tracking (ADR 0093), inside ReverseGoodsReceipt's transaction. The existing, narrow stock
 * reversal runs unchanged (whole line, full original quantity, only while the receipt's layer is untouched, AVCO pool sufficiency, the same valuation
 * entry reversed); alongside it a RECEIPT_REVERSAL successor **removes the declared physical draw** from the scope's latest evidence. It never restores
 * the pre-receipt composition and never invents state: the draw must be coverable by what is on hand now and total exactly the line's quantity.
 * Omitted, the draw defaults to what the receipt's own evidence says arrived; a receipt that predates activation has no such evidence and needs one.
 */
final class ReverseGroupedReceiptLine
{
    public function __construct(private readonly PackageDrawPlanner $draw) {}

    /**
     * @param  list<array<string,mixed>>|null  $declared  SEALED / LOOSE lines; null means the originally observed composition
     */
    public function __invoke(GoodsReceiptLine $line, ?array $declared, string $reason, mixed $reversedAt, User $actor): PackageOperation
    {
        if ((int) $actor->company_id !== app(CompanyContext::class)->id() || ! $actor->can('inventory.package-state.reverse') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package reverse and view authority in the current company is required to reverse a package-tracked receipt.');
        }
        $key = 'receipt-reversal:'.$line->id;
        $item = Item::query()->findOrFail($line->item_id);
        $receiptLeg = PackageOperation::query()->where('source_receipt_line_id', $line->id)->where('evidence_kind', 'RECEIPT_IN')->first();
        $lines = $declared !== null ? $this->draw->parse($declared) : $this->observed($receiptLeg);
        foreach ($lines as $each) {
            if (! in_array($each['kind'], ['SEALED', 'LOOSE'], true)) {
                throw new DomainInvariantException('A reversal removes whole sealed packages and loose quantity; opening is not part of it.');
            }
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'line' => $line->id, 'lines' => $lines, 'reason' => $reason], JSON_THROW_ON_ERROR));
        $existing = PackageOperation::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('This receipt line already has different reversal evidence.');
            }

            return $existing;
        }

        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->lockForUpdate()->firstOrFail();
        $location = Location::query()->lockForUpdate()->findOrFail($layer->location_id);
        if (! BigDecimal::of($layer->qty_remaining)->isEqualTo($layer->qty_received)) {
            throw new DomainInvariantException('This receipt has already been partly consumed and cannot be reversed; correct it with a package-aware issue or return and a count.', 'source_id');
        }
        WarehouseScope::requireLocation($actor, 'inventory.package-state.reverse', $location->id, 'reverse package-tracked stock');
        $predecessor = PackageOperation::query()->where(['item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id])
            ->whereIn('evidence_kind', ['COUNTED', 'OPEN', 'UNPACK', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'RECEIPT_IN', 'COUNT_VARIANCE', 'RECEIPT_REVERSAL'])->orderByDesc('id')->first();
        if ($predecessor === null) {
            throw new DomainInvariantException('This location holds package-tracked stock without package evidence: a counted checkpoint is required first.');
        }
        $current = $this->draw->fresh($predecessor->id, $actor);
        $draws = $this->draw->quantify($lines, $current['composition']);
        $total = array_reduce($draws, fn (BigDecimal $sum, array $draw) => $sum->plus($draw['quantity']), BigDecimal::zero());
        if (! $total->isEqualTo($line->stock_qty)) {
            throw new DomainInvariantException('The returned packages must add up to the whole received quantity ('.BigDecimal::of($line->stock_qty)->toScale(4).').');
        }

        $move = PackageWriteScope::run($item->id, fn () => app(PostInventoryMovement::class)->reverseReceipt(
            'GOODS_RECEIPT_LINE', $line->id, 'GOODS_RECEIPT_LINE_REVERSAL', $line->id, $reversedAt,
        ));
        $vector = [$layer->id => (string) BigDecimal::of($move->qty)->toScale(4)];
        $successor = app(PackageIssueComposition::class)($current['composition'], $draws, $vector);
        $operation = PackageOperation::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id, 'request_key' => $key, 'payload_hash' => $hash,
            'evidence_kind' => 'RECEIPT_REVERSAL', 'actor_id' => $actor->id, 'reason' => $reason, 'recorded_at' => now(),
            'stock_move_watermark' => $move->id, 'predecessor_id' => $predecessor->id, 'stock_move_id' => $move->id, 'reverses_operation_id' => $receiptLeg?->id,
        ]);
        foreach ($successor as $row) {
            $state = PackageState::query()->create([
                'operation_id' => $operation->id, 'origin_state_id' => $row['origin_state_id'], 'item_packaging_id' => $row['packaging_id'], 'form' => $row['form'],
                'availability' => $row['availability_evidence'], 'packaging_code' => $row['packaging_code'], 'packaging_name' => $row['packaging_name'],
                'sealed_count' => $row['sealed_count'], 'content_quantity' => $row['content_quantity'], 'stock_quantity' => $row['quantity'],
            ]);
            foreach ($row['contributions'] as $part) {
                PackageContribution::query()->create(['state_id' => $state->id, 'stock_layer_id' => $part['layer_id'], 'position' => $part['position'], 'quantity' => $part['quantity']]);
            }
        }
        foreach ($draws as $number => $draw) {
            PackageIssueDraw::query()->create(['operation_id' => $operation->id, 'line_no' => $number + 1, 'kind' => $draw['kind'],
                'item_packaging_id' => $draw['packaging_id'], 'whole_count' => $draw['count'], 'quantity' => (string) $draw['quantity']]);
        }
        foreach ($vector as $layerId => $quantity) {
            PackageIssueAllocation::query()->create(['operation_id' => $operation->id, 'stock_layer_id' => $layerId, 'quantity' => $quantity]);
        }

        return $operation->refresh();
    }

    /**
     * The composition the receipt's own evidence recorded as arriving, as draw lines; a receipt that predates activation has none.
     *
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>
     */
    private function observed(?PackageOperation $receiptLeg): array
    {
        if ($receiptLeg === null) {
            throw new DomainInvariantException('This receipt has no package evidence of what arrived (it predates grouped tracking): state the sealed packages and loose quantity that are going back.');
        }
        $lines = [];
        foreach (PackageIssueDraw::query()->where('operation_id', $receiptLeg->id)->orderBy('line_no')->get() as $draw) {
            $lines[] = ['kind' => $draw->kind, 'packaging_id' => $draw->item_packaging_id, 'count' => $draw->whole_count, 'quantity' => $draw->kind === 'LOOSE' ? (string) BigDecimal::of($draw->quantity)->toScale(4) : null];
        }

        return $lines;
    }
}
