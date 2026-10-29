<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** How much of a cost is known. PARTIAL and UNKNOWN are never a zero: a consumer must treat them as "cannot evaluate", not as a small or free cost. */
enum CostEvidenceCertainty: string
{
    case Complete = 'COMPLETE';
    case Partial = 'PARTIAL';
    case Unknown = 'UNKNOWN';
}
