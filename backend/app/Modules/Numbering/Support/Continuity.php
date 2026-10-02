<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** Whether a series may leave gaps (ADR 0079 §9). Each value is served by one allocation strategy. */
enum Continuity: string
{
    case Gapless = 'GAPLESS';
    case AllowGaps = 'ALLOW_GAPS';

    /** The counter implementation that provides this guarantee. */
    public function allocator(): string
    {
        return match ($this) {
            self::Gapless => 'LOCKED',
            self::AllowGaps => 'SEQUENCE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Gapless => 'No gaps — issues of one counter are processed one at a time',
            self::AllowGaps => 'Gaps possible after a failure — issues never wait on each other',
        };
    }
}
