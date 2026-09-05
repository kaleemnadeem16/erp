<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Internal, in-memory stock state for connected replay. Never persists its cloned layers/pools. */
final class MovementReplayState
{
    /** @var Collection<int, StockLayer> */
    private Collection $layers;

    /** @var array<string, array{qty: BigDecimal, value: BigDecimal}> */
    private array $pools = [];

    /** @var array<int|string, array<string, mixed>> */
    public array $outcomes = [];

    private int $nextLayerId = 0;

    public function __construct(private readonly MovementReplayHistory $history, private readonly LayerAllocator $allocator)
    {
        $this->layers = collect();
        foreach ($history->keys as $key) {
            $this->pools[$key] = ['qty' => BigDecimal::zero(), 'value' => BigDecimal::zero()];
        }
    }

    public function apply(StockMove $move): void
    {
        if ($move->from_location_id === null) {
            $layer = $this->history->layers->first(fn (StockLayer $l): bool => $l->source_type === $move->source_type && $l->source_id === $move->source_id);
            if ($layer === null) {
                throw new DomainInvariantException('A historical receipt is missing its traceability layer.', 'moved_at');
            }
            $copy = $this->addLayer($layer, $move->to_location_id, BigDecimal::of($move->qty), 'receipt:'.$move->id);
            $copy->setAttribute('receipt_move_id', $move->id);
            if ($this->isAvco()) {
                $value = $move->valuationEntries->reduce(fn (BigDecimal $sum, $e): BigDecimal => $sum->plus($e->base_amount), BigDecimal::zero());
                $this->adjustPool($move->to_location_id, $move->batch_id, BigDecimal::of($move->qty), $value);
            }

            return;
        }
        if ($move->movement_kind === 'REVERSAL') {
            $this->reverse($move);

            return;
        }
        $this->outbound((string) $move->id, $move->from_location_id, $move->to_location_id, $move->batch_id, $move->serial_no, BigDecimal::of($move->qty));
    }

    public function outbound(string $reference, int $source, ?int $destination, ?int $batchId, ?string $serial, BigDecimal $qty): void
    {
        $queue = $this->layers->filter(fn (StockLayer $l): bool => $l->location_id === $source && $l->batch_id === $batchId && $l->serial_no === $serial)
            ->sortBy([['received_at', 'asc'], ['id', 'asc']])->values();
        $allocations = $this->allocator->allocate($queue, $qty);
        $available = $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']), BigDecimal::zero());
        if (! $available->isEqualTo($qty)) {
            throw new DomainInvariantException('The backdated movement would leave insufficient physical stock for movement '.$reference.'.', 'qty');
        }
        $amount = $this->isAvco()
            ? $this->avcoOutflow($source, $batchId, $qty)
            : $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($this->money($a['qty']->multipliedBy($a['layer']->base_unit_cost))), BigDecimal::zero());
        if ($this->isAvco()) {
            $pool = $this->pools[$this->history->key($source, $batchId)];
            $rate = $destination === null ? $amount->dividedBy($qty, 4, RoundingMode::HALF_UP)
                : $pool['value']->dividedBy($pool['qty'], 4, RoundingMode::HALF_UP);
        } else {
            $raw = $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']->multipliedBy($a['layer']->base_unit_cost)), BigDecimal::zero());
            $rate = ($destination === null ? $this->money($raw) : $raw)->dividedBy($qty, 4, RoundingMode::HALF_UP);
        }
        if ($destination !== null && $rate->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('No defensible transfer cost exists at the proposed date.', 'moved_at');
        }
        $slices = [];
        foreach ($allocations as $allocation) {
            $layer = $allocation['layer'];
            $take = $allocation['qty'];
            $cost = $this->isAvco() ? $rate : BigDecimal::of($layer->base_unit_cost);
            $slices[] = ['source' => $layer->getAttribute('replay_reference'), 'qty' => (string) $this->money($take),
                'base_unit_cost' => (string) $this->money($cost), 'received_at' => $layer->received_at->toISOString()];
            $layer->qty_remaining = (string) BigDecimal::of($layer->qty_remaining)->minus($take);
            if ($destination !== null) {
                $copy = $this->addLayer($layer, $destination, $take, 'transfer:'.$reference.':'.$layer->getAttribute('replay_reference'));
                $copy->base_unit_cost = (string) $cost;
                $copy->setAttribute('receipt_move_id', null);
            }
        }
        if ($this->isAvco() && ($destination === null || $this->history->key($source, $batchId) !== $this->history->key($destination, $batchId))) {
            $this->adjustPool($source, $batchId, $qty->negated(), $amount->negated());
            if ($destination !== null) {
                $this->adjustPool($destination, $batchId, $qty, $amount);
            }
        }
        $this->outcomes[$reference] = ['base_amount' => (string) $this->money($amount), 'base_unit_cost' => (string) $rate, 'allocations' => $slices];
    }

    /** @return array<string, array{qty: string, value: string, average: string}> */
    public function snapshot(): array
    {
        $result = [];
        foreach ($this->history->keys as $key) {
            if ($this->isAvco()) {
                ['qty' => $qty, 'value' => $value] = $this->pools[$key];
            } else {
                $layers = $this->layers->filter(fn (StockLayer $l): bool => $this->history->key($l->location_id, $l->batch_id) === $key);
                $qty = $layers->reduce(fn (BigDecimal $sum, StockLayer $l): BigDecimal => $sum->plus($l->qty_remaining), BigDecimal::zero());
                $value = $layers->reduce(fn (BigDecimal $sum, StockLayer $l): BigDecimal => $sum->plus(BigDecimal::of($l->qty_remaining)->multipliedBy($l->base_unit_cost)), BigDecimal::zero());
            }
            $result[$key] = ['qty' => (string) $this->money($qty), 'value' => (string) $this->money($value),
                'average' => (string) ($qty->isZero() ? BigDecimal::zero()->toScale(4) : $value->dividedBy($qty, 4, RoundingMode::HALF_UP))];
        }

        return $result;
    }

    /** @return array<string, string> exact-location/tracking physical balances */
    public function physical(): array
    {
        $result = [];
        foreach ($this->layers as $layer) {
            $key = json_encode([$layer->location_id, $layer->batch_id, $layer->serial_no], JSON_THROW_ON_ERROR);
            $result[$key] = (string) $this->money(BigDecimal::of($result[$key] ?? '0')->plus($layer->qty_remaining));
        }
        ksort($result);

        return $result;
    }

    private function reverse(StockMove $move): void
    {
        $layer = $this->layers->first(fn (StockLayer $l): bool => $l->getAttribute('receipt_move_id') === $move->reverses_move_id);
        if ($layer === null || ! BigDecimal::of($layer->qty_remaining)->isEqualTo($move->qty)) {
            throw new DomainInvariantException('Replay would consume stock needed by an exact receipt reversal.', 'moved_at');
        }
        $layer->qty_remaining = '0.0000';
        $amount = $move->valuationEntries->reduce(fn (BigDecimal $sum, $e): BigDecimal => $sum->plus($e->base_amount), BigDecimal::zero());
        if ($this->isAvco()) {
            $this->adjustPool($move->from_location_id, $move->batch_id, BigDecimal::of($move->qty)->negated(), $amount);
        }
        $this->outcomes[(string) $move->id] = ['base_amount' => (string) $this->money($amount->abs()), 'reverses_move_id' => $move->reverses_move_id];
    }

    private function avcoOutflow(int $location, ?int $batchId, BigDecimal $qty): BigDecimal
    {
        $pool = $this->pools[$this->history->key($location, $batchId)];
        if ($pool['qty']->isLessThan($qty) || $pool['qty']->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('The backdated movement would require unsupported negative AVCO stock.', 'qty');
        }

        return $pool['qty']->isEqualTo($qty) ? $pool['value']
            : $this->money($qty->multipliedBy($pool['value']->dividedBy($pool['qty'], 4, RoundingMode::HALF_UP)));
    }

    private function adjustPool(int $location, ?int $batchId, BigDecimal $qty, BigDecimal $value): void
    {
        $key = $this->history->key($location, $batchId);
        $this->pools[$key]['qty'] = $this->pools[$key]['qty']->plus($qty);
        $this->pools[$key]['value'] = $this->money($this->pools[$key]['value']->plus($value));
        if ($this->pools[$key]['qty']->isLessThan(0) || $this->pools[$key]['value']->isLessThan(0)) {
            throw new DomainInvariantException('Replay would create an unsupported negative valuation state.', 'moved_at');
        }
    }

    private function addLayer(StockLayer $source, int $location, BigDecimal $qty, string $reference): StockLayer
    {
        $copy = $source->replicate();
        $copy->id = ++$this->nextLayerId;
        $copy->location_id = $location;
        $copy->qty_received = (string) $qty;
        $copy->qty_remaining = (string) $qty;
        // QA histories were refused by the loader, so availability is exactly remaining quantity.
        $copy->source_type = 'REPLAY';
        $copy->setAttribute('replay_reference', $reference);
        $this->layers->push($copy);

        return $copy;
    }

    private function isAvco(): bool
    {
        return $this->history->item->valuation_method === ValuationMethod::Avco;
    }

    private function money(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale(4, RoundingMode::HALF_UP);
    }
}
