<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/** Internal issue/transfer preview, including both sides and later transfer-connected movements. */
final class MovementCounterfactualReplayer
{
    public function __construct(private readonly MovementReplayScope $scope, private readonly LayerAllocator $allocator) {}

    /** @return array<string, mixed> economic attributes plus gross risk exposure */
    public function attributes(RecalculationRequest $request, Company $company): array
    {
        $history = $this->scope->load($request);
        $original = new MovementReplayState($history, $this->allocator);
        foreach ($history->moves as $move) {
            $original->apply($move);
            if ($move->from_location_id !== null) {
                $recorded = $move->valuationEntries->filter(fn ($e): bool => BigDecimal::of($e->qty)->isLessThan(0))
                    ->reduce(fn (BigDecimal $sum, $e): BigDecimal => $sum->plus($e->base_amount), BigDecimal::zero())->abs();
                if (! $recorded->isEqualTo($original->outcomes[(string) $move->id]['base_amount'])) {
                    throw new DomainInvariantException('Recorded valuation does not reconcile to clean movement replay; this history is unsupported.', 'moved_at');
                }
            }
        }
        $before = $original->snapshot();
        $this->verifyLiveState($history, $original, $before);
        $counterfactual = new MovementReplayState($history, $this->allocator);
        $inserted = false;
        foreach ($history->moves as $move) {
            if (! $inserted && Carbon::parse($move->moved_at)->gt($request->moved_at)) {
                $this->insert($counterfactual, $request);
                $inserted = true;
            }
            $counterfactual->apply($move);
        }
        if (! $inserted) {
            $this->insert($counterfactual, $request);
        }
        $after = $counterfactual->snapshot();
        [$oldQty, $oldValue, $oldAverage] = $this->totals($before);
        [$newQty, $newValue, $newAverage] = $this->totals($after);
        $changes = [];
        $exposure = BigDecimal::of($counterfactual->outcomes['proposed']['base_amount']);
        $scopeExposure = BigDecimal::zero();
        $downstreamExposure = BigDecimal::zero();
        foreach ($before as $key => $state) {
            $delta = BigDecimal::of($after[$key]['value'])->minus($state['value']);
            $scopeExposure = $scopeExposure->plus($delta->abs());
        }
        foreach ($original->outcomes as $id => $outcome) {
            $replayed = $counterfactual->outcomes[$id];
            if ($outcome !== $replayed) {
                $changes[(string) $id] = ['original' => $outcome, 'counterfactual' => $replayed];
                $downstreamExposure = $downstreamExposure->plus(BigDecimal::of($replayed['base_amount'])->minus($outcome['base_amount'])->abs());
            }
        }
        foreach ([$scopeExposure, $downstreamExposure] as $amount) {
            if ($amount->isGreaterThan($exposure)) {
                $exposure = $amount;
            }
        }
        $payload = [
            'risk_exposure_base_amount' => (string) $exposure->toScale(4),
            'currency_id' => $company->base_currency_id, 'fx_rate' => '1.000000000',
            'proposed' => $counterfactual->outcomes['proposed'],
            'original_scopes' => $before, 'counterfactual_scopes' => $after, 'changed_movements' => $changes,
            'location_ids' => $history->locations->keys()->all(), 'avco_pool_ids' => $history->pools->pluck('id')->all(),
            'movement_ids' => $history->moves->pluck('id')->all(),
        ];

        return [
            'avco_pool_id' => null, 'original_qty_on_hand' => $oldQty, 'original_inventory_value' => $oldValue,
            'original_avg_unit_cost' => $oldAverage, 'counterfactual_qty_on_hand' => $newQty,
            'counterfactual_inventory_value' => $newValue, 'counterfactual_avg_unit_cost' => $newAverage,
            'valuation_adjustment' => (string) BigDecimal::of($newValue)->minus($oldValue),
            'movement_impact' => $payload, 'risk_exposure' => $exposure,
        ];
    }

    private function insert(MovementReplayState $state, RecalculationRequest $request): void
    {
        $state->outbound('proposed', $request->location_id, $request->destination_location_id,
            $request->batch_id, $request->serial_no, BigDecimal::of($request->qty));
    }

    /** @param array<string, array{qty: string, value: string, average: string}> $snapshot */
    private function verifyLiveState(MovementReplayHistory $history, MovementReplayState $state, array $snapshot): void
    {
        $physical = [];
        foreach ($history->layers as $layer) {
            $key = json_encode([$layer->location_id, $layer->batch_id, $layer->serial_no], JSON_THROW_ON_ERROR);
            $physical[$key] = (string) BigDecimal::of($physical[$key] ?? '0')->plus($layer->qty_remaining)->toScale(4);
        }
        ksort($physical);
        if ($physical !== $state->physical()) {
            throw new DomainInvariantException('Physical stock does not reconcile to recorded movement history.', 'moved_at');
        }
        if ($history->item->valuation_method === ValuationMethod::Avco) {
            foreach ($history->pools as $pool) {
                $replayed = $snapshot[$history->poolKey($pool)];
                if (! BigDecimal::of($pool->qty_on_hand)->isEqualTo($replayed['qty']) || ! BigDecimal::of($pool->inventory_value)->isEqualTo($replayed['value'])) {
                    throw new DomainInvariantException('AVCO pool state does not reconcile to recorded movement history.', 'moved_at');
                }
            }
        }
    }

    /**
     * @param  array<string, array{qty: string, value: string, average: string}>  $snapshot
     * @return array{string, string, string}
     */
    private function totals(array $snapshot): array
    {
        $qty = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($snapshot as $state) {
            $qty = $qty->plus($state['qty']);
            $value = $value->plus($state['value']);
        }

        return [(string) $qty->toScale(4), (string) $value->toScale(4),
            (string) ($qty->isZero() ? BigDecimal::zero()->toScale(4) : $value->dividedBy($qty, 4, RoundingMode::HALF_UP))];
    }
}
