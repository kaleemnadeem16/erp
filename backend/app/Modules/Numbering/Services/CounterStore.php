<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Support\Continuity;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Finds, creates and converts counters, and picks the strategy for each (ADR 0079 §9). */
final class CounterStore
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly LockedCounterStrategy $locked,
        private readonly SequenceCounterStrategy $sequence,
    ) {}

    public function strategy(NumberingCounter $counter): AllocationStrategy
    {
        return $counter->allocator === 'SEQUENCE' ? $this->sequence : $this->locked;
    }

    /**
     * The counter for a key, created on first use with the allocator the version's continuity calls for, and converted if the
     * series' continuity has changed since the counter was made. A first-use race is settled by the unique index: the loser
     * waits for the winner's commit and then reads its counter.
     */
    public function forAllocation(NumberingSeries $series, string $key, Continuity $continuity): NumberingCounter
    {
        $counter = NumberingCounter::query()->where('series_id', $series->id)->where('counter_key', $key)->first()
            ?? $this->create($series, $key, $continuity);

        if ($counter->allocator !== $continuity->allocator()) {
            $counter = $this->convert($counter, $continuity);
        }

        return $counter;
    }

    public function find(NumberingSeries $series, string $key): ?NumberingCounter
    {
        return NumberingCounter::query()->where('series_id', $series->id)->where('counter_key', $key)->first();
    }

    private function create(NumberingSeries $series, string $key, Continuity $continuity): NumberingCounter
    {
        $companyId = $this->company->id();
        $sequenceName = $continuity === Continuity::AllowGaps ? 'numbering_seq_'.bin2hex(random_bytes(6)) : null;

        $inserted = DB::affectingStatement(
            'insert into numbering_counters (company_id, series_id, counter_key, allocator, next_number, sequence_name, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, now(), now()) on conflict (company_id, series_id, counter_key) do nothing',
            [$companyId, $series->id, $key, $continuity->allocator(), $continuity === Continuity::Gapless ? 1 : null, $sequenceName]
        );
        if ($inserted === 1 && $sequenceName !== null) {
            $this->sequence->create($sequenceName);
        }

        return NumberingCounter::query()->where('series_id', $series->id)->where('counter_key', $key)->firstOrFail();
    }

    /** Moves a counter between policies without losing its place: the next number issued is the same one either way. */
    private function convert(NumberingCounter $counter, Continuity $continuity): NumberingCounter
    {
        DB::table('numbering_counters')->where('id', $counter->id)->lockForUpdate()->first();
        $counter = NumberingCounter::query()->findOrFail($counter->id);
        if ($counter->allocator === $continuity->allocator()) {
            return $counter;
        }
        $next = $this->strategy($counter)->peek($counter);

        if ($continuity === Continuity::AllowGaps) {
            $name = 'numbering_seq_'.bin2hex(random_bytes(6));
            $this->sequence->create($name, $next);
            DB::table('numbering_counters')->where('id', $counter->id)->update(['allocator' => 'SEQUENCE', 'sequence_name' => $name, 'next_number' => null, 'updated_at' => now()]);
        } else {
            $old = $counter->sequence_name;
            DB::table('numbering_counters')->where('id', $counter->id)->update(['allocator' => 'LOCKED', 'next_number' => $next, 'sequence_name' => null, 'updated_at' => now()]);
            $this->sequence->drop((string) $old);
        }

        return NumberingCounter::query()->findOrFail($counter->id);
    }
}
