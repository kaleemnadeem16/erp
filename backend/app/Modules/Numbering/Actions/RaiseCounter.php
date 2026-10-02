<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingCounterAdjustment;
use App\Modules\Numbering\Services\CounterStore;
use App\Modules\Numbering\Support\NumberingException;
use Illuminate\Support\Facades\DB;

/** Raises a counter's next number, with a reason and an audit row. Never lowers it — a lower counter would repeat numbers (ADR 0079 §16). */
final class RaiseCounter
{
    public function __construct(private readonly CounterStore $counters) {}

    public function __invoke(NumberingCounter $counter, int $next, string $reason, User $actor): NumberingCounterAdjustment
    {
        if (! $actor->can('numbering.counter.raise')) {
            throw new NumberingException('You are not permitted to change numbering counters.', 'actor');
        }
        if (trim($reason) === '') {
            throw new NumberingException('Say why the counter is being raised; the reason is kept.', 'reason');
        }

        return DB::transaction(function () use ($counter, $next, $reason, $actor): NumberingCounterAdjustment {
            $counter = NumberingCounter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();
            $strategy = $this->counters->strategy($counter);
            $current = $strategy->peek($counter);
            if ($next <= $current) {
                throw new NumberingException("A counter can only be raised. It is at {$current}; {$next} would not be higher, and a lower counter could repeat an issued number.", 'next');
            }
            $strategy->raiseTo($counter, $next);

            return NumberingCounterAdjustment::query()->create([
                'counter_id' => $counter->id, 'from_value' => $current, 'to_value' => $next, 'reason' => trim($reason), 'changed_by' => $actor->id,
            ]);
        });
    }
}
