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
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PackageIssueComposition;
use App\Modules\Inventory\Support\PostedMovement;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A grouped, package-aware stock issue (ADR 0090). The caller declares what was physically taken; this posts the ordinary stock issue
 * through the sole movement boundary, takes the allocation vector it actually used, and writes the successor package evidence —
 * all in one transaction. Quantity stays authoritative in the ledger, packaging only explains its physical form, and cost is
 * whatever the allocator decides: nothing here reads or writes a price.
 *
 * It needs fresh, reconciled predecessor evidence (a counted checkpoint or a later saved successor). Without one the stock is not
 * authoritative in grouped form and the call is refused with the reason, so a quantity-only item never reaches this Action and
 * keeps posting exactly as before. Tracking activation, and the refusal of scalar issues once it is active, are separate slices.
 *
 * Draw lines, applied in order to a running composition:
 *   UNPACK         {packaging_id, count}               replace outer sealed packages with their inner sealed packages
 *   SEALED         {packaging_id, count}               take whole sealed packages
 *   LOOSE          {quantity}                          take from the single open/loose quantity
 *   OPEN_AND_DRAW  {packaging_id, count, quantity}     open whole sealed packages and take a quantity from the result
 * Opening happens as its own recorded OPEN/UNPACK step before the issue (and needs inventory.package-state.open).
 */
final class IssuePackagedStock
{
    /**
     * @param  array<string,mixed>  $input  predecessor_id, request_key, reason, source_type, source_id, moved_at, draw (list of lines), quantity (optional)
     * @return array{operation:PackageOperation, move:StockMove}
     */
    public function __invoke(array $input, User $actor): array
    {
        $company = app(CompanyContext::class)->id();
        if ((int) $actor->company_id !== $company || ! $actor->can('inventory.package-state.issue') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package issue and view authority in the current company is required.');
        }
        $key = trim((string) ($input['request_key'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        $predecessorId = $input['predecessor_id'] ?? null;
        if ($key === '' || strlen($key) > 100 || $reason === '' || ! is_int($predecessorId) || $predecessorId < 1) {
            throw new DomainInvariantException('A request key, a reason and the current package evidence are required.');
        }
        $lines = $this->lines($input['draw'] ?? []);
        $stated = isset($input['quantity']) ? $this->exact((string) $input['quantity'], 'The stated quantity') : null;
        $hash = hash('sha256', json_encode([
            'actor' => $actor->id, 'predecessor' => $input['predecessor_id'], 'reason' => $reason, 'lines' => $lines,
            'quantity' => $stated === null ? null : (string) $stated, 'source' => [$input['source_type'], $input['source_id']],
            'moved_at' => (string) Carbon::parse($input['moved_at'])->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $actor, $company, $key, $reason, $hash, $lines, $stated): array {
            // All evidence writers share this namespace, so an absent key is serialised even across locations.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["package-checkpoint:{$company}:{$key}"]);
            $existing = PackageOperation::query()->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->evidence_kind !== 'ISSUE' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new DomainInvariantException('Request key already records different package evidence.');
                }

                return ['operation' => $existing, 'move' => StockMove::query()->findOrFail($existing->stock_move_id)];
            }

            $parent = PackageOperation::query()->findOrFail($input['predecessor_id']);
            $location = Location::query()->lockForUpdate()->findOrFail($parent->location_id);
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('A package issue requires an active stock location and warehouse.');
            }
            WarehouseScope::requireLocation($actor, 'inventory.package-state.issue', $parent->location_id, 'issue package-tracked stock');
            $item = Item::query()->findOrFail($parent->item_id);
            $current = $this->fresh($parent->id, $actor);

            // Opening is its own recorded step, in order, so the history reads as "opened, then issued".
            $draws = [];
            $step = 0;
            foreach ($lines as $line) {
                if ($line['kind'] === 'SEALED' || $line['kind'] === 'LOOSE') {
                    $draws[] = $line;

                    continue;
                }
                $state = null;
                foreach ($current['composition'] as $row) {
                    if ($row['form'] === 'SEALED' && $row['availability_evidence'] === 'USABLE' && $row['packaging_id'] === $line['packaging_id']) {
                        $state = $row;

                        break;
                    }
                }
                if ($state === null) {
                    throw new DomainInvariantException('The draw opens a sealed package that is not on hand.');
                }
                $opened = app(PostPackageOpening::class)([
                    'operation_id' => $current['operation_id'], 'state_id' => $state['state_id'], 'count' => $line['count'],
                    'kind' => $line['kind'] === 'UNPACK' ? 'UNPACK' : 'OPEN', 'request_key' => $key.'#step'.(++$step),
                    'reason' => 'Opened within issue '.$key.': '.$reason,
                ], $actor);
                $current = $this->fresh($opened->id, $actor);
                if ($line['kind'] === 'OPEN_AND_DRAW') {
                    if (BigDecimal::of($line['quantity'])->isGreaterThan(BigDecimal::of($state['content_quantity'])->multipliedBy($line['count']))) {
                        throw new DomainInvariantException('A quantity drawn from opened packages cannot exceed what they held.');
                    }
                    $draws[] = ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => $line['quantity']];
                }
            }
            if ($draws === []) {
                throw new DomainInvariantException('The draw must take something.');
            }
            $draws = $this->withQuantities($draws, $current['composition']);
            $total = array_reduce($draws, fn (BigDecimal $sum, array $draw) => $sum->plus($draw['quantity']), BigDecimal::zero());
            if ($stated !== null && ! $stated->isEqualTo($total)) {
                throw new DomainInvariantException('The stated quantity does not equal what the draw takes.');
            }
            $predecessor = PackageOperation::query()->findOrFail($current['operation_id']);

            $batchNo = $predecessor->batch_id === null ? null : StockBatch::query()->findOrFail($predecessor->batch_id)->code;
            $posted = app(PostInventoryMovement::class)->issuePosted([
                'item_id' => $item->id, 'location_id' => $predecessor->location_id, 'qty' => (string) $total, 'uom_id' => $item->stock_uom_id,
                'moved_at' => $input['moved_at'], 'source_type' => $input['source_type'], 'source_id' => $input['source_id'], 'batch_no' => $batchNo,
            ]);
            $this->assertCaptured($posted);
            $vector = [];
            foreach ($posted->allocations as $allocation) {
                $vector[$allocation->sourceLayerId] = $allocation->qty;
            }

            $successor = app(PackageIssueComposition::class)($current['composition'], $draws, $vector);
            $operation = PackageOperation::query()->create([
                'item_id' => $predecessor->item_id, 'location_id' => $predecessor->location_id, 'batch_id' => $predecessor->batch_id,
                'request_key' => $key, 'payload_hash' => $hash, 'evidence_kind' => 'ISSUE', 'actor_id' => $actor->id,
                'reason' => $reason, 'recorded_at' => now(), 'stock_move_watermark' => $posted->move->id,
                'predecessor_id' => $predecessor->id, 'stock_move_id' => $posted->move->id,
            ]);
            foreach ($successor as $row) {
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

            return ['operation' => $operation->refresh(), 'move' => $posted->move];
        });
    }

    /**
     * Replays evidence and refuses unless it is the fresh, reconciled, full-scope truth for this stock.
     *
     * @return array<string,mixed>
     */
    private function fresh(int $operationId, User $actor): array
    {
        $snapshot = app(ReplayPackageCheckpoint::class)($operationId, $actor);
        if (! $snapshot['matches_current_stock']) {
            throw new DomainInvariantException('Grouped package stock is not authoritative for this item and location: a fresh counted checkpoint is required ('.implode(', ', $snapshot['mismatch_reasons']).').');
        }

        return $snapshot;
    }

    private function assertCaptured(PostedMovement $posted): void
    {
        if ($posted->wasReplayed()) {
            throw new DomainInvariantException('Stock was already issued for this source without package evidence; it cannot be attached afterwards.');
        }
        if (! BigDecimal::of($posted->uncoveredQty)->isZero()) {
            throw new DomainInvariantException('A package issue cannot be partly uncovered by stock.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $draw
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>
     */
    private function lines(array $draw): array
    {
        $lines = [];
        foreach ($draw as $line) {
            $kind = $line['kind'] ?? null;
            $count = $line['count'] ?? null;
            $packaging = $line['packaging_id'] ?? null;
            if (isset($line['container_id'])) {
                throw new DomainInvariantException('Grouped stock does not identify individual containers.');
            }
            if (in_array($kind, ['SEALED', 'UNPACK', 'OPEN_AND_DRAW'], true)) {
                if (! is_int($packaging) || ! is_int($count) || $count < 1) {
                    throw new DomainInvariantException('Sealed lines need a package level and a positive whole count.');
                }
            } elseif ($kind !== 'LOOSE') {
                throw new DomainInvariantException('A draw line must be SEALED, LOOSE, UNPACK or OPEN_AND_DRAW.');
            }
            $quantity = in_array($kind, ['LOOSE', 'OPEN_AND_DRAW'], true) ? (string) $this->exact((string) ($line['quantity'] ?? ''), 'A drawn quantity') : null;
            $lines[] = ['kind' => $kind, 'packaging_id' => $kind === 'LOOSE' ? null : $packaging, 'count' => $kind === 'LOOSE' ? null : $count, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * Validates the whole-package counts and loose quantity against the composition and fills the sealed quantities.
     *
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>  $draws
     * @param  list<array<string,mixed>>  $composition
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>
     */
    private function withQuantities(array $draws, array $composition): array
    {
        $sealed = [];
        $loose = BigDecimal::zero();
        foreach ($composition as $row) {
            if ($row['availability_evidence'] !== 'USABLE') {
                continue;
            }
            if ($row['form'] === 'SEALED') {
                $sealed[$row['packaging_id']] = ['count' => ($sealed[$row['packaging_id']]['count'] ?? 0) + (int) $row['sealed_count'], 'content' => BigDecimal::of($row['content_quantity'])];
            } else {
                $loose = $loose->plus($row['quantity']);
            }
        }
        $result = [];
        foreach ($draws as $draw) {
            if ($draw['kind'] === 'SEALED') {
                $available = $sealed[$draw['packaging_id']] ?? null;
                if ($available === null || $draw['count'] > $available['count']) {
                    throw new DomainInvariantException('The draw takes more sealed packages than are on hand.');
                }
                $sealed[$draw['packaging_id']]['count'] -= $draw['count'];
                $draw['quantity'] = $available['content']->multipliedBy($draw['count'])->toScale(4);
            } else {
                $draw['quantity'] = BigDecimal::of($draw['quantity'])->toScale(4);
                if ($draw['quantity']->isGreaterThan($loose)) {
                    throw new DomainInvariantException('The draw takes more open/loose quantity than is on hand.');
                }
                $loose = $loose->minus($draw['quantity']);
            }
            $result[] = $draw;
        }

        return $result;
    }

    private function exact(string $quantity, string $what): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return $value->toScale(4);
            }
        } catch (\InvalidArgumentException|MathException $error) {
            // fall through to the domain error
        }
        throw new DomainInvariantException("{$what} must be positive and no finer than the stock ledger.");
    }
}
