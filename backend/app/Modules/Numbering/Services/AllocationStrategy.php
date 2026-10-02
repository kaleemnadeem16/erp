<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingCounter;

/**
 * How a counter hands out its next number (ADR 0079 §9). One implementation per continuity policy; a new policy is a new
 * implementation, with no change to callers.
 */
interface AllocationStrategy
{
    /** Takes and returns the next number. Runs inside the document's transaction. */
    public function allocate(NumberingCounter $counter): int;

    /** The number the next allocation would return, without taking it (the tester, the counter screen). */
    public function peek(NumberingCounter $counter): int;

    /** Sets the next number to $next (only ever upward; the caller has checked). */
    public function raiseTo(NumberingCounter $counter, int $next): void;
}
