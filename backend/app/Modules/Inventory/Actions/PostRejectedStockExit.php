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
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PackageIssueComposition;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\RejectedStockBalance;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The shared posting core of a supplier return and a scrap (ADR 0097): rejected stock leaves for good, at the moment of posting, in one transaction.
 *
 *  - It consumes **the receipt's own layer** for exactly the quantity QA has finally rejected and not yet removed (`RejectedStockBalance`), never usable,
 *    held or pending stock, and never through the allocator (FIFO/SPECIFIC at that layer's cost, AVCO through the normal pool engine).
 *  - The immutable `rejected_stock_exits` row is the authoritative history and cap; the movement's `source_type` carries the accounting class
 *    (`SUPPLIER_RETURN` or `SCRAP_WRITE_OFF`) for the valuation feed. No journal, no supplier credit: those are Phase 6.
 *  - An item with grouped package tracking also writes a `REJECTED_EXIT` evidence successor removing the declared whole `REJECTED` sealed packages and
 *    `REJECTED` loose quantity — a sealed package leaves whole, is never opened or split, and usable/held rows are untouched. A quantity-only item
 *    declares no portion and writes no evidence.
 *  - The exit may differ from the QA decision (QA said return, the supplier says scrap locally); any quantity beyond its own kind's decided balance
 *    needs an explicit outcome-change reason, stored with the record.
 *  - Idempotent per request key: the same payload returns the original, a different payload is refused. Authority is checked here, not only at HTTP.
 */
final class PostRejectedStockExit
{
    public function __construct(private readonly PostInventoryMovement $movements, private readonly PackageDrawPlanner $draw) {}

    /**
     * @param  array{kind:string, goods_receipt_line_id:int, quantity:string, request_key:string, reason:string, outcome_change_reason?:string|null, portion?:list<array<string,mixed>>, document_type?:string|null, document_id?:int|null}  $input
     */
    public function __invoke(array $input, User $actor): RejectedStockExit
    {
        $kind = $input['kind'];
        if (! in_array($kind, [RejectedStockExit::SUPPLIER_RETURN, RejectedStockExit::SCRAP], true)) {
            throw new DomainInvariantException('A rejected-stock exit is a supplier return or a scrap.', 'kind');
        }
        $capability = $kind === RejectedStockExit::SUPPLIER_RETURN ? 'purchasing.supplier-return.post' : 'inventory.scrap.post';
        ActionAuthorization::require($actor, $capability, $kind === RejectedStockExit::SUPPLIER_RETURN ? 'post a supplier return' : 'post a scrap');
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The actor must belong to the current company.', 'actor');
        }
        $key = trim($input['request_key']);
        $reason = trim($input['reason']);
        $changeReason = trim((string) ($input['outcome_change_reason'] ?? ''));
        if ($key === '' || strlen($key) > 85 || $reason === '') {
            throw new DomainInvariantException('A request key (at most 85 characters) and a reason are required.', 'reason');
        }
        $quantity = $this->exact($input['quantity']);
        $lines = $this->draw->parse($input['portion'] ?? []);
        foreach ($lines as $each) {
            if (! in_array($each['kind'], ['SEALED', 'LOOSE'], true)) {
                throw new DomainInvariantException('A rejected-stock exit removes whole sealed packages and loose quantity; it never opens or splits a package.', 'portion');
            }
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'kind' => $kind, 'line' => (int) $input['goods_receipt_line_id'], 'quantity' => (string) $quantity,
            'reason' => $reason, 'change' => $changeReason, 'portion' => $lines], JSON_THROW_ON_ERROR));
        $existing = RejectedStockExit::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('This request key was already used for a different rejected-stock exit.', 'request_key');
            }

            return $existing;
        }

        return DB::transaction(fn (): RejectedStockExit => $this->post($input, $kind, $capability, $actor, $key, $hash, $reason, $changeReason, $quantity, $lines));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $lines
     */
    private function post(array $input, string $kind, string $capability, User $actor, string $key, string $hash, string $reason, string $changeReason, BigDecimal $quantity, array $lines): RejectedStockExit
    {
        $receiptLine = GoodsReceiptLine::query()->lockForUpdate()->findOrFail((int) $input['goods_receipt_line_id']);
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receiptLine->id)->lockForUpdate()->first();
        if ($layer === null) {
            throw new DomainInvariantException('This receipt line has no stock layer: the delivery was never posted or has been reversed.', 'goods_receipt_line_id', 'NOTHING_REJECTED_TO_EXIT');
        }
        $location = Location::query()->lockForUpdate()->findOrFail($layer->location_id);
        WarehouseScope::requireLocation($actor, $capability, $location->id, 'exit rejected stock');
        $item = Item::query()->findOrFail($receiptLine->item_id);
        // A retry racing the first request: re-check inside the lock.
        $existing = RejectedStockExit::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('This request key was already used for a different rejected-stock exit.', 'request_key');
            }

            return $existing;
        }

        $balance = RejectedStockBalance::forLine($receiptLine->id);
        if ($quantity->isGreaterThan($balance['available'])) {
            throw new DomainInvariantException('Only '.$balance['available']->toScale(4).' of this delivery is rejected and still on hand; usable, held and pending stock cannot leave this way.', 'quantity', 'NOTHING_REJECTED_TO_EXIT');
        }
        if (BigDecimal::of($layer->qty_remaining)->isLessThan($quantity)) {
            throw new DomainInvariantException('The delivery layer no longer holds that quantity.', 'quantity', 'NOTHING_REJECTED_TO_EXIT');
        }
        $own = $balance['decided'][$kind]->minus($balance['matched'][$kind]);
        $matching = $own->isNegative() ? BigDecimal::zero() : ($own->isLessThan($quantity) ? $own : $quantity);
        $differing = $quantity->minus($matching);
        if ($differing->isGreaterThan(0) && $changeReason === '') {
            throw new DomainInvariantException('The outcome differs from the QA decision: say why (for example, the supplier asked for local scrap).', 'outcome_change_reason', 'OUTCOME_CHANGE_REASON_REQUIRED');
        }

        $grouped = PackageWriteScope::isAuthoritative($item->id);
        $evidence = null;
        if ($grouped) {
            if ($lines === []) {
                throw new DomainInvariantException('This item uses grouped package tracking: state which rejected sealed packages and loose quantity leave.', 'portion', 'NEEDS_DECLARED_PORTION');
            }
            if (! $actor->can('inventory.package-state.view')) {
                throw new DomainInvariantException('Package-state view authority is required to exit rejected package-tracked stock.', 'actor');
            }
            $predecessor = PackageOperation::query()->where(['item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id])
                ->whereIn('evidence_kind', PackageOperation::CHAIN)->orderByDesc('id')->first();
            if ($predecessor === null) {
                throw new DomainInvariantException('This location holds package-tracked stock without package evidence: a counted checkpoint is required first.', null, 'NEEDS_COUNT_FIRST');
            }
            $current = $this->draw->fresh($predecessor->id, $actor);
            $drawn = $this->draw->quantify($lines, $current['composition'], 'REJECTED');
            $evidence = fn (RejectedStockExit $exit) => $this->writeEvidence($exit, $item, $location, $layer, $actor, $predecessor, $current, $drawn, (string) $quantity->toScale(4));
            $total = array_reduce($drawn, fn (BigDecimal $sum, array $row) => $sum->plus($row['quantity']), BigDecimal::zero());
            if (! $total->isEqualTo($quantity)) {
                throw new DomainInvariantException('The declared packages must add up to the quantity leaving ('.$quantity->toScale(4).').', 'portion');
            }
        } elseif ($lines !== []) {
            throw new DomainInvariantException('This item is not package-tracked: no package portion is declared.', 'portion');
        }

        $batch = $layer->batch_id === null ? null : StockBatch::query()->find($layer->batch_id);
        $sourceId = (int) hexdec(substr(hash('sha256', app(CompanyContext::class)->id().'|rejected-exit|'.$key), 0, 15));
        $movement = [
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => (string) $quantity, 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
            'source_type' => RejectedStockExit::sourceType($kind), 'source_id' => $sourceId, 'from_layer_id' => $layer->id,
            'batch_no' => $batch?->code, 'serial_no' => $layer->serial_no,
        ];
        $posted = $grouped
            ? PackageWriteScope::run($item->id, fn () => $this->movements->issueRejectedPosted($movement))
            : $this->movements->issueRejectedPosted($movement);
        if (! BigDecimal::of($posted->uncoveredQty)->isZero() || count($posted->allocations ?? []) !== 1 || $posted->allocations[0]->sourceLayerId !== $layer->id) {
            throw new DomainInvariantException('A rejected-stock exit must consume exactly its own delivery layer.');
        }

        $exit = RejectedStockExit::query()->create([
            'kind' => $kind, 'goods_receipt_line_id' => $receiptLine->id, 'stock_move_id' => $posted->move->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'batch_id' => $layer->batch_id, 'quantity' => (string) $quantity->toScale(4), 'matching_qty' => (string) $matching->toScale(4), 'differing_qty' => (string) $differing->toScale(4),
            'outcome_change_reason' => $differing->isGreaterThan(0) ? $changeReason : null, 'reason' => $reason, 'request_key' => $key, 'payload_hash' => $hash,
            'actor_id' => $actor->id, 'exited_at' => now(), 'document_type' => $input['document_type'] ?? null, 'document_id' => $input['document_id'] ?? null,
        ]);
        if ($evidence !== null) {
            $evidence($exit);
        }

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>  $drawn
     */
    private function writeEvidence(RejectedStockExit $exit, Item $item, Location $location, StockLayer $layer, User $actor, PackageOperation $predecessor, array $current, array $drawn, string $quantity): void
    {
        $successor = app(PackageIssueComposition::class)($current['composition'], $drawn, [$layer->id => $quantity], 'REJECTED');
        $operation = PackageOperation::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id, 'request_key' => 'rejected-exit:'.$exit->id, 'payload_hash' => $exit->payload_hash,
            'evidence_kind' => 'REJECTED_EXIT', 'actor_id' => $actor->id, 'reason' => $exit->reason, 'recorded_at' => now(), 'stock_move_watermark' => $exit->stock_move_id,
            'predecessor_id' => $predecessor->id, 'stock_move_id' => $exit->stock_move_id, 'rejected_stock_exit_id' => $exit->id,
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
        foreach ($drawn as $number => $line) {
            PackageIssueDraw::query()->create(['operation_id' => $operation->id, 'line_no' => $number + 1, 'kind' => $line['kind'], 'item_packaging_id' => $line['packaging_id'],
                'whole_count' => $line['count'], 'quantity' => (string) $line['quantity'], 'availability' => 'REJECTED']);
        }
        PackageIssueAllocation::query()->create(['operation_id' => $operation->id, 'stock_layer_id' => $layer->id, 'quantity' => $quantity]);
    }

    private function exact(string $quantity): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return $value->toScale(4);
            }
        } catch (\Throwable) {
            // domain error below
        }
        throw new DomainInvariantException('The quantity must be positive and no finer than the stock ledger.', 'quantity');
    }
}
