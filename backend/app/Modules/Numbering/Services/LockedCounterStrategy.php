<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingCounter;
use Illuminate\Support\Facades\DB;

/**
 * GAPLESS. The counter row is locked inside the document's own transaction, so a rollback returns the number and the numbers
 * of one counter are issued strictly one after another. Concurrent issues of the same counter queue on the lock.
 */
final class LockedCounterStrategy implements AllocationStrategy
{
    public function allocate(NumberingCounter $counter): int
    {
        // One atomic statement: the UPDATE takes the row lock, so a concurrent issue waits here until this transaction ends, and
        // the value returned is the one this transaction advanced — never a stale read followed by a write.
        return (int) DB::selectOne(
            'update numbering_counters set next_number = next_number + 1, updated_at = now() where id = ? returning next_number - 1 as value',
            [$counter->id]
        )->value;
    }

    public function peek(NumberingCounter $counter): int
    {
        return (int) DB::table('numbering_counters')->where('id', $counter->id)->value('next_number');
    }

    public function raiseTo(NumberingCounter $counter, int $next): void
    {
        DB::table('numbering_counters')->where('id', $counter->id)->lockForUpdate()->first();
        DB::table('numbering_counters')->where('id', $counter->id)->update(['next_number' => $next, 'updated_at' => now()]);
    }
}
