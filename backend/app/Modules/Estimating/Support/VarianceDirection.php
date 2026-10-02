<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** Over-plan and under-plan differences carry separate bands (ADR 0054 §3). */
enum VarianceDirection: string
{
    case Over = 'OVER';
    case Under = 'UNDER';
}
