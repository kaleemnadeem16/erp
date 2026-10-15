<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueAllocation;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\PhysicalCountLineComposition;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageCountComposition;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\StockQuantity;
use Brick\Math\BigDecimal;

/**
 * Posts one grouped physical count line (ADR 0092 §2) inside PostPhysicalCount's transaction. The count never rewrites history:
 *  - equal totals: no stock movement; a new COUNTED operation, linked to the line, replaces the composition over the same layers;
 *  - fewer found: the governed issue of exactly the difference (allocator-led cost, no override), and a COUNT_VARIANCE successor whose
 *    composition is the counted one;
 *  - more found: the governed receipt of exactly the difference at the reviewer-supplied found cost, with the same kind of successor.
 * Freshness is proved by the caller before any line posts.
 */
final class PostGroupedCountLine
{
    /** Is the line's captured evidence still the truth for its scope? Returns the reason it is not, or null. */
    public function staleReason(PhysicalCountLine $line, User $actor): ?string
    {
        $scope = ['item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $line->batch_id];
        $latest = PackageOperation::query()->where($scope)
            ->whereIn('evidence_kind', PackageOperation::CHAIN)->max('id');
        $watermark = StockMove::query()->where(['item_id' => $line->item_id, 'batch_id' => $line->batch_id, 'serial_no' => null])
            ->where(fn ($q) => $q->where('to_location_id', $line->location_id)->orWhere('from_location_id', $line->location_id))->max('id');
        if (($line->package_operation_id ?? 0) !== (int) $latest || (int) ($line->package_watermark ?? 0) !== (int) $watermark) {
            return 'stock or package evidence changed since the line was captured';
        }
        if ($line->package_operation_id !== null) {
            $snapshot = app(ReplayPackageCheckpoint::class)($line->package_operation_id, $actor);
            if (! $snapshot['matches_current_stock']) {
                return 'the captured evidence no longer matches stock ('.implode(', ', $snapshot['mismatch_reasons']).')';
            }
            foreach ($snapshot['composition'] as $row) {
                if ($row['availability_evidence'] !== 'USABLE') {
                    return 'the location holds packages under QA inspection or rejected; finish the QA dispositions first';
                }
            }
        }

        return null;
    }

    public function __invoke(PhysicalCountLine $line, ?string $foundCost, Company $company, User $actor): void
    {
        $item = Item::query()->findOrFail($line->item_id);
        $counted = $this->counted($line);
        $countedTotal = BigDecimal::of($line->counted_qty ?? '0');
        $book = BigDecimal::of($line->book_qty);
        $variance = $countedTotal->minus($book);
        if ($variance->isZero() && $book->isZero()) {
            return; // nothing on the book and nothing found: a reconciled empty scope
        }
        $batchNo = $line->batch_id === null ? null : StockBatch::query()->findOrFail($line->batch_id)->code;
        $key = 'count-line:'.$line->id;
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'line' => $line->id, 'book' => (string) $book, 'counted' => $counted, 'cost' => $foundCost], JSON_THROW_ON_ERROR));

        if ($variance->isZero()) {
            $operation = $this->write('COUNTED', null, $line, $key, $hash, $actor, null, $this->apportion($line, $counted), []);

            return;
        }
        if ($variance->isPositive()) {
            if ($foundCost === null || BigDecimal::of($foundCost)->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException("Line {$line->id} has a positive variance and requires a defensible found-stock unit cost before posting.", 'found_unit_cost');
            }
            $move = PackageWriteScope::run($item->id, fn () => app(PostInventoryMovement::class)->__invoke([
                'item_id' => $item->id, 'location_id' => $line->location_id, 'qty' => (string) $variance, 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                'source_type' => 'PHYSICAL_COUNT_LINE', 'source_id' => $line->id, 'unit_cost' => $foundCost, 'currency_id' => $company->base_currency_id,
                'fx_rate' => '1.000000000', 'base_unit_cost' => $foundCost, 'batch_no' => $batchNo, 'serial_no' => null,
            ]));
            $line->update(['found_unit_cost' => $foundCost, 'posted_stock_move_id' => $move->id]);
            $layer = StockLayer::query()->where('source_type', 'PHYSICAL_COUNT_LINE')->where('source_id', $line->id)->sole();
            $vector = [$layer->id => (string) $variance->toScale(4)];
        } else {
            $posted = PackageWriteScope::run($item->id, fn () => app(PostInventoryMovement::class)->issuePosted([
                'item_id' => $item->id, 'location_id' => $line->location_id, 'qty' => (string) $variance->abs(), 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                'source_type' => 'PHYSICAL_COUNT_LINE', 'source_id' => $line->id, 'batch_no' => $batchNo, 'serial_no' => null,
            ]));
            if ($posted->wasReplayed() || ! BigDecimal::of($posted->uncoveredQty)->isZero()) {
                throw new DomainInvariantException('A count loss must be fully covered by stock; it cannot create negative grouped stock.');
            }
            $move = $posted->move;
            $line->update(['posted_stock_move_id' => $move->id]);
            $vector = [];
            foreach ($posted->allocations as $allocation) {
                $vector[$allocation->sourceLayerId] = $allocation->qty;
            }
        }
        $this->write('COUNT_VARIANCE', $move, $line, $key, $hash, $actor, $line->package_operation_id, $this->apportion($line, $counted), $vector);
    }

    /** @return list<array{packaging_id:int|null, packaging_code:string|null, packaging_name:string|null, count:int|null, content_quantity:string|null, quantity:string}> */
    private function counted(PhysicalCountLine $line): array
    {
        $rows = [];
        foreach (PhysicalCountLineComposition::query()->where('physical_count_line_id', $line->id)->orderBy('line_no')->get() as $row) {
            $definition = $row->item_packaging_id === null ? null : ItemPackaging::query()->findOrFail($row->item_packaging_id);
            $rows[] = ['packaging_id' => $row->item_packaging_id, 'packaging_code' => $definition?->code, 'packaging_name' => $definition?->name,
                'count' => $row->whole_count, 'content_quantity' => $definition === null ? null : (string) StockQuantity::packageContent($definition->stock_quantity), 'quantity' => (string) $row->quantity];
        }

        return $rows;
    }

    /**
     * @param  list<array{packaging_id:int|null, packaging_code:string|null, packaging_name:string|null, count:int|null, content_quantity:string|null, quantity:string}>  $counted
     * @return list<array<string,mixed>>
     */
    private function apportion(PhysicalCountLine $line, array $counted): array
    {
        $layers = StockLayer::query()->where(['item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $line->batch_id, 'serial_no' => null])
            ->where('qty_remaining', '>', 0)->orderBy('received_at')->orderBy('id')->get()->mapWithKeys(fn ($layer) => [$layer->id => (string) BigDecimal::of($layer->qty_remaining)->toScale(4)])->all();

        return app(PackageCountComposition::class)($counted, $layers);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<int,string>  $vector  layer id => quantity moved
     */
    private function write(string $kind, ?StockMove $move, PhysicalCountLine $line, string $key, string $hash, User $actor, ?int $predecessor, array $rows, array $vector): PackageOperation
    {
        $watermark = $move !== null ? $move->id : (int) StockMove::query()->where(['item_id' => $line->item_id, 'batch_id' => $line->batch_id, 'serial_no' => null])
            ->where(fn ($q) => $q->where('to_location_id', $line->location_id)->orWhere('from_location_id', $line->location_id))->max('id');
        $operation = PackageOperation::query()->create([
            'item_id' => $line->item_id, 'location_id' => $line->location_id, 'batch_id' => $line->batch_id, 'request_key' => $key, 'payload_hash' => $hash,
            'evidence_kind' => $kind, 'actor_id' => $actor->id, 'reason' => 'Physical count line '.$line->id.($kind === 'COUNTED' ? ': composition recounted, quantity unchanged' : ': counted composition with a governed quantity variance'),
            'recorded_at' => now(), 'stock_move_watermark' => $watermark === 0 ? null : $watermark, 'predecessor_id' => $predecessor,
            'stock_move_id' => $move?->id, 'physical_count_line_id' => $line->id,
        ]);
        foreach ($rows as $row) {
            $state = PackageState::query()->create([
                'operation_id' => $operation->id, 'origin_state_id' => null, 'item_packaging_id' => $row['packaging_id'], 'form' => $row['form'],
                'availability' => $row['availability_evidence'], 'packaging_code' => $row['packaging_code'], 'packaging_name' => $row['packaging_name'],
                'sealed_count' => $row['sealed_count'], 'content_quantity' => $row['content_quantity'], 'stock_quantity' => $row['quantity'],
            ]);
            foreach ($row['contributions'] as $part) {
                PackageContribution::query()->create(['state_id' => $state->id, 'stock_layer_id' => $part['layer_id'], 'position' => $part['position'], 'quantity' => $part['quantity']]);
            }
        }
        foreach ($vector as $layerId => $quantity) {
            PackageIssueAllocation::query()->create(['operation_id' => $operation->id, 'stock_layer_id' => $layerId, 'quantity' => $quantity]);
        }

        return $operation;
    }
}
