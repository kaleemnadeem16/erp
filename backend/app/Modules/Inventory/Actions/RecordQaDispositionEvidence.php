<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageContribution;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PackageQaComposition;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\DispositionOutcome;
use Brick\Math\BigDecimal;

/**
 * The package evidence of a QA disposition of a grouped item (ADR 0094), written inside PostQualityDisposition's transaction. The person declares which held
 * whole sealed packages and loose quantity the disposition concerns; the declared portion is relabelled HELD -> USABLE (pass, concession) or HELD -> REJECTED
 * (return, scrap). No stock moves and no cost changes: the successor has the same total and the same layer totals. Never opens a package.
 */
final class RecordQaDispositionEvidence
{
    public function __construct(private readonly PackageDrawPlanner $draw) {}

    /** Whether this outcome changes package evidence at all (REWORK and PENDING_DECISION leave the packages held). */
    public static function decides(DispositionOutcome $outcome): bool
    {
        return in_array($outcome, [DispositionOutcome::Pass, DispositionOutcome::AcceptWithConcession, DispositionOutcome::ReturnToSupplier, DispositionOutcome::Scrap], true);
    }

    public static function targetFor(DispositionOutcome $outcome): string
    {
        return $outcome->releasesStockToAvailable() ? 'USABLE' : 'REJECTED';
    }

    /**
     * @param  list<array<string,mixed>>  $declared  SEALED / LOOSE lines of held stock
     */
    public function __invoke(QualityDisposition $disposition, GoodsReceiptLine $line, array $declared, User $actor): PackageOperation
    {
        if (! $actor->can('purchasing.quality-disposition.post') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Quality-disposition posting and package-state view authority is required to dispose package-tracked stock.');
        }
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->lockForUpdate()->firstOrFail();
        $location = Location::query()->lockForUpdate()->findOrFail($layer->location_id);
        WarehouseScope::requireLocation($actor, 'inventory.package-state.view', $location->id, 'dispose package-tracked stock');
        $outcome = $disposition->disposition;
        $key = 'qa-disposition:'.$disposition->id;
        $lines = $this->draw->parse($declared);
        foreach ($lines as $each) {
            if (! in_array($each['kind'], ['SEALED', 'LOOSE'], true)) {
                throw new DomainInvariantException('A QA disposition relabels whole sealed packages and loose quantity; it never opens a package.', 'package_portion');
            }
        }
        $hash = hash('sha256', json_encode(['actor' => $actor->id, 'disposition' => $disposition->id, 'lines' => $lines], JSON_THROW_ON_ERROR));
        $existing = PackageOperation::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new DomainInvariantException('This disposition already has different package evidence.');
            }

            return $existing;
        }
        $predecessor = PackageOperation::query()->where(['item_id' => $line->item_id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id])
            ->whereIn('evidence_kind', PackageOperation::CHAIN)->orderByDesc('id')->first();
        if ($predecessor === null) {
            throw new DomainInvariantException('This location holds package-tracked stock without package evidence: a counted checkpoint is required first.', null, 'NEEDS_COUNT_FIRST');
        }
        $current = $this->draw->fresh($predecessor->id, $actor);
        $draws = $this->draw->quantify($lines, $current['composition'], 'HELD');
        $total = array_reduce($draws, fn (BigDecimal $sum, array $draw) => $sum->plus($draw['quantity']), BigDecimal::zero());
        if (! $total->isEqualTo($disposition->qty)) {
            throw new DomainInvariantException('The declared packages must add up to the disposition quantity ('.BigDecimal::of($disposition->qty)->toScale(4).').', 'package_portion');
        }
        $successor = app(PackageQaComposition::class)($current['composition'], $draws, self::targetFor($outcome), $layer->id);

        $operation = PackageOperation::query()->create([
            'item_id' => $line->item_id, 'location_id' => $location->id, 'batch_id' => $layer->batch_id,
            'request_key' => $key, 'payload_hash' => $hash, 'evidence_kind' => 'QA_DISPOSITION', 'actor_id' => $actor->id, 'reason' => $disposition->reason,
            'recorded_at' => now(), 'stock_move_watermark' => $predecessor->stock_move_watermark, 'predecessor_id' => $predecessor->id, 'quality_disposition_id' => $disposition->id,
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
                'item_packaging_id' => $draw['packaging_id'], 'whole_count' => $draw['count'], 'quantity' => (string) $draw['quantity'], 'availability' => 'HELD']);
        }

        return $operation->refresh();
    }
}
